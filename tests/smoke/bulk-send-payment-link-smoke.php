<?php
/**
 * Bulk "Send Payment Link" smoke (v1 #7).
 *
 * Adds a bulk action on the Orders list that emails the hosted invoice payment
 * link to each selected UNPAID order (paid orders + orders without an email are
 * skipped, not failed). Mirrors the bulk-cancel queue pattern.
 *
 * Source-presence assertions for the UI/wiring + a behavioural check of the step
 * handler's gating via the filterable wp_die handler (the handler ends the request
 * with wp_send_json_*; we intercept it to read the response shape).
 *
 * Run: wp eval-file tests/smoke/bulk-send-payment-link-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence: UI + wiring ------------------------------------------
$list_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$loader    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$js        = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

$check( 'bulk action option "send_link" present', false !== strpos( $list_src, "value=\"send_link\"" ) && false !== strpos( $list_src, 'Send Payment Link' ) );
$check( 'bulk send-link modal rendered', false !== strpos( $list_src, 'render_bulk_send_link_modal' ) && false !== strpos( $list_src, 'eem-orders-bulk-send-link-modal' ) );
$check( 'eem_bulk_send_link nonce localized', false !== strpos( $list_src, "'eem_bulk_send_link'" ) );
$check( 'step handler defined', method_exists( 'EEM_Admin', 'handle_ajax_bulk_send_link_step' ) );
$check( 'step handler reuses send_invoice_email_for_order', false !== strpos( $admin_src, 'handle_ajax_bulk_send_link_step' ) && false !== strpos( $admin_src, '$this->send_invoice_email_for_order( $order )' ) );
$check( 'AJAX action registered', false !== strpos( $loader, "wp_ajax_eem_order_bulk_send_link_step" ) );
$check( 'JS: apply dispatches to the send-link modal', false !== strpos( $js, "sel.value === 'send_link'" ) && false !== strpos( $js, 'openOrdersBulkSendLinkModal' ) );
$check( 'JS: queue posts to the step endpoint', false !== strpos( $js, "'eem_order_bulk_send_link_step'" ) && false !== strpos( $js, 'startBulkSendLinkQueue' ) );

// --- behavioural: the step-handler gate (testable, no wp_die) ---------------
$admin = new EEM_Admin();

$gate_unpaid = $admin->classify_bulk_send_link_target( array( 'status_slug' => 'unpaid', 'email' => 'a@b.test' ) );
$check( 'unpaid order with email → send', 'send' === $gate_unpaid['result'] );

$gate_invsent = $admin->classify_bulk_send_link_target( array( 'status_slug' => 'invoice-sent', 'email' => 'a@b.test' ) );
$check( 'invoice-sent order with email → send', 'send' === $gate_invsent['result'] );

$gate_paid = $admin->classify_bulk_send_link_target( array( 'status_slug' => 'paid', 'email' => 'a@b.test' ) );
$check( 'paid order → skip (already paid)', 'skip' === $gate_paid['result'] && false !== strpos( (string) $gate_paid['reason'], 'paid' ) );

$gate_noemail = $admin->classify_bulk_send_link_target( array( 'status_slug' => 'unpaid', 'email' => '' ) );
$check( 'unpaid order without email → skip (no email)', 'skip' === $gate_noemail['result'] && false !== strpos( (string) $gate_noemail['reason'], 'email' ) );

$gate_missing = $admin->classify_bulk_send_link_target( null );
$check( 'missing order → not_found', 'not_found' === $gate_missing['result'] );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
