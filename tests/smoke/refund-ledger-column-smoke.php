<?php
/**
 * Refund numeric-ledger smoke (security audit MEDIUM: refund ledger hardening).
 *
 * The refunded-to-date amount now lives in a dedicated numeric `refunded_amount`
 * column (mig-011) instead of only a mutable free-text notes line. Verifies:
 *  1. the column exists on both order tables;
 *  2. get_component_refunded_amount() prefers the column but takes max() with the
 *     notes parse (safe-side bias for an un-backfilled row);
 *  3. a real persist_component_refund() round-trip writes the numeric column AND
 *     the human-readable notes line, and the value reads back correctly.
 *
 * Run: wp eval-file tests/smoke/refund-ledger-column-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admin  = new EEM_Admin();
$engine = new EEM_Refund_Engine( $admin );

// --- 1. schema column present ---------------------------------------------
foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $suffix ) {
	$t   = $wpdb->prefix . $suffix;
	$col = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'refunded_amount'",
		$t
	) );
	$check( "{$suffix}.refunded_amount column exists", 1 === $col );
}

// --- 2. read precedence: max(column, notes) -------------------------------
$check( 'column-only component returns the column value',
	30.0 === (float) $engine->get_component_refunded_amount( array( 'refunded_amount' => 30.0, 'notes' => '', 'total' => 100.0 ) ) );
$check( 'notes-only (un-backfilled) component falls back to the notes value',
	50.0 === (float) $engine->get_component_refunded_amount( array( 'refunded_amount' => 0.0, 'notes' => "Refunded Amount: 50.00", 'total' => 100.0 ) ) );
$check( 'column + smaller notes → column wins (max)',
	80.0 === (float) $engine->get_component_refunded_amount( array( 'refunded_amount' => 80.0, 'notes' => "Refunded Amount: 50.00", 'total' => 100.0 ) ) );
$check( 'column + larger notes → notes floor wins (safe side)',
	50.0 === (float) $engine->get_component_refunded_amount( array( 'refunded_amount' => 30.0, 'notes' => "Refunded Amount: 50.00", 'total' => 100.0 ) ) );
$check( 'fully-refunded with no ledger falls back to total',
	100.0 === (float) $engine->get_component_refunded_amount( array( 'refunded_amount' => 0.0, 'notes' => '', 'payment_status' => 'refunded', 'total' => 100.0 ) ) );

// --- 3. real persist round-trip -------------------------------------------
$table = $wpdb->prefix . 'eem_stall_reservations';
$wpdb->insert(
	$table,
	array(
		'customer_name'  => 'Ledger Smoke',
		'email'          => 'ledger-smoke@example.test',
		'total'          => 100.00,
		'payment_status' => 'paid',
		'payment_gateway'=> 'stripe',
		'transaction_id' => 'pi_ledger_smoke',
		'notes'          => '',
	),
	array( '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
);
$row_id = (int) $wpdb->insert_id;
$check( 'seeded a test stall component row', $row_id > 0 );

if ( $row_id > 0 ) {
	$component = array( 'table' => 'stall', 'row_id' => $row_id, 'total' => 100.0, 'notes' => '', 'payment_status' => 'paid', 'refunded_amount' => 0.0 );

	// First $40 refund.
	$engine->persist_component_refund( $component, 40.00, 're_smoke_1', array() );
	$after1 = $wpdb->get_row( $wpdb->prepare( "SELECT refunded_amount, payment_status, notes FROM `{$table}` WHERE id = %d", $row_id ), ARRAY_A );
	$check( 'first refund writes numeric column = 40.00', 40.0 === (float) $after1['refunded_amount'] );
	$check( 'first refund leaves status partially_refunded', 'partially_refunded' === (string) $after1['payment_status'] );
	$check( 'human-readable notes line written too', false !== strpos( (string) $after1['notes'], 'Refunded Amount: 40.00' ) );

	// Second $60 refund accumulates to the full total → refunded status.
	$component2 = array( 'table' => 'stall', 'row_id' => $row_id, 'total' => 100.0, 'notes' => (string) $after1['notes'], 'payment_status' => 'partially_refunded', 'refunded_amount' => (float) $after1['refunded_amount'] );
	$engine->persist_component_refund( $component2, 60.00, 're_smoke_2', array() );
	$after2 = $wpdb->get_row( $wpdb->prepare( "SELECT refunded_amount, payment_status FROM `{$table}` WHERE id = %d", $row_id ), ARRAY_A );
	$check( 'accumulated refund column = 100.00', 100.0 === (float) $after2['refunded_amount'] );
	$check( 'fully-refunded status set', 'refunded' === (string) $after2['payment_status'] );

	// Read-back via the engine using the freshly-stored column.
	$reloaded = array( 'refunded_amount' => (float) $after2['refunded_amount'], 'notes' => '', 'total' => 100.0 );
	$check( 'engine reads back accumulated 100.00 from the column',
		100.0 === (float) $engine->get_component_refunded_amount( $reloaded ) );
	$check( 'remaining refundable is now 0',
		0.0 === (float) $engine->get_component_remaining_refundable_amount( array( 'refunded_amount' => 100.0, 'notes' => '', 'total' => 100.0 ) ) );

	// Cleanup.
	$wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) );
	$check( 'cleaned up the test row', null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id = %d", $row_id ) ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
