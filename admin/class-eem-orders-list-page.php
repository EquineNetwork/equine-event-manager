<?php
/**
 * Orders list page controller (C5 — replaces legacy
 * EEM_Admin::render_orders_page with a mockup-faithful page).
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
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
	 * Planned-but-not-yet-shipped Customer Profile admin page slug.
	 * Registered as a hidden submenu (parent=null) by register_customer_profile_stub()
	 * so customer-name anchors in the Orders list resolve to a graceful
	 * placeholder card instead of WP's "page not found" error. The real
	 * page replaces the stub callback in a future chunk; URL convention
	 * + email key are documented in CLEANUP.md so the future chunk
	 * honours the link target Orders is already wiring.
	 */
	const CUSTOMER_PROFILE_MENU_SLUG = 'equine-event-manager-customer';

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
					esc_url( self::create_order_url() ),
					esc_html__( 'Create Order', 'equine-event-manager' )
				),
				'wrap'       => true,
			)
		);

		?>
		<?php $this->render_action_notice(); ?>
		<?php $this->render_bulk_refund_modal(); ?>
		<?php $this->render_bulk_cancel_modal(); ?>
		<?php $this->render_bulk_send_link_modal(); ?>
		<?php $this->render_bulk_trash_modal(); ?>
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
		<div class="eem-list-card">
			<?php $this->render_desktop_table( $page['items'], $orderby, $order, $billing ); ?>
			<?php $this->render_mobile_cards( $page['items'] ); ?>
			<?php $this->render_table_footer( $page, $billing, $type, $event, $search ); ?>
		</div>
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
			'order_trashed'            => array( 'type' => 'success', 'text' => __( 'Order moved to Trash.', 'equine-event-manager' ) ),
			'order_trash_failed'       => array( 'type' => 'error',   'text' => __( 'Could not move the order to Trash.', 'equine-event-manager' ) ),
			'order_restored'           => array( 'type' => 'success', 'text' => __( 'Order restored.', 'equine-event-manager' ) ),
			'order_restore_failed'     => array( 'type' => 'error',   'text' => __( 'Could not restore the order.', 'equine-event-manager' ) ),
			'order_deleted'            => array( 'type' => 'success', 'text' => __( 'Order permanently deleted.', 'equine-event-manager' ) ),
			'order_delete_failed'      => array( 'type' => 'error',   'text' => __( 'Could not delete the order.', 'equine-event-manager' ) ),
			'print_receipt_deferred'   => array( 'type' => 'info',    'text' => __( 'Receipt print view lands with the Order Detail page in C6. No action taken.', 'equine-event-manager' ) ),
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
					<select class="eem-toolbar-select" name="event" data-eem-orders-event-select data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>" onchange="this.form.submit()">
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
		<div class="eem-list-toolbar eem-toolbar-controls">
			<div class="eem-list-toolbar-left">
				<form class="eem-bulk-form" data-eem-orders-bulk-form>
					<?php /* Apply is type="button" — opens the Bulk Refund modal
					         via JS (data-eem-action). The modal carries its own
					         POST form with the nonce + selected order_keys. */ ?>
					<input type="hidden" name="_eem_selected_ids" data-eem-orders-bulk-selected-ids value="" />
					<select class="eem-toolbar-select" name="bulk_action" data-eem-orders-bulk-action>
						<option value=""><?php esc_html_e( 'Bulk actions', 'equine-event-manager' ); ?></option>
						<option value="refund"><?php esc_html_e( 'Refund Selected', 'equine-event-manager' ); ?></option>
						<option value="cancel"><?php esc_html_e( 'Cancel Selected', 'equine-event-manager' ); ?></option>
						<option value="send_link"><?php esc_html_e( 'Send Payment Link', 'equine-event-manager' ); ?></option>
						<option value="trash"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></option>
					</select>
					<button type="button" class="eem-toolbar-btn" data-eem-action="orders-bulk-apply"><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
				</form>
				<form method="get" class="eem-type-filter-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="billing" value="<?php echo esc_attr( $billing ); ?>" />
					<?php if ( '' !== $event )  : ?><input type="hidden" name="event" value="<?php echo esc_attr( $event ); ?>" /><?php endif; ?>
					<?php if ( '' !== $search ) : ?><input type="hidden" name="s"     value="<?php echo esc_attr( $search ); ?>" /><?php endif; ?>
					<?php // Single-select filter auto-submits on change (no Filter button) — matches the event filter. ?>
					<select class="eem-toolbar-select" name="type" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Types', 'equine-event-manager' ); ?></option>
						<?php foreach ( $type_keys as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $type_labels[ $key ] ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			<div class="eem-list-toolbar-right">
				<form method="get" class="eem-search-form" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="billing" value="<?php echo esc_attr( $billing ); ?>" />
					<?php if ( '' !== $type )  : ?><input type="hidden" name="type"  value="<?php echo esc_attr( $type ); ?>" /><?php endif; ?>
					<?php if ( '' !== $event ) : ?><input type="hidden" name="event" value="<?php echo esc_attr( $event ); ?>" /><?php endif; ?>
					<div class="eem-search-wrap">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" />
					</div>
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
						<?php $this->render_sortable_th( 'total',  __( 'Amount', 'equine-event-manager' ), $orderby, $order, $billing ); ?>
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
		// Plugin-wide convention: admin lists show "Last, First".
		$customer     = EEM_Admin::format_customer_last_first( isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '' );
		$event_name   = $this->derive_event_name( $order );
		$type_keys    = EEM_Orders_List_Repo::derive_type_keys( $order );
		$type_labels  = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
			'entry' => __( 'Entry',  'equine-event-manager' ),
		);
		// Division entries don't fold into the legacy component `type` string,
		// so source the "Entry" badge from the entrants ledger.
		if ( class_exists( 'EEM_Division_Entries' ) && '' !== $order_key && EEM_Division_Entries::order_has_entries( $order_key ) ) {
			$type_keys[] = 'entry';
		}
		$status_slug  = isset( $order['status_slug'] )  ? (string) $order['status_slug']  : '';
		$status_label = isset( $order['status_label'] ) ? (string) $order['status_label'] : '';
		$status_css   = self::status_slug_to_css_class( $status_slug );
		// Mirror the Order Detail badge override: a "paid" order whose total grew
		// after line-item edits has a real uncollected balance, so the list reads
		// amber "Balance Due" instead of green "Paid" — keeping list + detail in sync.
		if ( 'paid' === $status_slug && isset( $order['amount_due'] ) && (float) $order['amount_due'] > 0.005 ) {
			$status_css   = 'unpaid';
			$status_label = __( 'Balance Due', 'equine-event-manager' );
		}
		$created_at   = isset( $order['created_at'] )   ? (string) $order['created_at']   : '';
		$date_label   = self::format_date_label( $created_at );
		$billing_tab  = EEM_Orders_List_Repo::map_status_slug_to_tab( $status_slug );
		$data_types   = implode( ',', $type_keys );
		?>
		<tr data-order-key="<?php echo esc_attr( $order_key ); ?>" data-billing="<?php echo esc_attr( $billing_tab ); ?>" data-types="<?php echo esc_attr( $data_types ); ?>">
			<td class="eem-col-cb"><input type="checkbox" class="eem-orders-row-cb" name="order_keys[]" value="<?php echo esc_attr( $order_key ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select order %s', 'equine-event-manager' ), $order_number ) ); ?>" /></td>
			<td><a class="eem-order-num" href="<?php echo esc_url( self::order_detail_url( $order_key ) ); ?>"><?php echo esc_html( self::format_order_number_display( $order_number ) ); ?></a></td>
			<td><a class="eem-customer-name" href="<?php echo esc_url( self::customer_profile_url( isset( $order['email'] ) ? (string) $order['email'] : '' ) ); ?>"><?php echo esc_html( $customer ); ?></a></td>
			<td><?php
				// C5.G.10: Event name now renders as a link to the reservation's
				// edit screen (when the order traces back to a reservation via
				// the legacy "Reservation setup ID: N" note pattern). Completes
				// the link-affordance pattern across all clickable cell types
				// in the Orders list — order number → order detail (C6 stub),
				// customer name → customer profile (stub), event name →
				// reservation edit screen (existing WP CPT edit URL).
				$event_reservation_id  = $this->lookup_reservation_id_from_order( $order );
				$event_reservation_url = $event_reservation_id ? EEM_Reservation_Editor_Page::url( (int) $event_reservation_id ) : '';
				if ( $event_reservation_url ) :
				?><a class="eem-event-link" href="<?php echo esc_url( $event_reservation_url ); ?>"><?php echo esc_html( $event_name ); ?></a><?php
				else :
				?><span class="eem-event-name"><?php echo esc_html( $event_name ); ?></span><?php
				endif;
			?></td>
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
			<td><span class="eem-amount-val"><?php echo esc_html( isset( $order['total'] ) ? '$' . number_format( (float) $order['total'], 2 ) : '—' ); ?></span></td>
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
		$collect_url = self::collect_payment_url( $order_key );
		$reservation_id = $this->lookup_reservation_id_from_order( $order );
		$edit_reservation_url = $reservation_id ? EEM_Reservation_Editor_Page::url( (int) $reservation_id ) : '';
		?>
		<div class="eem-actions-cell">
			<?php if ( $can_collect ) : ?>
				<a class="eem-btn-collect" href="<?php echo esc_url( $collect_url ); ?>" data-order-key="<?php echo esc_attr( $order_key ); ?>">
					<?php esc_html_e( 'Collect', 'equine-event-manager' ); ?>
				</a>
			<?php endif; ?>
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
					<?php if ( ! empty( $order['trashed'] ) ) : ?>
						<button type="button" class="eem-row-dd-item" data-eem-action="order-restore" data-order-key="<?php echo esc_attr( $order_key ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><polyline points="3 3 3 8 8 8"/></svg>
							<?php esc_html_e( 'Restore', 'equine-event-manager' ); ?>
						</button>
						<button type="button" class="eem-row-dd-item eem-row-dd-danger" data-eem-action="order-delete-permanently" data-order-key="<?php echo esc_attr( $order_key ); ?>" data-order-number="<?php echo esc_attr( $this->format_order_number_display( (string) ( $order['order_number'] ?? '' ) ) ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
							<?php esc_html_e( 'Delete Permanently', 'equine-event-manager' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="eem-row-dd-item eem-row-dd-danger" data-eem-action="order-trash" data-order-key="<?php echo esc_attr( $order_key ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
							<?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?>
						</button>
					<?php endif; ?>
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
				// Plugin-wide convention: admin lists show "Last, First".
		$customer     = EEM_Admin::format_customer_last_first( isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '' );
				$event_name   = $this->derive_event_name( $order );
				$type_keys    = EEM_Orders_List_Repo::derive_type_keys( $order );
				if ( class_exists( 'EEM_Division_Entries' ) && '' !== $order_key && EEM_Division_Entries::order_has_entries( $order_key ) ) {
					$type_keys[] = 'entry';
				}
				$status_slug  = isset( $order['status_slug'] )  ? (string) $order['status_slug']  : '';
				$status_label = isset( $order['status_label'] ) ? (string) $order['status_label'] : '';
				$status_css   = self::status_slug_to_css_class( $status_slug );
				// Mirror the Order Detail badge override (see desktop row): a "paid"
				// order with an uncollected balance reads amber "Balance Due".
				if ( 'paid' === $status_slug && isset( $order['amount_due'] ) && (float) $order['amount_due'] > 0.005 ) {
					$status_css   = 'unpaid';
					$status_label = __( 'Balance Due', 'equine-event-manager' );
				}
				$created_at   = isset( $order['created_at'] )   ? (string) $order['created_at']   : '';
				$date_label   = self::format_date_label( $created_at );
				$type_labels  = array(
					'stall' => __( 'Stall',  'equine-event-manager' ),
					'rv'    => __( 'RV',     'equine-event-manager' ),
					'addon' => __( 'Add-On', 'equine-event-manager' ),
					'group' => __( 'Group',  'equine-event-manager' ),
					'entry' => __( 'Entry',  'equine-event-manager' ),
				);
				?>
				<div class="eem-mobile-card" data-order-key="<?php echo esc_attr( $order_key ); ?>">
					<div class="eem-mobile-card-top">
						<a class="eem-mobile-card-id eem-order-num" href="<?php echo esc_url( self::order_detail_url( $order_key ) ); ?>"><?php echo esc_html( self::format_order_number_display( $order_number ) ); ?></a>
						<span class="eem-mobile-card-meta"><?php echo esc_html( $date_label ); ?></span>
					</div>
					<div class="eem-mobile-card-title"><a class="eem-customer-name" href="<?php echo esc_url( self::customer_profile_url( isset( $order['email'] ) ? (string) $order['email'] : '' ) ); ?>"><?php echo esc_html( $customer ); ?></a></div>
					<?php
					// C5.G.10: mobile parallel of the desktop event-link cell.
					$mob_event_reservation_id  = $this->lookup_reservation_id_from_order( $order );
					$mob_event_reservation_url = $mob_event_reservation_id ? EEM_Reservation_Editor_Page::url( (int) $mob_event_reservation_id ) : '';
					?>
					<div class="eem-mobile-card-sub"><?php if ( $mob_event_reservation_url ) : ?><a class="eem-event-link" href="<?php echo esc_url( $mob_event_reservation_url ); ?>"><?php echo esc_html( $event_name ); ?></a><?php else : ?><?php echo esc_html( $event_name ); ?><?php endif; ?></div>
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
	// C6.A: promoted to public static so EEM_Order_Detail_Page can share the same status→class map.
	public static function status_slug_to_css_class( $status_slug ) {
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
			case 'open':                return 'open';
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
	// C6.A: promoted to public static so EEM_Order_Detail_Page can share the same "May 8, 2026" label format.
	public static function format_date_label( $mysql_datetime ) {
		$ts = '' === $mysql_datetime ? 0 : strtotime( $mysql_datetime );
		return $ts ? date_i18n( __( 'D, M j', 'equine-event-manager' ), $ts ) : '';
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
	// C6.A: promoted to public static so EEM_Order_Detail_Page can share the same "#NNNNN" rendering.
	public static function format_order_number_display( $order_number ) {
		$raw = trim( (string) $order_number );
		// Preserve a leading alpha source-prefix (e.g. "IMP-" on CSV-imported
		// orders) so an order's origin is visible at a glance; only the numeric
		// portion is zero-padded. Plain numeric order numbers render as "#00020".
		if ( preg_match( '/^([A-Za-z]+)-?(\d+)$/', $raw, $m ) ) {
			return sprintf( '%s-%05d', strtoupper( $m[1] ), (int) $m[2] );
		}
		$digits = preg_replace( '/\D/', '', $raw );
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
	 * Build a Customer Profile admin URL keyed by customer email.
	 *
	 * Per C5.G.8 link-affordance polish, customer-name spans in the
	 * Orders list are anchor-rendered to support a future Customer
	 * Profile page. The destination chunk is not yet sequenced into
	 * Phase 3 — until it ships, `EEM_Orders_List_Page::render_customer_profile_stub()`
	 * (registered as a hidden admin submenu) catches hits and shows a
	 * "Customer Profile is on the planned roadmap" placeholder card.
	 * See CLEANUP.md entry "Customer Profile chunk sequencing".
	 *
	 * @param string                       $customer_email
	 * @param array<string, string|int>    $extra_args
	 * @return string
	 */
	public static function customer_profile_url( $customer_email, array $extra_args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page'           => self::CUSTOMER_PROFILE_MENU_SLUG,
					'customer_email' => $customer_email,
				),
				$extra_args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a Create Order admin page URL. Stub in DS-1.A (renders the
	 * canonical mockup with a "Coming in C13" banner); real functional
	 * implementation lands in C13.
	 *
	 * @param array<string, string|int> $extra_args Optional extra query args.
	 * @return string
	 */
	public static function create_order_url( array $extra_args = array() ) {
		return add_query_arg(
			array_merge(
				array( 'page' => 'equine-event-manager-create-order' ),
				$extra_args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a Collect Payment admin page URL for a specific order. Stub
	 * in DS-1.A (renders the canonical mockup with a "Coming in C14"
	 * banner); real functional implementation lands in C14. Per Q6 of
	 * the DS-1.A scope kickoff: routes by `order_key` (shipped
	 * convention), not `order_id` (mockup convention).
	 *
	 * @param string                       $order_key
	 * @param array<string, string|int>    $extra_args
	 * @return string
	 */
	public static function collect_payment_url( $order_key, array $extra_args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page'      => 'equine-event-manager-collect-payment',
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
				'eem_order_restore'             => wp_create_nonce( 'eem_order_restore' ),
				'eem_order_delete_permanently'  => wp_create_nonce( 'eem_order_delete_permanently' ),
				'eem_order_print_receipt'       => wp_create_nonce( 'eem_order_print_receipt' ),
				'eem_bulk_cancel'               => wp_create_nonce( 'eem_bulk_cancel' ),
				'eem_bulk_send_link'            => wp_create_nonce( 'eem_bulk_send_link' ),
				'eem_bulk_trash'               => wp_create_nonce( 'eem_bulk_trash' ),
			),
		) );
	}

	/**
	 * Bulk Refund Selected confirmation modal — per REF-3 / ORD-2.
	 *
	 * Opens via data-eem-action="orders-bulk-apply" when the bulk
	 * action select is set to "refund" and at least one row is
	 * checked. The Confirm button drives a JS queue (startBulkRefundQueue)
	 * that POSTs each selected order_key to the eem_bulk_refund_step AJAX
	 * endpoint sequentially (nonce eem_bulk_refund_step), updating the
	 * per-order progress list and collecting failures for retry.
	 *
	 * @return void
	 */
	/**
	 * Lean bulk-cancel modal (v2). Single-state: reason + notify + a per-order
	 * progress list the JS fills as it runs the sequential cancel queue against
	 * the eem_order_bulk_cancel_step endpoint. Cancelling frees inventory and
	 * emails each customer; it does not refund.
	 *
	 * @return void
	 */
	private function render_bulk_cancel_modal() {
		?>
		<div class="eem-modal eem-bulk-cancel-modal" id="eem-orders-bulk-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="eem-orders-bulk-cancel-title" aria-hidden="true" data-eem-bulk-cancel-modal>
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-orders-bulk-cancel-title"><?php esc_html_e( 'Cancel Selected Orders', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="orders-bulk-cancel-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<div class="eem-modal-body">
					<?php wp_nonce_field( 'eem_bulk_cancel', '_eem_bulk_cancel_nonce' ); ?>
					<p class="eem-order-refund-summary" data-eem-bulk-cancel-summary><?php esc_html_e( 'Cancel the selected orders?', 'equine-event-manager' ); ?></p>
					<p class="eem-field-hint"><?php esc_html_e( 'Each order is cancelled, its stalls / RV lots are freed, and the customer is emailed. This does not refund any payment.', 'equine-event-manager' ); ?></p>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-orders-bulk-cancel-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<textarea class="eem-field-textarea" id="eem-orders-bulk-cancel-reason" name="reason" rows="2" maxlength="500" data-eem-bulk-cancel-reason></textarea>
					</div>

					<div class="eem-field-row eem-order-refund-notify-row">
						<label class="eem-order-refund-notify">
							<input type="checkbox" data-eem-bulk-cancel-notify value="1" checked />
							<?php esc_html_e( 'Email each customer a cancellation notice', 'equine-event-manager' ); ?>
						</label>
					</div>

					<ul class="eem-bulk-cancel-progress" data-eem-bulk-cancel-progress hidden></ul>
					<div class="eem-order-refund-error" data-eem-bulk-cancel-error hidden></div>
				</div>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="orders-bulk-cancel-close"><?php esc_html_e( 'Keep orders', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-danger" data-eem-action="orders-bulk-cancel-confirm" data-eem-bulk-cancel-confirm><?php esc_html_e( 'Cancel orders', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Bulk "Move to Trash" modal — simple one-shot confirmation (no queue, trash
	 * is reversible so no per-order progress needed). JS fires one AJAX request
	 * with all selected keys; on success the page reloads.
	 *
	 * @return void
	 */
	private function render_bulk_trash_modal(): void {
		?>
		<div class="eem-modal" id="eem-orders-bulk-trash-modal" role="dialog" aria-modal="true" aria-labelledby="eem-bulk-trash-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-bulk-trash-title"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="orders-bulk-trash-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
					</button>
				</header>
				<div class="eem-modal-body">
					<p data-eem-bulk-trash-summary class="eem-modal-desc"></p>
					<p class="eem-modal-desc" style="color:var(--color-text-secondary,#666);font-size:13px;"><?php esc_html_e( 'Trashed orders can be restored from the Trash tab.', 'equine-event-manager' ); ?></p>
				</div>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="orders-bulk-trash-close"><?php esc_html_e( 'Keep orders', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-danger" data-eem-action="orders-bulk-trash-confirm" data-eem-bulk-trash-confirm><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Bulk "Send Payment Link" modal (v1 #7). Lean single-state modal that mirrors
	 * the bulk-cancel pattern: a confirm prompt + per-order progress list. The JS
	 * (startBulkSendLinkQueue) POSTs each selected order_key to the
	 * eem_order_bulk_send_link_step endpoint sequentially. Each step emails the
	 * hosted invoice payment link for an unpaid order; paid orders are skipped with
	 * a per-row note rather than failing the batch.
	 *
	 * @return void
	 */
	private function render_bulk_send_link_modal(): void {
		?>
		<div class="eem-modal eem-bulk-send-link-modal" id="eem-orders-bulk-send-link-modal" role="dialog" aria-modal="true" aria-labelledby="eem-orders-bulk-send-link-title" aria-hidden="true" data-eem-bulk-send-link-modal>
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-orders-bulk-send-link-title"><?php esc_html_e( 'Send Payment Link', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="orders-bulk-send-link-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<div class="eem-modal-body">
					<?php wp_nonce_field( 'eem_bulk_send_link', '_eem_bulk_send_link_nonce' ); ?>
					<p class="eem-order-refund-summary" data-eem-bulk-send-link-summary><?php esc_html_e( 'Email a payment link to the selected orders?', 'equine-event-manager' ); ?></p>
					<p class="eem-field-hint"><?php esc_html_e( 'Each unpaid order with a customer email is sent the hosted invoice payment link. Paid orders and orders without an email address are skipped.', 'equine-event-manager' ); ?></p>

					<ul class="eem-bulk-cancel-progress" data-eem-bulk-send-link-progress hidden></ul>
					<div class="eem-order-refund-error" data-eem-bulk-send-link-error hidden></div>
				</div>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="orders-bulk-send-link-close"><?php esc_html_e( 'Close', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-electric" data-eem-action="orders-bulk-send-link-confirm" data-eem-bulk-send-link-confirm><?php esc_html_e( 'Send payment links', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	private function render_bulk_refund_modal() {
		// C6.C: modal now has three render states the JS toggles between:
		//   - intro      (default open state — confirm form, tab-close warning)
		//   - processing (per-order progress list)
		//   - summary    (success/failure recap + retry-failed button)
		// State swap is handled by JS adding/removing .eem-bulk-refund--state-* on
		// the modal-card. Server-side nonce is `eem_bulk_refund_step` — shared
		// across all step calls in the batch (NOT per-order — granted once on
		// modal open).
		?>
		<div class="eem-modal eem-bulk-refund-modal eem-bulk-refund--state-intro" id="eem-orders-bulk-refund-modal" role="dialog" aria-modal="true" aria-labelledby="eem-orders-bulk-refund-title" aria-hidden="true" data-eem-bulk-refund-modal>
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-orders-bulk-refund-title"><?php esc_html_e( 'Refund Selected Orders', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="orders-bulk-refund-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>

				<!-- INTRO STATE — confirm form + tab-close warning -->
				<div class="eem-modal-body eem-bulk-refund-state eem-bulk-refund-state--intro">
					<?php wp_nonce_field( 'eem_bulk_refund_step', '_eem_bulk_refund_nonce' ); ?>
					<input type="hidden" data-eem-bulk-refund-keys value="" />
					<p class="eem-orders-bulk-refund-summary" data-eem-orders-bulk-refund-summary>
						<?php esc_html_e( 'Recipients will load when the modal opens.', 'equine-event-manager' ); ?>
					</p>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-orders-bulk-refund-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<textarea class="eem-field-textarea" id="eem-orders-bulk-refund-reason" data-eem-bulk-refund-reason rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Event cancelled due to weather', 'equine-event-manager' ); ?>"></textarea>
							<p class="eem-field-hint"><?php esc_html_e( 'Stored on each refund record. Surfaced in the activity log; not sent to customers by default.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-orders-bulk-refund-notify"><?php esc_html_e( 'Notify customers', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<label><input type="checkbox" id="eem-orders-bulk-refund-notify" name="notify" value="1" checked /> <?php esc_html_e( 'Send the "Event Cancelled — Refund Processed" email to each customer.', 'equine-event-manager' ); ?></label>
						</div>
					</div>
					<p class="eem-bulk-refund-tab-warning">
						<?php esc_html_e( 'If you close this window, refunds in progress will complete but remaining orders will need to be re-submitted.', 'equine-event-manager' ); ?>
					</p>
				</div>

				<!-- PROCESSING STATE — per-order progress list (populated by JS) -->
				<div class="eem-modal-body eem-bulk-refund-state eem-bulk-refund-state--processing">
					<p class="eem-bulk-refund-processing-headline"><?php esc_html_e( 'Processing refunds…', 'equine-event-manager' ); ?></p>
					<ul class="eem-bulk-refund-progress-list" data-eem-bulk-refund-progress-list></ul>
				</div>

				<!-- SUMMARY STATE — totals + failure list + retry button (populated by JS) -->
				<div class="eem-modal-body eem-bulk-refund-state eem-bulk-refund-state--summary">
					<div class="eem-bulk-refund-summary-totals" data-eem-bulk-refund-summary-totals></div>
					<ul class="eem-bulk-refund-failure-list" data-eem-bulk-refund-failure-list></ul>
				</div>

				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="orders-bulk-refund-close"><?php esc_html_e( 'Close', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="orders-bulk-refund-confirm" data-eem-bulk-refund-primary-btn><?php esc_html_e( 'Confirm refund', 'equine-event-manager' ); ?></button>
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
		$order = self::check_order_action_request( 'eem_order_trash' );
		$repo  = new EEM_Orders_Repository();
		if ( $repo->trash_order( (string) $order['order_key'] ) ) {
			self::redirect_with_notice( 'order_trashed' );
		}
		self::redirect_with_notice( 'order_trash_failed' );
	}

	/**
	 * Restore a trashed order (v1 #9). The order is trashed, so the default
	 * get_order() lookup in check_order_action_request would miss it — verify
	 * cap + nonce + key directly, then restore via the repo (which looks up
	 * across all trash states).
	 *
	 * @return void
	 */
	public static function handle_restore() {
		$key = self::guard_trashed_order_action( 'eem_order_restore' );
		$repo = new EEM_Orders_Repository();
		if ( $repo->restore_order( $key ) ) {
			self::redirect_with_notice( 'order_restored' );
		}
		self::redirect_with_notice( 'order_restore_failed' );
	}

	/**
	 * Permanently delete a trashed order (v1 #9) — hard delete of all component
	 * rows, unrecoverable.
	 *
	 * @return void
	 */
	public static function handle_delete_permanently() {
		$key = self::guard_trashed_order_action( 'eem_order_delete_permanently' );
		$repo = new EEM_Orders_Repository();
		if ( $repo->delete_order( $key ) ) {
			self::redirect_with_notice( 'order_deleted' );
		}
		self::redirect_with_notice( 'order_delete_failed' );
	}

	/**
	 * Cap + nonce + order_key guard for actions on a TRASHED order (restore /
	 * delete-permanently). Unlike {@see check_order_action_request} it does not
	 * require the order to be in the live (non-trashed) list. Returns the
	 * order_key; redirects + exits on failure.
	 *
	 * @param string $action Nonce action.
	 * @return string Sanitized order_key.
	 */
	private static function guard_trashed_order_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_notice( 'denied' );
		}
		check_admin_referer( $action, '_eem_action_nonce' );
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		if ( '' === $order_key ) {
			self::redirect_with_notice( 'notfound' );
		}
		return $order_key;
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
	 * Register the Customer Profile placeholder admin page. Hidden from
	 * the menu (parent=null) so it doesn't pollute the sidebar, but
	 * reachable at admin.php?page=equine-event-manager-customer so the
	 * customer-name anchors emitted by render_table_row() resolve to
	 * a graceful "Coming Soon" card instead of WP's permission-denied
	 * error.
	 *
	 * Wired to admin_menu in includes/class-equine-event-manager.php.
	 *
	 * Despite the legacy "stub" name, this is the LIVE registration for the
	 * Customer Profile route: the callback below binds the real C9 page
	 * (EEM_Customer_Profile_Page::render) when that class is present, and falls
	 * back to render_customer_profile_stub() only as a defensive guard if it
	 * somehow isn't loaded.
	 *
	 * @return void
	 */
	public static function register_customer_profile_stub() {
		add_submenu_page(
			'', // parent=null → hidden from menu but reachable via direct URL
			__( 'Customer Profile', 'equine-event-manager' ),
			__( 'Customer Profile', 'equine-event-manager' ),
			'manage_options',
			self::CUSTOMER_PROFILE_MENU_SLUG,
			// C9 (2.4.x): the read-only Customer Profile page replaced the
			// placeholder stub. EEM_Customer_Profile_Page::render() falls back to
			// a graceful "no orders found" card for unknown emails.
			class_exists( 'EEM_Customer_Profile_Page' )
				? array( 'EEM_Customer_Profile_Page', 'render' )
				: array( __CLASS__, 'render_customer_profile_stub' )
		);
	}

	/**
	 * Placeholder render for the Customer Profile page. Uses the shared
	 * page shell + a simple "coming soon" card. Reads the customer_email
	 * query arg so the placeholder can echo whose profile was requested,
	 * matching the URL convention the Orders list is already wiring.
	 *
	 * @return void
	 */
	public static function render_customer_profile_stub() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
		$email = isset( $_GET['customer_email'] ) ? sanitize_email( wp_unslash( $_GET['customer_email'] ) ) : '';
		eem_render_page_open( array(
			'title'      => __( 'Customer Profile', 'equine-event-manager' ),
			'subtitle'   => '' !== $email
				? sprintf(
					/* translators: %s: customer email */
					__( 'Requested profile: %s', 'equine-event-manager' ),
					$email
				)
				: __( 'Customer Profile page is on the planned roadmap.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders',           'equine-event-manager' ), 'url' => self::url() ),
				array( 'label' => __( 'Customer Profile', 'equine-event-manager' ) ),
			),
			'wrap'       => true,
		) );
		?>
		<div style="padding:32px;text-align:center;color:#50575e;">
			<p style="font-size:14px;margin-bottom:8px;"><?php esc_html_e( 'Customer Profile is a planned roadmap chunk.', 'equine-event-manager' ); ?></p>
			<p style="font-size:13px;color:#8c8f94;">
				<?php esc_html_e( 'Order numbers and customer names in list pages are pre-wired as links so this page will Just Work once the chunk ships. See CLEANUP.md for the URL convention.', 'equine-event-manager' ); ?>
			</p>
			<p style="margin-top:20px;"><a class="eem-btn eem-btn-electric" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a></p>
		</div>
		<?php
		eem_render_page_close( array( 'wrap' => true ) );
	}
}
