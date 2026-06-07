<?php
/**
 * Smoke: v4 Slice 5 publish gate — Pick-from-layout requires a connected map.
 *
 * Pure-logic assertions on EEM_Reservation_Editor_Page::validate_for_publish().
 * The gate reads $ctx (stall_inventory_type, stall_customer_selection,
 * stall_has_map, stall_row_count) so it's DB-free and deterministic here.
 *
 * Run: {php} tests/smoke/stall-map-publish-gate-smoke.php
 */

define( 'ABSPATH', '/tmp/' );
foreach ( array( '__', 'esc_html__', 'esc_attr__' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function $fn( \$s, \$d = null ) { return \$s; }" );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = null ) { return $n === 1 ? $a : $b; } }

require_once __DIR__ . '/../../admin/class-eem-reservation-editor-page.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  NOT - $label\n"; }
}

// Minimal valid stall candidate (one stay type + rate) so only the layout gate fires.
$base = array(
	'stalls_enabled'         => 1,
	'stall_nightly_enabled'  => 1,
	'stall_nightly_rate'     => 50,
);

// 1. Pick + NO map + NO rows -> blocked.
$err = EEM_Reservation_Editor_Page::validate_for_publish( $base, 0, array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'pick_layout',
	'stall_has_map'            => false,
	'stall_row_count'          => 0,
) );
ok( isset( $err['stall'] ) && false !== strpos( $err['stall'], 'Pick from layout' ), 'pick + no map + no rows -> blocked with map message' );

// 2. Pick + HAS map -> allowed.
$err = EEM_Reservation_Editor_Page::validate_for_publish( $base, 0, array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'pick_layout',
	'stall_has_map'            => true,
	'stall_row_count'          => 0,
) );
ok( ! isset( $err['stall'] ), 'pick + connected map -> allowed' );

// 3. Pick + legacy rows + NO map -> allowed (grandfather/fallback).
$err = EEM_Reservation_Editor_Page::validate_for_publish( $base, 0, array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'pick_layout',
	'stall_has_map'            => false,
	'stall_row_count'          => 2,
) );
ok( ! isset( $err['stall'] ), 'pick + legacy rows + no map -> allowed (grandfather)' );

// 4. Quantity (numbered) + NO rows -> blocked (existing behaviour, not the map message).
$err = EEM_Reservation_Editor_Page::validate_for_publish( $base, 0, array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'quantity',
	'stall_has_map'            => false,
	'stall_row_count'          => 0,
) );
ok( isset( $err['stall'] ) && false !== strpos( $err['stall'], 'no stall rows' ), 'quantity + no rows -> blocked with rows message' );

// 5. Quantity (numbered) + rows -> allowed.
$err = EEM_Reservation_Editor_Page::validate_for_publish( $base, 0, array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'quantity',
	'stall_has_map'            => false,
	'stall_row_count'          => 3,
) );
ok( ! isset( $err['stall'] ), 'quantity + rows -> allowed' );

// 6. Quantity-only (not numbered) -> layout gate does not fire.
$err = EEM_Reservation_Editor_Page::validate_for_publish( $base, 0, array(
	'stall_inventory_type'     => 'quantity_only',
	'stall_customer_selection' => 'quantity',
	'stall_has_map'            => false,
	'stall_row_count'          => 0,
) );
ok( ! isset( $err['stall'] ), 'quantity_only -> no layout gate' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
