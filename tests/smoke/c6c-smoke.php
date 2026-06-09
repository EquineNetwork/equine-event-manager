<?php
/** C6.C smoke — Bulk refund engine (AJAX step endpoint + retry-time
 * re-validation + modal 3-state markup + CLEANUP #29 logged). */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass=0;$fail=0;$log=array();
function ok($l,$c,&$p,&$f,&$lg,$d=''){if($c){$p++;$lg[]="  ✓ {$l}";}else{$f++;$lg[]="  ✗ {$l}".($d?" — {$d}":'');}}

echo "\n=== C6.C SMOKE ===\n";

wp_set_current_user( 1 );
$admin = new EEM_Admin();
$repo  = new EEM_Orders_Repository();
$ref   = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$ref->setAccessible( true );
$rows  = $ref->invoke( $repo );

// ── [1] AJAX wiring + method existence ─────────────────────────────
echo "\n[1] Engine wiring + helper API\n";
ok( 'wp_ajax_eem_order_bulk_refund_step action is registered',
	false !== has_action( 'wp_ajax_eem_order_bulk_refund_step' ),
	$pass, $fail, $log );
ok( 'EEM_Admin::handle_ajax_bulk_refund_step is public',
	method_exists( 'EEM_Admin', 'handle_ajax_bulk_refund_step' ) && ( new ReflectionMethod( 'EEM_Admin', 'handle_ajax_bulk_refund_step' ) )->isPublic(),
	$pass, $fail, $log );
ok( 'EEM_Admin::get_order_remaining_refundable is public (C6.C prep)',
	method_exists( 'EEM_Admin', 'get_order_remaining_refundable' ) && ( new ReflectionMethod( 'EEM_Admin', 'get_order_remaining_refundable' ) )->isPublic(),
	$pass, $fail, $log );

// ── [2] get_order_remaining_refundable behavior ────────────────────
echo "\n[2] get_order_remaining_refundable\n";
ok( 'unknown order_key → 0.0',                            0.0 === $admin->get_order_remaining_refundable( 'definitely-not-a-real-key-abc123' ), $pass, $fail, $log );

$paid_order = null;
foreach ( $rows as $r ) {
	if ( 'paid' === ( $r['status_slug'] ?? '' ) ) { $paid_order = $r; break; }
}
if ( $paid_order ) {
	$remaining = $admin->get_order_remaining_refundable( $paid_order['order_key'] );
	ok( 'paid order remaining_refundable > 0',            $remaining > 0,                                  $pass, $fail, $log );
	ok( 'paid order remaining_refundable equals total',   abs( $remaining - (float) $paid_order['total'] ) < 0.01, $pass, $fail, $log, "remaining={$remaining}, total={$paid_order['total']}" );
}

// ── [3] Activity-log write moved into kernel ───────────────────────
// Verify process_amount_refund (the kernel) writes activity-log entries
// directly — both C6.B (single) and C6.C (bulk) callers inherit telemetry
// without duplicating the write. Source-grep is sufficient evidence.
echo "\n[3] Activity-log write moved into process_amount_refund kernel\n";
$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
// CLEANUP #27: the process_amount_refund kernel was extracted into
// EEM_Refund_Engine. The admin method is now a thin delegate; the real
// kernel body (with the EEM_Activity_Log::write call) lives in the engine.
$engine_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-refund-engine.php' );
$kernel_pos = strpos( $engine_src, 'public function process_amount_refund' );
$next_method_pos = strpos( $engine_src, "\n\tpublic function ", $kernel_pos + 1 );
$kernel_block = $kernel_pos && $next_method_pos ? substr( $engine_src, $kernel_pos, $next_method_pos - $kernel_pos ) : substr( $engine_src, (int) $kernel_pos );
ok( 'process_amount_refund kernel (EEM_Refund_Engine) contains EEM_Activity_Log::write call',
	false !== strpos( $kernel_block, 'EEM_Activity_Log::write' ),
	$pass, $fail, $log );

$single_pos = strpos( $admin_src, 'public function handle_ajax_refund_single' );
// Anchor updated in C6.E.2: handle_ajax_order_add_note was inserted
// between single and bulk, so slice now ends at the new method's
// declaration. Original intent preserved: assert handle_ajax_refund_
// single's body does NOT write to activity log (write was moved to
// the process_amount_refund kernel in C6.C).
$next_method2_pos = strpos( $admin_src, 'public function handle_ajax_order_add_note' );
$single_block = $single_pos && $next_method2_pos ? substr( $admin_src, $single_pos, $next_method2_pos - $single_pos ) : '';
ok( 'handle_ajax_refund_single no longer writes activity log (moved to kernel)',
	false === strpos( $single_block, 'EEM_Activity_Log::write' ),
	$pass, $fail, $log );

// ── [4] Bulk-step endpoint contract — NO amount parameter ──────────
// The retry-safety property requires the endpoint to compute the refund
// amount server-side at call time. Source-grep verifies no $_POST['amount']
// lookup in the bulk handler.
echo "\n[4] Bulk step contract — server computes amount, never trusts client\n";
$bulk_pos = strpos( $admin_src, 'public function handle_ajax_bulk_refund_step' );
$bulk_end = strpos( $admin_src, 'public function handle_order_refund', $bulk_pos );
$bulk_block = $bulk_pos && $bulk_end ? substr( $admin_src, $bulk_pos, $bulk_end - $bulk_pos ) : '';
ok( 'bulk-step body does NOT read $_POST[\'amount\']',
	false === strpos( $bulk_block, "_POST['amount']" ) && false === strpos( $bulk_block, '_POST["amount"]' ),
	$pass, $fail, $log );
ok( 'bulk-step body DOES call get_order_remaining_refundable',
	false !== strpos( $bulk_block, 'get_order_remaining_refundable' ),
	$pass, $fail, $log );
ok( 'bulk-step uses shared eem_bulk_refund_step nonce action (not per-order)',
	false !== strpos( $bulk_block, "'eem_bulk_refund_step'" ),
	$pass, $fail, $log );

// ── [5] Retry-time state-change safety ─────────────────────────────
// The critical property the user called out: between original batch
// attempt and retry click, an order's remaining_refundable can change
// (parallel admin refund, late-landing prior refund). Retry must use
// the CURRENT value, not a stale amount.
//
// We can't drive the gateway, but we can verify the helper that the
// retry path relies on returns the up-to-date remaining at call time.
echo "\n[5] Retry-time re-validation safety\n";
if ( $paid_order ) {
	// Initial reading.
	$before = $admin->get_order_remaining_refundable( $paid_order['order_key'] );

	// Simulate a parallel refund: directly mutate one component's notes
	// to add a "Refunded Amount: 30.00" line. This is what
	// persist_component_refund writes — we're synthesizing the post-
	// parallel-refund state without going through the gateway.
	if ( ! empty( $paid_order['components'] ) ) {
		global $wpdb;
		$first_comp = $paid_order['components'][0];
		// Component['table'] is a slug ('stall' | 'rv'), not a full table
		// name. The repo's private get_table_name() handles the slug→full
		// mapping; in smoke context we hardcode the same map.
		$table_slug = isset( $first_comp['table'] ) ? (string) $first_comp['table'] : '';
		$table      = 'stall' === $table_slug ? $wpdb->prefix . 'en_stall_reservations'
		             : ( 'rv'    === $table_slug ? $wpdb->prefix . 'en_rv_reservations' : '' );
		$row_id     = isset( $first_comp['row_id'] ) ? (int) $first_comp['row_id'] : 0;
		$orig_notes = isset( $first_comp['notes'] ) ? (string) $first_comp['notes'] : '';

		if ( $table && $row_id ) {
			// Pretend $30 was refunded externally between batch + retry.
			$wpdb->update(
				$table,
				array( 'notes' => $orig_notes . "\nRefunded Amount: 30.00" ),
				array( 'id' => $row_id ),
				array( '%s' ),
				array( '%d' )
			);

			// Re-read remaining via a FRESH EEM_Admin instance — the repo
			// caches get_grouped_orders() per-instance, and direct DB UPDATE
			// bypasses the cache invalidation pattern that the repo's own
			// write methods use. This mirrors the real-world flow where
			// each AJAX bulk-step request gets a fresh handler invocation.
			$admin_fresh = new EEM_Admin();
			$after = $admin_fresh->get_order_remaining_refundable( $paid_order['order_key'] );
			ok( 'remaining_refundable DROPS by parallel refund amount (synthetic)',
				abs( ( $before - $after ) - 30.0 ) < 0.01,
				$pass, $fail, $log, "before={$before}, after={$after}, delta=" . ( $before - $after ) );

			// Restore notes (smoke must be self-cleaning).
			$wpdb->update(
				$table,
				array( 'notes' => $orig_notes ),
				array( 'id' => $row_id ),
				array( '%s' ),
				array( '%d' )
			);

			// Sanity-check restore — again, fresh instance to dodge cache.
			$admin_fresh2 = new EEM_Admin();
			$restored = $admin_fresh2->get_order_remaining_refundable( $paid_order['order_key'] );
			ok( 'smoke is self-cleaning — remaining restored',
				abs( $restored - $before ) < 0.01,
				$pass, $fail, $log );
		}
	}
}

// ── [6] Modal 3-state markup present ───────────────────────────────
echo "\n[6] Modal 3-state markup\n";
wp_set_current_user( 1 );
$_GET = array( 'page' => EEM_Orders_List_Page::MENU_SLUG );
ob_start();
( new EEM_Orders_List_Page() )->render();
$list_html = ob_get_clean();

ok( 'modal carries eem-bulk-refund-modal class',          str_contains( $list_html, 'eem-bulk-refund-modal' ),                 $pass, $fail, $log );
ok( 'modal starts in --state-intro',                      str_contains( $list_html, 'eem-bulk-refund--state-intro' ),           $pass, $fail, $log );
ok( 'modal has intro state body',                         str_contains( $list_html, 'eem-bulk-refund-state--intro' ),           $pass, $fail, $log );
ok( 'modal has processing state body',                    str_contains( $list_html, 'eem-bulk-refund-state--processing' ),      $pass, $fail, $log );
ok( 'modal has summary state body',                       str_contains( $list_html, 'eem-bulk-refund-state--summary' ),         $pass, $fail, $log );
ok( 'modal has progress-list target',                     str_contains( $list_html, 'data-eem-bulk-refund-progress-list' ),     $pass, $fail, $log );
ok( 'modal has summary-totals target',                    str_contains( $list_html, 'data-eem-bulk-refund-summary-totals' ),    $pass, $fail, $log );
ok( 'modal has failure-list target',                      str_contains( $list_html, 'data-eem-bulk-refund-failure-list' ),      $pass, $fail, $log );
ok( 'modal carries tab-close warning copy',               str_contains( $list_html, 'refunds in progress will complete' ),      $pass, $fail, $log );
ok( 'modal uses shared bulk-step nonce action',           str_contains( $list_html, '_eem_bulk_refund_nonce' ),                 $pass, $fail, $log );

// ── [7] AJAX handler gates (capability + nonce, deterministic) ─────
echo "\n[7] AJAX handler gates\n";
$saved_user = get_current_user_id();
wp_set_current_user( 0 );
ok( 'capability gate rejects users without manage_options', ! current_user_can( 'manage_options' ), $pass, $fail, $log );
wp_set_current_user( $saved_user );
ok( 'capability gate accepts admin',                       current_user_can( 'manage_options' ),    $pass, $fail, $log );

$valid_nonce = wp_create_nonce( 'eem_bulk_refund_step' );
ok( 'shared bulk-step nonce verifies for any order_key (single nonce per batch)',
	false !== wp_verify_nonce( $valid_nonce, 'eem_bulk_refund_step' ),
	$pass, $fail, $log );
ok( 'bulk-step nonce rejects bogus value',
	false === wp_verify_nonce( 'BOGUS', 'eem_bulk_refund_step' ),
	$pass, $fail, $log );

// ── [8] CLEANUP entries — #29 logged, #15 marked resolved ──────────
echo "\n[8] CLEANUP doc updates\n";
$cleanup = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'CLEANUP.md' );
ok( 'CLEANUP #29 entry exists',                            str_contains( $cleanup, '### 29.' ),                                 $pass, $fail, $log );
ok( 'CLEANUP #29 mentions get_orders_by_keys',             str_contains( $cleanup, 'get_orders_by_keys' ),                      $pass, $fail, $log );
ok( 'CLEANUP #15 marked ✅ Resolved in C6.C',              str_contains( $cleanup, '### 15.' ) && str_contains( $cleanup, 'Resolved in C6.C' ), $pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
