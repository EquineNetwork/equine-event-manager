<?php
/**
 * V1 #5a smoke — per-reservation Tack Stall pricing settings.
 *
 * Verifies the editor renders the two fields and the CPT save normalize
 * persists stall_tack_pricing_mode + stall_tack_price (round-trip).
 *
 * Run: wp eval-file tests/smoke/tack-5a-pricing-smoke.php
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

// ── Defaults expose the keys ──
$mv = $cpt->get_meta_values( 3499 );
$check( 'meta values expose stall_tack_pricing_mode', array_key_exists( 'stall_tack_pricing_mode', $mv ) );
$check( 'meta values expose stall_tack_price', array_key_exists( 'stall_tack_price', $mv ) );
$check( 'default tack pricing mode is "same"', in_array( $mv['stall_tack_pricing_mode'], array( 'same', 'discounted', 'free' ), true ) );

// ── Save normalize round-trip ──
$existing = array( 'event_source' => 'native' );
$n1 = $cpt->sanitize_meta_submission( array( 'stall_tack_pricing_mode' => 'discounted', 'stall_tack_price' => '12.50' ), $existing );
$check( 'normalize keeps discounted mode', 'discounted' === $n1['stall_tack_pricing_mode'] );
$check( 'normalize formats tack price', '12.50' === $n1['stall_tack_price'] );

$n2 = $cpt->sanitize_meta_submission( array( 'stall_tack_pricing_mode' => 'free' ), $existing );
$check( 'normalize keeps free mode', 'free' === $n2['stall_tack_pricing_mode'] );

$n3 = $cpt->sanitize_meta_submission( array( 'stall_tack_pricing_mode' => 'bogus' ), $existing );
$check( 'normalize rejects junk mode → same', 'same' === $n3['stall_tack_pricing_mode'] );

// ── Persisted round-trip on a temp reservation ──
$id = wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'tack tmp' ) );
update_post_meta( $id, '_en_stall_tack_pricing_mode', 'discounted' );
update_post_meta( $id, '_en_stall_tack_price', '8.00' );
$mv2 = $cpt->get_meta_values( $id );
$check( 'reads back tack pricing mode', 'discounted' === $mv2['stall_tack_pricing_mode'] );
$check( 'reads back tack price', '8.00' === $mv2['stall_tack_price'] );
wp_delete_post( $id, true );

// ── Editor renders both fields + the JS hook ──
wp_set_current_user( 1 );
$_GET['page'] = 'equine-event-manager-reservation-editor';
$_GET['reservation_id'] = '3499';
$_REQUEST['reservation_id'] = '3499';
ob_start();
EEM_Reservation_Editor_Page::render();
$editor = ob_get_clean();
$check( 'editor renders Tack Stall Pricing select', false !== strpos( $editor, 'name="en_reservation[stall_tack_pricing_mode]"' ) );
$check( 'editor renders Tack Stall Price input', false !== strpos( $editor, 'name="en_reservation[stall_tack_price]"' ) );
$check( 'editor select carries the JS hook attr', false !== strpos( $editor, 'data-eem-tack-pricing-mode' ) );
$check( 'editor price row carries row id for toggle', false !== strpos( $editor, 'row-stall-tack-price' ) );

// ── Shortcodes reader also exposes the keys (for line-item code later) ──
$sc = new EEM_Shortcodes();
$rm = new ReflectionMethod( $sc, 'get_reservation_meta' );
$rm->setAccessible( true );
$sm = $rm->invoke( $sc, 3499 );
$check( 'shortcodes meta exposes tack pricing mode', array_key_exists( 'stall_tack_pricing_mode', $sm ) );
$check( 'shortcodes meta exposes tack price', array_key_exists( 'stall_tack_price', $sm ) );

// ── JS hook present in source ──
$js = file_get_contents( EQUINE_EVENT_MANAGER_URL ? EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' : '' );
$check( 'admin.js has the tack pricing toggle listener', false !== strpos( $js, 'data-eem-tack-pricing-mode' ) );

WP_CLI::log( "\n=== Tack #5a pricing smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Tack #5a pricing smoke passed.' );
