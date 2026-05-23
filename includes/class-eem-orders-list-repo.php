<?php
/**
 * Orders list query helpers.
 *
 * @package EEM_Plugin
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
	 * pending|invoice_sent → unpaid, partially_refunded → unpaid (it
	 * still has refundable balance per ORD-3), refunded → refunded,
	 * cancelled → cancelled.
	 *
	 * @return array<string, string>
	 */
	public static function billing_tabs() {
		return array(
			'all'       => __( 'All',       'equine-event-manager' ),
			'paid'      => __( 'Paid',      'equine-event-manager' ),
			'unpaid'    => __( 'Unpaid',    'equine-event-manager' ),
			'refunded'  => __( 'Refunded',  'equine-event-manager' ),
			'cancelled' => __( 'Cancelled', 'equine-event-manager' ),
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
		switch ( (string) $status_slug ) {
			case 'paid':
				return 'paid';
			case 'refunded':
				return 'refunded';
			case 'cancelled':
				return 'cancelled';
			case 'pending':
			case 'invoice_sent':
			case 'partially_refunded':
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
		);
		$repo   = new EEM_Orders_Repository();
		foreach ( $repo->get_orders() as $order ) {
			$tab = self::map_status_slug_to_tab( isset( $order['status_slug'] ) ? $order['status_slug'] : '' );
			$counts['all']++;
			if ( isset( $counts[ $tab ] ) ) {
				$counts[ $tab ]++;
			}
		}
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
	 *     @type string   $billing_status  Tab id. Default 'all'.
	 *     @type string[] $types           Type-chip keys to keep (OR
	 *                                     match). Default all four.
	 *     @type string   $event           Event filter label ('' = all).
	 *     @type string   $search          Customer / order # / event LIKE.
	 *     @type string   $orderby         'order_number' | 'status' | 'date'.
	 *                                     Default 'date'.
	 *     @type string   $order           'asc' | 'desc'. Default 'desc'.
	 *     @type int      $paged           1-based page number. Default 1.
	 *     @type int      $per_page        Default 25.
	 * }
	 * @return array{ items: array<int, array<string, mixed>>, total: int, total_pages: int, page: int, per_page: int }
	 */
	public static function get_paginated( array $args = array() ) {
		$defaults = array(
			'billing_status' => 'all',
			'types'          => self::type_filter_keys(),
			'event'          => '',
			'search'         => '',
			'orderby'        => 'date',
			'order'          => 'desc',
			'paged'          => 1,
			'per_page'       => 25,
		);
		$args = wp_parse_args( $args, $defaults );

		$repo   = new EEM_Orders_Repository();
		$orders = $repo->get_orders( (string) $args['event'], 'date', 'desc', (string) $args['search'] );

		$wanted_types = array_values( array_intersect(
			self::type_filter_keys(),
			array_map( 'sanitize_key', (array) $args['types'] )
		) );
		// Empty types[] = show none (matches the mockup's chip-toggle
		// semantics: deselect all four chips and the table empties).
		$skip_type_filter = count( $wanted_types ) === count( self::type_filter_keys() );

		$billing = sanitize_key( (string) $args['billing_status'] );
		$tabs    = self::billing_tabs();
		if ( ! isset( $tabs[ $billing ] ) ) {
			$billing = 'all';
		}

		$filtered = array();
		foreach ( $orders as $order ) {
			if ( 'all' !== $billing ) {
				$tab = self::map_status_slug_to_tab( isset( $order['status_slug'] ) ? $order['status_slug'] : '' );
				if ( $tab !== $billing ) {
					continue;
				}
			}
			if ( ! $skip_type_filter ) {
				$row_types = self::derive_type_keys( $order );
				if ( empty( array_intersect( $row_types, $wanted_types ) ) ) {
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
