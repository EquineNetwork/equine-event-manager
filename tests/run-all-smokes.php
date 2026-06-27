<?php
/**
 * Smoke-suite runner (CLEANUP — smoke reconciliation).
 *
 * Runs every tests/smoke/<name>-smoke.php (and *-smoke.php variants) in its own
 * `wp eval-file` subprocess so a wp_die()/fatal in one cannot abort the rest,
 * parses each file's "N passed, M failed" tally, and prints a roll-up plus the
 * list of failing/erroring files.
 *
 * Invoke directly with the Local PHP binary (NOT through WP — it shells out to
 * wp-cli per smoke):
 *   php tests/run-all-smokes.php <wp-path> <php-bin> <wp-cli.phar>
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 */

$wp_path = isset( $argv[1] ) ? $argv[1] : '';
$php_bin = isset( $argv[2] ) ? $argv[2] : PHP_BINARY;
$wp_cli  = isset( $argv[3] ) ? $argv[3] : '';

$dir   = __DIR__ . '/smoke';
$files = glob( $dir . '/*.php' );
sort( $files );

$totalPass = 0;
$totalFail = 0;
$failing   = array();
$errored   = array();
$noTally   = array();
$ran       = 0;

foreach ( $files as $file ) {
	$base = basename( $file );
	// Skip helper includes (leading underscore) — they are required by smokes,
	// not run standalone.
	if ( '_' === $base[0] ) {
		continue;
	}
	$ran++;
	$cmd = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $wp_cli )
		. ' --path=' . escapeshellarg( $wp_path )
		. ' eval-file ' . escapeshellarg( $file ) . ' --allow-root 2>&1';
	$out = (string) shell_exec( $cmd );

	$pass = null;
	$fail = null;
	if ( preg_match_all( '/(\d+)\s+passed,\s+(\d+)\s+failed/i', $out, $m, PREG_SET_ORDER ) ) {
		$last = end( $m );
		$pass = (int) $last[1];
		$fail = (int) $last[2];
	}

	$isFatal = (bool) preg_match( '/(PHP )?(Fatal error|Parse error|Uncaught)/i', $out );

	if ( null === $pass ) {
		if ( $isFatal ) {
			$errored[] = array( $base, trim( substr( $out, 0, 240 ) ) );
		} else {
			$noTally[] = array( $base, trim( substr( $out, -160 ) ) );
		}
		continue;
	}

	$totalPass += $pass;
	$totalFail += $fail;
	if ( $fail > 0 || $isFatal ) {
		$failing[] = array( $base, $pass, $fail );
	}
}

echo "================ SMOKE SUITE ROLL-UP ================\n";
echo "files run:        {$ran}\n";
echo "assertions pass:  {$totalPass}\n";
echo "assertions fail:  {$totalFail}\n";
echo 'files with failures: ' . count( $failing ) . "\n";
echo 'files erroring (fatal/parse): ' . count( $errored ) . "\n";
echo 'files with no tally line: ' . count( $noTally ) . "\n";

if ( $failing ) {
	echo "\n---- FAILING (has failed assertions) ----\n";
	foreach ( $failing as $f ) {
		echo sprintf( "  %-55s %d pass / %d FAIL\n", $f[0], $f[1], $f[2] );
	}
}
if ( $errored ) {
	echo "\n---- ERRORED (fatal/parse) ----\n";
	foreach ( $errored as $e ) {
		echo "  {$e[0]}\n      {$e[1]}\n";
	}
}
if ( $noTally ) {
	echo "\n---- NO TALLY LINE (informational/odd format) ----\n";
	foreach ( $noTally as $n ) {
		echo "  {$n[0]}\n      …{$n[1]}\n";
	}
}
echo "====================================================\n";
