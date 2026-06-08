<?php
/**
 * Smoke: v4 Slice 8 RV tab classification + kind-aware snapshot helpers.
 *
 * Pure-logic assertions on EEM_Stall_Map_Importer's tab-kind classifier and the
 * snapshot_of_kind / barns_of_kind splitters that route RV-named tabs into RV
 * inventory. Config routing (stall_units vs rv_units) is browser/seed-verified.
 *
 * Run: {php} tests/smoke/stall-map-rv-kind-smoke.php
 */

define( 'ABSPATH', '/tmp/' );
require_once __DIR__ . '/../../includes/class-eem-stall-map-importer.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  NOT - $label\n"; }
}

$C = 'EEM_Stall_Map_Importer';

// ── barn_kind default (pre-Slice-8 snapshots have no kind) ─────────────────
ok( 'stall' === $C::barn_kind( array( 'name' => 'Montcrief' ) ), 'missing kind -> stall' );
ok( 'rv'    === $C::barn_kind( array( 'name' => 'X', 'kind' => 'rv' ) ), 'explicit rv kind' );

// ── snapshot_of_kind / barns_of_kind on a mixed sheet ──────────────────────
$snapshot = array(
	'barns' => array(
		array( 'name' => 'Montcrief', 'kind' => 'stall', 'grid' => array( array( array( 'type' => 'stall', 'label' => '101' ), array( 'type' => 'stall', 'label' => '102' ) ) ) ),
		array( 'name' => 'RV North', 'kind' => 'rv', 'grid' => array( array( array( 'type' => 'stall', 'label' => 'R1' ), array( 'type' => 'stall', 'label' => 'R2' ), array( 'type' => 'stall', 'label' => 'R3' ) ) ) ),
	),
);

$stall_only = $C::snapshot_of_kind( $snapshot, 'stall' );
$rv_only    = $C::snapshot_of_kind( $snapshot, 'rv' );

ok( 1 === count( $stall_only['barns'] ) && 'Montcrief' === $stall_only['barns'][0]['name'], 'snapshot_of_kind(stall) keeps only Montcrief' );
ok( 1 === count( $rv_only['barns'] ) && 'RV North' === $rv_only['barns'][0]['name'], 'snapshot_of_kind(rv) keeps only RV North' );
ok( 2 === $C::count_stalls( $stall_only ), 'stall-only snapshot counts 2 stalls' );
ok( 3 === $C::count_stalls( $rv_only ), 'rv-only snapshot counts 3 lots' );
ok( array( '101', '102' ) === $C::stall_labels( $stall_only ), 'stall labels are the stall-tab labels only' );
ok( array( 'R1', 'R2', 'R3' ) === $C::stall_labels( $rv_only ), 'rv labels are the rv-tab labels only' );
ok( 2 === count( $C::barns_of_kind( $snapshot, 'stall' ) ) + count( $C::barns_of_kind( $snapshot, 'rv' ) ), 'split covers all barns' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
