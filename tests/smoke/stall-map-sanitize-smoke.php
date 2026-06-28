<?php
/**
 * Defensive map-render guard smoke — EEM_Stall_Map_Importer::sanitize_snapshot().
 *
 * A corrupted/malformed map (barns, grid rows, or cells stored as non-arrays —
 * the reservation-5990 landmine) must never fatal the chart render. This asserts
 * the sanitizer coerces every broken shape to a safe, iterable structure while
 * preserving well-formed snapshots and non-barn keys (zones, etc.).
 *
 * Run: wp eval-file tests/smoke/stall-map-sanitize-smoke.php  (no WP needed; uses Reflection)
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

if ( ! class_exists( 'EEM_Stall_Map_Importer' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/class-eem-stall-map-importer.php';
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; echo "  ok  — {$label}\n"; }
	else { $fail++; echo "FAIL — {$label}\n"; }
};

$m = new ReflectionMethod( 'EEM_Stall_Map_Importer', 'sanitize_snapshot' );
$m->setAccessible( true );
$san = static function ( $in ) use ( $m ) {
	return $m->invoke( null, $in );
};

// Corrupted shapes must not throw and must yield safe, iterable structures.
$cases = array(
	'non-array input'   => 'totally broken',
	'barns is a string' => array( 'barns' => 'corrupt' ),
	'barns missing'     => array( 'zones' => array( 1, 2 ) ),
	'grid is a string'  => array( 'barns' => array( array( 'grid' => 'x' ) ) ),
	'row not an array'  => array( 'barns' => array( array( 'grid' => array( 'notarow', array( array( 'type' => 'stall' ) ) ) ) ) ),
	'cell not an array' => array( 'barns' => array( array( 'grid' => array( array( 'str', array( 'type' => 'stall' ) ) ) ) ) ),
);
foreach ( $cases as $name => $in ) {
	$threw = false;
	$out   = array();
	try {
		$out = $san( $in );
	} catch ( \Throwable $e ) {
		$threw = true;
	}
	$check( "no fatal + array barns: {$name}", ! $threw && is_array( $out ) && ( ! isset( $out['barns'] ) || is_array( $out['barns'] ) ) );
}

// Iterating the sanitized output must be safe (mirrors the render path).
$out = $san( array( 'barns' => 'corrupt' ) );
$iter_ok = true;
foreach ( ( $out['barns'] ?? array() ) as $barn ) {
	foreach ( ( $barn['grid'] ?? array() ) as $row ) {
		foreach ( $row as $cell ) { $t = $cell['type'] ?? ''; }
	}
}
$check( 'sanitized corrupted snapshot is safe to iterate like the renderer', $iter_ok );

// Well-formed snapshot passes through; non-barn keys preserved.
$well = array( 'barns' => array( array( 'grid' => array( array( array( 'type' => 'stall', 'label' => '1' ) ) ) ) ), 'zones' => array( 'z' ) );
$w    = $san( $well );
$check( 'well-formed barns preserved', isset( $w['barns'][0]['grid'][0][0]['type'] ) && 'stall' === $w['barns'][0]['grid'][0][0]['type'] );
$check( 'non-barn keys (zones) preserved', isset( $w['zones'] ) && array( 'z' ) === $w['zones'] );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 && defined( 'WP_CLI' ) && WP_CLI ) { WP_CLI::halt( 1 ); }
