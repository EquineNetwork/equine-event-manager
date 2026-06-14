<?php
/**
 * Branded Producers list page (Native Events Admin C).
 *
 * Replaces the raw WP `edit.php?post_type=en_producer` list with a branded list
 * surface modeled on EEM_Venues_Page (stats strip, status tabs, search, sortable
 * table, per-row action dropdown, mobile cards). Producers are organizers behind
 * events; each en_producer post carries email / phone / website meta and is
 * linked from events via `_equine_event_manager_event_producer_id`.
 *
 * List-only: "Edit Producer" links to the native WP producer editor (which owns
 * the contact meta box). The raw CPT list is redirected here from EEM_Admin.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Producers admin list controller.
 */
class EEM_Producers_Page {

	/**
	 * Admin menu slug for the branded Producers list.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-producers';

	/**
	 * Post type backing this list.
	 *
	 * @var string
	 */
	const POST_TYPE = 'en_producer';

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
	 * Branded Producers list — backed by en_producer posts plus each producer's
	 * linked published-event count.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list nav.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) && 'events' === $_GET['orderby'] ? 'events' : 'title';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'desc' : 'asc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, array( 'all', 'publish', 'draft', 'trash' ), true ) ) {
			$status = 'all';
		}

		$event_counts = self::event_counts_by_producer();

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
			$email   = trim( (string) get_post_meta( $p->ID, '_equine_event_manager_producer_email', true ) );
			$phone   = trim( (string) get_post_meta( $p->ID, '_equine_event_manager_producer_phone', true ) );
			$website = trim( (string) get_post_meta( $p->ID, '_equine_event_manager_producer_website', true ) );
			$rows[]  = array(
				'id'          => $p->ID,
				'title'       => get_the_title( $p->ID ),
				'status'      => $p->post_status,
				'contact'     => trim( implode( ' · ', array_filter( array( $email, $phone ) ) ) ),
				'has_site'    => '' !== $website,
				'event_count' => isset( $event_counts[ $p->ID ] ) ? (int) $event_counts[ $p->ID ] : 0,
			);
		}
		$counts['all'] = $counts['publish'] + $counts['draft'];

		// Stats strip.
		$stat_total     = $counts['all'];
		$stat_published = $counts['publish'];
		$stat_site      = count( array_filter( $rows, static function ( $r ) { return $r['has_site'] && 'trash' !== $r['status']; } ) );
		$stat_in_use    = count( array_filter( $rows, static function ( $r ) { return $r['event_count'] > 0 && 'trash' !== $r['status']; } ) );

		// Filter by status tab.
		$rows = array_values( array_filter( $rows, static function ( $r ) use ( $status ) {
			if ( 'trash' === $status ) { return 'trash' === $r['status']; }
			if ( 'publish' === $status ) { return 'publish' === $r['status']; }
			if ( 'draft' === $status ) { return 'draft' === $r['status']; }
			return 'trash' !== $r['status'];
		} ) );
		// Search.
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values( array_filter( $rows, static function ( $r ) use ( $needle ) {
				return false !== strpos( strtolower( $r['title'] ), $needle ) || false !== strpos( strtolower( $r['contact'] ), $needle );
			} ) );
		}
		// Sort.
		usort( $rows, static function ( $a, $b ) use ( $orderby, $order ) {
			$cmp = 'events' === $orderby ? ( $a['event_count'] <=> $b['event_count'] ) : strcasecmp( $a['title'], $b['title'] );
			return 'desc' === $order ? -$cmp : $cmp;
		} );
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$page_rows = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		eem_render_page_open( array(
			'title'      => __( 'Producers', 'equine-event-manager' ),
			'subtitle'   => __( 'Manage the producers and organizers behind your events. Each producer can be linked to any number of events.', 'equine-event-manager' ),
			'meta'       => sprintf(
				'<div class="eem-page-meta-links"><a href="%s">%s</a><a href="%s">%s</a></div>',
				esc_url( admin_url( 'edit.php?post_type=en_event' ) ),
				esc_html__( 'View Events', 'equine-event-manager' ),
				esc_url( EEM_Venues_Page::url() ),
				esc_html__( 'View Venues', 'equine-event-manager' )
			),
			'actions'    => sprintf(
				'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
				esc_url( admin_url( 'post-new.php?post_type=en_producer' ) ),
				esc_html__( 'Add Producer', 'equine-event-manager' )
			),
			'breadcrumb' => array( array( 'label' => __( 'Producers', 'equine-event-manager' ) ) ),
		) );

		self::render_stats( $stat_total, $stat_published, $stat_in_use, $stat_site );
		self::render_status_tabs( $status, $counts );
		self::render_toolbar( $search, $total );
		self::render_table( $page_rows, $orderby, $order, $status, $search );
		self::render_footer( $total, $paged, $pages, $status, $orderby, $order, $search );

		eem_render_page_close();
	}

	/**
	 * Count published native events grouped by linked producer id.
	 *
	 * @return array<int,int> Producer id => published event count.
	 */
	private static function event_counts_by_producer(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT pm.meta_value AS producer_id, COUNT(*) AS c
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_equine_event_manager_event_producer_id'
			   AND pm.meta_value > 0 AND p.post_type = 'en_event' AND p.post_status = 'publish'
			 GROUP BY pm.meta_value",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['producer_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Stats-card strip (Total / Published / In Use / With Website).
	 *
	 * @return void
	 */
	private static function render_stats( int $total, int $published, int $in_use, int $with_site ): void {
		$cards = array(
			array( __( 'Total', 'equine-event-manager' ), $total ),
			array( __( 'Published', 'equine-event-manager' ), $published ),
			array( __( 'In Use', 'equine-event-manager' ), $in_use ),
			array( __( 'With Website', 'equine-event-manager' ), $with_site ),
		);
		echo '<div class="eem-venues-stats">';
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
		echo '<nav class="eem-status-tabs" aria-label="' . esc_attr__( 'Filter producers by status', 'equine-event-manager' ) . '">';
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
	 * Toolbar — search + item count.
	 *
	 * @return void
	 */
	private static function render_toolbar( string $search, int $total ): void {
		?>
		<div class="eem-list-toolbar eem-toolbar-controls">
			<div class="eem-list-toolbar-left"></div>
			<div class="eem-list-toolbar-right">
				<form class="eem-search-form" role="search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<span class="eem-search-wrap eem-search-wrap--attached">
						<svg class="eem-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search producers…', 'equine-event-manager' ); ?>" />
					</span>
					<button type="submit" class="eem-toolbar-btn eem-search-btn"><?php esc_html_e( 'Search Producers', 'equine-event-manager' ); ?></button>
				</form>
				<span class="eem-item-count"><?php
					echo esc_html( sprintf(
						/* translators: %s: item count */
						_n( '%s item', '%s items', $total, 'equine-event-manager' ),
						number_format_i18n( $total )
					) );
				?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Desktop table + mobile cards.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return void
	 */
	private static function render_table( array $rows, string $orderby, string $order, string $status, string $search ): void {
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<?php self::sortable_th( 'title', __( 'Producer Name', 'equine-event-manager' ), $orderby, $order, $status, $search ); ?>
						<th><?php esc_html_e( 'Contact', 'equine-event-manager' ); ?></th>
						<?php self::sortable_th( 'events', __( 'Events', 'equine-event-manager' ), $orderby, $order, $status, $search ); ?>
						<th style="text-align:right"><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4" class="eem-table-empty"><?php esc_html_e( 'No producers match your filters.', 'equine-event-manager' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<?php
							$edit_url  = (string) get_edit_post_link( (int) $r['id'], 'raw' );
							$trash_url = (string) get_delete_post_link( (int) $r['id'] );
							$events_url = admin_url( 'edit.php?post_type=en_event' );
							?>
							<tr>
								<td>
									<a class="eem-venue-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $r['title'] ); ?></a>
								</td>
								<td><?php echo '' !== $r['contact'] ? esc_html( $r['contact'] ) : '<span class="eem-venue-muted">—</span>'; ?></td>
								<td>
									<?php if ( (int) $r['event_count'] > 0 ) : ?>
										<span class="eem-tpl-count"><?php echo esc_html( number_format_i18n( (int) $r['event_count'] ) ); ?></span>
										<a class="eem-tpl-link" href="<?php echo esc_url( $events_url ); ?>"><?php esc_html_e( 'View', 'equine-event-manager' ); ?></a>
									<?php else : ?>
										<span class="eem-venue-muted">—</span>
									<?php endif; ?>
								</td>
								<td>
									<div class="eem-actions-cell">
										<div class="eem-row-menu-wrap">
											<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-producer-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
											<div class="eem-row-dropdown" id="eem-producer-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" role="menu">
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $edit_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php esc_html_e( 'Edit Producer', 'equine-event-manager' ); ?></a>
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $events_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg><?php esc_html_e( 'View Events', 'equine-event-manager' ); ?></a>
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
						if ( '' !== $r['contact'] ) { $bits[] = $r['contact']; }
						$bits[] = sprintf(
							/* translators: %s: event count */
							_n( '%s event', '%s events', (int) $r['event_count'], 'equine-event-manager' ),
							number_format_i18n( (int) $r['event_count'] )
						);
						echo esc_html( implode( ' · ', $bits ) );
					?></div>
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
	private static function sortable_th( string $key, string $label, string $current, string $order, string $status, string $search ): void {
		$is_active  = ( $key === $current );
		$next_order = $is_active ? ( 'asc' === $order ? 'desc' : 'asc' ) : ( 'events' === $key ? 'desc' : 'asc' );
		$href       = self::url( array( 'status' => $status, 's' => $search, 'orderby' => $key, 'order' => $next_order ) );
		$classes    = trim( 'sortable' . ( $is_active ? ' is-sorted is-sorted--' . $order : '' ) );
		printf(
			'<th class="%s"><a href="%s">%s <span class="eem-sort-icon" aria-hidden="true"><span></span><span></span></span></a></th>',
			esc_attr( $classes ),
			esc_url( $href ),
			esc_html( $label )
		);
	}


	/**
	 * Table footer with pagination.
	 *
	 * @return void
	 */
	private static function render_footer( int $total, int $paged, int $pages, string $status, string $orderby, string $order, string $search ): void {
		$base  = array( 'status' => $status, 'orderby' => $orderby, 'order' => $order, 's' => $search );
		$first = $total > 0 ? ( $paged - 1 ) * self::PER_PAGE + 1 : 0;
		$last  = min( $total, $paged * self::PER_PAGE );
		?>
		<div class="eem-table-footer">
			<span class="eem-table-footer-info"><?php
				echo esc_html( sprintf(
					/* translators: 1: first, 2: last, 3: total */
					__( 'Showing %1$s–%2$s of %3$s producers', 'equine-event-manager' ),
					number_format_i18n( $first ),
					number_format_i18n( $last ),
					number_format_i18n( $total )
				) );
			?></span>
			<?php if ( $pages > 1 ) : ?>
				<nav class="eem-pagination" aria-label="<?php esc_attr_e( 'Producers pagination', 'equine-event-manager' ); ?>">
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
