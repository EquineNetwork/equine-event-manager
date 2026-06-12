<?php
/**
 * Division entrants ledger (Entries → Divisions rework, Slice 2).
 *
 * Each row in {prefix}eem_division_entries is a customer's purchased spot(s) in
 * a Division (an `en_entry` CPT post). This ledger is the AUTHORITATIVE source
 * for spots-left, the entrants roster, and the hard-cap enforced at checkout.
 *
 * Spot accounting: a row in status `paid` OR `unpaid` HOLDS a spot (matches the
 * stall/RV inventory rule — unpaid invoice orders still count toward sold). A
 * `refunded` or `cancelled` row FREES the spot. The cap is enforced inside the
 * per-event checkout advisory lock (see EEM_Shortcodes::handle_reservation_submission)
 * so concurrent checkouts can't oversell.
 *
 * Both checkout entry points — the customer `[en_reservation]` form AND the
 * admin Create Order page — flow through the same shortcode submission path, so
 * the ledger write is wired once (in insert_reservation_orders) and covers both.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static repository + listener for the division entrants ledger.
 */
class EEM_Division_Entries {

	/** @var string Unprefixed table name. */
	const TABLE = 'eem_division_entries';

	/** @var string[] Ledger statuses that hold a spot (count toward sold). */
	const HOLDING_STATUSES = array( 'paid', 'unpaid' );

	/**
	 * Fully-qualified table name (with the site DB prefix).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create / upgrade the ledger table via dbDelta. Idempotent — safe to call
	 * on every activation + from the migration.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table_name();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			division_id bigint(20) unsigned NOT NULL,
			order_key varchar(64) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			qty int(11) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'unpaid',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY division_id (division_id),
			KEY order_key (order_key)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Map an order payment-status slug to the ledger status. Paid → paid;
	 * refunded → refunded; cancelled → cancelled; everything else (unpaid,
	 * invoice-sent, pending) holds a spot as `unpaid`.
	 *
	 * @param string $order_status Order payment-status slug (hyphenated or underscored).
	 * @return string Ledger status.
	 */
	public static function ledger_status_for_order_status( string $order_status ): string {
		$slug = str_replace( '-', '_', strtolower( trim( $order_status ) ) );
		if ( 'paid' === $slug ) {
			return 'paid';
		}
		if ( 'refunded' === $slug || 'partially_refunded' === $slug ) {
			return 'refunded';
		}
		if ( 'cancelled' === $slug || 'canceled' === $slug ) {
			return 'cancelled';
		}
		return 'unpaid';
	}

	/**
	 * Record a customer's entry into a Division.
	 *
	 * @param int    $division_id  en_entry post id.
	 * @param string $order_key    Owning order key.
	 * @param string $customer_name Display name.
	 * @param string $email        Customer email.
	 * @param int    $qty          Spots purchased (>=1).
	 * @param string $status       Ledger status (paid|unpaid|refunded|cancelled).
	 * @return int|false Inserted row id, or false on failure.
	 */
	public static function record_entry( int $division_id, string $order_key, string $customer_name, string $email, int $qty, string $status ) {
		global $wpdb;
		if ( $division_id <= 0 || $qty <= 0 ) {
			return false;
		}
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_name(),
			array(
				'division_id'   => $division_id,
				'order_key'     => $order_key,
				'customer_name' => $customer_name,
				'email'         => $email,
				'qty'           => max( 1, $qty ),
				'status'        => $status,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Total spots currently held (status in paid|unpaid) for a Division.
	 *
	 * @param int $division_id en_entry post id.
	 * @return int
	 */
	public static function entered_count( int $division_id ): int {
		global $wpdb;
		if ( $division_id <= 0 ) {
			return 0;
		}
		$sum = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT COALESCE(SUM(qty),0) FROM ' . self::table_name() . " WHERE division_id = %d AND status IN ('paid','unpaid')",
			$division_id
		) );
		return (int) $sum;
	}

	/**
	 * Spots remaining for a Division. Returns null when the Division is
	 * unlimited (spots blank/0).
	 *
	 * @param int      $division_id en_entry post id.
	 * @param int|null $spots       Configured total spots (0/null = unlimited).
	 * @return int|null Remaining spots, or null for unlimited.
	 */
	public static function spots_left( int $division_id, ?int $spots ): ?int {
		if ( null === $spots || $spots <= 0 ) {
			return null;
		}
		return max( 0, $spots - self::entered_count( $division_id ) );
	}

	/**
	 * Entrants roster for a Division (newest first). Each row carries the raw
	 * ledger fields; the detail page joins order metadata for display.
	 *
	 * @param int $division_id en_entry post id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entrants( int $division_id ): array {
		global $wpdb;
		if ( $division_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT id, division_id, order_key, customer_name, email, qty, status, created_at FROM ' . self::table_name() . ' WHERE division_id = %d ORDER BY created_at DESC, id DESC',
			$division_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether an order has at least one division entry (any status). Used by
	 * the Orders list to show the "Entry" type badge.
	 *
	 * @param string $order_key Owning order key.
	 * @return bool
	 */
	public static function order_has_entries( string $order_key ): bool {
		global $wpdb;
		if ( '' === $order_key ) {
			return false;
		}
		$count = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE order_key = %s',
			$order_key
		) );
		return (int) $count > 0;
	}

	/**
	 * Sync every ledger row for an order to a new status (refund/cancel free the
	 * spot; a later payment promotes unpaid → paid). Terminal statuses
	 * (refunded/cancelled) are not overwritten by a subsequent paid event.
	 *
	 * @param string $order_key    Owning order key.
	 * @param string $order_status New order payment-status slug.
	 * @return int Rows updated.
	 */
	public static function sync_status_for_order( string $order_key, string $order_status ): int {
		global $wpdb;
		if ( '' === $order_key ) {
			return 0;
		}
		$new = self::ledger_status_for_order_status( $order_status );

		// Promoting to paid must not resurrect a refunded/cancelled row.
		if ( 'paid' === $new ) {
			$updated = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'UPDATE ' . self::table_name() . " SET status = 'paid' WHERE order_key = %s AND status = 'unpaid'",
				$order_key
			) );
			return (int) $updated;
		}

		$updated = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'UPDATE ' . self::table_name() . ' SET status = %s WHERE order_key = %s',
			$new,
			$order_key
		) );
		return (int) $updated;
	}

	/**
	 * Listener: `eem_order_payment_status_changed`. Keeps the ledger in step
	 * with the order's lifecycle so spots free on refund/cancel and promote on
	 * payment.
	 *
	 * @param array $payload Status-change payload (order_key + new_status).
	 * @return void
	 */
	public static function on_order_payment_status_changed( array $payload ): void {
		$order_key  = isset( $payload['order_key'] ) ? (string) $payload['order_key'] : '';
		$new_status = isset( $payload['new_status'] ) ? (string) $payload['new_status'] : '';
		if ( '' === $order_key || '' === $new_status ) {
			return;
		}
		self::sync_status_for_order( $order_key, $new_status );
	}
}
