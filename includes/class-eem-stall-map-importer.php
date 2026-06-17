<?php
/**
 * Stall Map model + helpers (v4 Stall Mapping).
 *
 * Holds the facility-map snapshot on a reservation and the helpers every consumer
 * reads (customer picker, admin chart, auto-assign). A snapshot is
 * `{ source, synced_at, barns:[{ name, kind, rows, cols, grid:[[{type,label}]] }] }`
 * with cell `type ∈ {stall, gap, landmark}`, stored at `_en_stall_map` (stalls)
 * and `_en_rv_map` (RV lots).
 *
 * Maps are authored in the native in-plugin Map Builder, which posts its zones to
 * {@see self::snapshot_from_builder()} (via the eem_map_builder_save handler). The
 * earlier Google-Sheet import path was removed once the builder shipped; existing
 * sheet-imported snapshots keep working unchanged because the stored shape is
 * identical and this class never re-fetches from Google.
 *
 * Stall numbers are globally unique across barns, so a stall's label alone
 * identifies it; RV lots are zone-qualified (lot numbers repeat per zone). Pure
 * PHP, zero dependencies.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds, persists, and reads facility-map snapshots.
 *
 * Caller contract: {@see self::snapshot_from_builder()} turns Map Builder zones
 * into a snapshot; {@see self::save_to_reservation()} persists it; the renderers
 * consume {@see self::get_for_reservation()}. All helpers are pure (no
 * network/DB except the two persistence methods) and unit-testable in isolation.
 */
class EEM_Stall_Map_Importer {

	/**
	 * Post-meta key holding the stall-map snapshot on a reservation.
	 */
	const META_KEY = '_en_stall_map';

	/**
	 * Post-meta key holding the RV-map snapshot (v4 Slice 8 — separate connector).
	 * The RV Reservations section connects its own RV sheet here; every tab is an
	 * RV zone and every numbered cell an RV lot.
	 */
	const RV_META_KEY = '_en_rv_map';

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
	 * Persist a snapshot to a reservation.
	 *
	 * @param int    $reservation_id Reservation post id.
	 * @param array  $snapshot       Snapshot from {@see self::snapshot_from_builder()}.
	 * @param string $meta_key       Which snapshot slot (stall map or RV map).
	 * @return void
	 */
	public static function save_to_reservation( int $reservation_id, array $snapshot, string $meta_key = self::META_KEY ): void {
		update_post_meta( $reservation_id, $meta_key, $snapshot );
	}

	/**
	 * Read a reservation's stored snapshot.
	 *
	 * @param int    $reservation_id Reservation post id.
	 * @param string $meta_key       Which snapshot slot (stall map or RV map).
	 * @return array Snapshot, or an empty array if none stored.
	 */
	public static function get_for_reservation( int $reservation_id, string $meta_key = self::META_KEY ): array {
		$snap = get_post_meta( $reservation_id, $meta_key, true );
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
	 * Total available stall inventory = every cell that carries a stall number,
	 * summed across ALL barns/tabs in the snapshot.
	 *
	 * This is the authoritative inventory count when a reservation is map-driven
	 * (Numbered + Pick-from-layout, Option A): if a cell has a number it counts.
	 *
	 * @param array $snapshot Snapshot structure.
	 * @return int Total stall count across all barns.
	 */
	public static function count_stalls( array $snapshot ): int {
		return count( self::stall_labels( $snapshot ) );
	}

	/**
	 * Per-barn stall counts, keyed by barn name (in tab order).
	 *
	 * @param array $snapshot Snapshot structure.
	 * @return array<string,int>
	 */
	public static function barn_stall_counts( array $snapshot ): array {
		$counts = array();
		foreach ( ( $snapshot['barns'] ?? array() ) as $barn ) {
			$n = 0;
			foreach ( ( $barn['grid'] ?? array() ) as $row ) {
				foreach ( $row as $cell ) {
					if ( 'stall' === ( $cell['type'] ?? '' ) ) {
						$n++;
					}
				}
			}
			$counts[ (string) ( $barn['name'] ?? '' ) ] = $n;
		}
		return $counts;
	}

	/**
	 * Per-barn status breakdown for the admin (total / available / reserved /
	 * tack / blocked), keyed by barn name in tab order.
	 *
	 * The map supplies *which stalls exist* per barn; the caller supplies the
	 * operational status of each stall (from orders + admin actions) as a
	 * `label => status` map. Any stall not present in `$status_map` counts as
	 * 'available'. Keeps this method pure (no DB) and unit-testable; the admin
	 * renderer builds `$status_map` from the assignment data.
	 *
	 * Stall labels are globally unique, so `$status_map` is keyed by bare label.
	 * RV lots repeat per zone, so pass `$zone_qualified = true` to key the lookup
	 * by "{barn name} {label}" (e.g. "Red Lot 1") — matching the zone-qualified
	 * units the customer/admin RV surfaces store.
	 *
	 * @param array                 $snapshot       Snapshot structure.
	 * @param array<string,string>  $status_map     unit => 'reserved'|'tack'|'blocked'|'available'.
	 * @param bool                  $zone_qualified Key the lookup by "{barn} {label}" (RV).
	 * @return array<string,array{total:int,available:int,reserved:int,tack:int,blocked:int}>
	 */
	public static function barn_stats( array $snapshot, array $status_map = array(), bool $zone_qualified = false ): array {
		$stats = array();
		foreach ( ( $snapshot['barns'] ?? array() ) as $barn ) {
			$bname = (string) ( $barn['name'] ?? '' );
			$row   = array( 'total' => 0, 'available' => 0, 'reserved' => 0, 'tack' => 0, 'blocked' => 0 );
			foreach ( ( $barn['grid'] ?? array() ) as $grow ) {
				foreach ( $grow as $cell ) {
					if ( 'stall' !== ( $cell['type'] ?? '' ) ) {
						continue;
					}
					$row['total']++;
					$key    = $zone_qualified ? ( $bname . ' ' . (string) $cell['label'] ) : (string) $cell['label'];
					$status = $status_map[ $key ] ?? 'available';
					if ( ! isset( $row[ $status ] ) ) {
						$status = 'available';
					}
					$row[ $status ]++;
				}
			}
			$stats[ $bname ] = $row;
		}
		return $stats;
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

	/**
	 * A barn's kind, defaulting to 'stall' for snapshots imported before Slice 8.
	 *
	 * @param array $barn Barn structure.
	 * @return string 'rv' | 'stall'
	 */
	public static function barn_kind( array $barn ): string {
		$kind = isset( $barn['kind'] ) ? (string) $barn['kind'] : '';
		return 'rv' === $kind ? 'rv' : 'stall';
	}

	/**
	 * Barns of a given kind ('stall' | 'rv'), in tab order.
	 *
	 * @param array  $snapshot Snapshot structure.
	 * @param string $kind     'stall' | 'rv'.
	 * @return array<int,array> Matching barn structures.
	 */
	public static function barns_of_kind( array $snapshot, string $kind ): array {
		$kind = 'rv' === $kind ? 'rv' : 'stall';
		return array_values( array_filter( (array) ( $snapshot['barns'] ?? array() ), static function ( $b ) use ( $kind ) {
			return self::barn_kind( (array) $b ) === $kind;
		} ) );
	}

	/**
	 * A snapshot restricted to barns of one kind (so the existing stall helpers —
	 * count_stalls, barn_stats, stall_labels — operate on just that kind).
	 *
	 * @param array  $snapshot Snapshot structure.
	 * @param string $kind     'stall' | 'rv'.
	 * @return array Snapshot with only matching barns.
	 */
	public static function snapshot_of_kind( array $snapshot, string $kind ): array {
		$snapshot['barns'] = self::barns_of_kind( $snapshot, $kind );
		return $snapshot;
	}

	/**
	 * Build a stored snapshot from native Map Builder grid data.
	 *
	 * The in-plugin Map Builder (replacing the Google-Sheet import) posts an array
	 * of zones, each a rectangular grid of cells. This sanitises that payload into
	 * the canonical snapshot shape every consumer already understands — same
	 * structure the old sheet import produced, with `source` = 'builder'. Every
	 * zone's `kind` is forced to $kind because the builder edits one map slot
	 * (the stall map OR the RV map) at a time.
	 *
	 * @param array  $barns Raw zones: [ ['name'=>string,'grid'=>[[ ['type'=>string,'label'=>string] ]] ], ... ].
	 * @param string $kind  'stall' | 'rv'.
	 * @return array{source:string,synced_at:int,barns:array}
	 */
	public static function snapshot_from_builder( array $barns, string $kind = 'stall' ): array {
		$kind = 'rv' === $kind ? 'rv' : 'stall';
		$out  = array();
		foreach ( $barns as $barn ) {
			if ( ! is_array( $barn ) ) {
				continue;
			}
			$name     = isset( $barn['name'] ) ? sanitize_text_field( (string) $barn['name'] ) : '';
			$raw_grid = ( isset( $barn['grid'] ) && is_array( $barn['grid'] ) ) ? $barn['grid'] : array();
			$grid     = array();
			$cols     = 0;
			foreach ( $raw_grid as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$out_row = array();
				foreach ( $row as $cell ) {
					$out_row[] = self::sanitize_builder_cell( is_array( $cell ) ? $cell : array() );
				}
				$cols   = max( $cols, count( $out_row ) );
				$grid[] = $out_row;
			}
			// Pad every row to the widest so the stored grid stays rectangular.
			foreach ( $grid as &$grow ) {
				while ( count( $grow ) < $cols ) {
					$grow[] = array( 'type' => 'gap', 'label' => '' );
				}
			}
			unset( $grow );
			if ( '' === $name ) {
				/* translators: %d: zone number */
				$name = sprintf( __( 'Zone %d', 'equine-event-manager' ), count( $out ) + 1 );
			}
			$out[] = array(
				'name'      => $name,
				'kind'      => $kind,
				'rows'      => count( $grid ),
				'cols'      => $cols,
				'grid'      => $grid,
				// Slice 3 surcharge owners: a tab-level surcharge applied to every
				// cell in the barn, plus a registry of painted areas (cell subsets)
				// each with its own per-rate-type surcharge. Stacked at pricing time
				// by {@see self::surcharge_for_unit()}.
				'surcharge' => EEM_Surcharge::sanitize( isset( $barn['surcharge'] ) ? $barn['surcharge'] : array() ),
				'areas'     => self::sanitize_areas( ( isset( $barn['areas'] ) && is_array( $barn['areas'] ) ) ? $barn['areas'] : array() ),
			);
		}
		return array(
			'source'    => 'builder',
			'synced_at' => time(),
			'barns'     => $out,
		);
	}

	/**
	 * Sanitise one Map Builder cell into the canonical {type,label} cell language.
	 *
	 * Anything that is not an explicitly-labelled stall or landmark collapses to a
	 * gap (aisle), so a malformed/partial cell can never become sellable inventory.
	 *
	 * @param array $cell Raw cell from the builder payload.
	 * @return array{type:string,label:string}
	 */
	private static function sanitize_builder_cell( array $cell ): array {
		$type  = isset( $cell['type'] ) ? (string) $cell['type'] : 'gap';
		$label = isset( $cell['label'] ) ? sanitize_text_field( (string) $cell['label'] ) : '';
		if ( 'stall' === $type && '' !== $label ) {
			$out = array( 'type' => 'stall', 'label' => $label );
			// Slice 3: a stall/lot cell may belong to a painted surcharge area and
			// may be the anchor of a multi-cell unit (paddock) spanning w×h cells.
			if ( isset( $cell['area'] ) && '' !== (string) $cell['area'] ) {
				$out['area'] = sanitize_key( (string) $cell['area'] );
			}
			$w = isset( $cell['w'] ) ? (int) $cell['w'] : 1;
			$h = isset( $cell['h'] ) ? (int) $cell['h'] : 1;
			if ( $w > 1 ) {
				$out['w'] = $w;
			}
			if ( $h > 1 ) {
				$out['h'] = $h;
			}
			return $out;
		}
		if ( 'landmark' === $type && '' !== $label ) {
			return array( 'type' => 'landmark', 'label' => $label );
		}
		return array( 'type' => 'gap', 'label' => '' );
	}

	/**
	 * Sanitise the per-barn painted-area registry. Each area is a named, colored
	 * subset of cells carrying its own per-rate-type surcharge.
	 *
	 * @param array $areas Raw areas from the builder payload.
	 * @return array<int,array{id:string,name:string,color:string,surcharge:array}>
	 */
	private static function sanitize_areas( array $areas ): array {
		$out = array();
		foreach ( $areas as $area ) {
			if ( ! is_array( $area ) ) {
				continue;
			}
			$id = isset( $area['id'] ) ? sanitize_key( (string) $area['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'        => $id,
				'name'      => isset( $area['name'] ) ? sanitize_text_field( (string) $area['name'] ) : '',
				'color'     => isset( $area['color'] ) ? ( sanitize_hex_color( (string) $area['color'] ) ?: '' ) : '',
				'surcharge' => EEM_Surcharge::sanitize( isset( $area['surcharge'] ) ? $area['surcharge'] : array() ),
			);
		}
		return $out;
	}

	/**
	 * Effective per-rate-type surcharge for one sellable unit (stall/lot), stacking
	 * the barn's tab-level surcharge with the painted area the cell belongs to
	 * ("most layers add"). Returns the canonical EEM_Surcharge value.
	 *
	 * @param array  $snapshot  Map snapshot.
	 * @param string $barn_name Barn/zone name (case-insensitive match).
	 * @param string $label     Stall/lot label.
	 * @return array{nightly:float,packages:array<string,float>}
	 */
	public static function surcharge_for_unit( array $snapshot, string $barn_name, string $label ): array {
		$barn = null;
		$bn   = strtolower( trim( $barn_name ) );
		foreach ( ( $snapshot['barns'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && strtolower( trim( (string) ( $candidate['name'] ?? '' ) ) ) === $bn ) {
				$barn = $candidate;
				break;
			}
		}
		if ( ! $barn ) {
			return EEM_Surcharge::zero();
		}

		$total = EEM_Surcharge::sanitize( $barn['surcharge'] ?? array() ); // Tab-level.

		// Resolve the cell's painted area, then stack that area's surcharge.
		$area_id = '';
		foreach ( ( $barn['grid'] ?? array() ) as $row ) {
			foreach ( (array) $row as $cell ) {
				if ( is_array( $cell ) && 'stall' === ( $cell['type'] ?? '' ) && (string) ( $cell['label'] ?? '' ) === (string) $label ) {
					$area_id = isset( $cell['area'] ) ? (string) $cell['area'] : '';
					break 2;
				}
			}
		}
		if ( '' !== $area_id ) {
			foreach ( ( $barn['areas'] ?? array() ) as $area ) {
				if ( is_array( $area ) && (string) ( $area['id'] ?? '' ) === $area_id ) {
					$total = EEM_Surcharge::add( $total, $area['surcharge'] ?? array() );
					break;
				}
			}
		}
		return $total;
	}
}
