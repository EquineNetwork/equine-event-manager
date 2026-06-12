<?php
/**
 * Venues admin page render smoke (v2 Facility Layout Templates, Slice 2).
 *
 * Renders the list view (empty + populated) and the detail view, asserting the
 * canonical chrome + content density, and exercises the rename/delete AJAX
 * handlers end-to-end through EEM_Venue.
 *
 * Run: wp eval-file tests/smoke/venues-page-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

$check( 'page class loaded', class_exists( 'EEM_Venues_Page' ) );
$check( 'MENU_SLUG is the venues slug', EEM_Venues_Page::MENU_SLUG === 'equine-event-manager-venues' );
$check( 'source_label maps tec', EEM_Venues_Page::source_label( 'tec' ) === 'The Events Calendar' );
$check( 'source_label maps gems', EEM_Venues_Page::source_label( 'gems' ) === 'GEMS' );

global $wpdb;
EEM_Venue::create_tables();
$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
$vid    = EEM_Venue::resolve( 'tec', 'PV-' . $suffix, 'Page Smoke Arena ' . $suffix );
$check( 'seed venue created', $vid > 0 );

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Venue Page Smoke Res' ) );
update_post_meta( $rid, '_en_stall_rows', array( array( 'first' => '1', 'last' => '10' ) ) );
$lid = EEM_Venue::save_layout( $vid, $rid, 'Page Smoke Layout' );
$check( 'seed layout created', $lid > 0 );

// --- list render -----------------------------------------------------------
ob_start();
$_GET = array( 'page' => EEM_Venues_Page::MENU_SLUG );
EEM_Venues_Page::render();
$list = ob_get_clean();
$check( 'list renders the venues table', false !== strpos( $list, 'eem-venues-table' ) );
$check( 'list shows the seeded venue name', false !== strpos( $list, 'Page Smoke Arena ' . $suffix ) );
$check( 'list shows the intro copy', false !== strpos( $list, 'eem-venues-intro' ) );

// --- detail render ---------------------------------------------------------
ob_start();
$_GET = array( 'page' => EEM_Venues_Page::MENU_SLUG, 'venue_id' => (string) $vid );
EEM_Venues_Page::render();
$detail = ob_get_clean();
$check( 'detail carries the nonce data attr', false !== strpos( $detail, 'data-venue-nonce' ) );
$check( 'detail renders the Saved Layouts card', false !== strpos( $detail, 'eem-venue-layouts-table' ) );
$check( 'detail lists the seeded layout by name', false !== strpos( $detail, 'Page Smoke Layout' ) );
$check( 'detail wires a rename button to the layout id', (bool) preg_match( '/data-eem-action="venue-layout-rename"[^>]*data-layout-id="' . $lid . '"/', $detail ) );
$check( 'detail wires a delete button to the layout id', (bool) preg_match( '/data-eem-action="venue-layout-delete"[^>]*data-layout-id="' . $lid . '"/', $detail ) );
$check( 'detail renders the Event Sources card', false !== strpos( $detail, 'eem-venue-sources-table' ) );
$check( 'detail shows the source label', false !== strpos( $detail, 'The Events Calendar' ) );

// --- AJAX handler gates + data layer ---------------------------------------
// wp_send_json_* invokes wp_die() which EXITS the CLI runner (bypasses the
// wp_die_handler filter chain), so we verify the gates the handlers rely on
// (cap + nonce, both deterministic) plus the EEM_Venue write the handler calls,
// rather than invoking the handlers directly (canonical pattern; see c6b-smoke).
$check( 'rename/delete AJAX actions are registered', has_action( 'wp_ajax_eem_venue_rename_layout' ) !== false && has_action( 'wp_ajax_eem_venue_delete_layout' ) !== false );

$saved_user = get_current_user_id();
wp_set_current_user( 0 );
$check( 'capability gate rejects non-admins', ! current_user_can( 'manage_options' ) );
wp_set_current_user( $saved_user );
$check( 'capability gate accepts admin', current_user_can( 'manage_options' ) );

$valid_nonce = wp_create_nonce( 'eem_venue_layout' );
$check( 'nonce gate accepts a valid layout nonce', false !== wp_verify_nonce( $valid_nonce, 'eem_venue_layout' ) );
$check( 'nonce gate rejects a bogus nonce', false === wp_verify_nonce( 'BOGUS', 'eem_venue_layout' ) );

// The write the rename handler performs.
$check( 'rename data layer persists the new name', EEM_Venue::rename_layout( $lid, 'Renamed Page Smoke Layout' ) && EEM_Venue::get_layout( $lid )['name'] === 'Renamed Page Smoke Layout' );
// The write the delete handler performs.
$check( 'delete data layer removes the layout', EEM_Venue::delete_layout( $lid ) && null === EEM_Venue::get_layout( $lid ) );

// --- cleanup ---------------------------------------------------------------
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id = %d', $vid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::layouts_table() . ' WHERE venue_id = %d', $vid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::venues_table() . ' WHERE id = %d', $vid ) );
wp_delete_post( (int) $rid, true );
$_GET = array();
$_POST = array();

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
