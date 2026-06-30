<?php
/**
 * STRICT CHARGE-RECONCILE ALL-SURFACES SMOKE (capstone money audit).
 *
 * Seeds real orders via the real write path (insert_reservation_orders), reloads
 * via the consumer, verifies each surface reconciles, AND asserts fee + tax math
 * across 12 scenarios: stall base, add-on, additional shavings, group, RV,
 * multi-component percentage fee, multi-component FLAT fee (F7 once-per-order
 * guard), stay package, package + early bird, required shavings, tack exclusion.
 * Temporarily enables the global convenience fee (4%) + tax (8%), restores after.
 *
 * INVARIANTS per order:
 *   - charge total ($totals['total']) == stored grouped-order total
 *   - Σ(displayed receipt line items incl. fee line) + tax == stored total
 *   - fee == expected (4% subtotal OR flat once), tax == 8% subtotal
 *   - every expected charge LINE present (nothing folded/dropped)
 *
 * Run via: wp eval-file tests/smoke/charge-reconcile-allsurfaces-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$PASS = 0; $FAIL = 0; $FAILS = array();
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };
$chk = static function ( $cond, $label ) use ( &$PASS, &$FAIL, &$FAILS ) {
	if ( $cond ) { $PASS++; } else { $FAIL++; $FAILS[] = $label; echo "    FAIL — $label\n"; }
};
$money = static function ( $s ) { return (float) preg_replace( '/[^0-9.\-]/', '', (string) $s ); };

$sc = new EEM_Shortcodes();
$R = static function ( $name ) { $m = new ReflectionMethod( 'EEM_Shortcodes', $name ); $m->setAccessible( true ); return $m; };
$calc       = $R( 'calculate_submission_totals' );
$sanitize   = $R( 'sanitize_submission' );
$insert     = $R( 'insert_reservation_orders' );
$buildItems = $R( 'build_order_line_items' );

// ── Enable global fee (4%) + tax (8%); remember prior to restore ──────────────
$prev_fee = get_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE );
$prev_tax = get_option( EEM_Settings_Repo::OPTION_TAX );
$set_fee = static function ( $type, $value ) {
	EEM_Settings_Repo::update_convenience_fee( array( 'apply' => true, 'type' => $type, 'value' => $value, 'label' => 'Non-Refundable Convenience Fee' ) );
};
EEM_Settings_Repo::update_tax( array( 'apply' => true, 'default_rate' => 8.0, 'label' => 'Sales Tax' ) );
$set_fee( 'percentage', 4.0 );
$TAX_RATE = 8.0;

$make_submission = static function ( array $overrides ) use ( $sc, $sanitize ) {
	$_POST = array();
	$base  = $sanitize->invoke( $sc, array( 'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '' ) );
	$base['first_name'] = 'Audit'; $base['last_name'] = 'Tester'; $base['email'] = 'audit@eem-test.local';
	$base['invoice_type'] = 'customer';
	$base['submission_token'] = wp_generate_uuid4();
	$merged = array_merge( $base, $overrides );
	if ( ! empty( $merged['stall_qty'] ) ) { $merged['stall_billable_quantity'] = (int) $merged['stall_qty']; }
	return $merged;
};

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'AUDIT HARNESS RES', 'post_status' => 'publish' ) );
// Seed config the DISPLAY side rereads (required shavings price) so charge==display.
EEM_Reservation_Config::for( $rid )->set_many( array(
	'required_shavings_enabled'   => 1,
	'required_shavings_per_stall' => 2,
	'required_shavings_price'     => 10.0,
) )->save();
$shav_data = array( 'required_shavings_enabled' => 1, 'required_shavings_per_stall' => 2, 'required_shavings_price' => 10.0, 'stall_tack_mode' => 'customer' );

$base_data = array(
	'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '',
	'stalls_enabled' => 1, 'stall_nightly_rate' => 35.0, 'stall_weekend_rate' => 0.0, 'stall_weekly_rate' => 0.0,
	'stall_early_bird_enabled' => 0, 'stall_tack_mode' => 'off',
	'required_shavings_enabled' => 0, 'required_shavings_per_stall' => 0, 'required_shavings_price' => 0.0,
	'additional_shavings_enabled' => 1, 'additional_shavings_price' => 0.0,
	'general_addons_enabled' => 1, 'general_addons' => array(),
	'group_reservations_enabled' => 1,
	'group_rider_grounds_fee_enabled' => 1, 'group_rider_grounds_fee_amount' => 20.0,
	'group_rider_deposit_enabled' => 1, 'group_rider_deposit_amount' => 50.0,
	'rv_enabled' => 1, 'rv_lot_selection_enabled' => 0, 'rv_nightly_rate' => 45.0, 'rv_weekend_rate' => 0.0,
	'rv_early_bird_enabled' => 0, 'sync_stay_selections' => 0,
);
$status = array( 'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true );

$package_data = array_merge( $base_data, array(
	'stall_pricing_mode' => 'packages',
	'stall_packages' => array(
		array( 'id' => 1, 'name' => 'Weekend Package', 'price' => 150.0, 'early_bird_price' => 120.0, 'start_date' => '', 'end_date' => '', 'max_quantity' => 0 ),
	),
) );

$scenarios = array(
	array( 'label' => 'Stall base (5×$35×4n)', 'fee' => 'percentage', 'data' => array(),
		'sub' => array( 'stall_qty' => 5, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-23' ),
		'lines' => array( 'Stall Res.' ) ),
	array( 'label' => 'Stall + Add-On (Hay $15×2)', 'fee' => 'percentage',
		'data' => array( 'general_addons' => array( array( 'name' => 'Hay Bale', 'description' => '', 'applies_to' => 'any', 'price' => 15.0, 'per_label' => '' ) ) ),
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21' ),
		'addon_qty' => 2, 'lines' => array( 'Stall Res.', 'General Add-On' ) ),
	array( 'label' => 'Stall + Additional Shavings ($36 JSON)', 'fee' => 'percentage', 'data' => array(),
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21',
			'additional_shavings_qty' => 3, 'additional_shavings_items' => array( array( 'name' => 'Pine', 'qty' => 3, 'price' => 12.0, 'subtotal' => 36.0 ) ) ),
		'lines' => array( 'Stall Res.', 'Additional Shavings' ) ),
	array( 'label' => 'Stall + Group (3 riders $20+$50)', 'fee' => 'percentage', 'data' => array(),
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21',
			'group_reservation_enabled' => 1, 'group_rider_count' => 3 ),
		'lines' => array( 'Stall Res.', 'Group Res.' ) ),
	// F10 guard: pre-entries must persist onto the order (charge == stored).
	array( 'label' => 'Stall + Pre-Entries (4 × $15 = $60)', 'fee' => 'percentage',
		'data' => array( 'event_pre_entries_enabled' => 1, 'event_pre_entries' => array( array( 'title' => 'Early Entry', 'price' => 15.0, 'inventory' => 0, 'max_per_customer' => 0 ) ) ),
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21',
			'pre_entry_0_qty' => 4 ),
		'lines' => array( 'Stall Res.', 'Pre-Entry' ) ),
	array( 'label' => 'RV base (2×$45×3n)', 'fee' => 'percentage', 'data' => array(),
		'sub' => array( 'rv_qty' => 2, 'rv_stay_type' => 'nightly', 'rv_arrival_date' => '2026-08-19', 'rv_departure_date' => '2026-08-22' ),
		'lines' => array( 'RV Res.' ) ),
	array( 'label' => 'Stall+RV+addon+group PERCENT fee', 'fee' => 'percentage',
		'data' => array( 'general_addons' => array( array( 'name' => 'Hay Bale', 'description' => '', 'applies_to' => 'any', 'price' => 15.0, 'per_label' => '' ) ) ),
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21',
			'rv_qty' => 1, 'rv_stay_type' => 'nightly', 'rv_arrival_date' => '2026-08-19', 'rv_departure_date' => '2026-08-21',
			'group_reservation_enabled' => 1, 'group_rider_count' => 2 ),
		'addon_qty' => 1, 'lines' => array( 'Stall Res.', 'RV Res.', 'General Add-On', 'Group Res.' ) ),
	// 2.4 guard: per-row % fee ROUNDING must not drift stored from charged. stall
	// $12.62 + RV $12.62 → charge fee round(.04 × 25.24) = $1.01, but per-row rounding
	// gives round(.4952)+round(.4952) = 0.50 + 0.50 = $1.00 (1¢ short) unless the fee is
	// split once-per-order with an exact remainder.
	array( 'label' => 'Fee rounding edge (stall $12.62 + RV $12.62 @ 4%)', 'fee' => 'percentage',
		'data' => array( 'stall_nightly_rate' => 12.62, 'rv_nightly_rate' => 12.62 ),
		'sub' => array( 'stall_qty' => 1, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-20',
			'rv_qty' => 1, 'rv_stay_type' => 'nightly', 'rv_arrival_date' => '2026-08-19', 'rv_departure_date' => '2026-08-20' ),
		'lines' => array( 'Stall Res.', 'RV Res.' ) ),
	// FLAT fee on a MULTI-component order — does the flat fee get charged once or per row?
	array( 'label' => 'Stall+RV FLAT $25 fee (multi-row double-charge check)', 'fee' => 'flat:25',
		'data' => array(),
		'sub' => array( 'stall_qty' => 1, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21',
			'rv_qty' => 1, 'rv_stay_type' => 'nightly', 'rv_arrival_date' => '2026-08-19', 'rv_departure_date' => '2026-08-21' ),
		'lines' => array( 'Stall Res.', 'RV Res.' ) ),
	// Stay Package (billed once)
	array( 'label' => 'Stay Package stall ($150 ×1)', 'fee' => 'percentage', 'data' => $package_data,
		'sub' => array( 'stall_qty' => 1, 'stall_stay_type' => 'pkg_1', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-23' ),
		'lines' => array( 'Stall Res.' ), 'expect_sub' => 150.0 ),
	// Package + Early Bird active → $120
	array( 'label' => 'Stay Package + Early Bird active ($120)', 'fee' => 'percentage',
		'data' => array_merge( $package_data, array( 'stall_early_bird_enabled' => 1, 'stall_early_bird_cutoff' => '2030-01-01 00:00:00' ) ),
		'sub' => array( 'stall_qty' => 1, 'stall_stay_type' => 'pkg_1', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-23' ),
		'lines' => array( 'Stall Res.' ), 'expect_sub' => 120.0 ),
	// Required shavings: 3 stalls × 2 bags × $10 = $60 + stall base 3×$35×2n=$210 → $270
	array( 'label' => 'Required Shavings (3 stalls → 6 bags $60)', 'fee' => 'percentage', 'data' => $shav_data,
		'sub' => array( 'stall_qty' => 3, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21' ),
		'lines' => array( 'Stall Res.', 'Required Shavings' ), 'expect_sub' => 270.0 ),
	// Tack exclusion: 3 stalls, 1 tack → required on 2 stalls = 4 bags $40; base still 3×$35×2=$210 → $250
	array( 'label' => 'Tack excluded from required shavings (3 stalls,1 tack → 4 bags $40)', 'fee' => 'percentage', 'data' => $shav_data,
		'sub' => array( 'stall_qty' => 3, 'stall_stay_type' => 'nightly', 'stall_arrival_date' => '2026-08-19', 'stall_departure_date' => '2026-08-21', 'preferred_tack_stall' => '100' ),
		'lines' => array( 'Stall Res.', 'Required Shavings' ), 'expect_sub' => 250.0 ),
);

foreach ( $scenarios as $snum => $S ) {
	echo "\n[" . ( $snum + 1 ) . "] " . $S['label'] . "\n";
	$data = array_merge( $base_data, $S['data'] );
	if ( 0 === strpos( $S['fee'], 'flat' ) ) { $set_fee( 'flat', (float) substr( $S['fee'], 5 ) ); }
	else { $set_fee( 'percentage', 4.0 ); }

	$sub = $make_submission( $S['sub'] );
	if ( ! empty( $S['addon_qty'] ) ) {
		$optsRef = $R( 'get_enabled_general_addon_options' );
		$opts = $optsRef->invoke( $sc, $data );
		$akey = $opts ? (string) array_key_first( $opts ) : '';
		if ( '' !== $akey ) { $sub[ 'general_addon_' . $akey . '_qty' ] = (int) $S['addon_qty']; }
	}

	$totals = $calc->invoke( $sc, $data, $sub, $status, $rid );
	$subtotal = (float) $totals['subtotal'];
	$charge_total = (float) $totals['total'];

	// expected fee + tax from the canonical calculator
	if ( isset( $S['expect_sub'] ) ) { $chk( $approx( $subtotal, $S['expect_sub'] ), sprintf( 'subtotal %.2f == expected %.2f', $subtotal, $S['expect_sub'] ) ); }
	$exp_fee = ( 0 === strpos( $S['fee'], 'flat' ) ) ? (float) substr( $S['fee'], 5 ) : round( $subtotal * 0.04, 2 );
	$exp_tax = round( $subtotal * ( $TAX_RATE / 100 ), 2 );
	$chk( $approx( $totals['fees'], $exp_fee ), sprintf( 'fee %.2f == expected %.2f', $totals['fees'], $exp_fee ) );
	$chk( $approx( $totals['tax'], $exp_tax ), sprintf( 'tax %.2f == expected %.2f', $totals['tax'], $exp_tax ) );
	$chk( $approx( $charge_total, $subtotal + $exp_fee + $exp_tax ), sprintf( 'charge total %.2f == sub+fee+tax', $charge_total ) );

	$pay = array( 'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'transaction_id' => 'TEST-' . $sub['submission_token'] );
	$res = $insert->invoke( $sc, $rid, $data, $sub, $status, $pay );
	$chk( ! empty( $res['success'] ), 'order persisted' );
	$tok = $sub['submission_token'];
	$like = '%' . $wpdb->esc_like( $tok ) . '%';
	$order = ( new EEM_Orders_Repository() )->get_order_by_submission_token( $tok );
	$chk( is_array( $order ), 'order reloaded' );
	if ( is_array( $order ) ) {
		$stored_total = (float) ( $order['total'] ?? 0 );
		// THE BIG ONE: stored/displayed total must equal what was actually charged.
		$chk( $approx( $stored_total, $charge_total ), sprintf( 'stored total %.2f == CHARGE %.2f', $stored_total, $charge_total ) );

		// build_order_line_items($order, true) is the EMAIL variant: it itemizes
		// BOTH the convenience fee AND the tax line (2.7.711 added the tax line so
		// the email rows sum to the Total Paid). So Σ(lines) already includes fee +
		// tax and must equal the stored total directly — adding tax again would
		// double-count it.
		$items = $buildItems->invoke( $sc, $order, true );
		$sum_lines = 0.0; $present = array();
		foreach ( $items as $it ) { $sum_lines += $money( $it['total'] ); $present[ $it['section'] ] = true; $present[ $it['desc'] ] = true; }
		$tax = (float) ( $order['tax'] ?? 0 );
		$chk( $approx( $sum_lines, $stored_total ), sprintf( 'RECONCILE Σlines %.2f (incl. fee + tax) == stored %.2f', $sum_lines, $stored_total ) );
		foreach ( $S['lines'] as $need ) { $chk( isset( $present[ $need ] ), "receipt shows '$need'" ); }
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eem_stall_reservations WHERE notes LIKE %s", $like ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eem_rv_reservations WHERE notes LIKE %s", $like ) );
}

wp_delete_post( $rid, true );
// restore settings
if ( false !== $prev_fee ) { update_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE, $prev_fee, false ); } else { delete_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE ); }
if ( false !== $prev_tax ) { update_option( EEM_Settings_Repo::OPTION_TAX, $prev_tax, false ); } else { delete_option( EEM_Settings_Repo::OPTION_TAX ); }

echo "\n========================================\n";
echo "CHARGE AUDIT HARNESS v2: PASS=$PASS FAIL=$FAIL\n";
if ( $FAILS ) { echo "FAILURES:\n - " . implode( "\n - ", $FAILS ) . "\n"; }
echo "(global fee + tax restored to prior values)\n";
// Runner-compatible tally line (tests/run-all-smokes.php parses this).
echo "\n$PASS passed, $FAIL failed\n";
