<?php
/**
 * Smoke — move-customer (ajax_move_stall_assignment) entry-point guards
 * (ROADMAP v1 #11 / #234).
 *
 * The per-night-move smoke covers the serialize/parse/resolve helpers + the repo
 * round-trip but explicitly leaves the live AJAX handler "browser-verified at
 * ship time" — so its input guards had no automated coverage. This locks them
 * in: nonce + capability gate, missing-parameter rejection, order-not-found, and
 * the stall/RV `kind` routing. The fixture-dependent conflict branches (blocked
 * destination / not-in-chart / occupied-by-another-order) stay browser-verified;
 * they need a full chart-config fixture and are noted as such.
 *
 * Run: wp eval-file tests/smoke/move-stall-assignment-guards-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () {
	return static function ( $message ) { throw new Exception( is_scalar( $message ) ? (string) $message : 'wp_die' ); };
} );

$admin = new EEM_Admin( true ); // skip hook registration

/** Invoke the handler, capturing the wp_send_json_* payload. */
$call = static function ( array $post ) use ( $admin ) {
	$_POST    = $post;
	$_REQUEST = $post;
	ob_start();
	try {
		$admin->ajax_move_stall_assignment();
	} catch ( Throwable $e ) { /* wp_die handler throws — expected */ }
	$out  = ob_get_clean();
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : array( 'raw' => $out );
};

// --- registration -----------------------------------------------------------
$check( 'move AJAX action registered', false !== has_action( 'wp_ajax_eem_move_stall_assignment' ) );
$check( 'handler method exists', method_exists( 'EEM_Admin', 'ajax_move_stall_assignment' ) );

// --- capability gate (subscriber, valid own-nonce) --------------------------
$sub = wp_create_user( 'eem_move_smoke_sub_' . wp_generate_password( 6, false ), 'pw', 'sub_' . wp_generate_password( 6, false ) . '@example.test' );
if ( ! is_wp_error( $sub ) ) {
	wp_set_current_user( (int) $sub );
	$nonce = wp_create_nonce( 'eem_stall_chart_move' );
	$r = $call( array( '_wpnonce' => $nonce, 'order_id' => 'x', 'source_stall' => '1', 'destination_stall' => '2' ) );
	$check( 'non-admin denied', isset( $r['success'] ) && false === $r['success'] && false !== stripos( (string) ( $r['data']['message'] ?? '' ), 'denied' ) );
	wp_delete_user( (int) $sub );
}

// --- admin context for the remaining guards ---------------------------------
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admins ) ) { wp_set_current_user( (int) $admins[0] ); }
$nonce = wp_create_nonce( 'eem_stall_chart_move' );

// --- missing required parameters (400) --------------------------------------
$r = $call( array( '_wpnonce' => $nonce, 'order_id' => 'abc', 'source_stall' => '', 'destination_stall' => '2' ) );
$check( 'missing source_stall rejected', isset( $r['success'] ) && false === $r['success'] && false !== stripos( (string) ( $r['data']['message'] ?? '' ), 'required' ) );

$r = $call( array( '_wpnonce' => $nonce, 'order_id' => '', 'source_stall' => '1', 'destination_stall' => '2' ) );
$check( 'missing order_id rejected', isset( $r['success'] ) && false === $r['success'] );

// --- order not found (404) --------------------------------------------------
$r = $call( array( '_wpnonce' => $nonce, 'order_id' => 'no-such-order-' . wp_generate_password( 12, false ), 'source_stall' => '1', 'destination_stall' => '2' ) );
$check( 'bogus order_key -> not found', isset( $r['success'] ) && false === $r['success'] && false !== stripos( (string) ( $r['data']['message'] ?? '' ), 'not found' ) );

// --- kind routing: rv kind reaches the same guards (lot noun in message) -----
$r = $call( array( '_wpnonce' => $nonce, 'kind' => 'rv', 'order_id' => 'no-such-order-' . wp_generate_password( 12, false ), 'source_stall' => 'A1', 'destination_stall' => 'A2' ) );
$check( 'rv kind also reaches order-not-found guard', isset( $r['success'] ) && false === $r['success'] );

echo "\nNOTE: blocked-destination / not-in-chart / occupied-by-another-order conflict\n";
echo "branches need a full chart-config fixture and stay browser-verified.\n";
echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
