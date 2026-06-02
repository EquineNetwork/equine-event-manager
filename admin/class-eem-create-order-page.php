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

		$reservations = self::get_reservation_options();

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
			<form class="eem-co-workspace" id="eem-create-order-form" method="post" autocomplete="off">
				<div class="eem-co-main">
					<?php
					self::render_customer_lookup_card();
					self::render_reservation_card( $reservations );
					self::render_contact_card();
					self::render_section_card_stub( 'stall', __( 'Stall Reservations', 'equine-event-manager' ), self::icon( 'stall' ), true );
					self::render_section_card_stub( 'rv', __( 'RV Reservations', 'equine-event-manager' ), self::icon( 'rv' ), false );
					self::render_section_card_stub( 'addons', __( 'Add-Ons', 'equine-event-manager' ), self::icon( 'addon' ), true );
					self::render_custom_items_card();
					self::render_section_card_stub( 'group', __( 'Group Reservation', 'equine-event-manager' ), self::icon( 'group' ), false );
					self::render_special_requests_card();
					?>
				</div>
				<aside class="eem-co-rail">
					<?php
					self::render_summary_card();
					self::render_payment_card();
					?>
				</aside>
			</form>
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
					<p class="eem-field-hint"><?php esc_html_e( 'No published reservations found. Publish a reservation first, then return here to build an order against it.', 'equine-event-manager' ); ?></p>
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
	 * Rail — live order summary + discount affordance. Totals + discount math land
	 * in C13.C; C13.A.1 renders the container the summary populates into.
	 *
	 * @return void
	 */
	private static function render_summary_card(): void {
		?>
		<section class="eem-card eem-co-summary-card">
			<header class="eem-card-header eem-co-summary-head">
				<h2 class="eem-card-title"><?php esc_html_e( 'Order Summary', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-co-summary-lines" data-eem-co-summary-lines>
					<p class="eem-field-hint" data-eem-co-summary-empty><?php esc_html_e( 'Select a reservation and add items to build the order.', 'equine-event-manager' ); ?></p>
				</div>
				<div class="eem-co-discount" data-eem-co-discount>
					<button type="button" class="eem-co-discount-add" data-eem-action="create-order-add-discount">
						<?php echo self::icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Apply discount', 'equine-event-manager' ); ?>
					</button>
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
		$collect_url = admin_url( 'admin.php?page=equine-event-manager-collect-payment' );
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
				<p class="eem-field-hint"><?php esc_html_e( 'Charging a card happens on the Collect Payment page, where card entry is secured. Create the order first, then collect payment.', 'equine-event-manager' ); ?></p>
				<a class="eem-btn eem-btn-primary eem-co-btn-block eem-co-collect-link" href="<?php echo esc_url( $collect_url ); ?>">
					<?php echo self::icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?> <?php esc_html_e( 'Go to Collect Payment', 'equine-event-manager' ); ?>
				</a>
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
