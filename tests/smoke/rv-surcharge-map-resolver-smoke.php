<?php
/**
 * RV Surcharge Slice 3 (data foundation) smoke — map snapshot surcharge schema.
 *
 * Verifies that snapshot_from_builder() preserves the barn tab-level surcharge,
 * the painted-area registry, and per-cell area / multi-cell (w,h) fields, and
 * that surcharge_for_unit() stacks tab + area surcharge ("most layers add").
 *
 * Run: wp eval-file tests/smoke/rv-surcharge-map-resolver-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$barns = array( array(
	'name'      => 'RV Lot',
	'surcharge' => array( 'nightly' => 5, 'packages' => array( 'wk' => 50 ) ),
	'areas'     => array( array( 'id' => 'Paddocks!', 'name' => 'Paddocks', 'color' => '#00aa00', 'surcharge' => array( 'nightly' => 10, 'packages' => array( 'wk' => 100 ) ) ) ),
	'grid'      => array( array(
		array( 'type' => 'stall', 'label' => '1' ),
		array( 'type' => 'stall', 'label' => '2', 'area' => 'paddocks', 'w' => 2, 'h' => 2 ),
		array( 'type' => 'gap', 'label' => '' ),
	) ),
) );

$snap = EEM_Stall_Map_Importer::snapshot_from_builder( $barns, 'rv' );
$barn = $snap['barns'][0];

$check( 'barn tab surcharge preserved (nightly 5)', 5.0 === $barn['surcharge']['nightly'] );
$check( 'barn tab surcharge package wk 50', 50.0 === $barn['surcharge']['packages']['wk'] );
$check( 'area id sanitized to paddocks', $barn['areas'][0]['id'] === 'paddocks' );
$check( 'area name + color preserved', $barn['areas'][0]['name'] === 'Paddocks' && $barn['areas'][0]['color'] === '#00aa00' );
$check( 'area surcharge nightly 10', 10.0 === $barn['areas'][0]['surcharge']['nightly'] );
$check( 'cell area id preserved', ( $barn['grid'][0][1]['area'] ?? '' ) === 'paddocks' );
$check( 'cell multi-cell w/h preserved (2,2)', 2 === ( $barn['grid'][0][1]['w'] ?? 0 ) && 2 === ( $barn['grid'][0][1]['h'] ?? 0 ) );
$check( 'plain stall omits w/h + area', ! isset( $barn['grid'][0][0]['w'] ) && ! isset( $barn['grid'][0][0]['area'] ) );

$s1 = EEM_Stall_Map_Importer::surcharge_for_unit( $snap, 'rv lot', '1' );
$check( 'unit 1 (no area) = tab only nightly 5 / wk 50', 5.0 === $s1['nightly'] && 50.0 === $s1['packages']['wk'] );
$s2 = EEM_Stall_Map_Importer::surcharge_for_unit( $snap, 'RV Lot', '2' );
$check( 'unit 2 (area) stacks nightly 5+10=15', 15.0 === $s2['nightly'] );
$check( 'unit 2 (area) stacks wk 50+100=150', 150.0 === $s2['packages']['wk'] );
$check( 'missing barn → zero', EEM_Surcharge::is_zero( EEM_Stall_Map_Importer::surcharge_for_unit( $snap, 'Nope', '1' ) ) );
$check( 'missing label → tab only', 5.0 === EEM_Stall_Map_Importer::surcharge_for_unit( $snap, 'RV Lot', '999' )['nightly'] );

WP_CLI::log( '' );
if ( $fail ) { WP_CLI::error( "{$fail} failed, {$pass} passed" ); }
WP_CLI::success( "{$pass} passed, 0 failed" );
