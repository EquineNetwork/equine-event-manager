<?php
/**
 * C6.E.2 smoke — Add Note form + AJAX endpoint.
 *
 * Per the C6.E.2 mini-kickoff: ~40 assertions covering form render,
 * AJAX handler registration, validation envelope, write + read-back
 * round-trip via the canonical consumer query, render-after-write
 * confirmation, JS dispatch arm, CSS form chrome.
 *
 * Read-back discipline (CLAUDE.md note 1): write paths must verify
 * round-trip via the canonical consumer method. §5 writes a note via
 * the handler, §6 reads back via get_for_order_key, §7 confirms the
 * new entry appears in the next render of the Order Detail page.
 *
 * AJAX gating note (CLEANUP #28): wp_send_json_error in wp-cli context
 * bypasses some filters, so capability/nonce gates use source-grep +
 * lightweight direct-call verification rather than full HTTP round-
 * trips. Manual browser verify covers the AJAX wire end-to-end.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
// Force wp_send_json_* to use the AJAX wp_die handler path so our
// filter swap below catches the die and we can capture the JSON
// response. Without this, wp_doing_ajax() returns false in wp-cli
// context and wp_die uses the default handler which calls die()
// directly, terminating the smoke.
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
$pass = 0; $fail = 0; $log = array();
function ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C6.E.2 SMOKE ===\n";
wp_set_current_user( 1 );

// ── [1] Handler exists + registered to the wp_ajax_ hook ────────────
echo "\n[1] AJAX handler registration\n";
ok( 'EEM_Admin::handle_ajax_order_add_note method exists',
	method_exists( 'EEM_Admin', 'handle_ajax_order_add_note' ),
	$pass, $fail, $log );
ok( 'wp_ajax_eem_order_add_note action registered',
	false !== has_action( 'wp_ajax_eem_order_add_note' ),
	$pass, $fail, $log );

// ── [2] Form render in Order Detail page output ────────────────────
echo "\n[2] Form render in Order Detail HTML\n";
$repo = new EEM_Orders_Repository();
$ref  = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$ref->setAccessible( true );
$rows = $ref->invoke( $repo );
$order = null;
foreach ( $rows as $o ) { if ( ! empty( $o['order_key'] ) ) { $order = $o; break; } }
$html = '';
if ( $order ) {
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $order['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$html = ob_get_clean();
}
ok( 'render emits .eem-add-note-form wrapper',                     str_contains( $html, 'class="eem-add-note-form"' ),                 $pass, $fail, $log );
ok( 'render emits data-eem-add-note-form attr',                    str_contains( $html, 'data-eem-add-note-form' ),                    $pass, $fail, $log );
ok( 'render emits textarea with maxlength="2000"',                 str_contains( $html, 'maxlength="2000"' ),                          $pass, $fail, $log );
ok( 'render emits data-eem-add-note-textarea hook',                str_contains( $html, 'data-eem-add-note-textarea' ),                $pass, $fail, $log );
ok( 'render emits inline error mount node',                        str_contains( $html, 'data-eem-add-note-error' ),                   $pass, $fail, $log );
ok( 'render emits Submit button with add-note-submit action',      str_contains( $html, 'data-eem-action="add-note-submit"' ),         $pass, $fail, $log );
ok( 'render emits disabled attr on Submit (initial state)',        preg_match( '/data-eem-add-note-submit\s+disabled/', $html ) === 1 || preg_match( '/disabled\s+data-eem-add-note-submit/', $html ) === 1 || preg_match( '/data-eem-add-note-submit[^>]*disabled/', $html ) === 1, $pass, $fail, $log );
ok( 'render emits hidden action input = eem_order_add_note',       str_contains( $html, 'value="eem_order_add_note"' ),                $pass, $fail, $log );
ok( 'render emits nonce field _eem_add_note_nonce',                str_contains( $html, '_eem_add_note_nonce' ),                       $pass, $fail, $log );
ok( 'form rendered INSIDE the .eem-order-activity section',
	preg_match( '/<div class="eem-order-activity"[^>]*>.*?<form class="eem-add-note-form"/s', $html ) === 1,
	$pass, $fail, $log );
ok( 'form rendered AFTER the activity-list mount (positional Q1=a)',
	strpos( $html, 'data-eem-activity-list' ) < strpos( $html, 'data-eem-add-note-form' ),
	$pass, $fail, $log );

// ── [3] Validation gates (direct-call) ─────────────────────────────
// Drive the handler with synthesized $_POST states. Each invocation
// terminates via wp_send_json_*; we capture the JSON output, parse it,
// and assert the code + http status. Uses output buffering to swallow
// the JSON since wp_send_json_* echoes before die.
echo "\n[3] Validation gates — direct dispatch\n";

if ( ! $order ) {
	ok( 'fixture: seeded order exists', false, $pass, $fail, $log, 'no order available — bail' );
} else {
	$probe_key = $order['order_key'];
	$valid_nonce = wp_create_nonce( 'eem_order_add_note_' . $probe_key );
	$admin = new EEM_Admin();

	// Helper: run handler with $_POST overrides, capture wp_send_json_*
	// output, return decoded JSON array. wp_send_json_* calls die — we
	// trap via wp_die_handler swap so the script continues.
	$invoke = function ( array $post_overrides ) use ( $admin ) {
		$_POST = array_merge( array(
			'action'    => 'eem_order_add_note',
		), $post_overrides );
		$_REQUEST = $_POST;
		$captured = null;
		$handler = function ( $msg = '', $title = '', $args = array() ) use ( &$captured ) {
			$captured = ob_get_contents();
			ob_end_clean();
			throw new Exception( '__EEM_SMOKE_DIE__' );
		};
		add_filter( 'wp_die_ajax_handler', function () use ( $handler ) { return $handler; } );
		add_filter( 'wp_die_handler',      function () use ( $handler ) { return $handler; } );
		ob_start();
		try {
			$admin->handle_ajax_order_add_note();
		} catch ( Exception $e ) {
			// expected — wp_send_json_* always dies.
		}
		if ( null === $captured && ob_get_level() > 0 ) {
			$captured = ob_get_contents();
			ob_end_clean();
		}
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		return $captured ? json_decode( $captured, true ) : null;
	};

	// Missing order_key.
	$r = $invoke( array() );
	ok( 'missing order_key → invalid_request error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'invalid_request' === $r['data']['code'],
		$pass, $fail, $log, 'got ' . wp_json_encode( $r ) );

	// Missing/wrong nonce.
	$r = $invoke( array( 'order_key' => $probe_key ) );
	ok( 'missing nonce → nonce error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'nonce' === $r['data']['code'],
		$pass, $fail, $log );

	$r = $invoke( array( 'order_key' => $probe_key, '_eem_add_note_nonce' => 'bogus' ) );
	ok( 'wrong nonce → nonce error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'nonce' === $r['data']['code'],
		$pass, $fail, $log );

	// Unknown order_key — needs a fresh valid-format nonce for that key.
	$bogus_key = 'c6e2-bogus-' . wp_generate_password( 8, false );
	$bogus_nonce = wp_create_nonce( 'eem_order_add_note_' . $bogus_key );
	$r = $invoke( array( 'order_key' => $bogus_key, '_eem_add_note_nonce' => $bogus_nonce ) );
	ok( 'unknown order_key → not_found error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'not_found' === $r['data']['code'],
		$pass, $fail, $log );

	// Empty note (after trim).
	$r = $invoke( array( 'order_key' => $probe_key, '_eem_add_note_nonce' => $valid_nonce, 'note' => "   \n  " ) );
	ok( 'whitespace-only note → empty error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'empty' === $r['data']['code'],
		$pass, $fail, $log );

	$r = $invoke( array( 'order_key' => $probe_key, '_eem_add_note_nonce' => $valid_nonce, 'note' => '' ) );
	ok( 'empty note → empty error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'empty' === $r['data']['code'],
		$pass, $fail, $log );

	// Too-long note (>2000 chars).
	$too_long = str_repeat( 'x', 2001 );
	$r = $invoke( array( 'order_key' => $probe_key, '_eem_add_note_nonce' => $valid_nonce, 'note' => $too_long ) );
	ok( '>2000-char note → too_long error',
		is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'too_long' === $r['data']['code'],
		$pass, $fail, $log );

	// ── [4] Happy path WRITE ───────────────────────────────────────
	echo "\n[4] Happy-path write\n";
	$probe_text = 'c6e2-probe-' . wp_generate_password( 10, false ) . ' Smoke note content.';
	$r = $invoke( array(
		'order_key'           => $probe_key,
		'_eem_add_note_nonce' => $valid_nonce,
		'note'                => $probe_text,
	) );
	ok( 'happy-path returns success=true',
		is_array( $r ) && ! empty( $r['success'] ),
		$pass, $fail, $log, 'got ' . wp_json_encode( $r ) );
	ok( 'response carries entry_id (int > 0)',
		is_array( $r ) && ! empty( $r['data']['entry_id'] ) && is_int( $r['data']['entry_id'] ),
		$pass, $fail, $log );
	ok( 'response carries new_count >= 1',
		is_array( $r ) && isset( $r['data']['new_count'] ) && $r['data']['new_count'] >= 1,
		$pass, $fail, $log );
	ok( 'response carries non-empty html for prepend',
		is_array( $r ) && ! empty( $r['data']['html'] ) && str_contains( $r['data']['html'], 'eem-activity-log-entry' ),
		$pass, $fail, $log );
	ok( 'response html contains the probe note text',
		is_array( $r ) && ! empty( $r['data']['html'] ) && str_contains( $r['data']['html'], $probe_text ),
		$pass, $fail, $log );

	// ── [5] READ-BACK via canonical consumer query (round-trip) ────
	echo "\n[5] Round-trip via EEM_Activity_Log::get_for_order_key\n";
	$rows = EEM_Activity_Log::get_for_order_key( $probe_key, 5 );
	$probe_row = null;
	foreach ( $rows as $row ) {
		if ( 'ordernote' === $row['event_type'] && isset( $row['payload']['note'] ) && $row['payload']['note'] === $probe_text ) {
			$probe_row = $row;
			break;
		}
	}
	ok( 'read-back finds the new ordernote entry',
		null !== $probe_row,
		$pass, $fail, $log );
	ok( 'read-back entry has event_type = ordernote',
		$probe_row && 'ordernote' === $probe_row['event_type'],
		$pass, $fail, $log );
	ok( 'read-back entry payload preserves note text',
		$probe_row && $probe_row['payload']['note'] === $probe_text,
		$pass, $fail, $log );
	ok( 'read-back entry actor_type = admin',
		$probe_row && 'admin' === $probe_row['actor_type'],
		$pass, $fail, $log );
	ok( 'read-back entry has actor_label (admin display name)',
		$probe_row && ! empty( $probe_row['actor_label'] ),
		$pass, $fail, $log );
	ok( 'read-back entry payload title starts with "Admin note"',
		$probe_row && isset( $probe_row['payload']['title'] ) && 0 === strpos( $probe_row['payload']['title'], 'Admin note' ),
		$pass, $fail, $log );

	// ── [6] RENDER-AFTER-WRITE (full Order Detail page) ────────────
	echo "\n[6] Render-after-write — new entry surfaces in next render\n";
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $probe_key );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$post_html = ob_get_clean();
	ok( 'next render contains the probe note text in activity-log <ul>',
		preg_match( '/<ul class="eem-activity-log"[^>]*>.*' . preg_quote( $probe_text, '/' ) . '/s', $post_html ) === 1,
		$pass, $fail, $log );
	ok( 'next render contains an entry with --edit variant (ordernote → edit per C6.E.1 mapping)',
		preg_match( '/eem-activity-log-entry--edit[^>]*>.*?' . preg_quote( $probe_text, '/' ) . '/s', $post_html ) === 1,
		$pass, $fail, $log );
	ok( 'next render contains the "Admin note" title',
		str_contains( $post_html, 'Admin note' ),
		$pass, $fail, $log );

	// ── [7] Cap gate — non-manage_options user is rejected ────────
	echo "\n[7] Capability gate\n";
	$prev_user = get_current_user_id();
	// Create a low-cap user (subscriber) and re-invoke.
	$sub_id = wp_create_user( 'c6e2_subscriber_' . wp_generate_password( 6, false ), 'pw', 'sub_' . wp_generate_password( 4, false ) . '@probe.test' );
	if ( ! is_wp_error( $sub_id ) ) {
		( new WP_User( $sub_id ) )->set_role( 'subscriber' );
		wp_set_current_user( $sub_id );
		$r = $invoke( array(
			'order_key'           => $probe_key,
			'_eem_add_note_nonce' => $valid_nonce,
			'note'                => 'should not be written',
		) );
		ok( 'non-manage_options user → capability error',
			is_array( $r ) && empty( $r['success'] ) && isset( $r['data']['code'] ) && 'capability' === $r['data']['code'],
			$pass, $fail, $log );
		wp_set_current_user( $prev_user );
		wp_delete_user( $sub_id );
	} else {
		ok( 'cap gate test: subscriber created', false, $pass, $fail, $log, 'wp_create_user failed' );
	}

	// Self-clean probe entries.
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}en_activity_log WHERE payload LIKE %s", '%c6e2-probe-%' ) );
}

// ── [8] JS dispatch arm in admin.js ────────────────────────────────
echo "\n[8] JS dispatch arm + input listener\n";
$js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
ok( "'add-note-submit' arm string present in admin.js",          str_contains( $js, "'add-note-submit'" ),                              $pass, $fail, $log );
ok( 'submitAddNoteForm function defined',                         str_contains( $js, 'function submitAddNoteForm' ),                    $pass, $fail, $log );
ok( 'JS reads data-eem-add-note-textarea',                        str_contains( $js, 'data-eem-add-note-textarea' ),                    $pass, $fail, $log );
ok( 'JS handles error path (sets errEl.hidden=false)',            str_contains( $js, 'errEl.hidden = false' ),                          $pass, $fail, $log );
ok( 'JS disables button while in-flight',                         str_contains( $js, 'btn.disabled = true' ),                           $pass, $fail, $log );
ok( 'JS prepends html via insertAdjacentHTML afterbegin',         str_contains( $js, "insertAdjacentHTML('afterbegin'" ),               $pass, $fail, $log );
ok( 'JS bumps [data-eem-activity-count] badge',                   str_contains( $js, 'data-eem-activity-count' ) && str_contains( $js, 'new_count' ), $pass, $fail, $log );
ok( 'JS input listener disables button on empty textarea',        preg_match( "/data-eem-add-note-textarea.*btn\.disabled\s*=/s", $js ) === 1,   $pass, $fail, $log );
ok( 'JS toast confirm on success',                                str_contains( $js, 'showSaveToast' ) && str_contains( $js, 'Note added' ), $pass, $fail, $log );

// ── [9] CSS form chrome ─────────────────────────────────────────────
echo "\n[9] CSS form chrome\n";
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
ok( 'CSS: .eem-add-note-form',                                    false !== strpos( $css, '.eem-add-note-form' ),                       $pass, $fail, $log );
ok( 'CSS: .eem-add-note-form__textarea',                          false !== strpos( $css, '.eem-add-note-form__textarea' ),             $pass, $fail, $log );
ok( 'CSS: .eem-add-note-form__error',                             false !== strpos( $css, '.eem-add-note-form__error' ),                $pass, $fail, $log );
ok( 'CSS: .eem-add-note-form__actions',                           false !== strpos( $css, '.eem-add-note-form__actions' ),              $pass, $fail, $log );
ok( 'CSS: form hidden when activity section collapsed',           false !== strpos( $css, '.eem-order-activity.collapsed .eem-add-note-form' ), $pass, $fail, $log );

// ── [10] ordernote → edit variant (revalidation of C6.E.1 wiring) ──
echo "\n[10] ordernote → edit variant (carry-through from C6.E.1)\n";
ok( 'EEM_Order_Telemetry::filter_render_variant maps ordernote → edit',
	'edit' === EEM_Order_Telemetry::filter_render_variant( 'info', 'ordernote' ),
	$pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
