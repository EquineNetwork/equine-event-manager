<?php
/**
 * Repository for Division (en_entry CPT) configuration fields.
 *
 * Decouples division config from wp_postmeta into the relational
 * wp_eem_division_config table. Static methods with in-memory cache,
 * table_exists() gate for pre-migration fallback to postmeta.
 *
 * @package EEM_Plugin
 * @since   2.7.321
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Division_Config_Repo {

	/** @var array<int, array<string,mixed>> */
	private static array $cache = array();

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'eem_division_config';
	}

	/**
	 * Create or upgrade the division config table (idempotent via dbDelta).
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::table_name() . " (
			division_id bigint(20) unsigned NOT NULL,
			reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			description text NOT NULL,
			division_name varchar(255) NOT NULL DEFAULT '',
			price decimal(10,2) NOT NULL DEFAULT 0.00,
			spots int unsigned NOT NULL DEFAULT 0,
			max_per_customer int unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (division_id),
			KEY reservation_id (reservation_id)
		) {$cc};" );
	}

	/**
	 * Whether the table exists in the database.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}
		global $wpdb;
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table_name() ) ) === self::table_name() ); // phpcs:ignore WordPress.DB
		return $exists;
	}

	/**
	 * Get all config fields for a division.
	 *
	 * @param int $division_id en_entry post ID.
	 * @return array{reservation_id:int,description:string,division_name:string,price:string,spots:int,max_per_customer:int}
	 */
	public static function get( int $division_id ): array {
		$defaults = array(
			'reservation_id'  => 0,
			'description'     => '',
			'division_name'   => '',
			'price'           => '0.00',
			'spots'           => 0,
			'max_per_customer' => 0,
		);

		if ( $division_id <= 0 ) {
			return $defaults;
		}

		if ( isset( self::$cache[ $division_id ] ) ) {
			return self::$cache[ $division_id ];
		}

		if ( self::table_exists() ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE division_id = %d',
				$division_id
			), ARRAY_A ); // phpcs:ignore WordPress.DB

			if ( is_array( $row ) ) {
				$data = array(
					'reservation_id'   => (int) $row['reservation_id'],
					'description'      => (string) $row['description'],
					'division_name'    => (string) $row['division_name'],
					'price'            => number_format( (float) $row['price'], 2, '.', '' ),
					'spots'            => (int) $row['spots'],
					'max_per_customer' => (int) $row['max_per_customer'],
				);
				self::$cache[ $division_id ] = $data;
				return $data;
			}
		}

		$data = array(
			'reservation_id'   => (int) get_post_meta( $division_id, '_en_entry_reservation_id', true ),
			'description'      => (string) get_post_meta( $division_id, '_en_entry_description', true ),
			'division_name'    => (string) get_post_meta( $division_id, '_en_division_name', true ),
			'price'            => number_format( (float) get_post_meta( $division_id, '_en_division_price', true ), 2, '.', '' ),
			'spots'            => absint( get_post_meta( $division_id, '_en_division_spots', true ) ),
			'max_per_customer' => absint( get_post_meta( $division_id, '_en_division_max', true ) ),
		);
		self::$cache[ $division_id ] = $data;
		return $data;
	}

	/**
	 * Get a single field value.
	 *
	 * @param int    $division_id en_entry post ID.
	 * @param string $field       Field name.
	 * @return mixed
	 */
	public static function get_field( int $division_id, string $field ) {
		$data = self::get( $division_id );
		return $data[ $field ] ?? null;
	}

	/**
	 * Save division config fields (REPLACE INTO for upsert).
	 *
	 * @param int                 $division_id en_entry post ID.
	 * @param array<string,mixed> $data        Field => value pairs.
	 * @return bool
	 */
	public static function save( int $division_id, array $data ): bool {
		if ( $division_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$existing = self::get( $division_id );

		$merged = array(
			'division_id'      => $division_id,
			'reservation_id'   => isset( $data['reservation_id'] ) ? absint( $data['reservation_id'] ) : $existing['reservation_id'],
			'description'      => isset( $data['description'] ) ? (string) $data['description'] : $existing['description'],
			'division_name'    => isset( $data['division_name'] ) ? (string) $data['division_name'] : $existing['division_name'],
			'price'            => isset( $data['price'] ) ? number_format( (float) $data['price'], 2, '.', '' ) : $existing['price'],
			'spots'            => isset( $data['spots'] ) ? absint( $data['spots'] ) : $existing['spots'],
			'max_per_customer' => isset( $data['max_per_customer'] ) ? absint( $data['max_per_customer'] ) : $existing['max_per_customer'],
		);

		global $wpdb;
		$result = $wpdb->replace(
			self::table_name(),
			$merged,
			array( '%d', '%d', '%s', '%s', '%f', '%d', '%d' )
		); // phpcs:ignore WordPress.DB

		unset( self::$cache[ $division_id ] );
		return false !== $result;
	}
}
