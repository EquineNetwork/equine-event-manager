<?php
/**
 * Smoke test — C7.X.21 Typed-confirm word changed to "DELETE".
 *
 * UX change: permanent-delete typed-confirmation was "type the exact reservation
 * title" — fragile, special-char-prone, unfamiliar. Changed to the constant word
 * "DELETE" (case-sensitive uppercase) for both client (admin.js) and server
 * (class-eem-reservations-list-page.php).
 *
 * What changed:
 *   - openDeletePermanentlyModal() defines CONFIRM_WORD = 'DELETE' and uses it
 *     for input comparison instead of resTitle.
 *   - Modal copy now says "type DELETE below" (not "type the reservation title").
 *   - No <code> block in modal HTML (previously showed the dynamic title).
 *   - Server handle_delete_permanently() checks 'DELETE' !== $typed.
 *
 * Smoke philosophy: source-presence assertions.
 * MANDATORY BROWSER SELF-VERIFY:
 *   1. Click Delete Permanently → typed-confirm modal opens.
 *   2. Type 'delete' (lowercase) → Delete Permanently button stays DISABLED.
 *   3. Type 'DELETE' (uppercase) → button ENABLES.
 *   4. Click Delete Permanently → AJAX fires → row disappears + toast.
 *   5. Restore still one-click (regression check).
 *
 * Run via run-all.sh or directly:
 *   php tests/smoke/c7x21-delete-confirm-word-smoke.php
 */

$pass = 0;
$fail = 0;
$log  = array();

function c7x21_ok( string $label, bool $condition, int &$pass, int &$fail, array &$log ): void {
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
$php_path    = $plugin_root . '/admin/class-eem-reservations-list-page.php';

$js_raw  = file_get_contents( $js_path );
$php_raw = file_get_contents( $php_path );

echo "\n=== C7.X.21 smoke ===\n\n";

/* ─────────────────────────────────────────────────────────────────────────────
   CLIENT-SIDE: CONFIRM_WORD constant and comparisons
   ───────────────────────────────────────────────────────────────────────────── */

echo "-- Client: CONFIRM_WORD constant --\n";

// CONFIRM_WORD must be defined as 'DELETE' (exact string, uppercase).
$has_confirm_word = (bool) preg_match(
	"~var\s+CONFIRM_WORD\s*=\s*['\"]DELETE['\"]~",
	$js_raw
);
c7x21_ok( "admin.js: var CONFIRM_WORD = 'DELETE' defined in openDeletePermanentlyModal",
	$has_confirm_word, $pass, $fail, $log );

// Input validation must compare against CONFIRM_WORD, not a title variable.
$input_uses_confirm_word = (bool) preg_match(
	'~input\.value\s*!==\s*CONFIRM_WORD~',
	$js_raw
);
c7x21_ok( "admin.js: input.value !== CONFIRM_WORD used to disable/enable button",
	$input_uses_confirm_word, $pass, $fail, $log );

// Confirm button click guard must also use CONFIRM_WORD.
$click_guard_uses_confirm_word = (bool) preg_match(
	'~if\s*\(\s*input\.value\s*!==\s*CONFIRM_WORD\s*\)\s*return~',
	$js_raw
);
c7x21_ok( "admin.js: confirmBtn click guard uses CONFIRM_WORD (not resTitle)",
	$click_guard_uses_confirm_word, $pass, $fail, $log );

// Negative: the old pattern (input.value !== resTitle) must NOT appear.
$no_old_title_compare = ! (bool) preg_match(
	'~input\.value\s*!==\s*resTitle~',
	$js_raw
);
c7x21_ok( "admin.js: input.value !== resTitle (old pattern) not present",
	$no_old_title_compare, $pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   CLIENT-SIDE: Modal copy
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n-- Client: modal copy --\n";

// Modal instruction must say "type DELETE" (not "type the reservation title").
$copy_says_type_delete = (bool) preg_match(
	'~[Tt]ype\s+<strong>DELETE</strong>~',
	$js_raw
);
c7x21_ok( 'admin.js: modal body says "type <strong>DELETE</strong>"',
	$copy_says_type_delete, $pass, $fail, $log );

// Placeholder must reference DELETE.
$placeholder_says_delete = (bool) preg_match(
	'~placeholder="Type DELETE to confirm"~',
	$js_raw
);
c7x21_ok( 'admin.js: input placeholder is "Type DELETE to confirm"',
	$placeholder_says_delete, $pass, $fail, $log );

// Negative: old placeholder ("Type the reservation title") must not appear.
$no_old_placeholder = ! (bool) preg_match(
	'~placeholder=\\\\"Type the reservation title~',
	$js_raw
);
c7x21_ok( 'admin.js: old placeholder "Type the reservation title" not in JS',
	$no_old_placeholder, $pass, $fail, $log );

// Negative: no <code> block in modal HTML (was used to echo the dynamic title).
// A <code> tag appearing inside the openDeletePermanentlyModal HTML string would
// indicate the old title-display is still present.
$no_code_tag_in_modal = ! (bool) preg_match(
	'~<code>[^<]*\$\{resTitle\}[^<]*</code>~',
	$js_raw
);
c7x21_ok( 'admin.js: no ${resTitle} interpolation in <code> tag in modal HTML',
	$no_code_tag_in_modal, $pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   CLIENT-SIDE: submitReservationAction call passes CONFIRM_WORD
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n-- Client: AJAX payload --\n";

// confirmBtn click must pass CONFIRM_WORD as confirmation_title in the AJAX payload.
$ajax_passes_confirm_word = (bool) preg_match(
	'~confirmation_title\s*:\s*CONFIRM_WORD~',
	$js_raw
);
c7x21_ok( "admin.js: submitReservationAction called with { confirmation_title: CONFIRM_WORD }",
	$ajax_passes_confirm_word, $pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   SERVER-SIDE: PHP validation
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n-- Server: PHP confirmation check --\n";

// Server must reject if $typed !== 'DELETE'.
$server_checks_delete = (bool) preg_match(
	'~\'DELETE\'\s*!==\s*\$typed~',
	$php_raw
);
c7x21_ok( "class-eem-reservations-list-page.php: 'DELETE' !== \$typed gate present",
	$server_checks_delete, $pass, $fail, $log );

// Negative: old server check (typed !== post_title) must NOT appear near delete permanently handler.
$no_old_server_check = ! (bool) preg_match(
	'~\$post->post_title\s*!==\s*\$typed~',
	$php_raw
);
c7x21_ok( 'class-eem-reservations-list-page.php: $post->post_title !== $typed (old check) removed',
	$no_old_server_check, $pass, $fail, $log );

// Confirm $typed is still read from $_POST['confirmation_title'].
$typed_from_post = (bool) preg_match(
	'~\$typed\s*=\s*isset\(\s*\$_POST\[[\'"]confirmation_title[\'"]\]~',
	$php_raw
);
c7x21_ok( 'class-eem-reservations-list-page.php: $typed still read from $_POST[\'confirmation_title\']',
	$typed_from_post, $pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   REGRESSION: Restore still one-click (no modal)
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n-- Regression: Restore still wired --\n";

$restore_wired = (bool) preg_match(
	"~'reservation-restore'\s*:\s*function\s*\([^)]*\)\s*\{[^}]*submitReservationAction~s",
	$js_raw
);
c7x21_ok( "admin.js: reservation-restore still calls submitReservationAction directly (no modal)",
	$restore_wired, $pass, $fail, $log );

// Restore must NOT call openDeletePermanentlyModal (regression guard).
$restore_no_modal = ! (bool) preg_match(
	"~'reservation-restore'\s*:\s*function\s*\([^)]*\)\s*\{[^}]*openDeletePermanentlyModal~s",
	$js_raw
);
c7x21_ok( "admin.js: reservation-restore does not call openDeletePermanentlyModal",
	$restore_no_modal, $pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   VERSION
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n-- Version --\n";

$main_php = file_get_contents( $plugin_root . '/equine-event-manager.php' );
c7x21_ok( 'equine-event-manager.php: version define is valid semver',
	(bool) preg_match( "~define\(\s*'EQUINE_EVENT_MANAGER_VERSION',\s*'[0-9]+\.[0-9]+\.[0-9]+'\s*\)~", $main_php ),
	$pass, $fail, $log );

/* ─────────────────────────────────────────────────────────────────────────────
   SUMMARY
   ───────────────────────────────────────────────────────────────────────────── */

echo "\n";
foreach ( $log as $line ) {
	echo $line . "\n";
}

printf( "\n=== RESULT: %d passed, %d failed ===\n\n", $pass, $fail );
