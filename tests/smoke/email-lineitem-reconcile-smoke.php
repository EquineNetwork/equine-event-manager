<?php
/**
 * Regression smoke — confirmation email line items reconcile to the Total Paid
 * (Whitney 2026-06-30, master live audit).
 *
 * The email itemizes via build_order_line_items($order, true). It added a
 * Convenience Fee line but NO Tax line, so the itemized rows summed SHORT by the
 * tax amount while the email's "Total Paid" included tax. Fix adds a Tax line
 * (mirroring the fee line) when $include_fee is on. The receipt/PDF variant
 * ($include_fee = false) must NOT add fee/tax lines (they carry their own summary).
 *
 * BEHAVIORAL: finds a REAL order with both a convenience fee and tax, builds the
 * email line items, and asserts a Fee + Tax line exist and the row totals sum to
 * the order total.
 *
 * Run: wp eval-file tests/smoke/email-lineitem-reconcile-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// Find a real order that has BOTH a convenience fee and tax.
$repo  = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders() as $row ) {
	$o = $repo->get_order( $row['order_key'] );
	if ( is_array( $o ) && (float) ( $o['fees'] ?? 0 ) > 0 && (float) ( $o['tax'] ?? 0 ) > 0 ) { $order = $o; break; }
}
if ( null === $order ) {
	WP_CLI::warning( 'No order with both fee + tax found — skipping (seed one to run this guard).' );
	WP_CLI::success( 'Skipped (no fee+tax order).' );
	return;
}

$sc  = new EEM_Shortcodes();
$ref = new ReflectionMethod( $sc, 'build_order_line_items' );
$ref->setAccessible( true );
$money = static function ( $s ) { return (float) preg_replace( '/[^0-9.]/', '', (string) $s ); };

// Email variant: include fee + tax.
$items = $ref->invoke( $sc, $order, true );
$sum = 0.0; $has_fee = false; $has_tax = false;
foreach ( $items as $it ) {
	$sum += $money( $it['total'] );
	if ( false !== stripos( (string) $it['section'], 'Fee' ) ) { $has_fee = true; }
	if ( false !== stripos( (string) $it['section'], 'Tax' ) ) { $has_tax = true; }
}
$total = (float) $order['total'];
$check( 'email line items include a Fee line', $has_fee );
$check( 'email line items include a Tax line', $has_tax );
$check( 'email line-item totals sum to the order total (' . number_format( $sum, 2 ) . ' == ' . number_format( $total, 2 ) . ')', abs( $sum - $total ) < 0.02 );

// Receipt/PDF variant: NO fee/tax lines (carried in their own summary).
$items_no = $ref->invoke( $sc, $order, false );
$no_fee_tax = true;
foreach ( $items_no as $it ) {
	if ( false !== stripos( (string) $it['section'], 'Fee' ) || false !== stripos( (string) $it['section'], 'Tax' ) ) { $no_fee_tax = false; }
}
$check( 'receipt variant (include_fee=false) does NOT add Fee/Tax lines', $no_fee_tax );

WP_CLI::log( "\n=== Email line-item reconcile smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Email line-item reconcile smoke passed.' );
