<?php
/**
 * Notifications page smoke (v2 Notifications, Slice 2).
 *
 * Structure + wiring: menu route, AJAX handlers, page render (event picker +
 * audience builder + compose + history container), and the page JS handle.
 * Recipient math is covered by notifications-recipients-smoke.php (Slice 1).
 *
 * Run: wp eval-file tests/smoke/notifications-page-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// --- registration ----------------------------------------------------------
$check( 'EEM_Notifications_Page exists', class_exists( 'EEM_Notifications_Page' ) );
$check( 'menu route hooked on admin_menu', false !== has_action( 'admin_menu', array( 'EEM_Notifications_Page', 'register_route' ) ) );
$check( 'count AJAX hooked', false !== has_action( 'wp_ajax_eem_notifications_count', array( 'EEM_Notifications_Page', 'ajax_count' ) ) );
$check( 'event-meta AJAX hooked', false !== has_action( 'wp_ajax_eem_notifications_event_meta', array( 'EEM_Notifications_Page', 'ajax_event_meta' ) ) );

// --- menu lands under the Event Manager parent -----------------------------
do_action( 'admin_menu' );
global $submenu;
// The Event Manager TOP-LEVEL slug is the Orders page (EEM_Admin::MENU_SLUG),
// NOT 'equine-event-manager' — every EM submenu attaches there.
$parent = EEM_Admin::MENU_SLUG;
$slugs  = array();
if ( isset( $submenu[ $parent ] ) ) {
	foreach ( $submenu[ $parent ] as $item ) { $slugs[] = $item[2]; }
}
$check( 'Notifications submenu attaches to the EM top-level (Orders) parent', in_array( EEM_Notifications_Page::MENU_SLUG, $slugs, true ) );

// --- render ----------------------------------------------------------------
$_GET = array( 'page' => EEM_Notifications_Page::MENU_SLUG );
ob_start(); EEM_Notifications_Page::render(); $html = (string) ob_get_clean();
$_GET = array();

$check( 'renders the page wrapper + nonce', false !== strpos( $html, 'data-eem-notifications' ) && false !== strpos( $html, 'data-nonce=' ) );
$check( 'renders the event picker (Choices)', false !== strpos( $html, 'data-eem-notif-event' ) && false !== strpos( $html, 'data-eem-choices' ) );
$check( 'renders Include + Exclude + Payment selects', false !== strpos( $html, 'data-eem-notif-include' ) && false !== strpos( $html, 'data-eem-notif-exclude' ) && false !== strpos( $html, 'data-eem-notif-payment' ) );
$check( 'Include carries the canonical segments', false !== strpos( $html, '>All customers<' ) && false !== strpos( $html, '>Stall customers<' ) && false !== strpos( $html, '>RV customers<' ) );
$check( 'renders the live recipient count', false !== strpos( $html, 'data-eem-notif-count-badge' ) );
$check( 'renders subject + body compose', false !== strpos( $html, 'data-eem-notif-subject' ) && false !== strpos( $html, 'data-eem-notif-body' ) );
$check( 'renders the Send button + history container', false !== strpos( $html, 'data-eem-action="notifications-send"' ) && false !== strpos( $html, 'data-eem-notif-history' ) );

// --- page JS exists + carries the wiring -----------------------------------
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/notifications.js' );
$check( 'notifications.js posts the count + event-meta actions', false !== strpos( $js, 'eem_notifications_count' ) && false !== strpos( $js, 'eem_notifications_event_meta' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
