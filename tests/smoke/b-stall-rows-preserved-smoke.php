<?php
/**
 * Scenario B data-integrity — stall rows survive a save, including back-to-back.
 *
 * Regression guard for the bug where switching a reservation with a back-to-back
 * row into Numbered+Quantity ("simple range") mode and saving blanked that row's
 * range: the JS force-converted every row to one-sided, but a back-to-back row's
 * stall numbers live in the top/bot fields, which one-sided ignores — so the
 * 112-135 range was lost on save. Fix: simple mode no longer force-converts layout.
 *
 * Drives the real ajax_save with a 3-row layout and asserts every row + its data
 * round-trips. Throwaway reservation; fully isolated.
 *
 * Run: wp eval-file tests/smoke/b-stall-rows-preserved-smoke.php
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

$rid = wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'rows-preserved fixture' ) );

// The 3-row layout the browser collector would post (one-sided + back-to-back + one-sided).
$rows_post = array(
	0 => array( 'name' => 'Red Barn Row A', 'layout' => 'one-sided', 'first' => '100', 'last' => '111', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
	1 => array( 'name' => 'Red Barn Row B', 'layout' => 'back-to-back', 'first' => '', 'last' => '', 'top_first' => '112', 'top_last' => '123', 'bot_first' => '124', 'bot_last' => '135' ),
	2 => array( 'name' => 'Yellow Barn Row A', 'layout' => 'one-sided', 'first' => 'Y1', 'last' => 'Y12', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
);
$_POST = $_REQUEST = array(
	'_eem_editor_nonce'        => wp_create_nonce( 'eem_reservation_editor' ),
	'reservation_id'           => $rid,
	'save_kind'                => 'save_draft',
	'en_reservation'           => array( 'event_source' => 'native', 'stalls_enabled' => '1' ),
	'eem_stall_rows'           => $rows_post,
	'stall_inventory_type'     => 'numbered',
	'stall_customer_selection' => 'quantity', // simple-range mode
	'stall_selection_mode'     => 'quantity',
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); ob_get_clean(); } catch ( Exception $e ) { ob_get_clean(); }

// #55: stall rows persist to the eem_reservation_config table now (mig-016),
// not _en_stall_rows post-meta. Read from the config the save writes.
EEM_Reservation_Config::flush_cache( $rid );
$after = (array) EEM_Reservation_Config::for( $rid )->get( 'stall_rows', array() );
$check( 'all 3 rows persisted (none dropped)', 3 === count( $after ), 'got ' . count( $after ) );

$byname = array();
foreach ( $after as $r ) { $byname[ $r['name'] ?? '' ] = $r; }
$check( 'Row A range intact (100–111)', ( $byname['Red Barn Row A']['first'] ?? '' ) === '100' && ( $byname['Red Barn Row A']['last'] ?? '' ) === '111' );
$rb = $byname['Red Barn Row B'] ?? array();
$check( 'Row B stays back-to-back', ( $rb['layout'] ?? '' ) === 'back-to-back', 'layout=' . ( $rb['layout'] ?? '?' ) );
$check( 'Row B top range intact (112–123)', ( $rb['top_first'] ?? '' ) === '112' && ( $rb['top_last'] ?? '' ) === '123' );
$check( 'Row B bottom range intact (124–135)', ( $rb['bot_first'] ?? '' ) === '124' && ( $rb['bot_last'] ?? '' ) === '135' );
$check( 'Yellow Barn range intact (Y1–Y12)', ( $byname['Yellow Barn Row A']['first'] ?? '' ) === 'Y1' && ( $byname['Yellow Barn Row A']['last'] ?? '' ) === 'Y12' );

// Source guard: simple mode must NOT force layout to one-sided.
$js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
if ( preg_match( '/function applyStallRowsSimpleMode\(\).*?\n\}/s', $js, $fn ) ) {
	$check( 'applyStallRowsSimpleMode does not force layout to one-sided', false === strpos( $fn[0], "layoutSel.value = 'one-sided'" ) );
} else {
	$check( 'found applyStallRowsSimpleMode', false, 'function not matched' );
}

wp_delete_post( $rid, true );
WP_CLI::log( 'cleaned up #' . $rid );

WP_CLI::log( "\n=== B stall-rows-preserved smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'B stall-rows-preserved smoke passed.' );
