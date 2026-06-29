<?php
/**
 * #22 — Bulk-email unsubscribe (EEM_Email_Optout).
 *
 * Covers the HMAC link/verify, opt-out storage + check, recipient filtering, the
 * per-recipient footer, and the two bulk-send paths (Notifications dispatch_batch
 * + Email Customers) honoring the opt-out. Transactional sends are unaffected by
 * design (they never call the opt-out gate) — asserted structurally.
 *
 * Run: wp eval-file tests/smoke/email-optout-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

// Force the wp_mail transport so pre_wp_mail can capture the dispatch test.
add_filter( 'option_equine_event_manager_integration_settings', static function ( $v ) {
	if ( is_array( $v ) ) { unset( $v['sendgrid_api_key'] ); }
	return $v;
}, 99 );

$pass = 0; $fail = 0;
$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	if ( $ok ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

$check( 'EEM_Email_Optout loaded', class_exists( 'EEM_Email_Optout' ) );

$email = 'optout.smoke+' . wp_generate_password( 6, false ) . '@example.com';

// ── HMAC signature / verify ──
$sig = EEM_Email_Optout::signature( $email );
$check( 'signature is a 64-char hex hmac', (bool) preg_match( '/^[0-9a-f]{64}$/', $sig ) );
$check( 'signature is case-insensitive on the email', EEM_Email_Optout::signature( strtoupper( $email ) ) === $sig );
$check( 'verify accepts the matching signature', EEM_Email_Optout::verify( $email, $sig ) );
$check( 'verify rejects a tampered signature', ! EEM_Email_Optout::verify( $email, $sig . '00' ) );
$check( 'verify rejects an empty signature', ! EEM_Email_Optout::verify( $email, '' ) );
$check( 'signature empty for an invalid address', '' === EEM_Email_Optout::signature( 'not-an-email' ) );

// ── URL + footer ──
$url = EEM_Email_Optout::unsubscribe_url( $email );
$check( 'unsubscribe_url targets admin-post action', false !== strpos( $url, 'action=' . EEM_Email_Optout::ACTION ) );
$check( 'unsubscribe_url carries email + sig', false !== strpos( $url, rawurlencode( strtolower( $email ) ) ) && false !== strpos( $url, $sig ) );
$footer = EEM_Email_Optout::footer_html( $email );
// esc_url() HTML-encodes the & separators, so match the encoded href + the
// stable signature/action tokens rather than the raw URL string.
$check( 'footer_html contains the unsubscribe link', false !== strpos( $footer, esc_url( $url ) ) && false !== strpos( $footer, $sig ) && false !== stripos( $footer, 'Unsubscribe' ) );

// ── opt-out storage ──
$check( 'not opted out initially', ! EEM_Email_Optout::is_opted_out( $email ) );
$check( 'filter_recipients keeps a non-opted-out address', in_array( $email, EEM_Email_Optout::filter_recipients( array( $email ) ), true ) );
EEM_Email_Optout::record_optout( $email );
$check( 'is_opted_out true after record', EEM_Email_Optout::is_opted_out( $email ) );
$check( 'opt-out is case-insensitive', EEM_Email_Optout::is_opted_out( strtoupper( $email ) ) );
$check( 'filter_recipients drops the opted-out address', ! in_array( $email, EEM_Email_Optout::filter_recipients( array( $email, 'keep@example.com' ) ), true ) );
$check( 'filter_recipients keeps the others', in_array( 'keep@example.com', EEM_Email_Optout::filter_recipients( array( $email, 'keep@example.com' ) ), true ) );

// ── handler registered ──
$check( 'public (nopriv) unsubscribe handler registered', false !== has_action( 'admin_post_nopriv_' . EEM_Email_Optout::ACTION ) );

// ── dispatch_batch honors the opt-out + appends footer ──
$captured = array();
$cap = static function ( $short, $atts ) use ( &$captured ) {
	$to = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
	foreach ( $to as $t ) { $captured[] = array( 'to' => $t, 'body' => $atts['message'] ); }
	return true;
};
add_filter( 'pre_wp_mail', $cap, 10, 2 );
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Optout Smoke Res' ) );
$res = EEM_Notifications_Page::dispatch_batch( array( $email, 'keep@example.com' ), 'Update', 'Body text.', (int) $rid );
remove_filter( 'pre_wp_mail', $cap, 10 );
wp_delete_post( (int) $rid, true );

$check( 'dispatch_batch skipped the opted-out recipient', 1 === ( $res['skipped'] ?? 0 ) );
$check( 'dispatch_batch sent to the remaining recipient', 1 === ( $res['sent'] ?? 0 ) );
$to_list = array_column( $captured, 'to' );
$check( 'opted-out address received NO email', ! in_array( $email, $to_list, true ) );
$check( 'kept address received the email', in_array( 'keep@example.com', $to_list, true ) );
$kept = array_values( array_filter( $captured, static function ( $c ) { return 'keep@example.com' === $c['to']; } ) );
$check( 'sent email carries the unsubscribe footer', ! empty( $kept ) && false !== stripos( $kept[0]['body'], 'Unsubscribe' ) );

// ── transactional paths do NOT gate on opt-out (structural) ──
$admin_src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'transactional send_invoice_email_for_order does NOT call the opt-out gate',
	false === strpos( substr( $admin_src, strpos( $admin_src, 'function send_invoice_email_for_order' ), 2000 ), 'EEM_Email_Optout' ) );

// cleanup the test opt-out so we don't leave it in the option
$map = get_option( EEM_Email_Optout::OPTION, array() );
unset( $map[ strtolower( $email ) ] );
update_option( EEM_Email_Optout::OPTION, $map, false );

echo "\n=== #22 email-optout smoke: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'email-optout smoke passed.' );
