<?php
/**
 * Stall readiness-store smoke (#234 backfill — 2.7.467).
 *
 * The By-Location → List readiness grid (2.7.464–466) writes per-stall-night
 * status through EEM_Stall_Status_Repo. This backfills the smoke coverage that
 * shipped browser-verified-only:
 *
 *   - set_cell_status()              — single-cell upsert (override write)
 *   - bulk_set_status()              — units × nights fan-out + skip rules
 *   - mark_order_stalls_needs_cleaning() — checkout turnover flip + guard
 *
 * Every write is read back through the canonical consumer queries
 * (get_status_map / get) — not just write-side verification — per the C6.E.1
 * round-trip discipline. Self-contained: seeds a throwaway reservation + order
 * row, asserts, then deletes everything it created.
 *
 * Run: wp eval-file tests/smoke/stall-status-readiness-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

EEM_Stall_Status_Repo::create_tables();
$status_table = $wpdb->prefix . 'eem_stall_status';
$sr_table     = $wpdb->prefix . 'eem_stall_reservations';
$uid          = 1;

// Throwaway reservation so we never touch real data.
$res_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => '#234 readiness smoke reservation',
) );
$check( 'seed: throwaway reservation created', $res_id > 0 );

// --- set_cell_status: single-cell upsert + canonical read-back -------------
$ret = EEM_Stall_Status_Repo::set_cell_status( $res_id, 'T1', '2099-01-01', 'clean', $uid );
$check( 'set_cell_status returns the stored status', 'clean' === $ret );

$row = EEM_Stall_Status_Repo::get( $res_id, 'T1', '2099-01-01' );
$check( 'get() reads the cell back as clean', is_array( $row ) && 'clean' === $row['status'] );
$check( 'manual cell gets order_id 0 (no occupant)', is_array( $row ) && 0 === (int) $row['order_id'] );

$map = EEM_Stall_Status_Repo::get_status_map( $res_id );
$check( 'get_status_map() exposes the cell', isset( $map['T1']['2099-01-01'] ) && 'clean' === $map['T1']['2099-01-01'] );

// Upsert the same cell — status changes, NO duplicate row.
EEM_Stall_Status_Repo::set_cell_status( $res_id, 'T1', '2099-01-01', 'needs_cleaning', $uid );
$row = EEM_Stall_Status_Repo::get( $res_id, 'T1', '2099-01-01' );
$check( 'set_cell_status upserts (status updated in place)', is_array( $row ) && 'needs_cleaning' === $row['status'] );
$dupes = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$status_table} WHERE reservation_id = %d AND stall_unit = %s AND night_date = %s",
	$res_id, 'T1', '2099-01-01'
) );
$check( 'upsert leaves exactly one row (unique key holds)', 1 === $dupes );

// --- bulk_set_status: units × nights fan-out ------------------------------
$written = EEM_Stall_Status_Repo::bulk_set_status(
	$res_id, array( 'B1', 'B2' ), array( '2099-03-01', '2099-03-02' ), 'clean', $uid
);
$check( 'bulk_set_status writes units × nights (2×2=4)', 4 === $written );

$map = EEM_Stall_Status_Repo::get_status_map( $res_id );
$all_clean = isset( $map['B1']['2099-03-01'], $map['B1']['2099-03-02'], $map['B2']['2099-03-01'], $map['B2']['2099-03-02'] )
	&& 'clean' === $map['B1']['2099-03-01'] && 'clean' === $map['B2']['2099-03-02'];
$check( 'bulk cells all read back as clean', $all_clean );

// Skip rules: empty unit + invalid date are not written.
$written2 = EEM_Stall_Status_Repo::bulk_set_status(
	$res_id, array( 'B3', '' ), array( '2099-03-03', 'not-a-date' ), 'clean', $uid
);
$check( 'bulk_set_status skips empty unit + invalid date (1 write)', 1 === $written2 );
$check( 'only the valid bulk cell landed', null !== EEM_Stall_Status_Repo::get( $res_id, 'B3', '2099-03-03' ) );

// --- mark_order_stalls_needs_cleaning: checkout turnover + guard ----------
$order_number = 'SMK-9001';
$wpdb->insert( $sr_table, array(
	'reservation_id' => $res_id,
	'order_number'   => $order_number,
	'customer_name'  => 'Readiness Smoke',
	'arrival_date'   => '2099-02-01',
	'departure_date' => '2099-02-03',
), array( '%d', '%s', '%s', '%s', '%s' ) );
$order_id = (int) $wpdb->insert_id;
$check( 'seed: stall_reservations order row created', $order_id > 0 );

// Two stalls × two nights of occupancy tied to that order. Nights are
// departure-EXCLUSIVE (date_range uses `< end`), so arrival 02-01 →
// departure 02-03 yields nights 02-01 + 02-02 = 2 nights.
$occ = EEM_Stall_Status_Repo::create_occupied( $res_id, $order_id, array( 'O1', 'O2' ), '2099-02-01', '2099-02-03', $uid );
$check( 'seed: create_occupied wrote 4 occupied cells (2 stalls × 2 nights)', 4 === $occ );

// One cell already clean — the WHERE guard must leave it untouched.
$wpdb->query( $wpdb->prepare(
	"UPDATE {$status_table} SET status = 'clean' WHERE reservation_id = %d AND stall_unit = %s AND night_date = %s",
	$res_id, 'O1', '2099-02-01'
) );

$flipped = EEM_Stall_Status_Repo::mark_order_stalls_needs_cleaning( $res_id, $order_number, $uid );
$check( 'mark_order_stalls_needs_cleaning flips the 3 non-clean cells', 3 === $flipped );

$map = EEM_Stall_Status_Repo::get_status_map( $res_id );
$check( 'flipped cell reads needs_cleaning', isset( $map['O2']['2099-02-02'] ) && 'needs_cleaning' === $map['O2']['2099-02-02'] );
$check( 'already-clean cell NOT flipped (guard holds)', isset( $map['O1']['2099-02-01'] ) && 'clean' === $map['O1']['2099-02-01'] );

// Re-running is a no-op (everything is now needs_cleaning or clean).
$again = EEM_Stall_Status_Repo::mark_order_stalls_needs_cleaning( $res_id, $order_number, $uid );
$check( 'mark_order_stalls_needs_cleaning is idempotent (0 on re-run)', 0 === $again );

// --- teardown -------------------------------------------------------------
$wpdb->delete( $status_table, array( 'reservation_id' => $res_id ), array( '%d' ) );
$wpdb->delete( $sr_table, array( 'id' => $order_id ), array( '%d' ) );
wp_delete_post( $res_id, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
