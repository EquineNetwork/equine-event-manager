<?php
/**
 * Hosted-invoice payment lock smoke (financial audit F1).
 *
 * The hosted-invoice payment path (`?equine_event_manager_invoice=TOKEN` POST)
 * previously charged the gateway with NO advisory lock and NO dedup — two
 * concurrent or replayed invoice POSTs could both pass the payable check and
 * both charge the card (a customer-facing double-charge on the LAUNCH processor,
 * Authorize.net). It now mirrors the main-checkout pattern: a per-invoice
 * `eem_invoice_<md5>` advisory lock wraps an in-lock payable re-read → charge →
 * mark-paid, released in a finally.
 *
 * Source-presence assertions (the charge path needs a live gateway, so the lock
 * SHAPE is what's guarded here) + a real GET_LOCK round-trip on the lock name.
 *
 * Run: wp eval-file tests/smoke/invoice-payment-lock-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

// Isolate the handler body so the assertions are scoped to it, not the whole file.
$start = strpos( $src, 'function handle_invoice_payment_submission' );
$check( 'handle_invoice_payment_submission exists', false !== $start );
$end  = false !== $start ? strpos( $src, "\n\tprivate function get_invoice_order_gateway", $start ) : false;
$body = false !== $start ? substr( $src, $start, ( false !== $end ? $end - $start : 5000 ) ) : '';

$check( 'acquires a per-invoice GET_LOCK', false !== strpos( $body, "'eem_invoice_' . md5(" ) && false !== strpos( $body, 'GET_LOCK' ) );
$check( 'refuses (fail-safe) when the lock is not acquired', false !== strpos( $body, "1 !== \$got_lock" ) && false !== strpos( $body, 'invoice_busy' ) );
$check( 'wraps the charge in try { … } finally', false !== strpos( $body, 'try {' ) && false !== strpos( $body, '} finally {' ) );
$check( 're-reads the order fresh inside the lock', false !== strpos( $body, '$lock_repo' ) && false !== strpos( $body, "get_order( \$order['order_key'] )" ) );
$check( 'releases the lock in finally', false !== strpos( $body, 'RELEASE_LOCK' ) );

// Ordering: the lock acquire must come BEFORE the payable check + gateway dispatch.
$pos_lock    = strpos( $body, 'GET_LOCK' );
$pos_payable = strpos( $body, 'invoice_order_is_payable' );
$pos_gateway = strpos( $body, 'get_invoice_order_gateway' );
$check( 'lock acquired before the in-lock payable check', false !== $pos_lock && false !== $pos_payable && $pos_lock < $pos_payable );
$check( 'lock acquired before gateway dispatch', false !== $pos_lock && false !== $pos_gateway && $pos_lock < $pos_gateway );

// Functional: the lock name shape really acquires/releases a named MySQL lock.
global $wpdb;
$lock = 'eem_invoice_' . md5( 'smoke-invoice-token' );
$free_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) );
$check( 'invoice lock free before acquire', 1 === $free_before );
$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 1 ) );
$check( 'invoice lock acquires', 1 === $got );
$held = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) );
$check( 'invoice lock held after acquire', 0 === $held );
$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
$free_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) );
$check( 'invoice lock free after release', 1 === $free_after );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
