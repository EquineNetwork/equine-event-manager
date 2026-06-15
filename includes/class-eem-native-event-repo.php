<?php
/**
 * Repository for Native Event (en_event CPT) fields.
 *
 * Decouples native event data from wp_postmeta into the relational
 * wp_eem_native_events table. Static methods with in-memory cache,
 * table_exists() gate for pre-migration fallback to postmeta.
 *
 * @package EEM_Plugin
 * @since   2.7.322
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Native_Event_Repo {

	private static array $cache = array();

	const FIELDS = array(
		'start_date',
		'end_date',
		'venue_id',
		'producer_id',
		'location_label',
		'cta_label',
		'flyer_file_id',
		'flyer_url',
		'featured',
		'facebook',
		'instagram',
		'details_summary',
	);

	const META_MAP = array(
		'start_date'      => '_equine_event_manager_event_start_date',
		'end_date'        => '_equine_event_manager_event_end_date',
		'venue_id'        => '_equine_event_manager_event_venue_id',
		'producer_id'     => '_equine_event_manager_event_producer_id',
		'location_label'  => '_equine_event_manager_event_location_label',
		'cta_label'       => '_equine_event_manager_event_cta_label',
		'flyer_file_id'   => '_equine_event_manager_event_flyer_file_id',
		'flyer_url'       => '_equine_event_manager_event_flyer_url',
		'featured'        => '_equine_event_manager_event_featured',
		'facebook'        => '_en_event_facebook',
		'instagram'       => '_en_event_instagram',
		'details_summary' => '_en_event_details_summary',
	);

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'eem_native_events';
	}

	/**
	 * Create or upgrade the native events table (idempotent via dbDelta).
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::table_name() . " (
			event_id bigint(20) unsigned NOT NULL,
			start_date datetime NULL,
			end_date datetime NULL,
			venue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			producer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			location_label varchar(255) NOT NULL DEFAULT '',
			cta_label varchar(255) NOT NULL DEFAULT '',
			flyer_file_id bigint(20) unsigned NOT NULL DEFAULT 0,
			flyer_url varchar(500) NOT NULL DEFAULT '',
			featured tinyint(1) unsigned NOT NULL DEFAULT 0,
			facebook varchar(500) NOT NULL DEFAULT '',
			instagram varchar(500) NOT NULL DEFAULT '',
			details_summary text NOT NULL,
			PRIMARY KEY  (event_id),
			KEY start_date (start_date)
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
	 * Get all fields for a native event.
	 *
	 * @param int $event_id en_event post ID.
	 * @return array<string,mixed>
	 */
	public static function get( int $event_id ): array {
		$defaults = array(
			'start_date'      => '',
			'end_date'        => '',
			'venue_id'        => 0,
			'producer_id'     => 0,
			'location_label'  => '',
			'cta_label'       => '',
			'flyer_file_id'   => 0,
			'flyer_url'       => '',
			'featured'        => 0,
			'facebook'        => '',
			'instagram'       => '',
			'details_summary' => '',
		);

		if ( $event_id <= 0 ) {
			return $defaults;
		}

		if ( isset( self::$cache[ $event_id ] ) ) {
			return self::$cache[ $event_id ];
		}

		if ( self::table_exists() ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE event_id = %d',
				$event_id
			), ARRAY_A ); // phpcs:ignore WordPress.DB

			if ( is_array( $row ) ) {
				$data = self::normalize_row( $row );
				self::$cache[ $event_id ] = $data;
				return $data;
			}
		}

		$data = array(
			'start_date'      => (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true ),
			'end_date'        => (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true ),
			'venue_id'        => absint( get_post_meta( $event_id, '_equine_event_manager_event_venue_id', true ) ),
			'producer_id'     => absint( get_post_meta( $event_id, '_equine_event_manager_event_producer_id', true ) ),
			'location_label'  => (string) get_post_meta( $event_id, '_equine_event_manager_event_location_label', true ),
			'cta_label'       => (string) get_post_meta( $event_id, '_equine_event_manager_event_cta_label', true ),
			'flyer_file_id'   => absint( get_post_meta( $event_id, '_equine_event_manager_event_flyer_file_id', true ) ),
			'flyer_url'       => (string) get_post_meta( $event_id, '_equine_event_manager_event_flyer_url', true ),
			'featured'        => absint( get_post_meta( $event_id, '_equine_event_manager_event_featured', true ) ),
			'facebook'        => (string) get_post_meta( $event_id, '_en_event_facebook', true ),
			'instagram'       => (string) get_post_meta( $event_id, '_en_event_instagram', true ),
			'details_summary' => (string) get_post_meta( $event_id, '_en_event_details_summary', true ),
		);
		self::$cache[ $event_id ] = $data;
		return $data;
	}

	/**
	 * Get a single field value.
	 *
	 * @param int    $event_id en_event post ID.
	 * @param string $field    Field name.
	 * @return mixed
	 */
	public static function get_field( int $event_id, string $field ) {
		$data = self::get( $event_id );
		return $data[ $field ] ?? null;
	}

	/**
	 * Save native event fields (REPLACE INTO for upsert).
	 *
	 * @param int                 $event_id en_event post ID.
	 * @param array<string,mixed> $data     Field => value pairs.
	 * @return bool
	 */
	public static function save( int $event_id, array $data ): bool {
		if ( $event_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$existing = self::get( $event_id );

		$merged = array(
			'event_id'        => $event_id,
			'start_date'      => isset( $data['start_date'] ) ? self::sanitize_datetime( $data['start_date'] ) : self::sanitize_datetime( $existing['start_date'] ),
			'end_date'        => isset( $data['end_date'] ) ? self::sanitize_datetime( $data['end_date'] ) : self::sanitize_datetime( $existing['end_date'] ),
			'venue_id'        => isset( $data['venue_id'] ) ? absint( $data['venue_id'] ) : $existing['venue_id'],
			'producer_id'     => isset( $data['producer_id'] ) ? absint( $data['producer_id'] ) : $existing['producer_id'],
			'location_label'  => isset( $data['location_label'] ) ? (string) $data['location_label'] : $existing['location_label'],
			'cta_label'       => isset( $data['cta_label'] ) ? (string) $data['cta_label'] : $existing['cta_label'],
			'flyer_file_id'   => isset( $data['flyer_file_id'] ) ? absint( $data['flyer_file_id'] ) : $existing['flyer_file_id'],
			'flyer_url'       => isset( $data['flyer_url'] ) ? (string) $data['flyer_url'] : $existing['flyer_url'],
			'featured'        => isset( $data['featured'] ) ? absint( $data['featured'] ) : $existing['featured'],
			'facebook'        => isset( $data['facebook'] ) ? (string) $data['facebook'] : $existing['facebook'],
			'instagram'       => isset( $data['instagram'] ) ? (string) $data['instagram'] : $existing['instagram'],
			'details_summary' => isset( $data['details_summary'] ) ? (string) $data['details_summary'] : $existing['details_summary'],
		);

		global $wpdb;
		$result = $wpdb->replace(
			self::table_name(),
			$merged,
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB

		unset( self::$cache[ $event_id ] );
		return false !== $result;
	}

	/**
	 * Normalize a database row into typed values.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private static function normalize_row( array $row ): array {
		return array(
			'start_date'      => (string) ( $row['start_date'] ?? '' ),
			'end_date'        => (string) ( $row['end_date'] ?? '' ),
			'venue_id'        => (int) ( $row['venue_id'] ?? 0 ),
			'producer_id'     => (int) ( $row['producer_id'] ?? 0 ),
			'location_label'  => (string) ( $row['location_label'] ?? '' ),
			'cta_label'       => (string) ( $row['cta_label'] ?? '' ),
			'flyer_file_id'   => (int) ( $row['flyer_file_id'] ?? 0 ),
			'flyer_url'       => (string) ( $row['flyer_url'] ?? '' ),
			'featured'        => (int) ( $row['featured'] ?? 0 ),
			'facebook'        => (string) ( $row['facebook'] ?? '' ),
			'instagram'       => (string) ( $row['instagram'] ?? '' ),
			'details_summary' => (string) ( $row['details_summary'] ?? '' ),
		);
	}

	/**
	 * Sanitize a datetime string for storage. Returns NULL-safe value.
	 *
	 * @param mixed $value Input value.
	 * @return string|null
	 */
	private static function sanitize_datetime( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}
		return (string) $value;
	}
}
