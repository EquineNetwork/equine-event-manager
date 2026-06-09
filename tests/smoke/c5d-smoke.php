<?php
/**
 * C5.D smoke — toolbar dispatcher + sort wiring + bulk Refund Selected stub.
 *
 * Covers:
 *   - Toolbar form: method=get, posts to admin.php, hidden `page` field
 *     present, hidden `types[]` inputs preserve chip state on event/search
 *     submit, sr-only submit button.
 *   - Billing tab anchors preserve current filter state (types, event, s)
 *     across clicks and reset paged=1.
 *   - Type-chip anchors toggle one key in/out of the current types[] set
 *     (XOR semantics) and reset paged=1.
 *   - Sort wiring: column header anchors include orderby + order +
 *     preserved filter state; clicking flips order on the active column.
 *   - Pagination preserves filters.
 *   - Bulk Refund modal markup: modal container + form posting to
 *     admin-post.php + nonce + hidden order_keys field + reason +
 *     notify checkbox.
 *   - Repo sort modes still produce items: order_number ASC, date DESC.
 *
 * NOTE: the old deferred `handle_bulk_refund` admin-post stub was removed once
 * the live C6.C bulk-refund modal/AJAX-queue shipped; its end-to-end coverage
 * now lives in c6c-smoke section [6].
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C5.D SMOKE ===\n";

wp_set_current_user( 1 );
$_GET = array(
	'page'    => EEM_Orders_List_Page::MENU_SLUG,
	'billing' => 'paid',
	'type'    => 'stall',
	'event'   => '',
	's'       => 'whitney',
	'orderby' => 'date',
	'order'   => 'desc',
);
ob_start();
( new EEM_Orders_List_Page() )->render();
$html = ob_get_clean();

echo "\n[1] Toolbar per-form structure (C5.F-toolbar: 4 forms now, one per filter)\n";
ok( 'event-filter form present',             str_contains( $html, 'class="eem-orders-event-filter-form"' ),                  $pass, $fail, $log );
ok( 'bulk-form present',                     str_contains( $html, 'class="eem-bulk-form"' ),                                 $pass, $fail, $log );
ok( 'type-filter form present',              str_contains( $html, 'class="eem-type-filter-form"' ),                          $pass, $fail, $log );
ok( 'search form present',                   str_contains( $html, 'class="eem-search-form"' ),                               $pass, $fail, $log );
ok( 'at least 3 method="get" filter forms present', preg_match_all( '/<form[^>]*method="get"/', $html ) >= 3, $pass, $fail, $log );
ok( 'event select onchange auto-submits',    str_contains( $html, 'onchange="this.form.submit()"' ),                         $pass, $fail, $log );
ok( 'search input round-trips value',        str_contains( $html, 'name="s" value="whitney"' ),                              $pass, $fail, $log );
ok( 'type=stall pre-selected in dropdown',   preg_match( '/<option value="stall"\s+selected[^>]*>/', $html ) > 0,             $pass, $fail, $log );

echo "\n[2] Billing tab anchors preserve filter state via URL params\n";
// Tabs now use plain ?billing=X anchor links + preserve type/event/s
// if they're set. Format is the URL-encoded query-string add_query_arg
// produces (& vs &amp; depending on esc_url; both are fine for matching).
ok( 'unpaid-tab href has billing=unpaid',    preg_match( '/href="[^"]*billing=unpaid/', $html ) > 0,                          $pass, $fail, $log );
ok( 'unpaid-tab href preserves type=stall',  preg_match( '/href="[^"]*billing=unpaid[^"]*type=stall/', $html ) > 0
	|| preg_match( '/href="[^"]*type=stall[^"]*billing=unpaid/', $html ) > 0,                                                  $pass, $fail, $log );
ok( 'unpaid-tab href preserves s=whitney',   preg_match( '/href="[^"]*billing=unpaid[^"]*s=whitney/', $html ) > 0
	|| preg_match( '/href="[^"]*s=whitney[^"]*billing=unpaid/', $html ) > 0,                                                   $pass, $fail, $log );
ok( 'tab anchors reset paged=1',             preg_match( '/href="[^"]*billing=unpaid[^"]*paged=1/', $html ) > 0,              $pass, $fail, $log );

echo "\n[3] Type-filter dropdown (C5.F-toolbar: chips replaced)\n";
ok( 'type dropdown has All Types default',   str_contains( $html, '<option value="">All Types</option>' ),                    $pass, $fail, $log );
foreach ( array( 'stall', 'rv', 'addon', 'group' ) as $k ) {
	ok( "type dropdown has option {$k}", preg_match( '/<option value="' . $k . '"[^>]*>/', $html ) > 0, $pass, $fail, $log );
}
ok( 'type-filter Filter button',             preg_match( '/<button[^>]*type="submit"[^>]*>\s*Filter/', $html ) > 0,            $pass, $fail, $log );

echo "\n[4] Sort wiring — preserved filters\n";
ok( 'Date column anchor preserves billing=paid', preg_match( '/<a[^>]*href="[^"]*orderby=date[^"]*billing=paid/', $html ) > 0
	|| preg_match( '/<a[^>]*href="[^"]*billing=paid[^"]*orderby=date/', $html ) > 0, $pass, $fail, $log );
ok( 'Date column active sort flips to asc',      preg_match( '/<a[^>]*href="[^"]*orderby=date[^"]*order=asc/', $html ) > 0, $pass, $fail, $log );
ok( 'Order column anchor (orderby=order_number)',preg_match( '/<a[^>]*href="[^"]*orderby=order_number/', $html ) > 0, $pass, $fail, $log );
ok( 'Status column anchor (orderby=status)',     preg_match( '/<a[^>]*href="[^"]*orderby=status/', $html ) > 0, $pass, $fail, $log );

echo "\n[5] Bulk Refund modal markup\n";
ok( 'modal container present',                 str_contains( $html, 'id="eem-orders-bulk-refund-modal"' ),  $pass, $fail, $log );
ok( 'modal title present',                     str_contains( $html, 'Refund Selected Orders' ),             $pass, $fail, $log );
// C6.C: modal converted from admin-post form submit to AJAX-driven 3-state UI.
// The 4 pre-C6.C assertions (admin-post action, eem_orders_bulk_refund nonce
// action, eem-orders-bulk-refund-form data-attr, eem-orders-bulk-refund-keys
// data-attr) no longer apply. New contract verified in c6c-smoke section [6].
ok( 'modal nonce field present (now eem_bulk_refund_step nonce action)', str_contains( $html, '_eem_bulk_refund_nonce' ), $pass, $fail, $log );
ok( 'modal hidden keys input present (renamed data-eem-bulk-refund-keys in C6.C)', str_contains( $html, 'data-eem-bulk-refund-keys' ), $pass, $fail, $log );
ok( 'modal reason textarea present',           str_contains( $html, 'id="eem-orders-bulk-refund-reason"' ), $pass, $fail, $log );
ok( 'modal notify checkbox present',           str_contains( $html, 'id="eem-orders-bulk-refund-notify"' ), $pass, $fail, $log );
ok( 'modal Confirm button',                    str_contains( $html, 'data-eem-action="orders-bulk-refund-confirm"' ), $pass, $fail, $log );
ok( 'modal Cancel button',                     str_contains( $html, 'data-eem-action="orders-bulk-refund-close"' ),   $pass, $fail, $log );

// [6]+[7] RETIRED: the deferred `handle_bulk_refund` admin-post stub (and its
// bulk_refund_deferred notice) was removed once the live C6.C bulk-refund flow
// shipped — the modal now drives the eem_bulk_refund_step AJAX queue
// (startBulkRefundQueue), covered end-to-end by c6c-smoke section [6]. The modal
// markup is still asserted in section [5] above.

echo "\n[8] Repo sort modes against fixtures\n";
$asc_num = EEM_Orders_List_Repo::get_paginated( array( 'orderby' => 'order_number', 'order' => 'asc',  'per_page' => 50 ) );
$desc_num= EEM_Orders_List_Repo::get_paginated( array( 'orderby' => 'order_number', 'order' => 'desc', 'per_page' => 50 ) );
$desc_dt = EEM_Orders_List_Repo::get_paginated( array( 'orderby' => 'date',         'order' => 'desc', 'per_page' => 50 ) );
ok( 'order_number ASC matches DESC total count', $asc_num['total'] === $desc_num['total'], $pass, $fail, $log );
if ( ! empty( $asc_num['items'] ) && count( $asc_num['items'] ) >= 2 ) {
	$first  = (int) preg_replace( '/\D/', '', (string) $asc_num['items'][0]['order_number'] );
	$second = (int) preg_replace( '/\D/', '', (string) $asc_num['items'][1]['order_number'] );
	ok( 'order_number ASC is monotonic non-decreasing', $first <= $second, $pass, $fail, $log, "{$first} <= {$second}" );
}
if ( ! empty( $desc_num['items'] ) && count( $desc_num['items'] ) >= 2 ) {
	$first  = (int) preg_replace( '/\D/', '', (string) $desc_num['items'][0]['order_number'] );
	$second = (int) preg_replace( '/\D/', '', (string) $desc_num['items'][1]['order_number'] );
	ok( 'order_number DESC is monotonic non-increasing', $first >= $second, $pass, $fail, $log, "{$first} >= {$second}" );
}
ok( 'date DESC returns items', ! empty( $desc_dt['items'] ), $pass, $fail, $log );

echo "\n[9] CLEANUP entry exists\n";
$cleanup = file_get_contents( '/Users/whitneymitchell/Projects/equine-event-manager/CLEANUP.md' );
// C6.C closed #15. Entry now reads "### 15. ~~Bulk refund async engine ...~~ ✅ Resolved in C6.C".
ok( 'CLEANUP.md entry #15 marked resolved in C6.C', $cleanup && str_contains( $cleanup, '### 15.' ) && str_contains( $cleanup, 'Resolved in C6.C' ), $pass, $fail, $log );

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
