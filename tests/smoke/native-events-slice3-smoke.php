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
$vid = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'Slice3 Geocode Venue' ) );
update_post_meta( $vid, '_equine_event_manager_venue_address_1', '101 Main St' );
update_post_meta( $vid, '_equine_event_manager_venue_city', 'Rapid City' );
update_post_meta( $vid, '_equine_event_manager_venue_state', 'SD' );
update_post_meta( $vid, '_equine_event_manager_venue_postal_code', '57701' );
$check( 'get_venue_address_string assembles the address', '101 Main St, Rapid City, SD, 57701' === EEM_Events::get_venue_address_string( $vid ) );

// --- maybe_geocode_venue with a configured key ----------------------------
$integ = get_option( 'equine_event_manager_integration_settings', array() );
if ( ! is_array( $integ ) ) { $integ = array(); }
$integ_before = $integ;
$integ['google_maps_api_key'] = 'AIzaTESTKEY';
update_option( 'equine_event_manager_integration_settings', $integ, false );

$GLOBALS['eem_geocode_calls'] = 0;
EEM_Events::maybe_geocode_venue( $vid );
$check( 'auto-geocode saved latitude', (float) get_post_meta( $vid, '_en_venue_lat', true ) === 44.0805 );
$check( 'auto-geocode saved longitude', (float) get_post_meta( $vid, '_en_venue_lng', true ) === -103.231 );
$check( 'auto-geocode cached the address marker', EEM_Events::get_venue_address_string( $vid ) === (string) get_post_meta( $vid, '_en_venue_geocoded_address', true ) );
$check( 'auto-geocode made exactly one HTTP call', 1 === $GLOBALS['eem_geocode_calls'] );

// Re-run with unchanged address → cache guard prevents a second call.
$GLOBALS['eem_geocode_calls'] = 0;
EEM_Events::maybe_geocode_venue( $vid );
$check( 'unchanged address does NOT re-geocode (cache guard)', 0 === $GLOBALS['eem_geocode_calls'] );

// Address change → re-geocodes.
update_post_meta( $vid, '_equine_event_manager_venue_address_1', '202 Elm Ave' );
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
