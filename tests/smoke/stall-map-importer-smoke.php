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

/* ── extract_published_key ─────────────────────────────────────── */
sm_ok( 'key: pubhtml URL -> key', '2PACX-abc' === EEM_Stall_Map_Importer::extract_published_key( 'https://docs.google.com/spreadsheets/d/e/2PACX-abc/pubhtml' ), $pass, $fail, $log );
sm_ok( 'key: pub csv URL -> key', '2PACX-abc' === EEM_Stall_Map_Importer::extract_published_key( 'https://docs.google.com/spreadsheets/d/e/2PACX-abc/pub?gid=1&output=csv' ), $pass, $fail, $log );
sm_ok( 'key: private edit URL -> null', null === EEM_Stall_Map_Importer::extract_published_key( 'https://docs.google.com/spreadsheets/d/1ckPjAAk9T/edit' ), $pass, $fail, $log );

/* ── offline parse of the REAL Montcrief mockup CSV ────────────── */
$mont_csv = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . '.mockups/montcrief.csv' );
$mont = EEM_Stall_Map_Importer::parse_grid( $mont_csv );
$snap_mont = array( 'barns' => array( array( 'name' => 'Montcrief', 'grid' => $mont ) ) );
$mont_stalls = EEM_Stall_Map_Importer::stall_labels( $snap_mont );
sm_ok( 'Montcrief parses to 262 stalls', 262 === count( $mont_stalls ), $pass, $fail, $log );
sm_ok( 'Montcrief 0 duplicate labels', array() === EEM_Stall_Map_Importer::find_duplicate_labels( $snap_mont ), $pass, $fail, $log );
sm_ok( 'Montcrief has 5001 and 5262', in_array( '5001', $mont_stalls, true ) && in_array( '5262', $mont_stalls, true ), $pass, $fail, $log );

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

/* ── LIVE import against Whitney's published sheet (network) ────── */
$PUBLISHED = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSOenXtY5omsr9MJwSh034WPfEYGn2kaD0DxIdB74ltsJAsYOBFak0Jff1bJv0HtcRcRtxz7acFJhXJ/pubhtml';
$live = EEM_Stall_Map_Importer::import( $PUBLISHED );
if ( is_wp_error( $live ) ) {
	$fail++; $log[] = 'FAIL: live import returned WP_Error — ' . $live->get_error_message();
} else {
	$names = EEM_Stall_Map_Importer::barn_names( $live );
	sm_ok( 'live: discovers Montcrief + Burnett (in order)', array( 'Montcrief', 'Burnett' ) === $names, $pass, $fail, $log );
	sm_ok( 'live: 2 barns', 2 === count( $live['barns'] ), $pass, $fail, $log );
	sm_ok( 'live: total stalls > 600', count( EEM_Stall_Map_Importer::stall_labels( $live ) ) > 600, $pass, $fail, $log );
	sm_ok( 'live: 0 cross-barn duplicate labels', array() === EEM_Stall_Map_Importer::find_duplicate_labels( $live ), $pass, $fail, $log );

	/* inventory: per-barn counts + grand total (every numbered cell counts) */
	$per = EEM_Stall_Map_Importer::barn_stall_counts( $live );
	$total = EEM_Stall_Map_Importer::count_stalls( $live );
	sm_ok( 'inventory: per-barn counts present for both barns', isset( $per['Montcrief'], $per['Burnett'] ), $pass, $fail, $log );
	sm_ok( 'inventory: total == sum of per-barn counts', $total === array_sum( $per ), $pass, $fail, $log );
	sm_ok( 'inventory: Montcrief == 262', 262 === ( $per['Montcrief'] ?? 0 ), $pass, $fail, $log );
	echo "  [inventory] Montcrief=" . ( $per['Montcrief'] ?? 0 ) . "  Burnett=" . ( $per['Burnett'] ?? 0 ) . "  TOTAL AVAILABLE=" . $total . "\n";
	sm_ok( 'live: snapshot carries source_url + synced_at', ! empty( $live['source_url'] ) && ! empty( $live['synced_at'] ), $pass, $fail, $log );

	/* save → read-back via the canonical consumer (round-trip) */
	$rid = (int) ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
	if ( $rid ) {
		$before = EEM_Stall_Map_Importer::get_for_reservation( $rid ); // preserve to restore
		EEM_Stall_Map_Importer::save_to_reservation( $rid, $live );
		$back = EEM_Stall_Map_Importer::get_for_reservation( $rid );
		sm_ok( 'round-trip: read-back barns match', EEM_Stall_Map_Importer::barn_names( $back ) === $names, $pass, $fail, $log );
		// restore prior state so the smoke leaves no residue
		if ( empty( $before ) ) { delete_post_meta( $rid, EEM_Stall_Map_Importer::META_KEY ); }
		else { EEM_Stall_Map_Importer::save_to_reservation( $rid, $before ); }
	}
}

echo "\n=== Stall Map importer (Slice 1) smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
