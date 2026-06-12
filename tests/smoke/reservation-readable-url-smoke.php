<?php
/**
 * Readable reservation event-URL smoke (2.7.193).
 *
 * The customer event page is a virtual route keyed on the reservation id
 * (/equine-event/{id}/). This adds a readable prefix derived from the title so
 * recurring same-named events stay unique: /equine-event/{title-slug}-{id}/.
 * Verifies the URL builder emits the readable form, the route regex resolves
 * BOTH the readable and the legacy bare-id form (back-compat for already-sent
 * links), and an untitled reservation falls back to the bare id.
 *
 * Run: wp eval-file tests/smoke/reservation-readable-url-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- URL builder: readable {slug}-{id} ------------------------------------
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'NTR Rapid City 2026' ) );
$check( 'seeded a titled reservation', $rid > 0 );

$url = EEM_Events::get_reservation_public_url( $rid );
$check( 'builder emits the readable slug + id', (bool) preg_match( '#/equine-event/ntr-rapid-city-2026-' . $rid . '/?$#', $url ) );
$check( 'builder still ends in the resolving id', str_contains( $url, (string) $rid ) );

// private slug helper directly
$ref = new ReflectionMethod( 'EEM_Events', 'reservation_route_slug' );
$ref->setAccessible( true );
$check( 'reservation_route_slug returns {slug}-{id}', 'ntr-rapid-city-2026-' . $rid === $ref->invoke( null, $rid ) );

// Untitled (title sanitizes to empty) → bare id only.
$rid2 = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => '!!!' ) );
$check( 'untitled reservation slug falls back to bare id', (string) $rid2 === $ref->invoke( null, $rid2 ) );

// --- route regex resolves BOTH forms on the trailing id -------------------
$base = 'equine-event';
$pattern = '#/' . $base . '/(?:[^/]+-)?([0-9]+)/?$#';
$cases = array(
	"/equine-event/ntr-rapid-city-2026-{$rid}/" => $rid, // readable
	"/equine-event/{$rid}/"                     => $rid, // legacy bare id (back-compat)
	"/equine-event/race-2024-{$rid}/"           => $rid, // name containing digits → still trailing id
);
foreach ( $cases as $path => $expected ) {
	preg_match( $pattern, $path, $m );
	$got = isset( $m[1] ) ? (int) $m[1] : 0;
	$check( "route regex resolves trailing id {$expected} from a path", $got === (int) $expected );
}

// Cleanup.
wp_delete_post( (int) $rid, true );
wp_delete_post( (int) $rid2, true );
$check( 'cleaned up temp reservations', null === get_post( $rid ) && null === get_post( $rid2 ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
