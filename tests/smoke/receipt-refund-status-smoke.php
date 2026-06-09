<?php
/**
 * Smoke: the order receipt (hosted + PDF share one template) surfaces refund /
 * void status. A refunded order's receipt must NOT read like a clean paid
 * receipt — it shows a status banner + a "Refunded" pill + a Net Paid line; a
 * normal paid order shows none of that.
 *
 * Simulates the refunded order IN MEMORY (no DB write) so it needs no special
 * fixture — it grabs any existing order and mutates a copy.
 *
 * Run: wp eval-file tests/smoke/receipt-refund-status-smoke.php
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0;
$fail = 0;
$log  = array();
$ok   = function ( $name, $cond ) use ( &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; $log[] = "PASS  $name"; }
	else         { $fail++; $log[] = "FAIL  $name"; }
};

$repo  = new EEM_Orders_Repository();
$list  = $repo->get_orders();
$order = $list ? $repo->get_order( reset( $list )['order_key'] ) : null;
if ( ! is_array( $order ) ) {
	echo "receipt-refund-status-smoke: SKIP (no orders to render)\n";
	return;
}

$ref = new ReflectionMethod( 'EEM_Shortcodes', 'build_receipt_html' );
$ref->setAccessible( true );
$sc  = new EEM_Shortcodes();

// Paid control: ensure status is paid.
$paid = $order;
$paid['payment_status'] = 'paid';
if ( ! empty( $paid['components'] ) ) {
	foreach ( $paid['components'] as $i => $c ) { $paid['components'][ $i ]['refunded_amount'] = 0; }
}
$paid_html = (string) $ref->invoke( $sc, $paid, false );
$ok( 'paid receipt has NO refund banner div', false === strpos( $paid_html, '<div class="refund-banner">' ) );
$ok( 'paid receipt has NO status pill div', false === strpos( $paid_html, '<div class="receipt-status-pill">' ) );
$ok( 'paid receipt shows "Total Amount Paid"', false !== strpos( $paid_html, 'Total Amount Paid' ) );

// Refunded simulation.
$refunded = $order;
$refunded['payment_status'] = 'refunded';
if ( ! empty( $refunded['components'] ) ) {
	foreach ( $refunded['components'] as $i => $c ) {
		$refunded['components'][ $i ]['refunded_amount'] = (float) $order['total'];
		$refunded['components'][ $i ]['payment_status']  = 'refunded';
	}
}
$ref_html = (string) $ref->invoke( $sc, $refunded, false );
$ok( 'refunded receipt renders the banner div', false !== strpos( $ref_html, '<div class="refund-banner">' ) );
$ok( 'refunded receipt renders the status pill', false !== strpos( $ref_html, '<div class="receipt-status-pill">' ) );
$ok( 'refunded receipt shows "Refunded"', false !== strpos( $ref_html, 'Refunded' ) );
$ok( 'refunded receipt shows a "Net Paid" line', false !== strpos( $ref_html, 'Net Paid' ) );
$ok( 'refunded receipt has the returned-to-payment-method copy', false !== strpos( $ref_html, 'returned to the original payment method' ) );

echo implode( "\n", $log ) . "\n";
echo "receipt-refund-status-smoke: {$pass} passed, {$fail} failed\n";
