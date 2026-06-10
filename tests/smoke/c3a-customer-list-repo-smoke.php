<?php
/**
 * V1 commit #3.A smoke — EEM_Customer_Profile_Repo::get_customer_list().
 *
 * Run: wp eval-file tests/smoke/c3a-customer-list-repo-smoke.php
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

// ── Shape + pagination ──
$res = $repo->get_customer_list( array( 'per_page' => 5, 'paged' => 1 ) );
foreach ( array( 'rows', 'total', 'paged', 'per_page', 'pages' ) as $k ) {
	$check( "result has key '{$k}'", array_key_exists( $k, $res ) );
}

// Guard: requires seed data. If no customers exist, skip data-dependent assertions.
if ( 0 === (int) $res['total'] ) {
	WP_CLI::log( 'C#3.A SKIP — no customer data present; run: wp eval-file tools/seed-test-data.php' );
	WP_CLI::log( "\n=== C#3.A customer-list-repo smoke: {$pass} passed, {$fail} failed ===" );
	if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
	WP_CLI::success( 'C#3.A customer-list-repo smoke passed.' );
	return;
}

$check( 'total > 0 (seed data present)', $res['total'] > 0 );
$check( 'page holds at most per_page rows', count( $res['rows'] ) <= 5 );
$check( 'pages = ceil(total/per_page)', (int) $res['pages'] === (int) ceil( $res['total'] / 5 ) );

// Row shape + non-empty content.
$row = $res['rows'][0];
foreach ( array( 'email', 'name', 'name_sort', 'orders', 'spent_raw', 'spent', 'last_ts', 'last_activity' ) as $k ) {
	$check( "row has key '{$k}'", array_key_exists( $k, $row ) );
}
$check( 'row name is non-empty', '' !== $row['name'] );
$check( 'row email is non-empty', '' !== $row['email'] );
$check( 'row orders >= 1', (int) $row['orders'] >= 1 );
$check( 'row spent is $-formatted', is_string( $row['spent'] ) && '$' === $row['spent'][0] );

// ── Pagination: page 2 differs from page 1, no overlap, counts add up ──
if ( $res['pages'] >= 2 ) {
	$p1 = $repo->get_customer_list( array( 'per_page' => 5, 'paged' => 1 ) )['rows'];
	$p2 = $repo->get_customer_list( array( 'per_page' => 5, 'paged' => 2 ) )['rows'];
	$e1 = array_column( $p1, 'email' );
	$e2 = array_column( $p2, 'email' );
	$check( 'page 1 and page 2 do not overlap', array() === array_intersect( $e1, $e2 ) );
}
// Over-max page clamps to last page.
$clamped = $repo->get_customer_list( array( 'per_page' => 5, 'paged' => 9999 ) );
$check( 'paged clamps to last page', (int) $clamped['paged'] === (int) $clamped['pages'] );

// ── Default sort = Last Name A→Z ──
$sorted = $repo->get_customer_list( array( 'per_page' => 200 ) )['rows'];
$keys   = array_column( $sorted, 'name_sort' );
$copy   = $keys;
usort( $copy, 'strcasecmp' );
$check( 'default order is Last-Name A→Z (name_sort ascending)', $keys === $copy );

// last_first_key splits surname correctly.
$check( 'last_first_key("Amelia Brooks") = "brooks amelia"', 'brooks amelia' === EEM_Customer_Profile_Repo::last_first_key( 'Amelia Brooks' ) );
$check( 'last_first_key single word', 'cher' === EEM_Customer_Profile_Repo::last_first_key( 'Cher' ) );

// ── Search ── (guarded: requires seed customer named Delgado)
$s = $repo->get_customer_list( array( 'search' => 'delgado', 'per_page' => 50 ) );
if ( $s['total'] >= 1 ) {
	$check( 'search "delgado" returns >= 1', true );
	$all_match = true;
	foreach ( $s['rows'] as $r ) {
		if ( false === stripos( $r['name'], 'delgado' ) && false === stripos( $r['email'], 'delgado' ) ) { $all_match = false; }
	}
	$check( 'every search row matches the query', $all_match );
} else {
	WP_CLI::log( '  SKIP search "delgado" assertions (seed customer not in DB — run seed-test-data.php)' );
}

// ── Sort by spent desc ──
$sp   = $repo->get_customer_list( array( 'orderby' => 'spent', 'order' => 'desc', 'per_page' => 200 ) )['rows'];
$desc = true;
for ( $i = 1; $i < count( $sp ); $i++ ) {
	if ( $sp[ $i ]['spent_raw'] > $sp[ $i - 1 ]['spent_raw'] ) { $desc = false; break; }
}
$check( 'spent desc is monotonically non-increasing', $desc );

// ── Sort by orders desc: top row has the most orders ──
$ord = $repo->get_customer_list( array( 'orderby' => 'orders', 'order' => 'desc', 'per_page' => 200 ) )['rows'];
$max = 0;
foreach ( $ord as $r ) { $max = max( $max, (int) $r['orders'] ); }
$check( 'orders desc top row has the max order count', (int) $ord[0]['orders'] === $max );

WP_CLI::log( "\n=== C#3.A customer-list-repo smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C#3.A customer-list-repo smoke passed.' );
