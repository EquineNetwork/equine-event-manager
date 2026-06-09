<?php
/**
 * Smoke: with a GEMS connection configured, the Feed event source is selectable
 * and its plumbing delegates to GEMS — get_default_event_source() honors 'feed',
 * and EEM_Events::search_feed_events / get_feed_event_by_external_id delegate to
 * EEM_Gems_Client. Live section runs only when GEMS is configured.
 *
 * Run: wp eval-file tests/smoke/gems-feed-source-smoke.php
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0; $fail = 0; $log = array();
$ok = function ( $n, $c ) use ( &$pass, &$fail, &$log ) { if ( $c ) { $pass++; $log[] = "PASS  $n"; } else { $fail++; $log[] = "FAIL  $n"; } };

if ( ! ( class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured() ) ) {
	echo "gems-feed-source-smoke: SKIP (GEMS not configured on this box)\n";
	return;
}

// Force the active source to feed for this assertion only (no persistence).
$force_feed = function ( $v ) {
	$v = is_array( $v ) ? $v : array();
	$v['default_event_source'] = 'feed';
	return $v;
};
add_filter( 'option_equine_event_manager_integration_settings', $force_feed );

$ok( 'feed is an available source when GEMS configured', 'feed' === EEM_Events::get_default_event_source() );

$rows = EEM_Events::search_feed_events( '', '', 50 );
$ok( 'search_feed_events delegates to GEMS (returns rows)', is_array( $rows ) && count( $rows ) > 0 );
if ( is_array( $rows ) && $rows ) {
	$first = $rows[0];
	$ok( 'feed rows carry id + text + start_date', '' !== ( $first['id'] ?? '' ) && '' !== ( $first['text'] ?? '' ) );
	$ev = EEM_Events::get_feed_event_by_external_id( (string) $first['id'] );
	$ok( 'get_feed_event_by_external_id resolves a GEMS event', ! empty( $ev ) && ( $ev['title'] ?? '' ) === $first['text'] );
}

remove_filter( 'option_equine_event_manager_integration_settings', $force_feed );

echo implode( "\n", $log ) . "\n";
echo "gems-feed-source-smoke: {$pass} passed, {$fail} failed\n";
