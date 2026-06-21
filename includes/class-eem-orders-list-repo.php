<?php
/**
 * Orders list query helpers.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query + derivation helpers for the C5 Orders list page.
 *
 * Wraps the legacy {@see EEM_Orders_Repository::get_orders()} aggregate
 * (which joins the stall + rv custom tables into a per-customer grouped
 * order array) and adds:
 *
 *   - Billing-status tab counts (All / Paid / Unpaid / Refunded /
 *     Cancelled) so the toolbar can render counts without each render
 *     scanning the full tables.
 *   - Paginated fetch with billing-status + type-chip + event +
 *     search filters and sort.
 *   - Per-row type-chip key derivation from the legacy `type` label
 *     string. Locale-aware (compares against `__()`-translated labels).
 *
 * Filter / sort happens in PHP over the grouped-orders array because
 * the underlying tables aren't joinable cleanly enough for a single
 * SQL pass — same compromise the legacy repo already makes. Capped
 * at the legacy repo's natural result set; C11 revisits if perf
 * demands.
 *
 * All methods are static — stateless query helper, not a lifecycle
 * repository (mutations stay on EEM_Orders_Repository).
 */
class EEM_Orders_List_Repo {

	/**
	 * Billing-status tab keys → label. Drives the .eem-orders-billing-tabs
	 * segmented control. Maps onto the legacy `status_slug` produced by
	 * EEM_Orders_Repository::get_order_status_display(): paid → paid,
	 * pending|invoice_sent → unpaid, partially_refunded → refunded (was
	 * paid then partially refunded — doesn't owe; Whitney 2026-06-21),
	 * refunded → refunded, cancelled → cancelled.
	 *
	 * @return array<string, string>
	 */
	public static function billing_tabs() {
		return array(
			'all'       => __( 'All',       'equine-event-manager' ),
			'unpaid'    => __( 'Unpaid',    'equine-event-manager' ),
			'paid'      => __( 'Paid',      'equine-event-manager' ),
			'refunded'  => __( 'Refunded',  'equine-event-manager' ),
			'cancelled' => __( 'Cancelled', 'equine-event-manager' ),
			// v1 #9 — soft-deleted orders. Special tab: not a payment status, it
			// fetches only trashed orders (the others exclude them).
			'trash'     => __( 'Trash',     'equine-event-manager' ),
		);
	}

	/**
	 * Friendly label for a billing tab id.
	 *
	 * @param string $tab_id
	 * @return string
	 */
	public static function tab_label( $tab_id ) {
		$tabs = self::billing_tabs();
		return isset( $tabs[ $tab_id ] ) ? $tabs[ $tab_id ] : $tabs['all'];
	}

	/**
	 * Type-chip filter keys (canonical order: stall, rv, addon, group).
	 * Drives the .eem-orders-type-chips multi-select. Default state =
	 * all four selected (per ORD-1).
	 *
	 * @return array<int, string>
	 */
	public static function type_filter_keys() {
		return array( 'stall', 'rv', 'addon', 'group' );
	}

	/**
	 * Map a legacy `status_slug` → a C5 billing-tab id.
	 *
	 * @param string $status_slug
	 * @return string  One of: paid, unpaid, refunded, cancelled.
	 */
	public static function map_status_slug_to_tab( $status_slug ) {
		// C5.G.6: legacy emits HYPHENATED slugs (invoice-sent,
		// partially-refunded), not underscored. The underscore arms
		// here were dead — they happened to produce the right answer
		// ('unpaid' via default fallback) but only by coincidence.
		// Hyphen arms added for correctness + so the mapping doesn't
		// silently drift if the default branch ever changes.
		switch ( (string) $status_slug ) {
			case 'paid':
				return 'paid';
			case 'refunded':
			// Partially-refunded orders were PAID then partially refunded — they
			// don't owe money, so they belong in Refunded, not Unpaid. (Was mapped
			// to 'unpaid' under ORD-3 "still has refundable balance", but that
			// conflated refundable balance with owed balance — Whitney 2026-06-21,
			// so Collect Payment / Unpaid only shows orders that actually owe.)
			case 'partially-refunded':
				return 'refunded';
			case 'cancelled':
				return 'cancelled';
			case 'unpaid':
			case 'invoice-sent':
			default:
				return 'unpaid';
		}
	}

	/**
	 * Counts per billing tab. Single pass over the grouped-orders array.
	 *
	 * @return array<string, int>  Keys: all, paid, unpaid, refunded, cancelled.
	 */
	public static function counts_by_billing_status() {
		$counts = array(
			'all'       => 0,
			'paid'      => 0,
			'unpaid'    => 0,
			'refunded'  => 0,
			'cancelled' => 0,
			'trash'     => 0,
		);
		$repo   = new EEM_Orders_Repository();
		// Live tabs count non-trashed orders only (the default).
		foreach ( $repo->get_orders() as $order ) {
			$tab = self::map_status_slug_to_tab( isset( $order['status_slug'] ) ? $order['status_slug'] : '' );
			$counts['all']++;
			if ( isset( $counts[ $tab ] ) ) {
				$counts[ $tab ]++;
			}
		}
		// Trash tab counts only trashed orders.
		$counts['trash'] = count( $repo->get_orders( '', 'date', 'desc', '', 'only' ) );
		return $counts;
	}

	/**
	 * Derive the type-chip key set from a legacy order's joined `type`
	 * label string. Locale-aware — matches against the same `__()`
	 * outputs that the legacy repo uses when assembling type_labels.
	 *
	 * @param array<string, mixed> $order  Legacy order row from
	 *                                     EEM_Orders_Repository::get_orders().
	 * @return array<int, string>  Subset of [stall, rv, addon, group].
	 */
	public static function derive_type_keys( array $order ) {
		$label_to_key = array(
			(string) __( 'Stall',  'equine-event-manager' ) => 'stall',
			(string) __( 'RV',     'equine-event-manager' ) => 'rv',
			(string) __( 'Add-On', 'equine-event-manager' ) => 'addon',
			(string) __( 'Group',  'equine-event-manager' ) => 'group',
		);
		$type_str = isset( $order['type'] ) ? (string) $order['type'] : '';
		if ( '' === $type_str ) {
			return array();
		}
		$keys  = array();
		$parts = array_map( 'trim', explode( ',', $type_str ) );
		foreach ( $parts as $part ) {
			if ( isset( $label_to_key[ $part ] ) ) {
				$keys[] = $label_to_key[ $part ];
			}
		}
		// Preserve canonical order regardless of label string ordering.
		$ordered = array();
		foreach ( self::type_filter_keys() as $canonical ) {
			if ( in_array( $canonical, $keys, true ) ) {
				$ordered[] = $canonical;
			}
		}
		return $ordered;
	}

	/**
	 * Event-filter dropdown options (label => label, used for both
	 * select value and display text since the legacy repo keys options
	 * by label, not by event id). Wraps the legacy recent-event filter.
	 *
	 * @return array<string, string>
	 */
	public static function get_event_filter_options() {
		$repo = new EEM_Orders_Repository();
		return $repo->get_recent_event_filter_options();
	}

	/**
	 * Paginated fetch with billing + type + event + search filters + sort.
	 *
	 * Sort modes (per ORD-1 + mockup thead cursor:pointer columns):
	 *   - 'order_number' → numeric ascending of the ORD-4 #NNNN number
	 *   - 'status'       → alphabetic by status_label
	 *   - 'date'         → strtotime(created_at)
	 *
	 * @param array $args {
	 *     @type string $billing_status  Tab id. Default 'all'.
	 *     @type string $type            Single type slug (stall/rv/addon/group)
	 *                                   or '' for All Types. Default ''.
	 *                                   (C5.F-toolbar: replaced the prior
	 *                                   `types` array signature from C5.B-D
	 *                                   when the toolbar restructure swapped
	 *                                   the multi-select chip array for a
	 *                                   single-select dropdown.)
	 *     @type string $event           Event filter label ('' = all).
	 *     @type string $search          Customer / order # / event LIKE.
	 *     @type string $orderby         'order_number' | 'status' | 'date'.
	 *                                   Default 'date'.
	 *     @type string $order           'asc' | 'desc'. Default 'desc'.
	 *     @type int    $paged           1-based page number. Default 1.
	 *     @type int    $per_page        Default 25.
	 * }
	 * @return array{ items: array<int, array<string, mixed>>, total: int, total_pages: int, page: int, per_page: int }
	 */
	public static function get_paginated( array $args = array() ) {
		$defaults = array(
			'billing_status' => 'all',
			'type'           => '',
			'event'          => '',
			'search'         => '',
			'orderby'        => 'date',
			'order'          => 'desc',
			'paged'          => 1,
			'per_page'       => 25,
		);
		$args = wp_parse_args( $args, $defaults );

		$billing_pre = sanitize_key( (string) $args['billing_status'] );
		$is_trash    = ( 'trash' === $billing_pre );
		$repo        = new EEM_Orders_Repository();
		// Trash tab fetches ONLY trashed orders; every other tab excludes them.
		$orders = $repo->get_orders( (string) $args['event'], 'date', 'desc', (string) $args['search'], $is_trash ? 'only' : 'exclude' );

		// C5.F-toolbar: type filter is now a SINGLE-select dropdown.
		// '' = "All Types" (no type filter). A non-empty type that
		// isn't in type_filter_keys() falls through to "no filter" so
		// stale URL params don't blank the table.
		$type = sanitize_key( (string) $args['type'] );
		if ( '' !== $type && ! in_array( $type, self::type_filter_keys(), true ) ) {
			$type = '';
		}

		$billing = sanitize_key( (string) $args['billing_status'] );
		$tabs    = self::billing_tabs();
		if ( ! isset( $tabs[ $billing ] ) ) {
			$billing = 'all';
		}

		$filtered = array();
		foreach ( $orders as $order ) {
			// The Trash tab shows every trashed order regardless of payment status;
			// the payment-status tabs only apply to the (non-trashed) live list.
			if ( ! $is_trash && 'all' !== $billing ) {
				$tab = self::map_status_slug_to_tab( isset( $order['status_slug'] ) ? $order['status_slug'] : '' );
				if ( $tab !== $billing ) {
					continue;
				}
			}
			if ( '' !== $type ) {
				$row_types = self::derive_type_keys( $order );
				if ( ! in_array( $type, $row_types, true ) ) {
					continue;
				}
			}
			$filtered[] = $order;
		}

		// Sort.
		$orderby = sanitize_key( (string) $args['orderby'] );
		$dir     = 'asc' === strtolower( (string) $args['order'] ) ? 1 : -1;
		usort( $filtered, function( $a, $b ) use ( $orderby, $dir ) {
			switch ( $orderby ) {
				case 'order_number':
					$av = (int) preg_replace( '/\D/', '', isset( $a['order_number'] ) ? (string) $a['order_number'] : '' );
					$bv = (int) preg_replace( '/\D/', '', isset( $b['order_number'] ) ? (string) $b['order_number'] : '' );
					return $dir * ( $av <=> $bv );
				case 'status':
					$av = isset( $a['status_label'] ) ? (string) $a['status_label'] : '';
					$bv = isset( $b['status_label'] ) ? (string) $b['status_label'] : '';
					return $dir * strcmp( $av, $bv );
				case 'date':
				default:
					$av = isset( $a['created_at'] ) ? strtotime( (string) $a['created_at'] ) : 0;
					$bv = isset( $b['created_at'] ) ? strtotime( (string) $b['created_at'] ) : 0;
					return $dir * ( $av <=> $bv );
			}
		} );

		$total    = count( $filtered );
		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$page_items = array_slice( $filtered, ( $paged - 1 ) * $per_page, $per_page );

		return array(
			'items'       => $page_items,
			'total'       => $total,
			'total_pages' => (int) ceil( max( 1, $total ) / $per_page ),
			'page'        => $paged,
			'per_page'    => $per_page,
		);
	}
}
