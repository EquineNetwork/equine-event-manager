<?php
/**
 * Orders list page controller (C5 — replaces legacy
 * EEM_Admin::render_orders_page with a mockup-faithful page).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that lists customer orders per the mockup at
 * .mockups/orders_page.html.
 *
 * Architecture (per CLAUDE.md layout-shell verification step 4):
 *   Bordered list-card containing five stacked internal sections —
 *   page-header (inside the card per step 4.5; reuses C4 .eem-page-header
 *   primitive via `wrap=true` on the shell), toolbar (#fafafa with two
 *   horizontal rows + a bulk-action row), desktop table, mobile cards
 *   (hidden on desktop), pagination footer (#fafafa).
 *
 * Inherited constraints (do not modify in C5):
 *   - Shared C4 primitives — .eem-page-header / .eem-page-title /
 *     .eem-page-subtitle / .eem-page-actions / .eem-search-input —
 *     are READ-ONLY. New page-unique chrome lives under .eem-orders-*.
 *   - Search-pair visual seam is CLEANUP #13, deferred to C13. C5
 *     does not attempt to fix it.
 *
 * Sub-chunks land here in order:
 *   C5.A — Scaffold + repo wiring + smoke (this file)
 *   C5.B — Full render body (toolbar / table / mobile / footer / CSS)
 *   C5.C — Row actions + Collect button + Print Receipt
 *   C5.D — Toolbar dispatcher + bulk Refund Selected stub
 */
class EEM_Orders_List_Page {

	const MENU_SLUG = 'equine-event-manager-orders';

	/**
	 * Build the admin URL for this page with optional query args.
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
	 * Render the page. Renders the shell with the page-header INSIDE
	 * the bordered card (wrap=true per step 4.5), then toolbar +
	 * desktop table + mobile cards + pagination footer per the mockup
	 * at .mockups/orders_page.html.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// Query state. Filter dispatch + bulk wiring land in C5.D; for
		// C5.B we honour GET params so the table round-trips on its own
		// links (?billing=paid, ?orderby=date, etc.).
		$billing = isset( $_GET['billing'] ) ? sanitize_key( wp_unslash( $_GET['billing'] ) ) : 'all';
		$tabs    = EEM_Orders_List_Repo::billing_tabs();
		if ( ! isset( $tabs[ $billing ] ) ) {
			$billing = 'all';
		}
		$types_raw = isset( $_GET['types'] ) ? (array) wp_unslash( $_GET['types'] ) : EEM_Orders_List_Repo::type_filter_keys();
		$types     = array_values( array_intersect( EEM_Orders_List_Repo::type_filter_keys(), array_map( 'sanitize_key', $types_raw ) ) );
		$event     = isset( $_GET['event'] )   ? sanitize_text_field( wp_unslash( $_GET['event'] ) )   : '';
		$search    = isset( $_GET['s'] )       ? sanitize_text_field( wp_unslash( $_GET['s'] ) )       : '';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) )        : 'date';
		$order     = isset( $_GET['order'] )   ? sanitize_key( wp_unslash( $_GET['order'] ) )          : 'desc';
		$paged     = isset( $_GET['paged'] )   ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) )       : 1;

		$counts = EEM_Orders_List_Repo::counts_by_billing_status();
		$page   = EEM_Orders_List_Repo::get_paginated( array(
			'billing_status' => $billing,
			'types'          => $types,
			'event'          => $event,
			'search'         => $search,
			'orderby'        => $orderby,
			'order'          => $order,
			'paged'          => $paged,
			'per_page'       => 25,
		) );

		eem_render_page_open(
			array(
				'title'      => __( 'Orders', 'equine-event-manager' ),
				'subtitle'   => __( 'View and manage all customer orders. Filter by event or billing status, search orders, and take quick actions.', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Orders', 'equine-event-manager' ) ),
				),
				'actions'    => sprintf(
					'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
					esc_url( admin_url( 'admin.php?page=equine-event-manager-invoicing' ) ),
					esc_html__( 'Create Order', 'equine-event-manager' )
				),
				'wrap'       => true,
			)
		);

		?>
		<div class="eem-list-card eem-orders-list" data-eem-orders-list>
			<?php $this->render_toolbar( $billing, $types, $event, $search, $page['total'] ); ?>
			<?php $this->render_desktop_table( $page['items'], $orderby, $order ); ?>
			<?php $this->render_mobile_cards( $page['items'] ); ?>
			<?php $this->render_table_footer( $page ); ?>
		</div>
		<?php

		eem_render_page_close( array( 'wrap' => true ) );
	}

	/**
	 * Toolbar — three stacked rows per mockup lines 299–339:
	 *   Row 1: event-filter <select> + billing-status segmented tabs
	 *   Row 2: type-chip filter label + 4 type chips + bare search input + live order count
	 *   Row 3: bulk-action <select> + Apply + selected count (reuses .eem-bulk-action-bar primitive)
	 *
	 * Row 3 form POST + nonce wiring lands in C5.D. C5.B emits the
	 * static markup so the visual rendering matches the mockup.
	 *
	 * @param string         $billing
	 * @param array<string>  $types
	 * @param string         $event
	 * @param string         $search
	 * @param int            $total
	 * @return void
	 */
	private function render_toolbar( $billing, array $types, $event, $search, $total ) {
		$tabs        = EEM_Orders_List_Repo::billing_tabs();
		$type_keys   = EEM_Orders_List_Repo::type_filter_keys();
		$event_opts  = EEM_Orders_List_Repo::get_event_filter_options();
		$type_labels = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		?>
		<div class="eem-orders-toolbar">
			<div class="eem-orders-toolbar-row">
				<select class="eem-list-select" name="event" data-eem-orders-event-select>
					<option value=""><?php esc_html_e( 'All events', 'equine-event-manager' ); ?></option>
					<?php foreach ( $event_opts as $label => $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $event, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="eem-filter-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Billing status', 'equine-event-manager' ); ?>">
					<?php foreach ( $tabs as $tab_id => $tab_label ) :
						$is_active = ( $tab_id === $billing );
						$href = self::url( array( 'billing' => $tab_id ) );
						?>
						<a class="eem-filter-tab<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo esc_url( $href ); ?>" role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"><?php echo esc_html( $tab_label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="eem-orders-toolbar-row">
				<span class="eem-orders-type-filter-label"><?php esc_html_e( 'Type:', 'equine-event-manager' ); ?></span>
				<div class="eem-type-chips" role="group" aria-label="<?php esc_attr_e( 'Type filters', 'equine-event-manager' ); ?>">
					<?php foreach ( $type_keys as $key ) :
						$is_active = in_array( $key, $types, true );
						?>
						<button type="button" class="eem-type-chip eem-type-chip--<?php echo esc_attr( $key ); ?><?php echo $is_active ? '' : ' inactive'; ?>" data-eem-action="orders-type-chip" data-type-key="<?php echo esc_attr( $key ); ?>" aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
							<span class="eem-type-chip-dot"></span><?php echo esc_html( $type_labels[ $key ] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="eem-search-wrap">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input class="eem-search-input" type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by customer, order #, event…', 'equine-event-manager' ); ?>" />
				</div>
				<span class="eem-orders-list-count" data-eem-orders-list-count>
					<?php
					echo esc_html( sprintf(
						/* translators: %s: total order count */
						_n( '%s order', '%s orders', $total, 'equine-event-manager' ),
						number_format_i18n( $total )
					) );
					?>
				</span>
			</div>
			<div class="eem-bulk-action-bar">
				<select name="bulk_action" data-eem-orders-bulk-action>
					<option value=""><?php esc_html_e( 'Bulk actions', 'equine-event-manager' ); ?></option>
					<option value="refund"><?php esc_html_e( 'Refund Selected', 'equine-event-manager' ); ?></option>
				</select>
				<button type="button" data-eem-action="orders-bulk-apply"><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
				<span class="eem-bulk-selected-count" data-eem-orders-bulk-count>
					<?php
					echo esc_html( sprintf(
						/* translators: %d: number of selected orders */
						_n( '%d selected', '%d selected', 0, 'equine-event-manager' ),
						0
					) );
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Desktop table — 8 columns per mockup lines 343–353.
	 * Empty state renders inline as a tbody row spanning all columns.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param string $orderby
	 * @param string $order
	 * @return void
	 */
	private function render_desktop_table( array $items, $orderby, $order ) {
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<th class="eem-col-cb"><input type="checkbox" data-eem-action="orders-toggle-all" aria-label="<?php esc_attr_e( 'Select all', 'equine-event-manager' ); ?>" /></th>
						<?php $this->render_sortable_th( 'order_number', __( 'Order', 'equine-event-manager' ), $orderby, $order ); ?>
						<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Event',    'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Type',     'equine-event-manager' ); ?></th>
						<?php $this->render_sortable_th( 'status', __( 'Status', 'equine-event-manager' ), $orderby, $order ); ?>
						<?php $this->render_sortable_th( 'date',   __( 'Date',   'equine-event-manager' ), $orderby, $order ); ?>
						<th><?php esc_html_e( 'Actions',  'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="8" class="eem-table-empty"><?php esc_html_e( 'No orders match the current filters.', 'equine-event-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $order_row ) : ?>
							<?php $this->render_table_row( $order_row ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Sortable column header. Mirrors C4's pattern — clicking flips
	 * order; active column gets is-sorted--asc/desc modifier.
	 *
	 * @param string $key
	 * @param string $label
	 * @param string $current_orderby
	 * @param string $current_order
	 * @return void
	 */
	private function render_sortable_th( $key, $label, $current_orderby, $current_order ) {
		$is_active  = ( $key === $current_orderby );
		$next_order = ( $is_active && 'asc' === $current_order ) ? 'desc' : 'asc';
		$href       = self::url( array(
			'orderby' => $key,
			'order'   => $next_order,
		) );
		$classes    = 'sortable' . ( $is_active ? ' is-sorted is-sorted--' . $current_order : '' );
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
	 * One desktop row — 8 cells per mockup row template (lines 356–
	 * 364). Row-action wiring (Collect button, Print Receipt, meatballs
	 * items) lands in C5.C; C5.B renders the static cell structure so
	 * the smoke can verify markup before handlers exist.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_table_row( array $order ) {
		$order_key    = isset( $order['order_key'] )    ? (string) $order['order_key']    : '';
		$order_number = isset( $order['order_number'] ) ? (string) $order['order_number'] : '';
		$customer     = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$event_name   = $this->derive_event_name( $order );
		$type_keys    = EEM_Orders_List_Repo::derive_type_keys( $order );
		$type_labels  = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		$status_slug  = isset( $order['status_slug'] )  ? (string) $order['status_slug']  : '';
		$status_label = isset( $order['status_label'] ) ? (string) $order['status_label'] : '';
		$status_css   = $this->status_slug_to_css_class( $status_slug );
		$created_at   = isset( $order['created_at'] )   ? (string) $order['created_at']   : '';
		$date_label   = $this->format_date_label( $created_at );
		$billing_tab  = EEM_Orders_List_Repo::map_status_slug_to_tab( $status_slug );
		$data_types   = implode( ',', $type_keys );
		?>
		<tr data-order-key="<?php echo esc_attr( $order_key ); ?>" data-billing="<?php echo esc_attr( $billing_tab ); ?>" data-types="<?php echo esc_attr( $data_types ); ?>">
			<td class="eem-col-cb"><input type="checkbox" class="eem-orders-row-cb" name="order_keys[]" value="<?php echo esc_attr( $order_key ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select order %s', 'equine-event-manager' ), $order_number ) ); ?>" /></td>
			<td><span class="eem-order-num"><?php echo esc_html( $order_number ); ?></span></td>
			<td><span class="eem-customer-name"><?php echo esc_html( $customer ); ?></span></td>
			<td><span class="eem-event-name"><?php echo esc_html( $event_name ); ?></span></td>
			<td>
				<?php if ( empty( $type_keys ) ) : ?>
					<span class="eem-type-badges-empty">—</span>
				<?php else : ?>
					<div class="eem-type-badges">
						<?php foreach ( $type_keys as $key ) : ?>
							<span class="eem-type-badge eem-type-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $type_labels[ $key ] ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</td>
			<td><span class="eem-status-badge eem-status-<?php echo esc_attr( $status_css ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
			<td><span class="eem-date-val"><?php echo esc_html( $date_label ); ?></span></td>
			<td>
				<div class="eem-actions-cell">
					<?php /* C5.C wires Print Receipt + conditional Collect button + meatballs items. */ ?>
					<button type="button" class="eem-action-icon-btn" data-eem-action="order-print-receipt" data-order-key="<?php echo esc_attr( $order_key ); ?>" title="<?php esc_attr_e( 'Print Receipt', 'equine-event-manager' ); ?>" aria-label="<?php esc_attr_e( 'Print Receipt', 'equine-event-manager' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
					</button>
					<div class="eem-row-menu-wrap">
						<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-order-menu-<?php echo esc_attr( $order_key ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
						<div class="eem-row-dropdown" id="eem-order-menu-<?php echo esc_attr( $order_key ); ?>" role="menu">
							<?php /* C5.C fills meatballs items here. */ ?>
						</div>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Mobile cards — alongside desktop table, hidden on desktop via
	 * CSS, shown <768px. Reuses the generic .eem-mobile-card primitives
	 * from C1.3 (which match the orders mockup shape 1:1 — id + date
	 * top row, title=customer, sub=event, bottom badges + actions).
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return void
	 */
	private function render_mobile_cards( array $items ) {
		?>
		<div class="eem-mobile-cards">
			<?php foreach ( $items as $order ) :
				$order_key    = isset( $order['order_key'] )    ? (string) $order['order_key']    : '';
				$order_number = isset( $order['order_number'] ) ? (string) $order['order_number'] : '';
				$customer     = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
				$event_name   = $this->derive_event_name( $order );
				$type_keys    = EEM_Orders_List_Repo::derive_type_keys( $order );
				$status_slug  = isset( $order['status_slug'] )  ? (string) $order['status_slug']  : '';
				$status_label = isset( $order['status_label'] ) ? (string) $order['status_label'] : '';
				$status_css   = $this->status_slug_to_css_class( $status_slug );
				$created_at   = isset( $order['created_at'] )   ? (string) $order['created_at']   : '';
				$date_label   = $this->format_date_label( $created_at );
				$type_labels  = array(
					'stall' => __( 'Stall',  'equine-event-manager' ),
					'rv'    => __( 'RV',     'equine-event-manager' ),
					'addon' => __( 'Add-On', 'equine-event-manager' ),
					'group' => __( 'Group',  'equine-event-manager' ),
				);
				?>
				<div class="eem-mobile-card" data-order-key="<?php echo esc_attr( $order_key ); ?>">
					<div class="eem-mobile-card-top">
						<span class="eem-mobile-card-id"><?php echo esc_html( $order_number ); ?></span>
						<span class="eem-mobile-card-meta"><?php echo esc_html( $date_label ); ?></span>
					</div>
					<div class="eem-mobile-card-title"><?php echo esc_html( $customer ); ?></div>
					<div class="eem-mobile-card-sub"><?php echo esc_html( $event_name ); ?></div>
					<div class="eem-mobile-card-bottom">
						<div class="eem-mobile-card-badges">
							<?php foreach ( $type_keys as $key ) : ?>
								<span class="eem-type-badge eem-type-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $type_labels[ $key ] ); ?></span>
							<?php endforeach; ?>
							<span class="eem-status-badge eem-status-<?php echo esc_attr( $status_css ); ?>"><?php echo esc_html( $status_label ); ?></span>
						</div>
						<div class="eem-mobile-card-actions">
							<button type="button" class="eem-action-icon-btn" data-eem-action="order-print-receipt" data-order-key="<?php echo esc_attr( $order_key ); ?>" aria-label="<?php esc_attr_e( 'Print Receipt', 'equine-event-manager' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
							</button>
							<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-order-menu-mob-<?php echo esc_attr( $order_key ); ?>" aria-label="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Pagination footer — mockup lines 456–465.
	 *
	 * @param array{ items: array<int, array<string, mixed>>, total: int, total_pages: int, page: int, per_page: int } $page
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
						__( 'Showing %1$s–%2$s of %3$s orders', 'equine-event-manager' ),
						number_format_i18n( $start ),
						number_format_i18n( $end ),
						number_format_i18n( $total )
					) );
				} else {
					esc_html_e( 'No orders to display.', 'equine-event-manager' );
				}
				?>
			</span>
			<?php if ( $total_pages > 1 ) : ?>
				<nav class="eem-pagination" aria-label="<?php esc_attr_e( 'Orders pagination', 'equine-event-manager' ); ?>">
					<?php if ( $prev_url ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( $prev_url ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'equine-event-manager' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></a>
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
						<a class="eem-page-btn" href="<?php echo esc_url( $next_url ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'equine-event-manager' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></span>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Best-effort event name for a legacy order row. The legacy repo
	 * stores derived event labels behind a private method, so we read
	 * the customer-visible field that's already exposed on the order
	 * payload. Falls back to the order's `event_id` ↔ event title lookup.
	 *
	 * @param array<string, mixed> $order
	 * @return string
	 */
	private function derive_event_name( array $order ) {
		if ( ! empty( $order['event_name'] ) ) {
			return (string) $order['event_name'];
		}
		if ( ! empty( $order['external_event_label'] ) ) {
			return (string) $order['external_event_label'];
		}
		if ( ! empty( $order['event_id'] ) ) {
			$title = get_the_title( (int) $order['event_id'] );
			if ( '' !== $title ) {
				return $title;
			}
		}
		return '';
	}

	/**
	 * Map a legacy status_slug → the CSS-class suffix used by the
	 * shared .eem-status-* badge variants in admin.css. The CSS
	 * vocabulary (paid / unpaid / partial / invoice / refunded /
	 * cancelled) was shipped pre-emptively in C1.3 and matches the
	 * mockup variant set.
	 *
	 * @param string $status_slug
	 * @return string
	 */
	private function status_slug_to_css_class( $status_slug ) {
		switch ( (string) $status_slug ) {
			case 'paid':               return 'paid';
			case 'partially_refunded': return 'partial';
			case 'invoice_sent':       return 'invoice';
			case 'refunded':           return 'refunded';
			case 'cancelled':          return 'cancelled';
			case 'pending':
			default:                   return 'unpaid';
		}
	}

	/**
	 * Format a created_at MySQL datetime to the mockup-style "May 8, 2026"
	 * label. Empty string for missing/invalid input.
	 *
	 * @param string $mysql_datetime
	 * @return string
	 */
	private function format_date_label( $mysql_datetime ) {
		$ts = '' === $mysql_datetime ? 0 : strtotime( $mysql_datetime );
		return $ts ? date_i18n( __( 'M j, Y', 'equine-event-manager' ), $ts ) : '';
	}
}
