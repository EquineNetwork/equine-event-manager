<?php
/**
 * C9.A smoke — EEM_Customer_Profile_Repo read-only aggregation.
 *
 * Run: wp eval-file tests/smoke/c9a-customer-profile-repo-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$repo = new EEM_Customer_Profile_Repo();

// Pick the email with the most orders so aggregation is meaningfully exercised.
$orders_repo = new EEM_Orders_Repository();
$counts = array();
foreach ( $orders_repo->get_orders( '', 'date', 'desc' ) as $o ) {
	$e = strtolower( trim( (string) ( $o['email'] ?? '' ) ) );
	if ( '' !== $e ) { $counts[ $e ] = ( $counts[ $e ] ?? 0 ) + 1; }
}
arsort( $counts );
$email = (string) array_key_first( $counts );
$expected_orders = (int) reset( $counts );
WP_CLI::log( "Testing email: {$email} ({$expected_orders} orders)" );

// ── Existence + null-on-unknown ──
$check( 'exists() true for a known customer', $repo->exists( $email ) );
$check( 'exists() false for unknown email', ! $repo->exists( 'nobody-' . md5( $email ) . '@nowhere.test' ) );
$check( 'get_profile() null for unknown email', null === $repo->get_profile( 'nobody-' . md5( $email ) . '@nowhere.test' ) );

// ── Profile shape ──
$p = $repo->get_profile( $email );
$check( 'get_profile returns an array', is_array( $p ) );
foreach ( array( 'email', 'name', 'phone', 'billing', 'customer_since', 'stats', 'orders', 'reservations', 'activity', 'note' ) as $k ) {
	$check( "profile has key '{$k}'", array_key_exists( $k, $p ) );
}
$check( 'profile email matches', strtolower( $p['email'] ) === $email );
$check( 'profile name non-empty', '' !== $p['name'] );

// ── Stats ──
$s = $p['stats'];
$check( 'stats orders_count matches order count', (int) $s['orders_count'] === $expected_orders );
$check( 'stats paid + unpaid = total', (int) $s['paid_count'] + (int) $s['unpaid_count'] === (int) $s['orders_count'] );
$check( 'lifetime_spend is $-formatted', is_string( $s['lifetime_spend'] ) && '$' === $s['lifetime_spend'][0] );
$check( 'avg_order_value is $-formatted', is_string( $s['avg_order_value'] ) && '$' === $s['avg_order_value'][0] );
$check( 'last_order_date present', '' !== $s['last_order_date'] );

// ── Order rows ──
$check( 'order rows count matches', count( $p['orders'] ) === $expected_orders );
foreach ( $p['orders'] as $row ) {
	$check( 'order row has order_number', '' !== $row['order_number'] );
	$check( 'order row total is $-formatted', '$' === $row['total'][0] );
	$check( 'order row type_labels is array', is_array( $row['type_labels'] ) );
	$check( 'order row can_collect is bool', is_bool( $row['can_collect'] ) );
}

// ── Reservation rows: order counts sum back to total ──
$res_order_sum = 0;
foreach ( $p['reservations'] as $r ) {
	$res_order_sum += (int) $r['orders'];
	// event_name may be '' for orders with no linked event — page renders a fallback.
	$check( 'reservation row event_name is a string', is_string( $r['event_name'] ) );
	$check( 'reservation row total is $-formatted', '$' === $r['total'][0] );
}
$check( 'sum(reservation order counts) == total orders', $res_order_sum === $expected_orders );

// ── Activity is an array (may be empty if no log entries) ──
$check( 'activity is an array', is_array( $p['activity'] ) );

// ── Notes round-trip (save then read back, then restore) ──
$original = $repo->get_note( $email );
$probe    = 'C9.A smoke note ' . wp_generate_password( 6, false );
$check( 'save_note returns true', $repo->save_note( $email, $probe ) );
$check( 'get_note reads back the saved note', $repo->get_note( $email ) === $probe );
$check( 'profile note reflects saved note', ( $repo->get_profile( $email )['note'] ) === $probe );
// Restore original (empty clears the key).
$repo->save_note( $email, $original );
$check( 'note restored to original', $repo->get_note( $email ) === $original );

// ── email_key is stable + case-insensitive ──
$check( 'email_key case-insensitive', EEM_Customer_Profile_Repo::email_key( 'A@B.com' ) === EEM_Customer_Profile_Repo::email_key( 'a@b.com ' ) );

WP_CLI::log( "\n=== C9.A customer-profile-repo smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C9.A customer-profile-repo smoke passed.' );
