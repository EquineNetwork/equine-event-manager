<?php
/**
 * Migration #042 behavioral smoke — legacy-order token backfill + aux re-key
 * (ship-readiness 4.1b).
 *
 * Seeds a synthetic TOKENLESS order (one stall row + one rv row sharing a
 * composite, so they group to one order_key) plus one row in EVERY
 * order_key-keyed aux table at that old key, then runs the migration and proves:
 *   1. both component rows get the SAME uuid submission token,
 *   2. every aux row is repointed old_key -> md5(token),
 *   3. no aux row is left orphaned at old_key,
 *   4. a second run is a no-op on the already-tokenized order (idempotent).
 * All synthetic rows are removed at the end (targeted by a unique marker), and
 * the migration flag is cleared so the real activate() path is unaffected.
 *
 * Run via: wp eval-file tests/smoke/mig042-token-backfill-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-042-backfill-order-tokens.php';
if ( ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "  FAIL — EEM_Orders_Repository missing\n0 passed, 1 failed\n";
	return;
}
$repo = new EEM_Orders_Repository();

$marker  = 'EEM_MIG042_SMOKE_' . substr( md5( uniqid( '', true ) ), 0, 10 );
$flag    = 'eem_mig_042_backfill_order_tokens_complete';
$stall_t = $wpdb->prefix . 'eem_stall_reservations';
$rv_t    = $wpdb->prefix . 'eem_rv_reservations';
$aux_tables = array(
	$wpdb->prefix . 'eem_order_adjustments',
	$wpdb->prefix . 'eem_order_payments',
	$wpdb->prefix . 'eem_activity_log',
	$wpdb->prefix . 'eem_division_entries',
	$wpdb->prefix . 'eem_order_documents',
);

// Shared composite for the synthetic order — NO submission token in notes.
$composite = array(
	'event_source'      => 'native',
	'event_id'          => 999001,
	'external_event_id' => '',
	'customer_name'     => $marker,
	'email'             => 'mig042@example.test',
	'phone'             => '555-0042',
	'created_at'        => '2026-01-02 03:04:05',
);
$notes_no_token = "Reservation setup ID: 424242\nBilling Name: " . $marker;

/**
 * Build a schema-valid row for any table: fill every NOT NULL column that has no
 * default with a type-appropriate placeholder, then apply explicit $overrides.
 * Returns true on a successful insert.
 */
$seed_row = static function ( $table, array $overrides ) use ( $wpdb ) {
	$cols = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, EXTRA
			 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
			$table
		),
		ARRAY_A
	);
	if ( empty( $cols ) ) { return false; }
	$data = array();
	foreach ( $cols as $c ) {
		$name = $c['COLUMN_NAME'];
		if ( false !== strpos( (string) $c['EXTRA'], 'auto_increment' ) ) { continue; }
		if ( array_key_exists( $name, $overrides ) ) { $data[ $name ] = $overrides[ $name ]; continue; }
		if ( 'NO' === $c['IS_NULLABLE'] && null === $c['COLUMN_DEFAULT'] ) {
			$dt = strtolower( (string) $c['DATA_TYPE'] );
			if ( in_array( $dt, array( 'int', 'bigint', 'tinyint', 'smallint', 'decimal', 'float', 'double' ), true ) ) {
				$data[ $name ] = 0;
			} elseif ( in_array( $dt, array( 'datetime', 'timestamp' ), true ) ) {
				$data[ $name ] = '2026-01-02 03:04:05';
			} elseif ( 'date' === $dt ) {
				$data[ $name ] = '2026-01-02';
			} else {
				$data[ $name ] = '';
			}
		}
	}
	return false !== $wpdb->insert( $table, $data );
};
$seed_aux = static function ( $table, $order_key ) use ( $seed_row ) {
	return $seed_row( $table, array( 'order_key' => $order_key ) );
};

// ---- Seed the synthetic order + aux rows ----------------------------------
$comp_overrides = array_merge( $composite, array( 'notes' => $notes_no_token, 'order_number' => '0', 'order_key' => '' ) );
$chk( $seed_row( $stall_t, $comp_overrides ), 'inserted synthetic stall row' );
$chk( $seed_row( $rv_t, $comp_overrides ), 'inserted synthetic rv row' );

$old_key_stall = $repo->order_key_for_row( array_merge( $composite, array( 'notes' => $notes_no_token ) ) );
$old_key       = $old_key_stall;
$chk( 32 === strlen( $old_key ), 'synthetic order has a 32-char composite-derived old_key' );

$seeded = array();
foreach ( $aux_tables as $aux ) {
	$seeded[ $aux ] = $seed_aux( $aux, $old_key );
}
$chk( in_array( true, $seeded, true ), 'seeded at least one aux row at old_key' );

// ---- Run the migration ----------------------------------------------------
delete_option( $flag );
$res = eem_mig_042_backfill_order_tokens();
$chk( $res['orders'] >= 1, "migration re-keyed >= 1 order (got {$res['orders']})" );

// Component rows now tokenized with the SAME token.
$stall_notes = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes FROM {$stall_t} WHERE customer_name = %s", $marker ) );
$rv_notes    = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes FROM {$rv_t} WHERE customer_name = %s", $marker ) );
preg_match( '/Submission token:\s*([a-f0-9-]+)/i', $stall_notes, $sm );
preg_match( '/Submission token:\s*([a-f0-9-]+)/i', $rv_notes, $rm );
$token = isset( $sm[1] ) ? $sm[1] : '';
$chk( '' !== $token, 'stall row received a submission token' );
$chk( isset( $rm[1] ) && $rm[1] === $token, 'rv row received the SAME token (one order, one token)' );

$new_key = md5( $token );
$chk( $new_key !== $old_key, 'new_key differs from the guessable old_key' );

// Every seeded aux row moved old_key -> new_key, with nothing left behind.
foreach ( $aux_tables as $aux ) {
	if ( empty( $seeded[ $aux ] ) ) { continue; }
	$at_new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aux} WHERE order_key = %s", $new_key ) );
	$at_old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aux} WHERE order_key = %s", $old_key ) );
	$short  = str_replace( $wpdb->prefix, '', $aux );
	$chk( $at_new >= 1, "$short: row repointed to new_key" );
	$chk( 0 === $at_old, "$short: no row orphaned at old_key" );
}

// ---- Idempotency ----------------------------------------------------------
delete_option( $flag );
$res2 = eem_mig_042_backfill_order_tokens();
$stall_notes2 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes FROM {$stall_t} WHERE customer_name = %s", $marker ) );
$tok_count = preg_match_all( '/Submission token:/i', $stall_notes2, $ignored );
$chk( 1 === $tok_count, 'second run did NOT add a duplicate token (idempotent)' );

// ---- Cleanup --------------------------------------------------------------
$wpdb->delete( $stall_t, array( 'customer_name' => $marker ) );
$wpdb->delete( $rv_t, array( 'customer_name' => $marker ) );
foreach ( $aux_tables as $aux ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$aux} WHERE order_key = %s OR order_key = %s", $new_key, $old_key ) );
}
delete_option( $flag );

echo "\n$pass passed, $fail failed\n";
