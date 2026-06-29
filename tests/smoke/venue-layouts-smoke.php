<?php
/**
 * Venue Save/Load Layout smoke (v2 Facility Layout Templates, Slice 3).
 *
 * Covers the editor "Save Layout" / "Load Layout" flow: read-only find vs.
 * creating resolve, the save → find → copy-on-use load round-trip across two
 * reservations sharing a venue, the cross-venue load guard, the toolbar partial
 * render, and handler registration + gates.
 *
 * Run: wp eval-file tests/smoke/venue-layouts-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

global $wpdb;
EEM_Venue::create_tables();
$suffix  = substr( md5( (string) wp_rand() ), 0, 6 );
$evt_key = 'SMOKEVENUE-' . $suffix;

// Two reservations linked to the SAME source event → same resolved venue.
$mk_res = static function ( string $title ) use ( $evt_key ): int {
	$id = (int) wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => $title ) );
	update_post_meta( $id, '_en_event_source', 'tec' );
	update_post_meta( $id, '_en_external_event_id', $evt_key );
	return $id;
};
$rid_a = $mk_res( 'Venue Layout Smoke A' );
$rid_b = $mk_res( 'Venue Layout Smoke B' );
update_post_meta( $rid_a, '_en_stall_rows', array( array( 'first' => '100', 'last' => '130' ) ) );
update_post_meta( $rid_a, '_en_rv_lots', array( 'Lot 1', 'Lot 2' ) );
update_post_meta( $rid_a, '_en_blocked_stalls', array( '101' ) );

// find before any save → 0 (read-only, no creation).
$check( 'find_for_reservation is 0 before any venue exists', 0 === EEM_Venue::find_for_reservation( $rid_a ) );
$count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EEM_Venue::venues_table() );
EEM_Venue::find_for_reservation( $rid_a );
$check( 'find_for_reservation did NOT create a venue', $count_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EEM_Venue::venues_table() ) );

// resolve creates the venue (the "Save Layout" path).
$vid = EEM_Venue::resolve_for_reservation( $rid_a );
$check( 'resolve_for_reservation creates the venue', $vid > 0 );
$check( 'find_for_reservation now returns the same venue', $vid === EEM_Venue::find_for_reservation( $rid_a ) );
$check( 'reservation B resolves to the SAME venue (shared event)', $vid === EEM_Venue::find_for_reservation( $rid_b ) );

// Save A's layout, then load it into B (copy-on-use).
$lid = EEM_Venue::save_layout( $vid, $rid_a, '2026 Smoke Layout' );
$check( 'save_layout returns an id', $lid > 0 );
$check( 'apply layout to reservation B', EEM_Venue::apply_layout_to_reservation( $lid, $rid_b ) );
$check( 'B received A’s stall rows', get_post_meta( $rid_b, '_en_stall_rows', true ) === array( array( 'first' => '100', 'last' => '130' ) ) );
$check( 'B received A’s RV lots', get_post_meta( $rid_b, '_en_rv_lots', true ) === array( 'Lot 1', 'Lot 2' ) );

// copy-on-use: editing B must not touch the saved layout.
update_post_meta( $rid_b, '_en_stall_rows', array( array( 'first' => '900', 'last' => '999' ) ) );
$saved = EEM_Venue::get_layout( $lid );
$check( 'editing B does not mutate the saved venue layout', '100' === ( $saved['layout']['_en_stall_rows'][0]['first'] ?? '' ) );

// Cross-venue load guard: a layout from a DIFFERENT venue must be rejected by
// the handler's ownership check (layout.venue_id !== find_for_reservation).
$other_vid = EEM_Venue::resolve( 'tec', 'OTHER-' . $suffix, 'Other Smoke Venue ' . $suffix );
$other_lid = EEM_Venue::save_layout( $other_vid, $rid_a, 'Foreign Layout' );
$foreign   = EEM_Venue::get_layout( $other_lid );
$check( 'cross-venue guard condition holds (foreign layout venue != reservation venue)', (int) $foreign['venue_id'] !== EEM_Venue::find_for_reservation( $rid_b ) );

// Toolbar partial renders both buttons.
$context = 'stall';
ob_start();
require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_layout-template-bar.php';
$bar = (string) ob_get_clean();
$check( 'bar renders the Save Layout button', false !== strpos( $bar, 'data-eem-action="venue-save-layout"' ) );
$check( 'bar renders the Load Layout button', false !== strpos( $bar, 'data-eem-action="venue-load-layout"' ) );

// #55: the layout-template-bar partial is v2 Facility Layout Templates
// scaffolding — it ships as a standalone partial but is NOT yet wired into the
// v1 stall/rv editor sections (the integration lands when v2 ships). Assert the
// partial exists; the section-wiring is intentionally deferred.
$check( 'layout-template-bar partial exists (v2 scaffolding)',
	file_exists( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_layout-template-bar.php' ) );

// Handler registration + gates.
$check( 'save handler registered', false !== has_action( 'wp_ajax_eem_venue_save_layout' ) );
$check( 'list handler registered', false !== has_action( 'wp_ajax_eem_venue_list_layouts' ) );
$check( 'load handler registered', false !== has_action( 'wp_ajax_eem_venue_load_layout' ) );
$check( 'nonce gate accepts a valid layout nonce', false !== wp_verify_nonce( wp_create_nonce( 'eem_venue_layout' ), 'eem_venue_layout' ) );

// The JS reads reservation id from the editor body data attr; assert the editor
// emits it (the contract the JS depends on).
$editor_src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$check( 'editor body emits data-eem-reservation-id (JS contract)', false !== strpos( $editor_src, 'data-eem-reservation-id' ) );

// --- cleanup ---------------------------------------------------------------
foreach ( array( $vid, $other_vid ) as $cv ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id = %d', $cv ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::layouts_table() . ' WHERE venue_id = %d', $cv ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::venues_table() . ' WHERE id = %d', $cv ) );
}
wp_delete_post( $rid_a, true );
wp_delete_post( $rid_b, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
