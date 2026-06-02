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
$cfg  = $priv( 'get_stall_chart_config' )->invoke( $admin, 3499 );
$grid = $priv( 'build_stall_chart_grid' )->invoke( $admin, 3499, $cfg );
ob_start();
$priv( 'render_stall_chart_matrix_table' )->invoke( $admin, $grid['stall_rows'], $grid['date_columns'], 'Stall', 'Block' );
$loc_html = ob_get_clean();
$check( 'By Location renders a Group badge', false !== strpos( $loc_html, 'eem-occ-badge--group' ) );
$check( 'Group badge carries the "Group" label', (bool) preg_match( '/eem-occ-badge--group[^>]*>Group</', $loc_html ) );
$check( 'By Location sets the --eem-group-color var', false !== strpos( $loc_html, '--eem-group-color:#' ) );
$check( 'By Location pill title carries the group label', false !== strpos( $loc_html, 'Group: ' ) );

// ── By Customer roster: chip reuses the same color + a dot ──
$rows = $priv( 'build_stall_chart_rows' )->invoke( $admin, 3499, $cfg );
ob_start();
$priv( 'render_stall_chart_order_count_table' )->invoke( $admin, $rows, $grid['date_columns'] );
$cust_html = ob_get_clean();
$check( 'By Customer chip carries a colored dot', false !== strpos( $cust_html, 'eem-chart-cust-group__dot' ) );
$check( 'By Customer chip sets --eem-group-color', false !== strpos( $cust_html, '--eem-group-color:#' ) );

// ── Cross-view color agreement: a group present in both views uses the SAME color ──
if ( preg_match( '/data-group-name="([^"]+)"\s+style="--eem-group-color:(#[0-9a-f]{6})/i', $loc_html, $lm ) ) {
	$grp = html_entity_decode( $lm[1], ENT_QUOTES, 'UTF-8' );
	$expected = $gc->invoke( $admin, $grp );
	$check( 'By Location color matches group_color_for', strtolower( $lm[2] ) === strtolower( $expected ), "grp={$grp}" );
} else {
	$check( 'found at least one grouped pill with a color', false, 'no grouped pill matched' );
}

// ── CSS hooks present ──
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$check( 'CSS styles the group badge', false !== strpos( $css, '.eem-occ-badge--group' ) );
$check( 'CSS styles the badge container', false !== strpos( $css, '.eem-occ-pill__badges' ) );

WP_CLI::log( "\n=== D2 group-location indicator smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'D2 group-location indicator smoke passed.' );
