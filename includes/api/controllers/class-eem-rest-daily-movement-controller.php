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

		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-daily-movement-service.php';

		$date_param = (string) $request->get_param( 'date' );
		$view       = (string) $request->get_param( 'view' );

		if ( '' !== $date_param ) {
			$report = EEM_Daily_Movement_Service::build_date_report( $reservation_id, $date_param );
			return $this->respond( $this->format_api_report( $report ) );
		}

		if ( 'all' === $view ) {
			$all = EEM_Daily_Movement_Service::build_all_dates_report( $reservation_id );
			return $this->respond( array_map( array( $this, 'format_api_report' ), $all ) );
		}

		$report = EEM_Daily_Movement_Service::build_date_report( $reservation_id, wp_date( 'Y-m-d' ) );
		return $this->respond( $this->format_api_report( $report ) );
	}

	/**
	 * Format a service report into the API response shape.
	 *
	 * The service returns raw Y-m-d dates + a date_display field. The API
	 * historically returned 'date' as formatted display text, so we preserve
	 * that contract.
	 *
	 * @param array $report Service report array.
	 * @return array
	 */
	private function format_api_report( array $report ): array {
		$arriving = array_map( function ( $row ) {
			return array(
				'order_key'            => $row['order_key'],
				'customer_name'        => $row['customer_name'],
				'stall_numbers'        => $row['stall_numbers'],
				'date'                 => EEM_Daily_Movement_Service::format_display_date( $row['arrival_date'] ),
				'shavings'             => $row['shavings'],
				'check_in_status'      => $row['check_in_status'],
				'special_instructions' => $row['special_instructions'],
			);
		}, $report['arriving'] );

		$departing = array_map( function ( $row ) {
			return array(
				'order_key'            => $row['order_key'],
				'customer_name'        => $row['customer_name'],
				'stall_numbers'        => $row['stall_numbers'],
				'date'                 => EEM_Daily_Movement_Service::format_display_date( $row['departure_date'] ),
				'shavings'             => $row['shavings'],
				'check_in_status'      => $row['check_in_status'],
				'special_instructions' => $row['special_instructions'],
			);
		}, $report['departing'] );

		return array(
			'date'      => $report['date_display'],
			'summary'   => $report['summary'],
			'arriving'  => $arriving,
			'departing' => $departing,
		);
	}
}
