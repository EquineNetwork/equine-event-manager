<?php
/**
 * Cross-surface breakdown PARITY smoke (#9 DISPLAY MATH).
 *
 * The admin Order Detail / print used to carry its OWN copy of
 * get_order_stall_breakdown() that computed additional shavings via the legacy
 * `additional_shavings_price × qty` model, while the customer receipt / PDF /
 * email used the canonical per-product `additional_shavings_items` JSON sum.
 * For an order #00009-style payload (per-product additional shavings) the two
 * surfaces produced DIFFERENT splits — the divergence #9 targets.
 *
 * Admin now delegates to EEM_Shortcodes::get_order_stall_breakdown(). This smoke
 * seeds that exact payload and asserts both surfaces return an identical
 * breakdown, so they can never silently diverge again.
 *
 * Run via: wp eval-file tests/smoke/order-breakdown-cross-surface-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0;
$fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.005; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

// $35/night stalls, required shavings $10/bag; additional shavings priced ONLY
// in per-product JSON (legacy single price intentionally left at 0 to expose
// the old admin model's blind spot).
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'XSurface', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'              => 1,
	'stall_nightly_rate'          => 35.0,
	'required_shavings_enabled'   => 1,
	'required_shavings_per_stall' => 2,
	'required_shavings_price'     => 10.0,
	'additional_shavings_enabled' => 1,
	'additional_shavings_price'   => 0.0,
	'convenience_fee_enabled'     => 0,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

$table = $wpdb->prefix . 'en_stall_reservations';
$onum  = 999600;
$wpdb->delete( $table, array( 'order_number' => $onum ) );
$wpdb->insert( $table, array(
	'reservation_id'            => $rid,
	'event_source'              => 'native',
	'event_id'                  => 0,
	'customer_name'             => 'XSurface Tester',
	'email'                     => 'xsurface@example.com',
	'phone'                     => '',
	'stay_type'                 => 'nightly',
	'arrival_date'              => '2026-08-19',
	'departure_date'            => '2026-08-23',
	'stall_qty'                 => 2,
	'tack_stall_qty'            => 0,
	'required_shavings_qty'     => 2,
	'additional_shavings_qty'   => 3,
	'additional_shavings_items' => wp_json_encode( array( array( 'name' => 'Pine Shavings', 'qty' => 3, 'price' => 12.0, 'subtotal' => 36.0 ) ) ),
	'unit_price'                => 35.00,
	// 280 stalls + 20 required + 36 additional = 336.
	'subtotal'                  => 336.00,
	'convenience_fee'           => 0.00,
	'total'                     => 336.00,
	'payment_status'            => 'paid',
	'payment_gateway'           => 'stripe',
	'order_number'              => $onum,
	'transaction_id'            => 'SEED-XSURFACE',
	'notes'                     => "Assigned Stall Units: 401, 402\n",
	'created_at'                => '2026-06-01 00:00:00',
) );
$chk( $wpdb->insert_id > 0, 'seeded order ($336 = 280 stalls + 20 req + 36 add shavings via per-product JSON)' );

$repo  = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders( '', 'date', 'asc' ) as $o ) {
	if ( (int) $o['order_number'] === $onum ) { $order = $o; break; }
}
$chk( is_array( $order ), 'grouped order resolved' );

if ( is_array( $order ) ) {
	$sc       = new EEM_Shortcodes();
	$customer = $sc->get_order_stall_breakdown( $order );

	$admin     = new EEM_Admin();
	$admin_ref = new ReflectionMethod( 'EEM_Admin', 'get_order_stall_breakdown' );
	$admin_ref->setAccessible( true );
	$admin_bd  = $admin_ref->invoke( $admin, $order );

	// Canonical expected split.
	$chk( $approx( $customer['base_subtotal'], 280.0 ), 'customer base = $280' );
	$chk( $approx( $customer['required_shavings_subtotal'], 20.0 ), 'customer required shavings = $20' );
	$chk( $approx( $customer['additional_shavings_subtotal'], 36.0 ), 'customer additional shavings = $36 (per-product JSON)' );

	// PARITY: admin == customer on every field.
	foreach ( array( 'base_subtotal', 'required_shavings_qty', 'required_shavings_subtotal', 'additional_shavings_qty', 'additional_shavings_subtotal' ) as $k ) {
		$chk( $approx( $admin_bd[ $k ], $customer[ $k ] ), "PARITY admin == customer: $k (admin=" . $admin_bd[ $k ] . ", customer=" . $customer[ $k ] . ')' );
	}

	// And the split reconciles to the stored stall subtotal.
	$sum = (float) $customer['base_subtotal'] + (float) $customer['required_shavings_subtotal'] + (float) $customer['additional_shavings_subtotal'];
	$chk( $approx( $sum, (float) $order['stall_subtotal'] ), 'INVARIANT: base + req + add == stall subtotal ($' . number_format( $sum, 2 ) . ' == $' . number_format( (float) $order['stall_subtotal'], 2 ) . ')' );
}

$wpdb->delete( $table, array( 'order_number' => $onum ) );
wp_delete_post( $rid, true );

echo "\nDone. PASS=$pass FAIL=$fail\n";
