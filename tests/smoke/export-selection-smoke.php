<?php
/**
 * Export-selection smoke — build_export() honors the three include-section flags
 * (event / reservation / orders) so the admin can export just the reservation
 * setup, optionally with/without the event, and orders only ride along with the
 * reservation they attach to.
 *
 * Run: wp eval-file tests/smoke/export-selection-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// Reservation 5990 has an event, config/packages, and seeded orders.
$rid = 5990;

$method = new ReflectionMethod( 'EEM_Import_Handler', 'build_export' );
$method->setAccessible( true );

// --- full export (legacy default: all sections) --------------------------
$full = $method->invoke( null, $rid, array() );
$check( 'full: reservation present', ! empty( $full['reservation'] ) );
$check( 'full: config present', isset( $full['config'] ) );
$check( 'full: event present', ! empty( $full['event'] ) );
$check( 'full: orders present', ! empty( $full['stall_orders'] ) || ! empty( $full['rv_orders'] ) );
$check( 'full: all flags true', $full['included']['event'] && $full['included']['reservation'] && $full['included']['orders'] );

// --- setup only: reservation, no event, no orders ------------------------
$setup = $method->invoke( null, $rid, array( 'event' => false, 'reservation' => true, 'orders' => false ) );
$check( 'setup-only: reservation present', ! empty( $setup['reservation'] ) );
$check( 'setup-only: config present (map lives here)', isset( $setup['config'] ) );
$check( 'setup-only: reservation meta carries the map', is_array( $setup['reservation']['meta'] ) );
$check( 'setup-only: packages travel with reservation', isset( $setup['packages'] ) );
$check( 'setup-only: NO event', empty( $setup['event'] ) );
$check( 'setup-only: NO venue', empty( $setup['venue'] ) );
$check( 'setup-only: NO orders', array() === $setup['stall_orders'] && array() === $setup['rv_orders'] );

// --- orders REQUIRE reservation: asking for orders without reservation drops them
$bad = $method->invoke( null, $rid, array( 'event' => true, 'reservation' => false, 'orders' => true ) );
$check( 'orders-without-reservation: reservation absent', empty( $bad['reservation'] ) );
$check( 'orders-without-reservation: orders forced empty', array() === $bad['stall_orders'] && array() === $bad['rv_orders'] );
$check( 'orders-without-reservation: included.orders forced false', false === $bad['included']['orders'] );
$check( 'orders-without-reservation: event still present', ! empty( $bad['event'] ) );

// --- clone-to-new-year: reservation + orders, drop event -----------------
$clone = $method->invoke( null, $rid, array( 'event' => false, 'reservation' => true, 'orders' => true ) );
$check( 'clone: reservation present', ! empty( $clone['reservation'] ) );
$check( 'clone: orders present (reservation included)', ! empty( $clone['stall_orders'] ) || ! empty( $clone['rv_orders'] ) );
$check( 'clone: no event', empty( $clone['event'] ) );

// --- import tolerates an eventless setup-only payload --------------------
$import = new ReflectionMethod( 'EEM_Import_Handler', 'import_setup' );
$import->setAccessible( true );
$result  = $import->invoke( null, $setup );
$new_rid = (int) ( $result['reservation_id'] ?? 0 );
$check( 'import: setup-only payload creates a reservation', $new_rid > 0 );
$check( 'import: no event created from eventless payload', empty( $result['event_id'] ) );
$check( 'import: reservation lands as Draft for review', $new_rid > 0 && 'draft' === get_post_status( $new_rid ) );
if ( $new_rid > 0 ) {
	wp_delete_post( $new_rid, true );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
