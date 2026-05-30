<?php
/**
 * Mockup presence + MD5 fingerprint smoke.
 *
 * Established post-C6 (handoff Step 1) per the CLAUDE.md standing rule
 * "`.mockups/` is PERMANENTLY EXCLUDED from iCloud sync." This smoke is
 * the canary: it asserts that every expected canonical mockup file
 * exists in `Projects/.mockups/` at the MD5 it was committed with. Any
 * accidental wipe-out (iCloud sync misfire, mistaken `git checkout --`,
 * lost-during-merge incident) gets caught at smoke time instead of at
 * visual-verify several chunks later.
 *
 * When a mockup legitimately changes (canonical update from the audit
 * chat, or a render-driven correction), update the MD5 below in the
 * same commit that lands the new file. Treat MD5 updates here as a
 * deliberate signal — they should be rare and always paired with a
 * commit-message note.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== MOCKUPS PRESENCE SMOKE ===\n";

$mockups_dir = EQUINE_EVENT_MANAGER_PATH . '.mockups/';

// ── [1] Directory + canonical-file presence ─────────────────────────
echo "\n[1] .mockups/ directory + canonical-file presence\n";
ok( '.mockups/ directory exists', is_dir( $mockups_dir ), $pass, $fail, $log );

// Canonical fingerprints — single source of truth for what should be in .mockups/.
// Each entry: filename => MD5 of the committed canonical version.
// Update an entry HERE in the same commit that lands a new canonical version.
$canonical = array(
	// 10 new/updated from handoff Step 1 (post-C6)
	'dashboard_page.html'               => 'd373c7ca90da3e3f744e1b0a8950d9ef',
	'edit_reservation_page.html'        => 'cc0dc9ff532de4809789a55215af5e59', // 2.3.32 — breadcrumb link resting #031B4E navy, font-weight:600, text-decoration:none
	'stall_charts_page.html'            => '6dc053dc24affbe5e23199685a510156', // 2.3.31 — search placeholder standardized to "Search"
	'stall_chart_detail.html'           => '5ea62ad121712dec91ca0ff043c51569', // 2.3.32 — breadcrumb link resting #031B4E navy
	'stall_chart_print_view.html'       => '8c76b6d1396dc757cb798b6734a6a83b',
	'create_order_page.html'            => '2d4338f0678b7770f46286d7b672bc1f', // 2.3.32 — breadcrumb link resting #031B4E navy
	'collect_payment_page.html'         => '0b0cd67265ad6393db458e1e73f41925', // 2.3.32 — breadcrumb link resting #031B4E navy
	'reports_page.html'                 => 'ee88a2ceab0be5a3ff8d1a30a038d354',
	'customer_confirmation_email.html'  => '1705857179c8ee90053fae1304810cdc', // Step 1.7 cancellation-arch update
	'order_receipt.html'                => 'f26a56fd6d5f8a4fb56059f7c340eb34', // Step 1.7 cancellation-arch update

	// 4 of the 6 "shipped" mockups were sidebar-updated in DS-1.A to
	// reflect the post-HANDOFF canonical state ("Stall Charts" →
	// "Stall & RV Charts"; Invoicing entry removed). The other 2
	// (customer_profile_page.html, event_page.html) are off-canvas to
	// DS-1.A's mechanical-edit scope.
	'reservations_page.html'            => 'c504cbe03c6cbf87eb369928fed5cdcb', // 2.3.31 — search placeholder standardized to "Search"
	'orders_page.html'                  => 'a1fa462f4644585e0ec6054f1d3dab99', // 2.3.31 — search placeholder standardized to "Search"
	'settings_page.html'                => '6efd31e96700c2d4d7eb4902c7306248', // DS-1.A sidebar update
	'order_detail_page.html'            => '87d8cb86f3d597a9e66ad4e7039359b8', // 2.3.32 — breadcrumb link resting #031B4E navy
	'customer_profile_page.html'        => 'd7bd2f3b28d59be697ccd97e03f86e46',
	'event_page.html'                   => '341de9b586d4484e16dee45560d549f3',

	// Plugin brand asset (referenced by admin shell + breadcrumb)
	'Equine Event Manager Logo.png'     => 'fad15ea2d6637ca49090ab8c118250a5',
);

foreach ( $canonical as $file => $expected_md5 ) {
	$path = $mockups_dir . $file;
	if ( ! file_exists( $path ) ) {
		ok( "{$file} exists", false, $pass, $fail, $log, 'FILE MISSING — check for accidental wipe-out' );
		continue;
	}
	ok( "{$file} exists", true, $pass, $fail, $log );
	$actual = md5_file( $path );
	ok(
		"{$file} matches canonical MD5 ({$expected_md5})",
		$actual === $expected_md5,
		$pass, $fail, $log,
		"got {$actual} — file content drifted; update canonical hash if intentional"
	);
}

// ── [2] invoicing_page.html — deleted per HANDOFF, must NOT come back ─
echo "\n[2] invoicing_page.html removed (per HANDOFF Step 1)\n";
ok(
	'invoicing_page.html is NOT in .mockups/ (deleted per HANDOFF)',
	! file_exists( $mockups_dir . 'invoicing_page.html' ),
	$pass, $fail, $log,
	'invoicing was split into create_order_page.html + collect_payment_page.html and must not reappear'
);

// ── [3] Bonus: no stray .html files we forgot to inventory ──────────
echo "\n[3] No undocumented mockup files in .mockups/\n";
$expected_set = array_keys( $canonical );
// `.archive/` (post-2026-05 addition) holds version-controlled retired
// mockups — see `.mockups/.archive/README.md`. Excluded from the stray-
// file inventory check because it's intentionally separate from the
// active canonical set; promotions back into the active set go through
// a fresh canonical-mockup import, not a scandir surprise.
$actual_files = array_filter( scandir( $mockups_dir ), function ( $f ) {
	return '.' !== $f && '..' !== $f && '.DS_Store' !== $f && '.archive' !== $f;
} );
$stray = array_diff( $actual_files, $expected_set );
ok(
	'no stray files in .mockups/ (any unexpected file means inventory is out of date)',
	empty( $stray ),
	$pass, $fail, $log,
	'stray files: ' . implode( ', ', $stray )
);

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
