<?php
/**
 * Smoke: the customer-checkout "at least one reservation item" gate
 * (validate_submission) must NOT block a GROUP-ONLY reservation.
 *
 * Regression guard for the bug where a group-only event (stalls/RV/add-ons all
 * off, only Group Reservation enabled) returned "Please select at least one
 * reservation item." on Reserve Now even though the Rider Grounds Fee / Deposit
 * line items priced a non-zero total. The gate now counts group riders (and
 * general/RV add-ons + pre-entries) as valid reservation items.
 *
 * Run: wp eval-file tests/smoke/group-only-checkout-gate-smoke.php
 *
 * Needs the seeded group-only reservation (slug group-only-reservation). If it
 * is absent the smoke reports a precondition skip rather than a false failure.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0;
$fail = 0;
$log  = array();
$ok   = function ( $name, $cond ) use ( &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; $log[] = "PASS  $name"; }
	else         { $fail++; $log[] = "FAIL  $name"; }
};

$post = get_page_by_path( 'group-only-reservation', OBJECT, 'en_reservation' );
$rid  = $post ? (int) $post->ID : 0;
if ( ! $rid ) {
	echo "group-only-checkout-gate-smoke: SKIP (no group-only-reservation fixture)\n";
	return;
}

$sc   = new EEM_Shortcodes();
$ref  = new ReflectionClass( $sc );
$meta = $ref->getMethod( 'get_reservation_meta' );    $meta->setAccessible( true );
$stat = $ref->getMethod( 'get_reservation_status' );  $stat->setAccessible( true );
$san  = $ref->getMethod( 'sanitize_submission' );     $san->setAccessible( true );
$val  = $ref->getMethod( 'validate_submission' );     $val->setAccessible( true );

$data   = $meta->invoke( $sc, $rid );
$status = $stat->invoke( $sc, $data, $rid );
$needle = 'Please select at least one reservation item.';

$ok( 'fixture is group-only (group enabled, stalls/rv off)',
	! empty( $data['group_reservations_enabled'] ) && empty( $data['stalls_enabled'] ) && empty( $data['rv_enabled'] ) );

$billing = array(
	'first_name' => 'Whitney', 'last_name' => 'Mitchell', 'email' => 'w@example.test', 'phone' => '+15555550100',
	'billing_first_name' => 'Whitney', 'billing_last_name' => 'Mitchell', 'billing_address_1' => '1 Main',
	'billing_city' => 'Orange Cove', 'billing_state' => 'CA', 'billing_postal_code' => '93646', 'billing_country' => 'US',
	'en_submission_token' => 'tok',
);

// [1] Group selected → gate must NOT fire.
$_POST = array_merge( $billing, array( 'group_reservation_enabled' => '1', 'group_rider_count' => '1' ) );
$errs  = (array) $val->invoke( $sc, $san->invoke( $sc, $data ), $status, $data );
$ok( '[1] group-only selection clears the "at least one item" gate', ! in_array( $needle, $errs, true ) );

// [2] Nothing selected → gate MUST still fire (control: the guard isn't dead).
$_POST = $billing;
$errs2 = (array) $val->invoke( $sc, $san->invoke( $sc, $data ), $status, $data );
$ok( '[2] empty selection still trips the gate (guard intact)', in_array( $needle, $errs2, true ) );

echo implode( "\n", $log ) . "\n";
echo "group-only-checkout-gate-smoke: {$pass} passed, {$fail} failed\n";
