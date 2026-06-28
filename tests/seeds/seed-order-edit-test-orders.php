<?php
/**
 * Seed: two clean, reservation-linked PAID orders for testing Order Edit / Add
 * Items — one stall order on reservation 5990 (Southeast, $45 nightly) and one
 * RV order on reservation 6519 (NTR, $40 nightly).
 *
 * The key thing the demo orders were missing: a "Reservation setup ID: N" note
 * line + a populated reservation_id column, so the order resolves back to its
 * reservation and the Add-Items modal can offer stall / RV inventory (not just a
 * custom line item).
 *
 * Idempotent: deletes any prior rows with these test emails first, so re-running
 * doesn't pile up duplicates.
 *
 * Run:
 *   php wp-cli.phar eval-file tests/seeds/seed-order-edit-test-orders.php
 */

global $wpdb;
$stall = $wpdb->prefix . 'eem_stall_reservations';
$rv    = $wpdb->prefix . 'eem_rv_reservations';

$stall_email = 'order-edit-stall@example.test';
$rv_email    = 'order-edit-rv@example.test';

// Idempotency — clear prior runs.
$wpdb->delete( $stall, array( 'email' => $stall_email ) );
$wpdb->delete( $rv, array( 'email' => $rv_email ) );

$created = current_time( 'mysql' );

// ── Stall order — reservation 5990 (1 stall, 1 night @ $45, paid) ──────── //
$stall_sub   = 45.00;
$stall_total = $stall_sub; // no fee/tax configured
$wpdb->insert( $stall, array(
	'event_source'    => 'tec',
	'event_id'        => 9,
	'customer_name'   => 'Order Edit Test (Stall)',
	'email'           => $stall_email,
	'phone'           => '555-0190',
	'stall_qty'       => 1,
	'stay_type'       => 'nightly',
	'arrival_date'    => '2026-06-26',
	'departure_date'  => '2026-06-27',
	'unit_price'      => '45.00',
	'subtotal'        => '45.00',
	'convenience_fee' => '0.00',
	'total'           => number_format( $stall_total, 2, '.', '' ),
	'tax'             => '0.00',
	'tax_rate'        => '0.000',
	'amount_paid'     => number_format( $stall_total, 2, '.', '' ),
	'payment_status'  => 'paid',
	'payment_gateway' => 'manual',
	'order_number'    => '90801',
	'transaction_id'  => 'seed_oe_stall',
	'reservation_id'  => 5990,
	'notes'           => "Reservation setup ID: 5990",
	'created_at'      => $created,
) );

// ── RV order — reservation 6519 (1 lot, 1 night @ $40, paid) ───────────── //
$rv_sub   = 40.00;
$rv_total = $rv_sub;
$wpdb->insert( $rv, array(
	'event_source'    => 'native',
	'event_id'        => 0,
	'customer_name'   => 'Order Edit Test (RV)',
	'email'           => $rv_email,
	'phone'           => '555-0191',
	'rv_qty'          => 1,
	'rv_type'         => 'Standard',
	'stay_type'       => 'nightly',
	'arrival_date'    => '2026-07-01',
	'departure_date'  => '2026-07-02',
	'unit_price'      => '40.00',
	'subtotal'        => '40.00',
	'convenience_fee' => '0.00',
	'total'           => number_format( $rv_total, 2, '.', '' ),
	'tax'             => '0.00',
	'tax_rate'        => '0.000',
	'amount_paid'     => number_format( $rv_total, 2, '.', '' ),
	'payment_status'  => 'paid',
	'payment_gateway' => 'manual',
	'order_number'    => '90802',
	'transaction_id'  => 'seed_oe_rv',
	'reservation_id'  => 6519,
	'notes'           => "Reservation setup ID: 6519",
	'created_at'      => $created,
) );

// Confirm both resolve as grouped orders with their reservation linkage + addable inventory.
$repo = new EEM_Orders_Repository();
$sc   = new EEM_Shortcodes();
foreach ( (array) $repo->get_orders( '', 'date', 'desc' ) as $o ) {
	if ( in_array( $o['email'] ?? '', array( $stall_email, $rv_email ), true ) ) {
		$inv  = $sc->get_addable_inventory( (int) $o['reservation_id'] );
		$kind = ! empty( $inv['stall'] ) ? 'stall' : ( ! empty( $inv['rv'] ) ? 'rv' : 'none' );
		echo sprintf(
			"  order %s  rid=%d  total=%s  inventory=%s\n",
			$o['order_key'],
			(int) $o['reservation_id'],
			$o['total'],
			$kind
		);
	}
}

echo "Seeded 2 reservation-linked test orders (#90801 stall / 5990, #90802 RV / 6519).\n";
