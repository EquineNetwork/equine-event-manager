<?php
/**
 * Equine Event Manager — Dashboard data repository (DS-1.B).
 *
 * Builds the data payload consumed by `EEM_Dashboard_Page::render()` against
 * `.mockups/dashboard_page.html`. All KPI / Recent Orders / This-Week / Revenue
 * Chart numbers derive from canonical sources (`EEM_Orders_Repository::get_grouped_orders`
 * for orders, `en_reservation` CPT posts for Upcoming Reservations). Sections
 * whose source data is still owed by C8 (Stall Charts) or C11 (Customer
 * Confirmation Email / agreement tracking) ship as graceful-degrade em-dash
 * placeholders per CLEANUP #37-#40.
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
		list( $from, $to ) = self::range_window( $range );
		$orders            = $this->in_window( $this->all_orders(), $from, $to );

		$total_revenue       = 0.0;
		$outstanding_amount  = 0.0;
		$outstanding_count   = 0;
		$total_orders        = count( $orders );
		$outstanding_statuses = array( 'unpaid', 'invoice-sent', 'partially-paid' );

		foreach ( $orders as $o ) {
			$slug = isset( $o['status_slug'] ) ? (string) $o['status_slug'] : '';
			$amt  = isset( $o['total'] ) ? (float) $o['total'] : 0.0;
			if ( 'paid' === $slug || 'partially-paid' === $slug ) {
				$total_revenue += $amt;
			}
			if ( in_array( $slug, $outstanding_statuses, true ) ) {
				$outstanding_amount += $amt;
				$outstanding_count++;
			}
		}

		return array(
			array(
				'border' => 'blue',
				'label'  => __( 'Total Revenue', 'equine-event-manager' ),
				'value'  => self::format_currency( $total_revenue ),
				'sub'    => __( 'in selected range', 'equine-event-manager' ),
			),
			array(
				'border'   => 'orange',
				'label'    => __( 'Outstanding Payments', 'equine-event-manager' ),
				'value'    => self::format_currency( $outstanding_amount ),
				'sub_pre'  => sprintf(
					/* translators: %d: count of outstanding orders */
					_n( '%d order', '%d orders', $outstanding_count, 'equine-event-manager' ),
					$outstanding_count
				),
				'sub_post' => __( ' awaiting payment', 'equine-event-manager' ),
				'sub_tone' => 'warn',
			),
			array(
				'border' => 'green',
				'label'  => __( 'Total Orders', 'equine-event-manager' ),
				'value'  => number_format_i18n( $total_orders ),
				'sub'    => __( 'in selected range', 'equine-event-manager' ),
			),
			array(
				'border'    => 'red',
				'label'     => __( 'Unassigned Stalls', 'equine-event-manager' ),
				'value'     => '—', // CLEANUP #37: wire to real query at C8 close.
				'sub'       => __( 'pending C8 stall-chart data', 'equine-event-manager' ),
				'sub_tone'  => 'down',
				'em_dash'   => true,
			),
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
				if ( ! empty( $o['has_stall'] ) || ! empty( $o['stall_components'] ) ) { $tags['stall'] = true; }
				if ( ! empty( $o['has_rv'] ) || ! empty( $o['rv_components'] ) )       { $tags['rv']    = true; }
			}
			if ( empty( $tags ) ) { $tags['stall'] = true; }

			$rows[] = array(
				'id'         => $res_id,
				'name'       => get_the_title( $post ),
				'date_range' => self::format_date_range( $start_str, $end_str ),
				'opens_in'   => self::format_opens_in( $days_until ),
				'tags'       => array_keys( $tags ),
				'orders'     => $count,
				'revenue'    => self::format_currency( $revenue ),
				// CLEANUP #38: wire to real query at C8 close.
				'stall_progress' => array(
					'assigned' => '—',
					'total'    => '—',
					'pct'      => 0,
					'tone'     => 'red',
					'em_dash'  => true,
				),
			);
		}
		wp_reset_postdata();
		return $rows;
	}

	/**
	 * Build the 6-row Needs Attention payload. Mixes real-data rows (orders
	 * awaiting payment is fully queryable) with C8/C11-blocked rows that
	 * render as em-dash placeholders per CLEANUP #39/#40.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function attention_items() {
		$orders               = $this->all_orders();
		$outstanding_statuses = array( 'unpaid', 'invoice-sent', 'partially-paid' );
		$out_count            = 0;
		$out_total            = 0.0;
		foreach ( $orders as $o ) {
			if ( in_array( (string) ( $o['status_slug'] ?? '' ), $outstanding_statuses, true ) ) {
				$out_count++;
				$out_total += (float) ( $o['total'] ?? 0 );
			}
		}

		return array(
			array(
				// CLEANUP #39: wire to C8 stall-chart unassigned query.
				'icon'  => 'red',
				'icon_key' => 'stall',
				'title' => __( '— stalls unassigned', 'equine-event-manager' ),
				'desc'  => __( 'Pending C8 stall-chart data', 'equine-event-manager' ),
				'href'  => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
				'action'=> __( 'Assign →', 'equine-event-manager' ),
				'em_dash' => true,
			),
			array(
				'icon'  => 'orange',
				'icon_key' => 'payment',
				'title' => sprintf(
					/* translators: %d: count of unpaid orders */
					_n( '%d order awaiting payment', '%d orders awaiting payment', $out_count, 'equine-event-manager' ),
					$out_count
				),
				'desc'  => sprintf(
					/* translators: %s: outstanding currency total */
					__( '%s outstanding', 'equine-event-manager' ),
					self::format_currency( $out_total )
				),
				'href'  => EEM_Orders_List_Page::url( array( 'status' => 'unpaid' ) ),
				'action'=> __( 'View →', 'equine-event-manager' ),
			),
			array(
				// CLEANUP #39: wire to C8 RV-lot assignment query.
				'icon'  => 'red',
				'icon_key' => 'alert',
				'title' => __( '— RV lot assignment issues', 'equine-event-manager' ),
				'desc'  => __( 'Pending C8 stall-chart data', 'equine-event-manager' ),
				'href'  => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
				'action'=> __( 'Fix →', 'equine-event-manager' ),
				'em_dash' => true,
			),
			array(
				// CLEANUP #40: wire to C11 agreement-signature tracking.
				'icon'  => 'blue',
				'icon_key' => 'mail',
				'title' => __( '— customers haven\'t signed the agreement', 'equine-event-manager' ),
				'desc'  => __( 'Pending C11 agreement tracking', 'equine-event-manager' ),
				'href'  => EEM_Orders_List_Page::url(),
				'action'=> __( 'View →', 'equine-event-manager' ),
				'em_dash' => true,
			),
			array(
				// CLEANUP #39: wire to C8 stall-chart-not-configured query.
				'icon'  => 'orange',
				'icon_key' => 'config',
				'title' => __( '— stall chart not configured', 'equine-event-manager' ),
				'desc'  => __( 'Pending C8 stall-chart data', 'equine-event-manager' ),
				'href'  => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
				'action'=> __( 'Set up →', 'equine-event-manager' ),
				'em_dash' => true,
			),
			array(
				'icon'  => 'blue',
				'icon_key' => 'gear',
				'title' => __( 'Stripe webhook not configured', 'equine-event-manager' ),
				'desc'  => __( 'Payment confirmations may be delayed without a webhook secret', 'equine-event-manager' ),
				'href'  => admin_url( 'admin.php?page=equine-event-manager-settings' ),
				'action'=> __( 'Fix →', 'equine-event-manager' ),
			),
		);
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
	 * Revenue chart — top 5 reservations by total revenue (all time).
	 *
	 * @return array{bars: array<int, array<string, mixed>>, total_label: string, total_value: string}
	 */
	public function revenue_chart() {
		$orders = $this->all_orders();
		$by_res = array();
		$grand  = 0.0;
		foreach ( $orders as $o ) {
			$slug = (string) ( $o['status_slug'] ?? '' );
			if ( 'paid' !== $slug && 'partially-paid' !== $slug ) {
				continue;
			}
			$key   = (string) ( $o['reservation_title'] ?? __( '(no event)', 'equine-event-manager' ) );
			$amt   = (float) ( $o['total'] ?? 0 );
			$grand += $amt;
			if ( ! isset( $by_res[ $key ] ) ) {
				$by_res[ $key ] = 0.0;
			}
			$by_res[ $key ] += $amt;
		}
		arsort( $by_res );
		$top = array_slice( $by_res, 0, 5, true );
		$max = $top ? max( $top ) : 0.0;

		$bars = array();
		foreach ( $top as $label => $amount ) {
			$bars[] = array(
				'label'   => self::short_event_label( $label ),
				'value'   => self::format_currency_short( $amount ),
				'pct'     => $max > 0 ? max( 4, (int) round( ( $amount / $max ) * 100 ) ) : 0,
			);
		}

		return array(
			'bars'        => $bars,
			'total_label' => __( 'Total collected (all time)', 'equine-event-manager' ),
			'total_value' => self::format_currency( $grand ),
		);
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
		foreach ( $orders as $o ) {
			$slug = (string) ( $o['status_slug'] ?? '' );
			$amt  = (float) ( $o['total'] ?? 0 );
			if ( 'paid' === $slug || 'partially-paid' === $slug ) { $revenue += $amt; }
			if ( 'invoice-sent' === $slug )                       { $invoices_sent++; }
			if ( 'refunded' === $slug )                           { $refunds_amount += $amt; }
		}

		return array(
			array( 'label' => __( 'New orders', 'equine-event-manager' ),       'value' => number_format_i18n( $new_orders ), 'tone' => '' ),
			array( 'label' => __( 'Revenue collected', 'equine-event-manager' ),'value' => self::format_currency( $revenue ), 'tone' => 'positive' ),
			array( 'label' => __( 'Invoices sent', 'equine-event-manager' ),    'value' => number_format_i18n( $invoices_sent ), 'tone' => '' ),
			array( 'label' => __( 'Refunds processed', 'equine-event-manager' ),'value' => self::format_currency( $refunds_amount ), 'tone' => 'negative' ),
			// CLEANUP #39: wire to C8 stall-assignment activity once chart data ships.
			array( 'label' => __( 'Stalls assigned', 'equine-event-manager' ),  'value' => '—', 'tone' => '', 'em_dash' => true ),
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
	 * Short form for chart bar labels — "$6.4k" / "$820".
	 *
	 * @param float $amount
	 * @return string
	 */
	public static function format_currency_short( $amount ) {
		$amount = (float) $amount;
		if ( $amount >= 1000 ) {
			return '$' . number_format_i18n( $amount / 1000, 1 ) . 'k';
		}
		return '$' . number_format_i18n( $amount, 0 );
	}

	/**
	 * Truncate event names to ~10 chars for chart labels.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function short_event_label( $name ) {
		$name = (string) $name;
		if ( strlen( $name ) <= 12 ) {
			return $name;
		}
		return rtrim( substr( $name, 0, 11 ) ) . '…';
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
	 * "Opens in N days" / "Opens today" / "Open now" + tone class.
	 *
	 * @param int $days_until
	 * @return array{label:string, tone:string}
	 */
	public static function format_opens_in( $days_until ) {
		if ( $days_until < 0 ) {
			return array( 'label' => __( 'Open now', 'equine-event-manager' ), 'tone' => 'opens-soon' );
		}
		if ( 0 === $days_until ) {
			return array( 'label' => __( 'Opens today', 'equine-event-manager' ), 'tone' => 'opens-soon' );
		}
		if ( $days_until <= 7 ) {
			return array(
				/* translators: %d: days until reservation opens */
				'label' => sprintf( _n( 'Opens in %d day', 'Opens in %d days', $days_until, 'equine-event-manager' ), $days_until ),
				'tone'  => 'opens-soon',
			);
		}
		return array(
			/* translators: %d: days until reservation opens */
			'label' => sprintf( _n( 'In %d day', 'In %d days', $days_until, 'equine-event-manager' ), $days_until ),
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
}
