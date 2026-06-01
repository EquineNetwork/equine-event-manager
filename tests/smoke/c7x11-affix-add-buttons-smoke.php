<?php
/**
 * C7.X.11 affix-seam + add-button fix-up smoke.
 *
 * Two root-cause bugs from C7.X.10 visual verify:
 *
 *   VV-3 recurrence: `.eem-repeat-price-in` (Add-On price inputs in
 *     general + RV add-on tables) was missing from the
 *     `:not(.eem-price-input):not(.eem-pct-input)` exclusion list
 *     added at C7.X.10. Add-On rows still picked up legacy
 *     `border-radius: 8px !important` and showed the seam. Fix:
 *     extend exclusion to all three classes across all 19
 *     `input[type="number"]` selectors in admin-legacy.css.
 *
 *   VV-6: "+ Add Add-On" and "+ Add RV Add-On" buttons did nothing.
 *     Handler at admin.js:1831 did
 *     `addBtn.closest('.eem-repeating-row-helper')` to find the
 *     template/tbody ID source, but C7.X.4+ partials emit those
 *     attributes ON THE BUTTON itself (not on a wrapper). The
 *     `.eem-repeating-row-helper` wrapper class lives only in the
 *     now-orphan `_repeating-row-helper.php` partial. Fix: handler
 *     reads attrs from button when present, fallback to ancestor
 *     for any (orphan) caller still using the wrapper.
 *
 *   Structural deliverable — form-control class enumeration cross-
 *   check. C4 → C7.X.4 → C7.X.10 → C7.X.11 is FOUR iterations of
 *   the same class of bug: developer ships a new form-control
 *   class, forgets to add `:not(.classname)` exclusions in
 *   admin-legacy.css. The C7.X.10 process-miss note said "future
 *   form-control ports must run the checklist." VV-3 recurring on
 *   `.eem-repeat-price-in` proves the checklist as written is
 *   insufficient — it relies on developer memory. The structural
 *   fix lives in this smoke: enumerate every distinct class that
 *   appears on `<input type="number">` elements in the live editor
 *   render and cross-check that each has a matching `:not()` in
 *   every `input[type="number"]` selector in admin-legacy.css.
 *   That removes the "did I remember to enumerate?" reliance.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x11_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.11 AFFIX SEAM + ADD-BUTTONS SMOKE ===\n";

$legacy_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin-legacy.css' );
$js_src     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

wp_set_current_user( 1 );
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X.11 AddBtn' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.X.11 AddBtn ' . wp_generate_password( 6, false, false ),
) );
// Enable RV + addons + group sections so all price-input shapes render.
update_post_meta( $rid, '_en_rv_enabled',                  1 );
update_post_meta( $rid, '_en_general_addons_enabled',      1 );
update_post_meta( $rid, '_en_group_reservations_enabled',  1 );
update_post_meta( $rid, '_en_general_addons', array(
	array( 'name' => 'Feed Bag', 'price' => 25.00, 'per_label' => 'bag', 'applies_to' => 'any' ),
) );

// 2.3.56 — link a throwaway TEC event so the editor renders the configuration
// form. The hard gate shows only the event picker until a reservation is linked.
$eid = wp_insert_post( array( 'post_type' => 'tribe_events', 'post_status' => 'publish', 'post_title' => 'C7.X.11 AddBtn Event' ) );
update_post_meta( $eid, '_equine_event_manager_reservation_id', $rid );
update_post_meta( $rid, '_en_event_id', $eid );

$_GET['reservation_id'] = $rid;
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();
wp_delete_post( $eid, true );

// ── [1] Five-class exclusion count assertion ────────────────────
// C7.X.11 update: the structural enumeration smoke at [2] surfaced
// two MORE form-control classes (`.eem-field-input` + `.eem-zone-
// price-in`) that the audit's manual class enumeration missed.
// Exclusion list grew from 3 (post-C7.X.10 plan) to 5 in the same
// commit. This is the structural fix paying off on its first run.
echo "\n[1] admin-legacy.css — :not() exclusion covers 5 form-control classes\n";

$total = preg_match_all( '#input\[type="number"\]#', $legacy_css );
$with5 = preg_match_all( '#input\[type="number"\]:not\(\.eem-price-input\):not\(\.eem-pct-input\):not\(\.eem-repeat-price-in\):not\(\.eem-zone-price-in\):not\(\.eem-field-input\)#', $legacy_css );
c7x11_ok( "every input[type=\"number\"] selector carries all 5 exclusions ({$total} total, {$with5} with all 5)",
	$total > 0 && $total === $with5,
	$pass, $fail, $log );

// Absence — zero unprotected.
$unprotected = 0;
foreach ( explode( "\n", $legacy_css ) as $line ) {
	if ( false !== strpos( $line, 'input[type="number"]' )
		&& false === strpos( $line, ':not(.eem-field-input)' ) ) {
		$unprotected++;
	}
}
c7x11_ok( 'zero legacy lines have input[type="number"] without :not(.eem-field-input)', 0 === $unprotected, $pass, $fail, $log );

// ── [2] STRUCTURAL — form-control class enumeration cross-check ─
echo "\n[2] STRUCTURAL — enumerate <input type='number'> classes + verify exclusion coverage\n";
echo "    (breaks the C4 → C7.X.4 → C7.X.10 → C7.X.11 recurring-bug cycle)\n";

// Regex scope per Whitney clarification #1: match ONLY <input type="number">
// elements. Capture every distinct class token. Cross-check each appears
// in the exclusion list of EVERY input[type="number"] selector in
// admin-legacy.css.
$number_input_classes = array();
if ( preg_match_all( '#<input\b[^>]*\btype="number"[^>]*\bclass="([^"]+)"#', $html, $m1 )
	|| preg_match_all( '#<input\b[^>]*\bclass="([^"]+)"[^>]*\btype="number"#', $html, $m2 ) ) {
	// Combine both orderings (HTML attribute order isn't guaranteed).
	$class_attrs = array_merge(
		isset( $m1[1] ) ? (array) $m1[1] : array(),
		isset( $m2[1] ) ? (array) $m2[1] : array()
	);
	foreach ( $class_attrs as $attr ) {
		foreach ( preg_split( '/\s+/', trim( $attr ) ) as $tok ) {
			if ( '' !== $tok ) { $number_input_classes[ $tok ] = true; }
		}
	}
}
$enumerated = array_keys( $number_input_classes );
sort( $enumerated );
c7x11_ok( 'at least one <input type="number"> rendered (sanity)', count( $enumerated ) > 0, $pass, $fail, $log, 'enumerated=' . implode( ',', $enumerated ) );
echo "    enumerated classes: " . ( $enumerated ? implode( ', ', $enumerated ) : '(none)' ) . "\n";

// Every enumerated class must appear as a `:not(.classname)` clause in
// every `input[type="number"]` selector that targets these inputs.
// We require: for EACH enumerated class, the count of selectors
// carrying its exclusion equals the count of total `input[type="number"]`
// selectors. This is the structural guarantee: any new form-control
// class shipped on a number input MUST land in the exclusion list at
// the same time, or this smoke trips.
$missing_coverage = array();
foreach ( $enumerated as $cls ) {
	$pattern = '#input\[type="number"\][^,{\n]*:not\(\.' . preg_quote( $cls, '#' ) . '\)#';
	$cls_count = preg_match_all( $pattern, $legacy_css );
	if ( $cls_count !== $total ) {
		$missing_coverage[ $cls ] = "{$cls_count}/{$total}";
	}
}
c7x11_ok( 'every enumerated <input type="number"> class has exclusion coverage in admin-legacy.css',
	empty( $missing_coverage ),
	$pass, $fail, $log,
	empty( $missing_coverage ) ? '' : 'missing: ' . wp_json_encode( $missing_coverage )
);

// ── [3] Cache-bust constant ─────────────────────────────────────
echo "\n[3] EQUINE_EVENT_MANAGER_VERSION bumped for CSS cache-bust\n";
// C7.X.13 — assertion made forward-compatible. Each cache-bust bump
// (2.3.1, 2.3.2, …) shouldn't trip this smoke. Compare version ≥ 2.3.1
// AND assert no `2.3.0` drift (the original cache-bust precondition).
c7x11_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.1 (cache-bust constant bumped post-C7.X.10)',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.1', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );
$main_file = file_get_contents( EQUINE_EVENT_MANAGER_FILE );
c7x11_ok( 'plugin header Version: matches EQUINE_EVENT_MANAGER_VERSION constant (single source of truth, no drift)',
	false !== strpos( $main_file, ' * Version:           ' . EQUINE_EVENT_MANAGER_VERSION ),
	$pass, $fail, $log );
c7x11_ok( 'no stale `2.3.0` hardcoded in plugin main file (Version: line + constant) outside @since tags',
	false === strpos( $main_file, "'2.3.0'" )
	&& false === strpos( $main_file, 'Version:           2.3.0' ),
	$pass, $fail, $log );

// ── [4] Add-row handler — attrs on button + JS reads them ───────
echo "\n[4] Add-row handler — VV-6 fix\n";

// Rendered HTML — both add-buttons carry both required attrs.
preg_match_all(
	'#<button[^>]*data-eem-action="reservation-editor-add-repeating-row"[^>]*>#',
	$html,
	$btns
);
c7x11_ok( 'editor renders exactly 2 add-row buttons (general add-ons + RV add-ons)',
	2 === count( $btns[0] ),
	$pass, $fail, $log, 'count=' . count( $btns[0] ) );

$all_have_attrs = true;
foreach ( (array) $btns[0] as $btn ) {
	if ( false === strpos( $btn, 'data-eem-repeating-template=' )
		|| false === strpos( $btn, 'data-eem-repeating-tbody=' ) ) {
		$all_have_attrs = false; break;
	}
}
c7x11_ok( 'every add-row button carries data-eem-repeating-template AND data-eem-repeating-tbody attrs',
	$all_have_attrs, $pass, $fail, $log );

// Each button's template + tbody actually exist in the rendered HTML.
foreach ( array( 'eem-general-addons-row-template' => 'eem-general-addons-rows',
                 'eem-rv-addons-row-template'      => 'eem-rv-addons-rows' ) as $tpl => $tb ) {
	c7x11_ok( "template id={$tpl} exists in rendered HTML",
		false !== strpos( $html, 'id="' . $tpl . '"' ), $pass, $fail, $log );
	c7x11_ok( "tbody id={$tb} exists in rendered HTML",
		false !== strpos( $html, 'id="' . $tb . '"' ), $pass, $fail, $log );
}

// JS source — handler reads from button OR ancestor (the C7.X.11 fallback).
c7x11_ok( 'JS handler uses addBtn.hasAttribute("data-eem-repeating-template") to source attrs',
	false !== strpos( $js_src, "addBtn.hasAttribute('data-eem-repeating-template')" ),
	$pass, $fail, $log );
c7x11_ok( 'JS handler still falls back to .eem-repeating-row-helper ancestor (orphan-partial back-compat)',
	false !== strpos( $js_src, ".closest('.eem-repeating-row-helper')" ),
	$pass, $fail, $log );

// Anti-pattern guard — pre-C7.X.11 form must not return.
c7x11_ok( 'JS handler no longer ONLY reads from .eem-repeating-row-helper ancestor (the pre-C7.X.11 bug)',
	false === strpos( $js_src, "var container = addBtn.closest('.eem-repeating-row-helper');\n\t\t\tif (!container) return;" ),
	$pass, $fail, $log );

// Functional probe — simulate the add-row clone path in PHP. Extract the
// template HTML, simulate __index__ substitution, assert the resulting
// row carries the expected name pattern with index 1 (one row exists,
// next index = 1).
if ( preg_match( '#<template id="eem-general-addons-row-template"[^>]*>(.*?)</template>#s', $html, $tpl_m ) ) {
	$row_tpl    = $tpl_m[1];
	$next_index = 1; // tbody has 1 existing row from seed
	$cloned     = str_replace( '__index__', (string) $next_index, $row_tpl );
	c7x11_ok( 'cloned row substitutes __index__ → 1 in name attrs (functional simulation)',
		false !== strpos( $cloned, 'name="en_reservation[general_addons][1][name]"' )
		&& false !== strpos( $cloned, 'name="en_reservation[general_addons][1][price]"' ),
		$pass, $fail, $log );
} else {
	c7x11_ok( 'extracted general-addons row template', false, $pass, $fail, $log, 'template tag not found' );
}

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
