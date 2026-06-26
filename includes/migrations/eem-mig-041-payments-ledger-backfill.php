<?php
/**
 * Migration #041 — backfill the per-order payments ledger
 * ({prefix}eem_order_payments) from the legacy single-set-of-payment-columns
 * stored on each order component row (en_stall_reservations / en_rv_reservations).
 *
 * Before the ledger existed, the only record of money movement was the component
 * columns: amount_paid / payment_gateway / transaction_id (the collection) and
 * refunded_amount / refund_transaction_id / refunded_at (the return). That can't
 * represent a mixed-tender order, but for EXISTING orders it's the only source we
 * have, so this synthesizes one ledger entry per non-zero column set:
 *
 *  - amount_paid > 0    -> a 'payment' entry (gateway = component gateway, or a
 *                          manual tender derived from the notes when no gateway).
 *  - refunded_amount > 0 -> a 'refund' entry (same gateway, refund transaction id).
 *
 * Each component row is its own money line (the stall portion and RV portion are
 * collected/refunded independently in this schema), so iterating rows — not orders
 * — produces faithful per-tender lines without double counting.
 *
 * Idempotent: skips any order that already has ledger entries (so re-running, or
 * running after new orders have written their own entries, is safe). Reads only
 * payment columns — no config hydration — so it's safe on large seeded sites.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill the payments ledger from legacy component payment columns.
 *
 * @return array{payments:int, refunds:int} Counts of ledger rows created.
 */
function eem_mig_041_payments_ledger_backfill(): array {
	global $wpdb;

	$flag = 'eem_mig_041_payments_ledger_backfill_complete';
	if ( get_option( $flag ) ) {
		return array( 'payments' => 0, 'refunds' => 0 );
	}

	if ( ! class_exists( 'EEM_Order_Payments_Repo' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-order-payments-repo.php';
	}

	$tables  = array(
		$wpdb->prefix . 'en_stall_reservations',
		$wpdb->prefix . 'en_rv_reservations',
	);
	$payments = 0;
	$refunds  = 0;

	// Track which orders already had entries (either from a prior run, or from a
	// new payment recorded after the ledger shipped) so the backfill never doubles.
	$seen = array();

	foreach ( $tables as $table ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$rows = $wpdb->get_results(
			"SELECT order_key, amount_paid, payment_gateway, transaction_id, refunded_amount, refund_transaction_id, refunded_at, notes, created_at
			 FROM {$table}
			 WHERE order_key <> '' AND ( amount_paid > 0 OR refunded_amount > 0 )",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$order_key = (string) $row['order_key'];
			if ( '' === $order_key ) {
				continue;
			}

			if ( ! isset( $seen[ $order_key ] ) ) {
				$seen[ $order_key ] = EEM_Order_Payments_Repo::has_entries( $order_key );
			}
			if ( $seen[ $order_key ] ) {
				continue; // Order already has a faithful ledger; don't synthesize over it.
			}

			$gateway   = (string) $row['payment_gateway'];
			$is_gw     = EEM_Order_Payments_Repo::is_gateway( $gateway );
			$paid      = round( (float) $row['amount_paid'], 2 );
			$refunded  = round( (float) $row['refunded_amount'], 2 );

			if ( $paid > 0 ) {
				if ( $is_gw ) {
					$method  = 'Card';
					$gw_slug = $gateway;
				} else {
					$gw_slug = 'manual';
					$method  = eem_mig_041_detect_manual_method( (string) $row['notes'] );
				}

				$entry = array(
					'order_key'      => $order_key,
					'direction'      => EEM_Order_Payments_Repo::DIRECTION_PAYMENT,
					'method'         => $method,
					'gateway'        => $gw_slug,
					'amount'         => $paid,
					'transaction_id' => $is_gw ? (string) $row['transaction_id'] : '',
				);
				if ( '' !== (string) $row['created_at'] ) {
					$entry['created_at'] = (string) $row['created_at'];
				}
				if ( false !== EEM_Order_Payments_Repo::record( $entry ) ) {
					$payments++;
				}
			}

			if ( $refunded > 0 ) {
				$entry = array(
					'order_key'      => $order_key,
					'direction'      => EEM_Order_Payments_Repo::DIRECTION_REFUND,
					'method'         => $is_gw ? 'Card' : eem_mig_041_detect_manual_method( (string) $row['notes'] ),
					'gateway'        => $is_gw ? $gateway : 'manual',
					'amount'         => $refunded,
					'transaction_id' => (string) $row['refund_transaction_id'],
				);
				if ( '' !== (string) $row['refunded_at'] ) {
					$entry['created_at'] = (string) $row['refunded_at'];
				}
				if ( false !== EEM_Order_Payments_Repo::record( $entry ) ) {
					$refunds++;
				}
			}
		}
	}

	update_option( $flag, time() );
	return array( 'payments' => $payments, 'refunds' => $refunds );
}

/**
 * Best-effort detection of a manual tender from a component's free-text notes.
 *
 * @param string $notes Component notes.
 * @return string 'Check', 'Cash', or '' (generic manual).
 */
function eem_mig_041_detect_manual_method( string $notes ): string {
	$lower = strtolower( $notes );
	if ( false !== strpos( $lower, 'check' ) || false !== strpos( $lower, 'cheque' ) ) {
		return 'Check';
	}
	if ( false !== strpos( $lower, 'cash' ) ) {
		return 'Cash';
	}
	return '';
}
