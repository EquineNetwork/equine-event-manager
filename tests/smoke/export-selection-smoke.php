<?php
/**
 * Export-selection smoke — build_export() honors the include-section flags so the
 * admin can export just the reservation setup (map + config) without dragging
 * orders/customers along, optionally omitting the event/venue too.
 *
 * Run: wp eval-file tests/smoke/export-selection-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// Reservation 5990 has an event, packages config, and seeded orders.
$rid = 5990;

$method = new ReflectionMethod( 'EEM_Import_Handler', 'build_export' );
$method->setAccessible( true );

// --- full export (legacy default: all sections) --------------------------
$full = $method->invoke( null, $rid, array() );
$check( 'full: reservation always present', ! empty( $full['reservation'] ) );
$check( 'full: config always present', isset( $full['config'] ) );
$check( 'full: includes event', ! empty( $full['event'] ) );
$check( 'full: includes orders', ! empty( $full['stall_orders'] ) || ! empty( $full['rv_orders'] ) );
$check( 'full: included flags all true', $full['included']['event'] && $full['included']['orders'] && $full['included']['packages'] );

// --- setup only (no orders, no event) ------------------------------------
$setup = $method->invoke( null, $rid, array( 'event' => false, 'packages' => true, 'orders' => false ) );
$check( 'setup-only: reservation still present', ! empty( $setup['reservation'] ) );
$check( 'setup-only: config still present (the map travels here)', isset( $setup['config'] ) );
$check( 'setup-only: reservation meta carries the map', is_array( $setup['reservation']['meta'] ) );
$check( 'setup-only: NO event', empty( $setup['event'] ) );
$check( 'setup-only: NO venue', empty( $setup['venue'] ) );
$check( 'setup-only: NO stall orders', array() === $setup['stall_orders'] );
$check( 'setup-only: NO rv orders', array() === $setup['rv_orders'] );
$check( 'setup-only: included flags reflect choices', false === $setup['included']['event'] && false === $setup['included']['orders'] && true === $setup['included']['packages'] );

// --- event kept, orders dropped (the common clone-to-new-year case) ------
$clone = $method->invoke( null, $rid, array( 'event' => true, 'packages' => true, 'orders' => false ) );
$check( 'clone: keeps event', ! empty( $clone['event'] ) );
$check( 'clone: drops orders', array() === $clone['stall_orders'] && array() === $clone['rv_orders'] );

// --- orders kept, packages dropped ---------------------------------------
$nopkg = $method->invoke( null, $rid, array( 'event' => true, 'packages' => false, 'orders' => true ) );
$check( 'no-packages: packages empty', array() === $nopkg['packages'] );
$check( 'no-packages: orders present', ! empty( $nopkg['stall_orders'] ) || ! empty( $nopkg['rv_orders'] ) );

// --- import tolerates a setup-only (eventless, orderless) payload ---------
// import_setup guards every optional section with !empty(), so a setup-only
// export must import without fatal. Round-trip the setup-only export through it.
$import = new ReflectionMethod( 'EEM_Import_Handler', 'import_setup' );
$import->setAccessible( true );
$result = $import->invoke( null, $setup );
$new_rid = (int) ( $result['reservation_id'] ?? 0 );
$check( 'import: setup-only payload creates a reservation', $new_rid > 0 );
$check( 'import: no event created from eventless payload', empty( $result['event_id'] ) );
if ( $new_rid > 0 ) {
	wp_delete_post( $new_rid, true ); // clean up the imported clone
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
