<?php
/**
 * C7.X.17 — Whitney's C7.X.16 visual verify fix-ups (5 issues).
 *
 *   A — Global border-radius token: --eem-radius 4px → 3px.
 *       Cross-input-type :not() exclusion chain extended to ALL input
 *       types in admin-legacy.css (C7.X.13 only covered number).
 *   B — Dashboard range-select height normalised: --eem-select-height
 *       token introduced; eem-dashboard-range-select gets min-height +
 *       line-height:normal so it matches eem-toolbar-select at 29px.
 *   C — Media Library modal chrome bleed: root cause = backdrop
 *       opacity:0.7 lets admin chrome (lower z-index) bleed through.
 *       Fix: backdrop uses background:rgba(0,0,0,0.7)+opacity:1 and
 *       z-index:199999; modal at z-index:200000.
 *   D — Trash row meatballs: D1=status-aware menu (trash shows only
 *       Restore+Delete Permanently); D3=typed-confirm modal for delete
 *       with server-side title validation.
 *   E — Tab count vs body count divergence: get_paginated() meta_key
 *       ordering used INNER JOIN which dropped orphan reservations.
 *       Fix: posts_clauses filter swaps to LEFT JOIN for that join.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x17_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.17 — WALKTHROUGH FIX-UPS SMOKE ===\n";

$admin_css   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$legacy      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin-legacy.css' );
$js_src      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$list_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$repo_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reservations-list-repo.php' );
$main_php    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'equine-event-manager.php' );

// Strip comments before scanning to avoid false-positives from audit prose.
$strip = function( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$list_nocom  = $strip( $list_src );
$repo_nocom  = $strip( $repo_src );
$legacy_nocom = preg_replace( '~/\*.*?\*/~s', '', $legacy );

wp_set_current_user( 1 );

// ── [VERSION] ──────────────────────────────────────────────────────────────
// Self-consistency check: the plugin-header `Version:` must match the
// EQUINE_EVENT_MANAGER_VERSION constant. (Was a hardcoded 2.3.6 assertion;
// the plugin bumps regularly, so assert header/constant agreement instead.)
echo "\n[VERSION] Plugin header Version matches the version constant\n";
preg_match( '~^\s*\*\s*Version:\s*([0-9][0-9.]*)~mi', $main_php, $hdr_m );
preg_match( "~define\(\s*'EQUINE_EVENT_MANAGER_VERSION'\s*,\s*'([0-9][0-9.]*)'\s*\)~", $main_php, $const_m );
$hdr_ver   = $hdr_m[1]   ?? '';
$const_ver = $const_m[1] ?? '';
c7x17_ok( "Plugin header Version ({$hdr_ver}) is parseable",
	'' !== $hdr_ver,
	$pass, $fail, $log );
c7x17_ok( "EQUINE_EVENT_MANAGER_VERSION ({$const_ver}) matches plugin header Version ({$hdr_ver})",
	'' !== $const_ver && $const_ver === $hdr_ver,
	$pass, $fail, $log );

// ── [A] Border-radius token ────────────────────────────────────────────────
echo "\n[A] Border-radius token + cross-input exclusion chains\n";

// Token value
c7x17_ok( '--eem-radius token is 3px in admin.css',
	(bool) preg_match( '~--eem-radius\s*:\s*3px~', $admin_css ),
	$pass, $fail, $log );
c7x17_ok( '--eem-radius-sm token is 2px in admin.css',
	(bool) preg_match( '~--eem-radius-sm\s*:\s*2px~', $admin_css ),
	$pass, $fail, $log );

// Toolbar controls use var(--eem-radius) not hardcoded 4px
foreach ( array(
	'\.eem-list-toolbar\s+\.eem-toolbar-select',
	'\.eem-list-toolbar\s+\.eem-search-input',
	'\.eem-list-toolbar\s+\.eem-toolbar-btn',
) as $sel ) {
	$block = '';
	if ( preg_match( '~' . $sel . '(?:[^{]*)\{([^}]*)\}~s', $admin_css, $m ) ) {
		$block = $m[1];
	}
	c7x17_ok( "{$sel} block does not contain border-radius: 4px (uses token or none)",
		'' === $block || false === strpos( $block, 'border-radius: 4px' ),
		$pass, $fail, $log );
}

// admin-legacy.css: text/search/email/url inputs in the GENERAL shell-page
// !important blocks must carry :not() exclusion chains.  We assert positively
// that the shell-page:not(...) and body.post-type-en_reservation patterns exist
// WITH :not() rather than scanning the entire file (which contains many
// legitimately bare selectors in page-variant-scoped blocks like
// body.eem-shell-page--reservations ... input[type="search"]).
$legacy_nc = $legacy_nocom;
// DS-1.A / C16 UPDATE (2.7.173): the editor- and post-type-scoped form-control
// blocks that previously hosted the cross-input-type :not() exclusion chains
//   body.eem-shell-page--editor input[type=X]:not(...)
//   body.post-type-en_reservation.post-php / .post-new-php input[type=X]:not(...)
// were REMOVED as dead code. The classic WP CPT edit screens (post.php /
// post-new.php for en_reservation) unconditionally redirect to the custom route
// `equine-event-manager-reservation-editor` (body `--reservation-editor`, styled
// entirely by admin.css with no legacy rules), so those blocks matched zero
// rendered elements. Guard against the dead blocks being reintroduced.
foreach ( array( 'body.eem-shell-page--editor input[type=', 'body.post-type-en_reservation.post-php input[type=', 'body.post-type-en_reservation.post-new-php input[type=' ) as $dead ) {
	c7x17_ok( "admin-legacy.css: dead classic-editor form-control block stays removed ({$dead}…)",
		false === strpos( $legacy_nc, $dead ), $pass, $fail, $log );
}

// textarea exclusion chain. C7.X.18 Issue A1 corrected the chain: the actual
// class on plugin textareas is .eem-field-input (not .eem-field-textarea), so
// the exclusion is now textarea:not(.eem-field-input):not(.eem-field-textarea)
// (both kept for safety). Old single-:not(.eem-field-textarea) check is stale.
c7x17_ok( 'admin-legacy.css: textarea selectors in !important block carry :not(.eem-field-input):not(.eem-field-textarea)',
	false !== strpos( $legacy_nc, 'textarea:not(.eem-field-input):not(.eem-field-textarea)' ),
	$pass, $fail, $log );

// ── [B] Select height ─────────────────────────────────────────────────────
echo "\n[B] Dashboard range-select height normalisation\n";

c7x17_ok( '--eem-select-height token defined in admin.css',
	(bool) preg_match( '~--eem-select-height\s*:\s*29px~', $admin_css ),
	$pass, $fail, $log );

// Dashboard range-select block has min-height + line-height:normal
$dash_block = '';
if ( preg_match( '~select\.eem-dashboard-range-select\s*\{([^}]*)\}~s', $admin_css, $dm ) ) {
	$dash_block = $dm[1];
}
c7x17_ok( 'select.eem-dashboard-range-select declares min-height: var(--eem-select-height)',
	'' !== $dash_block && false !== strpos( $dash_block, 'min-height: var(--eem-select-height)' ),
	$pass, $fail, $log );
c7x17_ok( 'select.eem-dashboard-range-select declares line-height: normal',
	'' !== $dash_block && false !== strpos( $dash_block, 'line-height: normal' ),
	$pass, $fail, $log );
c7x17_ok( 'select.eem-dashboard-range-select padding is not 7px (was causing 51px height)',
	'' !== $dash_block && ! (bool) preg_match( '~padding\s*:\s*7px~', $dash_block ),
	$pass, $fail, $log );

// Toolbar select uses token height
$toolbar_block = '';
if ( preg_match( '~\.eem-list-toolbar\s+\.eem-toolbar-select,\s*\n[^{]*\{([^}]*)\}~s', $admin_css, $tm ) ) {
	$toolbar_block = $tm[1];
}
c7x17_ok( '.eem-list-toolbar .eem-toolbar-select block declares min-height via token',
	'' !== $toolbar_block && false !== strpos( $toolbar_block, 'min-height: var(--eem-select-height)' ),
	$pass, $fail, $log );

// ── [C] Media Library modal ───────────────────────────────────────────────
echo "\n[C] Media Library modal — backdrop opacity fix\n";

c7x17_ok( '.media-modal-backdrop has z-index: 199999 (explicit, below .media-modal)',
	(bool) preg_match( '~\.media-modal-backdrop\s*\{[^}]*z-index\s*:\s*199999[^}]*\}~s', $admin_css ),
	$pass, $fail, $log );
c7x17_ok( '.media-modal-backdrop declares background: rgba(0,0,0,0.7)',
	(bool) preg_match( '~\.media-modal-backdrop\s*\{[^}]*background\s*:\s*rgba\(0,0,0,0\.7\)~s', $admin_css ),
	$pass, $fail, $log );
c7x17_ok( '.media-modal-backdrop declares opacity: 1 !important',
	(bool) preg_match( '~\.media-modal-backdrop\s*\{[^}]*opacity\s*:\s*1\s*!important~s', $admin_css ),
	$pass, $fail, $log );
c7x17_ok( '.media-modal has z-index: 200000',
	(bool) preg_match( '~\.media-modal\s*\{\s*z-index\s*:\s*200000\s*;\s*\}~', $admin_css ),
	$pass, $fail, $log );

// ── [D] Trash row meatballs ───────────────────────────────────────────────
echo "\n[D] Trash row meatballs — status-aware menu + typed-confirm modal\n";

// D1: render_row_actions — when $is_trashed, only Restore + Delete Permanently
// Structural check: the is_trashed branch should come BEFORE View on Front-End
$trashed_restore_pos  = strpos( $list_nocom, 'reservation-restore' );
// Label was "View on Front-End" at C7.X.17; renamed to "View on Frontend" since.
$view_front_pos       = strpos( $list_nocom, 'View on Frontend' );
$delete_perm_pos      = strpos( $list_nocom, 'reservation-delete-permanently' );
c7x17_ok( 'Restore action appears before "View on Frontend" (is_trashed branch is primary)',
	false !== $trashed_restore_pos && false !== $view_front_pos && $trashed_restore_pos < $view_front_pos,
	$pass, $fail, $log );
c7x17_ok( 'render_row_actions contains reservation-restore in is_trashed branch',
	false !== $trashed_restore_pos,
	$pass, $fail, $log );
c7x17_ok( 'render_row_actions contains reservation-delete-permanently in is_trashed branch',
	false !== $delete_perm_pos,
	$pass, $fail, $log );

// D3: data-reservation-title attribute on Delete Permanently button
c7x17_ok( 'Delete Permanently button carries data-reservation-title attribute',
	false !== strpos( $list_nocom, 'data-reservation-title' ),
	$pass, $fail, $log );

// D3: server-side title validation in handle_delete_permanently
c7x17_ok( 'handle_delete_permanently validates confirmation_title server-side',
	(bool) preg_match( '~confirmation_title~', $list_nocom ),
	$pass, $fail, $log );
// The typed-confirm gate was simplified plugin-wide: instead of typing the
// reservation's post_title, the admin types the fixed uppercase word DELETE
// (server validates 'DELETE' !== $typed). Same typed-confirm protection,
// simpler + consistent across all permanent-delete actions. (Old
// $typed !== $post->post_title check is stale.)
c7x17_ok( "handle_delete_permanently checks typed word equals 'DELETE'",
	(bool) preg_match( "~'DELETE'\s*!==\s*\\\$typed~", $list_nocom ),
	$pass, $fail, $log );

// D3: deleted-permanently notice defined in $messages array
c7x17_ok( "'deleted-permanently' notice entry exists in \$messages",
	false !== strpos( $list_src, "'deleted-permanently'" ),
	$pass, $fail, $log );

// D3: JS typed-confirm modal — openDeletePermanentlyModal function
c7x17_ok( 'admin.js defines openDeletePermanentlyModal function',
	(bool) preg_match( '~function openDeletePermanentlyModal~', $js_src ),
	$pass, $fail, $log );
c7x17_ok( 'admin.js: delete-permanently action calls openDeletePermanentlyModal (not window.confirm)',
	(bool) preg_match( "~'reservation-delete-permanently'[^}]*openDeletePermanentlyModal~s", $js_src ) &&
	! (bool) preg_match( "~'reservation-delete-permanently'[^}]*window\.confirm~s", $js_src ),
	$pass, $fail, $log );
// Client-side gate matches the simplified server gate: Delete stays disabled
// until the typed input equals the fixed CONFIRM_WORD ('DELETE'). (Was compared
// against resTitle; the design moved from title-match to DELETE-word match.)
c7x17_ok( 'admin.js: typed-confirm modal disables Delete button until input equals CONFIRM_WORD',
	(bool) preg_match( '~confirmBtn\.disabled\s*=\s*\(input\.value\s*!==\s*CONFIRM_WORD\)~', $js_src ),
	$pass, $fail, $log );
c7x17_ok( 'admin.js: submitReservationAction accepts extraFields 4th arg (D3 title post)',
	(bool) preg_match( '~function submitReservationAction\s*\(\s*target\s*,\s*actionName\s*,\s*nonceAction\s*,\s*extraFields\s*\)~', $js_src ),
	$pass, $fail, $log );
c7x17_ok( 'admin.js: typed-confirm modal submits confirmation_title field',
	false !== strpos( $js_src, 'confirmation_title' ),
	$pass, $fail, $log );

// ── [E] Tab count / body count divergence ─────────────────────────────────
echo "\n[E] get_paginated() LEFT JOIN fix for orphan reservations\n";

c7x17_ok( 'get_paginated() defines left_join_filter closure (posts_clauses hook)',
	(bool) preg_match( '~left_join_filter~', $repo_nocom ),
	$pass, $fail, $log );
c7x17_ok( 'get_paginated() adds posts_clauses filter before event_dates WP_Query',
	(bool) preg_match( '~add_filter\s*\(\s*[\'"]posts_clauses[\'"]~', $repo_nocom ),
	$pass, $fail, $log );
c7x17_ok( 'get_paginated() removes posts_clauses filter after query (no filter bleed)',
	(bool) preg_match( '~remove_filter\s*\(\s*[\'"]posts_clauses[\'"]~', $repo_nocom ),
	$pass, $fail, $log );
c7x17_ok( 'left_join_filter swaps INNER JOIN to LEFT JOIN on postmeta',
	false !== strpos( $repo_nocom, 'LEFT JOIN' ) && false !== strpos( $repo_nocom, 'INNER' ),
	$pass, $fail, $log );
c7x17_ok( 'left_join_filter pushes NULL meta_value to end (IS NULL sort)',
	(bool) preg_match( '~meta_value IS NULL~', $repo_nocom ),
	$pass, $fail, $log );

// ── LIVE QUERY: counts_by_tab() vs get_paginated() totals must match ──────
echo "\n[E-live] counts_by_tab() == get_paginated() totals (no orphan divergence)\n";
$tabs = EEM_Reservations_List_Repo::status_tabs();
$counts = EEM_Reservations_List_Repo::counts_by_tab();
foreach ( array_keys( $tabs ) as $tab_id ) {
	$page = EEM_Reservations_List_Repo::get_paginated( array(
		'status'      => $tab_id,
		'per_page'    => 500,
		'paged'       => 1,
		'orderby'     => 'event_dates',
	) );
	c7x17_ok( "Tab '{$tab_id}': counts_by_tab ({$counts[$tab_id]}) == get_paginated total ({$page['total']})",
		$counts[ $tab_id ] === $page['total'],
		$pass, $fail, $log,
		"counts_by_tab={$counts[$tab_id]}, get_paginated={$page['total']}"
	);
}

// ── FINAL REPORT ─────────────────────────────────────────────────────────
echo "\n";
foreach ( $log as $line ) { echo $line . "\n"; }
printf(
	"\n=== RESULT: %d passed, %d failed ===\n\n",
	$pass, $fail
);
if ( $fail > 0 ) { exit( 1 ); }
