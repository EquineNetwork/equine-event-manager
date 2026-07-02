<?php
/**
 * ITEMIZATION RECONCILE MATRIX SMOKE (answers "is the double-count present with
 * other combinations?" — 2026-07-02).
 *
 * The RV-only pre-entry double-count (fixed 2.7.740) belonged to a general class:
 * a charge folded into a component's stored subtotal must be subtracted from that
 * component's base line whenever it is ALSO itemized as its own line, or the receipt
 * over/under-counts. Rather than trust one example, this enumerates the matrix of
 * component configurations × "extra" charges and asserts the load-bearing invariant
 * on EVERY cell:
 *
 *     Σ( build_order_line_items line totals ) == stored pre-fee/tax subtotal.
 *
 * Components : stall-only · RV-only · stall+RV (extras attach to stall when a stall
 *              order exists, else RV — insert_reservation_orders $attach_*_to).
 * Extras     : none · add-on · group charge · pre-entry · all-three-together.
 *
 * 3 × 5 = 15 orders. A double-count (line sum > subtotal) or an omission (line sum <
 * subtotal) on ANY cell trips the smoke. Extras are notes-recoverable so the synthetic
 * harness reconstructs them exactly (surcharge/shavings need a configured reservation
 * and are covered by their own smokes).
 *
 * Run: wp eval-file tests/smoke/itemization-reconcile-matrix-smoke.php
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

$sc  = new EEM_Shortcodes();
$bli = new ReflectionMethod( 'EEM_Shortcodes', 'build_order_line_items' ); $bli->setAccessible( true );
$repo = new EEM_Orders_Repository();

$STALL_BASE = 100.00; $RV_BASE = 80.00;
$EXTRA_AMT  = array( 'addon' => 30.00, 'group' => 20.00, 'preentry' => 45.00 );
$EXTRA_NOTE = array(
	'addon'    => 'Add-On: Golf Cart | Qty: 1 | Subtotal: $30.00',
	'group'    => 'Group Charge: Extra Rider | Qty: 1 | Rate: $20.00 | Subtotal: $20.00',
	'preentry' => 'Pre-Entry: #10.5 Division | Qty: 1 | Subtotal: $45.00',
);

$configs = array( 'stall-only', 'rv-only', 'stall+rv' );
$extra_sets = array(
	'none'     => array(),
	'addon'    => array( 'addon' ),
	'group'    => array( 'group' ),
	'preentry' => array( 'preentry' ),
	'all3'     => array( 'addon', 'group', 'preentry' ),
);

$emails = array();
$cases  = array(); // key => [label, expected_subtotal]
$n = 0;

// Seed EVERY cell up front (the repo caches its order list on first lookup).
try {
	foreach ( $configs as $config ) {
		foreach ( $extra_sets as $set_name => $set ) {
			$n++;
			$email    = "matrix{$n}@eem-test.local";
			$emails[] = $email;
			$token    = md5( $email . wp_generate_password( 10, false ) );
			$has_stall = ( 'rv-only' !== $config );
			$has_rv    = ( 'stall-only' !== $config );

			$extras_sum = 0.0;
			$extra_lines = '';
			foreach ( $set as $ex ) {
				$extras_sum  += $EXTRA_AMT[ $ex ];
				$extra_lines .= "\n" . $EXTRA_NOTE[ $ex ];
			}
			$expected_subtotal = ( $has_stall ? $STALL_BASE : 0.0 ) + ( $has_rv ? $RV_BASE : 0.0 ) + $extras_sum;

			// Extras attach to the stall component when it exists, else RV.
			$extras_on_stall = $has_stall;
			$base_notes = "Reservation setup ID: 0\nSubmission token: {$token}";
			$stall_notes = $base_notes . ( $extras_on_stall ? $extra_lines : '' );
			$rv_notes    = $base_notes . ( ! $extras_on_stall ? $extra_lines : '' );

			$common = array(
				'event_source' => 'tec', 'event_id' => 960000 + $n, 'external_event_id' => '',
				'customer_name' => "Matrix {$n}", 'email' => $email, 'phone' => '5550005555',
				'stay_type' => 'nightly', 'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
				'unit_price' => 40.00, 'convenience_fee' => 0.00, 'tax' => 0.00, 'tax_rate' => 0.000,
				'payment_status' => 'completed', 'payment_gateway' => 'stripe',
				'order_number' => (string) ( 91000 + $n ), 'transaction_id' => "ch_mx{$n}", 'refund_transaction_id' => '',
				'created_at' => sprintf( '2026-03-%02d 10:30:00', $n ),
			);
			if ( $has_stall ) {
				$ss = $STALL_BASE + ( $extras_on_stall ? $extras_sum : 0.0 );
				$wpdb->insert( $wpdb->prefix . 'eem_stall_reservations', array_merge( $common, array(
					'stall_qty' => 1, 'tack_stall_qty' => 0, 'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
					'subtotal' => $ss, 'total' => $ss, 'notes' => $stall_notes,
				) ) );
			}
			if ( $has_rv ) {
				$rs = $RV_BASE + ( ! $extras_on_stall ? $extras_sum : 0.0 );
				$wpdb->insert( $wpdb->prefix . 'eem_rv_reservations', array_merge( $common, array(
					'rv_qty' => 1, 'rv_type' => '',
					'subtotal' => $rs, 'total' => $rs, 'notes' => $rv_notes,
				) ) );
			}
			$cases[ md5( sanitize_text_field( $token ) ) ] = array( "{$config} + {$set_name}", $expected_subtotal );
		}
	}

	// Now reconcile every cell.
	foreach ( $cases as $key => $info ) {
		list( $label, $expected ) = $info;
		$order = $repo->get_order_by_order_key( $key );
		if ( ! is_array( $order ) ) { $chk( false, "{$label}: order loaded" ); continue; }
		$order['event_name'] = $order['reservation_title'] = "Matrix {$label}";
		$items = $bli->invoke( $sc, $order, false );
		$sum = 0.0;
		foreach ( $items as $it ) { $sum += $money( $it['total'] ); }
		$chk( $approx( $sum, $expected ), sprintf( '%s: Σ line totals $%.2f == subtotal $%.2f', $label, $sum, $expected ) );
	}
} finally {
	if ( $emails ) {
		$in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$t} WHERE email IN ($in)", $emails ) );
		}
	}
}

echo "\n" . ( 0 === $FAIL ? 'OK' : 'FAILURES' ) . " — {$PASS} passed, {$FAIL} failed\n";
if ( $FAIL > 0 ) { echo 'Failures: ' . implode( '; ', $FAILS ) . "\n"; exit( 1 ); }
