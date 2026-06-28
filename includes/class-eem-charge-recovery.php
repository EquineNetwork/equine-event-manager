<?php
/**
 * EEM_Charge_Recovery — durable safety net for the charge→save seam (P3).
 *
 * Every charge path persists the order/payment AFTER the gateway charge succeeds.
 * A crash, fatal, or timeout in that gap would take the customer's money with no
 * record in WordPress — and, worse, a retry could charge again because the
 * "already processed" guard is only set once the save completes.
 *
 * This class records a DURABLE snapshot the instant a charge succeeds, BEFORE the
 * save. The snapshot holds the gateway transaction id plus everything needed to
 * finalize the order. The flow then:
 *   1. saves the order as usual; on success, clears the snapshot;
 *   2. on a retry (or an explicit recovery), finds the snapshot and finalizes the
 *      order from it WITHOUT charging again (idempotent on transaction id);
 *   3. surfaces any snapshot still un-cleared after a grace period as an orphan,
 *      so a charge can never silently vanish.
 *
 * Storage: a non-autoloaded `wp_option` per snapshot (durable across crashes, no
 * schema migration). Volume is one row per in-flight charge and they're deleted
 * on success, so the table stays tiny.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Charge_Recovery {

	/** Option-name prefix for snapshot rows. */
	const OPTION_PREFIX = 'eem_chgrec_';

	/**
	 * Record (or overwrite) a durable recovery snapshot for an in-flight charge.
	 *
	 * Call this the instant the gateway reports success, BEFORE the order save.
	 * The option is stored with autoload disabled so it never bloats page loads.
	 *
	 * @param string               $key     Stable id for this charge — the checkout
	 *                                       submission token, or the order key for an
	 *                                       admin Collect Payment.
	 * @param array<string, mixed> $payload Everything needed to finalize the order,
	 *                                       including at least `transaction_id`,
	 *                                       `gateway`, and `type` ('checkout'|'collect').
	 * @return void
	 */
	public static function snapshot( string $key, array $payload ): void {
		if ( '' === $key ) {
			return;
		}
		if ( empty( $payload['charged_at'] ) ) {
			$payload['charged_at'] = current_time( 'mysql' );
		}
		$payload['recovery_key'] = $key;
		// autoload = false (third arg) — these must never load on every request.
		update_option( self::OPTION_PREFIX . md5( $key ), $payload, false );
	}

	/**
	 * Fetch a snapshot by its key, or null if none / malformed.
	 *
	 * @param string $key Stable charge id (submission token or order key).
	 * @return array<string, mixed>|null
	 */
	public static function get( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}
		$value = get_option( self::OPTION_PREFIX . md5( $key ), null );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Delete a snapshot once its order has been saved (or is confirmed present).
	 *
	 * @param string $key Stable charge id.
	 * @return void
	 */
	public static function clear( string $key ): void {
		if ( '' === $key ) {
			return;
		}
		delete_option( self::OPTION_PREFIX . md5( $key ) );
	}

	/**
	 * Whether any snapshot already records the given gateway transaction id —
	 * used to dedup a retry so the same charge is never finalized twice.
	 *
	 * @param string $transaction_id Gateway transaction id (Stripe intent / Auth.net transId).
	 * @return bool
	 */
	public static function exists_for_transaction( string $transaction_id ): bool {
		if ( '' === $transaction_id ) {
			return false;
		}
		foreach ( self::all() as $snapshot ) {
			if ( isset( $snapshot['transaction_id'] ) && (string) $snapshot['transaction_id'] === $transaction_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List snapshots older than $older_than_seconds — i.e. charges that succeeded
	 * but whose order still has not been saved + cleared. These are the records the
	 * Dashboard "Needs Attention" surface and any recovery sweep act on.
	 *
	 * @param int $older_than_seconds Grace period before a snapshot counts as orphaned.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_orphans( int $older_than_seconds = 300 ): array {
		$cutoff  = current_time( 'timestamp' ) - max( 0, $older_than_seconds );
		$orphans = array();
		foreach ( self::all() as $snapshot ) {
			$charged_at = isset( $snapshot['charged_at'] ) ? strtotime( (string) $snapshot['charged_at'] ) : 0;
			if ( $charged_at && $charged_at <= $cutoff ) {
				$orphans[] = $snapshot;
			}
		}
		return $orphans;
	}

	/**
	 * Load every snapshot row. Direct query (option_name LIKE prefix) because
	 * WordPress has no "options by prefix" API. Volume is tiny (one per in-flight
	 * charge, deleted on success), so this is cheap.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function all(): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$value = maybe_unserialize( $row );
			if ( is_array( $value ) ) {
				$out[] = $value;
			}
		}
		return $out;
	}
}
