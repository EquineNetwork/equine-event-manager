<?php
/**
 * Behavioral smoke — the Dashboard "Revenue" KPI counts money NET of refunds, not
 * the gross component amount_paid (Whitney 2026-06-30 live audit — bug #11: the
 * revenue total summed the component amount_paid column, which is gross, so a
 * refunded order counted the refunded money as revenue, overstating it).
 *
 * Run: wp eval-file tests/smoke/dashboard-revenue-net-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

if ( ! class_exists( 'EEM_Dashboard_Repo' ) ) { WP_CLI::warning( 'EEM_Dashboard_Repo missing — skipping.' ); WP_CLI::success( 'Skipped.' ); return; }

$repo = new EEM_Orders_Repository();
$dr   = new EEM_Dashboard_Repo();
$m    = new ReflectionMethod( $dr, 'compute_revenue_outstanding_totals' ); $m->setAccessible( true );

// Find a real order with a ledger refund.
$refOrder = null; $gross = 0.0; $net = 0.0;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' ); if ( '' === $key ) { continue; }
	$r = 0.0; $p = 0.0;
	foreach ( EEM_Order_Payments_Repo::get_for_order( $key ) as $e ) {
		if ( EEM_Order_Payments_Repo::DIRECTION_REFUND === ( $e['direction'] ?? '' ) ) { $r += (float) $e['amount']; } else { $p += (float) $e['amount']; }
	}
	if ( $p > 0 && $r > 0 ) { $refOrder = $repo->get_order( $key ); $gross = (float) ( $refOrder['amount_paid'] ?? 0 ); $net = $repo->get_net_collected( $key, $refOrder ); break; }
}
if ( null === $refOrder ) { WP_CLI::warning( 'No refunded order found — skipping.' ); WP_CLI::success( 'Skipped.' ); return; }

$totals = $m->invoke( $dr, array( $refOrder ) );
$check( 'revenue == net collected (' . number_format( (float) $totals['revenue'], 2 ) . ' == ' . number_format( $net, 2 ) . ')', abs( (float) $totals['revenue'] - $net ) < 0.02 );
$check( 'revenue is LESS than the gross amount_paid (refund netted)', (float) $totals['revenue'] < $gross - 0.005, 'revenue=' . $totals['revenue'] . ' gross=' . $gross );

WP_CLI::log( "\n=== Dashboard revenue net smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Dashboard revenue net smoke passed.' );
