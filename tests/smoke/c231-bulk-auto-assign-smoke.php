<?php
/** 2.3.51 smoke — Bulk-mode admin auto-assignment UI + AJAX wiring.
 *
 * Covers the new auto-assign feature on the Stall & RV Charts detail page:
 *   - EEM_Orders_Repository::auto_assign_units_for_reservation (full RV pool,
 *     two-pass conflict-safe fill) + private fill_remaining_chart_units helper.
 *   - EEM_Admin::ajax_auto_assign handler + wp_ajax_eem_auto_assign hook.
 *   - render_stall_chart_dynamic_region / render_stall_chart_overlays split
 *     (so the AJAX response can swap #eem-stall-chart-dynamic without reload).
 *   - Admin markup: Generate Assignments is a button (not an admin-post link),
 *     issues card carries Auto-Assign All + per-row Auto-Assign[data-order-key].
 *   - JS: delegated handlers + eemRunAutoAssign region-swap + autoAssignNonce.
 *
 * NOTE: source-presence + return-shape assertions only for the render/JS
 * surface — the functional end-to-end (click → assign → chart refresh) is
 * verified in the browser per the project's runtime-claim discipline.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function ok( $l, $c, &$p, &$f, &$lg, $d = '' ) { if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; } else { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); } }

$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

/* ── Repo: reservation-wide auto-assign ── */
ok( 'EEM_Orders_Repository exists', class_exists( 'EEM_Orders_Repository' ), $pass, $fail, $log );
ok( 'auto_assign_units_for_reservation is public', method_exists( 'EEM_Orders_Repository', 'auto_assign_units_for_reservation' ) && ( new ReflectionMethod( 'EEM_Orders_Repository', 'auto_assign_units_for_reservation' ) )->isPublic(), $pass, $fail, $log );
ok( 'fill_remaining_chart_units is private', method_exists( 'EEM_Orders_Repository', 'fill_remaining_chart_units' ) && ( new ReflectionMethod( 'EEM_Orders_Repository', 'fill_remaining_chart_units' ) )->isPrivate(), $pass, $fail, $log );

$repo   = new EEM_Orders_Repository();
$result = $repo->auto_assign_units_for_reservation( 0 ); // invalid id → safe empty shape
ok( 'return shape has updated key',   is_array( $result ) && array_key_exists( 'updated', $result ),   $pass, $fail, $log );
ok( 'return shape has total key',     array_key_exists( 'total', $result ),     $pass, $fail, $log );
ok( 'return shape has assigned key',  array_key_exists( 'assigned', $result ),  $pass, $fail, $log );
ok( 'return shape has shortages key', array_key_exists( 'shortages', $result ), $pass, $fail, $log );
ok( 'invalid reservation → updated 0', 0 === (int) $result['updated'], $pass, $fail, $log );

/* ── Admin: AJAX handler + hook ── */
ok( 'EEM_Admin::ajax_auto_assign is public', method_exists( 'EEM_Admin', 'ajax_auto_assign' ) && ( new ReflectionMethod( 'EEM_Admin', 'ajax_auto_assign' ) )->isPublic(), $pass, $fail, $log );
ok( 'wp_ajax_eem_auto_assign hooked', false !== has_action( 'wp_ajax_eem_auto_assign' ), $pass, $fail, $log );
ok( 'handler checks eem_auto_assign nonce', strpos( $admin_src, "check_ajax_referer( 'eem_auto_assign'" ) !== false, $pass, $fail, $log );
ok( 'handler requires manage_options', preg_match( '/ajax_auto_assign\(\).*?current_user_can\(\s*\'manage_options\'/s', $admin_src ) === 1, $pass, $fail, $log );
ok( 'handler buffers a dynamic-region re-render', preg_match( '/ajax_auto_assign\(\).*?ob_start\(\).*?render_stall_chart_dynamic_region/s', $admin_src ) === 1, $pass, $fail, $log );
ok( 'handler returns html in JSON', preg_match( "/ajax_auto_assign\(\).*?'html'\s*=>\s*\\\$html/s", $admin_src ) === 1, $pass, $fail, $log );
ok( 'handler 409s when no inventory + shortfall', preg_match( '/ajax_auto_assign\(\).*?No available inventory.*?409/s', $admin_src ) === 1, $pass, $fail, $log );

/* ── Admin: dynamic-region / overlays split ── */
ok( 'render_stall_chart_dynamic_region is private', method_exists( 'EEM_Admin', 'render_stall_chart_dynamic_region' ) && ( new ReflectionMethod( 'EEM_Admin', 'render_stall_chart_dynamic_region' ) )->isPrivate(), $pass, $fail, $log );
ok( 'render_stall_chart_overlays is private', method_exists( 'EEM_Admin', 'render_stall_chart_overlays' ) && ( new ReflectionMethod( 'EEM_Admin', 'render_stall_chart_overlays' ) )->isPrivate(), $pass, $fail, $log );
ok( 'page wraps dynamic region in #eem-stall-chart-dynamic', strpos( $admin_src, 'id="eem-stall-chart-dynamic"' ) !== false, $pass, $fail, $log );
ok( 'overlays localizes autoAssignNonce', strpos( $admin_src, 'window.eemStallChart.autoAssignNonce' ) !== false, $pass, $fail, $log );
ok( 'overlays creates eem_auto_assign nonce', strpos( $admin_src, "wp_create_nonce( 'eem_auto_assign' )" ) !== false, $pass, $fail, $log );

/* ── Admin: button markup ── */
// Text-only per the no-icons-on-buttons rule (leading SVG removed 2.7.175).
ok( 'Generate Assignments is now a button (auto-assign-all action)', preg_match( '/<button[^>]*data-eem-action="stall-chart-auto-assign-all"[^>]*>.*?Generate Assignments/s', $admin_src ) === 1, $pass, $fail, $log );
// Scope the negative to the chart-detail dynamic region; the legacy admin-post
// flow legitimately persists on the CPT edit screen + chart-disabled fallback.
$dyn_start = strpos( $admin_src, 'function render_stall_chart_dynamic_region(' );
$dyn_end   = strpos( $admin_src, 'function render_stall_chart_overlays(' );
$dyn_body  = ( false !== $dyn_start && false !== $dyn_end ) ? substr( $admin_src, $dyn_start, $dyn_end - $dyn_start ) : '';
ok( 'chart-detail action bar no longer uses admin-post generate link', '' !== $dyn_body && strpos( $dyn_body, 'equine_event_manager_generate_stall_assignments' ) === false, $pass, $fail, $log, 'legacy reload link still in dynamic region' );
ok( 'issues card has Auto-Assign All button', preg_match( '/eem-stall-chart-issues-auto-all[^>]*data-eem-action="stall-chart-auto-assign-all"/', $admin_src ) === 1 || preg_match( '/data-eem-action="stall-chart-auto-assign-all"[^>]*eem-stall-chart-issues-auto-all/', $admin_src ) === 1, $pass, $fail, $log );
ok( 'issues row has per-order Auto-Assign button w/ data-order-key', preg_match( '/data-eem-action="stall-chart-auto-assign-order"[^>]*data-order-key="/s', $admin_src ) === 1, $pass, $fail, $log );

/* ── JS: delegated handlers + swap ── */
ok( 'JS handles stall-chart-auto-assign-all', strpos( $js_src, "data-eem-action=\"stall-chart-auto-assign-all\"" ) !== false, $pass, $fail, $log );
ok( 'JS handles stall-chart-auto-assign-order', strpos( $js_src, "data-eem-action=\"stall-chart-auto-assign-order\"" ) !== false, $pass, $fail, $log );
ok( 'JS defines eemRunAutoAssign', strpos( $js_src, 'function eemRunAutoAssign(' ) !== false, $pass, $fail, $log );
ok( 'JS posts action eem_auto_assign', preg_match( "/eemRunAutoAssign.*?body\.set\('action', 'eem_auto_assign'\)/s", $js_src ) === 1, $pass, $fail, $log );
ok( 'JS sends autoAssignNonce', strpos( $js_src, 'cfg.autoAssignNonce' ) !== false, $pass, $fail, $log );
ok( 'JS swaps #eem-stall-chart-dynamic innerHTML', preg_match( "/getElementById\('eem-stall-chart-dynamic'\).*?innerHTML\s*=\s*data\.html/s", $js_src ) === 1, $pass, $fail, $log );
ok( 'JS re-applies inv/tab state after swap', preg_match( '/innerHTML\s*=\s*data\.html;.*?eemScApplyState/s', $js_src ) === 1, $pass, $fail, $log );

/* ── Version ── */
ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.51', version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.51', '>=' ), $pass, $fail, $log );

echo implode( "\n", $log ) . "\n";
echo "=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
