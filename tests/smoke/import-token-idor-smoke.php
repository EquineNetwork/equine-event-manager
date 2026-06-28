<?php
/**
 * Import-token IDOR smoke (ship-readiness 4.1, forward fix).
 *
 * An imported order's bearer key is md5(build_group_key(row)). build_group_key
 * returns the "Submission token" note when one is present + extractable, else a
 * GUESSABLE composite of event/name/email/phone/timestamp. The old import token
 * 'imp-' . md5(order_number . name) was (a) guessable and (b) not even matched by
 * the extractor (the 'imp-' prefix isn't hex), so imported orders fell through to
 * the guessable composite — an IDOR on uploaded identity/health docs.
 *
 * Asserts a pure-uuid token IS extracted (→ unguessable key) and the old
 * 'imp-md5' token is NOT (→ the composite, which this fix eliminates for new
 * imports).
 *
 * Run via: wp eval-file tests/smoke/import-token-idor-smoke.php
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

$repo    = new EEM_Orders_Repository();
$extract = new ReflectionMethod( 'EEM_Orders_Repository', 'extract_submission_token_from_notes' );
$extract->setAccessible( true );
$bgk     = new ReflectionMethod( 'EEM_Orders_Repository', 'build_group_key' );
$bgk->setAccessible( true );

$uuid       = wp_generate_uuid4();
$guess_name = 'Jane Rider';
$guess_num  = 'IMP-00042';
$old_token  = 'imp-' . md5( $guess_num . $guess_name );

// Extractor behaviour.
$chk( $uuid === $extract->invoke( $repo, "Submission token: $uuid\nReservation setup ID: 5" ), 'pure uuid token IS extracted' );
$chk( '' === $extract->invoke( $repo, "Submission token: $old_token\nReservation setup ID: 5" ), "old 'imp-md5' token is NOT extracted (the bug)" );

// build_group_key: uuid row → the uuid (strong); old-token row → guessable composite.
$base_row = array(
	'event_source'      => 'native',
	'event_id'          => 7,
	'external_event_id' => '',
	'customer_name'     => $guess_name,
	'email'             => 'jane@example.com',
	'phone'             => '555-0100',
	'created_at'        => '2026-06-01 10:00:00',
);
$uuid_row = array_merge( $base_row, array( 'notes' => "Submission token: $uuid\nReservation setup ID: 5" ) );
$old_row  = array_merge( $base_row, array( 'notes' => "Submission token: $old_token\nReservation setup ID: 5" ) );

$uuid_key  = (string) $bgk->invoke( $repo, $uuid_row );
$old_key   = (string) $bgk->invoke( $repo, $old_row );

$chk( $uuid_key === $uuid, 'uuid row → group key is the unguessable uuid' );
$chk( false !== strpos( $old_key, $guess_name ), "old-token row → group key is the GUESSABLE composite (contains the name)" );

// The resulting bearer keys (md5) must differ, and the uuid one must not be
// derivable from the customer's known fields.
$guessable_md5 = md5( implode( '|', array( 'native', 7, '', $guess_name, 'jane@example.com', '555-0100', '2026-06-01 10:00:00', 5 ) ) );
$chk( md5( $old_key ) === $guessable_md5, 'old import order_key IS brute-forceable from known fields' );
$chk( md5( $uuid_key ) !== $guessable_md5, 'new (uuid) import order_key is NOT derivable from known fields' );

echo "\n$pass passed, $fail failed\n";
