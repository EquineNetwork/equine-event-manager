<?php
/**
 * Daily Movement Report REST controller.
 *
 * Provides a facility-focused view of who is arriving, departing, and present
 * for a given reservation on a given date. Designed for on-the-ground staff
 * at live events.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoint for the Daily Movement Report.
 *
 * Routes:
 *   GET /eem/v1/reports/daily-movement — arrivals + departures for a reservation/date (staff+)
 */
class EEM_REST_Daily_Movement_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reports/daily-movement',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => array(
					'reservation_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'date'           => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'view'           => array(
						'type'              => 'string',
						'default'           => '',
						'enum'              => array( '', 'today', 'all' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * GET /reports/daily-movement — movement report for a reservation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_report( WP_REST_Request $request ): WP_REST_Response {
		$reservation_id = (int) $request->get_param( 'reservation_id' );

		if ( 'publish' !== get_post_status( $reservation_id ) || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$date_param = (string) $request->get_param( 'date' );
		$view       = (string) $request->get_param( 'view' );

		if ( '' !== $date_param ) {
			$report = $this->build_date_report( $reservation_id, $date_param );
			return $this->respond( $report );
		}

		if ( 'all' === $view ) {
			return $this->respond( $this->build_all_dates_report( $reservation_id ) );
		}

		$report = $this->build_date_report( $reservation_id, wp_date( 'Y-m-d' ) );
		return $this->respond( $report );
	}

	/**
	 * Build the movement report for a single date.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $date           Date in Y-m-d format.
	 * @return array
	 */
	private function build_date_report( int $reservation_id, string $date ): array {
		global $wpdb;
		$table        = $wpdb->prefix . 'en_stall_reservations';
		$status_table = $wpdb->prefix . 'eem_stall_status';

		$arriving = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sr.id, sr.customer_name, sr.arrival_date, sr.departure_date, sr.notes,
				        sr.required_shavings_qty, sr.additional_shavings_qty,
				        ss.status AS live_status
				 FROM {$table} sr
				 LEFT JOIN (
				   SELECT order_id, MIN(status) AS status
				   FROM {$status_table}
				   WHERE night_date = %s
				   GROUP BY order_id
				 ) ss ON ss.order_id = sr.id
				 WHERE sr.reservation_id = %d AND sr.arrival_date = %s AND sr.trashed_at IS NULL",
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
				 LEFT JOIN (
				   SELECT order_id, MIN(status) AS status
				   FROM {$status_table}
				   WHERE night_date = %s
				   GROUP BY order_id
				 ) ss ON ss.order_id = sr.id
				 WHERE sr.reservation_id = %d AND sr.departure_date = %s AND sr.trashed_at IS NULL",
				$date,
				$reservation_id,
				$date
			),
			ARRAY_A
		);

		$not_checked_in_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} sr
				 LEFT JOIN {$status_table} ss
				   ON ss.order_id = sr.id AND ss.night_date = %s
				 WHERE sr.reservation_id = %d
				   AND sr.arrival_date <= %s
				   AND sr.departure_date >= %s
				   AND sr.trashed_at IS NULL
				   AND (ss.status IS NULL OR ss.status = 'occupied')",
				$date,
				$reservation_id,
				$date,
				$date
			)
		);

		$arriving_rows  = array_map( array( $this, 'shape_movement_row' ), $arriving );
		$departing_rows = array_map( array( $this, 'shape_movement_row' ), $departing );

		return array(
			'date'      => $this->format_display_date( $date ),
			'summary'   => array(
				'arriving'           => count( $arriving_rows ),
				'departing'          => count( $departing_rows ),
				'not_yet_checked_in' => $not_checked_in_count,
			),
			'arriving'  => $arriving_rows,
			'departing' => $departing_rows,
		);
	}

	/**
	 * Build the report for all dates that have any movement.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array
	 */
	private function build_all_dates_report( int $reservation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'en_stall_reservations';

		$dates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT d FROM (
				   SELECT arrival_date AS d FROM {$table} WHERE reservation_id = %d AND trashed_at IS NULL
				   UNION
				   SELECT departure_date AS d FROM {$table} WHERE reservation_id = %d AND trashed_at IS NULL
				 ) AS all_dates ORDER BY d ASC",
				$reservation_id,
				$reservation_id
			)
		);

		$reports = array();
		foreach ( $dates as $date ) {
			if ( ! empty( $date ) ) {
				$reports[] = $this->build_date_report( $reservation_id, $date );
			}
		}

		return $reports;
	}

	/**
	 * Shape a raw DB row into the API response format.
	 *
	 * @param array $row Raw row from wp_en_stall_reservations.
	 * @return array
	 */
	private function shape_movement_row( array $row ): array {
		$notes = isset( $row['notes'] ) ? (string) $row['notes'] : '';

		$live_status = ! empty( $row['live_status'] ) ? (string) $row['live_status'] : 'not_checked_in';

		return array(
			'order_key'            => $this->extract_note_value( $notes, 'Submission token' ),
			'customer_name'        => (string) ( $row['customer_name'] ?? '' ),
			'stall_numbers'        => $this->extract_stall_numbers( $notes ),
			'date'                 => $this->format_display_date( (string) ( $row['arrival_date'] ?? '' ) ),
			'shavings'             => (int) ( $row['required_shavings_qty'] ?? 0 ) + (int) ( $row['additional_shavings_qty'] ?? 0 ),
			'check_in_status'      => $live_status,
			'special_instructions' => $this->extract_special_instructions( $notes ),
		);
	}

	/**
	 * Extract assigned stall numbers from the notes field.
	 *
	 * @param string $notes Raw notes text.
	 * @return string[]
	 */
	private function extract_stall_numbers( string $notes ): array {
		$value = $this->extract_note_value( $notes, 'Assigned Stall Units' );
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
	private function extract_note_value( string $notes, string $label ): string {
		if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $notes, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Extract free-text lines from notes that aren't structured key-value pairs.
	 *
	 * Structured lines follow the pattern "Label: value" and are used for internal
	 * data (billing, tokens, assignments). Free-text lines are customer-facing
	 * special instructions or imported comments (e.g. Roper Comments from GH).
	 *
	 * @param string $notes Raw notes text.
	 * @return string
	 */
	private function extract_special_instructions( string $notes ): string {
		$known_labels = array(
			'Billing Name',
			'Billing Address',
			'Invoice Type',
			'Invoice Token',
			'Invoice Status',
			'Invoice Paid At',
			'Manual Payment Method',
			'Manual Payment Recorded At',
			'Reservation setup ID',
			'Submission token',
			'Assigned Stall Units',
			'Assigned RV Lots',
			'Assigned RV Units',
			'Stall Night Map',
			'RV Lot Night Map',
			'Tack Stalls',
			'RV Lot',
			'Group Name',
			'Order Status',
			'Cancelled At',
			'Cancellation Reason',
			'Roper Comments',
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

		return implode( ' | ', $free_lines );
	}

	/**
	 * Format a Y-m-d date string as "Mon, Jul 15" for display.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string
	 */
	private function format_display_date( string $date ): string {
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
