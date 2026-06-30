<?php
/**
 * Behavioral smoke — the Collect Payment charge amount + the canonical
 * get_order_outstanding() equal composed grand − ledger net collected, so a
 * fully-collected order can't be re-charged and a partially-paid one charges only
 * the remainder (Whitney 2026-06-30 live audit — bug #9: get_order_amount_due
 * returned the full grand total, and the collect page used the component
 * amount_paid — so a fully-collected adjusted order showed the adjustment as
 * still due / chargeable).
 *
 * Run: wp eval-file tests/smoke/collect-charge-outstanding-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

$repo = new EEM_Orders_Repository();
$sc   = new EEM_Shortcodes();
$gad  = new ReflectionMethod( $sc, 'get_order_amount_due' ); $gad->setAccessible( true );

// The charge amount must equal get_order_outstanding for EVERY order — never the
// gross grand total when something's already collected.
$checked = 0; $worstDiff = 0.0; $charged_fully_collected = false;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	$o = $repo->get_order( $key );
	if ( ! is_array( $o ) ) { continue; }
	$outstanding = $repo->get_order_outstanding( $key, $o );
	$charge      = (float) $gad->invoke( $sc, $o, $key );
	$worstDiff   = max( $worstDiff, abs( $outstanding - $charge ) );
	// A fully-collected order (outstanding 0) must charge 0 (not re-chargeable).
	if ( $outstanding < 0.005 && $charge > 0.005 ) { $charged_fully_collected = true; }
	$checked++;
	if ( $checked >= 60 ) { break; }
}
$check( "charge amount == get_order_outstanding across {$checked} orders (max diff " . number_format( $worstDiff, 2 ) . ')', $worstDiff < 0.02, 'max diff ' . $worstDiff );
$check( 'no fully-collected order is re-chargeable (charge 0 when outstanding 0)', ! $charged_fully_collected );

// Targeted: an order with a custom item collected in full → outstanding 0.
$adjFull = null;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) { break; }
	$adj = EEM_Order_Adjustments_Repo::get_for_order( $key );
	$o = $repo->get_order( $key );
	if ( is_array( $o ) && ! empty( $adj['custom_items'] ) ) {
		$composed = EEM_Order_Adjustments_Repo::compose_order_totals( $o, $adj );
		if ( $repo->get_net_collected( $key, $o ) + 0.005 >= (float) $composed['grand_total'] ) { $adjFull = $o; break; }
	}
}
if ( null !== $adjFull ) {
	$check( 'fully-collected custom-item order: outstanding == 0 (no phantom adjustment due)', $repo->get_order_outstanding( (string) $adjFull['order_key'], $adjFull ) < 0.005 );
}

WP_CLI::log( "\n=== Collect/charge outstanding smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Collect/charge outstanding smoke passed.' );
