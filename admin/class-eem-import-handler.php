<?php
/**
 * Import/Export Handler — CSV order import + full reservation setup JSON export/import.
 *
 * Handles the Import / Export tab's CSV upload, preview, and order creation.
 * Also handles full-setup JSON export/import for migrating reservations between sites.
 *
 * @package EEM_Plugin
 * @since   2.7.581
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_normalize_caps_name' ) ) {
	/**
	 * Normalize an accidental ALL-CAPS name to Title Case ("EVANS" -> "Evans").
	 *
	 * Only strings whose letters are ENTIRELY uppercase are changed — a name
	 * already containing any lowercase letter (McCann, Lopez Dugas, Anne-Claire,
	 * o'Brien) is assumed intentional and returned untouched. Strings with no
	 * letters (digits/punctuation only) are returned unchanged.
	 *
	 * Shared by the CSV importer (applied at import time) and migration #038
	 * (one-time cleanup of already-imported rows), hence a guarded global rather
	 * than a class method — migrations run before admin classes are loaded.
	 *
	 * @param string $name Raw name value.
	 * @return string Normalized name.
	 */
	function eem_normalize_caps_name( $name ) {
		$name    = (string) $name;
		$letters = preg_replace( '/[^\p{L}]/u', '', $name );
		if ( '' === $letters || $letters !== mb_strtoupper( $letters, 'UTF-8' ) ) {
			return $name; // No letters, or already has lowercase — leave as-is.
		}
		return mb_convert_case( mb_strtolower( $name, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
	}
}

if ( ! function_exists( 'eem_stay_day_tokens' ) ) {
	/**
	 * Extract the set of weekday abbreviations (sun..sat) present in a stay-type
	 * or package-name string. Handles full names ("Friday"), abbreviations
	 * ("Fri"), spaces/punctuation, and multiple days ("Friday-Sunday; Saturday-
	 * Sunday").
	 *
	 * @param string $s Stay-type label or package name.
	 * @return string[] Distinct day abbreviations found.
	 */
	function eem_stay_day_tokens( $s ) {
		$s     = strtolower( (string) $s );
		$names = array( 'sunday' => 'sun', 'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed', 'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat' );
		$found = array();
		foreach ( $names as $full => $ab ) {
			if ( false !== strpos( $s, $full ) ) { $found[ $ab ] = true; }
		}
		foreach ( array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ) as $ab ) {
			if ( preg_match( '/\b' . $ab . '\b/', $s ) ) { $found[ $ab ] = true; }
		}
		return array_keys( $found );
	}
}

if ( ! function_exists( 'eem_match_stay_package_dates' ) ) {
	/**
	 * Derive [arrival, departure] (Y-m-d) for an order from its stay-type label
	 * by matching the reservation's packages of the relevant component type. A
	 * package contributes when its day-token set is a subset of the order's
	 * tokens; arrival = earliest start, departure = latest end. Falls back to the
	 * widest package window when the stay-type carries no day names (e.g.
	 * "Imported") or nothing matches.
	 *
	 * @param string                       $stay_type Order stay-type label.
	 * @param array<int,array<string,mixed>> $packages Packages (name/start_date/end_date) of the relevant type.
	 * @return array{0:string,1:string} [arrival, departure]; ['',''] when undeterminable.
	 */
	function eem_match_stay_package_dates( $stay_type, array $packages ) {
		if ( empty( $packages ) ) { return array( '', '' ); }
		$order_tokens = eem_stay_day_tokens( $stay_type );
		$starts = array();
		$ends   = array();
		foreach ( $packages as $p ) {
			$ps = (string) ( $p['start_date'] ?? '' );
			$pe = (string) ( $p['end_date'] ?? '' );
			if ( '' === $ps || '' === $pe ) { continue; }
			if ( empty( $order_tokens ) ) {
				$starts[] = $ps; $ends[] = $pe; // No day info — consider all.
				continue;
			}
			$pkg_tokens = eem_stay_day_tokens( (string) ( $p['name'] ?? '' ) );
			if ( ! empty( $pkg_tokens ) && array() === array_diff( $pkg_tokens, $order_tokens ) ) {
				$starts[] = $ps; $ends[] = $pe;
			}
		}
		if ( empty( $starts ) ) {
			foreach ( $packages as $p ) {
				if ( ! empty( $p['start_date'] ) ) { $starts[] = (string) $p['start_date']; }
				if ( ! empty( $p['end_date'] ) )   { $ends[]   = (string) $p['end_date']; }
			}
		}
		if ( empty( $starts ) || empty( $ends ) ) { return array( '', '' ); }
		sort( $starts );
		sort( $ends );
		return array( $starts[0], end( $ends ) );
	}
}

class EEM_Import_Handler {

	/**
	 * Maximum accepted upload size for CSV / JSON imports, in bytes (A2).
	 *
	 * @var int
	 */
	const MAX_UPLOAD_BYTES = 5242880; // 5 MB.

	/**
	 * Validate an uploaded import file before it is read (A2).
	 *
	 * Confirms the entry is a genuine HTTP upload, is within the size cap, and
	 * carries an allowed extension. Returns the verified tmp path on success or
	 * a WP_Error describing the rejection. Centralizes the checks the CSV and
	 * JSON upload handlers previously each did partially (tmp_name + is_uploaded_file
	 * only — no size or extension gate).
	 *
	 * @param array<string,mixed> $file_entry   A single $_FILES[...] entry.
	 * @param string[]            $allowed_exts Lowercase extensions to accept, e.g. array( 'csv' ).
	 * @return string|WP_Error Verified uploaded tmp-file path, or WP_Error on rejection.
	 */
	private static function validate_upload_file( array $file_entry, array $allowed_exts ) {
		if ( empty( $file_entry ) || UPLOAD_ERR_OK !== (int) ( $file_entry['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'eem_upload_error', __( 'No file uploaded or upload error.', 'equine-event-manager' ) );
		}

		$tmp = isset( $file_entry['tmp_name'] ) ? sanitize_text_field( $file_entry['tmp_name'] ) : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'eem_upload_invalid', __( 'Invalid upload.', 'equine-event-manager' ) );
		}

		$size = (int) ( $file_entry['size'] ?? 0 );
		if ( $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error(
				'eem_upload_too_large',
				sprintf(
					/* translators: %d: maximum upload size in megabytes. */
					__( 'The file is empty or larger than the %d MB import limit.', 'equine-event-manager' ),
					(int) round( self::MAX_UPLOAD_BYTES / 1048576 )
				)
			);
		}

		$name = isset( $file_entry['name'] ) ? sanitize_file_name( (string) $file_entry['name'] ) : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array_map( 'strtolower', $allowed_exts ), true ) ) {
			return new WP_Error(
				'eem_upload_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of accepted file extensions. */
					__( 'Unsupported file type. Accepted: %s.', 'equine-event-manager' ),
					strtoupper( implode( ', ', $allowed_exts ) )
				)
			);
		}

		return $tmp;
	}

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

		// A2 — validate the upload (genuine upload + size cap + .csv extension)
		// before reading a single byte.
		$file = self::validate_upload_file( isset( $_FILES['csv_file'] ) ? (array) $_FILES['csv_file'] : array(), array( 'csv' ) );
		if ( is_wp_error( $file ) ) {
			wp_send_json_error( array( 'message' => $file->get_error_message() ), 400 );
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

		$stall_table = $wpdb->prefix . 'eem_stall_reservations';
		$rv_table    = $wpdb->prefix . 'eem_rv_reservations';

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

			// Normalize accidental ALL-CAPS entries (e.g. "EVANS" -> "Evans");
			// names already entered in mixed case (McCann, Lopez Dugas) are left
			// exactly as-is. See eem_normalize_caps_name().
			$last_name     = eem_normalize_caps_name( $last_name );
			$first_name    = eem_normalize_caps_name( $first_name );
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

			if ( ! $stall_qty && ! $rv_qty && ! $shavings_qty ) {
				$skipped++;
				continue;
			}

			$order_number = 'IMP-' . str_pad( (string) $next_num, 5, '0', STR_PAD_LEFT );
			$next_num++;

			// 4.1 (IDOR): the order's submission token becomes its bearer order_key
			// (md5 of the token). The old 'imp-' . md5(order_number+name) was doubly
			// broken — it was GUESSABLE (sequential IMP number + known name), AND the
			// 'imp-' prefix isn't hex so extract_submission_token_from_notes() (regex
			// [a-f0-9-]+) never even matched it, so imported orders fell through to the
			// GUESSABLE event+name+phone+timestamp composite group key. A pure uuid4 is
			// both high-entropy AND extractable, so every imported order now gets an
			// unguessable order_key exactly like a real checkout submission token.
			$token      = wp_generate_uuid4();
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

			/* Stall row (also created when stall_qty=0 but shavings exist) */
			if ( $stall_qty > 0 || $shavings_qty > 0 ) {
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
					// A2 — don't surface $wpdb->last_error (leaks schema/query
					// structure); report the row with a generic message.
					$errors[] = sprintf(
						/* translators: 1: CSV row number, 2: customer name. */
						__( 'Row %1$d (%2$s): stall insert failed.', 'equine-event-manager' ),
						$i + 2,
						$customer_name
					);
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
					// A2 — generic message; do not echo $wpdb->last_error.
					$errors[] = sprintf(
						/* translators: 1: CSV row number, 2: customer name. */
						__( 'Row %1$d (%2$s): RV insert failed.', 'equine-event-manager' ),
						$i + 2,
						$customer_name
					);
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

	/**
	 * Export a full reservation setup as JSON (event + venue + config + packages + orders).
	 *
	 * Streams a JSON file download directly to the browser.
	 *
	 * @return void
	 */
	public static function ajax_export_setup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'equine-event-manager' ) );
		}
		check_admin_referer( 'eem_export_setup' );

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( $_GET['reservation_id'] ) : 0;
		if ( ! $reservation_id ) {
			wp_die( esc_html__( 'Missing reservation ID.', 'equine-event-manager' ) );
		}

		$reservation = get_post( $reservation_id );
		if ( ! $reservation || 'en_reservation' !== $reservation->post_type ) {
			wp_die( esc_html__( 'Invalid reservation.', 'equine-event-manager' ) );
		}

		// Section selection from the export UI checkboxes. An unchecked box is
		// simply absent from the query string, so each flag defaults to OFF and is
		// only ON when its param is explicitly '1'. The reservation setup + map
		// always export regardless.
		$include = array(
			'event'       => isset( $_GET['include_event'] ) && '1' === $_GET['include_event'],
			'reservation' => isset( $_GET['include_reservation'] ) && '1' === $_GET['include_reservation'],
			'orders'      => isset( $_GET['include_orders'] ) && '1' === $_GET['include_orders'],
		);

		$export = self::build_export( $reservation_id, $include );

		$filename = sanitize_file_name( 'eem-setup-' . $reservation->post_title . '-' . gmdate( 'Y-m-d' ) . '.json' );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache' );

		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Build the full export array for a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array Complete setup data.
	 */
	private static function build_export( int $reservation_id, array $include = array() ): array {
		global $wpdb;

		// Which sections to bundle, chosen via the export UI checkboxes:
		//   event       — the linked event + its venue
		//   reservation — the reservation setup: stall/RV map + blocked units +
		//                  pricing config + stay packages (these always travel
		//                  together; packages are part of the reservation)
		//   orders      — stall + RV orders (customer PII)
		// Defaults to everything for backward-compatible callers.
		$include = wp_parse_args( $include, array(
			'event'       => true,
			'reservation' => true,
			'orders'      => true,
		) );

		// Orders reference their reservation setup, so they can only be exported
		// alongside it — you can't import orders that have nothing to attach to.
		if ( ! $include['reservation'] ) {
			$include['orders'] = false;
		}

		$reservation = get_post( $reservation_id );
		$res_meta    = get_post_meta( $reservation_id );

		$cfg      = class_exists( 'EEM_Reservation_Config' ) ? EEM_Reservation_Config::for( $reservation_id ) : null;
		$cfg_data = $cfg ? $cfg->all() : array();

		$export = array(
			'export_version' => '1.1',
			'exported_at'    => current_time( 'c' ),
			'plugin_version' => defined( 'EEM_VERSION' ) ? EEM_VERSION : 'unknown',
			'included'       => array(
				'event'       => (bool) $include['event'],
				'reservation' => (bool) $include['reservation'],
				'orders'      => (bool) $include['orders'],
			),
		);

		/* ── Event + venue (optional) ─────────────────────────────── */
		if ( $include['event'] ) {
			$event_id = (int) ( $cfg_data['event_id'] ?? 0 );
			$event    = $event_id ? get_post( $event_id ) : null;

			$venue_id = $event ? (int) get_post_meta( $event_id, '_equine_event_manager_event_venue_id', true ) : 0;
			$venue    = $venue_id ? get_post( $venue_id ) : null;

			$producer_id = $event ? (int) get_post_meta( $event_id, '_equine_event_manager_event_producer_id', true ) : 0;
			$producer    = $producer_id ? get_post( $producer_id ) : null;

			if ( $venue ) {
				$export['venue'] = array(
					'title' => $venue->post_title,
					'meta'  => self::flatten_meta( get_post_meta( $venue_id ) ),
				);
			}
			if ( $producer ) {
				$export['producer'] = array(
					'title' => $producer->post_title,
					'meta'  => self::flatten_meta( get_post_meta( $producer_id ) ),
				);
			}
			if ( $event ) {
				$export['event'] = array(
					'title' => $event->post_title,
					'meta'  => self::flatten_meta( get_post_meta( $event_id ) ),
				);
			}
		}

		/* ── Reservation setup: map + config + packages (optional) ── */
		if ( $include['reservation'] ) {
			$export['reservation'] = array(
				'title'  => $reservation->post_title,
				'status' => $reservation->post_status,
				'meta'   => self::flatten_meta( $res_meta ),
			);
			$export['config']   = $cfg_data;
			$export['packages'] = class_exists( 'EEM_Stay_Packages_Repo' )
				? EEM_Stay_Packages_Repo::get_packages( $reservation_id )
				: array();
		}

		/* ── Orders + customer data (optional) ────────────────────── */
		if ( $include['orders'] ) {
			$stall_table = $wpdb->prefix . 'eem_stall_reservations';
			$rv_table    = $wpdb->prefix . 'eem_rv_reservations';

			$export['stall_orders'] = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$stall_table} WHERE reservation_id = %d AND trashed_at IS NULL ORDER BY id ASC",
				$reservation_id
			), ARRAY_A ) ?: array();

			$export['rv_orders'] = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$rv_table} WHERE reservation_id = %d AND trashed_at IS NULL ORDER BY id ASC",
				$reservation_id
			), ARRAY_A ) ?: array();
		} else {
			$export['stall_orders'] = array();
			$export['rv_orders']    = array();
		}

		return $export;
	}

	/**
	 * Flatten WordPress post meta array (strips single-value arrays).
	 *
	 * @param array $meta Raw get_post_meta() result.
	 * @return array Flattened meta.
	 */
	private static function flatten_meta( array $meta ): array {
		$flat = array();
		foreach ( $meta as $key => $values ) {
			if ( strpos( $key, '_edit_' ) === 0 || $key === '_wp_old_slug' ) {
				continue;
			}
			$flat[ $key ] = count( $values ) === 1 ? $values[0] : $values;
		}
		return $flat;
	}

	/**
	 * Import a full reservation setup from a JSON file upload.
	 *
	 * Creates venue, event, reservation, config, packages, and orders.
	 *
	 * @return void Sends JSON response.
	 */
	public static function ajax_import_setup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_import_csv', '_wpnonce' );

		// A2 — validate the upload (genuine upload + size cap + .json extension)
		// before reading it.
		$file = self::validate_upload_file( isset( $_FILES['json_file'] ) ? (array) $_FILES['json_file'] : array(), array( 'json' ) );
		if ( is_wp_error( $file ) ) {
			wp_send_json_error( array( 'message' => $file->get_error_message() ), 400 );
		}

		$json = file_get_contents( $file );
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['reservation'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON file — missing reservation data.', 'equine-event-manager' ) ), 400 );
		}

		$result = self::import_setup( $data );

		wp_send_json_success( $result );
	}

	/**
	 * 4.3: only post-meta keys in the plugin's OWN namespace may be written from an
	 * imported file. The import loops otherwise did blind update_post_meta() with
	 * attacker-controlled keys, so a poisoned JSON could set arbitrary WP/other-plugin
	 * meta (capabilities, _edit_lock, _wp_*, etc.). JSON can't encode PHP objects, so
	 * restricting the KEY namespace is the proportionate guard.
	 *
	 * @param string $key Candidate meta key.
	 * @return bool
	 */
	private static function is_importable_meta_key( string $key ): bool {
		foreach ( array( '_en_', '_eem_', '_equine_event_manager_' ) as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 4.3: the real columns of a component table (minus the auto-increment PK), used
	 * to filter an imported order row so a poisoned file can't write to unexpected
	 * columns. Reads the live schema so it can't drift out of sync.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return string[] Column names (excluding `id`).
	 */
	private static function table_columns( string $table ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal prefix + literal, not user input.
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', 0 );
		return array_values( array_filter( (array) $cols, static function ( $c ) {
			return 'id' !== $c;
		} ) );
	}

	/**
	 * Perform the full setup import from parsed JSON data.
	 *
	 * @param array $data Parsed export JSON.
	 * @return array Summary of created entities.
	 */
	private static function import_setup( array $data ): array {
		global $wpdb;

		$summary     = array();
		$venue_id    = 0;
		$producer_id = 0;
		$event_id    = 0;

		/* ── Venue ─────────────────────────────────────────────── */
		if ( ! empty( $data['venue'] ) ) {
			$venue_id = (int) wp_insert_post( array(
				'post_type'   => 'en_venue',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $data['venue']['title'] ?? 'Imported Venue' ),
			) );
			if ( $venue_id && ! empty( $data['venue']['meta'] ) ) {
				foreach ( $data['venue']['meta'] as $key => $val ) {
					if ( ! self::is_importable_meta_key( (string) $key ) ) {
						continue;
					}
					update_post_meta( $venue_id, $key, $val );
				}
			}
			if ( class_exists( 'EEM_Venue' ) && method_exists( 'EEM_Venue', 'sync_post_to_table' ) ) {
				EEM_Venue::sync_post_to_table( $venue_id );
			}
			$summary['venue_id'] = $venue_id;
		}

		/* ── Producer ──────────────────────────────────────────── */
		if ( ! empty( $data['producer'] ) ) {
			$producer_id = (int) wp_insert_post( array(
				'post_type'   => 'en_producer',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $data['producer']['title'] ?? 'Imported Producer' ),
			) );
			if ( $producer_id && ! empty( $data['producer']['meta'] ) ) {
				foreach ( $data['producer']['meta'] as $key => $val ) {
					if ( ! self::is_importable_meta_key( (string) $key ) ) {
						continue;
					}
					update_post_meta( $producer_id, $key, $val );
				}
			}
			$summary['producer_id'] = $producer_id;
		}

		/* ── Event ─────────────────────────────────────────────── */
		if ( ! empty( $data['event'] ) ) {
			$event_id = (int) wp_insert_post( array(
				'post_type'   => 'en_event',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $data['event']['title'] ?? 'Imported Event' ),
			) );
			if ( $event_id && ! empty( $data['event']['meta'] ) ) {
				foreach ( $data['event']['meta'] as $key => $val ) {
					if ( ! self::is_importable_meta_key( (string) $key ) ) {
						continue;
					}
					if ( $key === '_equine_event_manager_event_venue_id' && $venue_id ) {
						$val = $venue_id;
					}
					if ( $key === '_equine_event_manager_event_producer_id' && $producer_id ) {
						$val = $producer_id;
					}
					update_post_meta( $event_id, $key, $val );
				}
			}
			// #16: TEC/feed-sourced events don't store their start/end in en_event
			// post-meta (the dates live in the upstream calendar), so a cloned event
			// imports dateless even though the reservation's date window is known.
			// Backfill the cloned event's display dates from the export's
			// available_start/end window when the event has none — fills empties only,
			// never overwrites a real event date.
			$cfg_avail_start = isset( $data['config']['available_start_date'] ) ? (string) $data['config']['available_start_date'] : '';
			$cfg_avail_end   = isset( $data['config']['available_end_date'] ) ? (string) $data['config']['available_end_date'] : '';
			if ( '' !== $cfg_avail_start && '' === (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true ) ) {
				update_post_meta( $event_id, '_equine_event_manager_event_start_date', $cfg_avail_start );
			}
			if ( '' !== $cfg_avail_end && '' === (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true ) ) {
				update_post_meta( $event_id, '_equine_event_manager_event_end_date', $cfg_avail_end );
			}
			if ( class_exists( 'EEM_Native_Event_Repo' ) && method_exists( 'EEM_Native_Event_Repo', 'save' ) ) {
				$start = get_post_meta( $event_id, '_equine_event_manager_event_start_date', true );
				$end   = get_post_meta( $event_id, '_equine_event_manager_event_end_date', true );
				EEM_Native_Event_Repo::save( $event_id, array(
					'start_date'     => $start,
					'end_date'       => $end,
					'venue_id'       => $venue_id,
					'location_label' => get_post_meta( $event_id, '_equine_event_manager_event_location_label', true ),
					'featured'       => (int) get_post_meta( $event_id, '_equine_event_manager_event_featured', true ),
				) );
			}
			$summary['event_id'] = $event_id;
		}

		/* ── Reservation ───────────────────────────────────────── */
		$res_data = $data['reservation'];
		$reservation_id = (int) wp_insert_post( array(
			'post_type'   => 'en_reservation',
			'post_status' => sanitize_text_field( $res_data['status'] ?? 'publish' ),
			'post_title'  => sanitize_text_field( $res_data['title'] ?? 'Imported Reservation' ),
		) );

		if ( ! empty( $res_data['meta'] ) ) {
			foreach ( $res_data['meta'] as $key => $val ) {
				if ( ! self::is_importable_meta_key( (string) $key ) ) {
					continue;
				}
				if ( $key === '_en_event_id' && $event_id ) {
					$val = $event_id;
				}
				update_post_meta( $reservation_id, $key, $val );
			}
		}
		$summary['reservation_id'] = $reservation_id;

		/* ── Config ────────────────────────────────────────────── */
		if ( ! empty( $data['config'] ) && class_exists( 'EEM_Reservation_Config' ) ) {
			$cfg_values = $data['config'];
			if ( $event_id ) {
				$cfg_values['event_id'] = $event_id;
			}
			$cfg = EEM_Reservation_Config::for( $reservation_id );
			$cfg->set_many( $cfg_values )->save();
			EEM_Reservation_Config::flush_cache( $reservation_id );
			// Mirror pricing mode to post meta (resilient fallback — see
			// EEM_Reservations_CPT::get_meta_values) so imported reservations
			// keep their Stay Packages pricing on environments where the
			// config-table column read/write isn't taking.
			if ( isset( $cfg_values['stall_pricing_mode'] ) ) {
				update_post_meta( $reservation_id, '_en_stall_pricing_mode', $cfg_values['stall_pricing_mode'] );
			}
			if ( isset( $cfg_values['rv_pricing_mode'] ) ) {
				update_post_meta( $reservation_id, '_en_rv_pricing_mode', $cfg_values['rv_pricing_mode'] );
			}
			// #20: mirror EVERY section-enabled flag to post meta. The config table
			// holds the truth, but many gates read EEM_Reservations_CPT::section_enabled()
			// which only looks at post meta — without this an imported reservation is
			// functionally "off" (no Assign Stalls button, sections hidden) until the
			// admin opens + re-saves the editor. Mirror so a fresh import works as-is.
			if ( class_exists( 'EEM_Reservations_CPT' ) ) {
				foreach ( EEM_Reservations_CPT::SECTION_ENABLED_MAP as $field => $slug ) {
					if ( ! array_key_exists( $field, $cfg_values ) ) {
						continue;
					}
					update_post_meta(
						$reservation_id,
						EEM_Reservations_CPT::section_enabled_meta_key( $field ),
						! empty( $cfg_values[ $field ] ) ? 1 : 0
					);
				}
			}
		}

		/* ── Stay Packages ─────────────────────────────────────── */
		$pkg_count = 0;
		if ( ! empty( $data['packages'] ) && class_exists( 'EEM_Stay_Packages_Repo' ) ) {
			foreach ( $data['packages'] as $pkg ) {
				unset( $pkg['id'] );
				$pkg['reservation_id'] = $reservation_id;
				EEM_Stay_Packages_Repo::insert( $pkg );
				$pkg_count++;
			}
		}
		$summary['packages_created'] = $pkg_count;

		/* ── Stall Orders ──────────────────────────────────────── */
		$stall_count = 0;
		if ( ! empty( $data['stall_orders'] ) ) {
			$stall_table = $wpdb->prefix . 'eem_stall_reservations';
			$stall_cols  = array_flip( self::table_columns( $stall_table ) );
			foreach ( $data['stall_orders'] as $row ) {
				// 4.3: keep only real table columns from the imported row.
				$row = array_intersect_key( (array) $row, $stall_cols );
				$row['reservation_id'] = $reservation_id;
				if ( $event_id ) {
					$row['event_id'] = $event_id;
				}
				if ( empty( $row ) ) {
					continue;
				}
				$wpdb->insert( $stall_table, $row );
				$stall_count++;
			}
		}
		$summary['stall_orders_created'] = $stall_count;

		/* ── RV Orders ─────────────────────────────────────────── */
		$rv_count = 0;
		if ( ! empty( $data['rv_orders'] ) ) {
			$rv_table = $wpdb->prefix . 'eem_rv_reservations';
			$rv_cols  = array_flip( self::table_columns( $rv_table ) );
			foreach ( $data['rv_orders'] as $row ) {
				// 4.3: keep only real table columns from the imported row.
				$row = array_intersect_key( (array) $row, $rv_cols );
				$row['reservation_id'] = $reservation_id;
				if ( $event_id ) {
					$row['event_id'] = $event_id;
				}
				if ( empty( $row ) ) {
					continue;
				}
				$wpdb->insert( $rv_table, $row );
				$rv_count++;
			}
		}
		$summary['rv_orders_created'] = $rv_count;

		$summary['message'] = sprintf(
			__( 'Setup imported: reservation #%d with %d packages, %d stall orders, %d RV orders.', 'equine-event-manager' ),
			$reservation_id,
			$pkg_count,
			$stall_count,
			$rv_count
		);

		return $summary;
	}
}
