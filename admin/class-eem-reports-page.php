<?php
/**
 * Reports page + export dispatch (C15.C).
 *
 * Owns the mockup-faithful Reports admin page render (added in C15.C-b) and the
 * export handlers. C15.C-a (this commit) lands the functional core:
 *   - generate_export(): repo -> dataset -> exporter -> cached file + log entry.
 *   - handle_export(): admin-post — cap + nonce, generate, stream download.
 *   - download_cached(): admin-post — cap + nonce, re-stream a cached export
 *     (export-history re-download). Files live in a deny-all dir, so they are
 *     ONLY reachable through this capability-checked handler.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports page controller + export endpoints.
 */
class EEM_Reports_Page {

	const MENU_SLUG    = 'equine-event-manager-reports';
	const NONCE_ACTION = 'eem_reports_export';

	/**
	 * Register the export admin-post endpoints.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_eem_reports_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_eem_reports_download', array( __CLASS__, 'download_cached' ) );
	}

	/**
	 * Read + sanitize report filters from a request array.
	 *
	 * @param array $src Request source ($_POST / $_GET).
	 * @return array{reservation_id:int,date_from:string,date_to:string,status:string}
	 */
	public static function read_filters( array $src ): array {
		return array(
			'reservation_id' => isset( $src['reservation_id'] ) ? absint( $src['reservation_id'] ) : 0,
			'date_from'      => isset( $src['date_from'] ) ? sanitize_text_field( wp_unslash( $src['date_from'] ) ) : '',
			'date_to'        => isset( $src['date_to'] ) ? sanitize_text_field( wp_unslash( $src['date_to'] ) ) : '',
			'status'         => isset( $src['status'] ) ? sanitize_key( wp_unslash( $src['status'] ) ) : '',
		);
	}

	/**
	 * Generate an export: build the report, render the format, cache it, log it.
	 *
	 * @param string $slug    Report slug (one of EEM_Reports_Repo::REPORTS).
	 * @param array  $filters Filters.
	 * @param string $format  'csv' (pdf/zip land in C15.E).
	 * @return array{path:string,filename:string}|WP_Error
	 */
	public static function generate_export( string $slug, array $filters, string $format = 'csv' ) {
		$slug   = sanitize_key( $slug );
		$format = sanitize_key( $format );

		if ( ! in_array( $slug, EEM_Reports_Repo::REPORTS, true ) ) {
			return new WP_Error( 'eem_reports_bad_slug', __( 'Unknown report.', 'equine-event-manager' ) );
		}
		if ( 'csv' !== $format ) {
			// PDF + ZIP land in C15.E.
			return new WP_Error( 'eem_reports_format_pending', __( 'That export format is not available yet.', 'equine-event-manager' ) );
		}

		$repo     = new EEM_Reports_Repo();
		$exporter = new EEM_Report_Exporter();
		$norm     = $repo->normalize_filters( $filters );
		$report   = $repo->get_report( $slug, $norm );

		$contents = $exporter->build_csv( $report );
		if ( '' === $contents ) {
			return new WP_Error( 'eem_reports_empty', __( 'The report could not be generated.', 'equine-event-manager' ) );
		}

		$filename = $exporter->export_filename( $slug, $norm['reservation_id'], 'csv' );
		$path     = $exporter->write_to_cache( $filename, $contents );
		if ( '' === $path ) {
			return new WP_Error( 'eem_reports_cache_failed', __( 'The export could not be written to disk.', 'equine-event-manager' ) );
		}

		self::log_export( $norm['reservation_id'], $filename );
		$exporter->purge_old();

		return array( 'path' => $path, 'filename' => $filename );
	}

	/**
	 * Insert an export-history row.
	 *
	 * @param int    $reservation_id Reservation (0 = all).
	 * @param string $filename       Cached filename.
	 * @return void
	 */
	private static function log_export( int $reservation_id, string $filename ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'en_report_exports';
		$name  = $reservation_id > 0 ? get_the_title( $reservation_id ) : __( 'All reservations', 'equine-event-manager' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'export_scope'     => $reservation_id > 0 ? 'reservation' : 'all',
				'reservation_id'   => $reservation_id,
				'reservation_name' => $name ? $name : __( 'All reservations', 'equine-event-manager' ),
				'file_name'        => $filename,
				'exported_by'      => get_current_user_id(),
				'created_at'       => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * admin-post: generate + stream an export download.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'equine-event-manager' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$slug   = isset( $_POST['report'] ) ? sanitize_key( wp_unslash( $_POST['report'] ) ) : '';
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		$result = self::generate_export( $slug, self::read_filters( $_POST ), $format );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}

		self::stream_file( $result['path'], $result['filename'], 'csv' );
	}

	/**
	 * admin-post: re-stream a previously cached export (export-history download).
	 *
	 * @return void
	 */
	public static function download_cached(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download reports.', 'equine-event-manager' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$exporter = new EEM_Report_Exporter();

		// Only serve files that actually live in the cache dir (no traversal).
		if ( '' === $filename || ! $exporter->cached_exists( $filename ) ) {
			wp_die( esc_html__( 'That export is no longer available. Re-run the report to download it again.', 'equine-event-manager' ), '', array( 'response' => 404 ) );
		}

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		self::stream_file( $exporter->cached_path( $filename ), $filename, $ext );
	}

	/**
	 * Stream a file as an attachment and exit.
	 *
	 * @param string $path     Absolute path.
	 * @param string $filename Download filename.
	 * @param string $ext      Extension (for content type).
	 * @return void
	 */
	private static function stream_file( string $path, string $filename, string $ext ): void {
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The export file could not be read.', 'equine-event-manager' ), '', array( 'response' => 404 ) );
		}
		$types = array( 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf', 'zip' => 'application/zip' );
		$type  = isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
