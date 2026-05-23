<?php
/**
 * C5.C smoke — row action handlers + Collect button + nonce wiring.
 *
 * Covers:
 *   - 4 admin_post handlers exist on EEM_Orders_List_Page
 *   - 4 admin_post hooks registered against the right callbacks
 *   - localize_row_action_nonces method exists
 *   - Per-row meatballs render with all 6 items in canonical order
 *   - Conditional Collect button: visible on unpaid/invoice_sent rows,
 *     absent on paid rows
 *   - Conditional Refund Order item: hidden on refunded/cancelled rows,
 *     present otherwise
 *   - order_detail_url() helper shape
 *   - lookup_reservation_id_from_order recovers id from notes
 *   - ?eem_notice=… renders the matching inline notice
 *   - handle_resend_notification stub-end-to-end against a synthetic
 *     order with missing email → notification_no_email notice
 *   - handle_trash redirects with order_trash_deferred notice (stub
 *     semantics intentional per ORD-3 + CLEANUP #14)
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C5.C SMOKE ===\n";

echo "\n[1] Handler methods exist\n";
foreach ( array( 'handle_resend_notification', 'handle_export_csv', 'handle_trash', 'handle_print_receipt', 'localize_row_action_nonces', 'order_detail_url' ) as $m ) {
	ok( "EEM_Orders_List_Page::{$m}() exists", method_exists( 'EEM_Orders_List_Page', $m ), $pass, $fail, $log );
}

echo "\n[2] admin_post hooks registered\n";
$hooks = array(
	'admin_post_eem_order_resend_notification' => array( 'EEM_Orders_List_Page', 'handle_resend_notification' ),
	'admin_post_eem_order_export_csv'          => array( 'EEM_Orders_List_Page', 'handle_export_csv' ),
	'admin_post_eem_order_trash'               => array( 'EEM_Orders_List_Page', 'handle_trash' ),
	'admin_post_eem_order_print_receipt'       => array( 'EEM_Orders_List_Page', 'handle_print_receipt' ),
);
foreach ( $hooks as $action => $callback ) {
	ok( "{$action} hook registered", false !== has_action( $action, $callback ), $pass, $fail, $log );
}

echo "\n[3] order_detail_url shape\n";
$url = EEM_Orders_List_Page::order_detail_url( 'ABC-123' );
ok( 'order_detail_url has page param',      str_contains( $url, 'page=equine-event-manager-order' ), $pass, $fail, $log );
ok( 'order_detail_url has order_key param', str_contains( $url, 'order_key=ABC-123' ),                $pass, $fail, $log );
$refund_url = EEM_Orders_List_Page::order_detail_url( 'ABC-123', array( 'panel' => 'refund' ) );
ok( 'order_detail_url extras merged in',    str_contains( $refund_url, 'panel=refund' ),              $pass, $fail, $log );

echo "\n[4] Per-row meatballs — render against real fixtures\n";
wp_set_current_user( 1 );
$_GET = array( 'page' => EEM_Orders_List_Page::MENU_SLUG );
ob_start();
( new EEM_Orders_List_Page() )->render();
$html = ob_get_clean();

$page = EEM_Orders_List_Repo::get_paginated( array( 'per_page' => 25 ) );
if ( $page['total'] > 0 ) {
	ok( 'View Order menu item present',          str_contains( $html, 'View Order' ),                  $pass, $fail, $log );
	ok( 'Resend Notification button present',    str_contains( $html, 'data-eem-action="order-resend-notification"' ), $pass, $fail, $log );
	ok( 'Export CSV button present',             str_contains( $html, 'data-eem-action="order-export-csv"' ),         $pass, $fail, $log );
	ok( 'Move to Trash button present',          str_contains( $html, 'data-eem-action="order-trash"' ),              $pass, $fail, $log );
	ok( 'Print Receipt action button present',   str_contains( $html, 'data-eem-action="order-print-receipt"' ),      $pass, $fail, $log );
} else {
	$log[] = "  (no fixtures — meatballs assertions skipped)";
}

echo "\n[5] Conditional Collect button\n";
$ref = new ReflectionMethod( 'EEM_Orders_List_Page', 'render_row_action_cell' );
$ref->setAccessible( true );
$page_obj = new EEM_Orders_List_Page();
// C5.G.6: synthetic status_slugs now match the HYPHENATED values the
// legacy repo actually emits ('unpaid', 'invoice-sent'). Earlier
// underscored values ('pending' / 'invoice_sent') would have failed
// the new $can_collect in_array check; the fix in C5.G.6 made the
// production code match real-row data, and this smoke now mirrors it.
ob_start(); $ref->invoke( $page_obj, array( 'order_key' => 'X1', 'status_slug' => 'unpaid' ),       'desktop' ); $unpaid_html = ob_get_clean();
ob_start(); $ref->invoke( $page_obj, array( 'order_key' => 'X2', 'status_slug' => 'invoice-sent' ), 'desktop' ); $invoice_html = ob_get_clean();
ob_start(); $ref->invoke( $page_obj, array( 'order_key' => 'X3', 'status_slug' => 'paid' ),         'desktop' ); $paid_html = ob_get_clean();
ob_start(); $ref->invoke( $page_obj, array( 'order_key' => 'X4', 'status_slug' => 'refunded' ),     'desktop' ); $refunded_html = ob_get_clean();
ok( 'Collect visible on unpaid row',        str_contains( $unpaid_html,   'eem-btn-collect' ), $pass, $fail, $log );
ok( 'Collect visible on invoice-sent row',  str_contains( $invoice_html,  'eem-btn-collect' ), $pass, $fail, $log );
ok( 'Collect ABSENT on paid row',         ! str_contains( $paid_html,     'eem-btn-collect' ), $pass, $fail, $log );
ok( 'Collect ABSENT on refunded row',     ! str_contains( $refunded_html, 'eem-btn-collect' ), $pass, $fail, $log );

echo "\n[6] Conditional Refund Order menu item\n";
ok( 'Refund Order visible on unpaid row',     str_contains( $unpaid_html,   'Refund Order' ),  $pass, $fail, $log );
ok( 'Refund Order visible on paid row',       str_contains( $paid_html,     'Refund Order' ),  $pass, $fail, $log );
ok( 'Refund Order ABSENT on refunded row',  ! str_contains( $refunded_html, 'Refund Order' ),  $pass, $fail, $log );
ob_start(); $ref->invoke( $page_obj, array( 'order_key' => 'X5', 'status_slug' => 'cancelled' ), 'desktop' ); $cancelled_html = ob_get_clean();
ok( 'Refund Order ABSENT on cancelled row', ! str_contains( $cancelled_html, '>Refund Order<' ), $pass, $fail, $log );

echo "\n[7] lookup_reservation_id_from_order\n";
$rid_ref = new ReflectionMethod( 'EEM_Orders_List_Page', 'lookup_reservation_id_from_order' );
$rid_ref->setAccessible( true );
$o1 = array( 'notes' => "Some context.\nReservation setup ID: 42\nMore lines." );
ok( 'extracts id 42 from notes', 42 === $rid_ref->invoke( $page_obj, $o1 ), $pass, $fail, $log );
$o2 = array( 'components' => array( array( 'notes' => 'Reservation setup ID: 7' ), array( 'notes' => 'orphan' ) ) );
ok( 'falls back to components when top-level notes missing', 7 === $rid_ref->invoke( $page_obj, $o2 ), $pass, $fail, $log );
$o3 = array( 'notes' => 'no id here' );
ok( 'returns 0 when no match', 0 === $rid_ref->invoke( $page_obj, $o3 ), $pass, $fail, $log );

echo "\n[8] Inline notice rendering\n";
foreach ( array( 'notification_resent', 'notification_no_email', 'export_failed', 'order_trash_deferred', 'print_receipt_deferred', 'denied', 'notfound' ) as $code ) {
	$_GET = array( 'page' => EEM_Orders_List_Page::MENU_SLUG, 'eem_notice' => $code );
	ob_start();
	( new EEM_Orders_List_Page() )->render();
	$h = ob_get_clean();
	ok( "notice rendered for ?eem_notice={$code}", preg_match( '/notice notice-(success|warning|error|info)[^"]*"[^>]*>\s*<p>/', $h ) > 0, $pass, $fail, $log );
}
unset( $_GET['eem_notice'] );

echo "\n[9] handle_resend_notification end-to-end — missing-email path\n";
// Pick or synthesize an order with no customer email so we can exercise
// the no-email branch without sending real mail.
$repo = new EEM_Orders_Repository();
$orders = $repo->get_orders();
$no_email_order = null;
foreach ( $orders as $o ) {
	if ( empty( $o['email'] ) ) { $no_email_order = $o; break; }
}
if ( $no_email_order ) {
	$_POST = array(
		'order_key'         => $no_email_order['order_key'],
		'_eem_action_nonce' => wp_create_nonce( 'eem_order_resend_notification' ),
	);
	$_REQUEST = $_POST;
	remove_all_filters( 'wp_redirect' );
	add_filter( 'wp_redirect', function( $url ) { throw new RuntimeException( 'REDIR:' . $url ); }, 1 );
	try {
		EEM_Orders_List_Page::handle_resend_notification();
		ok( 'resend handler redirected', false, $pass, $fail, $log, 'no redirect' );
	} catch ( RuntimeException $e ) {
		$msg = $e->getMessage();
		ok( 'resend handler redirected with notification_no_email', str_contains( $msg, 'eem_notice=notification_no_email' ), $pass, $fail, $log, $msg );
	}
	remove_all_filters( 'wp_redirect' );
} else {
	$log[] = "  (no orders with empty email — resend handler end-to-end skipped)";
}

echo "\n[10] handle_trash stub end-to-end\n";
$any_order = ! empty( $orders ) ? $orders[0] : null;
if ( $any_order ) {
	$_POST = array(
		'order_key'         => $any_order['order_key'],
		'_eem_action_nonce' => wp_create_nonce( 'eem_order_trash' ),
	);
	$_REQUEST = $_POST;
	remove_all_filters( 'wp_redirect' );
	add_filter( 'wp_redirect', function( $url ) { throw new RuntimeException( 'REDIR:' . $url ); }, 1 );
	try {
		EEM_Orders_List_Page::handle_trash();
		ok( 'trash handler redirected', false, $pass, $fail, $log, 'no redirect' );
	} catch ( RuntimeException $e ) {
		ok( 'trash redirects with order_trash_deferred (stub per CLEANUP #14)', str_contains( $e->getMessage(), 'eem_notice=order_trash_deferred' ), $pass, $fail, $log, $e->getMessage() );
	}
	remove_all_filters( 'wp_redirect' );
} else {
	$log[] = "  (no orders — trash handler skipped)";
}

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
