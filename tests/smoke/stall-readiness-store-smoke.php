<?php
/**
 * Stall readiness-store smoke (#234 — backfill coverage for the 2.7.466
 * Stall & RV Charts "By Location – List" readiness grid).
 *
 * The 06-18 session shipped EEM_Stall_Status_Repo (per-stall-night readiness:
 * Occupied / Cleaning / Available) with no smoke coverage. This backfills the
 * core write/read surface the By-Location grid + bulk-update + check-out
 * turnover depend on:
 *
 *   - set_cell_status()  — override upsert of one stall-night cell
 *   - get_status_map()   — [stall_unit][night_date] => status read used by the grid
 *   - bulk_set_status()  — many stalls × many nights, skipping empty/invalid input
 *   - create_occupied()  — lazy 'occupied' row creation across a stay range
 *   - transition()       — forward-only chain guard + override bypass
 *   - mark_order_stalls_needs_cleaning() — auto check-out turnover (method shape)
 *   - date_range()       — night enumeration (private; deterministic logic)
 *
 * All DB writes use a synthetic probe reservation/order id (well outside real
 * post-id space) and are cleaned up before AND after the run, so re-running is
 * idempotent and never touches live reservation data.
 *
 * Run: wp eval-file tests/smoke/stall-readiness-store-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;

$PROBE_RES   = 9902340;            // synthetic reservation id — not a real post.
$PROBE_ORDER = 9902341;            // synthetic wp_en_stall_reservations.id.
$STATUS_TBL  = $wpdb->prefix . 'eem_stall_status';
$LOG_TBL     = $wpdb->prefix . 'eem_stall_status_log';
$USER        = get_current_user_id();

// --- cleanup helper: wipe all probe rows (status + their log entries) --------
$cleanup = static function () use ( $wpdb, $STATUS_TBL, $LOG_TBL, $PROBE_RES, $PROBE_ORDER ): void {
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$STATUS_TBL} WHERE reservation_id = %d OR order_id = %d", $PROBE_RES, $PROBE_ORDER ) );
	if ( ! empty( $ids ) ) {
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$LOG_TBL} WHERE stall_status_id IN ({$ph})", ...$ids ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$STATUS_TBL} WHERE reservation_id = %d OR order_id = %d", $PROBE_RES, $PROBE_ORDER ) );
};
$cleanup();

// --- class + method existence ------------------------------------------------
$check( 'EEM_Stall_Status_Repo class exists', class_exists( 'EEM_Stall_Status_Repo' ) );
$ref = new ReflectionClass( 'EEM_Stall_Status_Repo' );
foreach ( array( 'set_cell_status', 'get_status_map', 'bulk_set_status', 'create_occupied', 'transition', 'mark_order_stalls_needs_cleaning', 'date_range' ) as $m ) {
	$check( "method {$m}() defined", $ref->hasMethod( $m ) );
}

// --- date_range(): deterministic night enumeration (private) ------------------
$date_range = $ref->getMethod( 'date_range' ); $date_range->setAccessible( true );
$nights = $date_range->invoke( null, '2026-06-01', '2026-06-03' );
$check( 'date_range excludes departure (2 nights for a 3-day span)', array( '2026-06-01', '2026-06-02' ) === $nights );
$check( 'date_range from==to yields the single night', array( '2026-06-01' ) === $date_range->invoke( null, '2026-06-01', '2026-06-01' ) );
$check( 'date_range reversed range yields empty', array() === $date_range->invoke( null, '2026-06-03', '2026-06-01' ) );

// --- set_cell_status() + get_status_map() round-trip -------------------------
EEM_Stall_Status_Repo::set_cell_status( $PROBE_RES, 'T-01', '2026-06-01', 'needs_cleaning', $USER );
$map = EEM_Stall_Status_Repo::get_status_map( $PROBE_RES );
$check( 'set_cell_status persists + get_status_map reflects it', ( $map['T-01']['2026-06-01'] ?? '' ) === 'needs_cleaning' );

// upsert: same cell, new status overwrites (no duplicate row)
EEM_Stall_Status_Repo::set_cell_status( $PROBE_RES, 'T-01', '2026-06-01', 'clean', $USER );
$map = EEM_Stall_Status_Repo::get_status_map( $PROBE_RES );
$check( 'set_cell_status upserts in place (status overwritten to clean)', ( $map['T-01']['2026-06-01'] ?? '' ) === 'clean' );
$dupes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$STATUS_TBL} WHERE reservation_id = %d AND stall_unit = %s AND night_date = %s", $PROBE_RES, 'T-01', '2026-06-01' ) );
$check( 'set_cell_status leaves exactly one row for the cell (unique key honored)', 1 === $dupes );

// --- bulk_set_status(): cartesian write + input hardening -------------------
$written = EEM_Stall_Status_Repo::bulk_set_status( $PROBE_RES, array( 'B-01', 'B-02' ), array( '2026-06-01', '2026-06-02' ), 'needs_cleaning', $USER );
$check( 'bulk_set_status writes units × nights (2×2 = 4 cells)', 4 === $written );
$map = EEM_Stall_Status_Repo::get_status_map( $PROBE_RES );
$check( 'bulk_set_status results readable via get_status_map', ( $map['B-02']['2026-06-02'] ?? '' ) === 'needs_cleaning' );

// empty unit + invalid date are skipped, not written
$written2 = EEM_Stall_Status_Repo::bulk_set_status( $PROBE_RES, array( '', 'B-03' ), array( 'not-a-date', '2026-06-01' ), 'clean', $USER );
$check( 'bulk_set_status skips empty units + invalid dates (only 1 valid cell)', 1 === $written2 );

// --- create_occupied(): lazy occupied rows across a stay --------------------
$ins = EEM_Stall_Status_Repo::create_occupied( $PROBE_RES, $PROBE_ORDER, array( 'C-01' ), '2026-07-01', '2026-07-03', $USER );
$check( 'create_occupied inserts one row per night excluding departure (2)', 2 === $ins );
// idempotent: re-running inserts nothing
$check( 'create_occupied is idempotent on re-run (0 new rows)', 0 === EEM_Stall_Status_Repo::create_occupied( $PROBE_RES, $PROBE_ORDER, array( 'C-01' ), '2026-07-01', '2026-07-03', $USER ) );

// --- transition(): forward-only guard + override bypass ----------------------
$ok = EEM_Stall_Status_Repo::transition( $PROBE_RES, 'C-01', '2026-07-01', 'checked_in', $USER );
$check( 'transition occupied -> checked_in allowed', true === ( $ok['success'] ?? false ) );

$blocked = EEM_Stall_Status_Repo::transition( $PROBE_RES, 'C-01', '2026-07-02', 'checked_out', $USER );
$check( 'transition occupied -> checked_out blocked without override', false === ( $blocked['success'] ?? true ) );

$forced = EEM_Stall_Status_Repo::transition( $PROBE_RES, 'C-01', '2026-07-02', 'checked_out', $USER, true );
$check( 'transition occupied -> checked_out allowed WITH override', true === ( $forced['success'] ?? false ) );

$invalid = EEM_Stall_Status_Repo::transition( $PROBE_RES, 'C-01', '2026-07-01', 'bogus_status', $USER, true );
$check( 'transition rejects an unknown status even with override', false === ( $invalid['success'] ?? true ) );

// --- mark_order_stalls_needs_cleaning(): callable + returns int --------------
// (Full join coverage needs a seeded wp_en_stall_reservations row; here we
// assert the method runs and returns an int without fataling on a probe order.)
$flipped = EEM_Stall_Status_Repo::mark_order_stalls_needs_cleaning( $PROBE_RES, 'PROBE-ORDER', $USER );
$check( 'mark_order_stalls_needs_cleaning returns an int (no fatal)', is_int( $flipped ) );

// --- teardown ----------------------------------------------------------------
$cleanup();
$residual = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$STATUS_TBL} WHERE reservation_id = %d OR order_id = %d", $PROBE_RES, $PROBE_ORDER ) );
$check( 'teardown removes all probe rows', 0 === $residual );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
