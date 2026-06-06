<?php
/**
 * Main plugin loader.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-orders-repository.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-order-adjustments-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reports-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-report-exporter.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-customer-profile-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-mailer.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-pdf.php';
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

// Phase 3 — Reservations list subsystem (C4 — replaces WP-native
// edit.php?post_type=en_reservation with a custom mockup-faithful page).
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reservation-source-resolver.php';

// C6.D — activity-log auto-fire telemetry. Listens for eem_order_created,
// eem_order_payment_status_changed, eem_email_sent and writes
// corresponding EEM_Activity_Log entries.
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-order-telemetry.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reservations-list-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php';

// Phase 3 — Orders list subsystem (C5 — replaces legacy
// EEM_Admin::render_orders_page with a mockup-faithful page).
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-orders-list-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php';

// DS-1.A — Create Order + Collect Payment admin page stubs (functional
// implementation lands in C13/C14). Each renders the canonical mockup
// HTML with a "Coming in C13/C14" preview banner so the routes are
// wired and the UI is browser-verifiable.
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-create-order-page.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-collect-payment-page.php';

// Phase 3 — Order Detail page (C6.A — single-order view; refund + activity-log
// telemetry land in C6.B/C/D/E).
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reports-page.php';

// C9 — Customer Profile page (read-only aggregate by email) + its data repo.
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-customer-profile-page.php';

// V1 #3 — Customers list page (top-level menu; paginated index into profiles).
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-customers-list-page.php';

// DS-1.B — Admin Dashboard page + data repository (renders against
// .mockups/dashboard_page.html; em-dash placeholders for C8/C11-blocked
// data per CLEANUP #37-#40).
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-dashboard-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-dashboard-icons.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-dashboard-page.php';

// 2.7.24 — First-run setup checklist (Dashboard card). Computes go-live
// readiness from live Settings option values; dismissible per-user.
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-setup-checklist.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-setup-wizard.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-stall-setup-wizard.php';

// 2.3.25 — WP-CLI demo data seeder. Loaded only in CLI context; the file
// self-guards against being loaded outside WP_CLI and registers the
// `wp eem seed_demo` command via WP_CLI::add_command().
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once EQUINE_EVENT_MANAGER_PATH . 'tools/seed-demo-data.php';
}

// C7.A — Event Defaults repository + Cancellation Policy resolver.
// Repo backs the new wp_eem_event_defaults table (cancellation_policy
// + venue_map_*). Resolver is consumed by C7.E editor UI + C10/C11/C12
// customer-facing surfaces.
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-event-defaults-repo.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/eem-cancellation-policy.php';

// C7.B.1 — Reservation Editor page (Path A custom-render). Replaces
// the WP CPT meta-box editor over the course of C7. C7.B.1 ships the
// render scaffold + section skeletons; C7.B.2 adds modal + save bar;
// C7.C wires existing-section data; C7.D/E add Event Day + Cancellation.
require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php';

// C7.B.2 AJAX dispatchers — save bar (post_status flip) + Linked
// Event modal. Per Decision I: single nonce action covers all
// endpoints. Per Decision D: save dispatcher is SHELL only — per-
// section meta saves wire in C7.C.
add_action( 'wp_ajax_eem_reservation_editor_save', array( 'EEM_Reservation_Editor_Page', 'ajax_save' ) );
// FIX 1 (2.3.43) — Pencil inline-edit rename from the Edit Reservation header.
add_action( 'wp_ajax_eem_rename_reservation',      array( 'EEM_Reservation_Editor_Page', 'ajax_rename' ) );
// FIX 5 (2.3.42) — Quick Edit inline row save from the Reservations list.
add_action( 'wp_ajax_eem_reservation_quick_edit', array( 'EEM_Reservations_List_Page', 'handle_quick_edit_ajax' ) );
// FIX 5 (2.3.43) / FIX 1 (2.3.44) — Duplicate row action via AJAX.
// 2.3.44: returns list-stay payload (no redirect_url); JS reloads list page.
add_action( 'wp_ajax_eem_reservation_duplicate_ajax', array( 'EEM_Reservations_List_Page', 'handle_duplicate_ajax' ) );
// FIX 2 (2.3.44) — Push name/slug mirror to linked reservations when a TEC
// event is saved (admin edits TEC event title → all reservations auto-sync).
add_action( 'save_post_tribe_events', array( 'EEM_Reservation_Editor_Page', 'on_tec_event_save' ), 20, 2 );
// C7.X.3 — change_linked_event handler retired; replaced by ajax_unlink_event
// (rail-card Unlink button) + event-search typeahead handler in a later commit.
add_action( 'wp_ajax_eem_reservation_editor_unlink_event', array( 'EEM_Reservation_Editor_Page', 'ajax_unlink_event' ) );
add_action( 'wp_ajax_eem_reservation_editor_trash',        array( 'EEM_Reservation_Editor_Page', 'ajax_trash' ) );

// Redirect legacy WP CPT edit URL (`post.php?post=N&action=edit`) to the
// new editor for `en_reservation` posts. Catches bookmarked URLs +
// third-party links + any `get_edit_post_link()` callers we missed in
// the C7.X.7+ rewire. Mirrors the list-view redirect on
// `EEM_Reservations_List_Page::maybe_redirect_old_list`.
add_action( 'load-post.php',     array( 'EEM_Reservation_Editor_Page', 'maybe_redirect_legacy_edit' ) );
add_action( 'load-post-new.php', array( 'EEM_Reservation_Editor_Page', 'maybe_redirect_new_reservation' ) );

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
		// C7.C.1 — meta-box registration retired. The Reservation Editor
		// is now a custom render page (EEM_Reservation_Editor_Page); the
		// legacy EEM_Reservation_Editor class is kept ONLY as a "save +
		// shell adapter" for save_meta() / body-class filter until the
		// full migration completes at end of C7 lineage. The
		// `add_meta_boxes_en_reservation` action wiring is intentionally
		// absent — both the static smoke (no add_meta_box() calls remain
		// in the editor class) and the runtime smoke (no boxes register
		// when the action fires) protect the retirement.
		add_action( 'save_post_en_reservation', array( $this->reservations_cpt, 'save_meta' ), 10, 2 );
		add_action( 'save_post_en_reservation', array( $this->reservations_cpt, 'sync_shortcode_to_linked_event_after_save' ), 20, 2 );
		// C6.6 / RES-ARCH-1: write the source-event start_date sort cache after
		// save_meta + sync_shortcode have updated any linked-event meta keys.
		add_action( 'save_post_en_reservation', array( 'EEM_Reservation_Source_Resolver', 'cache_source_event_start_date' ), 30, 2 );
		add_action( 'trashed_post', array( $this->reservations_cpt, 'clear_shortcode_from_linked_event' ) );
		add_action( 'before_delete_post', array( $this->reservations_cpt, 'clear_shortcode_from_linked_event' ) );
		add_filter( 'admin_body_class', array( $this->reservation_editor, 'filter_editor_shell_body_class' ) );
		add_filter( 'get_user_option_meta-box-order_en_reservation', array( $this->reservation_editor, 'filter_editor_meta_box_order' ) );
		add_action( 'admin_enqueue_scripts', array( $this->reservation_editor, 'enqueue_editor_shell_styles' ) );
		add_action( 'admin_footer', array( $this->admin, 'render_global_toast_container' ) );
		add_action( 'wp_ajax_eem_save_settings', array( $this->settings_page, 'handle_ajax_save_settings' ) );
		add_action( 'wp_ajax_eem_send_test_email', array( $this->settings_page, 'handle_ajax_send_test_email' ) );
		add_action( 'wp_ajax_eem_reset_all_data', array( $this->settings_page, 'handle_ajax_reset_all_data' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueue_assets' ) );
		// C4 — redirect WP-native en_reservation list to our custom page.
		add_action( 'current_screen', array( 'EEM_Reservations_List_Page', 'maybe_redirect_old_list' ) );

		// C4.C — Reservations list row-action handlers.
		add_action( 'admin_post_eem_reservation_duplicate',       array( 'EEM_Reservations_List_Page', 'handle_duplicate' ) );
		add_action( 'admin_post_eem_reservation_trash',           array( 'EEM_Reservations_List_Page', 'handle_trash' ) );
		add_action( 'admin_post_eem_reservation_restore',         array( 'EEM_Reservations_List_Page', 'handle_restore' ) );
		add_action( 'admin_post_eem_reservation_delete_permanently', array( 'EEM_Reservations_List_Page', 'handle_delete_permanently' ) ); // C7.X.16 Issue G
		add_action( 'admin_post_eem_reservation_export_roster',   array( 'EEM_Reservations_List_Page', 'handle_export_roster' ) );
		add_action( 'admin_post_eem_reservations_bulk',           array( 'EEM_Reservations_List_Page', 'handle_bulk' ) );
		add_action( 'wp_ajax_eem_email_customers',                array( 'EEM_Reservations_List_Page', 'handle_email_customers_ajax' ) );
		add_action( 'wp_ajax_eem_email_customers_count',          array( 'EEM_Reservations_List_Page', 'handle_email_customers_count_ajax' ) );
		add_action( 'admin_enqueue_scripts',                      array( 'EEM_Reservations_List_Page', 'localize_row_action_nonces' ), 20 );

		// C5.C — Orders list row-action handlers.
		add_action( 'admin_post_eem_order_resend_notification',   array( 'EEM_Orders_List_Page', 'handle_resend_notification' ) );
		add_action( 'admin_post_eem_order_export_csv',            array( 'EEM_Orders_List_Page', 'handle_export_csv' ) );

		// C15 — Reports export + cached-file download endpoints.
		EEM_Reports_Page::register();
		add_action( 'admin_post_eem_order_trash',                 array( 'EEM_Orders_List_Page', 'handle_trash' ) );
		add_action( 'admin_post_eem_order_print_receipt',         array( 'EEM_Orders_List_Page', 'handle_print_receipt' ) );
		add_action( 'admin_post_eem_orders_bulk_refund',          array( 'EEM_Orders_List_Page', 'handle_bulk_refund' ) );
		// C5.G.8 — hidden Customer Profile placeholder page. Real page
		// replaces this stub when the planned-roadmap chunk ships
		// (see CLEANUP.md "Customer Profile chunk sequencing").
		add_action( 'admin_menu',                                 array( 'EEM_Orders_List_Page', 'register_customer_profile_stub' ), 25 );
		// C9 — Customer Profile AJAX (notes save) + CSV export handlers.
		EEM_Customer_Profile_Page::register();

		// C6.A — hidden Order Detail page. Reachable via direct URL only
		// (admin.php?page=equine-event-manager-order&order_key=...).
		// Orders list View Order / order # / customer-name anchors all
		// converge here.
		add_action( 'admin_menu',                                 array( 'EEM_Order_Detail_Page', 'register_page' ), 25 );
		add_action( 'admin_enqueue_scripts',                      array( 'EEM_Orders_List_Page', 'localize_row_action_nonces' ), 20 );
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

		// C6.B — AJAX endpoint for the Order Detail page's single-order
		// Refund Order modal. Wraps the legacy refund infrastructure
		// (refund_order_component + persist_component_refund) via the
		// new public process_amount_refund() adapter on EEM_Admin.
		add_action( 'wp_ajax_eem_order_refund_single', array( $this->admin, 'handle_ajax_refund_single' ) );

		// C6.C — bulk-refund step endpoint. Called sequentially by the
		// runBulkRefundQueue JS layer, once per selected order. Server
		// computes amount = remaining_refundable at call time (no
		// client-supplied amount) — that's the retry-safety property.
		add_action( 'wp_ajax_eem_order_bulk_refund_step', array( $this->admin, 'handle_ajax_bulk_refund_step' ) );

		// C13.C.4b — remove an order's applied discount with a required reason
		// (logged to the Activity Log). Handler lives on the Order Detail page.
		add_action( 'wp_ajax_eem_order_remove_discount', array( 'EEM_Order_Detail_Page', 'ajax_remove_discount' ) );

		// C14 — admin Collect Payment Stripe charge (two-step: create intent →
		// confirm). Capability + nonce gated; reuses the Stripe REST primitives on
		// EEM_Shortcodes. Admin-only (no nopriv).
		add_action( 'wp_ajax_eem_collect_payment_create_intent', array( $this->shortcodes, 'ajax_collect_payment_create_intent' ) );
		add_action( 'wp_ajax_eem_collect_payment_confirm', array( $this->shortcodes, 'ajax_collect_payment_confirm' ) );

		// C6.E.2 — Add Note form AJAX (writes ordernote entry to
		// EEM_Activity_Log, returns rendered entry HTML for the JS
		// `add-note-submit` arm to prepend).
		add_action( 'wp_ajax_eem_order_add_note', array( $this->admin, 'handle_ajax_order_add_note' ) );

		// C6.D — activity-log auto-fire telemetry listeners (order.create,
		// order.payment_received / order.status_change funnel, order.email_sent).
		EEM_Order_Telemetry::register();
		add_action( 'admin_post_equine_event_manager_send_invoice_email', array( $this->admin, 'handle_send_invoice_email' ) );
		add_action( 'admin_post_equine_event_manager_resend_customer_notification', array( $this->admin, 'handle_resend_customer_notification' ) );
		add_action( 'admin_post_equine_event_manager_mark_order_paid', array( $this->admin, 'handle_mark_order_paid' ) );
		add_action( 'admin_post_equine_event_manager_update_order_assignments', array( $this->admin, 'handle_update_order_assignments' ) );
		add_action( 'admin_post_equine_event_manager_generate_stall_assignments', array( $this->admin, 'handle_generate_stall_assignments' ) );
		add_action( 'init', array( $this->shortcodes, 'register' ) );
		add_action( 'wp_ajax_equine_event_manager_create_stripe_payment_intent', array( $this->shortcodes, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_equine_event_manager_create_stripe_payment_intent', array( $this->shortcodes, 'ajax_create_stripe_payment_intent' ) );

		// Phase 4 — Stripe webhook endpoint (POST /wp-json/eem/v1/stripe-webhook).
		// Signature-verified inside the handler; reconciles payment_intent.succeeded.
		add_action( 'rest_api_init', array( $this->shortcodes, 'register_stripe_webhook_route' ) );

		add_action( 'template_redirect', array( $this->shortcodes, 'maybe_render_hosted_receipt' ) );
		add_action( 'template_redirect', array( $this->shortcodes, 'maybe_render_invoice_payment_page' ) );
		add_action( 'wp_ajax_equine_event_manager_create_invoice_payment_intent', array( $this->shortcodes, 'ajax_create_invoice_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_equine_event_manager_create_invoice_payment_intent', array( $this->shortcodes, 'ajax_create_invoice_payment_intent' ) );

		add_action( 'init', array( $this->events, 'register_event_routes' ) );
		add_filter( 'query_vars', array( $this->events, 'filter_query_vars' ) );
		add_action( 'template_redirect', array( $this->events, 'maybe_render_virtual_event_page' ) );
		add_action( 'init', array( $this->events, 'register_shortcodes' ) );
		add_filter( 'template_include', array( $this->events, 'filter_single_event_template' ), 99 );
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
