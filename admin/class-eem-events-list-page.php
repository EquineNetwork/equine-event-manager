<?php
/**
 * Branded Events list page (Native Events Admin C).
 *
 * Replaces the raw WP `edit.php?post_type=en_event` list with a branded list
 * (stats strip incl. an upcoming-events card, status tabs, producer filter +
 * search, sortable table by title/date, per-row action dropdown, mobile cards)
 * per .mockups/events_admin_page.html.
 *
 * List-only: "Edit Event" links to the native WP event editor (which owns the
 * date / venue / producer / social meta boxes). The raw CPT list is redirected
 * here from EEM_Admin.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Events admin list controller.
 */
class EEM_Events_List_Page {

	/**
	 * Admin menu slug for the branded Events list.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-events';

	/**
	 * Post type backing this list.
	 *
	 * @var string
	 */
	const POST_TYPE = 'en_event';

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Build an admin.php URL for this page with optional extra query args.
	 *
	 * @param array<string,mixed> $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page (capability-gated). List-only — no detail view.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
		self::render_list();
	}

	/**
	 * Branded Events list — en_event posts with date / venue / producer columns.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list nav.
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$producer = isset( $_GET['producer'] ) ? absint( wp_unslash( $_GET['producer'] ) ) : 0;
		$orderby  = isset( $_GET['orderby'] ) && 'date' === $_GET['orderby'] ? 'date' : 'title';
		$order    = isset( $_GET['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'desc' : 'asc';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, array( 'all', 'publish', 'draft', 'trash' ), true ) ) {
			$status = 'all';
		}

		$reservation_counts = self::reservation_counts_by_event();

		$posts  = get_posts( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => array( 'publish', 'draft', 'trash' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );
		$counts = array( 'all' => 0, 'publish' => 0, 'draft' => 0, 'trash' => 0 );
		$rows   = array();
		foreach ( $posts as $p ) {
			if ( isset( $counts[ $p->post_status ] ) ) {
				$counts[ $p->post_status ]++;
			}
			$venue_id    = absint( get_post_meta( $p->ID, '_equine_event_manager_event_venue_id', true ) );
			$producer_id = absint( get_post_meta( $p->ID, '_equine_event_manager_event_producer_id', true ) );
			$start       = (string) get_post_meta( $p->ID, '_equine_event_manager_event_start_date', true );
			$end         = (string) get_post_meta( $p->ID, '_equine_event_manager_event_end_date', true );
			$rows[]      = array(
				'id'            => $p->ID,
				'title'         => get_the_title( $p->ID ),
				'status'        => $p->post_status,
				'start'         => $start,
				'end'           => $end,
				'date_label'    => self::format_date_range( $start, $end ),
				'venue'         => $venue_id ? get_the_title( $venue_id ) : '',
				'producer_id'   => $producer_id,
				'producer'      => $producer_id ? get_the_title( $producer_id ) : '',
				'reservations'  => isset( $reservation_counts[ $p->ID ] ) ? (int) $reservation_counts[ $p->ID ] : 0,
			);
		}
		$counts['all'] = $counts['publish'] + $counts['draft'];

		// Stats.
		$stat_total    = $counts['all'];
		$stat_pub      = $counts['publish'];
		$stat_linked   = array_sum( $reservation_counts );
		$upcoming      = self::upcoming_events( $rows );

		// Producer filter options (published producers).
		$producer_options = get_posts( array(
			'post_type'   => 'en_producer',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );

		// Filter by status tab.
		$rows = array_values( array_filter( $rows, static function ( $r ) use ( $status ) {
			if ( 'trash' === $status ) { return 'trash' === $r['status']; }
			if ( 'publish' === $status ) { return 'publish' === $r['status']; }
			if ( 'draft' === $status ) { return 'draft' === $r['status']; }
			return 'trash' !== $r['status'];
		} ) );
		// Producer filter.
		if ( $producer > 0 ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $producer ) {
				return (int) $r['producer_id'] === $producer;
			} ) );
		}
		// Search.
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values( array_filter( $rows, static function ( $r ) use ( $needle ) {
				return false !== strpos( strtolower( $r['title'] ), $needle )
					|| false !== strpos( strtolower( $r['venue'] ), $needle )
					|| false !== strpos( strtolower( $r['producer'] ), $needle );
			} ) );
		}
		// Sort.
		usort( $rows, static function ( $a, $b ) use ( $orderby, $order ) {
			$cmp = 'date' === $orderby ? strcmp( (string) $a['start'], (string) $b['start'] ) : strcasecmp( $a['title'], $b['title'] );
			return 'desc' === $order ? -$cmp : $cmp;
		} );
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$page_rows = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		eem_render_page_open( array(
			'title'      => __( 'Events', 'equine-event-manager' ),
			'subtitle'   => __( 'Manage your equine events. Each event can be linked to a reservation setup for stall, RV, and add-on bookings.', 'equine-event-manager' ),
			'meta'       => sprintf(
				'<div class="eem-page-meta-links"><a href="%s">%s</a><a href="%s">%s</a></div>',
				esc_url( EEM_Venues_Page::url() ),
				esc_html__( 'View Venues', 'equine-event-manager' ),
				esc_url( EEM_Producers_Page::url() ),
				esc_html__( 'View Producers', 'equine-event-manager' )
			),
			'actions'    => sprintf(
				'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
				esc_url( admin_url( 'post-new.php?post_type=en_event' ) ),
				esc_html__( 'Add Event', 'equine-event-manager' )
			),
			'breadcrumb' => array( array( 'label' => __( 'Events', 'equine-event-manager' ) ) ),
		) );

		self::render_stats( $upcoming, $stat_total, $stat_pub, $stat_linked );
		self::render_status_tabs( $status, $counts );
		self::render_toolbar( $search, $producer, $producer_options, $total );
		self::render_table( $page_rows, $orderby, $order, $status, $search, $producer );
		self::render_footer( $total, $paged, $pages, $status, $orderby, $order, $search, $producer );

		eem_render_page_close();
	}

	/**
	 * Count published-event reservations grouped by linked event id.
	 *
	 * @return array<int,int> Event id => reservation count.
	 */
	private static function reservation_counts_by_event(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT pm.meta_value AS event_id, COUNT(*) AS c
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_en_event_id'
			   AND pm.meta_value > 0 AND p.post_type = 'en_reservation' AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			 GROUP BY pm.meta_value",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['event_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Build the next few upcoming events (start date today or later) for the
	 * "Current + Upcoming" stat card.
	 *
	 * @param array<int,array<string,mixed>> $rows All event rows.
	 * @return array<int,array{label:string,title:string}>
	 */
	private static function upcoming_events( array $rows ): array {
		$today    = gmdate( 'Y-m-d' );
		$upcoming = array_filter( $rows, static function ( $r ) use ( $today ) {
			return 'publish' === $r['status'] && '' !== $r['start'] && $r['start'] >= $today;
		} );
		usort( $upcoming, static function ( $a, $b ) {
			return strcmp( (string) $a['start'], (string) $b['start'] );
		} );
		$out = array();
		foreach ( array_slice( $upcoming, 0, 3 ) as $r ) {
			$out[] = array(
				'label' => mysql2date( 'M j', $r['start'] ),
				'title' => $r['title'],
			);
		}
		return $out;
	}

	/**
	 * Format an event's start/end dates as a display range.
	 *
	 * @param string $start Y-m-d start date.
	 * @param string $end   Y-m-d end date.
	 * @return string
	 */
	private static function format_date_range( string $start, string $end ): string {
		if ( '' === $start ) {
			return '';
		}
		if ( '' === $end || $end === $start ) {
			return mysql2date( 'M j, Y', $start );
		}
		// Same year → "Jun 14 – Jun 18, 2026"; cross-year → full both sides.
		$same_year = substr( $start, 0, 4 ) === substr( $end, 0, 4 );
		if ( $same_year ) {
			return mysql2date( 'M j', $start ) . ' – ' . mysql2date( 'M j, Y', $end );
		}
		return mysql2date( 'M j, Y', $start ) . ' – ' . mysql2date( 'M j, Y', $end );
	}

	/**
	 * Stats strip: "Current + Upcoming" wide card + Total / Published / Linked.
	 *
	 * @param array<int,array{label:string,title:string}> $upcoming
	 * @return void
	 */
	private static function render_stats( array $upcoming, int $total, int $published, int $linked ): void {
		echo '<div class="eem-venues-stats">';
		// Wide upcoming card.
		echo '<div class="eem-stat-card eem-stat-card--wide">';
		echo '<div class="eem-stat-card-label">' . esc_html__( 'Current + Upcoming', 'equine-event-manager' ) . '</div>';
		if ( empty( $upcoming ) ) {
			echo '<div class="eem-stat-card-rows"><div class="eem-stat-card-row">' . esc_html__( 'No upcoming events scheduled.', 'equine-event-manager' ) . '</div></div>';
		} else {
			echo '<div class="eem-stat-card-rows">';
			foreach ( $upcoming as $u ) {
				printf(
					'<div class="eem-stat-card-row"><strong>%s:</strong> %s</div>',
					esc_html( $u['label'] ),
					esc_html( $u['title'] )
				);
			}
			echo '</div>';
		}
		echo '</div>';

		$cards = array(
			array( __( 'Total Events', 'equine-event-manager' ), $total ),
			array( __( 'Published', 'equine-event-manager' ), $published ),
			array( __( 'Linked Reservations', 'equine-event-manager' ), $linked ),
		);
		foreach ( $cards as $c ) {
			printf(
				'<div class="eem-stat-card"><div class="eem-stat-card-label">%s</div><div class="eem-stat-card-num">%s</div></div>',
				esc_html( $c[0] ),
				esc_html( number_format_i18n( (int) $c[1] ) )
			);
		}
		echo '</div>';
	}

	/**
	 * Status tabs (All / Published / Draft / Trash).
	 *
	 * @param array<string,int> $counts
	 * @return void
	 */
	private static function render_status_tabs( string $active, array $counts ): void {
		$tabs = array(
			'all'     => __( 'All', 'equine-event-manager' ),
			'publish' => __( 'Published', 'equine-event-manager' ),
			'draft'   => __( 'Draft', 'equine-event-manager' ),
			'trash'   => __( 'Trash', 'equine-event-manager' ),
		);
		echo '<nav class="eem-status-tabs" aria-label="' . esc_attr__( 'Filter events by status', 'equine-event-manager' ) . '">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			if ( ! $first ) {
				echo '<span class="eem-status-tab-sep" aria-hidden="true">|</span>';
			}
			$first     = false;
			$is_active = $key === $active;
			printf(
				'<a class="eem-status-tab%s" href="%s"%s>%s <span class="eem-status-tab-count">(%s)</span></a>',
				$is_active ? ' is-active' : '',
				esc_url( self::url( array( 'status' => $key ) ) ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) )
			);
		}
		echo '</nav>';
	}

	/**
	 * Toolbar — producer filter + search + item count.
	 *
	 * @param WP_Post[] $producer_options
	 * @return void
	 */
	private static function render_toolbar( string $search, int $producer, array $producer_options, int $total ): void {
		?>
		<div class="eem-list-toolbar">
			<form class="eem-search-form eem-events-toolbar-form" role="search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<div class="eem-list-toolbar-left">
					<select class="eem-toolbar-select eem-field-select" name="producer" aria-label="<?php esc_attr_e( 'Filter by producer', 'equine-event-manager' ); ?>">
						<option value="0"><?php esc_html_e( 'All producers', 'equine-event-manager' ); ?></option>
						<?php foreach ( $producer_options as $po ) : ?>
							<option value="<?php echo esc_attr( (string) $po->ID ); ?>" <?php selected( $producer, $po->ID ); ?>><?php echo esc_html( get_the_title( $po->ID ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="eem-list-toolbar-right">
					<span class="eem-search-wrap eem-search-wrap--attached">
						<svg class="eem-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>" />
					</span>
					<button type="submit" class="eem-toolbar-btn eem-search-btn"><?php esc_html_e( 'Search Events', 'equine-event-manager' ); ?></button>
				</div>
				<span class="eem-item-count"><?php
					echo esc_html( sprintf(
						/* translators: %s: item count */
						_n( '%s item', '%s items', $total, 'equine-event-manager' ),
						number_format_i18n( $total )
					) );
				?></span>
			</form>
		</div>
		<?php
	}

	/**
	 * Desktop table + mobile cards.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return void
	 */
	private static function render_table( array $rows, string $orderby, string $order, string $status, string $search, int $producer ): void {
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<?php self::sortable_th( 'title', __( 'Event Title', 'equine-event-manager' ), $orderby, $order, $status, $search, $producer ); ?>
						<?php self::sortable_th( 'date', __( 'Date', 'equine-event-manager' ), $orderby, $order, $status, $search, $producer ); ?>
						<th><?php esc_html_e( 'Venue', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Producer', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
						<th style="text-align:right"><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6" class="eem-table-empty"><?php esc_html_e( 'No events match your filters.', 'equine-event-manager' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<?php
							$edit_url      = (string) get_edit_post_link( (int) $r['id'], 'raw' );
							$view_url      = (string) get_permalink( (int) $r['id'] );
							$trash_url     = (string) get_delete_post_link( (int) $r['id'] );
							$res_url       = add_query_arg( array( 'page' => EEM_Reservations_List_Page::MENU_SLUG, 'event' => (int) $r['id'] ), admin_url( 'admin.php' ) );
							?>
							<tr>
								<td>
									<a class="eem-venue-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $r['title'] ); ?></a>
								</td>
								<td><?php echo '' !== $r['date_label'] ? esc_html( $r['date_label'] ) : '<span class="eem-venue-muted">—</span>'; ?></td>
								<td><?php echo '' !== $r['venue'] ? esc_html( $r['venue'] ) : '<span class="eem-venue-muted">—</span>'; ?></td>
								<td><?php echo '' !== $r['producer'] ? esc_html( $r['producer'] ) : '<span class="eem-venue-muted">—</span>'; ?></td>
								<td><?php self::lifecycle_badge( (string) $r['start'], (string) $r['end'] ); ?></td>
								<td>
									<div class="eem-actions-cell">
										<div class="eem-row-menu-wrap">
											<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-event-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
											<div class="eem-row-dropdown" id="eem-event-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" role="menu">
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $edit_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php esc_html_e( 'Edit Event', 'equine-event-manager' ); ?></a>
												<?php if ( '' !== $view_url ) : ?>
													<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><?php esc_html_e( 'View Event', 'equine-event-manager' ); ?></a>
												<?php endif; ?>
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $res_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg><?php esc_html_e( 'View Reservations', 'equine-event-manager' ); ?></a>
												<a class="eem-row-dd-item eem-row-dd-danger" role="menuitem" href="<?php echo esc_url( $trash_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg><?php echo 'trash' === $r['status'] ? esc_html__( 'Delete Permanently', 'equine-event-manager' ) : esc_html__( 'Move to Trash', 'equine-event-manager' ); ?></a>
											</div>
										</div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="eem-mobile-cards eem-mobile-venues">
			<?php foreach ( $rows as $r ) : ?>
				<div class="eem-mobile-card">
					<div class="eem-mobile-card-top">
						<a class="eem-mobile-card-id" href="<?php echo esc_url( (string) get_edit_post_link( (int) $r['id'], 'raw' ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a>
					</div>
					<div class="eem-mobile-card-sub"><?php
						$bits = array();
						if ( '' !== $r['date_label'] ) { $bits[] = $r['date_label']; }
						$place = trim( implode( ' · ', array_filter( array( $r['venue'], $r['producer'] ) ) ) );
						if ( '' !== $place ) { $bits[] = $place; }
						echo esc_html( implode( ' — ', $bits ) );
					?></div>
					<div class="eem-mobile-card-bottom"><?php self::lifecycle_badge( (string) $r['start'], (string) $r['end'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a sortable column header.
	 *
	 * @return void
	 */
	private static function sortable_th( string $key, string $label, string $current, string $order, string $status, string $search, int $producer ): void {
		$is_active  = ( $key === $current );
		$next_order = $is_active ? ( 'asc' === $order ? 'desc' : 'asc' ) : ( 'date' === $key ? 'desc' : 'asc' );
		$args       = array( 'status' => $status, 's' => $search, 'orderby' => $key, 'order' => $next_order );
		if ( $producer > 0 ) {
			$args['producer'] = $producer;
		}
		$href    = self::url( $args );
		$classes = trim( 'sortable' . ( $is_active ? ' is-sorted is-sorted--' . $order : '' ) );
		printf(
			'<th class="%s"><a href="%s">%s <span class="eem-sort-icon" aria-hidden="true"><span></span><span></span></span></a></th>',
			esc_attr( $classes ),
			esc_url( $href ),
			esc_html( $label )
		);
	}

	/**
	 * Lifecycle pill derived from the event's dates (NOT the WP post status):
	 * Upcoming (starts in the future), Ongoing (today within start–end), or
	 * Past Event (ended). Undated events render a muted dash.
	 *
	 * @param string $start Y-m-d start date.
	 * @param string $end   Y-m-d end date.
	 * @return void
	 */
	private static function lifecycle_badge( string $start, string $end ): void {
		if ( '' === $start ) {
			echo '<span class="eem-venue-muted">—</span>';
			return;
		}
		$today = gmdate( 'Y-m-d' );
		$end   = '' !== $end ? $end : $start;
		if ( $end < $today ) {
			$class = 'eem-status-archived';
			$label = __( 'Past Event', 'equine-event-manager' );
		} elseif ( $start <= $today && $today <= $end ) {
			$class = 'eem-status-active';
			$label = __( 'Ongoing', 'equine-event-manager' );
		} else {
			$class = 'eem-status-upcoming';
			$label = __( 'Upcoming', 'equine-event-manager' );
		}
		printf( '<span class="eem-status-badge %s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Table footer with pagination.
	 *
	 * @return void
	 */
	private static function render_footer( int $total, int $paged, int $pages, string $status, string $orderby, string $order, string $search, int $producer ): void {
		$base = array( 'status' => $status, 'orderby' => $orderby, 'order' => $order, 's' => $search );
		if ( $producer > 0 ) {
			$base['producer'] = $producer;
		}
		$first = $total > 0 ? ( $paged - 1 ) * self::PER_PAGE + 1 : 0;
		$last  = min( $total, $paged * self::PER_PAGE );
		?>
		<div class="eem-table-footer">
			<span class="eem-table-footer-info"><?php
				echo esc_html( sprintf(
					/* translators: 1: first, 2: last, 3: total */
					__( 'Showing %1$s–%2$s of %3$s events', 'equine-event-manager' ),
					number_format_i18n( $first ),
					number_format_i18n( $last ),
					number_format_i18n( $total )
				) );
			?></span>
			<?php if ( $pages > 1 ) : ?>
				<nav class="eem-pagination" aria-label="<?php esc_attr_e( 'Events pagination', 'equine-event-manager' ); ?>">
					<?php if ( $paged > 1 ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $paged - 1 ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'equine-event-manager' ); ?>">‹</a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true">‹</span>
					<?php endif; ?>
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<?php if ( $i === $paged ) : ?>
							<span class="eem-page-btn active" aria-current="page"><?php echo esc_html( number_format_i18n( $i ) ); ?></span>
						<?php else : ?>
							<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $i ) ) ) ); ?>"><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>
					<?php if ( $paged < $pages ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $paged + 1 ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'equine-event-manager' ); ?>">›</a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true">›</span>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}
}
