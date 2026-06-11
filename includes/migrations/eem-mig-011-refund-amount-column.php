<?php
/**
 * Migration #011 — backfill the numeric `refunded_amount` column on the stall +
 * RV order tables (security audit MEDIUM: refund ledger hardening).
 *
 * The refunded-to-date amount was historically stored ONLY inside the mutable
 * free-text `notes` column as a `Refunded Amount: N` line. Any later rewrite of
 * `notes` could drop that line and silently re-open the full refundable balance
 * (over-refund risk). A dedicated numeric column is now the authoritative ledger;
 * this migration seeds it for every pre-existing row by re-deriving the value
 * from EXACTLY the source the live read used (`get_component_refunded_amount`):
 *   1. the `Refunded Amount:` notes line, if present; else
 *   2. the component `total`, if `payment_status === 'refunded'`; else 0.
 *
 * Because the backfill uses the same derivation the system reads today, the
 * column matches current behavior precisely at migration time — no balance moves.
 *
 * Idempotent / flag-gated: only touches rows where `refunded_amount <= 0` (the
 * schema default) and a refund signal exists. Bounded row count (one row per
 * stall/RV component, not per unit).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill `refunded_amount` on both order tables from the legacy notes ledger.
 *
 * @return array{updated:int} Count of rows updated (for telemetry/verification).
 */
function eem_mig_011_refund_amount_column() {
	global $wpdb;

	$flag = 'eem_mig_011_refund_amount_column_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$updated = 0;

	foreach ( array( 'en_stall_reservations', 'en_rv_reservations' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		// Guard: the column must exist (create_reservation_tables runs first).
		$has_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'refunded_amount'",
				$table
			)
		);
		if ( ! $has_col ) {
			continue;
		}

		// Only rows that are still at the default 0 and carry a refund signal.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from a known suffix.
		$rows = $wpdb->get_results(
			"SELECT id, total, payment_status, notes FROM `{$table}`
			 WHERE ( refunded_amount IS NULL OR refunded_amount <= 0 )
			   AND ( notes LIKE '%Refunded Amount:%' OR payment_status = 'refunded' )",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$amount = 0.0;

			// (1) Prefer the explicit notes ledger line, matching the live read.
			if ( preg_match( '/(?:^|\n)\s*Refunded Amount:\s*([0-9.,\-]+)/i', (string) $row['notes'], $m ) ) {
				$amount = (float) preg_replace( '/[^0-9.\-]/', '', $m[1] );
			} elseif ( 'refunded' === (string) $row['payment_status'] ) {
				// (2) Fully-refunded with no explicit line → the whole total.
				$amount = (float) $row['total'];
			}

			$amount = max( 0.0, $amount );

			if ( $amount > 0 ) {
				$wpdb->update(
					$table,
					array( 'refunded_amount' => number_format( $amount, 2, '.', '' ) ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
				$updated++;
			}
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
