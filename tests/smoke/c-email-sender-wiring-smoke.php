<?php
/**
 * Launch fix smoke — Settings "Sender" UI actually drives transactional emails.
 *
 * Bug: the Settings → Communications Sender section writes to eem_email_sender
 * (EEM_Settings_Repo), but the transactional emails read the legacy
 * receipt_settings option — so From/Reply-To set in the UI was silently ignored.
 * Fix: get_receipt_settings() (both EEM_Admin + EEM_Shortcodes copies) now layers
 * the UI sender over the legacy values. This asserts that round-trip + that the
 * built emails carry the UI From header.
 *
 * Run: wp eval-file tests/smoke/c-email-sender-wiring-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// Snapshot + set the UI sender option to known values.
$prev = get_option( 'eem_email_sender', array() );
EEM_Settings_Repo::update_email_sender( array(
	'send_customer_emails' => 1,
	'from_name'            => 'Equine Network Events',
	'from_email'           => 'events@equinenetwork.com',
	'reply_to'             => 'support@equinenetwork.com',
) );

// --- EEM_Admin::get_receipt_settings picks up the UI sender -----------------
$admin = new EEM_Admin();
$ar    = new ReflectionMethod( 'EEM_Admin', 'get_receipt_settings' );
$ar->setAccessible( true );
$rs = $ar->invoke( $admin );
$check( '[admin] from_name = UI value', 'Equine Network Events' === $rs['from_name'] );
$check( '[admin] from_email = UI value', 'events@equinenetwork.com' === $rs['from_email'] );
$check( '[admin] reply_to_email mapped from UI reply_to', 'support@equinenetwork.com' === $rs['reply_to_email'] );

// --- EEM_Shortcodes::get_receipt_settings picks it up too -------------------
$sc  = new EEM_Shortcodes();
$sr  = new ReflectionMethod( 'EEM_Shortcodes', 'get_receipt_settings' );
$sr->setAccessible( true );
$rs2 = $sr->invoke( $sc );
$check( '[shortcodes] from_name = UI value', 'Equine Network Events' === $rs2['from_name'] );
$check( '[shortcodes] from_email = UI value', 'events@equinenetwork.com' === $rs2['from_email'] );
$check( '[shortcodes] reply_to_email mapped from UI reply_to', 'support@equinenetwork.com' === $rs2['reply_to_email'] );

// --- the built invoice email carries the UI From header --------------------
$captured = array();
$f = static function ( $short, $atts ) use ( &$captured ) { $captured = $atts; return true; };
add_filter( 'pre_wp_mail', $f, 10, 2 );
$order = array(
	'order_key' => 'sender-smoke', 'order_number' => '7', 'email' => 'cust@example.com',
	'customer_name' => 'Cust', 'event_name' => 'E', 'reservation_title' => 'R',
	'status_slug' => 'unpaid', 'components' => array(),
);
$send = $admin->send_invoice_email_for_order( $order );
remove_filter( 'pre_wp_mail', $f, 10 );
$from_header_ok = false;
foreach ( (array) ( $captured['headers'] ?? array() ) as $h ) {
	if ( false !== strpos( $h, 'From:' ) && false !== strpos( $h, 'events@equinenetwork.com' ) ) { $from_header_ok = true; }
}
$check( 'invoice email From header uses the UI sender', $from_header_ok );

// restore
update_option( 'eem_email_sender', $prev, false );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
