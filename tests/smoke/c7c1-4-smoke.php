<?php
/**
 * C7.C.1.4.A smoke — mockup-canonical chrome retroactive port (4 sections).
 *
 * Applies the new "Mockup Walkthrough Pre-Audit" discipline as automated
 * assertions. For each of the 4 .A sections (description, checkin, group,
 * fees), the smoke walks the walkthrough enumeration columns and asserts:
 *   - control type (NO native checkbox where mockup specifies toggle)
 *   - mockup-canonical CSS class present per element
 *   - initial state respects persisted meta
 *   - conditional-visibility rows carry eem-row--hidden when controller off
 *   - hint copy verbatim from mockup
 *   - inline style attrs where mockup specifies (max-width:260px, 120px)
 *
 *   [1]  Shared partial helpers exist (_partial-field-row, _partial-toggle-label-row)
 *   [2]  Section-skeleton supports disabled_note + intro_hint_html + dynamic enable-label
 *   [3]  All 4 sections render with .eem-field-row grid (NOT <table class="form-table">)
 *   [4]  Description section: textarea has .eem-field-textarea + label-sub
 *   [5]  Checkin section: 2 datetime rows + .eem-field-input + max-width:260px
 *   [6]  Group section: 2 toggle-label-row + 2 conditional rows + new meta keys + disabled-note
 *   [7]  Fees section: fee-mode pill triplet + conditional flat/pct rows + percentage with .eem-pct-symbol on RIGHT
 *   [8]  Enable-label dynamic text ("Enabled" / "Disabled") per state
 *   [9]  CSS primitives shipped: .eem-toggle-label-row, .eem-row--hidden, .eem-section-disabled-note, .eem-fee-modes, .eem-pct-wrap
 *   [10] JS: applyControls() + reservation-editor-toggle-subsection + reservation-editor-fee-mode + subsection-enabled hidden-input skip
 *   [11] Render-extract-post round-trip per section (1 representative field each)
 *   [12] addons collapsed-by-default fix in section_definitions
 *   [13] CPT class new meta keys for group_description + group_riders_per_group
 *   [14] No native <input type="checkbox" name="en_reservation[X_enabled]"> for ANY sub-section toggle
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7c14_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.C.1.4.A SMOKE ===\n";

add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new Exception( 'eem_test_die' ); };
} );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.C.1.4 Smoke' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}

$tpl_dir   = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/';
$css_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$page_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$cpt_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );

// ── [1] Shared partial helpers exist ──────────────────────────────
echo "\n[1] Shared partial helpers\n";
c7c14_ok( 'partial exists: _partial-field-row.php',         file_exists( $tpl_dir . '_partial-field-row.php' ),         $pass, $fail, $log );
c7c14_ok( 'partial exists: _partial-toggle-label-row.php',  file_exists( $tpl_dir . '_partial-toggle-label-row.php' ),  $pass, $fail, $log );

// ── [2] Section-skeleton enhancements ─────────────────────────────
echo "\n[2] Section-skeleton supports disabled_note + intro_hint_html + dynamic enable-label\n";
$skel_src = file_get_contents( $tpl_dir . '_section-skeleton.php' );
c7c14_ok( "skeleton defaults carry 'disabled_note'",   false !== strpos( $skel_src, "'disabled_note'" ),   $pass, $fail, $log );
c7c14_ok( "skeleton defaults carry 'intro_hint_html'", false !== strpos( $skel_src, "'intro_hint_html'" ), $pass, $fail, $log );
c7c14_ok( "skeleton enable-label text computed dynamically from is_enabled",
	false !== strpos( $skel_src, "'Disabled', 'equine-event-manager'" ),
	$pass, $fail, $log );
c7c14_ok( "skeleton emits data-eem-section-disabled-note attr for JS targeting",
	false !== strpos( $skel_src, 'data-eem-section-disabled-note' ),
	$pass, $fail, $log );

// Seed reservation
wp_set_current_user( 1 );
$reservation_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => 'C7.C.1.4 Smoke ' . wp_generate_password( 6, false, false ),
) );
update_post_meta( $reservation_id, '_en_event_source',           'feed' );
update_post_meta( $reservation_id, '_en_use_global_event_source', 0 );
// Linked event required for the editor to render section cards ($has_linked_event).
update_post_meta( $reservation_id, '_en_external_event_id',       'ext-c7c14-verify' );
update_post_meta( $reservation_id, '_en_external_event_title',    'C7.C.1.4 Verify Event' );
update_post_meta( $reservation_id, '_en_reservation_description', 'Description body smoke' );
update_post_meta( $reservation_id, '_en_checkin_checkout_enabled', 0 );
update_post_meta( $reservation_id, '_en_checkin_time',             '' );
update_post_meta( $reservation_id, '_en_checkout_time',            '' );
update_post_meta( $reservation_id, '_en_checkin_time_enabled',     0 );
update_post_meta( $reservation_id, '_en_checkout_time_enabled',    0 );
update_post_meta( $reservation_id, '_en_group_reservations_enabled', 1 );
update_post_meta( $reservation_id, '_en_group_description',        'Group body smoke' );
update_post_meta( $reservation_id, '_en_group_riders_per_group',   8 );
update_post_meta( $reservation_id, '_en_group_rider_grounds_fee_enabled', 1 );
update_post_meta( $reservation_id, '_en_group_rider_grounds_fee_amount', 25.00 );
update_post_meta( $reservation_id, '_en_group_rider_deposit_enabled', 0 );
update_post_meta( $reservation_id, '_en_group_rider_deposit_amount', 0.00 );
update_post_meta( $reservation_id, '_en_convenience_fee_enabled',  1 );
update_post_meta( $reservation_id, '_en_convenience_fee_type',     'percentage' );
update_post_meta( $reservation_id, '_en_convenience_fee_value',    3.5 );
update_post_meta( $reservation_id, '_en_stall_schedule_enabled',   0 );
update_post_meta( $reservation_id, '_en_rv_schedule_enabled',      0 );

// 2.3.56 — link a TEC event so the editor renders the configuration form. The
// hard gate (get_tec_event_id_for_reservation reverse-lookup) is source-agnostic,
// so this satisfies it even though the fixture's source meta is 'native'.
$eem_c7c14_event = wp_insert_post( array( 'post_type' => 'tribe_events', 'post_status' => 'publish', 'post_title' => 'C7.C.1.4 Smoke Event' ) );
update_post_meta( $eem_c7c14_event, '_equine_event_manager_reservation_id', $reservation_id );
update_post_meta( $reservation_id, '_en_event_id', $eem_c7c14_event );

// #55: the editor reads section config from the eem_reservation_config TABLE
// (mig-016), not the _en_* post-meta seeded above. Mirror it into the config so
// the sections actually render with their configured state.
require_once __DIR__ . '/_editor-seed.php';
eem_smoke_setup_editor( $reservation_id );

$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $reservation_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$html = ob_get_clean();

$slice_body = function ( $haystack, $key ) {
	$o = strpos( $haystack, 'id="body-' . $key . '"' );
	if ( false === $o ) return '';
	$n = strpos( $haystack, 'id="card-', $o + 1 );
	return false === $n ? substr( $haystack, $o ) : substr( $haystack, $o, $n - $o );
};

// ── [3] All 4 .A sections use .eem-field-row grid (NOT table.form-table) ──
echo "\n[3] Layout primitive: .eem-field-row (NOT <table class='form-table'>)\n";
foreach ( array( 'description', 'checkin', 'group', 'fees' ) as $key ) {
	$body = $slice_body( $html, $key );
	c7c14_ok( "section '{$key}' body uses .eem-field-row grid (mockup-canonical)",
		false !== strpos( $body, 'class="eem-field-row' ),
		$pass, $fail, $log );
	c7c14_ok( "section '{$key}' body does NOT use legacy <table class='form-table'>",
		0 === preg_match( '/<table[^>]*class="form-table"/', $body ),
		$pass, $fail, $log );
}

// ── [4] Description section ──────────────────────────────────────
echo "\n[4] description section: .eem-field-textarea + label-sub\n";
$desc = $slice_body( $html, 'description' );
c7c14_ok( "description textarea uses .eem-field-textarea",
	(bool) preg_match( '/<textarea[^>]*class="eem-field-textarea"[^>]*name="en_reservation\[reservation_description\]"/', $desc ),
	$pass, $fail, $log );
c7c14_ok( "description renders .eem-field-label-sub copy",
	false !== strpos( $desc, 'Shown on the customer-facing reservation form' ),
	$pass, $fail, $log );
c7c14_ok( "description hint matches mockup verbatim",
	false !== strpos( $desc, 'Shown above the reservation date and rate instructions on the front end.' ),
	$pass, $fail, $log );
c7c14_ok( "description textarea carries seeded value",
	false !== strpos( $desc, 'Description body smoke' ),
	$pass, $fail, $log );

// ── [5] Checkin section ──────────────────────────────────────────
echo "\n[5] checkin section: 2 datetime rows + max-width:260px\n";
$ck = $slice_body( $html, 'checkin' );
// Current render (templates/.../_section-checkin.php:58-66) uses a TIME picker
// (type="time", max-width:180px), not the older datetime-local/260px shape the
// mockup docblock once described. The control is still .eem-field-input with the
// canonical en_reservation[checkin_time] name — assert the current correct shape.
c7c14_ok( "checkin Check-In input uses .eem-field-input class + type=time",
	(bool) preg_match( '/<input[^>]*class="eem-field-input"[^>]*name="en_reservation\[checkin_time\]"[^>]*type="time"/', $ck ),
	$pass, $fail, $log );
c7c14_ok( "checkin Check-In input carries max-width:180px inline style",
	(bool) preg_match( '/name="en_reservation\[checkin_time\]"[^>]*style="max-width:180px"/', $ck )
		|| (bool) preg_match( '/style="max-width:180px"[^>]*name="en_reservation\[checkin_time\]"/', $ck ),
	$pass, $fail, $log );
c7c14_ok( "checkin Check-Out input uses .eem-field-input class",
	(bool) preg_match( '/<input[^>]*class="eem-field-input"[^>]*name="en_reservation\[checkout_time\]"/', $ck ),
	$pass, $fail, $log );
c7c14_ok( "checkin section does NOT render available_* dates (mockup canon: only datetime rows)",
	false === strpos( $ck, 'name="en_reservation[available_start_date]"' ),
	$pass, $fail, $log );
// Disabled-state: checkin seeded with checkin_checkout_enabled=0 → section disabled →
// body carries the disabled-note + striped overlay.
c7c14_ok( "checkin section disabled-note renders when toggle off",
	false !== strpos( $ck, 'This section is disabled. Enable it to set check-in and check-out times.' ),
	$pass, $fail, $log );
// Slice starts at `id="body-checkin"` (substring mid-tag) — class attrs
// come BEFORE id in the tag, so check the full $html scoped to the
// checkin card's opening body div instead.
c7c14_ok( "checkin section body carries --disabled class (toggle off in seed)",
	(bool) preg_match( '/<div\s+class="eem-section-body[^"]*eem-section-body--disabled[^"]*"\s+id="body-checkin"/', $html ),
	$pass, $fail, $log );

// ── [6] Group section ────────────────────────────────────────────
echo "\n[6] group section: 2 toggle-label-row + conditional rows + NEW meta keys\n";
$gr = $slice_body( $html, 'group' );
c7c14_ok( "group section emits hidden mirror for group_reservations_enabled",
	(bool) preg_match( '/<input\s+type="hidden"\s+name="en_reservation\[group_reservations_enabled\]"\s+data-eem-section-enabled="group"/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group Description textarea renders with NEW meta key",
	(bool) preg_match( '/<textarea[^>]*class="eem-field-textarea"[^>]*name="en_reservation\[group_description\]"/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group_description seeded value flows through",
	false !== strpos( $gr, 'Group body smoke' ),
	$pass, $fail, $log );
c7c14_ok( "group Riders Per Group input renders with NEW meta key + max-width:120px",
	(bool) preg_match( '/<input[^>]*class="eem-field-input"[^>]*name="en_reservation\[group_riders_per_group\]"[^>]*style="max-width:120px"/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group renders 2× .eem-toggle-label-row (grounds fee + deposit)",
	2 === substr_count( $gr, 'eem-toggle-label-row' ),
	$pass, $fail, $log );
c7c14_ok( "group grounds-fee toggle carries data-eem-action='reservation-editor-toggle-switch-row' (mockup-canonical)",
	// C7.X.10 — controls is now ID-based (`row-group-grounds-amt`).
	// The pre-C7.X.10 class-token form (`eem-ctrl--grounds-amt`) is
	// kept as a backward-compat fallback in this assertion only so
	// the smoke can land both before and after the rewire; it WILL be
	// dropped at C16's wholesale legacy strip.
	(bool) preg_match( '/data-eem-action="reservation-editor-toggle-switch-row"[\s\S]{0,200}data-controls="row-group-grounds-amt"/', $gr )
		|| (bool) preg_match( '/data-eem-action="reservation-editor-toggle-switch-row"[\s\S]{0,200}data-controls="row-grounds-amt"/', $gr )
		|| (bool) preg_match( '/data-eem-action="reservation-editor-toggle-switch-row"[\s\S]{0,200}data-controls="eem-ctrl--grounds-amt"/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group grounds-fee toggle hidden mirror reads value='1' (seeded on)",
	(bool) preg_match( '/data-eem-subsection-enabled="grounds-fee"\s+value="1"/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group deposit toggle hidden mirror reads value='0' (seeded off)",
	(bool) preg_match( '/data-eem-subsection-enabled="deposit"\s+value="0"/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group Grounds Fee Amount row VISIBLE (controller on)",
	// C7.X.10 — row carries id="row-group-grounds-amt" instead of
	// class="eem-ctrl--grounds-amt". Visibility = absence of
	// eem-row--hidden. Match either old or new shape to land across
	// the architecture boundary.
	( false !== strpos( $gr, 'id="row-group-grounds-amt"' )
		&& ! (bool) preg_match( '/<div\s+class="[^"]*eem-row--hidden[^"]*"\s+id="row-group-grounds-amt"/', $gr ) )
	|| (bool) preg_match( '/<div\s+class="eem-field-row\s+eem-ctrl--grounds-amt(?!\s+eem-row--hidden)/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group Deposit Amount row HIDDEN (controller off — eem-row--hidden)",
	// C7.X.10 — same architecture boundary as above.
	(bool) preg_match( '/<div\s+class="[^"]*eem-row--hidden[^"]*"\s+id="row-group-deposit-amt"/', $gr )
	|| (bool) preg_match( '/<div\s+class="eem-field-row\s+eem-ctrl--deposit-amt\s+eem-row--hidden/', $gr ),
	$pass, $fail, $log );
c7c14_ok( "group amount inputs use .eem-price-wrap (NOT .eem-currency-field)",
	(bool) preg_match( '/<div class="eem-price-wrap"><span class="eem-price-symbol">\$<\/span><input class="eem-price-input"[^>]*name="en_reservation\[group_rider_grounds_fee_amount\]"/', $gr ),
	$pass, $fail, $log );

// ── [7] Fees section ─────────────────────────────────────────────
echo "\n[7] fees section: pill triplet + conditional rows + .eem-pct-wrap (% on right)\n";
$fees = $slice_body( $html, 'fees' );
c7c14_ok( "fees section renders 3× .eem-fee-mode-btn (None / Flat / Percentage)",
	3 === substr_count( $fees, 'class="eem-fee-mode-btn' ),
	$pass, $fail, $log );
c7c14_ok( "fees None button data-eem-fee-mode='none'",
	false !== strpos( $fees, 'data-eem-fee-mode="none"' ),
	$pass, $fail, $log );
c7c14_ok( "fees Flat button data-eem-fee-mode='flat'",
	false !== strpos( $fees, 'data-eem-fee-mode="flat"' ),
	$pass, $fail, $log );
c7c14_ok( "fees Percentage button data-eem-fee-mode='percentage'",
	false !== strpos( $fees, 'data-eem-fee-mode="percentage"' ),
	$pass, $fail, $log );
c7c14_ok( "fees Percentage button has .eem-fee-mode-btn--active (seeded type=percentage)",
	(bool) preg_match( '/<button[^>]*class="eem-fee-mode-btn eem-fee-mode-btn--active"[^>]*data-eem-fee-mode="percentage"/', $fees ),
	$pass, $fail, $log );
c7c14_ok( "fees hidden mirror for convenience_fee_type carries value='percentage'",
	(bool) preg_match( '/<input\s+type="hidden"\s+name="en_reservation\[convenience_fee_type\]"\s+id="en_convenience_fee_type"\s+data-eem-fee-mode-mirror\s+value="percentage"/', $fees ),
	$pass, $fail, $log );
c7c14_ok( "fees Flat row HIDDEN (type=percentage, not flat)",
	(bool) preg_match( '/<div\s+class="eem-field-row\s+eem-ctrl--fee-flat\s+eem-row--hidden"\s+id="row-fee-flat"/', $fees ),
	$pass, $fail, $log );
c7c14_ok( "fees Percentage row VISIBLE (type=percentage)",
	(bool) preg_match( '/<div\s+class="eem-field-row\s+eem-ctrl--fee-pct(?!\s+eem-row--hidden)"\s+id="row-fee-pct"/', $fees ),
	$pass, $fail, $log );
c7c14_ok( "fees Percentage row uses .eem-pct-wrap with .eem-pct-symbol on RIGHT",
	(bool) preg_match( '/<div class="eem-pct-wrap"><input class="eem-pct-input"[^>]*\/>\s*<span class="eem-pct-symbol">%<\/span><\/div>/', $fees ),
	$pass, $fail, $log );
c7c14_ok( "fees Type hint copy verbatim from mockup (line 1031)",
	false !== strpos( $fees, 'Non-refundable. Displayed to customers at checkout' ),
	$pass, $fail, $log );

// ── [8] Enable-label dynamic text ────────────────────────────────
echo "\n[8] Enable-label dynamic 'Enabled' / 'Disabled' text\n";
preg_match( '/id="card-checkin"[\s\S]{0,1500}?<span class="eem-enable-toggle__label"[^>]*>([^<]+)</', $html, $ckl );
c7c14_ok( "checkin enable-label text reads 'Disabled' (toggle off)",
	isset( $ckl[1] ) && 'Disabled' === trim( $ckl[1] ),
	$pass, $fail, $log,
	'got: ' . ( $ckl[1] ?? '<missing>' ) );
preg_match( '/id="card-group"[\s\S]{0,1500}?<span class="eem-enable-toggle__label"[^>]*>([^<]+)</', $html, $grl );
c7c14_ok( "group enable-label text reads 'Enabled' (toggle on)",
	isset( $grl[1] ) && 'Enabled' === trim( $grl[1] ),
	$pass, $fail, $log,
	'got: ' . ( $grl[1] ?? '<missing>' ) );

// ── [9] CSS primitives shipped ───────────────────────────────────
echo "\n[9] CSS primitives in admin.css\n";
c7c14_ok( 'admin.css ships .eem-toggle-label-row',     false !== strpos( $css_src, '.eem-toggle-label-row' ), $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-row--hidden',          false !== strpos( $css_src, '.eem-row--hidden' ),       $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-section-disabled-note', false !== strpos( $css_src, '.eem-section-disabled-note' ), $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-fee-modes',            false !== strpos( $css_src, '.eem-fee-modes' ),         $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-fee-mode-btn',         false !== strpos( $css_src, '.eem-fee-mode-btn' ),      $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-fee-mode-btn--active', false !== strpos( $css_src, '.eem-fee-mode-btn--active' ), $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-pct-wrap',             false !== strpos( $css_src, '.eem-pct-wrap' ),          $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-pct-input',            false !== strpos( $css_src, '.eem-pct-input' ),         $pass, $fail, $log );
c7c14_ok( 'admin.css ships .eem-pct-symbol',           false !== strpos( $css_src, '.eem-pct-symbol' ),        $pass, $fail, $log );

// ── [10] JS handlers shipped ──────────────────────────────────────
echo "\n[10] JS handlers in admin.js\n";
c7c14_ok( "admin.js carries eemApplyControls() function",
	false !== strpos( $js_src, 'function eemApplyControls' ),
	$pass, $fail, $log );
c7c14_ok( "admin.js carries reservation-editor-toggle-subsection handler",
	false !== strpos( $js_src, 'reservation-editor-toggle-subsection' ),
	$pass, $fail, $log );
c7c14_ok( "admin.js carries reservation-editor-fee-mode handler",
	false !== strpos( $js_src, 'reservation-editor-fee-mode' ),
	$pass, $fail, $log );
c7c14_ok( "admin.js collector skips data-eem-subsection-enabled hidden mirrors when value!=1",
	false !== strpos( $js_src, 'data-eem-subsection-enabled' )
		&& (bool) preg_match( '/data-eem-subsection-enabled[\s\S]{0,200}?\'1\' !==\s*el\.value/', $js_src ),
	$pass, $fail, $log );
c7c14_ok( "admin.js on-load DOMContentLoaded calls eemApplyControls",
	(bool) preg_match( '/DOMContentLoaded[\s\S]{0,300}?eemApplyControls/', $js_src ),
	$pass, $fail, $log );

// ── [11] Render-extract-post round-trip per section ───────────────
echo "\n[11] Render-extract-post round-trip per .A section\n";
$extract = function ( $html ) {
	$flat = array();
	preg_match_all( '/<input\s+[^>]*name="(en_reservation\[[^"]*\])"[^>]*>/i', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		$type    = preg_match( '/type="([^"]+)"/i', $row[0], $tm ) ? $tm[1] : 'text';
		$value   = preg_match( '/value="([^"]*)"/i', $row[0], $vm ) ? html_entity_decode( $vm[1], ENT_QUOTES, 'UTF-8' ) : '';
		$checked = (bool) preg_match( '/\bchecked\b/i', $row[0] );
		$is_sec  = (bool) preg_match( '/data-eem-section-enabled="/i', $row[0] );
		$is_sub  = (bool) preg_match( '/data-eem-subsection-enabled="/i', $row[0] );
		if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $checked ) continue;
		if ( 'hidden' === $type && $is_sec && '1' !== $value ) continue;
		if ( 'hidden' === $type && $is_sub && '1' !== $value ) continue;
		$flat[ $row[1] ] = $value;
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

// 11a — description round-trip
$payload = $extract( $html );
$payload['reservation_description'] = 'ROUNDTRIP_DESC';
$payload['event_source'] = 'native';
$_POST = $_REQUEST = array( '_eem_editor_nonce' => $nonce, 'reservation_id' => $reservation_id, 'save_kind' => 'save_draft', 'en_reservation' => $payload );
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c14_ok( 'description round-trip: AJAX success',
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log,
	'raw: ' . substr( (string) $resp_raw, 0, 200 ) );
c7c14_ok( 'description round-trip: reservation_description persisted',
	'ROUNDTRIP_DESC' === get_post_meta( $reservation_id, '_en_reservation_description', true ),
	$pass, $fail, $log );

// 11b — group_description round-trip (NEW meta key)
ob_start(); EEM_Reservation_Editor_Page::render(); $html2 = ob_get_clean();
$payload2 = $extract( $html2 );
$payload2['group_description'] = 'ROUNDTRIP_GROUP_DESC';
$payload2['event_source'] = 'native';
$_POST = $_REQUEST = array( '_eem_editor_nonce' => $nonce, 'reservation_id' => $reservation_id, 'save_kind' => 'save_draft', 'en_reservation' => $payload2 );
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c14_ok( 'group_description round-trip: AJAX success',
	is_array( $resp ) && ! empty( $resp['success'] ),
	$pass, $fail, $log );
c7c14_ok( 'group_description round-trip: NEW meta key _en_group_description persisted',
	'ROUNDTRIP_GROUP_DESC' === get_post_meta( $reservation_id, '_en_group_description', true ),
	$pass, $fail, $log,
	'got: ' . get_post_meta( $reservation_id, '_en_group_description', true ) );

// 11c — group_riders_per_group round-trip (NEW meta key)
ob_start(); EEM_Reservation_Editor_Page::render(); $html3 = ob_get_clean();
$payload3 = $extract( $html3 );
$payload3['group_riders_per_group'] = '12';
$payload3['event_source'] = 'native';
$_POST = $_REQUEST = array( '_eem_editor_nonce' => $nonce, 'reservation_id' => $reservation_id, 'save_kind' => 'save_draft', 'en_reservation' => $payload3 );
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c14_ok( 'group_riders_per_group round-trip: NEW meta key persisted as 12',
	12 === (int) get_post_meta( $reservation_id, '_en_group_riders_per_group', true ),
	$pass, $fail, $log );

// 11d — fees round-trip: type=flat (was percentage)
ob_start(); EEM_Reservation_Editor_Page::render(); $html4 = ob_get_clean();
$payload4 = $extract( $html4 );
$payload4['convenience_fee_type'] = 'flat';
$payload4['convenience_fee_value'] = '4.25';
$payload4['event_source'] = 'native';
$_POST = $_REQUEST = array( '_eem_editor_nonce' => $nonce, 'reservation_id' => $reservation_id, 'save_kind' => 'save_draft', 'en_reservation' => $payload4 );
try { ob_start(); EEM_Reservation_Editor_Page::ajax_save(); $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
catch ( Exception $e ) { $resp_raw = ob_get_clean(); $resp = json_decode( $resp_raw, true ); }
c7c14_ok( 'fees round-trip: type=flat persisted',
	'flat' === get_post_meta( $reservation_id, '_en_convenience_fee_type', true ),
	$pass, $fail, $log );

// ── [12] section_definitions: addons collapsed=true ──────────────
echo "\n[12] section_definitions corrections\n";
$defs = EEM_Reservation_Editor_Page::section_definitions();
$addons_def = array_values( array_filter( $defs, function ( $d ) { return 'addons' === $d['key']; } ) );
c7c14_ok( "section_definitions: addons collapsed=true (mockup line 890)",
	isset( $addons_def[0] ) && true === $addons_def[0]['collapsed'],
	$pass, $fail, $log );

// ── [13] CPT new meta keys present ───────────────────────────────
echo "\n[13] CPT class — NEW meta keys (Decision N1)\n";
c7c14_ok( "CPT sanitize_meta_submission carries 'group_description' branch",
	false !== strpos( $cpt_src, "'group_description'" )
		&& false !== strpos( $cpt_src, "sanitize_textarea_field( \$source['group_description'] )" ),
	$pass, $fail, $log );
// v2.3.82 changed the semantics: blank = unlimited. The sanitize branch now stores
// absint() when the submission is a positive integer, else '' (uncapped) — no
// longer a max(1, …) floor.
c7c14_ok( "CPT sanitize_meta_submission carries 'group_riders_per_group' branch (positive-absint else blank)",
	(bool) preg_match( "/'group_riders_per_group'\s*=>[\s\S]{0,300}?absint\(\s*\\\$source\['group_riders_per_group'\]\s*\)\s*>\s*0/", $cpt_src ),
	$pass, $fail, $log );
c7c14_ok( "CPT defaults include 'group_description' => ''",
	(bool) preg_match( "/'group_description'\s*=>\s*''/", $cpt_src ),
	$pass, $fail, $log );
// v2.3.82: blank default = unlimited (was 6).
c7c14_ok( "CPT defaults include 'group_riders_per_group' => '' (blank = unlimited)",
	(bool) preg_match( "/'group_riders_per_group'\s*=>\s*''/", $cpt_src ),
	$pass, $fail, $log );

// ── [14] No native <input type="checkbox"> for ANY sub-section toggle ──
echo "\n[14] Sub-section toggle anti-pattern: NO native checkboxes for *_enabled fields\n";
foreach ( array(
	'group_rider_grounds_fee_enabled',
	'group_rider_deposit_enabled',
) as $name ) {
	c7c14_ok( "NO <input type='checkbox' name='en_reservation[{$name}]'> in rendered output (mockup-canonical toggle pattern)",
		0 === preg_match( '/<input[^>]*type="checkbox"[^>]*name="en_reservation\[' . preg_quote( $name, '/' ) . '\]"/', $html ),
		$pass, $fail, $log );
}

// Cleanup
wp_delete_post( $reservation_id, true );
wp_delete_post( $eem_c7c14_event, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
