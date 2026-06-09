<?php
/**
 * Smoke: the CLEANUP #28 AJAX harness captures wp_die / wp_send_json paths in an
 * isolated subprocess WITHOUT the wp_die() exit killing the smoke.
 *
 * Run: wp eval-file tests/smoke/ajax-harness-smoke.php
 *
 * The proof is that BOTH dispatches return captured output and the assertions
 * AFTER them still execute — if dispatch ran in-process, the first wp_die() would
 * have terminated the smoke before the second dispatch or the final tally.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

require __DIR__ . '/_ajax-harness.php';

$pass = 0;
$fail = 0;
$log  = array();
$ok   = function ( $name, $cond ) use ( &$pass, &$fail, &$log ) {
	if ( $cond ) {
		$pass++;
		$log[] = "PASS  $name";
	} else {
		$fail++;
		$log[] = "FAIL  $name";
	}
};

$ok( 'harness function loaded', function_exists( 'eem_dispatch_ajax' ) );
$ok( 'runner file present', file_exists( __DIR__ . '/_ajax-runner.php' ) );

// [1] Dispatch a registered AJAX endpoint with NO nonce, logged out. Its
// check_ajax_referer()/capability guard calls wp_die() — which in-process would
// kill us. The harness must capture it and return cleanly.
$r1 = eem_dispatch_ajax( 'eem_order_refund_single', array( 'order_key' => 'nope' ), 0 );
$ok( '[1] dispatch returned (parent survived the child wp_die)', is_array( $r1 ) );
$ok( '[1] captured non-empty response body', '' !== trim( (string) $r1['raw'] ) );

// [2] A SECOND dispatch only runs if [1] did not terminate the process —
// this is the load-bearing proof of isolation.
$r2 = eem_dispatch_ajax( 'eem_order_add_note', array( 'order_key' => 'nope' ), 0 );
$ok( '[2] second dispatch also returned (isolation confirmed)', is_array( $r2 ) );

// [3] When a handler emits JSON (wp_send_json_*), the harness decodes it. A
// bad-nonce check_ajax_referer typically dies with "-1" (not JSON), so json may
// be null here — assert the SHAPE of the return contract instead.
$ok( '[3] return contract has raw/json/success/data keys',
	array_key_exists( 'raw', $r1 ) && array_key_exists( 'json', $r1 )
	&& array_key_exists( 'success', $r1 ) && array_key_exists( 'data', $r1 )
);

echo implode( "\n", $log ) . "\n";
echo "ajax-harness-smoke: {$pass} passed, {$fail} failed\n";
