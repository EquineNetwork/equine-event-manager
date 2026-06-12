<?php
/**
 * Notifications recipient-resolution engine (v2 Notifications feature, Slice 1).
 *
 * Resolves the distinct customer email set for an event-targeted notification,
 * driven by an audience built from three controls:
 *   - INCLUDE segment  : all | stall | rv | addon | group | division:{id}
 *   - EXCLUDE segment  : same option set + '' (none)  →  recipients = include − exclude
 *   - PAYMENT filter    : all | paid | unpaid
 *
 * This covers the canonical asks: "everyone who entered the #9.5 Division"
 * (include=division:{id}) and "RV buyers but NOT stall customers"
 * (include=rv, exclude=stall).
 *
 * Recipients are resolved from ORDERS (authoritative — includes division-fold
 * orders), not from stall/RV-table notes; division segments read the
 * wp_eem_division_entries ledger. All emails are lowercased + de-duplicated.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper for resolving notification audiences.
 */
class EEM_Notifications {

	/** @var string[] Order payment statuses treated as "unpaid" (owes money). */
	const UNPAID_STATUSES = array( 'pending', 'unpaid', 'invoice-sent', 'invoice_sent' );

	/**
	 * Canonical audience-segment options (key => label). `division:{id}` is
	 * appended dynamically per event from {@see self::event_divisions()}.
	 *
	 * @return array<string,string>
	 */
	public static function segment_options(): array {
		return array(
			'all'   => __( 'All customers', 'equine-event-manager' ),
			'stall' => __( 'Stall customers', 'equine-event-manager' ),
			'rv'    => __( 'RV customers', 'equine-event-manager' ),
			'addon' => __( 'Add-on customers', 'equine-event-manager' ),
			'group' => __( 'Group customers', 'equine-event-manager' ),
		);
	}

	/**
	 * Divisions for an event, as {division_id => name}, for the audience
	 * dropdown (Include/Exclude can target a specific division's entrants).
	 *
	 * @param int $reservation_id Reservation (event) id.
	 * @return array<int,string>
	 */
	public static function event_divisions( int $reservation_id ): array {
		$out = array();
		if ( $reservation_id <= 0 || ! class_exists( 'EEM_Entries' ) ) {
			return $out;
		}
		foreach ( EEM_Entries::get_for_reservation( $reservation_id ) as $division ) {
			if ( ! empty( $division['division_id'] ) ) {
				$out[ (int) $division['division_id'] ] = (string) $division['title'];
			}
		}
		return $out;
	}

	/**
	 * Resolve the distinct recipient email list for an audience.
	 *
	 * @param int    $reservation_id  Reservation (event) id.
	 * @param string $include         Include segment key (all|stall|rv|addon|group|division:{id}).
	 * @param string $exclude         Exclude segment key, or '' for none.
	 * @param string $payment         Payment filter (all|paid|unpaid).
	 * @return string[] Distinct lowercased recipient emails.
	 */
	public static function resolve_recipients( int $reservation_id, string $include = 'all', string $exclude = '', string $payment = 'all' ): array {
		if ( $reservation_id <= 0 ) {
			return array();
		}
		$include_emails = self::segment_emails( $reservation_id, $include, $payment );
		if ( empty( $include_emails ) ) {
			return array();
		}
		if ( '' !== $exclude ) {
			// Exclusion ignores the payment filter — "not a stall customer" means
			// not a stall customer at all, regardless of their payment status.
			$exclude_emails = self::segment_emails( $reservation_id, $exclude, 'all' );
			$include_emails = array_diff( $include_emails, $exclude_emails );
		}
		return array_values( $include_emails );
	}

	/**
	 * Recipient count for an audience (cheap wrapper for the live count peek).
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $include        Include segment.
	 * @param string $exclude        Exclude segment ('' = none).
	 * @param string $payment        Payment filter.
	 * @return int
	 */
	public static function count( int $reservation_id, string $include = 'all', string $exclude = '', string $payment = 'all' ): int {
		return count( self::resolve_recipients( $reservation_id, $include, $exclude, $payment ) );
	}

	/**
	 * Distinct lowercased emails for a single segment, with the payment filter
	 * applied (order segments) or mapped to ledger status (division segment).
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $segment        Segment key.
	 * @param string $payment        Payment filter (all|paid|unpaid).
	 * @return array<string,string> email => email (keyed for set ops).
	 */
	private static function segment_emails( int $reservation_id, string $segment, string $payment ): array {
		// Division segment → entrants ledger.
		if ( 0 === strpos( $segment, 'division:' ) ) {
			$division_id = (int) substr( $segment, strlen( 'division:' ) );
			return self::division_segment_emails( $division_id, $payment );
		}

		// Order-backed segments (all/stall/rv/addon/group).
		$emails = array();
		foreach ( self::event_orders( $reservation_id ) as $order ) {
			$email = strtolower( trim( (string) ( $order['email'] ?? '' ) ) );
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}
			if ( ! self::order_matches_payment( (string) ( $order['payment_status'] ?? 'pending' ), $payment ) ) {
				continue;
			}
			if ( 'all' === $segment ) {
				$emails[ $email ] = $email;
				continue;
			}
			$type_keys = class_exists( 'EEM_Orders_List_Repo' ) ? EEM_Orders_List_Repo::derive_type_keys( $order ) : array();
			if ( in_array( $segment, $type_keys, true ) ) {
				$emails[ $email ] = $email;
			}
		}
		return $emails;
	}

	/**
	 * Emails entered in a division (ledger), filtered by payment. Refunded /
	 * cancelled entries are excluded (they no longer hold a spot).
	 *
	 * @param int    $division_id Division (en_entry) id.
	 * @param string $payment     Payment filter (all|paid|unpaid).
	 * @return array<string,string>
	 */
	private static function division_segment_emails( int $division_id, string $payment ): array {
		$emails = array();
		if ( $division_id <= 0 || ! class_exists( 'EEM_Division_Entries' ) ) {
			return $emails;
		}
		foreach ( EEM_Division_Entries::get_entrants( $division_id ) as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( 'paid' !== $status && 'unpaid' !== $status ) {
				continue; // refunded / cancelled → not a current entrant.
			}
			if ( 'paid' === $payment && 'paid' !== $status ) {
				continue;
			}
			if ( 'unpaid' === $payment && 'unpaid' !== $status ) {
				continue;
			}
			$email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
			if ( '' !== $email && is_email( $email ) ) {
				$emails[ $email ] = $email;
			}
		}
		return $emails;
	}

	/**
	 * Whether an order's payment status matches the audience payment filter.
	 *
	 * @param string $status  Order payment_status slug.
	 * @param string $payment Filter (all|paid|unpaid).
	 * @return bool
	 */
	private static function order_matches_payment( string $status, string $payment ): bool {
		$slug = strtolower( trim( $status ) );
		if ( 'paid' === $payment ) {
			return 'paid' === $slug;
		}
		if ( 'unpaid' === $payment ) {
			return in_array( $slug, self::UNPAID_STATUSES, true );
		}
		return true; // 'all'
	}

	/**
	 * Grouped orders for one event (reservation), cached per request.
	 *
	 * @param int $reservation_id Reservation id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function event_orders( int $reservation_id ): array {
		static $cache = array();
		if ( isset( $cache[ $reservation_id ] ) ) {
			return $cache[ $reservation_id ];
		}
		$orders = array();
		if ( class_exists( 'EEM_Orders_Repository' ) ) {
			foreach ( ( new EEM_Orders_Repository() )->get_orders() as $order ) {
				if ( (int) ( $order['reservation_id'] ?? 0 ) === $reservation_id ) {
					$orders[] = $order;
				}
			}
		}
		$cache[ $reservation_id ] = $orders;
		return $orders;
	}
}
