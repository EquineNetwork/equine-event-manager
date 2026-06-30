<?php
/**
 * Behavioral smoke — the Collect Payment "Paid Cash" tab pre-fills the
 * "Amount Received" field with the fee-waived balance, not $0.00 (Whitney
 * 2026-06-30 live audit — found render_payment_card referenced an out-of-scope
 * $cash_total_due, so the field rendered number_format(null) = "$0.00").
 *
 * Renders the real private render_payment_card() with a known fee-waived amount
 * and asserts the cash field carries it.
 *
 * Run: wp eval-file tests/smoke/collect-payment-cash-field-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

if ( ! class_exists( 'EEM_Collect_Payment_Page' ) ) {
	WP_CLI::warning( 'EEM_Collect_Payment_Page missing — skipping.' );
	WP_CLI::success( 'Skipped.' ); return;
}

$m = new ReflectionMethod( 'EEM_Collect_Payment_Page', 'render_payment_card' );
$m->setAccessible( true );

// total_due (card-inclusive) $83.20, fee-waived cash balance $80.00.
ob_start();
$m->invoke( null, 'http://example.com/detail', 'smoke-key', 83.20, 'c@example.com', 80.00 );
$html = ob_get_clean();

if ( preg_match( '/id="eem-cp-cash-amount"[^>]*value="([^"]*)"/', $html, $mm ) ) {
	$val = $mm[1];
	$check( 'cash "Amount Received" field pre-fills the fee-waived amount ($80.00)', '$80.00' === $val, 'got ' . $val );
	$check( 'cash field is NOT the $0.00 out-of-scope default', '$0.00' !== $val, 'got ' . $val );
} else {
	$check( 'cash amount field rendered', false, 'field not found in output' );
}

// Settled order (total_due 0) shows no cash field — sanity that the method branches.
ob_start();
$m->invoke( null, 'http://example.com/detail', 'smoke-key2', 0.0, 'c@example.com', 0.0 );
$paid_html = ob_get_clean();
$check( 'a settled order (balance 0) renders no payable cash field', false === strpos( $paid_html, 'id="eem-cp-cash-amount"' ) );

WP_CLI::log( "\n=== Collect Payment cash field smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Collect Payment cash field smoke passed.' );
