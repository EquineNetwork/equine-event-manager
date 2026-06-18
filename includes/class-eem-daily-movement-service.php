<?php
/**
 * Daily Movement shared service.
 *
 * Provides movement-report data (arrivals, departures, status counts) for a
 * reservation on a given date. Consumed by both the REST controller and the
 * admin page — single source of truth for movement queries.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Daily_Movement_Service {

	/**
	 * Build the movement report for a single date.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $date           Date in Y-m-d format.
	 * @return array{date: string, summary: array, arriving: array, departing: array}
	 */
	public static function build_date_report( int $reservation_id, string $date ): array {
		global $wpdb;
		$table        = $wpdb->prefix . 'en_stall_reservations';
		$status_table = $wpdb->prefix . 'eem_stall_status';

		$status_subquery = "(SELECT order_id, MIN(status) AS status FROM {$status_table} WHERE night_date = %s GROUP BY order_id)";

		$arriving = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sr.id, sr.customer_name, sr.arrival_date, sr.departure_date, sr.notes,
				        sr.required_shavings_qty, sr.additional_shavings_qty,
				        ss.status AS live_status
				 FROM {$table} sr
				 LEFT JOIN {$status_subquery} ss ON ss.order_id = sr.id
				 WHERE sr.reservation_id = %d AND sr.arrival_date = %s AND sr.trashed_at IS NULL
				 ORDER BY sr.customer_name ASC",
				$date,
				$reservation_id,
				$date
			),
			ARRAY_A
		);

		$departing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sr.id, sr.customer_name, sr.arrival_date, sr.departure_date, sr.notes,
				        sr.required_shavings_qty, sr.additional_shavings_qty,
				        ss.status AS live_status
				 FROM {$table} sr
				 LEFT JOIN {$status_subquery} ss ON ss.order_id = sr.id
				 WHERE sr.reservation_id = %d AND sr.departure_date = %s AND sr.trashed_at IS NULL
				 ORDER BY sr.customer_name ASC",
				$date,
				$reservation_id,
				$date
			),
			ARRAY_A
		);

		// Order-level effective status (MIN across all the order's nights) — matches
		// the whole-stay check-in/out model where one click moves the entire order.
		$order_status_sub = "(SELECT order_id, MIN(status) AS status FROM {$status_table} GROUP BY order_id)";

		// Occupying the date (arrival <= date <= departure), not yet checked in.
		$not_checked_in_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} sr
				 LEFT JOIN {$order_status_sub} os ON os.order_id = sr.id
				 WHERE sr.reservation_id = %d
				   AND sr.arrival_date <= %s
				   AND sr.departure_date >= %s
				   AND sr.trashed_at IS NULL
				   AND (os.status IS NULL OR os.status = 'occupied')",
				$reservation_id,
				$date,
				$date
			)
		);

		// Occupying the date and checked in (arrived, not yet departed).
		$checked_in_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} sr
				 LEFT JOIN {$order_status_sub} os ON os.order_id = sr.id
				 WHERE sr.reservation_id = %d
				   AND sr.arrival_date <= %s
				   AND sr.departure_date >= %s
				   AND sr.trashed_at IS NULL
				   AND os.status = 'checked_in'",
				$reservation_id,
				$date,
				$date
			)
		);

		// Departing on the date and already checked out (departed).
		$departed_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} sr
				 LEFT JOIN {$order_status_sub} os ON os.order_id = sr.id
				 WHERE sr.reservation_id = %d
				   AND sr.departure_date = %s
				   AND sr.trashed_at IS NULL
				   AND os.status = 'checked_out'",
				$reservation_id,
				$date
			)
		);

		$shaped_arriving  = array_map( array( __CLASS__, 'shape_row' ), $arriving );
		$shaped_departing = array_map( array( __CLASS__, 'shape_row' ), $departing );

		$shavings_total = 0;
		foreach ( array_merge( $shaped_arriving, $shaped_departing ) as $r ) {
			$shavings_total += $r['shavings'];
		}

		return array(
			'date'      => $date,
			'date_display' => self::format_display_date( $date ),
			'summary'   => array(
				'arriving'           => count( $arriving ),
				'departing'          => count( $departing ),
				'checked_in'         => $checked_in_count,
				'not_yet_checked_in' => $not_checked_in_count,
				'departed'           => $departed_count,
				'shavings_total'     => $shavings_total,
			),
			'arriving'  => $shaped_arriving,
			'departing' => $shaped_departing,
		);
	}

	/**
	 * Build reports for all dates that have any movement.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array[]
	 */
	public static function build_all_dates_report( int $reservation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'en_stall_reservations';

		$dates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT d FROM (
				   SELECT arrival_date AS d FROM {$table} WHERE reservation_id = %d AND trashed_at IS NULL
				   UNION
				   SELECT departure_date AS d FROM {$table} WHERE reservation_id = %d AND trashed_at IS NULL
				 ) AS all_dates WHERE d > '0000-00-00' ORDER BY d ASC",
				$reservation_id,
				$reservation_id
			)
		);

		$reports = array();
		foreach ( $dates as $date ) {
			if ( ! empty( $date ) ) {
				$reports[] = self::build_date_report( $reservation_id, $date );
			}
		}

		return $reports;
	}

	/**
	 * Shape a raw DB row into a normalized movement row.
	 *
	 * @param array $row Raw row from wp_en_stall_reservations.
	 * @return array
	 */
	private static function shape_row( array $row ): array {
		$notes       = isset( $row['notes'] ) ? (string) $row['notes'] : '';
		$live_status = ! empty( $row['live_status'] ) ? (string) $row['live_status'] : 'not_checked_in';

		return array(
			'order_key'            => self::extract_note_value( $notes, 'Submission token' ),
			// Component row id (wp_en_stall_reservations.id) — the key wp_eem_stall_status
			// uses, so the check-in/out chips can transition this order's whole stay.
			'status_order_id'      => (int) ( $row['id'] ?? 0 ),
			'customer_name'        => (string) ( $row['customer_name'] ?? '' ),
			'stall_numbers'        => self::extract_stall_numbers( $notes ),
			'arrival_date'         => (string) ( $row['arrival_date'] ?? '' ),
			'departure_date'       => (string) ( $row['departure_date'] ?? '' ),
			'shavings'             => (int) ( $row['required_shavings_qty'] ?? 0 ) + (int) ( $row['additional_shavings_qty'] ?? 0 ),
			'check_in_status'      => $live_status,
			'special_instructions' => self::extract_special_instructions( $notes ),
		);
	}

	/**
	 * Extract assigned stall numbers from the notes field.
	 *
	 * @param string $notes Raw notes text.
	 * @return string[]
	 */
	private static function extract_stall_numbers( string $notes ): array {
		$value = self::extract_note_value( $notes, 'Assigned Stall Units' );
		if ( '' === $value ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	/**
	 * Extract a key-value note line.
	 *
	 * @param string $notes Raw notes text.
	 * @param string $label Label to search for.
	 * @return string
	 */
	private static function extract_note_value( string $notes, string $label ): string {
		if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $notes, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Extract free-text lines from notes (special instructions / comments).
	 *
	 * @param string $notes Raw notes text.
	 * @return string
	 */
	private static function extract_special_instructions( string $notes ): string {
		$known_labels = array(
			'Billing Name', 'Billing Address', 'Invoice Type', 'Invoice Token',
			'Invoice Status', 'Invoice Paid At', 'Manual Payment Method',
			'Manual Payment Recorded At', 'Reservation setup ID', 'Submission token',
			'Assigned Stall Units', 'Assigned RV Lots', 'Assigned RV Units',
			'Stall Night Map', 'RV Lot Night Map', 'Tack Stalls', 'RV Lot',
			'Group Name', 'Order Status', 'Cancelled At', 'Cancellation Reason',
			'Roper Comments', 'Shavings', 'Special Instructions',
		);

		$lines      = preg_split( '/\r?\n/', $notes );
		$free_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$is_structured = false;
			foreach ( $known_labels as $label ) {
				if ( 0 === strpos( $line, $label . ':' ) ) {
					$is_structured = true;
					break;
				}
			}
			if ( $is_structured ) {
				continue;
			}
			if ( preg_match( '/^[^:]+,\s+[A-Z][a-z]/', $line ) ) {
				continue;
			}
			if ( preg_match( '/^(United States|Canada|Mexico|United Kingdom)\s*$/i', $line ) ) {
				continue;
			}
			$free_lines[] = $line;
		}

		$si = self::extract_note_value( $notes, 'Special Instructions' );
		if ( '' !== $si ) {
			array_unshift( $free_lines, $si );
		}

		return implode( ' | ', $free_lines );
	}

	/**
	 * Get all distinct dates (arrival + departure) for a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string[] Dates in Y-m-d format, sorted ascending.
	 */
	public static function get_available_dates( int $reservation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'en_stall_reservations';

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT d FROM (
				   SELECT arrival_date AS d FROM {$table} WHERE reservation_id = %d AND trashed_at IS NULL
				   UNION
				   SELECT departure_date AS d FROM {$table} WHERE reservation_id = %d AND trashed_at IS NULL
				 ) AS all_dates WHERE d IS NOT NULL AND d > '0000-00-00' ORDER BY d ASC",
				$reservation_id,
				$reservation_id
			)
		);
	}

	/**
	 * Map each reservation to its booking date window (earliest arrival → latest
	 * departure across the stall + RV tables). Source-agnostic — derived from the
	 * actual bookings rather than per-source event meta. Reservations with no
	 * bookings are absent from the map.
	 *
	 * @return array<int, array{start:string, end:string}> Keyed by reservation_id.
	 */
	public static function get_reservation_windows(): array {
		global $wpdb;
		$stall = $wpdb->prefix . 'en_stall_reservations';
		$rv    = $wpdb->prefix . 'en_rv_reservations';

		$rows = $wpdb->get_results(
			"SELECT reservation_id, MIN(arrival_date) AS start_date, MAX(departure_date) AS end_date FROM (
			   SELECT reservation_id, arrival_date, departure_date FROM {$stall} WHERE reservation_id > 0 AND trashed_at IS NULL
			   UNION ALL
			   SELECT reservation_id, arrival_date, departure_date FROM {$rv} WHERE reservation_id > 0 AND trashed_at IS NULL
			 ) AS combined
			 WHERE arrival_date > '0000-00-00'
			 GROUP BY reservation_id",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names internal, no user input.

		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r['reservation_id'] ] = array(
				'start' => (string) $r['start_date'],
				'end'   => (string) $r['end_date'],
			);
		}
		return $map;
	}

	/**
	 * Whether a reservation is "active" today within a ±N-day buffer around its
	 * booking window — i.e. today is in [start − $buffer_days, end + $buffer_days].
	 *
	 * @param array{start:string, end:string}|null $window     Window from get_reservation_windows().
	 * @param string                               $today      Today in Y-m-d.
	 * @param int                                  $buffer_days Days of slack on each side.
	 * @return bool
	 */
	public static function is_reservation_active( ?array $window, string $today, int $buffer_days = 1 ): bool {
		if ( empty( $window ) || empty( $window['start'] ) || empty( $window['end'] ) ) {
			return false;
		}
		$today_ts = strtotime( $today );
		$start_ts = strtotime( $window['start'] . ' -' . $buffer_days . ' days' );
		$end_ts   = strtotime( $window['end'] . ' +' . $buffer_days . ' days' );
		if ( false === $today_ts || false === $start_ts || false === $end_ts ) {
			return false;
		}
		return $today_ts >= $start_ts && $today_ts <= $end_ts;
	}

	/**
	 * Format a Y-m-d date string for display.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string
	 */
	public static function format_display_date( string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}
		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return $date;
		}
		return date_i18n( 'D, M j', $timestamp );
	}
}
