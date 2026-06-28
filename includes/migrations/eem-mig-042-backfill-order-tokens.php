<?php
/**
 * Migration #042 — backfill an unguessable submission token onto every legacy
 * order that lacks one, and atomically repoint all order_key-keyed aux tables
 * from the order's old (guessable) composite-derived key to the new md5(token)
 * key. This is the EXISTING-DATA half of ship-readiness 4.1 (the #32 forward
 * fix only covered NEW imports/checkouts).
 *
 * Background — how an order is keyed
 * ----------------------------------
 * An order's `order_key` is `md5( build_group_key($row) )`. `build_group_key`
 * returns the row's `Submission token:` note when present, else a GUESSABLE
 * composite (event_source|event_id|external_event_id|customer_name|email|phone|
 * created_at|reservation_id). Any order whose component rows carry no token
 * therefore gets a brute-forceable order_key — and that key is the bearer
 * credential for the hosted receipt + document downloads (IDOR).
 *
 * What this migration does, per tokenless order
 * ---------------------------------------------
 *  1. Group every token-free component row (across eem_stall_reservations +
 *     eem_rv_reservations) by its CURRENT order_key — `order_key_for_row()`, the
 *     same md5 the read path + aux tables use, so we match exactly what's stored.
 *  2. Generate ONE uuid4 token for the order; new_key = md5(token).
 *  3. Append "Submission token: <uuid>" to every component row's notes (so all
 *     future reads compute md5(token)).
 *  4. UPDATE every order_key-keyed aux table (order_adjustments, order_payments,
 *     activity_log, division_entries, order_documents) SET order_key = new_key
 *     WHERE order_key = old_key — so no document / payment / log row is orphaned.
 *  All four writes for one order run inside a transaction: either the order is
 *  fully re-keyed or not at all.
 *
 * Idempotent / flag-gated: only token-free rows are candidates, so a second run
 * (and a run after the forward fix has tokenized all new orders) finds nothing.
 * On a database where every order already carries a token — the expected state
 * after #32 plus a reseed — this is a safe no-op.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill submission tokens + repoint aux tables for legacy tokenless orders.
 *
 * @return array{orders:int, rows:int, aux:int} Orders re-keyed, component rows
 *         tokenized, and aux-table rows repointed (for telemetry/verification).
 */
function eem_mig_042_backfill_order_tokens(): array {
	global $wpdb;

	$flag = 'eem_mig_042_backfill_order_tokens_complete';
	if ( get_option( $flag ) ) {
		return array( 'orders' => 0, 'rows' => 0, 'aux' => 0 );
	}

	if ( ! class_exists( 'EEM_Orders_Repository' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-orders-repository.php';
	}
	$repo = new EEM_Orders_Repository();

	$component_tables = array(
		$wpdb->prefix . 'eem_stall_reservations',
		$wpdb->prefix . 'eem_rv_reservations',
	);

	// Every table that stores order_key as a bearer/foreign key. Missing one
	// here = orphaned data after the re-key, so this list is exhaustive (verified
	// against the activator schema + each repo's runtime queries).
	$aux_tables = array(
		$wpdb->prefix . 'eem_order_adjustments',
		$wpdb->prefix . 'eem_order_payments',
		$wpdb->prefix . 'eem_activity_log',
		$wpdb->prefix . 'eem_division_entries',
		$wpdb->prefix . 'eem_order_documents',
	);

	// 1. Gather token-free component rows and bucket them by current order_key.
	//    Each bucket = one legacy order spanning >= 1 row across both tables.
	$buckets = array(); // old_key => [ ['table'=>..,'id'=>..,'notes'=>..], .. ]

	foreach ( $component_tables as $table ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$rows = $wpdb->get_results(
			"SELECT id, event_source, event_id, external_event_id, customer_name, email, phone, created_at, notes
			 FROM {$table}
			 WHERE notes NOT LIKE '%Submission token:%'",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$old_key = $repo->order_key_for_row( $row );
			$buckets[ $old_key ][] = array(
				'table' => $table,
				'id'    => (int) $row['id'],
				'notes' => (string) $row['notes'],
			);
		}
	}

	$orders_done = 0;
	$rows_done   = 0;
	$aux_done    = 0;

	// 2. Re-key each legacy order atomically.
	foreach ( $buckets as $old_key => $members ) {
		$token   = wp_generate_uuid4();
		$new_key = md5( $token );

		// Paranoia: a fresh uuid can't collide, but never clobber a real order.
		if ( $new_key === $old_key ) {
			continue;
		}

		$wpdb->query( 'START TRANSACTION' );
		$ok = true;

		// 2a. Tokenize each component row (append the token line to notes).
		foreach ( $members as $m ) {
			$new_notes = rtrim( $m['notes'], "\n" ) . "\nSubmission token: " . $token;
			$res       = $wpdb->update(
				$m['table'],
				array( 'notes' => $new_notes ),
				array( 'id' => $m['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $res ) {
				$ok = false;
				break;
			}
		}

		// 2b. Repoint every aux table from old_key -> new_key.
		if ( $ok ) {
			foreach ( $aux_tables as $aux ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aux ) ) ) {
					continue;
				}
				$res = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
						"UPDATE {$aux} SET order_key = %s WHERE order_key = %s",
						$new_key,
						$old_key
					)
				);
				if ( false === $res ) {
					$ok = false;
					break;
				}
				$aux_done += (int) $res;
			}
		}

		if ( $ok ) {
			$wpdb->query( 'COMMIT' );
			$orders_done++;
			$rows_done += count( $members );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	update_option( $flag, time() );
	return array( 'orders' => $orders_done, 'rows' => $rows_done, 'aux' => $aux_done );
}
