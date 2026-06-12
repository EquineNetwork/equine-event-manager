<?php
/**
 * Notifications send-pipeline smoke (v2 Notifications, Slice 3).
 *
 * Exercises the testable write path — dispatch_batch (per-message send, captured
 * via pre_wp_mail so nothing leaves the box), the body wrapper, the audience
 * description, the activity-log NOTIFICATION_SENT history read, and the history
 * table render.
 *
 * Run: wp eval-file tests/smoke/notifications-send-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// --- send hooks registered -------------------------------------------------
$check( 'send_start AJAX hooked', false !== has_action( 'wp_ajax_eem_notifications_send_start', array( 'EEM_Notifications_Page', 'ajax_send_start' ) ) );
$check( 'send_step AJAX hooked', false !== has_action( 'wp_ajax_eem_notifications_send_step', array( 'EEM_Notifications_Page', 'ajax_send_step' ) ) );

// --- dispatch_batch: capture mail via pre_wp_mail (no real send) ------------
$captured = array();
$cap = function ( $pre, $atts ) use ( &$captured ) {
	$to = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
	foreach ( $to as $t ) { $captured[] = array( 'to' => $t, 'subject' => $atts['subject'], 'body' => $atts['message'] ); }
	return true; // short-circuit wp_mail as success
};
add_filter( 'pre_wp_mail', $cap, 10, 2 );

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Notif Send Smoke Event' ) );
$res = EEM_Notifications_Page::dispatch_batch( array( 'x@example.com', 'y@example.com' ), 'Gate times update', "Gates open at 7am.\nSee you there.", $rid );
remove_filter( 'pre_wp_mail', $cap, 10 );

$check( 'dispatch_batch reports 2 sent / 0 failed', 2 === $res['sent'] && 0 === $res['failed'] );
$check( 'both messages captured', 2 === count( $captured ) );
$check( 'message carries the subject', isset( $captured[0]['subject'] ) && 'Gate times update' === $captured[0]['subject'] );
$check( 'body wrapped as HTML with the subject heading + body text', false !== strpos( (string) $captured[0]['body'], 'Gate times update' ) && false !== strpos( (string) $captured[0]['body'], 'Gates open at 7am.' ) );

// --- wrap_body + audience_description (private — Reflection) ----------------
$wrap = new ReflectionMethod( 'EEM_Notifications_Page', 'wrap_body' );
$wrap->setAccessible( true );
$html = (string) $wrap->invoke( null, 'Subj', "line1\nline2" );
$check( 'wrap_body nl2br-s the body', false !== strpos( $html, '<br' ) && false !== strpos( $html, 'line1' ) );

$adesc = new ReflectionMethod( 'EEM_Notifications_Page', 'audience_description' );
$adesc->setAccessible( true );
$desc = (string) $adesc->invoke( null, $rid, 'rv', 'stall', 'unpaid' );
$check( 'audience_description reads "RV ... (not Stall ...) · unpaid"', false !== stripos( $desc, 'RV' ) && false !== stripos( $desc, 'not' ) && false !== stripos( $desc, 'unpaid' ) );

// --- activity-log history read + render ------------------------------------
EEM_Activity_Log::write(
	EEM_Activity_Log::NOTIFICATION_SENT,
	array( 'channel' => 'notifications_page', 'audience' => 'All customers', 'subject' => 'Smoke Subject Z', 'recipient_count' => 5, 'sent' => 5, 'failed' => 0 ),
	array( 'reservation_id' => $rid, 'actor_type' => 'admin', 'actor_id' => get_current_user_id() )
);
$recent = EEM_Activity_Log::get_recent_by_type( EEM_Activity_Log::NOTIFICATION_SENT, 10 );
$found = false;
foreach ( $recent as $e ) {
	$p = is_array( $e['payload'] ?? null ) ? $e['payload'] : array();
	if ( ( $p['subject'] ?? '' ) === 'Smoke Subject Z' ) { $found = true; break; }
}
$check( 'get_recent_by_type returns the NOTIFICATION_SENT entry', $found );

$_GET = array( 'page' => EEM_Notifications_Page::MENU_SLUG );
ob_start(); EEM_Notifications_Page::render(); $page = (string) ob_get_clean();
$_GET = array();
$check( 'history table renders the sent notification', false !== strpos( $page, 'Smoke Subject Z' ) && false !== strpos( $page, 'All customers' ) );

// --- cleanup ---------------------------------------------------------------
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Activity_Log::table_name() . ' WHERE reservation_id = %d', $rid ) );
wp_delete_post( (int) $rid, true );
$check( 'cleaned up', null === get_post( $rid ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
