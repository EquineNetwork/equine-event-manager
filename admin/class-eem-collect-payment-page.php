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

		$is_paid = 'paid' === $status;

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
				self::render_amount_due_card( $order_no, $status, $customer, $stall_subtotal, $rv_subtotal, $fees, $custom_items, $discount, $discount_amt, $total_due );
				self::render_payment_card( $detail_url, $order_key, $status, $total_due );
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
				<div class="eem-co-summary-total"><span><?php echo 'paid' === $status ? esc_html__( 'Total Paid', 'equine-event-manager' ) : esc_html__( 'Total Due', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $total_due, 2 ) ); ?></span></div>
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
	 * @return void
	 */
	private static function render_payment_card( string $detail_url, string $order_key, string $status, float $total_due ): void {
		// Already-paid orders show a settled notice — no payment UI.
		if ( 'paid' === $status ) {
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

		$stripe       = self::get_stripe_client_config();
		$charge_ready = $stripe['ready'] && $total_due > 0;
		?>
		<section class="eem-card eem-co-payment-card">
			<div class="eem-co-payment-tabs" role="tablist">
				<button type="button" class="eem-co-payment-tab is-active" data-eem-action="collect-payment-tab" data-tab="link" role="tab" aria-selected="true"><?php esc_html_e( 'Send Link', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-co-payment-tab" data-eem-action="collect-payment-tab" data-tab="charge" role="tab" aria-selected="false"><?php esc_html_e( 'Charge Card', 'equine-event-manager' ); ?></button>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="link">
				<p class="eem-field-hint"><?php esc_html_e( 'Resending the payment-link email is pending sign-off. In the meantime you can charge a card directly or record an offline payment from the order page.', 'equine-event-manager' ); ?></p>
				<a class="eem-btn eem-btn-secondary eem-co-btn-block" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Go to Order — record payment', 'equine-event-manager' ); ?></a>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-collect-panel="charge" hidden>
				<?php if ( $charge_ready ) : ?>
					<p class="eem-field-hint"><?php esc_html_e( 'Enter the card to charge the balance directly. Card details are tokenized by Stripe in the browser — they never reach the server.', 'equine-event-manager' ); ?></p>
					<div id="eem-cp-card-element" class="eem-cp-card-element"></div>
					<div id="eem-cp-charge-error" class="eem-cp-charge-error" role="alert"></div>
					<button type="button" id="eem-cp-charge-btn" class="eem-btn eem-btn-primary eem-co-btn-block">
						<?php
						/* translators: %s: amount to charge */
						echo esc_html( sprintf( __( 'Charge $%s', 'equine-event-manager' ), number_format_i18n( $total_due, 2 ) ) );
						?>
					</button>
					<p class="eem-cp-secure-note"><?php esc_html_e( 'Secured by Stripe', 'equine-event-manager' ); ?></p>
					<?php
					self::print_charge_assets( $order_key );
				else :
					?>
					<div class="eem-info-banner eem-info-banner--preview">
						<?php esc_html_e( 'Card charging needs Stripe configured in Settings (and an unpaid balance). Configure Stripe, or record an offline payment from the order page.', 'equine-event-manager' ); ?>
					</div>
					<a class="eem-btn eem-btn-secondary eem-co-btn-block" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Go to Order — record payment', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
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
