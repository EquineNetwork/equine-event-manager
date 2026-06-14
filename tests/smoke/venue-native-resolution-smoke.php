<?php
/**
 * Native en_venue → canonical EEM_Venue resolution + Saved Layouts meta box smoke.
 *
 * Covers:
 *  - resolve_for_native_venue / find_for_native_venue (en_venue post ↔ canonical venue)
 *  - the native source-venue keying fix: two native events at the SAME en_venue
 *    resolve to ONE canonical Venue (so they share saved layouts)
 *  - the venue editor "Saved Stall / RV Layouts" meta box render (list + actions)
 *
 * Run: wp eval-file tests/smoke/venue-native-resolution-smoke.php
 */

if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }

$pass = 0; $fail = 0;
$ok = static function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }
if ( ! post_type_exists( 'en_venue' ) && class_exists( 'EEM_Events' ) ) {
	( new EEM_Events() )->register_content_types();
}
EEM_Venue::create_tables();

$suffix = substr( md5( (string) wp_rand() ), 0, 6 );

// --- seed: one venue, two native events at it, one reservation per event -----
$venue = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'NV Venue ' . $suffix ) );
$ev_a  = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'NV Event A ' . $suffix ) );
$ev_b  = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'NV Event B ' . $suffix ) );
update_post_meta( $ev_a, '_equine_event_manager_event_venue_id', $venue );
update_post_meta( $ev_b, '_equine_event_manager_event_venue_id', $venue );

$res_a = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'NV Res A ' . $suffix ) );
$res_b = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'NV Res B ' . $suffix ) );
foreach ( array( $res_a => $ev_a, $res_b => $ev_b ) as $rid => $eid ) {
	update_post_meta( $rid, '_en_event_source', 'native' );
	update_post_meta( $rid, '_en_event_id', (string) $eid );
}
$ok( 'seed venue + 2 events + 2 reservations', $venue > 0 && $ev_a > 0 && $ev_b > 0 && $res_a > 0 && $res_b > 0 );

// --- native venue resolution -------------------------------------------------
// NOTE: the save_post_en_venue sync hook unifies eagerly on insert (2.7.279), so
// the seeded venue is already linked here — find returns its canonical id, not 0.
$ok( 'find_for_native_venue returns the eagerly-linked venue', EEM_Venue::find_for_native_venue( (int) $venue ) > 0 );
$vid = EEM_Venue::resolve_for_native_venue( (int) $venue );
$ok( 'resolve_for_native_venue returns the canonical venue', $vid > 0 );
$ok( 'find_for_native_venue now returns the same venue', EEM_Venue::find_for_native_venue( (int) $venue ) === $vid );
$ok( 'resolve is idempotent (same id on second call)', EEM_Venue::resolve_for_native_venue( (int) $venue ) === $vid );

// --- the keying fix: both reservations resolve to the SAME canonical venue ---
$rv_a = EEM_Venue::resolve_for_reservation( (int) $res_a );
$rv_b = EEM_Venue::resolve_for_reservation( (int) $res_b );
$ok( 'reservation A resolves to the venue-keyed canonical venue', $rv_a === $vid );
$ok( 'two events at the same venue share ONE canonical venue', $rv_a === $rv_b );

// --- save a layout, assert it surfaces on the venue meta box -----------------
$lid = EEM_Venue::save_layout( $vid, (int) $res_a, 'NV Layout ' . $suffix );
$ok( 'save_layout returns a row id', $lid > 0 );
$ok( 'get_layouts returns the saved layout', 1 === count( array_filter( EEM_Venue::get_layouts( $vid ), static function ( $l ) use ( $lid ) { return (int) $l['id'] === $lid; } ) ) );

$events = new EEM_Events();
ob_start();
$events->render_venue_layouts_meta_box( get_post( $venue ) );
$html = ob_get_clean();
$ok( 'meta box renders the saved layout name', false !== strpos( $html, 'NV Layout ' . $suffix ) );
$ok( 'meta box renders rename action', false !== strpos( $html, 'data-eem-action="venue-layout-rename"' ) );
$ok( 'meta box renders delete action', false !== strpos( $html, 'data-eem-action="venue-layout-delete"' ) );
$ok( 'meta box carries the layout nonce', false !== strpos( $html, 'data-nonce="' ) );
$ok( 'meta box rows carry the layout id', false !== strpos( $html, 'data-layout-id="' . $lid . '"' ) );

// empty-state render for a venue with no canonical record yet
$bare = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'NV Bare ' . $suffix ) );
ob_start();
$events->render_venue_layouts_meta_box( get_post( $bare ) );
$bare_html = ob_get_clean();
$ok( 'empty venue shows the no-layouts hint', false !== strpos( $bare_html, 'No saved layouts yet' ) );

// --- cleanup -----------------------------------------------------------------
EEM_Venue::delete_layout( $lid );
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::venues_table() . ' WHERE id = %d', $vid ) ); // phpcs:ignore WordPress.DB
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id = %d', $vid ) ); // phpcs:ignore WordPress.DB
foreach ( array( $venue, $bare, $ev_a, $ev_b, $res_a, $res_b ) as $pid ) { wp_delete_post( (int) $pid, true ); }

echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { exit( 1 ); }
