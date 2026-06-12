<?php
/**
 * Assignment-lock smoke (v1 #2 — inventory concurrent-assign backstop).
 *
 * Every admin stall/RV assignment write serializes behind the SAME per-reservation
 * MySQL advisory lock the customer checkout uses (`eem_checkout_{reservation_id}`),
 * so a conflict re-check + write is atomic and two concurrent assigns (or an assign
 * racing a checkout) can never double-book a stall / RV lot.
 *
 * Asserts: (1) the lock helpers exist + really acquire/release the named MySQL
 * lock; (2) the lock key is byte-identical to the checkout key; (3) every one of
 * the five admin assignment write handlers takes the lock.
 *
 * Run: wp eval-file tests/smoke/assignment-lock-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;

// --- helpers exist ----------------------------------------------------------
$ref = new ReflectionClass( 'EEM_Admin' );
$check( 'acquire_assignment_lock() defined', $ref->hasMethod( 'acquire_assignment_lock' ) );
$check( 'release_assignment_lock() defined', $ref->hasMethod( 'release_assignment_lock' ) );
$check( 'send_assignment_lock_busy() defined', $ref->hasMethod( 'send_assignment_lock_busy' ) );

// --- functional: the helper really holds + frees the named MySQL lock -------
// Use a real, unused reservation-id-shaped value. IS_FREE_LOCK returns 1 when
// free, 0 when held by ANY session. We acquire (this session), assert held,
// release, assert free.
$admin   = $ref->newInstanceWithoutConstructor();
$acquire = $ref->getMethod( 'acquire_assignment_lock' ); $acquire->setAccessible( true );
$release = $ref->getMethod( 'release_assignment_lock' ); $release->setAccessible( true );

$probe_id  = 990001; // arbitrary; not a real reservation, lock name is just a string.
$lock_name = 'eem_checkout_' . $probe_id;

$free_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) );
$check( 'lock is free before acquire', 1 === $free_before );

$got = (bool) $acquire->invoke( $admin, $probe_id );
$check( 'acquire_assignment_lock() returns true on a free lock', $got );

$free_during = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) );
$check( 'lock is held after acquire (IS_FREE_LOCK = 0)', 0 === $free_during );

$release->invoke( $admin, $probe_id );
$free_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) );
$check( 'lock is free after release', 1 === $free_after );

// id 0 → no-op acquire returns true (nothing to lock), takes no MySQL lock.
$check( 'acquire with id 0 is a no-op true', true === $acquire->invoke( $admin, 0 ) );

// --- key parity with checkout + handler coverage (source presence) ----------
$admin_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$checkout_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

$check( 'checkout uses eem_checkout_ lock key', false !== strpos( $checkout_src, "'eem_checkout_' . absint( \$reservation_id )" ) );
$check( 'admin lock helper uses the same eem_checkout_ key', false !== strpos( $admin_src, "'eem_checkout_' . \$reservation_id" ) );

// Each of the five admin assignment write handlers must take the lock. Count the
// acquire_assignment_lock( call sites — one per handler, at minimum five.
$acquire_calls = preg_match_all( '/\$this->acquire_assignment_lock\s*\(/', $admin_src );
$check( 'at least 5 acquire_assignment_lock() call sites (one per write handler)', $acquire_calls >= 5 );

// Spot-check the two AJAX entry points that re-check conflicts then write.
foreach ( array(
	'ajax_stall_map_action'       => 'map assign/unassign/block',
	'ajax_move_stall_assignment'  => 'drag-move',
	'ajax_auto_assign'            => 'auto-generate (AJAX)',
	'handle_update_order_assignments' => 'Order Detail form',
	'handle_generate_stall_assignments' => 'auto-generate (non-AJAX)',
) as $method => $desc ) {
	$m = $ref->getMethod( $method );
	$start = $m->getStartLine();
	$end   = $m->getEndLine();
	$lines = array_slice( file( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' ), $start - 1, $end - $start + 1 );
	$body  = implode( '', $lines );
	$check( "{$method} ({$desc}) acquires the assignment lock", false !== strpos( $body, 'acquire_assignment_lock' ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
