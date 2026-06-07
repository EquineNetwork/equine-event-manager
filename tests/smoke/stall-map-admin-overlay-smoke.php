<?php
/**
 * Smoke: v4 Slice 4 admin By-Location map data layer.
 *
 * Source-presence + pure-logic assertions on the overlay state builder and the
 * Last, First name formatter (reflection — both are private). The render +
 * AJAX block/unblock loop is browser-verified separately on reservation 6124;
 * assign/unassign/tack reuse the same dispatch + the proven
 * update_order_unit_assignments / update_order_tack_stalls writers.
 *
 * Run: {php} tests/smoke/stall-map-admin-overlay-smoke.php
 */

define( 'ABSPATH', '/tmp/' );
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  NOT - $label\n"; }
}

// ── format_customer_last_first via reflection ──────────────────────────────
require_once __DIR__ . '/../../admin/class-equine-event-manager-admin.php';

$ref = new ReflectionClass( 'EEM_Admin' );
$admin = $ref->newInstanceWithoutConstructor();

$fmt = $ref->getMethod( 'format_customer_last_first' );
$fmt->setAccessible( true );
ok( 'Smith, James' === $fmt->invoke( $admin, 'James Smith' ), 'formats "James Smith" -> "Smith, James"' );
ok( 'Garcia, Antonio' === $fmt->invoke( $admin, 'Antonio Garcia' ), 'formats two-token name' );
ok( 'Lee, Maria Jane' === $fmt->invoke( $admin, 'Maria Jane Lee' ), 'surname = last token; rest = given' );
ok( 'Smith, James' === $fmt->invoke( $admin, 'Smith, James' ), 'already comma-formatted passes through' );
ok( 'Patriot' === $fmt->invoke( $admin, 'Patriot' ), 'single-token (business) passes through' );
ok( '' === $fmt->invoke( $admin, '   ' ), 'whitespace-only -> empty' );

// ── build_stall_map_overlay_state via reflection ───────────────────────────
$build = $ref->getMethod( 'build_stall_map_overlay_state' );
$build->setAccessible( true );

$order_rows = array(
	array(
		'order_key'    => 'ok_a',
		'order_number' => '00021',
		'customer_name' => 'James Smith',
		'group_name'   => 'Smith Barn',
		'stall_units'  => array( '101', '102' ),
		'tack_units'   => array( '102' ),
	),
	array(
		'order_key'    => 'ok_b',
		'order_number' => '00022',
		'customer_name' => 'Maria Lee',
		'group_name'   => '4-H Team',
		'stall_units'  => array( '205' ),
		'tack_units'   => array(),
	),
	array(
		'order_key'    => 'ok_c',
		'order_number' => '00023',
		'customer_name' => 'Antonio Garcia',
		'group_name'   => '', // no group
		'stall_units'  => array( '300' ),
		'tack_units'   => array(),
	),
);
$blocked = array( '110', '101' ); // 101 is assigned -> assignment wins

$res = $build->invoke( $admin, $order_rows, $blocked );
$state = $res['state'];
$groups = $res['groups'];
$customers = $res['customers'];

ok( isset( $state['101'] ) && 'reserved' === $state['101']['s'], '101 reserved (assignment wins over block)' );
ok( isset( $state['102'] ) && 'tack' === $state['102']['s'], '102 tack (in tack_units)' );
ok( isset( $state['205'] ) && 'reserved' === $state['205']['s'], '205 reserved' );
ok( isset( $state['110'] ) && 'blocked' === $state['110']['s'], '110 blocked (not assigned)' );
ok( 'Smith, James' === $state['101']['c'], '101 customer formatted Last, First' );
ok( 'Smith Barn' === $state['101']['g'], '101 carries group name' );
ok( ! empty( $state['101']['gc'] ), '101 carries a group color (gc)' );
ok( empty( $state['300']['g'] ), '300 has no group' );
ok( empty( $state['300']['gc'] ), '300 has no group color' );

// Group colors: distinct groups -> distinct palette colors, stable by sorted name.
ok( 2 === count( $groups ), 'two distinct groups detected' );
ok( isset( $groups['Smith Barn'] ) && isset( $groups['4-H Team'] ), 'both group names present' );
ok( $groups['Smith Barn'] !== $groups['4-H Team'], 'distinct groups get distinct colors' );
ok( '#' === substr( $groups['Smith Barn'], 0, 1 ), 'group color is a hex value' );

// Customer roster (typeahead): deduped, sorted by Last, First.
$names = array_map( function ( $c ) { return $c['n']; }, $customers );
ok( 3 === count( $customers ), 'three customers in roster' );
ok( $names === array( 'Garcia, Antonio', 'Lee, Maria', 'Smith, James' ), 'roster sorted by Last, First: ' . implode( ' | ', $names ) );
ok( $customers[0]['o'] === 'ok_c', 'roster carries order_key' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
