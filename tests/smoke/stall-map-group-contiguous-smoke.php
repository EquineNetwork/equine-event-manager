<?php
/**
 * Smoke: v4 Slice 7 group-contiguous auto-assign label helpers.
 *
 * Reflection tests on the private label-adjacency helpers + the contiguous-run
 * finder that the group seating relies on. The full auto_assign round-trip is
 * browser/seed-verified on reservation 6124 (Smith Barn -> 5001/5002/5003,
 * 4-H Team -> 5004/5005).
 *
 * Run: {php} tests/smoke/stall-map-group-contiguous-smoke.php
 */

define( 'ABSPATH', '/tmp/' );
foreach ( array( '__', 'esc_html__' ) as $fn ) {
	if ( ! function_exists( $fn ) ) { eval( "function $fn( \$s, \$d = null ) { return \$s; }" ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }

require_once __DIR__ . '/../../includes/class-equine-event-manager-orders-repository.php';

$ref  = new ReflectionClass( 'EEM_Orders_Repository' );
$repo = $ref->newInstanceWithoutConstructor();
$call = function ( $name, ...$args ) use ( $ref, $repo ) {
	$m = $ref->getMethod( $name );
	$m->setAccessible( true );
	return $m->invoke( $repo, ...$args );
};

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  NOT - $label\n"; }
}

// ── label parts / adjacency ────────────────────────────────────────────────
ok( $call( 'stall_labels_consecutive', '5009', '5010' ), '5009 -> 5010 consecutive' );
ok( ! $call( 'stall_labels_consecutive', '5009', '5011' ), '5009 -> 5011 not consecutive' );
ok( $call( 'stall_labels_consecutive', 'A-01', 'A-02' ), 'A-01 -> A-02 consecutive (same prefix)' );
ok( ! $call( 'stall_labels_consecutive', 'A-02', 'B-03' ), 'A-02 -> B-03 not consecutive (prefix differs)' );
ok( $call( 'stall_labels_consecutive', 'Y1', 'Y2' ), 'Y1 -> Y2 consecutive' );

// ── compare (sort) ─────────────────────────────────────────────────────────
$labels = array( '5010', '5001', '5003', '5002' );
usort( $labels, array( $repo, 'compare_stall_labels' ) );
ok( $labels === array( '5001', '5002', '5003', '5010' ), 'compare sorts numerically: ' . implode( ',', $labels ) );

// ── find_contiguous_stall_run ──────────────────────────────────────────────
// Montcrief: 5001..5005 free; 5007 isolated. Burnett: 100..101.
$pool = array( '5001', '5002', '5003', '5004', '5005', '5007', '100', '101' );
$barn = array(
	'5001' => 'Montcrief', '5002' => 'Montcrief', '5003' => 'Montcrief',
	'5004' => 'Montcrief', '5005' => 'Montcrief', '5007' => 'Montcrief',
	'100'  => 'Burnett',   '101'  => 'Burnett',
);
$empty_map = array();
$run3 = $call( 'find_contiguous_stall_run', $pool, $empty_map, $barn, 3, array() );
ok( $run3 === array( '5001', '5002', '5003' ), 'run of 3 -> first contiguous block: ' . implode( ',', $run3 ) );

$run5 = $call( 'find_contiguous_stall_run', $pool, $empty_map, $barn, 5, array() );
ok( $run5 === array( '5001', '5002', '5003', '5004', '5005' ), 'run of 5 -> full Montcrief block' );

$run6 = $call( 'find_contiguous_stall_run', $pool, $empty_map, $barn, 6, array() );
ok( array() === $run6, 'run of 6 -> none (5007 isolated breaks the run, barns separate)' );

$run2b = $call( 'find_contiguous_stall_run', array( '100', '101' ), $empty_map, $barn, 2, array() );
ok( $run2b === array( '100', '101' ), 'run of 2 within Burnett' );

// ── group name extraction ──────────────────────────────────────────────────
ok( 'Smith Barn' === $call( 'extract_group_name_from_notes', "Foo\nGroup Name: Smith Barn\nReservation setup ID: 1" ), 'extracts Group Name line' );
ok( '' === $call( 'extract_group_name_from_notes', "no group here" ), 'no Group Name -> empty' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
