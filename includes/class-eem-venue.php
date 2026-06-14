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
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY normalized_key (normalized_key)
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

		// 1) exact source-map hit.
		if ( '' !== $source_venue_id ) {
			$hit = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT venue_id FROM ' . self::source_map_table() . ' WHERE source = %s AND source_venue_id = %s LIMIT 1',
				$source, $source_venue_id
			) );
			if ( $hit > 0 ) {
				return $hit;
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
		$source   = (string) get_post_meta( $reservation_id, '_en_event_source', true );
		$event_id = (string) get_post_meta( $reservation_id, '_en_event_id', true );
		$ext_id   = (string) get_post_meta( $reservation_id, '_en_external_event_id', true );
		$venue    = '';
		if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
			$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
			$venue  = isset( $fields['venue'] ) ? (string) $fields['venue'] : '';
		}
		$resolved_source = '' !== $source ? $source : 'native';
		$source_venue_id = self::source_venue_key( $resolved_source, $ext_id, $event_id );
		return self::find( $resolved_source, $source_venue_id, $venue );
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
		$source   = (string) get_post_meta( $reservation_id, '_en_event_source', true );
		$event_id = (string) get_post_meta( $reservation_id, '_en_event_id', true );
		$ext_id   = (string) get_post_meta( $reservation_id, '_en_external_event_id', true );
		$venue    = '';
		if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
			$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
			$venue  = isset( $fields['venue'] ) ? (string) $fields['venue'] : '';
		}
		// Use the source venue id when present, else fall back to the event id as
		// the stable key (keeps the same physical venue stable across reuses).
		$resolved_source = '' !== $source ? $source : 'native';
		$source_venue_id = self::source_venue_key( $resolved_source, $ext_id, $event_id );
		return self::resolve( $resolved_source, $source_venue_id, $venue );
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
	public static function all_with_counts(): array {
		global $wpdb;
		$v = self::venues_table();
		$l = self::layouts_table();
		$m = self::source_map_table();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT v.id, v.name, v.created_at,
				(SELECT COUNT(*) FROM {$l} WHERE {$l}.venue_id = v.id) AS layout_count,
				(SELECT COUNT(*) FROM {$m} WHERE {$m}.venue_id = v.id) AS source_count
			FROM {$v} v ORDER BY v.name ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
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
	 * @param int $reservation_id Reservation id.
	 * @return array<string,mixed>
	 */
	public static function snapshot_reservation_layout( int $reservation_id ): array {
		$out = array();
		foreach ( self::LAYOUT_META_KEYS as $key ) {
			$out[ $key ] = get_post_meta( $reservation_id, $key, true );
		}
		return $out;
	}

	/**
	 * Save a reservation's current layout to a Venue (the "Save Layout" action).
	 *
	 * @param int    $venue_id       Canonical venue id.
	 * @param int    $reservation_id Reservation whose layout to capture.
	 * @param string $name           Layout name.
	 * @return int New layout id (0 on failure).
	 */
	public static function save_layout( int $venue_id, int $reservation_id, string $name ): int {
		global $wpdb;
		if ( $venue_id <= 0 || $reservation_id <= 0 ) {
			return 0;
		}
		$json = wp_json_encode( self::snapshot_reservation_layout( $reservation_id ) );
		$ok   = $wpdb->insert( self::layouts_table(), array( // phpcs:ignore WordPress.DB
			'venue_id'    => $venue_id,
			'name'        => '' !== trim( $name ) ? trim( $name ) : __( 'Untitled layout', 'equine-event-manager' ),
			'layout_json' => $json,
			'based_on_id' => 0,
			'created_at'  => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%d', '%s' ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Saved layouts for a venue (newest first).
	 *
	 * @param int $venue_id Venue id.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_layouts( int $venue_id ): array {
		global $wpdb;
		if ( $venue_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, venue_id, name, based_on_id, created_at FROM ' . self::layouts_table() . ' WHERE venue_id = %d ORDER BY created_at DESC, id DESC',
			$venue_id
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
		$data = is_array( $layout['layout'] ?? null ) ? $layout['layout'] : array();
		foreach ( self::LAYOUT_META_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				update_post_meta( $target_reservation_id, $key, $data[ $key ] );
			}
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
}
