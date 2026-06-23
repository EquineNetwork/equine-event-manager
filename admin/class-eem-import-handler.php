<?php
/**
 * CSV Import Handler — processes uploaded CSV files and creates orders.
 *
 * Handles the Import / Export tab's CSV upload, preview, and order creation.
 * Orders are created with an IMP- prefix and marked as Paid.
 *
 * @package EEM_Plugin
 * @since   2.7.581
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Import_Handler {

	/**
	 * Handle the CSV upload and preview AJAX request.
	 *
	 * Parses the uploaded CSV, returns rows for preview before committing.
	 *
	 * @return void Sends JSON response.
	 */
	public static function ajax_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_import_csv', '_wpnonce' );

		if ( empty( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['csv_file']['error'] ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded or upload error.', 'equine-event-manager' ) ), 400 );
		}

		$file = sanitize_text_field( $_FILES['csv_file']['tmp_name'] );
		if ( ! is_uploaded_file( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'equine-event-manager' ) ), 400 );
		}

		$rows    = array();
		$headers = array();
		$handle  = fopen( $file, 'r' );
		if ( ! $handle ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the CSV file.', 'equine-event-manager' ) ), 400 );
		}

		$line_num = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$line_num++;
			if ( 1 === $line_num ) {
				$headers = array_map( 'trim', $row );
				continue;
			}
			if ( count( $row ) !== count( $headers ) ) {
				continue;
			}
			$rows[] = array_combine( $headers, $row );
		}
		fclose( $handle );

		/* Stash parsed data in a transient so the commit step can read it */
		$import_key = 'eem_import_' . wp_generate_password( 12, false );
		set_transient( $import_key, array( 'headers' => $headers, 'rows' => $rows ), HOUR_IN_SECONDS );

		wp_send_json_success( array(
			'import_key' => $import_key,
			'headers'    => $headers,
			'row_count'  => count( $rows ),
			'preview'    => array_slice( $rows, 0, 10 ),
		) );
	}

	/**
	 * Handle the import commit AJAX request.
	 *
	 * Creates orders from the previously previewed CSV data.
	 *
	 * @return void Sends JSON response.
	 */
	public static function ajax_commit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_import_csv', '_wpnonce' );

		$import_key     = isset( $_POST['import_key'] ) ? sanitize_text_field( wp_unslash( $_POST['import_key'] ) ) : '';
		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;

		if ( ! $import_key || ! $reservation_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing import key or reservation.', 'equine-event-manager' ) ), 400 );
		}

		$data = get_transient( $import_key );
		if ( ! $data || empty( $data['rows'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Import session expired. Please re-upload the CSV.', 'equine-event-manager' ) ), 400 );
		}

		/* Column mapping from POST */
		$map = isset( $_POST['column_map'] ) && is_array( $_POST['column_map'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['column_map'] ) )
			: array();

		$required = array( 'last_name', 'first_name' );
		foreach ( $required as $field ) {
			if ( empty( $map[ $field ] ) ) {
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: field name */
						__( 'Column mapping missing for required field: %s', 'equine-event-manager' ),
						$field
					),
				), 400 );
			}
		}

		$reservation = get_post( $reservation_id );
		if ( ! $reservation || 'en_reservation' !== $reservation->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reservation.', 'equine-event-manager' ) ), 400 );
		}

		/* Resolve event linkage from the reservation config */
		$cfg          = class_exists( 'EEM_Reservation_Config' ) ? EEM_Reservation_Config::for( $reservation_id ) : null;
		$event_source = $cfg ? $cfg->get( 'event_source' ) : 'native';
		$event_id     = $cfg ? (int) $cfg->get( 'event_id' ) : 0;

		$result = self::create_orders( $data['rows'], $map, $reservation_id, $event_source, $event_id );

		delete_transient( $import_key );

		wp_send_json_success( array(
			'message'  => sprintf(
				/* translators: %d: number of orders created */
				__( '%d orders imported successfully.', 'equine-event-manager' ),
				$result['created']
			),
			'created'  => $result['created'],
			'skipped'  => $result['skipped'],
			'errors'   => $result['errors'],
		) );
	}

	/**
	 * Create orders from parsed CSV rows.
	 *
	 * @param array  $rows           Parsed CSV rows (assoc arrays).
	 * @param array  $map            Column mapping: field_name => csv_header_name.
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $event_source   Event source (native/tec/gems).
	 * @param int    $event_id       Event post ID.
	 * @return array{created: int, skipped: int, errors: list<string>}
	 */
	private static function create_orders( array $rows, array $map, int $reservation_id, string $event_source, int $event_id ): array {
		global $wpdb;

		$stall_table = $wpdb->prefix . 'en_stall_reservations';
		$rv_table    = $wpdb->prefix . 'en_rv_reservations';

		$created = 0;
		$skipped = 0;
		$errors  = array();

		/* Get the next order number from the repo */
		$next_num = absint( get_option( 'equine_event_manager_next_order_number', 1 ) );

		foreach ( $rows as $i => $row ) {
			$last_name  = trim( self::mapped_val( $row, $map, 'last_name' ) );
			$first_name = trim( self::mapped_val( $row, $map, 'first_name' ) );

			if ( '' === $last_name && '' === $first_name ) {
				$skipped++;
				continue;
			}

			$customer_name = $last_name . ', ' . $first_name;
			$email         = sanitize_email( self::mapped_val( $row, $map, 'email' ) );
			$phone         = sanitize_text_field( self::mapped_val( $row, $map, 'phone' ) );
			$stall_qty     = absint( self::mapped_val( $row, $map, 'stall_qty' ) );
			$stall_dates   = sanitize_text_field( self::mapped_val( $row, $map, 'stall_dates' ) );
			$shavings_qty  = absint( self::mapped_val( $row, $map, 'shavings_qty' ) );
			$shavings_total = (float) str_replace( array( '$', ',' ), '', self::mapped_val( $row, $map, 'shavings_total' ) );
			$rv_qty        = absint( self::mapped_val( $row, $map, 'rv_qty' ) );
			$rv_dates      = sanitize_text_field( self::mapped_val( $row, $map, 'rv_dates' ) );
			$rv_total      = (float) str_replace( array( '$', ',' ), '', self::mapped_val( $row, $map, 'rv_total' ) );
			$rv_price_each = (float) str_replace( array( '$', ',' ), '', self::mapped_val( $row, $map, 'rv_price_each' ) );
			$conf_numbers  = sanitize_text_field( self::mapped_val( $row, $map, 'confirmation_numbers' ) );
			$notes_text    = sanitize_text_field( self::mapped_val( $row, $map, 'notes' ) );

			if ( ! $stall_qty && ! $rv_qty ) {
				$skipped++;
				continue;
			}

			$order_number = 'IMP-' . str_pad( (string) $next_num, 5, '0', STR_PAD_LEFT );
			$next_num++;

			$token      = 'imp-' . md5( $order_number . $customer_name );
			$created_at = current_time( 'mysql' );

			/* Build notes */
			$notes_parts = array( 'Reservation setup ID: ' . $reservation_id, 'Submission token: ' . $token );
			if ( $conf_numbers ) {
				$notes_parts[] = 'Confirmation Numbers: ' . $conf_numbers;
			}
			if ( $notes_text ) {
				$notes_parts[] = $notes_text;
			}
			$notes = implode( "\n", $notes_parts );

			/* Stall row */
			if ( $stall_qty > 0 ) {
				$stall_subtotal = self::resolve_price_from_dates( $stall_dates, $reservation_id, 'stall', $stall_qty );

				$insert_result = $wpdb->insert( $stall_table, array(
					'event_source'            => $event_source,
					'event_id'                => $event_id,
					'reservation_id'          => $reservation_id,
					'customer_name'           => $customer_name,
					'email'                   => $email,
					'phone'                   => $phone,
					'stall_qty'               => $stall_qty,
					'additional_shavings_qty' => $shavings_qty,
					'stay_type'               => $stall_dates ? $stall_dates : 'Imported',
					'unit_price'              => number_format( $stall_qty > 0 ? $stall_subtotal / $stall_qty : 0, 2, '.', '' ),
					'subtotal'                => number_format( $stall_subtotal + $shavings_total, 2, '.', '' ),
					'convenience_fee'         => '0.00',
					'tax'                     => '0.00',
					'tax_rate'                => '0.000',
					'total'                   => number_format( $stall_subtotal + $shavings_total, 2, '.', '' ),
					'amount_paid'             => number_format( $stall_subtotal + $shavings_total, 2, '.', '' ),
					'payment_status'          => 'paid',
					'payment_gateway'         => 'manual',
					'order_number'            => $order_number,
					'transaction_id'          => '',
					'notes'                   => $notes,
					'created_at'              => $created_at,
				) );

				if ( false === $insert_result ) {
					$errors[] = sprintf( 'Row %d (%s): stall insert failed — %s', $i + 2, $customer_name, $wpdb->last_error );
					continue;
				}
			}

			/* RV row */
			if ( $rv_qty > 0 ) {
				$rv_subtotal = $rv_total > 0 ? $rv_total : ( $rv_price_each * $rv_qty );

				$insert_result = $wpdb->insert( $rv_table, array(
					'event_source'    => $event_source,
					'event_id'        => $event_id,
					'reservation_id'  => $reservation_id,
					'customer_name'   => $customer_name,
					'email'           => $email,
					'phone'           => $phone,
					'rv_qty'          => $rv_qty,
					'rv_type'         => '',
					'stay_type'       => $rv_dates ? $rv_dates : 'Imported',
					'unit_price'      => number_format( $rv_price_each, 2, '.', '' ),
					'subtotal'        => number_format( $rv_subtotal, 2, '.', '' ),
					'convenience_fee' => '0.00',
					'tax'             => '0.00',
					'tax_rate'        => '0.000',
					'total'           => number_format( $rv_subtotal, 2, '.', '' ),
					'amount_paid'     => number_format( $rv_subtotal, 2, '.', '' ),
					'payment_status'  => 'paid',
					'payment_gateway' => 'manual',
					'order_number'    => $order_number,
					'transaction_id'  => '',
					'notes'           => $notes,
					'created_at'      => $created_at,
				) );

				if ( false === $insert_result ) {
					$errors[] = sprintf( 'Row %d (%s): RV insert failed — %s', $i + 2, $customer_name, $wpdb->last_error );
					continue;
				}
			}

			$created++;
		}

		/* Update the order number counter */
		update_option( 'equine_event_manager_next_order_number', $next_num, false );

		return array( 'created' => $created, 'skipped' => $skipped, 'errors' => $errors );
	}

	/**
	 * Resolve stall/RV price from a stay-type date label by matching stay packages.
	 *
	 * Tries to match the CSV dates field (e.g. "Thursday-Sunday") to a stay
	 * package name on the reservation. Falls back to 0 if no match.
	 *
	 * @param string $dates_label     Date range label from CSV (e.g. "Thursday-Sunday").
	 * @param int    $reservation_id  Reservation post ID.
	 * @param string $type            'stall' or 'rv'.
	 * @param int    $qty             Quantity ordered.
	 * @return float Total price for the line (package price * qty).
	 */
	private static function resolve_price_from_dates( string $dates_label, int $reservation_id, string $type, int $qty ): float {
		if ( ! class_exists( 'EEM_Stay_Packages_Repo' ) || ! $dates_label ) {
			return 0.0;
		}

		$packages = EEM_Stay_Packages_Repo::get_packages( $reservation_id, $type );
		$label_lc = strtolower( trim( $dates_label ) );

		foreach ( $packages as $pkg ) {
			$pkg_name_lc = strtolower( trim( $pkg['name'] ?? '' ) );
			if ( false !== strpos( $pkg_name_lc, $label_lc ) || false !== strpos( $label_lc, $pkg_name_lc ) ) {
				return (float) $pkg['price'] * $qty;
			}
		}

		/* Try partial match: "Friday-Sunday" matches "Stall Fri-Sun" */
		$day_map = array(
			'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat', 'sunday' => 'sun',
			'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed',
		);
		$short_label = $label_lc;
		foreach ( $day_map as $long => $short ) {
			$short_label = str_replace( $long, $short, $short_label );
		}

		foreach ( $packages as $pkg ) {
			$pkg_name_lc = strtolower( trim( $pkg['name'] ?? '' ) );
			if ( false !== strpos( $pkg_name_lc, $short_label ) || false !== strpos( $short_label, $pkg_name_lc ) ) {
				return (float) $pkg['price'] * $qty;
			}
		}

		return 0.0;
	}

	/**
	 * Get a mapped value from a CSV row.
	 *
	 * @param array  $row CSV row (assoc array).
	 * @param array  $map Column mapping.
	 * @param string $field Internal field name.
	 * @return string
	 */
	private static function mapped_val( array $row, array $map, string $field ): string {
		$header = $map[ $field ] ?? '';
		if ( '' === $header || ! isset( $row[ $header ] ) ) {
			return '';
		}
		return (string) $row[ $header ];
	}
}
