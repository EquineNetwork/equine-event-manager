<?php
/**
 * C4.C smoke — row-action handlers + Email Customers modal.
 *
 * Covers:
 *   - 7 handler methods exist on EEM_Reservations_List_Page
 *   - 6 admin_post + wp_ajax hooks registered
 *   - Email Customers modal markup rendered in page output
 *   - Inline ?eem_notice= renders the matching success/error notice
 *   - Duplicate handler end-to-end against a throwaway reservation
 *     (creates source, runs handler with valid nonce + cap, asserts
 *      redirect URL contains eem_notice=duplicated, verifies a new
 *      draft was created with the meta copied)
 *   - Recipient resolver dedup against a seeded order row
 *
 * Re-runnable: creates + deletes its own test posts; no leftover state.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C4.C SMOKE ===\n";

echo "\n[1] Static handler methods exist\n";
foreach ( array( 'handle_duplicate', 'handle_trash', 'handle_restore', 'handle_export_roster', 'handle_email_customers_ajax', 'handle_email_customers_count_ajax', 'localize_row_action_nonces' ) as $m ) {
	ok( "EEM_Reservations_List_Page::{$m}() exists", method_exists( 'EEM_Reservations_List_Page', $m ), $pass, $fail, $log );
}

echo "\n[2] admin_post + ajax hooks registered\n";
$hooks = array(
	'admin_post_eem_reservation_duplicate'        => array( 'EEM_Reservations_List_Page', 'handle_duplicate' ),
	'admin_post_eem_reservation_trash'            => array( 'EEM_Reservations_List_Page', 'handle_trash' ),
	'admin_post_eem_reservation_restore'          => array( 'EEM_Reservations_List_Page', 'handle_restore' ),
	'admin_post_eem_reservation_export_roster'    => array( 'EEM_Reservations_List_Page', 'handle_export_roster' ),
	'wp_ajax_eem_email_customers'                 => array( 'EEM_Reservations_List_Page', 'handle_email_customers_ajax' ),
	'wp_ajax_eem_email_customers_count'           => array( 'EEM_Reservations_List_Page', 'handle_email_customers_count_ajax' ),
);
foreach ( $hooks as $action => $callback ) {
	ok( "{$action} hook registered", false !== has_action( $action, $callback ), $pass, $fail, $log );
}

echo "\n[3] Modal renders in page output\n";
wp_set_current_user( 1 );
$_GET['page'] = EEM_Reservations_List_Page::MENU_SLUG;
ob_start();
( new EEM_Reservations_List_Page() )->render();
$html = ob_get_clean();
ok( 'modal div present',          str_contains( $html, 'id="eem-email-customers-modal"' ),      $pass, $fail, $log );
ok( 'modal subject input present', str_contains( $html, 'id="eem-email-customers-subject"' ),   $pass, $fail, $log );
ok( 'modal body textarea present', str_contains( $html, 'id="eem-email-customers-body"' ),      $pass, $fail, $log );
ok( 'modal Send button present',   str_contains( $html, 'data-eem-action="email-customers-send"' ), $pass, $fail, $log );
ok( 'modal Cancel button present', str_contains( $html, 'data-eem-action="email-customers-close"' ), $pass, $fail, $log );
ok( 'modal nonce field present',   str_contains( $html, '_eem_email_customers_nonce' ),         $pass, $fail, $log );

echo "\n[4] Inline notice rendering\n";
$_GET['eem_notice'] = 'duplicated';
ob_start();
( new EEM_Reservations_List_Page() )->render();
$html2 = ob_get_clean();
ok( 'notice rendered for ?eem_notice=duplicated', str_contains( $html2, 'Reservation duplicated as draft' ), $pass, $fail, $log );
unset( $_GET['eem_notice'] );

echo "\n[5] Duplicate handler end-to-end\n";
$src_id = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C4C SMOKE SRC' ) );
update_post_meta( $src_id, '_en_stall_quantity_available', 12 );
ok( 'created source reservation', $src_id > 0, $pass, $fail, $log );

$_POST = array(
	'reservation_id'    => $src_id,
	'_eem_action_nonce' => wp_create_nonce( 'eem_reservation_duplicate' ),
);
$_REQUEST = $_POST;
remove_all_filters( 'wp_redirect' );
add_filter( 'wp_redirect', function( $url ) { throw new RuntimeException( 'REDIRECTED:' . $url ); }, 1 );

try {
	EEM_Reservations_List_Page::handle_duplicate();
	ok( 'handle_duplicate redirected', false, $pass, $fail, $log, 'did not redirect' );
} catch ( RuntimeException $e ) {
	$msg = $e->getMessage();
	ok( 'handle_duplicate redirected with notice=duplicated', str_contains( $msg, 'eem_notice=duplicated' ), $pass, $fail, $log, $msg );
}

$dupes = get_posts( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	's'           => 'C4C SMOKE SRC',
	'numberposts' => 5,
) );
$dupe_found = false;
foreach ( $dupes as $d ) {
	if ( str_contains( $d->post_title, '(Copy)' ) ) { $dupe_found = $d->ID; break; }
}
ok( 'duplicate post exists as draft', $dupe_found !== false, $pass, $fail, $log );

if ( $dupe_found ) {
	$copied_meta = (int) get_post_meta( $dupe_found, '_en_stall_quantity_available', true );
	ok( 'duplicate copied stall_quantity meta', 12 === $copied_meta, $pass, $fail, $log, "got {$copied_meta}" );
	wp_delete_post( $dupe_found, true );
}

remove_all_filters( 'wp_redirect' );

echo "\n[6] Recipient resolver dedup\n";
global $wpdb;
$stall_table = $wpdb->prefix . 'en_stall_reservations';
$wpdb->insert( $stall_table, array(
	'event_source' => 'native', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'C4C Alice', 'email' => 'c4c-alice@example.com', 'phone' => '',
	'stall_qty' => 1, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
	'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
	'unit_price' => 0, 'subtotal' => 0, 'convenience_fee' => 0, 'total' => 0,
	'payment_status' => 'pending', 'payment_gateway' => '',
	'order_number' => 'C4C-SMOKE', 'transaction_id' => '', 'refund_transaction_id' => '',
	'refunded_at' => null, 'notes' => "Reservation setup ID: {$src_id}",
	'created_at' => current_time( 'mysql' ),
) );
$ref = new ReflectionMethod( 'EEM_Reservations_List_Page', 'resolve_recipients_for_reservation' );
$ref->setAccessible( true );
$emails = $ref->invoke( null, $src_id );
ok( 'resolver finds 1 email', count( $emails ) === 1, $pass, $fail, $log, var_export( $emails, true ) );
ok( 'resolver returns c4c-alice@example.com', in_array( 'c4c-alice@example.com', $emails, true ), $pass, $fail, $log );

$wpdb->delete( $stall_table, array( 'order_number' => 'C4C-SMOKE' ) );
wp_delete_post( $src_id, true );

echo "\n" . implode( "\n", $log ) . "\n\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
