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
		// F4: the convenience fee follows admin-added line items; a discount leaves
		// the fee untouched. Single source of truth shared with Order Detail + receipt.
		if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			$composed  = EEM_Order_Adjustments_Repo::compose_order_totals( $order, $adjustments );
			$fees      = (float) $composed['effective_fees'];
			$total_due = (float) $composed['grand_total'];
		} else {
			$total_due = $base_total + $custom_total - $discount_amt;
		}
		// Amount collected = ledger net (payments − refunds), NOT the component
		// amount_paid column (which can't represent custom-item/discount portions,
		// so a fully-collected adjusted order otherwise read as still owing the
		// adjustment — bug #9). Single source of truth shared with Order Detail.
		$amount_paid    = class_exists( 'EEM_Orders_Repository' )
			? ( new EEM_Orders_Repository() )->get_net_collected( $order_key, $order )
			: ( isset( $order['amount_paid'] ) ? (float) $order['amount_paid'] : 0.0 );
		$outstanding    = max( 0.0, $total_due - $amount_paid );

		// Cash/check waives the convenience fee (Whitney decision: the fee is a
		// pass-through of the card processing cost, so offline payments drop it).
		// The Paid Cash tab pre-fills the fee-free REMAINING balance; the server
		// handler (handle_mark_order_paid) zeroes the fee on the order to match.
		$cash_total_due = max( 0.0, ( $total_due - $fees ) - $amount_paid );
		$cash_outstanding = $cash_total_due;

		$customer = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$email    = isset( $order['email'] ) ? (string) $order['email'] : '';
		$status   = isset( $order['payment_status'] ) ? (string) $order['payment_status'] : 'pending';
		$detail_url = class_exists( 'EEM_Orders_List_Page' )
			? EEM_Orders_List_Page::order_detail_url( $order_key )
			: admin_url( 'admin.php?page=equine-event-manager-orders' );

		// Treat the order as paid in full only when the actual outstanding balance
		// (total including custom items/discounts minus what has been collected) is
		// zero or negative — NOT just when payment_status === 'paid', which can be
		// stale after a custom line item is added post-payment.
		$is_paid = $outstanding <= 0.0;

		// Status banner: green "Payment Collected" when paid, else amber outstanding.
		if ( $is_paid ) :
			?>
			<div class="eem-cp-banner eem-cp-banner--paid">
				<div class="eem-cp-banner-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
				</div>
				<div class="eem-cp-banner-content">
					<div class="eem-cp-banner-title"><?php esc_html_e( 'Payment Collected', 'equine-event-manager' ); ?></div>
					<div class="eem-cp-banner-meta"><?php esc_html_e( 'This order is paid in full. No balance is due.', 'equine-event-manager' ); ?></div>
				</div>
			</div>
			<?php
		else :
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
			<?php
		endif;
		?>

		<div class="eem-co-workspace">
			<div class="eem-co-main">
				<?php
				self::render_customer_card( $customer, $email, $order, $order_no, $detail_url );
				self::render_items_card( $stall_subtotal, $rv_subtotal, $fees, $custom_items );
				?>
			</div>
			<aside class="eem-co-rail">
				<?php
				self::render_amount_due_card( $order_no, $status, $customer, $stall_subtotal, $rv_subtotal, $fees, $custom_items, $discount, $discount_amt, $total_due, $outstanding );
				self::render_payment_card( $detail_url, $order_key, $outstanding, $email, $cash_outstanding );
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
				<h2 class="eem-card-title"><svg class="eem-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?php
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
				<h2 class="eem-card-title"><svg class="eem-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg> <?php esc_html_e( 'Order Items', 'equine-event-manager' ); ?></h2>
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
	private static function render_amount_due_card( string $order_no, string $status, string $customer, float $stall, float $rv, float $fees, array $items, ?array $discount, float $disc_amt, float $total_due, float $outstanding = 0.0 ): void {
		?>
		<section class="eem-card eem-co-summary-card">
			<header class="eem-card-header eem-co-summary-head">
				<h2 class="eem-card-title"><svg class="eem-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <?php esc_html_e( 'Amount Due', 'equine-event-manager' ); ?></h2>
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
				<div class="eem-co-summary-total"><span><?php echo $outstanding <= 0.0 ? esc_html__( 'Total Paid', 'equine-event-manager' ) : esc_html__( 'Total Due', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $total_due, 2 ) ); ?></span></div>
			</div>
		</section>
		<?php
	}

	/**
	 * Read the active Stripe publishable key + readiness from settings, without
	 * exposing the secret key. Returns ['ready'=>bool, 'publishable'=>string].
	 *
	 * @return array{ready:bool, publishable:string}
	 */
	private static function get_stripe_client_config(): array {
		$settings = get_option( 'equine_event_manager_payment_settings', array() );
		$gateway  = isset( $settings['selected_gateway'] ) ? (string) $settings['selected_gateway'] : 'stripe';
		$stripe   = isset( $settings['stripe'] ) && is_array( $settings['stripe'] ) ? $settings['stripe'] : array();
		$mode     = isset( $stripe['mode'] ) && 'live' === $stripe['mode'] ? 'live' : 'test';
		$pub      = 'live' === $mode
			? ( isset( $stripe['live_publishable_key'] ) ? (string) $stripe['live_publishable_key'] : '' )
			: ( isset( $stripe['test_publishable_key'] ) ? (string) $stripe['test_publishable_key'] : '' );
		$sec      = 'live' === $mode
			? ( isset( $stripe['live_secret_key'] ) ? (string) $stripe['live_secret_key'] : '' )
			: ( isset( $stripe['test_secret_key'] ) ? (string) $stripe['test_secret_key'] : '' );
		return array(
			'ready'       => 'stripe' === $gateway && '' !== $pub && '' !== $sec,
			'publishable' => $pub,
		);
	}

	/**
	 * Authorize.net charge readiness for the Charge Card tab. Auth.net charges are
	 * server-side (raw card → authCaptureTransaction), so unlike Stripe there is no
	 * publishable key — readiness is just selected_gateway === 'authorize_net' plus
	 * the active-mode API login + transaction key being present in Settings.
	 *
	 * @return array{ready:bool}
	 */
	private static function get_authorize_net_client_config(): array {
		$settings = get_option( 'equine_event_manager_payment_settings', array() );
		$gateway  = isset( $settings['selected_gateway'] ) ? (string) $settings['selected_gateway'] : 'stripe';
		$an       = isset( $settings['authorize_net'] ) && is_array( $settings['authorize_net'] ) ? $settings['authorize_net'] : array();
		$mode     = isset( $an['mode'] ) && 'live' === $an['mode'] ? 'live' : 'test';
		$login    = 'live' === $mode
			? ( isset( $an['live_api_login'] ) ? (string) $an['live_api_login'] : '' )
			: ( isset( $an['test_api_login'] ) ? (string) $an['test_api_login'] : '' );
		$key      = 'live' === $mode
			? ( isset( $an['live_transaction_key'] ) ? (string) $an['live_transaction_key'] : '' )
			: ( isset( $an['test_transaction_key'] ) ? (string) $an['test_transaction_key'] : '' );
		return array(
			'ready' => 'authorize_net' === $gateway && '' !== trim( $login ) && '' !== trim( $key ),
		);
	}

	/**
	 * Payment card — Send Link / Charge Card tabs.
	 *
	 * Charge Card: when Stripe is configured and the order is unpaid with a
	 * balance due, renders a live Stripe Elements card form (client-tokenized; no
	 * card data touches the server) wired to the gated, capability+nonce-checked
	 * eem_collect_payment_* AJAX handlers. Otherwise a gated notice. Send Link
	 * (email) remains gated pending separate sign-off.
	 *
	 * @param string $detail_url Order Detail URL.
	 * @param string $order_key  Order key.
	 * @param string $status     Payment status.
	 * @param float  $total_due  Recomputed balance due.
	 * @param string $email      Customer email (for the Send Link copy).
	 * @param float  $cash_total_due Fee-waived balance for the Paid Cash tab's
	 *                          "Amount Received" pre-fill (cash/check waive the
	 *                          convenience fee). Computed by render_workspace and
	 *                          passed in — previously referenced here as an
	 *                          out-of-scope variable, so the field rendered $0.00.
	 * @return void
	 */
	private static function render_payment_card( string $detail_url, string $order_key, float $total_due, string $email = '', float $cash_total_due = 0.0 ): void {
		// No outstanding balance — show a settled notice rather than a payment form.
		if ( $total_due <= 0.0 ) {
			?>
			<section class="eem-card eem-co-payment-card">
				<div class="eem-card-body">
					<p class="eem-field-hint"><?php esc_html_e( 'This order is paid in full — there is nothing left to collect.', 'equine-event-manager' ); ?></p>
					<a class="eem-btn eem-btn-secondary eem-co-btn-block" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View Order', 'equine-event-manager' ); ?></a>
				</div>
			</section>
			<?php
			return;
		}

		$stripe        = self::get_stripe_client_config();
		$authnet       = self::get_authorize_net_client_config();
		$stripe_ready  = $stripe['ready'] && $total_due > 0;
		$authnet_ready = $authnet['ready'] && $total_due > 0;
		$charge_ready  = $stripe_ready || $authnet_ready;
		?>
		<section class="eem-card eem-co-payment-card">
			<div class="eem-co-payment-tabs" role="tablist">
				<button type="button" class="eem-co-payment-tab is-active" data-eem-action="collect-payment-tab" data-tab="link" role="tab" aria-selected="true"><?php esc_html_e( 'Send Link', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-co-payment-tab" data-eem-action="collect-payment-tab" data-tab="charge" role="tab" aria-selected="false"><?php esc_html_e( 'Charge Card', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-co-payment-tab" data-eem-action="collect-payment-tab" data-tab="cash" role="tab" aria-selected="false"><?php esc_html_e( 'Paid Cash', 'equine-event-manager' ); ?></button>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="link">
				<?php
				$send_link_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=equine_event_manager_send_invoice_email&order_key=' . rawurlencode( $order_key ) ),
					'equine_event_manager_send_invoice_email_' . $order_key
				);
				?>
				<p class="eem-field-hint">
					<?php
					if ( '' !== $email ) {
						printf(
							/* translators: 1: customer email, 2: amount due */
							esc_html__( 'Email %1$s a secure link to pay the %2$s balance online — no card details needed here.', 'equine-event-manager' ),
							'<strong>' . esc_html( $email ) . '</strong>',
							'<strong>$' . esc_html( number_format_i18n( $total_due, 2 ) ) . '</strong>'
						);
					} else {
						esc_html_e( 'Email the customer a secure link to pay their balance online — no card details needed here.', 'equine-event-manager' );
					}
					?>
				</p>
				<a class="eem-btn eem-btn-electric eem-co-btn-block" href="<?php echo esc_url( $send_link_url ); ?>"><?php esc_html_e( 'Send Payment Link', 'equine-event-manager' ); ?></a>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="charge" hidden>
				<?php if ( $stripe_ready ) : ?>
					<p class="eem-field-hint"><?php esc_html_e( 'Enter the card to charge the balance directly. Card details are tokenized by Stripe in the browser — they never reach the server.', 'equine-event-manager' ); ?></p>
					<div id="eem-cp-card-element" class="eem-cp-card-element"></div>
					<div id="eem-cp-charge-error" class="eem-cp-charge-error" role="alert"></div>
					<button type="button" id="eem-cp-charge-btn" class="eem-btn eem-btn-electric eem-co-btn-block">
						<?php
						/* translators: %s: amount to charge */
						echo esc_html( sprintf( __( 'Charge $%s', 'equine-event-manager' ), number_format_i18n( $total_due, 2 ) ) );
						?>
					</button>
					<p class="eem-cp-secure-note"><?php esc_html_e( 'Secured by Stripe', 'equine-event-manager' ); ?></p>
					<?php
					self::print_charge_assets( $order_key );
				elseif ( $authnet_ready ) :
					?>
					<p class="eem-field-hint"><?php esc_html_e( 'Enter the card to charge the balance directly through Authorize.net.', 'equine-event-manager' ); ?></p>
					<div class="eem-cp-an-fields">
						<label class="eem-field-row">
							<span class="eem-field-label"><?php esc_html_e( 'Card Number', 'equine-event-manager' ); ?></span>
							<input type="text" id="eem-cp-an-number" class="eem-field-input" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="1234 5678 9012 3456" />
						</label>
						<div class="eem-cp-an-row">
							<label class="eem-field-row">
								<span class="eem-field-label"><?php esc_html_e( 'Exp. Month', 'equine-event-manager' ); ?></span>
								<input type="text" id="eem-cp-an-exp-month" class="eem-field-input" inputmode="numeric" autocomplete="cc-exp-month" maxlength="2" placeholder="MM" />
							</label>
							<label class="eem-field-row">
								<span class="eem-field-label"><?php esc_html_e( 'Exp. Year', 'equine-event-manager' ); ?></span>
								<input type="text" id="eem-cp-an-exp-year" class="eem-field-input" inputmode="numeric" autocomplete="cc-exp-year" maxlength="4" placeholder="YYYY" />
							</label>
							<label class="eem-field-row">
								<span class="eem-field-label"><?php esc_html_e( 'CVC', 'equine-event-manager' ); ?></span>
								<input type="text" id="eem-cp-an-cvc" class="eem-field-input" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="123" />
							</label>
						</div>
					</div>
					<div id="eem-cp-charge-error" class="eem-cp-charge-error" role="alert"></div>
					<button type="button" id="eem-cp-an-charge-btn" class="eem-btn eem-btn-electric eem-co-btn-block">
						<?php
						/* translators: %s: amount to charge */
						echo esc_html( sprintf( __( 'Charge $%s', 'equine-event-manager' ), number_format_i18n( $total_due, 2 ) ) );
						?>
					</button>
					<p class="eem-cp-secure-note"><?php esc_html_e( 'Secured by Authorize.net', 'equine-event-manager' ); ?></p>
					<?php
					self::print_authorize_charge_assets( $order_key );
				else :
					?>
					<div class="eem-info-banner eem-info-banner--preview">
						<?php esc_html_e( 'Card charging needs Stripe or Authorize.net configured in Settings (and an unpaid balance). Configure a payment gateway, or record an offline payment from the order page.', 'equine-event-manager' ); ?>
					</div>
					<a class="eem-btn eem-btn-secondary eem-co-btn-block" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Go to Order — record payment', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="cash" hidden>
				<p class="eem-field-hint">
					<?php esc_html_e( 'Record an offline payment (cash, check, or other) for this order. The order will be marked as paid.', 'equine-event-manager' ); ?>
				</p>
				<?php if ( $fees > 0 ) : ?>
				<p class="eem-field-hint">
					<?php
					printf(
						/* translators: %s: convenience fee amount */
						esc_html__( 'The %s convenience fee is waived for cash and check payments — the amount below excludes it.', 'equine-event-manager' ),
						'<strong>$' . esc_html( number_format_i18n( $fees, 2 ) ) . '</strong>'
					);
					?>
				</p>
				<?php endif; ?>
				<div class="eem-co-cash-field">
					<span class="eem-co-cash-field__label"><?php esc_html_e( 'Payment Method', 'equine-event-manager' ); ?></span>
					<select id="eem-cp-cash-method" class="eem-field-select" style="width:100%">
						<option value="cash"><?php esc_html_e( 'Cash', 'equine-event-manager' ); ?></option>
						<option value="check"><?php esc_html_e( 'Check', 'equine-event-manager' ); ?></option>
					</select>
				</div>
				<div class="eem-co-cash-field" id="eem-cp-check-number-wrap" hidden>
					<span class="eem-co-cash-field__label"><?php esc_html_e( 'Check #', 'equine-event-manager' ); ?></span>
					<input type="text" id="eem-cp-check-number" class="eem-field-input" style="width:100%" placeholder="<?php esc_attr_e( 'Check number', 'equine-event-manager' ); ?>" />
				</div>
				<div class="eem-co-cash-field">
					<span class="eem-co-cash-field__label"><?php esc_html_e( 'Amount Received', 'equine-event-manager' ); ?></span>
					<input type="text" id="eem-cp-cash-amount" class="eem-field-input" inputmode="decimal" style="width:100%" value="<?php echo esc_attr( '$' . number_format( $cash_total_due, 2, '.', '' ) ); ?>" />
				</div>
				<?php
				$cash_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=equine_event_manager_mark_order_paid&order_key=' . rawurlencode( $order_key ) ),
					'equine_event_manager_mark_order_paid_' . $order_key
				);
				?>
				<a id="eem-cp-cash-btn" class="eem-btn eem-btn-electric eem-co-btn-block" href="<?php echo esc_url( $cash_url . '&method=cash' ); ?>">
					<?php esc_html_e( 'Record Payment', 'equine-event-manager' ); ?>
				</a>
			</div>
		</section>
		<?php
	}

	/**
	 * Print the Stripe.js loader + the inline charge client (create intent →
	 * confirm card → record). Two-step: create the PaymentIntent server-side,
	 * confirm with Stripe Elements in the browser, then verify+record server-side.
	 * Inline (not innerHTML-injected) so it executes on page load.
	 *
	 * @param string $order_key Order key.
	 * @return void
	 */
	private static function print_charge_assets( string $order_key ): void {
		$cfg = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'publishableKey' => self::get_stripe_client_config()['publishable'],
			'orderKey'       => $order_key,
			'nonce'          => wp_create_nonce( 'eem_collect_payment_' . $order_key ),
		);
		?>
		<script src="https://js.stripe.com/v3/"></script>
		<script>
		window.eemCollectPayment = <?php echo wp_json_encode( $cfg ); ?>;
		(function () {
			var cfg = window.eemCollectPayment;
			if ( ! cfg || ! cfg.publishableKey || typeof Stripe === 'undefined' ) { return; }
			var stripe = Stripe( cfg.publishableKey );
			var card = stripe.elements().create( 'card' );
			card.mount( '#eem-cp-card-element' );
			var btn = document.getElementById( 'eem-cp-charge-btn' );
			var errEl = document.getElementById( 'eem-cp-charge-error' );
			function body( obj ) { var p = new URLSearchParams(); Object.keys( obj ).forEach( function ( k ) { p.set( k, obj[ k ] ); } ); return p; }
			btn.addEventListener( 'click', function () {
				btn.disabled = true; errEl.textContent = '';
				fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body( { action: 'eem_collect_payment_create_intent', _wpnonce: cfg.nonce, order_key: cfg.orderKey } ) } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( ! j || ! j.success || ! j.data || ! j.data.client_secret ) { throw new Error( ( j && j.data && j.data.message ) || 'Could not start the payment.' ); }
						return stripe.confirmCardPayment( j.data.client_secret, { payment_method: { card: card } } );
					} )
					.then( function ( result ) {
						if ( result.error ) { throw new Error( result.error.message ); }
						if ( ! result.paymentIntent || result.paymentIntent.status !== 'succeeded' ) { throw new Error( 'Payment was not completed.' ); }
						return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body( { action: 'eem_collect_payment_confirm', _wpnonce: cfg.nonce, order_key: cfg.orderKey, payment_intent_id: result.paymentIntent.id } ) } ).then( function ( r ) { return r.json(); } );
					} )
					.then( function ( j2 ) {
						if ( j2 && j2.success ) {
							if ( window.EEM && typeof window.EEM.showSaveToast === 'function' ) { window.EEM.showSaveToast( 'Payment collected. Reloading…' ); }
							setTimeout( function () { window.location.reload(); }, 700 );
						} else { throw new Error( ( j2 && j2.data && j2.data.message ) || 'Could not record the payment.' ); }
					} )
					.catch( function ( e ) { errEl.textContent = e.message; btn.disabled = false; } );
			} );
		})();
		</script>
		<?php
	}

	/**
	 * Print the Authorize.net charge client. Unlike Stripe, Auth.net charges are
	 * a single server-side step: collect the raw card fields and POST them to
	 * `eem_collect_payment_authorize_charge`, which runs the proven
	 * authCaptureTransaction dispatch + records the payment. (Auth.net here uses
	 * raw card fields server-side, matching the existing customer invoice-pay
	 * path; no client tokenization.) Inline so it runs on load.
	 *
	 * @param string $order_key Order key.
	 * @return void
	 */
	private static function print_authorize_charge_assets( string $order_key ): void {
		// #46: Accept.js config so the admin charge can tokenize the card in the
		// browser when the gateway is set up for it (gated off otherwise).
		$an  = ( new EEM_Shortcodes() )->get_active_authorize_net_configuration();
		$cfg = array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'orderKey'    => $order_key,
			'nonce'       => wp_create_nonce( 'eem_collect_payment_' . $order_key ),
			'useAcceptjs' => ! empty( $an['use_acceptjs'] ),
			'clientKey'   => (string) $an['client_key'],
			'apiLogin'    => (string) $an['api_login'],
			'acceptUrl'   => (string) $an['acceptjs_url'],
		);
		?>
		<script>
		window.eemCollectPaymentAn = <?php echo wp_json_encode( $cfg ); ?>;
		(function () {
			var cfg = window.eemCollectPaymentAn;
			if ( ! cfg ) { return; }
			var btn   = document.getElementById( 'eem-cp-an-charge-btn' );
			var errEl = document.getElementById( 'eem-cp-charge-error' );
			if ( ! btn || ! errEl ) { return; }
			if ( cfg.useAcceptjs ) { var as = document.createElement( 'script' ); as.src = cfg.acceptUrl; as.async = true; document.head.appendChild( as ); }
			function val( id ) { var el = document.getElementById( id ); return el ? el.value.replace( /[^0-9]/g, '' ) : ''; }
			function body( obj ) { var p = new URLSearchParams(); Object.keys( obj ).forEach( function ( k ) { p.set( k, obj[ k ] ); } ); return p; }
			function charge( fields ) {
				btn.disabled = true;
				var base = { action: 'eem_collect_payment_authorize_charge', _wpnonce: cfg.nonce, order_key: cfg.orderKey };
				Object.keys( fields ).forEach( function ( k ) { base[ k ] = fields[ k ]; } );
				fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body( base ) } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success ) {
							if ( window.EEM && typeof window.EEM.showSaveToast === 'function' ) { window.EEM.showSaveToast( 'Payment collected. Reloading…' ); }
							setTimeout( function () { window.location.reload(); }, 700 );
						} else {
							throw new Error( ( j && j.data && j.data.message ) || 'The charge could not be completed.' );
						}
					} )
					.catch( function ( e ) { errEl.textContent = e.message; btn.disabled = false; } );
			}
			btn.addEventListener( 'click', function () {
				errEl.textContent = '';
				var num = val( 'eem-cp-an-number' ), mm = val( 'eem-cp-an-exp-month' ), yy = val( 'eem-cp-an-exp-year' ), cvc = val( 'eem-cp-an-cvc' );
				if ( num.length < 13 || num.length > 19 || ! mm || ! yy || cvc.length < 3 ) {
					errEl.textContent = 'Enter a complete card number, expiration date, and security code.';
					return;
				}
				if ( cfg.useAcceptjs ) {
					if ( ! window.Accept || typeof window.Accept.dispatchData !== 'function' ) { errEl.textContent = 'Secure payment library is still loading — try again in a moment.'; return; }
					btn.disabled = true;
					window.Accept.dispatchData( {
						authData: { clientKey: cfg.clientKey, apiLoginID: cfg.apiLogin },
						cardData: { cardNumber: num, month: mm, year: yy, cardCode: cvc }
					}, function ( response ) {
						if ( ! response || ! response.messages || response.messages.resultCode === 'Error' ) {
							errEl.textContent = ( response && response.messages && response.messages.message && response.messages.message[0] ) ? response.messages.message[0].text : 'We could not verify the card.';
							btn.disabled = false;
							return;
						}
						charge( { authorize_opaque_descriptor: response.opaqueData.dataDescriptor, authorize_opaque_value: response.opaqueData.dataValue } );
					} );
				} else {
					charge( { authorize_card_number: num, authorize_exp_month: mm, authorize_exp_year: yy, authorize_card_code: cvc } );
				}
			} );
		})();
		</script>
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
		// Canonical logic in EEM_Formatter (one source of truth).
		return EEM_Formatter::format_order_number( $order_number );
	}
}
