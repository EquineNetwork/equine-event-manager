<?php
/**
 * DS-TOGGLE smoke — the official toggle style is consistent across both stylesheets.
 *
 * One toggle style plugin-wide (decisions.md DS-TOGGLE): 44×24, radius 8px,
 * off #D9E2F2, on #1668F2, 18px knob, translateX(20px). The admin .eem-toggle
 * (admin.css) and the front-end .eem-reservation-section-toggle (public.css) must
 * carry these exact values. Guards against the cascade-drift that caused rework.
 *
 * Run: wp eval-file tests/smoke/toggle-style-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$admin = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$pub   = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );

// Isolate each canonical block.
$admin_block = strstr( $admin, '.eem-toggle {' );
$admin_block = $admin_block ? substr( $admin_block, 0, 900 ) : '';
$pub_block   = strstr( $pub, '.eem-reservation-section-toggle__track {' );
$pub_block   = $pub_block ? substr( $pub_block, 0, 900 ) : '';

$check( 'found admin .eem-toggle block', '' !== $admin_block );
$check( 'found front-end track block', '' !== $pub_block );

// Official spec values present in BOTH blocks.
$specs = array(
	'track width 44px'      => 'width: 44px;',
	'track height 24px'     => 'height: 24px;',
	'track radius 8px'      => 'border-radius: 8px;',
	'knob 18px'             => 'width: 18px;',
	'knob travel 20px'      => 'translateX(20px)',
);
foreach ( $specs as $label => $needle ) {
	$check( "admin: {$label}", false !== strpos( $admin_block, $needle ) );
	$check( "front-end: {$label}", false !== strpos( $pub_block, $needle ) );
}

// Colors (case-insensitive — admin uses --eem-electric for on; both use #D9E2F2 off).
$check( 'admin off color #D9E2F2', false !== stripos( $admin_block, '#D9E2F2' ) );
$check( 'front-end off color #D9E2F2', false !== stripos( $pub_block, '#D9E2F2' ) );
$check( 'front-end on color #1668F2', false !== stripos( $pub, '__track {' ) && false !== stripos( $pub, 'background: #1668F2' ) );
$check( 'admin on color is --eem-electric (== #1668F2)', false !== strpos( $admin_block, 'var(--eem-electric)' ) && false !== strpos( $admin, '--eem-electric: #1668F2' ) );

// The misleading dead override must be gone.
$check( 'no leftover .eem-event-page toggle size override', false === strpos( $pub, '.eem-event-page .eem-reservation-section-toggle__track {' ) );
// No stale 58×34 / green in the toggle blocks.
$check( 'no stale 58px pill in admin toggle block', false === strpos( $admin_block, '58px' ) );
$check( 'no stale green #52b788 in front-end toggle', false === stripos( $pub_block, '#52b788' ) );

WP_CLI::log( "\n=== DS-TOGGLE style smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'DS-TOGGLE style smoke passed.' );
