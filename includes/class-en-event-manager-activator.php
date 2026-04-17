<?php
/**
 * Plugin activation tasks.
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation tasks for EN Event Manager.
 */
class EN_Event_Manager_Activator {

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		self::create_reservation_tables();
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
			transaction_id varchar(191) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY external_event_id (external_event_id),
			KEY payment_status (payment_status),
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
			stay_type varchar(100) NOT NULL DEFAULT '',
			arrival_date date DEFAULT NULL,
			departure_date date DEFAULT NULL,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			convenience_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			payment_status varchar(50) NOT NULL DEFAULT 'pending',
			transaction_id varchar(191) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY external_event_id (external_event_id),
			KEY payment_status (payment_status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $stall_sql );
		dbDelta( $rv_sql );
	}

}
