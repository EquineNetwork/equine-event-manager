<?php
/**
 * Behavioral smoke — the Orders list "Balance Due" badge agrees with the Order
 * Detail balance for orders with custom-item/discount adjustments (Whitney
 * 2026-06-30 live audit — bug #8: the list used the component-based amount_due,
 * which misses adjustments, so a "paid" order with a post-payment custom item
 * read green "Paid" on the list while the detail correctly showed a balance).
 *
 * EEM_Orders_List_Page::order_true_outstanding() must equal the Order Detail's
 * compute_balance_due() for the same order.
 *
 * Run: wp eval-file tests/smoke/orders-list-balance-badge-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

if ( ! class_exists( 'EEM_Orders_List_Page' ) || ! class_exists( 'EEM_Order_Detail_Page' ) ) {
	WP_CLI::warning( 'Required classes missing — skipping.' ); WP_CLI::success( 'Skipped.' ); return;
}

$repo = new EEM_Orders_Repository();
$lto  = new ReflectionMethod( 'EEM_Orders_List_Page', 'order_true_outstanding' ); $lto->setAccessible( true );
$page = new EEM_Order_Detail_Page();
$cbd  = new ReflectionMethod( $page, 'compute_balance_due' ); $cbd->setAccessible( true );

// Walk every order; for each, the list's true-outstanding must equal the
// detail's balance due. (This is the cross-surface agreement the bug broke.)
$checked = 0; $worst = 0.0;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$o = $repo->get_order( (string) $row['order_key'] );
	if ( ! is_array( $o ) ) { continue; }
	$listBal   = (float) $lto->invoke( null, $o );
	$detailBal = (float) $cbd->invoke( $page, $o );
	$worst = max( $worst, abs( $listBal - $detailBal ) );
	$checked++;
	if ( $checked >= 60 ) { break; } // bound the walk
}
$check( "list balance == detail balance across {$checked} orders (max diff " . number_format( $worst, 2 ) . ')', $worst < 0.02, 'max diff ' . $worst );

// Targeted: an order with a custom item + a ledger payment shows a positive
// outstanding equal to grand − collected (the bug-#8 scenario).
$adjOrder = null;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) { break; }
	$adj = EEM_Order_Adjustments_Repo::get_for_order( $key );
	$o = $repo->get_order( $key );
	$net = $repo->get_net_collected( $key, $o );
	if ( is_array( $o ) && ! empty( $adj['custom_items'] ) && $net > 0.005 ) { $adjOrder = $o; break; }
}
if ( null !== $adjOrder ) {
	$composed = EEM_Order_Adjustments_Repo::compose_order_totals( $adjOrder, EEM_Order_Adjustments_Repo::get_for_order( (string) $adjOrder['order_key'] ) );
	$expected = round( max( 0.0, (float) $composed['grand_total'] - $repo->get_net_collected( (string) $adjOrder['order_key'], $adjOrder ) ), 2 );
	$got = (float) $lto->invoke( null, $adjOrder );
	$check( 'custom-item order: list outstanding == composed grand − collected (' . number_format( $got, 2 ) . ' == ' . number_format( $expected, 2 ) . ')', abs( $got - $expected ) < 0.02 );
}

WP_CLI::log( "\n=== Orders list balance badge smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Orders list balance badge smoke passed.' );
