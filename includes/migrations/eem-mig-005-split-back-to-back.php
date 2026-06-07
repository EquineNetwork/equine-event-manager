<?php
/**
 * Migration #005 — split back-to-back stall/RV rows into one-sided rows.
 *
 * The back-to-back layout (with its "AISLE" divider) was removed because it
 * implied a physical facility layout we can't guarantee. Each back-to-back row
 * is rewritten as up to two independent one-sided rows so no stalls/lots are
 * lost: the top side keeps the original row name, the bottom side gets a
 * "(2)" suffix. One-sided rows pass through untouched (their now-unused
 * top/bottom fields are cleared). Runs once per install (flag-gated, idempotent).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a single rows-meta array: split back-to-back rows, clear stale
 * side fields on one-sided rows.
 *
 * @param array<int,mixed> $rows Raw rows (each: name, layout, first, last, top_*, bot_*).
 * @return array{0:array<int,array<string,string>>,1:bool} [new rows, changed?]
 */
function eem_mig_005_normalize_rows( $rows ) {
	$out     = array();
	$changed = false;
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$g = static function ( $k ) use ( $row ) {
			return trim( (string) ( $row[ $k ] ?? '' ) );
		};
		if ( 'back-to-back' === (string) ( $row['layout'] ?? 'one-sided' ) ) {
			$changed = true;
			$name    = $g( 'name' );
			if ( '' !== $g( 'top_first' ) || '' !== $g( 'top_last' ) ) {
				$out[] = array(
					'name' => $name, 'layout' => 'one-sided',
					'first' => $g( 'top_first' ), 'last' => $g( 'top_last' ),
					'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '',
				);
			}
			if ( '' !== $g( 'bot_first' ) || '' !== $g( 'bot_last' ) ) {
				$out[] = array(
					'name' => '' !== $name ? $name . ' (2)' : '', 'layout' => 'one-sided',
					'first' => $g( 'bot_first' ), 'last' => $g( 'bot_last' ),
					'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '',
				);
			}
		} else {
			// Already one-sided — keep, but clear any stale side fields.
			if ( '' !== $g( 'top_first' ) || '' !== $g( 'top_last' ) || '' !== $g( 'bot_first' ) || '' !== $g( 'bot_last' ) ) {
				$changed = true;
			}
			$out[] = array(
				'name' => $g( 'name' ), 'layout' => 'one-sided',
				'first' => $g( 'first' ), 'last' => $g( 'last' ),
				'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '',
			);
		}
	}
	return array( $out, $changed );
}

/**
 * Run the back-to-back split across all reservations.
 *
 * @return array{scanned:int, updated:int}
 */
function eem_mig_005_split_back_to_back() {
	$flag = 'eem_mig_005_split_back_to_back_complete';
	if ( get_option( $flag ) ) {
		return array( 'scanned' => 0, 'updated' => 0 );
	}

	$scanned = 0;
	$updated = 0;
	$paged   = 1;

	do {
		$ids = get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		if ( empty( $ids ) ) {
			break;
		}
		foreach ( $ids as $rid ) {
			$scanned++;
			$touched = false;
			foreach ( array( '_en_stall_rows', '_en_rv_rows' ) as $key ) {
				$rows = get_post_meta( (int) $rid, $key, true );
				if ( ! is_array( $rows ) || empty( $rows ) ) {
					continue;
				}
				list( $new_rows, $changed ) = eem_mig_005_normalize_rows( $rows );
				if ( $changed ) {
					update_post_meta( (int) $rid, $key, $new_rows );
					$touched = true;
				}
			}
			if ( $touched ) {
				$updated++;
			}
		}
		$paged++;
	} while ( count( $ids ) === 200 );

	update_option( $flag, time() );
	return array( 'scanned' => $scanned, 'updated' => $updated );
}
