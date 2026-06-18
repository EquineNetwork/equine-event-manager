<?php
/**
 * Smoke: Order Edit Phase 2 — append-to-order engine.
 *
 * Exercises the two halves of the engine:
 *   - EEM_Shortcodes::price_base_rate_addition() — base-rate pricing for an
 *     added quantity of a reservation's stall/RV inventory (rate × units × qty
 *     + fee + tax), returning the fee/tax config used.
 *   - EEM_Orders_Repository::add_component_quantity() — persists the addition:
 *     bumps an existing section row (subtotal/qty/fee/tax/total) or clones a new
 *     one, leaving amount_paid untouched so the delta surfaces as balance due.
 *
 * Pricing is asserted read-only against reservation 5990 (known $45 nightly).
 * Persistence is asserted via before/after deltas on a live stall order, so the
 * assertions hold regardless of the order's starting balance (re-run safe).
 *
 * Run:
 *   php wp-cli.phar eval-file tests/smoke/order-edit-append-engine-smoke.php
 */

if ( ! class_exists( 'EEM_Shortcodes' ) || ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "FAIL: required classes not loaded\n";
	return;
}

$pass = 0;
$fail = 0;
$check = function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
};

/* ---- Pricing (read-only, deterministic) ---- */
$sc = new EEM_Shortcodes();
$p  = $sc->price_base_rate_addition( 5990, 'stall', 2, 'nightly', '2026-07-03', '2026-07-04' );

$check( 'pricing returns an array', is_array( $p ) );
$check( 'unit_price = 45 (res 5990 nightly)', abs( (float) $p['unit_price'] - 45.0 ) < 0.001 );
$check( 'nights = 1 (single-night span)', (int) $p['nights'] === 1 );
$check( 'subtotal = qty × unit × nights = 90', abs( (float) $p['subtotal'] - 90.0 ) < 0.001 );

// Guard: invalid reservation / zero qty returns a zeroed shape, never fatals.
$z = $sc->price_base_rate_addition( 0, 'stall', 2, 'nightly' );
$check( 'reservation_id < 1 yields subtotal 0 (guarded)', abs( (float) $z['subtotal'] ) < 0.001 );

/* ---- Persistence (before/after deltas on a live stall order) ---- */
$repo   = new EEM_Orders_Repository();
$orders = $repo->get_orders( '', 'date', 'desc' );
$target = null;
foreach ( (array) $orders as $o ) {
	if ( (int) $o['reservation_id'] > 0 && (int) $o['stall_quantity'] > 0 && 'cancelled' !== $o['status_slug'] ) {
		$target = $o;
		break;
	}
}

if ( ! $target ) {
	echo "  skip - no stall order with reservation_id found for persistence test\n";
} else {
	$key       = $target['order_key'];
	$before    = $repo->get_order( $key );
	$priced    = array(
		'subtotal'    => 90.0,
		'unit_price'  => 45.0,
		'nights'      => 1,
		'tax_rate'    => 0.0,
		'fee_enabled' => false,
		'fee_type'    => 'none',
		'fee_value'   => 0.0,
		'stay_type'   => 'nightly',
		'arrival'     => '',
		'departure'   => '',
	);
	$ok    = $repo->add_component_quantity( $key, 'stall', 2, $priced );
	$after = $repo->get_order( $key );

	$check( 'add_component_quantity returns true', true === $ok );
	$check( 'stall_quantity rises by 2', (int) $after['stall_quantity'] - (int) $before['stall_quantity'] === 2 );
	$check( 'total rises by 90 (subtotal, tax 0)', abs( ( (float) $after['total'] - (float) $before['total'] ) - 90.0 ) < 0.001 );
	$check( 'amount_paid unchanged (addition bills as balance)', abs( (float) $after['amount_paid'] - (float) $before['amount_paid'] ) < 0.001 );
	$check( 'amount_due rises by 90', abs( ( (float) $after['amount_due'] - (float) $before['amount_due'] ) - 90.0 ) < 0.001 );
	$check( 'balance invariant holds after add', abs( (float) $after['amount_due'] - max( 0.0, (float) $after['total'] - (float) $after['amount_paid'] ) ) < 0.001 );
}

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
