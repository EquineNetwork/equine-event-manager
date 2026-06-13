<?php
/**
 * Plugin activation tasks.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation tasks for Equine Event Manager.
 */
class EEM_Activator {

	/**
	 * DB version option key.
	 */
	const DB_VERSION_OPTION = 'equine_event_manager_db_version';
	const NATIVE_EVENT_REWRITE_VERSION_OPTION = 'equine_event_manager_native_event_rewrite_version';

	/**
	 * Next stable order number option key.
	 */
	const NEXT_ORDER_NUMBER_OPTION = 'equine_event_manager_next_order_number';

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		self::create_reservation_tables();
		self::create_reports_log_table();
		self::create_activity_log_table();
		self::create_event_defaults_table();
		self::create_order_adjustments_table();
		if ( class_exists( 'EEM_Division_Entries' ) ) {
			EEM_Division_Entries::create_table();
		}
		if ( class_exists( 'EEM_Venue' ) ) {
			EEM_Venue::create_tables();
		}
		if ( class_exists( 'EEM_Sheet_Entries' ) ) {
			EEM_Sheet_Entries::create_table();
		}
		self::maybe_refresh_native_event_rewrite_rules();
		self::run_one_time_migrations();
		update_option( self::DB_VERSION_OPTION, EQUINE_EVENT_MANAGER_VERSION );
		update_option( self::NATIVE_EVENT_REWRITE_VERSION_OPTION, self::get_native_event_rewrite_signature() );
	}

	/**
	 * Create the plugin-owned event defaults table ({prefix}eem_event_defaults).
	 *
	 * Composite PK (event_id, event_source) per Q4 — handles native/tec
	 * (WP post IDs) + feed (remote feed string IDs) uniformly via the
	 * varchar(191) event_id column. Idempotent via dbDelta — re-running
	 * upgrades the schema rather than recreating.
	 *
	 * Per Q5 — canonical wp_eem_* prefix. Legacy wp_en_* tables get
	 * renamed in a coordinated cleanup chunk.
	 *
	 * @return void
	 */
	private static function create_event_defaults_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'eem_event_defaults';

		$sql = "CREATE TABLE {$table_name} (
			event_id varchar(191) NOT NULL,
			event_source varchar(32) NOT NULL DEFAULT 'native',
			cancellation_policy longtext NULL,
			venue_map_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			venue_map_download_url varchar(2048) NULL,
			venue_map_caption varchar(255) NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (event_id, event_source),
			KEY event_source (event_source)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create the order adjustments table ({prefix}en_order_adjustments).
	 *
	 * Stores order-level adjustments that don't fit the component-row model
	 * (en_stall_reservations / en_rv_reservations): custom line items (one-off
	 * charges keyed by order_number) and a single per-order discount. Introduced
	 * in C13.C (Create Order — Custom Line Items + Discount). Additive — existing
	 * orders simply have zero adjustment rows, so no data backfill is required;
	 * dbDelta on the post-version-change upgrade pass creates the table on
	 * existing installs.
	 *
	 * Row kinds (the `kind` column):
	 *  - 'custom_item' — uses `description` + `amount` (amount may be negative to
	 *    represent a credit/comp); discount_* columns stay empty.
	 *  - 'discount'    — uses `discount_type` ('dollar'|'percent') + `discount_value`
	 *    (raw entered value) + `discount_reason` (required) + `amount` (the resolved
	 *    positive dollar reduction at save time); at most one per order_number.
	 *
	 * @return void
	 */
	private static function create_order_adjustments_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'en_order_adjustments';

		// order_key holds the order's submission token (a 32-char hash), NOT the
		// short display order_number — so it must be wide enough (varchar(191)),
		// matching the key Order Detail / the adjustments repo query by.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_key varchar(191) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT '',
			description varchar(191) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			discount_type varchar(10) NOT NULL DEFAULT '',
			discount_value decimal(10,2) NOT NULL DEFAULT 0.00,
			discount_reason varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_key (order_key),
			KEY kind (kind)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Run all one-time migrations, each gated by its own wp_options flag.
	 *
	 * Sequenced per Q6 in-flight D: #001 (cancellation snapshot from
	 * global wp_option to per-reservation override) before #002
	 * ('external'→'feed' event_source canonicalization in
	 * wp_eem_event_defaults). Both idempotent at top level via flag;
	 * #001 also row-level idempotent.
	 *
	 * Failure on any single migration does NOT block the others — they
	 * each set their own flag on success; a re-fire next admin load
	 * resumes any that didn't complete.
	 *
	 * @return void
	 */
	private static function run_one_time_migrations() {
		if ( ! get_option( 'eem_mig_001_cancellation_snapshot_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-001-cancellation-snapshot.php';
			eem_mig_001_cancellation_snapshot();
		}
		if ( ! get_option( 'eem_mig_002_feed_source_canon_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-002-feed-source-canon.php';
			eem_mig_002_feed_source_canon();
		}
		if ( ! get_option( 'eem_mig_003_reservation_name_inherit_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-003-reservation-name-inherit.php';
			eem_mig_003_reservation_name_inherit();
		}
		if ( ! get_option( 'eem_mig_004_stall_inventory_split_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-004-stall-inventory-split.php';
			eem_mig_004_stall_inventory_split();
		}
		if ( ! get_option( 'eem_mig_005_split_back_to_back_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-005-split-back-to-back.php';
			eem_mig_005_split_back_to_back();
		}
		if ( ! get_option( 'eem_mig_006_tack_mode_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-006-tack-mode.php';
			eem_mig_006_tack_mode();
		}
		if ( ! get_option( 'eem_mig_007_section_enabled_rename_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-007-section-enabled-rename.php';
			eem_mig_007_section_enabled_rename();
		}
		if ( ! get_option( 'eem_mig_008_activity_log_order_key_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-008-activity-log-order-key.php';
			eem_mig_008_activity_log_order_key();
		}
		if ( ! get_option( 'eem_mig_009_order_reservation_id_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-009-order-reservation-id.php';
			eem_mig_009_order_reservation_id();
		}
		if ( ! get_option( 'eem_mig_010_activity_event_type_underscores_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-010-activity-event-type-underscores.php';
			eem_mig_010_activity_event_type_underscores();
		}
		if ( ! get_option( 'eem_mig_011_refund_amount_column_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-011-refund-amount-column.php';
			eem_mig_011_refund_amount_column();
		}
		if ( ! get_option( 'eem_mig_012_frontend_url_cache_cleanup_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-012-frontend-url-cache-cleanup.php';
			eem_mig_012_frontend_url_cache_cleanup();
		}
		if ( ! get_option( 'eem_mig_013_order_trashed_at_column_complete' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-013-order-trashed-at-column.php';
			eem_mig_013_order_trashed_at_column();
		}
	}

	/**
	 * Run schema updates after a plugin version change.
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

		if ( EQUINE_EVENT_MANAGER_VERSION === $installed_version ) {
			return;
		}

		self::activate();
	}

	/**
	 * Refresh native event rewrites when the native event rewrite signature changes.
	 *
	 * @return void
	 */
	public static function maybe_refresh_runtime_rewrite_rules() {
		$stored_signature  = get_option( self::NATIVE_EVENT_REWRITE_VERSION_OPTION, '' );
		$current_signature = self::get_native_event_rewrite_signature();

		if ( $current_signature === $stored_signature ) {
			return;
		}

		self::maybe_refresh_native_event_rewrite_rules();
		update_option( self::NATIVE_EVENT_REWRITE_VERSION_OPTION, $current_signature );
	}

	/**
	 * Create reservation database tables.
	 */
	private static function create_reservation_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$stall_table     = $wpdb->prefix . 'en_stall_reservations';
		$rv_table        = $wpdb->prefix . 'en_rv_reservations';

		$stall_sql = "CREATE TABLE {$stall_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_source varchar(100) NOT NULL DEFAULT '',
			event_id bigint(20) unsigned DEFAULT NULL,
			reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			external_event_id varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			stall_qty int(11) unsigned NOT NULL DEFAULT 0,
			tack_stall_qty int(11) unsigned NOT NULL DEFAULT 0,
			stay_type varchar(100) NOT NULL DEFAULT '',
			arrival_date date DEFAULT NULL,
			departure_date date DEFAULT NULL,
			required_shavings_qty int(11) unsigned NOT NULL DEFAULT 0,
			additional_shavings_qty int(11) unsigned NOT NULL DEFAULT 0,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			convenience_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			tax decimal(10,2) NOT NULL DEFAULT 0.00,
			tax_rate decimal(6,3) NOT NULL DEFAULT 0.000,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			payment_status varchar(50) NOT NULL DEFAULT 'pending',
			payment_gateway varchar(50) NOT NULL DEFAULT '',
			order_number varchar(20) NOT NULL DEFAULT '',
			transaction_id varchar(191) NOT NULL DEFAULT '',
			refund_transaction_id varchar(191) NOT NULL DEFAULT '',
			refunded_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			refunded_at datetime DEFAULT NULL,
			trashed_at datetime DEFAULT NULL,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY reservation_id (reservation_id),
			KEY external_event_id (external_event_id),
			KEY payment_status (payment_status),
			KEY order_number (order_number),
			KEY created_at (created_at)
		) {$charset_collate};";

		$rv_sql = "CREATE TABLE {$rv_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_source varchar(100) NOT NULL DEFAULT '',
			event_id bigint(20) unsigned DEFAULT NULL,
			reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			external_event_id varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			rv_qty int(11) unsigned NOT NULL DEFAULT 0,
			rv_type varchar(100) NOT NULL DEFAULT '',
			stay_type varchar(100) NOT NULL DEFAULT '',
			arrival_date date DEFAULT NULL,
			departure_date date DEFAULT NULL,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			convenience_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			tax decimal(10,2) NOT NULL DEFAULT 0.00,
			tax_rate decimal(6,3) NOT NULL DEFAULT 0.000,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			payment_status varchar(50) NOT NULL DEFAULT 'pending',
			payment_gateway varchar(50) NOT NULL DEFAULT '',
			order_number varchar(20) NOT NULL DEFAULT '',
			transaction_id varchar(191) NOT NULL DEFAULT '',
			refund_transaction_id varchar(191) NOT NULL DEFAULT '',
			refunded_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			refunded_at datetime DEFAULT NULL,
			trashed_at datetime DEFAULT NULL,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY reservation_id (reservation_id),
			KEY external_event_id (external_event_id),
			KEY payment_status (payment_status),
			KEY order_number (order_number),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $stall_sql );
		dbDelta( $rv_sql );
	}

	/**
	 * Create the exported reports log table.
	 */
	private static function create_reports_log_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'en_report_exports';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			export_scope varchar(50) NOT NULL DEFAULT 'all',
			reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reservation_name varchar(191) NOT NULL DEFAULT '',
			file_name varchar(191) NOT NULL DEFAULT '',
			exported_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY reservation_id (reservation_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create the activity log table ({prefix}en_activity_log).
	 *
	 * Append-only event log used by Order Detail (ODET-7) and Stall Chart
	 * Detail (CDET-5). Idempotent via dbDelta — re-running upgrades the
	 * schema rather than recreating.
	 */
	private static function create_activity_log_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'en_activity_log';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned DEFAULT NULL,
			reservation_id bigint(20) unsigned DEFAULT NULL,
			event_type varchar(64) NOT NULL DEFAULT '',
			order_key varchar(64) NOT NULL DEFAULT '',
			payload longtext NULL,
			actor_type varchar(32) NOT NULL DEFAULT 'system',
			actor_id bigint(20) unsigned DEFAULT NULL,
			actor_label varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_created (order_id, created_at),
			KEY reservation_created (reservation_id, created_at),
			KEY event_type_created (event_type, created_at),
			KEY order_key_created (order_key, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Refresh native event rewrites when that feature is active.
	 *
	 * @return void
	 */
	private static function maybe_refresh_native_event_rewrite_rules() {
		if ( ! class_exists( 'EEM_Events' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php';
		}

		if ( ! class_exists( 'EEM_Events' ) ) {
			flush_rewrite_rules( false );
			return;
		}

		$events = new EEM_Events();
		$events->register_event_routes();

		if ( EEM_Events::is_native_events_enabled() ) {
			$events->register_content_types();
		}

		flush_rewrite_rules( false );
	}

	/**
	 * Build a rewrite signature so route changes refresh even without a version bump.
	 *
	 * @return string
	 */
	private static function get_native_event_rewrite_signature() {
		if ( ! class_exists( 'EEM_Events' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php';
		}

		if ( ! class_exists( 'EEM_Events' ) ) {
			return EQUINE_EVENT_MANAGER_VERSION . '|missing-events-class';
		}

		$enabled      = EEM_Events::is_native_events_enabled() ? 'enabled' : 'disabled';
		$slug         = EEM_Events::get_event_rewrite_slug();
		$virtual_base = EEM_Events::VIRTUAL_EVENT_ROUTE_BASE;

		return EQUINE_EVENT_MANAGER_VERSION . '|' . $enabled . '|' . $slug . '|' . $virtual_base . '|event-directory-v1';
	}
}
