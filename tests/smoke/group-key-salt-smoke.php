<?php
/**
 * Group-key salt smoke (ship-readiness 4.1c / task #60).
 *
 * The composite fallback in EEM_Orders_Repository::build_group_key (used only for
 * a row with NO submission token) hashes event / name / email / phone / timestamp
 * — all attacker-knowable — so without a salt the resulting order_key (the bearer
 * credential for doc downloads) is brute-forceable. This asserts the salted
 * fallback is:
 *   1. UNGUESSABLE — order_key_for_row != md5 of the raw composite,
 *   2. DETERMINISTIC — identical across two rows sharing the composite (so one
 *      order's stall + RV rows still group together),
 *   3. TOKEN-SAFE — a tokened row's key is md5(token), independent of the salt.
 *
 * Run via: wp eval-file tests/smoke/group-key-salt-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "  FAIL — EEM_Orders_Repository missing\n0 passed, 1 failed\n";
	return;
}
$repo = new EEM_Orders_Repository();

$composite = array(
	'event_source'      => 'native',
	'event_id'          => 7,
	'external_event_id' => '',
	'customer_name'     => 'Jane Rider',
	'email'             => 'jane@example.com',
	'phone'             => '555-0100',
	'created_at'        => '2026-06-01 10:00:00',
	'notes'             => 'Reservation setup ID: 5',
);

// The raw, salt-less composite an attacker could reconstruct from known fields.
$guessable = md5( implode( '|', array( 'native', 7, '', 'Jane Rider', 'jane@example.com', '555-0100', '2026-06-01 10:00:00', 5 ) ) );

$key_a = $repo->order_key_for_row( $composite );
$key_b = $repo->order_key_for_row( $composite ); // same fields again

$chk( $key_a !== $guessable, 'tokenless order_key is NOT the brute-forceable composite md5' );
$chk( $key_a === $key_b, 'tokenless key is deterministic across rows sharing the composite (groups correctly)' );

// A second order with DIFFERENT identity gets a different key (no collision).
$other = array_merge( $composite, array( 'customer_name' => 'Sam Other', 'email' => 'sam@example.com' ) );
$chk( $repo->order_key_for_row( $other ) !== $key_a, 'a different customer yields a different key' );

// Tokened row: key is md5(token), independent of the salt.
$token   = wp_generate_uuid4();
$tokened = array_merge( $composite, array( 'notes' => "Reservation setup ID: 5\nSubmission token: {$token}" ) );
$chk( $repo->order_key_for_row( $tokened ) === md5( $token ), 'tokened row key = md5(token), unaffected by the salt' );

// The salt is persisted (so it survives across requests) and is high-entropy.
$salt = get_option( 'eem_group_key_salt', '' );
$chk( is_string( $salt ) && strlen( $salt ) >= 32, 'group-key salt persisted at >= 32 chars' );

echo "\n$pass passed, $fail failed\n";
