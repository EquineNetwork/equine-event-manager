<?php
/**
 * V1 D2 follow-up — group indicator on the By Location grid.
 *
 * Review feedback (Whitney): the By Location stall map showed names only, with no
 * way to tell which customers are in a group. This adds a per-group colored dot +
 * left-border accent on each occupied pill (same group = same color), with the
 * group name in the hover tooltip; the By Customer chip reuses the same color.
 *
 * Run: wp eval-file tests/smoke/d2-group-location-indicator-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $extra = '' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) ); }
};

$admin = EEM_Admin::for_compute();
$ref   = new ReflectionClass( $admin );
$priv  = function ( $name ) use ( $admin, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m; };

// ── Color helper is deterministic + per-group distinct ──
$gc = $priv( 'group_color_for' );
$c_carlos = $gc->invoke( $admin, 'Delgado Performance Horses' );
$check( 'group_color_for returns a hex color', (bool) preg_match( '/^#[0-9a-f]{6}$/i', $c_carlos ) );
$check( 'group_color_for is deterministic', $c_carlos === $gc->invoke( $admin, 'Delgado Performance Horses' ) );
$check( 'empty group → empty color', '' === $gc->invoke( $admin, '' ) );

// ── By Location grid: occupied pills with a group carry the dot + color + title ──
// Discover the reservation carrying the seeded group orders (tools/seed-test-data.php
// targets whichever reservation has a configured chart), so this isn't tied to a
// hardcoded id.
// The By Location matrix needs grouped orders assigned to GRID CELLS (not just
// group rows), so discover a reservation whose stall-chart grid has grouped cells.
$seed_rid = 0; $cfg = array(); $grid = array();
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $cand ) {
	$ccfg = $priv( 'get_stall_chart_config' )->invoke( $admin, (int) $cand );
	$cgrid = $priv( 'build_stall_chart_grid' )->invoke( $admin, (int) $cand, $ccfg );
	foreach ( (array) ( $cgrid['stall_rows'] ?? array() ) as $crow ) {
		foreach ( (array) ( $crow['cells'] ?? array() ) as $ccell ) {
			if ( '' !== trim( (string) ( $ccell['group_name'] ?? '' ) ) ) { $seed_rid = (int) $cand; $cfg = $ccfg; $grid = $cgrid; break 3; }
		}
	}
}
$check( 'found a seeded reservation with grouped grid cells (run tools/seed-test-data.php first)', $seed_rid > 0 );
ob_start();
$priv( 'render_stall_chart_matrix_table' )->invoke( $admin, $grid['stall_rows'], $grid['date_columns'], 'Stall', 'Block' );
$loc_html = ob_get_clean();
// #55: the By-Location grid carries the group as a DATA CONTRACT (data-group +
// data-group-name on occupied pills); the visible group coloring is applied
// CLIENT-SIDE by admin.js when "Show by group" is toggled (same pattern as the
// tack badge). So assert the server contract + the JS handler, not a
// server-rendered badge/color.
$check( 'By Location pills carry data-group-name (group contract)', false !== strpos( $loc_html, 'data-group-name="' ) );
$loc_js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$check( 'admin.js implements Show-by-group from data-group',        false !== strpos( $loc_js, "getAttribute('data-group')" ) );

// ── By Customer roster: chip reuses the same color + a group icon ──
$rows = $priv( 'build_stall_chart_rows' )->invoke( $admin, $seed_rid, $cfg );
ob_start();
$priv( 'render_stall_chart_order_count_table' )->invoke( $admin, $rows, $grid['date_columns'] );
$cust_html = ob_get_clean();
$check( 'By Customer chip carries the group icon', false !== strpos( $cust_html, 'eem-chart-cust-icon--group' ) );
$check( 'By Customer chip sets --eem-group-color', false !== strpos( $cust_html, '--eem-group-color:#' ) );

// ── Cross-view color agreement: the By-Customer chip color matches group_color_for ──
if ( preg_match( '/--eem-group-color:(#[0-9a-f]{6})[^>]*aria-label="Group: ([^"]+)"/i', $cust_html, $lm ) ) {
	$grp      = html_entity_decode( $lm[2], ENT_QUOTES, 'UTF-8' );
	$expected = $gc->invoke( $admin, $grp );
	$check( 'By Customer chip color matches group_color_for', strtolower( $lm[1] ) === strtolower( $expected ), "grp={$grp}" );
} else {
	$check( 'found at least one grouped chip with a color', false, 'no grouped chip matched' );
}

// ── CSS hooks present ──
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$check( 'CSS styles the group icon', false !== strpos( $css, '.eem-chart-cust-icon--group' ) );
$check( 'CSS styles the badge container', false !== strpos( $css, '.eem-occ-pill__badges' ) );

WP_CLI::log( "\n=== D2 group-location indicator smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'D2 group-location indicator smoke passed.' );
