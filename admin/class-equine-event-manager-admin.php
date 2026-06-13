<?php
/**
 * Admin pages for Equine Event Manager.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Orders, Reports, and Settings admin pages.
 */
class EEM_Admin {

	/** @var EEM_Refund_Engine|null Lazy refund-kernel engine (CLEANUP #27). */
	private $refund_engine = null;

	/**
	 * Lazily build + cache the refund engine (CLEANUP #27). The engine owns the
	 * refund kernel; these methods stay on EEM_Admin as thin delegators so every
	 * existing caller is unchanged.
	 *
	 * @return EEM_Refund_Engine
	 */
	private function refund_engine() {
		if ( null === $this->refund_engine ) {
			$this->refund_engine = new EEM_Refund_Engine( $this );
		}
		return $this->refund_engine;
	}

	const MENU_SLUG = 'equine-event-manager-orders';

	const SPECIAL_REQUESTS_DESCRIPTION_OPTION = 'equine_event_manager_special_requests_description';

	const DEFAULT_SPECIAL_REQUESTS_DESCRIPTION = 'Please let us know if you have any special requests for your stay including stallion accommodations, preferred contestant proximity stalling, etc.';

	const PAYMENT_SETTINGS_OPTION = 'equine_event_manager_payment_settings';

	const COMPANY_SETTINGS_OPTION = 'equine_event_manager_company_settings';
	const FEATURE_SETTINGS_OPTION = 'equine_event_manager_feature_settings';
	const INTEGRATION_SETTINGS_OPTION = 'equine_event_manager_integration_settings';

	const RESERVATION_MESSAGE_SETTINGS_OPTION = 'equine_event_manager_reservation_message_settings';

	const RECEIPT_SETTINGS_OPTION = 'equine_event_manager_receipt_settings';

	const DEFAULT_PREOPEN_MESSAGE = 'Reservations for [event_name] will open on [open_date_time]. If you have questions please call [phone].';

	const DEFAULT_CLOSED_MESSAGE = 'Reservations for [event_name] are now closed. Please call [phone] for assistance.';

	const DEFAULT_CUSTOMER_RECEIPT_SUBJECT = 'Your reservation receipt for [event_name]';

	const DEFAULT_ADMIN_RECEIPT_SUBJECT = 'New reservation received for [event_name]';

	const DEFAULT_CUSTOMER_RECEIPT_BODY = "Hi [customer_name],\n\nThank you for your reservation for [event_name]. Your order number is [order_number] and the total amount due is [total]. A PDF copy of your receipt is attached.\n\nIf you have questions, please contact us at [support_phone] or [support_email].";

	const DEFAULT_ADMIN_RECEIPT_BODY = "A new reservation has been received for [event_name].\n\nOrder Number: [order_number]\nCustomer: [customer_name]\nTotal: [total]";

	/**
	 * Orders repository.
	 *
	 * @var EEM_Orders_Repository
	 */
	private $orders_repository;

	/**
	 * Orders screen hook.
	 *
	 * @var string
	 */
	private $orders_hook = '';

	/**
	/**
	 * Reservation overview screen hook.
	 *
	 * @var string
	 */
	private $reservation_overview_hook = '';

	/**
	 * Set up admin dependencies.
	 */
	/**
	 * Cached hook-free instance for read-only computation (see for_compute()).
	 *
	 * @var EEM_Admin|null
	 */
	private static ?EEM_Admin $compute_instance = null;

	/**
	 * @param bool $skip_hooks When true, the orders repository is still wired but
	 *                         NO WordPress hooks (filters/actions/AJAX) are
	 *                         registered. Used by for_compute() so off-request
	 *                         consumers (e.g. the Dashboard repo) can reuse this
	 *                         class's stall-chart computation helpers without
	 *                         double-registering the live instance's hooks.
	 */
	public function __construct( bool $skip_hooks = false ) {
		$this->orders_repository = new EEM_Orders_Repository();
		if ( $skip_hooks ) {
			return;
		}
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'screen_options_show_screen', array( $this, 'filter_screen_options_visibility' ), 10, 2 );
		add_filter( 'admin_body_class', array( $this, 'filter_backend_shell_body_class' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_disabled_native_event_admin_screens' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_event_manager_admin_routes' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_venue_list_to_branded_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_taxonomy_screens_to_branded_pages' ) );
		add_action( 'admin_menu', array( $this, 'position_event_manager_after_tec_events' ), 1002 );
		add_action( 'admin_menu', array( $this, 'normalize_event_manager_submenu_order' ), 1001 );
		add_action( 'admin_menu', array( $this, 'maybe_remove_disabled_native_event_menu_items' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_backend_shell_styles' ) );
		add_action( 'admin_footer', array( $this, 'print_reservations_list_toolbar_normalizer' ) );
		add_action( 'all_admin_notices', array( $this, 'render_reservations_list_banner' ) );
		add_action( 'all_admin_notices', array( $this, 'render_native_content_list_banner' ) );
		add_action( 'wp_ajax_eem_move_stall_assignment', array( $this, 'ajax_move_stall_assignment' ) );
		add_action( 'wp_ajax_eem_toggle_tack_stall', array( $this, 'ajax_toggle_tack_stall' ) );
		add_action( 'wp_ajax_eem_stall_map_action', array( $this, 'ajax_stall_map_action' ) );
		add_action( 'wp_ajax_eem_group_rename', array( $this, 'ajax_group_rename' ) );
		add_action( 'wp_ajax_eem_auto_assign', array( $this, 'ajax_auto_assign' ) );
		add_action( 'wp_ajax_eem_create_order_customer_search', array( 'EEM_Create_Order_Page', 'ajax_customer_search' ) );
		add_action( 'wp_ajax_eem_create_order_reservation_meta', array( 'EEM_Create_Order_Page', 'ajax_reservation_meta' ) );
		add_action( 'wp_ajax_eem_admin_create_order', array( 'EEM_Create_Order_Page', 'ajax_create_order' ) );
		EEM_Setup_Checklist::register();
		EEM_Setup_Wizard::register();
		EEM_Stall_Setup_Wizard::register();
	}

	/**
	 * Lazily build (and cache) a hook-free EEM_Admin instance for read-only
	 * computation. The Dashboard data repository uses this to reuse the
	 * stall-chart assignment logic (get_stall_chart_config + allocate_*) without
	 * spinning up a second hook-registering admin instance.
	 *
	 * @return EEM_Admin
	 */
	public static function for_compute(): EEM_Admin {
		if ( null === self::$compute_instance ) {
			self::$compute_instance = new self( true );
		}
		return self::$compute_instance;
	}

	/**
	 * Add minimal body classes for the canonical backend shell patterns.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public function filter_backend_shell_body_class( $classes ) {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return $classes;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$post_type = $screen->post_type;

		if ( empty( $post_type ) ) {
			$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

			if ( $post_id > 0 ) {
				$post_type = get_post_type( $post_id );
			} elseif ( isset( $_GET['post_type'] ) ) {
				$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
			}
		}

		if ( 'edit-en_reservation' === $screen->id && 'edit' === $screen->base ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--reservations' );
		}

		if ( EEM_Reservations_CPT::POST_TYPE === $post_type && in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--editor' );
		}

		if ( in_array( $page, array( self::MENU_SLUG, 'equine-event-manager-orders', 'equine-event-manager-order', 'equine-event-manager-order-refund' ), true ) ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--orders' );
		}

		if ( 'equine-event-manager-stall-charts' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--stall-charts' );
		}

		if ( 'equine-event-manager-stall-chart-print' === $page ) {
			// Print view — no header variant; WP chrome hidden via CSS.
			return trim( $classes . ' eem-shell-page eem-shell-page--print' );
		}

		if ( 'equine-event-manager-reports' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--reports' );
		}

		if ( 'equine-event-manager-reservation-overview' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--overview' );
		}

		if ( 'equine-event-manager-settings' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--rail eem-shell-page--settings' );
		}

		if ( EEM_Reservations_List_Page::MENU_SLUG === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--reservations-list' );
		}

		// C7.B.1: Reservation Editor branch — NEW variant
		// `eem-shell-page--reservation-editor` (per Decision C, distinct
		// from the legacy `eem-shell-page--editor` used by the WP CPT
		// edit screen so legacy carve-out rules don't cross-apply).
		// Required per DS-1.B.4 lesson — without this the new page
		// renders narrower than other admin pages.
		if ( 'equine-event-manager-reservation-editor' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--reservation-editor' );
		}

		// Entries editor — reuse the reservation-editor shell variant so the
		// styled editor renders identically. Per the DS-1.B.4 lesson this branch
		// is required or admin-legacy.css carve-outs shrink the page.
		if ( EEM_Entries::EDITOR_SLUG === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--reservation-editor' );
		}

		// Entries list — reuse the reservations-list shell variant so the custom
		// list page renders with identical chrome.
		if ( EEM_Entries::LIST_SLUG === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--reservations-list' );
		}

		// DS-1.B.4: Dashboard branch added. Without this, the Dashboard
		// page rendered with NO eem-shell-page class on body, causing
		// admin-legacy.css `:not(.eem-shell-page--…)` carve-out rules to
		// apply and shrink .eem-page by 20px (visible width discrepancy
		// vs Orders/Reservations/Order Detail confirmed in DevTools).
		// Also restores the body.eem-shell-page font-family rule.
		if ( 'equine-event-manager-dashboard' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--dashboard' );
		}

		// DS-1.B.4: Create Order + Collect Payment stub pages also need
		// the shell-page class for the same reason (legacy carve-outs).
		if ( in_array( $page, array( 'equine-event-manager-create-order', 'equine-event-manager-collect-payment' ), true ) ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--' . $page );
		}

		// C9: Customer Profile page — same shell-page branch requirement
		// (DS-1.B.4 lesson) so legacy carve-outs don't shrink .eem-page.
		if ( 'equine-event-manager-customer' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--customer' );
		}

		// V1 #3: Customers list page — same shell-page branch requirement.
		if ( 'equine-event-manager-customers' === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--customers' );
		}

		// v2 Notifications page.
		if ( EEM_Notifications_Page::MENU_SLUG === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--notifications' );
		}

		if ( EEM_Venues_Page::MENU_SLUG === $page ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--venues' );
		}

		// Native Events Admin B — branded taxonomy Categories pages (Event /
		// Venue / Producer). Same shell-page branch requirement (DS-1.B.4) so
		// legacy carve-outs don't shrink .eem-page.
		if ( in_array( $page, EEM_Term_Categories_Page::slugs(), true ) ) {
			return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--term-categories' );
		}

		return $classes;
	}

	/**
	 * Enqueue the new minimal backend shell stylesheet for canonical patterns only.
	 *
	 * @return void
	 */
	public function enqueue_backend_shell_styles( $hook_suffix = '' ) {
		$screen = get_current_screen();

		if ( ! $screen ) {
			$screen = (object) array(
				'id'        => '',
				'base'      => '',
				'post_type' => '',
			);
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$post_type = $screen->post_type;
		$post_id   = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( empty( $post_type ) && $post_id > 0 ) {
			$post_type = get_post_type( $post_id );
		}

		if ( empty( $post_type ) && isset( $_GET['post_type'] ) ) {
			$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}

		$should_load = false;

		if ( 'edit-en_reservation' === $screen->id && 'edit' === $screen->base ) {
			$should_load = true;
		}

		if ( EEM_Reservations_CPT::POST_TYPE === $post_type && in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$should_load = true;
		}

		if ( in_array( $page, array( self::MENU_SLUG, 'equine-event-manager-orders', 'equine-event-manager-order', 'equine-event-manager-order-refund', 'equine-event-manager-settings', 'equine-event-manager-stall-charts', 'equine-event-manager-stall-chart-print', 'equine-event-manager-reports', 'equine-event-manager-reservation-overview', 'equine-event-manager-create-order', 'equine-event-manager-collect-payment', 'equine-event-manager-dashboard', 'equine-event-manager-reservation-editor', EEM_Entries::EDITOR_SLUG, EEM_Entries::LIST_SLUG, 'equine-event-manager-customer', 'equine-event-manager-customers', EEM_Notifications_Page::MENU_SLUG, EEM_Venues_Page::MENU_SLUG, EEM_Reservations_List_Page::MENU_SLUG ), true ) ) {
			$should_load = true;
		}

		// Native Events Admin B — branded taxonomy Categories pages.
		if ( in_array( $page, EEM_Term_Categories_Page::slugs(), true ) ) {
			$should_load = true;
		}

		if ( ! $should_load ) {
			return;
		}

		$ver = defined( 'EQUINE_EVENT_MANAGER_VERSION' ) ? EQUINE_EVENT_MANAGER_VERSION : false;

		// DS-1.A: Google Fonts (Space Grotesk + IBM Plex Sans) — load
		// from the Google Fonts CDN. admin.css's `--eem-font-display` and
		// `--eem-font-ui` CSS vars reference these by name; without the
		// font files being loaded, every plugin admin page rendered with
		// system-ui fallback (the single biggest visual-fidelity gap
		// pre-DS-1). `display=swap` lets pages paint with the system
		// fallback first, then re-render once the web fonts arrive —
		// avoids FOIT (flash of invisible text) on slow connections.
		wp_enqueue_style(
			'eem-google-fonts',
			'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap',
			array(),
			null
		);

		// Phase 3 rebuild (tokenized) — loaded first so legacy rules can
		// override it where pages haven't been ported yet.
		wp_enqueue_style( 'eem-admin', EQUINE_EVENT_MANAGER_URL . 'assets/css/admin.css', array( 'eem-google-fonts' ), $ver );

		// Phase 2 → Phase 3 transition stylesheet. Each page-port chunk
		// migrates rules out of this file into admin.css; final commit of
		// Phase 3 deletes it.
		wp_enqueue_style( 'eem-admin-legacy', EQUINE_EVENT_MANAGER_URL . 'assets/css/admin-legacy.css', array( 'eem-admin' ), $ver );

		// Shared admin JS (delegated handlers, EEM namespace).
		wp_enqueue_script( 'eem-admin', EQUINE_EVENT_MANAGER_URL . 'assets/js/admin.js', array(), $ver, true );

		// Entry editor — self-contained typeahead + save dispatch (v1 #1b).
		if ( EEM_Entries::EDITOR_SLUG === $page ) {
			wp_enqueue_script( 'eem-entry-editor', EQUINE_EVENT_MANAGER_URL . 'assets/js/entry-editor.js', array( 'eem-admin' ), $ver, true );
		}

		// C7.X.15 Issue 2B — Reservation Editor needs the WordPress
		// Media Library for the Agreement upload button. Enqueue only on
		// the editor page so we don't bloat every admin surface.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'equine-event-manager-reservation-editor' ) ) {
			wp_enqueue_media();
			// Native Map Builder modal (replaces the Google-Sheet connector).
			wp_enqueue_script( 'eem-map-builder', EQUINE_EVENT_MANAGER_URL . 'assets/js/eem-map-builder.js', array( 'eem-admin' ), $ver, true );
			// v2 Venues Slice 3 — Save Layout / Load Layout to Venue.
			wp_enqueue_script( 'eem-venue-layouts', EQUINE_EVENT_MANAGER_URL . 'assets/js/venue-layouts.js', array( 'eem-admin' ), $ver, true );
			wp_localize_script( 'eem-venue-layouts', 'eemVenueLayouts', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eem_venue_layout' ),
			) );
		}

			// CLEANUP #21 (Choices.js, MIT) -- searchable filter dropdowns on the Orders
			// event filter + Reservations date filter, where a native select becomes an
			// unusable long scroll past ~50 events. Loaded only on those two list screens.
			if ( in_array( $page, array( 'equine-event-manager-orders', EEM_Reservations_List_Page::MENU_SLUG, EEM_Entries::LIST_SLUG, EEM_Notifications_Page::MENU_SLUG ), true ) ) {
				wp_enqueue_style( 'eem-choices-vendor', EQUINE_EVENT_MANAGER_URL . 'assets/vendor/choices/choices.min.css', array(), '10.2.0' );
				wp_enqueue_style( 'eem-choices', EQUINE_EVENT_MANAGER_URL . 'assets/css/eem-choices.css', array( 'eem-choices-vendor', 'eem-admin' ), $ver );
				wp_enqueue_script( 'eem-choices-vendor', EQUINE_EVENT_MANAGER_URL . 'assets/vendor/choices/choices.min.js', array(), '10.2.0', true );
				wp_enqueue_script( 'eem-choices', EQUINE_EVENT_MANAGER_URL . 'assets/js/eem-choices.js', array( 'eem-choices-vendor' ), $ver, true );
			}

			// v2 Notifications page JS (audience builder + live count + send).
			if ( EEM_Notifications_Page::MENU_SLUG === $page ) {
				wp_enqueue_script( 'eem-notifications', EQUINE_EVENT_MANAGER_URL . 'assets/js/notifications.js', array( 'eem-admin' ), $ver, true );
				wp_localize_script( 'eem-notifications', 'eemNotifications', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
			}

			// v2 Venues detail page JS (saved-layout rename/delete modals).
			if ( EEM_Venues_Page::MENU_SLUG === $page ) {
				wp_enqueue_script( 'eem-venues', EQUINE_EVENT_MANAGER_URL . 'assets/js/venues.js', array( 'eem-admin' ), $ver, true );
				wp_localize_script( 'eem-venues', 'eemVenues', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
			}

		// C13.B.2.a — public.css required when Create Order embeds a reservation form.
		// render_frontend_form_assets_in_footer() guards against is_admin() so we
		// enqueue the stylesheet directly here instead of relying on the footer hook.
		if (
			'equine-event-manager-create-order' === $page &&
			! empty( $_GET['reservation_id'] ) &&
			class_exists( 'EEM_Events' )
		) {
			EEM_Events::render_frontend_styles();
		}
	}

	/**
	 * Inject the single global toast container into admin pages where the
	 * shell loaded. JS at EEM.showSaveToast() reuses or creates this on demand.
	 *
	 * @return void
	 */
	public function render_global_toast_container() {
		if ( ! wp_style_is( 'eem-admin', 'enqueued' ) ) {
			return;
		}
		echo '<div class="eem-toast-wrap" aria-live="polite" aria-atomic="false"></div>';
	}

	/**
	 * Keep the top-level Event Manager menu directly after TEC Events.
	 *
	 * @return void
	 */
	public function position_event_manager_after_tec_events() {
		global $menu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}

		$tec_index           = null;
		$event_manager_index = null;
		$event_manager_item  = null;

		foreach ( $menu as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}

			if ( 'edit.php?post_type=tribe_events' === $item[2] ) {
				$tec_index = $index;
			}

			if ( self::MENU_SLUG === $item[2] ) {
				$event_manager_index = $index;
				$event_manager_item  = $item;
			}
		}

		if ( null === $tec_index || null === $event_manager_index || null === $event_manager_item ) {
			return;
		}

		unset( $menu[ $event_manager_index ] );
		$menu = array_values( $menu );

		foreach ( $menu as $index => $item ) {
			if ( is_array( $item ) && isset( $item[2] ) && 'edit.php?post_type=tribe_events' === $item[2] ) {
				array_splice( $menu, $index + 1, 0, array( $event_manager_item ) );
				return;
			}
		}
	}

	/**
	 * Keep the Event Manager submenu in the intended order.
	 *
	 * @return void
	 */
	public function normalize_event_manager_submenu_order() {
		global $submenu;

		if ( empty( $submenu[ self::MENU_SLUG ] ) || ! is_array( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}

		// C5.G.3: 'edit.php?post_type=en_reservation' (the WP-native CPT
		// list URL) used to be the only Reservations submenu entry; C4
		// added a separate equine-event-manager-reservations page that
		// the user actually reaches via this sidebar. Leaving the old
		// edit.php URL in the preferred order + auto-adding it below
		// produced TWO "Reservations" entries in the sidebar. Removed
		// both — the new Phase 3 slug controls ordering, and the legacy
		// CPT URL is still reachable via direct nav (the C4
		// maybe_redirect_old_list bounce handles accidental hits).
		// DS-1.B.4: Dashboard pinned to position 0 (above Orders).
		$preferred_order = array(
			'equine-event-manager-dashboard',
			self::MENU_SLUG,
			// Entries sits directly below Orders (custom styled list page).
			'equine-event-manager-entries',
			'equine-event-manager-reservations',
			'equine-event-manager-stall-charts',
			'equine-event-manager-customers',
			'equine-event-manager-orders',
			EEM_Notifications_Page::MENU_SLUG,
			'edit.php?post_type=en_event',
			'equine-event-manager-event-categories',
			EEM_Venues_Page::MENU_SLUG,
			'equine-event-manager-venue-categories',
			'edit.php?post_type=en_producer',
			'equine-event-manager-producer-categories',
			'equine-event-manager-reports',
			'equine-event-manager-settings',
		);
		$existing = $submenu[ self::MENU_SLUG ];
		$ordered  = array();

		foreach ( $preferred_order as $slug ) {
			foreach ( $existing as $index => $item ) {
				if ( isset( $item[2] ) && $item[2] === $slug ) {
					$ordered[] = $item;
					unset( $existing[ $index ] );
				}
			}
		}

		$submenu[ self::MENU_SLUG ] = array_values( array_merge( $ordered, $existing ) );
	}

	/**
	 * Check whether a submenu item already exists for a given slug.
	 *
	 * @param array<int, array<int, string>> $items Menu items.
	 * @param string                         $slug  Target slug.
	 * @return bool
	 */
	private function submenu_contains_slug( $items, $slug ) {
		foreach ( $items as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redirect native event admin screens back to Settings when the feature is off.
	 *
	 * @return void
	 */
	public function maybe_redirect_disabled_native_event_admin_screens() {
		if ( EEM_Events::is_native_events_enabled() || wp_doing_ajax() ) {
			return;
		}

		global $pagenow;

		$blocked_post_types = array( 'en_event', 'en_venue', 'en_producer' );
		$blocked_taxonomies = array( 'en_event_category', 'en_event_tag', 'en_venue_category', 'en_producer_category' );
		$current_post_type  = '';
		$current_taxonomy   = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';

		if ( ! empty( $_GET['post_type'] ) ) {
			$current_post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
		} elseif ( ! empty( $_GET['post'] ) ) {
			$current_post_type = (string) get_post_type( absint( $_GET['post'] ) );
		}

		$is_blocked_post_type = in_array( $current_post_type, $blocked_post_types, true ) && in_array( $pagenow, array( 'post-new.php', 'post.php', 'edit.php' ), true );
		$is_blocked_taxonomy  = in_array( $current_taxonomy, $blocked_taxonomies, true ) && in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true );

		if ( ! $is_blocked_post_type && ! $is_blocked_taxonomy ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'equine-event-manager-settings',
					'tab'       => 'integrations',
					'en_notice' => 'native_events_disabled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Bounce the raw `edit.php?post_type=en_venue` WP list to the branded
	 * EEM_Venues_Page. Only the list view is redirected — add-new
	 * (`post-new.php`) and the single-venue editor (`post.php`) stay on the
	 * native WP screens. No-op when native events are disabled (that case is
	 * handled by maybe_redirect_disabled_native_event_admin_screens).
	 *
	 * @return void
	 */
	public function maybe_redirect_venue_list_to_branded_page() {
		if ( wp_doing_ajax() || ! EEM_Events::is_native_events_enabled() ) {
			return;
		}

		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		if ( empty( $_GET['post_type'] ) || 'en_venue' !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => EEM_Venues_Page::MENU_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Bounce the raw WP `edit-tags.php` term list and `term.php` term editor for
	 * the three managed category taxonomies (en_event_category / en_venue_category
	 * / en_producer_category) to the branded EEM_Term_Categories_Page. The term
	 * editor carries the term id forward as `?edit=N` so the branded page opens in
	 * edit mode. No-op when native events are disabled.
	 *
	 * @return void
	 */
	public function maybe_redirect_taxonomy_screens_to_branded_pages() {
		if ( wp_doing_ajax() || ! EEM_Events::is_native_events_enabled() ) {
			return;
		}

		global $pagenow;

		if ( ! in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$slug     = EEM_Term_Categories_Page::slug_for_taxonomy( $taxonomy );
		if ( '' === $slug ) {
			return;
		}

		$args = array( 'page' => $slug );
		if ( 'term.php' === $pagenow && ! empty( $_GET['tag_ID'] ) ) {
			$args['edit'] = absint( wp_unslash( $_GET['tag_ID'] ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect legacy Event Manager page routes that still carry the reservation post type parent.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_event_manager_admin_routes() {
		if ( wp_doing_ajax() || empty( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		// DS-1.A: legacy `equine-event-manager-dashboard` bounce to
		// MENU_SLUG removed — DS-1.A wires Dashboard as a real admin
		// page (stub during DS-1.A, full render in DS-1.B).

		if ( ! in_array( $page, array( 'equine-event-manager-orders', 'equine-event-manager-reports', 'equine-event-manager-settings', 'equine-event-manager-stall-charts', 'equine-event-manager-reservation-overview', 'equine-event-manager-create-order', 'equine-event-manager-collect-payment', 'equine-event-manager-dashboard', 'equine-event-manager-reservation-editor' ), true ) ) {
			return;
		}

		if ( empty( $_GET['post_type'] ) || 'en_reservation' !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return;
		}

		$query_args = wp_unslash( $_GET );
		unset( $query_args['post_type'] );

		wp_safe_redirect(
			add_query_arg(
				array_map(
					static function ( $value ) {
						return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
					},
					$query_args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Explicitly remove native event menu items when the feature is disabled.
	 *
	 * @return void
	 */
	public function maybe_remove_disabled_native_event_menu_items() {
		if ( EEM_Events::is_native_events_enabled() ) {
			return;
		}

		global $submenu;

		$event_submenus = array(
			'edit.php?post_type=en_event',
			'edit-tags.php?taxonomy=en_event_category&post_type=en_event',
			'edit.php?post_type=en_venue',
			'edit-tags.php?taxonomy=en_venue_category&post_type=en_venue',
			'edit.php?post_type=en_producer',
			'edit-tags.php?taxonomy=en_producer_category&post_type=en_producer',
		);

		foreach ( $event_submenus as $submenu_slug ) {
			remove_submenu_page( self::MENU_SLUG, $submenu_slug );
		}

		if ( isset( $submenu[ self::MENU_SLUG ] ) && is_array( $submenu[ self::MENU_SLUG ] ) ) {
			$submenu[ self::MENU_SLUG ] = array_values(
				array_filter(
					$submenu[ self::MENU_SLUG ],
					function ( $item ) use ( $event_submenus ) {
						return empty( $item[2] ) || ! in_array( $item[2], $event_submenus, true );
					}
				)
			);
		}

		remove_menu_page( 'edit.php?post_type=en_event' );
		remove_menu_page( 'edit.php?post_type=en_venue' );
		remove_menu_page( 'edit.php?post_type=en_producer' );
	}

	/**
	 * Hide Screen Options on plugin-managed screens.
	 *
	 * @param bool      $show   Whether to show the Screen Options UI.
	 * @param WP_Screen $screen Current screen object.
	 * @return bool
	 */
	public function filter_screen_options_visibility( $show, $screen ) {
		if ( ! $screen ) {
			return $show;
		}

		$is_plugin_screen = ! empty( $screen->id ) && false !== strpos( (string) $screen->id, 'equine-event-manager' );
		$is_native_editor = ! empty( $screen->post_type ) && in_array( $screen->post_type, array( 'en_event', 'en_venue', 'en_producer', 'en_reservation' ), true );

		if ( $is_plugin_screen || $is_native_editor ) {
			return false;
		}

		return $show;
	}

	/**
	 * Register admin pages under the Reservations CPT menu.
	 */
	public function register_menu() {
		$native_events_enabled = EEM_Events::is_native_events_enabled();

		// Orders page is rendered by the Phase 3 EEM_Orders_List_Page controller
		// (admin/class-eem-orders-list-page.php). Menu callback was swapped in
		// C5.E; the legacy render_orders_page method + its private helpers
		// stay until a separate cleanup chunk audits remaining callers and
		// removes them (same staging as C3.D.4).
		//
		// IMPORTANT: $orders_list_page is shared between add_menu_page() and
		// add_submenu_page() so WP's callback dedup (which compares object
		// identity for [object, method] pairs) collapses both registrations
		// against the same page hook into a single callback. Two separate
		// `new EEM_Orders_List_Page()` calls would register as two distinct
		// callbacks and render() would fire twice on every page load —
		// caught the hard way at C5.F first browser verify.
		$orders_list_page = new EEM_Orders_List_Page();
		$this->orders_hook = add_menu_page(
			__( 'Orders', 'equine-event-manager' ),
			__( 'Event Manager', 'equine-event-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( $orders_list_page, 'render' ),
			$this->get_menu_icon(),
			20
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Orders', 'equine-event-manager' ),
			__( 'Orders', 'equine-event-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( $orders_list_page, 'render' )
		);

		// C4 — Reservations submenu points at the new Phase 3 custom page
		// (admin/class-eem-reservations-list-page.php). The WP-native CPT
		// list at edit.php?post_type=en_reservation still exists and is
		// reachable via direct URL; current_screen redirect (wired in
		// includes/class-equine-event-manager.php) bounces accidental
		// hits to the new page.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Reservations', 'equine-event-manager' ),
			__( 'Reservations', 'equine-event-manager' ),
			'manage_options',
			EEM_Reservations_List_Page::MENU_SLUG,
			array( new EEM_Reservations_List_Page(), 'render' )
		);

		// DS-1.A: Invoicing menu/route removed entirely. Per HANDOFF Edit 5,
		// "Invoicing" is superseded by:
		//   - Create Order (admin manually creates an order; mockup
		//     `.mockups/create_order_page.html`; functional impl in C13)
		//   - Collect Payment (admin charges a customer for an existing
		//     order; mockup `.mockups/collect_payment_page.html`;
		//     functional impl in C14)
		// DS-1.A registers stub controllers for both that render the
		// canonical mockup HTML with a "coming in C13/C14" preview banner.

		$this->reservation_overview_hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'View Event', 'equine-event-manager' ),
			__( 'View Event', 'equine-event-manager' ),
			'manage_options',
			'equine-event-manager-reservation-overview',
			array( $this, 'render_reservation_overview_page' )
		);

		// DS-1.A: slug renamed equine-event-manager-stall-chart →
		// equine-event-manager-stall-charts (plural) per HANDOFF Edit 5
		// + Dashboard mockup convention. Sidebar label renamed
		// "Stall Charts" → "Stall & RV Charts" per HANDOFF Edits 1-4.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Stall & RV Charts', 'equine-event-manager' ),
			__( 'Stall & RV Charts', 'equine-event-manager' ),
			'manage_options',
			'equine-event-manager-stall-charts',
			array( $this, 'render_stall_chart_page' )
		);


		// V1 #3: Customers list (top-level index of every customer by email,
		// linking to the read-only Customer Profile page).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Customers', 'equine-event-manager' ),
			__( 'Customers', 'equine-event-manager' ),
			'manage_options',
			EEM_Customers_List_Page::MENU_SLUG,
			array( 'EEM_Customers_List_Page', 'render' )
		);

		// Stall Chart Print View — hidden from sidebar (reached via the
		// "Print View" button on the Stall Chart Detail page, which opens
		// this URL in a new tab). No sidebar entry; WP chrome hidden via CSS.
		add_submenu_page(
			'',
			__( 'Stall Chart Print View', 'equine-event-manager' ),
			'',
			'manage_options',
			'equine-event-manager-stall-chart-print',
			array( $this, 'render_stall_chart_print_page' )
		);

		// DS-1.A: Create Order admin page stub (functional implementation
		// lands in C13). EEM_Create_Order_Page::render renders the canonical
		// mockup HTML with a "preview only" info banner at the top.
		// DS-1.A.1: Registered as a HIDDEN submenu (parent='') — Create Order
		// is a workflow destination reached from the Orders list "+ Create
		// Order" button, not a top-level navigation entry. Mirrors the
		// hidden-submenu pattern used by Collect Payment below and by
		// EEM_Order_Detail_Page (C6).
		add_submenu_page(
			'',
			__( 'Create Order', 'equine-event-manager' ),
			'',
			'manage_options',
			'equine-event-manager-create-order',
			array( 'EEM_Create_Order_Page', 'render' )
		);

		// DS-1.A: Collect Payment admin page stub (functional implementation
		// lands in C14). Hidden from sidebar (label '') — reached via the
		// Collect pill on Orders list rows + the Collect Payment button on
		// the Order Detail payment-outstanding banner.
		add_submenu_page(
			'',
			__( 'Collect Payment', 'equine-event-manager' ),
			'',
			'manage_options',
			'equine-event-manager-collect-payment',
			array( 'EEM_Collect_Payment_Page', 'render' )
		);

		// C7.B.1: Reservation Editor page — Path A custom-render,
		// replaces the WP CPT meta-box editor over the course of C7.
		// Hidden submenu (parent='') — reached via the Reservations
		// list row "Edit" action, not a top-level nav entry (Decision A).
		add_submenu_page(
			'',
			__( 'Edit Reservation', 'equine-event-manager' ),
			'',
			'manage_options',
			'equine-event-manager-reservation-editor',
			array( 'EEM_Reservation_Editor_Page', 'render' )
		);

		// DS-1.B: Admin Dashboard page — real render against
		// .mockups/dashboard_page.html. DS-1.A reserved the slug + sidebar
		// entry; DS-1.B replaces the placeholder callback with
		// EEM_Dashboard_Page::render. Stays visible in the sidebar
		// (navigation destination, unlike Create Order / Collect Payment).
		// DS-1.B.4: 7th arg `$position = 0` pins Dashboard to the top of
		// the Event Manager submenu so navigation order reads Dashboard →
		// Orders → Reservations → Stall & RV Charts → Reports → Settings.
		// Final sidebar order also enforced via the `admin_menu` re-order
		// hook below (position alone doesn't override WP's auto-added
		// parent-slug submenu entry).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'equine-event-manager' ),
			__( 'Dashboard', 'equine-event-manager' ),
			'manage_options',
			'equine-event-manager-dashboard',
			array( 'EEM_Dashboard_Page', 'render' ),
			0
		);

		if ( $native_events_enabled ) {
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Events', 'equine-event-manager' ),
				__( 'Events', 'equine-event-manager' ),
				'manage_options',
				'edit.php?post_type=en_event'
			);

			// "Add New Event" removed from the sidebar (Whitney 2026-06-13) — the
			// Events list page already has an "Add Event" button. "Tags" removed
			// entirely (the en_event_tag taxonomy is no longer registered).

			// Category taxonomies use the branded EEM_Term_Categories_Page split
			// form/table layout, not the raw WP edit-tags.php screen (which is
			// redirected here). One page class, three slugs.
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Event Categories', 'equine-event-manager' ),
				__( 'Event Categories', 'equine-event-manager' ),
				'manage_options',
				'equine-event-manager-event-categories',
				array( 'EEM_Term_Categories_Page', 'render' )
			);

			// "Venues" = the branded EEM_Venues_Page list (en_venue posts + their
			// facility-template counts), not the raw WP CPT list. The raw
			// edit.php?post_type=en_venue screen is redirected here.
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Venues', 'equine-event-manager' ),
				__( 'Venues', 'equine-event-manager' ),
				'manage_options',
				EEM_Venues_Page::MENU_SLUG,
				array( 'EEM_Venues_Page', 'render' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Venue Categories', 'equine-event-manager' ),
				__( 'Venue Categories', 'equine-event-manager' ),
				'manage_options',
				'equine-event-manager-venue-categories',
				array( 'EEM_Term_Categories_Page', 'render' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Producers', 'equine-event-manager' ),
				__( 'Producers', 'equine-event-manager' ),
				'manage_options',
				'edit.php?post_type=en_producer'
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Producer Categories', 'equine-event-manager' ),
				__( 'Producer Categories', 'equine-event-manager' ),
				'manage_options',
				'equine-event-manager-producer-categories',
				array( 'EEM_Term_Categories_Page', 'render' )
			);

		}

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Reports', 'equine-event-manager' ),
			__( 'Reports', 'equine-event-manager' ),
			'manage_options',
			'equine-event-manager-reports',
			// C15.C — mockup-faithful Reports page replaces the legacy
			// render_reports_page (kept for now but no longer the menu callback).
			array( new EEM_Reports_Page(), 'render' )
		);

		// Settings page is rendered by the Phase 3 EEM_Settings_Page controller
		// (admin/class-eem-settings-page.php). Menu callback was swapped in
		// C3.D.2; the legacy 662-line render_settings_page method was deleted
		// in C3.D.4 after browser verification of the new page.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'equine-event-manager' ),
			__( 'Settings', 'equine-event-manager' ),
			'manage_options',
			'equine-event-manager-settings',
			array( new EEM_Settings_Page(), 'render' )
		);

		// C6.A: legacy `equine-event-manager-order` submenu callback swap.
		// The mockup-faithful Order Detail page (EEM_Order_Detail_Page) now
		// owns this slug, registered as a hidden submenu via
		// EEM_Order_Detail_Page::register_page() in the bootstrap loader.
		// The legacy render_order_details_page method becomes dead and is
		// scheduled for removal in the next CLEANUP #20 audit (post-C6 merge).

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Refund Order', 'equine-event-manager' ),
			__( 'Refund Order', 'equine-event-manager' ),
			'manage_options',
			'equine-event-manager-order-refund',
			array( $this, 'render_order_refund_page' )
		);

		if ( $this->orders_hook ) {
			add_action( 'load-' . $this->orders_hook, array( $this, 'add_orders_screen_options' ) );
		}
	}

	/**
	 * Resolve the top-level admin-menu icon.
	 *
	 * Returns the brand mark (white) as a base64-encoded SVG data URI so it
	 * renders inline as the menu's background image. WordPress does not recolor
	 * background-image menu icons, so the asset is authored white to read on the
	 * dark admin menu. Falls back to a Dashicon when the asset is unreadable.
	 *
	 * @return string Data-URI for the SVG, or a dashicons-* slug fallback.
	 */
	private function get_menu_icon(): string {
		$icon_path = EQUINE_EVENT_MANAGER_PATH . 'assets/images/menu-icon.svg';

		if ( is_readable( $icon_path ) ) {
			$svg = file_get_contents( $icon_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $svg && '' !== $svg ) {
				return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		return 'dashicons-tickets-alt';
	}

	/**
	 * Hide the Order Details submenu item while keeping the page registered.
	 *
	 * WordPress access checks can block hidden pages if we remove the submenu
	 * after registration, so we keep it registered and hide it visually.
	 *
	 * @return void
	 */
	public function hide_order_details_submenu() {
		?>
		<style>
			#adminmenu a[href="admin.php?page=equine-event-manager-order"] {
				display: none !important;
			}

			#adminmenu a[href="admin.php?page=equine-event-manager-order-refund"] {
				display: none !important;
			}

			#adminmenu a[href="admin.php?page=equine-event-manager-reservation-overview"] {
				display: none !important;
			}
		</style>
		<?php
	}

	/**
	 * Determine whether the current screen is the Reservations list table.
	 *
	 * @return bool
	 */
	private function is_reservations_list_screen() {
		$screen = get_current_screen();

		return $screen && 'edit-en_reservation' === $screen->id && 'edit' === $screen->base;
	}

	/**
	 * Resolve the current native content list post type.
	 *
	 * @return string
	 */
	private function get_native_content_list_post_type() {
		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base ) {
			return '';
		}

		return in_array( $screen->post_type, array( 'en_event', 'en_venue', 'en_producer' ), true ) ? (string) $screen->post_type : '';
	}

	/**
	 * Render the branded banner above the Reservations list table.
	 *
	 * @return void
	 */
	public function render_reservations_list_banner() {
		if ( ! $this->is_reservations_list_screen() ) {
			return;
		}

		$metrics            = $this->get_reservations_list_metrics();
		$total_reservations = isset( $metrics['total'] ) ? (int) $metrics['total'] : 0;
		?>
		<div class="wrap eem-shell-wrap eem-shell-wrap--header">
			<?php
			$this->render_brand_banner(
				__( 'Reservations', 'equine-event-manager' ),
				''
			);
			?>
			<div class="eem-shell-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-create-order' ) ); ?>"><?php esc_html_e( 'Create Order', 'equine-event-manager' ); ?></a>
			</div>
			<?php

			if ( 0 === $total_reservations ) {
				$this->render_empty_data_diagnostics( 'reservations' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Normalize the native Reservations list markup into one toolbar surface.
	 *
	 * WordPress outputs the status row, search box, tablenav, and table as
	 * separate blocks. This rearranges the native markup into the same app-style
	 * structure every time so CSS is styling one stable layout instead of
	 * fighting several disconnected surfaces.
	 *
	 * @return void
	 */
	public function print_reservations_list_toolbar_normalizer() {
		if ( ! $this->is_reservations_list_screen() ) {
			return;
		}
		?>
		<script>
			(function () {
				var form = document.getElementById('posts-filter');
				if (!form) {
					return;
				}

				var toolbar = form.querySelector(':scope > .tablenav.top');
				var table = form.querySelector(':scope > .wp-list-table');
				var searchBox = form.querySelector(':scope > .search-box');
				if (!toolbar || !table) {
					return;
				}

				if (toolbar.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING) {
					// already above the table
				} else {
					form.insertBefore(toolbar, table);
				}

				var right = toolbar.querySelector('.eem-reservations-toolbar-right');
				if (!right) {
					right = document.createElement('div');
					right.className = 'eem-reservations-toolbar-right';
				}

				if (searchBox && searchBox.parentNode !== right) {
					right.appendChild(searchBox);
				}

				var count = toolbar.querySelector('.displaying-num');
				if (count && count.parentNode !== right) {
					right.appendChild(count);
				}

				if (right.childNodes.length && right.parentNode !== toolbar) {
					toolbar.appendChild(right);
				}

				form.classList.add('eem-reservations-toolbar-ready');
			}());
		</script>
		<?php
	}

	/**
	 * Render the branded banner above native content list tables.
	 *
	 * @return void
	 */
	public function render_native_content_list_banner() {
		$post_type = $this->get_native_content_list_post_type();

		if ( '' === $post_type ) {
			return;
		}

		$context = $this->get_native_content_list_context( $post_type );
		$metrics = $this->get_native_content_list_metrics( $post_type );
		?>
		<div>
			<?php
			$this->render_brand_banner(
				$context['title'],
				$context['description']
			);
			?>
			<p>
					<?php foreach ( $context['actions'] as $action ) : ?>
						<a href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
					<?php endforeach; ?>
			</p>
			<div>
					<?php foreach ( $metrics as $metric ) : ?>
						<p>
							<strong><?php echo esc_html( $metric['label'] ); ?>:</strong>
							<?php echo esc_html( number_format_i18n( (int) $metric['value'] ) ); ?>
							<?php if ( '' !== $metric['meta'] ) : ?>
								<?php echo esc_html( ' - ' . $metric['meta'] ); ?>
							<?php endif; ?>
						</p>
					<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Build summary metrics for the Reservations list screen.
	 *
	 * @return array<string,int>
	 */
	private function get_reservations_list_metrics() {
		$reservation_counts = wp_count_posts( 'en_reservation' );
		$metrics            = array(
			'total'             => 0,
			'published'         => 0,
			'linked'            => 0,
			'assignments_ready' => 0,
		);

		if ( $reservation_counts ) {
			foreach ( array( 'publish', 'draft', 'private', 'future', 'pending' ) as $status_key ) {
				$metrics['total'] += isset( $reservation_counts->{$status_key} ) ? (int) $reservation_counts->{$status_key} : 0;
			}

			$metrics['published'] = isset( $reservation_counts->publish ) ? (int) $reservation_counts->publish : 0;
		}

		$reservation_ids = get_posts(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		foreach ( $reservation_ids as $reservation_id ) {
			$event_id         = absint( get_post_meta( $reservation_id, '_en_event_id', true ) );
			$native_event_id  = absint( get_post_meta( $reservation_id, '_en_native_event_id', true ) );
			$stall_chart      = EEM_Reservations_CPT::section_enabled( $reservation_id, 'stalls_enabled' );
			$rv_lot_selection = EEM_Reservations_CPT::section_enabled( $reservation_id, 'rv_enabled' );

			if ( $event_id || $native_event_id ) {
				$metrics['linked']++;
			}

			if ( $stall_chart || $rv_lot_selection ) {
				$metrics['assignments_ready']++;
			}
		}

		return $metrics;
	}

	/**
	 * Build content and actions for a native content list screen.
	 *
	 * @param string $post_type Current post type.
	 * @return array<string,mixed>
	 */
	private function get_native_content_list_context( $post_type ) {
		$contexts = array(
			'en_event'    => array(
				'title'           => __( 'Events', 'equine-event-manager' ),
				'description'     => __( 'Manage the event catalog, keep reservations linked, and maintain the shared frontend experience from one workspace.', 'equine-event-manager' ),
				'actions'         => array(
					array(
						'label'   => __( 'Add Event', 'equine-event-manager' ),
						'url'     => admin_url( 'post-new.php?post_type=en_event' ),
						'primary' => true,
					),
					array(
						'label' => __( 'View Venues', 'equine-event-manager' ),
						'url'   => admin_url( 'edit.php?post_type=en_venue' ),
					),
					array(
						'label' => __( 'View Producers', 'equine-event-manager' ),
						'url'   => admin_url( 'edit.php?post_type=en_producer' ),
					),
				),
			),
			'en_venue'    => array(
				'title'           => __( 'Venues', 'equine-event-manager' ),
				'description'     => __( 'Maintain venue records, location details, and the event connections that power the shared frontend event experience.', 'equine-event-manager' ),
				'actions'         => array(
					array(
						'label'   => __( 'Add Venue', 'equine-event-manager' ),
						'url'     => admin_url( 'post-new.php?post_type=en_venue' ),
						'primary' => true,
					),
					array(
						'label' => __( 'View Events', 'equine-event-manager' ),
						'url'   => admin_url( 'edit.php?post_type=en_event' ),
					),
					array(
						'label' => __( 'View Producers', 'equine-event-manager' ),
						'url'   => admin_url( 'edit.php?post_type=en_producer' ),
					),
				),
			),
			'en_producer' => array(
				'title'           => __( 'Producers', 'equine-event-manager' ),
				'description'     => __( 'Manage organizer records, contact details, and the event relationships used throughout the native event workspace.', 'equine-event-manager' ),
				'actions'         => array(
					array(
						'label'   => __( 'Add Producer', 'equine-event-manager' ),
						'url'     => admin_url( 'post-new.php?post_type=en_producer' ),
						'primary' => true,
					),
					array(
						'label' => __( 'View Events', 'equine-event-manager' ),
						'url'   => admin_url( 'edit.php?post_type=en_event' ),
					),
					array(
						'label' => __( 'View Venues', 'equine-event-manager' ),
						'url'   => admin_url( 'edit.php?post_type=en_venue' ),
					),
				),
			),
		);

		return isset( $contexts[ $post_type ] ) ? $contexts[ $post_type ] : $contexts['en_event'];
	}

	/**
	 * Build summary metrics for native content list screens.
	 *
	 * @param string $post_type Current post type.
	 * @return array<string,array<string,string|int>>
	 */
	private function get_native_content_list_metrics( $post_type ) {
		$total     = 0;
		$published = 0;
		$counts    = wp_count_posts( $post_type );

		if ( $counts ) {
			foreach ( array( 'publish', 'draft', 'private', 'future', 'pending' ) as $status_key ) {
				$total += isset( $counts->{$status_key} ) ? (int) $counts->{$status_key} : 0;
			}

			$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		$metrics = array(
			'total' => array(
				'label' => __( 'Total', 'equine-event-manager' ),
				'value' => $total,
				'meta'  => __( 'All active records across editorial statuses.', 'equine-event-manager' ),
			),
		);

		if ( 'en_event' === $post_type ) {
			$metrics['published'] = array(
				'label' => __( 'Published', 'equine-event-manager' ),
				'value' => $published,
				'meta'  => __( 'Live event pages available on the frontend.', 'equine-event-manager' ),
			);
			$metrics['linked']    = array(
				'label' => __( 'Linked Reservations', 'equine-event-manager' ),
				'value' => $this->count_posts_with_positive_meta( 'en_event', '_equine_event_manager_reservation_id' ),
				'meta'  => __( 'Events already connected to a reservation setup.', 'equine-event-manager' ),
			);
			$metrics['upcoming']  = array(
				'label' => __( 'Current + Upcoming', 'equine-event-manager' ),
				'value' => $this->count_current_upcoming_native_events(),
				'meta'  => __( 'Events still active or coming up next on the schedule.', 'equine-event-manager' ),
			);
		} elseif ( 'en_venue' === $post_type ) {
			$metrics['published'] = array(
				'label' => __( 'Published', 'equine-event-manager' ),
				'value' => $published,
				'meta'  => __( 'Venue records currently live in the directory.', 'equine-event-manager' ),
			);
			$metrics['in_use']    = array(
				'label' => __( 'In Use', 'equine-event-manager' ),
				'value' => $this->count_referenced_native_objects( '_equine_event_manager_event_venue_id' ),
				'meta'  => __( 'Venue records connected to at least one native event.', 'equine-event-manager' ),
			);
			$metrics['website']   = array(
				'label' => __( 'With Website', 'equine-event-manager' ),
				'value' => $this->count_posts_with_nonempty_meta( 'en_venue', '_equine_event_manager_venue_website' ),
				'meta'  => __( 'Venue records with an outbound website link ready to use.', 'equine-event-manager' ),
			);
		} else {
			$metrics['published'] = array(
				'label' => __( 'Published', 'equine-event-manager' ),
				'value' => $published,
				'meta'  => __( 'Producer records currently live in the directory.', 'equine-event-manager' ),
			);
			$metrics['in_use']    = array(
				'label' => __( 'In Use', 'equine-event-manager' ),
				'value' => $this->count_referenced_native_objects( '_equine_event_manager_event_producer_id' ),
				'meta'  => __( 'Producer records connected to at least one native event.', 'equine-event-manager' ),
			);
			$metrics['contact']   = array(
				'label' => __( 'With Contact', 'equine-event-manager' ),
				'value' => $this->count_posts_with_nonempty_meta( 'en_producer', '_equine_event_manager_producer_email' ),
				'meta'  => __( 'Producer records ready with a primary email contact.', 'equine-event-manager' ),
			);
		}

		return $metrics;
	}

	/**
	 * Count posts where a given numeric meta key stores a positive value.
	 *
	 * @param string $post_type Post type.
	 * @param string $meta_key  Meta key.
	 * @return int
	 */
	private function count_posts_with_positive_meta( $post_type, $meta_key ) {
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			if ( absint( get_post_meta( $post_id, $meta_key, true ) ) > 0 ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count posts where a given meta key contains a non-empty string value.
	 *
	 * @param string $post_type Post type.
	 * @param string $meta_key  Meta key.
	 * @return int
	 */
	private function count_posts_with_nonempty_meta( $post_type, $meta_key ) {
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			if ( '' !== trim( (string) get_post_meta( $post_id, $meta_key, true ) ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count unique referenced native objects from event post meta.
	 *
	 * @param string $meta_key Event meta key containing the linked object ID.
	 * @return int
	 */
	private function count_referenced_native_objects( $meta_key ) {
		$event_ids = get_posts(
			array(
				'post_type'      => 'en_event',
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$linked_ids = array();

		foreach ( $event_ids as $event_id ) {
			$linked_id = absint( get_post_meta( $event_id, $meta_key, true ) );

			if ( $linked_id > 0 ) {
				$linked_ids[] = $linked_id;
			}
		}

		return count( array_unique( $linked_ids ) );
	}

	/**
	 * Count current and upcoming native events using the saved end date.
	 *
	 * @return int
	 */
	private function count_current_upcoming_native_events() {
		$event_ids = get_posts(
			array(
				'post_type'      => 'en_event',
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$today = gmdate( 'Y-m-d' );
		$count = 0;

		foreach ( $event_ids as $event_id ) {
			$start_date = trim( (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true ) );
			$end_date   = trim( (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true ) );
			$end_date   = '' !== $end_date ? $end_date : $start_date;

			if ( '' !== $end_date && $end_date >= $today ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Keep the custom Reservations menu highlighted on plugin admin screens.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function filter_parent_file( $parent_file ) {
		$screen = get_current_screen();

		if ( $screen && in_array( $screen->post_type, array( 'en_reservation', 'en_event', 'en_venue', 'en_producer' ), true ) ) {
			return self::MENU_SLUG;
		}

		if ( isset( $_GET['page'] ) && 0 === strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'equine-event-manager' ) ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Keep the correct submenu item active on reservation-related admin screens.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public function filter_submenu_file( $submenu_file ) {
		$screen = get_current_screen();

		if ( $screen && 'en_reservation' === $screen->post_type ) {
			if ( in_array( $screen->base, array( 'post-new', 'post', 'edit' ), true ) ) {
				return 'edit.php?post_type=en_reservation';
			}
		}

		if ( $screen && 'en_event' === $screen->post_type ) {
			if ( 'post-new' === $screen->base ) {
				return 'post-new.php?post_type=en_event';
			}

			if ( in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
				return 'edit.php?post_type=en_event';
			}
		}

		if ( $screen && 'en_venue' === $screen->post_type ) {
			if ( 'post-new' === $screen->base ) {
				return 'post-new.php?post_type=en_venue';
			}

			if ( in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
				return 'edit.php?post_type=en_venue';
			}
		}

		if ( $screen && 'en_producer' === $screen->post_type ) {
			if ( 'post-new' === $screen->base ) {
				return 'post-new.php?post_type=en_producer';
			}

			if ( in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
				return 'edit.php?post_type=en_producer';
			}
		}

		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_key( wp_unslash( $_GET['page'] ) );

			if ( 'equine-event-manager-order' === $page ) {
				return 'equine-event-manager-orders';
			}

			if ( 'equine-event-manager-order-refund' === $page ) {
				return 'equine-event-manager-orders';
			}

			if ( 'equine-event-manager-reservation-overview' === $page ) {
				return 'edit.php?post_type=en_reservation';
			}

			if ( 'equine-event-manager-stall-charts' === $page ) {
				return 'equine-event-manager-stall-charts';
			}

			if ( in_array( $page, array( self::MENU_SLUG, 'equine-event-manager-orders', 'equine-event-manager-reports', 'equine-event-manager-settings', 'equine-event-manager-create-order', 'equine-event-manager-collect-payment', 'equine-event-manager-dashboard', 'equine-event-manager-reservation-editor' ), true ) ) {
				return $page;
			}

		}

		if ( isset( $_GET['taxonomy'] ) ) {
			$taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ) );

			if ( 'en_event_category' === $taxonomy ) {
				return 'edit-tags.php?taxonomy=en_event_category&post_type=en_event';
			}

			if ( 'en_event_tag' === $taxonomy ) {
				return 'edit-tags.php?taxonomy=en_event_tag&post_type=en_event';
			}

			if ( 'en_venue_category' === $taxonomy ) {
				return 'edit-tags.php?taxonomy=en_venue_category&post_type=en_venue';
			}

			if ( 'en_producer_category' === $taxonomy ) {
				return 'edit-tags.php?taxonomy=en_producer_category&post_type=en_producer';
			}
		}

		return $submenu_file;
	}


	/**
	 * Add screen options for the dashboard recent orders list.
	 *
	 * @return void
	 */
	public function add_orders_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Orders per page', 'equine-event-manager' ),
				'default' => 20,
				'option'  => 'equine_event_manager_orders_per_page',
			)
		);
	}

	/**
	 * Persist supported screen option values.
	 *
	 * @param mixed  $status Default status.
	 * @param string $option Option name.
	 * @param mixed  $value Submitted value.
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		if ( 'equine_event_manager_orders_per_page' !== $option ) {
			return $status;
		}

		$value = (int) $value;

		if ( $value < 1 ) {
			return 20;
		}

		return min( 100, $value );
	}

	// C5.5: removed dead get_order_list_per_page() — verified zero live callers (only dead render_dashboard_page + render_orders_page referenced it).

	/**
	 * Build a consistent Orders page URL.
	 *
	 * @param array<string, scalar|null> $args Optional query arguments.
	 * @return string
	 */
	private function get_orders_page_url( $args = array() ) {
		$query_args = array_merge(
			array(
				'page' => 'equine-event-manager-orders',
			),
			(array) $args
		);

		$query_args = array_filter(
			$query_args,
			static function ( $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					return false;
				}

				return null !== $value && '' !== (string) $value;
			}
		);

		return add_query_arg( $query_args, admin_url( 'admin.php' ) );
	}

	// C5.5: removed dead render_dashboard_page() + render_orders_page() — verified zero live callers (render_dashboard_page was never wired to a menu_page; render_orders_page was replaced by EEM_Orders_List_Page::render in C5.E).

	/**
	 * Render diagnostics when Reservations or Orders resolve empty.
	 *
	 * @param string $context Screen context.
	 * @return void
	 */
	private function render_empty_data_diagnostics( $context ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$diagnostics = $this->orders_repository->get_diagnostics();
		$title       = 'reservations' === $context ? __( 'Reservations Diagnostic', 'equine-event-manager' ) : __( 'Orders Diagnostic', 'equine-event-manager' );
		?>
		<div>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						__( 'Detected reservation posts: %1$d. Stall rows: %2$d. RV rows: %3$d.', 'equine-event-manager' ),
						(int) $diagnostics['reservation_posts'],
						(int) $diagnostics['stall_rows'],
						(int) $diagnostics['rv_rows']
					)
				);
				?>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						__( 'Stall tables: %1$s. RV tables: %2$s.', 'equine-event-manager' ),
						implode( ', ', array_map( 'strval', (array) $diagnostics['stall_tables'] ) ),
						implode( ', ', array_map( 'strval', (array) $diagnostics['rv_tables'] ) )
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the reservation event overview page.
	 *
	 * @return void
	 */
	public function render_reservation_overview_page() {
		$this->guard_admin_page();

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$reservation    = $reservation_id ? get_post( $reservation_id ) : null;

		if ( ! $reservation instanceof WP_Post || 'en_reservation' !== $reservation->post_type ) {
			?>
			<div class="wrap eem-shell-wrap eem-shell-wrap--header">
				<?php $this->render_brand_banner( __( 'Event Overview', 'equine-event-manager' ) ); ?>
				<div class="eem-shell-content eem-shell-content--app">
					<div class="postbox">
						<p><?php esc_html_e( 'That reservation overview could not be loaded.', 'equine-event-manager' ); ?></p>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=en_reservation' ) ); ?>"><?php esc_html_e( 'Back to Reservations', 'equine-event-manager' ); ?></a></p>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		$overview = $this->get_reservation_overview_data( $reservation_id );
		?>
		<div class="wrap eem-shell-wrap eem-shell-wrap--header">
			<?php
			$this->render_brand_banner(
				$overview['event_label'],
				__( 'Review what has sold, what inventory remains, and which products are moving for this event.', 'equine-event-manager' )
			);
			?>
			<div class="eem-shell-content eem-shell-content--app">
				<?php $this->render_admin_notice(); ?>
				<div class="eem-shell-actions eem-shell-inline-actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=en_reservation' ) ); ?>"><?php esc_html_e( 'Back to Reservations', 'equine-event-manager' ); ?></a>
					<a class="button" href="<?php echo esc_url( get_edit_post_link( $reservation_id, '' ) ); ?>"><?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-stall-charts&reservation_id=' . $reservation_id ) ); ?>"><?php esc_html_e( 'Stall Assignments', 'equine-event-manager' ); ?></a>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_generate_stall_assignments&reservation_id=' . $reservation_id ), 'equine_event_manager_generate_stall_assignments_' . $reservation_id ) ); ?>"><?php esc_html_e( 'Generate Stall Assignments', 'equine-event-manager' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-create-order' ) ); ?>"><?php esc_html_e( 'Create Order', 'equine-event-manager' ); ?></a>
					<a class="button" target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_print_reservation_overview&reservation_id=' . $reservation_id ), 'equine_event_manager_print_reservation_overview_' . $reservation_id ) ); ?>"><?php esc_html_e( 'Print PDF', 'equine-event-manager' ); ?></a>
				</div>

				<div class="postbox">
					<div class="inside">
						<div class="eem-shell-metrics-grid">
							<div class="eem-shell-metric-card">
								<p class="eem-shell-metric-label"><?php esc_html_e( 'Orders', 'equine-event-manager' ); ?></p>
								<p class="eem-shell-metric-value"><?php echo esc_html( number_format_i18n( $overview['order_count'] ) ); ?></p>
							</div>
							<div class="eem-shell-metric-card">
								<p class="eem-shell-metric-label"><?php esc_html_e( 'Revenue', 'equine-event-manager' ); ?></p>
								<p class="eem-shell-metric-value"><?php echo esc_html( '$' . number_format_i18n( (float) $overview['revenue_total'], 2 ) ); ?></p>
							</div>
							<div class="eem-shell-metric-card">
								<p class="eem-shell-metric-label"><?php esc_html_e( 'Stalls Sold', 'equine-event-manager' ); ?></p>
								<p class="eem-shell-metric-value"><?php echo esc_html( number_format_i18n( $overview['stall_sold'] ) . ' (' . $overview['stall_remaining_label'] . ')' ); ?></p>
							</div>
							<div class="eem-shell-metric-card">
								<p class="eem-shell-metric-label"><?php esc_html_e( 'RVs Sold', 'equine-event-manager' ); ?></p>
								<p class="eem-shell-metric-value"><?php echo esc_html( number_format_i18n( $overview['rv_sold'] ) . ' (' . $overview['rv_remaining_label'] . ')' ); ?></p>
							</div>
							<div class="eem-shell-metric-card">
								<p class="eem-shell-metric-label"><?php esc_html_e( 'Rider Groups', 'equine-event-manager' ); ?></p>
								<p class="eem-shell-metric-value">
									<?php
									echo esc_html(
										sprintf(
											_n( '%d group reservation', '%d group reservations', $overview['group_reservation_count'], 'equine-event-manager' ),
											$overview['group_reservation_count']
										)
									);
									?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<div class="postbox">
					<h2><?php esc_html_e( 'Event Snapshot', 'equine-event-manager' ); ?></h2>
					<div class="inside">
						<table class="widefat striped">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></th>
									<td><?php echo esc_html( get_the_title( $reservation_id ) ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Event Source', 'equine-event-manager' ); ?></th>
									<td><?php echo esc_html( $overview['event_source_label'] ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></th>
									<td><?php echo esc_html( $overview['event_dates_label'] ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
									<td><?php echo esc_html( $overview['type_label'] ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Stall Inventory', 'equine-event-manager' ); ?></th>
									<td><?php echo esc_html( $overview['stall_inventory_label'] ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'RV Inventory', 'equine-event-manager' ); ?></th>
									<td><?php echo esc_html( $overview['rv_inventory_label'] ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="postbox">
					<h2><?php esc_html_e( 'Inventory Overview', 'equine-event-manager' ); ?></h2>
					<div class="inside">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Item', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Inventory', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Sold', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Remaining', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $overview['inventory_rows'] as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['label'] ); ?></td>
										<td><?php echo esc_html( $row['status_label'] ); ?></td>
										<?php if ( ! empty( $row['display'] ) && 'group_reservations' === $row['display'] ) : ?>
											<td><?php echo esc_html( $row['summary_label'] ); ?></td>
											<td><?php echo esc_html( $row['summary_value'] ); ?></td>
											<td><?php echo esc_html( $row['summary_meta'] ); ?></td>
										<?php else : ?>
											<td><?php echo esc_html( $row['inventory_label'] ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $row['sold'] ) ); ?></td>
											<td><?php echo esc_html( $row['remaining_label'] ); ?></td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<div class="postbox">
					<h2><?php esc_html_e( 'Product Activity', 'equine-event-manager' ); ?></h2>
					<div class="inside">
						<?php if ( empty( $overview['product_rows'] ) ) : ?>
							<p><?php esc_html_e( 'No products have been sold for this event yet.', 'equine-event-manager' ); ?></p>
						<?php else : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Product', 'equine-event-manager' ); ?></th>
										<th><?php esc_html_e( 'Category', 'equine-event-manager' ); ?></th>
										<th><?php esc_html_e( 'Sold', 'equine-event-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $overview['product_rows'] as $row ) : ?>
										<tr>
											<td><?php echo esc_html( $row['label'] ); ?></td>
											<td><?php echo esc_html( $row['category'] ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $row['sold'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>

				<div class="postbox">
					<h2><?php esc_html_e( 'Recent Orders', 'equine-event-manager' ); ?></h2>
					<div class="inside">
						<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-orders&event_id=' . $reservation_id ) ); ?>"><?php esc_html_e( 'View Recent Orders', 'equine-event-manager' ); ?></a></p>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $overview['orders'] ) ) : ?>
									<tr>
										<td colspan="4"><?php esc_html_e( 'No orders have been placed for this event yet.', 'equine-event-manager' ); ?></td>
									</tr>
								<?php else : ?>
									<?php foreach ( array_slice( $overview['orders'], 0, 8 ) as $order ) : ?>
										<tr>
											<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-order&order_key=' . rawurlencode( $order['order_key'] ) ) ); ?>">#<?php echo esc_html( $order['order_number'] ); ?></a></td>
											<td><?php echo esc_html( $order['customer_name'] ); ?></td>
											<td><?php echo $this->render_order_type_badges( $order['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td><?php echo esc_html( wp_date( 'F j, Y', strtotime( $order['created_at'] ) ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the stall chart overview page.
	 *
	 * @return void
	 */
	public function render_stall_chart_page() {
		$this->guard_admin_page();

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$reservation    = $reservation_id ? get_post( $reservation_id ) : null;

		if ( ! $reservation instanceof WP_Post || 'en_reservation' !== $reservation->post_type ) {
			$this->render_stall_charts_list_page( $reservation_id );
			return;
		}

		$config            = $this->get_stall_chart_config( $reservation_id );
		$inv               = isset( $_GET['inv'] ) ? sanitize_key( wp_unslash( $_GET['inv'] ) ) : 'all';
		$inv               = in_array( $inv, array( 'all', 'stalls', 'rv' ), true ) ? $inv : 'all';
		$tab               = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'location';
		$tab               = in_array( $tab, array( 'location', 'customer' ), true ) ? $tab : 'location';
		$reservation_title = get_the_title( $reservation_id );
		$screen_title      = sprintf(
			/* translators: %s: reservation title. */
			__( 'Stall & RV Chart - %s', 'equine-event-manager' ),
			$reservation_title
		);

		if ( empty( $config['enabled'] ) ) {
			?>
			<div class="eem-page">
			<?php
			eem_render_breadcrumb( array(
				array(
					'label' => __( 'Stall & RV Charts', 'equine-event-manager' ),
					'url'   => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
				),
				array(
					'label' => $reservation_title,
				),
			) );
			?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<h1 class="eem-plugin-title"><?php echo esc_html( $screen_title ); ?></h1>
					</div>
				</header>
				<div class="eem-stall-chart-body">
					<p><?php esc_html_e( 'Stall Assignments are currently disabled for this reservation.', 'equine-event-manager' ); ?></p>
					<div class="eem-stall-chart-action-bar">
						<div class="eem-stall-chart-action-bar-btns">
							<a class="eem-btn eem-btn--ghost" href="<?php echo esc_url( get_edit_post_link( $reservation_id, '' ) ); ?>"><?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?></a>
						</div>
					</div>
				</div>
			</div>
			</div><!-- /.eem-page -->
			<?php
			return;
		}

		$reservation_dates = $this->get_reservation_date_range_label( $reservation_id );
		?>
		<div class="eem-page">
		<?php
		eem_render_breadcrumb( array(
			array(
				'label' => __( 'Stall & RV Charts', 'equine-event-manager' ),
				'url'   => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
			),
			array(
				'label' => $reservation_title,
			),
		) );
		?>
		<div class="eem-plugin-wrap">

			<header class="eem-plugin-header">
				<div class="eem-plugin-header-left">
					<h1 class="eem-plugin-title" id="eem-header-event-name"><?php echo esc_html( $screen_title ); ?></h1>
					<div class="eem-plugin-header-meta" id="eem-header-meta">
						<?php
						if ( $reservation_dates ) {
							echo esc_html( $reservation_dates );
						}
						?>
					</div>
					<div class="eem-header-typeahead" id="eem-header-typeahead" style="display:none">
						<input class="eem-header-event-search" type="text" id="eem-header-event-input"
							placeholder="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>"
							autocomplete="off"
							data-eem-input-action="stall-chart-filter-events">
						<div class="eem-header-event-options" id="eem-stall-chart-event-results"></div>
						<button class="eem-header-cancel-btn" type="button" data-eem-action="stall-chart-cancel-change">
							<?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?>
						</button>
					</div>
				</div>
				<button class="eem-header-action-change" type="button" id="eem-stall-chart-change-btn" data-eem-action="stall-chart-change-event">
					<?php esc_html_e( 'Change Event', 'equine-event-manager' ); ?>
				</button>
			</header>

			<div class="eem-stall-chart-body">

				<?php $this->render_admin_notice(); ?>

				<div id="eem-stall-chart-dynamic">
				<?php $this->render_stall_chart_dynamic_region( $reservation_id, $config, $inv, $tab ); ?>
				</div><!-- /#eem-stall-chart-dynamic -->

			</div><!-- /eem-stall-chart-body -->

		</div><!-- /eem-plugin-wrap -->

		<?php $this->render_stall_chart_overlays( $reservation_id ); ?>
		</div><!-- /.eem-page -->
		<?php
	}

	/**
	 * Render the data-driven region of the stall chart detail page.
	 *
	 * Contains the stats bar, action bar, tabbed occupancy chart, and the
	 * Assignment Issues card — everything that changes when assignments are
	 * generated. Extracted so the auto-assign AJAX handler can re-render just
	 * this region (returned as HTML and swapped into #eem-stall-chart-dynamic)
	 * without a full page reload.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param array  $config         Chart config from get_stall_chart_config().
	 * @param string $inv            Active inventory filter (all|stalls|rv).
	 * @param string $tab            Active view tab (location|customer).
	 * @return void
	 */
	private function render_stall_chart_dynamic_region( int $reservation_id, array $config, string $inv = 'all', string $tab = 'location' ): void {
		$grid              = $this->build_stall_chart_grid( $reservation_id, $config );
		$order_rows        = $this->build_stall_chart_rows( $reservation_id, $config );
		$date_cols         = $grid['date_columns'];
		$stall_count       = count( $config['stall_units'] );
		$rv_count          = count( $config['rv_lot_names'] );
		$barn_options      = $this->get_stall_chart_block_filter_options( isset( $config['stall_blocks'] ) ? $config['stall_blocks'] : array(), isset( $config['barn_names'] ) ? $config['barn_names'] : array() );
		$rv_zone_options   = isset( $config['rv_zone_options'] ) ? $config['rv_zone_options'] : array();
		$rv_zone_map       = isset( $config['rv_zone_map'] ) ? $config['rv_zone_map'] : array();
		$reservation_dates = $this->get_reservation_date_range_label( $reservation_id );
		$reservation_title = get_the_title( $reservation_id );

		// v4 Stall Mapping: when a facility-map snapshot is connected, the
		// By-Location panel renders the spatial map (above the date matrix) and
		// the whole card shows even if the legacy row-builder inventory is empty.
		$stall_map_snapshot = class_exists( 'EEM_Stall_Map_Importer' )
			? EEM_Stall_Map_Importer::get_for_reservation( $reservation_id )
			: array();
		// v4 Slice 8: the By-Location stall map shows stall-kind tabs only; RV-kind
		// tabs feed the RV inventory/matrix instead.
		if ( ! empty( $stall_map_snapshot['barns'] ) ) {
			$stall_map_snapshot = EEM_Stall_Map_Importer::snapshot_of_kind( $stall_map_snapshot, 'stall' );
		}
		$has_stall_map = ! empty( $stall_map_snapshot['barns'] );
		$stall_map_overlay = $has_stall_map
			? $this->build_stall_map_overlay_state( $order_rows, $this->get_raw_blocked_stall_labels( $reservation_id ) )
			: array();

		// v4 RV spatial map (separate _en_rv_map connector). Every barn is an RV
		// zone; lots are zone-qualified ("Red Lot 1"). Shown when inv != stalls.
		$rv_map_snapshot = class_exists( 'EEM_Stall_Map_Importer' )
			? EEM_Stall_Map_Importer::get_for_reservation( $reservation_id, EEM_Stall_Map_Importer::RV_META_KEY )
			: array();
		$has_rv_map = ! empty( $rv_map_snapshot['barns'] );
		$rv_blocked = array();
		if ( $has_rv_map ) {
			$rv_chart_blocked = get_post_meta( $reservation_id, '_en_stall_chart_blocked_rv_lots', true );
			$rv_blocked       = array_values( array_filter( array_map( 'strval', is_array( $rv_chart_blocked ) ? $rv_chart_blocked : array() ) ) );
			foreach ( (array) ( isset( $config['blocked_rv_lots'] ) ? $config['blocked_rv_lots'] : array() ) as $brl ) {
				$brl = (string) $brl;
				if ( '' !== $brl && ! in_array( $brl, $rv_blocked, true ) ) {
					$rv_blocked[] = $brl;
				}
			}
		}
		$rv_map_overlay = $has_rv_map
			? $this->build_stall_map_overlay_state( $order_rows, $rv_blocked, 'rv_units', false )
			: array();
		?>
				<!-- Stats Bar -->
				<div class="eem-stall-chart-stats-bar">
					<div class="eem-stall-chart-stat-card eem-stall-chart-stat-card--wide">
						<div class="eem-stall-chart-stat-label"><?php esc_html_e( 'Daily Movement', 'equine-event-manager' ); ?></div>
						<div class="eem-stall-chart-stat-daily">
							<?php if ( ! empty( $grid['movement_summary'] ) ) : ?>
								<?php foreach ( $grid['movement_summary'] as $date_key => $movement ) : ?>
									<div>
										<span><?php echo esc_html( isset( $date_cols[ $date_key ] ) ? $date_cols[ $date_key ] : $date_key ); ?>:</span>
										<?php
										echo esc_html( sprintf(
											/* translators: 1: arriving count, 2: departing count */
											__( 'Arriving %1$s, Departing %2$s', 'equine-event-manager' ),
											number_format_i18n( $movement['arriving'] ),
											number_format_i18n( $movement['departing'] )
										) );
										?>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<div><?php echo esc_html( $reservation_dates ? $reservation_dates : $reservation_title ); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<div class="eem-stall-chart-stat-card">
						<div class="eem-stall-chart-stat-label"><?php esc_html_e( 'Stall Units', 'equine-event-manager' ); ?></div>
						<div class="eem-stall-chart-stat-value"><?php echo esc_html( number_format_i18n( $stall_count ) ); ?></div>
					</div>
					<div class="eem-stall-chart-stat-card">
						<div class="eem-stall-chart-stat-label"><?php esc_html_e( 'RV Lots', 'equine-event-manager' ); ?></div>
						<div class="eem-stall-chart-stat-value"><?php echo esc_html( number_format_i18n( $rv_count ) ); ?></div>
					</div>
					<div class="eem-stall-chart-stat-card">
						<div class="eem-stall-chart-stat-label"><?php esc_html_e( 'Orders on Chart', 'equine-event-manager' ); ?></div>
						<div class="eem-stall-chart-stat-value"><?php echo esc_html( number_format_i18n( $grid['order_count'] ) ); ?></div>
					</div>
				</div>

				<!-- Action Bar -->
				<div class="eem-stall-chart-action-bar">
					<div class="eem-stall-chart-action-bar-btns">
						<button class="eem-btn eem-btn--primary" type="button" data-eem-action="stall-chart-auto-assign-all">
							<?php esc_html_e( 'Generate Assignments', 'equine-event-manager' ); ?>
						</button>
						<button class="eem-btn eem-btn--ghost" type="button"
							data-eem-action="stall-chart-print"
							data-print-url="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-stall-chart-print&reservation_id=' . $reservation_id . '&view=' . ( 'customer' === $tab ? 'customer' : 'location' ) ) ); ?>">
							<?php esc_html_e( 'Print View', 'equine-event-manager' ); ?>
						</button>
						<a class="eem-btn eem-btn--ghost" href="<?php echo esc_url( get_edit_post_link( $reservation_id, '' ) ); ?>">
							<?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?>
						</a>
					</div>
				</div>

				<?php $eem_unsaved = isset( $grid['unsaved_order_count'] ) ? (int) $grid['unsaved_order_count'] : 0; ?>
				<?php if ( $eem_unsaved > 0 ) : ?>
					<!-- Unsaved auto-suggested layout banner -->
					<div class="eem-stall-chart-unsaved-banner" role="status">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
						<span>
							<strong><?php echo esc_html( sprintf( /* translators: %d: number of orders */ _n( '%d order has a suggested stall that isn\'t saved yet.', '%d orders have suggested stalls that aren\'t saved yet.', $eem_unsaved, 'equine-event-manager' ), $eem_unsaved ) ); ?></strong>
							<?php esc_html_e( 'The placements shown below are an auto-suggested layout. Click', 'equine-event-manager' ); ?>
							<button type="button" class="eem-link-btn" data-eem-action="stall-chart-auto-assign-all"><?php esc_html_e( 'Generate Assignments', 'equine-event-manager' ); ?></button>
							<?php esc_html_e( 'to save this layout, or click a customer\'s name to place them manually.', 'equine-event-manager' ); ?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( empty( $grid['stall_rows'] ) && empty( $grid['rv_rows'] ) && ! $has_stall_map ) : ?>
					<div class="eem-stall-chart-empty-card">
						<p><?php esc_html_e( 'No paid or reserved orders are currently linked to this reservation.', 'equine-event-manager' ); ?></p>
					</div>
				<?php else : ?>

					<!-- Tabbed View Card -->
					<div class="eem-stall-chart-view-tabs-card" id="eem-stall-chart-view-tabs-card">

						<!-- INVENTORY TOGGLE BAR (top) -->
						<div class="eem-sc-inv-bar">
							<div class="eem-sc-inv-toggle" role="group" aria-label="<?php esc_attr_e( 'Inventory type', 'equine-event-manager' ); ?>">
								<button class="eem-sc-inv-btn<?php echo 'all' === $inv ? ' active' : ''; ?>" type="button"
									data-eem-action="sc-inv-switch" data-inv="all">
									<?php esc_html_e( 'All', 'equine-event-manager' ); ?>
								</button>
								<button class="eem-sc-inv-btn<?php echo 'stalls' === $inv ? ' active' : ''; ?>" type="button"
									data-eem-action="sc-inv-switch" data-inv="stalls">
									<?php esc_html_e( 'Stalls', 'equine-event-manager' ); ?>
								</button>
								<button class="eem-sc-inv-btn<?php echo 'rv' === $inv ? ' active' : ''; ?>" type="button"
									data-eem-action="sc-inv-switch" data-inv="rv">
									<?php esc_html_e( 'RV', 'equine-event-manager' ); ?>
								</button>
							</div>
						</div>

						<!-- VIEW TABS (By Location / By Customer) -->
						<div class="eem-stall-chart-view-tabs" id="eem-stall-chart-view-tabs" role="tablist">
							<button class="eem-stall-chart-view-tab<?php echo 'location' === $tab ? ' active' : ''; ?>" type="button"
								data-eem-action="stall-chart-switch-view"
								data-view="location"
								role="tab"
								aria-selected="<?php echo 'location' === $tab ? 'true' : 'false'; ?>"
								aria-controls="eem-stall-chart-panel-location">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
								<?php esc_html_e( 'By Location', 'equine-event-manager' ); ?>
							</button>
							<button class="eem-stall-chart-view-tab<?php echo 'customer' === $tab ? ' active' : ''; ?>" type="button"
								data-eem-action="stall-chart-switch-view"
								data-view="customer"
								role="tab"
								aria-selected="<?php echo 'customer' === $tab ? 'true' : 'false'; ?>"
								aria-controls="eem-stall-chart-panel-customer">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
								<?php esc_html_e( 'By Customer', 'equine-event-manager' ); ?>
							</button>
						</div>

						<!-- BY LOCATION PANEL -->
						<div class="eem-stall-chart-tab-panel<?php echo 'location' === $tab ? ' active' : ''; ?>" id="eem-stall-chart-panel-location" role="tabpanel"<?php echo 'customer' === $tab ? ' style="display:none"' : ''; ?>>

							<!-- Tip banner -->
							<div class="eem-stall-chart-help">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
								<span><strong><?php esc_html_e( 'Tip:', 'equine-event-manager' ); ?></strong> <?php esc_html_e( 'Click any customer name to view their order or move them to a different stall. A dashed outline means the placement is auto-suggested and not saved yet — Generate Assignments saves it.', 'equine-event-manager' ); ?></span>
							</div>

							<?php
							// v4 Stall Mapping: spatial facility maps. Wrapped in
							// data-inv-section so the All/Stalls/RV toggle hides them
							// client-side too (the JS filter keys off data-inv-section).
							if ( $has_stall_map ) :
								?>
								<div data-inv-section="stalls"<?php echo 'rv' === $inv ? ' style="display:none"' : ''; ?>>
									<?php $this->render_stall_map_location_view( $reservation_id, $stall_map_snapshot, $stall_map_overlay, 'stall' ); ?>
								</div>
								<?php
							endif;
							if ( $has_rv_map ) :
								?>
								<div data-inv-section="rv"<?php echo 'stalls' === $inv ? ' style="display:none"' : ''; ?>>
									<?php $this->render_stall_map_location_view( $reservation_id, $rv_map_snapshot, $rv_map_overlay, 'rv' ); ?>
								</div>
								<?php
							endif;
							?>

							<!-- FILTER ROW -->
							<div class="eem-stall-chart-filter-row">

								<?php
								// Barn tabs: visible only in stalls mode (and only when barn options exist).
								$show_barn_tabs = ( 'stalls' === $inv && ! empty( $barn_options ) );
								?>
								<div class="eem-stall-chart-barn-tabs" id="eem-sc-barn-tabs"<?php echo $show_barn_tabs ? '' : ' style="display:none"'; ?>>
									<button class="eem-stall-chart-barn-tab active" type="button"
										data-eem-action="stall-chart-filter-barn"
										data-barn="all">
										<?php esc_html_e( 'All Barns', 'equine-event-manager' ); ?>
									</button>
									<?php foreach ( $barn_options as $barn_label ) : ?>
										<button class="eem-stall-chart-barn-tab" type="button"
											data-eem-action="stall-chart-filter-barn"
											data-barn="<?php echo esc_attr( sanitize_html_class( strtolower( $barn_label ) ) ); ?>">
											<?php echo esc_html( $barn_label ); ?>
										</button>
									<?php endforeach; ?>
								</div>

								<?php
								// Zone tabs: visible only in rv mode (and only when zone options exist).
								$show_zone_tabs = ( 'rv' === $inv && ! empty( $rv_zone_options ) );
								?>
								<div class="eem-stall-chart-zone-tabs" id="eem-sc-zone-tabs"<?php echo $show_zone_tabs ? '' : ' style="display:none"'; ?>>
									<button class="eem-stall-chart-zone-tab active" type="button"
										data-eem-action="stall-chart-filter-zone"
										data-zone="all">
										<?php esc_html_e( 'All Zones', 'equine-event-manager' ); ?>
									</button>
									<?php foreach ( $rv_zone_options as $zone_label ) : ?>
										<button class="eem-stall-chart-zone-tab" type="button"
											data-eem-action="stall-chart-filter-zone"
											data-zone="<?php echo esc_attr( sanitize_html_class( strtolower( $zone_label ) ) ); ?>">
											<?php echo esc_html( $zone_label ); ?>
										</button>
									<?php endforeach; ?>
								</div>

								<div class="eem-stall-chart-filter-search">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
									<input type="search" id="eem-stall-chart-search" class="eem-search-input eem-stall-chart-search-input" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" />
								</div>
								<span class="eem-stall-chart-filter-hint"><?php esc_html_e( 'Search by customer name, stall number, RV lot, or block title.', 'equine-event-manager' ); ?></span>
							</div>
							<p class="eem-stall-chart-empty-note" hidden><?php esc_html_e( 'No assignment rows match this search.', 'equine-event-manager' ); ?></p>

							<?php if ( ! empty( $grid['stall_rows'] ) && ! empty( $grid['rv_rows'] ) && 'all' === $inv ) : ?>
								<!-- Section divider: Stall Units (only in "All" mode when both types present) -->
								<div class="eem-sc-section-divider">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
									<?php esc_html_e( 'Stall Units', 'equine-event-manager' ); ?>
								</div>
							<?php endif; ?>

							<!-- STALL SECTION: hidden when inv=rv -->
							<div id="eem-sc-loc-stalls" data-inv-section="stalls"<?php echo 'rv' === $inv ? ' style="display:none"' : ''; ?>>
								<?php if ( ! empty( $grid['stall_rows'] ) ) : ?>
									<?php
									// Barn/Row names are optional. When every stall row is unnamed the
									// chart is "by number" â drop the Block column + barn dividers.
									$eem_has_block = false;
									foreach ( (array) $grid['stall_rows'] as $eem_sr ) {
										if ( '' !== trim( (string) ( $eem_sr['block'] ?? '' ) ) ) { $eem_has_block = true; break; }
									}
									?>
									<?php $this->render_stall_chart_matrix_table( $grid['stall_rows'], $date_cols, __( 'Stall', 'equine-event-manager' ), $eem_has_block ? __( 'Block', 'equine-event-manager' ) : '', array(), 'stall' ); ?>
								<?php else : ?>
									<p class="eem-stall-chart-no-data"><?php esc_html_e( 'No stall assignments configured for this reservation.', 'equine-event-manager' ); ?></p>
								<?php endif; ?>
							</div>

							<?php if ( ! empty( $grid['stall_rows'] ) && ! empty( $grid['rv_rows'] ) && 'all' === $inv ) : ?>
								<!-- Section divider: RV Lots (only in "All" mode when both types present) -->
								<div class="eem-sc-section-divider">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
									<?php esc_html_e( 'RV Lots', 'equine-event-manager' ); ?>
								</div>
							<?php endif; ?>

							<!-- RV SECTION: hidden when inv=stalls -->
							<div id="eem-sc-loc-rv" data-inv-section="rv"<?php echo 'stalls' === $inv ? ' style="display:none"' : ''; ?>>
								<?php if ( ! empty( $grid['rv_rows'] ) ) : ?>
									<?php $this->render_stall_chart_matrix_table( $grid['rv_rows'], $date_cols, __( 'RV Lot', 'equine-event-manager' ), '', $rv_zone_map, 'rv' ); ?>
								<?php else : ?>
									<p class="eem-stall-chart-no-data"><?php esc_html_e( 'No RV lot assignments configured for this reservation.', 'equine-event-manager' ); ?></p>
								<?php endif; ?>
							</div>

						</div><!-- /panel-location -->

						<!-- BY CUSTOMER PANEL -->
						<div class="eem-stall-chart-tab-panel<?php echo 'customer' === $tab ? ' active' : ''; ?>" id="eem-stall-chart-panel-customer" role="tabpanel"<?php echo 'location' === $tab ? ' style="display:none"' : ''; ?>>

							<!-- Tip banner -->
							<div class="eem-stall-chart-help">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
								<span><strong><?php esc_html_e( 'Tip:', 'equine-event-manager' ); ?></strong> <?php esc_html_e( 'Click any customer name to open the customer profile, or click an order number to view the order.', 'equine-event-manager' ); ?></span>
							</div>

							<!-- Filter row -->
							<div class="eem-stall-chart-filter-row">
								<div class="eem-stall-chart-filter-search">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
									<input type="search" id="eem-stall-chart-cust-search" class="eem-search-input eem-stall-chart-search-input" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" />
								</div>
								<?php // V1 D2 — filters the list to orders that carry a Group Name. ?>
								<button type="button" class="eem-stall-chart-group-toggle" data-eem-action="stall-chart-toggle-groups" aria-pressed="false">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
									<?php esc_html_e( 'Show by group', 'equine-event-manager' ); ?>
								</button>
								<?php // V1 #5 — filters the list to orders that have a tack stall. ?>
								<button type="button" class="eem-stall-chart-group-toggle eem-stall-chart-tack-toggle" data-eem-action="stall-chart-toggle-tack" aria-pressed="false">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
									<?php esc_html_e( 'Tack Stalls', 'equine-event-manager' ); ?>
								</button>
								<span class="eem-stall-chart-filter-hint"><?php esc_html_e( 'Search by customer, order, stall, or RV lot.', 'equine-event-manager' ); ?></span>
							</div>
							<p class="eem-stall-chart-empty-note" hidden><?php esc_html_e( 'No assignment rows match this search.', 'equine-event-manager' ); ?></p>

							<?php
							// v4 Slice 6 — group reconciliation. Distinct group names across
							// this reservation's orders, with an inline rename so the admin can
							// fold a misspelled variant into the canonical group.
							$eem_group_counts = array();
							foreach ( $order_rows as $eem_or ) {
								$eem_g = trim( (string) ( isset( $eem_or['group_name'] ) ? $eem_or['group_name'] : '' ) );
								if ( '' !== $eem_g ) {
									$eem_group_counts[ $eem_g ] = ( isset( $eem_group_counts[ $eem_g ] ) ? $eem_group_counts[ $eem_g ] : 0 ) + 1;
								}
							}
							uksort( $eem_group_counts, 'strcasecmp' );
							if ( ! empty( $eem_group_counts ) ) :
							?>
							<div class="eem-group-manage" data-eem-group-manage data-reservation-id="<?php echo (int) $reservation_id; ?>">
								<button type="button" class="eem-stall-chart-group-toggle" data-eem-action="group-manage-toggle" aria-expanded="false">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
									<?php esc_html_e( 'Manage Groups', 'equine-event-manager' ); ?>
								</button>
								<div class="eem-group-manage-panel" hidden>
									<p class="eem-group-manage-hint"><?php esc_html_e( 'Rename a group to merge misspelled variants — every order in the group is updated. The admin-set name is the source of truth.', 'equine-event-manager' ); ?></p>
									<?php foreach ( $eem_group_counts as $eem_gname => $eem_gcount ) : ?>
									<div class="eem-group-manage-row" data-group-from="<?php echo esc_attr( (string) $eem_gname ); ?>">
										<input type="text" class="eem-group-manage-input" value="<?php echo esc_attr( (string) $eem_gname ); ?>" data-role="group-name" aria-label="<?php esc_attr_e( 'Group name', 'equine-event-manager' ); ?>">
										<span class="eem-group-manage-count"><?php echo esc_html( sprintf( /* translators: %d: order count */ _n( '%d order', '%d orders', (int) $eem_gcount, 'equine-event-manager' ), (int) $eem_gcount ) ); ?></span>
										<button type="button" class="eem-btn-add" data-eem-action="group-rename-save"><?php esc_html_e( 'Save', 'equine-event-manager' ); ?></button>
									</div>
									<?php endforeach; ?>
								</div>
							</div>
							<?php endif; ?>

							<?php $this->render_stall_chart_order_count_table( $order_rows, $date_cols ); ?>

						</div><!-- /panel-customer -->

					</div><!-- /eem-stall-chart-view-tabs-card -->

				<?php endif; ?>

				<?php if ( ! empty( $grid['issues'] ) ) : ?>
					<!-- Assignment Issues Card -->
					<div class="eem-stall-chart-issues-card">
						<div class="eem-stall-chart-issues-header">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
							<span class="eem-stall-chart-issues-title"><?php esc_html_e( 'Assignment Issues', 'equine-event-manager' ); ?></span>
							<span class="eem-stall-chart-issues-count">
								<?php
								echo esc_html( sprintf(
									/* translators: %d: number of issues */
									_n( '%d issue', '%d issues', count( $grid['issues'] ), 'equine-event-manager' ),
									count( $grid['issues'] )
								) );
								?>
							</span>
							<button class="eem-btn eem-btn--primary eem-stall-chart-issues-auto-all" type="button" data-eem-action="stall-chart-auto-assign-all">
								<?php esc_html_e( 'Auto-Assign All', 'equine-event-manager' ); ?>
							</button>
						</div>
						<div class="eem-stall-chart-issues-body">
							<?php foreach ( $grid['issues'] as $issue ) : ?>
								<div class="eem-stall-chart-issue-row">
									<span class="eem-stall-chart-issue-text"><?php echo esc_html( is_array( $issue ) ? $issue['text'] : $issue ); ?></span>
									<?php if ( is_array( $issue ) && ! empty( $issue['order_key'] ) ) : ?>
										<div class="eem-stall-chart-issue-actions">
											<button class="eem-btn eem-btn--ghost eem-stall-chart-issue-auto-btn" type="button" data-eem-action="stall-chart-auto-assign-order" data-order-key="<?php echo esc_attr( $issue['order_key'] ); ?>">
												<?php esc_html_e( 'Auto-Assign', 'equine-event-manager' ); ?>
											</button>
											<a class="eem-stall-chart-issue-view-link" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-order&order_key=' . rawurlencode( $issue['order_key'] ) ) ); ?>">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
												<?php esc_html_e( 'View Order', 'equine-event-manager' ); ?>
											</a>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
		<?php
	}

	/**
	 * Group accent palette for the By-Location map (matches stall_map_admin.html).
	 *
	 * Distinct group names are assigned a color by their position in the sorted
	 * group list so the same group reads the same color across barns and reloads.
	 *
	 * @var string[]
	 */
	private const STALL_MAP_GROUP_COLORS = array( '#DC2626', '#16A34A', '#9333EA', '#CA8A04', '#2563EB', '#DB2777', '#0891B2', '#65A30D' );

	/**
	 * Format a customer's display name as "Last, First" (plugin-wide convention).
	 *
	 * Splits on the last whitespace run: everything after it is the surname,
	 * everything before is the given name(s). Single-token names (businesses,
	 * mononyms) and already-comma-formatted names are returned unchanged.
	 *
	 * Public + static so admin list/table surfaces across the plugin share one
	 * implementation. NOT for customer-facing greetings (emails keep "Hi James").
	 *
	 * @param string $name Raw customer name (typically "First Last").
	 * @return string "Last, First", or the input unchanged when it can't be split.
	 */
	public static function format_customer_last_first( string $name ): string {
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		if ( '' === $name || false !== strpos( $name, ',' ) ) {
			return $name;
		}
		$pos = strrpos( $name, ' ' );
		if ( false === $pos ) {
			return $name;
		}
		$first = trim( substr( $name, 0, $pos ) );
		$last  = trim( substr( $name, $pos + 1 ) );
		return ( '' !== $last && '' !== $first ) ? $last . ', ' . $first : $name;
	}

	/**
	 * Build the per-stall-label overlay state for the By-Location map.
	 *
	 * Reads each order's *current* assigned stall labels (from the canonical
	 * `Assigned Stall Units:` note), marks them reserved (or tack when the label
	 * is in the order's `Tack Stalls:` note), tags the assigned customer +
	 * group, then overlays blocked labels that aren't otherwise assigned. The
	 * customer roster (for the assign typeahead) and group→color map are derived
	 * from the same order rows so the map is self-consistent without depending on
	 * the legacy stall-row inventory ($config['stall_units']) — map-connected
	 * reservations draw inventory from the snapshot, not the row builder.
	 *
	 * @param array $order_rows Rows from build_stall_chart_rows().
	 * @param array $blocked    Blocked stall labels (config['blocked_stall_units']).
	 * @return array{state:array<string,array>,groups:array<string,string>,customers:array<int,array>}
	 */
	private function build_stall_map_overlay_state( array $order_rows, array $blocked, string $units_key = 'stall_units', bool $with_tack = true ): array {
		$state     = array();
		$customers = array();
		$group_set = array();

		foreach ( $order_rows as $row ) {
			$cust  = self::format_customer_last_first( (string) ( isset( $row['customer_name'] ) ? $row['customer_name'] : '' ) );
			$group = trim( (string) ( isset( $row['group_name'] ) ? $row['group_name'] : '' ) );
			$okey  = (string) ( isset( $row['order_key'] ) ? $row['order_key'] : '' );
			$onum  = (string) ( isset( $row['order_number'] ) ? $row['order_number'] : '' );
			$tack  = $with_tack ? array_map( 'strval', (array) ( isset( $row['tack_units'] ) ? $row['tack_units'] : array() ) ) : array();

			if ( '' !== $group ) {
				$group_set[ $group ] = true;
			}

			$customers[] = array(
				'o' => $okey,
				'n' => '' !== $cust ? $cust : $onum,
			);

			foreach ( (array) ( isset( $row[ $units_key ] ) ? $row[ $units_key ] : array() ) as $label ) {
				$label = (string) $label;
				if ( '' === $label ) {
					continue;
				}
				$state[ $label ] = array(
					's' => ( $with_tack && in_array( $label, $tack, true ) ) ? 'tack' : 'reserved',
					'c' => $cust,
					'g' => $group,
					'o' => $okey,
					'n' => $onum,
				);
			}
		}

		foreach ( (array) $blocked as $label ) {
			$label = (string) $label;
			if ( '' !== $label && ! isset( $state[ $label ] ) ) {
				$state[ $label ] = array( 's' => 'blocked' );
			}
		}

		// Distinct groups → stable color by sorted position.
		$group_names = array_keys( $group_set );
		sort( $group_names, SORT_NATURAL | SORT_FLAG_CASE );
		$groups = array();
		foreach ( $group_names as $i => $gname ) {
			$groups[ $gname ] = self::STALL_MAP_GROUP_COLORS[ $i % count( self::STALL_MAP_GROUP_COLORS ) ];
		}
		foreach ( $state as $label => &$s ) {
			if ( ! empty( $s['g'] ) && isset( $groups[ $s['g'] ] ) ) {
				$s['gc'] = $groups[ $s['g'] ];
			}
		}
		unset( $s );

		// Dedupe + sort the customer roster (Last, First) for the typeahead.
		$seen = array();
		$roster = array();
		foreach ( $customers as $c ) {
			$k = $c['o'] . '|' . $c['n'];
			if ( '' === $c['o'] || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$roster[]   = $c;
		}
		usort( $roster, static function ( $a, $b ) {
			return strcasecmp( $a['n'], $b['n'] );
		} );

		return array(
			'state'     => $state,
			'groups'    => $groups,
			'customers' => $roster,
		);
	}

	/**
	 * Render the By-Location facility MAP for a map-connected reservation.
	 *
	 * Emits the per-barn stats strip, barn tabs, legend, an empty grid container
	 * the client renders into, the click-popover shell, and a JSON payload
	 * (barns + per-label state + groups + customer roster) consumed by
	 * EEM.renderStallMaps() in admin.js. The spatial render (same-label
	 * rectangle merge + vertical text for tall-narrow blocks) and the
	 * assign/tack/block popover live client-side so they survive the
	 * auto-assign dynamic-region swaps.
	 *
	 * @param int   $reservation_id Reservation post ID.
	 * @param array $snapshot       Stall-map snapshot (EEM_Stall_Map_Importer shape).
	 * @param array $overlay        build_stall_map_overlay_state() result.
	 * @return void
	 */
	private function render_stall_map_location_view( int $reservation_id, array $snapshot, array $overlay, string $kind = 'stall' ): void {
		$barns = isset( $snapshot['barns'] ) ? (array) $snapshot['barns'] : array();
		if ( empty( $barns ) ) {
			return;
		}
		$is_rv = 'rv' === $kind;            // RV lots are zone-qualified ("Red Lot 1").
		$noun  = $is_rv ? __( 'lot', 'equine-event-manager' ) : __( 'stall', 'equine-event-manager' );

		$status_map = array();
		foreach ( $overlay['state'] as $label => $s ) {
			$status_map[ $label ] = isset( $s['s'] ) ? $s['s'] : 'available';
		}
		$barn_stats = class_exists( 'EEM_Stall_Map_Importer' )
			? EEM_Stall_Map_Importer::barn_stats( $snapshot, $status_map, $is_rv )
			: array();

		// Compact barn payload: grid cells as {t,l} (type letter + label).
		$payload_barns = array();
		foreach ( $barns as $barn ) {
			$grid_out = array();
			foreach ( (array) ( isset( $barn['grid'] ) ? $barn['grid'] : array() ) as $grow ) {
				$row_out = array();
				foreach ( (array) $grow as $cell ) {
					$type = isset( $cell['type'] ) ? $cell['type'] : 'gap';
					$row_out[] = array(
						't' => 'stall' === $type ? 's' : ( 'landmark' === $type ? 'l' : 'g' ),
						'l' => isset( $cell['label'] ) ? (string) $cell['label'] : '',
					);
				}
				$grid_out[] = $row_out;
			}
			$payload_barns[] = array(
				'name' => (string) ( isset( $barn['name'] ) ? $barn['name'] : '' ),
				'grid' => $grid_out,
			);
		}

		$payload = array(
			'barns'         => $payload_barns,
			'state'         => (object) $overlay['state'],
			'groups'        => (object) $overlay['groups'],
			'customers'     => $overlay['customers'],
			'zoneQualified' => $is_rv,
			'kind'          => $kind,
		);
		?>
		<div class="eem-smap" data-eem-smap data-eem-smap-kind="<?php echo esc_attr( $kind ); ?>">
			<?php if ( ! empty( $barn_stats ) ) : ?>
				<div class="eem-smap-barn-stats" data-eem-smap-barn-stats>
					<?php foreach ( $barn_stats as $bname => $bs ) : ?>
						<div class="eem-smap-barn-stat">
							<div class="eem-smap-barn-stat-name"><?php echo esc_html( (string) $bname ); ?></div>
							<div class="eem-smap-barn-stat-nums">
								<span><strong><?php echo esc_html( number_format_i18n( (int) $bs['total'] ) ); ?></strong> <?php esc_html_e( 'total', 'equine-event-manager' ); ?></span>
								<span class="eem-smap-stat-avail"><strong><?php echo esc_html( number_format_i18n( (int) $bs['available'] ) ); ?></strong> <?php esc_html_e( 'avail', 'equine-event-manager' ); ?></span>
								<span class="eem-smap-stat-resv"><strong><?php echo esc_html( number_format_i18n( (int) $bs['reserved'] ) ); ?></strong> <?php esc_html_e( 'assigned', 'equine-event-manager' ); ?></span>
								<span class="eem-smap-stat-tack"><strong><?php echo esc_html( number_format_i18n( (int) $bs['tack'] ) ); ?></strong> <?php esc_html_e( 'tack', 'equine-event-manager' ); ?></span>
								<span class="eem-smap-stat-block"><strong><?php echo esc_html( number_format_i18n( (int) $bs['blocked'] ) ); ?></strong> <?php esc_html_e( 'blocked', 'equine-event-manager' ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="eem-smap-barn-tabs" data-eem-smap-tabs></div>

			<div class="eem-smap-legend">
				<span><i class="eem-smap-sw eem-smap-sw--avail"></i> <?php esc_html_e( 'Available', 'equine-event-manager' ); ?></span>
				<span><i class="eem-smap-sw eem-smap-sw--resv"></i> <?php esc_html_e( 'Assigned', 'equine-event-manager' ); ?></span>
				<?php if ( ! $is_rv ) : ?>
					<span><i class="eem-smap-sw eem-smap-sw--tack"></i> <?php esc_html_e( 'Tack', 'equine-event-manager' ); ?></span>
				<?php endif; ?>
				<span><i class="eem-smap-sw eem-smap-sw--block"></i> <?php esc_html_e( 'Blocked', 'equine-event-manager' ); ?></span>
				<span class="eem-smap-legend-hint"><?php echo esc_html( $is_rv ? __( 'Click any lot to assign or block.', 'equine-event-manager' ) : __( 'Click any stall to assign, mark tack, or block.', 'equine-event-manager' ) ); ?></span>
			</div>

			<div class="eem-smap-zoombar">
				<span class="eem-smap-zoom" data-eem-smap-zoom>
					<button type="button" data-zoom="fit" title="<?php esc_attr_e( 'Fit the whole facility', 'equine-event-manager' ); ?>"><?php esc_html_e( 'Fit', 'equine-event-manager' ); ?></button>
					<button type="button" data-zoom="2x" title="<?php esc_attr_e( '2× detail', 'equine-event-manager' ); ?>">2&times;</button>
					<button type="button" data-zoom="3x" title="<?php esc_attr_e( '3× detail', 'equine-event-manager' ); ?>">3&times;</button>
				</span>
			</div>

			<div class="eem-smap-scroll" data-eem-smap-scroll><div class="eem-smap-grid" data-eem-smap-grid></div></div>

			<script type="application/json" data-eem-smap-payload><?php echo wp_json_encode( $payload ); ?></script>
		</div>

		<div class="eem-smap-pop" data-eem-smap-pop>
			<div class="eem-smap-pop-head">
				<div class="eem-smap-pop-num" data-eem-smap-pop-num></div>
				<div class="eem-smap-pop-st" data-eem-smap-pop-st></div>
			</div>
			<div class="eem-smap-pop-body" data-eem-smap-pop-body></div>
		</div>
		<?php
	}

	/**
	 * Acquire the per-reservation assignment lock (shared with customer checkout).
	 *
	 * Every admin assignment write for a reservation — manual map assign/unassign,
	 * drag-move, auto-generate, and the Order Detail assignment form — serializes
	 * behind this MySQL advisory lock, which is the SAME key the customer checkout
	 * path uses (`eem_checkout_{reservation_id}`). Holding it across a handler's
	 * conflict re-check AND the write that follows makes the pair atomic, so two
	 * concurrent admin assigns (or an admin assign racing a checkout) can never
	 * both pass their availability check and double-book the same stall / RV lot.
	 *
	 * The lock is connection-scoped: MySQL auto-releases it when the request ends,
	 * so the `wp_send_json_*` / redirect exit paths (which terminate the request)
	 * release it implicitly even without an explicit {@see release_assignment_lock}.
	 *
	 * @param int $reservation_id Reservation whose inventory is being mutated.
	 * @return bool True if the lock was acquired (or none is needed for id 0);
	 *              false on a 15s wait timeout (another assign is mid-flight).
	 */
	private function acquire_assignment_lock( int $reservation_id ): bool {
		if ( $reservation_id < 1 ) {
			return true;
		}
		global $wpdb;
		$got = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'eem_checkout_' . $reservation_id, 15 )
		);
		return 1 === $got;
	}

	/**
	 * Release the per-reservation assignment lock from {@see acquire_assignment_lock}.
	 *
	 * @param int $reservation_id Reservation whose lock to release.
	 * @return void
	 */
	private function release_assignment_lock( int $reservation_id ): void {
		if ( $reservation_id < 1 ) {
			return;
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'eem_checkout_' . $reservation_id )
		);
	}

	/**
	 * Standard "another assignment is finishing" 409 for AJAX handlers that fail
	 * to acquire {@see acquire_assignment_lock}. Ends the request.
	 *
	 * @return void
	 */
	private function send_assignment_lock_busy(): void {
		wp_send_json_error( array(
			'message' => __( 'Another assignment for this event is still finishing. Please wait a moment and try again.', 'equine-event-manager' ),
		), 409 );
	}

	/**
	 * AJAX: mutate a single stall from the By-Location map popover.
	 *
	 * Sub-actions (op): assign | unassign | block | unblock | tack | untack.
	 * Assignment reuses the canonical per-order `Assigned Stall Units:` note
	 * (no new data model); block/unblock toggles the reservation's
	 * `_en_stall_chart_blocked_stall_units` meta; tack/untack toggles the order's
	 * `Tack Stalls:` note. On success the freshly-rendered dynamic chart region
	 * is returned so the client swaps #eem-stall-chart-dynamic and re-inits the
	 * map. Reuses the eem_stall_chart_move nonce already on the page.
	 *
	 * @return void
	 */
	public function ajax_stall_map_action() {
		check_ajax_referer( 'eem_stall_chart_move', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$op             = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$stall          = isset( $_POST['stall'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['stall'] ) ) : '';
		$order_key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['order_key'] ) ) : '';
		$inv            = isset( $_POST['inv'] ) ? sanitize_key( wp_unslash( $_POST['inv'] ) ) : 'all';
		$inv            = in_array( $inv, array( 'all', 'stalls', 'rv' ), true ) ? $inv : 'all';
		$tab            = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'location';
		$tab            = in_array( $tab, array( 'location', 'customer' ), true ) ? $tab : 'location';

		if ( $reservation_id < 1 || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}
		if ( '' === $op || '' === $stall ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'equine-event-manager' ) ), 400 );
		}

		// v4: which map — stall or the (zone-qualified) RV map. Picks the note
		// component + assignment label + chart-blocked meta key. RV has no tack.
		$kind         = ( isset( $_POST['kind'] ) && 'rv' === sanitize_key( wp_unslash( $_POST['kind'] ) ) ) ? 'rv' : 'stall';
		$is_rv        = 'rv' === $kind;
		$note_comp    = $is_rv ? 'rv' : 'stall';
		$note_label   = $is_rv ? 'Assigned RV Lots' : 'Assigned Stall Units';
		$blocked_meta = $is_rv ? '_en_stall_chart_blocked_rv_lots' : '_en_stall_chart_blocked_stall_units';
		$unit_noun    = $is_rv ? __( 'lot', 'equine-event-manager' ) : __( 'stall', 'equine-event-manager' );

		if ( $is_rv && in_array( $op, array( 'tack', 'untack' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tack does not apply to RV lots.', 'equine-event-manager' ) ), 400 );
		}

		// Serialize the conflict re-check + write below behind the per-reservation
		// lock so concurrent map actions / auto-assigns / checkouts can't double-book.
		if ( ! $this->acquire_assignment_lock( $reservation_id ) ) {
			$this->send_assignment_lock_busy();
		}

		// Helper: build the (stall_csv, rv_csv) pair for an order, modifying only
		// the active component's list and preserving the other.
		$build_pair = function ( array $order, array $active_units ) use ( $is_rv ) {
			if ( $is_rv ) {
				$stall_csv = implode( ', ', array_filter( array_map( 'strval', (array) $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' ) ) ) ) );
				$rv_csv    = implode( ', ', $active_units );
			} else {
				$stall_csv = implode( ', ', $active_units );
				$rv_csv    = implode( ', ', array_filter( array_map( 'strval', (array) $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) ) ) ) );
			}
			return array( $stall_csv, $rv_csv );
		};

		$message = '';

		if ( 'block' === $op || 'unblock' === $op ) {
			$blocked = get_post_meta( $reservation_id, $blocked_meta, true );
			$blocked = array_values( array_filter( array_map( 'strval', is_array( $blocked ) ? $blocked : array() ) ) );

			if ( 'block' === $op ) {
				// Refuse to block a unit that's currently assigned.
				$orders = $this->get_reservation_orders( $reservation_id );
				foreach ( $orders as $o ) {
					$units = array_map( 'strval', (array) $this->parse_assigned_units_string(
						$this->get_order_component_note_value( $o, $note_comp, $note_label )
					) );
					if ( in_array( $stall, $units, true ) ) {
						wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot */ __( 'Unassign this %s before blocking it.', 'equine-event-manager' ), $unit_noun ) ), 409 );
					}
				}
				if ( ! in_array( $stall, $blocked, true ) ) {
					$blocked[] = $stall;
				}
				$message = $is_rv ? __( 'Lot blocked.', 'equine-event-manager' ) : __( 'Stall blocked.', 'equine-event-manager' );
			} else {
				$blocked = array_values( array_diff( $blocked, array( $stall ) ) );
				// Stalls: also clear the editor's Blocked Stall Numbers field so a
				// stall blocked there can be unblocked from the map.
				if ( ! $is_rv ) {
					$editor_blocked = get_post_meta( $reservation_id, '_en_blocked_stalls', true );
					if ( is_array( $editor_blocked ) ) {
						$editor_blocked = array_values( array_diff( array_map( 'strval', $editor_blocked ), array( $stall ) ) );
						update_post_meta( $reservation_id, '_en_blocked_stalls', $editor_blocked );
					}
				}
				$message = $is_rv ? __( 'Lot unblocked.', 'equine-event-manager' ) : __( 'Stall unblocked.', 'equine-event-manager' );
			}
			update_post_meta( $reservation_id, $blocked_meta, $blocked );
		} elseif ( 'assign' === $op ) {
			if ( '' === $order_key ) {
				wp_send_json_error( array( 'message' => __( 'Choose a customer to assign.', 'equine-event-manager' ) ), 400 );
			}
			$order = $this->orders_repository->get_order( $order_key );
			if ( ! $order ) {
				wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
			}
			// The assignment note can only live on a component of the matching kind.
			// Assigning an RV lot to a stall-only order (no RV component) would
			// silently no-op, so reject it with a clear message.
			$has_component = false;
			foreach ( (array) ( isset( $order['components'] ) ? $order['components'] : array() ) as $comp ) {
				if ( ( isset( $comp['table'] ) ? $comp['table'] : '' ) === $note_comp ) {
					$has_component = true;
					break;
				}
			}
			if ( ! $has_component ) {
				wp_send_json_error( array( 'message' => $is_rv
					? __( 'That order has no RV booking — assign a lot only to an order that includes RV.', 'equine-event-manager' )
					: __( 'That order has no stall booking — assign a stall only to an order that includes stalls.', 'equine-event-manager' ) ), 409 );
			}
			// Conflict: unit already assigned to another order on this reservation.
			foreach ( $this->get_reservation_orders( $reservation_id ) as $o ) {
				if ( (string) $o['order_key'] === (string) $order_key ) {
					continue;
				}
				$units = array_map( 'strval', (array) $this->parse_assigned_units_string(
					$this->get_order_component_note_value( $o, $note_comp, $note_label )
				) );
				if ( in_array( $stall, $units, true ) ) {
					wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot */ __( 'That %s is already assigned to another customer.', 'equine-event-manager' ), $unit_noun ) ), 409 );
				}
			}
			$current = array_map( 'strval', (array) $this->parse_assigned_units_string(
				$this->get_order_component_note_value( $order, $note_comp, $note_label )
			) );
			if ( ! in_array( $stall, $current, true ) ) {
				$current[] = $stall;
			}
			list( $stall_csv, $rv_csv ) = $build_pair( $order, $current );
			$ok = $this->orders_repository->update_order_unit_assignments( $order_key, $stall_csv, $rv_csv );
			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot */ __( 'Could not assign the %s.', 'equine-event-manager' ), $unit_noun ) ), 500 );
			}
			$message = $is_rv ? __( 'Lot assigned.', 'equine-event-manager' ) : __( 'Stall assigned.', 'equine-event-manager' );
		} elseif ( 'unassign' === $op || 'tack' === $op || 'untack' === $op ) {
			// Resolve the owning order: prefer the posted key, else find it.
			$order = '' !== $order_key ? $this->orders_repository->get_order( $order_key ) : null;
			if ( ! $order ) {
				foreach ( $this->get_reservation_orders( $reservation_id ) as $o ) {
					$units = array_map( 'strval', (array) $this->parse_assigned_units_string(
						$this->get_order_component_note_value( $o, $note_comp, $note_label )
					) );
					if ( in_array( $stall, $units, true ) ) {
						$order     = $o;
						$order_key = (string) $o['order_key'];
						break;
					}
				}
			}
			if ( ! $order ) {
				wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot */ __( 'No order owns that %s.', 'equine-event-manager' ), $unit_noun ) ), 404 );
			}

			if ( 'unassign' === $op ) {
				$current = array_map( 'strval', (array) $this->parse_assigned_units_string(
					$this->get_order_component_note_value( $order, $note_comp, $note_label )
				) );
				$current = array_values( array_diff( $current, array( $stall ) ) );
				list( $stall_csv, $rv_csv ) = $build_pair( $order, $current );
				$ok = $this->orders_repository->update_order_unit_assignments( $order_key, $stall_csv, $rv_csv );
				// Stalls: removing an assignment also clears any tack flag.
				if ( ! $is_rv ) {
					$tack = array_map( 'strval', (array) $this->parse_assigned_units_string(
						$this->get_order_component_note_value( $order, 'stall', 'Tack Stalls' )
					) );
					if ( in_array( $stall, $tack, true ) ) {
						$tack = array_values( array_diff( $tack, array( $stall ) ) );
						$this->orders_repository->update_order_tack_stalls( $order_key, implode( ', ', $tack ) );
					}
				}
				if ( ! $ok ) {
					wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot */ __( 'Could not unassign the %s.', 'equine-event-manager' ), $unit_noun ) ), 500 );
				}
				$message = $is_rv ? __( 'Lot unassigned.', 'equine-event-manager' ) : __( 'Stall unassigned.', 'equine-event-manager' );
			} else {
				$tack = array_map( 'strval', (array) $this->parse_assigned_units_string(
					$this->get_order_component_note_value( $order, 'stall', 'Tack Stalls' )
				) );
				$assigned = array_map( 'strval', (array) $this->parse_assigned_units_string(
					$this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' )
				) );
				if ( 'tack' === $op ) {
					if ( ! in_array( $stall, $assigned, true ) ) {
						wp_send_json_error( array( 'message' => __( 'Assign the stall before marking it tack.', 'equine-event-manager' ) ), 409 );
					}
					if ( ! in_array( $stall, $tack, true ) ) {
						$tack[] = $stall;
					}
					$message = __( 'Marked as tack stall.', 'equine-event-manager' );
				} else {
					$tack    = array_values( array_diff( $tack, array( $stall ) ) );
					$message = __( 'Tack designation removed.', 'equine-event-manager' );
				}
				$ok = $this->orders_repository->update_order_tack_stalls( $order_key, implode( ', ', $tack ) );
				if ( ! $ok ) {
					wp_send_json_error( array( 'message' => __( 'Could not update tack designation.', 'equine-event-manager' ) ), 500 );
				}
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'equine-event-manager' ) ), 400 );
		}

		// Write done — release before the (read-only) re-render + response.
		$this->release_assignment_lock( $reservation_id );

		// Re-render the dynamic region against fresh state for an in-place swap.
		$config = $this->get_stall_chart_config( $reservation_id );
		ob_start();
		$this->render_stall_chart_dynamic_region( $reservation_id, $config, $inv, $tab );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'message' => $message,
			'html'    => $html,
		) );
	}

	/**
	 * AJAX: reconcile a group name across a reservation's orders (v4 Slice 6).
	 *
	 * Renames every order whose Group Name equals `from` to `to` (case-sensitive
	 * exact match on the stored label), so an admin can fold a misspelled variant
	 * ("Smith Barn ", "smith barn") into the canonical group. The admin-assigned
	 * name is the source of truth for grouping. Returns the re-rendered dynamic
	 * region for an in-place swap. Reuses the eem_stall_chart_move nonce.
	 *
	 * @return void
	 */
	public function ajax_group_rename() {
		check_ajax_referer( 'eem_stall_chart_move', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$from           = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '';
		$to             = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '';
		$inv            = isset( $_POST['inv'] ) ? sanitize_key( wp_unslash( $_POST['inv'] ) ) : 'all';
		$inv            = in_array( $inv, array( 'all', 'stalls', 'rv' ), true ) ? $inv : 'all';
		$tab            = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'customer';
		$tab            = in_array( $tab, array( 'location', 'customer' ), true ) ? $tab : 'customer';

		if ( $reservation_id < 1 || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}
		$from = trim( $from );
		$to   = trim( $to );
		if ( '' === $from || '' === $to ) {
			wp_send_json_error( array( 'message' => __( 'Both the current and new group name are required.', 'equine-event-manager' ) ), 400 );
		}

		$renamed = 0;
		foreach ( $this->get_reservation_orders( $reservation_id ) as $order ) {
			$current = $this->get_group_name_from_order_notes( (string) ( isset( $order['notes'] ) ? $order['notes'] : '' ) );
			if ( $current === $from && isset( $order['order_key'] ) ) {
				if ( $this->orders_repository->update_order_group_name( (string) $order['order_key'], $to ) ) {
					$renamed++;
				}
			}
		}

		$config = $this->get_stall_chart_config( $reservation_id );
		ob_start();
		$this->render_stall_chart_dynamic_region( $reservation_id, $config, $inv, $tab );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: number of orders, 2: new group name */
				_n( '%1$d order moved to "%2$s".', '%1$d orders moved to "%2$s".', $renamed, 'equine-event-manager' ),
				$renamed,
				$to
			),
			'renamed' => $renamed,
			'html'    => $html,
		) );
	}

	/**
	 * Get the raw blocked-stall labels for a reservation (unfiltered).
	 *
	 * Unions the chart-block meta (`_en_stall_chart_blocked_stall_units`, written
	 * by the map popover) with the editor's Blocked Stall Numbers field
	 * (`_en_blocked_stalls`). Unlike get_stall_chart_config()'s blocked list this
	 * is NOT intersected with the legacy row-builder inventory — map-connected
	 * reservations draw inventory from the snapshot, so a snapshot label that
	 * isn't in `_en_stall_rows` must still survive as blocked.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string[] Blocked stall labels.
	 */
	private function get_raw_blocked_stall_labels( int $reservation_id ): array {
		$labels = array();
		foreach ( array( '_en_stall_chart_blocked_stall_units', '_en_blocked_stalls' ) as $key ) {
			$meta = get_post_meta( $reservation_id, $key, true );
			if ( is_array( $meta ) ) {
				foreach ( $meta as $label ) {
					$label = (string) $label;
					if ( '' !== $label ) {
						$labels[ $label ] = true;
					}
				}
			}
		}
		return array_keys( $labels );
	}

	/**
	 * Order unit labels by their barn (tab order) then natural label (v4).
	 *
	 * Map snapshots collect labels in grid-traversal order, which can run a row
	 * right-to-left (…11, 10, 9…). The matrix list + lowest-first auto-assign want
	 * 1, 2, 3 within each barn, in the barn's tab order. The spatial map is
	 * unaffected (it renders from the snapshot grid, not this list).
	 *
	 * @param array $units      Unit labels.
	 * @param array $unit_barn  label => barn name.
	 * @param array $barn_order Barn names in tab order.
	 * @return array Sorted labels.
	 */
	private function sort_units_by_barn( array $units, array $unit_barn, array $barn_order ): array {
		$index = array_flip( array_values( $barn_order ) );
		usort( $units, static function ( $a, $b ) use ( $unit_barn, $index ) {
			$ba = isset( $unit_barn[ $a ], $index[ $unit_barn[ $a ] ] ) ? $index[ $unit_barn[ $a ] ] : PHP_INT_MAX;
			$bb = isset( $unit_barn[ $b ], $index[ $unit_barn[ $b ] ] ) ? $index[ $unit_barn[ $b ] ] : PHP_INT_MAX;
			if ( $ba !== $bb ) {
				return $ba <=> $bb;
			}
			return strnatcasecmp( (string) $a, (string) $b );
		} );
		return array_values( $units );
	}

	/**
	 * Get all orders linked to a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array[] Orders whose reservation_id matches.
	 */
	private function get_reservation_orders( int $reservation_id ): array {
		return array_values( array_filter(
			$this->orders_repository->get_orders(),
			static function ( $order ) use ( $reservation_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === $reservation_id;
			}
		) );
	}

	/**
	 * Render the static overlay chrome for the stall chart detail page.
	 *
	 * The cell-action popover, destination-select banner, move-scope modal,
	 * and the localized `window.eemStallChart` script live outside the
	 * `#eem-stall-chart-dynamic` region so they persist across auto-assign
	 * AJAX swaps. Emitted once per page load.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return void
	 */
	private function render_stall_chart_overlays( int $reservation_id ): void {
		?>
		<!-- Cell action popover (positioned via JS) -->
		<div class="eem-stall-chart-cell-menu cell-action-menu" id="eem-stall-chart-cell-menu">
			<div class="eem-stall-chart-menu-title-wrap">
				<div class="eem-stall-chart-menu-title cell-action-menu__title" id="eem-stall-chart-menu-title">—</div>
				<div class="eem-stall-chart-menu-subtitle cell-action-menu__subtitle" id="eem-stall-chart-menu-subtitle">—</div>
			</div>
			<button class="eem-stall-chart-cell-menu-btn cell-action-menu__btn" type="button" data-eem-action="move-to-different-stall">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
				<?php esc_html_e( 'Move to different stall', 'equine-event-manager' ); ?>
			</button>
			<button class="eem-stall-chart-cell-menu-btn cell-action-menu__btn" type="button" data-eem-action="view-active-order">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
				<?php esc_html_e( 'View order', 'equine-event-manager' ); ?>
			</button>
			<?php // V1 #5: toggle the active stall's tack designation (operational, no price change). ?>
			<button class="eem-stall-chart-cell-menu-btn cell-action-menu__btn" type="button" data-eem-action="toggle-tack-stall" id="eem-stall-chart-tack-btn">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
				<span data-eem-tack-btn-label><?php esc_html_e( 'Mark as Tack Stall', 'equine-event-manager' ); ?></span>
			</button>
		</div>

		<!-- Destination-select banner -->
		<div class="destination-banner eem-destination-banner" id="eem-destination-banner" style="display:none;">
			<span class="destination-banner__msg"><?php esc_html_e( 'Click any available cell to move the customer there.', 'equine-event-manager' ); ?> <strong id="eem-destination-customer-name">—</strong></span>
			<button type="button" class="destination-banner__cancel" data-eem-action="cancel-destination-mode"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
		</div>

		<!-- Move scope modal -->
		<div class="scope-modal-overlay" id="eem-scope-modal-overlay" style="display:none;">
			<div class="scope-modal">
				<h3 class="scope-modal__title"><?php esc_html_e( 'Move customer', 'equine-event-manager' ); ?></h3>
				<div class="scope-modal__section">
					<div class="scope-modal__label"><?php esc_html_e( 'Currently assigned', 'equine-event-manager' ); ?></div>
					<div class="scope-modal__current-info" id="eem-scope-modal-current">—</div>
				</div>
				<div class="scope-modal__section">
					<div class="scope-modal__label"><?php esc_html_e( 'Move:', 'equine-event-manager' ); ?></div>
					<label class="scope-radio"><input type="radio" name="eem_move_scope" value="this-night" checked> <?php esc_html_e( 'Just this night', 'equine-event-manager' ); ?></label>
					<label class="scope-radio"><input type="radio" name="eem_move_scope" value="all-nights"> <?php esc_html_e( 'All nights', 'equine-event-manager' ); ?></label>
				</div>
				<div class="scope-modal-footer">
					<span class="scope-modal__error" id="eem-scope-modal-error" style="display:none;"></span>
					<button type="button" class="eem-btn eem-btn--ghost" data-eem-action="close-scope-modal"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn--primary" data-eem-action="confirm-move"><?php esc_html_e( 'Move', 'equine-event-manager' ); ?></button>
				</div>
			</div>
		</div>

		<?php
		// Localize AJAX endpoints + nonces for move and auto-assign functionality.
		$nonce             = wp_create_nonce( 'eem_stall_chart_move' );
		$auto_assign_nonce = wp_create_nonce( 'eem_auto_assign' );
		?>
		<script>
			window.eemStallChart = window.eemStallChart || {};
			window.eemStallChart.ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			window.eemStallChart.moveNonce = <?php echo wp_json_encode( $nonce ); ?>;
			window.eemStallChart.autoAssignNonce = <?php echo wp_json_encode( $auto_assign_nonce ); ?>;
			window.eemStallChart.reservationId = <?php echo (int) $reservation_id; ?>;
		</script>
		<?php
	}

	/**
	 * Render the Stall Chart Print View page.
	 *
	 * Standalone print-optimised view of a reservation's stall chart.
	 * URL: admin.php?page=equine-event-manager-stall-chart-print&reservation_id=N
	 *
	 * Opened in a new tab by the "Print View" button on the Stall Chart
	 * Detail page. WP admin chrome (sidebar, topbar, footer) is suppressed
	 * via CSS scoped to the `eem-shell-page--print` body class applied by
	 * filter_backend_shell_body_class(). The page's own sticky toolbar
	 * (`.pv-topbar`) carries Print and Close buttons; the toolbar is
	 * automatically hidden when the user prints via @media print.
	 *
	 * Mockup: .mockups/stall_chart_print_view.html
	 *
	 * @return void
	 */
	public function render_stall_chart_print_page(): void {
		$this->guard_admin_page();

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$reservation    = $reservation_id ? get_post( $reservation_id ) : null;

		// Which section(s) to print: 'location', 'customer', or 'both' (default).
		$pv_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'both';
		if ( ! in_array( $pv_view, array( 'location', 'customer', 'both' ), true ) ) {
			$pv_view = 'both';
		}

		// Which rows to print: 'assigned' (only stalls/lots with an occupied or
		// blocked night — the dense default Whitney asked for) or 'all' (every
		// stall/lot, e.g. a blank walk-up check-in sheet).
		$pv_rows = isset( $_GET['rows'] ) ? sanitize_key( wp_unslash( $_GET['rows'] ) ) : 'assigned';
		if ( ! in_array( $pv_rows, array( 'assigned', 'all' ), true ) ) {
			$pv_rows = 'assigned';
		}
		$assigned_only = ( 'assigned' === $pv_rows );

		// Nav-URL builder that preserves both toggles, overriding only the keys passed.
		$pv_nav_url = static function ( array $over ) use ( $reservation_id, $pv_view, $pv_rows ) {
			return add_query_arg(
				array_merge(
					array( 'page' => 'equine-event-manager-stall-chart-print', 'reservation_id' => $reservation_id, 'view' => $pv_view, 'rows' => $pv_rows ),
					$over
				),
				admin_url( 'admin.php' )
			);
		};
		$pv_view_url = static function ( $v ) use ( $pv_nav_url ) {
			return $pv_nav_url( array( 'view' => $v ) );
		};

		// True when a chart row has any occupied or blocked night (worth printing
		// in assigned-only mode); a purely-available row is dropped.
		$row_has_content = static function ( array $row ): bool {
			foreach ( (array) ( $row['cells'] ?? array() ) as $cell ) {
				$t = $cell['type'] ?? 'available';
				if ( 'occupied' === $t || 'blocked' === $t ) {
					return true;
				}
			}
			return false;
		};

		if ( ! $reservation instanceof WP_Post || 'en_reservation' !== $reservation->post_type ) {
			wp_die(
				esc_html__( 'Reservation not found.', 'equine-event-manager' ),
				esc_html__( 'Stall Chart Print View', 'equine-event-manager' ),
				array( 'back_link' => true )
			);
		}

		$config = $this->get_stall_chart_config( $reservation_id );

		if ( empty( $config['enabled'] ) ) {
			wp_die(
				esc_html__( 'Stall assignments are not enabled for this reservation.', 'equine-event-manager' ),
				esc_html__( 'Stall Chart Print View', 'equine-event-manager' ),
				array( 'back_link' => true )
			);
		}

		$grid              = $this->build_stall_chart_grid( $reservation_id, $config );
		$order_rows        = $this->build_stall_chart_rows( $reservation_id, $config );
		$date_cols         = $grid['date_columns'];
		$reservation_title = get_the_title( $reservation_id );
		$reservation_dates = $this->get_reservation_date_range_label( $reservation_id );
		$printed_at        = wp_date( 'F j, Y g:i A' );

		// Build order_key → formatted order number map for cell lookups.
		$order_num_map = array();
		foreach ( $order_rows as $or ) {
			if ( ! empty( $or['order_key'] ) ) {
				$order_num_map[ $or['order_key'] ] = isset( $or['order_number'] ) ? (int) $or['order_number'] : 0;
			}
		}

		// Build stall unit → block (barn) map for the By Customer stall grouping.
		$unit_block_map = array();
		foreach ( $grid['stall_rows'] as $row ) {
			$unit_block_map[ $row['unit'] ] = $row['block'] ?? '';
		}

		// Group stall rows by block (barn) for the By Location section.
		$by_barn = array();
		foreach ( $grid['stall_rows'] as $row ) {
			$barn             = $row['block'] ?? '';
			$by_barn[ $barn ][] = $row;
		}

		// Assigned/blocked row counts — drive the assigned-only empty-state note.
		$assigned_stall_count = 0;
		foreach ( $grid['stall_rows'] as $row ) {
			if ( $row_has_content( $row ) ) { $assigned_stall_count++; }
		}
		$assigned_rv_count = 0;
		foreach ( (array) ( $grid['rv_rows'] ?? array() ) as $row ) {
			if ( $row_has_content( $row ) ) { $assigned_rv_count++; }
		}

		// By Location table colspan = Stall + Customer + Order + ✓Arr. + date columns.
		$by_loc_colspan = 4 + count( $date_cols );
		?>
		<div class="eem-pv-page">

		<!-- ── ON-SCREEN TOOLBAR (hidden on print) ── -->
		<div class="pv-topbar">
			<div class="pv-topbar-left">
				<div>
					<div class="pv-topbar-title"><?php esc_html_e( 'Print View — Stall & RV Chart', 'equine-event-manager' ); ?></div>
					<div class="pv-topbar-sub">
						<?php echo esc_html( $reservation_title ); ?>
						<?php if ( $reservation_dates ) : ?>
							&middot; <?php echo esc_html( $reservation_dates ); ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="pv-topbar-btns">
				<div class="pv-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Choose what to print', 'equine-event-manager' ); ?>">
					<a class="pv-view-btn<?php echo 'both' === $pv_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( $pv_view_url( 'both' ) ); ?>"><?php esc_html_e( 'Both', 'equine-event-manager' ); ?></a>
					<a class="pv-view-btn<?php echo 'location' === $pv_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( $pv_view_url( 'location' ) ); ?>"><?php esc_html_e( 'By Location', 'equine-event-manager' ); ?></a>
					<a class="pv-view-btn<?php echo 'customer' === $pv_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( $pv_view_url( 'customer' ) ); ?>"><?php esc_html_e( 'By Customer', 'equine-event-manager' ); ?></a>
				</div>
				<div class="pv-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Choose which rows to print', 'equine-event-manager' ); ?>">
					<a class="pv-view-btn<?php echo $assigned_only ? ' is-active' : ''; ?>" href="<?php echo esc_url( $pv_nav_url( array( 'rows' => 'assigned' ) ) ); ?>"><?php esc_html_e( 'Assigned only', 'equine-event-manager' ); ?></a>
					<a class="pv-view-btn<?php echo $assigned_only ? '' : ' is-active'; ?>" href="<?php echo esc_url( $pv_nav_url( array( 'rows' => 'all' ) ) ); ?>"><?php esc_html_e( 'All stalls', 'equine-event-manager' ); ?></a>
				</div>
				<button class="btn-pv-print" type="button" onclick="window.print()">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
					<?php esc_html_e( 'Print / Save PDF', 'equine-event-manager' ); ?>
				</button>
				<button class="btn-pv-exit" type="button" onclick="window.close()">
					<?php esc_html_e( '✕ Close', 'equine-event-manager' ); ?>
				</button>
			</div>
		</div>

		<div class="pv-body">

			<!-- ── Event header ── -->
			<div class="pv-header">
				<div class="pv-event"><?php echo esc_html( $reservation_title ); ?></div>
				<div class="pv-meta">
					<?php if ( $reservation_dates ) : ?>
						<span><strong><?php esc_html_e( 'Dates:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $reservation_dates ); ?></span>
					<?php endif; ?>
					<span><strong><?php esc_html_e( 'Printed:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $printed_at ); ?></span>
				</div>
			</div>

			<!-- ── Daily Movement ── -->
			<?php if ( ! empty( $grid['movement_summary'] ) ) : ?>
			<div class="pv-movement">
				<div class="pv-movement-title"><?php esc_html_e( 'Daily Movement', 'equine-event-manager' ); ?></div>
				<div class="pv-movement-grid">
					<?php foreach ( $grid['movement_summary'] as $date_key => $movement ) : ?>
						<div class="pv-movement-day">
							<div class="pv-movement-date">
								<?php echo esc_html( isset( $date_cols[ $date_key ] ) ? $date_cols[ $date_key ] : $date_key ); ?>
							</div>
							<div class="pv-movement-vals">
								<span><?php echo esc_html( sprintf(
									/* translators: %d: count of arriving customers */
									__( '↓ %d Arriving', 'equine-event-manager' ),
									(int) $movement['arriving']
								) ); ?></span>
								<span><?php echo esc_html( sprintf(
									/* translators: %d: count of departing customers */
									__( '↑ %d Departing', 'equine-event-manager' ),
									(int) $movement['departing']
								) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- ══════════════════════════════════════════════════════ -->
			<!-- BY LOCATION                                           -->
			<!-- ══════════════════════════════════════════════════════ -->

			<?php if ( 'customer' !== $pv_view && ! empty( $grid['stall_rows'] ) ) : ?>
			<div class="pv-section-band"><?php esc_html_e( 'By Location', 'equine-event-manager' ); ?></div>
			<?php if ( $assigned_only && 0 === $assigned_stall_count ) : ?>
			<p class="pv-empty-note"><?php esc_html_e( 'No stalls are assigned or blocked yet. Switch to “All stalls” to print a blank check-in sheet.', 'equine-event-manager' ); ?></p>
			<?php else : ?>
			<div class="pv-table-wrap">
				<table class="pv-table">
					<thead>
						<tr>
							<th style="width:60px"><?php esc_html_e( 'Stall', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
							<th style="width:85px"><?php esc_html_e( 'Order #', 'equine-event-manager' ); ?></th>
							<th class="c" style="width:60px">✓ <?php esc_html_e( 'Arr.', 'equine-event-manager' ); ?></th>
							<?php foreach ( $date_cols as $date_label ) : ?>
								<th class="c" style="width:90px"><?php echo esc_html( $date_label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $by_barn as $barn_name => $barn_rows ) : ?>
							<?php
							// Compute stall range subtitle from numeric unit labels.
							$b_units     = array_column( $barn_rows, 'unit' );
							$b_numeric   = array_filter( $b_units, 'is_numeric' );
							$barn_sub    = '';
							if ( ! empty( $b_numeric ) ) {
								$barn_sub = sprintf(
									/* translators: 1: first stall number, 2: last stall number */
									__( '· Stalls %1$s–%2$s', 'equine-event-manager' ),
									min( $b_numeric ),
									max( $b_numeric )
								);
							}
							// Count stalls with at least one occupied cell, and (for the
							// assigned-only skip) those with any occupied OR blocked cell.
							$occ_count     = 0;
							$content_count = 0;
							$total_count   = count( $barn_rows );
							foreach ( $barn_rows as $br ) {
								if ( $row_has_content( $br ) ) {
									$content_count++;
								}
								foreach ( (array) ( $br['cells'] ?? array() ) as $cell ) {
									if ( 'occupied' === ( $cell['type'] ?? '' ) ) {
										$occ_count++;
										break;
									}
								}
							}
							// Assigned-only: drop barns with nothing to show.
							if ( $assigned_only && 0 === $content_count ) {
								continue;
							}
							?>
							<tr class="pv-barn-header">
								<td colspan="<?php echo esc_attr( $by_loc_colspan ); ?>">
									<?php echo esc_html( $barn_name ); ?>
									<?php if ( $barn_sub ) : ?>
										<span class="pv-barn-sub"><?php echo esc_html( $barn_sub ); ?></span>
									<?php endif; ?>
									<span class="pv-occ-count">
										<?php echo esc_html( sprintf(
											/* translators: 1: occupied count, 2: total stall count */
											__( '%1$d of %2$d occupied', 'equine-event-manager' ),
											$occ_count,
											$total_count
										) ); ?>
									</span>
								</td>
							</tr>
							<?php foreach ( $barn_rows as $stall_row ) : ?>
								<?php
								// Resolve primary customer from first occupied cell.
								$prim_customer  = '';
								$prim_order_key = '';
								foreach ( (array) ( $stall_row['cells'] ?? array() ) as $cell ) {
									if ( 'occupied' === ( $cell['type'] ?? '' ) && ! empty( $cell['label'] ) ) {
										$prim_customer  = $cell['label'];
										$prim_order_key = $cell['order_key'] ?? '';
										break;
									}
								}
								$prim_order_num = ( ! empty( $prim_order_key ) && isset( $order_num_map[ $prim_order_key ] ) && $order_num_map[ $prim_order_key ] > 0 )
									? sprintf( '#%05d', $order_num_map[ $prim_order_key ] )
									: '';
								$is_empty = '' === $prim_customer;
								// Assigned-only: drop purely-available stalls (keep occupied + blocked).
								if ( $assigned_only && ! $row_has_content( $stall_row ) ) {
									continue;
								}
								?>
								<tr>
									<td class="pv-stall-num"><?php echo esc_html( $stall_row['unit'] ); ?></td>
									<td class="pv-customer<?php echo $is_empty ? ' pv-customer-empty' : ''; ?>">
										<?php echo $is_empty ? '—' : esc_html( self::format_customer_last_first( (string) $prim_customer ) ); ?>
									</td>
									<td class="pv-order"><?php echo esc_html( $is_empty ? '—' : ( $prim_order_num ?: '—' ) ); ?></td>
									<td class="pv-checkin">
										<?php if ( $is_empty ) : ?>—<?php else : ?><span class="pv-checkbox"></span><?php endif; ?>
									</td>
									<?php foreach ( $date_cols as $date_key => $date_label ) : ?>
										<?php
										$cell      = ( $stall_row['cells'] ?? array() )[ $date_key ] ?? array( 'type' => 'available' );
										$cell_type = $cell['type'] ?? 'available';
										if ( 'occupied' === $cell_type ) {
											$pill_cls  = 'pv-occ-res';
											$pill_text = __( 'Reserved', 'equine-event-manager' );
										} elseif ( 'blocked' === $cell_type ) {
											$pill_cls  = 'pv-occ-block';
											$pill_text = __( 'Blocked', 'equine-event-manager' );
										} else {
											$pill_cls  = 'pv-occ-avail';
											$pill_text = __( 'Available', 'equine-event-manager' );
										}
										?>
										<td class="pv-night-cell">
											<span class="pv-occ <?php echo esc_attr( $pill_cls ); ?>"><?php echo esc_html( $pill_text ); ?></span>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<!-- ══════════════════════════════════════════════════════ -->
			<!-- RV LOTS                                               -->
			<!-- ══════════════════════════════════════════════════════ -->

			<?php if ( ! empty( $grid['rv_rows'] ) ) : ?>
			<div class="pv-section-band"><?php esc_html_e( 'RV Lots', 'equine-event-manager' ); ?></div>
			<?php if ( $assigned_only && 0 === $assigned_rv_count ) : ?>
			<p class="pv-empty-note"><?php esc_html_e( 'No RV lots are assigned or blocked yet. Switch to “All stalls” to print a blank check-in sheet.', 'equine-event-manager' ); ?></p>
			<?php else : ?>
			<div class="pv-table-wrap">
				<table class="pv-table">
					<thead>
						<tr>
							<th style="width:100px"><?php esc_html_e( 'RV Lot', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
							<th style="width:85px"><?php esc_html_e( 'Order #', 'equine-event-manager' ); ?></th>
							<th class="c" style="width:60px">✓ <?php esc_html_e( 'Arr.', 'equine-event-manager' ); ?></th>
							<?php foreach ( $date_cols as $date_label ) : ?>
								<th class="c" style="width:90px"><?php echo esc_html( $date_label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $grid['rv_rows'] as $rv_row ) : ?>
							<?php
							$prim_customer  = '';
							$prim_order_key = '';
							foreach ( (array) ( $rv_row['cells'] ?? array() ) as $cell ) {
								if ( 'occupied' === ( $cell['type'] ?? '' ) && ! empty( $cell['label'] ) ) {
									$prim_customer  = $cell['label'];
									$prim_order_key = $cell['order_key'] ?? '';
									break;
								}
							}
							$prim_order_num = ( ! empty( $prim_order_key ) && isset( $order_num_map[ $prim_order_key ] ) && $order_num_map[ $prim_order_key ] > 0 )
								? sprintf( '#%05d', $order_num_map[ $prim_order_key ] )
								: '';
							$is_empty = '' === $prim_customer;
							// Assigned-only: drop purely-available RV lots (keep occupied + blocked).
							if ( $assigned_only && ! $row_has_content( $rv_row ) ) {
								continue;
							}
							?>
							<tr>
								<td class="pv-stall-num"><?php echo esc_html( $rv_row['unit'] ); ?></td>
								<td class="pv-customer<?php echo $is_empty ? ' pv-customer-empty' : ''; ?>">
									<?php echo $is_empty ? '—' : esc_html( self::format_customer_last_first( (string) $prim_customer ) ); ?>
								</td>
								<td class="pv-order"><?php echo esc_html( $is_empty ? '—' : ( $prim_order_num ?: '—' ) ); ?></td>
								<td class="pv-checkin">
									<?php if ( $is_empty ) : ?>—<?php else : ?><span class="pv-checkbox"></span><?php endif; ?>
								</td>
								<?php foreach ( $date_cols as $date_key => $date_label ) : ?>
									<?php
									$cell      = ( $rv_row['cells'] ?? array() )[ $date_key ] ?? array( 'type' => 'available' );
									$cell_type = $cell['type'] ?? 'available';
									if ( 'occupied' === $cell_type ) {
										$pill_cls  = 'pv-occ-res';
										$pill_text = __( 'Reserved', 'equine-event-manager' );
									} elseif ( 'blocked' === $cell_type ) {
										$pill_cls  = 'pv-occ-block';
										$pill_text = __( 'Blocked', 'equine-event-manager' );
									} else {
										$pill_cls  = 'pv-occ-avail';
										$pill_text = __( 'Available', 'equine-event-manager' );
									}
									?>
									<td class="pv-night-cell">
										<span class="pv-occ <?php echo esc_attr( $pill_cls ); ?>"><?php echo esc_html( $pill_text ); ?></span>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<!-- ══════════════════════════════════════════════════════ -->
			<!-- BY CUSTOMER                                           -->
			<!-- ══════════════════════════════════════════════════════ -->

			<?php if ( 'location' !== $pv_view && ! empty( $order_rows ) ) : ?>
			<div class="pv-section-band pv-section-by-customer"><?php esc_html_e( 'By Customer', 'equine-event-manager' ); ?></div>
			<div class="pv-table-wrap">
				<table class="pv-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
							<th style="width:85px"><?php esc_html_e( 'Order #', 'equine-event-manager' ); ?></th>
							<th class="c" style="width:60px">✓ <?php esc_html_e( 'Arr.', 'equine-event-manager' ); ?></th>
							<th class="c" style="width:65px"><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Stall(s)', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'RV Lot(s)', 'equine-event-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $order_rows as $or ) : ?>
							<?php
							// Count occupied nights (date columns where customer has assignments).
							$nights = 0;
							foreach ( (array) ( $or['daily_counts'] ?? array() ) as $cnt ) {
								if ( (int) $cnt > 0 ) {
									$nights++;
								}
							}
							// Formatted order number.
							$or_display = ! empty( $or['order_number'] )
								? sprintf( '#%05d', (int) $or['order_number'] )
								: '—';
							// Group stall units by block/barn for display.
							$stall_by_block = array();
							foreach ( (array) ( $or['stall_units'] ?? array() ) as $unit ) {
								$block                     = $unit_block_map[ $unit ] ?? '';
								$stall_by_block[ $block ][] = $unit;
							}
							$stall_parts = array();
							foreach ( $stall_by_block as $block => $s_units ) {
								$stall_parts[] = ( '' !== $block ? $block . ' ' : '' ) . implode( ', ', $s_units );
							}
							$stall_disp = implode( '; ', $stall_parts );
							$rv_disp    = implode( ', ', (array) ( $or['rv_units'] ?? array() ) );
							?>
							<tr>
								<td class="pv-customer"><?php echo esc_html( self::format_customer_last_first( (string) ( $or['customer_name'] ?? '' ) ) ); ?></td>
								<td class="pv-order"><?php echo esc_html( $or_display ); ?></td>
								<td class="pv-checkin"><span class="pv-checkbox"></span></td>
								<td class="pv-night-cell"><?php echo esc_html( (string) $nights ); ?></td>
								<td><?php
									if ( '' !== $stall_disp ) {
										echo esc_html( $stall_disp );
									} else {
										echo '<span class="pv-customer-empty">—</span>';
									}
								?></td>
								<td><?php
									if ( '' !== $rv_disp ) {
										echo esc_html( $rv_disp );
									} else {
										echo '<span class="pv-customer-empty">—</span>';
									}
								?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<!-- Footer -->
			<div class="pv-footer">
				<span><?php echo esc_html( $reservation_title ); ?> · <?php esc_html_e( 'Stall & RV Chart', 'equine-event-manager' ); ?></span>
				<span><?php echo esc_html( sprintf(
					/* translators: %s: formatted printed date and time */
					__( 'Printed %s · Equine Event Manager', 'equine-event-manager' ),
					$printed_at
				) ); ?></span>
			</div>

		</div><!-- /pv-body -->
		</div><!-- /eem-pv-page -->
		<?php
	}

	/**
	 * Render the Stall & RV Charts list page (V1 port of stall_charts_page.html).
	 *
	 * 2.3.29 — uses the canonical eem_render_page_open() / _close() shell
	 * (same shell as the Reservations list page) so the white card,
	 * padding, and Space Grotesk header apply correctly. 2.3.28 had
	 * invented `.eem-plugin-wrap` / `.eem-plugin-header` / `.eem-plugin-title`
	 * markup which targets the Edit Reservation editor's chrome primitives
	 * — wrong CSS family for a list page; the list-page shell is `.eem-page`
	 * + `.eem-page-wrap` + `.eem-page-header` + `.eem-page-title`.
	 *
	 * @param int $invalid_reservation_id Non-zero when an invalid reservation_id
	 *                                    was supplied in the query string.
	 * @return void
	 */
	private function render_stall_charts_list_page( int $invalid_reservation_id ): void {
		$rows = $this->get_stall_charts_list_data();

		// Derive a "Month YYYY" option list from the rendered reservations for
		// the toolbar date filter (mockup A2 — All dates + monthly stubs).
		$date_options = array();
		foreach ( $rows as $row ) {
			$start = isset( $row['start_ts'] ) ? (int) $row['start_ts'] : 0;
			if ( $start > 0 ) {
				$key = gmdate( 'Y-m', $start );
				if ( ! isset( $date_options[ $key ] ) ) {
					$date_options[ $key ] = gmdate( 'F Y', $start );
				}
			}
		}
		ksort( $date_options );

		eem_render_page_open(
			array(
				'title'      => __( 'Stall & RV Charts', 'equine-event-manager' ),
				'subtitle'   => __( 'View and manage stall assignments for each reservation.', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Stall & RV Charts', 'equine-event-manager' ) ),
				),
			)
		);
		?>
		<div class="eem-stall-charts-list">

			<?php if ( $invalid_reservation_id > 0 ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'That stall chart could not be loaded — the reservation may not exist. Choose another below.', 'equine-event-manager' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- Toolbar -->
			<div class="eem-list-toolbar eem-sc-list-toolbar toolbar">
				<div class="eem-list-toolbar-left toolbar-left">
					<select class="toolbar-select eem-toolbar-select eem-sc-date-filter" id="eem-sc-date-filter" data-eem-input-action="sc-list-date-filter">
						<option value="all"><?php esc_html_e( 'All dates', 'equine-event-manager' ); ?></option>
						<?php foreach ( $date_options as $date_key => $date_label ) : ?>
							<option value="<?php echo esc_attr( $date_key ); ?>"><?php echo esc_html( $date_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="eem-search-wrap search-wrap">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" data-eem-input-action="sc-list-search">
					</div>
				</div>
				<div class="eem-list-toolbar-right toolbar-right">
					<span class="eem-list-count item-count" id="eem-sc-list-count">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of reservations */
								_n( '%d reservation', '%d reservations', count( $rows ), 'equine-event-manager' ),
								count( $rows )
							)
						);
						?>
					</span>
				</div>
			</div>

			<?php if ( empty( $rows ) ) : ?>
			<!-- Empty state -->
			<?php $new_res_url = admin_url( 'post-new.php?post_type=' . ( class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation' ) ); ?>
			<div class="eem-empty-cta">
				<div class="eem-empty-cta__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
				</div>
				<h3 class="eem-empty-cta__title"><?php esc_html_e( 'No stall charts yet', 'equine-event-manager' ); ?></h3>
				<p class="eem-empty-cta__text"><?php esc_html_e( 'Stall charts come from reservations. Create a reservation and enable its stall or RV reservations, and its chart will appear here ready to assign.', 'equine-event-manager' ); ?></p>
				<a class="eem-btn eem-btn-amber" href="<?php echo esc_url( $new_res_url ); ?>"><?php esc_html_e( 'Create a Reservation', 'equine-event-manager' ); ?></a>
			</div>
			<?php else : ?>

			<!-- Desktop table -->
			<div class="eem-sc-list-desktop">
				<table class="eem-sc-list-table">
					<thead>
						<tr>
							<th class="sortable" data-eem-action="sc-sort" data-sort-key="title"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?> <span class="sort-icon">&#8597;</span></th>
							<th><?php esc_html_e( 'Barns', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'RV Lots', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
						</tr>
					</thead>
					<tbody id="eem-sc-list-tbody">
					<?php foreach ( $rows as $row ) :
						$chart_url = admin_url( 'admin.php?page=equine-event-manager-stall-charts&reservation_id=' . absint( $row['id'] ) );
						$edit_url  = admin_url( 'admin.php?page=equine-event-manager-reservation-editor&reservation_id=' . absint( $row['id'] ) );
						$btn_label = __( 'View Chart', 'equine-event-manager' );
						?>
						<tr data-sc-title="<?php echo esc_attr( strtolower( $row['title'] ) ); ?>">
							<td>
								<a class="eem-sc-res-name" href="<?php echo esc_url( $chart_url ); ?>">
									<?php echo esc_html( $row['title'] ); ?>
								</a>
								<?php if ( ! empty( $row['dates'] ) ) : ?>
									<div class="eem-sc-res-dates res-dates"><?php echo wp_kses_post( $row['dates'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $row['stat_text'] ) ) : ?>
									<div class="eem-sc-chart-stats chart-stats">
										<?php foreach ( $row['stats'] as $stat ) : ?>
											<span class="eem-sc-chart-stat chart-stat">
												<span class="eem-sc-stat-dot stat-dot" style="background:<?php echo esc_attr( $stat['color'] ); ?>"></span>
												<?php echo esc_html( $stat['label'] ); ?>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $row['barn_names'] ) ) : ?>
									<div class="eem-sc-barn-tags">
										<?php foreach ( $row['barn_names'] as $barn ) : ?>
											<span class="eem-sc-barn-tag"><?php echo esc_html( $barn ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<span class="eem-sc-empty-dash">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $row['rv_zone_names'] ) ) : ?>
									<div class="eem-sc-barn-tags">
										<?php foreach ( $row['rv_zone_names'] as $zone ) : ?>
											<span class="eem-sc-rv-lot-tag"><?php echo esc_html( $zone ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<span class="eem-sc-empty-dash">—</span>
								<?php endif; ?>
							</td>
							<td>
								<div class="eem-sc-actions-cell">
									<a class="eem-sc-action-btn eem-sc-action-btn--primary"
									   href="<?php echo esc_url( $chart_url ); ?>">
										<?php echo esc_html( $btn_label ); ?>
									</a>
									<a class="eem-sc-action-btn eem-sc-action-btn--ghost"
									   href="<?php echo esc_url( $edit_url ); ?>">
										<?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?>
									</a>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Mobile cards -->
			<div class="eem-sc-list-mobile">
				<?php foreach ( $rows as $row ) :
					$chart_url = admin_url( 'admin.php?page=equine-event-manager-stall-charts&reservation_id=' . absint( $row['id'] ) );
					$edit_url  = admin_url( 'admin.php?page=equine-event-manager-reservation-editor&reservation_id=' . absint( $row['id'] ) );
					$btn_label = __( 'View Chart', 'equine-event-manager' );
					?>
					<div class="eem-sc-mobile-card"
						 data-sc-title="<?php echo esc_attr( strtolower( $row['title'] ) ); ?>">
						<div class="eem-sc-mob-card-top">
							<a class="eem-sc-mob-res-name" href="<?php echo esc_url( $chart_url ); ?>">
								<?php echo esc_html( $row['title'] ); ?>
							</a>
						</div>
						<div class="eem-sc-mob-card-body">
							<?php if ( ! empty( $row['dates'] ) ) : ?>
								<div class="eem-sc-mob-card-dates"><?php echo esc_html( $row['dates'] ); ?></div>
							<?php endif; ?>
							<?php if ( ! empty( $row['barn_names'] ) ) : ?>
								<div class="eem-sc-barn-tags" style="margin-bottom:6px">
									<?php foreach ( $row['barn_names'] as $barn ) : ?>
										<span class="eem-sc-barn-tag"><?php echo esc_html( $barn ); ?></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $row['rv_zone_names'] ) ) : ?>
								<div class="eem-sc-barn-tags" style="margin-bottom:6px">
									<?php foreach ( $row['rv_zone_names'] as $zone ) : ?>
										<span class="eem-sc-rv-lot-tag"><?php echo esc_html( $zone ); ?></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="eem-sc-mob-card-bottom">
							<a class="eem-sc-action-btn eem-sc-action-btn--primary" href="<?php echo esc_url( $chart_url ); ?>">
								<?php echo esc_html( $btn_label ); ?>
							</a>
							<a class="eem-sc-action-btn eem-sc-action-btn--ghost" href="<?php echo esc_url( $edit_url ); ?>">
								<?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?>
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- No-match state (shown by JS when the search filter hides every row) -->
			<div class="eem-empty-state eem-sc-no-match" id="eem-sc-no-match" style="display:none;padding:48px 24px;text-align:center;">
				<p style="font-size:13px;color:#6B7A99;margin:0;line-height:1.6;"><?php esc_html_e( 'No reservations match your filters.', 'equine-event-manager' ); ?></p>
			</div>

			<!-- Pagination row -->
			<div class="pagination-row eem-sc-pagination-row">
				<span class="pagination-info"><?php
					echo esc_html( sprintf(
						/* translators: 1: first item, 2: last item, 3: total */
						__( 'Showing %1$d&ndash;%2$d of %3$d reservations', 'equine-event-manager' ),
						count( $rows ) > 0 ? 1 : 0,
						count( $rows ),
						count( $rows )
					) );
				?></span>
				<div class="pagination">
					<button type="button" class="page-btn" disabled aria-label="<?php esc_attr_e( 'Previous page', 'equine-event-manager' ); ?>">&lsaquo;</button>
					<button type="button" class="page-btn page-btn--active active" aria-current="page">1</button>
					<button type="button" class="page-btn" disabled aria-label="<?php esc_attr_e( 'Next page', 'equine-event-manager' ); ?>">&rsaquo;</button>
				</div>
			</div>

			<?php endif; // end empty check ?>

		</div><!-- /.eem-stall-charts-list -->
		<?php
		eem_render_page_close();
	}

	/**
	 * Build the data set for the Stall & RV Charts list page.
	 *
	 * Returns all en_reservation posts with chart status, barn names,
	 * RV zone names, and date label. Chart status is determined by:
	 *   - 'configured' — stall rows (or RV zones) present
	 *   - 'partial'    — stall or RV reservations enabled but no layout yet
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_stall_charts_list_data(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				// 2.3.50 — any reservation selling stalls OR RV lots needs a chart
				// view (to verify customer-picked assignments in Mapped mode, or to
				// manually assign in Bulk mode), regardless of inventory mode.
				// CLEANUP #44 — match canonical `_eem_section_enabled_*` OR legacy
				// `_en_*_enabled` so unmigrated reservations still resolve.
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => EEM_Reservations_CPT::section_enabled_meta_key( 'stalls_enabled' ),
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => '_en_stalls_enabled',
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => EEM_Reservations_CPT::section_enabled_meta_key( 'rv_enabled' ),
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => '_en_rv_enabled',
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
			return array();
		}

		$rows = array();

		foreach ( $query->posts as $rid ) {
			$rid = absint( $rid );
			if ( $rid <= 0 ) {
				continue;
			}

			$stall_rows = get_post_meta( $rid, '_en_stall_rows', true );
			$has_rows   = is_array( $stall_rows ) && ! empty( $stall_rows );

			// Barn names from stall rows
			$barn_names = array();
			if ( $has_rows ) {
				foreach ( $stall_rows as $sr ) {
					if ( isset( $sr['name'] ) && '' !== (string) $sr['name'] ) {
						$barn_names[] = (string) $sr['name'];
					}
				}
			}

			// RV zone names
			$rv_zones      = get_post_meta( $rid, '_en_rv_zones', true );
			$rv_zone_names = array();
			if ( is_array( $rv_zones ) ) {
				foreach ( $rv_zones as $zone ) {
					if ( isset( $zone['name'] ) && '' !== (string) $zone['name'] ) {
						$rv_zone_names[] = (string) $zone['name'];
					}
				}
			}

			// Chart status — 2.3.50: the reservation is in this list because it
			// sells stalls and/or RV lots. "configured" once any layout exists
			// (stall rows or RV zones); "partial" until then.
			$chart_status = ( $has_rows || ! empty( $rv_zone_names ) ) ? 'configured' : 'partial';

			// Stats blurb. Available/Reserved/Blocked for configured charts;
			// "Not yet configured" placeholder for empty/partial charts.
			$stats = array();
			if ( 'configured' === $chart_status ) {
				$cfg        = $this->get_stall_chart_config( $rid );
				$grid       = $this->build_stall_chart_grid( $rid, $cfg );
				$stall_rows = isset( $grid['stall_rows'] ) ? (array) $grid['stall_rows'] : array();

				// Classify each configured stall unit from the SAME occupancy grid
				// the chart detail/print pages render, so this at-a-glance summary
				// reconciles with the chart and with capacity. A unit booked on any
				// event date is Reserved; an unbooked-but-blocked unit is Blocked;
				// otherwise Available. The prior approach counted raw "Assigned
				// Stall Units" note labels, which don't always map to the configured
				// grid — that double-/over-counted, inflating Reserved past capacity
				// (e.g. 38 of 21) and flooring Available at 0.
				$available_count = 0;
				$reserved_count  = 0;
				$blocked_count   = 0;
				foreach ( $stall_rows as $stall_unit_row ) {
					$has_occupied = false;
					$has_blocked  = false;
					foreach ( (array) ( $stall_unit_row['cells'] ?? array() ) as $cell ) {
						$cell_type = $cell['type'] ?? 'available';
						if ( 'occupied' === $cell_type ) {
							$has_occupied = true;
						} elseif ( 'blocked' === $cell_type ) {
							$has_blocked = true;
						}
					}
					if ( $has_occupied ) {
						$reserved_count++;
					} elseif ( $has_blocked ) {
						$blocked_count++;
					} else {
						$available_count++;
					}
				}

				$stats = array(
					array(
						'color' => '#22c55e',
						'label' => sprintf( '%d Available', $available_count ),
					),
					array(
						'color' => '#dc2626',
						'label' => sprintf( '%d Reserved', $reserved_count ),
					),
					array(
						'color' => '#94a3b8',
						'label' => sprintf( '%d Blocked', $blocked_count ),
					),
				);
			} else {
				$stats = array(
					array( 'color' => '#94a3b8', 'label' => __( 'Not yet configured', 'equine-event-manager' ) ),
				);
			}

			$start_raw = get_post_meta( $rid, '_en_event_start_date', true );
			if ( ! $start_raw ) {
				$start_raw = get_post_meta( $rid, '_en_start_date', true );
			}
			$start_ts = $start_raw ? strtotime( (string) $start_raw ) : 0;

			$rows[] = array(
				'id'            => $rid,
				'title'         => get_the_title( $rid ),
				'dates'         => $this->get_reservation_date_range_label( $rid ),
				'barn_names'    => $barn_names,
				'rv_zone_names' => $rv_zone_names,
				'chart_status'  => $chart_status,
				'stats'         => $stats,
				'stat_text'     => 1,
				'start_ts'      => $start_ts ?: 0,
			);
		}

		return $rows;
	}

	/**
	 * Aggregate stall / RV assignment metrics for the Admin Dashboard.
	 *
	 * Reuses the exact production assignment path the Stall Chart Detail page
	 * uses (get_stall_chart_config + allocate_stall_chart_units /
	 * allocate_rv_lot_rows) so dashboard numbers always match the chart page.
	 * "Unassigned" therefore means the same thing here as it does there: a
	 * reservation's purchased stall/RV quantity that has no specific unit/lot
	 * allocated yet.
	 *
	 * Considers reservations selling stalls and/or RV lots (publish + private).
	 * A reservation with no layout configured (no stall rows, no RV zones) is
	 * reported under `unconfigured` rather than counted as unassigned.
	 *
	 * @return array{
	 *   stalls_unassigned_total:int,
	 *   stalls_assigned_total:int,
	 *   rv_unassigned_total:int,
	 *   per_reservation:array<int,array{assigned:int,total:int,unassigned:int,rv_unassigned:int,title:string,start_ts:int}>,
	 *   unconfigured:array<int,array{id:int,title:string}>,
	 *   assigned_by_order_key:array<string,int>
	 * }
	 */
	public function get_dashboard_stall_metrics(): array {
		$out = array(
			'stalls_unassigned_total' => 0,
			'stalls_assigned_total'   => 0,
			'rv_unassigned_total'     => 0,
			'per_reservation'         => array(),
			'unconfigured'            => array(),
			'assigned_by_order_key'   => array(),
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// CLEANUP #44 — canonical OR legacy section-enabled keys.
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => EEM_Reservations_CPT::section_enabled_meta_key( 'stalls_enabled' ), 'value' => '1', 'compare' => '=' ),
					array( 'key' => '_en_stalls_enabled', 'value' => '1', 'compare' => '=' ),
					array( 'key' => EEM_Reservations_CPT::section_enabled_meta_key( 'rv_enabled' ), 'value' => '1', 'compare' => '=' ),
					array( 'key' => '_en_rv_enabled', 'value' => '1', 'compare' => '=' ),
				),
			)
		);

		if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
			return $out;
		}

		$all_orders = $this->orders_repository->get_orders( '', 'date', 'asc' );

		foreach ( $query->posts as $rid ) {
			$rid = absint( $rid );
			if ( $rid <= 0 ) {
				continue;
			}

			// Configured = has a stall-row layout OR named RV zones (matches
			// get_stall_charts_list_data()).
			$stall_rows = get_post_meta( $rid, '_en_stall_rows', true );
			$has_rows   = is_array( $stall_rows ) && ! empty( $stall_rows );
			$rv_zones   = get_post_meta( $rid, '_en_rv_zones', true );
			$has_zones  = is_array( $rv_zones ) && ! empty( array_filter( array_column( $rv_zones, 'name' ) ) );

			if ( ! $has_rows && ! $has_zones ) {
				$out['unconfigured'][] = array( 'id' => $rid, 'title' => get_the_title( $rid ) );
				continue;
			}

			$config = $this->get_stall_chart_config( $rid );
			$orders = array_filter(
				$all_orders,
				static function ( $o ) use ( $rid ) {
					return absint( isset( $o['reservation_id'] ) ? $o['reservation_id'] : 0 ) === $rid;
				}
			);

			$stall_map = array();
			$rv_map    = array();
			$assigned  = 0;
			$unassigned = 0;
			$rv_unassigned = 0;

			foreach ( $orders as $order ) {
				$stall_dates  = $this->get_stall_chart_occupied_dates( $order['stall_arrival_date'], $order['stall_departure_date'] );
				$rv_dates     = $this->get_stall_chart_occupied_dates( $order['rv_arrival_date'], $order['rv_departure_date'] );
				$stall_needed = absint( $order['stall_quantity'] );
				$rv_needed    = $this->order_requires_rv_assignment( $order ) ? absint( $order['rv_quantity'] ) : 0;
				$stall_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' ) );
				$rv_manual    = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) );
				if ( empty( $rv_manual ) ) {
					$rv_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' ) );
				}
				$rv_lot_name = $this->parse_rv_lot_name_from_notes( $order['notes'] );

				$stall_units = $this->allocate_stall_chart_units( $config['available_stall_units'], $stall_map, $stall_dates, $stall_needed, $stall_manual, $order['order_key'] );
				$rv_units    = $this->allocate_rv_lot_rows(
					isset( $config['rv_lot_names'] ) ? $config['rv_lot_names'] : array(),
					isset( $config['auto_assign_rv_lot_names'] ) ? $config['auto_assign_rv_lot_names'] : ( isset( $config['available_rv_lot_names'] ) ? $config['available_rv_lot_names'] : array() ),
					$rv_map,
					$rv_dates,
					$rv_needed,
					$rv_lot_name,
					$rv_manual,
					$order['order_key']
				);

				$order_assigned = count( $stall_units['assigned'] );
				$assigned       += $order_assigned;
				$unassigned     += (int) $stall_units['unassigned'];
				$rv_unassigned  += (int) $rv_units['unassigned'];

				$ok = (string) $order['order_key'];
				if ( '' !== $ok ) {
					$out['assigned_by_order_key'][ $ok ] = ( $out['assigned_by_order_key'][ $ok ] ?? 0 ) + $order_assigned;
				}
			}

			$start_raw = get_post_meta( $rid, '_en_source_event_start_date', true );
			$start_ts  = $start_raw ? (int) strtotime( (string) $start_raw ) : 0;

			$out['per_reservation'][ $rid ] = array(
				'assigned'      => $assigned,
				'total'         => $assigned + $unassigned,
				'unassigned'    => $unassigned,
				'rv_unassigned' => $rv_unassigned,
				'title'         => get_the_title( $rid ),
				'start_ts'      => $start_ts,
			);
			$out['stalls_assigned_total']   += $assigned;
			$out['stalls_unassigned_total'] += $unassigned;
			$out['rv_unassigned_total']     += $rv_unassigned;
		}

		return $out;
	}

	/**
	 * Get reservations that can launch Stall Assignments.
	 *
	 * @deprecated 2.3.25 Use get_stall_charts_list_data() for the list page.
	 *   Retained for any external callers.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_stall_assignment_reservations() {
		$query = new WP_Query(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
			return array();
		}

		$reservations = array();

		foreach ( $query->posts as $reservation_id ) {
			$reservation_id = absint( $reservation_id );
			if ( $reservation_id <= 0 ) {
				continue;
			}

			$config = $this->get_stall_chart_config( $reservation_id );
			$has_stall_assignments = ! empty( $config['enabled'] ) || ! empty( $config['stall_units'] ) || ! empty( $config['rv_lot_names'] );

			if ( ! $has_stall_assignments ) {
				continue;
			}

			$date_label = $this->get_reservation_date_range_label( $reservation_id );
			$reservations[] = array(
				'id'    => $reservation_id,
				'title' => get_the_title( $reservation_id ),
				'dates' => $date_label ? $date_label : __( 'Dates not set yet', 'equine-event-manager' ),
			);
		}

		return $reservations;
	}

	/**
	 * Get a friendly reservation date range label.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string
	 */
	private function get_reservation_date_range_label( $reservation_id ) {
		$start_date = (string) get_post_meta( $reservation_id, '_en_start_date', true );
		$end_date   = (string) get_post_meta( $reservation_id, '_en_end_date', true );

		if ( '' === $start_date && '' === $end_date ) {
			return '';
		}

		if ( '' !== $start_date && '' !== $end_date ) {
			return sprintf(
				/* translators: 1: start date, 2: end date */
				__( '%1$s &ndash; %2$s', 'equine-event-manager' ),
				wp_date( 'M j, Y', strtotime( $start_date ) ),
				wp_date( 'M j, Y', strtotime( $end_date ) )
			);
		}

		$date_value = '' !== $start_date ? $start_date : $end_date;
		return wp_date( 'F j, Y', strtotime( $date_value ) );
	}

	/**
	 * Render dashboard recent order view counts.
	 *
	 * @param int    $total_order_count Total order count.
	 * @param int    $filtered_order_count Filtered order count.
	 * @param string $event_filter Active event filter.
	 * @return void
	 */
	private function render_dashboard_orders_views( $total_order_count, $filtered_order_count, $event_filter ) {
		?>
		<ul class="subsubsub">
			<li class="all">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="<?php echo '' === $event_filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'All', 'equine-event-manager' ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $total_order_count ) ); ?>)</span>
				</a>
				<?php if ( '' !== $event_filter ) : ?>
					<span class="separator"> | </span>
				<?php endif; ?>
			</li>
			<?php if ( '' !== $event_filter ) : ?>
				<li class="current">
					<span class="current">
						<?php esc_html_e( 'Filtered', 'equine-event-manager' ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( $filtered_order_count ) ); ?>)</span>
					</span>
				</li>
			<?php endif; ?>
		</ul>
		<?php
	}

	/**
	 * Render the reports page.
	 */
	public function render_reports_page() {
		$this->guard_admin_page();

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( $_GET['reservation_id'] ) : 0;
		$reservations   = get_posts(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$logs           = $this->get_report_export_logs();

		?>
		<div class="wrap eem-shell-wrap eem-shell-wrap--header">
			<?php
			$this->render_brand_banner(
				__( 'Reports', 'equine-event-manager' ),
				__( 'Export reservation data and review report history for Equine Event Manager.', 'equine-event-manager' )
			);
			?>
			<div class="eem-shell-content eem-shell-content--app">
				<?php $this->render_admin_notice(); ?>

				<div class="postbox">
					<div class="inside">
						<h2><?php esc_html_e( 'Export Reservations', 'equine-event-manager' ); ?></h2>
						<p>
							<strong><?php esc_html_e( 'Scope', 'equine-event-manager' ); ?>:</strong>
							<?php echo esc_html( $reservation_id ? get_the_title( $reservation_id ) : __( 'All Reservations', 'equine-event-manager' ) ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'History Entries', 'equine-event-manager' ); ?>:</strong>
							<?php echo esc_html( number_format_i18n( count( $logs ) ) ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eem-shell-form-inline">
							<?php wp_nonce_field( 'equine_event_manager_export_report', 'equine_event_manager_export_report_nonce' ); ?>
							<input type="hidden" name="action" value="equine_event_manager_export_report" />
							<label for="reservation_id"><?php esc_html_e( 'Reservation to export', 'equine-event-manager' ); ?></label>
							<select name="reservation_id" id="reservation_id">
								<option value="0"><?php esc_html_e( 'All reservations', 'equine-event-manager' ); ?></option>
								<?php foreach ( $reservations as $reservation ) : ?>
									<option value="<?php echo esc_attr( $reservation->ID ); ?>" <?php selected( $reservation_id, $reservation->ID ); ?>><?php echo esc_html( $reservation->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php submit_button( __( 'Export CSV', 'equine-event-manager' ), 'primary', '', false ); ?>
						</form>
						<p><?php esc_html_e( 'Choose one reservation or export the full reservation set for offline reporting.', 'equine-event-manager' ); ?></p>
					</div>
				</div>

				<div class="postbox">
					<div class="inside">
						<h2><?php esc_html_e( 'Export History', 'equine-event-manager' ); ?></h2>
						<?php $this->render_report_logs_table( $logs ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Update saved stall/RV assignment overrides for an order.
	 *
	 * @return void
	 */
	public function handle_update_order_assignments() {
		$this->guard_admin_action();

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_update_order_assignments_' . $order_key );
		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			$this->redirect_to_order_notice( $order_key, 'assignment_update_failed', __( 'Order not found.', 'equine-event-manager' ) );
		}

		$stall_unit_values = isset( $_POST['assigned_stall_units'] ) ? (array) wp_unslash( $_POST['assigned_stall_units'] ) : array();
		$stall_limit       = max( 0, absint( isset( $order['stall_quantity'] ) ? $order['stall_quantity'] : 0 ) );

		if ( $stall_limit > 0 && count( array_filter( array_map( 'trim', $stall_unit_values ) ) ) > $stall_limit ) {
			$this->redirect_to_order_notice(
				$order_key,
				'assignment_update_failed',
				sprintf(
					/* translators: %d: paid stall quantity. */
					_n( 'This order only includes %d paid stall, so only that many stall assignments can be selected.', 'This order only includes %d paid stalls, so only that many stall assignments can be selected.', $stall_limit, 'equine-event-manager' ),
					$stall_limit
				)
			);
		}

		$stall_units = ! empty( $stall_unit_values ) ? $this->sanitize_assignment_selection( $stall_unit_values, $stall_limit ) : '';
		$rv_lots     = isset( $_POST['assigned_rv_lots'] ) ? $this->sanitize_assignment_selection( wp_unslash( $_POST['assigned_rv_lots'] ) ) : '';

		// Serialize the write behind the per-reservation lock so this manual
		// override can't interleave with a concurrent auto-assign / checkout.
		$assign_res_id = isset( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
		$this->acquire_assignment_lock( $assign_res_id );
		$updated = $this->orders_repository->update_order_unit_assignments( $order_key, $stall_units, $rv_lots );
		$this->release_assignment_lock( $assign_res_id );

		if ( ! $updated ) {
			$this->redirect_to_order_notice( $order_key, 'assignment_update_failed', __( 'Assignments could not be updated.', 'equine-event-manager' ) );
		}

		$this->redirect_to_order_notice( $order_key, 'assignment_update_success' );
	}

	/**
	 * Build the stall chart configuration for a reservation.
	 *
	 * Reads stall unit and RV lot definitions from post meta, supporting both
	 * the legacy `_en_stall_chart_stall_blocks` / `_en_rv_lots` format and the
	 * V1 row-builder format stored in `_en_stall_rows` / `_en_rv_rows`.
	 *
	 * Priority: V1 row meta wins when present; legacy keys are the fallback for
	 * reservations created before the V1 editor shipped.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array{
	 *   enabled: bool,
	 *   stall_blocks: array,
	 *   stall_units: array,
	 *   rv_lot_names: array,
	 *   blocked_stall_units: array,
	 *   blocked_rv_lots: array,
	 *   available_stall_units: array,
	 *   available_rv_lot_names: array,
	 *   auto_assign_rv_lot_names: array,
	 *   rv_lots: array,
	 *   barn_map: array,
	 *   barn_names: array,
	 * }
	 */
	private function get_stall_chart_config( $reservation_id ) {
		$stall_units  = array();
		$barn_map     = array();
		$barn_names   = array();
		$rv_lot_names = array();

		// ── Stall units: V1 _en_stall_rows wins; legacy _en_stall_chart_stall_blocks is fallback ── //
		$v1_stall_rows = get_post_meta( $reservation_id, '_en_stall_rows', true );
		if ( is_array( $v1_stall_rows ) && ! empty( $v1_stall_rows ) ) {
			$stall_units = $this->expand_v1_stall_rows( $v1_stall_rows );
			$barn_map    = $this->build_barn_map_from_v1_rows( $v1_stall_rows );
			$barn_names  = array_values( array_unique( array_filter( array_column( $v1_stall_rows, 'name' ) ) ) );
			$stall_blocks = array(); // V1 rows supersede blocks; keep for legacy callers.
		} else {
			$stall_blocks = get_post_meta( $reservation_id, '_en_stall_chart_stall_blocks', true );
			if ( is_array( $stall_blocks ) && ! empty( $stall_blocks ) ) {
				$stall_units = $this->expand_stall_chart_units( $stall_blocks );
				$barn_map    = $this->map_stall_chart_unit_blocks( $stall_blocks );
				$barn_names  = array_values( array_unique( array_filter( array_column( $stall_blocks, 'label' ) ) ) );
			} else {
				$stall_blocks = array();
			}
		}

		// ── v4 Slice 5: a connected Stall Map supersedes the legacy row inventory.
		// Stalls and their barn (the chart's "Block" column) derive from the
		// sheet's tabs — one tab = one barn, every numbered cell = a stall — so the
		// matrix, the assignment pool, and Available Stall Inventory all read the
		// same source the customer + admin maps render from. ──
		$map_rv_lots      = array();   // v4 Slice 8: RV lots from RV-kind tabs.
		$map_rv_zone_map  = array();   // lot label => RV barn (zone) name.
		$map_rv_zones     = array();   // RV barn names.
		if ( class_exists( 'EEM_Stall_Map_Importer' ) ) {
			$chart_map_snapshot = EEM_Stall_Map_Importer::get_for_reservation( (int) $reservation_id );
			if ( ! empty( $chart_map_snapshot['barns'] ) ) {
				$stall_units = array();
				$barn_map    = array();
				$barn_names  = array();
				foreach ( (array) $chart_map_snapshot['barns'] as $chart_barn ) {
					$chart_barn_name = (string) ( isset( $chart_barn['name'] ) ? $chart_barn['name'] : '' );
					// v4 Slice 8: RV-named tabs supply RV lots, not stalls.
					$chart_is_rv = ( 'rv' === EEM_Stall_Map_Importer::barn_kind( (array) $chart_barn ) );
					if ( '' !== $chart_barn_name ) {
						if ( $chart_is_rv ) {
							$map_rv_zones[] = $chart_barn_name;
						} else {
							$barn_names[] = $chart_barn_name;
						}
					}
					foreach ( (array) ( isset( $chart_barn['grid'] ) ? $chart_barn['grid'] : array() ) as $chart_grow ) {
						foreach ( (array) $chart_grow as $chart_cell ) {
							if ( isset( $chart_cell['type'], $chart_cell['label'] )
								&& 'stall' === $chart_cell['type']
								&& '' !== (string) $chart_cell['label'] ) {
								$chart_label = (string) $chart_cell['label'];
								if ( $chart_is_rv ) {
									$map_rv_lots[]                = $chart_label;
									$map_rv_zone_map[ $chart_label ] = $chart_barn_name;
								} else {
									$stall_units[]            = $chart_label;
									$barn_map[ $chart_label ] = $chart_barn_name;
								}
							}
						}
					}
				}
				$stall_units  = array_values( array_unique( $stall_units ) );
				$barn_names   = array_values( array_unique( $barn_names ) );
				// v4: order by barn (tab order) then natural label so the matrix
				// lists 1,2,3… and lowest-first auto-assign fills 1 before 11 —
				// independent of the sheet's grid traversal. The spatial map keeps
				// grid order (it renders from the snapshot, not this list).
				$stall_units  = $this->sort_units_by_barn( $stall_units, $barn_map, $barn_names );
				$stall_blocks = array(); // Map supersedes the legacy block table.
			}
		}

		// ── v4 Slice 8: RV map is a SEPARATE connector (_en_rv_map). Every tab is
		// an RV zone; every numbered cell an RV lot. Supersedes legacy RV lots. ──
		if ( class_exists( 'EEM_Stall_Map_Importer' ) ) {
			$chart_rv_snapshot = EEM_Stall_Map_Importer::get_for_reservation( (int) $reservation_id, EEM_Stall_Map_Importer::RV_META_KEY );
			if ( ! empty( $chart_rv_snapshot['barns'] ) ) {
				$map_rv_lots     = array();
				$map_rv_zone_map = array();
				$map_rv_zones    = array();
				foreach ( (array) $chart_rv_snapshot['barns'] as $rv_barn ) {
					$rv_zone_name = (string) ( isset( $rv_barn['name'] ) ? $rv_barn['name'] : '' );
					if ( '' !== $rv_zone_name ) {
						$map_rv_zones[] = $rv_zone_name;
					}
					foreach ( (array) ( isset( $rv_barn['grid'] ) ? $rv_barn['grid'] : array() ) as $rv_grow ) {
						foreach ( (array) $rv_grow as $rv_cell ) {
							if ( isset( $rv_cell['type'], $rv_cell['label'] )
								&& 'stall' === $rv_cell['type']
								&& '' !== (string) $rv_cell['label'] ) {
								// RV lots are numbered per-zone (1..N in each tab), so the
								// lot IDENTITY is zone-qualified ("Red Lot 1") to stay
								// unique — matching the legacy rv_zone_map convention.
								$rv_lot_label                     = trim( $rv_zone_name . ' ' . (string) $rv_cell['label'] );
								$map_rv_lots[]                    = $rv_lot_label;
								$map_rv_zone_map[ $rv_lot_label ] = $rv_zone_name;
							}
						}
					}
				}
			}
		}

		// ── Blocked stalls: try legacy key first, fall back to V1 key ─────── //
		$blocked_stall_units = get_post_meta( $reservation_id, '_en_stall_chart_blocked_stall_units', true );
		if ( ! is_array( $blocked_stall_units ) || empty( $blocked_stall_units ) ) {
			$v1_blocked = get_post_meta( $reservation_id, '_en_blocked_stalls', true );
			if ( is_array( $v1_blocked ) ) {
				$blocked_stall_units = $v1_blocked;
			}
		}

		// ── RV lots: V1 _en_rv_rows wins; legacy _en_rv_lots is fallback ──── //
		$rv_lots = array();
		$v1_rv_rows = get_post_meta( $reservation_id, '_en_rv_rows', true );
		if ( is_array( $v1_rv_rows ) && ! empty( $v1_rv_rows ) ) {
			$rv_lot_names = $this->expand_rv_lot_names_from_v1_rows( $v1_rv_rows );
		} else {
			$rv_lots = get_post_meta( $reservation_id, '_en_rv_lots', true );
			if ( is_array( $rv_lots ) && ! empty( $rv_lots ) ) {
				$rv_lot_names = $this->get_stall_chart_rv_lot_names( $rv_lots );
			}
		}

		// ── Blocked RV: try legacy key first, fall back to V1 key ─────────── //
		$blocked_rv_lots = get_post_meta( $reservation_id, '_en_stall_chart_blocked_rv_units', true );
		if ( ! is_array( $blocked_rv_lots ) || empty( $blocked_rv_lots ) ) {
			$v1_blocked_rv = get_post_meta( $reservation_id, '_en_blocked_rv_lots', true );
			if ( is_array( $v1_blocked_rv ) ) {
				$blocked_rv_lots = $v1_blocked_rv;
			}
		}

		$blocked_stall_units = $this->sanitize_chart_unit_list( is_array( $blocked_stall_units ) ? $blocked_stall_units : array(), $stall_units );
		$blocked_rv_lots     = $this->sanitize_chart_unit_list( is_array( $blocked_rv_lots ) ? $blocked_rv_lots : array(), $rv_lot_names );

		// RV zone map and options (V1 rows only — zone = row 'name' field).
		$rv_zone_options = array();
		$rv_zone_map     = array(); // lot_name => zone_name.
		if ( is_array( $v1_rv_rows ) && ! empty( $v1_rv_rows ) ) {
			foreach ( $v1_rv_rows as $v1_row ) {
				$zone_name = isset( $v1_row['name'] ) ? sanitize_text_field( $v1_row['name'] ) : '';
				$first     = isset( $v1_row['first'] ) ? (string) $v1_row['first'] : '';
				$last      = isset( $v1_row['last'] )  ? (string) $v1_row['last']  : '';
				if ( '' === $zone_name || '' === $first || '' === $last ) {
					continue;
				}
				$rv_zone_options[] = $zone_name;
				foreach ( $this->expand_label_range( $first, $last ) as $num ) {
					$rv_zone_map[ $zone_name . ' ' . $num ] = $zone_name;
				}
			}
			$rv_zone_options = array_values( array_unique( $rv_zone_options ) );
			sort( $rv_zone_options, SORT_NATURAL | SORT_FLAG_CASE );
		}

		// ── v4 Slice 8: RV-kind map tabs supersede legacy RV lots. Each RV tab is
		// a zone; every numbered cell is an RV lot named by its label. ──
		$rv_barn_map = array();
		if ( ! empty( $map_rv_lots ) ) {
			$rv_lot_names    = $this->sort_units_by_barn( array_values( array_unique( $map_rv_lots ) ), $map_rv_zone_map, $map_rv_zones );
			$rv_zone_map     = $map_rv_zone_map;
			$rv_barn_map     = $map_rv_zone_map; // label => zone (for contiguity).
			$rv_zone_options = array_values( array_unique( $map_rv_zones ) );
			sort( $rv_zone_options, SORT_NATURAL | SORT_FLAG_CASE );
			$rv_lots         = array(); // Map supersedes the legacy lot config.
			$blocked_rv_lots = $this->sanitize_chart_unit_list( is_array( $blocked_rv_lots ) ? $blocked_rv_lots : array(), $rv_lot_names );
		}

		return array(
			// 2.3.52 — chart is active when Stall OR RV reservations are enabled.
			// Replaces the removed _en_stall_chart_enabled gate (the field that
			// left the Stall Chart Detail page showing "disabled" after 2.3.50).
			'enabled'               => EEM_Reservations_CPT::section_enabled( $reservation_id, 'stalls_enabled' ) || EEM_Reservations_CPT::section_enabled( $reservation_id, 'rv_enabled' ),
			'stall_blocks'          => $stall_blocks,
			'stall_units'           => $stall_units,
			'rv_lot_names'          => $rv_lot_names,
			'blocked_stall_units'   => $blocked_stall_units,
			'blocked_rv_lots'       => $blocked_rv_lots,
			'available_stall_units' => array_values( array_diff( $stall_units, $blocked_stall_units ) ),
			'available_rv_lot_names'=> array_values( array_diff( $rv_lot_names, $blocked_rv_lots ) ),
			// For V1 rows, all non-blocked lots are auto-assignable (zone rates live
			// in _en_rv_zones which is not consumed here; treat all lots as eligible).
			'auto_assign_rv_lot_names' => ! empty( $rv_lots )
				? $this->get_stall_chart_auto_assignable_rv_lot_names( $rv_lots, $blocked_rv_lots )
				: array_values( array_diff( $rv_lot_names, $blocked_rv_lots ) ),
			'rv_lots'               => $rv_lots,
			'barn_map'              => $barn_map,
			'barn_names'            => $barn_names,
			'rv_zone_options'       => $rv_zone_options,
			'rv_zone_map'           => $rv_zone_map,
		);
	}

	/**
	 * Build the full stall chart occupancy grid.
	 *
	 * @param int   $reservation_id Reservation post ID.
	 * @param array $config Chart config.
	 * @return array
	 */
	/**
	 * Format an order number as the canonical 5-digit zero-padded `#NNNNN`
	 * (matches Orders list / Order Detail / Dashboard). Used across the stall
	 * charts, order-detail side card, refund-meta labels, and invoice render.
	 * Returns an em dash for an empty number.
	 *
	 * @param string $order_number Raw order number.
	 * @return string
	 */
	private function format_order_number_display( string $order_number ): string {
		$order_number = trim( $order_number );
		if ( '' === $order_number ) {
			return '—';
		}
		return is_numeric( $order_number ) ? sprintf( '#%05d', (int) $order_number ) : '#' . $order_number;
	}

	/**
	 * Extract the V1 D2 "Group Name" tag from an order's notes (empty if none).
	 *
	 * @param string $notes Raw order notes.
	 * @return string
	 */
	private function get_group_name_from_order_notes( string $notes ): string {
		if ( preg_match( '/(?:^|\n)Group Name:\s*(.+)$/im', $notes, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Deterministic accent color for a group name, so the same group reads as the
	 * same color across every stall pill (By Location) and every roster chip (By
	 * Customer). Empty group → empty string (no indicator). Palette is chosen to be
	 * distinguishable and to avoid clashing with reserved-blue, blocked-red, and the
	 * amber tack dot.
	 *
	 * @param string $group_name Group label (already trimmed).
	 * @return string Hex color, or '' when no group.
	 */
	private function group_color_for( string $group_name ): string {
		$group_name = trim( $group_name );
		if ( '' === $group_name ) {
			return '';
		}
		$palette = array( '#2563eb', '#059669', '#7c3aed', '#0891b2', '#db2777', '#4f46e5', '#0d9488', '#c026d3' );
		$sum     = 0;
		$len     = strlen( $group_name );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += ord( $group_name[ $i ] );
		}
		return $palette[ $sum % count( $palette ) ];
	}

	private function build_stall_chart_grid( $reservation_id, $config ) {
		$orders = array_filter(
			$this->orders_repository->get_orders( '', 'date', 'asc' ),
			function ( $order ) use ( $reservation_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === absint( $reservation_id );
			}
		);

		$date_columns = $this->get_stall_chart_date_columns( $reservation_id, array() );
		$stall_rows   = $this->initialize_stall_chart_unit_rows( $config['stall_units'], $config['stall_blocks'], $config['blocked_stall_units'], $date_columns, isset( $config['barn_map'] ) ? $config['barn_map'] : array() );
		$rv_rows      = $this->initialize_rv_lot_chart_rows( $config['rv_lot_names'], isset( $config['blocked_rv_lots'] ) ? $config['blocked_rv_lots'] : array(), $date_columns );
		$movement     = $this->build_stall_chart_movement_summary( $orders, $date_columns );
		$issues       = array();
		$stall_map    = array();
		$rv_map       = array();
		// Track orders whose displayed placement is auto-SUGGESTED (computed live
		// for the proposed layout) rather than SAVED (persisted to the order from
		// a prior Generate Assignments / manual move). Drives the "not yet saved"
		// banner + the per-pill suggested styling so admins can tell the two apart.
		$unsaved_order_keys = array();

		foreach ( $orders as $order ) {
			$stall_dates  = $this->get_stall_chart_occupied_dates( $order['stall_arrival_date'], $order['stall_departure_date'] );
			$rv_dates     = $this->get_stall_chart_occupied_dates( $order['rv_arrival_date'], $order['rv_departure_date'] );
			$stall_needed = absint( $order['stall_quantity'] );
			$rv_needed    = $this->order_requires_rv_assignment( $order ) ? absint( $order['rv_quantity'] ) : 0;
			$stall_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' ) );
			$rv_manual    = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) );
			if ( empty( $rv_manual ) ) {
				$rv_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' ) );
			}
			$rv_lot_name  = $this->parse_rv_lot_name_from_notes( $order['notes'] );
			$stall_units  = $this->allocate_stall_chart_units( $config['available_stall_units'], $stall_map, $stall_dates, $stall_needed, $stall_manual, $order['order_key'] );
			$rv_units     = $this->allocate_rv_lot_rows( isset( $config['rv_lot_names'] ) ? $config['rv_lot_names'] : array(), isset( $config['auto_assign_rv_lot_names'] ) ? $config['auto_assign_rv_lot_names'] : ( isset( $config['available_rv_lot_names'] ) ? $config['available_rv_lot_names'] : array() ), $rv_map, $rv_dates, $rv_needed, $rv_lot_name, $rv_manual, $order['order_key'] );

			// V1 Scenario F: surface the customer's Special Requests on the chart
			// (pill tooltip + by-customer note) so admins see them while assigning.
			$special_requests = trim( (string) $this->get_special_requests_from_order_notes( $order['notes'] ) );
			// V1 D2: group name tag (for manual clustering + Show-by-group filter).
			$group_name = $this->get_group_name_from_order_notes( (string) $order['notes'] );
			// V1 #5: tack stall designations (subset of assigned units; operational).
			$tack_lookup = array_fill_keys(
				array_map( 'strval', (array) $this->parse_assigned_units_string(
					$this->get_order_component_note_value( $order, 'stall', 'Tack Stalls' )
				) ),
				true
			);

			// A unit is SAVED when it came from the order's persisted "Assigned
			// Stall Units" notes ($stall_manual); anything else was auto-filled
			// just now for the proposed layout and is SUGGESTED (unsaved).
			$saved_stall_set = array_fill_keys( array_map( 'strval', (array) $stall_manual ), true );
			foreach ( $stall_units['assigned'] as $unit ) {
				$unit_suggested = ! isset( $saved_stall_set[ (string) $unit ] );
				if ( $unit_suggested ) {
					$unsaved_order_keys[ $order['order_key'] ] = true;
				}
				foreach ( $stall_dates as $date_key ) {
					if ( isset( $stall_rows[ $unit ]['cells'][ $date_key ] ) ) {
						$stall_rows[ $unit ]['cells'][ $date_key ] = array(
							'type'             => 'occupied',
							'label'            => $order['customer_name'],
							'order_key'        => $order['order_key'],
							'order_number'     => (string) $order['order_number'],
							'special_requests' => $special_requests,
							'group_name'       => $group_name,
							'is_tack'          => isset( $tack_lookup[ (string) $unit ] ),
							'suggested'        => $unit_suggested,
						);
					}
				}
			}

			// Per-night override overlay: if this order carries a saved Stall Night
			// Map (a horse moved to a different stall for some nights), reconcile
			// its cells + occupancy per date so each night shows the right stall.
			// The uniform pass above placed the flat assignment on every night;
			// here we vacate the nights that differ and occupy the override stall.
			if ( '' !== (string) $this->get_order_component_note_value( $order, 'stall', 'Stall Night Map' ) ) {
				$night_assign = $this->get_order_night_assignments( $order, $stall_dates, $stall_manual );
				foreach ( $stall_dates as $date_key ) {
					$want = isset( $night_assign[ $date_key ] ) ? array_map( 'strval', (array) $night_assign[ $date_key ] ) : array();

					// Vacate any cell this order currently holds that night but no
					// longer wants.
					foreach ( (array) $stall_units['assigned'] as $held ) {
						if ( in_array( (string) $held, $want, true ) ) {
							continue;
						}
						if ( isset( $stall_rows[ $held ]['cells'][ $date_key ]['order_key'] )
							&& (string) $stall_rows[ $held ]['cells'][ $date_key ]['order_key'] === (string) $order['order_key'] ) {
							$stall_rows[ $held ]['cells'][ $date_key ] = array( 'type' => 'available', 'label' => __( 'Available', 'equine-event-manager' ) );
							unset( $stall_map[ $held ][ $date_key ] );
						}
					}

					// Occupy each wanted stall for that night.
					foreach ( $want as $unit ) {
						if ( ! isset( $stall_rows[ $unit ]['cells'][ $date_key ] ) ) {
							continue;
						}
						$cur = $stall_rows[ $unit ]['cells'][ $date_key ];
						if ( isset( $cur['type'] ) && 'occupied' === $cur['type'] && (string) ( $cur['order_key'] ?? '' ) !== (string) $order['order_key'] ) {
							continue; // held by someone else — leave it (conflict check should have prevented this).
						}
						$stall_rows[ $unit ]['cells'][ $date_key ] = array(
							'type'             => 'occupied',
							'label'            => $order['customer_name'],
							'order_key'        => $order['order_key'],
							'order_number'     => (string) $order['order_number'],
							'special_requests' => $special_requests,
							'group_name'       => $group_name,
							'is_tack'          => isset( $tack_lookup[ (string) $unit ] ),
							'suggested'        => false,
						);
						$this->mark_stall_chart_unit_occupied( $stall_map, $unit, array( $date_key ), $order['order_key'] );
					}
				}
			}

			$saved_rv_set = array_fill_keys( array_map( 'strval', (array) $rv_manual ), true );
			foreach ( $rv_units['assigned'] as $unit ) {
				$rv_unit_suggested = ! isset( $saved_rv_set[ (string) $unit ] );
				if ( $rv_unit_suggested ) {
					$unsaved_order_keys[ $order['order_key'] ] = true;
				}
				foreach ( $rv_dates as $date_key ) {
					if ( isset( $rv_rows[ $unit ]['cells'][ $date_key ] ) ) {
						$rv_rows[ $unit ]['cells'][ $date_key ] = array(
							'type'             => 'occupied',
							'label'            => $order['customer_name'],
							'order_key'        => $order['order_key'],
							'order_number'     => (string) $order['order_number'],
							'special_requests' => $special_requests,
							'group_name'       => $group_name,
							'suggested'        => $rv_unit_suggested,
						);
					}
				}
			}

			// Per-night override overlay for RV lots (v1 #4) — mirror of the stall
			// overlay above. If this order carries a saved RV Lot Night Map, vacate
			// the lots it no longer wants on overridden nights and occupy the new lot.
			if ( '' !== (string) $this->get_order_component_note_value( $order, 'rv', 'RV Lot Night Map' ) ) {
				$rv_night_assign = $this->get_order_night_assignments( $order, $rv_dates, $rv_manual, 'rv', 'RV Lot Night Map' );
				foreach ( $rv_dates as $date_key ) {
					$want = isset( $rv_night_assign[ $date_key ] ) ? array_map( 'strval', (array) $rv_night_assign[ $date_key ] ) : array();

					foreach ( (array) $rv_units['assigned'] as $held ) {
						if ( in_array( (string) $held, $want, true ) ) {
							continue;
						}
						if ( isset( $rv_rows[ $held ]['cells'][ $date_key ]['order_key'] )
							&& (string) $rv_rows[ $held ]['cells'][ $date_key ]['order_key'] === (string) $order['order_key'] ) {
							$rv_rows[ $held ]['cells'][ $date_key ] = array( 'type' => 'available', 'label' => __( 'Available', 'equine-event-manager' ) );
							unset( $rv_map[ $held ][ $date_key ] );
						}
					}

					foreach ( $want as $unit ) {
						if ( ! isset( $rv_rows[ $unit ]['cells'][ $date_key ] ) ) {
							continue;
						}
						$cur = $rv_rows[ $unit ]['cells'][ $date_key ];
						if ( isset( $cur['type'] ) && 'occupied' === $cur['type'] && (string) ( $cur['order_key'] ?? '' ) !== (string) $order['order_key'] ) {
							continue;
						}
						$rv_rows[ $unit ]['cells'][ $date_key ] = array(
							'type'             => 'occupied',
							'label'            => $order['customer_name'],
							'order_key'        => $order['order_key'],
							'order_number'     => (string) $order['order_number'],
							'special_requests' => $special_requests,
							'group_name'       => $group_name,
							'suggested'        => false,
						);
						$this->mark_stall_chart_unit_occupied( $rv_map, $unit, array( $date_key ), $order['order_key'] );
					}
				}
			}

			if ( $stall_units['unassigned'] > 0 ) {
				$issues[] = array(
					'text'          => sprintf( __( 'Order #%1$s (%2$s) still has %3$d unassigned stall(s).', 'equine-event-manager' ), $order['order_number'], $order['customer_name'], $stall_units['unassigned'] ),
					'order_key'     => $order['order_key'],
					'order_number'  => $order['order_number'],
				);
			}

			if ( $rv_units['unassigned'] > 0 ) {
				$issues[] = array(
					'text'          => sprintf( __( 'Order #%1$s (%2$s) still has %3$d unassigned RV lot(s).', 'equine-event-manager' ), $order['order_number'], $order['customer_name'], $rv_units['unassigned'] ),
					'order_key'     => $order['order_key'],
					'order_number'  => $order['order_number'],
				);
			}
		}

		return array(
			'date_columns' => $date_columns,
			'stall_rows'   => array_values( $stall_rows ),
			'rv_rows'      => array_values( $rv_rows ),
			'movement_summary' => $movement,
			'issues'       => array_values( $issues ),
			'order_count'  => count( $orders ),
			'unsaved_order_count' => count( $unsaved_order_keys ),
		);
	}

	/**
	 * Build arriving/departing counts for each chart date.
	 *
	 * @param array $orders Order rows.
	 * @param array $date_columns Date columns keyed by Y-m-d.
	 * @return array<string, array<string, int>>
	 */
	private function build_stall_chart_movement_summary( $orders, $date_columns ) {
		$summary = array();

		foreach ( array_keys( $date_columns ) as $date_key ) {
			$summary[ $date_key ] = array(
				'arriving'  => 0,
				'departing' => 0,
			);
		}

		foreach ( (array) $orders as $order ) {
			$movement_dates = $this->get_order_stall_chart_movement_dates( $order );

			if ( ! empty( $movement_dates['arrival'] ) && isset( $summary[ $movement_dates['arrival'] ] ) ) {
				$summary[ $movement_dates['arrival'] ]['arriving']++;
			}

			if ( ! empty( $movement_dates['departure'] ) && isset( $summary[ $movement_dates['departure'] ] ) ) {
				$summary[ $movement_dates['departure'] ]['departing']++;
			}
		}

		return $summary;
	}

	/**
	 * Resolve a single arrival/departure pair for chart movement summaries.
	 *
	 * @param array $order Order row.
	 * @return array<string, string>
	 */
	private function get_order_stall_chart_movement_dates( $order ) {
		$arrival_candidates   = array_filter(
			array(
				isset( $order['stall_arrival_date'] ) ? sanitize_text_field( $order['stall_arrival_date'] ) : '',
				isset( $order['rv_arrival_date'] ) ? sanitize_text_field( $order['rv_arrival_date'] ) : '',
			)
		);
		$departure_candidates = array_filter(
			array(
				isset( $order['stall_departure_date'] ) ? sanitize_text_field( $order['stall_departure_date'] ) : '',
				isset( $order['rv_departure_date'] ) ? sanitize_text_field( $order['rv_departure_date'] ) : '',
			)
		);

		sort( $arrival_candidates );
		sort( $departure_candidates );

		return array(
			'arrival'   => ! empty( $arrival_candidates ) ? reset( $arrival_candidates ) : '',
			'departure' => ! empty( $departure_candidates ) ? end( $departure_candidates ) : '',
		);
	}

	/**
	 * Initialize per-unit stall chart rows with per-date cell states.
	 *
	 * @param array $units        All configured stall unit names.
	 * @param array $blocks       Legacy block definitions (used when barn_map is empty).
	 * @param array $blocked_units Blocked unit names.
	 * @param array $date_columns Date columns keyed by Y-m-d.
	 * @param array $barn_map     Optional precomputed unit→barn-name map (V1 rows).
	 * @return array<string, array>
	 */
	private function initialize_stall_chart_unit_rows( $units, $blocks, $blocked_units, $date_columns, $barn_map = array() ) {
		$rows      = array();
		$block_map = ! empty( $barn_map ) ? $barn_map : $this->map_stall_chart_unit_blocks( $blocks );

		foreach ( (array) $units as $unit ) {
			$cells = array();

			foreach ( array_keys( $date_columns ) as $date_key ) {
				$is_blocked        = in_array( $unit, $blocked_units, true );
				$cells[ $date_key ] = array(
					'type'      => $is_blocked ? 'blocked' : 'available',
					'label'     => $is_blocked ? __( 'Blocked', 'equine-event-manager' ) : __( 'Available', 'equine-event-manager' ),
					'order_key' => '',
				);
			}

			$rows[ $unit ] = array(
				'unit'  => $unit,
				'block' => isset( $block_map[ $unit ] ) ? $block_map[ $unit ] : '',
				'cells' => $cells,
			);
		}

		return $rows;
	}

	/**
	 * Initialize RV occupancy rows from configured lot names.
	 *
	 * @param array $lot_names Configured RV lot names.
	 * @param array $date_columns Date columns.
	 * @return array
	 */
	private function initialize_rv_lot_chart_rows( $lot_names, $blocked_lot_names, $date_columns ) {
		$rows = array();

		foreach ( (array) $lot_names as $lot_name ) {
			$cells = array();

			foreach ( array_keys( $date_columns ) as $date_key ) {
				$is_blocked        = in_array( $lot_name, (array) $blocked_lot_names, true );
				$cells[ $date_key ] = array(
					'type'      => $is_blocked ? 'blocked' : 'available',
					'label'     => $is_blocked ? __( 'Blocked', 'equine-event-manager' ) : __( 'Available', 'equine-event-manager' ),
					'order_key' => '',
				);
			}

			$rows[ $lot_name ] = array(
				'unit'  => $lot_name,
				'block' => __( 'RV Lot', 'equine-event-manager' ),
				'cells' => $cells,
			);
		}

		return $rows;
	}

	/**
	 * Map each expanded unit to its block label.
	 *
	 * @param array $blocks Block definitions.
	 * @return array
	 */
	private function map_stall_chart_unit_blocks( $blocks ) {
		$map = array();

		foreach ( (array) $blocks as $block ) {
			$label = isset( $block['label'] ) ? sanitize_text_field( $block['label'] ) : '';
			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( '' === $label || ! $start || ! $end ) {
				continue;
			}

			for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
				$map[ (string) $number ] = $label;
			}
		}

		return $map;
	}

	/**
	 * Get unique barn/block labels for the stall chart filter tabs.
	 *
	 * When V1 row-based barn names are provided directly, they are used as-is.
	 * Falls back to extracting `label` fields from legacy block definitions.
	 *
	 * @param array $blocks     Legacy block definitions.
	 * @param array $barn_names Optional precomputed barn names from V1 rows.
	 * @return array<int, string>
	 */
	private function get_stall_chart_block_filter_options( $blocks, $barn_names = array() ) {
		if ( ! empty( $barn_names ) ) {
			$options = array_values( array_unique( array_filter( array_map( 'strval', $barn_names ) ) ) );
			sort( $options, SORT_NATURAL | SORT_FLAG_CASE );
			return $options;
		}

		$options = array();

		foreach ( (array) $blocks as $block ) {
			$label = isset( $block['label'] ) ? sanitize_text_field( $block['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$options[] = $label;
		}

		$options = array_values( array_unique( array_filter( $options ) ) );
		sort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	/**
	 * Render a stall/RV occupancy matrix table.
	 *
	 * @param array $rows Unit rows.
	 * @param array $date_columns Date columns.
	 * @return void
	 */
	/**
	 * AJAX: move a customer's stall assignment from source stall to destination stall.
	 *
	 * Accepts: order_id (order_key), source_stall, source_date, destination_stall,
	 * scope (this-night | all-nights). Updates the stored "Assigned Stall Units"
	 * note line on the matching stall component, swapping the source unit token
	 * for the destination unit. "all-nights" scope rewrites the assignment for
	 * every night the order is configured for; "this-night" scope only affects
	 * the matrix cell for source_date (in practice the assignment list is the
	 * same across nights for a given order/component, so both paths end up
	 * performing the same swap — this preserves the API surface for future
	 * per-night persistence).
	 *
	 * @return void
	 */
	/**
	 * AJAX: toggle a stall's "tack" designation for an order (V1 #5).
	 *
	 * Operational only — does NOT change pricing. Adds/removes the stall from the
	 * order's `Tack Stalls:` note line. The stall must already be assigned to the
	 * order. Reuses the chart-move nonce.
	 *
	 * @return void
	 */
	public function ajax_toggle_tack_stall() {
		check_ajax_referer( 'eem_stall_chart_move', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['order_id'] ) ) : '';
		$stall     = isset( $_POST['stall'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['stall'] ) ) : '';

		if ( '' === $order_key || '' === $stall ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'equine-event-manager' ) ), 400 );
		}

		$order = $this->orders_repository->get_order( $order_key );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		// The stall must actually belong to this order.
		$assigned = (array) $this->parse_assigned_units_string(
			$this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' )
		);
		if ( ! in_array( $stall, $assigned, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That stall is not assigned to this order.', 'equine-event-manager' ) ), 409 );
		}

		// Toggle the stall in/out of the tack list.
		$tack = (array) $this->parse_assigned_units_string(
			$this->get_order_component_note_value( $order, 'stall', 'Tack Stalls' )
		);
		if ( in_array( $stall, $tack, true ) ) {
			$tack    = array_values( array_diff( $tack, array( $stall ) ) );
			$is_tack = false;
		} else {
			$tack[]  = $stall;
			$is_tack = true;
		}

		$ok = $this->orders_repository->update_order_tack_stalls(
			$order_key,
			implode( ', ', array_filter( array_map( 'strval', $tack ) ) )
		);
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not update tack designation.', 'equine-event-manager' ) ), 500 );
		}

		wp_send_json_success( array(
			'is_tack' => $is_tack,
			'stall'   => $stall,
			'message' => $is_tack
				? __( 'Marked as tack stall.', 'equine-event-manager' )
				: __( 'Tack designation removed.', 'equine-event-manager' ),
		) );
	}

	public function ajax_move_stall_assignment() {
		check_ajax_referer( 'eem_stall_chart_move', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key   = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['order_id'] ) ) : '';
		$src_stall   = isset( $_POST['source_stall'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_stall'] ) ) : '';
		$src_date    = isset( $_POST['source_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_date'] ) ) : '';
		$dest_stall  = isset( $_POST['destination_stall'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['destination_stall'] ) ) : '';
		$scope       = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['scope'] ) ) : 'this-night';
		// v1 #4: the same move flow drives stall AND RV-lot per-night moves; the
		// kind picks which component / assignment note / night-map / chart config
		// the swap reads and writes.
		$kind        = ( isset( $_POST['kind'] ) && 'rv' === sanitize_key( wp_unslash( $_POST['kind'] ) ) ) ? 'rv' : 'stall';
		$is_rv       = ( 'rv' === $kind );
		$note_comp   = $is_rv ? 'rv' : 'stall';
		$assign_lbl  = $is_rv ? 'Assigned RV Lots' : 'Assigned Stall Units';
		$night_lbl   = $is_rv ? 'RV Lot Night Map' : 'Stall Night Map';
		$unit_noun   = $is_rv ? __( 'lot', 'equine-event-manager' ) : __( 'stall', 'equine-event-manager' );

		if ( '' === $order_key || '' === $src_stall || '' === $dest_stall ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'equine-event-manager' ) ), 400 );
		}

		$order = $this->orders_repository->get_order( $order_key );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		// Serialize the per-DATE conflict re-check + write below behind the
		// per-reservation lock (shared with checkout + other admin assigns).
		$reservation_id = isset( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
		if ( ! $this->acquire_assignment_lock( $reservation_id ) ) {
			$this->send_assignment_lock_busy();
		}

		// This order's occupied nights (for the active kind), and which this move
		// targets. "Just this night" (this-night) scopes the move to $src_date
		// only; any other scope moves the whole stay. Falling back to all nights
		// when the single date is missing keeps the move safe rather than a no-op.
		$arrival   = $is_rv ? $order['rv_arrival_date'] : $order['stall_arrival_date'];
		$departure = $is_rv ? $order['rv_departure_date'] : $order['stall_departure_date'];
		$order_dates  = $this->get_stall_chart_occupied_dates( $arrival, $departure );
		$single_night = ( 'this-night' === $scope && '' !== $src_date && in_array( $src_date, $order_dates, true ) );
		$target_dates = $single_night ? array( $src_date ) : $order_dates;

		// Validate the destination unit exists in the chart + isn't blocked.
		if ( $reservation_id > 0 ) {
			$config       = $this->get_stall_chart_config( $reservation_id );
			$blocked_pool = $is_rv ? ( $config['blocked_rv_lots'] ?? array() ) : ( $config['blocked_stall_units'] ?? array() );
			$unit_pool    = $is_rv ? ( $config['rv_lot_names'] ?? array() ) : ( $config['stall_units'] ?? array() );
			if ( in_array( $dest_stall, (array) $blocked_pool, true ) ) {
				wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot noun */ __( 'Destination %s is blocked.', 'equine-event-manager' ), $unit_noun ) ), 409 );
			}
			if ( ! in_array( $dest_stall, (array) $unit_pool, true ) ) {
				wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot noun */ __( 'Destination %s is not part of this chart.', 'equine-event-manager' ), $unit_noun ) ), 409 );
			}

			// Per-DATE conflict check: is the destination unit occupied by ANOTHER
			// order on any of the dates this move actually touches? (A whole-stay
			// move checks every night; a single-night move checks just that night.)
			$other_orders = array_filter(
				$this->orders_repository->get_orders( '', 'date', 'asc' ),
				function ( $o ) use ( $reservation_id, $order_key ) {
					return absint( isset( $o['reservation_id'] ) ? $o['reservation_id'] : 0 ) === absint( $reservation_id )
						&& ( ! isset( $o['order_key'] ) || (string) $o['order_key'] !== (string) $order_key );
				}
			);
			$target_lookup = array_fill_keys( $target_dates, true );
			foreach ( $other_orders as $other ) {
				$other_arr   = $is_rv ? $other['rv_arrival_date'] : $other['stall_arrival_date'];
				$other_dep   = $is_rv ? $other['rv_departure_date'] : $other['stall_departure_date'];
				$other_dates = $this->get_stall_chart_occupied_dates( $other_arr, $other_dep );
				$other_flat  = $this->parse_assigned_units_string( $this->get_order_component_note_value( $other, $note_comp, $assign_lbl ) );
				$other_night = $this->get_order_night_assignments( $other, $other_dates, $other_flat, $note_comp, $night_lbl );
				foreach ( $other_night as $d => $units ) {
					if ( isset( $target_lookup[ $d ] ) && in_array( (string) $dest_stall, array_map( 'strval', (array) $units ), true ) ) {
						wp_send_json_error( array( 'message' => sprintf( /* translators: %s: stall/lot noun */ __( 'Destination %s is already reserved on one of those nights.', 'equine-event-manager' ), $unit_noun ) ), 409 );
					}
				}
			}
		}

		// Resolve the order's current per-night assignment, then swap src -> dest
		// only on the target date(s).
		$flat  = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, $note_comp, $assign_lbl ) );
		$night = $this->get_order_night_assignments( $order, $order_dates, $flat, $note_comp, $night_lbl );
		foreach ( $target_dates as $d ) {
			$units   = isset( $night[ $d ] ) ? array_values( array_map( 'strval', (array) $night[ $d ] ) ) : array();
			$swapped = array();
			$did     = false;
			foreach ( $units as $u ) {
				if ( ! $did && (string) $u === (string) $src_stall ) {
					$swapped[] = (string) $dest_stall;
					$did       = true;
				} else {
					$swapped[] = $u;
				}
			}
			if ( ! $did ) {
				$swapped[] = (string) $dest_stall; // source not present that night — place dest.
			}
			$night[ $d ] = array_values( array_unique( $swapped ) );
		}

		// Flat = union of every night's units; map = '' when uniform.
		$new_flat = array();
		foreach ( $night as $units ) {
			foreach ( (array) $units as $u ) {
				$new_flat[ (string) $u ] = true;
			}
		}
		$new_flat   = array_keys( $new_flat );
		$serialized = $this->serialize_stall_night_map( $night );
		$new_csv    = implode( ', ', array_filter( array_map( 'strval', $new_flat ) ) );

		// Preserve the OTHER component's assignment untouched while we rewrite this
		// kind's flat assignment (update_order_unit_assignments takes both CSVs).
		if ( $is_rv ) {
			$preserved_stall = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' ) );
			$stall_csv = implode( ', ', array_filter( array_map( 'strval', (array) $preserved_stall ) ) );
			$rv_csv    = $new_csv;
		} else {
			$preserved_rv = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) );
			$stall_csv = $new_csv;
			$rv_csv    = implode( ', ', array_filter( array_map( 'strval', (array) $preserved_rv ) ) );
		}

		$ok = $this->orders_repository->update_order_unit_assignments( $order_key, $stall_csv, $rv_csv );
		// Persist (or clear) the per-night override map on the active kind.
		if ( $is_rv ) {
			$this->orders_repository->update_order_rv_night_map( $order_key, $serialized );
		} else {
			$this->orders_repository->update_order_stall_night_map( $order_key, $serialized );
		}

		$this->release_assignment_lock( $reservation_id );

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not update assignment.', 'equine-event-manager' ) ), 500 );
		}

		wp_send_json_success( array(
			'message'           => $is_rv
				? __( 'RV lot assignment moved.', 'equine-event-manager' )
				: __( 'Stall assignment moved.', 'equine-event-manager' ),
			'order_id'          => $order_key,
			'kind'              => $kind,
			'source_stall'      => $src_stall,
			'destination_stall' => $dest_stall,
			'scope'             => $scope,
			'source_date'       => $src_date,
		) );
	}

	/**
	 * AJAX: auto-assign unassigned bulk stalls/RV lots to available inventory.
	 *
	 * Drives both the action-bar "Generate Assignments" / issues-card
	 * "Auto-Assign All" buttons (reservation-wide) and the per-row
	 * "Auto-Assign" button (single order via the optional order_key param).
	 * Delegates the conflict-aware fill to
	 * EEM_Orders_Repository::auto_assign_units_for_reservation(), then returns
	 * the freshly-rendered dynamic chart region so the client can swap
	 * #eem-stall-chart-dynamic without a full page reload.
	 *
	 * Accepts: reservation_id (int, required), order_key (string, optional),
	 * inv (all|stalls|rv), tab (location|customer).
	 *
	 * @return void
	 */
	public function ajax_auto_assign() {
		check_ajax_referer( 'eem_auto_assign', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$order_key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['order_key'] ) ) : '';
		$inv            = isset( $_POST['inv'] ) ? sanitize_key( wp_unslash( $_POST['inv'] ) ) : 'all';
		$inv            = in_array( $inv, array( 'all', 'stalls', 'rv' ), true ) ? $inv : 'all';
		$tab            = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'location';
		$tab            = in_array( $tab, array( 'location', 'customer' ), true ) ? $tab : 'location';

		if ( $reservation_id < 1 || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		// The conflict-aware fill reads every order then writes assignments back —
		// serialize it behind the per-reservation lock so it can't race a checkout
		// or another admin assign and double-book a stall / RV lot.
		if ( ! $this->acquire_assignment_lock( $reservation_id ) ) {
			$this->send_assignment_lock_busy();
		}
		try {
			$result = $this->orders_repository->auto_assign_units_for_reservation( $reservation_id, $order_key );
		} finally {
			$this->release_assignment_lock( $reservation_id );
		}

		// Re-render the dynamic region against fresh assignment state.
		$config = $this->get_stall_chart_config( $reservation_id );
		ob_start();
		$this->render_stall_chart_dynamic_region( $reservation_id, $config, $inv, $tab );
		$html = ob_get_clean();

		$updated     = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
		$shortages   = isset( $result['shortages'] ) ? (array) $result['shortages'] : array();
		$has_shortfall = false;
		foreach ( $shortages as $shortage ) {
			if ( '' !== $order_key && (string) $shortage['order_key'] !== $order_key ) {
				continue;
			}
			if ( ! empty( $shortage['stall_unassigned'] ) || ! empty( $shortage['rv_unassigned'] ) ) {
				$has_shortfall = true;
				break;
			}
		}

		// Nothing could be assigned and a need remains → no available inventory.
		if ( 0 === $updated && $has_shortfall ) {
			wp_send_json_error( array(
				'message' => __( 'No available inventory to assign. All matching stalls or RV lots are occupied or blocked.', 'equine-event-manager' ),
				'html'    => $html,
			), 409 );
		}

		if ( 0 === $updated ) {
			$message = __( 'Assignments are already up to date.', 'equine-event-manager' );
		} elseif ( $has_shortfall ) {
			$message = __( 'Assignments updated, but some units could not be placed — see remaining issues.', 'equine-event-manager' );
		} else {
			$message = __( 'Assignments generated.', 'equine-event-manager' );
		}

		wp_send_json_success( array(
			'message'       => $message,
			'updated'       => $updated,
			'has_shortfall' => $has_shortfall,
			'html'          => $html,
		) );
	}

	private function render_stall_chart_matrix_table( $rows, $date_columns, $primary_label = 'Unit', $secondary_label = 'Block', $zone_map = array(), $kind = 'stall' ) {
		$kind = ( 'rv' === $kind ) ? 'rv' : 'stall';
		$show_secondary_column = '' !== (string) $secondary_label;
		$table_class = ( 'Stall' === $primary_label || 'Unit' === $primary_label ) ? 'eem-stall-chart-table' : 'eem-rv-chart-table';
		?>
		<div class="eem-chart-table-scroll">
			<table class="<?php echo esc_attr( $table_class ); ?>">
				<thead>
					<tr>
						<th><?php echo esc_html( $primary_label ); ?></th>
						<?php if ( $show_secondary_column ) : ?>
							<th><?php echo esc_html( $secondary_label ); ?></th>
						<?php endif; ?>
						<?php foreach ( $date_columns as $date_label ) : ?>
							<th class="eem-chart-date-col"><?php echo esc_html( $date_label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$current_block = null;
					foreach ( $rows as $row ) :
						$block = (string) $row['block'];
						if ( $show_secondary_column && $block !== $current_block ) :
							$current_block = $block;
							?>
							<tr class="eem-chart-barn-row" data-barn="<?php echo esc_attr( sanitize_html_class( strtolower( $block ) ) ); ?>">
								<td colspan="<?php echo esc_attr( (string) ( 2 + count( $date_columns ) ) ); ?>">
									<?php echo esc_html( $block ); ?>
								</td>
							</tr>
							<?php
						endif;
						$search_parts = array( (string) $row['unit'], (string) $row['block'] );
						foreach ( array_keys( $date_columns ) as $date_key ) {
							if ( ! empty( $row['cells'][ $date_key ]['label'] ) ) {
								$search_parts[] = (string) $row['cells'][ $date_key ]['label'];
							}
						}
					?>
					<tr class="eem-chart-stall-row" data-stall-chart-search="<?php echo esc_attr( strtolower( implode( ' ', array_filter( $search_parts ) ) ) ); ?>" data-stall-chart-block="<?php echo esc_attr( strtolower( (string) $row['block'] ) ); ?>" data-barn="<?php echo esc_attr( sanitize_html_class( strtolower( (string) $row['block'] ) ) ); ?>" data-zone="<?php echo esc_attr( isset( $zone_map[ $row['unit'] ] ) ? sanitize_html_class( strtolower( $zone_map[ $row['unit'] ] ) ) : '' ); ?>">
						<td class="eem-chart-unit-num"><?php echo esc_html( $row['unit'] ); ?></td>
						<?php if ( $show_secondary_column ) : ?>
							<td class="eem-chart-block-name"><?php echo esc_html( $row['block'] ); ?></td>
						<?php endif; ?>
						<?php foreach ( array_keys( $date_columns ) as $date_key ) : ?>
							<?php $cell = isset( $row['cells'][ $date_key ] ) ? $row['cells'][ $date_key ] : array( 'type' => 'available', 'label' => __( 'Available', 'equine-event-manager' ) ); ?>
							<td>
								<div class="eem-chart-cell-wrap">
									<?php if ( 'occupied' === $cell['type'] && ! empty( $cell['order_key'] ) ) : ?>
										<?php
										$eem_cell_note  = isset( $cell['special_requests'] ) ? trim( (string) $cell['special_requests'] ) : '';
										$eem_cell_group = isset( $cell['group_name'] ) ? trim( (string) $cell['group_name'] ) : '';
										$eem_cell_gcolor = $this->group_color_for( $eem_cell_group );
										// Combine group + special-requests into one hover tooltip.
										$eem_title_parts = array();
										if ( '' !== $eem_cell_group ) {
											$eem_title_parts[] = sprintf( /* translators: %s: group name */ __( 'Group: %s', 'equine-event-manager' ), $eem_cell_group );
										}
										if ( '' !== $eem_cell_note ) {
											$eem_title_parts[] = sprintf( /* translators: %s: customer special requests text */ __( 'Special requests: %s', 'equine-event-manager' ), $eem_cell_note );
										}
										$eem_pill_title = implode( ' · ', $eem_title_parts );
										?>
										<span class="eem-occ-pill eem-occ-pill--reserved<?php echo '' !== $eem_cell_note ? ' eem-occ-pill--has-note' : ''; ?><?php echo ! empty( $cell['suggested'] ) ? ' eem-occ-pill--suggested' : ''; ?>" data-is-tack="<?php echo ! empty( $cell['is_tack'] ) ? '1' : '0'; ?>" data-suggested="<?php echo ! empty( $cell['suggested'] ) ? '1' : '0'; ?>"
											data-order-key="<?php echo esc_attr( $cell['order_key'] ); ?>"
											data-order-id="<?php echo esc_attr( $cell['order_key'] ); ?>"
											data-eem-action="stall-pill-click"
											data-kind="<?php echo esc_attr( $kind ); ?>"
											data-customer-name="<?php echo esc_attr( $cell['label'] ); ?>"
											data-customer="<?php echo esc_attr( $cell['label'] ); ?>"
											data-order-number="<?php echo esc_attr( $this->format_order_number_display( (string) ( $cell['order_number'] ?? '' ) ) ); ?>"
											<?php if ( '' !== $eem_cell_group ) : ?>data-group-name="<?php echo esc_attr( $eem_cell_group ); ?>" style="--eem-group-color:<?php echo esc_attr( $eem_cell_gcolor ); ?>"<?php endif; ?>
											data-stall="<?php echo esc_attr( (string) $row['unit'] ); ?>"
											data-date="<?php echo esc_attr( (string) $date_key ); ?>"
											<?php if ( '' !== $eem_cell_note ) : ?>data-special-requests="<?php echo esc_attr( $eem_cell_note ); ?>"<?php endif; ?>
											<?php if ( '' !== $eem_pill_title ) : ?>title="<?php echo esc_attr( $eem_pill_title ); ?>"<?php endif; ?>>
											<?php if ( '' !== $eem_cell_group || ! empty( $cell['is_tack'] ) ) : ?><span class="eem-occ-pill__badges"><?php if ( ! empty( $cell['is_tack'] ) ) : ?><span class="eem-occ-badge eem-occ-badge--tack" data-eem-tack-badge><?php esc_html_e( 'Tack', 'equine-event-manager' ); ?></span><?php endif; ?><?php if ( '' !== $eem_cell_group ) : ?><span class="eem-occ-badge eem-occ-badge--group"><?php esc_html_e( 'Group', 'equine-event-manager' ); ?></span><?php endif; ?></span><?php endif; ?>
											<?php if ( '' !== $eem_cell_group ) : ?><span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: group name */ __( '(group: %s)', 'equine-event-manager' ), $eem_cell_group ) ); ?></span><?php endif; ?>
											<?php echo esc_html( $cell['label'] ); ?>
											<?php if ( ! empty( $cell['suggested'] ) ) : ?><span class="screen-reader-text"><?php esc_html_e( '(suggested — not saved)', 'equine-event-manager' ); ?></span><?php endif; ?>
											<?php if ( '' !== $eem_cell_note ) : ?><span class="eem-occ-pill__note-dot" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( '(has special requests)', 'equine-event-manager' ); ?></span><?php endif; ?>
											<svg class="eem-occ-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
										</span>
									<?php elseif ( 'blocked' === $cell['type'] ) : ?>
										<span class="eem-occ-pill eem-occ-pill--blocked"><?php esc_html_e( 'Blocked', 'equine-event-manager' ); ?></span>
									<?php else : ?>
										<span class="eem-occ-pill eem-occ-pill--available"
											data-eem-action="stall-available-click"
											data-kind="<?php echo esc_attr( $kind ); ?>"
											data-stall="<?php echo esc_attr( (string) $row['unit'] ); ?>"
											data-date="<?php echo esc_attr( (string) $date_key ); ?>"><?php esc_html_e( 'Available', 'equine-event-manager' ); ?></span>
									<?php endif; ?>
								</div>
							</td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render a customer-by-night count table.
	 *
	 * @param array $rows Chart rows grouped by order/customer.
	 * @param array $date_columns Date columns.
	 * @return void
	 */
	private function render_stall_chart_order_count_table( $rows, $date_columns ) {
		?>
		<div class="eem-chart-table-scroll">
			<table class="eem-cust-chart-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Order', 'equine-event-manager' ); ?></th>
						<?php foreach ( $date_columns as $date_label ) : ?>
							<th class="eem-chart-date-col"><?php echo esc_html( $date_label ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Stall Assignments', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'RV Lots', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $rows as $row ) : ?>
						<?php
						$eem_row_note  = isset( $row['special_requests'] ) ? trim( (string) $row['special_requests'] ) : '';
						$eem_row_group = isset( $row['group_name'] ) ? trim( (string) $row['group_name'] ) : '';
						$search_parts  = array(
							(string) $row['customer_name'],
							(string) $row['order_number'],
							implode( ' ', (array) $row['stall_units'] ),
							implode( ' ', (array) $row['rv_units'] ),
							$eem_row_note,
							$eem_row_group,
						);
						?>
						<tr data-stall-chart-search="<?php echo esc_attr( strtolower( implode( ' ', array_filter( $search_parts ) ) ) ); ?>" data-stall-chart-block="" data-has-stalls="<?php echo ! empty( $row['stall_units'] ) ? '1' : '0'; ?>" data-has-rv="<?php echo ! empty( $row['rv_units'] ) ? '1' : '0'; ?>" data-group="<?php echo esc_attr( $eem_row_group ); ?>" data-has-tack="<?php echo ! empty( $row['tack_units'] ) ? '1' : '0'; ?>">
							<td>
								<a class="eem-chart-cust-link" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-order&order_key=' . rawurlencode( $row['order_key'] ) ) ); ?>">
									<?php echo esc_html( self::format_customer_last_first( (string) $row['customer_name'] ) ); ?>
								</a>
								<?php if ( '' !== $eem_row_group ) : ?>
									<div class="eem-chart-cust-group">
										<span class="eem-chart-cust-group__chip" style="--eem-group-color:<?php echo esc_attr( $this->group_color_for( $eem_row_group ) ); ?>" title="<?php echo esc_attr( $eem_row_group ); ?>"><span class="eem-chart-cust-group__dot" aria-hidden="true"></span><?php echo esc_html( $eem_row_group ); ?></span>
									</div>
								<?php endif; ?>
								<?php if ( '' !== $eem_row_note ) : ?>
									<div class="eem-chart-cust-note" title="<?php echo esc_attr( $eem_row_note ); ?>">
										<span class="eem-chart-cust-note__label"><?php esc_html_e( 'Special requests:', 'equine-event-manager' ); ?></span>
										<?php echo esc_html( $eem_row_note ); ?>
									</div>
								<?php endif; ?>
							</td>
							<td class="eem-chart-order-num">
								<a class="eem-chart-order-link" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-order&order_key=' . rawurlencode( $row['order_key'] ) ) ); ?>">
									<?php echo esc_html( $this->format_order_number_display( (string) $row['order_number'] ) ); ?>
								</a>
							</td>
							<?php foreach ( array_keys( $date_columns ) as $date_key ) : ?>
								<?php $count = isset( $row['daily_counts'][ $date_key ] ) ? absint( $row['daily_counts'][ $date_key ] ) : 0; ?>
								<td class="eem-chart-night-count<?php echo $count > 0 ? ' eem-chart-night-count--has' : ''; ?>">
									<?php echo esc_html( $count > 0 ? number_format_i18n( $count ) : '—' ); ?>
								</td>
							<?php endforeach; ?>
							<td class="eem-chart-stall-assignment"><?php echo ! empty( $row['stall_units'] ) ? wp_kses_post( $this->render_assignment_summary_chips( $row['stall_units'], 'stall' ) ) : '<span class="eem-chart-dash">—</span>'; ?><?php if ( ! empty( $row['tack_units'] ) ) : ?><div class="eem-chart-tack-note" title="<?php esc_attr_e( 'Tack stall(s)', 'equine-event-manager' ); ?>"><span class="eem-chart-tack-note__dot" aria-hidden="true"></span><?php echo esc_html( sprintf( /* translators: %s: comma-separated tack stall numbers */ __( 'Tack: %s', 'equine-event-manager' ), implode( ', ', array_map( 'strval', (array) $row['tack_units'] ) ) ) ); ?></div><?php endif; ?></td>
							<td class="eem-chart-rv-assignment"><?php echo ! empty( $row['rv_units'] ) ? wp_kses_post( $this->render_assignment_summary_chips( $row['rv_units'], 'rv' ) ) : '<span class="eem-chart-dash">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Expand block ranges into flat unit numbers.
	 *
	 * @param array $blocks Configured blocks.
	 * @return array
	 */
	private function expand_stall_chart_units( $blocks ) {
		$units = array();

		foreach ( (array) $blocks as $block ) {
			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( ! $start || ! $end ) {
				continue;
			}

			for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
				$units[] = (string) $number;
			}
		}

		return array_values( array_unique( $units ) );
	}

	/**
	 * Expand a label range into individual unit strings.
	 *
	 * Handles purely numeric ranges (100–131) and prefix-numeric ranges
	 * (Y1–Y12, A-01–A-12). Returns the two endpoints as a fallback when
	 * neither pattern matches.
	 *
	 * @param string $first First label in the range.
	 * @param string $last  Last label in the range.
	 * @return array<int, string>
	 */
	private function expand_label_range( string $first, string $last ): array {
		// Pure numeric range.
		if ( is_numeric( $first ) && is_numeric( $last ) ) {
			$start = (int) $first;
			$end   = (int) $last;
			$units = array();
			for ( $i = min( $start, $end ); $i <= max( $start, $end ); $i++ ) {
				$units[] = (string) $i;
			}
			return $units;
		}
		// Prefix-numeric range (Y1–Y12, A-01–A-12, etc.).
		if ( preg_match( '/^([A-Za-z][A-Za-z\-]*)(\d+)$/', $first, $fm )
			&& preg_match( '/^([A-Za-z][A-Za-z\-]*)(\d+)$/', $last, $lm )
			&& $fm[1] === $lm[1] ) {
			$prefix = $fm[1];
			$start  = (int) $fm[2];
			$end    = (int) $lm[2];
			$units  = array();
			for ( $i = min( $start, $end ); $i <= max( $start, $end ); $i++ ) {
				$units[] = $prefix . $i;
			}
			return $units;
		}
		// Fallback: just the two endpoints.
		return array_values( array_unique( array( $first, $last ) ) );
	}

	/**
	 * Expand V1 stall row definitions into a flat list of unit names.
	 *
	 * V1 rows use `first`/`last` string labels for one-sided rows (e.g.
	 * "200"/"215", "Y1"/"Y12") and `top_first`/`top_last`/`bot_first`/`bot_last`
	 * for back-to-back rows (e.g. top side "100"/"115", bottom side "116"/"131").
	 * Each side contributes every unit in its [first, last] range inclusive.
	 *
	 * @param array $v1_rows V1 stall rows from `_en_stall_rows` meta.
	 * @return array<int, string>
	 */
	private function expand_v1_stall_rows( array $v1_rows ): array {
		$units = array();
		foreach ( $v1_rows as $row ) {
			$layout = isset( $row['layout'] ) ? $row['layout'] : 'one-sided';
			if ( 'back-to-back' === $layout ) {
				$top_first = isset( $row['top_first'] ) ? (string) $row['top_first'] : '';
				$top_last  = isset( $row['top_last'] )  ? (string) $row['top_last']  : '';
				$bot_first = isset( $row['bot_first'] ) ? (string) $row['bot_first'] : '';
				$bot_last  = isset( $row['bot_last'] )  ? (string) $row['bot_last']  : '';
				if ( '' !== $top_first && '' !== $top_last ) {
					$units = array_merge( $units, $this->expand_label_range( $top_first, $top_last ) );
				}
				if ( '' !== $bot_first && '' !== $bot_last ) {
					$units = array_merge( $units, $this->expand_label_range( $bot_first, $bot_last ) );
				}
			} else {
				$first = isset( $row['first'] ) ? (string) $row['first'] : '';
				$last  = isset( $row['last'] )  ? (string) $row['last']  : '';
				if ( '' !== $first && '' !== $last ) {
					$units = array_merge( $units, $this->expand_label_range( $first, $last ) );
				}
			}
		}
		return array_values( array_unique( $units ) );
	}

	/**
	 * Build a unit-to-barn-name map from V1 stall rows.
	 *
	 * Returns an associative array keyed by unit name whose value is the
	 * human-readable barn/section name (e.g. "Red Barn", "Y Section").
	 * Used by the chart matrix to group rows into barn header bands.
	 * Handles both one-sided (first/last) and back-to-back
	 * (top_first/top_last/bot_first/bot_last) row layouts.
	 *
	 * @param array $v1_rows V1 stall rows from `_en_stall_rows` meta.
	 * @return array<string, string>
	 */
	private function build_barn_map_from_v1_rows( array $v1_rows ): array {
		$map = array();
		foreach ( $v1_rows as $row ) {
			$barn   = isset( $row['name'] )   ? sanitize_text_field( $row['name'] ) : '';
			$layout = isset( $row['layout'] ) ? $row['layout'] : 'one-sided';
			if ( '' === $barn ) {
				continue;
			}
			if ( 'back-to-back' === $layout ) {
				$top_first = isset( $row['top_first'] ) ? (string) $row['top_first'] : '';
				$top_last  = isset( $row['top_last'] )  ? (string) $row['top_last']  : '';
				$bot_first = isset( $row['bot_first'] ) ? (string) $row['bot_first'] : '';
				$bot_last  = isset( $row['bot_last'] )  ? (string) $row['bot_last']  : '';
				if ( '' !== $top_first && '' !== $top_last ) {
					foreach ( $this->expand_label_range( $top_first, $top_last ) as $unit ) {
						$map[ $unit ] = $barn;
					}
				}
				if ( '' !== $bot_first && '' !== $bot_last ) {
					foreach ( $this->expand_label_range( $bot_first, $bot_last ) as $unit ) {
						$map[ $unit ] = $barn;
					}
				}
			} else {
				$first = isset( $row['first'] ) ? (string) $row['first'] : '';
				$last  = isset( $row['last'] )  ? (string) $row['last']  : '';
				if ( '' === $first || '' === $last ) {
					continue;
				}
				foreach ( $this->expand_label_range( $first, $last ) as $unit ) {
					$map[ $unit ] = $barn;
				}
			}
		}
		return $map;
	}

	/**
	 * Expand V1 RV row definitions into a flat list of lot name strings.
	 *
	 * Each V1 RV row has a `name` prefix (e.g. "Premium Row") and numeric
	 * `first`/`last` values (e.g. "1"/"10"). The expansion produces lot
	 * names like "Premium Row 1", "Premium Row 2", …, "Premium Row 10".
	 *
	 * @param array $v1_rv_rows V1 RV rows from `_en_rv_rows` meta.
	 * @return array<int, string>
	 */
	private function expand_rv_lot_names_from_v1_rows( array $v1_rv_rows ): array {
		$names = array();
		foreach ( $v1_rv_rows as $row ) {
			$name  = isset( $row['name'] )  ? sanitize_text_field( $row['name'] ) : '';
			$first = isset( $row['first'] ) ? (string) $row['first'] : '';
			$last  = isset( $row['last'] )  ? (string) $row['last']  : '';
			if ( '' === $name || '' === $first || '' === $last ) {
				continue;
			}
			foreach ( $this->expand_label_range( $first, $last ) as $num ) {
				$names[] = $name . ' ' . $num;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Sanitize a selected chart unit list against the configured unit pool.
	 *
	 * @param array $values Submitted unit values.
	 * @param array $allowed Allowed unit pool.
	 * @return array
	 */
	private function sanitize_chart_unit_list( $values, $allowed ) {
		$values = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) $values ) ) );

		return array_values( array_intersect( array_unique( $values ), (array) $allowed ) );
	}

	/**
	 * Sanitize a posted assignment selection into a stored comma-separated string.
	 *
	 * @param mixed $values Selected values.
	 * @return string
	 */
	private function sanitize_assignment_selection( $values, $max_items = 0 ) {
		$values = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) $values ) ) );
		$values = array_values( array_unique( $values ) );

		if ( $max_items > 0 ) {
			$values = array_slice( $values, 0, absint( $max_items ) );
		}

		return implode( ', ', $values );
	}

	/**
	 * Render a unit assignment select field.
	 *
	 * @param string $field_name Field name.
	 * @param array  $options Available unit options.
	 * @param array  $selected Selected unit values.
	 * @param array  $blocked Blocked unit values.
	 * @return void
	 */
	private function render_assignment_select_field( $field_name, $options, $selected, $blocked = array(), $max_items = 0 ) {
		$options  = array_values( array_unique( array_map( 'strval', (array) $options ) ) );
		$selected = array_values( array_unique( array_map( 'strval', (array) $selected ) ) );
		$blocked  = array_values( array_unique( array_map( 'strval', (array) $blocked ) ) );
		$visible_rows = $max_items > 0 ? absint( $max_items ) + 1 : max( count( $selected ), min( count( $options ), 6 ) );
		$visible_rows = max( 4, min( 8, $visible_rows ) );
		?>
		<div class="eem-order-assignment-form__picker">
			<select class="eem-order-assignment-form__select" name="<?php echo esc_attr( $field_name ); ?>[]" multiple="multiple" size="<?php echo esc_attr( $visible_rows ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<?php $is_blocked = in_array( $option, $blocked, true ); ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( in_array( $option, $selected, true ) ); ?><?php disabled( $is_blocked && ! in_array( $option, $selected, true ) ); ?>>
						<?php echo esc_html( $is_blocked ? $option . ' (' . __( 'Blocked', 'equine-event-manager' ) . ')' : $option ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<p class="description eem-order-assignment-form__help"><?php esc_html_e( 'Select one or more assigned units from the configured stall chart numbers.', 'equine-event-manager' ); ?></p>
		<?php
	}

	/**
	 * Render a searchable RV lot assignment picker.
	 *
	 * @param string $field_name Field name.
	 * @param array  $options Available lot names.
	 * @param array  $selected Selected lot names.
	 * @return void
	 */
	private function render_rv_lot_assignment_select_field( $field_name, $options, $selected, $blocked = array() ) {
		$options  = array_values( array_unique( array_map( 'strval', (array) $options ) ) );
		$selected = array_values( array_unique( array_map( 'strval', (array) $selected ) ) );
		$blocked  = array_values( array_unique( array_map( 'strval', (array) $blocked ) ) );
		$visible_rows = max( 4, min( 8, max( count( $selected ), min( count( $options ), 6 ) ) ) );
		?>
		<div class="eem-order-assignment-form__picker">
			<select class="eem-order-assignment-form__select" name="<?php echo esc_attr( $field_name ); ?>[]" multiple="multiple" size="<?php echo esc_attr( $visible_rows ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<?php $is_blocked = in_array( $option, $blocked, true ); ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( in_array( $option, $selected, true ) ); ?><?php disabled( $is_blocked && ! in_array( $option, $selected, true ) ); ?>>
						<?php echo esc_html( $is_blocked ? $option . ' (' . __( 'Blocked', 'equine-event-manager' ) . ')' : $option ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<p class="description eem-order-assignment-form__help"><?php esc_html_e( 'Choose the reserved RV lot from the configured lot list for this reservation.', 'equine-event-manager' ); ?></p>
		<?php
	}

	/**
	 * Render assigned units as chips for quick review.
	 *
	 * @param array  $values Assigned values.
	 * @param string $type Assignment type.
	 * @return string
	 */
	private function render_assignment_summary_chips( $values, $type = 'stall' ) {
		$values = array_values( array_unique( array_map( 'sanitize_text_field', array_filter( (array) $values ) ) ) );

		if ( empty( $values ) ) {
			return '';
		}

		return esc_html( implode( ', ', $values ) );
	}

	/**
	 * Build chart rows for a reservation.
	 *
	 * @param int   $reservation_id Reservation post ID.
	 * @param array $config         Stall chart config.
	 * @return array
	 */
	private function build_stall_chart_rows( $reservation_id, $config ) {
		$orders    = array_filter(
			$this->orders_repository->get_orders(),
			function ( $order ) use ( $reservation_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === absint( $reservation_id );
			}
		);
		$rows      = array();
		$stall_map = array();
		$rv_map    = array();

		foreach ( $orders as $order ) {
			$stall_dates  = $this->get_stall_chart_occupied_dates( $order['stall_arrival_date'], $order['stall_departure_date'] );
			$rv_dates     = $this->get_stall_chart_occupied_dates( $order['rv_arrival_date'], $order['rv_departure_date'] );
			$stall_needed = absint( $order['stall_quantity'] );
			$rv_needed    = $this->order_requires_rv_assignment( $order ) ? absint( $order['rv_quantity'] ) : 0;
			$stall_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' ) );
			$rv_manual    = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) );
			$rv_lot_name  = $this->parse_rv_lot_name_from_notes( $order['notes'] );
			if ( empty( $rv_manual ) ) {
				$rv_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' ) );
			}
			$stall_units  = $this->allocate_stall_chart_units( $config['available_stall_units'], $stall_map, $stall_dates, $stall_needed, $stall_manual, $order['order_key'] );
			$rv_units     = $this->allocate_rv_lot_rows( isset( $config['rv_lot_names'] ) ? $config['rv_lot_names'] : array(), isset( $config['auto_assign_rv_lot_names'] ) ? $config['auto_assign_rv_lot_names'] : ( isset( $config['available_rv_lot_names'] ) ? $config['available_rv_lot_names'] : array() ), $rv_map, $rv_dates, $rv_needed, $rv_lot_name, $rv_manual, $order['order_key'] );
			$daily_counts = array();

			foreach ( $stall_dates as $date_key ) {
				$daily_counts[ $date_key ] = ( isset( $daily_counts[ $date_key ] ) ? $daily_counts[ $date_key ] : 0 ) + count( $stall_units['assigned'] );
			}

			foreach ( $rv_dates as $date_key ) {
				$daily_counts[ $date_key ] = ( isset( $daily_counts[ $date_key ] ) ? $daily_counts[ $date_key ] : 0 ) + count( $rv_units['assigned'] );
			}

			$unassigned = array();

			if ( $stall_units['unassigned'] > 0 ) {
				$unassigned[] = sprintf( _n( '%d stall unassigned', '%d stalls unassigned', $stall_units['unassigned'], 'equine-event-manager' ), $stall_units['unassigned'] );
			}

			if ( $rv_units['unassigned'] > 0 ) {
				$unassigned[] = sprintf( _n( '%d RV lot unassigned', '%d RV lots unassigned', $rv_units['unassigned'], 'equine-event-manager' ), $rv_units['unassigned'] );
			}

			$rows[] = array(
				'order_key'        => $order['order_key'],
				'order_number'     => $order['order_number'],
				'customer_name'    => $order['customer_name'],
				'daily_counts'     => $daily_counts,
				'stall_units'      => $stall_units['assigned'],
				'rv_units'         => $rv_units['assigned'],
				'unassigned'       => implode( ' | ', $unassigned ),
				// V1 Scenario F: special requests shown under the customer name.
				'special_requests' => trim( (string) $this->get_special_requests_from_order_notes( $order['notes'] ) ),
				// V1 D2: group name tag.
				'group_name'       => $this->get_group_name_from_order_notes( (string) $order['notes'] ),
				// V1 #5: tack stalls (the admin's explicit `Tack Stalls:` note is the
				// source of truth for which stalls are tack — not re-derived from
				// this view's allocation, which can differ from the by-location grid).
				'tack_units'       => array_values( array_map( 'strval', (array) $this->parse_assigned_units_string(
					$this->get_order_component_note_value( $order, 'stall', 'Tack Stalls' )
				) ) ),
			);
		}

		return $rows;
	}

	/**
	 * Allocate RV lot rows by configured lot names.
	 *
	 * @param array  $lot_names Available RV lot names.
	 * @param array  $map Occupancy map, passed by reference.
	 * @param array  $dates Occupied dates.
	 * @param int    $needed Needed lots.
	 * @param string $preferred_lot Preferred lot from order notes.
	 * @param array  $manual_lots Admin-selected lot overrides.
	 * @param string $order_key Order key.
	 * @return array
	 */
	private function allocate_rv_lot_rows( $all_lot_names, $auto_assign_lot_names, &$map, $dates, $needed, $preferred_lot, $manual_lots, $order_key ) {
		$assigned = array();
		$candidates = array();

		foreach ( (array) $manual_lots as $manual_lot ) {
			$manual_lot = sanitize_text_field( $manual_lot );
			if ( '' !== $manual_lot ) {
				$candidates[] = $manual_lot;
			}
		}

		if ( '' !== $preferred_lot ) {
			array_unshift( $candidates, $preferred_lot );
		}

		$candidates = array_values( array_unique( array_filter( $candidates ) ) );

		foreach ( $candidates as $lot_name ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $lot_name, $all_lot_names, true ) && $this->stall_chart_unit_is_available( $map, $lot_name, $dates ) ) {
				$assigned[] = $lot_name;
				$this->mark_stall_chart_unit_occupied( $map, $lot_name, $dates, $order_key );
			}
		}

		foreach ( (array) $auto_assign_lot_names as $lot_name ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $lot_name, $assigned, true ) ) {
				continue;
			}

			if ( $this->stall_chart_unit_is_available( $map, $lot_name, $dates ) ) {
				$assigned[] = $lot_name;
				$this->mark_stall_chart_unit_occupied( $map, $lot_name, $dates, $order_key );
			}
		}

		return array(
			'assigned'   => $assigned,
			'unassigned' => max( 0, $needed - count( $assigned ) ),
		);
	}

	/**
	 * Get RV lot names that are safe for automatic assignment.
	 *
	 * @param array $rv_lots Configured RV lots.
	 * @param array $blocked_rv_lots Blocked RV lot names.
	 * @return array
	 */
	private function get_stall_chart_auto_assignable_rv_lot_names( $rv_lots, $blocked_rv_lots ) {
		$lot_names = array();

		foreach ( (array) $rv_lots as $rv_lot ) {
			if ( ! is_array( $rv_lot ) || empty( $rv_lot['name'] ) ) {
				continue;
			}

			$lot_name      = sanitize_text_field( $rv_lot['name'] );
			$nightly_rate  = isset( $rv_lot['nightly_rate'] ) ? trim( (string) $rv_lot['nightly_rate'] ) : '';
			$weekend_rate  = isset( $rv_lot['weekend_rate'] ) ? trim( (string) $rv_lot['weekend_rate'] ) : '';
			$has_addon_fee = ( '' !== $nightly_rate && (float) $nightly_rate > 0 ) || ( '' !== $weekend_rate && (float) $weekend_rate > 0 );

			if ( $has_addon_fee || in_array( $lot_name, (array) $blocked_rv_lots, true ) ) {
				continue;
			}

			$lot_names[] = $lot_name;
		}

		$lot_names = array_values( array_unique( array_filter( $lot_names ) ) );
		sort( $lot_names, SORT_NATURAL );

		return $lot_names;
	}

	/**
	 * Normalize configured RV lot names for the assignments board.
	 *
	 * @param mixed $rv_lots Raw RV lot configuration.
	 * @return array
	 */
	private function get_stall_chart_rv_lot_names( $rv_lots ) {
		$names = array();

		foreach ( (array) $rv_lots as $rv_lot ) {
			if ( ! is_array( $rv_lot ) || empty( $rv_lot['name'] ) ) {
				continue;
			}

			$names[] = sanitize_text_field( $rv_lot['name'] );
		}

		$names = array_values( array_unique( array_filter( $names ) ) );
		sort( $names, SORT_NATURAL );

		return $names;
	}

	/**
	 * Allocate units from a pool across occupied dates.
	 *
	 * @param array  $pool      Available unit pool.
	 * @param array  $map       Occupancy map, passed by reference.
	 * @param array  $dates     Occupied dates.
	 * @param int    $needed    Needed units.
	 * @param array  $preferred Preferred/manual units.
	 * @param string $order_key Order key.
	 * @return array
	 */
	private function allocate_stall_chart_units( $pool, &$map, $dates, $needed, $preferred, $order_key ) {
		$assigned = array();

		foreach ( (array) $preferred as $unit ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $unit, $pool, true ) && $this->stall_chart_unit_is_available( $map, $unit, $dates ) ) {
				$assigned[] = $unit;
				$this->mark_stall_chart_unit_occupied( $map, $unit, $dates, $order_key );
			}
		}

		foreach ( (array) $pool as $unit ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $unit, $assigned, true ) ) {
				continue;
			}

			if ( $this->stall_chart_unit_is_available( $map, $unit, $dates ) ) {
				$assigned[] = $unit;
				$this->mark_stall_chart_unit_occupied( $map, $unit, $dates, $order_key );
			}
		}

		return array(
			'assigned'   => $assigned,
			'unassigned' => max( 0, $needed - count( $assigned ) ),
		);
	}

	/**
	 * Check whether a unit is free for the supplied dates.
	 *
	 * @param array  $map  Occupancy map.
	 * @param string $unit Unit identifier.
	 * @param array  $dates Occupied dates.
	 * @return bool
	 */
	private function stall_chart_unit_is_available( $map, $unit, $dates ) {
		foreach ( (array) $dates as $date_key ) {
			if ( ! empty( $map[ $unit ][ $date_key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark a unit occupied on each date in the range.
	 *
	 * @param array  $map       Occupancy map, passed by reference.
	 * @param string $unit      Unit identifier.
	 * @param array  $dates     Occupied dates.
	 * @param string $order_key Order key.
	 * @return void
	 */
	private function mark_stall_chart_unit_occupied( &$map, $unit, $dates, $order_key ) {
		foreach ( (array) $dates as $date_key ) {
			$map[ $unit ][ $date_key ] = $order_key;
		}
	}

	/**
	 * Build displayed chart date columns.
	 *
	 * @param int   $reservation_id Reservation post ID.
	 * @param array $rows           Chart rows.
	 * @return array
	 */
	private function get_stall_chart_date_columns( $reservation_id, $rows ) {
		$start_date = get_post_meta( $reservation_id, '_en_available_start_date', true );
		$end_date   = get_post_meta( $reservation_id, '_en_available_end_date', true );
		$keys       = array();

		if ( $start_date && $end_date ) {
			$keys = $this->get_stall_chart_occupied_dates( $start_date, gmdate( 'Y-m-d', strtotime( $end_date . ' +1 day' ) ) );
		}

		if ( empty( $keys ) ) {
			foreach ( (array) $rows as $row ) {
				$keys = array_merge( $keys, array_keys( isset( $row['daily_counts'] ) ? $row['daily_counts'] : array() ) );
			}

			$keys = array_values( array_unique( $keys ) );
			sort( $keys );
		}

		$columns = array();

		foreach ( $keys as $key ) {
			$columns[ $key ] = wp_date( 'M j', strtotime( $key ) );
		}

		return $columns;
	}

	/**
	 * Build occupied nightly dates from arrival/departure.
	 *
	 * @param string $arrival_date Arrival date.
	 * @param string $departure_date Departure date.
	 * @return array
	 */
	private function get_stall_chart_occupied_dates( $arrival_date, $departure_date ) {
		$arrival_timestamp   = $arrival_date ? strtotime( $arrival_date ) : 0;
		$departure_timestamp = $departure_date ? strtotime( $departure_date ) : 0;
		$dates               = array();

		if ( ! $arrival_timestamp ) {
			return $dates;
		}

		if ( ! $departure_timestamp || $departure_timestamp <= $arrival_timestamp ) {
			return array( gmdate( 'Y-m-d', $arrival_timestamp ) );
		}

		for ( $current = $arrival_timestamp; $current < $departure_timestamp; $current = strtotime( '+1 day', $current ) ) {
			$dates[] = gmdate( 'Y-m-d', $current );
		}

		return $dates;
	}

	/**
	 * Parse a comma-separated unit string into normalized values.
	 *
	 * @param string $value Raw units string.
	 * @return array
	 */
	private function parse_assigned_units_string( $value ) {
		$units = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$units = array_map( 'sanitize_text_field', $units );

		return array_values( array_unique( $units ) );
	}

	/**
	 * Parse an order's per-night stall override map from its `Stall Night Map`
	 * note line. Format: `DATE=unit,unit;DATE=unit`. Returns only the dates that
	 * carry an explicit override (others fall back to the whole-stay assignment).
	 *
	 * @param array $order Order payload.
	 * @return array<string, array<int, string>> date (Y-m-d) => list of unit labels.
	 */
	private function parse_stall_night_overrides( $order, $component = 'stall', $note_label = 'Stall Night Map' ) {
		$raw = $this->get_order_component_note_value( $order, $component, $note_label );
		$map = array();

		if ( '' === (string) $raw ) {
			return $map;
		}

		foreach ( explode( ';', (string) $raw ) as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair || false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $date, $units ) = explode( '=', $pair, 2 );
			$date  = trim( $date );
			$list  = $this->parse_assigned_units_string( $units );
			if ( '' !== $date ) {
				$map[ $date ] = $list;
			}
		}

		return $map;
	}

	/**
	 * Serialize a full per-night assignment to the `Stall Night Map` note value.
	 * When every date carries the same unit set the stay is uniform — the flat
	 * `Assigned Stall Units` line already covers it, so '' is returned (and the
	 * caller removes the note). Otherwise EVERY date is written explicitly so the
	 * map is self-contained.
	 *
	 * @param array<string, array<int, string>> $night date (Y-m-d) => units.
	 * @return string Serialized map, or '' when the stay is uniform.
	 */
	private function serialize_stall_night_map( array $night ) {
		$signatures = array();
		foreach ( $night as $units ) {
			$u = array_values( array_unique( array_map( 'strval', (array) $units ) ) );
			sort( $u );
			$signatures[] = implode( ',', $u );
		}

		if ( count( array_unique( $signatures ) ) <= 1 ) {
			return ''; // uniform whole-stay assignment — no per-night map needed.
		}

		ksort( $night );
		$parts = array();
		foreach ( $night as $date => $units ) {
			$u       = array_values( array_unique( array_map( 'strval', (array) $units ) ) );
			$parts[] = $date . '=' . implode( ',', $u );
		}

		return implode( ';', $parts );
	}

	/**
	 * Resolve an order's full per-night stall assignment: for every occupied date,
	 * the units it should occupy — the per-night override when present, else the
	 * whole-stay ($flat_units) assignment.
	 *
	 * @param array              $order       Order payload.
	 * @param array<int, string> $stall_dates Occupied dates (Y-m-d).
	 * @param array<int, string> $flat_units  Whole-stay assigned units.
	 * @return array<string, array<int, string>> date => list of unit labels.
	 */
	private function get_order_night_assignments( $order, $stall_dates, $flat_units, $component = 'stall', $note_label = 'Stall Night Map' ) {
		$overrides = $this->parse_stall_night_overrides( $order, $component, $note_label );
		$result    = array();

		foreach ( (array) $stall_dates as $date ) {
			$result[ $date ] = isset( $overrides[ $date ] )
				? $overrides[ $date ]
				: array_values( array_map( 'strval', (array) $flat_units ) );
		}

		return $result;
	}

	/**
	 * Read a note value from the first matching order component.
	 *
	 * @param array  $order Order payload.
	 * @param string $table Component table slug.
	 * @param string $label Note label.
	 * @return string
	 */
	private function get_order_component_note_value( $order, $table, $label ) {
		foreach ( (array) $order['components'] as $component ) {
			if ( $table !== $component['table'] ) {
				continue;
			}

			$value = $this->get_order_note_value( isset( $component['notes'] ) ? $component['notes'] : '', $label );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	// C5.5: removed dead render_import_tec_events_page() — verified zero live callers (never wired to a menu_page; was a 2-line redirect stub).

	/**
	 * Handle TEC event imports.
	 *
	 * @return void
	 */
	public function handle_import_tec_events() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import events.', 'equine-event-manager' ) );
		}

		check_admin_referer( EEM_Events::IMPORT_TEC_ACTION, 'equine_event_manager_import_tec_events_nonce' );

		$event_ids = isset( $_POST['tec_event_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tec_event_ids'] ) ) : array();
		$event_ids = array_values( array_filter( $event_ids ) );
		$counts    = array(
			'imported' => 0,
			'existing' => 0,
			'error'    => 0,
		);

		foreach ( $event_ids as $event_id ) {
			$result = EEM_Events::import_tec_event( $event_id );

			if ( isset( $counts[ $result['status'] ] ) ) {
				++$counts[ $result['status'] ];
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'equine-event-manager-settings',
					'tab'      => 'integrations',
					'imported' => $counts['imported'],
					'existing' => $counts['existing'],
					'error'    => $counts['error'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render import result notice when present.
	 *
	 * @return void
	 */
	private function render_import_tec_events_notice() {
		$imported = isset( $_GET['imported'] ) ? absint( wp_unslash( $_GET['imported'] ) ) : 0;
		$existing = isset( $_GET['existing'] ) ? absint( wp_unslash( $_GET['existing'] ) ) : 0;
		$error    = isset( $_GET['error'] ) ? absint( wp_unslash( $_GET['error'] ) ) : 0;

		if ( ! $imported && ! $existing && ! $error ) {
			return;
		}

		$parts = array();

		if ( $imported ) {
			$parts[] = sprintf(
				/* translators: %d: number of imported events. */
				_n( '%d event imported.', '%d events imported.', $imported, 'equine-event-manager' ),
				$imported
			);
		}

		if ( $existing ) {
			$parts[] = sprintf(
				/* translators: %d: number of already imported events. */
				_n( '%d event was already imported.', '%d events were already imported.', $existing, 'equine-event-manager' ),
				$existing
			);
		}

		if ( $error ) {
			$parts[] = sprintf(
				/* translators: %d: number of failed imports. */
				_n( '%d event failed to import.', '%d events failed to import.', $error, 'equine-event-manager' ),
				$error
			);
		}
		?>
		<div class="notice notice-info is-dismissible" role="status"><p><?php echo esc_html( implode( ' ', $parts ) ); ?></p></div>
		<?php
	}

	/**
	 * Build the reservation overview dataset.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array
	 */
	private function get_reservation_overview_data( $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		$orders         = array_values(
			array_filter(
				$this->orders_repository->get_orders(),
				static function ( $order ) use ( $reservation_id ) {
					return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === $reservation_id;
				}
			)
		);
		$data                     = $this->get_reservation_meta_values( $reservation_id );
		$general_addon_sales      = array();
		$rv_addon_sales           = array();
		$rv_lot_sales             = array();
		$required_shavings_sold   = 0;
		$additional_shavings_sold = 0;
		$stall_sold               = 0;
		$rv_sold                  = 0;
		$group_rider_total        = 0;
		$group_reservation_count  = 0;
		$revenue_total            = 0.0;

		foreach ( $orders as $order ) {
			$notes                    = isset( $order['notes'] ) ? $order['notes'] : '';
			$stall_sold               += absint( isset( $order['stall_quantity'] ) ? $order['stall_quantity'] : 0 );
			$rv_sold                  += absint( isset( $order['rv_quantity'] ) ? $order['rv_quantity'] : 0 );
			$required_shavings_sold   += absint( isset( $order['required_shavings_qty'] ) ? $order['required_shavings_qty'] : 0 );
			$additional_shavings_sold += absint( isset( $order['additional_shavings_qty'] ) ? $order['additional_shavings_qty'] : 0 );
			$revenue_total            += (float) ( isset( $order['total'] ) ? $order['total'] : 0 );
			$group_rider_count         = self::parse_group_rider_count_from_notes( $notes );

			if ( $group_rider_count > 0 ) {
				$group_rider_total += $group_rider_count;
				$group_reservation_count++;
			}

			foreach ( $this->parse_general_addon_quantities_from_notes( $notes ) as $addon_name => $quantity ) {
				if ( ! isset( $general_addon_sales[ $addon_name ] ) ) {
					$general_addon_sales[ $addon_name ] = 0;
				}

				$general_addon_sales[ $addon_name ] += $quantity;
			}

			$rv_addon_labels = $this->get_order_rv_addon_labels( $order );

			foreach ( $rv_addon_labels as $addon_label ) {
				if ( ! isset( $rv_addon_sales[ $addon_label ] ) ) {
					$rv_addon_sales[ $addon_label ] = 0;
				}

				$rv_addon_sales[ $addon_label ] += absint( isset( $order['rv_quantity'] ) ? $order['rv_quantity'] : 0 );
			}

			$rv_lot_name = $this->parse_rv_lot_name_from_notes( $notes );

			if ( '' !== $rv_lot_name ) {
				if ( ! isset( $rv_lot_sales[ $rv_lot_name ] ) ) {
					$rv_lot_sales[ $rv_lot_name ] = 0;
				}

				$rv_lot_sales[ $rv_lot_name ] += absint( isset( $order['rv_quantity'] ) ? $order['rv_quantity'] : 0 );
			}
		}

		$stall_inventory_total = $this->normalize_inventory_limit( $data['stall_inventory'] );
		$rv_inventory_total    = $this->normalize_inventory_limit( $data['rv_inventory'] );
		$stall_remaining       = null === $stall_inventory_total ? null : max( 0, $stall_inventory_total - $stall_sold );
		$rv_remaining          = null === $rv_inventory_total ? null : max( 0, $rv_inventory_total - $rv_sold );
		$inventory_rows        = array();

		if ( ! empty( $data['stalls_enabled'] ) || $stall_sold > 0 ) {
			$inventory_rows[] = $this->build_inventory_overview_row( __( 'Stall Reservations', 'equine-event-manager' ), __( 'Core Inventory', 'equine-event-manager' ), $stall_inventory_total, $stall_sold );
		}

		if ( ! empty( $data['rv_enabled'] ) || $rv_sold > 0 ) {
			$inventory_rows[] = $this->build_inventory_overview_row( __( 'RV Reservations', 'equine-event-manager' ), __( 'Core Inventory', 'equine-event-manager' ), $rv_inventory_total, $rv_sold );
		}

		if ( ! empty( $data['group_reservations_enabled'] ) || $group_reservation_count > 0 ) {
			$inventory_rows[] = array(
				'label'         => __( 'Group Reservations', 'equine-event-manager' ),
				'category'      => __( 'Order Activity', 'equine-event-manager' ),
				'status_slug'   => 'available',
				'status_label'  => __( 'Available', 'equine-event-manager' ),
				'display'       => 'group_reservations',
				'summary_label' => __( 'Submitted Groups', 'equine-event-manager' ),
				'summary_value' => number_format_i18n( $group_reservation_count ),
				'summary_meta'  => sprintf(
					_n( '%d order includes a group reservation', '%d orders include a group reservation', $group_reservation_count, 'equine-event-manager' ),
					$group_reservation_count
				),
			);
		}

		$product_rows = array();

		if ( $required_shavings_sold > 0 ) {
			$product_rows[] = array(
				'label'    => __( 'Required Shavings', 'equine-event-manager' ),
				'category' => __( 'Stall Product', 'equine-event-manager' ),
				'sold'     => $required_shavings_sold,
			);
		}

		if ( $additional_shavings_sold > 0 ) {
			$product_rows[] = array(
				'label'    => __( 'Additional Shavings', 'equine-event-manager' ),
				'category' => __( 'Legacy Product', 'equine-event-manager' ),
				'sold'     => $additional_shavings_sold,
			);
		}

		foreach ( $data['general_addons'] as $configured_addon ) {
			if ( empty( $configured_addon['name'] ) ) {
				continue;
			}

			$addon_name = sanitize_text_field( $configured_addon['name'] );

			if ( ! isset( $general_addon_sales[ $addon_name ] ) ) {
				$general_addon_sales[ $addon_name ] = 0;
			}
		}

		foreach ( $general_addon_sales as $addon_name => $quantity ) {
			$product_rows[] = array(
				'label'    => $addon_name,
				'category' => __( 'General Add-On', 'equine-event-manager' ),
				'sold'     => $quantity,
			);
		}

		foreach ( $data['rv_addons'] as $configured_addon ) {
			if ( empty( $configured_addon['name'] ) ) {
				continue;
			}

			$addon_label = sanitize_text_field( $configured_addon['name'] );

			if ( ! isset( $rv_addon_sales[ $addon_label ] ) ) {
				$rv_addon_sales[ $addon_label ] = 0;
			}
		}

		foreach ( $rv_addon_sales as $addon_label => $quantity ) {
			$product_rows[] = array(
				'label'    => $addon_label,
				'category' => __( 'RV Add-On', 'equine-event-manager' ),
				'sold'     => $quantity,
			);
		}

		foreach ( $data['rv_lots'] as $lot ) {
			if ( empty( $lot['name'] ) ) {
				continue;
			}

			$lot_name = sanitize_text_field( $lot['name'] );

			if ( ! isset( $rv_lot_sales[ $lot_name ] ) ) {
				$rv_lot_sales[ $lot_name ] = 0;
			}
		}

		foreach ( $rv_lot_sales as $lot_name => $quantity ) {
			$product_rows[] = array(
				'label'    => $lot_name,
				'category' => __( 'RV Lot Selection', 'equine-event-manager' ),
				'sold'     => $quantity,
			);
		}

		usort(
			$product_rows,
			static function ( $left, $right ) {
				$category_order = array(
					'Stall Product'     => 10,
					'Legacy Product'    => 20,
					'RV Lot Selection'  => 30,
					'RV Add-On'         => 40,
					'General Add-On'    => 50,
				);

				$left_category_order  = isset( $category_order[ $left['category'] ] ) ? $category_order[ $left['category'] ] : 999;
				$right_category_order = isset( $category_order[ $right['category'] ] ) ? $category_order[ $right['category'] ] : 999;

				if ( $left_category_order !== $right_category_order ) {
					return $left_category_order - $right_category_order;
				}

				return strcasecmp( $left['label'], $right['label'] );
			}
		);

		return array(
			'orders'                => $orders,
			'order_count'           => count( $orders ),
			'revenue_total'         => $revenue_total,
			'stall_sold'            => $stall_sold,
			'rv_sold'               => $rv_sold,
			'group_rider_total'     => $group_rider_total,
			'group_reservation_count' => $group_reservation_count,
			'stall_remaining_label' => $this->format_remaining_inventory_label( $stall_remaining ),
			'rv_remaining_label'    => $this->format_remaining_inventory_label( $rv_remaining ),
			'stall_inventory_label' => $this->format_inventory_limit_label( $stall_inventory_total ),
			'rv_inventory_label'    => $this->format_inventory_limit_label( $rv_inventory_total ),
			'event_label'           => $this->get_reservation_event_label( $reservation_id, $data ),
			'event_dates_label'     => $this->get_reservation_event_dates_label( $data ),
			'event_source_label'    => $this->get_reservation_event_source_label( $data ),
			'type_label'            => $this->get_reservation_type_label( $data ),
			'inventory_rows'        => $inventory_rows,
			'product_rows'          => $product_rows,
		);
	}

	/**
	 * Get reservation meta values needed for the overview screen.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array
	 */
	private function get_reservation_meta_values( $reservation_id ) {
		$data = array(
			'event_source'                => $this->get_effective_reservation_event_source( $reservation_id ),
			'event_id'                    => absint( get_post_meta( $reservation_id, '_en_event_id', true ) ),
			'external_event_name'         => (string) get_post_meta( $reservation_id, '_en_external_event_name', true ),
			'available_start_date'        => (string) get_post_meta( $reservation_id, '_en_available_start_date', true ),
			'available_end_date'          => (string) get_post_meta( $reservation_id, '_en_available_end_date', true ),
			'stalls_enabled'              => EEM_Reservations_CPT::section_enabled( $reservation_id, 'stalls_enabled' ) ? 1 : 0,
			'rv_enabled'                  => EEM_Reservations_CPT::section_enabled( $reservation_id, 'rv_enabled' ) ? 1 : 0,
			'general_addons_enabled'      => EEM_Reservations_CPT::section_enabled( $reservation_id, 'general_addons_enabled' ) ? 1 : 0,
			'group_reservations_enabled'  => EEM_Reservations_CPT::section_enabled( $reservation_id, 'group_reservations_enabled' ) ? 1 : 0,
			'group_rider_grounds_fee_enabled' => ! empty( get_post_meta( $reservation_id, '_en_group_rider_grounds_fee_enabled', true ) ) ? 1 : 0,
			'group_rider_grounds_fee_amount'  => (string) get_post_meta( $reservation_id, '_en_group_rider_grounds_fee_amount', true ),
			'group_rider_deposit_enabled'     => ! empty( get_post_meta( $reservation_id, '_en_group_rider_deposit_enabled', true ) ) ? 1 : 0,
			'group_rider_deposit_amount'      => (string) get_post_meta( $reservation_id, '_en_group_rider_deposit_amount', true ),
			'general_addons'              => get_post_meta( $reservation_id, '_en_general_addons', true ),
			'rv_addons'                   => get_post_meta( $reservation_id, '_en_rv_addons', true ),
			'rv_lots'                     => get_post_meta( $reservation_id, '_en_rv_lots', true ),
			'stall_inventory'             => get_post_meta( $reservation_id, '_en_stall_inventory', true ),
			'rv_inventory'                => get_post_meta( $reservation_id, '_en_rv_inventory', true ),
		);

		if ( ! is_array( $data['general_addons'] ) ) {
			$data['general_addons'] = array();
		}

		if ( ! is_array( $data['rv_lots'] ) ) {
			$data['rv_lots'] = array();
		}

		if ( ! is_array( $data['rv_addons'] ) ) {
			$data['rv_addons'] = array();
		}

		return $data;
	}

	/**
	 * Build one inventory overview table row.
	 *
	 * @param string   $label Row label.
	 * @param string   $category Row category.
	 * @param int|null $inventory_total Inventory limit.
	 * @param int      $sold Sold quantity.
	 * @return array
	 */
	private function build_inventory_overview_row( $label, $category, $inventory_total, $sold ) {
		$remaining = null === $inventory_total ? null : max( 0, $inventory_total - $sold );

		if ( null === $inventory_total ) {
			$status_slug  = 'unlimited';
			$status_label = __( 'Unlimited', 'equine-event-manager' );
		} elseif ( $remaining <= 0 ) {
			$status_slug  = 'sold-out';
			$status_label = __( 'Sold Out', 'equine-event-manager' );
		} elseif ( $remaining <= 5 ) {
			$status_slug  = 'low';
			$status_label = __( 'Low', 'equine-event-manager' );
		} else {
			$status_slug  = 'available';
			$status_label = __( 'Available', 'equine-event-manager' );
		}

		return array(
			'label'           => $label,
			'category'        => $category,
			'inventory_label' => $this->format_inventory_limit_label( $inventory_total ),
			'sold'            => absint( $sold ),
			'remaining_label' => $this->format_remaining_inventory_label( $remaining ),
			'status_slug'     => $status_slug,
			'status_label'    => $status_label,
		);
	}

	/**
	 * Parse general add-on quantities from saved notes.
	 *
	 * @param string $notes Order notes.
	 * @return array<string, int>
	 */
	private function parse_general_addon_quantities_from_notes( $notes ) {
		$results = array();

		if ( preg_match_all( '/(?:^|\n)Add-On:\s*(.+?)\s*\|\s*Qty:\s*(\d+)/mi', (string) $notes, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$addon_name = sanitize_text_field( trim( $match[1] ) );
				$quantity   = absint( $match[2] );

				if ( '' === $addon_name || $quantity <= 0 ) {
					continue;
				}

				if ( ! isset( $results[ $addon_name ] ) ) {
					$results[ $addon_name ] = 0;
				}

				$results[ $addon_name ] += $quantity;
			}
		}

		return $results;
	}

	/**
	 * Parse saved general add-on rows from notes.
	 *
	 * @param string $notes Order notes.
	 * @return array
	 */
	private function parse_general_addon_breakdown_from_notes( $notes ) {
		$results = array();

		if ( preg_match_all( '/(?:^|\n)Add-On:\s*(.+?)\s*\|\s*Qty:\s*(\d+)(?:\s*\|\s*Per:\s*(.+?))?\s*\|\s*Subtotal:\s*\$?\s*([0-9,]+(?:\.\d{1,2})?)/mi', (string) $notes, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$addon_name = sanitize_text_field( trim( $match[1] ) );
				$quantity   = absint( $match[2] );
				$per_label  = ! empty( $match[3] ) ? sanitize_text_field( trim( $match[3] ) ) : '';
				$subtotal   = (float) str_replace( ',', '', trim( $match[4] ) );

				if ( '' === $addon_name || $quantity <= 0 ) {
					continue;
				}

				if ( ! isset( $results[ $addon_name ] ) ) {
					$results[ $addon_name ] = array(
						'label'     => $addon_name,
						'quantity'  => 0,
						'per_label' => $per_label,
						'subtotal'  => 0.0,
					);
				}

				$results[ $addon_name ]['quantity'] += $quantity;
				if ( '' === $results[ $addon_name ]['per_label'] && '' !== $per_label ) {
					$results[ $addon_name ]['per_label'] = $per_label;
				}
				$results[ $addon_name ]['subtotal'] += $subtotal;
			}
		}

		return array_values( $results );
	}

	/**
	 * Parse group charge rows from notes.
	 *
	 * @param string $notes Order notes.
	 * @return array<int, array{label:string, quantity:int, rate:float, subtotal:float}>
	 */
	private function parse_group_charge_breakdown_from_notes( $notes ) {
		$results = array();

		if ( preg_match_all( '/(?:^|\n)Group Charge:\s*(.+?)\s*\|\s*Qty:\s*(\d+)\s*\|\s*Rate:\s*\$?\s*([0-9,]+(?:\.\d{1,2})?)\s*\|\s*Subtotal:\s*\$?\s*([0-9,]+(?:\.\d{1,2})?)/mi', (string) $notes, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$label    = sanitize_text_field( trim( $match[1] ) );
				$quantity = absint( $match[2] );
				$rate     = (float) str_replace( ',', '', trim( $match[3] ) );
				$subtotal = (float) str_replace( ',', '', trim( $match[4] ) );

				if ( '' === $label || $quantity <= 0 ) {
					continue;
				}

				$results[] = array(
					'label'    => $label,
					'quantity' => $quantity,
					'rate'     => $rate,
					'subtotal' => $subtotal,
				);
			}
		}

		return $results;
	}

	/**
	 * Parse an RV lot name from notes.
	 *
	 * @param string $notes Order notes.
	 * @return string
	 */
	private function parse_rv_lot_name_from_notes( $notes ) {
		if ( preg_match( '/(?:^|\n)RV Lot:\s*(.+)$/mi', (string) $notes, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Parse saved group rider count from notes.
	 *
	 * @param string $notes Order notes.
	 * @return int
	 */
	public static function parse_group_rider_count_from_notes( $notes ) {
		if ( preg_match( '/(?:^|\n)Group Riders Count:\s*(\d+)/i', (string) $notes, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Parse saved group rider names from notes.
	 *
	 * @param string $notes Order notes.
	 * @return array
	 */
	public static function parse_group_rider_names_from_notes( $notes ) {
		if ( empty( $notes ) || ! preg_match( '/(?:^|\n)Group Riders:\s*(.+)$/mi', (string) $notes, $matches ) ) {
			return array();
		}

		$names = array();

		foreach ( preg_split( '/\s*\|\s*/', trim( $matches[1] ) ) as $name ) {
			$name = sanitize_text_field( trim( (string) $name ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Normalize an inventory limit to an integer or null.
	 *
	 * @param mixed $value Raw inventory value.
	 * @return int|null
	 */
	private function normalize_inventory_limit( $value ) {
		if ( '' === $value || null === $value ) {
			return null;
		}

		return max( 0, absint( $value ) );
	}

	/**
	 * Format an inventory limit label.
	 *
	 * @param int|null $inventory_total Inventory count.
	 * @return string
	 */
	private function format_inventory_limit_label( $inventory_total ) {
		if ( null === $inventory_total ) {
			return __( 'Unlimited', 'equine-event-manager' );
		}

		return number_format_i18n( absint( $inventory_total ) );
	}

	/**
	 * Format a remaining inventory label.
	 *
	 * @param int|null $remaining Remaining count.
	 * @return string
	 */
	private function format_remaining_inventory_label( $remaining ) {
		if ( null === $remaining ) {
			return __( 'Not tracked', 'equine-event-manager' );
		}

		return number_format_i18n( absint( $remaining ) );
	}

	/**
	 * Get a display event label for a reservation.
	 *
	 * @param int   $reservation_id Reservation post ID.
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_event_label( $reservation_id, $data ) {
		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_title = get_the_title( absint( $data['event_id'] ) );

			if ( $event_title ) {
				return $event_title;
			}
		}

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_title = get_the_title( absint( $data['event_id'] ) );

			if ( $event_title ) {
				return $event_title;
			}
		}

		$reservation_title = get_the_title( $reservation_id );

		return $reservation_title ? $reservation_title : __( 'Event Overview', 'equine-event-manager' );
	}

	/**
	 * Get display event dates for a reservation.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_event_dates_label( $data ) {
		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$start = get_post_meta( absint( $data['event_id'] ), '_EventStartDate', true );
			$end   = get_post_meta( absint( $data['event_id'] ), '_EventEndDate', true );

			if ( $start ) {
				return $this->format_admin_date_range_label( $start, $end ? $end : $start );
			}
		}

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_dates = EEM_Events::get_native_event_date_values( absint( $data['event_id'] ) );

			if ( ! empty( $event_dates['start_date'] ) ) {
				return $this->format_admin_date_range_label( $event_dates['start_date'], ! empty( $event_dates['end_date'] ) ? $event_dates['end_date'] : $event_dates['start_date'] );
			}
		}

		if ( ! empty( $data['available_start_date'] ) ) {
			return $this->format_admin_date_range_label( $data['available_start_date'], ! empty( $data['available_end_date'] ) ? $data['available_end_date'] : $data['available_start_date'] );
		}

		return __( 'Dates unavailable', 'equine-event-manager' );
	}

	/**
	 * Get a display event source label.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_event_source_label( $data ) {
		return EEM_Events::get_event_source_label( $data['event_source'] );
	}

	/**
	 * Resolve the effective event source for a reservation in admin views.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string
	 */
	private function get_effective_reservation_event_source( $reservation_id ) {
		$use_global_event_source = ! empty( get_post_meta( $reservation_id, '_en_use_global_event_source', true ) );
		$event_source            = sanitize_key( (string) get_post_meta( $reservation_id, '_en_event_source', true ) );
		$allowed_sources         = array( 'native', 'tec', 'feed', 'external' );

		if ( $use_global_event_source || ! in_array( $event_source, $allowed_sources, true ) ) {
			$event_source = EEM_Events::get_default_event_source();
		}

		return in_array( $event_source, $allowed_sources, true ) ? $event_source : 'external';
	}

	/**
	 * Get a display type label.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_type_label( $data ) {
		$types = array();

		if ( ! empty( $data['stalls_enabled'] ) ) {
			$types[] = __( 'Stall', 'equine-event-manager' );
		}

		if ( ! empty( $data['rv_enabled'] ) ) {
			$types[] = __( 'RV', 'equine-event-manager' );
		}

		if ( ! empty( $data['general_addons_enabled'] ) && ! empty( $data['general_addons'] ) ) {
			$types[] = __( 'Add-On', 'equine-event-manager' );
		}

		return ! empty( $types ) ? implode( ', ', $types ) : __( 'None', 'equine-event-manager' );
	}

	/**
	 * Format an admin-facing date range label.
	 *
	 * @param string $start Start date/datetime.
	 * @param string $end End date/datetime.
	 * @return string
	 */
	private function format_admin_date_range_label( $start, $end ) {
		$start_timestamp = strtotime( (string) $start );
		$end_timestamp   = strtotime( (string) $end );

		if ( ! $start_timestamp ) {
			return __( 'Dates unavailable', 'equine-event-manager' );
		}

		if ( ! $end_timestamp || gmdate( 'Y-m-d', $start_timestamp ) === gmdate( 'Y-m-d', $end_timestamp ) ) {
			return wp_date( 'F j, Y', $start_timestamp );
		}

		return sprintf(
			/* translators: 1: start date, 2: end date. */
			__( '%1$s - %2$s', 'equine-event-manager' ),
			wp_date( 'F j, Y', $start_timestamp ),
			wp_date( 'F j, Y', $end_timestamp )
		);
	}

	/**
	 * Render a single order details page.
	 */
	public function render_order_details_page() {
		$this->guard_admin_page();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$order     = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			?>
			<div class="wrap eem-shell-wrap eem-shell-wrap--header">
				<?php $this->render_brand_banner( __( 'Order Details', 'equine-event-manager' ) ); ?>
				<div class="eem-shell-content">
					<div class="postbox eem-card">
						<p><?php esc_html_e( 'Order not found.', 'equine-event-manager' ); ?></p>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		?>
		<div class="wrap eem-shell-wrap eem-shell-wrap--header">
			<?php $this->render_admin_notice(); ?>
			<?php
			$invoice_token      = $this->get_invoice_token_for_order( $order );
			$invoice_payment_url = $this->get_invoice_payment_url( $invoice_token );
			$invoice_sent_at    = $this->get_order_note_value( isset( $order['notes'] ) ? $order['notes'] : '', 'Invoice Sent At' );
			$reservation_id     = ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
			$reservation_title  = $order['reservation_title'] ? $order['reservation_title'] : __( 'Unknown', 'equine-event-manager' );
			$reservation_edit_url = $reservation_id ? get_edit_post_link( $reservation_id, '' ) : '';
			$payment_state_badges = $this->render_order_type_badges( $order['type'] );
			$payment_status_badge = $this->render_order_status_badge( $order['status_label'], isset( $order['status_slug'] ) ? $order['status_slug'] : '' );
			$assigned_stall_units = $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' );
			$assigned_rv_lots     = $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' );
			if ( '' === $assigned_rv_lots ) {
				$assigned_rv_lots = $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' );
			}
			$assigned_stall_unit_list = $this->parse_assigned_units_string( $assigned_stall_units );
			$assigned_rv_lot_list     = $this->parse_assigned_units_string( $assigned_rv_lots );
			$stall_nights_label       = absint( $order['stall_quantity'] ) > 0 ? $this->get_stay_nights_label( $order['stall_arrival_date'], $order['stall_departure_date'] ) : '';
			$rv_nights_label          = absint( $order['rv_quantity'] ) > 0 ? $this->get_stay_nights_label( $order['rv_arrival_date'], $order['rv_departure_date'] ) : '';
			$total_shavings_needed    = absint( $order['required_shavings_qty'] ) + absint( $order['additional_shavings_qty'] );
			$this->render_brand_banner(
				sprintf( __( 'Order #%s', 'equine-event-manager' ), $order['order_number'] ),
				$order['event_name']
			);
			?>
			<h1 class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Order #%s', 'equine-event-manager' ), $order['order_number'] ) ); ?></h1>

			<?php
			$group_rider_count       = self::parse_group_rider_count_from_notes( $order['notes'] );
			$group_rider_names       = self::parse_group_rider_names_from_notes( $order['notes'] );
			$general_addons          = $this->parse_general_addon_breakdown_from_notes( $order['notes'] );
			$stall_chart_enabled     = $reservation_id ? ( EEM_Reservations_CPT::section_enabled( $reservation_id, 'stalls_enabled' ) || EEM_Reservations_CPT::section_enabled( $reservation_id, 'rv_enabled' ) ) : false;
			$stall_chart_config      = $reservation_id ? $this->get_stall_chart_config( $reservation_id ) : array();
			$stall_assignment_ready  = $stall_chart_enabled || ! empty( $stall_chart_config['stall_units'] );
			$rv_assignment_ready     = $stall_chart_enabled || ! empty( $stall_chart_config['rv_lot_names'] );
			$refund_details          = $this->get_refund_details_from_order( $order );
			$special_requests        = $this->get_special_requests_from_order_notes( $order['notes'] );
			$billing_address         = $this->get_billing_details_from_order_notes( $order['notes'] );
			$has_agreement           = $this->has_venue_agreement_from_order_notes( $order['notes'] );
			$transaction_summary     = $this->get_transaction_id_summary( $order );
			$customer_email_value    = $this->get_email_definition_value( $order['email'] );
			$customer_phone_value    = $this->get_phone_definition_value( $order['phone'] );
			$customer_email_html     = is_array( $customer_email_value ) && ! empty( $customer_email_value['html'] ) ? $customer_email_value['html'] : esc_html( (string) $customer_email_value );
			$customer_phone_html     = is_array( $customer_phone_value ) && ! empty( $customer_phone_value['html'] ) ? $customer_phone_value['html'] : esc_html( (string) $customer_phone_value );

			$stall_detail_rows       = array();
			$stall_assignment_html   = '';
			$rv_detail_rows          = array();
			$rv_assignment_html      = '';

			if ( absint( $order['stall_quantity'] ) > 0 ) {
				$stall_detail_rows[] = array(
					__( 'Stay Type', 'equine-event-manager' ) => $this->format_stay_type_label( $order['stall_stay_type'] ),
					__( 'Nights', 'equine-event-manager' )    => $stall_nights_label,
				);
				$stall_detail_rows[] = array(
					__( 'Arrival Date', 'equine-event-manager' )   => $this->format_reservation_date_label( $order['stall_arrival_date'] ),
					__( 'Departure Date', 'equine-event-manager' ) => $this->format_reservation_date_label( $order['stall_departure_date'] ),
				);
				$stall_detail_rows[] = array(
					__( 'Stall Quantity', 'equine-event-manager' )             => (string) absint( $order['stall_quantity'] ),
					__( 'Required Shavings Quantity', 'equine-event-manager' ) => (string) absint( $order['required_shavings_qty'] ),
				);
				$stall_detail_rows[] = array(
					__( 'Additional Shavings', 'equine-event-manager' ) => (string) absint( $order['additional_shavings_qty'] ),
					__( 'Subtotal', 'equine-event-manager' )            => $this->format_money( (float) $order['stall_subtotal'] ),
				);

				if ( ! empty( $assigned_stall_unit_list ) ) {
					$stall_detail_rows[] = array(
						__( 'Assigned Units', 'equine-event-manager' ) => array(
							'html' => $this->render_assignment_summary_chips( $assigned_stall_unit_list, 'stall' ),
						),
					);
				}

				if ( $stall_assignment_ready ) {
					ob_start();
					?>
					<form class="eem-order-assignment-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'equine_event_manager_update_order_assignments_' . $order['order_key'] ); ?>
						<input type="hidden" name="action" value="equine_event_manager_update_order_assignments" />
						<input type="hidden" name="order_key" value="<?php echo esc_attr( $order['order_key'] ); ?>" />
						<p class="eem-order-assignment-form__intro">
							<strong><?php esc_html_e( 'Assign Stall Units', 'equine-event-manager' ); ?></strong>
						</p>
						<div class="eem-order-assignment-form__controls">
							<?php $this->render_assignment_select_field( 'assigned_stall_units', isset( $stall_chart_config['stall_units'] ) ? $stall_chart_config['stall_units'] : array(), $assigned_stall_unit_list, isset( $stall_chart_config['blocked_stall_units'] ) ? $stall_chart_config['blocked_stall_units'] : array(), absint( $order['stall_quantity'] ) ); ?>
							<div class="eem-order-assignment-form__actions">
								<button type="submit" class="button button-primary eem-order-assignment-form__submit"><?php esc_html_e( 'Save Stall Assignment', 'equine-event-manager' ); ?></button>
							</div>
						</div>
						<p class="eem-order-assignment-form__help"><?php esc_html_e( 'Select the stall units assigned to this order from the stall chart.', 'equine-event-manager' ); ?></p>
					</form>
					<?php
					$stall_assignment_html = ob_get_clean();
				}
			}

			if ( absint( $order['rv_quantity'] ) > 0 ) {
				$rv_detail_rows[] = array(
					__( 'Stay Type', 'equine-event-manager' )      => $this->format_stay_type_label( $order['rv_stay_type'] ),
					__( 'Nights', 'equine-event-manager' )         => $rv_nights_label,
					__( 'RV Quantity', 'equine-event-manager' )    => (string) absint( $order['rv_quantity'] ),
				);
				$rv_detail_rows[] = array(
					__( 'Arrival Date', 'equine-event-manager' )   => $this->format_reservation_date_label( $order['rv_arrival_date'] ),
					__( 'Departure Date', 'equine-event-manager' ) => $this->format_reservation_date_label( $order['rv_departure_date'] ),
				);

				if ( ! empty( $order['rv_type'] ) ) {
					$rv_detail_rows[] = array(
						__( 'RV Add-Ons', 'equine-event-manager' ) => $this->get_rv_addon_definition_value( $order['rv_type'] ),
						__( 'Subtotal', 'equine-event-manager' )   => $this->format_money( (float) $order['rv_subtotal'] ),
					);
				}

				if ( ! empty( $assigned_rv_lot_list ) ) {
					$rv_detail_rows[] = array(
						__( 'Assigned Lots', 'equine-event-manager' ) => array(
							'html' => $this->render_assignment_summary_chips( $assigned_rv_lot_list, 'rv' ),
						),
					);
				}

				if ( $rv_assignment_ready ) {
					ob_start();
					?>
					<form class="eem-order-assignment-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'equine_event_manager_update_order_assignments_' . $order['order_key'] ); ?>
						<input type="hidden" name="action" value="equine_event_manager_update_order_assignments" />
						<input type="hidden" name="order_key" value="<?php echo esc_attr( $order['order_key'] ); ?>" />
						<p class="eem-order-assignment-form__intro">
							<strong><?php esc_html_e( 'Assign RV Lots', 'equine-event-manager' ); ?></strong>
						</p>
						<div class="eem-order-assignment-form__controls">
							<?php $this->render_rv_lot_assignment_select_field( 'assigned_rv_lots', isset( $stall_chart_config['rv_lot_names'] ) ? $stall_chart_config['rv_lot_names'] : array(), $this->parse_assigned_units_string( $assigned_rv_lots ), isset( $stall_chart_config['blocked_rv_lots'] ) ? $stall_chart_config['blocked_rv_lots'] : array() ); ?>
							<div class="eem-order-assignment-form__actions">
								<button type="submit" class="button button-primary eem-order-assignment-form__submit"><?php esc_html_e( 'Save RV Lot Assignment', 'equine-event-manager' ); ?></button>
							</div>
						</div>
						<p class="eem-order-assignment-form__help"><?php esc_html_e( 'Select the RV lots assigned to this order from the stall chart.', 'equine-event-manager' ); ?></p>
					</form>
					<?php
					$rv_assignment_html = ob_get_clean();
				}
			}
			?>

			<div class="eem-shell-content eem-shell-content--app eem-shell-content--order-detail">
				<div class="postbox eem-card eem-order-detail-toolbar-card eem-order-detail-headercard">
					<div class="inside">
						<div class="eem-order-detail-headercard__row">
							<div class="eem-order-detail-headercard__meta">
								<h2 class="eem-order-detail-toolbar-card__title"><?php echo esc_html( sprintf( __( 'Order #%s', 'equine-event-manager' ), $order['order_number'] ) ); ?></h2>
								<div class="eem-order-detail-toolbar-card__badges">
									<?php echo wp_kses_post( $payment_status_badge ); ?>
									<?php if ( ! empty( $payment_state_badges ) ) : ?>
										<?php echo wp_kses_post( $payment_state_badges ); ?>
									<?php endif; ?>
								</div>
								<div class="eem-order-detail-headercard__subline">
									<span><?php echo esc_html( $order['created_at'] ); ?></span>
									<span><?php echo esc_html( $reservation_title ); ?></span>
								</div>
							</div>
							<div class="eem-shell-inline-actions eem-order-detail-toolbar-card__actions">
								<a class="button" href="<?php echo esc_url( $this->get_orders_page_url() ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a>
								<?php if ( $reservation_edit_url ) : ?>
									<a class="button" href="<?php echo esc_url( $reservation_edit_url ); ?>"><?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_export_order_csv&order_key=' . rawurlencode( $order['order_key'] ) ), 'equine_event_manager_export_order_' . $order['order_key'] ) ); ?>"><?php esc_html_e( 'Export CSV', 'equine-event-manager' ); ?></a>
								<?php if ( in_array( $order['status_slug'], array( 'unpaid', 'invoice-sent' ), true ) ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_mark_order_paid&order_key=' . rawurlencode( $order['order_key'] ) . '&method=cash' ), 'equine_event_manager_mark_order_paid_' . $order['order_key'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Mark this order as paid by cash?', 'equine-event-manager' ) ); ?>');"><?php esc_html_e( 'Mark Cash', 'equine-event-manager' ); ?></a>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_mark_order_paid&order_key=' . rawurlencode( $order['order_key'] ) . '&method=check' ), 'equine_event_manager_mark_order_paid_' . $order['order_key'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Mark this order as paid by check?', 'equine-event-manager' ) ); ?>');"><?php esc_html_e( 'Mark Check', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
								<?php if ( ! empty( $order['email'] ) ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_resend_customer_notification&order_key=' . rawurlencode( $order['order_key'] ) ), 'equine_event_manager_resend_customer_notification_' . $order['order_key'] ) ); ?>"><?php esc_html_e( 'Resend Customer Notification', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
								<?php if ( in_array( $order['status_slug'], array( 'unpaid', 'invoice-sent' ), true ) && ! empty( $order['email'] ) ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_send_invoice_email&order_key=' . rawurlencode( $order['order_key'] ) ), 'equine_event_manager_send_invoice_email_' . $order['order_key'] ) ); ?>"><?php esc_html_e( 'Email Payment Link', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
								<?php if ( $order['can_refund'] ) : ?>
									<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'equine-event-manager-order-refund', 'order_key' => $order['order_key'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Refund Order', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
								<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_delete_order&order_key=' . rawurlencode( $order['order_key'] ) ), 'equine_event_manager_delete_order_' . $order['order_key'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this order permanently?', 'equine-event-manager' ) ); ?>');"><?php esc_html_e( 'Delete Order', 'equine-event-manager' ); ?></a>
								<a class="button button-primary" target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=equine_event_manager_print_order&order_key=' . rawurlencode( $order['order_key'] ) ), 'equine_event_manager_print_order_' . $order['order_key'] ) ); ?>"><?php esc_html_e( 'Print as PDF', 'equine-event-manager' ); ?></a>
							</div>
						</div>
					</div>
				</div>

				<div class="eem-order-detail-layout">
					<div class="eem-order-detail-main">
						<?php if ( ! empty( $stall_detail_rows ) ) : ?>
							<div class="postbox eem-card eem-order-product-card eem-order-product-card--stall">
								<div class="inside">
									<div class="eem-order-product-card__header">
										<div>
											<h2><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></h2>
											<p class="eem-order-product-card__subcopy"><?php echo esc_html( $reservation_title ); ?></p>
										</div>
										<div class="eem-order-product-card__meta">
											<?php if ( '' !== $stall_nights_label ) : ?><span class="eem-shell-badge eem-shell-badge--status"><?php echo esc_html( $stall_nights_label ); ?></span><?php endif; ?>
										</div>
									</div>
									<?php $this->render_key_value_rows( $stall_detail_rows ); ?>
									<?php if ( '' !== $stall_assignment_html ) : ?>
										<div class="eem-order-product-card__section">
											<?php echo wp_kses_post( $stall_assignment_html ); ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $rv_detail_rows ) ) : ?>
							<div class="postbox eem-card eem-order-product-card eem-order-product-card--rv">
								<div class="inside">
									<div class="eem-order-product-card__header">
										<div>
											<h2><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></h2>
											<p class="eem-order-product-card__subcopy"><?php echo esc_html( $reservation_title ); ?></p>
										</div>
										<div class="eem-order-product-card__meta">
											<?php if ( '' !== $rv_nights_label ) : ?><span class="eem-shell-badge eem-shell-badge--status"><?php echo esc_html( $rv_nights_label ); ?></span><?php endif; ?>
										</div>
									</div>
									<?php $this->render_key_value_rows( $rv_detail_rows ); ?>
									<?php if ( '' !== $rv_assignment_html ) : ?>
										<div class="eem-order-product-card__section">
											<?php echo wp_kses_post( $rv_assignment_html ); ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $general_addons ) ) : ?>
							<div class="postbox eem-card eem-order-product-card eem-order-product-card--addons">
								<div class="inside">
									<div class="eem-order-product-card__header">
										<div>
											<h2><?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></h2>
											<p class="eem-order-product-card__subcopy"><?php esc_html_e( 'Additional products included in this order.', 'equine-event-manager' ); ?></p>
										</div>
									</div>
									<?php
									foreach ( $general_addons as $addon ) :
										$addon_label    = isset( $addon['label'] ) ? $addon['label'] : '';
										$addon_quantity = isset( $addon['quantity'] ) ? absint( $addon['quantity'] ) : 0;
										$addon_per      = ! empty( $addon['per_label'] ) ? $addon['per_label'] : __( 'qty', 'equine-event-manager' );
										$addon_subtotal = isset( $addon['subtotal'] ) ? (float) $addon['subtotal'] : 0;
										?>
										<div class="eem-order-line-item">
											<div class="eem-order-line-item__title"><?php echo esc_html( $addon_label ); ?></div>
											<div class="eem-order-line-item__meta"><?php echo esc_html( sprintf( __( '%1$d %2$s', 'equine-event-manager' ), $addon_quantity, $addon_per ) ); ?></div>
											<div class="eem-order-line-item__amount"><?php echo esc_html( $this->format_money( $addon_subtotal ) ); ?></div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $group_rider_count > 0 ) : ?>
							<div class="postbox eem-card eem-order-product-card eem-order-product-card--group">
								<div class="inside">
									<div class="eem-order-product-card__header">
										<div>
											<h2><?php esc_html_e( 'Group Reservation', 'equine-event-manager' ); ?></h2>
											<p class="eem-order-product-card__subcopy"><?php esc_html_e( 'Group rider details captured on this reservation.', 'equine-event-manager' ); ?></p>
										</div>
									</div>
									<?php
									$this->render_key_value_rows(
										array(
											array(
												__( 'Rider Count', 'equine-event-manager' ) => (string) $group_rider_count,
											),
											! empty( $group_rider_names ) ? array(
												__( 'Riders', 'equine-event-manager' ) => implode( ', ', $group_rider_names ),
											) : array(),
										)
									);
									?>
								</div>
							</div>
						<?php endif; ?>

						<div class="postbox eem-card eem-order-payment-card">
							<div class="inside">
								<div class="eem-order-product-card__header">
									<div>
										<h2><?php esc_html_e( 'Order Summary', 'equine-event-manager' ); ?></h2>
										<p class="eem-order-product-card__subcopy"><?php esc_html_e( 'Payment details and order totals.', 'equine-event-manager' ); ?></p>
									</div>
									<div class="eem-order-product-card__meta">
										<?php echo wp_kses_post( $payment_status_badge ); ?>
									</div>
								</div>
								<div class="eem-order-payment-card__hero">
									<div class="eem-order-payment-card__hero-label"><?php esc_html_e( 'Total Paid', 'equine-event-manager' ); ?></div>
									<div class="eem-order-payment-card__hero-amount"><?php echo esc_html( $this->format_money( (float) $order['total'] ) ); ?></div>
								</div>
								<?php $this->render_order_totals_table( $order ); ?>
								<?php if ( ! empty( $refund_details ) ) : ?>
									<div class="eem-order-product-card__section">
										<h3><?php esc_html_e( 'Refund Details', 'equine-event-manager' ); ?></h3>
										<?php $this->render_key_value_rows( $refund_details ); ?>
									</div>
								<?php endif; ?>
								<?php if ( $invoice_payment_url ) : ?>
									<div class="eem-order-product-card__section">
										<h3><?php esc_html_e( 'Invoice Link', 'equine-event-manager' ); ?></h3>
										<div class="eem-order-payment-link-row">
											<input class="regular-text code" type="text" readonly value="<?php echo esc_attr( $invoice_payment_url ); ?>" />
											<button type="button" class="button" data-eem-copy-url="<?php echo esc_attr( $invoice_payment_url ); ?>"><?php esc_html_e( 'Copy', 'equine-event-manager' ); ?></button>
											<?php if ( in_array( $order['status_slug'], array( 'unpaid', 'invoice-sent' ), true ) ) : ?>
												<a class="button button-primary" target="_blank" href="<?php echo esc_url( $invoice_payment_url ); ?>"><?php esc_html_e( 'Open Payment Page', 'equine-event-manager' ); ?></a>
											<?php endif; ?>
										</div>
										<div class="eem-order-payment-card__meta-grid">
											<div><span><?php esc_html_e( 'Invoice Status', 'equine-event-manager' ); ?></span><strong><?php echo esc_html( $order['status_label'] ); ?></strong></div>
											<div><span><?php esc_html_e( 'Last Sent', 'equine-event-manager' ); ?></span><strong><?php echo esc_html( $invoice_sent_at ? $invoice_sent_at : __( 'Not sent yet', 'equine-event-manager' ) ); ?></strong></div>
											<div><span><?php esc_html_e( 'Transaction', 'equine-event-manager' ); ?></span><strong><?php echo esc_html( $transaction_summary ); ?></strong></div>
										</div>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<aside class="eem-order-detail-sidebar">
						<div class="postbox eem-card eem-order-side-card eem-order-side-card--requests">
							<div class="inside">
								<h2><?php esc_html_e( 'Special Instructions', 'equine-event-manager' ); ?></h2>
								<div class="eem-order-side-card__content">
									<?php if ( '' !== trim( $special_requests ) ) : ?>
										<div class="eem-order-side-card__text"><?php echo nl2br( esc_html( trim( $special_requests ) ) ); ?></div>
									<?php else : ?>
										<div class="eem-order-side-card__empty"><?php esc_html_e( 'No special instructions were provided with this order.', 'equine-event-manager' ); ?></div>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<div class="postbox eem-card eem-order-side-card eem-order-side-card--customer">
							<div class="inside">
								<h2><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></h2>
								<div class="eem-order-side-card__content">
									<div class="eem-order-side-card__customer-name"><?php echo esc_html( $order['customer_name'] ); ?></div>
									<div class="eem-order-side-card__summary-link"><?php esc_html_e( '1 order', 'equine-event-manager' ); ?></div>
									<div class="eem-order-side-card__meta-label"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></div>
									<div class="eem-order-side-card__text"><?php echo esc_html( $reservation_title ); ?></div>
									<div class="eem-order-side-card__meta-label"><?php esc_html_e( 'Contact information', 'equine-event-manager' ); ?></div>
									<div class="eem-order-side-card__text"><?php echo wp_kses_post( $customer_email_html ); ?></div>
									<div class="eem-order-side-card__text"><?php echo wp_kses_post( $customer_phone_html ); ?></div>
									<div class="eem-order-side-card__meta-label"><?php esc_html_e( 'Billing address', 'equine-event-manager' ); ?></div>
									<div class="eem-order-side-card__text"><?php echo nl2br( esc_html( $billing_address ) ); ?></div>
									<div class="eem-order-side-card__meta-grid">
										<div><span><?php esc_html_e( 'Order Number', 'equine-event-manager' ); ?></span><strong><?php echo esc_html( $this->format_order_number_display( (string) $order['order_number'] ) ); ?></strong></div>
										<div><span><?php esc_html_e( 'Created', 'equine-event-manager' ); ?></span><strong><?php echo esc_html( $order['created_at'] ); ?></strong></div>
										<div><span><?php esc_html_e( 'Agreement', 'equine-event-manager' ); ?></span><strong><?php echo esc_html( $has_agreement ? __( 'Yes', 'equine-event-manager' ) : __( 'No', 'equine-event-manager' ) ); ?></strong></div>
									</div>
								</div>
							</div>
						</div>
					</aside>
				</div>
			</div>
		</div>
		<script>
			(function() {
				var copyButtons = document.querySelectorAll('[data-eem-copy-url]');
				function setCopiedState(button) {
					var originalText = button.textContent;
					button.textContent = '<?php echo esc_js( __( 'Copied', 'equine-event-manager' ) ); ?>';
					window.setTimeout(function() {
						button.textContent = originalText;
					}, 1800);
				}
				copyButtons.forEach(function(button) {
					button.addEventListener('click', function() {
						var text = button.getAttribute('data-eem-copy-url') || '';
						if (!text) {
							return;
						}
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(text).then(function() {
								setCopiedState(button);
							});
							return;
						}
						var temp = document.createElement('input');
						temp.value = text;
						document.body.appendChild(temp);
						temp.select();
						document.execCommand('copy');
						document.body.removeChild(temp);
						setCopiedState(button);
					});
				});
			})();
		</script>
		<?php
	}

	/**
	 * Render the refund selection page for a single order.
	 *
	 * @return void
	 */
	public function render_order_refund_page() {
		$this->guard_admin_page();

		$order_key     = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$order         = $this->orders_repository->get_order( $order_key );
		$refund_groups = $order ? $this->build_refund_groups_for_order( $order ) : array();
		$has_auth_only = false;

		if ( ! $order ) {
			?>
			<div class="wrap eem-shell-wrap eem-shell-wrap--header">
				<?php $this->render_brand_banner( __( 'Refund Order', 'equine-event-manager' ) ); ?>
				<div class="eem-shell-content">
				<div class="postbox eem-card">
					<div class="inside">
					<p><?php esc_html_e( 'Order not found.', 'equine-event-manager' ); ?></p>
					</div>
				</div>
				</div>
			</div>
			<?php
			return;
		}

		foreach ( $refund_groups as $group ) {
			if ( 'authorize_net' === $group['gateway'] ) {
				$has_auth_only = true;
				break;
			}
		}
		?>
		<div class="wrap eem-shell-wrap eem-shell-wrap--header">
			<?php
			$this->render_brand_banner(
				sprintf( __( 'Refund Order #%s', 'equine-event-manager' ), $order['order_number'] ),
				$order['event_name']
			);
			?>
			<?php $this->render_admin_notice(); ?>

			<div class="eem-shell-content">
			<div class="postbox eem-card">
				<div class="inside">
				<h1 class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Refund Order #%s', 'equine-event-manager' ), $order['order_number'] ) ); ?></h1>
				<p>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'equine-event-manager-order', 'order_key' => $order['order_key'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Back to Order', 'equine-event-manager' ); ?></a>
					|
					<a href="<?php echo esc_url( $this->get_orders_page_url() ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a>
				</p>
				</div>
			</div>

			<div class="postbox eem-card">
				<div class="inside">
				<h2><?php esc_html_e( 'Choose Refund Items', 'equine-event-manager' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: customer name */
							__( 'Select the charge lines you want to refund for %s. We will only refund the selected amounts.', 'equine-event-manager' ),
							$order['customer_name']
						)
					);
					?>
				</p>
				<?php if ( $has_auth_only ) : ?>
					<p class="description"><?php esc_html_e( 'Authorize.net orders can currently be refunded only as full charged components, so those sections appear as one full-refund option.', 'equine-event-manager' ); ?></p>
				<?php endif; ?>
				<?php if ( empty( $refund_groups ) ) : ?>
					<p><?php esc_html_e( 'There are no refundable transactions left on this order.', 'equine-event-manager' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="equine_event_manager_refund_order" />
						<input type="hidden" name="order_key" value="<?php echo esc_attr( $order['order_key'] ); ?>" />
						<?php wp_nonce_field( 'equine_event_manager_refund_order_' . $order['order_key'] ); ?>

						<div>
							<?php foreach ( $refund_groups as $group ) : ?>
								<div class="postbox eem-card">
									<div class="inside">
									<div>
										<div>
											<h3><?php echo esc_html( $group['title'] ); ?></h3>
											<p class="description"><?php echo esc_html( $group['meta'] ); ?></p>
										</div>
										<div>
											<span><?php echo esc_html( sprintf( __( 'Gateway: %s', 'equine-event-manager' ), $this->orders_repository->get_gateway_label( $group['gateway'] ) ) ); ?></span>
											<strong><?php echo esc_html( sprintf( __( 'Available: %s', 'equine-event-manager' ), $this->format_money( $group['remaining_amount'] ) ) ); ?></strong>
										</div>
									</div>
									<div>
										<?php foreach ( $group['items'] as $item ) : ?>
											<label>
												<input type="checkbox" name="refund_lines[]" value="<?php echo esc_attr( $item['id'] ); ?>" />
												<span>
													<span><strong><?php echo esc_html( $item['label'] ); ?></strong></span>
													<?php if ( ! empty( $item['description'] ) ) : ?>
														<span><?php echo esc_html( $item['description'] ); ?></span>
													<?php endif; ?>
												</span>
												<strong><?php echo esc_html( $this->format_money( $item['amount'] ) ); ?></strong>
											</label>
											<br />
										<?php endforeach; ?>
									</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<?php submit_button( __( 'Refund Selected Items', 'equine-event-manager' ) ); ?>
					</form>
				<?php endif; ?>
				</div>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Build refund groups for an order.
	 *
	 * @param array $order Order payload.
	 * @return array
	 */
	private function build_refund_groups_for_order( $order ) {
		$groups = array();

		foreach ( (array) $order['components'] as $component ) {
			if ( empty( $component['transaction_id'] ) || ! in_array( $component['payment_status'], array( 'paid', 'captured', 'completed', 'partially_paid', 'partially_refunded' ), true ) ) {
				continue;
			}

			$remaining_amount = $this->get_component_remaining_refundable_amount( $component );

			if ( $remaining_amount <= 0 ) {
				continue;
			}

			$items = $this->build_refund_items_for_component( $order, $component, $remaining_amount );

			if ( empty( $items ) ) {
				continue;
			}

			$groups[] = array(
				'component'           => $component,
				'component_signature' => $this->get_component_signature( $component ),
				'title'               => $this->get_component_refund_title( $component ),
				'meta'                => $this->get_component_refund_meta( $component ),
				'gateway'             => isset( $component['payment_gateway'] ) ? sanitize_key( $component['payment_gateway'] ) : '',
				'remaining_amount'    => $remaining_amount,
				'items'               => $items,
			);
		}

		return $groups;
	}

	/**
	 * Build refund items for a single charged component.
	 *
	 * @param array $order Order payload.
	 * @param array $component Component payload.
	 * @param float $remaining_amount Remaining refundable amount.
	 * @return array
	 */
	private function build_refund_items_for_component( $order, $component, $remaining_amount ) {
		$gateway = isset( $component['payment_gateway'] ) ? sanitize_key( $component['payment_gateway'] ) : '';

		if ( 'authorize_net' === $gateway ) {
			return array(
				array(
					'id'          => $this->build_refund_item_id( $component, 'full-component' ),
					'label'       => sprintf( __( 'Full %s refund', 'equine-event-manager' ), $this->get_component_refund_title( $component ) ),
					'description' => __( 'This gateway currently supports only full component refunds in the admin.', 'equine-event-manager' ),
					'amount'      => $remaining_amount,
				),
			);
		}

		$items = 'stall' === $component['table']
			? $this->build_stall_component_refund_items( $order, $component )
			: $this->build_rv_component_refund_items( $order, $component );

		$refunded_item_ids = $this->get_component_refunded_item_ids( $component );
		$valid_items       = array();

		foreach ( $items as $item ) {
			if ( empty( $item['amount'] ) || (float) $item['amount'] <= 0 ) {
				continue;
			}

			$item['id']   = $this->build_refund_item_id( $component, $item['slug'] );

			if ( in_array( $item['id'], $refunded_item_ids, true ) ) {
				continue;
			}

			$valid_items[] = $item;
		}

		if ( empty( $valid_items ) ) {
			$valid_items[] = array(
				'id'          => $this->build_refund_item_id( $component, 'component-total' ),
				'label'       => $this->get_component_refund_title( $component ),
				'description' => __( 'Refund the remaining component total.', 'equine-event-manager' ),
				'amount'      => $remaining_amount,
			);
		}

		return $valid_items;
	}

	/**
	 * Get the refunded amount already recorded against a component.
	 *
	 * @param array $component Component payload.
	 * @return float
	 */
	private function get_component_refunded_amount( $component ) {
		return $this->refund_engine()->get_component_refunded_amount( $component );
	}

	/**
	 * Get the remaining refundable amount for a component.
	 *
	 * @param array $component Component payload.
	 * @return float
	 */
	private function get_component_remaining_refundable_amount( $component ) {
		return $this->refund_engine()->get_component_remaining_refundable_amount( $component );
	}

	/**
	 * Get already-refunded line item IDs for a component.
	 *
	 * @param array $component Component payload.
	 * @return array
	 */
	public function get_component_refunded_item_ids( $component ) {
		$notes     = isset( $component['notes'] ) ? (string) $component['notes'] : '';
		$raw_value = $this->get_order_note_value( $notes, 'Refunded Items' );

		if ( '' === $raw_value ) {
			return array();
		}

		$items = array_map( 'trim', explode( ',', $raw_value ) );
		$items = array_map( 'sanitize_text_field', $items );

		return array_values( array_unique( array_filter( $items ) ) );
	}

	/**
	 * Persist refund bookkeeping on a refunded component row.
	 *
	 * @param array  $component Component payload.
	 * @param float  $refund_amount Refunded amount.
	 * @param string $refund_transaction_id Refund transaction/reference ID.
	 * @param array  $refunded_item_ids Refunded item IDs.
	 * @return bool
	 */
	private function persist_component_refund( $component, $refund_amount, $refund_transaction_id, $refunded_item_ids ) {
		return $this->refund_engine()->persist_component_refund( $component, $refund_amount, $refund_transaction_id, $refunded_item_ids );
	}

	/**
	 * Build itemized refund rows for a stall component.
	 *
	 * @param array $order Order payload.
	 * @param array $component Component payload.
	 * @return array
	 */
	private function build_stall_component_refund_items( $order, $component ) {
		$row = $this->get_component_db_row( $component );

		if ( empty( $row ) ) {
			return array();
		}

		$items                = array();
		$general_addons       = $this->parse_general_addon_breakdown_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
		$group_charges        = $this->parse_group_charge_breakdown_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
		$general_addon_total  = array_sum( wp_list_pluck( $general_addons, 'subtotal' ) );
		$group_charge_total   = array_sum( wp_list_pluck( $group_charges, 'subtotal' ) );
		$stall_quantity       = absint( $row['stall_qty'] ) + absint( $row['tack_stall_qty'] );
		$stay_units           = $this->get_billable_stay_units( $row['arrival_date'], $row['departure_date'], $row['stay_type'] );
		$base_subtotal        = $stall_quantity * (float) $row['unit_price'] * $stay_units;
		$shavings_pool        = max( 0, (float) $row['subtotal'] - $base_subtotal - $general_addon_total - $group_charge_total );
		$required_qty         = absint( $row['required_shavings_qty'] );
		$additional_qty       = absint( $row['additional_shavings_qty'] );
		$total_shavings_qty   = $required_qty + $additional_qty;
		$required_subtotal    = 0.0;
		$additional_subtotal  = 0.0;
		$fees                 = max( 0, (float) $row['total'] - (float) $row['subtotal'] );

		if ( $total_shavings_qty > 0 && $shavings_pool > 0 ) {
			if ( $required_qty > 0 && $additional_qty > 0 ) {
				$required_ratio     = $required_qty / $total_shavings_qty;
				$required_subtotal  = $shavings_pool * $required_ratio;
				$additional_subtotal = $shavings_pool - $required_subtotal;
			} elseif ( $required_qty > 0 ) {
				$required_subtotal = $shavings_pool;
			} elseif ( $additional_qty > 0 ) {
				$additional_subtotal = $shavings_pool;
			}
		}

		if ( $base_subtotal > 0 ) {
			$items[] = array(
				'slug'        => 'stall-reservation',
				'label'       => __( 'Stall Reservation', 'equine-event-manager' ),
				'description' => sprintf(
					/* translators: 1: stay type, 2: arrival date, 3: departure date. */
					__( '%1$s stay from %2$s to %3$s', 'equine-event-manager' ),
					$this->format_stay_type_label( $row['stay_type'] ),
					$this->format_reservation_date_label( $row['arrival_date'] ),
					$this->format_reservation_date_label( $row['departure_date'] )
				),
				'amount'      => $base_subtotal,
			);
		}

		if ( $required_subtotal > 0 ) {
			$items[] = array(
				'slug'        => 'required-shavings',
				'label'       => __( 'Required Shavings', 'equine-event-manager' ),
				'description' => sprintf( _n( '%d bag', '%d bags', $required_qty, 'equine-event-manager' ), $required_qty ),
				'amount'      => $required_subtotal,
			);
		}

		if ( $additional_subtotal > 0 ) {
			$items[] = array(
				'slug'        => 'additional-shavings',
				'label'       => __( 'Additional Shavings', 'equine-event-manager' ),
				'description' => sprintf( _n( '%d bag', '%d bags', $additional_qty, 'equine-event-manager' ), $additional_qty ),
				'amount'      => $additional_subtotal,
			);
		}

		foreach ( $general_addons as $index => $addon ) {
			$items[] = array(
				'slug'        => 'general-addon-' . $index,
				'label'       => isset( $addon['label'] ) ? $addon['label'] : __( 'Add-On', 'equine-event-manager' ),
				'description' => ! empty( $addon['per_label'] ) ? sprintf( __( '%1$d x %2$s', 'equine-event-manager' ), absint( $addon['quantity'] ), $addon['per_label'] ) : sprintf( _n( '%d unit', '%d units', absint( $addon['quantity'] ), 'equine-event-manager' ), absint( $addon['quantity'] ) ),
				'amount'      => isset( $addon['subtotal'] ) ? (float) $addon['subtotal'] : 0.0,
			);
		}

		foreach ( $group_charges as $index => $charge ) {
			$items[] = array(
				'slug'        => 'group-charge-' . $index,
				'label'       => isset( $charge['label'] ) ? $charge['label'] : __( 'Group Charge', 'equine-event-manager' ),
				'description' => sprintf( _n( '%d rider', '%d riders', absint( $charge['quantity'] ), 'equine-event-manager' ), absint( $charge['quantity'] ) ),
				'amount'      => isset( $charge['subtotal'] ) ? (float) $charge['subtotal'] : 0.0,
			);
		}

		if ( $fees > 0 ) {
			$items[] = array(
				'slug'        => 'fees',
				'label'       => __( 'Fees', 'equine-event-manager' ),
				'description' => __( 'Refund the charged fees for this component.', 'equine-event-manager' ),
				'amount'      => $fees,
			);
		}

		return $items;
	}

	/**
	 * Build itemized refund rows for an RV component.
	 *
	 * @param array $order Order payload.
	 * @param array $component Component payload.
	 * @return array
	 */
	private function build_rv_component_refund_items( $order, $component ) {
		$row = $this->get_component_db_row( $component );

		if ( empty( $row ) ) {
			return array();
		}

		$items               = array();
		$general_addons      = $this->parse_general_addon_breakdown_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
		$group_charges       = $this->parse_group_charge_breakdown_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
		$general_addon_total = array_sum( wp_list_pluck( $general_addons, 'subtotal' ) );
		$group_charge_total  = array_sum( wp_list_pluck( $group_charges, 'subtotal' ) );
		$rv_quantity         = absint( $row['rv_qty'] );
		$stay_type           = ! empty( $row['stay_type'] ) ? sanitize_key( $row['stay_type'] ) : 'nightly';
		$stay_units          = $this->get_billable_stay_units( $row['arrival_date'], $row['departure_date'], $stay_type );
		$base_subtotal       = $rv_quantity * (float) $row['unit_price'] * $stay_units;
		$rv_addon_total      = max( 0, (float) $row['subtotal'] - $base_subtotal - $general_addon_total - $group_charge_total );
		$rv_labels           = $this->get_rv_addon_labels_from_row_payload( $row );
		$row_breakdown       = array();
		$row_priced_total    = 0.0;
		$reservation_id      = self::extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
		$reservation_meta    = $reservation_id ? $this->get_reservation_meta_values( $reservation_id ) : array( 'rv_addons' => array() );
		$fees                = max( 0, (float) $row['total'] - (float) $row['subtotal'] );

		foreach ( $rv_labels as $addon_label ) {
			$rate = $this->get_named_rv_addon_price( isset( $reservation_meta['rv_addons'] ) ? $reservation_meta['rv_addons'] : array(), $addon_label );

			if ( $rate <= 0 ) {
				$row_breakdown[ $addon_label ] = array(
					'label'    => $addon_label,
					'subtotal' => 0.0,
				);
				continue;
			}

			$row_breakdown[ $addon_label ] = array(
				'label'    => $addon_label,
				'subtotal' => $rv_quantity * $rate * $stay_units,
			);
			$row_priced_total += $row_breakdown[ $addon_label ]['subtotal'];
		}

		if ( $rv_addon_total > 0 && ! empty( $row_breakdown ) ) {
			if ( $row_priced_total > 0 ) {
				$scale = $rv_addon_total / $row_priced_total;

				foreach ( $row_breakdown as $addon_label => $addon_row ) {
					$row_breakdown[ $addon_label ]['subtotal'] = $addon_row['subtotal'] * $scale;
				}
			} else {
				$equal_share = $rv_addon_total / count( $row_breakdown );

				foreach ( $row_breakdown as $addon_label => $addon_row ) {
					$row_breakdown[ $addon_label ]['subtotal'] = $equal_share;
				}
			}
		}

		if ( $base_subtotal > 0 ) {
			$items[] = array(
				'slug'        => 'rv-reservation',
				'label'       => __( 'RV Reservation', 'equine-event-manager' ),
				'description' => sprintf(
					/* translators: 1: stay type, 2: arrival date, 3: departure date. */
					__( '%1$s stay from %2$s to %3$s', 'equine-event-manager' ),
					$this->format_stay_type_label( $stay_type ),
					$this->format_reservation_date_label( $row['arrival_date'] ),
					$this->format_reservation_date_label( $row['departure_date'] )
				),
				'amount'      => $base_subtotal,
			);
		}

		foreach ( $row_breakdown as $index => $addon_row ) {
			$items[] = array(
				'slug'        => 'rv-addon-' . sanitize_title( $index ),
				'label'       => sprintf( __( '%s Add-On', 'equine-event-manager' ), $addon_row['label'] ),
				'description' => sprintf( _n( '%d RV spot', '%d RV spots', $rv_quantity, 'equine-event-manager' ), $rv_quantity ),
				'amount'      => (float) $addon_row['subtotal'],
			);
		}

		foreach ( $general_addons as $index => $addon ) {
			$items[] = array(
				'slug'        => 'general-addon-' . $index,
				'label'       => isset( $addon['label'] ) ? $addon['label'] : __( 'Add-On', 'equine-event-manager' ),
				'description' => ! empty( $addon['per_label'] ) ? sprintf( __( '%1$d x %2$s', 'equine-event-manager' ), absint( $addon['quantity'] ), $addon['per_label'] ) : sprintf( _n( '%d unit', '%d units', absint( $addon['quantity'] ), 'equine-event-manager' ), absint( $addon['quantity'] ) ),
				'amount'      => isset( $addon['subtotal'] ) ? (float) $addon['subtotal'] : 0.0,
			);
		}

		foreach ( $group_charges as $index => $charge ) {
			$items[] = array(
				'slug'        => 'group-charge-' . $index,
				'label'       => isset( $charge['label'] ) ? $charge['label'] : __( 'Group Charge', 'equine-event-manager' ),
				'description' => sprintf( _n( '%d rider', '%d riders', absint( $charge['quantity'] ), 'equine-event-manager' ), absint( $charge['quantity'] ) ),
				'amount'      => isset( $charge['subtotal'] ) ? (float) $charge['subtotal'] : 0.0,
			);
		}

		if ( $fees > 0 ) {
			$items[] = array(
				'slug'        => 'fees',
				'label'       => __( 'Fees', 'equine-event-manager' ),
				'description' => __( 'Refund the charged fees for this component.', 'equine-event-manager' ),
				'amount'      => $fees,
			);
		}

		return $items;
	}

	/**
	 * Get the DB row payload for a component.
	 *
	 * @param array $component Component payload.
	 * @return array
	 */
	private function get_component_db_row( $component ) {
		global $wpdb;

		if ( empty( $component['table'] ) || empty( $component['row_id'] ) ) {
			return array();
		}

		$table_name = 'stall' === $component['table'] ? $wpdb->prefix . 'en_stall_reservations' : $wpdb->prefix . 'en_rv_reservations';

		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
			absint( $component['row_id'] )
		);

		$row = $wpdb->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Get a stable signature for a component.
	 *
	 * @param array $component Component payload.
	 * @return string
	 */
	private function get_component_signature( $component ) {
		return sanitize_key( $component['table'] ) . ':' . absint( $component['row_id'] );
	}

	/**
	 * Build a stable refund item ID for a component row.
	 *
	 * @param array  $component Component payload.
	 * @param string $slug Item slug.
	 * @return string
	 */
	private function build_refund_item_id( $component, $slug ) {
		return $this->get_component_signature( $component ) . '|' . sanitize_title( $slug );
	}

	/**
	 * Get a user-facing title for a refundable component.
	 *
	 * @param array $component Component payload.
	 * @return string
	 */
	private function get_component_refund_title( $component ) {
		return 'stall' === $component['table'] ? __( 'Stall Charge', 'equine-event-manager' ) : __( 'RV Charge', 'equine-event-manager' );
	}

	/**
	 * Get component meta text for refund UI.
	 *
	 * @param array $component Component payload.
	 * @return string
	 */
	private function get_component_refund_meta( $component ) {
		$parts = array();

		if ( ! empty( $component['order_number'] ) ) {
			$parts[] = $this->format_order_number_display( (string) $component['order_number'] );
		}

		if ( ! empty( $component['transaction_id'] ) ) {
			$parts[] = sprintf( __( 'Transaction: %s', 'equine-event-manager' ), $component['transaction_id'] );
		}

		return ! empty( $parts ) ? implode( ' | ', $parts ) : __( 'Refundable transaction', 'equine-event-manager' );
	}


	/**
	 * Get feature settings with defaults.
	 *
	 * @return array<string, int>
	 */
	private function get_feature_settings() {
		return wp_parse_args(
			get_option( self::FEATURE_SETTINGS_OPTION, array() ),
			array(
				'native_events_enabled' => 0,
			)
		);
	}

	/**
	 * Get integration settings with defaults.
	 *
	 * @return array
	 */
	private function get_integration_settings() {
		return wp_parse_args(
			get_option( self::INTEGRATION_SETTINGS_OPTION, array() ),
			array(
				'tec_integration_enabled' => 1,
				'default_event_source'    => 'feed',
				'feed_url'                => '',
				'sendgrid_api_key'        => '',
			)
		);
	}

	/**
	 * Handle a report export request.
	 */
	public function handle_report_export() {
		$this->guard_admin_action();

		check_admin_referer( 'equine_event_manager_export_report', 'equine_event_manager_export_report_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
		$orders         = $this->orders_repository->get_orders();
		$export_orders  = array_filter(
			$orders,
			function ( $order ) use ( $reservation_id ) {
				return 0 === $reservation_id || absint( $order['reservation_id'] ) === $reservation_id;
			}
		);

		$reservation_name = 0 === $reservation_id ? __( 'All reservations', 'equine-event-manager' ) : get_the_title( $reservation_id );
		$file_name        = sprintf(
			'equine-event-manager-report-%s-%s.csv',
			$reservation_id ? absint( $reservation_id ) : 'all',
			gmdate( 'Ymd-His' )
		);

		$this->log_report_export( $reservation_id, $reservation_name, $file_name );
		$this->stream_orders_csv( $file_name, $export_orders );
	}

	/**
	 * Handle order CSV export.
	 */
	public function handle_order_export() {
		$this->guard_admin_action();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_export_order_' . $order_key );

		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'equine-event-manager' ) );
		}

		$this->stream_orders_csv( 'equine-event-manager-order-' . $order['order_number'] . '.csv', array( $order ) );
	}

	/**
	 * Handle deleting an order and its underlying reservation rows.
	 */
	public function handle_order_delete() {
		$this->guard_admin_action();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_delete_order_' . $order_key );

		$deleted = $this->orders_repository->delete_order( $order_key );

		wp_safe_redirect(
			$this->get_orders_page_url(
				array(
					'en_notice' => $deleted ? 'order_deleted' : 'order_delete_failed',
				)
			)
		);
		exit;
	}

	/**
	 * Process an amount-based refund against an order (C6.B).
	 *
	 * Distributes the requested refund amount across the order's payment
	 * components SEQUENTIALLY — refunds the first component up to its
	 * remaining-refundable capacity, then the next, until the requested
	 * amount is exhausted. Per-component refunds hit the order's
	 * configured gateway (Stripe or Authorize.net) via the existing
	 * refund_order_component() infrastructure.
	 *
	 * Server-side validations (enforced regardless of any client-side checks):
	 *   - amount > 0
	 *   - amount <= sum of remaining refundable across all components
	 *     (this single check covers both "<= captured" and
	 *     "<= captured - already_refunded" cases — the components'
	 *     `remaining_refundable` already accounts for prior partial
	 *     refunds via get_component_refunded_amount).
	 *
	 * Wraps the legacy private refund stack (refund_order_component +
	 * persist_component_refund) so external callers (the C6.B AJAX
	 * endpoint, the future C6.C bulk engine) don't need access to
	 * those internals. Scheduled for extraction into a dedicated
	 * EEM_Refund_Engine class per CLEANUP #27 once C6.C lands and
	 * clarifies the batch-error-attribution contract.
	 *
	 * @param string $order_key       Order key.
	 * @param float  $requested_amount Refund amount in dollars (positive).
	 * @param string $reason          Optional human-readable reason; stored on activity-log payload (currently unused by the gateway calls themselves).
	 * @return array|WP_Error On success: array{ refunded_amount:float, components:array, new_status_slug:string, new_status_label:string }. On failure: WP_Error with code and message.
	 */
	public function process_amount_refund( $order_key, $requested_amount, $reason = '' ) {
		return $this->refund_engine()->process_amount_refund( $order_key, $requested_amount, $reason );
	}

	/**
	 * Get the remaining refundable amount for an order across all its
	 * components (C6.C prep helper). Wraps the private per-component
	 * helper so external callers — the C6.C bulk-refund step endpoint
	 * primarily — can ask "what should I refund for this order?" without
	 * duplicating the math.
	 *
	 * @param string $order_key Order key.
	 * @return float Sum of remaining refundable across all components, or 0.0 when order missing / already fully refunded.
	 */
	public function get_order_remaining_refundable( $order_key ) {
		$order = $this->orders_repository->get_order( $order_key );

		if ( ! is_array( $order ) || empty( $order['components'] ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( (array) $order['components'] as $component ) {
			$total += $this->get_component_remaining_refundable_amount( $component );
		}

		return (float) $total;
	}

	/**
	 * AJAX endpoint: single-order refund from the C6.B Order Detail modal.
	 *
	 * Validates nonce + capability, parses the POST payload, calls
	 * process_amount_refund(), writes an activity-log entry on success,
	 * and returns a JSON response with the fragments the C6.B JS handler
	 * uses to update the page in-place (option-3 UX per C6.B kickoff):
	 *   - new_status_slug / new_status_label / new_status_css for the
	 *     status badge swap
	 *   - banner_html for the payment-banner DOM update (empty string
	 *     when the order is now fully paid/refunded — JS removes the
	 *     banner element)
	 *   - refund_history_html for the Payment Details sidebar block
	 *   - requires_reload: true when in-place update isn't safe (mixed-
	 *     gateway partial failure, etc.). JS falls back to toast+reload.
	 *
	 * On failure: wp_send_json_error with error code + message. JS
	 * surfaces the message inline in the modal.
	 *
	 * @return void  Always exits via wp_send_json_*.
	 */
	public function handle_ajax_refund_single() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'capability', 'message' => __( 'You do not have permission to refund orders.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$nonce     = isset( $_POST['_eem_refund_single_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_refund_single_nonce'] ) ) : '';

		if ( '' === $order_key || ! wp_verify_nonce( $nonce, 'eem_refund_single_' . $order_key ) ) {
			wp_send_json_error( array( 'code' => 'nonce', 'message' => __( 'Security check failed. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		$amount_raw = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$amount     = (float) preg_replace( '/[^0-9.\-]/', '', $amount_raw );
		$reason     = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$notify     = ! empty( $_POST['notify'] );

		$result = $this->process_amount_refund( $order_key, $amount, $reason );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), 400 );
		}

		// CLEANUP #30 — send the customer-facing refund-processed email when the
		// admin opted in. Non-fatal: a send failure doesn't fail the refund (the
		// money already moved); we surface notification_sent in the response.
		$notification_sent = false;
		if ( $notify ) {
			$order_for_email   = $this->orders_repository->get_order( $order_key );
			$emailed           = is_array( $order_for_email )
				? $this->send_refund_email_for_order( $order_for_email, $result['refunded_amount'], $reason )
				: new WP_Error( 'refund_email_no_order', 'Order not found for email.' );
			$notification_sent = ! is_wp_error( $emailed ) && true === $emailed;
		}

		// C6.C: activity-log write moved into process_amount_refund kernel so
		// both single (this) and bulk (handle_ajax_bulk_refund_step) callers
		// get telemetry without duplicating the write.

		// Build in-place fragments.
		$reloaded = $this->orders_repository->get_order( $order_key );

		// Status badge HTML (Payment Details sidebar carries one; the
		// plugin-header carries another via .eem-page-meta).
		$status_css = EEM_Orders_List_Page::status_slug_to_css_class( $result['new_status_slug'] );
		$status_badge_html = sprintf(
			'<span class="eem-status-badge eem-status-%1$s">%2$s</span>',
			esc_attr( $status_css ),
			esc_html( $result['new_status_label'] )
		);

		// Payment-banner HTML — empty string when the order no longer
		// has outstanding balance (Paid / Refunded / Partially Refunded
		// with no remaining capture). JS removes the banner element
		// when this is empty.
		$banner_html = '';
		$outstanding_states = array( 'unpaid', 'invoice-sent', 'partially-paid' );
		if ( in_array( $result['new_status_slug'], $outstanding_states, true ) ) {
			$amount_remaining = isset( $reloaded['total'] ) ? (float) $reloaded['total'] : 0.0;
			$banner_html      = sprintf(
				'<div class="eem-order-payment-banner__content"><div class="eem-order-payment-banner__title">%1$s</div><div class="eem-order-payment-banner__meta"><span class="eem-order-payment-banner__amount">$%2$s</span> %3$s</div></div>',
				esc_html__( 'Payment Outstanding', 'equine-event-manager' ),
				esc_html( number_format( $amount_remaining, 2 ) ),
				esc_html__( 'has not been collected for this order.', 'equine-event-manager' )
			);
		}

		// Refund history fragment — Payment Details sidebar replaces
		// the "No refunds processed" hint with a per-component line.
		ob_start();
		?>
		<div class="eem-order-payment__val">
			<?php foreach ( $result['components'] as $rc ) : ?>
				<div class="eem-order-payment__refund-line">
					<strong>$<?php echo esc_html( number_format( (float) $rc['amount'], 2 ) ); ?></strong>
					<span class="eem-order-payment__mono"><?php echo esc_html( $rc['transaction_id'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$refund_history_html = (string) ob_get_clean();

		wp_send_json_success( array(
			'refunded_amount'      => $result['refunded_amount'],
			'new_status_slug'      => $result['new_status_slug'],
			'new_status_label'     => $result['new_status_label'],
			'new_status_css'       => $status_css,
			'status_badge_html'    => $status_badge_html,
			'banner_html'          => $banner_html,
			'refund_history_html'  => $refund_history_html,
			'requires_reload'      => false,
			'notification_sent'    => $notification_sent,
		) );
	}

	/**
	 * AJAX endpoint: cancel a single order (v2 — order cancellation).
	 *
	 * Sets the order to cancelled (terminal; frees its stall/RV inventory) and,
	 * when the admin opted in, emails the customer a cancellation notice carrying
	 * the reservation's cancellation policy. Cancel is independent of refunds —
	 * if money is owed back the admin refunds the (still-recorded) payment first.
	 *
	 * @return void
	 */
	public function handle_ajax_cancel_single() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'capability', 'message' => __( 'You do not have permission to cancel orders.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$nonce     = isset( $_POST['_eem_cancel_single_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_cancel_single_nonce'] ) ) : '';

		if ( '' === $order_key || ! wp_verify_nonce( $nonce, 'eem_cancel_single_' . $order_key ) ) {
			wp_send_json_error( array( 'code' => 'nonce', 'message' => __( 'Security check failed. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$notify = ! isset( $_POST['notify'] ) || ! empty( $_POST['notify'] );

		$order = $this->orders_repository->get_order( $order_key );
		if ( ! is_array( $order ) ) {
			wp_send_json_error( array( 'code' => 'not_found', 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}
		if ( isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'] ) {
			wp_send_json_error( array( 'code' => 'already_cancelled', 'message' => __( 'This order is already cancelled.', 'equine-event-manager' ) ), 409 );
		}

		$cancelled = $this->orders_repository->cancel_order( $order_key, $reason );
		if ( ! $cancelled ) {
			wp_send_json_error( array( 'code' => 'cancel_failed', 'message' => __( 'The order could not be cancelled. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		// Customer notification — non-fatal: a send failure doesn't undo the cancel.
		$notification_sent = false;
		if ( $notify ) {
			$emailed           = $this->send_cancellation_email_for_order( $order, $reason );
			$notification_sent = ! is_wp_error( $emailed ) && true === $emailed;
		}

		$status_css        = EEM_Orders_List_Page::status_slug_to_css_class( 'cancelled' );
		$status_badge_html = sprintf(
			'<span class="eem-status-badge eem-status-%1$s">%2$s</span>',
			esc_attr( $status_css ),
			esc_html__( 'Cancelled', 'equine-event-manager' )
		);

		wp_send_json_success( array(
			'new_status_slug'   => 'cancelled',
			'new_status_label'  => __( 'Cancelled', 'equine-event-manager' ),
			'new_status_css'    => $status_css,
			'status_badge_html' => $status_badge_html,
			'notification_sent' => $notification_sent,
		) );
	}

	/**
	 * AJAX endpoint: bulk-cancel single step. Processes ONE order from a bulk
	 * batch; the JS layer calls it sequentially per selected order so a per-order
	 * failure doesn't halt the batch (mirrors the bulk-refund step contract).
	 *
	 * @return void
	 */
	public function handle_ajax_bulk_cancel_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'capability', 'message' => __( 'You do not have permission to cancel orders.', 'equine-event-manager' ) ), 403 );
		}

		$nonce = isset( $_POST['_eem_bulk_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_bulk_cancel_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'eem_bulk_cancel' ) ) {
			wp_send_json_error( array( 'code' => 'nonce', 'message' => __( 'Security check failed. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$reason    = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$notify    = ! isset( $_POST['notify'] ) || ! empty( $_POST['notify'] );

		$order = '' !== $order_key ? $this->orders_repository->get_order( $order_key ) : null;
		if ( ! is_array( $order ) ) {
			wp_send_json_error( array( 'code' => 'not_found', 'order_key' => $order_key, 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		$was_noop = isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'];
		if ( ! $was_noop ) {
			$cancelled = $this->orders_repository->cancel_order( $order_key, $reason );
			if ( ! $cancelled ) {
				wp_send_json_error( array( 'code' => 'cancel_failed', 'order_key' => $order_key, 'message' => __( 'Could not cancel this order.', 'equine-event-manager' ) ), 400 );
			}
			if ( $notify ) {
				$this->send_cancellation_email_for_order( $order, $reason );
			}
		}

		wp_send_json_success( array(
			'order_key'        => $order_key,
			'new_status_slug'  => 'cancelled',
			'new_status_label' => __( 'Cancelled', 'equine-event-manager' ),
			'was_noop'         => $was_noop,
		) );
	}

	/**
	 * AJAX endpoint: bulk "Send Payment Link" single step (v1 #7).
	 *
	 * Emails the hosted invoice payment link for ONE unpaid order from a bulk
	 * batch. The JS queue (startBulkSendLinkQueue) calls this sequentially per
	 * selected order; each call is independent so a per-order failure doesn't halt
	 * the batch. Paid orders (or orders without a customer email) are SKIPPED — a
	 * skip is a success with `skipped=true` + a reason, not a batch failure, so the
	 * progress row shows "(skipped)" rather than "failed". Reuses the same
	 * send_invoice_email_for_order() helper as the single-order "Email Payment
	 * Link" row action.
	 *
	 * @return void
	 */
	public function handle_ajax_bulk_send_link_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'capability', 'message' => __( 'You do not have permission to send payment links.', 'equine-event-manager' ) ), 403 );
		}

		$nonce = isset( $_POST['_eem_bulk_send_link_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_bulk_send_link_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'eem_bulk_send_link' ) ) {
			wp_send_json_error( array( 'code' => 'nonce', 'message' => __( 'Security check failed. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = '' !== $order_key ? $this->orders_repository->get_order( $order_key ) : null;

		$gate = $this->classify_bulk_send_link_target( $order );
		if ( 'not_found' === $gate['result'] ) {
			wp_send_json_error( array( 'code' => 'not_found', 'order_key' => $order_key, 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}
		if ( 'skip' === $gate['result'] ) {
			wp_send_json_success( array( 'order_key' => $order_key, 'skipped' => true, 'reason' => $gate['reason'] ) );
		}

		$sent = $this->send_invoice_email_for_order( $order );
		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( array( 'code' => 'send_failed', 'order_key' => $order_key, 'message' => $sent->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'order_key' => $order_key, 'skipped' => false ) );
	}

	/**
	 * Decide whether a bulk "Send Payment Link" step should send, skip, or error
	 * for an order. Split out of {@see self::handle_ajax_bulk_send_link_step} so the
	 * gate is testable without wp_die(). Only unpaid / invoice-sent orders with a
	 * customer email are sent; any other state is a benign skip.
	 *
	 * @param mixed $order The order array (or null when not found).
	 * @return array{result:string,reason?:string} result = send | skip | not_found.
	 */
	public function classify_bulk_send_link_target( $order ): array {
		if ( ! is_array( $order ) ) {
			return array( 'result' => 'not_found' );
		}
		$status = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : '';
		if ( ! in_array( $status, array( 'unpaid', 'invoice-sent' ), true ) ) {
			return array( 'result' => 'skip', 'reason' => __( 'already paid', 'equine-event-manager' ) );
		}
		if ( empty( $order['email'] ) ) {
			return array( 'result' => 'skip', 'reason' => __( 'no email', 'equine-event-manager' ) );
		}
		return array( 'result' => 'send' );
	}

	/**
	 * AJAX endpoint: bulk-refund single step (C6.C).
	 *
	 * Processes ONE order from a bulk-refund batch. The JS layer
	 * (runBulkRefundQueue) calls this endpoint sequentially for each
	 * selected order; each call is fully independent so per-order
	 * failures don't halt the batch (option-3 batch-error-attribution
	 * per the C6.C kickoff Q2 decision).
	 *
	 * **Important contract — payload is order_key + reason ONLY, never
	 * an amount.** The endpoint computes the refund amount server-side
	 * via get_order_remaining_refundable() at call time. This is the
	 * retry-safety mechanism: when the user clicks "Retry failed"
	 * after a parallel admin action has changed the order's state, the
	 * retry call refunds the CURRENT remaining_refundable, never a
	 * stale amount from the original batch attempt.
	 *
	 * Returns wp_send_json_success with:
	 *   - order_key:       echo of the input
	 *   - refunded_amount: amount actually refunded (may be 0 if already
	 *                      fully refunded between batch start and this step)
	 *   - new_status_slug + new_status_label
	 *   - components:      per-component transaction IDs (empty when no-op)
	 *   - was_noop:        true when remaining_refundable was 0 at call time
	 *
	 * Or wp_send_json_error with:
	 *   - order_key:       echo of the input (always present even on error,
	 *                      so the JS queue can attribute the failure)
	 *   - code:            order_not_found / nonce / capability / gateway / persist_failed / etc.
	 *   - message:         human-readable error
	 *
	 * Nonce: action = 'eem_bulk_refund_step' (NOT per-order — the batch
	 * shares one nonce, granted on bulk-modal open).
	 *
	 * @return void  Always exits via wp_send_json_*.
	 */
	/**
	 * AJAX endpoint: Add Note from the C6.E.2 Order Detail activity log
	 * form. Validates cap + nonce + length + order_key existence, writes
	 * an EEM_Activity_Log entry with event_type 'ordernote' (per CLEANUP
	 * #31 flat-string convention — `sanitize_key('ordernote')` is a no-
	 * op), enriches via EEM_Order_Telemetry::enrich_entry_for_render,
	 * and returns a JSON response carrying server-rendered entry HTML
	 * (via the same C2 partial the live list uses) for the JS arm to
	 * prepend into `[data-eem-activity-list]`.
	 *
	 * Response shape on success:
	 *   { html, entry_id, new_count }
	 *
	 * Validation envelope (server is authoritative; client mirrors for
	 * UX but the server rejects bypassed clients):
	 *   - cap         → 403 capability
	 *   - nonce       → 400 nonce
	 *   - missing key → 400 invalid_request
	 *   - unknown key → 404 not_found
	 *   - empty note  → 400 empty
	 *   - too long    → 400 too_long  (>2000 chars after trim)
	 *
	 * Note on smoke-anchor placement: this method's docblock is included
	 * in the c6c slice for handle_ajax_refund_single (which ends at the
	 * next public-function declaration — i.e. this one). Keep the
	 * docblock free of the literal class::method string the c6c slice
	 * is grepping for; describe what the method does in prose instead.
	 *
	 * @return void  Always exits via wp_send_json_*.
	 */
	public function handle_ajax_order_add_note() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'capability', 'message' => __( 'You do not have permission to add notes to orders.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$nonce     = isset( $_POST['_eem_add_note_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_add_note_nonce'] ) ) : '';

		if ( '' === $order_key ) {
			wp_send_json_error( array( 'code' => 'invalid_request', 'message' => __( 'Missing order reference.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! wp_verify_nonce( $nonce, 'eem_order_add_note_' . $order_key ) ) {
			wp_send_json_error( array( 'code' => 'nonce', 'message' => __( 'Security check failed. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		// Verify the order_key actually resolves to an order before we write.
		$order = $this->orders_repository->get_order( $order_key );
		if ( ! is_array( $order ) ) {
			wp_send_json_error( array( 'code' => 'not_found', 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		$note_raw  = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '';
		$note      = trim( sanitize_textarea_field( $note_raw ) );

		if ( '' === $note ) {
			wp_send_json_error( array( 'code' => 'empty', 'message' => __( 'Note cannot be empty.', 'equine-event-manager' ) ), 400 );
		}
		if ( strlen( $note ) > 2000 ) {
			wp_send_json_error( array( 'code' => 'too_long', 'message' => __( 'Note exceeds the 2,000-character limit.', 'equine-event-manager' ) ), 400 );
		}

		// Resolve actor for attribution + write title (Q4-locked at
		// "Admin note by {actor_label}" w/ "Admin note" fallback).
		$current_user = wp_get_current_user();
		$actor_label  = ( $current_user && $current_user->exists() )
			? (string) $current_user->display_name
			: '';
		$title = '' !== $actor_label
			? sprintf(
				/* translators: %s: admin display name */
				__( 'Admin note by %s', 'equine-event-manager' ),
				$actor_label
			)
			: __( 'Admin note', 'equine-event-manager' );

		$reservation_id = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;

		$entry_id = EEM_Activity_Log::write(
			'ordernote',
			array(
				'order_key' => $order_key,
				'title'     => $title,
				'meta'      => $note,
				'note'      => $note,
			),
			array(
				'reservation_id' => $reservation_id ?: null,
				'actor_type'     => 'admin',
				'actor_id'       => $current_user ? (int) $current_user->ID : null,
				'actor_label'    => $actor_label,
			)
		);

		if ( ! $entry_id ) {
			wp_send_json_error( array( 'code' => 'write_failed', 'message' => __( 'Failed to save note. Please try again.', 'equine-event-manager' ) ), 500 );
		}

		// Read the row back through the canonical consumer query so the
		// server-rendered HTML matches exactly what the next page-load
		// would render (per CLAUDE.md round-trip discipline).
		$rows = EEM_Activity_Log::get_for_order_key( $order_key, 1 );
		$new_entry = isset( $rows[0] ) ? EEM_Order_Telemetry::enrich_entry_for_render( $rows[0] ) : null;

		$entry_html = '';
		if ( is_array( $new_entry ) && function_exists( 'eem_render_activity_log_entry' ) ) {
			ob_start();
			eem_render_activity_log_entry( $new_entry );
			$entry_html = (string) ob_get_clean();
		}

		// Authoritative new_count = full re-query length, so the badge
		// stays correct even if a parallel write landed.
		$new_count = count( EEM_Activity_Log::get_for_order_key( $order_key, 1000 ) );

		wp_send_json_success( array(
			'html'      => $entry_html,
			'entry_id'  => (int) $entry_id,
			'new_count' => $new_count,
		) );
	}

	public function handle_ajax_bulk_refund_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'capability', 'message' => __( 'You do not have permission to refund orders.', 'equine-event-manager' ) ), 403 );
		}

		$nonce = isset( $_POST['_eem_bulk_refund_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_bulk_refund_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'eem_bulk_refund_step' ) ) {
			wp_send_json_error( array( 'code' => 'nonce', 'message' => __( 'Security check failed. Please reload and try again.', 'equine-event-manager' ) ), 400 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$reason    = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( '' === $order_key ) {
			wp_send_json_error( array( 'order_key' => '', 'code' => 'invalid_payload', 'message' => __( 'Missing order_key.', 'equine-event-manager' ) ), 400 );
		}

		// Compute the refund amount server-side at call time. NEVER trust
		// a client-supplied amount in the bulk flow — that's the retry-
		// safety property documented in the method docblock above.
		$remaining = $this->get_order_remaining_refundable( $order_key );

		// No-op success: order has nothing left to refund. Common in
		// the "Retry failed" path when a parallel refund landed between
		// the batch attempt and the retry click.
		if ( $remaining <= 0.009 ) {
			wp_send_json_success( array(
				'order_key'        => $order_key,
				'refunded_amount'  => 0.0,
				'components'       => array(),
				'new_status_slug'  => 'refunded',
				'new_status_label' => __( 'Refunded', 'equine-event-manager' ),
				'was_noop'         => true,
			) );
		}

		$result = $this->process_amount_refund( $order_key, $remaining, $reason );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'order_key' => $order_key,
				'code'      => $result->get_error_code(),
				'message'   => $result->get_error_message(),
			), 400 );
		}

		wp_send_json_success( array(
			'order_key'        => $order_key,
			'refunded_amount'  => $result['refunded_amount'],
			'components'       => $result['components'],
			'new_status_slug'  => $result['new_status_slug'],
			'new_status_label' => $result['new_status_label'],
			'was_noop'         => false,
		) );
	}

	/**
	 * Handle refunding an order through its configured payment gateway.
	 */
	public function handle_order_refund() {
		$this->guard_admin_action();

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_refund_order_' . $order_key );

		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'equine-event-manager' ) );
		}

		$selected_lines = isset( $_POST['refund_lines'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['refund_lines'] ) ) : array();
		$selected_lines = array_values( array_unique( array_filter( $selected_lines ) ) );
		$refund_groups  = $this->build_refund_groups_for_order( $order );
		$error          = '';
		$refunded_any   = false;
		$group_totals    = array();
		$group_item_ids  = array();
		$group_index     = array();

		if ( empty( $selected_lines ) ) {
			$error = __( 'Select at least one product or charge line to refund.', 'equine-event-manager' );
		}

		foreach ( $refund_groups as $group ) {
			$signature = $group['component_signature'];

			$group_index[ $signature ]    = $group;
			$group_totals[ $signature ]   = 0.0;
			$group_item_ids[ $signature ] = array();

			foreach ( (array) $group['items'] as $item ) {
				if ( ! in_array( $item['id'], $selected_lines, true ) ) {
					continue;
				}

				$group_totals[ $signature ]  += (float) $item['amount'];
				$group_item_ids[ $signature ][] = $item['id'];
			}
		}

		if ( ! $error ) {
			foreach ( $group_totals as $signature => $refund_amount ) {
				if ( $refund_amount <= 0 || empty( $group_index[ $signature ]['component'] ) ) {
					continue;
				}

				$component     = $group_index[ $signature ]['component'];
				$refund_result = $this->refund_order_component( $component, $refund_amount );

				if ( is_wp_error( $refund_result ) ) {
					$error = $refund_result->get_error_message();
					break;
				}

				if ( ! $this->persist_component_refund( $component, $refund_amount, $refund_result, $group_item_ids[ $signature ] ) ) {
					$error = __( 'The refund was sent to the gateway, but the order record could not be updated afterward.', 'equine-event-manager' );
					break;
				}

				$refunded_any = true;
			}
		}

		if ( ! $error && ! $refunded_any ) {
			$error = __( 'There were no refundable line items selected on this order.', 'equine-event-manager' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'equine-event-manager-order',
					'order_key' => $order_key,
					'en_notice' => $error ? 'refund_failed' : 'refund_success',
					'en_error'  => $error ? $error : null,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render a printer-friendly order page.
	 */
	public function handle_order_print() {
		$this->guard_admin_action();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_print_order_' . $order_key );

		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'equine-event-manager' ) );
		}

		$company_logo_url    = $this->get_company_logo_url( 'medium' );
		$company_settings    = $this->get_company_settings();
		$special_requests    = $this->get_special_requests_from_order_notes( $order['notes'] );
		$billing_details     = $this->get_billing_details_from_order_notes( $order['notes'] );
		$group_rider_count   = self::parse_group_rider_count_from_notes( $order['notes'] );
		$group_rider_names   = self::parse_group_rider_names_from_notes( $order['notes'] );
		$event_label         = $order['reservation_title'] ? $order['reservation_title'] : $order['event_name'];
		$is_paid             = in_array( $order['status_slug'], array( 'paid', 'refunded' ), true );
		$payment_date        = '';
		if ( ! empty( $order['created_at'] ) ) {
			$payment_timestamp = strtotime( (string) $order['created_at'] );
			if ( false !== $payment_timestamp ) {
				$payment_date = wp_date( 'm-d-Y', $payment_timestamp );
			}
		}
		if ( '' === $payment_date && ! empty( $order['created_at'] ) ) {
			$payment_date = (string) $order['created_at'];
		}
		$support_phone       = trim( (string) $company_settings['support_phone'] );
		$support_email       = trim( (string) $company_settings['support_email'] );
		$stall_breakdown     = $this->get_order_stall_breakdown( $order );
		$rv_addon_breakdown  = $this->get_order_rv_addon_breakdown( $order );
		$rv_addon_total      = array_sum( wp_list_pluck( $rv_addon_breakdown, 'subtotal' ) );
		$rv_base_subtotal    = max( 0, (float) $order['rv_subtotal'] - (float) $rv_addon_total );
		$stall_nights        = $this->get_stay_nights_label( $order['stall_arrival_date'], $order['stall_departure_date'] );
		$rv_nights           = $this->get_stay_nights_label( $order['rv_arrival_date'], $order['rv_departure_date'] );
		$rv_addon_labels     = array();

		if ( ! empty( $rv_addon_breakdown ) ) {
			$rv_addon_labels = wp_list_pluck( $rv_addon_breakdown, 'label' );
		} elseif ( ! empty( $order['rv_type'] ) ) {
			$rv_addon_labels = array_filter( array_map( 'trim', explode( ',', $this->format_rv_type_label( $order['rv_type'] ) ) ) );
		}

		$footer_contacts         = array_filter(
			array(
				$support_phone ? sprintf( __( 'Support: %s', 'equine-event-manager' ), $support_phone ) : '',
				$support_email ? sprintf( __( 'Email: %s', 'equine-event-manager' ), $support_email ) : '',
			)
		);
		$reservation_data        = ! empty( $order['reservation_id'] ) ? $this->get_reservation_meta_values( absint( $order['reservation_id'] ) ) : array( 'general_addons' => array() );
		$general_addon_breakdown = $this->parse_general_addon_breakdown_from_notes( $order['notes'] );
		$group_charge_breakdown  = $this->parse_group_charge_breakdown_from_notes( $order['notes'] );
		$general_addon_prices    = array();
		$general_addon_units     = array();
		$line_items              = array();
		$summary_cards           = array();
		$format_money            = static function ( $amount ) {
			return '$' . number_format_i18n( (float) $amount, 2 );
		};

		foreach ( $reservation_data['general_addons'] as $addon ) {
			if ( empty( $addon['name'] ) ) {
				continue;
			}

			$addon_name = sanitize_text_field( $addon['name'] );

			$general_addon_prices[ $addon_name ] = isset( $addon['price'] ) ? (float) $addon['price'] : 0.0;
			$general_addon_units[ $addon_name ]  = isset( $addon['per_label'] ) ? sanitize_text_field( $addon['per_label'] ) : '';
		}

		$stall_stay_units = $this->get_billable_stay_units( $order['stall_arrival_date'], $order['stall_departure_date'], $order['stall_stay_type'] );
		$rv_stay_units    = $this->get_billable_stay_units( $order['rv_arrival_date'], $order['rv_departure_date'], $order['rv_stay_type'] );

		if ( absint( $order['stall_quantity'] ) > 0 ) {
			$summary_cards[] = array(
				'title' => __( 'Stall Reservation', 'equine-event-manager' ),
				'badge' => sprintf( _n( '%d reserved', '%d reserved', absint( $order['stall_quantity'] ), 'equine-event-manager' ), absint( $order['stall_quantity'] ) ),
				'rows'  => array(
					array( 'label' => __( 'Stay Type', 'equine-event-manager' ), 'value' => $this->format_stay_type_label( $order['stall_stay_type'] ) ),
					array( 'label' => __( 'Arrival', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['stall_arrival_date'] ) ),
					array( 'label' => __( 'Departure', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['stall_departure_date'] ) ),
					array( 'label' => __( 'Nights', 'equine-event-manager' ), 'value' => $stall_nights ),
					array( 'label' => __( 'Stalls', 'equine-event-manager' ), 'value' => (string) absint( $order['stall_quantity'] ) ),
					array( 'label' => __( 'Required Shavings', 'equine-event-manager' ), 'value' => (string) absint( $order['required_shavings_qty'] ) ),
					array( 'label' => __( 'Additional Shavings', 'equine-event-manager' ), 'value' => (string) absint( $order['additional_shavings_qty'] ) ),
				),
			);

			if ( (float) $stall_breakdown['base_subtotal'] > 0 ) {
				$stall_unit_divisor = max( 1, absint( $order['stall_quantity'] ) * max( 1, $stall_stay_units ) );
				$line_items[]       = array(
					'section'     => __( 'Stall Reservation', 'equine-event-manager' ),
					'description' => sprintf(
						/* translators: 1: stay type label, 2: arrival date, 3: departure date */
						__( '%1$s stay from %2$s to %3$s', 'equine-event-manager' ),
						$this->format_stay_type_label( $order['stall_stay_type'] ),
						$this->format_reservation_date_label( $order['stall_arrival_date'] ),
						$this->format_reservation_date_label( $order['stall_departure_date'] )
					),
					'qty'         => absint( $order['stall_quantity'] ),
					'units'       => $stall_nights,
					'rate'        => $format_money( (float) $stall_breakdown['base_subtotal'] / $stall_unit_divisor ),
					'total'       => $format_money( $stall_breakdown['base_subtotal'] ),
				);
			}

			if ( (float) $stall_breakdown['required_shavings_subtotal'] > 0 ) {
				$required_qty  = max( 1, absint( $order['required_shavings_qty'] ) );
				$line_items[] = array(
					'section'     => __( 'Stall Product', 'equine-event-manager' ),
					'description' => __( 'Required Shavings', 'equine-event-manager' ),
					'qty'         => absint( $order['required_shavings_qty'] ),
					'units'       => __( 'bags', 'equine-event-manager' ),
					'rate'        => $format_money( (float) $stall_breakdown['required_shavings_subtotal'] / $required_qty ),
					'total'       => $format_money( $stall_breakdown['required_shavings_subtotal'] ),
				);
			}

			if ( (float) $stall_breakdown['additional_shavings_subtotal'] > 0 ) {
				$additional_qty = max( 1, absint( $order['additional_shavings_qty'] ) );
				$line_items[]   = array(
					'section'     => __( 'Stall Product', 'equine-event-manager' ),
					'description' => __( 'Additional Shavings', 'equine-event-manager' ),
					'qty'         => absint( $order['additional_shavings_qty'] ),
					'units'       => __( 'bags', 'equine-event-manager' ),
					'rate'        => $format_money( (float) $stall_breakdown['additional_shavings_subtotal'] / $additional_qty ),
					'total'       => $format_money( $stall_breakdown['additional_shavings_subtotal'] ),
				);
			}
		}

		if ( absint( $order['rv_quantity'] ) > 0 ) {
			$summary_cards[] = array(
				'title' => __( 'RV Reservation', 'equine-event-manager' ),
				'badge' => sprintf( _n( '%d reserved', '%d reserved', absint( $order['rv_quantity'] ), 'equine-event-manager' ), absint( $order['rv_quantity'] ) ),
				'rows'  => array(
					array( 'label' => __( 'Stay Type', 'equine-event-manager' ), 'value' => $this->format_stay_type_label( $order['rv_stay_type'] ) ),
					array( 'label' => __( 'Arrival', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['rv_arrival_date'] ) ),
					array( 'label' => __( 'Departure', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['rv_departure_date'] ) ),
					array( 'label' => __( 'Nights', 'equine-event-manager' ), 'value' => $rv_nights ),
					array( 'label' => __( 'RV Spots', 'equine-event-manager' ), 'value' => (string) absint( $order['rv_quantity'] ) ),
					array( 'label' => __( 'Lot Selection', 'equine-event-manager' ), 'value' => $this->parse_rv_lot_name_from_notes( $order['notes'] ) ? $this->parse_rv_lot_name_from_notes( $order['notes'] ) : __( 'Not specified', 'equine-event-manager' ) ),
					array( 'label' => __( 'RV Add-Ons', 'equine-event-manager' ), 'value' => ! empty( $rv_addon_labels ) ? implode( ', ', $rv_addon_labels ) : __( 'None', 'equine-event-manager' ) ),
				),
			);

			if ( (float) $rv_base_subtotal > 0 ) {
				$rv_unit_divisor = max( 1, absint( $order['rv_quantity'] ) * max( 1, $rv_stay_units ) );
				$line_items[]    = array(
					'section'     => __( 'RV Reservation', 'equine-event-manager' ),
					'description' => sprintf(
						/* translators: 1: stay type label, 2: arrival date, 3: departure date */
						__( '%1$s stay from %2$s to %3$s', 'equine-event-manager' ),
						$this->format_stay_type_label( $order['rv_stay_type'] ),
						$this->format_reservation_date_label( $order['rv_arrival_date'] ),
						$this->format_reservation_date_label( $order['rv_departure_date'] )
					),
					'qty'         => absint( $order['rv_quantity'] ),
					'units'       => $rv_nights,
					'rate'        => $format_money( (float) $rv_base_subtotal / $rv_unit_divisor ),
					'total'       => $format_money( $rv_base_subtotal ),
				);
			}

			foreach ( $rv_addon_breakdown as $addon_row ) {
				if ( (float) $addon_row['subtotal'] <= 0 ) {
					continue;
				}

				$rv_addon_divisor = max( 1, absint( $order['rv_quantity'] ) * max( 1, $rv_stay_units ) );
				$line_items[]     = array(
					'section'     => __( 'RV Add-On', 'equine-event-manager' ),
					'description' => $addon_row['label'],
					'qty'         => absint( $order['rv_quantity'] ),
					'units'       => $rv_nights,
					'rate'        => $format_money( (float) $addon_row['subtotal'] / $rv_addon_divisor ),
					'total'       => $format_money( $addon_row['subtotal'] ),
				);
			}
		}

		foreach ( $general_addon_breakdown as $addon_row ) {
			$addon_qty     = max( 1, absint( $addon_row['quantity'] ) );
			$addon_rate    = isset( $general_addon_prices[ $addon_row['label'] ] ) && $general_addon_prices[ $addon_row['label'] ] > 0 ? (float) $general_addon_prices[ $addon_row['label'] ] : ( (float) $addon_row['subtotal'] / $addon_qty );
			$addon_unit    = ! empty( $addon_row['per_label'] ) ? $addon_row['per_label'] : ( isset( $general_addon_units[ $addon_row['label'] ] ) ? $general_addon_units[ $addon_row['label'] ] : '' );
			$line_items[]  = array(
				'section'     => __( 'General Add-On', 'equine-event-manager' ),
				'description' => $addon_row['label'],
				'qty'         => absint( $addon_row['quantity'] ),
				'units'       => '' !== $addon_unit ? $addon_unit : __( 'qty', 'equine-event-manager' ),
				'rate'        => $format_money( $addon_rate ),
				'total'       => $format_money( $addon_row['subtotal'] ),
			);
		}

		if ( $group_rider_count > 0 ) {
			$group_rows = array(
				array( 'label' => __( 'Riders in Group', 'equine-event-manager' ), 'value' => (string) $group_rider_count ),
				array( 'label' => __( 'Rider Names', 'equine-event-manager' ), 'value' => ! empty( $group_rider_names ) ? implode( ', ', $group_rider_names ) : __( 'Captured on order', 'equine-event-manager' ) ),
			);

			foreach ( $group_charge_breakdown as $group_charge ) {
				if ( (float) $group_charge['subtotal'] <= 0 ) {
					continue;
				}

				$group_rows[] = array(
					'label' => $group_charge['label'],
					'value' => sprintf(
						/* translators: 1: quantity, 2: subtotal. */
						__( '%1$d riders | %2$s', 'equine-event-manager' ),
						absint( $group_charge['quantity'] ),
						$format_money( $group_charge['subtotal'] )
					),
				);
			}

			$summary_cards[] = array(
				'title' => __( 'Group Reservation', 'equine-event-manager' ),
				'badge' => sprintf( _n( '%d rider', '%d riders', $group_rider_count, 'equine-event-manager' ), $group_rider_count ),
				'rows'  => $group_rows,
			);

			if ( ! empty( $group_charge_breakdown ) ) {
				foreach ( $group_charge_breakdown as $group_charge ) {
					$line_items[] = array(
						'section'     => __( 'Group Reservation', 'equine-event-manager' ),
						'description' => $group_charge['label'],
						'qty'         => absint( $group_charge['quantity'] ),
						'units'       => __( 'riders', 'equine-event-manager' ),
						'rate'        => $format_money( $group_charge['rate'] ),
						'total'       => $format_money( $group_charge['subtotal'] ),
					);
				}
			} else {
				$line_items[] = array(
					'section'     => __( 'Group Reservation', 'equine-event-manager' ),
					'description' => ! empty( $group_rider_names ) ? implode( ', ', $group_rider_names ) : __( 'Rider roster captured for this order.', 'equine-event-manager' ),
					'qty'         => $group_rider_count,
					'units'       => __( 'riders', 'equine-event-manager' ),
					'rate'        => __( 'Included', 'equine-event-manager' ),
					'total'       => __( 'Included', 'equine-event-manager' ),
				);
			}
		}
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<title><?php echo esc_html( sprintf( __( 'Order #%s', 'equine-event-manager' ), $order['order_number'] ) ); ?></title>
			<style>
				@page {
					size: auto;
					margin: 0.45in;
				}
				* { box-sizing: border-box; }
				body {
					margin: 0;
					background: #ffffff;
					color: #111827;
					font-family: "Helvetica Neue", Arial, sans-serif;
					font-size: 11px;
					line-height: 1.45;
					-webkit-print-color-adjust: exact;
					print-color-adjust: exact;
				}
				h1, h2, h3, p { margin: 0; }
				.equprint-sheet {
					max-width: 1000px;
					margin: 0 auto;
					padding: 0;
					background: #ffffff;
				}
				.equprint-header {
					display: grid;
					grid-template-columns: minmax(0, 1.55fr) minmax(250px, 0.8fr);
					gap: 18px;
					align-items: start;
					padding-bottom: 16px;
					border-bottom: 2px solid #d9e1ea;
				}
				.equprint-brand {
					display: grid;
					gap: 10px;
					align-items: flex-start;
					justify-items: start;
					min-width: 0;
				}
				.equprint-brand img {
					display: block;
					max-width: 190px;
					max-height: 72px;
					object-fit: contain;
				}
				.equprint-brand__fallback {
					font-size: 18px;
					font-weight: 800;
					letter-spacing: -0.03em;
				}
				.equprint-brand__meta {
					display: grid;
					gap: 4px;
					min-width: 0;
					justify-items: start;
					text-align: left;
				}
				.equprint-eyebrow {
					color: #5b6472;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.16em;
					text-transform: uppercase;
				}
				.equprint-brand__meta h1 {
					font-size: 22px;
					line-height: 1.1;
					letter-spacing: -0.02em;
				}
				.equprint-brand__meta p {
					color: #4b5563;
					font-size: 10px;
					font-weight: 600;
				}
				.equprint-meta-line {
					display: flex;
					flex-wrap: wrap;
					gap: 4px 10px;
					color: #4b5563;
					font-size: 10px;
				}
				.equprint-invoice-card {
					padding: 11px 13px;
					border: 1px solid #d9e1ea;
					border-radius: 12px;
					background: #eef2f6;
				}
				.equprint-meta-grid {
					display: grid;
					gap: 6px;
				}
				.equprint-meta-item {
					display: grid;
					gap: 2px;
				}
				.equprint-meta-item span {
					color: #5b6472;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.1em;
					text-transform: uppercase;
				}
				.equprint-meta-item strong,
				.equprint-meta-item div {
					font-size: 12px;
					font-weight: 700;
					word-break: break-word;
				}
				.equprint-divider {
					margin: 16px 0 14px;
					border: 0;
					border-top: 1px solid #d9e1ea;
				}
				.equprint-section {
					margin-top: 16px;
					page-break-inside: avoid;
				}
				.equprint-section--flush {
					margin-top: 0;
				}
				.equprint-section h2 {
					margin-bottom: 8px;
					font-size: 10px;
					font-weight: 800;
					letter-spacing: 0.14em;
					text-transform: uppercase;
					color: #111827;
				}
				.equprint-two-column {
					display: grid;
					grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
					gap: 14px;
				}
				.equprint-card-grid {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 12px;
				}
				.equprint-card {
					padding: 13px 14px;
					border: 1px solid #d9e1ea;
					border-radius: 12px;
					background: #eef2f6;
					page-break-inside: avoid;
				}
				.equprint-card__header {
					display: flex;
					justify-content: space-between;
					gap: 8px;
					align-items: flex-start;
					margin-bottom: 10px;
					padding-bottom: 8px;
					border-bottom: 1px solid #d9e1ea;
				}
				.equprint-card__header h3 {
					font-size: 13px;
					font-weight: 800;
					letter-spacing: -0.01em;
				}
				.equprint-badge {
					display: inline-flex;
					align-items: center;
					padding: 4px 8px;
					border-radius: 999px;
					background: #e5e7eb;
					color: #5b6472;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.08em;
					text-transform: uppercase;
					white-space: nowrap;
				}
				.equprint-card__rows {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 8px 12px;
				}
				.equprint-card__row {
					display: grid;
					gap: 2px;
				}
				.equprint-card__row span {
					color: #5b6472;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.08em;
					text-transform: uppercase;
				}
				.equprint-card__row strong {
					font-size: 11px;
					font-weight: 700;
					word-break: break-word;
				}
				.equprint-table-wrap {
					border: 1px solid #d9e1ea;
					border-radius: 12px;
					overflow: hidden;
					background: #eef2f6;
				}
				.equprint-table {
					width: 100%;
					border-collapse: collapse;
					table-layout: fixed;
				}
				.equprint-table thead th {
					padding: 9px 10px;
					border-bottom: 1px solid #d9e1ea;
					background: #e5e7eb;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.08em;
					text-align: left;
					text-transform: uppercase;
					color: #5b6472;
				}
				.equprint-table tbody td {
					padding: 10px;
					border-top: 1px solid #d9e1ea;
					vertical-align: top;
					word-wrap: break-word;
				}
				.equprint-table tbody tr:first-child td {
					border-top: 0;
				}
				.equprint-table tbody tr:nth-child(even) td {
					background: #eef2f6;
				}
				.equprint-table__section {
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.08em;
					text-transform: uppercase;
					color: #5b6472;
				}
				.equprint-table__description {
					font-size: 11px;
					font-weight: 700;
				}
				.equprint-table__numeric {
					text-align: right;
					white-space: nowrap;
				}
				.equprint-table__muted {
					color: #5b6472;
					font-size: 10px;
				}
				.equprint-note,
				.equprint-address {
					white-space: pre-line;
					font-size: 11px;
					line-height: 1.6;
				}
				.equprint-totals-card {
					padding: 12px 14px;
					border: 1px solid #d9e1ea;
					border-radius: 12px;
					background: #eef2f6;
				}
				.equprint-totals-table {
					width: 100%;
					border-collapse: collapse;
				}
				.equprint-totals-table td {
					padding: 7px 0;
					border-top: 1px solid #d9e1ea;
					font-size: 11px;
				}
				.equprint-totals-table tr:first-child td {
					border-top: 0;
				}
				.equprint-totals-table td:last-child {
					text-align: right;
					font-weight: 700;
				}
				.equprint-totals-table .equprint-total-row td {
					padding-top: 10px;
					font-size: 16px;
					font-weight: 800;
					color: #111827;
				}
				.equprint-footer {
					margin-top: 16px;
					padding-top: 12px;
					border-top: 1px solid #d9e1ea;
					color: #5b6472;
					font-size: 10px;
					text-align: center;
				}
				.equprint-footer__contacts + .equprint-footer__event {
					margin-top: 4px;
				}
				.equprint-table th:nth-child(1) { width: 19%; }
				.equprint-table th:nth-child(2) { width: 42%; }
				.equprint-table th:nth-child(3) { width: 8%; }
				.equprint-table th:nth-child(4) { width: 11%; }
				.equprint-table th:nth-child(5) { width: 10%; }
				.equprint-table th:nth-child(6) { width: 10%; }
				@media screen {
					body {
						padding: 20px;
						background: #f5f7fb;
					}
					.equprint-sheet {
						max-width: 980px;
						padding: 24px 26px 20px;
						box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
					}
				}
				@media print {
					body {
						background: #ffffff;
						-webkit-print-color-adjust: exact;
						print-color-adjust: exact;
					}
					.equprint-sheet {
						max-width: none;
						margin: 0;
						padding: 0;
						box-shadow: none;
					}
					.equprint-section,
					.equprint-card,
					.equprint-invoice-card,
					.equprint-totals-card,
					.equprint-table-wrap {
						page-break-inside: avoid;
					}
				}
			</style>
		</head>
		<body onload="window.print()">
			<div class="equprint-sheet">
				<header class="equprint-header">
					<div class="equprint-brand">
						<?php if ( $company_logo_url ) : ?>
							<img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" />
						<?php else : ?>
							<div class="equprint-brand__fallback"><?php esc_html_e( 'Equine Event Manager', 'equine-event-manager' ); ?></div>
						<?php endif; ?>
						<div class="equprint-brand__meta">
							<span class="equprint-eyebrow">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: receipt/invoice label, 2: order number */
										__( '%1$s #%2$s', 'equine-event-manager' ),
										$is_paid ? __( 'Order Receipt', 'equine-event-manager' ) : __( 'Reservation Invoice', 'equine-event-manager' ),
										$order['order_number']
									)
								);
								?>
							</span>
							<h1><?php echo esc_html( $event_label ); ?></h1>
							<p><?php echo esc_html( $order['event_dates'] ); ?></p>
							<div class="equprint-meta-line">
								<?php if ( $support_phone ) : ?>
									<span><?php echo esc_html( $support_phone ); ?></span>
								<?php endif; ?>
								<?php if ( $support_email ) : ?>
									<span><?php echo esc_html( $support_email ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="equprint-invoice-card">
						<div class="equprint-meta-grid">
							<div class="equprint-meta-item">
								<span><?php esc_html_e( 'Order Number', 'equine-event-manager' ); ?></span>
								<strong>#<?php echo esc_html( $order['order_number'] ); ?></strong>
							</div>
							<div class="equprint-meta-item">
								<span><?php esc_html_e( 'Payment Date', 'equine-event-manager' ); ?></span>
								<div><?php echo esc_html( $payment_date ); ?></div>
							</div>
							<div class="equprint-meta-item">
								<span><?php echo esc_html( $is_paid ? __( 'Amount Paid', 'equine-event-manager' ) : __( 'Amount Due', 'equine-event-manager' ) ); ?></span>
								<div><?php echo esc_html( $format_money( $order['total'] ) ); ?></div>
							</div>
						</div>
					</div>
				</header>

				<hr class="equprint-divider" />

				<div class="equprint-two-column">
					<section class="equprint-section equprint-section--flush">
						<h2><?php esc_html_e( 'Customer Details', 'equine-event-manager' ); ?></h2>
						<div class="equprint-invoice-card">
							<div class="equprint-meta-grid">
								<div class="equprint-meta-item">
									<span><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></span>
									<div><?php echo esc_html( $order['customer_name'] ); ?></div>
								</div>
								<div class="equprint-meta-item">
									<span><?php esc_html_e( 'Reservation Type', 'equine-event-manager' ); ?></span>
									<div><?php echo esc_html( $order['type'] ); ?></div>
								</div>
								<div class="equprint-meta-item">
									<span><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></span>
									<div><?php echo esc_html( $order['email'] ); ?></div>
								</div>
								<div class="equprint-meta-item">
									<span><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?></span>
									<div><?php echo esc_html( $this->format_phone_label( $order['phone'] ) ); ?></div>
								</div>
							</div>
						</div>
					</section>

					<section class="equprint-section equprint-section--flush">
						<h2><?php esc_html_e( 'Billing Details', 'equine-event-manager' ); ?></h2>
						<div class="equprint-invoice-card">
							<div class="equprint-address"><?php echo esc_html( $billing_details ); ?></div>
						</div>
					</section>
				</div>

				<?php if ( ! empty( $summary_cards ) ) : ?>
					<section class="equprint-section">
						<h2><?php esc_html_e( 'Reservation Summary', 'equine-event-manager' ); ?></h2>
						<div class="equprint-card-grid">
							<?php foreach ( $summary_cards as $summary_card ) : ?>
								<div class="equprint-card">
									<div class="equprint-card__header">
										<h3><?php echo esc_html( $summary_card['title'] ); ?></h3>
										<?php if ( ! empty( $summary_card['badge'] ) ) : ?>
											<span class="equprint-badge"><?php echo esc_html( $summary_card['badge'] ); ?></span>
										<?php endif; ?>
									</div>
									<div class="equprint-card__rows">
										<?php foreach ( $summary_card['rows'] as $row ) : ?>
											<div class="equprint-card__row">
												<span><?php echo esc_html( $row['label'] ); ?></span>
												<strong><?php echo esc_html( $row['value'] ); ?></strong>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<section class="equprint-section">
					<h2><?php esc_html_e( 'Purchased Items', 'equine-event-manager' ); ?></h2>
					<div class="equprint-table-wrap">
						<table class="equprint-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Section', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></th>
									<th class="equprint-table__numeric"><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></th>
									<th class="equprint-table__numeric"><?php esc_html_e( 'Units', 'equine-event-manager' ); ?></th>
									<th class="equprint-table__numeric"><?php esc_html_e( 'Rate', 'equine-event-manager' ); ?></th>
									<th class="equprint-table__numeric"><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $line_items as $line_item ) : ?>
									<tr>
										<td><span class="equprint-table__section"><?php echo esc_html( $line_item['section'] ); ?></span></td>
										<td><span class="equprint-table__description"><?php echo esc_html( $line_item['description'] ); ?></span></td>
										<td class="equprint-table__numeric"><?php echo esc_html( (string) $line_item['qty'] ); ?></td>
										<td class="equprint-table__numeric"><span class="equprint-table__muted"><?php echo esc_html( (string) $line_item['units'] ); ?></span></td>
										<td class="equprint-table__numeric"><?php echo esc_html( (string) $line_item['rate'] ); ?></td>
										<td class="equprint-table__numeric"><?php echo esc_html( (string) $line_item['total'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>

				<div class="equprint-two-column">
					<?php if ( '' !== $special_requests ) : ?>
						<section class="equprint-section">
							<h2><?php esc_html_e( 'Special Requests', 'equine-event-manager' ); ?></h2>
							<div class="equprint-invoice-card">
								<div class="equprint-note"><?php echo esc_html( $special_requests ); ?></div>
							</div>
						</section>
					<?php else : ?>
						<div></div>
					<?php endif; ?>

					<section class="equprint-section">
						<h2><?php esc_html_e( 'Totals', 'equine-event-manager' ); ?></h2>
						<div class="equprint-totals-card">
							<table class="equprint-totals-table">
								<tbody>
									<?php if ( (float) $stall_breakdown['base_subtotal'] > 0 ) : ?>
										<tr><td><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $format_money( $stall_breakdown['base_subtotal'] ) ); ?></td></tr>
									<?php endif; ?>
									<?php if ( (float) $stall_breakdown['required_shavings_subtotal'] > 0 ) : ?>
										<tr><td><?php esc_html_e( 'Required Shavings', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $format_money( $stall_breakdown['required_shavings_subtotal'] ) ); ?></td></tr>
									<?php endif; ?>
									<?php if ( (float) $stall_breakdown['additional_shavings_subtotal'] > 0 ) : ?>
										<tr><td><?php esc_html_e( 'Additional Shavings', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $format_money( $stall_breakdown['additional_shavings_subtotal'] ) ); ?></td></tr>
									<?php endif; ?>
									<?php if ( (float) $rv_base_subtotal > 0 ) : ?>
										<tr><td><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $format_money( $rv_base_subtotal ) ); ?></td></tr>
									<?php endif; ?>
									<?php foreach ( $rv_addon_breakdown as $addon_row ) : ?>
										<?php if ( (float) $addon_row['subtotal'] <= 0 ) { continue; } ?>
										<tr><td><?php echo esc_html( $addon_row['label'] ); ?></td><td><?php echo esc_html( $format_money( $addon_row['subtotal'] ) ); ?></td></tr>
									<?php endforeach; ?>
									<?php foreach ( $general_addon_breakdown as $addon_row ) : ?>
										<tr><td><?php echo esc_html( $addon_row['label'] ); ?></td><td><?php echo esc_html( $format_money( $addon_row['subtotal'] ) ); ?></td></tr>
									<?php endforeach; ?>
									<?php if ( (float) $order['fees'] > 0 ) : ?>
										<tr><td><?php esc_html_e( 'Non-Refundable Convenience Fee', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $format_money( $order['fees'] ) ); ?></td></tr>
									<?php endif; ?>
									<tr class="equprint-total-row"><td><?php echo esc_html( $is_paid ? __( 'Total Amount Paid', 'equine-event-manager' ) : __( 'Total Amount Due', 'equine-event-manager' ) ); ?></td><td><?php echo esc_html( $format_money( $order['total'] ) ); ?></td></tr>
								</tbody>
							</table>
						</div>
					</section>
				</div>

				<?php if ( ! empty( $footer_contacts ) || '' !== trim( $event_label ) ) : ?>
					<div class="equprint-footer">
						<?php if ( ! empty( $footer_contacts ) ) : ?>
							<div class="equprint-footer__contacts"><?php echo esc_html( implode( '  |  ', $footer_contacts ) ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== trim( $event_label ) ) : ?>
							<div class="equprint-footer__event"><?php echo esc_html( $event_label ); ?></div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Send a hosted invoice payment link email for an unpaid order.
	 *
	 * @return void
	 */
	public function handle_send_invoice_email() {
		$this->guard_admin_action();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_send_invoice_email_' . $order_key );

		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order || empty( $order['email'] ) ) {
			$this->redirect_to_order_notice( $order_key, 'invoice_email_failed', __( 'A customer email address is required before sending an invoice.', 'equine-event-manager' ) );
		}

		$sent = $this->send_invoice_email_for_order( $order );

		if ( is_wp_error( $sent ) ) {
			$this->redirect_to_order_notice( $order_key, 'invoice_email_failed', $sent->get_error_message() );
		}

		$this->redirect_to_order_notice( $order_key, 'invoice_email_sent' );
	}

	/**
	 * Resend the customer-facing order notification email for an order.
	 *
	 * @return void
	 */
	public function handle_resend_customer_notification() {
		$this->guard_admin_action();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		check_admin_referer( 'equine_event_manager_resend_customer_notification_' . $order_key );

		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			$this->redirect_to_order_notice( $order_key, 'customer_notification_failed', __( 'Order not found.', 'equine-event-manager' ) );
		}

		$shortcodes = new EEM_Shortcodes();
		$sent       = $shortcodes->send_customer_notification_email_for_order( $order );

		if ( is_wp_error( $sent ) ) {
			$this->redirect_to_order_notice( $order_key, 'customer_notification_failed', $sent->get_error_message() );
		}

		$resent_at = current_time( 'mysql' );

		foreach ( $order['components'] as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';
			$notes = $this->upsert_order_note_line( $notes, 'Customer Notification Resent At', $resent_at );

			$this->orders_repository->update_component_fields(
				$component['table'],
				$component['id'],
				array(
					'notes' => $notes,
				)
			);
		}

		$this->redirect_to_order_notice( $order_key, 'customer_notification_sent' );
	}

	/**
	 * Generate stall and RV assignments for all orders on a reservation.
	 *
	 * @return void
	 */
	public function handle_generate_stall_assignments() {
		$this->guard_admin_action();

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$return_page    = isset( $_GET['return_page'] ) ? sanitize_key( wp_unslash( $_GET['return_page'] ) ) : '';
		check_admin_referer( 'equine_event_manager_generate_stall_assignments_' . $reservation_id );

		$reservation = $reservation_id ? get_post( $reservation_id ) : null;
		$config      = $reservation_id ? $this->get_stall_chart_config( $reservation_id ) : array();

		if ( ! $reservation instanceof WP_Post || 'en_reservation' !== $reservation->post_type ) {
			$this->redirect_to_stall_chart_notice( 0, 'stall_assignments_generation_failed', __( 'That reservation could not be loaded.', 'equine-event-manager' ) );
		}

		if ( empty( $config['enabled'] ) && empty( $config['rv_lot_names'] ) ) {
			$this->redirect_to_reservation_notice_destination( $reservation_id, 'stall_assignments_generation_failed', __( 'Turn on Stall Assignments or configure RV lots before generating assignments.', 'equine-event-manager' ), $return_page );
		}

		$orders = array_filter(
			$this->orders_repository->get_orders( '', 'date', 'asc' ),
			function ( $order ) use ( $reservation_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === $reservation_id;
			}
		);

		if ( empty( $orders ) ) {
			$this->redirect_to_reservation_notice_destination( $reservation_id, 'stall_assignments_generation_failed', __( 'There are no orders on this reservation to assign yet.', 'equine-event-manager' ), $return_page );
		}

		$stall_map   = array();
		$rv_map      = array();
		$updated_any = false;

		// Serialize the whole allocate-then-write sweep behind the per-reservation
		// lock so it can't race a checkout or another admin assign mid-loop.
		$this->acquire_assignment_lock( $reservation_id );
		try {
		foreach ( $orders as $order ) {
			$stall_dates  = $this->get_stall_chart_occupied_dates( $order['stall_arrival_date'], $order['stall_departure_date'] );
			$rv_dates     = $this->get_stall_chart_occupied_dates( $order['rv_arrival_date'], $order['rv_departure_date'] );
			$stall_needed = absint( $order['stall_quantity'] );
			$rv_needed    = $this->order_requires_rv_assignment( $order ) ? absint( $order['rv_quantity'] ) : 0;
			$stall_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' ) );
			$rv_manual    = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) );
			$rv_preferred = $this->get_order_component_note_value( $order, 'rv', 'RV Lot' );

			if ( empty( $rv_manual ) ) {
				$rv_manual = $this->parse_assigned_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' ) );
			}

			$stall_units = $this->allocate_stall_chart_units( $config['available_stall_units'], $stall_map, $stall_dates, $stall_needed, $stall_manual, $order['order_key'] );
			$rv_units    = $this->allocate_rv_lot_rows( $config['rv_lot_names'], isset( $config['auto_assign_rv_lot_names'] ) ? $config['auto_assign_rv_lot_names'] : $config['available_rv_lot_names'], $rv_map, $rv_dates, $rv_needed, $rv_preferred, $rv_manual, $order['order_key'] );

			$updated_any = $this->orders_repository->update_order_unit_assignments(
				$order['order_key'],
				implode( ', ', $stall_units['assigned'] ),
				implode( ', ', $rv_units['assigned'] )
			) || $updated_any;
		}
		} finally {
			$this->release_assignment_lock( $reservation_id );
		}

		$this->redirect_to_reservation_notice_destination(
			$reservation_id,
			$updated_any ? 'stall_assignments_generated' : 'stall_assignments_generation_failed',
			$updated_any ? null : __( 'Assignments were generated, but no order records needed updating.', 'equine-event-manager' ),
			$return_page
		);
	}

	/**
	 * Determine whether an order truly requires an RV lot assignment.
	 *
	 * This avoids false-positive RV assignment issues from stray or legacy RV-side
	 * rows that do not represent an actual RV reservation purchase.
	 *
	 * @param array $order Order payload.
	 * @return bool
	 */
	private function order_requires_rv_assignment( $order ) {
		$rv_quantity = absint( isset( $order['rv_quantity'] ) ? $order['rv_quantity'] : 0 );

		if ( $rv_quantity < 1 ) {
			return false;
		}

		if ( ! empty( $order['rv_arrival_date'] ) || ! empty( $order['rv_departure_date'] ) ) {
			return true;
		}

		if ( ! empty( $order['rv_subtotal'] ) && (float) $order['rv_subtotal'] > 0 ) {
			return true;
		}

		$preferred_rv_lot = $this->get_order_component_note_value( $order, 'rv', 'RV Lot' );

		if ( '' !== $preferred_rv_lot ) {
			return true;
		}

		$assigned_rv_lots = $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' );

		if ( '' === $assigned_rv_lots ) {
			$assigned_rv_lots = $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' );
		}

		return '' !== $assigned_rv_lots;
	}

	/**
	 * Mark an unpaid order as manually paid for cash or in-person collection.
	 *
	 * @return void
	 */
	public function handle_mark_order_paid() {
		$this->guard_admin_action();

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$method    = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : '';
		check_admin_referer( 'equine_event_manager_mark_order_paid_' . $order_key );

		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			$this->redirect_to_order_notice( $order_key, 'manual_payment_failed', __( 'Order not found.', 'equine-event-manager' ) );
		}

		if ( ! in_array( $order['status_slug'], array( 'unpaid', 'invoice-sent' ), true ) ) {
			$this->redirect_to_order_notice( $order_key, 'manual_payment_failed', __( 'Only unpaid orders can be marked paid manually.', 'equine-event-manager' ) );
		}

		$method_map = array(
			'cash'  => __( 'Cash', 'equine-event-manager' ),
			'check' => __( 'Check', 'equine-event-manager' ),
		);

		if ( ! isset( $method_map[ $method ] ) ) {
			$this->redirect_to_order_notice( $order_key, 'manual_payment_failed', __( 'Please choose either Cash or Check when marking an order paid.', 'equine-event-manager' ) );
		}

		$updated = $this->orders_repository->mark_order_paid_manually( $order_key, $method_map[ $method ] );

		if ( ! $updated ) {
			$this->redirect_to_order_notice( $order_key, 'manual_payment_failed', __( 'The order could not be marked paid.', 'equine-event-manager' ) );
		}

		$this->redirect_to_order_notice( $order_key, 'manual_payment_recorded' );
	}

	/**
	 * Send a hosted invoice payment link email for an unpaid order payload.
	 *
	 * @param array $order Grouped order payload.
	 * @return true|WP_Error
	 */
	public function send_invoice_email_for_order( $order ) {
		if ( empty( $order ) || empty( $order['email'] ) ) {
			return new WP_Error( 'invoice_email_missing_email', __( 'A customer email address is required before sending an invoice.', 'equine-event-manager' ) );
		}

		if ( ! in_array( $order['status_slug'], array( 'unpaid', 'invoice-sent' ), true ) ) {
			return new WP_Error( 'invoice_email_invalid_status', __( 'Only unpaid orders can receive a payment-link invoice.', 'equine-event-manager' ) );
		}

		$invoice_token     = wp_generate_password( 32, false, false );
		$sent_at           = current_time( 'mysql' );
		$receipt_settings  = $this->get_receipt_settings();
		$headers           = array( 'Content-Type: text/html; charset=UTF-8' );
		$reservation_label = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];

		if ( ! empty( $receipt_settings['from_name'] ) && is_email( $receipt_settings['from_email'] ) ) {
			$headers[] = 'From: ' . wp_specialchars_decode( $receipt_settings['from_name'], ENT_QUOTES ) . ' <' . $receipt_settings['from_email'] . '>';
		}

		if ( is_email( $receipt_settings['reply_to_email'] ) ) {
			$headers[] = 'Reply-To: ' . $receipt_settings['reply_to_email'];
		}

		$subject = sprintf(
			/* translators: %s: event or reservation title. */
			__( 'Payment link for %s', 'equine-event-manager' ),
			$reservation_label
		);

		$sent = EEM_Mailer::send_html_email(
			sanitize_email( $order['email'] ),
			$subject,
			$this->build_invoice_email_html( $order, $invoice_token ),
			$headers,
			// C6.D telemetry context — surfaces this send in the order's
			// activity log as type=invoice.
			array(
				'type'      => 'invoice',
				'order_key' => isset( $order['order_key'] ) ? (string) $order['order_key'] : '',
				'event_label' => isset( $order['event_label'] ) ? (string) $order['event_label'] : '',
			)
		);

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		foreach ( $order['components'] as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';
			$notes = $this->upsert_order_note_line( $notes, 'Invoice Token', $invoice_token );
			$notes = $this->upsert_order_note_line( $notes, 'Invoice Status', 'Sent' );
			$notes = $this->upsert_order_note_line( $notes, 'Invoice Sent At', $sent_at );

			$this->orders_repository->update_component_fields(
				$component['table'],
				$component['row_id'],
				array(
					'notes'          => $notes,
					'payment_status' => 'invoice_sent',
				),
				array( '%s', '%s' )
			);
		}

		return true;
	}

	/**
	 * Render a printer-friendly reservation overview page.
	 */
	public function handle_reservation_overview_print() {
		$this->guard_admin_action();

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		check_admin_referer( 'equine_event_manager_print_reservation_overview_' . $reservation_id );

		$reservation = $reservation_id ? get_post( $reservation_id ) : null;

		if ( ! $reservation instanceof WP_Post || 'en_reservation' !== $reservation->post_type ) {
			wp_die( esc_html__( 'Reservation overview not found.', 'equine-event-manager' ) );
		}

		$overview         = $this->get_reservation_overview_data( $reservation_id );
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$company_settings = $this->get_company_settings();
		$support_phone    = trim( (string) $company_settings['support_phone'] );
		$support_email    = trim( (string) $company_settings['support_email'] );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<title><?php echo esc_html( sprintf( __( '%s Overview', 'equine-event-manager' ), $overview['event_label'] ) ); ?></title>
			<style>
				* { box-sizing: border-box; }
				body {
					margin: 0;
					padding: 20px;
					background: #f4f6f8;
					color: #172033;
					font-family: Arial, sans-serif;
					font-size: 12px;
					line-height: 1.45;
				}
				h1, h2, h3, p { margin: 0; }
				.equprint-sheet {
					max-width: 980px;
					margin: 0 auto;
					padding: 20px 22px;
					border: 1px solid #e3e7ee;
					border-radius: 16px;
					background: #fff;
					box-shadow: 0 12px 32px rgba(23, 32, 51, 0.06);
				}
				.equprint-header {
					display: flex;
					justify-content: space-between;
					gap: 18px;
					align-items: flex-start;
					padding-bottom: 14px;
					border-bottom: 1px solid #e8edf3;
				}
				.equprint-brand {
					display: flex;
					gap: 14px;
					align-items: flex-start;
					min-width: 0;
				}
				.equprint-brand img {
					display: block;
					max-width: 120px;
					max-height: 40px;
					object-fit: contain;
				}
				.equprint-brand__fallback {
					font-size: 16px;
					font-weight: 800;
					letter-spacing: -0.03em;
				}
				.equprint-brand__meta {
					display: grid;
					gap: 4px;
				}
				.equprint-eyebrow {
					color: #6a7789;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.12em;
					text-transform: uppercase;
				}
				.equprint-brand__meta h1 {
					font-size: 22px;
					line-height: 1.15;
					letter-spacing: -0.02em;
				}
				.equprint-brand__meta p {
					color: #5d6b7d;
					font-size: 12px;
				}
				.equprint-meta {
					display: grid;
					gap: 6px;
					min-width: 180px;
					text-align: right;
				}
				.equprint-meta__item span {
					display: block;
					color: #6a7789;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.12em;
					text-transform: uppercase;
				}
				.equprint-meta__item strong {
					display: block;
					margin-top: 3px;
					font-size: 13px;
				}
				.equprint-metrics {
					display: grid;
					grid-template-columns: repeat(5, minmax(0, 1fr));
					gap: 12px;
					margin-top: 18px;
				}
				.equprint-metric {
					padding: 12px;
					border: 1px solid #e3e7ee;
					border-radius: 14px;
					background: #fbfdff;
				}
				.equprint-metric span {
					display: block;
					margin-bottom: 6px;
					color: #6a7789;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.1em;
					text-transform: uppercase;
				}
				.equprint-metric strong {
					display: block;
					font-size: 28px;
					line-height: 1.05;
				}
				.equprint-metric small {
					display: block;
					margin-top: 6px;
					color: #64748b;
					font-size: 11px;
				}
				.equprint-grid {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 20px;
					margin-top: 20px;
				}
				.equprint-card {
					padding: 18px;
					border: 1px solid #e3e7ee;
					border-radius: 16px;
					background: #fff;
					page-break-inside: avoid;
				}
				.equprint-card h2 {
					margin-bottom: 14px;
					font-size: 12px;
					font-weight: 800;
					letter-spacing: 0.1em;
					text-transform: uppercase;
				}
				.equprint-definition-columns {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 14px;
				}
				.equprint-definition-grid {
					display: grid;
					gap: 12px;
				}
				.equprint-definition-item {
					padding: 12px;
					border: 1px solid #e8edf3;
					border-radius: 12px;
					background: #fbfdff;
				}
				.equprint-definition-item span {
					display: block;
					margin-bottom: 6px;
					color: #6a7789;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.1em;
					text-transform: uppercase;
				}
				.equprint-definition-item strong {
					display: block;
					font-size: 14px;
				}
				.equprint-overview-cards {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
					gap: 14px;
				}
				.equprint-overview-card {
					padding: 14px;
					border: 1px solid #e3e7ee;
					border-radius: 14px;
					background: #fbfdff;
				}
				.equprint-overview-card__header {
					display: flex;
					align-items: flex-start;
					justify-content: space-between;
					gap: 12px;
					margin-bottom: 14px;
				}
				.equprint-overview-card__header h3 {
					margin-bottom: 4px;
					font-size: 14px;
				}
				.equprint-overview-card__header p {
					color: #64748b;
					font-size: 11px;
				}
				.equprint-pill {
					display: inline-flex;
					align-items: center;
					border-radius: 999px;
					padding: 6px 10px;
					background: #e6f6eb;
					color: #18794e;
					font-size: 11px;
					font-weight: 700;
					line-height: 1;
				}
				.equprint-overview-stats {
					display: grid;
					grid-template-columns: repeat(3, minmax(0, 1fr));
					gap: 10px;
				}
				.equprint-overview-stats span {
					display: block;
					margin-bottom: 4px;
					color: #6a7789;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.08em;
					text-transform: uppercase;
				}
				.equprint-overview-stats strong {
					display: block;
					font-size: 16px;
					line-height: 1.1;
				}
				.equprint-table {
					width: 100%;
					border-collapse: collapse;
				}
				.equprint-table th,
				.equprint-table td {
					padding: 10px 8px;
					border-bottom: 1px solid #e8edf3;
					text-align: left;
					vertical-align: top;
				}
				.equprint-table th {
					color: #6a7789;
					font-size: 9px;
					font-weight: 800;
					letter-spacing: 0.08em;
					text-transform: uppercase;
				}
				.equprint-table td {
					font-size: 12px;
				}
				.equprint-table td strong {
					font-size: 13px;
				}
				@media print {
					body {
						padding: 0;
						background: #fff;
					}
					.equprint-sheet {
						max-width: none;
						border: 0;
						border-radius: 0;
						box-shadow: none;
					}
				}
			</style>
		</head>
		<body onload="window.print()">
			<div class="equprint-sheet">
				<div class="equprint-header">
					<div class="equprint-brand">
						<?php if ( $company_logo_url ) : ?>
							<img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Equine Event Manager logo', 'equine-event-manager' ); ?>" />
						<?php else : ?>
							<div class="equprint-brand__fallback"><?php esc_html_e( 'Equine Event Manager', 'equine-event-manager' ); ?></div>
						<?php endif; ?>
						<div class="equprint-brand__meta">
							<span class="equprint-eyebrow"><?php esc_html_e( 'Equine Event Manager', 'equine-event-manager' ); ?></span>
							<h1><?php echo esc_html( $overview['event_label'] ); ?></h1>
							<p><?php esc_html_e( 'Reservation Overview', 'equine-event-manager' ); ?></p>
						</div>
					</div>
					<div class="equprint-meta">
						<div class="equprint-meta__item">
							<span><?php esc_html_e( 'Printed', 'equine-event-manager' ); ?></span>
							<strong><?php echo esc_html( wp_date( 'F j, Y g:i a' ) ); ?></strong>
						</div>
						<?php if ( $support_phone || $support_email ) : ?>
							<div class="equprint-meta__item">
								<span><?php esc_html_e( 'Contact', 'equine-event-manager' ); ?></span>
								<strong><?php echo esc_html( trim( implode( ' | ', array_filter( array( $support_phone, $support_email ) ) ) ) ); ?></strong>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="equprint-metrics">
					<div class="equprint-metric">
						<span><?php esc_html_e( 'Orders', 'equine-event-manager' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $overview['order_count'] ) ); ?></strong>
					</div>
					<div class="equprint-metric">
						<span><?php esc_html_e( 'Revenue', 'equine-event-manager' ); ?></span>
						<strong><?php echo esc_html( '$' . number_format_i18n( (float) $overview['revenue_total'], 2 ) ); ?></strong>
					</div>
					<div class="equprint-metric">
						<span><?php esc_html_e( 'Stalls Sold', 'equine-event-manager' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $overview['stall_sold'] ) ); ?></strong>
						<small><?php echo esc_html( $overview['stall_remaining_label'] ); ?></small>
					</div>
					<div class="equprint-metric">
						<span><?php esc_html_e( 'RVs Sold', 'equine-event-manager' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $overview['rv_sold'] ) ); ?></strong>
						<small><?php echo esc_html( $overview['rv_remaining_label'] ); ?></small>
					</div>
					<div class="equprint-metric">
						<span><?php esc_html_e( 'Group Riders', 'equine-event-manager' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $overview['group_reservation_count'] ) ); ?></strong>
						<small>
							<?php
							echo esc_html(
								sprintf(
									_n( '%d group reservation', '%d group reservations', $overview['group_reservation_count'], 'equine-event-manager' ),
									$overview['group_reservation_count']
								)
							);
							?>
						</small>
					</div>
				</div>

				<div class="equprint-grid">
					<div class="equprint-card">
						<h2><?php esc_html_e( 'Event Snapshot', 'equine-event-manager' ); ?></h2>
						<div class="equprint-definition-columns">
							<div class="equprint-definition-grid">
								<div class="equprint-definition-item">
									<span><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></span>
									<strong><?php echo esc_html( get_the_title( $reservation_id ) ); ?></strong>
								</div>
								<div class="equprint-definition-item">
									<span><?php esc_html_e( 'Event Source', 'equine-event-manager' ); ?></span>
									<strong><?php echo esc_html( $overview['event_source_label'] ); ?></strong>
								</div>
								<div class="equprint-definition-item">
									<span><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></span>
									<strong><?php echo esc_html( $overview['event_dates_label'] ); ?></strong>
								</div>
							</div>
							<div class="equprint-definition-grid">
								<div class="equprint-definition-item">
									<span><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></span>
									<strong><?php echo esc_html( $overview['type_label'] ); ?></strong>
								</div>
								<div class="equprint-definition-item">
									<span><?php esc_html_e( 'Stall Inventory', 'equine-event-manager' ); ?></span>
									<strong><?php echo esc_html( $overview['stall_inventory_label'] ); ?></strong>
								</div>
								<div class="equprint-definition-item">
									<span><?php esc_html_e( 'RV Inventory', 'equine-event-manager' ); ?></span>
									<strong><?php echo esc_html( $overview['rv_inventory_label'] ); ?></strong>
								</div>
							</div>
						</div>
					</div>

					<div class="equprint-card">
						<h2><?php esc_html_e( 'Inventory Overview', 'equine-event-manager' ); ?></h2>
						<div class="equprint-overview-cards">
							<?php foreach ( $overview['inventory_rows'] as $row ) : ?>
								<div class="equprint-overview-card">
									<div class="equprint-overview-card__header">
										<div>
											<h3><?php echo esc_html( $row['label'] ); ?></h3>
										</div>
										<span class="equprint-pill"><?php echo esc_html( $row['status_label'] ); ?></span>
									</div>
									<div class="equprint-overview-stats">
										<div>
											<span><?php esc_html_e( 'Inventory', 'equine-event-manager' ); ?></span>
											<strong><?php echo esc_html( $row['inventory_label'] ); ?></strong>
										</div>
										<div>
											<span><?php esc_html_e( 'Sold', 'equine-event-manager' ); ?></span>
											<strong><?php echo esc_html( number_format_i18n( $row['sold'] ) ); ?></strong>
										</div>
										<div>
											<span><?php esc_html_e( 'Remaining', 'equine-event-manager' ); ?></span>
											<strong><?php echo esc_html( $row['remaining_label'] ); ?></strong>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="equprint-grid">
					<div class="equprint-card">
						<h2><?php esc_html_e( 'Product Activity', 'equine-event-manager' ); ?></h2>
						<table class="equprint-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Category', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Sold', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $overview['product_rows'] ) ) : ?>
									<tr>
										<td colspan="3"><?php esc_html_e( 'No products have been sold for this event yet.', 'equine-event-manager' ); ?></td>
									</tr>
								<?php else : ?>
									<?php foreach ( $overview['product_rows'] as $row ) : ?>
										<tr>
											<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
											<td><?php echo esc_html( $row['category'] ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $row['sold'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="equprint-card">
						<h2><?php esc_html_e( 'Recent Orders', 'equine-event-manager' ); ?></h2>
						<table class="equprint-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $overview['orders'] ) ) : ?>
									<tr>
										<td colspan="4"><?php esc_html_e( 'No orders have been placed for this event yet.', 'equine-event-manager' ); ?></td>
									</tr>
								<?php else : ?>
									<?php foreach ( array_slice( $overview['orders'], 0, 12 ) as $order ) : ?>
										<tr>
											<td><strong>#<?php echo esc_html( $order['order_number'] ); ?></strong></td>
											<td><?php echo esc_html( $order['customer_name'] ); ?></td>
											<td><?php echo wp_kses_post( $this->render_order_type_badges( $order['type'] ) ); ?></td>
											<td><?php echo esc_html( wp_date( 'F j, Y', strtotime( $order['created_at'] ) ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	// C5.5: removed dead render_orders_table() + render_orders_pagination() + get_orders_sort_link() — verified zero live callers (only the dead render_orders_page chain referenced them).

	/**
	 * Refund a row through the selected payment gateway.
	 *
	 * @param array $component Order component.
	 * @param float $amount Amount to refund.
	 * @return string|WP_Error Refund transaction/reference ID.
	 */
	private function refund_order_component( $component, $amount = 0.0 ) {
		return $this->refund_engine()->refund_order_component( $component, $amount );
	}

	/**
	 * Refund a Stripe transaction.
	 *
	 * @param array $component Order component.
	 * @param float $amount Amount to refund.
	 * @return string|WP_Error
	 */
	private function refund_with_stripe( $component, $amount = 0.0 ) {
		return $this->refund_engine()->refund_with_stripe( $component, $amount );
	}

	/**
	 * Attempt an Authorize.net void/refund.
	 *
	 * @param array $component Order component.
	 * @param float $amount Amount to refund.
	 * @return string|WP_Error
	 */
	private function refund_with_authorize_net( $component, $amount = 0.0 ) {
		return $this->refund_engine()->refund_with_authorize_net( $component, $amount );
	}

	/**
	 * Render report export history.
	 *
	 * @param array $logs Logged exports.
	 */
	private function render_report_logs_table( $logs ) {
		?>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Exported At', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'File Name', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Exported By', 'equine-event-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No reports exported yet.', 'equine-event-manager' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Exported At', 'equine-event-manager' ); ?>"><?php echo esc_html( $log['created_at'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Reservation', 'equine-event-manager' ); ?>"><?php echo esc_html( $log['reservation_name'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Scope', 'equine-event-manager' ); ?>"><?php echo esc_html( 'all' === $log['export_scope'] ? __( 'All reservations', 'equine-event-manager' ) : __( 'Single reservation', 'equine-event-manager' ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'File Name', 'equine-event-manager' ); ?>"><?php echo esc_html( $log['file_name'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Exported By', 'equine-event-manager' ); ?>"><?php echo esc_html( $log['exported_by_name'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the branded page banner.
	 *
	 * @param string $title Banner title.
	 * @param string $description Optional supporting text.
	 */
	private function render_brand_banner( $title, $description = '' ) {
		$logo_url = EQUINE_EVENT_MANAGER_URL . 'assets/images/logo.png';
		?>
		<header class="eem-shell-header">
			<div class="eem-shell-header__inner">
				<div class="eem-shell-header__brand">
					<img class="eem-shell-header__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Equine Event Manager', 'equine-event-manager' ); ?>" width="150" style="display:block;width:150px;max-width:150px;height:auto;flex:0 0 auto;">
					<div class="eem-shell-header__copy">
						<h1 class="eem-shell-header__title"><?php echo esc_html( $title ); ?></h1>
					</div>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Render a simple key/value definition grid.
	 *
	 * @param array $items Items to render.
	 */
	private function render_key_value_grid( $items ) {
		?>
		<table class="widefat striped" role="presentation">
			<tbody>
			<?php foreach ( $items as $label => $value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td>
						<?php
						if ( is_array( $value ) && ! empty( $value['html'] ) ) {
							echo wp_kses(
								$value['html'],
								array(
									'a' => array(
										'href'   => true,
										'class'  => true,
										'target' => true,
										'rel'    => true,
									),
									'button' => array(
										'type'             => true,
										'class'            => true,
										'data-eem-copy-url' => true,
									),
									'br' => array(),
									'div' => array(
										'class' => true,
									),
									'form' => array(
										'method' => true,
										'action' => true,
										'class'  => true,
									),
									'input' => array(
										'type'     => true,
										'readonly' => true,
										'value'    => true,
										'name'     => true,
										'placeholder' => true,
										'class'    => true,
										'id'       => true,
									),
									'label' => array(
										'class' => true,
									),
									'option' => array(
										'value'    => true,
										'selected' => true,
										'disabled' => true,
									),
									'select' => array(
										'name'     => true,
										'multiple' => true,
										'size'     => true,
										'class'    => true,
									),
									'span' => array(
										'class' => true,
									),
									'ul' => array(
										'class' => true,
									),
									'li' => array(
										'class' => true,
									),
									'p'  => array(
										'class' => true,
									),
									'strong' => array(
										'class' => true,
									),
								)
							);
						} else {
							echo esc_html( $value );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render grouped key/value rows for cleaner reservation layouts.
	 *
	 * @param array $rows Array of row item arrays.
	 */
	private function render_key_value_rows( $rows ) {
		?>
		<?php foreach ( $rows as $row_items ) : ?>
			<?php
			if ( empty( $row_items ) || ! is_array( $row_items ) ) {
				continue;
			}
			?>
			<?php $this->render_key_value_grid( $row_items ); ?>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Format a currency value for admin display.
	 *
	 * @param float|int|string $value Amount to format.
	 * @return string
	 */
	private function format_money( $value ) {
		return '$' . number_format_i18n( (float) $value, 2 );
	}

	/**
	 * Format a stored phone number for display.
	 *
	 * @param string $phone Raw phone value.
	 * @return string
	 */
	private function format_phone_label( $phone ) {
		$phone       = trim( (string) $phone );
		$digits_only = preg_replace( '/\D+/', '', $phone );

		if ( strlen( $digits_only ) >= 10 ) {
			$local_digits   = substr( $digits_only, -10 );
			$country_digits = substr( $digits_only, 0, -10 );

			if ( '' === $country_digits || preg_match( '/^1+$/', $country_digits ) ) {
				return sprintf(
					'(%1$s) %2$s-%3$s',
					substr( $local_digits, 0, 3 ),
					substr( $local_digits, 3, 3 ),
					substr( $local_digits, 6, 4 )
				);
			}
		}

		return $phone;
	}

	/**
	 * Render Shopify-style type badges for an order.
	 *
	 * @param string $type_label Comma-separated type label string.
	 * @return string
	 */
	private function render_order_type_badges( $type_label ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $type_label ) ) );

		if ( empty( $parts ) ) {
			return '';
		}

		$badges = array();
		$styles = array(
			'stall'   => 'background:#eaf1ff !important;border-color:#bad0ff !important;color:#2453a6 !important;',
			'rv'      => 'background:#ecf9ef !important;border-color:#bbe5c8 !important;color:#247548 !important;',
			'addon'   => 'background:#fff7dc !important;border-color:#efd58a !important;color:#9b6a12 !important;',
			'group'   => 'background:#f4ecff !important;border-color:#d7c2ff !important;color:#6c41b7 !important;',
			'default' => '',
		);

		foreach ( $parts as $part ) {
			$label = sanitize_text_field( $part );
			$key   = sanitize_title( $label );

			if ( false !== strpos( $key, 'add-on' ) || false !== strpos( $key, 'addon' ) ) {
				$key   = 'addon';
				$label = __( 'Add-On', 'equine-event-manager' );
			} elseif ( false !== strpos( $key, 'stall' ) ) {
				$key   = 'stall';
				$label = __( 'Stall', 'equine-event-manager' );
			} elseif ( 'rv' === $key || false !== strpos( $key, 'rv-' ) || false !== strpos( $key, '-rv' ) ) {
				$key   = 'rv';
				$label = __( 'RV', 'equine-event-manager' );
			} elseif ( false !== strpos( $key, 'group' ) ) {
				$key   = 'group';
				$label = __( 'Group', 'equine-event-manager' );
			} else {
				$key = 'default';
			}

			$badges[] = sprintf(
				'<span class="eem-shell-badge eem-shell-badge--%1$s"%3$s>%2$s</span>',
				esc_attr( $key ),
				esc_html( $label ),
				! empty( $styles[ $key ] ) ? ' style="' . esc_attr( $styles[ $key ] ) . '"' : ''
			);
		}

		return sprintf(
			'<span class="eem-shell-badges">%s</span>',
			implode( '', $badges )
		);
	}

	/**
	 * Render an app-style status badge for an order.
	 *
	 * @param string $status_label Readable status label.
	 * @param string $status_slug  Machine status slug.
	 * @return string
	 */
	private function render_order_status_badge( $status_label, $status_slug = '' ) {
		$label = sanitize_text_field( (string) $status_label );
		$slug  = sanitize_key( $status_slug ? $status_slug : sanitize_title( $label ) );

		if ( '' === $label ) {
			return '';
		}

		$variant = 'default';

		if ( in_array( $slug, array( 'paid', 'completed' ), true ) ) {
			$variant = 'paid';
		} elseif ( in_array( $slug, array( 'unpaid', 'failed' ), true ) ) {
			$variant = 'unpaid';
		} elseif ( in_array( $slug, array( 'invoice-sent', 'pending', 'outstanding-show-bill' ), true ) ) {
			$variant = 'pending';
		} elseif ( in_array( $slug, array( 'partially-refunded', 'refunded' ), true ) ) {
			$variant = 'refund';
		}

		$styles = array(
			'paid'    => 'background:#ecf9ef !important;border-color:#bbe5c8 !important;color:#247548 !important;',
			'pending' => 'background:#fff7dc !important;border-color:#efd58a !important;color:#9b6a12 !important;',
			'unpaid'  => 'background:#fff0f0 !important;border-color:#f2b6b6 !important;color:#b44040 !important;',
			'refund'  => 'background:#fff2e8 !important;border-color:#f0be91 !important;color:#b86a18 !important;',
		);

		return sprintf(
			'<span class="eem-shell-badge eem-shell-badge--status eem-shell-badge--%1$s"%3$s>%2$s</span>',
			esc_attr( $variant ),
			esc_html( $label ),
			! empty( $styles[ $variant ] ) ? ' style="' . esc_attr( $styles[ $variant ] ) . '"' : ''
		);
	}

	/**
	 * Format the number of nights between arrival and departure.
	 *
	 * @param string $arrival_date Arrival date.
	 * @param string $departure_date Departure date.
	 * @return string
	 */
	private function get_stay_nights_label( $arrival_date, $departure_date ) {
		$arrival_timestamp   = strtotime( (string) $arrival_date );
		$departure_timestamp = strtotime( (string) $departure_date );

		if ( ! $arrival_timestamp || ! $departure_timestamp ) {
			return '1';
		}

		$nights = max( 1, (int) round( ( $departure_timestamp - $arrival_timestamp ) / DAY_IN_SECONDS ) );

		return sprintf(
			/* translators: %d: number of nights. */
			_n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ),
			$nights
		);
	}

	/**
	 * Get a clickable email definition value payload.
	 *
	 * @param string $email Email address.
	 * @return array|string
	 */
	private function get_email_definition_value( $email ) {
		$email = sanitize_email( (string) $email );

		if ( '' === $email ) {
			return '';
		}

		return array(
			'html' => sprintf(
				'<a href="mailto:%1$s">%2$s</a>',
				esc_attr( $email ),
				esc_html( $email )
			),
		);
	}

	/**
	 * Get a clickable phone definition value payload.
	 *
	 * @param string $phone Raw phone number.
	 * @return array|string
	 */
	private function get_phone_definition_value( $phone ) {
		$phone       = trim( (string) $phone );
		$digits_only = preg_replace( '/\D+/', '', $phone );
		$formatted   = $this->format_phone_label( $phone );

		if ( '' === $formatted ) {
			return '';
		}

		if ( strlen( $digits_only ) >= 10 ) {
			$local_digits   = substr( $digits_only, -10 );
			$country_digits = substr( $digits_only, 0, -10 );
			$href_number    = preg_match( '/^1+$/', $country_digits ) || '' === $country_digits ? '1' . $local_digits : $digits_only;

			return array(
				'html' => sprintf(
					'<a href="tel:+%1$s">%2$s</a>',
					esc_attr( $href_number ),
					esc_html( $formatted )
				),
			);
		}

		return $formatted;
	}

	/**
	 * Get a readable transaction ID summary for the order.
	 *
	 * @param array $order Order payload.
	 * @return string
	 */
	private function get_transaction_id_summary( $order ) {
		if ( empty( $order['components'] ) || ! is_array( $order['components'] ) ) {
			return __( 'Pending', 'equine-event-manager' );
		}

		$transaction_ids = array();

		foreach ( $order['components'] as $component ) {
			if ( empty( $component['transaction_id'] ) ) {
				continue;
			}

			$transaction_ids[] = sanitize_text_field( $component['transaction_id'] );
		}

		$transaction_ids = array_values( array_unique( array_filter( $transaction_ids ) ) );

		if ( empty( $transaction_ids ) ) {
			return __( 'Pending', 'equine-event-manager' );
		}

		if ( 1 === count( $transaction_ids ) ) {
			return $transaction_ids[0];
		}

		return sprintf(
			/* translators: 1: first transaction ID, 2: number of additional transaction IDs. */
			__( '%1$s + %2$d more', 'equine-event-manager' ),
			$transaction_ids[0],
			count( $transaction_ids ) - 1
		);
	}

	/**
	 * Format a stay type key for display.
	 *
	 * @param string $stay_type Raw stay type value.
	 * @return string
	 */
	private function format_stay_type_label( $stay_type ) {
		$stay_type = sanitize_key( $stay_type );

		if ( 'weekend' === $stay_type ) {
			return __( 'Weekend', 'equine-event-manager' );
		}

		if ( 'nightly' === $stay_type ) {
			return __( 'Nightly', 'equine-event-manager' );
		}

		return $stay_type ? ucfirst( $stay_type ) : '';
	}

	/**
	 * Format an RV hookup type key for display.
	 *
	 * @param string $rv_type Raw RV type value.
	 * @return string
	 */
	private function format_rv_type_label( $rv_type ) {
		$labels = array(
			'electric_water' => __( 'Electrical & Water', 'equine-event-manager' ),
			'electric_only'  => __( 'Electrical Only', 'equine-event-manager' ),
			'water_only'     => __( 'Water Only', 'equine-event-manager' ),
			'electric'       => __( 'Electric', 'equine-event-manager' ),
			'water'          => __( 'Water', 'equine-event-manager' ),
			'sewage'         => __( 'Sewage', 'equine-event-manager' ),
		);

		$formatted_labels = array();
		$raw_parts        = preg_split( '/\s*,\s*/', trim( (string) $rv_type ) );

		foreach ( (array) $raw_parts as $raw_part ) {
			$normalized = sanitize_key( $raw_part );

			if ( isset( $labels[ $normalized ] ) ) {
				$formatted_labels[] = $labels[ $normalized ];
			}
		}

		if ( empty( $formatted_labels ) ) {
			$raw_value = sanitize_key( trim( (string) $rv_type ) );

			foreach ( $labels as $normalized => $label ) {
				if ( false !== strpos( $raw_value, $normalized ) ) {
					$formatted_labels[] = $label;
				}
			}
		}

		$formatted_labels = array_values( array_unique( array_filter( $formatted_labels ) ) );

		if ( empty( $formatted_labels ) ) {
			$raw_value = trim( (string) $rv_type );

			return $raw_value ? ucwords( str_replace( '_', ' ', $raw_value ) ) : '';
		}

		return implode( ', ', $formatted_labels );
	}

	/**
	 * Get an RV add-on definition payload for card rendering.
	 *
	 * @param string $rv_type Raw RV add-on value.
	 * @return array|string
	 */
	private function get_rv_addon_definition_value( $rv_type ) {
		$labels = array_filter( array_map( 'trim', explode( ',', $this->format_rv_type_label( $rv_type ) ) ) );

		if ( empty( $labels ) ) {
			return '';
		}

		if ( 1 === count( $labels ) ) {
			return $labels[0];
		}

		$list_items = '';

		foreach ( $labels as $label ) {
			$list_items .= '<li>' . esc_html( $label ) . '</li>';
		}

		return array(
			'html' => '<ul>' . $list_items . '</ul>',
		);
	}

	/**
	 * Format a reservation date for display.
	 *
	 * @param string $value Raw date value.
	 * @return string
	 */
	private function format_reservation_date_label( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$timezone = wp_timezone();
		$date     = date_create_immutable_from_format( '!Y-m-d', $value, $timezone );

		if ( $date ) {
			return wp_date( 'l, F j, Y', $date->getTimestamp(), $timezone );
		}

		$timestamp = strtotime( $value );

		return $timestamp ? wp_date( 'l, F j, Y', $timestamp, $timezone ) : $value;
	}

	/**
	 * Extract customer special requests from stored order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return string
	 */
	private function get_special_requests_from_order_notes( $notes ) {
		$lines = preg_split( "/\r\n|\r|\n/", trim( (string) $notes ) );

		if ( empty( $lines ) ) {
			return '';
		}

		$filtered            = array();
		$skipping_billing    = false;
		$metadata_line_regex = '/^(Reservation setup ID:|Submission token:|RV Add-Ons:|Tack Stalls:|Group Name:|Group Charge:|Group Reservation:|Group Riders Count:|Group Riders:|RV Lot:|Assigned Stall Units:|Assigned RV Lots:|Assigned RV Units:|Add-On:|Venue Agreement (Accepted|Provided):|Invoice Type:|Invoice Token:|Invoice Status:|Invoice Sent At:|Invoice Paid At:|Refunded Amount:|Refunded Items:|Last Refund Transaction:|Last Refunded At:)/i';

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				if ( ! $skipping_billing ) {
					$filtered[] = '';
				}
				continue;
			}

			if ( preg_match( '/^Billing Name:/i', $line ) || preg_match( '/^Billing Address:/i', $line ) ) {
				$skipping_billing = true;
				continue;
			}

			if ( preg_match( $metadata_line_regex, $line ) ) {
				$skipping_billing = false;
				continue;
			}

			if ( $skipping_billing ) {
				continue;
			}

			$filtered[] = $line;
		}

		$notes = trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $filtered ) ) );

		return $this->strip_billing_lines_from_special_requests(
			$notes,
			$this->get_billing_details_from_order_notes( $notes )
		);
	}

	/**
	 * Remove leaked billing lines from the special requests output.
	 *
	 * @param string $special_requests Parsed special requests text.
	 * @param string $billing_details Parsed billing details text.
	 * @return string
	 */
	private function strip_billing_lines_from_special_requests( $special_requests, $billing_details ) {
		$special_lines = preg_split( "/\r\n|\r|\n/", trim( (string) $special_requests ) );
		$billing_lines = preg_split( "/\r\n|\r|\n/", trim( (string) $billing_details ) );
		$billing_map   = array();
		$filtered      = array();

		foreach ( $billing_lines as $billing_line ) {
			$billing_line = trim( (string) $billing_line );

			if ( '' !== $billing_line ) {
				$billing_map[ strtolower( $billing_line ) ] = true;
			}
		}

		foreach ( $special_lines as $special_line ) {
			$special_line = trim( (string) $special_line );

			if ( '' === $special_line ) {
				if ( ! empty( $filtered ) && '' !== end( $filtered ) ) {
					$filtered[] = '';
				}
				continue;
			}

			if ( isset( $billing_map[ strtolower( $special_line ) ] ) ) {
				continue;
			}

			$filtered[] = $special_line;
		}

		return trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $filtered ) ) );
	}

	/**
	 * Build refund detail rows for the order details screen.
	 *
	 * @param array $order Grouped order payload.
	 * @return array<int, array<string, string>>
	 */
	private function get_refund_details_from_order( $order ) {
		$rows = array();

		if ( empty( $order['components'] ) || ! is_array( $order['components'] ) ) {
			return $rows;
		}

		foreach ( $order['components'] as $component ) {
			$notes             = isset( $component['notes'] ) ? (string) $component['notes'] : '';
			$refunded_amount   = $this->get_order_note_value( $notes, 'Refunded Amount' );
			$refund_txn        = $this->get_order_note_value( $notes, 'Last Refund Transaction' );
			$refunded_at       = $this->get_order_note_value( $notes, 'Last Refunded At' );
			$refunded_item_ids = $this->get_component_refunded_item_ids( $component );

			if ( '' === $refunded_amount && '' === $refund_txn && '' === $refunded_at && empty( $refunded_item_ids ) ) {
				continue;
			}

			$row = array(
				__( 'Component', 'equine-event-manager' ) => $this->get_component_refund_title( $component ),
			);

			if ( '' !== $refunded_amount ) {
				$row[ __( 'Refunded Amount', 'equine-event-manager' ) ] = $this->format_money( (float) $refunded_amount );
			}

			if ( ! empty( $refunded_item_ids ) ) {
				$row[ __( 'Refunded Items', 'equine-event-manager' ) ] = $this->format_refunded_item_labels_for_component( $order, $component, $refunded_item_ids );
			}

			if ( '' !== $refund_txn ) {
				$row[ __( 'Refund Transaction', 'equine-event-manager' ) ] = $refund_txn;
			}

			if ( '' !== $refunded_at ) {
				$row[ __( 'Refunded At', 'equine-event-manager' ) ] = $refunded_at;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Format refunded item labels for display.
	 *
	 * @param array $order Grouped order payload.
	 * @param array $component Order component payload.
	 * @param array $refunded_item_ids Refunded item IDs.
	 * @return string
	 */
	private function format_refunded_item_labels_for_component( $order, $component, $refunded_item_ids ) {
		$label_map = $this->get_component_refund_item_label_map( $order, $component );
		$labels    = array();

		foreach ( (array) $refunded_item_ids as $item_id ) {
			$item_id = sanitize_text_field( (string) $item_id );

			if ( '' === $item_id ) {
				continue;
			}

			if ( isset( $label_map[ $item_id ] ) ) {
				$labels[] = $label_map[ $item_id ];
				continue;
			}

			$raw_label = preg_replace( '/^.*\|/', '', $item_id );
			$raw_label = str_replace( array( '-', '_' ), ' ', (string) $raw_label );
			$labels[]  = ucwords( trim( $raw_label ) );
		}

		$labels = array_values( array_unique( array_filter( $labels ) ) );

		return ! empty( $labels ) ? implode( ', ', $labels ) : __( 'Not specified', 'equine-event-manager' );
	}

	/**
	 * Build a refund item label map for a component.
	 *
	 * @param array $order Grouped order payload.
	 * @param array $component Order component payload.
	 * @return array<string, string>
	 */
	private function get_component_refund_item_label_map( $order, $component ) {
		$items = array();

		if ( 'authorize_net' === ( isset( $component['payment_gateway'] ) ? $component['payment_gateway'] : '' ) ) {
			$items[] = array(
				'slug'  => 'full-component',
				'label' => sprintf( __( 'Full %s refund', 'equine-event-manager' ), $this->get_component_refund_title( $component ) ),
			);
		} elseif ( 'stall' === $component['type'] ) {
			$items = $this->build_stall_component_refund_items( $order, $component );
		} else {
			$items = $this->build_rv_component_refund_items( $order, $component );
		}

		$map = array();

		foreach ( $items as $item ) {
			if ( empty( $item['slug'] ) || empty( $item['label'] ) ) {
				continue;
			}

			$map[ $this->build_refund_item_id( $component, $item['slug'] ) ] = (string) $item['label'];
		}

		$map[ $this->build_refund_item_id( $component, 'component-total' ) ] = $this->get_component_refund_title( $component );

		return $map;
	}

	/**
	 * Extract billing details from stored order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return string
	 */
	private function get_billing_details_from_order_notes( $notes ) {
		$lines                 = preg_split( "/\r\n|\r|\n/", (string) $notes );
		$billing_name          = '';
		$billing_address_lines = array();
		$capturing             = false;
		$metadata_line_regex   = '/^(Venue Agreement (Accepted|Provided):|Add-On:|Group Charge:|Group Reservation:|Group Riders Count:|Group Riders:|Invoice Type:|Reservation setup ID:|Submission token:|RV Lot:|RV Add-Ons:|Invoice Token:|Invoice Status:|Invoice Sent At:|Invoice Paid At:)/i';

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				if ( $capturing ) {
					break;
				}
				continue;
			}

			if ( preg_match( '/^Billing Name:\s*(.+)$/i', $line, $matches ) ) {
				$billing_name = trim( $matches[1] );
				$capturing    = true;
				continue;
			}

			if ( preg_match( '/^Billing Address:\s*(.+)$/i', $line, $matches ) ) {
				$billing_address_lines[] = trim( $matches[1] );
				$capturing               = true;
				continue;
			}

			if ( $capturing ) {
				if ( preg_match( $metadata_line_regex, $line ) ) {
					break;
				}

				$billing_address_lines[] = $line;
			}
		}

		$billing = trim( $billing_name . "\n" . implode( "\n", array_filter( $billing_address_lines ) ) );

		if ( '' !== $billing ) {
			return $billing;
		}

		if ( preg_match( '/Billing Address:\s*(.+)$/mi', (string) $notes, $matches ) ) {
			$billing = trim( $matches[1] );

			if ( '' !== $billing ) {
				return $billing;
			}
		}

		return __( 'No billing details provided.', 'equine-event-manager' );
	}

	/**
	 * Detect whether venue agreement was provided for an order.
	 *
	 * @param string $notes Raw order notes.
	 * @return bool
	 */
	private function has_venue_agreement_from_order_notes( $notes ) {
		return (bool) preg_match( '/(?:^|\n)Venue Agreement (Accepted|Provided):\s*Yes(?:\n|$)/i', (string) $notes );
	}

	/**
	 * Extract reservation setup ID from component notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return int
	 */
	private static function extract_reservation_id_from_notes( $notes ) {
		if ( preg_match( '/(?:^|\n)Reservation setup ID:\s*(\d+)/i', (string) $notes, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Render order totals.
	 *
	 * @param array $order Order data.
	 */
	private function render_order_totals_table( $order ) {
		$rows                     = array();
		$stall_breakdown          = $this->get_order_stall_breakdown( $order );
		$rv_addon_breakdown       = $this->get_order_rv_addon_breakdown( $order );
		$rows[ __( 'Stall Subtotal', 'equine-event-manager' ) ] = $stall_breakdown['base_subtotal'];

		if ( $stall_breakdown['required_shavings_qty'] > 0 || $stall_breakdown['required_shavings_subtotal'] > 0 ) {
			$rows[ __( 'Required Shavings', 'equine-event-manager' ) ] = $stall_breakdown['required_shavings_subtotal'];
		}

		if ( $stall_breakdown['additional_shavings_qty'] > 0 || $stall_breakdown['additional_shavings_subtotal'] > 0 ) {
			$rows[ __( 'Additional Shavings', 'equine-event-manager' ) ] = $stall_breakdown['additional_shavings_subtotal'];
		}

		if ( ! empty( $rv_addon_breakdown ) ) {
			$rv_base_subtotal = max( 0, (float) $order['rv_subtotal'] - array_sum( wp_list_pluck( $rv_addon_breakdown, 'subtotal' ) ) );
			$rows[ __( 'RV Subtotal', 'equine-event-manager' ) ] = $rv_base_subtotal;

			foreach ( $rv_addon_breakdown as $addon_label => $addon_row ) {
				$rows[ sprintf( __( '%s Add-On', 'equine-event-manager' ), $addon_row['label'] ) ] = $addon_row['subtotal'];
			}
		} else {
			$rows[ __( 'RV Subtotal', 'equine-event-manager' ) ] = $order['rv_subtotal'];
		}

		$rows[ __( 'Non-Refundable Convenience Fee', 'equine-event-manager' ) ] = $order['fees'];
		$rows[ __( 'Total', 'equine-event-manager' ) ]                           = $order['total'];

		?>
		<table class="widefat fixed striped">
			<tbody>
				<?php foreach ( $rows as $label => $amount ) : ?>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<td>
							<?php if ( __( 'Total', 'equine-event-manager' ) === $label ) : ?>
								<strong><?php echo esc_html( '$' . number_format_i18n( (float) $amount, 2 ) ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( '$' . number_format_i18n( (float) $amount, 2 ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Get a derived stall subtotal breakdown for an order.
	 *
	 * @param array $order Order payload.
	 * @return array
	 */
	private function get_order_stall_breakdown( $order ) {
		$reservation_id               = ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
		$required_price              = $reservation_id ? (float) get_post_meta( $reservation_id, 'required_shavings_price', true ) : 0.0;
		$additional_price            = $reservation_id ? (float) get_post_meta( $reservation_id, 'additional_shavings_price', true ) : 0.0;
		$required_shavings_qty       = 0;
		$additional_shavings_qty     = 0;
		$base_subtotal               = 0.0;
		$required_shavings_subtotal  = 0.0;
		$additional_shavings_subtotal = 0.0;
		$stall_rows                  = $this->get_order_component_rows( $order, 'stall' );

		foreach ( $stall_rows as $row ) {
			$row_reservation_id         = self::extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
			$row_reservation_id         = $row_reservation_id ? $row_reservation_id : $reservation_id;
			$row_required_price         = $row_reservation_id ? (float) get_post_meta( $row_reservation_id, 'required_shavings_price', true ) : $required_price;
			$row_additional_price       = $row_reservation_id ? (float) get_post_meta( $row_reservation_id, 'additional_shavings_price', true ) : $additional_price;
			$stall_quantity             = absint( $row['stall_qty'] ) + absint( $row['tack_stall_qty'] );
			$row_required_qty           = absint( $row['required_shavings_qty'] );
			$row_additional_qty         = absint( $row['additional_shavings_qty'] );
			$stay_units                 = $this->get_billable_stay_units( $row['arrival_date'], $row['departure_date'], $row['stay_type'] );
			$row_base_subtotal          = $stall_quantity * (float) $row['unit_price'] * $stay_units;
			$row_required_subtotal      = $row_required_qty * $row_required_price;
			$row_additional_subtotal    = $row_additional_qty * $row_additional_price;
			$row_shavings_total         = max( 0, (float) $row['subtotal'] - $row_base_subtotal );
			$row_priced_shavings_total  = $row_required_subtotal + $row_additional_subtotal;

			if ( $row_shavings_total > 0 && ( $row_required_qty > 0 || $row_additional_qty > 0 ) ) {
				if ( $row_priced_shavings_total > 0 ) {
					$shavings_scale = $row_shavings_total / $row_priced_shavings_total;

					$row_required_subtotal   *= $shavings_scale;
					$row_additional_subtotal *= $shavings_scale;
				} else {
					$total_shavings_qty = $row_required_qty + $row_additional_qty;

					if ( $total_shavings_qty > 0 ) {
						$derived_per_bag = $row_shavings_total / $total_shavings_qty;

						$row_required_subtotal   = $row_required_qty * $derived_per_bag;
						$row_additional_subtotal = $row_additional_qty * $derived_per_bag;
					}
				}
			}

			$required_shavings_qty      += $row_required_qty;
			$additional_shavings_qty    += $row_additional_qty;
			$base_subtotal              += $row_base_subtotal;
			$required_shavings_subtotal += $row_required_subtotal;
			$additional_shavings_subtotal += $row_additional_subtotal;
		}

		if ( empty( $stall_rows ) ) {
			$required_shavings_qty       = absint( $order['required_shavings_qty'] );
			$additional_shavings_qty     = absint( $order['additional_shavings_qty'] );
			$required_shavings_subtotal  = $required_shavings_qty * $required_price;
			$additional_shavings_subtotal = $additional_shavings_qty * $additional_price;
			$base_subtotal               = max( 0, (float) $order['stall_subtotal'] - $required_shavings_subtotal - $additional_shavings_subtotal );
		}

		$total_shavings_qty = $required_shavings_qty + $additional_shavings_qty;
		$stored_stall_total = (float) $order['stall_subtotal'];
		$derived_shavings_total = $required_shavings_subtotal + $additional_shavings_subtotal;

		if ( $total_shavings_qty > 0 && $stored_stall_total > 0 ) {
			$derived_base_subtotal = max( 0, $stored_stall_total - $derived_shavings_total );

			if ( $derived_shavings_total <= 0 || $derived_base_subtotal > $base_subtotal + 0.01 ) {
				$fallback_shavings_total = max( 0, $stored_stall_total - $base_subtotal );

				if ( $fallback_shavings_total > 0 ) {
					if ( $derived_shavings_total > 0 ) {
						$shavings_scale = $fallback_shavings_total / $derived_shavings_total;

						$required_shavings_subtotal   *= $shavings_scale;
						$additional_shavings_subtotal *= $shavings_scale;
					} elseif ( $required_shavings_qty > 0 && $additional_shavings_qty > 0 ) {
						$required_ratio = $required_shavings_qty / $total_shavings_qty;

						$required_shavings_subtotal   = $fallback_shavings_total * $required_ratio;
						$additional_shavings_subtotal = $fallback_shavings_total - $required_shavings_subtotal;
					} elseif ( $required_shavings_qty > 0 ) {
						$required_shavings_subtotal   = $fallback_shavings_total;
						$additional_shavings_subtotal = 0.0;
					} elseif ( $additional_shavings_qty > 0 ) {
						$required_shavings_subtotal   = 0.0;
						$additional_shavings_subtotal = $fallback_shavings_total;
					}
				}
			}
		}

		return array(
			'base_subtotal'               => $base_subtotal,
			'required_shavings_qty'       => $required_shavings_qty,
			'required_shavings_subtotal'  => $required_shavings_subtotal,
			'additional_shavings_qty'     => $additional_shavings_qty,
			'additional_shavings_subtotal' => $additional_shavings_subtotal,
		);
	}

	/**
	 * Get a derived RV add-on subtotal breakdown for an order.
	 *
	 * @param array $order Order payload.
	 * @return array
	 */
	private function get_order_rv_addon_breakdown( $order ) {
		$breakdown = array();
		$rv_rows   = $this->get_order_component_rows( $order, 'rv' );
		$rv_base_subtotal = 0.0;

		foreach ( $rv_rows as $row ) {
			$reservation_id = self::extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
			$reservation_id = $reservation_id ? $reservation_id : ( ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0 );
			$rv_labels      = $this->get_rv_addon_labels_from_row_payload( $row );
			$rv_labels      = ! empty( $rv_labels ) ? $rv_labels : $this->get_order_rv_addon_labels( $order );
			$rv_quantity    = absint( $row['rv_qty'] );

			if ( empty( $rv_labels ) || $rv_quantity < 1 ) {
				continue;
			}

			$stay_type  = ! empty( $row['stay_type'] ) ? sanitize_key( $row['stay_type'] ) : 'nightly';
			$stay_units = $this->get_billable_stay_units( $row['arrival_date'], $row['departure_date'], $stay_type );
			$row_base_subtotal = $rv_quantity * (float) $row['unit_price'] * $stay_units;
			$row_addon_total   = max( 0, (float) $row['subtotal'] - $row_base_subtotal );
			$row_priced_total  = 0.0;
			$row_breakdown     = array();
			$rv_base_subtotal += $row_base_subtotal;

			$reservation_rv_addons = $reservation_id ? $this->get_reservation_meta_values( $reservation_id )['rv_addons'] : array();

			foreach ( $rv_labels as $addon_label ) {
				$rate = $this->get_named_rv_addon_price( $reservation_rv_addons, $addon_label );

				if ( $rate <= 0 ) {
					$row_breakdown[ $addon_label ] = array(
						'label'    => $addon_label,
						'subtotal' => 0.0,
					);
					continue;
				}

				$row_breakdown[ $addon_label ] = array(
					'label'    => $addon_label,
					'subtotal' => $rv_quantity * $rate * $stay_units,
				);
				$row_priced_total += $row_breakdown[ $addon_label ]['subtotal'];
			}

			if ( $row_addon_total > 0 && ! empty( $row_breakdown ) ) {
				if ( $row_priced_total > 0 ) {
					$addon_scale = $row_addon_total / $row_priced_total;

					foreach ( $row_breakdown as $addon_label => $addon_row ) {
						$row_breakdown[ $addon_label ]['subtotal'] = $addon_row['subtotal'] * $addon_scale;
					}
				} else {
					$equal_share = $row_addon_total / count( $row_breakdown );

					foreach ( $row_breakdown as $addon_label => $addon_row ) {
						$row_breakdown[ $addon_label ]['subtotal'] = $equal_share;
					}
				}
			}

			foreach ( $row_breakdown as $addon_label => $addon_row ) {
				if ( ! isset( $breakdown[ $addon_label ] ) ) {
					$breakdown[ $addon_label ] = array(
						'label'    => $addon_label,
						'subtotal' => 0.0,
					);
				}

				$breakdown[ $addon_label ]['subtotal'] += $addon_row['subtotal'];
			}
		}

		if ( ! empty( $breakdown ) ) {
			return $breakdown;
		}

		$reservation_id = ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
		$rv_labels      = $this->get_order_rv_addon_labels( $order );
		$stored_rv_total = (float) $order['rv_subtotal'];
		$fallback_addon_total = max( 0, $stored_rv_total - $rv_base_subtotal );

		if ( empty( $rv_labels ) ) {
			return $breakdown;
		}

		$rv_quantity = absint( $order['rv_quantity'] );

		if ( $rv_quantity < 1 && $fallback_addon_total <= 0 ) {
			return $breakdown;
		}

		$stay_type  = ! empty( $order['rv_stay_type'] ) ? sanitize_key( $order['rv_stay_type'] ) : 'nightly';
		$stay_units = $this->get_billable_stay_units( $order['rv_arrival_date'], $order['rv_departure_date'], $stay_type );
		$priced_total = 0.0;

		$reservation_rv_addons = $reservation_id ? $this->get_reservation_meta_values( $reservation_id )['rv_addons'] : array();

		foreach ( $rv_labels as $addon_label ) {
			$rate = $this->get_named_rv_addon_price( $reservation_rv_addons, $addon_label );

			if ( $rate <= 0 ) {
				$breakdown[ $addon_label ] = array(
					'label'    => $addon_label,
					'subtotal' => 0.0,
				);
				continue;
			}

			$breakdown[ $addon_label ] = array(
				'label'    => $addon_label,
				'subtotal' => $rv_quantity * $rate * $stay_units,
			);
			$priced_total += $breakdown[ $addon_label ]['subtotal'];
		}

		if ( $fallback_addon_total > 0 && ! empty( $breakdown ) ) {
			if ( $priced_total > 0 ) {
				$scale = $fallback_addon_total / $priced_total;

				foreach ( $breakdown as $addon_label => $addon_row ) {
					$breakdown[ $addon_label ]['subtotal'] = $addon_row['subtotal'] * $scale;
				}
			} else {
				$equal_share = $fallback_addon_total / count( $breakdown );

				foreach ( $breakdown as $addon_label => $addon_row ) {
					$breakdown[ $addon_label ]['subtotal'] = $equal_share;
				}
			}
		}

		return $breakdown;
	}

	/**
	 * Get the configured price for a saved RV add-on by name.
	 *
	 * @param array  $rv_addons Saved RV add-on rows.
	 * @param string $addon_label Add-on label.
	 * @return float
	 */
	private function get_named_rv_addon_price( $rv_addons, $addon_label ) {
		$addon_label = sanitize_text_field( $addon_label );

		foreach ( (array) $rv_addons as $addon ) {
			if ( ! is_array( $addon ) || empty( $addon['name'] ) ) {
				continue;
			}

			if ( sanitize_text_field( $addon['name'] ) !== $addon_label ) {
				continue;
			}

			if ( isset( $addon['price'] ) ) {
				return (float) $addon['price'];
			}

			if ( isset( $addon['nightly_rate'] ) ) {
				return (float) $addon['nightly_rate'];
			}

			if ( isset( $addon['weekend_rate'] ) ) {
				return (float) $addon['weekend_rate'];
			}
		}

		return 0.0;
	}

	/**
	 * Get saved DB rows for order components of a given type.
	 *
	 * @param array  $order Grouped order payload.
	 * @param string $component_type Component type.
	 * @return array
	 */
	private function get_order_component_rows( $order, $component_type ) {
		global $wpdb;

		$row_ids = array();

		foreach ( (array) $order['components'] as $component ) {
			if ( empty( $component['table'] ) || $component_type !== $component['table'] || empty( $component['row_id'] ) ) {
				continue;
			}

			$row_ids[] = absint( $component['row_id'] );
		}

		$row_ids = array_values( array_unique( array_filter( $row_ids ) ) );

		if ( empty( $row_ids ) ) {
			return array();
		}

		$table_name   = 'stall' === $component_type ? $wpdb->prefix . 'en_stall_reservations' : $wpdb->prefix . 'en_rv_reservations';
		$placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
		$query        = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id IN ({$placeholders})", $row_ids );

		return (array) $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get selected RV add-on labels from an order.
	 *
	 * @param array $order Order payload.
	 * @return array
	 */
	private function get_order_rv_addon_labels( $order ) {
		$raw = ! empty( $order['rv_type'] ) ? (string) $order['rv_type'] : '';

		if ( '' === $raw && ! empty( $order['notes'] ) && preg_match( '/(?:^|\n)RV Add-Ons:\s*(.+)$/mi', (string) $order['notes'], $matches ) ) {
			$raw = trim( $matches[1] );
		}

		if ( '' === $raw ) {
			return array();
		}

		$raw_parts = preg_split( '/\s*,\s*/', trim( (string) $raw ) );
		$labels    = array();

		foreach ( (array) $raw_parts as $raw_part ) {
			$raw_part = trim( (string) $raw_part );

			if ( '' === $raw_part ) {
				continue;
			}

			$labels[] = sanitize_text_field( $raw_part );
		}

		return array_values( array_unique( array_filter( $labels ) ) );
	}

	/**
	 * Get selected RV add-on labels from a raw RV row payload.
	 *
	 * @param array $row Raw RV row.
	 * @return array
	 */
	private function get_rv_addon_labels_from_row_payload( $row ) {
		$raw = ! empty( $row['rv_type'] ) ? (string) $row['rv_type'] : '';

		if ( '' === $raw && ! empty( $row['notes'] ) && preg_match( '/(?:^|\n)RV Add-Ons:\s*(.+)$/mi', (string) $row['notes'], $matches ) ) {
			$raw = trim( $matches[1] );
		}

		if ( '' === $raw ) {
			return array();
		}

		$raw_parts = preg_split( '/\s*,\s*/', trim( (string) $raw ) );
		$labels    = array();

		foreach ( (array) $raw_parts as $raw_part ) {
			$raw_part = trim( (string) $raw_part );

			if ( '' === $raw_part ) {
				continue;
			}

			$labels[] = sanitize_text_field( $raw_part );
		}

		return array_values( array_unique( array_filter( $labels ) ) );
	}

	/**
	 * Get billable stay units for pricing.
	 *
	 * @param string $arrival_date Arrival date.
	 * @param string $departure_date Departure date.
	 * @param string $stay_type Stay type.
	 * @return int
	 */
	private function get_billable_stay_units( $arrival_date, $departure_date, $stay_type ) {
		if ( 'weekend' === $stay_type ) {
			return 1;
		}

		$arrival_timestamp   = strtotime( (string) $arrival_date );
		$departure_timestamp = strtotime( (string) $departure_date );

		if ( ! $arrival_timestamp || ! $departure_timestamp ) {
			return 1;
		}

		return max( 1, (int) round( ( $departure_timestamp - $arrival_timestamp ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Stream a CSV download.
	 *
	 * @param string $file_name File name.
	 * @param array  $orders Order rows.
	 */
	private function stream_orders_csv( $file_name, $orders ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $file_name ) );

		$output = fopen( 'php://output', 'w' );

		fputcsv(
			$output,
			array(
				'Order Number',
				'Event Name',
				'Event Dates',
				'Customer Name',
				'Email',
				'Phone',
				'Type',
				'Stall Quantity',
				'RV Quantity',
				'Required Shavings Quantity',
				'Additional Shavings Quantity',
				'Stall Subtotal',
				'RV Subtotal',
				'Non-Refundable Convenience Fee',
				'Total',
				'Payment Status',
				'Created At',
				'Stall Stay Type',
				'Stall Arrival Date',
				'Stall Departure Date',
				'RV Type',
				'RV Stay Type',
				'RV Arrival Date',
				'RV Departure Date',
				'Notes',
			)
		);

		foreach ( $orders as $order ) {
			fputcsv(
				$output,
				array(
					$order['order_number'],
					$order['event_name'],
					$order['event_dates'],
					$order['customer_name'],
					$order['email'],
					$order['phone'],
					$order['type'],
					$order['stall_quantity'],
					$order['rv_quantity'],
					$order['required_shavings_qty'],
					$order['additional_shavings_qty'],
					$order['stall_subtotal'],
					$order['rv_subtotal'],
					$order['fees'],
					$order['total'],
					$order['payment_status'],
					$order['created_at'],
					$order['stall_stay_type'],
					$order['stall_arrival_date'],
					$order['stall_departure_date'],
					$this->format_rv_type_label( $order['rv_type'] ),
					$order['rv_stay_type'],
					$order['rv_arrival_date'],
					$order['rv_departure_date'],
					$order['notes'],
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Log a report export.
	 *
	 * @param int    $reservation_id Reservation ID.
	 * @param string $reservation_name Reservation name.
	 * @param string $file_name File name.
	 */
	private function log_report_export( $reservation_id, $reservation_name, $file_name ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'en_report_exports';

		$wpdb->insert(
			$table_name,
			array(
				'export_scope'     => $reservation_id ? 'reservation' : 'all',
				'reservation_id'   => $reservation_id,
				'reservation_name' => $reservation_name ? $reservation_name : __( 'All reservations', 'equine-event-manager' ),
				'file_name'        => $file_name,
				'exported_by'      => get_current_user_id(),
				'created_at'       => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get report export logs.
	 *
	 * @return array
	 */
	private function get_report_export_logs() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'en_report_exports';
		$rows       = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 50", ARRAY_A );

		foreach ( $rows as &$row ) {
			$user                    = ! empty( $row['exported_by'] ) ? get_user_by( 'id', absint( $row['exported_by'] ) ) : null;
			$row['exported_by_name'] = $user ? $user->display_name : __( 'Unknown user', 'equine-event-manager' );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Render a simple admin notice based on query args.
	 */
	private function render_admin_notice() {
		$notice = isset( $_GET['en_notice'] ) ? sanitize_key( wp_unslash( $_GET['en_notice'] ) ) : '';
		$error  = isset( $_GET['en_error'] ) ? sanitize_text_field( wp_unslash( $_GET['en_error'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$map = array(
			'order_deleted'      => array( 'success', __( 'Order deleted.', 'equine-event-manager' ) ),
			'order_delete_failed'=> array( 'error', __( 'Order could not be deleted.', 'equine-event-manager' ) ),
			'refund_success'     => array( 'success', __( 'Order refund completed.', 'equine-event-manager' ) ),
			'refund_failed'      => array( 'error', $error ? $error : __( 'Refund failed.', 'equine-event-manager' ) ),
			'invoice_email_sent' => array( 'success', __( 'Payment-link invoice email sent.', 'equine-event-manager' ) ),
			'invoice_email_failed' => array( 'error', $error ? $error : __( 'Invoice email could not be sent.', 'equine-event-manager' ) ),
			'customer_notification_sent' => array( 'success', __( 'Customer notification resent.', 'equine-event-manager' ) ),
			'customer_notification_failed' => array( 'error', $error ? $error : __( 'Customer notification could not be sent.', 'equine-event-manager' ) ),
			'manual_payment_recorded' => array( 'success', __( 'Order marked paid for a manual cash/in-person payment.', 'equine-event-manager' ) ),
			'manual_payment_failed' => array( 'error', $error ? $error : __( 'The order could not be marked paid.', 'equine-event-manager' ) ),
			'assignment_update_success' => array( 'success', __( 'Unit assignments updated.', 'equine-event-manager' ) ),
			'assignment_update_failed' => array( 'error', $error ? $error : __( 'Assignments could not be updated.', 'equine-event-manager' ) ),
			'stall_assignments_generated' => array( 'success', __( 'Stall assignments generated for this reservation.', 'equine-event-manager' ) ),
			'stall_assignments_generation_failed' => array( 'error', $error ? $error : __( 'Stall assignments could not be generated.', 'equine-event-manager' ) ),
			'settings_saved'     => array( 'success', __( 'Settings saved.', 'equine-event-manager' ) ),
			'native_events_disabled' => array( 'info', __( 'Native Events is currently turned off. Re-enable it in Settings > Features to access those event screens again.', 'equine-event-manager' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $map[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible" role="status"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Get payment settings with defaults.
	 *
	 * @return array
	 */
	public function get_payment_settings() {
		$settings = wp_parse_args(
			get_option( self::PAYMENT_SETTINGS_OPTION, array() ),
			array(
				'selected_gateway' => 'stripe',
				'stripe'           => array(),
				'authorize_net'    => array(),
			)
		);

		$settings['stripe'] = wp_parse_args(
			$settings['stripe'],
			array(
				'mode'                   => 'test',
				'test_publishable_key'   => '',
				'test_secret_key'        => '',
				'live_publishable_key'   => '',
				'live_secret_key'        => '',
				'webhook_signing_secret' => '',
			)
		);

		$settings['authorize_net'] = wp_parse_args(
			$settings['authorize_net'],
			array(
				'mode'                 => 'test',
				'test_api_login'       => '',
				'test_transaction_key' => '',
				'live_api_login'       => '',
				'live_transaction_key' => '',
			)
		);

		return $settings;
	}

	/**
	 * Get company settings with defaults.
	 *
	 * @return array
	 */
	private function get_company_settings() {
		return wp_parse_args(
			get_option( self::COMPANY_SETTINGS_OPTION, array() ),
			array(
				'logo_id'       => 0,
				'support_phone' => '',
				'support_email' => get_option( 'admin_email', '' ),
			)
		);
	}

	/**
	 * Get the effective company logo URL for admin and print views.
	 *
	 * @param string $size Image size.
	 * @return string
	 */
	private function get_company_logo_url( $size = 'full' ) {
		$company_settings = $this->get_company_settings();
		$logo_url         = ! empty( $company_settings['logo_id'] ) ? wp_get_attachment_image_url( absint( $company_settings['logo_id'] ), $size ) : '';

		if ( ! $logo_url ) {
			$logo_url = EQUINE_EVENT_MANAGER_URL . 'assets/images/logo.png';
		}

		return $logo_url;
	}

	/**
	 * Get reservation message settings with defaults.
	 *
	 * @return array
	 */
	private function get_reservation_message_settings() {
		$company_settings = $this->get_company_settings();

		return wp_parse_args(
			get_option( self::RESERVATION_MESSAGE_SETTINGS_OPTION, array() ),
			array(
				'support_phone' => $company_settings['support_phone'],
				'support_email' => $company_settings['support_email'],
				'preopen_message' => self::DEFAULT_PREOPEN_MESSAGE,
				'closed_message' => self::DEFAULT_CLOSED_MESSAGE,
			)
		);
	}

	/**
	 * Get email receipt settings with defaults.
	 *
	 * @return array
	 */
	private function get_receipt_settings() {
		$company_settings = $this->get_company_settings();
		$saved            = get_option( self::RECEIPT_SETTINGS_OPTION, array() );
		$fallback_email   = ! empty( $company_settings['support_email'] ) && is_email( $company_settings['support_email'] ) ? $company_settings['support_email'] : get_option( 'admin_email', '' );
		$settings         = wp_parse_args(
			$saved,
			array(
				'customer_receipt_enabled' => 1,
				'admin_receipt_email'      => '',
				'from_name'                => '',
				'from_email'               => '',
				'reply_to_email'           => '',
				'customer_subject'         => self::DEFAULT_CUSTOMER_RECEIPT_SUBJECT,
				'admin_subject'            => self::DEFAULT_ADMIN_RECEIPT_SUBJECT,
				'customer_body'            => self::DEFAULT_CUSTOMER_RECEIPT_BODY,
				'admin_body'               => self::DEFAULT_ADMIN_RECEIPT_BODY,
			)
		);

		$settings['customer_receipt_enabled'] = isset( $saved['customer_receipt_enabled'] ) ? (int) ! empty( $saved['customer_receipt_enabled'] ) : 1;
		$settings['admin_receipt_email']      = ! empty( $settings['admin_receipt_email'] ) && is_email( $settings['admin_receipt_email'] ) ? $settings['admin_receipt_email'] : $fallback_email;
		$settings['from_name']                = ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name' );
		$settings['from_email']               = ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) ? $settings['from_email'] : $fallback_email;
		$settings['reply_to_email']           = ! empty( $settings['reply_to_email'] ) && is_email( $settings['reply_to_email'] ) ? $settings['reply_to_email'] : $fallback_email;
		$settings['customer_subject']         = ! empty( $settings['customer_subject'] ) ? $settings['customer_subject'] : self::DEFAULT_CUSTOMER_RECEIPT_SUBJECT;
		$settings['admin_subject']            = ! empty( $settings['admin_subject'] ) ? $settings['admin_subject'] : self::DEFAULT_ADMIN_RECEIPT_SUBJECT;
		$settings['customer_body']            = ! empty( $settings['customer_body'] ) ? $settings['customer_body'] : self::DEFAULT_CUSTOMER_RECEIPT_BODY;
		$settings['admin_body']               = ! empty( $settings['admin_body'] ) ? $settings['admin_body'] : self::DEFAULT_ADMIN_RECEIPT_BODY;

		// Launch fix: the Settings → Communications "Sender" UI writes to the
		// newer eem_email_sender option (EEM_Settings_Repo), NOT this legacy
		// receipt_settings option — so without this layer the From/Reply-To the
		// admin sets in the UI is silently ignored by transactional emails. Treat
		// the UI sender as canonical when set (mapping its `reply_to` →
		// `reply_to_email`).
		if ( class_exists( 'EEM_Settings_Repo' ) && method_exists( 'EEM_Settings_Repo', 'get_email_sender' ) ) {
			$ui_sender = EEM_Settings_Repo::get_email_sender();
			if ( ! empty( $ui_sender['from_name'] ) ) {
				$settings['from_name'] = $ui_sender['from_name'];
			}
			if ( ! empty( $ui_sender['from_email'] ) && is_email( $ui_sender['from_email'] ) ) {
				$settings['from_email'] = $ui_sender['from_email'];
			}
			if ( ! empty( $ui_sender['reply_to'] ) && is_email( $ui_sender['reply_to'] ) ) {
				$settings['reply_to_email'] = $ui_sender['reply_to'];
			}
		}

		return $settings;
	}

	/**
	 * Ensure the current user can access plugin admin pages.
	 */
	private function guard_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
	}

	/**
	 * Ensure the current user can run admin actions.
	 */
	private function guard_admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'equine-event-manager' ) );
		}
	}

	/**
	 * Redirect to a plugin admin page with a notice payload, then exit.
	 *
	 * Empty/null values in $extra_args and the notice/error pair are dropped
	 * via array_filter so the resulting URL only carries meaningful args.
	 *
	 * @param string      $page_slug  Admin page slug (the `page=` query arg).
	 * @param array       $extra_args Additional query args specific to the destination.
	 * @param string      $notice     Notice slug.
	 * @param string|null $error      Optional error text.
	 * @return void
	 */
	private function redirect_with_notice( $page_slug, array $extra_args, $notice, $error = null ) {
		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array_merge(
						array( 'page' => $page_slug ),
						$extra_args,
						array( 'en_notice' => $notice, 'en_error' => $error )
					)
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect back to the order details page with a notice payload.
	 *
	 * @param string      $order_key Order key.
	 * @param string      $notice    Notice slug.
	 * @param string|null $error     Optional error text.
	 * @return void
	 */
	private function redirect_to_order_notice( $order_key, $notice, $error = null ) {
		$this->redirect_with_notice(
			'equine-event-manager-order',
			array( 'order_key' => $order_key ),
			$notice,
			$error
		);
	}

	/**
	 * Redirect back to the stall chart page with a notice payload.
	 *
	 * @param int         $reservation_id Reservation ID (omitted from the URL when zero).
	 * @param string      $notice Notice slug.
	 * @param string|null $error Optional error text.
	 * @return void
	 */
	private function redirect_to_stall_chart_notice( $reservation_id, $notice, $error = null ) {
		$this->redirect_with_notice(
			'equine-event-manager-stall-charts',
			array( 'reservation_id' => $reservation_id > 0 ? absint( $reservation_id ) : null ),
			$notice,
			$error
		);
	}

	/**
	 * Redirect to the reservation overview or stall chart depending on caller preference.
	 *
	 * @param int         $reservation_id Reservation ID.
	 * @param string      $notice Notice slug.
	 * @param string|null $error Optional error text.
	 * @param string      $return_page Preferred return page (`stall_chart` routes to chart, anything else to overview).
	 * @return void
	 */
	private function redirect_to_reservation_notice_destination( $reservation_id, $notice, $error = null, $return_page = '' ) {
		if ( 'stall_chart' === $return_page ) {
			$this->redirect_to_stall_chart_notice( $reservation_id, $notice, $error );
		}

		$this->redirect_with_notice(
			'equine-event-manager-reservation-overview',
			array( 'reservation_id' => absint( $reservation_id ) ),
			$notice,
			$error
		);
	}

	/**
	 * Extract a metadata value from notes by label.
	 *
	 * @param string $notes Notes text.
	 * @param string $label Metadata label.
	 * @return string
	 */
	public function get_order_note_value( $notes, $label ) {
		if ( preg_match( '/(?:^|\n)' . preg_quote( $label, '/' ) . ':\s*(.+?)(?:\n|$)/i', (string) $notes, $matches ) ) {
			return trim( sanitize_text_field( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Get an order invoice token from stored notes.
	 *
	 * @param array $order Grouped order payload.
	 * @return string
	 */
	private function get_invoice_token_for_order( $order ) {
		foreach ( $order['components'] as $component ) {
			if ( empty( $component['notes'] ) ) {
				continue;
			}

			$invoice_token = $this->get_order_note_value( $component['notes'], 'Invoice Token' );

			if ( '' !== $invoice_token ) {
				return $invoice_token;
			}
		}

		return '';
	}

	/**
	 * Build the hosted payment URL for an invoice token.
	 *
	 * @param string $invoice_token Invoice token.
	 * @return string
	 */
	private function get_invoice_payment_url( $invoice_token ) {
		$invoice_token = sanitize_text_field( $invoice_token );

		if ( '' === $invoice_token ) {
			return '';
		}

		return add_query_arg(
			array(
				'equine_event_manager_invoice' => rawurlencode( $invoice_token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Insert or replace a metadata line within stored notes.
	 *
	 * @param string $notes Notes value.
	 * @param string $label Metadata label.
	 * @param string $value Metadata value.
	 * @return string
	 */
	public function upsert_order_note_line( $notes, $label, $value ) {
		$notes   = trim( (string) $notes );
		$pattern = '/^' . preg_quote( $label, '/' ) . ':\s*.*$/mi';
		$line    = $label . ': ' . $value;

		if ( preg_match( $pattern, $notes ) ) {
			$notes = preg_replace( $pattern, $line, $notes );
		} else {
			$notes = trim( $notes . "\n" . $line );
		}

		return trim( preg_replace( "/\n{3,}/", "\n\n", $notes ) );
	}

	/**
	 * Build the hosted invoice email markup.
	 *
	 * @param array  $order         Order payload.
	 * @param string $invoice_token Invoice token.
	 * @return string
	 */
	private function build_invoice_email_html( $order, $invoice_token ) {
		$company_settings = $this->get_company_settings();
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$payment_url      = add_query_arg(
			array(
				'equine_event_manager_invoice' => rawurlencode( $invoice_token ),
			),
			home_url( '/' )
		);
		$support_chunks   = array_filter(
			array(
				! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '',
				! empty( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
			)
		);
		$invoice_rows = array(
			__( 'Order Number', 'equine-event-manager' )    => $this->format_order_number_display( (string) $order['order_number'] ),
			__( 'Event', 'equine-event-manager' )           => $event_label,
			__( 'Event Dates', 'equine-event-manager' )     => ! empty( $order['event_dates'] ) ? $order['event_dates'] : __( 'Dates unavailable', 'equine-event-manager' ),
			__( 'Reservation Type', 'equine-event-manager' ) => $order['type'] ?? '',
			__( 'Amount Due', 'equine-event-manager' )      => '$' . number_format_i18n( (float) ( $order['total'] ?? 0 ), 2 ),
		);

		ob_start();
		?>
		<div style="margin:0;padding:28px;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:680px;margin:0 auto;">
				<div style="margin:0 0 18px;padding:28px 30px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<?php if ( $company_logo_url ) : ?>
						<p style="margin:0 0 20px;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:block;object-fit:contain;" /></p>
					<?php endif; ?>
					<div style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Reservation Invoice', 'equine-event-manager' ); ?></div>
					<h1 style="margin:10px 0 12px;font-size:30px;line-height:1.1;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
					<p style="margin:0;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( __( 'A payment link is ready for order #%s.', 'equine-event-manager' ), $order['order_number'] ) ); ?></p>
				</div>

				<div style="margin:0 0 18px;padding:26px 28px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( __( 'Hi %s, use the secure button below to review your reservation and complete payment.', 'equine-event-manager' ), $order['customer_name'] ) ); ?></p>
					<p style="margin:0 0 22px;"><a href="<?php echo esc_url( $payment_url ); ?>" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#111827;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;"><?php esc_html_e( 'Review Invoice & Pay Now', 'equine-event-manager' ); ?></a></p>
					<div style="display:grid;gap:12px;">
						<?php foreach ( $invoice_rows as $label => $value ) : ?>
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;">
								<span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php echo esc_html( $label ); ?></span>
								<strong style="font-size:15px;color:#111827;text-align:right;"><?php echo esc_html( $value ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( ! empty( $support_chunks ) ) : ?>
					<p style="margin:0;padding:0 10px;text-align:center;color:#4b5563;font-size:13px;line-height:1.7;"><?php echo esc_html( implode( ' | ', $support_chunks ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Build the customer-facing "Refund Processed" email HTML (CLEANUP #30).
	 *
	 * Mirrors the invoice email shell (logo header + detail rows + support
	 * footer); refund-themed and informational (no CTA). Sent from the refund
	 * flow when the admin checks "Notify customer".
	 *
	 * @param array<string,mixed> $order         Grouped order payload.
	 * @param float               $refund_amount Amount refunded.
	 * @param string              $reason        Optional refund reason.
	 * @return string Inline-styled email HTML.
	 */
	private function build_refund_email_html( $order, $refund_amount, $reason = '' ) {
		$company_settings = $this->get_company_settings();
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$support_chunks   = array_filter(
			array(
				! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '',
				! empty( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
			)
		);
		$rows = array(
			__( 'Order Number', 'equine-event-manager' )  => $this->format_order_number_display( (string) $order['order_number'] ),
			__( 'Event', 'equine-event-manager' )         => $event_label,
			__( 'Refund Amount', 'equine-event-manager' ) => '$' . number_format_i18n( (float) $refund_amount, 2 ),
		);
		if ( '' !== trim( (string) $reason ) ) {
			$rows[ __( 'Reason', 'equine-event-manager' ) ] = $reason;
		}

		ob_start();
		?>
		<div style="margin:0;padding:28px;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:680px;margin:0 auto;">
				<div style="margin:0 0 18px;padding:28px 30px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<?php if ( $company_logo_url ) : ?>
						<p style="margin:0 0 20px;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:block;object-fit:contain;" /></p>
					<?php endif; ?>
					<div style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Refund Processed', 'equine-event-manager' ); ?></div>
					<h1 style="margin:10px 0 12px;font-size:30px;line-height:1.1;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
					<p style="margin:0;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( __( 'A refund has been processed for order #%s.', 'equine-event-manager' ), $order['order_number'] ) ); ?></p>
				</div>

				<div style="margin:0 0 18px;padding:26px 28px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( __( 'Hi %s, we\'ve refunded the amount below to your original payment method. Depending on your bank, it may take a few business days to appear.', 'equine-event-manager' ), $order['customer_name'] ) ); ?></p>
					<div style="display:grid;gap:12px;">
						<?php foreach ( $rows as $label => $value ) : ?>
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;">
								<span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php echo esc_html( $label ); ?></span>
								<strong style="font-size:15px;color:#111827;text-align:right;"><?php echo esc_html( $value ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( ! empty( $support_chunks ) ) : ?>
					<p style="margin:0;padding:0 10px;text-align:center;color:#4b5563;font-size:13px;line-height:1.7;"><?php echo esc_html__( 'Questions about your refund? Contact us:', 'equine-event-manager' ) . ' ' . esc_html( implode( ' | ', $support_chunks ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Send the customer-facing refund-processed email for an order (CLEANUP #30).
	 *
	 * @param array<string,mixed> $order         Grouped order payload.
	 * @param float               $refund_amount Amount refunded.
	 * @param string              $reason        Optional refund reason.
	 * @return bool|WP_Error True on send, WP_Error on failure / missing email.
	 */
	public function send_refund_email_for_order( $order, $refund_amount, $reason = '' ) {
		if ( empty( $order ) || empty( $order['email'] ) ) {
			return new WP_Error( 'refund_email_missing_email', __( 'No customer email on file for this order.', 'equine-event-manager' ) );
		}

		$receipt_settings  = $this->get_receipt_settings();
		$reservation_label = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$headers           = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $receipt_settings['from_name'] ) && is_email( $receipt_settings['from_email'] ) ) {
			$headers[] = 'From: ' . wp_specialchars_decode( $receipt_settings['from_name'], ENT_QUOTES ) . ' <' . $receipt_settings['from_email'] . '>';
		}
		if ( is_email( $receipt_settings['reply_to_email'] ) ) {
			$headers[] = 'Reply-To: ' . $receipt_settings['reply_to_email'];
		}

		return EEM_Mailer::send_html_email(
			sanitize_email( $order['email'] ),
			sprintf( /* translators: %s: event or reservation title. */ __( 'Refund processed for %s', 'equine-event-manager' ), $reservation_label ),
			$this->build_refund_email_html( $order, $refund_amount, $reason ),
			$headers,
			array(
				'type'        => 'refund_notification',
				'order_key'   => isset( $order['order_key'] ) ? (string) $order['order_key'] : '',
				'event_label' => isset( $order['event_label'] ) ? (string) $order['event_label'] : '',
			)
		);
	}

	/**
	 * Resolve the reservation ID backing an order, parsed from the
	 * "Reservation setup ID: N" note line written at checkout. Used to pull the
	 * per-reservation cancellation policy into the cancellation email.
	 *
	 * @param array<string,mixed> $order Grouped order payload.
	 * @return int Reservation ID, or 0 when not present.
	 */
	private function get_order_reservation_id( $order ) {
		if ( isset( $order['reservation_id'] ) && (int) $order['reservation_id'] > 0 ) {
			return (int) $order['reservation_id'];
		}
		$notes = isset( $order['notes'] ) ? (string) $order['notes'] : '';
		if ( preg_match( '/Reservation setup ID:\s*(\d+)/', $notes, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Build the customer-facing order-cancellation email HTML. Mirrors the
	 * refund-email layout (company-branded, inline-styled for client safety) and
	 * surfaces the reservation's cancellation policy (resolved per reservation,
	 * with the event default as fallback) per the cancellation-policy decision.
	 *
	 * @param array<string,mixed> $order  Grouped order payload.
	 * @param string              $reason Optional admin-entered cancellation reason.
	 * @return string
	 */
	private function build_cancellation_email_html( $order, $reason = '' ) {
		$company_settings = $this->get_company_settings();
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$support_chunks   = array_filter(
			array(
				! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '',
				! empty( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
			)
		);
		$rows = array(
			__( 'Order Number', 'equine-event-manager' ) => $this->format_order_number_display( (string) $order['order_number'] ),
			__( 'Event', 'equine-event-manager' )         => $event_label,
		);
		if ( '' !== trim( (string) $reason ) ) {
			$rows[ __( 'Reason', 'equine-event-manager' ) ] = $reason;
		}

		$policy_text = '';
		$reservation_id = $this->get_order_reservation_id( $order );
		if ( $reservation_id > 0 && function_exists( 'eem_resolve_cancellation_policy' ) ) {
			$resolved = eem_resolve_cancellation_policy( $reservation_id );
			$policy_text = ( null !== $resolved ) ? trim( (string) $resolved ) : '';
		}

		ob_start();
		?>
		<div style="margin:0;padding:28px;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:680px;margin:0 auto;">
				<div style="margin:0 0 18px;padding:28px 30px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<?php if ( $company_logo_url ) : ?>
						<p style="margin:0 0 20px;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:block;object-fit:contain;" /></p>
					<?php endif; ?>
					<div style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Reservation Cancelled', 'equine-event-manager' ); ?></div>
					<h1 style="margin:10px 0 12px;font-size:30px;line-height:1.1;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
					<p style="margin:0;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( /* translators: %s: order number. */ __( 'Your reservation under order #%s has been cancelled.', 'equine-event-manager' ), $order['order_number'] ) ); ?></p>
				</div>

				<div style="margin:0 0 18px;padding:26px 28px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( /* translators: %s: customer name. */ __( 'Hi %s, the reservation below has been cancelled. If you believe this was a mistake, please contact us.', 'equine-event-manager' ), $order['customer_name'] ) ); ?></p>
					<div style="display:grid;gap:12px;">
						<?php foreach ( $rows as $label => $value ) : ?>
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;">
								<span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php echo esc_html( $label ); ?></span>
								<strong style="font-size:15px;color:#111827;text-align:right;"><?php echo esc_html( $value ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( '' !== $policy_text ) : ?>
					<div style="margin:0 0 18px;padding:20px 24px;background:#f3f4f5;border:1px solid #e5e7eb;border-radius:18px;color:#50575e;">
						<div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#031B4E;margin-bottom:8px;"><?php esc_html_e( 'Cancellation Policy', 'equine-event-manager' ); ?></div>
						<p style="margin:0;font-size:13px;line-height:1.6;color:#50575e;"><?php echo wp_kses( nl2br( esc_html( $policy_text ) ), array( 'br' => array() ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $support_chunks ) ) : ?>
					<p style="margin:0;padding:0 10px;text-align:center;color:#4b5563;font-size:13px;line-height:1.7;"><?php echo esc_html__( 'Questions? Contact us:', 'equine-event-manager' ) . ' ' . esc_html( implode( ' | ', $support_chunks ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Send the customer-facing order-cancellation email.
	 *
	 * @param array<string,mixed> $order  Grouped order payload.
	 * @param string              $reason Optional cancellation reason.
	 * @return bool|WP_Error True on send, WP_Error on missing email / failure.
	 */
	public function send_cancellation_email_for_order( $order, $reason = '' ) {
		if ( empty( $order ) || empty( $order['email'] ) ) {
			return new WP_Error( 'cancellation_email_missing_email', __( 'No customer email on file for this order.', 'equine-event-manager' ) );
		}

		$receipt_settings  = $this->get_receipt_settings();
		$reservation_label = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$headers           = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $receipt_settings['from_name'] ) && is_email( $receipt_settings['from_email'] ) ) {
			$headers[] = 'From: ' . wp_specialchars_decode( $receipt_settings['from_name'], ENT_QUOTES ) . ' <' . $receipt_settings['from_email'] . '>';
		}
		if ( is_email( $receipt_settings['reply_to_email'] ) ) {
			$headers[] = 'Reply-To: ' . $receipt_settings['reply_to_email'];
		}

		return EEM_Mailer::send_html_email(
			sanitize_email( $order['email'] ),
			sprintf( /* translators: %s: event or reservation title. */ __( 'Reservation cancelled — %s', 'equine-event-manager' ), $reservation_label ),
			$this->build_cancellation_email_html( $order, $reason ),
			$headers,
			array(
				'type'        => 'cancellation_notification',
				'order_key'   => isset( $order['order_key'] ) ? (string) $order['order_key'] : '',
				'event_label' => isset( $order['event_label'] ) ? (string) $order['event_label'] : '',
			)
		);
	}

	/**
	 * Listener for `eem_order_payment_status_changed`: when an outstanding order
	 * transitions to PAID (invoice link paid, Mark Cash/Check, Collect Payment
	 * charge), email the customer a "Payment Received" confirmation (v1 #8).
	 *
	 * Mirrors the telemetry funnel's payment-received detection so the two stay in
	 * sync. Orders created already-paid at checkout do NOT transition, so this
	 * never double-sends with the checkout confirmation/receipt email.
	 *
	 * @param array<string,mixed> $payload Hook payload (order_key, old/new status, …).
	 * @return void
	 */
	public function on_payment_received_send_email( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['order_key'] ) ) {
			return;
		}
		$old = isset( $payload['old_status'] ) ? (string) $payload['old_status'] : '';
		$new = isset( $payload['new_status'] ) ? (string) $payload['new_status'] : '';
		$outstanding = class_exists( 'EEM_Order_Telemetry' ) ? EEM_Order_Telemetry::OUTSTANDING_STATUSES : array( 'unpaid', 'invoice-sent' );
		if ( 'paid' !== $new || ! in_array( $old, (array) $outstanding, true ) ) {
			return;
		}
		$order = $this->orders_repository->get_order( (string) $payload['order_key'] );
		if ( ! is_array( $order ) || empty( $order['email'] ) ) {
			return;
		}
		$this->send_payment_received_email_for_order( $order );
	}

	/**
	 * Send the customer-facing "Payment Received" email for a now-paid order.
	 *
	 * @param array<string,mixed> $order Grouped order payload.
	 * @return bool|WP_Error True on send, WP_Error on missing email / failure.
	 */
	public function send_payment_received_email_for_order( $order ) {
		if ( empty( $order ) || empty( $order['email'] ) ) {
			return new WP_Error( 'payment_received_email_missing_email', __( 'No customer email on file for this order.', 'equine-event-manager' ) );
		}

		$receipt_settings  = $this->get_receipt_settings();
		$reservation_label = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$headers           = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $receipt_settings['from_name'] ) && is_email( $receipt_settings['from_email'] ) ) {
			$headers[] = 'From: ' . wp_specialchars_decode( $receipt_settings['from_name'], ENT_QUOTES ) . ' <' . $receipt_settings['from_email'] . '>';
		}
		if ( is_email( $receipt_settings['reply_to_email'] ) ) {
			$headers[] = 'Reply-To: ' . $receipt_settings['reply_to_email'];
		}

		return EEM_Mailer::send_html_email(
			sanitize_email( $order['email'] ),
			sprintf( /* translators: %s: event or reservation title. */ __( 'Payment received — %s', 'equine-event-manager' ), $reservation_label ),
			$this->build_payment_received_email_html( $order ),
			$headers,
			array(
				'type'        => 'payment_received_notification',
				'order_key'   => isset( $order['order_key'] ) ? (string) $order['order_key'] : '',
				'event_label' => isset( $order['event_label'] ) ? (string) $order['event_label'] : '',
			)
		);
	}

	/**
	 * Build the customer-facing "Payment Received" email HTML. Branded + inline-
	 * styled for email-client safety, mirroring the refund / cancellation layout.
	 *
	 * @param array<string,mixed> $order Grouped order payload.
	 * @return string
	 */
	private function build_payment_received_email_html( $order ) {
		$company_settings = $this->get_company_settings();
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$amount_paid      = '$' . number_format_i18n( (float) ( isset( $order['total'] ) ? $order['total'] : 0 ), 2 );
		$support_chunks   = array_filter(
			array(
				! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '',
				! empty( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
			)
		);
		$rows = array(
			__( 'Order Number', 'equine-event-manager' ) => $this->format_order_number_display( (string) $order['order_number'] ),
			__( 'Event', 'equine-event-manager' )         => $event_label,
			__( 'Amount Paid', 'equine-event-manager' )   => $amount_paid,
		);

		ob_start();
		?>
		<div style="margin:0;padding:28px;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:680px;margin:0 auto;">
				<div style="margin:0 0 18px;padding:28px 30px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<?php if ( $company_logo_url ) : ?>
						<p style="margin:0 0 20px;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:block;object-fit:contain;" /></p>
					<?php endif; ?>
					<div style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#15803d;"><?php esc_html_e( 'Payment Received', 'equine-event-manager' ); ?></div>
					<h1 style="margin:10px 0 12px;font-size:30px;line-height:1.1;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
					<p style="margin:0;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( /* translators: %s: order number. */ __( 'Thank you — we\'ve received your payment for order #%s.', 'equine-event-manager' ), $order['order_number'] ) ); ?></p>
				</div>

				<div style="margin:0 0 18px;padding:26px 28px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:24px;color:#111827;">
					<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( /* translators: %s: customer name. */ __( 'Hi %s, your payment is confirmed. Here are the details for your records.', 'equine-event-manager' ), $order['customer_name'] ) ); ?></p>
					<div style="display:grid;gap:12px;">
						<?php foreach ( $rows as $label => $value ) : ?>
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;">
								<span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php echo esc_html( $label ); ?></span>
								<strong style="font-size:15px;color:#111827;text-align:right;"><?php echo esc_html( $value ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( ! empty( $support_chunks ) ) : ?>
					<p style="margin:0;padding:0 10px;text-align:center;color:#4b5563;font-size:13px;line-height:1.7;"><?php echo esc_html__( 'Questions? Contact us:', 'equine-event-manager' ) . ' ' . esc_html( implode( ' | ', $support_chunks ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
