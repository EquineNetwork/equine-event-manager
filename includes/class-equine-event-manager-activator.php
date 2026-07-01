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
	 * Plugin version at which the 42 one-time migrations were collapsed into the
	 * dbDelta baseline (#41). Sites upgrading from before this version without a
	 * completed legacy migration chain trip the baseline gap guard.
	 */
	const MIGRATION_BASELINE_VERSION = '2.7.736';

	/**
	 * Next stable order number option key.
	 */
	const NEXT_ORDER_NUMBER_OPTION = 'equine_event_manager_next_order_number';

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		// #45: rename the legacy wp_en_* tables to the canonical wp_eem_* prefix
		// BEFORE any create/dbDelta or one-time migration runs, so every downstream
		// step (schema verification, the token backfill, the payments ledger) sees
		// the canonical names. Idempotent: only renames when the old table exists
		// and the new one doesn't, so it's a no-op on fresh installs + re-runs.
		self::rename_legacy_en_tables();
		self::create_reservation_tables();
		self::create_reports_log_table();
		self::create_activity_log_table();
		self::create_event_defaults_table();
		self::create_order_adjustments_table();
		self::create_order_payments_table();
		if ( class_exists( 'EEM_Unit_Holds_Repo' ) ) {
			EEM_Unit_Holds_Repo::create_table();
			// A8 — schedule the hourly expired-holds sweep (idempotent).
			EEM_Unit_Holds_Repo::schedule_cleanup();
		}
		// #23 — schedule the daily payment-reminder sweep (idempotent; the sweep
		// itself no-ops until the feature is explicitly enabled in options).
		if ( class_exists( 'EEM_Payment_Reminder' ) ) {
			EEM_Payment_Reminder::schedule();
		}
		if ( class_exists( 'EEM_Division_Entries' ) ) {
			EEM_Division_Entries::create_table();
		}
		if ( class_exists( 'EEM_Venue' ) ) {
			EEM_Venue::create_tables();
		}
		if ( class_exists( 'EEM_Sheet_Entries' ) ) {
			EEM_Sheet_Entries::create_table();
		}
		if ( class_exists( 'EEM_Reservation_Config' ) ) {
			EEM_Reservation_Config::create_table();
		}
		if ( class_exists( 'EEM_Producer_Repo' ) ) {
			EEM_Producer_Repo::create_table();
		}
		if ( class_exists( 'EEM_Division_Config_Repo' ) ) {
			EEM_Division_Config_Repo::create_table();
		}
		if ( class_exists( 'EEM_Native_Event_Repo' ) ) {
			EEM_Native_Event_Repo::create_table();
		}
		if ( class_exists( 'EEM_Stall_Status_Repo' ) ) {
			EEM_Stall_Status_Repo::create_tables();
			EEM_Order_Documents::create_table();
		}
		self::maybe_refresh_native_event_rewrite_rules();
		self::run_one_time_migrations();
		update_option( self::DB_VERSION_OPTION, EQUINE_EVENT_MANAGER_VERSION );
		update_option( self::NATIVE_EVENT_REWRITE_VERSION_OPTION, self::get_native_event_rewrite_signature() );
	}

	/**
	 * Rename the five legacy `wp_en_*` tables to the canonical `wp_eem_*` prefix
	 * (#45). Runs first in activate() so schema verification + one-time migrations
	 * all operate on the canonical names. Idempotent + lossless: a table is renamed
	 * only when the old name exists and the new one does not, so re-runs and fresh
	 * installs (where dbDelta already created the wp_eem_* tables) are no-ops.
	 *
	 * @return void
	 */
	private static function rename_legacy_en_tables(): void {
		global $wpdb;

		$renames = array(
			'en_stall_reservations' => 'eem_stall_reservations',
			'en_rv_reservations'    => 'eem_rv_reservations',
			'en_activity_log'       => 'eem_activity_log',
			'en_report_exports'     => 'eem_report_exports',
			'en_order_adjustments'  => 'eem_order_adjustments',
		);

		foreach ( $renames as $old => $new ) {
			$old_table  = $wpdb->prefix . $old;
			$new_table  = $wpdb->prefix . $new;
			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );

			if ( $old_exists && ! $new_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers derived from $wpdb->prefix, not user input.
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
			}
		}
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
	 * Create the order adjustments table ({prefix}eem_order_adjustments).
	 *
	 * Stores order-level adjustments that don't fit the component-row model
	 * (eem_stall_reservations / eem_rv_reservations): custom line items (one-off
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
		$table_name      = $wpdb->prefix . 'eem_order_adjustments';

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
	 * Create the order payments ledger table.
	 *
	 * Records every individual payment AND refund event against an order so a single
	 * order settled by more than one tender (e.g. part card, part cash) is represented
	 * faithfully — the component rows only carry one payment_gateway/transaction_id
	 * each. Owned by EEM_Order_Payments_Repo. Additive: existing orders get their
	 * ledger backfilled from the component columns by eem-mig-041, so no data is lost;
	 * dbDelta on the post-version-change upgrade pass creates the table on existing
	 * installs.
	 *
	 * Columns:
	 *  - direction    — 'payment' (collected) | 'refund' (returned).
	 *  - method       — human tender ('Cash', 'Check', 'card', ...).
	 *  - gateway      — 'stripe' | 'authorize_net' | 'manual' | ''.
	 *  - amount       — always positive; direction carries the sign meaning.
	 *  - transaction_id / reference — processor id / check number / card last4.
	 *  - reason       — refund reason (refund rows).
	 *
	 * @return void
	 */
	private static function create_order_payments_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'eem_order_payments';

		// order_key holds the order's submission token (a 32-char hash), matching the
		// key the component rows + adjustments table group by.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_key varchar(191) NOT NULL DEFAULT '',
			direction varchar(10) NOT NULL DEFAULT 'payment',
			method varchar(50) NOT NULL DEFAULT '',
			gateway varchar(50) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			transaction_id varchar(191) NOT NULL DEFAULT '',
			reference varchar(191) NOT NULL DEFAULT '',
			reason varchar(255) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_key (order_key),
			KEY direction (direction)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * One-time-migration baseline guard (#41).
	 *
	 * The 42 one-time migrations (#001–#042) that this method used to run were
	 * collapsed into the dbDelta CREATE TABLE baseline as of
	 * {@see self::MIGRATION_BASELINE_VERSION}. Their schema is now reproduced by the
	 * create_*() methods alone — proven by tests/schema-baseline-drift-check.php,
	 * which reports 0 drift between the dbDelta baseline and the fully-migrated live
	 * schema. Their DATA backfills already ran on every existing install (each set its
	 * own wp_options flag) and are no-ops on fresh installs (there is no legacy data to
	 * transform).
	 *
	 * This therefore no longer runs any migration. It only guards the ONE unsafe case
	 * the collapse introduces: a site that upgrades ACROSS the collapse boundary —
	 * i.e. it carries existing data but never finished the pre-collapse migration chain
	 * — would be missing a data backfill that no longer ships. That case cannot occur
	 * on a fresh install or an already-current site; the guard detects it and records a
	 * flag so {@see self::render_migration_baseline_notice()} can warn the operator to
	 * reinstall the last pre-collapse release (<= 2.7.735), let it finish migrating,
	 * then upgrade again. Schema is already correct via dbDelta, so this is a DATA
	 * integrity guard, not a fatal.
	 *
	 * @return void
	 */
	private static function run_one_time_migrations(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		// Fresh install: dbDelta just built the complete baseline schema and there is
		// no legacy data to backfill. Record the baseline and return.
		if ( '' === $installed ) {
			update_option( 'eem_migration_baseline_complete', self::MIGRATION_BASELINE_VERSION, false );
			return;
		}

		// Safe upgrade paths:
		//  - the final pre-collapse migration flag (#042) is set → the full legacy
		//    chain completed before this upgrade;
		//  - the installed version is already at/after the baseline → this site was
		//    activated on a collapsed build already;
		//  - the baseline-complete marker is already recorded.
		if ( get_option( 'eem_mig_042_backfill_order_tokens_complete' )
			|| version_compare( $installed, self::MIGRATION_BASELINE_VERSION, '>=' )
			|| get_option( 'eem_migration_baseline_complete' ) ) {
			update_option( 'eem_migration_baseline_complete', self::MIGRATION_BASELINE_VERSION, false );
			return;
		}

		// Pre-baseline site that crossed the collapse boundary without finishing the
		// legacy chain. The one-time backfills no longer ship, so flag for the notice.
		update_option( 'eem_migration_baseline_gap', 1, false );
	}

	/**
	 * Admin notice shown when {@see self::run_one_time_migrations()} detected a site
	 * that upgraded across the #41 migration-collapse boundary without completing the
	 * legacy migration chain. Hooked to `admin_notices` from the main plugin class.
	 *
	 * @return void
	 */
	public static function render_migration_baseline_notice(): void {
		if ( ! get_option( 'eem_migration_baseline_gap' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Equine Event Manager: this site was upgraded from a version older than 2.7.736 without completing its data migrations. Please reinstall version 2.7.735 or earlier, let it finish, then upgrade again to avoid missing order data.', 'equine-event-manager' );
		echo '</p></div>';
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

		// Best-effort OPcache flush on every version change. On WP Engine the
		// in-WP "Clear all caches" does NOT reset PHP OPcache, so a freshly
		// updated plugin can serve stale bytecode until the cache TTL expires.
		// Guarded with function_exists; a silent no-op where the host restricts
		// or disables the API (managed hosts often do).
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; restricted hosts return false.
		}
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
		$stall_table     = $wpdb->prefix . 'eem_stall_reservations';
		$rv_table        = $wpdb->prefix . 'eem_rv_reservations';

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
			additional_shavings_items text NULL DEFAULT NULL,
			selected_package_ids json NULL,
			effective_start_date date DEFAULT NULL,
			effective_end_date date DEFAULT NULL,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			convenience_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			tax decimal(10,2) NOT NULL DEFAULT 0.00,
			tax_rate decimal(6,3) NOT NULL DEFAULT 0.000,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
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
			selected_package_ids json NULL,
			effective_start_date date DEFAULT NULL,
			effective_end_date date DEFAULT NULL,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			convenience_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			tax decimal(10,2) NOT NULL DEFAULT 0.00,
			tax_rate decimal(6,3) NOT NULL DEFAULT 0.000,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
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
		$table_name      = $wpdb->prefix . 'eem_report_exports';

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
	 * Create the activity log table ({prefix}eem_activity_log).
	 *
	 * Append-only event log used by Order Detail (ODET-7) and Stall Chart
	 * Detail (CDET-5). Idempotent via dbDelta — re-running upgrades the
	 * schema rather than recreating.
	 */
	private static function create_activity_log_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'eem_activity_log';

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
