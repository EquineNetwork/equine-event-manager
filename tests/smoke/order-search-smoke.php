<?php
/**
 * Order search smoke — order_matches_search_term() must find an order by the
 * padded number the UI shows (#09017), not just the raw stored number, and must
 * NOT false-match unrelated orders whose notes contain the same digit run
 * (the bug: searching "09017" pulled up IMP-90681 orders).
 *
 * Run: wp eval-file tests/smoke/order-search-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$repo = new EEM_Orders_Repository();
$m    = new ReflectionMethod( 'EEM_Orders_Repository', 'order_matches_search_term' );
$m->setAccessible( true );
$match = static function ( array $order, string $term ) use ( $repo, $m ): bool {
	return (bool) $m->invoke( $repo, $order, $term );
};

$order = array(
	'order_number'      => '9017',
	'customer_name'     => 'Carlos Delgado',
	'email'             => 'carlos@example.com',
	'phone'             => '(605) 555-0199',
	'reservation_title' => 'NTR- Rapid City, SD',
	'event_name'        => 'NTR- Rapid City, SD',
	'notes'             => 'Submission token: a88d6601a7b0a53308e5baecb0acba4d',
	'components'        => array(
		array( 'notes' => 'Assigned Stall Units: 12, 13', 'transaction_id' => 'ch_3Pabc09017xyz' ),
	),
);

// Bug 1: searching the padded display number must match the raw stored number.
$check( 'padded "#09017" matches raw 9017', $match( $order, '#09017' ) );
$check( 'padded "09017" matches raw 9017', $match( $order, '09017' ) );
$check( 'raw "9017" matches', $match( $order, '9017' ) );
$check( 'partial "901" matches 9017', $match( $order, '901' ) );

// Bug 2: an unrelated order whose NOTES contain the digit run must NOT match an
// order-number search.
$other = array(
	'order_number'  => 'IMP-90681',
	'customer_name' => 'Brianna Brown',
	'email'         => 'brianna@example.com',
	'notes'         => 'Imported ref 1209017004 — legacy migration tag',
	'components'    => array( array( 'notes' => 'token 9990901744' ) ),
);
$check( 'order-number search does NOT false-match an unrelated order via notes', ! $match( $other, '09017' ) );
$check( 'IMP order still findable by its own number', $match( $other, 'IMP-90681' ) );
$check( 'IMP order findable by its digits', $match( $other, '90681' ) );

// Text + identifier searches still work.
$check( 'name search works', $match( $order, 'carlos' ) );
$check( 'email search works', $match( $order, 'carlos@example' ) );
$check( 'reservation title search works', $match( $order, 'rapid city' ) );
$check( 'transaction id search works', $match( $order, 'ch_3Pabc' ) );
$check( 'phone search (formatted) works', $match( $order, '605' ) );
$check( 'note content search works', $match( $order, 'a88d6601' ) );

// A different order number must not match this one.
$check( 'unrelated number 9020 does NOT match order 9017', ! $match( $order, '9020' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
