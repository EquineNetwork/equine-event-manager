<?php
/**
 * C7.X.9 toggle-behaviors fix-up smoke.
 *
 * Covers the 3 root-cause bug fixes landed in C7.X.9:
 *   A. Disabled-note unconditional emission — fixed via CSS gate
 *      (`.eem-section-disabled-note { display:none }` default +
 *      `.eem-section-body--disabled .eem-section-disabled-note
 *      { display:block }` descendant override).
 *   B. Duplicate stale state-class tokens — fixed by stripping
 *      `active` from stay-type-pair + `on`/`off` from toggle-label-row.
 *      JS `eemApplyControlsById` now toggles `eem-row--hidden` class
 *      (not just inline style) so initially-hidden rows can be revealed.
 *   C. Chevron-lock handler removed — peek-while-disabled is the
 *      canonical UX. Section bodies keep `--disabled` chrome when
 *      expanded via chevron click on a disabled section; no JS forces
 *      them back collapsed.
 *
 * All 4 probes use ABSENCE-assertions where appropriate (per the
 * C7.X.9 review note): the smoke must fail if someone re-introduces
 * the stale tokens or re-adds the lock handler.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x9_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.9 TOGGLE-BEHAVIORS SMOKE ===\n";

$css_path = EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css';
$js_path  = EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js';
$partial_stay_path = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-stay-type-pair.php';
$partial_tlr_path  = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-toggle-label-row.php';

$css = file_get_contents( $css_path );
$js  = file_get_contents( $js_path );

// ── [1] CSS disabled-note gate ──────────────────────────────────
echo "\n[1] CSS — disabled-note gate (presence + descendant override)\n";

// Locate the .eem-section-disabled-note rule block.
$has_default_hide = (bool) preg_match(
	'#\.eem-section-disabled-note\s*\{[^}]*\bdisplay\s*:\s*none\s*;?[^}]*\}#s',
	$css
);
c7x9_ok( '.eem-section-disabled-note default rule has display:none', $has_default_hide, $pass, $fail, $log );

$has_descendant_show = (bool) preg_match(
	'#\.eem-section-body--disabled\s+\.eem-section-disabled-note\s*\{[^}]*\bdisplay\s*:\s*block\s*;?[^}]*\}#s',
	$css
);
c7x9_ok( '.eem-section-body--disabled .eem-section-disabled-note override has display:block', $has_descendant_show, $pass, $fail, $log );

// Hygiene-rule guard — no !important in the new CSS gate.
$disabled_note_block = '';
if ( preg_match( '#\.eem-section-disabled-note\s*\{[^}]*\}#s', $css, $m ) ) {
	$disabled_note_block = $m[0];
}
c7x9_ok( 'no !important in .eem-section-disabled-note default block', false === stripos( $disabled_note_block, '!important' ), $pass, $fail, $log );

// Live render check — note IS emitted by skeleton, regardless of state
// (CSS does the gating).
wp_set_current_user( 1 );
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X.9 Toggle' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.X.9 Toggle ' . wp_generate_password( 6, false, false ),
) );
// Force a section disabled + a section enabled so both branches render.
update_post_meta( $rid, '_en_stalls_enabled',            0 );
update_post_meta( $rid, '_en_checkin_checkout_enabled', 1 );
update_post_meta( $rid, '_en_event_day_enabled',        1 );

// 2.3.56 — link a throwaway TEC event so the editor renders the configuration
// form. The hard gate shows only the event picker until a reservation is linked.
$eid = wp_insert_post( array( 'post_type' => 'tribe_events', 'post_status' => 'publish', 'post_title' => 'C7.X.9 Toggle Event' ) );
update_post_meta( $eid, '_equine_event_manager_reservation_id', $rid );
update_post_meta( $rid, '_en_event_id', $eid );

$_GET['reservation_id'] = $rid;
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();
wp_delete_post( $eid, true );

c7x9_ok( 'stall section renders eem-section-disabled-note (skeleton emits unconditionally)', false !== strpos( $html, 'data-eem-section-disabled-note="stall"' ), $pass, $fail, $log );
// Verify the body containing the note has --disabled when section is off.
c7x9_ok( 'stall body has eem-section-body--disabled at initial render', (bool) preg_match( '#<div class="[^"]*eem-section-body[^"]*eem-section-body--disabled[^"]*"\s+id="body-stall"#', $html ), $pass, $fail, $log );

// ── [2] Stay-type partial — absence-assertion for stale `active` ─
echo "\n[2] _partial-stay-type-pair — stale ` active` token stripped\n";
$stay_src = file_get_contents( $partial_stay_path );
// Stale duplicate would be the literal substring ' active' in $active_cls.
c7x9_ok( 'partial source has 0 occurrences of " eem-stay-type-btn--active active"', false === strpos( $stay_src, ' eem-stay-type-btn--active active' ), $pass, $fail, $log );
c7x9_ok( 'partial source has 0 occurrences of "eem-toggle--on on"',                false === strpos( $stay_src, 'eem-toggle--on on' ), $pass, $fail, $log );
c7x9_ok( 'partial source has 0 occurrences of "eem-toggle--off off"',              false === strpos( $stay_src, 'eem-toggle--off off' ), $pass, $fail, $log );
c7x9_ok( 'partial source still emits canonical eem-stay-type-btn--active', false !== strpos( $stay_src, 'eem-stay-type-btn--active' ), $pass, $fail, $log );

// Live render — assert active stay-type btn doesn't carry bare `active`.
$btn_pattern_no_bare_active = '#<div class="eem-stay-type-btn(?:--active)?\s+eem-stay-type-btn--active(?:\s+active)?"#';
// Simpler: assert no <div class="eem-stay-type-btn ... active"> WITHOUT also being canonical.
$active_matches = array();
preg_match_all( '#<div class="(eem-stay-type-btn[^"]*)"#', $html, $active_matches );
$bare_active_found = false;
foreach ( (array) $active_matches[1] as $cls ) {
	$tokens = preg_split( '/\s+/', trim( $cls ) );
	if ( in_array( 'active', $tokens, true ) ) { $bare_active_found = true; break; }
}
c7x9_ok( 'no rendered eem-stay-type-btn carries bare "active" token', ! $bare_active_found, $pass, $fail, $log );

// ── [3] Toggle-label-row partial — absence-assertion for stale on/off
echo "\n[3] _partial-toggle-label-row — stale on/off wrapper + inner duplicates stripped\n";
$tlr_src = file_get_contents( $partial_tlr_path );
// The pre-C7.X.9 emission was: <div class="eem-toggle-label-row <on|off>" ...
// Detect that the wrapper template no longer interpolates a bare state token.
c7x9_ok( 'partial source no longer has eem-toggle-label-row followed by <wrapper_state>', false === strpos( $tlr_src, 'eem-toggle-label-row <?php echo esc_attr( $wrapper_state )' ), $pass, $fail, $log );
c7x9_ok( 'partial source has no $wrapper_state assignment', false === strpos( $tlr_src, '$wrapper_state' ), $pass, $fail, $log );
c7x9_ok( 'partial source emits canonical eem-toggle-label-row class', false !== strpos( $tlr_src, 'class="eem-toggle-label-row"' ), $pass, $fail, $log );

// Live render — assert toggle-label-row wrapper doesn't carry bare on/off.
$tlr_matches = array();
preg_match_all( '#<div class="(eem-toggle-label-row[^"]*)"#', $html, $tlr_matches );
$bare_on_found = false;
foreach ( (array) $tlr_matches[1] as $cls ) {
	$tokens = preg_split( '/\s+/', trim( $cls ) );
	if ( in_array( 'on', $tokens, true ) || in_array( 'off', $tokens, true ) ) { $bare_on_found = true; break; }
}
c7x9_ok( 'no rendered eem-toggle-label-row carries bare "on" or "off" token', ! $bare_on_found, $pass, $fail, $log );

// Inner .eem-toggle inside a toggle-label-row should not carry bare on/off.
$inner_matches = array();
preg_match_all( '#<div class="(eem-toggle eem-toggle--(?:on|off)[^"]*)"#', $html, $inner_matches );
$inner_bare = false;
foreach ( (array) $inner_matches[1] as $cls ) {
	$tokens = preg_split( '/\s+/', trim( $cls ) );
	// Allow eem-toggle, eem-toggle--on, eem-toggle--off only — flag bare on/off.
	foreach ( $tokens as $t ) {
		if ( 'on' === $t || 'off' === $t ) { $inner_bare = true; break 2; }
	}
}
c7x9_ok( 'no rendered .eem-toggle inner indicator carries bare "on" or "off" token', ! $inner_bare, $pass, $fail, $log );

// ── [4] JS — applyControlsById class-toggle + lock-handler removal ─
echo "\n[4] JS — eemApplyControlsById toggles eem-row--hidden class + lock handler removed\n";

// Locate the eemApplyControlsById function body.
$body = '';
if ( preg_match( '#function\s+eemApplyControlsById\s*\(controller\)\s*\{(.+?)\n\t\}\n#s', $js, $m ) ) {
	$body = $m[1];
}
c7x9_ok( 'eemApplyControlsById function found', '' !== $body, $pass, $fail, $log );
c7x9_ok( 'eemApplyControlsById toggles eem-row--hidden class',          false !== strpos( $body, "classList.toggle('eem-row--hidden'" ), $pass, $fail, $log );
// Absence guard — the old read accepted stale `on` / `active` tokens.
c7x9_ok( 'eemApplyControlsById no longer reads bare "on" class token',   false === strpos( $body, "classList.contains('on')" ),  $pass, $fail, $log );
c7x9_ok( 'eemApplyControlsById no longer reads bare "active" class token', false === strpos( $body, "classList.contains('active')" ), $pass, $fail, $log );

// Lock-handler absence — the deleted handler had a distinct signature.
c7x9_ok( 'JS no longer contains "Lock chevron when disabled" handler', false === strpos( $js, 'Lock chevron when disabled' ), $pass, $fail, $log );
c7x9_ok( 'JS no longer contains the wrong-target parentElement.parentElement chain', false === strpos( $js, 'collapse2.parentElement ? collapse2.parentElement.parentElement.querySelector' ), $pass, $fail, $log );

// Initial-pass DOMContentLoaded handler still calls applyControlsById on
// every stay-type + toggle-switch-row controller (guards against a
// regression that strips the init pass).
c7x9_ok( 'DOMContentLoaded init pass still calls eemApplyControlsById on toggle-switch-row + stay-type controllers',
	false !== strpos( $js, "querySelectorAll('[data-eem-action=\"reservation-editor-toggle-switch-row\"], [data-eem-action=\"reservation-editor-toggle-stay-type\"]').forEach(eemApplyControlsById)" ),
	$pass, $fail, $log );

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
