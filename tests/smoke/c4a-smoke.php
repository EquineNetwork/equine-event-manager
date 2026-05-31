<?php
/** C4.A smoke — Repo + page controller scaffold + class loading.
 *
 * C6.6 / RES-ARCH-1 migration (2026-05-23): the original C4.A smoke seeded
 * `_en_nightly_start_date` / `_en_nightly_end_date` directly on each test
 * reservation and asserted that `EEM_Reservations_List_Repo::get_event_date_range_label`
 * returned a label built from those keys. Post-migration:
 *   - The date label comes from the source event via
 *     EEM_Reservation_Source_Resolver (which delegates to
 *     EEM_Events::get_normalized_reservation_event_data).
 *   - The sort/filter SQL targets the `_en_source_event_start_date` cache
 *     written by save_post_en_reservation hook.
 * Fixture rewrites below: (a) the date-label test now seeds a native
 * source event + links the reservation, exercising the resolver
 * end-to-end; (b) the orderby=event_dates sort test seeds the cache key
 * directly since sort behavior is independent of source.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

ok( 'EEM_Reservations_List_Repo exists', class_exists( 'EEM_Reservations_List_Repo' ), $pass, $fail, $log );
ok( 'EEM_Reservations_List_Page exists', class_exists( 'EEM_Reservations_List_Page' ), $pass, $fail, $log );
ok( 'EEM_Reservation_Source_Resolver exists (C6.6)', class_exists( 'EEM_Reservation_Source_Resolver' ), $pass, $fail, $log );
$tabs = EEM_Reservations_List_Repo::status_tabs();
ok( 'status_tabs returns 4 entries', count( $tabs ) === 4, $pass, $fail, $log );
foreach ( array( 'all', 'publish', 'draft', 'trash' ) as $tab ) {
	ok( "tab '{$tab}' registered", isset( $tabs[ $tab ] ), $pass, $fail, $log );
	$label = EEM_Reservations_List_Repo::tab_label( $tab );
	ok( "tab_label('{$tab}') non-empty", is_string( $label ) && '' !== $label, $pass, $fail, $log );
}
$counts = EEM_Reservations_List_Repo::counts_by_tab();
ok( 'counts_by_tab returns 4 keys',  count( $counts ) === 4, $pass, $fail, $log );
ok( 'counts[all] = publish+draft', $counts['all'] === ( $counts['publish'] + $counts['draft'] ), $pass, $fail, $log );
$page = EEM_Reservations_List_Repo::get_paginated( array( 'status' => 'all', 'per_page' => 5, 'paged' => 1 ) );
ok( 'get_paginated returns items array',     isset( $page['items'] ) && is_array( $page['items'] ), $pass, $fail, $log );
ok( 'get_paginated returns total int',       isset( $page['total'] ) && is_int( $page['total'] ),   $pass, $fail, $log );
ok( 'get_paginated returns total_pages int', isset( $page['total_pages'] ),                          $pass, $fail, $log );
ok( 'get_paginated returns page int = 1',    isset( $page['page'] ) && $page['page'] === 1,         $pass, $fail, $log );
ok( 'get_paginated returns per_page = 5',    isset( $page['per_page'] ) && $page['per_page'] === 5, $pass, $fail, $log );

// C6.6 / RES-ARCH-1: build a native source event with start/end dates, then
// link a reservation to it. Resolver must return the SOURCE event's title +
// dates, not the reservation's own post_title or any reservation-side meta.
$event_id = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'C45A SOURCE EVENT' ) );
ok( 'created native source event', $event_id > 0, $pass, $fail, $log );
update_post_meta( $event_id, '_equine_event_manager_event_start_date', '2026-05-08' );
update_post_meta( $event_id, '_equine_event_manager_event_end_date',   '2026-05-10' );

$res_id = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C45A SMOKE' ) );
ok( 'created test reservation', $res_id > 0, $pass, $fail, $log );
update_post_meta( $res_id, '_en_stall_quantity_available', 10 );
update_post_meta( $res_id, '_en_rv_quantity_available', 5 );
// C6.6 — RES-ARCH-1 wiring: tell the resolver to dispatch through the
// native CPT, and point it at the source event we just created.
update_post_meta( $res_id, '_en_event_source', 'native' );
update_post_meta( $res_id, '_en_event_id', $event_id );
// The save_post hook would normally populate this on real reservation save,
// but wp_insert_post above ran save_post BEFORE the _en_event_id meta was
// set, so trigger the cache write explicitly to mirror the post-save state.
EEM_Reservation_Source_Resolver::cache_source_event_start_date( $res_id, get_post( $res_id ) );

$badges = EEM_Reservations_List_Repo::get_type_badges( $res_id );
ok( 'type badges include stall', in_array( 'stall', $badges, true ), $pass, $fail, $log );
ok( 'type badges include rv',    in_array( 'rv',    $badges, true ), $pass, $fail, $log );
ok( 'type badges exclude addon', ! in_array( 'addon', $badges, true ), $pass, $fail, $log );
ok( 'type badges exclude group', ! in_array( 'group', $badges, true ), $pass, $fail, $log );

// C6.6 / RES-ARCH-1: resolver returns source-event fields (title + dates).
$resolved = EEM_Reservation_Source_Resolver::resolve_event_fields( $res_id );
ok( 'resolver title = source event title (not reservation post_title)',  'C45A SOURCE EVENT' === $resolved['title'],      $pass, $fail, $log, "got '{$resolved['title']}'" );
ok( 'resolver start_date = source event start_date',                     '2026-05-08'         === $resolved['start_date'], $pass, $fail, $log, "got '{$resolved['start_date']}'" );
ok( 'resolver end_date   = source event end_date',                       '2026-05-10'         === $resolved['end_date'],   $pass, $fail, $log, "got '{$resolved['end_date']}'" );
ok( 'get_title convenience accessor matches',                            'C45A SOURCE EVENT' === EEM_Reservation_Source_Resolver::get_title( $res_id ),                $pass, $fail, $log );

// Sort cache: save-post hook wrote it from resolver output.
$sort_cache = get_post_meta( $res_id, EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY, true );
ok( 'sort cache _en_source_event_start_date populated', '2026-05-08' === (string) $sort_cache, $pass, $fail, $log, "got '{$sort_cache}'" );

// Date-range label: proxied through the resolver — pulls source-event start/end.
$label = EEM_Reservations_List_Repo::get_event_date_range_label( $res_id );
ok( 'event date range label has en-dash', str_contains( $label, '–' ), $pass, $fail, $log, "got '{$label}'" );

$orders_count = EEM_Reservations_List_Repo::get_orders_count_for_reservation( $res_id );
ok( 'orders count is 0 for new reservation', $orders_count === 0, $pass, $fail, $log );
wp_delete_post( $res_id, true );
wp_delete_post( $event_id, true );

wp_set_current_user( 1 );
$_GET['page'] = EEM_Reservations_List_Page::MENU_SLUG;

// C5.G.2 — verify the eem-admin JS bundle is enqueued on the Reservations
// page hook. Without it, meatballs dropdown won't open and row-action
// buttons won't dispatch (pre-existing C4 defect surfaced in C5.G).
// EEM_Admin::enqueue_backend_shell_styles() reads $_GET['page'] to decide
// should_load, so $_GET must be set before triggering admin_enqueue_scripts.
wp_dequeue_script( 'eem-admin' );
wp_deregister_script( 'eem-admin' );
do_action( 'admin_enqueue_scripts', 'toplevel_page_' . EEM_Reservations_List_Page::MENU_SLUG );
ok( 'eem-admin script registered on Reservations page', wp_script_is( 'eem-admin', 'registered' ), $pass, $fail, $log );
ok( 'eem-admin script enqueued on Reservations page',   wp_script_is( 'eem-admin', 'enqueued' ),   $pass, $fail, $log );
ob_start();
try {
	$page = new EEM_Reservations_List_Page();
	$page->render();
	$html = ob_get_clean();
	ok( 'render() produces output',                  strlen( $html ) > 500, $pass, $fail, $log );
	ok( 'render() contains New Reservation CTA',     str_contains( $html, 'New Reservation' ), $pass, $fail, $log );
	ok( 'render() contains eem-reservations-list',   str_contains( $html, 'eem-reservations-list' ), $pass, $fail, $log );
	ok( 'render() uses the page shell breadcrumb',   str_contains( $html, 'eem-breadcrumb' ), $pass, $fail, $log );
} catch ( Throwable $e ) {
	ob_end_clean();
	ok( 'render() does not throw', false, $pass, $fail, $log, $e->getMessage() );
}

// C5.G.4 — conditional stall-chart icon. Verify both paths render correctly:
// (a) reservation WITH _en_stalls_enabled meta → icon present
// (b) reservation WITHOUT the meta → icon absent (meatballs still present)
// C6.6 — sort-cache seed: orderby=event_dates targets the new
// _en_source_event_start_date cache key. Seed it directly here since the
// sort behavior under test doesn't depend on which source produced the
// value (covered by the resolver assertions above).
$with_chart = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C5G4 WITH chart' ) );
update_post_meta( $with_chart, '_en_stalls_enabled', 1 );
update_post_meta( $with_chart, EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY, '2030-01-01' );
$no_chart   = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C5G4 NO chart' ) );
update_post_meta( $no_chart, EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY, '2030-01-02' );
ok( 'has_stall_chart_enabled true when meta set',     true  === EEM_Reservations_List_Repo::has_stall_chart_enabled( $with_chart ), $pass, $fail, $log );
ok( 'has_stall_chart_enabled false when meta unset',  false === EEM_Reservations_List_Repo::has_stall_chart_enabled( $no_chart ),   $pass, $fail, $log );
$_GET = array( 'page' => EEM_Reservations_List_Page::MENU_SLUG, 'orderby' => 'event_dates', 'order' => 'desc' );
ob_start();
( new EEM_Reservations_List_Page() )->render();
$h = ob_get_clean();
// Extract each row's HTML segment cleanly (avoid tempered-greedy regex
// pitfalls that can match across </tr> boundaries).
$with_row = ( false !== ( $i = strpos( $h, 'data-reservation-id="' . $with_chart . '"' ) ) )
	? substr( $h, $i, ( strpos( $h, '</tr>', $i ) - $i ) ) : '';
$no_row   = ( false !== ( $i = strpos( $h, 'data-reservation-id="' . $no_chart   . '"' ) ) )
	? substr( $h, $i, ( strpos( $h, '</tr>', $i ) - $i ) ) : '';
ok( 'stall-chart icon renders for chart-enabled row', str_contains( $with_row, 'eem-action-icon-btn--stall-chart' ), $pass, $fail, $log );
ok( 'stall-chart icon ABSENT for non-chart row',    ! str_contains( $no_row,   'eem-action-icon-btn--stall-chart' ), $pass, $fail, $log );
wp_delete_post( $with_chart, true );
wp_delete_post( $no_chart,   true );

$url = EEM_Reservations_List_Page::url( array( 'status' => 'draft' ) );
ok( 'url() includes page param',   str_contains( $url, 'page=' . EEM_Reservations_List_Page::MENU_SLUG ), $pass, $fail, $log );
ok( 'url() includes status param', str_contains( $url, 'status=draft' ),                                  $pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
