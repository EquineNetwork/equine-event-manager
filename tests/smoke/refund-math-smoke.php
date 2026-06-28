<?php
/**
 * Refund math smoke — validates the money math of the refund kernel
 * (EEM_Refund_Engine) WITHOUT touching a payment gateway. Covers the parts that
 * decide "how much can be refunded" and "how much has been refunded":
 *   - remaining refundable = component total − already refunded
 *   - persist_component_refund accumulates the ledger + flips status
 *     (paid → partially_refunded → refunded) at the right thresholds
 *   - the over-refund guard rejects a request above the remaining balance
 *     (this check runs BEFORE any gateway call, so it's safe to exercise)
 *
 * Refund-amount correctness was a major pain point; this locks the arithmetic.
 * (The actual gateway dispatch — Stripe/Authorize.net — is integration-tested
 * separately and intentionally not called here.)
 *
 * Run via: wp eval-file tests/smoke/refund-math-smoke.php
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

$admin  = new EEM_Admin( true ); // skip_hooks
$engine = new EEM_Refund_Engine( $admin );
$repo   = new EEM_Orders_Repository();
$table  = $wpdb->prefix . 'en_stall_reservations';
$email1 = 'refund@example.com';
$email2 = 'guard@example.com';

// Clean any prior run + seed a paid stall order: total $103 ($100 + $3 fee).
// NOTE: order_key is NOT a stored column on the component tables — it's the
// computed md5(build_group_key) the read path + refund engine resolve by. Seed
// the row, then derive the real key the same way get_order() does.
$wpdb->delete( $table, array( 'email' => $email1 ) );
$wpdb->insert( $table, array(
	'event_source'    => 'native',
	'event_id'        => 0,
	'customer_name'   => 'Refund Tester',
	'email'           => 'refund@example.com',
	'phone'           => '',
	'stay_type'       => 'nightly',
	'arrival_date'    => '2026-08-19',
	'departure_date'  => '2026-08-23',
	'stall_qty'       => 2,
	'tack_stall_qty'  => 0,
	'unit_price'      => 25.00,
	'subtotal'        => 100.00,
	'convenience_fee' => 3.00,
	'total'           => 103.00,
	'refunded_amount' => 0.00,
	'payment_status'  => 'paid',
	'payment_gateway' => 'stripe',
	'order_number'    => 999001,
	'transaction_id'  => 'SEED-REFUND-TXN',
	'notes'           => '',
	'created_at'      => '2026-06-01 00:00:00',
) );
$row_id = (int) $wpdb->insert_id;
$chk( $row_id > 0, 'seeded paid stall order ($103 total)' );
$seed1 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $row_id ), ARRAY_A ); // phpcs:ignore
$okey  = $repo->order_key_for_row( (array) $seed1 );

$component = static function () use ( $wpdb, $table, $row_id ) {
	$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $row_id ), ARRAY_A ); // phpcs:ignore
	$r['table']  = 'stall';
	$r['row_id'] = $row_id;
	return $r;
};

// Fresh: remaining = full total.
$chk( $approx( $engine->get_component_remaining_refundable_amount( $component() ), 103.0 ), 'fresh remaining refundable = $103' );
$chk( $approx( $engine->get_component_refunded_amount( $component() ), 0.0 ), 'fresh refunded-to-date = $0' );

// Partial refund of $40 (ledger only — no gateway).
$engine->persist_component_refund( $component(), 40.0, 'TXN-PARTIAL', array() );
$c = $component();
$chk( $approx( $engine->get_component_refunded_amount( $c ), 40.0 ), 'after $40 refund: refunded-to-date = $40' );
$chk( $approx( $engine->get_component_remaining_refundable_amount( $c ), 63.0 ), 'after $40 refund: remaining = $63' );
$chk( 'partially_refunded' === $c['payment_status'], 'after partial refund: status = partially_refunded' );

// Refund the remaining $63 → fully refunded.
$engine->persist_component_refund( $component(), 63.0, 'TXN-REST', array() );
$c = $component();
$chk( $approx( $engine->get_component_refunded_amount( $c ), 103.0 ), 'after rest: refunded-to-date = $103' );
$chk( $approx( $engine->get_component_remaining_refundable_amount( $c ), 0.0 ), 'after rest: remaining = $0' );
$chk( 'refunded' === $c['payment_status'], 'fully refunded: status = refunded' );

// Order-level remaining helper agrees (now $0).
$chk( $approx( $admin->get_order_remaining_refundable( $okey ), 0.0 ), 'order remaining refundable = $0 when fully refunded' );

// ── Over-refund guard (runs before any gateway call) ────────────────────────
$wpdb->delete( $table, array( 'email' => $email2 ) );
$wpdb->insert( $table, array(
	'event_source' => 'native', 'event_id' => 0,
	'customer_name' => 'Guard Tester', 'email' => $email2, 'phone' => '',
	'stay_type' => 'nightly', 'arrival_date' => '2026-08-19', 'departure_date' => '2026-08-23',
	'stall_qty' => 1, 'tack_stall_qty' => 0, 'unit_price' => 50.0, 'subtotal' => 50.0,
	'convenience_fee' => 2.0, 'total' => 52.0, 'refunded_amount' => 0.0, 'payment_status' => 'paid',
	'payment_gateway' => 'stripe', 'order_number' => 999002, 'transaction_id' => 'SEED-GUARD-TXN',
	'notes' => '', 'created_at' => '2026-06-01 00:00:00',
) );
$id2   = (int) $wpdb->insert_id;
$seed2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id2 ), ARRAY_A ); // phpcs:ignore
$okey2 = $repo->order_key_for_row( (array) $seed2 );

// The order rows were seeded with direct $wpdb writes, which bypass the repo's
// per-instance grouped-orders memo. Use fresh instances so the order-level
// lookups below read the just-seeded order rather than the stale cache built
// during the order-1 assertions above. (Production creates orders through the
// repo, which invalidates the memo on write, so this is a smoke-only concern.)
$admin  = new EEM_Admin( true );
$engine = new EEM_Refund_Engine( $admin );
$chk( $approx( $admin->get_order_remaining_refundable( $okey2 ), 52.0 ), 'second order remaining = $52' );
$over = $engine->process_amount_refund( $okey2, 9999.0, 'over-refund attempt' );
$chk( is_wp_error( $over ) && 'exceeds_remaining' === $over->get_error_code(), 'over-refund ($9999 vs $52) rejected by guard, no gateway call' );
$zero = $engine->process_amount_refund( $okey2, 0.0, 'zero' );
$chk( is_wp_error( $zero ) && 'invalid_amount' === $zero->get_error_code(), 'zero-amount refund rejected' );

// cleanup
$wpdb->delete( $table, array( 'email' => $email1 ) );
$wpdb->delete( $table, array( 'email' => $email2 ) );

echo "\nDone. PASS=$pass FAIL=$fail\n";
