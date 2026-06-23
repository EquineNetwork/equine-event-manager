<?php
/**
 * Smoke — post-meta → config-table decouple ratchet (ROADMAP v1 #10 / #13, #211).
 *
 * Operationalizes step 5 of docs/POSTMETA-AUDIT.md: a regression guard that
 * counts business-data post-meta calls (`_en_*` / `_equine_event_manager_*`
 * keys) in live code (includes/ + admin/ + public/, excluding one-time
 * migrations) and FAILS if the count rises above the recorded baseline. This
 * keeps the codebase from regressing back onto post-meta while the relational
 * decouple proceeds — the number should only ever go DOWN.
 *
 * Also asserts the #212 fix stays in place (checkout base rates overlaid from
 * the config table in get_reservation_meta), since reverting that is a live
 * pricing risk.
 *
 * Pure file-scan — does NOT require WordPress. Runs under wp eval-file or
 * plain `php tests/smoke/postmeta-decouple-ratchet-smoke.php`.
 *
 * When you intentionally remove post-meta reads (good!), lower BASELINE to the
 * new count in the same commit so the ratchet keeps tightening.
 */

// Baseline captured 2026-06-23 (#10/#13 audit). Lower it as decouple progresses;
// raising it requires an explicit, justified post-meta addition.
const EEM_POSTMETA_BASELINE = 214;

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$root = dirname( __DIR__, 2 );
$dirs = array( $root . '/includes', $root . '/admin', $root . '/public' );

$pattern = '/(get|update|delete|add)_post_meta\s*\([^;]*[\'"]_(en_|equine_event_manager_)/';
$count   = 0;
$by_file = array();

foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) { continue; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
		$path = $file->getPathname();
		if ( false !== strpos( $path, '/migrations/' ) ) { continue; }
		$lines = file( $path );
		if ( false === $lines ) { continue; }
		foreach ( $lines as $line ) {
			if ( preg_match( $pattern, $line ) ) {
				$count++;
				$rel = ltrim( str_replace( $root, '', $path ), '/' );
				$by_file[ $rel ] = ( $by_file[ $rel ] ?? 0 ) + 1;
			}
		}
	}
}

echo 'Business-data post-meta calls in live code (excl. migrations): ' . $count . ' (baseline ' . EEM_POSTMETA_BASELINE . ")\n";
arsort( $by_file );
foreach ( array_slice( $by_file, 0, 12, true ) as $f => $n ) {
	echo "    {$n}  {$f}\n";
}

$check( 'post-meta count did not regress above baseline', $count <= EEM_POSTMETA_BASELINE );
if ( $count < EEM_POSTMETA_BASELINE ) {
	echo "NOTE: count ({$count}) is below baseline (" . EEM_POSTMETA_BASELINE . ") — lower EEM_POSTMETA_BASELINE to {$count} in this commit to keep the ratchet tight.\n";
}

// #212 regression guard — checkout base rates must still overlay from the config table.
$sc = (string) file_get_contents( $root . '/public/class-equine-event-manager-shortcodes.php' );
$check( '#212: config base-rate overlay still present in shortcodes', false !== strpos( $sc, 'EEM_Reservation_Config' ) && false !== stripos( $sc, 'base-rate' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
