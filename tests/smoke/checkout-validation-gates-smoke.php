<?php
/**
 * CHECKOUT-VALIDATION-AUDIT — behavioral sweep of every checkout gate
 * (Whitney 2026-06-29). Drives the REAL validate_submission() against the
 * canonical reservation, mutating ONE field at a time off a known-valid baseline
 * and asserting the matching gate fires (and that the baseline itself is clean).
 *
 * Each gate is the authoritative server check that runs in BOTH the Stripe
 * intent handler (ajax_create_stripe_payment_intent) and the final submit
 * (handle_reservation_submission) BEFORE any charge — so a firing gate means no
 * money moves. This is the regression guard for the whole "can't check out unless
 * X" class that produced the partial-stall-pick bug.
 *
 * Run: wp eval-file tests/smoke/checkout-validation-gates-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$RES = 18375; // canonical Columbiana fixture: group ON, stall exact_map, nightly.

$sc  = new EEM_Shortcodes();
$ref = new ReflectionClass( $sc );
$priv = function ( $name ) use ( $sc, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m; };
$setActive = function ( $id ) use ( $sc, $ref ) { $p = $ref->getProperty( 'active_reservation_id' ); $p->setAccessible( true ); $p->setValue( $sc, (int) $id ); };

$data = $priv( 'get_reservation_meta' )->invoke( $sc, $RES );

// A known-VALID baseline: group-only checkout (1 rider) avoids stall/RV date
// requirements while still being a complete, chargeable order.
$baseline = array(
	'en_reservation_id'        => $RES,
	'first_name'               => 'Test',
	'last_name'                => 'User',
	'email'                    => 'test@example.com',
	'phone'                    => '+13305551234',
	'billing_first_name'       => 'Test',
	'billing_last_name'        => 'User',
	'billing_address_1'        => '1 Main St',
	'billing_city'             => 'Columbiana',
	'billing_state'            => 'OH',
	'billing_postal_code'      => '44408',
	'billing_country'          => 'US',
	'en_submission_token'      => 'tok-baseline-123',
	'en_invoice_type'          => 'customer',
	'en_invoice_action_mode'   => 'charge_now',
	'group_reservation_enabled' => '1',
	'group_rider_count'        => '1',
	'group_riders'             => array( array( 'first_name' => 'Rider', 'last_name' => 'One' ) ),
);

// Run the real pipeline for a $_POST (baseline + overrides). Returns the error list.
$run = function ( array $overrides ) use ( $sc, $priv, $setActive, $baseline, $data, $RES ) {
	$setActive( $RES );
	$_POST = array_merge( $baseline, $overrides );
	// Allow a test to REMOVE a key by passing it as the sentinel '__unset__'.
	foreach ( $_POST as $k => $v ) { if ( '__unset__' === $v ) { unset( $_POST[ $k ] ); } }
	$submission = $priv( 'sanitize_submission' )->invoke( $sc, $data );
	$status     = $priv( 'get_reservation_status' )->invoke( $sc, $data, $RES );
	$errors     = $priv( 'validate_submission' )->invoke( $sc, $submission, $status, $data );
	$_POST = array();
	$setActive( 0 );
	return $errors;
};
$hits = function ( array $errors, $needle ) {
	foreach ( $errors as $e ) { if ( false !== stripos( (string) $e, $needle ) ) { return true; } }
	return false;
};

// ── 0. Positive control: the baseline is a CLEAN, checkout-able order ──
$base_errors = $run( array() );
$check( 'BASELINE valid order passes with zero errors (' . count( $base_errors ) . ' err: ' . implode( ' | ', $base_errors ) . ')', empty( $base_errors ) );

// ── 1. Contact name/email/phone present ──
$check( 'GATE: missing first name is rejected', $hits( $run( array( 'first_name' => '' ) ), 'enter your name' ) );

// ── 2. Email format ──
$check( 'GATE: invalid email is rejected', $hits( $run( array( 'email' => 'not-an-email' ) ), 'valid email' ) );

// ── 3. Phone format (must be international +). NOTE: a bare 10-digit US number
// auto-normalizes to "+1 ..." and is correctly accepted; only a too-short /
// junk number that can't normalize is rejected. ──
$check( 'GATE: too-short / un-normalizable phone is rejected', $hits( $run( array( 'phone' => '12' ) ), 'international phone' ) );

// ── 4. Billing details complete (customer charge path) ──
$check( 'GATE: missing billing city is rejected', $hits( $run( array( 'billing_city' => '' ) ), 'billing details' ) );

// ── 5. Submission token present ──
$check( 'GATE: missing submission token is rejected', $hits( $run( array( 'en_submission_token' => '' ) ), 'could not verify' ) );

// ── 6. At-least-one purchasable item ──
$check( 'GATE: empty cart (no items at all) is rejected', $hits( $run( array( 'group_reservation_enabled' => '__unset__', 'group_rider_count' => '0', 'group_riders' => array() ) ), 'at least one' ) );

// ── 7. Group rider count >= 1 when group enabled ──
$check( 'GATE: group enabled with 0 riders is rejected', $hits( $run( array( 'group_rider_count' => '0', 'group_riders' => array() ) ), 'how many riders' ) );

// ── 8. Every rider name filled ──
$check( 'GATE: group with missing rider names is rejected', $hits( $run( array( 'group_rider_count' => '2', 'group_riders' => array( array( 'first_name' => 'Only', 'last_name' => 'One' ) ) ) ), 'first and last name' ) );

// ── 9-11. Stall map gates (exact_map): partial pick, over-pick, stay dates ──
$labels = ( function () use ( $sc, $ref, $setActive, $RES ) {
	$setActive( $RES );
	$m = $ref->getMethod( 'get_stall_map_unit_labels' ); $m->setAccessible( true );
	$out = $m->invoke( $sc ); $setActive( 0 ); return array_values( $out );
} )();

$stall_post = array(
	'stall_stay_type'     => 'nightly',
	'stall_arrival_date'  => '2026-08-20',
	'stall_departure_date' => '2026-08-24',
	'stall_qty'           => '3',
	'preferred_stall_units' => array( $labels[0], $labels[1] ), // 2 of 3 = PARTIAL
);
$check( 'GATE: partial stall map pick (2 of 3) is rejected', $hits( $run( $stall_post ), 'stalls on the map' ) );

// Over-pick: MORE units than qty. (Suspected gap: the units>qty check at ~3989 is
// gated on stall_chart_enabled, which is 0 for this exact_map reservation.)
$overpick = array_merge( $stall_post, array( 'stall_qty' => '2', 'preferred_stall_units' => array( $labels[0], $labels[1], $labels[2] ) ) );
$check( 'GATE: over-pick (3 units, qty 2) is rejected', $hits( $run( $overpick ), 'more preferred stall' ) );

// Stall stay dates: arrival after departure.
$baddate = array_merge( $stall_post, array( 'preferred_stall_units' => array( $labels[0], $labels[1], $labels[2] ), 'stall_arrival_date' => '2026-08-24', 'stall_departure_date' => '2026-08-20' ) );
$check( 'GATE: stall departure-before-arrival is rejected', $hits( $run( $baddate ), 'on or after' ) );

// Stall arrival outside the available window.
$outwindow = array_merge( $stall_post, array( 'preferred_stall_units' => array( $labels[0], $labels[1], $labels[2] ), 'stall_arrival_date' => '2026-09-01', 'stall_departure_date' => '2026-09-02' ) );
$check( 'GATE: stall dates outside available range are rejected', $hits( $run( $outwindow ), 'available reservation date range' ) );

// Complete stall pick (3 of 3, valid dates) — should NOT trip the stall gates.
$good_stall = array_merge( $stall_post, array( 'preferred_stall_units' => array( $labels[0], $labels[1], $labels[2] ) ) );
$gs_err = $run( $good_stall );
$check( 'POSITIVE: complete stall pick (3 of 3, valid dates) trips no stall gate', ! $hits( $gs_err, 'stalls on the map' ) && ! $hits( $gs_err, 'more preferred stall' ) && ! $hits( $gs_err, 'valid stall' ) );

WP_CLI::log( "\n=== Checkout-validation gates smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Checkout-validation gates smoke passed.' );
