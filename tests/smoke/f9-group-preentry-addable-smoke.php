<?php
/**
 * F9 smoke — Group fees + Pre-Entries are addable via the Order Detail
 * "Add Items" modal.
 *
 * Whitney use case: "a customer forgot to pay their group reservation fees, add
 * them later." Before F9, Add Items only offered Stall / RV / Shavings / Add-Ons
 * / Custom — group grounds-fee, group rider-deposit, and pre-entries could only
 * be approximated with an un-itemized Custom Line Item (no fee parity).
 *
 * Asserts three layers:
 *   1. DATA — get_addable_products() surfaces the group fees + pre-entry with
 *      correct keys / prices / group labels, gated on reservation config.
 *   2. UI   — render_add_items_modal() emits a "Group Fees" + "Pre-Entries"
 *      <optgroup> with the matching data-product-key options.
 *   3. MATH — the AJAX-style server re-price (match key → price × qty) and the
 *      convenience fee following the added item via compose_order_totals().
 *
 * Run via: wp eval-file tests/smoke/f9-group-preentry-addable-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };

if ( ! class_exists( 'EEM_Shortcodes' ) || ! class_exists( 'EEM_Order_Adjustments_Repo' ) || ! class_exists( 'EEM_Reservation_Config' ) ) {
	echo "  FAIL — required classes missing\n0 passed, 1 failed\n";
	return;
}

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'F9 Group Smoke', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'                  => 1,
	'stall_nightly_rate'              => 30.0,
	'convenience_fee_enabled'         => 1,
	'group_reservations_enabled'      => 1,
	'group_rider_grounds_fee_enabled' => 1,
	'group_rider_grounds_fee_amount'  => 25.0,
	'group_rider_deposit_enabled'     => 1,
	'group_rider_deposit_amount'      => 100.0,
	'event_pre_entries_enabled'       => 1,
	'event_pre_entries'               => array(
		array( 'title' => 'Early Entry', 'price' => 15.0, 'inventory' => 0, 'max_per_customer' => 0 ),
	),
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

// --- 1. DATA layer ---
$sc      = new EEM_Shortcodes();
$catalog = $sc->get_addable_products( $rid );
$by_key  = array();
foreach ( $catalog as $c ) { $by_key[ $c['key'] ] = $c; }

$chk( isset( $by_key['group_grounds_fee'] ) && $approx( $by_key['group_grounds_fee']['price'], 25.0 ) && 'Group Fees' === $by_key['group_grounds_fee']['group'], 'catalog: group grounds fee ($25, Group Fees)' );
$chk( isset( $by_key['group_rider_deposit'] ) && $approx( $by_key['group_rider_deposit']['price'], 100.0 ) && 'Group Fees' === $by_key['group_rider_deposit']['group'], 'catalog: group rider deposit ($100, Group Fees)' );
$chk( isset( $by_key['pre_entry_0'] ) && 'Early Entry' === $by_key['pre_entry_0']['label'] && $approx( $by_key['pre_entry_0']['price'], 15.0 ) && 'Pre-Entries' === $by_key['pre_entry_0']['group'], 'catalog: pre-entry (Early Entry $15, Pre-Entries)' );

// Gating: a reservation WITHOUT group enabled must NOT surface group fees.
$rid_off = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'F9 No Group', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid_off )->set_many( array( 'stalls_enabled' => 1, 'stall_nightly_rate' => 30.0 ) )->save();
EEM_Reservation_Config::flush_cache( $rid_off );
$catalog_off = $sc->get_addable_products( $rid_off );
$off_keys    = array_map( static function ( $c ) { return $c['key']; }, $catalog_off );
$chk( ! in_array( 'group_grounds_fee', $off_keys, true ) && ! in_array( 'pre_entry_0', $off_keys, true ), 'gating: group/pre-entry absent when reservation does not enable them' );

// --- 2. UI layer: render the Add Items modal ---
$order = array(
	'order_key'           => 'F9UI' . substr( md5( (string) $rid ), 0, 12 ),
	'reservation_id'      => $rid,
	'status_slug'         => 'pending',
	'stall_arrival_date'  => '2026-09-01',
	'stall_departure_date'=> '2026-09-03',
);
$page = new EEM_Order_Detail_Page();
$ref  = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_add_items_modal' );
$ref->setAccessible( true );
ob_start(); $ref->invoke( $page, $order ); $html = (string) ob_get_clean();

$chk( false !== strpos( $html, '<optgroup label="Group Fees">' ), 'modal: Group Fees optgroup rendered' );
$chk( false !== strpos( $html, '<optgroup label="Pre-Entries">' ), 'modal: Pre-Entries optgroup rendered' );
$chk( false !== strpos( $html, 'data-product-key="group_grounds_fee"' ), 'modal: grounds-fee option present' );
$chk( false !== strpos( $html, 'data-product-key="group_rider_deposit"' ), 'modal: rider-deposit option present' );
$chk( false !== strpos( $html, 'data-product-key="pre_entry_0"' ), 'modal: pre-entry option present' );
$chk( false !== strpos( $html, 'Group Grounds Fee (per rider)' ), 'modal: grounds-fee label shown' );

// --- 3. MATH layer: server re-price + fee follows ---
$qty    = 3;
$match  = $by_key['group_grounds_fee'];
$amount = round( (float) $match['price'] * $qty, 2 );
$chk( $approx( $amount, 75.0 ), 'reprice: 3 riders × $25 = $75' );

$order_key = 'F9MATH' . substr( md5( (string) $rid ), 0, 14 );
EEM_Order_Adjustments_Repo::replace_custom_items( $order_key, array() );
$desc = sprintf( '%s × %d', $match['label'], $qty );
EEM_Order_Adjustments_Repo::insert_custom_item( $order_key, $desc, $amount );
$adj = EEM_Order_Adjustments_Repo::get_for_order( $order_key );
$chk( $approx( $adj['custom_items_total'], 75.0 ), 'reprice: custom item persisted at $75' );

$synthetic = array( 'total' => 93.60, 'fees' => 3.60, 'tax' => 0.0, 'reservation_id' => $rid, 'notes' => '' );
$comp      = EEM_Order_Adjustments_Repo::compose_order_totals( $synthetic, $adj );
$feecfg    = EEM_Settings_Repo::get_convenience_fee();
$is_pct    = ! empty( $feecfg['apply'] ) && 'percentage' === ( $feecfg['type'] ?? '' );
$exp_cfee  = $is_pct ? round( (float) $feecfg['value'] / 100 * 75.0, 2 ) : 0.0;
$chk( $approx( $comp['custom_fee'], $exp_cfee ), 'compose: convenience fee follows the added group fee' );
$chk( $approx( $comp['grand_total'], round( 93.60 + 75.0 + $exp_cfee, 2 ) ), 'compose: grand_total = base + $75 + its fee' );

// Cleanup.
EEM_Order_Adjustments_Repo::delete_for_order( $order_key );
wp_delete_post( $rid, true );
wp_delete_post( $rid_off, true );

echo "\n$pass passed, $fail failed\n";
