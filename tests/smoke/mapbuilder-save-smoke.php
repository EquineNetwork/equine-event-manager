<?php
/**
 * Native Map Builder — C1: snapshot_from_builder() round-trip.
 *
 * Proves the builder payload → canonical snapshot → consumer-helper path that
 * replaces the Google-Sheet import. Builds a stall map and an RV map from
 * builder-shaped grid data, asserts the snapshot shape + counts + kind, the
 * duplicate-label guard, the gap/landmark sanitisation, and a save/get
 * round-trip via the canonical consumer (get_for_reservation).
 */

if ( ! function_exists( 'get_option' ) ) {
	fwrite( STDERR, "must run via wp eval-file\n" );
	return;
}

$pass = 0; $fail = 0; $log = array();
function mb_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; echo "  \xE2\x9C\x93 $label\n"; }
	else { $fail++; $log[] = "FAIL: $label"; echo "  \xE2\x9C\x97 $label\n"; }
}

/* A builder-shaped stall map: 1 zone, a row of 3 stalls (101-103), an aisle
   cell, and a 1x2 landmark — mirroring what the JS builder serialises. */
$stall_barns = array(
	array(
		'name' => 'Montcrief',
		'grid' => array(
			array(
				array( 'type' => 'stall', 'label' => '101' ),
				array( 'type' => 'stall', 'label' => '102' ),
				array( 'type' => 'stall', 'label' => '103' ),
			),
			array(
				array( 'type' => 'gap', 'label' => '' ),
				array( 'type' => 'landmark', 'label' => 'Wash Rack' ),
				array( 'type' => 'landmark', 'label' => 'Wash Rack' ),
			),
		),
	),
);

$snap = EEM_Stall_Map_Importer::snapshot_from_builder( $stall_barns, 'stall' );
mb_ok( 'source is builder', 'builder' === ( $snap['source'] ?? '' ), $pass, $fail, $log );
mb_ok( 'synced_at is set', ! empty( $snap['synced_at'] ), $pass, $fail, $log );
mb_ok( 'one barn', 1 === count( $snap['barns'] ), $pass, $fail, $log );
mb_ok( 'barn kind = stall', 'stall' === $snap['barns'][0]['kind'], $pass, $fail, $log );
mb_ok( 'barn name preserved', 'Montcrief' === $snap['barns'][0]['name'], $pass, $fail, $log );
mb_ok( 'rows = 2', 2 === $snap['barns'][0]['rows'], $pass, $fail, $log );
mb_ok( 'cols = 3', 3 === $snap['barns'][0]['cols'], $pass, $fail, $log );
mb_ok( 'counts 3 stalls', 3 === EEM_Stall_Map_Importer::count_stalls( $snap ), $pass, $fail, $log );
$labels = EEM_Stall_Map_Importer::stall_labels( $snap );
mb_ok( 'labels are 101-103', array( '101', '102', '103' ) === $labels, $pass, $fail, $log );
mb_ok( 'landmark survives', 'landmark' === $snap['barns'][0]['grid'][1][1]['type'] && 'Wash Rack' === $snap['barns'][0]['grid'][1][1]['label'], $pass, $fail, $log );
mb_ok( 'aisle stays gap', 'gap' === $snap['barns'][0]['grid'][1][0]['type'], $pass, $fail, $log );

/* Sanitisation: a stall cell with no label collapses to a gap (never sellable). */
$bad = EEM_Stall_Map_Importer::snapshot_from_builder( array( array( 'name' => 'X', 'grid' => array( array( array( 'type' => 'stall', 'label' => '' ) ) ) ) ), 'stall' );
mb_ok( 'empty-label stall -> gap', 0 === EEM_Stall_Map_Importer::count_stalls( $bad ), $pass, $fail, $log );

/* Rectangular padding: a short row pads to the widest. */
$ragged = EEM_Stall_Map_Importer::snapshot_from_builder( array( array( 'name' => 'X', 'grid' => array(
	array( array( 'type' => 'stall', 'label' => '1' ), array( 'type' => 'stall', 'label' => '2' ) ),
	array( array( 'type' => 'stall', 'label' => '3' ) ),
) ) ), 'stall' );
mb_ok( 'ragged row padded to cols=2', 2 === count( $ragged['barns'][0]['grid'][1] ), $pass, $fail, $log );
mb_ok( 'padded cell is gap', 'gap' === $ragged['barns'][0]['grid'][1][1]['type'], $pass, $fail, $log );

/* Duplicate stall guard: same label in two zones is rejected for stalls. */
$dup = EEM_Stall_Map_Importer::snapshot_from_builder( array(
	array( 'name' => 'A', 'grid' => array( array( array( 'type' => 'stall', 'label' => '5' ) ) ) ),
	array( 'name' => 'B', 'grid' => array( array( array( 'type' => 'stall', 'label' => '5' ) ) ) ),
), 'stall' );
mb_ok( 'dup label detected', array( '5' ) === EEM_Stall_Map_Importer::find_duplicate_labels( $dup ), $pass, $fail, $log );

/* RV: kind forced to rv; lots repeat per zone (cross-zone dup is allowed). */
$rv = EEM_Stall_Map_Importer::snapshot_from_builder( array(
	array( 'name' => 'Red Lot', 'grid' => array( array( array( 'type' => 'stall', 'label' => '1' ), array( 'type' => 'stall', 'label' => '2' ) ) ) ),
	array( 'name' => 'Blue Lot', 'grid' => array( array( array( 'type' => 'stall', 'label' => '1' ), array( 'type' => 'stall', 'label' => '2' ) ) ) ),
), 'rv' );
mb_ok( 'rv barns kind = rv', 'rv' === $rv['barns'][0]['kind'] && 'rv' === $rv['barns'][1]['kind'], $pass, $fail, $log );
mb_ok( 'rv counts 4 lots total', 4 === EEM_Stall_Map_Importer::count_stalls( $rv ), $pass, $fail, $log );
mb_ok( 'rv snapshot_of_kind keeps both zones', 2 === count( EEM_Stall_Map_Importer::barns_of_kind( $rv, 'rv' ) ), $pass, $fail, $log );

/* Save/get round-trip via the canonical consumer on a throwaway reservation. */
$rid = wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_title' => 'MB Smoke', 'post_status' => 'draft' ) );
mb_ok( 'temp reservation created', $rid > 0, $pass, $fail, $log );
if ( $rid > 0 ) {
	EEM_Stall_Map_Importer::save_to_reservation( $rid, $snap, EEM_Stall_Map_Importer::META_KEY );
	EEM_Stall_Map_Importer::save_to_reservation( $rid, $rv, EEM_Stall_Map_Importer::RV_META_KEY );
	$got_stall = EEM_Stall_Map_Importer::get_for_reservation( $rid, EEM_Stall_Map_Importer::META_KEY );
	$got_rv    = EEM_Stall_Map_Importer::get_for_reservation( $rid, EEM_Stall_Map_Importer::RV_META_KEY );
	mb_ok( 'round-trip stall map: 3 stalls', 3 === EEM_Stall_Map_Importer::count_stalls( $got_stall ), $pass, $fail, $log );
	mb_ok( 'round-trip rv map: 4 lots', 4 === EEM_Stall_Map_Importer::count_stalls( $got_rv ), $pass, $fail, $log );
	mb_ok( 'stall + rv slots are independent', 'stall' === $got_stall['barns'][0]['kind'] && 'rv' === $got_rv['barns'][0]['kind'], $pass, $fail, $log );
	wp_delete_post( $rid, true );
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "$l\n"; }
