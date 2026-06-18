<?php
/**
 * Smoke: Order Edit foundation — amount_paid / amount_due (mig-029).
 *
 * Asserts the grouped-order payload exposes the new amount_paid + amount_due
 * keys and that the balance invariant holds for every order:
 *   amount_due === max( 0, total - amount_paid )
 * plus that paid orders record a positive amount_paid (so a later-added item
 * bills as a balance instead of being masked by the paid status).
 *
 * Read-only against whatever orders exist on the site (post-migration). Run:
 *   php wp-cli.phar eval-file tests/smoke/order-amount-paid-foundation-smoke.php
 */

if ( ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "FAIL: EEM_Orders_Repository not loaded\n";
	return;
}

$repo = new EEM_Orders_Repository();
$pass = 0;
$fail = 0;
$check = function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
};

$orders = $repo->get_orders( '', 'date', 'desc' );
$check( 'get_orders returns an array', is_array( $orders ) );

$has_keys      = true;
$invariant_ok  = true;
$paid_records  = 0;
$paid_positive = true;

foreach ( (array) $orders as $o ) {
	if ( ! array_key_exists( 'amount_paid', $o ) || ! array_key_exists( 'amount_due', $o ) ) {
		$has_keys = false;
		continue;
	}
	$total = (float) $o['total'];
	$paid  = (float) $o['amount_paid'];
	$due   = (float) $o['amount_due'];

	if ( abs( $due - max( 0.0, $total - $paid ) ) > 0.001 ) {
		$invariant_ok = false;
	}
	if ( isset( $o['status_slug'] ) && 'paid' === $o['status_slug'] && $total > 0 ) {
		$paid_records++;
		if ( $paid <= 0 ) {
			$paid_positive = false;
		}
	}
}

$check( 'every order exposes amount_paid + amount_due keys', $has_keys );
$check( 'amount_due === max(0, total - amount_paid) for all orders', $invariant_ok );
$check( 'paid orders record a positive amount_paid (' . $paid_records . ' checked)', $paid_positive );

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
