<?php
/**
 * Behavioral smoke — the RENDERED customer surfaces (confirmation email with
 * send-time CSS inlining, web/PDF receipt HTML, and the Dompdf PDF binary)
 * actually produce valid, complete, reconciling output — not just that the
 * line-item builder returns the right numbers (Whitney 2026-06-30 live audit).
 *
 * Guards against: the email shipping with no inlinable CSS (broken in Outlook),
 * the receipt template fatally erroring, and Dompdf producing an empty/invalid
 * PDF. Exercises the real private render methods + EEM_Mailer::inline_css +
 * EEM_PDF::render against a real order.
 *
 * Run: wp eval-file tests/smoke/surface-render-integrity-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

// Pick any real order with components.
$repo = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$o = $repo->get_order( (string) $row['order_key'] );
	if ( is_array( $o ) && ! empty( $o['components'] ) && (float) ( $o['total'] ?? 0 ) > 0 ) { $order = $o; break; }
}
if ( null === $order ) { WP_CLI::warning( 'No order with components found — skipping.' ); WP_CLI::success( 'Skipped.' ); return; }

$sc = new EEM_Shortcodes();
$call = function ( $n ) use ( $sc ) { $m = new ReflectionMethod( $sc, $n ); $m->setAccessible( true ); return $m; };
$email = $call( 'build_confirmation_email_html' );
$receipt = $call( 'build_receipt_html' );
$bli = $call( 'build_order_line_items' );

// --- Confirmation email: design-time <style> block → send-time inliner → style="" attrs ---
$eh = (string) $email->invoke( $sc, $order, false );
$check( 'email HTML renders (non-trivial)', strlen( $eh ) > 1000, 'len=' . strlen( $eh ) );
$check( 'email source carries a <style> block (design-time CSS)', false !== stripos( $eh, '<style' ) );
if ( class_exists( 'EEM_Mailer' ) ) {
	$inlined = EEM_Mailer::inline_css( $eh );
	$check( 'send-time Emogrifier inlining yields style="" attributes', substr_count( $inlined, 'style=' ) >= 5, 'count=' . substr_count( $inlined, 'style=' ) );
}
// Email itemized rows (fee+tax variant) reconcile to the stored total.
$items = $bli->invoke( $sc, $order, true );
$sum = 0.0; foreach ( $items as $it ) { $sum += (float) preg_replace( '/[^0-9.]/', '', (string) $it['total'] ); }
$check( 'email line items reconcile to stored total (' . number_format( $sum, 2 ) . ' == ' . number_format( (float) $order['total'], 2 ) . ')', abs( $sum - (float) $order['total'] ) < 0.02 );

// --- Receipt HTML (web + pdf variants) ---
$rh = (string) $receipt->invoke( $sc, $order, false );
$rhp = (string) $receipt->invoke( $sc, $order, true );
$check( 'receipt web HTML renders', strlen( $rh ) > 1000, 'len=' . strlen( $rh ) );
$check( 'receipt PDF-variant HTML renders', strlen( $rhp ) > 1000, 'len=' . strlen( $rhp ) );
$check( 'receipt shows a $ figure', (bool) preg_match( '/\$[0-9,]+\.\d{2}/', $rh ) );

// --- PDF binary (Dompdf) ---
if ( class_exists( 'EEM_PDF' ) && EEM_PDF::is_available() ) {
	$pdf = EEM_PDF::render( $rhp );
	$check( 'Dompdf produces a non-empty PDF', strlen( $pdf ) > 500, 'len=' . strlen( $pdf ) );
	$check( 'PDF carries a valid %PDF- header', '%PDF-' === substr( $pdf, 0, 5 ) );
} else {
	WP_CLI::log( '  (Dompdf unavailable — PDF binary check skipped)' );
}

WP_CLI::log( "\n=== Surface render integrity smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Surface render integrity smoke passed.' );
