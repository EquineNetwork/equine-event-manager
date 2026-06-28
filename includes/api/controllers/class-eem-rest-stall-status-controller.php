<?php
/**
 * Stall Check-In/Out REST controller.
 *
 * Provides lifecycle transition endpoints for on-the-ground facility staff
 * and admin overrides. Also exposes read-only status and count endpoints
 * for the dashboard and Daily Movement report.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for stall check-in/out lifecycle.
 *
 * Routes:
 *   POST /eem/v1/stalls/check-in   — facility: transition to checked_in
 *   POST /eem/v1/stalls/check-out  — facility: transition to checked_out
 *   POST /eem/v1/stalls/clean      — facility: transition to clean
 *   POST /eem/v1/stalls/status     — admin: arbitrary transition with override_reason
 *   GET  /eem/v1/stalls/status     — staff: get_for_date
 *   GET  /eem/v1/stalls/counts     — staff: get_counts (dashboard + Daily Movement)
 *   POST /eem/v1/orders/{order_key}/check-in  — facility: bulk check-in by order
 *   POST /eem/v1/orders/{order_key}/check-out — facility: bulk check-out by order
 */
class EEM_REST_Stall_Status_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$stall_args = array(
			'reservation_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'stall_unit'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'night_date'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'note'           => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/stalls/check-in',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_in' ),
				'permission_callback' => array( static::class, 'require_facility' ),
				'args'                => $stall_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stalls/check-out',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_out' ),
				'permission_callback' => array( static::class, 'require_facility' ),
				'args'                => $stall_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stalls/clean',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clean' ),
				'permission_callback' => array( static::class, 'require_facility' ),
				'args'                => $stall_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stalls/status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'override_status' ),
					'permission_callback' => array( static::class, 'require_admin' ),
					'args'                => array_merge( $stall_args, array(
						'status'          => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'override_reason' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( static::class, 'require_staff' ),
					'args'                => array(
						'reservation_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'night_date'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stalls/counts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_counts' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => array(
					'reservation_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'night_date'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<order_key>[a-f0-9]{32})/check-in',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'order_check_in' ),
				'permission_callback' => array( static::class, 'require_facility' ),
				'args'                => array(
					'night_date' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'note'       => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<order_key>[a-f0-9]{32})/check-out',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'order_check_out' ),
				'permission_callback' => array( static::class, 'require_facility' ),
				'args'                => array(
					'night_date' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'note'       => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * POST /stalls/check-in — transition a stall to checked_in.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function check_in( WP_REST_Request $request ): WP_REST_Response {
		return $this->do_transition( $request, 'checked_in' );
	}

	/**
	 * POST /stalls/check-out — transition a stall to checked_out.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function check_out( WP_REST_Request $request ): WP_REST_Response {
		return $this->do_transition( $request, 'checked_out' );
	}

	/**
	 * POST /stalls/clean — transition a stall to clean.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function clean( WP_REST_Request $request ): WP_REST_Response {
		return $this->do_transition( $request, 'clean' );
	}

	/**
	 * POST /stalls/status — admin override to any valid status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function override_status( WP_REST_Request $request ): WP_REST_Response {
		$reservation_id  = (int) $request->get_param( 'reservation_id' );
		$stall_unit      = (string) $request->get_param( 'stall_unit' );
		$night_date      = (string) $request->get_param( 'night_date' );
		$new_status      = (string) $request->get_param( 'status' );
		$override_reason = (string) $request->get_param( 'override_reason' );
		$note            = (string) $request->get_param( 'note' );

		if ( ! $this->validate_date( $night_date ) ) {
			return $this->respond_error( 'invalid_date', __( 'night_date must be Y-m-d format.', 'equine-event-manager' ), 422 );
		}

		if ( ! $this->validate_reservation( $reservation_id ) ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$result = EEM_Stall_Status_Repo::transition(
			$reservation_id,
			$stall_unit,
			$night_date,
			$new_status,
			get_current_user_id(),
			true,
			$override_reason,
			$note
		);

		if ( ! $result['success'] ) {
			return $this->respond_error( 'transition_failed', $result['message'], 422 );
		}

		return $this->respond( $this->shape_status_row( $result['row'] ) );
	}

	/**
	 * GET /stalls/status — all statuses for a reservation on a date.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$reservation_id = (int) $request->get_param( 'reservation_id' );
		$night_date     = (string) $request->get_param( 'night_date' );

		if ( ! $this->validate_date( $night_date ) ) {
			return $this->respond_error( 'invalid_date', __( 'night_date must be Y-m-d format.', 'equine-event-manager' ), 422 );
		}

		if ( ! $this->validate_reservation( $reservation_id ) ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$rows = EEM_Stall_Status_Repo::get_for_date( $reservation_id, $night_date );

		return $this->respond( array_map( array( $this, 'shape_status_row' ), $rows ) );
	}

	/**
	 * GET /stalls/counts — status counts for dashboard and reports.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_counts( WP_REST_Request $request ): WP_REST_Response {
		$reservation_id = (int) $request->get_param( 'reservation_id' );
		$night_date     = (string) $request->get_param( 'night_date' );

		if ( ! $this->validate_date( $night_date ) ) {
			return $this->respond_error( 'invalid_date', __( 'night_date must be Y-m-d format.', 'equine-event-manager' ), 422 );
		}

		if ( ! $this->validate_reservation( $reservation_id ) ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$counts = EEM_Stall_Status_Repo::get_counts( $reservation_id, $night_date );

		return $this->respond( $counts );
	}

	/**
	 * POST /orders/{order_key}/check-in — bulk check-in all stalls for an order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function order_check_in( WP_REST_Request $request ): WP_REST_Response {
		return $this->do_order_transition( $request, 'checked_in' );
	}

	/**
	 * POST /orders/{order_key}/check-out — bulk check-out all stalls for an order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function order_check_out( WP_REST_Request $request ): WP_REST_Response {
		return $this->do_order_transition( $request, 'checked_out' );
	}

	/**
	 * Execute a standard (non-override) transition.
	 *
	 * @param WP_REST_Request $request    Request object.
	 * @param string          $new_status Target status.
	 * @return WP_REST_Response
	 */
	private function do_transition( WP_REST_Request $request, string $new_status ): WP_REST_Response {
		$reservation_id = (int) $request->get_param( 'reservation_id' );
		$stall_unit     = (string) $request->get_param( 'stall_unit' );
		$night_date     = (string) $request->get_param( 'night_date' );
		$note           = (string) $request->get_param( 'note' );

		if ( ! $this->validate_date( $night_date ) ) {
			return $this->respond_error( 'invalid_date', __( 'night_date must be Y-m-d format.', 'equine-event-manager' ), 422 );
		}

		if ( ! $this->validate_reservation( $reservation_id ) ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$result = EEM_Stall_Status_Repo::transition(
			$reservation_id,
			$stall_unit,
			$night_date,
			$new_status,
			get_current_user_id(),
			false,
			'',
			$note
		);

		if ( ! $result['success'] ) {
			return $this->respond_error( 'transition_failed', $result['message'], 422 );
		}

		return $this->respond( $this->shape_status_row( $result['row'] ) );
	}

	/**
	 * Execute a bulk order-level transition.
	 *
	 * @param WP_REST_Request $request    Request object.
	 * @param string          $new_status Target status.
	 * @return WP_REST_Response
	 */
	private function do_order_transition( WP_REST_Request $request, string $new_status ): WP_REST_Response {
		$order_key  = (string) $request->get_param( 'order_key' );
		$night_date = (string) $request->get_param( 'night_date' );
		$note       = (string) $request->get_param( 'note' );

		if ( ! $this->validate_date( $night_date ) ) {
			return $this->respond_error( 'invalid_date', __( 'night_date must be Y-m-d format.', 'equine-event-manager' ), 422 );
		}

		$order_id = $this->resolve_order_id( $order_key );
		if ( ! $order_id ) {
			return $this->respond_error( 'order_not_found', __( 'Order not found.', 'equine-event-manager' ), 404 );
		}

		$result = EEM_Stall_Status_Repo::transition_order(
			$order_id,
			$night_date,
			$new_status,
			get_current_user_id(),
			false,
			'',
			$note
		);

		return $this->respond( array(
			'transitioned' => $result['success'],
			'failed'       => $result['failed'],
			'errors'       => $result['errors'],
		) );
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}

	/**
	 * Validate that a reservation exists and is published.
	 *
	 * @param int $reservation_id Post ID.
	 * @return bool
	 */
	private function validate_reservation( int $reservation_id ): bool {
		return 'publish' === get_post_status( $reservation_id ) && 'en_reservation' === get_post_type( $reservation_id );
	}

	/**
	 * Resolve an order_key (hex token) to the order row ID.
	 *
	 * @param string $order_key 32-char hex key.
	 * @return int|null Order row ID or null if not found.
	 */
	private function resolve_order_id( string $order_key ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_reservations';

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE notes LIKE %s AND trashed_at IS NULL LIMIT 1",
				'%Submission token: ' . $wpdb->esc_like( $order_key ) . '%'
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Shape a raw status row for the API response.
	 *
	 * @param array $row Raw row from wp_eem_stall_status.
	 * @return array
	 */
	private function shape_status_row( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'reservation_id' => (int) $row['reservation_id'],
			'order_id'       => (int) $row['order_id'],
			'stall_unit'     => (string) $row['stall_unit'],
			'night_date'     => (string) $row['night_date'],
			'status'         => (string) $row['status'],
			'updated_by'     => (int) $row['updated_by'],
			'updated_at'     => (string) $row['updated_at'],
		);
	}
}
