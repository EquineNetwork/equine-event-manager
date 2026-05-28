<?php
/**
 * Smoke test — C7.X.20 Delete Permanently modal class-name fix.
 *
 * Root cause diagnosed: openDeletePermanentlyModal() created the overlay element
 * with className = 'eem-modal-overlay eem-modal-overlay--active' — classes that do
 * not exist in admin.css. The inner div used class="eem-modal eem-modal--sm" which
 * defaults to display:none (per .eem-modal { display:none }) and never had .open
 * added. Result: modal was appended to the DOM but permanently invisible. Restore
 * worked because it calls submitReservationAction() (form submit + redirect) which
 * doesn't need a modal at all. Delete Permanently needs the typed-confirm modal,
 * which is why it appeared as "nothing happens" while Restore worked.
 *
 * Fix (C7.X.20):
 *   - overlay.className = 'eem-modal' (matches CSS backdrop rule)
 *   - overlay.classList.add('open') after append (matches .eem-modal.open { display:flex })
 *   - inner card uses .eem-modal-card (not .eem-modal)
 *   - header uses .eem-modal-head (not .eem-modal-header)
 *   - footer uses .eem-modal-foot (not .eem-modal-footer)
 *   - Added .eem-modal-head--danger and .eem-modal-title--danger CSS modifiers
 *
 * Smoke philosophy: Source-presence assertions only.
 * MANDATORY BROWSER SELF-VERIFY required for runtime confirmation:
 *   1. Click Delete Permanently → typed-confirm modal OPENS (dark backdrop + card)
 *   2. Wrong title keeps Delete disabled → exact title enables → Delete → AJAX → row gone + toast
 *   3. Restore still fires (regression check).
 *
 * Run via run-all.sh or directly:
 *   php tests/smoke/c7x20-delete-perm-modal-smoke.php
 */

$pass = 0;
$fail = 0;
$log  = array();

function c7x20_ok( string $label, bool $condition, int &$pass, int &$fail, array &$log ): void {
	if ( $condition ) {
		++$pass;
		$log[] = "  PASS  {$label}";
	} else {
		++$fail;
		$log[] = "✗ FAIL  {$label}";
	}
}

$plugin_root = dirname( dirname( __DIR__ ) );
$js_path     = $plugin_root . '/assets/js/admin.js';
$css_path    = $plugin_root . '/assets/css/admin.css';

$js_raw  = file_get_contents( $js_path );
$css_raw = file_get_contents( $css_path );

// Strip CSS comments so patterns don't hit comment text.
$css_nc = preg_replace( '/\/\*.*?\*\//s', '', $css_raw );

echo "\n=== C7.X.20 smoke ===\n\n";

/* ─────────────────────────────────────────────────────────────────────────────
   ISSUE: Wrong CSS class names in openDeletePermanentlyModal — modal invisible
   ───────────────────────────────────────────────────────────────────────────── */

echo "-- Wrong class names fixed --\n";

// Negative: the broken class name must NOT appear.
$no_old_overlay_class = ! (bool) preg_match(
	"~overlay\.className\s*=\s*['\"]eem-modal-overlay~",
	$js_raw
);
c7x20_ok( 'admin.js: overlay.className no longer uses eem-modal-overlay (broken class)',
	$no_old_overlay_class, $pass, $fail, $log );

// Positive: outer overlay must now be assigned 'eem-modal'.
$has_correct_overlay_class = (bool) preg_match(
	"~overlay\.className\s*=\s*['\"]eem-modal['\"]~",
	$js_raw
);
c7x20_ok( "admin.js: overlay.className = 'eem-modal' (correct backdrop class)",
	$has_correct_overlay_class, $pass, $fail, $log );

// Positive: .open must be added AFTER the element is appended.
$has_add_open = (bool) preg_match(
	'~overlay\.classList\.add\(\s*[\'"]open[\'"]\s*\)~',
	$js_raw
);
c7x20_ok( "admin.js: overlay.classList.add('open') called to make modal visible",
	$has_add_open, $pass, $fail, $log );

// Positive: appendChild must precede classList.add in source order for the overlay.
// Find the position of each pattern within the openDeletePermanentlyModal function.
$append_pos    = strpos( $js_raw, 'document.body.appendChild(overlay)' );
$add_open_pos  = strpos( $js_raw, "overlay.classList.add('open')" );
$append_before_open = ( $append_pos !== false && $add_open_pos !== false && $append_pos < $add_open_pos );
c7x20_ok( 'admin.js: appendChild(overlay) appears before classList.add("open") in source',
	$append_before_open, $pass, $fail, $log );

echo "\n-- Inner element class names fixed --\n";

// Negative: old inner class 'eem-modal eem-modal--sm' must not be the inner div class.
// Check that eem-modal-card is the inner wrapper (not eem-modal wrapping eem-modal-card).
$no_old_inner = ! (bool) preg_match(
	"~'<div class=\"eem-modal eem-modal--sm\">~",
	$js_raw
);
c7x20_ok( "admin.js: inner wrapper no longer uses 'eem-modal eem-modal--sm'",
	$no_old_inner, $pass, $fail, $log );

// Positive: inner card uses eem-modal-card.
$has_modal_card = (bool) preg_match(
	"~'<div class=\"eem-modal-card\">~",
	$js_raw
);
c7x20_ok( "admin.js: inner wrapper uses eem-modal-card",
	$has_modal_card, $pass, $fail, $log );

// Negative: old eem-modal-header must not appear as a CSS class in the modal HTML string.
// Checks within a class="..." context only — the string may appear in comments.
$no_old_header = ! (bool) preg_match(
	'~class=\\\\"eem-modal-header~',
	$js_raw
);
c7x20_ok( 'admin.js: class="eem-modal-header" (broken) not in JS HTML string',
	$no_old_header, $pass, $fail, $log );

// Positive: header now uses eem-modal-head.
$has_modal_head = (bool) preg_match(
	"~eem-modal-head~",
	$js_raw
);
c7x20_ok( 'admin.js: eem-modal-head (correct) used for modal header',
	$has_modal_head, $pass, $fail, $log );

// Negative: old eem-modal-footer must not appear as a CSS class in the modal HTML string.
$no_old_footer = ! (bool) preg_match(
	'~class=\\\\"eem-modal-footer~',
	$js_raw
);
c7x20_ok( 'admin.js: class="eem-modal-footer" (broken) not in JS HTML string',
	$no_old_footer, $pass, $fail, $log );

// Positive: footer now uses eem-modal-foot.
$has_modal_foot = (bool) preg_match(
	"~eem-modal-foot~",
	$js_raw
);
c7x20_ok( 'admin.js: eem-modal-foot (correct) used for modal footer',
	$has_modal_foot, $pass, $fail, $log );

echo "\n-- CSS: .eem-modal.open makes the modal visible --\n";

// The CSS must define .eem-modal.open { display: flex } — this is the pattern
// the fixed JS depends on to make the overlay visible.
$css_modal_open = (bool) preg_match(
	'~\.eem-modal\s*\.\s*open\s*\{[^}]*display\s*:\s*flex~s',
	$css_nc
);
c7x20_ok( 'admin.css: .eem-modal.open { display: flex } defined (visibility mechanism)',
	$css_modal_open, $pass, $fail, $log );

// .eem-modal default must be display:none (so it's hidden before .open is added).
$css_modal_hidden = (bool) preg_match(
	'~\.eem-modal\s*\{[^}]*display\s*:\s*none~s',
	$css_nc
);
c7x20_ok( 'admin.css: .eem-modal { display: none } default ensures modal starts hidden',
	$css_modal_hidden, $pass, $fail, $log );

// .eem-modal-card must be defined.
$css_modal_card = (bool) preg_match(
	'~\.eem-modal-card\s*\{~',
	$css_nc
);
c7x20_ok( 'admin.css: .eem-modal-card defined',
	$css_modal_card, $pass, $fail, $log );

echo "\n-- CSS: danger header modifiers added (C7.X.20) --\n";

// eem-modal-head--danger modifier exists.
$css_head_danger = (bool) preg_match(
	'~\.eem-modal-head--danger\s*\{[^}]*background~s',
	$css_nc
);
c7x20_ok( 'admin.css: .eem-modal-head--danger { background: ... } defined',
	$css_head_danger, $pass, $fail, $log );

// eem-modal-title--danger modifier exists.
$css_title_danger = (bool) preg_match(
	'~\.eem-modal-title--danger\s*\{[^}]*color~s',
	$css_nc
);
c7x20_ok( 'admin.css: .eem-modal-title--danger { color: ... } defined',
	$css_title_danger, $pass, $fail, $log );

echo "\n-- Dispatcher integrity: reservation-delete-permanently still wired --\n";

// The action key must still be in the actions object.
$has_action = (bool) preg_match(
	"~'reservation-delete-permanently'\s*:\s*function~",
	$js_raw
);
c7x20_ok( "admin.js: 'reservation-delete-permanently' action still registered in dispatcher",
	$has_action, $pass, $fail, $log );

// The action must call openDeletePermanentlyModal.
$calls_modal = (bool) preg_match(
	"~'reservation-delete-permanently'\s*:\s*function\s*\([^)]*\)\s*\{[^}]*openDeletePermanentlyModal~s",
	$js_raw
);
c7x20_ok( "admin.js: reservation-delete-permanently handler calls openDeletePermanentlyModal",
	$calls_modal, $pass, $fail, $log );

// Restore still wired (regression).
$restore_wired = (bool) preg_match(
	"~'reservation-restore'\s*:\s*function\s*\([^)]*\)\s*\{[^}]*submitReservationAction~s",
	$js_raw
);
c7x20_ok( "admin.js: reservation-restore still calls submitReservationAction (regression check)",
	$restore_wired, $pass, $fail, $log );

echo "\n-- Version --\n";

$main_php = file_get_contents( $plugin_root . '/equine-event-manager.php' );
c7x20_ok( 'equine-event-manager.php: version is 2.3.10',
	(bool) preg_match( "~define\(\s*'EQUINE_EVENT_MANAGER_VERSION',\s*'2\.3\.10'\s*\)~", $main_php ),
	$pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   SUMMARY
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n";
foreach ( $log as $line ) {
	echo $line . "\n";
}

printf( "\n=== RESULT: %d passed, %d failed ===\n\n", $pass, $fail );
