<?php
/**
 * Venues admin page (v2 Facility Layout Templates, Slice 2).
 *
 * Lists every canonical Venue resolved by EEM_Venue (source-agnostic — fed by
 * TEC + GEMS today, Native Events in v3) with its saved-layout and source-mapping
 * counts. Drilling into a venue shows its saved Facility Layout Templates (rename
 * / delete) plus the event sources that point at it. Nested UNDER "Stall & RV
 * Charts" in the Event Manager menu (layouts are stall/RV grids) per
 * docs/ARCHITECTURE-VENUES.md §4 — there is never a second "Venues" nav entry.
 *
 * Read-mostly: the Venues themselves are created implicitly by the resolver when
 * reservations link to events; this page does not create venues directly. Layout
 * rename/delete are the only mutations, dispatched via AJAX.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Venues list + detail page controller.
 */
class EEM_Venues_Page {

	/**
	 * Visible submenu slug (sits under Stall & RV Charts in the menu order).
	 */
	const MENU_SLUG = 'equine-event-manager-venues';

	/**
	 * Register AJAX handlers (the submenu itself is registered by EEM_Admin
	 * alongside the other Event Manager submenus so the parent-slug attachment
	 * and ordering stay single-sourced).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_venue_view_layout', array( __CLASS__, 'ajax_view_layout' ) );
		add_action( 'wp_ajax_eem_venue_rename_layout', array( __CLASS__, 'ajax_rename_layout' ) );
		add_action( 'wp_ajax_eem_venue_delete_layout', array( __CLASS__, 'ajax_delete_layout' ) );
		// Slice 3 — "Save Layout" / "Load Layout" on the Edit Reservation builders.
		add_action( 'wp_ajax_eem_venue_save_layout', array( __CLASS__, 'ajax_save_layout' ) );
		add_action( 'wp_ajax_eem_venue_list_layouts', array( __CLASS__, 'ajax_list_layouts' ) );
		add_action( 'wp_ajax_eem_venue_load_layout', array( __CLASS__, 'ajax_load_layout' ) );
	}

	/**
	 * Build a Venues-page URL with query args layered on the base.
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
	 * Human-readable label for an event-source key.
	 *
	 * @param string $source Source key (tec|gems|native|...).
	 * @return string
	 */
	public static function source_label( string $source ): string {
		switch ( $source ) {
			case 'tec':
				return __( 'The Events Calendar', 'equine-event-manager' );
			case 'gems':
				return __( 'GEMS', 'equine-event-manager' );
			case 'native':
				return __( 'Native Events', 'equine-event-manager' );
			default:
				return ucfirst( $source );
		}
	}

	/**
	 * Render dispatcher — list view, or detail view when ?venue_id=N is present.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param.
		$venue_id = isset( $_GET['venue_id'] ) ? absint( wp_unslash( $_GET['venue_id'] ) ) : 0;
		if ( $venue_id > 0 ) {
			self::render_detail( $venue_id );
			return;
		}
		self::render_list();
	}

	/**
	 * Rows per page on the Venues list.
	 */
	const PER_PAGE = 20;

	/**
	 * Branded Venues list — the single Venues admin surface, backed by en_venue
	 * posts (venue details) plus each venue's saved Facility Template count from
	 * the relational EEM_Venue store. Matches .mockups/venues_admin_page.html.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list nav.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) && 'templates' === $_GET['orderby'] ? 'templates' : 'title';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'desc' : 'asc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, array( 'all', 'publish', 'draft', 'trash' ), true ) ) {
			$status = 'all';
		}

		// One pass over every venue post → row data + status counts.
		$posts  = get_posts( array(
			'post_type'   => 'en_venue',
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
			$city    = trim( (string) get_post_meta( $p->ID, '_equine_event_manager_venue_city', true ) );
			$state   = trim( (string) get_post_meta( $p->ID, '_equine_event_manager_venue_state', true ) );
			$website = trim( (string) get_post_meta( $p->ID, '_equine_event_manager_venue_website', true ) );
			$tpl_vid = EEM_Venue::find( '', '', get_the_title( $p->ID ) );
			$rows[]  = array(
				'id'         => $p->ID,
				'title'      => get_the_title( $p->ID ),
				'status'     => $p->post_status,
				'city_state' => trim( implode( ', ', array_filter( array( $city, $state ) ) ) ),
				'has_site'   => '' !== $website,
				'tpl_count'  => $tpl_vid > 0 ? count( EEM_Venue::get_layouts( $tpl_vid ) ) : 0,
				'tpl_vid'    => $tpl_vid,
			);
		}
		$counts['all'] = $counts['publish'] + $counts['draft'];

		// Stats strip.
		$stat_total     = $counts['all'];
		$stat_published = $counts['publish'];
		$stat_site      = count( array_filter( $rows, static function ( $r ) { return $r['has_site'] && 'trash' !== $r['status']; } ) );
		$stat_templates = array_sum( array_map( static function ( $v ) { return (int) $v['layout_count']; }, EEM_Venue::all_with_counts() ) );
		$stat_in_use    = self::count_venues_in_use();

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
				return false !== strpos( strtolower( $r['title'] ), $needle ) || false !== strpos( strtolower( $r['city_state'] ), $needle );
			} ) );
		}
		// Sort.
		usort( $rows, static function ( $a, $b ) use ( $orderby, $order ) {
			$cmp = 'templates' === $orderby ? ( $a['tpl_count'] <=> $b['tpl_count'] ) : strcasecmp( $a['title'], $b['title'] );
			return 'desc' === $order ? -$cmp : $cmp;
		} );
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$page_rows = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		eem_render_page_open( array(
			'title'      => __( 'Venues', 'equine-event-manager' ),
			'subtitle'   => __( 'Manage the facilities where your events are held. Each venue can store facility templates for stall &amp; RV layouts.', 'equine-event-manager' ),
			'meta'       => sprintf(
				'<div class="eem-page-meta-links"><a href="%s">%s</a><a href="%s">%s</a></div>',
				esc_url( admin_url( 'edit.php?post_type=en_event' ) ),
				esc_html__( 'View Events', 'equine-event-manager' ),
				esc_url( admin_url( 'edit.php?post_type=en_producer' ) ),
				esc_html__( 'View Producers', 'equine-event-manager' )
			),
			'actions'    => sprintf(
				'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
				esc_url( admin_url( 'post-new.php?post_type=en_venue' ) ),
				esc_html__( 'Add Venue', 'equine-event-manager' )
			),
			'breadcrumb' => array( array( 'label' => __( 'Venues', 'equine-event-manager' ) ) ),
		) );

		self::render_stats( $stat_total, $stat_published, $stat_in_use, $stat_site, $stat_templates );
		self::render_status_tabs( $status, $counts );
		self::render_toolbar( $search, $total );
		self::render_table( $page_rows, $orderby, $order, $status, $search );
		self::render_footer( $total, $paged, $pages, $status, $orderby, $order, $search );

		eem_render_page_close();
	}

	/**
	 * Count distinct venues referenced by at least one published native event.
	 *
	 * @return int
	 */
	private static function count_venues_in_use(): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			"SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_equine_event_manager_event_venue_id'
			   AND pm.meta_value > 0 AND p.post_type = 'en_event' AND p.post_status = 'publish'"
		);
	}

	/**
	 * Stats-card strip.
	 *
	 * @return void
	 */
	private static function render_stats( int $total, int $published, int $in_use, int $with_site, int $templates ): void {
		$cards = array(
			array( __( 'Total', 'equine-event-manager' ), $total ),
			array( __( 'Published', 'equine-event-manager' ), $published ),
			array( __( 'In Use', 'equine-event-manager' ), $in_use ),
			array( __( 'With Website', 'equine-event-manager' ), $with_site ),
			array( __( 'Facility Templates', 'equine-event-manager' ), $templates ),
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
		echo '<nav class="eem-status-tabs" aria-label="' . esc_attr__( 'Filter venues by status', 'equine-event-manager' ) . '">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			if ( ! $first ) {
				echo '<span class="eem-status-tab-sep" aria-hidden="true">|</span>';
			}
			$first   = false;
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
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" />
					</span>
					<button type="submit" class="eem-toolbar-btn eem-search-btn"><?php esc_html_e( 'Search Venues', 'equine-event-manager' ); ?></button>
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
						<?php self::sortable_th( 'title', __( 'Venue Name', 'equine-event-manager' ), $orderby, $order, $status, $search ); ?>
						<th><?php esc_html_e( 'City / State', 'equine-event-manager' ); ?></th>
						<?php self::sortable_th( 'templates', __( 'Facility Templates', 'equine-event-manager' ), $orderby, $order, $status, $search ); ?>
						<th style="text-align:right"><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4" class="eem-table-empty"><?php esc_html_e( 'No venues match your filters.', 'equine-event-manager' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<?php
							$edit_url  = (string) get_edit_post_link( (int) $r['id'], 'raw' );
							$trash_url = (string) get_delete_post_link( (int) $r['id'] );
							$tpl_url   = $r['tpl_vid'] > 0 ? self::url( array( 'venue_id' => (int) $r['tpl_vid'] ) ) : '';
							?>
							<tr>
								<td>
									<a class="eem-venue-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $r['title'] ); ?></a>
								</td>
								<td><?php echo '' !== $r['city_state'] ? esc_html( $r['city_state'] ) : '<span class="eem-venue-muted">—</span>'; ?></td>
								<td>
									<?php if ( (int) $r['tpl_count'] > 0 ) : ?>
										<span class="eem-tpl-count"><?php echo esc_html( number_format_i18n( (int) $r['tpl_count'] ) ); ?></span>
										<?php if ( '' !== $tpl_url ) : ?>
											<a class="eem-tpl-link" href="<?php echo esc_url( $tpl_url ); ?>"><?php esc_html_e( 'View', 'equine-event-manager' ); ?></a>
										<?php endif; ?>
									<?php else : ?>
										<span class="eem-venue-muted">—</span>
									<?php endif; ?>
								</td>
								<td>
									<div class="eem-actions-cell">
										<div class="eem-row-menu-wrap">
											<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-venue-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
											<div class="eem-row-dropdown" id="eem-venue-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" role="menu">
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $edit_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php esc_html_e( 'Edit Venue', 'equine-event-manager' ); ?></a>
												<?php if ( '' !== $tpl_url ) : ?>
													<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $tpl_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg><?php esc_html_e( 'Facility Templates', 'equine-event-manager' ); ?></a>
												<?php endif; ?>
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( admin_url( 'edit.php?post_type=en_event' ) ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg><?php esc_html_e( 'View Events', 'equine-event-manager' ); ?></a>
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
						if ( '' !== $r['city_state'] ) { $bits[] = $r['city_state']; }
						$bits[] = sprintf(
							/* translators: %s: template count */
							_n( '%s facility template', '%s facility templates', (int) $r['tpl_count'], 'equine-event-manager' ),
							number_format_i18n( (int) $r['tpl_count'] )
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
		$next_order = $is_active ? ( 'asc' === $order ? 'desc' : 'asc' ) : ( 'templates' === $key ? 'desc' : 'asc' );
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
					__( 'Showing %1$s–%2$s of %3$s venues', 'equine-event-manager' ),
					number_format_i18n( $first ),
					number_format_i18n( $last ),
					number_format_i18n( $total )
				) );
			?></span>
			<?php if ( $pages > 1 ) : ?>
				<nav class="eem-pagination" aria-label="<?php esc_attr_e( 'Venues pagination', 'equine-event-manager' ); ?>">
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

	/**
	 * Single-venue detail view (saved layouts + source mappings).
	 *
	 * @param int $venue_id Venue id.
	 * @return void
	 */
	private static function render_detail( int $venue_id ): void {
		$venue = EEM_Venue::get( $venue_id );
		if ( null === $venue ) {
			eem_render_page_open(
				array(
					'title'      => __( 'Venue not found', 'equine-event-manager' ),
					'breadcrumb' => array(
						array( 'label' => __( 'Venues', 'equine-event-manager' ), 'href' => self::url() ),
						array( 'label' => __( 'Not found', 'equine-event-manager' ) ),
					),
				)
			);
			echo '<div class="eem-venues"><div class="eem-venues-empty"><p>' . esc_html__( 'That venue no longer exists.', 'equine-event-manager' ) . '</p><p><a class="eem-btn eem-btn-secondary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Venues', 'equine-event-manager' ) . '</a></p></div></div>';
			eem_render_page_close();
			return;
		}

		$layouts = EEM_Venue::get_layouts( $venue_id );
		$sources = EEM_Venue::get_source_mappings( $venue_id );
		$nonce   = wp_create_nonce( 'eem_venue_layout' );

		eem_render_page_open(
			array(
				'title'      => $venue['name'],
				'subtitle'   => sprintf(
					/* translators: %s: saved-layout count */
					_n( '%s saved layout', '%s saved layouts', count( $layouts ), 'equine-event-manager' ),
					number_format_i18n( count( $layouts ) )
				),
				'breadcrumb' => array(
					array( 'label' => __( 'Stall & RV Charts', 'equine-event-manager' ), 'href' => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ) ),
					array( 'label' => __( 'Venues', 'equine-event-manager' ), 'href' => self::url() ),
					array( 'label' => $venue['name'] ),
				),
			)
		);
		?>
		<div class="eem-venues eem-venue-detail" data-venue-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="eem-card eem-venue-card">
				<div class="eem-card-header"><h2 class="eem-card-title"><?php esc_html_e( 'Saved Layouts', 'equine-event-manager' ); ?></h2></div>
				<div class="eem-card-body">
					<?php if ( empty( $layouts ) ) : ?>
						<p class="eem-venue-empty-note"><?php esc_html_e( 'No saved layouts for this venue yet. Use "Save Layout" on a reservation’s stall or RV builder to capture one.', 'equine-event-manager' ); ?></p>
					<?php else : ?>
						<table class="eem-table eem-venue-layouts-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Layout', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Saved', 'equine-event-manager' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $layouts as $l ) : ?>
									<tr data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>">
										<td class="eem-venue-layout-name"><?php echo esc_html( $l['name'] ); ?></td>
										<td><?php echo esc_html( self::format_date( (string) $l['created_at'] ) ); ?></td>
										<td class="eem-table-r">
											<button type="button" class="eem-btn eem-btn-primary eem-btn-sm" data-eem-action="venue-layout-view" data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>" data-layout-name="<?php echo esc_attr( (string) $l['name'] ); ?>"><?php esc_html_e( 'View', 'equine-event-manager' ); ?></button>
											<button type="button" class="eem-btn eem-btn-secondary eem-btn-sm" data-eem-action="venue-layout-rename" data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>" data-layout-name="<?php echo esc_attr( (string) $l['name'] ); ?>"><?php esc_html_e( 'Rename', 'equine-event-manager' ); ?></button>
											<button type="button" class="eem-btn eem-btn-danger eem-btn-sm" data-eem-action="venue-layout-delete" data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>" data-layout-name="<?php echo esc_attr( (string) $l['name'] ); ?>"><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="eem-card eem-venue-card">
				<div class="eem-card-header"><h2 class="eem-card-title"><?php esc_html_e( 'Event Sources', 'equine-event-manager' ); ?></h2></div>
				<div class="eem-card-body">
					<p class="eem-venue-empty-note"><?php esc_html_e( 'These event sources resolve to this venue. The same physical place reached through multiple sources or seasons unifies here.', 'equine-event-manager' ); ?></p>
					<?php if ( ! empty( $sources ) ) : ?>
						<table class="eem-table eem-venue-sources-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Source Venue Name', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Source ID', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sources as $s ) : ?>
									<tr>
										<td><span class="eem-status-badge eem-venue-source-badge"><?php echo esc_html( self::source_label( (string) $s['source'] ) ); ?></span></td>
										<td><?php echo esc_html( '' !== (string) $s['source_venue_name'] ? (string) $s['source_venue_name'] : '—' ); ?></td>
										<td><?php echo esc_html( '' !== (string) $s['source_venue_id'] ? (string) $s['source_venue_id'] : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Format a stored MySQL datetime to the site date format.
	 *
	 * @param string $mysql_datetime Stored datetime.
	 * @return string
	 */
	private static function format_date( string $mysql_datetime ): string {
		$ts = strtotime( $mysql_datetime );
		if ( ! $ts ) {
			return $mysql_datetime;
		}
		return date_i18n( (string) get_option( 'date_format', 'M j, Y' ), $ts );
	}

	/* ── AJAX ───────────────────────────────────────────────────── */

	/**
	 * Shared guard for the layout AJAX handlers (cap + nonce).
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_venue_layout', 'nonce' );
	}

	/**
	 * AJAX: return a layout's grid data for the read-only preview modal.
	 *
	 * @return void
	 */
	public static function ajax_view_layout(): void {
		self::guard();
		$layout_id = isset( $_POST['layout_id'] ) ? absint( wp_unslash( $_POST['layout_id'] ) ) : 0;
		if ( $layout_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid layout.', 'equine-event-manager' ) ), 400 );
		}
		$layout = EEM_Venue::get_layout( $layout_id );
		if ( null === $layout ) {
			wp_send_json_error( array( 'message' => __( 'Layout not found.', 'equine-event-manager' ) ), 404 );
		}
		$data = is_array( $layout['layout'] ?? null ) ? $layout['layout'] : array();
		$stall_map = isset( $data['_en_stall_map'] ) && is_array( $data['_en_stall_map'] ) ? $data['_en_stall_map'] : array();
		$rv_map    = isset( $data['_en_rv_map'] ) && is_array( $data['_en_rv_map'] ) ? $data['_en_rv_map'] : array();
		wp_send_json_success( array(
			'name'      => (string) $layout['name'],
			'stall_map' => $stall_map,
			'rv_map'    => $rv_map,
		) );
	}

	/**
	 * AJAX: rename a saved layout.
	 *
	 * @return void
	 */
	public static function ajax_rename_layout(): void {
		self::guard();
		$layout_id = isset( $_POST['layout_id'] ) ? absint( wp_unslash( $_POST['layout_id'] ) ) : 0;
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( $layout_id <= 0 || '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'A layout name is required.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! EEM_Venue::rename_layout( $layout_id, $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not rename the layout.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success( array( 'name' => $name ) );
	}

	/**
	 * AJAX: delete a saved layout.
	 *
	 * @return void
	 */
	public static function ajax_delete_layout(): void {
		self::guard();
		$layout_id = isset( $_POST['layout_id'] ) ? absint( wp_unslash( $_POST['layout_id'] ) ) : 0;
		if ( $layout_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid layout.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! EEM_Venue::delete_layout( $layout_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete the layout.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success();
	}

	/* ── Editor "Save Layout" / "Load Layout" ───────────────────── */

	/**
	 * AJAX: save the reservation's current saved layout to its resolved Venue
	 * (the "Save Layout" action on the Edit Reservation stall/RV builders).
	 * Captures the COMBINED structural layout (stall grid + RV lots/zones +
	 * blocked units + map geometry) from the reservation's persisted post-meta.
	 *
	 * @return void
	 */
	public static function ajax_save_layout(): void {
		self::guard();
		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( $reservation_id <= 0 || EEM_Reservations_CPT::POST_TYPE !== get_post_type( $reservation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reservation.', 'equine-event-manager' ) ), 400 );
		}
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'A layout name is required.', 'equine-event-manager' ) ), 400 );
		}
		$venue_id = EEM_Venue::resolve_for_reservation( $reservation_id );
		if ( $venue_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Link this reservation to an event before saving a layout — the layout is saved to that event’s venue.', 'equine-event-manager' ) ), 409 );
		}
		$layout_id = EEM_Venue::save_layout( $venue_id, $reservation_id, $name );
		if ( $layout_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the layout.', 'equine-event-manager' ) ), 500 );
		}
		$venue = EEM_Venue::get( $venue_id );
		wp_send_json_success( array(
			'layout_id'  => $layout_id,
			'venue_name' => $venue ? (string) $venue['name'] : '',
		) );
	}

	/**
	 * AJAX: list the saved layouts available to a reservation's resolved Venue
	 * (read-only; never creates a venue). Powers the "Load Layout" picker.
	 *
	 * @return void
	 */
	public static function ajax_list_layouts(): void {
		self::guard();
		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		if ( $reservation_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reservation.', 'equine-event-manager' ) ), 400 );
		}
		$venue_id = EEM_Venue::find_for_reservation( $reservation_id );
		if ( $venue_id <= 0 ) {
			wp_send_json_success( array( 'venue_name' => '', 'layouts' => array() ) );
		}
		$venue   = EEM_Venue::get( $venue_id );
		$out      = array();
		foreach ( EEM_Venue::get_layouts( $venue_id ) as $l ) {
			$out[] = array(
				'id'      => (int) $l['id'],
				'name'    => (string) $l['name'],
				'created' => self::format_date( (string) $l['created_at'] ),
			);
		}
		wp_send_json_success( array(
			'venue_name' => $venue ? (string) $venue['name'] : '',
			'layouts'    => $out,
		) );
	}

	/**
	 * AJAX: clone a saved Venue layout into the reservation (the "Load Layout"
	 * action). COPY-ON-USE — writes the layout onto THIS reservation's post-meta;
	 * never mutates the saved Venue layout. Verifies the chosen layout belongs to
	 * the reservation's own resolved venue.
	 *
	 * @return void
	 */
	public static function ajax_load_layout(): void {
		self::guard();
		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$layout_id      = isset( $_POST['layout_id'] ) ? absint( wp_unslash( $_POST['layout_id'] ) ) : 0;
		if ( $reservation_id <= 0 || EEM_Reservations_CPT::POST_TYPE !== get_post_type( $reservation_id ) || $layout_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'equine-event-manager' ) ), 400 );
		}
		$layout   = EEM_Venue::get_layout( $layout_id );
		$venue_id = EEM_Venue::find_for_reservation( $reservation_id );
		if ( null === $layout || $venue_id <= 0 || (int) $layout['venue_id'] !== $venue_id ) {
			wp_send_json_error( array( 'message' => __( 'That layout is not available for this reservation’s venue.', 'equine-event-manager' ) ), 409 );
		}
		if ( ! EEM_Venue::apply_layout_to_reservation( $layout_id, $reservation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not load the layout.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success();
	}
}
