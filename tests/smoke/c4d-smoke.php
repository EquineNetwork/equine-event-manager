<?php
/**
 * C4.D smoke — toolbar fix + bulk dispatcher + sort + filter + meatballs swap.
 *
 * Covers:
 *   - Toolbar render: + New Reservation button uses .eem-btn-electric;
 *     bulk-action select, date-filter select, Search Reservations
 *     button all have no `disabled` attr; bulk form posts to admin-
 *     post.php with _eem_bulk_nonce; search form is method="get" with
 *     name="s" input; date filter form carries eem_date.
 *   - Per-status meatballs swap: Trash tab renders Restore button,
 *     not Move to Trash. All tab renders Move to Trash, not Restore.
 *   - Sort wiring: orderby=event_dates ASC/DESC, orderby=orders
 *     ASC/DESC against seeded fixtures (assumes /tmp/c4-seed.php has
 *     been run OR fixtures survive from prior session).
 *   - Date filter: ?eem_date=yyyy-mm narrows to month; bogus month
 *     returns 0.
 *   - Bulk handler: handle_bulk method exists, trash_one private,
 *     admin_post hook registered; end-to-end against 2 fresh test
 *     posts with confirmation that both end up in trash + redirect
 *     carries eem_notice=bulk_trashed + eem_bulk_count=2.
 *
 * Re-runnable: creates + deletes its own bulk-test posts; doesn't
 * touch the seeded fixtures.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C4.D SMOKE ===\n";

// C6.6 / RES-ARCH-1 backfill — the pre-C6.6 seed reservations in this test
// DB (loaded by the legacy /tmp/c4-seed.php on prior sessions) wrote
// `_en_nightly_start_date` for the orderby=event_dates SQL key. Post-C6.6
// the orderby targets `_en_source_event_start_date` (the resolver's sort
// cache). For test fixtures that pre-date the migration, copy the legacy
// value across so the sort/filter assertions below have rows to operate
// on. Production has no `_en_nightly_*` writers per the C6.6 audit; this
// backfill is a smoke-test concern only.
$legacy_seeded = get_posts( array(
	'post_type'   => 'en_reservation',
	'post_status' => array( 'publish', 'draft', 'trash' ),
	'numberposts' => -1,
	'fields'      => 'ids',
	'meta_query'  => array(
		array( 'key' => '_en_nightly_start_date', 'compare' => 'EXISTS' ),
		array( 'key' => EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY, 'compare' => 'NOT EXISTS' ),
	),
) );
foreach ( $legacy_seeded as $legacy_id ) {
	$legacy_start = (string) get_post_meta( $legacy_id, '_en_nightly_start_date', true );
	if ( '' !== $legacy_start ) {
		update_post_meta( $legacy_id, EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY, $legacy_start );
	}
}

wp_set_current_user( 1 );
$_GET = array( 'page' => EEM_Reservations_List_Page::MENU_SLUG );

echo "\n[1] Toolbar render\n";
ob_start();
( new EEM_Reservations_List_Page() )->render();
$html = ob_get_clean();

ok( '+ New Reservation uses eem-btn-electric class', str_contains( $html, 'eem-btn eem-btn-electric' ), $pass, $fail, $log );
ok( '+ New Reservation does NOT use eem-btn-primary', ! preg_match( '/eem-btn eem-btn-primary[^"]*">[^<]*New Reservation/', $html ), $pass, $fail, $log );
ok( 'bulk-action select has no disabled attr',  ! preg_match( '/<select[^>]*name="bulk_action"[^>]*\sdisabled/', $html ), $pass, $fail, $log );
ok( 'date-filter select has no disabled attr',  ! preg_match( '/<select[^>]*name="eem_date"[^>]*\sdisabled/', $html ), $pass, $fail, $log );
ok( 'search input has no disabled attr',        ! preg_match( '/<input[^>]*class="[^"]*eem-search-input[^"]*"[^>]*\sdisabled/', $html ), $pass, $fail, $log );
ok( 'all <button class="eem-toolbar-btn"> have no disabled attr', ! preg_match( '/<button[^>]*class="[^"]*eem-toolbar-btn[^"]*"[^>]*\sdisabled/', $html ), $pass, $fail, $log );
ok( 'bulk form posts to admin-post.php',        str_contains( $html, 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' ), $pass, $fail, $log );
ok( 'bulk form carries _eem_bulk_nonce',        str_contains( $html, '_eem_bulk_nonce' ), $pass, $fail, $log );
ok( 'search form GETs with name="s" input',     preg_match( '/<form[^>]*method="get"[^>]*class="[^"]*eem-search-form/', $html ) > 0, $pass, $fail, $log );
ok( 'date filter form GETs with eem_date',      str_contains( $html, 'name="eem_date"' ), $pass, $fail, $log );

echo "\n[2] Per-status meatballs swap (Trash → Restore)\n";
$_GET['status'] = 'trash';
ob_start();
( new EEM_Reservations_List_Page() )->render();
$trash_html = ob_get_clean();
ok( 'Trash tab shows Restore button',      preg_match( '/data-eem-action="reservation-restore"/', $trash_html ) > 0, $pass, $fail, $log );
ok( 'Trash tab does NOT show Move to Trash for trashed rows', preg_match( '/data-eem-action="reservation-trash"/', $trash_html ) === 0, $pass, $fail, $log );

$_GET['status'] = 'all';
ob_start();
( new EEM_Reservations_List_Page() )->render();
$all_html = ob_get_clean();
ok( 'All tab shows Move to Trash (not Restore)', preg_match( '/data-eem-action="reservation-trash"/', $all_html ) > 0, $pass, $fail, $log );
ok( 'bulk select offers Publish (non-trash tab)',        preg_match( '/<option value="publish"/', $all_html ) > 0, $pass, $fail, $log );
ok( 'bulk select offers Switch to Draft (non-trash tab)', preg_match( '/<option value="draft"/', $all_html ) > 0, $pass, $fail, $log );

echo "\n[3] Sort wiring (Repo::get_paginated)\n";

// Fixture drift: the named seed reservations this section originally asserted
// against ("2025 Spring Classic", "2026 Sunshine Dressage", "2026 Lone Star
// Invitational") were removed by later seed churn. Only one surviving seed
// carries a sort-cache date, so hardcoded title assertions can't exercise the
// ordering logic. Seed three throwaway reservations with KNOWN distinct
// source-event start dates (the sort-cache key) + unique titles, assert the
// ordering/filtering behavior against them, then hard-delete at the end of the
// section. This tests the repo's real SQL ordering rather than stale fixtures.
$sort_key = EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY;
$seed = array();
$seed['early']  = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C4D SORT_EARLY 2024-02' ) );
$seed['mid']    = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C4D SORT_MID 2027-07' ) );
$seed['late']   = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C4D SORT_LATE 2099-11' ) );
update_post_meta( $seed['early'], $sort_key, '2024-02-10' );
update_post_meta( $seed['mid'],   $sort_key, '2027-07-15' );
update_post_meta( $seed['late'],  $sort_key, '2099-11-20' );

$page_dates = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'event_dates', 'order' => 'asc', 'per_page' => 200 ) );
$titles_asc = array_map( function( $p ) { return $p->post_title; }, $page_dates['items'] );
// Among OUR three dated seeds, ASC order must be EARLY < MID < LATE.
$ours_asc = array_values( array_filter( $titles_asc, function( $t ) { return str_starts_with( $t, 'C4D SORT_' ); } ) );
ok( "event_dates ASC orders our seeds EARLY→MID→LATE", array( 'C4D SORT_EARLY 2024-02', 'C4D SORT_MID 2027-07', 'C4D SORT_LATE 2099-11' ) === $ours_asc, $pass, $fail, $log, implode( ' | ', $ours_asc ) );
// 2099-11 is the latest date in the whole set, so it must lead the global ASC
// list's tail — i.e. it precedes all null-dated rows (nulls sort last).
$late_idx = array_search( 'C4D SORT_LATE 2099-11', $titles_asc, true );
$first_null_idx = null;
foreach ( $titles_asc as $i => $t ) {
	$pid = $page_dates['items'][ $i ]->ID;
	if ( '' === (string) get_post_meta( $pid, $sort_key, true ) ) { $first_null_idx = $i; break; }
}
ok( "event_dates ASC sorts null-dated rows after dated rows", null === $first_null_idx || $late_idx < $first_null_idx, $pass, $fail, $log, "late={$late_idx} firstNull=" . var_export( $first_null_idx, true ) );

$page_dates_desc = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'event_dates', 'order' => 'desc', 'per_page' => 200 ) );
$first_desc = ! empty( $page_dates_desc['items'] ) ? $page_dates_desc['items'][0]->post_title : '';
// Our LATE seed (2099-11-20) is the latest date in the entire set, so DESC must
// place it first globally.
ok( "event_dates DESC puts latest-dated reservation first", 'C4D SORT_LATE 2099-11' === $first_desc, $pass, $fail, $log, "got '{$first_desc}'" );

// Orders sort: assert the result is monotonically ordered by orders-count rather
// than against a specific (drift-prone) title. Pull the count for each returned
// row and verify the sequence is non-decreasing (ASC) / non-increasing (DESC).
$cnt = function( $p ) { return EEM_Reservations_List_Repo::get_orders_count_for_reservation( $p->ID ); };
$page_orders = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'orders', 'order' => 'asc', 'per_page' => 200 ) );
$counts_asc = array_map( $cnt, $page_orders['items'] );
$is_sorted_asc = $counts_asc === array_values( $counts_asc ) && $counts_asc === ( function( $a ) { sort( $a, SORT_NUMERIC ); return $a; } )( $counts_asc );
ok( "orders ASC: results are non-decreasing by order count", $is_sorted_asc && ! empty( $counts_asc ) && 0 === $counts_asc[0], $pass, $fail, $log, 'head=' . implode( ',', array_slice( $counts_asc, 0, 3 ) ) );

$page_ord_desc = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'orders', 'order' => 'desc', 'per_page' => 200 ) );
$counts_desc = array_map( $cnt, $page_ord_desc['items'] );
$sorted_desc = $counts_desc; rsort( $sorted_desc, SORT_NUMERIC );
ok( "orders DESC: results are non-increasing by order count (max first)", $counts_desc === $sorted_desc && ! empty( $counts_desc ) && $counts_desc[0] === max( $counts_desc ), $pass, $fail, $log, 'head=' . implode( ',', array_slice( $counts_desc, 0, 3 ) ) );

echo "\n[4] Date filter\n";
// Use our seeded dates: MID is the only July-2027 row, EARLY the only Feb-2024 row.
$page_jul = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'date_filter' => '2027-07', 'per_page' => 25 ) );
$titles_jul = array_map( function( $p ) { return $p->post_title; }, $page_jul['items'] );
ok( '?eem_date=2027-07 returns only our MID seed', 1 === count( $titles_jul ) && 'C4D SORT_MID 2027-07' === $titles_jul[0], $pass, $fail, $log, var_export( $titles_jul, true ) );

$page_feb24 = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'date_filter' => '2024-02', 'per_page' => 25 ) );
$titles_feb24 = array_map( function( $p ) { return $p->post_title; }, $page_feb24['items'] );
ok( '?eem_date=2024-02 returns only our EARLY seed', 1 === count( $titles_feb24 ) && 'C4D SORT_EARLY 2024-02' === $titles_feb24[0], $pass, $fail, $log, var_export( $titles_feb24, true ) );

$page_nomatch = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'date_filter' => '2031-01', 'per_page' => 25 ) );
ok( '?eem_date=2031-01 returns 0', 0 === $page_nomatch['total'], $pass, $fail, $log, "got {$page_nomatch['total']}" );

// Clean up the sort/filter seeds so the reservation set is left unchanged.
foreach ( $seed as $sid ) { wp_delete_post( $sid, true ); }

echo "\n[5] Bulk handler\n";
ok( 'handle_bulk method exists',  method_exists( 'EEM_Reservations_List_Page', 'handle_bulk' ),  $pass, $fail, $log );
ok( 'trash_one private method exists', ( new ReflectionMethod( 'EEM_Reservations_List_Page', 'trash_one' ) )->isPrivate(), $pass, $fail, $log );
ok( 'admin_post_eem_reservations_bulk hook registered', false !== has_action( 'admin_post_eem_reservations_bulk', array( 'EEM_Reservations_List_Page', 'handle_bulk' ) ), $pass, $fail, $log );

$bulk_a = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C4D BULK_TEST_A' ) );
$bulk_b = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C4D BULK_TEST_B' ) );

$_POST = array(
	'action'            => 'eem_reservations_bulk',
	'bulk_action'       => 'trash',
	'_eem_bulk_nonce'   => wp_create_nonce( 'eem_reservations_bulk' ),
	'_eem_selected_ids' => $bulk_a . ',' . $bulk_b,
	'status'            => 'all',
);
$_REQUEST = $_POST;
remove_all_filters( 'wp_redirect' );
add_filter( 'wp_redirect', function( $url ) { throw new RuntimeException( 'REDIR:' . $url ); }, 1 );
try {
	EEM_Reservations_List_Page::handle_bulk();
	ok( 'bulk trash redirected', false, $pass, $fail, $log, 'no redirect' );
} catch ( RuntimeException $e ) {
	ok( 'bulk trash redirected with eem_notice=bulk_trashed', str_contains( $e->getMessage(), 'eem_notice=bulk_trashed' ), $pass, $fail, $log );
	ok( 'bulk trash includes eem_bulk_count=2', str_contains( $e->getMessage(), 'eem_bulk_count=2' ), $pass, $fail, $log );
}
ok( "post {$bulk_a} now in trash", 'trash' === get_post_status( $bulk_a ), $pass, $fail, $log );
ok( "post {$bulk_b} now in trash", 'trash' === get_post_status( $bulk_b ), $pass, $fail, $log );
wp_delete_post( $bulk_a, true );
wp_delete_post( $bulk_b, true );

// Bulk Publish — eligibility gate: only reservations with a linked event publish.
$pub_linked   = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'draft', 'post_title' => 'C4D BULK_PUB_LINKED' ) );
$pub_unlinked = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'draft', 'post_title' => 'C4D BULK_PUB_UNLINKED' ) );
update_post_meta( $pub_linked, '_en_event_id', 999001 ); // satisfies the linked-event gate
$_POST = array(
	'action'            => 'eem_reservations_bulk',
	'bulk_action'       => 'publish',
	'_eem_bulk_nonce'   => wp_create_nonce( 'eem_reservations_bulk' ),
	'_eem_selected_ids' => $pub_linked . ',' . $pub_unlinked,
	'status'            => 'draft',
);
$_REQUEST = $_POST;
try {
	EEM_Reservations_List_Page::handle_bulk();
	ok( 'bulk publish redirected', false, $pass, $fail, $log, 'no redirect' );
} catch ( RuntimeException $e ) {
	ok( 'bulk publish: partial notice + counts (1 published, 1 skipped)',
		str_contains( $e->getMessage(), 'eem_notice=bulk_published_partial' )
		&& str_contains( $e->getMessage(), 'eem_bulk_count=1' )
		&& str_contains( $e->getMessage(), 'eem_bulk_skipped=1' ),
		$pass, $fail, $log );
}
ok( 'linked reservation published',                'publish' === get_post_status( $pub_linked ),   $pass, $fail, $log );
ok( 'unlinked reservation stayed draft (skipped)', 'draft'   === get_post_status( $pub_unlinked ), $pass, $fail, $log );

// Bulk Switch to Draft — always-safe unpublish.
$_POST = array(
	'action'            => 'eem_reservations_bulk',
	'bulk_action'       => 'draft',
	'_eem_bulk_nonce'   => wp_create_nonce( 'eem_reservations_bulk' ),
	'_eem_selected_ids' => (string) $pub_linked,
	'status'            => 'publish',
);
$_REQUEST = $_POST;
try {
	EEM_Reservations_List_Page::handle_bulk();
	ok( 'bulk draft redirected', false, $pass, $fail, $log, 'no redirect' );
} catch ( RuntimeException $e ) {
	ok( 'bulk draft: bulk_drafted notice', str_contains( $e->getMessage(), 'eem_notice=bulk_drafted' ), $pass, $fail, $log );
}
ok( 'reservation switched back to draft', 'draft' === get_post_status( $pub_linked ), $pass, $fail, $log );
wp_delete_post( $pub_linked, true );
wp_delete_post( $pub_unlinked, true );

remove_all_filters( 'wp_redirect' );

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
