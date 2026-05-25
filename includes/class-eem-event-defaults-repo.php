<?php
/**
 * Event Defaults repository (C7.A).
 *
 * Plugin-owned event-level defaults — currently stores cancellation
 * policy text + venue map (image attachment + optional download URL +
 * caption). Keyed by composite primary key (event_id, event_source).
 *
 * Event source taxonomy (per HANDOFF Backend 9 + Q4.5 lock):
 *   - 'native'  : plugin's en_event CPT (event_id is a WP post ID)
 *   - 'tec'     : The Events Calendar's tribe_events CPT (event_id is a WP post ID)
 *   - 'feed'    : remote JSON feed (event_id is a feed-supplied string)
 *
 * 'external' is a legacy synonym for 'feed' — normalized to 'feed' at
 * the write boundary by `normalize_event_source()`. Migration #002
 * canonicalizes any pre-existing 'external' rows. Per Q4.5: long-term
 * goal is to drop 'external' from the allowed-sources set in
 * EEM_Events after all consumers ship.
 *
 * Per Q3.5: NO `enabled` column. Implicit "set ⇒ show" semantic at the
 * event level — if cancellation_policy / venue_map_image_id is non-
 * empty, surfaces display it. Admins clear the field to hide.
 *
 * Per Q5: table uses canonical `wp_eem_*` prefix. Existing legacy
 * `wp_en_*` tables get renamed in a coordinated cleanup chunk.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 2.3.0
 */
class EEM_Event_Defaults_Repo {

	/**
	 * Table name (without the WP prefix).
	 */
	const TABLE_BASE = 'eem_event_defaults';

	/**
	 * Allowed event_source values after normalization. 'external' is
	 * NOT in this set — it gets normalized to 'feed' before write.
	 *
	 * @var array<int, string>
	 */
	const ALLOWED_SOURCES = array( 'native', 'tec', 'feed' );

	/**
	 * Fully-qualified table name (with WP prefix).
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Normalize an incoming event_source value to its canonical form.
	 * Per Q4.5, 'external' is a legacy synonym for 'feed' — both accepted
	 * on input, only 'feed' written. Unknown values fall back to 'native'.
	 *
	 * @param string $source
	 * @return string  One of ALLOWED_SOURCES
	 */
	public static function normalize_event_source( $source ) {
		$source = sanitize_key( (string) $source );
		if ( 'external' === $source ) {
			return 'feed';
		}
		if ( in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			return $source;
		}
		return 'native';
	}

	/**
	 * Fetch the cancellation policy for an event-defaults row.
	 * Returns null when no row exists OR the policy column is empty.
	 *
	 * @param string $event_id
	 * @param string $event_source
	 * @return string|null
	 */
	public function get_cancellation_policy( $event_id, $event_source = 'native' ) {
		$row = $this->find_row( $event_id, $event_source );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$policy = isset( $row['cancellation_policy'] ) ? (string) $row['cancellation_policy'] : '';
		return '' === trim( $policy ) ? null : $policy;
	}

	/**
	 * Insert/update the cancellation policy column. Passing null or an
	 * empty string clears the column (leaves the row + other columns
	 * intact). Returns false on DB error.
	 *
	 * @param string      $event_id
	 * @param string      $event_source
	 * @param string|null $policy
	 * @return bool
	 */
	public function set_cancellation_policy( $event_id, $event_source, $policy ) {
		$normalized = $this->normalize_event_id_and_source( $event_id, $event_source );
		if ( null === $normalized ) {
			return false;
		}
		$payload = array( 'cancellation_policy' => null === $policy ? null : (string) $policy );
		$ok = $this->upsert( $normalized['event_id'], $normalized['event_source'], $payload );
		if ( $ok ) {
			eem_clear_cancellation_policy_resolver_cache();
		}
		return $ok;
	}

	/**
	 * Fetch the venue map payload for an event-defaults row.
	 * Returns null when the row carries no image_id (the canonical
	 * "set ⇒ show" trigger per Q3.5).
	 *
	 * @param string $event_id
	 * @param string $event_source
	 * @return array{image_id:int, download_url:string, caption:string}|null
	 */
	public function get_venue_map( $event_id, $event_source = 'native' ) {
		$row = $this->find_row( $event_id, $event_source );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$image_id = isset( $row['venue_map_image_id'] ) ? (int) $row['venue_map_image_id'] : 0;
		if ( $image_id <= 0 ) {
			return null;
		}
		return array(
			'image_id'     => $image_id,
			'download_url' => isset( $row['venue_map_download_url'] ) ? (string) $row['venue_map_download_url'] : '',
			'caption'      => isset( $row['venue_map_caption'] ) ? (string) $row['venue_map_caption'] : '',
		);
	}

	/**
	 * Insert/update the venue map columns. Payload keys:
	 *   image_id     (int, required to "set" the map — 0 clears it)
	 *   download_url (string, optional)
	 *   caption      (string, optional)
	 *
	 * @param string               $event_id
	 * @param string               $event_source
	 * @param array<string, mixed> $payload
	 * @return bool
	 */
	public function set_venue_map( $event_id, $event_source, array $payload ) {
		$normalized = $this->normalize_event_id_and_source( $event_id, $event_source );
		if ( null === $normalized ) {
			return false;
		}
		$columns = array(
			'venue_map_image_id'     => isset( $payload['image_id'] ) ? max( 0, (int) $payload['image_id'] ) : 0,
			'venue_map_download_url' => isset( $payload['download_url'] ) ? esc_url_raw( (string) $payload['download_url'] ) : '',
			'venue_map_caption'      => isset( $payload['caption'] ) ? sanitize_text_field( (string) $payload['caption'] ) : '',
		);
		return $this->upsert( $normalized['event_id'], $normalized['event_source'], $columns );
	}

	/**
	 * Validate + normalize an (event_id, event_source) pair for writes.
	 * Returns null if event_id is empty (refuse to write a blank PK).
	 *
	 * @param string $event_id
	 * @param string $event_source
	 * @return array{event_id:string, event_source:string}|null
	 */
	private function normalize_event_id_and_source( $event_id, $event_source ) {
		$event_id = (string) $event_id;
		if ( '' === trim( $event_id ) ) {
			return null;
		}
		return array(
			'event_id'     => $event_id,
			'event_source' => self::normalize_event_source( $event_source ),
		);
	}

	/**
	 * Composite-PK row lookup. event_source is normalized BUT also
	 * accepts legacy 'external' transparently — the resolver may have
	 * fetched 'external' from reservation meta before migration #002
	 * ran.
	 *
	 * @param string $event_id
	 * @param string $event_source
	 * @return array<string, mixed>|null
	 */
	private function find_row( $event_id, $event_source ) {
		global $wpdb;
		$event_id     = (string) $event_id;
		$event_source = self::normalize_event_source( $event_source );
		if ( '' === trim( $event_id ) ) {
			return null;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is constant.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %s AND event_source = %s LIMIT 1",
				$event_id,
				$event_source
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert-on-missing, update-on-present (composite PK). Returns
	 * false if any DB write errors. $payload keys that are not present
	 * are left untouched on update.
	 *
	 * @param string               $event_id
	 * @param string               $event_source
	 * @param array<string, mixed> $payload
	 * @return bool
	 */
	private function upsert( $event_id, $event_source, array $payload ) {
		global $wpdb;
		$table = self::table_name();
		$existing = $this->find_row( $event_id, $event_source );

		if ( is_array( $existing ) ) {
			$result = $wpdb->update(
				$table,
				$payload,
				array(
					'event_id'     => $event_id,
					'event_source' => $event_source,
				)
			);
			return false !== $result;
		}

		$payload['event_id']     = $event_id;
		$payload['event_source'] = $event_source;
		$result = $wpdb->insert( $table, $payload );
		return false !== $result;
	}
}
