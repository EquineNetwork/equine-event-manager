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

echo "\n[3] Sort wiring (Repo::get_paginated)\n";

$page_dates = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'event_dates', 'order' => 'asc', 'per_page' => 10 ) );
$first_title = ! empty( $page_dates['items'] ) ? $page_dates['items'][0]->post_title : '';
ok( "event_dates ASC first item is '2025 Spring Classic'", '2025 Spring Classic' === $first_title, $pass, $fail, $log, "got '{$first_title}'" );

$page_dates_desc = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'event_dates', 'order' => 'desc', 'per_page' => 10 ) );
$first_desc = ! empty( $page_dates_desc['items'] ) ? $page_dates_desc['items'][0]->post_title : '';
// C7.X.16 — fixture drift: "2026 Lone Star Invitational" was removed
// from the seeded reservation set somewhere between C7.X.10 and
// C7.X.16. Current latest by cached source-event start_date is
// "2026 Sunshine Dressage" (2026-08-14). Assertion intent
// unchanged: event_dates DESC puts the latest-dated reservation first.
ok( "event_dates DESC first item is '2026 Sunshine Dressage' (post-fixture-drift)", '2026 Sunshine Dressage' === $first_desc, $pass, $fail, $log, "got '{$first_desc}'" );

$page_orders = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'orders', 'order' => 'asc', 'per_page' => 25 ) );
$titles_ord = array_map( function( $p ) { return $p->post_title; }, $page_orders['items'] );
ok( "orders ASC: zero-orders fixture is first", ! empty( $titles_ord ) && in_array( $titles_ord[0], array( 'TEst Event', '2026 Sunshine Dressage' ), true ), $pass, $fail, $log, 'first=' . ( $titles_ord[0] ?? '' ) );

$page_ord_desc = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'orderby' => 'orders', 'order' => 'desc', 'per_page' => 25 ) );
$titles_ord_desc = array_map( function( $p ) { return $p->post_title; }, $page_ord_desc['items'] );
ok( "orders DESC first is '2026 Southeast Region Super Sort' (23 orders)", ! empty( $titles_ord_desc ) && '2026 Southeast Region Super Sort' === $titles_ord_desc[0], $pass, $fail, $log, 'first=' . ( $titles_ord_desc[0] ?? '' ) );

echo "\n[4] Date filter\n";
$page_may = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'date_filter' => '2026-05', 'per_page' => 25 ) );
$titles_may = array_map( function( $p ) { return $p->post_title; }, $page_may['items'] );
ok( '?eem_date=2026-05 returns R1 only', 1 === count( $titles_may ) && '2026 Southeast Region Super Sort' === $titles_may[0], $pass, $fail, $log, var_export( $titles_may, true ) );

$page_mar25 = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'date_filter' => '2025-03', 'per_page' => 25 ) );
$titles_mar25 = array_map( function( $p ) { return $p->post_title; }, $page_mar25['items'] );
ok( '?eem_date=2025-03 returns R2 only', 1 === count( $titles_mar25 ) && '2025 Spring Classic' === $titles_mar25[0], $pass, $fail, $log, var_export( $titles_mar25, true ) );

$page_nomatch = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'date_filter' => '2030-01', 'per_page' => 25 ) );
ok( '?eem_date=2030-01 returns 0', 0 === $page_nomatch['total'], $pass, $fail, $log, "got {$page_nomatch['total']}" );

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

remove_all_filters( 'wp_redirect' );

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
