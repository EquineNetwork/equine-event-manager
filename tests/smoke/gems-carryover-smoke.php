<?php
/**
 * Smoke: a reservation linked to a GEMS event inherits the event's dates, venue,
 * and title (carry-over). For the 'feed' source the linked event is authoritative
 * for dates — it must win over the reservation's own availability window.
 *
 * Creates a throwaway published reservation, links it to a live GEMS event,
 * asserts the resolver carries the event fields over, then deletes it. Runs only
 * when a GEMS connection is configured.
 *
 * Run: wp eval-file tests/smoke/gems-carryover-smoke.php
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0; $fail = 0; $log = array();
$ok = function ( $n, $c ) use ( &$pass, &$fail, &$log ) { if ( $c ) { $pass++; $log[] = "PASS  $n"; } else { $fail++; $log[] = "FAIL  $n"; } };

if ( ! ( class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured() ) ) {
	echo "gems-carryover-smoke: SKIP (GEMS not configured on this box)\n";
	return;
}

// Pick a real GEMS event to link against.
$events = EEM_Gems_Client::get_events();
if ( is_wp_error( $events ) || empty( $events ) ) {
	echo "gems-carryover-smoke: SKIP (GEMS returned no events)\n";
	return;
}
$gems = $events[0];

// Throwaway reservation with a DELIBERATELY-WRONG stored date window, to prove
// the GEMS dates win over the reservation's own availability meta.
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'GEMS carryover smoke fixture',
) );
$ok( 'created throwaway reservation', $rid && ! is_wp_error( $rid ) );
if ( ! $rid || is_wp_error( $rid ) ) {
	echo implode( "\n", $log ) . "\ngems-carryover-smoke: {$pass} passed, " . ( $fail + 1 ) . " failed\n";
	return;
}

update_post_meta( $rid, '_en_event_source', 'feed' );
update_post_meta( $rid, '_en_use_global_event_source', 0 );
update_post_meta( $rid, '_en_external_event_id', (string) $gems['external_event_id'] );
update_post_meta( $rid, '_en_external_event_name', (string) $gems['title'] );
// Stale window that must be overridden by the GEMS dates.
update_post_meta( $rid, '_en_available_start_date', '2000-01-01' );
update_post_meta( $rid, '_en_available_end_date', '2000-01-02' );
// #55: event source + external-event link resolve from the relational config
// table (mig-016 decouple), not post-meta — seed it there so the normalized
// reservation-event lookup finds the GEMS link.
if ( class_exists( 'EEM_Reservation_Config' ) ) {
	EEM_Reservation_Config::for( (int) $rid )
		->set_many( array(
			'event_source'            => 'feed',
			'use_global_event_source' => 0,
			'external_event_id'       => (string) $gems['external_event_id'],
			'external_event_name'     => (string) $gems['title'],
			'available_start_date'    => '2000-01-01',
			'available_end_date'      => '2000-01-02',
		) )
		->save();
	EEM_Reservation_Config::flush_cache( (int) $rid );
}

$f = EEM_Reservation_Source_Resolver::resolve_event_fields( (int) $rid );

$ok( 'title carries over from GEMS', $f['title'] === $gems['title'] );
$ok( 'start_date is the GEMS event date (not the stale 2000-01-01)', $f['start_date'] === $gems['start_date'] && '2000-01-01' !== $f['start_date'] );
$ok( 'end_date is the GEMS event date', $f['end_date'] === $gems['end_date'] );
$ok( 'venue carries over from GEMS', $f['venue'] === $gems['venue_name'] );

wp_delete_post( (int) $rid, true );
$ok( 'throwaway reservation cleaned up', null === get_post( (int) $rid ) );

echo implode( "\n", $log ) . "\n";
echo "gems-carryover-smoke: {$pass} passed, {$fail} failed\n";
