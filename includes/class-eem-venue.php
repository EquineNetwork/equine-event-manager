<?php
/**
 * Venues + saved layouts (v2 Facility Layout Templates, Slice 1).
 *
 * A **Venue** is a real-world place (fairgrounds / arena complex) that OWNS
 * saved stall/RV layouts. It is source-agnostic — TEC, GEMS, and (v3) Native
 * Events all resolve INTO one canonical Venue rather than each keeping their own
 * venue concept. Mirrors EEM_Reservation_Source_Resolver's event normalization.
 * Full design: docs/ARCHITECTURE-VENUES.md.
 *
 * Relational tables (NOT wp_postmeta — per the WordPress-replaceable principle):
 *   - {prefix}eem_venues            : canonical venue (name + normalized_key)
 *   - {prefix}eem_venue_source_map  : (source, source_venue_id) → venue_id
 *   - {prefix}eem_venue_layouts     : saved combined layouts (layout_json)
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static repository + resolver for canonical Venues and their saved layouts.
 */
class EEM_Venue {

	/**
	 * Reservation post-meta keys that make up the FULL structural layout a
	 * Venue layout captures: stall grid + RV lots/zones + map geometry + blocked
	 * units. A Venue layout is COMBINED (one save/load restores the whole venue).
	 * Pricing/dates are deliberately excluded (per-event).
	 *
	 * @var string[]
	 */
	const LAYOUT_META_KEYS = array(
		'_en_stall_rows',
		'_en_stall_map',
		'_en_rv_rows',
		'_en_rv_map',
		'_en_rv_zones',
		'_en_rv_lots',
		'_en_blocked_stalls',
		'_en_blocked_rv_lots',
	);

	/**
	 * Post-meta key on an `en_venue` post that durably stores the id of its
	 * canonical EEM_Venue row. Written by sync_native_venue() / on venue save so
	 * the two records are one (no per-request re-resolution).
	 *
	 * @var string
	 */
	const CANONICAL_VENUE_META = '_eem_canonical_venue_id';

	/* ── Table names ─────────────────────────────────────────────── */

	/** @return string */
	public static function venues_table(): string {
		global $wpdb; return $wpdb->prefix . 'eem_venues';
	}
	/** @return string */
	public static function source_map_table(): string {
		global $wpdb; return $wpdb->prefix . 'eem_venue_source_map';
	}
	/** @return string */
	public static function layouts_table(): string {
		global $wpdb; return $wpdb->prefix . 'eem_venue_layouts';
	}

	/**
	 * Create / upgrade the three Venue tables (idempotent via dbDelta).
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::venues_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			normalized_key varchar(191) NOT NULL DEFAULT '',
			address_1 varchar(255) NOT NULL DEFAULT '',
			address_2 varchar(255) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(100) NOT NULL DEFAULT '',
			postal_code varchar(20) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			website varchar(500) NOT NULL DEFAULT '',
			lat double DEFAULT NULL,
			lng double DEFAULT NULL,
			geocoded_address varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY normalized_key (normalized_key),
			KEY status (status)
		) {$cc};" );

		dbDelta( 'CREATE TABLE ' . self::source_map_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			venue_id bigint(20) unsigned NOT NULL,
			source varchar(32) NOT NULL DEFAULT '',
			source_venue_id varchar(191) NOT NULL DEFAULT '',
			source_venue_name varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY venue_id (venue_id),
			KEY source_lookup (source, source_venue_id)
		) {$cc};" );

		dbDelta( 'CREATE TABLE ' . self::layouts_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			venue_id bigint(20) unsigned NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			layout_json longtext NULL,
			layout_type varchar(20) NOT NULL DEFAULT 'combined',
			based_on_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY venue_id (venue_id)
		) {$cc};" );
	}

	/* ── Canonical venue CRUD + resolution ──────────────────────── */

	/**
	 * Normalize a venue name to a stable match key (lowercased, alnum-collapsed).
	 *
	 * @param string $name Raw venue name.
	 * @return string
	 */
	public static function normalize_key( string $name ): string {
		$k = strtolower( trim( $name ) );
		$k = preg_replace( '/[^a-z0-9]+/', '-', $k );
		return trim( (string) $k, '-' );
	}

	/**
	 * Resolve a source venue to a canonical venue id, creating it (and the
	 * source mapping) on first sight. Order: exact (source, source_venue_id)
	 * map hit → normalized-name match against existing venues → create new.
	 *
	 * @param string $source            native | tec | gems.
	 * @param string $source_venue_id   Stable source id when available (else '').
	 * @param string $source_venue_name Raw venue name from the source.
	 * @return int Canonical venue id (0 if no name + no id to resolve).
	 */
	public static function resolve( string $source, string $source_venue_id, string $source_venue_name ): int {
		global $wpdb;
		$source = sanitize_key( $source );
		$name   = trim( $source_venue_name );
		if ( '' === $name && '' === $source_venue_id ) {
			return 0;
		}

		// 1) exact source-map hit — but re-check if the upstream venue name
		//    changed (e.g. admin edited the TEC event's venue). When the name
		//    drifts, update the source map to point at the correct canonical venue.
		if ( '' !== $source_venue_id ) {
			$map_row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT id, venue_id, source_venue_name FROM ' . self::source_map_table() . ' WHERE source = %s AND source_venue_id = %s LIMIT 1',
				$source, $source_venue_id
			), ARRAY_A );
			if ( $map_row ) {
				$old_name = trim( (string) ( $map_row['source_venue_name'] ?? '' ) );
				if ( '' !== $name && $name !== $old_name ) {
					// Venue changed upstream — resolve the new name.
					$nkey       = self::normalize_key( $name );
					$new_venue  = 0;
					if ( '' !== $nkey ) {
						$new_venue = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
							'SELECT id FROM ' . self::venues_table() . ' WHERE normalized_key = %s LIMIT 1',
							$nkey
						) );
					}
					if ( $new_venue <= 0 ) {
						$wpdb->insert( self::venues_table(), array( // phpcs:ignore WordPress.DB
							'name'           => $name,
							'normalized_key' => '' !== $nkey ? $nkey : self::normalize_key( $source_venue_id ),
							'created_at'     => current_time( 'mysql' ),
						), array( '%s', '%s', '%s' ) );
						$new_venue = (int) $wpdb->insert_id;
					}
					if ( $new_venue > 0 ) {
						$wpdb->update( // phpcs:ignore WordPress.DB
							self::source_map_table(),
							array( 'venue_id' => $new_venue, 'source_venue_name' => $name ),
							array( 'id' => (int) $map_row['id'] ),
							array( '%d', '%s' ),
							array( '%d' )
						);
						return $new_venue;
					}
				}
				return (int) $map_row['venue_id'];
			}
		}

		// 2) normalized-name match against an existing canonical venue.
		$venue_id = 0;
		$nkey     = self::normalize_key( $name );
		if ( '' !== $nkey ) {
			$venue_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT id FROM ' . self::venues_table() . ' WHERE normalized_key = %s LIMIT 1',
				$nkey
			) );
		}

		// 3) create the canonical venue.
		if ( $venue_id <= 0 ) {
			$wpdb->insert( self::venues_table(), array( // phpcs:ignore WordPress.DB
				'name'           => '' !== $name ? $name : $source_venue_id,
				'normalized_key' => '' !== $nkey ? $nkey : self::normalize_key( $source_venue_id ),
				'created_at'     => current_time( 'mysql' ),
			), array( '%s', '%s', '%s' ) );
			$venue_id = (int) $wpdb->insert_id;
		}

		// Record the source mapping (idempotent on source + source_venue_id).
		if ( $venue_id > 0 ) {
			$map_id = '' !== $source_venue_id
				? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::source_map_table() . ' WHERE source = %s AND source_venue_id = %s LIMIT 1', $source, $source_venue_id ) )
				: (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::source_map_table() . ' WHERE venue_id = %d AND source = %s AND source_venue_name = %s LIMIT 1', $venue_id, $source, $name ) );
			if ( $map_id <= 0 ) {
				$wpdb->insert( self::source_map_table(), array( // phpcs:ignore WordPress.DB
					'venue_id'          => $venue_id,
					'source'            => $source,
					'source_venue_id'   => $source_venue_id,
					'source_venue_name' => $name,
				), array( '%d', '%s', '%s', '%s' ) );
			}
		}

		return $venue_id;
	}

	/**
	 * Read-only lookup of an existing canonical venue (exact source-map hit, then
	 * normalized-name match). Unlike resolve(), NEVER creates a venue or source
	 * mapping — used by read paths (e.g. "Load Layout") that must not pollute the
	 * venue table when nothing matches yet.
	 *
	 * @param string $source            native | tec | gems.
	 * @param string $source_venue_id   Stable source id when available (else '').
	 * @param string $source_venue_name Raw venue name from the source.
	 * @return int Canonical venue id, or 0 when no existing venue matches.
	 */
	public static function find( string $source, string $source_venue_id, string $source_venue_name ): int {
		global $wpdb;
		$source = sanitize_key( $source );
		if ( '' !== $source_venue_id ) {
			$hit = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT venue_id FROM ' . self::source_map_table() . ' WHERE source = %s AND source_venue_id = %s LIMIT 1',
				$source, $source_venue_id
			) );
			if ( $hit > 0 ) {
				return $hit;
			}
		}
		$nkey = self::normalize_key( trim( $source_venue_name ) );
		if ( '' === $nkey ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . self::venues_table() . ' WHERE normalized_key = %s LIMIT 1',
			$nkey
		) );
	}

	/**
	 * Read-only counterpart to resolve_for_reservation(): the existing canonical
	 * venue for a reservation's linked event, or 0 if none exists yet.
	 *
	 * @param int $reservation_id Reservation id.
	 * @return int Canonical venue id, or 0.
	 */
	public static function find_for_reservation( int $reservation_id ): int {
		if ( $reservation_id <= 0 ) {
			return 0;
		}
		$parts = self::reservation_venue_parts( $reservation_id );
		return self::find( $parts['source'], $parts['source_venue_id'], $parts['venue'] );
	}

	/**
	 * Resolve the canonical Venue for a reservation's linked event (the source
	 * of the venue name). Returns 0 when no venue is resolvable.
	 *
	 * @param int $reservation_id Reservation id.
	 * @return int Canonical venue id.
	 */
	public static function resolve_for_reservation( int $reservation_id ): int {
		if ( $reservation_id <= 0 ) {
			return 0;
		}
		$parts = self::reservation_venue_parts( $reservation_id );
		return self::resolve( $parts['source'], $parts['source_venue_id'], $parts['venue'] );
	}

	/**
	 * Extract source, event_id, external_event_id, and venue name for a
	 * reservation — reading from the config table first (canonical) and
	 * using EEM_Events::get_effective_reservation_event_source() so the
	 * resolved source matches TEC/GEMS/native correctly even when
	 * _en_event_source post-meta is empty.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array{source:string,source_venue_id:string,venue:string}
	 */
	private static function reservation_venue_parts( int $reservation_id ): array {
		// Use the authoritative source resolver (config table → post-meta → global default).
		$source = '';
		if ( class_exists( 'EEM_Events' ) && method_exists( 'EEM_Events', 'get_effective_reservation_event_source' ) ) {
			$source = (string) EEM_Events::get_effective_reservation_event_source( $reservation_id );
		}
		if ( '' === $source ) {
			$source = (string) get_post_meta( $reservation_id, '_en_event_source', true );
		}
		$resolved_source = '' !== $source ? $source : 'native';

		// Read event linkage from config table, fall back to post-meta.
		$event_id = '';
		$ext_id   = '';
		if ( class_exists( 'EEM_Reservation_Config' ) ) {
			$cfg      = EEM_Reservation_Config::for( $reservation_id );
			$event_id = (string) $cfg->get( 'event_id' );
			$ext_id   = (string) $cfg->get( 'external_event_id' );
		}
		if ( '' === $event_id ) {
			$event_id = (string) get_post_meta( $reservation_id, '_en_event_id', true );
		}
		if ( '' === $ext_id ) {
			$ext_id = (string) get_post_meta( $reservation_id, '_en_external_event_id', true );
		}

		$venue = '';
		if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
			$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
			$venue  = isset( $fields['venue'] ) ? (string) $fields['venue'] : '';
		}

		$source_venue_id = self::source_venue_key( $resolved_source, $ext_id, $event_id );
		return array(
			'source'          => $resolved_source,
			'source_venue_id' => $source_venue_id,
			'venue'           => $venue,
		);
	}

	/**
	 * Stable source-venue key for a reservation's linked event.
	 *
	 * For native events the physical venue is the linked `en_venue` post, so two
	 * events at the same venue resolve to ONE canonical Venue (and share its
	 * saved layouts). For external sources the external/event id is the key.
	 *
	 * @param string $source   Resolved event source (native|tec|gems|…).
	 * @param string $ext_id   External event id, if any.
	 * @param string $event_id Linked event post/source id.
	 * @return string Source venue key.
	 */
	private static function source_venue_key( string $source, string $ext_id, string $event_id ): string {
		if ( 'native' === $source && '' !== $event_id ) {
			$native_venue = (int) get_post_meta( (int) $event_id, '_equine_event_manager_event_venue_id', true );
			if ( $native_venue > 0 ) {
				return (string) $native_venue;
			}
		}

		if ( 'tec' === $source && '' !== $event_id ) {
			$tec_venue = (int) get_post_meta( (int) $event_id, '_EventVenueID', true );
			if ( $tec_venue > 0 ) {
				return (string) $tec_venue;
			}
		}

		return '' !== $ext_id ? $ext_id : $event_id;
	}

	/**
	 * Resolve (creating if needed) the canonical Venue for a native `en_venue`
	 * post. Lets the en_venue editor surface and manage that venue's layouts.
	 *
	 * @param int    $en_venue_post_id The `en_venue` post id.
	 * @param string $name             Optional name override (defaults to the post title).
	 * @return int Canonical venue id, or 0.
	 */
	public static function resolve_for_native_venue( int $en_venue_post_id, string $name = '' ): int {
		if ( $en_venue_post_id <= 0 ) {
			return 0;
		}
		if ( '' === $name ) {
			$name = (string) get_the_title( $en_venue_post_id );
		}
		$venue_id = self::resolve( 'native', (string) $en_venue_post_id, $name );
		if ( $venue_id > 0 ) {
			update_post_meta( $en_venue_post_id, self::CANONICAL_VENUE_META, $venue_id );
		}
		return $venue_id;
	}

	/**
	 * Read-only counterpart to resolve_for_native_venue(): the existing canonical
	 * Venue for a native `en_venue` post, or 0 if none exists yet. Prefers the
	 * durable back-reference post-meta (fast path) before falling back to a
	 * source-map / name lookup.
	 *
	 * @param int    $en_venue_post_id The `en_venue` post id.
	 * @param string $name             Optional name override (defaults to the post title).
	 * @return int Canonical venue id, or 0.
	 */
	public static function find_for_native_venue( int $en_venue_post_id, string $name = '' ): int {
		if ( $en_venue_post_id <= 0 ) {
			return 0;
		}
		$ref = (int) get_post_meta( $en_venue_post_id, self::CANONICAL_VENUE_META, true );
		if ( $ref > 0 && null !== self::get( $ref ) ) {
			return $ref;
		}
		if ( '' === $name ) {
			$name = (string) get_the_title( $en_venue_post_id );
		}
		return self::find( 'native', (string) $en_venue_post_id, $name );
	}

	/**
	 * Update a canonical venue's display name (and its normalized matching key).
	 * Used to keep the canonical record in lock-step with its native `en_venue`
	 * post title.
	 *
	 * @param int    $venue_id Canonical venue id.
	 * @param string $name     New display name.
	 * @return bool True on a successful update.
	 */
	public static function update_name( int $venue_id, string $name ): bool {
		global $wpdb;
		$name = trim( $name );
		if ( $venue_id <= 0 || '' === $name ) {
			return false;
		}
		$ok = $wpdb->update( // phpcs:ignore WordPress.DB
			self::venues_table(),
			array( 'name' => $name, 'normalized_key' => self::normalize_key( $name ) ),
			array( 'id' => $venue_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $ok;
	}

	/**
	 * Unify a native `en_venue` post with its canonical EEM_Venue record: resolve
	 * (creating if needed), keep the canonical name in sync with the post title,
	 * and persist the durable back-reference on the post. Idempotent — safe to run
	 * on every venue save and from the backfill migration.
	 *
	 * @param int $en_venue_post_id The `en_venue` post id.
	 * @return int Canonical venue id, or 0 when the post is not a usable venue.
	 */
	public static function sync_native_venue( int $en_venue_post_id ): int {
		if ( $en_venue_post_id <= 0 || 'en_venue' !== get_post_type( $en_venue_post_id ) ) {
			return 0;
		}
		$name     = (string) get_the_title( $en_venue_post_id );
		$venue_id = self::resolve( 'native', (string) $en_venue_post_id, $name );
		if ( $venue_id <= 0 ) {
			return 0;
		}
		$row = self::get( $venue_id );
		if ( $row && '' !== trim( $name ) && (string) $row['name'] !== $name ) {
			self::update_name( $venue_id, $name );
		}
		update_post_meta( $en_venue_post_id, self::CANONICAL_VENUE_META, $venue_id );
		return $venue_id;
	}

	/**
	 * Fetch a canonical venue row.
	 *
	 * @param int $venue_id Venue id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $venue_id ): ?array {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::venues_table() . ' WHERE id = %d', $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/**
	 * All canonical venues with their layout + source-mapping counts (for the
	 * Venues list page).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function all_with_counts( string $status = '' ): array {
		global $wpdb;
		$v = self::venues_table();
		$l = self::layouts_table();
		$m = self::source_map_table();
		$where = '';
		if ( '' !== $status ) {
			$where = $wpdb->prepare( ' WHERE v.status = %s', $status );
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT v.id, v.name, v.status, v.created_at,
				(SELECT COUNT(*) FROM {$l} WHERE {$l}.venue_id = v.id) AS layout_count,
				(SELECT COUNT(*) FROM {$m} WHERE {$m}.venue_id = v.id) AS source_count
			FROM {$v} v{$where} ORDER BY v.name ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count venues grouped by status.
	 *
	 * @return array<string,int> e.g. ['active' => 5, 'trash' => 2]
	 */
	public static function counts_by_status(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT status, COUNT(*) AS cnt FROM ' . self::venues_table() . ' GROUP BY status',
			ARRAY_A
		);
		$out = array( 'active' => 0, 'trash' => 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ (string) $r['status'] ] = (int) $r['cnt'];
			}
		}
		return $out;
	}

	/**
	 * Source mappings for a venue (which event sources point at it).
	 *
	 * @param int $venue_id Venue id.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_source_mappings( int $venue_id ): array {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, source, source_venue_id, source_venue_name FROM ' . self::source_map_table() . ' WHERE venue_id = %d ORDER BY source ASC, id ASC',
			$venue_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/* ── Saved layouts ──────────────────────────────────────────── */

	/**
	 * Read a reservation's current structural layout into a JSON-able array
	 * keyed by the LAYOUT_META_KEYS (combined stall + RV + blocked + geometry).
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $layout_type    'stall', 'rv', or 'combined' (default).
	 * @return array<string,mixed>
	 */
	public static function snapshot_reservation_layout( int $reservation_id, string $layout_type = 'combined' ): array {
		$out = array();
		$cfg = ( class_exists( 'EEM_Reservation_Config' ) && EEM_Reservation_Config::table_exists() )
			? EEM_Reservation_Config::for( $reservation_id )
			: null;
		$keys = self::LAYOUT_META_KEYS;
		if ( 'stall' === $layout_type ) {
			$keys = array_filter( $keys, function ( $k ) {
				return false !== strpos( $k, 'stall' );
			} );
		} elseif ( 'rv' === $layout_type ) {
			$keys = array_filter( $keys, function ( $k ) {
				return false !== strpos( $k, 'rv' );
			} );
		}
		foreach ( $keys as $key ) {
			// Post-decouple the map/rows/zones live in the config table, NOT post
			// meta — read there first so Save Layout captures the live map. Fall
			// back to legacy post-meta for pre-decouple reservations.
			$val = null;
			if ( $cfg ) {
				$val = $cfg->get( self::config_key_for_meta( $key ), null );
			}
			if ( null === $val || '' === $val || array() === $val ) {
				$val = get_post_meta( $reservation_id, $key, true );
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * Map a LAYOUT_META_KEYS post-meta key (`_en_stall_map`) to its canonical
	 * config-table short key (`stall_map`).
	 *
	 * @param string $meta_key Post-meta key.
	 * @return string Config short key.
	 */
	private static function config_key_for_meta( string $meta_key ): string {
		return ( 0 === strpos( $meta_key, '_en_' ) ) ? substr( $meta_key, 4 ) : $meta_key;
	}

	/**
	 * Normalize a stored layout value: JSON-string blobs (e.g. a saved `"[]"`)
	 * decode to arrays so the config layer re-encodes them consistently.
	 *
	 * @param mixed $value Raw layout value.
	 * @return mixed
	 */
	private static function normalize_layout_value( $value ) {
		if ( is_string( $value ) ) {
			$trim = trim( $value );
			if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
				$decoded = json_decode( $trim, true );
				if ( null !== $decoded ) {
					return $decoded;
				}
			}
		}
		return $value;
	}

	/**
	 * Save a reservation's current layout to a Venue (the "Save Layout" action).
	 *
	 * @param int    $venue_id       Canonical venue id.
	 * @param int    $reservation_id Reservation whose layout to capture.
	 * @param string $name           Layout name.
	 * @param string $layout_type    'stall', 'rv', or 'combined' (default).
	 * @return int New layout id (0 on failure).
	 */
	public static function save_layout( int $venue_id, int $reservation_id, string $name, string $layout_type = 'combined' ): int {
		global $wpdb;
		if ( $venue_id <= 0 || $reservation_id <= 0 ) {
			return 0;
		}
		$valid_types = array( 'stall', 'rv', 'combined' );
		if ( ! in_array( $layout_type, $valid_types, true ) ) {
			$layout_type = 'combined';
		}
		$snapshot = self::snapshot_reservation_layout( $reservation_id, $layout_type );
		$json     = wp_json_encode( $snapshot );
		$ok       = $wpdb->insert( self::layouts_table(), array( // phpcs:ignore WordPress.DB
			'venue_id'    => $venue_id,
			'name'        => '' !== trim( $name ) ? trim( $name ) : __( 'Untitled layout', 'equine-event-manager' ),
			'layout_json' => $json,
			'layout_type' => $layout_type,
			'based_on_id' => 0,
			'created_at'  => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%d', '%s' ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Reserved name for the rolling auto-saved layout (ROADMAP v1 #12). One per
	 * venue, overwritten on every reservation save, kept distinct from the admin's
	 * manual named saves.
	 *
	 * @var string
	 */
	const AUTO_LAYOUT_NAME = 'Auto-saved (latest)';

	/**
	 * Whether a layout snapshot actually contains structural content, so an empty
	 * save (e.g. a reservation with no map yet) never clobbers a good auto-save.
	 *
	 * @param array<string,mixed> $snapshot Snapshot from snapshot_reservation_layout().
	 * @return bool
	 */
	private static function layout_has_content( array $snapshot ): bool {
		foreach ( $snapshot as $value ) {
			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					return true;
				}
			} elseif ( is_string( $value ) ) {
				$trim = trim( $value );
				if ( '' !== $trim && '[]' !== $trim && '{}' !== $trim && '0' !== $trim ) {
					return true;
				}
			} elseif ( ! empty( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Auto-save a reservation's current layout to its venue as the rolling
	 * "latest" copy (ROADMAP v1 #12 — "never lose a map"). Upserts a single
	 * reserved layout row per venue (overwrites on each call); does nothing when
	 * the reservation has no structural layout yet, so a good auto-save is never
	 * replaced by an empty one. Best-effort and side-effect-only — callers fire it
	 * after a save and ignore the result.
	 *
	 * @param int $venue_id       Canonical venue id.
	 * @param int $reservation_id Reservation whose layout to capture.
	 * @return int The auto-layout row id (0 when nothing was saved).
	 */
	public static function auto_save_layout( int $venue_id, int $reservation_id ): int {
		global $wpdb;
		if ( $venue_id <= 0 || $reservation_id <= 0 ) {
			return 0;
		}

		$snapshot = self::snapshot_reservation_layout( $reservation_id, 'combined' );
		if ( ! self::layout_has_content( $snapshot ) ) {
			return 0; // Nothing worth saving — don't overwrite an existing auto-save.
		}

		$json     = wp_json_encode( $snapshot );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . self::layouts_table() . ' WHERE venue_id = %d AND name = %s AND layout_type = %s LIMIT 1',
			$venue_id, self::AUTO_LAYOUT_NAME, 'combined'
		) );

		if ( $existing > 0 ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				self::layouts_table(),
				array( 'layout_json' => $json, 'created_at' => current_time( 'mysql' ) ),
				array( 'id' => $existing ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return $existing;
		}

		$ok = $wpdb->insert( self::layouts_table(), array( // phpcs:ignore WordPress.DB
			'venue_id'    => $venue_id,
			'name'        => self::AUTO_LAYOUT_NAME,
			'layout_json' => $json,
			'layout_type' => 'combined',
			'based_on_id' => 0,
			'created_at'  => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%d', '%s' ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Convenience wrapper: resolve a reservation's venue and auto-save its layout.
	 * No-op when the reservation has no resolvable venue. Safe to call after any
	 * reservation/map save.
	 *
	 * @param int $reservation_id Reservation id.
	 * @return int Auto-layout row id (0 when nothing saved).
	 */
	public static function auto_save_reservation_layout( int $reservation_id ): int {
		if ( $reservation_id <= 0 ) {
			return 0;
		}
		$venue_id = self::resolve_for_reservation( $reservation_id );
		if ( $venue_id <= 0 ) {
			return 0;
		}
		return self::auto_save_layout( $venue_id, $reservation_id );
	}

	/**
	 * Saved layouts for a venue (newest first).
	 *
	 * @param int    $venue_id    Venue id.
	 * @param string $layout_type Optional filter: 'stall', 'rv', 'combined', or '' for all.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_layouts( int $venue_id, string $layout_type = '' ): array {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return array();
		}
		$where = 'WHERE venue_id = %d';
		$args  = array( $venue_id );
		if ( '' !== $layout_type && in_array( $layout_type, array( 'stall', 'rv', 'combined' ), true ) ) {
			$where .= ' AND layout_type = %s';
			$args[] = $layout_type;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, venue_id, name, layout_type, based_on_id, created_at FROM ' . self::layouts_table() . ' ' . $where . ' ORDER BY created_at DESC, id DESC',
			$args
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single layout (incl. decoded layout_json).
	 *
	 * @param int $layout_id Layout id.
	 * @return array<string,mixed>|null
	 */
	public static function get_layout( int $layout_id ): ?array {
		global $wpdb;
		if ( $layout_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::layouts_table() . ' WHERE id = %d', $layout_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['layout'] = is_string( $row['layout_json'] ) && '' !== $row['layout_json'] ? json_decode( $row['layout_json'], true ) : array();
		return $row;
	}

	/**
	 * COPY-ON-USE: clone a saved layout's structure into a target reservation
	 * (the "Load Layout" action). Writes the LAYOUT_META_KEYS onto the target;
	 * NEVER mutates the saved Venue layout. Does not record lineage on the saved
	 * layout (lineage is for layouts saved FROM a clone — handled at save time).
	 *
	 * @param int $layout_id            Saved layout to clone.
	 * @param int $target_reservation_id Reservation to write the layout into.
	 * @return bool
	 */
	public static function apply_layout_to_reservation( int $layout_id, int $target_reservation_id ): bool {
		$layout = self::get_layout( $layout_id );
		if ( null === $layout || $target_reservation_id <= 0 ) {
			return false;
		}
		$data       = is_array( $layout['layout'] ?? null ) ? $layout['layout'] : array();
		$cfg_values = array();
		foreach ( self::LAYOUT_META_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$value = self::normalize_layout_value( $data[ $key ] );
				// Write to BOTH the canonical config table (what the editor + map
				// builder read post-decouple) and legacy post-meta (back-compat).
				// Writing only post-meta is why a loaded layout never appeared.
				update_post_meta( $target_reservation_id, $key, $value );
				$cfg_values[ self::config_key_for_meta( $key ) ] = $value;
			}
		}
		if ( ! empty( $cfg_values ) && class_exists( 'EEM_Reservation_Config' ) && EEM_Reservation_Config::table_exists() ) {
			EEM_Reservation_Config::for( $target_reservation_id )->set_many( $cfg_values )->save();
			EEM_Reservation_Config::flush_cache( $target_reservation_id );
		}
		return true;
	}

	/**
	 * Rename a saved layout.
	 *
	 * @param int    $layout_id Layout id.
	 * @param string $name      New name.
	 * @return bool
	 */
	public static function rename_layout( int $layout_id, string $name ): bool {
		global $wpdb;
		if ( $layout_id <= 0 || '' === trim( $name ) ) {
			return false;
		}
		return false !== $wpdb->update( self::layouts_table(), array( 'name' => trim( $name ) ), array( 'id' => $layout_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete a saved layout.
	 *
	 * @param int $layout_id Layout id.
	 * @return bool
	 */
	public static function delete_layout( int $layout_id ): bool {
		global $wpdb;
		if ( $layout_id <= 0 ) {
			return false;
		}
		return false !== $wpdb->delete( self::layouts_table(), array( 'id' => $layout_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Soft-delete a venue (move to trash).
	 *
	 * @param int $venue_id Venue id.
	 * @return bool
	 */
	public static function rename( int $venue_id, string $name ): bool {
		if ( $venue_id <= 0 || '' === $name ) {
			return false;
		}
		global $wpdb;
		$result = $wpdb->update( self::venues_table(), array( 'name' => $name ), array( 'id' => $venue_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		return false !== $result;
	}

	/**
	 * @param int $venue_id Venue id.
	 * @return bool
	 */
	public static function delete_venue( int $venue_id ): bool {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update( self::venues_table(), array( 'status' => 'trash' ), array( 'id' => $venue_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Restore a trashed venue back to active.
	 *
	 * @param int $venue_id Venue id.
	 * @return bool
	 */
	public static function restore_venue( int $venue_id ): bool {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update( self::venues_table(), array( 'status' => 'active' ), array( 'id' => $venue_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Permanently delete a venue and all its layouts + source mappings.
	 *
	 * @param int $venue_id Venue id.
	 * @return bool
	 */
	public static function delete_venue_permanently( int $venue_id ): bool {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return false;
		}
		$wpdb->delete( self::layouts_table(), array( 'venue_id' => $venue_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( self::source_map_table(), array( 'venue_id' => $venue_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		return false !== $wpdb->delete( self::venues_table(), array( 'id' => $venue_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/* ── Venue detail (address / geo / contact) ────────────────── */

	/**
	 * Detail column names stored on wp_eem_venues.
	 *
	 * @var string[]
	 */
	const DETAIL_FIELDS = array(
		'address_1',
		'address_2',
		'city',
		'state',
		'postal_code',
		'phone',
		'website',
		'lat',
		'lng',
		'geocoded_address',
	);

	/** @var array<int, array<string,mixed>> */
	private static array $detail_cache = array();

	/**
	 * Resolve the canonical venue_id for an en_venue post ID.
	 *
	 * Checks the _eem_canonical_venue_id postmeta first (fast path), then
	 * falls back to source_map lookup.
	 *
	 * @param int $post_id en_venue post ID.
	 * @return int 0 if unresolvable.
	 */
	public static function venue_id_for_post( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}
		$cached = (int) get_post_meta( $post_id, self::CANONICAL_VENUE_META, true );
		if ( $cached > 0 ) {
			return $cached;
		}
		global $wpdb;
		$venue_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT venue_id FROM ' . self::source_map_table() . ' WHERE source = %s AND source_venue_id = %s LIMIT 1',
			'native',
			(string) $post_id
		) ); // phpcs:ignore WordPress.DB
		return $venue_id > 0 ? $venue_id : 0;
	}

	/**
	 * Get all detail fields for a venue.
	 *
	 * Accepts either a canonical venue_id or an en_venue post ID (resolved
	 * transparently). Falls back to postmeta when the table lacks the row or
	 * hasn't been upgraded yet.
	 *
	 * @param int  $id           Canonical venue_id OR en_venue post ID.
	 * @param bool $is_post_id   True when $id is a post ID (default false).
	 * @return array<string,mixed>
	 */
	public static function get_detail( int $id, bool $is_post_id = false ): array {
		$defaults = array(
			'address_1'        => '',
			'address_2'        => '',
			'city'             => '',
			'state'            => '',
			'postal_code'      => '',
			'phone'            => '',
			'website'          => '',
			'lat'              => '',
			'lng'              => '',
			'geocoded_address' => '',
		);

		if ( $id <= 0 ) {
			return $defaults;
		}

		$post_id  = $is_post_id ? $id : 0;
		$venue_id = $is_post_id ? self::venue_id_for_post( $id ) : $id;

		if ( $venue_id > 0 && isset( self::$detail_cache[ $venue_id ] ) ) {
			return self::$detail_cache[ $venue_id ];
		}

		if ( $venue_id > 0 && self::table_has_detail_columns() ) {
			$row = self::get( $venue_id );
			if ( $row ) {
				$detail = array();
				foreach ( self::DETAIL_FIELDS as $f ) {
					$detail[ $f ] = isset( $row[ $f ] ) ? (string) $row[ $f ] : '';
				}
				self::$detail_cache[ $venue_id ] = $detail;
				return $detail;
			}
		}

		if ( $post_id <= 0 ) {
			return $defaults;
		}
		$detail = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postal_code', 'phone', 'website' ) as $f ) {
			$detail[ $f ] = (string) get_post_meta( $post_id, '_equine_event_manager_venue_' . $f, true );
		}
		$detail['lat']              = (string) get_post_meta( $post_id, '_en_venue_lat', true );
		$detail['lng']              = (string) get_post_meta( $post_id, '_en_venue_lng', true );
		$detail['geocoded_address'] = (string) get_post_meta( $post_id, '_en_venue_geocoded_address', true );
		return $detail;
	}

	/**
	 * Save detail fields for a venue.
	 *
	 * @param int                  $id         Canonical venue_id OR en_venue post ID.
	 * @param array<string,mixed>  $data       Field => value pairs (only DETAIL_FIELDS keys accepted).
	 * @param bool                 $is_post_id True when $id is a post ID.
	 * @return bool
	 */
	public static function save_detail( int $id, array $data, bool $is_post_id = false ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$venue_id = $is_post_id ? self::venue_id_for_post( $id ) : $id;

		$cols   = array();
		$vals   = array();
		$format = array();
		foreach ( self::DETAIL_FIELDS as $f ) {
			if ( ! array_key_exists( $f, $data ) ) {
				continue;
			}
			$cols[] = $f;
			if ( 'lat' === $f || 'lng' === $f ) {
				$v = $data[ $f ];
				if ( '' === $v || null === $v ) {
					$vals[]   = null;
					$format[] = '%s';
				} else {
					$vals[]   = (float) $v;
					$format[] = '%f';
				}
			} else {
				$vals[]   = (string) $data[ $f ];
				$format[] = '%s';
			}
		}

		if ( empty( $cols ) ) {
			return false;
		}

		if ( $venue_id > 0 && self::table_has_detail_columns() ) {
			global $wpdb;
			$result = $wpdb->update( self::venues_table(), array_combine( $cols, $vals ), array( 'id' => $venue_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB
			unset( self::$detail_cache[ $venue_id ] );
			return false !== $result;
		}

		return false;
	}

	/**
	 * Check whether the venues table has the detail columns (post-upgrade guard).
	 *
	 * @return bool
	 */
	private static function table_has_detail_columns(): bool {
		static $has = null;
		if ( null !== $has ) {
			return $has;
		}
		global $wpdb;
		$col = $wpdb->get_var( $wpdb->prepare(
			'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			DB_NAME,
			self::venues_table(),
			'address_1'
		) ); // phpcs:ignore WordPress.DB
		$has = ( null !== $col );
		return $has;
	}
}
