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

// Canonical invariant (ledger-based, 2.7.716): for ANY order with a ledger,
// net collected == Σ(ledger payments) − Σ(ledger refunds) — independent of the
// per-component amount_paid (which can't represent custom-item adjustments).
$ledgerOrder = null; $exp_payments = 0.0; $exp_refunds = 0.0;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	$led = EEM_Order_Payments_Repo::get_for_order( $key );
	if ( empty( $led ) ) { continue; }
	$p = 0.0; $r = 0.0;
	foreach ( $led as $e ) {
		if ( EEM_Order_Payments_Repo::DIRECTION_REFUND === ( $e['direction'] ?? '' ) ) { $r += (float) $e['amount']; }
		else { $p += (float) $e['amount']; }
	}
	$ledgerOrder = $repo->get_order( $key ); $exp_payments = $p; $exp_refunds = $r; break;
}
if ( null !== $ledgerOrder ) {
	$cn = (float) $net->invoke( $sc, $ledgerOrder );
	$exp = round( max( 0.0, $exp_payments - $exp_refunds ), 2 );
	$check( 'ledger order: net == Σ(ledger payments) − Σ(ledger refunds) (' . number_format( $cn, 2 ) . ' == ' . number_format( $exp, 2 ) . ')', abs( $cn - $exp ) < 0.02 );
}

// Adjustment invariant: a paid order with custom items collects the FULL composed
// grand total (not just the component subtotal) — no phantom balance.
$page2 = new EEM_Order_Detail_Page();
$cgt = new ReflectionMethod( $page2, 'compute_grand_total' ); $cgt->setAccessible( true );
$cbd = new ReflectionMethod( $page2, 'compute_balance_due' ); $cbd->setAccessible( true );
$adjPaidOrder = null;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) { break; }
	$adj = EEM_Order_Adjustments_Repo::get_for_order( $key );
	$o = $repo->get_order( $key );
	if ( is_array( $o ) && ! empty( $adj['custom_items'] ) && (float) $repo->get_net_collected( $key, $o ) > 0.005 ) { $adjPaidOrder = $o; break; }
}
if ( null !== $adjPaidOrder ) {
	$grand = (float) $cgt->invoke( $page2, $adjPaidOrder );
	$bal   = (float) $cbd->invoke( $page2, $adjPaidOrder );
	$coll  = (float) $repo->get_net_collected( (string) $adjPaidOrder['order_key'], $adjPaidOrder );
	// If fully collected (ledger >= grand), balance must be 0 — the phantom-balance bug.
	if ( $coll + 0.005 >= $grand ) {
		$check( 'paid order with custom items has NO phantom balance (balance == 0)', $bal < 0.005, 'grand=' . $grand . ' collected=' . $coll . ' balance=' . $bal );
	}
}

WP_CLI::log( "\n=== Receipt/email net-collected smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Receipt/email net-collected smoke passed.' );
