<?php
/**
 * Customer Profile data repository (C9.A).
 *
 * The plugin has no first-class "customer" entity — customer data lives inside
 * orders, keyed by email (decision: read-only aggregate model, 2026-06-01). This
 * repo aggregates all of a customer's grouped orders (via the canonical
 * EEM_Orders_Repository) into the payload the Customer Profile admin page renders
 * against `.mockups/customer_profile_page.html`: identity + contact, KPI stats,
 * order history, reservation history, and a merged activity timeline.
 *
 * Internal admin notes (the only writable surface) are stored in a single
 * `eem_customer_notes` option map keyed by a hash of the lower-cased email, so no
 * schema change is needed.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates orders by email into a Customer Profile payload.
 */
class EEM_Customer_Profile_Repo {

	/**
	 * Option name holding the per-customer internal-notes map.
	 */
	const NOTES_OPTION = 'eem_customer_notes';

	/**
	 * Statuses that count as collected revenue toward lifetime spend / paid count.
	 *
	 * @var string[]
	 */
	private array $paid_statuses = array( 'paid', 'partially-paid' );

	/**
	 * Canonical orders source.
	 *
	 * @var EEM_Orders_Repository
	 */
	private EEM_Orders_Repository $orders;

	public function __construct() {
		$this->orders = new EEM_Orders_Repository();
	}

	/**
	 * Stable storage key for a customer email (lower-cased + trimmed, hashed).
	 *
	 * @param string $email
	 * @return string
	 */
	public static function email_key( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	/**
	 * All grouped orders belonging to one email (newest first), or empty.
	 *
	 * @param string $email
	 * @return array<int, array<string, mixed>>
	 */
	private function orders_for_email( string $email ): array {
		$needle = strtolower( trim( $email ) );
		if ( '' === $needle ) {
			return array();
		}
		$matched = array();
		foreach ( $this->orders->get_orders( '', 'date', 'desc' ) as $o ) {
			if ( strtolower( trim( (string) ( $o['email'] ?? '' ) ) ) === $needle ) {
				$matched[] = $o;
			}
		}
		return $matched;
	}

	/**
	 * Whether any order exists for this email.
	 *
	 * @param string $email
	 * @return bool
	 */
	public function exists( string $email ): bool {
		return ! empty( $this->orders_for_email( $email ) );
	}

	/**
	 * Build the full Customer Profile payload for an email.
	 *
	 * @param string $email
	 * @return array<string, mixed>|null Null when the customer has no orders.
	 */
	public function get_profile( string $email ): ?array {
		$orders = $this->orders_for_email( $email );
		if ( empty( $orders ) ) {
			return null;
		}

		// Identity comes from the most-recent order (orders are newest-first).
		$latest = $orders[0];
		$name   = trim( (string) ( $latest['customer_name'] ?? '' ) );
		$phone  = trim( (string) ( $latest['phone'] ?? '' ) );

		return array(
			'email'          => trim( $email ),
			'name'           => '' !== $name ? $name : trim( $email ),
			'phone'          => $phone,
			'billing'        => $this->parse_billing( (string) ( $latest['notes'] ?? '' ), $name ),
			'customer_since' => $this->customer_since( $orders ),
			'stats'          => $this->stats( $orders ),
			'orders'         => $this->order_rows( $orders ),
			'reservations'   => $this->reservation_rows( $orders ),
			'activity'       => $this->activity( $orders ),
			'note'           => $this->get_note( $email ),
		);
	}

	/**
	 * KPI stats: lifetime spend, order counts, average value, last order.
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @return array<string, mixed>
	 */
	private function stats( array $orders ): array {
		$total_orders = count( $orders );
		$spend        = 0.0;
		$gross        = 0.0;
		$paid_count   = 0;
		$last_ts      = 0;

		foreach ( $orders as $o ) {
			$amt  = (float) ( $o['total'] ?? 0 );
			$gross += $amt;
			if ( in_array( (string) ( $o['status_slug'] ?? '' ), $this->paid_statuses, true ) ) {
				$spend += $amt;
				$paid_count++;
			}
			$ts = ! empty( $o['created_at'] ) ? (int) strtotime( (string) $o['created_at'] ) : 0;
			if ( $ts > $last_ts ) {
				$last_ts = $ts;
			}
		}

		$unpaid_count = max( 0, $total_orders - $paid_count );
		$avg          = $total_orders > 0 ? $gross / $total_orders : 0.0;

		return array(
			'lifetime_spend'    => $this->money( $spend ),
			'orders_count'      => $total_orders,
			'paid_count'        => $paid_count,
			'unpaid_count'      => $unpaid_count,
			'avg_order_value'   => $this->money( $avg ),
			'last_order_date'   => $last_ts ? date_i18n( 'M j, Y', $last_ts ) : '—',
			'last_order_rel'    => $last_ts ? $this->relative_days( $last_ts ) : '',
		);
	}

	/**
	 * Order-history rows for the Order History table.
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @return array<int, array<string, mixed>>
	 */
	private function order_rows( array $orders ): array {
		$rows = array();
		foreach ( $orders as $o ) {
			$ts     = ! empty( $o['created_at'] ) ? (int) strtotime( (string) $o['created_at'] ) : 0;
			$slug   = (string) ( $o['status_slug'] ?? '' );
			$rows[] = array(
				'order_number' => (string) ( $o['order_number'] ?? '' ),
				'order_key'    => (string) ( $o['order_key'] ?? '' ),
				'event_name'   => (string) ( $o['reservation_title'] ?? ( $o['event_name'] ?? '' ) ),
				'reservation_id' => (int) ( $o['reservation_id'] ?? 0 ),
				'type_labels'  => $this->type_labels( $o ),
				'status_slug'  => $slug,
				'status_label' => (string) ( $o['status_label'] ?? '' ),
				'date'         => $ts ? date_i18n( 'M j, Y', $ts ) : '',
				'date_ts'      => $ts,
				'total'        => $this->money( (float) ( $o['total'] ?? 0 ) ),
				'can_collect'  => in_array( $slug, array( 'unpaid', 'invoice-sent' ), true ),
			);
		}
		return $rows;
	}

	/**
	 * Reservation-history rows: orders grouped by reservation.
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @return array<int, array<string, mixed>>
	 */
	private function reservation_rows( array $orders ): array {
		$by_res = array();
		foreach ( $orders as $o ) {
			$rid = (int) ( $o['reservation_id'] ?? 0 );
			$key = $rid > 0 ? (string) $rid : ( 'name:' . (string) ( $o['event_name'] ?? '' ) );
			if ( ! isset( $by_res[ $key ] ) ) {
				$by_res[ $key ] = array(
					'reservation_id' => $rid,
					'event_name'     => (string) ( $o['reservation_title'] ?? ( $o['event_name'] ?? '' ) ),
					'event_dates'    => (string) ( $o['event_dates'] ?? '' ),
					'type_labels'    => array(),
					'orders'         => 0,
					'total_raw'      => 0.0,
					'first_ts'       => PHP_INT_MAX,
				);
			}
			$by_res[ $key ]['orders']++;
			$by_res[ $key ]['total_raw'] += (float) ( $o['total'] ?? 0 );
			foreach ( $this->type_labels( $o ) as $slug => $label ) {
				$by_res[ $key ]['type_labels'][ $slug ] = $label;
			}
			$ts = ! empty( $o['created_at'] ) ? (int) strtotime( (string) $o['created_at'] ) : 0;
			if ( $ts > 0 && $ts < $by_res[ $key ]['first_ts'] ) {
				$by_res[ $key ]['first_ts'] = $ts;
			}
		}

		// Newest reservation first (by earliest order in each).
		uasort(
			$by_res,
			static function ( $a, $b ) {
				return $b['first_ts'] <=> $a['first_ts'];
			}
		);

		$rows = array();
		foreach ( $by_res as $r ) {
			$rows[] = array(
				'reservation_id' => $r['reservation_id'],
				'event_name'     => $r['event_name'],
				'event_dates'    => $r['event_dates'],
				'type_labels'    => $r['type_labels'],
				'orders'         => $r['orders'],
				'total'          => $this->money( $r['total_raw'] ),
			);
		}
		return $rows;
	}

	/**
	 * Merged activity timeline across all the customer's orders (newest first).
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @param int                              $limit
	 * @return array<int, array<string, mixed>>
	 */
	private function activity( array $orders, int $limit = 20 ): array {
		if ( ! class_exists( 'EEM_Activity_Log' ) ) {
			return array();
		}
		$rows = array();
		foreach ( $orders as $o ) {
			$key = (string) ( $o['order_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			foreach ( EEM_Activity_Log::get_for_order_key( $key ) as $row ) {
				$row['order_number'] = (string) ( $o['order_number'] ?? '' );
				$rows[] = $row;
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Normalise an order's `type_labels` map (slug => label), defaulting Stall.
	 *
	 * @param array<string, mixed> $order
	 * @return array<string, string>
	 */
	private function type_labels( array $order ): array {
		$labels = isset( $order['type_labels'] ) && is_array( $order['type_labels'] ) ? $order['type_labels'] : array();
		$out    = array();
		foreach ( $labels as $slug => $label ) {
			$out[ (string) $slug ] = (string) $label;
		}
		return $out;
	}

	/**
	 * "Customer since {Month Year}" derived from the earliest order.
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @return string
	 */
	private function customer_since( array $orders ): string {
		$earliest = 0;
		foreach ( $orders as $o ) {
			$ts = ! empty( $o['created_at'] ) ? (int) strtotime( (string) $o['created_at'] ) : 0;
			if ( $ts > 0 && ( 0 === $earliest || $ts < $earliest ) ) {
				$earliest = $ts;
			}
		}
		return $earliest ? date_i18n( 'F Y', $earliest ) : '';
	}

	/**
	 * Parse "Billing Name" / "Billing Address" lines out of an order's notes.
	 *
	 * @param string $notes
	 * @param string $fallback_name
	 * @return array{name:string, lines:array<int,string>}
	 */
	private function parse_billing( string $notes, string $fallback_name ): array {
		$name = $fallback_name;
		if ( preg_match( '/^Billing Name:\s*(.+)$/mi', $notes, $m ) ) {
			$name = trim( $m[1] );
		}
		$lines = array();
		if ( preg_match( '/^Billing Address:\s*(.+)$/mi', $notes, $m ) ) {
			$raw = trim( $m[1] );
			// Address may be comma-separated on one line.
			foreach ( preg_split( '/\s*,\s*/', $raw ) as $part ) {
				$part = trim( (string) $part );
				if ( '' !== $part ) {
					$lines[] = $part;
				}
			}
		}
		return array( 'name' => $name, 'lines' => $lines );
	}

	/**
	 * "today" / "yesterday" / "N days ago" for the Last Order stat.
	 *
	 * @param int $ts
	 * @return string
	 */
	private function relative_days( int $ts ): string {
		$days = (int) floor( ( strtotime( 'today' ) - strtotime( date( 'Y-m-d', $ts ) ) ) / DAY_IN_SECONDS );
		if ( $days <= 0 ) {
			return __( 'today', 'equine-event-manager' );
		}
		if ( 1 === $days ) {
			return __( 'yesterday', 'equine-event-manager' );
		}
		/* translators: %d: number of days */
		return sprintf( _n( '%d day ago', '%d days ago', $days, 'equine-event-manager' ), $days );
	}

	/**
	 * Format a money amount as "$1,234.56".
	 *
	 * @param float $amount
	 * @return string
	 */
	private function money( float $amount ): string {
		return '$' . number_format_i18n( $amount, 2 );
	}

	// ── Internal notes (option-map storage) ─────────────────────────────────

	/**
	 * Read the internal note for a customer email.
	 *
	 * @param string $email
	 * @return string
	 */
	public function get_note( string $email ): string {
		$map = get_option( self::NOTES_OPTION, array() );
		$key = self::email_key( $email );
		return is_array( $map ) && isset( $map[ $key ] ) ? (string) $map[ $key ] : '';
	}

	/**
	 * Persist the internal note for a customer email.
	 *
	 * @param string $email
	 * @param string $note
	 * @return bool
	 */
	public function save_note( string $email, string $note ): bool {
		$map = get_option( self::NOTES_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$key  = self::email_key( $email );
		$note = trim( wp_kses_post( $note ) );
		if ( '' === $note ) {
			unset( $map[ $key ] );
		} else {
			$map[ $key ] = $note;
		}
		return update_option( self::NOTES_OPTION, $map, false );
	}
}
