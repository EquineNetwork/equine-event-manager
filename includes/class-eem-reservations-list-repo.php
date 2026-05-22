<?php
/**
 * Reservations list query helpers.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query + derivation helpers for the C4 Reservations list page.
 *
 * Owns:
 *   - Status-tab counts (All / Published / Draft / Trash) so the header
 *     can render the counts without each page-render reading the whole
 *     post table.
 *   - Paginated post-fetch with sort + search + status filter merged
 *     into a single WP_Query call.
 *   - Per-row derived data: orders count, type-badge set
 *     (Stall / RV / Add-On / Group), event-date range label.
 *
 * Status mapping for the UI:
 *   - Active   → WP post_status `publish`
 *   - Draft    → WP post_status `draft`
 *   - Archived → WP post_status `private` (Phase 3 reuses `private` as
 *               the "archived" tag so we don't introduce a custom
 *               post_status that needs cap-mapping)
 *   - Trashed  → WP post_status `trash`
 *
 * All methods are static — this is a stateless query helper, not a
 * lifecycle-bearing repository.
 */
class EEM_Reservations_List_Repo {

	const POST_TYPE = 'en_reservation';

	/**
	 * Map UI tab id → WP post_status arg (or array). The All tab passes
	 * an array of every status we want listed by default (excludes Trash;
	 * Trash is its own tab).
	 *
	 * @return array<string, string|string[]>
	 */
	public static function status_tabs() {
		return array(
			'all'       => array( 'publish', 'draft', 'private' ),
			'publish'   => 'publish',
			'draft'     => 'draft',
			'trash'     => 'trash',
		);
	}

	/**
	 * Friendly label for each tab — separated so the page renderer can
	 * loop over status_tabs() and call this for the UI label without
	 * hard-coding the order.
	 *
	 * @param string $tab_id
	 * @return string
	 */
	public static function tab_label( $tab_id ) {
		switch ( $tab_id ) {
			case 'publish': return __( 'Published', 'equine-event-manager' );
			case 'draft':   return __( 'Draft',     'equine-event-manager' );
			case 'trash':   return __( 'Trash',     'equine-event-manager' );
			case 'all':
			default:        return __( 'All',       'equine-event-manager' );
		}
	}

	/**
	 * Counts for each status tab. Single wp_count_posts call + one extra
	 * for the All total so we don't hit the DB once per tab.
	 *
	 * @return array<string, int>
	 */
	public static function counts_by_tab() {
		$counts = (array) wp_count_posts( self::POST_TYPE );

		$publish = isset( $counts['publish'] ) ? (int) $counts['publish'] : 0;
		$draft   = isset( $counts['draft'] )   ? (int) $counts['draft']   : 0;
		$private = isset( $counts['private'] ) ? (int) $counts['private'] : 0;
		$trash   = isset( $counts['trash'] )   ? (int) $counts['trash']   : 0;

		return array(
			'all'     => $publish + $draft + $private,
			'publish' => $publish,
			'draft'   => $draft,
			'trash'   => $trash,
		);
	}

	/**
	 * Paginated fetch with sort + search + status filter.
	 *
	 * @param array $args {
	 *     @type string $status   Tab id (all/publish/draft/trash). Default 'all'.
	 *     @type string $search   Search by reservation post_title (LIKE). Default ''.
	 *     @type string $orderby  'title' | 'event_dates' | 'orders'. Default 'event_dates'.
	 *     @type string $order    'asc' | 'desc'. Default 'asc'.
	 *     @type int    $paged    Page number (1-based). Default 1.
	 *     @type int    $per_page Default 25.
	 * }
	 * @return array{ items: WP_Post[], total: int, total_pages: int, page: int, per_page: int }
	 */
	public static function get_paginated( array $args = array() ) {
		$defaults = array(
			'status'   => 'all',
			'search'   => '',
			'orderby'  => 'event_dates',
			'order'    => 'asc',
			'paged'    => 1,
			'per_page' => 25,
		);
		$args = wp_parse_args( $args, $defaults );

		$tabs   = self::status_tabs();
		$status = isset( $tabs[ $args['status'] ] ) ? $tabs[ $args['status'] ] : $tabs['all'];

		// orderby translation. C4.A scaffold only honours the WP-native
		// orderby keys; the 'event_dates' and 'orders' custom sorts get
		// wired in C4.D once the meta-query joins are designed.
		$wp_orderby = 'title';
		if ( 'title' === $args['orderby'] ) {
			$wp_orderby = 'title';
		}

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => max( 1, (int) $args['per_page'] ),
			'paged'          => max( 1, (int) $args['paged'] ),
			'orderby'        => $wp_orderby,
			'order'          => 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC',
			's'              => (string) $args['search'],
		);

		$query = new WP_Query( $query_args );

		return array(
			'items'       => $query->posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) $args['paged'],
			'per_page'    => (int) $args['per_page'],
		);
	}

	/**
	 * Order count for a single reservation. Sums the stall + RV table
	 * row counts since one reservation can have parallel orders in both.
	 *
	 * @param int $reservation_id
	 * @return int
	 */
	public static function get_orders_count_for_reservation( $reservation_id ) {
		global $wpdb;

		$id = absint( $reservation_id );
		if ( $id <= 0 ) {
			return 0;
		}

		$stall_table = $wpdb->prefix . 'en_stall_reservations';
		$rv_table    = $wpdb->prefix . 'en_rv_reservations';

		// `notes` column carries `Reservation setup ID: N` per
		// insert_reservation_orders. C4.D revisits this if perf is a
		// concern (consider a denormalized reservation_id column).
		$needle = '%Reservation setup ID: ' . $id . '%';

		$stall_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$stall_table}` WHERE notes LIKE %s", $needle )
		);
		$rv_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$rv_table}` WHERE notes LIKE %s", $needle )
		);

		return $stall_count + $rv_count;
	}

	/**
	 * Type-badge set for a reservation — which sections are enabled.
	 * Drives the Type column's Stall / RV / Add-On / Group badges.
	 *
	 * @param int $reservation_id
	 * @return array<int, string>  Subset of ['stall','rv','addon','group']
	 *                             in canonical display order.
	 */
	public static function get_type_badges( $reservation_id ) {
		$id = absint( $reservation_id );
		if ( $id <= 0 ) {
			return array();
		}

		$badges = array();

		// Stall section enabled when stall capacity > 0 or stall stay types are configured.
		if ( (int) get_post_meta( $id, '_en_stall_quantity_available', true ) > 0 ) {
			$badges[] = 'stall';
		}

		// RV section enabled when RV capacity > 0.
		if ( (int) get_post_meta( $id, '_en_rv_quantity_available', true ) > 0 ) {
			$badges[] = 'rv';
		}

		// Add-On section enabled when any general add-on is configured.
		$addons = get_post_meta( $id, '_en_general_addons', true );
		if ( is_array( $addons ) && ! empty( $addons ) ) {
			$badges[] = 'addon';
		}

		// Group section enabled when group reservations toggle is on.
		if ( ! empty( get_post_meta( $id, '_en_group_reservations_enabled', true ) ) ) {
			$badges[] = 'group';
		}

		return $badges;
	}

	/**
	 * Display label for a reservation's event date range. Pulls the
	 * earliest start + latest end across whichever stay-type fields
	 * are populated (stall + rv ranges sit in separate meta keys).
	 *
	 * @param int $reservation_id
	 * @return string  Formatted as "May 8, 2026 – May 10, 2026", or '' when no dates.
	 */
	public static function get_event_date_range_label( $reservation_id ) {
		$id = absint( $reservation_id );
		if ( $id <= 0 ) {
			return '';
		}

		$candidate_starts = array(
			get_post_meta( $id, '_en_nightly_start_date', true ),
			get_post_meta( $id, '_en_weekend_start_date', true ),
		);
		$candidate_ends = array(
			get_post_meta( $id, '_en_nightly_end_date', true ),
			get_post_meta( $id, '_en_weekend_end_date', true ),
		);

		$starts = array_filter( $candidate_starts, 'strlen' );
		$ends   = array_filter( $candidate_ends,   'strlen' );

		if ( empty( $starts ) || empty( $ends ) ) {
			return '';
		}

		sort( $starts );
		rsort( $ends );

		$start_ts = strtotime( $starts[0] );
		$end_ts   = strtotime( $ends[0] );

		if ( ! $start_ts || ! $end_ts ) {
			return '';
		}

		$fmt = 'M j, Y';
		return sprintf( '%s – %s', date_i18n( $fmt, $start_ts ), date_i18n( $fmt, $end_ts ) );
	}
}
