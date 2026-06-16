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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['print'] ) ) {
			self::render_print_view();
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-daily-movement-service.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter state.
		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$view           = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
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
			if ( '' !== $date ) {
				$reports     = array( EEM_Daily_Movement_Service::build_date_report( $reservation_id, $date ) );
				$report_date = $date;
				$view        = 'date';
			} elseif ( 'today' === $view ) {
				$today       = wp_date( 'Y-m-d' );
				$reports     = array( EEM_Daily_Movement_Service::build_date_report( $reservation_id, $today ) );
				$report_date = $today;
			} else {
				$reports = EEM_Daily_Movement_Service::build_all_dates_report( $reservation_id );
				$view    = 'all';
			}
		}

		$available_dates   = $reservation_id > 0 ? EEM_Daily_Movement_Service::get_available_dates( $reservation_id ) : array();
		$reservation_title = $reservation_id > 0 ? get_the_title( $reservation_id ) : '';
		$totals            = self::compute_totals( $reports );

		$order_map = self::build_order_map( $reports );

		$print_url = add_query_arg( array(
			'page'           => self::MENU_SLUG,
			'reservation_id' => $reservation_id,
			'view'           => $view,
			'date'           => $date,
			'print'          => '1',
		), admin_url( 'admin.php' ) );
		$print_btn = '<a href="' . esc_url( $print_url ) . '" target="_blank" class="eem-btn eem-btn-outline">' . esc_html__( 'Print View', 'equine-event-manager' ) . '</a>';

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
		self::render_toolbar( $reservations, $reservation_id, $date, $available_dates );
		self::render_view_tabs( $reservation_id, $view, $date );

		if ( empty( $reservations ) ) {
			echo '<div class="eem-dm-empty"><p>' . esc_html__( 'No published reservations yet. Create and publish a reservation to see movement data.', 'equine-event-manager' ) . '</p></div>';
		} elseif ( empty( $reports ) || ( 1 === count( $reports ) && 0 === $totals['arriving'] && 0 === $totals['departing'] ) ) {
			echo '<div class="eem-dm-empty"><p>' . esc_html__( 'No movement data for the selected date. Try "All Days" to see all activity.', 'equine-event-manager' ) . '</p></div>';
		} else {
			self::render_print_header( $reservation_title, $reports, $view, $report_date );
			foreach ( $reports as $report ) {
				self::render_date_section( $report, 'all' === $view, $order_map );
			}
		}

		eem_render_page_close();
	}

	/**
	 * Render the standalone print-view page (no WP admin chrome).
	 *
	 * Opens in a new tab via the Print View button. Contains its own toolbar
	 * with Print / Save PDF and Close buttons, plus the full report tables
	 * styled for clean printing.
	 *
	 * @return void
	 */
	public static function render_print_view(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-daily-movement-service.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$view           = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'today';
		$date           = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$reports     = array();
		$report_date = '';

		if ( $reservation_id > 0 ) {
			if ( '' !== $date ) {
				$reports     = array( EEM_Daily_Movement_Service::build_date_report( $reservation_id, $date ) );
				$report_date = $date;
				$view        = 'date';
			} elseif ( 'today' === $view ) {
				$today       = wp_date( 'Y-m-d' );
				$reports     = array( EEM_Daily_Movement_Service::build_date_report( $reservation_id, $today ) );
				$report_date = $today;
			} else {
				$reports = EEM_Daily_Movement_Service::build_all_dates_report( $reservation_id );
				$view    = 'all';
			}
		}

		$reservation_title = $reservation_id > 0 ? get_the_title( $reservation_id ) : '';

		$back_url = add_query_arg( array(
			'page'           => self::MENU_SLUG,
			'reservation_id' => $reservation_id,
			'view'           => $view,
			'date'           => $date,
		), admin_url( 'admin.php' ) );

		$date_label = '';
		if ( 'all' === $view ) {
			$date_label = __( 'All Days', 'equine-event-manager' );
		} elseif ( '' !== $report_date ) {
			$date_label = EEM_Daily_Movement_Service::format_display_date( $report_date );
		}

		$status_labels = array(
			'not_checked_in' => __( 'Not Checked In', 'equine-event-manager' ),
			'occupied'       => __( 'Not Checked In', 'equine-event-manager' ),
			'checked_in'     => __( 'Checked In', 'equine-event-manager' ),
			'checked_out'    => __( 'Checked Out', 'equine-event-manager' ),
		);

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $reservation_title . ' — ' . __( 'Daily Movement', 'equine-event-manager' ) ); ?></title>
			<link rel="preconnect" href="https://fonts.googleapis.com">
			<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
			<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
			<style>
				*{box-sizing:border-box;margin:0;padding:0}
				body{font-family:'IBM Plex Sans',-apple-system,BlinkMacSystemFont,sans-serif;font-size:13px;color:#1e293b;background:#fff}
				.dm-pv-toolbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:#f1f5f9;border-bottom:1px solid #e5e7eb}
				.dm-pv-toolbar h1{font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:700;color:#031B4E}
				.dm-pv-toolbar-actions{display:flex;gap:8px}
				.dm-pv-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;font-size:13px;font-weight:600;border-radius:6px;border:none;cursor:pointer;text-decoration:none}
				.dm-pv-btn--primary{background:#3b82f6;color:#fff}
				.dm-pv-btn--primary:hover{background:#2563eb}
				.dm-pv-btn--outline{background:#fff;color:#1e293b;border:1px solid #d1d5db}
				.dm-pv-btn--outline:hover{background:#f9fafb}
				a.dm-pv-btn--outline{color:#1e293b}
				a.dm-pv-btn--outline:hover{color:#1e293b;text-decoration:none}
				.dm-pv-header{text-align:center;padding:24px 24px 16px}
				.dm-pv-header h2{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;color:#031B4E;margin-bottom:4px}
				.dm-pv-header p{font-size:13px;color:#6B7A99}
				.dm-pv-body{padding:0 24px 24px}
				.dm-pv-date-section{margin-bottom:24px}
				.dm-pv-date-heading{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:700;color:#031B4E;padding-bottom:6px;border-bottom:2px solid #3b82f6;margin-bottom:8px}
				.dm-pv-summary{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap}
				.dm-pv-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
				.dm-pv-chip::before{content:'';display:inline-block;width:5px;height:5px;border-radius:50%}
				.dm-pv-chip--arriving{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
				.dm-pv-chip--arriving::before{background:#22c55e}
				.dm-pv-chip--departing{background:#fefce8;color:#a16207;border:1px solid #fde68a}
				.dm-pv-chip--departing::before{background:#eab308}
				.dm-pv-chip--pending{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
				.dm-pv-chip--pending::before{background:#ef4444}
				.dm-pv-chip--shavings{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}
				.dm-pv-chip--shavings::before{background:#8b5cf6}
				.dm-pv-group-heading{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin:14px 0 6px}
				table.dm-pv-table{width:100%;border-collapse:collapse}
				.dm-pv-table th{text-align:left;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;padding:6px 10px;border-bottom:1px solid #e5e7eb}
				.dm-pv-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12.5px;vertical-align:top}
				.dm-pv-table tbody tr:last-child td{border-bottom:none}
				.dm-pv-cell-stall{font-weight:700;white-space:nowrap}
				.dm-pv-cell-dates{white-space:nowrap;color:#475569;font-size:12px}
				.dm-pv-cell-shavings{text-align:center}
				.dm-pv-cell-notes{color:#475569;font-size:12px;max-width:200px}
				.dm-pv-status{display:inline-flex;align-items:center;gap:5px;padding:2px 7px;border-radius:20px;font-size:10.5px;font-weight:600;white-space:nowrap}
				.dm-pv-status::before{content:'';display:inline-block;width:5px;height:5px;border-radius:50%}
				.dm-pv-status--not_checked_in,.dm-pv-status--occupied{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
				.dm-pv-status--not_checked_in::before,.dm-pv-status--occupied::before{background:#ef4444}
				.dm-pv-status--checked_in{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
				.dm-pv-status--checked_in::before{background:#22c55e}
				.dm-pv-status--checked_out{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
				.dm-pv-status--checked_out::before{background:#3b82f6}
				@media print{
					.dm-pv-toolbar{display:none}
					body{font-size:11px}
					.dm-pv-header h2{font-size:18px}
					.dm-pv-table td{padding:5px 8px;font-size:11px}
					.dm-pv-table th{font-size:9.5px;padding:4px 8px}
					.dm-pv-chip{font-size:10px;padding:1px 6px}
					.dm-pv-status{font-size:9.5px;padding:1px 5px}
					@page{margin:10mm 8mm;size:letter portrait}
					*{-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact}
				}
			</style>
		</head>
		<body>
			<div class="dm-pv-toolbar">
				<h1><?php esc_html_e( 'Print View — Daily Movement', 'equine-event-manager' ); ?></h1>
				<div class="dm-pv-toolbar-actions">
					<button type="button" class="dm-pv-btn dm-pv-btn--primary" onclick="window.print()">
						<?php esc_html_e( 'Print / Save PDF', 'equine-event-manager' ); ?>
					</button>
					<a href="<?php echo esc_url( $back_url ); ?>" class="dm-pv-btn dm-pv-btn--outline">
						<?php esc_html_e( 'Close', 'equine-event-manager' ); ?>
					</a>
				</div>
			</div>
			<div class="dm-pv-header">
				<h2><?php echo esc_html( $reservation_title ); ?></h2>
				<?php if ( '' !== $date_label ) : ?>
					<p><?php echo esc_html( $date_label ); ?> &middot; <?php printf( esc_html__( 'Printed %s', 'equine-event-manager' ), esc_html( wp_date( 'F j, Y g:i A' ) ) ); ?></p>
				<?php endif; ?>
			</div>
			<div class="dm-pv-body">
				<?php foreach ( $reports as $report ) : ?>
					<div class="dm-pv-date-section">
						<div class="dm-pv-date-heading"><?php echo esc_html( $report['date_display'] ); ?></div>
						<div class="dm-pv-summary">
							<span class="dm-pv-chip dm-pv-chip--arriving"><?php printf( esc_html__( '%d Arriving', 'equine-event-manager' ), $report['summary']['arriving'] ); ?></span>
							<span class="dm-pv-chip dm-pv-chip--departing"><?php printf( esc_html__( '%d Departing', 'equine-event-manager' ), $report['summary']['departing'] ); ?></span>
							<span class="dm-pv-chip dm-pv-chip--pending"><?php printf( esc_html__( '%d Not Yet Checked In', 'equine-event-manager' ), $report['summary']['not_yet_checked_in'] ); ?></span>
							<?php if ( ( $report['summary']['shavings_total'] ?? 0 ) > 0 ) : ?>
								<span class="dm-pv-chip dm-pv-chip--shavings"><?php printf( esc_html__( '%d Bags Shavings', 'equine-event-manager' ), $report['summary']['shavings_total'] ); ?></span>
							<?php endif; ?>
						</div>
						<?php
						foreach ( array( 'arriving', 'departing' ) as $group ) :
							if ( empty( $report[ $group ] ) ) {
								continue;
							}
							$rows = $report[ $group ];
							usort( $rows, function ( $a, $b ) {
								return strnatcasecmp( implode( ',', $a['stall_numbers'] ), implode( ',', $b['stall_numbers'] ) );
							} );
						?>
							<div class="dm-pv-group-heading"><?php echo esc_html( ucfirst( $group ) ); ?></div>
							<table class="dm-pv-table">
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
											<td class="dm-pv-cell-stall"><?php echo esc_html( implode( ', ', $row['stall_numbers'] ) ?: '—' ); ?></td>
											<td><?php echo esc_html( $row['customer_name'] ); ?></td>
											<td class="dm-pv-cell-dates"><?php echo esc_html( EEM_Daily_Movement_Service::format_display_date( $row['arrival_date'] ) . ' → ' . EEM_Daily_Movement_Service::format_display_date( $row['departure_date'] ) ); ?></td>
											<td class="dm-pv-cell-shavings"><?php echo esc_html( $row['shavings'] > 0 ? (string) $row['shavings'] : '—' ); ?></td>
											<td>
												<?php
												$s  = $row['check_in_status'];
												$sl = $status_labels[ $s ] ?? ucwords( str_replace( '_', ' ', $s ) );
												?>
												<span class="dm-pv-status dm-pv-status--<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $sl ); ?></span>
											</td>
											<td class="dm-pv-cell-notes"><?php echo esc_html( $row['special_instructions'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</body>
		</html>
		<?php
		exit;
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
			'shavings'           => 0,
		);
		foreach ( $reports as $report ) {
			$totals['arriving']           += $report['summary']['arriving'];
			$totals['departing']          += $report['summary']['departing'];
			$totals['not_yet_checked_in'] += $report['summary']['not_yet_checked_in'];
			$totals['shavings']           += $report['summary']['shavings_total'] ?? 0;

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
			<div class="eem-stat-card">
				<div class="eem-stat-card-label"><?php esc_html_e( 'Shavings Required', 'equine-event-manager' ); ?></div>
				<div class="eem-stat-card-num" style="color: #7c3aed;"><?php echo (int) $totals['shavings']; ?> <span style="font-size: 14px; font-weight: 500; color: #64748b;"><?php esc_html_e( 'bags', 'equine-event-manager' ); ?></span></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the toolbar: reservation selector + date dropdown.
	 *
	 * @param WP_Post[] $reservations   Available reservations.
	 * @param int       $reservation_id Selected reservation ID.
	 * @param string    $date           Selected date (Y-m-d).
	 * @param string[]  $available_dates All dates with movement for the reservation.
	 * @return void
	 */
	private static function render_toolbar( array $reservations, int $reservation_id, string $date, array $available_dates = array() ): void {
		$base_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&reservation_id=' . $reservation_id );
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
					<select name="date" id="eem-dm-date" class="eem-field-select" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Days', 'equine-event-manager' ); ?></option>
						<?php foreach ( $available_dates as $d ) : ?>
							<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $date, $d ); ?>>
								<?php echo esc_html( date_i18n( 'l, F j', strtotime( $d ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
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
		$is_today = ( 'today' === $view );
		$is_all   = ( 'all' === $view && '' === $date );
		$base     = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&reservation_id=' . $reservation_id );
		?>
		<div class="eem-dm-view-tabs">
			<a class="eem-dm-view-tab<?php echo $is_all ? ' active' : ''; ?>" href="<?php echo esc_url( $base . '&view=all' ); ?>">
				<?php esc_html_e( 'All Days', 'equine-event-manager' ); ?>
			</a>
			<a class="eem-dm-view-tab<?php echo $is_today ? ' active' : ''; ?>" href="<?php echo esc_url( $base . '&view=today' ); ?>">
				<?php esc_html_e( 'Today', 'equine-event-manager' ); ?>
			</a>
			<?php if ( '' !== $date && ! $is_all ) : ?>
				<a class="eem-dm-view-tab active" href="<?php echo esc_url( $base . '&date=' . urlencode( $date ) ); ?>">
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
	 * @param array                        $report    Report data from the service.
	 * @param bool                         $show_date Whether to show the date heading.
	 * @param array<string, array|null>    $order_map Order key → order data map.
	 * @return void
	 */
	private static function render_date_section( array $report, bool $show_date, array $order_map = array() ): void {
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
				<?php if ( ( $report['summary']['shavings_total'] ?? 0 ) > 0 ) : ?>
					<span class="eem-dm-summary-item eem-dm-summary-shavings">
						<?php printf( esc_html__( '%d Bags Shavings', 'equine-event-manager' ), $report['summary']['shavings_total'] ); ?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $report['arriving'] ) ) : ?>
				<h4 class="eem-dm-group-heading"><?php esc_html_e( 'Arriving', 'equine-event-manager' ); ?></h4>
				<?php self::render_table( $report['arriving'], $order_map ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $report['departing'] ) ) : ?>
				<h4 class="eem-dm-group-heading"><?php esc_html_e( 'Departing', 'equine-event-manager' ); ?></h4>
				<?php self::render_table( $report['departing'], $order_map ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the movement data table.
	 *
	 * @param array                     $rows      Shaped movement rows.
	 * @param array<string, array|null> $order_map Order key → order data map.
	 * @return void
	 */
	private static function render_table( array $rows, array $order_map = array() ): void {
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
					<th><?php esc_html_e( 'Order #', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Dates', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Shavings', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'equine-event-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) :
					$order_key = isset( $row['order_key'] ) ? (string) $row['order_key'] : '';
					$order     = '' !== $order_key && isset( $order_map[ $order_key ] ) ? $order_map[ $order_key ] : null;
				?>
					<tr>
						<td class="eem-dm-cell-stall"><?php echo esc_html( implode( ', ', $row['stall_numbers'] ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $row['customer_name'] ); ?></td>
						<td class="eem-dm-cell-order">
							<?php if ( $order ) :
								$order_number = isset( $order['order_number'] ) ? (string) $order['order_number'] : '';
								$display      = EEM_Orders_List_Page::format_order_number_display( $order_number );
								$url          = EEM_Orders_List_Page::order_detail_url( $order_key );
							?>
								<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $display ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
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

	/**
	 * Collect unique order keys from reports and batch-resolve to grouped orders.
	 *
	 * @param array[] $reports Report arrays from the service.
	 * @return array<string, array|null> Order key → grouped order (or null).
	 */
	private static function build_order_map( array $reports ): array {
		$keys = array();
		foreach ( $reports as $report ) {
			foreach ( array_merge( $report['arriving'] ?? array(), $report['departing'] ?? array() ) as $row ) {
				$k = isset( $row['order_key'] ) ? (string) $row['order_key'] : '';
				if ( '' !== $k ) {
					$keys[ $k ] = true;
				}
			}
		}

		if ( empty( $keys ) ) {
			return array();
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-orders-repository.php';
		$repo   = new EEM_Orders_Repository();
		$orders = $repo->get_orders_by_keys( array_keys( $keys ) );
		$map    = array();
		foreach ( $orders as $order ) {
			$k = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
			if ( '' !== $k ) {
				$map[ $k ] = $order;
			}
		}
		return $map;
	}
}
