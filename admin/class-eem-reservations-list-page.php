<?php
/**
 * Reservations list page controller (C4 — replaces WP-native
 * edit.php?post_type=en_reservation with a custom mockup-faithful page).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that lists reservations per the mockup at
 * .mockups/reservations_page.html.
 *
 * Architecture (per CLAUDE.md layout-shell verification step 4):
 *   Bordered list-card with five stacked internal sections divided by
 *   horizontal borders — status tabs → toolbar (#fafafa) → desktop
 *   table → mobile cards (hidden on desktop) → pagination footer
 *   (#fafafa). Sits below a `.eem-page-header` row (title + "+ New
 *   Reservation" CTA). Container chrome comes from the shared
 *   `.eem-page` shell (`templates/admin/_page_shell.php`).
 *
 * Departure from WordPress admin convention: this page does NOT extend
 * `WP_List_Table` — the mockup's chrome (custom status-tabs strip,
 * #fafafa toolbar with two filter groups, distinct mobile-card mode,
 * bespoke pagination footer) is too far from the WP-native list-table
 * defaults to be worth fighting the framework. Same Path B pattern we
 * used for the Settings page port in C3.
 *
 * C4 sub-chunks land here in order:
 *   C4.A — Scaffold + menu rewire + redirect-from-old-list (this file)
 *   C4.B — Full render body (status tabs / toolbar / table / footer / mobile)
 *   C4.C — Row actions + Email Customers modal
 *   C4.D — Bulk actions + sort / filter / pagination + smoke test
 */
class EEM_Reservations_List_Page {

	const MENU_SLUG = 'equine-event-manager-reservations';

	/**
	 * Build the admin URL for this page with optional query args
	 * (status tab, search, paged, etc.). Used by render() + by the
	 * old-list redirect.
	 *
	 * @param array<string, string|int> $args
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page. Renders the standalone page header + a bordered
	 * .eem-list-card containing status tabs, toolbar, desktop table,
	 * mobile cards, and pagination footer per the mockup at
	 * .mockups/reservations_page.html.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// Read query state. Filter handlers land in C4.D; for C4.B we
		// honour the params if present so the page round-trips on its
		// own ?status= / ?paged= links.
		$active_tab = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$tabs       = EEM_Reservations_List_Repo::status_tabs();
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'all';
		}
		$search  = isset( $_GET['s'] )     ? sanitize_text_field( wp_unslash( $_GET['s'] ) )    : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) )  : 1;
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'event_dates';
		$order   = isset( $_GET['order'] )   ? sanitize_key( wp_unslash( $_GET['order'] ) )   : 'asc';

		$counts = EEM_Reservations_List_Repo::counts_by_tab();
		$page   = EEM_Reservations_List_Repo::get_paginated( array(
			'status'   => $active_tab,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'paged'    => $paged,
			'per_page' => 25,
		) );

		eem_render_page_open(
			array(
				'title'      => __( 'Reservations', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Reservations', 'equine-event-manager' ) ),
				),
				'actions'    => sprintf(
					'<a class="eem-btn eem-btn-primary" href="%s">+ %s</a>',
					esc_url( admin_url( 'post-new.php?post_type=' . EEM_Reservations_List_Repo::POST_TYPE ) ),
					esc_html__( 'New Reservation', 'equine-event-manager' )
				),
				'wrap'       => false,
			)
		);

		?>
		<div class="eem-list-card eem-reservations-list" data-eem-reservations-list>
			<?php $this->render_status_tabs( $active_tab, $counts ); ?>
			<?php $this->render_toolbar( $search, $page['total'] ); ?>
			<?php $this->render_desktop_table( $page['items'], $orderby, $order ); ?>
			<?php $this->render_mobile_cards( $page['items'] ); ?>
			<?php $this->render_table_footer( $page ); ?>
		</div>
		<?php

		eem_render_page_close( array( 'wrap' => false ) );
	}

	/**
	 * Status tabs strip at top of the list card (All / Published / Draft
	 * / Trash) with per-tab counts. Mockup lines 244–252.
	 *
	 * @param string             $active_tab
	 * @param array<string, int> $counts
	 * @return void
	 */
	private function render_status_tabs( $active_tab, array $counts ) {
		$tabs = array(
			'all'     => EEM_Reservations_List_Repo::tab_label( 'all' ),
			'publish' => EEM_Reservations_List_Repo::tab_label( 'publish' ),
			'draft'   => EEM_Reservations_List_Repo::tab_label( 'draft' ),
			'trash'   => EEM_Reservations_List_Repo::tab_label( 'trash' ),
		);
		?>
		<nav class="eem-status-tabs" aria-label="<?php esc_attr_e( 'Filter by status', 'equine-event-manager' ); ?>">
			<?php
			$first = true;
			foreach ( $tabs as $id => $label ) :
				$is_active = ( $id === $active_tab );
				$count     = isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0;
				?>
				<?php if ( ! $first ) : ?><span class="eem-status-tab-sep" aria-hidden="true">|</span><?php endif; ?>
				<a class="eem-status-tab<?php echo $is_active ? ' is-active' : ''; ?>"
				   href="<?php echo esc_url( self::url( array( 'status' => $id ) ) ); ?>"
				   <?php echo $is_active ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
					<span class="eem-status-tab-count">(<?php echo esc_html( number_format_i18n( $count ) ); ?>)</span>
				</a>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Toolbar — bulk actions select + Apply, date filter + Filter,
	 * search box + Search button + item count. Mockup lines 254–270.
	 * Bulk-action submit + search submit wire in C4.D.
	 *
	 * @param string $search   Current search term (echoed back into the input).
	 * @param int    $total    Total matching items (for the "N items" pill).
	 * @return void
	 */
	private function render_toolbar( $search, $total ) {
		?>
		<div class="eem-list-toolbar">
			<div class="eem-list-toolbar-left">
				<select class="eem-toolbar-select" name="bulk_action" disabled>
					<option><?php esc_html_e( 'Bulk actions', 'equine-event-manager' ); ?></option>
					<option value="edit"><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></option>
					<option value="trash"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></option>
				</select>
				<button type="button" class="eem-toolbar-btn" disabled><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
				<select class="eem-toolbar-select" name="date_filter" disabled>
					<option><?php esc_html_e( 'All dates', 'equine-event-manager' ); ?></option>
				</select>
				<button type="button" class="eem-toolbar-btn" disabled><?php esc_html_e( 'Filter', 'equine-event-manager' ); ?></button>
			</div>
			<div class="eem-list-toolbar-right">
				<form method="get" class="eem-search-wrap" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search reservations…', 'equine-event-manager' ); ?>" />
				</form>
				<button type="button" class="eem-toolbar-btn" disabled><?php esc_html_e( 'Search Reservations', 'equine-event-manager' ); ?></button>
				<span class="eem-item-count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: total item count (already number_format_i18n'd) */
							_n( '%s item', '%s items', $total, 'equine-event-manager' ),
							number_format_i18n( $total )
						)
					);
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Desktop table — checkbox / Reservation / Event Dates / Type /
	 * Status / Orders / Actions columns. Sortable headers send the user
	 * to ?orderby=…&order=…. C4.D wires real sort handling; C4.B emits
	 * the header links so the markup is forward-compatible.
	 *
	 * @param WP_Post[] $items
	 * @param string    $orderby
	 * @param string    $order
	 * @return void
	 */
	private function render_desktop_table( array $items, $orderby, $order ) {
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<th class="eem-col-cb"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'equine-event-manager' ); ?>" /></th>
						<?php $this->render_sortable_th( 'title',       __( 'Reservation', 'equine-event-manager' ), $orderby, $order ); ?>
						<?php $this->render_sortable_th( 'event_dates', __( 'Event Dates', 'equine-event-manager' ), $orderby, $order ); ?>
						<th><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
						<?php $this->render_sortable_th( 'orders',      __( 'Orders',     'equine-event-manager' ), $orderby, $order ); ?>
						<th><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="7" class="eem-table-empty"><?php esc_html_e( 'No reservations match the current filters.', 'equine-event-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $post ) : ?>
							<?php $this->render_table_row( $post ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One sortable column header. Renders the column label + a sort-icon
	 * stack; clicking flips order. Active sort column gets is-sorted.
	 *
	 * @param string $key            Sort key ('title' | 'event_dates' | 'orders').
	 * @param string $label
	 * @param string $current_orderby
	 * @param string $current_order
	 * @return void
	 */
	private function render_sortable_th( $key, $label, $current_orderby, $current_order ) {
		$is_active = ( $key === $current_orderby );
		$next_order = ( $is_active && 'asc' === $current_order ) ? 'desc' : 'asc';
		$href = self::url( array(
			'orderby' => $key,
			'order'   => $next_order,
		) );
		$classes = 'sortable' . ( $is_active ? ' is-sorted is-sorted--' . $current_order : '' );
		?>
		<th class="<?php echo esc_attr( $classes ); ?>">
			<a href="<?php echo esc_url( $href ); ?>">
				<?php echo esc_html( $label ); ?>
				<span class="eem-sort-icon" aria-hidden="true"><span></span><span></span></span>
			</a>
		</th>
		<?php
	}

	/**
	 * One desktop table row. Renders all 7 columns including the
	 * conditional Stall Chart icon and the meatballs dropdown.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	private function render_table_row( $post ) {
		$id           = (int) $post->ID;
		$edit_url     = get_edit_post_link( $id );
		$dates        = EEM_Reservations_List_Repo::get_event_date_range_label( $id );
		$badges       = EEM_Reservations_List_Repo::get_type_badges( $id );
		$orders_count = EEM_Reservations_List_Repo::get_orders_count_for_reservation( $id );
		$has_stalls   = in_array( 'stall', $badges, true );
		$status_id    = $this->derive_status_id( $post );
		$status_label = $this->status_label_for( $status_id );
		?>
		<tr data-reservation-id="<?php echo esc_attr( $id ); ?>">
			<td class="eem-col-cb"><input type="checkbox" name="reservation_ids[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'equine-event-manager' ), get_the_title( $post ) ) ); ?>" /></td>
			<td><a class="eem-res-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
			<td><span class="eem-event-dates"><?php echo esc_html( $dates !== '' ? $dates : '—' ); ?></span></td>
			<td><?php $this->render_type_badges( $badges ); ?></td>
			<td><span class="eem-res-status eem-res-status--<?php echo esc_attr( $status_id ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
			<td><span class="eem-orders-count<?php echo $orders_count === 0 ? ' is-zero' : ''; ?>"><?php echo esc_html( number_format_i18n( $orders_count ) ); ?></span></td>
			<td><?php $this->render_row_actions( $id, $has_stalls ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Type badge set inside a row (Stall / RV / Add-On / Group).
	 *
	 * @param string[] $badges
	 * @return void
	 */
	private function render_type_badges( array $badges ) {
		$labels = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		if ( empty( $badges ) ) {
			echo '<span class="eem-type-badges-empty">—</span>';
			return;
		}
		echo '<div class="eem-type-badges">';
		foreach ( $badges as $b ) {
			if ( ! isset( $labels[ $b ] ) ) {
				continue;
			}
			printf(
				'<span class="eem-type-badge eem-type-badge--%1$s">%2$s</span>',
				esc_attr( $b ),
				esc_html( $labels[ $b ] )
			);
		}
		echo '</div>';
	}

	/**
	 * Row actions cell — conditional Stall Chart icon + meatballs menu.
	 * Meatballs items render as buttons whose data-eem-action handlers
	 * land in C4.C; the dropdown toggle reuses the C1.5 generic
	 * dropdown-toggle delegated handler.
	 *
	 * @param int  $reservation_id
	 * @param bool $has_stalls
	 * @return void
	 */
	private function render_row_actions( $reservation_id, $has_stalls ) {
		$stall_chart_url = add_query_arg(
			array(
				'page'           => 'equine-event-manager-stall-chart',
				'reservation_id' => $reservation_id,
			),
			admin_url( 'admin.php' )
		);
		$front_end_url   = get_permalink( $reservation_id );
		$orders_url      = add_query_arg(
			array(
				'page'           => 'equine-event-manager-orders',
				'reservation_id' => $reservation_id,
			),
			admin_url( 'admin.php' )
		);
		$menu_id = 'eem-res-menu-' . $reservation_id;
		?>
		<div class="eem-actions-cell">
			<?php if ( $has_stalls ) : ?>
				<a class="eem-action-icon-btn eem-action-icon-btn--stall-chart" href="<?php echo esc_url( $stall_chart_url ); ?>" title="<?php esc_attr_e( 'Stall Chart', 'equine-event-manager' ); ?>" aria-label="<?php esc_attr_e( 'Stall Chart', 'equine-event-manager' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
				</a>
			<?php endif; ?>
			<div class="eem-row-menu-wrap">
				<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="<?php echo esc_attr( $menu_id ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
				<div class="eem-row-dropdown" id="<?php echo esc_attr( $menu_id ); ?>" role="menu">
					<?php if ( $front_end_url ) : ?>
						<a class="eem-row-dd-item" href="<?php echo esc_url( $front_end_url ); ?>" target="_blank" rel="noopener" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
							<?php esc_html_e( 'View on Front-End', 'equine-event-manager' ); ?>
						</a>
					<?php endif; ?>
					<a class="eem-row-dd-item" href="<?php echo esc_url( $orders_url ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg>
						<?php esc_html_e( 'View Orders', 'equine-event-manager' ); ?>
					</a>
					<button type="button" class="eem-row-dd-item" data-eem-action="reservation-duplicate" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
						<?php esc_html_e( 'Duplicate', 'equine-event-manager' ); ?>
					</button>
					<button type="button" class="eem-row-dd-item" data-eem-action="reservation-export-roster" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export Roster (CSV)', 'equine-event-manager' ); ?>
					</button>
					<button type="button" class="eem-row-dd-item" data-eem-action="reservation-email-customers" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
						<?php esc_html_e( 'Email Customers', 'equine-event-manager' ); ?>
					</button>
					<button type="button" class="eem-row-dd-item eem-row-dd-danger" data-eem-action="reservation-trash" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
						<?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Mobile cards view — rendered alongside the desktop table, hidden
	 * on desktop via CSS and shown at <768px. Each card carries the same
	 * meatballs menu as the desktop row.
	 *
	 * @param WP_Post[] $items
	 * @return void
	 */
	private function render_mobile_cards( array $items ) {
		?>
		<div class="eem-mobile-reservations">
			<?php foreach ( $items as $post ) :
				$id           = (int) $post->ID;
				$edit_url     = get_edit_post_link( $id );
				$dates        = EEM_Reservations_List_Repo::get_event_date_range_label( $id );
				$badges       = EEM_Reservations_List_Repo::get_type_badges( $id );
				$orders_count = EEM_Reservations_List_Repo::get_orders_count_for_reservation( $id );
				$has_stalls   = in_array( 'stall', $badges, true );
				$status_id    = $this->derive_status_id( $post );
				$status_label = $this->status_label_for( $status_id );
				?>
				<div class="eem-mobile-res-card">
					<a class="eem-mob-res-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
					<div class="eem-mob-res-dates"><?php echo esc_html( $dates !== '' ? $dates : '—' ); ?></div>
					<div class="eem-mob-res-bottom">
						<div class="eem-mob-res-badges">
							<?php $this->render_type_badges( $badges ); ?>
							<span class="eem-res-status eem-res-status--<?php echo esc_attr( $status_id ); ?>"><?php echo esc_html( $status_label ); ?></span>
							<span class="eem-orders-count<?php echo $orders_count === 0 ? ' is-zero' : ''; ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: order count (already number_format_i18n'd) */
										_n( '%s order', '%s orders', $orders_count, 'equine-event-manager' ),
										number_format_i18n( $orders_count )
									)
								);
								?>
							</span>
						</div>
						<div class="eem-mob-res-actions">
							<?php $this->render_row_actions( $id, $has_stalls ); ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Table footer with pagination + "showing N–M of T" info. Mockup
	 * lines 468–477.
	 *
	 * @param array{ items: WP_Post[], total: int, total_pages: int, page: int, per_page: int } $page
	 * @return void
	 */
	private function render_table_footer( array $page ) {
		$total       = (int) $page['total'];
		$current     = (int) $page['page'];
		$total_pages = max( 1, (int) $page['total_pages'] );
		$per_page    = (int) $page['per_page'];

		$start = $total > 0 ? ( ( $current - 1 ) * $per_page ) + 1 : 0;
		$end   = min( $start + $per_page - 1, $total );

		$prev_url = $current > 1 ? self::url( array( 'paged' => $current - 1 ) ) : '';
		$next_url = $current < $total_pages ? self::url( array( 'paged' => $current + 1 ) ) : '';
		?>
		<div class="eem-table-footer">
			<span class="eem-table-footer-info">
				<?php
				if ( $total > 0 ) {
					echo esc_html( sprintf(
						/* translators: 1: first item, 2: last item, 3: total */
						__( 'Showing %1$s–%2$s of %3$s reservations', 'equine-event-manager' ),
						number_format_i18n( $start ),
						number_format_i18n( $end ),
						number_format_i18n( $total )
					) );
				} else {
					esc_html_e( 'No reservations to display.', 'equine-event-manager' );
				}
				?>
			</span>
			<?php if ( $total_pages > 1 ) : ?>
				<nav class="eem-pagination" aria-label="<?php esc_attr_e( 'Reservations pagination', 'equine-event-manager' ); ?>">
					<?php if ( $prev_url ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( $prev_url ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'equine-event-manager' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
						</a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></span>
					<?php endif; ?>

					<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
						<?php if ( $i === $current ) : ?>
							<span class="eem-page-btn active" aria-current="page"><?php echo esc_html( number_format_i18n( $i ) ); ?></span>
						<?php else : ?>
							<a class="eem-page-btn" href="<?php echo esc_url( self::url( array( 'paged' => $i ) ) ); ?>"><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>

					<?php if ( $next_url ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( $next_url ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'equine-event-manager' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
						</a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></span>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Map a WP post_status to our lifecycle id (active/draft/archived/trashed).
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	private function derive_status_id( $post ) {
		switch ( $post->post_status ) {
			case 'publish': return 'active';
			case 'draft':   return 'draft';
			case 'private': return 'archived';
			case 'trash':   return 'trashed';
			default:        return 'draft';
		}
	}

	/**
	 * Friendly UI label for a lifecycle id.
	 *
	 * @param string $status_id
	 * @return string
	 */
	private function status_label_for( $status_id ) {
		switch ( $status_id ) {
			case 'active':   return __( 'Active',   'equine-event-manager' );
			case 'draft':    return __( 'Draft',    'equine-event-manager' );
			case 'archived': return __( 'Archived', 'equine-event-manager' );
			case 'trashed':  return __( 'Trashed',  'equine-event-manager' );
			default:         return __( 'Unknown',  'equine-event-manager' );
		}
	}

	/**
	 * Redirect requests for the old WP-native CPT list at
	 * `edit.php?post_type=en_reservation` to the new custom page. Wired
	 * to `current_screen` so the redirect fires before WP renders the
	 * legacy list-table chrome.
	 *
	 * Direct hits on a single en_reservation edit screen pass through
	 * untouched — only the list view is intercepted.
	 *
	 * @param WP_Screen $screen
	 * @return void
	 */
	public static function maybe_redirect_old_list( $screen ) {
		if ( ! is_object( $screen ) ) {
			return;
		}
		if ( 'edit-' . EEM_Reservations_List_Repo::POST_TYPE !== $screen->id ) {
			return;
		}
		if ( 'edit' !== $screen->base ) {
			return;
		}

		// Preserve any passthrough query args we want forwarded to the
		// new page — currently just the status tab if WP supplied one.
		$forward = array();
		if ( isset( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tabs   = EEM_Reservations_List_Repo::status_tabs();
			if ( isset( $tabs[ $status ] ) ) {
				$forward['status'] = $status;
			}
		}

		wp_safe_redirect( self::url( $forward ), 302 );
		exit;
	}
}
