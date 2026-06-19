<?php
/**
 * Required-document uploads attached to an order.
 *
 * Storage + retrieval for the customer-uploaded files that satisfy a
 * reservation's admin-defined "Required Documents" list (Coggins, health
 * certificate, etc.). One row per (order_key, requirement); files live in a
 * private uploads subdir (non-guessable names, .htaccess-denied) and are served
 * only through an authenticated download endpoint.
 *
 * Auth model: the order_key is an unguessable bearer token (same key the hosted
 * receipt page uses), so a logged-out customer with their order link can upload
 * and download their own docs; admins (manage_options) can do so for any order.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order required-document repository + file handler.
 */
class EEM_Order_Documents {

	/** Max upload size in bytes (10 MB). */
	const MAX_BYTES = 10485760;

	/**
	 * Allowed extension => mime map. PDF + common image types.
	 *
	 * @var array<string,string>
	 */
	const ALLOWED = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'heic' => 'image/heic',
		'webp' => 'image/webp',
	);

	/**
	 * Create the wp_eem_order_documents table. Idempotent via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'eem_order_documents';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_key varchar(191) NOT NULL,
			requirement varchar(191) NOT NULL,
			file_name varchar(255) NOT NULL,
			original_name varchar(255) NOT NULL,
			mime varchar(100) NOT NULL DEFAULT '',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_order_req (order_key, requirement),
			KEY idx_order (order_key)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Absolute path to the private storage directory (created on first use,
	 * with an .htaccess deny + empty index.html so it is never browsable).
	 *
	 * @return string Trailing-slashed path, or '' if uploads dir is unavailable.
	 */
	public static function storage_dir(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'eem-required-docs/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . 'index.html' ) ) {
			@file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Validate an uploaded $_FILES entry. Returns '' when valid, else an error
	 * message suitable for surfacing to the uploader.
	 *
	 * @param array $file A single $_FILES['x'] entry.
	 * @return string Error message, or '' when valid.
	 */
	public static function validate_upload( array $file ): string {
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return __( 'The file failed to upload. Please try again.', 'equine-event-manager' );
		}
		if ( (int) ( $file['size'] ?? 0 ) <= 0 || (int) $file['size'] > self::MAX_BYTES ) {
			return __( 'File must be 10 MB or smaller.', 'equine-event-manager' );
		}
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::ALLOWED[ $ext ] ) ) {
			return __( 'Allowed file types: PDF, JPG, PNG, HEIC, WEBP.', 'equine-event-manager' );
		}
		// Confirm the real type matches the extension (defense in depth).
		$check = wp_check_filetype_and_ext( (string) ( $file['tmp_name'] ?? '' ), $name, self::ALLOWED );
		if ( empty( $check['ext'] ) || ! isset( self::ALLOWED[ strtolower( (string) $check['ext'] ) ] ) ) {
			return __( 'That file does not appear to be a valid PDF or image.', 'equine-event-manager' );
		}
		return '';
	}

	/**
	 * Store an uploaded file for (order_key, requirement), replacing any prior
	 * upload for that pair. Moves the temp file into the private dir under a
	 * random name and upserts the DB row.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name (admin-defined).
	 * @param array  $file        A single $_FILES entry.
	 * @param int    $user_id     Acting user (0 = customer/front-end).
	 * @return string Error message, or '' on success.
	 */
	public static function store( string $order_key, string $requirement, array $file, int $user_id = 0 ): string {
		$order_key   = sanitize_text_field( $order_key );
		$requirement = sanitize_text_field( $requirement );
		if ( '' === $order_key || '' === $requirement ) {
			return __( 'Missing order or document reference.', 'equine-event-manager' );
		}
		$err = self::validate_upload( $file );
		if ( '' !== $err ) {
			return $err;
		}
		$dir = self::storage_dir();
		if ( '' === $dir ) {
			return __( 'The upload directory is not writable.', 'equine-event-manager' );
		}

		$ext       = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		$rand      = wp_generate_password( 20, false );
		$stored    = 'doc-' . md5( $order_key . '|' . $requirement ) . '-' . $rand . '.' . $ext;
		$dest      = $dir . $stored;

		if ( ! @move_uploaded_file( (string) $file['tmp_name'], $dest ) ) { // phpcs:ignore
			return __( 'The file could not be saved. Please try again.', 'equine-event-manager' );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore

		// Replace any prior file for this pair (delete the old physical file).
		$existing = self::get( $order_key, $requirement );
		if ( $existing && ! empty( $existing['file_name'] ) && $existing['file_name'] !== $stored ) {
			$old = $dir . $existing['file_name'];
			if ( is_file( $old ) ) {
				wp_delete_file( $old );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_documents';
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT INTO {$table} ( order_key, requirement, file_name, original_name, mime, file_size, uploaded_by, uploaded_at )
			 VALUES ( %s, %s, %s, %s, %s, %d, %d, %s )
			 ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), original_name = VALUES(original_name), mime = VALUES(mime), file_size = VALUES(file_size), uploaded_by = VALUES(uploaded_by), uploaded_at = VALUES(uploaded_at)",
			$order_key,
			$requirement,
			$stored,
			sanitize_file_name( (string) $file['name'] ),
			self::ALLOWED[ $ext ],
			(int) $file['size'],
			$user_id,
			current_time( 'mysql' )
		) );

		return '';
	}

	/**
	 * All uploaded documents for an order, keyed by requirement name.
	 *
	 * @param string $order_key Order bearer key.
	 * @return array<string,array<string,mixed>> requirement => row.
	 */
	public static function get_for_order( string $order_key ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_documents';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_key = %s", sanitize_text_field( $order_key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$map   = array();
		foreach ( (array) $rows as $r ) {
			$map[ (string) $r['requirement'] ] = $r;
		}
		return $map;
	}

	/**
	 * A single uploaded document row for (order_key, requirement), or null.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $order_key, string $requirement ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_documents';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_key = %s AND requirement = %s", sanitize_text_field( $order_key ), sanitize_text_field( $requirement ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * Stream a stored file to the browser (inline). Caller MUST authorize first.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name.
	 * @return void Exits on success; returns on not-found so caller can 404.
	 */
	public static function stream( string $order_key, string $requirement ): void {
		$row = self::get( $order_key, $requirement );
		if ( ! $row ) {
			return;
		}
		$dir  = self::storage_dir();
		$path = $dir . $row['file_name'];
		if ( '' === $dir || ! is_file( $path ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: ' . ( '' !== $row['mime'] ? $row['mime'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( (string) $row['original_name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/**
	 * Register the AJAX upload + download endpoints (logged-in + nopriv).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_upload_required_doc', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'wp_ajax_nopriv_eem_upload_required_doc', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'wp_ajax_eem_download_required_doc', array( __CLASS__, 'ajax_download' ) );
		add_action( 'wp_ajax_nopriv_eem_download_required_doc', array( __CLASS__, 'ajax_download' ) );
		// Pre-order staging (customer uploads on the reservation form before an
		// order exists; files are re-keyed onto the order at creation).
		add_action( 'wp_ajax_eem_stage_required_doc', array( __CLASS__, 'ajax_stage' ) );
		add_action( 'wp_ajax_nopriv_eem_stage_required_doc', array( __CLASS__, 'ajax_stage' ) );
	}

	/**
	 * Build the storage key used for documents staged before an order exists.
	 * The token is a per-form-render random string carried in a hidden field;
	 * on order creation reassign_pending() rewrites these rows to the order_key.
	 *
	 * @param string $token Per-render session token.
	 * @return string Pending key, or '' when the token is invalid.
	 */
	public static function pending_key( string $token ): string {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		return '' !== $token ? 'pending:' . $token : '';
	}

	/**
	 * The list of requirement names a reservation defines (non-empty names only).
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array<int,string>
	 */
	public static function requirement_names( int $reservation_id ): array {
		$names = array();
		if ( $reservation_id > 0 && class_exists( 'EEM_Reservation_Config' ) ) {
			$docs = (array) EEM_Reservation_Config::for( $reservation_id )->get( 'required_documents', array() );
			foreach ( $docs as $d ) {
				if ( is_array( $d ) && '' !== trim( (string) ( $d['name'] ?? '' ) ) ) {
					$names[] = (string) $d['name'];
				}
			}
		}
		return $names;
	}

	/**
	 * Re-key every staged document for $token onto the real $order_key once the
	 * order has been created. Skips any pair that already has a real upload
	 * (ON DUPLICATE would otherwise be needed; we delete the staged dupe instead).
	 *
	 * @param string $token     Per-render session token from the form.
	 * @param string $order_key Newly created order's bearer key.
	 * @return int Number of rows moved onto the order.
	 */
	public static function reassign_pending( string $token, string $order_key ): int {
		$pending = self::pending_key( $token );
		$order_key = sanitize_text_field( $order_key );
		if ( '' === $pending || '' === $order_key ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'eem_order_documents';

		// Drop any staged rows whose (order_key, requirement) target already
		// exists for the real order, so the UPDATE can't trip the UNIQUE key.
		$existing = self::get_for_order( $order_key );
		if ( $existing ) {
			$dir = self::storage_dir();
			foreach ( array_keys( $existing ) as $req ) {
				$staged = self::get( $pending, (string) $req );
				if ( $staged ) {
					if ( '' !== $dir && ! empty( $staged['file_name'] ) && is_file( $dir . $staged['file_name'] ) ) {
						wp_delete_file( $dir . $staged['file_name'] );
					}
					$wpdb->delete( $table, array( 'order_key' => $pending, 'requirement' => (string) $req ), array( '%s', '%s' ) ); // phpcs:ignore WordPress.DB
				}
			}
		}

		return (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"UPDATE {$table} SET order_key = %s WHERE order_key = %s",
			$order_key,
			$pending
		) );
	}

	/**
	 * AJAX: stage an uploaded required document before the order exists. Expects
	 * reservation_id, session (token), requirement, nonce (eem_stage_required_doc),
	 * and $_FILES['file']. Authorized by the reservation actually defining the
	 * requirement (no order/login required — this is the public checkout form).
	 *
	 * @return void
	 */
	public static function ajax_stage(): void {
		check_ajax_referer( 'eem_stage_required_doc', 'nonce' );
		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$token          = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		$requirement    = isset( $_POST['requirement'] ) ? sanitize_text_field( wp_unslash( $_POST['requirement'] ) ) : '';
		$pending        = self::pending_key( $token );

		if ( '' === $pending ) {
			wp_send_json_error( array( 'message' => __( 'Missing upload session.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! in_array( $requirement, self::requirement_names( $reservation_id ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown document requirement.', 'equine-event-manager' ) ), 400 );
		}
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'equine-event-manager' ) ), 400 );
		}

		$err = self::store( $pending, $requirement, $_FILES['file'], get_current_user_id() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handled in store()/validate_upload().
		if ( '' !== $err ) {
			wp_send_json_error( array( 'message' => $err ), 400 );
		}
		$row = self::get( $pending, $requirement );
		wp_send_json_success( array(
			'requirement'   => $requirement,
			'original_name' => $row ? (string) $row['original_name'] : '',
		) );
	}

	/**
	 * Resolve + authorize an (order_key, requirement) request. Returns the order
	 * array on success, or false (after sending a JSON error) on failure. The
	 * order_key is the bearer secret; admins (manage_options) are also allowed.
	 * The requirement must be one the reservation actually defines.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name.
	 * @return array<string,mixed>|false
	 */
	private static function authorize( string $order_key, string $requirement ) {
		$order_key = sanitize_text_field( $order_key );
		if ( '' === $order_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing order reference.', 'equine-event-manager' ) ), 400 );
		}
		$order = ( new EEM_Orders_Repository() )->get_order_by_order_key( $order_key );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		// Requirement must be one the reservation defines.
		$rid    = (int) ( $order['reservation_id'] ?? 0 );
		$names  = array();
		if ( $rid > 0 && class_exists( 'EEM_Reservation_Config' ) ) {
			$docs = (array) EEM_Reservation_Config::for( $rid )->get( 'required_documents', array() );
			foreach ( $docs as $d ) {
				if ( is_array( $d ) && '' !== trim( (string) ( $d['name'] ?? '' ) ) ) {
					$names[] = (string) $d['name'];
				}
			}
		}
		if ( ! in_array( $requirement, $names, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown document requirement.', 'equine-event-manager' ) ), 400 );
		}
		return $order;
	}

	/**
	 * AJAX: store an uploaded required document. Expects order_key, requirement,
	 * nonce (eem_required_doc), and $_FILES['file'].
	 *
	 * @return void
	 */
	public static function ajax_upload(): void {
		check_ajax_referer( 'eem_required_doc', 'nonce' );
		$order_key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$requirement = isset( $_POST['requirement'] ) ? sanitize_text_field( wp_unslash( $_POST['requirement'] ) ) : '';
		self::authorize( $order_key, $requirement ); // exits on failure

		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'equine-event-manager' ) ), 400 );
		}
		$err = self::store( $order_key, $requirement, $_FILES['file'], get_current_user_id() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handled in store()/validate_upload().
		if ( '' !== $err ) {
			wp_send_json_error( array( 'message' => $err ), 400 );
		}
		$row = self::get( $order_key, $requirement );
		wp_send_json_success( array(
			'requirement'   => $requirement,
			'original_name' => $row ? (string) $row['original_name'] : '',
			'uploaded_at'   => $row ? (string) $row['uploaded_at'] : '',
			'download_url'  => self::download_url( $order_key, $requirement ),
		) );
	}

	/**
	 * AJAX: stream a stored required document (inline). Expects order_key,
	 * requirement, nonce.
	 *
	 * @return void
	 */
	public static function ajax_download(): void {
		check_ajax_referer( 'eem_required_doc', 'nonce' );
		$order_key   = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$requirement = isset( $_GET['requirement'] ) ? sanitize_text_field( wp_unslash( $_GET['requirement'] ) ) : '';
		self::authorize( $order_key, $requirement ); // exits on failure
		self::stream( $order_key, $requirement );
		wp_die( esc_html__( 'Document not found.', 'equine-event-manager' ), '', array( 'response' => 404 ) );
	}

	/**
	 * Build a nonce'd admin-ajax download URL for an order's document.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name.
	 * @return string
	 */
	public static function download_url( string $order_key, string $requirement ): string {
		return add_query_arg(
			array(
				'action'      => 'eem_download_required_doc',
				'order_key'   => rawurlencode( $order_key ),
				'requirement' => rawurlencode( $requirement ),
				'nonce'       => wp_create_nonce( 'eem_required_doc' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}
}
