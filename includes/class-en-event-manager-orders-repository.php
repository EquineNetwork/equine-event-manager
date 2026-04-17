<?php
/**
 * Reservation order data access.
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads customer reservation/order rows.
 */
class EN_Event_Manager_Orders_Repository {

	/**
	 * Get reservation orders from stall and RV tables.
	 *
	 * @param string $event_filter Optional event name or ID filter.
	 * @return array
	 */
	public function get_orders( $event_filter = '' ) {
		global $wpdb;

		$stall_table = $wpdb->prefix . 'en_stall_reservations';
		$rv_table    = $wpdb->prefix . 'en_rv_reservations';
		$event_names = $this->get_reservation_event_names();
		$orders      = array();

		$stall_rows = $wpdb->get_results(
			"SELECT id, event_source, event_id, external_event_id, customer_name, stall_qty, tack_stall_qty, total, payment_status, created_at FROM {$stall_table} ORDER BY created_at DESC LIMIT 100",
			ARRAY_A
		);

		foreach ( (array) $stall_rows as $row ) {
			$orders[] = array(
				'id'             => 'S-' . absint( $row['id'] ),
				'event_name'     => $this->get_event_name( $row, $event_names ),
				'customer_name'  => $row['customer_name'],
				'type'           => __( 'Stall', 'en-event-manager' ),
				'quantity'       => absint( $row['stall_qty'] ) + absint( $row['tack_stall_qty'] ),
				'total'          => $row['total'],
				'payment_status' => $row['payment_status'],
				'created_at'     => $row['created_at'],
			);
		}

		$rv_rows = $wpdb->get_results(
			"SELECT id, event_source, event_id, external_event_id, customer_name, rv_qty, total, payment_status, created_at FROM {$rv_table} ORDER BY created_at DESC LIMIT 100",
			ARRAY_A
		);

		foreach ( (array) $rv_rows as $row ) {
			$orders[] = array(
				'id'             => 'R-' . absint( $row['id'] ),
				'event_name'     => $this->get_event_name( $row, $event_names ),
				'customer_name'  => $row['customer_name'],
				'type'           => __( 'RV', 'en-event-manager' ),
				'quantity'       => absint( $row['rv_qty'] ),
				'total'          => $row['total'],
				'payment_status' => $row['payment_status'],
				'created_at'     => $row['created_at'],
			);
		}

		usort(
			$orders,
			function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		if ( '' !== $event_filter ) {
			$orders = array_filter(
				$orders,
				function ( $order ) use ( $event_filter ) {
					return false !== stripos( $order['event_name'], $event_filter ) || false !== stripos( $order['id'], $event_filter );
				}
			);
		}

		return array_values( $orders );
	}

	/**
	 * Resolve an event name for an order row.
	 *
	 * @param array $row Order row.
	 * @param array $event_names Event name lookup.
	 * @return string
	 */
	private function get_event_name( $row, $event_names ) {
		if ( ! empty( $row['event_id'] ) ) {
			$key = 'event_id:' . absint( $row['event_id'] );

			if ( isset( $event_names[ $key ] ) ) {
				return $event_names[ $key ];
			}

			$title = get_the_title( absint( $row['event_id'] ) );

			if ( $title ) {
				return $title;
			}
		}

		if ( ! empty( $row['external_event_id'] ) ) {
			$key = 'external_event_id:' . $row['external_event_id'];

			if ( isset( $event_names[ $key ] ) ) {
				return $event_names[ $key ];
			}

			return sprintf(
				/* translators: %s: External event ID. */
				__( 'External Event %s', 'en-event-manager' ),
				$row['external_event_id']
			);
		}

		return __( 'Unassigned Event', 'en-event-manager' );
	}

	/**
	 * Build event name lookup from reservation CPT meta.
	 *
	 * @return array
	 */
	private function get_reservation_event_names() {
		$reservations = get_posts(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$event_names  = array();

		foreach ( $reservations as $reservation_id ) {
			$event_id            = absint( get_post_meta( $reservation_id, '_en_event_id', true ) );
			$external_event_id   = get_post_meta( $reservation_id, '_en_external_event_id', true );
			$external_event_name = get_post_meta( $reservation_id, '_en_external_event_name', true );

			if ( $event_id ) {
				$title = get_the_title( $event_id );

				if ( $title ) {
					$event_names[ 'event_id:' . $event_id ] = $title;
				}
			}

			if ( '' !== $external_event_id && '' !== $external_event_name ) {
				$event_names[ 'external_event_id:' . $external_event_id ] = $external_event_name;
			}
		}

		return $event_names;
	}
}
