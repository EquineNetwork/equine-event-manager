<?php
/**
 * Behavioral smoke — a partial refund nets into the Order Detail Balance Due
 * (Whitney 2026-06-30 live audit decision: "net the refund in").
 *
 * compute_amount_paid() previously returned the GROSS collected amount, so a
 * refund recorded in the payments ledger did not reduce what the Order Detail
 * showed as collected — the Balance Due understated what the customer owed by the
 * refunded amount. The fix subtracts ledger refunds, so:
 *   net collected = gross amount_paid − Σ(ledger refunds)
 *   balance due   = effective grand total − net collected
 * This also lets the Edit-Dates-shorten "Refund Due" banner clear once the owed
 * amount is actually refunded.
 *
 * BEHAVIORAL: exercises the real private compute_amount_paid / compute_balance_due
 * against an order whose payments ledger carries a real refund entry.
 *
 * Run: wp eval-file tests/smoke/refund-nets-into-balance-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

if ( ! class_exists( 'EEM_Order_Payments_Repo' ) || ! class_exists( 'EEM_Order_Detail_Page' ) ) {
	WP_CLI::warning( 'Required classes missing — skipping.' );
	WP_CLI::success( 'Skipped.' ); return;
}

$page = new EEM_Order_Detail_Page();
$cap  = new ReflectionMethod( $page, 'compute_amount_paid' );  $cap->setAccessible( true );
$cbd  = new ReflectionMethod( $page, 'compute_balance_due' );  $cbd->setAccessible( true );

// Find a real order that has BOTH a payment and a refund in its ledger.
$repo = new EEM_Orders_Repository();
$target = null; $gross = 0.0; $refunded = 0.0;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$key = (string) ( $row['order_key'] ?? '' );
	if ( '' === $key ) { continue; }
	$led = EEM_Order_Payments_Repo::get_for_order( $key );
	$p = 0.0; $r = 0.0;
	foreach ( $led as $e ) {
		if ( EEM_Order_Payments_Repo::DIRECTION_REFUND === ( $e['direction'] ?? '' ) ) { $r += (float) $e['amount']; }
		else { $p += (float) $e['amount']; }
	}
	if ( $p > 0 && $r > 0 ) { $target = $repo->get_order( $key ); $gross = $p; $refunded = $r; break; }
}

if ( null === $target ) {
	WP_CLI::warning( 'No order with a payment + a refund in the ledger found — skipping (refund one to run this guard).' );
	WP_CLI::success( 'Skipped (no refunded order).' ); return;
}

WP_CLI::log( sprintf( 'Order %s — gross ledger paid $%.2f, refunded $%.2f', $target['order_key'], $gross, $refunded ) );

$stored_gross = (float) ( $target['amount_paid'] ?? 0 );
$net_paid     = (float) $cap->invoke( $page, $target );
$balance      = (float) $cbd->invoke( $page, $target );

$check( 'compute_amount_paid nets out the ledger refund (net < stored gross amount_paid)', $net_paid < $stored_gross - 0.005 );
$check( 'net paid == stored gross − refunds (' . number_format( $net_paid, 2 ) . ' == ' . number_format( max( 0.0, $stored_gross - $refunded ), 2 ) . ')', abs( $net_paid - max( 0.0, $stored_gross - $refunded ) ) < 0.02 );

// Effective grand total via the page's own computation.
$cgt = new ReflectionMethod( $page, 'compute_grand_total' ); $cgt->setAccessible( true );
$grand = (float) $cgt->invoke( $page, $target );
$expected_balance = round( max( 0.0, $grand - $net_paid ), 2 );
$check( 'balance due reflects the netted refund (' . number_format( $balance, 2 ) . ' == ' . number_format( $expected_balance, 2 ) . ')', abs( $balance - $expected_balance ) < 0.02 );

// The refund handler now asks the client to reload so the authoritative render shows.
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'refund handler returns requires_reload => true', false !== strpos( $src, "'requires_reload'      => true" ) );

WP_CLI::log( "\n=== Refund-nets-into-balance smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Refund-nets-into-balance smoke passed.' );
