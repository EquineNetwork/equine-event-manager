<?php
/**
 * Stall Check-In/Out lifecycle repository.
 *
 * Manages the `wp_eem_stall_status` (current state) and
 * `wp_eem_stall_status_log` (audit trail) tables. One row per stall per
 * night; absence = available. Rows are created lazily when stall
 * assignments are saved (status = 'occupied').
 *
 * Status chain:
 *   available → occupied → checked_in → checked_out → needs_cleaning → clean → available
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Stall_Status_Repo {

	/**
	 * Allowed status values.
	 */
	const STATUSES = array(
		'occupied',
		'checked_in',
		'checked_out',
		'needs_cleaning',
		'clean',
	);

	/**
	 * Allowed forward transitions (non-override).
	 *
	 * Key = from status, value = array of allowed next statuses.
	 *
	 * @var array<string, string[]>
	 */
	const TRANSITIONS = array(
		'occupied'       => array( 'checked_in' ),
		'checked_in'     => array( 'checked_out' ),
		'checked_out'    => array( 'needs_cleaning' ),
		'needs_cleaning' => array( 'clean' ),
	);

	/**
	 * Create both tables via dbDelta. Idempotent.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$status_table    = $wpdb->prefix . 'eem_stall_status';
		$log_table       = $wpdb->prefix . 'eem_stall_status_log';

		$status_sql = "CREATE TABLE {$status_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			stall_unit varchar(20) NOT NULL,
			night_date date NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'occupied',
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_stall_night (reservation_id, stall_unit, night_date),
			KEY idx_order (order_id),
			KEY idx_status (reservation_id, night_date, status)
		) {$charset_collate};";

		// Per-ORDER customer check-in status (one row per order, covering the
		// whole party — stalls AND RV). "Is the customer here?" — checking in
		// releases their units. Keyed by (reservation_id, order_number) which is
		// the only identifier shared between Daily Movement and the Stall Charts
		// By-Customer table. Absence of a row = 'occupied' (Pending Arrival).
		$checkin_table = $wpdb->prefix . 'eem_order_checkin';
		$checkin_sql   = "CREATE TABLE {$checkin_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_id bigint(20) unsigned NOT NULL,
			order_number varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'occupied',
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_order (reservation_id, order_number)
		) {$charset_collate};";

		$log_sql = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stall_status_id bigint(20) unsigned NOT NULL,
			from_status varchar(20) NOT NULL,
			to_status varchar(20) NOT NULL,
			performed_by bigint(20) unsigned NOT NULL,
			performed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			override_reason varchar(100) DEFAULT NULL,
			note text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_stall_status (stall_status_id),
			KEY idx_performed_at (performed_at)
		) {$charset_collate};";

		dbDelta( $status_sql );
		dbDelta( $log_sql );
		dbDelta( $checkin_sql );
	}

	/**
	 * Allowed per-order customer check-in statuses (the three the UI exposes).
	 */
	const CHECKIN_STATUSES = array( 'occupied', 'checked_in', 'checked_out' );

	/**
	 * Read one order's customer check-in status. Absence = 'occupied'
	 * (Pending Arrival).
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $order_number   The order's human number (e.g. "09020").
	 * @return string occupied|checked_in|checked_out
	 */
	public static function get_order_checkin( int $reservation_id, string $order_number ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_checkin';
		$val   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE reservation_id = %d AND order_number = %s",
				$reservation_id,
				$order_number
			)
		);
		return $val ? (string) $val : 'occupied';
	}

	/**
	 * Batch-read every stored check-in status for a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array<string, string> order_number → status (only stored rows).
	 */
	public static function get_order_checkin_map( int $reservation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_checkin';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_number, status FROM {$table} WHERE reservation_id = %d",
				$reservation_id
			),
			ARRAY_A
		) ?: array();

		$map = array();
		foreach ( $rows as $r ) {
			$map[ (string) $r['order_number'] ] = (string) $r['status'];
		}
		return $map;
	}

	/**
	 * Set one order's customer check-in status (upsert). Any direction allowed —
	 * a mistaken check-out can be reverted. 'occupied' is the Pending Arrival
	 * baseline.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $order_number   The order's human number.
	 * @param string $status         Target status (must be in CHECKIN_STATUSES).
	 * @param int    $user_id        Acting user.
	 * @return string The stored status (unchanged input when invalid).
	 */
	public static function set_order_checkin( int $reservation_id, string $order_number, string $status, int $user_id ): string {
		if ( ! in_array( $status, self::CHECKIN_STATUSES, true ) ) {
			return self::get_order_checkin( $reservation_id, $order_number );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_checkin';

		// Upsert on the (reservation_id, order_number) unique key.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} ( reservation_id, order_number, status, updated_by, updated_at )
				 VALUES ( %d, %s, %s, %d, %s )
				 ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
				$reservation_id,
				$order_number,
				$status,
				$user_id,
				current_time( 'mysql' )
			)
		);

		return $status;
	}

	/* ─────────────────────────────────────────────────────────────────────────
	 * Per-stall-night READINESS (By Location grid): Occupied / Cleaning / Available.
	 * The wp_eem_stall_status row's status drives a cell's readiness; absence of a
	 * row falls back to assignment-derived occupancy. Distinct from per-ORDER
	 * customer check-in (wp_eem_order_checkin) which answers "is the person here?".
	 * ──────────────────────────────────────────────────────────────────────── */

	/**
	 * Build a status map for the whole reservation: [stall_unit][night_date] => status.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array<string, array<string, string>>
	 */
	public static function get_status_map( int $reservation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stall_unit, night_date, status FROM {$table} WHERE reservation_id = %d",
				$reservation_id
			),
			ARRAY_A
		) ?: array();

		$map = array();
		foreach ( $rows as $r ) {
			$map[ (string) $r['stall_unit'] ][ (string) $r['night_date'] ] = (string) $r['status'];
		}
		return $map;
	}

	/**
	 * Directly set (upsert) the readiness status for one stall on one night.
	 * Override write — admins set Available / Cleaning regardless of the
	 * forward-only chain. A new row gets order_id 0 (manual / no occupant).
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $stall_unit     Stall/lot label.
	 * @param string $night_date     Y-m-d.
	 * @param string $status         Target status.
	 * @param int    $user_id        Acting user.
	 * @return string The stored status.
	 */
	public static function set_cell_status( int $reservation_id, string $stall_unit, string $night_date, string $status, int $user_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} ( reservation_id, order_id, stall_unit, night_date, status, updated_by, updated_at )
				 VALUES ( %d, %d, %s, %s, %s, %d, %s )
				 ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
				$reservation_id,
				0,
				$stall_unit,
				$night_date,
				$status,
				$user_id,
				current_time( 'mysql' )
			)
		);

		return $status;
	}

	/**
	 * Bulk-set the readiness status for many stalls across many nights.
	 *
	 * @param int      $reservation_id Reservation post ID.
	 * @param string[] $stall_units    Stall labels to update.
	 * @param string[] $night_dates    Nights to update (each stall × each night).
	 * @param string   $status         Target status.
	 * @param int      $user_id        Acting user.
	 * @return int Number of cell writes performed.
	 */
	public static function bulk_set_status( int $reservation_id, array $stall_units, array $night_dates, string $status, int $user_id ): int {
		$written = 0;
		foreach ( $stall_units as $unit ) {
			$unit = sanitize_text_field( (string) $unit );
			if ( '' === $unit ) {
				continue;
			}
			foreach ( $night_dates as $date ) {
				$date = (string) $date;
				if ( '' === $date || ! strtotime( $date ) ) {
					continue;
				}
				self::set_cell_status( $reservation_id, $unit, $date, $status, $user_id );
				$written++;
			}
		}
		return $written;
	}

	/**
	 * Flip every stall-night row for an order to 'needs_cleaning' — the turnover
	 * auto-step fired when the customer is marked Checked Out. Resolves the order
	 * by (reservation_id, order_number) → wp_eem_stall_reservations.id.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $order_number   The order's human number.
	 * @param int    $user_id        Acting user.
	 * @return int Rows flipped.
	 */
	public static function mark_order_stalls_needs_cleaning( int $reservation_id, string $order_number, int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';
		$sr    = $wpdb->prefix . 'eem_stall_reservations';

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} ss
				 INNER JOIN {$sr} sr ON sr.id = ss.order_id
				 SET ss.status = 'needs_cleaning', ss.updated_by = %d, ss.updated_at = %s
				 WHERE sr.reservation_id = %d AND sr.order_number = %s
				   AND ss.status NOT IN ( 'needs_cleaning', 'clean', 'available' )",
				$user_id,
				current_time( 'mysql' ),
				$reservation_id,
				$order_number
			)
		);
	}

	/**
	 * Get the current status for a single stall on a single night.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $stall_unit     Stall label (e.g. "101").
	 * @param string $night_date     Date in Y-m-d format.
	 * @return array|null Row array or null if no row (= available).
	 */
	public static function get( int $reservation_id, string $stall_unit, string $night_date ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE reservation_id = %d AND stall_unit = %s AND night_date = %s",
				$reservation_id,
				$stall_unit,
				$night_date
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get all stall statuses for a reservation on a given date.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $night_date     Date in Y-m-d format.
	 * @return array[] Array of status rows.
	 */
	public static function get_for_date( int $reservation_id, string $night_date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE reservation_id = %d AND night_date = %s ORDER BY stall_unit ASC",
				$reservation_id,
				$night_date
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get distinct stall units currently flagged 'needs_cleaning' for a reservation.
	 *
	 * Used by the customer-facing inventory gate: stalls being cleaned are
	 * excluded from the available pool so they cannot be booked until an admin
	 * marks them clean.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string[] Stall unit labels with at least one night in needs_cleaning.
	 */
	public static function get_cleaning_units( int $reservation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$units = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT stall_unit FROM {$table} WHERE reservation_id = %d AND status = 'needs_cleaning'",
				$reservation_id
			)
		);

		return $units ?: array();
	}

	/**
	 * Get all stall statuses for a specific order.
	 *
	 * @param int $order_id Order row ID (wp_eem_stall_reservations.id).
	 * @return array[] Array of status rows.
	 */
	public static function get_for_order( int $order_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_id = %d ORDER BY night_date ASC, stall_unit ASC",
				$order_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get status counts for a reservation on a given date.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $night_date     Date in Y-m-d format.
	 * @return array<string, int> Keyed by status value.
	 */
	public static function get_counts( int $reservation_id, string $night_date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM {$table} WHERE reservation_id = %d AND night_date = %s GROUP BY status",
				$reservation_id,
				$night_date
			),
			ARRAY_A
		) ?: array();

		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ $r['status'] ] = (int) $r['cnt'];
		}
		return $counts;
	}

	/**
	 * Insert status rows for newly assigned stalls (status = 'occupied').
	 *
	 * Called when an order is placed or admin assigns stalls. Creates one
	 * row per stall per night across the stay date range.
	 *
	 * @param int      $reservation_id Reservation post ID.
	 * @param int      $order_id       Order row ID.
	 * @param string[] $stall_units    Array of stall labels.
	 * @param string   $arrival_date   Y-m-d.
	 * @param string   $departure_date Y-m-d.
	 * @param int      $user_id        WP user ID performing the action.
	 * @return int Number of rows inserted.
	 */
	public static function create_occupied( int $reservation_id, int $order_id, array $stall_units, string $arrival_date, string $departure_date, int $user_id = 0 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';
		$now   = current_time( 'mysql' );

		$inserted = 0;
		$dates    = self::date_range( $arrival_date, $departure_date );

		foreach ( $stall_units as $unit ) {
			$unit = sanitize_text_field( $unit );
			foreach ( $dates as $date ) {
				// Idempotent: skip if a row already exists for this stall+night+reservation.
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM {$table} WHERE reservation_id = %d AND stall_unit = %s AND night_date = %s LIMIT 1",
						$reservation_id,
						$unit,
						$date
					)
				);
				if ( $exists ) {
					continue;
				}

				$result = $wpdb->insert(
					$table,
					array(
						'reservation_id' => $reservation_id,
						'order_id'       => $order_id,
						'stall_unit'     => $unit,
						'night_date'     => $date,
						'status'         => 'occupied',
						'updated_by'     => $user_id,
						'updated_at'     => $now,
					),
					array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
				);
				if ( false !== $result ) {
					$inserted++;
				}
			}
		}

		return $inserted;
	}

	/**
	 * Return which of the given stall units are already occupied (by any order) on
	 * one or more nights in [arrival, departure). Used to block a double-book before
	 * an admin quick-add / placeholder assigns a unit a live customer already holds.
	 *
	 * @param int      $reservation_id Reservation post ID.
	 * @param string[] $stall_units    Candidate unit labels.
	 * @param string   $arrival_date   Y-m-d (inclusive).
	 * @param string   $departure_date Y-m-d (exclusive — same night semantics as create_occupied()).
	 * @return string[] The subset of $stall_units that conflict (empty array = all free).
	 */
	public static function units_occupied_in_window( int $reservation_id, array $stall_units, string $arrival_date, string $departure_date ): array {
		global $wpdb;

		$units = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $stall_units ), 'strlen' ) ) );
		$dates = self::date_range( $arrival_date, $departure_date );
		if ( empty( $units ) || empty( $dates ) ) {
			return array();
		}

		$table   = $wpdb->prefix . 'eem_stall_status';
		$unit_ph = implode( ',', array_fill( 0, count( $units ), '%s' ) );
		$date_ph = implode( ',', array_fill( 0, count( $dates ), '%s' ) );
		$params  = array_merge( array( $reservation_id ), $units, $dates );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from counts; values bound below.
		$sql  = "SELECT DISTINCT stall_unit FROM {$table} WHERE reservation_id = %d AND status = 'occupied' AND stall_unit IN ({$unit_ph}) AND night_date IN ({$date_ph})";
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_values( array_map( 'strval', (array) $rows ) );
	}

	/**
	 * Transition a stall's status for a given night.
	 *
	 * Enforces the allowed transition chain unless $is_override is true.
	 *
	 * @param int    $reservation_id  Reservation post ID.
	 * @param string $stall_unit      Stall label.
	 * @param string $night_date      Y-m-d.
	 * @param string $new_status      Target status.
	 * @param int    $user_id         WP user ID.
	 * @param bool   $is_override     Skip transition validation.
	 * @param string $override_reason Machine-readable reason (e.g. 'missing_docs').
	 * @param string $note            Free-text note.
	 * @return array{success: bool, message: string, row?: array}
	 */
	public static function transition( int $reservation_id, string $stall_unit, string $night_date, string $new_status, int $user_id, bool $is_override = false, string $override_reason = '', string $note = '' ): array {
		global $wpdb;
		$table     = $wpdb->prefix . 'eem_stall_status';
		$log_table = $wpdb->prefix . 'eem_stall_status_log';

		if ( ! in_array( $new_status, self::STATUSES, true ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid status.', 'equine-event-manager' ) );
		}

		$row = self::get( $reservation_id, $stall_unit, $night_date );
		if ( ! $row ) {
			return array( 'success' => false, 'message' => __( 'No status row found for this stall/night.', 'equine-event-manager' ) );
		}

		$from_status = $row['status'];

		if ( $from_status === $new_status ) {
			return array( 'success' => true, 'message' => __( 'Already at this status.', 'equine-event-manager' ), 'row' => $row );
		}

		if ( ! $is_override ) {
			$allowed = self::TRANSITIONS[ $from_status ] ?? array();
			if ( ! in_array( $new_status, $allowed, true ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: current status, 2: requested status */
						__( 'Cannot transition from "%1$s" to "%2$s".', 'equine-event-manager' ),
						$from_status,
						$new_status
					),
				);
			}
		}

		$now = current_time( 'mysql' );

		$wpdb->update(
			$table,
			array(
				'status'     => $new_status,
				'updated_by' => $user_id,
				'updated_at' => $now,
			),
			array( 'id' => $row['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$wpdb->insert(
			$log_table,
			array(
				'stall_status_id' => (int) $row['id'],
				'from_status'     => $from_status,
				'to_status'       => $new_status,
				'performed_by'    => $user_id,
				'performed_at'    => $now,
				'override_reason' => $is_override && '' !== $override_reason ? sanitize_text_field( $override_reason ) : null,
				'note'            => '' !== $note ? sanitize_textarea_field( $note ) : null,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$updated_row = self::get( $reservation_id, $stall_unit, $night_date );
		return array( 'success' => true, 'message' => __( 'Status updated.', 'equine-event-manager' ), 'row' => $updated_row );
	}

	/**
	 * Bulk transition: move all stalls for an order on a given night.
	 *
	 * @param int    $order_id   Order row ID.
	 * @param string $night_date Y-m-d.
	 * @param string $new_status Target status.
	 * @param int    $user_id    WP user ID.
	 * @param bool   $is_override     Skip transition validation.
	 * @param string $override_reason Machine-readable reason.
	 * @param string $note            Free-text note.
	 * @return array{success: int, failed: int, errors: string[]}
	 */
	public static function transition_order( int $order_id, string $night_date, string $new_status, int $user_id, bool $is_override = false, string $override_reason = '', string $note = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reservation_id, stall_unit, night_date FROM {$table} WHERE order_id = %d AND night_date = %s",
				$order_id,
				$night_date
			),
			ARRAY_A
		) ?: array();

		$success = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $rows as $r ) {
			$result = self::transition(
				(int) $r['reservation_id'],
				$r['stall_unit'],
				$r['night_date'],
				$new_status,
				$user_id,
				$is_override,
				$override_reason,
				$note
			);
			if ( $result['success'] ) {
				$success++;
			} else {
				$failed++;
				$errors[] = $r['stall_unit'] . ': ' . $result['message'];
			}
		}

		return array( 'success' => $success, 'failed' => $failed, 'errors' => $errors );
	}

	/**
	 * Whole-stay transition: move EVERY status row for an order (all stalls, all
	 * nights) to $new_status. Drives the admin Check In / Check Out actions where
	 * one click applies to the customer's entire stay (hotel-style front desk).
	 * Rows already at/ahead of $new_status are skipped by the per-row transition
	 * guard, so re-clicking is harmless. Returns the resolved status the order now
	 * sits at plus success/failed counts.
	 *
	 * @param int    $order_id   Order row ID.
	 * @param string $new_status Target status (checked_in / checked_out / needs_cleaning).
	 * @param int    $user_id    Acting user.
	 * @return array{success:int, failed:int, errors:array<int,string>, status:string}
	 */
	public static function transition_order_all_nights( int $order_id, string $new_status, int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reservation_id, stall_unit, night_date FROM {$table} WHERE order_id = %d",
				$order_id
			),
			ARRAY_A
		) ?: array();

		$success = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $rows as $r ) {
			$result = self::transition( (int) $r['reservation_id'], $r['stall_unit'], $r['night_date'], $new_status, $user_id );
			if ( $result['success'] ) {
				$success++;
			} else {
				$failed++;
				$errors[] = $r['stall_unit'] . ': ' . $result['message'];
			}
		}

		// Resolve the order's effective status (MIN across rows = least-advanced).
		$effective = $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(status) FROM {$table} WHERE order_id = %d", $order_id )
		);

		return array(
			'success' => $success,
			'failed'  => $failed,
			'errors'  => $errors,
			'status'  => (string) ( $effective ?: 'occupied' ),
		);
	}

	/**
	 * Set EVERY status row for an order (all stalls, all nights) to an exact
	 * status, bypassing the forward-only transition guard so staff can correct
	 * mistakes (e.g. revert Checked Out → Checked In, or back to occupied). Drives
	 * the Daily Movement status dropdown where each pick is an explicit target,
	 * not a one-way step. Rows already at the target are no-ops.
	 *
	 * @param int    $order_id   Order row ID (wp_eem_stall_reservations.id).
	 * @param string $new_status Exact target status (occupied / checked_in / checked_out).
	 * @param int    $user_id    Acting user.
	 * @return array{success:int, failed:int, errors:array<int,string>, status:string}
	 */
	public static function set_order_status_all_nights( int $order_id, string $new_status, int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reservation_id, stall_unit, night_date FROM {$table} WHERE order_id = %d",
				$order_id
			),
			ARRAY_A
		) ?: array();

		$success = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $rows as $r ) {
			// is_override = true → skip the forward-only TRANSITIONS guard so the
			// status can move in any direction.
			$result = self::transition( (int) $r['reservation_id'], $r['stall_unit'], $r['night_date'], $new_status, $user_id, true );
			if ( $result['success'] ) {
				$success++;
			} else {
				$failed++;
				$errors[] = $r['stall_unit'] . ': ' . $result['message'];
			}
		}

		$effective = $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(status) FROM {$table} WHERE order_id = %d", $order_id )
		);

		return array(
			'success' => $success,
			'failed'  => $failed,
			'errors'  => $errors,
			'status'  => (string) ( $effective ?: 'occupied' ),
		);
	}

	/**
	 * Delete status rows when stall assignments are removed (e.g. order cancelled).
	 *
	 * Also deletes related log entries.
	 *
	 * @param int $order_id Order row ID.
	 * @return int Number of status rows deleted.
	 */
	public static function delete_for_order( int $order_id ): int {
		global $wpdb;
		$table     = $wpdb->prefix . 'eem_stall_status';
		$log_table = $wpdb->prefix . 'eem_stall_status_log';

		$status_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE order_id = %d",
				$order_id
			)
		);

		if ( ! empty( $status_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $status_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$log_table} WHERE stall_status_id IN ({$placeholders})",
					...$status_ids
				)
			);
		}

		return (int) $wpdb->delete( $table, array( 'order_id' => $order_id ), array( '%d' ) );
	}

	/**
	 * Delete all status rows for a clean→available transition.
	 *
	 * Removes the row entirely (absence = available). Only deletes rows
	 * currently in 'clean' status.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $stall_unit     Stall label.
	 * @param string $night_date     Y-m-d.
	 * @param int    $user_id        WP user ID.
	 * @return bool True if deleted.
	 */
	public static function release_to_available( int $reservation_id, string $stall_unit, string $night_date, int $user_id ): bool {
		global $wpdb;
		$table     = $wpdb->prefix . 'eem_stall_status';
		$log_table = $wpdb->prefix . 'eem_stall_status_log';

		$row = self::get( $reservation_id, $stall_unit, $night_date );
		if ( ! $row || 'clean' !== $row['status'] ) {
			return false;
		}

		$wpdb->insert(
			$log_table,
			array(
				'stall_status_id' => (int) $row['id'],
				'from_status'     => 'clean',
				'to_status'       => 'available',
				'performed_by'    => $user_id,
				'performed_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		$wpdb->delete( $table, array( 'id' => $row['id'] ), array( '%d' ) );
		return true;
	}

	/**
	 * Get the transition log for a specific stall status row.
	 *
	 * @param int $stall_status_id Row ID from wp_eem_stall_status.
	 * @return array[] Log entries ordered by performed_at ASC.
	 */
	public static function get_log( int $stall_status_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE stall_status_id = %d ORDER BY performed_at ASC",
				$stall_status_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get override counts for a reservation (for reporting).
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $override_reason Filter by reason (empty = all overrides).
	 * @return int Count of override log entries.
	 */
	public static function count_overrides( int $reservation_id, string $override_reason = '' ): int {
		global $wpdb;
		$status_table = $wpdb->prefix . 'eem_stall_status';
		$log_table    = $wpdb->prefix . 'eem_stall_status_log';

		if ( '' !== $override_reason ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$log_table} l
					 INNER JOIN {$status_table} s ON l.stall_status_id = s.id
					 WHERE s.reservation_id = %d AND l.override_reason = %s",
					$reservation_id,
					$override_reason
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} l
				 INNER JOIN {$status_table} s ON l.stall_status_id = s.id
				 WHERE s.reservation_id = %d AND l.override_reason IS NOT NULL AND l.override_reason != ''",
				$reservation_id
			)
		);
	}

	/**
	 * Generate a date range array (each night of stay, excluding departure).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return string[] Array of Y-m-d date strings.
	 */
	private static function date_range( string $from, string $to ): array {
		$dates   = array();
		$current = strtotime( $from );
		$end     = strtotime( $to );

		if ( false === $current || false === $end || $current >= $end ) {
			return $from === $to ? array( $from ) : array();
		}

		while ( $current < $end ) {
			$dates[] = gmdate( 'Y-m-d', $current );
			$current = strtotime( '+1 day', $current );
		}

		return $dates;
	}
}
