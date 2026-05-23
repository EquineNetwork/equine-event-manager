<?php
/**
 * C5.G.1 — Orders test data seeder.
 *
 * Generates 25 orders in wp_en_stall_reservations + wp_en_rv_reservations
 * covering all 6 payment-status variants × 5 type combinations so the
 * Orders list page surfaces every billing tab, every type-badge, and
 * triggers pagination (per_page=10 → 3 pages).
 *
 * Idempotent — deletes prior SEED-* rows before inserting fresh ones.
 *
 * KNOWN LIMITATION: legacy EEM_Orders_Repository::get_order_status_display()
 * has no 'cancelled' arm — Cancelled-status seed rows will display as
 * Unpaid until a future chunk teaches the legacy repo the cancelled slug.
 * Seed rows still created to keep the count + cleanup story symmetric.
 *
 * Re-runnable: re-running cleans + re-seeds in one pass.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
global $wpdb;
$stall_table = $wpdb->prefix . 'en_stall_reservations';
$rv_table    = $wpdb->prefix . 'en_rv_reservations';

echo "\n=== C5.G.1 ORDERS SEEDER ===\n";

$del_stall = $wpdb->query( $wpdb->prepare( "DELETE FROM `$stall_table` WHERE order_number LIKE %s", 'SEED-%' ) );
$del_rv    = $wpdb->query( $wpdb->prepare( "DELETE FROM `$rv_table`    WHERE order_number LIKE %s", 'SEED-%' ) );
echo "Cleaned prior SEED-* rows: stall={$del_stall}, rv={$del_rv}\n";

$events = array(
	array( 'id' => 0, 'ext' => 'SEED-EVT-SORT',      'label' => '2026 Southeast Region Super Sort' ),
	array( 'id' => 0, 'ext' => 'SEED-EVT-BLUEGRASS', 'label' => '2026 Bluegrass Show' ),
	array( 'id' => 0, 'ext' => 'SEED-EVT-DRESSAGE',  'label' => '2026 Sunshine Dressage' ),
);

$customers = array(
	'Whitney Mitchell', 'James Hartwell', 'Sara Calloway', 'Marcus Trevino',
	'Devon Lacroix', 'Priya Anand', 'Ada Lovelace', 'Grace Hopper',
	'Alan Turing', 'Donald Knuth', 'Linus Torvalds', 'Margaret Hamilton',
	'Barbara Liskov', 'Brian Kernighan', 'Bjarne Stroustrup', 'Vint Cerf',
	'Tim Berners-Lee', 'Ken Thompson', 'Dennis Ritchie', 'Rob Pike',
	'Anders Hejlsberg', 'Guido van Rossum', 'Larry Wall', 'Yukihiro Matsumoto', 'Niklaus Wirth',
);

// [payment_status, type_combo, customer_idx, event_idx, days_ago]
$specs = array(
	// 8 Paid — covers all 5 type combinations + a few repeats.
	array( 'paid',               'stall',          0, 0,  1 ),
	array( 'paid',               'stall_addon',    1, 0,  2 ),
	array( 'paid',               'rv',             2, 0,  3 ),
	array( 'paid',               'stall_rv',       3, 0,  5 ),
	array( 'paid',               'stall_rv_addon', 4, 0,  7 ),
	array( 'paid',               'group_rv',       5, 1, 10 ),
	array( 'paid',               'stall',          6, 1, 12 ),
	array( 'paid',               'stall_addon',    7, 2, 14 ),
	// 5 Unpaid (payment_status='pending' falls through to 'unpaid')
	array( 'pending',            'stall',          8, 0, 16 ),
	array( 'pending',            'stall_addon',    9, 0, 18 ),
	array( 'pending',            'rv',            10, 1, 20 ),
	array( 'pending',            'stall_rv',      11, 1, 22 ),
	array( 'pending',            'group_rv',      12, 2, 24 ),
	// 4 Invoice Sent
	array( 'invoice_sent',       'stall_addon',   13, 0, 26 ),
	array( 'invoice_sent',       'rv',            14, 0, 28 ),
	array( 'invoice_sent',       'stall',         15, 1, 30 ),
	array( 'invoice_sent',       'stall_rv_addon',16, 2, 32 ),
	// 3 Partially Refunded
	array( 'partially_refunded', 'stall_addon',   17, 0, 34 ),
	array( 'partially_refunded', 'rv',            18, 1, 36 ),
	array( 'partially_refunded', 'stall',         19, 2, 38 ),
	// 2 Refunded
	array( 'refunded',           'stall',         20, 0, 40 ),
	array( 'refunded',           'rv',            21, 1, 42 ),
	// 3 Cancelled (renders as Unpaid until legacy learns 'cancelled')
	array( 'cancelled',          'addon',         22, 0, 44 ),
	array( 'cancelled',          'stall',         23, 1, 46 ),
	array( 'cancelled',          'rv',            24, 2, 48 ),
);

$inserted_stall = 0;
$inserted_rv    = 0;
foreach ( $specs as $i => $spec ) {
	list( $status, $combo, $cust_idx, $evt_idx, $days_ago ) = $spec;
	$customer = $customers[ $cust_idx ];
	$email    = strtolower( str_replace( array( ' ', '-' ), array( '.', '' ), $customer ) ) . '@example.com';
	$event    = $events[ $evt_idx ];
	$order_no = sprintf( 'SEED-%03d', $i + 1 );
	$created  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days" ) );
	$arrival  = gmdate( 'Y-m-d', strtotime( "+{$days_ago} days" ) );
	$departure= gmdate( 'Y-m-d', strtotime( "+" . ( $days_ago + 2 ) . " days" ) );

	$needs_stall = in_array( $combo, array( 'stall', 'stall_addon', 'stall_rv', 'stall_rv_addon' ), true );
	$needs_rv    = in_array( $combo, array( 'rv', 'stall_rv', 'stall_rv_addon', 'group_rv' ), true );
	$needs_addon = in_array( $combo, array( 'stall_addon', 'stall_rv_addon', 'addon' ), true );
	$is_group    = in_array( $combo, array( 'group_rv' ), true );

	$notes = "Seed order spec={$combo}";
	if ( $is_group ) {
		$notes .= "\nGroup Reservation: Yes";
	}

	$base = array(
		'event_source'            => 'native',
		'event_id'                => (int) $event['id'],
		'external_event_id'       => $event['ext'],
		'customer_name'           => $customer,
		'email'                   => $email,
		'phone'                   => '',
		'stay_type'               => 'nightly',
		'arrival_date'            => $arrival,
		'departure_date'          => $departure,
		'required_shavings_qty'   => $needs_addon ? 2 : 0,
		'additional_shavings_qty' => 0,
		'unit_price'              => 25.00,
		'subtotal'                => 100.00,
		'convenience_fee'         => 3.00,
		'total'                   => 103.00,
		'payment_status'          => $status,
		'payment_gateway'         => 'stripe',
		'order_number'            => $order_no,
		'transaction_id'          => 'SEED-TXN-' . ( $i + 1 ),
		'refund_transaction_id'   => '',
		'refunded_at'             => null,
		'notes'                   => $notes,
		'created_at'              => $created,
	);

	if ( $needs_stall ) {
		$row = $base;
		$row['stall_qty'] = 2;
		$row['tack_stall_qty'] = 1;
		$res = $wpdb->insert( $stall_table, $row );
		if ( false === $res ) { echo "FAIL stall insert {$order_no}: {$wpdb->last_error}\n"; }
		else { $inserted_stall++; }
	} elseif ( $needs_addon && ! $needs_rv ) {
		// Add-On-only path: a stall-table row with 0 stall qty + shavings.
		$row = $base;
		$row['stall_qty'] = 0;
		$row['tack_stall_qty'] = 0;
		$res = $wpdb->insert( $stall_table, $row );
		if ( false === $res ) { echo "FAIL addon-only insert {$order_no}: {$wpdb->last_error}\n"; }
		else { $inserted_stall++; }
	}

	if ( $needs_rv ) {
		// RV table has a different column set than stall: no
		// required_shavings_qty / additional_shavings_qty / tack_stall_qty.
		// Adds rv_qty + rv_type. Build a fresh dictionary from $base.
		$row = $base;
		unset( $row['required_shavings_qty'], $row['additional_shavings_qty'] );
		$row['rv_qty']  = 1;
		$row['rv_type'] = 'standard';
		$res = $wpdb->insert( $rv_table, $row );
		if ( false === $res ) { echo "FAIL rv insert {$order_no}: {$wpdb->last_error}\n"; }
		else { $inserted_rv++; }
	}
}

echo "Inserted: stall={$inserted_stall}, rv={$inserted_rv} (across " . count( $specs ) . " orders)\n";

$repo = new EEM_Orders_Repository();
$all  = $repo->get_orders();
echo "Repo now reports " . count( $all ) . " total orders\n";

echo "\nBilling-tab counts:\n";
$counts = EEM_Orders_List_Repo::counts_by_billing_status();
foreach ( $counts as $tab => $n ) {
	echo "  {$tab}: {$n}\n";
}

echo "\n=== SEEDER DONE ===\n";
