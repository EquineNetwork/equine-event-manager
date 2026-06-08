<?php
/**
 * Order Detail page controller (C6.A — replaces legacy
 * EEM_Admin::render_order_details_page with a mockup-faithful page).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that displays a single order's details per the mockup
 * at .mockups/order_detail_page.html (the post-VIS rewrite).
 *
 * URL convention: admin.php?page=equine-event-manager-order&order_key=...
 * (established by EEM_Orders_List_Page::order_detail_url() in C5.C — the
 * Orders list's `#NNNNN`, "View Order", and "Refund Order" actions all
 * point here).
 *
 * Page architecture (per VIS-3):
 *
 *   eem-page (outer)
 *   ├── breadcrumb
 *   └── eem-page-wrap (single bordered card containing everything)
 *       ├── eem-page-header (title-band INSIDE card)
 *       │   ├── eem-page-header-left (title + status/type badges + meta line)
 *       │   └── eem-page-actions     (Back, Edit Reservation, More dropdown)
 *       └── eem-page-body
 *           ├── eem-order-payment-banner    (conditional — Unpaid/Invoice/Partial)
 *           ├── eem-order-body              (2-col grid: 1fr + 320px)
 *           │   ├── eem-order-main          (left: Stall/RV/Add-On/Group cards)
 *           │   └── eem-order-side          (right: Summary + Payment Details)
 *           ├── eem-order-full-width        (Special Instructions)
 *           └── eem-order-activity          (Activity Log — C6.E will flesh out)
 *
 * Layout-shell verification (per CLAUDE.md):
 *   - Pattern: single bordered card (.eem-page-wrap) with title-band header
 *     inside. Departs from WP-default `wrap`+h1-outside-card convention.
 *   - Outer-structure check 4.5: matches the existing _page_shell.php
 *     wrap=true branch exactly. New `meta` arg added to the shell in C6.A
 *     for the badges row + meta line below the title.
 *
 * Display-only in C6.A. Refund/CSV/Trash handlers + activity-log auto-
 * fire telemetry land in C6.B/C/D/E.
 *
 * @since 2.2.0
 */
class EEM_Order_Detail_Page {

	/**
	 * Menu slug used by the URL convention. Matches the slug Orders list
	 * already wires links to via EEM_Orders_List_Page::order_detail_url().
	 */
	const MENU_SLUG = 'equine-event-manager-order';

	/**
	 * Register Order Detail as a HIDDEN submenu page (parent=null) — same
	 * pattern as EEM_Orders_List_Page::register_customer_profile_stub().
	 * The page is reachable only via direct URL, never sidebar nav.
	 *
	 * Wired to admin_menu in includes/class-equine-event-manager.php.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'',
			__( 'Order Detail', 'equine-event-manager' ),
			__( 'Order Detail', 'equine-event-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_callback' )
		);
	}

	/**
	 * Static menu callback — wraps the instance render so register_page()
	 * can stay a one-liner.
	 *
	 * @return void
	 */
	public static function render_callback() {
		( new self() )->render();
	}

	/**
	 * Build an Order Detail URL.
	 *
	 * @param string                       $order_key  Required order key.
	 * @param array<string, string|int>    $extra_args Optional extra query args.
	 * @return string
	 */
	public static function url( $order_key, array $extra_args = array() ) {
		return EEM_Orders_List_Page::order_detail_url( $order_key, $extra_args );
	}

	/**
	 * Main render entry point. Looks up the order, dispatches to section
	 * renderers. Renders a "not found" card when the order_key is missing
	 * or doesn't resolve.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$order     = '' !== $order_key
			? ( new EEM_Orders_Repository() )->get_order( $order_key )
			: null;

		if ( ! is_array( $order ) ) {
			$this->render_not_found( $order_key );
			return;
		}

		$reservation_id   = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
		$event_fields     = $reservation_id > 0
			? EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id )
			: array( 'title' => '', 'start_date' => '', 'end_date' => '', 'venue' => '' );
		$event_title      = '' !== $event_fields['title']
			? $event_fields['title']
			: ( isset( $order['event_label'] ) ? (string) $order['event_label'] : '' );
		$order_number_str = self::format_order_number( $order );
		$status_slug      = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : 'unpaid';
		$status_css       = EEM_Orders_List_Page::status_slug_to_css_class( $status_slug );
		$status_label     = isset( $order['status_label'] ) ? (string) $order['status_label'] : __( 'Unpaid', 'equine-event-manager' );

		eem_render_page_open( array(
			'title'      => $order_number_str,
			'meta'       => $this->build_header_meta_html( $order, $event_title, $reservation_id, $status_slug, $status_css, $status_label ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders',       'equine-event-manager' ), 'url' => EEM_Orders_List_Page::url() ),
				array( 'label' => $order_number_str ),
			),
			'actions'    => $this->build_header_actions_html( $order, $reservation_id ),
		) );

		$this->render_payment_banner( $order, $status_slug );

		?>
		<div class="eem-order-body">
			<div class="eem-order-main">
				<?php $this->render_stall_card( $order, $event_title );  ?>
				<?php $this->render_rv_card( $order, $event_title );     ?>
				<?php $this->render_addon_card( $order );                ?>
				<?php $this->render_group_card( $order );                ?>
			</div>
			<aside class="eem-order-side">
				<?php $this->render_summary_card( $order );              ?>
				<?php $this->render_payment_details_card( $order );      ?>
			</aside>
		</div>

		<?php $this->render_special_instructions_card( $reservation_id ); ?>

		<?php
		/*
		 * SAVE BAR — DEFERRED to C7 per CLEANUP #33.
		 *
		 * Mockup: .mockups/order_detail_page.html lines 586-592 spec a
		 * Cancel + "Save Changes" pair that lives here, between the
		 * Special Instructions card and the Activity Log section. C6
		 * scope is display-only (refund/CSV/trash live in the header
		 * More menu) — there is no inline-editable field to save yet,
		 * so the bar would dispatch nowhere. C7 wires an inline-edit
		 * save flow and reinstates this region.
		 *
		 * Grep targets for the C7 implementer: "CLEANUP #33" OR
		 * "mockup lines 586-592". Both appear in this single comment
		 * so either search lands here.
		 */
		?>

		<?php $this->render_activity_log( $order, $reservation_id ); ?>

		<?php $this->render_refund_modal( $order ); ?>

		<?php $this->render_cancel_modal( $order ); ?>

		<?php $this->render_remove_discount_modal( $order ); ?>

		<?php
		eem_render_page_close();
	}

	/**
	 * Render a graceful "order not found" card when order_key is invalid.
	 *
	 * @param string $order_key
	 * @return void
	 */
	private function render_not_found( $order_key ) {
		eem_render_page_open( array(
			'title'      => __( 'Order Not Found', 'equine-event-manager' ),
			'subtitle'   => '' !== $order_key
				? sprintf(
					/* translators: %s: order key */
					__( 'No order found for key: %s', 'equine-event-manager' ),
					$order_key
				)
				: __( 'No order key was provided.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders',          'equine-event-manager' ), 'url' => EEM_Orders_List_Page::url() ),
				array( 'label' => __( 'Order Not Found', 'equine-event-manager' ) ),
			),
		) );
		?>
		<div class="eem-order-not-found">
			<p><?php esc_html_e( 'The order you requested could not be found. It may have been deleted, or the URL may be malformed.', 'equine-event-manager' ); ?></p>
			<p><a class="eem-btn eem-btn-electric" href="<?php echo esc_url( EEM_Orders_List_Page::url() ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a></p>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Build the header meta-line HTML (status badge + type badges + date /
	 * event / customer line). Plugged into the shell's `meta` slot so it
	 * renders below the title inside .eem-page-header-left.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $event_title
	 * @param int                  $reservation_id
	 * @param string               $status_slug
	 * @param string               $status_css
	 * @param string               $status_label
	 * @return string  Pre-escaped HTML safe for shell's wp_kses_post() pass.
	 */
	private function build_header_meta_html( array $order, $event_title, $reservation_id, $status_slug, $status_css, $status_label ) {
		$created_at   = isset( $order['created_at'] ) ? (string) $order['created_at'] : '';
		$date_label   = EEM_Orders_List_Page::format_date_label( $created_at );
		$time_label   = '' !== $created_at ? date_i18n( __( 'g:i A', 'equine-event-manager' ), strtotime( $created_at ) ) : '';
		$type_keys    = $this->derive_type_keys( $order );
		$type_labels  = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		// C6.A.2 fix: the order's top-level customer email key is 'email'
		// (not 'customer_email' — see EEM_Orders_Repository::get_order).
		// CLAUDE.md Note 3 captures the silent-degrade lesson.
		$customer_email = isset( $order['email'] ) ? (string) $order['email'] : '';
		$customer_name  = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$reservation_url = $reservation_id > 0 ? EEM_Reservation_Editor_Page::url( (int) $reservation_id ) : '';

		ob_start();
		?>
		<div class="eem-order-meta-badges">
			<span class="eem-status-badge eem-status-<?php echo esc_attr( $status_css ); ?>"><?php echo esc_html( $status_label ); ?></span>
			<?php foreach ( $type_keys as $key ) : ?>
				<?php if ( isset( $type_labels[ $key ] ) ) : ?>
					<span class="eem-type-badge eem-type-badge--<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $type_labels[ $key ] ); ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<div class="eem-order-meta-line">
			<?php if ( '' !== $date_label ) : ?>
				<span><strong><?php echo esc_html( $date_label ); ?></strong><?php
					if ( '' !== $time_label ) {
						/* translators: %s: time-of-day label */
						echo ' ' . esc_html( sprintf( __( 'at %s', 'equine-event-manager' ), $time_label ) );
					}
				?></span>
			<?php endif; ?>
			<?php if ( '' !== $event_title ) : ?>
				<span><?php esc_html_e( 'Event:', 'equine-event-manager' ); ?>
					<?php if ( '' !== $reservation_url ) : ?>
						<a href="<?php echo esc_url( $reservation_url ); ?>"><?php echo esc_html( $event_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $event_title ); ?>
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<?php if ( '' !== $customer_name ) : ?>
				<span><?php esc_html_e( 'Customer:', 'equine-event-manager' ); ?>
					<?php if ( '' !== $customer_email ) : ?>
						<a href="<?php echo esc_url( EEM_Orders_List_Page::customer_profile_url( $customer_email ) ); ?>"><?php echo esc_html( $customer_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $customer_name ); ?>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the right-side action-bar HTML — Back to Orders + Edit
	 * Reservation + More dropdown.
	 *
	 * @param array<string, mixed> $order
	 * @param int                  $reservation_id
	 * @return string  Pre-escaped HTML safe for shell's wp_kses_post() pass.
	 */
	private function build_header_actions_html( array $order, $reservation_id ) {
		$back_url        = EEM_Orders_List_Page::url();
		$reservation_url = $reservation_id > 0 ? EEM_Reservation_Editor_Page::url( (int) $reservation_id ) : '';

		// C12 — hosted receipt links (token-bearer order_key).
		$order_key        = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$receipt_view_url = '' !== $order_key ? add_query_arg( array( 'eem_receipt' => $order_key ), home_url( '/' ) ) : '';
		$receipt_pdf_url  = '' !== $order_key ? add_query_arg( array( 'eem_receipt' => $order_key, 'download' => 'pdf' ), home_url( '/' ) ) : '';

		ob_start();
		?>
		<a class="eem-btn eem-btn-ghost" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a>
		<?php if ( '' !== $reservation_url ) : ?>
			<a class="eem-btn eem-btn-ghost" href="<?php echo esc_url( $reservation_url ); ?>"><?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?></a>
		<?php endif; ?>
		<?php if ( '' !== $receipt_pdf_url ) : ?>
			<a class="eem-btn eem-btn-ghost" href="<?php echo esc_url( $receipt_pdf_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download Receipt', 'equine-event-manager' ); ?></a>
		<?php endif; ?>
		<div class="eem-row-menu-wrap eem-order-detail-more">
			<button type="button" class="eem-btn eem-btn-ghost" data-eem-action="dropdown-toggle" aria-haspopup="true" aria-expanded="false"><?php esc_html_e( 'More', 'equine-event-manager' ); ?> &#9662;</button>
			<div class="eem-row-dropdown">
				<?php if ( '' !== $receipt_view_url ) : ?>
					<a class="eem-row-dd-item" href="<?php echo esc_url( $receipt_view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Receipt', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $receipt_pdf_url ) : ?>
					<a class="eem-row-dd-item" href="<?php echo esc_url( $receipt_pdf_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download PDF Receipt', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
				<a class="eem-row-dd-item" href="#" data-eem-action="order-export-csv-single"><?php esc_html_e( 'Export CSV', 'equine-event-manager' ); ?></a>
				<a class="eem-row-dd-item" href="#" data-eem-action="order-refund-single"><?php esc_html_e( 'Refund Order', 'equine-event-manager' ); ?></a>
				<?php if ( ! isset( $order['status_slug'] ) || 'cancelled' !== $order['status_slug'] ) : ?>
					<a class="eem-row-dd-item eem-row-dd-item--danger" href="#" data-eem-action="order-cancel-single"><?php esc_html_e( 'Cancel Order', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
				<a class="eem-row-dd-item eem-row-dd-item--danger" href="#" data-eem-action="order-trash"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Conditional Payment Outstanding banner. Renders when the order's
	 * status is one of the outstanding-balance states (Unpaid, Invoice
	 * Sent, Partially Paid); silently absent when Paid / Refunded /
	 * Cancelled.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $status_slug
	 * @return void
	 */
	private function render_payment_banner( array $order, $status_slug ) {
		$outstanding_states = array( 'unpaid', 'invoice-sent', 'partially-paid' );
		if ( ! in_array( $status_slug, $outstanding_states, true ) ) {
			return;
		}

		$amount = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$msg    = 'invoice-sent' === $status_slug
			? __( 'Invoice has been sent. Awaiting payment.', 'equine-event-manager' )
			: __( 'No payment received yet for this order.', 'equine-event-manager' );
		?>
		<div class="eem-order-payment-banner" role="status">
			<div class="eem-order-payment-banner__left">
				<div class="eem-order-payment-banner__icon" aria-hidden="true">!</div>
				<div class="eem-order-payment-banner__content">
					<div class="eem-order-payment-banner__title"><?php esc_html_e( 'Payment Outstanding', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment-banner__meta">
						<span class="eem-order-payment-banner__amount"><?php echo esc_html( '$' . number_format_i18n( $amount, 2 ) ); ?></span>
						<?php
						/* translators: %s: human-readable reason */
						echo ' ' . esc_html( sprintf( __( 'has not been collected for this order. %s', 'equine-event-manager' ), $msg ) );
						?>
					</div>
				</div>
			</div>
			<?php
			// DS-1.A: converted from <button data-eem-action="..."> to
			// <a href="..."> per HANDOFF Edit 2. Collect Payment is its
			// own admin page (`equine-event-manager-collect-payment`)
			// reached by URL navigation, not a JS dispatch. Real charge
			// dispatch logic lands in C14.
			$collect_url = EEM_Orders_List_Page::collect_payment_url(
				isset( $order['order_key'] ) ? (string) $order['order_key'] : ''
			);
			?>
			<a class="eem-btn eem-btn-collect-banner" href="<?php echo esc_url( $collect_url ); ?>">
				<?php esc_html_e( 'Collect Payment', 'equine-event-manager' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Stall Reservation card — visible only when the order includes a
	 * stall component.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $event_title
	 * @return void
	 */
	private function render_stall_card( array $order, $event_title ) {
		$qty = isset( $order['stall_quantity'] ) ? (int) $order['stall_quantity'] : 0;
		if ( $qty <= 0 ) {
			return;
		}

		$arrival   = isset( $order['stall_arrival_date'] )   ? (string) $order['stall_arrival_date']   : '';
		$departure = isset( $order['stall_departure_date'] ) ? (string) $order['stall_departure_date'] : '';
		$stay_type = isset( $order['stall_stay_type'] )      ? (string) $order['stall_stay_type']      : '';
		$nights    = $this->compute_nights( $arrival, $departure );
		$required  = isset( $order['required_shavings_qty'] )   ? (int) $order['required_shavings_qty']   : 0;
		$additional = isset( $order['additional_shavings_qty'] ) ? (int) $order['additional_shavings_qty'] : 0;
		$assigned  = $this->extract_assigned_stalls( $order );
		$subtotal  = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></div>
					<?php if ( '' !== $event_title ) : ?>
						<div class="eem-order-card__subtitle"><?php echo esc_html( $event_title ); ?></div>
					<?php endif; ?>
				</div>
				<?php if ( $nights > 0 ) : ?>
					<span class="eem-order-card__nights"><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></span>
				<?php endif; ?>
			</div>
			<table class="eem-detail-table">
				<tr><td><?php esc_html_e( 'Stay Type', 'equine-event-manager' ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $stay_type ) ) ); ?></td></tr>
				<?php if ( $nights > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $arrival ) : ?>
					<tr><td><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $arrival ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $departure ) : ?>
					<tr><td><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $departure ) ); ?></td></tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'Stall Quantity', 'equine-event-manager' ); ?></td><td><?php echo esc_html( (string) $qty ); ?></td></tr>
				<?php if ( $required > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Required Shavings', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d bag', '%d bags', $required, 'equine-event-manager' ), $required ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $additional > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Additional Shavings', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d bag', '%d bags', $additional, 'equine-event-manager' ), $additional ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="eem-detail-table__subtotal"><td><?php esc_html_e( 'Stall Subtotal', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
			</table>
			<div class="eem-stall-assignment">
				<div class="eem-stall-assignment__label"><?php esc_html_e( 'Assigned Stall Units', 'equine-event-manager' ); ?></div>
				<div class="eem-stall-assignment__value">
					<?php if ( '' !== $assigned ) : ?>
						<span class="eem-stall-assignment__badge"><?php echo esc_html( $assigned ); ?></span>
					<?php else : ?>
						<span class="eem-stall-assignment__none"><?php esc_html_e( '— not yet assigned —', 'equine-event-manager' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="eem-stall-assignment__note"><?php esc_html_e( 'Stall assignment editor will be available with Stall & RV Charts (C8).', 'equine-event-manager' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * RV Reservation card — visible only when the order includes an RV
	 * component.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $event_title
	 * @return void
	 */
	private function render_rv_card( array $order, $event_title ) {
		$qty = isset( $order['rv_quantity'] ) ? (int) $order['rv_quantity'] : 0;
		if ( $qty <= 0 ) {
			return;
		}

		$arrival   = isset( $order['rv_arrival_date'] )   ? (string) $order['rv_arrival_date']   : '';
		$departure = isset( $order['rv_departure_date'] ) ? (string) $order['rv_departure_date'] : '';
		$stay_type = isset( $order['rv_stay_type'] )      ? (string) $order['rv_stay_type']      : '';
		$nights    = $this->compute_nights( $arrival, $departure );
		$rv_addons = isset( $order['rv_type'] ) && is_array( $order['rv_type'] ) ? implode( ', ', $order['rv_type'] ) : '';
		$subtotal  = isset( $order['rv_subtotal'] ) ? (float) $order['rv_subtotal'] : 0.0;

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></div>
					<?php if ( '' !== $event_title ) : ?>
						<div class="eem-order-card__subtitle"><?php echo esc_html( $event_title ); ?></div>
					<?php endif; ?>
				</div>
				<?php if ( $nights > 0 ) : ?>
					<span class="eem-order-card__nights"><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></span>
				<?php endif; ?>
			</div>
			<table class="eem-detail-table">
				<tr><td><?php esc_html_e( 'Stay Type', 'equine-event-manager' ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $stay_type ) ) ); ?></td></tr>
				<?php if ( $nights > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></td></tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'RV Quantity', 'equine-event-manager' ); ?></td><td><?php echo esc_html( (string) $qty ); ?></td></tr>
				<?php if ( '' !== $arrival ) : ?>
					<tr><td><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $arrival ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $departure ) : ?>
					<tr><td><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $departure ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $rv_addons ) : ?>
					<tr><td><?php esc_html_e( 'RV Add-Ons', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $rv_addons ); ?></td></tr>
				<?php endif; ?>
				<tr class="eem-detail-table__subtotal"><td><?php esc_html_e( 'RV Subtotal', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Add-Ons card — visible only when the order has add-on type-label
	 * (shavings, general add-ons, etc.).
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_addon_card( array $order ) {
		$type_keys = $this->derive_type_keys( $order );
		if ( ! in_array( 'addon', $type_keys, true ) ) {
			return;
		}

		$required   = isset( $order['required_shavings_qty'] )   ? (int) $order['required_shavings_qty']   : 0;
		$additional = isset( $order['additional_shavings_qty'] ) ? (int) $order['additional_shavings_qty'] : 0;
		$total_qty  = $required + $additional;
		$subtotal   = $this->compute_addon_subtotal( $order );

		// Required shavings are billed WITHIN the Stall Reservation subtotal, and
		// their quantities are already itemized on the Stall card ("Required
		// Shavings: N bags"). The add-on subtotal is a residual (total − stall −
		// rv − fees), so for a stall order whose shavings are folded into the
		// stall subtotal it resolves to $0. Rendering the card in that case
		// produced a misleading "Shavings (×N) $0.00" line. Only render when
		// there's a genuine add-on charge — this mirrors the order-summary
		// sidebar gate (`$addon_subtotal > 0`) so the two stay consistent.
		if ( $subtotal <= 0 ) {
			return;
		}

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></div>
					<div class="eem-order-card__subtitle"><?php esc_html_e( 'Additional products included in this order', 'equine-event-manager' ); ?></div>
				</div>
			</div>
			<table class="eem-detail-table">
				<?php if ( $total_qty > 0 ) : ?>
					<tr><td><?php echo esc_html( sprintf( __( 'Shavings (×%d)', 'equine-event-manager' ), $total_qty ) ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="eem-detail-table__subtotal"><td><?php esc_html_e( 'Add-On Subtotal', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Group Reservation card — visible only when the order has the
	 * 'group' type-label.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_group_card( array $order ) {
		$type_keys = $this->derive_type_keys( $order );
		if ( ! in_array( 'group', $type_keys, true ) ) {
			return;
		}

		// Group rider data lives in the order's notes string. We promoted
		// EEM_Admin::parse_group_rider_count_from_notes /
		// parse_group_rider_names_from_notes from private to public static
		// in C6.A.2 specifically so this renderer can reuse them — single
		// source of truth for the parse format.
		$notes = isset( $order['notes'] ) ? (string) $order['notes'] : '';
		$names = EEM_Admin::parse_group_rider_names_from_notes( $notes );
		$count = EEM_Admin::parse_group_rider_count_from_notes( $notes );
		if ( $count < 1 ) {
			$count = ! empty( $names )
				? count( $names )
				: ( isset( $order['components'] ) && is_array( $order['components'] ) ? count( $order['components'] ) : 1 );
		}

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php esc_html_e( 'Group Reservation', 'equine-event-manager' ); ?></div>
					<div class="eem-order-card__subtitle"><?php esc_html_e( 'Group rider details captured on this reservation', 'equine-event-manager' ); ?></div>
				</div>
			</div>
			<table class="eem-detail-table">
				<tr><td><?php esc_html_e( 'Rider Count', 'equine-event-manager' ); ?></td><td><?php echo esc_html( (string) $count ); ?></td></tr>
			</table>
			<?php if ( ! empty( $names ) ) : ?>
				<div class="eem-rider-list">
					<?php foreach ( $names as $i => $name ) : ?>
						<div class="eem-rider-row">
							<span class="eem-rider-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<span class="eem-rider-name"><?php echo esc_html( $name ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Order Summary sidebar card — section-by-section subtotals + grand
	 * total. Renders only the sections present in the order.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_summary_card( array $order ) {
		$stall_subtotal = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
		$rv_subtotal    = isset( $order['rv_subtotal'] )    ? (float) $order['rv_subtotal']    : 0.0;
		$fees           = isset( $order['fees'] ) ? (float) $order['fees'] : 0.0;
		$total          = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$addon_subtotal = $this->compute_addon_subtotal( $order );

		$stall_nights = $this->compute_nights(
			isset( $order['stall_arrival_date'] )   ? (string) $order['stall_arrival_date']   : '',
			isset( $order['stall_departure_date'] ) ? (string) $order['stall_departure_date'] : ''
		);
		$rv_nights    = $this->compute_nights(
			isset( $order['rv_arrival_date'] )   ? (string) $order['rv_arrival_date']   : '',
			isset( $order['rv_departure_date'] ) ? (string) $order['rv_departure_date'] : ''
		);
		$required     = isset( $order['required_shavings_qty'] )   ? (int) $order['required_shavings_qty']   : 0;
		$additional   = isset( $order['additional_shavings_qty'] ) ? (int) $order['additional_shavings_qty'] : 0;
		$addon_qty    = $required + $additional;

		// C13.C: order-level adjustments (custom line items + discount). These
		// aren't part of the component-row totals, so the grand total is recomputed
		// from $total + custom items − the discount's snapshotted reduction.
		$order_key    = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$adjustments  = ( '' !== $order_key && class_exists( 'EEM_Order_Adjustments_Repo' ) )
			? EEM_Order_Adjustments_Repo::get_for_order( $order_key )
			: array( 'custom_items' => array(), 'discount' => null, 'custom_items_total' => 0.0 );
		$custom_items      = $adjustments['custom_items'];
		$custom_items_total = (float) $adjustments['custom_items_total'];
		$discount          = $adjustments['discount'];
		$discount_amount   = is_array( $discount ) ? (float) $discount['amount'] : 0.0;
		$total             = $total + $custom_items_total - $discount_amount;
		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div class="eem-order-card__title"><?php esc_html_e( 'Order Summary', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-order-summary__body">
				<?php if ( $stall_subtotal > 0 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></span>
							<?php if ( $stall_nights > 0 ) : ?>
								<span class="eem-order-summary__section-badge eem-order-summary__section-badge--stall"><?php echo esc_html( sprintf( _n( '%d night', '%d nights', $stall_nights, 'equine-event-manager' ), $stall_nights ) ); ?></span>
							<?php endif; ?>
						</div>
						<div class="eem-order-summary__line"><span><?php esc_html_e( 'Stall Subtotal', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $stall_subtotal, 2 ) ); ?></span></div>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Section Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $stall_subtotal, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( $rv_subtotal > 0 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></span>
							<?php if ( $rv_nights > 0 ) : ?>
								<span class="eem-order-summary__section-badge eem-order-summary__section-badge--rv"><?php echo esc_html( sprintf( _n( '%d night', '%d nights', $rv_nights, 'equine-event-manager' ), $rv_nights ) ); ?></span>
							<?php endif; ?>
						</div>
						<div class="eem-order-summary__line"><span><?php esc_html_e( 'RV Subtotal', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $rv_subtotal, 2 ) ); ?></span></div>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Section Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $rv_subtotal, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( $addon_subtotal > 0 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></span>
							<?php if ( $addon_qty > 0 ) : ?>
								<span class="eem-order-summary__section-badge eem-order-summary__section-badge--addon"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $addon_qty, 'equine-event-manager' ), $addon_qty ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $addon_qty > 0 ) : ?>
							<div class="eem-order-summary__line"><span><?php echo esc_html( sprintf( __( 'Shavings (×%d)', 'equine-event-manager' ), $addon_qty ) ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $addon_subtotal, 2 ) ); ?></span></div>
						<?php endif; ?>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Section Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $addon_subtotal, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( $fees > 0 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Fees', 'equine-event-manager' ); ?></span>
						</div>
						<div class="eem-order-summary__line"><span><?php esc_html_e( 'Non-Refundable Convenience Fee', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $fees, 2 ) ); ?></span></div>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Section Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $fees, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $custom_items ) ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Custom Line Items', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__section-badge eem-order-summary__section-badge--addon"><?php echo esc_html( sprintf( _n( '%d item', '%d items', count( $custom_items ), 'equine-event-manager' ), count( $custom_items ) ) ); ?></span>
						</div>
						<?php foreach ( $custom_items as $item ) : ?>
							<div class="eem-order-summary__line"><span><?php echo esc_html( $item['description'] ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( (float) $item['amount'], 2 ) ); ?></span></div>
						<?php endforeach; ?>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Section Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $custom_items_total, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( is_array( $discount ) && $discount_amount > 0 ) : ?>
					<div class="eem-order-summary__discount" data-eem-order-discount>
						<div class="eem-order-summary__discount-line">
							<span class="eem-order-summary__discount-label"><?php esc_html_e( 'Discount', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__discount-val"><?php echo esc_html( '−$' . number_format_i18n( $discount_amount, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__discount-reason">
							<span class="eem-order-summary__discount-chip"><?php echo esc_html( $discount['reason'] ); ?></span>
							<button type="button" class="eem-order-summary__discount-remove" data-eem-action="order-remove-discount-open"><?php esc_html_e( 'Remove', 'equine-event-manager' ); ?></button>
						</div>
					</div>
				<?php endif; ?>
				<div class="eem-order-summary__grand-total">
					<span class="eem-order-summary__grand-label"><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></span>
					<span class="eem-order-summary__grand-val"><?php echo esc_html( '$' . number_format_i18n( $total, 2 ) ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Compute the add-on subtotal from order totals (no per-line price data
	 * is currently persisted — shavings are the only add-on). Equals the
	 * residual: total - stall - rv - fees.
	 *
	 * @param array<string, mixed> $order
	 * @return float
	 */
	private function compute_addon_subtotal( array $order ) {
		$total          = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$stall_subtotal = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
		$rv_subtotal    = isset( $order['rv_subtotal'] )    ? (float) $order['rv_subtotal']    : 0.0;
		$fees           = isset( $order['fees'] ) ? (float) $order['fees'] : 0.0;
		return max( 0.0, $total - $stall_subtotal - $rv_subtotal - $fees );
	}

	/**
	 * Payment Details sidebar card — customer block + processor +
	 * transaction details + refund history.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_payment_details_card( array $order ) {
		// NOTE (C6.A.2 fix): the C6.A first-draft used keys 'customer_email'
		// and 'customer_phone' which don't exist on the order shape. The
		// actual top-level keys are 'email' and 'phone' (see
		// EEM_Orders_Repository::get_order). Captured as CLAUDE.md Note 3
		// (missing-key silent-degrade lesson).
		$customer_email = isset( $order['email'] ) ? (string) $order['email'] : '';
		$customer_name  = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$customer_phone = isset( $order['phone'] ) ? (string) $order['phone'] : '';
		$gateway        = isset( $order['payment_gateway'] ) ? (string) $order['payment_gateway'] : '';
		$status_slug    = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : 'unpaid';
		$captured       = in_array( $status_slug, array( 'paid', 'partially-refunded', 'refunded' ), true );

		$first_component = isset( $order['components'][0] ) && is_array( $order['components'][0] ) ? $order['components'][0] : array();
		$transaction_id  = isset( $first_component['transaction_id'] ) ? (string) $first_component['transaction_id'] : '';

		// Card brand/last4 (CLEANUP #34 — now captured by the C14 collect-payment
		// confirm handler into the component notes as "Card Brand"/"Card Last4").
		$card_notes = isset( $first_component['notes'] ) ? (string) $first_component['notes'] : '';
		$card_brand = preg_match( '/Card Brand:\s*(.+)/i', $card_notes, $m ) ? trim( $m[1] ) : '';
		$card_last4 = preg_match( '/Card Last4:\s*(.+)/i', $card_notes, $m ) ? trim( $m[1] ) : '';

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div class="eem-order-card__title"><?php esc_html_e( 'Payment Details', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-order-payment__body">
				<?php if ( '' !== $customer_name ) : ?>
					<div class="eem-order-payment__label"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment__customer-name">
						<?php if ( '' !== $customer_email ) : ?>
							<a href="<?php echo esc_url( EEM_Orders_List_Page::customer_profile_url( $customer_email ) ); ?>"><?php echo esc_html( $customer_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $customer_name ); ?>
						<?php endif; ?>
					</div>
					<div class="eem-order-payment__val">
						<?php if ( '' !== $customer_email ) : ?>
							<a href="mailto:<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></a><br>
						<?php endif; ?>
						<?php if ( '' !== $customer_phone ) : ?>
							<?php echo esc_html( $customer_phone ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $gateway ) : ?>
					<div class="eem-order-payment__label"><?php esc_html_e( 'Processor', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment__val"><?php echo esc_html( $gateway ); ?></div>
				<?php endif; ?>

				<?php if ( '' !== $transaction_id ) : ?>
					<div class="eem-order-payment__label"><?php esc_html_e( 'Transaction ID', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment__val eem-order-payment__mono"><?php echo esc_html( $transaction_id ); ?></div>
				<?php endif; ?>

				<?php if ( '' !== $card_brand || '' !== $card_last4 ) : ?>
					<div class="eem-order-payment__label"><?php esc_html_e( 'Card', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment__val"><?php echo esc_html( trim( ucfirst( $card_brand ) . ( '' !== $card_last4 ? ' •••• ' . $card_last4 : '' ) ) ); ?></div>
				<?php endif; ?>

				<div class="eem-order-payment__label"><?php esc_html_e( 'Captured', 'equine-event-manager' ); ?></div>
				<div class="eem-order-payment__val">
					<?php if ( $captured ) : ?>
						<strong><?php esc_html_e( 'Yes', 'equine-event-manager' ); ?></strong>
					<?php else : ?>
						<strong>&mdash;</strong> <span class="eem-order-payment__hint">(<?php esc_html_e( 'awaiting payment', 'equine-event-manager' ); ?>)</span>
					<?php endif; ?>
				</div>

				<div class="eem-order-payment__label eem-order-payment__label--sep"><?php esc_html_e( 'Refund History', 'equine-event-manager' ); ?></div>
				<div class="eem-order-payment__val eem-order-payment__hint" data-eem-refund-history><?php esc_html_e( 'No refunds processed', 'equine-event-manager' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Special Instructions card — full-width below the 2-col grid.
	 * Read-only in C6 (editor lands in C7). Reads the reservation's
	 * `_en_special_instructions` meta directly.
	 *
	 * @param int $reservation_id
	 * @return void
	 */
	private function render_special_instructions_card( $reservation_id ) {
		// C6.A.2: always render — empty-guard removed so the card is
		// visible even when no instructions are saved (mockup parity:
		// the section is part of the page chrome, not conditional on
		// content). Empty state shows an em-dash placeholder.
		$text = $reservation_id > 0 ? (string) get_post_meta( $reservation_id, '_en_special_instructions', true ) : '';
		$has  = '' !== trim( $text );
		?>
		<div class="eem-order-full-width">
			<div class="eem-card eem-order-card">
				<div class="eem-order-card__header">
					<div class="eem-order-card__title"><?php esc_html_e( 'Special Instructions', 'equine-event-manager' ); ?></div>
				</div>
				<div class="eem-order-instructions__body">
					<?php if ( $has ) : ?>
						<p class="eem-order-instructions__text"><?php echo esc_html( $text ); ?></p>
					<?php else : ?>
						<p class="eem-order-instructions__text eem-order-instructions__text--empty">&mdash;</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Single-order Refund modal (C6.B). Reuses the C5.D `.eem-modal*`
	 * vocabulary (overlay, card, head, body, foot, close button, split
	 * footer) and the C1.2 field primitives (`.eem-field-label`,
	 * `.eem-field-textarea`). Zero new modal-chrome CSS in C6.B — only
	 * the refund-amount field row + summary block are additive.
	 *
	 * Markup is rendered once per page load, populated client-side when
	 * the More menu's "Refund Order" item dispatches `order-refund-single`.
	 * The amount field defaults to the order's outstanding balance; user
	 * can override down for partial refunds. Server-side validation in
	 * EEM_Admin::process_amount_refund() enforces > 0 and <= remaining
	 * refundable balance (which already accounts for prior partial
	 * refunds via get_component_refunded_amount).
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_refund_modal( array $order ) {
		$order_key       = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$customer_name   = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$total           = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$default_amount  = number_format( $total, 2, '.', '' );

		?>
		<div class="eem-modal" id="eem-order-refund-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-refund-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-refund-title"><?php esc_html_e( 'Refund Order', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-refund-single-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-order-refund-form>
					<?php wp_nonce_field( 'eem_refund_single_' . $order_key, '_eem_refund_single_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_refund_single" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />

					<p class="eem-order-refund-summary">
						<?php
						printf(
							/* translators: 1: customer name */
							esc_html__( 'Refund this order for %1$s. The amount will be reversed to the original payment method via the order\'s configured processor.', 'equine-event-manager' ),
							'<strong>' . esc_html( $customer_name ) . '</strong>'
						);
						?>
					</p>

					<div class="eem-field-row eem-order-refund-amount-row">
						<label class="eem-field-label" for="eem-order-refund-amount"><?php esc_html_e( 'Refund amount', 'equine-event-manager' ); ?></label>
						<div class="eem-order-refund-amount-input">
							<span class="eem-order-refund-amount-prefix">$</span>
							<input type="number" id="eem-order-refund-amount" name="amount" step="0.01" min="0.01" value="<?php echo esc_attr( $default_amount ); ?>" required />
						</div>
						<p class="eem-field-hint">
							<?php
							printf(
								/* translators: %s: order total */
								esc_html__( 'Defaults to the full order total ($%s). Lower this for a partial refund.', 'equine-event-manager' ),
								esc_html( number_format( $total, 2 ) )
							);
							?>
						</p>
					</div>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-order-refund-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<textarea class="eem-field-textarea" id="eem-order-refund-reason" name="reason" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Customer disputed shavings quantity', 'equine-event-manager' ); ?>"></textarea>
					</div>

					<div class="eem-field-row eem-order-refund-notify-row">
						<label class="eem-order-refund-notify">
							<input type="checkbox" name="notify" value="1" />
							<?php esc_html_e( 'Email the customer a refund confirmation', 'equine-event-manager' ); ?>
						</label>
					</div>

					<div class="eem-order-refund-error" data-eem-order-refund-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-refund-single-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-refund-single-confirm"><?php esc_html_e( 'Confirm refund', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Cancel-order modal — captures an optional reason and a notify toggle, then
	 * dispatches eem_order_cancel_single. Cancelling is terminal: it frees the
	 * reserved stalls/RV lots and emails the customer, but does NOT refund any
	 * payment (the admin refunds separately if money is owed back).
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_cancel_modal( array $order ) {
		if ( isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'] ) {
			return; // Already cancelled — no modal needed.
		}
		$order_key     = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$customer_name = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		?>
		<div class="eem-modal" id="eem-order-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-cancel-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-cancel-title"><?php esc_html_e( 'Cancel Order', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-cancel-single-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-order-cancel-form>
					<?php wp_nonce_field( 'eem_cancel_single_' . $order_key, '_eem_cancel_single_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_cancel_single" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />

					<p class="eem-order-refund-summary">
						<?php
						printf(
							/* translators: 1: customer name */
							esc_html__( 'Cancel this order for %1$s. The reserved stalls / RV lots are freed for others, and the customer is emailed a cancellation notice. This does NOT refund any payment — refund separately first if money is owed back.', 'equine-event-manager' ),
							'<strong>' . esc_html( $customer_name ) . '</strong>'
						);
						?>
					</p>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-order-cancel-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<textarea class="eem-field-textarea" id="eem-order-cancel-reason" name="reason" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Customer can no longer attend', 'equine-event-manager' ); ?>"></textarea>
					</div>

					<div class="eem-field-row eem-order-refund-notify-row">
						<label class="eem-order-refund-notify">
							<input type="checkbox" name="notify" value="1" checked />
							<?php esc_html_e( 'Email the customer a cancellation notice', 'equine-event-manager' ); ?>
						</label>
					</div>

					<div class="eem-order-refund-error" data-eem-order-cancel-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-cancel-single-close"><?php esc_html_e( 'Keep order', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-delete" data-eem-action="order-cancel-single-confirm"><?php esc_html_e( 'Cancel order', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Remove-discount modal — captures the REQUIRED fresh reason for removing an
	 * applied discount (logged as a new Activity Log entry per the discount
	 * schema). Only rendered when the order actually carries a discount.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_remove_discount_modal( array $order ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			return;
		}
		if ( null === EEM_Order_Adjustments_Repo::get_discount( $order_key ) ) {
			return;
		}
		?>
		<div class="eem-modal" id="eem-order-remove-discount-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-remove-discount-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-remove-discount-title"><?php esc_html_e( 'Remove Discount', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-remove-discount-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-remove-discount-form>
					<?php wp_nonce_field( 'eem_remove_discount_' . $order_key, '_eem_remove_discount_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_remove_discount" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
					<p class="eem-order-refund-summary"><?php esc_html_e( 'Removing the discount restores the order total. A reason is required and recorded in the Activity Log.', 'equine-event-manager' ); ?></p>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-order-remove-discount-reason"><?php esc_html_e( 'Reason', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label>
						<textarea class="eem-field-textarea" id="eem-order-remove-discount-reason" name="reason" rows="3" maxlength="500" required placeholder="<?php esc_attr_e( 'e.g. Discount applied in error', 'equine-event-manager' ); ?>"></textarea>
					</div>
					<div class="eem-order-refund-error" data-eem-remove-discount-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-remove-discount-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-remove-discount-confirm"><?php esc_html_e( 'Remove discount', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX — remove an order's discount with a required fresh reason.
	 *
	 * Capability + nonce gated. Validates the reason is non-empty, removes the
	 * discount via the adjustments repo, and logs an order_discount_removed
	 * Activity Log entry (order_key + reason in payload). Responds with success +
	 * requires_reload so the client refreshes the recomputed total.
	 *
	 * @return void
	 */
	public static function ajax_remove_discount(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_ajax_referer( 'eem_remove_discount_' . $order_key, '_eem_remove_discount_nonce' );

		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( '' === trim( $reason ) ) {
			wp_send_json_error( array(
				'message' => __( 'A reason is required to remove the discount.', 'equine-event-manager' ),
				'code'    => 'reason_required',
			), 422 );
		}

		if ( '' === $order_key || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		$existing = EEM_Order_Adjustments_Repo::get_discount( $order_key );
		if ( null === $existing ) {
			wp_send_json_error( array(
				'message' => __( 'This order has no discount to remove.', 'equine-event-manager' ),
				'code'    => 'no_discount',
			), 404 );
		}

		$removed = EEM_Order_Adjustments_Repo::remove_discount( $order_key );
		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => __( 'The discount could not be removed. Please try again.', 'equine-event-manager' ) ), 500 );
		}

		if ( class_exists( 'EEM_Activity_Log' ) ) {
			EEM_Activity_Log::write(
				'order_discount_removed',
				array(
					'order_key'       => $order_key,
					'discount_type'   => isset( $existing['type'] ) ? (string) $existing['type'] : '',
					'discount_value'  => isset( $existing['value'] ) ? (float) $existing['value'] : 0.0,
					'discount_amount' => isset( $existing['amount'] ) ? (float) $existing['amount'] : 0.0,
					'reason'          => $reason,
				),
				array(
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
				)
			);
		}

		wp_send_json_success( array(
			'requires_reload' => true,
			'message'         => __( 'Discount removed.', 'equine-event-manager' ),
		) );
	}

	/**
	 * Activity Log section — real render (C6.E.1).
	 *
	 * Fetches entries via EEM_Activity_Log::get_for_order_key (added in
	 * C6.E.1 to bridge the order_id=NULL / order_key-in-payload gap from
	 * C6.D telemetry), enriches each entry's payload with a render-ready
	 * title via EEM_Order_Telemetry::enrich_entry_for_render, then hands
	 * off to the shared C2 partial eem_render_activity_log() for the
	 * actual entry HTML.
	 *
	 * Collapsible: default-expanded; toggle chevron flips on click via
	 * the activity-toggle JS dispatch arm. Show-all-capped-at-100 per
	 * the C6.E kickoff decision (pagination deferred — typical order has
	 * <20 entries; revisit if anyone hits the 100 cap).
	 *
	 * C6.E.2 will add the Add Note form below the entries list inside
	 * this same section.
	 *
	 * @param array<string, mixed> $order
	 * @param int                  $reservation_id
	 * @return void
	 */
	private function render_activity_log( array $order, $reservation_id ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$entries   = '' !== $order_key
			? EEM_Activity_Log::get_for_order_key( $order_key, 100 )
			: array();

		// Enrich each entry with a render-ready title so the C2 partial's
		// default-title resolver doesn't fall through on our C6.D event
		// type taxonomy (which it wouldn't recognize otherwise).
		$entries = array_map( array( 'EEM_Order_Telemetry', 'enrich_entry_for_render' ), $entries );

		$count = count( $entries );
		?>
		<div class="eem-order-activity" data-eem-activity-section>
			<div class="eem-order-activity__toggle" data-eem-action="activity-toggle" role="button" tabindex="0" aria-expanded="true">
				<div class="eem-order-activity__toggle-left">
					<span class="eem-order-activity__title"><?php esc_html_e( 'Activity Log', 'equine-event-manager' ); ?></span>
					<span class="eem-order-activity__count" data-eem-activity-count><?php
						echo esc_html( sprintf(
							/* translators: %s: number of activity log entries */
							_n( '%s entry', '%s entries', $count, 'equine-event-manager' ),
							number_format_i18n( $count )
						) );
					?></span>
				</div>
				<span class="eem-order-activity__chevron" aria-hidden="true">▾</span>
			</div>
			<div class="eem-order-activity__list" data-eem-activity-list>
				<?php
				// C2 partial. Entries already enriched; partial reuses
				// the .eem-activity-log-* CSS class taxonomy shipped in C2.4.
				if ( function_exists( 'eem_render_activity_log' ) ) {
					eem_render_activity_log( $entries, array(
						'empty_message' => __( 'No activity recorded yet for this order.', 'equine-event-manager' ),
					) );
				}
				?>
			</div>
			<?php $this->render_add_note_form( $order ); ?>
		</div>
		<?php
	}

	/**
	 * Add-Note form (C6.E.2) — single textarea + Submit button at the
	 * bottom of the Activity Log section. AJAX-submitted via the
	 * `add-note-submit` dispatch arm in admin.js. On success the AJAX
	 * handler returns pre-rendered entry HTML which JS prepends to the
	 * `[data-eem-activity-list]` mount node, bumps the count badge, and
	 * shows a toast confirmation.
	 *
	 * Validation contract — server is authoritative (cap, nonce, trim,
	 * maxlength 2000, order_key exists); client mirrors trim + maxlength
	 * + disable-on-empty/in-flight for UX. HTML maxlength="2000" attr
	 * provides browser-level enforcement on top.
	 *
	 * Decisions locked at C6.E parent kickoff:
	 *   Q1 position — (a) bottom of activity log section
	 *   Q2 fields   — (a) single textarea + Submit
	 *   Q3 valid    — three-layer (HTML maxlength + JS disable + server)
	 *   Q4 attrib   — "Admin note by {actor_label}" w/ "Admin note" fallback
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_add_note_form( array $order ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key ) {
			return;
		}
		?>
		<form class="eem-add-note-form" data-eem-add-note-form data-eem-order-key="<?php echo esc_attr( $order_key ); ?>">
			<?php wp_nonce_field( 'eem_order_add_note_' . $order_key, '_eem_add_note_nonce' ); ?>
			<input type="hidden" name="action" value="eem_order_add_note" />
			<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
			<label class="screen-reader-text" for="eem-add-note-textarea-<?php echo esc_attr( $order_key ); ?>"><?php esc_html_e( 'Add an internal note about this order', 'equine-event-manager' ); ?></label>
			<textarea
				class="eem-add-note-form__textarea"
				id="eem-add-note-textarea-<?php echo esc_attr( $order_key ); ?>"
				name="note"
				rows="3"
				maxlength="2000"
				placeholder="<?php esc_attr_e( 'Add an internal note about this order…', 'equine-event-manager' ); ?>"
				data-eem-add-note-textarea
			></textarea>
			<div class="eem-add-note-form__error" data-eem-add-note-error hidden></div>
			<div class="eem-add-note-form__actions">
				<button
					type="button"
					class="eem-btn eem-btn-primary"
					data-eem-action="add-note-submit"
					data-eem-add-note-submit
					disabled
				><?php esc_html_e( 'Add Note', 'equine-event-manager' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Derive type-key set from the order's `type` string. Returns the
	 * canonical display order: stall, rv, addon, group.
	 *
	 * @param array<string, mixed> $order
	 * @return string[]
	 */
	private function derive_type_keys( array $order ) {
		$type_str = isset( $order['type'] ) ? strtolower( (string) $order['type'] ) : '';
		$keys     = array();
		if ( false !== strpos( $type_str, 'stall' ) ) { $keys[] = 'stall'; }
		if ( false !== strpos( $type_str, 'rv' ) )    { $keys[] = 'rv'; }
		if ( false !== strpos( $type_str, 'add' ) )   { $keys[] = 'addon'; }
		if ( false !== strpos( $type_str, 'group' ) ) { $keys[] = 'group'; }
		return $keys;
	}

	/**
	 * Format an order number for header display ("#NNNNN") via the shared
	 * EEM_Orders_List_Page helper. Wrapper exists so callers don't have
	 * to remember which class owns the helper.
	 *
	 * @param array<string, mixed> $order
	 * @return string
	 */
	private static function format_order_number( array $order ) {
		$raw = isset( $order['order_number'] ) ? (string) $order['order_number'] : '';
		return EEM_Orders_List_Page::format_order_number_display( $raw );
	}

	/**
	 * Compute the night count between arrival and departure date strings.
	 *
	 * @param string $arrival   YYYY-MM-DD or empty.
	 * @param string $departure YYYY-MM-DD or empty.
	 * @return int  Nights (>= 0).
	 */
	private function compute_nights( $arrival, $departure ) {
		if ( '' === $arrival || '' === $departure ) {
			return 0;
		}
		$a = strtotime( $arrival );
		$d = strtotime( $departure );
		if ( ! $a || ! $d || $d <= $a ) {
			return 0;
		}
		return (int) round( ( $d - $a ) / DAY_IN_SECONDS );
	}

	/**
	 * Format a YYYY-MM-DD date to the mockup's "Friday, May 8, 2026" style.
	 *
	 * @param string $ymd
	 * @return string
	 */
	private function format_long_date( $ymd ) {
		$ts = '' === $ymd ? 0 : strtotime( $ymd );
		return $ts ? date_i18n( __( 'l, F j, Y', 'equine-event-manager' ), $ts ) : (string) $ymd;
	}

	/**
	 * Extract assigned stall unit labels from order components. Components
	 * carry `stall_units_csv` (legacy field). Returns comma-joined list
	 * with each unit prefixed by `#` per mockup, or empty when none.
	 *
	 * @param array<string, mixed> $order
	 * @return string
	 */
	private function extract_assigned_stalls( array $order ) {
		$units = array();
		if ( ! empty( $order['components'] ) && is_array( $order['components'] ) ) {
			foreach ( $order['components'] as $component ) {
				if ( ! is_array( $component ) || empty( $component['stall_units_csv'] ) ) {
					continue;
				}
				$raw = (string) $component['stall_units_csv'];
				foreach ( explode( ',', $raw ) as $unit ) {
					$u = trim( $unit );
					if ( '' !== $u ) {
						$units[] = '#' . ltrim( $u, '#' );
					}
				}
			}
		}
		return implode( ', ', array_unique( $units ) );
	}
}
