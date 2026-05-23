<?php
/** C6.E.1 smoke — Activity log render: get_for_order_key + real render +
 * collapsible. Includes a write→read-back roundtrip per the new CLAUDE.md
 * discipline (covers the C6.D gap where written entries weren't readable
 * by the canonical consumer query). */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C6.E.1 SMOKE ===\n";

wp_set_current_user( 1 );

// ── [1] get_for_order_key exists + signature ───────────────────────
echo "\n[1] EEM_Activity_Log::get_for_order_key API\n";
ok( 'get_for_order_key method exists',                   method_exists( 'EEM_Activity_Log', 'get_for_order_key' ),                       $pass, $fail, $log );
ok( 'get_for_order_key is public static',                method_exists( 'EEM_Activity_Log', 'get_for_order_key' ) && ( new ReflectionMethod( 'EEM_Activity_Log', 'get_for_order_key' ) )->isStatic(), $pass, $fail, $log );
ok( 'get_for_order_key empty order_key → empty array',   array() === EEM_Activity_Log::get_for_order_key( '', 100 ),                      $pass, $fail, $log );
ok( 'get_for_order_key unknown key → empty array',       array() === EEM_Activity_Log::get_for_order_key( 'definitely-not-a-key-c6e1', 100 ), $pass, $fail, $log );

// ── [2] WRITE → READ-BACK ROUNDTRIP via the canonical consumer API ─
// (Covers the C6.D gap where written telemetry entries were unreadable
// via the canonical get_for_order() consumer. New discipline per
// CLAUDE.md: smoke must verify the round-trip end-to-end.)
echo "\n[2] Write → read-back roundtrip via canonical consumer API\n";

$probe_key = 'c6e1-probe-' . wp_generate_password( 12, false );

// Fire each of the 3 C6.D telemetry hooks against the probe key.
do_action( 'eem_order_created', array(
	'order_key'      => $probe_key,
	'order_number'   => 'C6E1PROBE',
	'customer_email' => 'c6e1@probe.test',
	'customer_name'  => 'C6E1 Probe',
	'payment_status' => 'paid',
	'total'          => 250.00,
	'source'         => 'smoke_probe',
	'created_at'     => current_time( 'mysql' ),
) );
do_action( 'eem_order_payment_status_changed', array(
	'order_key'  => $probe_key,
	'old_status' => 'invoice-sent',
	'new_status' => 'paid',
	'source'     => 'smoke_probe_funnel',
) );
do_action( 'eem_email_sent', array(
	'to'      => 'c6e1@probe.test',
	'subject' => 'Probe email',
	'context' => array( 'type' => 'invoice', 'order_key' => $probe_key ),
) );

// READ-BACK via the canonical consumer method that Order Detail uses.
$readback = EEM_Activity_Log::get_for_order_key( $probe_key, 100 );

ok( 'read-back returns 3 entries (all writes round-tripped)',          3 === count( $readback ),                                                       $pass, $fail, $log, 'got ' . count( $readback ) . ' entries' );

// Verify event_type taxonomy round-trips (sanitize_key form per CLEANUP #31).
$event_types = array_map( function ( $r ) { return $r['event_type']; }, $readback );
sort( $event_types );
ok( 'read-back includes ordercreate event',                            in_array( 'ordercreate', $event_types, true ),                                  $pass, $fail, $log );
ok( 'read-back includes orderpayment_received event',                  in_array( 'orderpayment_received', $event_types, true ),                        $pass, $fail, $log );
ok( 'read-back includes orderemail_sent event',                        in_array( 'orderemail_sent', $event_types, true ),                              $pass, $fail, $log );

// Verify payload JSON decoded into array (caller-convenience contract).
ok( 'read-back entries have decoded payload arrays',                   is_array( $readback[0]['payload'] ?? null ),                                    $pass, $fail, $log );
ok( 'read-back payload preserves order_key',                           $readback[0]['payload']['order_key'] === $probe_key,                            $pass, $fail, $log );

// Order: newest first (DESC by created_at, id).
ok( 'read-back ordered DESC by created_at',                            count( $readback ) >= 2 && strtotime( $readback[0]['created_at'] ) >= strtotime( $readback[1]['created_at'] ), $pass, $fail, $log );

// Limit boundary — passing 1 returns at most 1.
$capped = EEM_Activity_Log::get_for_order_key( $probe_key, 1 );
ok( 'read-back honors $limit (limit=1 returns 1 row)',                 1 === count( $capped ),                                                         $pass, $fail, $log );

// Self-clean probe rows.
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}en_activity_log WHERE payload LIKE %s", '%' . $wpdb->esc_like( $probe_key ) . '%' ) );

// ── [3] EEM_Order_Telemetry render-side filter + enricher ──────────
echo "\n[3] Render-side filter + enricher\n";
ok( 'EEM_Order_Telemetry::filter_render_variant exists',               method_exists( 'EEM_Order_Telemetry', 'filter_render_variant' ),                $pass, $fail, $log );
ok( 'EEM_Order_Telemetry::enrich_entry_for_render exists',             method_exists( 'EEM_Order_Telemetry', 'enrich_entry_for_render' ),              $pass, $fail, $log );
ok( 'eem_activity_log_variant filter is registered',                   false !== has_filter( 'eem_activity_log_variant' ),                             $pass, $fail, $log );

// Variant mapping coverage for each C6.D/E event type.
ok( 'ordercreate → create variant',                                    'create'       === EEM_Order_Telemetry::filter_render_variant( 'info', 'ordercreate' ),       $pass, $fail, $log );
ok( 'orderrefund → refund variant',                                    'refund'       === EEM_Order_Telemetry::filter_render_variant( 'info', 'orderrefund' ),       $pass, $fail, $log );
ok( 'orderpayment_received → notification variant',                    'notification' === EEM_Order_Telemetry::filter_render_variant( 'info', 'orderpayment_received' ), $pass, $fail, $log );
ok( 'orderstatus_change → info variant',                               'info'         === EEM_Order_Telemetry::filter_render_variant( 'info', 'orderstatus_change' ), $pass, $fail, $log );
ok( 'orderemail_sent → notification variant',                          'notification' === EEM_Order_Telemetry::filter_render_variant( 'info', 'orderemail_sent' ),   $pass, $fail, $log );
ok( 'ordernote → edit variant (for C6.E.2)',                          'edit'         === EEM_Order_Telemetry::filter_render_variant( 'info', 'ordernote' ),         $pass, $fail, $log );
ok( 'unknown event_type → default passthrough',                       'something_else' === EEM_Order_Telemetry::filter_render_variant( 'something_else', 'unknown_event' ), $pass, $fail, $log );

// Enricher injects render-ready title.
$enriched = EEM_Order_Telemetry::enrich_entry_for_render( array(
	'event_type' => 'ordercreate',
	'payload'    => array( 'order_key' => 'x' ),
	'actor_label'=> 'Admin',
) );
ok( 'enrich injects title for ordercreate',                            ! empty( $enriched['payload']['title'] ) && str_contains( (string) $enriched['payload']['title'], 'created' ), $pass, $fail, $log );

$enriched_refund = EEM_Order_Telemetry::enrich_entry_for_render( array(
	'event_type' => 'orderrefund',
	'payload'    => array( 'order_key' => 'x', 'amount' => 42.50 ),
) );
ok( 'enrich injects refund title with amount',                         ! empty( $enriched_refund['payload']['title'] ) && str_contains( (string) $enriched_refund['payload']['title'], '42.50' ), $pass, $fail, $log );

$enriched_status = EEM_Order_Telemetry::enrich_entry_for_render( array(
	'event_type' => 'orderstatus_change',
	'payload'    => array( 'order_key' => 'x', 'old_status' => 'unpaid', 'new_status' => 'refunded' ),
) );
ok( 'enrich injects status_change title with old→new',                 ! empty( $enriched_status['payload']['title'] ) && str_contains( (string) $enriched_status['payload']['title'], 'unpaid' ) && str_contains( (string) $enriched_status['payload']['title'], 'refunded' ), $pass, $fail, $log );

// Caller-supplied title wins.
$enriched_explicit = EEM_Order_Telemetry::enrich_entry_for_render( array(
	'event_type' => 'ordercreate',
	'payload'    => array( 'title' => 'Custom title', 'order_key' => 'x' ),
) );
ok( 'enrich respects caller-supplied title (does not overwrite)',      'Custom title' === $enriched_explicit['payload']['title'],                       $pass, $fail, $log );

// ── [4] Order Detail render emits real activity-log markup ─────────
echo "\n[4] Order Detail page renders real activity log\n";

// Seed at least 1 entry on an existing order so the render path has data.
$repo  = new EEM_Orders_Repository();
$ref   = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$ref->setAccessible( true );
$rows  = $ref->invoke( $repo );
$test_order = null;
foreach ( $rows as $o ) { if ( ! empty( $o['order_key'] ) ) { $test_order = $o; break; } }

if ( $test_order ) {
	// Ensure ≥1 entry exists for this order so the empty-state path isn't tested here.
	$render_probe_marker = 'c6e1-render-probe-' . wp_generate_password( 6, false );
	do_action( 'eem_order_created', array(
		'order_key'      => $test_order['order_key'],
		'order_number'   => 'RENDER',
		'customer_email' => 'render@probe',
		'customer_name'  => 'Render Probe',
		'payment_status' => 'paid',
		'total'          => 10.00,
		'source'         => $render_probe_marker,
		'created_at'     => current_time( 'mysql' ),
	) );

	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $test_order['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$html = ob_get_clean();

	// Section + toggle structure.
	ok( 'render emits .eem-order-activity section',                    str_contains( $html, 'class="eem-order-activity"' ),                            $pass, $fail, $log );
	ok( 'render emits data-eem-activity-section attr',                 str_contains( $html, 'data-eem-activity-section' ),                             $pass, $fail, $log );
	ok( 'render emits activity-toggle dispatch action',                str_contains( $html, 'data-eem-action="activity-toggle"' ),                     $pass, $fail, $log );
	ok( 'render emits chevron glyph',                                  str_contains( $html, 'eem-order-activity__chevron' ),                           $pass, $fail, $log );
	ok( 'render emits aria-expanded=true (default-expanded)',          str_contains( $html, 'aria-expanded="true"' ),                                  $pass, $fail, $log );
	ok( 'render emits entry-count badge',                              str_contains( $html, 'data-eem-activity-count' ),                               $pass, $fail, $log );

	// Entry list rendered via C2 partial.
	ok( 'render emits .eem-order-activity__list wrapper',              str_contains( $html, 'class="eem-order-activity__list"' ),                      $pass, $fail, $log );
	ok( 'render emits C2 partial markup (eem-activity-log <ul>)',      preg_match( '/<ul class="eem-activity-log"/', $html ) === 1,                    $pass, $fail, $log );

	// Self-clean the probe entry.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}en_activity_log WHERE payload LIKE %s", '%' . $wpdb->esc_like( $render_probe_marker ) . '%' ) );
}

// ── [5] Empty-state copy ───────────────────────────────────────────
echo "\n[5] Empty-state copy for orders with no entries\n";

// Use a synthetic order_key that won't match any entries.
$empty_key = 'c6e1-empty-' . wp_generate_password( 12, false );
$empty_entries = EEM_Activity_Log::get_for_order_key( $empty_key, 100 );
ok( 'unknown order_key → empty entries array',                          array() === $empty_entries,                                                     $pass, $fail, $log );

// The render path's empty message — verify the partial honors the custom
// empty_message arg we pass.
ob_start();
eem_render_activity_log( array(), array(
	'empty_message' => 'C6E1 EMPTY STATE PROBE',
) );
$empty_html = ob_get_clean();
ok( 'eem_render_activity_log custom empty_message renders',             str_contains( $empty_html, 'C6E1 EMPTY STATE PROBE' ),                          $pass, $fail, $log );

// ── [6] Collapsible JS dispatch arm wired ──────────────────────────
echo "\n[6] Collapsible JS dispatch arm\n";
$js_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
ok( 'activity-toggle dispatch arm exists in admin.js',                  str_contains( $js_src, "'activity-toggle'" ),                                   $pass, $fail, $log );
ok( 'activity-toggle toggles .collapsed class on section',              str_contains( $js_src, 'classList.toggle' ) && str_contains( $js_src, 'collapsed' ), $pass, $fail, $log );
ok( 'activity-toggle updates aria-expanded',                            str_contains( $js_src, 'aria-expanded' ),                                       $pass, $fail, $log );

// ── [7] CSS state-gating for .collapsed ────────────────────────────
echo "\n[7] CSS gates .eem-order-activity__list on .collapsed parent\n";
$css_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
ok( 'CSS rule: .eem-order-activity.collapsed .eem-order-activity__list { display: none }',
	false !== strpos( $css_src, '.eem-order-activity.collapsed .eem-order-activity__list' ),
	$pass, $fail, $log );
ok( 'CSS rule: chevron rotates when collapsed',                        false !== strpos( $css_src, '.eem-order-activity.collapsed .eem-order-activity__chevron' ), $pass, $fail, $log );

// ── [8] CLEANUP #32 entry exists ───────────────────────────────────
echo "\n[8] CLEANUP doc updates\n";
$cleanup = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'CLEANUP.md' );
ok( 'CLEANUP #32 entry exists',                                         str_contains( $cleanup, '### 32.' ),                                            $pass, $fail, $log );
ok( 'CLEANUP #32 mentions indexed order_key column',                   str_contains( $cleanup, 'order_key VARCHAR' ) || str_contains( $cleanup, 'indexed' ), $pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
