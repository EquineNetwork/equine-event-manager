<?php
/**
 * STAY-TYPE MATRIX ALL-SURFACES SMOKE (V1 Full Verification Pass — gap closure).
 *
 * The capstone charge-reconcile smoke exercised only NIGHTLY stall/RV pricing;
 * the coverage audit (2026-07-01) found the less-common stay types were never
 * priced with non-zero rates:
 *   - WEEKLY (stall AND RV) — rate was 0.0 in every existing smoke
 *   - RV WEEKEND / WEEKLY — only RV nightly was asserted
 *   - RV EARLY-BIRD
 * These bill ONCE per unit (get_billable_stay_units() returns 1 for weekend /
 * weekly / packages), NOT per night — so the highest-risk bug this guards is a
 * weekly/weekend stay being mis-billed × night-count.
 *
 * For each scenario it drives the REAL engine end to end and reconciles every
 * money surface it can reach in-process:
 *   - calculate_submission_totals()  → charge (subtotal / fee / tax / total)
 *   - insert_reservation_orders()    → stored order total  (== charge)
 *   - build_order_line_items(order, true) → EMAIL line items  (Σlines == stored)
 * plus the KEY correctness assertion: subtotal == qty × rate (billed ONCE).
 *
 * Run: wp eval-file tests/smoke/stay-type-matrix-allsurfaces-smoke.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

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

// Global fee 4% + tax 8% (restored after).
$prev_fee = get_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE );
$prev_tax = get_option( EEM_Settings_Repo::OPTION_TAX );
EEM_Settings_Repo::update_tax( array( 'apply' => true, 'default_rate' => 8.0, 'label' => 'Sales Tax' ) );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => true, 'type' => 'percentage', 'value' => 4.0, 'label' => 'Non-Refundable Convenience Fee' ) );
$TAX_RATE = 8.0;

$make_submission = static function ( array $overrides ) use ( $sc, $sanitize ) {
	$_POST = array();
	$base  = $sanitize->invoke( $sc, array( 'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '' ) );
	$base['first_name'] = 'StayType'; $base['last_name'] = 'Tester'; $base['email'] = 'staytype@eem-test.local';
	$base['invoice_type'] = 'customer';
	$base['submission_token'] = wp_generate_uuid4();
	$merged = array_merge( $base, $overrides );
	if ( ! empty( $merged['stall_qty'] ) ) { $merged['stall_billable_quantity'] = (int) $merged['stall_qty']; }
	return $merged;
};

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'STAY-TYPE HARNESS RES', 'post_status' => 'publish' ) );

// Config carries NON-ZERO weekly + weekend rates for both sections + an RV
// early-bird rate, so the stay-type paths actually price something.
$base_data = array(
	'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '',
	'stalls_enabled' => 1,
	'stall_nightly_enabled' => 1, 'stall_weekend_enabled' => 1, 'stall_weekly_enabled' => 1,
	'stall_nightly_rate' => 35.0, 'stall_weekend_rate' => 180.0, 'stall_weekly_rate' => 500.0,
	'stall_early_bird_enabled' => 0, 'stall_tack_mode' => 'off',
	'required_shavings_enabled' => 0, 'additional_shavings_enabled' => 0,
	'general_addons_enabled' => 0, 'general_addons' => array(),
	'group_reservations_enabled' => 0,
	'rv_enabled' => 1, 'rv_lot_selection_enabled' => 0,
	'rv_nightly_enabled' => 1, 'rv_weekend_enabled' => 1, 'rv_weekly_enabled' => 1,
	'rv_nightly_rate' => 45.0, 'rv_weekend_rate' => 150.0, 'rv_weekly_rate' => 420.0,
	'rv_early_bird_enabled' => 0,
	'sync_stay_selections' => 0,
);
$status = array( 'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true );

// Multi-night span to PROVE weekly/weekend bill once, not × nights.
$A = '2026-08-17'; $D = '2026-08-24'; // 7 nights

$scenarios = array(
	// KEY: weekly stall billed ONCE per unit (2 × $500 = $1000), NOT × 7 nights.
	array( 'label' => 'Stall WEEKLY 2×$500 billed once (7-night span)',
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'weekly', 'stall_arrival_date' => $A, 'stall_departure_date' => $D ),
		'expect_sub' => 1000.0, 'lines' => array( 'Stall Res.' ) ),
	array( 'label' => 'Stall WEEKEND 2×$180 billed once',
		'sub' => array( 'stall_qty' => 2, 'stall_stay_type' => 'weekend', 'stall_arrival_date' => $A, 'stall_departure_date' => $D ),
		'expect_sub' => 360.0, 'lines' => array( 'Stall Res.' ) ),
	array( 'label' => 'RV WEEKLY 2×$420 billed once',
		'sub' => array( 'rv_qty' => 2, 'rv_stay_type' => 'weekly', 'rv_arrival_date' => $A, 'rv_departure_date' => $D ),
		'expect_sub' => 840.0, 'lines' => array( 'RV Res.' ) ),
	array( 'label' => 'RV WEEKEND 2×$150 billed once',
		'sub' => array( 'rv_qty' => 2, 'rv_stay_type' => 'weekend', 'rv_arrival_date' => $A, 'rv_departure_date' => $D ),
		'expect_sub' => 300.0, 'lines' => array( 'RV Res.' ) ),
	// Mixed: stall weekly + RV weekend on ONE order — both bill once, reconcile together.
	array( 'label' => 'Stall WEEKLY + RV WEEKEND mixed (1000/unit checks)',
		'sub' => array(
			'stall_qty' => 1, 'stall_stay_type' => 'weekly', 'stall_arrival_date' => $A, 'stall_departure_date' => $D,
			'rv_qty' => 1, 'rv_stay_type' => 'weekend', 'rv_arrival_date' => $A, 'rv_departure_date' => $D,
		),
		'expect_sub' => 650.0, 'lines' => array( 'Stall Res.', 'RV Res.' ) ), // 500 + 150
	// RV PACKAGE: flat price × qty, billed ONCE (twin of the stall package the
	// capstone charge-reconcile already covers — RV package was untested).
	array( 'label' => 'RV PACKAGE 2×$200 flat billed once',
		'data' => array( 'rv_pricing_mode' => 'packages', 'rv_packages' => array(
			array( 'id' => 1, 'name' => 'RV Weekend Package', 'price' => 200.0, 'early_bird_price' => 160.0, 'start_date' => '', 'end_date' => '', 'max_quantity' => 0 ),
		) ),
		'sub' => array( 'rv_qty' => 2, 'rv_stay_type' => 'pkg_1', 'rv_arrival_date' => $A, 'rv_departure_date' => $D ),
		'expect_sub' => 400.0, 'lines' => array( 'RV Res.' ) ),
	// RV EARLY-BIRD nightly: the early-bird rate ($30) applies, not the regular
	// $45 nightly. 2 qty × $30 × 2 nights = $120 (untested — stall early-bird was
	// covered, RV early-bird was not).
	array( 'label' => 'RV EARLY-BIRD nightly 2×$30×2n = $120 (not $45 regular)',
		'data' => array( 'rv_early_bird_enabled' => 1, 'rv_early_bird_cutoff' => '2030-01-01 00:00:00',
			'rv_early_bird_nightly_rate' => 30.0, 'rv_early_bird_weekend_rate' => 0.0, 'rv_early_bird_weekly_rate' => 0.0 ),
		'sub' => array( 'rv_qty' => 2, 'rv_stay_type' => 'nightly', 'rv_arrival_date' => '2026-08-19', 'rv_departure_date' => '2026-08-21' ),
		'expect_sub' => 120.0, 'lines' => array( 'RV Res.' ) ),
);

foreach ( $scenarios as $snum => $S ) {
	echo "\n[" . ( $snum + 1 ) . "] " . $S['label'] . "\n";
	$data = array_merge( $base_data, isset( $S['data'] ) ? $S['data'] : array() );

	$sub    = $make_submission( $S['sub'] );
	$totals = $calc->invoke( $sc, $data, $sub, $status, $rid );
	$subtotal     = (float) $totals['subtotal'];
	$charge_total = (float) $totals['total'];

	// KEY correctness: billed once per unit, not × night-count.
	$chk( $approx( $subtotal, $S['expect_sub'] ), sprintf( 'subtotal %.2f == expected %.2f (billed ONCE, not ×nights)', $subtotal, $S['expect_sub'] ) );

	$exp_fee = round( $subtotal * 0.04, 2 );
	$exp_tax = round( $subtotal * ( $TAX_RATE / 100 ), 2 );
	$chk( $approx( $totals['fees'], $exp_fee ), sprintf( 'fee %.2f == 4%% of subtotal %.2f', $totals['fees'], $exp_fee ) );
	$chk( $approx( $totals['tax'], $exp_tax ), sprintf( 'tax %.2f == 8%% of subtotal %.2f', $totals['tax'], $exp_tax ) );
	$chk( $approx( $charge_total, $subtotal + $exp_fee + $exp_tax ), sprintf( 'charge %.2f == sub+fee+tax', $charge_total ) );

	// Write path → stored order total must equal the charge.
	$pay = array( 'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'transaction_id' => 'TEST-' . $sub['submission_token'] );
	$res = $insert->invoke( $sc, $rid, $data, $sub, $status, $pay );
	$chk( ! empty( $res['success'] ), 'order persisted' );

	$order = ( new EEM_Orders_Repository() )->get_order_by_submission_token( $sub['submission_token'] );
	$chk( is_array( $order ), 'order reloaded' );
	if ( is_array( $order ) ) {
		$stored_total = (float) ( $order['total'] ?? 0 );
		$chk( $approx( $stored_total, $charge_total ), sprintf( 'stored total %.2f == CHARGE %.2f', $stored_total, $charge_total ) );

		// EMAIL surface: build_order_line_items(order, true) itemizes fee + tax,
		// so Σlines == stored total, and every expected section line is present.
		$items = $buildItems->invoke( $sc, $order, true );
		$sum_lines = 0.0; $present = array();
		foreach ( $items as $it ) { $sum_lines += $money( $it['total'] ); $present[ $it['section'] ] = true; }
		$chk( $approx( $sum_lines, $stored_total ), sprintf( 'RECONCILE email Σlines %.2f (incl fee+tax) == stored %.2f', $sum_lines, $stored_total ) );
		foreach ( $S['lines'] as $need ) { $chk( isset( $present[ $need ] ), "email shows '$need' line" ); }
	}
}

// Restore prior fee/tax settings ROBUSTLY — always restore or delete, never
// leave the enabled 4%/8% state leaking into a later smoke that assumes it's off
// (an is_array() guard would silently skip the restore when the option was
// absent, so match the charge-reconcile smoke's false-check pattern).
if ( false !== $prev_fee ) { update_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE, $prev_fee, false ); } else { delete_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE ); }
if ( false !== $prev_tax ) { update_option( EEM_Settings_Repo::OPTION_TAX, $prev_tax, false ); } else { delete_option( EEM_Settings_Repo::OPTION_TAX ); }

// Clean up EVERYTHING this smoke seeded so it can't pollute other smokes. The
// seeded orders carry a convenience fee; if left in the DB they become the
// "most recent order" that order-edit-append-engine picks as its target, and
// add_component_quantity would wipe that fee — breaking that smoke's delta.
// Delete the order rows (by this run's email) + the harness reservation.
foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $eem_t ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$eem_t} WHERE email = %s", 'staytype@eem-test.local' ) );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID = %d", $rid ) );

echo "\n" . ( 0 === $FAIL ? 'OK' : 'FAILURES' ) . " — {$PASS} passed, {$FAIL} failed\n";
if ( $FAIL > 0 ) { echo 'Failures: ' . implode( '; ', $FAILS ) . "\n"; exit( 1 ); }
