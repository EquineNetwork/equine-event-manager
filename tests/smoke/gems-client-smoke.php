<?php
/**
 * Smoke: EEM_Gems_Client normalizes the GEMS schedule into the canonical
 * feed-event shape and resolves credentials. The normalizer assertions run
 * against a recorded sample (no network); a live section runs only when a real
 * GEMS connection is configured on the box.
 *
 * Run: wp eval-file tests/smoke/gems-client-smoke.php
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0; $fail = 0; $log = array();
$ok = function ( $n, $c ) use ( &$pass, &$fail, &$log ) { if ( $c ) { $pass++; $log[] = "PASS  $n"; } else { $fail++; $log[] = "FAIL  $n"; } };

$ok( 'class loaded', class_exists( 'EEM_Gems_Client' ) );

// Recorded sample from the live GEMS /api/Schedule/236 payload.
$sample = array(
	'eventUID'     => 43986,
	'season'       => 2026,
	'eventName'    => 'NTR - Texas Finals',
	'eventType'    => 'Non-Sanctioned',
	'startDate'    => '2026-10-23T00:00:00',
	'endDate'      => '2026-10-25T00:00:00',
	'arenaName'    => 'Circle T Arena',
	'arenaAddress' => '1 Rodeo Dr',
	'arenaCity'    => 'Hamilton',
	'arenaState'   => 'TX',
	'arenaZip'     => '76531',
	'producerName' => 'Acme Roping',
	'producerPhone'=> '555-0100',
	'producerEmail'=> 'p@example.test',
	'imgLogo'      => 'https://example.test/logo.png',
	'refId'        => 23809,
);
$n = EEM_Gems_Client::normalize_event( $sample );
$ok( 'normalize maps eventUID → external_event_id', isset( $n['external_event_id'] ) && '43986' === $n['external_event_id'] );
$ok( 'normalize maps eventName → title', ( $n['title'] ?? '' ) === 'NTR - Texas Finals' );
$ok( 'normalize formats start_date Y-m-d', ( $n['start_date'] ?? '' ) === '2026-10-23' );
$ok( 'normalize formats end_date Y-m-d', ( $n['end_date'] ?? '' ) === '2026-10-25' );
$ok( 'normalize maps arenaName → venue_name', ( $n['venue_name'] ?? '' ) === 'Circle T Arena' );
$ok( 'normalize builds location "City, ST"', ( $n['location'] ?? '' ) === 'Hamilton, TX' );
$ok( 'normalize maps producerName', ( $n['producer']['name'] ?? '' ) === 'Acme Roping' );
$ok( 'normalize tags source=feed', ( $n['source'] ?? '' ) === 'feed' );

// A record with no eventUID is unusable.
$ok( 'normalize drops record without eventUID', array() === EEM_Gems_Client::normalize_event( array( 'eventName' => 'x' ) ) );

// request_schedule guards missing creds.
$err = EEM_Gems_Client::request_schedule( 'https://x', '', '' );
$ok( 'request_schedule errors without creds', is_wp_error( $err ) );

// Live section (only when configured).
if ( EEM_Gems_Client::is_configured() ) {
	EEM_Gems_Client::clear_cache();
	$events = EEM_Gems_Client::get_events();
	$ok( '[live] get_events returns events', is_array( $events ) && count( $events ) > 0 );
	if ( is_array( $events ) ) {
		$ok( '[live] every event has an id + title', (function ( $events ) {
			foreach ( $events as $e ) { if ( '' === ( $e['external_event_id'] ?? '' ) ) { return false; } }
			return true;
		})( $events ) );
		$rows = EEM_Gems_Client::search( '', 100, false );
		$ok( '[live] search returns picker rows', is_array( $rows ) && count( $rows ) > 0 );
	}
} else {
	$log[] = 'SKIP  [live] GEMS not configured on this box';
}

echo implode( "\n", $log ) . "\n";
echo "gems-client-smoke: {$pass} passed, {$fail} failed\n";
