<?php
/**
 * [en_events] filter alias + "ongoing" timeframe smoke.
 *
 * Verifies parse_timeframe_filter accepts the new ongoing/current keys and the
 * filter_events_by_timeframe split (upcoming / ongoing / past / all) against a
 * fixed "today", via Reflection on the private statics.
 *
 * Run: wp eval-file tests/smoke/events-filter-alias-smoke.php
 */

if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }

$pass = 0; $fail = 0;
$ok = static function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$ref = new ReflectionClass( 'EEM_Events' );
$parse = $ref->getMethod( 'parse_timeframe_filter' );
$parse->setAccessible( true );
$filter = $ref->getMethod( 'filter_events_by_timeframe' );
$filter->setAccessible( true );
$events = new EEM_Events();

// --- parse normalization ----------------------------------------------------
$ok( 'upcoming → current_upcoming', 'current_upcoming' === $parse->invoke( $events, 'upcoming' ) );
$ok( 'current → ongoing', 'ongoing' === $parse->invoke( $events, 'current' ) );
$ok( 'ongoing → ongoing', 'ongoing' === $parse->invoke( $events, 'ongoing' ) );
$ok( 'past → past', 'past' === $parse->invoke( $events, 'past' ) );
$ok( 'all → all', 'all' === $parse->invoke( $events, 'all' ) );
$ok( 'include_past → all', 'all' === $parse->invoke( $events, 'include_past' ) );
$ok( 'garbage → current_upcoming', 'current_upcoming' === $parse->invoke( $events, 'nonsense' ) );

// --- timeframe split against a fixed today ----------------------------------
// today is derived from current_time inside the method, so build rows relative to it.
$today = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
$yday  = wp_date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
$tmrw  = wp_date( 'Y-m-d', strtotime( $today . ' +1 day' ) );
$next  = wp_date( 'Y-m-d', strtotime( $today . ' +10 day' ) );
$prev  = wp_date( 'Y-m-d', strtotime( $today . ' -10 day' ) );

$rows = array(
	'past'    => array( 'start_date' => $prev, 'end_date' => $yday ),    // fully past
	'ongoing' => array( 'start_date' => $yday, 'end_date' => $tmrw ),    // straddles today
	'future'  => array( 'start_date' => $next, 'end_date' => $next ),    // upcoming
);

$keys = static function ( $list ) {
	return array_map( static function ( $r ) {
		// Identify by start_date for assertion clarity.
		return $r['start_date'];
	}, $list );
};

$past = $filter->invoke( $events, $rows, 'past' );
$ok( 'past timeframe → only fully-past event', 1 === count( $past ) && $past[0]['end_date'] === $yday );

$ongoing = $filter->invoke( $events, $rows, 'ongoing' );
$ok( 'ongoing timeframe → only the straddling event', 1 === count( $ongoing ) && $ongoing[0]['end_date'] === $tmrw );

$upcoming = $filter->invoke( $events, $rows, 'current_upcoming' );
// current_upcoming = sort_date (end) >= today → ongoing + future, NOT past.
$ok( 'current_upcoming → ongoing + future (2)', 2 === count( $upcoming ) );

$all = $filter->invoke( $events, $rows, 'all' );
$ok( 'all → every row (3)', 3 === count( $all ) );

echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { exit( 1 ); }
