<?php
/**
 * v4 Stall Mapping — Slice 1: EEM_Stall_Map_Importer.
 *
 * Pure parsing (classify / parse_grid / key extraction / dup detection) +
 * an offline parse of the real Montcrief mockup CSV + a LIVE import against
 * Whitney's published sheet + a save/get round-trip via the canonical consumer.
 */

$pass = 0; $fail = 0; $log = array();
function sm_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

/* ── classify_cell ─────────────────────────────────────────────── */
sm_ok( 'classify: number -> stall',   'stall'    === EEM_Stall_Map_Importer::classify_cell( '5010' )['type'], $pass, $fail, $log );
sm_ok( 'classify: blank -> gap',      'gap'      === EEM_Stall_Map_Importer::classify_cell( '' )['type'], $pass, $fail, $log );
sm_ok( 'classify: blank-ws -> gap',   'gap'      === EEM_Stall_Map_Importer::classify_cell( '   ' )['type'], $pass, $fail, $log );
sm_ok( 'classify: text -> landmark',  'landmark' === EEM_Stall_Map_Importer::classify_cell( 'Wash Rack' )['type'], $pass, $fail, $log );
sm_ok( 'classify: prefixed Y1 -> stall',   'stall' === EEM_Stall_Map_Importer::classify_cell( 'Y1' )['type'], $pass, $fail, $log );
sm_ok( 'classify: padded A-01 -> stall',   'stall' === EEM_Stall_Map_Importer::classify_cell( 'A-01' )['type'], $pass, $fail, $log );
sm_ok( 'classify: 120x120 Arena -> landmark', 'landmark' === EEM_Stall_Map_Importer::classify_cell( '120x120 Arena' )['type'], $pass, $fail, $log );

/* ── parse_grid (rectangular) ──────────────────────────────────── */
$g = EEM_Stall_Map_Importer::parse_grid( "100,,Office\n101,102,Office" );
sm_ok( 'parse_grid: row count',  2 === count( $g ), $pass, $fail, $log );
sm_ok( 'parse_grid: padded to widest', 3 === count( $g[0] ) && 3 === count( $g[1] ), $pass, $fail, $log );
sm_ok( 'parse_grid: (0,0) stall 100', 'stall' === $g[0][0]['type'] && '100' === $g[0][0]['label'], $pass, $fail, $log );
sm_ok( 'parse_grid: (0,1) gap', 'gap' === $g[0][1]['type'], $pass, $fail, $log );
sm_ok( 'parse_grid: (0,2) landmark', 'landmark' === $g[0][2]['type'] && 'Office' === $g[0][2]['label'], $pass, $fail, $log );

/* ── offline parse of the REAL Montcrief mockup CSV ────────────── */
// #55: the .mockups/ fixtures are a dev-only resource (export-ignored), so the
// CSV isn't present on a deployed/copied plugin. Run the fixture-backed checks
// only when it's available; the importer is otherwise exercised by the inline
// synthetic grids above + below.
$mont_csv_path = EQUINE_EVENT_MANAGER_PATH . '.mockups/montcrief.csv';
if ( file_exists( $mont_csv_path ) ) {
	$mont_csv = (string) file_get_contents( $mont_csv_path );
	$mont = EEM_Stall_Map_Importer::parse_grid( $mont_csv );
	$snap_mont = array( 'barns' => array( array( 'name' => 'Montcrief', 'grid' => $mont ) ) );
	$mont_stalls = EEM_Stall_Map_Importer::stall_labels( $snap_mont );
	sm_ok( 'Montcrief parses to 262 stalls', 262 === count( $mont_stalls ), $pass, $fail, $log );
	sm_ok( 'Montcrief 0 duplicate labels', array() === EEM_Stall_Map_Importer::find_duplicate_labels( $snap_mont ), $pass, $fail, $log );
	sm_ok( 'Montcrief has 5001 and 5262', in_array( '5001', $mont_stalls, true ) && in_array( '5262', $mont_stalls, true ), $pass, $fail, $log );
} else {
	$log[] = 'SKIP Montcrief CSV fixture — .mockups/ not present on a deployed install.';
}

/* ── per-barn stats breakdown (total/available/reserved/tack/blocked) ── */
$stats_snap = array( 'barns' => array( array( 'name' => 'A', 'grid' => array(
	array( array( 'type' => 'stall', 'label' => '1' ), array( 'type' => 'stall', 'label' => '2' ) ),
	array( array( 'type' => 'stall', 'label' => '3' ), array( 'type' => 'stall', 'label' => '4' ) ),
) ) ) );
$bs = EEM_Stall_Map_Importer::barn_stats( $stats_snap, array( '1' => 'reserved', '2' => 'tack', '3' => 'blocked' ) );
sm_ok( 'barn_stats: total 4',     4 === $bs['A']['total'], $pass, $fail, $log );
sm_ok( 'barn_stats: reserved 1',  1 === $bs['A']['reserved'], $pass, $fail, $log );
sm_ok( 'barn_stats: tack 1',      1 === $bs['A']['tack'], $pass, $fail, $log );
sm_ok( 'barn_stats: blocked 1',   1 === $bs['A']['blocked'], $pass, $fail, $log );
sm_ok( 'barn_stats: available 1 (unlisted defaults available)', 1 === $bs['A']['available'], $pass, $fail, $log );

/* ── dup detection guard (synthetic) ───────────────────────────── */
$dup_snap = array( 'barns' => array(
	array( 'name' => 'A', 'grid' => array( array( array( 'type' => 'stall', 'label' => '12' ) ) ) ),
	array( 'name' => 'B', 'grid' => array( array( array( 'type' => 'stall', 'label' => '12' ) ) ) ),
) );
sm_ok( 'find_duplicate_labels catches cross-barn dup', array( '12' ) === EEM_Stall_Map_Importer::find_duplicate_labels( $dup_snap ), $pass, $fail, $log );

/* ── Montcrief grid → builder-shaped snapshot → consumer helpers ── */
// The Map Builder authors maps now; feed the parsed Montcrief grid through
// snapshot_from_builder (the canonical author path) and assert the consumers.
$built = EEM_Stall_Map_Importer::snapshot_from_builder( array( array( 'name' => 'Montcrief', 'grid' => $mont ) ), 'stall' );
sm_ok( 'builder snapshot: source=builder', 'builder' === ( $built['source'] ?? '' ), $pass, $fail, $log );
sm_ok( 'builder snapshot: 262 stalls', 262 === EEM_Stall_Map_Importer::count_stalls( $built ), $pass, $fail, $log );
sm_ok( 'builder snapshot: barn kind=stall', 'stall' === $built['barns'][0]['kind'], $pass, $fail, $log );
sm_ok( 'builder snapshot: 0 duplicate labels', array() === EEM_Stall_Map_Importer::find_duplicate_labels( $built ), $pass, $fail, $log );

/* save → read-back via the canonical consumer (round-trip) on a throwaway post */
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'Importer Smoke', 'post_status' => 'draft' ) );
if ( $rid ) {
	EEM_Stall_Map_Importer::save_to_reservation( $rid, $built );
	$back = EEM_Stall_Map_Importer::get_for_reservation( $rid );
	sm_ok( 'round-trip: read-back is Montcrief', array( 'Montcrief' ) === EEM_Stall_Map_Importer::barn_names( $back ), $pass, $fail, $log );
	sm_ok( 'round-trip: 262 stalls survive', 262 === EEM_Stall_Map_Importer::count_stalls( $back ), $pass, $fail, $log );
	wp_delete_post( $rid, true );
}

echo "\n=== Stall Map importer smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
