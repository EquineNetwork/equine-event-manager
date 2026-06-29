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

// Paid control: ensure status is paid AND strip any refund traces (the chosen
// order may itself be refunded in the live DB — scrub it to a clean paid state).
$paid = $order;
$paid['payment_status'] = 'paid';
// #55: the "Total Amount Paid" grand-total row renders only when the receipt has
// NO outstanding balance (and no refund). balance_due is computed from the
// composed grand total (which may include custom items / discounts) minus
// amount_paid, gated on status_slug==='paid'. Force a fully-settled control:
// mark it paid and record collection covering the full composed total.
$paid['status_slug']    = 'paid';
$paid['payment_status'] = 'paid';
$paid['amount_due']     = 0;
$paid['amount_paid']    = max(
	(float) ( $paid['total'] ?? 0 ),
	(float) ( $paid['grand_total'] ?? 0 ),
	(float) ( $paid['amount_paid'] ?? 0 )
) + 1000.0;
if ( ! empty( $paid['components'] ) ) {
	foreach ( $paid['components'] as $i => $c ) {
		$paid['components'][ $i ]['refunded_amount'] = 0;
		$paid['components'][ $i ]['payment_status']  = 'paid';
		$paid['components'][ $i ]['notes']           = preg_replace( '/^.*Refunded Amount:.*$/mi', '', (string) ( $c['notes'] ?? '' ) );
	}
}
$paid_html = (string) $ref->invoke( $sc, $paid, false );
$ok( 'paid receipt has NO refund banner div', false === strpos( $paid_html, '<div class="refund-banner">' ) );
$ok( 'paid receipt has NO status pill div', false === strpos( $paid_html, '<div class="receipt-status-pill">' ) );
$ok( 'paid receipt shows "Total Amount Paid"', false !== strpos( $paid_html, 'Total Amount Paid' ) );

// Refunded simulation — mirror the REAL data shape: the refunded amount lives
// in the component notes ("Refunded Amount: X"), NOT the (often-NULL) column.
$refunded = $order;
$refunded['payment_status'] = 'refunded';
if ( ! empty( $refunded['components'] ) ) {
	foreach ( $refunded['components'] as $i => $c ) {
		$refunded['components'][ $i ]['refunded_amount'] = null;
		$refunded['components'][ $i ]['payment_status']  = 'refunded';
		$refunded['components'][ $i ]['notes']           = (string) ( $c['notes'] ?? '' )
			. "\nRefunded Amount: " . number_format( (float) $order['total'], 2, '.', '' );
	}
}
$ref_html = (string) $ref->invoke( $sc, $refunded, false );
$ok( 'refunded receipt renders the banner div', false !== strpos( $ref_html, '<div class="refund-banner">' ) );
$ok( 'refunded receipt renders the status pill', false !== strpos( $ref_html, '<div class="receipt-status-pill">' ) );
$ok( 'refunded receipt shows "Refunded"', false !== strpos( $ref_html, 'Refunded' ) );
$ok( 'refunded receipt shows a "Net Paid" line', false !== strpos( $ref_html, 'Net Paid' ) );
$ok( 'refunded receipt has the returned-to-payment-method copy', false !== strpos( $ref_html, 'returned to the original payment method' ) );
// The amount must come through from the NOTES (column is NULL) — guards the
// real-world data shape where refunded_amount lives only in the notes.
$ok( 'refunded receipt shows the refunded amount from notes (not $0.00 detail)', false !== strpos( $ref_html, 'refund-banner-detail' ) );

echo implode( "\n", $log ) . "\n";
echo "receipt-refund-status-smoke: {$pass} passed, {$fail} failed\n";
