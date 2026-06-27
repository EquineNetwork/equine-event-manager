<?php
/**
 * Smoke: single-order contiguous stall auto-assign (ROADMAP v1 #7).
 *
 * Verifies assign_order_contiguous_stalls() seats one order's multiple stalls in
 * a consecutive run within a barn, skips single-stall orders, marks occupancy so
 * later orders take the next run, and leaves an order untouched (→ lowest-first
 * fallback) when no run is long enough.
 *
 * No-WP reflection test, matching stall-map-group-contiguous-smoke.php.
 *
 * Run: {php} tests/smoke/single-order-contiguous-smoke.php
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
$call = function ( $name, &...$args ) use ( $ref, $repo ) {
	$m = $ref->getMethod( $name );
	$m->setAccessible( true );
	return $m->invokeArgs( $repo, $args );
};

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  NOT - $label\n"; }
}

// Montcrief 5001..5005 + isolated 5007; Burnett 100..101.
$pool = array( '5001', '5002', '5003', '5004', '5005', '5007', '100', '101' );
$barn = array(
	'5001' => 'Montcrief', '5002' => 'Montcrief', '5003' => 'Montcrief',
	'5004' => 'Montcrief', '5005' => 'Montcrief', '5007' => 'Montcrief',
	'100'  => 'Burnett',   '101'  => 'Burnett',
);
$dates = array( '2026-08-19', '2026-08-20' );

// Orders: A needs 3, B needs 1 (single → skip), C needs 2.
$orders = array(
	0 => array( 'order_key' => 'A' ),
	1 => array( 'order_key' => 'B' ),
	2 => array( 'order_key' => 'C' ),
);
$mk = function ( $need ) use ( $dates ) {
	return array( 'stall_needed' => $need, 'stall_base' => array(), 'stall_dates' => $dates );
};
$state = array( 0 => $mk( 3 ), 1 => $mk( 1 ), 2 => $mk( 2 ) );
$map   = array();

$ref_m = $ref->getMethod( 'assign_order_contiguous_stalls' );
$ref_m->setAccessible( true );
$ref_m->invokeArgs( $repo, array( $orders, &$state, $pool, &$map, $barn ) );

ok( $state[0]['stall_base'] === array( '5001', '5002', '5003' ), 'order A (need 3) → contiguous run 5001,5002,5003: ' . implode( ',', $state[0]['stall_base'] ) );
ok( $state[1]['stall_base'] === array(), 'order B (need 1) → untouched (single stall, no contiguity work)' );
ok( $state[2]['stall_base'] === array( '5004', '5005' ), 'order C (need 2) → next run 5004,5005 after A took 5001-5003: ' . implode( ',', $state[2]['stall_base'] ) );

// No-fit case: an order needing 6 in a facility whose longest free run is 5
// (and now partially taken) is left empty → falls through to lowest-first fill.
$state2 = array( 0 => $mk( 6 ) );
$orders2 = array( 0 => array( 'order_key' => 'Z' ) );
$map2   = array();
$ref_m->invokeArgs( $repo, array( $orders2, &$state2, $pool, &$map2, $barn ) );
ok( $state2[0]['stall_base'] === array(), 'order needing 6 (no run that long) → left empty for lowest-first fallback' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
