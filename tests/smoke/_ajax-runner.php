<?php
/**
 * Isolated AJAX dispatcher child process for the EEM smoke harness (CLEANUP #28).
 *
 * Invoked by eem_dispatch_ajax() as:
 *   php _ajax-runner.php <ABSPATH> <base64(serialize(['action','post','user']))>
 *
 * Mirrors wp-admin/admin-ajax.php: defines DOING_AJAX + WP_ADMIN (the latter is
 * what makes is_admin() true, so the plugin registers its admin-gated AJAX
 * hooks), bootstraps WP, sets the request context + current user, installs a
 * wp_die handler that captures the buffered response to stdout and exits
 * cleanly, then fires the action.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$abspath = isset( $argv[1] ) ? (string) $argv[1] : '';
$req     = isset( $argv[2] ) ? unserialize( base64_decode( (string) $argv[2] ) ) : array();

if ( ! is_array( $req ) || '' === $abspath || empty( $req['action'] ) || ! file_exists( rtrim( $abspath, '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "bad runner args\n" );
	exit( 2 );
}

if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

$_POST    = isset( $req['post'] ) && is_array( $req['post'] ) ? $req['post'] : array();
$_REQUEST = array_merge( isset( $_REQUEST ) ? $_REQUEST : array(), $_POST );

require rtrim( $abspath, '/\\' ) . '/wp-load.php';

if ( ! empty( $req['user'] ) ) {
	wp_set_current_user( (int) $req['user'] );
}

add_filter( 'wp_die_ajax_handler', function () {
	return function ( $message, $title, $args ) {
		fwrite( STDOUT, (string) ob_get_clean() );
		exit( 0 );
	};
} );

ob_start();
do_action( 'wp_ajax_' . $req['action'] );
fwrite( STDOUT, (string) ob_get_clean() );
