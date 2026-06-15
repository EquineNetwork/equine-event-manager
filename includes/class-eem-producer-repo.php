<?php
/**
 * Producer Repository.
 *
 * Reads/writes producer detail fields (contact name, email, phone, website)
 * from `wp_eem_producers`. Falls back to postmeta when the table doesn't
 * exist yet (pre-migration).
 *
 * @package EEM_Plugin
 * @since   2.7.319
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Producer_Repo {

	/**
	 * Canonical meta key prefix used in postmeta (fallback path).
	 */
	const META_PREFIX = '_equine_event_manager_producer_';

	/**
	 * Fields stored in the producers table.
	 */
	const FIELDS = array(
		'contact_name',
		'email',
		'phone',
		'website',
		'imported_tec_organizer_id',
	);

	/**
	 * In-memory cache keyed by producer post ID.
	 *
	 * @var array<int,array<string,string>>
	 */
	private static array $cache = array();

	/**
	 * Create the wp_eem_producers table. Idempotent via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();

		$sql = "CREATE TABLE {$table} (
			producer_id bigint(20) unsigned NOT NULL,
			contact_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			website varchar(500) NOT NULL DEFAULT '',
			imported_tec_organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (producer_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Full table name including WP prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'eem_producers';
	}

	/**
	 * Whether the producers table exists in the database.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Flush the in-memory cache for a specific producer (or all).
	 *
	 * @param int|null $producer_id Null flushes all.
	 * @return void
	 */
	public static function flush_cache( ?int $producer_id = null ): void {
		if ( null === $producer_id ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ $producer_id ] );
		}
	}

	/**
	 * Get all detail fields for a producer.
	 *
	 * @param int $producer_id Producer post ID.
	 * @return array{contact_name:string,email:string,phone:string,website:string,imported_tec_organizer_id:int}
	 */
	public static function get( int $producer_id ): array {
		if ( isset( self::$cache[ $producer_id ] ) ) {
			return self::$cache[ $producer_id ];
		}

		$data = self::table_exists()
			? self::read_from_table( $producer_id )
			: self::read_from_postmeta( $producer_id );

		self::$cache[ $producer_id ] = $data;
		return $data;
	}

	/**
	 * Get a single field value.
	 *
	 * @param int    $producer_id Producer post ID.
	 * @param string $field       One of the FIELDS constants.
	 * @return string|int
	 */
	public static function get_field( int $producer_id, string $field ) {
		$data = self::get( $producer_id );
		return $data[ $field ] ?? '';
	}

	/**
	 * Save producer detail fields. Inserts or updates.
	 *
	 * @param int                    $producer_id Producer post ID.
	 * @param array<string,string>   $fields      Associative array of field => value.
	 * @return void
	 */
	public static function save( int $producer_id, array $fields ): void {
		if ( self::table_exists() ) {
			self::write_to_table( $producer_id, $fields );
		} else {
			self::write_to_postmeta( $producer_id, $fields );
		}
		self::flush_cache( $producer_id );
	}

	/**
	 * Read from the relational table.
	 *
	 * @param int $producer_id Producer post ID.
	 * @return array<string,string|int>
	 */
	private static function read_from_table( int $producer_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT contact_name, email, phone, website, imported_tec_organizer_id FROM ' . self::table_name() . ' WHERE producer_id = %d',
				$producer_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return self::defaults();
		}

		$row['imported_tec_organizer_id'] = (int) $row['imported_tec_organizer_id'];
		return $row;
	}

	/**
	 * Fallback: read from wp_postmeta.
	 *
	 * @param int $producer_id Producer post ID.
	 * @return array<string,string|int>
	 */
	private static function read_from_postmeta( int $producer_id ): array {
		return array(
			'contact_name'              => (string) get_post_meta( $producer_id, self::META_PREFIX . 'contact_name', true ),
			'email'                     => (string) get_post_meta( $producer_id, self::META_PREFIX . 'email', true ),
			'phone'                     => (string) get_post_meta( $producer_id, self::META_PREFIX . 'phone', true ),
			'website'                   => (string) get_post_meta( $producer_id, self::META_PREFIX . 'website', true ),
			'imported_tec_organizer_id' => (int) get_post_meta( $producer_id, '_equine_event_manager_imported_tec_organizer_id', true ),
		);
	}

	/**
	 * Write to the relational table (REPLACE INTO for upsert).
	 *
	 * @param int                  $producer_id Producer post ID.
	 * @param array<string,string> $fields      Fields to write.
	 * @return void
	 */
	private static function write_to_table( int $producer_id, array $fields ): void {
		global $wpdb;

		$existing = self::read_from_table( $producer_id );
		$merged   = array_merge( $existing, $fields );

		$wpdb->replace(
			self::table_name(),
			array(
				'producer_id'               => $producer_id,
				'contact_name'              => (string) ( $merged['contact_name'] ?? '' ),
				'email'                     => (string) ( $merged['email'] ?? '' ),
				'phone'                     => (string) ( $merged['phone'] ?? '' ),
				'website'                   => (string) ( $merged['website'] ?? '' ),
				'imported_tec_organizer_id' => (int) ( $merged['imported_tec_organizer_id'] ?? 0 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Fallback: write to wp_postmeta.
	 *
	 * @param int                  $producer_id Producer post ID.
	 * @param array<string,string> $fields      Fields to write.
	 * @return void
	 */
	private static function write_to_postmeta( int $producer_id, array $fields ): void {
		foreach ( $fields as $key => $value ) {
			if ( 'imported_tec_organizer_id' === $key ) {
				update_post_meta( $producer_id, '_equine_event_manager_imported_tec_organizer_id', $value );
			} else {
				update_post_meta( $producer_id, self::META_PREFIX . $key, $value );
			}
		}
	}

	/**
	 * Default empty field set.
	 *
	 * @return array<string,string|int>
	 */
	private static function defaults(): array {
		return array(
			'contact_name'              => '',
			'email'                     => '',
			'phone'                     => '',
			'website'                   => '',
			'imported_tec_organizer_id' => 0,
		);
	}
}
