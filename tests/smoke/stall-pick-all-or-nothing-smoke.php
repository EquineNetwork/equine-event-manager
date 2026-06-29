<?php
/**
 * Regression smoke — map/pick mode is all-or-nothing (Whitney 2026-06-29).
 *
 * THE BUG: with the stall map on, a customer could set quantity = 3 but pick only
 * 2 stalls and still check out — the 3rd stall was silently undefined. The rule:
 * pick NONE (auto-assign after checkout) or pick a spot for EVERY stall. A PARTIAL
 * selection must block checkout.
 *
 * BEHAVIORAL: drives the real validate_submission() against a mapped reservation
 * with three carts — partial (blocked), complete (allowed), and zero (allowed,
 * auto-assign) — asserting the partial-pick error fires only on the partial cart.
 *
 * Run: wp eval-file tests/smoke/stall-pick-all-or-nothing-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

if ( ! class_exists( 'EEM_Stall_Map_Importer' ) ) {
	WP_CLI::warning( 'EEM_Stall_Map_Importer unavailable — skipping.' );
	WP_CLI::success( 'Skipped (no map importer).' );
	return;
}

$sc  = new EEM_Shortcodes();
$ref = new ReflectionClass( $sc );
$priv = function ( $name ) use ( $sc, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m; };
$set_active = function ( $id ) use ( $sc, $ref ) {
	$p = $ref->getProperty( 'active_reservation_id' ); $p->setAccessible( true ); $p->setValue( $sc, (int) $id );
};

// ── Find a published reservation in exact_map mode with a 3+ stall map ──
$reservation_id = 0; $labels = array(); $data = array();
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids' ) ) as $cand ) {
	$set_active( $cand );
	$cand_data = $priv( 'get_reservation_meta' )->invoke( $sc, $cand );
	$mode      = $priv( 'get_resolved_stall_selection_mode' )->invoke( $sc, $cand, $cand_data );
	$cand_lbls = $priv( 'get_stall_map_unit_labels' )->invoke( $sc );
	if ( 'exact_map' === $mode && count( $cand_lbls ) >= 3 ) {
		$reservation_id = (int) $cand; $labels = array_values( $cand_lbls ); $data = $cand_data; break;
	}
}
$set_active( 0 );

if ( $reservation_id <= 0 ) {
	WP_CLI::warning( 'No exact_map reservation with a 3+ stall map found — skipping.' );
	WP_CLI::success( 'Skipped (no mapped exact_map fixture).' );
	return;
}

WP_CLI::log( "Using reservation #{$reservation_id}; picking from: " . implode( ', ', array_slice( $labels, 0, 5 ) ) );

$err_needle = 'stalls on the map';

// Helper: build a submission from a $_POST shape and run validate_submission.
$run = function ( array $picks, int $qty ) use ( $sc, $priv, $set_active, $reservation_id, $data ) {
	$set_active( $reservation_id );
	$_POST = array(
		'en_reservation_id'      => $reservation_id,
		'preferred_stall_units'  => $picks,
		'stall_qty'              => $qty,
		'stall_stay_type'        => 'nightly',
	);
	$submission = $priv( 'sanitize_submission' )->invoke( $sc, $data );
	$status     = $priv( 'get_reservation_status' )->invoke( $sc, $data, $reservation_id );
	$errors     = $priv( 'validate_submission' )->invoke( $sc, $submission, $status, $data );
	$_POST = array();
	$set_active( 0 );
	return $errors;
};

// 1) PARTIAL: qty 3, pick 2 → the all-or-nothing error MUST fire.
$partial = $run( array( $labels[0], $labels[1] ), 3 );
$has_partial_err = false;
foreach ( $partial as $e ) { if ( false !== strpos( (string) $e, $err_needle ) ) { $has_partial_err = true; break; } }
$check( 'PARTIAL pick (2 of 3) is rejected with the all-or-nothing error', $has_partial_err );

// 2) COMPLETE: qty 3, pick 3 → the error MUST NOT fire.
$complete = $run( array( $labels[0], $labels[1], $labels[2] ), 3 );
$has_complete_err = false;
foreach ( $complete as $e ) { if ( false !== strpos( (string) $e, $err_needle ) ) { $has_complete_err = true; break; } }
$check( 'COMPLETE pick (3 of 3) does NOT trigger the all-or-nothing error', ! $has_complete_err );

// 3) ZERO: qty 3, pick none → allowed (auto-assign), error MUST NOT fire.
$zero = $run( array(), 3 );
$has_zero_err = false;
foreach ( $zero as $e ) { if ( false !== strpos( (string) $e, $err_needle ) ) { $has_zero_err = true; break; } }
$check( 'ZERO picks (auto-assign) does NOT trigger the all-or-nothing error', ! $has_zero_err );

WP_CLI::log( "\n=== Stall-pick all-or-nothing smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Stall-pick all-or-nothing smoke passed.' );
