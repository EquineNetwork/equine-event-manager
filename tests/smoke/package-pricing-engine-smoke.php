<?php
/**
 * Behavioral smoke — Stay Package pricing through the REAL authoritative engine
 * (Whitney 2026-06-30, master live audit — RES-PKG).
 *
 * Stay Packages bill a FLAT price for the whole stay window (not per-night):
 * selecting a package charges `package_price × quantity`, and mixed package
 * selections sum. This drives the exact private chain
 * ajax_create_stripe_payment_intent uses — get_reservation_meta →
 * get_reservation_status → sanitize_submission → resolve_*_tier_submission →
 * calculate_submission_totals — against a real package reservation, so it proves
 * the engine the gateway charge is built from, not a reimplementation.
 *
 * Discovers a package reservation dynamically (skips clean if none), so it runs
 * on any environment without hard-coding a reservation/package id.
 *
 * Run: wp eval-file tests/smoke/package-pricing-engine-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

if ( ! class_exists( 'EEM_Stay_Packages_Repo' ) ) {
	WP_CLI::warning( 'EEM_Stay_Packages_Repo missing — skipping.' );
	WP_CLI::success( 'Skipped.' ); return;
}

// Find a publish reservation in stall package mode with >=1 stall package.
$q = new WP_Query( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$rid = 0; $pkgs = array();
foreach ( $q->posts as $id ) {
	$cfg  = EEM_Reservation_Config::for( $id )->all();
	$mode = $cfg['stall_pricing_mode'] ?? 'nightly';
	if ( ! in_array( $mode, array( 'packages', 'both' ), true ) ) { continue; }
	$p = EEM_Stay_Packages_Repo::get_packages( $id, 'stall' );
	if ( $p ) { $rid = $id; $pkgs = $p; break; }
}
if ( ! $rid ) { WP_CLI::warning( 'No package reservation found — skipping.' ); WP_CLI::success( 'Skipped.' ); return; }
WP_CLI::log( "Package reservation #{$rid} with " . count( $pkgs ) . ' stall package(s).' );

$sc   = new EEM_Shortcodes();
$call = function ( $name ) use ( $sc ) { $m = new ReflectionMethod( $sc, $name ); $m->setAccessible( true ); return $m; };
$rm = $call( 'get_reservation_meta' ); $rs = $call( 'get_reservation_status' ); $ss = $call( 'sanitize_submission' );
$rrt = $call( 'resolve_rv_tier_submission' ); $rst = $call( 'resolve_stall_tier_submission' ); $ct = $call( 'calculate_submission_totals' );
$arp = new ReflectionProperty( $sc, 'active_reservation_id' ); $arp->setAccessible( true );

$engine = function ( $post ) use ( $sc, $rid, $rm, $rs, $ss, $rrt, $rst, $ct, $arp ) {
	$_POST = $post; $arp->setValue( $sc, (int) $rid );
	$data = $rm->invoke( $sc, $rid );
	$status = $rs->invoke( $sc, $data, $rid );
	$sub = $ss->invoke( $sc, $data );
	$sub = $rrt->invoke( $sc, $sub, $data, (int) $rid );
	$sub = $rst->invoke( $sc, $sub, $data, (int) $rid );
	$t = $ct->invoke( $sc, $data, $sub, $status, $rid );
	$_POST = array();
	return $t;
};

// Case 1 — first package × 2 → stall_subtotal == price × 2.
$p0    = $pkgs[0];
$pid0  = (int) $p0['id'];
$price0 = (float) $p0['price'];
$t1 = $engine( array( 'stall_stay_type' => 'pkg_' . $pid0, 'stall_qty_pkg_' . $pid0 => '2', 'stall_same_for_all' => '1' ) );
$check( "package '{$p0['name']}' ×2 prices stall at flat price×2 (" . number_format( $price0 * 2, 2 ) . ')', abs( (float) $t1['stall_subtotal'] - $price0 * 2 ) < 0.005 );
$check( 'total reconciles: stall + rv + fee + tax == total', abs( ( (float) $t1['stall_subtotal'] + (float) $t1['rv_subtotal'] + (float) $t1['fees'] + (float) $t1['tax'] ) - (float) $t1['total'] ) < 0.005 );

// Case 2 — mixed: pkg0 ×1 + pkg1 ×2 (when a 2nd package exists) → sum of flats.
if ( count( $pkgs ) >= 2 ) {
	$p1 = $pkgs[1]; $pid1 = (int) $p1['id']; $price1 = (float) $p1['price'];
	$expect = $price0 * 1 + $price1 * 2;
	$t2 = $engine( array( 'stall_stay_type' => 'pkg_' . $pid0, 'stall_qty_pkg_' . $pid0 => '1', 'stall_qty_pkg_' . $pid1 => '2', 'stall_same_for_all' => '1' ) );
	$check( "mixed packages ({$p0['name']}×1 + {$p1['name']}×2) sum to " . number_format( $expect, 2 ), abs( (float) $t2['stall_subtotal'] - $expect ) < 0.005 );
}

// Case 3 — a package bills ONCE, not per-night: stall_subtotal must NOT equal price × night_count.
$nights = 1;
if ( isset( $p0['start_date'], $p0['end_date'] ) && $p0['start_date'] && $p0['end_date'] ) {
	$nights = max( 1, (int) round( ( strtotime( $p0['end_date'] ) - strtotime( $p0['start_date'] ) ) / DAY_IN_SECONDS ) );
}
if ( $nights > 1 ) {
	$t3 = $engine( array( 'stall_stay_type' => 'pkg_' . $pid0, 'stall_qty_pkg_' . $pid0 => '1', 'stall_same_for_all' => '1' ) );
	$check( "single package bills flat once (not ×{$nights} nights)", abs( (float) $t3['stall_subtotal'] - $price0 ) < 0.005 && abs( (float) $t3['stall_subtotal'] - $price0 * $nights ) > 0.005 );
}

// Case 4 — SURFACE LABELS: a live `pkg_<id>` stay type must render the package
// NAME (not the raw "Pkg_7" identifier) and "Package" units on receipts /
// Order Detail. Regression guard for the format_stay_type_label fix.
$lbl = new ReflectionMethod( $sc, 'format_stay_type_label' ); $lbl->setAccessible( true );
$bli = new ReflectionMethod( $sc, 'build_order_line_items' );  $bli->setAccessible( true );
$name0 = (string) $p0['name'];
$check( "format_stay_type_label('pkg_{$pid0}') renders the package name '{$name0}' (not 'Pkg_{$pid0}')", $lbl->invoke( $sc, 'pkg_' . $pid0 ) === $name0 );
$synthetic = array(
	'stall_stay_type' => 'pkg_' . $pid0, 'stall_arrival_date' => (string) ( $p0['start_date'] ?? '2026-06-25' ), 'stall_departure_date' => (string) ( $p0['end_date'] ?? '2026-06-28' ),
	'rv_stay_type' => 'nightly', 'rv_arrival_date' => '', 'rv_departure_date' => '',
	'stall_quantity' => 2, 'rv_quantity' => 0, 'stall_subtotal' => $price0 * 2, 'rv_subtotal' => 0,
	'required_shavings_qty' => 0, 'additional_shavings_qty' => 0, 'notes' => '', 'fees' => 0, 'tax' => 0, 'total' => $price0 * 2, 'components' => array(),
);
$rows  = $bli->invoke( $sc, $synthetic, false );
$stall_row = null;
foreach ( $rows as $r ) { if ( __( 'Stall Res.', 'equine-event-manager' ) === $r['section'] ) { $stall_row = $r; break; } }
$check( 'receipt stall line desc carries the package name (not "Pkg_")', $stall_row && false !== strpos( $stall_row['desc'], $name0 ) && false === stripos( $stall_row['desc'], 'Pkg_' ) );
$check( 'receipt stall line units column reads "Package"', $stall_row && __( 'Package', 'equine-event-manager' ) === $stall_row['units'] );

WP_CLI::log( "\n=== Package pricing engine smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Package pricing engine smoke passed.' );
