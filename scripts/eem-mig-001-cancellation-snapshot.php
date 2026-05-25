<?php
/**
 * wp-cli fallback wrapper for migration #001 (C7.A, Option d).
 *
 * Per Q6: defensive manual lever. The migration normally fires
 * automatically via `EEM_Activator::maybe_upgrade()` → `activate()` →
 * `run_one_time_migrations()` on any plugin version bump. This wrapper
 * is for emergency operator action if the auto-runner fails for any
 * reason (timeout on a very large site, fatal in unrelated code
 * blocking the `init` hook, manual re-run after un-flagging for
 * testing, etc.).
 *
 * Idempotent re-runs: the underlying migration is flag-gated. To
 * force a re-run, delete the `eem_mig_001_cancellation_snapshot_complete`
 * option first:
 *   wp option delete eem_mig_001_cancellation_snapshot_complete
 *   wp eval-file scripts/eem-mig-001-cancellation-snapshot.php
 *
 * Usage (from the WP install root):
 *   wp eval-file /path/to/equine-event-manager/scripts/eem-mig-001-cancellation-snapshot.php
 *
 * Output style mirrors scripts/dev-backfill-seed-reservation-ids.php
 * (per-row tally + final summary).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_mig_001_cancellation_snapshot' ) ) {
	require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-001-cancellation-snapshot.php';
}

$flag_key      = 'eem_mig_001_cancellation_snapshot_complete';
$already_done  = get_option( $flag_key );
$global_policy = (string) get_option( 'cancellation_policy', '' );

echo "=== EEM Migration #001 — Cancellation Policy Snapshot ===\n";
echo 'Flag (' . $flag_key . '): ' . ( $already_done ? 'SET at ' . gmdate( 'Y-m-d H:i:s', (int) $already_done ) . ' UTC' : 'unset' ) . "\n";
echo 'Global cancellation_policy: ' . ( '' === trim( $global_policy ) ? '(empty)' : strlen( $global_policy ) . ' chars' ) . "\n\n";

$result = eem_mig_001_cancellation_snapshot();

echo "Source path:        " . $result['source'] . "\n";
echo "Reservations scanned: " . $result['scanned'] . "\n";
echo "Newly snapshotted:    " . $result['snapshotted'] . "\n";
echo "Skipped (already set):" . $result['skipped'] . "\n";

$flag_after = get_option( $flag_key );
echo "\nFlag after run:     " . ( $flag_after ? 'SET at ' . gmdate( 'Y-m-d H:i:s', (int) $flag_after ) . ' UTC' : 'unset' ) . "\n";
echo "=== DONE ===\n";
