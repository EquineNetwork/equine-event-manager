<?php
/**
 * Base REST API controller.
 *
 * Provides response envelope helpers and permission-check callbacks
 * for all EEM REST endpoints.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for EEM REST controllers.
 *
 * Subclasses implement `register_routes()` and call the response/permission
 * helpers defined here. The namespace is shared across all endpoints so the
 * Stripe webhook (already at `eem/v1/stripe-webhook`) and the new CRUD
 * endpoints coexist under the same prefix.
 */
abstract class EEM_REST_Controller {

	const NAMESPACE = 'eem/v1';

	/**
	 * Register this controller's routes. Called from EEM_REST_API::init().
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Success response.
	 *
	 * @param mixed $data    Payload.
	 * @param int   $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	protected function respond( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Paginated list response.
	 *
	 * @param array $items    Items for the current page.
	 * @param int   $total    Total matching items across all pages.
	 * @param int   $page     Current page number (1-based).
	 * @param int   $per_page Items per page.
	 * @return WP_REST_Response
	 */
	protected function respond_paginated( array $items, int $total, int $page, int $per_page ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $items,
				'meta'    => array(
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per_page,
					'pages'    => (int) ceil( $total / max( 1, $per_page ) ),
				),
			),
			200
		);
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	protected function respond_error( string $code, string $message, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
					'status'  => $status,
				),
			),
			$status
		);
	}

	/**
	 * Permission callback: manage_options (super admin / organizer admin).
	 *
	 * @return bool
	 */
	public static function require_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback: staff-level (eem_manage_reservations OR manage_options).
	 *
	 * @return bool
	 */
	public static function require_staff(): bool {
		return current_user_can( 'eem_manage_reservations' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback: facility worker (eem_facility_ops OR higher).
	 *
	 * @return bool
	 */
	public static function require_facility(): bool {
		return current_user_can( 'eem_facility_ops' ) || current_user_can( 'eem_manage_reservations' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback: any authenticated user.
	 *
	 * @return bool
	 */
	public static function require_auth(): bool {
		return is_user_logged_in();
	}

	/**
	 * Permission callback: public (always true).
	 *
	 * @return bool
	 */
	public static function allow_public(): bool {
		return true;
	}
}
