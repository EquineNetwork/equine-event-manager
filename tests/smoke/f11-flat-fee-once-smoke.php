<?php
/**
 * F11 smoke — a FLAT convenience fee stays ONCE per order across post-creation
 * edits (Add Items quantity). Companion to F7 (which fixed the same per-row
 * double at checkout-insert time) and the edit-dates branches (hardened in the
 * same pass).
 *
 * A flat fee is a per-ORDER charge. The naive per-row recompute
 * (calculate_convenience_fee returns the flat value for ANY subtotal) would stamp
 * the flat amount onto every component row — so adding an RV to a flat-fee stall
 * order, or adding qty to the $0 non-fee-bearing row of a stall+RV order, would
 * silently add a second flat fee. Percentage fees are unaffected (per-row
 * proportional → already sum correctly), and percentage is the common config.
 *
 * Asserts the order's TOTAL convenience fee stays at exactly one flat amount
 * after (a) bumping an existing component and (b) adding a brand-new component.
 *
 * Run via: wp eval-file tests/smoke/f11-flat-fee-once-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };

if ( ! class_exists( 'EEM_Shortcodes' ) || ! class_exists( 'EEM_Orders_Repository' ) || ! class_exists( 'EEM_Settings_Repo' ) ) {
	echo "  FAIL — required classes missing\n0 passed, 1 failed\n";
	return;
}

$R = static function ( $name ) { $m = new ReflectionMethod( 'EEM_Shortcodes', $name ); $m->setAccessible( true ); return $m; };
$sc       = new EEM_Shortcodes();
$calc     = $R( 'calculate_submission_totals' );
$sanitize = $R( 'sanitize_submission' );
$insert   = $R( 'insert_reservation_orders' );
$getData  = $R( 'get_reservation_data' );

$prev_fee = get_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => true, 'type' => 'flat', 'value' => 25.0, 'label' => 'Flat Fee' ) );
$FLAT = 25.0;

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'F11 Flat Fee Smoke', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'     => 1,
	'stall_nightly_rate' => 35.0,
	'rv_enabled'         => 1,
	'rv_nightly_rate'    => 45.0,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );
$data = $getData->invoke( $sc, $rid );

// The grouped order's `fees` is the sum of every component row's convenience_fee
// (see get_grouped_orders), so it's the order's total convenience fee.
$order_fee_total = static function ( $order ) {
	return round( isset( $order['fees'] ) ? (float) $order['fees'] : 0.0, 2 );
};

$repo = new EEM_Orders_Repository();

// Build $priced shapes for add_component_quantity (flat-fee config).
$rv_priced = array(
	'subtotal' => 90.0, 'unit_price' => 45.0, 'tax_rate' => 0.0, 'stay_type' => 'nightly',
	'arrival' => '2026-08-19', 'departure' => '2026-08-21',
	'fee_enabled' => true, 'fee_type' => 'flat', 'fee_value' => 25.0,
);

// ---- Case A: stall+RV order, bump the EXISTING RV row ($0-fee row) ----
$_POST = array();
$subA = $sanitize->invoke( $sc, array( 'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '' ) );
$subA['first_name'] = 'Flat'; $subA['last_name'] = 'A'; $subA['email'] = 'flatA@eem-test.local';
$subA['invoice_type'] = 'customer'; $subA['submission_token'] = wp_generate_uuid4();
$subA['stall_qty'] = 1; $subA['stall_billable_quantity'] = 1; $subA['stall_stay_type'] = 'nightly';
$subA['stall_arrival_date'] = '2026-08-19'; $subA['stall_departure_date'] = '2026-08-21';
$subA['rv_qty'] = 1; $subA['rv_stay_type'] = 'nightly';
$subA['rv_arrival_date'] = '2026-08-19'; $subA['rv_departure_date'] = '2026-08-21';
$statusA = array( 'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true );
$totalsA = $calc->invoke( $sc, $data, $subA, $statusA, $rid );
$insert->invoke( $sc, $rid, $data, $subA, $statusA, array( 'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'transaction_id' => 'T-' . $subA['submission_token'] ) );

$orderA = $repo->get_order_by_submission_token( $subA['submission_token'] );
$chk( $approx( $totalsA['fees'], $FLAT ), 'A: charge has ONE flat fee ($25)' );
$chk( $approx( $order_fee_total( $orderA ), $FLAT ), 'A: stored order fee == $25 before edit (F7)' );

$repo->add_component_quantity( $orderA['order_key'], 'rv', 1, $rv_priced );
$repo2  = new EEM_Orders_Repository();
$orderA = $repo2->get_order_by_submission_token( $subA['submission_token'] );
$chk( $approx( $order_fee_total( $orderA ), $FLAT ), 'A: after bumping RV qty, order fee STILL $25 (no second flat fee)' );

// ---- Case B: stall-ONLY order, add a brand-NEW RV component ----
$_POST = array();
$subB = $sanitize->invoke( $sc, array( 'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '' ) );
$subB['first_name'] = 'Flat'; $subB['last_name'] = 'B'; $subB['email'] = 'flatB@eem-test.local';
$subB['invoice_type'] = 'customer'; $subB['submission_token'] = wp_generate_uuid4();
$subB['stall_qty'] = 1; $subB['stall_billable_quantity'] = 1; $subB['stall_stay_type'] = 'nightly';
$subB['stall_arrival_date'] = '2026-08-19'; $subB['stall_departure_date'] = '2026-08-21';
$statusB = array( 'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true );
$insert->invoke( $sc, $rid, $data, $subB, $statusB, array( 'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'transaction_id' => 'T-' . $subB['submission_token'] ) );

$orderB = ( new EEM_Orders_Repository() )->get_order_by_submission_token( $subB['submission_token'] );
$chk( $approx( $order_fee_total( $orderB ), $FLAT ), 'B: stall-only stored order fee == $25' );

( new EEM_Orders_Repository() )->add_component_quantity( $orderB['order_key'], 'rv', 1, $rv_priced );
$repo3  = new EEM_Orders_Repository();
$orderB = $repo3->get_order_by_submission_token( $subB['submission_token'] );
$chk( $approx( $order_fee_total( $orderB ), $FLAT ), 'B: after adding a NEW RV component, order fee STILL $25 (new row carries $0 flat)' );

// Cleanup.
foreach ( array( $subA['submission_token'], $subB['submission_token'] ) as $tok ) {
	$like = '%' . $wpdb->esc_like( $tok ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}en_stall_reservations WHERE notes LIKE %s", $like ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}en_rv_reservations WHERE notes LIKE %s", $like ) );
}
wp_delete_post( $rid, true );
if ( false !== $prev_fee ) { update_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE, $prev_fee, false ); } else { delete_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE ); }

echo "\n$pass passed, $fail failed\n";
