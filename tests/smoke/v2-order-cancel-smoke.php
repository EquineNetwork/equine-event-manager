<?php
/**
 * v2 — Order cancellation: repo behavior, inventory release, status mapping,
 * AJAX/UI/JS wiring, and the cancellation email (policy-bearing).
 *
 * Decisions locked: cancel marks the order cancelled + frees inventory + emails
 * the customer; it does NOT refund (payment record preserved for a separate
 * refund). Trigger: Order Detail "More" menu + Orders-list bulk. Email: branded
 * notice carrying the reservation's cancellation policy.
 */

$pass = 0; $fail = 0; $log = array();
function oc_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

global $wpdb;
$st = $wpdb->prefix . 'eem_stall_reservations';

$repo = new EEM_Orders_Repository();
$sc   = new EEM_Shortcodes();
$inv  = new ReflectionMethod( 'EEM_Shortcodes', 'get_reservation_inventory_usage' );
$inv->setAccessible( true );

// Baseline inventory BEFORE inserting the synthetic order — reservation 5990 may
// already carry other seeded paid stall orders, so assert the DELTA (+4 then back
// to baseline), not absolute 4 → 0.
$inv_base = (int) $inv->invoke( $sc, 5990 )['stall_sold'];

// ── Behavioral: insert a synthetic PAID order tied to demo reservation 5990
//    (which carries a cancellation-policy snapshot), cancel it, assert effects.
$wpdb->insert( $st, array(
	'event_source' => 'tec', 'event_id' => 9,
	'customer_name' => 'Cancel Smoke', 'email' => 'cancel-smoke@example.com', 'phone' => '555-0100',
	'stall_qty' => 4, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'unit_price' => '25.00', 'subtotal' => '100.00', 'total' => '100.00',
	'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'order_number' => '99777',
	'transaction_id' => 'ch_smoke_cancel',
	'notes' => "Reservation setup ID: 5990\nSubmission token: tok_cancel_smoke_99777",
	'created_at' => current_time( 'mysql' ),
) );
$row_id = (int) $wpdb->insert_id;

$order = $repo->get_order_by_submission_token( 'tok_cancel_smoke_99777' );
$key   = $order ? $order['order_key'] : '';
oc_ok( 'synthetic order resolves', '' !== $key, $pass, $fail, $log );

$inv_before = (int) $inv->invoke( $sc, 5990 )['stall_sold'];
oc_ok( 'inventory counts the paid order before cancel (+4)', $inv_base + 4 === $inv_before, $pass, $fail, $log );

$cancelled = $repo->cancel_order( $key, 'Smoke reason.' );
oc_ok( 'cancel_order returns true', true === $cancelled, $pass, $fail, $log );

$order2 = $repo->get_order_by_submission_token( 'tok_cancel_smoke_99777' );
oc_ok( 'order status becomes cancelled', isset( $order2['status_slug'] ) && 'cancelled' === $order2['status_slug'], $pass, $fail, $log );

$inv_after = (int) $inv->invoke( $sc, 5990 )['stall_sold'];
oc_ok( 'inventory released after cancel (back to baseline)', $inv_base === $inv_after, $pass, $fail, $log );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT payment_status, transaction_id, notes FROM $st WHERE id=%d", $row_id ), ARRAY_A );
oc_ok( 'line item payment_status = cancelled', 'cancelled' === $row['payment_status'], $pass, $fail, $log );
oc_ok( 'original transaction_id preserved (refund-separately)', 'ch_smoke_cancel' === $row['transaction_id'], $pass, $fail, $log );
oc_ok( 'cancel reason recorded in notes', false !== strpos( $row['notes'], 'Cancellation Reason' ), $pass, $fail, $log );

$again = $repo->cancel_order( $key, '' );
oc_ok( 'second cancel is an idempotent no-op (false)', false === $again, $pass, $fail, $log );

// ── Status mapping.
$disp = new ReflectionMethod( 'EEM_Orders_Repository', 'get_order_status_display' );
$disp->setAccessible( true );
$d = $disp->invoke( $repo, 'cancelled', '' );
oc_ok( 'status display: cancelled -> Cancelled/cancelled', 'cancelled' === $d['slug'], $pass, $fail, $log );

$comb = new ReflectionMethod( 'EEM_Orders_Repository', 'get_combined_payment_status' );
$comb->setAccessible( true );
oc_ok( 'combiner: cancelled wins over paid', 'cancelled' === $comb->invoke( $repo, 'paid', 'cancelled' ), $pass, $fail, $log );

// Cleanup synthetic row.
$wpdb->delete( $st, array( 'id' => $row_id ) );

// ── Wiring (source-presence).
$main = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
oc_ok( 'single-cancel AJAX action registered', false !== strpos( $main, "wp_ajax_eem_order_cancel_single" ), $pass, $fail, $log );
oc_ok( 'bulk-cancel-step AJAX action registered', false !== strpos( $main, "wp_ajax_eem_order_bulk_cancel_step" ), $pass, $fail, $log );

$admin = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
oc_ok( 'handle_ajax_cancel_single exists', false !== strpos( $admin, 'function handle_ajax_cancel_single' ), $pass, $fail, $log );
oc_ok( 'handle_ajax_bulk_cancel_step exists', false !== strpos( $admin, 'function handle_ajax_bulk_cancel_step' ), $pass, $fail, $log );
oc_ok( 'build_cancellation_email_html exists', false !== strpos( $admin, 'function build_cancellation_email_html' ), $pass, $fail, $log );
oc_ok( 'cancellation email surfaces the reservation policy', false !== strpos( $admin, 'eem_resolve_cancellation_policy' ) && false !== strpos( $admin, 'Reservation Cancelled' ), $pass, $fail, $log );

$detail = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
oc_ok( 'Order Detail More menu has Cancel Order', false !== strpos( $detail, 'order-cancel-single' ), $pass, $fail, $log );
oc_ok( 'Order Detail renders the cancel modal', false !== strpos( $detail, 'eem-order-cancel-modal' ) && false !== strpos( $detail, 'render_cancel_modal' ), $pass, $fail, $log );

$list = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
oc_ok( 'Orders list bulk dropdown has Cancel Selected', false !== strpos( $list, '"cancel"' ) && false !== strpos( $list, 'Cancel Selected' ), $pass, $fail, $log );
oc_ok( 'Orders list renders the bulk cancel modal', false !== strpos( $list, 'render_bulk_cancel_modal' ), $pass, $fail, $log );

$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
oc_ok( 'JS wires order-cancel-single-confirm', false !== strpos( $js, "'order-cancel-single-confirm'" ) && false !== strpos( $js, 'submitOrderCancelForm' ), $pass, $fail, $log );
oc_ok( 'JS bulk-apply branches to cancel', false !== strpos( $js, "sel.value === 'cancel'" ) && false !== strpos( $js, 'startBulkCancelQueue' ), $pass, $fail, $log );

echo "\n=== v2 order-cancel smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
