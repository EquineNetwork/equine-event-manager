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

		$kpi_cards       = $repo->kpi_cards( $range );
		$upcoming        = $repo->upcoming_reservations();
		$upcoming_events = $repo->upcoming_events();
		$attention      = $repo->attention_items();
		$recent_orders  = $repo->recent_orders();
		$revenue_chart  = $repo->revenue_chart();
		$this_week      = $repo->this_week();
		$addons         = EEM_Dashboard_Repo::addons_summary();
		$upcoming_count = $repo->upcoming_reservations_count( 30 );

		$today          = date_i18n( 'l, F j, Y', current_time( 'timestamp' ) );

		$header_actions = self::header_actions_html();
		// 2.7.23 — greeting ("Good afternoon, {name} · ") removed per product
		// request; subtitle now leads with the date.
		$subtitle       = $today . ' · ' . sprintf(
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
			<?php self::render_setup_checklist(); ?>
			<?php self::render_range_filter( $range ); ?>
			<?php self::render_kpi_grid( $kpi_cards ); ?>

			<div class="eem-dashboard-grid">
				<div class="eem-dashboard-main">
					<?php self::render_upcoming_card( $upcoming ); ?>
					<?php if ( class_exists( 'EEM_Events' ) && EEM_Events::is_native_events_enabled() ) : ?>
						<?php self::render_upcoming_events_card( $upcoming_events ); ?>
					<?php endif; ?>
					<?php self::render_attention_card( $attention ); ?>
					<?php self::render_recent_orders_card( $recent_orders ); ?>
				</div>
				<div class="eem-dashboard-side">
					<?php self::render_quick_actions_card(); ?>
					<?php self::render_addons_card( $addons ); ?>
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
	 * First-run setup checklist card. Renders only when there's at least one
	 * unfinished required area and the admin hasn't dismissed it. Each row
	 * links to its Settings panel; the card auto-hides once setup is complete.
	 *
	 * @return void
	 */
	private static function render_setup_checklist() {
		$pending = EEM_Setup_Checklist::should_show() ? EEM_Setup_Checklist::pending_actions() : array();
		if ( empty( $pending ) ) {
			return;
		}

		$nonce         = wp_create_nonce( EEM_Setup_Checklist::DISMISS_NONCE );
		$setup_left    = count( array_filter( $pending, static function ( $r ) {
			return 'setup' === $r['type'];
		} ) );
		// Sub-line adapts: still configuring vs. set-up-complete-now-add-a-reservation.
		$sub = $setup_left > 0
			? __( 'A few quick steps before you go live. Finished items disappear from this list.', 'equine-event-manager' )
			: __( "You're configured — here's your next step to start taking orders.", 'equine-event-manager' );
		?>
		<section class="eem-setup-checklist" data-eem-setup-checklist data-eem-dismiss-nonce="<?php echo esc_attr( $nonce ); ?>">
			<header class="eem-setup-checklist__head">
				<div>
					<h2 class="eem-setup-checklist__title"><?php esc_html_e( 'Finish setting up your plugin', 'equine-event-manager' ); ?></h2>
					<p class="eem-setup-checklist__sub"><?php echo esc_html( $sub ); ?></p>
				</div>
			</header>
			<ul class="eem-setup-checklist__list">
				<?php foreach ( $pending as $row ) : ?>
					<li class="eem-setup-checklist__item is-todo<?php echo 'amber' === $row['tone'] ? ' is-next' : ''; ?>">
						<span class="eem-setup-checklist__icon" aria-hidden="true">
							<?php if ( 'reservation' === $row['type'] ) : ?>
								<svg viewBox="0 0 20 20" width="18" height="18"><path fill="currentColor" d="M10 2a1 1 0 0 1 1 1v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H3a1 1 0 1 1 0-2h6V3a1 1 0 0 1 1-1z"/></svg>
							<?php else : ?>
								<svg viewBox="0 0 20 20" width="18" height="18"><circle cx="10" cy="10" r="7.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
							<?php endif; ?>
						</span>
						<span class="eem-setup-checklist__text">
							<span class="eem-setup-checklist__label"><?php echo esc_html( $row['label'] ); ?></span>
							<span class="eem-setup-checklist__hint"><?php echo esc_html( $row['hint'] ); ?></span>
						</span>
						<span class="eem-setup-checklist__actions">
							<a class="eem-btn eem-btn-<?php echo esc_attr( $row['tone'] ); ?> eem-setup-checklist__cta" href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['cta'] ); ?></a>
							<?php if ( ! empty( $row['dismissable'] ) ) : ?>
								<button type="button" class="eem-setup-checklist__skip" data-eem-action="setup-checklist-dismiss"><?php esc_html_e( 'Skip', 'equine-event-manager' ); ?></button>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
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
				<a class="eem-card-link" href="<?php echo esc_url( EEM_Reservations_List_Page::url() ); ?>"><?php esc_html_e( 'View all →', 'equine-event-manager' ); ?></a>
			</div>
			<?php if ( empty( $rows ) ) : ?>
				<div class="eem-dashboard-empty"><?php esc_html_e( 'No upcoming reservations.', 'equine-event-manager' ); ?></div>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$edit_url = EEM_Reservation_Editor_Page::url( (int) $row['id'] );
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
										<?php echo esc_html( $row['stall_progress']['assigned'] ?? '0' ); ?> / <?php echo esc_html( $row['stall_progress']['total'] ?? '0' ); ?>
									</span>
								</div>
								<div class="eem-dashboard-stall-progress-bar">
									<div class="eem-dashboard-stall-progress-fill eem-dashboard-fill-<?php echo esc_attr( $row['stall_progress']['tone'] ?? 'green' ); ?>" style="width:<?php echo (int) ( $row['stall_progress']['pct'] ?? 0 ); ?>%"></div>
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
	 * Upcoming Events card (Native Events) — listed when the Native Events
	 * feature is on, parallel to Upcoming Reservations. Each row: event title +
	 * date range · venue, with a relative "when" chip. Whitney 2026-06-14.
	 *
	 * @param array<int, array<string, mixed>> $events
	 * @return void
	 */
	private static function render_upcoming_events_card( array $events ) {
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'calendar' ); ?> <?php esc_html_e( 'Upcoming Events', 'equine-event-manager' ); ?></div>
				<a class="eem-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=equine-event-manager-events' ) ); ?>"><?php esc_html_e( 'View all →', 'equine-event-manager' ); ?></a>
			</div>
			<?php if ( empty( $events ) ) : ?>
				<div class="eem-dashboard-empty"><?php esc_html_e( 'No upcoming events.', 'equine-event-manager' ); ?></div>
			<?php else : ?>
				<?php foreach ( $events as $ev ) :
					$edit_url = class_exists( 'EEM_Event_Editor_Page' ) ? EEM_Event_Editor_Page::url( (int) $ev['id'] ) : '';
					?>
					<a class="eem-dashboard-event-row" href="<?php echo esc_url( $edit_url ); ?>">
						<div class="eem-dashboard-event-main">
							<div class="eem-dashboard-event-name"><?php echo esc_html( $ev['title'] ); ?></div>
							<div class="eem-dashboard-event-meta">
								<?php echo esc_html( $ev['date_range'] ); ?><?php if ( '' !== (string) $ev['venue'] ) : ?> · <?php echo esc_html( $ev['venue'] ); ?><?php endif; ?>
							</div>
						</div>
						<?php if ( ! empty( $ev['when']['label'] ) ) : ?>
							<span class="eem-dashboard-event-when eem-dashboard-res-<?php echo esc_attr( $ev['when']['tone'] ); ?>"><?php echo esc_html( $ev['when']['label'] ); ?></span>
						<?php endif; ?>
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
	/**
	 * "Add-Ons" side card — surfaces Entries + Sheets & Results activity, each
	 * row gated on its per-site feature flag. Renders nothing when both add-ons
	 * are disabled.
	 *
	 * @param array $addons Summary from EEM_Dashboard_Repo::addons_summary().
	 * @return void
	 */
	private static function render_addons_card( array $addons ) {
		$entries = isset( $addons['entries'] ) ? $addons['entries'] : array( 'enabled' => false );
		$sheets  = isset( $addons['sheets'] ) ? $addons['sheets'] : array( 'enabled' => false );
		if ( empty( $entries['enabled'] ) && empty( $sheets['enabled'] ) ) {
			return; // Both add-ons off — render nothing.
		}
		?>
		<div class="eem-card eem-dashboard-card">
			<div class="eem-card-header">
				<div class="eem-card-title"><?php echo EEM_Dashboard_Icons::svg( 'package' ); ?> <?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-dashboard-tw-body">
				<?php if ( ! empty( $entries['enabled'] ) ) : ?>
					<a class="eem-dashboard-addon-row" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . EEM_Entries::POST_TYPE ) ); ?>">
						<span class="eem-dashboard-addon-row__head">
							<span class="eem-dashboard-addon-row__title"><?php esc_html_e( 'Entries', 'equine-event-manager' ); ?></span>
							<span class="eem-dashboard-addon-row__cta"><?php esc_html_e( 'Manage →', 'equine-event-manager' ); ?></span>
						</span>
						<span class="eem-dashboard-addon-row__stats">
							<?php
							/* translators: 1: division count, 2: entrant count. */
							echo esc_html(
								sprintf(
									/* translators: 1: number of divisions, 2: number of entrants. */
									_n( '%1$s division · %2$s entered', '%1$s divisions · %2$s entered', (int) $entries['divisions'], 'equine-event-manager' ),
									number_format_i18n( (int) $entries['divisions'] ),
									number_format_i18n( (int) $entries['entrants'] )
								)
							);
							?>
						</span>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $sheets['enabled'] ) ) : ?>
					<a class="eem-dashboard-addon-row" href="<?php echo esc_url( admin_url( 'admin.php?page=' . EEM_Sheets_Results_Page::MENU_SLUG ) ); ?>">
						<span class="eem-dashboard-addon-row__head">
							<span class="eem-dashboard-addon-row__title"><?php esc_html_e( 'Sheets & Results', 'equine-event-manager' ); ?></span>
							<span class="eem-dashboard-addon-row__cta"><?php esc_html_e( 'Open →', 'equine-event-manager' ); ?></span>
						</span>
						<span class="eem-dashboard-addon-row__stats">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: draw-sheet count, 2: result count. */
									__( '%1$s draw sheets · %2$s results', 'equine-event-manager' ),
									number_format_i18n( (int) $sheets['drawsheets'] ),
									number_format_i18n( (int) $sheets['results'] )
								)
							);
							?>
						</span>
						<?php if ( (int) $sheets['awaiting'] > 0 ) : ?>
							<span class="eem-dashboard-addon-row__alert">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of draw sheets awaiting a result PDF. */
										_n( '%s awaiting results', '%s awaiting results', (int) $sheets['awaiting'], 'equine-event-manager' ),
										number_format_i18n( (int) $sheets['awaiting'] )
									)
								);
								?>
							</span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

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
