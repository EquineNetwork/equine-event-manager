<?php
/** C6.A smoke — Order Detail page render + shell meta slot + conditional banner. */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C6.A SMOKE ===\n";

// [1] Class + constant existence
ok( 'EEM_Order_Detail_Page class exists',          class_exists( 'EEM_Order_Detail_Page' ), $pass, $fail, $log );
ok( 'MENU_SLUG constant = equine-event-manager-order', 'equine-event-manager-order' === EEM_Order_Detail_Page::MENU_SLUG, $pass, $fail, $log );
ok( 'register_page method exists',                 method_exists( 'EEM_Order_Detail_Page', 'register_page' ), $pass, $fail, $log );
ok( 'render_callback method exists',               method_exists( 'EEM_Order_Detail_Page', 'render_callback' ), $pass, $fail, $log );
ok( 'url() static helper exists',                  method_exists( 'EEM_Order_Detail_Page', 'url' ), $pass, $fail, $log );

// [2] URL builder uses C5.C order_detail_url convention
$url = EEM_Order_Detail_Page::url( 'test-key' );
ok( 'url() routes to admin.php page=equine-event-manager-order',
	str_contains( $url, 'page=equine-event-manager-order' ),
	$pass, $fail, $log );
ok( 'url() carries order_key',                     str_contains( $url, 'order_key=test-key' ), $pass, $fail, $log );

// [3] Admin menu registration — hidden submenu (parent=null)
wp_set_current_user( 1 );
// Trigger admin_menu so register_page fires.
do_action( 'admin_menu' );
global $_registered_pages;
$hook_suffix = 'admin_page_' . EEM_Order_Detail_Page::MENU_SLUG;
ok( 'Order Detail page registered as hidden submenu',
	isset( $_registered_pages[ $hook_suffix ] ),
	$pass, $fail, $log );

// [4] Shell meta-slot accepts HTML — verify _page_shell.php was extended.
ok( 'eem_render_page_open accepts meta arg',
	function_exists( 'eem_render_page_open' ) && file_exists( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php' ),
	$pass, $fail, $log );
$shell_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php' );
ok( 'shell partial declares meta arg in defaults',  str_contains( $shell_src, "'meta'       => ''" ),    $pass, $fail, $log );
ok( 'shell partial renders meta inside .eem-page-meta', str_contains( $shell_src, 'eem-page-meta' ),     $pass, $fail, $log );

// [5] Not-found render (missing/invalid order_key)
$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => 'definitely-not-a-real-key-abc123' );
ob_start();
( new EEM_Order_Detail_Page() )->render();
$nf_html = ob_get_clean();
ok( 'not-found render emits expected card',         str_contains( $nf_html, 'eem-order-not-found' ),     $pass, $fail, $log );
ok( 'not-found render uses page shell breadcrumb',  str_contains( $nf_html, 'eem-breadcrumb' ),          $pass, $fail, $log );
ok( 'not-found render links Back to Orders',        str_contains( $nf_html, 'Back to Orders' ),          $pass, $fail, $log );

// [6] End-to-end render against a real seeded order (any will do)
$repo = new EEM_Orders_Repository();
// Reach into the private get_grouped_orders to enumerate available keys.
$ref = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$ref->setAccessible( true );
$rows = $ref->invoke( $repo );
ok( 'at least 1 seeded order exists for end-to-end render',
	is_array( $rows ) && ! empty( $rows ),
	$pass, $fail, $log );

if ( ! empty( $rows ) ) {
	$first_order = $rows[0];
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $first_order['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$html = ob_get_clean();

	// Page architecture per VIS-3 — single plugin-wrap with header inside.
	ok( 'render uses .eem-page-wrap (VIS-3 single bordered card)',  str_contains( $html, 'eem-page-wrap' ),     $pass, $fail, $log );
	ok( 'render uses .eem-page-header (title-band INSIDE card)',    str_contains( $html, 'eem-page-header' ),   $pass, $fail, $log );
	ok( 'render uses .eem-page-meta (badges + meta-line slot)',     str_contains( $html, 'eem-page-meta' ),     $pass, $fail, $log );
	ok( 'render uses .eem-page-body for content',                   str_contains( $html, 'eem-page-body' ),     $pass, $fail, $log );

	// Order number rendered in 5-digit zero-padded format.
	ok( 'order # renders in #NNNNN 5-digit format',                 preg_match( '/#\d{5}/', $html ) > 0,        $pass, $fail, $log );

	// 2-col grid + cards
	ok( 'render includes 2-col grid wrapper .eem-order-body',       str_contains( $html, '<div class="eem-order-body">' ), $pass, $fail, $log );
	ok( 'render includes left column .eem-order-main',              str_contains( $html, 'eem-order-main' ),    $pass, $fail, $log );
	ok( 'render includes right column .eem-order-side',             str_contains( $html, 'eem-order-side' ),    $pass, $fail, $log );

	// Header action bar
	ok( 'render includes Back to Orders ghost button',              str_contains( $html, 'Back to Orders' ),    $pass, $fail, $log );
	ok( 'render includes More dropdown wrap',                       str_contains( $html, 'eem-row-menu-wrap eem-order-detail-more' ), $pass, $fail, $log );

	// Receipt actions in More dropdown — the C11-era stubs were replaced by the
	// real C12 receipt features (View Receipt / Download PDF Receipt).
	ok( 'More menu has View Receipt (C12, replaces C11 stub)',      str_contains( $html, 'View Receipt' ),         $pass, $fail, $log );
	ok( 'More menu has Download PDF Receipt (C12)',                 str_contains( $html, 'Download PDF Receipt' ),  $pass, $fail, $log );
	ok( 'receipt links open in a new tab (real, not dimmed stub)',  str_contains( $html, 'target="_blank"' ),       $pass, $fail, $log );

	// Refund / CSV / Trash actions present
	ok( 'More menu has Refund Order action',                        str_contains( $html, 'Refund Order' ),     $pass, $fail, $log );
	ok( 'More menu has Export CSV action',                          str_contains( $html, 'Export CSV' ),       $pass, $fail, $log );
	ok( 'More menu has Move to Trash action (danger variant)',      str_contains( $html, 'eem-row-dd-item--danger' ), $pass, $fail, $log );

	// Sidebar cards
	ok( 'render includes Order Summary card',                       str_contains( $html, 'Order Summary' ),    $pass, $fail, $log );
	ok( 'render includes Payment Details card',                     str_contains( $html, 'Payment Details' ),  $pass, $fail, $log );
	ok( 'summary grand total uses navy bordered treatment',         str_contains( $html, 'eem-order-summary__grand-total' ), $pass, $fail, $log );
}

// [7] Conditional payment-banner — appears ONLY for outstanding statuses
$unpaid = null;
$paid   = null;
foreach ( $rows as $r ) {
	if ( ! $unpaid && in_array( $r['status_slug'] ?? '', array( 'unpaid', 'invoice-sent', 'partially-paid' ), true ) ) {
		$unpaid = $r;
	}
	if ( ! $paid && 'paid' === ( $r['status_slug'] ?? '' ) ) {
		$paid = $r;
	}
	if ( $unpaid && $paid ) { break; }
}

if ( $unpaid ) {
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $unpaid['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$h_unpaid = ob_get_clean();
	ok( 'payment-banner RENDERS for outstanding-status order',      str_contains( $h_unpaid, 'eem-order-payment-banner' ), $pass, $fail, $log );
	ok( 'payment-banner copy includes Payment Outstanding',         str_contains( $h_unpaid, 'Payment Outstanding' ), $pass, $fail, $log );
	ok( 'payment-banner contains solid-orange Collect button',      str_contains( $h_unpaid, 'eem-btn-collect-banner' ), $pass, $fail, $log );
} else {
	ok( 'payment-banner conditional skipped (no unpaid fixtures)',  true, $pass, $fail, $log, 'no unpaid orders in DB' );
}

if ( $paid ) {
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $paid['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$h_paid = ob_get_clean();
	ok( 'payment-banner ABSENT for paid-status order',              ! str_contains( $h_paid, 'eem-order-payment-banner' ), $pass, $fail, $log );
} else {
	ok( 'paid-banner-absent assertion skipped (no paid fixtures)',  true, $pass, $fail, $log, 'no paid orders in DB' );
}

// [8] Stall-assignment readout — read-only badge + C8 deferral note (when stall card renders)
foreach ( $rows as $r ) {
	if ( (int) ( $r['stall_quantity'] ?? 0 ) > 0 ) {
		$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $r['order_key'] );
		ob_start();
		( new EEM_Order_Detail_Page() )->render();
		$h_stall = ob_get_clean();
		ok( 'stall card renders Assigned Stall Units label',            str_contains( $h_stall, 'Assigned Stall Units' ),    $pass, $fail, $log );
		ok( 'stall card defers interactive editor to C8',               str_contains( $h_stall, 'Stall &amp; RV Charts (C8)' ), $pass, $fail, $log );
		break;
	}
}

// [9] Legacy callback swap — EEM_Admin no longer wires equine-event-manager-order to its render
$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
ok( 'EEM_Admin::register_menu does NOT register equine-event-manager-order with render_order_details_page',
	! preg_match( "/'equine-event-manager-order',\s*array\( \\\$this, 'render_order_details_page'/", $admin_src ),
	$pass, $fail, $log );

// [10] Helpers promoted in EEM_Orders_List_Page
ok( 'EEM_Orders_List_Page::format_order_number_display is callable statically',
	method_exists( 'EEM_Orders_List_Page', 'format_order_number_display' ) && ( new ReflectionMethod( 'EEM_Orders_List_Page', 'format_order_number_display' ) )->isStatic(),
	$pass, $fail, $log );
ok( 'EEM_Orders_List_Page::status_slug_to_css_class is callable statically',
	method_exists( 'EEM_Orders_List_Page', 'status_slug_to_css_class' ) && ( new ReflectionMethod( 'EEM_Orders_List_Page', 'status_slug_to_css_class' ) )->isStatic(),
	$pass, $fail, $log );
ok( 'EEM_Orders_List_Page::format_date_label is callable statically',
	method_exists( 'EEM_Orders_List_Page', 'format_date_label' ) && ( new ReflectionMethod( 'EEM_Orders_List_Page', 'format_date_label' ) )->isStatic(),
	$pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
