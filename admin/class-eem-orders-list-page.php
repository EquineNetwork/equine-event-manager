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
 *   primitive via `wrap=true` on the shell), TWO toolbar rows (each
 *   .eem-list-toolbar primitive: Row 1 event-filter + billing-tabs,
 *   Row 2 bulk-form + type-filter form + search + count), desktop
 *   table, mobile cards (hidden on desktop), pagination footer.
 *
 * Inherited constraints (do not modify in C5):
 *   - Shared C4 primitives — .eem-page-header / .eem-page-title /
 *     .eem-page-subtitle / .eem-page-actions / .eem-list-toolbar /
 *     .eem-toolbar-select / .eem-toolbar-btn / .eem-bulk-form /
 *     .eem-search-form / .eem-search-wrap / .eem-search-input /
 *     .eem-search-btn / .eem-item-count — are READ-ONLY consumed.
 *   - Search-pair visual seam is CLEANUP #13, deferred to C13. C5
 *     does not attempt to fix it.
 *
 * Sub-chunks land here in order:
 *   C5.A          — Scaffold + repo wiring + smoke (this file)
 *   C5.B          — Full render body (toolbar / table / mobile / footer / CSS)
 *   C5.C          — Row actions + Collect button + Print Receipt
 *   C5.D          — Toolbar dispatcher + bulk Refund Selected stub
 *   C5.F-toolbar  — Toolbar restructured to reuse C4 primitives;
 *                   C5-specific .eem-orders-toolbar component class
 *                   dropped (mooted the class-name collision with the
 *                   legacy CSS grid rule), bulk-action-bar bottom
 *                   strip dropped, type multi-select chips replaced
 *                   with single-select Type dropdown.
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
		// Type filter is now a single-select dropdown (C5.F-toolbar). Empty
		// string = "All Types" (no filter). Backward-compat: legacy
		// ?types[]=... params silently dropped — the dropdown is the only
		// way to select a type going forward.
		$type    = isset( $_GET['type'] )    ? sanitize_key( wp_unslash( $_GET['type'] ) )            : '';
		if ( '' !== $type && ! in_array( $type, EEM_Orders_List_Repo::type_filter_keys(), true ) ) {
			$type = '';
		}
		$event   = isset( $_GET['event'] )   ? sanitize_text_field( wp_unslash( $_GET['event'] ) )    : '';
		$search  = isset( $_GET['s'] )       ? sanitize_text_field( wp_unslash( $_GET['s'] ) )        : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) )         : 'date';
		$order   = isset( $_GET['order'] )   ? sanitize_key( wp_unslash( $_GET['order'] ) )           : 'desc';
		$paged   = isset( $_GET['paged'] )   ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) )       : 1;

		$counts = EEM_Orders_List_Repo::counts_by_billing_status();
		$page   = EEM_Orders_List_Repo::get_paginated( array(
			'billing_status' => $billing,
			'type'           => $type,
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
		<?php $this->render_action_notice(); ?>
		<?php $this->render_bulk_refund_modal(); ?>
		<?php /* C5.F-toolbar: toolbar restructured to mirror the C4
		         Reservations pattern — two stacked .eem-list-toolbar rows
		         using shared C1.3 primitives. Row 1 = event filter + billing
		         tabs; Row 2 = bulk-form + type-filter dropdown + search +
		         count. The pre-restructure C5-specific .eem-orders-toolbar
		         class + .eem-bulk-action-bar bottom strip + chip multi-
		         select are gone; their class-name collision with the legacy
		         CSS (admin-legacy.css .eem-orders-toolbar { display: grid })
		         is mooted by the rewrite. */ ?>
		<?php $this->render_toolbar( $billing, $type, $event, $search, $page['total'] ); ?>
		<?php $this->render_desktop_table( $page['items'], $orderby, $order, $billing ); ?>
		<?php $this->render_mobile_cards( $page['items'] ); ?>
		<?php $this->render_table_footer( $page, $billing, $type, $event, $search ); ?>
		<?php

		eem_render_page_close( array( 'wrap' => true ) );
	}

	/**
	 * Inline notice rendered after admin_post handlers redirect back
	 * with ?eem_notice=… on the URL. Matches the pattern C4 uses on
	 * the Reservations list page so the visual treatment is identical.
	 *
	 * @return void
	 */
	private function render_action_notice() {
		$code = isset( $_GET['eem_notice'] ) ? sanitize_key( wp_unslash( $_GET['eem_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$messages = array(
			'notification_resent'      => array( 'type' => 'success', 'text' => __( 'Customer notification email resent.', 'equine-event-manager' ) ),
			'notification_no_email'    => array( 'type' => 'warning', 'text' => __( 'Cannot resend — this order has no customer email on file.', 'equine-event-manager' ) ),
			'notification_failed'      => array( 'type' => 'error',   'text' => __( 'Could not resend the notification email. Check the WordPress error log.', 'equine-event-manager' ) ),
			'export_failed'            => array( 'type' => 'error',   'text' => __( 'Could not generate the CSV export. The order may have been deleted.', 'equine-event-manager' ) ),
			'order_trash_deferred'     => array( 'type' => 'warning', 'text' => __( 'Move to Trash for orders is not yet wired — the soft-delete schema lands in a future chunk. No changes were made.', 'equine-event-manager' ) ),
			'print_receipt_deferred'   => array( 'type' => 'info',    'text' => __( 'Receipt print view lands with the Order Detail page in C6. No action taken.', 'equine-event-manager' ) ),
			'bulk_refund_deferred'     => array( 'type' => 'info',    'text' => $this->bulk_refund_deferred_text() ),
			'bulk_no_selection'        => array( 'type' => 'warning', 'text' => __( 'Pick at least one order before clicking Apply.', 'equine-event-manager' ) ),
			'bulk_no_action'           => array( 'type' => 'warning', 'text' => __( 'Pick a bulk action before clicking Apply.', 'equine-event-manager' ) ),
			'denied'                   => array( 'type' => 'error',   'text' => __( 'You do not have permission to perform that action.', 'equine-event-manager' ) ),
			'notfound'                 => array( 'type' => 'error',   'text' => __( 'Order not found.', 'equine-event-manager' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		$m = $messages[ $code ];
		printf(
			'<div class="notice notice-%1$s is-dismissible" style="margin-bottom:12px;"><p>%2$s</p></div>',
			esc_attr( $m['type'] ),
			esc_html( $m['text'] )
		);
	}

	/**
	 * Toolbar — two stacked rows using shared C4 .eem-list-toolbar
	 * primitives (C5.F-toolbar restructure replaces the C5.B-D custom
	 * .eem-orders-toolbar / .eem-bulk-action-bar markup).
	 *
	 *   Row 1: Event filter <select> (LEFT) + Payment-status tabs (RIGHT)
	 *   Row 2: Bulk-actions form + Type-filter form (LEFT) +
	 *          Search form + Order count (RIGHT)
	 *
	 * Row-1 event filter is its own GET form that auto-submits on
	 * change (no Filter button — single-control row). Row-2 mirrors
	 * the C4 Reservations row exactly: bulk-form (Apply opens the
	 * Bulk Refund modal via JS — modal POSTs to admin-post separately),
	 * type-filter form (single-select dropdown + Filter button), and
	 * search form (input + Search Orders button). Each form preserves
	 * other filter state via its own hidden inputs — no
	 * preserve_filters() helper threading required.
	 *
	 * @param string $billing Active billing-status tab id.
	 * @param string $type    Active type-filter slug ('' = All Types).
	 * @param string $event   Active event-filter label ('' = All events).
	 * @param string $search  Active search term.
	 * @param int    $total   Total orders matching current filter (drives the count).
	 * @return void
	 */
	private function render_toolbar( $billing, $type, $event, $search, $total ) {
		$tabs       = EEM_Orders_List_Repo::billing_tabs();
		$type_keys  = EEM_Orders_List_Repo::type_filter_keys();
		$event_opts = EEM_Orders_List_Repo::get_event_filter_options();
		$type_labels = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		?>
		<div class="eem-list-toolbar" data-eem-orders-list>
			<div class="eem-list-toolbar-left">
				<form method="get" class="eem-orders-event-filter-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="billing" value="<?php echo esc_attr( $billing ); ?>" />
					<?php if ( '' !== $type )   : ?><input type="hidden" name="type"  value="<?php echo esc_attr( $type ); ?>" /><?php endif; ?>
					<?php if ( '' !== $search ) : ?><input type="hidden" name="s"     value="<?php echo esc_attr( $search ); ?>" /><?php endif; ?>
					<select class="eem-toolbar-select" name="event" data-eem-orders-event-select onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All events', 'equine-event-manager' ); ?></option>
						<?php foreach ( $event_opts as $label => $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $event, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			<div class="eem-list-toolbar-right">
				<div class="eem-filter-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Billing status', 'equine-event-manager' ); ?>">
					<?php foreach ( $tabs as $tab_id => $tab_label ) :
						$is_active = ( $tab_id === $billing );
						// Tabs preserve event + type + s so switching tab keeps the
						// other filters in place; resets paged. Matches the spirit
						// of C4's status-tab links (which preserve other URL state
						// via plain href composition).
						$tab_args = array( 'billing' => $tab_id, 'paged' => 1 );
						if ( '' !== $type )   { $tab_args['type']  = $type; }
						if ( '' !== $event )  { $tab_args['event'] = $event; }
						if ( '' !== $search ) { $tab_args['s']     = $search; }
						?>
						<a class="eem-filter-tab<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo esc_url( self::url( $tab_args ) ); ?>" role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"><?php echo esc_html( $tab_label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<div class="eem-list-toolbar">
			<div class="eem-list-toolbar-left">
				<form class="eem-bulk-form" data-eem-orders-bulk-form>
					<?php /* Apply is type="button" — opens the Bulk Refund modal
					         via JS (data-eem-action). The modal carries its own
					         POST form with the nonce + selected order_keys. */ ?>
					<input type="hidden" name="_eem_selected_ids" data-eem-orders-bulk-selected-ids value="" />
					<select class="eem-toolbar-select" name="bulk_action" data-eem-orders-bulk-action>
						<option value=""><?php esc_html_e( 'Bulk actions', 'equine-event-manager' ); ?></option>
						<option value="refund"><?php esc_html_e( 'Refund Selected', 'equine-event-manager' ); ?></option>
					</select>
					<button type="button" class="eem-toolbar-btn" data-eem-action="orders-bulk-apply"><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
				</form>
				<form method="get" class="eem-type-filter-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="billing" value="<?php echo esc_attr( $billing ); ?>" />
					<?php if ( '' !== $event )  : ?><input type="hidden" name="event" value="<?php echo esc_attr( $event ); ?>" /><?php endif; ?>
					<?php if ( '' !== $search ) : ?><input type="hidden" name="s"     value="<?php echo esc_attr( $search ); ?>" /><?php endif; ?>
					<select class="eem-toolbar-select" name="type">
						<option value=""><?php esc_html_e( 'All Types', 'equine-event-manager' ); ?></option>
						<?php foreach ( $type_keys as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $type_labels[ $key ] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="eem-toolbar-btn"><?php esc_html_e( 'Filter', 'equine-event-manager' ); ?></button>
				</form>
			</div>
			<div class="eem-list-toolbar-right">
				<form method="get" class="eem-search-form" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="billing" value="<?php echo esc_attr( $billing ); ?>" />
					<?php if ( '' !== $type )  : ?><input type="hidden" name="type"  value="<?php echo esc_attr( $type ); ?>" /><?php endif; ?>
					<?php if ( '' !== $event ) : ?><input type="hidden" name="event" value="<?php echo esc_attr( $event ); ?>" /><?php endif; ?>
					<div class="eem-search-wrap eem-search-wrap--attached">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by customer, order #, event…', 'equine-event-manager' ); ?>" />
					</div>
					<button type="submit" class="eem-toolbar-btn eem-search-btn"><?php esc_html_e( 'Search Orders', 'equine-event-manager' ); ?></button>
				</form>
				<span class="eem-item-count">
					<?php
					echo esc_html( sprintf(
						/* translators: %s: total order count (already number_format_i18n'd) */
						_n( '%s order', '%s orders', $total, 'equine-event-manager' ),
						number_format_i18n( $total )
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
	private function render_desktop_table( array $items, $orderby, $order, $billing = 'all' ) {
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<th class="eem-col-cb"><input type="checkbox" data-eem-action="orders-toggle-all" aria-label="<?php esc_attr_e( 'Select all', 'equine-event-manager' ); ?>" /></th>
						<?php $this->render_sortable_th( 'order_number', __( 'Order', 'equine-event-manager' ), $orderby, $order, $billing ); ?>
						<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Event',    'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Type',     'equine-event-manager' ); ?></th>
						<?php $this->render_sortable_th( 'status', __( 'Status', 'equine-event-manager' ), $orderby, $order, $billing ); ?>
						<?php $this->render_sortable_th( 'date',   __( 'Date',   'equine-event-manager' ), $orderby, $order, $billing ); ?>
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
	private function render_sortable_th( $key, $label, $current_orderby, $current_order, $billing = 'all' ) {
		$is_active  = ( $key === $current_orderby );
		$next_order = ( $is_active && 'asc' === $current_order ) ? 'desc' : 'asc';
		// Sort flip preserves the active billing tab (so flipping sort
		// doesn't drop the tab the user is filtering by) but not the
		// row-2 filters (type/event/search) — matches C4 Reservations'
		// minimal sort URL composition. If the user has those filters
		// applied and sorts, they need to re-apply.
		$href = self::url( array(
			'orderby' => $key,
			'order'   => $next_order,
			'paged'   => 1,
			'billing' => $billing,
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
			<td><span class="eem-order-num"><?php echo esc_html( $this->format_order_number_display( $order_number ) ); ?></span></td>
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
			<td><?php $this->render_row_action_cell( $order, 'desktop' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Action cell — Print Receipt + conditional Collect button + meatballs
	 * with the ORD-3 6-item menu. Shared between desktop rows and mobile
	 * cards so the action surface stays consistent.
	 *
	 * Conditional rules (per ORD-3):
	 *   - Collect button visible only when status is `pending` or
	 *     `invoice_sent` (unpaid + invoice-sent collapse to the same
	 *     billing tab but are distinct statuses).
	 *   - Refund Order menu item hidden when status is `refunded` or
	 *     `cancelled` (nothing left to refund).
	 *
	 * The dropdown items mix plain <a> links (View Order, Edit
	 * Reservation, Refund Order, Collect — all pointing at the
	 * forthcoming Order Detail page in C6) and admin_post button
	 * dispatchers (Resend Notification, Export CSV, Trash, Print
	 * Receipt — wired below).
	 *
	 * @param array<string, mixed> $order
	 * @param string               $context 'desktop' | 'mobile' — used
	 *                                      to compose unique aria-controls
	 *                                      ids so the same dropdown is
	 *                                      reachable from both surfaces.
	 * @return void
	 */
	private function render_row_action_cell( array $order, $context = 'desktop' ) {
		$order_key   = isset( $order['order_key'] )   ? (string) $order['order_key']   : '';
		$status_slug = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : '';
		// C5.G.6: per ORD-3 visible only when status is Unpaid or Invoice
		// Sent. Legacy emits HYPHENATED 'invoice-sent' + 'unpaid' (default
		// fallback) — earlier underscored arms were dead, producing zero
		// Collect buttons on any real row. Defect 12.5 from C5.F audit.
		$can_collect = in_array( $status_slug, array( 'unpaid', 'invoice-sent' ), true );
		$can_refund  = ! in_array( $status_slug, array( 'refunded', 'cancelled' ), true );
		$menu_id     = sprintf( 'eem-order-menu-%s-%s', 'mobile' === $context ? 'mob' : 'desk', $order_key );
		$detail_url  = self::order_detail_url( $order_key );
		$refund_url  = self::order_detail_url( $order_key, array( 'panel' => 'refund' ) );
		$collect_url = self::order_detail_url( $order_key, array( 'panel' => 'collect' ) );
		$reservation_id = $this->lookup_reservation_id_from_order( $order );
		$edit_reservation_url = $reservation_id ? get_edit_post_link( $reservation_id ) : '';
		?>
		<div class="eem-actions-cell">
			<?php if ( $can_collect ) : ?>
				<a class="eem-btn-collect" href="<?php echo esc_url( $collect_url ); ?>" data-order-key="<?php echo esc_attr( $order_key ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
					<?php esc_html_e( 'Collect', 'equine-event-manager' ); ?>
				</a>
			<?php endif; ?>
			<button type="button" class="eem-action-icon-btn" data-eem-action="order-print-receipt" data-order-key="<?php echo esc_attr( $order_key ); ?>" title="<?php esc_attr_e( 'Print Receipt', 'equine-event-manager' ); ?>" aria-label="<?php esc_attr_e( 'Print Receipt', 'equine-event-manager' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
			</button>
			<div class="eem-row-menu-wrap">
				<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="<?php echo esc_attr( $menu_id ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
				<div class="eem-row-dropdown" id="<?php echo esc_attr( $menu_id ); ?>" role="menu">
					<a class="eem-row-dd-item" href="<?php echo esc_url( $detail_url ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
						<?php esc_html_e( 'View Order', 'equine-event-manager' ); ?>
					</a>
					<?php if ( $edit_reservation_url ) : ?>
						<a class="eem-row-dd-item" href="<?php echo esc_url( $edit_reservation_url ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
							<?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?>
						</a>
					<?php endif; ?>
					<button type="button" class="eem-row-dd-item" data-eem-action="order-resend-notification" data-order-key="<?php echo esc_attr( $order_key ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
						<?php esc_html_e( 'Resend Notification', 'equine-event-manager' ); ?>
					</button>
					<button type="button" class="eem-row-dd-item" data-eem-action="order-export-csv" data-order-key="<?php echo esc_attr( $order_key ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export CSV', 'equine-event-manager' ); ?>
					</button>
					<?php if ( $can_refund ) : ?>
						<a class="eem-row-dd-item" href="<?php echo esc_url( $refund_url ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 12 3 6 21 6 21 12"/><polyline points="3 18 3 12 21 12 21 18"/></svg>
							<?php esc_html_e( 'Refund Order', 'equine-event-manager' ); ?>
						</a>
					<?php endif; ?>
					<button type="button" class="eem-row-dd-item eem-row-dd-danger" data-eem-action="order-trash" data-order-key="<?php echo esc_attr( $order_key ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
						<?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?>
					</button>
				</div>
			</div>
		</div>
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
						<span class="eem-mobile-card-id"><?php echo esc_html( $this->format_order_number_display( $order_number ) ); ?></span>
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
						<?php $this->render_row_action_cell( $order, 'mobile' ); ?>
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
	private function render_table_footer( array $page, $billing = 'all', $type = '', $event = '', $search = '' ) {
		$total       = (int) $page['total'];
		$current     = (int) $page['page'];
		$total_pages = max( 1, (int) $page['total_pages'] );
		$per_page    = (int) $page['per_page'];

		$start = $total > 0 ? ( ( $current - 1 ) * $per_page ) + 1 : 0;
		$end   = min( $start + $per_page - 1, $total );

		// Pagination links preserve ALL active filters so paging through
		// a filtered view doesn't drop the filters mid-flow. Sort + tab
		// flips drop other filters per their own minimal-args composition.
		$preserve = array( 'billing' => $billing );
		if ( '' !== $type )   { $preserve['type']  = $type; }
		if ( '' !== $event )  { $preserve['event'] = $event; }
		if ( '' !== $search ) { $preserve['s']     = $search; }

		$prev_url = $current > 1            ? self::url( array_merge( $preserve, array( 'paged' => $current - 1 ) ) ) : '';
		$next_url = $current < $total_pages ? self::url( array_merge( $preserve, array( 'paged' => $current + 1 ) ) ) : '';
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
							<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $preserve, array( 'paged' => $i ) ) ) ); ?>"><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
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
		// C5.G.6: legacy EEM_Orders_Repository::get_order_status_display()
		// emits HYPHENATED slugs ('invoice-sent', 'partially-refunded',
		// 'outstanding-show-bill'). C5.B shipped underscored case arms
		// that silently fell through to the default 'unpaid' branch,
		// painting Invoice Sent + Partially Refunded rows with the wrong
		// (warning-yellow) badge variant — defect 10.2/10.3 from the
		// C5.F audit. Hyphen arms added here for correctness.
		switch ( (string) $status_slug ) {
			case 'paid':                return 'paid';
			case 'partially-refunded':  return 'partial';
			case 'invoice-sent':        return 'invoice';
			case 'refunded':            return 'refunded';
			case 'cancelled':           return 'cancelled';
			case 'unpaid':
			default:                    return 'unpaid';
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

	/**
	 * Render-side order number formatting: strip non-digit characters
	 * from whatever the legacy repo's order_number field holds (e.g.
	 * "C4F-001", "SEED-001", or a bare "0028") and emit as #%05d.
	 * Matches ORD-4's mockup-spec "#NNNN" pattern visually, normalized
	 * to 5-digit zero-padding (mockup line 99 shows #0028; one extra
	 * leading zero accommodates orders past #9999 cleanly).
	 *
	 * Display-side only — does NOT modify the underlying field or
	 * persisted value. Empty / no-digit inputs render as "#00000".
	 *
	 * @param string $order_number  Whatever the legacy repo stored.
	 * @return string  "#NNNNN"
	 */
	private function format_order_number_display( $order_number ) {
		$digits = preg_replace( '/\D/', '', (string) $order_number );
		$n      = '' === $digits ? 0 : (int) $digits;
		return sprintf( '#%05d', $n );
	}

	/**
	 * Build an Order Detail page URL for the given order key. The
	 * detail page itself lands in C6 — for C5.C the link target may
	 * 404 if visited, which is acceptable: View Order / Refund Order /
	 * Collect all converge on this URL and will start working once C6
	 * ships.
	 *
	 * @param string                       $order_key
	 * @param array<string, string|int>    $extra_args  Additional query
	 *                                                  args (e.g. panel=refund).
	 * @return string
	 */
	public static function order_detail_url( $order_key, array $extra_args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page'      => 'equine-event-manager-order',
					'order_key' => $order_key,
				),
				$extra_args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Look up the reservation post id tied to an order. Reads from the
	 * order's notes field which carries "Reservation setup ID: N" per
	 * the legacy insert_reservation_orders pipeline. Returns 0 when
	 * the order isn't tied to a reservation (rare — orphan orders).
	 *
	 * @param array<string, mixed> $order
	 * @return int
	 */
	private function lookup_reservation_id_from_order( array $order ) {
		$notes = '';
		if ( isset( $order['notes'] ) ) {
			$notes = (string) $order['notes'];
		} elseif ( ! empty( $order['components'] ) && is_array( $order['components'] ) ) {
			foreach ( $order['components'] as $component ) {
				if ( ! empty( $component['notes'] ) ) {
					$notes .= "\n" . (string) $component['notes'];
				}
			}
		}
		if ( '' === $notes ) {
			return 0;
		}
		if ( preg_match( '/Reservation setup ID:\s*(\d+)/i', $notes, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Localize the row-action nonces so admin.js can dispatch the
	 * four admin_post handlers without re-fetching nonces. Hooked to
	 * admin_enqueue_scripts; runs only when the eem-admin script is
	 * registered (i.e. on a Phase 3 page).
	 *
	 * @return void
	 */
	public static function localize_row_action_nonces() {
		if ( ! wp_script_is( 'eem-admin', 'registered' ) ) {
			return;
		}
		wp_localize_script( 'eem-admin', 'eemOrderRowActions', array(
			'adminPostUrl' => admin_url( 'admin-post.php' ),
			'nonces'       => array(
				'eem_order_resend_notification' => wp_create_nonce( 'eem_order_resend_notification' ),
				'eem_order_export_csv'          => wp_create_nonce( 'eem_order_export_csv' ),
				'eem_order_trash'               => wp_create_nonce( 'eem_order_trash' ),
				'eem_order_print_receipt'       => wp_create_nonce( 'eem_order_print_receipt' ),
				'eem_orders_bulk_refund'        => wp_create_nonce( 'eem_orders_bulk_refund' ),
			),
		) );
	}

	/**
	 * Compose the bulk_refund_deferred notice text. Inspects
	 * ?eem_bulk_count=N (URL param set by handle_bulk_refund on
	 * redirect) so the message reflects the actual selection size.
	 *
	 * @return string
	 */
	private function bulk_refund_deferred_text() {
		$n = isset( $_GET['eem_bulk_count'] ) ? absint( wp_unslash( $_GET['eem_bulk_count'] ) ) : 0;
		if ( $n > 0 ) {
			return sprintf(
				/* translators: %d: number of orders the admin selected for bulk refund */
				_n(
					'Bulk refund queued for %d order — the async refund engine lands with the Order Detail page in C6. No refunds processed yet.',
					'Bulk refund queued for %d orders — the async refund engine lands with the Order Detail page in C6. No refunds processed yet.',
					$n,
					'equine-event-manager'
				),
				$n
			);
		}
		return __( 'Bulk refund stub — the async refund engine lands in C6. No refunds processed yet.', 'equine-event-manager' );
	}

	/**
	 * Bulk Refund Selected confirmation modal — per REF-3 / ORD-2.
	 *
	 * Opens via data-eem-action="orders-bulk-apply" when the bulk
	 * action select is set to "refund" and at least one row is
	 * checked. The Confirm button submits the modal form to
	 * admin-post.php (action=eem_orders_bulk_refund) with the
	 * selected order_keys[], the reason text, and the notify-customers
	 * flag. Server-side engine deferred to C6 (see CLEANUP.md #15) —
	 * for now the handler just validates and redirects with a
	 * deferred-notice carrying the count.
	 *
	 * @return void
	 */
	private function render_bulk_refund_modal() {
		?>
		<div class="eem-modal" id="eem-orders-bulk-refund-modal" role="dialog" aria-modal="true" aria-labelledby="eem-orders-bulk-refund-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-orders-bulk-refund-title"><?php esc_html_e( 'Refund Selected Orders', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="orders-bulk-refund-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-eem-orders-bulk-refund-form>
					<input type="hidden" name="action" value="eem_orders_bulk_refund" />
					<?php wp_nonce_field( 'eem_orders_bulk_refund', '_eem_bulk_refund_nonce' ); ?>
					<input type="hidden" name="order_keys" value="" data-eem-orders-bulk-refund-keys />
					<p class="eem-orders-bulk-refund-summary" data-eem-orders-bulk-refund-summary>
						<?php esc_html_e( 'Recipients will load when the modal opens.', 'equine-event-manager' ); ?>
					</p>
					<div class="eem-field-row" style="margin-top:14px;">
						<label class="eem-field-label" for="eem-orders-bulk-refund-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<textarea class="eem-field-textarea" id="eem-orders-bulk-refund-reason" name="reason" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Event cancelled due to weather', 'equine-event-manager' ); ?>"></textarea>
							<p class="eem-field-hint"><?php esc_html_e( 'Stored on each refund record. Surfaced in the activity log; not sent to customers by default.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-orders-bulk-refund-notify"><?php esc_html_e( 'Notify customers', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<label><input type="checkbox" id="eem-orders-bulk-refund-notify" name="notify" value="1" checked /> <?php esc_html_e( 'Send the "Event Cancelled — Refund Processed" email to each customer.', 'equine-event-manager' ); ?></label>
						</div>
					</div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="orders-bulk-refund-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="orders-bulk-refund-confirm"><?php esc_html_e( 'Confirm refund', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Shared admin_post entry guard — verifies capability + nonce +
	 * order_key presence. Returns the order array on success; on
	 * failure, redirects with the appropriate notice and exits.
	 *
	 * @param string $action  The nonce action.
	 * @return array<string, mixed>
	 */
	private static function check_order_action_request( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_notice( 'denied' );
		}
		check_admin_referer( $action, '_eem_action_nonce' );
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		if ( '' === $order_key ) {
			self::redirect_with_notice( 'notfound' );
		}
		$repo  = new EEM_Orders_Repository();
		$order = $repo->get_order( $order_key );
		if ( ! is_array( $order ) ) {
			self::redirect_with_notice( 'notfound' );
		}
		return $order;
	}

	/**
	 * Redirect back to the Orders list with a ?eem_notice=… code.
	 *
	 * @param string $code
	 * @param array<string, string|int> $extra
	 * @return void
	 */
	private static function redirect_with_notice( $code, array $extra = array() ) {
		wp_safe_redirect( self::url( array_merge( array( 'eem_notice' => $code ), $extra ) ) );
		exit;
	}

	/**
	 * Resend the customer order-notification email for a single order.
	 * Delegates to EEM_Shortcodes::send_customer_notification_email_for_order
	 * which is the canonical sender (same one used on initial checkout
	 * + post-payment webhook receipt flow).
	 *
	 * @return void
	 */
	public static function handle_resend_notification() {
		$order = self::check_order_action_request( 'eem_order_resend_notification' );
		if ( ! class_exists( 'EEM_Shortcodes' ) || ! method_exists( 'EEM_Shortcodes', 'send_customer_notification_email_for_order' ) ) {
			self::redirect_with_notice( 'notification_failed' );
		}
		$shortcodes = new EEM_Shortcodes();
		$result = $shortcodes->send_customer_notification_email_for_order( $order );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'customer_notification_missing_email' === $code ) {
				self::redirect_with_notice( 'notification_no_email' );
			}
			self::redirect_with_notice( 'notification_failed' );
		}
		self::redirect_with_notice( 'notification_resent' );
	}

	/**
	 * Stream a single order's components as a CSV download.
	 *
	 * @return void
	 */
	public static function handle_export_csv() {
		$order = self::check_order_action_request( 'eem_order_export_csv' );
		$order_number = isset( $order['order_number'] ) ? (string) $order['order_number'] : 'order';
		$slug = sanitize_title( $order_number );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="eem-' . $slug . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array(
			__( 'Order #',        'equine-event-manager' ),
			__( 'Customer',       'equine-event-manager' ),
			__( 'Email',          'equine-event-manager' ),
			__( 'Event',          'equine-event-manager' ),
			__( 'Component',      'equine-event-manager' ),
			__( 'Stay Type',      'equine-event-manager' ),
			__( 'Arrival',        'equine-event-manager' ),
			__( 'Departure',      'equine-event-manager' ),
			__( 'Subtotal',       'equine-event-manager' ),
			__( 'Convenience Fee','equine-event-manager' ),
			__( 'Total',          'equine-event-manager' ),
			__( 'Payment Status', 'equine-event-manager' ),
		) );
		$customer = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$email    = isset( $order['email'] )         ? (string) $order['email']         : '';
		$event    = isset( $order['event_name'] )    ? (string) $order['event_name']    : '';
		$components = isset( $order['components'] ) && is_array( $order['components'] ) ? $order['components'] : array();
		if ( empty( $components ) ) {
			fputcsv( $out, array( $order_number, $customer, $email, $event, '', '', '', '', '', '', isset( $order['total'] ) ? (string) $order['total'] : '', isset( $order['payment_status'] ) ? (string) $order['payment_status'] : '' ) );
		} else {
			foreach ( $components as $c ) {
				fputcsv( $out, array(
					$order_number, $customer, $email, $event,
					isset( $c['table'] )          ? (string) $c['table']          : '',
					isset( $c['stay_type'] )      ? (string) $c['stay_type']      : '',
					isset( $c['arrival_date'] )   ? (string) $c['arrival_date']   : '',
					isset( $c['departure_date'] ) ? (string) $c['departure_date'] : '',
					isset( $c['subtotal'] )       ? (string) $c['subtotal']       : '',
					isset( $c['convenience_fee'] )? (string) $c['convenience_fee']: '',
					isset( $c['total'] )          ? (string) $c['total']          : '',
					isset( $c['payment_status'] ) ? (string) $c['payment_status'] : '',
				) );
			}
		}
		fclose( $out );
		exit;
	}

	/**
	 * Move-to-Trash stub. Per ORD-3 this is a WP-standard soft delete
	 * with 30-day recovery, which requires a `trashed_at` column on the
	 * stall/rv tables. Schema migration is out of scope for C5.C and
	 * deferred to a future chunk — for now this handler redirects with
	 * a clear "not yet wired" notice instead of falling back to the
	 * legacy hard-delete (which would surprise users expecting soft
	 * semantics). See CLEANUP.md.
	 *
	 * @return void
	 */
	public static function handle_trash() {
		self::check_order_action_request( 'eem_order_trash' );
		self::redirect_with_notice( 'order_trash_deferred' );
	}

	/**
	 * Print-Receipt stub. The receipt render template lands with the
	 * Order Detail page in C6; until then this handler redirects with
	 * a deferred-notice so the meatballs item visibly does something.
	 *
	 * @return void
	 */
	public static function handle_print_receipt() {
		self::check_order_action_request( 'eem_order_print_receipt' );
		self::redirect_with_notice( 'print_receipt_deferred' );
	}

	/**
	 * Bulk Refund Selected dispatcher (per REF-3 / ORD-2). Validates
	 * the modal POST (cap + nonce + at least one order_key + each key
	 * resolves to an existing order), then redirects with
	 * ?eem_notice=bulk_refund_deferred&eem_bulk_count=N until the
	 * async refund engine ships in C6.
	 *
	 * The handler intentionally does NOT call the merchant API yet —
	 * see CLEANUP.md #15 for the engine scope (queued per-order
	 * processing, activity log entries, customer notifications,
	 * error collection at the end). C5.D wires the dispatcher so the
	 * modal form posts somewhere coherent; C6 fills in the engine.
	 *
	 * @return void
	 */
	public static function handle_bulk_refund() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_notice( 'denied' );
		}
		check_admin_referer( 'eem_orders_bulk_refund', '_eem_bulk_refund_nonce' );

		$keys_raw = isset( $_POST['order_keys'] ) ? wp_unslash( $_POST['order_keys'] ) : '';
		// The modal serializes selected keys as a comma-joined string
		// into a single hidden input (data-eem-orders-bulk-refund-keys)
		// so the JS layer doesn't have to manage multiple inputs.
		$keys = array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $keys_raw ) ) ) );
		if ( empty( $keys ) ) {
			self::redirect_with_notice( 'bulk_no_selection' );
		}

		$repo  = new EEM_Orders_Repository();
		$valid = 0;
		foreach ( $keys as $k ) {
			$o = $repo->get_order( $k );
			if ( is_array( $o ) ) {
				$valid++;
			}
		}
		if ( 0 === $valid ) {
			self::redirect_with_notice( 'notfound' );
		}

		// Engine TODO (C6): per REF-3, queue async per-order refund
		// processing via merchant API, write activity log entries,
		// send notification emails (when notify=1), collect failures
		// for a "Needs Attention" list. See CLEANUP #15.
		self::redirect_with_notice( 'bulk_refund_deferred', array( 'eem_bulk_count' => $valid ) );
	}
}
