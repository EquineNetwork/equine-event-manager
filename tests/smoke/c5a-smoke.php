<?php
/** C5.A smoke — Orders list repo + page controller scaffold + class loading. */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C5.A SMOKE ===\n";

echo "\n[1] Class loading\n";
ok( 'EEM_Orders_List_Repo class exists', class_exists( 'EEM_Orders_List_Repo' ), $pass, $fail, $log );
ok( 'EEM_Orders_List_Page class exists', class_exists( 'EEM_Orders_List_Page' ), $pass, $fail, $log );
ok( 'legacy EEM_Orders_Repository still loaded', class_exists( 'EEM_Orders_Repository' ), $pass, $fail, $log );

echo "\n[2] Billing tabs API\n";
$tabs = EEM_Orders_List_Repo::billing_tabs();
ok( 'billing_tabs returns 5 entries', count( $tabs ) === 5, $pass, $fail, $log );
foreach ( array( 'all', 'paid', 'unpaid', 'refunded', 'cancelled' ) as $tab ) {
	ok( "tab '{$tab}' registered", isset( $tabs[ $tab ] ), $pass, $fail, $log );
}
ok( "tab_label('paid') non-empty", '' !== EEM_Orders_List_Repo::tab_label( 'paid' ), $pass, $fail, $log );
ok( "tab_label('garbage') falls back to All", EEM_Orders_List_Repo::tab_label( 'garbage' ) === $tabs['all'], $pass, $fail, $log );

echo "\n[3] Type-chip + status mapping\n";
$types = EEM_Orders_List_Repo::type_filter_keys();
ok( 'type_filter_keys returns 4 entries', count( $types ) === 4, $pass, $fail, $log );
ok( 'type_filter_keys canonical order',   $types === array( 'stall', 'rv', 'addon', 'group' ), $pass, $fail, $log );
ok( "map_status_slug 'paid' → paid",                 'paid'      === EEM_Orders_List_Repo::map_status_slug_to_tab( 'paid' ),               $pass, $fail, $log );
ok( "map_status_slug 'unpaid' → unpaid",             'unpaid'    === EEM_Orders_List_Repo::map_status_slug_to_tab( 'unpaid' ),             $pass, $fail, $log );
// HYPHENATED slugs — what legacy EEM_Orders_Repository actually emits
// (C5.F-polish lock-in; earlier underscored arms were dead).
ok( "map_status_slug 'invoice-sent' → unpaid",       'unpaid'    === EEM_Orders_List_Repo::map_status_slug_to_tab( 'invoice-sent' ),       $pass, $fail, $log );
ok( "map_status_slug 'partially-refunded' → unpaid", 'unpaid'    === EEM_Orders_List_Repo::map_status_slug_to_tab( 'partially-refunded' ), $pass, $fail, $log );
// Underscored variants — still map to unpaid via default arm (forward-compat).
ok( "map_status_slug 'pending' → unpaid (default)",  'unpaid'    === EEM_Orders_List_Repo::map_status_slug_to_tab( 'pending' ),            $pass, $fail, $log );
ok( "map_status_slug 'refunded' → refunded",         'refunded'  === EEM_Orders_List_Repo::map_status_slug_to_tab( 'refunded' ),           $pass, $fail, $log );
ok( "map_status_slug 'cancelled' → cancelled",       'cancelled' === EEM_Orders_List_Repo::map_status_slug_to_tab( 'cancelled' ),          $pass, $fail, $log );
ok( "map_status_slug unknown → unpaid",              'unpaid'    === EEM_Orders_List_Repo::map_status_slug_to_tab( 'wat' ),                $pass, $fail, $log );

echo "\n[4] derive_type_keys decoding\n";
$row_a = array( 'type' => 'Stall, Add-On' );
$keys_a = EEM_Orders_List_Repo::derive_type_keys( $row_a );
ok( "'Stall, Add-On' → [stall,addon]", $keys_a === array( 'stall', 'addon' ), $pass, $fail, $log, var_export( $keys_a, true ) );
$row_b = array( 'type' => 'RV, Group, Stall' );
$keys_b = EEM_Orders_List_Repo::derive_type_keys( $row_b );
ok( "canonical order regardless of input ordering", $keys_b === array( 'stall', 'rv', 'group' ), $pass, $fail, $log, var_export( $keys_b, true ) );
$row_c = array( 'type' => '' );
ok( "empty type string → empty array",   array() === EEM_Orders_List_Repo::derive_type_keys( $row_c ), $pass, $fail, $log );
$row_d = array();
ok( "missing type key → empty array",    array() === EEM_Orders_List_Repo::derive_type_keys( $row_d ), $pass, $fail, $log );

echo "\n[5] counts_by_billing_status\n";
$counts = EEM_Orders_List_Repo::counts_by_billing_status();
ok( 'counts returns 5 keys', count( $counts ) === 5, $pass, $fail, $log );
foreach ( array( 'all', 'paid', 'unpaid', 'refunded', 'cancelled' ) as $tab ) {
	ok( "counts[{$tab}] is int", isset( $counts[ $tab ] ) && is_int( $counts[ $tab ] ), $pass, $fail, $log );
}
ok( 'counts[all] = sum of 4 buckets', $counts['all'] === ( $counts['paid'] + $counts['unpaid'] + $counts['refunded'] + $counts['cancelled'] ), $pass, $fail, $log,
	"all={$counts['all']} paid={$counts['paid']} unpaid={$counts['unpaid']} refunded={$counts['refunded']} cancelled={$counts['cancelled']}" );

echo "\n[6] get_paginated shape\n";
$page = EEM_Orders_List_Repo::get_paginated( array( 'per_page' => 5, 'paged' => 1 ) );
ok( 'get_paginated returns items array',     isset( $page['items'] ) && is_array( $page['items'] ), $pass, $fail, $log );
ok( 'get_paginated returns total int',       isset( $page['total'] ) && is_int( $page['total'] ),   $pass, $fail, $log );
ok( 'get_paginated returns total_pages int', isset( $page['total_pages'] ) && is_int( $page['total_pages'] ), $pass, $fail, $log );
ok( 'get_paginated returns page = 1',        isset( $page['page'] ) && 1 === $page['page'],         $pass, $fail, $log );
ok( 'get_paginated returns per_page = 5',    isset( $page['per_page'] ) && 5 === $page['per_page'], $pass, $fail, $log );
ok( 'get_paginated items count <= per_page', count( $page['items'] ) <= 5,                          $pass, $fail, $log );

echo "\n[7] Filter semantics — type='' (All Types) returns all items\n";
// C5.F-toolbar replaced the multi-select chip semantics ("empty types[]
// shows nothing") with single-select dropdown semantics ("empty type
// means All Types — no filter applied").
$all_types = EEM_Orders_List_Repo::get_paginated( array( 'type' => '', 'per_page' => 25 ) );
$stall = EEM_Orders_List_Repo::get_paginated( array( 'type' => 'stall', 'per_page' => 25 ) );
ok( 'type="" returns all items',                 $all_types['total'] >= $stall['total'], $pass, $fail, $log, "all={$all_types['total']} stall={$stall['total']}" );
ok( 'type="garbage" falls through to All Types', EEM_Orders_List_Repo::get_paginated( array( 'type' => 'garbage', 'per_page' => 25 ) )['total'] === $all_types['total'], $pass, $fail, $log );

echo "\n[8] Filter semantics — billing=cancelled <= all\n";
$all = EEM_Orders_List_Repo::get_paginated( array( 'billing_status' => 'all',       'per_page' => 100 ) );
$can = EEM_Orders_List_Repo::get_paginated( array( 'billing_status' => 'cancelled', 'per_page' => 100 ) );
ok( 'cancelled.total <= all.total', $can['total'] <= $all['total'], $pass, $fail, $log );
ok( 'cancelled.total === counts[cancelled]', $can['total'] === $counts['cancelled'], $pass, $fail, $log );

echo "\n[9] Event filter options shape\n";
$opts = EEM_Orders_List_Repo::get_event_filter_options();
ok( 'event filter options is array', is_array( $opts ), $pass, $fail, $log );

echo "\n[10] Page controller scaffold\n";
ok( 'EEM_Orders_List_Page::MENU_SLUG is equine-event-manager-orders', 'equine-event-manager-orders' === EEM_Orders_List_Page::MENU_SLUG, $pass, $fail, $log );
$url = EEM_Orders_List_Page::url( array( 'billing' => 'paid' ) );
ok( 'url() includes page param',     str_contains( $url, 'page=equine-event-manager-orders' ), $pass, $fail, $log );
ok( 'url() includes billing param',  str_contains( $url, 'billing=paid' ),                     $pass, $fail, $log );

wp_set_current_user( 1 );
$_GET = array( 'page' => EEM_Orders_List_Page::MENU_SLUG );
ob_start();
try {
	( new EEM_Orders_List_Page() )->render();
	$html = ob_get_clean();
	ok( 'render() produces output',                strlen( $html ) > 200, $pass, $fail, $log, 'len=' . strlen( $html ) );
	ok( 'render() contains page title "Orders"',   str_contains( $html, '>Orders<' ),         $pass, $fail, $log );
	ok( 'render() contains Create Order CTA',      str_contains( $html, 'Create Order' ),     $pass, $fail, $log );
	ok( 'render() contains eem-orders-list class', str_contains( $html, 'eem-orders-list' ),  $pass, $fail, $log );
	ok( 'render() contains eem-breadcrumb',        str_contains( $html, 'eem-breadcrumb' ),   $pass, $fail, $log );
	ok( 'render() uses wrap=true page-header',     str_contains( $html, 'eem-page-header' ) && ! str_contains( $html, 'eem-page-header--standalone' ), $pass, $fail, $log );
} catch ( Throwable $e ) {
	ob_end_clean();
	ok( 'render() does not throw', false, $pass, $fail, $log, $e->getMessage() );
}

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
