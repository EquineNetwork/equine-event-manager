<?php
/**
 * C7.B.2 smoke — Save bar + Linked Event modal + AJAX dispatchers.
 *
 *   [1]  Class methods + AJAX action registrations
 *   [2]  Save bar partial renders for draft post (Cancel + Save Draft + Publish)
 *   [3]  Save bar partial renders for published post (Cancel + Update only)
 *   [4]  Save bar partial accepts $args (Decision J — Order Detail reuse contract)
 *   [5]  Save bar carries the AJAX nonce input
 *   [6]  Save bar sticky positioning + navy band CSS shipped
 *   [7]  Linked Event modal partial renders (modal markup, hidden by default)
 *   [8]  Source-mode picker has all 3 options (native / tec / feed)
 *   [9]  Source pickers (3) render with correct data-* attributes
 *   [10] Meta-line "(change linked event)" promoted from placeholder to launcher anchor
 *   [11] AJAX nonce action shared per Decision I
 *   [12] AJAX dispatcher: save_kind validation
 *   [13] AJAX dispatcher: post_status flip Draft → Publish
 *   [14] AJAX dispatcher: change-linked-event rejects empty event_id
 *   [15] AJAX dispatcher: change-linked-event rejects unknown source
 *   [16] AJAX dispatcher: change-linked-event happy path returns refreshed meta_line_html
 *   [17] Anchor umbrellas on save bar btn classes
 *   [18] C7.B.1 regression — page chrome + 10 section cards still render
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7b2_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.B.2 SMOKE ===\n";

// AJAX testing: replace default wp_die handler so wp_send_json_*
// throws Exception instead of terminating the script. Matches
// the WP_Ajax_UnitTestCase pattern.
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new Exception( 'eem_test_die' ); };
} );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

// Defensive pre-cleanup for crash-leftover test reservations
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.B.2 Smoke' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}

$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$loader_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$css_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

// ── [1] Class methods + AJAX registrations ─────────────────────────
echo "\n[1] Class methods + AJAX registrations\n";
c7b2_ok( 'EEM_Reservation_Editor_Page::ajax_save method exists',
	method_exists( 'EEM_Reservation_Editor_Page', 'ajax_save' ),
	$pass, $fail, $log );
c7b2_ok( 'EEM_Reservation_Editor_Page::ajax_change_linked_event method exists',
	method_exists( 'EEM_Reservation_Editor_Page', 'ajax_change_linked_event' ),
	$pass, $fail, $log );
c7b2_ok( 'loader registers wp_ajax_eem_reservation_editor_save',
	str_contains( $loader_src, "wp_ajax_eem_reservation_editor_save" ),
	$pass, $fail, $log );
c7b2_ok( 'loader registers wp_ajax_eem_reservation_editor_change_linked_event',
	str_contains( $loader_src, "wp_ajax_eem_reservation_editor_change_linked_event" ),
	$pass, $fail, $log );

// Setup a draft + a published reservation for render checks
wp_set_current_user( 1 );
$draft_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => 'C7.B.2 Smoke Draft ' . wp_generate_password( 6, false, false ),
) );
$pub_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.B.2 Smoke Published ' . wp_generate_password( 6, false, false ),
) );

// Render draft reservation
$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $draft_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$draft_html = ob_get_clean();

// Render published reservation
$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $pub_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$pub_html = ob_get_clean();

// ── [2] Save bar — draft post buttons ──────────────────────────────
echo "\n[2] Save bar — draft post buttons\n";
c7b2_ok( 'draft render contains .eem-save-bar',
	str_contains( $draft_html, 'class="eem-save-bar"' ),
	$pass, $fail, $log );
// C7.B.2.2: save-bar buttons consolidated onto existing C1.2 .eem-btn-*
// variants (Cancel + Save Draft = .eem-btn-secondary; Publish/Update =
// .eem-btn-primary). Custom .eem-btn-savebar-* variants removed.
c7b2_ok( 'draft render contains Cancel button (data-eem-action)',
	str_contains( $draft_html, 'data-eem-action="reservation-editor-cancel"' ),
	$pass, $fail, $log );
c7b2_ok( 'draft render contains Save Draft button (data-eem-action + label)',
	str_contains( $draft_html, 'data-eem-action="reservation-editor-save-draft"' ) && str_contains( $draft_html, 'Save Draft' ),
	$pass, $fail, $log );
c7b2_ok( 'draft render contains Publish button (data-eem-action + label)',
	str_contains( $draft_html, 'data-eem-action="reservation-editor-publish"' )
		&& (bool) preg_match( '/data-eem-action="reservation-editor-publish"[^>]*>\s*Publish\s*<\/button>/s', $draft_html ),
	$pass, $fail, $log );
c7b2_ok( 'draft render does NOT contain Update button data-eem-action',
	false === strpos( $draft_html, 'data-eem-action="reservation-editor-update"' ),
	$pass, $fail, $log );
c7b2_ok( 'C7.B.2.2 — no leftover .eem-btn-savebar-* custom variants in render (consolidated onto C1.2 .eem-btn-*)',
	0 === preg_match( '/eem-btn-savebar-/', $draft_html ),
	$pass, $fail, $log );

// ── [3] Save bar — published post buttons ──────────────────────────
echo "\n[3] Save bar — published post buttons\n";
c7b2_ok( 'published render contains Cancel + Update buttons only',
	str_contains( $pub_html, 'data-eem-action="reservation-editor-cancel"' )
		&& str_contains( $pub_html, 'data-eem-action="reservation-editor-update"' )
		&& false === strpos( $pub_html, 'data-eem-action="reservation-editor-save-draft"' )
		&& false === strpos( $pub_html, 'data-eem-action="reservation-editor-publish"' ),
	$pass, $fail, $log );
c7b2_ok( 'published render shows "Update" label',
	(bool) preg_match( '/data-eem-action="reservation-editor-update"[^>]*>\s*Update\s*<\/button>/s', $pub_html ),
	$pass, $fail, $log );

// ── [4] Save bar partial accepts $args (Decision J) ─────────────────
echo "\n[4] Save bar partial accepts \$args (Decision J — Order Detail reuse)\n";
$args = array(
	'cancel_label'   => 'Custom Cancel',
	'primary_action' => 'update',
	'update_label'   => 'Custom Update',
);
ob_start();
require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_save-bar.php';
$custom_html = ob_get_clean();
c7b2_ok( 'partial honors custom cancel_label',  str_contains( $custom_html, 'Custom Cancel' ),  $pass, $fail, $log );
c7b2_ok( 'partial honors custom update_label',  str_contains( $custom_html, 'Custom Update' ),  $pass, $fail, $log );
c7b2_ok( 'partial honors primary_action=update', str_contains( $custom_html, 'data-eem-action="reservation-editor-update"' ) && false === strpos( $custom_html, 'data-eem-action="reservation-editor-save-draft"' ), $pass, $fail, $log );

// ── [5] Save bar carries AJAX nonce input ──────────────────────────
echo "\n[5] AJAX nonce wired into save bar\n";
c7b2_ok( 'save bar carries _eem_editor_nonce input',
	(bool) preg_match( '/<input[^>]+name="_eem_editor_nonce"[^>]+value="[a-f0-9]{10}"/', $draft_html ),
	$pass, $fail, $log );

// ── [6] Save bar CSS — fixed-bottom (C7.B.2.1) + navy band ──────────
echo "\n[6] Save bar CSS — fixed-bottom (C7.B.2.1) + navy band\n";
c7b2_ok( '.eem-save-bar uses position:fixed bottom:0 (C7.B.2.1 — switched from sticky)',
	(bool) preg_match( '/\.eem-save-bar\s*\{[^}]*position\s*:\s*fixed[^}]*bottom\s*:\s*0/s', $css_src ),
	$pass, $fail, $log );
c7b2_ok( '.eem-save-bar uses WHITE background (C7.B.2.2 — corrected mockup drift; mockup line 275 sticky-save is white not navy)',
	(bool) preg_match( '/\.eem-save-bar\s*\{[^}]*background\s*:\s*var\(\s*--eem-surface\s*\)/s', $css_src ),
	$pass, $fail, $log );
c7b2_ok( '.eem-save-bar carries 1px light gray top border (mockup line 275)',
	(bool) preg_match( '/\.eem-save-bar\s*\{[^}]*border-top\s*:\s*1px\s+solid\s+#dcdcde/s', $css_src ),
	$pass, $fail, $log );
c7b2_ok( '.eem-save-bar no longer uses navy background (C7.B.2 drift removed)',
	0 === preg_match( '/\.eem-save-bar\s*\{[^}]*background\s*:\s*var\(\s*--eem-navy\s*\)/s', $css_src ),
	$pass, $fail, $log );
c7b2_ok( '.eem-reservation-editor-body has padding-bottom for fixed save bar clearance (C7.B.2.1)',
	(bool) preg_match( '/\.eem-reservation-editor-body\s*\{\s*padding-bottom\s*:\s*\d+px/', $css_src ),
	$pass, $fail, $log );
c7b2_ok( 'JS enable-toggle handler runs BEFORE collapse handler (C7.B.2.1 handler-ordering fix)',
	(bool) preg_match( '/var enable = t\.closest\([^)]+reservation-editor-toggle-enabled[^)]+\);\s*if\s*\(\s*enable\s*\)\s*\{[\s\S]+?var collapse = t\.closest\([^)]+reservation-editor-toggle-collapse/', $js_src ),
	$pass, $fail, $log );

// ── [7] Linked Event modal partial renders ─────────────────────────
echo "\n[7] Linked Event modal partial renders\n";
c7b2_ok( 'modal markup id="eem-modal-linked-event" present',
	str_contains( $draft_html, 'id="eem-modal-linked-event"' ),
	$pass, $fail, $log );
c7b2_ok( 'modal uses .eem-modal chrome (C1.4 primitive reuse)',
	(bool) preg_match( '/<div class="eem-modal"[^>]*id="eem-modal-linked-event"/', $draft_html ),
	$pass, $fail, $log );
c7b2_ok( 'modal NOT in open state by default (no .eem-modal.open or .open class on modal)',
	0 === preg_match( '/<div class="eem-modal open"[^>]*id="eem-modal-linked-event"/', $draft_html ),
	$pass, $fail, $log );

// ── [8] Source-mode picker has 3 options ────────────────────────────
echo "\n[8] Source-mode picker — 3 options\n";
c7b2_ok( "source-mode option 'native' present",
	str_contains( $draft_html, 'data-eem-source="native"' ),
	$pass, $fail, $log );
c7b2_ok( "source-mode option 'tec' present",
	str_contains( $draft_html, 'data-eem-source="tec"' ),
	$pass, $fail, $log );
c7b2_ok( "source-mode option 'feed' present",
	str_contains( $draft_html, 'data-eem-source="feed"' ),
	$pass, $fail, $log );

// ── [9] Source pickers (per-source bodies) ──────────────────────────
echo "\n[9] Source pickers — 3 body containers\n";
c7b2_ok( 'native picker container present',
	str_contains( $draft_html, 'data-eem-source-picker="native"' ),
	$pass, $fail, $log );
c7b2_ok( 'tec picker container present',
	str_contains( $draft_html, 'data-eem-source-picker="tec"' ),
	$pass, $fail, $log );
c7b2_ok( 'feed picker container has URL input',
	str_contains( $draft_html, 'data-eem-source-picker="feed"' )
		&& str_contains( $draft_html, 'eem-modal-linked-event__feed-url' ),
	$pass, $fail, $log );

// ── [10] Meta-line "(change)" promoted from placeholder ─────────────
echo "\n[10] Meta-line change-link promoted from placeholder\n";
c7b2_ok( 'meta-line carries the launcher anchor with data-eem-action',
	str_contains( $draft_html, 'data-eem-action="reservation-editor-launch-linked-event-modal"' ),
	$pass, $fail, $log );
c7b2_ok( 'meta-line link carries current-source data attr',
	(bool) preg_match( '/data-eem-current-source="(native|tec|feed)"/', $draft_html ),
	$pass, $fail, $log );
c7b2_ok( 'meta-line NO LONGER carries the C7.B.1 disabled placeholder class',
	0 === preg_match( '/eem-reservation-editor-meta-change-placeholder/', $draft_html ),
	$pass, $fail, $log );

// ── [11] Single shared nonce action (Decision I) ────────────────────
echo "\n[11] AJAX nonce action shared (Decision I)\n";
c7b2_ok( 'controller uses single nonce action eem_reservation_editor',
	substr_count( $admin_src, "'eem_reservation_editor'" ) >= 2,
	$pass, $fail, $log,
	'expected references in both ajax_save + ajax_change_linked_event' );

// ── [12] AJAX save dispatcher — save_kind validation ────────────────
echo "\n[12] AJAX save dispatcher — save_kind validation\n";
$nonce = wp_create_nonce( 'eem_reservation_editor' );
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $draft_id,
	'save_kind'         => 'bogus',
);
try {
	ob_start();
	EEM_Reservation_Editor_Page::ajax_save();
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
} catch ( Exception $e ) {
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
}
c7b2_ok( "unknown save_kind rejected (success === false)",
	is_array( $resp ) && empty( $resp['success'] ),
	$pass, $fail, $log );

// ── [13] AJAX save — Draft → Publish flip ──────────────────────────
echo "\n[13] AJAX save dispatcher — post_status flip Draft → Publish\n";
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $draft_id,
	'save_kind'         => 'publish',
);
try {
	ob_start();
	EEM_Reservation_Editor_Page::ajax_save();
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
} catch ( Exception $e ) {
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
}
c7b2_ok( 'publish response success === true',
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log );
c7b2_ok( 'post_status flipped to publish in DB',
	'publish' === get_post_status( $draft_id ),
	$pass, $fail, $log );
c7b2_ok( "response primary_action == 'update' (button-label switch contract)",
	is_array( $resp ) && isset( $resp['data']['primary_action'] ) && 'update' === $resp['data']['primary_action'],
	$pass, $fail, $log );

// ── [14] Change-linked-event rejects empty event_id ─────────────────
echo "\n[14] AJAX change-linked-event — rejects empty event_id\n";
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $draft_id,
	'source'            => 'native',
	'event_id'          => '',
);
try {
	ob_start();
	EEM_Reservation_Editor_Page::ajax_change_linked_event();
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
} catch ( Exception $e ) {
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
}
c7b2_ok( 'empty event_id rejected (success === false)',
	is_array( $resp ) && empty( $resp['success'] ),
	$pass, $fail, $log );

// ── [15] Change-linked-event rejects unknown source ─────────────────
echo "\n[15] AJAX change-linked-event — rejects unknown source\n";
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $draft_id,
	'source'            => 'garbage',
	'event_id'          => '42',
);
try {
	ob_start();
	EEM_Reservation_Editor_Page::ajax_change_linked_event();
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
} catch ( Exception $e ) {
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
}
c7b2_ok( 'unknown source rejected (success === false)',
	is_array( $resp ) && empty( $resp['success'] ),
	$pass, $fail, $log );

// ── [16] Change-linked-event happy path ─────────────────────────────
echo "\n[16] AJAX change-linked-event — happy path\n";
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $draft_id,
	'source'            => 'native',
	'event_id'          => '42',
);
try {
	ob_start();
	EEM_Reservation_Editor_Page::ajax_change_linked_event();
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
} catch ( Exception $e ) {
	$resp_raw = ob_get_clean();
	$resp = json_decode( $resp_raw, true );
}
c7b2_ok( 'happy-path response success === true',
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log );
c7b2_ok( "_en_event_source meta written as 'native'",
	'native' === (string) get_post_meta( $draft_id, '_en_event_source', true ),
	$pass, $fail, $log );
c7b2_ok( '_en_event_id meta written as 42',
	42 === (int) get_post_meta( $draft_id, '_en_event_id', true ),
	$pass, $fail, $log );
c7b2_ok( 'response carries refreshed meta_line_html (Decision K — DOM replacement)',
	is_array( $resp ) && isset( $resp['data']['meta_line_html'] )
		&& str_contains( (string) $resp['data']['meta_line_html'], 'eem-reservation-editor-meta-line' ),
	$pass, $fail, $log );

// ── [17] Anchor umbrellas on save-bar btn classes ──────────────────
echo "\n[17] Anchor umbrellas on save-bar btn classes (DS-1.A.1 discipline)\n";
c7b2_ok( 'C7.B.2.2 — save bar Cancel anchor reuses C1.2 .eem-btn-secondary (umbrella already shipped in C1.2)',
	(bool) preg_match( '/a\.eem-btn-secondary,\s*\n?\s*a\.eem-btn-secondary:link[\s\S]{0,400}?text-decoration\s*:\s*none/s', $css_src )
		|| (bool) preg_match( '/\.eem-btn-secondary,\s*\n?\s*a\.eem-btn-secondary\s*\{/s', $css_src ),
	$pass, $fail, $log );
c7b2_ok( 'a.eem-reservation-editor-meta-change-link umbrella shipped',
	(bool) preg_match( '/a\.eem-reservation-editor-meta-change-link:hover[\s\S]{0,400}?text-decoration\s*:\s*none/s', $css_src ),
	$pass, $fail, $log );

// ── [18] C7.B.1 regression — page chrome + sections still render ────
echo "\n[18] C7.B.1 regression guards\n";
c7b2_ok( '.eem-reservation-editor-body wrapper still renders',
	str_contains( $draft_html, 'eem-reservation-editor-body' ),
	$pass, $fail, $log );
c7b2_ok( 'meta-line still renders',
	str_contains( $draft_html, 'eem-reservation-editor-meta-line' ),
	$pass, $fail, $log );
foreach ( array( 'description', 'checkin', 'eventday', 'stall', 'rv', 'addons', 'group', 'fees', 'agreement', 'cancellation' ) as $key ) {
	c7b2_ok( "section card id='card-{$key}' still renders",
		str_contains( $draft_html, 'id="card-' . $key . '"' ),
		$pass, $fail, $log );
}
c7b2_ok( 'C7.B.1 placeholder strip REMOVED (replaced by real save bar)',
	false === strpos( $draft_html, 'eem-reservation-editor-savebar-placeholder' ),
	$pass, $fail, $log );

// Cleanup
wp_delete_post( $draft_id, true );
wp_delete_post( $pub_id, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
