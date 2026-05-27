<?php
/**
 * C7.C.2.1 smoke — Stall + RV rules-body partials wired through the
 * Path A Reservation Editor.
 *
 *   [1]  Partials exist + page-class dispatch wires stall + rv
 *   [2]  Section bodies render real fields (NOT placeholders)
 *   [3]  Content-density per 5-section enumeration (stall + rv field
 *        names match the kickoff-locked table)
 *   [4]  Hidden enabled-mirror per section (Desync A/B regression for
 *        the two new wired sections)
 *   [5]  Available Reservation Dates rows render in BOTH stall and rv
 *        sections AND in checkin — same persisted meta keys
 *   [6a] CLEAN render-extract-post round-trip (stall description)
 *   [6b] CLEAN render-extract-post round-trip (rv description)
 *   [6c] TRIPWIRE stall schedule (enabled + empty open) → AJAX error +
 *        validation message + zero collateral persistence
 *   [6d] TRIPWIRE rv schedule (enabled + empty open) → same shape
 *   [7]  DOM-presence per C7.C.1.3 canon — chevron + icon-chip + svg
 *        glyphs render in stall + rv specifically (regression)
 *   [8]  C7.C.1 + C7.C.1.1 + C7.C.1.2 + C7.C.1.3 regressions still
 *        green (chrome, save bar, modal, 10 sections, 10 chevrons)
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7c21_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.C.2.1 SMOKE ===\n";

// AJAX-test plumbing (same shape as c7c1)
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new Exception( 'eem_test_die' ); };
} );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

// Pre-cleanup
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.C.2.1 Smoke' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}

$page_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$tpl_dir  = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/';

// ── [1] Partials exist + page-class dispatch ──────────────────────
echo "\n[1] Partials + page-class dispatch\n";
c7c21_ok( "partial exists: _section-stall.php", file_exists( $tpl_dir . '_section-stall.php' ), $pass, $fail, $log );
c7c21_ok( "partial exists: _section-rv.php",    file_exists( $tpl_dir . '_section-rv.php' ),    $pass, $fail, $log );
c7c21_ok( "page-class wired_map carries 'stall' => _section-stall.php",
	false !== strpos( $page_src, "'stall'       => '_section-stall.php'" ),
	$pass, $fail, $log );
c7c21_ok( "page-class wired_map carries 'rv'    => _section-rv.php",
	false !== strpos( $page_src, "'rv'          => '_section-rv.php'" ),
	$pass, $fail, $log );

// Seed reservation
wp_set_current_user( 1 );
$reservation_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => 'C7.C.2.1 Smoke Reservation ' . wp_generate_password( 6, false, false ),
) );
// Seed event source to a stable non-feed value so feed-URL validation
// doesn't trip during the round-trip scenarios.
update_post_meta( $reservation_id, '_en_event_source',          'native' );
update_post_meta( $reservation_id, '_en_use_global_event_source', 0 );
// Seed stall + rv content for content-density + round-trip asserts
update_post_meta( $reservation_id, '_en_stalls_enabled',          1 );
update_post_meta( $reservation_id, '_en_stall_description',       'Stall body smoke description' );
update_post_meta( $reservation_id, '_en_stall_nightly_enabled',   1 );
update_post_meta( $reservation_id, '_en_stall_weekend_enabled',   0 );
update_post_meta( $reservation_id, '_en_stall_nightly_rate',      18.00 );
update_post_meta( $reservation_id, '_en_stall_inventory',         40 );
update_post_meta( $reservation_id, '_en_required_shavings_enabled', 1 );
update_post_meta( $reservation_id, '_en_required_shavings_per_stall', 2 );
update_post_meta( $reservation_id, '_en_required_shavings_price', 22.00 );
update_post_meta( $reservation_id, '_en_rv_enabled',              1 );
update_post_meta( $reservation_id, '_en_rv_description',          'RV body smoke description' );
update_post_meta( $reservation_id, '_en_rv_nightly_enabled',      1 );
update_post_meta( $reservation_id, '_en_rv_nightly_rate',         25.00 );
update_post_meta( $reservation_id, '_en_rv_inventory',            12 );
update_post_meta( $reservation_id, '_en_available_start_date',    '2026-06-01' );
update_post_meta( $reservation_id, '_en_available_end_date',      '2026-06-05' );
// Avoid validation tripwires for the baseline render. get_meta_values()
// at line 1892 auto-coerces checkin_checkout_enabled back to 1 if ANY
// of checkin_time / checkout_time / checkin_time_enabled /
// checkout_time_enabled are non-empty — clear them ALL.
update_post_meta( $reservation_id, '_en_checkin_checkout_enabled', 0 );
update_post_meta( $reservation_id, '_en_checkin_time',             '' );
update_post_meta( $reservation_id, '_en_checkout_time',            '' );
update_post_meta( $reservation_id, '_en_checkin_time_enabled',     0 );
update_post_meta( $reservation_id, '_en_checkout_time_enabled',    0 );
update_post_meta( $reservation_id, '_en_stall_schedule_enabled',   0 );
update_post_meta( $reservation_id, '_en_stalls_open_at',           '' );
update_post_meta( $reservation_id, '_en_stalls_close_at',          '' );
update_post_meta( $reservation_id, '_en_rv_schedule_enabled',      0 );
update_post_meta( $reservation_id, '_en_rv_open_at',               '' );
update_post_meta( $reservation_id, '_en_rv_close_at',              '' );

$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $reservation_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$html = ob_get_clean();

// Structural slice helper (per c7c1 pattern)
$slice = function ( $haystack, $key ) {
	$o = strpos( $haystack, 'id="body-' . $key . '"' );
	if ( false === $o ) return '';
	$n = strpos( $haystack, 'id="card-', $o + 1 );
	return false === $n ? substr( $haystack, $o ) : substr( $haystack, $o, $n - $o );
};
$stall_body = $slice( $html, 'stall' );
$rv_body    = $slice( $html, 'rv' );

// ── [2] Section bodies are real (not placeholders) ────────────────
echo "\n[2] Wired bodies — real fields, NOT placeholder\n";
c7c21_ok( "stall body wired (contains en_reservation[stall_description], NOT placeholder)",
	false !== strpos( $stall_body, 'name="en_reservation[stall_description]"' )
		&& false === strpos( $stall_body, 'wires in a later C7 sub-chunk' ),
	$pass, $fail, $log );
c7c21_ok( "rv body wired (contains en_reservation[rv_description], NOT placeholder)",
	false !== strpos( $rv_body, 'name="en_reservation[rv_description]"' )
		&& false === strpos( $rv_body, 'wires in a later C7 sub-chunk' ),
	$pass, $fail, $log );

// ── [3] Content-density per 5-section enumeration ─────────────────
echo "\n[3] Content-density assertions per the locked 5-section enumeration\n";
$stall_expected = array(
	'en_reservation[stall_description]'             => 'stall description textarea',
	'en_reservation[stall_nightly_enabled]'         => 'stall stay-type Nightly toggle',
	'en_reservation[stall_weekend_enabled]'         => 'stall stay-type Weekend Rate toggle',
	'en_reservation[stall_weekend_package_start_date]' => 'stall weekend package start date',
	'en_reservation[stall_weekend_package_end_date]'   => 'stall weekend package end date',
	'en_reservation[stall_schedule_enabled]'        => 'stall schedule toggle',
	'en_reservation[stalls_open_at]'                => 'stalls open datetime',
	'en_reservation[stalls_close_at]'               => 'stalls close datetime',
	'en_reservation[stall_inventory]'               => 'stall inventory',
	'en_reservation[stall_nightly_rate]'            => 'stall nightly rate',
	'en_reservation[stall_weekend_rate]'            => 'stall weekend rate',
	'en_reservation[stall_early_bird_enabled]'      => 'stall early bird toggle',
	'en_reservation[stall_early_bird_cutoff]'       => 'stall early bird cutoff',
	'en_reservation[stall_early_bird_nightly_rate]' => 'stall early bird nightly rate',
	'en_reservation[stall_early_bird_weekend_rate]' => 'stall early bird weekend rate',
	'en_reservation[required_shavings_enabled]'     => 'required shavings toggle',
	'en_reservation[required_shavings_per_stall]'   => 'required shavings per stall',
	'en_reservation[required_shavings_price]'       => 'required shavings price',
);
foreach ( $stall_expected as $field => $label ) {
	c7c21_ok( "stall section emits {$label}",
		false !== strpos( $stall_body, 'name="' . $field . '"' ),
		$pass, $fail, $log );
}
$rv_expected = array(
	'en_reservation[rv_description]'             => 'rv description textarea',
	'en_reservation[rv_nightly_enabled]'         => 'rv stay-type Nightly toggle',
	'en_reservation[rv_weekend_enabled]'         => 'rv stay-type Weekend Rate toggle',
	'en_reservation[rv_weekend_package_start_date]' => 'rv weekend package start date',
	'en_reservation[rv_weekend_package_end_date]'   => 'rv weekend package end date',
	'en_reservation[rv_schedule_enabled]'        => 'rv schedule toggle',
	'en_reservation[rv_open_at]'                 => 'rv open datetime',
	'en_reservation[rv_close_at]'                => 'rv close datetime',
	'en_reservation[rv_inventory]'               => 'rv inventory',
	'en_reservation[rv_nightly_rate]'            => 'rv nightly rate',
	'en_reservation[rv_weekend_rate]'            => 'rv weekend rate',
	'en_reservation[rv_early_bird_enabled]'      => 'rv early bird toggle',
	'en_reservation[rv_early_bird_cutoff]'       => 'rv early bird cutoff',
	'en_reservation[rv_early_bird_nightly_rate]' => 'rv early bird nightly rate',
	'en_reservation[rv_early_bird_weekend_rate]' => 'rv early bird weekend rate',
);
foreach ( $rv_expected as $field => $label ) {
	c7c21_ok( "rv section emits {$label}",
		false !== strpos( $rv_body, 'name="' . $field . '"' ),
		$pass, $fail, $log );
}
// Seeded value pass-through
c7c21_ok( "stall description textarea carries the seeded value",
	false !== strpos( $stall_body, 'Stall body smoke description' ),
	$pass, $fail, $log );
c7c21_ok( "rv description textarea carries the seeded value",
	false !== strpos( $rv_body, 'RV body smoke description' ),
	$pass, $fail, $log );

// ── [4] Hidden enabled-mirror per section ─────────────────────────
echo "\n[4] Hidden enabled-mirror per section (Desync A/B regression)\n";
c7c21_ok( "stall body carries hidden mirror data-eem-section-enabled='stall'",
	(bool) preg_match( '/<input\s+type="hidden"\s+name="en_reservation\[stalls_enabled\]"\s+data-eem-section-enabled="stall"/', $stall_body ),
	$pass, $fail, $log );
c7c21_ok( "rv body carries hidden mirror data-eem-section-enabled='rv'",
	(bool) preg_match( '/<input\s+type="hidden"\s+name="en_reservation\[rv_enabled\]"\s+data-eem-section-enabled="rv"/', $rv_body ),
	$pass, $fail, $log );
// No visible inline checkbox for either section's enable toggle (C7.C.1.1 Decision E)
c7c21_ok( "no visible <input type='checkbox' name='en_reservation[stalls_enabled]'> (Desync A/B removed)",
	0 === preg_match( '/<input[^>]*type="checkbox"[^>]*name="en_reservation\[stalls_enabled\]"/', $stall_body ),
	$pass, $fail, $log );
c7c21_ok( "no visible <input type='checkbox' name='en_reservation[rv_enabled]'> (Desync A/B removed)",
	0 === preg_match( '/<input[^>]*type="checkbox"[^>]*name="en_reservation\[rv_enabled\]"/', $rv_body ),
	$pass, $fail, $log );

// ── [5] Available Reservation Dates render in BOTH stall + rv + checkin ─
echo "\n[5] Available Reservation Dates render in 3 sections (mockup-canon redundancy)\n";
$checkin_body = $slice( $html, 'checkin' );
// C7.C.1.4.A — checkin section dropped Available Reservation Dates row
// per mockup canon (lines 410-422: ONLY Check-In Time + Check-Out Time).
// Available dates render in stall + rv sections instead (mockup canon
// 502-511 + 676-685). Smoke assertion removed.
c7c21_ok( "stall section renders en_reservation[available_start_date]",
	false !== strpos( $stall_body, 'name="en_reservation[available_start_date]"' ),
	$pass, $fail, $log );
c7c21_ok( "rv section renders en_reservation[available_start_date]",
	false !== strpos( $rv_body, 'name="en_reservation[available_start_date]"' ),
	$pass, $fail, $log );

// ── [6] Render-extract-post canon ─────────────────────────────────
// Extractor (mirrors admin.js eemCollectEditorFields() — also mirrors
// the c7c1-smoke pattern: skip unchecked checkboxes + skip hidden
// section-enabled mirrors with value !== "1").
$extract = function ( $html ) {
	$flat = array();
	preg_match_all( '/<input\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>/i', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		$type    = preg_match( '/type="([^"]+)"/i', $row[0], $tm ) ? $tm[1] : 'text';
		$value   = preg_match( '/value="([^"]*)"/i', $row[0], $vm ) ? html_entity_decode( $vm[1], ENT_QUOTES, 'UTF-8' ) : '';
		$checked = (bool) preg_match( '/\bchecked\b/i', $row[0] );
		$is_section_enabled    = (bool) preg_match( '/data-eem-section-enabled="/i', $row[0] );
		$is_subsection_enabled = (bool) preg_match( '/data-eem-subsection-enabled="/i', $row[0] );
		$is_stay_type_mirror   = (bool) preg_match( '/data-eem-stay-type-mirror/i', $row[0] );
		if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $checked ) continue;
		if ( 'hidden' === $type && $is_section_enabled    && '1' !== $value ) continue;
		if ( 'hidden' === $type && $is_subsection_enabled && '1' !== $value ) continue;
		if ( 'hidden' === $type && $is_stay_type_mirror   && '1' !== $value ) continue;
		$flat[ $row[1] ] = $value;
	}
	preg_match_all( '/<select\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>(.*?)<\/select>/is', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		if ( preg_match( '/<option\s+[^>]*value="([^"]*)"[^>]*\bselected/i', $row[2], $sm ) ) { $flat[ $row[1] ] = $sm[1]; }
		elseif ( preg_match( '/<option\s+[^>]*value="([^"]*)"[^>]*>/i', $row[2], $sm ) )    { $flat[ $row[1] ] = $sm[1]; }
	}
	preg_match_all( '/<textarea\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>(.*?)<\/textarea>/is', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) { $flat[ $row[1] ] = html_entity_decode( $row[2], ENT_QUOTES, 'UTF-8' ); }
	$query = '';
	foreach ( $flat as $k => $v ) { $query .= ( '' === $query ? '' : '&' ) . urlencode( $k ) . '=' . urlencode( (string) $v ); }
	$parsed = array();
	parse_str( $query, $parsed );
	return isset( $parsed['en_reservation'] ) ? (array) $parsed['en_reservation'] : array();
};
$nonce = wp_create_nonce( 'eem_reservation_editor' );

// ── [6a] CLEAN stall round-trip — modify description, persist ─────
echo "\n[6a] CLEAN render-extract-post — stall description persists\n";
$payload = $extract( $html );
$payload['stall_description'] = 'CLEAN_STALL_UPDATE';
$payload['event_source'] = 'native';
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $reservation_id,
	'save_kind'         => 'save_draft',
	'en_reservation'    => $payload,
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c21_ok( "CLEAN stall: AJAX success === true",
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 200 ) );
c7c21_ok( "CLEAN stall: stall_description persisted via render-extract-post",
	'CLEAN_STALL_UPDATE' === get_post_meta( $reservation_id, '_en_stall_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_stall_description', true ) );

// ── [6b] CLEAN rv round-trip — modify description, persist ────────
echo "\n[6b] CLEAN render-extract-post — rv description persists\n";
// Re-render after [6a] save so the payload reflects current state
ob_start(); EEM_Reservation_Editor_Page::render(); $html2 = ob_get_clean();
$payload2 = $extract( $html2 );
$payload2['rv_description'] = 'CLEAN_RV_UPDATE';
$payload2['event_source'] = 'native';
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $reservation_id,
	'save_kind'         => 'save_draft',
	'en_reservation'    => $payload2,
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c21_ok( "CLEAN rv: AJAX success === true",
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 200 ) );
c7c21_ok( "CLEAN rv: rv_description persisted via render-extract-post",
	'CLEAN_RV_UPDATE' === get_post_meta( $reservation_id, '_en_rv_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_rv_description', true ) );

// ── [6c] TRIPWIRE stall — schedule enabled + empty open ───────────
echo "\n[6c] TRIPWIRE stall schedule — enabled + empty stalls_open_at\n";
update_post_meta( $reservation_id, '_en_stall_schedule_enabled', 1 );
update_post_meta( $reservation_id, '_en_stalls_open_at',         '' );
update_post_meta( $reservation_id, '_en_stalls_close_at',        '' );
update_post_meta( $reservation_id, '_en_stall_description',      'BEFORE_STALL_TRIP' );
ob_start(); EEM_Reservation_Editor_Page::render(); $html3 = ob_get_clean();
$trip_stall = $extract( $html3 );
$trip_stall['stall_description'] = 'SHOULD_NOT_PERSIST';
$trip_stall['event_source'] = 'native';
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $reservation_id,
	'save_kind'         => 'update',
	'en_reservation'    => $trip_stall,
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c21_ok( "TRIPWIRE stall: AJAX success === false",
	is_array( $resp ) && empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 250 ) );
c7c21_ok( "TRIPWIRE stall: response.data.message references stall open/close requirement",
	is_array( $resp ) && isset( $resp['data']['message'] )
		&& false !== stripos( $resp['data']['message'], 'stall open' ),
	$pass, $fail, $log,
	'got message: ' . ( $resp['data']['message'] ?? '<missing>' ) );
c7c21_ok( "TRIPWIRE stall: response.data.code === 'validation_failed'",
	is_array( $resp ) && isset( $resp['data']['code'] ) && 'validation_failed' === $resp['data']['code'],
	$pass, $fail, $log );
c7c21_ok( "TRIPWIRE stall: stall_description NOT collateral-changed (write phase aborted clean)",
	'BEFORE_STALL_TRIP' === get_post_meta( $reservation_id, '_en_stall_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_stall_description', true ) );

// ── [6d] TRIPWIRE rv — schedule enabled + empty open ──────────────
echo "\n[6d] TRIPWIRE rv schedule — enabled + empty rv_open_at\n";
// Clear stall tripwire AND the open/close fields so the get_meta_values
// coercion (line 1893) doesn't flip stall_schedule_enabled back to 1.
update_post_meta( $reservation_id, '_en_stall_schedule_enabled', 0 );
update_post_meta( $reservation_id, '_en_stalls_open_at',         '' );
update_post_meta( $reservation_id, '_en_stalls_close_at',        '' );
update_post_meta( $reservation_id, '_en_rv_schedule_enabled',    1 );
update_post_meta( $reservation_id, '_en_rv_open_at',             '' );
update_post_meta( $reservation_id, '_en_rv_close_at',            '' );
update_post_meta( $reservation_id, '_en_rv_description',         'BEFORE_RV_TRIP' );
ob_start(); EEM_Reservation_Editor_Page::render(); $html4 = ob_get_clean();
$trip_rv = $extract( $html4 );
$trip_rv['rv_description'] = 'SHOULD_NOT_PERSIST';
$trip_rv['event_source'] = 'native';
$_POST = $_REQUEST = array(
	'_eem_editor_nonce' => $nonce,
	'reservation_id'    => $reservation_id,
	'save_kind'         => 'update',
	'en_reservation'    => $trip_rv,
);
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c21_ok( "TRIPWIRE rv: AJAX success === false",
	is_array( $resp ) && empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 250 ) );
c7c21_ok( "TRIPWIRE rv: response.data.message references rv open/close requirement",
	is_array( $resp ) && isset( $resp['data']['message'] )
		&& false !== stripos( $resp['data']['message'], 'rv open' ),
	$pass, $fail, $log,
	'got message: ' . ( $resp['data']['message'] ?? '<missing>' ) );
c7c21_ok( "TRIPWIRE rv: rv_description NOT collateral-changed",
	'BEFORE_RV_TRIP' === get_post_meta( $reservation_id, '_en_rv_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_rv_description', true ) );

// ── [7] DOM-presence per C7.C.1.3 canon (chevron + icon-chip) ─────
echo "\n[7] DOM-presence regressions for stall + rv (C7.C.1.3 canon)\n";
// Chevron lives in the section HEADER (above body-X); slice the full
// CARD instead of body-X so the chevron + icon-chip markup is in scope.
$slice_card = function ( $haystack, $key ) {
	$o = strpos( $haystack, 'id="card-' . $key . '"' );
	if ( false === $o ) return '';
	$n = strpos( $haystack, 'id="card-', $o + 5 );
	return false === $n ? substr( $haystack, $o ) : substr( $haystack, $o, $n - $o );
};
$stall_card = $slice_card( $html, 'stall' );
$rv_card    = $slice_card( $html, 'rv' );
c7c21_ok( "stall card chevron carries <svg> with polyline path",
	(bool) preg_match( '/<div class="eem-section-chevron"[^>]*>\s*<svg[\s\S]*?<polyline/', $stall_card ),
	$pass, $fail, $log );
c7c21_ok( "rv card chevron carries <svg> with polyline path",
	(bool) preg_match( '/<div class="eem-section-chevron"[^>]*>\s*<svg[\s\S]*?<polyline/', $rv_card ),
	$pass, $fail, $log );
// Icon chip presence (Decision E tone map: stall=green, rv=purple)
c7c21_ok( "stall card icon-chip carries .eem-section-icon--green with non-empty <svg>",
	(bool) preg_match( '/id="card-stall"[\s\S]{0,1200}?<div class="eem-section-icon eem-section-icon--green"[^>]*><svg[^>]*>\s*<(rect|path|line|polyline|circle|polygon)/', $html ),
	$pass, $fail, $log );
c7c21_ok( "rv card icon-chip carries .eem-section-icon--purple with non-empty <svg>",
	(bool) preg_match( '/id="card-rv"[\s\S]{0,1200}?<div class="eem-section-icon eem-section-icon--purple"[^>]*><svg[^>]*>\s*<(rect|path|line|polyline|circle|polygon)/', $html ),
	$pass, $fail, $log );

// ── [8] C7.C.1.x regressions still green ──────────────────────────
echo "\n[8] C7.C.1.x regression guards\n";
c7c21_ok( "all 10 section cards still render (C7.B.1)",
	10 === substr_count( $html, '<section class="eem-card eem-reservation-editor-section' ),
	$pass, $fail, $log,
	'found: ' . substr_count( $html, '<section class="eem-card eem-reservation-editor-section' ) );
c7c21_ok( "rail Publish card renders (replaces retired .eem-save-bar)",
	false !== strpos( $html, '<span class="eem-rail-title">Publish</span>' ),
	$pass, $fail, $log );
c7c21_ok( "rail Linked Event card renders (replaces retired modal)",
	false !== strpos( $html, '<span class="eem-rail-title">Linked Event</span>' ),
	$pass, $fail, $log );
preg_match_all( '#<div class="eem-section-chevron"[^>]*>(.*?)</div>#s', $html, $cbods );
$chevrons_with_polyline = 0;
foreach ( $cbods[1] as $b ) { if ( false !== strpos( $b, '<svg' ) && false !== strpos( $b, 'polyline' ) ) { $chevrons_with_polyline++; } }
c7c21_ok( "10/10 section chevrons still carry SVG+polyline (C7.C.1.3 regression)",
	10 === $chevrons_with_polyline,
	$pass, $fail, $log,
	"chevrons: {$chevrons_with_polyline}" );
set_current_screen( 'admin_page_equine-event-manager-reservation-editor' );
c7c21_ok( "body classes still carry eem-shell-page--reservation-editor (DS-1.B.4)",
	false !== strpos( apply_filters( 'admin_body_class', '' ), 'eem-shell-page--reservation-editor' ),
	$pass, $fail, $log );

// Cleanup
wp_delete_post( $reservation_id, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
