<?php
/**
 * Regression smoke — the all-or-nothing "finish your picks" notice renders
 * kind-aware for BOTH the stall map and the RV map (Whitney 2026-06-29).
 *
 * render_stall_map_picker() is shared by stalls and RV. The incomplete-notice it
 * emits must carry data-eem-unit-field / data-eem-qty-field matching the picker's
 * kind so the picker-agnostic JS (syncStallPickWarning) and the server gate judge
 * each map against its own units + quantity. This asserts the rendered chrome so a
 * future refactor can't silently drop the RV variant (which has no live fixture).
 *
 * Run: wp eval-file tests/smoke/map-pick-incomplete-notice-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$sc  = new EEM_Shortcodes();
$ref = new ReflectionClass( $sc );
$m   = $ref->getMethod( 'render_stall_map_picker' );
$m->setAccessible( true );

$snapshot = array( 'barns' => array( array(
	'name' => 'Test Barn',
	'grid' => array( array(
		array( 'type' => 'stall', 'label' => '1' ),
		array( 'type' => 'stall', 'label' => '2' ),
	) ),
) ) );

$render = function ( array $opts ) use ( $m, $sc, $snapshot ) {
	ob_start();
	$m->invoke( $sc, $snapshot, array(), array(), false, 2, $opts );
	return ob_get_clean();
};

// ── Stall picker: notice keyed to stall fields ──
$stall_html = $render( array( 'unit_field' => 'preferred_stall_units', 'qty_field' => 'stall_qty', 'noun_plural' => 'stalls' ) );
$check( 'stall picker emits the incomplete notice', false !== strpos( $stall_html, 'data-eem-stall-incomplete' ) );
$check( 'stall notice carries data-eem-unit-field="preferred_stall_units"', false !== strpos( $stall_html, 'data-eem-unit-field="preferred_stall_units"' ) );
$check( 'stall notice carries data-eem-qty-field="stall_qty"', false !== strpos( $stall_html, 'data-eem-qty-field="stall_qty"' ) );
$check( 'stall notice uses the "stalls" noun', false !== strpos( $stall_html, 'all your stalls' ) );

// ── RV picker: SAME function, notice keyed to RV fields ──
$rv_html = $render( array( 'unit_field' => 'preferred_rv_lots', 'qty_field' => 'rv_qty', 'noun_plural' => 'lots', 'zone_qualified' => true, 'prefix' => '' ) );
$check( 'RV picker emits the incomplete notice', false !== strpos( $rv_html, 'data-eem-stall-incomplete' ) );
$check( 'RV notice carries data-eem-unit-field="preferred_rv_lots"', false !== strpos( $rv_html, 'data-eem-unit-field="preferred_rv_lots"' ) );
$check( 'RV notice carries data-eem-qty-field="rv_qty"', false !== strpos( $rv_html, 'data-eem-qty-field="rv_qty"' ) );
$check( 'RV notice uses the "lots" noun', false !== strpos( $rv_html, 'all your lots' ) );

// ── Server gate symmetry: both RV branch + stall branch present in source ──
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$check( 'server validates partial STALL picks', false !== strpos( $src, 'stalls on the map' ) );
$check( 'server validates partial RV picks', false !== strpos( $src, 'RV spaces on the map' ) );
$check( 'RV gate reads resolve_rv_pair selection_mode', false !== strpos( $src, "resolve_rv_pair( \$eem_pick_reservation_id )['selection_mode']" ) );

WP_CLI::log( "\n=== Map-pick incomplete-notice smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Map-pick incomplete-notice smoke passed.' );
