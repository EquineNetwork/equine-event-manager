<?php
/**
 * Behavioral smoke — the payment-link INVOICE email's "Amount Due" equals the
 * canonical outstanding (composed grand incl. custom items/discount − ledger
 * collected), so the invoiced amount matches what the payment link charges
 * (get_order_amount_due). (Whitney 2026-06-30 live audit — bug #12: it showed the
 * base component total, understating adjusted orders.)
 *
 * Run: wp eval-file tests/smoke/invoice-email-amount-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $x='' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}  {$x}" ); }
};

if ( ! class_exists( 'EEM_Admin' ) || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) { WP_CLI::warning( 'Required classes missing — skipping.' ); WP_CLI::success( 'Skipped.' ); return; }

$repo  = new EEM_Orders_Repository();
$admin = new EEM_Admin();
$bih   = new ReflectionMethod( $admin, 'build_invoice_email_html' ); $bih->setAccessible( true );

// Find an unpaid order (invoices only go to unpaid/invoice-sent).
$target = null;
foreach ( $repo->get_orders( '', 'date', 'desc', '' ) as $row ) {
	$o = $repo->get_order( (string) $row['order_key'] );
	if ( is_array( $o ) && in_array( (string) ( $o['status_slug'] ?? '' ), array( 'unpaid', 'invoice-sent' ), true ) ) { $target = $o; break; }
}
if ( null === $target ) { WP_CLI::warning( 'No unpaid order found — skipping.' ); WP_CLI::success( 'Skipped.' ); return; }

$k = (string) $target['order_key'];
$existing = EEM_Order_Adjustments_Repo::get_custom_items( $k );
EEM_Order_Adjustments_Repo::insert_custom_item( $k, 'Invoice smoke item', 40 );
$o = $repo->get_order( $k );
$outstanding = $repo->get_order_outstanding( $k, $o );
$html = (string) $bih->invoke( $admin, $o, 'smoke-token' );
preg_match( '/Amount Due.*?\$([0-9,]+\.[0-9]{2})/s', $html, $mm );
$shown = isset( $mm[1] ) ? (float) str_replace( ',', '', $mm[1] ) : -1.0;

$check( 'invoice Amount Due == canonical outstanding (' . number_format( $shown, 2 ) . ' == ' . number_format( $outstanding, 2 ) . ')', abs( $shown - $outstanding ) < 0.02 );
$check( 'invoice Amount Due includes the $40 custom item (> base total)', $shown > (float) $target['total'] + 39.99 - 0.02 );

// cleanup — restore the order's custom items to what they were
EEM_Order_Adjustments_Repo::replace_custom_items( $k, array_map( static function ( $c ) {
	return array( 'description' => (string) ( $c['description'] ?? '' ), 'amount' => (float) ( $c['amount'] ?? 0 ) );
}, $existing ) );

WP_CLI::log( "\n=== Invoice email amount smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Invoice email amount smoke passed.' );
