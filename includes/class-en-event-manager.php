<?php
/**
 * Main plugin loader.
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EN_EVENT_MANAGER_PATH . 'includes/class-en-event-manager-orders-repository.php';
require_once EN_EVENT_MANAGER_PATH . 'includes/class-en-event-manager-reservations-cpt.php';
require_once EN_EVENT_MANAGER_PATH . 'admin/class-en-event-manager-admin.php';
require_once EN_EVENT_MANAGER_PATH . 'public/class-en-event-manager-shortcodes.php';

/**
 * Registers plugin hooks.
 */
class EN_Event_Manager {

	/**
	 * Admin screen handler.
	 *
	 * @var EN_Event_Manager_Admin
	 */
	private $admin;

	/**
	 * Reservations custom post type handler.
	 *
	 * @var EN_Event_Manager_Reservations_CPT
	 */
	private $reservations_cpt;

	/**
	 * Shortcode handler.
	 *
	 * @var EN_Event_Manager_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Set up plugin components.
	 */
	public function __construct() {
		$this->admin            = new EN_Event_Manager_Admin();
		$this->reservations_cpt = new EN_Event_Manager_Reservations_CPT();
		$this->shortcodes       = new EN_Event_Manager_Shortcodes();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function run() {
		add_action( 'init', array( $this->reservations_cpt, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this->reservations_cpt, 'register_meta_boxes' ) );
		add_action( 'save_post_en_reservation', array( $this->reservations_cpt, 'save_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this->reservations_cpt, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_en_event_manager_search_tec_events', array( $this->reservations_cpt, 'ajax_search_tec_events' ) );
		add_filter( 'manage_en_reservation_posts_columns', array( $this->reservations_cpt, 'filter_columns' ) );
		add_action( 'manage_en_reservation_posts_custom_column', array( $this->reservations_cpt, 'render_column' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this->reservations_cpt, 'filter_updated_messages' ) );
		add_action( 'admin_menu', array( $this->admin, 'register_menu' ), 20 );
		add_action( 'init', array( $this->shortcodes, 'register' ) );
	}
}
