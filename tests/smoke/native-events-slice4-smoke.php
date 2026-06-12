<?php
/**
 * Native Events Slice 4 smoke — map view ([en_events view="map"]).
 *
 * Asserts graceful degradation (no key / no coords) and that a key + a
 * coordinate-bearing venue produces a map container with a correct pin payload.
 *
 * Run: wp eval-file tests/smoke/native-events-slice4-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

$events = new EEM_Events();
$render = new ReflectionMethod( 'EEM_Events', 'render_event_map_markup' );
$render->setAccessible( true );

$integ        = get_option( 'equine_event_manager_integration_settings', array() );
if ( ! is_array( $integ ) ) { $integ = array(); }
$integ_before = $integ;

// Seed a venue WITH coordinates + an event linked to it.
$vid = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'Slice4 Map Venue' ) );
update_post_meta( $vid, '_equine_event_manager_venue_city', 'Rapid City' );
update_post_meta( $vid, '_equine_event_manager_venue_state', 'SD' );
update_post_meta( $vid, '_en_venue_lat', 44.0805 );
update_post_meta( $vid, '_en_venue_lng', -103.231 );

$eid = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'Slice4 Map Event' ) );
update_post_meta( $eid, '_equine_event_manager_event_start_date', '2026-10-01T09:00' );
update_post_meta( $eid, '_equine_event_manager_event_end_date', '2026-10-02T17:00' );
update_post_meta( $eid, '_equine_event_manager_event_venue_id', $vid );

// venue details carry coordinates.
$data = EEM_Events::get_normalized_event_data( $eid );
$check( 'venue details expose lat', ( $data['venue']['lat'] ?? '' ) == 44.0805 );
$check( 'venue details expose lng', ( $data['venue']['lng'] ?? '' ) == -103.231 );

// No key → friendly notice.
$integ['google_maps_api_key'] = '';
update_option( 'equine_event_manager_integration_settings', $integ, false );
$out = $render->invoke( $events, array( $data ) );
$check( 'no Maps key → renders the key-needed notice', false !== strpos( $out, 'eem-event-map-notice' ) && false !== strpos( $out, 'Google Maps API key' ) );

// Key present + a coordinate-bearing event → map container with the pin.
$integ['google_maps_api_key'] = 'AIzaTESTKEY';
update_option( 'equine_event_manager_integration_settings', $integ, false );
$out = $render->invoke( $events, array( $data ) );
$check( 'with key → renders the map container', false !== strpos( $out, 'class="eem-event-map"' ) && false !== strpos( $out, 'data-eem-event-map' ) );

// Decode the pin payload from the data-events attribute.
$pins = array();
if ( preg_match( '/data-events="([^"]*)"/', $out, $m ) ) {
	$pins = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}
$check( 'pin payload decodes to one pin', is_array( $pins ) && 1 === count( $pins ) );
$check( 'pin carries the event title', ! empty( $pins[0]['title'] ) && false !== strpos( $pins[0]['title'], 'Slice4 Map Event' ) );
$check( 'pin carries numeric lat/lng', isset( $pins[0]['lat'], $pins[0]['lng'] ) && 44.0805 === $pins[0]['lat'] && -103.231 === $pins[0]['lng'] );
$check( 'pin carries a url', ! empty( $pins[0]['url'] ) );

// Key present but event has NO coordinates → "no coordinates" notice.
$vid2 = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'Slice4 No-Coords Venue' ) );
$eid2 = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'Slice4 No-Coords Event' ) );
update_post_meta( $eid2, '_equine_event_manager_event_start_date', '2026-10-05T09:00' );
update_post_meta( $eid2, '_equine_event_manager_event_venue_id', $vid2 );
$data2 = EEM_Events::get_normalized_event_data( $eid2 );
$out2  = $render->invoke( $events, array( $data2 ) );
$check( 'key but no coords → renders the no-coordinates notice', false !== strpos( $out2, 'eem-event-map-notice' ) && false !== strpos( $out2, 'map coordinates yet' ) );

// Shortcode wiring: view=map routes to the map renderer.
$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
$check( "view='map' branch calls render_event_map_markup", false !== strpos( $src, "if ( 'map' === \$view )" ) && false !== strpos( $src, 'render_event_map_markup( $events )' ) );

// --- cleanup --------------------------------------------------------------
update_option( 'equine_event_manager_integration_settings', $integ_before, false );
wp_delete_post( (int) $eid, true );
wp_delete_post( (int) $vid, true );
wp_delete_post( (int) $eid2, true );
wp_delete_post( (int) $vid2, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
