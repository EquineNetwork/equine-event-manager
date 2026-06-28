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
			satisfied tinyint(1) NOT NULL DEFAULT 0,
			satisfied_by bigint(20) unsigned NOT NULL DEFAULT 0,
			satisfied_at datetime NULL DEFAULT NULL,
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

		// A11 — no error suppression: a failed move surfaces a diagnostic
		// warning to the log AND returns the user-facing message below.
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $dest ) ) {
			return __( 'The file could not be saved. Please try again.', 'equine-event-manager' );
		}
		// chmod is intentionally best-effort: a hardened host may disallow it,
		// and the file is already inside a private (.htaccess-protected) dir, so
		// a failed chmod is non-fatal. Suppress the warning rather than abort.
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening; see note above.

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
	 * A requirement is fulfilled when it has an uploaded file OR has been marked
	 * satisfied in person by an admin.
	 *
	 * @param array<string,mixed>|null $row Document row (or null when no row).
	 * @return bool
	 */
	public static function is_fulfilled( ?array $row ): bool {
		if ( ! is_array( $row ) ) {
			return false;
		}
		$has_file = ! empty( $row['file_name'] );
		return $has_file || ! empty( $row['satisfied'] );
	}

	/**
	 * Mark a requirement satisfied in person (no file). Upserts the row,
	 * preserving any existing uploaded file. Admin-only callers.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name.
	 * @param int    $user_id     Acting admin user id.
	 * @return bool True on success.
	 */
	public static function mark_satisfied( string $order_key, string $requirement, int $user_id ): bool {
		global $wpdb;
		$order_key   = sanitize_text_field( $order_key );
		$requirement = sanitize_text_field( $requirement );
		if ( '' === $order_key || '' === $requirement ) {
			return false;
		}
		$table = $wpdb->prefix . 'eem_order_documents';
		$result = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT INTO {$table} ( order_key, requirement, file_name, original_name, mime, file_size, satisfied, satisfied_by, satisfied_at, uploaded_by, uploaded_at )
			 VALUES ( %s, %s, '', '', '', 0, 1, %d, %s, %d, %s )
			 ON DUPLICATE KEY UPDATE satisfied = 1, satisfied_by = VALUES(satisfied_by), satisfied_at = VALUES(satisfied_at)",
			$order_key,
			$requirement,
			$user_id,
			current_time( 'mysql' ),
			$user_id,
			current_time( 'mysql' )
		) );
		return false !== $result;
	}

	/**
	 * Undo an in-person "satisfied" mark. If the row also carries an uploaded
	 * file the file is preserved (only the satisfied flag clears); if the row is
	 * satisfied-only it is deleted so the requirement returns to outstanding.
	 *
	 * @param string $order_key   Order bearer key.
	 * @param string $requirement Requirement name.
	 * @return bool True on success.
	 */
	public static function unmark_satisfied( string $order_key, string $requirement ): bool {
		global $wpdb;
		$order_key   = sanitize_text_field( $order_key );
		$requirement = sanitize_text_field( $requirement );
		$table       = $wpdb->prefix . 'eem_order_documents';
		$row         = self::get( $order_key, $requirement );
		if ( ! $row ) {
			return true;
		}
		if ( ! empty( $row['file_name'] ) ) {
			return false !== $wpdb->update( $table, array( 'satisfied' => 0, 'satisfied_by' => 0, 'satisfied_at' => null ), array( 'order_key' => $order_key, 'requirement' => $requirement ), array( '%d', '%d', '%s' ), array( '%s', '%s' ) );
		}
		return false !== $wpdb->delete( $table, array( 'order_key' => $order_key, 'requirement' => $requirement ), array( '%s', '%s' ) );
	}

	/**
	 * Names of the reservation's required documents that are NOT yet fulfilled
	 * (no upload and not marked satisfied) for this order. Empty array = all
	 * requirements met (or none defined).
	 *
	 * @param string $order_key      Order bearer key.
	 * @param int    $reservation_id Owning reservation post id.
	 * @return array<int,string>
	 */
	public static function outstanding_requirements( string $order_key, int $reservation_id ): array {
		$names = self::requirement_names( $reservation_id );
		if ( empty( $names ) ) {
			return array();
		}
		$have        = self::get_for_order( $order_key );
		$outstanding = array();
		foreach ( $names as $req ) {
			$row = isset( $have[ $req ] ) ? $have[ $req ] : null;
			if ( ! self::is_fulfilled( $row ) ) {
				$outstanding[] = $req;
			}
		}
		return $outstanding;
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
		// In-person "mark satisfied" / undo — admin-only (no nopriv variant).
		add_action( 'wp_ajax_eem_mark_doc_satisfied', array( __CLASS__, 'ajax_mark_satisfied' ) );
	}

	/**
	 * AJAX: admin marks a required document satisfied in person, or undoes it.
	 * Admin-only (manage_options). Expects order_key, requirement, nonce
	 * (eem_required_doc), and satisfied ('1' = mark, '0' = undo).
	 *
	 * @return void
	 */
	public static function ajax_mark_satisfied(): void {
		check_ajax_referer( 'eem_required_doc', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		$order_key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$requirement = isset( $_POST['requirement'] ) ? sanitize_text_field( wp_unslash( $_POST['requirement'] ) ) : '';
		$satisfied   = isset( $_POST['satisfied'] ) && '1' === (string) $_POST['satisfied'];
		if ( '' === $order_key || '' === $requirement ) {
			wp_send_json_error( array( 'message' => __( 'Missing order or document reference.', 'equine-event-manager' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$ok      = $satisfied
			? self::mark_satisfied( $order_key, $requirement, $user_id )
			: self::unmark_satisfied( $order_key, $requirement );

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the document. Please try again.', 'equine-event-manager' ) ), 500 );
		}

		if ( class_exists( 'EEM_Activity_Log' ) ) {
			$current_user = wp_get_current_user();
			EEM_Activity_Log::write(
				$satisfied ? 'order_doc_satisfied' : 'order_doc_unsatisfied',
				array( 'order_key' => $order_key, 'requirement' => $requirement ),
				array( 'actor_type' => 'user', 'actor_id' => (int) $user_id, 'actor_label' => $current_user ? $current_user->display_name : '' )
			);
		}

		$actor = wp_get_current_user();
		wp_send_json_success( array(
			'satisfied'    => $satisfied,
			'satisfied_by' => $actor ? $actor->display_name : '',
		) );
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
	 * 4.4c: garbage-collect staged documents from checkouts that never completed —
	 * their order_key is still the 'pending:' placeholder (reassign_pending() never
	 * re-keyed them onto a real order) and they're older than the cutoff. Removes
	 * both the file and the row so abandoned uploads don't pile up on disk. Called
	 * lazily from ajax_stage(); the cutoff is generous so a slow-but-real checkout
	 * is never collected mid-flight.
	 *
	 * @param int $max_age_hours Age threshold in hours (default 48).
	 * @return int Number of staged documents removed.
	 */
	public static function gc_orphaned_pending( int $max_age_hours = 48 ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'eem_order_documents';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_hours * HOUR_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, file_name FROM {$table} WHERE order_key LIKE %s AND uploaded_at < %s",
			$wpdb->esc_like( 'pending:' ) . '%',
			$cutoff
		), ARRAY_A );
		if ( empty( $rows ) ) {
			return 0;
		}

		$dir     = self::storage_dir();
		$deleted = 0;
		foreach ( $rows as $row ) {
			$file_name = isset( $row['file_name'] ) ? (string) $row['file_name'] : '';
			if ( '' !== $dir && '' !== $file_name && is_file( $dir . $file_name ) ) {
				wp_delete_file( $dir . $file_name );
			}
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB
			$deleted++;
		}
		return $deleted;
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

		// 4.4c: throttle this UNAUTHENTICATED 10MB upload per IP — without a limit it's
		// a cheap DoS / disk-fill vector. Allow a generous burst (a customer uploads a
		// handful of docs), then 429. Best-effort IP (spoofable, but raises the bar).
		$eem_ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$eem_rl    = 'eem_stage_rl_' . md5( $eem_ip );
		$eem_count = (int) get_transient( $eem_rl );
		if ( $eem_count >= 20 ) {
			wp_send_json_error( array( 'message' => __( 'Too many uploads in a short time. Please wait a few minutes and try again.', 'equine-event-manager' ) ), 429 );
		}
		set_transient( $eem_rl, $eem_count + 1, 5 * MINUTE_IN_SECONDS );

		// Lazily garbage-collect orphaned staged files (staged but never claimed by an
		// order) so abandoned checkouts don't accumulate on disk. Runs on ~3% of
		// stage calls to avoid a per-request cost or a dedicated cron.
		if ( wp_rand( 1, 100 ) <= 3 ) {
			self::gc_orphaned_pending();
		}

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
