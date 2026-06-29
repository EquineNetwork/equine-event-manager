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
		add_action( 'admin_post_eem_reports_clear', array( __CLASS__, 'clear_history' ) );
	}

	/**
	 * Clear the export history: delete every cached export file and empty the
	 * history table. Cap + nonce gated; redirects back with a notice.
	 *
	 * @return void
	 */
	public static function clear_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'equine-event-manager' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		( new EEM_Report_Exporter() )->purge_all();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}eem_report_exports" ); // phpcs:ignore WordPress.DB

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'eem_notice' => 'history_cleared' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the mockup-faithful Reports page.
	 *
	 * Filters round-trip through GET (Apply reloads with the filter query args);
	 * each report's CSV/PDF export is a POST form to admin-post carrying the
	 * current filters + report slug + format. C15.D layers on the live date-preset
	 * + localStorage JS.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// PDF buttons open a standalone print view (Daily Movement pattern): the
		// admin clicks Print / Save PDF from the browser. CSV still downloads.
		if ( ! empty( $_GET['eem_report_print'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render.
			$this->render_print_view();
			return;
		}

		$filters = self::read_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
		$reservations = get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		eem_render_page_open( array(
			'title'      => __( 'Reports', 'equine-event-manager' ),
			'subtitle'   => __( 'View, export, and re-download reports for one or all reservations. Use filters to narrow results, then export individual reports as CSV or PDF.', 'equine-event-manager' ),
			'breadcrumb' => array( array( 'label' => __( 'Reports', 'equine-event-manager' ) ) ),
			'wrap'       => true,
		) );

		$this->render_action_notice();

		// First-run gate: reports are built from reservation + order data, so with
		// no published reservations there is nothing to report on yet. Show the
		// create-first-reservation CTA instead of empty report cards + a stale
		// export history (consistent with Create Order / Stall Charts empty states).
		$has_published = ! empty( get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) ) );
		?>
		<div class="eem-reports-body">
			<?php if ( ! $has_published ) : ?>
				<?php $new_res_url = admin_url( 'post-new.php?post_type=' . ( class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation' ) ); ?>
				<div class="eem-card">
					<div class="eem-card-body">
						<div class="eem-empty-cta">
							<div class="eem-empty-cta__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg>
							</div>
							<h3 class="eem-empty-cta__title"><?php esc_html_e( 'No reports yet', 'equine-event-manager' ); ?></h3>
							<p class="eem-empty-cta__text"><?php esc_html_e( 'Reports summarize your reservations and orders. Create a reservation and start taking orders, and your exportable reports will appear here.', 'equine-event-manager' ); ?></p>
							<a class="eem-btn eem-btn-amber" href="<?php echo esc_url( $new_res_url ); ?>"><?php esc_html_e( 'Create a Reservation', 'equine-event-manager' ); ?></a>
						</div>
					</div>
				</div>
			<?php else : ?>
				<?php
				$this->render_filters_card( $reservations, $filters );
				$this->render_report_catalog( $filters );
				$this->render_export_history();
				?>
			<?php endif; ?>
		</div>
		<?php

		eem_render_page_close( array( 'wrap' => true ) );
	}

	/**
	 * Post-redirect action notice (e.g. ?eem_notice=...). Rendered as a hidden
	 * flash-toast stub that admin.js promotes into a top-right toast on load —
	 * the same affordance as every other save/confirm action — rather than a
	 * static inline banner above the filters.
	 *
	 * @return void
	 */
	private function render_action_notice(): void {
		$notice = isset( $_GET['eem_notice'] ) ? sanitize_key( wp_unslash( $_GET['eem_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'export_failed' === $notice ) {
			$this->render_flash_toast(
				__( 'That report could not be exported. Please try again.', 'equine-event-manager' ),
				'',
				'error'
			);
		} elseif ( 'history_cleared' === $notice ) {
			$this->render_flash_toast(
				__( 'Export history cleared.', 'equine-event-manager' ),
				__( 'Cached export files were deleted.', 'equine-event-manager' ),
				'success'
			);
		}
	}

	/**
	 * Emit a hidden flash-toast stub. admin.js reads `[data-eem-flash-toast]` on
	 * load, fires EEM.showSaveToast() with the message/sub/variant, then removes
	 * the stub so a refresh doesn't replay it.
	 *
	 * @param string $message Toast title.
	 * @param string $sub     Optional secondary line.
	 * @param string $variant 'success' (default) or 'error'.
	 * @return void
	 */
	private function render_flash_toast( string $message, string $sub, string $variant ): void {
		printf(
			'<div class="eem-flash-toast" data-eem-flash-toast data-eem-flash-message="%s" data-eem-flash-sub="%s" data-eem-flash-variant="%s" hidden></div>',
			esc_attr( $message ),
			esc_attr( $sub ),
			esc_attr( 'error' === $variant ? 'error' : 'success' )
		);
	}

	/**
	 * The 6 report definitions (slug, title, description, icon-tone).
	 *
	 * @return array<int,array<string,string>>
	 */
	private function report_catalog(): array {
		return array(
			array( 'slug' => 'orders', 'tone' => 'orders', 'title' => __( 'Orders', 'equine-event-manager' ), 'desc' => __( 'Every order with customer, items, payment status, totals. Best for transactional bookkeeping.', 'equine-event-manager' ) ),
			array( 'slug' => 'reservations', 'tone' => 'reservations', 'title' => __( 'Reservations', 'equine-event-manager' ), 'desc' => __( 'Event-level summary: dates, total orders, total revenue, occupancy %, capacity used.', 'equine-event-manager' ) ),
			array( 'slug' => 'stall_occupancy', 'tone' => 'occupancy', 'title' => __( 'Stall Occupancy', 'equine-event-manager' ), 'desc' => __( 'Stall utilization by day. Stalls in use, capacity, and occupancy % for each day of the event.', 'equine-event-manager' ) ),
			array( 'slug' => 'rv_occupancy', 'tone' => 'occupancy', 'title' => __( 'RV Occupancy', 'equine-event-manager' ), 'desc' => __( 'RV lot utilization by day. Spots in use, capacity, and occupancy % for each day of the event.', 'equine-event-manager' ) ),
			array( 'slug' => 'shavings', 'tone' => 'shavings', 'title' => __( 'Shavings', 'equine-event-manager' ), 'desc' => __( 'Bedding worksheet per event: required + additional bags per order, with per-event totals for the barn.', 'equine-event-manager' ) ),
			array( 'slug' => 'addons', 'tone' => 'shavings', 'title' => __( 'Add-Ons', 'equine-event-manager' ), 'desc' => __( 'General add-on worksheet per event: per-day quantity of each add-on across the stay, plus total quantity purchased.', 'equine-event-manager' ) ),
			array( 'slug' => 'cleaning', 'tone' => 'occupancy', 'title' => __( 'Facility Cleaning', 'equine-event-manager' ), 'desc' => __( 'Stalls + RV lots flagged for cleaning, grouped by barn/zone with per-barn and total counts. Turnover worksheet for facilities.', 'equine-event-manager' ) ),
			array( 'slug' => 'revenue', 'tone' => 'revenue', 'title' => __( 'Revenue', 'equine-event-manager' ), 'desc' => __( 'Revenue breakdown by date, reservation, payment method. Includes refunds + convenience fees + tax.', 'equine-event-manager' ) ),
			array( 'slug' => 'customer_list', 'tone' => 'customers', 'title' => __( 'Customer List', 'equine-event-manager' ), 'desc' => __( 'All customers with contact info + order count + lifetime value. Good for marketing.', 'equine-event-manager' ) ),
			array( 'slug' => 'refund_log', 'tone' => 'refunds', 'title' => __( 'Refund Log', 'equine-event-manager' ), 'desc' => __( 'Refunds with amount, date, reason, and section. For reconciliation.', 'equine-event-manager' ) ),
		);
	}

	/**
	 * Hidden filter inputs shared by every export form (current filter state).
	 *
	 * @param array $filters Current filters.
	 * @return void
	 */
	private function filter_hidden_inputs( array $filters ): void {
		printf( '<input type="hidden" name="reservation_id" value="%d" data-eem-export-filter="reservation_id">', absint( $filters['reservation_id'] ) );
	}

	/**
	 * Render one export form (a single button posting to admin-post).
	 *
	 * @param string $slug    Report slug (or '__zip__' for the all-reports ZIP).
	 * @param string $format  'csv' | 'pdf' | 'zip'.
	 * @param string $label   Button label.
	 * @param array  $filters Current filters.
	 * @param string $class   Button class.
	 * @return void
	 */
	private function export_form( string $slug, string $format, string $label, array $filters, string $class = 'btn-export' ): void {
		// PDF = open a standalone print view in a new tab (admin prints / saves from
		// the browser). CSV = POST to admin-post, which streams a file download.
		if ( 'pdf' === $format ) {
			$print_url = add_query_arg(
				array_filter( array(
					'page'           => self::MENU_SLUG,
					'eem_report_print' => $slug,
					'reservation_id' => $filters['reservation_id'] ? (string) $filters['reservation_id'] : '',
				) ),
				admin_url( 'admin.php' )
			);
			?>
			<a class="<?php echo esc_attr( $class ); ?> btn-export--pdf" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener">
				<span class="format-label"><?php echo esc_html( $label ); ?></span>
			</a>
			<?php
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eem-export-form">
			<input type="hidden" name="action" value="eem_reports_export">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="report" value="<?php echo esc_attr( $slug ); ?>">
			<input type="hidden" name="format" value="<?php echo esc_attr( $format ); ?>">
			<?php $this->filter_hidden_inputs( $filters ); ?>
			<button class="<?php echo esc_attr( $class ); ?>" type="submit">
				<span class="format-label"><?php echo esc_html( $label ); ?></span>
			</button>
		</form>
		<?php
	}


	/**
	 * Global Filters card (GET form — Apply reloads with the filter query args).
	 *
	 * @param array $reservations Reservation posts.
	 * @param array $filters      Current filters.
	 * @return void
	 */
	private function render_filters_card( array $reservations, array $filters ): void {
		?>
		<div class="eem-card eem-reports-filter-card">
			<form method="get" class="eem-reports-filter-form" id="eem-reports-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<div class="eem-reports-filter-row">
					<div class="eem-filter-group">
						<label class="eem-filter-label" for="eem-filter-reservation"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></label>
						<select class="eem-filter-select" id="eem-filter-reservation" name="reservation_id" data-eem-export-filter="reservation_id" data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search reservations…', 'equine-event-manager' ); ?>">
							<option value="0"><?php esc_html_e( 'All reservations', 'equine-event-manager' ); ?></option>
							<?php foreach ( $reservations as $r ) : ?>
								<option value="<?php echo esc_attr( $r->ID ); ?>" <?php selected( $filters['reservation_id'], $r->ID ); ?>><?php echo esc_html( get_the_title( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * The "Individual reports" grid (6 cards, each with CSV + PDF export).
	 *
	 * @param array $filters Current filters.
	 * @return void
	 */
	private function render_report_catalog( array $filters ): void {
		?>
		<div class="eem-section-title"><?php esc_html_e( 'Individual reports', 'equine-event-manager' ); ?></div>
		<div class="eem-report-grid">
			<?php foreach ( $this->report_catalog() as $report ) : ?>
				<div class="eem-report-card">
					<div class="eem-report-card-head">
						<div class="eem-report-icon eem-report-icon-<?php echo esc_attr( $report['tone'] ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
						</div>
						<div>
							<div class="eem-report-card-title"><?php echo esc_html( $report['title'] ); ?></div>
							<div class="eem-report-card-desc"><?php echo esc_html( $report['desc'] ); ?></div>
						</div>
					</div>
					<div class="eem-report-card-actions">
						<?php $this->export_form( $report['slug'], 'csv', __( 'CSV', 'equine-event-manager' ), $filters ); ?>
						<?php $this->export_form( $report['slug'], 'pdf', __( 'PDF', 'equine-event-manager' ), $filters ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Export History — recent exports with re-download or expired-link re-export.
	 *
	 * @return void
	 */
	private function render_export_history(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}eem_report_exports ORDER BY created_at DESC LIMIT 25" ); // phpcs:ignore WordPress.DB
		if ( empty( $rows ) ) {
			return;
		}
		$exporter = new EEM_Report_Exporter();
		?>
		<?php $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=eem_reports_clear' ), self::NONCE_ACTION ); ?>
		<div class="eem-section-title eem-export-history-head">
			<span><?php esc_html_e( 'Export history', 'equine-event-manager' ); ?></span>
			<a class="eem-btn-clear-history" href="<?php echo esc_url( $clear_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear all export history and delete the cached files? This cannot be undone.', 'equine-event-manager' ) ); ?>');"><?php esc_html_e( 'Clear history', 'equine-event-manager' ); ?></a>
		</div>
		<div class="eem-card eem-export-history-card">
			<div class="eem-card-body">
				<div class="eem-desktop-table">
				<table class="eem-export-history">
					<thead><tr>
						<th><?php esc_html_e( 'Report', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Scope', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Exported', 'equine-event-manager' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$file      = (string) $row->file_name;
							$available = '' !== $file && $exporter->cached_exists( $file );
							$dl_url    = wp_nonce_url( admin_url( 'admin-post.php?action=eem_reports_download&file=' . rawurlencode( $file ) ), self::NONCE_ACTION );
							?>
							<tr>
								<td><?php echo esc_html( $file ); ?></td>
								<td><?php echo esc_html( (string) $row->reservation_name ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' g:i a', (string) $row->created_at ) ); ?></td>
								<td>
									<?php if ( $available ) : ?>
										<a class="eem-btn-download" href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download', 'equine-event-manager' ); ?></a>
									<?php else : ?>
										<span class="eem-expired-link"><?php esc_html_e( 'Expired — re-run the report above', 'equine-event-manager' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<div class="eem-mobile-cards">
					<?php foreach ( $rows as $row ) :
						$file      = (string) $row->file_name;
						$available = '' !== $file && $exporter->cached_exists( $file );
						$dl_url    = wp_nonce_url( admin_url( 'admin-post.php?action=eem_reports_download&file=' . rawurlencode( $file ) ), self::NONCE_ACTION );
						$exported  = mysql2date( get_option( 'date_format' ) . ' g:i a', (string) $row->created_at );
					?>
					<div class="eem-mobile-card">
						<div class="eem-mobile-card-top">
							<span class="eem-mobile-card-title"><?php echo esc_html( $file ); ?></span>
						</div>
						<div class="eem-mobile-card-meta">
							<span><?php echo esc_html( (string) $row->reservation_name ); ?></span>
							<span><?php echo esc_html( $exported ); ?></span>
						</div>
						<div class="eem-mobile-card-bottom">
							<div class="eem-mobile-card-actions">
								<?php if ( $available ) : ?>
									<a class="eem-btn-download" href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download', 'equine-event-manager' ); ?></a>
								<?php else : ?>
									<span class="eem-expired-link"><?php esc_html_e( 'Expired', 'equine-event-manager' ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Read + sanitize report filters from a request array.
	 *
	 * @param array $src Request source ($_POST / $_GET).
	 * @return array{reservation_id:int,date_from:string,date_to:string,status:string}
	 */
	public static function read_filters( array $src ): array {
		// #55: previously dropped date_from/date_to/status, so the Reports page's
		// date-range + status filters never reached the repo (companion to the
		// EEM_Reports_Repo::normalize_filters fix). Read + sanitize all four;
		// status is lowercased to match the canonical slug form.
		return array(
			'reservation_id' => isset( $src['reservation_id'] ) ? absint( $src['reservation_id'] ) : 0,
			'date_from'      => isset( $src['date_from'] ) ? sanitize_text_field( (string) $src['date_from'] ) : '',
			'date_to'        => isset( $src['date_to'] ) ? sanitize_text_field( (string) $src['date_to'] ) : '',
			'status'         => isset( $src['status'] ) ? strtolower( sanitize_text_field( (string) $src['status'] ) ) : '',
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
		$slug     = sanitize_key( $slug );
		$format   = sanitize_key( $format );
		$repo     = new EEM_Reports_Repo();
		$exporter = new EEM_Report_Exporter();
		$norm     = $repo->normalize_filters( $filters );

		// ZIP = all 6 reports × CSV + PDF in one archive (slug ignored).
		if ( 'zip' === $format ) {
			return self::generate_zip( $repo, $exporter, $norm );
		}

		if ( ! in_array( $slug, EEM_Reports_Repo::REPORTS, true ) ) {
			return new WP_Error( 'eem_reports_bad_slug', __( 'Unknown report.', 'equine-event-manager' ) );
		}
		if ( ! in_array( $format, array( 'csv', 'pdf' ), true ) ) {
			return new WP_Error( 'eem_reports_bad_format', __( 'Unknown export format.', 'equine-event-manager' ) );
		}

		$report = $repo->get_report( $slug, $norm );

		if ( 'pdf' === $format ) {
			$contents = $exporter->build_pdf( $report, self::pdf_meta( $norm ) );
			$ext      = 'pdf';
			if ( '' === $contents ) {
				return new WP_Error( 'eem_reports_pdf_unavailable', __( 'PDF export is unavailable (the PDF engine could not run).', 'equine-event-manager' ) );
			}
		} else {
			$contents = $exporter->build_csv( $report );
			$ext      = 'csv';
			if ( '' === $contents ) {
				return new WP_Error( 'eem_reports_empty', __( 'The report could not be generated.', 'equine-event-manager' ) );
			}
		}

		$filename = $exporter->export_filename( $slug, $norm['reservation_id'], $ext );
		$path     = $exporter->write_to_cache( $filename, $contents );
		if ( '' === $path ) {
			return new WP_Error( 'eem_reports_cache_failed', __( 'The export could not be written to disk.', 'equine-event-manager' ) );
		}

		self::log_export( $norm['reservation_id'], $filename );
		$exporter->purge_old();

		return array( 'path' => $path, 'filename' => $filename );
	}

	/**
	 * Generate the all-reports ZIP (6 reports × CSV + PDF).
	 *
	 * @param EEM_Reports_Repo     $repo     Repo.
	 * @param EEM_Report_Exporter  $exporter Exporter.
	 * @param array                $norm     Normalized filters.
	 * @return array{path:string,filename:string}|WP_Error
	 */
	private static function generate_zip( EEM_Reports_Repo $repo, EEM_Report_Exporter $exporter, array $norm ) {
		if ( ! $exporter->zip_available() ) {
			return new WP_Error( 'eem_reports_no_zip', __( 'ZIP export is unavailable on this server (the PHP zip extension is not installed).', 'equine-event-manager' ) );
		}

		$meta  = self::pdf_meta( $norm );
		$files = array();
		foreach ( EEM_Reports_Repo::REPORTS as $slug ) {
			$report                  = $repo->get_report( $slug, $norm );
			$files[ $slug . '.csv' ] = $exporter->build_csv( $report );
			$pdf                     = $exporter->build_pdf( $report, $meta );
			if ( '' !== $pdf ) {
				$files[ $slug . '.pdf' ] = $pdf;
			}
		}

		$zip = $exporter->build_zip( $files );
		if ( '' === $zip ) {
			return new WP_Error( 'eem_reports_zip_failed', __( 'The ZIP archive could not be built.', 'equine-event-manager' ) );
		}

		$filename = $exporter->export_filename( 'all-reports', $norm['reservation_id'], 'zip' );
		$path     = $exporter->write_to_cache( $filename, $zip );
		if ( '' === $path ) {
			return new WP_Error( 'eem_reports_cache_failed', __( 'The export could not be written to disk.', 'equine-event-manager' ) );
		}

		self::log_export( $norm['reservation_id'], $filename );
		$exporter->purge_old();

		return array( 'path' => $path, 'filename' => $filename );
	}

	/**
	 * Standalone report print view (Daily Movement pattern): a full HTML document
	 * with no WP admin chrome, the locked print-view styling, and a Print / Save PDF
	 * toolbar. Triggered by ?eem_report_print=<slug>. Reuses the same {headers,rows}
	 * report data the CSV export uses; if the report supplies a `groups` array, the
	 * rows render grouped (amber band per group) with per-group + grand totals.
	 *
	 * @return void
	 */
	private function render_print_view(): void {
		$slug = sanitize_key( wp_unslash( $_GET['eem_report_print'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render.
		if ( ! in_array( $slug, EEM_Reports_Repo::REPORTS, true ) ) {
			wp_die( esc_html__( 'Unknown report.', 'equine-event-manager' ) );
		}

		$repo   = new EEM_Reports_Repo();
		$norm   = $repo->normalize_filters( self::read_filters( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report = $repo->get_report( $slug, $norm );
		$meta   = self::pdf_meta( $norm );

		$all_headers  = isset( $report['headers'] ) && is_array( $report['headers'] ) ? $report['headers'] : array();
		$all_rows     = isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array();
		$groups       = isset( $report['groups'] ) && is_array( $report['groups'] ) ? $report['groups'] : array();
		$event_header = isset( $report['event_header'] ) ? (string) $report['event_header'] : '';
		$title     = (string) ( $report['title'] ?? __( 'Report', 'equine-event-manager' ) );
		$total_lbl = isset( $report['total_label'] ) ? (string) $report['total_label'] : '';
		$doc_title = $title . ' — ' . ( $norm['reservation_id'] > 0 ? get_the_title( $norm['reservation_id'] ) : __( 'All reservations', 'equine-event-manager' ) );

		// print_columns: optional array of column indices to show in the print view.
		// When absent, all columns are shown. CSV always uses the full dataset.
		$print_cols = isset( $report['print_columns'] ) && is_array( $report['print_columns'] )
			? array_map( 'absint', $report['print_columns'] )
			: array_keys( $all_headers );

		$headers = array_values( array_intersect_key( $all_headers, array_flip( $print_cols ) ) );
		$rows    = array_map(
			static function ( array $row ) use ( $print_cols ): array {
				$out = array();
				foreach ( $print_cols as $i ) {
					$out[] = $row[ $i ] ?? '';
				}
				return $out;
			},
			$all_rows
		);
		if ( ! empty( $groups ) ) {
			$groups = array_map(
				static function ( array $group ) use ( $print_cols ): array {
					$group['rows'] = array_map(
						static function ( array $row ) use ( $print_cols ): array {
							$out = array();
							foreach ( $print_cols as $i ) {
								$out[] = $row[ $i ] ?? '';
							}
							return $out;
						},
						isset( $group['rows'] ) && is_array( $group['rows'] ) ? $group['rows'] : array()
					);
					return $group;
				},
				$groups
			);
		}

		$colspan           = max( 1, count( $headers ) );
		$summary_row_count = isset( $report['summary_row_count'] ) ? absint( $report['summary_row_count'] ) : 0;
		$logo_url          = esc_url( EQUINE_EVENT_MANAGER_URL . 'assets/images/logo.png' );
		$back_url          = esc_url( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) );

		// Renders one <tr>; $is_summary adds the totals highlight class.
		$render_row = static function ( array $row, bool $is_summary = false ) use ( $headers ): void {
			echo $is_summary ? '<tr class="pv-summary">' : '<tr>';
			foreach ( $headers as $i => $_ ) {
				echo '<td>' . esc_html( (string) ( $row[ $i ] ?? '' ) ) . '</td>';
			}
			echo '</tr>';
		};

		$total_row_count = ! empty( $groups )
			? array_sum( array_map( static fn( $g ) => count( $g['rows'] ?? array() ), $groups ) )
			: count( $rows );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $doc_title ); ?></title>
			<link href="<?php echo esc_url( EQUINE_EVENT_MANAGER_URL . 'assets/css/eem-fonts.css' ); ?>" rel="stylesheet">
			<style>
				*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
				body{font-family:'IBM Plex Sans',sans-serif;color:#0d1b3e;background:#fff;font-size:14px;line-height:1.5}
				a{text-decoration:none;color:inherit}
				svg{flex-shrink:0}
				.pv-topbar{background:#fff;border-bottom:1px solid #e2e8f4;padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:10}
				.pv-topbar-left{display:flex;align-items:center;gap:12px;min-width:0}
				.pv-logo img{height:28px;width:auto;display:block}
				.pv-title{font-size:14px;font-weight:700;color:#0d1b3e}
				.pv-sub{font-size:12px;color:#64748b;margin-top:1px}
				.pv-topbar-btns{display:flex;gap:8px;flex-shrink:0}
				.btn-print{background:#1668F2;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
				.btn-print:hover{background:#0d4fc2}
				.btn-exit{background:#F7F9FC;color:#0d1b3e;border:1.5px solid #d0daea;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
				.btn-exit:hover{background:#e8eef8}
				a.btn-exit,a.btn-exit:link,a.btn-exit:visited{color:#0d1b3e;text-decoration:none}
				.pv-body{padding:24px 28px;max-width:1000px;margin:0 auto}
				.pv-header{margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #1668F2}
				.pv-eyebrow{font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin-bottom:4px}
				.pv-header-title{font-size:22px;font-weight:700;color:#0d1b3e;margin-bottom:4px;letter-spacing:-.02em}
				.pv-header-event{font-size:15px;font-weight:600;color:#0d1b3e;margin-bottom:4px}
				.pv-meta{font-size:12.5px;color:#64748b}
				.pv-table-wrap{border:1px solid #e2e8f4;border-radius:10px;overflow:hidden;margin-bottom:0}
				.pv-table{width:100%;border-collapse:collapse;background:#fff}
				.pv-table thead tr{background:#F7F9FC;border-bottom:1px solid #e2e8f4}
				.pv-table thead th{padding:9px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;text-align:left;white-space:nowrap}
				.pv-table tbody tr{border-bottom:1px solid #f0f4fb}
				.pv-table tbody tr:last-child{border-bottom:none}
				.pv-table td{padding:8px 12px;font-size:12.5px;vertical-align:middle;color:#0d1b3e}
				.pv-group td{background:#fffbeb;color:#b45309;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #fde68a;border-bottom:1px solid #fde68a}
				.pv-group-count{font-weight:600;color:#92400e;margin-left:8px}
				.pv-summary td{background:#0d1b3e!important;color:#fff!important;font-weight:700;font-size:12px}
				.pv-total{padding:11px 16px;font-size:13px;font-weight:700;color:#0d1b3e;border-top:2px solid #1668F2;background:#F7F9FC}
				.pv-empty{padding:28px 22px;text-align:center;color:#64748b}
				.pv-note-section{margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f4}
				.pv-note-label{font-size:13px;font-weight:700;color:#0d1b3e;margin-bottom:10px}
				.pv-footer{margin-top:18px;padding-top:12px;border-top:1px solid #e2e8f4;display:flex;justify-content:space-between;font-size:12px;color:#64748b;flex-wrap:wrap;gap:8px}
				@media(max-width:900px){
					.btn-exit .btn-label,.btn-print .btn-label{display:none}
					.btn-exit,.btn-print{width:36px;height:36px;padding:0;justify-content:center}
				}
				@media(max-width:767px){
					.pv-topbar{flex-direction:column;align-items:flex-start;gap:8px;padding:10px 14px;height:auto}
					.pv-topbar-btns{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:8px}
					.btn-print,.btn-exit{width:100%;justify-content:center}
					.pv-body{padding:14px}
				}
				@media print{
					.pv-topbar{display:none}
					.pv-body{padding:0;max-width:none}
					body{background:#fff;font-size:11px}
					.pv-header-title{font-size:18px}
					.pv-table-wrap{border-radius:0;border:none}
					.pv-table thead tr,.pv-group td,.pv-summary td{-webkit-print-color-adjust:exact;print-color-adjust:exact}
					.pv-table tbody tr{page-break-inside:avoid;break-inside:avoid}
					.pv-table td,.pv-table th{padding:5px 8px;font-size:10px}
					@page{size:landscape;margin:0.6in 0.5in 0.7in 0.5in}
					*{-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact}
				}
			</style>
		</head>
		<body>
			<div class="pv-topbar">
				<div class="pv-topbar-left">
					<div class="pv-logo"><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Equine Event Manager', 'equine-event-manager' ); ?>"></div>
					<div>
						<div class="pv-title"><?php echo esc_html( $title ); ?></div>
						<?php if ( '' !== $meta['subtitle'] ) : ?>
							<div class="pv-sub"><?php echo esc_html( $meta['subtitle'] ); ?></div>
						<?php endif; ?>
					</div>
				</div>
				<div class="pv-topbar-btns">
					<a href="<?php echo esc_url( $back_url ); ?>" class="btn-exit">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
						<span class="btn-label"><?php esc_html_e( 'Back to Reports', 'equine-event-manager' ); ?></span>
					</a>
					<button class="btn-print" onclick="window.print()">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
						<span class="btn-label"><?php esc_html_e( 'Print / Save PDF', 'equine-event-manager' ); ?></span>
					</button>
				</div>
			</div>
			<div class="pv-body">
				<div class="pv-header">
					<div class="pv-eyebrow"><?php esc_html_e( 'Report', 'equine-event-manager' ); ?></div>
					<div class="pv-header-title"><?php echo esc_html( $title ); ?></div>
					<?php if ( '' !== $event_header ) : ?>
						<div class="pv-header-event"><?php echo esc_html( $event_header ); ?></div>
					<?php endif; ?>
					<div class="pv-meta"><?php echo esc_html( $meta['subtitle'] ); ?> &nbsp;·&nbsp; <?php echo esc_html( $meta['generated'] ); ?></div>
				</div>

				<?php if ( empty( $rows ) && empty( $groups ) ) : ?>
					<div class="pv-table-wrap"><div class="pv-empty"><?php esc_html_e( 'No data matches the current filters.', 'equine-event-manager' ); ?></div></div>
				<?php else : ?>
					<div class="pv-table-wrap">
						<table class="pv-table">
							<thead>
								<tr><?php foreach ( $headers as $h ) : ?><th><?php echo esc_html( (string) $h ); ?></th><?php endforeach; ?></tr>
							</thead>
							<tbody>
								<?php if ( ! empty( $groups ) ) : ?>
									<?php foreach ( $groups as $group ) : ?>
										<tr class="pv-group">
											<td colspan="<?php echo esc_attr( $colspan ); ?>">
												<?php echo esc_html( (string) ( $group['label'] ?? '' ) ); ?>
												<span class="pv-group-count"><?php echo esc_html( sprintf( /* translators: %d: order count */ _n( '%d order', '%d orders', (int) ( $group['count'] ?? 0 ), 'equine-event-manager' ), (int) ( $group['count'] ?? 0 ) ) ); ?></span>
											</td>
										</tr>
										<?php foreach ( (array) ( $group['rows'] ?? array() ) as $row ) : ?>
											<?php $render_row( (array) $row ); ?>
										<?php endforeach; ?>
									<?php endforeach; ?>
								<?php else : ?>
									<?php foreach ( $rows as $idx => $row ) : ?>
										<?php $render_row( (array) $row, $idx < $summary_row_count ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
						<div class="pv-total">
							<?php
							if ( '' !== $total_lbl ) {
								echo esc_html( $total_lbl );
							} else {
								echo esc_html( sprintf( /* translators: %s: row count */ _n( '%s row', '%s rows', $total_row_count, 'equine-event-manager' ), number_format_i18n( $total_row_count ) ) );
							}
							?>
						</div>
					</div>
				<?php endif; ?>

				<?php
				$note_sections = isset( $report['note_sections'] ) && is_array( $report['note_sections'] ) ? $report['note_sections'] : array();
				foreach ( $note_sections as $ns ) :
					if ( empty( $ns['rows'] ) ) { continue; }
					$ns_headers = isset( $ns['headers'] ) && is_array( $ns['headers'] ) ? $ns['headers'] : array();
					$ns_label   = isset( $ns['label'] ) ? (string) $ns['label'] : '';
				?>
				<div class="pv-note-section">
					<?php if ( '' !== $ns_label ) : ?>
						<div class="pv-note-label"><?php echo esc_html( $ns_label ); ?></div>
					<?php endif; ?>
					<div class="pv-table-wrap">
						<table class="pv-table">
							<?php if ( ! empty( $ns_headers ) ) : ?>
							<thead><tr><?php foreach ( $ns_headers as $h ) : ?><th><?php echo esc_html( (string) $h ); ?></th><?php endforeach; ?></tr></thead>
							<?php endif; ?>
							<tbody>
								<?php foreach ( $ns['rows'] as $ns_row ) : ?>
									<tr><?php foreach ( (array) $ns_row as $cell ) : ?><td><?php echo esc_html( (string) $cell ); ?></td><?php endforeach; ?></tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<?php endforeach; ?>

				<div class="pv-footer">
					<span><?php echo esc_html( $title . ' · ' . $meta['subtitle'] ); ?></span>
					<span><?php echo esc_html( get_bloginfo( 'name' ) . ' · ' . $meta['generated'] ); ?></span>
				</div>
			</div>
			<script>document.title = <?php echo wp_json_encode( $doc_title ); ?>;</script>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Build the PDF header meta (subtitle + generated stamp) from filters.
	 *
	 * @param array $norm Normalized filters.
	 * @return array{subtitle:string,generated:string}
	 */
	private static function pdf_meta( array $norm ): array {
		$scope = $norm['reservation_id'] > 0 ? get_the_title( $norm['reservation_id'] ) : __( 'All reservations', 'equine-event-manager' );

		return array(
			'subtitle'  => (string) $scope,
			/* translators: %s: date/time. */
			'generated' => sprintf( __( 'Generated %s', 'equine-event-manager' ), date_i18n( get_option( 'date_format' ) . ' g:i a' ) ),
		);
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
		$table = $wpdb->prefix . 'eem_report_exports';
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
		// PDFs render inline (open in the new tab); everything else downloads.
		$disposition = ( 'pdf' === $ext ) ? 'inline' : 'attachment';
		header( 'Content-Disposition: ' . $disposition . '; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
