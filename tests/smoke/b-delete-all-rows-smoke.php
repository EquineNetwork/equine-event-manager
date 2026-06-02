<?php
/**
 * Scenario B — deleting every stall row must persist as zero rows.
 *
 * Regression guard for the bug where the save handler only updated _en_stall_rows
 * when an eem_stall_rows field was posted. Deleting all rows posts no such field,
 * so the isset() guard failed and the old rows survived ("rows keep coming back").
 * Fix: an always-present eem_stall_rows_present sentinel lets the handler tell
 * "deleted them all" (clear) apart from "section not rendered" (leave untouched).
 *
 * Run: wp eval-file tests/smoke/b-delete-all-rows-smoke.php
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
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new Exception( 'die' ); }; } );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

$rid = wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'delete-all fixture' ) );
$seed = array(
	array( 'name' => 'Row A', 'layout' => 'one-sided', 'first' => '100', 'last' => '111', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
	array( 'name' => 'Row B', 'layout' => 'one-sided', 'first' => '200', 'last' => '211', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
);

$nonce = wp_create_nonce( 'eem_reservation_editor' );
$save = function ( array $extra ) use ( $rid, $nonce ) {
	$_POST = $_REQUEST = array_merge( array(
		'_eem_editor_nonce' => $nonce,
		'reservation_id'    => $rid,
		'save_kind'         => 'save_draft',
		'en_reservation'    => array( 'event_source' => 'native', 'stalls_enabled' => '1' ),
	), $extra );
	try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); ob_get_clean(); } catch ( Exception $e ) { ob_get_clean(); }
	return (array) get_post_meta( $rid, '_en_stall_rows', true );
};

// 1) Normal save with 2 rows persists 2.
update_post_meta( $rid, '_en_stall_rows', $seed );
$after = $save( array( 'eem_stall_rows_present' => '1', 'eem_stall_rows' => $seed ) );
$check( 'normal save keeps both rows', 2 === count( $after ), 'got ' . count( $after ) );

// 2) THE FIX: sentinel present, no eem_stall_rows (deleted all) → cleared to zero.
$after = $save( array( 'eem_stall_rows_present' => '1' ) );
$check( 'delete-all (sentinel, no rows) clears to zero', 0 === count( $after ), 'got ' . count( $after ) );

// 3) Gated section (no sentinel, no rows) must NOT wipe existing rows.
update_post_meta( $rid, '_en_stall_rows', $seed );
$after = $save( array() ); // neither sentinel nor rows posted
$check( 'gated save (no sentinel) leaves rows untouched', 2 === count( $after ), 'got ' . count( $after ) );

// Source guards: sentinel emitted + handler honors it.
$tpl = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php' );
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$check( 'stall template emits the sentinel', false !== strpos( $tpl, 'name="eem_stall_rows_present"' ) );
$check( 'save handler checks the sentinel', false !== strpos( $src, "isset( \$_POST['eem_stall_rows_present'] )" ) );
$check( 'RV handler checks its sentinel', false !== strpos( $src, "isset( \$_POST['eem_rv_zones_present'] )" ) );

wp_delete_post( $rid, true );
WP_CLI::log( 'cleaned up #' . $rid );

WP_CLI::log( "\n=== B delete-all-rows smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'B delete-all-rows smoke passed.' );
