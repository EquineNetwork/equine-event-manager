<?php
/**
 * C7.X.10 toggle + affix fix-up smoke.
 *
 * Covers the 2 root-cause bugs surfaced at C7.X.9 post-merge visual
 * verify on reservation 44:
 *
 *   VV-2: Group section grounds-fee + deposit sub-toggles weren't
 *         hiding their dependent rows. Root cause: group was the only
 *         remaining partial still using the retired class-token
 *         controls system (`eem-ctrl--grounds-amt`, `eem-ctrl--deposit-
 *         amt`). The C7.X.9 `eemApplyControlsById` JS handler does
 *         `document.getElementById(id)` for each token, so class tokens
 *         silently no-op'd. Fix: convert to ID-based controls
 *         (`row-group-grounds-amt`, `row-group-deposit-amt`).
 *
 *   VV-3: Currency $ chip + input AND percent % chip + input render
 *         with visible seam — chip's inner edge has border-radius:0 in
 *         admin.css but the input picks up legacy `border-radius: 8px
 *         !important` from admin-legacy.css's 6 form-control blocks.
 *         Fix: surgical `:not(.eem-price-input):not(.eem-pct-input)`
 *         exclusions on every `input[type="number"]` selector in
 *         admin-legacy.css. Per CLAUDE.md C4 lesson + hygiene rule #7
 *         (legacy CSS is remediated, not extended).
 *
 *   VV-5: section order matches mockup; dropped during audit.
 *
 * Smoke shape: absence + presence + count-based per Whitney's review
 * note. Count-based assertion catches future regressions where someone
 * adds a 7th !important block without the exclusion.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x10_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.10 TOGGLE + AFFIX SMOKE ===\n";

$group_partial_path = EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-group.php';
$admin_css_path     = EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css';
$legacy_css_path    = EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin-legacy.css';

$group_src  = file_get_contents( $group_partial_path );
$admin_css  = file_get_contents( $admin_css_path );
$legacy_css = file_get_contents( $legacy_css_path );

// ── [VV-2] Group section ID-based controls ──────────────────────
echo "\n[VV-2] Group section — grounds-fee + deposit converted to ID-based controls\n";

// ABSENCE — stale class-token controls must be GONE from operative
// PHP (the audit-trail docblock comments mention the old tokens by
// name, so only flag the canonical pattern shapes used by the
// pre-C7.X.10 wiring: `'controls' => array( 'eem-ctrl--...' )` and
// `'row_classes' => 'eem-ctrl--...'`).
c7x10_ok( "group source has no operative \"'controls'   => array( 'eem-ctrl--grounds-amt' )\"", false === strpos( $group_src, "'controls'   => array( 'eem-ctrl--grounds-amt' )" ), $pass, $fail, $log );
c7x10_ok( "group source has no operative \"'controls'   => array( 'eem-ctrl--deposit-amt' )\"", false === strpos( $group_src, "'controls'   => array( 'eem-ctrl--deposit-amt' )" ), $pass, $fail, $log );
c7x10_ok( "group source has no operative \"'row_classes'  => 'eem-ctrl--grounds-amt'\"",        false === strpos( $group_src, "'row_classes'  => 'eem-ctrl--grounds-amt'" ),        $pass, $fail, $log );
c7x10_ok( "group source has no operative \"'row_classes'  => 'eem-ctrl--deposit-amt'\"",        false === strpos( $group_src, "'row_classes'  => 'eem-ctrl--deposit-amt'" ),        $pass, $fail, $log );

// PRESENCE — canonical row IDs + ID-based controls wiring.
c7x10_ok( 'group source emits row_id "row-group-grounds-amt"',                       false !== strpos( $group_src, "'row_id'       => 'row-group-grounds-amt'" ), $pass, $fail, $log );
c7x10_ok( 'group source emits row_id "row-group-deposit-amt"',                       false !== strpos( $group_src, "'row_id'       => 'row-group-deposit-amt'" ), $pass, $fail, $log );
c7x10_ok( 'grounds-fee toggle has controls array(  row-group-grounds-amt )',         false !== strpos( $group_src, "'controls'   => array( 'row-group-grounds-amt' )" ), $pass, $fail, $log );
c7x10_ok( 'deposit toggle has controls array( row-group-deposit-amt )',              false !== strpos( $group_src, "'controls'   => array( 'row-group-deposit-amt' )" ), $pass, $fail, $log );

// Live render — assert the rendered HTML carries the new IDs/controls.
wp_set_current_user( 1 );
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X.10 Toggle' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.X.10 Toggle ' . wp_generate_password( 6, false, false ),
) );
// Enable the group section so its body renders the sub-toggles + rows.
update_post_meta( $rid, '_en_group_reservations_enabled', 1 );
// Disable both sub-toggles so the conditional rows initially render hidden.
update_post_meta( $rid, '_en_group_rider_grounds_fee_enabled', 0 );
update_post_meta( $rid, '_en_group_rider_deposit_enabled',     0 );

$_GET['reservation_id'] = $rid;
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();

c7x10_ok( 'rendered grounds-fee toggle wrapper has data-controls="row-group-grounds-amt"',
	(bool) preg_match( '#data-eem-subsection="grounds-fee"[^>]*data-controls="row-group-grounds-amt"#', $html )
	|| (bool) preg_match( '#data-controls="row-group-grounds-amt"[^>]*data-eem-subsection="grounds-fee"#', $html ),
	$pass, $fail, $log );
c7x10_ok( 'rendered deposit toggle wrapper has data-controls="row-group-deposit-amt"',
	(bool) preg_match( '#data-eem-subsection="deposit"[^>]*data-controls="row-group-deposit-amt"#', $html )
	|| (bool) preg_match( '#data-controls="row-group-deposit-amt"[^>]*data-eem-subsection="deposit"#', $html ),
	$pass, $fail, $log );

c7x10_ok( 'rendered Grounds Fee Amount row carries id="row-group-grounds-amt"', false !== strpos( $html, 'id="row-group-grounds-amt"' ), $pass, $fail, $log );
c7x10_ok( 'rendered Deposit Amount row carries id="row-group-deposit-amt"',     false !== strpos( $html, 'id="row-group-deposit-amt"' ), $pass, $fail, $log );

// Initially-hidden rows carry eem-row--hidden (toggles are off in seed).
c7x10_ok( 'Grounds Fee Amount row carries eem-row--hidden when toggle is off',
	(bool) preg_match( '#<div class="[^"]*eem-row--hidden[^"]*"\s+id="row-group-grounds-amt"#', $html ),
	$pass, $fail, $log );
c7x10_ok( 'Deposit Amount row carries eem-row--hidden when toggle is off',
	(bool) preg_match( '#<div class="[^"]*eem-row--hidden[^"]*"\s+id="row-group-deposit-amt"#', $html ),
	$pass, $fail, $log );

// ── [VV-3] Affix seam — legacy CSS exclusions ───────────────────
echo "\n[VV-3] admin-legacy.css — :not(.eem-price-input):not(.eem-pct-input) exclusions\n";

// Count assertion — every input[type="number"] selector carries the exclusion.
// Per Whitney's review note, this catches a future regression where
// someone adds a 7th !important block without the exclusion.
$total_number_inputs = preg_match_all( '#input\[type="number"\]#', $legacy_css );
$with_exclusion      = preg_match_all( '#input\[type="number"\]:not\(\.eem-price-input\):not\(\.eem-pct-input\)#', $legacy_css );
c7x10_ok( "every input[type=\"number\"] selector in admin-legacy.css carries the exclusion ({$total_number_inputs} total, {$with_exclusion} excluded)",
	$total_number_inputs > 0 && $total_number_inputs === $with_exclusion,
	$pass, $fail, $log );

// ABSENCE — zero unprotected input[type="number"] selectors.
$unprotected = 0;
foreach ( explode( "\n", $legacy_css ) as $line ) {
	if ( false !== strpos( $line, 'input[type="number"]' ) && false === strpos( $line, ':not(.eem-price-input)' ) ) {
		$unprotected++;
	}
}
c7x10_ok( 'zero legacy lines have unprotected input[type="number"]', 0 === $unprotected, $pass, $fail, $log, "$unprotected unprotected line(s)" );

// PRESENCE — admin.css canonical affix rules still in place.
c7x10_ok( '.eem-price-input still has inner-right radius 0 (left side of seam)',
	(bool) preg_match( '#\.eem-price-input\s*\{[^}]*border-radius:\s*0\s+var\(--eem-radius\)\s+var\(--eem-radius\)\s+0\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );
c7x10_ok( '.eem-price-symbol still has inner-right radius 0',
	(bool) preg_match( '#\.eem-price-symbol\s*\{[^}]*border-radius:\s*var\(--eem-radius\)\s+0\s+0\s+var\(--eem-radius\)\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );
c7x10_ok( '.eem-pct-input still has inner-right radius 0',
	(bool) preg_match( '#\.eem-pct-input\s*\{[^}]*border-radius:\s*4px\s+0\s+0\s+4px\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );
c7x10_ok( '.eem-pct-symbol still has inner-left radius 0',
	(bool) preg_match( '#\.eem-pct-symbol\s*\{[^}]*border-radius:\s*0\s+4px\s+4px\s+0\s*;[^}]*\}#s', $admin_css ),
	$pass, $fail, $log );

// Live render — affix chrome still wraps the inputs as expected.
c7x10_ok( 'rendered group section emits at least one .eem-price-wrap containing .eem-price-input',
	(bool) preg_match( '#<div class="eem-price-wrap"><span class="eem-price-symbol">\$</span><input class="eem-price-input"#', $html ),
	$pass, $fail, $log );

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
