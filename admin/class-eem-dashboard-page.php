<?php
/**
 * Equine Event Manager — Admin Dashboard page (DS-1.B).
 *
 * Renders the canonical `.mockups/dashboard_page.html` layout against live
 * data from `EEM_Dashboard_Repo`. Sections whose source data isn't yet
 * queryable (Unassigned Stalls KPI, Upcoming Reservations stall-progress
 * bars, three of six Needs Attention rows, This Week stalls-assigned row)
 * render em-dash placeholders per the locked DS-1.B graceful-degrade
 * strategy — see CLEANUP #37-#40.
 *
 * Architecture per VIS-3: single `.eem-page-wrap` bordered card with the
 * page header INSIDE, body grid (1fr + 340px sidebar) underneath. No
 * separate welcome bar (per DEC-1 from mockup).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 2.2.0
 */
class EEM_Dashboard_Page {

	const MENU_SLUG = 'equine-event-manager-dashboard';

	/**
	 * Render the Dashboard page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$range = EEM_Dashboard_Repo::sanitize_range( isset( $_GET['range'] ) ? wp_unslash( $_GET['range'] ) : '' );
		$repo  = new EEM_Dashboard_Repo();

		$kpi_cards      = $repo->kpi_cards( $range );
		$upcoming       = $repo->upcoming_reservations();
		$attention      = $repo->attention_items();
		$recent_orders  = $repo->recent_orders();
		$revenue_chart  = $repo->revenue_chart();
		$this_week      = $repo->this_week();
		$upcoming_count = $repo->upcoming_reservations_count( 30 );

		$user           = wp_get_current_user();
		// DS-1.B.1: ucwords() applied so a lowercase WP display_name
		// (e.g. "whitney") renders capitalised ("Whitney") to match the
		// mockup. Multi-word names retain per-word capitalisation.
		$display_name   = $user ? ucwords( (string) $user->display_name ) : '';
		$greeting       = EEM_Dashboard_Repo::format_greeting( $display_name );
		$today          = date_i18n( 'l, F j, Y', current_time( 'timestamp' ) );

		$header_actions = self::header_actions_html();
		$subtitle       = $greeting . ' · ' . $today . ' · ' . sprintf(
			/* translators: %d: count of upcoming reservations in the next 30 days */
			_n( '%d reservation coming up in the next 30 days', '%d reservations coming up in the next 30 days', $upcoming_count, 'equine-event-manager' ),
			$upcoming_count
		);

		eem_render_page_open( array(
			'title'      => __( 'Dashboard', 'equine-event-manager' ),
			'subtitle'   => $subtitle,
			'breadcrumb' => array(
				array( 'label' => __( 'Dashboard', 'equine-event-manager' ) ),
			),
			'actions'    => $header_actions,
		) );

		?>
		<div class="eem-dashboard-body">
			<?php self::render_range_filter( $range ); ?>
			<?php self::render_kpi_grid( $kpi_cards ); ?>

			<div class="eem-dashboard-grid">
				<div class="eem-dashboard-main">
					<?php self::render_upcoming_card( $upcoming ); ?>
					<?php self::render_attention_card( $attention ); ?>
					<?php self::render_recent_orders_card( $recent_orders ); ?>
				</div>
				<div class="eem-dashboard-side">
					<?php self::render_quick_actions_card(); ?>
					<?php self::render_revenue_chart_card( $revenue_chart ); ?>
					<?php self::render_this_week_card( $this_week ); ?>
				</div>
			</div>
		</div>
		<?php

		eem_render_page_close();
	}

	/**
	 * Header action bar — Create Order + View Reservations.
	 *
	 * @return string  Pre-escaped HTML safe for shell's wp_kses_post() pass.
	 */
	private static function header_actions_html() {
		$create  = esc_url( EEM_Orders_List_Page::create_order_url() );
		$res_url = esc_url( admin_url( 'edit.php?post_type=en_reservation' ) );
		$plus    = EEM_Dashboard_Icons::svg( 'plus' );
		$cal     = EEM_Dashboard_Icons::svg( 'calendar' );
		ob_start();
		?>
		<a class="eem-btn eem-btn-electric" href="<?php echo $create; ?>"><?php echo $plus; ?> <?php esc_html_e( 'Create Order', 'equine-event-manager' ); ?></a>
		<a class="eem-btn eem-btn-ghost" href="<?php echo $res_url; ?>"><?php echo $cal; ?> <?php esc_html_e( 'View Reservations', 'equine-event-manager' ); ?></a>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Range filter `<select>` — change-handler in admin.js reloads with
	 * `?range=<key>` (full-page reload, no AJAX) per kickoff decision E.
	 *
	 * @param string $current
	 * @return void
	 */
	private static function render_range_filter( $current ) {
		$options = EEM_Dashboard_Repo::range_options();
		?>
		<div class="eem-dashboard-range-bar">
			<span class="eem-dashboard-range-label"><?php esc_html_e( 'Showing metrics for', 'equine-event-manager' ); ?></span>
			<select class="eem-dashboard-range-select" data-eem-action="dashboard-range-change">
				<?php foreach ( $options as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $key, $current ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * 4-card KPI grid with colored top borders.
	 *
	 * @param array<int, array<string, mixed>> $cards
	 * @return void
	 */
	private static function render_kpi_grid( array $cards ) {
		?>
		<div class="eem-dashboard-kpi-grid">
			<?php foreach ( $cards as $card ) :
				$icon_key = isset( $card['icon'] ) ? (string) $card['icon'] : '';
			?>
				<div class="eem-dashboard-kpi-card eem-dashboard-kpi-card--<?php echo esc_attr( $card['border'] ); ?>">
					<div class="eem-dashboard-kpi-label">
						<span><?php echo esc_html( $card['label'] ); ?></span>
						<?php if ( '' !== $icon_key ) : echo EEM_Dashboard_Icons::svg( $icon_key ); endif; ?>
					</div>
					<div class="eem-dashboard-kpi-value"><?php echo esc_html( $card['value'] ); ?></div>
					<div class="eem-dashboard-kpi-sub">
						<?php if ( ! empty( $card['sub_pre'] ) ) : ?>
							<span class="<?php echo esc_attr( 'eem-dashboard-kpi-tone--' . ( $card['sub_tone'] ?? '' ) ); ?>"><?php echo esc_html( $card['sub_pre'] ); ?></span><?php echo esc_html( $card['sub_post'] ?? '' ); ?>
						<?php elseif ( ! empty( $card['sub_tone'] ) ) : ?>
							<span class="<?php echo esc_attr( 'eem-dashboard-kpi-tone--' . $card['sub_tone'] ); ?>"><?php echo esc_html( $card['sub'] ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $card['sub'] ); ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Upcoming Reservations card with up to 5 rows.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return void
	 */
	private static function render_upcoming_card( array $rows ) {
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'calendar' ); ?> <?php esc_html_e( 'Upcoming Reservations', 'equine-event-manager' ); ?></div>
				<a class="eem-card-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=en_reservation' ) ); ?>"><?php esc_html_e( 'View all →', 'equine-event-manager' ); ?></a>
			</div>
			<?php if ( empty( $rows ) ) : ?>
				<div class="eem-dashboard-empty"><?php esc_html_e( 'No upcoming reservations.', 'equine-event-manager' ); ?></div>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$edit_url = (string) get_edit_post_link( (int) $row['id'] );
				?>
					<a class="eem-dashboard-res-row" href="<?php echo esc_url( $edit_url ); ?>">
						<div class="eem-dashboard-res-main">
							<div class="eem-dashboard-res-name"><?php echo esc_html( $row['name'] ); ?></div>
							<div class="eem-dashboard-res-dates">
								<?php echo esc_html( $row['date_range'] ); ?>
								<?php if ( ! empty( $row['opens_in']['label'] ) ) : ?>
									· <span class="eem-dashboard-res-<?php echo esc_attr( $row['opens_in']['tone'] ); ?>"><?php echo esc_html( $row['opens_in']['label'] ); ?></span>
								<?php endif; ?>
							</div>
							<div class="eem-dashboard-res-tags">
								<?php foreach ( $row['tags'] as $tag ) : ?>
									<span class="eem-dashboard-res-tag eem-dashboard-tag-<?php echo esc_attr( $tag ); ?>"><?php echo esc_html( ucfirst( $tag ) ); ?></span>
								<?php endforeach; ?>
							</div>
							<div class="eem-dashboard-stall-progress">
								<div class="eem-dashboard-stall-progress-label">
									<span><?php esc_html_e( 'Stall assignments', 'equine-event-manager' ); ?></span>
									<span class="eem-dashboard-stall-progress-count">
										<?php // CLEANUP #38: wire to real query at C8 close. ?>
										<?php echo esc_html( $row['stall_progress']['assigned'] ); ?> / <?php echo esc_html( $row['stall_progress']['total'] ); ?>
									</span>
								</div>
								<div class="eem-dashboard-stall-progress-bar">
									<div class="eem-dashboard-stall-progress-fill eem-dashboard-fill-<?php echo esc_attr( $row['stall_progress']['tone'] ); ?>" style="width:<?php echo (int) $row['stall_progress']['pct']; ?>%"></div>
								</div>
							</div>
						</div>
						<div class="eem-dashboard-res-right">
							<div class="eem-dashboard-res-orders"><?php echo esc_html( (string) $row['orders'] ); ?></div>
							<div class="eem-dashboard-res-orders-label"><?php esc_html_e( 'orders', 'equine-event-manager' ); ?></div>
							<div class="eem-dashboard-res-revenue"><?php echo esc_html( $row['revenue'] ); ?></div>
						</div>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Needs Attention card with 6 rows.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return void
	 */
	private static function render_attention_card( array $items ) {
		$count = count( $items );
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'alert-circle' ); ?> <?php esc_html_e( 'Needs Attention', 'equine-event-manager' ); ?></div>
				<span class="eem-dashboard-attention-count"><?php
					echo esc_html( sprintf(
						/* translators: %d: count of attention items */
						_n( '%d item', '%d items', $count, 'equine-event-manager' ),
						$count
					) );
				?></span>
			</div>
			<?php foreach ( $items as $item ) :
				$icon_key = isset( $item['icon_key'] ) ? (string) $item['icon_key'] : '';
				$svg      = '' !== $icon_key ? EEM_Dashboard_Icons::svg( $icon_key ) : '';
			?>
				<a class="eem-dashboard-attention-row" href="<?php echo esc_url( $item['href'] ); ?>">
					<span class="eem-dashboard-attention-icon eem-dashboard-icon-<?php echo esc_attr( $item['icon'] ); ?>"><?php echo $svg; ?></span>
					<div class="eem-dashboard-attention-text">
						<div class="eem-dashboard-attention-title"><?php echo esc_html( $item['title'] ); ?></div>
						<div class="eem-dashboard-attention-desc"><?php echo esc_html( $item['desc'] ); ?></div>
					</div>
					<span class="eem-dashboard-attention-action"><?php echo esc_html( $item['action'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Recent Orders card — 5 rows with 5-digit zero-padded order numbers
	 * via the canonical `EEM_Orders_List_Page::format_order_number_display()`
	 * helper (do NOT reinvent the sprintf format).
	 *
	 * @param array<int, array<string, mixed>> $orders
	 * @return void
	 */
	private static function render_recent_orders_card( array $orders ) {
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'package' ); ?> <?php esc_html_e( 'Recent Orders', 'equine-event-manager' ); ?></div>
				<a class="eem-card-link" href="<?php echo esc_url( EEM_Orders_List_Page::url() ); ?>"><?php esc_html_e( 'View all →', 'equine-event-manager' ); ?></a>
			</div>
			<?php if ( empty( $orders ) ) : ?>
				<div class="eem-dashboard-empty"><?php esc_html_e( 'No recent orders.', 'equine-event-manager' ); ?></div>
			<?php else : ?>
				<?php foreach ( $orders as $o ) :
					$href   = EEM_Orders_List_Page::order_detail_url( $o['order_key'] );
					$number = EEM_Orders_List_Page::format_order_number_display( $o['number'] );
					$css    = EEM_Orders_List_Page::status_slug_to_css_class( $o['status_slug'] );
				?>
					<a class="eem-dashboard-order-row" href="<?php echo esc_url( $href ); ?>">
						<span class="eem-dashboard-order-num"><?php echo esc_html( $number ); ?></span>
						<div class="eem-dashboard-order-customer">
							<div class="eem-dashboard-order-name"><?php echo esc_html( $o['customer'] ); ?></div>
							<div class="eem-dashboard-order-event"><?php echo esc_html( $o['event'] ); ?></div>
						</div>
						<span class="eem-dashboard-order-amount"><?php echo esc_html( $o['total'] ); ?></span>
						<span class="eem-status-badge eem-status-<?php echo esc_attr( $css ); ?>"><?php echo esc_html( $o['status_label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Quick Actions tile grid — 4 tiles. Hrefs per kickoff route
	 * resolutions: Collect Payment → orders&billing=unpaid (workflow
	 * launcher, not the Collect Payment page directly); Export Report →
	 * reports page (C15 deliverable).
	 *
	 * @return void
	 */
	private static function render_quick_actions_card() {
		$tiles = array(
			array(
				'icon'  => 'blue',
				'icon_key' => 'plus',
				'label' => __( 'Create Order', 'equine-event-manager' ),
				'sub'   => __( 'Manual entry', 'equine-event-manager' ),
				'href'  => EEM_Orders_List_Page::create_order_url(),
			),
			array(
				'icon'  => 'green',
				'icon_key' => 'grid',
				'label' => __( 'Stall Charts', 'equine-event-manager' ),
				'sub'   => __( 'Assign stalls', 'equine-event-manager' ),
				'href'  => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
			),
			array(
				'icon'  => 'purple',
				'icon_key' => 'card',
				'label' => __( 'Collect Payment', 'equine-event-manager' ),
				'sub'   => __( 'Unpaid orders', 'equine-event-manager' ),
				// DS-1.B.5: Orders list reads ?billing= (not ?status=) — see
				// EEM_Orders_List_Page::render line 92. Valid billing-tab
				// keys: all / paid / unpaid / refunded / cancelled (from
				// EEM_Orders_List_Repo::billing_tabs).
				'href'  => EEM_Orders_List_Page::url( array( 'billing' => 'unpaid' ) ),
			),
			array(
				'icon'  => 'orange',
				'icon_key' => 'download',
				'label' => __( 'Export Report', 'equine-event-manager' ),
				'sub'   => __( 'Download CSV', 'equine-event-manager' ),
				'href'  => admin_url( 'admin.php?page=equine-event-manager-reports' ),
			),
		);
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'lightning' ); ?> <?php esc_html_e( 'Quick Actions', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-dashboard-quick-actions">
				<?php foreach ( $tiles as $tile ) : ?>
					<a class="eem-dashboard-qa-btn" href="<?php echo esc_url( $tile['href'] ); ?>">
						<span class="eem-dashboard-qa-icon eem-dashboard-qi-<?php echo esc_attr( $tile['icon'] ); ?>"><?php echo EEM_Dashboard_Icons::svg( $tile['icon_key'] ); ?></span>
						<div>
							<div class="eem-dashboard-qa-label"><?php echo esc_html( $tile['label'] ); ?></div>
							<div class="eem-dashboard-qa-sub"><?php echo esc_html( $tile['sub'] ); ?></div>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Revenue by Reservation chart — 5 bars (or N<5), all Electric Blue
	 * per DEC-6.
	 *
	 * @param array{bars: array<int, array<string, mixed>>, total_label: string, total_value: string} $chart
	 * @return void
	 */
	private static function render_revenue_chart_card( array $chart ) {
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'bar-chart' ); ?> <?php esc_html_e( 'Revenue by Reservation', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-dashboard-rev-chart">
				<?php if ( empty( $chart['bars'] ) ) : ?>
					<div class="eem-dashboard-empty"><?php esc_html_e( 'No revenue recorded yet — paid and partially-paid orders will appear here.', 'equine-event-manager' ); ?></div>
				<?php else : ?>
					<div class="eem-dashboard-rev-bars">
						<?php foreach ( $chart['bars'] as $bar ) : ?>
							<div class="eem-dashboard-rev-bar-wrap">
								<div class="eem-dashboard-rev-bar-val"><?php echo esc_html( $bar['value'] ); ?></div>
								<div class="eem-dashboard-rev-bar" style="height:<?php echo (int) $bar['pct']; ?>%" title="<?php echo esc_attr( $bar['label'] ); ?>"></div>
								<div class="eem-dashboard-rev-bar-label"><?php echo esc_html( $bar['label'] ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="eem-dashboard-rev-total">
					<span class="eem-dashboard-rev-total-label"><?php echo esc_html( $chart['total_label'] ); ?></span>
					<span class="eem-dashboard-rev-total-val"><?php echo esc_html( $chart['total_value'] ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * This Week summary — 5 rows.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return void
	 */
	private static function render_this_week_card( array $rows ) {
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'clock' ); ?> <?php esc_html_e( 'This Week', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-dashboard-tw-body">
				<?php foreach ( $rows as $row ) : ?>
					<div class="eem-dashboard-tw-row">
						<span class="eem-dashboard-tw-label"><?php echo esc_html( $row['label'] ); ?></span>
						<span class="eem-dashboard-tw-value<?php echo $row['tone'] ? ' eem-dashboard-tw-value--' . esc_attr( $row['tone'] ) : ''; ?>"><?php echo esc_html( $row['value'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
