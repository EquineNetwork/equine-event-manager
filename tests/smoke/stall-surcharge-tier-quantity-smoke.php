<?php
/**
 * Smoke: Stall Surcharge Slice 9 — Quantity-mode per-tier steppers (stall twin
 * of rv-surcharge-tier-quantity-smoke).
 *
 * Covers the server-side stall tier logic end to end with synthetic reservation
 * data (no DB writes): tier capacity from stall-row ranges, capacity-clamped
 * resolution rolling into stall_qty, the per-tier surcharge sum, and the
 * "Stall Tiers:" note round-trip via the shared parse_rv_tiers_note parser.
 *
 * Run: php wp-cli.phar eval-file tests/smoke/stall-surcharge-tier-quantity-smoke.php
 */

if ( ! class_exists( 'EEM_Shortcodes' ) ) {
	echo "FAIL: EEM_Shortcodes not loaded\n";
	return;
}

$sc   = new EEM_Shortcodes();
$pass = 0;
$fail = 0;
$check = function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
};

$call = function ( $method, ...$args ) use ( $sc ) {
	$m = new ReflectionMethod( 'EEM_Shortcodes', $method );
	$m->setAccessible( true );
	return $m->invoke( $sc, ...$args );
};

// Synthetic reservation data: two stall tiers (+ a range-less row that is skipped).
$data = array(
	'stall_rows' => array(
		array( 'name' => 'Standard', 'first' => '1',  'last' => '20', 'nightly_surcharge' => '0.00',  'surcharge' => array( 'nightly' => 0.0,  'packages' => array() ) ),
		array( 'name' => 'Premium',  'first' => '21', 'last' => '25', 'nightly_surcharge' => '15.00', 'surcharge' => array( 'nightly' => 15.0, 'packages' => array() ) ),
		array( 'name' => 'NoRange',  'first' => '',   'last' => '',   'nightly_surcharge' => '5.00',  'surcharge' => array( 'nightly' => 5.0,  'packages' => array() ) ),
	),
);

echo "get_stall_tiers:\n";
$tiers = $call( 'get_stall_tiers', $data, 0 );
$check( 'skips the range-less row (2 tiers, not 3)', count( $tiers ) === 2 );
$check( 'Standard capacity = 20', isset( $tiers[0] ) && 20 === $tiers[0]['capacity'] );
$check( 'Premium capacity = 5',  isset( $tiers[1] ) && 5  === $tiers[1]['capacity'] );
$check( 'Premium surcharge = 15.00', isset( $tiers[1] ) && abs( $tiers[1]['nightly_surcharge'] - 15.0 ) < 0.001 );

echo "resolve_stall_tier_submission (capacity clamp + sums):\n";
// Request 3 Standard + 99 Premium (over Premium's 5 cap). Quantity mode (id=0 default).
$submission = array( 'stall_tier_qty' => array( 0 => 3, 1 => 99 ), 'stall_qty' => 0, 'stall_stay_type' => 'nightly' );
$resolved   = $call( 'resolve_stall_tier_submission', $submission, $data, 0 );
$check( 'stall_qty clamped to 3 + 5 = 8', 8 === (int) $resolved['stall_qty'] );
$check( 'Premium clamped to 5 in selection', isset( $resolved['stall_tier_selection']['Premium'] ) && 5 === $resolved['stall_tier_selection']['Premium'] );
$check( 'surcharge sum = 5×15 = 75 (Standard adds 0)', abs( (float) $resolved['stall_tier_surcharge_sum'] - 75.0 ) < 0.001 );

echo "Stall Tiers note round-trip:\n";
$note = '';
foreach ( $resolved['stall_tier_selection'] as $n => $q ) { $note .= ( $note ? ', ' : '' ) . $n . ' ×' . $q; }
$parsed = $call( 'parse_rv_tiers_note', $note );
$check( 'note parses back to Standard=3', isset( $parsed['Standard'] ) && 3 === $parsed['Standard'] );
$check( 'note parses back to Premium=5',  isset( $parsed['Premium'] )  && 5 === $parsed['Premium'] );

// Guard: get_order_stall_surcharge_total no-ops without a reservation_id (the
// recompute reads reservation data + stay-unit dates from the DB; the live
// Quantity-mode total is verified in-browser against a real reservation).
echo "get_order_stall_surcharge_total guard:\n";
$check( 'returns 0.0 for a reservation-less order', abs( $sc->get_order_stall_surcharge_total( array( 'order_key' => 'x', 'reservation_id' => 0 ) ) ) < 0.001 );

echo "Done. PASS=$pass FAIL=$fail\n";
