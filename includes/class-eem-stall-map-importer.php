<?php
/**
 * Stall Map importer (v4 Stall Mapping — Phase A, Slice 1).
 *
 * Turns a Google Sheet "Publish to web" link into a stored facility-map snapshot
 * on a reservation. One published sheet = one facility; **every tab is a barn**
 * (locked decision 2026-06-07). The admin pastes ONE published URL; we discover
 * all barn tabs from it, fetch each tab's CSV, parse the grid (number = stall,
 * blank = aisle/gap, text = marked area), and snapshot the result to
 * `_en_stall_map`. The customer/admin renderers read from that snapshot — we do
 * NOT render live from Google (a "Refresh" re-pulls).
 *
 * Stall numbers are globally unique across barns, so a stall's label alone
 * identifies it (no barn namespacing). Pure-PHP CSV parsing, zero dependencies.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches, parses, and persists spreadsheet-driven stall maps.
 *
 * Caller contract: {@see self::import()} performs network I/O (returns a snapshot
 * array or WP_Error); {@see self::save_to_reservation()} persists it; the
 * renderers consume {@see self::get_for_reservation()}. All parsing helpers are
 * pure (no network/DB) and unit-testable in isolation.
 */
class EEM_Stall_Map_Importer {

	/**
	 * Post-meta key holding the snapshot on a reservation.
	 */
	const META_KEY = '_en_stall_map';

	/**
	 * Extract the published-document key from a "Publish to web" URL.
	 *
	 * Accepts the published form (`/spreadsheets/d/e/{KEY}/...`). The private
	 * edit form (`/spreadsheets/d/{ID}/edit`) is intentionally rejected — it
	 * requires authentication and cannot be fetched.
	 *
	 * @param string $url Pasted sheet URL.
	 * @return string|null The `2PACX-…` key, or null if not a published URL.
	 */
	public static function extract_published_key( string $url ): ?string {
		if ( preg_match( '#/spreadsheets/d/e/([^/]+)/#', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Build the published `pubhtml` URL for a document key.
	 *
	 * @param string $key Published document key.
	 * @return string
	 */
	private static function pubhtml_url( string $key ): string {
		return 'https://docs.google.com/spreadsheets/d/e/' . $key . '/pubhtml';
	}

	/**
	 * Build the published CSV URL for a single tab (by gid).
	 *
	 * @param string $key Published document key.
	 * @param string $gid Tab gid.
	 * @return string
	 */
	private static function tab_csv_url( string $key, string $gid ): string {
		return 'https://docs.google.com/spreadsheets/d/e/' . $key . '/pub?gid=' . rawurlencode( $gid ) . '&single=true&output=csv';
	}

	/**
	 * Discover every barn tab (name + gid, in tab order) in a published sheet.
	 *
	 * Parses the `pubhtml` page's embedded sheet list
	 * (`items.push({name: "…", … gid: "…"})`).
	 *
	 * @param string $key Published document key.
	 * @return array<int,array{name:string,gid:string}>|WP_Error
	 */
	public static function discover_barns( string $key ) {
		$res = wp_remote_get( self::pubhtml_url( $key ), array( 'timeout' => 15, 'redirection' => 5 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'eem_stall_map_pubhtml', __( 'Could not read the published sheet. Make sure it is published to the web.', 'equine-event-manager' ) );
		}
		$html = (string) wp_remote_retrieve_body( $res );
		$barns = array();
		if ( preg_match_all( '/items\.push\(\{name:\s*"([^"]+)"[^}]*?gid:\s*"(\d+)"/s', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$barns[] = array(
					'name' => trim( html_entity_decode( $row[1], ENT_QUOTES ) ),
					'gid'  => $row[2],
				);
			}
		}
		if ( empty( $barns ) ) {
			return new WP_Error( 'eem_stall_map_no_tabs', __( 'No sheet tabs were found in that published link.', 'equine-event-manager' ) );
		}
		return $barns;
	}

	/**
	 * Classify a single raw cell value into the map cell language.
	 *
	 * @param string $value Raw cell text.
	 * @return array{type:string,label:string} type is 'stall' | 'landmark' | 'gap'.
	 */
	public static function classify_cell( string $value ): array {
		$v = trim( $value );
		if ( '' === $v ) {
			return array( 'type' => 'gap', 'label' => '' );
		}
		// Integer (100), prefixed (Y1), or padded-prefixed (A-01) stall labels.
		if ( preg_match( '/^\d+$/', $v ) || preg_match( '/^[A-Za-z]{0,3}-?\d+$/', $v ) ) {
			return array( 'type' => 'stall', 'label' => $v );
		}
		return array( 'type' => 'landmark', 'label' => $v );
	}

	/**
	 * Parse a CSV string into a rectangular grid of classified cells.
	 *
	 * Rows are padded to the widest row so the grid is rectangular. Uses
	 * {@see str_getcsv()} so quoted commas inside a cell are handled.
	 *
	 * @param string $csv Raw CSV text.
	 * @return array<int,array<int,array{type:string,label:string}>>
	 */
	public static function parse_grid( string $csv ): array {
		$lines = preg_split( '/\r\n|\r|\n/', rtrim( $csv, "\r\n" ) );
		$rows  = array();
		$maxc  = 0;
		foreach ( $lines as $line ) {
			$cells = str_getcsv( $line );
			$maxc  = max( $maxc, count( $cells ) );
			$rows[] = $cells;
		}
		$grid = array();
		foreach ( $rows as $cells ) {
			$out = array();
			for ( $c = 0; $c < $maxc; $c++ ) {
				$out[] = self::classify_cell( isset( $cells[ $c ] ) ? (string) $cells[ $c ] : '' );
			}
			$grid[] = $out;
		}
		return $grid;
	}

	/**
	 * Fetch + parse every barn in a published sheet into a snapshot structure.
	 *
	 * @param string $url The pasted "Publish to web" URL.
	 * @return array{source_url:string,key:string,synced_at:int,barns:array}|WP_Error
	 */
	public static function import( string $url ) {
		$key = self::extract_published_key( $url );
		if ( null === $key ) {
			return new WP_Error( 'eem_stall_map_url', __( 'That is not a "Publish to web" link. In Google Sheets use File → Share → Publish to web, then paste that link.', 'equine-event-manager' ) );
		}
		$barns = self::discover_barns( $key );
		if ( is_wp_error( $barns ) ) {
			return $barns;
		}
		$out = array();
		foreach ( $barns as $barn ) {
			$res = wp_remote_get( self::tab_csv_url( $key, $barn['gid'] ), array( 'timeout' => 15, 'redirection' => 5 ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return new WP_Error( 'eem_stall_map_tab', sprintf( /* translators: %s: barn/tab name */ __( 'Could not read the "%s" tab.', 'equine-event-manager' ), $barn['name'] ) );
			}
			$grid = self::parse_grid( (string) wp_remote_retrieve_body( $res ) );
			$out[] = array(
				'name' => $barn['name'],
				'gid'  => $barn['gid'],
				'rows' => count( $grid ),
				'cols' => empty( $grid ) ? 0 : count( $grid[0] ),
				'grid' => $grid,
			);
		}
		return array(
			'source_url' => esc_url_raw( $url ),
			'key'        => $key,
			'synced_at'  => time(),
			'barns'      => $out,
		);
	}

	/**
	 * Persist a snapshot to a reservation.
	 *
	 * @param int   $reservation_id Reservation post id.
	 * @param array $snapshot       Snapshot from {@see self::import()}.
	 * @return void
	 */
	public static function save_to_reservation( int $reservation_id, array $snapshot ): void {
		update_post_meta( $reservation_id, self::META_KEY, $snapshot );
	}

	/**
	 * Read a reservation's stored snapshot.
	 *
	 * @param int $reservation_id Reservation post id.
	 * @return array Snapshot, or an empty array if none stored.
	 */
	public static function get_for_reservation( int $reservation_id ): array {
		$snap = get_post_meta( $reservation_id, self::META_KEY, true );
		return is_array( $snap ) ? $snap : array();
	}

	/**
	 * Flatten every stall label across all barns in a snapshot.
	 *
	 * @param array $snapshot Snapshot structure.
	 * @return array<int,string> Stall labels (order = barn order, then row/col).
	 */
	public static function stall_labels( array $snapshot ): array {
		$labels = array();
		foreach ( ( $snapshot['barns'] ?? array() ) as $barn ) {
			foreach ( ( $barn['grid'] ?? array() ) as $row ) {
				foreach ( $row as $cell ) {
					if ( 'stall' === ( $cell['type'] ?? '' ) ) {
						$labels[] = (string) $cell['label'];
					}
				}
			}
		}
		return $labels;
	}

	/**
	 * Find stall labels that appear more than once across all barns.
	 *
	 * Locked decision: stall numbers are globally unique, so this should return
	 * empty — it is the validation guard surfaced to the admin on import.
	 *
	 * @param array $snapshot Snapshot structure.
	 * @return array<int,string> Duplicated labels (empty when the sheet is clean).
	 */
	public static function find_duplicate_labels( array $snapshot ): array {
		$counts = array_count_values( self::stall_labels( $snapshot ) );
		// array_count_values coerces numeric-string keys to ints — cast back so a
		// label is always a string (matching how labels are used everywhere else).
		return array_values( array_map( 'strval', array_keys( array_filter( $counts, static function ( $n ) {
			return $n > 1;
		} ) ) ) );
	}

	/**
	 * Barn names in tab order.
	 *
	 * @param array $snapshot Snapshot structure.
	 * @return array<int,string>
	 */
	public static function barn_names( array $snapshot ): array {
		return array_map( static function ( $b ) {
			return (string) ( $b['name'] ?? '' );
		}, $snapshot['barns'] ?? array() );
	}
}
