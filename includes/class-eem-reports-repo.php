<?php
/**
 * Reports data repository (C15.A).
 *
 * Builds the six report datasets (Orders, Reservations, Revenue, Stall
 * Occupancy, Customer List, Refund Log) from the grouped-order data, honoring
 * the global filters (reservation, date range, order status). Each builder
 * returns a normalized shape:
 *
 *     array(
 *       'title'   => string,            // human report title
 *       'slug'    => string,            // 'orders' | 'reservations' | ...
 *       'headers' => array<int,string>, // column headers
 *       'rows'    => array<int,array>,  // each a flat array aligned to headers
 *     )
 *
 * Pure data — no output. The exporter (C15.B) turns these into CSV/PDF/ZIP and
 * the page (C15.C) renders the catalog. Filter-aware so every report reflects
 * the same scope.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles report datasets from grouped orders + reservation meta.
 */
class EEM_Reports_Repo {

	/**
	 * The six report slugs, in catalog order.
	 *
	 * @var array<int,string>
	 */
	const REPORTS = array( 'orders', 'reservations', 'revenue', 'stall_occupancy', 'rv_occupancy', 'shavings', 'addons', 'customer_list', 'refund_log', 'cleaning' );

	/**
	 * Orders repository.
	 *
	 * @var EEM_Orders_Repository
	 */
	private $orders_repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->orders_repo = new EEM_Orders_Repository();
	}

	/**
	 * Normalize a raw filter array into the canonical shape.
	 *
	 * @param array $filters Raw filters (reservation_id, date_from, date_to, status).
	 * @return array{reservation_id:int,date_from:string,date_to:string,status:string}
	 */
	public function normalize_filters( array $filters ): array {
		return array(
			'reservation_id' => isset( $filters['reservation_id'] ) ? absint( $filters['reservation_id'] ) : 0,
		);
	}

	/**
	 * Get the grouped orders that match the current filters.
	 *
	 * @param array $filters Normalized filters.
	 * @return array<int,array> Filtered grouped orders.
	 */
	public function get_filtered_orders( array $filters ): array {
		$filters = $this->normalize_filters( $filters );
		$out     = array();

		foreach ( $this->orders_repo->get_orders( '', 'date', 'desc', '' ) as $order ) {
			if ( $filters['reservation_id'] > 0 && absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) !== $filters['reservation_id'] ) {
				continue;
			}
			$out[] = $order;
		}

		return $out;
	}

	/**
	 * Whether an order's created date falls within [from, to] (inclusive).
	 *
	 * @param array  $order Grouped order.
	 * @param string $from  Y-m-d or ''.
	 * @param string $to    Y-m-d or ''.
	 * @return bool
	 */
	private function order_in_date_range( array $order, string $from, string $to ): bool {
		if ( '' === $from && '' === $to ) {
			return true;
		}
		$created = isset( $order['created_at'] ) ? substr( (string) $order['created_at'], 0, 10 ) : '';
		if ( '' === $created ) {
			return true;
		}
		if ( '' !== $from && $created < $from ) {
			return false;
		}
		if ( '' !== $to && $created > $to ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether an order matches a status filter slug (Paid/Unpaid/etc.).
	 *
	 * @param array  $order  Grouped order.
	 * @param string $status Status slug from the filter.
	 * @return bool
	 */
	private function order_matches_status( array $order, string $status ): bool {
		$slug = isset( $order['status_slug'] ) && '' !== $order['status_slug']
			? sanitize_key( $order['status_slug'] )
			: sanitize_key( isset( $order['payment_status'] ) ? $order['payment_status'] : '' );

		// Normalize a few synonyms so the filter dropdown matches stored values.
		$aliases = array(
			'paid'         => array( 'paid', 'completed', 'complete' ),
			'unpaid'       => array( 'unpaid', 'pending' ),
			'invoice_sent' => array( 'invoice_sent', 'invoice-sent', 'invoiced' ),
			'partial'      => array( 'partial', 'partially_paid', 'partially-paid' ),
			'refunded'     => array( 'refunded', 'partially_refunded' ),
			'cancelled'    => array( 'cancelled', 'canceled', 'void' ),
		);

		if ( isset( $aliases[ $status ] ) ) {
			return in_array( $slug, $aliases[ $status ], true );
		}
		return $slug === $status;
	}

	/**
	 * Build a single report dataset by slug.
	 *
	 * @param string $slug    Report slug (one of self::REPORTS).
	 * @param array  $filters Filters.
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	public function get_report( string $slug, array $filters ): array {
		switch ( $slug ) {
			case 'orders':
				return $this->orders_report( $filters );
			case 'reservations':
				return $this->reservations_report( $filters );
			case 'revenue':
				return $this->revenue_report( $filters );
			case 'stall_occupancy':
				return $this->stall_occupancy_report( $filters );
			case 'shavings':
				return $this->shavings_report( $filters );
			case 'addons':
				return $this->addons_report( $filters );
			case 'customer_list':
				return $this->customer_list_report( $filters );
			case 'refund_log':
				return $this->refund_log_report( $filters );
			case 'cleaning':
				return $this->cleaning_report( $filters );
			case 'rv_occupancy':
				return $this->rv_occupancy_report( $filters );
			default:
				return array( 'title' => '', 'slug' => $slug, 'headers' => array(), 'rows' => array() );
		}
	}

	/**
	 * Money formatter (plain, CSV-safe — no currency symbol).
	 *
	 * @param mixed $v Amount.
	 * @return string
	 */
	private function money( $v ): string {
		return number_format( (float) $v, 2, '.', '' );
	}

	/**
	 * Report 1 — Orders: one row per order.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function orders_report( array $filters ): array {
		$rows          = array();
		$event_names   = array();
		$event_dates   = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$rows[] = array(
				EEM_Formatter::format_order_number( $o['order_number'] ),
				(string) ( $o['customer_name'] ?? '' ),
				(string) ( $o['email'] ?? '' ),
				(string) ( $o['phone'] ?? '' ),
				! empty( $o['type_labels'] ) && is_array( $o['type_labels'] ) ? implode( ', ', $o['type_labels'] ) : (string) ( $o['type'] ?? '' ),
				(string) absint( $o['stall_quantity'] ?? 0 ),
				(string) absint( $o['rv_quantity'] ?? 0 ),
				$this->money( $o['stall_subtotal'] ?? 0 ),
				$this->money( $o['rv_subtotal'] ?? 0 ),
				$this->money( $o['fees'] ?? 0 ),
				$this->money( $o['tax'] ?? 0 ),
				$this->money( $o['total'] ?? 0 ),
				(string) ( $o['status_label'] ?? ( $o['payment_status'] ?? '' ) ),
				(string) ( $o['created_at'] ?? '' ),
			);
			$name = (string) ( $o['event_name'] ?? '' );
			if ( '' !== $name ) {
				$event_names[ $name ] = true;
			}
			$dates = (string) ( $o['event_dates'] ?? '' );
			if ( '' !== $dates ) {
				$event_dates[ $dates ] = true;
			}
		}

		// Collapse unique event names + dates for the print-view header line.
		$unique_names = array_keys( $event_names );
		$unique_dates = array_keys( $event_dates );
		$event_header = '';
		if ( 1 === count( $unique_names ) ) {
			$event_header = $unique_names[0];
			if ( 1 === count( $unique_dates ) ) {
				$event_header .= '  ·  ' . $unique_dates[0];
			}
		} elseif ( count( $unique_names ) > 1 ) {
			$event_header = implode( '  |  ', $unique_names );
		}

		return array(
			'title'         => __( 'Orders', 'equine-event-manager' ),
			'slug'          => 'orders',
			'event_header'  => $event_header,
			'print_columns' => array( 0, 1, 3, 5, 6, 7, 8, 9, 10, 11, 12 ),
			'headers'       => array(
				__( 'Order #', 'equine-event-manager' ),
				__( 'Customer', 'equine-event-manager' ),
				__( 'Email', 'equine-event-manager' ),
				__( 'Phone', 'equine-event-manager' ),
				__( 'Type', 'equine-event-manager' ),
				__( 'Stalls', 'equine-event-manager' ),
				__( 'RV Spots', 'equine-event-manager' ),
				__( 'Stall Subtotal', 'equine-event-manager' ),
				__( 'RV Subtotal', 'equine-event-manager' ),
				__( 'Convenience Fee', 'equine-event-manager' ),
				__( 'Tax', 'equine-event-manager' ),
				__( 'Total', 'equine-event-manager' ),
				__( 'Status', 'equine-event-manager' ),
				__( 'Created', 'equine-event-manager' ),
			),
			'rows'         => $rows,
		);
	}

	/**
	 * Report 2 — Reservations: event-level aggregate (orders, revenue, stalls, RV, occupancy).
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function reservations_report( array $filters ): array {
		$agg = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$rid = absint( $o['reservation_id'] ?? 0 );
			$key = $rid > 0 ? (string) $rid : 'r:' . ( $o['event_name'] ?? '' );
			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = array(
					'rid'    => $rid,
					'title'  => (string) ( $o['reservation_title'] ?? ( $o['event_name'] ?? '' ) ),
					'dates'  => (string) ( $o['event_dates'] ?? '' ),
					'orders' => 0,
					'rev'    => 0.0,
					'stalls' => 0,
					'rv'     => 0,
				);
			}
			$agg[ $key ]['orders']++;
			$agg[ $key ]['rev']    += (float) ( $o['total'] ?? 0 );
			$agg[ $key ]['stalls'] += absint( $o['stall_quantity'] ?? 0 );
			$agg[ $key ]['rv']     += absint( $o['rv_quantity'] ?? 0 );
		}

		$rows = array();
		foreach ( $agg as $r ) {
			$capacity   = $r['rid'] > 0 ? $this->stall_capacity_for_reservation( $r['rid'] ) : 0;
			$occupancy  = $capacity > 0 ? round( $r['stalls'] / $capacity * 100, 1 ) . '%' : '—';
			$rows[]     = array(
				$r['title'],
				$r['dates'],
				(string) $r['orders'],
				$this->money( $r['rev'] ),
				(string) $r['stalls'],
				(string) $r['rv'],
				$capacity > 0 ? (string) $capacity : '—',
				$occupancy,
			);
		}

		return array(
			'title'   => __( 'Reservations', 'equine-event-manager' ),
			'slug'    => 'reservations',
			'headers' => array(
				__( 'Reservation', 'equine-event-manager' ),
				__( 'Event Dates', 'equine-event-manager' ),
				__( 'Total Orders', 'equine-event-manager' ),
				__( 'Total Revenue', 'equine-event-manager' ),
				__( 'Stalls Booked', 'equine-event-manager' ),
				__( 'RV Booked', 'equine-event-manager' ),
				__( 'Stall Capacity', 'equine-event-manager' ),
				__( 'Occupancy', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Report 3 — Revenue: per-order ledger (subtotal, fee, tax, total, refunded, net).
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function revenue_report( array $filters ): array {
		$rows = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$subtotal = (float) ( $o['stall_subtotal'] ?? 0 ) + (float) ( $o['rv_subtotal'] ?? 0 );
			$refunded = $this->order_refunded_amount( $o );
			$total    = (float) ( $o['total'] ?? 0 );
			$rows[]   = array(
				substr( (string) ( $o['created_at'] ?? '' ), 0, 10 ),
				EEM_Formatter::format_order_number( $o['order_number'] ),
				(string) ( $o['reservation_title'] ?? ( $o['event_name'] ?? '' ) ),
				(string) ( $o['payment_gateway'] ?? '' ),
				(string) ( $o['status_label'] ?? ( $o['payment_status'] ?? '' ) ),
				$this->money( $subtotal ),
				$this->money( $o['fees'] ?? 0 ),
				$this->money( $o['tax'] ?? 0 ),
				$this->money( $total ),
				$this->money( $refunded ),
				$this->money( max( 0, $total - $refunded ) ),
			);
		}

		return array(
			'title'   => __( 'Revenue', 'equine-event-manager' ),
			'slug'    => 'revenue',
			'headers' => array(
				__( 'Date', 'equine-event-manager' ),
				__( 'Order #', 'equine-event-manager' ),
				__( 'Reservation', 'equine-event-manager' ),
				__( 'Payment Method', 'equine-event-manager' ),
				__( 'Status', 'equine-event-manager' ),
				__( 'Subtotal', 'equine-event-manager' ),
				__( 'Convenience Fee', 'equine-event-manager' ),
				__( 'Tax', 'equine-event-manager' ),
				__( 'Total', 'equine-event-manager' ),
				__( 'Refunded', 'equine-event-manager' ),
				__( 'Net Revenue', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Report 4 — Stall Occupancy: per-reservation stall/RV utilization (best-effort).
	 *
	 * Capacity is read from reservation meta; assigned/blocked detail is C8 data
	 * (Stall Charts) and is reported as best-effort booked counts until C8 lands.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function stall_occupancy_report( array $filters ): array {
		// When a specific reservation is selected, build the per-day usage grid.
		// When "All reservations" is selected, fall back to the per-event summary.
		if ( $filters['reservation_id'] > 0 ) {
			return $this->stall_occupancy_daily( $filters );
		}

		return $this->stall_occupancy_summary( $filters );
	}

	/**
	 * Stall Occupancy — per-event summary (used when no specific reservation filtered).
	 *
	 * @param array $filters Normalized filters.
	 * @return array
	 */
	private function stall_occupancy_summary( array $filters ): array {
		$agg = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$rid = absint( $o['reservation_id'] ?? 0 );
			if ( $rid <= 0 ) {
				continue;
			}
			if ( ! isset( $agg[ $rid ] ) ) {
				$agg[ $rid ] = array(
					'title'  => (string) ( $o['reservation_title'] ?? ( $o['event_name'] ?? '' ) ),
					'stalls' => 0,
					'rv'     => 0,
				);
			}
			$agg[ $rid ]['stalls'] += absint( $o['stall_quantity'] ?? 0 );
			$agg[ $rid ]['rv']     += absint( $o['rv_quantity'] ?? 0 );
		}

		$rows = array();
		foreach ( $agg as $rid => $r ) {
			$stall_cap = $this->stall_capacity_for_reservation( $rid );
			$rv_cap    = $this->rv_capacity_for_reservation( $rid );
			$fill      = $stall_cap > 0 ? round( $r['stalls'] / $stall_cap * 100, 1 ) . '%' : '—';
			$rows[]    = array(
				$r['title'],
				$stall_cap > 0 ? (string) $stall_cap : '—',
				(string) $r['stalls'],
				$fill,
				$rv_cap > 0 ? (string) $rv_cap : '—',
				(string) $r['rv'],
			);
		}

		return array(
			'title'   => __( 'Stall Occupancy', 'equine-event-manager' ),
			'slug'    => 'stall_occupancy',
			'headers' => array(
				__( 'Reservation', 'equine-event-manager' ),
				__( 'Stall Capacity', 'equine-event-manager' ),
				__( 'Stalls Booked', 'equine-event-manager' ),
				__( 'Fill Rate', 'equine-event-manager' ),
				__( 'RV Capacity', 'equine-event-manager' ),
				__( 'RV Booked', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Stall Occupancy — per-day summary for a single reservation.
	 *
	 * One row per calendar date in the event's range. Columns: Date, Stalls In Use,
	 * Stall Capacity, Occupancy %. "Stalls in use" on a given day = sum of
	 * stall_quantity across all orders whose arrival_date ≤ date ≤ departure_date.
	 * A TOTALS row (peak day + total stall-nights) is prepended for the facility.
	 *
	 * @param array $filters Normalized filters (reservation_id > 0 guaranteed).
	 * @return array
	 */
	private function stall_occupancy_daily( array $filters ): array {
		$rid      = $filters['reservation_id'];
		$capacity = $this->stall_capacity_for_reservation( $rid );

		// Collect per-order stall + date data.
		$order_data = array();
		$min_date   = null;
		$max_date   = null;

		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$stalls = absint( $o['stall_quantity'] ?? 0 );
			if ( $stalls <= 0 ) {
				continue;
			}
			$arrival   = isset( $o['stall_arrival_date'] ) && '' !== (string) $o['stall_arrival_date']
				? (string) $o['stall_arrival_date'] : '';
			$departure = isset( $o['stall_departure_date'] ) && '' !== (string) $o['stall_departure_date']
				? (string) $o['stall_departure_date'] : '';

			if ( '' !== $arrival && ( null === $min_date || $arrival < $min_date ) ) {
				$min_date = $arrival;
			}
			if ( '' !== $departure && ( null === $max_date || $departure > $max_date ) ) {
				$max_date = $departure;
			}

			$order_data[] = array(
				'stalls'    => $stalls,
				'arrival'   => $arrival,
				'departure' => $departure,
			);
		}

		// Build event_header from reservation title + date range.
		$res_title    = (string) get_the_title( $rid );
		$event_header = $res_title;
		if ( null !== $min_date && null !== $max_date ) {
			$event_header .= '  ·  ' . date_i18n( 'M j', strtotime( $min_date ) )
				. ' – ' . date_i18n( 'M j, Y', strtotime( $max_date ) );
		}

		$headers = array(
			__( 'Date', 'equine-event-manager' ),
			__( 'Stalls In Use', 'equine-event-manager' ),
			__( 'Stall Capacity', 'equine-event-manager' ),
			__( 'Occupancy %', 'equine-event-manager' ),
		);

		if ( null === $min_date || null === $max_date ) {
			return array(
				'title'        => __( 'Stall Occupancy', 'equine-event-manager' ),
				'slug'         => 'stall_occupancy',
				'event_header' => $event_header,
				'headers'      => $headers,
				'rows'         => array(),
			);
		}

		// Build the full date range as Y-m-d strings.
		$dates   = array();
		$current = new DateTime( $min_date );
		$end     = new DateTime( $max_date );
		while ( $current <= $end ) {
			$dates[] = $current->format( 'Y-m-d' );
			$current->modify( '+1 day' );
		}

		// For each date, count stalls in use across all orders.
		$rows        = array();
		$peak        = 0;
		$stall_nights = 0;

		foreach ( $dates as $date ) {
			$in_use = 0;
			foreach ( $order_data as $od ) {
				if (
					'' !== $od['arrival'] &&
					'' !== $od['departure'] &&
					$date >= $od['arrival'] &&
					$date <= $od['departure']
				) {
					$in_use += $od['stalls'];
				}
			}

			$pct     = $capacity > 0 ? round( $in_use / $capacity * 100, 1 ) . '%' : '—';
			$rows[]  = array(
				date_i18n( 'D, M j', strtotime( $date ) ),
				(string) $in_use,
				$capacity > 0 ? (string) $capacity : '—',
				$pct,
			);

			if ( $in_use > $peak ) {
				$peak = $in_use;
			}
			$stall_nights += $in_use;
		}

		// Summary row pinned at top: peak day usage + total stall-nights.
		$peak_pct    = $capacity > 0 ? round( $peak / $capacity * 100, 1 ) . '%' : '—';
		$totals_row  = array(
			/* translators: %d: number of days. */
			sprintf( _n( 'TOTALS (%d day)', 'TOTALS (%d days)', count( $dates ), 'equine-event-manager' ), count( $dates ) ),
			/* translators: %d: peak stall count. */
			sprintf( __( 'Peak: %d', 'equine-event-manager' ), $peak ),
			$capacity > 0 ? (string) $capacity : '—',
			/* translators: %s: peak occupancy percentage. */
			sprintf( __( 'Peak: %s', 'equine-event-manager' ), $peak_pct ),
		);

		return array(
			'title'             => __( 'Stall Occupancy', 'equine-event-manager' ),
			'slug'              => 'stall_occupancy',
			'event_header'      => $event_header,
			'headers'           => $headers,
			'rows'              => array_merge( array( $totals_row ), $rows ),
			'summary_row_count' => 1,
		);
	}

	/**
	 * Report — RV Occupancy: daily RV spot utilization for a single reservation.
	 *
	 * Dispatches to the per-day or per-event-summary view depending on whether a
	 * specific reservation is selected.
	 *
	 * @param array $filters Filters (reservation_id).
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	public function rv_occupancy_report( array $filters ): array {
		$rid = absint( $filters['reservation_id'] ?? 0 );
		if ( $rid > 0 ) {
			return $this->rv_occupancy_daily( $filters );
		}
		return $this->rv_occupancy_summary( $filters );
	}

	/**
	 * RV Occupancy — per-event summary row for "All reservations" view.
	 *
	 * @param array $filters Filters.
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	private function rv_occupancy_summary( array $filters ): array {
		$headers = array(
			__( 'Reservation', 'equine-event-manager' ),
			__( 'Event Dates', 'equine-event-manager' ),
			__( 'RV Spots In Use', 'equine-event-manager' ),
			__( 'RV Capacity', 'equine-event-manager' ),
			__( 'Occupancy %', 'equine-event-manager' ),
		);

		$buckets = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$qty = absint( $o['rv_quantity'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			$rid = absint( $o['reservation_id'] ?? 0 );
			$key = $rid > 0 ? (string) $rid : 'r:' . (string) ( $o['event_name'] ?? '' );
			if ( ! isset( $buckets[ $key ] ) ) {
				$title = $rid > 0 ? (string) get_the_title( $rid ) : (string) ( $o['event_name'] ?? '' );
				$buckets[ $key ] = array(
					'title'      => $title,
					'rid'        => $rid,
					'in_use'     => 0,
					'min_date'   => null,
					'max_date'   => null,
				);
			}
			$buckets[ $key ]['in_use'] += $qty;
			$arr = isset( $o['rv_arrival_date'] ) && '' !== (string) $o['rv_arrival_date'] ? (string) $o['rv_arrival_date'] : '';
			$dep = isset( $o['rv_departure_date'] ) && '' !== (string) $o['rv_departure_date'] ? (string) $o['rv_departure_date'] : '';
			if ( '' !== $arr && ( null === $buckets[ $key ]['min_date'] || $arr < $buckets[ $key ]['min_date'] ) ) {
				$buckets[ $key ]['min_date'] = $arr;
			}
			if ( '' !== $dep && ( null === $buckets[ $key ]['max_date'] || $dep > $buckets[ $key ]['max_date'] ) ) {
				$buckets[ $key ]['max_date'] = $dep;
			}
		}

		$rows = array();
		foreach ( $buckets as $b ) {
			$rv_cap  = $b['rid'] > 0 ? $this->rv_capacity_for_reservation( $b['rid'] ) : 0;
			$pct     = $rv_cap > 0 ? round( $b['in_use'] / $rv_cap * 100, 1 ) . '%' : '—';
			$dates   = '';
			if ( null !== $b['min_date'] && null !== $b['max_date'] ) {
				$dates = date_i18n( 'M j', strtotime( $b['min_date'] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $b['max_date'] ) );
			}
			$rows[] = array(
				$b['title'],
				$dates,
				(string) $b['in_use'],
				$rv_cap > 0 ? (string) $rv_cap : '—',
				$pct,
			);
		}

		return array(
			'title'   => __( 'RV Occupancy', 'equine-event-manager' ),
			'slug'    => 'rv_occupancy',
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * RV Occupancy — per-day rows for a single reservation.
	 *
	 * @param array $filters Filters (reservation_id must be > 0).
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	private function rv_occupancy_daily( array $filters ): array {
		$rid      = $filters['reservation_id'];
		$capacity = $this->rv_capacity_for_reservation( $rid );

		$order_data = array();
		$min_date   = null;
		$max_date   = null;

		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$qty = absint( $o['rv_quantity'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			$arrival   = isset( $o['rv_arrival_date'] ) && '' !== (string) $o['rv_arrival_date']
				? (string) $o['rv_arrival_date'] : '';
			$departure = isset( $o['rv_departure_date'] ) && '' !== (string) $o['rv_departure_date']
				? (string) $o['rv_departure_date'] : '';

			if ( '' !== $arrival && ( null === $min_date || $arrival < $min_date ) ) {
				$min_date = $arrival;
			}
			if ( '' !== $departure && ( null === $max_date || $departure > $max_date ) ) {
				$max_date = $departure;
			}

			$order_data[] = array(
				'qty'       => $qty,
				'arrival'   => $arrival,
				'departure' => $departure,
			);
		}

		$res_title    = (string) get_the_title( $rid );
		$event_header = $res_title;
		if ( null !== $min_date && null !== $max_date ) {
			$event_header .= '  ·  ' . date_i18n( 'M j', strtotime( $min_date ) )
				. ' – ' . date_i18n( 'M j, Y', strtotime( $max_date ) );
		}

		$headers = array(
			__( 'Date', 'equine-event-manager' ),
			__( 'RV Spots In Use', 'equine-event-manager' ),
			__( 'RV Capacity', 'equine-event-manager' ),
			__( 'Occupancy %', 'equine-event-manager' ),
		);

		if ( null === $min_date || null === $max_date ) {
			return array(
				'title'        => __( 'RV Occupancy', 'equine-event-manager' ),
				'slug'         => 'rv_occupancy',
				'event_header' => $event_header,
				'headers'      => $headers,
				'rows'         => array(),
			);
		}

		$dates   = array();
		$current = new DateTime( $min_date );
		$end     = new DateTime( $max_date );
		while ( $current <= $end ) {
			$dates[] = $current->format( 'Y-m-d' );
			$current->modify( '+1 day' );
		}

		$rows      = array();
		$peak      = 0;
		$rv_nights = 0;

		foreach ( $dates as $date ) {
			$in_use = 0;
			foreach ( $order_data as $od ) {
				if (
					'' !== $od['arrival'] &&
					'' !== $od['departure'] &&
					$date >= $od['arrival'] &&
					$date <= $od['departure']
				) {
					$in_use += $od['qty'];
				}
			}

			$pct    = $capacity > 0 ? round( $in_use / $capacity * 100, 1 ) . '%' : '—';
			$rows[] = array(
				date_i18n( 'D, M j', strtotime( $date ) ),
				(string) $in_use,
				$capacity > 0 ? (string) $capacity : '—',
				$pct,
			);

			if ( $in_use > $peak ) {
				$peak = $in_use;
			}
			$rv_nights += $in_use;
		}

		$peak_pct   = $capacity > 0 ? round( $peak / $capacity * 100, 1 ) . '%' : '—';
		$totals_row = array(
			/* translators: %d: number of days. */
			sprintf( _n( 'TOTALS (%d day)', 'TOTALS (%d days)', count( $dates ), 'equine-event-manager' ), count( $dates ) ),
			/* translators: %d: peak RV spot count. */
			sprintf( __( 'Peak: %d', 'equine-event-manager' ), $peak ),
			$capacity > 0 ? (string) $capacity : '—',
			/* translators: %s: peak occupancy percentage. */
			sprintf( __( 'Peak: %s', 'equine-event-manager' ), $peak_pct ),
		);

		return array(
			'title'             => __( 'RV Occupancy', 'equine-event-manager' ),
			'slug'              => 'rv_occupancy',
			'event_header'      => $event_header,
			'headers'           => $headers,
			'rows'              => array_merge( array( $totals_row ), $rows ),
			'summary_row_count' => 1,
		);
	}

	/**
	 * Report — Shavings: per-order bedding worksheet, grouped by event.
	 *
	 * One row per order that carries any required or additional shavings, sorted
	 * by event then customer so the facility can pull a delivery list. A trailing
	 * subtotal row per event and a grand-total row give the per-event bag counts
	 * the barn needs for fulfillment. Orders with zero shavings are omitted.
	 *
	 * @param array $filters Filters.
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	public function shavings_report( array $filters ): array {
		$rid = absint( $filters['reservation_id'] ?? 0 );
		if ( $rid > 0 ) {
			return $this->shavings_daily( $filters );
		}
		return $this->shavings_summary( $filters );
	}

	/**
	 * Shavings — per-event summary row for "All reservations" view.
	 *
	 * @param array $filters Filters.
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	private function shavings_summary( array $filters ): array {
		$buckets = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$req = absint( $o['required_shavings_qty'] ?? 0 );
			$add = absint( $o['additional_shavings_qty'] ?? 0 );
			if ( $req <= 0 && $add <= 0 ) {
				continue;
			}
			$rid = absint( $o['reservation_id'] ?? 0 );
			$key = $rid > 0 ? (string) $rid : 'r:' . (string) ( $o['event_name'] ?? '' );
			if ( ! isset( $buckets[ $key ] ) ) {
				$title = $rid > 0 ? (string) get_the_title( $rid ) : (string) ( $o['event_name'] ?? '' );
				if ( '' === trim( $title ) ) {
					$title = __( '(Unlinked reservation)', 'equine-event-manager' );
				}
				$buckets[ $key ] = array( 'title' => $title, 'req' => 0, 'add' => 0 );
			}
			$buckets[ $key ]['req'] += $req;
			$buckets[ $key ]['add'] += $add;
		}

		$rows = array();
		foreach ( $buckets as $b ) {
			$rows[] = array(
				$b['title'],
				(string) $b['req'],
				(string) $b['add'],
				(string) ( $b['req'] + $b['add'] ),
			);
		}

		return array(
			'title'   => __( 'Shavings', 'equine-event-manager' ),
			'slug'    => 'shavings',
			'headers' => array(
				__( 'Reservation', 'equine-event-manager' ),
				__( 'Required Bags', 'equine-event-manager' ),
				__( 'Additional Bags', 'equine-event-manager' ),
				__( 'Total Bags', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Shavings — per-day rows for a single reservation.
	 *
	 * Each row = one calendar day within the event date range.
	 * Required bags = orders whose stall stay covers that day × bags_per_stall.
	 * Additional bags = orders whose stall stay covers that day × additional_shavings_qty.
	 * A navy TOTALS row is pinned at the top showing event totals.
	 *
	 * @param array $filters Filters (reservation_id must be > 0).
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	private function shavings_daily( array $filters ): array {
		global $wpdb;

		$rid        = $filters['reservation_id'];
		$order_data = array();
		$min_date   = null;
		$max_date   = null;

		// Per-type breakdown: sum qty per product name from additional_shavings_items JSON.
		$type_totals = array();
		$sr_table    = $wpdb->prefix . 'en_stall_reservations';
		$raw_items   = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT additional_shavings_items FROM {$sr_table} WHERE reservation_id = %d AND additional_shavings_items IS NOT NULL AND additional_shavings_items != ''",
			$rid
		) );
		foreach ( $raw_items as $json ) {
			$items = json_decode( (string) $json, true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$name = isset( $item['name'] ) ? (string) $item['name'] : '';
				$qty  = isset( $item['qty'] ) ? absint( $item['qty'] ) : 0;
				if ( '' !== $name && $qty > 0 ) {
					$type_totals[ $name ] = ( $type_totals[ $name ] ?? 0 ) + $qty;
				}
			}
		}

		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$req = absint( $o['required_shavings_qty'] ?? 0 );
			$add = absint( $o['additional_shavings_qty'] ?? 0 );
			if ( $req <= 0 && $add <= 0 ) {
				continue;
			}
			$arrival   = isset( $o['stall_arrival_date'] ) && '' !== (string) $o['stall_arrival_date']
				? (string) $o['stall_arrival_date'] : '';
			$departure = isset( $o['stall_departure_date'] ) && '' !== (string) $o['stall_departure_date']
				? (string) $o['stall_departure_date'] : '';

			if ( '' !== $arrival && ( null === $min_date || $arrival < $min_date ) ) {
				$min_date = $arrival;
			}
			if ( '' !== $departure && ( null === $max_date || $departure > $max_date ) ) {
				$max_date = $departure;
			}

			$order_data[] = array(
				'req'       => $req,
				'add'       => $add,
				'arrival'   => $arrival,
				'departure' => $departure,
			);
		}

		$res_title    = (string) get_the_title( $rid );
		$event_header = $res_title;
		if ( null !== $min_date && null !== $max_date ) {
			$event_header .= '  ·  ' . date_i18n( 'M j', strtotime( $min_date ) )
				. ' – ' . date_i18n( 'M j, Y', strtotime( $max_date ) );
		}

		$headers = array(
			__( 'Date', 'equine-event-manager' ),
			__( 'Required Bags', 'equine-event-manager' ),
			__( 'Additional Bags', 'equine-event-manager' ),
			__( 'Total Bags', 'equine-event-manager' ),
		);

		if ( null === $min_date || null === $max_date ) {
			return array(
				'title'        => __( 'Shavings', 'equine-event-manager' ),
				'slug'         => 'shavings',
				'event_header' => $event_header,
				'headers'      => $headers,
				'rows'         => array(),
			);
		}

		$dates   = array();
		$current = new DateTime( $min_date );
		$end     = new DateTime( $max_date );
		while ( $current <= $end ) {
			$dates[] = $current->format( 'Y-m-d' );
			$current->modify( '+1 day' );
		}

		$rows          = array();
		$total_req     = 0;
		$total_add     = 0;

		foreach ( $dates as $date ) {
			$day_req = 0;
			$day_add = 0;
			foreach ( $order_data as $od ) {
				if (
					'' !== $od['arrival'] &&
					'' !== $od['departure'] &&
					$date >= $od['arrival'] &&
					$date <= $od['departure']
				) {
					$day_req += $od['req'];
					$day_add += $od['add'];
				}
			}
			$rows[] = array(
				date_i18n( 'D, M j', strtotime( $date ) ),
				(string) $day_req,
				(string) $day_add,
				(string) ( $day_req + $day_add ),
			);
			$total_req += $day_req;
			$total_add += $day_add;
		}

		$totals_row = array(
			/* translators: %d: number of days. */
			sprintf( _n( 'TOTALS (%d day)', 'TOTALS (%d days)', count( $dates ), 'equine-event-manager' ), count( $dates ) ),
			(string) $total_req,
			(string) $total_add,
			(string) ( $total_req + $total_add ),
		);

		// Build per-type note section if any additional shavings by type were sold.
		$note_sections = array();
		if ( ! empty( $type_totals ) ) {
			arsort( $type_totals );
			$type_rows = array();
			foreach ( $type_totals as $name => $qty ) {
				$type_rows[] = array( $name, (string) $qty );
			}
			$note_sections[] = array(
				'label'   => __( 'Additional Shavings — By Type', 'equine-event-manager' ),
				'headers' => array(
					__( 'Product', 'equine-event-manager' ),
					__( 'Bags Sold', 'equine-event-manager' ),
				),
				'rows'    => $type_rows,
			);
		}

		return array(
			'title'             => __( 'Shavings', 'equine-event-manager' ),
			'slug'              => 'shavings',
			'event_header'      => $event_header,
			'headers'           => $headers,
			'rows'              => array_merge( array( $totals_row ), $rows ),
			'summary_row_count' => 1,
			'note_sections'     => $note_sections,
		);
	}

	/**
	 * Parse general add-on quantities from an order's notes.
	 *
	 * Checkout writes one "Add-On: NAME | Qty: N | Per: … | Subtotal: $X" line per
	 * selected general add-on. This sums quantity per add-on name for one order.
	 *
	 * @param string $notes Raw order notes.
	 * @return array<string,int> Map of add-on name => total quantity.
	 */
	private function parse_addons_from_notes( string $notes ): array {
		$out = array();
		if ( preg_match_all( '/(?:^|\n)Add-On:\s*(.+?)\s*\|\s*Qty:\s*(\d+)/mi', $notes, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = trim( (string) $match[1] );
				$qty  = absint( $match[2] );
				if ( '' === $name || $qty <= 0 ) {
					continue;
				}
				$out[ $name ] = ( $out[ $name ] ?? 0 ) + $qty;
			}
		}
		return $out;
	}

	/**
	 * Add-Ons report — per-event summary across all reservations, or per-day
	 * quantities for a single reservation. Mirrors the Shavings report shape.
	 *
	 * @param array $filters Filters.
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	public function addons_report( array $filters ): array {
		$rid = absint( $filters['reservation_id'] ?? 0 );
		return $rid > 0 ? $this->addons_daily( $filters ) : $this->addons_summary( $filters );
	}

	/**
	 * Add-Ons — per-event summary row for the "All reservations" view.
	 *
	 * @param array $filters Filters.
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	private function addons_summary( array $filters ): array {
		$buckets = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$units = array_sum( $this->parse_addons_from_notes( (string) ( $o['notes'] ?? '' ) ) );
			if ( $units <= 0 ) {
				continue;
			}
			$rid = absint( $o['reservation_id'] ?? 0 );
			$key = $rid > 0 ? (string) $rid : 'r:' . (string) ( $o['event_name'] ?? '' );
			if ( ! isset( $buckets[ $key ] ) ) {
				$title = $rid > 0 ? (string) get_the_title( $rid ) : (string) ( $o['event_name'] ?? '' );
				if ( '' === trim( $title ) ) {
					$title = __( '(Unlinked reservation)', 'equine-event-manager' );
				}
				$buckets[ $key ] = array( 'title' => $title, 'units' => 0 );
			}
			$buckets[ $key ]['units'] += $units;
		}

		$rows = array();
		foreach ( $buckets as $b ) {
			$rows[] = array( $b['title'], (string) $b['units'] );
		}

		return array(
			'title'   => __( 'Add-Ons', 'equine-event-manager' ),
			'slug'    => 'addons',
			'headers' => array(
				__( 'Reservation', 'equine-event-manager' ),
				__( 'Add-On Units', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Add-Ons — per-day quantities for a single reservation.
	 *
	 * Each row = one calendar day in the event range. Columns are dynamic, one per
	 * distinct general add-on, plus a Total. An add-on's quantity is counted on
	 * EVERY day of its order's stay (per Whitney's spec). A navy TOTALS row pins
	 * the column sums at the top; a note section lists total quantity purchased per
	 * add-on (the actual order count, not day-multiplied).
	 *
	 * @param array $filters Filters (reservation_id must be > 0).
	 * @return array{title:string,slug:string,headers:array,rows:array}
	 */
	private function addons_daily( array $filters ): array {
		$rid              = absint( $filters['reservation_id'] );
		$order_data       = array();
		$types            = array();
		$purchased_totals = array();
		$min_date         = null;
		$max_date         = null;

		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$addons = $this->parse_addons_from_notes( (string) ( $o['notes'] ?? '' ) );
			if ( empty( $addons ) ) {
				continue;
			}
			$arrival   = (string) ( $o['stall_arrival_date'] ?? '' );
			$departure = (string) ( $o['stall_departure_date'] ?? '' );
			if ( '' === $arrival ) {
				$arrival = (string) ( $o['rv_arrival_date'] ?? '' );
			}
			if ( '' === $departure ) {
				$departure = (string) ( $o['rv_departure_date'] ?? '' );
			}
			if ( '' !== $arrival && ( null === $min_date || $arrival < $min_date ) ) {
				$min_date = $arrival;
			}
			if ( '' !== $departure && ( null === $max_date || $departure > $max_date ) ) {
				$max_date = $departure;
			}
			foreach ( $addons as $name => $qty ) {
				$types[ $name ]            = true;
				$purchased_totals[ $name ] = ( $purchased_totals[ $name ] ?? 0 ) + $qty;
			}
			$order_data[] = array(
				'addons'    => $addons,
				'arrival'   => $arrival,
				'departure' => $departure,
			);
		}

		ksort( $types );
		$type_names = array_keys( $types );

		$res_title    = (string) get_the_title( $rid );
		$event_header = $res_title;
		if ( null !== $min_date && null !== $max_date ) {
			$event_header .= '  ·  ' . date_i18n( 'M j', strtotime( $min_date ) )
				. ' – ' . date_i18n( 'M j, Y', strtotime( $max_date ) );
		}

		$headers = array_merge(
			array( __( 'Date', 'equine-event-manager' ) ),
			$type_names,
			array( __( 'Total', 'equine-event-manager' ) )
		);

		if ( null === $min_date || null === $max_date || empty( $type_names ) ) {
			return array(
				'title'        => __( 'Add-Ons', 'equine-event-manager' ),
				'slug'         => 'addons',
				'event_header' => $event_header,
				'headers'      => $headers,
				'rows'         => array(),
			);
		}

		$dates   = array();
		$current = new DateTime( $min_date );
		$end     = new DateTime( $max_date );
		while ( $current <= $end ) {
			$dates[] = $current->format( 'Y-m-d' );
			$current->modify( '+1 day' );
		}

		$rows        = array();
		$col_totals  = array_fill_keys( $type_names, 0 );
		$grand_total = 0;
		foreach ( $dates as $date ) {
			$day = array_fill_keys( $type_names, 0 );
			foreach ( $order_data as $od ) {
				if (
					'' !== $od['arrival'] &&
					'' !== $od['departure'] &&
					$date >= $od['arrival'] &&
					$date <= $od['departure']
				) {
					foreach ( $od['addons'] as $name => $qty ) {
						$day[ $name ] += $qty;
					}
				}
			}
			$row       = array( date_i18n( 'D, M j', strtotime( $date ) ) );
			$day_total = 0;
			foreach ( $type_names as $name ) {
				$row[]               = (string) $day[ $name ];
				$col_totals[ $name ] += $day[ $name ];
				$day_total           += $day[ $name ];
			}
			$row[]        = (string) $day_total;
			$grand_total += $day_total;
			$rows[]       = $row;
		}

		$totals_row = array(
			/* translators: %d: number of days. */
			sprintf( _n( 'TOTALS (%d day)', 'TOTALS (%d days)', count( $dates ), 'equine-event-manager' ), count( $dates ) ),
		);
		foreach ( $type_names as $name ) {
			$totals_row[] = (string) $col_totals[ $name ];
		}
		$totals_row[] = (string) $grand_total;

		$note_sections = array();
		if ( ! empty( $purchased_totals ) ) {
			arsort( $purchased_totals );
			$type_rows = array();
			foreach ( $purchased_totals as $name => $qty ) {
				$type_rows[] = array( $name, (string) $qty );
			}
			$note_sections[] = array(
				'label'   => __( 'Add-Ons — Total Purchased', 'equine-event-manager' ),
				'headers' => array(
					__( 'Add-On', 'equine-event-manager' ),
					__( 'Quantity', 'equine-event-manager' ),
				),
				'rows'    => $type_rows,
			);
		}

		return array(
			'title'             => __( 'Add-Ons', 'equine-event-manager' ),
			'slug'              => 'addons',
			'event_header'      => $event_header,
			'headers'           => $headers,
			'rows'              => array_merge( array( $totals_row ), $rows ),
			'summary_row_count' => 1,
			'note_sections'     => $note_sections,
		);
	}

	/**
	 * Report 5 — Customer List: per-customer (by email) contact + order count + lifetime value.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function customer_list_report( array $filters ): array {
		$agg = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$email = strtolower( trim( (string) ( $o['email'] ?? '' ) ) );
			$key   = '' !== $email ? $email : 'name:' . strtolower( trim( (string) ( $o['customer_name'] ?? '' ) ) );
			if ( '' === trim( $key ) ) {
				continue;
			}
			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = array(
					'name'   => (string) ( $o['customer_name'] ?? '' ),
					'email'  => (string) ( $o['email'] ?? '' ),
					'phone'  => (string) ( $o['phone'] ?? '' ),
					'orders' => 0,
					'ltv'    => 0.0,
				);
			}
			$agg[ $key ]['orders']++;
			$agg[ $key ]['ltv'] += (float) ( $o['total'] ?? 0 );
		}

		$rows = array();
		foreach ( $agg as $c ) {
			$rows[] = array(
				$c['name'],
				$c['email'],
				$c['phone'],
				(string) $c['orders'],
				$this->money( $c['ltv'] ),
			);
		}

		return array(
			'title'   => __( 'Customer List', 'equine-event-manager' ),
			'slug'    => 'customer_list',
			'headers' => array(
				__( 'Customer', 'equine-event-manager' ),
				__( 'Email', 'equine-event-manager' ),
				__( 'Phone', 'equine-event-manager' ),
				__( 'Orders', 'equine-event-manager' ),
				__( 'Lifetime Value', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Report 6 — Refund Log: per refunded component/order.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function refund_log_report( array $filters ): array {
		$rows = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$components = isset( $o['components'] ) && is_array( $o['components'] ) ? $o['components'] : array();
			foreach ( $components as $comp ) {
				$notes  = (string) ( $comp['notes'] ?? '' );
				$amount = $this->note_value( $notes, 'Refunded Amount' );
				$has_tx = ! empty( $comp['refund_transaction_id'] );
				if ( '' === $amount && ! $has_tx && 'refunded' !== ( $comp['payment_status'] ?? '' ) ) {
					continue;
				}
				$amount_val = '' !== $amount ? (float) preg_replace( '/[^0-9.\-]/', '', $amount ) : (float) ( $comp['total'] ?? 0 );
				$rows[]     = array(
					EEM_Formatter::format_order_number( $o['order_number'] ),
					substr( (string) ( $comp['refunded_at'] ?? ( $o['created_at'] ?? '' ) ), 0, 10 ),
					(string) ( $o['reservation_title'] ?? ( $o['event_name'] ?? '' ) ),
					(string) ( $o['customer_name'] ?? '' ),
					strtoupper( (string) ( $comp['table'] ?? '' ) ),
					$this->money( $amount_val ),
					$this->note_value( $notes, 'Refund Reason' ),
					(string) ( $comp['refund_transaction_id'] ?? '' ),
				);
			}
		}

		return array(
			'title'   => __( 'Refund Log', 'equine-event-manager' ),
			'slug'    => 'refund_log',
			'headers' => array(
				__( 'Order #', 'equine-event-manager' ),
				__( 'Refunded On', 'equine-event-manager' ),
				__( 'Reservation', 'equine-event-manager' ),
				__( 'Customer', 'equine-event-manager' ),
				__( 'Section', 'equine-event-manager' ),
				__( 'Refund Amount', 'equine-event-manager' ),
				__( 'Reason', 'equine-event-manager' ),
				__( 'Transaction', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Report 7 — Cleaning: stalls + RV lots currently flagged "needs cleaning",
	 * grouped by barn/zone with a per-group count and a grand total. A turnover
	 * worksheet for facilities staff — they print it and walk the rows. Only
	 * units needing cleaning are listed (not the whole inventory).
	 *
	 * @param array $filters Filters (only reservation_id applies — cleaning is
	 *                       current-state, so date/status filters are ignored).
	 * @return array{title:string,slug:string,headers:array,rows:array,groups:array,total_label:string}
	 */
	public function cleaning_report( array $filters ): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'eem_stall_status';
		$rid     = absint( $filters['reservation_id'] ?? 0 );
		$all     = ( 0 === $rid );

		// Distinct units with at least one night flagged needs_cleaning.
		if ( $all ) {
			$found = $wpdb->get_results( "SELECT DISTINCT reservation_id, stall_unit FROM {$table} WHERE status = 'needs_cleaning'", ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT reservation_id, stall_unit FROM {$table} WHERE status = 'needs_cleaning' AND reservation_id = %d", $rid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}

		// Bucket units by reservation so each reservation's location map is built once.
		$by_res = array();
		foreach ( (array) $found as $r ) {
			$by_res[ (int) $r['reservation_id'] ][] = (string) $r['stall_unit'];
		}

		$admin       = class_exists( 'EEM_Admin' ) ? new EEM_Admin( true ) : null;
		$status_lbl  = __( 'Needs Cleaning', 'equine-event-manager' );
		$groups      = array();
		$rows        = array();
		$grand_total = 0;

		foreach ( $by_res as $res_id => $units ) {
			$maps       = $admin ? $admin->get_unit_location_maps( $res_id ) : array( 'stall_barn' => array(), 'rv_zone' => array() );
			$stall_barn = (array) ( $maps['stall_barn'] ?? array() );
			$rv_zone    = (array) ( $maps['rv_zone'] ?? array() );
			$res_title  = get_the_title( $res_id );

			// Resolve each unit's barn/zone, then group.
			$res_groups = array();
			foreach ( $units as $unit ) {
				if ( isset( $stall_barn[ $unit ] ) && '' !== $stall_barn[ $unit ] ) {
					$loc = $stall_barn[ $unit ];
				} elseif ( isset( $rv_zone[ $unit ] ) && '' !== $rv_zone[ $unit ] ) {
					$loc = $rv_zone[ $unit ];
				} else {
					$loc = __( 'Unassigned', 'equine-event-manager' );
				}
				$res_groups[ $loc ][] = $unit;
			}

			ksort( $res_groups, SORT_NATURAL | SORT_FLAG_CASE );
			foreach ( $res_groups as $loc => $loc_units ) {
				natsort( $loc_units );
				$loc_units = array_values( $loc_units );
				$label     = $all ? $res_title . ' — ' . $loc : $loc;

				$group_rows = array();
				foreach ( $loc_units as $unit ) {
					$row          = array( $unit, $loc, $status_lbl );
					$rows[]       = $row;
					$group_rows[] = $row;
				}
				$grand_total += count( $loc_units );
				$groups[]     = array( 'label' => $label, 'count' => count( $loc_units ), 'rows' => $group_rows );
			}
		}

		return array(
			'title'       => __( 'Facility Cleaning', 'equine-event-manager' ),
			'slug'        => 'cleaning',
			'headers'     => array(
				__( 'Stall / Lot', 'equine-event-manager' ),
				__( 'Barn / Zone', 'equine-event-manager' ),
				__( 'Status', 'equine-event-manager' ),
			),
			'rows'        => $rows,
			'groups'      => $groups,
			/* translators: %s: number of units needing cleaning. */
			'total_label' => sprintf( _n( '%s unit needs cleaning', '%s units need cleaning', $grand_total, 'equine-event-manager' ), number_format_i18n( $grand_total ) ),
		);
	}

	/**
	 * Sum the refunded amount across an order's components.
	 *
	 * @param array $order Grouped order.
	 * @return float
	 */
	private function order_refunded_amount( array $order ): float {
		$total      = 0.0;
		$components = isset( $order['components'] ) && is_array( $order['components'] ) ? $order['components'] : array();
		foreach ( $components as $comp ) {
			$amount = $this->note_value( (string) ( $comp['notes'] ?? '' ), 'Refunded Amount' );
			if ( '' !== $amount ) {
				$total += max( 0, (float) preg_replace( '/[^0-9.\-]/', '', $amount ) );
			} elseif ( 'refunded' === ( $comp['payment_status'] ?? '' ) ) {
				$total += max( 0, (float) ( $comp['total'] ?? 0 ) );
			}
		}
		return $total;
	}

	/**
	 * Read a single-line "Label: value" from order notes.
	 *
	 * @param string $notes Notes blob.
	 * @param string $label Label to find.
	 * @return string Value, or ''.
	 */
	private function note_value( string $notes, string $label ): string {
		if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/im', $notes, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Best-effort stall capacity for a reservation (from inventory meta,
	 * else expanded stall-row count).
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return int Capacity, or 0 when unknown/unlimited.
	 */
	private function stall_capacity_for_reservation( int $reservation_id ): int {
		$cfg       = EEM_Reservation_Config::for( $reservation_id );
		$inventory = $cfg->get( 'stall_inventory', '' );
		if ( '' !== $inventory && is_numeric( $inventory ) ) {
			return absint( $inventory );
		}
		$rows = $cfg->get( 'stall_rows', array() );
		return is_array( $rows ) ? $this->count_units_in_rows( $rows ) : 0;
	}

	/**
	 * Best-effort RV capacity for a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return int
	 */
	private function rv_capacity_for_reservation( int $reservation_id ): int {
		$cfg       = EEM_Reservation_Config::for( $reservation_id );
		$inventory = $cfg->get( 'rv_inventory', '' );
		if ( '' !== $inventory && is_numeric( $inventory ) ) {
			return absint( $inventory );
		}
		$rows = $cfg->get( 'rv_rows', array() );
		return is_array( $rows ) ? $this->count_units_in_rows( $rows ) : 0;
	}

	/**
	 * Roughly count the stall/RV units defined across builder rows.
	 *
	 * Each row defines a strip; one-sided rows have first/last, back-to-back rows
	 * have top + bottom blocks. Numeric ranges are counted by span; non-numeric
	 * (prefixed) labels count as 1 conservatively to avoid over/under-stating.
	 *
	 * @param array $rows Stall/RV builder rows.
	 * @return int
	 */
	private function count_units_in_rows( array $rows ): int {
		$count = 0;
		$span  = static function ( $a, $b ) {
			$a = is_numeric( $a ) ? (int) $a : null;
			$b = is_numeric( $b ) ? (int) $b : null;
			if ( null !== $a && null !== $b ) {
				return abs( $b - $a ) + 1;
			}
			return ( '' !== (string) $a || '' !== (string) $b ) ? 1 : 0;
		};
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'back-to-back' === ( $row['layout'] ?? '' ) ) {
				$count += $span( $row['top_first'] ?? '', $row['top_last'] ?? '' );
				$count += $span( $row['bot_first'] ?? '', $row['bot_last'] ?? '' );
			} else {
				$count += $span( $row['first'] ?? '', $row['last'] ?? '' );
			}
		}
		return $count;
	}
}
