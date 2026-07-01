<?php
/**
 * Payment-received email smoke (v1 #8).
 *
 * When an outstanding order transitions to PAID (invoice link paid, Mark Cash/
 * Check, Collect Payment charge), the customer gets a branded "Payment Received"
 * email. Hooked to `eem_order_payment_status_changed` (same trigger as the
 * payment-received telemetry). Orders created already-paid at checkout don't
 * transition, so this never double-sends with the checkout confirmation.
 *
 * Run: wp eval-file tests/smoke/payment-received-email-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

// #55: force the wp_mail transport (strip the SendGrid API key) so the send-path
// test's pre_wp_mail short-circuit intercepts instead of the live SendGrid call.
add_filter( 'option_equine_event_manager_integration_settings', static function ( $v ) {
	if ( is_array( $v ) ) { unset( $v['sendgrid_api_key'] ); }
	return $v;
}, 99 );

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admin = new EEM_Admin();

// --- source presence: wiring -----------------------------------------------
$loader = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$check( 'listener hooked to eem_order_payment_status_changed', false !== strpos( $loader, "add_action( 'eem_order_payment_status_changed', array( \$this->admin, 'on_payment_received_send_email' )" ) );
$check( 'send + build + listener methods defined', method_exists( 'EEM_Admin', 'send_payment_received_email_for_order' ) && method_exists( 'EEM_Admin', 'on_payment_received_send_email' ) );

// --- build the email HTML for a synthetic order ----------------------------
$order = array(
	'email'             => 'rider@example.test',
	'customer_name'     => 'Jane Rider',
	'order_number'      => 128,
	'event_name'        => 'Spring Classic',
	'reservation_title' => 'Spring Classic',
	'total'             => 250.00,
	// The "Amount Paid" is now the NET collected (ledger-based, 2.7.723). This is a
	// no-ledger synthetic key, so the fallback reads the component amount_paid —
	// set it to the collected total so the fixture reflects a settled payment.
	'amount_paid'       => 250.00,
	'status_slug'       => 'paid',
	'order_key'         => 'pr-smoke-key',
);
$build = new ReflectionMethod( 'EEM_Admin', 'build_payment_received_email_html' );
$build->setAccessible( true );
$html = (string) $build->invoke( $admin, $order );
$check( 'email says "Payment Received"', false !== strpos( $html, 'Payment Received' ) );
$check( 'email shows the amount paid', false !== strpos( $html, '$250.00' ) );
$check( 'email shows the 5-digit order number', false !== strpos( $html, '#00128' ) );
$check( 'email shows the event + customer name', false !== strpos( $html, 'Spring Classic' ) && false !== strpos( $html, 'Jane Rider' ) );

// --- send path: missing email → WP_Error -----------------------------------
$err = $admin->send_payment_received_email_for_order( array( 'email' => '' ) );
$check( 'send without an email returns WP_Error', is_wp_error( $err ) );

// --- capture wp_mail attempts via pre_wp_mail (no real send) ----------------
$GLOBALS['eem_pr_mail'] = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) {
	$GLOBALS['eem_pr_mail'][] = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
	return true; // short-circuit: report success without actually sending.
}, 10, 2 );

// Direct send → one "Payment received" mail captured.
$admin->send_payment_received_email_for_order( $order );
$check( 'send dispatches a "Payment received" email', 1 === count( $GLOBALS['eem_pr_mail'] ) && false !== strpos( $GLOBALS['eem_pr_mail'][0], 'Payment received' ) );

// --- listener gating --------------------------------------------------------
$GLOBALS['eem_pr_mail'] = array();
// new_status != paid → no send (returns before any order lookup).
$admin->on_payment_received_send_email( array( 'order_key' => 'whatever', 'old_status' => 'unpaid', 'new_status' => 'invoice-sent' ) );
$check( 'non-paid transition sends nothing', 0 === count( $GLOBALS['eem_pr_mail'] ) );
// paid but from a non-outstanding status → no send.
$admin->on_payment_received_send_email( array( 'order_key' => 'whatever', 'old_status' => 'refunded', 'new_status' => 'paid' ) );
$check( 'paid-from-non-outstanding sends nothing', 0 === count( $GLOBALS['eem_pr_mail'] ) );
// missing order_key → no send.
$admin->on_payment_received_send_email( array( 'old_status' => 'unpaid', 'new_status' => 'paid' ) );
$check( 'missing order_key sends nothing', 0 === count( $GLOBALS['eem_pr_mail'] ) );

// --- end-to-end listener against a real unpaid order (fixture-gated) --------
$repo = new EEM_Orders_Repository();
$target = null;
foreach ( $repo->get_orders() as $o ) {
	if ( in_array( $o['status_slug'] ?? '', array( 'unpaid', 'invoice-sent' ), true ) && ! empty( $o['email'] ) ) { $target = $o; break; }
}
if ( $target ) {
	$GLOBALS['eem_pr_mail'] = array();
	$admin->on_payment_received_send_email( array( 'order_key' => $target['order_key'], 'old_status' => 'unpaid', 'new_status' => 'paid' ) );
	$check( 'real outstanding→paid transition emails the customer', 1 === count( $GLOBALS['eem_pr_mail'] ) );
} else {
	echo "  ..  - no unpaid order with email in fixtures; end-to-end listener check skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
