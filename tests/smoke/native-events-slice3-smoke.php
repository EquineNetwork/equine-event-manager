<?php
/**
 * Native Events Slice 3 smoke — venue geocoding (address assembly, Google
 * geocode parse, auto-geocode-on-save guard, manual override, editor fields).
 *
 * The Google HTTP call is mocked via the `pre_http_request` filter so the smoke
 * runs offline and deterministically.
 *
 * Run: wp eval-file tests/smoke/native-events-slice3-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// --- mock Google Geocoding ------------------------------------------------
$GLOBALS['eem_geocode_calls'] = 0;
$mock = static function ( $pre, $args, $url ) {
	if ( false === strpos( (string) $url, 'maps.googleapis.com/maps/api/geocode' ) ) {
		return $pre;
	}
	$GLOBALS['eem_geocode_calls']++;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'status'  => 'OK',
			'results' => array( array( 'geometry' => array( 'location' => array( 'lat' => 44.0805, 'lng' => -103.231 ) ) ) ),
		) ),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );

// --- geocode_address ------------------------------------------------------
$check( 'geocode_address returns null without a key', null === EEM_Events::geocode_address( '101 Main St', '' ) );
$check( 'geocode_address returns null on empty address', null === EEM_Events::geocode_address( '', 'KEY' ) );
$coords = EEM_Events::geocode_address( '101 Main St, Rapid City, SD', 'AIzaKEY' );
$check( 'geocode_address parses lat/lng from Google response', is_array( $coords ) && 44.0805 === $coords['lat'] && -103.231 === $coords['lng'] );

// --- seed venue + address assembly ----------------------------------------
// Unique title per run: resolve_for_native_venue matches the canonical venue by
// name, and the relational store row outlives the en_venue post on cleanup — a
// fixed title would reuse a prior run's already-geocoded venue (0 HTTP calls).
$venue_title = 'Slice3 Geocode Venue ' . substr( md5( (string) wp_rand() ), 0, 8 );
$vid = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => $venue_title ) );
update_post_meta( $vid, '_equine_event_manager_venue_address_1', '101 Main St' );
update_post_meta( $vid, '_equine_event_manager_venue_city', 'Rapid City' );
update_post_meta( $vid, '_equine_event_manager_venue_state', 'SD' );
update_post_meta( $vid, '_equine_event_manager_venue_postal_code', '57701' );
// Geocoded coords persist to the relational venue store (save_detail), which needs
// a resolved canonical venue. The save_post_en_venue resolve hook only fires when
// Native Events is enabled (gated off here), so resolve explicitly. get_detail
// backfills the address from post-meta, so the address string still assembles.
if ( class_exists( 'EEM_Venue' ) ) {
	EEM_Venue::resolve_for_native_venue( (int) $vid, $venue_title );
}
$check( 'get_venue_address_string assembles the address', '101 Main St, Rapid City, SD, 57701' === EEM_Events::get_venue_address_string( $vid ) );

// --- maybe_geocode_venue with a configured key ----------------------------
$integ = get_option( 'equine_event_manager_integration_settings', array() );
if ( ! is_array( $integ ) ) { $integ = array(); }
$integ_before = $integ;
$integ['google_maps_api_key'] = 'AIzaTESTKEY';
update_option( 'equine_event_manager_integration_settings', $integ, false );

$GLOBALS['eem_geocode_calls'] = 0;
EEM_Events::maybe_geocode_venue( $vid );
// Coords + marker persist to the relational venue store — read them back there.
$geo = EEM_Venue::get_detail( (int) $vid, true );
$check( 'auto-geocode saved latitude', (float) ( $geo['lat'] ?? 0 ) === 44.0805 );
$check( 'auto-geocode saved longitude', (float) ( $geo['lng'] ?? 0 ) === -103.231 );
$check( 'auto-geocode cached the address marker', EEM_Events::get_venue_address_string( $vid ) === (string) ( $geo['geocoded_address'] ?? '' ) );
$check( 'auto-geocode made exactly one HTTP call', 1 === $GLOBALS['eem_geocode_calls'] );

// Re-run with unchanged address → cache guard prevents a second call.
$GLOBALS['eem_geocode_calls'] = 0;
EEM_Events::maybe_geocode_venue( $vid );
$check( 'unchanged address does NOT re-geocode (cache guard)', 0 === $GLOBALS['eem_geocode_calls'] );

// Address change → re-geocodes. A real venue edit goes through save_detail (which
// also busts the per-request detail cache), so mirror that rather than touching
// post-meta alone — otherwise the cached detail from the geocode above is stale
// within this single request (a non-issue across separate requests in production).
update_post_meta( $vid, '_equine_event_manager_venue_address_1', '202 Elm Ave' );
EEM_Venue::save_detail( (int) $vid, array( 'address_1' => '202 Elm Ave' ), true );
$GLOBALS['eem_geocode_calls'] = 0;
EEM_Events::maybe_geocode_venue( $vid );
$check( 'changed address re-geocodes', 1 === $GLOBALS['eem_geocode_calls'] );

// No key → no-op (clear key, change address, expect no call).
$integ['google_maps_api_key'] = '';
update_option( 'equine_event_manager_integration_settings', $integ, false );
update_post_meta( $vid, '_equine_event_manager_venue_address_1', '303 Oak Blvd' );
$GLOBALS['eem_geocode_calls'] = 0;
EEM_Events::maybe_geocode_venue( $vid );
$check( 'no Maps key → no geocode call', 0 === $GLOBALS['eem_geocode_calls'] );

// --- editor render emits lat/lng inputs -----------------------------------
$events = new EEM_Events();
ob_start();
$events->render_venue_details_meta_box( get_post( $vid ) );
$editor = (string) ob_get_clean();
$check( 'venue editor renders the Latitude input', false !== strpos( $editor, 'name="en_venue_lat"' ) );
$check( 'venue editor renders the Longitude input', false !== strpos( $editor, 'name="en_venue_lng"' ) );

// Save handler wires manual-override + auto-geocode.
$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
$check( 'save_venue_meta reads manual en_venue_lat', false !== strpos( $src, "\$_POST['en_venue_lat']" ) );
$check( 'save_venue_meta calls maybe_geocode_venue', false !== strpos( $src, 'self::maybe_geocode_venue( $post_id )' ) );

// --- cleanup --------------------------------------------------------------
remove_filter( 'pre_http_request', $mock, 10 );
update_option( 'equine_event_manager_integration_settings', $integ_before, false );
wp_delete_post( (int) $vid, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
