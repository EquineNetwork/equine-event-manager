<?php
/**
 * C13.C.4b smoke — remove-discount-with-reason from Order Detail.
 *
 * Covers the modal render (only when a discount exists), the Remove button in
 * the Order Summary, the AJAX handler logic (reason required, successful removal
 * + Activity Log entry, no-discount guard), the handler registration, and the
 * JS/CSS wiring (canonical .eem-modal class names per the C7.X.20 lesson).
 *
 * The AJAX handler uses the catchable wp_send_json pattern (wp_die_ajax_handler
 * filter + DOING_AJAX) so its exits become catchable exceptions.
 *
 * Run: wp eval-file tests/smoke/c13c4b-remove-discount-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run via wp eval-file\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () {
	return static function ( $message ) { throw new Exception( is_scalar( $message ) ? (string) $message : 'wp_die' ); };
} );

/** Invoke the static AJAX handler, capturing the wp_send_json_* payload. */
$call = static function ( array $post ) {
	// check_ajax_referer reads the nonce from $_REQUEST — set both.
	$_POST    = $post;
	$_REQUEST = $post;
	ob_start();
	try {
		EEM_Order_Detail_Page::ajax_remove_discount();
	} catch ( Throwable $e ) { /* wp_die handler throws — expected */ }
	$out  = ob_get_clean();
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : array( 'raw' => $out );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admin ) ) { wp_set_current_user( (int) $admin[0] ); }

$order_key = wp_generate_password( 32, false );
EEM_Order_Adjustments_Repo::set_discount( $order_key, 'dollar', 10.0, 'First-time customer', 140.0 );

// --- 1. Modal renders only when a discount exists --------------------------
$page = new EEM_Order_Detail_Page();
$mref = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_remove_discount_modal' );
$mref->setAccessible( true );
ob_start(); $mref->invoke( $page, array( 'order_key' => $order_key ) ); $modal = (string) ob_get_clean();
$check( 'modal renders when discount present', str_contains( $modal, 'eem-order-remove-discount-modal' ) );
$check( 'modal carries the remove-discount action', str_contains( $modal, 'name="action" value="eem_order_remove_discount"' ) );
$check( 'modal carries the order_key', str_contains( $modal, 'value="' . $order_key . '"' ) );
$check( 'modal nonce field present', str_contains( $modal, '_eem_remove_discount_nonce' ) );
$check( 'modal reason field required', str_contains( $modal, 'name="reason"' ) && str_contains( $modal, 'required' ) );
$check( 'modal confirm button wired', str_contains( $modal, 'data-eem-action="order-remove-discount-confirm"' ) );

ob_start(); $mref->invoke( $page, array( 'order_key' => wp_generate_password( 32, false ) ) ); $no_modal = (string) ob_get_clean();
$check( 'modal NOT rendered when no discount', '' === trim( $no_modal ) );

// --- 2. Remove button appears in the Order Summary -------------------------
$sref = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_summary_card' );
$sref->setAccessible( true );
ob_start(); $sref->invoke( $page, array( 'order_key' => $order_key, 'stall_subtotal' => 90.0, 'fees' => 10.0, 'total' => 100.0 ) ); $summary = (string) ob_get_clean();
$check( 'summary discount block has Remove button', str_contains( $summary, 'data-eem-action="order-remove-discount-open"' ) );

// --- 3. Handler registration -----------------------------------------------
$check( 'AJAX action registered', has_action( 'wp_ajax_eem_order_remove_discount' ) !== false );
$check( 'handler method exists', method_exists( 'EEM_Order_Detail_Page', 'ajax_remove_discount' ) );

// --- 4. Handler: reason required -------------------------------------------
$nonce = wp_create_nonce( 'eem_remove_discount_' . $order_key );
$r = $call( array( 'order_key' => $order_key, '_eem_remove_discount_nonce' => $nonce, 'reason' => '   ' ) );
$check( 'empty reason rejected (success false)', isset( $r['success'] ) && false === $r['success'] );
$check( 'reason-required code returned', isset( $r['data']['code'] ) && 'reason_required' === $r['data']['code'] );
$check( 'discount still present after rejected removal', null !== EEM_Order_Adjustments_Repo::get_discount( $order_key ) );

// --- 5. Handler: successful removal + activity log -------------------------
$nonce = wp_create_nonce( 'eem_remove_discount_' . $order_key );
$r = $call( array( 'order_key' => $order_key, '_eem_remove_discount_nonce' => $nonce, 'reason' => 'Applied in error' ) );
$check( 'removal success', isset( $r['success'] ) && true === $r['success'] );
$check( 'requires_reload returned', isset( $r['data']['requires_reload'] ) && true === $r['data']['requires_reload'] );
$check( 'discount gone after removal', null === EEM_Order_Adjustments_Repo::get_discount( $order_key ) );

$log = EEM_Activity_Log::get_for_order_key( $order_key );
$logged = false;
foreach ( (array) $log as $e ) {
	if ( isset( $e['event_type'] ) && 'order_discount_removed' === $e['event_type'] ) {
		$logged = isset( $e['payload']['reason'] ) && 'Applied in error' === $e['payload']['reason'];
		break;
	}
}
$check( 'order_discount_removed logged with reason', $logged );

// --- 6. Handler: no discount to remove -------------------------------------
$nonce = wp_create_nonce( 'eem_remove_discount_' . $order_key );
$r = $call( array( 'order_key' => $order_key, '_eem_remove_discount_nonce' => $nonce, 'reason' => 'Again' ) );
$check( 'no-discount guard rejects', isset( $r['success'] ) && false === $r['success'] && isset( $r['data']['code'] ) && 'no_discount' === $r['data']['code'] );

// --- 7. JS + CSS wiring ----------------------------------------------------
$base = dirname( __DIR__, 2 );
$js   = (string) file_get_contents( $base . '/assets/js/admin.js' );
$css  = (string) file_get_contents( $base . '/assets/css/admin.css' );
foreach ( array( 'openRemoveDiscountModal', 'closeRemoveDiscountModal', 'submitRemoveDiscountForm' ) as $fn ) {
	$check( "JS defines {$fn}()", (bool) preg_match( '/function\s+' . $fn . '\s*\(/', $js ) );
}
$check( 'JS maps order-remove-discount-open', str_contains( $js, "'order-remove-discount-open'" ) );
$check( 'JS maps order-remove-discount-confirm', str_contains( $js, "'order-remove-discount-confirm'" ) );
// Canonical modal classes exist in admin.css (C7.X.20 — invisible-modal guard).
foreach ( array( '.eem-modal', '.eem-modal-card', '.eem-modal-head', '.eem-modal-body', '.eem-modal-foot' ) as $cls ) {
	$check( "admin.css defines {$cls}", str_contains( $css, $cls . ' ' ) || str_contains( $css, $cls . '{' ) || str_contains( $css, $cls . ',' ) || str_contains( $css, $cls . '.' ) );
}
$check( 'CSS styles the discount remove button', str_contains( $css, '.eem-order-summary__discount-remove' ) );

EEM_Order_Adjustments_Repo::delete_for_order( $order_key );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
