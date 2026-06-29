<?php
/**
 * C14 smoke — Collect Payment page (non-gated half).
 *
 * Asserts the read-only workspace renders real order data (customer, items,
 * amount-due rail with C13.C adjustments + recomputed Total Due), the empty
 * state, the gated payment tabs (no charge/email code), 5-digit order-number
 * formatting, and the JS/CSS wiring. Content-density per the render-chunk
 * discipline (asserts non-empty values + the recomputed total).
 *
 * Run: wp eval-file tests/smoke/c14-collect-payment-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$check( 'collect-payment class loaded', class_exists( 'EEM_Collect_Payment_Page' ) );

// --- format_order_number_display (5-digit standard) ------------------------
$fmt = new ReflectionMethod( 'EEM_Collect_Payment_Page', 'format_order_number_display' );
$fmt->setAccessible( true );
$check( 'numeric order number -> #%05d', '#00021' === $fmt->invoke( null, '21' ) );
// #55: EEM_Formatter now zero-pads the numeric suffix of a prefixed order number
// (X-7 → X-00007) rather than '#'-prefixing it verbatim.
$check( 'prefixed order number zero-pads its numeric suffix', 'X-00007' === $fmt->invoke( null, 'X-7' ) );

// --- workspace render with real adjustments --------------------------------
$order_key = wp_generate_password( 32, false );
EEM_Order_Adjustments_Repo::replace_custom_items( $order_key, array( array( 'description' => 'Late arrival fee', 'amount' => 50.00 ) ) );
EEM_Order_Adjustments_Repo::set_discount( $order_key, 'dollar', 10.00, 'First-time customer', 140.00 );

$order = array(
	'order_key'      => $order_key,
	'order_number'   => '21',
	'customer_name'  => 'Devon Lacroix',
	'email'          => 'devon@example.com',
	'payment_status' => 'pending',
	'stall_subtotal' => 40.00,
	'rv_subtotal'    => 0.0,
	'fees'           => 2.40,
	'total'          => 42.40,
	'event_label'    => '2026 Southeast Region Super Sort',
);

// This smoke covers the STRIPE charge form, so force Stripe as the active
// gateway for the render (the box may have Authorize.net selected). Restore after.
$pay_orig = get_option( 'equine_event_manager_payment_settings' );
update_option( 'equine_event_manager_payment_settings', array_merge( (array) $pay_orig, array( 'selected_gateway' => 'stripe' ) ) );

$ws = new ReflectionMethod( 'EEM_Collect_Payment_Page', 'render_workspace' );
$ws->setAccessible( true );
ob_start(); $ws->invoke( null, $order, $order_key, '#00021' ); $html = (string) ob_get_clean();

update_option( 'equine_event_manager_payment_settings', $pay_orig ); // restore active gateway

$check( 'outstanding banner rendered', str_contains( $html, 'eem-cp-banner' ) && str_contains( $html, 'Payment Outstanding' ) );
$check( 'customer name shown', str_contains( $html, 'Devon Lacroix' ) );
$check( 'customer email shown', str_contains( $html, 'devon@example.com' ) );
$check( 'reservation/event label shown', str_contains( $html, '2026 Southeast Region Super Sort' ) );
$check( 'order items card present', str_contains( $html, 'Order Items' ) );
$check( 'stall line item shown', str_contains( $html, 'Stall Reservation' ) );
$check( 'custom line item shown in items', str_contains( $html, 'Late arrival fee' ) );
$check( 'convenience fee line shown', str_contains( $html, 'Convenience Fee' ) );
$check( 'amount-due rail present', str_contains( $html, 'Amount Due' ) );
$check( 'discount line with reason shown', str_contains( $html, 'First-time customer' ) && str_contains( $html, '−$10.00' ) );
// Total Due = 42.40 + 50 − 10 = 82.40
$check( 'Total Due recomputed to $82.40', str_contains( $html, 'Total Due' ) && str_contains( $html, '$82.40' ) );

// --- payment tabs — Stripe-tokenized, never raw card / secret --------------
$check( 'Send Link + Charge Card tabs present', str_contains( $html, 'collect-payment-tab' ) && str_contains( $html, 'Charge Card' ) );
$check( 'Send Link tab wires the real send-invoice-email action', str_contains( $html, 'action=equine_event_manager_send_invoice_email' ) && str_contains( $html, 'Send Payment Link' ) );
$check( 'charge form is client-tokenized (honest note)', str_contains( $html, 'never reach the server' ) );
$check( 'no raw card input fields shipped', ! str_contains( $html, '1234 1234' ) && ! str_contains( $html, 'name="card' ) );

// --- paid order shows Payment Collected, not Outstanding -------------------
// #55: the "paid in full" banner keys on the computed outstanding balance
// (total_due − amount_paid <= 0), NOT payment_status alone — so record a
// collected amount that covers the total.
$paid_order = array_merge( $order, array( 'payment_status' => 'paid', 'amount_paid' => 100000.0, 'amount_due' => 0.0 ) );
ob_start(); $ws->invoke( null, $paid_order, $order_key, '#00021' ); $paid_html = (string) ob_get_clean();
$check( 'paid order shows Payment Collected banner', str_contains( $paid_html, 'Payment Collected' ) && str_contains( $paid_html, 'eem-cp-banner--paid' ) );
$check( 'paid order does NOT show Payment Outstanding', ! str_contains( $paid_html, 'Payment Outstanding' ) );
$check( 'paid order shows Total Paid label', str_contains( $paid_html, 'Total Paid' ) );
$check( 'paid order shows paid-in-full notice (no charge form)', str_contains( $paid_html, 'paid in full' ) && ! str_contains( $paid_html, 'id="eem-cp-card-element"' ) );

// --- empty state -----------------------------------------------------------
$es = new ReflectionMethod( 'EEM_Collect_Payment_Page', 'render_empty_state' );
$es->setAccessible( true );
ob_start(); $es->invoke( null, admin_url( 'admin.php?page=equine-event-manager-orders' ) ); $empty = (string) ob_get_clean();
$check( 'empty state title', str_contains( $empty, 'No Order Specified' ) );
$check( 'empty state back-to-orders button', str_contains( $empty, 'Back to Orders' ) );

// --- source-level gate guard: no real-money code in the page ---------------
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-eem-collect-payment-page.php' );
$check( 'page ships NO wp_remote_post', ! str_contains( $src, 'wp_remote_post' ) );
$check( 'page ships NO wp_mail', ! str_contains( $src, 'wp_mail' ) );
// The page may reference the client paymentIntent + create-intent action and
// read the secret-key setting to gate readiness, but must NEVER output a secret
// key into the rendered page, nor call the Stripe API directly (that lives in
// the gated server handlers on EEM_Shortcodes).
$check( 'rendered page leaks NO secret key value', ! str_contains( $html, 'sk_' ) );
$check( 'page never calls the Stripe API directly', ! str_contains( $src, 'api.stripe.com' ) );

// --- JS + CSS wiring -------------------------------------------------------
$js  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' );
$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/admin.css' );
$check( 'JS handles collect-payment-tab', str_contains( $js, 'collect-payment-tab' ) && str_contains( $js, 'data-eem-collect-panel' ) );
$check( 'CSS defines .eem-cp-banner', str_contains( $css, '.eem-cp-banner' ) );
$check( 'CSS defines .eem-cp-item-row', str_contains( $css, '.eem-cp-item-row' ) );
$check( 'CSS defines .eem-cp-empty', str_contains( $css, '.eem-cp-empty' ) );

EEM_Order_Adjustments_Repo::delete_for_order( $order_key );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
