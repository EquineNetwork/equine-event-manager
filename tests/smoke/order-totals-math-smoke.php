<?php
/**
 * Order-totals math smoke — exercises the canonical server-side charging
 * calculator (EEM_Shortcodes::calculate_submission_totals), the source of truth
 * for what the customer is actually charged. Asserts each line of a
 * comprehensive order against hand-computed dollar amounts so a pricing
 * regression in stalls / required shavings / additional shavings / general
 * add-ons / group rider grounds-fee + deposit / convenience fee / tax is caught
 * here before it ever reaches the live site.
 *
 * Run via: wp eval-file tests/smoke/order-totals-math-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0;
$fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.005; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

$sc  = new EEM_Shortcodes();
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'calculate_submission_totals' );
$ref->setAccessible( true );

// ── Reservation config ($data) ──────────────────────────────────────────────
// 5 stalls @ $35/night, 1 tack stall; 2 required shavings/stall @ $10/bag (tack
// excluded); additional shavings enabled; one general add-on @ $15; group rider
// grounds fee $20 + deposit $50 each; 4% convenience fee.
$data = array(
	'stalls_enabled'                   => 1,
	'stall_nightly_rate'               => 35.0,
	'stall_weekend_rate'               => 0.0,
	'stall_weekly_rate'                => 0.0,
	'stall_early_bird_enabled'         => 0,
	'stall_tack_mode'                  => 'customer',
	'required_shavings_enabled'        => 1,
	'required_shavings_per_stall'      => 2,
	'required_shavings_price'          => 10.0,
	'additional_shavings_enabled'      => 1,
	'general_addons_enabled'           => 1,
	'general_addons'                   => array(
		array( 'name' => 'Hay Bale', 'description' => '', 'applies_to' => 'any', 'price' => 15.0, 'per_label' => '' ),
	),
	'group_reservations_enabled'       => 1,
	'group_rider_grounds_fee_enabled'  => 1,
	'group_rider_grounds_fee_amount'   => 20.0,
	'group_rider_deposit_enabled'      => 1,
	'group_rider_deposit_amount'       => 50.0,
	'convenience_fee_enabled'          => 1,
	'convenience_fee_type'             => 'percentage',
	'convenience_fee_value'            => 4.0,
	'rv_lot_selection_enabled'         => 0,
	'sync_stay_selections'             => 0,
);

// Discover the general add-on key the calculator expects, so the submission
// quantity is keyed correctly regardless of how keys are derived.
$opts_ref = new ReflectionMethod( 'EEM_Shortcodes', 'get_enabled_general_addon_options' );
$opts_ref->setAccessible( true );
$addon_opts = $opts_ref->invoke( $sc, $data );
$addon_key  = ! empty( $addon_opts ) ? (string) array_key_first( $addon_opts ) : '';
$chk( '' !== $addon_key, "general add-on option resolves a key ($addon_key)" );

// ── Submission ($submission) ────────────────────────────────────────────────
$submission = array(
	'stall_qty'              => 5,
	'tack_stall_qty'         => 0,
	'preferred_tack_stall'   => '295', // → tack count = 1
	'stall_stay_type'        => 'nightly',
	'stall_arrival_date'     => '2026-08-19',
	'stall_departure_date'   => '2026-08-23', // 4 nights
	'stall_items'            => array(),
	'preferred_stall_units'  => array(),
	'stall_tier_surcharge_sum' => null,
	'rv_qty'                 => 0,
	'rv_stay_type'           => 'nightly',
	'rv_arrival_date'        => '',
	'rv_departure_date'      => '',
	'rv_lot'                 => '',
	'preferred_rv_lots'      => array(),
	'rv_tier_surcharge_sum'  => null,
	'additional_shavings_items' => array( array( 'subtotal' => 30.0 ) ),
	'group_reservation_enabled' => 1,
	'group_rider_count'      => 3,
);
if ( '' !== $addon_key ) {
	$submission[ 'general_addon_' . $addon_key . '_qty' ] = 2;
}

$status = array( 'stalls_open' => true, 'rv_open' => false );

$t = $ref->invoke( $sc, $data, $submission, $status, 0 );

// ── Expected hand-math ──────────────────────────────────────────────────────
// stall base: 5 × $35 × 4 = $700
// required shavings: (5 − 1 tack) × 2 = 8 bags × $10 = $80
// additional shavings: $30
// stall_subtotal = 700 + 80 + 30 = $810
// group: 3 × $20 (grounds) + 3 × $50 (deposit) = $60 + $150 = $210
// general add-on: 2 × $15 = $30
// subtotal = 810 + 0(rv) + 30 + 0(pre-entry) + 210 = $1050
// convenience fee: 4% × 1050 = $42.00
// tax: per global setting (asserted via returned rate)
// total = subtotal + fees + tax
$chk( $approx( $t['stall_subtotal'], 810.0 ), 'stall_subtotal = $810 (700 stalls + 80 req shavings + 30 add shavings)' );
$chk( $t['required_shavings_qty'] === 8, 'required shavings qty = 8 bags (tack stall excluded)' );
$chk( $approx( $t['required_shavings_subtotal'], 80.0 ), 'required shavings subtotal = $80' );
$chk( $approx( $t['additional_shavings_subtotal'], 30.0 ), 'additional shavings subtotal = $30' );
$chk( $approx( $t['group_rider_grounds_fee_subtotal'], 60.0 ), 'group grounds fee = 3 × $20 = $60' );
$chk( $approx( $t['group_rider_deposit_subtotal'], 150.0 ), 'group rider deposit = 3 × $50 = $150' );
$chk( $approx( $t['group_subtotal'], 210.0 ), 'group subtotal = $210' );
$chk( $approx( $t['general_addons_subtotal'], 30.0 ), 'general add-on subtotal = 2 × $15 = $30' );
$chk( $approx( $t['subtotal'], 1050.0 ), 'subtotal = $1050' );
$chk( $approx( $t['fees'], 42.0 ), 'convenience fee = 4% × $1050 = $42.00' );
$expected_tax = round( 1050.0 * ( (float) $t['tax_rate'] / 100 ), 2 );
$chk( $approx( $t['tax'], $expected_tax ), 'tax = tax_rate% × subtotal (' . $t['tax_rate'] . '% → $' . number_format( $expected_tax, 2 ) . ')' );
$chk( $approx( $t['total'], $t['subtotal'] + $t['fees'] + $t['tax'] ), 'total = subtotal + fees + tax = $' . number_format( $t['total'], 2 ) );

// ── Flat convenience fee variation ──────────────────────────────────────────
$data_flat = $data;
$data_flat['convenience_fee_type']  = 'flat';
$data_flat['convenience_fee_value'] = 25.0;
$t2 = $ref->invoke( $sc, $data_flat, $submission, $status, 0 );
$chk( $approx( $t2['fees'], 25.0 ), 'flat convenience fee = $25.00 (not percentage)' );

// ── Group toggled OFF → no group charges ────────────────────────────────────
$sub_nogroup = $submission;
$sub_nogroup['group_reservation_enabled'] = 0;
$t3 = $ref->invoke( $sc, $data, $sub_nogroup, $status, 0 );
$chk( $approx( $t3['group_subtotal'], 0.0 ), 'group off → group subtotal = $0' );
$chk( $approx( $t3['subtotal'], 840.0 ), 'group off → subtotal = $840 (1050 − 210)' );

echo "\nDone. PASS=$pass FAIL=$fail\n";
