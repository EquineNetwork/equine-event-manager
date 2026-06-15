<?php
/**
 * REST API route registration.
 *
 * Loads all controller files and hooks their `register_routes()` methods
 * into `rest_api_init`. Coexists with the existing Stripe webhook endpoint
 * already registered under the same `eem/v1` namespace.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps all EEM REST API controllers.
 */
class EEM_REST_API {

	/**
	 * Controller instances.
	 *
	 * @var EEM_REST_Controller[]
	 */
	private array $controllers = array();

	/**
	 * Wire into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->load_controllers();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Require controller files and instantiate them.
	 *
	 * @return void
	 */
	private function load_controllers(): void {
		$dir = EQUINE_EVENT_MANAGER_PATH . 'includes/api/';

		require_once $dir . 'class-eem-rest-controller.php';
		require_once $dir . 'class-eem-rest-auth-controller.php';
		require_once $dir . 'controllers/class-eem-rest-orders-controller.php';
		require_once $dir . 'controllers/class-eem-rest-reservations-controller.php';
		require_once $dir . 'controllers/class-eem-rest-events-controller.php';
		require_once $dir . 'controllers/class-eem-rest-sheets-controller.php';

		$this->controllers[] = new EEM_REST_Auth_Controller();
		$this->controllers[] = new EEM_REST_Orders_Controller();
		$this->controllers[] = new EEM_REST_Reservations_Controller();
		$this->controllers[] = new EEM_REST_Events_Controller();
		$this->controllers[] = new EEM_REST_Sheets_Controller();
	}

	/**
	 * Register all controller routes. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
