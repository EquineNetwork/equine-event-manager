<?php
/**
 * C13.B.1 smoke — reservation select loads section config.
 *
 * The eem_create_order_reservation_meta AJAX returns which sections are enabled +
 * rate labels + dates for a chosen reservation; the JS reflects it onto the
 * section cards + rail. (Interactive pricing/steppers = C13.B.2.)
 *
 * Run: wp eval-file tests/smoke/c13b1-reservation-meta-smoke.php
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

$check( 'AJAX action registered', has_action( 'wp_ajax_eem_create_order_reservation_meta' ) !== false );

$_POST = $_REQUEST = array(
	'_wpnonce'       => wp_create_nonce( 'eem_create_order_customer_search' ),
	'reservation_id' => 3499,
);
try { ob_start(); EEM_Create_Order_Page::ajax_reservation_meta(); $raw = ob_get_clean(); }
catch ( Exception $e ) { $raw = ob_get_clean(); }
$resp = json_decode( (string) $raw, true );

$check( 'AJAX returns success', is_array( $resp ) && ! empty( $resp['success'] ), 'raw: ' . substr( (string) $raw, 0, 160 ) );
$data = ( is_array( $resp ) && isset( $resp['data'] ) ) ? $resp['data'] : array();
$check( 'returns the reservation title', ! empty( $data['title'] ) );
$check( 'returns a dates range', isset( $data['dates'] ) );
$check( 'returns all four section flags', isset( $data['sections']['stall'], $data['sections']['rv'], $data['sections']['addons'], $data['sections']['group'] ) );
$check( 'stall section carries enabled + rate label', isset( $data['sections']['stall']['enabled'] ) && false !== strpos( (string) ( $data['sections']['stall']['label'] ?? '' ), 'Stalls' ) );

// Bad reservation id is rejected.
$_POST['reservation_id'] = 0;
try { ob_start(); EEM_Create_Order_Page::ajax_reservation_meta(); $raw2 = ob_get_clean(); } catch ( Exception $e ) { $raw2 = ob_get_clean(); }
$resp2 = json_decode( (string) $raw2, true );
$check( 'invalid reservation id rejected', is_array( $resp2 ) && empty( $resp2['success'] ) );
$_POST = array();

// JS wiring.
$js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
// 2.7.25 — selecting a reservation now NAVIGATES to ?reservation_id=N so the
// real interactive embedded form loads (was a label-only AJAX stub that never
// produced qty/nights/pricing controls). Assert the navigate wiring.
$check( 'JS navigates to ?reservation_id on select change', false !== strpos( $js, "'create-order-reservation'" ) && false !== strpos( $js, "searchParams.set('reservation_id'" ) );
$check( 'JS updates section cards from the response', false !== strpos( $js, 'function coUpdateSection' ) );

WP_CLI::log( "\n=== C13.B.1 reservation-meta smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C13.B.1 reservation-meta smoke passed.' );
