<?php
/**
 * Reservation Configuration Repository.
 *
 * Single access point for all reservation config data (~120 keys). Phase 1
 * backs onto wp_postmeta via the existing key-resolver; Phase 2 swaps to
 * relational tables. Callers never change.
 *
 * Usage:
 *   $cfg = EEM_Reservation_Config::for( $reservation_id );
 *   $cfg->get( 'stall_nightly_rate' );    // typed read
 *   $cfg->set( 'stall_nightly_rate', '25.00' );
 *   $cfg->save();                         // batched write
 *
 * @package EEM_Plugin
 * @since   2.7.310
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Reservation_Config {

	/**
	 * Reservation post ID this config belongs to.
	 *
	 * @var int
	 */
	private int $reservation_id;

	/**
	 * Hydrated config values (short keys → values).
	 *
	 * @var array<string,mixed>
	 */
	private array $data = array();

	/**
	 * Keys that have been modified via set() since hydration.
	 *
	 * @var array<string,true>
	 */
	private array $dirty = array();

	/**
	 * Whether hydration has occurred.
	 *
	 * @var bool
	 */
	private bool $hydrated = false;

	/**
	 * In-memory instance cache keyed by reservation ID.
	 *
	 * @var array<int,self>
	 */
	private static array $cache = array();

	/**
	 * Private constructor — use ::for() factory.
	 *
	 * @param int $reservation_id Reservation post ID.
	 */
	private function __construct( int $reservation_id ) {
		$this->reservation_id = $reservation_id;
	}

	/**
	 * Factory — returns a hydrated config instance for a reservation.
	 *
	 * Instances are cached per request so multiple callers share one hydration.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return self
	 */
	public static function for( int $reservation_id ): self {
		if ( isset( self::$cache[ $reservation_id ] ) ) {
			return self::$cache[ $reservation_id ];
		}

		$instance = new self( $reservation_id );
		$instance->hydrate();
		self::$cache[ $reservation_id ] = $instance;

		return $instance;
	}

	/**
	 * Clear the in-memory instance cache (useful after bulk operations or tests).
	 *
	 * @param int|null $reservation_id Clear a specific ID, or all if null.
	 */
	public static function flush_cache( ?int $reservation_id = null ): void {
		if ( null === $reservation_id ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ $reservation_id ] );
		}
	}

	/**
	 * Get a config value by short key.
	 *
	 * @param string $key   Short key (e.g. 'stall_nightly_rate', 'stalls_enabled').
	 * @param mixed  $fallback Value to return when the key doesn't exist in the manifest.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		if ( ! $this->hydrated ) {
			$this->hydrate();
		}

		if ( array_key_exists( $key, $this->data ) ) {
			return $this->data[ $key ];
		}

		return $fallback;
	}

	/**
	 * Stage a config value for writing. Call save() to persist.
	 *
	 * @param string $key   Short key.
	 * @param mixed  $value New value.
	 * @return self Fluent.
	 */
	public function set( string $key, $value ): self {
		if ( ! $this->hydrated ) {
			$this->hydrate();
		}

		$this->data[ $key ]  = $value;
		$this->dirty[ $key ] = true;

		return $this;
	}

	/**
	 * Bulk-set multiple keys at once.
	 *
	 * @param array<string,mixed> $values Key-value pairs.
	 * @return self Fluent.
	 */
	public function set_many( array $values ): self {
		foreach ( $values as $key => $value ) {
			$this->set( $key, $value );
		}
		return $this;
	}

	/**
	 * Persist all dirty keys to storage (postmeta in Phase 1).
	 *
	 * @return bool True if at least one key was written.
	 */
	public function save(): bool {
		if ( empty( $this->dirty ) ) {
			return false;
		}

		foreach ( $this->dirty as $key => $_ ) {
			$meta_key = EEM_Reservations_CPT::section_enabled_meta_key( $key );
			$value    = $this->data[ $key ] ?? '';

			if ( is_array( $value ) ) {
				update_post_meta( $this->reservation_id, $meta_key, $value );
			} else {
				update_post_meta( $this->reservation_id, $meta_key, $value );
			}
		}

		// Phase 2 dual-write: mirror full state to relational table.
		if ( self::table_exists() ) {
			self::insert_from_values( $this->reservation_id, $this->data );
		}

		$this->dirty = array();
		return true;
	}

	/**
	 * Return the full hydrated data array (read-only snapshot).
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( ! $this->hydrated ) {
			$this->hydrate();
		}

		return $this->data;
	}

	/**
	 * The reservation post ID.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->reservation_id;
	}

	/**
	 * Whether any keys have been modified since hydration.
	 *
	 * @return bool
	 */
	public function is_dirty(): bool {
		return ! empty( $this->dirty );
	}

	/**
	 * Return the canonical default values for all config keys.
	 *
	 * Delegates to EEM_Reservations_CPT::get_default_meta_values() so
	 * the manifest stays in one place.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$cpt = self::get_cpt_instance();
		return $cpt->get_default_meta_values_public();
	}

	// ------------------------------------------------------------------
	// Hydration (Phase 2: relational table preferred, postmeta fallback)
	// ------------------------------------------------------------------

	/**
	 * Hydrate config from the best available source.
	 *
	 * Phase 2: reads from the relational table when it exists and has a
	 * row for this reservation. Falls back to postmeta (Phase 1 path)
	 * for reservations not yet migrated or when the table doesn't exist.
	 */
	private function hydrate(): void {
		if ( self::table_exists() && $this->hydrate_from_table() ) {
			$this->hydrated = true;
			return;
		}

		$cpt          = self::get_cpt_instance();
		$this->data   = $cpt->get_meta_values( $this->reservation_id );
		$this->hydrated = true;
	}

	/**
	 * Read config from the relational table.
	 *
	 * @return bool True if a row was found and hydrated.
	 */
	private function hydrate_from_table(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'eem_reservation_config';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE reservation_id = %d", $this->reservation_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		$col_map  = self::column_map();
		$json_set = array_flip( self::json_keys() );
		$data     = array();

		foreach ( $row as $key => $value ) {
			if ( 'reservation_id' === $key || 'updated_at' === $key ) {
				continue;
			}

			if ( 'extra_json' === $key ) {
				if ( ! empty( $value ) ) {
					$extra = json_decode( $value, true );
					if ( is_array( $extra ) ) {
						$data = array_merge( $data, $extra );
					}
				}
				continue;
			}

			if ( isset( $json_set[ $key ] ) ) {
				$data[ $key ] = ( null !== $value && '' !== $value ) ? json_decode( $value, true ) : array();
				if ( ! is_array( $data[ $key ] ) ) {
					$data[ $key ] = array();
				}
				continue;
			}

			if ( isset( $col_map[ $key ] ) ) {
				$data[ $key ] = self::cast_from_db( $value, $col_map[ $key ] );
				continue;
			}

			$data[ $key ] = $value;
		}

		// Merge defaults for any keys not present in the row.
		$defaults   = self::defaults();
		$this->data = array_merge( $defaults, $data );

		return true;
	}

	/**
	 * Cast a DB value back to the PHP type callers expect.
	 *
	 * @param mixed  $value DB value (always string from wpdb).
	 * @param string $type  SQL column type from column_map().
	 * @return mixed
	 */
	private static function cast_from_db( $value, string $type ) {
		if ( null === $value ) {
			if ( str_starts_with( $type, 'tinyint' ) || str_starts_with( $type, 'int' ) || str_starts_with( $type, 'bigint' ) ) {
				return 0;
			}
			if ( str_starts_with( $type, 'decimal' ) ) {
				return '0.00';
			}
			return '';
		}

		if ( str_starts_with( $type, 'tinyint' ) || str_starts_with( $type, 'int' ) || str_starts_with( $type, 'bigint' ) ) {
			return (int) $value;
		}

		return (string) $value;
	}

	/**
	 * Get or create the singleton CPT instance used for delegation.
	 *
	 * @return EEM_Reservations_CPT
	 */
	private static function get_cpt_instance(): EEM_Reservations_CPT {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new EEM_Reservations_CPT();
		}
		return $instance;
	}

	// ------------------------------------------------------------------
	// Query helpers (replaces meta_query lookups)
	// ------------------------------------------------------------------

	/**
	 * Find reservation IDs by event.
	 *
	 * Phase 1: delegates to WP_Query with meta_query.
	 * Phase 2: becomes a simple SQL query against the config table.
	 *
	 * @param int          $event_id     Event post ID.
	 * @param string|array $post_status  Post status filter (default 'publish').
	 * @return int[] Reservation post IDs.
	 */
	public static function for_event( int $event_id, $post_status = 'publish' ): array {
		if ( self::table_exists() ) {
			return self::query_table( array( 'event_id' => (string) $event_id ), $post_status );
		}

		$query = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => $post_status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_en_event_id',
					'value' => $event_id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Find reservation IDs by event source type.
	 *
	 * @param string $source  Event source ('tec', 'feed', 'native').
	 * @param string $status  Post status filter.
	 * @return int[]
	 */
	public static function for_source( string $source, string $status = 'publish' ): array {
		if ( self::table_exists() ) {
			return self::query_table( array( 'event_source' => sanitize_key( $source ) ), $status );
		}

		$query = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => $status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_en_event_source',
					'value' => sanitize_key( $source ),
				),
			),
		) );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Check whether a reservation has stalls enabled.
	 *
	 * Convenience method — avoids hydrating the full config when only
	 * the boolean is needed.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return bool
	 */
	public static function stalls_enabled( int $reservation_id ): bool {
		return EEM_Reservations_CPT::section_enabled( $reservation_id, 'stalls_enabled' );
	}

	/**
	 * Check whether a reservation has RV enabled.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return bool
	 */
	public static function rv_enabled( int $reservation_id ): bool {
		return EEM_Reservations_CPT::section_enabled( $reservation_id, 'rv_enabled' );
	}

	/**
	 * Find reservation IDs by event ID AND source type.
	 *
	 * Phase 1: meta_query on _en_event_id + _en_event_source.
	 * Phase 2: single indexed query on the config table.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $source   Event source ('tec', 'feed', 'native').
	 * @param string $status   Post status filter ('any', 'publish', etc.).
	 * @return int[]
	 */
	public static function for_event_and_source( int $event_id, string $source, string $status = 'any' ): array {
		if ( self::table_exists() ) {
			return self::query_table(
				array( 'event_id' => (string) $event_id, 'event_source' => sanitize_key( $source ) ),
				$status
			);
		}

		$query = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => $status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => '_en_event_id', 'value' => (string) $event_id ),
				array( 'key' => '_en_event_source', 'value' => sanitize_key( $source ) ),
			),
		) );

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Find reservation IDs with stalls and/or RV enabled.
	 *
	 * Handles the CLEANUP #44 dual-key compat pattern: checks both the
	 * canonical _eem_section_enabled_* key and the legacy _en_* key.
	 *
	 * Phase 1: meta_query with OR relation across both key formats.
	 * Phase 2: simple column filter on the config table.
	 *
	 * @param string|string[] $post_status Post status(es) to include.
	 * @param int             $limit       Max results (default 200).
	 * @return int[]
	 */
	public static function with_stalls_or_rv( $post_status = 'publish', int $limit = 200 ): array {
		if ( self::table_exists() ) {
			global $wpdb;
			$table   = $wpdb->prefix . 'eem_reservation_config';
			$statuses = (array) $post_status;
			if ( in_array( 'any', $statuses, true ) ) {
				$status_clause = "p.post_status NOT IN ('auto-draft','inherit')";
			} else {
				$placeholders  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
				$status_clause = $wpdb->prepare( "p.post_status IN ($placeholders)", ...$statuses );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				"SELECT c.reservation_id FROM {$table} c
				 INNER JOIN {$wpdb->posts} p ON p.ID = c.reservation_id
				 WHERE (c.stalls_enabled = 1 OR c.rv_enabled = 1)
				 AND p.post_type = 'en_reservation'
				 AND {$status_clause}
				 LIMIT {$limit}"
			);

			return array_map( 'intval', $ids );
		}

		$query = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => $post_status,
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
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
		) );

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Find a single reservation for a TEC event with a specific section enabled.
	 *
	 * Used by the customer shortcode to find the reservation backing a
	 * TEC event's stall or RV page.
	 *
	 * @param int    $event_id TEC event post ID.
	 * @param string $section  'stalls_enabled' or 'rv_enabled'.
	 * @return int Reservation post ID, or 0 if none found.
	 */
	public static function for_tec_event_with_section( int $event_id, string $section ): int {
		$field = in_array( $section, array( 'stalls_enabled', 'rv_enabled' ), true ) ? $section : 'stalls_enabled';

		if ( self::table_exists() ) {
			global $wpdb;
			$table = $wpdb->prefix . 'eem_reservation_config';

			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT c.reservation_id FROM {$table} c
				 INNER JOIN {$wpdb->posts} p ON p.ID = c.reservation_id
				 WHERE c.event_source = 'tec'
				 AND c.event_id = %s
				 AND c.{$field} = 1
				 AND p.post_type = 'en_reservation'
				 AND p.post_status = 'publish'
				 LIMIT 1",
				(string) $event_id
			) );

			return $id ? absint( $id ) : 0;
		}

		$query = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_en_event_source',
					'value'   => 'tec',
					'compare' => '=',
				),
				array(
					'key'     => '_en_event_id',
					'value'   => $event_id,
					'compare' => '=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => EEM_Reservations_CPT::section_enabled_meta_key( $field ),
						'value'   => 1,
						'compare' => '=',
					),
					array(
						'key'     => '_en_' . $field,
						'value'   => 1,
						'compare' => '=',
					),
				),
			),
		) );

		return ! empty( $query->posts ) ? absint( $query->posts[0] ) : 0;
	}

	/**
	 * Query the config table for reservation IDs matching column conditions.
	 *
	 * @param array<string,string> $where   Column => value pairs (AND).
	 * @param string|string[]      $post_status Post status filter.
	 * @param int                  $limit   Max results (0 = unlimited).
	 * @return int[]
	 */
	private static function query_table( array $where, $post_status = 'publish', int $limit = 0 ): array {
		global $wpdb;
		$table    = $wpdb->prefix . 'eem_reservation_config';
		$statuses = (array) $post_status;

		if ( in_array( 'any', $statuses, true ) ) {
			$status_clause = "p.post_status NOT IN ('auto-draft','inherit')";
		} else {
			$placeholders  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$status_clause = $wpdb->prepare( "p.post_status IN ($placeholders)", ...$statuses );
		}

		$conditions = array();
		$values     = array();
		foreach ( $where as $col => $val ) {
			$conditions[] = "c.{$col} = %s";
			$values[]     = $val;
		}
		$where_sql = implode( ' AND ', $conditions );

		$sql = "SELECT c.reservation_id FROM {$table} c
				INNER JOIN {$wpdb->posts} p ON p.ID = c.reservation_id
				WHERE {$where_sql}
				AND p.post_type = 'en_reservation'
				AND {$status_clause}";

		if ( $limit > 0 ) {
			$sql .= " LIMIT {$limit}";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$values ) );

		return array_map( 'intval', $ids );
	}

	// ------------------------------------------------------------------
	// Phase 2: Relational table (schema + dual-write)
	// ------------------------------------------------------------------

	/**
	 * Column map: short config key → SQL column definition.
	 *
	 * Scalar keys become typed columns; array/blob keys become JSON columns.
	 * Keys not listed here are stored in the catch-all `extra_json` column.
	 *
	 * @return array<string,string> key → column type fragment
	 */
	private static function column_map(): array {
		return array(
			// Event linkage.
			'use_global_event_source'        => 'tinyint(1)',
			'event_source'                   => 'varchar(32)',
			'event_id'                       => 'varchar(191)',
			'event_feed_url'                 => 'varchar(2048)',
			'external_event_name'            => 'varchar(255)',
			'external_event_id'              => 'varchar(191)',
			// Section toggles.
			'stalls_enabled'                 => 'tinyint(1)',
			'rv_enabled'                     => 'tinyint(1)',
			// Selection modes.
			'stall_selection_mode'           => 'varchar(32)',
			'stall_inventory_type'           => 'varchar(32)',
			'stall_customer_selection'       => 'varchar(32)',
			'rv_selection_mode'              => 'varchar(32)',
			'rv_inventory_type'              => 'varchar(32)',
			'rv_customer_selection'          => 'varchar(32)',
			// Stay-type toggles.
			'nightly_enabled'                => 'tinyint(1)',
			'weekend_enabled'                => 'tinyint(1)',
			'weekly_enabled'                 => 'tinyint(1)',
			'stall_nightly_enabled'          => 'tinyint(1)',
			'stall_weekend_enabled'          => 'tinyint(1)',
			'stall_weekly_enabled'           => 'tinyint(1)',
			'rv_nightly_enabled'             => 'tinyint(1)',
			'rv_weekend_enabled'             => 'tinyint(1)',
			'rv_weekly_enabled'              => 'tinyint(1)',
			// Dates.
			'available_start_date'           => 'varchar(20)',
			'available_end_date'             => 'varchar(20)',
			'weekend_package_start_date'     => 'varchar(20)',
			'weekend_package_end_date'       => 'varchar(20)',
			'stall_weekend_package_start_date' => 'varchar(20)',
			'stall_weekend_package_end_date'   => 'varchar(20)',
			'rv_weekend_package_start_date'    => 'varchar(20)',
			'rv_weekend_package_end_date'      => 'varchar(20)',
			'stall_weekly_package_start_date'  => 'varchar(20)',
			'stall_weekly_package_end_date'    => 'varchar(20)',
			'rv_weekly_package_start_date'     => 'varchar(20)',
			'rv_weekly_package_end_date'       => 'varchar(20)',
			'available_dates_manually_edited'  => 'tinyint(1)',
			'sync_stay_selections'             => 'tinyint(1)',
			// Stall descriptions / schedule.
			'stall_description'              => 'text',
			'stall_schedule_enabled'         => 'tinyint(1)',
			'stalls_open_at'                 => 'varchar(10)',
			'stalls_close_at'                => 'varchar(10)',
			'stall_inventory'                => 'varchar(20)',
			// RV descriptions / schedule.
			'rv_description'                 => 'text',
			'rv_schedule_enabled'            => 'tinyint(1)',
			'rv_open_at'                     => 'varchar(10)',
			'rv_close_at'                    => 'varchar(10)',
			'rv_inventory'                   => 'varchar(20)',
			// Pricing — stall.
			'stall_nightly_rate'             => 'decimal(10,2)',
			'stall_weekend_rate'             => 'decimal(10,2)',
			'stall_weekly_rate'              => 'decimal(10,2)',
			'stall_early_bird_enabled'       => 'tinyint(1)',
			'stall_early_bird_cutoff'        => 'varchar(20)',
			'stall_early_bird_nightly_rate'  => 'decimal(10,2)',
			'stall_early_bird_weekend_rate'  => 'decimal(10,2)',
			'stall_early_bird_weekly_rate'   => 'decimal(10,2)',
			// Pricing — RV.
			'rv_nightly_rate'                => 'decimal(10,2)',
			'rv_weekend_rate'                => 'decimal(10,2)',
			'rv_weekly_rate'                 => 'decimal(10,2)',
			'rv_early_bird_enabled'          => 'tinyint(1)',
			'rv_early_bird_cutoff'           => 'varchar(20)',
			'rv_early_bird_nightly_rate'     => 'decimal(10,2)',
			'rv_early_bird_weekend_rate'     => 'decimal(10,2)',
			'rv_early_bird_weekly_rate'      => 'decimal(10,2)',
			// Convenience fee.
			'convenience_fee_label'          => 'varchar(255)',
			'convenience_fee_enabled'        => 'tinyint(1)',
			'convenience_fee_type'           => 'varchar(32)',
			'convenience_fee_value'          => 'decimal(10,2)',
			// Shavings.
			'required_shavings_enabled'      => 'tinyint(1)',
			'required_shavings_per_stall'    => 'int',
			'required_shavings_price'        => 'decimal(10,2)',
			'additional_shavings_enabled'    => 'tinyint(1)',
			'additional_shavings_description' => 'varchar(255)',
			'additional_shavings_price'       => 'decimal(10,2)',
			// Descriptions / venue / check-in.
			'reservation_description'        => 'text',
			'event_details_summary'          => 'text',
			'venue_name'                     => 'varchar(255)',
			'event_location'                 => 'varchar(255)',
			'venue_address'                  => 'text',
			'checkin_checkout_enabled'       => 'tinyint(1)',
			'checkin_time_enabled'           => 'tinyint(1)',
			'checkout_time_enabled'          => 'tinyint(1)',
			'checkin_time'                   => 'varchar(10)',
			'checkout_time'                  => 'varchar(10)',
			// Venue map.
			'venue_map_enabled'              => 'tinyint(1)',
			'venue_map_download_url'         => 'varchar(2048)',
			'venue_map_image_id'             => 'bigint(20)',
			'venue_map_caption'              => 'varchar(255)',
			// Agreement.
			'venue_agreement_enabled'        => 'tinyint(1)',
			'venue_agreement_file_id'        => 'bigint(20)',
			'venue_agreement_file_label'     => 'varchar(255)',
			'venue_agreement_label'          => 'text',
			'venue_agreement_link_label'     => 'varchar(255)',
			'venue_agreement_text'           => 'longtext',
			// Groups.
			'general_addons_enabled'         => 'tinyint(1)',
			'group_reservations_enabled'     => 'tinyint(1)',
			'group_description'              => 'text',
			'group_riders_per_group'         => 'varchar(10)',
			'group_rider_grounds_fee_enabled' => 'tinyint(1)',
			'group_rider_grounds_fee_amount'  => 'decimal(10,2)',
			'group_rider_deposit_enabled'     => 'tinyint(1)',
			'group_rider_deposit_amount'      => 'decimal(10,2)',
			// Event day.
			'event_day_enabled'              => 'tinyint(1)',
			'event_day_checkin'              => 'text',
			'event_day_bring'                => 'text',
			'event_day_parking'              => 'text',
			'event_day_contact'              => 'text',
			// Cancellation.
			'cancellation_enabled'           => 'tinyint(1)',
			'cancellation_policy_override'   => 'longtext',
			// Tack + limits.
			'stall_tack_mode'                => 'varchar(32)',
			'stall_max_per_customer'         => 'varchar(10)',
			'rv_max_per_customer'            => 'varchar(10)',
			// File/map IDs.
			'stall_map_file_id'              => 'bigint(20)',
			'rv_lot_selection_enabled'        => 'tinyint(1)',
			'rv_addons_enabled'              => 'tinyint(1)',
			'stall_map_id'                   => 'bigint(20)',
			'rv_lot_map_id'                  => 'bigint(20)',
			'event_pre_entries_enabled'      => 'tinyint(1)',
		);
	}

	/**
	 * Keys whose values are arrays stored as JSON columns.
	 *
	 * @return string[]
	 */
	private static function json_keys(): array {
		return array(
			'stall_chart_stall_blocks',
			'stall_chart_rv_blocks',
			'stall_chart_blocked_stall_units',
			'stall_chart_blocked_rv_units',
			'rv_lots',
			'general_addons',
			'rv_lot_zones',
			'rv_addons',
			'stall_map',
			'rv_map',
			'event_pre_entries',
			'stall_rows',
			'blocked_stalls',
			'rv_zones',
			'rv_rows',
			'blocked_rv_lots',
		);
	}

	/**
	 * Create the wp_eem_reservation_config table. Idempotent via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'eem_reservation_config';

		$cols = array( 'reservation_id bigint(20) unsigned NOT NULL' );

		foreach ( self::column_map() as $key => $type ) {
			$col_name = $key;
			$default  = self::sql_default_for_type( $type );
			$cols[]   = "{$col_name} {$type}{$default}";
		}

		foreach ( self::json_keys() as $key ) {
			$cols[] = "{$key} longtext NULL";
		}

		$cols[] = "extra_json longtext NULL";
		$cols[] = "updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
		$cols[] = "PRIMARY KEY  (reservation_id)";
		$cols[] = "KEY event_lookup (event_source, event_id)";
		$cols[] = "KEY stalls_enabled (stalls_enabled)";
		$cols[] = "KEY rv_enabled (rv_enabled)";

		$col_str = implode( ",\n", $cols );

		$sql = "CREATE TABLE {$table} (\n{$col_str}\n) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * SQL DEFAULT clause for a column type.
	 *
	 * @param string $type SQL column type.
	 * @return string
	 */
	private static function sql_default_for_type( string $type ): string {
		if ( str_starts_with( $type, 'tinyint' ) ) {
			return ' NOT NULL DEFAULT 0';
		}
		if ( str_starts_with( $type, 'int' ) || str_starts_with( $type, 'bigint' ) ) {
			return ' NOT NULL DEFAULT 0';
		}
		if ( str_starts_with( $type, 'decimal' ) ) {
			return ' NOT NULL DEFAULT 0.00';
		}
		return ' NULL';
	}

	/**
	 * Insert a row from a hydrated values array (used by migration + dual-write).
	 *
	 * @param int                 $reservation_id Reservation post ID.
	 * @param array<string,mixed> $values         Hydrated short-key → value map.
	 * @return bool True on success.
	 */
	public static function insert_from_values( int $reservation_id, array $values ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'eem_reservation_config';
		$data    = array( 'reservation_id' => $reservation_id );
		$formats = array( '%d' );

		$col_map  = self::column_map();
		$json_set = array_flip( self::json_keys() );
		$extra    = array();

		foreach ( $values as $key => $value ) {
			if ( isset( $col_map[ $key ] ) ) {
				$data[ $key ]    = self::cast_for_db( $value, $col_map[ $key ] );
				$formats[]       = self::format_for_type( $col_map[ $key ] );
			} elseif ( isset( $json_set[ $key ] ) ) {
				$data[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
				$formats[]    = '%s';
			} else {
				$extra[ $key ] = $value;
			}
		}

		if ( ! empty( $extra ) ) {
			$data['extra_json'] = wp_json_encode( $extra );
			$formats[]          = '%s';
		}

		$result = $wpdb->replace( $table, $data, $formats );

		return false !== $result;
	}

	/**
	 * Cast a PHP value for DB insertion.
	 *
	 * @param mixed  $value PHP value.
	 * @param string $type  SQL column type.
	 * @return mixed
	 */
	private static function cast_for_db( $value, string $type ) {
		if ( str_starts_with( $type, 'tinyint' ) ) {
			return (int) $value;
		}
		if ( str_starts_with( $type, 'int' ) || str_starts_with( $type, 'bigint' ) ) {
			return (int) $value;
		}
		if ( str_starts_with( $type, 'decimal' ) ) {
			return ( '' === $value || null === $value ) ? '0.00' : (string) $value;
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/**
	 * wpdb format string for a column type.
	 *
	 * @param string $type SQL column type.
	 * @return string
	 */
	private static function format_for_type( string $type ): string {
		if ( str_starts_with( $type, 'tinyint' ) || str_starts_with( $type, 'int' ) || str_starts_with( $type, 'bigint' ) ) {
			return '%d';
		}
		if ( str_starts_with( $type, 'decimal' ) ) {
			return '%s';
		}
		return '%s';
	}

	/**
	 * Check whether the relational table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}
		$table  = $wpdb->prefix . 'eem_reservation_config';
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $exists;
	}
}
