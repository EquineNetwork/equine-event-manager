<?php
/**
 * Behavioral smoke — the customer receipt + confirmation email show NET collected
 * (gross − refunds), consistent with the admin Order Detail (Whitney 2026-06-30,
 * "net the refund in", extended to customer surfaces in 2.7.715).
 *
 * get_order_net_collected() must (a) net ledger refunds, and (b) agree with the
 * admin EEM_Order_Detail_Page::compute_amount_paid for the same order.
 *
 * Run: wp eval-file tests/smoke/receipt-email-net-collected-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

$repo = new EEM_Orders_Repository();
$sc   = new EEM_Shortcodes();
$net  = new ReflectionMethod( $sc, 'get_order_net_collected' ); $net->setAccessible( true );
$page = new EEM_Order_Detail_Page();
$cap  = new ReflectionMethod( $page, 'compute_amount_paid' ); $cap->setAccessible( true );

// Find a real order with a ledger refund.
$refundedOrder = null; $gross = 0.0; $refunded = 0.0;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	$r = 0.0; $p = 0.0;
	foreach ( EEM_Order_Payments_Repo::get_for_order( $key ) as $e ) {
		if ( EEM_Order_Payments_Repo::DIRECTION_REFUND === ( $e['direction'] ?? '' ) ) { $r += (float) $e['amount']; }
		else { $p += (float) $e['amount']; }
	}
	if ( $p > 0 && $r > 0 ) { $refundedOrder = $repo->get_order( $key ); $gross = $p; $refunded = $r; break; }
}

if ( null === $refundedOrder ) {
	WP_CLI::warning( 'No order with a ledger refund found — skipping.' );
	WP_CLI::success( 'Skipped.' ); return;
}

$stored_gross = (float) ( $refundedOrder['amount_paid'] ?? 0 );
$receipt_net  = (float) $net->invoke( $sc, $refundedOrder );
$admin_net    = (float) $cap->invoke( $page, $refundedOrder );

$check( 'receipt net_collected nets out the refund (< stored gross amount_paid)', $receipt_net < $stored_gross - 0.005, 'net=' . $receipt_net . ' gross=' . $stored_gross );
$check( 'receipt net == gross − refunds (' . number_format( $receipt_net, 2 ) . ' == ' . number_format( max( 0.0, $stored_gross - $refunded ), 2 ) . ')', abs( $receipt_net - max( 0.0, $stored_gross - $refunded ) ) < 0.02 );
$check( 'receipt net == admin compute_amount_paid (surfaces agree)', abs( $receipt_net - $admin_net ) < 0.02, 'receipt=' . $receipt_net . ' admin=' . $admin_net );

// Positive control: an order with NO refund → net == gross (helper is a no-op).
$cleanOrder = null;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	$has_refund = false;
	foreach ( EEM_Order_Payments_Repo::get_for_order( $key ) as $e ) { if ( EEM_Order_Payments_Repo::DIRECTION_REFUND === ( $e['direction'] ?? '' ) ) { $has_refund = true; break; } }
	$o = $repo->get_order( $key );
	if ( ! $has_refund && is_array( $o ) && (float) ( $o['amount_paid'] ?? 0 ) > 0 ) { $cleanOrder = $o; break; }
}
if ( null !== $cleanOrder ) {
	$cn = (float) $net->invoke( $sc, $cleanOrder );
	$check( 'no-refund order: net == gross amount_paid (helper is a no-op)', abs( $cn - (float) $cleanOrder['amount_paid'] ) < 0.02, 'net=' . $cn . ' gross=' . $cleanOrder['amount_paid'] );
}

WP_CLI::log( "\n=== Receipt/email net-collected smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Receipt/email net-collected smoke passed.' );
