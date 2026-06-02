<?php
/**
 * Front-end — Max Stalls Per Customer is enforced.
 *
 * Bug: the stall quantity stepper capped at remaining inventory, not the
 * per-customer limit, so a customer could select more stalls than allowed, and
 * there was no server-side check either ("Enforced at checkout" was a no-op).
 * Fix: stepper max = min(inventory, per-customer), plus a server-side validation
 * error when the submitted quantity exceeds the per-customer cap.
 *
 * Run: wp eval-file tests/smoke/stall-max-per-customer-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $extra = '' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) ); }
};

$sc  = new EEM_Shortcodes();
$ref = new ReflectionClass( $sc );
$call = function ( $name, $args ) use ( $sc, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m->invokeArgs( $sc, $args ); };

// ── Server-side validation: qty over the per-customer cap is rejected ──
$data   = array( 'stall_max_per_customer' => 2, 'stalls_enabled' => 1 );
$status = array( 'stall_inventory_remaining' => 57, 'stall_sold_out' => false, 'rv_sold_out' => false, 'stalls_open' => true );
$base_sub = array(
	'stall_qty' => 3, 'tack_stall_qty' => 0, 'rv_qty' => 0, 'rv_lot' => '',
	'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com', 'phone' => '1',
	'preferred_stall_units' => array(), 'stall_arrival_date' => '', 'stall_departure_date' => '',
);
$errs_over = (array) $call( 'validate_submission', array( $base_sub, $status, $data ) );
$has_cap_err = false;
foreach ( $errs_over as $e ) { if ( false !== stripos( (string) $e, 'at most' ) ) { $has_cap_err = true; } }
$check( 'qty 3 with max 2 is rejected server-side', $has_cap_err, 'errors: ' . wp_json_encode( $errs_over ) );

// At the cap (qty 2) → no cap error.
$sub_ok = array_merge( $base_sub, array( 'stall_qty' => 2 ) );
$errs_ok = (array) $call( 'validate_submission', array( $sub_ok, $status, $data ) );
$cap_err_at_2 = false;
foreach ( $errs_ok as $e ) { if ( false !== stripos( (string) $e, 'at most' ) ) { $cap_err_at_2 = true; } }
$check( 'qty 2 with max 2 is allowed (no cap error)', ! $cap_err_at_2, 'errors: ' . wp_json_encode( $errs_ok ) );

// Unlimited (blank max) → no cap error even at high qty.
$data_unlim = array( 'stall_max_per_customer' => '', 'stalls_enabled' => 1 );
$sub_hi = array_merge( $base_sub, array( 'stall_qty' => 10 ) );
$errs_unlim = (array) $call( 'validate_submission', array( $sub_hi, $status, $data_unlim ) );
$cap_err_unlim = false;
foreach ( $errs_unlim as $e ) { if ( false !== stripos( (string) $e, 'at most' ) ) { $cap_err_unlim = true; } }
$check( 'blank max = unlimited (no cap error at qty 10)', ! $cap_err_unlim );

// ── Root-cause guard: get_reservation_meta must surface the key to the front-end ──
$gm = $ref->getMethod( 'get_reservation_meta' ); $gm->setAccessible( true );
$meta = (array) $gm->invoke( $sc, 3499 );
$check( 'get_reservation_meta includes stall_max_per_customer key', array_key_exists( 'stall_max_per_customer', $meta ), 'keys missing the field default leave the cap at 0' );

// ── Stepper render: max attribute = min(inventory, per-customer) ──
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$check( 'stepper cap computed from per-customer max', false !== strpos( $src, '$stall_stepper_max' ) && false !== strpos( $src, "min( (int) \$stall_stepper_max, \$stall_per_customer_max )" ) );
$check( 'stall line passes the capped max to the stepper', false !== strpos( $src, "'max_quantity'       => \$stall_stepper_max," ) );

WP_CLI::log( "\n=== Stall max-per-customer smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Stall max-per-customer smoke passed.' );
