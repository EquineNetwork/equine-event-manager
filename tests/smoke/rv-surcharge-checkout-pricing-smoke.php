<?php
/**
 * RV Surcharge Slice 4 smoke — customer pick-from-layout checkout pricing.
 *
 * Verifies get_rv_zone_surcharge_for_units() resolves each picked lot's surcharge
 * from the MAP (tab + painted-area, stacked) for the selected stay type:
 *   - Nightly stay → per-night figure (caller multiplies by nights)
 *   - Package stay (pkg_*) → flat per-package figure (caller multiplies by 1)
 *
 * Run: wp eval-file tests/smoke/rv-surcharge-checkout-pricing-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// Build a saved-shape RV snapshot: tab +$5/night +$100/pkg, a "Premium" area
// (+$15/night) on lot 1; lot 2 plain.
$barns = array( array(
	'name'      => 'Blue Lot',
	'surcharge' => array( 'nightly' => 5, 'packages' => array( '_all' => 100 ) ),
	'areas'     => array( array( 'id' => 'premium', 'name' => 'Premium', 'color' => '#16a34a', 'surcharge' => array( 'nightly' => 15, 'packages' => array() ) ) ),
	'grid'      => array( array(
		array( 'type' => 'stall', 'label' => '1', 'area' => 'premium' ),
		array( 'type' => 'stall', 'label' => '2' ),
	) ),
) );
$snap = EEM_Stall_Map_Importer::snapshot_from_builder( $barns, 'rv' );
$data = array( 'rv_map' => $snap );

$method = new ReflectionMethod( 'EEM_Shortcodes', 'get_rv_zone_surcharge_for_units' );
$method->setAccessible( true );
$sc = new EEM_Shortcodes();
$call = function ( $units, $stay ) use ( $method, $sc, $data ) {
	return (float) $method->invoke( $sc, $data, $units, $stay );
};

// Nightly: lot1 = tab5 + area15 = 20; lot2 = tab5. Sum = 25 (per-night).
$check( 'nightly: premium lot stacks tab+area = 20', 20.0 === $call( array( 'Blue Lot 1' ), 'nightly' ) );
$check( 'nightly: plain lot = tab only 5', 5.0 === $call( array( 'Blue Lot 2' ), 'nightly' ) );
$check( 'nightly: both lots sum = 25', 25.0 === $call( array( 'Blue Lot 1', 'Blue Lot 2' ), 'nightly' ) );

// Package: flat per-package = tab _all 100 (area has no pkg amount). Both lots 100 each.
$check( 'package: premium lot = tab _all 100', 100.0 === $call( array( 'Blue Lot 1' ), 'pkg_6' ) );
$check( 'package: both lots sum = 200', 200.0 === $call( array( 'Blue Lot 1', 'Blue Lot 2' ), 'pkg_6' ) );

// Edge: empty units = 0; unknown lot = 0.
$check( 'empty units = 0', 0.0 === $call( array(), 'nightly' ) );
$check( 'unknown lot ignored = 0', 0.0 === $call( array( 'Nope 9' ), 'nightly' ) );

WP_CLI::log( '' );
if ( $fail ) { WP_CLI::error( "{$fail} failed, {$pass} passed" ); }
WP_CLI::success( "{$pass} passed, 0 failed" );
