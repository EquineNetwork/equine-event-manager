<?php
/**
 * Readable frontend-URL smoke (v1 #5).
 *
 * Linked reservations must resolve to the readable virtual event route
 * (/equine-event/{slug}-{id}/) — including pre-existing reservations that still
 * carry a legacy `_eem_frontend_url_cache` pointing at the old /event/{slug}/
 * form. The resolver now resolves the readable route up front (bypassing the
 * cache for linked reservations), and eem-mig-012 deletes the dead cache meta.
 *
 * Run: wp eval-file tests/smoke/readable-frontend-url-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$resolve = new ReflectionMethod( 'EEM_Reservations_List_Page', 'resolve_frontend_url' );
$resolve->setAccessible( true );
$page = new EEM_Reservations_List_Page();

// --- linked reservation with a STALE cache → readable route wins ------------
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Readable URL Smoke Event' ) );
update_post_meta( $rid, '_en_event_id', 99001 ); // a linked-event signal.
update_post_meta( $rid, '_eem_frontend_url_cache', 'http://example.test/event/old-bare-form/' ); // legacy stale cache.

$resolved = (string) $resolve->invoke( $page, $rid );
$readable = EEM_Events::get_reservation_public_url( $rid );

$check( 'readable route has the /equine-event/{slug}-{id}/ shape', false !== strpos( $readable, '/equine-event/readable-url-smoke-event-' . $rid ) );
$check( 'linked reservation resolves to the readable route, NOT the stale cache', $resolved === $readable );
$check( 'stale cache value is bypassed', false === strpos( $resolved, 'old-bare-form' ) );

// --- migration deletes the dead cache meta ---------------------------------
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-012-frontend-url-cache-cleanup.php';
// Re-arm the flag so the migration runs in this smoke (it may already be done).
delete_option( 'eem_mig_012_frontend_url_cache_cleanup_complete' );
$mig = eem_mig_012_frontend_url_cache_cleanup();
$check( 'migration deletes at least the seeded cache row', isset( $mig['deleted'] ) && $mig['deleted'] >= 1 );
$check( 'seeded reservation no longer has the cache meta', '' === (string) get_post_meta( $rid, '_eem_frontend_url_cache', true ) );

// Idempotent: a second run is a no-op (flag set).
$mig2 = eem_mig_012_frontend_url_cache_cleanup();
$check( 'migration is flag-gated / idempotent (second run deletes 0)', 0 === $mig2['deleted'] );

// --- still readable after the cache is gone --------------------------------
$check( 'resolver still returns the readable route after cache cleanup', EEM_Events::get_reservation_public_url( $rid ) === (string) $resolve->invoke( $page, $rid ) );

// --- registered in the activator -------------------------------------------
$act_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php' );
$check( 'eem-mig-012 registered in the activator', false !== strpos( $act_src, 'eem_mig_012_frontend_url_cache_cleanup' ) );

wp_delete_post( (int) $rid, true );
$check( 'cleaned up temp reservation', null === get_post( $rid ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
