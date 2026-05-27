<?php
/**
 * C7.X.14 — VV-7 responsive breakpoints + full-editor regression sweep.
 *
 * Two deliverables consolidated:
 *
 * VV-7 — Responsive breakpoints (`@media`) for the editor page must
 *   match mockup canon. Verified against
 *   `.mockups/edit_reservation_page.html` lines 279-300:
 *     @media (max-width: 1024px) { .edit-body { grid-template-columns:
 *       1fr 260px; padding: 14px 16px; } }
 *     @media (max-width: 767px) { .edit-body { grid-template-columns:
 *       1fr; padding: 12px; gap: 14px; } .edit-rail { position:
 *       static; order: -1; } .sticky-save { display: flex; } }
 *
 * Full-editor regression sweep — render reservation 44 with ALL
 *   sections enabled, verify against canonical mockup section-by-
 *   section element-shape inventory. Catches any structural drift
 *   that other section-specific smokes might miss because they only
 *   check their own section.
 *
 * Also covers the C7.X.14 cleanup of two additional unprefixed
 * input classes surfaced during the regression sweep:
 *   - .eem-repeat-input (Add-On row text inputs)
 *   - .eem-zone-name-input (RV Lot Zone name input)
 * Both prefixed with `input.` to extend the C7.X.13 WP-core
 * specificity-tie fix to remaining vulnerable inputs.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x14_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.14 — RESPONSIVE + FULL-EDITOR SWEEP SMOKE ===\n";

$admin_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

// ── [1] Responsive @media breakpoints mockup-canonical ──────────
echo "\n[1] VV-7 — @media breakpoints match mockup canon\n";

c7x14_ok( '@media (max-width: 1024px) block present',
	(bool) preg_match( '~@media\s*\(\s*max-width\s*:\s*1024px\s*\)\s*\{~', $admin_css ),
	$pass, $fail, $log );
c7x14_ok( '@media (max-width: 767px) block present',
	(bool) preg_match( '~@media\s*\(\s*max-width\s*:\s*767px\s*\)\s*\{~', $admin_css ),
	$pass, $fail, $log );

// At 1024px, .eem-edit-body collapses rail from 300px → 260px.
c7x14_ok( '1024px: .eem-edit-body grid-template-columns 1fr 260px',
	(bool) preg_match( '~@media\s*\(\s*max-width\s*:\s*1024px\s*\)\s*\{[^}]*\.eem-edit-body\s*\{[^}]*grid-template-columns\s*:\s*1fr\s+260px~s', $admin_css ),
	$pass, $fail, $log );

// At 767px, .eem-edit-body collapses to single column + .eem-edit-rail
// goes static with order:-1 (rail above main) + .eem-sticky-save
// reveals (mobile save bar replaces desktop rail Publish card).
// Note: admin.css has MULTIPLE @media (max-width: 767px) blocks (one
// per major surface — dashboard, editor, etc.). Rather than walk
// braces to find the editor-specific block, assert the EXACT rules
// that ONLY exist inside the editor mobile block — these one-line
// rule shapes are unique to the editor 767px context.
c7x14_ok( '767px: .eem-edit-body collapses to single column (grid-template-columns: 1fr)',
	false !== strpos( $admin_css, '.eem-edit-body { grid-template-columns: 1fr;' ),
	$pass, $fail, $log );
c7x14_ok( '767px: .eem-edit-rail goes position: static + order: -1 (rail above main)',
	false !== strpos( $admin_css, '.eem-edit-rail { position: static; order: -1; }' ),
	$pass, $fail, $log );
c7x14_ok( '767px: .eem-sticky-save reveals (display: flex)',
	false !== strpos( $admin_css, '.eem-sticky-save { display: flex; }' ),
	$pass, $fail, $log );

// Default (non-media) .eem-sticky-save MUST be display: none so it
// doesn't double-render on desktop alongside the rail Publish card.
c7x14_ok( 'default .eem-sticky-save is display: none (desktop hidden)',
	(bool) preg_match( '~(?<!\})\s*\.eem-sticky-save\s*\{[^}]*display\s*:\s*none~', $admin_css ),
	$pass, $fail, $log );

// ── [2] C7.X.14 sweep — two MORE input classes prefixed with input. ─
echo "\n[2] Sweep — .eem-repeat-input + .eem-zone-name-input WP-core specificity tie\n";
foreach ( array( 'eem-repeat-input', 'eem-zone-name-input' ) as $cls ) {
	c7x14_ok( "admin.css declares `input.{$cls}` selector (specificity-tie to WP core forms.css)",
		false !== strpos( $admin_css, "input.{$cls}" ),
		$pass, $fail, $log );
}

// Cache-bust constant bumped because CSS changed.
c7x14_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.3 (cache-bust)',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.3', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

// ── [3] Full-editor regression sweep — render res 44 ─────────────
echo "\n[3] Full-editor regression sweep — every section structurally clean\n";

wp_set_current_user( 1 );
// Seed all 9 section enable toggles on res 44 so every section body
// renders. Without this some sections collapse to header-only and
// the section-body inventory looks empty.
foreach ( array(
	'_en_checkin_checkout_enabled',
	'_en_event_day_enabled',
	'_en_stalls_enabled',
	'_en_rv_enabled',
	'_en_general_addons_enabled',
	'_en_group_reservations_enabled',
	'_en_convenience_fee_enabled',
	'_en_venue_agreement_enabled',
	'_en_cancellation_enabled',
) as $key ) {
	update_post_meta( 44, $key, 1 );
}

$_GET['reservation_id'] = 44;
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();

// Each of the 10 sections renders a section card.
$expected_sections = array( 'description', 'checkin', 'eventday', 'stall', 'rv', 'addons', 'group', 'fees', 'agreement', 'cancellation' );
foreach ( $expected_sections as $sk ) {
	c7x14_ok( "section card-{$sk} renders", false !== strpos( $html, "id=\"card-{$sk}\"" ), $pass, $fail, $log );
}
c7x14_ok( 'exactly 10 editor section cards present', 10 === preg_match_all( '#<section[^>]*id="card-[a-z]+"#', $html ), $pass, $fail, $log );

// Section order matches mockup top-to-bottom.
preg_match_all( '#<section[^>]*id="card-([a-z]+)"#', $html, $order_matches );
c7x14_ok( 'section order matches mockup canon (description → cancellation)',
	$expected_sections === $order_matches[1],
	$pass, $fail, $log,
	implode( ',', $order_matches[1] ?? array() ) );

// C7.X.15 Issue 7 — rail card count returns to 3 (hybrid restoration).
c7x14_ok( 'right rail has exactly 3 cards (Publish + Linked Event + Shortcode — C7.X.15 hybrid)',
	3 === substr_count( $html, 'class="eem-rail-card' ),
	$pass, $fail, $log,
	'found ' . substr_count( $html, 'class="eem-rail-card' ) );

// C7.X.15 — Linked Event rail card carries the actionable controls;
// meta-line is read-only context.
c7x14_ok( 'Linked Event rail card present (C7.X.15 hybrid restoration)',
	false !== strpos( $html, '<span class="eem-rail-title">Linked Event</span>' ),
	$pass, $fail, $log );
c7x14_ok( 'rail card has Change link + ✕ icon Unlink button (actionable controls live here per Issue 7 hybrid)',
	false !== strpos( $html, 'data-eem-action="reservation-editor-event-change"' )
	&& false !== strpos( $html, 'data-eem-action="reservation-editor-event-unlink"' )
	&& false !== strpos( $html, 'class="eem-event-unlink-icon"' ),
	$pass, $fail, $log );

// VV-4 — Agreement Label field renders ABOVE the Agreement PDF row.
c7x14_ok( 'Agreement Label input renders (VV-4)',
	false !== strpos( $html, 'name="en_reservation[venue_agreement_link_label]"' ),
	$pass, $fail, $log );

// VV-3 — .eem-price-wrap uses align-items: stretch (THE seam fix).
// Verified at CSS level; the rendered HTML doesn't carry computed
// styles, so this assertion proves the rule still ships.
c7x14_ok( '.eem-price-wrap uses align-items: stretch (C7.X.12 seam fix intact)',
	(bool) preg_match( '~\.eem-price-wrap\s*\{[^}]*align-items\s*:\s*stretch~', $admin_css ),
	$pass, $fail, $log );

// VV-3 (C7.X.13) — input.eem-price-input prefix intact.
c7x14_ok( 'input.eem-price-input selector intact (C7.X.13 WP-core tie)',
	false !== strpos( $admin_css, 'input.eem-price-input' ),
	$pass, $fail, $log );

// VV-2 — group section sub-toggles use ID-based controls.
c7x14_ok( 'group grounds-fee toggle uses ID-based control (row-group-grounds-amt)',
	false !== strpos( $html, 'data-controls="row-group-grounds-amt"' ),
	$pass, $fail, $log );
c7x14_ok( 'group deposit toggle uses ID-based control (row-group-deposit-amt)',
	false !== strpos( $html, 'data-controls="row-group-deposit-amt"' ),
	$pass, $fail, $log );

// Sticky-save mobile partial renders (CSS hides on desktop).
c7x14_ok( 'sticky-save mobile partial present in rendered HTML',
	false !== strpos( $html, 'class="eem-sticky-save"' ),
	$pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
