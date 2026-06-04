<?php
/**
 * Order adjustments data repository (C13.C).
 *
 * Orders in this plugin are stored as 1–2 component rows (en_stall_reservations /
 * en_rv_reservations) grouped by order_key; there is no native line-item or
 * discount storage. This repo owns the {prefix}en_order_adjustments table, which
 * holds the two order-level adjustment kinds introduced by the Create Order page:
 *
 *  - Custom line items — one-off charges not configured on the reservation (late
 *    fee, damage charge, transferred credit). Many per order. Each carries a
 *    description and an amount (negative amounts represent a credit/comp).
 *  - Discount — at most one per order. Dollar or percentage, with a REQUIRED
 *    reason (logged to the Activity Log by the caller). The resolved positive
 *    dollar reduction is snapshotted into `amount` at save time so display does
 *    not have to recompute against a moving subtotal.
 *
 * Math model (per CLAUDE.md "Discount handling schema" + the C13.C decision to
 * recompute order totals at display time): the discount applies to the subtotal;
 * convenience fee + tax recalculation from the post-discount subtotal is composed
 * by the consuming surface, not stored on the component rows in C13.C (the deep
 * per-row tax allocation rewrite stays parked in CLEANUP #9 / C12).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes order-level custom line items and the per-order discount.
 */
class EEM_Order_Adjustments_Repo {

	/**
	 * Adjustment kind: a one-off custom line item.
	 */
	const KIND_CUSTOM_ITEM = 'custom_item';

	/**
	 * Adjustment kind: the per-order discount (at most one row per order).
	 */
	const KIND_DISCOUNT = 'discount';

	/**
	 * Discount type: a flat dollar reduction.
	 */
	const DISCOUNT_DOLLAR = 'dollar';

	/**
	 * Discount type: a percentage of subtotal.
	 */
	const DISCOUNT_PERCENT = 'percent';

	/**
	 * Fully-qualified adjustments table name.
	 *
	 * @return string The prefixed table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'en_order_adjustments';
	}

	/**
	 * Insert a single custom line item for an order.
	 *
	 * @param string $order_key Order key the item belongs to.
	 * @param string $description  Human-readable description (e.g. "Late arrival fee").
	 * @param float  $amount       Charge amount; negative represents a credit/comp.
	 * @return int|false Inserted row id, or false on failure / empty description.
	 */
	public static function insert_custom_item( string $order_key, string $description, float $amount ) {
		global $wpdb;

		$order_key   = trim( $order_key );
		$description = trim( $description );
		if ( '' === $order_key || '' === $description ) {
			return false;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'order_key'   => $order_key,
				'kind'        => self::KIND_CUSTOM_ITEM,
				'description' => $description,
				'amount'      => round( $amount, 2 ),
				'created_by'  => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%f', '%d' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Replace the full set of custom line items for an order.
	 *
	 * Clears any existing custom items for the order, then inserts the supplied
	 * set. Discount rows are untouched. Used by the Create Order save path, which
	 * collects the whole list at once.
	 *
	 * @param string                                   $order_key Order key.
	 * @param array<int, array{description:string, amount:float}> $items   Items to store.
	 * @return int Count of items inserted.
	 */
	public static function replace_custom_items( string $order_key, array $items ): int {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return 0;
		}

		$wpdb->delete(
			self::table(),
			array( 'order_key' => $order_key, 'kind' => self::KIND_CUSTOM_ITEM ),
			array( '%s', '%s' )
		);

		$count = 0;
		foreach ( $items as $item ) {
			$description = isset( $item['description'] ) ? (string) $item['description'] : '';
			$amount      = isset( $item['amount'] ) ? (float) $item['amount'] : 0.0;
			if ( false !== self::insert_custom_item( $order_key, $description, $amount ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Set (or replace) the single discount on an order.
	 *
	 * Enforces the at-most-one-discount-per-order rule by deleting any existing
	 * discount row first. The resolved dollar reduction is snapshotted so display
	 * surfaces don't recompute against a changing subtotal. The caller is
	 * responsible for validating that $reason is non-empty and for logging the
	 * change to the Activity Log.
	 *
	 * @param string $order_key   Order key.
	 * @param string $discount_type  self::DISCOUNT_DOLLAR or self::DISCOUNT_PERCENT.
	 * @param float  $discount_value Raw entered value ($ amount or % rate).
	 * @param string $reason         Required reason for the discount.
	 * @param float  $subtotal       Order subtotal the discount resolves against.
	 * @return int|false Inserted row id, or false on failure / invalid input.
	 */
	public static function set_discount( string $order_key, string $discount_type, float $discount_value, string $reason, float $subtotal ) {
		global $wpdb;

		$order_key  = trim( $order_key );
		$reason        = trim( $reason );
		$discount_type = self::DISCOUNT_PERCENT === $discount_type ? self::DISCOUNT_PERCENT : self::DISCOUNT_DOLLAR;
		if ( '' === $order_key || '' === $reason || $discount_value <= 0 ) {
			return false;
		}

		self::remove_discount( $order_key );

		$resolved = self::resolve_discount_amount( $discount_type, $discount_value, $subtotal );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'order_key'   => $order_key,
				'kind'           => self::KIND_DISCOUNT,
				'amount'         => $resolved,
				'discount_type'  => $discount_type,
				'discount_value' => round( $discount_value, 2 ),
				'discount_reason' => $reason,
				'created_by'     => get_current_user_id(),
			),
			array( '%s', '%s', '%f', '%s', '%f', '%s', '%d' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Resolve a discount definition into a positive dollar reduction.
	 *
	 * Percentage discounts are taken against the subtotal; dollar discounts are
	 * the entered value. Both are clamped to [0, subtotal] so a discount can never
	 * exceed the subtotal or go negative.
	 *
	 * @param string $discount_type  self::DISCOUNT_DOLLAR or self::DISCOUNT_PERCENT.
	 * @param float  $discount_value Raw entered value.
	 * @param float  $subtotal       Subtotal the discount applies to.
	 * @return float Positive dollar reduction, clamped to the subtotal.
	 */
	public static function resolve_discount_amount( string $discount_type, float $discount_value, float $subtotal ): float {
		if ( $discount_value <= 0 || $subtotal <= 0 ) {
			return 0.0;
		}

		$reduction = self::DISCOUNT_PERCENT === $discount_type
			? $subtotal * ( $discount_value / 100 )
			: $discount_value;

		$reduction = max( 0.0, min( $reduction, $subtotal ) );

		return round( $reduction, 2 );
	}

	/**
	 * Remove the discount from an order.
	 *
	 * @param string $order_key Order key.
	 * @return bool True if a discount row was deleted.
	 */
	public static function remove_discount( string $order_key ): bool {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return false;
		}

		$deleted = $wpdb->delete(
			self::table(),
			array( 'order_key' => $order_key, 'kind' => self::KIND_DISCOUNT ),
			array( '%s', '%s' )
		);

		return (bool) $deleted;
	}

	/**
	 * Fetch all custom line items for an order.
	 *
	 * @param string $order_key Order key.
	 * @return array<int, array{id:int, description:string, amount:float}>
	 */
	public static function get_custom_items( string $order_key ): array {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, description, amount FROM ' . self::table() . ' WHERE order_key = %s AND kind = %s ORDER BY id ASC',
				$order_key,
				self::KIND_CUSTOM_ITEM
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'          => (int) $row['id'],
				'description' => (string) $row['description'],
				'amount'      => (float) $row['amount'],
			);
		}

		return $items;
	}

	/**
	 * Fetch the discount on an order, if any.
	 *
	 * @param string $order_key Order key.
	 * @return array{id:int, type:string, value:float, reason:string, amount:float}|null
	 */
	public static function get_discount( string $order_key ): ?array {
		global $wpdb;

		$order_key = trim( $order_key );
		if ( '' === $order_key ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, discount_type, discount_value, discount_reason, amount FROM ' . self::table() . ' WHERE order_key = %s AND kind = %s ORDER BY id DESC LIMIT 1',
				$order_key,
				self::KIND_DISCOUNT
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'     => (int) $row['id'],
			'type'   => (string) $row['discount_type'],
			'value'  => (float) $row['discount_value'],
			'reason' => (string) $row['discount_reason'],
			'amount' => (float) $row['amount'],
		);
	}

	/**
	 * Fetch the full adjustment set for an order in one call.
	 *
	 * @param string $order_key Order key.
	 * @return array{custom_items:array<int, array{id:int, description:string, amount:float}>, discount:?array{id:int, type:string, value:float, reason:string, amount:float}, custom_items_total:float}
	 */
	public static function get_for_order( string $order_key ): array {
		$custom_items = self::get_custom_items( $order_key );

		$custom_total = 0.0;
		foreach ( $custom_items as $item ) {
			$custom_total += $item['amount'];
		}

		return array(
			'custom_items'       => $custom_items,
			'discount'           => self::get_discount( $order_key ),
			'custom_items_total' => round( $custom_total, 2 ),
		);
	}

	/**
	 * Delete every adjustment row for an order (custom items + discount).
	 *
	 * Used when an order is deleted so adjustments don't orphan.
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
