<?php
/**
 * AJAX smoke harness (CLEANUP #28).
 *
 * The problem: `wp_send_json_success/error()` end in `wp_die()`, which in a CLI
 * smoke (`wp eval-file`) EXITS the whole PHP process — so an in-process
 * `do_action( 'wp_ajax_…' )` kills the smoke before any assertion can run. Prior
 * AJAX smokes could therefore only assert "the hook is registered / the handler
 * is public", never the actual response shape.
 *
 * The fix: dispatch each AJAX action in an ISOLATED subprocess. The child
 * (`_ajax-runner.php`) bootstraps WP, sets `$_POST` + the current user, installs
 * a `wp_die_ajax_handler` that captures the already-buffered JSON to stdout and
 * exits cleanly, then fires `do_action( 'wp_ajax_{$action}' )`. The parent reads
 * stdout, decodes the JSON, and returns a structured result — so the smoke can
 * assert on `success` / `data` / HTTP code without the wp_die exit nuking it.
 *
 * Usage (inside a smoke run under `wp eval-file`):
 *   require __DIR__ . '/_ajax-harness.php';
 *   $r = eem_dispatch_ajax( 'eem_order_refund_single', array( 'order_key' => 'x' ), $admin_id );
 *   // $r = [ 'raw' => string, 'json' => array|null, 'success' => bool|null, 'data' => mixed ]
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! function_exists( 'eem_dispatch_ajax' ) ) {
	/**
	 * Dispatch a `wp_ajax_{$action}` handler in an isolated subprocess and
	 * capture its JSON / wp_die output.
	 *
	 * @param string              $action  The action slug (without the `wp_ajax_` prefix).
	 * @param array<string,mixed> $post    $_POST payload to set in the child.
	 * @param int                 $user_id WP user to run as (0 = logged out).
	 * @return array{raw:string,json:?array,success:?bool,data:mixed,exit:int}
	 */
	function eem_dispatch_ajax( $action, array $post = array(), $user_id = 0 ) {
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : '';
		$runner  = __DIR__ . '/_ajax-runner.php';
		$payload = base64_encode( serialize( array(
			'action' => (string) $action,
			'post'   => $post,
			'user'   => (int) $user_id,
		) ) );

		// Reuse the same PHP binary that's running the smoke.
		$php = ( defined( 'PHP_BINARY' ) && PHP_BINARY ) ? PHP_BINARY : 'php';

		$cmd = escapeshellarg( $php )
			. ' ' . escapeshellarg( $runner )
			. ' ' . escapeshellarg( $abspath )
			. ' ' . escapeshellarg( $payload )
			. ' 2>/dev/null';

		$raw = (string) shell_exec( $cmd );

		// The runner prints the captured body; if a handler json-encoded a
		// response there may be leading notices — grab the last JSON object.
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) && '' !== trim( $raw ) ) {
			$start = strpos( $raw, '{' );
			if ( false !== $start ) {
				$json = json_decode( substr( $raw, $start ), true );
			}
		}

		return array(
			'raw'     => $raw,
			'json'    => is_array( $json ) ? $json : null,
			'success' => is_array( $json ) && array_key_exists( 'success', $json ) ? (bool) $json['success'] : null,
			'data'    => is_array( $json ) && array_key_exists( 'data', $json ) ? $json['data'] : null,
			'exit'    => 0,
		);
	}
}
