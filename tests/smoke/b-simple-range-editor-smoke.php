<?php
/**
 * Scenario B refinement smoke — "simple range" editor mode.
 *
 * Numbered + Quantity collapses the Stall Row Builder to plain First/Last ranges
 * (no Layout dropdown, no back-to-back, no preview); Numbered + Pick-from-layout
 * keeps the full builder. Verifies the server-rendered markup + copy for each, and
 * that the JS + CSS hooks that drive the live toggle are present.
 *
 * Run: wp eval-file tests/smoke/b-simple-range-editor-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$cpt = new EEM_Reservations_CPT();

// Build a base $data from a real reservation so every key the template reads exists.
$base = $cpt->get_meta_values( 3499 );
$reservations_cpt = $cpt;

$render = function ( array $overrides ) use ( $base, $reservations_cpt ) {
	$data = array_merge( $base, $overrides );
	ob_start();
	include EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php';
	return (string) ob_get_clean();
};

// ── Simple-range mode: Numbered + Quantity ──
$simple = $render( array( 'stall_inventory_type' => 'numbered', 'stall_customer_selection' => 'quantity' ) );
$check( 'simple mode adds eem-stall-rows--simple class', false !== strpos( $simple, 'eem-stall-rows--simple' ) );
$check( 'simple mode label is "Stall Number Ranges"', false !== strpos( $simple, 'Stall Number Ranges' ) );
$check( 'simple mode button says "Add Range"', false !== strpos( $simple, 'Add Range' ) );
$check( 'simple mode hint mentions a block of consecutive stall numbers', false !== stripos( $simple, 'block of consecutive stall numbers' ) );
$check( 'simple mode still renders the row builder list', false !== strpos( $simple, 'id="eem-stall-row-builder-list"' ) );

// ── Full builder: Numbered + Pick-from-layout ──
$full = $render( array( 'stall_inventory_type' => 'numbered', 'stall_customer_selection' => 'pick_layout' ) );
$check( 'pick mode does NOT add the simple class', false === strpos( $full, 'eem-stall-rows--simple' ) );
$check( 'pick mode label is "Stall Rows"', false !== strpos( $full, '>Stall Rows<' ) );
$check( 'pick mode button says "Add Row"', false !== strpos( $full, 'Add Row' ) );
$check( 'pick mode keeps the Layout dropdown markup', false !== strpos( $full, 'data-eem-input-action="stall-row-layout"' ) );

// ── JS + CSS hooks ──
$js  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$check( 'JS defines applyStallRowsSimpleMode', false !== strpos( $js, 'function applyStallRowsSimpleMode' ) );
$check( 'inventory-type toggle calls it', false !== strpos( $js, 'applyStallRowsSimpleMode();' ) );
$check( 'CSS hides layout + preview in simple mode', false !== strpos( $css, '.eem-stall-rows--simple .eem-row-card-field-layout' ) );
$check( 'preview block carries the eem-row-card-preview wrapper class', false !== strpos( $simple, 'eem-row-card-preview' ) );

WP_CLI::log( "\n=== B simple-range editor smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'B simple-range editor smoke passed.' );
