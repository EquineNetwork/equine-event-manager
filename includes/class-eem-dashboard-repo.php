<?php
/**
 * Equine Event Manager — Dashboard data repository (DS-1.B).
 *
 * Builds the data payload consumed by `EEM_Dashboard_Page::render()` against
 * `.mockups/dashboard_page.html`. All KPI / Recent Orders / This-Week / Revenue
 * Chart numbers derive from canonical sources (`EEM_Orders_Repository::get_grouped_orders`
 * for orders, `en_reservation` CPT posts for Upcoming Reservations). Stall/RV
 * assignment numbers (Unassigned Stalls KPI, Upcoming Reservations progress bars,
 * Needs Attention rows, This Week "Stalls assigned") are wired live via
 * `EEM_Admin::get_dashboard_stall_metrics()` (DS-1.B live-data pass, 2.4.0).
 * The only intentionally-unwired item is the "customers haven't signed the
 * agreement" Needs Attention row — V1 has no per-order signature data source, so
 * that row is omitted rather than faked.
 *
 * Range filter semantics: `last-7` / `last-30` / `last-90` / `this-year` /
 * `all-time` collapse to a `[from, to]` MySQL-datetime window applied to
 * `created_at` on grouped orders. Default `last-30`.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 2.2.0
 */
class EEM_Dashboard_Repo {

	const RANGE_DEFAULT = 'last-30';

	/**
	 * Canonical range option list — keys match the `?range=` query param and
	 * the option `value` strings rendered into the range-filter <select>.
	 *
	 * @return array<string, string>
	 */
	public static function range_options() {
		return array(
			'last-7'    => __( 'Last 7 days', 'equine-event-manager' ),
			'last-30'   => __( 'Last 30 days', 'equine-event-manager' ),
			'last-90'   => __( 'Last 90 days', 'equine-event-manager' ),
			'this-year' => __( 'This year', 'equine-event-manager' ),
			'all-time'  => __( 'All time', 'equine-event-manager' ),
		);
	}

	/**
	 * Sanitise an incoming `?range=` value, defaulting to RANGE_DEFAULT on
	 * unknown input.
	 *
	 * @param string $raw
	 * @return string
	 */
	public static function sanitize_range( $raw ) {
		$raw = (string) $raw;
		return array_key_exists( $raw, self::range_options() ) ? $raw : self::RANGE_DEFAULT;
	}

	/**
	 * Resolve a range key to a [from_ts, to_ts] window. `all-time` returns
	 * `[0, time()]`; `this-year` starts at Jan 1 of the current year; the
	 * relative ranges start at `today - N days`.
	 *
	 * @param string $range
	 * @return array{0:int,1:int}
	 */
	public static function range_window( $range ) {
		$to    = time();
		$today = strtotime( 'today', $to );
		switch ( $range ) {
			case 'last-7':
				return array( $today - 7 * DAY_IN_SECONDS, $to );
			case 'last-90':
				return array( $today - 90 * DAY_IN_SECONDS, $to );
			case 'this-year':
				return array( strtotime( date( 'Y-01-01 00:00:00', $to ) ), $to );
			case 'all-time':
				return array( 0, $to );
			case 'last-30':
			default:
				return array( $today - 30 * DAY_IN_SECONDS, $to );
		}
	}

	/**
	 * Pull all grouped orders via the canonical
	 * `EEM_Orders_Repository::get_grouped_orders()` consumer method.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function all_orders() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$repo = new EEM_Orders_Repository();
		$ref  = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
		$ref->setAccessible( true );
		$cache = (array) $ref->invoke( $repo );
		return $cache;
	}

	/**
	 * Stall / RV assignment metrics from the canonical stall-chart computation
	 * (EEM_Admin::get_dashboard_stall_metrics), memoised per request. Returns an
	 * empty-shaped array if the admin class is unavailable (front-end context).
	 *
	 * @return array<string, mixed>
	 */
	private function stall_metrics() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		if ( class_exists( 'EEM_Admin' ) && method_exists( 'EEM_Admin', 'for_compute' ) ) {
			$cache = EEM_Admin::for_compute()->get_dashboard_stall_metrics();
		} else {
			$cache = array(
				'stalls_unassigned_total'  => 0,
				'stalls_assigned_total'    => 0,
				'rv_unassigned_total'      => 0,
				'per_reservation'          => array(),
				'unconfigured'             => array(),
				'assigned_by_order_key'    => array(),
				'rv_assigned_by_order_key' => array(),
			);
		}
		return $cache;
	}

	/**
	 * Filter orders by created_at window.
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @param int                              $from_ts
	 * @param int                              $to_ts
	 * @return array<int, array<string, mixed>>
	 */
	private function in_window( array $orders, $from_ts, $to_ts ) {
		$out = array();
		foreach ( $orders as $o ) {
			$ts = isset( $o['created_at'] ) ? strtotime( (string) $o['created_at'] ) : 0;
			if ( $ts >= $from_ts && $ts <= $to_ts ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/**
	 * Build the 4-card KPI metrics grid payload.
	 *
	 * Card 4 (Unassigned Stalls) is an em-dash placeholder pending C8 stall-
	 * chart data wiring. See CLEANUP #37.
	 *
	 * @param string $range
	 * @return array<int, array<string, mixed>>
	 */
	public function kpi_cards( $range ) {
		list( $from, $to )          = self::range_window( $range );
		list( $prior_from, $prior_to ) = self::prior_window( $range );
		$all                        = $this->all_orders();
		$orders                     = $this->in_window( $all, $from, $to );
		$prior_orders               = $this->in_window( $all, $prior_from, $prior_to );

		$totals = $this->compute_revenue_outstanding_totals( $orders );
		$prior  = $this->compute_revenue_outstanding_totals( $prior_orders );

		$revenue_trend = self::format_trend_percent( $totals['revenue'], $prior['revenue'] );
		$orders_trend  = self::format_trend_absolute( count( $orders ), count( $prior_orders ) );

		$cards = array(
			array(
				'border'   => 'blue',
				'icon'     => 'dollar',
				'label'    => __( 'Total Revenue', 'equine-event-manager' ),
				'value'    => self::format_currency( $totals['revenue'] ),
				'sub_pre'  => $revenue_trend['label'],
				'sub_post' => $revenue_trend['suffix'],
				'sub_tone' => $revenue_trend['tone'],
			),
			array(
				'border'   => 'orange',
				'icon'     => 'alert-circle',
				'label'    => __( 'Outstanding Payments', 'equine-event-manager' ),
				'value'    => self::format_currency( $totals['outstanding_amount'] ),
				'sub_pre'  => sprintf(
					/* translators: %d: count of outstanding orders */
					_n( '%d order', '%d orders', $totals['outstanding_count'], 'equine-event-manager' ),
					$totals['outstanding_count']
				),
				'sub_post' => __( ' awaiting payment', 'equine-event-manager' ),
				'sub_tone' => 'warn',
			),
			array(
				'border'   => 'green',
				'icon'     => 'package',
				'label'    => __( 'Total Orders', 'equine-event-manager' ),
				'value'    => number_format_i18n( count( $orders ) ),
				'sub_pre'  => $orders_trend['label'],
				'sub_post' => $orders_trend['suffix'],
				'sub_tone' => $orders_trend['tone'],
			),
			$this->unassigned_stalls_kpi(),
		);

		if ( class_exists( 'EEM_Events' ) && EEM_Events::is_entries_enabled() ) {
			$cards[] = $this->entries_kpi( $from, $to );
		}

		return $cards;
	}

	/**
	 * KPI card 4 — Unassigned Stalls. Total stalls purchased-but-not-yet-assigned
	 * across all stall-selling reservations (live via EEM_Admin metrics).
	 *
	 * @return array<string, mixed>
	 */
	private function unassigned_stalls_kpi() {
		$m          = $this->stall_metrics();
		$unassigned = (int) ( $m['stalls_unassigned_total'] ?? 0 );
		$assigned   = (int) ( $m['stalls_assigned_total'] ?? 0 );
		$total      = $assigned + $unassigned;

		if ( $unassigned > 0 ) {
			$sub  = sprintf(
				/* translators: %1$d: assigned stalls, %2$d: total purchased stalls */
				__( '%1$d of %2$d stalls assigned', 'equine-event-manager' ),
				$assigned,
				$total
			);
			$tone = 'down';
		} else {
			$sub  = $total > 0
				? __( 'all stalls assigned', 'equine-event-manager' )
				: __( 'no stalls to assign yet', 'equine-event-manager' );
			$tone = $total > 0 ? 'up' : 'flat';
		}

		return array(
			'border'   => 'red',
			'icon'     => 'grid',
			'label'    => __( 'Unassigned Stalls', 'equine-event-manager' ),
			'value'    => number_format_i18n( $unassigned ),
			'sub'      => $sub,
			'sub_tone' => $tone,
		);
	}

	/**
	 * KPI card 5 (conditional) — Entries Sold. Shows total entrants purchased
	 * within the date window and their combined revenue (qty × division price).
	 *
	 * @param int $from Unix timestamp window start.
	 * @param int $to   Unix timestamp window end.
	 * @return array<string, mixed>
	 */
	private function entries_kpi( int $from, int $to ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'eem_division_entries';
		$from_sql = gmdate( 'Y-m-d H:i:s', $from );
		$to_sql   = gmdate( 'Y-m-d H:i:s', $to );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(e.qty), 0) AS sold,
			        COALESCE(SUM(e.qty * dc.price), 0) AS revenue
			 FROM {$table} e
			 INNER JOIN {$wpdb->prefix}eem_division_config dc ON dc.division_id = e.division_id
			 WHERE e.status IN ('paid','unpaid')
			   AND e.created_at >= %s
			   AND e.created_at <= %s",
			$from_sql,
			$to_sql
		) );

		$sold    = $row ? (int) $row->sold : 0;
		$revenue = $row ? (float) $row->revenue : 0.0;

		return array(
			'border'   => 'purple',
			'icon'     => 'users',
			'label'    => __( 'Entries Sold', 'equine-event-manager' ),
			'value'    => number_format_i18n( $sold ),
			'sub'      => self::format_currency( $revenue ) . ' ' . __( 'revenue', 'equine-event-manager' ),
			'sub_tone' => 'flat',
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $orders
	 * @return array{revenue:float, outstanding_amount:float, outstanding_count:int}
	 */
	private function compute_revenue_outstanding_totals( array $orders ) {
		$rev = 0.0; $out_amt = 0.0; $out_count = 0;
		$outstanding_statuses = array( 'unpaid', 'invoice-sent', 'partially-paid' );
		foreach ( $orders as $o ) {
			$slug = (string) ( $o['status_slug'] ?? '' );
			$amt  = (float) ( $o['total'] ?? 0 );
			if ( 'paid' === $slug || 'partially-paid' === $slug ) {
				$rev += $amt;
			}
			if ( in_array( $slug, $outstanding_statuses, true ) ) {
				$out_amt += $amt;
				$out_count++;
			}
		}
		return array( 'revenue' => $rev, 'outstanding_amount' => $out_amt, 'outstanding_count' => $out_count );
	}

	/**
	 * Compute the prior window for trend comparisons. For relative ranges
	 * (last-7/30/90): the same length immediately preceding. For this-year:
	 * the Jan-1-to-(today-anniversary) window of the prior year. For
	 * all-time: returns `[0, 0]` (no comparable prior — trend hidden by
	 * `format_trend_*`).
	 *
	 * @param string $range
	 * @return array{0:int,1:int}
	 */
	public static function prior_window( $range ) {
		list( $from, $to ) = self::range_window( $range );
		switch ( $range ) {
			case 'all-time':
				return array( 0, 0 );
			case 'this-year':
				$prior_year = (int) date( 'Y', $to ) - 1;
				$prior_from = strtotime( sprintf( '%d-01-01 00:00:00', $prior_year ) );
				$prior_to   = strtotime( '+' . ( $to - $from ) . ' seconds', $prior_from );
				return array( $prior_from, $prior_to );
			default:
				$length = $to - $from;
				return array( $from - $length, $from );
		}
	}

	/**
	 * "↑ 12%" / "↓ 5%" trend label + suffix + tone. Returns neutral
	 * label "—" when the prior window has no data, signalling no
	 * comparable trend.
	 *
	 * @param float $current
	 * @param float $prior
	 * @return array{label:string, suffix:string, tone:string}
	 */
	public static function format_trend_percent( $current, $prior ) {
		if ( $prior <= 0 ) {
			return array( 'label' => '—', 'suffix' => ' ' . __( 'no prior data', 'equine-event-manager' ), 'tone' => 'flat' );
		}
		$delta = ( ( $current - $prior ) / $prior ) * 100;
		$arrow = $delta > 0 ? '↑' : ( $delta < 0 ? '↓' : '→' );
		$tone  = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
		return array(
			'label'  => sprintf( '%s %s%%', $arrow, number_format_i18n( abs( $delta ), 0 ) ),
			'suffix' => ' ' . __( 'vs prior period', 'equine-event-manager' ),
			'tone'   => $tone,
		);
	}

	/**
	 * "↑ 23" / "↓ 4" absolute-count trend label.
	 *
	 * @param int $current
	 * @param int $prior
	 * @return array{label:string, suffix:string, tone:string}
	 */
	public static function format_trend_absolute( $current, $prior ) {
		$delta = (int) $current - (int) $prior;
		if ( 0 === $prior && 0 === $current ) {
			return array( 'label' => '—', 'suffix' => ' ' . __( 'no prior data', 'equine-event-manager' ), 'tone' => 'flat' );
		}
		$arrow = $delta > 0 ? '↑' : ( $delta < 0 ? '↓' : '→' );
		$tone  = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
		return array(
			'label'  => sprintf( '%s %s', $arrow, number_format_i18n( abs( $delta ) ) ),
			'suffix' => ' ' . __( 'vs prior period', 'equine-event-manager' ),
			'tone'   => $tone,
		);
	}

	/**
	 * Build the Upcoming Reservations rows (top 5 by arrival date, future-
	 * only). Returns per-row: name, date label, opens-in label/tone, type
	 * tags, order count, revenue, and an em-dash stall-progress payload
	 * (CLEANUP #38).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function upcoming_reservations() {
		$today_ts = strtotime( 'today' );
		$args     = array(
			'post_type'      => 'en_reservation',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 50,
			'meta_query'     => array(
				array(
					'key'     => '_en_source_event_start_date',
					'value'   => date( 'Y-m-d', $today_ts ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
			'orderby'        => 'meta_value',
			'meta_key'       => '_en_source_event_start_date',
			'order'          => 'ASC',
		);
		$query = new WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return array();
		}

		$orders = $this->all_orders();

		$rows = array();
		foreach ( array_slice( $query->posts, 0, 5 ) as $post ) {
			$res_id     = (int) $post->ID;
			$start_str  = (string) get_post_meta( $res_id, '_en_source_event_start_date', true );
			$end_str    = (string) get_post_meta( $res_id, '_en_nightly_end_date', true );
			$start_ts   = $start_str ? strtotime( $start_str ) : 0;
			$days_until = $start_ts ? (int) floor( ( $start_ts - $today_ts ) / DAY_IN_SECONDS ) : 0;

			// Aggregate per-reservation orders.
			$count   = 0;
			$revenue = 0.0;
			$tags    = array();
			foreach ( $orders as $o ) {
				if ( (int) ( $o['reservation_id'] ?? 0 ) !== $res_id ) {
					continue;
				}
				$count++;
				$revenue += (float) ( $o['total'] ?? 0 );
				if ( ! empty( $o['has_stall'] ) || ! empty( $o['stall_components'] ) || (int) ( $o['stall_quantity'] ?? 0 ) > 0 ) { $tags['stall'] = true; }
				if ( ! empty( $o['has_rv'] ) || ! empty( $o['rv_components'] ) || (int) ( $o['rv_quantity'] ?? 0 ) > 0 )       { $tags['rv']    = true; }
			}
			if ( empty( $tags ) ) { $tags['stall'] = true; }

			$rows[] = array(
				'id'         => $res_id,
				'name'       => get_the_title( $post ),
				'date_range' => self::format_date_range( $start_str, $end_str ),
				'opens_in'   => self::format_event_countdown( $days_until ),
				'tags'       => array_keys( $tags ),
				'orders'     => $count,
				'revenue'    => self::format_currency( $revenue ),
				'stall_progress' => $this->stall_progress_for( $res_id ),
				'rv_progress'    => $this->rv_progress_for( $res_id ),
			);
		}
		wp_reset_postdata();
		return $rows;
	}

	/**
	 * Per-reservation stall-assignment progress bar payload (assigned / total +
	 * width % + tone), live via EEM_Admin metrics.
	 *
	 * Tone: green when nothing is outstanding, amber when partially assigned,
	 * red when stalls are purchased but none assigned yet.
	 *
	 * @param int $res_id Reservation post ID.
	 * @return array{assigned:string, total:string, pct:int, tone:string}
	 */
	private function stall_progress_for( $res_id ) {
		$m        = $this->stall_metrics();
		$pr       = isset( $m['per_reservation'][ (int) $res_id ] ) ? $m['per_reservation'][ (int) $res_id ] : null;
		$assigned = $pr ? (int) $pr['assigned'] : 0;
		$unassign = $pr ? (int) $pr['unassigned'] : 0;
		$total    = $pr ? (int) $pr['total'] : 0;

		$pct = $total > 0 ? (int) round( ( $assigned / $total ) * 100 ) : 100;
		if ( $unassign <= 0 ) {
			$tone = 'green';
		} elseif ( $assigned > 0 ) {
			$tone = 'amber';
		} else {
			$tone = 'red';
		}

		return array(
			'assigned' => number_format_i18n( $assigned ),
			'total'    => number_format_i18n( $total ),
			'pct'      => max( 0, min( 100, $pct ) ),
			'tone'     => $tone,
		);
	}

	/**
	 * Per-reservation RV-lot assignment progress payload (assigned / total),
	 * parallel to stall_progress_for(). Live via EEM_Admin metrics.
	 *
	 * @param int $res_id Reservation post ID.
	 * @return array{assigned:string, total:string}
	 */
	private function rv_progress_for( $res_id ) {
		$m        = $this->stall_metrics();
		$pr       = isset( $m['per_reservation'][ (int) $res_id ] ) ? $m['per_reservation'][ (int) $res_id ] : null;
		$assigned = $pr ? (int) ( $pr['rv_assigned'] ?? 0 ) : 0;
		$total    = $pr ? (int) ( $pr['rv_total'] ?? 0 ) : 0;

		return array(
			'assigned' => number_format_i18n( $assigned ),
			'total'    => number_format_i18n( $total ),
		);
	}

	/**
	 * Build the Needs Attention payload — live data. Each row represents an
	 * outstanding issue and is only emitted when it has something to flag:
	 * stalls unassigned, RV lots unassigned, orders awaiting payment, a
	 * stall/RV reservation with no chart configured, or a missing Stripe
	 * webhook secret. Resolved categories (0 count) drop off the card.
	 *
	 * The "customers haven't signed the agreement" row is intentionally NOT
	 * emitted: V1 records only "Venue Agreement Provided" (event-side), not a
	 * per-order customer signature, so there's no live data to drive it. It
	 * returns once signature tracking is recorded per order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function attention_items() {
		$items   = array();
		$metrics = $this->stall_metrics();
		$charts_url = admin_url( 'admin.php?page=equine-event-manager-stall-charts' );

		// ── Stalls unassigned — surfaces the single most-affected reservation ──
		$worst = $this->worst_unassigned_reservation( $metrics );
		if ( null !== $worst ) {
			$desc_parts = array(
				sprintf(
					/* translators: %1$d: assigned stalls, %2$d: total stalls */
					__( 'Stall chart has %1$d of %2$d stalls assigned', 'equine-event-manager' ),
					$worst['assigned'],
					$worst['total']
				),
			);
			if ( $worst['start_ts'] > 0 ) {
				$days = (int) floor( ( $worst['start_ts'] - strtotime( 'today' ) ) / DAY_IN_SECONDS );
				$opens = self::format_event_countdown( $days );
				$desc_parts[] = $opens['label'];
			}
			$items[] = array(
				'icon'     => 'red',
				'icon_key' => 'grid',
				'title'    => sprintf(
					/* translators: %1$d: unassigned stall count, %2$s: reservation name */
					_n( '%1$d stall unassigned — %2$s', '%1$d stalls unassigned — %2$s', $worst['unassigned'], 'equine-event-manager' ),
					$worst['unassigned'],
					$worst['title']
				),
				'desc'     => implode( ' · ', $desc_parts ),
				'href'     => $charts_url,
				'action'   => __( 'Assign →', 'equine-event-manager' ),
			);
		}

		// ── Orders awaiting payment ──
		$outstanding_statuses = array( 'unpaid', 'invoice-sent', 'partially-paid' );
		$out_count            = 0;
		$out_total            = 0.0;
		foreach ( $this->all_orders() as $o ) {
			if ( in_array( (string) ( $o['status_slug'] ?? '' ), $outstanding_statuses, true ) ) {
				$out_count++;
				$out_total += (float) ( $o['total'] ?? 0 );
			}
		}
		if ( $out_count > 0 ) {
			$items[] = array(
				'icon'     => 'orange',
				'icon_key' => 'card',
				'title'    => sprintf(
					/* translators: %d: count of unpaid orders */
					_n( '%d order awaiting payment', '%d orders awaiting payment', $out_count, 'equine-event-manager' ),
					$out_count
				),
				'desc'     => sprintf(
					/* translators: %s: outstanding currency total */
					__( '%s outstanding', 'equine-event-manager' ),
					self::format_currency( $out_total )
				),
				// DS-1.B.5: param is `billing` not `status` (see Orders list render).
				'href'     => EEM_Orders_List_Page::url( array( 'billing' => 'unpaid' ) ),
				'action'   => __( 'View →', 'equine-event-manager' ),
			);
		}

		// ── RV lots unassigned ──
		$rv_un = (int) ( $metrics['rv_unassigned_total'] ?? 0 );
		if ( $rv_un > 0 ) {
			$items[] = array(
				'icon'     => 'red',
				'icon_key' => 'alert-triangle',
				'title'    => sprintf(
					/* translators: %d: count of unassigned RV lots */
					_n( '%d RV lot assignment issue', '%d RV lot assignment issues', $rv_un, 'equine-event-manager' ),
					$rv_un
				),
				'desc'     => __( 'RV lots purchased without an assigned lot', 'equine-event-manager' ),
				'href'     => $charts_url,
				'action'   => __( 'Fix →', 'equine-event-manager' ),
			);
		}

		// ── Stall/RV reservations with no chart configured ──
		$unconfigured = isset( $metrics['unconfigured'] ) ? (array) $metrics['unconfigured'] : array();
		if ( ! empty( $unconfigured ) ) {
			$first = $unconfigured[0];
			$more  = count( $unconfigured ) - 1;
			$desc  = $more > 0
				? sprintf(
					/* translators: %d: count of additional reservations needing a chart */
					_n( '%d more reservation also needs a chart', '%d more reservations also need a chart', $more, 'equine-event-manager' ),
					$more
				)
				: __( 'Sells stalls or RV but has no chart layout yet', 'equine-event-manager' );
			$items[] = array(
				'icon'     => 'orange',
				'icon_key' => 'users',
				'title'    => sprintf(
					/* translators: %s: reservation name */
					__( '%s stall chart not configured', 'equine-event-manager' ),
					(string) $first['title']
				),
				'desc'     => $desc,
				'href'     => $charts_url,
				'action'   => __( 'Set up →', 'equine-event-manager' ),
			);
		}

		// ── Stripe webhook secret missing (config check, not a data count) ──
		if ( '' === $this->stripe_webhook_secret() ) {
			$items[] = array(
				// Attention icons are always red or amber — never blue/info, which
				// reads as "neutral" and contradicts the "needs attention" framing
				// (Whitney 2026-06-21). A missing webhook is a config warning → amber.
				'icon'     => 'orange',
				'icon_key' => 'alert-circle',
				'title'    => __( 'Stripe webhook not configured', 'equine-event-manager' ),
				'desc'     => __( 'Payment confirmations may be delayed without a webhook secret', 'equine-event-manager' ),
				'href'     => admin_url( 'admin.php?page=equine-event-manager-settings' ),
				'action'   => __( 'Fix →', 'equine-event-manager' ),
			);
		}

		return $items;
	}

	/**
	 * Pick the reservation with the most unassigned stalls (tie-break: soonest
	 * upcoming start), for the Needs Attention "stalls unassigned" row.
	 *
	 * @param array<string, mixed> $metrics Output of stall_metrics().
	 * @return array{assigned:int,total:int,unassigned:int,title:string,start_ts:int}|null
	 */
	private function worst_unassigned_reservation( array $metrics ) {
		$worst = null;
		foreach ( (array) ( $metrics['per_reservation'] ?? array() ) as $pr ) {
			if ( (int) $pr['unassigned'] <= 0 ) {
				continue;
			}
			if ( null === $worst ) {
				$worst = $pr;
				continue;
			}
			$more_unassigned = (int) $pr['unassigned'] > (int) $worst['unassigned'];
			$tie_sooner      = (int) $pr['unassigned'] === (int) $worst['unassigned']
				&& $pr['start_ts'] > 0
				&& ( $worst['start_ts'] <= 0 || $pr['start_ts'] < $worst['start_ts'] );
			if ( $more_unassigned || $tie_sooner ) {
				$worst = $pr;
			}
		}
		return $worst;
	}

	/**
	 * Read the configured Stripe webhook signing secret (empty string if unset).
	 *
	 * @return string
	 */
	private function stripe_webhook_secret() {
		$settings = get_option( 'equine_event_manager_payment_settings', array() );
		$secret   = '';
		if ( is_array( $settings ) && isset( $settings['stripe']['webhook_signing_secret'] ) ) {
			$secret = (string) $settings['stripe']['webhook_signing_secret'];
		}
		return trim( $secret );
	}

	/**
	 * Recent Orders — top 5 by created_at DESC. Uses the canonical
	 * `EEM_Orders_List_Page::format_order_number_display()` helper for the
	 * 5-digit zero-padded `#NNNNN` rendering at render time.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_orders() {
		$orders = $this->all_orders();
		$slice  = array_slice( $orders, 0, 5 );
		$out    = array();
		foreach ( $slice as $o ) {
			$out[] = array(
				'number'       => isset( $o['order_number'] ) ? (string) $o['order_number'] : '',
				'order_key'    => isset( $o['order_key'] ) ? (string) $o['order_key'] : '',
				'customer'     => isset( $o['customer_name'] ) ? (string) $o['customer_name'] : '',
				'event'        => isset( $o['reservation_title'] ) ? (string) $o['reservation_title'] : '',
				'total'        => self::format_currency( (float) ( $o['total'] ?? 0 ) ),
				'status_slug'  => isset( $o['status_slug'] ) ? (string) $o['status_slug'] : '',
				'status_label' => isset( $o['status_label'] ) ? (string) $o['status_label'] : '',
			);
		}
		return $out;
	}

	/**
	 * This Week summary — 5 rows. Row 5 (Stalls assigned) is em-dash per
	 * CLEANUP #39.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function this_week() {
		$week_start = strtotime( 'monday this week' );
		$week_end   = time();
		$orders     = $this->in_window( $this->all_orders(), $week_start, $week_end );

		$new_orders     = count( $orders );
		$revenue        = 0.0;
		$invoices_sent  = 0;
		$refunds_amount = 0.0;
		$metrics         = $this->stall_metrics();
		$assigned_map    = (array) ( $metrics['assigned_by_order_key'] ?? array() );
		$rv_assigned_map = (array) ( $metrics['rv_assigned_by_order_key'] ?? array() );
		$stalls_assigned = 0;
		$rv_assigned     = 0;
		foreach ( $orders as $o ) {
			$slug = (string) ( $o['status_slug'] ?? '' );
			$amt  = (float) ( $o['total'] ?? 0 );
			if ( 'paid' === $slug || 'partially-paid' === $slug ) { $revenue += $amt; }
			if ( 'invoice-sent' === $slug )                       { $invoices_sent++; }
			if ( 'refunded' === $slug )                           { $refunds_amount += $amt; }
			$ok = (string) ( $o['order_key'] ?? '' );
			if ( '' !== $ok && isset( $assigned_map[ $ok ] ) ) {
				$stalls_assigned += (int) $assigned_map[ $ok ];
			}
			if ( '' !== $ok && isset( $rv_assigned_map[ $ok ] ) ) {
				$rv_assigned += (int) $rv_assigned_map[ $ok ];
			}
		}

		return array(
			array( 'label' => __( 'New orders', 'equine-event-manager' ),       'value' => number_format_i18n( $new_orders ), 'tone' => '' ),
			array( 'label' => __( 'Revenue collected', 'equine-event-manager' ),'value' => self::format_currency( $revenue ), 'tone' => 'positive' ),
			array( 'label' => __( 'Invoices sent', 'equine-event-manager' ),    'value' => number_format_i18n( $invoices_sent ), 'tone' => '' ),
			array( 'label' => __( 'Refunds processed', 'equine-event-manager' ),'value' => self::format_currency( $refunds_amount ), 'tone' => 'negative' ),
			// Stalls assigned on orders placed this week (no per-assignment
			// timestamp exists, so order-creation week is the honest proxy).
			array( 'label' => __( 'Stalls assigned', 'equine-event-manager' ),  'value' => number_format_i18n( $stalls_assigned ), 'tone' => '' ),
			array( 'label' => __( 'RV lots assigned', 'equine-event-manager' ),'value' => number_format_i18n( $rv_assigned ), 'tone' => '' ),
		);
	}

	/**
	 * Count of reservations with arrival date >= today (header subtitle).
	 *
	 * @param int $within_days Optional window (default 30) — matches the
	 *                         mockup's "next 30 days" subtitle phrasing.
	 * @return int
	 */
	public function upcoming_reservations_count( $within_days = 30 ) {
		$today_ts = strtotime( 'today' );
		$end_ts   = $today_ts + ( $within_days * DAY_IN_SECONDS );
		$q = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_en_source_event_start_date',
					'value'   => array( date( 'Y-m-d', $today_ts ), date( 'Y-m-d', $end_ts ) ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		) );
		$count = (int) $q->found_posts;
		wp_reset_postdata();
		return $count;
	}

	// ── Formatting helpers ──────────────────────────────────────────────

	/**
	 * @param float $amount
	 * @return string
	 */
	public static function format_currency( $amount ) {
		return '$' . number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * "May 8 – May 10, 2026" style date range.
	 *
	 * @param string $start_ymd
	 * @param string $end_ymd
	 * @return string
	 */
	public static function format_date_range( $start_ymd, $end_ymd ) {
		$start_ts = $start_ymd ? strtotime( $start_ymd ) : 0;
		$end_ts   = $end_ymd ? strtotime( $end_ymd ) : 0;
		if ( ! $start_ts ) {
			return '';
		}
		if ( ! $end_ts || date( 'Y-m-d', $start_ts ) === date( 'Y-m-d', $end_ts ) ) {
			return date_i18n( 'M j, Y', $start_ts );
		}
		// Same year → drop year from start.
		if ( date( 'Y', $start_ts ) === date( 'Y', $end_ts ) ) {
			return date_i18n( 'M j', $start_ts ) . ' – ' . date_i18n( 'M j, Y', $end_ts );
		}
		return date_i18n( 'M j, Y', $start_ts ) . ' – ' . date_i18n( 'M j, Y', $end_ts );
	}

	/**
	 * Relative "when" chip for a reservation's event, anchored on the event
	 * START date (callers pass days-until-event-start, derived from
	 * `_en_source_event_start_date`). Wording is kept consistent with the
	 * Upcoming Events card (event_when_label) so both Dashboard cards read the
	 * same way: "Happening now" / "Starts today" / "Starts in N days".
	 *
	 * (Formerly format_opens_in / "Opens in N days" — that phrasing implied a
	 * registration-open countdown, but the value measured is the event start,
	 * which conflicted with the events card. #10.)
	 *
	 * @param int $days_until Days until the event starts (negative = underway).
	 * @return array{label:string, tone:string}
	 */
	public static function format_event_countdown( $days_until ) {
		if ( $days_until < 0 ) {
			return array( 'label' => __( 'Happening now', 'equine-event-manager' ), 'tone' => 'opens-soon' );
		}
		if ( 0 === $days_until ) {
			return array( 'label' => __( 'Starts today', 'equine-event-manager' ), 'tone' => 'opens-soon' );
		}
		if ( $days_until <= 7 ) {
			return array(
				/* translators: %d: days until the event starts */
				'label' => sprintf( _n( 'Starts in %d day', 'Starts in %d days', $days_until, 'equine-event-manager' ), $days_until ),
				'tone'  => 'opens-soon',
			);
		}
		return array(
			/* translators: %d: days until the event starts */
			'label' => sprintf( _n( 'Starts in %d day', 'Starts in %d days', $days_until, 'equine-event-manager' ), $days_until ),
			'tone'  => 'future',
		);
	}

	/**
	 * Time-of-day greeting prefix ("Good morning/afternoon/evening").
	 * Per DS-1.B kickoff (calibration B): if $display_name is empty, drop the
	 * name segment entirely rather than fall back to a placeholder.
	 *
	 * @param string $display_name
	 * @return string  Pre-translated greeting, no trailing punctuation.
	 */
	public static function format_greeting( $display_name ) {
		$hour = (int) current_time( 'G' );
		if ( $hour < 12 ) {
			$prefix = __( 'Good morning', 'equine-event-manager' );
		} elseif ( $hour < 18 ) {
			$prefix = __( 'Good afternoon', 'equine-event-manager' );
		} else {
			$prefix = __( 'Good evening', 'equine-event-manager' );
		}
		$display_name = trim( (string) $display_name );
		if ( '' === $display_name ) {
			return $prefix;
		}
		return $prefix . ', ' . $display_name;
	}

	/**
	 * Optional-add-on activity for the Dashboard, gated on the per-site feature
	 * flags. Only the enabled add-ons return a populated payload; the card
	 * renders nothing when both are off.
	 *
	 * @return array{
	 *   entries: array{enabled: bool, divisions: int, entrants: int},
	 *   sheets:  array{enabled: bool, events: int, drawsheets: int, results: int, awaiting: int}
	 * }
	 */
	/**
	 * Upcoming native events for the Dashboard "Upcoming Events" card — shown
	 * only when the Native Events feature is enabled (Whitney 2026-06-14: make
	 * sure nothing is left out of the dashboard). Returns published `en_event`
	 * events that haven't ended yet (upcoming + ongoing), soonest first, each
	 * with a relative "when" chip parallel to the Upcoming Reservations card.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int, array{id:int,title:string,date_range:string,venue:string,when:array{label:string,tone:string}}>
	 */
	public function upcoming_events( int $limit = 5 ): array {
		if ( ! class_exists( 'EEM_Events' ) || ! EEM_Events::is_native_events_enabled() ) {
			return array();
		}

		$posts = EEM_Events::get_upcoming_native_events( 200 );
		$today = (int) strtotime( current_time( 'Y-m-d' ) );
		$out   = array();

		foreach ( $posts as $p ) {
			if ( 'publish' !== get_post_status( $p ) ) {
				continue;
			}
			$evt      = EEM_Native_Event_Repo::get( (int) $p->ID );
			$start    = $evt['start_date'];
			$end      = $evt['end_date'];
			$start_ts = '' !== trim( $start ) ? (int) strtotime( $start ) : 0;
			$end_ts   = '' !== trim( $end ) ? (int) strtotime( $end ) : $start_ts;
			// Skip events that have already ended.
			if ( $end_ts && $end_ts < $today ) {
				continue;
			}
			$venue_id = $evt['venue_id'];

			$out[] = array(
				'id'         => (int) $p->ID,
				'title'      => (string) get_the_title( $p ),
				'date_range' => self::format_date_range( $start, $end ),
				'venue'      => $venue_id > 0 ? (string) get_the_title( $venue_id ) : '',
				'when'       => self::event_when_label( $start_ts, $end_ts, $today ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Relative "when" chip for an upcoming event: Happening now / Starts today /
	 * Starts in N days. Wording matches format_event_countdown so the Upcoming
	 * Events and Upcoming Reservations cards read consistently (#10). Tone drives
	 * the pill colour (opens-soon = live/today/green, future = blue).
	 *
	 * @param int $start_ts Event start (unix, 0 if none).
	 * @param int $end_ts   Event end (unix, 0 if none).
	 * @param int $today    Midnight-today (unix).
	 * @return array{label:string,tone:string}
	 */
	private static function event_when_label( int $start_ts, int $end_ts, int $today ): array {
		if ( $start_ts && $start_ts < $today && ( ! $end_ts || $end_ts >= $today ) ) {
			return array( 'label' => __( 'Happening now', 'equine-event-manager' ), 'tone' => 'opens-soon' );
		}
		if ( $start_ts && $start_ts === $today ) {
			return array( 'label' => __( 'Starts today', 'equine-event-manager' ), 'tone' => 'opens-soon' );
		}
		if ( $start_ts && $start_ts > $today ) {
			$days = (int) round( ( $start_ts - $today ) / DAY_IN_SECONDS );
			return array(
				/* translators: %d: number of days until the event starts. */
				'label' => sprintf( _n( 'Starts in %d day', 'Starts in %d days', $days, 'equine-event-manager' ), $days ),
				'tone'  => 'future',
			);
		}
		return array( 'label' => '', 'tone' => 'future' );
	}

	/**
	 * General Add-Ons offered across reservations — for the dashboard "Add-Ons"
	 * card (Whitney 2026-06-14: this card is for the purchasable add-on items
	 * configured in Edit Reservation → General Add-Ons, e.g. Golf Cart Rental /
	 * Extra Wristband — NOT plugin features). Counts the distinct add-on line
	 * items configured on published reservations that have General Add-Ons on.
	 *
	 * @return array{items:int,reservations:int}
	 */
	public function general_addons_summary(): array {
		$ids   = get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$items = 0;
		$res   = 0;
		foreach ( $ids as $rid ) {
			if ( '1' !== (string) get_post_meta( (int) $rid, '_en_general_addons_enabled', true ) ) {
				continue;
			}
			$cfg = get_post_meta( (int) $rid, '_en_general_addons', true );
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$count = 0;
			foreach ( $cfg as $item ) {
				if ( is_array( $item ) && '' !== trim( (string) ( $item['name'] ?? '' ) ) ) {
					$count++;
				}
			}
			if ( $count > 0 ) {
				$items += $count;
				$res++;
			}
		}

		return array( 'items' => $items, 'reservations' => $res );
	}

	/**
	 * Count of distinct stalls + RV lots currently flagged "needs cleaning"
	 * across all reservations. Powers the Dashboard Facility card.
	 *
	 * @return int
	 */
	public function facility_cleaning_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stall_status';
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT reservation_id, stall_unit) FROM {$table} WHERE status = 'needs_cleaning'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Today's movement aggregated across every reservation active today (±1 day):
	 * how many customers are arriving and departing. Powers the Dashboard
	 * "Today's Movement" card.
	 *
	 * @return array{active:bool,label:string,arriving:int,departing:int,date:string,reservation_id:int}
	 */
	public function today_movement(): array {
		if ( ! class_exists( 'EEM_Daily_Movement_Service' ) ) {
			require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-daily-movement-service.php';
		}
		$today    = current_time( 'Y-m-d' );
		$windows  = EEM_Daily_Movement_Service::get_reservation_windows();
		$arriving = 0;
		$departing = 0;
		$active   = array();

		foreach ( $windows as $rid => $win ) {
			if ( ! EEM_Daily_Movement_Service::is_reservation_active( $win, $today, 1 ) ) {
				continue;
			}
			$report     = EEM_Daily_Movement_Service::build_date_report( (int) $rid, $today );
			$arriving  += (int) ( $report['summary']['arriving'] ?? 0 );
			$departing += (int) ( $report['summary']['departing'] ?? 0 );
			$active[]   = (int) $rid;
		}

		$label = '';
		if ( 1 === count( $active ) ) {
			$label = (string) get_the_title( $active[0] );
		} elseif ( count( $active ) > 1 ) {
			/* translators: %d: number of events active today */
			$label = sprintf( _n( '%d active event', '%d active events', count( $active ), 'equine-event-manager' ), count( $active ) );
		}

		return array(
			'active'         => ! empty( $active ),
			'label'          => $label,
			'arriving'       => $arriving,
			'departing'      => $departing,
			'date'           => $today,
			'reservation_id' => 1 === count( $active ) ? $active[0] : 0,
		);
	}

	public static function addons_summary(): array {
		global $wpdb;

		$entries_on = class_exists( 'EEM_Events' ) && EEM_Events::is_entries_enabled();
		$sheets_on  = class_exists( 'EEM_Events' ) && EEM_Events::is_sheets_results_enabled();

		$out = array(
			'entries' => array( 'enabled' => $entries_on, 'divisions' => 0, 'entrants' => 0 ),
			'sheets'  => array( 'enabled' => $sheets_on, 'events' => 0, 'drawsheets' => 0, 'results' => 0, 'awaiting' => 0 ),
		);

		if ( $entries_on ) {
			$counts                       = wp_count_posts( EEM_Entries::POST_TYPE );
			$out['entries']['divisions']  = isset( $counts->publish ) ? (int) $counts->publish : 0;
			if ( class_exists( 'EEM_Division_Entries' ) ) {
				$table                       = EEM_Division_Entries::table_name();
				$out['entries']['entrants']  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(qty),0) FROM {$table} WHERE status IN ('paid','unpaid')" ); // phpcs:ignore WordPress.DB
			}
		}

		if ( $sheets_on && class_exists( 'EEM_Sheet_Entries' ) ) {
			$table                       = EEM_Sheet_Entries::table_name();
			$out['sheets']['events']     = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT event_id) FROM {$table} WHERE drawsheet_pdf > 0 OR result_pdf > 0" ); // phpcs:ignore WordPress.DB
			$out['sheets']['drawsheets'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE drawsheet_pdf > 0" ); // phpcs:ignore WordPress.DB
			$out['sheets']['results']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE result_pdf > 0" ); // phpcs:ignore WordPress.DB
			$out['sheets']['awaiting']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE drawsheet_pdf > 0 AND result_pdf = 0" ); // phpcs:ignore WordPress.DB
		}

		return $out;
	}
}
