<?php
/**
 * C7.X.16 — Whitney's C7.X.15 visual verify fix-ups (9 issues).
 *
 *   A — Main column "double padding" stripped (.eem-reservation-
 *       editor-body padding: 18px 18px removed; grid container's
 *       18px 22px is the canonical outer padding)
 *   B — Repeating-table border-radius confirmed using var(--eem-
 *       radius-sm) (3px) on all 4 classes (C7.X.15 actually landed;
 *       Whitney saw cached CSS; C7.X.16 cache-bust to 2.3.5 resolves)
 *   C — Legacy admin-legacy.css SELECT exclusion sweep — every bare
 *       `select` selector now carries :not(.eem-dashboard-range-
 *       select):not(.eem-list-select):not(.eem-toolbar-select):not(
 *       .eem-field-select). Same C7.X.10/11 pattern, applied to SELECT.
 *   D1 — Preview button label "Preview Frontend Form" → "Preview"
 *   D2 — a.eem-btn-preview:hover umbrella selector
 *   D3 — Disabled state with tooltip "Customer preview available after C10 ships."
 *   E  — Defensive z-index raise on .media-modal{,-backdrop} (no
 *        !important; cascade order wins)
 *   F  — NO CODE; documented as post-commit re-seed operation
 *   G  — Delete Permanently button + handler + admin-post wire
 *   H  — counts_by_tab() shares WP_Query path with get_paginated()
 *   I  — Per-section publish-gate validator + server gate + JS
 *        highlight + auto-clear
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x16_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.16 — WALKTHROUGH FIX-UPS SMOKE ===\n";

$admin_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$legacy    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin-legacy.css' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$page_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
$rail_pub  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_rail-publish-card.php' );
$list_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$repo_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reservations-list-repo.php' );

// Strip block + line comments from PHP/JS sources before scanning for
// presence/absence of operative tokens — audit-trail comments often
// mention class names + retired function names + old labels, which
// would false-positive the absence-assertions otherwise.
$strip_php  = function( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$rail_pub_nocom = $strip_php( $rail_pub );
$repo_src_nocom = $strip_php( $repo_src );

wp_set_current_user( 1 );

// ── [A] Main column padding stripped ────────────────────────────
echo "\n[A] .eem-reservation-editor-body — padding declaration stripped\n";
$body_block = '';
if ( preg_match( '/\.eem-reservation-editor-body\s*\{[^}]*\}/s', $admin_css, $bm ) ) { $body_block = $bm[0]; }
c7x16_ok( '.eem-reservation-editor-body base block does NOT declare `padding: 18px 18px`',
	'' !== $body_block && false === strpos( $body_block, 'padding: 18px 18px' ),
	$pass, $fail, $log );
c7x16_ok( '.eem-reservation-editor-body retains `display: flex; flex-direction: column; gap: 14px`',
	'' !== $body_block && false !== strpos( $body_block, 'display: flex' ) && false !== strpos( $body_block, 'gap: 14px' ),
	$pass, $fail, $log );

// ── [B] Repeating-table inputs use --eem-radius-sm ──────────────
echo "\n[B] Repeating-table input border-radius uses var(--eem-radius-sm) (3px)\n";
foreach ( array( 'eem-repeat-input', 'eem-repeat-price-in', 'eem-zone-name-input', 'eem-zone-price-in' ) as $cls ) {
	$has = (bool) preg_match( '~input\.' . preg_quote( $cls, '~' ) . '\s*\{[^}]*border-radius\s*:\s*[^;]*var\(--eem-radius-sm\)[^;]*;~s', $admin_css );
	c7x16_ok( "input.{$cls} border-radius declares var(--eem-radius-sm)", $has, $pass, $fail, $log );
}

// ── [C] Legacy SELECT exclusion sweep ───────────────────────────
echo "\n[C] admin-legacy.css — every bare SELECT carries :not() exclusion chain\n";
// Strip CSS comments first so audit-trail prose doesn't trip the count.
$legacy_no_comments = preg_replace( '~/\*.*?\*/~s', '', $legacy );
// Count protected vs unprotected. Unprotected = `select` followed by `,`
// or `{` (i.e. it's the FINAL element in a selector chain) AND NOT
// followed by `:not(`. Protected = `select:not(.eem-dashboard-range-select)`
// (the full 4-class chain). The exclusion sweep is correct iff
// unprotected count is 0 AND protected count > 0.
$with_full_chain = preg_match_all( '/select:not\(\.eem-dashboard-range-select\):not\(\.eem-list-select\):not\(\.eem-toolbar-select\):not\(\.eem-field-select\)/', $legacy_no_comments );
$unprotected = 0;
foreach ( explode( "\n", $legacy_no_comments ) as $line ) {
	// Match `select,` or `select {` at end of a selector chain — pre-
	// `:not(` would protect it. Skip lines that already carry `:not(`
	// somewhere immediately after `select`.
	if ( preg_match( '/(\s+|,)select(\s*,|\s*\{)/', $line ) && ! preg_match( '/select:not\(/', $line ) ) {
		$unprotected++;
	}
}
c7x16_ok( "admin-legacy.css has {$with_full_chain} fully-protected select selector(s) and 0 unprotected",
	$with_full_chain > 0 && 0 === $unprotected,
	$pass, $fail, $log, "with_full_chain={$with_full_chain}, unprotected={$unprotected}" );

// ── [D1/D2/D3] Preview button ───────────────────────────────────
echo "\n[D] Preview button — label simplified, hover umbrella, disabled state\n";
c7x16_ok( 'D1: button label is "Preview" (not "Preview Frontend Form") — comments excluded',
	false === strpos( $rail_pub_nocom, "Preview Frontend Form" )
	&& false !== strpos( $rail_pub_nocom, "esc_html_e( 'Preview', 'equine-event-manager' )" ),
	$pass, $fail, $log );
c7x16_ok( 'D2: a.eem-btn-preview:hover umbrella selector forces text-decoration: none',
	(bool) preg_match( '/a\.eem-btn-preview:hover[^{}]*\{[^}]*text-decoration\s*:\s*none/s', $admin_css ),
	$pass, $fail, $log );
c7x16_ok( 'D3: button renders <button disabled> with title tooltip',
	false !== strpos( $rail_pub, '<button type="button" class="eem-btn-preview" disabled' )
	&& false !== strpos( $rail_pub, 'Customer preview available after C10 ships' ),
	$pass, $fail, $log );
c7x16_ok( 'D3 CSS: .eem-btn-preview:disabled / [aria-disabled="true"] muted styling present',
	false !== strpos( $admin_css, '.eem-btn-preview:disabled' )
	&& false !== strpos( $admin_css, '.eem-btn-preview[aria-disabled="true"]' ),
	$pass, $fail, $log );

// ── [E] Defensive Media Library modal z-index raise ─────────────
echo "\n[E] .media-modal{,-backdrop} z-index raised defensively (cascade-order wins, no !important)\n";
// C7.X.17 superseded the combined .media-modal-backdrop,.media-modal rule with
// two separate rules (backdrop z-index:199999 + modal z-index:200000) to fix
// the opacity-bleed root cause. Assert the post-C7.X.17 state: .media-modal
// still has z-index:200000 and there is no !important on its own rule.
c7x16_ok( '.media-modal has z-index: 200000 (split from backdrop by C7.X.17)',
	(bool) preg_match( '/\.media-modal\s*\{\s*z-index\s*:\s*200000\s*;\s*\}/', $admin_css ),
	$pass, $fail, $log );
c7x16_ok( 'NO !important on .media-modal z-index rule (cascade-order wins)',
	! (bool) preg_match( '/\.media-modal\s*\{[^}]*z-index\s*:\s*200000[^}]*!important/', $admin_css ),
	$pass, $fail, $log );

// ── [G] Delete Permanently — UI + handler + admin-post wire ─────
echo "\n[G] Delete Permanently — Trash row meatballs + handler + admin-post wire\n";
c7x16_ok( 'list-page renders Delete Permanently button on trash rows',
	false !== strpos( $list_src, 'data-eem-action="reservation-delete-permanently"' )
	&& false !== strpos( $list_src, 'Delete Permanently' ),
	$pass, $fail, $log );
c7x16_ok( 'EEM_Reservations_List_Page::handle_delete_permanently() exists',
	method_exists( 'EEM_Reservations_List_Page', 'handle_delete_permanently' ),
	$pass, $fail, $log );
c7x16_ok( 'handle_delete_permanently guards against deleting non-trash posts',
	(bool) preg_match( "/'trash'\s*!==\s*\\\$post->post_status/", $list_src ),
	$pass, $fail, $log );
c7x16_ok( 'admin_post_eem_reservation_delete_permanently hook registered',
	false !== has_action( 'admin_post_eem_reservation_delete_permanently', array( 'EEM_Reservations_List_Page', 'handle_delete_permanently' ) ),
	$pass, $fail, $log );
c7x16_ok( 'localize_row_action_nonces includes eem_reservation_delete_permanently',
	false !== strpos( $list_src, "'eem_reservation_delete_permanently' => wp_create_nonce" ),
	$pass, $fail, $log );
c7x16_ok( "JS dispatch table has 'reservation-delete-permanently' arm",
	false !== strpos( $js_src, "'reservation-delete-permanently'" ),
	$pass, $fail, $log );
c7x16_ok( "JS confirm includes 'cannot be undone' warning",
	false !== strpos( $js_src, 'cannot be undone' ),
	$pass, $fail, $log );

// ── [H] counts_by_tab() shares WP_Query path ────────────────────
echo "\n[H] counts_by_tab() uses WP_Query (not wp_count_posts) — aligns with get_paginated()\n";
c7x16_ok( 'counts_by_tab() no longer calls wp_count_posts (comments excluded)',
	false === strpos( $repo_src_nocom, 'wp_count_posts' ),
	$pass, $fail, $log );
c7x16_ok( 'counts_by_tab() iterates tabs through WP_Query with no_found_rows=false',
	(bool) preg_match( '/public static function counts_by_tab[\s\S]{0,1500}new WP_Query[\s\S]{0,200}no_found_rows.*false/s', $repo_src ),
	$pass, $fail, $log );

// Functional probe: counts should be int + non-negative for every tab.
$counts = EEM_Reservations_List_Repo::counts_by_tab();
$all_ok = true; foreach ( $counts as $v ) { if ( ! is_int( $v ) || $v < 0 ) { $all_ok = false; break; } }
c7x16_ok( 'counts_by_tab() returns all int + non-negative counts',
	is_array( $counts ) && ! empty( $counts ) && $all_ok,
	$pass, $fail, $log, var_export( $counts, true ) );

// ── [I] Publish-gate validator + server gate + client UI ────────
echo "\n[I] validate_for_publish() + publish gate + client highlight\n";

// Method exists + signature.
c7x16_ok( 'EEM_Reservation_Editor_Page::validate_for_publish() exists',
	method_exists( 'EEM_Reservation_Editor_Page', 'validate_for_publish' ),
	$pass, $fail, $log );

// Server gate in ajax_save calls validate_for_publish ONLY when new_status === publish.
c7x16_ok( "ajax_save calls validate_for_publish only when new_status === 'publish'",
	(bool) preg_match( "/if\s*\(\s*'publish'\s*===\s*\\\$new_status\s*\)\s*\{[\s\S]{0,400}validate_for_publish/", $page_src ),
	$pass, $fail, $log );

// Code returned uses 422 + publish_validation_failed + first_section.
c7x16_ok( "server gate response code is publish_validation_failed (not the legacy validation_failed)",
	false !== strpos( $page_src, "'publish_validation_failed'" ),
	$pass, $fail, $log );
c7x16_ok( 'server response includes first_section key for client scroll-and-highlight',
	false !== strpos( $page_src, "'first_section'" ),
	$pass, $fail, $log );

// Rule coverage — every section listed in Whitney's spec has a check.
$rules_covered = 0;
foreach ( array(
	'checkin_checkout_enabled',
	'event_day_enabled',
	'stalls_enabled',
	'rv_enabled',
	'general_addons_enabled',
	'group_reservations_enabled',
	'convenience_fee_enabled',
	'venue_agreement_enabled',
	'cancellation_enabled',
) as $key ) {
	if ( false !== strpos( $page_src, $key ) ) { $rules_covered++; }
}
c7x16_ok( 'validate_for_publish covers all 9 toggle-gated sections', 9 === $rules_covered, $pass, $fail, $log, "covered={$rules_covered}/9" );

// Save Draft is NOT gated.
c7x16_ok( "Save Draft path is NOT gated by validate_for_publish",
	// Pattern: the call to validate_for_publish lives inside an `if
	// ('publish' === $new_status)` block — confirmed above. Defensive
	// negation: there's no unconditional call to validate_for_publish.
	false === strpos( $page_src, '$publish_errors = self::validate_for_publish( $candidate, $reservation_id );' )
	|| (bool) preg_match( "/if\s*\(\s*'publish'\s*===\s*\\\$new_status\s*\)\s*\{[^}]*\\\$publish_errors/s", $page_src ),
	$pass, $fail, $log );

// Client-side: admin.js handles publish_validation_failed → highlight + scroll.
c7x16_ok( "admin.js handles publish_validation_failed by toggling .eem-section-invalid",
	false !== strpos( $js_src, "publish_validation_failed" )
	&& false !== strpos( $js_src, "eem-section-invalid" ),
	$pass, $fail, $log );
c7x16_ok( 'admin.js scrolls first failed section into view',
	false !== strpos( $js_src, "scrollIntoView" ),
	$pass, $fail, $log );

// CSS for .eem-section-invalid.
c7x16_ok( '.eem-reservation-editor-section.eem-section-invalid CSS rule present',
	(bool) preg_match( '/\.eem-reservation-editor-section\.eem-section-invalid\s*\{/', $admin_css ),
	$pass, $fail, $log );

// Functional — seed a reservation with one specific failure, call validator, assert error keys match.
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X.16 Validate' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C7.X.16 Validate ' . wp_generate_password( 6, false, false ) ) );

// Scenario 1: Event Day enabled with all 4 fields empty → eventday error.
$candidate_a = array(
	'event_day_enabled'    => 1,
	'event_day_checkin'    => '',
	'event_day_bring'      => '',
	'event_day_parking'    => '',
	'event_day_contact'    => '',
);
$errs_a = EEM_Reservation_Editor_Page::validate_for_publish( $candidate_a, $rid );
c7x16_ok( "validator: Event Day enabled w/ empty fields → 'eventday' key error",
	isset( $errs_a['eventday'] ) && '' !== $errs_a['eventday'],
	$pass, $fail, $log );

// Scenario 2: Stall enabled but no stay type → stall error.
$candidate_b = array(
	'stalls_enabled'        => 1,
	'stall_nightly_enabled' => 0,
	'stall_weekend_enabled' => 0,
);
$errs_b = EEM_Reservation_Editor_Page::validate_for_publish( $candidate_b, $rid );
c7x16_ok( "validator: Stall enabled w/ no stay type → 'stall' key error",
	isset( $errs_b['stall'] ),
	$pass, $fail, $log );

// Scenario 3: Agreement enabled with no file → agreement error.
$candidate_c = array(
	'venue_agreement_enabled' => 1,
	'venue_agreement_file_id' => 0,
);
$errs_c = EEM_Reservation_Editor_Page::validate_for_publish( $candidate_c, $rid );
c7x16_ok( "validator: Agreement enabled w/ no file → 'agreement' key error",
	isset( $errs_c['agreement'] ),
	$pass, $fail, $log );

// Scenario 4: Everything off → empty error array (valid for publish).
$candidate_d = array();
$errs_d = EEM_Reservation_Editor_Page::validate_for_publish( $candidate_d, $rid );
c7x16_ok( 'validator: all toggles off → empty error array (publish allowed)',
	is_array( $errs_d ) && empty( $errs_d ),
	$pass, $fail, $log );

wp_delete_post( $rid, true );

// ── [Cache-bust] ────────────────────────────────────────────────
echo "\n[Cache-bust] EQUINE_EVENT_MANAGER_VERSION >= 2.3.5\n";
c7x16_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.5',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.5', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
