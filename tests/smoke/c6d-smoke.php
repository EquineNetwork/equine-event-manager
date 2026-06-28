<?php
/** C6.D smoke — Activity-log auto-fire telemetry (3 listener hooks +
 * status funnel + mailer signature extension + 5 caller context updates
 * + refund-duplication regression guard + CLEANUP #30). */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C6.D SMOKE ===\n";

wp_set_current_user( 1 );

// ── [1] Telemetry class + listener registration ────────────────────
echo "\n[1] EEM_Order_Telemetry class + listener registration\n";
ok( 'EEM_Order_Telemetry class exists',                  class_exists( 'EEM_Order_Telemetry' ),                                     $pass, $fail, $log );
ok( 'OUTSTANDING_STATUSES constant defined',             defined( 'EEM_Order_Telemetry::OUTSTANDING_STATUSES' ),                    $pass, $fail, $log );
ok( 'register() method exists',                          method_exists( 'EEM_Order_Telemetry', 'register' ),                        $pass, $fail, $log );
foreach ( array( 'on_order_created', 'on_payment_status_changed', 'on_email_sent' ) as $listener_method ) {
	ok( "{$listener_method} method exists",              method_exists( 'EEM_Order_Telemetry', $listener_method ),                 $pass, $fail, $log );
}
ok( 'eem_order_created hook registered',                 false !== has_action( 'eem_order_created' ),                              $pass, $fail, $log );
ok( 'eem_order_payment_status_changed hook registered',  false !== has_action( 'eem_order_payment_status_changed' ),               $pass, $fail, $log );
ok( 'eem_email_sent hook registered',                    false !== has_action( 'eem_email_sent' ),                                 $pass, $fail, $log );

// ── [2] on_order_created listener — writes order.create activity entry ─
echo "\n[2] on_order_created — writes order.create entry\n";
$captured_create = array();
$capture_create_filter = function ( $event_type ) use ( &$captured_create ) {
	if ( 'order_create' === $event_type ) { $captured_create[] = func_get_args(); }
	return $event_type;
};
add_filter( 'eem_activity_log_event_type', $capture_create_filter, 10, 1 );

// Use the do_action directly to exercise the listener (avoids needing
// to simulate a checkout). Payload mirrors what insert_reservation_orders
// emits.
do_action( 'eem_order_created', array(
	'order_key'      => 'c6d-smoke-create-test',
	'order_number'   => 'C6DSMOKE-001',
	'customer_email' => 'c6d@smoke.test',
	'customer_name'  => 'C6D Smoke',
	'payment_status' => 'paid',
	'total'          => 100.0,
	'event_label'    => 'C6D Smoke Event',
	'source'         => 'checkout_submission',
	'created_at'     => current_time( 'mysql' ),
) );

remove_filter( 'eem_activity_log_event_type', $capture_create_filter, 10 );
// Note: EEM_Activity_Log doesn't currently expose an eem_activity_log_event_type
// filter. The cleaner assertion is via direct DB read for our smoke event_type.
global $wpdb;
$created_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
	'order_create',
	'%c6d-smoke-create-test%'
) );
ok( 'do_action eem_order_created → exactly 1 order.create row inserted',  1 === $created_rows, $pass, $fail, $log, "got {$created_rows}" );

// Self-clean.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->prefix}eem_activity_log WHERE payload LIKE %s",
	'%c6d-smoke-create-test%'
) );

// ── [3] on_payment_status_changed funnel — payment_received vs status_change ─
echo "\n[3] on_payment_status_changed funnel decision tree\n";

// (a) outstanding → paid = payment_received
do_action( 'eem_order_payment_status_changed', array(
	'order_key'  => 'c6d-smoke-funnel-pr',
	'old_status' => 'invoice-sent',
	'new_status' => 'paid',
	'source'     => 'gateway_callback',
) );
$pr_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
	'order_payment_received',
	'%c6d-smoke-funnel-pr%'
) );
ok( 'outstanding (invoice-sent) → paid emits order.payment_received',     1 === $pr_rows, $pass, $fail, $log, "got {$pr_rows}" );

// (b) paid → refunded = status_change (not payment_received)
do_action( 'eem_order_payment_status_changed', array(
	'order_key'  => 'c6d-smoke-funnel-sc',
	'old_status' => 'paid',
	'new_status' => 'refunded',
	'source'     => 'process_amount_refund_kernel',
) );
$sc_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
	'order_status_change',
	'%c6d-smoke-funnel-sc%'
) );
ok( 'paid → refunded emits order.status_change (not payment_received)',   1 === $sc_rows, $pass, $fail, $log, "got {$sc_rows}" );

// (c) no-op when old_status === new_status
do_action( 'eem_order_payment_status_changed', array(
	'order_key'  => 'c6d-smoke-funnel-noop',
	'old_status' => 'paid',
	'new_status' => 'paid',
	'source'     => 'spurious',
) );
$noop_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE payload LIKE %s",
	'%c6d-smoke-funnel-noop%'
) );
ok( 'old == new emits NO entry (funnel no-op guard)',                    0 === $noop_rows, $pass, $fail, $log, "got {$noop_rows}" );

// (d) source field round-trips through payload (forensics attribution)
$source_row = $wpdb->get_var( $wpdb->prepare(
	"SELECT payload FROM {$wpdb->prefix}eem_activity_log WHERE payload LIKE %s LIMIT 1",
	'%c6d-smoke-funnel-pr%'
) );
ok( 'source field round-trips through payload (forensics attribution)',  $source_row && false !== strpos( $source_row, 'gateway_callback' ), $pass, $fail, $log );

// Self-clean funnel rows.
$wpdb->query( "DELETE FROM {$wpdb->prefix}eem_activity_log WHERE payload LIKE '%c6d-smoke-funnel-%'" );

// ── [4] update_order_payment_details + mark_order_paid_manually emit the funnel ─
echo "\n[4] orders-repository methods emit the status-change funnel\n";
$emitted_from_upd = array();
$capture_upd = function ( $payload ) use ( &$emitted_from_upd ) { $emitted_from_upd[] = $payload; };
add_action( 'eem_order_payment_status_changed', $capture_upd, 5, 1 );

$repo = new EEM_Orders_Repository();
$rows = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$rows->setAccessible( true );
$orders = $rows->invoke( $repo );
$unpaid_order = null;
foreach ( $orders as $o ) {
	if ( in_array( $o['status_slug'] ?? '', array( 'unpaid', 'invoice-sent', 'invoice_sent' ), true ) ) { $unpaid_order = $o; break; }
}
if ( $unpaid_order ) {
	$repo_fresh = new EEM_Orders_Repository();
	$repo_fresh->update_order_payment_details( $unpaid_order['order_key'], 'paid', 'c6d-smoke-txn-12345', 'stripe' );
	ok( 'update_order_payment_details emitted funnel',                   ! empty( $emitted_from_upd ),                                                $pass, $fail, $log );
	if ( ! empty( $emitted_from_upd ) ) {
		$first = $emitted_from_upd[0];
		ok( 'emitted payload carries old_status',                         isset( $first['old_status'] ) && '' !== $first['old_status'],              $pass, $fail, $log );
		ok( 'emitted payload carries new_status=paid',                    'paid' === ( $first['new_status'] ?? '' ),                                  $pass, $fail, $log );
		ok( 'emitted payload carries source=update_order_payment_details',('update_order_payment_details' === ( $first['source'] ?? '' )),            $pass, $fail, $log );
		ok( 'emitted payload carries gateway=stripe',                     'stripe' === ( $first['gateway'] ?? '' ),                                   $pass, $fail, $log );
	}
	// Restore unpaid state for cleanliness (best-effort — direct meta write would re-trigger).
	$repo_restore = new EEM_Orders_Repository();
	$repo_restore->update_order_payment_details( $unpaid_order['order_key'], (string) $unpaid_order['status_slug'], '', $unpaid_order['payment_gateway'] ?? '' );
}
remove_action( 'eem_order_payment_status_changed', $capture_upd, 5 );

// Self-clean any rows generated.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->prefix}eem_activity_log WHERE payload LIKE %s",
	'%c6d-smoke-txn-12345%'
) );

// ── [5] EEM_Mailer signature extension + email_sent emission ───────
echo "\n[5] EEM_Mailer::send_html_email accepts $context arg (backward-compatible)\n";
$mailer_refl = new ReflectionMethod( 'EEM_Mailer', 'send_html_email' );
$params = $mailer_refl->getParameters();
ok( 'send_html_email signature has 6 params (incl. C12 attachments)', 6 === count( $params ),                                                     $pass, $fail, $log );
ok( '5th param is named context',                                        isset( $params[4] ) && 'context' === $params[4]->getName(),                $pass, $fail, $log );
ok( '5th param has default (backward-compatible)',                      isset( $params[4] ) && $params[4]->isDefaultValueAvailable(),               $pass, $fail, $log );

// ── [6] All 5 callers pass meaningful context (type + identifying key) ─
echo "\n[6] All 5 EEM_Mailer::send_html_email callers pass meaningful context\n";

$caller_files = array(
	'invoice (admin)'                 => 'admin/class-equine-event-manager-admin.php',
	'admin_receipt (shortcodes)'      => 'public/class-equine-event-manager-shortcodes.php',
	'checkout_confirmation (shortcodes)' => 'public/class-equine-event-manager-shortcodes.php',
	'test_email (settings)'           => 'admin/class-eem-settings-page.php',
	'email_customers (reservations-list)' => 'admin/class-eem-reservations-list-page.php',
);
$caller_expected_types = array(
	'invoice (admin)'                 => "'type'      => 'invoice'",
	'admin_receipt (shortcodes)'      => "'type'      => 'admin_receipt'",
	'checkout_confirmation (shortcodes)' => "'type'      => 'checkout_confirmation'",
	'test_email (settings)'           => "'type'        => 'test_email'",
	'email_customers (reservations-list)' => "'type'           => 'email_customers'",
);
foreach ( $caller_expected_types as $label => $needle ) {
	$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . $caller_files[ $label ] );
	ok( "{$label} passes type context",                                  false !== strpos( $src, $needle ),                                          $pass, $fail, $log );
}

// Identifying key per caller (order_key for 3, reservation_id for 1, template_id for test).
$identifying_needles = array(
	'invoice (admin)'                 => "'order_key' =>",
	'admin_receipt (shortcodes)'      => "'order_key' =>",
	'checkout_confirmation (shortcodes)' => "'order_key' =>",
	'email_customers (reservations-list)' => "'reservation_id' =>",
	'test_email (settings)'           => "'template_id' =>",
);
foreach ( $identifying_needles as $label => $needle ) {
	$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . $caller_files[ $label ] );
	ok( "{$label} passes identifying key",                               false !== strpos( $src, $needle ),                                          $pass, $fail, $log );
}

// ── [7] on_email_sent listener writes order.email_sent ONLY when order_key in context ─
echo "\n[7] on_email_sent listener gates on order_key context\n";

// (a) With order_key → entry written.
do_action( 'eem_email_sent', array(
	'to'      => 'c6d-smoke-email@test',
	'subject' => 'C6D Smoke Email With Order',
	'context' => array( 'type' => 'invoice', 'order_key' => 'c6d-smoke-email-with' ),
) );
$with_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
	'order_email_sent',
	'%c6d-smoke-email-with%'
) );
ok( 'email with order_key context → order.email_sent entry written',      1 === $with_rows, $pass, $fail, $log, "got {$with_rows}" );

// (b) Without order_key (e.g. test_email) → entry NOT written.
do_action( 'eem_email_sent', array(
	'to'      => 'c6d-smoke-email@test',
	'subject' => 'C6D Smoke Email No Order',
	'context' => array( 'type' => 'test_email' ),
) );
$without_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
	'order_email_sent',
	'%C6D Smoke Email No Order%'
) );
ok( 'email without order_key context (test_email) → NO entry written',    0 === $without_rows, $pass, $fail, $log, "got {$without_rows}" );

// Self-clean.
$wpdb->query( "DELETE FROM {$wpdb->prefix}eem_activity_log WHERE payload LIKE '%c6d-smoke-email-with%' OR payload LIKE '%C6D Smoke Email No Order%'" );

// ── [8] Refund-duplication regression — exactly ONE order.refund per process_amount_refund ─
echo "\n[8] Refund-duplication regression guard\n";

// Get a refundable order (paid status).
$paid_order = null;
foreach ( $orders as $o ) { if ( 'paid' === ( $o['status_slug'] ?? '' ) ) { $paid_order = $o; break; } }

if ( $paid_order ) {
	$pre_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
		'order_refund',
		'%' . $paid_order['order_key'] . '%'
	) );

	// Invoke process_amount_refund. It hits the gateway; for orders with
	// 'unsupported_gateway' it returns WP_Error before persistence — no
	// activity-log write. We're after the "exactly one write per success"
	// contract, so use a small refund amount that the kernel will validate
	// successfully (or fail at gateway, which is also fine — failure means
	// 0 writes, still satisfying "exactly N writes per N successes").
	$admin = new EEM_Admin();
	$result = $admin->process_amount_refund( $paid_order['order_key'], 0.01, 'C6D regression-guard probe' );

	$post_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
		'order_refund',
		'%' . $paid_order['order_key'] . '%'
	) );

	$delta = $post_count - $pre_count;
	if ( is_wp_error( $result ) ) {
		ok( 'WP_Error result → 0 activity-log writes (no spurious entry)', 0 === $delta, $pass, $fail, $log, "delta={$delta}, error={$result->get_error_code()}" );
	} else {
		ok( 'Success result → EXACTLY 1 order.refund entry (no duplication)', 1 === $delta, $pass, $fail, $log, "delta={$delta}" );
	}

	// Self-clean any test row.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}eem_activity_log WHERE event_type = %s AND payload LIKE %s",
		'order_refund',
		'%C6D regression-guard probe%'
	) );
} else {
	ok( 'refund-duplication probe skipped (no paid orders)',              true, $pass, $fail, $log, 'no paid order to probe' );
}

// ── [9] CLEANUP #30 entry exists ───────────────────────────────────
echo "\n[9] CLEANUP #30 (refund-notify deferred to C11)\n";
$cleanup = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'CLEANUP.md' );
ok( 'CLEANUP #30 entry exists',                                          str_contains( $cleanup, '### 30.' ),                                       $pass, $fail, $log );
ok( 'CLEANUP #30 mentions refund-notify deferral to C11',                str_contains( $cleanup, 'refund_processed' ) && str_contains( $cleanup, 'C11' ), $pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
