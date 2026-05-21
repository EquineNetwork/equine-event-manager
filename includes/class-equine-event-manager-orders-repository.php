<?php
/**
 * Reservation order data access.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and mutates customer reservation/order rows.
 */
class EEM_Orders_Repository {

	/**
	 * Next stable order number option key.
	 *
	 * @var string
	 */
	const NEXT_ORDER_NUMBER_OPTION = 'equine_event_manager_next_order_number';

	/**
	 * Cached grouped orders.
	 *
	 * @var array|null
	 */
	private $cached_orders = null;

	/**
	 * Get reservation orders from stall and RV tables.
	 *
	 * @param string $event_filter Optional event name or ID filter.
	 * @param string $search_term Optional search term.
	 * @return array
	 */
	public function get_orders( $event_filter = '', $orderby = 'date', $order = 'desc', $search_term = '' ) {
		$orders = $this->get_grouped_orders();

		if ( '' !== $event_filter ) {
			$orders = array_filter(
				$orders,
				function ( $order ) use ( $event_filter ) {
					return $this->get_order_event_filter_label( $order ) === $event_filter;
				}
			);
		}

		if ( '' !== $search_term ) {
			$orders = array_filter(
				$orders,
				function ( $order ) use ( $search_term ) {
					return $this->order_matches_search_term( $order, $search_term );
				}
			);
		}

		$orders = $this->sort_orders( $orders, $orderby, $order );

		return array_values( $orders );
	}

	/**
	 * Get event filter options for recent orders.
	 *
	 * Events remain in the list if they have activity in the current year or in
	 * the last 365 days, whichever is broader.
	 *
	 * @return array<string, string>
	 */
	public function get_recent_event_filter_options() {
		$options      = array();
		$current_year = (int) wp_date( 'Y', current_time( 'timestamp' ) );
		$cutoff       = current_time( 'timestamp' ) - YEAR_IN_SECONDS;

		foreach ( $this->get_grouped_orders() as $order ) {
			$created_timestamp = ! empty( $order['created_at'] ) ? strtotime( (string) $order['created_at'] ) : 0;

			if ( ! $created_timestamp ) {
				continue;
			}

			$created_year = (int) wp_date( 'Y', $created_timestamp );

			if ( $created_year !== $current_year && $created_timestamp < $cutoff ) {
				continue;
			}

			$label = $this->get_order_event_filter_label( $order );

			if ( '' === $label ) {
				continue;
			}

			if ( ! isset( $options[ $label ] ) || $created_timestamp > $options[ $label ] ) {
				$options[ $label ] = $created_timestamp;
			}
		}

		arsort( $options );

		return array_combine( array_keys( $options ), array_keys( $options ) );
	}

	/**
	 * Get a single order by its key.
	 *
	 * @param string $order_key Order key.
	 * @return array|null
	 */
	public function get_order( $order_key ) {
		foreach ( $this->get_grouped_orders() as $order ) {
			if ( $order['order_key'] === $order_key ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Get a single order by the stored submission token in its notes.
	 *
	 * @param string $submission_token Submission token.
	 * @return array|null
	 */
	public function get_order_by_submission_token( $submission_token ) {
		$submission_token = sanitize_text_field( $submission_token );

		if ( '' === $submission_token ) {
			return null;
		}

		foreach ( $this->get_grouped_orders() as $order ) {
			if ( ! empty( $order['notes'] ) && false !== strpos( (string) $order['notes'], 'Submission token: ' . $submission_token ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Get a single order by its stored invoice token.
	 *
	 * @param string $invoice_token Invoice token.
	 * @return array|null
	 */
	public function get_order_by_invoice_token( $invoice_token ) {
		$invoice_token = sanitize_text_field( $invoice_token );

		if ( '' === $invoice_token ) {
			return null;
		}

		foreach ( $this->get_grouped_orders() as $order ) {
			foreach ( $order['components'] as $component ) {
				if ( empty( $component['notes'] ) ) {
					continue;
				}

				if ( false !== strpos( (string) $component['notes'], 'Invoice Token: ' . $invoice_token ) ) {
					return $order;
				}
			}
		}

		return null;
	}

	/**
	 * Delete all table rows for an order.
	 *
	 * @param string $order_key Order key.
	 * @return bool
	 */
	public function delete_order( $order_key ) {
		global $wpdb;

		$order = $this->get_order( $order_key );

		if ( ! $order ) {
			return false;
		}

		foreach ( $order['components'] as $component ) {
			$wpdb->delete(
				$this->get_table_name( $component['table'] ),
				array( 'id' => absint( $component['row_id'] ) ),
				array( '%d' )
			);
		}

		$this->cached_orders = null;

		return true;
	}

	/**
	 * Mark a component as refunded.
	 *
	 * @param string $table Table slug.
	 * @param int    $row_id Row ID.
	 * @param string $refund_transaction_id Refund transaction/reference ID.
	 * @return bool
	 */
	public function mark_component_refunded( $table, $row_id, $refund_transaction_id ) {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name( $table ),
			array(
				'payment_status'         => 'refunded',
				'refund_transaction_id'  => sanitize_text_field( $refund_transaction_id ),
				'refunded_at'            => current_time( 'mysql' ),
			),
			array( 'id' => absint( $row_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->cached_orders = null;

		return false !== $updated;
	}

	/**
	 * Update arbitrary component fields.
	 *
	 * @param string $table   Table slug.
	 * @param int    $row_id  Row ID.
	 * @param array  $data    Column/value map.
	 * @param array  $formats Optional value formats.
	 * @return bool
	 */
	public function update_component_fields( $table, $row_id, $data, $formats = array() ) {
		global $wpdb;

		if ( empty( $data ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$this->get_table_name( $table ),
			$data,
			array( 'id' => absint( $row_id ) ),
			! empty( $formats ) ? $formats : null,
			array( '%d' )
		);

		$this->cached_orders = null;

		return false !== $updated;
	}

	/**
	 * Update payment details across every component in an order.
	 *
	 * @param string $order_key        Order key.
	 * @param string $payment_status   Payment status slug.
	 * @param string $transaction_id   Transaction/reference ID.
	 * @param string $payment_gateway  Gateway slug.
	 * @return bool
	 */
	public function update_order_payment_details( $order_key, $payment_status, $transaction_id, $payment_gateway = '' ) {
		$order = $this->get_order( $order_key );

		if ( ! $order ) {
			return false;
		}

		$updated_any = false;

		foreach ( $order['components'] as $component ) {
			$updated_any = $this->update_component_fields(
				$component['table'],
				$component['row_id'],
				array(
					'payment_status'  => sanitize_key( $payment_status ),
					'transaction_id'  => sanitize_text_field( $transaction_id ),
					'payment_gateway' => sanitize_key( $payment_gateway ? $payment_gateway : $component['payment_gateway'] ),
				),
				array( '%s', '%s', '%s' )
			) || $updated_any;
		}

		return $updated_any;
	}

	/**
	 * Mark an order as manually paid without attaching a processor transaction.
	 *
	 * @param string $order_key       Order key.
	 * @param string $payment_method  Human-readable payment method.
	 * @return bool
	 */
	public function mark_order_paid_manually( $order_key, $payment_method = 'Cash' ) {
		$order = $this->get_order( $order_key );

		if ( ! $order ) {
			return false;
		}

		$updated_any     = false;
		$paid_at         = current_time( 'mysql' );
		$payment_method  = trim( sanitize_text_field( $payment_method ) );
		$payment_method  = '' !== $payment_method ? $payment_method : 'Cash';

		foreach ( $order['components'] as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';
			$notes = $this->upsert_note_line( $notes, 'Invoice Status', 'Paid' );
			$notes = $this->upsert_note_line( $notes, 'Invoice Paid At', $paid_at );
			$notes = $this->upsert_note_line( $notes, 'Manual Payment Method', $payment_method );
			$notes = $this->upsert_note_line( $notes, 'Manual Payment Recorded At', $paid_at );

			$updated_any = $this->update_component_fields(
				$component['table'],
				$component['row_id'],
				array(
					'payment_status'  => 'paid',
					'transaction_id'  => '',
					'payment_gateway' => 'manual',
					'notes'           => $notes,
				),
				array( '%s', '%s', '%s', '%s' )
			) || $updated_any;
		}

		return $updated_any;
	}

	/**
	 * Update saved stall and RV unit assignments for an order.
	 *
	 * @param string $order_key   Order key.
	 * @param string $stall_units Comma-separated stall units.
	 * @param string $rv_units    Comma-separated RV lot names.
	 * @return bool
	 */
	public function update_order_unit_assignments( $order_key, $stall_units = '', $rv_units = '' ) {
		$order = $this->get_order( $order_key );

		if ( ! $order ) {
			return false;
		}

		$updated_any = false;
		$stall_units = trim( sanitize_text_field( $stall_units ) );
		$rv_units    = trim( sanitize_text_field( $rv_units ) );

		foreach ( $order['components'] as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';

			if ( 'stall' === $component['table'] ) {
				$notes = $this->upsert_note_line( $notes, 'Assigned Stall Units', $stall_units );
			}

			if ( 'rv' === $component['table'] ) {
				$notes = $this->upsert_note_line( $notes, 'Assigned RV Lots', $rv_units );
				$notes = $this->remove_note_line( $notes, 'Assigned RV Units' );
			}

			$updated_any = $this->update_component_fields(
				$component['table'],
				$component['row_id'],
				array(
					'notes' => $notes,
				),
				array( '%s' )
			) || $updated_any;
		}

		return $updated_any;
	}

	/**
	 * Auto-assign stall and RV units for an order from the reservation chart config.
	 *
	 * @param string $order_key Order key.
	 * @return bool
	 */
	public function auto_assign_units_for_order( $order_key ) {
		$order = $this->get_order( $order_key );

		if ( ! $order || empty( $order['reservation_id'] ) ) {
			return false;
		}

		$config = $this->get_stall_chart_config( absint( $order['reservation_id'] ) );

		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		$reservation_orders = array_filter(
			$this->get_grouped_orders(),
			function ( $candidate ) use ( $order ) {
				return absint( isset( $candidate['reservation_id'] ) ? $candidate['reservation_id'] : 0 ) === absint( $order['reservation_id'] );
			}
		);

		usort(
			$reservation_orders,
			function ( $left, $right ) {
				$left_created  = ! empty( $left['created_at'] ) ? strtotime( (string) $left['created_at'] ) : 0;
				$right_created = ! empty( $right['created_at'] ) ? strtotime( (string) $right['created_at'] ) : 0;

				if ( $left_created === $right_created ) {
					return strcmp( isset( $left['order_number'] ) ? (string) $left['order_number'] : '', isset( $right['order_number'] ) ? (string) $right['order_number'] : '' );
				}

				return $left_created <=> $right_created;
			}
		);

		$stall_map = array();
		$rv_map    = array();

		foreach ( $reservation_orders as $candidate ) {
			$stall_dates  = $this->get_chart_occupied_dates( isset( $candidate['stall_arrival_date'] ) ? $candidate['stall_arrival_date'] : '', isset( $candidate['stall_departure_date'] ) ? $candidate['stall_departure_date'] : '' );
			$rv_dates     = $this->get_chart_occupied_dates( isset( $candidate['rv_arrival_date'] ) ? $candidate['rv_arrival_date'] : '', isset( $candidate['rv_departure_date'] ) ? $candidate['rv_departure_date'] : '' );
			$stall_needed = absint( isset( $candidate['stall_quantity'] ) ? $candidate['stall_quantity'] : 0 );
			$rv_needed    = absint( isset( $candidate['rv_quantity'] ) ? $candidate['rv_quantity'] : 0 );
			$stall_saved  = $this->parse_assigned_units_string( $this->get_order_component_note_value( $candidate, 'stall', 'Assigned Stall Units' ) );
			$rv_saved     = $this->parse_assigned_units_string( $this->get_order_component_note_value( $candidate, 'rv', 'Assigned RV Lots' ) );
			$rv_preferred = $this->get_order_component_note_value( $candidate, 'rv', 'RV Lot' );
			$rv_pool      = array_values(
				array_unique(
					array_filter(
						array_merge(
							array( $rv_preferred ),
							$rv_saved,
							$config['auto_assignable_rv_units']
						)
					)
				)
			);

			$stall_units = $this->allocate_chart_units( $config['available_stall_units'], $stall_map, $stall_dates, $stall_needed, $stall_saved, $candidate['order_key'] );
			$rv_units    = $this->allocate_chart_units( $rv_pool, $rv_map, $rv_dates, $rv_needed, array_values( array_unique( array_filter( array_merge( array( $rv_preferred ), $rv_saved ) ) ) ), $candidate['order_key'] );

			if ( $candidate['order_key'] !== $order_key ) {
				continue;
			}

			return $this->persist_auto_assigned_units( $candidate, $stall_saved, $rv_saved, $stall_units['assigned'], $rv_units['assigned'] );
		}

		return false;
	}

	/**
	 * Build grouped orders from stall and RV reservation tables.
	 *
	 * @return array
	 */
	private function get_grouped_orders() {
		if ( null !== $this->cached_orders ) {
			return $this->cached_orders;
		}

		$reservation_index = $this->get_reservation_index();
		$order_map         = array();
		$stall_rows        = $this->get_component_rows( 'stall' );

		foreach ( (array) $stall_rows as $row ) {
			$group_key = $this->build_group_key( $row );

			if ( ! isset( $order_map[ $group_key ] ) ) {
				$order_map[ $group_key ] = $this->create_order_seed( $row, $reservation_index );
			}

			$stall_quantity = absint( $row['stall_qty'] ) + absint( $row['tack_stall_qty'] );
			$shavings_only  = 0 === $stall_quantity && ( absint( $row['required_shavings_qty'] ) > 0 || absint( $row['additional_shavings_qty'] ) > 0 );

			if ( $shavings_only ) {
				$order_map[ $group_key ]['type_labels']['add_ons'] = __( 'Add-On', 'equine-event-manager' );
			} else {
				$order_map[ $group_key ]['type_labels']['stall'] = __( 'Stall', 'equine-event-manager' );
			}

			if ( absint( $row['required_shavings_qty'] ) > 0 || absint( $row['additional_shavings_qty'] ) > 0 || $this->notes_include_general_add_ons( $row ) ) {
				$order_map[ $group_key ]['type_labels']['add_ons'] = __( 'Add-On', 'equine-event-manager' );
			}

			if ( $this->notes_include_group_reservation( $row ) ) {
				$order_map[ $group_key ]['type_labels']['group'] = __( 'Group', 'equine-event-manager' );
			}

			$order_map[ $group_key ]['stall_quantity']          += $stall_quantity;
			$order_map[ $group_key ]['required_shavings_qty']   += absint( $row['required_shavings_qty'] );
			$order_map[ $group_key ]['additional_shavings_qty'] += absint( $row['additional_shavings_qty'] );
			$order_map[ $group_key ]['stall_subtotal']          += (float) $row['subtotal'];
			$order_map[ $group_key ]['fees']                    += (float) $row['convenience_fee'];
			$order_map[ $group_key ]['total']                   += (float) $row['total'];
			$order_map[ $group_key ]['arrival_date']            = $row['arrival_date'];
			$order_map[ $group_key ]['departure_date']          = $row['departure_date'];
			$order_map[ $group_key ]['stay_type']               = $row['stay_type'];
			$order_map[ $group_key ]['stall_arrival_date']      = $row['arrival_date'];
			$order_map[ $group_key ]['stall_departure_date']    = $row['departure_date'];
			$order_map[ $group_key ]['stall_stay_type']         = $row['stay_type'];
			$order_map[ $group_key ]['notes']                   = $row['notes'];
			$order_map[ $group_key ]['payment_status']          = $this->get_combined_payment_status( $order_map[ $group_key ]['payment_status'], $row['payment_status'] );
			$order_map[ $group_key ]['components'][]            = $this->build_component_payload( 'stall', $row );
		}

		$rv_rows = $this->get_component_rows( 'rv' );

		foreach ( (array) $rv_rows as $row ) {
			$group_key = $this->build_group_key( $row );

			if ( ! isset( $order_map[ $group_key ] ) ) {
				$order_map[ $group_key ] = $this->create_order_seed( $row, $reservation_index );
			}

			$order_map[ $group_key ]['type_labels']['rv']  = __( 'RV', 'equine-event-manager' );
			if ( ! empty( $this->get_rv_addon_labels_from_row( $row ) ) || $this->notes_include_general_add_ons( $row ) ) {
				$order_map[ $group_key ]['type_labels']['add_ons'] = __( 'Add-On', 'equine-event-manager' );
			}
			if ( $this->notes_include_group_reservation( $row ) ) {
				$order_map[ $group_key ]['type_labels']['group'] = __( 'Group', 'equine-event-manager' );
			}
			$order_map[ $group_key ]['rv_quantity']       += absint( $row['rv_qty'] );
			$order_map[ $group_key ]['rv_subtotal']       += (float) $row['subtotal'];
			$order_map[ $group_key ]['fees']              += (float) $row['convenience_fee'];
			$order_map[ $group_key ]['total']             += (float) $row['total'];
			$order_map[ $group_key ]['arrival_date']       = $row['arrival_date'];
			$order_map[ $group_key ]['departure_date']     = $row['departure_date'];
			$order_map[ $group_key ]['stay_type']          = $row['stay_type'];
			$order_map[ $group_key ]['rv_type']            = $this->get_rv_addon_labels_from_row( $row );
			$order_map[ $group_key ]['rv_arrival_date']    = $row['arrival_date'];
			$order_map[ $group_key ]['rv_departure_date']  = $row['departure_date'];
			$order_map[ $group_key ]['rv_stay_type']       = $row['stay_type'];
			$order_map[ $group_key ]['notes']              = $row['notes'];
			$order_map[ $group_key ]['payment_status']     = $this->get_combined_payment_status( $order_map[ $group_key ]['payment_status'], $row['payment_status'] );
			$order_map[ $group_key ]['components'][]       = $this->build_component_payload( 'rv', $row );
		}

		$orders = array_values( $order_map );

		foreach ( $orders as &$order ) {
			$order['order_number']    = $this->ensure_persisted_order_number( $order );
			$order['type']            = implode( ', ', array_values( $order['type_labels'] ) );
			$order['payment_gateway'] = $this->get_combined_gateway_label( $order['components'] );
			$order['can_refund']      = $this->order_can_refund( $order );
			$status                   = $this->get_order_status_display( $order['payment_status'], $order['notes'] );
			$order['status_label']    = $status['label'];
			$order['status_slug']     = $status['slug'];
			unset( $order['type_labels'] );
		}
		unset( $order );

		usort(
			$orders,
			function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		$this->cached_orders = $orders;

		return $this->cached_orders;
	}

	/**
	 * Persist auto-assigned units for components that do not already have them.
	 *
	 * @param array $order Current order payload.
	 * @param array $existing_stall_units Existing saved stall units.
	 * @param array $existing_rv_units Existing saved RV units.
	 * @param array $assigned_stall_units Computed stall units.
	 * @param array $assigned_rv_units Computed RV units.
	 * @return bool
	 */
	private function persist_auto_assigned_units( $order, $existing_stall_units, $existing_rv_units, $assigned_stall_units, $assigned_rv_units ) {
		$updated_any = false;
		$existing_stall_units = array_values( array_unique( array_map( 'sanitize_text_field', (array) $existing_stall_units ) ) );
		$existing_rv_units    = array_values( array_unique( array_map( 'sanitize_text_field', (array) $existing_rv_units ) ) );
		$assigned_stall_units = array_values( array_unique( array_map( 'sanitize_text_field', (array) $assigned_stall_units ) ) );
		$assigned_rv_units    = array_values( array_unique( array_map( 'sanitize_text_field', (array) $assigned_rv_units ) ) );

		foreach ( (array) $order['components'] as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';

			if ( 'stall' === $component['table'] && $existing_stall_units !== $assigned_stall_units ) {
				$notes = $this->upsert_note_line( $notes, 'Assigned Stall Units', implode( ', ', $assigned_stall_units ) );
			}

			if ( 'rv' === $component['table'] && $existing_rv_units !== $assigned_rv_units ) {
				$notes = $this->upsert_note_line( $notes, 'Assigned RV Lots', implode( ', ', $assigned_rv_units ) );
				$notes = $this->remove_note_line( $notes, 'Assigned RV Units' );
			}

			$updated_any = $this->update_component_fields(
				$component['table'],
				$component['row_id'],
				array(
					'notes' => $notes,
				),
				array( '%s' )
			) || $updated_any;
		}

		return $updated_any;
	}

	/**
	 * Get the reservation stall chart configuration.
	 *
	 * @param int $reservation_id Reservation ID.
	 * @return array
	 */
	private function get_stall_chart_config( $reservation_id ) {
		$stall_blocks         = get_post_meta( $reservation_id, '_en_stall_chart_stall_blocks', true );
		$rv_lots              = get_post_meta( $reservation_id, '_en_rv_lots', true );
		$blocked_stall_units  = get_post_meta( $reservation_id, '_en_stall_chart_blocked_stall_units', true );
		$blocked_rv_lots      = get_post_meta( $reservation_id, '_en_stall_chart_blocked_rv_units', true );
		$stall_units          = $this->expand_chart_units( is_array( $stall_blocks ) ? $stall_blocks : array() );
		$rv_units             = $this->get_chart_rv_lot_names( $rv_lots );
		$blocked_stall_units  = $this->sanitize_chart_unit_list( is_array( $blocked_stall_units ) ? $blocked_stall_units : array(), $stall_units );
		$blocked_rv_lots      = $this->sanitize_chart_unit_list( is_array( $blocked_rv_lots ) ? $blocked_rv_lots : array(), $rv_units );

		return array(
			'enabled'              => (bool) get_post_meta( $reservation_id, '_en_stall_chart_enabled', true ),
			'stall_units'          => $stall_units,
			'rv_units'             => $rv_units,
			'blocked_stall_units'  => $blocked_stall_units,
			'blocked_rv_units'     => $blocked_rv_lots,
			'available_stall_units'=> array_values( array_diff( $stall_units, $blocked_stall_units ) ),
			'available_rv_units'   => array_values( array_diff( $rv_units, $blocked_rv_lots ) ),
			'auto_assignable_rv_units' => $this->get_auto_assignable_rv_lot_names( is_array( $rv_lots ) ? $rv_lots : array(), $blocked_rv_lots ),
		);
	}

	/**
	 * Normalize RV lot names into an assignable chart list.
	 *
	 * @param mixed $rv_lots Raw RV lot configuration.
	 * @return array
	 */
	private function get_chart_rv_lot_names( $rv_lots ) {
		$names = array();

		foreach ( (array) $rv_lots as $rv_lot ) {
			if ( ! is_array( $rv_lot ) || empty( $rv_lot['name'] ) ) {
				continue;
			}

			$names[] = sanitize_text_field( $rv_lot['name'] );
		}

		$names = array_values( array_unique( array_filter( $names ) ) );
		sort( $names, SORT_NATURAL );

		return $names;
	}

	/**
	 * Get RV lot names that can be safely used for automatic assignment.
	 *
	 * @param array $rv_lots Raw RV lot configuration.
	 * @param array $blocked_rv_lots Blocked lot names.
	 * @return array
	 */
	private function get_auto_assignable_rv_lot_names( $rv_lots, $blocked_rv_lots ) {
		$names = array();

		foreach ( (array) $rv_lots as $rv_lot ) {
			if ( ! is_array( $rv_lot ) || empty( $rv_lot['name'] ) ) {
				continue;
			}

			$lot_name      = sanitize_text_field( $rv_lot['name'] );
			$nightly_rate  = isset( $rv_lot['nightly_rate'] ) ? trim( (string) $rv_lot['nightly_rate'] ) : '';
			$weekend_rate  = isset( $rv_lot['weekend_rate'] ) ? trim( (string) $rv_lot['weekend_rate'] ) : '';
			$has_addon_fee = ( '' !== $nightly_rate && (float) $nightly_rate > 0 ) || ( '' !== $weekend_rate && (float) $weekend_rate > 0 );

			if ( $has_addon_fee || in_array( $lot_name, (array) $blocked_rv_lots, true ) ) {
				continue;
			}

			$names[] = $lot_name;
		}

		$names = array_values( array_unique( array_filter( $names ) ) );
		sort( $names, SORT_NATURAL );

		return $names;
	}

	/**
	 * Expand configured unit blocks into flat unit values.
	 *
	 * @param array $blocks Block definitions.
	 * @return array
	 */
	private function expand_chart_units( $blocks ) {
		$units = array();

		foreach ( (array) $blocks as $block ) {
			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( ! $start || ! $end ) {
				continue;
			}

			for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
				$units[] = (string) $number;
			}
		}

		return array_values( array_unique( $units ) );
	}

	/**
	 * Sanitize a selected chart unit list against the allowed pool.
	 *
	 * @param array $values Submitted values.
	 * @param array $allowed Allowed units.
	 * @return array
	 */
	private function sanitize_chart_unit_list( $values, $allowed ) {
		$values = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) $values ) ) );

		return array_values( array_intersect( array_unique( $values ), $allowed ) );
	}

	/**
	 * Allocate units from a pool across a date range.
	 *
	 * @param array  $pool Available pool.
	 * @param array  $map Occupancy map by reference.
	 * @param array  $dates Occupied date keys.
	 * @param int    $needed Required count.
	 * @param array  $preferred Preferred/manual units.
	 * @param string $order_key Order key.
	 * @return array
	 */
	private function allocate_chart_units( $pool, &$map, $dates, $needed, $preferred, $order_key ) {
		$assigned = array();

		foreach ( (array) $preferred as $unit ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $unit, $pool, true ) && $this->chart_unit_is_available( $map, $unit, $dates ) ) {
				$assigned[] = $unit;
				$this->mark_chart_unit_occupied( $map, $unit, $dates, $order_key );
			}
		}

		foreach ( (array) $pool as $unit ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $unit, $assigned, true ) ) {
				continue;
			}

			if ( $this->chart_unit_is_available( $map, $unit, $dates ) ) {
				$assigned[] = $unit;
				$this->mark_chart_unit_occupied( $map, $unit, $dates, $order_key );
			}
		}

		return array(
			'assigned'   => $assigned,
			'unassigned' => max( 0, $needed - count( $assigned ) ),
		);
	}

	/**
	 * Check whether a chart unit is available for all occupied dates.
	 *
	 * @param array  $map Occupancy map.
	 * @param string $unit Unit identifier.
	 * @param array  $dates Occupied date keys.
	 * @return bool
	 */
	private function chart_unit_is_available( $map, $unit, $dates ) {
		foreach ( (array) $dates as $date_key ) {
			if ( ! empty( $map[ $unit ][ $date_key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark a chart unit occupied.
	 *
	 * @param array  $map Occupancy map by reference.
	 * @param string $unit Unit identifier.
	 * @param array  $dates Occupied date keys.
	 * @param string $order_key Order key.
	 * @return void
	 */
	private function mark_chart_unit_occupied( &$map, $unit, $dates, $order_key ) {
		foreach ( (array) $dates as $date_key ) {
			$map[ $unit ][ $date_key ] = $order_key;
		}
	}

	/**
	 * Get occupied nightly dates from arrival and departure.
	 *
	 * @param string $arrival_date Arrival date.
	 * @param string $departure_date Departure date.
	 * @return array
	 */
	private function get_chart_occupied_dates( $arrival_date, $departure_date ) {
		$arrival_timestamp   = $arrival_date ? strtotime( (string) $arrival_date ) : 0;
		$departure_timestamp = $departure_date ? strtotime( (string) $departure_date ) : 0;
		$dates               = array();

		if ( ! $arrival_timestamp ) {
			return $dates;
		}

		if ( ! $departure_timestamp || $departure_timestamp <= $arrival_timestamp ) {
			return array( gmdate( 'Y-m-d', $arrival_timestamp ) );
		}

		for ( $current = $arrival_timestamp; $current < $departure_timestamp; $current = strtotime( '+1 day', $current ) ) {
			$dates[] = gmdate( 'Y-m-d', $current );
		}

		return $dates;
	}

	/**
	 * Get a specific component note line value from an order.
	 *
	 * @param array  $order Order payload.
	 * @param string $table Component table slug.
	 * @param string $label Note label.
	 * @return string
	 */
	private function get_order_component_note_value( $order, $table, $label ) {
		foreach ( (array) $order['components'] as $component ) {
			if ( $table !== $component['table'] ) {
				continue;
			}

			$value = $this->get_note_value( isset( $component['notes'] ) ? $component['notes'] : '', $label );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Parse a comma-separated unit string into unique values.
	 *
	 * @param string $value Raw value.
	 * @return array
	 */
	private function parse_assigned_units_string( $value ) {
		$units = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$units = array_map( 'sanitize_text_field', $units );

		return array_values( array_unique( $units ) );
	}

	/**
	 * Get the display label used for event filtering.
	 *
	 * @param array $order Grouped order payload.
	 * @return string
	 */
	private function get_order_event_filter_label( $order ) {
		$label = ! empty( $order['reservation_title'] ) ? (string) $order['reservation_title'] : ( ! empty( $order['event_name'] ) ? (string) $order['event_name'] : '' );

		return trim( $label );
	}

	/**
	 * Create the starting order payload.
	 *
	 * @param array $row Reservation DB row.
	 * @param array $reservation_index Reservation lookup data.
	 * @return array
	 */
	private function create_order_seed( $row, $reservation_index ) {
		$reservation_id = $this->extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
		$event_name     = $this->get_event_name( $row, $reservation_index );

		return array(
			'order_key'               => md5( $this->build_group_key( $row ) ),
			'order_number'            => isset( $row['order_number'] ) ? sanitize_text_field( $row['order_number'] ) : '',
			'event_name'              => $event_name,
			'event_dates'             => $this->get_event_dates_label( $row, $reservation_index ),
			'customer_name'           => isset( $row['customer_name'] ) ? $row['customer_name'] : '',
			'email'                   => isset( $row['email'] ) ? $row['email'] : '',
			'phone'                   => isset( $row['phone'] ) ? $row['phone'] : '',
			'type'                    => '',
			'type_labels'             => array(),
			'payment_gateway'         => '',
			'invoice_type'            => $this->get_invoice_type_label( isset( $row['notes'] ) ? $row['notes'] : '' ),
			'status_label'            => '',
			'status_slug'             => '',
			'stall_quantity'          => 0,
			'rv_quantity'             => 0,
			'required_shavings_qty'   => 0,
			'additional_shavings_qty' => 0,
			'stall_subtotal'          => 0.0,
			'rv_subtotal'             => 0.0,
			'fees'                    => 0.0,
			'total'                   => 0.0,
			'payment_status'          => isset( $row['payment_status'] ) ? $row['payment_status'] : 'pending',
			'created_at'              => isset( $row['created_at'] ) ? $row['created_at'] : '',
			'arrival_date'            => isset( $row['arrival_date'] ) ? $row['arrival_date'] : '',
			'departure_date'          => isset( $row['departure_date'] ) ? $row['departure_date'] : '',
			'stay_type'               => isset( $row['stay_type'] ) ? $row['stay_type'] : '',
			'stall_arrival_date'      => '',
			'stall_departure_date'    => '',
			'stall_stay_type'         => '',
			'rv_arrival_date'         => '',
			'rv_departure_date'       => '',
			'rv_stay_type'            => '',
			'rv_type'                 => $this->get_rv_addon_labels_from_row( $row ),
			'reservation_id'          => $reservation_id,
			'reservation_title'       => $reservation_id && isset( $reservation_index[ $reservation_id ]['title'] ) ? $reservation_index[ $reservation_id ]['title'] : '',
			'notes'                   => isset( $row['notes'] ) ? $row['notes'] : '',
			'components'              => array(),
			'can_refund'              => false,
		);
	}

	/**
	 * Build a component payload from a row.
	 *
	 * @param string $table Table slug.
	 * @param array  $row Raw row.
	 * @return array
	 */
	private function build_component_payload( $table, $row ) {
		return array(
			'table'                 => $table,
			'row_id'                => absint( $row['id'] ),
			'order_number'          => isset( $row['order_number'] ) ? sanitize_text_field( $row['order_number'] ) : '',
			'payment_gateway'       => isset( $row['payment_gateway'] ) ? sanitize_key( $row['payment_gateway'] ) : '',
			'payment_status'        => isset( $row['payment_status'] ) ? sanitize_key( $row['payment_status'] ) : 'pending',
			'transaction_id'        => isset( $row['transaction_id'] ) ? sanitize_text_field( $row['transaction_id'] ) : '',
			'refund_transaction_id' => isset( $row['refund_transaction_id'] ) ? sanitize_text_field( $row['refund_transaction_id'] ) : '',
			'notes'                 => isset( $row['notes'] ) ? (string) $row['notes'] : '',
			'total'                 => isset( $row['total'] ) ? (float) $row['total'] : 0.0,
		);
	}

	/**
	 * Get RV add-on labels from a reservation row.
	 *
	 * Older RV reservations stored the selected add-ons only in the notes
	 * column, so we fall back to parsing that line when rv_type is empty.
	 *
	 * @param array $row Raw RV reservation row.
	 * @return string
	 */
	private function get_rv_addon_labels_from_row( $row ) {
		$rv_type = isset( $row['rv_type'] ) ? trim( (string) $row['rv_type'] ) : '';

		if ( '' !== $rv_type ) {
			return $rv_type;
		}

		$notes = isset( $row['notes'] ) ? (string) $row['notes'] : '';

		if ( preg_match( '/(?:^|\n)RV Add-Ons:\s*(.+)$/mi', $notes, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Ensure an order has a stable persisted order number.
	 *
	 * @param array $order Grouped order payload.
	 * @return string
	 */
	private function ensure_persisted_order_number( $order ) {
		$order_number = sanitize_text_field( isset( $order['order_number'] ) ? $order['order_number'] : '' );

		if ( '' === $order_number ) {
			$order_number = $this->reserve_order_number();
		}

		foreach ( $order['components'] as $component ) {
			$component_order_number = isset( $component['order_number'] ) ? sanitize_text_field( $component['order_number'] ) : '';

			if ( $component_order_number === $order_number ) {
				continue;
			}

			$this->update_component_order_number( $component['table'], $component['row_id'], $order_number );
		}

		return $order_number;
	}

	/**
	 * Reserve the next stable order number.
	 *
	 * @return string
	 */
	public function reserve_order_number() {
		$next_number = $this->get_next_order_number_counter();

		update_option( self::NEXT_ORDER_NUMBER_OPTION, $next_number + 1, false );

		return str_pad( (string) $next_number, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Get the next numeric order number to reserve.
	 *
	 * @return int
	 */
	private function get_next_order_number_counter() {
		$stored_next = absint( get_option( self::NEXT_ORDER_NUMBER_OPTION, 0 ) );

		if ( $stored_next > 0 ) {
			return $stored_next;
		}

		global $wpdb;
		$stall_max = 0;
		$rv_max    = 0;

		foreach ( $this->get_component_table_candidates( 'stall' ) as $stall_table ) {
			$stall_max = max( $stall_max, (int) $wpdb->get_var( "SELECT MAX(CAST(order_number AS UNSIGNED)) FROM {$stall_table}" ) );
		}

		foreach ( $this->get_component_table_candidates( 'rv' ) as $rv_table ) {
			$rv_max = max( $rv_max, (int) $wpdb->get_var( "SELECT MAX(CAST(order_number AS UNSIGNED)) FROM {$rv_table}" ) );
		}

		$next      = max( $stall_max, $rv_max ) + 1;

		return $next > 0 ? $next : 1;
	}

	/**
	 * Persist an order number to an underlying reservation row.
	 *
	 * @param string $table        Table slug.
	 * @param int    $row_id       Row ID.
	 * @param string $order_number Stable order number.
	 * @return void
	 */
	private function update_component_order_number( $table, $row_id, $order_number ) {
		global $wpdb;

		$wpdb->update(
			$this->get_table_name( $table ),
			array( 'order_number' => sanitize_text_field( $order_number ) ),
			array( 'id' => absint( $row_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get a combined payment status.
	 *
	 * @param string $current Current grouped status.
	 * @param string $incoming Incoming row status.
	 * @return string
	 */
	private function get_combined_payment_status( $current, $incoming ) {
		$current  = sanitize_key( $current );
		$incoming = sanitize_key( $incoming );

		if ( '' === $current ) {
			return $incoming;
		}

		if ( $current === $incoming ) {
			return $current;
		}

		if ( in_array( 'refunded', array( $current, $incoming ), true ) ) {
			return 'partially_refunded';
		}

		if ( in_array( 'paid', array( $current, $incoming ), true ) && in_array( 'pending', array( $current, $incoming ), true ) ) {
			return 'partially_paid';
		}

		return $incoming;
	}

	/**
	 * Get a readable gateway label for grouped components.
	 *
	 * @param array $components Component rows.
	 * @return string
	 */
	private function get_combined_gateway_label( $components ) {
		$labels = array();

		foreach ( $components as $component ) {
			if ( empty( $component['payment_gateway'] ) ) {
				continue;
			}

			$labels[ $component['payment_gateway'] ] = $this->get_gateway_label( $component['payment_gateway'] );
		}

		return ! empty( $labels ) ? implode( ', ', $labels ) : __( 'Not set', 'equine-event-manager' );
	}

	/**
	 * Check whether an order has refundable components.
	 *
	 * @param array $order Order data.
	 * @return bool
	 */
	private function order_can_refund( $order ) {
		foreach ( $order['components'] as $component ) {
			if ( ! empty( $component['transaction_id'] ) && in_array( $component['payment_status'], array( 'paid', 'captured', 'completed', 'partially_paid', 'partially_refunded' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Insert or replace a metadata line within component notes.
	 *
	 * @param string $notes Notes value.
	 * @param string $label Metadata label.
	 * @param string $value Metadata value.
	 * @return string
	 */
	private function upsert_note_line( $notes, $label, $value ) {
		$notes   = trim( (string) $notes );
		$pattern = '/^' . preg_quote( $label, '/' ) . ':\s*.*$/mi';
		$line    = $label . ': ' . $value;

		if ( preg_match( $pattern, $notes ) ) {
			$notes = preg_replace( $pattern, $line, $notes );
		} else {
			$notes = trim( $notes . "\n" . $line );
		}

		return trim( preg_replace( "/\n{3,}/", "\n\n", $notes ) );
	}

	/**
	 * Remove a labeled metadata line from component notes.
	 *
	 * @param string $notes Notes value.
	 * @param string $label Metadata label.
	 * @return string
	 */
	private function remove_note_line( $notes, $label ) {
		$notes   = (string) $notes;
		$pattern = '/^' . preg_quote( $label, '/' ) . ':\s*.*(?:\R|$)/mi';
		$notes   = preg_replace( $pattern, '', $notes );

		return trim( preg_replace( "/\n{3,}/", "\n\n", (string) $notes ) );
	}

	/**
	 * Read a single labeled note line value.
	 *
	 * @param string $notes Notes blob.
	 * @param string $label Line label.
	 * @return string
	 */
	private function get_note_value( $notes, $label ) {
		if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', (string) $notes, $matches ) ) {
			return trim( sanitize_text_field( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Get a display label for a payment gateway.
	 *
	 * @param string $gateway Gateway slug.
	 * @return string
	 */
	public function get_gateway_label( $gateway ) {
		if ( 'authorize_net' === $gateway ) {
			return __( 'Authorize.net', 'equine-event-manager' );
		}

		if ( 'stripe' === $gateway ) {
			return __( 'Stripe', 'equine-event-manager' );
		}

		return __( 'Unknown', 'equine-event-manager' );
	}

	/**
	 * Get the DB table name for a component type.
	 *
	 * @param string $table Table slug.
	 * @return string
	 */
	private function get_table_name( $table ) {
		$candidates = $this->get_component_table_candidates( $table );

		return reset( $candidates );
	}

	/**
	 * Get reservation rows for a component type with compatibility fallback.
	 *
	 * @param string $table Table slug.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_component_rows( $table ) {
		global $wpdb;

		$rows = array();

		foreach ( $this->get_component_table_candidates( $table ) as $table_name ) {
			$table_rows = $wpdb->get_results(
				"SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 250",
				ARRAY_A
			);

			if ( ! empty( $table_rows ) ) {
				$rows = array_merge( $rows, $table_rows );
			}
		}

		if ( empty( $rows ) ) {
			return array();
		}

		usort(
			$rows,
			function ( $left, $right ) {
				return strcmp(
					isset( $right['created_at'] ) ? (string) $right['created_at'] : '',
					isset( $left['created_at'] ) ? (string) $left['created_at'] : ''
				);
			}
		);

		return array_slice( $rows, 0, 250 );
	}

	/**
	 * Discover likely table names for reservation components.
	 *
	 * @param string $table Table slug.
	 * @return array<int, string>
	 */
	private function get_component_table_candidates( $table ) {
		global $wpdb;

		$canonical_name = 'stall' === $table ? $wpdb->prefix . 'en_stall_reservations' : $wpdb->prefix . 'en_rv_reservations';
		$legacy_suffix  = 'stall' === $table ? 'stall_reservations' : 'rv_reservations';
		$candidates     = array( $canonical_name );
		$patterns       = array(
			$wpdb->esc_like( $wpdb->prefix . 'en_' . $legacy_suffix ),
			$wpdb->esc_like( $wpdb->prefix . $legacy_suffix ),
			'%en_' . $legacy_suffix,
			'%' . $legacy_suffix,
		);

		foreach ( $patterns as $pattern ) {
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );

			foreach ( (array) $tables as $table_name ) {
				$candidates[] = (string) $table_name;
			}
		}

		$candidates = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $candidates )
				)
			)
		);

		return ! empty( $candidates ) ? $candidates : array( $canonical_name );
	}

	/**
	 * Build a grouping key for stall/RV rows created by one submission.
	 *
	 * @param array $row Reservation DB row.
	 * @return string
	 */
	private function build_group_key( $row ) {
		$submission_token = $this->extract_submission_token_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );

		if ( '' !== $submission_token ) {
			return sanitize_text_field( $submission_token );
		}

		$created_timestamp = isset( $row['created_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $row['created_at'] ) ) : '';
		$reservation_id    = $this->extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );

		return implode(
			'|',
			array(
				sanitize_key( isset( $row['event_source'] ) ? $row['event_source'] : '' ),
				absint( isset( $row['event_id'] ) ? $row['event_id'] : 0 ),
				sanitize_text_field( isset( $row['external_event_id'] ) ? $row['external_event_id'] : '' ),
				sanitize_text_field( isset( $row['customer_name'] ) ? $row['customer_name'] : '' ),
				sanitize_text_field( isset( $row['email'] ) ? $row['email'] : '' ),
				sanitize_text_field( isset( $row['phone'] ) ? $row['phone'] : '' ),
				$created_timestamp,
				absint( $reservation_id ),
			)
		);
	}

	/**
	 * Extract submission token from stored notes.
	 *
	 * @param string $notes Notes value.
	 * @return string
	 */
	private function extract_submission_token_from_notes( $notes ) {
		if ( preg_match( '/Submission token:\s*([a-f0-9-]+)/i', (string) $notes, $matches ) ) {
			return sanitize_text_field( $matches[1] );
		}

		return '';
	}

	/**
	 * Extract reservation setup ID from stored notes.
	 *
	 * @param string $notes Notes value.
	 * @return int
	 */
	private function extract_reservation_id_from_notes( $notes ) {
		if ( preg_match( '/Reservation setup ID:\s*(\d+)/', (string) $notes, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Get the stored invoice type label from order notes.
	 *
	 * @param string $notes Notes value.
	 * @return string
	 */
	private function get_invoice_type_label( $notes ) {
		if ( preg_match( '/Invoice Type:\s*(admin|manual|customer)/i', (string) $notes, $matches ) ) {
			$type = strtolower( trim( $matches[1] ) );

			if ( in_array( $type, array( 'admin', 'manual' ), true ) ) {
				return __( 'Admin', 'equine-event-manager' );
			}
		}

		return __( 'Customer', 'equine-event-manager' );
	}

	/**
	 * Get the display status used for grouped orders.
	 *
	 * @param string $payment_status Payment status.
	 * @param string $notes          Order notes.
	 * @return array{label:string,slug:string}
	 */
	private function get_order_status_display( $payment_status, $notes ) {
		$status = sanitize_key( (string) $payment_status );

		if ( 'partially_refunded' === $status ) {
			return array(
				'label' => __( 'Partially Refunded', 'equine-event-manager' ),
				'slug'  => 'partially-refunded',
			);
		}

		if ( 'refunded' === $status ) {
			return array(
				'label' => __( 'Refunded', 'equine-event-manager' ),
				'slug'  => 'refunded',
			);
		}

		if ( in_array( $status, array( 'paid', 'captured', 'completed', 'partially_paid' ), true ) ) {
			return array(
				'label' => __( 'Paid', 'equine-event-manager' ),
				'slug'  => 'paid',
			);
		}

		if ( in_array( $status, array( 'invoice_sent', 'sent' ), true ) || preg_match( '/(?:^|\n)Invoice Status:\s*Sent(?:\n|$)/i', (string) $notes ) ) {
			return array(
				'label' => __( 'Invoice Sent', 'equine-event-manager' ),
				'slug'  => 'invoice-sent',
			);
		}

		if ( preg_match( '/(?:^|\n)Show Bill Status:\s*Outstanding(?:\n|$)/i', (string) $notes ) ) {
			return array(
				'label' => __( 'Show Bill', 'equine-event-manager' ),
				'slug'  => 'outstanding-show-bill',
			);
		}

		return array(
			'label' => __( 'Unpaid', 'equine-event-manager' ),
			'slug'  => 'unpaid',
		);
	}

	/**
	 * Resolve event name for an order row.
	 *
	 * @param array $row Reservation DB row.
	 * @param array $reservation_index Reservation lookup data.
	 * @return string
	 */
	private function get_event_name( $row, $reservation_index ) {
		$reservation_id = $this->extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );

		if ( $reservation_id && isset( $reservation_index[ $reservation_id ]['event_name'] ) ) {
			return $reservation_index[ $reservation_id ]['event_name'];
		}

		if ( ! empty( $row['event_id'] ) ) {
			$title = get_the_title( absint( $row['event_id'] ) );

			if ( $title ) {
				return $title;
			}
		}

		if ( ! empty( $row['external_event_id'] ) ) {
			return sprintf(
				/* translators: %s: external event ID. */
				__( 'External Event %s', 'equine-event-manager' ),
				$row['external_event_id']
			);
		}

		return __( 'Unassigned Event', 'equine-event-manager' );
	}

	/**
	 * Get formatted event dates for an order row.
	 *
	 * @param array $row Reservation DB row.
	 * @param array $reservation_index Reservation lookup data.
	 * @return string
	 */
	private function get_event_dates_label( $row, $reservation_index ) {
		$reservation_id = $this->extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );

		if ( $reservation_id && isset( $reservation_index[ $reservation_id ]['event_dates'] ) ) {
			return $reservation_index[ $reservation_id ]['event_dates'];
		}

		if ( ! empty( $row['arrival_date'] ) && ! empty( $row['departure_date'] ) ) {
			return $this->format_date_range( $row['arrival_date'], $row['departure_date'] );
		}

		return __( 'Dates unavailable', 'equine-event-manager' );
	}

	/**
	 * Build reservation lookup data.
	 *
	 * @return array
	 */
	private function get_reservation_index() {
		$reservations = get_posts(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$index        = array();

		foreach ( $reservations as $reservation_id ) {
			$reservation_title = get_the_title( $reservation_id );
			$start_date  = get_post_meta( $reservation_id, '_en_available_start_date', true );
			$end_date    = get_post_meta( $reservation_id, '_en_available_end_date', true );

			$index[ $reservation_id ] = array(
				'title'       => $reservation_title,
				'event_name'  => $reservation_title ? $reservation_title : __( 'Unassigned Event', 'equine-event-manager' ),
				'event_dates' => $this->format_date_range( $start_date, $end_date ),
			);
		}

		return $index;
	}

	/**
	 * Format a date range label.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return string
	 */
	private function format_date_range( $start_date, $end_date ) {
		if ( empty( $start_date ) ) {
			return __( 'Dates unavailable', 'equine-event-manager' );
		}

		$timezone = wp_timezone();
		$start    = date_create_immutable_from_format( '!Y-m-d', (string) $start_date, $timezone );

		if ( ! $start ) {
			return __( 'Dates unavailable', 'equine-event-manager' );
		}

		$formatted_start = wp_date( get_option( 'date_format' ), $start->getTimestamp(), $timezone );

		if ( empty( $end_date ) || $start_date === $end_date ) {
			return $formatted_start;
		}

		$end = date_create_immutable_from_format( '!Y-m-d', (string) $end_date, $timezone );

		if ( ! $end ) {
			return $formatted_start;
		}

		return sprintf(
			/* translators: 1: start date, 2: end date. */
			__( '%1$s - %2$s', 'equine-event-manager' ),
			$formatted_start,
			wp_date( get_option( 'date_format' ), $end->getTimestamp(), $timezone )
		);
	}

	/**
	 * Sort grouped orders for the Orders screen.
	 *
	 * @param array  $orders Grouped orders.
	 * @param string $orderby Sort key.
	 * @param string $order Sort direction.
	 * @return array
	 */
	private function sort_orders( $orders, $orderby, $order ) {
		$orderby = sanitize_key( $orderby );
		$order   = 'asc' === strtolower( (string) $order ) ? 'asc' : 'desc';
		$fields  = array(
			'order'     => 'order_number',
			'event'     => 'event_name',
			'customer'  => 'customer_name',
			'type'      => 'type',
			'stall_qty' => 'stall_quantity',
			'rv_qty'    => 'rv_quantity',
			'total'     => 'total',
			'status'    => 'payment_status',
			'date'      => 'created_at',
		);

		if ( ! isset( $fields[ $orderby ] ) ) {
			$orderby = 'date';
		}

		$field = $fields[ $orderby ];

		usort(
			$orders,
			function ( $left, $right ) use ( $field, $orderby, $order ) {
				$left_value  = isset( $left[ $field ] ) ? $left[ $field ] : '';
				$right_value = isset( $right[ $field ] ) ? $right[ $field ] : '';

				if ( in_array( $orderby, array( 'stall_qty', 'rv_qty', 'total' ), true ) ) {
					$comparison = (float) $left_value <=> (float) $right_value;
				} else {
					$comparison = strcasecmp( (string) $left_value, (string) $right_value );
				}

				return 'asc' === $order ? $comparison : -1 * $comparison;
			}
		);

		return $orders;
	}

	/**
	 * Determine whether a row notes payload includes general add-on lines.
	 *
	 * @param array $row Raw reservation row.
	 * @return bool
	 */
	private function notes_include_general_add_ons( $row ) {
		$notes = isset( $row['notes'] ) ? (string) $row['notes'] : '';

		return '' !== $notes && preg_match( '/(?:^|\n)Add-On:\s*.+$/mi', $notes );
	}

	/**
	 * Determine whether an order matches a search term.
	 *
	 * @param array  $order Grouped order payload.
	 * @param string $search_term Search term.
	 * @return bool
	 */
	private function order_matches_search_term( $order, $search_term ) {
		$search_term = trim( (string) $search_term );

		if ( '' === $search_term ) {
			return true;
		}

		$needle        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search_term ) : strtolower( $search_term );
		$needle_digits = preg_replace( '/\D+/', '', $search_term );
		$haystacks     = array(
			isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '',
			isset( $order['email'] ) ? (string) $order['email'] : '',
			isset( $order['phone'] ) ? (string) $order['phone'] : '',
			isset( $order['order_number'] ) ? (string) $order['order_number'] : '',
			isset( $order['reservation_title'] ) ? (string) $order['reservation_title'] : '',
			isset( $order['event_name'] ) ? (string) $order['event_name'] : '',
			isset( $order['notes'] ) ? (string) $order['notes'] : '',
		);

		foreach ( (array) $order['components'] as $component ) {
			if ( ! empty( $component['notes'] ) ) {
				$haystacks[] = (string) $component['notes'];
			}

			if ( ! empty( $component['transaction_id'] ) ) {
				$haystacks[] = (string) $component['transaction_id'];
			}

			if ( ! empty( $component['refund_transaction_id'] ) ) {
				$haystacks[] = (string) $component['refund_transaction_id'];
			}
		}

		foreach ( $haystacks as $haystack ) {
			$haystack = (string) $haystack;

			if ( '' === $haystack ) {
				continue;
			}

			$haystack_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

			if ( false !== strpos( $haystack_lower, $needle ) ) {
				return true;
			}

			if ( '' !== $needle_digits ) {
				$haystack_digits = preg_replace( '/\D+/', '', $haystack );

				if ( '' !== $haystack_digits && false !== strpos( $haystack_digits, $needle_digits ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Determine whether a row notes payload includes a group reservation marker.
	 *
	 * @param array $row Raw reservation row.
	 * @return bool
	 */
	private function notes_include_group_reservation( $row ) {
		$notes = isset( $row['notes'] ) ? (string) $row['notes'] : '';

		return '' !== $notes && preg_match( '/(?:^|\n)Group Reservation:\s*Yes$/mi', $notes );
	}

	/**
	 * Return lightweight diagnostics for empty-state admin troubleshooting.
	 *
	 * @return array<string, mixed>
	 */
	public function get_diagnostics() {
		global $wpdb;

		$stall_tables = $this->get_component_table_candidates( 'stall' );
		$rv_tables    = $this->get_component_table_candidates( 'rv' );
		$counts       = array(
			'reservation_posts' => 0,
			'stall_rows'        => 0,
			'rv_rows'           => 0,
			'stall_tables'      => $stall_tables,
			'rv_tables'         => $rv_tables,
		);

		$reservation_counts = wp_count_posts( 'en_reservation' );

		if ( $reservation_counts ) {
			foreach ( array( 'publish', 'draft', 'private', 'future', 'pending' ) as $status_key ) {
				$counts['reservation_posts'] += isset( $reservation_counts->{$status_key} ) ? (int) $reservation_counts->{$status_key} : 0;
			}
		}

		foreach ( $stall_tables as $table_name ) {
			$counts['stall_rows'] += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		}

		foreach ( $rv_tables as $table_name ) {
			$counts['rv_rows'] += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		}

		return $counts;
	}
}
