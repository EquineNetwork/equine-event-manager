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

// === FIX 3 — inventory oversell: per-reservation checkout advisory lock ====
// handle_reservation_submission must serialize the re-validate -> charge ->
// insert critical section behind a per-reservation MySQL advisory lock, re-read
// availability FRESH inside the lock, and release in a finally. Source-presence
// assertions on structure + a runtime check that the lock primitive works and
// frees on this DB.
$check( 'checkout acquires a per-reservation GET_LOCK',
	str_contains( $sc_src, "'eem_checkout_' . absint( \$reservation_id )" ) && str_contains( $sc_src, 'GET_LOCK' ) );
$check( 'checkout lock released in a finally via RELEASE_LOCK',
	str_contains( $sc_src, 'RELEASE_LOCK' ) && str_contains( $sc_src, '} finally {' ) );
$check( 'timeout-to-acquire returns a friendly retry notice (no charge)',
	str_contains( $sc_src, 'Another checkout for this event is still finishing' ) );

// Availability is recomputed under the lock and re-validated before charging.
$lock_pos   = strpos( $sc_src, "'eem_checkout_'" );
$status_pos = strpos( $sc_src, '$live_status = $this->get_reservation_status( $data, $reservation_id );' );
$reval_pos  = strpos( $sc_src, '$live_errors = $this->validate_submission( $submission, $live_status, $data );' );
$pay_pos    = strpos( $sc_src, '$payment_result = $this->process_payment_submission( $reservation_id, $data, $submission, $live_status );' );
$insert_pos = strpos( $sc_src, '$insert_result = $this->insert_reservation_orders( $reservation_id, $data, $submission, $live_status, $payment_result );' );
$check( 'fresh status recomputed inside the lock', false !== $status_pos && $status_pos > $lock_pos );
$check( 're-validation runs against the fresh status', false !== $reval_pos && $reval_pos > $status_pos );
$check( 'payment uses the fresh (locked) status, not the stale snapshot', false !== $pay_pos && $pay_pos > $reval_pos );
$check( 'insert happens after the re-validation + charge, inside the lock',
	false !== $insert_pos && $insert_pos > $pay_pos );

// Runtime: the checkout-lock primitive works on this DB and frees cleanly.
$ck_lock = 'eem_checkout_smoke_' . wp_generate_password( 8, false );
$got     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $ck_lock, 2 ) );
$held    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $ck_lock ) );
$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $ck_lock ) );
$freed   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $ck_lock ) );
$check( 'GET_LOCK acquires + IS_FREE_LOCK reflects held/freed', 1 === $got && 0 === $held && 1 === $freed );

// === FIX 4 — agreement upload server-side PDF MIME enforcement =============
// sanitize_agreement_file_id() must drop any non-PDF (or non-existent)
// attachment id to 0 — the client-side Media Library filter is bypassable.
$cpt    = new EEM_Reservations_CPT();
$mimefn = new ReflectionMethod( 'EEM_Reservations_CPT', 'sanitize_agreement_file_id' );
$mimefn->setAccessible( true );

$pdf_id = wp_insert_post( array( 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_title' => 'smoke-agreement', 'post_status' => 'inherit' ) );
$png_id = wp_insert_post( array( 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_title' => 'smoke-not-agreement', 'post_status' => 'inherit' ) );
$check( 'a real PDF attachment id is accepted', (int) $pdf_id === (int) $mimefn->invoke( $cpt, $pdf_id ) );
$check( 'a non-PDF (image) attachment id is rejected to 0', 0 === (int) $mimefn->invoke( $cpt, $png_id ) );
$check( 'a non-existent attachment id is rejected to 0', 0 === (int) $mimefn->invoke( $cpt, 99999999 ) );
$check( 'zero / empty stays 0', 0 === (int) $mimefn->invoke( $cpt, 0 ) );
$check( 'save sanitizer routes the agreement id through the PDF gate',
	str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-equine-event-manager-reservations-cpt.php' ), '$this->sanitize_agreement_file_id( $source[\'venue_agreement_file_id\'] )' ) );
wp_delete_post( (int) $pdf_id, true );
wp_delete_post( (int) $png_id, true );

// === FIX 5 — Stripe webhook re-checks amount + status before marking paid ==
$check( 'webhook requires intent status === succeeded',
	str_contains( $sc_src, "'succeeded' !== \$intent_status" ) );
$check( 'webhook compares captured cents against the order total',
	str_contains( $sc_src, '$expected_cents' ) && str_contains( $sc_src, 'amount_received' ) );
$check( 'underpayment is logged + does NOT mark paid',
	str_contains( $sc_src, "'order_payment_amount_mismatch'" ) && str_contains( $sc_src, '$paid_cents < $expected_cents' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
