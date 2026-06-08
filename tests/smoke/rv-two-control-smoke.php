<?php
/**
 * Smoke: v4 RV two-control — sanitizers, mode derivation, resolve precedence,
 * and the publish gate (Mapped + Pick requires a connected RV map).
 *
 * Run: {php} tests/smoke/rv-two-control-smoke.php
 */

define( 'ABSPATH', '/tmp/' );
foreach ( array( '__', 'esc_html__', 'esc_attr__' ) as $fn ) {
	if ( ! function_exists( $fn ) ) { eval( "function $fn( \$s, \$d = null ) { return \$s; }" ); }
}
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = null ) { return 1 === $n ? $a : $b; } }
if ( ! function_exists( 'metadata_exists' ) ) { function metadata_exists() { return false; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta() { return ''; } }

require_once __DIR__ . '/../../includes/class-equine-event-manager-reservations-cpt.php';
require_once __DIR__ . '/../../admin/class-eem-reservation-editor-page.php';

$C = 'EEM_Reservations_CPT';
$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $l\n"; } else { $fail++; echo "  NOT - $l\n"; } }

// ── sanitizers + derive ────────────────────────────────────────────────────
ok( 'bulk'   === $C::sanitize_rv_inventory_type( 'bulk' ),    'inv bulk' );
ok( 'mapped' === $C::sanitize_rv_inventory_type( 'mapped' ),  'inv mapped' );
ok( 'bulk'   === $C::sanitize_rv_inventory_type( 'garbage' ), 'inv fallback bulk' );
ok( 'pick_layout' === $C::sanitize_rv_customer_selection( 'pick_layout' ), 'sel pick' );
ok( 'quantity'    === $C::sanitize_rv_customer_selection( 'x' ),           'sel fallback quantity' );
ok( 'exact_map' === $C::derive_rv_selection_mode( 'mapped', 'pick_layout' ), 'mapped+pick -> exact_map' );
ok( 'quantity'  === $C::derive_rv_selection_mode( 'mapped', 'quantity' ),    'mapped+quantity -> quantity' );
ok( 'quantity'  === $C::derive_rv_selection_mode( 'bulk', 'pick_layout' ),   'bulk forces quantity' );

// ── publish gate (validate_for_publish RV branch) ──────────────────────────
$E = 'EEM_Reservation_Editor_Page';
$base = array( 'rv_enabled' => 1, 'rv_nightly_enabled' => 1, 'rv_nightly_rate' => 40 );

// Mapped + Pick + no map + no rows -> blocked.
$err = $E::validate_for_publish( $base, 0, array( 'rv_selection_mode' => 'exact_map', 'rv_inventory_type' => 'mapped', 'rv_customer_selection' => 'pick_layout', 'rv_has_map' => false, 'rv_row_count' => 0 ) );
ok( isset( $err['rv'] ) && false !== strpos( $err['rv'], 'Pick from layout' ), 'pick + no map + no rows -> blocked' );

// Mapped + Pick + map -> allowed.
$err = $E::validate_for_publish( $base, 0, array( 'rv_selection_mode' => 'exact_map', 'rv_inventory_type' => 'mapped', 'rv_customer_selection' => 'pick_layout', 'rv_has_map' => true, 'rv_row_count' => 0 ) );
ok( ! isset( $err['rv'] ), 'pick + connected RV map -> allowed' );

// Mapped + Pick + legacy rows + no map -> allowed (grandfather; needs a zone too).
$err = $E::validate_for_publish( $base, 0, array( 'rv_selection_mode' => 'exact_map', 'rv_inventory_type' => 'mapped', 'rv_customer_selection' => 'pick_layout', 'rv_has_map' => false, 'rv_row_count' => 2, 'rv_zone_count' => 1, 'rv_rows_with_zone' => 2 ) );
ok( ! isset( $err['rv'] ), 'pick + legacy rows + zone -> allowed (grandfather)' );

// Mapped + Quantity + no rows -> blocked (rows message, not the map message).
$err = $E::validate_for_publish( $base, 0, array( 'rv_selection_mode' => 'quantity', 'rv_inventory_type' => 'mapped', 'rv_customer_selection' => 'quantity', 'rv_has_map' => false, 'rv_row_count' => 0 ) );
ok( isset( $err['rv'] ) && false !== strpos( $err['rv'], 'no RV lots' ), 'mapped + quantity + no rows -> blocked' );

// Bulk -> no RV layout gate.
$err = $E::validate_for_publish( $base, 0, array( 'rv_selection_mode' => 'quantity', 'rv_inventory_type' => 'bulk', 'rv_customer_selection' => 'quantity', 'rv_has_map' => false, 'rv_row_count' => 0 ) );
ok( ! isset( $err['rv'] ), 'bulk -> no layout gate' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
