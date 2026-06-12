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

// C8 port (current canon): the editor is a SINGLE-COLUMN layout. The old
// right rail (300px → 260px at 1024px, collapsing to single column at 767px)
// was removed; .eem-edit-body is now a plain block container whose only
// responsive change is padding. Assert the current single-column padding
// rules at each breakpoint rather than the retired grid model.
c7x14_ok( '1024px: .eem-edit-body single-column padding override (C8 port)',
	(bool) preg_match( '~@media\s*\(\s*max-width\s*:\s*1024px\s*\)\s*\{[^}]*\.eem-edit-body\s*\{[^}]*padding\s*:\s*14px\s+16px~s', $admin_css ),
	$pass, $fail, $log );

// At 767px the edit body keeps single column and only adjusts padding.
c7x14_ok( '767px: .eem-edit-body single-column padding override (C8 port)',
	false !== strpos( $admin_css, '.eem-edit-body { padding: 12px 12px 90px; }' ),
	$pass, $fail, $log );

// C8 port: the .eem-sticky-save bar is a fixed bottom bar shown at ALL
// viewports (the rail Publish card it used to alternate with was removed),
// so its default state is display: flex (not display: none + 767px reveal).
c7x14_ok( '.eem-sticky-save is display: flex by default (C8 fixed bottom bar at all viewports)',
	(bool) preg_match( '~\.eem-sticky-save\s*\{[^}]*display\s*:\s*flex~', $admin_css ),
	$pass, $fail, $log );
c7x14_ok( '.eem-sticky-save is fixed to the bottom of the viewport (C8 port)',
	(bool) preg_match( '~\.eem-sticky-save\s*\{[^}]*position\s*:\s*fixed[^}]*bottom\s*:\s*0~s', $admin_css ),
	$pass, $fail, $log );

// On mobile only the status badge inside the sticky-save bar is hidden.
c7x14_ok( '767px: .eem-sticky-save-status hidden on mobile (mockup line 285)',
	false !== strpos( $admin_css, '.eem-sticky-save-status { display: none; }' ),
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
// Create a fresh, fully-configured reservation fixture (the old hardcoded res 44
// no longer exists in the seed). Feed-linked so the editor renders section cards
// ($has_linked_event), with all 9 section toggles on so every body renders.
$rid_14 = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.X.14 Sweep ' . wp_generate_password( 6, false, false ),
) );
update_post_meta( $rid_14, '_en_event_source',           'feed' );
update_post_meta( $rid_14, '_en_use_global_event_source', 0 );
update_post_meta( $rid_14, '_en_external_event_id',       'ext-c7x14-sweep' );
update_post_meta( $rid_14, '_en_external_event_title',    'C7.X.14 Sweep Event' );
foreach ( array(
	'_en_checkin_checkout_enabled',
	'_en_event_day_enabled',
	'_en_stalls_enabled',
	'_en_rv_enabled',
	'_en_general_addons_enabled',
	'_en_group_reservations_enabled',
	'_en_convenience_fee_enabled',
	'_en_venue_map_enabled',
	'_en_venue_agreement_enabled',
	'_en_cancellation_enabled',
) as $key ) {
	update_post_meta( $rid_14, $key, 1 );
}

// Self-cleanup: this fixture is ephemeral. Remove it on shutdown (even if an
// assertion below fatals) so repeated runs don't accumulate "C7.X.14 Sweep …"
// reservations in the picker — they previously leaked one post per run.
register_shutdown_function( static function () use ( $rid_14 ) {
	if ( $rid_14 ) {
		wp_delete_post( (int) $rid_14, true );
	}
} );

$_GET['reservation_id'] = $rid_14;
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();

// Each of the 11 sections renders a section card. (The former `event_pre_entries`
// section was extracted into the standalone `en_entry` Entries CPT — see
// entries-cpt-smoke.php — so it is no longer an editor section.) Card ids can
// contain underscores so the regex allows [a-z_].
$expected_sections = array( 'description', 'checkin', 'eventday', 'stall', 'rv', 'addons', 'group', 'fees', 'venuemap', 'agreement', 'cancellation' );
foreach ( $expected_sections as $sk ) {
	c7x14_ok( "section card-{$sk} renders", false !== strpos( $html, "id=\"card-{$sk}\"" ), $pass, $fail, $log );
}
c7x14_ok( 'exactly 11 editor section cards present', 11 === preg_match_all( '#<section[^>]*id="card-[a-z_]+"#', $html ), $pass, $fail, $log );

// Section order matches the section registry top-to-bottom.
preg_match_all( '#<section[^>]*id="card-([a-z_]+)"#', $html, $order_matches );
c7x14_ok( 'section order matches canon (description → cancellation)',
	$expected_sections === $order_matches[1],
	$pass, $fail, $log,
	implode( ',', $order_matches[1] ?? array() ) );

// C8 port: the right rail was removed entirely; the editor is single-column
// and the Linked-Event controls moved up into the event-anchor header. There
// should be ZERO rail cards rendered now.
c7x14_ok( 'no rail cards rendered (C8 port removed the right rail)',
	0 === substr_count( $html, 'class="eem-rail-card' ),
	$pass, $fail, $log,
	'found ' . substr_count( $html, 'class="eem-rail-card' ) );

// C8 port: the event-anchor header carries the actionable event controls.
// "Change Event" is always present; "View Event" only renders when the
// linked event resolves to a permalink (feed events have none).
c7x14_ok( 'event-anchor header action group present (.eem-header-actions)',
	false !== strpos( $html, 'class="eem-header-actions"' ),
	$pass, $fail, $log );
c7x14_ok( 'header carries Change Event control (data-eem-action="header-change-event")',
	false !== strpos( $html, 'data-eem-action="header-change-event"' ),
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
