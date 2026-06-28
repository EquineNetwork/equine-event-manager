<?php
/**
 * Assignment cross-order conflict smoke (inventory audit MED-2 + LOW-1 fixes).
 *
 * The Order Detail manual-override handler previously wrote the admin's selected
 * stalls/lots verbatim with NO check that they were already assigned to another
 * order on the reservation — so two admins editing two orders could double-book
 * the same stall (the lock serialized the writes but neither rejected the clash).
 * This proves the new `find_assignment_conflict()` actually detects the clash,
 * excludes the order being edited, and ignores empty selections — plus the two
 * fail-SAFE-on-lock-timeout source guards (LOW-1).
 *
 * Run: wp eval-file tests/smoke/assignment-conflict-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;
$st   = $wpdb->prefix . 'eem_stall_reservations';
$repo = new EEM_Orders_Repository();
$suf  = substr( md5( (string) wp_rand() ), 0, 6 );

// --- seed a reservation + two paid stall orders on it -----------------------
$res_id = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'AC Smoke Res ' . $suf ) );

$mk = static function ( $tok, $num ) use ( $wpdb, $st, $res_id ) {
	$wpdb->insert( $st, array(
		'reservation_id' => $res_id,
		'event_source' => 'native', 'event_id' => 0,
		'customer_name' => 'AC ' . $tok, 'email' => $tok . '@example.com', 'phone' => '555-0100',
		'stall_qty' => 2, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
		'unit_price' => '25.00', 'subtotal' => '50.00', 'total' => '50.00',
		'payment_status' => 'paid', 'payment_gateway' => 'native', 'order_number' => $num,
		'transaction_id' => '', 'notes' => "Reservation setup ID: {$res_id}\nSubmission token: {$tok}",
		'created_at' => current_time( 'mysql' ),
	) );
	return (int) $wpdb->insert_id;
};
$rowA = $mk( 'tok_ac_a_' . $suf, '99' . substr( $suf, 0, 3 ) );
$rowB = $mk( 'tok_ac_b_' . $suf, '98' . substr( $suf, 0, 3 ) );

$orderA = $repo->get_order_by_submission_token( 'tok_ac_a_' . $suf );
$orderB = $repo->get_order_by_submission_token( 'tok_ac_b_' . $suf );
$keyA   = $orderA ? $orderA['order_key'] : '';
$keyB   = $orderB ? $orderB['order_key'] : '';
$check( 'seeded two orders on the reservation', '' !== $keyA && '' !== $keyB && $keyA !== $keyB );
$check( 'orders carry the reservation_id', (int) ( $orderA['reservation_id'] ?? 0 ) === $res_id );

// Assign stall "901" to order A via the canonical repo writer (real note format).
$repo->update_order_unit_assignments( $keyA, '901', '' );

// --- exercise find_assignment_conflict via reflection -----------------------
$ref   = new ReflectionClass( 'EEM_Admin' );
$admin = method_exists( 'EEM_Admin', 'for_compute' ) ? EEM_Admin::for_compute() : $ref->newInstanceWithoutConstructor();
$fac   = $ref->getMethod( 'find_assignment_conflict' );
$fac->setAccessible( true );

$conflict_b_901 = (string) $fac->invoke( $admin, $res_id, $keyB, '901', '' );
$check( 'CONFLICT: assigning 901 to order B is rejected (901 is order A\'s)', '' !== $conflict_b_901 );
$check( 'conflict message names the stall', false !== strpos( $conflict_b_901, '901' ) );

$conflict_b_902 = (string) $fac->invoke( $admin, $res_id, $keyB, '902', '' );
$check( 'NO conflict: assigning a free stall 902 to order B', '' === $conflict_b_902 );

$conflict_a_901 = (string) $fac->invoke( $admin, $res_id, $keyA, '901', '' );
$check( 'NO conflict: order A re-saving its OWN 901 (self excluded)', '' === $conflict_a_901 );

$conflict_empty = (string) $fac->invoke( $admin, $res_id, $keyB, '', '' );
$check( 'NO conflict: empty selection short-circuits', '' === $conflict_empty );

$conflict_multi = (string) $fac->invoke( $admin, $res_id, $keyB, '902, 901', '' );
$check( 'CONFLICT: a multi-unit selection that includes 901 is rejected', '' !== $conflict_multi );

// --- source guards: fail-SAFE on lock timeout (LOW-1) + override calls the check
$admin_src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'override handler refuses when lock not acquired',
	1 === preg_match( '/handle_update_order_assignments.*?if \( ! \$this->acquire_assignment_lock/s', $admin_src ) );
$check( 'override handler runs the conflict check before writing',
	false !== strpos( $admin_src, 'find_assignment_conflict( $assign_res_id, $order_key' ) );
$check( 'generate handler refuses when lock not acquired',
	1 === preg_match( '/handle_generate_stall_assignments.*?if \( ! \$this->acquire_assignment_lock/s', $admin_src ) );

// --- cleanup -----------------------------------------------------------------
$wpdb->delete( $st, array( 'id' => $rowA ) );
$wpdb->delete( $st, array( 'id' => $rowB ) );
wp_delete_post( (int) $res_id, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
