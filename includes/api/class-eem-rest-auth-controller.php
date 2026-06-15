<?php
/**
 * Auth REST controller — GET /eem/v1/auth/me.
 *
 * Returns the authenticated user's identity and EEM-specific capabilities.
 * Used by API consumers to verify credentials and discover their role.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /auth/me endpoint.
 */
class EEM_REST_Auth_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_me' ),
				'permission_callback' => array( static::class, 'require_auth' ),
			)
		);
	}

	/**
	 * Return the current user's identity and EEM capabilities.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_me( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();

		$eem_caps = array(
			'manage_options'         => current_user_can( 'manage_options' ),
			'eem_manage_reservations' => current_user_can( 'eem_manage_reservations' ),
			'eem_view_reservations'  => current_user_can( 'eem_view_reservations' ),
			'eem_facility_ops'       => current_user_can( 'eem_facility_ops' ),
		);

		$role = 'customer';
		if ( $eem_caps['manage_options'] ) {
			$role = 'super_admin';
		} elseif ( $eem_caps['eem_manage_reservations'] ) {
			$role = 'organizer_admin';
		} elseif ( $eem_caps['eem_view_reservations'] ) {
			$role = 'organizer_staff';
		} elseif ( $eem_caps['eem_facility_ops'] ) {
			$role = 'facility_worker';
		}

		return $this->respond(
			array(
				'id'           => $user->ID,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'role'         => $role,
				'capabilities' => $eem_caps,
			)
		);
	}
}
