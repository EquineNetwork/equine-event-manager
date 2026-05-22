<?php
/**
 * Main plugin loader.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-orders-repository.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-mailer.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-reservation-editor.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php';

// Phase 3 — Activity Log subsystem (ODET-7, CDET-5).
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-activity-log.php';

// Phase 3 — Settings subsystem (SET-*). Repos load eagerly so any code path
// (admin page render OR AJAX save) can use them without a separate guard.
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-email-templates-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-settings-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-settings-page.php';

// Phase 3 admin template partials.
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_breadcrumb.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_activity_log.php';

/**
 * Registers plugin hooks.
 */
class EEM_Plugin {

	/**
	 * Admin screen handler.
	 *
	 * @var EEM_Admin
	 */
	private $admin;

	/**
	 * Reservations custom post type handler.
	 *
	 * @var EEM_Reservations_CPT
	 */
	private $reservations_cpt;

	/**
	 * Native event handler.
	 *
	 * @var EEM_Events
	 */
	private $events;

	/**
	 * Shortcode handler.
	 *
	 * @var EEM_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Reservation editor screen controller.
	 *
	 * @var EEM_Reservation_Editor
	 */
	private $reservation_editor;

	/**
	 * Settings page controller (Phase 3 port). Renderable parallel to the
	 * legacy render_settings_page through C3.A–C; menu callback swap in C3.D.
	 *
	 * @var EEM_Settings_Page
	 */
	private $settings_page;

	/**
	 * Set up plugin components.
	 */
	public function __construct() {
		$this->admin            = new EEM_Admin();
		$this->reservations_cpt = new EEM_Reservations_CPT();
		$this->events           = new EEM_Events();
		$this->shortcodes       = new EEM_Shortcodes();
		$this->reservation_editor = new EEM_Reservation_Editor( $this->reservations_cpt );
		$this->settings_page    = new EEM_Settings_Page();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function run() {
		add_action( 'init', array( 'EEM_Activator', 'maybe_upgrade' ) );
		add_action( 'init', array( 'EEM_Activator', 'maybe_refresh_runtime_rewrite_rules' ), 30 );
		add_action( 'init', array( $this->reservations_cpt, 'register_post_type' ) );
		add_action( 'add_meta_boxes_en_reservation', array( $this->reservation_editor, 'register_meta_boxes' ) );
		add_action( 'save_post_en_reservation', array( $this->reservations_cpt, 'save_meta' ), 10, 2 );
		add_action( 'save_post_en_reservation', array( $this->reservations_cpt, 'sync_shortcode_to_linked_event_after_save' ), 20, 2 );
		add_action( 'trashed_post', array( $this->reservations_cpt, 'clear_shortcode_from_linked_event' ) );
		add_action( 'before_delete_post', array( $this->reservations_cpt, 'clear_shortcode_from_linked_event' ) );
		add_filter( 'admin_body_class', array( $this->reservation_editor, 'filter_editor_shell_body_class' ) );
		add_filter( 'get_user_option_meta-box-order_en_reservation', array( $this->reservation_editor, 'filter_editor_meta_box_order' ) );
		add_action( 'admin_enqueue_scripts', array( $this->reservation_editor, 'enqueue_editor_shell_styles' ) );
		add_action( 'admin_footer', array( $this->admin, 'render_global_toast_container' ) );
		add_action( 'wp_ajax_eem_save_settings', array( $this->settings_page, 'handle_ajax_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this->reservation_editor, 'print_editor_shell_fallback_assets' ) );
		add_action( 'edit_form_top', array( $this->reservation_editor, 'render_editor_header' ) );
		add_action( 'edit_form_after_title', array( $this->reservation_editor, 'render_editor_overview' ) );
		add_action( 'admin_head', array( $this->admin, 'hide_order_details_submenu' ) );
		add_filter( 'manage_en_reservation_posts_columns', array( $this->reservations_cpt, 'filter_columns' ) );
		add_action( 'manage_en_reservation_posts_custom_column', array( $this->reservations_cpt, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this->reservations_cpt, 'filter_row_actions' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this->reservations_cpt, 'filter_updated_messages' ) );
		add_action( 'admin_notices', array( $this->reservations_cpt, 'render_validation_notice' ) );
		add_action( 'admin_menu', array( $this->admin, 'register_menu' ), 20 );
		add_filter( 'parent_file', array( $this->admin, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( $this->admin, 'filter_submenu_file' ) );
		add_action( 'admin_post_equine_event_manager_export_report', array( $this->admin, 'handle_report_export' ) );
		add_action( 'admin_post_equine_event_manager_export_order_csv', array( $this->admin, 'handle_order_export' ) );
		add_action( 'admin_post_equine_event_manager_print_order', array( $this->admin, 'handle_order_print' ) );
		add_action( 'admin_post_equine_event_manager_print_reservation_overview', array( $this->admin, 'handle_reservation_overview_print' ) );
		add_action( 'admin_post_equine_event_manager_delete_order', array( $this->admin, 'handle_order_delete' ) );
		add_action( 'admin_post_equine_event_manager_refund_order', array( $this->admin, 'handle_order_refund' ) );
		add_action( 'admin_post_equine_event_manager_send_invoice_email', array( $this->admin, 'handle_send_invoice_email' ) );
		add_action( 'admin_post_equine_event_manager_resend_customer_notification', array( $this->admin, 'handle_resend_customer_notification' ) );
		add_action( 'admin_post_equine_event_manager_mark_order_paid', array( $this->admin, 'handle_mark_order_paid' ) );
		add_action( 'admin_post_equine_event_manager_update_order_assignments', array( $this->admin, 'handle_update_order_assignments' ) );
		add_action( 'admin_post_equine_event_manager_generate_stall_assignments', array( $this->admin, 'handle_generate_stall_assignments' ) );
		add_action( 'init', array( $this->shortcodes, 'register' ) );
		add_action( 'wp_ajax_equine_event_manager_create_stripe_payment_intent', array( $this->shortcodes, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_equine_event_manager_create_stripe_payment_intent', array( $this->shortcodes, 'ajax_create_stripe_payment_intent' ) );

		add_action( 'template_redirect', array( $this->shortcodes, 'maybe_render_invoice_payment_page' ) );
		add_action( 'wp_ajax_equine_event_manager_create_invoice_payment_intent', array( $this->shortcodes, 'ajax_create_invoice_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_equine_event_manager_create_invoice_payment_intent', array( $this->shortcodes, 'ajax_create_invoice_payment_intent' ) );

		add_action( 'init', array( $this->events, 'register_event_routes' ) );
		add_filter( 'query_vars', array( $this->events, 'filter_query_vars' ) );
		add_action( 'template_redirect', array( $this->events, 'maybe_render_virtual_event_page' ) );
		add_action( 'init', array( $this->events, 'register_shortcodes' ) );
		add_filter( 'the_content', array( $this->events, 'filter_single_event_content' ), 20 );
		add_action( 'wp_ajax_equine_event_manager_test_feed_url', array( $this->events, 'ajax_test_feed_url' ) );
		add_action( 'wp_ajax_equine_event_manager_search_feed_events', array( $this->events, 'ajax_search_feed_events' ) );

		if ( EEM_Events::is_native_events_enabled() ) {
			add_action( 'init', array( $this->events, 'register_content_types' ) );
			add_filter( 'use_block_editor_for_post_type', array( $this->events, 'filter_use_block_editor_for_post_type' ), 10, 2 );
			add_action( 'add_meta_boxes', array( $this->events, 'register_meta_boxes' ) );
			add_action( 'add_meta_boxes_en_event', array( $this->reservations_cpt, 'register_native_event_meta_box' ) );
			add_action( 'save_post_en_event', array( $this->events, 'save_event_meta' ), 10, 2 );
			add_action( 'save_post_en_venue', array( $this->events, 'save_venue_meta' ), 10, 2 );
			add_action( 'save_post_en_producer', array( $this->events, 'save_producer_meta' ), 10, 2 );
			add_action( 'save_post_en_event', array( $this->reservations_cpt, 'save_native_event_meta' ), 20, 2 );
			add_action( 'widgets_init', array( $this->events, 'register_widgets' ) );
		}

		if ( EEM_Events::is_tec_integration_configured() ) {
			add_action( 'add_meta_boxes_tribe_events', array( $this->reservations_cpt, 'register_tec_event_meta_box' ) );
			add_action( 'save_post_tribe_events', array( $this->reservations_cpt, 'save_tec_event_meta' ), 10, 2 );
			add_action( 'wp_ajax_equine_event_manager_search_tec_events', array( $this->reservations_cpt, 'ajax_search_tec_events' ) );
			add_action( 'admin_post_equine_event_manager_import_tec_events', array( $this->admin, 'handle_import_tec_events' ) );
		}
	}
}
