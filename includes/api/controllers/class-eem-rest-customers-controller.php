<?php
/**
 * Customers REST controller.
 *
 * Authenticated endpoints for customer list, profile, order history, and notes.
 * Customers are email-derived aggregates (no numeric ID); routes use the MD5
 * email hash from EEM_Customer_Profile_Repo::email_key().
 *
 * PII redaction: billing details (address, phone) are stripped unless the
 * requesting user holds the eem_view_reservations capability.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for Customers.
 *
 * Routes:
 *   GET  /eem/v1/customers                       — paginated list (staff+)
 *   GET  /eem/v1/customers/{hash}                 — profile detail (staff+)
 *   GET  /eem/v1/customers/{hash}/orders           — order history (staff+)
 *   PUT  /eem/v1/customers/{hash}/note             — save admin note (staff+)
 */
class EEM_REST_Customers_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$hash_pattern = '(?P<hash>[a-f0-9]{32})';

		register_rest_route(
			self::NAMESPACE,
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_customers' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => $this->get_list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/customers/' . $hash_pattern,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer' ),
				'permission_callback' => array( static::class, 'require_staff' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/customers/' . $hash_pattern . '/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer_orders' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => array(
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
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/customers/' . $hash_pattern . '/note',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'save_note' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => array(
					'note' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * GET /customers — paginated customer list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_customers( WP_REST_Request $request ): WP_REST_Response {
		$repo   = new EEM_Customer_Profile_Repo();
		$result = $repo->get_customer_list( array(
			'search'   => (string) $request->get_param( 'search' ),
			'orderby'  => (string) $request->get_param( 'orderby' ),
			'order'    => (string) $request->get_param( 'order' ),
			'paged'    => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
		) );

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'email_hash'    => EEM_Customer_Profile_Repo::email_key( $row['email'] ),
					'email'         => $row['email'],
					'name'          => $row['name'],
					'orders'        => $row['orders'],
					'spent'         => $row['spent'],
					'spent_raw'     => $row['spent_raw'],
					'last_activity' => $row['last_activity'],
				);
			},
			$result['rows']
		);

		return $this->respond_paginated(
			$items,
			$result['total'],
			$result['paged'],
			$result['per_page']
		);
	}

	/**
	 * GET /customers/{hash} — customer profile.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_customer( WP_REST_Request $request ): WP_REST_Response {
		$hash  = (string) $request->get_param( 'hash' );
		$email = $this->resolve_email( $hash );

		if ( null === $email ) {
			return $this->respond_error( 'customer_not_found', __( 'Customer not found.', 'equine-event-manager' ), 404 );
		}

		$repo    = new EEM_Customer_Profile_Repo();
		$profile = $repo->get_profile( $email );

		if ( ! $profile ) {
			return $this->respond_error( 'customer_not_found', __( 'Customer not found.', 'equine-event-manager' ), 404 );
		}

		$can_see_pii = current_user_can( 'eem_view_reservations' ) || current_user_can( 'manage_options' );

		$recent_orders = array_slice( $profile['orders'] ?? array(), 0, 5 );

		$data = array(
			'email_hash'     => $hash,
			'email'          => $profile['email'],
			'name'           => $profile['name'],
			'phone'          => $can_see_pii ? $profile['phone'] : '[redacted]',
			'billing'        => $can_see_pii ? $profile['billing'] : array( 'name' => '[redacted]', 'lines' => array() ),
			'customer_since' => $profile['customer_since'],
			'stats'          => $profile['stats'],
			'order_count'    => count( $profile['orders'] ?? array() ),
			'recent_orders'  => $recent_orders,
			'reservations'   => $profile['reservations'],
			'activity'       => $profile['activity'],
			'note'           => $profile['note'],
		);

		return $this->respond( $data );
	}

	/**
	 * GET /customers/{hash}/orders — paginated order history.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_customer_orders( WP_REST_Request $request ): WP_REST_Response {
		$hash  = (string) $request->get_param( 'hash' );
		$email = $this->resolve_email( $hash );

		if ( null === $email ) {
			return $this->respond_error( 'customer_not_found', __( 'Customer not found.', 'equine-event-manager' ), 404 );
		}

		$orders_repo = new EEM_Orders_Repository();
		$all_orders  = $orders_repo->get_orders( '', 'date', 'desc' );

		$needle  = strtolower( trim( $email ) );
		$matched = array_values( array_filter(
			$all_orders,
			static function ( array $o ) use ( $needle ): bool {
				return strtolower( trim( (string) ( $o['email'] ?? '' ) ) ) === $needle;
			}
		) );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, (int) $request->get_param( 'per_page' ) );
		$total    = count( $matched );
		$items    = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );

		return $this->respond_paginated( $items, $total, $page, $per_page );
	}

	/**
	 * PUT /customers/{hash}/note — save admin note.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_note( WP_REST_Request $request ): WP_REST_Response {
		$hash  = (string) $request->get_param( 'hash' );
		$email = $this->resolve_email( $hash );

		if ( null === $email ) {
			return $this->respond_error( 'customer_not_found', __( 'Customer not found.', 'equine-event-manager' ), 404 );
		}

		$repo = new EEM_Customer_Profile_Repo();
		$note = (string) $request->get_param( 'note' );
		$repo->save_note( $email, $note );

		return $this->respond( array(
			'email_hash' => $hash,
			'note'       => $repo->get_note( $email ),
		) );
	}

	/**
	 * Resolve an MD5 email hash to the actual email address by scanning
	 * known customer emails.
	 *
	 * @param string $hash MD5 hash of lowercased email.
	 * @return string|null The email, or null if no match.
	 */
	private function resolve_email( string $hash ): ?string {
		$orders_repo = new EEM_Orders_Repository();
		$all_orders  = $orders_repo->get_orders( '', 'date', 'desc' );

		foreach ( $all_orders as $o ) {
			$email = strtolower( trim( (string) ( $o['email'] ?? '' ) ) );
			if ( '' !== $email && md5( $email ) === $hash ) {
				return $email;
			}
		}

		return null;
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
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'              => 'string',
				'default'           => 'last_name',
				'enum'              => array( 'last_name', 'name', 'orders', 'spent', 'activity' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'type'              => 'string',
				'default'           => 'asc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
