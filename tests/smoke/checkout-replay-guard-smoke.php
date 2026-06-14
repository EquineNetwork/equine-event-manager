<?php
/**
 * Checkout replay-guard smoke (financial audit MED-1).
 *
 * The main [en_reservation] checkout previously fired the (synchronous Auth.net)
 * charge BEFORE the duplicate-submission-token check — so a double-submit /
 * refresh / retry of an already-completed order could fire a SECOND charge (no
 * duplicate order, but a duplicate charge). The fix short-circuits a replay of an
 * already-processed token BEFORE process_payment_submission(), under the same
 * checkout lock.
 *
 * Asserts: (1) the dedup check is wired ahead of the charge in
 * handle_reservation_submission; (2) it returns the duplicate-success shape so
 * the confirmation render is unchanged; (3) the token mark/has-processed
 * mechanism really round-trips; (4) the belt-and-suspenders dedup inside
 * insert_reservation_orders is still present.
 *
 * Run: wp eval-file tests/smoke/checkout-replay-guard-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

// Isolate handle_reservation_submission.
$start = strpos( $src, 'function handle_reservation_submission' );
$end   = false !== $start ? strpos( $src, "\n\tprivate function ", $start + 10 ) : false;
$body  = ( false !== $start ) ? substr( $src, $start, ( false !== $end ? $end - $start : 6000 ) ) : '';
$check( 'handle_reservation_submission found', '' !== $body );

$pos_guard  = strpos( $body, 'has_processed_submission_token( $replay_token )' );
$pos_charge = strpos( $body, 'process_payment_submission(' );
$check( 'replay guard present in checkout handler', false !== $pos_guard );
$check( 'replay guard runs BEFORE the charge', false !== $pos_guard && false !== $pos_charge && $pos_guard < $pos_charge );
$check( 'replay short-circuit returns duplicate-success shape', false !== strpos( $body, "'duplicate'        => true" ) );
$check( 'charge still happens in the non-replay else branch', false !== strpos( $body, '} else {' ) && false !== strpos( $body, 'insert_reservation_orders(' ) );
$check( 'guard is inside the checkout lock (before RELEASE_LOCK)', false !== $pos_guard && $pos_guard < strpos( $body, 'RELEASE_LOCK' ) );

// Belt-and-suspenders: the in-insert dedup remains.
$check( 'insert_reservation_orders still has its own dedup backstop', false !== strpos( $src, 'has_processed_submission_token( $submission_token )' ) );

// Functional: the token mark / has-processed mechanism round-trips.
$ref  = new ReflectionClass( 'EEM_Shortcodes' );
$sc   = $ref->newInstanceWithoutConstructor();
$has  = $ref->getMethod( 'has_processed_submission_token' ); $has->setAccessible( true );
$mark = $ref->getMethod( 'mark_submission_token_processed' ); $mark->setAccessible( true );

$tok = 'replay_smoke_' . substr( md5( (string) wp_rand() ), 0, 10 );
$check( 'fresh token is NOT yet processed', false === (bool) $has->invoke( $sc, $tok ) );
$mark->invoke( $sc, $tok );
$check( 'token reads as processed after mark (so a replay short-circuits)', true === (bool) $has->invoke( $sc, $tok ) );

// cleanup the transient the mark created.
delete_transient( EEM_Shortcodes::SUBMISSION_TOKEN_TRANSIENT_PREFIX . md5( $tok ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
