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
	const REPORTS = array( 'orders', 'reservations', 'revenue', 'stall_occupancy', 'customer_list', 'refund_log' );

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
		$valid_date = static function ( $v ) {
			$v = is_string( $v ) ? trim( $v ) : '';
			return ( '' !== $v && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) ? $v : '';
		};

		return array(
			'reservation_id' => isset( $filters['reservation_id'] ) ? absint( $filters['reservation_id'] ) : 0,
			'date_from'      => isset( $filters['date_from'] ) ? $valid_date( $filters['date_from'] ) : '',
			'date_to'        => isset( $filters['date_to'] ) ? $valid_date( $filters['date_to'] ) : '',
			'status'         => isset( $filters['status'] ) ? sanitize_key( $filters['status'] ) : '',
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
			if ( ! $this->order_in_date_range( $order, $filters['date_from'], $filters['date_to'] ) ) {
				continue;
			}
			if ( '' !== $filters['status'] && 'all' !== $filters['status'] && ! $this->order_matches_status( $order, $filters['status'] ) ) {
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
			case 'customer_list':
				return $this->customer_list_report( $filters );
			case 'refund_log':
				return $this->refund_log_report( $filters );
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
		$rows = array();
		foreach ( $this->get_filtered_orders( $filters ) as $o ) {
			$rows[] = array(
				sprintf( '#%05d', absint( $o['order_number'] ) ),
				(string) ( $o['event_name'] ?? '' ),
				(string) ( $o['event_dates'] ?? '' ),
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
		}

		return array(
			'title'   => __( 'Orders', 'equine-event-manager' ),
			'slug'    => 'orders',
			'headers' => array(
				__( 'Order #', 'equine-event-manager' ),
				__( 'Event', 'equine-event-manager' ),
				__( 'Event Dates', 'equine-event-manager' ),
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
			'rows'    => $rows,
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
				sprintf( '#%05d', absint( $o['order_number'] ) ),
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
					sprintf( '#%05d', absint( $o['order_number'] ) ),
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
		$inventory = get_post_meta( $reservation_id, '_en_stall_inventory', true );
		if ( '' !== $inventory && is_numeric( $inventory ) ) {
			return absint( $inventory );
		}
		$rows = get_post_meta( $reservation_id, '_en_stall_rows', true );
		return is_array( $rows ) ? $this->count_units_in_rows( $rows ) : 0;
	}

	/**
	 * Best-effort RV capacity for a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return int
	 */
	private function rv_capacity_for_reservation( int $reservation_id ): int {
		$inventory = get_post_meta( $reservation_id, '_en_rv_inventory', true );
		if ( '' !== $inventory && is_numeric( $inventory ) ) {
			return absint( $inventory );
		}
		$rows = get_post_meta( $reservation_id, '_en_rv_rows', true );
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
