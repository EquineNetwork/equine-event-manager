<?php
/**
 * Collect Payment admin page (C14 — non-gated half).
 *
 * Renders a read-only payment workspace for an existing order, reached via
 * `?order_key=<key>` from the Orders list "Collect" pill and the Order Detail
 * "Payment Outstanding" banner. Shows the order's customer/items, an Amount Due
 * rail (component lines + any C13.C custom items + discount, recomputed total),
 * and the Send Link / Charge Card payment tabs.
 *
 * GATED — real-money / send-on-behalf actions are NOT implemented here and stay
 * behind explicit per-action approval (see docs/AUDIT-C14.md decision-locks):
 *   - Charge Card dispatch (Stripe Elements tokenization → PaymentIntent confirm;
 *     Stripe-first per the C14 gateway decision).
 *   - Send Link (resend payment-link email).
 * Until approved, both tabs render an honest gated notice pointing the admin at
 * the existing "mark paid manually" path on Order Detail. No charge code, no card
 * fields, no email send ships in this build.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only Collect Payment workspace for an existing order.
 *
 * @since 2.2.0
 */
class EEM_Collect_Payment_Page {

	/**
	 * Menu slug used for the route. Hidden submenu (parent='') — reached only via
	 * direct URL from Orders list / Order Detail.
	 */
	const MENU_SLUG = 'equine-event-manager-collect-payment';

	/**
	 * Render the page: empty state when no/invalid order_key, otherwise the
	 * read-only payment workspace.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_breadcrumb.php';
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET navigation.
		$order     = '' !== $order_key && class_exists( 'EEM_Orders_Repository' )
			? ( new EEM_Orders_Repository() )->get_order( $order_key )
			: null;

		$orders_url = admin_url( 'admin.php?page=equine-event-manager-orders' );
		$order_no   = is_array( $order ) && isset( $order['order_number'] )
			? self::format_order_number_display( (string) $order['order_number'] )
			: '';

		eem_render_page_open( array(
			'title'      => is_array( $order )
				/* translators: %s: order number */
				? sprintf( __( 'Collect Payment — Order %s', 'equine-event-manager' ), $order_no )
				: __( 'Collect Payment', 'equine-event-manager' ),
			'subtitle'   => __( 'Process payment for an existing order. The order details are locked here — to amend the order, return to the order detail page.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders', 'equine-event-manager' ), 'url' => $orders_url ),
				array( 'label' => __( 'Collect Payment', 'equine-event-manager' ) ),
			),
		) );

		echo '<div class="eem-create-order-body">';

		if ( ! is_array( $order ) ) {
			self::render_empty_state( $orders_url );
		} else {
			self::render_workspace( $order, $order_key, $order_no );
		}

		echo '</div>';

		eem_render_page_close();
	}

	/**
	 * Empty state shown when no order_key is supplied or the order is not found.
	 *
	 * @param string $orders_url Back-to-orders URL.
	 * @return void
	 */
	private static function render_empty_state( string $orders_url ): void {
		?>
		<div class="eem-cp-empty">
			<div class="eem-cp-empty-title"><?php esc_html_e( 'No Order Specified', 'equine-event-manager' ); ?></div>
			<p class="eem-cp-empty-desc"><?php esc_html_e( 'This page expects an order. Return to the Orders list and click Collect on any unpaid or invoice-sent order to start a payment.', 'equine-event-manager' ); ?></p>
			<a class="eem-btn eem-btn-secondary" href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Render the full read-only workspace for a found order.
	 *
	 * @param array<string,mixed> $order     Grouped order data.
	 * @param string              $order_key Order key.
	 * @param string              $order_no  Pre-formatted display order number.
	 * @return void
	 */
	private static function render_workspace( array $order, string $order_key, string $order_no ): void {
		$stall_subtotal = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
		$rv_subtotal    = isset( $order['rv_subtotal'] ) ? (float) $order['rv_subtotal'] : 0.0;
		$fees           = isset( $order['fees'] ) ? (float) $order['fees'] : 0.0;
		$base_total     = isset( $order['total'] ) ? (float) $order['total'] : 0.0;

		$adjustments    = class_exists( 'EEM_Order_Adjustments_Repo' )
			? EEM_Order_Adjustments_Repo::get_for_order( $order_key )
			: array( 'custom_items' => array(), 'discount' => null, 'custom_items_total' => 0.0 );
		$custom_items   = $adjustments['custom_items'];
		$custom_total   = (float) $adjustments['custom_items_total'];
		$discount       = $adjustments['discount'];
		$discount_amt   = is_array( $discount ) ? (float) $discount['amount'] : 0.0;
		$total_due      = $base_total + $custom_total - $discount_amt;

		$customer = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$email    = isset( $order['email'] ) ? (string) $order['email'] : '';
		$status   = isset( $order['payment_status'] ) ? (string) $order['payment_status'] : 'pending';
		$detail_url = class_exists( 'EEM_Orders_List_Page' )
			? EEM_Orders_List_Page::order_detail_url( $order_key )
			: admin_url( 'admin.php?page=equine-event-manager-orders' );

		// Outstanding banner.
		?>
		<div class="eem-cp-banner">
			<div class="eem-cp-banner-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			</div>
			<div class="eem-cp-banner-content">
				<div class="eem-cp-banner-title"><?php esc_html_e( 'Payment Outstanding', 'equine-event-manager' ); ?></div>
				<div class="eem-cp-banner-meta">
					<?php
					printf(
						/* translators: %s: amount due */
						esc_html__( '%s has not been collected for this order.', 'equine-event-manager' ),
						'<span class="eem-cp-banner-amount">$' . esc_html( number_format_i18n( $total_due, 2 ) ) . '</span>'
					);
					?>
				</div>
			</div>
		</div>

		<div class="eem-co-workspace">
			<div class="eem-co-main">
				<?php
				self::render_customer_card( $customer, $email, $order, $order_no, $detail_url );
				self::render_items_card( $stall_subtotal, $rv_subtotal, $fees, $custom_items );
				?>
			</div>
			<aside class="eem-co-rail">
				<?php
				self::render_amount_due_card( $order_no, $status, $customer, $stall_subtotal, $rv_subtotal, $fees, $custom_items, $discount, $discount_amt, $total_due );
				self::render_payment_card( $detail_url );
				?>
			</aside>
		</div>
		<?php
	}

	/**
	 * Read-only customer + order info card.
	 *
	 * @param string              $customer   Customer name.
	 * @param string              $email      Customer email.
	 * @param array<string,mixed> $order      Order data.
	 * @param string              $order_no   Display order number.
	 * @param string              $detail_url Order Detail URL.
	 * @return void
	 */
	private static function render_customer_card( string $customer, string $email, array $order, string $order_no, string $detail_url ): void {
		$event = isset( $order['event_label'] ) ? (string) $order['event_label'] : '';
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php
					/* translators: %s: order number */
					echo esc_html( sprintf( __( 'Customer — Order %s', 'equine-event-manager' ), $order_no ) );
				?></h2>
				<a class="eem-link" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View Full Order →', 'equine-event-manager' ); ?></a>
			</header>
			<div class="eem-card-body">
				<div class="eem-cp-field-grid">
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></label><div class="eem-cp-field-value"><?php echo esc_html( '' !== $customer ? $customer : '—' ); ?></div></div>
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></label><div class="eem-cp-field-value"><?php echo esc_html( '' !== $email ? $email : '—' ); ?></div></div>
				</div>
				<?php if ( '' !== $event ) : ?>
				<div class="eem-cp-field-grid eem-cp-field-grid--1">
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?></label><div class="eem-cp-field-value"><?php echo esc_html( $event ); ?></div></div>
				</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Read-only order items card.
	 *
	 * @param float                                                       $stall  Stall subtotal.
	 * @param float                                                       $rv     RV subtotal.
	 * @param float                                                       $fees   Convenience fees.
	 * @param array<int,array{id:int,description:string,amount:float}>    $items  Custom items.
	 * @return void
	 */
	private static function render_items_card( float $stall, float $rv, float $fees, array $items ): void {
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php esc_html_e( 'Order Items', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<?php if ( $stall > 0 ) : ?>
					<div class="eem-cp-item-row"><span><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></span><span class="eem-cp-item-price"><?php echo esc_html( '$' . number_format_i18n( $stall, 2 ) ); ?></span></div>
				<?php endif; ?>
				<?php if ( $rv > 0 ) : ?>
					<div class="eem-cp-item-row"><span><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></span><span class="eem-cp-item-price"><?php echo esc_html( '$' . number_format_i18n( $rv, 2 ) ); ?></span></div>
				<?php endif; ?>
				<?php foreach ( $items as $item ) : ?>
					<div class="eem-cp-item-row"><span><?php echo esc_html( $item['description'] ); ?></span><span class="eem-cp-item-price"><?php echo esc_html( '$' . number_format_i18n( (float) $item['amount'], 2 ) ); ?></span></div>
				<?php endforeach; ?>
				<?php if ( $fees > 0 ) : ?>
					<div class="eem-cp-item-row"><span><?php esc_html_e( 'Convenience Fee', 'equine-event-manager' ); ?></span><span class="eem-cp-item-price"><?php echo esc_html( '$' . number_format_i18n( $fees, 2 ) ); ?></span></div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Amount Due rail card — summary lines + adjustments (read-only) + Total Due.
	 *
	 * Discount management (apply/remove) lives on Order Detail; this rail shows the
	 * current adjustments read-only since charging is gated.
	 *
	 * @param string                                                    $order_no  Display order number.
	 * @param string                                                    $status    Payment status.
	 * @param string                                                    $customer  Customer name.
	 * @param float                                                     $stall     Stall subtotal.
	 * @param float                                                     $rv        RV subtotal.
	 * @param float                                                     $fees      Fees.
	 * @param array<int,array{id:int,description:string,amount:float}>  $items     Custom items.
	 * @param array{reason:string,amount:float}|null                    $discount  Discount or null.
	 * @param float                                                     $disc_amt  Resolved discount amount.
	 * @param float                                                     $total_due Recomputed total due.
	 * @return void
	 */
	private static function render_amount_due_card( string $order_no, string $status, string $customer, float $stall, float $rv, float $fees, array $items, ?array $discount, float $disc_amt, float $total_due ): void {
		?>
		<section class="eem-card eem-co-summary-card">
			<header class="eem-card-header eem-co-summary-head">
				<h2 class="eem-card-title"><?php esc_html_e( 'Amount Due', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-co-summary-lines">
					<div class="eem-co-summary-event"><?php
						/* translators: 1: order number, 2: customer name */
						echo esc_html( sprintf( __( 'Order %1$s · %2$s', 'equine-event-manager' ), $order_no, $customer ) );
					?></div>
					<?php if ( $stall > 0 ) : ?><div class="eem-co-summary-line"><span class="eem-co-summary-line-label"><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></span><span class="eem-co-summary-line-price"><?php echo esc_html( '$' . number_format_i18n( $stall, 2 ) ); ?></span></div><?php endif; ?>
					<?php if ( $rv > 0 ) : ?><div class="eem-co-summary-line"><span class="eem-co-summary-line-label"><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></span><span class="eem-co-summary-line-price"><?php echo esc_html( '$' . number_format_i18n( $rv, 2 ) ); ?></span></div><?php endif; ?>
					<?php foreach ( $items as $item ) : ?><div class="eem-co-summary-line eem-co-summary-line--custom"><span class="eem-co-summary-line-label"><?php echo esc_html( $item['description'] ); ?></span><span class="eem-co-summary-line-price"><?php echo esc_html( '$' . number_format_i18n( (float) $item['amount'], 2 ) ); ?></span></div><?php endforeach; ?>
					<?php if ( $fees > 0 ) : ?><div class="eem-co-summary-line"><span class="eem-co-summary-line-label"><?php esc_html_e( 'Convenience Fee', 'equine-event-manager' ); ?></span><span class="eem-co-summary-line-price"><?php echo esc_html( '$' . number_format_i18n( $fees, 2 ) ); ?></span></div><?php endif; ?>
					<?php if ( is_array( $discount ) && $disc_amt > 0 ) : ?>
					<div class="eem-co-summary-line eem-co-summary-line--discount"><span class="eem-co-summary-line-label"><?php echo esc_html( sprintf( __( 'Discount (%s)', 'equine-event-manager' ), $discount['reason'] ) ); ?></span><span class="eem-co-summary-line-price"><?php echo esc_html( '−$' . number_format_i18n( $disc_amt, 2 ) ); ?></span></div>
					<?php endif; ?>
				</div>
				<hr class="eem-co-summary-divider" />
				<div class="eem-co-summary-total"><span><?php esc_html_e( 'Total Due', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $total_due, 2 ) ); ?></span></div>
			</div>
		</section>
		<?php
	}

	/**
	 * Payment card — Send Link / Charge Card tabs. Both bodies are GATED: real
	 * dispatch is not implemented until payment-flow approval. An honest notice
	 * points the admin at the existing manual mark-paid path on Order Detail.
	 *
	 * @param string $detail_url Order Detail URL.
	 * @return void
	 */
	private static function render_payment_card( string $detail_url ): void {
		?>
		<section class="eem-card eem-co-payment-card">
			<div class="eem-co-payment-tabs" role="tablist">
				<button type="button" class="eem-co-payment-tab is-active" data-eem-action="collect-payment-tab" data-tab="link" role="tab" aria-selected="true"><?php esc_html_e( 'Send Link', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-co-payment-tab" data-eem-action="collect-payment-tab" data-tab="charge" role="tab" aria-selected="false"><?php esc_html_e( 'Charge Card', 'equine-event-manager' ); ?></button>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="link">
				<p class="eem-field-hint"><?php esc_html_e( 'Resending the payment-link email and charging a card are pending payment-flow activation.', 'equine-event-manager' ); ?></p>
				<div class="eem-info-banner eem-info-banner--preview">
					<?php esc_html_e( 'Charge dispatch and Send-Link email require sign-off before they go live (Stripe-first, client-tokenized — no card data is handled here). In the meantime, record an offline payment from the order page.', 'equine-event-manager' ); ?>
				</div>
				<a class="eem-btn eem-btn-secondary eem-co-btn-block" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Go to Order — record payment', 'equine-event-manager' ); ?></a>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="charge" hidden>
				<p class="eem-field-hint"><?php esc_html_e( 'Card charging is pending payment-flow activation. When enabled, cards are tokenized client-side (Stripe Elements) — raw card numbers are never handled by the server.', 'equine-event-manager' ); ?></p>
				<a class="eem-btn eem-btn-secondary eem-co-btn-block" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Go to Order — record payment', 'equine-event-manager' ); ?></a>
			</div>
		</section>
		<?php
	}

	/**
	 * Format an order number for display as `#%05d` when numeric (5-digit
	 * zero-padded standard), else prefixed verbatim.
	 *
	 * @param string $order_number Raw order number.
	 * @return string
	 */
	private static function format_order_number_display( string $order_number ): string {
		$order_number = trim( $order_number );
		if ( '' === $order_number ) {
			return '';
		}
		return ctype_digit( $order_number ) ? sprintf( '#%05d', (int) $order_number ) : '#' . $order_number;
	}
}
