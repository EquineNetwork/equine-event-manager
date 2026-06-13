<?php
/**
 * Branded Producers + Events list pages smoke (Native Events Admin C).
 *
 * Renders both list pages against seeded en_producer / en_event posts and
 * asserts the branded chrome + content density: stats strip, status tabs,
 * search toolbar, sortable table columns, per-row action dropdown, and the
 * event-specific date / venue / producer columns + linked-reservation count.
 *
 * Run: wp eval-file tests/smoke/native-events-lists-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// Determinism guard — register the native CPTs regardless of the flag state.
if ( ! post_type_exists( 'en_event' ) && class_exists( 'EEM_Events' ) ) {
	( new EEM_Events() )->register_content_types();
}

$check( 'producers page class loaded', class_exists( 'EEM_Producers_Page' ) );
$check( 'events page class loaded', class_exists( 'EEM_Events_List_Page' ) );
$check( 'producers slug', EEM_Producers_Page::MENU_SLUG === 'equine-event-manager-producers' );
$check( 'events slug', EEM_Events_List_Page::MENU_SLUG === 'equine-event-manager-events' );

$suffix = substr( md5( (string) wp_rand() ), 0, 6 );

// --- seed producer + venue + event + reservation ---------------------------
$producer = wp_insert_post( array( 'post_type' => 'en_producer', 'post_status' => 'publish', 'post_title' => 'Smoke Producer ' . $suffix ) );
update_post_meta( $producer, '_equine_event_manager_producer_email', 'smoke@example.com' );
update_post_meta( $producer, '_equine_event_manager_producer_phone', '5551234567' );
update_post_meta( $producer, '_equine_event_manager_producer_website', 'https://smoke.example.com' );

$venue = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'Smoke Venue ' . $suffix ) );

$event = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'Smoke Event ' . $suffix ) );
update_post_meta( $event, '_equine_event_manager_event_venue_id', $venue );
update_post_meta( $event, '_equine_event_manager_event_producer_id', $producer );
update_post_meta( $event, '_equine_event_manager_event_start_date', '2099-06-14' );
update_post_meta( $event, '_equine_event_manager_event_end_date', '2099-06-18' );

$reservation = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Smoke Res ' . $suffix ) );
update_post_meta( $reservation, '_en_event_id', $event );

$check( 'seed producer/venue/event/reservation created', $producer > 0 && $venue > 0 && $event > 0 && $reservation > 0 );

// --- producers list render -------------------------------------------------
ob_start();
$_GET = array( 'page' => EEM_Producers_Page::MENU_SLUG );
EEM_Producers_Page::render();
$plist = ob_get_clean();
$check( 'producers: stats strip', false !== strpos( $plist, 'eem-venues-stats' ) );
$check( 'producers: stat card num', false !== strpos( $plist, 'eem-stat-card-num' ) );
$check( 'producers: With Website stat label', false !== strpos( $plist, 'With Website' ) );
$check( 'producers: status tabs', false !== strpos( $plist, 'eem-status-tabs' ) );
$check( 'producers: search input', false !== strpos( $plist, 'eem-search-input' ) );
$check( 'producers: shows the seeded producer', false !== strpos( $plist, 'Smoke Producer ' . $suffix ) );
$check( 'producers: shows the contact', false !== strpos( $plist, 'smoke@example.com' ) );
$check( 'producers: shows the event count', false !== strpos( $plist, 'eem-tpl-count' ) );
$check( 'producers: row dropdown', false !== strpos( $plist, 'data-eem-action="dropdown-toggle"' ) );
$check( 'producers: Producer Name column', false !== strpos( $plist, 'Producer Name' ) );

// --- events list render ----------------------------------------------------
ob_start();
$_GET = array( 'page' => EEM_Events_List_Page::MENU_SLUG );
EEM_Events_List_Page::render();
$elist = ob_get_clean();
$check( 'events: stats strip', false !== strpos( $elist, 'eem-venues-stats' ) );
$check( 'events: Current + Upcoming wide card', false !== strpos( $elist, 'eem-stat-card--wide' ) && false !== strpos( $elist, 'Current + Upcoming' ) );
$check( 'events: upcoming card lists the seeded event', false !== strpos( $elist, 'Smoke Event ' . $suffix ) );
$check( 'events: Total Events stat', false !== strpos( $elist, 'Total Events' ) );
$check( 'events: Linked Reservations stat', false !== strpos( $elist, 'Linked Reservations' ) );
$check( 'events: status tabs', false !== strpos( $elist, 'eem-status-tabs' ) );
$check( 'events: producer filter select', false !== strpos( $elist, 'name="producer"' ) && false !== strpos( $elist, 'All producers' ) );
$check( 'events: producer filter lists the seeded producer', false !== strpos( $elist, 'Smoke Producer ' . $suffix ) );
$check( 'events: Event Title column', false !== strpos( $elist, 'Event Title' ) );
$check( 'events: Date column', false !== strpos( $elist, '>Date ' ) );
$check( 'events: Venue column', false !== strpos( $elist, '>Venue<' ) );
$check( 'events: Producer column', false !== strpos( $elist, '>Producer<' ) );
$check( 'events: shows the event row', false !== strpos( $elist, 'Smoke Event ' . $suffix ) );
$check( 'events: shows the date range', false !== strpos( $elist, 'Jun 14' ) && false !== strpos( $elist, 'Jun 18, 2099' ) );
$check( 'events: shows the linked venue', false !== strpos( $elist, 'Smoke Venue ' . $suffix ) );
$check( 'events: row dropdown', false !== strpos( $elist, 'data-eem-action="dropdown-toggle"' ) );
$check( 'events: View Reservations action', false !== strpos( $elist, 'View Reservations' ) );

// --- data-layer counts -----------------------------------------------------
$ref = new ReflectionMethod( 'EEM_Events_List_Page', 'reservation_counts_by_event' );
$ref->setAccessible( true );
$rc = $ref->invoke( null );
$check( 'events: reservation count maps the seeded event', isset( $rc[ $event ] ) && (int) $rc[ $event ] >= 1 );

// --- cleanup ---------------------------------------------------------------
wp_delete_post( (int) $reservation, true );
wp_delete_post( (int) $event, true );
wp_delete_post( (int) $venue, true );
wp_delete_post( (int) $producer, true );
$_GET = array();

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
