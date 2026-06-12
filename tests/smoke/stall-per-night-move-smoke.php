<?php
/**
 * Per-night stall move smoke (2.7.195).
 *
 * Moves were always whole-stay even when the admin picked "Just this night".
 * Now an optional per-night override (`Stall Night Map` note: DATE=units;...)
 * lets a horse occupy different stalls on different nights. Verifies the
 * serialize/parse/resolve helpers + the repo persist round-trip. The live AJAX
 * move + chart re-render were browser-verified at ship time.
 *
 * Run: wp eval-file tests/smoke/stall-per-night-move-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admin = new EEM_Admin();
$ser   = new ReflectionMethod( 'EEM_Admin', 'serialize_stall_night_map' );      $ser->setAccessible( true );
$parse = new ReflectionMethod( 'EEM_Admin', 'parse_stall_night_overrides' );    $parse->setAccessible( true );
$resolve = new ReflectionMethod( 'EEM_Admin', 'get_order_night_assignments' );  $resolve->setAccessible( true );

// --- serialize: uniform -> '' ; non-uniform -> full map --------------------
$uniform = array( '2026-07-02' => array( '5' ), '2026-07-03' => array( '5' ), '2026-07-04' => array( '5' ) );
$check( 'uniform stay serializes to empty (no map needed)', '' === $ser->invoke( $admin, $uniform ) );

$split = array( '2026-07-02' => array( '4' ), '2026-07-03' => array( '5' ), '2026-07-04' => array( '5' ) );
$check( 'split stay serializes every date', '2026-07-02=4;2026-07-03=5;2026-07-04=5' === $ser->invoke( $admin, $split ) );

// --- parse: note line -> date=>units --------------------------------------
$order = array( 'components' => array( array( 'table' => 'stall', 'notes' => "Assigned Stall Units: 4, 5\nStall Night Map: 2026-07-02=4;2026-07-03=5;2026-07-04=5" ) ) );
$parsed = $parse->invoke( $admin, $order );
$check( 'parse reads the override map', isset( $parsed['2026-07-02'] ) && array( '4' ) === $parsed['2026-07-02'] && array( '5' ) === $parsed['2026-07-03'] );

$order_nomap = array( 'components' => array( array( 'table' => 'stall', 'notes' => 'Assigned Stall Units: 5' ) ) );
$check( 'parse returns empty when no map line', array() === $parse->invoke( $admin, $order_nomap ) );

// --- resolve: override wins per date, else flat ----------------------------
$dates = array( '2026-07-02', '2026-07-03', '2026-07-04' );
$res_map = $resolve->invoke( $admin, $order, $dates, array( '4', '5' ) );
$check( 'resolve uses the override per night', array( '4' ) === $res_map['2026-07-02'] && array( '5' ) === $res_map['2026-07-03'] );

$res_flat = $resolve->invoke( $admin, $order_nomap, $dates, array( '5' ) );
$check( 'resolve falls back to flat on every night when no map', array( '5' ) === $res_flat['2026-07-02'] && array( '5' ) === $res_flat['2026-07-04'] );

// --- repo round-trip: write + clear the map line ---------------------------
$repo  = new EEM_Orders_Repository();
$table = $wpdb->prefix . 'en_stall_reservations';
$okey  = 'eem-pn-smoke-' . wp_generate_password( 10, false );
$wpdb->insert( $table, array(
	'customer_name' => 'Per Night', 'email' => 'pn@example.test',
	'order_number' => 'PN1', 'reservation_id' => 0,
	'stall_qty' => 1, 'total' => 3.00, 'payment_status' => 'paid',
	'notes' => "Reservation setup ID: 0\nAssigned Stall Units: 4, 5\nSubmission token: {$okey}",
), array( '%s','%s','%s','%d','%d','%f','%s','%s' ) );
$row_id = (int) $wpdb->insert_id;
// get_order_by_submission_token keys off the token embedded in notes.
$order_obj = $repo->get_order_by_submission_token( $okey );
$ok_seed = is_array( $order_obj ) && ! empty( $order_obj['order_key'] );
$check( 'seeded an order resolvable by key', $ok_seed );

if ( $ok_seed ) {
	$ok_key = (string) $order_obj['order_key'];
	$repo->update_order_stall_night_map( $ok_key, '2026-07-02=4;2026-07-03=5' );
	$after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes FROM `{$table}` WHERE id = %d", $row_id ) );
	$check( 'repo writes the Stall Night Map line', false !== strpos( $after, 'Stall Night Map: 2026-07-02=4;2026-07-03=5' ) );

	$repo->update_order_stall_night_map( $ok_key, '' );
	$cleared = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes FROM `{$table}` WHERE id = %d", $row_id ) );
	$check( 'repo clears the map line on empty value', false === strpos( $cleared, 'Stall Night Map:' ) );
}

$wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
