<?php
/**
 * C7.X.12 — three-deliverable smoke.
 *
 * Deliverable 1 (VV-3): admin.css `.eem-price-*` rules brought to
 *   mockup canon. The visible seam was a flex-alignment bug, not a
 *   CSS-cascade bug — three previous commits chased the wrong tree.
 *   `.eem-price-wrap { align-items: stretch; }` (was center) +
 *   `.eem-price-symbol { display: flex; align-items: center; }` +
 *   focus box-shadow + 1px borders + matching backgrounds + padding
 *   match. Full mockup-canon pass.
 *
 * Deliverable 2 (VV-4): new "Agreement Label" admin field. Renders
 *   ABOVE the Agreement PDF row in the section body. Meta key
 *   `_en_venue_agreement_link_label` (distinct from existing
 *   `_label` and `_file_label` — checkbox vs admin-display vs
 *   customer-facing link text). Customer-facing render in
 *   public/class-equine-event-manager-shortcodes.php prefers the
 *   new key; falls back to literal "Venue Agreement" when blank.
 *
 * Deliverable 3 (Item 7): right-rail Linked Event card retired.
 *   Meta-line gains "(change)" + "(unlink)" small text links.
 *   Rail card partial `_rail-linked-event-card.php` deleted.
 *   Right rail now renders 2 cards (Publish + Shortcode).
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x12_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.12 — AFFIX + AGREEMENT-LABEL + META-LINE SMOKE ===\n";

$admin_css     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$agreement_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-agreement.php' );
$meta_line_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_meta-line.php' );
$shortcodes    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

wp_set_current_user( 1 );
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X.12 D-' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.X.12 D-' . wp_generate_password( 6, false, false ),
) );
update_post_meta( $rid, '_en_venue_agreement_enabled',    1 );
update_post_meta( $rid, '_en_venue_agreement_link_label', 'Custom Waiver PDF' );
update_post_meta( $rid, '_en_rv_enabled',                 1 );

$_GET['reservation_id'] = $rid;
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();

// ── [D1] VV-3 — affix seam align-items + mockup canon ──────────
echo "\n[D1] VV-3 — .eem-price-* mockup-canon pass (align-items: stretch is THE seam fix)\n";

// THE smoking-gun assertion: align-items must be `stretch`, not `center`.
c7x12_ok( '.eem-price-wrap has align-items: stretch (was center — THE seam fix)',
	(bool) preg_match( '#\.eem-price-wrap\s*\{[^}]*\balign-items\s*:\s*stretch\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );
c7x12_ok( '.eem-price-wrap does NOT have align-items: center anywhere in its rule block',
	! (bool) preg_match( '#\.eem-price-wrap\s*\{[^}]*\balign-items\s*:\s*center\b#s', $admin_css ),
	$pass, $fail, $log );

// .eem-price-symbol must have display:flex + align-items:center so the
// $ stays vertically centered inside the now-stretched chip.
c7x12_ok( '.eem-price-symbol has display: flex',
	(bool) preg_match( '#\.eem-price-symbol\s*\{[^}]*\bdisplay\s*:\s*flex\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );
c7x12_ok( '.eem-price-symbol has align-items: center',
	(bool) preg_match( '#\.eem-price-symbol\s*\{[^}]*\balign-items\s*:\s*center\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );

// Mockup-canon border (1px solid #8c8f94) replaces var(--eem-border-input)
// (1.5px). On both chip and input.
// NB: regex uses `(?<![-\w])border:` to avoid matching `border-right:` /
// `border-radius:` (which both contain the substring "border"). Uses
// `~` as the delimiter so the `#8c8f94` color literal doesn't
// prematurely terminate the pattern.
c7x12_ok( '.eem-price-symbol border = 1px solid #8c8f94 (mockup canon)',
	(bool) preg_match( '~\.eem-price-symbol\s*\{[^}]*(?<![-\w])border\s*:\s*1px\s+solid\s+\#8c8f94\s*;[^}]*\}~s', $admin_css ),
	$pass, $fail, $log );
c7x12_ok( '.eem-price-input  border = 1px solid #8c8f94 (mockup canon)',
	(bool) preg_match( '~\.eem-price-input\s*\{[^}]*(?<![-\w])border\s*:\s*1px\s+solid\s+\#8c8f94\s*;[^}]*\}~s', $admin_css ),
	$pass, $fail, $log );

// Focus ring mockup-canon (box-shadow Electric Blue glow).
c7x12_ok( '.eem-price-input:focus has box-shadow Electric Blue glow (mockup canon)',
	(bool) preg_match( '#\.eem-price-input:focus\s*\{[^}]*\bbox-shadow\s*:\s*0\s+0\s+0\s+2px\s+rgba\(\s*22\s*,\s*104\s*,\s*242\s*,\s*0?\.12\s*\)\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );

// Aesthetic mockup-canon assertions (background, padding).
c7x12_ok( '.eem-price-symbol background = #f3f4f5 (mockup canon, was #f6f7f7)',
	(bool) preg_match( '~\.eem-price-symbol\s*\{[^}]*\bbackground\s*:\s*\#f3f4f5\s*;[^}]*\}~s', $admin_css ),
	$pass, $fail, $log );
c7x12_ok( '.eem-price-input padding = 8px 12px (mockup canon, was 8px 11px)',
	(bool) preg_match( '#\.eem-price-input\s*\{[^}]*\bpadding\s*:\s*8px\s+12px\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );

// ── [D2] VV-4 — Agreement Label field ──────────────────────────
echo "\n[D2] VV-4 — Agreement Label admin field + customer-facing fallback\n";

// Partial emits the input ABOVE the PDF row. We assert by source ordering:
// the eem_render_editor_field_row() call with label "Agreement Label"
// must appear BEFORE the one with label "Agreement PDF".
$label_pos = strpos( $agreement_src, "__( 'Agreement Label'," );
$pdf_pos   = strpos( $agreement_src, "__( 'Agreement PDF'," );
c7x12_ok( "agreement partial emits 'Agreement Label' row before 'Agreement PDF' row",
	false !== $label_pos && false !== $pdf_pos && $label_pos < $pdf_pos,
	$pass, $fail, $log );

c7x12_ok( "agreement partial uses canonical meta key 'venue_agreement_link_label'",
	false !== strpos( $agreement_src, 'venue_agreement_link_label' ),
	$pass, $fail, $log );

c7x12_ok( "agreement partial placeholder is exactly 'Agreement name (ex: Venue Agreement)'",
	false !== strpos( $agreement_src, "esc_attr__( 'Agreement name (ex: Venue Agreement)', 'equine-event-manager' )" ),
	$pass, $fail, $log );

// Rendered HTML — seed set link_label=Custom Waiver PDF; assert input
// carries that value + canonical name attr.
c7x12_ok( 'rendered editor has <input name="en_reservation[venue_agreement_link_label]"> with seed value',
	(bool) preg_match( '#<input[^>]*name="en_reservation\[venue_agreement_link_label\]"[^>]*value="Custom Waiver PDF"#', $html ),
	$pass, $fail, $log );

// CPT sanitize + defaults wire the meta key.
$cpt_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );
c7x12_ok( "CPT sanitize_meta_submission reads 'venue_agreement_link_label' as sanitize_text_field",
	false !== strpos( $cpt_src, "isset( \$source['venue_agreement_link_label'] ) ? sanitize_text_field( \$source['venue_agreement_link_label'] ) : ''" ),
	$pass, $fail, $log );
c7x12_ok( "CPT get_default_meta_values declares 'venue_agreement_link_label' = ''",
	(bool) preg_match( "#'venue_agreement_link_label'\s*=>\s*''#", $cpt_src ),
	$pass, $fail, $log );

// Customer-facing render uses the new key + falls back to literal
// "Venue Agreement" when blank.
c7x12_ok( "customer-facing shortcode reads venue_agreement_link_label first",
	false !== strpos( $shortcodes, "\$data['venue_agreement_link_label']" ),
	$pass, $fail, $log );
c7x12_ok( "customer-facing shortcode falls back to literal 'Venue Agreement'",
	false !== strpos( $shortcodes, "__( 'Venue Agreement', 'equine-event-manager' )" ),
	$pass, $fail, $log );

// Mockup file updated to reflect new field.
$mockup = file_get_contents( EQUINE_EVENT_MANAGER_PATH . '.mockups/edit_reservation_page.html' );
c7x12_ok( 'mockup edit_reservation_page.html Agreement section contains "Agreement Label" field row',
	false !== strpos( $mockup, 'Agreement Label' )
	&& false !== strpos( $mockup, 'Agreement name (ex: Venue Agreement)' ),
	$pass, $fail, $log );

// ── [D3] Item 7 — C7.X.15 hybrid restoration (REVERSAL of C7.X.12) ─
echo "\n[D3] Item 7 — C7.X.15 hybrid restoration: meta-line read-only + rail card with actions\n";

// Rail card partial RESTORED.
c7x12_ok( '_rail-linked-event-card.php partial exists on disk (C7.X.15 restoration)',
	file_exists( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_rail-linked-event-card.php' ),
	$pass, $fail, $log );

// Editor page requires the rail card partial again.
$editor_page_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
c7x12_ok( 'editor page requires _rail-linked-event-card.php (restored)',
	false !== strpos( $editor_page_src, '_rail-linked-event-card.php' ),
	$pass, $fail, $log );

// Rendered HTML — rail card AND its inline typeahead present.
c7x12_ok( 'rendered editor has <span class="eem-rail-title">Linked Event</span>',
	false !== strpos( $html, '<span class="eem-rail-title">Linked Event</span>' ),
	$pass, $fail, $log );
c7x12_ok( 'rendered editor has .eem-event-search typeahead (inline in rail card)',
	false !== strpos( $html, 'class="eem-event-search"' )
	|| false !== strpos( $html, 'input.eem-event-search' )
	|| (bool) preg_match( '#<input[^>]*class="eem-event-search"#', $html ),
	$pass, $fail, $log );
c7x12_ok( 'rendered editor has exactly 3 rail cards (Publish + Linked Event + Shortcode)',
	3 === substr_count( $html, 'class="eem-rail-card' ),
	$pass, $fail, $log,
	'found: ' . substr_count( $html, 'class="eem-rail-card' ) );

// Meta-line is now READ-ONLY — no action-link data attributes.
$meta_block = '';
if ( preg_match( '#<div class="eem-plugin-meta-line">.*?</div>#s', $html, $mlm ) ) {
	$meta_block = $mlm[0];
}
c7x12_ok( 'meta-line is READ-ONLY (no data-eem-action attributes — actions live in rail card)',
	'' !== $meta_block && false === strpos( $meta_block, 'data-eem-action' ),
	$pass, $fail, $log );
c7x12_ok( 'meta-line source has NO data-eem-action="reservation-editor-event-change"',
	false === strpos( $meta_line_src, 'data-eem-action="reservation-editor-event-change"' ),
	$pass, $fail, $log );
c7x12_ok( 'meta-line source has NO data-eem-action="reservation-editor-event-unlink"',
	false === strpos( $meta_line_src, 'data-eem-action="reservation-editor-event-unlink"' ),
	$pass, $fail, $log );

// Rail card on res 44 (linked) emits Change link + ✕ icon unlink button.
$_GET['reservation_id'] = 44;
ob_start(); EEM_Reservation_Editor_Page::render(); $html44 = (string) ob_get_clean();
$_GET = array();
c7x12_ok( 'res 44 rail card emits Change link (data-eem-action="reservation-editor-event-change")',
	false !== strpos( $html44, 'class="eem-event-linked-change"' )
	&& false !== strpos( $html44, 'data-eem-action="reservation-editor-event-change"' ),
	$pass, $fail, $log );
c7x12_ok( 'res 44 rail card emits ✕ icon Unlink button (terse, not verbose word)',
	false !== strpos( $html44, 'class="eem-event-unlink-icon"' )
	&& false !== strpos( $html44, 'data-eem-action="reservation-editor-event-unlink"' )
	&& false !== strpos( $html44, 'aria-label="Unlink event"' ),
	$pass, $fail, $log );

// JS handler for change/unlink — still wired (unchanged).
$js_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
c7x12_ok( 'admin.js has reservation-editor-event-change click handler',
	false !== strpos( $js_src, "[data-eem-action=\"reservation-editor-event-change\"]" ),
	$pass, $fail, $log );

// Mockup file reflects hybrid pattern (meta-line read-only, rail card restored).
c7x12_ok( 'mockup edit_reservation_page.html has restored rail-card>rail-title>Linked Event',
	(bool) preg_match( '#<div class="rail-card">\s*<div class="rail-header">\s*<span class="rail-title">Linked Event<#s', $mockup ),
	$pass, $fail, $log );
c7x12_ok( 'mockup meta-line is read-only (no (change) or (unlink) anchor text within meta-line block)',
	(bool) ( preg_match( '#<div class="plugin-meta-line">.*?</div>\s*</div>#s', $mockup, $mockmeta )
		&& false === strpos( $mockmeta[0], '(change)' )
		&& false === strpos( $mockmeta[0], '(unlink)' ) ),
	$pass, $fail, $log );

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
