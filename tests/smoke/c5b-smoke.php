<?php
/**
 * C5.B smoke — toolbar + desktop table + mobile cards + footer.
 *
 * Verifies the static render structure introduced in C5.B against the
 * mockup at .mockups/orders_page.html. Row-action handlers (C5.C) and
 * filter-dispatcher wiring (C5.D) are NOT covered here — those have
 * their own smokes.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C5.B SMOKE ===\n";

wp_set_current_user( 1 );
$_GET = array( 'page' => EEM_Orders_List_Page::MENU_SLUG );

ob_start();
( new EEM_Orders_List_Page() )->render();
$html = ob_get_clean();

echo "\n[-1] JS bundle enqueued on Orders page hook (C5.G.2 row-action infra)\n";
// Simulate admin_enqueue_scripts on the orders page hook; this is what
// makes the meatballs dropdown + Print Receipt + Resend Notification +
// Export CSV + Move to Trash + Bulk Apply buttons actually do anything.
wp_dequeue_script( 'eem-admin' );
wp_deregister_script( 'eem-admin' );
do_action( 'admin_enqueue_scripts', 'toplevel_page_' . EEM_Orders_List_Page::MENU_SLUG );
ok( 'eem-admin script registered on Orders page',  wp_script_is( 'eem-admin', 'registered' ), $pass, $fail, $log );
ok( 'eem-admin script enqueued on Orders page',    wp_script_is( 'eem-admin', 'enqueued' ),   $pass, $fail, $log );

echo "\n[0] Create Order button uses Electric Blue (VIS-4 cross-app CTA consistency)\n";
ok( 'Create Order button has eem-btn-electric class',  str_contains( $html, 'class="eem-btn eem-btn-electric"' ), $pass, $fail, $log );
ok( 'Create Order button does NOT have eem-btn-navy class', ! str_contains( $html, 'eem-btn-navy' ), $pass, $fail, $log );

echo "\n[1] Toolbar — Row 1 (event filter + billing tabs) using shared .eem-list-toolbar\n";
$toolbar_count = preg_match_all( '/<div class="eem-list-toolbar"/', $html );
ok( 'renders TWO .eem-list-toolbar rows (R1 + R2)', 2 === $toolbar_count,            $pass, $fail, $log, "got {$toolbar_count}" );
ok( 'event-filter form present',         str_contains( $html, 'class="eem-orders-event-filter-form"' ),   $pass, $fail, $log );
ok( 'event-filter uses .eem-toolbar-select', preg_match( '/<select[^>]*class="eem-toolbar-select"[^>]*name="event"/', $html ) > 0, $pass, $fail, $log );
ok( 'event-filter onchange auto-submits', str_contains( $html, 'onchange="this.form.submit()"' ),         $pass, $fail, $log );
ok( 'first event option is "All events"', str_contains( $html, '<option value="">All events</option>' ),  $pass, $fail, $log );
ok( 'billing-tabs primitive .eem-filter-tabs', str_contains( $html, 'class="eem-filter-tabs"' ),          $pass, $fail, $log );
$tab_count = preg_match_all( '/class="eem-filter-tab(?:\s|")/', $html );
ok( 'renders all 6 billing tabs (incl. Trash)', 6 === $tab_count, $pass, $fail, $log, "got {$tab_count}" );
ok( '"All" tab active by default', str_contains( $html, 'eem-filter-tab active' ),                        $pass, $fail, $log );

echo "\n[2] Toolbar — Row 2 (bulk-form + type-filter + search + count)\n";
ok( 'bulk-form present (.eem-bulk-form)',         str_contains( $html, 'class="eem-bulk-form"' ),                    $pass, $fail, $log );
ok( 'bulk select uses .eem-toolbar-select',       preg_match( '/<select[^>]*class="eem-toolbar-select"[^>]*name="bulk_action"/', $html ) > 0, $pass, $fail, $log );
ok( 'bulk Apply button is type="button" (opens modal, not submit)', preg_match( '/<button\s+type="button"[^>]*data-eem-action="orders-bulk-apply"/', $html ) > 0, $pass, $fail, $log );
ok( 'bulk select has Refund Selected option',     str_contains( $html, 'Refund Selected' ),                          $pass, $fail, $log );
ok( 'type-filter form present',                   str_contains( $html, 'class="eem-type-filter-form"' ),             $pass, $fail, $log );
ok( 'type-filter dropdown has "All Types" default option', str_contains( $html, '<option value="">All Types</option>' ), $pass, $fail, $log );
foreach ( array( 'stall', 'rv', 'addon', 'group' ) as $k ) {
	ok( "type-filter has option value=\"{$k}\"", preg_match( '/<option value="' . $k . '"[^>]*>/', $html ) > 0, $pass, $fail, $log );
}
ok( 'type-filter Filter button',                  preg_match( '/<button\s+type="submit"[^>]*class="eem-toolbar-btn"[^>]*>\s*Filter/', $html ) > 0, $pass, $fail, $log );
ok( 'search form present (.eem-search-form)',     str_contains( $html, 'class="eem-search-form"' ),                  $pass, $fail, $log );
ok( 'search-wrap uses --attached modifier',       str_contains( $html, 'class="eem-search-wrap eem-search-wrap--attached"' ), $pass, $fail, $log );
ok( 'search input reuses .eem-search-input',      str_contains( $html, 'class="eem-search-input"' ),                 $pass, $fail, $log );
ok( 'search Search Orders button',                str_contains( $html, '>Search Orders<' ),                          $pass, $fail, $log );
ok( 'item-count primitive .eem-item-count',       str_contains( $html, 'class="eem-item-count"' ),                   $pass, $fail, $log );

echo "\n[4] Desktop table — 8 headers\n";
ok( 'desktop-table wrapper present',            str_contains( $html, 'class="eem-desktop-table"' ),        $pass, $fail, $log );
ok( 'table.eem-table present',                  str_contains( $html, 'class="eem-table"' ),                $pass, $fail, $log );
ok( 'cb header column',                         str_contains( $html, 'data-eem-action="orders-toggle-all"' ), $pass, $fail, $log );
foreach ( array( 'Order', 'Customer', 'Event', 'Type', 'Status', 'Date', 'Actions' ) as $col ) {
	// Sortable headers wrap the label in <a>...<span class="eem-sort-icon">,
	// non-sortable headers render label as bare text inside <th>. Both
	// land somewhere in the <thead>; use a broader contains check.
	ok( "header column '{$col}' present", false !== strpos( $html, $col ), $pass, $fail, $log );
}
ok( 'Order header is sortable',                 str_contains( $html, 'orderby=order_number' ),             $pass, $fail, $log );
ok( 'Status header is sortable',                str_contains( $html, 'orderby=status' ),                   $pass, $fail, $log );
ok( 'Date header is sortable',                  str_contains( $html, 'orderby=date' ),                     $pass, $fail, $log );

echo "\n[5] Mobile cards — generic primitive reuse\n";
ok( 'mobile-cards container present',           str_contains( $html, 'class="eem-mobile-cards"' ),         $pass, $fail, $log );

echo "\n[6] Pagination footer\n";
ok( 'table-footer present',                     str_contains( $html, 'class="eem-table-footer"' ),         $pass, $fail, $log );
ok( 'footer info text rendered',                preg_match( '/Showing\s+\d+/', $html ) || str_contains( $html, 'No orders to display' ), $pass, $fail, $log );

echo "\n[7] Row rendering against real fixtures\n";
$page = EEM_Orders_List_Repo::get_paginated( array( 'per_page' => 5 ) );
if ( $page['total'] > 0 ) {
	$first = $page['items'][0];
	$key = $first['order_key'];
	ok( "row carries data-order-key for first item", str_contains( $html, 'data-order-key="' . esc_attr( $key ) . '"' ), $pass, $fail, $log );
	ok( "row carries data-billing attr",             preg_match( '/data-billing="(all|paid|unpaid|refunded|cancelled)"/', $html ) > 0, $pass, $fail, $log );
	ok( "Print Receipt action button rendered",      str_contains( $html, 'data-eem-action="order-print-receipt"' ), $pass, $fail, $log );
	ok( "meatballs (eem-more-btn) rendered",         str_contains( $html, 'class="eem-more-btn"' ), $pass, $fail, $log );
	ok( "row-dropdown shell rendered",               str_contains( $html, 'class="eem-row-dropdown"' ), $pass, $fail, $log );
	ok( "status-badge variant class applied",        preg_match( '/eem-status-badge eem-status-(paid|unpaid|partial|invoice|refunded|cancelled)/', $html ) > 0, $pass, $fail, $log );
} else {
	$lg[] = "  (no fixtures — skipping row render assertions)";
}

echo "\n[8] Empty-state path (impossible-type filter)\n";
// C5.F-toolbar: empty types[] semantics gone. Use an unknown event
// label that won't match anything to trigger empty state.
$_GET = array( 'page' => EEM_Orders_List_Page::MENU_SLUG, 'event' => 'NoSuchEvent_XYZ' );
ob_start();
( new EEM_Orders_List_Page() )->render();
$empty_html = ob_get_clean();
ok( 'unknown event triggers empty-state row', str_contains( $empty_html, 'eem-table-empty' ), $pass, $fail, $log );
ok( 'empty state copy "No orders match"',     str_contains( $empty_html, 'No orders match' ),  $pass, $fail, $log );

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
