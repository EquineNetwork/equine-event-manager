<?php
/**
 * Plugin activation tasks.
 *
 * @package Equine_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation tasks for Equine Event Manager.
 */
class Equine_Event_Manager_Activator {

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
		self::maybe_refresh_native_event_rewrite_rules();
		update_option( self::DB_VERSION_OPTION, EQUINE_EVENT_MANAGER_VERSION );
		update_option( self::NATIVE_EVENT_REWRITE_VERSION_OPTION, self::get_native_event_rewrite_signature() );
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
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			payment_status varchar(50) NOT NULL DEFAULT 'pending',
			payment_gateway varchar(50) NOT NULL DEFAULT '',
			order_number varchar(20) NOT NULL DEFAULT '',
			transaction_id varchar(191) NOT NULL DEFAULT '',
			refund_transaction_id varchar(191) NOT NULL DEFAULT '',
			refunded_at datetime DEFAULT NULL,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY external_event_id (external_event_id),
			KEY payment_status (payment_status),
			KEY order_number (order_number),
			KEY created_at (created_at)
		) {$charset_collate};";

		$rv_sql = "CREATE TABLE {$rv_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_source varchar(100) NOT NULL DEFAULT '',
			event_id bigint(20) unsigned DEFAULT NULL,
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
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			payment_status varchar(50) NOT NULL DEFAULT 'pending',
			payment_gateway varchar(50) NOT NULL DEFAULT '',
			order_number varchar(20) NOT NULL DEFAULT '',
			transaction_id varchar(191) NOT NULL DEFAULT '',
			refund_transaction_id varchar(191) NOT NULL DEFAULT '',
			refunded_at datetime DEFAULT NULL,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
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
	 * Refresh native event rewrites when that feature is active.
	 *
	 * @return void
	 */
	private static function maybe_refresh_native_event_rewrite_rules() {
		if ( ! class_exists( 'Equine_Event_Manager_Events' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php';
		}

		if ( ! class_exists( 'Equine_Event_Manager_Events' ) ) {
			flush_rewrite_rules( false );
			return;
		}

		$events = new Equine_Event_Manager_Events();
		$events->register_event_routes();

		if ( Equine_Event_Manager_Events::is_native_events_enabled() ) {
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
		if ( ! class_exists( 'Equine_Event_Manager_Events' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php';
		}

		if ( ! class_exists( 'Equine_Event_Manager_Events' ) ) {
			return EQUINE_EVENT_MANAGER_VERSION . '|missing-events-class';
		}

		$enabled      = Equine_Event_Manager_Events::is_native_events_enabled() ? 'enabled' : 'disabled';
		$slug         = Equine_Event_Manager_Events::get_event_rewrite_slug();
		$virtual_base = Equine_Event_Manager_Events::VIRTUAL_EVENT_ROUTE_BASE;

		return EQUINE_EVENT_MANAGER_VERSION . '|' . $enabled . '|' . $slug . '|' . $virtual_base . '|event-directory-v1';
	}
}
