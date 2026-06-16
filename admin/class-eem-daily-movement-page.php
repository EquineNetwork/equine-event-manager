<?php
/**
 * Daily Movement admin page.
 *
 * Staff-facing page under Reports showing arrivals, departures, and check-in
 * status for a selected reservation and date. Layout: stat cards strip,
 * toolbar (reservation + date picker), filter tabs (Today / All Days),
 * then data tables grouped by date. Print-friendly via @media print.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Daily_Movement_Page {

	const MENU_SLUG = 'equine-event-manager-daily-movement';

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-daily-movement-service.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter state.
		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$view           = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'today';
		$date           = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$reservations = get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => array( 'publish' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		if ( 0 === $reservation_id && ! empty( $reservations ) ) {
			$reservation_id = (int) $reservations[0]->ID;
		}

		$reports     = array();
		$report_date = '';

		if ( $reservation_id > 0 ) {
			if ( 'all' === $view ) {
				$reports = EEM_Daily_Movement_Service::build_all_dates_report( $reservation_id );
			} elseif ( '' !== $date ) {
				$reports     = array( EEM_Daily_Movement_Service::build_date_report( $reservation_id, $date ) );
				$report_date = $date;
			} else {
				$today       = wp_date( 'Y-m-d' );
				$reports     = array( EEM_Daily_Movement_Service::build_date_report( $reservation_id, $today ) );
				$report_date = $today;
			}
		}

		$reservation_title = $reservation_id > 0 ? get_the_title( $reservation_id ) : '';
		$totals            = self::compute_totals( $reports );

		$print_btn = '<button type="button" class="eem-btn eem-btn-outline" onclick="window.print()">' . esc_html__( 'Print', 'equine-event-manager' ) . '</button>';

		eem_render_page_open( array(
			'title'      => __( 'Daily Movement', 'equine-event-manager' ),
			'subtitle'   => __( 'Arrivals, departures, and check-in status for on-the-ground staff.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array(
					'label' => __( 'Reports', 'equine-event-manager' ),
					'url'   => admin_url( 'admin.php?page=equine-event-manager-reports' ),
				),
				array( 'label' => __( 'Daily Movement', 'equine-event-manager' ) ),
			),
			'actions' => $print_btn,
		) );

		self::render_stat_cards( $totals );
		self::render_toolbar( $reservations, $reservation_id, $date );
		self::render_view_tabs( $reservation_id, $view, $date );

		if ( empty( $reservations ) ) {
			echo '<div class="eem-dm-empty"><p>' . esc_html__( 'No published reservations yet. Create and publish a reservation to see movement data.', 'equine-event-manager' ) . '</p></div>';
		} elseif ( empty( $reports ) || ( 1 === count( $reports ) && 0 === $totals['arriving'] && 0 === $totals['departing'] ) ) {
			echo '<div class="eem-dm-empty"><p>' . esc_html__( 'No movement data for the selected date. Try "All Days" to see all activity.', 'equine-event-manager' ) . '</p></div>';
		} else {
			self::render_print_header( $reservation_title, $reports, $view, $report_date );
			foreach ( $reports as $report ) {
				self::render_date_section( $report, 'all' === $view );
			}
		}

		eem_render_page_close();
	}

	/**
	 * Aggregate totals across all report dates for the stat cards.
	 *
	 * @param array $reports Report data from the service.
	 * @return array{arriving: int, departing: int, not_yet_checked_in: int, checked_in: int}
	 */
	private static function compute_totals( array $reports ): array {
		$totals = array(
			'arriving'           => 0,
			'departing'          => 0,
			'not_yet_checked_in' => 0,
			'checked_in'         => 0,
		);
		foreach ( $reports as $report ) {
			$totals['arriving']           += $report['summary']['arriving'];
			$totals['departing']          += $report['summary']['departing'];
			$totals['not_yet_checked_in'] += $report['summary']['not_yet_checked_in'];

			foreach ( array_merge( $report['arriving'] ?? array(), $report['departing'] ?? array() ) as $row ) {
				if ( 'checked_in' === $row['check_in_status'] ) {
					++$totals['checked_in'];
				}
			}
		}
		return $totals;
	}

	/**
	 * Render the stat cards strip.
	 *
	 * @param array $totals Aggregated totals.
	 * @return void
	 */
	private static function render_stat_cards( array $totals ): void {
		?>
		<div class="eem-dm-stats">
			<div class="eem-stat-card">
				<div class="eem-stat-card-label"><?php esc_html_e( 'Arriving', 'equine-event-manager' ); ?></div>
				<div class="eem-stat-card-num" style="color: #15803d;"><?php echo (int) $totals['arriving']; ?></div>
			</div>
			<div class="eem-stat-card">
				<div class="eem-stat-card-label"><?php esc_html_e( 'Departing', 'equine-event-manager' ); ?></div>
				<div class="eem-stat-card-num" style="color: #a16207;"><?php echo (int) $totals['departing']; ?></div>
			</div>
			<div class="eem-stat-card">
				<div class="eem-stat-card-label"><?php esc_html_e( 'Checked In', 'equine-event-manager' ); ?></div>
				<div class="eem-stat-card-num" style="color: #1d4ed8;"><?php echo (int) $totals['checked_in']; ?></div>
			</div>
			<div class="eem-stat-card">
				<div class="eem-stat-card-label"><?php esc_html_e( 'Not Yet Checked In', 'equine-event-manager' ); ?></div>
				<div class="eem-stat-card-num" style="color: #dc2626;"><?php echo (int) $totals['not_yet_checked_in']; ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the toolbar: reservation selector + date picker + Go on one row.
	 *
	 * @param WP_Post[] $reservations Available reservations.
	 * @param int       $reservation_id Selected reservation ID.
	 * @param string    $date Selected date.
	 * @return void
	 */
	private static function render_toolbar( array $reservations, int $reservation_id, string $date ): void {
		?>
		<div class="eem-dm-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="eem-dm-toolbar-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">

				<div class="eem-dm-toolbar-group">
					<label class="eem-dm-toolbar-label" for="eem-dm-reservation"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></label>
					<select name="reservation_id" id="eem-dm-reservation" class="eem-field-select">
						<?php foreach ( $reservations as $res ) : ?>
							<option value="<?php echo esc_attr( (string) $res->ID ); ?>" <?php selected( $reservation_id, $res->ID ); ?>>
								<?php echo esc_html( $res->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="eem-dm-toolbar-group">
					<label class="eem-dm-toolbar-label" for="eem-dm-date"><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></label>
					<div class="eem-dm-toolbar-date">
						<input type="date" name="date" id="eem-dm-date" class="eem-field-input" value="<?php echo esc_attr( $date ); ?>">
						<button type="submit" class="eem-btn eem-btn-sm eem-btn-electric"><?php esc_html_e( 'Go', 'equine-event-manager' ); ?></button>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Today / All Days filter tabs.
	 *
	 * @param int    $reservation_id Selected reservation ID.
	 * @param string $view Current view.
	 * @param string $date Selected date.
	 * @return void
	 */
	private static function render_view_tabs( int $reservation_id, string $view, string $date ): void {
		$is_today = ( 'all' !== $view && '' === $date );
		$is_all   = ( 'all' === $view );
		$base     = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&reservation_id=' . $reservation_id );
		?>
		<div class="eem-dm-view-tabs">
			<a class="eem-filter-tab<?php echo $is_today ? ' active' : ''; ?>" href="<?php echo esc_url( $base . '&view=today' ); ?>">
				<?php esc_html_e( 'Today', 'equine-event-manager' ); ?>
			</a>
			<a class="eem-filter-tab<?php echo $is_all ? ' active' : ''; ?>" href="<?php echo esc_url( $base . '&view=all' ); ?>">
				<?php esc_html_e( 'All Days', 'equine-event-manager' ); ?>
			</a>
			<?php if ( '' !== $date && ! $is_all ) : ?>
				<a class="eem-filter-tab active" href="<?php echo esc_url( $base . '&date=' . urlencode( $date ) ); ?>">
					<?php echo esc_html( EEM_Daily_Movement_Service::format_display_date( $date ) ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render print-only header.
	 *
	 * @param string $title       Reservation title.
	 * @param array  $reports     Report data.
	 * @param string $view        Current view.
	 * @param string $report_date Selected date.
	 * @return void
	 */
	private static function render_print_header( string $title, array $reports, string $view, string $report_date ): void {
		$date_label = '';
		if ( 'all' === $view ) {
			$date_label = __( 'All Days', 'equine-event-manager' );
		} elseif ( '' !== $report_date ) {
			$date_label = EEM_Daily_Movement_Service::format_display_date( $report_date );
		}
		?>
		<div class="eem-dm-print-header">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( '' !== $date_label ) : ?>
				<p><?php echo esc_html( $date_label ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single date's movement section.
	 *
	 * @param array $report     Report data from the service.
	 * @param bool  $show_date  Whether to show the date heading.
	 * @return void
	 */
	private static function render_date_section( array $report, bool $show_date ): void {
		?>
		<div class="eem-dm-date-section">
			<?php if ( $show_date ) : ?>
				<h3 class="eem-dm-date-heading"><?php echo esc_html( $report['date_display'] ); ?></h3>
			<?php endif; ?>

			<div class="eem-dm-summary">
				<span class="eem-dm-summary-item eem-dm-summary-arriving">
					<?php printf( esc_html__( '%d Arriving', 'equine-event-manager' ), $report['summary']['arriving'] ); ?>
				</span>
				<span class="eem-dm-summary-item eem-dm-summary-departing">
					<?php printf( esc_html__( '%d Departing', 'equine-event-manager' ), $report['summary']['departing'] ); ?>
				</span>
				<span class="eem-dm-summary-item eem-dm-summary-pending">
					<?php printf( esc_html__( '%d Not Yet Checked In', 'equine-event-manager' ), $report['summary']['not_yet_checked_in'] ); ?>
				</span>
			</div>

			<?php if ( ! empty( $report['arriving'] ) ) : ?>
				<h4 class="eem-dm-group-heading"><?php esc_html_e( 'Arriving', 'equine-event-manager' ); ?></h4>
				<?php self::render_table( $report['arriving'] ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $report['departing'] ) ) : ?>
				<h4 class="eem-dm-group-heading"><?php esc_html_e( 'Departing', 'equine-event-manager' ); ?></h4>
				<?php self::render_table( $report['departing'] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the movement data table.
	 *
	 * @param array $rows Shaped movement rows.
	 * @return void
	 */
	private static function render_table( array $rows ): void {
		usort( $rows, function ( $a, $b ) {
			$a_stalls = implode( ',', $a['stall_numbers'] );
			$b_stalls = implode( ',', $b['stall_numbers'] );
			return strnatcasecmp( $a_stalls, $b_stalls );
		} );
		?>
		<table class="eem-dm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Stall #', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Dates', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Shavings', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'equine-event-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td class="eem-dm-cell-stall"><?php echo esc_html( implode( ', ', $row['stall_numbers'] ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $row['customer_name'] ); ?></td>
						<td class="eem-dm-cell-dates">
							<?php
							echo esc_html(
								EEM_Daily_Movement_Service::format_display_date( $row['arrival_date'] )
								. ' → '
								. EEM_Daily_Movement_Service::format_display_date( $row['departure_date'] )
							);
							?>
						</td>
						<td class="eem-dm-cell-shavings"><?php echo esc_html( $row['shavings'] > 0 ? (string) $row['shavings'] : '—' ); ?></td>
						<td class="eem-dm-cell-status">
							<?php echo wp_kses_post( self::status_badge( $row['check_in_status'] ) ); ?>
						</td>
						<td class="eem-dm-cell-notes"><?php echo esc_html( $row['special_instructions'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Status key.
	 * @return string HTML for the badge.
	 */
	private static function status_badge( string $status ): string {
		$labels = array(
			'not_checked_in' => __( 'Not Checked In', 'equine-event-manager' ),
			'occupied'       => __( 'Not Checked In', 'equine-event-manager' ),
			'checked_in'     => __( 'Checked In', 'equine-event-manager' ),
			'checked_out'    => __( 'Checked Out', 'equine-event-manager' ),
			'needs_cleaning' => __( 'Needs Cleaning', 'equine-event-manager' ),
			'clean'          => __( 'Clean', 'equine-event-manager' ),
			'available'      => __( 'Available', 'equine-event-manager' ),
		);

		$label = $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
		$class = 'eem-dm-status-' . sanitize_html_class( $status );

		return '<span class="eem-dm-status ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}
}
