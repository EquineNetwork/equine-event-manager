<?php
/**
 * Native Events Slice 1 smoke — un-gate + Maps key + shortcode aliases + view
 * normalization (month alias, images toggle).
 *
 * Run: wp eval-file tests/smoke/native-events-slice1-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// Shortcode aliases registered.
$check( '[en_events] alias registered', shortcode_exists( 'en_events' ) );
$check( '[en_event] alias registered', shortcode_exists( 'en_event' ) );
$check( 'legacy [equine_event_manager_events] still registered', shortcode_exists( 'equine_event_manager_events' ) );

// Google Maps API key getter.
$check( 'get_google_maps_api_key() exists', method_exists( 'EEM_Events', 'get_google_maps_api_key' ) );
$opt = get_option( 'equine_event_manager_integration_settings', array() );
if ( ! is_array( $opt ) ) { $opt = array(); }
$saved_before = $opt;
$opt['google_maps_api_key'] = 'AIzaTESTKEY123';
update_option( 'equine_event_manager_integration_settings', $opt, false );
$check( 'getter returns the saved Maps key', 'AIzaTESTKEY123' === EEM_Events::get_google_maps_api_key() );
// restore
update_option( 'equine_event_manager_integration_settings', $saved_before, false );

// Settings panel no longer gates native as Coming Soon.
$settings_src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-settings-page.php' );
$check( 'native source no longer carries coming_soon flag', false === strpos( $settings_src, "'coming_soon' => true" ) );
$check( 'coming-soon list no longer contains native', (bool) preg_match( '/\$coming_soon_sources\s*=\s*array\(\s*\)/', $settings_src ) );
$check( 'Settings renders a Google Maps API Key field', false !== strpos( $settings_src, 'payload[google_maps_api_key]' ) );

// View normalization: shortcode handler accepts month/map/images without fatal.
$events = new EEM_Events();
foreach ( array(
	'[en_events view="list"]',
	'[en_events view="month"]',
	'[en_events view="map"]',
	'[en_events view="list" images="no"]',
) as $sc ) {
	$out = do_shortcode( $sc );
	$check( "shortcode renders without fatal: {$sc}", is_string( $out ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
