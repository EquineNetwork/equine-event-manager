<?php
/**
 * Sheets & Results data layer (draw sheet + result PDFs per event).
 *
 * Each row in {prefix}eem_sheet_entries represents ONE document slot for an
 * event, grouped by discipline (the `en_discipline` taxonomy). The draw sheet
 * and its mirrored result are TWO PDF columns on the SAME row — not two
 * records. "Adding a draw sheet" inserts a row with `drawsheet_pdf` set and
 * `result_pdf` empty; the Results surface renders the same rows, showing either
 * the result PDF or an "Upload Result PDF" affordance. This makes the
 * draw-sheet→result mirror automatic: there is nothing to keep in sync.
 *
 * The repo is the authoritative source for the admin manager page (Screen 1),
 * the event-editor section (Screen 2), the conditional event-card buttons
 * (Screen 3), and the public per-event page (Screen 4). All four read through
 * the grouped/counts helpers here.
 *
 * Scope: native events (`en_event` posts). The taxonomy + entries are gated by
 * native-events being enabled, the same as the rest of the native CPT surface.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static repository for the Sheets & Results document ledger.
 */
class EEM_Sheet_Entries {

	/** @var string Unprefixed table name. */
	const TABLE = 'eem_sheet_entries';

	/** @var string The discipline taxonomy slug (registered on en_event). */
	const TAXONOMY = 'en_discipline';

	/**
	 * Fully-qualified table name (with the site DB prefix).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Round options as slug => label. The slugs are stable storage values; the
	 * labels are translated for display. Order matches the mockup's Round
	 * select (Screen 1 add-file panel).
	 *
	 * @return array<string,string>
	 */
	public static function rounds(): array {
		return array(
			'1st-go'        => __( '1st Go', 'equine-event-manager' ),
			'2nd-go'        => __( '2nd Go', 'equine-event-manager' ),
			'short-go'      => __( 'Short Go', 'equine-event-manager' ),
			'finals'        => __( 'Finals', 'equine-event-manager' ),
			'average'       => __( 'Average', 'equine-event-manager' ),
			'qualifications' => __( 'Qualifications', 'equine-event-manager' ),
			'top-15-points' => __( 'Top 15 Points', 'equine-event-manager' ),
			'other'         => __( 'Other', 'equine-event-manager' ),
		);
	}

	/**
	 * Translated label for a round slug. Falls back to empty string for the
	 * "no round" case and to the raw slug for unknown values.
	 *
	 * @param string $slug Round slug.
	 * @return string
	 */
	public static function round_label( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}
		$rounds = self::rounds();
		return $rounds[ $slug ] ?? $slug;
	}

	/**
	 * Normalise a submitted round value to a known slug (or '' if unrecognised).
	 *
	 * @param string $raw Raw round value (slug).
	 * @return string
	 */
	private static function sanitize_round( string $raw ): string {
		$raw = sanitize_key( $raw );
		return array_key_exists( $raw, self::rounds() ) ? $raw : '';
	}

	/**
	 * Normalise a submitted date to Y-m-d, or null when empty/invalid.
	 *
	 * @param string $raw Raw date string.
	 * @return string|null
	 */
	private static function sanitize_date( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		return false === $ts ? null : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Create / upgrade the ledger table via dbDelta. Idempotent — safe to call
	 * on every activation.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table_name();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			discipline_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(191) NOT NULL DEFAULT '',
			round varchar(40) NOT NULL DEFAULT '',
			entry_date date NULL DEFAULT NULL,
			drawsheet_pdf bigint(20) unsigned NOT NULL DEFAULT 0,
			result_pdf bigint(20) unsigned NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY discipline_id (discipline_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a new document row (typically a draw sheet). The mirrored result
	 * slot is implicit — `result_pdf` simply starts at 0 and is filled later.
	 *
	 * @param array $data {
	 *     @type int    $event_id      en_event post ID (required).
	 *     @type int    $discipline_id en_discipline term ID.
	 *     @type string $label         Human label (e.g. "Open 5D Long Go").
	 *     @type string $round         Round slug (see rounds()).
	 *     @type string $entry_date    Date (any strtotime-parseable form).
	 *     @type int    $drawsheet_pdf Attachment ID for the draw sheet PDF.
	 *     @type int    $result_pdf    Attachment ID for the result PDF.
	 *     @type int    $sort_order    Manual sort within discipline.
	 * }
	 * @return int New row ID, or 0 on failure / missing event_id.
	 */
	public static function add_entry( array $data ): int {
		global $wpdb;

		$event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;
		if ( $event_id <= 0 ) {
			return 0;
		}

		$row = array(
			'event_id'      => $event_id,
			'discipline_id' => isset( $data['discipline_id'] ) ? absint( $data['discipline_id'] ) : 0,
			'label'         => isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '',
			'round'         => self::sanitize_round( (string) ( $data['round'] ?? '' ) ),
			'entry_date'    => self::sanitize_date( (string) ( $data['entry_date'] ?? '' ) ),
			'drawsheet_pdf' => isset( $data['drawsheet_pdf'] ) ? absint( $data['drawsheet_pdf'] ) : 0,
			'result_pdf'    => isset( $data['result_pdf'] ) ? absint( $data['result_pdf'] ) : 0,
			'sort_order'    => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
		);

		$ok = $wpdb->insert( self::table_name(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a single row as an associative array (typed), or null if missing.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( int $id ) {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return $row ? self::shape_row( $row ) : null;
	}

	/**
	 * Update mutable fields on an existing row. Only the keys present in $data
	 * are written. `which` PDF columns are set via set_pdf() instead.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Subset of label/round/entry_date/discipline_id/sort_order.
	 * @return bool
	 */
	public static function update_entry( int $id, array $data ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}

		$row = array();
		if ( array_key_exists( 'label', $data ) ) {
			$row['label'] = sanitize_text_field( (string) $data['label'] );
		}
		if ( array_key_exists( 'round', $data ) ) {
			$row['round'] = self::sanitize_round( (string) $data['round'] );
		}
		if ( array_key_exists( 'entry_date', $data ) ) {
			$row['entry_date'] = self::sanitize_date( (string) $data['entry_date'] );
		}
		if ( array_key_exists( 'discipline_id', $data ) ) {
			$row['discipline_id'] = absint( $data['discipline_id'] );
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$row['sort_order'] = (int) $data['sort_order'];
		}

		if ( empty( $row ) ) {
			return false;
		}

		$ok = $wpdb->update( self::table_name(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		return false !== $ok;
	}

	/**
	 * Set (or clear) one of the two PDF columns on a row.
	 *
	 * @param int    $id            Row ID.
	 * @param string $which         'drawsheet' or 'result'.
	 * @param int    $attachment_id Media Library attachment ID (0 to clear).
	 * @return bool
	 */
	public static function set_pdf( int $id, string $which, int $attachment_id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$column = 'result' === $which ? 'result_pdf' : 'drawsheet_pdf';
		$ok     = $wpdb->update( // phpcs:ignore WordPress.DB
			self::table_name(),
			array( $column => max( 0, $attachment_id ) ),
			array( 'id' => $id )
		);
		return false !== $ok;
	}

	/**
	 * Delete all rows for one event + discipline (used when a discipline is
	 * removed from an event). Attachments are left in the Media Library.
	 *
	 * @param int $event_id      en_event post id.
	 * @param int $discipline_id en_discipline term id.
	 * @return int Rows deleted.
	 */
	public static function delete_for_event_discipline( int $event_id, int $discipline_id ): int {
		global $wpdb;
		if ( $event_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_name(),
			array( 'event_id' => $event_id, 'discipline_id' => $discipline_id )
		);
	}

	/**
	 * Delete a row entirely (removes both the draw sheet and result slots).
	 * The attachments themselves are left in the Media Library.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function delete_entry( int $id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$ok = $wpdb->delete( self::table_name(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		return false !== $ok;
	}

	/**
	 * All rows for an event, ordered by discipline then sort/date.
	 *
	 * @param int $event_id en_event post ID.
	 * @return array<int,array>
	 */
	public static function get_for_event( int $event_id ): array {
		global $wpdb;
		if ( $event_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE event_id = %d ORDER BY discipline_id ASC, sort_order ASC, entry_date ASC, id ASC', // phpcs:ignore WordPress.DB
				$event_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'shape_row' ), $rows ? $rows : array() );
	}

	/**
	 * Rows for an event grouped by discipline. The group set is the UNION of the
	 * disciplines assigned to the event (en_discipline terms) and any discipline
	 * referenced by an existing row — so disciplines with zero files still
	 * render as an (empty) group, matching the mockup.
	 *
	 * @param int $event_id en_event post ID.
	 * @return array<int,array{discipline_id:int,discipline_name:string,entries:array}>
	 */
	public static function get_for_event_grouped_by_discipline( int $event_id ): array {
		$entries = self::get_for_event( $event_id );

		// Ordered map of discipline_id => discipline_name. Assigned terms first
		// (alphabetical via get_the_terms ordering), then any orphan disciplines
		// referenced only by rows.
		$groups = array();

		$terms = $event_id > 0 ? get_the_terms( $event_id, self::TAXONOMY ) : false;
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$groups[ (int) $term->term_id ] = array(
					'discipline_id'   => (int) $term->term_id,
					'discipline_name' => $term->name,
					'entries'         => array(),
				);
			}
		}

		foreach ( $entries as $entry ) {
			$did = (int) $entry['discipline_id'];
			if ( ! isset( $groups[ $did ] ) ) {
				$name = '';
				if ( $did > 0 ) {
					$term = get_term( $did, self::TAXONOMY );
					$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
				}
				$groups[ $did ] = array(
					'discipline_id'   => $did,
					'discipline_name' => '' !== $name ? $name : __( 'Uncategorized', 'equine-event-manager' ),
					'entries'         => array(),
				);
			}
			$groups[ $did ]['entries'][] = $entry;
		}

		return array_values( $groups );
	}

	/**
	 * Draw-sheet and result document counts for an event. A row counts toward
	 * "drawsheets" when it has a draw-sheet PDF and toward "results" when it has
	 * a result PDF.
	 *
	 * @param int $event_id en_event post ID.
	 * @return array{drawsheets:int,results:int}
	 */
	public static function counts( int $event_id ): array {
		global $wpdb;
		$out = array(
			'drawsheets' => 0,
			'results'    => 0,
		);
		if ( $event_id <= 0 ) {
			return $out;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(drawsheet_pdf > 0) AS drawsheets, SUM(result_pdf > 0) AS results FROM ' . self::table_name() . ' WHERE event_id = %d', // phpcs:ignore WordPress.DB
				$event_id
			),
			ARRAY_A
		);
		if ( $row ) {
			$out['drawsheets'] = (int) $row['drawsheets'];
			$out['results']    = (int) $row['results'];
		}
		return $out;
	}

	/**
	 * Whether the event has at least one uploaded draw-sheet PDF (drives the
	 * conditional "Draw Sheets" button on the event-list cards).
	 *
	 * @param int $event_id en_event post ID.
	 * @return bool
	 */
	public static function has_drawsheets( int $event_id ): bool {
		return self::counts( $event_id )['drawsheets'] > 0;
	}

	/**
	 * Whether the event has at least one uploaded result PDF (drives the
	 * conditional "Results" button on the event-list cards).
	 *
	 * @param int $event_id en_event post ID.
	 * @return bool
	 */
	public static function has_results( int $event_id ): bool {
		return self::counts( $event_id )['results'] > 0;
	}

	/**
	 * Coerce a raw DB row into a typed associative array.
	 *
	 * @param array $row Raw row from $wpdb.
	 * @return array
	 */
	private static function shape_row( array $row ): array {
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'event_id'      => (int) ( $row['event_id'] ?? 0 ),
			'discipline_id' => (int) ( $row['discipline_id'] ?? 0 ),
			'label'         => (string) ( $row['label'] ?? '' ),
			'round'         => (string) ( $row['round'] ?? '' ),
			'entry_date'    => isset( $row['entry_date'] ) && $row['entry_date'] ? (string) $row['entry_date'] : '',
			'drawsheet_pdf' => (int) ( $row['drawsheet_pdf'] ?? 0 ),
			'result_pdf'    => (int) ( $row['result_pdf'] ?? 0 ),
			'sort_order'    => (int) ( $row['sort_order'] ?? 0 ),
		);
	}
}
