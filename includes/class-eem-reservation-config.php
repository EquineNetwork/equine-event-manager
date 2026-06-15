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
	// Hydration (Phase 1: postmeta-backed)
	// ------------------------------------------------------------------

	/**
	 * Read all config keys from postmeta and apply legacy fallbacks.
	 *
	 * This is a direct delegation to EEM_Reservations_CPT::get_meta_values()
	 * which already encapsulates all the legacy-fallback logic (event-source
	 * inference, stall/RV pair resolution, date fallbacks, stay-type auto-enable,
	 * legacy RV addon migration, etc.). The repo wraps it rather than duplicating it.
	 */
	private function hydrate(): void {
		$cpt          = self::get_cpt_instance();
		$this->data   = $cpt->get_meta_values( $this->reservation_id );
		$this->hydrated = true;
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
}
