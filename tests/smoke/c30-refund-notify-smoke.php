<?php
/**
 * CLEANUP #30 smoke — refund-notify email wiring.
 *
 * Tests the refund-processed email builder + send path (wp_mail short-circuited
 * via pre_wp_mail so nothing is actually delivered), the modal "Notify customer"
 * checkbox, and the handler/JS wiring that fires it.
 *
 * Run: wp eval-file tests/smoke/c30-refund-notify-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// #55: EEM_Mailer sends via the SendGrid API directly when a key is configured
// (this box has one), which bypasses the pre_wp_mail short-circuit the send-path
// test relies on. Strip the key for this run so the mailer falls to wp_mail,
// where pre_wp_mail intercepts cleanly (no real delivery either way).
add_filter( 'option_equine_event_manager_integration_settings', static function ( $v ) {
	if ( is_array( $v ) ) { unset( $v['sendgrid_api_key'] ); }
	return $v;
}, 99 );

$admin = new EEM_Admin();
$order = array(
	'order_key'        => 'smoke-refund-' . wp_generate_password( 8, false ),
	'order_number'     => '42',
	'email'            => 'refund.tester@example.com',
	'customer_name'    => 'Refund Tester',
	'event_name'       => 'Test Event',
	'reservation_title' => '2026 Test Reservation',
);

// --- build_refund_email_html content ---------------------------------------
$bref = new ReflectionMethod( 'EEM_Admin', 'build_refund_email_html' );
$bref->setAccessible( true );
$html = (string) $bref->invoke( $admin, $order, 25.50, 'Customer cancelled' );
$check( 'email has Refund Processed header', str_contains( $html, 'Refund Processed' ) );
$check( 'email shows refund amount $25.50', str_contains( $html, '$25.50' ) );
$check( 'email shows the reason', str_contains( $html, 'Customer cancelled' ) );
$check( 'email greets the customer by name', str_contains( $html, 'Refund Tester' ) );
$check( 'email shows 5-digit order number #00042', str_contains( $html, '#00042' ) );
// Reason row omitted when empty.
$html_no_reason = (string) $bref->invoke( $admin, $order, 10.00, '' );
$check( 'reason row omitted when no reason given', ! str_contains( $html_no_reason, 'Reason' ) );

// --- send path (wp_mail short-circuited, args captured) --------------------
$captured = array();
$filter = static function ( $short, $atts ) use ( &$captured ) { $captured = $atts; return true; };
add_filter( 'pre_wp_mail', $filter, 10, 2 );
$sent = $admin->send_refund_email_for_order( $order, 25.50, 'Customer cancelled' );
remove_filter( 'pre_wp_mail', $filter, 10 );
$check( 'send_refund_email_for_order returns true', true === $sent );
$check( 'email addressed to the customer', isset( $captured['to'] ) && 'refund.tester@example.com' === $captured['to'] );
$check( 'subject mentions Refund processed', isset( $captured['subject'] ) && str_contains( $captured['subject'], 'Refund processed' ) );
$check( 'HTML content-type header set', isset( $captured['headers'] ) && in_array( 'Content-Type: text/html; charset=UTF-8', (array) $captured['headers'], true ) );

// Missing email → WP_Error, no send.
$no_email = $admin->send_refund_email_for_order( array( 'order_number' => '1', 'event_name' => 'X', 'customer_name' => 'Y' ), 5.0, '' );
$check( 'missing customer email returns WP_Error', is_wp_error( $no_email ) );

// --- modal checkbox + handler/JS wiring (source) ---------------------------
$od_ref = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_refund_modal' );
$od_ref->setAccessible( true );
ob_start();
$od_ref->invoke( new EEM_Order_Detail_Page(), array( 'order_key' => 'k', 'customer_name' => 'C', 'total' => 50.0 ) );
$modal = (string) ob_get_clean();
$check( 'refund modal has the Notify-customer checkbox', str_contains( $modal, 'name="notify"' ) && str_contains( $modal, 'Email the customer a refund confirmation' ) );
$check( 'notify checkbox defaults unchecked (opt-in)', ! preg_match( '/name="notify"[^>]*checked/', $modal ) );

$admin_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-equine-event-manager-admin.php' );
$check( 'handler reads notify + sends when checked', str_contains( $admin_src, "! empty( \$_POST['notify'] )" ) && str_contains( $admin_src, 'send_refund_email_for_order( $order_for_email' ) );
$check( 'handler returns notification_sent', str_contains( $admin_src, "'notification_sent'    => \$notification_sent" ) );
$js_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' );
$check( 'JS toast notes when customer was notified', str_contains( $js_src, 'Customer notified by email' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
