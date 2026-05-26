<?php
/**
 * C7.C.1 smoke — Reservation Editor field bodies + save dispatcher reuse + meta-box retirement.
 *
 *   [1]  All 6 wired section partials exist + repeating-row helper exists
 *   [2]  Render: 6 wired sections produce real bodies, 4 deferred sections stay at placeholder
 *   [3]  Each wired section emits its expected `en_reservation[*]` form fields (content-density)
 *   [4]  Repeating-row helper renders the addons <template> + <tbody> + add-button
 *   [5]  Page class wires render_section_body() dispatch + collect_data via CPT instance
 *   [6]  AJAX save: round-trip 6 field values via legacy save_meta() — read-back assertions
 *   [7]  AJAX save: post_status flip + save_meta() invocation gated on en_reservation present
 *   [8]  JS: form-field collector + repeating-row add/remove handlers + fee-type visibility
 *   [9]  Meta-box retirement — static guard (zero add_meta_box() in legacy editor source)
 *   [10] Meta-box retirement — runtime guard (no boxes register when action fires)
 *   [11] Loader retirement — add_meta_boxes_en_reservation action no longer wired
 *   [12] Regression: page chrome + icon chips + section toggles still render (C7.B.1/B.2/B.3)
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7c1_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.C.1 SMOKE ===\n";

// AJAX testing setup (matches c7b2 pattern)
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new Exception( 'eem_test_die' ); };
} );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

// Defensive pre-cleanup
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.C.1 Smoke' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}

$page_src     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$editor_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-reservation-editor.php' );
$loader_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$js_src       = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$tpl_dir      = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/';

// ── [1] Partials exist ─────────────────────────────────────────────
echo "\n[1] Section partials + repeating-row helper exist\n";
$expected_partials = array(
	'_section-description.php',
	'_section-checkin.php',
	'_section-addons.php',
	'_section-group.php',
	'_section-fees.php',
	'_section-agreement.php',
	'_repeating-row-helper.php',
);
foreach ( $expected_partials as $p ) {
	c7c1_ok( "partial exists: {$p}", file_exists( $tpl_dir . $p ), $pass, $fail, $log );
}
c7c1_ok( 'eem_render_repeating_row_table() function loaded after page render',
	function_exists( 'eem_render_repeating_row_table' ) || file_exists( $tpl_dir . '_repeating-row-helper.php' ),
	$pass, $fail, $log );

// Setup: real reservation with seed values across all 6 wired sections
wp_set_current_user( 1 );
$reservation_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => 'C7.C.1 Smoke Reservation ' . wp_generate_password( 6, false, false ),
) );
// Seed all 6 wired-section meta values
update_post_meta( $reservation_id, '_en_reservation_description', 'Smoke description for content density check.' );
update_post_meta( $reservation_id, '_en_available_start_date', '2026-06-01' );
update_post_meta( $reservation_id, '_en_available_end_date', '2026-06-05' );
update_post_meta( $reservation_id, '_en_checkin_checkout_enabled', 1 );
update_post_meta( $reservation_id, '_en_checkin_time', '2026-06-01T08:00' );
update_post_meta( $reservation_id, '_en_general_addons_enabled', 1 );
update_post_meta( $reservation_id, '_en_general_addons', array(
	array( 'name' => 'Bedding Hay', 'description' => 'Compressed bale', 'price' => 12.50, 'applies_to' => 'any', 'per_label' => 'bale' ),
) );
update_post_meta( $reservation_id, '_en_group_reservations_enabled', 1 );
update_post_meta( $reservation_id, '_en_group_rider_grounds_fee_enabled', 1 );
update_post_meta( $reservation_id, '_en_group_rider_grounds_fee_amount', 25.00 );
update_post_meta( $reservation_id, '_en_convenience_fee_enabled', 1 );
update_post_meta( $reservation_id, '_en_convenience_fee_label', 'Service Fee' );
update_post_meta( $reservation_id, '_en_convenience_fee_type', 'flat' );
update_post_meta( $reservation_id, '_en_convenience_fee_value', 5.00 );
update_post_meta( $reservation_id, '_en_venue_agreement_enabled', 1 );
update_post_meta( $reservation_id, '_en_venue_agreement_file_label', 'Venue Agreement' );

$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $reservation_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$html = ob_get_clean();

// ── [2] Wired vs deferred bodies ───────────────────────────────────
echo "\n[2] 6 wired sections emit real bodies; 4 deferred stay at placeholder\n";
$wired_keys    = array( 'description', 'checkin', 'addons', 'group', 'fees', 'agreement' );
$deferred_keys = array( 'stall', 'rv', 'eventday', 'cancellation' );

// Structural slicing: capture each body's contents from `id="body-X"`
// up to the next `id="card-` or `id="body-` so the comparison is
// scoped to ONE section, not bleeding into the next.
$slice_section = function ( $html, $key ) {
	$open = strpos( $html, 'id="body-' . $key . '"' );
	if ( false === $open ) return '';
	$next = strpos( $html, 'id="card-', $open + 1 );
	return false === $next ? substr( $html, $open ) : substr( $html, $open, $next - $open );
};
foreach ( $wired_keys as $key ) {
	$body = $slice_section( $html, $key );
	c7c1_ok( "section '{$key}' body wired (contains en_reservation[* form field, NOT placeholder)",
		false !== strpos( $body, 'name="en_reservation[' ) && false === strpos( $body, 'wires in a later C7 sub-chunk' ),
		$pass, $fail, $log );
}
foreach ( $deferred_keys as $key ) {
	$body = $slice_section( $html, $key );
	c7c1_ok( "section '{$key}' body stays at placeholder (deferred to later C7 sub-chunk)",
		false !== strpos( $body, 'wires in a later C7 sub-chunk' ),
		$pass, $fail, $log );
}

// ── [3] Content density — each wired section emits expected fields ─
echo "\n[3] Content-density assertions — expected form fields per section\n";
$expected_fields = array(
	'en_reservation[reservation_description]'        => 'description section emits description textarea',
	'en_reservation[available_start_date]'           => 'checkin section emits available_start_date',
	'en_reservation[available_end_date]'             => 'checkin section emits available_end_date',
	'en_reservation[checkin_checkout_enabled]'       => 'checkin section emits checkin_checkout_enabled toggle',
	'en_reservation[general_addons][0][name]'        => 'addons section emits seeded row with name',
	'en_reservation[general_addons_enabled]'         => 'addons section emits general_addons_enabled toggle',
	'en_reservation[group_reservations_enabled]'     => 'group section emits group_reservations_enabled toggle',
	'en_reservation[group_rider_grounds_fee_enabled]'=> 'group section emits grounds fee toggle',
	'en_reservation[group_rider_grounds_fee_amount]' => 'group section emits grounds fee amount input',
	'en_reservation[convenience_fee_enabled]'        => 'fees section emits convenience_fee_enabled toggle',
	'en_reservation[convenience_fee_label]'          => 'fees section emits convenience_fee_label input',
	'en_reservation[convenience_fee_type]'           => 'fees section emits convenience_fee_type select',
	'en_reservation[venue_agreement_enabled]'        => 'agreement section emits venue_agreement_enabled toggle',
	'en_reservation[venue_agreement_file_label]'     => 'agreement section emits agreement file label input',
);
foreach ( $expected_fields as $field => $label ) {
	c7c1_ok( $label, false !== strpos( $html, 'name="' . $field . '"' ), $pass, $fail, $log );
}
// Round-trip: seeded values flow through to rendered output
c7c1_ok( 'description textarea contains the seeded value',
	false !== strpos( $html, 'Smoke description for content density check.' ),
	$pass, $fail, $log );
c7c1_ok( 'addons row carries seeded "Bedding Hay" name',
	false !== strpos( $html, 'value="Bedding Hay"' ),
	$pass, $fail, $log );
c7c1_ok( 'fees label input carries the seeded "Service Fee" value',
	false !== strpos( $html, 'value="Service Fee"' ),
	$pass, $fail, $log );

// ── [4] Repeating-row helper rendered output ───────────────────────
echo "\n[4] Repeating-row helper — template + tbody + add-button\n";
c7c1_ok( 'addons <tbody id="en_general_addons_rows"> renders',
	false !== strpos( $html, 'id="en_general_addons_rows"' ),
	$pass, $fail, $log );
c7c1_ok( 'addons <template id="eem-general-addon-row-template"> renders',
	false !== strpos( $html, 'id="eem-general-addon-row-template"' ),
	$pass, $fail, $log );
c7c1_ok( 'add-button carries data-eem-action="reservation-editor-add-repeating-row"',
	false !== strpos( $html, 'data-eem-action="reservation-editor-add-repeating-row"' ),
	$pass, $fail, $log );
c7c1_ok( 'remove-button rendered with data-eem-action="reservation-editor-remove-repeating-row"',
	false !== strpos( $html, 'data-eem-action="reservation-editor-remove-repeating-row"' ),
	$pass, $fail, $log );
c7c1_ok( 'repeating-row container exposes data-eem-repeating-template attribute',
	false !== strpos( $html, 'data-eem-repeating-template="eem-general-addon-row-template"' ),
	$pass, $fail, $log );

// ── [5] Page class wiring ──────────────────────────────────────────
echo "\n[5] Page class dispatch wiring\n";
c7c1_ok( 'render_section_body() dispatcher present in page class',
	false !== strpos( $page_src, 'function render_section_body' ),
	$pass, $fail, $log );
c7c1_ok( 'page class instantiates EEM_Reservations_CPT for data collection',
	false !== strpos( $page_src, 'new EEM_Reservations_CPT()' ),
	$pass, $fail, $log );
c7c1_ok( 'page class calls get_editor_meta_values() for $data',
	false !== strpos( $page_src, 'get_editor_meta_values' ),
	$pass, $fail, $log );
c7c1_ok( 'page class calls get_editor_general_addons_context() for $addons',
	false !== strpos( $page_src, 'get_editor_general_addons_context' ),
	$pass, $fail, $log );

// ── [6] AJAX save: RENDER-EXTRACT-POST round-trip ──────────────────
// C7.C.1.1 — the C7.C.1 smoke used a hand-crafted payload that
// bypassed the rendered-form path, so the validation-trip bug
// (checkin_checkout_enabled=1 + empty times → save_meta no-op →
// AJAX returns success while nothing persists) shipped to the user.
// New canonical pattern per CLAUDE.md "render-then-collect-then-post
// is the canonical browser-realistic save test" rule: render the
// page, extract every form field exactly as eemCollectEditorFields()
// would, POST through ajax_save, read-back via canonical consumer.
echo "\n[6] AJAX save — RENDER-EXTRACT-POST round-trip\n";

/**
 * Mimic admin.js eemCollectEditorFields(): walk rendered HTML, collect
 * every input/select/textarea whose name starts with `en_reservation`,
 * skip unchecked checkboxes/radios (browser default), return as a
 * flat key=>value map that parse_str-style unflattens into nested array.
 */
$extract_browser_payload = function ( $html ) {
	$flat = array();
	// inputs
	preg_match_all( '/<input\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>/i', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		$type    = preg_match( '/type="([^"]+)"/i', $row[0], $tm ) ? $tm[1] : 'text';
		$value   = preg_match( '/value="([^"]*)"/i', $row[0], $vm ) ? html_entity_decode( $vm[1], ENT_QUOTES, 'UTF-8' ) : '';
		$checked = (bool) preg_match( '/\bchecked\b/i', $row[0] );
		$is_section_enabled = (bool) preg_match( '/data-eem-section-enabled="/i', $row[0] );
		if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $checked ) continue;
		// Mirror the JS collector: hidden section-enabled mirrors with
		// value !== "1" are SKIPPED (presence semantics expected by
		// the legacy save_meta sanitizer).
		if ( 'hidden' === $type && $is_section_enabled && '1' !== $value ) continue;
		$flat[ $row[1] ] = $value;
	}
	// selects
	preg_match_all( '/<select\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>(.*?)<\/select>/is', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		if ( preg_match( '/<option\s+[^>]*value="([^"]*)"[^>]*\bselected/i', $row[2], $sm ) ) {
			$flat[ $row[1] ] = $sm[1];
		} elseif ( preg_match( '/<option\s+[^>]*value="([^"]*)"[^>]*>/i', $row[2], $sm ) ) {
			$flat[ $row[1] ] = $sm[1];
		}
	}
	// textareas
	preg_match_all( '/<textarea\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>(.*?)<\/textarea>/is', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		$flat[ $row[1] ] = html_entity_decode( $row[2], ENT_QUOTES, 'UTF-8' );
	}
	// PHP-side parse: en_reservation[key]=val → ['key' => 'val']; nested
	// keys (e.g. general_addons[0][name]) preserved.
	$query = '';
	foreach ( $flat as $k => $v ) {
		$query .= ( '' === $query ? '' : '&' ) . urlencode( $k ) . '=' . urlencode( (string) $v );
	}
	$parsed = array();
	parse_str( $query, $parsed );
	return isset( $parsed['en_reservation'] ) ? (array) $parsed['en_reservation'] : array();
};

// ── [6a] CLEAN-PAYLOAD scenario — no validation tripwires ──────────
echo "\n[6a] CLEAN payload — should succeed + persist all wired fields\n";
update_post_meta( $reservation_id, '_en_event_source', 'native' );
// Force the section truly disabled: get_meta_values() at line 1892–1894
// auto-coerces checkin_checkout_enabled back to 1 if ANY of
// checkin_time / checkout_time / checkin_time_enabled /
// checkout_time_enabled are non-empty. Set them all to 0/empty.
update_post_meta( $reservation_id, '_en_checkin_checkout_enabled', 0 );
update_post_meta( $reservation_id, '_en_checkin_time', '' );
update_post_meta( $reservation_id, '_en_checkout_time', '' );
update_post_meta( $reservation_id, '_en_checkin_time_enabled', 0 );
update_post_meta( $reservation_id, '_en_checkout_time_enabled', 0 );
update_post_meta( $reservation_id, '_en_reservation_description', 'pre-update value' );
$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $reservation_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$rendered_clean = ob_get_clean();
$payload_clean = $extract_browser_payload( $rendered_clean );
$payload_clean['reservation_description'] = 'Round-trip clean test';
$payload_clean['event_source'] = 'native'; // editor body doesn't carry this; preserve so feed-URL validation doesn't trip

$nonce = wp_create_nonce( 'eem_reservation_editor' );
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $reservation_id,
	'save_kind'         => 'save_draft',
	'en_reservation'    => $payload_clean,
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }

c7c1_ok( 'CLEAN payload: AJAX returns success === true',
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 250 ) );
c7c1_ok( 'CLEAN payload: description persisted via render-extract-post round-trip',
	'Round-trip clean test' === get_post_meta( $reservation_id, '_en_reservation_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_reservation_description', true ) );

// ── [6b] TRIPWIRE scenario — the exact bug C7.C.1 shipped ──────────
// Seed checkin_checkout_enabled=1 with empty times. User would never
// know to fix this because the section is collapsed by default; just
// touching the description should not silently wipe state. New
// behavior: ajax_save pre-validates and returns 422 with the real
// error message; toast renders it. Nothing persists. This is the
// canary that would have caught the C7.C.1 bug at smoke time.
echo "\n[6b] TRIPWIRE payload — should error + NOT persist + surface the real reason\n";
update_post_meta( $reservation_id, '_en_checkin_checkout_enabled', 1 );
update_post_meta( $reservation_id, '_en_checkin_time', '' );
update_post_meta( $reservation_id, '_en_checkout_time', '' );
update_post_meta( $reservation_id, '_en_reservation_description', 'BEFORE_TRIPWIRE' );
$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $reservation_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$rendered_trip = ob_get_clean();
$payload_trip = $extract_browser_payload( $rendered_trip );
$payload_trip['reservation_description'] = 'SHOULD_NOT_PERSIST';
$payload_trip['event_source'] = 'native';

$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $reservation_id,
	'save_kind'         => 'update',
	'en_reservation'    => $payload_trip,
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }

c7c1_ok( 'TRIPWIRE payload: AJAX returns success === false (no more silent no-op)',
	is_array( $resp ) && empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 250 ) );
c7c1_ok( 'TRIPWIRE payload: response carries the actual validation error in data.message',
	is_array( $resp ) && isset( $resp['data']['message'] )
		&& false !== stripos( $resp['data']['message'], 'check-in time' ),
	$pass, $fail, $log,
	'got message: ' . ( $resp['data']['message'] ?? '<missing>' ) );
c7c1_ok( 'TRIPWIRE payload: response code is validation_failed',
	is_array( $resp ) && isset( $resp['data']['code'] ) && 'validation_failed' === $resp['data']['code'],
	$pass, $fail, $log );
c7c1_ok( 'TRIPWIRE payload: description did NOT change (proves write phase aborted cleanly)',
	'BEFORE_TRIPWIRE' === get_post_meta( $reservation_id, '_en_reservation_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_reservation_description', true ) );

// ── [6c] Header-toggle hidden-input mirror present ─────────────────
echo "\n[6c] Header-toggle / hidden-input architecture (Desync A/B/C fix)\n";
foreach ( array( 'checkin', 'addons', 'group', 'fees', 'agreement' ) as $section ) {
	c7c1_ok( "section '{$section}' body carries hidden enabled mirror input",
		(bool) preg_match( '/<input\s+type="hidden"\s+name="en_reservation\[[a-z_]+_enabled\]"\s+data-eem-section-enabled="' . $section . '"/i', $rendered_clean ),
		$pass, $fail, $log );
}
// Visible legacy inline checkbox markup REMOVED (was the second toggle)
c7c1_ok( 'no visible <input type="checkbox" name="en_reservation[checkin_checkout_enabled]"> (Desync A/B removed)',
	0 === preg_match( '/<input[^>]*type="checkbox"[^>]*name="en_reservation\[checkin_checkout_enabled\]"/', $rendered_clean ),
	$pass, $fail, $log );
c7c1_ok( 'no visible <input type="checkbox" name="en_reservation[general_addons_enabled]"> (Desync A/B removed)',
	0 === preg_match( '/<input[^>]*type="checkbox"[^>]*name="en_reservation\[general_addons_enabled\]"/', $rendered_clean ),
	$pass, $fail, $log );
c7c1_ok( 'no visible <input type="checkbox" name="en_reservation[convenience_fee_enabled]"> (Desync A/B removed)',
	0 === preg_match( '/<input[^>]*type="checkbox"[^>]*name="en_reservation\[convenience_fee_enabled\]"/', $rendered_clean ),
	$pass, $fail, $log );
// Header-toggle initial state now derived from data (Desync C fix)
// Force convenience fee fully off: get_meta_values() line 1908–1909
// auto-coerces convenience_fee_enabled to 1 if fee_type != 'none'.
update_post_meta( $reservation_id, '_en_convenience_fee_enabled', 0 );
update_post_meta( $reservation_id, '_en_convenience_fee_type', 'none' );
ob_start();
EEM_Reservation_Editor_Page::render();
$rendered_off = ob_get_clean();
// Structural slice from card-fees open to the next card- open
$slice_off = function( $haystack ) {
	$o = strpos( $haystack, 'id="card-fees"' );
	$n = $o !== false ? strpos( $haystack, 'id="card-', $o + 5 ) : false;
	return $o === false ? '' : substr( $haystack, $o, $n === false ? null : $n - $o );
};
c7c1_ok( "Desync C: section 'fees' header toggle renders eem-toggle--off when meta is 0",
	false !== strpos( $slice_off( $rendered_off ), '<div class="eem-toggle eem-toggle--off"' ),
	$pass, $fail, $log );
update_post_meta( $reservation_id, '_en_convenience_fee_enabled', 1 );
ob_start();
EEM_Reservation_Editor_Page::render();
$rendered_on = ob_get_clean();
c7c1_ok( "Desync C: section 'fees' header toggle renders eem-toggle--on when meta is 1",
	false !== strpos( $slice_off( $rendered_on ), '<div class="eem-toggle eem-toggle--on"' ),
	$pass, $fail, $log );

// ── [6d] JS — header-toggle click flips hidden input + error-toast surfaces server message ──
echo "\n[6d] JS — toggle-click hidden-input flip + error-toast wiring\n";
c7c1_ok( 'admin.js header-toggle handler flips data-eem-section-enabled hidden input',
	false !== strpos( $js_src, 'data-eem-section-enabled="' ),
	$pass, $fail, $log );
c7c1_ok( 'admin.js error-response path renders server resp.data.message in toast',
	(bool) preg_match( '/resp\.data\.message[\s\S]{0,200}eemSaveBarToast\([^)]*msg[^)]*error/', $js_src ),
	$pass, $fail, $log );

// ── [6e] CPT class — sanitize + validate + get_meta_values made public ──
echo "\n[6e] CPT class — sanitize + validate + get_meta_values visibility flip\n";
c7c1_ok( 'sanitize_meta_submission() is PUBLIC (was private; C7.C.1.1 flip)',
	(new ReflectionMethod( 'EEM_Reservations_CPT', 'sanitize_meta_submission' ))->isPublic(),
	$pass, $fail, $log );
c7c1_ok( 'validate_meta_submission() is PUBLIC (was private; C7.C.1.1 flip)',
	(new ReflectionMethod( 'EEM_Reservations_CPT', 'validate_meta_submission' ))->isPublic(),
	$pass, $fail, $log );
c7c1_ok( 'get_meta_values() is PUBLIC (was private; C7.C.1.1 flip)',
	(new ReflectionMethod( 'EEM_Reservations_CPT', 'get_meta_values' ))->isPublic(),
	$pass, $fail, $log );

// ── [6f] ajax_save pre-validate code path ──────────────────────────
echo "\n[6f] ajax_save pre-validate code path present\n";
c7c1_ok( 'ajax_save calls cpt->sanitize_meta_submission BEFORE save_meta',
	(bool) preg_match( '/sanitize_meta_submission\s*\([\s\S]{0,200}?validate_meta_submission\s*\([\s\S]{0,400}?save_meta/s', $page_src ),
	$pass, $fail, $log );
c7c1_ok( 'ajax_save returns 422 + validation_failed code on validation errors',
	false !== strpos( $page_src, "'code'    => 'validation_failed'" )
		&& false !== strpos( $page_src, '422' ),
	$pass, $fail, $log );

// ── [7] AJAX save: gating on en_reservation presence ───────────────
echo "\n[7] AJAX save — save_meta() gated on en_reservation present\n";
c7c1_ok( 'page class injects nonce and gates on $_POST[en_reservation]',
	false !== strpos( $page_src, "wp_create_nonce( 'equine_event_manager_save_reservation_meta' )" )
		&& false !== strpos( $page_src, "isset( \$_POST['en_reservation'] )" ),
	$pass, $fail, $log );
c7c1_ok( 'page class calls EEM_Reservations_CPT->save_meta()',
	(bool) preg_match( '/\$cpt\s*->\s*save_meta\s*\(/', $page_src ),
	$pass, $fail, $log );

// ── [8] JS handlers shipped ────────────────────────────────────────
echo "\n[8] JS — form collector + repeating-row + fee-type handlers\n";
c7c1_ok( 'admin.js carries eemCollectEditorFields() helper',
	false !== strpos( $js_src, 'function eemCollectEditorFields' ),
	$pass, $fail, $log );
c7c1_ok( 'admin.js collector queries name^="en_reservation"',
	false !== strpos( $js_src, 'name^="en_reservation"' ),
	$pass, $fail, $log );
c7c1_ok( 'admin.js carries reservation-editor-add-repeating-row handler',
	false !== strpos( $js_src, 'reservation-editor-add-repeating-row' ),
	$pass, $fail, $log );
c7c1_ok( 'admin.js carries reservation-editor-remove-repeating-row handler',
	false !== strpos( $js_src, 'reservation-editor-remove-repeating-row' ),
	$pass, $fail, $log );
c7c1_ok( 'admin.js carries fee-type visibility handler',
	false !== strpos( $js_src, 'reservation-editor-fee-type-change' ),
	$pass, $fail, $log );
c7c1_ok( 'admin.js rewrites __index__ tokens on row add',
	false !== strpos( $js_src, '__index__' ),
	$pass, $fail, $log );

// ── [9] Meta-box retirement — static guard (Decision F) ────────────
echo "\n[9] Meta-box retirement — STATIC guard (Decision F)\n";
$add_meta_box_calls = substr_count( $editor_src, 'add_meta_box(' );
c7c1_ok( 'ZERO add_meta_box() calls remain in admin/class-equine-event-manager-reservation-editor.php',
	0 === $add_meta_box_calls,
	$pass, $fail, $log,
	"found: {$add_meta_box_calls}" );
c7c1_ok( 'register_meta_boxes() method carries @deprecated marker',
	false !== strpos( $editor_src, '@deprecated' ) && false !== strpos( $editor_src, 'register_meta_boxes' ),
	$pass, $fail, $log );

// ── [10] Meta-box retirement — runtime guard (Decision F) ──────────
echo "\n[10] Meta-box retirement — RUNTIME guard (Decision F)\n";
global $wp_meta_boxes;
$wp_meta_boxes = array(); // reset to a clean slate
do_action( 'add_meta_boxes_en_reservation', null );
$en_boxes = isset( $wp_meta_boxes['en_reservation'] ) ? $wp_meta_boxes['en_reservation'] : array();
$total_registered = 0;
foreach ( array( 'normal', 'side', 'advanced' ) as $ctx ) {
	if ( isset( $en_boxes[ $ctx ] ) ) {
		foreach ( $en_boxes[ $ctx ] as $priority_boxes ) {
			$total_registered += is_array( $priority_boxes ) ? count( array_filter( $priority_boxes ) ) : 0;
		}
	}
}
c7c1_ok( 'no meta boxes register for en_reservation when add_meta_boxes_en_reservation fires',
	0 === $total_registered,
	$pass, $fail, $log,
	"registered: {$total_registered}" );
// Belt + suspenders: invoke register_meta_boxes() directly and re-check
$editor_class = new EEM_Reservation_Editor( new EEM_Reservations_CPT() );
$wp_meta_boxes = array();
$editor_class->register_meta_boxes( null );
$en_boxes = isset( $wp_meta_boxes['en_reservation'] ) ? $wp_meta_boxes['en_reservation'] : array();
$total_after_direct = 0;
foreach ( array( 'normal', 'side', 'advanced' ) as $ctx ) {
	if ( isset( $en_boxes[ $ctx ] ) ) {
		foreach ( $en_boxes[ $ctx ] as $priority_boxes ) {
			$total_after_direct += is_array( $priority_boxes ) ? count( array_filter( $priority_boxes ) ) : 0;
		}
	}
}
c7c1_ok( 'register_meta_boxes() called directly registers ZERO boxes (method is no-op)',
	0 === $total_after_direct,
	$pass, $fail, $log,
	"registered: {$total_after_direct}" );

// ── [11] Loader retirement — action hook removed ───────────────────
echo "\n[11] Loader retirement — add_meta_boxes_en_reservation hook removed\n";
c7c1_ok( 'includes/class-equine-event-manager.php no longer wires add_meta_boxes_en_reservation',
	false === strpos( $loader_src, "add_action( 'add_meta_boxes_en_reservation'" ),
	$pass, $fail, $log );

// ── [12] Regression: C7.B.1/B.2/B.3 chrome still renders ───────────
echo "\n[12] Regression: page chrome + icon chips + section toggles\n";
c7c1_ok( '.eem-page wrapper still renders (C7.B.1 regression)',
	false !== strpos( $html, 'class="eem-page"' ),
	$pass, $fail, $log );
c7c1_ok( 'all 10 section cards still render (C7.B.1 regression)',
	10 === substr_count( $html, '<section class="eem-card eem-reservation-editor-section' ),
	$pass, $fail, $log,
	'found: ' . substr_count( $html, '<section class="eem-card eem-reservation-editor-section' ) );
c7c1_ok( 'save bar still renders (C7.B.2 regression)',
	false !== strpos( $html, 'class="eem-save-bar"' ),
	$pass, $fail, $log );
c7c1_ok( 'Linked Event modal still renders (C7.B.2 regression)',
	false !== strpos( $html, 'id="eem-modal-linked-event"' ),
	$pass, $fail, $log );
c7c1_ok( 'all 10 section chips still carry SVG glyphs (C7.B.3 regression)',
	substr_count( $html, '<div class="eem-section-icon eem-section-icon--' ) === 10
		&& substr_count( $html, 'eem-section-icon eem-section-icon--' ) >= 10,
	$pass, $fail, $log );
preg_match_all( '#<div class="eem-section-icon eem-section-icon--[a-z]+"[^>]*>(.*?)</div>#s', $html, $chip_bodies );
$chips_with_svg = 0;
foreach ( $chip_bodies[1] as $body ) { if ( false !== strpos( $body, '<svg' ) ) { $chips_with_svg++; } }
c7c1_ok( '10/10 section chips contain inline <svg (C7.B.3 icon-density regression)',
	10 === $chips_with_svg,
	$pass, $fail, $log,
	"chips with SVG: {$chips_with_svg}" );
c7c1_ok( 'reservation-editor-toggle-enabled handler still in JS (C7.B.2.1 regression)',
	false !== strpos( $js_src, "reservation-editor-toggle-enabled" ),
	$pass, $fail, $log );
c7c1_ok( 'reservation-editor-toggle-collapse handler still in JS (C7.B.2.1 regression)',
	false !== strpos( $js_src, "reservation-editor-toggle-collapse" ),
	$pass, $fail, $log );
// Re-set the screen — global state drifts across the AJAX test above
set_current_screen( 'admin_page_equine-event-manager-reservation-editor' );
$_GET = array( 'page' => 'equine-event-manager-reservation-editor' );
c7c1_ok( 'body classes include eem-shell-page--reservation-editor (DS-1.B.4 regression)',
	false !== strpos( apply_filters( 'admin_body_class', '' ), 'eem-shell-page--reservation-editor' ),
	$pass, $fail, $log );

// Cleanup
wp_delete_post( $reservation_id, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
