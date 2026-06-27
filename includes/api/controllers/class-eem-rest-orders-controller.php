<?php
/**
 * Orders REST controller.
 *
 * Wraps EEM_Orders_Repository to expose order CRUD over the WP REST API.
 * Proof-of-concept endpoint — first entity in the REST layer.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for orders.
 *
 * Routes:
 *   GET  /eem/v1/orders                    — paginated list
 *   GET  /eem/v1/orders/{order_key}        — single order
 *   PUT  /eem/v1/orders/{order_key}        — update payment details
 *   POST /eem/v1/orders/{order_key}/cancel — cancel order
 */
class EEM_REST_Orders_Controller extends EEM_REST_Controller {

	/**
	 * Orders repository instance.
	 *
	 * @var EEM_Orders_Repository
	 */
	private EEM_Orders_Repository $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo = new EEM_Orders_Repository();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_orders' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => $this->get_list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<order_key>[a-f0-9]{32})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order' ),
					'permission_callback' => array( static::class, 'require_staff' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_order' ),
					'permission_callback' => array( static::class, 'require_admin' ),
					'args'                => $this->get_update_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<order_key>[a-f0-9]{32})/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_order' ),
				'permission_callback' => array( static::class, 'require_admin' ),
				'args'                => array(
					'reason' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /orders — paginated list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$page           = (int) $request->get_param( 'page' );
		$per_page       = (int) $request->get_param( 'per_page' );
		$orderby        = $request->get_param( 'orderby' );
		$order          = $request->get_param( 'order' );
		$search         = $request->get_param( 'search' );
		$event          = $request->get_param( 'event' );
		$status         = $request->get_param( 'status' );
		$reservation_id = (int) $request->get_param( 'reservation_id' );

		$all_orders = $this->repo->get_orders( $event, $orderby, $order, $search );

		if ( '' !== $status ) {
			$all_orders = array_values(
				array_filter(
					$all_orders,
					static function ( $o ) use ( $status ) {
						return $o['status_slug'] === $status;
					}
				)
			);
		}

		if ( $reservation_id > 0 ) {
			$all_orders = array_values(
				array_filter(
					$all_orders,
					static function ( $o ) use ( $reservation_id ) {
						return (int) $o['reservation_id'] === $reservation_id;
					}
				)
			);
		}

		$total  = count( $all_orders );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $all_orders, $offset, $per_page );

		return $this->respond_paginated(
			array_map( array( $this, 'shape_order' ), $items ),
			$total,
			$page,
			$per_page
		);
	}

	/**
	 * GET /orders/{order_key} — single order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_order( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->repo->get_order( $request->get_param( 'order_key' ) );

		if ( null === $order ) {
			return $this->respond_error( 'order_not_found', __( 'Order not found.', 'equine-event-manager' ), 404 );
		}

		return $this->respond( $this->shape_order( $order ) );
	}

	/**
	 * PUT /orders/{order_key} — update payment details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_order( WP_REST_Request $request ): WP_REST_Response {
		$order_key = $request->get_param( 'order_key' );
		$order     = $this->repo->get_order( $order_key );

		if ( null === $order ) {
			return $this->respond_error( 'order_not_found', __( 'Order not found.', 'equine-event-manager' ), 404 );
		}

		$payment_status = $request->get_param( 'payment_status' );
		$transaction_id = $request->get_param( 'transaction_id' );
		$payment_gateway = $request->get_param( 'payment_gateway' );

		$this->repo->update_order_payment_details(
			$order_key,
			$payment_status,
			$transaction_id,
			$payment_gateway
		);

		$updated = $this->repo->get_order( $order_key );

		return $this->respond( $this->shape_order( $updated ) );
	}

	/**
	 * POST /orders/{order_key}/cancel — cancel order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function cancel_order( WP_REST_Request $request ): WP_REST_Response {
		$order_key = $request->get_param( 'order_key' );
		$order     = $this->repo->get_order( $order_key );

		if ( null === $order ) {
			return $this->respond_error( 'order_not_found', __( 'Order not found.', 'equine-event-manager' ), 404 );
		}

		$result = $this->repo->cancel_order( $order_key, $request->get_param( 'reason' ) );

		if ( is_wp_error( $result ) ) {
			return $this->respond_error( $result->get_error_code(), $result->get_error_message(), 422 );
		}

		$cancelled = $this->repo->get_order( $order_key );

		return $this->respond( $this->shape_order( $cancelled ) );
	}

	/**
	 * Shape a grouped-order array for API output.
	 *
	 * Strips internal fields (notes, type_labels) and formats monetary
	 * values as floats with 2 decimal precision.
	 *
	 * @param array $order Raw grouped order from the repository.
	 * @return array API-safe order representation.
	 */
	private function shape_order( array $order ): array {
		return array(
			'order_key'        => $order['order_key'],
			'order_number'     => EEM_Formatter::format_order_number( $order['order_number'] ),
			'event_name'       => $order['event_name'],
			'event_dates'      => $order['event_dates'],
			'customer_name'    => $order['customer_name'],
			'email'            => $order['email'],
			'phone'            => $order['phone'],
			'type'             => $order['type'],
			'status'           => $order['status_slug'],
			'status_label'     => $order['status_label'],
			'payment_gateway'  => $order['payment_gateway'],
			'invoice_type'     => $order['invoice_type'],
			'stall_quantity'   => (int) $order['stall_quantity'],
			'rv_quantity'      => (int) $order['rv_quantity'],
			'subtotal'         => round( (float) $order['stall_subtotal'] + (float) $order['rv_subtotal'], 2 ),
			'fees'             => round( (float) $order['fees'], 2 ),
			'tax'              => round( (float) $order['tax'], 2 ),
			'total'            => round( (float) $order['total'], 2 ),
			'arrival_date'     => $order['arrival_date'],
			'departure_date'   => $order['departure_date'],
			'reservation_id'   => (int) $order['reservation_id'],
			'reservation_title' => $order['reservation_title'],
			'can_refund'       => (bool) $order['can_refund'],
			'created_at'       => $order['created_at'],
			'trashed'          => ! empty( $order['trashed'] ),
			'components'       => array_map(
				static function ( $c ) {
					return array(
						'type'                  => $c['table'],
						'row_id'                => (int) $c['row_id'],
						'payment_status'        => $c['payment_status'],
						'payment_gateway'       => $c['payment_gateway'],
						'transaction_id'        => $c['transaction_id'],
						'refund_transaction_id' => $c['refund_transaction_id'],
						'refunded_amount'       => round( (float) $c['refunded_amount'], 2 ),
						'total'                 => round( (float) $c['total'], 2 ),
					);
				},
				$order['components']
			),
		);
	}

	/**
	 * Argument definitions for the list endpoint.
	 *
	 * @return array
	 */
	private function get_list_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'orderby'  => array(
				'type'              => 'string',
				'default'           => 'date',
				'enum'              => array( 'date', 'customer', 'total', 'status' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'event'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'         => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'reservation_id' => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Argument definitions for the update endpoint.
	 *
	 * @return array
	 */
	private function get_update_args(): array {
		return array(
			'payment_status'  => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'transaction_id'  => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'payment_gateway' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
