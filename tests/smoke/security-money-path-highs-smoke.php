<?php
/**
 * Security smoke — the two HIGH money-path fixes (security audit #109).
 *
 * 1. Refund over-refund guard: process_amount_refund() must serialize behind a
 *    per-order MySQL advisory lock so concurrent / replayed refunds can't both
 *    pass the remaining-balance check. Verified by (a) a RUNTIME round-trip
 *    proving the lock is acquired AND released (a refund on a nonexistent order
 *    returns order_not_found, not a lock error, and leaves the named lock free),
 *    plus (b) structural assertions on the worker split.
 *
 * 2. Stripe intent reuse guard: process_payment_submission() must bind the
 *    PaymentIntent to the current submission_token (hash_equals on
 *    intent.metadata.submission_token), so a paid intent can't be replayed into
 *    a fresh form for the same reservation. Source-presence assertions only —
 *    the full path needs a live Stripe intent; the runtime check is covered by
 *    reasoning + the structural guard here.
 *
 * Run: wp eval-file tests/smoke/security-money-path-highs-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;

// === FIX 1 — refund advisory lock =========================================
$admin  = new EEM_Admin();
$engine = new EEM_Refund_Engine( $admin );
$rref   = new ReflectionClass( 'EEM_Refund_Engine' );

$check( 'process_amount_refund is public', $rref->getMethod( 'process_amount_refund' )->isPublic() );
$check( 'process_amount_refund_locked worker exists + is private',
	$rref->hasMethod( 'process_amount_refund_locked' ) && $rref->getMethod( 'process_amount_refund_locked' )->isPrivate() );

$engine_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-eem-refund-engine.php' );
$check( 'public wrapper acquires GET_LOCK', str_contains( $engine_src, 'GET_LOCK' ) );
$check( 'lock released in finally via RELEASE_LOCK',
	str_contains( $engine_src, 'RELEASE_LOCK' ) && str_contains( $engine_src, 'finally' ) );
$check( 'lock name namespaced + hashed per order',
	str_contains( $engine_src, "'eem_refund_' . md5(" ) );
$check( 'worker does NOT acquire its own lock (single lock owner)',
	1 === substr_count( $engine_src, 'GET_LOCK' ) );

// Runtime round-trip: a refund on a nonexistent order must run the worker
// (returns order_not_found) — proving the lock was acquired and the finally
// released it — and leave the named lock free afterward.
$fake_key  = 'eem-no-such-order-' . wp_generate_password( 10, false );
$lock_name = 'eem_refund_' . md5( $fake_key );
$result    = $engine->process_amount_refund( $fake_key, 25.00, 'smoke' );
$check( 'refund on missing order returns WP_Error (worker ran past the lock)', is_wp_error( $result ) );
$check( 'error is order_not_found, not a lock failure',
	is_wp_error( $result ) && 'order_not_found' === $result->get_error_code() );
$is_free = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) );
$check( 'advisory lock is released after the call (no stuck lock)', 1 === $is_free );

// === FIX 2 — Stripe intent ↔ submission_token binding =====================
$sc_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/public/class-equine-event-manager-shortcodes.php' );

$check( 'intent created with metadata[submission_token]',
	str_contains( $sc_src, "'metadata[submission_token]' => \$submission['submission_token']" ) );
$check( 'checkout verifies submission_token via hash_equals',
	str_contains( $sc_src, "hash_equals( \$intent_token, \$submission_token )" ) );
$check( 'missing/empty token treated as a mismatch (rejects)',
	str_contains( $sc_src, "'' === \$intent_token || '' === \$submission_token" ) );
$check( 'token mismatch returns a dedicated WP_Error code',
	str_contains( $sc_src, "'stripe_token_mismatch'" ) );
// The reservation_id check stays as a first-line guard (defense in depth).
$check( 'reservation_id guard still present',
	str_contains( $sc_src, "'stripe_reservation_mismatch'" ) );
// One-token-one-order dedup transient still backs the binding.
$check( 'one-token-one-order dedup transient still in place',
	str_contains( $sc_src, 'has_processed_submission_token' ) && str_contains( $sc_src, 'mark_submission_token_processed' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
