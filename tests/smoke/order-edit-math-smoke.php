<?php
/**
 * Order-edit (back-end) math smoke — validates the pricing used when an admin
 * ADDS items to an existing order from the Order Detail page. The Add-Items flow
 * prices base-rate quantity additions via EEM_Shortcodes::price_base_rate_addition
 * (rate × qty × nights, plus the reservation's convenience-fee + tax config the
 * caller folds into the order). Catches regressions in the admin-side add/edit
 * money path — distinct from the customer checkout calculator (covered by
 * order-totals-math-smoke.php).
 *
 * Run via: wp eval-file tests/smoke/order-edit-math-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0;
$fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.005; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

$sc = new EEM_Shortcodes();

// Seed a reservation whose config carries stall + RV nightly rates. The
// convenience fee is now GLOBAL (ROADMAP v1 #8): the Add-Items pricer reads it
// from Settings, not per-reservation config.
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'OrderEditMath', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'          => 1,
	'rv_enabled'              => 1,
	'stall_nightly_rate'      => 40.0,
	'rv_nightly_rate'         => 55.0,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

// Global 4% percentage convenience fee.
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'percentage', 'value' => 4.0 ) );

// ── Add 3 stalls × 4 nights @ $40 ───────────────────────────────────────────
$add_stall = $sc->price_base_rate_addition( $rid, 'stall', 3, 'nightly', '2026-08-19', '2026-08-23' );
$chk( $approx( $add_stall['unit_price'], 40.0 ), 'add-items: stall unit price = $40 (from config)' );
$chk( $add_stall['nights'] === 4, 'add-items: stall nights = 4' );
$chk( $approx( $add_stall['subtotal'], 480.0 ), 'add-items: stall add subtotal = 3 × $40 × 4 = $480' );
$chk( $add_stall['fee_enabled'] === true && 'percentage' === $add_stall['fee_type'] && $approx( $add_stall['fee_value'], 4.0 ), 'add-items: convenience-fee config carried (4% percentage)' );

// ── Add 2 RV lots × 3 nights @ $55 ──────────────────────────────────────────
$add_rv = $sc->price_base_rate_addition( $rid, 'rv', 2, 'nightly', '2026-08-19', '2026-08-22' );
$chk( $approx( $add_rv['unit_price'], 55.0 ), 'add-items: RV unit price = $55 (from config)' );
$chk( $add_rv['nights'] === 3, 'add-items: RV nights = 3' );
$chk( $approx( $add_rv['subtotal'], 330.0 ), 'add-items: RV add subtotal = 2 × $55 × 3 = $330' );

// ── Fee derived from an addition (what the order-edit handler applies) ───────
// 4% convenience fee on the $480 stall addition = $19.20.
$expected_fee = round( $add_stall['subtotal'] * ( $add_stall['fee_value'] / 100 ), 2 );
$chk( $approx( $expected_fee, 19.20 ), 'add-items: 4% fee on $480 stall addition = $19.20' );

// ── Zero / guard cases ──────────────────────────────────────────────────────
$add_zero = $sc->price_base_rate_addition( $rid, 'stall', 0, 'nightly', '2026-08-19', '2026-08-23' );
$chk( $approx( $add_zero['subtotal'], 0.0 ), 'add-items: qty 0 → subtotal $0' );

wp_delete_post( $rid, true );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 0, 'type' => 'percentage', 'value' => 0.0 ) );

echo "\nDone. PASS=$pass FAIL=$fail\n";
