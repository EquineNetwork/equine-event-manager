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
			'subtitle'   => __( 'View, export, and re-download reports for one or all reservations. Use filters to narrow results, then export individual reports as CSV or PDF — or grab everything at once as a ZIP.', 'equine-event-manager' ),
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
				$this->render_zip_card( $reservations, $filters );
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
	 * Inline notice after an export redirect (e.g. ?eem_notice=...).
	 *
	 * @return void
	 */
	private function render_action_notice(): void {
		$notice = isset( $_GET['eem_notice'] ) ? sanitize_key( wp_unslash( $_GET['eem_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'export_failed' === $notice ) {
			echo '<div class="eem-admin-notice eem-admin-notice--error">' . esc_html__( 'That report could not be exported. Please try again.', 'equine-event-manager' ) . '</div>';
		}
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
			array( 'slug' => 'revenue', 'tone' => 'revenue', 'title' => __( 'Revenue', 'equine-event-manager' ), 'desc' => __( 'Revenue breakdown by date, reservation, payment method. Includes refunds + convenience fees + tax.', 'equine-event-manager' ) ),
			array( 'slug' => 'stall_occupancy', 'tone' => 'occupancy', 'title' => __( 'Stall Occupancy', 'equine-event-manager' ), 'desc' => __( 'Stall + RV lot utilization per event. Capacity, fill rate, booked counts.', 'equine-event-manager' ) ),
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
		printf( '<input type="hidden" name="date_from" value="%s" data-eem-export-filter="date_from">', esc_attr( $filters['date_from'] ) );
		printf( '<input type="hidden" name="date_to" value="%s" data-eem-export-filter="date_to">', esc_attr( $filters['date_to'] ) );
		printf( '<input type="hidden" name="status" value="%s" data-eem-export-filter="status">', esc_attr( $filters['status'] ) );
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
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eem-export-form">
			<input type="hidden" name="action" value="eem_reports_export">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="report" value="<?php echo esc_attr( $slug ); ?>">
			<input type="hidden" name="format" value="<?php echo esc_attr( $format ); ?>">
			<?php $this->filter_hidden_inputs( $filters ); ?>
			<button class="<?php echo esc_attr( $class ); ?>" type="submit">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
				<span class="format-label"><?php echo esc_html( $label ); ?></span>
			</button>
		</form>
		<?php
	}

	/**
	 * ZIP "export all reports for one reservation" card.
	 *
	 * @param array $reservations Reservation posts.
	 * @param array $filters      Current filters.
	 * @return void
	 */
	private function render_zip_card( array $reservations, array $filters ): void {
		?>
		<div class="eem-card eem-card-zip">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php esc_html_e( 'Export all reports for one reservation', 'equine-event-manager' ); ?></div>
				<div class="eem-card-subtitle"><?php esc_html_e( 'One ZIP file with all 6 reports (Orders, Reservations, Revenue, Stall Occupancy, Customer List, Refund Log) in both CSV and PDF format.', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-card-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eem-zip-controls">
					<input type="hidden" name="action" value="eem_reports_export">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="report" value="orders">
					<input type="hidden" name="format" value="zip">
					<input type="hidden" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
					<input type="hidden" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
					<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>">
					<select class="eem-zip-select" name="reservation_id">
						<?php foreach ( $reservations as $r ) : ?>
							<option value="<?php echo esc_attr( $r->ID ); ?>" <?php selected( $filters['reservation_id'], $r->ID ); ?>><?php echo esc_html( get_the_title( $r ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="eem-btn-zip" type="submit">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export ZIP (6 reports × CSV + PDF)', 'equine-event-manager' ); ?>
					</button>
				</form>
			</div>
		</div>
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
		$statuses = array(
			''             => __( 'All statuses', 'equine-event-manager' ),
			'paid'         => __( 'Paid', 'equine-event-manager' ),
			'unpaid'       => __( 'Unpaid', 'equine-event-manager' ),
			'invoice_sent' => __( 'Invoice Sent', 'equine-event-manager' ),
			'partial'      => __( 'Partial', 'equine-event-manager' ),
			'refunded'     => __( 'Refunded', 'equine-event-manager' ),
			'cancelled'    => __( 'Cancelled', 'equine-event-manager' ),
		);
		?>
		<div class="eem-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php esc_html_e( 'Filters', 'equine-event-manager' ); ?> <span class="eem-filters-applied-pill"><?php esc_html_e( 'Applies to all reports below', 'equine-event-manager' ); ?></span></div>
			</div>
			<div class="eem-card-body">
				<form method="get" class="eem-reports-filter-form" id="eem-reports-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
					<div class="eem-filter-grid">
						<div class="eem-filter-group">
							<label class="eem-filter-label" for="eem-filter-reservation"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></label>
							<select class="eem-filter-select" id="eem-filter-reservation" name="reservation_id">
								<option value="0"><?php esc_html_e( 'All reservations', 'equine-event-manager' ); ?></option>
								<?php foreach ( $reservations as $r ) : ?>
									<option value="<?php echo esc_attr( $r->ID ); ?>" <?php selected( $filters['reservation_id'], $r->ID ); ?>><?php echo esc_html( get_the_title( $r ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="eem-filter-group">
							<label class="eem-filter-label" for="eem-filter-preset"><?php esc_html_e( 'Date range', 'equine-event-manager' ); ?></label>
							<select class="eem-filter-select" id="eem-filter-preset" name="date_preset" data-eem-date-preset>
								<option value="last-30"><?php esc_html_e( 'Last 30 days', 'equine-event-manager' ); ?></option>
								<option value="last-7"><?php esc_html_e( 'Last 7 days', 'equine-event-manager' ); ?></option>
								<option value="last-90"><?php esc_html_e( 'Last 90 days', 'equine-event-manager' ); ?></option>
								<option value="this-year"><?php esc_html_e( 'This year', 'equine-event-manager' ); ?></option>
								<option value="all"><?php esc_html_e( 'All time', 'equine-event-manager' ); ?></option>
								<option value="custom" selected><?php esc_html_e( 'Custom range', 'equine-event-manager' ); ?></option>
							</select>
							<div class="eem-daterange-inputs">
								<input class="eem-filter-input" type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" data-eem-date-input>
								<span class="eem-daterange-sep"><?php esc_html_e( 'to', 'equine-event-manager' ); ?></span>
								<input class="eem-filter-input" type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" data-eem-date-input>
							</div>
						</div>
						<div class="eem-filter-group">
							<label class="eem-filter-label" for="eem-filter-status"><?php esc_html_e( 'Order status', 'equine-event-manager' ); ?></label>
							<select class="eem-filter-select" id="eem-filter-status" name="status">
								<?php foreach ( $statuses as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filters['status'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="eem-filter-footer">
						<a class="eem-filter-reset" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Reset filters', 'equine-event-manager' ); ?></a>
						<button class="eem-btn eem-btn-electric" type="submit"><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
					</div>
				</form>
			</div>
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
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}en_report_exports ORDER BY created_at DESC LIMIT 25" ); // phpcs:ignore WordPress.DB
		if ( empty( $rows ) ) {
			return;
		}
		$exporter = new EEM_Report_Exporter();
		?>
		<div class="eem-section-title"><?php esc_html_e( 'Export history', 'equine-event-manager' ); ?></div>
		<div class="eem-card">
			<div class="eem-card-body">
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
	 * Build the PDF header meta (subtitle + generated stamp) from filters.
	 *
	 * @param array $norm Normalized filters.
	 * @return array{subtitle:string,generated:string}
	 */
	private static function pdf_meta( array $norm ): array {
		$scope  = $norm['reservation_id'] > 0 ? get_the_title( $norm['reservation_id'] ) : __( 'All reservations', 'equine-event-manager' );
		$range  = ( '' !== $norm['date_from'] || '' !== $norm['date_to'] )
			? trim( ( '' !== $norm['date_from'] ? $norm['date_from'] : '…' ) . ' – ' . ( '' !== $norm['date_to'] ? $norm['date_to'] : '…' ) )
			: __( 'All time', 'equine-event-manager' );
		$status = '' !== $norm['status'] ? ucwords( str_replace( '_', ' ', $norm['status'] ) ) : __( 'All statuses', 'equine-event-manager' );

		return array(
			'subtitle'  => $scope . '  ·  ' . $range . '  ·  ' . $status,
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
