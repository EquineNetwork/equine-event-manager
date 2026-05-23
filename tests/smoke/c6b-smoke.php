<?php
/** C6.B smoke — Single-order Refund modal + AJAX handler + server-side validation. */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C6.B SMOKE ===\n";

wp_set_current_user( 1 );
$admin = new EEM_Admin();
$repo  = new EEM_Orders_Repository();
$ref   = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$ref->setAccessible( true );
$rows  = $ref->invoke( $repo );

// ── [1] AJAX wiring + method existence ─────────────────────────────
ok( 'wp_ajax_eem_order_refund_single action is registered',
	false !== has_action( 'wp_ajax_eem_order_refund_single' ),
	$pass, $fail, $log );
ok( 'EEM_Admin::process_amount_refund is public',
	method_exists( 'EEM_Admin', 'process_amount_refund' ) && ( new ReflectionMethod( 'EEM_Admin', 'process_amount_refund' ) )->isPublic(),
	$pass, $fail, $log );
ok( 'EEM_Admin::handle_ajax_refund_single is public',
	method_exists( 'EEM_Admin', 'handle_ajax_refund_single' ) && ( new ReflectionMethod( 'EEM_Admin', 'handle_ajax_refund_single' ) )->isPublic(),
	$pass, $fail, $log );

// ── [2] Modal markup is rendered on the Order Detail page ──────────
if ( ! empty( $rows ) ) {
	$any_order = $rows[0];
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $any_order['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$html = ob_get_clean();

	ok( 'render emits #eem-order-refund-modal',           str_contains( $html, 'eem-order-refund-modal' ),    $pass, $fail, $log );
	ok( 'modal carries refund nonce field',               str_contains( $html, '_eem_refund_single_nonce' ),  $pass, $fail, $log );
	ok( 'modal carries data-eem-order-refund-form attr',  str_contains( $html, 'data-eem-order-refund-form' ),$pass, $fail, $log );
	ok( 'modal carries amount input',                     str_contains( $html, 'eem-order-refund-amount' ),   $pass, $fail, $log );
	ok( 'modal carries reason textarea',                  str_contains( $html, 'eem-order-refund-reason' ),   $pass, $fail, $log );
	ok( 'modal carries Confirm refund button',            str_contains( $html, 'order-refund-single-confirm' ),$pass, $fail, $log );
	ok( 'Payment Details has data-eem-refund-history target', str_contains( $html, 'data-eem-refund-history' ),$pass, $fail, $log );
	ok( 'modal reuses C5.D .eem-modal chrome',            str_contains( $html, 'class="eem-modal"' ) || str_contains( $html, 'eem-modal" id="eem-order-refund-modal' ), $pass, $fail, $log );
	ok( 'amount input has prefix dollar sign',            str_contains( $html, 'eem-order-refund-amount-prefix' ), $pass, $fail, $log );
}

// ── [3] Server-side validation paths (no gateway call required) ────
echo "\n[3] Server-side validation paths\n";

// (a) Unknown order_key → order_not_found.
$r1 = $admin->process_amount_refund( 'definitely-not-a-real-key-abc123', 10.0 );
ok( 'unknown order_key → WP_Error',                       is_wp_error( $r1 ), $pass, $fail, $log );
ok( 'unknown order_key → code=order_not_found',           is_wp_error( $r1 ) && 'order_not_found' === $r1->get_error_code(), $pass, $fail, $log );

// (b) Zero amount → invalid_amount.
$paid_order = null;
foreach ( $rows as $r ) {
	if ( 'paid' === ( $r['status_slug'] ?? '' ) ) { $paid_order = $r; break; }
}

if ( $paid_order ) {
	$r2 = $admin->process_amount_refund( $paid_order['order_key'], 0.0 );
	ok( 'zero amount → WP_Error',                         is_wp_error( $r2 ), $pass, $fail, $log );
	ok( 'zero amount → code=invalid_amount',              is_wp_error( $r2 ) && 'invalid_amount' === $r2->get_error_code(), $pass, $fail, $log );

	$r2b = $admin->process_amount_refund( $paid_order['order_key'], -50.0 );
	ok( 'negative amount → WP_Error invalid_amount',      is_wp_error( $r2b ) && 'invalid_amount' === $r2b->get_error_code(), $pass, $fail, $log );

	// (c) Amount exceeds remaining → exceeds_remaining.
	$r3 = $admin->process_amount_refund( $paid_order['order_key'], 999999.99 );
	ok( 'amount > remaining → WP_Error',                  is_wp_error( $r3 ), $pass, $fail, $log );
	ok( 'amount > remaining → code=exceeds_remaining',    is_wp_error( $r3 ) && 'exceeds_remaining' === $r3->get_error_code(), $pass, $fail, $log );
	ok( 'exceeds error message names the cap',            is_wp_error( $r3 ) && str_contains( $r3->get_error_message(), 'remaining refundable balance' ), $pass, $fail, $log );
} else {
	ok( 'amount-validation tests skipped (no paid order)',true, $pass, $fail, $log );
}

// ── [4] Partial-then-partial math — synthetic component with prior refund ──
// Verifies get_component_refunded_amount + get_component_remaining_refundable_amount
// account for an already-refunded amount embedded in component notes.
// (The two helpers are private; reach them via Reflection. Test is math-only —
// no gateway call, no DB mutation.)
echo "\n[4] Partial-then-partial math (synthetic component)\n";

$adminRefl       = new ReflectionClass( 'EEM_Admin' );
$refundedFn      = $adminRefl->getMethod( 'get_component_refunded_amount' );
$refundedFn->setAccessible( true );
$remainingFn     = $adminRefl->getMethod( 'get_component_remaining_refundable_amount' );
$remainingFn->setAccessible( true );

$component_fresh = array( 'total' => 100.00, 'notes' => 'Something unrelated' );
ok( 'fresh component → already_refunded = 0',             0.0 === $refundedFn->invoke( $admin, $component_fresh ), $pass, $fail, $log );
ok( 'fresh component → remaining = 100',                  100.0 === $remainingFn->invoke( $admin, $component_fresh ), $pass, $fail, $log );

$component_partial = array( 'total' => 100.00, 'notes' => "Some line\nRefunded Amount: 30.00\nAnother line" );
ok( 'partially-refunded component → already_refunded = 30', 30.0 === $refundedFn->invoke( $admin, $component_partial ), $pass, $fail, $log );
ok( 'partially-refunded component → remaining = 70',        70.0 === $remainingFn->invoke( $admin, $component_partial ), $pass, $fail, $log );

$component_fully = array( 'total' => 100.00, 'notes' => "Refunded Amount: 100.00" );
ok( 'fully-refunded component → already_refunded = 100',  100.0 === $refundedFn->invoke( $admin, $component_fully ), $pass, $fail, $log );
// Note: max(0, $float) in legacy returns int 0 when result == 0, hence the cast.
ok( 'fully-refunded component → remaining = 0',           0.0 === (float) $remainingFn->invoke( $admin, $component_fully ), $pass, $fail, $log );

// Implicit-from-payment_status branch (no Refunded Amount line; status=refunded).
$component_status_refunded = array( 'total' => 50.00, 'notes' => '', 'payment_status' => 'refunded' );
ok( 'status=refunded → already_refunded = total',         50.0 === $refundedFn->invoke( $admin, $component_status_refunded ), $pass, $fail, $log );

// ── [5] AJAX handler gate-level checks ─────────────────────────────
// wp_send_json_error invokes wp_die() which in CLI context exits the
// PHP process — bypassing both wp_die_handler and wp_die_ajax_handler
// filter chains (verified via probe). We therefore can't invoke
// handle_ajax_refund_single() directly from this smoke without killing
// the runner. Instead, verify the GATES the handler relies on behave
// correctly. The handler's first two checks (capability + nonce) use
// current_user_can() and wp_verify_nonce() — both deterministic.
//
// Full end-to-end AJAX coverage is a manual-browser-verification step
// for this chunk; a future "AJAX smoke harness" CLEANUP entry could
// subshell wp-cli to exercise wp_die paths in isolated processes.
echo "\n[5] AJAX handler gates (capability + nonce)\n";

$saved_user = get_current_user_id();
wp_set_current_user( 0 );
ok( 'capability gate rejects users without manage_options',
	! current_user_can( 'manage_options' ),
	$pass, $fail, $log );

wp_set_current_user( $saved_user );
ok( 'capability gate accepts admin user',
	current_user_can( 'manage_options' ),
	$pass, $fail, $log );

// Nonce gate — the handler builds nonces with action 'eem_refund_single_' . $order_key.
$test_order_key = 'test-key-for-nonce-gate';
$valid_nonce    = wp_create_nonce( 'eem_refund_single_' . $test_order_key );
ok( 'nonce gate accepts a valid nonce for the order_key',
	false !== wp_verify_nonce( $valid_nonce, 'eem_refund_single_' . $test_order_key ),
	$pass, $fail, $log );
ok( 'nonce gate rejects a bogus nonce',
	false === wp_verify_nonce( 'BOGUS', 'eem_refund_single_' . $test_order_key ),
	$pass, $fail, $log );
ok( 'nonce gate rejects a valid nonce against the wrong order_key',
	false === wp_verify_nonce( $valid_nonce, 'eem_refund_single_different-order-key' ),
	$pass, $fail, $log );

// ── [6] Legacy refund infrastructure unchanged (regression guard) ──
$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
ok( 'legacy handle_order_refund still present',           false !== strpos( $admin_src, 'public function handle_order_refund' ), $pass, $fail, $log );
ok( 'legacy refund_order_component still present',        false !== strpos( $admin_src, 'private function refund_order_component' ), $pass, $fail, $log );
ok( 'legacy persist_component_refund still present',      false !== strpos( $admin_src, 'private function persist_component_refund' ), $pass, $fail, $log );

// ── [7] CLEANUP #27 entry present ─────────────────────────────────
$cleanup = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'CLEANUP.md' );
ok( 'CLEANUP #27 entry exists',                            str_contains( $cleanup, '### 27.' ), $pass, $fail, $log );
ok( 'CLEANUP #27 mentions EEM_Refund_Engine',              str_contains( $cleanup, 'EEM_Refund_Engine' ), $pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
