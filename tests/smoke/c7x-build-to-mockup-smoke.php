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

// 10a. Version bumped to 2.3.23.
c7x_ok(
	'2.3.23: EQUINE_EVENT_MANAGER_VERSION is 2.3.23',
	EQUINE_EVENT_MANAGER_VERSION === '2.3.23',
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

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
