<?php
/**
 * Edit-Dates behavioral round-trip smoke (F5 / task #10).
 *
 * Drives the REAL admin Edit-Dates AJAX handler (EEM_Admin::handle_ajax_edit_dates)
 * end to end against a seeded paid order and asserts penny-exact recalculation in
 * both directions:
 *   - LENGTHEN (add nights, money_action=charge) → subtotal/fee/tax/total rise by
 *     the per-night delta, the paid row re-opens to partially_paid, and the order
 *     shows Balance Due = grand_total − amount_paid.
 *   - SHORTEN (remove nights, money_action=reduce) → those values fall by the
 *     delta and, because amount_paid is untouched, the order shows
 *     Refund Owed = amount_paid − grand_total.
 * Fee (percentage) and tax both move with the night delta, exactly per #10.
 *
 * In-process AJAX pattern: override wp_die to throw so the handler's
 * wp_send_json_* doesn't exit the smoke, then read the buffered JSON.
 *
 * Run via: wp eval-file tests/smoke/edit-dates-balance-refund-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;

$pass = 0; $fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.005; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Admin' ) || ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "  FAIL — core classes missing\n0 passed, 1 failed\n";
	return;
}

wp_set_current_user( 1 );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new Exception( 'wp_die' ); }; } );

$admin = new EEM_Admin();
$repo  = new EEM_Orders_Repository();
$stall = $wpdb->prefix . 'eem_stall_reservations';
$email = 'edit-dates-f5@example.test';

// --- Global 4% convenience fee (the Add-Items / Edit-Dates pricer reads it) ---
$fee_before = EEM_Settings_Repo::get_convenience_fee();
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'percentage', 'value' => 4.0 ) );

// --- Reservation with a $40 nightly stall rate -------------------------------
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'EditDates F5', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'     => 1,
	'stall_nightly_rate' => 40.0,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

// --- Seed a PAID stall order: 3 stalls × 4 nights @ $40, 4% fee, 7% tax ------
$token = wp_generate_uuid4();
$wpdb->delete( $stall, array( 'email' => $email ) );
$wpdb->insert( $stall, array(
	'event_source'    => 'native',
	'event_id'        => 0,
	'customer_name'   => 'Edit Dates F5',
	'email'           => $email,
	'phone'           => '555-0150',
	'stall_qty'       => 3,
	'stay_type'       => 'nightly',
	'arrival_date'    => '2026-08-19',
	'departure_date'  => '2026-08-23', // 4 nights
	'unit_price'      => '40.00',
	'subtotal'        => '480.00',     // 3 × 40 × 4
	'convenience_fee' => '19.20',      // 4% of 480
	'tax'             => '33.60',      // 7% of 480
	'tax_rate'        => '7.000',
	'total'           => '532.80',     // 480 + 19.20 + 33.60
	'amount_paid'     => '532.80',
	'payment_status'  => 'paid',
	'payment_gateway' => 'manual',
	'order_number'    => '90810',
	'transaction_id'  => 'seed_f5_stall',
	'reservation_id'  => $rid,
	'notes'           => "Reservation setup ID: {$rid}\nSubmission token: {$token}",
	'created_at'      => current_time( 'mysql' ),
) );
$order_key = md5( $token );

$dispatch = static function ( $arrival, $departure, $money_action ) use ( $admin, $order_key ) {
	$_POST = $_REQUEST = array(
		'order_key'             => $order_key,
		'_eem_edit_dates_nonce' => wp_create_nonce( 'eem_edit_dates_' . $order_key ),
		'component'             => 'stall',
		'arrival'               => $arrival,
		'departure'             => $departure,
		'money_action'          => $money_action,
	);
	$raw = '';
	try { ob_start(); $admin->handle_ajax_edit_dates(); $raw = ob_get_clean(); }
	catch ( Exception $e ) { $raw = ob_get_clean(); }
	$_POST = $_REQUEST = array();
	return json_decode( (string) $raw, true );
};
$row_now = static function () use ( $wpdb, $stall, $email ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$stall} WHERE email = %s", $email ), ARRAY_A );
};

// Sanity: the order resolves with a stall component before we edit it.
$order0 = $repo->get_order( $order_key );
$chk( is_array( $order0 ) && ! empty( $order0['components'] ), 'seeded order resolves by order_key with components' );

// === LENGTHEN: 4 → 6 nights (delta +2), charge ===============================
$resp = $dispatch( '2026-08-19', '2026-08-25', 'charge' );
$chk( is_array( $resp ) && ! empty( $resp['success'] ), 'lengthen: handler returns success' );
$r = $row_now();
$chk( $approx( $r['subtotal'], 720.00 ), 'lengthen: subtotal 480 → 720 (+3×40×2)' );
$chk( $approx( $r['convenience_fee'], 28.80 ), 'lengthen: 4% fee 19.20 → 28.80' );
$chk( $approx( $r['tax'], 50.40 ), 'lengthen: 7% tax 33.60 → 50.40 (tax on night delta)' );
$chk( $approx( $r['total'], 799.20 ), 'lengthen: total 532.80 → 799.20' );
$chk( $approx( $r['amount_paid'], 532.80 ), 'lengthen: amount_paid untouched (532.80)' );
$chk( 'partially_paid' === $r['payment_status'], 'lengthen: paid row re-opens to partially_paid' );
$balance_due = max( 0.0, round( (float) $r['total'] - (float) $r['amount_paid'], 2 ) );
$chk( $approx( $balance_due, 266.40 ), 'lengthen: Balance Due = grand_total − amount_paid = 266.40' );

// === SHORTEN: 6 → 2 nights (delta −4), reduce ================================
$resp2 = $dispatch( '2026-08-19', '2026-08-21', 'reduce' );
$chk( is_array( $resp2 ) && ! empty( $resp2['success'] ), 'shorten: handler returns success' );
$r2 = $row_now();
$chk( $approx( $r2['subtotal'], 240.00 ), 'shorten: subtotal 720 → 240 (−3×40×4)' );
$chk( $approx( $r2['convenience_fee'], 9.60 ), 'shorten: 4% fee 28.80 → 9.60' );
$chk( $approx( $r2['tax'], 16.80 ), 'shorten: 7% tax 50.40 → 16.80 (tax on night delta)' );
$chk( $approx( $r2['total'], 266.40 ), 'shorten: total → 266.40' );
$chk( $approx( $r2['amount_paid'], 532.80 ), 'shorten: amount_paid still 532.80 (no auto-refund)' );
$refund_owed = max( 0.0, round( (float) $r2['amount_paid'] - (float) $r2['total'], 2 ) );
$chk( $approx( $refund_owed, 266.40 ), 'shorten: Refund Owed = amount_paid − grand_total = 266.40' );

// --- Cleanup -----------------------------------------------------------------
$wpdb->delete( $stall, array( 'email' => $email ) );
wp_delete_post( $rid, true );
EEM_Reservation_Config::flush_cache( $rid );
if ( is_array( $fee_before ) ) {
	EEM_Settings_Repo::update_convenience_fee( $fee_before );
} else {
	EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 0, 'type' => 'percentage', 'value' => 0.0 ) );
}

echo "\n$pass passed, $fail failed\n";
