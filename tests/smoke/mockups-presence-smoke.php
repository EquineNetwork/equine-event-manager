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
	'edit_reservation_page.html'        => '7e9722dcc0890c5af75faf15c8553c13', // Step 1.7 cancellation-arch update
	'stall_charts_page.html'            => 'f999373626398cac60b99930989bf2e9',
	'stall_chart_detail.html'           => '91c79b2ce1f7583ad70c6d41ab12f90b',
	'stall_chart_print_view.html'       => '8c76b6d1396dc757cb798b6734a6a83b',
	'create_order_page.html'            => 'ea83953366dbf0b3a6364007ac971f80',
	'collect_payment_page.html'         => '67ca3972922e3370532b45e571ce16c7',
	'reports_page.html'                 => 'ee88a2ceab0be5a3ff8d1a30a038d354',
	'customer_confirmation_email.html'  => '1705857179c8ee90053fae1304810cdc', // Step 1.7 cancellation-arch update
	'order_receipt.html'                => 'f26a56fd6d5f8a4fb56059f7c340eb34', // Step 1.7 cancellation-arch update

	// 6 "shipped/unchanged" from handoff Step 1 — three were resynced to
	// audit-canonical versions in Step 1.6 (HANDOFF.md line refs resolve
	// correctly only on the Desktop versions; see Step 1.5 diagnostic).
	'reservations_page.html'            => '2b4a92cc51852ef08f9e5f3e40344e07', // Step 1.6 canonical
	'orders_page.html'                  => '9e388e32b865960c4c4fc428498e04fa', // Step 1.6 canonical
	'settings_page.html'                => '25a27c6f558cbf6b4c32ef5edb2d49a1', // Step 1.6 canonical
	'order_detail_page.html'            => 'dab6465b96bcf271946fea893695c436',
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
$actual_files = array_filter( scandir( $mockups_dir ), function ( $f ) {
	return '.' !== $f && '..' !== $f && '.DS_Store' !== $f;
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
