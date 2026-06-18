<?php
/**
 * Migration #029 — backfill the numeric `amount_paid` column on the stall + RV
 * order tables (Order Edit foundation: charge-the-difference correctness).
 *
 * Orders historically had no record of how much money was actually collected —
 * "amount due" was INFERRED from `payment_status` (paid ⇒ assume the full total
 * was collected). That inference breaks the moment an item is added to an
 * already-paid order: the total rises but the system still assumes the full new
 * total was paid, so it shows $0 due instead of the delta. A dedicated
 * `amount_paid` column makes the collected figure authoritative, so
 * `amount_due = total - amount_paid` is correct in every case.
 *
 * `amount_paid` is GROSS collected (what the customer handed over), independent
 * of refunds — refunds are tracked separately in `refunded_amount` and do NOT
 * increase what is owed. So a fully-paid-then-partially-refunded order has
 * amount_paid = total and amount_due = 0 (the customer owes nothing more).
 *
 * Backfill rule, matching the system's current inference exactly so no balance
 * moves at migration time:
 *   - paid / refunded / partially-refunded / captured / completed → amount_paid = total
 *   - everything else (pending / unpaid / invoice-sent / partially-paid /
 *     cancelled) → amount_paid = 0 (collected amount unknown / nothing collected)
 *
 * Idempotent / flag-gated: only touches rows still at the schema default
 * (amount_paid <= 0) in a paid-like state. Bounded row count (one row per
 * stall/RV component).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill `amount_paid` on both order tables from the current payment status.
 *
 * @return array{updated:int} Count of rows updated (for telemetry/verification).
 */
function eem_mig_029_amount_paid_column() {
	global $wpdb;

	$flag = 'eem_mig_029_amount_paid_column_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$paid_like = array( 'paid', 'refunded', 'partially-refunded', 'captured', 'completed' );
	$updated   = 0;

	foreach ( array( 'en_stall_reservations', 'en_rv_reservations' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		// Guard: the column must exist (create_reservation_tables runs first).
		$has_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'amount_paid'",
				$table
			)
		);
		if ( ! $has_col ) {
			continue;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $paid_like ), '%s' ) );

		// Only rows still at the default 0 whose status implies the total was paid.
		// The `total` column is pre-tax (CLEANUP #9), so the gross charged amount is
		// total + tax — that's what was collected.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a known suffix; values are placeholdered.
		$sql = $wpdb->prepare(
			"SELECT id, total, tax FROM `{$table}`
			 WHERE ( amount_paid IS NULL OR amount_paid <= 0 )
			   AND payment_status IN ( {$placeholders} )",
			$paid_like
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			$amount = max( 0.0, (float) $row['total'] + (float) $row['tax'] );
			if ( $amount <= 0 ) {
				continue;
			}
			$wpdb->update(
				$table,
				array( 'amount_paid' => number_format( $amount, 2, '.', '' ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			$updated++;
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
