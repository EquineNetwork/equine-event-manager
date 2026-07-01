<?php
/**
 * Order payments ledger repository.
 *
 * Orders in this plugin are stored as 1–2 component rows (eem_stall_reservations /
 * eem_rv_reservations) grouped by order_key. Each component carries a SINGLE set of
 * payment columns (amount_paid, payment_gateway, transaction_id, payment_status),
 * which cannot represent an order settled by more than one tender (e.g. part card,
 * part cash). This repo owns the {prefix}eem_order_payments ledger, which records
 * every individual payment AND refund event against an order so mixed-tender orders
 * are represented faithfully:
 *
 *  - Payment Details lists each tender separately ("Authorize.net — $488.00",
 *    "Cash — $217.80") instead of collapsing to one processor label.
 *  - The refund modal can show how much is refundable per tender and route the card
 *    portion to the gateway while recording the cash portion as a manual refund.
 *
 * The component columns remain the source of truth for order-level totals / status
 * (so existing grouping math is untouched); this ledger is an additive record of the
 * individual money movements that produced those totals. Existing orders are
 * backfilled from the component columns by eem-mig-041.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the per-order payments/refunds ledger.
 */
class EEM_Order_Payments_Repo {

	/**
	 * Ledger direction: money collected from the customer.
	 */
	const DIRECTION_PAYMENT = 'payment';

	/**
	 * Ledger direction: money returned to the customer.
	 */
	const DIRECTION_REFUND = 'refund';

	/**
	 * Fully-qualified ledger table name.
	 *
	 * @return string The prefixed table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eem_order_payments';
	}

	/**
	 * Record a single payment or refund event against an order.
	 *
	 * @param array{
	 *   order_key:string,
	 *   direction?:string,
	 *   method?:string,
	 *   gateway?:string,
	 *   amount:float,
	 *   transaction_id?:string,
	 *   reference?:string,
	 *   reason?:string,
	 *   note?:string,
	 *   created_by?:int,
	 *   created_at?:string
	 * } $args Ledger entry fields. amount is stored as a positive value regardless of direction.
	 * @return int|false Inserted row id, or false on failure / invalid input.
	 */
	public static function record( array $args ) {
		global $wpdb;

		$order_key = isset( $args['order_key'] ) ? trim( (string) $args['order_key'] ) : '';
		$amount    = isset( $args['amount'] ) ? round( abs( (float) $args['amount'] ), 2 ) : 0.0;
		if ( '' === $order_key || $amount <= 0 ) {
			return false;
		}

		$direction = ( isset( $args['direction'] ) && self::DIRECTION_REFUND === $args['direction'] )
			? self::DIRECTION_REFUND
			: self::DIRECTION_PAYMENT;

		$created_at = isset( $args['created_at'] ) && '' !== (string) $args['created_at']
			? (string) $args['created_at']
			: current_time( 'mysql' );

		$created_by = isset( $args['created_by'] ) ? (int) $args['created_by'] : (int) get_current_user_id();

		$transaction_id = isset( $args['transaction_id'] ) ? substr( (string) $args['transaction_id'], 0, 191 ) : '';

		// Idempotency (bug #24 — F1/F2). A gateway payment/refund carries a
		// transaction_id that uniquely identifies it. If a row for the same
		// (order_key, transaction_id, direction) already exists this call is a
		// DUPLICATE — a retried/replayed Stripe webhook, or the hosted-invoice
		// submit racing its own webhook for the same charge — so return the
		// existing row instead of inserting a second one that would double the
		// order's collected / refunded total. Manual cash/check entries carry no
		// transaction_id and are NOT deduped here; those paths guard with a
		// per-order advisory lock instead. (Callers that can race concurrently —
		// the webhook — also take a GET_LOCK so this check-then-insert is atomic.)
		if ( '' !== $transaction_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE order_key = %s AND transaction_id = %s AND direction = %s LIMIT 1',
					$order_key,
					$transaction_id,
					$direction
				)
			);
			if ( $existing_id ) {
				return (int) $existing_id;
			}
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'order_key'      => $order_key,
				'direction'      => $direction,
				'method'         => isset( $args['method'] ) ? substr( (string) $args['method'], 0, 50 ) : '',
				'gateway'        => isset( $args['gateway'] ) ? substr( (string) $args['gateway'], 0, 50 ) : '',
				'amount'         => $amount,
				'transaction_id' => $transaction_id,
				'reference'      => isset( $args['reference'] ) ? substr( (string) $args['reference'], 0, 191 ) : '',
				'reason'         => isset( $args['reason'] ) ? substr( (string) $args['reason'], 0, 255 ) : '',
				'note'           => isset( $args['note'] ) ? substr( (string) $args['note'], 0, 255 ) : '',
				'created_by'     => $created_by,
				'created_at'     => $created_at,
			),
			array( '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Fetch every ledger entry for an order, oldest first.
	 *
	 * @param string $order_key Order key.
	 * @return array<int, array{id:int, direction:string, method:string, gateway:string, amount:float, transaction_id:string, reference:string, reason:string, note:string, created_by:int, created_at:string}>
	 */
	public static function get_for_order( string $order_key ): array {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, direction, method, gateway, amount, transaction_id, reference, reason, note, created_by, created_at FROM ' . self::table() . ' WHERE order_key = %s ORDER BY created_at ASC, id ASC',
				$order_key
			),
			ARRAY_A
		);

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$entries[] = array(
				'id'             => (int) $row['id'],
				'direction'      => (string) $row['direction'],
				'method'         => (string) $row['method'],
				'gateway'        => (string) $row['gateway'],
				'amount'         => (float) $row['amount'],
				'transaction_id' => (string) $row['transaction_id'],
				'reference'      => (string) $row['reference'],
				'reason'         => (string) $row['reason'],
				'note'           => (string) $row['note'],
				'created_by'     => (int) $row['created_by'],
				'created_at'     => (string) $row['created_at'],
			);
		}

		return $entries;
	}

	/**
	 * Whether an order has any ledger entries yet (used by the backfill to stay idempotent).
	 *
	 * @param string $order_key Order key.
	 * @return bool True when at least one ledger row exists for the order.
	 */
	public static function has_entries( string $order_key ): bool {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE order_key = %s', $order_key )
		);

		return (int) $count > 0;
	}

	/**
	 * Sum of payment-direction entries for an order.
	 *
	 * @param string $order_key Order key.
	 * @return float Total collected across all tenders.
	 */
	public static function payments_total( string $order_key ): float {
		return self::direction_total( $order_key, self::DIRECTION_PAYMENT );
	}

	/**
	 * Sum of refund-direction entries for an order.
	 *
	 * @param string $order_key Order key.
	 * @return float Total refunded across all tenders.
	 */
	public static function refunds_total( string $order_key ): float {
		return self::direction_total( $order_key, self::DIRECTION_REFUND );
	}

	/**
	 * Sum the ledger for one direction.
	 *
	 * @param string $order_key Order key.
	 * @param string $direction self::DIRECTION_PAYMENT or self::DIRECTION_REFUND.
	 * @return float Rounded sum.
	 */
	private static function direction_total( string $order_key, string $direction ): float {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return 0.0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . ' WHERE order_key = %s AND direction = %s',
				$order_key,
				$direction
			)
		);

		return round( (float) $sum, 2 );
	}

	/**
	 * Per-tender summary for the refund UI: how much each method collected, how much
	 * of it has already been refunded, and how much remains refundable.
	 *
	 * Tenders are grouped by a (gateway|method) key so a card charge and a cash
	 * payment surface as separate rows. Refund entries are matched back to a tender by
	 * the same key; a refund whose tender can't be matched (legacy/manual) reduces the
	 * cash bucket so totals stay consistent.
	 *
	 * @param string $order_key Order key.
	 * @return array<int, array{key:string, method:string, gateway:string, label:string, paid:float, refunded:float, refundable:float, is_gateway:bool}>
	 */
	public static function summary_by_method( string $order_key ): array {
		$entries = self::get_for_order( $order_key );
		if ( empty( $entries ) ) {
			return array();
		}

		$buckets = array();
		foreach ( $entries as $entry ) {
			$key = self::tender_key( $entry['gateway'], $entry['method'] );
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'key'        => $key,
					'method'     => $entry['method'],
					'gateway'    => $entry['gateway'],
					'label'      => self::tender_label( $entry['gateway'], $entry['method'] ),
					'paid'       => 0.0,
					'refunded'   => 0.0,
					'is_gateway' => self::is_gateway( $entry['gateway'] ),
				);
			}
			if ( self::DIRECTION_REFUND === $entry['direction'] ) {
				$buckets[ $key ]['refunded'] += $entry['amount'];
			} else {
				$buckets[ $key ]['paid'] += $entry['amount'];
			}
		}

		$summary = array();
		foreach ( $buckets as $bucket ) {
			$bucket['paid']       = round( $bucket['paid'], 2 );
			$bucket['refunded']   = round( $bucket['refunded'], 2 );
			$bucket['refundable'] = round( max( 0.0, $bucket['paid'] - $bucket['refunded'] ), 2 );
			$summary[]            = $bucket;
		}

		return $summary;
	}

	/**
	 * Whether a gateway slug represents an online processor (refundable via API) vs a
	 * manual/offline tender (cash, check) recorded by hand.
	 *
	 * @param string $gateway Gateway slug.
	 * @return bool True for stripe / authorize_net.
	 */
	public static function is_gateway( string $gateway ): bool {
		return in_array( $gateway, array( 'stripe', 'authorize_net' ), true );
	}

	/**
	 * Stable grouping key for a tender (gateway preferred, else method).
	 *
	 * @param string $gateway Gateway slug.
	 * @param string $method  Method slug.
	 * @return string Grouping key.
	 */
	private static function tender_key( string $gateway, string $method ): string {
		if ( self::is_gateway( $gateway ) ) {
			return 'gw:' . $gateway;
		}
		$method = '' !== $method ? strtolower( $method ) : 'manual';
		return 'm:' . $method;
	}

	/**
	 * Human-readable label for a tender.
	 *
	 * @param string $gateway Gateway slug.
	 * @param string $method  Method slug/label.
	 * @return string Display label (e.g. "Authorize.net", "Cash").
	 */
	public static function tender_label( string $gateway, string $method ): string {
		switch ( $gateway ) {
			case 'stripe':
				return __( 'Stripe', 'equine-event-manager' );
			case 'authorize_net':
				return __( 'Authorize.net', 'equine-event-manager' );
		}

		$method = trim( $method );
		if ( '' === $method ) {
			return __( 'Manual', 'equine-event-manager' );
		}

		// Method may already be a human label ("Cash", "Check"); normalise common slugs.
		$lower = strtolower( $method );
		if ( 'cash' === $lower ) {
			return __( 'Cash', 'equine-event-manager' );
		}
		if ( 'check' === $lower || 'cheque' === $lower ) {
			return __( 'Check', 'equine-event-manager' );
		}

		return ucwords( $method );
	}

	/**
	 * Delete every ledger entry for an order (used when an order is deleted).
	 *
	 * @param string $order_key Order key.
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_order( string $order_key ): int {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return 0;
		}

		$deleted = $wpdb->delete( self::table(), array( 'order_key' => $order_key ), array( '%s' ) );

		return false === $deleted ? 0 : (int) $deleted;
	}
}
