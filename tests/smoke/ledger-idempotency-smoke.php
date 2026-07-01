<?php
/**
 * Ledger idempotency smoke (bug #24 — F1/F2/F3), code-only fix.
 *
 * F1 — a retried/replayed Stripe webhook (or an invoice submit racing its own
 *      webhook) must not insert a SECOND ledger row for the same gateway
 *      transaction. EEM_Order_Payments_Repo::record() dedupes on
 *      (order_key, transaction_id, direction) for non-empty transaction_id.
 * F2 — the hosted-invoice submit must WRITE a ledger row (it didn't).
 * F3 — the manual mark-paid path serializes behind a per-order advisory lock
 *      so a double-click can't record the cash payment twice.
 *
 * The dedupe is directly testable; the two advisory-lock races need real
 * concurrency, so those are asserted by source presence.
 *
 * Run: wp eval-file tests/smoke/ledger-idempotency-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;
$ledger = $wpdb->prefix . 'eem_order_payments';
$key    = 'ledger-idem-smoke';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$ledger} WHERE order_key = %s", $key ) );

// --- F1: gateway rows dedupe on (order_key, transaction_id, direction) -------
$id1 = EEM_Order_Payments_Repo::record( array(
	'order_key' => $key, 'direction' => EEM_Order_Payments_Repo::DIRECTION_PAYMENT,
	'method' => 'Card', 'gateway' => 'stripe', 'amount' => 120.00, 'transaction_id' => 'pi_dup_123',
) );
$id2 = EEM_Order_Payments_Repo::record( array(  // replay: same txn id
	'order_key' => $key, 'direction' => EEM_Order_Payments_Repo::DIRECTION_PAYMENT,
	'method' => 'Card', 'gateway' => 'stripe', 'amount' => 120.00, 'transaction_id' => 'pi_dup_123',
) );
$count_pay = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ledger} WHERE order_key = %s AND transaction_id = %s AND direction = %s", $key, 'pi_dup_123', 'payment' ) );
$check( 'duplicate gateway payment (same txn) inserts ONE row', 1 === $count_pay );
$check( 'dedupe returns the existing row id', (int) $id1 === (int) $id2 && $id1 > 0 );

// A REFUND with the same txn id is a different direction → allowed (distinct row).
EEM_Order_Payments_Repo::record( array(
	'order_key' => $key, 'direction' => EEM_Order_Payments_Repo::DIRECTION_REFUND,
	'method' => 'Card', 'gateway' => 'stripe', 'amount' => 20.00, 'transaction_id' => 'pi_dup_123',
) );
$count_ref = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ledger} WHERE order_key = %s AND direction = %s", $key, 'refund' ) );
$check( 'a refund with the same txn id is a distinct row (direction differs)', 1 === $count_ref );

// --- Manual entries (no txn id) are NOT deduped — two cash payments are real -
EEM_Order_Payments_Repo::record( array( 'order_key' => $key, 'direction' => 'payment', 'method' => 'Cash', 'amount' => 5.00 ) );
EEM_Order_Payments_Repo::record( array( 'order_key' => $key, 'direction' => 'payment', 'method' => 'Cash', 'amount' => 5.00 ) );
$count_cash = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ledger} WHERE order_key = %s AND method = %s", $key, 'Cash' ) );
$check( 'manual (no-txn) entries are NOT deduped — 2 cash rows', 2 === $count_cash );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$ledger} WHERE order_key = %s", $key ) );

// --- source presence: F2 invoice ledger row + F1/F3 advisory locks ----------
$sc = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$check( 'F2: mark_invoice_order_paid records a ledger PAYMENT row', (bool) preg_match( '/function mark_invoice_order_paid.*EEM_Order_Payments_Repo::record\(/s', $sc ) );
$check( 'F1: webhook payment_intent.succeeded holds a GET_LOCK', false !== strpos( $sc, "\$eem_wh_lock = 'eem_charge_'" ) && false !== strpos( $sc, "GET_LOCK(%s, 10)" ) );

$repo = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-orders-repository.php' );
$check( 'F3: record_manual_payment holds a GET_LOCK', false !== strpos( $repo, "\$eem_mp_lock = 'eem_charge_'" ) );

$pay = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-order-payments-repo.php' );
$check( 'record() dedupe queries (order_key, transaction_id, direction)', false !== strpos( $pay, "WHERE order_key = %s AND transaction_id = %s AND direction = %s" ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
