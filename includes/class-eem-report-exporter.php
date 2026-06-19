<?php
/**
 * Report exporter (C15.B).
 *
 * Turns a report dataset (from EEM_Reports_Repo) into a CSV string, manages the
 * on-disk export cache (`uploads/eem-reports/`), and purges stale files. PDF +
 * ZIP generation layer on top in C15.E.
 *
 * Security: the cache directory is protected with a deny-all `.htaccess` + empty
 * `index.html`. Exports contain customer PII, so the files are NOT served by
 * direct (guessable) URL — a capability-checked admin handler (C15.C/D) streams
 * them. `cached_path()` returns the filesystem path for that handler.
 *
 * Filename convention: `eem-{slug}-{rid|all}-{Ymd}.{ext}` (event-id-based, so the
 * 5-digit order-number rule doesn't apply here).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV + cache management for report exports.
 */
class EEM_Report_Exporter {

	/**
	 * Cache retention in days (hardcoded for now per roadmap).
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Build a CSV string from a report dataset (headers + rows).
	 *
	 * Uses fputcsv for correct quoting/escaping. UTF-8 BOM prepended so Excel
	 * opens accented characters correctly.
	 *
	 * @param array $report Report dataset: { headers: array, rows: array<array> }.
	 * @return string CSV bytes.
	 */
	public function build_csv( array $report ): string {
		$headers = isset( $report['headers'] ) && is_array( $report['headers'] ) ? $report['headers'] : array();
		$rows    = isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array();

		$fh = fopen( 'php://temp', 'r+' );
		if ( false === $fh ) {
			return '';
		}

		if ( ! empty( $headers ) ) {
			fputcsv( $fh, array_map( array( $this, 'neutralize_csv_cell' ), $headers ) );
		}
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( $this, 'neutralize_csv_cell' ), (array) $row ) );
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return "\xEF\xBB\xBF" . ( is_string( $csv ) ? $csv : '' );
	}

	/**
	 * Neutralize CSV formula injection (CWE-1236) in a single cell.
	 *
	 * Customer-supplied fields (names, notes, event names) flow into exported
	 * reports. A cell beginning with `=`, `+`, `-`, `@`, `|` or `%` is interpreted
	 * as a formula by Excel / LibreOffice / Sheets when the admin opens the file —
	 * e.g. `=WEBSERVICE(...)` can exfiltrate data. Prefixing a single quote forces
	 * the spreadsheet to treat the cell as literal text. Genuine numbers (incl.
	 * negative / decimal) are left untouched so money + count columns stay numeric.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string Neutralized cell value.
	 */
	private function neutralize_csv_cell( $value ): string {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		// Leave real numbers alone (keeps money/qty columns sortable + summable).
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', '|', '%' ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Render a report dataset to a portrait, brand-headed tabular PDF (C15.E).
	 *
	 * Portrait letter with the company logo header, matching the styling of the
	 * order receipt / stall-chart print views.
	 *
	 * @param array $report Report dataset { title, headers, rows }.
	 * @param array $meta   Optional { subtitle, generated }.
	 * @return string PDF bytes, or '' when Dompdf is unavailable / render fails.
	 */
	public function build_pdf( array $report, array $meta = array() ): string {
		if ( ! class_exists( 'EEM_PDF' ) || ! EEM_PDF::is_available() ) {
			return '';
		}

		$ctx = array(
			'title'     => isset( $report['title'] ) ? (string) $report['title'] : '',
			'subtitle'  => isset( $meta['subtitle'] ) ? (string) $meta['subtitle'] : '',
			'generated' => isset( $meta['generated'] ) ? (string) $meta['generated'] : '',
			'logo'      => $this->company_logo_data_uri(),
			'headers'   => isset( $report['headers'] ) && is_array( $report['headers'] ) ? $report['headers'] : array(),
			'rows'      => isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array(),
		);

		ob_start();
		include EQUINE_EVENT_MANAGER_PATH . 'templates/reports/report-pdf.php';
		$html = (string) ob_get_clean();

		return EEM_PDF::render( $html, 'letter', 'portrait' );
	}

	/**
	 * Company logo as a base64 data URI for embedding in the report PDF header.
	 *
	 * Dompdf renders with remote loading disabled, so the logo must be inlined.
	 * Resolves the configured company logo attachment, else the bundled fallback
	 * asset. Mirrors EEM shortcodes' receipt-logo helper.
	 *
	 * @return string Data URI, or '' when no readable image is found.
	 */
	private function company_logo_data_uri(): string {
		$path     = '';
		$settings = get_option( 'equine_event_manager_company_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['logo_id'] ) ) {
			$attached = get_attached_file( absint( $settings['logo_id'] ) );
			if ( $attached && is_readable( $attached ) ) {
				$path = $attached;
			}
		}
		if ( '' === $path ) {
			$fallback = EQUINE_EVENT_MANAGER_PATH . 'assets/images/logo.png';
			$path     = is_readable( $fallback ) ? $fallback : '';
		}
		if ( '' === $path ) {
			return '';
		}

		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || '' === $data ) {
			return '';
		}
		$mime = 'png';
		if ( 'jpg' === $ext || 'jpeg' === $ext ) {
			$mime = 'jpeg';
		} elseif ( 'svg' === $ext ) {
			$mime = 'svg+xml';
		}
		return 'data:image/' . $mime . ';base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Whether the PHP ZipArchive extension is available.
	 *
	 * @return bool
	 */
	public function zip_available(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Bundle named in-memory files into a ZIP and return its bytes.
	 *
	 * @param array<string,string> $files filename => contents.
	 * @return string ZIP bytes, or '' on failure / no ZipArchive.
	 */
	public function build_zip( array $files ): string {
		if ( empty( $files ) || ! $this->zip_available() ) {
			return '';
		}

		$tmp = wp_tempnam( 'eem-report-zip' );
		if ( ! $tmp ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			return '';
		}
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( sanitize_file_name( (string) $name ), (string) $contents );
		}
		$zip->close();

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmp );

		return is_string( $bytes ) ? $bytes : '';
	}

	/**
	 * Absolute path to the export cache directory, creating + protecting it on
	 * first use.
	 *
	 * @return string Path with trailing slash, or '' if it can't be created.
	 */
	public function cache_dir(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'eem-reports';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// Protect: no directory listing, no direct file access (PII).
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
		}
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
		}

		return trailingslashit( $dir );
	}

	/**
	 * Build the canonical export filename.
	 *
	 * @param string $slug           Report slug.
	 * @param int    $reservation_id Reservation ID (0 = all).
	 * @param string $ext            'csv' | 'pdf' | 'zip'.
	 * @param string $date_ymd       Date stamp (Ymd). Pass explicitly (Date funcs
	 *                               are unavailable in some contexts); defaults to
	 *                               today via current_time.
	 * @return string
	 */
	public function export_filename( string $slug, int $reservation_id, string $ext, string $date_ymd = '' ): string {
		$slug  = sanitize_key( $slug );
		$ext   = sanitize_key( $ext );
		$scope = $reservation_id > 0 ? (string) $reservation_id : 'all';
		$stamp = '' !== $date_ymd ? preg_replace( '/[^0-9]/', '', $date_ymd ) : current_time( 'Ymd' );

		return sanitize_file_name( "eem-{$slug}-{$scope}-{$stamp}.{$ext}" );
	}

	/**
	 * Write contents to the cache directory.
	 *
	 * @param string $filename Bare filename (use export_filename()).
	 * @param string $contents File bytes.
	 * @return string Absolute path on success, '' on failure.
	 */
	public function write_to_cache( string $filename, string $contents ): string {
		$dir = $this->cache_dir();
		if ( '' === $dir ) {
			return '';
		}
		$filename = sanitize_file_name( $filename );
		$path     = $dir . $filename;

		return ( false !== file_put_contents( $path, $contents ) ) ? $path : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
	}

	/**
	 * Resolve the cached path for a filename (whether or not it exists).
	 *
	 * @param string $filename Bare filename.
	 * @return string Absolute path, or '' if the cache dir is unavailable.
	 */
	public function cached_path( string $filename ): string {
		$dir = $this->cache_dir();
		return '' !== $dir ? $dir . sanitize_file_name( $filename ) : '';
	}

	/**
	 * Whether a cached export file currently exists.
	 *
	 * @param string $filename Bare filename.
	 * @return bool
	 */
	public function cached_exists( string $filename ): bool {
		$path = $this->cached_path( $filename );
		return '' !== $path && is_readable( $path );
	}

	/**
	 * Delete cached export files older than the retention window.
	 *
	 * @param int $days Retention in days (default RETENTION_DAYS).
	 * @return int Number of files deleted.
	 */
	public function purge_old( int $days = self::RETENTION_DAYS ): int {
		$dir = $this->cache_dir();
		if ( '' === $dir ) {
			return 0;
		}

		$cutoff  = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		$deleted = 0;

		foreach ( (array) glob( $dir . 'eem-*' ) as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			if ( filemtime( $file ) < $cutoff ) {
				if ( wp_delete_file( $file ) || ! file_exists( $file ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Delete every cached export file regardless of age. Used by the "Clear
	 * history" action on the Reports page (which also empties the history table).
	 *
	 * @return int Files deleted.
	 */
	public function purge_all(): int {
		$dir = $this->cache_dir();
		if ( '' === $dir ) {
			return 0;
		}

		$deleted = 0;
		foreach ( (array) glob( $dir . 'eem-*' ) as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			if ( wp_delete_file( $file ) || ! file_exists( $file ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}
}
