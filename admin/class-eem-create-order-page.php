<?php
/**
 * Create Order admin page (C13).
 *
 * Manually assemble an order on behalf of a customer (phone orders, walk-ins).
 * Two-column workspace mirroring `.mockups/create_order_page.html`: a main column
 * of form cards (customer lookup, reservation picker, contact info, reservation
 * sections, custom line items, special requests) and a sticky rail (live order
 * summary + discount affordance + payment hand-off).
 *
 * Per the C13 kickoff decisions:
 *  - Payment is NOT dispatched from this page. "Send Link" emails an invoice/pay
 *    link; "Charge Card" hands off to the C14 Collect Payment page. No real charge
 *    is wired here, so nothing payment-gated lives on this page.
 *  - Reservation sections reuse the customer reservation-form engine (wired in a
 *    later C13 sub-chunk); C13.A.1 ports the page chrome.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Create Order admin page and backs its customer typeahead.
 *
 * @since 2.6.0
 */
class EEM_Create_Order_Page {

	/**
	 * Menu slug for the route (wired in EEM_Admin::register_admin_pages).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-create-order';

	/**
	 * Build the admin URL for this page.
	 *
	 * @param array<string, mixed> $args Optional extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_breadcrumb.php';
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		// C13.B.2.a — server-side embed. When ?reservation_id=N is set, the four
		// stub section cards are replaced by the rendered [en_reservation] shortcode
		// output so the live pricing engine (qty steppers, date pickers, totals)
		// runs in the admin context. Validate here so the embed path is always clean.
		$rid           = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$embedded_post = $rid ? get_post( $rid ) : null;
		if (
			! $embedded_post ||
			EEM_Reservations_CPT::POST_TYPE !== $embedded_post->post_type ||
			'publish' !== $embedded_post->post_status
		) {
			$rid           = 0;
			$embedded_post = null;
		}

		$reservations   = self::get_reservation_options();
		$embedded_title = '';
		$embedded_dates = '';

		if ( $rid > 0 && $embedded_post ) {
			$start          = (string) get_post_meta( $rid, '_en_available_start_date', true );
			$end            = (string) get_post_meta( $rid, '_en_available_end_date', true );
			$embedded_dates = ( '' !== $start && '' !== $end ) ? ( $start . ' – ' . $end ) : '';
			$embedded_title = $embedded_post->post_title;
		}

		// Localize the customer-search endpoint for admin.js (same inline pattern
		// as the stall chart's window.eemStallChart).
		?>
		<script>
			window.eemCreateOrder = window.eemCreateOrder || {};
			window.eemCreateOrder.ajaxUrl             = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			window.eemCreateOrder.searchNonce         = <?php echo wp_json_encode( wp_create_nonce( 'eem_create_order_customer_search' ) ); ?>;
			window.eemCreateOrder.reservationId       = <?php echo wp_json_encode( $rid > 0 ? $rid : null ); ?>;
			window.eemCreateOrder.reservationTitle    = <?php echo wp_json_encode( $embedded_title ); ?>;
			window.eemCreateOrder.createOrderNonce    = <?php echo wp_json_encode( $rid > 0 ? wp_create_nonce( 'eem_admin_create_order' ) : null ); ?>;
			window.eemCreateOrder.ordersUrl           = <?php echo wp_json_encode( admin_url( 'admin.php?page=equine-event-manager-orders' ) ); ?>;
		</script>
		<?php

		eem_render_page_open( array(
			'title'      => __( 'Create Order', 'equine-event-manager' ),
			'subtitle'   => __( 'Manually create a new order on behalf of a customer — phone orders, walk-ins, or anything not coming through the customer-facing reservation form.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders', 'equine-event-manager' ), 'url' => admin_url( 'admin.php?page=equine-event-manager-orders' ) ),
				array( 'label' => __( 'Create Order', 'equine-event-manager' ) ),
			),
		) );
		?>
		<div class="eem-create-order-body">
			<?php
			// C13.B.2.a: when a reservation is embedded, the outer workspace is a <div>
			// (not <form>) because the [en_reservation] shortcode renders its own <form>.
			// Nested <form> elements are invalid HTML and browsers close the outer form
			// at the first inner <form> tag, making contact/notes fields unreachable.
			// B.2.c will collect all fields (outer fields + embedded form) for submission.
			if ( $rid > 0 ) :
			?>
			<div class="eem-co-workspace" id="eem-create-order-form" data-eem-co-has-embed="1">
			<?php else : ?>
			<form class="eem-co-workspace" id="eem-create-order-form" method="post" autocomplete="off">
			<?php endif; ?>
				<div class="eem-co-main">
					<?php
					self::render_customer_lookup_card();

					if ( $rid > 0 ) {
						self::render_reservation_card_picked( $rid, $embedded_title, $embedded_dates );
					} else {
						self::render_reservation_card( $reservations );
					}

					self::render_contact_card();

					if ( $rid > 0 ) {
						// Stall / RV / Add-Ons / Group all come from the embedded shortcode.
						// Group appears inside the embed block (before Custom Items) because
						// the shortcode is a monolith — visual re-ordering is a B.2.polish task.
						self::render_embedded_sections( $rid );
					} else {
						self::render_section_card_stub( 'stall', __( 'Stall Reservations', 'equine-event-manager' ), self::icon( 'stall' ), true );
						self::render_section_card_stub( 'rv', __( 'RV Reservations', 'equine-event-manager' ), self::icon( 'rv' ), false );
						self::render_section_card_stub( 'addons', __( 'Add-Ons', 'equine-event-manager' ), self::icon( 'addon' ), true );
						self::render_section_card_stub( 'group', __( 'Group Reservation', 'equine-event-manager' ), self::icon( 'group' ), false );
					}

					self::render_custom_items_card();
					self::render_special_requests_card();
					?>
				</div>
				<aside class="eem-co-rail">
					<?php
					self::render_summary_card( $embedded_title );
					self::render_payment_card();
					?>
				</aside>
			<?php if ( $rid > 0 ) : ?>
			</div>
			<?php else : ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Card 1 — customer typeahead. Wired to the eem_create_order_customer_search
	 * AJAX endpoint in C13.A.2.
	 *
	 * @return void
	 */
	private static function render_customer_lookup_card(): void {
		?>
		<section class="eem-card eem-co-customer-card" id="eem-co-customer" data-eem-co-customer>
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Look up customer', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-co-cs-header">
					<p class="eem-field-hint"><?php esc_html_e( 'Search by name or email to autofill contact info. Skip to enter a new customer manually.', 'equine-event-manager' ); ?></p>
					<button type="button" class="eem-btn eem-btn-secondary eem-co-cs-skip" data-eem-action="create-order-skip-customer"><?php esc_html_e( 'Skip — new customer', 'equine-event-manager' ); ?></button>
				</div>
				<div class="eem-search-wrap eem-co-cs-input-wrap" data-eem-co-cs-wrap>
					<svg class="eem-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input type="search" class="eem-search-input eem-co-cs-input" placeholder="<?php esc_attr_e( 'Start typing a name or email…', 'equine-event-manager' ); ?>" data-eem-input-action="create-order-customer-search" aria-label="<?php esc_attr_e( 'Customer search', 'equine-event-manager' ); ?>" />
					<div class="eem-co-cs-dropdown" data-eem-co-cs-dropdown hidden></div>
				</div>
				<div class="eem-co-cs-picked" data-eem-co-cs-picked hidden>
					<div>
						<div class="eem-co-cs-picked-name" data-eem-co-cs-picked-name>—</div>
						<div class="eem-co-cs-picked-meta" data-eem-co-cs-picked-meta>—</div>
					</div>
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="create-order-change-customer"><?php esc_html_e( 'Change', 'equine-event-manager' ); ?></button>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Card 2 — reservation picker. The chosen reservation drives which sections,
	 * pricing, and dates are available (sections rendered in C13.B).
	 *
	 * @param array<int, array{id:int,label:string,dates:string}> $reservations Options.
	 * @return void
	 */
	private static function render_reservation_card( array $reservations ): void {
		?>
		<section class="eem-card" id="eem-co-reservation">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo self::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Choose Reservation', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<?php if ( empty( $reservations ) ) : ?>
					<?php
					$new_res_url = admin_url( 'post-new.php?post_type=' . ( class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation' ) );
					?>
					<div class="eem-empty-cta">
						<div class="eem-empty-cta__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
						</div>
						<h3 class="eem-empty-cta__title"><?php esc_html_e( 'Create your first reservation', 'equine-event-manager' ); ?></h3>
						<p class="eem-empty-cta__text"><?php esc_html_e( 'Orders are built against a reservation, so you need one before you can create an order. A reservation defines the event, stalls / RV spaces, add-ons, and the pricing customers are charged.', 'equine-event-manager' ); ?></p>
						<a class="eem-btn eem-btn-amber" href="<?php echo esc_url( $new_res_url ); ?>"><?php esc_html_e( 'Create First Reservation', 'equine-event-manager' ); ?></a>
					</div>
				<?php else : ?>
					<label class="eem-field-label" for="eem-co-reservation-select"><?php esc_html_e( 'Reservation', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label>
					<select class="eem-field-select" id="eem-co-reservation-select" name="reservation_id" data-eem-input-action="create-order-reservation">
						<option value=""><?php esc_html_e( 'Select a reservation…', 'equine-event-manager' ); ?></option>
						<?php foreach ( $reservations as $r ) : ?>
							<option value="<?php echo esc_attr( (string) $r['id'] ); ?>" data-dates="<?php echo esc_attr( $r['dates'] ); ?>"><?php echo esc_html( $r['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="eem-field-hint" style="margin-top:8px"><?php esc_html_e( 'The reservation controls which sections, pricing, and dates are available on this form.', 'equine-event-manager' ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Card 2 (selected state) — reservation picker showing the already-chosen reservation.
	 * Rendered in place of render_reservation_card() when ?reservation_id=N is set.
	 * The "Change" link navigates back to the base Create Order URL, clearing the selection.
	 *
	 * @param int    $rid   Reservation post ID.
	 * @param string $title Reservation post title.
	 * @param string $dates Formatted date range string, or empty when dates are not set.
	 * @return void
	 */
	private static function render_reservation_card_picked( int $rid, string $title, string $dates ): void {
		$base_url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		?>
		<section class="eem-card" id="eem-co-reservation">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo self::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Choose Reservation', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-co-linked-res">
					<div class="eem-co-linked-res__info">
						<div class="eem-co-linked-res__name"><?php echo esc_html( $title ); ?></div>
						<?php if ( '' !== $dates ) : ?>
							<div class="eem-co-linked-res__dates"><?php echo esc_html( $dates ); ?></div>
						<?php endif; ?>
					</div>
					<a href="<?php echo esc_url( $base_url ); ?>" class="eem-co-linked-res__change"><?php esc_html_e( 'Change', 'equine-event-manager' ); ?></a>
				</div>
				<p class="eem-field-hint" style="margin-top:8px"><?php esc_html_e( 'The reservation controls which sections, pricing, and dates are available on this form.', 'equine-event-manager' ); ?></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Embedded reservation-form section block (C13.B.2.a). Calls the [en_reservation]
	 * shortcode to render Stall / RV / Add-Ons / Group sections with the live pricing
	 * engine. Because is_admin() is true, render_form_styles() fires inline inside the
	 * shortcode's ob buffer, so the pricing JS is embedded directly in the output.
	 *
	 * CSS (.eem-co-form-embed) hides everything except data-eem-section=stall/rv/addons/group
	 * and strips the shortcode's own visual chrome (contact, summary rail, payment, submit).
	 *
	 * NOTE: The embedded <form> must live outside the outer workspace <form> (the outer
	 * wrapper becomes a <div> when $rid > 0) to keep HTML valid. B.2.c collects both
	 * outer-form fields and the embedded-form fields for the actual order submission.
	 *
	 * admin_invoice="1" attribute: configures the shortcode for admin-invoice mode —
	 * sets the hidden en_invoice_type='manual' + en_invoice_action_mode='send_payment_link'
	 * control fields and hides the customer-facing billing/payment UI. These hidden
	 * fields travel with the form data when B.2.c collects and submits them.
	 *
	 * @param int $rid Reservation post ID (already validated as published en_reservation).
	 * @return void
	 */
	private static function render_embedded_sections( int $rid ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		// Shortcode output is already escaped by the shortcode renderer; wrapping in
		// wp_kses_post here would strip the <script> tags that carry the pricing engine.
		// The `eem-event-page` class is required: ALL the front-end reservation-form
		// styles in public.css (stall-picker grid, qty steppers, legend, etc.) are
		// scoped under `.eem-event-page`. Without it the admin embed renders unstyled
		// (stall picker collapses to full-width stacked rows). Adding it here makes the
		// admin Create Order form match the customer form pixel-for-pixel.
		echo '<div class="eem-co-form-embed eem-event-page">';
		echo do_shortcode( sprintf( '[en_reservation id="%d" admin_invoice="1"]', $rid ) );
		echo '</div>';
	}

	/**
	 * Card 3 — contact information.
	 *
	 * @return void
	 */
	private static function render_contact_card(): void {
		?>
		<section class="eem-card" id="eem-co-contact">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo self::icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Contact Information', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-co-field-grid">
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'First Name', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label><input class="eem-field-input" type="text" name="first_name" data-eem-co-contact="first_name" placeholder="<?php esc_attr_e( 'First name', 'equine-event-manager' ); ?>" /></div>
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'Last Name', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label><input class="eem-field-input" type="text" name="last_name" data-eem-co-contact="last_name" placeholder="<?php esc_attr_e( 'Last name', 'equine-event-manager' ); ?>" /></div>
				</div>
				<div class="eem-co-field-grid">
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'Email', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label><input class="eem-field-input" type="email" name="email" data-eem-co-contact="email" placeholder="<?php esc_attr_e( 'customer@email.com', 'equine-event-manager' ); ?>" /></div>
					<div class="eem-field-group"><label class="eem-field-label"><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label><input class="eem-field-input" type="tel" name="phone" data-eem-co-contact="phone" placeholder="<?php esc_attr_e( '(555) 000-0000', 'equine-event-manager' ); ?>" /></div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Reservation-driven section card (Stall / RV / Add-Ons / Group). The body is
	 * populated from the chosen reservation's form engine in C13.B; for C13.A.1 it
	 * renders the card chrome + enable toggle + a placeholder note.
	 *
	 * @param string $key       Section key.
	 * @param string $label     Card title.
	 * @param string $icon_html Inline SVG.
	 * @param bool   $enabled   Initial toggle state.
	 * @return void
	 */
	private static function render_section_card_stub( string $key, string $label, string $icon_html, bool $enabled ): void {
		?>
		<section class="eem-card eem-co-section-card" data-eem-co-section="<?php echo esc_attr( $key ); ?>">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php echo esc_html( $label ); ?></h2>
				<button type="button" class="eem-toggle <?php echo $enabled ? 'on' : 'off'; ?>" role="switch" aria-checked="<?php echo $enabled ? 'true' : 'false'; ?>" data-eem-action="create-order-toggle-section" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: section name */ __( 'Toggle %s', 'equine-event-manager' ), $label ) ); ?>"></button>
			</header>
			<div class="eem-card-body eem-co-section-body"<?php echo $enabled ? '' : ' hidden'; ?>>
				<p class="eem-field-hint eem-co-section-placeholder"><?php esc_html_e( 'Configured from the selected reservation. Choose a reservation above to load this section.', 'equine-event-manager' ); ?></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Custom Line Items card — one-off charges not on the reservation. Schema +
	 * persistence land in C13.C; C13.A.1 renders the add/remove UI shell.
	 *
	 * @return void
	 */
	private static function render_custom_items_card(): void {
		?>
		<section class="eem-card" id="eem-co-custom-items">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo self::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Custom Line Items', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<p class="eem-field-hint" style="margin-bottom:12px"><?php esc_html_e( 'Add one-off charges not configured on the reservation. Examples: late fee, damage charge, transferred credit. Each appears on the customer\'s invoice.', 'equine-event-manager' ); ?></p>
				<div class="eem-co-custom-list" data-eem-co-custom-list></div>
				<button type="button" class="eem-btn-add" data-eem-action="create-order-add-custom-item">
					<?php echo self::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Add custom line item', 'equine-event-manager' ); ?>
				</button>
			</div>
		</section>
		<?php
	}

	/**
	 * Special Requests card.
	 *
	 * @return void
	 */
	private static function render_special_requests_card(): void {
		?>
		<section class="eem-card" id="eem-co-special">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php echo self::icon( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Special Requests', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<textarea class="eem-field-textarea" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Any special requests, stall preferences, accessibility needs…', 'equine-event-manager' ); ?>"></textarea>
			</div>
		</section>
		<?php
	}

	/**
	 * Rail — live order summary + discount affordance. Totals are mirrored from
	 * the embedded pricing engine by coSyncTotals() in admin.js (C13.B.2.b).
	 * Discount math and custom-item totalling land in C13.C.
	 *
	 * @param string $embedded_title Reservation title shown as the event-name line
	 *                               when a reservation is embedded (?reservation_id=N).
	 *                               Empty string on the no-embed default state.
	 * @return void
	 */
	private static function render_summary_card( string $embedded_title = '' ): void {
		?>
		<section class="eem-card eem-co-summary-card">
			<header class="eem-card-header eem-co-summary-head">
				<h2 class="eem-card-title"><?php esc_html_e( 'Order Summary', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-co-summary-lines" data-eem-co-summary-lines>
					<?php if ( '' !== $embedded_title ) : ?>
					<div class="eem-co-summary-event" data-eem-co-summary-event><?php echo esc_html( $embedded_title ); ?></div>
					<?php endif; ?>
					<p class="eem-field-hint" data-eem-co-summary-empty><?php esc_html_e( 'Select a reservation and add items to build the order.', 'equine-event-manager' ); ?></p>
				</div>
				<div class="eem-co-discount" data-eem-co-discount>
					<button type="button" class="eem-co-discount-add" data-eem-action="create-order-add-discount">
						<?php echo self::icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Apply discount', 'equine-event-manager' ); ?>
					</button>
					<div class="eem-co-discount-fields" data-eem-co-discount-fields>
						<div class="eem-co-discount-row">
							<select class="eem-field-select eem-co-discount-type" name="eem_discount_type" data-eem-co-discount-type>
								<option value="dollar"><?php esc_html_e( 'Dollar amount ($)', 'equine-event-manager' ); ?></option>
								<option value="percent"><?php esc_html_e( 'Percentage (%)', 'equine-event-manager' ); ?></option>
							</select>
							<div class="eem-co-discount-value-wrap">
								<span class="eem-co-discount-currency" data-eem-co-discount-symbol>$</span>
								<input class="eem-field-input eem-co-discount-value" type="number" step="0.01" min="0" name="eem_discount_value" value="" data-eem-co-discount-value />
							</div>
						</div>
						<input class="eem-field-input eem-co-discount-reason" type="text" name="eem_discount_reason" placeholder="<?php esc_attr_e( 'Reason (required, logged in Activity Log)', 'equine-event-manager' ); ?>" data-eem-co-discount-reason />
						<div class="eem-co-discount-applied-row" data-eem-co-discount-applied hidden>
							<span class="eem-co-discount-applied-label"><?php esc_html_e( 'Discount applied', 'equine-event-manager' ); ?></span>
							<span class="eem-co-discount-applied-value" data-eem-co-discount-applied-value>&minus;$0.00</span>
							<button type="button" class="eem-co-discount-remove" data-eem-action="create-order-remove-discount"><?php esc_html_e( 'Remove', 'equine-event-manager' ); ?></button>
						</div>
					</div>
				</div>
				<hr class="eem-co-summary-divider" />
				<div class="eem-co-summary-total"><span><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></span><span data-eem-co-summary-total><?php echo esc_html( self::money( 0 ) ); ?></span></div>
			</div>
		</section>
		<?php
	}

	/**
	 * Rail — payment hand-off. UI only: "Send Link" and "Charge Card" tabs. Per the
	 * C13 decision, no charge is dispatched here — Send Link emails an invoice (C13
	 * follow-up) and Charge Card links to the C14 Collect Payment page.
	 *
	 * @return void
	 */
	private static function render_payment_card(): void {
		?>
		<section class="eem-card eem-co-payment-card">
			<div class="eem-co-payment-tabs" role="tablist">
				<button type="button" class="eem-co-payment-tab is-active" data-eem-action="create-order-payment-tab" data-tab="link" role="tab" aria-selected="true"><?php echo self::icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Send Link', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-co-payment-tab" data-eem-action="create-order-payment-tab" data-tab="charge" role="tab" aria-selected="false"><?php echo self::icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Charge Card', 'equine-event-manager' ); ?></button>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-co-payment-panel="link">
				<p class="eem-field-hint"><?php esc_html_e( 'Email the customer a secure link to pay their balance online. No card details needed here.', 'equine-event-manager' ); ?></p>
				<label class="eem-field-label" for="eem-co-invoice-msg" style="display:block;margin:10px 0 5px"><?php esc_html_e( 'Personal message', 'equine-event-manager' ); ?> <span class="eem-field-optional">(<?php esc_html_e( 'optional', 'equine-event-manager' ); ?>)</span></label>
				<textarea class="eem-field-textarea" id="eem-co-invoice-msg" name="invoice_message" rows="2" placeholder="<?php esc_attr_e( 'Add a personal note that will appear in the email body…', 'equine-event-manager' ); ?>"></textarea>
				<button type="button" class="eem-btn eem-btn-primary eem-co-btn-block" data-eem-action="create-order-send-link" disabled>
					<?php echo self::icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Send Payment Link', 'equine-event-manager' ); ?>
				</button>
			</div>
			<div class="eem-card-body eem-co-payment-panel" data-eem-co-payment-panel="charge" hidden>
				<p class="eem-field-hint"><?php esc_html_e( 'Creates the order, then takes you to the secured Collect Payment page to enter the card.', 'equine-event-manager' ); ?></p>
				<button type="button" class="eem-btn eem-btn-primary eem-co-btn-block" data-eem-action="create-order-charge" disabled>
					<?php echo self::icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Create Order & Collect Payment', 'equine-event-manager' ); ?>
				</button>
			</div>
			<?php // "Open Tab" — save the order to finish later, without emailing or charging. ?>
			<div class="eem-card-body eem-co-open-tab-row">
				<button type="button" class="eem-btn eem-btn-ghost eem-co-btn-block" data-eem-action="create-order-open-tab" disabled>
					<?php esc_html_e( 'Save as Open Tab', 'equine-event-manager' ); ?>
				</button>
				<p class="eem-field-hint"><?php esc_html_e( 'Saves an unpaid order to finish later — nothing is emailed or charged. Send the invoice or collect payment from the order page anytime.', 'equine-event-manager' ); ?></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Build the reservation-picker options from published reservations.
	 *
	 * @return array<int, array{id:int,label:string,dates:string}>
	 */
	private static function get_reservation_options(): array {
		$posts = get_posts( array(
			'post_type'      => EEM_Reservations_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$out = array();
		foreach ( $posts as $p ) {
			$start = (string) get_post_meta( $p->ID, '_en_available_start_date', true );
			$end   = (string) get_post_meta( $p->ID, '_en_available_end_date', true );
			$dates = ( '' !== $start && '' !== $end ) ? ( $start . ' – ' . $end ) : '';
			$out[] = array(
				'id'    => (int) $p->ID,
				'label' => '' !== $dates ? ( $p->post_title . ' (' . $dates . ')' ) : $p->post_title,
				'dates' => $dates,
			);
		}
		return $out;
	}

	/**
	 * AJAX — customer typeahead for the lookup card. Returns up to 8 customers
	 * (aggregated by email from existing orders) matching the search term.
	 *
	 * @return void
	 */
	public static function ajax_customer_search(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_create_order_customer_search', '_wpnonce' );

		$term    = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$results = array();
		if ( strlen( $term ) >= 2 && class_exists( 'EEM_Customer_Profile_Repo' ) ) {
			$repo = new EEM_Customer_Profile_Repo();
			$list = $repo->get_customer_list( array( 'search' => $term, 'per_page' => 8, 'paged' => 1 ) );
			$rows = isset( $list['rows'] ) && is_array( $list['rows'] ) ? $list['rows'] : array();
			foreach ( $rows as $r ) {
				$results[] = array(
					'name'   => (string) ( $r['name'] ?? '' ),
					'email'  => (string) ( $r['email'] ?? '' ),
					'orders' => (int) ( $r['orders'] ?? 0 ),
				);
			}
		}
		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX — section config for a chosen reservation. Drives which section cards
	 * are enabled, their rate labels, the available dates, and the rail event name.
	 * (Full interactive pricing/steppers reuse the customer-form engine in C13.B.2.)
	 *
	 * @return void
	 */
	public static function ajax_reservation_meta(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_create_order_customer_search', '_wpnonce' );

		$rid  = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$post = $rid ? get_post( $rid ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		$cpt = new EEM_Reservations_CPT();
		$d   = $cpt->get_meta_values( $rid );

		$start = (string) ( $d['available_start_date'] ?? '' );
		$end   = (string) ( $d['available_end_date'] ?? '' );

		$stall_rate = (float) ( $d['stall_nightly_rate'] ?? 0 );
		$rv_rate    = (float) ( $d['rv_nightly_rate'] ?? 0 );

		wp_send_json_success( array(
			'title'    => $post->post_title,
			'dates'    => ( '' !== $start && '' !== $end ) ? ( $start . ' – ' . $end ) : '',
			'sections' => array(
				'stall'  => array(
					'enabled' => ! empty( $d['stalls_enabled'] ),
					'label'   => sprintf( /* translators: %s: nightly rate */ __( 'Stalls — %s/night', 'equine-event-manager' ), self::money( $stall_rate ) ),
				),
				'rv'     => array(
					'enabled' => ! empty( $d['rv_enabled'] ),
					'label'   => sprintf( /* translators: %s: nightly rate */ __( 'RV Spots — %s/night', 'equine-event-manager' ), self::money( $rv_rate ) ),
				),
				'addons' => array(
					'enabled' => ! empty( $d['general_addons_enabled'] ),
					'label'   => __( 'Add-ons configured on this reservation.', 'equine-event-manager' ),
				),
				'group'  => array(
					'enabled' => ! empty( $d['group_reservations_enabled'] ),
					'label'   => __( 'Group reservation available on this reservation.', 'equine-event-manager' ),
				),
			),
		) );
	}

	/**
	 * AJAX — create an admin-initiated order from the embedded [en_reservation] form
	 * submission (C13.B.2.c). Called by coSubmitOrder() in admin.js when the admin
	 * clicks "Send Payment Link" on the Create Order page with a reservation embedded.
	 *
	 * Strategy: inject admin-invoice control fields into $_POST, hook eem_order_created
	 * to capture the new order_key before running do_shortcode(), which internally calls
	 * handle_reservation_submission() → insert_reservation_orders(). The rendered HTML
	 * string is discarded; only the eem_order_created side-effect matters.
	 *
	 * The caller (JS) is responsible for passing all embedded-form field values (stall
	 * selection, dates, qty, nonces) plus the admin contact fields (first_name etc.).
	 * The handler overrides the invoice-type control fields to enforce the unpaid path.
	 *
	 * @return void
	 */
	public static function ajax_create_order(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_admin_create_order', '_wpnonce' );

		$rid  = isset( $_POST['en_reservation_id'] ) ? absint( wp_unslash( $_POST['en_reservation_id'] ) ) : 0;
		$post = $rid ? get_post( $rid ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		// Collect the order-level adjustments (custom line items + discount) up
		// front so the discount's required-reason rule can be validated BEFORE the
		// order rows are written. Rejecting after creation would orphan an order.
		$custom_items = self::collect_custom_items_from_post();
		$discount     = self::collect_discount_from_post();
		if ( null !== $discount && '' === $discount['reason'] ) {
			wp_send_json_error( array(
				'message' => __( 'A reason is required to apply a discount.', 'equine-event-manager' ),
				'code'    => 'discount_reason_required',
			), 422 );
		}
		// A percentage discount above 100% is rejected outright. The resolved
		// dollar amount is clamped to the subtotal regardless, but storing/
		// logging a >100% value is a data-entry error that should be corrected,
		// not silently capped.
		if ( null !== $discount
			&& EEM_Order_Adjustments_Repo::DISCOUNT_PERCENT === $discount['type']
			&& $discount['value'] > 100 ) {
			wp_send_json_error( array(
				'message' => __( 'A percentage discount cannot exceed 100%.', 'equine-event-manager' ),
				'code'    => 'discount_percent_too_large',
			), 422 );
		}

		// Force admin-invoice / unpaid path regardless of what the JS sent.
		// This ensures no charge is dispatched and the order is created as pending.
		// "Open Tab" (en_open_tab=1) creates the order WITHOUT emailing a payment
		// link — the admin sends the invoice or collects payment later. Otherwise
		// the order is created and a payment-link email is sent.
		$open_tab = isset( $_POST['en_open_tab'] ) && '1' === (string) wp_unslash( $_POST['en_open_tab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked at handler top.
		$_POST['en_invoice_type']        = 'manual';
		$_POST['en_invoice_action_mode'] = $open_tab ? 'save_only' : 'send_payment_link';

		// Normalize phone to international format. The existing pipeline's validate_submission()
		// requires a leading '+'. Admin contact cards don't enforce this, so we prepend '+1 '
		// (US/CA default) when the submitted phone lacks a country-code prefix.
		if ( isset( $_POST['phone'] ) && '' !== (string) wp_unslash( $_POST['phone'] ) ) {
			$raw_phone = (string) wp_unslash( $_POST['phone'] );
			if ( '+' !== substr( ltrim( $raw_phone ), 0, 1 ) ) {
				$_POST['phone'] = '+1 ' . ltrim( $raw_phone );
			}
		}

		// Capture the created order's key via the eem_order_created action hook.
		// The hook fires inside insert_reservation_orders() on success.
		$captured_order_key = null;
		add_action(
			'eem_order_created',
			static function ( array $payload ) use ( &$captured_order_key ): void {
				$captured_order_key = isset( $payload['order_key'] ) ? (string) $payload['order_key'] : null;
			}
		);

		// Run the shortcode. Because $_POST contains the reservation submission fields
		// (en_reservation_action='submit_reservation', en_reservation_id, nonce, etc.)
		// and REQUEST_METHOD is POST (AJAX), is_current_reservation_submission() returns
		// true and handle_reservation_submission() fires. The HTML output is discarded.
		//
		// IMPORTANT: render_reservation() calls render_form_styles() BEFORE ob_start(),
		// so render_form_styles() would write its <script> block directly to stdout
		// (the HTTP response) rather than into the shortcode's ob buffer. We wrap the
		// entire do_shortcode() call in a second buffer to capture and discard that
		// direct output, keeping the AJAX response clean JSON.
		ob_start();
		do_shortcode( sprintf( '[en_reservation id="%d" admin_invoice="1"]', $rid ) );
		ob_end_clean();

		if ( null === $captured_order_key || '' === $captured_order_key ) {
			wp_send_json_error( array(
				'message' => __( 'Order could not be created. Please check the form fields and try again.', 'equine-event-manager' ),
				'code'    => 'create_failed',
			), 422 );
		}

		// Persist the collected adjustments against the freshly created order.
		self::persist_adjustments( $captured_order_key, $custom_items, $discount );

		// Charge mode ("Create Order & Collect Payment") routes straight to the
		// secured Collect Payment page for the new order; otherwise land on the
		// Order Detail page (the payment-link email has already been sent).
		$collect_after = isset( $_POST['en_collect_after'] ) && '1' === (string) wp_unslash( $_POST['en_collect_after'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked at handler top.

		if ( $collect_after && class_exists( 'EEM_Orders_List_Page' ) ) {
			$redirect_url = EEM_Orders_List_Page::collect_payment_url( $captured_order_key );
			$message      = __( 'Order created — opening Collect Payment.', 'equine-event-manager' );
		} else {
			$redirect_url = class_exists( 'EEM_Orders_List_Page' )
				? EEM_Orders_List_Page::order_detail_url( $captured_order_key )
				: admin_url( 'admin.php?page=equine-event-manager-orders' );
			$message      = $open_tab
				? __( 'Open tab saved — no email sent. Send the invoice or collect payment from this order anytime.', 'equine-event-manager' )
				: __( 'Order created and payment link sent.', 'equine-event-manager' );
		}

		wp_send_json_success( array(
			'order_key' => $captured_order_key,
			'redirect'  => $redirect_url,
			'message'   => $message,
		) );
	}

	/**
	 * Collect custom line items from the submitted POST payload.
	 *
	 * Reads the parallel custom_item_desc[] / custom_item_amount[] arrays the
	 * Create Order JS serializes (one entry per row, index-aligned). Parallel
	 * arrays avoid the reindex-on-remove churn nested-index names would require.
	 * Rows with an empty description are dropped — they're incomplete and the repo
	 * rejects them anyway. Amounts may be negative (a credit/comp).
	 *
	 * @return array<int, array{description:string, amount:float}> Cleaned items.
	 */
	private static function collect_custom_items_from_post(): array {
		if ( ! isset( $_POST['custom_item_desc'] ) || ! is_array( $_POST['custom_item_desc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
			return array();
		}

		$descriptions = array_values( (array) wp_unslash( $_POST['custom_item_desc'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$amounts      = isset( $_POST['custom_item_amount'] ) && is_array( $_POST['custom_item_amount'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_values( (array) wp_unslash( $_POST['custom_item_amount'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to float below.
			: array();

		$items = array();
		foreach ( $descriptions as $i => $raw_desc ) {
			$description = sanitize_text_field( (string) $raw_desc );
			if ( '' === trim( $description ) ) {
				continue;
			}
			$amount  = isset( $amounts[ $i ] ) ? (float) $amounts[ $i ] : 0.0;
			$items[] = array( 'description' => $description, 'amount' => round( $amount, 2 ) );
		}

		return $items;
	}

	/**
	 * Collect the discount definition from the submitted POST payload.
	 *
	 * Returns null when no discount is being applied (value <= 0). When a discount
	 * IS applied, returns its type/value/reason — the caller validates that the
	 * reason is non-empty before allowing the order to be created.
	 *
	 * @return array{type:string, value:float, reason:string}|null
	 */
	private static function collect_discount_from_post(): ?array {
		$value = isset( $_POST['eem_discount_value'] ) ? (float) wp_unslash( $_POST['eem_discount_value'] ) : 0.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		if ( $value <= 0 ) {
			return null;
		}

		$type = isset( $_POST['eem_discount_type'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['eem_discount_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		$type = ( EEM_Order_Adjustments_Repo::DISCOUNT_PERCENT === $type ) ? EEM_Order_Adjustments_Repo::DISCOUNT_PERCENT : EEM_Order_Adjustments_Repo::DISCOUNT_DOLLAR;

		$reason = isset( $_POST['eem_discount_reason'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['eem_discount_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.

		return array( 'type' => $type, 'value' => round( $value, 2 ), 'reason' => trim( $reason ) );
	}

	/**
	 * Persist the collected adjustments against a created order and log the
	 * discount to the Activity Log.
	 *
	 * The discount resolves against the order subtotal (component subtotals +
	 * custom-item total) so the snapshotted reduction matches the total the admin
	 * saw. Failures here don't roll back the order — the order is valid without
	 * adjustments; a failed adjustment write is surfaced via the Activity Log gap
	 * rather than discarding a created order.
	 *
	 * @param string                                              $order_key    Created order key.
	 * @param array<int, array{description:string, amount:float}> $custom_items Cleaned custom items.
	 * @param array{type:string, value:float, reason:string}|null $discount     Discount, or null.
	 * @return void
	 */
	private static function persist_adjustments( string $order_key, array $custom_items, ?array $discount ): void {
		if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			return;
		}

		EEM_Order_Adjustments_Repo::replace_custom_items( $order_key, $custom_items );

		if ( null === $discount ) {
			return;
		}

		// Subtotal the discount applies to: component subtotals + custom items.
		$subtotal = 0.0;
		foreach ( $custom_items as $item ) {
			$subtotal += $item['amount'];
		}
		if ( class_exists( 'EEM_Orders_Repository' ) ) {
			$repo  = new EEM_Orders_Repository();
			$order = $repo->get_order( $order_key );
			if ( is_array( $order ) ) {
				$subtotal += isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
				$subtotal += isset( $order['rv_subtotal'] ) ? (float) $order['rv_subtotal'] : 0.0;
			}
		}

		$discount_id = EEM_Order_Adjustments_Repo::set_discount(
			$order_key,
			$discount['type'],
			$discount['value'],
			$discount['reason'],
			$subtotal
		);

		if ( false !== $discount_id && class_exists( 'EEM_Activity_Log' ) ) {
			$resolved = EEM_Order_Adjustments_Repo::resolve_discount_amount( $discount['type'], $discount['value'], $subtotal );
			EEM_Activity_Log::write(
				'order_discount_applied',
				array(
					'order_key'      => $order_key,
					'discount_type'  => $discount['type'],
					'discount_value' => $discount['value'],
					'discount_amount' => $resolved,
					'reason'         => $discount['reason'],
				),
				array(
					'actor_type'  => 'admin',
					'actor_id'    => get_current_user_id(),
				)
			);
		}
	}

	/**
	 * Format a money value for display.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( float $amount ): string {
		return '$' . number_format_i18n( $amount, 2 );
	}

	/**
	 * Inline SVG icon by key (stroke icons matching the mockup). Returns safe,
	 * static markup.
	 *
	 * @param string $key Icon key.
	 * @return string
	 */
	private static function icon( string $key ): string {
		$paths = array(
			'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
			'user'     => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
			'stall'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
			'rv'       => '<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
			'addon'    => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
			'group'    => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
			'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
			'message'  => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
			'tag'      => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
			'mail'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
			'card'     => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
			'send'     => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
		);
		$inner = isset( $paths[ $key ] ) ? $paths[ $key ] : '';
		return '<svg class="eem-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $inner . '</svg>';
	}
}
