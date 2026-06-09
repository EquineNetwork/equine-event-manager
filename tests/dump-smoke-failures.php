<?php
/**
 * Re-run a given list of smoke files and print only their FAIL/error lines, so
 * the failures can be triaged (test-drift vs real bug) without scrolling full
 * passing output. Args: <wp-path> <php-bin> <wp-cli.phar> <file1> <file2> ...
 */
$wp_path = $argv[1];
$php_bin = $argv[2];
$wp_cli  = $argv[3];
$names   = array_slice( $argv, 4 );
$dir     = __DIR__ . '/smoke';

foreach ( $names as $name ) {
	$file = $dir . '/' . $name;
	if ( ! file_exists( $file ) ) {
		echo "?? missing: $name\n";
		continue;
	}
	$cmd = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $wp_cli )
		. ' --path=' . escapeshellarg( $wp_path )
		. ' eval-file ' . escapeshellarg( $file ) . ' 2>&1';
	$out   = (string) shell_exec( $cmd );
	$lines = preg_split( '/\r?\n/', $out );
	echo "\n========== $name ==========\n";
	foreach ( $lines as $ln ) {
		if ( preg_match( '/\b(FAIL|fail|✗|Fatal error|Parse error|Uncaught|precondition|Error:|Warning:)\b/', $ln )
			&& ! preg_match( '/\d+\s+passed,\s+\d+\s+failed/', $ln ) ) {
			echo '  ' . trim( $ln ) . "\n";
		}
	}
	// always show the tally
	if ( preg_match( '/(\d+\s+passed,\s+\d+\s+failed)/i', $out, $m ) ) {
		echo '  >> ' . $m[1] . "\n";
	}
}
