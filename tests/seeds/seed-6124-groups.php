<?php
/**
 * Dev seed: grouped + assigned orders on the map-connected reservation 6124
 * (Montcrief + Burnett) so v4 Slice 6 (group identity) and Slice 7
 * (group-contiguous auto-assign) can be verified against real data.
 *
 * Idempotent: deletes any prior seed rows (transaction_id LIKE 'eemseed6124-%')
 * before re-inserting. Enables Group Reservations on 6124 so the checkout Group
 * Name field + datalist render.
 *
 * Run: {php} {wpcli.phar} --path={wp} eval-file tests/seeds/seed-6124-groups.php
 */

global $wpdb;
$rid   = 6124;
$table = $wpdb->prefix . 'en_stall_reservations';

// Enable Group Reservations so the checkout group field + datalist render.
update_post_meta( $rid, '_en_group_reservations_enabled', '1' );

// Wipe prior seed rows so re-running is clean.
$wpdb->query( "DELETE FROM {$table} WHERE transaction_id LIKE 'eemseed6124-%'" );

// Each entry: customer, group (or ''), qty, assigned stalls (or [] = unassigned),
// tack stalls (or []). Groups left UNASSIGNED give Slice 7 contiguous-fill work.
$orders = array(
	array( 'Smith, James',     'Smith Barn',     1, array(),        array() ),
	array( 'Smith, Dale',      'Smith Barn',     1, array(),        array() ),
	array( 'Smith, Rebecca',   'Smith Barn',     1, array(),        array() ),
	array( 'Owens, Dana',      'smith barn ',    1, array(),        array() ), // misspelled variant -> reconcile
	array( 'Lee, Maria',       '4-H Team',       1, array(),        array() ),
	array( 'Nguyen, Thanh',    '4-H Team',       1, array(),        array() ),
	array( 'Patel, Rohan',     'Patel Stables',  2, array(),        array() ),
	array( 'Carter, Logan',    '',               1, array( '5050' ), array() ),       // ungrouped, assigned
	array( 'Brooks, Kelly',    '',               1, array( '5051' ), array( '5051' ) ), // assigned + tack
);

$created = current_time( 'mysql' );
$n       = 0;
foreach ( $orders as $i => $o ) {
	list( $name, $group, $qty, $assigned, $tack ) = $o;
	// Submission token must match the repo's [a-f0-9-]+ grouping regex, so it
	// has to be hex. transaction_id carries the human-readable cleanup marker.
	$token  = sprintf( 'dead6124-%04d', $i + 1 );
	$txn    = 'eemseed6124-' . ( $i + 1 );
	$number = 90000 + $i + 1;

	$notes = "Reservation: 6124";
	if ( '' !== trim( $group ) ) {
		$notes .= "\nGroup Name: " . $group;
	}
	if ( ! empty( $assigned ) ) {
		$notes .= "\nAssigned Stall Units: " . implode( ', ', $assigned );
	}
	if ( ! empty( $tack ) ) {
		$notes .= "\nTack Stalls: " . implode( ', ', $tack );
	}
	$notes .= "\nReservation setup ID: " . $rid;
	$notes .= "\nSubmission token: " . $token;

	$wpdb->insert(
		$table,
		array(
			'event_source'          => 'native',
			'event_id'              => 0,
			'external_event_id'     => '',
			'customer_name'         => $name,
			'email'                 => 'seed' . ( $i + 1 ) . '@example.test',
			'phone'                 => '+1 555 0100',
			'stall_qty'             => $qty,
			'tack_stall_qty'        => count( $tack ),
			'stay_type'             => 'nightly',
			'arrival_date'          => '2026-06-26',
			'departure_date'        => '2026-06-28',
			'required_shavings_qty' => 0,
			'additional_shavings_qty' => 0,
			'unit_price'            => 50.00,
			'subtotal'              => 50.00 * $qty,
			'convenience_fee'       => 0.00,
			'total'                 => 50.00 * $qty,
			'payment_status'        => 'paid',
			'payment_gateway'       => 'manual',
			'order_number'          => (string) $number,
			'transaction_id'        => $txn,
			'refund_transaction_id' => '',
			'refunded_at'           => null,
			'notes'                 => $notes,
			'created_at'            => $created,
			'tax'                   => 0.00,
			'tax_rate'              => 0.00,
		)
	);
	$n++;
}

echo "Seeded {$n} orders on reservation {$rid}. Group Reservations enabled.\n";
