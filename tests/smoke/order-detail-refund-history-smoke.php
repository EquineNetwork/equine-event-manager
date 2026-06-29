<?php
/**
 * Smoke: the Order Detail "Payment Details" card renders PERSISTED refund history
 * on page load (not just after an in-page AJAX refund), and shows "No refunds
 * processed" only when the order genuinely has none.
 *
 * Regression guard for the bug where a refunded order's Order Detail kept showing
 * "No refunds processed" after reload because the block was hardcoded and only
 * updated by JS post-refund.
 *
 * Simulates the refunded shape in memory (notes carry "Refunded Amount").
 *
 * Run: wp eval-file tests/smoke/order-detail-refund-history-smoke.php
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0; $fail = 0; $log = array();
$ok = function ( $n, $c ) use ( &$pass, &$fail, &$log ) { if ( $c ) { $pass++; $log[] = "PASS  $n"; } else { $fail++; $log[] = "FAIL  $n"; } };

$repo = new EEM_Orders_Repository();
$list = $repo->get_orders();
$base = $list ? $repo->get_order( reset( $list )['order_key'] ) : null;
if ( ! is_array( $base ) || empty( $base['components'] ) ) {
	echo "order-detail-refund-history-smoke: SKIP (no order with components)\n";
	return;
}

$page = new EEM_Order_Detail_Page();
$m    = new ReflectionMethod( $page, 'render_payment_details_card' );
$m->setAccessible( true );
$render = function ( $order ) use ( $m, $page ) { ob_start(); $m->invoke( $page, $order ); return (string) ob_get_clean(); };

// Clean (no refund) — strip any refund note lines.
$clean = $base;
foreach ( $clean['components'] as $i => $c ) {
	$clean['components'][ $i ]['notes'] = preg_replace( '/^.*Refunded Amount:.*$/mi', '', (string) ( $c['notes'] ?? '' ) );
}
$clean_html = $render( $clean );
$ok( 'clean order shows "No refunds processed"', false !== strpos( $clean_html, 'No refunds processed' ) );
$ok( 'clean order has no refund-line', false === strpos( $clean_html, 'eem-order-payment__refund-line' ) );

// Refunded — inject the persisted note shape. The card prefers the C14 payments
// ledger when the order has one (get_for_order keys off order_key), so to
// exercise the LEGACY note-based refund path (imported / pre-ledger orders) we
// point the in-memory order at a key with no ledger entries.
$refunded = $base;
$refunded['order_key'] = 'eem-noledger-refund-' . uniqid();
foreach ( $refunded['components'] as $i => $c ) {
	$refunded['components'][ $i ]['notes'] = (string) ( $c['notes'] ?? '' )
		. "\nRefunded Amount: 2.00\nLast Refund Transaction: authorize-net-refund\nLast Refunded At: 2026-06-09 21:23:48";
	break; // one component is enough
}
$ref_html = $render( $refunded );
$ok( 'refunded order does NOT show "No refunds processed"', false === strpos( $ref_html, 'No refunds processed' ) );
$ok( 'refunded order renders a refund-line', false !== strpos( $ref_html, 'eem-order-payment__refund-line' ) );
$ok( 'refund-line shows the amount', false !== strpos( $ref_html, '$2.00' ) );
$ok( 'refund-line shows the transaction id', false !== strpos( $ref_html, 'authorize-net-refund' ) );

echo implode( "\n", $log ) . "\n";
echo "order-detail-refund-history-smoke: {$pass} passed, {$fail} failed\n";
