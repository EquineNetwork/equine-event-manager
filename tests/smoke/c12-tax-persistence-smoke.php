<?php
/**
 * C12 increment 1 smoke — tax persistence + grouping aggregation.
 *
 * Inserts a synthetic stall + RV row sharing one submission token (so they group
 * into a single order), with the full order tax stored on the stall row. Then
 * fetches the grouped order via the canonical consumer
 * (EEM_Orders_Repository::get_order_by_submission_token) and asserts the order
 * exposes tax + tax_rate and that the grouped total includes tax.
 *
 * Run: wp eval-file tests/smoke/c12-tax-persistence-smoke.php
 *
 * NOTE: this exercises the READ/grouping path. The checkout WRITE path
 * (per-row tax insert) is verified separately with a live test checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$stall_table = $wpdb->prefix . 'en_stall_reservations';
$rv_table    = $wpdb->prefix . 'en_rv_reservations';
$token       = 'test-c12-tax-' . wp_generate_password( 8, false );
$notes       = "Reservation setup ID: 0\nSubmission token: {$token}";

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// ── Stall row carries the full order tax (14.10 @ 7.5%). total = subtotal+fee. ──
$wpdb->insert( $stall_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Tax Tester', 'email' => 'tax@example.com', 'phone' => '5550001111',
	'stall_qty' => 1, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
	'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
	'unit_price' => 10.00, 'subtotal' => 148.00, 'convenience_fee' => 6.56,
	'tax' => 14.10, 'tax_rate' => 7.500, 'total' => 154.56,
	'payment_status' => 'completed', 'payment_gateway' => 'stripe',
	'order_number' => '90001', 'transaction_id' => 'ch_tax', 'refund_transaction_id' => '',
	'notes' => $notes, 'created_at' => '2026-04-24 10:30:00',
) );
$stall_id = (int) $wpdb->insert_id;

// ── RV row: no tax (the stall row holds it). ──
$wpdb->insert( $rv_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Tax Tester', 'email' => 'tax@example.com', 'phone' => '5550001111',
	'rv_qty' => 1, 'rv_type' => '', 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
	'unit_price' => 20.00, 'subtotal' => 40.00, 'convenience_fee' => 0.00,
	'tax' => 0.00, 'tax_rate' => 7.500, 'total' => 40.00,
	'payment_status' => 'completed', 'payment_gateway' => 'stripe',
	'order_number' => '90001', 'transaction_id' => 'ch_tax', 'refund_transaction_id' => '',
	'notes' => $notes, 'created_at' => '2026-04-24 10:30:00',
) );
$rv_id = (int) $wpdb->insert_id;

$repo  = new EEM_Orders_Repository();
$order = $repo->get_order_by_submission_token( $token );

$check( 'grouped order found by submission token', is_array( $order ) && ! empty( $order ) );
if ( is_array( $order ) ) {
	$check( 'order exposes tax = 14.10', abs( (float) $order['tax'] - 14.10 ) < 0.001 );
	$check( 'order exposes tax_rate = 7.5', abs( (float) $order['tax_rate'] - 7.5 ) < 0.001 );
	$check( 'stall_subtotal = 148.00', abs( (float) $order['stall_subtotal'] - 148.00 ) < 0.001 );
	$check( 'rv_subtotal = 40.00', abs( (float) $order['rv_subtotal'] - 40.00 ) < 0.001 );
	$check( 'fees = 6.56', abs( (float) $order['fees'] - 6.56 ) < 0.001 );
	// total = sum(row.total) + sum(row.tax) = (154.56 + 40.00) + (14.10 + 0) = 208.66
	$check( 'grouped total INCLUDES tax = 208.66', abs( (float) $order['total'] - 208.66 ) < 0.001 );
}

// ── Cleanup ──
$wpdb->delete( $stall_table, array( 'id' => $stall_id ) );
$wpdb->delete( $rv_table, array( 'id' => $rv_id ) );

WP_CLI::log( "\n=== C12 tax smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C12 tax persistence (grouping) smoke passed.' );
