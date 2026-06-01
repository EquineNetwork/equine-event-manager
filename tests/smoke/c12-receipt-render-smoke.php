<?php
/**
 * C12 increment 2 smoke — order receipt render (build_receipt_html).
 *
 * Renders the receipt against a synthetic order + seeded reservation and asserts
 * content-density per section (Customer/Billing, Reservation Summary cards,
 * itemized totals incl. the Sales Tax line) plus the structural rules: the fee
 * appears in TOTALS not in the items table, and assignments are omitted.
 *
 * Run: wp eval-file tests/smoke/c12-receipt-render-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => '2026 Southeast Region Super Sort' ) );
update_post_meta( $rid, '_en_required_shavings_price', '22.00' );
update_post_meta( $rid, '_en_cancellation_policy_override', 'Full refund 14+ days before the event; non-refundable within 14 days.' );

$order = array(
	'order_key' => 'rcpt', 'order_number' => '42', 'created_at' => '2026-04-24 10:30:00',
	'reservation_id' => $rid, 'reservation_title' => '2026 Southeast Region Super Sort',
	'event_name' => '2026 Southeast Region Super Sort', 'event_dates' => 'May 8, 2026 – May 10, 2026',
	'customer_name' => 'Whitney Mitchell', 'email' => 'info@wmpromotions.com', 'phone' => '5593935352',
	'type_labels' => array( 'stall' => 'Stall', 'rv' => 'RV' ),
	'total' => 208.66, 'fees' => 6.56, 'tax' => 14.10, 'tax_rate' => 7.500, 'transaction_id' => 'ch_1',
	'notes' => "Billing Details:\n12253 Avenue 472\nOrange Cove, California 93646\nSpecial Requests:\nEarly arrival please\nReservation setup ID: {$rid}",
	'components' => array(),
	'stall_quantity' => 1, 'stall_subtotal' => 64.00, 'stall_arrival_date' => '2026-05-08', 'stall_departure_date' => '2026-05-10',
	'stall_stay_type' => 'nightly', 'required_shavings_qty' => 2, 'additional_shavings_qty' => 0,
	'rv_quantity' => 1, 'rv_arrival_date' => '2026-05-08', 'rv_departure_date' => '2026-05-10',
	'rv_stay_type' => 'nightly', 'rv_subtotal' => 40.00, 'rv_type' => '',
);

$shortcodes = new EEM_Shortcodes();
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'build_receipt_html' );
$ref->setAccessible( true );
$html = (string) $ref->invoke( $shortcodes, $order );
WP_CLI::log( 'Rendered receipt length: ' . strlen( $html ) );

// Header / order box
$check( 'receipt tag shows 5-digit order number #00042', false !== strpos( $html, '#00042' ) );
$check( 'event title present', false !== strpos( $html, '2026 Southeast Region Super Sort' ) );
$check( 'payment date MM-DD-YYYY (04-24-2026)', false !== strpos( $html, '04-24-2026' ) );
$check( 'amount paid $208.66', false !== strpos( $html, '$208.66' ) );

// Customer + billing
$check( 'Customer Details block', false !== strpos( $html, 'Customer Details' ) );
$check( 'customer name rendered', false !== strpos( $html, 'Whitney Mitchell' ) );
$check( 'customer email rendered', false !== strpos( $html, 'info@wmpromotions.com' ) );
$check( 'customer phone formatted (559)', false !== strpos( $html, '(559) 393-5352' ) );
$check( 'Billing Details block', false !== strpos( $html, 'Billing Details' ) );
$check( 'billing address line rendered', false !== strpos( $html, '12253 Avenue 472' ) );

// Reservation summary cards
$check( 'Reservation Summary label', false !== strpos( $html, 'Reservation Summary' ) );
$check( 'Stall Reservation card', false !== strpos( $html, 'Stall Reservation' ) );
$check( 'RV Reservation card', false !== strpos( $html, 'RV Reservation' ) );

// Line items (fee NOT here)
$check( 'items: Stall Res. row', false !== strpos( $html, 'Stall Res.' ) );
$check( 'items: Required Shavings row', false !== strpos( $html, 'Required Shavings' ) );
$check( 'items: RV Res. row', false !== strpos( $html, 'RV Res.' ) );

// Special requests
$check( 'Special Requests block', false !== strpos( $html, 'Special Requests' ) && false !== strpos( $html, 'Early arrival please' ) );

// Totals (itemized + subtotal + fee + TAX + grand)
$check( 'totals: Subtotal line', false !== strpos( $html, 'Subtotal' ) );
$check( 'totals: Convenience Fee line', false !== strpos( $html, 'Convenience Fee' ) );
$check( 'totals: Sales Tax (7.5%) line', false !== strpos( $html, 'Sales Tax (7.5%)' ) );
$check( 'totals: $14.10 tax amount', false !== strpos( $html, '$14.10' ) );
$check( 'totals: Total Amount Paid', false !== strpos( $html, 'Total Amount Paid' ) );

// Cancellation
$check( 'Cancellation Policy with override text', false !== strpos( $html, 'Cancellation Policy' ) && false !== strpos( $html, 'Full refund 14+ days' ) );

// Structural rules
$check( 'STRUCT: fee is NOT a line in the items table (Fee section label absent)', false === strpos( $html, '>Fee<' ) );
$check( 'STRUCT: no "Your Assignments" (nothing assigned)', false === strpos( $html, 'Your Assignments' ) );

wp_delete_post( $rid, true );

WP_CLI::log( "\n=== C12 receipt smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C12 receipt render smoke passed.' );
