<?php
/**
 * Refund ceiling = actually-collected smoke (bug #20) — BEHAVIORAL.
 *
 * The amount / bulk refund path capped the refundable amount at Σ component base
 * `total`, not the ledger. On a discounted or fee-waived order the base totals
 * exceed what was actually charged, so the bulk-refund step would refund real
 * money that was never collected (over-refund onto the card), then get_net_
 * collected() floored at 0 and masked it.
 *
 * This seeds a real ledger and drives EEM_Refund_Engine::get_order_refundable_
 * ceiling() (the single ceiling now shared by the bulk path + the amount-refund
 * guard), asserting it caps at gross-collected − already-refunded and STAYS
 * correct across a second partial refund (the amount path records refunds in
 * component notes, not the ledger, so multi-refund safety is the tricky part).
 *
 * Run: wp eval-file tests/smoke/refund-ceiling-collected-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;
$ledger_table = $wpdb->prefix . 'eem_order_payments';

$disc_key  = 'refund-ceiling-disc-smoke';
$plain_key = 'refund-ceiling-plain-smoke';

// Clean any prior run.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$ledger_table} WHERE order_key IN (%s, %s)", $disc_key, $plain_key ) );

// Seed ledgers: discounted order collected $60 on a $100 base; plain order $100.
EEM_Order_Payments_Repo::record( array(
	'order_key' => $disc_key, 'direction' => EEM_Order_Payments_Repo::DIRECTION_PAYMENT,
	'method' => 'Card', 'gateway' => 'test', 'amount' => 60.00, 'transaction_id' => 'tst_disc',
) );
EEM_Order_Payments_Repo::record( array(
	'order_key' => $plain_key, 'direction' => EEM_Order_Payments_Repo::DIRECTION_PAYMENT,
	'method' => 'Card', 'gateway' => 'test', 'amount' => 100.00, 'transaction_id' => 'tst_plain',
) );

$repo   = new EEM_Orders_Repository();
$admin  = new EEM_Admin( true );
$engine = new EEM_Refund_Engine( $admin );

// --- gross collected reads the ledger, gross of refunds -----------------------
$check( 'get_gross_collected reads $60 from the discounted-order ledger', abs( $repo->get_gross_collected( $disc_key ) - 60.00 ) < 0.005 );

// --- discounted order: ceiling caps at collected ($60), NOT base ($100) -------
$disc_order = array(
	'order_key'  => $disc_key,
	'components' => array(
		array( 'table' => 'stall', 'row_id' => 1, 'total' => 100.00, 'notes' => '', 'refunded_amount' => 0.0, 'payment_status' => 'paid' ),
	),
);
$ceiling = (float) $engine->get_order_refundable_ceiling( $disc_key, $disc_order );
$check( 'ceiling caps at $60 collected, not $100 base (over-refund blocked)', abs( $ceiling - 60.00 ) < 0.005 );

// --- after a $60 refund recorded on the component, ceiling → $0 (multi-refund)-
$disc_order['components'][0]['notes']           = 'Refunded Amount: 60.00';
$disc_order['components'][0]['refunded_amount'] = 60.00;
$ceiling2 = (float) $engine->get_order_refundable_ceiling( $disc_key, $disc_order );
$check( 'after refunding the $60 collected, ceiling → $0 (no second over-refund)', $ceiling2 < 0.005 );

// --- regression control: plain order (base == collected) still refunds fully --
$plain_order = array(
	'order_key'  => $plain_key,
	'components' => array(
		array( 'table' => 'stall', 'row_id' => 1, 'total' => 100.00, 'notes' => '', 'refunded_amount' => 0.0, 'payment_status' => 'paid' ),
	),
);
$plain_ceiling = (float) $engine->get_order_refundable_ceiling( $plain_key, $plain_order );
$check( 'plain order (no discount): full $100 still refundable (no regression)', abs( $plain_ceiling - 100.00 ) < 0.005 );

// --- partial on the plain order: ceiling shrinks by the refunded amount -------
$plain_order['components'][0]['notes']           = 'Refunded Amount: 30.00';
$plain_order['components'][0]['refunded_amount'] = 30.00;
$plain_ceiling2 = (float) $engine->get_order_refundable_ceiling( $plain_key, $plain_order );
$check( 'plain order after $30 refund: $70 remaining refundable', abs( $plain_ceiling2 - 70.00 ) < 0.005 );

// Cleanup seeded ledger rows.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$ledger_table} WHERE order_key IN (%s, %s)", $disc_key, $plain_key ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
