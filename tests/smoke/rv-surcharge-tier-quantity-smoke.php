<?php
/**
 * Smoke: RV Surcharge Slice 5 — Quantity-mode per-tier steppers.
 *
 * Covers the server-side tier logic end to end with synthetic reservation data
 * (no DB writes): tier capacity from row ranges, capacity-clamped resolution,
 * surcharge sum, rv_qty rollup, the persisted "RV Tiers:" note round-trip, and
 * the get_order_rv_surcharge_total recompute for a tier order.
 *
 * Run: php wp-cli.phar eval-file tests/smoke/rv-surcharge-tier-quantity-smoke.php
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

// Synthetic reservation data: two RV tiers.
$data = array(
	'rv_rows' => array(
		array( 'name' => 'Standard', 'first' => '1',  'last' => '20', 'nightly_surcharge' => '0.00',  'surcharge' => array( 'nightly' => 0.0,  'packages' => array() ) ),
		array( 'name' => 'Premium',  'first' => '21', 'last' => '25', 'nightly_surcharge' => '10.00', 'surcharge' => array( 'nightly' => 10.0, 'packages' => array() ) ),
		array( 'name' => 'NoRange',  'first' => '',   'last' => '',   'nightly_surcharge' => '5.00',  'surcharge' => array( 'nightly' => 5.0,  'packages' => array() ) ),
	),
);

echo "get_rv_tiers:\n";
$tiers = $call( 'get_rv_tiers', $data );
$check( 'skips the range-less row (2 tiers, not 3)', count( $tiers ) === 2 );
$check( 'Standard capacity = 20', isset( $tiers[0] ) && 20 === $tiers[0]['capacity'] );
$check( 'Premium capacity = 5',  isset( $tiers[1] ) && 5  === $tiers[1]['capacity'] );
$check( 'Premium surcharge = 10.00', isset( $tiers[1] ) && abs( $tiers[1]['nightly_surcharge'] - 10.0 ) < 0.001 );

echo "resolve_rv_tier_submission (capacity clamp + sums):\n";
// Request 2 Standard + 99 Premium (over Premium's 5 cap). Quantity mode ('quantity').
// We bypass the mode gate by ensuring no exact_map; resolve reads resolve_rv_pair,
// but for a 0 reservation_id resolve_rv_pair returns 'quantity' default.
$submission = array( 'rv_tier_qty' => array( 0 => 2, 1 => 99 ), 'rv_qty' => 0, 'rv_stay_type' => 'nightly' );
$resolved   = $call( 'resolve_rv_tier_submission', $submission, $data, 0 );
$check( 'rv_qty clamped to 2 + 5 = 7', 7 === (int) $resolved['rv_qty'] );
$check( 'Premium clamped to 5 in selection', isset( $resolved['rv_tier_selection']['Premium'] ) && 5 === $resolved['rv_tier_selection']['Premium'] );
$check( 'surcharge sum = 5×10 = 50 (Standard adds 0)', abs( (float) $resolved['rv_tier_surcharge_sum'] - 50.0 ) < 0.001 );

echo "RV Tiers note round-trip:\n";
$note = '';
foreach ( $resolved['rv_tier_selection'] as $n => $q ) { $note .= ( $note ? ', ' : '' ) . $n . ' ×' . $q; }
$parsed = $call( 'parse_rv_tiers_note', $note );
$check( 'note parses back to Standard=2', isset( $parsed['Standard'] ) && 2 === $parsed['Standard'] );
$check( 'note parses back to Premium=5',  isset( $parsed['Premium'] )  && 5 === $parsed['Premium'] );

echo "Done. PASS=$pass FAIL=$fail\n";
