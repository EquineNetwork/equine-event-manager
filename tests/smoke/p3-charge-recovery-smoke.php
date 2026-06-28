<?php
/**
 * P3 smoke — charge→save crash safety net.
 *
 * Two layers:
 *   1. EEM_Charge_Recovery durable snapshot lifecycle (snapshot/get/exists/orphans/clear).
 *   2. insert_reservation_orders() idempotency by gateway transaction id — a second
 *      insert carrying the SAME transaction (what a recovery retry does after a crash
 *      that left the submission token unmarked) must NOT create a duplicate order.
 *
 * Together these guarantee: a charge that succeeds but whose order failed to save is
 * recorded durably (snapshot), recoverable, surfaced as an orphan after a grace
 * period, and can never be turned into two orders for one charge.
 *
 * Run via: wp eval-file tests/smoke/p3-charge-recovery-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Charge_Recovery' ) || ! class_exists( 'EEM_Shortcodes' ) ) {
	echo "  FAIL — required classes missing\n0 passed, 1 failed\n";
	return;
}

/* ---- 1. Recovery snapshot lifecycle ---- */
$key = 'p3-smoke-' . wp_generate_uuid4();
$txn = 'pi_p3smoke_' . substr( md5( $key ), 0, 12 );
EEM_Charge_Recovery::clear( $key );
$chk( null === EEM_Charge_Recovery::get( $key ), 'no snapshot before write' );

EEM_Charge_Recovery::snapshot( $key, array(
	'type'           => 'checkout',
	'transaction_id' => $txn,
	'gateway'        => 'stripe',
	'amount'         => 123.45,
	'charged_at'     => gmdate( 'Y-m-d H:i:s', time() - 600 ), // 10 min ago → orphan
) );
$snap = EEM_Charge_Recovery::get( $key );
$chk( is_array( $snap ) && $snap['transaction_id'] === $txn, 'snapshot persists + reads back' );
$chk( EEM_Charge_Recovery::exists_for_transaction( $txn ), 'exists_for_transaction finds the txn' );
$chk( ! EEM_Charge_Recovery::exists_for_transaction( 'pi_does_not_exist' ), 'exists_for_transaction false for unknown txn' );

$orphans = EEM_Charge_Recovery::get_orphans( 300 ); // older than 5 min
$found_orphan = false;
foreach ( $orphans as $o ) { if ( isset( $o['transaction_id'] ) && $o['transaction_id'] === $txn ) { $found_orphan = true; } }
$chk( $found_orphan, 'a 10-min-old snapshot surfaces as an orphan (>5 min)' );
$chk( count( EEM_Charge_Recovery::get_orphans( 3600 ) ) < count( $orphans ) + 1 && ! in_array( $txn, array_map( static function ( $o ) { return $o['transaction_id'] ?? ''; }, EEM_Charge_Recovery::get_orphans( 3600 ) ), true ), 'a fresh 10-min snapshot is NOT an orphan under a 1-hour grace' );

EEM_Charge_Recovery::clear( $key );
$chk( null === EEM_Charge_Recovery::get( $key ), 'clear() removes the snapshot' );

/* ---- 2. Idempotent insert by transaction id ---- */
$R = static function ( $name ) { $m = new ReflectionMethod( 'EEM_Shortcodes', $name ); $m->setAccessible( true ); return $m; };
$sc       = new EEM_Shortcodes();
$sanitize = $R( 'sanitize_submission' );
$insert   = $R( 'insert_reservation_orders' );
$getData  = $R( 'get_reservation_data' );

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'P3 Idem', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array( 'stalls_enabled' => 1, 'stall_nightly_rate' => 35.0 ) )->save();
EEM_Reservation_Config::flush_cache( $rid );
$data = $getData->invoke( $sc, $rid );

$shared_txn = 'pi_p3idem_' . substr( md5( (string) $rid ), 0, 12 );
$mk = static function ( $sc, $sanitize ) {
	$GLOBALS_POST = $_POST; $_POST = array();
	$s = $sanitize->invoke( $sc, array( 'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '' ) );
	$_POST = $GLOBALS_POST;
	$s['first_name'] = 'P3'; $s['last_name'] = 'Idem'; $s['email'] = 'p3idem@eem-test.local';
	$s['invoice_type'] = 'customer';
	$s['stall_qty'] = 1; $s['stall_billable_quantity'] = 1; $s['stall_stay_type'] = 'nightly';
	$s['stall_arrival_date'] = '2026-08-19'; $s['stall_departure_date'] = '2026-08-21';
	return $s;
};
$status = array( 'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true );
$pay    = array( 'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'transaction_id' => $shared_txn );

// First insert (token A) — creates the order.
$subA = $mk( $sc, $sanitize ); $subA['submission_token'] = wp_generate_uuid4();
$resA = $insert->invoke( $sc, $rid, $data, $subA, $status, $pay );
$chk( ! empty( $resA['success'] ) && empty( $resA['duplicate'] ), 'first insert creates the order' );
$count_after_first = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eem_stall_reservations WHERE transaction_id = %s", $shared_txn ) );
$chk( 1 === $count_after_first, 'exactly one stall row for the transaction after first insert' );

// Second insert (DIFFERENT token B, SAME transaction) — simulates a recovery retry
// after a crash that left the token unmarked. Must dedup, not duplicate.
$subB = $mk( $sc, $sanitize ); $subB['submission_token'] = wp_generate_uuid4();
$resB = $insert->invoke( $sc, $rid, $data, $subB, $status, $pay );
$chk( ! empty( $resB['success'] ) && ! empty( $resB['duplicate'] ), 'second insert (same txn) reports duplicate, not a new order' );
$count_after_second = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eem_stall_reservations WHERE transaction_id = %s", $shared_txn ) );
$chk( 1 === $count_after_second, 'STILL exactly one stall row — no duplicate order for one charge' );

/* ---- 3. Dashboard surfaces an orphaned charge as the top Needs-Attention row ---- */
if ( class_exists( 'EEM_Dashboard_Repo' ) ) {
	$orphan_key = 'p3-orphan-' . wp_generate_uuid4();
	EEM_Charge_Recovery::snapshot( $orphan_key, array(
		'type'           => 'checkout',
		'transaction_id' => 'pi_orphan_' . substr( md5( $orphan_key ), 0, 10 ),
		'gateway'        => 'stripe',
		'amount'         => 250.00,
		'charged_at'     => gmdate( 'Y-m-d H:i:s', time() - 600 ), // 10 min ago → orphan
	) );
	$attention = ( new EEM_Dashboard_Repo() )->attention_items();
	$has_orphan_row = false;
	foreach ( (array) $attention as $row ) {
		if ( isset( $row['title'] ) && false !== stripos( (string) $row['title'], 'payment taken, no order saved' ) ) {
			$has_orphan_row = true;
		}
	}
	$chk( $has_orphan_row, 'Dashboard Needs-Attention surfaces the orphaned charge' );
	EEM_Charge_Recovery::clear( $orphan_key );
	// After clearing, the row must drop off.
	$attention2 = ( new EEM_Dashboard_Repo() )->attention_items();
	$still = false;
	foreach ( (array) $attention2 as $row ) {
		if ( isset( $row['title'] ) && false !== stripos( (string) $row['title'], 'payment taken, no order saved' ) ) { $still = true; }
	}
	$chk( ! $still, 'orphan row clears once the snapshot is resolved' );
}

// Cleanup.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eem_stall_reservations WHERE transaction_id = %s", $shared_txn ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eem_rv_reservations WHERE transaction_id = %s", $shared_txn ) );
wp_delete_post( $rid, true );

echo "\n$pass passed, $fail failed\n";
