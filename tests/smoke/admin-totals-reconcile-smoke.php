<?php
/**
 * Admin Order Detail totals-table RECONCILIATION smoke (#9 DISPLAY MATH).
 *
 * Renders EEM_Admin::render_order_totals_table() for a seeded order and asserts
 * the displayed subtotal rows (everything except the Total row) sum to the
 * stored order Total — the invariant that nothing is silently dropped from, or
 * double-counted on, the admin Order Detail. Guards the canonical-breakdown
 * delegation + premium-line additions made for #9.
 *
 * Run via: wp eval-file tests/smoke/admin-totals-reconcile-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0;
$fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.02; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'AdminRecon', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'              => 1,
	'stall_nightly_rate'          => 35.0,
	'required_shavings_enabled'   => 1,
	'required_shavings_per_stall' => 2,
	'required_shavings_price'     => 10.0,
	'additional_shavings_enabled' => 1,
	'additional_shavings_price'   => 0.0,
	'convenience_fee_enabled'     => 1,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

$table = $wpdb->prefix . 'eem_stall_reservations';
$onum  = 999800;
$wpdb->delete( $table, array( 'order_number' => $onum ) );
// 280 stalls + 20 req + 36 add = 336 stall subtotal; $15 fee → 351 total.
$wpdb->insert( $table, array(
	'reservation_id'            => $rid,
	'event_source'              => 'native',
	'event_id'                  => 0,
	'customer_name'             => 'Admin Recon',
	'email'                     => 'adminrecon@example.com',
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
	'subtotal'                  => 336.00,
	'convenience_fee'           => 15.00,
	'total'                     => 351.00,
	'payment_status'            => 'paid',
	'payment_gateway'           => 'stripe',
	'order_number'              => $onum,
	'transaction_id'            => 'SEED-ADMINRECON',
	'notes'                     => "Assigned Stall Units: 501, 502\n",
	'created_at'                => '2026-06-01 00:00:00',
) );
$chk( $wpdb->insert_id > 0, 'seeded order ($351 = 280 + 20 + 36 + 15 fee)' );

$repo  = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders( '', 'date', 'asc' ) as $o ) {
	if ( (int) $o['order_number'] === $onum ) { $order = $o; break; }
}
$chk( is_array( $order ), 'grouped order resolved' );

if ( is_array( $order ) ) {
	$admin = new EEM_Admin();
	$ref   = new ReflectionMethod( 'EEM_Admin', 'render_order_totals_table' );
	$ref->setAccessible( true );
	ob_start();
	$ref->invoke( $admin, $order );
	$html = (string) ob_get_clean();

	// Parse <th>label</th><td>...$amount...</td> pairs.
	preg_match_all( '#<th>(.*?)</th>\s*<td>(.*?)</td>#s', $html, $m, PREG_SET_ORDER );
	$total_shown = null;
	$sum_rows    = 0.0;
	foreach ( $m as $pair ) {
		$label = trim( wp_strip_all_tags( $pair[1] ) );
		$amt   = (float) preg_replace( '/[^0-9.\-]/', '', $pair[2] );
		if ( __( 'Total', 'equine-event-manager' ) === $label ) {
			$total_shown = $amt;
		} else {
			$sum_rows += $amt;
		}
	}
	$chk( null !== $total_shown && $approx( $total_shown, 351.0 ), 'Total row shows stored total $351 (got $' . number_format( (float) $total_shown, 2 ) . ')' );
	$chk( $approx( $sum_rows, 351.0 ), 'INVARIANT: Σ(subtotal rows) $' . number_format( $sum_rows, 2 ) . ' == Total $351 — nothing dropped/double-counted' );
}

$wpdb->delete( $table, array( 'order_number' => $onum ) );
wp_delete_post( $rid, true );

echo "\nDone. PASS=$pass FAIL=$fail\n";
