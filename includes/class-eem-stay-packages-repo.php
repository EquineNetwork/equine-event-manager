<?php
/**
 * Stay Packages Repository.
 *
 * CRUD operations for the wp_eem_stay_packages table. Each package belongs to
 * a reservation and has a type (stall or rv). Used by the reservation editor
 * AJAX handlers and the frontend checkout renderer.
 *
 * @package EEM_Plugin
 * @since   2.7.334
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Stay_Packages_Repo {

	/**
	 * Get all packages for a reservation, optionally filtered by type.
	 *
	 * @param int         $reservation_id Reservation post ID.
	 * @param string|null $type           'stall', 'rv', or null for both.
	 * @return array<int, array>
	 */
	public static function get_packages( int $reservation_id, ?string $type = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stay_packages';

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE reservation_id = %d",
			$reservation_id
		);

		if ( null !== $type ) {
			$sql .= $wpdb->prepare( ' AND type = %s', $type );
		}

		$sql .= ' ORDER BY sort_order ASC, id ASC';

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a single package by ID.
	 *
	 * @param int $package_id Package row ID.
	 * @return array|null
	 */
	public static function get( int $package_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stay_packages';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $package_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert a new package.
	 *
	 * @param array $data Associative array with reservation_id, type, name, start_date, end_date, price, sort_order, max_quantity.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stay_packages';

		$result = $wpdb->insert(
			$table,
			array(
				'reservation_id' => (int) $data['reservation_id'],
				'type'           => sanitize_key( $data['type'] ?? 'stall' ),
				'name'           => sanitize_text_field( $data['name'] ?? '' ),
				'start_date'     => sanitize_text_field( $data['start_date'] ?? '' ),
				'end_date'       => sanitize_text_field( $data['end_date'] ?? '' ),
				'price'          => (float) ( $data['price'] ?? 0 ),
				'early_bird_price' => ( isset( $data['early_bird_price'] ) && '' !== $data['early_bird_price'] && null !== $data['early_bird_price'] ) ? (float) $data['early_bird_price'] : null,
				'sort_order'     => (int) ( $data['sort_order'] ?? 0 ),
				'max_quantity'   => (int) ( $data['max_quantity'] ?? 0 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d' )
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing package.
	 *
	 * @param int   $package_id Package row ID.
	 * @param array $data       Fields to update.
	 * @return bool
	 */
	public static function update( int $package_id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stay_packages';

		$update = array();
		$formats = array();

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
			$formats[]      = '%s';
		}
		if ( isset( $data['start_date'] ) ) {
			$update['start_date'] = sanitize_text_field( $data['start_date'] );
			$formats[]            = '%s';
		}
		if ( isset( $data['end_date'] ) ) {
			$update['end_date'] = sanitize_text_field( $data['end_date'] );
			$formats[]          = '%s';
		}
		if ( isset( $data['price'] ) ) {
			$update['price'] = (float) $data['price'];
			$formats[]       = '%f';
		}
		if ( array_key_exists( 'early_bird_price', $data ) ) {
			$eb = $data['early_bird_price'];
			// null = clear the early-bird price (wpdb writes NULL; the %f format is
			// ignored for null values).
			$update['early_bird_price'] = ( '' === $eb || null === $eb ) ? null : (float) $eb;
			$formats[]                  = '%f';
		}
		if ( isset( $data['sort_order'] ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$formats[]            = '%d';
		}
		if ( isset( $data['max_quantity'] ) ) {
			$update['max_quantity'] = (int) $data['max_quantity'];
			$formats[]              = '%d';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update( $table, $update, array( 'id' => $package_id ), $formats, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete a package.
	 *
	 * @param int $package_id Package row ID.
	 * @return bool
	 */
	public static function delete( int $package_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stay_packages';

		$result = $wpdb->delete( $table, array( 'id' => $package_id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Reorder packages by setting sort_order from an ordered array of IDs.
	 *
	 * @param int   $reservation_id Reservation post ID (ownership guard).
	 * @param int[] $ordered_ids    Package IDs in desired order.
	 * @return bool
	 */
	public static function reorder( int $reservation_id, array $ordered_ids ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_stay_packages';

		foreach ( $ordered_ids as $index => $id ) {
			$wpdb->update(
				$table,
				array( 'sort_order' => $index ),
				array( 'id' => (int) $id, 'reservation_id' => $reservation_id ),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}

		return true;
	}

	/**
	 * Count how many orders have selected a given package (for max_quantity enforcement).
	 *
	 * @param int    $package_id      Package row ID.
	 * @param string $reservation_table 'eem_stall_reservations' or 'eem_rv_reservations'.
	 * @return int
	 */
	public static function count_sold( int $package_id, string $reservation_table = 'eem_stall_reservations' ): int {
		global $wpdb;
		$table = $wpdb->prefix . sanitize_key( $reservation_table );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(
					CASE WHEN JSON_CONTAINS(selected_package_ids, CAST(%d AS JSON)) THEN stall_qty ELSE 0 END
				), 0) FROM {$table} WHERE trashed_at IS NULL",
				$package_id
			)
		);

		return (int) $count;
	}
}
