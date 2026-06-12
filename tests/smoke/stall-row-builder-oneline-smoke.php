<?php
/**
 * Stall row builder one-line + optional-name smoke (2.7.196).
 *
 * The stall row builder now lays Barn/Row Name + First + Last + delete on a
 * single line, marks the name optional (label + placeholder), and the chart
 * drops the Block column / barn dividers when every row is unnamed ("by number").
 * The name was already optional server-side (count_usable_rows checks first/last).
 * One-line layout was browser-verified at ship time.
 *
 * Run: wp eval-file tests/smoke/stall-row-builder-oneline-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$root = dirname( __DIR__, 2 );
$tpl  = (string) file_get_contents( $root . '/templates/admin/reservation-editor/_section-stall.php' );
$js   = (string) file_get_contents( $root . '/assets/js/admin.js' );
$css  = (string) file_get_contents( $root . '/assets/css/admin.css' );
$adm  = (string) file_get_contents( $root . '/admin/class-equine-event-manager-admin.php' );

// --- PHP template: one line + optional name --------------------------------
$check( 'PHP card uses single .eem-row-card-line', str_contains( $tpl, 'class="eem-row-card-line"' ) );
$check( 'PHP name field flagged --name', str_contains( $tpl, 'eem-row-card-field--name' ) );
$check( 'PHP shows (optional) on the name label', str_contains( $tpl, 'eem-row-card-optional' ) && str_contains( $tpl, '(optional)' ) );
$check( 'PHP name input has the "number only" placeholder', str_contains( $tpl, 'Leave blank to number stalls only' ) );
$check( 'PHP no longer uses the stacked .eem-row-card-top for stalls', ! str_contains( $tpl, 'eem-row-card-top' ) );

// --- JS new-row template matches -------------------------------------------
$check( 'JS new-row card uses .eem-row-card-line', str_contains( $js, "'<div class=\"eem-row-card-line\">'" ) );
$check( 'JS new-row name field is optional + placeholder', str_contains( $js, 'eem-row-card-field--name' ) && str_contains( $js, 'Leave blank to number stalls only' ) );

// --- CSS lays the line out as a flex row -----------------------------------
$check( 'CSS .eem-row-card-line is a flex row', (bool) preg_match( '/\.eem-row-card-line\s*\{[^}]*display:\s*flex/', $css ) );
$check( 'CSS name field is wider (flex 2)', str_contains( $css, '.eem-row-card-line .eem-row-card-field--name { flex: 2' ) );
$check( 'CSS optional label is muted', str_contains( $css, '.eem-row-card-optional' ) );

// --- chart: Block column dropped when all rows unnamed ---------------------
$check( 'chart computes $eem_has_block from row blocks', str_contains( $adm, '$eem_has_block' ) && str_contains( $adm, "trim( (string) ( \$eem_sr['block'] ?? '' ) )" ) );
$check( 'chart passes empty Block label when unnamed', str_contains( $adm, "\$eem_has_block ? __( 'Block', 'equine-event-manager' ) : ''" ) );

// --- server still treats name as optional (usable = first+last) ------------
$ref = new ReflectionMethod( 'EEM_Reservation_Editor_Page', 'count_usable_rows' );
$ref->setAccessible( true );
$rows = array( array( 'layout' => 'one-sided', 'name' => '', 'first' => '1', 'last' => '50' ) );
$check( 'an unnamed row with first+last still counts as usable', 1 === (int) $ref->invoke( null, $rows ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
