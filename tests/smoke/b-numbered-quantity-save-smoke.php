<?php
/**
 * Scenario B save round-trip — Numbered + Quantity must persist.
 *
 * Regression guard for the bug where Numbered+Quantity silently collapsed to
 * Quantity-only on save: the JS collector never sent the two bare-named mode
 * inputs (stall_inventory_type / stall_customer_selection), so the server fell
 * back to deriving from the LOSSY legacy stall_selection_mode (both quantity-only
 * AND numbered+quantity map to 'quantity').
 *
 * Drives the real EEM_Reservation_Editor_Page::ajax_save() the way the corrected
 * collector now posts, reading persisted meta back. Uses a throwaway reservation
 * so it's fully isolated.
 *
 * Run: wp eval-file tests/smoke/b-numbered-quantity-save-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $extra = '' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) ); }
};

wp_set_current_user( 1 );

// AJAX-test plumbing: make wp_send_json_* throw a catchable Exception instead of
// die()-ing the CLI process (same shape as c7c2-1).
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new Exception( 'eem_test_die' ); };
} );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

// Throwaway reservation (no toggles enabled → no cross-field validation trips).
$rid = wp_insert_post( array(
	'post_type'   => EEM_Reservations_CPT::POST_TYPE,
	'post_status' => 'draft',
	'post_title'  => 'B save smoke fixture',
) );
if ( ! $rid || is_wp_error( $rid ) ) { WP_CLI::error( 'could not create fixture reservation' ); return; }

// Self-cleanup: guarantee removal even if an assertion below fatals before the
// end-of-file delete, so "B save smoke fixture" posts never leak.
register_shutdown_function( static function () use ( $rid ) {
	if ( $rid && ! is_wp_error( $rid ) ) {
		wp_delete_post( (int) $rid, true );
	}
} );

$nonce = wp_create_nonce( 'eem_reservation_editor' );
$do_save = function ( array $mode_fields ) use ( $rid, $nonce ) {
	$_POST = $_REQUEST = array_merge( array(
		'_eem_editor_nonce' => $nonce,
		'reservation_id'    => $rid,
		'save_kind'         => 'save_draft',
		'en_reservation'    => array( 'event_source' => 'native', 'stalls_enabled' => '1' ),
	), $mode_fields );
	try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $raw = ob_get_clean(); }
	catch ( Exception $e ) { $raw = ob_get_clean(); }
	return json_decode( (string) $raw, true );
};

// ── THE FIX: corrected collector posts both bare fields → numbered+quantity persists ──
$resp = $do_save( array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'quantity',
	'stall_selection_mode'     => 'quantity', // legacy derived value the JS also sends
) );
$check( 'AJAX save succeeded', is_array( $resp ) && ! empty( $resp['success'] ), 'raw: ' . wp_json_encode( $resp ) );
$check( 'inventory_type persisted as numbered', 'numbered' === get_post_meta( $rid, '_en_stall_inventory_type', true ), 'got: ' . get_post_meta( $rid, '_en_stall_inventory_type', true ) );
$check( 'customer_selection persisted as quantity', 'quantity' === get_post_meta( $rid, '_en_stall_customer_selection', true ), 'got: ' . get_post_meta( $rid, '_en_stall_customer_selection', true ) );
$check( 'legacy mode derived as quantity', 'quantity' === get_post_meta( $rid, '_en_stall_selection_mode', true ) );

// ── Pick mode round-trips too (numbered + pick_layout → exact_map) ──
$resp_pick = $do_save( array(
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'pick_layout',
	'stall_selection_mode'     => 'exact_map',
) );
$check( 'pick mode: customer_selection persisted as pick_layout', 'pick_layout' === get_post_meta( $rid, '_en_stall_customer_selection', true ) );
$check( 'pick mode: legacy derived as exact_map', 'exact_map' === get_post_meta( $rid, '_en_stall_selection_mode', true ) );

// ── THE OLD BUG: legacy-only post (no bare fields) collapses to quantity_only ──
// Documents WHY the two fields must be collected — the legacy value alone can't
// distinguish numbered+quantity from quantity-only.
$do_save( array( 'stall_selection_mode' => 'quantity' ) );
$check( 'legacy-only fallback collapses to quantity_only (documents the bug)', 'quantity_only' === get_post_meta( $rid, '_en_stall_inventory_type', true ) );

// ── Source guard: collector now includes both bare-named mode inputs ──
$js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$check( 'JS collector includes stall_inventory_type', false !== strpos( $js, 'input[name="stall_inventory_type"]' ) );
$check( 'JS collector includes stall_customer_selection', false !== strpos( $js, 'input[name="stall_customer_selection"]' ) );

wp_delete_post( $rid, true );
WP_CLI::log( 'Deleted fixture reservation #' . $rid );

WP_CLI::log( "\n=== B numbered-quantity save smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'B numbered-quantity save smoke passed.' );
