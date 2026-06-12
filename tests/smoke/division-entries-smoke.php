<?php
/**
 * Division entrants ledger smoke (Entries → Divisions rework, Slice 2).
 *
 * Covers the EEM_Division_Entries repository (record / count / spots-left /
 * status sync), the order-status listener, the checkout spots-cap validation
 * (via EEM_Shortcodes::validate_submission), and the ledger write fold (via
 * EEM_Shortcodes::record_division_entries). Both reach the same code the
 * customer form AND admin Create Order page use.
 *
 * Run: wp eval-file tests/smoke/division-entries-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

global $wpdb;
EEM_Division_Entries::create_table();
$table = EEM_Division_Entries::table_name();
$check( 'ledger table exists', $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table );

// Seed a reservation + a published Division (spots = 2).
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Divisions Ledger Event' ) );
$did = wp_insert_post( array( 'post_type' => 'en_entry', 'post_status' => 'publish', 'post_title' => 'Divisions Ledger Event - #5 Division' ) );
update_post_meta( $did, EEM_Entries::META_RESERVATION, $rid );
update_post_meta( $did, EEM_Entries::META_DIVISION_NAME, '#5 Division' );
update_post_meta( $did, EEM_Entries::META_PRICE, '40.00' );
update_post_meta( $did, EEM_Entries::META_SPOTS, 2 );
update_post_meta( $did, EEM_Entries::META_MAX, 0 );

// --- status mapping --------------------------------------------------------
$check( 'maps paid → paid',              'paid'      === EEM_Division_Entries::ledger_status_for_order_status( 'paid' ) );
$check( 'maps invoice-sent → unpaid',    'unpaid'    === EEM_Division_Entries::ledger_status_for_order_status( 'invoice-sent' ) );
$check( 'maps refunded → refunded',      'refunded'  === EEM_Division_Entries::ledger_status_for_order_status( 'refunded' ) );
$check( 'maps cancelled → cancelled',    'cancelled' === EEM_Division_Entries::ledger_status_for_order_status( 'cancelled' ) );

// --- record + count + spots-left -------------------------------------------
$check( 'division starts with 0 entered', 0 === EEM_Division_Entries::entered_count( $did ) );
$check( 'unlimited division → null spots-left', null === EEM_Division_Entries::spots_left( $did, null ) );
$check( '2-spot division → 2 left', 2 === EEM_Division_Entries::spots_left( $did, 2 ) );

$r1 = EEM_Division_Entries::record_entry( $did, 'ORDER-A', 'Doe, Jane', 'jane@example.com', 1, 'paid' );
$r2 = EEM_Division_Entries::record_entry( $did, 'ORDER-B', 'Roe, Rick', 'rick@example.com', 1, 'unpaid' );
$check( 'record_entry returns row ids', $r1 > 0 && $r2 > 0 );
$check( 'entered_count counts paid + unpaid', 2 === EEM_Division_Entries::entered_count( $did ) );
$check( '2-spot division → 0 left (full)', 0 === EEM_Division_Entries::spots_left( $did, 2 ) );

// --- status sync: refund frees, paid promotes ------------------------------
EEM_Division_Entries::sync_status_for_order( 'ORDER-A', 'refunded' );
$check( 'refund of ORDER-A frees its spot', 1 === EEM_Division_Entries::entered_count( $did ) );
$check( '1 spot now available again', 1 === EEM_Division_Entries::spots_left( $did, 2 ) );
EEM_Division_Entries::sync_status_for_order( 'ORDER-B', 'paid' );
$rows_b = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE order_key = %s", 'ORDER-B' ) );
$check( 'ORDER-B unpaid promoted to paid', 'paid' === $rows_b );
EEM_Division_Entries::sync_status_for_order( 'ORDER-A', 'paid' );
$rows_a = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE order_key = %s", 'ORDER-A' ) );
$check( 'paid event does NOT resurrect a refunded row', 'refunded' === $rows_a );

// --- listener wiring -------------------------------------------------------
$check( 'status-change listener registered', false !== has_action( 'eem_order_payment_status_changed', array( 'EEM_Division_Entries', 'on_order_payment_status_changed' ) ) );
EEM_Division_Entries::on_order_payment_status_changed( array( 'order_key' => 'ORDER-B', 'new_status' => 'cancelled' ) );
$check( 'listener cancels ORDER-B via payload', 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE order_key = %s", 'ORDER-B' ) ) );

// --- entrants roster -------------------------------------------------------
$entrants = EEM_Division_Entries::get_entrants( $did );
$check( 'get_entrants returns all rows for the division', 2 === count( $entrants ) );
$check( 'entrants carry customer_name + qty + status', isset( $entrants[0]['customer_name'], $entrants[0]['qty'], $entrants[0]['status'] ) );

// --- checkout spots-cap validation (shared customer + Create Order path) ----
$sc = new EEM_Shortcodes();
$ar = new ReflectionProperty( 'EEM_Shortcodes', 'active_reservation_id' );
$ar->setAccessible( true );
$ar->setValue( $sc, $rid );
$validate = new ReflectionMethod( 'EEM_Shortcodes', 'validate_submission' );
$validate->setAccessible( true );

// Reset to a known state: 1 held spot against 2 → 1 left. (Earlier rows are
// all refunded/cancelled by this point.) A qty-2 submission must be rejected.
EEM_Division_Entries::record_entry( $did, 'ORDER-HOLD', 'Hold, One', 'hold@example.com', 1, 'paid' );
$check( 'one held spot → 1 left before validation', 1 === EEM_Division_Entries::spots_left( $did, 2 ) );
$key   = 'entry_' . $did;
$sub_over = array( 'pre_entry_' . $key . '_qty' => 2, 'stall_qty' => 0, 'tack_stall_qty' => 0, 'rv_qty' => 0, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com', 'phone' => '+1 5551234567' );
$status = array(
	'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true,
	'stalls_sold_out' => false, 'rv_sold_out' => false,
	'stall_inventory_remaining' => null, 'rv_inventory_remaining' => null,
	'rv_lot_inventory' => array(),
);
$errs_over = $validate->invoke( $sc, $sub_over, $status, array() );
$has_full_error = false;
foreach ( (array) $errs_over as $e ) { if ( false !== stripos( (string) $e, '#5 Division' ) ) { $has_full_error = true; break; } }
$check( 'over-cap submission rejected with a spots error', $has_full_error );

$sub_ok = array( 'pre_entry_' . $key . '_qty' => 1, 'stall_qty' => 0, 'tack_stall_qty' => 0, 'rv_qty' => 0, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com', 'phone' => '+1 5551234567' );
$errs_ok = $validate->invoke( $sc, $sub_ok, $status, array() );
$within_ok = true;
foreach ( (array) $errs_ok as $e ) { if ( false !== stripos( (string) $e, '#5 Division' ) ) { $within_ok = false; break; } }
$check( 'within-cap submission (qty 1, 1 left) passes the spots check', $within_ok );

// --- ledger write fold (record_division_entries) ---------------------------
// Free the held spot so the write isn't blocked by anything, then record a
// fresh entry through the private fold used by insert_reservation_orders.
$record = new ReflectionMethod( 'EEM_Shortcodes', 'record_division_entries' );
$record->setAccessible( true );
$sub_fold = array( 'pre_entry_' . $key . '_qty' => 1, 'email' => 'fold@example.com' );
$record->invoke( $sc, $rid, array(), $sub_fold, 'ORDER-FOLD', 'Fold, Tester', 'fold@example.com', 'paid' );
$fold_row = $wpdb->get_row( $wpdb->prepare( "SELECT division_id, qty, status, customer_name FROM {$table} WHERE order_key = %s", 'ORDER-FOLD' ), ARRAY_A );
$check( 'fold wrote a ledger row for the division', is_array( $fold_row ) && (int) $fold_row['division_id'] === (int) $did );
$check( 'fold row carries qty + paid status + name', is_array( $fold_row ) && 1 === (int) $fold_row['qty'] && 'paid' === $fold_row['status'] && 'Fold, Tester' === $fold_row['customer_name'] );

// --- order_has_entries (Orders list "Entry" type badge) --------------------
$check( 'order_has_entries true for a seeded order_key', EEM_Division_Entries::order_has_entries( 'ORDER-HOLD' ) );
$check( 'order_has_entries false for an unknown order_key', ! EEM_Division_Entries::order_has_entries( 'NO-SUCH-ORDER' ) );
$check( 'order_has_entries false for empty key', ! EEM_Division_Entries::order_has_entries( '' ) );

// --- Slice 3: detail page + list column ------------------------------------
$check( 'detail_url carries the division_id param', false !== strpos( EEM_Entries::detail_url( $did ), 'division_id=' . $did ) );

ob_start(); EEM_Entries::render_detail( $did ); $detail_html = (string) ob_get_clean();
$check( 'detail renders the composed Event - Division title', false !== strpos( $detail_html, 'Divisions Ledger Event - #5 Division' ) );
$check( 'detail renders the Entered / Spots / Spots Left stat cards', 3 === substr_count( $detail_html, 'eem-dashboard-kpi-card eem-dashboard-kpi-card--' ) );
$check( 'detail renders the entrants table with the held customer', false !== strpos( $detail_html, 'eem-table' ) && false !== strpos( $detail_html, 'Hold, One' ) );
$check( 'detail renders an Edit Division action', false !== strpos( $detail_html, 'Edit Division' ) );
$check( 'detail renders a paid status badge', false !== strpos( $detail_html, 'eem-status-paid' ) );

// list dispatch routes division_id → detail
$_GET = array( 'page' => EEM_Entries::LIST_SLUG, 'division_id' => (string) $did );
ob_start(); EEM_Entries::render_list(); $dispatch_html = (string) ob_get_clean();
$_GET = array();
$check( 'render_list dispatches division_id to the detail view', false !== strpos( $dispatch_html, 'Divisions Ledger Event - #5 Division' ) && false !== strpos( $dispatch_html, 'eem-dashboard-kpi-card' ) );

// oversold: drop spots below entered → list shows the oversold note
update_post_meta( $did, EEM_Entries::META_SPOTS, 1 ); // entered is 1 (ORDER-HOLD) → not oversold; add another hold.
EEM_Division_Entries::record_entry( $did, 'ORDER-OVER', 'Over, Sold', 'over@example.com', 1, 'paid' );
ob_start(); EEM_Entries::render_detail( $did ); $over_html = (string) ob_get_clean();
$check( 'detail shows the oversold note when entered > spots', false !== stripos( $over_html, 'Oversold by' ) );

// --- cleanup ---------------------------------------------------------------
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE division_id = %d", $did ) );
wp_delete_post( (int) $did, true );
wp_delete_post( (int) $rid, true );
$check( 'cleaned up temp ledger rows + posts', null === get_post( $did ) && 0 === EEM_Division_Entries::entered_count( $did ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
