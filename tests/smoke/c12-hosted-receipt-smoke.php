<?php
/**
 * C12 increment 4 smoke — hosted order receipt (order_key lookup + URL + email link).
 *
 * Seeds an order row carrying a submission token (order_key = md5(token)), then
 * verifies: the repo finds it by order_key; get_hosted_receipt_url builds the
 * token-bearer URL (HTML + PDF variants); and the confirmation email re-enables
 * the "view your order online" hosted link.
 *
 * Run: wp eval-file tests/smoke/c12-hosted-receipt-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

global $wpdb;
$stall_table = $wpdb->prefix . 'en_stall_reservations';
// Submission tokens are hex (the extractor regex is [a-f0-9-]); use a hex token.
$token       = md5( 'c12hosted-' . wp_generate_password( 12, false ) );
$order_key   = md5( sanitize_text_field( $token ) );
$notes       = "Reservation setup ID: 0\nSubmission token: {$token}";

$wpdb->insert( $stall_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Hosted Tester', 'email' => 'hosted@example.com', 'phone' => '5550001111',
	'stall_qty' => 1, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
	'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
	'unit_price' => 20.00, 'subtotal' => 40.00, 'convenience_fee' => 0.00,
	'tax' => 3.00, 'tax_rate' => 7.500, 'total' => 40.00,
	'payment_status' => 'completed', 'payment_gateway' => 'stripe',
	'order_number' => '90050', 'transaction_id' => 'ch_h', 'refund_transaction_id' => '',
	'notes' => $notes, 'created_at' => '2026-04-24 10:30:00',
) );
$stall_id = (int) $wpdb->insert_id;

$repo  = new EEM_Orders_Repository();
$order = $repo->get_order_by_order_key( $order_key );

$check( 'get_order_by_order_key finds the order', is_array( $order ) && ! empty( $order ) );
$check( 'returned order carries the matching order_key', is_array( $order ) && isset( $order['order_key'] ) && hash_equals( $order['order_key'], $order_key ) );
$check( 'unknown order_key returns null', null === $repo->get_order_by_order_key( 'nope-not-a-key' ) );
$check( 'empty order_key returns null', null === $repo->get_order_by_order_key( '' ) );

$s   = new EEM_Shortcodes();
$url = $s->get_hosted_receipt_url( $order_key );
$pdf = $s->get_hosted_receipt_url( $order_key, true );
$check( 'hosted URL carries eem_receipt token', false !== strpos( $url, 'eem_receipt=' . $order_key ) );
$check( 'hosted URL is on home_url', 0 === strpos( $url, home_url( '/' ) ) );
$check( 'PDF URL adds download=pdf', false !== strpos( $pdf, 'download=pdf' ) );
$check( 'empty key yields empty URL', '' === $s->get_hosted_receipt_url( '' ) );

// Confirmation email re-enables the hosted link now that order_key resolves.
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'build_confirmation_email_html' );
$ref->setAccessible( true );
$order['reservation_title'] = '2026 Southeast Region Super Sort';
$order['event_name'] = '2026 Southeast Region Super Sort';
$html = (string) $ref->invoke( $s, $order, false );
$check( 'confirmation email includes the hosted receipt link', false !== strpos( $html, 'eem_receipt=' . $order_key ) );
$check( 'confirmation email shows the "view your order online" anchor', false !== strpos( $html, 'view your order online' ) );

$wpdb->delete( $stall_table, array( 'id' => $stall_id ) );

WP_CLI::log( "\n=== C12 hosted receipt smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C12 hosted receipt smoke passed.' );
