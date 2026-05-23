<?php
/**
 * Activity Log repository (ODET-7, CDET-5).
 *
 * Append-only event log scoped to orders and/or reservations. Drives the
 * Activity Log card on Order Detail (ODET-7) and the chart-move audit trail
 * on Stall Chart Detail (CDET-5).
 *
 * Schema lives in {prefix}en_activity_log, created/migrated by EEM_Activator.
 *
 * Read access via:
 *   EEM_Activity_Log::get_for_order( $order_id )
 *   EEM_Activity_Log::get_for_reservation( $reservation_id )
 *
 * Write access via:
 *   EEM_Activity_Log::write( EEM_Activity_Log::ORDER_EDITED, $payload, $context )
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static repository for the activity log custom table.
 */
class EEM_Activity_Log {

	/** ODET-7 + CDET-5 event-type constants. */
	const ORDER_CREATED                = 'order_created';
	const ORDER_EDITED                 = 'order_edited';
	const STATUS_CHANGED               = 'status_changed';
	const REFUND_PROCESSED             = 'refund_processed';
	const ASSIGNMENT_CHANGED           = 'assignment_changed';
	const NOTIFICATION_SENT            = 'notification_sent';
	const SPECIAL_INSTRUCTIONS_EDITED  = 'special_instructions_edited';

	/**
	 * Custom table name with site prefix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'en_activity_log';
	}

	/**
	 * Append a single activity entry.
	 *
	 * @param string $event_type One of the class constants. Free-form strings are
	 *                           accepted (stored as-is) so add-ons can record their
	 *                           own event types without modifying this class.
	 * @param array  $payload    Free-form key/value pairs describing what happened
	 *                           (before/after values, amounts, reasons, etc.).
	 *                           Stored as JSON; do not pass non-serializable objects.
	 * @param array  $context    Optional. {
	 *     @type int    $order_id       Order this entry belongs to.
	 *     @type int    $reservation_id Reservation context.
	 *     @type string $actor_type     'admin' | 'customer' | 'system'. Default 'system'.
	 *     @type int    $actor_id       WP user_id for admin/customer; null for system.
	 *     @type string $actor_label    Display name. Auto-resolves from actor_id if omitted.
	 *     @type string $created_at     MySQL DATETIME. Defaults to current_time( 'mysql', true ).
	 * }
	 * @return int|false  Newly-inserted row id, or false on error.
	 */
	public static function write( $event_type, array $payload = array(), array $context = array() ) {
		global $wpdb;

		$event_type = sanitize_key( (string) $event_type );
		if ( '' === $event_type ) {
			return false;
		}

		$context = wp_parse_args(
			$context,
			array(
				'order_id'       => null,
				'reservation_id' => null,
				'actor_type'     => 'system',
				'actor_id'       => null,
				'actor_label'    => '',
				'created_at'     => '',
			)
		);

		$actor_type = sanitize_key( (string) $context['actor_type'] );
		if ( ! in_array( $actor_type, array( 'admin', 'customer', 'system' ), true ) ) {
			$actor_type = 'system';
		}

		$actor_id    = $context['actor_id'] ? absint( $context['actor_id'] ) : null;
		$actor_label = (string) $context['actor_label'];

		// Resolve actor display label from the WP user when missing.
		if ( '' === $actor_label && $actor_id ) {
			$user        = get_userdata( $actor_id );
			$actor_label = $user ? $user->display_name : '';
		}

		$created_at = '' !== (string) $context['created_at']
			? (string) $context['created_at']
			: current_time( 'mysql', true );

		$row = array(
			'order_id'       => $context['order_id'] ? absint( $context['order_id'] ) : null,
			'reservation_id' => $context['reservation_id'] ? absint( $context['reservation_id'] ) : null,
			'event_type'     => $event_type,
			'payload'        => $payload ? wp_json_encode( $payload ) : null,
			'actor_type'     => $actor_type,
			'actor_id'       => $actor_id,
			'actor_label'    => $actor_label,
			'created_at'     => $created_at,
		);

		$formats = array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' );

		// Drop nullable keys when empty so wpdb writes NULL instead of 0/'' coercion.
		foreach ( array( 'order_id', 'reservation_id', 'actor_id', 'payload' ) as $maybe_null ) {
			if ( null === $row[ $maybe_null ] ) {
				unset( $row[ $maybe_null ] );
			}
		}

		// Rebuild formats array in lockstep with kept keys.
		$format_map = array(
			'order_id'       => '%d',
			'reservation_id' => '%d',
			'event_type'     => '%s',
			'payload'        => '%s',
			'actor_type'     => '%s',
			'actor_id'       => '%d',
			'actor_label'    => '%s',
			'created_at'     => '%s',
		);
		$formats = array_values( array_intersect_key( $format_map, $row ) );

		$result = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch entries for one order, newest first.
	 *
	 * @param int $order_id Order id.
	 * @param int $limit    Optional max rows. Default 100.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_order( $order_id, $limit = 100 ) {
		return self::query(
			array(
				'order_id' => absint( $order_id ),
				'limit'    => absint( $limit ),
			)
		);
	}

	/**
	 * Fetch entries for one reservation, newest first.
	 *
	 * @param int $reservation_id Reservation post id.
	 * @param int $limit          Optional max rows. Default 100.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_reservation( $reservation_id, $limit = 100 ) {
		return self::query(
			array(
				'reservation_id' => absint( $reservation_id ),
				'limit'          => absint( $limit ),
			)
		);
	}

	/**
	 * Run a parameterized fetch. Internal; callers use the get_for_* helpers.
	 *
	 * @param array $args See body for accepted keys.
	 * @return array<int, array<string, mixed>>
	 */
	private static function query( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'order_id'       => 0,
				'reservation_id' => 0,
				'limit'          => 100,
			)
		);

		$table = self::table_name();
		$where = array( '1=1' );
		$params = array();

		if ( $args['order_id'] > 0 ) {
			$where[]   = 'order_id = %d';
			$params[]  = (int) $args['order_id'];
		}

		if ( $args['reservation_id'] > 0 ) {
			$where[]  = 'reservation_id = %d';
			$params[] = (int) $args['reservation_id'];
		}

		$limit = $args['limit'] > 0 ? min( $args['limit'], 500 ) : 100;
		$params[] = $limit;

		$sql = "SELECT id, order_id, reservation_id, event_type, payload, actor_type, actor_id, actor_label, created_at
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY created_at DESC, id DESC
			LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Decode payload JSON for caller convenience.
		foreach ( $rows as &$row ) {
			$row['payload'] = isset( $row['payload'] ) && '' !== $row['payload']
				? json_decode( $row['payload'], true )
				: array();
			if ( ! is_array( $row['payload'] ) ) {
				$row['payload'] = array();
			}
		}
		unset( $row );

		return $rows;
	}
}
