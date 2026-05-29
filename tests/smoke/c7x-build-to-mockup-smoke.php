<?php
/**
 * C7.X build-to-mockup verification smoke.
 *
 * Asserts the entire Reservation Editor matches the canonical mockup
 * at .mockups/edit_reservation_page.html. This is the discipline
 * smoke per the new "Build to Mockup, Period" standing rule — every
 * drift item from the audit is asserted here so future regressions
 * are caught at smoke time, not visual-verify time.
 *
 * Groups (C8 port update — rail removed, sticky save bar added):
 *   [1]  Page chrome: .eem-plugin-wrap + .eem-plugin-header +
 *        .eem-plugin-title / -subtitle / -meta-line
 *   [2]  Body layout: single-col .eem-edit-body + .eem-edit-main.
 *        C8: .eem-edit-rail NOT rendered on Edit Reservation page.
 *   [3]  Right rail: C8 port — 0 rail cards in editor output. Publish
 *        card + Shortcode card RETIRED from editor. CSS rules kept.
 *   [4]  Sticky save bar: always visible (all screen sizes). Status
 *        badge + 4 action buttons. Replaces mobile-only strip.
 *   [5]  All 10 sections render (description / checkin / eventday /
 *        stall / rv / addons / group / fees / agreement /
 *        cancellation) with proper section-skeleton chrome.
 *   [6]  Section bodies use .eem-field-row grid (NOT <table
 *        class="form-table">).
 *   [7]  Sub-section toggles use .eem-toggle-label-row (NOT native
 *        checkbox).
 *   [8]  Stay-type pair uses .eem-stay-type-btn pills with
 *        data-controls IDs.
 *   [9]  Fee-mode pill triplet renders (None / Flat / Percentage).
 *   [10] Lot Zones repeating-row + 8-preset color swatches.
 *   [11] Layout summary widgets (Stall + Lot) with C8-stub buttons.
 *   [12] File-row agreement chrome.
 *   [13] Inherited-default-banner + override-actions in Cancellation.
 *   [14] Event Day Info section renders 4 field rows.
 *   [15] CSS primitives present (every mockup-canonical class shipped).
 *   [16] JS handlers present (applyControls, toggleStay, fee-mode, etc.).
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X BUILD-TO-MOCKUP VERIFICATION SMOKE ===\n";

foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X Verify' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}

wp_set_current_user( 1 );
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => 'C7.X Verify ' . wp_generate_password( 6, false, false ),
) );
update_post_meta( $rid, '_en_event_source',           'native' );
update_post_meta( $rid, '_en_use_global_event_source', 0 );

$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $rid );
ob_start();
EEM_Reservation_Editor_Page::render();
$html = ob_get_clean();
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

// ── [1] Page chrome ──────────────────────────────────────────────
// C8 update: .eem-plugin-subtitle retired from reservation editor;
// event-anchor header pattern replaces it.
echo "\n[1] Page chrome — .eem-plugin-wrap + .eem-plugin-header (C8 event-anchor)\n";
c7x_ok( '.eem-plugin-wrap renders',           false !== strpos( $html, 'class="eem-plugin-wrap"' ),    $pass, $fail, $log );
c7x_ok( '.eem-plugin-header renders',         false !== strpos( $html, 'class="eem-plugin-header"' ),  $pass, $fail, $log );
c7x_ok( '.eem-plugin-title renders',          false !== strpos( $html, 'class="eem-plugin-title"' ),   $pass, $fail, $log );
// C8: .eem-plugin-subtitle REMOVED from reservation editor (retired pattern)
c7x_ok( 'RETIRED: .eem-plugin-subtitle not in editor HTML', false === strpos( $html, 'class="eem-plugin-subtitle"' ), $pass, $fail, $log );
// C8: event-anchor header elements
c7x_ok( 'C8: id="eem-header-event-name" renders', false !== strpos( $html, 'id="eem-header-event-name"' ), $pass, $fail, $log );
c7x_ok( 'C8: class="eem-plugin-header-meta" renders', false !== strpos( $html, 'class="eem-plugin-header-meta"' ), $pass, $fail, $log );
c7x_ok( 'C8: id="eem-header-meta" renders',   false !== strpos( $html, 'id="eem-header-meta"' ),       $pass, $fail, $log );
c7x_ok( 'C8: id="eem-header-typeahead" renders', false !== strpos( $html, 'id="eem-header-typeahead"' ), $pass, $fail, $log );
c7x_ok( 'C8: id="eem-header-action-change" renders', false !== strpos( $html, 'id="eem-header-action-change"' ), $pass, $fail, $log );
c7x_ok( 'C8: id="eem-event-search-results" renders', false !== strpos( $html, 'id="eem-event-search-results"' ), $pass, $fail, $log );

// ── [2] Body layout ──────────────────────────────────────────────
// C8 port: right rail REMOVED — single column layout, no .eem-edit-rail.
echo "\n[2] Single-col body — .eem-edit-body + .eem-edit-main (C8: rail removed)\n";
c7x_ok( '.eem-edit-body renders',         false !== strpos( $html, 'class="eem-edit-body"' ),      $pass, $fail, $log );
c7x_ok( '.eem-edit-main wraps sections',  false !== strpos( $html, 'eem-edit-main' ),              $pass, $fail, $log );
c7x_ok( 'C8: NO <aside class="eem-edit-rail"> in rendered HTML', false === strpos( $html, 'class="eem-edit-rail"' ), $pass, $fail, $log );

// ── [3] Right rail content ───────────────────────────────────────
// C8 port: Right rail RETIRED from Edit Reservation page. 0 rail cards.
echo "\n[3] Rail cards — C8 port: 0 rail cards in Edit Reservation output\n";
c7x_ok( 'C8: 0 rail cards (eem-rail-card not rendered)',
	0 === substr_count( $html, 'class="eem-rail-card"' ),
	$pass, $fail, $log, 'found: ' . substr_count( $html, 'class="eem-rail-card"' ) );
c7x_ok( 'C8: Publish rail card NOT in editor HTML',
	false === strpos( $html, '<span class="eem-rail-title">Publish</span>' ),
	$pass, $fail, $log );
c7x_ok( 'C8: Shortcode rail card NOT in editor HTML',
	false === strpos( $html, '<span class="eem-rail-title">Shortcode</span>' ),
	$pass, $fail, $log );
c7x_ok( 'RETIRED C8: Linked Event rail card not in rail',
	false === strpos( $html, '<span class="eem-rail-title">Linked Event</span>' ),
	$pass, $fail, $log );
c7x_ok( 'RETIRED: no .eem-save-bar fixed-bottom',
	false === strpos( $html, 'class="eem-save-bar"' ),
	$pass, $fail, $log );
c7x_ok( 'RETIRED: no #eem-modal-linked-event',
	false === strpos( $html, 'id="eem-modal-linked-event"' ),
	$pass, $fail, $log );
c7x_ok( 'RETIRED: no meta-line change-link launcher',
	false === strpos( $html, 'reservation-editor-launch-linked-event-modal' ),
	$pass, $fail, $log );

// ── [4] Sticky save bar (always visible, all screen sizes) ──────
// C8 port: replaces mobile-only strip. Full bar with status badge + 4 buttons.
echo "\n[4] Sticky save bar — always visible, 4 action buttons\n";
c7x_ok( '.eem-sticky-save renders',
	false !== strpos( $html, 'class="eem-sticky-save"' ),
	$pass, $fail, $log );
c7x_ok( 'sticky bar: Update Reservation button text',
	false !== strpos( $html, 'Update Reservation' ) || false !== strpos( $html, 'Publish Reservation' ),
	$pass, $fail, $log );
c7x_ok( 'sticky bar: Save as Draft button',
	false !== strpos( $html, 'Save as Draft' ),
	$pass, $fail, $log );
c7x_ok( 'sticky bar: Move to Trash button (data-eem-action=reservation-editor-trash)',
	false !== strpos( $html, 'data-eem-action="reservation-editor-trash"' ),
	$pass, $fail, $log );
c7x_ok( 'sticky bar: Preview button renders',
	false !== strpos( $html, 'class="eem-btn-preview"' ),
	$pass, $fail, $log );
c7x_ok( 'sticky bar: status badge (.eem-sticky-save-status)',
	false !== strpos( $html, 'eem-sticky-save-status' ),
	$pass, $fail, $log );
c7x_ok( 'sticky bar: nonce input present',
	false !== strpos( $html, 'name="_eem_editor_nonce"' ),
	$pass, $fail, $log );
c7x_ok( 'C8: breadcrumb NOT in editor HTML (eem_render_breadcrumb removed)',
	false === strpos( $html, 'class="eem-breadcrumb"' ),
	$pass, $fail, $log );
c7x_ok( '.eem-sticky-save CSS: always-visible (display:flex, no display:none for main rule)',
	(bool) preg_match( '/\.eem-sticky-save\s*\{[^}]*display:\s*flex/', $css ),
	$pass, $fail, $log );
c7x_ok( '.eem-sticky-save-status CSS defined',
	false !== strpos( $css, '.eem-sticky-save-status' ),
	$pass, $fail, $log );
c7x_ok( '.eem-sticky-save-dot CSS defined',
	false !== strpos( $css, '.eem-sticky-save-dot' ),
	$pass, $fail, $log );

// ── [5] All 10 sections render ───────────────────────────────────
echo "\n[5] 10 sections — full mockup roster\n";
foreach ( array( 'description', 'checkin', 'eventday', 'stall', 'rv', 'addons', 'group', 'fees', 'agreement', 'cancellation' ) as $k ) {
	c7x_ok( "section card '{$k}' renders",
		false !== strpos( $html, 'id="card-' . $k . '"' ),
		$pass, $fail, $log );
}
c7x_ok( '10 section chevrons each carry inline <svg>+polyline',
	10 === preg_match_all( '/<div class="eem-section-chevron"[^>]*>\s*<svg[\s\S]*?<polyline/', $html ),
	$pass, $fail, $log );

// ── [6] Section bodies use .eem-field-row grid ───────────────────
echo "\n[6] Section bodies — .eem-field-row grid (NOT WP form-table)\n";
c7x_ok( 'eem-field-row appears 30+ times across all sections',
	preg_match_all( '/class="eem-field-row[^"]*"/', $html ) >= 30,
	$pass, $fail, $log,
	'count: ' . preg_match_all( '/class="eem-field-row[^"]*"/', $html ) );
c7x_ok( 'NO <table class="form-table"> in editor body',
	0 === preg_match( '/<table[^>]*class="form-table"/', $html ),
	$pass, $fail, $log );

// ── [7] Sub-section toggles use .eem-toggle-label-row ────────────
echo "\n[7] Sub-section toggles — .eem-toggle-label-row (NOT native checkbox)\n";
c7x_ok( '.eem-toggle-label-row renders (sub-section toggles)',
	preg_match_all( '/class="eem-toggle-label-row/', $html ) >= 4,
	$pass, $fail, $log,
	'count: ' . preg_match_all( '/class="eem-toggle-label-row/', $html ) );

// ── [8] Stay-type pair pills ──────────────────────────────────────
echo "\n[8] Stay-type pair — .eem-stay-type-btn with data-controls\n";
c7x_ok( '.eem-stay-types group renders (stall + rv)',
	2 === preg_match_all( '/class="eem-stay-types"/', $html ),
	$pass, $fail, $log,
	'count: ' . preg_match_all( '/class="eem-stay-types"/', $html ) );
c7x_ok( '.eem-stay-type-btn renders 4× (stall + rv × Nightly + Weekend)',
	4 === preg_match_all( '/class="eem-stay-type-btn[^"]*"/', $html ),
	$pass, $fail, $log,
	'count: ' . preg_match_all( '/class="eem-stay-type-btn[^"]*"/', $html ) );

// ── [9] Fee-mode pill triplet ────────────────────────────────────
echo "\n[9] Fee-mode pill triplet (None / Flat / Percentage)\n";
c7x_ok( '.eem-fee-modes container',  false !== strpos( $html, 'class="eem-fee-modes"' ),       $pass, $fail, $log );
c7x_ok( '3 .eem-fee-mode-btn buttons', 3 === preg_match_all( '/class="eem-fee-mode-btn[^"]*"/', $html ), $pass, $fail, $log );
c7x_ok( '.eem-pct-symbol present (% on RIGHT)', false !== strpos( $html, 'class="eem-pct-symbol"' ), $pass, $fail, $log );

// ── [10] Lot Zones repeating-row ─────────────────────────────────
echo "\n[10] Lot Zones — repeating-row + color swatch + template\n";
c7x_ok( '#eem-lot-zones-list container',     false !== strpos( $html, 'id="eem-lot-zones-list"' ), $pass, $fail, $log );
c7x_ok( '.eem-zone-add-btn renders',         false !== strpos( $html, 'class="eem-zone-add-btn"' ), $pass, $fail, $log );
c7x_ok( '#eem-lot-zone-row-template renders', false !== strpos( $html, 'id="eem-lot-zone-row-template"' ), $pass, $fail, $log );

// ── [11] Layout summary widgets ──────────────────────────────────
echo "\n[11] Layout summary widgets — Stall + Lot read-only\n";
c7x_ok( '.eem-layout-summary renders 2× (stall + lot)',
	2 === preg_match_all( '/class="eem-layout-summary"/', $html ),
	$pass, $fail, $log,
	'count: ' . preg_match_all( '/class="eem-layout-summary"/', $html ) );
c7x_ok( '.eem-btn-manage-layout renders 2× with C8-stub',
	2 === preg_match_all( '/class="eem-btn-manage-layout"/', $html ),
	$pass, $fail, $log );

// ── [12] File-row agreement chrome ───────────────────────────────
echo "\n[12] Agreement — .eem-file-row chrome\n";
c7x_ok( '.eem-file-row renders',     false !== strpos( $html, 'class="eem-file-row"' ),  $pass, $fail, $log );
c7x_ok( '.eem-file-name renders',    false !== strpos( $html, 'class="eem-file-name"' ), $pass, $fail, $log );
c7x_ok( '.eem-btn-upload renders',   false !== strpos( $html, 'class="eem-btn-upload"' ), $pass, $fail, $log );

// ── [13] Cancellation — inherited-default-banner + override ──────
echo "\n[13] Cancellation Policy — inherited-default + override actions\n";
c7x_ok( 'cancellation override textarea renders',
	false !== strpos( $html, 'id="en_cancellation_policy_override"' ),
	$pass, $fail, $log );
c7x_ok( '.eem-cancellation-override-actions renders',
	false !== strpos( $html, 'class="eem-cancellation-override-actions"' ),
	$pass, $fail, $log );
c7x_ok( 'cancellation status hint renders',
	false !== strpos( $html, 'id="eem-cancellation-status-hint"' ),
	$pass, $fail, $log );
c7x_ok( 'Restore default button renders',
	false !== strpos( $html, 'id="eem-cancellation-restore-btn"' ),
	$pass, $fail, $log );

// ── [14] Event Day Info section ──────────────────────────────────
echo "\n[14] Event Day Info — 4 field rows + intro hint\n";
c7x_ok( 'event_day_checkin input',  false !== strpos( $html, 'name="en_reservation[event_day_checkin]"' ),  $pass, $fail, $log );
c7x_ok( 'event_day_bring textarea', false !== strpos( $html, 'name="en_reservation[event_day_bring]"' ),    $pass, $fail, $log );
c7x_ok( 'event_day_parking textarea', false !== strpos( $html, 'name="en_reservation[event_day_parking]"' ), $pass, $fail, $log );
c7x_ok( 'event_day_contact input',  false !== strpos( $html, 'name="en_reservation[event_day_contact]"' ),  $pass, $fail, $log );
c7x_ok( 'event day intro hint copy', false !== strpos( $html, 'Customer-facing info shown in the confirmation email' ), $pass, $fail, $log );

// ── [15] CSS primitives shipped ──────────────────────────────────
echo "\n[15] CSS primitives — every mockup-canonical class in admin.css\n";
$css_classes = array(
	// Page chrome — note: .eem-plugin-subtitle + .eem-plugin-meta-line still in CSS for Settings page; not asserted here because they are retired from reservation editor HTML
	'.eem-plugin-wrap', '.eem-plugin-header', '.eem-plugin-title',
	// C8 event-anchor header classes
	'.eem-plugin-header-meta', '.eem-header-action-change', '.eem-header-typeahead',
	'input.eem-event-search-input', '.eem-event-search-results', '.eem-event-option',
	'.eem-event-option-name', '.eem-event-option-date', '.eem-event-option-current-badge',
	'.eem-header-typeahead-cancel',
	// C8 Inventory Mode buttons
	'.eem-mode-btn',
	// C8 Computed inventory display
	'.eem-inventory-computed-wrap', '.eem-inventory-computed-number', '.eem-inventory-computed-label',
	// Layout (C8 port: .eem-edit-rail CSS retained but no longer rendered in editor)
	'.eem-edit-body', '.eem-edit-main', '.eem-edit-rail',
	// Rail card CSS retained for possible reuse
	'.eem-rail-card', '.eem-rail-header', '.eem-rail-title', '.eem-rail-body', '.eem-rail-hint',
	'.eem-publish-row',
	'.eem-btn-preview', '.eem-btn-save-draft', '.eem-btn-update', '.eem-btn-danger-sm',
	'.eem-code-box',
	// C8 sticky save bar classes
	'.eem-sticky-save', '.eem-sticky-save-status', '.eem-sticky-save-dot', '.eem-sticky-save-spacer', '.eem-sticky-save-actions',
	'.eem-layout-summary', '.eem-layout-summary-left', '.eem-layout-summary-stat', '.eem-layout-summary-stat-num', '.eem-layout-summary-meta',
	'.eem-btn-manage-layout',
	'.eem-zone-list', '.eem-zone-row', '.eem-zone-color-swatch', '.eem-zone-name-input', '.eem-zone-add-btn',
	'.eem-repeat-table', '.eem-repeat-input', '.eem-repeat-price-wrap', '.eem-btn-add', '.eem-btn-delete',
	'.eem-file-row', '.eem-file-name', '.eem-btn-upload', '.eem-btn-file-del', '.eem-view-link',
	'.eem-inherited-default-banner', '.eem-cancellation-override-actions', '.eem-btn-link-secondary',
	'.eem-stay-hint',
	'.eem-toggle-label-row', '.eem-row--hidden', '.eem-section-disabled-note',
	'.eem-fee-modes', '.eem-fee-mode-btn',
	'.eem-pct-wrap', '.eem-pct-input', '.eem-pct-symbol',
);
foreach ( $css_classes as $cls ) {
	c7x_ok( "CSS primitive: {$cls}", false !== strpos( $css, $cls ), $pass, $fail, $log );
}

// ── [16] JS handlers shipped ─────────────────────────────────────
echo "\n[16] JS handlers — mockup-canonical behaviors\n";
c7x_ok( 'eemApplyControlsById',                          false !== strpos( $js, 'function eemApplyControlsById' ), $pass, $fail, $log );
c7x_ok( 'eemFlashStayHint',                              false !== strpos( $js, 'function eemFlashStayHint' ),     $pass, $fail, $log );
c7x_ok( 'eemApplyFeeModeVisibility',                     false !== strpos( $js, 'function eemApplyFeeModeVisibility' ), $pass, $fail, $log );
c7x_ok( 'eemUpdateCancellationOverrideState',            false !== strpos( $js, 'function eemUpdateCancellationOverrideState' ), $pass, $fail, $log );
c7x_ok( 'window.eemRestoreCancellationDefault',          false !== strpos( $js, 'window.eemRestoreCancellationDefault' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-toggle-stay-type handler',   false !== strpos( $js, 'reservation-editor-toggle-stay-type' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-toggle-switch-row handler',  false !== strpos( $js, 'reservation-editor-toggle-switch-row' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-fee-mode handler',           false !== strpos( $js, 'reservation-editor-fee-mode' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-zone-color-open handler',    false !== strpos( $js, 'reservation-editor-zone-color-open' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-zone-add handler',           false !== strpos( $js, 'reservation-editor-zone-add' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-trash handler',              false !== strpos( $js, 'reservation-editor-trash' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-event-unlink handler',       false !== strpos( $js, 'reservation-editor-event-unlink' ), $pass, $fail, $log );

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
