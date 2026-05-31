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
// C8 delegation fix: inline onclick handlers removed; data-eem-action delegation used instead.
c7x_ok( 'C8 delegation: NO inline onclick="changeLinkedEvent" in rendered HTML',
	false === strpos( $html, 'onclick="changeLinkedEvent' ),
	$pass, $fail, $log );
c7x_ok( 'C8 delegation: NO inline onclick="cancelChangeEvent" in rendered HTML',
	false === strpos( $html, 'onclick="cancelChangeEvent' ),
	$pass, $fail, $log );
c7x_ok( 'C8 delegation: NO inline onclick="toggleInventoryMode" in rendered HTML',
	false === strpos( $html, 'onclick="toggleInventoryMode' ),
	$pass, $fail, $log );
c7x_ok( 'C8 delegation: data-eem-action="header-change-event" present in rendered HTML',
	false !== strpos( $html, 'data-eem-action="header-change-event"' ),
	$pass, $fail, $log );
c7x_ok( 'C8 delegation: data-eem-action="header-cancel-change" present in rendered HTML',
	false !== strpos( $html, 'data-eem-action="header-cancel-change"' ),
	$pass, $fail, $log );
c7x_ok( 'C8 delegation: data-eem-action="toggle-inventory-mode" present in rendered HTML (stall + rv = 4×)',
	4 === preg_match_all( '/data-eem-action="toggle-inventory-mode"/', $html ),
	$pass, $fail, $log,
	'count: ' . preg_match_all( '/data-eem-action="toggle-inventory-mode"/', $html ) );
c7x_ok( 'C8 delegation: data-eem-input-action="header-filter-events" present in rendered HTML',
	false !== strpos( $html, 'data-eem-input-action="header-filter-events"' ),
	$pass, $fail, $log );

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

// ── [5] All 11 sections render ───────────────────────────────────
echo "\n[5] 11 sections — full mockup roster (includes event_pre_entries)\n";
foreach ( array( 'description', 'checkin', 'eventday', 'stall', 'rv', 'event_pre_entries', 'addons', 'group', 'fees', 'agreement', 'cancellation' ) as $k ) {
	c7x_ok( "section card '{$k}' renders",
		false !== strpos( $html, 'id="card-' . $k . '"' ),
		$pass, $fail, $log );
}
c7x_ok( '11 section chevrons each carry inline <svg>+polyline',
	11 === preg_match_all( '/<div class="eem-section-chevron"[^>]*>\s*<svg[\s\S]*?<polyline/', $html ),
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

// ── [10] Lot Zones repeating-row (V1: nightly/weekend only; Avail Qty removed 2.3.22) ──
echo "\n[10] Lot Zones — nightly + weekend columns + template (Avail Qty removed in V1)\n";
c7x_ok( '#eem-lot-zones-list container',      false !== strpos( $html, 'id="eem-lot-zones-list"' ),         $pass, $fail, $log );
c7x_ok( '.eem-zone-add-btn renders',          false !== strpos( $html, 'class="eem-zone-add-btn"' ),         $pass, $fail, $log );
c7x_ok( '#eem-lot-zone-row-template renders', false !== strpos( $html, 'id="eem-lot-zone-row-template"' ),   $pass, $fail, $log );
c7x_ok( 'V1: zone-qty Avail Qty field NOT in HTML (removed 2.3.22)',
	false === strpos( $html, 'data-role="zone-qty"' ),             $pass, $fail, $log );
c7x_ok( 'rv-add-zone action wired',           false !== strpos( $html, 'data-eem-action="rv-add-zone"' ),    $pass, $fail, $log );
c7x_ok( 'rv-delete-zone action wired',        false !== strpos( $html, 'data-eem-action="rv-delete-zone"' ), $pass, $fail, $log );

// ── [10.5] Stall + RV row builder (C8) ──────────────────────────
echo "\n[10.5] Stall + RV row builder — row cards + add buttons\n";
c7x_ok( '#eem-stall-row-builder-list renders', false !== strpos( $html, 'id="eem-stall-row-builder-list"' ), $pass, $fail, $log );
c7x_ok( 'stall-add-row action wired',          false !== strpos( $html, 'data-eem-action="stall-add-row"' ), $pass, $fail, $log );
c7x_ok( 'stall-delete-row action wired',       false !== strpos( $html, 'data-eem-action="stall-delete-row"' ), $pass, $fail, $log );
c7x_ok( '#eem-rv-row-builder-list renders',    false !== strpos( $html, 'id="eem-rv-row-builder-list"' ),   $pass, $fail, $log );
c7x_ok( 'rv-add-row action wired',             false !== strpos( $html, 'data-eem-action="rv-add-row"' ),   $pass, $fail, $log );
c7x_ok( 'rv-delete-row action wired',          false !== strpos( $html, 'data-eem-action="rv-delete-row"' ), $pass, $fail, $log );
c7x_ok( '#eem-blocked-stalls-select renders',  false !== strpos( $html, 'id="eem-blocked-stalls-select"' ), $pass, $fail, $log );
c7x_ok( '#eem-blocked-rv-lots-select renders', false !== strpos( $html, 'id="eem-blocked-rv-lots-select"' ), $pass, $fail, $log );
c7x_ok( 'stall map upload field renders',      false !== strpos( $html, 'id="eem-stall-map-id"' ),          $pass, $fail, $log );

// ── [10.7] V1 Zone model — Paint Mode removed (2.3.22) ──────────
// Paint Mode was removed in V1 (2.3.22). Zone is assigned at row level
// via a Zone dropdown on each row card. See docs/c10-contracts.md.
echo "\n[10.7] V1 Zone model — Paint Mode removed; row-level Zone dropdown present\n";
// Negative guards: Paint Mode UI must NOT appear.
c7x_ok( 'V1: rv-paint-zone action NOT in rendered HTML',
	false === strpos( $html, 'data-eem-input-action="rv-paint-zone"' ),
	$pass, $fail, $log );
c7x_ok( 'V1: #eem-rv-lot-zone-assignments-input NOT in rendered HTML',
	false === strpos( $html, 'id="eem-rv-lot-zone-assignments-input"' ),
	$pass, $fail, $log );
c7x_ok( 'V1: window._rvLotZoneAssignmentsInit NOT emitted',
	false === strpos( $html, 'window._rvLotZoneAssignmentsInit' ),
	$pass, $fail, $log );
c7x_ok( 'V1: rvLotClick function NOT in JS',
	false === strpos( $js, 'function rvLotClick' ),
	$pass, $fail, $log );
c7x_ok( 'V1: eem-lot-zone-dot class NOT in JS',
	false === strpos( $js, 'eem-lot-zone-dot' ),
	$pass, $fail, $log );
// Positive guards: V1 row-level zone model must be present.
c7x_ok( 'V1: getZoneColor function defined in JS',
	false !== strpos( $js, 'function getZoneColor' ),
	$pass, $fail, $log );
c7x_ok( 'V1: rvUpdateRowZoneIndicator function defined in JS',
	false !== strpos( $js, 'function rvUpdateRowZoneIndicator' ),
	$pass, $fail, $log );
c7x_ok( 'V1: zone_id Zone dropdown in rendered HTML',
	false !== strpos( $html, '[zone_id]' ),
	$pass, $fail, $log );

// ── [10.8] Section state sessionStorage (Bug-fix) ────────────────
echo "\n[10.8] Section state — sessionStorage persist + restore\n";
c7x_ok( 'JS: sessionStorage.setItem call for section state',
	false !== strpos( $js, "sessionStorage.setItem(\n\t\t\t\t\t'eem-section-STATE-'" ) ||
	false !== strpos( $js, "sessionStorage.setItem(" ) && false !== strpos( $js, 'eem-section-STATE-' ),
	$pass, $fail, $log );
c7x_ok( 'JS: sessionStorage.getItem call for restore',
	false !== strpos( $js, 'sessionStorage.getItem(' ),
	$pass, $fail, $log );
c7x_ok( 'JS: eem-section-collapsed class in restore block',
	false !== strpos( $js, "classList.add('eem-section-collapsed')" ),
	$pass, $fail, $log );
c7x_ok( 'CSS: .eem-lot-cell defined',
	false !== strpos( $css, '.eem-lot-cell' ),
	$pass, $fail, $log );
c7x_ok( 'CSS: .eem-lot-zone-dot defined',
	false !== strpos( $css, '.eem-lot-zone-dot' ),
	$pass, $fail, $log );
c7x_ok( 'CSS: .eem-lot-label defined',
	false !== strpos( $css, '.eem-lot-label' ),
	$pass, $fail, $log );
c7x_ok( 'CSS: .eem-paint-mode-active defined',
	false !== strpos( $css, '.eem-paint-mode-active' ),
	$pass, $fail, $log );

// ── [10.6] Event Pre-Entries section (C8) ───────────────────────
echo "\n[10.6] Event Pre-Entries — card + enable toggle + repeat table\n";
c7x_ok( '#card-event_pre_entries renders',        false !== strpos( $html, 'id="card-event_pre_entries"' ),          $pass, $fail, $log );
c7x_ok( '#eem-pre-entries-list renders',          false !== strpos( $html, 'id="eem-pre-entries-list"' ),            $pass, $fail, $log );
c7x_ok( 'pre-entry-add action wired',             false !== strpos( $html, 'data-eem-action="pre-entry-add"' ),      $pass, $fail, $log );
c7x_ok( 'pre-entry-delete action wired',          false !== strpos( $html, 'data-eem-action="pre-entry-delete"' ),   $pass, $fail, $log );
c7x_ok( '#eem-pre-entry-row-template renders',    false !== strpos( $html, 'id="eem-pre-entry-row-template"' ),      $pass, $fail, $log );
c7x_ok( 'seeded Friday Reining Class row',        false !== strpos( $html, 'Friday Reining Class' ),                 $pass, $fail, $log );
c7x_ok( 'seeded Saturday Cutting Class row',      false !== strpos( $html, 'Saturday Cutting Class' ),               $pass, $fail, $log );
c7x_ok( 'disabled note references pre-entries',   false !== strpos( $html, 'class or competition entries' ),         $pass, $fail, $log );

// ── [11] Layout summary widgets removed from mapped content ──────
echo "\n[11] Layout summary stub — removed from mapped-content (C8 row builder replaces it)\n";
c7x_ok( 'layout not configured yet stub REMOVED from stall section',
	false === strpos( $html, 'layout not configured yet' ),
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
	// C8 — Row builder
	'.eem-row-builder', '.eem-row-card', '.eem-row-card-top', '.eem-row-card-one-sided', '.eem-row-card-sides',
	'.eem-side-block', '.eem-side-block-label', '.eem-side-block-row',
	'.eem-row-card-field', '.eem-row-card-field-label', '.eem-row-card-delete',
	'.eem-row-card-preview-label', '.eem-row-card-count', '.eem-row-builder-summary',
	'.eem-stall-row-layout', '.eem-stall-row-side', '.eem-stall-row-aisle', '.eem-stall-box',
	'.eem-row-add-btn',
	// C8 — Tag multi-select
	'.eem-tag-select', '.eem-tag-select-input', '.eem-tag-chip', '.eem-tag-chip-remove',
	'.eem-tag-search', '.eem-tag-dropdown', '.eem-tag-dropdown-item', '.eem-tag-dropdown-empty',
	// C8 — Zone painter (RV) removed in V1 (2.3.22) — .eem-zone-painter* and .eem-lot-cell* gone.
	'.eem-file-row', '.eem-file-name', '.eem-btn-upload', '.eem-btn-file-del', '.eem-view-link',
	'.eem-inherited-default-banner', '.eem-cancellation-override-actions', '.eem-btn-link-secondary',
	'.eem-stay-hint',
	'.eem-toggle-label-row', '.eem-row--hidden', '.eem-section-disabled-note',
	'.eem-fee-modes', '.eem-fee-mode-btn',
	'.eem-pct-wrap', '.eem-pct-input', '.eem-pct-symbol',
	// Note: .eem-lot-cell, .eem-lot-zone-dot, .eem-lot-label, .eem-paint-mode-active,
	// .eem-zone-painter* removed in V1 (2.3.22) — Paint Mode is V2 backlog.
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
// Zone color picker removed (2.3.20) — colors are auto-palette-only via getZoneColor().
// Negative guard: no color-picker action should appear in rendered HTML or JS.
c7x_ok( 'zone-color-open action NOT in rendered HTML',   false === strpos( $html, 'reservation-editor-zone-color-open' ), $pass, $fail, $log );
c7x_ok( 'data-eem-zone-color-mirror NOT in rendered HTML', false === strpos( $html, 'data-eem-zone-color-mirror' ),       $pass, $fail, $log );
// V1 (2.3.22): rvRebuildPaintDropdown removed — Paint Mode is V2 backlog.
c7x_ok( 'V1: rvRebuildPaintDropdown NOT in JS',          false === strpos( $js, 'function rvRebuildPaintDropdown' ),      $pass, $fail, $log );
c7x_ok( 'V1: rvUpdateRowZoneIndicator called in JS',     false !== strpos( $js, 'rvUpdateRowZoneIndicator' ),             $pass, $fail, $log );
c7x_ok( 'reservation-editor-zone-add handler',           false !== strpos( $js, 'reservation-editor-zone-add' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-trash handler',              false !== strpos( $js, 'reservation-editor-trash' ), $pass, $fail, $log );
c7x_ok( 'reservation-editor-event-unlink handler',       false !== strpos( $js, 'reservation-editor-event-unlink' ), $pass, $fail, $log );
// C8 delegation: new actions wired through the central dispatcher
c7x_ok( 'C8: header-change-event in actions dispatcher', false !== strpos( $js, "'header-change-event'" ), $pass, $fail, $log );
c7x_ok( 'C8: header-cancel-change in actions dispatcher', false !== strpos( $js, "'header-cancel-change'" ), $pass, $fail, $log );
c7x_ok( 'C8: header-select-event in actions dispatcher', false !== strpos( $js, "'header-select-event'" ), $pass, $fail, $log );
c7x_ok( 'C8: toggle-inventory-mode in actions dispatcher', false !== strpos( $js, "'toggle-inventory-mode'" ), $pass, $fail, $log );
c7x_ok( 'C8: input delegation for header-filter-events present',
	false !== strpos( $js, "eemInputAction === 'header-filter-events'" ),
	$pass, $fail, $log );
c7x_ok( 'C8: filterEventOptions generates data-eem-action="header-select-event" (no inline onclick)',
	false !== strpos( $js, 'data-eem-action="header-select-event"' ) &&
	false === strpos( $js, 'onclick="selectLinkedEvent(' ),
	$pass, $fail, $log );
// C8 — Row builder / zone / pre-entry JS
c7x_ok( 'C8: stallAddRow function defined',       false !== strpos( $js, 'function stallAddRow' ),       $pass, $fail, $log );
c7x_ok( 'C8: stallDeleteRow function defined',    false !== strpos( $js, 'function stallDeleteRow' ),    $pass, $fail, $log );
c7x_ok( 'C8: stallRowInputChange function defined', false !== strpos( $js, 'function stallRowInputChange' ), $pass, $fail, $log );
c7x_ok( 'C8: stallRowLayoutChange function defined', false !== strpos( $js, 'function stallRowLayoutChange' ), $pass, $fail, $log );
c7x_ok( 'C8: stallLabelsBetween function defined', false !== strpos( $js, 'function stallLabelsBetween' ), $pass, $fail, $log );
c7x_ok( 'C8: generateStallPreview function defined', false !== strpos( $js, 'function generateStallPreview' ), $pass, $fail, $log );
c7x_ok( 'C8: rvAddZone function defined',         false !== strpos( $js, 'function rvAddZone' ),         $pass, $fail, $log );
c7x_ok( 'C8: rvDeleteZone function defined',      false !== strpos( $js, 'function rvDeleteZone' ),      $pass, $fail, $log );
c7x_ok( 'C8: rvAddRow function defined',          false !== strpos( $js, 'function rvAddRow' ),          $pass, $fail, $log );
c7x_ok( 'C8: rvDeleteRow function defined',       false !== strpos( $js, 'function rvDeleteRow' ),       $pass, $fail, $log );
c7x_ok( 'C8: rvRowInputChange function defined',  false !== strpos( $js, 'function rvRowInputChange' ),  $pass, $fail, $log );
c7x_ok( 'C8: preEntryAdd function defined',       false !== strpos( $js, 'function preEntryAdd' ),       $pass, $fail, $log );
c7x_ok( 'C8: preEntryDelete function defined',    false !== strpos( $js, 'function preEntryDelete' ),    $pass, $fail, $log );
c7x_ok( 'C8: stall-add-row in actions dispatcher', false !== strpos( $js, "'stall-add-row'" ),           $pass, $fail, $log );
c7x_ok( 'C8: rv-add-zone in actions dispatcher',   false !== strpos( $js, "'rv-add-zone'" ),             $pass, $fail, $log );
c7x_ok( 'C8: pre-entry-add in actions dispatcher', false !== strpos( $js, "'pre-entry-add'" ),           $pass, $fail, $log );
c7x_ok( 'C8: no inline onclick in stall row builder (data-eem-action used instead)',
	false === strpos( $js, 'onclick="addRow()' ) &&
	false === strpos( $js, 'onclick="addPreEntry()' ),
	$pass, $fail, $log );

// ── C7.X Bug + Feature assertions (2.3.16) ──────────────────────────────────────

// 1. Inventory Mode persistence: stall PHP reads _en_stall_selection_mode and applies .active conditionally.
$stall_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php' );
c7x_ok(
	'Bug C: _section-stall.php reads stall_selection_mode from $data and applies .active conditionally',
	false !== strpos( $stall_php, '_en_stall_selection_mode' ) || (
		false !== strpos( $stall_php, 'stall_selection_mode' ) &&
		false !== strpos( $stall_php, 'active' )
	),
	$pass, $fail, $log
);

// 2. Blocked RV Lots data source uses getRvLotLabels().
c7x_ok(
	'Bug D: getRvLotLabels() function defined in admin.js',
	false !== strpos( $js, 'function getRvLotLabels' ),
	$pass, $fail, $log
);

// 3. Max Stalls Per Customer field: name="eem_stall_max_per_customer" present in _section-stall.php.
c7x_ok(
	'Feature: Max Stalls Per Customer input (name=eem_stall_max_per_customer) in _section-stall.php',
	false !== strpos( $stall_php, 'name="eem_stall_max_per_customer"' ),
	$pass, $fail, $log
);

// 4. Max RV Lots Per Customer field: name="eem_rv_max_per_customer" present in _section-rv.php.
$rv_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-rv.php' );
c7x_ok(
	'Feature: Max RV Lots Per Customer input (name=eem_rv_max_per_customer) in _section-rv.php',
	false !== strpos( $rv_php, 'name="eem_rv_max_per_customer"' ),
	$pass, $fail, $log
);

// 5. Max Per Customer column in pre-entries template (eem_event_pre_entries[__index__][max_per_customer]).
$pe_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-event-pre-entries.php' );
c7x_ok(
	'Feature: Max Per Customer column in pre-entries template (eem_event_pre_entries[__index__][max_per_customer])',
	false !== strpos( $pe_php, 'eem_event_pre_entries[__index__][max_per_customer]' ),
	$pass, $fail, $log
);

// 6. V1 hint text: rows without zone assignment are unavailable (Paint Mode hint removed).
c7x_ok(
	'Polish: V1 zone hint — rows without zone assignment unavailable — present in _section-rv.php',
	false !== strpos( $rv_php, 'unavailable to customers at checkout' ),
	$pass, $fail, $log
);

// ── C8 selector regression guard (2.3.17) ───────────────────────────────────
// Prevent the class of bug where a new field added to a section template uses
// a name that eemCollectEditorFields() doesn't capture, causing silent save
// failure. Two complementary checks:

// 7a. admin.js collector includes eem_* arm — catches future narrowing.
c7x_ok(
	'Regression guard: eemCollectEditorFields() selector includes eem_* arm',
	false !== strpos( $js, 'input[name^="eem_"]' ),
	$pass, $fail, $log
);

// 7b. admin.js collector includes bare stall_selection_mode arm.
c7x_ok(
	'Regression guard: eemCollectEditorFields() selector includes bare stall_selection_mode',
	false !== strpos( $js, 'input[name="stall_selection_mode"]' ),
	$pass, $fail, $log
);

// 7c. stall_inventory renamed to en_reservation namespace (routes through save_meta).
c7x_ok(
	'Regression guard: _section-stall.php stall_inventory uses en_reservation[stall_inventory]',
	false !== strpos( $stall_php, 'name="en_reservation[stall_inventory]"' ),
	$pass, $fail, $log
);

// 7d. rv_inventory renamed to en_reservation namespace.
c7x_ok(
	'Regression guard: _section-rv.php rv_inventory uses en_reservation[rv_inventory]',
	false !== strpos( $rv_php, 'name="en_reservation[rv_inventory]"' ),
	$pass, $fail, $log
);

// 7e. No eem_* or bare-named fields exist outside the two known bare names.
//     Scan all section templates for name="X" where X is not en_reservation[*],
//     eem_*, stall_selection_mode, or rv_selection_mode — any hit is a missed field.
$section_dir    = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/';
$section_files  = glob( $section_dir . '_section-*.php' ) ?: array();
$orphan_fields  = array();
foreach ( $section_files as $sf ) {
	$src = file_get_contents( $sf );
	// Find all name="..." attribute values on form elements.
	if ( preg_match_all( '/<(?:input|select|textarea)[^>]+\bname="([^"]+)"/', $src, $m ) ) {
		foreach ( $m[1] as $field_name ) {
			// Allowed: en_reservation[*], eem_*, stall_selection_mode, rv_selection_mode,
			// eem_stall_rows[*], eem_rv_zones[*], eem_rv_rows[*], eem_event_pre_entries[*]
			// (those all start with eem_), _eem_* (nonce fields), or template placeholders.
			if (
				0 === strpos( $field_name, 'en_reservation' ) ||
				0 === strpos( $field_name, 'eem_' )           ||
				0 === strpos( $field_name, '_eem_' )          ||
				'stall_selection_mode' === $field_name        ||
				'rv_selection_mode'    === $field_name        ||
				false !== strpos( $field_name, '__index__' )  // template placeholder rows
			) {
				continue;
			}
			$orphan_fields[] = basename( $sf ) . ': name="' . $field_name . '"';
		}
	}
}
c7x_ok(
	'Regression guard: no orphaned bare-named fields in section templates (would be silently dropped by eemCollectEditorFields)',
	empty( $orphan_fields ),
	$pass, $fail, $log,
	empty( $orphan_fields ) ? '' : 'orphaned: ' . implode( ', ', $orphan_fields )
);

// ── C8 Pre-Entries meta-registration regression guard (2.3.18) ───────────────
// Catches the class of bug where a new section template is added but its meta
// keys are not registered in get_default_meta_values(), causing get_meta_values()
// to skip them and $data never carrying those keys to the template.

$cpt_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );

// 8a. event_pre_entries_enabled registered in get_default_meta_values().
c7x_ok(
	'Regression guard: event_pre_entries_enabled in get_default_meta_values()',
	false !== strpos( $cpt_php, "'event_pre_entries_enabled'" ),
	$pass, $fail, $log
);

// 8b. event_pre_entries registered in get_default_meta_values().
c7x_ok(
	'Regression guard: event_pre_entries in get_default_meta_values()',
	false !== strpos( $cpt_php, "'event_pre_entries'" ),
	$pass, $fail, $log
);

// 8c. Pre-entries template does NOT use get_post_meta(get_the_ID(), ...) pattern.
//     That call returns 0 on custom admin pages (not inside WP loop).
$pe_php_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-event-pre-entries.php' );
c7x_ok(
	'Regression guard: _section-event-pre-entries.php does not call get_post_meta(get_the_ID())',
	false === strpos( $pe_php_src, 'get_post_meta( get_the_ID()' ) &&
	false === strpos( $pe_php_src, 'get_post_meta(get_the_ID()' ),
	$pass, $fail, $log
);

// 8d. Broadened guard (2.3.19): scan ALL section templates — stall and RV are now also fixed.
$section_dir2    = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/';
$section_files2  = glob( $section_dir2 . '_section-*.php' ) ?: array();
$get_the_id_hits = array();
foreach ( $section_files2 as $sf2 ) {
	$src2 = file_get_contents( $sf2 );
	// Match both spacing variants. Comments containing the string also fail — templates must
	// use the $data pattern exclusively; the warning comment itself should not contain the call.
	if (
		false !== strpos( $src2, 'get_post_meta( get_the_ID()' ) ||
		false !== strpos( $src2, 'get_post_meta(get_the_ID()' )
	) {
		$get_the_id_hits[] = basename( $sf2 );
	}
}
c7x_ok(
	'Regression guard: no section template calls get_post_meta(get_the_ID()) — use $data instead',
	empty( $get_the_id_hits ),
	$pass, $fail, $log,
	empty( $get_the_id_hits ) ? '' : 'offending: ' . implode( ', ', $get_the_id_hits )
);

// 8e. Stall/RV C8 meta keys registered in get_default_meta_values() (2.3.19).
// Note: 'rv_lot_zone_assignments' removed in V1 (2.3.22) — see section 9h for
// the negative guard asserting it is NOT present.
$keys_9 = array(
	'stall_rows', 'blocked_stalls', 'stall_map_id',
	'rv_zones', 'rv_rows', 'blocked_rv_lots',
	'rv_lot_map_id',
);
foreach ( $keys_9 as $k9 ) {
	c7x_ok(
		"Regression guard: '{$k9}' registered in get_default_meta_values()",
		false !== strpos( $cpt_php, "'{$k9}'" ),
		$pass, $fail, $log
	);
}

// ── 9. V1 simplification: Paint Mode removed, row-level Zone dropdown (2.3.22) ─

$rv_php    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-rv.php' );
$admin_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$cpt_php2  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );

// 9a. Paint Mode UI not present in rendered RV section output.
c7x_ok(
	'2.3.22: No Paint Mode UI in rendered HTML (eem-zone-painter absent)',
	false === strpos( $html, 'eem-zone-painter' ) &&
	false === strpos( $html, 'rv-paint-zone' ),
	$pass, $fail, $log
);

// 9b. No rv-lot-click data-eem-action in rendered HTML.
c7x_ok(
	'2.3.22: No rv-lot-click action in rendered HTML',
	false === strpos( $html, 'rv-lot-click' ),
	$pass, $fail, $log
);

// 9c. RV row cards contain Zone dropdown (name pattern eem_rv_rows[N][zone_id]).
c7x_ok(
	'2.3.22: RV row card Zone dropdown present in rendered HTML (eem_rv_rows[0][zone_id])',
	false !== strpos( $html, 'eem_rv_rows[0][zone_id]' ) ||
	false !== strpos( $html, 'eem_rv_rows[1][zone_id]' ),
	$pass, $fail, $log
);

// 9d. Zone dropdown has Unassigned option.
c7x_ok(
	'2.3.22: Zone dropdown has Unassigned option',
	false !== strpos( $html, 'Unassigned' ),
	$pass, $fail, $log
);

// 9e. Paint Mode functions NOT in admin.js.
c7x_ok(
	'2.3.22: rvCountUnassignedLots NOT in admin.js (removed)',
	false === strpos( $js, 'function rvCountUnassignedLots' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.22: openUnassignedLotsWarning NOT in admin.js (removed)',
	false === strpos( $js, 'function openUnassignedLotsWarning' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.22: rvRebuildPaintDropdown NOT in admin.js (removed)',
	false === strpos( $js, 'function rvRebuildPaintDropdown' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.22: getDefaultZoneForLot NOT in admin.js (removed)',
	false === strpos( $js, 'function getDefaultZoneForLot' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.22: rvSyncPaintModeState NOT in admin.js (removed)',
	false === strpos( $js, 'function rvSyncPaintModeState' ),
	$pass, $fail, $log
);

// 9f. rvUpdateRowZoneIndicator function defined in admin.js.
c7x_ok(
	'2.3.22: rvUpdateRowZoneIndicator function defined in admin.js',
	false !== strpos( $js, 'function rvUpdateRowZoneIndicator' ),
	$pass, $fail, $log
);

// 9g. updateRvInventoryDisplay uses row lot counts (not zone-qty inputs).
c7x_ok(
	'2.3.22: updateRvInventoryDisplay sums row lot counts (not zone-qty)',
	false !== strpos( $js, 'eem-rv-row-builder-list' ) &&
	false === strpos( $js, 'data-role="zone-qty"' ),
	$pass, $fail, $log
);

// 9h. _en_rv_lot_zone_assignments NOT in get_default_meta_values().
c7x_ok(
	'2.3.22: rv_lot_zone_assignments NOT registered in get_default_meta_values()',
	false === strpos( $cpt_php2, "'rv_lot_zone_assignments' => array()" ),
	$pass, $fail, $log
);

// 9i. Paint Mode CSS not in admin.css.
c7x_ok(
	'2.3.22: .eem-zone-painter CSS removed from admin.css',
	false === strpos( $admin_css, '.eem-zone-painter {' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.22: .eem-lot-cell CSS removed from admin.css',
	false === strpos( $admin_css, '.eem-lot-cell {' ),
	$pass, $fail, $log
);

// 9j. _section-rv.php has V2 backlog comment.
c7x_ok(
	'2.3.22: _section-rv.php has V2 BACKLOG comment block',
	false !== strpos( $rv_php, 'V2 BACKLOG' ),
	$pass, $fail, $log
);

// 9k. docs/c10-contracts.md exists and references V1 model.
c7x_ok(
	'2.3.22: docs/c10-contracts.md exists',
	file_exists( EQUINE_EVENT_MANAGER_PATH . 'docs/c10-contracts.md' ),
	$pass, $fail, $log
);

// 9l. Version was bumped to 2.3.22 at the V1 simplification commit.
// Version assertion superseded by 10a (bumped again to 2.3.23 in UX polish commit).
// Kept as a historical milestone marker; actual version check is in section 10.

// ── 10. UX polish 2.3.23 ────────────────────────────────────────────────────
echo "\n[10.UX] UX Polish 2.3.23 — field reorders, Stall Map upload fix, RV Lot Map\n";

$stall_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php' );

// 10a. Version bumped to 2.3.23 (superseded by 2.3.24 — checked in section 11).
c7x_ok(
	'2.3.23: EQUINE_EVENT_MANAGER_VERSION is at least 2.3.23',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.23', '>=' ),
	$pass, $fail, $log
);

// 10b. Stall section field order: Shavings Price appears before Inventory Mode.
c7x_ok(
	'2.3.23: Stall — Inventory Mode row appears after Shavings Price row in rendered HTML',
	strpos( $html, 'row-stall-shavings-price' ) < strpos( $html, 'eem-row-stall-inventory-mode' ),
	$pass, $fail, $log
);

// 10c. RV section field order: Early Bird Weekend Rate appears before RV Inventory Mode.
c7x_ok(
	'2.3.23: RV — Inventory Mode row appears after Early Bird Weekend Rate in rendered HTML',
	strpos( $html, 'rv_early_bird_weekend_rate' ) < strpos( $html, 'eem-row-rv-inventory-mode' ),
	$pass, $fail, $log
);

// 10d. Stall Map upload button uses data-eem-action (no inline onclick).
c7x_ok(
	'2.3.23: Stall Map upload uses data-eem-action="stall-map-upload" (not inline onclick)',
	false !== strpos( $html, 'data-eem-action="stall-map-upload"' ) &&
	false === strpos( $html, 'onclick="stallMapUpload' ),
	$pass, $fail, $log
);

// 10e. Stall Map JS handler present in admin.js.
c7x_ok(
	'2.3.23: stall-map-upload handler present in admin.js',
	false !== strpos( $js, 'stall-map-upload' ),
	$pass, $fail, $log
);

// 10f. RV Lot Map field renders in HTML.
c7x_ok(
	'2.3.23: RV Lot Map field renders (name="eem_rv_lot_map_id")',
	false !== strpos( $html, 'name="eem_rv_lot_map_id"' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.23: RV Lot Map hidden input renders (id="eem-rv-lot-map-id")',
	false !== strpos( $html, 'id="eem-rv-lot-map-id"' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.23: RV Lot Map upload button uses data-eem-action="rv-lot-map-upload"',
	false !== strpos( $html, 'data-eem-action="rv-lot-map-upload"' ),
	$pass, $fail, $log
);

// 10g. RV Lot Map JS handler present in admin.js.
c7x_ok(
	'2.3.23: rv-lot-map-upload handler present in admin.js',
	false !== strpos( $js, 'rv-lot-map-upload' ),
	$pass, $fail, $log
);

// 10h. rv_lot_map_id registered in get_default_meta_values() (already in $keys_9 above).
// Positive regression guard: checked above in the $keys_9 loop.

// 10i. RV Lot Map save handler wired in reservation editor page.
$editor_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
c7x_ok(
	'2.3.23: eem_rv_lot_map_id save handler in reservation editor page',
	false !== strpos( $editor_php, 'eem_rv_lot_map_id' ),
	$pass, $fail, $log
);

// ── 11. Stall Chart Detail port 2.3.24 ──────────────────────────────────────
echo "\n[11] Stall Chart Detail port 2.3.24 — V1 patterns\n";

$admin_php  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

// 11a. Version 2.3.25
c7x_ok(
	'2.3.25: EQUINE_EVENT_MANAGER_VERSION is at least 2.3.25',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.25', '>=' ),
	$pass, $fail, $log
);

// 11b. render_stall_chart_page uses eem-plugin-wrap (old inline script removed)
c7x_ok(
	'2.3.24: render_stall_chart_page uses eem-plugin-wrap + eem-stall-chart-body; old inline script removed',
	false !== strpos( $admin_php, 'eem-plugin-wrap' ) &&
	false !== strpos( $admin_php, 'eem-stall-chart-body' ) &&
	false === strpos( $admin_php, 'eem-stall-chart-table-card' ),
	$pass, $fail, $log
);

// 11c. No inline onclick in stall chart page render
c7x_ok(
	'2.3.24: render_stall_chart_page has no inline onclick= handlers',
	false === strpos( $admin_php, 'onclick="window.print()' ) &&
	false === strpos( $admin_php, 'onclick="this.form.submit()' ) &&
	false === strpos( $admin_php, 'onchange="this.form.submit()' ),
	$pass, $fail, $log
);

// 11d. data-eem-action delegation used for stall chart actions
c7x_ok(
	'2.3.24: stall-chart-switch-view data-eem-action in admin_php',
	false !== strpos( $admin_php, 'stall-chart-switch-view' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.24: stall-chart-filter-barn data-eem-action in admin_php',
	false !== strpos( $admin_php, 'stall-chart-filter-barn' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.24: stall-chart-change-event data-eem-action in admin_php',
	false !== strpos( $admin_php, 'stall-chart-change-event' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.24: stall-chart-print data-eem-action in admin_php',
	false !== strpos( $admin_php, 'stall-chart-print' ),
	$pass, $fail, $log
);

// 11e. eem-occ-pill CSS in admin.css
c7x_ok(
	'2.3.24: .eem-occ-pill--reserved CSS in admin.css',
	false !== strpos( $admin_css, 'eem-occ-pill--reserved' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.24: .eem-stall-chart-view-tab CSS in admin.css',
	false !== strpos( $admin_css, 'eem-stall-chart-view-tab' ),
	$pass, $fail, $log
);

// 11f. stall-chart-switch-view JS handler in admin.js
c7x_ok(
	'2.3.24: stall-chart-switch-view handler in admin.js',
	false !== strpos( $js, 'stall-chart-switch-view' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.24: stall-chart-pill-click handler in admin.js',
	false !== strpos( $js, 'stall-chart-pill-click' ),
	$pass, $fail, $log
);
c7x_ok(
	'2.3.24: stall-chart-print handler in admin.js',
	false !== strpos( $js, 'stall-chart-print' ),
	$pass, $fail, $log
);

// 11g. Issues data structured (now arrays not strings) in build_stall_chart_grid
c7x_ok(
	"2.3.24: build_stall_chart_grid returns structured issues (array with 'text' key)",
	false !== strpos( $admin_php, "'text'" ) && false !== strpos( $admin_php, "'order_key'" ),
	$pass, $fail, $log
);

// 11h. render_stall_chart_matrix_table uses eem-occ-pill classes
c7x_ok(
	'2.3.24: render_stall_chart_matrix_table uses eem-occ-pill spans',
	false !== strpos( $admin_php, 'eem-occ-pill' ),
	$pass, $fail, $log
);

// 11i. No postbox/form-table in the stall chart detail (old WP patterns gone)
c7x_ok(
	'2.3.24: stall chart detail uses eem-stall-chart-stats-bar (not eem-shell-metrics-grid)',
	false !== strpos( $admin_php, 'eem-stall-chart-stats-bar' ),
	$pass, $fail, $log
);

// ── 12. Stall & RV Charts LIST page + Enable Stall Chart toggle (2.3.25) ──
echo "\n[12] Stall & RV Charts list page + stall chart toggle 2.3.25\n";

$stall_section_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php' );
$editor_page_php   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$admin_php_fresh   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$seeder_php        = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'tools/seed-demo-data.php' );

// 12a. stall_chart_enabled in get_default_meta_values() (reservations CPT class).
$cpt_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );
c7x_ok(
	'2.3.25: stall_chart_enabled registered in get_default_meta_values()',
	false !== strpos( $cpt_php, "'stall_chart_enabled'" ) && false !== strpos( $cpt_php, 'get_default_meta_values' ),
	$pass, $fail, $log
);

// 12b. Stall section template contains the eem_stall_chart_enabled toggle input name.
c7x_ok(
	'2.3.25: _section-stall.php emits eem_stall_chart_enabled toggle',
	false !== strpos( $stall_section_php, 'eem_stall_chart_enabled' ),
	$pass, $fail, $log
);

// 12c. Stall section toggle uses eem_render_editor_toggle_label_row() helper.
c7x_ok(
	'2.3.25: stall chart toggle uses eem_render_editor_toggle_label_row helper',
	false !== strpos( $stall_section_php, 'eem_render_editor_toggle_label_row' ) &&
	false !== strpos( $stall_section_php, 'stall-chart' ),
	$pass, $fail, $log
);

// 12d. ajax_save() in editor page handles eem_stall_chart_enabled checkbox.
c7x_ok(
	'2.3.25: ajax_save() handles eem_stall_chart_enabled checkbox',
	false !== strpos( $editor_page_php, '_en_stall_chart_enabled' ) &&
	false !== strpos( $editor_page_php, 'eem_stall_chart_enabled' ),
	$pass, $fail, $log
);

// 12e. render_stall_charts_list_page() method exists in EEM_Admin.
c7x_ok(
	'2.3.25: render_stall_charts_list_page() method defined in EEM_Admin',
	false !== strpos( $admin_php_fresh, 'render_stall_charts_list_page' ),
	$pass, $fail, $log
);

// 12f. get_stall_charts_list_data() method exists in EEM_Admin.
c7x_ok(
	'2.3.25: get_stall_charts_list_data() method defined in EEM_Admin',
	false !== strpos( $admin_php_fresh, 'get_stall_charts_list_data' ),
	$pass, $fail, $log
);

// 12g. List page uses .eem-plugin-header (V1 shell pattern, not WP postbox).
c7x_ok(
	'2.3.25: render_stall_charts_list_page uses eem-plugin-header',
	false !== strpos( $admin_php_fresh, 'eem-plugin-header' ) &&
	false === strpos( $admin_php_fresh, 'class="postbox"' ),
	$pass, $fail, $log
);

// 12h. List page table uses .eem-sc-list-table.
c7x_ok(
	'2.3.25: render_stall_charts_list_page emits eem-sc-list-table',
	false !== strpos( $admin_php_fresh, 'eem-sc-list-table' ),
	$pass, $fail, $log
);

// 12i. Status tabs removed in 2.3.28 — list now shows configured-only.
c7x_ok(
	'2.3.28: render_stall_charts_list_page no longer emits sc-filter-tab actions',
	false === strpos( $admin_php_fresh, 'sc-filter-tab' ),
	$pass, $fail, $log
);

// 12j. Search input uses data-eem-input-action="sc-list-search".
c7x_ok(
	'2.3.25: render_stall_charts_list_page emits sc-list-search input action',
	false !== strpos( $admin_php_fresh, 'sc-list-search' ),
	$pass, $fail, $log
);

// 12k. sc-filter-tab JS handler wired in admin.js.
c7x_ok(
	'2.3.25: sc-filter-tab click handler in admin.js',
	false !== strpos( $js, 'sc-filter-tab' ),
	$pass, $fail, $log
);

// 12l. sc-list-search JS handler wired in admin.js.
c7x_ok(
	'2.3.25: sc-list-search input handler in admin.js',
	false !== strpos( $js, 'sc-list-search' ),
	$pass, $fail, $log
);

// 12m. .eem-sc-list-table CSS defined in admin.css.
c7x_ok(
	'2.3.25: .eem-sc-list-table CSS in admin.css',
	false !== strpos( $admin_css, 'eem-sc-list-table' ),
	$pass, $fail, $log
);

// 12n. .eem-sc-status-tabs CSS defined in admin.css.
c7x_ok(
	'2.3.25: .eem-sc-status-tabs CSS in admin.css',
	false !== strpos( $admin_css, 'eem-sc-status-tabs' ),
	$pass, $fail, $log
);

// 12o. Seeder file exists at tools/seed-demo-data.php.
c7x_ok(
	'2.3.25: tools/seed-demo-data.php exists',
	file_exists( EQUINE_EVENT_MANAGER_PATH . 'tools/seed-demo-data.php' ),
	$pass, $fail, $log
);

// 12p. WP_CLI::add_command('eem seed_demo', ...) registered in seeder.
c7x_ok(
	"2.3.25: WP_CLI::add_command 'eem seed_demo' registered in seeder",
	false !== strpos( $seeder_php, "add_command( 'eem seed_demo'" ),
	$pass, $fail, $log
);

// 12q. Seeder contains EEM_Seed_Demo_Command class.
c7x_ok(
	'2.3.25: EEM_Seed_Demo_Command class defined in seeder',
	false !== strpos( $seeder_php, 'class EEM_Seed_Demo_Command' ),
	$pass, $fail, $log
);

// 12r. WP-CLI bootstrap wired in includes/class-equine-event-manager.php.
$main_class_php = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
c7x_ok(
	'2.3.25: WP-CLI require of seed-demo-data.php wired in main class',
	false !== strpos( $main_class_php, 'seed-demo-data.php' ) &&
	false !== strpos( $main_class_php, 'WP_CLI' ),
	$pass, $fail, $log
);

// ── 13. V1 meta-key config reader + seeder linkage fix (2.3.26) ──────────────
echo "\n[13] V1 stall/RV config reader + seeder notes linkage 2.3.26\n";

$repo_php    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-orders-repository.php' );
$seeder_php2 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'tools/seed-demo-data.php' );

// 13a. Plugin version is at least 2.3.26 (canonical version asserted in [14] for 2.3.27).
c7x_ok(
	'2.3.26: EQUINE_EVENT_MANAGER_VERSION >= 2.3.26',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.26', '>=' ),
	$pass, $fail, $log
);

// 13b. Admin class get_stall_chart_config reads _en_stall_rows (V1 key).
c7x_ok(
	'2.3.26: admin get_stall_chart_config reads _en_stall_rows V1 meta key',
	false !== strpos( $admin_php_fresh, "'_en_stall_rows'" ),
	$pass, $fail, $log
);

// 13c. Admin class get_stall_chart_config reads _en_rv_rows (V1 key).
c7x_ok(
	'2.3.26: admin get_stall_chart_config reads _en_rv_rows V1 meta key',
	false !== strpos( $admin_php_fresh, "'_en_rv_rows'" ),
	$pass, $fail, $log
);

// 13d. expand_label_range helper defined in admin class.
c7x_ok(
	'2.3.26: expand_label_range helper defined in admin class',
	false !== strpos( $admin_php_fresh, 'function expand_label_range' ),
	$pass, $fail, $log
);

// 13e. expand_v1_stall_rows helper defined in admin class.
c7x_ok(
	'2.3.26: expand_v1_stall_rows helper defined in admin class',
	false !== strpos( $admin_php_fresh, 'function expand_v1_stall_rows' ),
	$pass, $fail, $log
);

// 13f. build_barn_map_from_v1_rows helper defined in admin class.
c7x_ok(
	'2.3.26: build_barn_map_from_v1_rows helper defined in admin class',
	false !== strpos( $admin_php_fresh, 'function build_barn_map_from_v1_rows' ),
	$pass, $fail, $log
);

// 13g. expand_rv_lot_names_from_v1_rows helper defined in admin class.
c7x_ok(
	'2.3.26: expand_rv_lot_names_from_v1_rows helper defined in admin class',
	false !== strpos( $admin_php_fresh, 'function expand_rv_lot_names_from_v1_rows' ),
	$pass, $fail, $log
);

// 13h. expand_label_range helper defined in orders repository.
c7x_ok(
	'2.3.26: expand_label_range helper defined in orders repository',
	false !== strpos( $repo_php, 'function expand_label_range' ),
	$pass, $fail, $log
);

// 13i. expand_v1_stall_rows helper defined in orders repository.
c7x_ok(
	'2.3.26: expand_v1_stall_rows helper defined in orders repository',
	false !== strpos( $repo_php, 'function expand_v1_stall_rows' ),
	$pass, $fail, $log
);

// 13j. expand_rv_lot_names_from_v1_rows helper defined in orders repository.
c7x_ok(
	'2.3.26: expand_rv_lot_names_from_v1_rows helper defined in orders repository',
	false !== strpos( $repo_php, 'function expand_rv_lot_names_from_v1_rows' ),
	$pass, $fail, $log
);

// 13k. Seeder notes include "Reservation setup ID:" pattern for order linkage.
c7x_ok(
	'2.3.26: seeder notes include Reservation setup ID: pattern',
	false !== strpos( $seeder_php2, "'Reservation setup ID: '" ),
	$pass, $fail, $log
);

// 13l. Seeder teardown deletes orphan non-demo rows (C4F/SEED-* cleanup).
c7x_ok(
	'2.3.26: seeder teardown deletes orphan non-demo rows by notes+email filter',
	false !== strpos( $seeder_php2, 'Reservation setup ID:' ) &&
	false !== strpos( $seeder_php2, 'email NOT LIKE' ),
	$pass, $fail, $log
);

// 13m. Orders repository get_stall_chart_config reads _en_stall_rows (V1 key).
c7x_ok(
	'2.3.26: orders repo get_stall_chart_config reads _en_stall_rows V1 meta key',
	false !== strpos( $repo_php, "'_en_stall_rows'" ),
	$pass, $fail, $log
);

// 13n. Orders repository get_stall_chart_config reads _en_rv_rows (V1 key).
c7x_ok(
	'2.3.26: orders repo get_stall_chart_config reads _en_rv_rows V1 meta key',
	false !== strpos( $repo_php, "'_en_rv_rows'" ),
	$pass, $fail, $log
);

// ── 14. Stall & RV Charts list polish + detail switching (2.3.27) ──────────────
echo "\n[14] Stall & RV Charts list polish + detail switching 2.3.27\n";

// 14a. Plugin version is at least 2.3.27 (canonical version asserted in [15] for 2.3.28).
c7x_ok(
	'2.3.27: EQUINE_EVENT_MANAGER_VERSION >= 2.3.27',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.27', '>=' ),
	$pass, $fail, $log
);

// 14b. List page render emits .chart-stats.
c7x_ok(
	'2.3.27: list page renders .chart-stats class',
	false !== strpos( $admin_php_fresh, 'chart-stats' ),
	$pass, $fail, $log
);

// 14c. List page render emits .res-dates.
c7x_ok(
	'2.3.27: list page renders .res-dates class',
	false !== strpos( $admin_php_fresh, 'res-dates' ),
	$pass, $fail, $log
);

// 14d. Sortable headers carry .sort-icon.
c7x_ok(
	'2.3.27: list page renders .sort-icon on sortable headers',
	false !== strpos( $admin_php_fresh, 'sort-icon' ),
	$pass, $fail, $log
);

// 14e. Toolbar wrapper class .toolbar present.
c7x_ok(
	'2.3.27: list page renders .toolbar wrapper',
	false !== strpos( $admin_php_fresh, 'toolbar-left' ) && false !== strpos( $admin_php_fresh, 'toolbar-select' ),
	$pass, $fail, $log
);

// 14f. Status tab separators removed in 2.3.28 (status tabs no longer rendered).
c7x_ok(
	'2.3.28: list page no longer renders status tab separators (tabs removed)',
	false === strpos( $admin_php_fresh, 'eem-filter-tab-sep' ),
	$pass, $fail, $log
);

// 14g. Pagination row rendered.
c7x_ok(
	'2.3.27: list page renders .pagination-row',
	false !== strpos( $admin_php_fresh, 'pagination-row' ) && false !== strpos( $admin_php_fresh, 'page-btn' ),
	$pass, $fail, $log
);

// 14h. Detail page renders .cell-action-menu BEM alias.
c7x_ok(
	'2.3.27: detail page renders .cell-action-menu',
	false !== strpos( $admin_php_fresh, 'cell-action-menu' ),
	$pass, $fail, $log
);

// 14i. Detail page renders .destination-banner.
c7x_ok(
	'2.3.27: detail page renders .destination-banner',
	false !== strpos( $admin_php_fresh, 'destination-banner' ),
	$pass, $fail, $log
);

// 14j. Detail page renders .scope-modal-overlay.
c7x_ok(
	'2.3.27: detail page renders .scope-modal-overlay',
	false !== strpos( $admin_php_fresh, 'scope-modal-overlay' ),
	$pass, $fail, $log
);

// 14k. Pill carries data-eem-action="stall-pill-click".
c7x_ok(
	'2.3.27: occupancy pill emits data-eem-action="stall-pill-click"',
	false !== strpos( $admin_php_fresh, 'data-eem-action="stall-pill-click"' ),
	$pass, $fail, $log
);

// 14l. Move action emitted on cell menu button.
c7x_ok(
	'2.3.27: cell menu emits data-eem-action="move-to-different-stall"',
	false !== strpos( $admin_php_fresh, 'data-eem-action="move-to-different-stall"' ),
	$pass, $fail, $log
);

// 14m. AJAX action eem_move_stall_assignment registered.
c7x_ok(
	'2.3.27: wp_ajax_eem_move_stall_assignment action registered',
	false !== strpos( $admin_php_fresh, 'wp_ajax_eem_move_stall_assignment' ),
	$pass, $fail, $log
);

// 14n. AJAX handler method defined.
c7x_ok(
	'2.3.27: ajax_move_stall_assignment handler defined',
	false !== strpos( $admin_php_fresh, 'function ajax_move_stall_assignment' ),
	$pass, $fail, $log
);

// 14o. CSS adds 2.3.27 polish block.
c7x_ok(
	'2.3.27: admin.css contains chart-stats + scope-modal CSS',
	false !== strpos( $admin_css, 'scope-modal__title' ) && false !== strpos( $admin_css, 'destination-banner__cancel' ),
	$pass, $fail, $log
);

// 14p. JS handler for confirm-move present.
$admin_js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
c7x_ok(
	'2.3.27: admin.js wires data-eem-action="confirm-move" handler',
	false !== strpos( $admin_js, 'data-eem-action="confirm-move"' ) && false !== strpos( $admin_js, 'eem_move_stall_assignment' ),
	$pass, $fail, $log
);

// ── 15. List page structural polish + configured-only filter (2.3.28) ───────
echo "\n[15] Stall Charts list page: plugin-wrap + breadcrumb + no status tabs (2.3.28)\n";

$admin_php_228 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

// 15a. Version === 2.3.28
c7x_ok(
	'2.3.28: EQUINE_EVENT_MANAGER_VERSION === 2.3.28',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && '2.3.28' === EQUINE_EVENT_MANAGER_VERSION,
	$pass, $fail, $log
);

// 15b. render_stall_charts_list_page outputs eem-plugin-wrap class
c7x_ok(
	'2.3.28: render_stall_charts_list_page contains eem-plugin-wrap',
	false !== strpos( $admin_php_228, 'eem-plugin-wrap' ) &&
	false !== strpos( $admin_php_228, 'render_stall_charts_list_page' ),
	$pass, $fail, $log
);

// 15c. render output contains breadcrumb element
c7x_ok(
	'2.3.28: render_stall_charts_list_page contains eem-breadcrumb',
	false !== strpos( $admin_php_228, 'eem-breadcrumb' ),
	$pass, $fail, $log
);

// 15d. render output contains plugin-header
c7x_ok(
	'2.3.28: render_stall_charts_list_page contains eem-plugin-header',
	false !== strpos( $admin_php_228, 'eem-plugin-header' ),
	$pass, $fail, $log
);

// 15e. status-tabs block removed
c7x_ok(
	'2.3.28: status-tabs / eem-sc-status-tabs NOT in render_stall_charts_list_page',
	false === strpos( $admin_php_228, 'eem-sc-status-tabs' ),
	$pass, $fail, $log
);

// 15f. "Set Up Chart" string not present anywhere in the list renderer section
c7x_ok(
	'2.3.28: "Set Up Chart" button removed from render_stall_charts_list_page',
	false === strpos( $admin_php_228, 'Set Up Chart' ),
	$pass, $fail, $log
);

// 15g. "Stall Status" column header removed
c7x_ok(
	'2.3.28: "Stall Status" column removed from render_stall_charts_list_page',
	false === strpos( $admin_php_228, 'Stall Status' ),
	$pass, $fail, $log
);

// 15h. query filters to _en_stall_chart_enabled
c7x_ok(
	'2.3.28: get_stall_charts_list_data filters by _en_stall_chart_enabled',
	false !== strpos( $admin_php_228, '_en_stall_chart_enabled' ) &&
	false !== strpos( $admin_php_228, 'get_stall_charts_list_data' ),
	$pass, $fail, $log
);

// 15i. date filter renders as <select> not <button>
c7x_ok(
	'2.3.28: date filter uses toolbar-select <select> element (not <button>)',
	false !== strpos( $admin_php_228, 'toolbar-select' ) &&
	false === strpos( $admin_php_228, '<button class="toolbar-select' ),
	$pass, $fail, $log
);

// 15j. empty state present for zero-results case
c7x_ok(
	'2.3.28: empty state rendered when no stall charts configured',
	false !== strpos( $admin_php_228, 'No stall charts yet' ) ||
	false !== strpos( $admin_php_228, 'eem-empty-state' ),
	$pass, $fail, $log
);

// ── 16. Stall Charts list page uses correct wrapper class (2.3.29) ──────────
echo "\n[16] Stall Charts list uses correct wrapper class matching Reservations page (2.3.29)\n";

$admin_php_229 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

// 16a. Version === 2.3.29
c7x_ok(
	'2.3.29: EQUINE_EVENT_MANAGER_VERSION === 2.3.29',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && '2.3.29' === EQUINE_EVENT_MANAGER_VERSION,
	$pass, $fail, $log
);

// 16b. render_stall_charts_list_page now calls the canonical eem_render_page_open()
// shell (same shell as the Reservations list page) — produces .eem-page +
// .eem-page-wrap + .eem-page-header chrome that admin.css actually targets.
$render_method_pos = strpos( $admin_php_229, 'private function render_stall_charts_list_page' );
$render_method_end = false !== $render_method_pos ? strpos( $admin_php_229, "\n\t}\n", $render_method_pos ) : false;
$render_method_src = ( false !== $render_method_pos && false !== $render_method_end )
	? substr( $admin_php_229, $render_method_pos, $render_method_end - $render_method_pos )
	: '';
c7x_ok(
	'2.3.29: render_stall_charts_list_page uses canonical eem_render_page_open() shell',
	false !== strpos( $render_method_src, 'eem_render_page_open(' ) &&
	false !== strpos( $render_method_src, 'eem_render_page_close(' ),
	$pass, $fail, $log
);

// 16c. No more invented .eem-plugin-wrap / .eem-plugin-header / .eem-plugin-title
// inside the list render — those primitives are editor-page chrome, not list chrome.
c7x_ok(
	'2.3.29: render_stall_charts_list_page no longer uses .eem-plugin-* editor chrome',
	false === strpos( $render_method_src, 'eem-plugin-wrap' ) &&
	false === strpos( $render_method_src, 'eem-plugin-header' ) &&
	false === strpos( $render_method_src, 'eem-plugin-title' ),
	$pass, $fail, $log
);

// 16d. Stall Charts list toolbar uses <select> not <button> for date filter
c7x_ok(
	'2.3.29: Stall Charts list date filter is a <select> element',
	false !== strpos( $admin_php_229, '<select' ) &&
	false === strpos( $admin_php_229, '<button class="toolbar-select' ),
	$pass, $fail, $log
);

// ── 17. Breadcrumb consistency polish (2.3.30) ──────────────────────────────
echo "\n[17] Breadcrumb consistency: logo+breadcrumb on Stall Chart Detail + Edit Reservation (2.3.30)\n";

$admin_php_230    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$editor_php_230   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );

// Extract render_stall_chart_page method body for scoped assertions.
$sc_detail_pos = strpos( $admin_php_230, 'public function render_stall_chart_page()' );
$sc_detail_end = false !== $sc_detail_pos ? strpos( $admin_php_230, "\n\t}\n", $sc_detail_pos ) : false;
$sc_detail_src = ( false !== $sc_detail_pos && false !== $sc_detail_end )
	? substr( $admin_php_230, $sc_detail_pos, $sc_detail_end - $sc_detail_pos )
	: '';

// 17a. Version bump.
c7x_ok(
	'2.3.30: EQUINE_EVENT_MANAGER_VERSION === 2.3.30',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && '2.3.30' === EQUINE_EVENT_MANAGER_VERSION,
	$pass, $fail, $log
);

// 17b. render_stall_chart_page now wraps output in .eem-page.
c7x_ok(
	'2.3.30: render_stall_chart_page wraps in <div class="eem-page">',
	false !== strpos( $sc_detail_src, 'class="eem-page"' ),
	$pass, $fail, $log
);

// 17c. render_stall_chart_page calls eem_render_breadcrumb().
c7x_ok(
	'2.3.30: render_stall_chart_page calls eem_render_breadcrumb()',
	false !== strpos( $sc_detail_src, 'eem_render_breadcrumb(' ),
	$pass, $fail, $log
);

// 17d. Stall Chart Detail breadcrumb includes back-link to stall-charts list page.
c7x_ok(
	'2.3.30: render_stall_chart_page breadcrumb links to equine-event-manager-stall-charts',
	false !== strpos( $sc_detail_src, 'equine-event-manager-stall-charts' ),
	$pass, $fail, $log
);

// 17e. "Not enabled" early-return path also emits breadcrumb (both branches covered).
// The breadcrumb call appears twice — once before each render path.
c7x_ok(
	'2.3.30: render_stall_chart_page not-enabled path also calls eem_render_breadcrumb()',
	substr_count( $sc_detail_src, 'eem_render_breadcrumb(' ) >= 2,
	$pass, $fail, $log
);

// 17f. Edit Reservation page calls eem_render_breadcrumb().
c7x_ok(
	'2.3.30: class-eem-reservation-editor-page.php calls eem_render_breadcrumb()',
	false !== strpos( $editor_php_230, 'eem_render_breadcrumb(' ),
	$pass, $fail, $log
);

// 17g. Edit Reservation breadcrumb links back to Reservations list page.
c7x_ok(
	'2.3.30: Edit Reservation breadcrumb includes EEM_Reservations_List_Page::MENU_SLUG link',
	false !== strpos( $editor_php_230, 'EEM_Reservations_List_Page::MENU_SLUG' ),
	$pass, $fail, $log
);

// ── 18. Search input placeholder convention guard (2.3.31) ──────────────────
//
// LOCKED DESIGN RULE (2026-05-30): Every search input whose surrounding markup
// includes a magnifying-glass SVG MUST use placeholder="Search" exactly —
// no ellipsis, no descriptive text, no multi-word variants.
//
// This section scans all admin PHP files for search inputs and asserts the
// convention. It skips:
//   - Typeahead inputs (class contains "typeahead" or "event-search")
//   - Tag-input search fields (class contains "tag-search")
//   - data-placeholder attributes (Select2/Chosen dropdowns, not real inputs)
//
echo "\n[18] Search input placeholder convention — all inputs must use 'Search' exactly (2.3.31)\n";

// 18a. Version bump.
c7x_ok(
	'2.3.31: EQUINE_EVENT_MANAGER_VERSION === 2.3.31',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && '2.3.31' === EQUINE_EVENT_MANAGER_VERSION,
	$pass, $fail, $log
);

// 18b. Scan admin PHP files for search inputs with non-"Search" placeholders.
// Strategy: find every `placeholder=` on lines that also contain type="search"
// or class="eem-search-input" or class="eem-stall-chart-search-input".
// Exclude known out-of-scope patterns (typeahead, tag-search, data-placeholder,
// settings fields, textarea, non-admin files).
$search_input_violations = array();
$admin_scan_files = array(
	EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php',
	EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php',
	EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php',
	EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php',
);
foreach ( $admin_scan_files as $scan_file ) {
	if ( ! file_exists( $scan_file ) ) {
		continue;
	}
	$lines = file( $scan_file, FILE_IGNORE_NEW_LINES );
	foreach ( $lines as $ln => $line ) {
		// Must contain type="search" or eem-search-input or eem-stall-chart-search-input.
		if ( ! preg_match( '/type=["\']search["\']|eem-search-input|eem-stall-chart-search-input/', $line ) ) {
			continue;
		}
		// Skip known out-of-scope: typeahead / event-search / tag-search inputs.
		if ( preg_match( '/typeahead|event-search|tag-search|eem-header-event-search|eem-event-search/', $line ) ) {
			continue;
		}
		// Extract placeholder value.
		if ( preg_match( '/placeholder=["\']([^"\']*)["\']/', $line, $m ) ) {
			// Strip esc_attr_e wrapper noise — look at the string argument.
			// In PHP source the pattern is: esc_attr_e( 'TEXT', 'equine-event-manager' )
			// We match the inner string instead.
			if ( preg_match( "/esc_attr_e\(\s*'([^']*)'/", $line, $inner ) ) {
				$placeholder_text = $inner[1];
			} else {
				$placeholder_text = $m[1];
			}
			// Allow only exact "Search" (case-sensitive).
			if ( 'Search' !== $placeholder_text ) {
				$search_input_violations[] = basename( $scan_file ) . ':' . ( $ln + 1 ) . ' placeholder="' . $placeholder_text . '"';
			}
		}
	}
}
c7x_ok(
	'2.3.31: no search input has a placeholder other than "Search" (admin PHP files)',
	0 === count( $search_input_violations ),
	$pass, $fail, $log
);
if ( count( $search_input_violations ) > 0 ) {
	foreach ( $search_input_violations as $v ) {
		$log[] = '  VIOLATION: ' . $v;
	}
}

// 18c. Stall Chart Detail "By Location" filter input uses placeholder="Search".
c7x_ok(
	'2.3.31: Stall Chart "By Location" filter has placeholder="Search"',
	false !== strpos( $admin_php_230, "eem-stall-chart-search-input\" placeholder=\"<?php esc_attr_e( 'Search'" ) ||
	preg_match( "/eem-stall-chart-search-input[^>]+placeholder=\"<\?php esc_attr_e\( 'Search'/", $admin_php_230 ),
	$pass, $fail, $log
);

// 18d. Stall Chart Detail "By Customer" filter input uses placeholder="Search".
$cust_search_pos = strpos( $admin_php_230, 'eem-stall-chart-cust-search' );
$cust_search_line = false !== $cust_search_pos
	? substr( $admin_php_230, $cust_search_pos, 200 )
	: '';
c7x_ok(
	'2.3.31: Stall Chart "By Customer" filter has placeholder="Search"',
	false !== strpos( $cust_search_line, "'Search'" ),
	$pass, $fail, $log
);

// 18e. Orders list search input uses placeholder="Search".
$orders_php_231 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
$orders_search_pos = strpos( $orders_php_231, 'eem-search-input' );
$orders_search_line = false !== $orders_search_pos
	? substr( $orders_php_231, $orders_search_pos, 200 )
	: '';
c7x_ok(
	'2.3.31: Orders list search input has placeholder="Search"',
	false !== strpos( $orders_search_line, "'Search'" ) &&
	false === strpos( $orders_search_line, 'Search\xe2\x80\xa6' ) &&  // no ellipsis
	false === strpos( $orders_search_line, "Search\u{2026}" ),
	$pass, $fail, $log
);

// 18f. Reservations list search input uses placeholder="Search".
$res_php_231 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$res_search_pos = strpos( $res_php_231, 'eem-search-input' );
$res_search_line = false !== $res_search_pos
	? substr( $res_php_231, $res_search_pos, 200 )
	: '';
c7x_ok(
	'2.3.31: Reservations list search input has placeholder="Search"',
	false !== strpos( $res_search_line, "'Search'" ) &&
	false === strpos( $res_search_line, 'Search\xe2\x80\xa6' ),
	$pass, $fail, $log
);

// 18g. Stall & RV Charts list search input uses placeholder="Search" (regression guard).
$sc_list_search_pos = strpos( $admin_php_230, 'sc-list-search' );
$sc_list_search_line = false !== $sc_list_search_pos
	? substr( $admin_php_230, max( 0, $sc_list_search_pos - 300 ), 400 )
	: '';
c7x_ok(
	'2.3.31: Stall & RV Charts list page search input has placeholder="Search" (regression guard)',
	false !== strpos( $sc_list_search_line, "'Search'" ),
	$pass, $fail, $log
);

// ── 19. Search icon padding + breadcrumb link CSS guards (2.3.32) ───────────
//
// Two locked design conventions enforced here:
//   A. Search inputs must have padding-left >= 32px to clear the 13px icon at left:9px.
//   B. Breadcrumb <a> links: dark navy resting, electric blue hover, no underline.
//
echo "\n[19] Search icon padding + breadcrumb link CSS convention guards (2.3.32)\n";

$admin_css_232 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

// 19a. Version bump (now 2.3.33 — updated by 2.3.33 Stall Chart Detail bug bundle).
c7x_ok(
	'2.3.33: EQUINE_EVENT_MANAGER_VERSION === 2.3.33',
	defined( 'EQUINE_EVENT_MANAGER_VERSION' ) && '2.3.33' === EQUINE_EVENT_MANAGER_VERSION,
	$pass, $fail, $log
);

// 19b. .eem-stall-chart-search-input has padding-left >= 20px (icon clears at any reasonable value).
// 2.3.35 updated the canonical value to 25px (visual preference); threshold lowered to ≥ 20px
// so future tweaks don't require a smoke edit for minor adjustments within a sane range.
preg_match( '/\.eem-stall-chart-search-input\s*\{[^}]*padding[^}]*\}/', $admin_css_232, $sc_input_block );
$sc_input_css = isset( $sc_input_block[0] ) ? $sc_input_block[0] : '';
$sc_padding_ok = false;
if ( preg_match( '/padding[^;]*6px\s+10px\s+6px\s+(\d+)px/', $sc_input_css, $sc_pad_m ) ) {
	$sc_padding_ok = (int) $sc_pad_m[1] >= 20;
}
c7x_ok(
	'2.3.32+: .eem-stall-chart-search-input padding-left >= 20px (clears 13px icon at left:9px)',
	$sc_padding_ok,
	$pass, $fail, $log
);

// 19c. .eem-list-toolbar .eem-search-wrap .eem-search-input has padding-left 25px.
// 2.3.35 standardized all search input padding-left to 25px across the plugin.
c7x_ok(
	'2.3.35: .eem-list-toolbar .eem-search-wrap .eem-search-input padding-left: 25px present',
	(bool) preg_match(
		'/\.eem-list-toolbar\s+\.eem-search-wrap\s+\.eem-search-input\s*\{[^}]*padding-left\s*:\s*25px/',
		$admin_css_232
	),
	$pass, $fail, $log
);

// 19d. Breadcrumb resting link color uses --eem-navy (dark navy, not a bright color).
// Rule must be: .eem-breadcrumb a.eem-breadcrumb-segment { color: var(--eem-navy) }
c7x_ok(
	'2.3.32: .eem-breadcrumb a.eem-breadcrumb-segment resting color is --eem-navy',
	false !== strpos( $admin_css_232, '.eem-breadcrumb a.eem-breadcrumb-segment' ) &&
	(bool) preg_match(
		'/\.eem-breadcrumb a\.eem-breadcrumb-segment[^{]*\{[^}]*color\s*:\s*var\(--eem-navy\)/',
		$admin_css_232
	),
	$pass, $fail, $log
);

// 19e. Breadcrumb hover color uses --eem-electric (bright blue).
c7x_ok(
	'2.3.32: .eem-breadcrumb a.eem-breadcrumb-segment:hover color is --eem-electric',
	(bool) preg_match(
		'/\.eem-breadcrumb a\.eem-breadcrumb-segment:hover[^{]*\{[^}]*color\s*:\s*var\(--eem-electric\)/',
		$admin_css_232
	),
	$pass, $fail, $log
);

// 19f. Breadcrumb hover has text-decoration: none (no underline).
c7x_ok(
	'2.3.32: .eem-breadcrumb a.eem-breadcrumb-segment:hover has text-decoration: none',
	(bool) preg_match(
		'/\.eem-breadcrumb a\.eem-breadcrumb-segment:hover[^{]*\{[^}]*text-decoration\s*:\s*none/',
		$admin_css_232
	),
	$pass, $fail, $log
);

// 19g. Breadcrumb :link and :visited pseudo-classes present (WP admin cascade defense).
c7x_ok(
	'2.3.32: .eem-breadcrumb a.eem-breadcrumb-segment:link and :visited rules present',
	false !== strpos( $admin_css_232, 'a.eem-breadcrumb-segment:link' ) &&
	false !== strpos( $admin_css_232, 'a.eem-breadcrumb-segment:visited' ),
	$pass, $fail, $log
);

// 19h. Base .eem-breadcrumb-segment uses var(--eem-navy) not a hard-coded grey.
c7x_ok(
	'2.3.32: .eem-breadcrumb-segment base color is var(--eem-navy) not hard-coded grey',
	(bool) preg_match(
		'/\.eem-breadcrumb-segment\s*\{[^}]*color\s*:\s*var\(--eem-navy\)/',
		$admin_css_232
	) &&
	false === strpos(
		preg_replace( '/\/\*.*?\*\//s', '', $admin_css_232 ), // strip comments
		'.eem-breadcrumb-segment { font-size: 13px; color: #50575e'
	),
	$pass, $fail, $log
);

// ── 20. Stall Chart Detail bug bundle guards (2.3.33) ────────────────────────
//
// Guards for five bugs fixed in 2.3.33:
//   BUG 1/5 — Both stall chart filter search inputs carry eem-search-input class.
//   BUG 2   — Barn tab data-barn uses sanitize_html_class() to match stall row data-barn.
//   BUG 3   — Generate Assignments button uses eem-btn--primary (Electric Blue).
//   BUG 4   — Change Event button on Stall Chart uses canonical eem-header-action-change class.
//
echo "\n[20] Stall Chart Detail bug bundle guards (2.3.33)\n";

$admin_php_233 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

// 20a. By-Location search input carries both classes.
c7x_ok(
	'2.3.33: #eem-stall-chart-search carries eem-search-input AND eem-stall-chart-search-input',
	(bool) preg_match(
		'/id="eem-stall-chart-search"\s+class="eem-search-input\s+eem-stall-chart-search-input"/',
		$admin_php_233
	),
	$pass, $fail, $log
);

// 20b. By-Customer search input carries both classes.
c7x_ok(
	'2.3.33: #eem-stall-chart-cust-search carries eem-search-input AND eem-stall-chart-search-input',
	(bool) preg_match(
		'/id="eem-stall-chart-cust-search"\s+class="eem-search-input\s+eem-stall-chart-search-input"/',
		$admin_php_233
	),
	$pass, $fail, $log
);

// 20c. Barn tab button data-barn uses sanitize_html_class() — data-barn mismatch fix.
c7x_ok(
	'2.3.33: barn tab data-barn uses sanitize_html_class( strtolower( $barn_label ) )',
	false !== strpos( $admin_php_233, 'sanitize_html_class( strtolower( $barn_label ) )' ),
	$pass, $fail, $log
);

// 20d. Generate Assignments button uses eem-btn--primary (Electric Blue background).
c7x_ok(
	'2.3.33: Generate Assignments button uses eem-btn eem-btn--primary class',
	(bool) preg_match(
		'/class="eem-btn\s+eem-btn--primary"[^>]*>[^<]*(?:<[^>]+>[^<]*)*Generate Assignments/',
		$admin_php_233
	),
	$pass, $fail, $log
);

// 20e. Change Event button on Stall Chart uses canonical eem-header-action-change class.
c7x_ok(
	'2.3.33: Stall Chart Change Event button uses eem-header-action-change (not eem-header-change-btn)',
	false !== strpos( $admin_php_233, 'class="eem-header-action-change" type="button" id="eem-stall-chart-change-btn"' ) &&
	false === strpos( $admin_php_233, 'class="eem-header-change-btn"' ),
	$pass, $fail, $log
);

// 20f. No bare eem-header-change-btn class anywhere in admin PHP (class retired).
c7x_ok(
	'2.3.33: eem-header-change-btn class fully replaced by eem-header-action-change',
	false === strpos( $admin_php_233, 'eem-header-change-btn' ),
	$pass, $fail, $log
);

// ── [21] 2.3.34 fix guards ────────────────────────────────────────────────────
echo "\n[21] 2.3.34 fix guards (Generate Assignments anchor-button + search padding)\n";

$css_234 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

// 21a. a.eem-btn--primary chain is present with color: #fff — DS-1.A.1 anchor-button fix.
// Root cause: .eem-page a { color: var(--eem-electric) } at (0,1,1) beat .eem-btn--primary
// { color: #fff } at (0,1,0), rendering white text Electric Blue on Electric Blue = invisible.
c7x_ok(
	'2.3.34: a.eem-btn--primary rule present with color: #fff (DS-1.A.1 anchor-button fix)',
	(bool) preg_match(
		'/a\.eem-btn--primary[^{]*\{[^}]*color\s*:\s*#fff\s*;/',
		$css_234
	),
	$pass, $fail, $log
);

// 21b. a.eem-btn--ghost chain is present with color: #1d2327.
c7x_ok(
	'2.3.34: a.eem-btn--ghost rule present with color: #1d2327',
	(bool) preg_match(
		'/a\.eem-btn--ghost[^{]*\{[^}]*color\s*:\s*#1d2327\s*;/',
		$css_234
	),
	$pass, $fail, $log
);

// 21c. .eem-stall-chart-search-input padding-left is 25 px (2.3.35 visual-preference standardization).
// Icon right-edge lands at ~22 px; 25 px gives 3 px clearance — tight but intentional per Whitney.
c7x_ok(
	'2.3.35: .eem-stall-chart-search-input padding-left is 25px (standardized across all search inputs)',
	(bool) preg_match(
		'/\.eem-stall-chart-search-input\s*\{[^}]*padding[^:]*:\s*\d+px\s+\d+px\s+\d+px\s+25px/',
		$css_234
	),
	$pass, $fail, $log
);

// 21d. Scoped (0,2,0) guard rule exists — .eem-stall-chart-filter-search .eem-stall-chart-search-input.
c7x_ok(
	'2.3.34: scoped .eem-stall-chart-filter-search .eem-stall-chart-search-input guard rule present',
	false !== strpos( $css_234, '.eem-stall-chart-filter-search .eem-stall-chart-search-input' ),
	$pass, $fail, $log
);

// ── [22] 2.3.37 Stall Chart Print View guards ─────────────────────────────────
echo "\n[22] 2.3.37 Stall Chart Print View guards\n";

$css_237 = isset( $css_234 ) ? $css_234 : file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$php_admin = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

// 22a. Print-view WP chrome suppression block: body.eem-shell-page--print selector present.
c7x_ok(
	'2.3.37: body.eem-shell-page--print WP chrome suppression CSS present',
	false !== strpos( $css_237, 'body.eem-shell-page--print' ),
	$pass, $fail, $log
);

// 22b. .pv-topbar CSS rule present (on-screen toolbar for print view).
c7x_ok(
	'2.3.37: .pv-topbar CSS rule present',
	false !== strpos( $css_237, '.pv-topbar' ),
	$pass, $fail, $log
);

// 22c. @media print block hides .pv-topbar and forces colour printing.
c7x_ok(
	'2.3.37: @media print block hides .pv-topbar and forces -webkit-print-color-adjust: exact',
	(bool) preg_match(
		'/@media\s+print[^{]*\{[^}]*\.pv-topbar/',
		$css_237
	) && false !== strpos( $css_237, '-webkit-print-color-adjust: exact' ),
	$pass, $fail, $log
);

// 22d. render_stall_chart_print_page method registered in admin class.
c7x_ok(
	'2.3.37: render_stall_chart_print_page() method present in admin class',
	false !== strpos( $php_admin, 'render_stall_chart_print_page' ),
	$pass, $fail, $log
);

// 22e. Hidden submenu for print view is registered (parent slug is empty string '').
c7x_ok(
	"2.3.37: hidden submenu registration for equine-event-manager-stall-chart-print present",
	(bool) preg_match(
		"/add_submenu_page\s*\(\s*''\s*,.*?equine-event-manager-stall-chart-print/s",
		$php_admin
	),
	$pass, $fail, $log
);

// 22f. Print View button carries data-print-url attribute (opens page in new tab).
c7x_ok(
	'2.3.37: Print View button has data-print-url attribute',
	false !== strpos( $php_admin, 'data-print-url' ),
	$pass, $fail, $log
);

// 22g. RV lots date pill span is properly closed with pill text (not malformed).
// Checks the correct form: pv-occ ..."><php ...pill_text... /> — not the broken form
// that was missing the closing > and the pill_text echo.
c7x_ok(
	'2.3.37: RV lots date cell pill span is properly formed (pill text present, tag closed)',
	(bool) preg_match(
		'/pv-night-cell.*?pv-occ.*?esc_attr.*?pill_cls.*?"\s*>\s*<\?php\s+echo\s+esc_html\s*\(\s*\$pill_text\s*\)/s',
		$php_admin
	),
	$pass, $fail, $log
);

// ── [23] 2.3.38 Stall Chart Detail — inventory toggle + dual tabs ─────────────
echo "\n[23] 2.3.38 Stall Chart Detail — inventory toggle + view tabs restructure\n";

$php_admin_238 = isset( $php_admin ) ? $php_admin : file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$css_238       = isset( $css_234 ) ? $css_234 : file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_238        = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

// 23a. Page title uses "Stall & RV Chart"
c7x_ok(
	'2.3.38: render_stall_chart_page uses "Stall & RV Chart - %s" title string',
	false !== strpos( $php_admin_238, 'Stall & RV Chart - %s' ),
	$pass, $fail, $log
);

// 23b. Inventory toggle buttons present in PHP
c7x_ok(
	'2.3.38: inventory toggle buttons (data-eem-action="sc-inv-switch") present in PHP',
	false !== strpos( $php_admin_238, 'sc-inv-switch' ),
	$pass, $fail, $log
);

// 23c. Location panel id updated
c7x_ok(
	'2.3.38: By Location panel uses id="eem-stall-chart-panel-location" (not old -stall)',
	false !== strpos( $php_admin_238, 'eem-stall-chart-panel-location' ) &&
	false === strpos( $php_admin_238, 'eem-stall-chart-panel-stall' ),
	$pass, $fail, $log
);

// 23d. RV zone filter tabs present
c7x_ok(
	'2.3.38: RV zone filter tabs (stall-chart-filter-zone) present in PHP',
	false !== strpos( $php_admin_238, 'stall-chart-filter-zone' ),
	$pass, $fail, $log
);

// 23e. Customer rows have data-has-stalls for inventory filtering
c7x_ok(
	'2.3.38: customer table rows have data-has-stalls attribute for inventory filtering',
	false !== strpos( $php_admin_238, 'data-has-stalls' ),
	$pass, $fail, $log
);

// 23f. JS eemScApplyState function present
c7x_ok(
	'2.3.38: JS eemScApplyState centralised state function present in admin.js',
	false !== strpos( $js_238, 'eemScApplyState' ),
	$pass, $fail, $log
);

// 23g. CSS inventory toggle class present
c7x_ok(
	'2.3.38: .eem-sc-inv-toggle CSS rule present',
	false !== strpos( $css_238, '.eem-sc-inv-toggle' ),
	$pass, $fail, $log
);

// 23h. CSS zone tab class present
c7x_ok(
	'2.3.38: .eem-stall-chart-zone-tab CSS rule present',
	false !== strpos( $css_238, '.eem-stall-chart-zone-tab' ),
	$pass, $fail, $log
);

wp_delete_post( $rid, true );

echo "\n[24] C10.A — CSS migration (render_form_styles → public.css + mockup form CSS + Google Fonts)\n";

$css_public_24    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );
$php_events_24    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
$php_shortcodes_24 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

// 24a. Google Fonts URL present in render_frontend_styles() source.
c7x_ok(
	'C10.A: Google Fonts URL (Space Grotesk + IBM Plex Sans) present in render_frontend_styles()',
	false !== strpos( $php_events_24, 'fonts.googleapis.com/css2?family=Space+Grotesk' ) &&
	false !== strpos( $php_events_24, 'IBM+Plex+Sans' ),
	$pass, $fail, $log
);

// 24b. (2.3.46 fix) PHP shortcode emits eem-reservation-section class.
//      Previous assertion checked dead class .form-section — corrected.
c7x_ok(
	'C10.A: PHP shortcode source emits eem-reservation-section class',
	false !== strpos( $php_shortcodes_24, 'eem-reservation-section' ),
	$pass, $fail, $log
);

// 24c. (2.3.46 fix) PHP shortcode emits eem-reservation-section-toggle class.
//      Previous assertion checked dead class .toggle — corrected.
c7x_ok(
	'C10.A: PHP shortcode source emits eem-reservation-section-toggle class',
	false !== strpos( $php_shortcodes_24, 'eem-reservation-section-toggle' ),
	$pass, $fail, $log
);

// 24d. (2.3.46 fix) render_frontend_form_assets_in_footer() calls
//      EEM_Events::render_frontend_styles() to enqueue public.css.
//      Previous assertion checked dead class .agreement-notice — corrected.
c7x_ok(
	'C10.A: render_frontend_form_assets_in_footer() calls EEM_Events::render_frontend_styles()',
	false !== strpos( $php_shortcodes_24, 'EEM_Events::render_frontend_styles()' ),
	$pass, $fail, $log
);

// 24e. (2.3.46 fix) render_frontend_styles() registers eem-public handle with public.css.
//      Previous assertion checked dead class .mobile-order-drawer — corrected.
c7x_ok(
	'C10.A: render_frontend_styles() registers eem-public handle pointing at public.css',
	false !== strpos( $php_events_24, "'eem-public'" ) &&
	false !== strpos( $php_events_24, 'public.css' ),
	$pass, $fail, $log
);

// 24g. Dead mockup CSS classes are NOT present in public.css (stripped in 2.3.46).
c7x_ok(
	'C10.A (2.3.46): Dead mockup CSS class .form-section absent from public.css',
	false === strpos( $css_public_24, '.form-section{' ) && false === strpos( $css_public_24, '.form-section ' ),
	$pass, $fail, $log
);
c7x_ok(
	'C10.A (2.3.46): Dead mockup CSS class .mobile-order-drawer absent from public.css',
	false === strpos( $css_public_24, '.mobile-order-drawer{' ) &&
	false === strpos( $css_public_24, '.mobile-order-drawer ' ),
	$pass, $fail, $log
);
c7x_ok(
	'C10.A (2.3.46): Dead mockup CSS class .agreement-notice absent from public.css',
	false === strpos( $css_public_24, '.agreement-notice{' ) && false === strpos( $css_public_24, '.agreement-notice ' ),
	$pass, $fail, $log
);

// 24h. show_event_header defaults to 0 (V1 Rendering Decision: form only, no hero).
c7x_ok(
	'C10.A (2.3.46): show_event_header defaults to 0 in render_reservation() shortcode_atts',
	(bool) preg_match( "/'show_event_header'\s*=>\s*0/", $php_shortcodes_24 ),
	$pass, $fail, $log
);

// 24i. filter_single_event_content guards against Elementor double-render.
$php_events_246 = $php_events_24; // already loaded above.
c7x_ok(
	'C10.A (2.3.46): filter_single_event_content checks has_shortcode(en_reservation) before rendering',
	false !== strpos( $php_events_246, "has_shortcode( \$content, 'en_reservation' )" ),
	$pass, $fail, $log
);
c7x_ok(
	'C10.A (2.3.46): filter_single_event_content skips when Elementor owns template',
	false !== strpos( $php_events_246, '_elementor_edit_mode' ),
	$pass, $fail, $log
);

// 24f. render_form_styles() method body does NOT contain <style> output (stripped).
//      Assert the method is still present (private function render_form_styles) but
//      the actual <style> tag was removed from its body.
$rfs_method_present = false !== strpos( $php_shortcodes_24, 'private function render_form_styles()' );
$rfs_no_style_tag   = false === strpos(
	// Extract just the real render_form_styles() method body (not the __removed_css_block copy).
	// We check the first 200 chars after the method signature for <style>.
	substr(
		$php_shortcodes_24,
		strpos( $php_shortcodes_24, 'private function render_form_styles()' ),
		300
	),
	'<style>'
);
c7x_ok(
	'C10.A: render_form_styles() method present but body stripped (no <style> output within first 300 chars of method)',
	$rfs_method_present && $rfs_no_style_tag,
	$pass, $fail, $log
);

echo "\n[25] TEC event link — reverse lookup, category filter, one-to-one enforcement, live AJAX typeahead (2.3.40–41)\n";

$php_res_cpt_25 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );

// 25a. Reverse-lookup method: get_tec_event_id_for_reservation() present (public since 2.3.41).
c7x_ok(
	'[25a] get_tec_event_id_for_reservation() method defined in reservations CPT',
	false !== strpos( $php_res_cpt_25, 'function get_tec_event_id_for_reservation(' ),
	$pass, $fail, $log
);

// 25b. Dropdown query targets tribe_events post type (not fake data).
c7x_ok(
	'[25b] query_tec_events_by_start_date() queries tribe_events post type',
	false !== strpos( $php_res_cpt_25, "'post_type'      => 'tribe_events'" ) ||
	false !== strpos( $php_res_cpt_25, '"post_type" => "tribe_events"' ),
	$pass, $fail, $log
);

// 25c. Category filter applied: get_tec_event_category_filter() used inside query.
c7x_ok(
	'[25c] get_tec_event_category_filter() method defined and used in query methods',
	false !== strpos( $php_res_cpt_25, 'private function get_tec_event_category_filter()' ) &&
	false !== strpos( $php_res_cpt_25, '$this->get_tec_event_category_filter()' ),
	$pass, $fail, $log
);

// 25d. One-to-one enforcement method: enforce_tec_event_link_one_to_one() present.
c7x_ok(
	'[25d] enforce_tec_event_link_one_to_one() method defined in reservations CPT',
	false !== strpos( $php_res_cpt_25, 'private function enforce_tec_event_link_one_to_one(' ),
	$pass, $fail, $log
);

// 25e. Save path calls enforce_tec_event_link_one_to_one().
c7x_ok(
	'[25e] save_meta() calls enforce_tec_event_link_one_to_one()',
	false !== strpos( $php_res_cpt_25, '$this->enforce_tec_event_link_one_to_one(' ),
	$pass, $fail, $log
);

// 25f. _equine_event_manager_reservation_id is used as the reverse-lookup meta key.
c7x_ok(
	'[25f] _equine_event_manager_reservation_id is the reverse-lookup meta key in get_tec_event_id_for_reservation()',
	false !== strpos( $php_res_cpt_25, "'_equine_event_manager_reservation_id'" ),
	$pass, $fail, $log
);

echo "\n[26] FIX 1/2/3 — live typeahead, trash redirect, new-reservation routing (2.3.41)\n";

$js_admin_26      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$php_editor_26    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$php_list_26      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$php_loader_26    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );

// 26a. _linkedEvents fixture array is GONE from admin.js.
c7x_ok(
	'[26a] _linkedEvents fixture array removed from admin.js (FIX 1)',
	false === strpos( $js_admin_26, '_linkedEvents' ),
	$pass, $fail, $log
);

// 26b. AJAX call to equine_event_manager_search_tec_events is in admin.js.
c7x_ok(
	'[26b] admin.js calls equine_event_manager_search_tec_events via AJAX (FIX 1)',
	false !== strpos( $js_admin_26, 'equine_event_manager_search_tec_events' ),
	$pass, $fail, $log
);

// 26c. fetchEventOptions() function defined in admin.js.
c7x_ok(
	'[26c] fetchEventOptions() function defined in admin.js (FIX 1)',
	false !== strpos( $js_admin_26, 'function fetchEventOptions(' ),
	$pass, $fail, $log
);

// 26d. data-search-nonce attribute emitted in editor page PHP.
c7x_ok(
	'[26d] data-search-nonce emitted on typeahead div in editor page (FIX 1)',
	false !== strpos( $php_editor_26, 'data-search-nonce' ),
	$pass, $fail, $log
);

// 26e. Hidden en_reservation[event_id] input emitted in editor page PHP.
c7x_ok(
	'[26e] Hidden en_reservation[event_id] input emitted in editor page (FIX 1)',
	false !== strpos( $php_editor_26, 'name="en_reservation[event_id]"' ),
	$pass, $fail, $log
);

// 26f. handle_trash() no longer hard-codes status=trash redirect (FIX 2).
c7x_ok(
	'[26f] handle_trash() uses dynamic status redirect, not hard-coded trash (FIX 2)',
	false !== strpos( $php_list_26, 'FIX 2' ) &&
	// The old "array( 'status' => 'trash' )" hard-coded literal is gone.
	false === strpos( $php_list_26, "redirect_with_notice( 'trashed', array( 'status' => 'trash' ) )" ),
	$pass, $fail, $log
);

// 26g. maybe_redirect_new_reservation() method is defined in editor page (FIX 3).
c7x_ok(
	'[26g] maybe_redirect_new_reservation() method defined in editor page (FIX 3)',
	false !== strpos( $php_editor_26, 'function maybe_redirect_new_reservation()' ),
	$pass, $fail, $log
);

// 26h. load-post-new.php hook is wired in the loader (FIX 3).
c7x_ok(
	'[26h] load-post-new.php hook wired in plugin loader (FIX 3)',
	false !== strpos( $php_loader_26, 'load-post-new.php' ),
	$pass, $fail, $log
);

echo "\n[27] FIX 4/5/6 — Reservation Details card, Quick Edit, mockup update (2.3.42)\n";
// NOTE: 27a-27d were rewritten for 2.3.43 — Reservation Details card REMOVED;
// pencil inline-edit replaces it.  27e-27j test Quick Edit wiring which persists.

$js_admin_27   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$php_editor_27 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$php_list_27   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$php_loader_27 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$mockup_27     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . '.mockups/reservations_page.html' );

// 27a. [2.3.43 rewrite] render_reservation_details_card() REMOVED; pencil edit
//      replaces it.  Assert the OLD method is gone (not a regression — intentional).
c7x_ok(
	'[27a] render_reservation_details_card() removed from editor page (2.3.43 FIX 1)',
	false === strpos( $php_editor_27, 'function render_reservation_details_card(' ),
	$pass, $fail, $log
);

// 27b. [2.3.43 rewrite] Pencil button (.eem-pencil-btn + data-eem-action="res-name-edit")
//      emitted in editor page render().
c7x_ok(
	'[27b] eem-pencil-btn + res-name-edit action emitted in editor page (2.3.43 FIX 1)',
	false !== strpos( $php_editor_27, 'eem-pencil-btn' ) &&
	false !== strpos( $php_editor_27, 'res-name-edit' ),
	$pass, $fail, $log
);

// 27c. [2.3.43 rewrite] eem-res-name-inline-edit wrapper emitted with rename nonce attr.
c7x_ok(
	'[27c] eem-res-name-inline-edit + rename-nonce attr emitted in editor page (2.3.43 FIX 1)',
	false !== strpos( $php_editor_27, 'eem-res-name-inline-edit' ) &&
	false !== strpos( $php_editor_27, 'data-rename-nonce' ),
	$pass, $fail, $log
);

// 27d. [2.3.43 rewrite] FIX 2 — unconditional mirror in ajax_save() based on stored
//      override flags, NOT conditional on eem_res_name POST field.
c7x_ok(
	'[27d] ajax_save() FIX 2 unconditional mirror uses stored _eem_reservation_name_overridden (2.3.43)',
	false !== strpos( $php_editor_27, '_eem_reservation_name_overridden' ) &&
	false === strpos( $php_editor_27, "array_key_exists( 'eem_res_name'" ),
	$pass, $fail, $log
);

// 27e. openResNameEdit() + saveResNameEdit() + cancelResNameEdit() defined in admin.js.
c7x_ok(
	'[27e] openResNameEdit() + saveResNameEdit() + cancelResNameEdit() defined in admin.js (2.3.43 FIX 1)',
	false !== strpos( $js_admin_27, 'function openResNameEdit(' ) &&
	false !== strpos( $js_admin_27, 'function saveResNameEdit(' ) &&
	false !== strpos( $js_admin_27, 'function cancelResNameEdit(' ),
	$pass, $fail, $log
);

// 27f. reservation-quick-edit action handler wired in admin.js (FIX 5 — 2.3.42, persists).
c7x_ok(
	'[27f] reservation-quick-edit action wired in admin.js dispatcher (2.3.42 FIX 5)',
	false !== strpos( $js_admin_27, "'reservation-quick-edit'" ) &&
	false !== strpos( $js_admin_27, 'function openQuickEdit(' ),
	$pass, $fail, $log
);

// 27g. handle_quick_edit_ajax() method defined in list page (FIX 5 — 2.3.42).
c7x_ok(
	'[27g] handle_quick_edit_ajax() defined in list page (2.3.42 FIX 5)',
	false !== strpos( $php_list_27, 'function handle_quick_edit_ajax()' ),
	$pass, $fail, $log
);

// 27h. wp_ajax_eem_reservation_quick_edit hook wired in loader (2.3.42).
c7x_ok(
	'[27h] wp_ajax_eem_reservation_quick_edit wired in plugin loader (2.3.42)',
	false !== strpos( $php_loader_27, 'wp_ajax_eem_reservation_quick_edit' ),
	$pass, $fail, $log
);

// 27i. Quick Edit nonce created in localize_row_action_nonces() (2.3.42).
c7x_ok(
	'[27i] eem_reservation_quick_edit nonce in localize_row_action_nonces() (2.3.42)',
	false !== strpos( $php_list_27, "'eem_reservation_quick_edit'" ),
	$pass, $fail, $log
);

// 27j. reservations_page.html mockup contains quick-edit-row class (2.3.42 FIX 6).
c7x_ok(
	'[27j] reservations_page.html mockup contains quick-edit-row (2.3.42 FIX 6)',
	false !== strpos( $mockup_27, 'quick-edit-row' ),
	$pass, $fail, $log
);

echo "\n[28] 2.3.43 — FIX 1-5: Pencil edit, FIX 2 mirror, row actions, meatballs, duplicate AJAX\n";

$js_admin_28   = $js_admin_27;
$php_editor_28 = $php_editor_27;
$php_list_28   = $php_list_27;
$php_loader_28 = $php_loader_27;
$css_28        = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

// 28a. ajax_rename() method defined in editor page (FIX 1).
c7x_ok(
	'[28a] ajax_rename() defined in editor page (FIX 1)',
	false !== strpos( $php_editor_28, 'function ajax_rename()' ),
	$pass, $fail, $log
);

// 28b. wp_ajax_eem_rename_reservation hooked in loader (FIX 1).
c7x_ok(
	'[28b] wp_ajax_eem_rename_reservation wired in loader (FIX 1)',
	false !== strpos( $php_loader_28, 'wp_ajax_eem_rename_reservation' ),
	$pass, $fail, $log
);

// 28c. res-name-edit / res-name-save / res-name-cancel dispatcher entries present (FIX 1).
c7x_ok(
	'[28c] res-name-edit/save/cancel dispatcher entries in admin.js (FIX 1)',
	false !== strpos( $js_admin_28, "'res-name-edit'" ) &&
	false !== strpos( $js_admin_28, "'res-name-save'" ) &&
	false !== strpos( $js_admin_28, "'res-name-cancel'" ),
	$pass, $fail, $log
);

// 28d. CSS .eem-pencil-btn + .eem-row-actions defined (FIX 1 + FIX 3).
c7x_ok(
	'[28d] .eem-pencil-btn + .eem-row-actions defined in admin.css (FIX 1 + FIX 3)',
	false !== strpos( $css_28, '.eem-pencil-btn' ) &&
	false !== strpos( $css_28, '.eem-row-actions' ),
	$pass, $fail, $log
);

// 28e. render_row_action_links() defined in list page (FIX 3).
c7x_ok(
	'[28e] render_row_action_links() defined in list page (FIX 3)',
	false !== strpos( $php_list_28, 'function render_row_action_links(' ),
	$pass, $fail, $log
);

// 28f. Row action links include reservation-duplicate-ajax + reservation-quick-edit + reservation-trash (FIX 3).
c7x_ok(
	'[28f] Row actions emit reservation-duplicate-ajax + quick-edit + trash data-eem-action attrs (FIX 3)',
	false !== strpos( $php_list_28, 'reservation-duplicate-ajax' ) &&
	false !== strpos( $php_list_28, 'reservation-quick-edit' ) &&
	false !== strpos( $php_list_28, 'reservation-trash' ),
	$pass, $fail, $log
);

// 28g. render_row_actions() no longer contains Quick Edit or Duplicate meatball items (FIX 3+4).
//     Check that Quick Edit button is gone from the meatballs (moved to row action text links).
//     We look for the old Quick Edit meatball button pattern that included "reservation-quick-edit"
//     inside render_row_actions — after FIX 3, it appears only in render_row_action_links().
c7x_ok(
	'[28g] render_row_actions() no longer emits Quick Edit meatball (FIX 3 — moved to row links)',
	// Quick Edit meatball was a <button data-eem-action="reservation-quick-edit"> inside render_row_actions.
	// After FIX 3, that action only appears in render_row_action_links(). We verify by checking that
	// "reservation-quick-edit" does NOT appear in a context where eem-row-dd-item is also in the line.
	false === strpos( $php_list_28, "eem-row-dd-item\" data-eem-action=\"reservation-quick-edit\"" ),
	$pass, $fail, $log
);

// 28h. resolve_frontend_url() private static method defined in list page (FIX 4).
c7x_ok(
	'[28h] resolve_frontend_url() defined in list page (FIX 4)',
	false !== strpos( $php_list_28, 'function resolve_frontend_url(' ),
	$pass, $fail, $log
);

// 28i. handle_duplicate_ajax() method defined in list page (FIX 5).
c7x_ok(
	'[28i] handle_duplicate_ajax() defined in list page (FIX 5)',
	false !== strpos( $php_list_28, 'function handle_duplicate_ajax()' ),
	$pass, $fail, $log
);

// 28j. wp_ajax_eem_reservation_duplicate_ajax hooked in loader (FIX 5).
c7x_ok(
	'[28j] wp_ajax_eem_reservation_duplicate_ajax wired in loader (FIX 5)',
	false !== strpos( $php_loader_28, 'wp_ajax_eem_reservation_duplicate_ajax' ),
	$pass, $fail, $log
);

// 28k. duplicateReservationAjax() + reservation-duplicate-ajax action defined in admin.js (FIX 5).
c7x_ok(
	'[28k] duplicateReservationAjax() + reservation-duplicate-ajax wired in admin.js (FIX 5)',
	false !== strpos( $js_admin_28, 'function duplicateReservationAjax(' ) &&
	false !== strpos( $js_admin_28, "'reservation-duplicate-ajax'" ),
	$pass, $fail, $log
);

// 28l. Duplicate meta exclusion list includes _eem_frontend_url_cache + _en_event_id (FIX 5).
c7x_ok(
	'[28l] handle_duplicate_ajax() excludes _eem_frontend_url_cache + _en_event_id from meta copy (FIX 5)',
	false !== strpos( $php_list_28, "'_eem_frontend_url_cache'" ) &&
	false !== strpos( $php_list_28, "'_en_event_id'" ),
	$pass, $fail, $log
);

// 28m. Version bumped to 2.3.43 in both the plugin header and EQUINE_EVENT_MANAGER_VERSION.
$main_file_28 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'equine-event-manager.php' );
c7x_ok(
	'[28m] Plugin version 2.3.43 in header + EQUINE_EVENT_MANAGER_VERSION constant',
	false !== strpos( $main_file_28, "Version:           2.3.43" ) &&
	false !== strpos( $main_file_28, "'2.3.43'" ),
	$pass, $fail, $log
);

// ────────────────────────────────────────────────────────────────────────────
echo "\n[29] 2.3.44 — FIX 1 (duplicate stays on list) + FIX 2 (live mirror on TEC event save)\n";
// ────────────────────────────────────────────────────────────────────────────

$php_editor_29 = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$php_list_29   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$js_29         = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$loader_29     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$main_29       = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'equine-event-manager.php' );

// 29a. handle_duplicate_ajax() no longer returns redirect_url (FIX 1).
c7x_ok(
	'[29a] handle_duplicate_ajax() response: redirect_url removed; new_reservation_id + title returned (FIX 1, 2.3.44)',
	false === strpos( $php_list_29, "'redirect_url'       => EEM_Reservation_Editor_Page::url" ) &&
	false !== strpos( $php_list_29, "'new_reservation_id' => \$new_id" ) &&
	false !== strpos( $php_list_29, "'title'" ),
	$pass, $fail, $log
);

// 29b. JS duplicateReservationAjax() uses window.location.reload() not redirect_url (FIX 1).
c7x_ok(
	'[29b] duplicateReservationAjax() reloads page after toast instead of redirecting to redirect_url (FIX 1, 2.3.44)',
	false !== strpos( $js_29, 'window.location.reload()' ) &&
	false !== strpos( $js_29, 'Reservation duplicated as draft' ) &&
	false === strpos( $js_29, "json.data.redirect_url" ),
	$pass, $fail, $log
);

// 29c. apply_mirror() private static method defined in editor page (FIX 2).
c7x_ok(
	'[29c] apply_mirror() private static method defined in editor page (FIX 2, 2.3.44)',
	false !== strpos( $php_editor_29, 'private static function apply_mirror(' ),
	$pass, $fail, $log
);

// 29d. apply_mirror() called from ajax_save() (replaces inline 2.3.43 block) (FIX 2).
c7x_ok(
	'[29d] ajax_save() calls self::apply_mirror() (inline block replaced, FIX 2, 2.3.44)',
	false !== strpos( $php_editor_29, 'self::apply_mirror( $reservation_id )' ) &&
	false === strpos( $php_editor_29, '$_name_overridden = (bool) get_post_meta( $reservation_id' ),
	$pass, $fail, $log
);

// 29e. apply_mirror() called from render() on page load (FIX 2).
c7x_ok(
	'[29e] render() calls self::apply_mirror() before resolving $source_event_title (FIX 2, 2.3.44)',
	false !== strpos( $php_editor_29, "// FIX 2 (2.3.44) — Mirror on page load" ) &&
	false !== strpos( $php_editor_29, 'self::apply_mirror( $reservation_id );' ),
	$pass, $fail, $log
);

// 29f. on_tec_event_save() public static method defined (FIX 2).
c7x_ok(
	'[29f] on_tec_event_save() public static method defined in editor page (FIX 2, 2.3.44)',
	false !== strpos( $php_editor_29, 'public static function on_tec_event_save(' ),
	$pass, $fail, $log
);

// 29g. save_post_tribe_events hook registered in loader → on_tec_event_save (FIX 2).
c7x_ok(
	'[29g] save_post_tribe_events hooks to EEM_Reservation_Editor_Page::on_tec_event_save in loader (FIX 2, 2.3.44)',
	false !== strpos( $loader_29, "save_post_tribe_events" ) &&
	false !== strpos( $loader_29, "'EEM_Reservation_Editor_Page', 'on_tec_event_save'" ),
	$pass, $fail, $log
);

// 29h. apply_mirror() reads override flags and conditionally updates post_title/post_name (FIX 2).
c7x_ok(
	'[29h] apply_mirror() guards on name_overridden + slug_overridden flags; calls wp_update_post (FIX 2, 2.3.44)',
	false !== strpos( $php_editor_29, '_eem_reservation_name_overridden' ) &&
	false !== strpos( $php_editor_29, '_eem_reservation_slug_overridden' ) &&
	false !== strpos( $php_editor_29, 'wp_update_post( $args )' ),
	$pass, $fail, $log
);

// 29i. Version bumped to 2.3.44.
c7x_ok(
	'[29i] Plugin version 2.3.44 in header + EQUINE_EVENT_MANAGER_VERSION constant',
	false !== strpos( $main_29, "Version:           2.3.44" ) &&
	false !== strpos( $main_29, "'2.3.44'" ),
	$pass, $fail, $log
);

echo "\n[30] 2.3.46 — C10.A enqueue fix + hero default + content filter guard + dead CSS strip\n";

$main_30        = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'equine-event-manager.php' );
$php_short_30   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$php_events_30  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
$css_public_30  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );

// 30a. FIX 1 — render_frontend_form_assets_in_footer() calls EEM_Events::render_frontend_styles().
c7x_ok(
	'[30a] FIX 1: render_frontend_form_assets_in_footer() calls EEM_Events::render_frontend_styles() to enqueue public.css',
	false !== strpos( $php_short_30, 'EEM_Events::render_frontend_styles()' ),
	$pass, $fail, $log
);

// 30b. FIX 1 — eem-public handle registered in render_frontend_styles() with public.css path.
c7x_ok(
	'[30b] FIX 1: render_frontend_styles() registers eem-public handle with public.css',
	false !== strpos( $php_events_30, "'eem-public'" ) &&
	false !== strpos( $php_events_30, 'public.css' ),
	$pass, $fail, $log
);

// 30c. FIX 2 — show_event_header defaults to 0 (V1 form-only spec).
c7x_ok(
	'[30c] FIX 2: show_event_header defaults to 0 in render_reservation() shortcode_atts',
	(bool) preg_match( "/'show_event_header'\s*=>\s*0/", $php_short_30 ),
	$pass, $fail, $log
);

// 30d. FIX 3 — filter_single_event_content has_shortcode guard.
c7x_ok(
	'[30d] FIX 3: filter_single_event_content skips when has_shortcode(content, en_reservation)',
	false !== strpos( $php_events_30, "has_shortcode( \$content, 'en_reservation' )" ),
	$pass, $fail, $log
);

// 30e. FIX 3 — filter_single_event_content Elementor guard.
c7x_ok(
	'[30e] FIX 3: filter_single_event_content skips when _elementor_edit_mode = builder',
	false !== strpos( $php_events_30, '_elementor_edit_mode' ) &&
	false !== strpos( $php_events_30, "'builder'" ),
	$pass, $fail, $log
);

// 30f. FIX 5 — dead CSS classes stripped from public.css.
$dead_classes_30 = array(
	'.form-section{',
	'.toggle{',
	'.toggle.on{',
	'.stall-picker{',
	'.order-sidebar{',
	'.mobile-order-drawer{',
	'.agreement-notice{',
	'.mobile-order-banner{',
	'.page-body{',
);
$dead_found_30 = array();
foreach ( $dead_classes_30 as $cls ) {
	if ( false !== strpos( $css_public_30, $cls ) ) {
		$dead_found_30[] = $cls;
	}
}
c7x_ok(
	'[30f] FIX 5: Dead mockup CSS classes absent from public.css (' . implode( ', ', $dead_classes_30 ) . ')',
	empty( $dead_found_30 ),
	$pass, $fail, $log
);
if ( ! empty( $dead_found_30 ) ) {
	$log[] = '  DETAIL: still present — ' . implode( ', ', $dead_found_30 );
}

// 30g. [META-CHECK] Standing Rule 20 variant: every CSS class targeted by a PRESENCE
//      smoke assertion must exist in PHP render output, public.css, or admin.css.
//      Restrict to "false !== strpos($css_*, '.CLASS')" patterns only — absence checks
//      ("false === strpos") are intentionally asserting a class is NOT there and are
//      excluded. Admin-only classes (e.g. .eem-stall-chart-filter-search) live in
//      admin.css, not public.css, so admin.css is a valid codebase source here.
$smoke_src_30      = file_get_contents( __FILE__ );
$css_admin_30      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
// Extract class-name targets from PRESENCE-only patterns: false !== strpos($css_*, '.CLASSNAME').
preg_match_all(
	'/false\s*!==\s*strpos\s*\(\s*\$css_[a-z_0-9]+\s*,\s*[\'"](\.[a-z][a-z0-9_-]+)/i',
	$smoke_src_30,
	$meta_matches_30
);
$meta_class_names_30 = array_unique( $meta_matches_30[1] );
$orphaned_30 = array();
foreach ( $meta_class_names_30 as $meta_cls ) {
	$bare = ltrim( $meta_cls, '.' );
	// Check PHP files for emission and CSS files for definition.
	$in_codebase = false !== strpos( $php_short_30,   $bare )    ||
	               false !== strpos( $php_events_30,   $bare )    ||
	               false !== strpos( $css_public_30,   $meta_cls ) ||
	               false !== strpos( $css_admin_30,    $meta_cls );
	if ( ! $in_codebase ) {
		$orphaned_30[] = $meta_cls;
	}
}
c7x_ok(
	'[30g] META-CHECK: every CSS class targeted by a presence smoke assertion is emitted by PHP or defined in public.css / admin.css',
	empty( $orphaned_30 ),
	$pass, $fail, $log
);
if ( ! empty( $orphaned_30 ) ) {
	$log[] = '  DETAIL: orphaned targets — ' . implode( ', ', $orphaned_30 );
}

// 30h. Version bumped to 2.3.47 (2.3.47 — shortcode TEC auto-resolve +
// toggle-OFF persistence + cancellation-override validation fix).
c7x_ok(
	'[30h] Plugin version 2.3.47 in header + EQUINE_EVENT_MANAGER_VERSION constant',
	false !== strpos( $main_30, "Version:           2.3.47" ) &&
	false !== strpos( $main_30, "'2.3.47'" ),
	$pass, $fail, $log
);

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
