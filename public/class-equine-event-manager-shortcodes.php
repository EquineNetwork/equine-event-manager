<?php
/**
 * Shortcodes for Equine Event Manager.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders reservation shortcodes.
 */
class EEM_Shortcodes {

	const DEFAULT_SPECIAL_REQUESTS_DESCRIPTION = 'Please let us know if you have any special requests for your stay including stallion accommodations, preferred contestant proximity stalling, etc.';
	const SUBMISSION_TOKEN_TRANSIENT_PREFIX = 'equine_event_manager_submission_';
	const DEFAULT_PREOPEN_MESSAGE = 'Reservations for [event_name] will open on [open_date_time]. If you have questions please call [phone].';
	const DEFAULT_CLOSED_MESSAGE = 'Reservations for [event_name] are now closed. Please call [phone] for assistance.';
	const RECEIPT_SETTINGS_OPTION = 'equine_event_manager_receipt_settings';
	const COMPANY_SETTINGS_OPTION = 'equine_event_manager_company_settings';
	const DEFAULT_CUSTOMER_RECEIPT_SUBJECT = 'Your reservation receipt for [event_name]';
	const DEFAULT_ADMIN_RECEIPT_SUBJECT = 'New reservation received for [event_name]';
	const DEFAULT_CUSTOMER_RECEIPT_BODY = "Hi [customer_name],\n\nThank you for your reservation for [event_name]. Your order number is [order_number] and the total amount due is [total].\n\nIf you have questions, please contact us at [support_phone] or [support_email].";
	const DEFAULT_ADMIN_RECEIPT_BODY = "A new reservation has been received for [event_name].\n\nOrder Number: [order_number]\nCustomer: [customer_name]\nTotal: [total]";

	/**
	 * Track submission tokens already processed in the current request.
	 *
	 * @var array<string, bool>
	 */
	private static $processed_submission_tokens = array();

	/**
	 * Track whether frontend reservation form assets should be printed in the footer.
	 *
	 * @var bool
	 */
	private static $reservation_form_assets_needed = false;

	/**
	 * Register shortcodes.
	 */
	public function register() {
		add_shortcode( 'en_reservation', array( $this, 'render_reservation' ) );
		add_shortcode( 'en_stall_reservation_form', array( $this, 'render_stall_reservation_form' ) );
		add_shortcode( 'en_rv_reservation_form', array( $this, 'render_rv_reservation_form' ) );

		// Deprecated alias kept for any Elementor templates / posts that still embed the long form.
		// New pages should use [en_reservation id="N"] (or the event-id variants above).
		add_shortcode( 'equine_event_manager_event_reservation', array( $this, 'render_event_reservation_shortcode' ) );
		add_action( 'wp_footer', array( $this, 'render_frontend_form_assets_in_footer' ), 5 );
	}

	/**
	 * Render the reservation linked to the current event.
	 *
	 * This is intended for Elementor event templates so a single shortcode
	 * can render whatever reservation setup is linked to the current TEC event.
	 *
	 * @deprecated Use [en_reservation id="N"] for new pages, or
	 *             [en_stall_reservation_form event_id="N"] / [en_rv_reservation_form event_id="N"]
	 *             when the template only has the event id available. This alias remains so legacy
	 *             Elementor templates already wired to [equine_event_manager_event_reservation]
	 *             keep rendering.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_event_reservation_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id' => 0,
			),
			$atts,
			'equine_event_manager_event_reservation'
		);

		$event_id = absint( $atts['event_id'] );

		if ( ! $event_id ) {
			$event_id = get_the_ID();
		}

		if ( ! $event_id ) {
			return '';
		}

		$reservation_id = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );

		if ( ! $reservation_id && 'tribe_events' === get_post_type( $event_id ) ) {
			$stored_shortcode = (string) get_post_meta( $event_id, 'reservations', true );

			if ( preg_match( '/id="(\d+)"/', $stored_shortcode, $matches ) ) {
				$reservation_id = absint( $matches[1] );
			}
		}

		if ( ! $reservation_id ) {
			return '';
		}

		return do_shortcode( sprintf( '[en_reservation id="%d"]', $reservation_id ) );
	}

	/**
	 * Render the reservation form for a reservation setup.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_reservation( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'            => 0,
				'admin_invoice' => 0,
				'show_event_header' => 1,
			),
			$atts,
			'en_reservation'
		);

		$reservation_id   = absint( $atts['id'] );
		$is_admin_invoice = ! empty( $atts['admin_invoice'] );
		$show_event_header = '0' !== (string) $atts['show_event_header'];
		$reservation      = $reservation_id ? get_post( $reservation_id ) : null;

		if ( ! $reservation || 'en_reservation' !== $reservation->post_type || 'publish' !== $reservation->post_status ) {
			return $this->render_notice( __( 'Reservations are not available for this event yet.', 'equine-event-manager' ) );
		}

		$data    = $this->get_reservation_meta( $reservation_id );
		$status  = $this->get_reservation_status( $data, $reservation_id );
		$message = '';
		$min_date = ! empty( $data['available_start_date'] ) ? $data['available_start_date'] : '';
		$max_date = ! empty( $data['available_end_date'] ) ? $data['available_end_date'] : '';
		$stall_weekend_start_date   = $this->get_weekend_package_start_date( $data, 'stall' );
		$stall_weekend_end_date     = $this->get_weekend_package_end_date( $data, 'stall' );
		$rv_weekend_start_date      = $this->get_weekend_package_start_date( $data, 'rv' );
		$rv_weekend_end_date        = $this->get_weekend_package_end_date( $data, 'rv' );
		$stall_stay_type_options    = $this->get_enabled_stay_type_options( $data, 'stall' );
		$rv_stay_type_options       = $this->get_enabled_stay_type_options( $data, 'rv' );
		$rv_addon_options           = $this->get_enabled_rv_addon_options( $data );
		$general_addon_options      = $this->get_enabled_general_addon_options( $data );
		$rv_lot_options             = $this->get_enabled_rv_lots( $data );
		$stall_assignment_enabled   = ! empty( $data['stall_chart_enabled'] );
		$stall_map_url              = ! empty( $data['stall_map_file_id'] ) ? wp_get_attachment_url( absint( $data['stall_map_file_id'] ) ) : '';
		$stall_assignment_blocks    = $stall_assignment_enabled ? $this->get_stall_assignment_option_groups( $data ) : array();
		$stall_selection_context    = array();
		$rv_default_lot             = '';
		$stall_default_stay_type    = array_key_first( $stall_stay_type_options );
		$rv_default_stay_type       = array_key_first( $rv_stay_type_options );
		$stall_default_arrival_date = 'weekend' === $stall_default_stay_type ? $stall_weekend_start_date : $min_date;
		$stall_default_departure_date = 'weekend' === $stall_default_stay_type ? $stall_weekend_end_date : ( $max_date ? $max_date : $min_date );
		$rv_default_arrival_date    = 'weekend' === $rv_default_stay_type ? $rv_weekend_start_date : $min_date;
		$rv_default_departure_date  = 'weekend' === $rv_default_stay_type ? $rv_weekend_end_date : ( $max_date ? $max_date : $min_date );
		$stall_default_night_count  = $this->get_selected_night_count( $stall_default_arrival_date, $stall_default_departure_date );
		$rv_default_night_count     = $this->get_selected_night_count( $rv_default_arrival_date, $rv_default_departure_date );
		$special_requests_description = $this->get_special_requests_description();
		$nightly_date_summary         = '';
		$stall_weekend_date_summary   = '';
		$rv_weekend_date_summary      = '';
		$stall_early_bird_active      = $this->is_early_bird_active( $data, 'stall' );
		$rv_early_bird_active         = $this->is_early_bird_active( $data, 'rv' );
		$stall_early_bird_notice      = $this->get_early_bird_notice( $data, 'stall' );
		$rv_early_bird_notice         = $this->get_early_bird_notice( $data, 'rv' );
		$payment_settings             = $this->get_payment_settings();
		$stripe_config                = $this->get_active_stripe_configuration( $payment_settings );
		$stripe_card_enabled          = 'stripe' === $payment_settings['selected_gateway'] && ! empty( $stripe_config['publishable_key'] ) && ! empty( $stripe_config['secret_key'] );
		$authorize_net_config        = $this->get_active_authorize_net_configuration( $payment_settings );
		$authorize_card_enabled      = 'authorize_net' === $payment_settings['selected_gateway'] && ! empty( $authorize_net_config['api_login'] ) && ! empty( $authorize_net_config['transaction_key'] );
		$sync_stay_selections         = false;
		$submission_token             = wp_generate_uuid4();
		$event_label                  = $this->get_reservation_event_label( $reservation, $data );
		$event_date_summary           = $this->get_reservation_event_date_summary( $data );
		$company_settings             = $this->get_company_settings();
		$support_phone                = ! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '';
		$event_card_details           = $this->get_reservation_event_card_details( $data );
		$normalized_event_data        = class_exists( 'EEM_Events' ) ? EEM_Events::get_normalized_reservation_event_data( $reservation_id ) : array();
		$venue_map_url                = ! empty( $data['venue_map_image_id'] ) ? wp_get_attachment_image_url( absint( $data['venue_map_image_id'] ), 'large' ) : '';
		$venue_map_download_url       = ! empty( $data['venue_map_download_url'] ) ? esc_url( $data['venue_map_download_url'] ) : ( $venue_map_url ? esc_url( $venue_map_url ) : '' );
		$show_venue_map               = ! empty( $data['venue_map_enabled'] ) && ! empty( $venue_map_download_url );
		$venue_agreement_url          = ! empty( $data['venue_agreement_file_id'] ) ? wp_get_attachment_url( absint( $data['venue_agreement_file_id'] ) ) : '';
		$checkin_checkout_enabled     = ! empty( $data['checkin_checkout_enabled'] );
		$checkin_time                 = $checkin_checkout_enabled && ! empty( $data['checkin_time'] ) ? $this->format_time_label( $data['checkin_time'] ) : '';
		$checkout_time                = $checkin_checkout_enabled && ! empty( $data['checkout_time'] ) ? $this->format_time_label( $data['checkout_time'] ) : '';
		$group_reservations_enabled   = ! empty( $data['group_reservations_enabled'] );
		$group_grounds_fee_enabled    = ! empty( $data['group_rider_grounds_fee_enabled'] ) && (float) $data['group_rider_grounds_fee_amount'] > 0;
		$group_deposit_enabled        = ! empty( $data['group_rider_deposit_enabled'] ) && (float) $data['group_rider_deposit_amount'] > 0;
		$show_section_toggles         = ( ! empty( $data['stalls_enabled'] ) ? 1 : 0 ) + ( ! empty( $data['rv_enabled'] ) ? 1 : 0 ) > 1;
		$reservation_description      = ! empty( $data['reservation_description'] ) ? trim( (string) $data['reservation_description'] ) : '';

		if ( $min_date || $max_date ) {
			$nightly_date_summary = sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'Available reservation dates: %1$s to %2$s.', 'equine-event-manager' ),
				$min_date ? $this->format_date_label( $min_date ) : __( 'Any start date', 'equine-event-manager' ),
				$max_date ? $this->format_date_label( $max_date ) : __( 'Any end date', 'equine-event-manager' )
			);
		}

		if ( ! empty( $data['stall_weekend_enabled'] ) && ( $stall_weekend_start_date || $stall_weekend_end_date ) ) {
			$stall_weekend_date_summary = sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'Weekend Rate package dates: %1$s to %2$s.', 'equine-event-manager' ),
				$stall_weekend_start_date ? $this->format_date_label( $stall_weekend_start_date ) : __( 'Package start date unavailable', 'equine-event-manager' ),
				$stall_weekend_end_date ? $this->format_date_label( $stall_weekend_end_date ) : __( 'Package end date unavailable', 'equine-event-manager' )
			);
		}

		if ( ! empty( $data['rv_weekend_enabled'] ) && ( $rv_weekend_start_date || $rv_weekend_end_date ) ) {
			$rv_weekend_date_summary = sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'Weekend Rate package dates: %1$s to %2$s.', 'equine-event-manager' ),
				$rv_weekend_start_date ? $this->format_date_label( $rv_weekend_start_date ) : __( 'Package start date unavailable', 'equine-event-manager' ),
				$rv_weekend_end_date ? $this->format_date_label( $rv_weekend_end_date ) : __( 'Package end date unavailable', 'equine-event-manager' )
			);
		}

		$form_anchor_id      = 'reservation';
		$current_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$form_action_url     = $current_request_uri ? home_url( $current_request_uri ) . '#' . $form_anchor_id : '#' . $form_anchor_id;

		if ( $this->is_current_reservation_submission( $reservation_id ) ) {
			$message = $this->handle_reservation_submission( $reservation_id, $data, $status );
		}

		$rv_default_lot = $this->get_default_rv_lot_key( $rv_lot_options, $status );
		$stall_selection_context = array(
			'stall_early_bird_active' => $stall_early_bird_active,
			'stall_assignment_enabled' => $stall_assignment_enabled,
			'stall_assignment_blocks' => $stall_assignment_blocks,
			'stall_map_url' => $stall_map_url,
		);

		if ( $is_admin_invoice || is_admin() ) {
			$this->render_form_styles();
		} else {
			self::$reservation_form_assets_needed = true;
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $form_anchor_id ); ?>" class="eem-reservation-form-wrap" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" tabindex="-1">
			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( $show_event_header && ! $is_admin_invoice ) : ?>
				<div class="eem-reservation-event-hero">
					<div class="eem-reservation-event-hero__media">
						<div class="eem-reservation-event-media-card">
							<div class="eem-reservation-event-media-card__visual<?php echo ! empty( $normalized_event_data['featured_image'] ) ? ' has-image' : ''; ?>">
								<?php if ( ! empty( $normalized_event_data['featured_image'] ) ) : ?>
									<img src="<?php echo esc_url( $normalized_event_data['featured_image'] ); ?>" alt="<?php echo esc_attr( $event_label ); ?>" />
								<?php else : ?>
									<div class="eem-reservation-event-media-card__placeholder">
										<span class="eem-reservation-event-media-card__placeholder-icon" aria-hidden="true">EVENT</span>
										<strong><?php esc_html_e( 'Event Image', 'equine-event-manager' ); ?></strong>
									</div>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $normalized_event_data['flyer_url'] ) ) : ?>
								<div class="eem-reservation-event-media-card__actions">
									<a class="eem-reservation-event-media-card__button" href="<?php echo esc_url( $normalized_event_data['flyer_url'] ); ?>" target="_blank" rel="noopener noreferrer" onclick="window.open(this.href,'_blank','noopener'); return false;"><?php esc_html_e( 'View Event Flyer PDF', 'equine-event-manager' ); ?></a>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="eem-event-details-card">
						<div class="eem-event-details-card__eyebrow"><?php esc_html_e( 'Event Reservations', 'equine-event-manager' ); ?></div>
						<h2 class="eem-event-details-card__title"><?php echo esc_html( $event_label ); ?></h2>
						<?php if ( $event_card_details['venue_name'] || $event_card_details['location'] || $event_date_summary || $support_phone ) : ?>
							<div class="eem-event-details-card__facts">
								<?php if ( $event_card_details['venue_name'] || $event_card_details['location'] ) : ?>
									<div class="eem-event-details-card__fact">
										<span class="eem-event-details-card__fact-icon" aria-hidden="true">
											<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
												<path d="M12 22s7-6.2 7-13a7 7 0 1 0-14 0c0 6.8 7 13 7 13Zm0-9.5A2.5 2.5 0 1 1 12 7a2.5 2.5 0 0 1 0 5.5Z" fill="currentColor"></path>
											</svg>
										</span>
										<?php if ( $event_card_details['venue_name'] ) : ?>
											<strong><?php echo esc_html( $event_card_details['venue_name'] ); ?></strong>
										<?php endif; ?>
										<?php if ( $event_card_details['venue_name'] && $event_card_details['location'] ) : ?>
											<span class="eem-event-details-card__fact-separator" aria-hidden="true">&middot;</span>
										<?php endif; ?>
										<?php if ( $event_card_details['location'] ) : ?>
											<span><?php echo esc_html( $event_card_details['location'] ); ?></span>
										<?php endif; ?>
									</div>
								<?php endif; ?>
								<?php if ( $event_date_summary ) : ?>
									<div class="eem-event-details-card__fact">
										<span class="eem-event-details-card__fact-icon" aria-hidden="true">
											<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
												<path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v11a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm13 8H4v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8ZM5 6a1 1 0 0 0-1 1v1h16V7a1 1 0 0 0-1-1H5Z" fill="currentColor"></path>
											</svg>
										</span>
										<strong><?php echo esc_html( $event_date_summary ); ?></strong>
									</div>
								<?php endif; ?>
								<?php if ( $support_phone ) : ?>
									<div class="eem-event-details-card__fact">
										<span class="eem-event-details-card__fact-icon" aria-hidden="true">
											<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
												<path d="M6.6 3A2.6 2.6 0 0 0 4 5.6c0 7.95 6.45 14.4 14.4 14.4a2.6 2.6 0 0 0 2.6-2.6v-2.03c0-.63-.45-1.17-1.06-1.29l-4.05-.81a1.5 1.5 0 0 0-1.43.46l-.9.99a11.54 11.54 0 0 1-4.28-4.28l.99-.9a1.5 1.5 0 0 0 .46-1.43l-.81-4.05A1.31 1.31 0 0 0 8.63 3H6.6Z" fill="currentColor"></path>
											</svg>
										</span>
										<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $company_settings['support_phone'] ) ); ?>"><?php echo esc_html( $support_phone ); ?></a>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<?php if ( $checkin_time || $checkout_time ) : ?>
							<div class="eem-event-details-card__times">
								<?php if ( $checkin_time ) : ?>
									<div class="eem-event-details-card__time-card">
										<span class="eem-event-details-card__time-label"><?php esc_html_e( 'Check-In', 'equine-event-manager' ); ?></span>
										<strong class="eem-event-details-card__time-value"><?php echo esc_html( $checkin_time ); ?></strong>
									</div>
								<?php endif; ?>
								<?php if ( $checkout_time ) : ?>
									<div class="eem-event-details-card__time-card">
										<span class="eem-event-details-card__time-label"><?php esc_html_e( 'Check-Out', 'equine-event-manager' ); ?></span>
										<strong class="eem-event-details-card__time-value"><?php echo esc_html( $checkout_time ); ?></strong>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<?php if ( $show_venue_map ) : ?>
							<div class="eem-event-details-card__map-link">
								<a href="<?php echo esc_url( $venue_map_download_url ); ?>" target="_blank" rel="noopener noreferrer">
									<span class="eem-event-details-card__map-link-icon" aria-hidden="true">
										<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
											<path d="M12 3a1 1 0 0 1 1 1v8.59l2.3-2.29a1 1 0 1 1 1.4 1.41l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.41L11 12.59V4a1 1 0 0 1 1-1Zm-7 14a1 1 0 0 1 1 1v1h12v-1a1 1 0 1 1 2 0v1a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3v-1a1 1 0 0 1 1-1Z" fill="currentColor"></path>
										</svg>
									</span>
									<span><?php esc_html_e( 'Download Venue Map', 'equine-event-manager' ); ?></span>
								</a>
							</div>
						<?php elseif ( ! empty( $data['venue_name'] ) || ! empty( $data['venue_address'] ) ) : ?>
							<div class="eem-event-details-card__venue">
								<?php if ( ! empty( $data['venue_name'] ) ) : ?>
									<strong><?php echo esc_html( $data['venue_name'] ); ?></strong>
								<?php endif; ?>
								<?php if ( ! empty( $data['venue_address'] ) ) : ?>
									<span><?php echo wp_kses_post( nl2br( esc_html( $data['venue_address'] ) ) ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! $status['stalls_bookable'] && ! $status['rv_bookable'] && ! $status['shavings_bookable'] ) : ?>
				<?php echo wp_kses_post( $this->render_notice( $this->get_reservation_status_message( $reservation, $data ) ) ); ?>
			<?php else : ?>
				<form
					class="eem-reservation-form"
					method="post"
					action="<?php echo esc_url( $form_action_url ); ?>"
					data-stall-nightly-rate="<?php echo esc_attr( $this->get_current_rate( $data, 'stall', 'nightly' ) ); ?>"
					data-stall-weekend-rate="<?php echo esc_attr( $this->get_current_rate( $data, 'stall', 'weekend' ) ); ?>"
					data-rv-nightly-rate="<?php echo esc_attr( $this->get_current_rate( $data, 'rv', 'nightly' ) ); ?>"
					data-rv-weekend-rate="<?php echo esc_attr( $this->get_current_rate( $data, 'rv', 'weekend' ) ); ?>"
					data-rv-addon-pricing="<?php echo esc_attr( wp_json_encode( $this->get_enabled_rv_addon_pricing_matrix( $data ) ) ); ?>"
					data-general-addon-pricing="<?php echo esc_attr( wp_json_encode( $this->get_enabled_general_addon_pricing_matrix( $data ) ) ); ?>"
					data-rv-lot-pricing="<?php echo esc_attr( wp_json_encode( $this->get_enabled_rv_lot_pricing_matrix( $data, $status ) ) ); ?>"
					data-nightly-start-date="<?php echo esc_attr( $min_date ); ?>"
					data-nightly-end-date="<?php echo esc_attr( $max_date ); ?>"
					data-required-shavings-enabled="<?php echo esc_attr( ! empty( $data['required_shavings_enabled'] ) ? '1' : '0' ); ?>"
					data-required-shavings-per-stall="<?php echo esc_attr( absint( $data['required_shavings_per_stall'] ) ); ?>"
					data-required-shavings-price="<?php echo esc_attr( (float) $data['required_shavings_price'] ); ?>"
					data-group-grounds-fee-enabled="<?php echo esc_attr( $group_grounds_fee_enabled ? '1' : '0' ); ?>"
					data-group-grounds-fee-amount="<?php echo esc_attr( (float) $data['group_rider_grounds_fee_amount'] ); ?>"
					data-group-deposit-enabled="<?php echo esc_attr( $group_deposit_enabled ? '1' : '0' ); ?>"
					data-group-deposit-amount="<?php echo esc_attr( (float) $data['group_rider_deposit_amount'] ); ?>"
					data-fee-type="<?php echo esc_attr( $data['convenience_fee_type'] ); ?>"
					data-fee-value="<?php echo esc_attr( (float) $data['convenience_fee_value'] ); ?>"
					data-payment-gateway="<?php echo esc_attr( $payment_settings['selected_gateway'] ); ?>"
					data-stripe-enabled="<?php echo esc_attr( $stripe_card_enabled ? '1' : '0' ); ?>"
					data-stripe-publishable-key="<?php echo esc_attr( $stripe_config['publishable_key'] ); ?>"
					data-stripe-create-intent-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-stall-assignment-config="<?php echo esc_attr( wp_json_encode( $this->get_stall_assignment_frontend_config( $reservation_id, $data ) ) ); ?>"
				>
					<?php wp_nonce_field( 'en_submit_reservation_' . $reservation_id, 'en_reservation_nonce' ); ?>
					<input type="hidden" name="en_reservation_action" value="submit_reservation" />
					<input type="hidden" name="en_reservation_id" value="<?php echo esc_attr( $reservation_id ); ?>" />
					<input type="hidden" name="en_submission_token" value="<?php echo esc_attr( $submission_token ); ?>" />
					<input type="hidden" name="en_invoice_type" value="<?php echo esc_attr( $is_admin_invoice ? 'manual' : 'customer' ); ?>" />
					<input type="hidden" name="stripe_payment_intent_id" value="" />
					<input type="hidden" name="stripe_payment_gateway" value="<?php echo esc_attr( $payment_settings['selected_gateway'] ); ?>" />

					<div class="eem-reservation-workspace">
						<div class="eem-reservation-workspace__main">
					<div class="eem-reservation-section">
						<h4 class="eem-reservation-section__title"><?php esc_html_e( 'Contact Information', 'equine-event-manager' ); ?></h4>
						<div class="eem-reservation-grid eem-reservation-grid--two">
							<label>
								<span><?php esc_html_e( 'First Name', 'equine-event-manager' ); ?> <strong>*</strong></span>
								<input type="text" name="first_name" autocomplete="given-name" required />
							</label>
							<label>
								<span><?php esc_html_e( 'Last Name', 'equine-event-manager' ); ?> <strong>*</strong></span>
								<input type="text" name="last_name" autocomplete="family-name" required />
							</label>
						</div>
						<div class="eem-reservation-grid eem-reservation-grid--two">
							<label>
								<span><?php esc_html_e( 'Email', 'equine-event-manager' ); ?> <strong>*</strong></span>
								<input type="email" name="email" autocomplete="email" required />
							</label>
							<label>
								<span><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?> <strong>*</strong></span>
								<div class="eem-phone-field">
									<span class="eem-phone-field__flag" aria-hidden="true">🇺🇸</span>
									<input type="tel" name="phone" autocomplete="tel-national" value="+1 " required />
								</div>
							</label>
						</div>
					</div>

					<?php if ( $data['stalls_enabled'] || $data['rv_enabled'] ) : ?>
						<div class="eem-reservation-section eem-reservation-section--instructions">
							<h4 class="eem-reservation-section__title"><?php esc_html_e( 'Stay Details', 'equine-event-manager' ); ?></h4>
							<?php if ( $reservation_description ) : ?>
								<p class="eem-reservation-help"><?php echo esc_html( $reservation_description ); ?></p>
							<?php endif; ?>
						<?php if ( $nightly_date_summary ) : ?>
							<p class="eem-reservation-help"><?php echo esc_html( $nightly_date_summary ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

					<?php if ( $data['stalls_enabled'] ) : ?>
						<div class="eem-reservation-section" data-eem-section="stall">
							<div class="eem-reservation-section-heading eem-reservation-section-heading--collapsible">
								<h4 class="eem-reservation-section__title"><?php esc_html_e( 'Stall Reservations', 'equine-event-manager' ); ?></h4>
								<label class="eem-reservation-section-toggle" aria-label="<?php esc_attr_e( 'Toggle Stall Reservations section', 'equine-event-manager' ); ?>">
									<input type="checkbox" data-eem-section-collapse-toggle />
									<span class="eem-reservation-section-toggle__track" aria-hidden="true"></span>
								</label>
							</div>
							<div class="eem-reservation-section__body" data-eem-section-collapse-body>
								<?php if ( ! empty( $data['stall_description'] ) ) : ?>
									<p class="eem-reservation-help"><?php echo esc_html( $data['stall_description'] ); ?></p>
								<?php endif; ?>
								<?php if ( $status['stalls_bookable'] ) : ?>
									<?php $stall_inventory_notice = $this->get_inventory_notice( 'stall', $status['stall_inventory_remaining'] ); ?>
									<?php if ( $stall_inventory_notice ) : ?>
										<p class="eem-reservation-help eem-reservation-help--inventory"><?php echo esc_html( $stall_inventory_notice ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( $stall_early_bird_notice ) : ?>
									<p class="eem-reservation-help eem-reservation-help--early-bird"><?php echo esc_html( $stall_early_bird_notice ); ?></p>
								<?php endif; ?>
								<?php if ( $status['stalls_open'] && ! $status['stalls_sold_out'] ) : ?>
									<?php $stay_date_options = $this->get_available_stay_date_options( $min_date, $max_date ); ?>
									<div
										class="eem-section-stay-controls"
										data-section-stay-controls="stall"
										data-nightly-summary="<?php echo esc_attr( $nightly_date_summary ); ?>"
										data-weekend-summary="<?php echo esc_attr( $stall_weekend_date_summary ); ?>"
										data-weekend-start-date="<?php echo esc_attr( $stall_weekend_start_date ); ?>"
										data-weekend-end-date="<?php echo esc_attr( $stall_weekend_end_date ); ?>"
									>
										<p class="eem-reservation-help eem-section-stay-controls__help"><?php echo esc_html( 'weekend' === $stall_default_stay_type && $stall_weekend_date_summary ? $stall_weekend_date_summary : $nightly_date_summary ); ?></p>
										<div class="eem-reservation-grid eem-reservation-grid--stay-controls">
										<label class="eem-stay-type-field">
											<span><?php esc_html_e( 'Rate Type', 'equine-event-manager' ); ?></span>
											<select name="stall_stay_type" data-default-stay-type="<?php echo esc_attr( $stall_default_stay_type ); ?>">
												<?php foreach ( $stall_stay_type_options as $stay_type_value => $stay_type_label ) : ?>
													<option value="<?php echo esc_attr( $stay_type_value ); ?>" <?php selected( $stay_type_value, $stall_default_stay_type ); ?>><?php echo esc_html( $stay_type_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label class="eem-stay-date-field eem-stay-date-field--arrival">
											<span><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?> <strong>*</strong></span>
											<?php if ( ! empty( $stay_date_options ) ) : ?>
												<select name="stall_arrival_date" data-default-date="<?php echo esc_attr( $stall_default_arrival_date ); ?>" required>
													<?php foreach ( $stay_date_options as $option_value => $option_label ) : ?>
														<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $option_value, $stall_default_arrival_date ); ?>><?php echo esc_html( $option_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php else : ?>
												<input type="date" name="stall_arrival_date" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $stall_default_arrival_date ); ?>" data-default-date="<?php echo esc_attr( $stall_default_arrival_date ); ?>" required />
											<?php endif; ?>
										</label>
										<label class="eem-stay-date-field eem-stay-date-field--departure">
											<span><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?> <strong>*</strong></span>
											<?php if ( ! empty( $stay_date_options ) ) : ?>
												<select name="stall_departure_date" data-default-date="<?php echo esc_attr( $stall_default_departure_date ); ?>" required>
													<?php foreach ( $stay_date_options as $option_value => $option_label ) : ?>
														<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $option_value, $stall_default_departure_date ); ?>><?php echo esc_html( $option_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php else : ?>
												<input type="date" name="stall_departure_date" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $stall_default_departure_date ); ?>" data-default-date="<?php echo esc_attr( $stall_default_departure_date ); ?>" required />
											<?php endif; ?>
										</label>
										<div class="eem-stay-night-field">
											<span class="eem-stay-night-field__label"><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></span>
											<div class="eem-stay-night-field__value">
												<strong data-stay-nights-summary><?php echo esc_html( $this->get_night_count_label( $stall_default_night_count ) ); ?></strong>
											</div>
										</div>
									</div>
									<div class="eem-weekend-package-summary" hidden>
										<div class="eem-reservation-grid eem-reservation-grid--two">
											<div class="eem-weekend-package-field">
												<span><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?></span>
												<strong data-weekend-arrival-label><?php echo esc_html( $stall_weekend_start_date ? $this->format_date_label( $stall_weekend_start_date ) : '' ); ?></strong>
											</div>
											<div class="eem-weekend-package-field">
												<span><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?></span>
												<strong data-weekend-departure-label><?php echo esc_html( $stall_weekend_end_date ? $this->format_date_label( $stall_weekend_end_date ) : '' ); ?></strong>
											</div>
										</div>
										</div>
									</div>
								<?php endif; ?>
								<?php if ( $status['stalls_bookable'] ) : ?>
									<?php $this->render_stall_selection_ui( $reservation_id, $data, $status, $stall_selection_context ); ?>
								<?php elseif ( $status['stalls_sold_out'] ) : ?>
									<p class="eem-reservation-help eem-reservation-help--sold-out"><?php echo esc_html( $this->get_sold_out_message( 'stall' ) ); ?></p>
								<?php else : ?>
									<p class="eem-reservation-help"><?php echo esc_html( $this->get_closed_message( $data, 'stalls' ) ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $data['rv_enabled'] ) : ?>
						<div class="eem-reservation-section" data-eem-section="rv">
							<div class="eem-reservation-section-heading eem-reservation-section-heading--collapsible">
								<h4 class="eem-reservation-section__title"><?php esc_html_e( 'RV Reservations', 'equine-event-manager' ); ?></h4>
								<label class="eem-reservation-section-toggle" aria-label="<?php esc_attr_e( 'Toggle RV Reservations section', 'equine-event-manager' ); ?>">
									<input type="checkbox" data-eem-section-collapse-toggle />
									<span class="eem-reservation-section-toggle__track" aria-hidden="true"></span>
								</label>
							</div>
							<div class="eem-reservation-section__body" data-eem-section-collapse-body>
								<?php if ( ! empty( $data['rv_description'] ) ) : ?>
									<p class="eem-reservation-help"><?php echo esc_html( $data['rv_description'] ); ?></p>
								<?php endif; ?>
								<?php if ( $status['rv_bookable'] ) : ?>
									<?php $rv_inventory_notice = $this->get_inventory_notice( 'rv', $status['rv_inventory_remaining'] ); ?>
									<?php if ( $rv_inventory_notice ) : ?>
										<p class="eem-reservation-help eem-reservation-help--inventory"><?php echo esc_html( $rv_inventory_notice ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( $rv_early_bird_notice ) : ?>
									<p class="eem-reservation-help eem-reservation-help--early-bird"><?php echo esc_html( $rv_early_bird_notice ); ?></p>
								<?php endif; ?>
								<?php if ( $status['rv_open'] && ! $status['rv_sold_out'] ) : ?>
									<?php $stay_date_options = $this->get_available_stay_date_options( $min_date, $max_date ); ?>
									<div
										class="eem-section-stay-controls"
										data-section-stay-controls="rv"
										data-nightly-summary="<?php echo esc_attr( $nightly_date_summary ); ?>"
										data-weekend-summary="<?php echo esc_attr( $rv_weekend_date_summary ); ?>"
										data-weekend-start-date="<?php echo esc_attr( $rv_weekend_start_date ); ?>"
										data-weekend-end-date="<?php echo esc_attr( $rv_weekend_end_date ); ?>"
									>
										<p class="eem-reservation-help eem-section-stay-controls__help"><?php echo esc_html( 'weekend' === $rv_default_stay_type && $rv_weekend_date_summary ? $rv_weekend_date_summary : $nightly_date_summary ); ?></p>
										<div class="eem-reservation-grid eem-reservation-grid--stay-controls">
											<label class="eem-stay-type-field">
												<span><?php esc_html_e( 'Rate Type', 'equine-event-manager' ); ?></span>
												<select name="rv_stay_type" data-default-stay-type="<?php echo esc_attr( $rv_default_stay_type ); ?>">
													<?php foreach ( $rv_stay_type_options as $stay_type_value => $stay_type_label ) : ?>
														<option value="<?php echo esc_attr( $stay_type_value ); ?>" <?php selected( $stay_type_value, $rv_default_stay_type ); ?>><?php echo esc_html( $stay_type_label ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
											<label class="eem-stay-date-field eem-stay-date-field--arrival">
												<span><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?> <strong>*</strong></span>
												<?php if ( ! empty( $stay_date_options ) ) : ?>
													<select name="rv_arrival_date" data-default-date="<?php echo esc_attr( $rv_default_arrival_date ); ?>" required>
														<?php foreach ( $stay_date_options as $option_value => $option_label ) : ?>
															<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $option_value, $rv_default_arrival_date ); ?>><?php echo esc_html( $option_label ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<input type="date" name="rv_arrival_date" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $rv_default_arrival_date ); ?>" data-default-date="<?php echo esc_attr( $rv_default_arrival_date ); ?>" required />
												<?php endif; ?>
											</label>
											<label class="eem-stay-date-field eem-stay-date-field--departure">
												<span><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?> <strong>*</strong></span>
												<?php if ( ! empty( $stay_date_options ) ) : ?>
													<select name="rv_departure_date" data-default-date="<?php echo esc_attr( $rv_default_departure_date ); ?>" required>
														<?php foreach ( $stay_date_options as $option_value => $option_label ) : ?>
															<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $option_value, $rv_default_departure_date ); ?>><?php echo esc_html( $option_label ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<input type="date" name="rv_departure_date" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $rv_default_departure_date ); ?>" data-default-date="<?php echo esc_attr( $rv_default_departure_date ); ?>" required />
												<?php endif; ?>
											</label>
											<div class="eem-stay-night-field">
												<span class="eem-stay-night-field__label"><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></span>
												<div class="eem-stay-night-field__value">
													<strong data-stay-nights-summary><?php echo esc_html( $this->get_night_count_label( $rv_default_night_count ) ); ?></strong>
												</div>
											</div>
										</div>
										<div class="eem-weekend-package-summary" hidden>
											<div class="eem-reservation-grid eem-reservation-grid--two">
												<div class="eem-weekend-package-field">
													<span><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?></span>
													<strong data-weekend-arrival-label><?php echo esc_html( $rv_weekend_start_date ? $this->format_date_label( $rv_weekend_start_date ) : '' ); ?></strong>
												</div>
												<div class="eem-weekend-package-field">
													<span><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?></span>
													<strong data-weekend-departure-label><?php echo esc_html( $rv_weekend_end_date ? $this->format_date_label( $rv_weekend_end_date ) : '' ); ?></strong>
												</div>
											</div>
										</div>
									</div>
									<?php if ( ! empty( $data['rv_lot_selection_enabled'] ) && ! empty( $rv_lot_options ) ) : ?>
										<div class="eem-reservation-grid eem-reservation-grid--two eem-rv-lot-selector-row">
											<label>
												<span><?php esc_html_e( 'RV Lot', 'equine-event-manager' ); ?> <strong>*</strong></span>
												<select name="rv_lot" data-default-rv-lot="<?php echo esc_attr( (string) $rv_default_lot ); ?>">
													<option value=""><?php esc_html_e( 'Select RV lot', 'equine-event-manager' ); ?></option>
													<?php foreach ( $rv_lot_options as $lot_key => $lot ) : ?>
														<?php
														$lot_inventory = isset( $status['rv_lot_inventory'][ (string) $lot_key ] ) ? $status['rv_lot_inventory'][ (string) $lot_key ] : array();
														$is_lot_sold_out = ! empty( $lot_inventory['sold_out'] );
														$lot_label = $lot['name'] . ( $is_lot_sold_out ? ' (' . __( 'Sold Out', 'equine-event-manager' ) . ')' : '' );
														?>
														<option value="<?php echo esc_attr( (string) $lot_key ); ?>" <?php selected( (string) $lot_key, (string) $rv_default_lot ); ?> <?php disabled( $is_lot_sold_out ); ?>><?php echo esc_html( $lot_label ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
											<div class="eem-rv-lot-selector-row__details" data-rv-lot-summary>
												<p class="eem-rv-lot-selector-row__title" data-rv-lot-summary-title hidden></p>
												<p class="eem-rv-lot-selector-row__description" data-rv-lot-summary-description hidden></p>
											</div>
										</div>
									<?php endif; ?>
									<div class="eem-product-list">
										<?php $this->render_product_list_header(); ?>
										<?php
										$this->render_product_line_item(
											__( 'RV Spots', 'equine-event-manager' ),
											'',
											'rv_qty',
											'',
											array(
												'dynamic_price_type' => 'rv',
												'early_bird_active'  => $rv_early_bird_active,
												'max_quantity'       => ! empty( $data['rv_lot_selection_enabled'] ) && '' !== (string) $rv_default_lot && isset( $status['rv_lot_inventory'][ (string) $rv_default_lot ]['remaining'] ) ? $status['rv_lot_inventory'][ (string) $rv_default_lot ]['remaining'] : $status['rv_inventory_remaining'],
											)
										);
										?>
									<?php foreach ( $rv_addon_options as $addon_key => $addon ) : ?>
											<?php
											$this->render_checkbox_product_line_item(
												$addon['name'],
												$addon['description'],
												'rv_addon_' . $addon_key,
												'eem-product-line-item--rv-addon',
												array(
													'dynamic_price_type' => 'rv_addon',
													'dynamic_price_key'  => $addon_key,
													'disabled'           => true,
												)
											);
											?>
										<?php endforeach; ?>
									</div>
									<div class="eem-section-subtotal" aria-live="polite">
										<span><?php esc_html_e( 'RV Subtotal', 'equine-event-manager' ); ?></span>
										<strong data-eem-total="rv_section_subtotal">$0.00</strong>
									</div>
								<?php elseif ( $status['rv_sold_out'] ) : ?>
									<p class="eem-reservation-help eem-reservation-help--sold-out"><?php echo esc_html( $this->get_sold_out_message( 'rv' ) ); ?></p>
								<?php else : ?>
									<p class="eem-reservation-help"><?php echo esc_html( $this->get_closed_message( $data, 'rv' ) ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $general_addon_options ) ) : ?>
						<div class="eem-reservation-section" data-eem-section="addons">
							<div class="eem-reservation-section-heading eem-reservation-section-heading--collapsible">
								<h4 class="eem-reservation-section__title"><?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></h4>
								<label class="eem-reservation-section-toggle" aria-label="<?php esc_attr_e( 'Toggle Add-Ons section', 'equine-event-manager' ); ?>">
									<input type="checkbox" data-eem-section-collapse-toggle />
									<span class="eem-reservation-section-toggle__track" aria-hidden="true"></span>
								</label>
							</div>
							<div class="eem-reservation-section__body" data-eem-section-collapse-body>
								<div class="eem-product-list">
									<?php $this->render_product_list_header(); ?>
									<?php foreach ( $general_addon_options as $addon_key => $addon ) : ?>
										<?php
										$this->render_product_line_item(
											$addon['name'],
											$this->format_general_addon_description( $addon ),
											'general_addon_' . $addon_key . '_qty',
											'eem-product-line-item--general-addon',
											array(
												'static_price'        => (float) $addon['price'],
												'static_price_suffix' => $this->get_general_addon_price_suffix( $addon ),
												'addon_applies_to'    => $addon['applies_to'],
												'general_addon_key'   => $addon_key,
											)
										);
										?>
									<?php endforeach; ?>
								</div>
								<div class="eem-section-subtotal" aria-live="polite">
									<span><?php esc_html_e( 'Add-On Subtotal', 'equine-event-manager' ); ?></span>
									<strong data-eem-total="general_addons_subtotal">$0.00</strong>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $group_reservations_enabled ) : ?>
						<div class="eem-reservation-section eem-reservation-section--group-reservation" data-eem-section="group">
							<div class="eem-reservation-section-heading eem-reservation-section-heading--collapsible">
								<h4 class="eem-reservation-section__title"><?php esc_html_e( 'Group Reservation', 'equine-event-manager' ); ?></h4>
								<label class="eem-reservation-section-toggle" aria-label="<?php esc_attr_e( 'Toggle Group Reservation section', 'equine-event-manager' ); ?>">
									<input type="checkbox" name="group_reservation_enabled" value="1" data-eem-group-toggle data-eem-section-collapse-toggle />
									<span class="eem-reservation-section-toggle__track" aria-hidden="true"></span>
								</label>
							</div>
							<div class="eem-reservation-section__body" data-eem-section-collapse-body>
								<p class="eem-reservation-help"><?php esc_html_e( 'Turn this on to capture the total rider count and a first and last name for each rider in the group.', 'equine-event-manager' ); ?></p>
								<div class="eem-group-reservation-fields" data-eem-group-fields hidden>
									<div class="eem-product-list eem-product-list--group-reservation">
										<?php $this->render_product_list_header(); ?>
										<div class="eem-product-line-item eem-product-line-item--group-riders">
											<div class="eem-product-line-item__content">
												<div class="eem-product-line-item__title">
													<span class="eem-product-line-item__title-text"><?php esc_html_e( 'Number of Riders', 'equine-event-manager' ); ?> <strong>*</strong></span>
												</div>
												<div class="eem-product-line-item__description"><?php esc_html_e( 'Use the quantity controls to set how many riders are in the group reservation.', 'equine-event-manager' ); ?></div>
											</div>
											<div class="eem-product-line-item__qty">
												<div class="eem-quantity-control">
													<button type="button" class="eem-quantity-button" data-eem-quantity-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'equine-event-manager' ); ?>">-</button>
													<input type="number" name="group_rider_count" min="1" step="1" value="1" data-eem-group-count inputmode="numeric" />
													<button type="button" class="eem-quantity-button" data-eem-quantity-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'equine-event-manager' ); ?>">+</button>
												</div>
											</div>
										</div>
										<?php if ( $group_grounds_fee_enabled ) : ?>
											<?php
											$this->render_readonly_product_line_item(
												__( 'Rider Grounds Fee', 'equine-event-manager' ),
												sprintf(
													/* translators: %s: fee amount. */
													__( '%s charged for each rider in the group reservation.', 'equine-event-manager' ),
													$this->format_money( $data['group_rider_grounds_fee_amount'] )
												),
												'group_rider_grounds_fee'
											);
											?>
										<?php endif; ?>
										<?php if ( $group_deposit_enabled ) : ?>
											<?php
											$this->render_readonly_product_line_item(
												__( 'Rider Deposit', 'equine-event-manager' ),
												sprintf(
													/* translators: %s: deposit amount. */
													__( '%s deposit required for each rider in the group reservation.', 'equine-event-manager' ),
													$this->format_money( $data['group_rider_deposit_amount'] )
												),
												'group_rider_deposit'
											);
											?>
										<?php endif; ?>
									</div>
									<div class="eem-group-riders-list" data-eem-group-riders-list></div>
									<div class="eem-section-subtotal" aria-live="polite">
										<span><?php esc_html_e( 'Group Reservation Subtotal', 'equine-event-manager' ); ?></span>
										<strong data-eem-total="group_subtotal">$0.00</strong>
									</div>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<div class="eem-reservation-section eem-reservation-section--special-requests">
						<label>
							<h4 class="eem-checkout-subsection-title eem-checkout-subsection-title--field"><?php esc_html_e( 'Special Requests', 'equine-event-manager' ); ?></h4>
							<?php if ( $special_requests_description ) : ?>
								<small class="eem-reservation-help"><?php echo esc_html( $special_requests_description ); ?></small>
							<?php endif; ?>
							<textarea name="notes" rows="4"></textarea>
						</label>
					</div>

					<div class="eem-reservation-section eem-reservation-section--payment">
						<?php if ( $is_admin_invoice ) : ?>
							<div class="eem-invoice-mode-card">
								<div class="eem-invoice-mode-card__copy">
									<h4><?php esc_html_e( 'Invoice Delivery', 'equine-event-manager' ); ?></h4>
									<p class="eem-reservation-help"><?php esc_html_e( 'Leave card entry off to email a secure payment link. Turn it on only when you are charging the customer directly and need to enter billing details here.', 'equine-event-manager' ); ?></p>
									<div class="eem-invoice-mode-actions">
										<label class="eem-inline-toggle-control eem-invoice-mode-toggle">
											<input type="checkbox" name="en_collect_billing_now" value="1" data-eem-invoice-billing-toggle />
											<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
											<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Charge Customer', 'equine-event-manager' ); ?></span>
										</label>
										<div class="eem-reservation-submit-actions eem-reservation-submit-actions--invoice-mode">
											<button type="submit" class="eem-reservation-submit" data-eem-invoice-send-button data-eem-invoice-action="send_payment_link"><?php esc_html_e( 'Send Payment Link', 'equine-event-manager' ); ?></button>
											<button type="submit" class="eem-reservation-submit eem-reservation-submit--secondary" data-eem-invoice-show-bill-button data-eem-invoice-action="add_to_show_bill"><?php esc_html_e( 'Add to Show Bill', 'equine-event-manager' ); ?></button>
											<button type="submit" class="eem-reservation-submit eem-reservation-submit--secondary" data-eem-invoice-charge-button data-eem-invoice-action="charge_now" hidden><?php esc_html_e( 'Charge Card', 'equine-event-manager' ); ?></button>
										</div>
									</div>
								</div>
							</div>
						<?php endif; ?>
						<h4 class="eem-reservation-section__title"<?php echo $is_admin_invoice ? ' data-eem-invoice-billing-block hidden style="display:none;"' : ''; ?>><?php esc_html_e( 'Billing & Payment', 'equine-event-manager' ); ?></h4>
								<div class="eem-payment-checkout-block<?php echo $is_admin_invoice ? ' eem-payment-checkout-block--admin-invoice' : ''; ?>"<?php echo $is_admin_invoice ? ' data-eem-invoice-billing-block hidden style="display:none;"' : ''; ?>>
							<h4 class="eem-checkout-subsection-title"><?php esc_html_e( 'Billing Details', 'equine-event-manager' ); ?></h4>
							<div class="eem-reservation-grid eem-reservation-grid--two">
								<label>
									<span><?php esc_html_e( 'Billing First Name', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<input type="text" name="billing_first_name" autocomplete="billing given-name" required data-eem-required-for-charge="1" />
								</label>
								<label>
									<span><?php esc_html_e( 'Billing Last Name', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<input type="text" name="billing_last_name" autocomplete="billing family-name" required data-eem-required-for-charge="1" />
								</label>
							</div>
							<div class="eem-reservation-grid eem-reservation-grid--single">
								<label>
									<span><?php esc_html_e( 'Billing Address', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<input type="text" name="billing_address_1" autocomplete="billing address-line1" required data-eem-required-for-charge="1" />
								</label>
							</div>
							<div class="eem-reservation-grid eem-reservation-grid--single">
								<label>
									<span><?php esc_html_e( 'Apartment, suite, etc.', 'equine-event-manager' ); ?></span>
									<input type="text" name="billing_address_2" autocomplete="billing address-line2" />
								</label>
							</div>
							<div class="eem-reservation-grid eem-reservation-grid--three">
								<label>
									<span><?php esc_html_e( 'City', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<input type="text" name="billing_city" autocomplete="billing address-level2" required data-eem-required-for-charge="1" />
								</label>
								<label>
									<span><?php esc_html_e( 'State / Province', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<select name="billing_state" autocomplete="billing address-level1" required data-eem-required-for-charge="1">
										<?php foreach ( $this->get_state_options() as $state ) : ?>
											<option value="<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label>
									<span><?php esc_html_e( 'ZIP / Postal Code', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<input type="text" name="billing_postal_code" autocomplete="billing postal-code" required data-eem-required-for-charge="1" />
								</label>
							</div>
							<div class="eem-reservation-grid eem-reservation-grid--single">
								<label>
									<span><?php esc_html_e( 'Country', 'equine-event-manager' ); ?> <strong>*</strong></span>
									<select name="billing_country" autocomplete="billing country-name" required data-eem-required-for-charge="1">
										<?php foreach ( $this->get_billing_country_options() as $country ) : ?>
											<option value="<?php echo esc_attr( $country ); ?>" <?php selected( $country, 'United States' ); ?>><?php echo esc_html( $country ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>
								</div>
								<?php if ( 'stripe' === $payment_settings['selected_gateway'] ) : ?>
						<div class="eem-payment-card-field-wrap<?php echo $is_admin_invoice ? ' eem-payment-card-field-wrap--admin-invoice' : ''; ?>"<?php echo $is_admin_invoice ? ' data-eem-invoice-billing-block hidden style="display:none;"' : ''; ?>>
							<h4 class="eem-checkout-subsection-title"><?php esc_html_e( 'Credit Card', 'equine-event-manager' ); ?></h4>
							<?php if ( $stripe_card_enabled ) : ?>
								<div class="eem-payment-card-grid">
									<div class="eem-payment-card-field eem-payment-card-field--full">
										<label class="eem-payment-card-label" for="eem-stripe-card-number-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Card Number', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<div id="eem-stripe-card-number-<?php echo esc_attr( $reservation_id ); ?>" class="eem-stripe-card-element" data-eem-stripe-card-number></div>
									</div>
									<div class="eem-payment-card-field">
										<label class="eem-payment-card-label" for="eem-stripe-card-expiry-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Expiration Date', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<div id="eem-stripe-card-expiry-<?php echo esc_attr( $reservation_id ); ?>" class="eem-stripe-card-element" data-eem-stripe-card-expiry></div>
									</div>
									<div class="eem-payment-card-field">
										<label class="eem-payment-card-label" for="eem-stripe-card-cvc-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Security Code', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<div id="eem-stripe-card-cvc-<?php echo esc_attr( $reservation_id ); ?>" class="eem-stripe-card-element" data-eem-stripe-card-cvc></div>
									</div>
								</div>
								<p class="eem-reservation-help eem-payment-card-help"><?php esc_html_e( 'Use a Stripe test card to complete your payment. Example: 4242 4242 4242 4242.', 'equine-event-manager' ); ?></p>
								<div class="eem-stripe-card-error" data-eem-stripe-card-error hidden></div>
							<?php else : ?>
								<p class="eem-reservation-help eem-reservation-help--error"><?php esc_html_e( 'Stripe test card entry is not available yet because your Stripe keys are not configured in Settings.', 'equine-event-manager' ); ?></p>
							<?php endif; ?>
						</div>
					<?php elseif ( 'authorize_net' === $payment_settings['selected_gateway'] ) : ?>
						<div class="eem-payment-card-field-wrap<?php echo $is_admin_invoice ? ' eem-payment-card-field-wrap--admin-invoice' : ''; ?>"<?php echo $is_admin_invoice ? ' data-eem-invoice-billing-block hidden style="display:none;"' : ''; ?>>
							<h4 class="eem-checkout-subsection-title"><?php esc_html_e( 'Credit Card', 'equine-event-manager' ); ?></h4>
							<?php if ( $authorize_card_enabled ) : ?>
								<div class="eem-payment-card-grid">
									<div class="eem-payment-card-field eem-payment-card-field--full">
										<label class="eem-payment-card-label" for="eem-authorize-card-number-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Card Number', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<input id="eem-authorize-card-number-<?php echo esc_attr( $reservation_id ); ?>" type="text" name="authorize_card_number" inputmode="numeric" autocomplete="cc-number" class="eem-payment-card-input" placeholder="1234 1234 1234 1234" />
									</div>
									<div class="eem-payment-card-field">
										<label class="eem-payment-card-label" for="eem-authorize-exp-month-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Exp. Month', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<select id="eem-authorize-exp-month-<?php echo esc_attr( $reservation_id ); ?>" name="authorize_exp_month" class="eem-payment-card-input">
											<option value=""><?php esc_html_e( 'Month', 'equine-event-manager' ); ?></option>
											<?php for ( $month = 1; $month <= 12; $month++ ) : ?>
												<?php $month_value = sprintf( '%02d', $month ); ?>
												<option value="<?php echo esc_attr( $month_value ); ?>"><?php echo esc_html( $month_value ); ?></option>
											<?php endfor; ?>
										</select>
									</div>
									<div class="eem-payment-card-field">
										<label class="eem-payment-card-label" for="eem-authorize-exp-year-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Exp. Year', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<select id="eem-authorize-exp-year-<?php echo esc_attr( $reservation_id ); ?>" name="authorize_exp_year" class="eem-payment-card-input">
											<option value=""><?php esc_html_e( 'Year', 'equine-event-manager' ); ?></option>
											<?php for ( $year = (int) gmdate( 'Y' ); $year <= ( (int) gmdate( 'Y' ) + 15 ); $year++ ) : ?>
												<option value="<?php echo esc_attr( (string) $year ); ?>"><?php echo esc_html( (string) $year ); ?></option>
											<?php endfor; ?>
										</select>
									</div>
									<div class="eem-payment-card-field">
										<label class="eem-payment-card-label" for="eem-authorize-card-code-<?php echo esc_attr( $reservation_id ); ?>">
											<span><?php esc_html_e( 'Security Code', 'equine-event-manager' ); ?> <strong>*</strong></span>
										</label>
										<input id="eem-authorize-card-code-<?php echo esc_attr( $reservation_id ); ?>" type="text" name="authorize_card_code" inputmode="numeric" autocomplete="cc-csc" class="eem-payment-card-input" placeholder="CVC" />
									</div>
								</div>
								<p class="eem-reservation-help eem-payment-card-help"><?php esc_html_e( 'Enter your card details to process this payment through Authorize.net.', 'equine-event-manager' ); ?></p>
							<?php else : ?>
								<p class="eem-reservation-help eem-reservation-help--error"><?php esc_html_e( 'Authorize.net card entry is not available yet because your API Login ID and Transaction Key are not configured in Settings.', 'equine-event-manager' ); ?></p>
							<?php endif; ?>
						</div>
								<?php endif; ?>
								<input type="hidden" name="en_invoice_action_mode" value="<?php echo esc_attr( $is_admin_invoice ? 'send_payment_link' : 'customer_submit' ); ?>" />
								<?php if ( ! $is_admin_invoice ) : ?>
									<button type="submit" class="eem-reservation-submit"><?php esc_html_e( 'Complete Reservation', 'equine-event-manager' ); ?></button>
								<?php endif; ?>
							</div>
						</div>
						<?php
						echo $this->render_order_summary_sidebar(
							$data,
							$general_addon_options,
							$rv_addon_options,
							$group_grounds_fee_enabled,
							$group_deposit_enabled,
							$venue_agreement_url,
							$is_admin_invoice
						);
						?>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render the reservation workspace order summary sidebar.
	 *
	 * @param array  $data                       Reservation data.
	 * @param array  $general_addon_options      General add-on options.
	 * @param array  $rv_addon_options           RV add-on options.
	 * @param bool   $group_grounds_fee_enabled  Whether rider grounds fees are enabled.
	 * @param bool   $group_deposit_enabled      Whether rider deposits are enabled.
	 * @param string $venue_agreement_url        Venue agreement URL.
	 * @param bool   $is_admin_invoice           Whether this is the admin invoice experience.
	 * @return string
	 */
	private function render_order_summary_sidebar( $data, $general_addon_options, $rv_addon_options, $group_grounds_fee_enabled, $group_deposit_enabled, $venue_agreement_url, $is_admin_invoice ) {
		ob_start();
		?>
		<aside class="eem-reservation-workspace__rail">
			<div class="eem-reservation-summary-card">
				<div class="eem-reservation-summary-card__sticky">
					<h4 class="eem-checkout-subsection-title"><?php esc_html_e( 'Order Summary', 'equine-event-manager' ); ?></h4>
					<div class="eem-payment-summary" aria-live="polite">
						<div class="eem-payment-summary-row" data-eem-summary-row="stall_subtotal" hidden>
							<span><?php esc_html_e( 'Stall Subtotal', 'equine-event-manager' ); ?></span>
							<strong data-eem-total="stall_subtotal">$0.00</strong>
						</div>
						<div class="eem-payment-summary-row" data-eem-summary-row="required_shavings_subtotal" hidden>
							<span><?php esc_html_e( 'Required Shavings Subtotal', 'equine-event-manager' ); ?></span>
							<strong data-eem-total="required_shavings_subtotal">$0.00</strong>
						</div>
						<div class="eem-payment-summary-row" data-eem-summary-row="rv_subtotal" hidden>
							<span><?php esc_html_e( 'RV Reservations Subtotal', 'equine-event-manager' ); ?></span>
							<strong data-eem-total="rv_subtotal">$0.00</strong>
						</div>
						<?php if ( $group_grounds_fee_enabled ) : ?>
							<div class="eem-payment-summary-row" data-eem-summary-row="group_rider_grounds_fee_subtotal" hidden>
								<span><?php esc_html_e( 'Rider Grounds Fee', 'equine-event-manager' ); ?></span>
								<strong data-eem-total="group_rider_grounds_fee_subtotal">$0.00</strong>
							</div>
						<?php endif; ?>
						<?php if ( $group_deposit_enabled ) : ?>
							<div class="eem-payment-summary-row" data-eem-summary-row="group_rider_deposit_subtotal" hidden>
								<span><?php esc_html_e( 'Rider Deposit', 'equine-event-manager' ); ?></span>
								<strong data-eem-total="group_rider_deposit_subtotal">$0.00</strong>
							</div>
						<?php endif; ?>
						<?php foreach ( $general_addon_options as $addon_key => $addon ) : ?>
							<div class="eem-payment-summary-row" data-eem-summary-row="general_addon_<?php echo esc_attr( $addon_key ); ?>_subtotal" hidden>
								<span><?php echo esc_html( $addon['name'] ); ?></span>
								<strong data-eem-total="general_addon_<?php echo esc_attr( $addon_key ); ?>_subtotal">$0.00</strong>
							</div>
						<?php endforeach; ?>
						<?php foreach ( $rv_addon_options as $addon_key => $addon ) : ?>
							<div class="eem-payment-summary-row" data-eem-summary-row="rv_addon_<?php echo esc_attr( $addon_key ); ?>_subtotal" hidden>
								<span><?php echo esc_html( $addon['name'] ); ?> <?php esc_html_e( 'Add-On', 'equine-event-manager' ); ?></span>
								<strong data-eem-total="rv_addon_<?php echo esc_attr( $addon_key ); ?>_subtotal">$0.00</strong>
							</div>
						<?php endforeach; ?>
						<div class="eem-payment-summary-row" data-eem-summary-row="fees" hidden>
							<span><?php echo esc_html( ! empty( $data['convenience_fee_label'] ) ? $data['convenience_fee_label'] : __( 'Non-Refundable Convenience Fee', 'equine-event-manager' ) ); ?></span>
							<strong data-eem-total="fees">$0.00</strong>
						</div>
						<div class="eem-payment-summary-row eem-payment-summary-row--total">
							<span><?php esc_html_e( 'Total Amount Due', 'equine-event-manager' ); ?></span>
							<strong data-eem-total="total">$0.00</strong>
						</div>
					</div>
					<?php if ( ! $is_admin_invoice && ! empty( $data['venue_agreement_enabled'] ) && $venue_agreement_url ) : ?>
						<div class="eem-venue-agreement-card">
							<p>
								<?php esc_html_e( 'All transaction fees are non-refundable. Please be sure you have read the', 'equine-event-manager' ); ?>
								<a href="<?php echo esc_url( $venue_agreement_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( ! empty( $data['venue_agreement_file_label'] ) ? $data['venue_agreement_file_label'] : __( 'Agreement', 'equine-event-manager' ) ); ?></a>
								<?php esc_html_e( 'before clicking SAVE.', 'equine-event-manager' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</aside>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render the stall reservation form shortcode through the reservation form when possible.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_stall_reservation_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id' => 0,
			),
			$atts,
			'en_stall_reservation_form'
		);

		return $this->render_legacy_event_form( absint( $atts['event_id'] ), 'stall' );
	}

	/**
	 * Render the RV reservation form shortcode through the reservation form when possible.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_rv_reservation_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id' => 0,
			),
			$atts,
			'en_rv_reservation_form'
		);

		return $this->render_legacy_event_form( absint( $atts['event_id'] ), 'rv' );
	}

	/**
	 * Render a legacy event-based form shortcode.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $type Reservation type.
	 * @return string
	 */
	private function render_legacy_event_form( $event_id, $type ) {
		$reservation_id = $this->find_reservation_by_event_id( $event_id, $type );

		if ( ! $reservation_id ) {
			return $this->render_notice( __( 'Reservations are not available for this event yet.', 'equine-event-manager' ) );
		}

		return $this->render_reservation( array( 'id' => $reservation_id ) );
	}

	/**
	 * Resolve the stall selection mode for the current reservation render.
	 *
	 * Quantity mode remains the default until an add-on replaces the UI.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $data Reservation setup data.
	 * @return string
	 */
	private function get_resolved_stall_selection_mode( $reservation_id, $data ) {
		$selection_mode = isset( $data['stall_selection_mode'] ) ? $this->sanitize_stall_selection_mode( $data['stall_selection_mode'] ) : 'quantity';

		$selection_mode = apply_filters( 'eem_stall_selection_mode', $selection_mode, $reservation_id, $data, $this );

		return $this->sanitize_stall_selection_mode( $selection_mode );
	}

	/**
	 * Render the stall selection UI for the reservation form.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $data Reservation setup data.
	 * @param array $status Reservation status payload.
	 * @param array $context Additional render context.
	 * @return void
	 */
	private function render_stall_selection_ui( $reservation_id, $data, $status, $context = array() ) {
		$selection_mode = $this->get_resolved_stall_selection_mode( $reservation_id, $data );

		do_action( 'eem_before_stall_selection_ui', $selection_mode, $reservation_id, $data, $status, $context, $this );

		$custom_markup = apply_filters( 'eem_render_stall_selection_ui', '', $selection_mode, $reservation_id, $data, $status, $context, $this );

		if ( is_string( $custom_markup ) && '' !== trim( $custom_markup ) ) {
			echo $custom_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			$this->render_quantity_stall_selection_ui( $reservation_id, $data, $status, $context );
		}

		do_action( 'eem_after_stall_selection_ui', $selection_mode, $reservation_id, $data, $status, $context, $this );
	}

	/**
	 * Render the built-in quantity-based stall selection UI.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $data Reservation setup data.
	 * @param array $status Reservation status payload.
	 * @param array $context Additional render context.
	 * @return void
	 */
	private function render_quantity_stall_selection_ui( $reservation_id, $data, $status, $context = array() ) {
		$stall_product_description = '';
		$stall_early_bird_active   = ! empty( $context['stall_early_bird_active'] );
		$stall_assignment_enabled  = ! empty( $context['stall_assignment_enabled'] );
		$stall_assignment_blocks   = isset( $context['stall_assignment_blocks'] ) && is_array( $context['stall_assignment_blocks'] ) ? $context['stall_assignment_blocks'] : array();
		$stall_map_url             = isset( $context['stall_map_url'] ) ? $context['stall_map_url'] : '';

		if ( ! empty( $data['required_shavings_enabled'] ) ) {
			if ( absint( $data['required_shavings_per_stall'] ) > 0 ) {
				$stall_product_description = sprintf(
					/* translators: %d: required shavings quantity. */
					__( '%d bags of shavings are required to be purchased per stall.', 'equine-event-manager' ),
					absint( $data['required_shavings_per_stall'] )
				);
			} else {
				$stall_product_description = __( 'Required shavings are automatically calculated from the number of stalls selected.', 'equine-event-manager' );
			}
		}
		?>
		<div class="eem-product-list">
			<?php $this->render_product_list_header(); ?>
			<?php
			$this->render_product_line_item(
				__( 'Stalls', 'equine-event-manager' ),
				$stall_product_description,
				'stall_qty',
				'',
				array(
					'dynamic_price_type' => 'stall',
					'early_bird_active'  => $stall_early_bird_active,
					'max_quantity'       => $status['stall_inventory_remaining'],
				)
			);
			?>
			<?php if ( ! empty( $data['required_shavings_enabled'] ) ) : ?>
				<?php
				$this->render_readonly_product_line_item(
					sprintf(
						/* translators: %s: displayed price. */
						__( 'Required Shavings - %s per bag', 'equine-event-manager' ),
						$this->format_money( (float) $data['required_shavings_price'] )
					),
					__( 'Automatically added to cart.', 'equine-event-manager' ),
					'required_shavings',
					'eem-product-line-item--required-shavings'
				);
				?>
			<?php endif; ?>
		</div>
		<?php if ( $stall_assignment_enabled && ! empty( $stall_assignment_blocks ) ) : ?>
			<?php $this->render_quantity_stall_assignment_selector( $stall_assignment_blocks, $stall_map_url ); ?>
		<?php endif; ?>
		<div class="eem-section-subtotal" aria-live="polite">
			<span><?php esc_html_e( 'Stall Subtotal', 'equine-event-manager' ); ?></span>
			<strong data-eem-total="stall_section_subtotal">$0.00</strong>
		</div>
		<?php
	}

	/**
	 * Render the quantity-mode preferred stall assignment selector.
	 *
	 * @param array  $stall_assignment_blocks Grouped stall assignment blocks.
	 * @param string $stall_map_url Optional stall map URL.
	 * @return void
	 */
	private function render_quantity_stall_assignment_selector( $stall_assignment_blocks, $stall_map_url ) {
		?>
		<div class="eem-stall-assignment-selector">
			<div class="eem-stall-assignment-selector__toggle-row">
				<div class="eem-stall-assignment-selector__toggle-copy">
					<h4 class="eem-stall-assignment-selector__toggle-title"><?php esc_html_e( 'Stall Assignment', 'equine-event-manager' ); ?></h4>
					<p class="eem-stall-assignment-selector__toggle-note"><?php esc_html_e( 'This is not a map of facility stalls. Please refer to the stall map for actual stall placements. If you do not select a stall number, one will be automatically assigned to you after checkout.', 'equine-event-manager' ); ?></p>
				</div>
				<label class="eem-reservation-section-toggle eem-stall-assignment-selector__toggle" aria-label="<?php esc_attr_e( 'Toggle Stall Assignments section', 'equine-event-manager' ); ?>">
					<input type="checkbox" data-eem-stall-assignment-toggle />
					<span class="eem-reservation-section-toggle__track" aria-hidden="true"></span>
				</label>
			</div>
			<div class="eem-stall-assignment-selector__panel" data-eem-stall-assignment-panel hidden>
				<div class="eem-stall-assignment-selector__toolbar">
					<label class="eem-stall-assignment-selector__barn-field">
						<span><?php esc_html_e( 'Stall Barn', 'equine-event-manager' ); ?></span>
						<select data-eem-stall-barn-select>
							<?php foreach ( $stall_assignment_blocks as $index => $block_group ) : ?>
								<option value="<?php echo esc_attr( sanitize_title( $block_group['label'] ) ); ?>" <?php selected( 0, $index ); ?>><?php echo esc_html( $block_group['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php if ( $stall_map_url ) : ?>
						<a class="eem-stall-assignment-selector__map-link" href="<?php echo esc_url( $stall_map_url ); ?>" target="_blank" rel="noopener noreferrer" onclick="window.open(this.href, '_blank', 'noopener'); return false;">
							<span class="eem-stall-assignment-selector__map-link-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M12 3a1 1 0 0 1 1 1v8.59l2.3-2.29a1 1 0 1 1 1.4 1.41l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.41L11 12.59V4a1 1 0 0 1 1-1Zm-7 14a1 1 0 0 1 1 1v1h12v-1a1 1 0 1 1 2 0v1a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3v-1a1 1 0 0 1 1-1Z" fill="currentColor"></path>
								</svg>
							</span>
							<span><?php esc_html_e( 'View Stall Map', 'equine-event-manager' ); ?></span>
						</a>
					<?php endif; ?>
				</div>
				<div class="eem-stall-assignment-selector__legend" aria-hidden="true">
					<span class="eem-stall-assignment-selector__legend-item eem-stall-assignment-selector__legend-item--available"><?php esc_html_e( 'Available', 'equine-event-manager' ); ?></span>
					<span class="eem-stall-assignment-selector__legend-item eem-stall-assignment-selector__legend-item--reserved"><?php esc_html_e( 'Reserved', 'equine-event-manager' ); ?></span>
					<span class="eem-stall-assignment-selector__legend-item eem-stall-assignment-selector__legend-item--blocked"><?php esc_html_e( 'Blocked', 'equine-event-manager' ); ?></span>
				</div>
				<div class="eem-stall-assignment-selector__groups">
					<?php foreach ( $stall_assignment_blocks as $index => $block_group ) : ?>
						<?php $barn_slug = sanitize_title( $block_group['label'] ); ?>
						<div class="eem-stall-assignment-selector__group" data-eem-stall-barn-group data-stall-barn="<?php echo esc_attr( $barn_slug ); ?>"<?php echo 0 === $index ? '' : ' hidden'; ?>>
							<div class="eem-stall-assignment-selector__grid">
								<?php foreach ( $block_group['units'] as $unit ) : ?>
									<label class="eem-stall-assignment-selector__unit" data-eem-stall-unit data-stall-unit="<?php echo esc_attr( $unit ); ?>" data-stall-barn="<?php echo esc_attr( $barn_slug ); ?>">
										<input type="checkbox" name="preferred_stall_units[]" value="<?php echo esc_attr( $unit ); ?>" />
										<span><?php echo esc_html( $unit ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="eem-reservation-help eem-reservation-help--tight"><?php esc_html_e( 'Select up to the number of stalls you reserve. Any remaining stalls will be auto-assigned after checkout.', 'equine-event-manager' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize a stall selection mode value.
	 *
	 * @param mixed $value Raw mode value.
	 * @return string
	 */
	private function sanitize_stall_selection_mode( $value ) {
		$selection_mode = sanitize_key( $value );

		if ( ! in_array( $selection_mode, array( 'quantity', 'exact_map' ), true ) ) {
			return 'quantity';
		}

		return $selection_mode;
	}

	/**
	 * Handle a reservation form submission.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $data Reservation setup data.
	 * @param array $status Open/closed status.
	 * @return string
	 */
	private function handle_reservation_submission( $reservation_id, $data, $status ) {
		if ( ! isset( $_POST['en_reservation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['en_reservation_nonce'] ) ), 'en_submit_reservation_' . $reservation_id ) ) {
			return $this->render_notice( __( 'We could not verify this reservation request. Please refresh the page and try again.', 'equine-event-manager' ), 'error' );
		}

		$submission     = $this->sanitize_submission( $data );
		$errors         = $this->validate_submission( $submission, $status, $data );
		$is_send_link   = ( 'manual' === $submission['invoice_type'] && 'send_payment_link' === $submission['invoice_action_mode'] );
		$is_show_bill   = ( 'manual' === $submission['invoice_type'] && 'add_to_show_bill' === $submission['invoice_action_mode'] );
		$payment_result = array(
			'payment_status'  => 'pending',
			'payment_gateway' => $this->get_configured_payment_gateway(),
			'transaction_id'  => '',
		);

		if ( ! empty( $errors ) ) {
			return $this->render_notice( implode( ' ', $errors ), 'error' );
		}

		$payment_result = $this->process_payment_submission( $reservation_id, $data, $submission, $status );

		if ( is_wp_error( $payment_result ) ) {
			return $this->render_notice( $payment_result->get_error_message(), 'error' );
		}

		$insert_result = $this->insert_reservation_orders( $reservation_id, $data, $submission, $status, $payment_result );

		if ( empty( $insert_result['success'] ) ) {
			return $this->render_notice( __( 'We could not save this reservation request. Please try again.', 'equine-event-manager' ), 'error' );
		}

		if ( empty( $insert_result['duplicate'] ) && ! empty( $insert_result['submission_token'] ) && $is_send_link ) {
			$orders_repository = new EEM_Orders_Repository();
			$order             = $orders_repository->get_order_by_submission_token( $insert_result['submission_token'] );

			if ( ! $order ) {
				return $this->render_notice( __( 'The invoice was created, but the payment link could not be prepared. Please open the order and send the invoice again.', 'equine-event-manager' ), 'error' );
			}

			$admin_helper = new EEM_Admin();
			$sent         = $admin_helper->send_invoice_email_for_order( $order );

			if ( is_wp_error( $sent ) ) {
				return $this->render_notice( $sent->get_error_message(), 'error' );
			}

			return $this->render_notice( __( 'Invoice created and payment link sent to the customer.', 'equine-event-manager' ), 'success' );
		}

		if ( empty( $insert_result['duplicate'] ) && $is_show_bill ) {
			return $this->render_notice( __( 'Invoice created and added to the customer show bill.', 'equine-event-manager' ), 'success' );
		}

		if ( empty( $insert_result['duplicate'] ) && ! empty( $insert_result['submission_token'] ) ) {
			$this->maybe_send_receipt_emails( $insert_result['submission_token'] );
		}

		return $this->render_notice( __( 'Thank you. Your reservation request has been received.', 'equine-event-manager' ), 'success' );
	}

	/**
	 * Check whether the current request is a submission for this reservation.
	 *
	 * @param int $reservation_id Reservation setup ID.
	 * @return bool
	 */
	private function is_current_reservation_submission( $reservation_id ) {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return false;
		}

		$action        = isset( $_POST['en_reservation_action'] ) ? sanitize_key( wp_unslash( $_POST['en_reservation_action'] ) ) : '';
		$submitted_id  = isset( $_POST['en_reservation_id'] ) ? absint( $_POST['en_reservation_id'] ) : 0;

		return 'submit_reservation' === $action && $submitted_id === $reservation_id;
	}

	/**
	 * Sanitize posted reservation values.
	 *
	 * @return array
	 */
	private function sanitize_submission( $data = array() ) {
		$submission = array(
			'first_name'              => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			'last_name'               => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			'email'                   => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'phone'                   => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'billing_first_name'      => isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
			'billing_last_name'       => isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
			'rv_stay_type'            => $this->sanitize_stay_type_value( isset( $_POST['rv_stay_type'] ) ? wp_unslash( $_POST['rv_stay_type'] ) : 'nightly' ),
			'rv_arrival_date'         => isset( $_POST['rv_arrival_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rv_arrival_date'] ) ) : '',
			'rv_departure_date'       => isset( $_POST['rv_departure_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rv_departure_date'] ) ) : '',
			'rv_lot'                  => isset( $_POST['rv_lot'] ) ? sanitize_text_field( wp_unslash( $_POST['rv_lot'] ) ) : '',
			'rv_qty'                  => isset( $_POST['rv_qty'] ) ? absint( $_POST['rv_qty'] ) : 0,
			'billing_address_1'       => isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
			'billing_address_2'       => isset( $_POST['billing_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) : '',
			'billing_city'            => isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
			'billing_state'           => isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
			'billing_postal_code'     => isset( $_POST['billing_postal_code'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postal_code'] ) ) : '',
			'billing_country'         => isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '',
			'notes'                   => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'group_reservation_enabled' => isset( $_POST['group_reservation_enabled'] ) ? 1 : 0,
			'group_rider_count'         => isset( $_POST['group_rider_count'] ) ? absint( $_POST['group_rider_count'] ) : 0,
			'invoice_type'            => $this->sanitize_invoice_type( isset( $_POST['en_invoice_type'] ) ? wp_unslash( $_POST['en_invoice_type'] ) : 'customer' ),
			'invoice_action_mode'     => $this->sanitize_invoice_action_mode( isset( $_POST['en_invoice_action_mode'] ) ? wp_unslash( $_POST['en_invoice_action_mode'] ) : 'charge_now' ),
			'submission_token'        => isset( $_POST['en_submission_token'] ) ? sanitize_text_field( wp_unslash( $_POST['en_submission_token'] ) ) : '',
			'stripe_payment_intent_id' => isset( $_POST['stripe_payment_intent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_payment_intent_id'] ) ) : '',
			'authorize_card_number'    => isset( $_POST['authorize_card_number'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['authorize_card_number'] ) ) : '',
			'authorize_exp_month'      => isset( $_POST['authorize_exp_month'] ) ? sanitize_text_field( wp_unslash( $_POST['authorize_exp_month'] ) ) : '',
			'authorize_exp_year'       => isset( $_POST['authorize_exp_year'] ) ? sanitize_text_field( wp_unslash( $_POST['authorize_exp_year'] ) ) : '',
			'authorize_card_code'      => isset( $_POST['authorize_card_code'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['authorize_card_code'] ) ) : '',
		);

		$submission = array_merge( $submission, $this->get_stall_submission_payload( $data ) );

		foreach ( $this->get_enabled_rv_addon_options( $data ) as $addon_key => $addon ) {
			$submission[ 'rv_addon_' . $addon_key ] = isset( $_POST[ 'rv_addon_' . $addon_key ] ) ? 1 : 0;
		}

		foreach ( $this->get_enabled_general_addon_options( $data ) as $addon_key => $addon ) {
			$submission[ 'general_addon_' . $addon_key . '_qty' ] = isset( $_POST[ 'general_addon_' . $addon_key . '_qty' ] ) ? absint( $_POST[ 'general_addon_' . $addon_key . '_qty' ] ) : 0;
		}

		$submission['group_riders'] = array();

		if ( ! empty( $_POST['group_riders'] ) && is_array( $_POST['group_riders'] ) ) {
			foreach ( wp_unslash( $_POST['group_riders'] ) as $rider ) {
				if ( ! is_array( $rider ) ) {
					continue;
				}

				$submission['group_riders'][] = array(
					'first_name' => isset( $rider['first_name'] ) ? sanitize_text_field( $rider['first_name'] ) : '',
					'last_name'  => isset( $rider['last_name'] ) ? sanitize_text_field( $rider['last_name'] ) : '',
				);
			}
		}

		$submission['phone'] = $this->normalize_phone_number( $submission['phone'] );

		return $submission;
	}

	/**
	 * Build the normalized stall-related submission payload.
	 *
	 * The base plugin remains quantity-first for now, but this creates the
	 * structured payload a future exact-map add-on can extend.
	 *
	 * @param array $data Reservation setup data.
	 * @return array
	 */
	private function get_stall_submission_payload( $data = array() ) {
		$stall_payload = array(
			'stall_selection_mode'    => isset( $data['stall_selection_mode'] ) ? $this->sanitize_stall_selection_mode( $data['stall_selection_mode'] ) : 'quantity',
			'stall_stay_type'         => $this->sanitize_stay_type_value( isset( $_POST['stall_stay_type'] ) ? wp_unslash( $_POST['stall_stay_type'] ) : 'nightly' ),
			'stall_arrival_date'      => isset( $_POST['stall_arrival_date'] ) ? sanitize_text_field( wp_unslash( $_POST['stall_arrival_date'] ) ) : '',
			'stall_departure_date'    => isset( $_POST['stall_departure_date'] ) ? sanitize_text_field( wp_unslash( $_POST['stall_departure_date'] ) ) : '',
			'stall_qty'               => isset( $_POST['stall_qty'] ) ? absint( $_POST['stall_qty'] ) : 0,
			'tack_stall_qty'          => 0,
			'additional_shavings_qty' => 0,
			'preferred_stall_units'   => $this->sanitize_preferred_stall_units( isset( $_POST['preferred_stall_units'] ) ? wp_unslash( $_POST['preferred_stall_units'] ) : array(), $data ),
			'selected_stall_units'    => array(),
			'selected_stall_labels'   => array(),
			'stall_billable_quantity' => 0,
		);

		$stall_payload['selected_stall_units']    = array_values( $stall_payload['preferred_stall_units'] );
		$stall_payload['selected_stall_labels']   = array_values( $stall_payload['preferred_stall_units'] );
		$stall_payload['stall_billable_quantity'] = absint( $stall_payload['stall_qty'] ) + absint( $stall_payload['tack_stall_qty'] );

		$stall_payload = apply_filters( 'eem_submission_data', $stall_payload, $data, $this );

		if ( ! isset( $stall_payload['stall_selection_mode'] ) ) {
			$stall_payload['stall_selection_mode'] = 'quantity';
		}

		$stall_payload['stall_selection_mode'] = $this->sanitize_stall_selection_mode( $stall_payload['stall_selection_mode'] );
		$stall_payload['stall_qty']            = isset( $stall_payload['stall_qty'] ) ? absint( $stall_payload['stall_qty'] ) : 0;
		$stall_payload['tack_stall_qty']       = isset( $stall_payload['tack_stall_qty'] ) ? absint( $stall_payload['tack_stall_qty'] ) : 0;
		$stall_payload['stall_billable_quantity'] = isset( $stall_payload['stall_billable_quantity'] ) ? absint( $stall_payload['stall_billable_quantity'] ) : ( $stall_payload['stall_qty'] + $stall_payload['tack_stall_qty'] );
		$stall_payload['selected_stall_units']  = isset( $stall_payload['selected_stall_units'] ) && is_array( $stall_payload['selected_stall_units'] ) ? array_values( array_map( 'sanitize_text_field', $stall_payload['selected_stall_units'] ) ) : array();
		$stall_payload['selected_stall_labels'] = isset( $stall_payload['selected_stall_labels'] ) && is_array( $stall_payload['selected_stall_labels'] ) ? array_values( array_map( 'sanitize_text_field', $stall_payload['selected_stall_labels'] ) ) : array();
		$stall_payload['preferred_stall_units'] = isset( $stall_payload['preferred_stall_units'] ) && is_array( $stall_payload['preferred_stall_units'] ) ? array_values( array_map( 'sanitize_text_field', $stall_payload['preferred_stall_units'] ) ) : array();

		return $stall_payload;
	}

	/**
	 * Determine whether the current submission contains a stall selection.
	 *
	 * Quantity-based reservations still use the combined stall quantity by
	 * default, but this method provides a single seam for future exact-map
	 * selection logic.
	 *
	 * @param array $submission Submission values.
	 * @param array $data Reservation setup data.
	 * @param array $status Reservation status payload.
	 * @return bool
	 */
	private function has_stall_selection( $submission, $data = array(), $status = array() ) {
		$has_selection = ( absint( isset( $submission['stall_qty'] ) ? $submission['stall_qty'] : 0 ) + absint( isset( $submission['tack_stall_qty'] ) ? $submission['tack_stall_qty'] : 0 ) ) > 0;

		$has_selection = apply_filters( 'eem_has_stall_selection', $has_selection, $submission, $data, $status, $this );

		return (bool) $has_selection;
	}

	/**
	 * Resolve the billable stall quantity for pricing and add-on calculations.
	 *
	 * Quantity-based reservations still use the combined stall quantity by
	 * default, but a future exact-map add-on can override this centrally.
	 *
	 * @param array $submission Submission values.
	 * @param array $data Reservation setup data.
	 * @param array $status Reservation status payload.
	 * @return int
	 */
	private function get_stall_billable_quantity( $submission, $data = array(), $status = array() ) {
		$billable_quantity = absint( isset( $submission['stall_qty'] ) ? $submission['stall_qty'] : 0 ) + absint( isset( $submission['tack_stall_qty'] ) ? $submission['tack_stall_qty'] : 0 );

		if ( isset( $submission['stall_billable_quantity'] ) ) {
			$billable_quantity = absint( $submission['stall_billable_quantity'] );
		}

		$billable_quantity = apply_filters( 'eem_stall_billable_quantity', $billable_quantity, $submission, $data, $status, $this );

		return max( 0, absint( $billable_quantity ) );
	}

	/**
	 * Validate a reservation submission.
	 *
	 * @param array $submission Submission values.
	 * @param array $status Open/closed status.
	 * @param array $data Reservation setup data.
	 * @return array
	 */
	private function validate_submission( $submission, $status, $data ) {
		$errors = array();

		if ( '' === $submission['first_name'] || '' === $submission['last_name'] || '' === $submission['email'] || '' === $submission['phone'] ) {
			$errors[] = __( 'Please enter your name, email, and phone number.', 'equine-event-manager' );
		}

		if ( ! is_email( $submission['email'] ) ) {
			$errors[] = __( 'Please enter a valid email address.', 'equine-event-manager' );
		}

		if ( ! preg_match( '/^\+[0-9\s().-]{7,}$/', $submission['phone'] ) ) {
			$errors[] = __( 'Please enter an international phone number beginning with a country code, such as +1.', 'equine-event-manager' );
		}

		$requires_billing_details = ! ( 'manual' === $submission['invoice_type'] && in_array( $submission['invoice_action_mode'], array( 'send_payment_link', 'add_to_show_bill' ), true ) );

		if ( $requires_billing_details && ( '' === $submission['billing_first_name'] || '' === $submission['billing_last_name'] || '' === $submission['billing_address_1'] || '' === $submission['billing_city'] || '' === $submission['billing_state'] || '' === $submission['billing_postal_code'] || '' === $submission['billing_country'] ) ) {
			$errors[] = __( 'Please enter the full billing details, including billing first and last name.', 'equine-event-manager' );
		}

		if ( '' === $submission['submission_token'] ) {
			$errors[] = __( 'We could not verify this reservation request. Please refresh the page and try again.', 'equine-event-manager' );
		}

		if ( ! empty( $data['group_reservations_enabled'] ) && ! empty( $submission['group_reservation_enabled'] ) ) {
			if ( $submission['group_rider_count'] < 1 ) {
				$errors[] = __( 'Please enter how many riders are included in the group reservation.', 'equine-event-manager' );
			}

			if ( count( $submission['group_riders'] ) < $submission['group_rider_count'] ) {
				$errors[] = __( 'Please complete the first and last name for each rider in the group reservation.', 'equine-event-manager' );
			} else {
				for ( $index = 0; $index < $submission['group_rider_count']; $index++ ) {
					$rider = isset( $submission['group_riders'][ $index ] ) ? $submission['group_riders'][ $index ] : array();

					if ( empty( $rider['first_name'] ) || empty( $rider['last_name'] ) ) {
						$errors[] = __( 'Please complete the first and last name for each rider in the group reservation.', 'equine-event-manager' );
						break;
					}
				}
			}
		}

		$has_stall_selection     = $this->has_stall_selection( $submission, $data, $status );
		$has_rv_selection        = $submission['rv_qty'] > 0;
		$has_shavings_selection  = $submission['additional_shavings_qty'] > 0;
		$has_rv_addon_selection  = false;
		$has_general_addon_selection = false;

		foreach ( $this->get_enabled_rv_addon_options( $data ) as $addon_key => $addon ) {
			if ( ! empty( $submission[ 'rv_addon_' . $addon_key ] ) ) {
				$has_rv_addon_selection = true;
				break;
			}
		}

		foreach ( $this->get_enabled_general_addon_options( $data ) as $addon_key => $addon ) {
			$qty = isset( $submission[ 'general_addon_' . $addon_key . '_qty' ] ) ? absint( $submission[ 'general_addon_' . $addon_key . '_qty' ] ) : 0;

			if ( $qty <= 0 ) {
				continue;
			}

			$has_general_addon_selection = true;

			if ( 'any' === $addon['applies_to'] && ! $has_stall_selection && ! $has_rv_selection ) {
				$errors[] = sprintf( __( 'Please select stalls or RV spots before adding %s.', 'equine-event-manager' ), $addon['name'] );
			}

			if ( 'stall' === $addon['applies_to'] && ! $has_stall_selection ) {
				$errors[] = sprintf( __( 'Please select stalls before adding %s.', 'equine-event-manager' ), $addon['name'] );
			}

			if ( 'rv' === $addon['applies_to'] && ! $has_rv_selection ) {
				$errors[] = sprintf( __( 'Please select RV spots before adding %s.', 'equine-event-manager' ), $addon['name'] );
			}
		}

		if ( $has_stall_selection && ! $status['stalls_open'] ) {
			$errors[] = __( 'Stall reservations are not currently open.', 'equine-event-manager' );
		}

		if ( $has_rv_selection && ! $status['rv_open'] ) {
			$errors[] = __( 'RV reservations are not currently open.', 'equine-event-manager' );
		}

		if ( $has_shavings_selection && empty( $status['shavings_open'] ) ) {
			$errors[] = __( 'Shavings are not currently available.', 'equine-event-manager' );
		}

		if ( $has_stall_selection && ! empty( $status['stalls_sold_out'] ) ) {
			$errors[] = __( 'Stall reservations are sold out.', 'equine-event-manager' );
		}

		if ( $submission['rv_qty'] > 0 && ! empty( $status['rv_sold_out'] ) ) {
			$errors[] = __( 'RV reservations are sold out.', 'equine-event-manager' );
		}

		if ( $has_stall_selection && null !== $status['stall_inventory_remaining'] && ( absint( $submission['stall_qty'] ) + absint( $submission['tack_stall_qty'] ) ) > $status['stall_inventory_remaining'] ) {
			$errors[] = sprintf(
				/* translators: %d: remaining stall inventory. */
				_n( 'Only %d stall space remains available.', 'Only %d stall spaces remain available.', $status['stall_inventory_remaining'], 'equine-event-manager' ),
				$status['stall_inventory_remaining']
			);
		}

		if ( ! empty( $data['rv_lot_selection_enabled'] ) && $has_rv_selection && '' === (string) $submission['rv_lot'] ) {
			$errors[] = __( 'Please choose an RV lot before checking out.', 'equine-event-manager' );
		}

		if ( ! empty( $data['rv_lot_selection_enabled'] ) && $has_rv_selection && '' !== (string) $submission['rv_lot'] ) {
			$selected_rv_lot_inventory = isset( $status['rv_lot_inventory'][ (string) $submission['rv_lot'] ] ) ? $status['rv_lot_inventory'][ (string) $submission['rv_lot'] ] : null;

			if ( is_array( $selected_rv_lot_inventory ) ) {
				if ( ! empty( $selected_rv_lot_inventory['sold_out'] ) ) {
					$errors[] = sprintf(
						/* translators: %s: RV lot name. */
						__( '%s is sold out.', 'equine-event-manager' ),
						isset( $selected_rv_lot_inventory['label'] ) ? $selected_rv_lot_inventory['label'] : __( 'That RV lot', 'equine-event-manager' )
					);
				} elseif ( null !== $selected_rv_lot_inventory['remaining'] && $submission['rv_qty'] > $selected_rv_lot_inventory['remaining'] ) {
					$errors[] = sprintf(
						/* translators: 1: RV lot name, 2: remaining inventory count. */
						_n( 'Only %2$d space remains in %1$s.', 'Only %2$d spaces remain in %1$s.', $selected_rv_lot_inventory['remaining'], 'equine-event-manager' ),
						isset( $selected_rv_lot_inventory['label'] ) ? $selected_rv_lot_inventory['label'] : __( 'that RV lot', 'equine-event-manager' ),
						$selected_rv_lot_inventory['remaining']
					);
				}
			}
		}

		if ( $submission['rv_qty'] > 0 && null !== $status['rv_inventory_remaining'] && $submission['rv_qty'] > $status['rv_inventory_remaining'] ) {
			$errors[] = sprintf(
				/* translators: %d: remaining RV inventory. */
				_n( 'Only %d RV space remains available.', 'Only %d RV spaces remain available.', $status['rv_inventory_remaining'], 'equine-event-manager' ),
				$status['rv_inventory_remaining']
			);
		}

		if ( $has_rv_addon_selection && ! $has_rv_selection ) {
			$errors[] = __( 'Please select at least one RV spot before choosing RV add-ons.', 'equine-event-manager' );
		}

		if ( ! $has_stall_selection && ! $has_rv_selection && ! $has_shavings_selection ) {
			$errors[] = __( 'Please select at least one reservation item.', 'equine-event-manager' );
		}

		if ( ! empty( $data['stall_chart_enabled'] ) ) {
			$valid_stall_units = $this->get_available_stall_assignment_units(
				isset( $_POST['en_reservation_id'] ) ? absint( $_POST['en_reservation_id'] ) : 0,
				$data,
				$submission['stall_arrival_date'],
				$submission['stall_departure_date']
			);
			$preferred_units   = isset( $submission['preferred_stall_units'] ) ? (array) $submission['preferred_stall_units'] : array();

			if ( count( $preferred_units ) > absint( $submission['stall_qty'] ) ) {
				$errors[] = __( 'Please do not choose more preferred stall numbers than the number of stalls you are reserving.', 'equine-event-manager' );
			}

			if ( ! empty( $preferred_units ) ) {
				$unavailable_units = array_values( array_diff( $preferred_units, $valid_stall_units ) );

				if ( ! empty( $unavailable_units ) ) {
					$errors[] = sprintf(
						/* translators: %s: comma-separated stall numbers. */
						__( 'The following stall numbers are not available for the selected dates: %s.', 'equine-event-manager' ),
						implode( ', ', $unavailable_units )
					);
				}
			}
		}

		if ( $has_stall_selection ) {
			$errors = array_merge( $errors, $this->validate_section_stay_selection( $submission, $data, 'stall' ) );
		}

		if ( $submission['rv_qty'] > 0 ) {
			$errors = array_merge( $errors, $this->validate_section_stay_selection( $submission, $data, 'rv' ) );
		}

		return $errors;
	}

	/**
	 * Sanitize submitted preferred stall numbers against configured generated units.
	 *
	 * @param mixed $values Raw submitted values.
	 * @param array $data Reservation data.
	 * @return array
	 */
	private function sanitize_preferred_stall_units( $values, $data ) {
		$allowed_units = $this->get_stall_assignment_unit_pool( $data );
		$values        = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) $values ) ) );

		return array_values( array_intersect( array_unique( $values ), $allowed_units ) );
	}

	/**
	 * Build frontend stall-assignment config for the reservation form.
	 *
	 * @param int   $reservation_id Reservation ID.
	 * @param array $data Reservation data.
	 * @return array<string, mixed>
	 */
	private function get_stall_assignment_frontend_config( $reservation_id, $data ) {
		return array(
			'blocks'        => $this->get_stall_assignment_option_groups( $data ),
			'blocked_units' => array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) ( isset( $data['stall_chart_blocked_stall_units'] ) ? $data['stall_chart_blocked_stall_units'] : array() ) ) ) ),
			'occupied'      => $this->get_stall_assignment_occupancy_map( $reservation_id, $data ),
		);
	}

	/**
	 * Get grouped stall assignment options by configured block.
	 *
	 * @param array $data Reservation data.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_stall_assignment_option_groups( $data ) {
		$groups = array();

		foreach ( (array) $data['stall_chart_stall_blocks'] as $block ) {
			if ( ! is_array( $block ) || empty( $block['label'] ) ) {
				continue;
			}

			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( ! $start || ! $end ) {
				continue;
			}

			$units = array();

			for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
				$units[] = (string) $number;
			}

			if ( empty( $units ) ) {
				continue;
			}

			$groups[] = array(
				'label' => sanitize_text_field( $block['label'] ),
				'units' => $units,
			);
		}

		return $groups;
	}

	/**
	 * Build a nightly occupancy map for existing stall assignments.
	 *
	 * @param int   $reservation_id Reservation ID.
	 * @param array $data Reservation data.
	 * @return array<string, array<int, string>>
	 */
	private function get_stall_assignment_occupancy_map( $reservation_id, $data ) {
		$occupied_map = array();
		$reservation_id = absint( $reservation_id );

		if ( $reservation_id <= 0 ) {
			return $occupied_map;
		}

		$orders = array_filter(
			( new EEM_Orders_Repository() )->get_orders( '', 'date', 'asc' ),
			function ( $order ) use ( $reservation_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === $reservation_id;
			}
		);

		foreach ( (array) $orders as $order ) {
			$stall_quantity = absint( isset( $order['stall_quantity'] ) ? $order['stall_quantity'] : 0 );

			if ( $stall_quantity <= 0 ) {
				continue;
			}

			$order_dates = $this->get_assignment_date_keys(
				isset( $order['stall_arrival_date'] ) ? $order['stall_arrival_date'] : '',
				isset( $order['stall_departure_date'] ) ? $order['stall_departure_date'] : ''
			);

			if ( empty( $order_dates ) ) {
				continue;
			}

			$assigned_units = $this->parse_assigned_units_string(
				$this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' )
			);

			foreach ( $assigned_units as $assigned_unit ) {
				$assigned_unit = sanitize_text_field( $assigned_unit );

				if ( '' === $assigned_unit ) {
					continue;
				}

				if ( ! isset( $occupied_map[ $assigned_unit ] ) || ! is_array( $occupied_map[ $assigned_unit ] ) ) {
					$occupied_map[ $assigned_unit ] = array();
				}

				$occupied_map[ $assigned_unit ] = array_values(
					array_unique(
						array_merge( $occupied_map[ $assigned_unit ], $order_dates )
					)
				);
			}
		}

		return $occupied_map;
	}

	/**
	 * Get the full generated stall assignment pool excluding blocked stalls.
	 *
	 * @param array $data Reservation data.
	 * @return array
	 */
	private function get_stall_assignment_unit_pool( $data ) {
		$units         = array();
		$blocked_units = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) ( isset( $data['stall_chart_blocked_stall_units'] ) ? $data['stall_chart_blocked_stall_units'] : array() ) ) ) );

		foreach ( (array) $data['stall_chart_stall_blocks'] as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( ! $start || ! $end ) {
				continue;
			}

			for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
				$unit = (string) $number;

				if ( in_array( $unit, $blocked_units, true ) ) {
					continue;
				}

				$units[] = $unit;
			}
		}

		$units = array_values( array_unique( $units ) );
		sort( $units, SORT_NATURAL );

		return $units;
	}

	/**
	 * Get currently available stall units for a requested date span.
	 *
	 * @param int    $reservation_id Reservation ID.
	 * @param array  $data Reservation data.
	 * @param string $arrival_date Selected arrival date.
	 * @param string $departure_date Selected departure date.
	 * @return array
	 */
	private function get_available_stall_assignment_units( $reservation_id, $data, $arrival_date, $departure_date ) {
		$available_units = $this->get_stall_assignment_unit_pool( $data );
		$requested_dates = $this->get_assignment_date_keys( $arrival_date, $departure_date );

		if ( empty( $available_units ) || empty( $requested_dates ) || $reservation_id <= 0 ) {
			return $available_units;
		}

		$occupied_units = array();
		$orders         = array_filter(
			( new EEM_Orders_Repository() )->get_orders( '', 'date', 'asc' ),
			function ( $order ) use ( $reservation_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === $reservation_id;
			}
		);
		$assignment_map = array();

		foreach ( (array) $orders as $order ) {
			$stall_quantity = absint( isset( $order['stall_quantity'] ) ? $order['stall_quantity'] : 0 );

			if ( $stall_quantity <= 0 ) {
				continue;
			}

			$order_dates = $this->get_assignment_date_keys(
				isset( $order['stall_arrival_date'] ) ? $order['stall_arrival_date'] : '',
				isset( $order['stall_departure_date'] ) ? $order['stall_departure_date'] : ''
			);

			$preferred_units = $this->parse_assigned_units_string(
				$this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' )
			);
			$assigned_units = $this->allocate_requested_stall_units( $available_units, $assignment_map, $order_dates, $stall_quantity, $preferred_units );

			if ( empty( array_intersect( $requested_dates, $order_dates ) ) ) {
				continue;
			}

			foreach ( $assigned_units as $assigned_unit ) {
				$occupied_units[] = $assigned_unit;
			}
		}

		return array_values( array_diff( $available_units, array_unique( $occupied_units ) ) );
	}

	/**
	 * Allocate effective stall units for availability checks.
	 *
	 * @param array $pool Available stall unit pool.
	 * @param array $map Occupancy map passed by reference.
	 * @param array $dates Requested dates.
	 * @param int   $needed Number of units needed.
	 * @param array $preferred Preferred units to honor first.
	 * @return array
	 */
	private function allocate_requested_stall_units( $pool, &$map, $dates, $needed, $preferred ) {
		$assigned = array();

		foreach ( (array) $preferred as $unit ) {
			$unit = sanitize_text_field( $unit );

			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $unit, $pool, true ) && $this->stall_unit_is_available_for_dates( $map, $unit, $dates ) ) {
				$assigned[] = $unit;
				$this->mark_stall_unit_occupied_for_dates( $map, $unit, $dates );
			}
		}

		foreach ( (array) $pool as $unit ) {
			if ( count( $assigned ) >= $needed ) {
				break;
			}

			if ( in_array( $unit, $assigned, true ) ) {
				continue;
			}

			if ( $this->stall_unit_is_available_for_dates( $map, $unit, $dates ) ) {
				$assigned[] = $unit;
				$this->mark_stall_unit_occupied_for_dates( $map, $unit, $dates );
			}
		}

		return $assigned;
	}

	/**
	 * Determine whether a stall unit is open for all requested dates.
	 *
	 * @param array  $map Occupancy map.
	 * @param string $unit Stall unit.
	 * @param array  $dates Requested dates.
	 * @return bool
	 */
	private function stall_unit_is_available_for_dates( $map, $unit, $dates ) {
		foreach ( (array) $dates as $date_key ) {
			if ( ! empty( $map[ $unit ][ $date_key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark a stall unit occupied for each requested date.
	 *
	 * @param array  $map Occupancy map passed by reference.
	 * @param string $unit Stall unit.
	 * @param array  $dates Requested dates.
	 * @return void
	 */
	private function mark_stall_unit_occupied_for_dates( &$map, $unit, $dates ) {
		foreach ( (array) $dates as $date_key ) {
			if ( ! isset( $map[ $unit ] ) || ! is_array( $map[ $unit ] ) ) {
				$map[ $unit ] = array();
			}

			$map[ $unit ][ $date_key ] = true;
		}
	}

	/**
	 * Build the nightly date keys used by stall assignment availability checks.
	 *
	 * @param string $arrival_date Arrival date.
	 * @param string $departure_date Departure date.
	 * @return array
	 */
	private function get_assignment_date_keys( $arrival_date, $departure_date ) {
		$start = strtotime( (string) $arrival_date );
		$end   = strtotime( (string) $departure_date );
		$dates = array();

		if ( ! $start || ! $end || $end <= $start ) {
			return $dates;
		}

		while ( $start < $end ) {
			$dates[] = gmdate( 'Y-m-d', $start );
			$start   = strtotime( '+1 day', $start );
		}

		return $dates;
	}

	/**
	 * Extract a note-line value from a grouped order component.
	 *
	 * @param array  $order Grouped order payload.
	 * @param string $component_table Component table key.
	 * @param string $label Notes label.
	 * @return string
	 */
	private function get_order_component_note_value( $order, $component_table, $label ) {
		foreach ( (array) $order['components'] as $component ) {
			if ( empty( $component['table'] ) || $component_table !== $component['table'] ) {
				continue;
			}

			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';

			if ( preg_match( '/(?:^|\n)' . preg_quote( $label, '/' ) . ':\s*(.+?)(?:\n|$)/i', $notes, $matches ) ) {
				return trim( sanitize_text_field( $matches[1] ) );
			}
		}

		return '';
	}

	/**
	 * Parse a stored unit string into distinct values.
	 *
	 * @param string $raw_units Raw assigned units text.
	 * @return array
	 */
	private function parse_assigned_units_string( $raw_units ) {
		if ( '' === trim( (string) $raw_units ) ) {
			return array();
		}

		$units = preg_split( '/[\s,]+/', trim( (string) $raw_units ) );
		$units = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', (array) $units ) ) );
		$units = array_values( array_unique( $units ) );
		sort( $units, SORT_NATURAL );

		return $units;
	}

	/**
	 * Sanitize a stay type value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_stay_type_value( $value ) {
		$stay_type = sanitize_key( $value );

		if ( ! in_array( $stay_type, array( 'nightly', 'weekend' ), true ) ) {
			return 'nightly';
		}

		return $stay_type;
	}

	/**
	 * Sanitize invoice source type.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_invoice_type( $value ) {
		$invoice_type = sanitize_key( $value );

		if ( ! in_array( $invoice_type, array( 'customer', 'manual' ), true ) ) {
			return 'customer';
		}

		return $invoice_type;
	}

	/**
	 * Sanitize invoice action mode.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_invoice_action_mode( $value ) {
		$action_mode = sanitize_key( $value );

		if ( ! in_array( $action_mode, array( 'charge_now', 'send_payment_link', 'add_to_show_bill', 'customer_submit' ), true ) ) {
			return 'charge_now';
		}

		return $action_mode;
	}

	/**
	 * Validate stay selection for a reservation section.
	 *
	 * @param array  $submission Submission values.
	 * @param array  $data Reservation configuration.
	 * @param string $section Section slug.
	 * @return array
	 */
	private function validate_section_stay_selection( $submission, $data, $section ) {
		$errors          = array();
		$stay_type_key   = $section . '_stay_type';
		$arrival_key     = $section . '_arrival_date';
		$departure_key   = $section . '_departure_date';
		$nightly_key     = $section . '_nightly_enabled';
		$weekend_key     = $section . '_weekend_enabled';
		$section_label   = 'rv' === $section ? __( 'RV', 'equine-event-manager' ) : __( 'Stall', 'equine-event-manager' );
		$stay_type       = isset( $submission[ $stay_type_key ] ) ? $submission[ $stay_type_key ] : 'nightly';
		$arrival_date    = isset( $submission[ $arrival_key ] ) ? $submission[ $arrival_key ] : '';
		$departure_date  = isset( $submission[ $departure_key ] ) ? $submission[ $departure_key ] : '';

		if ( 'nightly' === $stay_type && empty( $data[ $nightly_key ] ) ) {
			$errors[] = sprintf( __( '%s nightly reservations are not available for this event.', 'equine-event-manager' ), $section_label );
		}

		if ( 'weekend' === $stay_type && empty( $data[ $weekend_key ] ) ) {
			$errors[] = sprintf( __( '%s weekend package reservations are not available for this event.', 'equine-event-manager' ), $section_label );
		}

		if ( ! $this->is_valid_date( $arrival_date ) || ! $this->is_valid_date( $departure_date ) ) {
			$errors[] = sprintf( __( 'Please choose valid %s arrival and departure dates.', 'equine-event-manager' ), strtolower( $section_label ) );
			return $errors;
		}

		if ( strtotime( $departure_date ) < strtotime( $arrival_date ) ) {
			$errors[] = sprintf( __( '%s departure date must be on or after the arrival date.', 'equine-event-manager' ), $section_label );
		}

		if ( ! empty( $data['available_start_date'] ) && strtotime( $arrival_date ) < strtotime( $data['available_start_date'] ) ) {
			$errors[] = sprintf( __( '%s arrival date is before the available reservation date range.', 'equine-event-manager' ), $section_label );
		}

		if ( ! empty( $data['available_start_date'] ) && strtotime( $departure_date ) < strtotime( $data['available_start_date'] ) ) {
			$errors[] = sprintf( __( '%s departure date is before the available reservation date range.', 'equine-event-manager' ), $section_label );
		}

		if ( ! empty( $data['available_end_date'] ) && strtotime( $arrival_date ) > strtotime( $data['available_end_date'] ) ) {
			$errors[] = sprintf( __( '%s arrival date is after the available reservation date range.', 'equine-event-manager' ), $section_label );
		}

		if ( ! empty( $data['available_end_date'] ) && strtotime( $departure_date ) > strtotime( $data['available_end_date'] ) ) {
			$errors[] = sprintf( __( '%s departure date is after the available reservation date range.', 'equine-event-manager' ), $section_label );
		}

		if ( 'weekend' === $stay_type ) {
			$weekend_start = $this->get_weekend_package_start_date( $data, $section );
			$weekend_end   = $this->get_weekend_package_end_date( $data, $section );

			if ( ! $weekend_start || ! $weekend_end ) {
				$errors[] = sprintf( __( '%s weekend package dates are not configured for this reservation.', 'equine-event-manager' ), $section_label );
			} elseif ( $arrival_date !== $weekend_start || $departure_date !== $weekend_end ) {
				$errors[] = sprintf( __( '%s weekend reservations must use the configured weekend package dates.', 'equine-event-manager' ), $section_label );
			}
		}

		return $errors;
	}

	/**
	 * Normalize a phone number to include a U.S. +1 code when possible.
	 *
	 * @param string $phone Phone input.
	 * @return string
	 */
	private function normalize_phone_number( $phone ) {
		$phone = trim( (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		$digits_only = preg_replace( '/\D+/', '', $phone );

		if ( strlen( $digits_only ) >= 10 ) {
			$local_digits   = substr( $digits_only, -10 );
			$country_digits = substr( $digits_only, 0, -10 );

			if ( '' === $country_digits || preg_match( '/^1+$/', $country_digits ) ) {
				return '+1 ' . $local_digits;
			}
		}

		if ( '+' !== substr( $phone, 0, 1 ) ) {
			if ( 10 === strlen( $digits_only ) ) {
				return '+1 ' . $digits_only;
			}

			if ( 11 === strlen( $digits_only ) && '1' === substr( $digits_only, 0, 1 ) ) {
				return '+1 ' . substr( $digits_only, 1 );
			}
		}

		return $phone;
	}

	/**
	 * Process payment for a validated reservation submission.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $data Reservation setup data.
	 * @param array $submission Submission values.
	 * @param array $status Open/closed status.
	 * @return array|WP_Error
	 */
	private function process_payment_submission( $reservation_id, $data, $submission, $status ) {
		$gateway = $this->get_configured_payment_gateway();
		$totals  = $this->calculate_submission_totals( $data, $submission, $status );
		$is_send_link = ( 'manual' === $submission['invoice_type'] && 'send_payment_link' === $submission['invoice_action_mode'] );
		$is_show_bill = ( 'manual' === $submission['invoice_type'] && 'add_to_show_bill' === $submission['invoice_action_mode'] );

		if ( $is_send_link ) {
			if ( $totals['total'] <= 0 ) {
				return new WP_Error( 'invoice_link_no_balance', __( 'Payment links can only be sent when the order has a balance due.', 'equine-event-manager' ) );
			}

			return array(
				'payment_status'  => 'pending',
				'payment_gateway' => $gateway,
				'transaction_id'  => '',
			);
		}

		if ( $is_show_bill ) {
			if ( $totals['total'] <= 0 ) {
				return new WP_Error( 'show_bill_no_balance', __( 'Show bills can only be created when the order has a balance due.', 'equine-event-manager' ) );
			}

			return array(
				'payment_status'  => 'pending',
				'payment_gateway' => $gateway,
				'transaction_id'  => '',
			);
		}

		if ( $totals['total'] <= 0 ) {
			return array(
				'payment_status'  => 'pending',
				'payment_gateway' => $gateway,
				'transaction_id'  => '',
			);
		}

		if ( 'stripe' === $gateway ) {
			if ( empty( $submission['stripe_payment_intent_id'] ) ) {
				return new WP_Error( 'missing_payment_intent', __( 'Please enter your card details and complete the Stripe payment before submitting.', 'equine-event-manager' ) );
			}

			$stripe_config = $this->get_active_stripe_configuration();

			if ( empty( $stripe_config['secret_key'] ) ) {
				return new WP_Error( 'stripe_not_configured', __( 'Stripe is not fully configured in Settings yet. Please add your Stripe keys first.', 'equine-event-manager' ) );
			}

			$intent = $this->get_stripe_payment_intent( $submission['stripe_payment_intent_id'], $stripe_config['secret_key'] );

			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			if ( empty( $intent['status'] ) || 'succeeded' !== $intent['status'] ) {
				return new WP_Error( 'stripe_not_paid', __( 'Your card payment has not completed yet. Please try again.', 'equine-event-manager' ) );
			}

			if ( absint( $intent['amount'] ) !== absint( round( $totals['total'] * 100 ) ) ) {
				return new WP_Error( 'stripe_amount_mismatch', __( 'The Stripe payment amount did not match this reservation total. Please try again.', 'equine-event-manager' ) );
			}

			if ( ! empty( $intent['metadata']['reservation_id'] ) && absint( $intent['metadata']['reservation_id'] ) !== absint( $reservation_id ) ) {
				return new WP_Error( 'stripe_reservation_mismatch', __( 'This Stripe payment does not belong to the current reservation form.', 'equine-event-manager' ) );
			}

			return array(
				'payment_status'  => 'paid',
				'payment_gateway' => 'stripe',
				'transaction_id'  => sanitize_text_field( $submission['stripe_payment_intent_id'] ),
			);
		}

		if ( 'authorize_net' === $gateway ) {
			return $this->process_authorize_net_payment( $reservation_id, $submission, $totals );
		}

		return array(
			'payment_status'  => 'pending',
			'payment_gateway' => $gateway,
			'transaction_id'  => '',
		);
	}

	/**
	 * Calculate all submitted order totals.
	 *
	 * @param array $data Reservation setup data.
	 * @param array $submission Submission values.
	 * @param array $status Open/closed status.
	 * @return array
	 */
	private function calculate_submission_totals( $data, $submission, $status ) {
		$submission = $this->maybe_sync_submission_stay_values( $submission, $data );
		$stall_qty_total               = $this->get_stall_billable_quantity( $submission, $data, $status );
		$stall_unit_price              = $this->get_current_rate( $data, 'stall', $submission['stall_stay_type'] );
		$rv_unit_price                 = ! empty( $data['rv_lot_selection_enabled'] ) && '' !== (string) $submission['rv_lot'] ? $this->get_rv_lot_rate( $data, $submission['rv_lot'], $submission['rv_stay_type'] ) : $this->get_current_rate( $data, 'rv', $submission['rv_stay_type'] );
		$stall_night_count             = $this->get_billable_stay_units( $submission['stall_arrival_date'], $submission['stall_departure_date'], $submission['stall_stay_type'] );
		$rv_night_count                = $this->get_billable_stay_units( $submission['rv_arrival_date'], $submission['rv_departure_date'], $submission['rv_stay_type'] );
		$required_shavings             = ! empty( $data['required_shavings_enabled'] ) ? $stall_qty_total * absint( $data['required_shavings_per_stall'] ) : 0;
		$required_shavings_subtotal    = $required_shavings * (float) $data['required_shavings_price'];
		$stall_subtotal                = ( $status['stalls_open'] && $stall_qty_total > 0 ) ? ( $stall_qty_total * $stall_unit_price * $stall_night_count ) : 0;
		$additional_shavings_subtotal = ! empty( $data['additional_shavings_enabled'] ) ? ( absint( $submission['additional_shavings_qty'] ) * (float) $data['additional_shavings_price'] ) : 0;
		$group_rider_count            = ( ! empty( $data['group_reservations_enabled'] ) && ! empty( $submission['group_reservation_enabled'] ) ) ? absint( $submission['group_rider_count'] ) : 0;
		$group_rider_grounds_fee_subtotal = ( ! empty( $data['group_rider_grounds_fee_enabled'] ) && $group_rider_count > 0 ) ? $group_rider_count * (float) $data['group_rider_grounds_fee_amount'] : 0.0;
		$group_rider_deposit_subtotal = ( ! empty( $data['group_rider_deposit_enabled'] ) && $group_rider_count > 0 ) ? $group_rider_count * (float) $data['group_rider_deposit_amount'] : 0.0;
		$group_subtotal               = $group_rider_grounds_fee_subtotal + $group_rider_deposit_subtotal;
		$general_addon_subtotals      = array();
		$general_addons_subtotal      = 0.0;
		$stall_subtotal              += $required_shavings_subtotal + $additional_shavings_subtotal;
		$rv_addon_subtotals           = array();
		$rv_subtotal                  = ( $status['rv_open'] && absint( $submission['rv_qty'] ) > 0 ) ? ( absint( $submission['rv_qty'] ) * $rv_unit_price * $rv_night_count ) : 0;

		if ( $status['rv_open'] && absint( $submission['rv_qty'] ) > 0 ) {
			foreach ( $this->get_enabled_rv_addon_options( $data ) as $addon_key => $addon ) {
				if ( empty( $submission[ 'rv_addon_' . $addon_key ] ) ) {
					continue;
				}

				$addon_rate = $this->get_current_rv_addon_rate( $data, $addon_key, $submission['rv_stay_type'] );
				$rv_addon_subtotals[ $addon_key ] = absint( $submission['rv_qty'] ) * $addon_rate * $rv_night_count;
				$rv_subtotal += $rv_addon_subtotals[ $addon_key ];
			}
		}

		foreach ( $this->get_enabled_general_addon_options( $data ) as $addon_key => $addon ) {
			$quantity = isset( $submission[ 'general_addon_' . $addon_key . '_qty' ] ) ? absint( $submission[ 'general_addon_' . $addon_key . '_qty' ] ) : 0;

			if ( $quantity <= 0 ) {
				continue;
			}

			$general_addon_subtotals[ $addon_key ] = $quantity * (float) $addon['price'];
			$general_addons_subtotal              += $general_addon_subtotals[ $addon_key ];
		}

		$subtotal = $stall_subtotal + $rv_subtotal + $general_addons_subtotal + $group_subtotal;
		$fees     = $this->calculate_convenience_fee( $subtotal, $data );

		return array(
			'stall_qty_total'              => $stall_qty_total,
			'stall_unit_price'             => $stall_unit_price,
			'stall_night_count'            => $stall_night_count,
			'rv_unit_price'                => $rv_unit_price,
			'rv_night_count'               => $rv_night_count,
			'rv_addon_subtotals'           => $rv_addon_subtotals,
			'general_addon_subtotals'      => $general_addon_subtotals,
			'general_addons_subtotal'      => $general_addons_subtotal,
			'group_rider_count'            => $group_rider_count,
			'group_rider_grounds_fee_subtotal' => $group_rider_grounds_fee_subtotal,
			'group_rider_deposit_subtotal' => $group_rider_deposit_subtotal,
			'group_subtotal'               => $group_subtotal,
			'required_shavings_qty'        => $required_shavings,
			'required_shavings_subtotal'   => $required_shavings_subtotal,
			'additional_shavings_subtotal' => $additional_shavings_subtotal,
			'stall_subtotal'               => $stall_subtotal,
			'rv_subtotal'                  => $rv_subtotal,
			'subtotal'                     => $subtotal,
			'fees'                         => $fees,
			'total'                        => $subtotal + $fees,
		);
	}

	/**
	 * Keep legacy shared stay selection reservations from fatalling.
	 *
	 * The UI option was removed, but older reservation records may still have the
	 * saved meta flag. In that case we mirror the Stall stay values into the RV
	 * fields so historical setups still behave consistently.
	 *
	 * @param array $submission Submitted reservation values.
	 * @param array $data Reservation setup data.
	 * @return array
	 */
	private function maybe_sync_submission_stay_values( $submission, $data ) {
		if ( empty( $data['sync_stay_selections'] ) ) {
			return $submission;
		}

		$submission['rv_stay_type']       = isset( $submission['stall_stay_type'] ) ? $submission['stall_stay_type'] : ( isset( $submission['rv_stay_type'] ) ? $submission['rv_stay_type'] : 'nightly' );
		$submission['rv_arrival_date']    = isset( $submission['stall_arrival_date'] ) ? $submission['stall_arrival_date'] : ( isset( $submission['rv_arrival_date'] ) ? $submission['rv_arrival_date'] : '' );
		$submission['rv_departure_date']  = isset( $submission['stall_departure_date'] ) ? $submission['stall_departure_date'] : ( isset( $submission['rv_departure_date'] ) ? $submission['rv_departure_date'] : '' );

		return $submission;
	}

	/**
	 * Get the number of billable units for a stay selection.
	 *
	 * Nightly reservations bill per night, while weekend/package reservations
	 * bill once for the configured package.
	 *
	 * @param string $arrival_date Arrival date in Y-m-d.
	 * @param string $departure_date Departure date in Y-m-d.
	 * @param string $stay_type Stay type slug.
	 * @return int
	 */
	private function get_billable_stay_units( $arrival_date, $departure_date, $stay_type ) {
		if ( 'weekend' === $stay_type ) {
			return 1;
		}

		if ( ! $this->is_valid_date( $arrival_date ) || ! $this->is_valid_date( $departure_date ) ) {
			return 1;
		}

		$arrival_timestamp   = strtotime( $arrival_date . ' 00:00:00' );
		$departure_timestamp = strtotime( $departure_date . ' 00:00:00' );

		if ( ! $arrival_timestamp || ! $departure_timestamp ) {
			return 1;
		}

		$night_count = (int) round( ( $departure_timestamp - $arrival_timestamp ) / DAY_IN_SECONDS );

		return max( 1, $night_count );
	}

	/**
	 * Get the total number of nights represented by a date range.
	 *
	 * @param string $arrival_date Arrival date in Y-m-d.
	 * @param string $departure_date Departure date in Y-m-d.
	 * @return int
	 */
	private function get_selected_night_count( $arrival_date, $departure_date ) {
		if ( ! $this->is_valid_date( $arrival_date ) || ! $this->is_valid_date( $departure_date ) ) {
			return 1;
		}

		$arrival_timestamp   = strtotime( $arrival_date . ' 00:00:00' );
		$departure_timestamp = strtotime( $departure_date . ' 00:00:00' );

		if ( ! $arrival_timestamp || ! $departure_timestamp ) {
			return 1;
		}

		$night_count = (int) round( ( $departure_timestamp - $arrival_timestamp ) / DAY_IN_SECONDS );

		return max( 1, $night_count );
	}

	/**
	 * Get the customer-facing stay night count label.
	 *
	 * @param int $night_count Number of nights selected.
	 * @return string
	 */
	private function get_night_count_label( $night_count ) {
		$night_count = max( 1, absint( $night_count ) );

		return sprintf(
			/* translators: %d: selected night count. */
			_n( '%d Night', '%d Nights', $night_count, 'equine-event-manager' ),
			$night_count
		);
	}

	/**
	 * Insert submitted reservation rows into the existing order tables.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $data Reservation setup data.
	 * @param array $submission Submission values.
	 * @param array $status Open/closed status.
	 * @param array $payment_result Payment processing result.
	 * @return array{success:bool,duplicate:bool,submission_token:string}
	 */
	private function insert_reservation_orders( $reservation_id, $data, $submission, $status, $payment_result ) {
		global $wpdb;
		$submission_token = isset( $submission['submission_token'] ) ? sanitize_text_field( $submission['submission_token'] ) : '';

		if ( '' === $submission_token ) {
			return array(
				'success'          => false,
				'duplicate'        => false,
				'submission_token' => '',
			);
		}

		if ( $this->has_processed_submission_token( $submission_token ) ) {
			return array(
				'success'          => true,
				'duplicate'        => true,
				'submission_token' => $submission_token,
			);
		}

		$inserted        = false;
		$event_id        = in_array( $data['event_source'], array( 'tec', 'native' ), true ) ? absint( $data['event_id'] ) : 0;
		$payment_gateway = ! empty( $payment_result['payment_gateway'] ) ? $payment_result['payment_gateway'] : $this->get_configured_payment_gateway();
		$payment_status  = ! empty( $payment_result['payment_status'] ) ? $payment_result['payment_status'] : 'pending';
		$transaction_id  = ! empty( $payment_result['transaction_id'] ) ? $payment_result['transaction_id'] : '';
		$order_number    = '';
		$totals          = $this->calculate_submission_totals( $data, $submission, $status );
		$has_stall_order = ( $status['stalls_open'] && $this->has_stall_selection( $submission, $data, $status ) ) || ( ! empty( $status['shavings_open'] ) && $submission['additional_shavings_qty'] > 0 );
		$has_rv_order    = $status['rv_open'] && $submission['rv_qty'] > 0;
		$has_group_fees  = ! empty( $totals['group_subtotal'] );
		$attach_general_addons_to = $has_stall_order ? 'stall' : 'rv';
		$attach_group_charges_to  = $has_stall_order ? 'stall' : 'rv';

		if ( $has_group_fees && ! $has_stall_order && ! $has_rv_order ) {
			$has_stall_order          = true;
			$attach_general_addons_to = 'stall';
			$attach_group_charges_to  = 'stall';
		}

		if ( $has_stall_order || $has_rv_order || ! empty( $totals['general_addons_subtotal'] ) || $has_group_fees ) {
			$order_number = ( new EEM_Orders_Repository() )->reserve_order_number();
		}
		$customer_name = trim( $submission['first_name'] . ' ' . $submission['last_name'] );
		$billing_notes = sprintf(
			/* translators: 1: billing full name, 2: address line 1, 3: address line 2, 4: city, 5: state, 6: postal code, 7: country. */
			__( "Billing Name: %1\$s\nBilling Address: %2\$s%3\$s\n%4\$s, %5\$s %6\$s\n%7\$s", 'equine-event-manager' ),
			trim( $submission['billing_first_name'] . ' ' . $submission['billing_last_name'] ),
			$submission['billing_address_1'],
			$submission['billing_address_2'] ? "\n" . $submission['billing_address_2'] : '',
			$submission['billing_city'],
			$submission['billing_state'],
			$submission['billing_postal_code'],
			$submission['billing_country']
		);
		$agreement_notes = ! empty( $data['venue_agreement_enabled'] ) && ! empty( $data['venue_agreement_file_id'] ) ? __( 'Venue Agreement Provided: Yes', 'equine-event-manager' ) . "\n" : '';
		$general_addon_notes = '';
		$group_reservation_notes = '';

		foreach ( $this->get_enabled_general_addon_options( $data ) as $addon_key => $addon ) {
			$quantity = isset( $submission[ 'general_addon_' . $addon_key . '_qty' ] ) ? absint( $submission[ 'general_addon_' . $addon_key . '_qty' ] ) : 0;

			if ( $quantity <= 0 ) {
				continue;
			}

			$subtotal = isset( $totals['general_addon_subtotals'][ $addon_key ] ) ? (float) $totals['general_addon_subtotals'][ $addon_key ] : 0.0;
			$general_addon_notes .= sprintf(
				"Add-On: %1\$s | Qty: %2\$d | Per: %3\$s | Subtotal: %4\$s\n",
				$addon['name'],
				$quantity,
				! empty( $addon['per_label'] ) ? $addon['per_label'] : __( 'qty', 'equine-event-manager' ),
				$this->format_money( $subtotal )
			);
		}

		if ( ! empty( $data['group_reservations_enabled'] ) && ! empty( $submission['group_reservation_enabled'] ) && $submission['group_rider_count'] > 0 ) {
			$group_names = array();

			for ( $index = 0; $index < $submission['group_rider_count']; $index++ ) {
				$rider = isset( $submission['group_riders'][ $index ] ) ? $submission['group_riders'][ $index ] : array();
				$name  = trim( ( isset( $rider['first_name'] ) ? $rider['first_name'] : '' ) . ' ' . ( isset( $rider['last_name'] ) ? $rider['last_name'] : '' ) );

				if ( '' !== $name ) {
					$group_names[] = $name;
				}
			}

			$group_reservation_notes = "Group Reservation: Yes\n";
			$group_reservation_notes .= 'Group Riders Count: ' . absint( $submission['group_rider_count'] ) . "\n";

			if ( ! empty( $group_names ) ) {
				$group_reservation_notes .= 'Group Riders: ' . implode( ' | ', $group_names ) . "\n";
			}

			if ( ! empty( $totals['group_rider_grounds_fee_subtotal'] ) ) {
				$group_reservation_notes .= sprintf(
					"Group Charge: %1\$s | Qty: %2\$d | Rate: %3\$s | Subtotal: %4\$s\n",
					__( 'Rider Grounds Fee', 'equine-event-manager' ),
					absint( $submission['group_rider_count'] ),
					$this->format_money( $data['group_rider_grounds_fee_amount'] ),
					$this->format_money( $totals['group_rider_grounds_fee_subtotal'] )
				);
			}

			if ( ! empty( $totals['group_rider_deposit_subtotal'] ) ) {
				$group_reservation_notes .= sprintf(
					"Group Charge: %1\$s | Qty: %2\$d | Rate: %3\$s | Subtotal: %4\$s\n",
					__( 'Rider Deposit', 'equine-event-manager' ),
					absint( $submission['group_rider_count'] ),
					$this->format_money( $data['group_rider_deposit_amount'] ),
					$this->format_money( $totals['group_rider_deposit_subtotal'] )
				);
			}
		}

		$invoice_type_note   = 'manual' === $submission['invoice_type'] ? __( 'Admin', 'equine-event-manager' ) : __( 'Customer', 'equine-event-manager' );
		$show_bill_note_line = ( 'manual' === $submission['invoice_type'] && 'add_to_show_bill' === $submission['invoice_action_mode'] ) ? "\nShow Bill Status: Outstanding" : '';
		$notes = trim( $submission['notes'] . "\n\n" . $billing_notes . "\n" . $agreement_notes . $general_addon_notes . $group_reservation_notes . "\nInvoice Type: " . $invoice_type_note . $show_bill_note_line . "\nReservation setup ID: " . absint( $reservation_id ) . "\nSubmission token: " . $submission_token );
		$created       = current_time( 'mysql' );

		if ( $has_stall_order ) {
			$stall_table       = $wpdb->prefix . 'en_stall_reservations';
			$stall_unit_price  = $totals['stall_unit_price'];
			$required_shavings = $totals['required_shavings_qty'];
			$stall_subtotal    = $totals['stall_subtotal'] + ( 'stall' === $attach_general_addons_to ? (float) $totals['general_addons_subtotal'] : 0.0 ) + ( 'stall' === $attach_group_charges_to ? (float) $totals['group_subtotal'] : 0.0 );
			$stall_fee         = $this->calculate_convenience_fee( $stall_subtotal, $data );

			$stall_notes = $notes;

			if ( ! empty( $submission['preferred_stall_units'] ) ) {
				$stall_notes = trim( $stall_notes . "\nAssigned Stall Units: " . implode( ', ', array_map( 'sanitize_text_field', (array) $submission['preferred_stall_units'] ) ) );
			}

			$inserted = false !== $wpdb->insert(
				$stall_table,
				array(
					'event_source'              => $data['event_source'],
					'event_id'                  => $event_id,
					'external_event_id'         => $data['external_event_id'],
					'customer_name'             => $customer_name,
					'email'                     => $submission['email'],
					'phone'                     => $submission['phone'],
					'stall_qty'                 => $submission['stall_qty'],
					'tack_stall_qty'            => $submission['tack_stall_qty'],
					'stay_type'                 => $submission['stall_stay_type'],
					'arrival_date'              => $submission['stall_arrival_date'],
					'departure_date'            => $submission['stall_departure_date'],
					'required_shavings_qty'     => $required_shavings,
					'additional_shavings_qty'   => $submission['additional_shavings_qty'],
					'unit_price'                => $stall_unit_price,
					'subtotal'                  => $stall_subtotal,
					'convenience_fee'           => $stall_fee,
					'total'                     => $stall_subtotal + $stall_fee,
					'payment_status'            => $payment_status,
					'payment_gateway'           => $payment_gateway,
					'order_number'              => $order_number,
					'transaction_id'            => $transaction_id,
					'refund_transaction_id'     => '',
					'refunded_at'               => null,
					'notes'                     => $stall_notes,
					'created_at'                => $created,
				)
			) || $inserted;
		}

		if ( $has_rv_order ) {
			$rv_table      = $wpdb->prefix . 'en_rv_reservations';
			$rv_unit_price = $totals['rv_unit_price'];
			$rv_subtotal   = $totals['rv_subtotal'] + ( 'rv' === $attach_general_addons_to ? (float) $totals['general_addons_subtotal'] : 0.0 ) + ( 'rv' === $attach_group_charges_to ? (float) $totals['group_subtotal'] : 0.0 );
			$rv_fee        = $this->calculate_convenience_fee( $rv_subtotal, $data );
			$rv_addon_labels = array();

			foreach ( $this->get_enabled_rv_addon_options( $data ) as $addon_key => $addon ) {
				if ( ! empty( $submission[ 'rv_addon_' . $addon_key ] ) ) {
					$rv_addon_labels[] = $addon['name'];
				}
			}

			$rv_notes = $notes;
			$rv_lot = ! empty( $data['rv_lot_selection_enabled'] ) && '' !== (string) $submission['rv_lot'] ? $this->get_rv_lot( $data, $submission['rv_lot'] ) : null;

			if ( $rv_lot && ! empty( $rv_lot['name'] ) ) {
				$rv_notes = trim( $rv_notes . "
RV Lot: " . $rv_lot['name'] );
			}

			if ( ! empty( $rv_addon_labels ) ) {
				$rv_notes = trim( $rv_notes . "\nRV Add-Ons: " . implode( ', ', $rv_addon_labels ) );
			}

			$inserted = false !== $wpdb->insert(
				$rv_table,
				array(
					'event_source'      => $data['event_source'],
					'event_id'          => $event_id,
					'external_event_id' => $data['external_event_id'],
					'customer_name'     => $customer_name,
					'email'             => $submission['email'],
					'phone'             => $submission['phone'],
					'rv_qty'            => $submission['rv_qty'],
					'rv_type'           => implode( ', ', $rv_addon_labels ),
					'stay_type'         => $submission['rv_stay_type'],
					'arrival_date'      => $submission['rv_arrival_date'],
					'departure_date'    => $submission['rv_departure_date'],
					'unit_price'        => $rv_unit_price,
					'subtotal'          => $rv_subtotal,
					'convenience_fee'   => $rv_fee,
					'total'             => $rv_subtotal + $rv_fee,
					'payment_status'    => $payment_status,
					'payment_gateway'   => $payment_gateway,
					'order_number'      => $order_number,
					'transaction_id'    => $transaction_id,
					'refund_transaction_id' => '',
					'refunded_at'       => null,
					'notes'             => $rv_notes,
					'created_at'        => $created,
				)
			) || $inserted;
		}

		if ( $inserted ) {
			$this->mark_submission_token_processed( $submission_token );

			$order_repository = new EEM_Orders_Repository();
			$saved_order      = $order_repository->get_order_by_submission_token( $submission_token );

			if ( $saved_order && ! empty( $saved_order['order_key'] ) ) {
				$order_repository->auto_assign_units_for_order( $saved_order['order_key'] );
			}
		}

		return array(
			'success'          => (bool) $inserted,
			'duplicate'        => false,
			'submission_token' => $submission_token,
		);
	}

	/**
	 * Send customer/admin receipt emails for a saved reservation order.
	 *
	 * @param string $submission_token Submission token.
	 * @return void
	 */
	private function maybe_send_receipt_emails( $submission_token ) {
		$orders_repository = new EEM_Orders_Repository();
		$order             = $orders_repository->get_order_by_submission_token( $submission_token );

		if ( ! $order ) {
			return;
		}

		$this->send_receipt_emails_for_order( $order );
	}

	/**
	 * Send customer/admin receipt emails for an order payload.
	 *
	 * @param array $order Order payload.
	 * @return void
	 */
	private function send_receipt_emails_for_order( $order ) {
		$receipt_settings = $this->get_receipt_settings();
		$headers          = $this->get_receipt_mail_headers( $receipt_settings );

		if ( ! empty( $receipt_settings['customer_receipt_enabled'] ) ) {
			$this->send_customer_notification_email_for_order( $order );
		}

		if ( ! empty( $receipt_settings['admin_receipt_email'] ) && is_email( $receipt_settings['admin_receipt_email'] ) ) {
			EEM_Mailer::send_html_email(
				$receipt_settings['admin_receipt_email'],
				$this->replace_receipt_tokens( $receipt_settings['admin_subject'], $order ),
				$this->build_receipt_email_html( $order, $receipt_settings['admin_body'] ),
				$headers
			);
		}
	}

	/**
	 * Send the customer-facing order notification/receipt email for an order.
	 *
	 * @param array $order Order payload.
	 * @return true|WP_Error
	 */
	public function send_customer_notification_email_for_order( $order ) {
		$receipt_settings = $this->get_receipt_settings();
		$headers          = $this->get_receipt_mail_headers( $receipt_settings );
		$customer_email   = $this->get_order_customer_email( $order );

		if ( ! $customer_email ) {
			return new WP_Error( 'customer_notification_missing_email', __( 'A customer email address is required before sending this notification.', 'equine-event-manager' ) );
		}

		return EEM_Mailer::send_html_email(
			$customer_email,
			$this->replace_receipt_tokens( $receipt_settings['customer_subject'], $order ),
			$this->build_receipt_email_html( $order, $receipt_settings['customer_body'] ),
			$headers
		);
	}

	/**
	 * Get formatted mail headers for receipt emails.
	 *
	 * @param array $receipt_settings Receipt settings.
	 * @return array
	 */
	private function get_receipt_mail_headers( $receipt_settings ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name = trim( (string) $receipt_settings['from_name'] );
		$from_email = trim( (string) $receipt_settings['from_email'] );
		$reply_to_email = trim( (string) $receipt_settings['reply_to_email'] );

		if ( $from_name && is_email( $from_email ) ) {
			$headers[] = 'From: ' . wp_specialchars_decode( $from_name, ENT_QUOTES ) . ' <' . $from_email . '>';
		}

		if ( is_email( $reply_to_email ) ) {
			$headers[] = 'Reply-To: ' . $reply_to_email;
		}

		return $headers;
	}

	/**
	 * Build the HTML receipt email body.
	 *
	 * @param array  $order Order payload.
	 * @param string $message_template Configured body template.
	 * @return string
	 */
	private function build_receipt_email_html( $order, $message_template ) {
		$company_logo_url   = $this->get_company_logo_url( 'medium' );
		$event_label        = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$message_text       = $this->replace_receipt_tokens( $message_template, $order );
		$message_text       = preg_replace( '/\s*A PDF copy of your receipt is attached\.?/i', '', (string) $message_text );
		$message_text       = preg_replace( '/\s*A PDF receipt is attached for your records\.?/i', '', (string) $message_text );
		$message_text       = preg_replace( '/\s*The attached PDF includes[^.]*\.?/i', '', (string) $message_text );
		$message            = nl2br( esc_html( trim( preg_replace( "/
{3,}/", "

", (string) $message_text ) ) ) );
		$company_settings   = $this->get_company_settings();
		$support_phone      = trim( (string) $company_settings['support_phone'] );
		$support_email      = trim( (string) $company_settings['support_email'] );
		$billing_details    = $this->get_billing_details_from_order_notes( $order['notes'] );
		$special_requests   = $this->get_special_requests_from_order_notes( $order['notes'] );
		$stall_breakdown    = $this->get_order_stall_breakdown( $order );
		$rv_addon_breakdown = $this->get_order_rv_addon_breakdown( $order );
		$general_addons     = $this->extract_general_addon_breakdown_from_notes( $order['notes'] );
		$group_charges      = $this->extract_group_charge_breakdown_from_notes( $order['notes'] );
		$rv_addon_total     = array_sum( wp_list_pluck( $rv_addon_breakdown, 'subtotal' ) );
		$rv_base_subtotal   = max( 0, (float) $order['rv_subtotal'] - $rv_addon_total );
		$stall_nights       = $this->get_night_count_label( $this->get_billable_stay_units( $order['stall_arrival_date'], $order['stall_departure_date'], $order['stall_stay_type'] ) );
		$rv_nights          = $this->get_night_count_label( $this->get_billable_stay_units( $order['rv_arrival_date'], $order['rv_departure_date'], $order['rv_stay_type'] ) );
		$rv_addon_labels    = $this->parse_rv_addon_labels( isset( $order['rv_type'] ) ? $order['rv_type'] : '' );
		$group_rider_count  = $this->extract_group_rider_count_from_notes( $order['notes'] );
		$group_rider_names  = $this->extract_group_rider_names_from_notes( $order['notes'] );
		$reservation_data   = ! empty( $order['reservation_id'] ) ? $this->get_reservation_data( absint( $order['reservation_id'] ) ) : array();
		$venue_lines        = array_filter(
			array(
				! empty( $reservation_data['venue_name'] ) ? $reservation_data['venue_name'] : '',
				! empty( $reservation_data['venue_address'] ) ? $reservation_data['venue_address'] : '',
				! empty( $reservation_data['event_location'] ) ? $reservation_data['event_location'] : '',
			)
		);
		$event_summary      = ! empty( $reservation_data['event_details_summary'] ) ? trim( (string) $reservation_data['event_details_summary'] ) : '';
		$venue_map_url      = ! empty( $reservation_data['venue_map_enabled'] ) && ! empty( $reservation_data['venue_map_download_url'] ) ? esc_url( $reservation_data['venue_map_download_url'] ) : '';
		$receipt_lines      = array();
		$summary_sections   = array();

		if ( absint( $order['stall_quantity'] ) > 0 ) {
			$summary_sections[] = array(
				'title' => __( 'Stall Summary', 'equine-event-manager' ),
				'rows'  => array(
					array( 'label' => __( 'Check In', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['stall_arrival_date'] ) ),
					array( 'label' => __( 'Check Out', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['stall_departure_date'] ) ),
					array( 'label' => __( 'Stay Type', 'equine-event-manager' ), 'value' => $this->format_stay_type_label( $order['stall_stay_type'] ) ),
					array( 'label' => __( 'Number of Stalls', 'equine-event-manager' ), 'value' => absint( $order['stall_quantity'] ) ),
					array( 'label' => __( 'Nights', 'equine-event-manager' ), 'value' => $stall_nights ),
					array( 'label' => __( 'Required Shavings', 'equine-event-manager' ), 'value' => absint( $order['required_shavings_qty'] ) ),
				),
			);
		}

		if ( absint( $order['rv_quantity'] ) > 0 ) {
			$summary_sections[] = array(
				'title' => __( 'RV Summary', 'equine-event-manager' ),
				'rows'  => array(
					array( 'label' => __( 'Check In', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['rv_arrival_date'] ) ),
					array( 'label' => __( 'Check Out', 'equine-event-manager' ), 'value' => $this->format_reservation_date_label( $order['rv_departure_date'] ) ),
					array( 'label' => __( 'Stay Type', 'equine-event-manager' ), 'value' => $this->format_stay_type_label( $order['rv_stay_type'] ) ),
					array( 'label' => __( 'Number of Spots', 'equine-event-manager' ), 'value' => absint( $order['rv_quantity'] ) ),
					array( 'label' => __( 'Nights', 'equine-event-manager' ), 'value' => $rv_nights ),
					array( 'label' => __( 'Spot Type', 'equine-event-manager' ), 'value' => ! empty( $rv_addon_labels ) ? implode( ', ', $rv_addon_labels ) : __( 'Standard', 'equine-event-manager' ) ),
				),
			);
		}

		if ( $group_rider_count > 0 ) {
			$group_rows = array(
				array( 'label' => __( 'Number of Riders', 'equine-event-manager' ), 'value' => $group_rider_count ),
				array( 'label' => __( 'Rider Names', 'equine-event-manager' ), 'value' => ! empty( $group_rider_names ) ? implode( ', ', $group_rider_names ) : __( 'Captured on order', 'equine-event-manager' ) ),
			);

			foreach ( $group_charges as $group_charge ) {
				if ( (float) $group_charge['subtotal'] <= 0 ) {
					continue;
				}

				$group_rows[] = array(
					'label' => $group_charge['label'],
					'value' => sprintf(
						/* translators: 1: quantity, 2: subtotal. */
						__( '%1$d riders | %2$s', 'equine-event-manager' ),
						absint( $group_charge['quantity'] ),
						$this->format_money( $group_charge['subtotal'] )
					),
				);
			}

			$summary_sections[] = array(
				'title' => __( 'Group Reservations', 'equine-event-manager' ),
				'rows'  => $group_rows,
			);
		}

		$payment_rows = array();

		if ( (float) $stall_breakdown['base_subtotal'] > 0 ) {
			$payment_rows[] = array( 'label' => __( 'Stall Subtotal', 'equine-event-manager' ), 'amount' => (float) $stall_breakdown['base_subtotal'] );
		}
		if ( (float) $stall_breakdown['required_shavings_subtotal'] > 0 ) {
			$payment_rows[] = array( 'label' => __( 'Required Shavings', 'equine-event-manager' ), 'amount' => (float) $stall_breakdown['required_shavings_subtotal'] );
		}
		if ( (float) $rv_base_subtotal > 0 ) {
			$payment_rows[] = array( 'label' => __( 'RV Subtotal', 'equine-event-manager' ), 'amount' => (float) $rv_base_subtotal );
		}
		foreach ( $rv_addon_breakdown as $addon_row ) {
			if ( (float) $addon_row['subtotal'] <= 0 ) { continue; }
			$payment_rows[] = array( 'label' => sprintf( __( '%s Add-On', 'equine-event-manager' ), $addon_row['label'] ), 'amount' => (float) $addon_row['subtotal'] );
		}
		foreach ( $general_addons as $addon_row ) {
			if ( (float) $addon_row['subtotal'] <= 0 ) { continue; }
			$payment_rows[] = array( 'label' => $addon_row['label'], 'amount' => (float) $addon_row['subtotal'] );
		}
		foreach ( $group_charges as $group_charge ) {
			if ( (float) $group_charge['subtotal'] <= 0 ) { continue; }
			$payment_rows[] = array( 'label' => $group_charge['label'], 'amount' => (float) $group_charge['subtotal'] );
		}
		if ( (float) $order['fees'] > 0 ) {
			$payment_rows[] = array( 'label' => __( 'Non-Refundable Convenience Fee', 'equine-event-manager' ), 'amount' => (float) $order['fees'] );
		}
		$payment_rows[] = array( 'label' => __( 'Total Amount Paid', 'equine-event-manager' ), 'amount' => (float) $order['total'], 'is_total' => true );

		if ( (float) $stall_breakdown['base_subtotal'] > 0 && absint( $order['stall_quantity'] ) > 0 ) {
			$receipt_lines[] = array(
				'label'  => sprintf(
					/* translators: 1: quantity, 2: nights label */
					__( '%1$s stall(s) x %2$s', 'equine-event-manager' ),
					absint( $order['stall_quantity'] ),
					$stall_nights
				),
				'amount' => (float) $stall_breakdown['base_subtotal'],
			);
		}

		if ( (float) $stall_breakdown['required_shavings_subtotal'] > 0 ) {
			$receipt_lines[] = array(
				'label'  => sprintf(
					/* translators: %d: quantity */
					__( '%d required shavings', 'equine-event-manager' ),
					absint( $order['required_shavings_qty'] )
				),
				'amount' => (float) $stall_breakdown['required_shavings_subtotal'],
			);
		}

		if ( (float) $rv_base_subtotal > 0 && absint( $order['rv_quantity'] ) > 0 ) {
			$receipt_lines[] = array(
				'label'  => sprintf(
					/* translators: 1: quantity, 2: nights label */
					__( '%1$s RV spot(s) x %2$s', 'equine-event-manager' ),
					absint( $order['rv_quantity'] ),
					$rv_nights
				),
				'amount' => (float) $rv_base_subtotal,
			);
		}

		foreach ( $rv_addon_breakdown as $addon_row ) {
			if ( (float) $addon_row['subtotal'] <= 0 ) {
				continue;
			}

			$receipt_lines[] = array(
				'label'  => $addon_row['label'],
				'amount' => (float) $addon_row['subtotal'],
			);
		}

		foreach ( $general_addons as $addon_row ) {
			if ( (float) $addon_row['subtotal'] <= 0 ) {
				continue;
			}

			$receipt_lines[] = array(
				'label'  => sprintf(
					/* translators: 1: add-on name, 2: quantity, 3: unit label */
					__( '%1$s (%2$d %3$s)', 'equine-event-manager' ),
					$addon_row['label'],
					absint( $addon_row['quantity'] ),
					! empty( $addon_row['per_label'] ) ? $addon_row['per_label'] : __( 'qty', 'equine-event-manager' )
				),
				'amount' => (float) $addon_row['subtotal'],
			);
		}

		$footer_chunks = array_filter(
			array(
				$support_phone ? sprintf( __( 'Support: %s', 'equine-event-manager' ), $this->format_phone_label( $support_phone ) ) : '',
				$support_email ? sprintf( __( 'Email: %s', 'equine-event-manager' ), $support_email ) : '',
				$event_label,
			)
		);

		ob_start();
		?>
		<div style="margin:0;padding:28px;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:760px;margin:0 auto;">
				<?php if ( $company_logo_url ) : ?>
					<p style="margin:0 0 18px;text-align:center;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:inline-block;object-fit:contain;" /></p>
				<?php endif; ?>
				<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;color:#111827;">
					<div style="margin:0 0 8px;font-size:12px;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;color:#5b6472;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: order number */
								__( 'Reservation Receipt #%s', 'equine-event-manager' ),
								$order['order_number']
							)
						);
						?>
					</div>
					<h1 style="margin:0 0 10px;font-size:28px;line-height:1.08;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
					<div style="margin:0;color:#4b5563;font-size:15px;line-height:1.7;"><?php echo wp_kses_post( wpautop( $message ) ); ?></div>
				</div>

				<?php if ( ! empty( $summary_sections ) ) : ?>
					<?php foreach ( $summary_sections as $summary_section ) : ?>
						<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
							<h2 style="margin:0 0 16px;font-size:15px;line-height:1.3;color:#111827;"><?php echo esc_html( $summary_section['title'] ); ?></h2>
							<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;">
								<?php foreach ( $summary_section['rows'] as $summary_row ) : ?>
									<div>
										<div style="margin:0 0 4px;font-size:13px;font-weight:800;color:#111827;"><?php echo esc_html( $summary_row['label'] ); ?></div>
										<div style="font-size:15px;line-height:1.6;color:#4b5563;"><?php echo esc_html( $summary_row['value'] ); ?></div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php if ( ! empty( $venue_lines ) ) : ?>
					<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
						<h2 style="margin:0 0 12px;font-size:15px;line-height:1.3;color:#111827;"><?php esc_html_e( 'Location', 'equine-event-manager' ); ?></h2>
						<div style="font-size:15px;line-height:1.7;color:#4b5563;"><?php echo wp_kses_post( nl2br( esc_html( implode( "\n", $venue_lines ) ) ) ); ?></div>
						<?php if ( $venue_map_url ) : ?>
							<div style="margin-top:6px;"><a href="<?php echo esc_url( $venue_map_url ); ?>" style="color:#111827;text-decoration:none;font-weight:700;"><?php esc_html_e( 'Download Venue Map', 'equine-event-manager' ); ?></a></div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $event_summary ) : ?>
					<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
						<h2 style="margin:0 0 12px;font-size:15px;line-height:1.3;color:#111827;"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></h2>
						<div style="font-size:15px;line-height:1.7;color:#4b5563;"><?php echo wp_kses_post( nl2br( esc_html( $event_summary ) ) ); ?></div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $general_addons ) ) : ?>
					<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
						<h2 style="margin:0 0 16px;font-size:15px;line-height:1.3;color:#111827;"><?php esc_html_e( 'Add Ons', 'equine-event-manager' ); ?></h2>
						<?php foreach ( $general_addons as $addon_row ) : ?>
							<div style="margin:0 0 12px;">
								<div style="font-size:14px;font-weight:800;color:#111827;"><?php echo esc_html( $addon_row['label'] ); ?></div>
								<div style="font-size:14px;line-height:1.6;color:#5b6472;"><?php echo esc_html( sprintf( '%1$d %2$s', absint( $addon_row['quantity'] ), ! empty( $addon_row['per_label'] ) ? $addon_row['per_label'] : __( 'units', 'equine-event-manager' ) ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
					<h2 style="margin:0 0 16px;font-size:15px;line-height:1.3;color:#111827;"><?php esc_html_e( 'Payment Summary', 'equine-event-manager' ); ?></h2>
					<div style="display:flex;justify-content:space-between;gap:12px;padding:0 0 14px;font-size:15px;font-weight:800;color:#111827;">
							<span><?php echo esc_html( $this->format_money( (float) $order['total'] ) . ' ' . __( 'payment received', 'equine-event-manager' ) ); ?></span>
						<?php if ( ! empty( $order['transaction_id'] ) ) : ?>
							<span style="color:#5b6472;"><?php echo esc_html( $order['transaction_id'] ); ?></span>
						<?php endif; ?>
					</div>
					<?php foreach ( $receipt_lines as $receipt_line ) : ?>
					<div style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #d9e1ea;font-size:14px;color:#4b5563;">
							<span><?php echo esc_html( $receipt_line['label'] ); ?></span>
							<span style="font-weight:700;color:#111827;"><?php echo esc_html( $this->format_money( (float) $receipt_line['amount'] ) ); ?></span>
						</div>
					<?php endforeach; ?>
					<?php if ( (float) $order['fees'] > 0 ) : ?>
						<div style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #d9e1ea;font-size:14px;color:#4b5563;">
							<span><?php esc_html_e( 'Transaction Fee', 'equine-event-manager' ); ?></span>
							<span style="font-weight:700;color:#111827;"><?php echo esc_html( $this->format_money( (float) $order['fees'] ) ); ?></span>
						</div>
					<?php endif; ?>
					<div style="display:flex;justify-content:space-between;gap:12px;padding:14px 0 0;border-top:1px solid #d9e1ea;font-size:16px;font-weight:800;color:#111827;">
						<span><?php esc_html_e( 'Total Paid', 'equine-event-manager' ); ?></span>
						<span><?php echo esc_html( $this->format_money( (float) $order['total'] ) ); ?></span>
					</div>
				</div>

				<?php if ( '' !== trim( $billing_details ) ) : ?>
					<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
						<h2 style="margin:0 0 12px;font-size:15px;line-height:1.3;color:#111827;"><?php esc_html_e( 'Billing Details', 'equine-event-manager' ); ?></h2>
						<div style="font-size:15px;line-height:1.7;color:#4b5563;"><?php echo wp_kses_post( nl2br( esc_html( $billing_details ) ) ); ?></div>
					</div>
				<?php endif; ?>
				<?php if ( '' !== trim( $special_requests ) ) : ?>
					<div style="margin:0 0 18px;padding:24px 26px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
						<h2 style="margin:0 0 12px;font-size:15px;line-height:1.3;color:#111827;"><?php esc_html_e( 'Special Requests', 'equine-event-manager' ); ?></h2>
						<div style="font-size:15px;line-height:1.7;color:#4b5563;"><?php echo wp_kses_post( nl2br( esc_html( $special_requests ) ) ); ?></div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $footer_chunks ) ) : ?>
					<div style="margin-top:18px;padding:0 12px;">
						<p style="margin:0;text-align:center;color:#5d6b7d;font-size:13px;line-height:1.7;"><?php echo esc_html( implode( ' | ', $footer_chunks ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Replace configured receipt placeholders.
	 *
	 * @param string $text Text template.
	 * @param array  $order Order payload.
	 * @return string
	 */
	private function replace_receipt_tokens( $text, $order ) {
		$company_settings = $this->get_company_settings();
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$tokens           = array(
			'[event_name]'    => $event_label,
			'[event_dates]'   => isset( $order['event_dates'] ) ? $order['event_dates'] : '',
			'[order_number]'  => ! empty( $order['order_number'] ) ? '#' . $order['order_number'] : '',
			'[customer_name]' => isset( $order['customer_name'] ) ? $order['customer_name'] : '',
			'[total]'         => '$' . number_format_i18n( (float) $order['total'], 2 ),
			'[support_phone]' => isset( $company_settings['support_phone'] ) ? $company_settings['support_phone'] : '',
			'[support_email]' => isset( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
		);

		return strtr( (string) $text, $tokens );
	}

	/**
	 * Format a stored phone number for display.
	 *
	 * @param string $phone Raw phone value.
	 * @return string
	 */
	private function format_phone_label( $phone ) {
		$phone       = trim( (string) $phone );
		$digits_only = preg_replace( '/\D+/', '', $phone );

		if ( strlen( $digits_only ) >= 10 ) {
			$local_digits   = substr( $digits_only, -10 );
			$country_digits = substr( $digits_only, 0, -10 );

			if ( '' === $country_digits || preg_match( '/^1+$/', $country_digits ) ) {
				return sprintf(
					'(%1$s) %2$s-%3$s',
					substr( $local_digits, 0, 3 ),
					substr( $local_digits, 3, 3 ),
					substr( $local_digits, 6, 4 )
				);
			}
		}

		return $phone;
	}

	/**
	 * Resolve the customer email for a grouped order.
	 *
	 * @param array $order Grouped order payload.
	 * @return string
	 */
	private function get_order_customer_email( $order ) {
		$email = sanitize_email( isset( $order['email'] ) ? $order['email'] : '' );

		if ( $email ) {
			return $email;
		}

		foreach ( $this->get_order_component_rows( $order, 'stall' ) as $row ) {
			$email = sanitize_email( isset( $row['email'] ) ? $row['email'] : '' );

			if ( $email ) {
				return $email;
			}
		}

		foreach ( $this->get_order_component_rows( $order, 'rv' ) as $row ) {
			$email = sanitize_email( isset( $row['email'] ) ? $row['email'] : '' );

			if ( $email ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Get saved DB rows for order components of a given type.
	 *
	 * @param array  $order Grouped order payload.
	 * @param string $component_type Component type.
	 * @return array
	 */
	private function get_order_component_rows( $order, $component_type ) {
		global $wpdb;

		$row_ids = array();

		foreach ( (array) $order['components'] as $component ) {
			if ( empty( $component['table'] ) || $component_type !== $component['table'] || empty( $component['row_id'] ) ) {
				continue;
			}

			$row_ids[] = absint( $component['row_id'] );
		}

		$row_ids = array_values( array_unique( array_filter( $row_ids ) ) );

		if ( empty( $row_ids ) ) {
			return array();
		}

		$table_name   = 'stall' === $component_type ? $wpdb->prefix . 'en_stall_reservations' : $wpdb->prefix . 'en_rv_reservations';
		$placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
		$query        = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id IN ({$placeholders})", $row_ids );

		return (array) $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get a derived stall subtotal breakdown for an order.
	 *
	 * @param array $order Order payload.
	 * @return array
	 */
	private function get_order_stall_breakdown( $order ) {
		$reservation_id                = ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
		$required_price                = $reservation_id ? (float) get_post_meta( $reservation_id, 'required_shavings_price', true ) : 0.0;
		$additional_price              = $reservation_id ? (float) get_post_meta( $reservation_id, 'additional_shavings_price', true ) : 0.0;
		$required_shavings_qty         = 0;
		$additional_shavings_qty       = 0;
		$required_shavings_subtotal    = 0.0;
		$additional_shavings_subtotal  = 0.0;
		$base_subtotal                 = 0.0;
		$stall_rows                    = $this->get_order_component_rows( $order, 'stall' );

		foreach ( $stall_rows as $row ) {
			$stall_quantity           = absint( $row['stall_qty'] ) + absint( $row['tack_stall_qty'] );
			$row_required_qty         = absint( $row['required_shavings_qty'] );
			$row_additional_qty       = absint( $row['additional_shavings_qty'] );
			$stay_units               = $this->get_billable_stay_units( $row['arrival_date'], $row['departure_date'], $row['stay_type'] );
			$row_base_subtotal        = $stall_quantity * (float) $row['unit_price'] * $stay_units;
			$row_required_subtotal    = $row_required_qty * $required_price;
			$row_additional_subtotal  = $row_additional_qty * $additional_price;

			$required_shavings_qty       += $row_required_qty;
			$additional_shavings_qty     += $row_additional_qty;
			$required_shavings_subtotal  += $row_required_subtotal;
			$additional_shavings_subtotal += $row_additional_subtotal;
			$base_subtotal               += $row_base_subtotal;
		}

		if ( empty( $stall_rows ) ) {
			$required_shavings_qty        = absint( $order['required_shavings_qty'] );
			$additional_shavings_qty      = absint( $order['additional_shavings_qty'] );
			$required_shavings_subtotal   = $required_shavings_qty * $required_price;
			$additional_shavings_subtotal = $additional_shavings_qty * $additional_price;
			$base_subtotal                = max( 0, (float) $order['stall_subtotal'] - $required_shavings_subtotal - $additional_shavings_subtotal );
		}

		return array(
			'base_subtotal'                => $base_subtotal,
			'required_shavings_qty'        => $required_shavings_qty,
			'required_shavings_subtotal'   => $required_shavings_subtotal,
			'additional_shavings_qty'      => $additional_shavings_qty,
			'additional_shavings_subtotal' => $additional_shavings_subtotal,
		);
	}

	/**
	 * Get a derived RV add-on subtotal breakdown for an order.
	 *
	 * @param array $order Order payload.
	 * @return array
	 */
	private function get_order_rv_addon_breakdown( $order ) {
		$breakdown = array();
		$rv_rows   = $this->get_order_component_rows( $order, 'rv' );

		foreach ( $rv_rows as $row ) {
			$reservation_id = $this->extract_reservation_id_from_notes( isset( $row['notes'] ) ? $row['notes'] : '' );
			$reservation_id = $reservation_id ? $reservation_id : ( ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0 );
			$rv_labels      = $this->get_rv_addon_labels_from_row_payload( $row );
			$rv_labels      = ! empty( $rv_labels ) ? $rv_labels : $this->get_order_rv_addon_labels( $order );
			$rv_quantity    = absint( $row['rv_qty'] );

			if ( ! $reservation_id || empty( $rv_labels ) || $rv_quantity < 1 ) {
				continue;
			}

			$stay_type  = ! empty( $row['stay_type'] ) ? sanitize_key( $row['stay_type'] ) : 'nightly';
			$stay_units = $this->get_billable_stay_units( $row['arrival_date'], $row['departure_date'], $stay_type );

			$reservation_data = $this->get_reservation_data( $reservation_id );

			foreach ( $this->get_enabled_rv_addon_options( $reservation_data ) as $addon ) {
				if ( ! in_array( $addon['name'], $rv_labels, true ) ) {
					continue;
				}

				$rate = isset( $addon['price'] ) ? (float) $addon['price'] : 0.0;

				if ( $rate <= 0 ) {
					continue;
				}

				if ( ! isset( $breakdown[ $addon['name'] ] ) ) {
					$breakdown[ $addon['name'] ] = array(
						'label'    => $addon['name'],
						'subtotal' => 0.0,
					);
				}

				$breakdown[ $addon['name'] ]['subtotal'] += $rv_quantity * $rate * $stay_units;
			}
		}

		if ( ! empty( $breakdown ) ) {
			return $breakdown;
		}

		$reservation_id = ! empty( $order['reservation_id'] ) ? absint( $order['reservation_id'] ) : 0;
		$rv_labels      = $this->get_order_rv_addon_labels( $order );

		if ( ! $reservation_id || empty( $rv_labels ) ) {
			return $breakdown;
		}

		$rv_quantity = absint( $order['rv_quantity'] );

		if ( $rv_quantity < 1 ) {
			return $breakdown;
		}

		$stay_type  = ! empty( $order['rv_stay_type'] ) ? sanitize_key( $order['rv_stay_type'] ) : 'nightly';
		$stay_units = $this->get_billable_stay_units( $order['rv_arrival_date'], $order['rv_departure_date'], $stay_type );

		$reservation_data = $this->get_reservation_data( $reservation_id );

		foreach ( $this->get_enabled_rv_addon_options( $reservation_data ) as $addon ) {
			if ( ! in_array( $addon['name'], $rv_labels, true ) ) {
				continue;
			}

			$rate = isset( $addon['price'] ) ? (float) $addon['price'] : 0.0;

			if ( $rate <= 0 ) {
				continue;
			}

			$breakdown[ $addon['name'] ] = array(
				'label'    => $addon['name'],
				'subtotal' => $rv_quantity * $rate * $stay_units,
			);
		}

		return $breakdown;
	}

	/**
	 * Extract reservation setup ID from notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return int
	 */
	private function extract_reservation_id_from_notes( $notes ) {
		if ( preg_match( '/(?:^|\n)Reservation setup ID:\s*(\d+)/i', (string) $notes, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Extract saved general add-on rows from order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return array
	 */
	private function extract_general_addon_breakdown_from_notes( $notes ) {
		$results = array();

		if ( preg_match_all( '/(?:^|\n)Add-On:\s*(.+?)\s*\|\s*Qty:\s*(\d+)(?:\s*\|\s*Per:\s*(.+?))?\s*\|\s*Subtotal:\s*\$?\s*([0-9,]+(?:\.\d{1,2})?)/mi', (string) $notes, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$addon_name = sanitize_text_field( trim( $match[1] ) );
				$quantity   = absint( $match[2] );
				$per_label  = ! empty( $match[3] ) ? sanitize_text_field( trim( $match[3] ) ) : '';
				$subtotal   = (float) str_replace( ',', '', trim( $match[4] ) );

				if ( '' === $addon_name || $quantity <= 0 ) {
					continue;
				}

				if ( ! isset( $results[ $addon_name ] ) ) {
					$results[ $addon_name ] = array(
						'label'     => $addon_name,
						'quantity'  => 0,
						'per_label' => $per_label,
						'subtotal'  => 0.0,
					);
				}

				$results[ $addon_name ]['quantity'] += $quantity;
				if ( '' === $results[ $addon_name ]['per_label'] && '' !== $per_label ) {
					$results[ $addon_name ]['per_label'] = $per_label;
				}
				$results[ $addon_name ]['subtotal'] += $subtotal;
			}
		}

		return array_values( $results );
	}

	/**
	 * Extract group charge rows from stored order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return array<int, array{label:string, quantity:int, rate:float, subtotal:float}>
	 */
	private function extract_group_charge_breakdown_from_notes( $notes ) {
		$results = array();

		if ( preg_match_all( '/(?:^|\n)Group Charge:\s*(.+?)\s*\|\s*Qty:\s*(\d+)\s*\|\s*Rate:\s*\$?\s*([0-9,]+(?:\.\d{1,2})?)\s*\|\s*Subtotal:\s*\$?\s*([0-9,]+(?:\.\d{1,2})?)/mi', (string) $notes, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$label    = sanitize_text_field( trim( $match[1] ) );
				$quantity = absint( $match[2] );
				$rate     = (float) str_replace( ',', '', trim( $match[3] ) );
				$subtotal = (float) str_replace( ',', '', trim( $match[4] ) );

				if ( '' === $label || $quantity <= 0 ) {
					continue;
				}

				$results[] = array(
					'label'    => $label,
					'quantity' => $quantity,
					'rate'     => $rate,
					'subtotal' => $subtotal,
				);
			}
		}

		return $results;
	}

	/**
	 * Extract the saved group rider count from order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return int
	 */
	private function extract_group_rider_count_from_notes( $notes ) {
		if ( preg_match( '/(?:^|\n)Group Riders Count:\s*(\d+)/i', (string) $notes, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Extract the saved group rider names from order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return array
	 */
	private function extract_group_rider_names_from_notes( $notes ) {
		if ( empty( $notes ) || ! preg_match( '/(?:^|\n)Group Riders:\s*(.+)$/mi', (string) $notes, $matches ) ) {
			return array();
		}

		$names = array();

		foreach ( preg_split( '/\s*\|\s*/', trim( $matches[1] ) ) as $name ) {
			$name = sanitize_text_field( trim( (string) $name ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Get RV add-on labels from a DB row payload.
	 *
	 * @param array $row Reservation row payload.
	 * @return array
	 */
	private function get_rv_addon_labels_from_row_payload( $row ) {
		$candidates = array();

		if ( ! empty( $row['rv_type'] ) ) {
			$candidates[] = $row['rv_type'];
		}

		if ( ! empty( $row['notes'] ) && preg_match( '/(?:^|\n)RV Add-Ons:\s*(.+)$/mi', (string) $row['notes'], $matches ) ) {
			$candidates[] = trim( $matches[1] );
		}

		foreach ( $candidates as $candidate ) {
			$labels = $this->parse_rv_addon_labels( $candidate );

			if ( ! empty( $labels ) ) {
				return $labels;
			}
		}

		return array();
	}

	/**
	 * Get RV add-on labels from a grouped order.
	 *
	 * @param array $order Grouped order payload.
	 * @return array
	 */
	private function get_order_rv_addon_labels( $order ) {
		$candidates = array();

		if ( ! empty( $order['rv_type'] ) ) {
			$candidates[] = $order['rv_type'];
		}

		if ( ! empty( $order['notes'] ) && preg_match( '/(?:^|\n)RV Add-Ons:\s*(.+)$/mi', (string) $order['notes'], $matches ) ) {
			$candidates[] = trim( $matches[1] );
		}

		foreach ( $candidates as $candidate ) {
			$labels = $this->parse_rv_addon_labels( $candidate );

			if ( ! empty( $labels ) ) {
				return $labels;
			}
		}

		return array();
	}

	/**
	 * Parse saved RV add-on labels from a raw string.
	 *
	 * @param string $raw Raw stored RV add-on value.
	 * @return array
	 */
	private function parse_rv_addon_labels( $raw ) {
		$raw    = trim( (string) $raw );
		$labels = array();

		if ( '' === $raw ) {
			return $labels;
		}

		foreach ( preg_split( '/\s*,\s*/', $raw ) as $raw_part ) {
			$raw_part = trim( (string) $raw_part );

			if ( '' === $raw_part ) {
				continue;
			}

			$labels[] = sanitize_text_field( $raw_part );
		}

		return array_values( array_unique( array_filter( $labels ) ) );
	}

	/**
	 * Insert or replace a metadata line inside stored notes.
	 *
	 * @param string $notes Notes value.
	 * @param string $label Metadata label.
	 * @param string $value Metadata value.
	 * @return string
	 */
	private function upsert_order_note_line( $notes, $label, $value ) {
		$notes   = trim( (string) $notes );
		$pattern = '/^' . preg_quote( $label, '/' ) . ':\s*.*$/mi';
		$line    = $label . ': ' . $value;

		if ( preg_match( $pattern, $notes ) ) {
			$notes = preg_replace( $pattern, $line, $notes );
		} else {
			$notes = trim( $notes . "\n" . $line );
		}

		return trim( preg_replace( "/\n{3,}/", "\n\n", $notes ) );
	}

	/**
	 * Create a Stripe PaymentIntent for an existing invoice order.
	 *
	 * @param array  $order         Order payload.
	 * @param string $invoice_token Invoice token.
	 * @param string $secret_key    Stripe secret key.
	 * @return array|WP_Error
	 */
	private function create_invoice_stripe_payment_intent( $order, $invoice_token, $secret_key ) {
		$body = array(
			'amount'                    => absint( round( (float) $order['total'] * 100 ) ),
			'currency'                  => 'usd',
			'payment_method_types[]'    => 'card',
			'description'               => sprintf( 'Equine Event Manager invoice order %s', sanitize_text_field( $order['order_number'] ) ),
			'receipt_email'             => $this->get_order_customer_email( $order ),
			'metadata[invoice_token]'   => $invoice_token,
			'metadata[order_key]'       => $order['order_key'],
			'metadata[order_number]'    => $order['order_number'],
		);

		return $this->request_stripe_api( 'POST', 'payment_intents', $secret_key, $body );
	}

	/**
	 * Process an Authorize.net payment for an existing invoice order.
	 *
	 * @param array  $order         Order payload.
	 * @param string $invoice_token Invoice token.
	 * @return array|WP_Error
	 */
	private function process_authorize_net_invoice_payment( $order, $invoice_token ) {
		$config = $this->get_active_authorize_net_configuration();

		if ( empty( $config['api_login'] ) || empty( $config['transaction_key'] ) ) {
			return new WP_Error( 'authorize_not_configured', __( 'Authorize.net is not fully configured in plugin Settings yet.', 'equine-event-manager' ) );
		}

		$card_number = isset( $_POST['authorize_card_number'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['authorize_card_number'] ) ) : '';
		$exp_month   = isset( $_POST['authorize_exp_month'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['authorize_exp_month'] ) ) : '';
		$exp_year    = isset( $_POST['authorize_exp_year'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['authorize_exp_year'] ) ) : '';
		$card_code   = isset( $_POST['authorize_card_code'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['authorize_card_code'] ) ) : '';

		if ( strlen( $card_number ) < 13 || strlen( $card_number ) > 19 || '' === $exp_month || '' === $exp_year || strlen( $card_code ) < 3 ) {
			return new WP_Error( 'authorize_missing_card', __( 'Please enter a complete credit card number, expiration date, and security code.', 'equine-event-manager' ) );
		}

		$billing = $this->get_billing_details_parts_from_notes( $order['notes'] );

		$request_body = array(
			'createTransactionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => trim( (string) $config['api_login'] ),
					'transactionKey' => trim( (string) $config['transaction_key'] ),
				),
				'refId'                  => substr( 'inv' . preg_replace( '/[^a-zA-Z0-9]/', '', $invoice_token ), 0, 20 ),
				'transactionRequest'     => array(
					'transactionType' => 'authCaptureTransaction',
					'amount'          => number_format( (float) $order['total'], 2, '.', '' ),
					'payment'         => array(
						'creditCard' => array(
							'cardNumber'     => $card_number,
							'expirationDate' => sprintf( '%04d-%02d', absint( $exp_year ), absint( $exp_month ) ),
							'cardCode'       => $card_code,
						),
					),
					'order'           => array(
						'invoiceNumber' => substr( 'INV' . sanitize_text_field( $order['order_number'] ), 0, 20 ),
					),
					'customer'        => array(
						'email' => $this->get_order_customer_email( $order ),
					),
					'billTo'          => array(
						'firstName' => $billing['first_name'],
						'lastName'  => $billing['last_name'],
						'address'   => $billing['address_1'],
						'city'      => $billing['city'],
						'state'     => $billing['state'],
						'zip'       => $billing['postal_code'],
						'country'   => $this->normalize_authorize_net_country( $billing['country'] ),
					),
				),
			),
		);

		$response = wp_remote_post(
			$config['endpoint'],
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload       = $this->parse_authorize_net_response_body( wp_remote_retrieve_body( $response ) );
		$response_code = isset( $payload['transactionResponse']['responseCode'] ) ? (string) $payload['transactionResponse']['responseCode'] : '';

		if ( ! is_array( $payload ) || '1' !== $response_code || empty( $payload['transactionResponse']['transId'] ) ) {
			$messages = is_array( $payload ) ? $this->get_authorize_net_response_messages( $payload ) : array();
			return new WP_Error( 'authorize_payment_failed', ! empty( $messages ) ? implode( ' ', $messages ) : __( 'Authorize.net payment failed. Please verify the card details and try again.', 'equine-event-manager' ) );
		}

		return array(
			'payment_gateway' => 'authorize_net',
			'transaction_id'  => sanitize_text_field( $payload['transactionResponse']['transId'] ),
		);
	}

	/**
	 * Parse billing details from stored notes.
	 *
	 * @param string $notes Notes value.
	 * @return array
	 */
	private function get_billing_details_parts_from_notes( $notes ) {
		$billing = array(
			'first_name'  => '',
			'last_name'   => '',
			'address_1'   => '',
			'address_2'   => '',
			'city'        => '',
			'state'       => '',
			'postal_code' => '',
			'country'     => '',
		);

		$billing_text = $this->get_billing_details_from_order_notes( $notes );

		if ( '' === $billing_text ) {
			return $billing;
		}

		$lines      = array_values( array_filter( preg_split( "/\r\n|\r|\n/", $billing_text ) ) );
		$name_line  = isset( $lines[0] ) ? trim( $lines[0] ) : '';
		$name_parts = preg_split( '/\s+/', $name_line );

		if ( ! empty( $name_parts ) ) {
			$billing['first_name'] = array_shift( $name_parts );
			$billing['last_name']  = ! empty( $name_parts ) ? implode( ' ', $name_parts ) : $billing['first_name'];
		}

		if ( isset( $lines[1] ) ) {
			$billing['address_1'] = trim( $lines[1] );
		}

		if ( isset( $lines[2] ) && false === strpos( $lines[2], ',' ) ) {
			$billing['address_2'] = trim( $lines[2] );
			$city_state_zip_index = 3;
		} else {
			$city_state_zip_index = 2;
		}

		if ( isset( $lines[ $city_state_zip_index ] ) && preg_match( '/^(.*?),\s*([A-Za-z]{2,})\s+(.+)$/', trim( $lines[ $city_state_zip_index ] ), $matches ) ) {
			$billing['city']        = trim( $matches[1] );
			$billing['state']       = trim( $matches[2] );
			$billing['postal_code'] = trim( $matches[3] );
		}

		if ( isset( $lines[ $city_state_zip_index + 1 ] ) ) {
			$billing['country'] = trim( $lines[ $city_state_zip_index + 1 ] );
		}

		return $billing;
	}

	/**
	 * Render the hosted invoice payment page.
	 *
	 * @param array  $order         Order payload.
	 * @param string $invoice_token Invoice token.
	 * @param string $error_message Optional error message.
	 * @return void
	 */
	private function render_invoice_payment_page( $order, $invoice_token, $error_message = '' ) {
		$company_settings = $this->get_company_settings();
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$gateway          = $this->get_invoice_order_gateway( $order );
		$stripe_config    = $this->get_active_stripe_configuration();
		$support_chunks   = array_filter(
			array(
				! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '',
				! empty( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
			)
		);
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html( sprintf( __( 'Invoice #%s', 'equine-event-manager' ), $order['order_number'] ) ); ?></title>
			<?php if ( 'stripe' === $gateway && ! empty( $stripe_config['publishable_key'] ) ) : ?>
				<script src="https://js.stripe.com/v3/"></script>
			<?php endif; ?>
		</head>
		<body style="margin:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:960px;margin:0 auto;padding:32px 18px 48px;">
				<div style="display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:start;">
					<div style="padding:30px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;color:#111827;">
						<?php if ( $company_logo_url ) : ?>
							<p style="margin:0 0 18px;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:block;object-fit:contain;" /></p>
						<?php endif; ?>
						<div style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Secure Payment Link', 'equine-event-manager' ); ?></div>
						<h1 style="margin:12px 0 14px;font-size:34px;line-height:1.05;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
						<p style="margin:0 0 18px;font-size:16px;line-height:1.7;color:#111827;"><?php echo esc_html( sprintf( __( 'Review order #%s and complete payment below.', 'equine-event-manager' ), $order['order_number'] ) ); ?></p>
						<div style="display:grid;gap:12px;">
							<div style="padding:16px 18px;border-radius:8px;background:#e4eaf1;border:1px solid #d9e1ea;">
								<div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></div>
								<div style="margin-top:6px;font-size:18px;font-weight:700;color:#111827;"><?php echo esc_html( $order['customer_name'] ); ?></div>
								<div style="margin-top:4px;color:#4b5563;"><?php echo esc_html( $this->get_order_customer_email( $order ) ); ?></div>
							</div>
							<div style="padding:16px 18px;border-radius:8px;background:#eef2f6;border:1px solid #d9e1ea;color:#111827;">
								<div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Amount Due', 'equine-event-manager' ); ?></div>
								<div style="margin-top:6px;font-size:32px;font-weight:800;color:#111827;"><?php echo esc_html( $this->format_money( (float) $order['total'] ) ); ?></div>
							</div>
						</div>
					</div>

					<div style="padding:28px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;color:#111827;">
						<div style="display:grid;gap:14px;">
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Order Number', 'equine-event-manager' ); ?></span><strong style="color:#111827;">#<?php echo esc_html( $order['order_number'] ); ?></strong></div>
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></span><strong style="text-align:right;color:#111827;"><?php echo esc_html( $order['event_dates'] ); ?></strong></div>
							<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Reservation Type', 'equine-event-manager' ); ?></span><strong style="text-align:right;color:#111827;"><?php echo esc_html( $order['type'] ); ?></strong></div>
							<div style="display:flex;justify-content:space-between;gap:16px;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></span><strong style="text-align:right;color:#111827;"><?php echo esc_html( $order['status_label'] ); ?></strong></div>
						</div>

						<?php if ( $error_message ) : ?>
							<div style="margin-top:18px;padding:14px 16px;border-radius:8px;background:#eef2f6;border:1px solid #d9e1ea;color:#111827;"><?php echo esc_html( $error_message ); ?></div>
						<?php endif; ?>

						<?php if ( $this->invoice_order_is_payable( $order ) ) : ?>
							<form method="post" id="eem-invoice-payment-form" style="margin-top:22px;">
								<?php wp_nonce_field( 'equine_event_manager_invoice_payment_' . $invoice_token, 'en_invoice_payment_nonce' ); ?>
								<input type="hidden" name="equine_event_manager_invoice" value="<?php echo esc_attr( $invoice_token ); ?>" />
								<input type="hidden" name="stripe_payment_intent_id" value="" />
								<?php if ( 'stripe' === $gateway ) : ?>
									<div id="eem-invoice-card-number" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:16px;background:#ffffff;margin-bottom:12px;"></div>
									<div style="display:grid;gap:12px;grid-template-columns:repeat(2,minmax(0,1fr));">
										<div id="eem-invoice-card-expiry" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:8px;background:#ffffff;"></div>
										<div id="eem-invoice-card-cvc" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:8px;background:#ffffff;"></div>
									</div>
									<div id="eem-invoice-card-error" style="display:none;margin-top:12px;color:#8a3528;"></div>
								<?php else : ?>
									<div style="display:grid;gap:12px;">
										<input type="text" name="authorize_card_number" placeholder="<?php esc_attr_e( 'Card Number', 'equine-event-manager' ); ?>" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:16px;background:#ffffff;color:#111827;" />
										<div style="display:grid;gap:12px;grid-template-columns:repeat(3,minmax(0,1fr));">
											<input type="text" name="authorize_exp_month" placeholder="<?php esc_attr_e( 'MM', 'equine-event-manager' ); ?>" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:16px;background:#ffffff;color:#111827;" />
											<input type="text" name="authorize_exp_year" placeholder="<?php esc_attr_e( 'YYYY', 'equine-event-manager' ); ?>" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:16px;background:#ffffff;color:#111827;" />
											<input type="text" name="authorize_card_code" placeholder="<?php esc_attr_e( 'CVV', 'equine-event-manager' ); ?>" style="padding:14px 16px;border:1px solid #d9e1ea;border-radius:16px;background:#ffffff;color:#111827;" />
										</div>
									</div>
								<?php endif; ?>
								<button type="submit" id="eem-invoice-submit" style="width:100%;margin-top:18px;padding:16px 20px;border:0;border-radius:12px;background:#111827;color:#fff;font-size:16px;font-weight:700;cursor:pointer;"><?php echo esc_html( sprintf( __( 'Pay %s', 'equine-event-manager' ), $this->format_money( (float) $order['total'] ) ) ); ?></button>
							</form>
						<?php else : ?>
							<div style="margin-top:20px;padding:16px;border-radius:8px;background:#eef2f6;border:1px solid #d9e1ea;color:#111827;"><?php esc_html_e( 'This invoice has already been paid.', 'equine-event-manager' ); ?></div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! empty( $support_chunks ) ) : ?>
					<p style="margin:18px 0 0;text-align:center;color:#4b5563;font-size:13px;line-height:1.7;"><?php echo esc_html( implode( ' | ', $support_chunks ) ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( 'stripe' === $gateway && ! empty( $stripe_config['publishable_key'] ) && $this->invoice_order_is_payable( $order ) ) : ?>
				<script>
					(function() {
						var stripe = window.Stripe('<?php echo esc_js( $stripe_config['publishable_key'] ); ?>');
						var elements = stripe.elements();
						var number = elements.create('cardNumber');
						var expiry = elements.create('cardExpiry');
						var cvc = elements.create('cardCvc');
						var form = document.getElementById('eem-invoice-payment-form');
						var errorTarget = document.getElementById('eem-invoice-card-error');
						var submitButton = document.getElementById('eem-invoice-submit');
						number.mount('#eem-invoice-card-number');
						expiry.mount('#eem-invoice-card-expiry');
						cvc.mount('#eem-invoice-card-cvc');
						form.addEventListener('submit', function(event) {
							event.preventDefault();
							submitButton.disabled = true;
							errorTarget.style.display = 'none';
							var data = new window.FormData();
							data.append('action', 'equine_event_manager_create_invoice_payment_intent');
							data.append('invoice_token', '<?php echo esc_js( $invoice_token ); ?>');
							data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'equine_event_manager_invoice_payment_' . $invoice_token ) ); ?>');
							fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: data, credentials: 'same-origin' })
								.then(function(response) { return response.json(); })
								.then(function(payload) {
									if (!payload.success) {
										throw new Error(payload.data && payload.data.message ? payload.data.message : '<?php echo esc_js( __( 'Unable to start the payment.', 'equine-event-manager' ) ); ?>');
									}
									return stripe.confirmCardPayment(payload.data.client_secret, { payment_method: { card: number } });
								})
								.then(function(result) {
									if (result.error) {
										throw new Error(result.error.message);
									}
									form.querySelector('[name="stripe_payment_intent_id"]').value = result.paymentIntent.id;
									form.submit();
								})
								.catch(function(error) {
									errorTarget.textContent = error.message;
									errorTarget.style.display = 'block';
									submitButton.disabled = false;
								});
						});
					})();
				</script>
			<?php endif; ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Render the hosted invoice success page.
	 *
	 * @param array $order Order payload.
	 * @return void
	 */
	private function render_invoice_payment_success_page( $order ) {
		$company_settings = $this->get_company_settings();
		$company_logo_url = $this->get_company_logo_url( 'medium' );
		$event_label      = ! empty( $order['reservation_title'] ) ? $order['reservation_title'] : $order['event_name'];
		$support_chunks   = array_filter(
			array(
				! empty( $company_settings['support_phone'] ) ? $this->format_phone_label( $company_settings['support_phone'] ) : '',
				! empty( $company_settings['support_email'] ) ? $company_settings['support_email'] : '',
			)
		);
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html( sprintf( __( 'Receipt #%s', 'equine-event-manager' ), $order['order_number'] ) ); ?></title>
		</head>
		<body style="margin:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
			<div style="max-width:760px;margin:0 auto;padding:32px 18px 48px;">
				<div style="padding:30px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;color:#111827;">
					<?php if ( $company_logo_url ) : ?>
						<p style="margin:0 0 18px;"><img src="<?php echo esc_url( $company_logo_url ); ?>" alt="<?php esc_attr_e( 'Company logo', 'equine-event-manager' ); ?>" style="max-width:180px;max-height:54px;display:block;object-fit:contain;" /></p>
					<?php endif; ?>
					<div style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Payment Received', 'equine-event-manager' ); ?></div>
					<h1 style="margin:12px 0 10px;font-size:32px;line-height:1.08;color:#111827;"><?php echo esc_html( $event_label ); ?></h1>
					<p style="margin:0;color:#4f5b6a;font-size:16px;line-height:1.7;"><?php esc_html_e( 'Your invoice has been paid successfully. A receipt has been emailed to the address on file.', 'equine-event-manager' ); ?></p>
				</div>
				<div style="margin-top:18px;padding:26px 28px;background:#eef2f6;border:1px solid #d9e1ea;border-radius:8px;">
					<div style="display:grid;gap:14px;">
						<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Order Number', 'equine-event-manager' ); ?></span><strong style="color:#111827;">#<?php echo esc_html( $order['order_number'] ); ?></strong></div>
						<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></span><strong style="color:#111827;"><?php echo esc_html( $order['customer_name'] ); ?></strong></div>
						<div style="display:flex;justify-content:space-between;gap:16px;padding-bottom:12px;border-bottom:1px solid #d9e1ea;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></span><strong style="color:#111827;"><?php echo esc_html( $order['status_label'] ); ?></strong></div>
						<div style="display:flex;justify-content:space-between;gap:16px;"><span style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#5b6472;"><?php esc_html_e( 'Total Paid', 'equine-event-manager' ); ?></span><strong style="color:#111827;"><?php echo esc_html( $this->format_money( (float) $order['total'] ) ); ?></strong></div>
					</div>
				</div>
				<?php if ( ! empty( $support_chunks ) ) : ?>
					<p style="margin:18px 0 0;text-align:center;color:#5b6472;font-size:13px;line-height:1.7;"><?php echo esc_html( implode( ' | ', $support_chunks ) ); ?></p>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
	}

	/**
	 * Get receipt settings with defaults.
	 *
	 * @return array
	 */
	private function get_receipt_settings() {
		$company_settings = $this->get_company_settings();
		$saved            = get_option( self::RECEIPT_SETTINGS_OPTION, array() );
		$fallback_email   = ! empty( $company_settings['support_email'] ) && is_email( $company_settings['support_email'] ) ? $company_settings['support_email'] : get_option( 'admin_email', '' );
		$settings         = wp_parse_args(
			$saved,
			array(
				'customer_receipt_enabled' => 1,
				'admin_receipt_email'      => '',
				'from_name'                => '',
				'from_email'               => '',
				'reply_to_email'           => '',
				'customer_subject'         => self::DEFAULT_CUSTOMER_RECEIPT_SUBJECT,
				'admin_subject'            => self::DEFAULT_ADMIN_RECEIPT_SUBJECT,
				'customer_body'            => self::DEFAULT_CUSTOMER_RECEIPT_BODY,
				'admin_body'               => self::DEFAULT_ADMIN_RECEIPT_BODY,
			)
		);

		$settings['customer_receipt_enabled'] = isset( $saved['customer_receipt_enabled'] ) ? (int) ! empty( $saved['customer_receipt_enabled'] ) : 1;
		$settings['admin_receipt_email']      = ! empty( $settings['admin_receipt_email'] ) && is_email( $settings['admin_receipt_email'] ) ? $settings['admin_receipt_email'] : $fallback_email;
		$settings['from_name']                = ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name' );
		$settings['from_email']               = ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) ? $settings['from_email'] : $fallback_email;
		$settings['reply_to_email']           = ! empty( $settings['reply_to_email'] ) && is_email( $settings['reply_to_email'] ) ? $settings['reply_to_email'] : $fallback_email;
		$settings['customer_subject']         = ! empty( $settings['customer_subject'] ) ? $settings['customer_subject'] : self::DEFAULT_CUSTOMER_RECEIPT_SUBJECT;
		$settings['admin_subject']            = ! empty( $settings['admin_subject'] ) ? $settings['admin_subject'] : self::DEFAULT_ADMIN_RECEIPT_SUBJECT;
		$settings['customer_body']            = ! empty( $settings['customer_body'] ) ? $settings['customer_body'] : self::DEFAULT_CUSTOMER_RECEIPT_BODY;
		$settings['admin_body']               = ! empty( $settings['admin_body'] ) ? $settings['admin_body'] : self::DEFAULT_ADMIN_RECEIPT_BODY;

		return $settings;
	}

	/**
	 * Get company settings with defaults.
	 *
	 * @return array
	 */
	private function get_company_settings() {
		return wp_parse_args(
			get_option( self::COMPANY_SETTINGS_OPTION, array() ),
			array(
				'logo_id'       => 0,
				'support_phone' => '',
				'support_email' => get_option( 'admin_email', '' ),
			)
		);
	}

	/**
	 * Get the effective company logo URL.
	 *
	 * @param string $size Image size.
	 * @return string
	 */
	private function get_company_logo_url( $size = 'full' ) {
		$company_settings = $this->get_company_settings();
		$logo_url         = ! empty( $company_settings['logo_id'] ) ? wp_get_attachment_image_url( absint( $company_settings['logo_id'] ), $size ) : '';

		if ( ! $logo_url ) {
			$logo_url = EQUINE_EVENT_MANAGER_URL . 'admin/images/equine-event-manager-logo.png';
		}

		return $logo_url;
	}

	/**
	 * Format a stay type key for display.
	 *
	 * @param string $stay_type Raw stay type value.
	 * @return string
	 */
	private function format_stay_type_label( $stay_type ) {
		$stay_type = sanitize_key( $stay_type );

		if ( 'weekend' === $stay_type ) {
			return __( 'Weekend', 'equine-event-manager' );
		}

		if ( 'nightly' === $stay_type ) {
			return __( 'Nightly', 'equine-event-manager' );
		}

		return $stay_type ? ucfirst( $stay_type ) : '';
	}

	/**
	 * Format RV add-on labels for display.
	 *
	 * @param string $rv_type Saved RV add-ons string.
	 * @return string
	 */
	private function format_rv_type_label( $rv_type ) {
		$parts = $this->parse_rv_addon_labels( $rv_type );

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( ', ', $parts );
	}

	/**
	 * Format a reservation date for display.
	 *
	 * @param string $value Raw date value.
	 * @return string
	 */
	private function format_reservation_date_label( $value ) {
		if ( ! $this->is_valid_date( $value ) ) {
			return '';
		}

		$timestamp = strtotime( $value . ' 00:00:00' );

		return $timestamp ? wp_date( 'l, F j, Y', $timestamp ) : $value;
	}

	/**
	 * Extract customer special requests from stored order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return string
	 */
	private function get_special_requests_from_order_notes( $notes ) {
		$lines = preg_split( "/\r\n|\n|\r/", trim( (string) $notes ) );

		if ( empty( $lines ) ) {
			return '';
		}

		$filtered_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				if ( ! empty( $filtered_lines ) && '' !== end( $filtered_lines ) ) {
					$filtered_lines[] = '';
				}
				continue;
			}

			if ( preg_match( '/^(Billing Name|Billing Address|Reservation setup ID|Submission token|RV Add-Ons|RV Lot|Assigned Stall Units|Assigned RV Lots|Assigned RV Units|Add-On|Group Charge|Group Reservation|Group Riders Count|Group Riders|Venue Agreement (Accepted|Provided)|Invoice Type|Invoice Token|Invoice Status|Invoice Sent At|Invoice Paid At):/i', $line ) ) {
				continue;
			}

			$filtered_lines[] = $line;
		}

		return $this->strip_billing_lines_from_special_requests(
			trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $filtered_lines ) ) ),
			$this->get_billing_details_from_order_notes( $notes )
		);
	}

	/**
	 * Remove leaked billing lines from the special requests output.
	 *
	 * @param string $special_requests Parsed special requests text.
	 * @param string $billing_details Parsed billing details text.
	 * @return string
	 */
	private function strip_billing_lines_from_special_requests( $special_requests, $billing_details ) {
		$special_lines = preg_split( "/\r\n|\n|\r/", trim( (string) $special_requests ) );
		$billing_lines = preg_split( "/\r\n|\n|\r/", trim( (string) $billing_details ) );
		$billing_map   = array();
		$filtered      = array();

		foreach ( $billing_lines as $billing_line ) {
			$billing_line = trim( (string) $billing_line );

			if ( '' !== $billing_line ) {
				$billing_map[ strtolower( $billing_line ) ] = true;
			}
		}

		foreach ( $special_lines as $special_line ) {
			$special_line = trim( (string) $special_line );

			if ( '' === $special_line ) {
				if ( ! empty( $filtered ) && '' !== end( $filtered ) ) {
					$filtered[] = '';
				}
				continue;
			}

			if ( isset( $billing_map[ strtolower( $special_line ) ] ) ) {
				continue;
			}

			$filtered[] = $special_line;
		}

		return trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $filtered ) ) );
	}

	/**
	 * Extract billing details from stored order notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return string
	 */
	private function get_billing_details_from_order_notes( $notes ) {
		$lines           = preg_split( "/\r\n|\n|\r/", (string) $notes );
		$billing_lines   = array();
		$capture_address = false;

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				if ( $capture_address ) {
					break;
				}
				continue;
			}

			if ( preg_match( '/^Billing Name:\s*(.+)$/i', $line, $matches ) ) {
				$billing_lines[] = trim( $matches[1] );
				continue;
			}

			if ( preg_match( '/^Billing Address:\s*(.+)$/i', $line, $matches ) ) {
				$billing_lines[] = trim( $matches[1] );
				$capture_address = true;
				continue;
			}

			if ( $capture_address ) {
				if ( preg_match( '/^(Venue Agreement (Accepted|Provided)|Add-On|Group Charge|Group Reservation|Group Riders Count|Group Riders|Invoice Type|Invoice Token|Invoice Status|Invoice Sent At|Invoice Paid At):/i', $line ) ) {
					break;
				}

				$billing_lines[] = $line;
			}
		}

		return trim( implode( "\n", array_filter( $billing_lines ) ) );
	}

	/**
	 * Extract venue agreement status from notes.
	 *
	 * @param string $notes Raw notes value.
	 * @return string
	 */
	private function extract_venue_agreement_status_from_notes( $notes ) {
		if ( preg_match( '/(?:^|\n)Venue Agreement (Accepted|Provided):\s*(Yes)/i', (string) $notes, $matches ) ) {
			return sanitize_text_field( $matches[2] );
		}

		return '';
	}

	/**
	 * Check whether a submission token has already been processed.
	 *
	 * @param string $submission_token Submission token.
	 * @return bool
	 */
	private function has_processed_submission_token( $submission_token ) {
		$submission_token = sanitize_text_field( $submission_token );

		if ( '' === $submission_token ) {
			return false;
		}

		if ( isset( self::$processed_submission_tokens[ $submission_token ] ) ) {
			return true;
		}

		return (bool) get_transient( self::SUBMISSION_TOKEN_TRANSIENT_PREFIX . md5( $submission_token ) );
	}

	/**
	 * Mark a submission token as processed to prevent duplicate orders.
	 *
	 * @param string $submission_token Submission token.
	 * @return void
	 */
	private function mark_submission_token_processed( $submission_token ) {
		$submission_token = sanitize_text_field( $submission_token );

		if ( '' === $submission_token ) {
			return;
		}

		self::$processed_submission_tokens[ $submission_token ] = true;
		set_transient( self::SUBMISSION_TOKEN_TRANSIENT_PREFIX . md5( $submission_token ), 1, DAY_IN_SECONDS );
	}

	/**
	 * Get the configured payment gateway slug.
	 *
	 * @return string
	 */
	private function get_configured_payment_gateway() {
		$defaults = array(
			'selected_gateway' => 'stripe',
		);
		$settings = wp_parse_args( get_option( 'equine_event_manager_payment_settings', array() ), $defaults );

		return in_array( $settings['selected_gateway'], array( 'stripe', 'authorize_net' ), true ) ? $settings['selected_gateway'] : 'stripe';
	}

	/**
	 * Handle Stripe PaymentIntent creation for the reservation form.
	 *
	 * @return void
	 */
	public function ajax_create_stripe_payment_intent() {
		$reservation_id = isset( $_POST['en_reservation_id'] ) ? absint( $_POST['en_reservation_id'] ) : 0;
		$reservation    = $reservation_id ? get_post( $reservation_id ) : null;

		if ( ! $reservation || 'en_reservation' !== $reservation->post_type || 'publish' !== $reservation->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Reservation form not found.', 'equine-event-manager' ) ), 404 );
		}

		if ( ! isset( $_POST['en_reservation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['en_reservation_nonce'] ) ), 'en_submit_reservation_' . $reservation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'We could not verify this reservation request. Please refresh the page and try again.', 'equine-event-manager' ) ), 403 );
		}

		$data            = $this->get_reservation_meta( $reservation_id );
		$status          = $this->get_reservation_status( $data, $reservation_id );
		$submission      = $this->sanitize_submission( $data );
		$validation      = $this->validate_submission( $submission, $status, $data );
		$stripe_config   = $this->get_active_stripe_configuration();
		$payment_gateway = $this->get_configured_payment_gateway();

		if ( ! empty( $validation ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $validation ) ), 400 );
		}

		if ( 'stripe' !== $payment_gateway ) {
			wp_send_json_error( array( 'message' => __( 'Stripe is not the active payment gateway in plugin Settings.', 'equine-event-manager' ) ), 400 );
		}

		if ( empty( $stripe_config['secret_key'] ) || empty( $stripe_config['publishable_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Stripe keys are not configured yet in plugin Settings.', 'equine-event-manager' ) ), 400 );
		}

		$totals = $this->calculate_submission_totals( $data, $submission, $status );

		if ( $totals['total'] <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please select at least one paid reservation item before paying.', 'equine-event-manager' ) ), 400 );
		}

		$intent = $this->create_stripe_payment_intent( $reservation_id, $submission, $totals, $stripe_config['secret_key'] );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => $intent->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'client_secret' => isset( $intent['client_secret'] ) ? $intent['client_secret'] : '',
				'intent_id'     => isset( $intent['id'] ) ? $intent['id'] : '',
			)
		);
	}

	/**
	 * Render the hosted invoice payment page when a secure token is present.
	 *
	 * @return void
	 */
	public function maybe_render_invoice_payment_page() {
		$invoice_token = isset( $_REQUEST['equine_event_manager_invoice'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['equine_event_manager_invoice'] ) ) : '';

		if ( '' === $invoice_token ) {
			return;
		}

		$order = $this->get_invoice_order( $invoice_token );

		if ( ! $order ) {
			wp_die( esc_html__( 'That invoice link is no longer available.', 'equine-event-manager' ), esc_html__( 'Invoice Unavailable', 'equine-event-manager' ), array( 'response' => 404 ) );
		}

		$error_message = '';

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			$result = $this->handle_invoice_payment_submission( $order, $invoice_token );

			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
			} else {
				$this->render_invoice_payment_success_page( $result );
				exit;
			}
		}

		$this->render_invoice_payment_page( $order, $invoice_token, $error_message );
		exit;
	}

	/**
	 * Handle Stripe PaymentIntent creation for hosted invoice checkout.
	 *
	 * @return void
	 */
	public function ajax_create_invoice_payment_intent() {
		$invoice_token = isset( $_POST['invoice_token'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_token'] ) ) : '';
		$order         = $this->get_invoice_order( $invoice_token );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Invoice not found.', 'equine-event-manager' ) ), 404 );
		}

		check_ajax_referer( 'equine_event_manager_invoice_payment_' . $invoice_token, 'nonce' );

		if ( ! $this->invoice_order_is_payable( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This invoice has already been paid or is no longer payable.', 'equine-event-manager' ) ), 400 );
		}

		$stripe_config = $this->get_active_stripe_configuration();

		if ( empty( $stripe_config['secret_key'] ) || empty( $stripe_config['publishable_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Stripe is not fully configured in plugin Settings.', 'equine-event-manager' ) ), 400 );
		}

		$intent = $this->create_invoice_stripe_payment_intent( $order, $invoice_token, $stripe_config['secret_key'] );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => $intent->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'client_secret' => isset( $intent['client_secret'] ) ? $intent['client_secret'] : '',
				'intent_id'     => isset( $intent['id'] ) ? $intent['id'] : '',
			)
		);
	}

	/**
	 * Resolve an order by invoice token.
	 *
	 * @param string $invoice_token Invoice token.
	 * @return array|null
	 */
	private function get_invoice_order( $invoice_token ) {
		$orders_repository = new EEM_Orders_Repository();

		return $orders_repository->get_order_by_invoice_token( $invoice_token );
	}

	/**
	 * Determine whether an invoice can still be paid.
	 *
	 * @param array $order Order payload.
	 * @return bool
	 */
	private function invoice_order_is_payable( $order ) {
		return in_array( isset( $order['status_slug'] ) ? $order['status_slug'] : '', array( 'unpaid', 'invoice-sent' ), true ) && (float) $order['total'] > 0;
	}

	/**
	 * Process a hosted invoice payment submission.
	 *
	 * @param array  $order         Order payload.
	 * @param string $invoice_token Invoice token.
	 * @return array|WP_Error
	 */
	private function handle_invoice_payment_submission( $order, $invoice_token ) {
		if ( ! isset( $_POST['en_invoice_payment_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['en_invoice_payment_nonce'] ) ), 'equine_event_manager_invoice_payment_' . $invoice_token ) ) {
			return new WP_Error( 'invalid_invoice_nonce', __( 'We could not verify this invoice payment request. Please refresh the page and try again.', 'equine-event-manager' ) );
		}

		if ( ! $this->invoice_order_is_payable( $order ) ) {
			return new WP_Error( 'invoice_unavailable', __( 'This invoice has already been paid or is no longer payable.', 'equine-event-manager' ) );
		}

		$gateway = $this->get_invoice_order_gateway( $order );
		$result  = array();

		if ( 'stripe' === $gateway ) {
			$intent_id     = isset( $_POST['stripe_payment_intent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_payment_intent_id'] ) ) : '';
			$stripe_config = $this->get_active_stripe_configuration();

			if ( empty( $stripe_config['secret_key'] ) ) {
				return new WP_Error( 'stripe_not_configured', __( 'Stripe is not fully configured in plugin Settings.', 'equine-event-manager' ) );
			}

			$intent = $this->get_stripe_payment_intent( $intent_id, $stripe_config['secret_key'] );

			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			if ( empty( $intent['status'] ) || 'succeeded' !== $intent['status'] ) {
				return new WP_Error( 'stripe_not_paid', __( 'Your card payment has not completed yet. Please try again.', 'equine-event-manager' ) );
			}

			if ( absint( $intent['amount'] ) !== absint( round( (float) $order['total'] * 100 ) ) ) {
				return new WP_Error( 'stripe_amount_mismatch', __( 'The Stripe payment amount did not match this invoice total. Please try again.', 'equine-event-manager' ) );
			}

			if ( ! empty( $intent['metadata']['invoice_token'] ) && sanitize_text_field( $intent['metadata']['invoice_token'] ) !== $invoice_token ) {
				return new WP_Error( 'stripe_invoice_mismatch', __( 'This Stripe payment does not belong to the current invoice link.', 'equine-event-manager' ) );
			}

			$result = array(
				'payment_gateway' => 'stripe',
				'transaction_id'  => $intent_id,
			);
		} elseif ( 'authorize_net' === $gateway ) {
			$result = $this->process_authorize_net_invoice_payment( $order, $invoice_token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			return new WP_Error( 'invoice_gateway_invalid', __( 'This invoice could not determine a supported payment gateway.', 'equine-event-manager' ) );
		}

		$this->mark_invoice_order_paid( $order, $invoice_token, $result['payment_gateway'], $result['transaction_id'] );

		$orders_repository = new EEM_Orders_Repository();
		$updated_order     = $orders_repository->get_order( $order['order_key'] );

		if ( $updated_order ) {
			$this->send_receipt_emails_for_order( $updated_order );
			return $updated_order;
		}

		return $order;
	}

	/**
	 * Get the payment gateway to use for an invoice order.
	 *
	 * @param array $order Order payload.
	 * @return string
	 */
	private function get_invoice_order_gateway( $order ) {
		foreach ( $order['components'] as $component ) {
			if ( ! empty( $component['payment_gateway'] ) ) {
				return sanitize_key( $component['payment_gateway'] );
			}
		}

		return $this->get_configured_payment_gateway();
	}

	/**
	 * Persist the paid state for every component in an invoice order.
	 *
	 * @param array  $order            Order payload.
	 * @param string $invoice_token    Invoice token.
	 * @param string $payment_gateway  Gateway slug.
	 * @param string $transaction_id   Transaction/reference ID.
	 * @return void
	 */
	private function mark_invoice_order_paid( $order, $invoice_token, $payment_gateway, $transaction_id ) {
		$orders_repository = new EEM_Orders_Repository();
		$paid_at           = current_time( 'mysql' );

		foreach ( $order['components'] as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';
			$notes = $this->upsert_order_note_line( $notes, 'Invoice Token', $invoice_token );
			$notes = $this->upsert_order_note_line( $notes, 'Invoice Status', 'Paid' );
			$notes = $this->upsert_order_note_line( $notes, 'Invoice Paid At', $paid_at );

			$orders_repository->update_component_fields(
				$component['table'],
				$component['row_id'],
				array(
					'payment_status'  => 'paid',
					'payment_gateway' => sanitize_key( $payment_gateway ),
					'transaction_id'  => sanitize_text_field( $transaction_id ),
					'notes'           => $notes,
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Get payment settings with defaults.
	 *
	 * @return array
	 */
	private function get_payment_settings() {
		$settings = wp_parse_args(
			get_option( 'equine_event_manager_payment_settings', array() ),
			array(
				'selected_gateway' => 'stripe',
				'stripe'           => array(),
				'authorize_net'    => array(),
			)
		);

		$settings['stripe'] = wp_parse_args(
			$settings['stripe'],
			array(
				'mode'                   => 'test',
				'test_publishable_key'   => '',
				'test_secret_key'        => '',
				'live_publishable_key'   => '',
				'live_secret_key'        => '',
				'webhook_signing_secret' => '',
			)
		);

		return $settings;
	}

	/**
	 * Get the active Stripe publishable and secret keys.
	 *
	 * @param array|null $payment_settings Optional settings array.
	 * @return array
	 */
	private function get_active_stripe_configuration( $payment_settings = null ) {
		$payment_settings = is_array( $payment_settings ) ? $payment_settings : $this->get_payment_settings();
		$stripe           = $payment_settings['stripe'];
		$mode             = 'live' === $stripe['mode'] ? 'live' : 'test';

		return array(
			'mode'            => $mode,
			'publishable_key' => 'live' === $mode ? $stripe['live_publishable_key'] : $stripe['test_publishable_key'],
			'secret_key'      => 'live' === $mode ? $stripe['live_secret_key'] : $stripe['test_secret_key'],
		);
	}

	/**
	 * Get the active Authorize.net credentials.
	 *
	 * @param array|null $payment_settings Optional settings array.
	 * @return array
	 */
	private function get_active_authorize_net_configuration( $payment_settings = null ) {
		$payment_settings = is_array( $payment_settings ) ? $payment_settings : $this->get_payment_settings();
		$authorize_net    = wp_parse_args(
			$payment_settings['authorize_net'],
			array(
				'mode'                 => 'test',
				'test_api_login'       => '',
				'test_transaction_key' => '',
				'live_api_login'       => '',
				'live_transaction_key' => '',
			)
		);
		$mode = 'live' === $authorize_net['mode'] ? 'live' : 'test';

		return array(
			'mode'            => $mode,
			'api_login'       => 'live' === $mode ? $authorize_net['live_api_login'] : $authorize_net['test_api_login'],
			'transaction_key' => 'live' === $mode ? $authorize_net['live_transaction_key'] : $authorize_net['test_transaction_key'],
			'endpoint'        => 'live' === $mode ? 'https://api.authorize.net/xml/v1/request.api' : 'https://apitest.authorize.net/xml/v1/request.api',
		);
	}

	/**
	 * Process a card payment through Authorize.net.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $submission Submission values.
	 * @param array $totals Calculated totals.
	 * @return array|WP_Error
	 */
	private function process_authorize_net_payment( $reservation_id, $submission, $totals ) {
		$config = $this->get_active_authorize_net_configuration();

		if ( empty( $config['api_login'] ) || empty( $config['transaction_key'] ) ) {
			return new WP_Error( 'authorize_not_configured', __( 'Authorize.net is not fully configured in Settings yet. Please add your API Login ID and Transaction Key first.', 'equine-event-manager' ) );
		}

		$card_number = isset( $submission['authorize_card_number'] ) ? preg_replace( '/[^0-9]/', '', (string) $submission['authorize_card_number'] ) : '';
		$exp_month   = isset( $submission['authorize_exp_month'] ) ? preg_replace( '/[^0-9]/', '', (string) $submission['authorize_exp_month'] ) : '';
		$exp_year    = isset( $submission['authorize_exp_year'] ) ? preg_replace( '/[^0-9]/', '', (string) $submission['authorize_exp_year'] ) : '';
		$card_code   = isset( $submission['authorize_card_code'] ) ? preg_replace( '/[^0-9]/', '', (string) $submission['authorize_card_code'] ) : '';

		if ( strlen( $card_number ) < 13 || strlen( $card_number ) > 19 || '' === $exp_month || '' === $exp_year || strlen( $card_code ) < 3 ) {
			return new WP_Error( 'authorize_missing_card', __( 'Please enter a complete credit card number, expiration date, and security code for Authorize.net.', 'equine-event-manager' ) );
		}

		$exp_month = sprintf( '%02d', absint( $exp_month ) );
		$exp_year  = sprintf( '%04d', absint( $exp_year ) );

		if ( (int) $exp_month < 1 || (int) $exp_month > 12 || (int) $exp_year < (int) gmdate( 'Y' ) ) {
			return new WP_Error( 'authorize_invalid_expiry', __( 'Please enter a valid Authorize.net card expiration date.', 'equine-event-manager' ) );
		}

		$request_body = array(
			'createTransactionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => trim( (string) $config['api_login'] ),
					'transactionKey' => trim( (string) $config['transaction_key'] ),
				),
				'refId'                  => $this->get_authorize_net_reference_id( $reservation_id, $submission ),
				'transactionRequest'     => array(
					'transactionType' => 'authCaptureTransaction',
					'amount'          => number_format( (float) $totals['total'], 2, '.', '' ),
					'payment'         => array(
						'creditCard' => array(
							'cardNumber'     => $card_number,
							'expirationDate' => $exp_year . '-' . $exp_month,
							'cardCode'       => $card_code,
						),
					),
					'order'           => array(
						'invoiceNumber' => $this->get_authorize_net_invoice_number( $reservation_id ),
					),
					'customer'        => array(
						'email' => $submission['email'],
					),
					'billTo'          => array(
						'firstName' => ! empty( $submission['billing_first_name'] ) ? $submission['billing_first_name'] : $submission['first_name'],
						'lastName'  => ! empty( $submission['billing_last_name'] ) ? $submission['billing_last_name'] : $submission['last_name'],
						'address'   => $submission['billing_address_1'],
						'city'      => $submission['billing_city'],
						'state'     => $submission['billing_state'],
						'zip'       => $submission['billing_postal_code'],
						'country'   => $this->normalize_authorize_net_country( $submission['billing_country'] ),
					),
				),
			),
		);

		$response = wp_remote_post(
			$config['endpoint'],
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Equine Event Manager] Authorize.net transport error: ' . $response->get_error_message() );
			return $response;
		}

		$http_status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$payload       = $this->parse_authorize_net_response_body( $raw_body );
		$response_code = isset( $payload['transactionResponse']['responseCode'] ) ? (string) $payload['transactionResponse']['responseCode'] : '';

		if ( ! is_array( $payload ) ) {
			error_log( '[Equine Event Manager] Authorize.net unreadable response (' . $http_status . '): ' . substr( (string) $raw_body, 0, 500 ) );
			return new WP_Error( 'authorize_payment_failed', __( 'Authorize.net returned an unreadable response. Please verify the gateway mode, API Login ID, and Transaction Key.', 'equine-event-manager' ) );
		}

		if ( '1' !== $response_code || empty( $payload['transactionResponse'][ 'transId' ] ) ) {
			$messages = $this->get_authorize_net_response_messages( $payload );
			$message  = ! empty( $messages ) ? implode( ' ', $messages ) : __( 'Authorize.net payment failed. Please verify the gateway mode, credentials, and card details, then try again.', 'equine-event-manager' );

			error_log( '[Equine Event Manager] Authorize.net payment failed (' . $http_status . '): ' . wp_json_encode( $payload ) );

			return new WP_Error( 'authorize_payment_failed', $message );
		}

		return array(
			'payment_status'  => 'paid',
			'payment_gateway' => 'authorize_net',
			'transaction_id'  => sanitize_text_field( $payload['transactionResponse']['transId'] ),
		);
	}

	/**
	 * Build a short Authorize.net refId for support and reconciliation.
	 *
	 * @param int   $reservation_id Reservation setup ID.
	 * @param array $submission Submission values.
	 * @return string
	 */
	private function get_authorize_net_reference_id( $reservation_id, $submission ) {
		$token = ! empty( $submission['submission_token'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) $submission['submission_token'] ) : '';

		return substr( 'en' . absint( $reservation_id ) . substr( $token, 0, 12 ), 0, 20 );
	}

	/**
	 * Build an invoice number that respects Authorize.net length limits.
	 *
	 * @param int $reservation_id Reservation setup ID.
	 * @return string
	 */
	private function get_authorize_net_invoice_number( $reservation_id ) {
		return substr( 'EN' . absint( $reservation_id ) . gmdate( 'ymdHis' ), 0, 20 );
	}

	/**
	 * Normalize billing country values for Authorize.net.
	 *
	 * @param string $country Raw billing country.
	 * @return string
	 */
	private function normalize_authorize_net_country( $country ) {
		$country = trim( (string) $country );

		if ( 'United States' === $country ) {
			return 'US';
		}

		if ( 'Canada' === $country ) {
			return 'CA';
		}

		return $country;
	}

	/**
	 * Parse an Authorize.net API response body.
	 *
	 * Accept JSON when available, but gracefully fall back to XML because some
	 * gateways and edge environments still return XML even when JSON is requested.
	 *
	 * @param string $raw_body Raw HTTP response body.
	 * @return array|null
	 */
	private function parse_authorize_net_response_body( $raw_body ) {
		$raw_body = trim( (string) $raw_body );

		if ( '' === $raw_body ) {
			return null;
		}

		$json_payload = json_decode( $raw_body, true );

		if ( is_array( $json_payload ) ) {
			return $this->normalize_authorize_net_payload( $json_payload );
		}

		if ( '<' === substr( ltrim( $raw_body ), 0, 1 ) && function_exists( 'simplexml_load_string' ) ) {
			$previous_setting = libxml_use_internal_errors( true );
			$xml              = simplexml_load_string( $raw_body, 'SimpleXMLElement', LIBXML_NOCDATA );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_setting );

			if ( false !== $xml ) {
				$xml_payload = json_decode( wp_json_encode( $xml ), true );

				if ( is_array( $xml_payload ) ) {
					return $this->normalize_authorize_net_payload( $xml_payload );
				}
			}
		}

		return null;
	}

	/**
	 * Normalize Authorize.net response payloads across JSON and XML formats.
	 *
	 * @param array $payload Raw decoded payload.
	 * @return array
	 */
	private function normalize_authorize_net_payload( $payload ) {
		if ( isset( $payload['createTransactionResponse'] ) && is_array( $payload['createTransactionResponse'] ) ) {
			$payload = $payload['createTransactionResponse'];
		}

		if ( ! empty( $payload['transactionResponse']['errors']['errorText'] ) ) {
			$payload['transactionResponse']['errors'] = array( $payload['transactionResponse']['errors'] );
		} elseif ( ! empty( $payload['transactionResponse']['errors'] ) && isset( $payload['transactionResponse']['errors']['error'] ) ) {
			$payload['transactionResponse']['errors'] = $this->normalize_authorize_net_collection( $payload['transactionResponse']['errors']['error'] );
		}

		if ( ! empty( $payload['messages']['message'] ) ) {
			$payload['messages']['message'] = $this->normalize_authorize_net_collection( $payload['messages']['message'] );
		}

		return $payload;
	}

	/**
	 * Normalize a mixed Authorize.net message/error collection to a flat list.
	 *
	 * @param mixed $value Collection value.
	 * @return array
	 */
	private function normalize_authorize_net_collection( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return array( $value );
		}

		return $value;
	}

	/**
	 * Extract readable messages from an Authorize.net response payload.
	 *
	 * @param array $payload Parsed response payload.
	 * @return array
	 */
	private function get_authorize_net_response_messages( $payload ) {
		$messages = array();

		if ( ! empty( $payload['transactionResponse']['errors'] ) && is_array( $payload['transactionResponse']['errors'] ) ) {
			foreach ( $payload['transactionResponse']['errors'] as $error_row ) {
				if ( ! empty( $error_row['errorText'] ) ) {
					$messages[] = sanitize_text_field( $error_row['errorText'] );
				}
			}
		}

		if ( ! empty( $payload['messages']['message'] ) && is_array( $payload['messages']['message'] ) ) {
			foreach ( $payload['messages']['message'] as $message_row ) {
				if ( ! empty( $message_row['text'] ) ) {
					$messages[] = sanitize_text_field( $message_row['text'] );
				}
			}
		}

		return array_values( array_unique( array_filter( $messages ) ) );
	}

	/**
	 * Create a Stripe PaymentIntent.
	 *
	 * @param int    $reservation_id Reservation setup ID.
	 * @param array  $submission Submission values.
	 * @param array  $totals Calculated totals.
	 * @param string $secret_key Stripe secret key.
	 * @return array|WP_Error
	 */
	private function create_stripe_payment_intent( $reservation_id, $submission, $totals, $secret_key ) {
		$body = array(
			'amount'                    => absint( round( $totals['total'] * 100 ) ),
			'currency'                  => 'usd',
			'payment_method_types[]'    => 'card',
			'description'               => sprintf( 'Equine Event Manager reservation %d', absint( $reservation_id ) ),
			'receipt_email'             => $submission['email'],
			'metadata[reservation_id]'  => absint( $reservation_id ),
			'metadata[customer_email]'  => $submission['email'],
			'metadata[submission_token]' => $submission['submission_token'],
		);

		return $this->request_stripe_api( 'POST', 'payment_intents', $secret_key, $body );
	}

	/**
	 * Retrieve a Stripe PaymentIntent.
	 *
	 * @param string $intent_id Stripe PaymentIntent ID.
	 * @param string $secret_key Stripe secret key.
	 * @return array|WP_Error
	 */
	private function get_stripe_payment_intent( $intent_id, $secret_key ) {
		$intent_id = sanitize_text_field( $intent_id );

		if ( '' === $intent_id ) {
			return new WP_Error( 'stripe_missing_intent', __( 'Missing Stripe payment intent.', 'equine-event-manager' ) );
		}

		return $this->request_stripe_api( 'GET', 'payment_intents/' . rawurlencode( $intent_id ), $secret_key );
	}

	/**
	 * Make a Stripe API request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Stripe API path.
	 * @param string $secret_key Stripe secret key.
	 * @param array  $body Optional request body.
	 * @return array|WP_Error
	 */
	private function request_stripe_api( $method, $path, $secret_key, $body = array() ) {
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
			),
		);

		if ( ! empty( $body ) && 'GET' !== strtoupper( $method ) ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( 'https://api.stripe.com/v1/' . ltrim( $path, '/' ), $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_request_failed', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Stripe request failed.', 'equine-event-manager' );
			return new WP_Error( 'stripe_request_failed', $message );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Get all saved reservation setup meta with defaults.
	 *
	 * @param int $reservation_id Reservation setup ID.
	 * @return array
	 */
	private function get_reservation_meta( $reservation_id ) {
		$defaults = array(
			'use_global_event_source'        => 1,
			'event_source'                    => 'native',
			'event_id'                        => 0,
			'event_feed_url'                  => '',
			'external_event_id'               => '',
			'stalls_enabled'                  => 0,
			'stall_selection_mode'            => 'quantity',
			'stall_description'               => '',
			'rv_enabled'                      => 0,
			'rv_description'                  => '',
			'nightly_enabled'                 => 1,
			'weekend_enabled'                 => 1,
			'stall_nightly_enabled'           => 1,
			'stall_weekend_enabled'           => 1,
			'rv_nightly_enabled'              => 1,
			'rv_weekend_enabled'              => 1,
			'available_start_date'            => '',
			'available_end_date'              => '',
			'weekend_package_start_date'      => '',
			'weekend_package_end_date'        => '',
			'stall_weekend_package_start_date' => '',
			'stall_weekend_package_end_date'   => '',
			'rv_weekend_package_start_date'    => '',
			'rv_weekend_package_end_date'      => '',
			'stall_schedule_enabled'          => 0,
			'stalls_open_at'                  => '',
			'stalls_close_at'                 => '',
			'stall_inventory'                 => '',
			'rv_schedule_enabled'             => 0,
			'rv_open_at'                      => '',
			'rv_close_at'                     => '',
			'rv_inventory'                    => '',
			'rv_addons_enabled'               => 0,
			'stall_nightly_rate'              => '0.00',
			'stall_weekend_rate'              => '0.00',
			'stall_early_bird_enabled'        => 0,
			'stall_early_bird_cutoff'         => '',
			'stall_early_bird_nightly_rate'   => '0.00',
			'stall_early_bird_weekend_rate'   => '0.00',
			'required_shavings_enabled'       => 0,
			'required_shavings_per_stall'     => 0,
			'required_shavings_price'         => '0.00',
			'additional_shavings_enabled'     => 0,
			'additional_shavings_description' => '',
			'additional_shavings_price'       => '0.00',
			'reservation_description'         => '',
			'event_details_summary'           => '',
			'venue_name'                      => '',
			'event_location'                  => '',
			'venue_address'                   => '',
			'checkin_checkout_enabled'        => 1,
			'checkin_time_enabled'            => 1,
			'checkout_time_enabled'           => 1,
			'checkin_time'                    => '',
			'checkout_time'                   => '',
			'venue_map_enabled'              => 0,
			'venue_map_download_url'          => '',
			'venue_map_image_id'              => 0,
			'venue_map_caption'               => '',
			'stall_chart_enabled'             => 0,
			'stall_chart_stall_blocks'        => array(),
			'stall_chart_blocked_stall_units' => array(),
			'stall_map_file_id'               => 0,
			'venue_agreement_enabled'         => 0,
			'venue_agreement_file_id'         => 0,
			'venue_agreement_file_label'      => __( 'Agreement', 'equine-event-manager' ),
			'venue_agreement_label'           => __( 'I agree to the venue terms and conditions.', 'equine-event-manager' ),
			'venue_agreement_text'            => '',
			'general_addons_enabled'          => 0,
			'group_reservations_enabled'      => 0,
			'group_rider_grounds_fee_enabled' => 0,
			'group_rider_grounds_fee_amount'  => '0.00',
			'group_rider_deposit_enabled'     => 0,
			'group_rider_deposit_amount'      => '0.00',
			'general_addons'                  => array(),
			'rv_lot_selection_enabled'        => 0,
			'rv_lots'                         => array(),
			'rv_nightly_rate'                 => '0.00',
			'rv_weekend_rate'                 => '0.00',
			'rv_early_bird_enabled'           => 0,
			'rv_early_bird_cutoff'            => '',
			'rv_early_bird_nightly_rate'      => '0.00',
			'rv_early_bird_weekend_rate'      => '0.00',
			'convenience_fee_label'           => __( 'Non-Refundable Convenience Fee', 'equine-event-manager' ),
			'convenience_fee_enabled'         => 0,
			'convenience_fee_type'            => 'none',
			'convenience_fee_value'           => '0.00',
		);

		$defaults['rv_addons'] = array();

		$data = array();

		foreach ( $defaults as $key => $default ) {
			$value        = get_post_meta( $reservation_id, '_en_' . $key, true );
			$data[ $key ] = '' === $value ? $default : $value;
		}

		if ( ! metadata_exists( 'post', $reservation_id, '_en_use_global_event_source' ) ) {
			$legacy_source_config = '' !== (string) get_post_meta( $reservation_id, '_en_event_source', true )
				|| absint( get_post_meta( $reservation_id, '_en_event_id', true ) ) > 0
				|| '' !== (string) get_post_meta( $reservation_id, '_en_event_feed_url', true )
				|| '' !== (string) get_post_meta( $reservation_id, '_en_external_event_name', true );

			$data['use_global_event_source'] = $legacy_source_config ? 0 : 1;
		}

		if ( ! empty( $data['use_global_event_source'] ) ) {
			$data['event_source'] = EEM_Events::get_default_event_source();
		}

		if ( empty( $data['stall_schedule_enabled'] ) && ( ! empty( $data['stalls_open_at'] ) || ! empty( $data['stalls_close_at'] ) ) ) {
			$data['stall_schedule_enabled'] = 1;
		}

		if ( empty( $data['rv_schedule_enabled'] ) && ( ! empty( $data['rv_open_at'] ) || ! empty( $data['rv_close_at'] ) ) ) {
			$data['rv_schedule_enabled'] = 1;
		}

		if ( empty( $data['rv_addons'] ) || ! is_array( $data['rv_addons'] ) ) {
			$legacy_rv_addons = array();

			foreach ( $this->get_rv_addon_definitions() as $addon_key => $addon_label ) {
				$is_enabled   = ! empty( get_post_meta( $reservation_id, '_en_rv_addon_' . $addon_key . '_enabled', true ) );
				$nightly_rate = $this->sanitize_money_value( get_post_meta( $reservation_id, '_en_rv_addon_' . $addon_key . '_nightly_rate', true ) );
				$weekend_rate = $this->sanitize_money_value( get_post_meta( $reservation_id, '_en_rv_addon_' . $addon_key . '_weekend_rate', true ) );
				$price        = '0.00' !== $nightly_rate ? $nightly_rate : $weekend_rate;

				if ( ! $is_enabled && '0.00' === $nightly_rate && '0.00' === $weekend_rate ) {
					continue;
				}

				$legacy_rv_addons[] = array(
					'name'        => $addon_label,
					'description' => '',
					'price'       => $price,
				);
			}

			$data['rv_addons'] = $legacy_rv_addons;
		}

		if ( empty( $data['rv_addons_enabled'] ) && ! empty( $data['rv_addons'] ) && is_array( $data['rv_addons'] ) ) {
			$data['rv_addons_enabled'] = 1;
		}

		if ( '' === $data['available_start_date'] ) {
			$stall_start_date              = get_post_meta( $reservation_id, '_en_stall_available_start_date', true );
			$rv_start_date                 = get_post_meta( $reservation_id, '_en_rv_available_start_date', true );
			$data['available_start_date'] = $stall_start_date ? $stall_start_date : $rv_start_date;
		}

		if ( '' === $data['available_end_date'] ) {
			$stall_end_date              = get_post_meta( $reservation_id, '_en_stall_available_end_date', true );
			$rv_end_date                 = get_post_meta( $reservation_id, '_en_rv_available_end_date', true );
			$data['available_end_date'] = $stall_end_date ? $stall_end_date : $rv_end_date;
		}

		$data['available_start_date']       = $this->normalize_date_for_input( $data['available_start_date'] );
		$data['available_end_date']         = $this->normalize_date_for_input( $data['available_end_date'] );
		$data['weekend_package_start_date'] = $this->normalize_date_for_input( $data['weekend_package_start_date'] );
		$data['weekend_package_end_date']   = $this->normalize_date_for_input( $data['weekend_package_end_date'] );
		$data['stall_weekend_package_start_date'] = $this->normalize_date_for_input( $data['stall_weekend_package_start_date'] );
		$data['stall_weekend_package_end_date']   = $this->normalize_date_for_input( $data['stall_weekend_package_end_date'] );
		$data['rv_weekend_package_start_date']    = $this->normalize_date_for_input( $data['rv_weekend_package_start_date'] );
		$data['rv_weekend_package_end_date']      = $this->normalize_date_for_input( $data['rv_weekend_package_end_date'] );

		if ( '' === $data['stall_weekend_package_start_date'] ) {
			$data['stall_weekend_package_start_date'] = $data['weekend_package_start_date'] ? $data['weekend_package_start_date'] : $data['available_start_date'];
		}

		if ( '' === $data['stall_weekend_package_end_date'] ) {
			$data['stall_weekend_package_end_date'] = $data['weekend_package_end_date'] ? $data['weekend_package_end_date'] : $data['available_end_date'];
		}

		if ( '' === $data['rv_weekend_package_start_date'] ) {
			$data['rv_weekend_package_start_date'] = $data['weekend_package_start_date'] ? $data['weekend_package_start_date'] : $data['available_start_date'];
		}

		if ( '' === $data['rv_weekend_package_end_date'] ) {
			$data['rv_weekend_package_end_date'] = $data['weekend_package_end_date'] ? $data['weekend_package_end_date'] : $data['available_end_date'];
		}

		if ( ! $data['stall_nightly_enabled'] && ! $data['stall_weekend_enabled'] ) {
			$data['stall_nightly_enabled'] = $data['nightly_enabled'] ? 1 : 0;
			$data['stall_weekend_enabled'] = $data['weekend_enabled'] ? 1 : 0;
		}

		if ( ! $data['rv_nightly_enabled'] && ! $data['rv_weekend_enabled'] ) {
			$data['rv_nightly_enabled'] = $data['nightly_enabled'] ? 1 : 0;
			$data['rv_weekend_enabled'] = $data['weekend_enabled'] ? 1 : 0;
		}

		return $data;
	}

	/**
	 * Get reservation configuration data for helper methods that still call the
	 * older reservation-data accessor name.
	 *
	 * @param int $reservation_id Reservation setup ID.
	 * @return array
	 */
	private function get_reservation_data( $reservation_id ) {
		return $this->get_reservation_meta( $reservation_id );
	}

	/**
	 * Normalize a stored date value for date input fields.
	 *
	 * @param string $value Raw stored date.
	 * @return string
	 */
	private function normalize_date_for_input( $value ) {
		if ( ! $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $value, $matches ) ) {
			return $matches[0];
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return date( 'Y-m-d', $timestamp );
	}

	/**
	 * Build selectable stay date options from a reservation date range.
	 *
	 * @param string $start_date Range start date in Y-m-d format.
	 * @param string $end_date   Range end date in Y-m-d format.
	 * @return array<string, string>
	 */
	private function get_available_stay_date_options( $start_date, $end_date ) {
		$options = array();

		if ( $this->is_valid_date( $start_date ) && ! $this->is_valid_date( $end_date ) ) {
			$end_date = $start_date;
		}

		if ( $this->is_valid_date( $end_date ) && ! $this->is_valid_date( $start_date ) ) {
			$start_date = $end_date;
		}

		if ( ! $this->is_valid_date( $start_date ) || ! $this->is_valid_date( $end_date ) ) {
			return $options;
		}

		$timezone = wp_timezone();
		$start    = date_create_immutable_from_format( '!Y-m-d', $start_date, $timezone );
		$end      = date_create_immutable_from_format( '!Y-m-d', $end_date, $timezone );

		if ( ! $start || ! $end || $start > $end ) {
			return $options;
		}

		for ( $current = $start; $current <= $end; $current = $current->modify( '+1 day' ) ) {
			$value             = $current->format( 'Y-m-d' );
			$options[ $value ] = wp_date( 'l, F j, Y', $current->getTimestamp(), $timezone );
		}

		return $options;
	}

	/**
	 * Get open/closed and inventory status for stall and RV reservations.
	 *
	 * @param array $data Reservation setup data.
	 * @param int   $reservation_id Reservation setup ID.
	 * @return array
	 */
	private function get_reservation_status( $data, $reservation_id = 0 ) {
		$inventory        = $this->get_reservation_inventory_usage( $reservation_id );
		$stall_total      = $this->normalize_inventory_limit( isset( $data['stall_inventory'] ) ? $data['stall_inventory'] : '' );
		$rv_total         = $this->normalize_inventory_limit( isset( $data['rv_inventory'] ) ? $data['rv_inventory'] : '' );
		$rv_lot_inventory = $this->get_rv_lot_inventory_status_map( $data, $inventory );
		$stall_remaining  = null;
		$rv_remaining     = null;
		$stalls_open      = ! empty( $data['stalls_enabled'] ) && $this->is_reservation_type_open( $data, 'stalls' );
		$rv_open          = ! empty( $data['rv_enabled'] ) && $this->is_reservation_type_open( $data, 'rv' );
		$shavings_open    = false;

		if ( null !== $stall_total ) {
			$stall_remaining = max( 0, $stall_total - $inventory['stall_sold'] );
		}

		if ( null !== $rv_total ) {
			$rv_remaining = max( 0, $rv_total - $inventory['rv_sold'] );
		}

		if ( ! empty( $data['rv_lot_selection_enabled'] ) && ! empty( $rv_lot_inventory ) ) {
			$rv_total = null;
			$rv_remaining = null;

			foreach ( $rv_lot_inventory as $lot_inventory ) {
				if ( ! is_array( $lot_inventory ) ) {
					continue;
				}

				if ( null === $lot_inventory['remaining'] || $lot_inventory['remaining'] > 0 ) {
					$rv_remaining = null;
					break;
				}
			}
		}

		$stalls_sold_out = ! empty( $data['stalls_enabled'] ) && null !== $stall_remaining && $stall_remaining <= 0;
		$rv_sold_out     = ! empty( $data['rv_enabled'] ) && null !== $rv_remaining && $rv_remaining <= 0;

		if ( ! empty( $data['rv_lot_selection_enabled'] ) && ! empty( $rv_lot_inventory ) ) {
			$rv_sold_out = true;

			foreach ( $rv_lot_inventory as $lot_inventory ) {
				if ( ! is_array( $lot_inventory ) ) {
					continue;
				}

				if ( empty( $lot_inventory['sold_out'] ) ) {
					$rv_sold_out = false;
					break;
				}
			}
		}

		return array(
			'stalls_open'               => $stalls_open,
			'rv_open'                   => $rv_open,
			'stall_inventory_total'     => $stall_total,
			'stall_inventory_sold'      => $inventory['stall_sold'],
			'stall_inventory_remaining' => $stall_remaining,
			'stalls_sold_out'           => $stalls_sold_out,
			'stalls_bookable'           => $stalls_open && ! $stalls_sold_out,
			'shavings_open'             => false,
			'shavings_bookable'         => false,
			'rv_inventory_total'        => $rv_total,
			'rv_inventory_sold'         => $inventory['rv_sold'],
			'rv_inventory_remaining'    => $rv_remaining,
			'rv_lot_inventory'         => $rv_lot_inventory,
			'rv_sold_out'               => $rv_sold_out,
			'rv_bookable'               => $rv_open && ! $rv_sold_out,
		);
	}


	/**
	 * Get the current sold inventory for a reservation setup.
	 *
	 * @param int $reservation_id Reservation setup ID.
	 * @return array<string, int>
	 */
	private function get_reservation_inventory_usage( $reservation_id ) {
		global $wpdb;

		$reservation_id = absint( $reservation_id );

		if ( ! $reservation_id ) {
			return array(
				'stall_sold'          => 0,
				'rv_sold'             => 0,
				'rv_lot_sold_by_name' => array(),
			);
		}

		$notes_like  = '%' . $wpdb->esc_like( 'Reservation setup ID: ' . $reservation_id ) . '%';
		$stall_table = $wpdb->prefix . 'en_stall_reservations';
		$rv_table    = $wpdb->prefix . 'en_rv_reservations';

		$stall_sold = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(stall_qty + tack_stall_qty), 0) FROM {$stall_table} WHERE notes LIKE %s AND payment_status != %s",
				$notes_like,
				'refunded'
			)
		);
		$rv_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rv_qty, notes FROM {$rv_table} WHERE notes LIKE %s AND payment_status != %s",
				$notes_like,
				'refunded'
			),
			ARRAY_A
		);

		$rv_sold             = 0;
		$rv_lot_sold_by_name = array();

		foreach ( (array) $rv_rows as $rv_row ) {
			$rv_qty  = absint( isset( $rv_row['rv_qty'] ) ? $rv_row['rv_qty'] : 0 );
			$rv_sold += $rv_qty;

			if ( $rv_qty < 1 || empty( $rv_row['notes'] ) ) {
				continue;
			}

			if ( preg_match( '/(?:^|\n)RV Lot:\s*(.+)$/mi', (string) $rv_row['notes'], $matches ) ) {
				$lot_name = sanitize_text_field( trim( (string) $matches[1] ) );

				if ( '' !== $lot_name ) {
					if ( ! isset( $rv_lot_sold_by_name[ $lot_name ] ) ) {
						$rv_lot_sold_by_name[ $lot_name ] = 0;
					}

					$rv_lot_sold_by_name[ $lot_name ] += $rv_qty;
				}
			}
		}

		return array(
			'stall_sold'          => max( 0, $stall_sold ),
			'rv_sold'             => max( 0, $rv_sold ),
			'rv_lot_sold_by_name' => $rv_lot_sold_by_name,
		);
	}

	/**
	 * Build per-lot RV inventory status.
	 *
	 * @param array $data Reservation setup data.
	 * @param array $inventory Inventory usage data.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_rv_lot_inventory_status_map( $data, $inventory ) {
		$rv_lot_inventory = array();
		$rv_lots = $this->get_enabled_rv_lots( $data );
		$sold_by_name = isset( $inventory['rv_lot_sold_by_name'] ) && is_array( $inventory['rv_lot_sold_by_name'] ) ? $inventory['rv_lot_sold_by_name'] : array();

		foreach ( $rv_lots as $lot_key => $lot ) {
			$lot_name = isset( $lot['name'] ) ? sanitize_text_field( $lot['name'] ) : '';
			if ( '' === $lot_name ) {
				continue;
			}

			$total = $this->normalize_inventory_limit( isset( $lot['inventory'] ) ? $lot['inventory'] : '' );
			$sold = isset( $sold_by_name[ $lot_name ] ) ? absint( $sold_by_name[ $lot_name ] ) : 0;
			$remaining = null === $total ? null : max( 0, $total - $sold );

			$rv_lot_inventory[ (string) $lot_key ] = array(
				'label'     => $lot_name,
				'total'     => $total,
				'sold'      => $sold,
				'remaining' => $remaining,
				'sold_out'  => null !== $remaining && $remaining <= 0,
			);
		}

		return $rv_lot_inventory;
	}

	/**
	 * Normalize an inventory value to an integer or null for unlimited.
	 *
	 * @param mixed $value Raw inventory value.
	 * @return int|null
	 */
	private function normalize_inventory_limit( $value ) {
		if ( '' === $value || null === $value ) {
			return null;
		}

		return max( 0, absint( $value ) );
	}

	/**
	 * Determine whether a reservation window is open.
	 *
	 * @param string $open_at Open datetime.
	 * @param string $close_at Close datetime.
	 * @return bool
	 */
	private function is_window_open( $open_at, $close_at ) {
		$now        = current_time( 'timestamp' );
		$open_time  = $open_at ? strtotime( $open_at ) : 0;
		$close_time = $close_at ? strtotime( $close_at ) : 0;

		if ( $open_time && $now < $open_time ) {
			return false;
		}

		if ( $close_time && $now > $close_time ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine whether scheduling is enabled for a reservation type.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @return bool
	 */
	private function is_schedule_enabled_for_type( $data, $type ) {
		$key = 'stalls' === $type ? 'stall_schedule_enabled' : 'rv_schedule_enabled';

		return ! empty( $data[ $key ] );
	}

	/**
	 * Determine whether a reservation type is currently open.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @return bool
	 */
	private function is_reservation_type_open( $data, $type ) {
		if ( ! $this->is_schedule_enabled_for_type( $data, $type ) ) {
			return true;
		}

		$open_key  = 'stalls' === $type ? 'stalls_open_at' : 'rv_open_at';
		$close_key = 'stalls' === $type ? 'stalls_close_at' : 'rv_close_at';

		return $this->is_window_open( $data[ $open_key ], $data[ $close_key ] );
	}

	/**
	 * Get a closed window message.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @return string
	 */
	private function get_closed_message( $data, $type ) {
		if ( ! $this->is_schedule_enabled_for_type( $data, $type ) ) {
			return '';
		}

		$open_key  = 'stalls' === $type ? 'stalls_open_at' : 'rv_open_at';
		$close_key = 'stalls' === $type ? 'stalls_close_at' : 'rv_close_at';
		$open_at   = ! empty( $data[ $open_key ] ) ? $data[ $open_key ] : '';
		$close_at  = ! empty( $data[ $close_key ] ) ? $data[ $close_key ] : '';
		$settings  = $this->get_reservation_message_settings();
		$event_name = ! empty( $data['external_event_name'] ) ? $data['external_event_name'] : get_the_title();

		if ( $open_at && current_time( 'timestamp' ) < strtotime( $open_at ) ) {
			return $this->replace_reservation_message_tokens( $settings['preopen_message'], $event_name, $open_at, $close_at, $settings['support_phone'], $settings['support_email'] );
		}

		if ( $close_at && current_time( 'timestamp' ) > strtotime( $close_at ) ) {
			return $this->replace_reservation_message_tokens( $settings['closed_message'], $event_name, $open_at, $close_at, $settings['support_phone'], $settings['support_email'] );
		}

		return __( 'Reservations are not available right now.', 'equine-event-manager' );
	}

	/**
	 * Get a sold-out message for a reservation section.
	 *
	 * @param string $type Reservation type.
	 * @return string
	 */
	private function get_sold_out_message( $type ) {
		if ( 'rv' === $type ) {
			return __( 'RV reservations are sold out.', 'equine-event-manager' );
		}

		return __( 'Stall reservations are sold out.', 'equine-event-manager' );
	}

	/**
	 * Get a remaining inventory notice for a reservation section.
	 *
	 * @param string   $type      Reservation type.
	 * @param int|null $remaining Remaining inventory count.
	 * @return string
	 */
	private function get_inventory_notice( $type, $remaining ) {
		if ( null === $remaining ) {
			return '';
		}

		$remaining = absint( $remaining );

		if ( 'rv' === $type ) {
			return sprintf(
				/* translators: %d: remaining RV inventory count. */
				_n( '%d RV spot left.', '%d RV spots left.', $remaining, 'equine-event-manager' ),
				$remaining
			);
		}

		return sprintf(
			/* translators: %d: remaining stall inventory count. */
			_n( '%d stall left.', '%d stalls left.', $remaining, 'equine-event-manager' ),
			$remaining
		);
	}

	/**
	 * Get the reservation-level status message when all reservation sections are closed.
	 *
	 * @param WP_Post $reservation Reservation post object.
	 * @param array   $data Reservation setup data.
	 * @return string
	 */
	private function get_reservation_status_message( $reservation, $data ) {
		$status = $this->get_reservation_status( $data, $reservation ? $reservation->ID : 0 );
		$settings     = $this->get_reservation_message_settings();
		$event_name   = $reservation ? $reservation->post_title : __( 'this event', 'equine-event-manager' );
		$open_times   = array();
		$close_times  = array();
		$current_time = current_time( 'timestamp' );

		foreach ( array( 'stalls', 'rv' ) as $type ) {
			if ( 'stalls' === $type && empty( $data['stalls_enabled'] ) ) {
				continue;
			}

			if ( 'rv' === $type && empty( $data['rv_enabled'] ) ) {
				continue;
			}

			$open_key = 'stalls' === $type ? 'stalls_open_at' : 'rv_open_at';
			$close_key = 'stalls' === $type ? 'stalls_close_at' : 'rv_close_at';

			if ( ! empty( $data[ $open_key ] ) ) {
				$open_times[] = $data[ $open_key ];
			}

			if ( ! empty( $data[ $close_key ] ) ) {
				$close_times[] = $data[ $close_key ];
			}
		}

		usort(
			$open_times,
			function ( $left, $right ) {
				return strtotime( $left ) <=> strtotime( $right );
			}
		);
		usort(
			$close_times,
			function ( $left, $right ) {
				return strtotime( $left ) <=> strtotime( $right );
			}
		);

		foreach ( $open_times as $open_at ) {
			if ( strtotime( $open_at ) > $current_time ) {
				return $this->replace_reservation_message_tokens( $settings['preopen_message'], $event_name, $open_at, ! empty( $close_times ) ? end( $close_times ) : '', $settings['support_phone'], $settings['support_email'] );
			}
		}

		if ( ! empty( $status['stalls_sold_out'] ) && ! empty( $status['rv_sold_out'] ) ) {
			return __( 'Reservations for this event are sold out. Please call for assistance.', 'equine-event-manager' );
		}

		if ( ! empty( $close_times ) ) {
			$last_close = end( $close_times );

			if ( strtotime( $last_close ) < $current_time ) {
				return $this->replace_reservation_message_tokens( $settings['closed_message'], $event_name, ! empty( $open_times ) ? reset( $open_times ) : '', $last_close, $settings['support_phone'], $settings['support_email'] );
			}
		}

		return __( 'Reservations are not available right now.', 'equine-event-manager' );
	}

	/**
	 * Get reservation message settings with defaults.
	 *
	 * @return array
	 */
	private function get_reservation_message_settings() {
		$company_settings = wp_parse_args(
			get_option( 'equine_event_manager_company_settings', array() ),
			array(
				'support_phone' => '',
				'support_email' => get_option( 'admin_email', '' ),
			)
		);

		return wp_parse_args(
			get_option( 'equine_event_manager_reservation_message_settings', array() ),
			array(
				'support_phone'   => $company_settings['support_phone'],
				'support_email'   => $company_settings['support_email'],
				'preopen_message' => self::DEFAULT_PREOPEN_MESSAGE,
				'closed_message'  => self::DEFAULT_CLOSED_MESSAGE,
			)
		);
	}

	/**
	 * Replace reservation message placeholders with live values.
	 *
	 * @param string $template Message template.
	 * @param string $event_name Event name.
	 * @param string $open_at Open datetime.
	 * @param string $close_at Close datetime.
	 * @param string $phone Support phone.
	 * @param string $email Support email.
	 * @return string
	 */
	private function replace_reservation_message_tokens( $template, $event_name, $open_at, $close_at, $phone, $email ) {
		$replacements = array(
			'[event_name]'     => $event_name ? $event_name : __( 'this event', 'equine-event-manager' ),
			'[open_date_time]' => $open_at ? $this->format_datetime_label( $open_at ) : __( 'TBD', 'equine-event-manager' ),
			'[close_date_time]' => $close_at ? $this->format_datetime_label( $close_at ) : __( 'TBD', 'equine-event-manager' ),
			'[phone]'          => $phone ? $phone : __( 'the event office', 'equine-event-manager' ),
			'[email]'          => $email ? $email : get_option( 'admin_email', '' ),
		);

		return strtr( $template, $replacements );
	}

	/**
	 * Get the configured Special Requests description.
	 *
	 * @return string
	 */
	private function get_special_requests_description() {
		$default = self::DEFAULT_SPECIAL_REQUESTS_DESCRIPTION;

		if ( class_exists( 'EEM_Admin' ) ) {
			$default = EEM_Admin::DEFAULT_SPECIAL_REQUESTS_DESCRIPTION;
		}

		return get_option( 'equine_event_manager_special_requests_description', $default );
	}

	/**
	 * Get the billing country dropdown options.
	 *
	 * @return string[]
	 */
	private function get_billing_country_options() {
		return array(
			'United States',
			'Canada',
			'Mexico',
			'Australia',
			'New Zealand',
			'United Kingdom',
			'Ireland',
			'France',
			'Germany',
			'Italy',
			'Spain',
			'Netherlands',
			'Belgium',
			'Sweden',
			'Norway',
			'Denmark',
			'Finland',
			'Switzerland',
			'Austria',
			'Portugal',
			'Brazil',
			'Argentina',
			'Chile',
			'Colombia',
			'South Africa',
			'Japan',
			'Singapore',
			'Hong Kong',
			'United Arab Emirates',
		);
	}

	/**
	 * Get the billing state dropdown options.
	 *
	 * @return string[]
	 */
	private function get_state_options() {
		return array(
			'Alabama',
			'Alaska',
			'Arizona',
			'Arkansas',
			'California',
			'Colorado',
			'Connecticut',
			'Delaware',
			'Florida',
			'Georgia',
			'Hawaii',
			'Idaho',
			'Illinois',
			'Indiana',
			'Iowa',
			'Kansas',
			'Kentucky',
			'Louisiana',
			'Maine',
			'Maryland',
			'Massachusetts',
			'Michigan',
			'Minnesota',
			'Mississippi',
			'Missouri',
			'Montana',
			'Nebraska',
			'Nevada',
			'New Hampshire',
			'New Jersey',
			'New Mexico',
			'New York',
			'North Carolina',
			'North Dakota',
			'Ohio',
			'Oklahoma',
			'Oregon',
			'Pennsylvania',
			'Rhode Island',
			'South Carolina',
			'South Dakota',
			'Tennessee',
			'Texas',
			'Utah',
			'Vermont',
			'Virginia',
			'Washington',
			'West Virginia',
			'Wisconsin',
			'Wyoming',
		);
	}

	/**
	 * Get a display summary for current rates.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @return string
	 */
	private function get_rate_summary( $data, $type ) {
		$parts = array();
		$nightly_enabled_key = 'stall' === $type ? 'stall_nightly_enabled' : 'rv_nightly_enabled';
		$weekend_enabled_key = 'stall' === $type ? 'stall_weekend_enabled' : 'rv_weekend_enabled';

		if ( ! empty( $data[ $nightly_enabled_key ] ) ) {
			$parts[] = sprintf(
				/* translators: %s: nightly rate. */
				__( '%s per night', 'equine-event-manager' ),
				$this->format_money( $this->get_current_rate( $data, $type, 'nightly' ) )
			);
		}

		if ( ! empty( $data[ $weekend_enabled_key ] ) ) {
			$parts[] = sprintf(
				/* translators: %s: weekend rate. */
				__( '%s weekend', 'equine-event-manager' ),
				$this->format_money( $this->get_current_rate( $data, $type, 'weekend' ) )
			);
		}

		return implode( ' / ', $parts );
	}

	/**
	 * Get the current rate for a type and stay type.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @param string $stay_type Stay type.
	 * @return float
	 */
	private function get_current_rate( $data, $type, $stay_type ) {
		$prefix       = 'stall' === $type ? 'stall' : 'rv';
		$is_weekend   = 'weekend' === $stay_type;
		$regular_key  = $prefix . ( $is_weekend ? '_weekend_rate' : '_nightly_rate' );
		$early_key    = $prefix . '_early_bird_' . ( $is_weekend ? 'weekend_rate' : 'nightly_rate' );
		$enabled_key  = $prefix . '_early_bird_enabled';
		$cutoff_key   = $prefix . '_early_bird_cutoff';
		$use_early    = ! empty( $data[ $enabled_key ] ) && ! empty( $data[ $cutoff_key ] ) && current_time( 'timestamp' ) <= strtotime( $data[ $cutoff_key ] );

		return (float) ( $use_early ? $data[ $early_key ] : $data[ $regular_key ] );
	}

	/**
	 * Get the current effective rate for a specific RV add-on.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $addon_key RV add-on key.
	 * @param string $stay_type Stay type.
	 * @return float
	 */
	private function get_current_rv_addon_rate( $data, $addon_key, $stay_type ) {
		$addons = $this->get_enabled_rv_addon_options( $data );

		if ( empty( $addons[ (string) $addon_key ] ) ) {
			return 0.0;
		}

		return isset( $addons[ (string) $addon_key ]['price'] ) ? (float) $addons[ (string) $addon_key ]['price'] : 0.0;
	}

	/**
	 * Determine whether a reservation section is currently using early bird pricing.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @return bool
	 */
	private function is_early_bird_active( $data, $type ) {
		$prefix      = 'stall' === $type ? 'stall' : 'rv';
		$enabled_key = $prefix . '_early_bird_enabled';
		$cutoff_key  = $prefix . '_early_bird_cutoff';

		if ( empty( $data[ $enabled_key ] ) || empty( $data[ $cutoff_key ] ) ) {
			return false;
		}

		$cutoff_timestamp = strtotime( $data[ $cutoff_key ] );

		if ( ! $cutoff_timestamp ) {
			return false;
		}

		return current_time( 'timestamp' ) <= $cutoff_timestamp;
	}

	/**
	 * Get a helper notice when a section is currently using early bird pricing.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $type Reservation type.
	 * @return string
	 */
	private function get_early_bird_notice( $data, $type ) {
		$prefix = 'stall' === $type ? 'stall' : 'rv';

		if ( ! $this->is_early_bird_active( $data, $type ) || empty( $data[ $prefix . '_early_bird_cutoff' ] ) ) {
			return '';
		}

		$cutoff_label = $this->format_datetime_label( $data[ $prefix . '_early_bird_cutoff' ] );

		if ( ! $cutoff_label ) {
			return '';
		}

		return sprintf(
			/* translators: %s: early bird cutoff date/time. */
			__( 'Early Bird rate window is currently open. After %s, regular rates will take effect.', 'equine-event-manager' ),
			$cutoff_label
		);
	}


	/**
	 * Get the enabled stay type options for the reservation form.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, string>
	 */
	private function get_enabled_stay_type_options( $data, $type ) {
		$options = array();
		$nightly_enabled_key = 'stall' === $type ? 'stall_nightly_enabled' : 'rv_nightly_enabled';
		$weekend_enabled_key = 'stall' === $type ? 'stall_weekend_enabled' : 'rv_weekend_enabled';

		if ( ! empty( $data[ $nightly_enabled_key ] ) ) {
			$options['nightly'] = __( 'Nightly', 'equine-event-manager' );
		}

		if ( ! empty( $data[ $weekend_enabled_key ] ) ) {
			$options['weekend'] = __( 'Weekend Rate', 'equine-event-manager' );
		}

		if ( empty( $options ) ) {
			$options['nightly'] = __( 'Nightly', 'equine-event-manager' );
		}

		return $options;
	}

	/**
	 * Get the enabled RV lot options.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, array<string, string>>
	 */
	private function get_enabled_rv_lots( $data ) {
		$lots    = isset( $data['rv_lots'] ) && is_array( $data['rv_lots'] ) ? $data['rv_lots'] : array();
		$results = array();

		foreach ( $lots as $index => $lot ) {
			if ( ! is_array( $lot ) ) {
				continue;
			}

			$name         = isset( $lot['name'] ) ? sanitize_text_field( $lot['name'] ) : '';
			$description  = isset( $lot['description'] ) ? sanitize_text_field( $lot['description'] ) : '';
			$nightly_rate = isset( $lot['nightly_rate'] ) ? (float) $lot['nightly_rate'] : 0.0;
			$weekend_rate = isset( $lot['weekend_rate'] ) ? (float) $lot['weekend_rate'] : 0.0;
			$inventory    = isset( $lot['inventory'] ) ? $this->normalize_inventory_limit( $lot['inventory'] ) : null;

			if ( '' === $name ) {
				continue;
			}

			$results[ (string) $index ] = array(
				'name'         => $name,
				'description'  => $description,
				'nightly_rate' => (string) $nightly_rate,
				'weekend_rate' => (string) $weekend_rate,
				'inventory'    => $inventory,
			);
		}

		return $results;
	}

	/**
	 * Get one RV lot configuration by key.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $lot_key RV lot key.
	 * @return array|null
	 */
	private function get_rv_lot( $data, $lot_key ) {
		$lots = $this->get_enabled_rv_lots( $data );

		return isset( $lots[ (string) $lot_key ] ) ? $lots[ (string) $lot_key ] : null;
	}

	/**
	 * Get the effective RV lot rate for the selected stay type.
	 *
	 * @param array  $data Reservation setup data.
	 * @param string $lot_key RV lot key.
	 * @param string $stay_type Stay type.
	 * @return float
	 */
	private function get_rv_lot_rate( $data, $lot_key, $stay_type ) {
		$lot = $this->get_rv_lot( $data, $lot_key );

		if ( empty( $lot ) ) {
			return $this->get_current_rate( $data, 'rv', $stay_type );
		}

		$rate_key = 'weekend' === $stay_type ? 'weekend_rate' : 'nightly_rate';
		$base_rate = $this->get_current_rate( $data, 'rv', $stay_type );
		$lot_surcharge = isset( $lot[ $rate_key ] ) ? (float) $lot[ $rate_key ] : 0.0;

		return $base_rate + $lot_surcharge;
	}

	/**
	 * Get the enabled RV lot pricing matrix for frontend JavaScript.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_enabled_rv_lot_pricing_matrix( $data, $status = array() ) {
		$matrix = array();
		$inventory_map = isset( $status['rv_lot_inventory'] ) && is_array( $status['rv_lot_inventory'] ) ? $status['rv_lot_inventory'] : array();

		foreach ( $this->get_enabled_rv_lots( $data ) as $lot_key => $lot ) {
			$lot_inventory = isset( $inventory_map[ (string) $lot_key ] ) ? $inventory_map[ (string) $lot_key ] : array();
			$matrix[ $lot_key ] = array(
				'label'       => isset( $lot['name'] ) ? $lot['name'] : '',
				'description' => isset( $lot['description'] ) ? $lot['description'] : '',
				'nightly'     => $this->get_rv_lot_rate( $data, $lot_key, 'nightly' ),
				'weekend'     => $this->get_rv_lot_rate( $data, $lot_key, 'weekend' ),
				'inventory'   => array(
					'total'     => isset( $lot_inventory['total'] ) ? $lot_inventory['total'] : null,
					'remaining' => isset( $lot_inventory['remaining'] ) ? $lot_inventory['remaining'] : null,
					'sold_out'  => ! empty( $lot_inventory['sold_out'] ),
				),
			);
		}

		return $matrix;
	}

	/**
	 * Choose the default RV lot key for the form.
	 *
	 * @param array $rv_lot_options Enabled RV lots.
	 * @param array $status Reservation status payload.
	 * @return string
	 */
	private function get_default_rv_lot_key( $rv_lot_options, $status ) {
		$inventory_map = isset( $status['rv_lot_inventory'] ) && is_array( $status['rv_lot_inventory'] ) ? $status['rv_lot_inventory'] : array();

		foreach ( $rv_lot_options as $lot_key => $lot ) {
			$lot_inventory = isset( $inventory_map[ (string) $lot_key ] ) ? $inventory_map[ (string) $lot_key ] : array();

			if ( empty( $lot_inventory['sold_out'] ) ) {
				return (string) $lot_key;
			}
		}

		return (string) array_key_first( $rv_lot_options );
	}

	/**
	 * Get the legacy fixed RV add-on definitions for backwards compatibility.
	 *
	 * @return array<string, string>
	 */
	private function get_rv_addon_definitions() {
		return array(
			'electric' => __( 'Electric', 'equine-event-manager' ),
			'water'    => __( 'Water', 'equine-event-manager' ),
			'sewage'   => __( 'Sewage', 'equine-event-manager' ),
		);
	}

	/**
	 * Get the enabled RV add-on options.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, array<string, string>>
	 */
	private function get_enabled_rv_addon_options( $data ) {
		if ( empty( $data['rv_addons_enabled'] ) ) {
			return array();
		}

		$options = array();
		$addons  = isset( $data['rv_addons'] ) && is_array( $data['rv_addons'] ) ? $data['rv_addons'] : array();

		foreach ( $addons as $addon_key => $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$name        = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$description = isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '';
			$price       = isset( $addon['price'] ) ? $this->sanitize_money_value( $addon['price'] ) : '';

			if ( '' === $price ) {
				$nightly_rate = isset( $addon['nightly_rate'] ) ? $this->sanitize_money_value( $addon['nightly_rate'] ) : '0.00';
				$weekend_rate = isset( $addon['weekend_rate'] ) ? $this->sanitize_money_value( $addon['weekend_rate'] ) : '0.00';
				$price        = '0.00' !== $nightly_rate ? $nightly_rate : $weekend_rate;
			}

			if ( '' === $name ) {
				continue;
			}

			$options[ (string) $addon_key ] = array(
				'name'        => $name,
				'description' => $description,
				'price'       => '' !== $price ? $price : '0.00',
			);
		}

		return $options;
	}

	/**
	 * Get the enabled RV add-on pricing matrix for frontend JavaScript.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_enabled_rv_addon_pricing_matrix( $data ) {
		$matrix = array();

		foreach ( $this->get_enabled_rv_addon_options( $data ) as $addon_key => $addon ) {
			$matrix[ $addon_key ] = array(
				'label'       => $addon['name'],
				'description' => $addon['description'],
				'nightly'     => $this->get_current_rv_addon_rate( $data, $addon_key, 'nightly' ),
				'weekend'     => $this->get_current_rv_addon_rate( $data, $addon_key, 'weekend' ),
			);
		}

		return $matrix;
	}

	/**
	 * Get the enabled general add-on options.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, array<string, string>>
	 */
	private function get_enabled_general_addon_options( $data ) {
		if ( empty( $data['general_addons_enabled'] ) ) {
			return array();
		}

		$addons  = isset( $data['general_addons'] ) && is_array( $data['general_addons'] ) ? $data['general_addons'] : array();
		$options = array();

		foreach ( $addons as $addon_key => $addon ) {
			if ( ! is_array( $addon ) || empty( $addon['name'] ) ) {
				continue;
			}

			$price = isset( $addon['price'] ) ? (string) $addon['price'] : '0.00';

			if ( (float) $price <= 0 ) {
				continue;
			}

			$options[ (string) $addon_key ] = array(
				'name'        => sanitize_text_field( $addon['name'] ),
				'description' => isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '',
				'applies_to'  => isset( $addon['applies_to'] ) ? sanitize_key( $addon['applies_to'] ) : 'any',
				'price'       => $price,
				'per_label'   => isset( $addon['per_label'] ) ? sanitize_text_field( $addon['per_label'] ) : '',
			);
		}

		return $options;
	}

	/**
	 * Get the enabled general add-on pricing matrix for frontend JavaScript.
	 *
	 * @param array $data Reservation setup data.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_enabled_general_addon_pricing_matrix( $data ) {
		$matrix = array();

		foreach ( $this->get_enabled_general_addon_options( $data ) as $addon_key => $addon ) {
			$matrix[ $addon_key ] = array(
				'name'       => $addon['name'],
				'applies_to' => $addon['applies_to'],
				'price'      => (float) $addon['price'],
				'per_label'  => $addon['per_label'],
			);
		}

		return $matrix;
	}

	/**
	 * Format the general add-on description with optional unit label.
	 *
	 * @param array $addon General add-on config.
	 * @return string
	 */
	private function format_general_addon_description( $addon ) {
		return isset( $addon['description'] ) ? trim( (string) $addon['description'] ) : '';
	}

	/**
	 * Get the inline general add-on price suffix.
	 *
	 * @param array $addon General add-on config.
	 * @return string
	 */
	private function get_general_addon_price_suffix( $addon ) {
		$per_label = isset( $addon['per_label'] ) ? trim( (string) $addon['per_label'] ) : '';

		if ( '' === $per_label ) {
			return '';
		}

		return sprintf(
			/* translators: %s: general add-on unit label. */
			__( ' per %s', 'equine-event-manager' ),
			$per_label
		);
	}

	/**
	 * Get the effective weekend package start date.
	 *
	 * @param array $data Reservation setup data.
	 * @return string
	 */
	private function get_weekend_package_start_date( $data, $type = '' ) {
		$key = $type ? $type . '_weekend_package_start_date' : 'weekend_package_start_date';

		if ( ! empty( $data[ $key ] ) && $this->is_valid_date( $data[ $key ] ) ) {
			return $data[ $key ];
		}

		return ! empty( $data['available_start_date'] ) ? $data['available_start_date'] : '';
	}

	/**
	 * Get the effective weekend package end date.
	 *
	 * @param array $data Reservation setup data.
	 * @return string
	 */
	private function get_weekend_package_end_date( $data, $type = '' ) {
		$key = $type ? $type . '_weekend_package_end_date' : 'weekend_package_end_date';

		if ( ! empty( $data[ $key ] ) && $this->is_valid_date( $data[ $key ] ) ) {
			return $data[ $key ];
		}

		if ( ! empty( $data['available_end_date'] ) ) {
			return $data['available_end_date'];
		}

		return ! empty( $data['available_start_date'] ) ? $data['available_start_date'] : '';
	}

	/**
	 * Calculate a convenience fee.
	 *
	 * @param float $subtotal Subtotal.
	 * @param array $data Reservation setup data.
	 * @return float
	 */
	private function calculate_convenience_fee( $subtotal, $data ) {
		if ( empty( $data['convenience_fee_enabled'] ) ) {
			return 0.00;
		}

		if ( 'flat' === $data['convenience_fee_type'] ) {
			return (float) $data['convenience_fee_value'];
		}

		if ( 'percentage' === $data['convenience_fee_type'] ) {
			return round( $subtotal * ( (float) $data['convenience_fee_value'] / 100 ), 2 );
		}

		return 0.00;
	}

	/**
	 * Find a reservation setup by linked TEC event ID.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $type Reservation type.
	 * @return int
	 */
	private function find_reservation_by_event_id( $event_id, $type ) {
		if ( ! $event_id ) {
			return 0;
		}

		$meta_key = 'stall' === $type ? '_en_stalls_enabled' : '_en_rv_enabled';
		$posts    = get_posts(
			array(
				'post_type'      => 'en_reservation',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_en_event_source',
						'value'   => 'tec',
						'compare' => '=',
					),
					array(
						'key'     => '_en_event_id',
						'value'   => $event_id,
						'compare' => '=',
					),
					array(
						'key'     => $meta_key,
						'value'   => 1,
						'compare' => '=',
					),
				),
			)
		);

		return ! empty( $posts ) ? absint( $posts[0] ) : 0;
	}

	/**
	 * Validate a Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private function is_valid_date( $date ) {
		$parsed = date_create_from_format( 'Y-m-d', $date );

		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Format a money value for display.
	 *
	 * @param mixed $value Money value.
	 * @return string
	 */
	private function format_money( $value ) {
		return '$' . number_format( (float) $value, 2 );
	}

	/**
	 * Render a product/qty heading row.
	 */
	private function render_product_list_header() {
		?>
		<div class="eem-product-list__head">
			<span><?php esc_html_e( 'Product', 'equine-event-manager' ); ?></span>
			<span><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render a product line item.
	 *
	 * @param string $title Item title.
	 * @param string $description Item description.
	 * @param string $name Quantity input name.
	 * @param string $modifier Optional CSS modifier.
	 */
	private function render_product_line_item( $title, $description, $name, $modifier = '', $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dynamic_price_type' => '',
				'static_price'       => null,
				'static_price_suffix'=> '',
				'early_bird_active'  => false,
				'max_quantity'       => null,
				'addon_applies_to'   => '',
				'general_addon_key'  => '',
			)
		);
		$classes = 'eem-product-line-item';
		$title_attributes = '';
		$line_attributes  = '';
		$display_title    = $title;

		if ( $modifier ) {
			$classes .= ' ' . $modifier;
		}

		if ( $args['dynamic_price_type'] ) {
			$title_attributes = sprintf(
				' data-dynamic-price-label="%1$s" data-product-base-label="%2$s"',
				esc_attr( $args['dynamic_price_type'] ),
				esc_attr( $title )
			);
		} elseif ( null !== $args['static_price'] ) {
			$title_attributes = sprintf(
				' data-product-base-label="%1$s" data-static-price="%2$s" data-static-price-suffix="%3$s"',
				esc_attr( $title ),
				esc_attr( $this->format_money( $args['static_price'] ) ),
				esc_attr( (string) $args['static_price_suffix'] )
			);
			$display_title    = $title . ' - ' . $this->format_money( $args['static_price'] ) . (string) $args['static_price_suffix'];
		}

		if ( '' !== (string) $args['general_addon_key'] ) {
			$line_attributes .= sprintf(
				' data-general-addon-key="%1$s" data-addon-applies-to="%2$s"',
				esc_attr( $args['general_addon_key'] ),
				esc_attr( $args['addon_applies_to'] )
			);
		}

		?>
		<div class="<?php echo esc_attr( $classes ); ?>"<?php echo $line_attributes; ?>>
			<div class="eem-product-line-item__content">
				<div class="eem-product-line-item__title"<?php echo $title_attributes; ?>>
					<span class="eem-product-line-item__title-text"><?php echo esc_html( $display_title ); ?></span>
					<?php if ( $args['early_bird_active'] ) : ?>
						<span class="eem-rate-badge eem-rate-badge--inline"><?php esc_html_e( 'Early Bird Rate', 'equine-event-manager' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $description ) : ?>
					<div class="eem-product-line-item__description"><?php echo wp_kses_post( nl2br( esc_html( trim( (string) $description ) ) ) ); ?></div>
				<?php endif; ?>
			</div>
			<div class="eem-product-line-item__qty">
				<?php $this->render_quantity_control( $name, $args['max_quantity'] ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a checkbox-style product line item.
	 *
	 * @param string $title Item title.
	 * @param string $description Item description.
	 * @param string $name Checkbox input name.
	 * @param string $modifier Optional CSS modifier.
	 * @param array  $args Additional arguments.
	 * @return void
	 */
	private function render_checkbox_product_line_item( $title, $description, $name, $modifier = '', $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dynamic_price_type' => '',
				'dynamic_price_key'  => '',
				'disabled'           => false,
			)
		);
		$classes = 'eem-product-line-item eem-product-line-item--checkbox';
		$title_attributes = '';
		$disabled         = ! empty( $args['disabled'] );
		$checkbox_attrs   = $disabled ? ' disabled="disabled" aria-disabled="true"' : '';

		if ( $modifier ) {
			$classes .= ' ' . $modifier;
		}

		if ( $disabled ) {
			$classes .= ' eem-product-line-item--disabled';
		}

		if ( $args['dynamic_price_type'] && '' !== (string) $args['dynamic_price_key'] ) {
			$title_attributes = sprintf(
				' data-dynamic-price-label="%1$s" data-dynamic-price-key="%2$s" data-product-base-label="%3$s"',
				esc_attr( $args['dynamic_price_type'] ),
				esc_attr( $args['dynamic_price_key'] ),
				esc_attr( $title )
			);
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="eem-product-line-item__content">
				<div class="eem-product-line-item__title"<?php echo $title_attributes; ?>>
					<span class="eem-product-line-item__title-text"><?php echo esc_html( $title ); ?></span>
					<span class="eem-rate-badge eem-rate-badge--inline eem-rate-badge--addon"><?php esc_html_e( 'Add-On', 'equine-event-manager' ); ?></span>
				</div>
				<?php if ( $description ) : ?>
					<div class="eem-product-line-item__description"><?php echo wp_kses_post( nl2br( esc_html( trim( (string) $description ) ) ) ); ?></div>
				<?php endif; ?>
			</div>
			<div class="eem-product-line-item__qty">
				<label class="eem-checkbox-control">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo $checkbox_attrs; ?> />
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a read-only product line item.
	 *
	 * @param string $title Item title.
	 * @param string $description Item description.
	 * @param string $data_source Quantity data source.
	 * @param string $modifier Optional CSS modifier.
	 */
	private function render_readonly_product_line_item( $title, $description, $data_source, $modifier = '' ) {
		$classes = 'eem-product-line-item eem-product-line-item--readonly';

		if ( $modifier ) {
			$classes .= ' ' . $modifier;
		}

		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="eem-product-line-item__content">
				<div class="eem-product-line-item__title"><?php echo esc_html( $title ); ?></div>
				<?php if ( $description ) : ?>
					<div class="eem-product-line-item__description"><?php echo wp_kses_post( nl2br( esc_html( trim( (string) $description ) ) ) ); ?></div>
				<?php endif; ?>
			</div>
			<div class="eem-product-line-item__qty">
				<div class="eem-quantity-control eem-quantity-control--readonly" data-eem-quantity-source="<?php echo esc_attr( $data_source ); ?>">
					<button type="button" class="eem-quantity-button" disabled="disabled" aria-hidden="true">-</button>
					<input type="number" value="0" readonly="readonly" tabindex="-1" />
					<button type="button" class="eem-quantity-button" disabled="disabled" aria-hidden="true">+</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a plus/minus quantity control.
	 *
	 * @param string   $name Input name.
	 * @param int|null $max Maximum quantity.
	 */
	private function render_quantity_control( $name, $max = null ) {
		?>
		<div class="eem-quantity-control">
			<button type="button" class="eem-quantity-button" data-eem-quantity-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'equine-event-manager' ); ?>">-</button>
			<input type="number" name="<?php echo esc_attr( $name ); ?>" min="0" step="1" value="0" inputmode="numeric" <?php echo null !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?> />
			<button type="button" class="eem-quantity-button" data-eem-quantity-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'equine-event-manager' ); ?>">+</button>
		</div>
		<?php
	}

	/**
	 * Format a saved date for display.
	 *
	 * @param string $value Date value.
	 * @return string
	 */
	private function format_date_label( $value ) {
		$timestamp = strtotime( $value );

		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Format a saved datetime for display.
	 *
	 * @param string $value Datetime value.
	 * @return string
	 */
	private function format_datetime_label( $value ) {
		$timestamp = strtotime( $value );

		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Format a saved time value for display.
	 *
	 * @param string $value Time value.
	 * @return string
	 */
	private function format_time_label( $value ) {
		$normalized = preg_replace( '/\b(after|before|by)\b/i', '', (string) $value );
		$timestamp  = strtotime( $normalized );

		if ( ! $timestamp ) {
			return trim( (string) $value );
		}

		return date_i18n( 'g:i A', $timestamp );
	}

	/**
	 * Get the display event label for a reservation form.
	 *
	 * @param WP_Post $reservation Reservation post object.
	 * @param array   $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_event_label( $reservation, $data ) {
		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_title = get_the_title( absint( $data['event_id'] ) );

			if ( $event_title ) {
				return $event_title;
			}
		}

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_title = get_the_title( absint( $data['event_id'] ) );

			if ( $event_title ) {
				return $event_title;
			}
		}

		if ( ! empty( $data['external_event_name'] ) ) {
			return $data['external_event_name'];
		}

		return $reservation ? $reservation->post_title : __( 'Event Reservations', 'equine-event-manager' );
	}

	/**
	 * Get linked TEC event dates normalized for display.
	 *
	 * @param int $event_id Event ID.
	 * @return array{start_date:string,end_date:string}
	 */
	private function get_tec_event_date_values( $event_id ) {
		if ( function_exists( 'tribe_get_start_date' ) ) {
			$start = tribe_get_start_date( $event_id, false, 'Y-m-d' );
			$end   = function_exists( 'tribe_get_end_date' ) ? tribe_get_end_date( $event_id, false, 'Y-m-d' ) : $start;

			return array(
				'start_date' => $this->normalize_date_for_input( $start ),
				'end_date'   => $this->normalize_date_for_input( $end ? $end : $start ),
			);
		}

		$start = get_post_meta( $event_id, '_EventStartDate', true );
		$end   = get_post_meta( $event_id, '_EventEndDate', true );

		return array(
			'start_date' => $this->normalize_date_for_input( $start ),
			'end_date'   => $this->normalize_date_for_input( $end ? $end : $start ),
		);
	}

	/**
	 * Get event-source details for the title card.
	 *
	 * @param array $data Reservation meta values.
	 * @return array{venue_name:string,location:string}
	 */
	private function get_reservation_event_card_details( $data ) {
		$venue_name = ! empty( $data['venue_name'] ) ? (string) $data['venue_name'] : '';
		$location   = ! empty( $data['event_location'] ) ? (string) $data['event_location'] : '';

		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_id  = absint( $data['event_id'] );
			$venue_id  = absint( get_post_meta( $event_id, '_EventVenueID', true ) );
			$city      = '';
			$state     = '';

			if ( $venue_id ) {
				$venue_title = get_the_title( $venue_id );

				if ( $venue_title ) {
					$venue_name = $venue_title;
				}

				$city  = (string) get_post_meta( $venue_id, '_VenueCity', true );
				$state = (string) get_post_meta( $venue_id, '_VenueStateProvince', true );

				if ( '' === $state ) {
					$state = (string) get_post_meta( $venue_id, '_VenueState', true );
				}
			}

			if ( '' === $venue_name ) {
				$venue_name = (string) get_post_meta( $event_id, '_EventVenue', true );
			}

			if ( '' === $city ) {
				$city = (string) get_post_meta( $event_id, '_VenueCity', true );
			}

			if ( '' === $state ) {
				$state = (string) get_post_meta( $event_id, '_VenueStateProvince', true );
			}

			if ( '' === $state ) {
				$state = (string) get_post_meta( $event_id, '_VenueState', true );
			}

			$event_location = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );

			if ( '' !== $event_location ) {
				$location = $event_location;
			}
		}

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$native_details = EEM_Events::get_native_event_card_details( absint( $data['event_id'] ) );

			if ( ! empty( $native_details['venue_name'] ) ) {
				$venue_name = $native_details['venue_name'];
			}

			if ( ! empty( $native_details['location'] ) ) {
				$location = $native_details['location'];
			}
		}

		return array(
			'venue_name' => $venue_name,
			'location'   => $location,
		);
	}

	/**
	 * Get a compact event date summary for the event card.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_event_date_summary( $data ) {
		$start_value = ! empty( $data['available_start_date'] ) ? $data['available_start_date'] : '';
		$end_value   = ! empty( $data['available_end_date'] ) ? $data['available_end_date'] : '';

		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_dates = $this->get_tec_event_date_values( absint( $data['event_id'] ) );

			if ( ! empty( $event_dates['start_date'] ) ) {
				$start_value = $event_dates['start_date'];
			}

			if ( ! empty( $event_dates['end_date'] ) ) {
				$end_value = $event_dates['end_date'];
			}
		}

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_dates = EEM_Events::get_native_event_date_values( absint( $data['event_id'] ) );

			if ( ! empty( $event_dates['start_date'] ) ) {
				$start_value = $event_dates['start_date'];
			}

			if ( ! empty( $event_dates['end_date'] ) ) {
				$end_value = $event_dates['end_date'];
			}
		}

		if ( $start_value && $end_value ) {
			return $this->format_compact_date_range_label( $start_value, $end_value );
		}

		if ( $start_value ) {
			return $this->format_compact_date_range_label( $start_value, $start_value );
		}

		if ( $end_value ) {
			return $this->format_compact_date_range_label( $end_value, $end_value );
		}

		return '';
	}

	/**
	 * Format a compact date range label for the title card.
	 *
	 * @param string $start_value Start date value.
	 * @param string $end_value End date value.
	 * @return string
	 */
	private function format_compact_date_range_label( $start_value, $end_value ) {
		$start_timestamp = strtotime( $start_value . ' 00:00:00' );
		$end_timestamp   = strtotime( $end_value . ' 00:00:00' );

		if ( ! $start_timestamp || ! $end_timestamp ) {
			return '';
		}

		if ( wp_date( 'Y-m-d', $start_timestamp ) === wp_date( 'Y-m-d', $end_timestamp ) ) {
			return wp_date( 'M j, Y', $start_timestamp );
		}

		if ( wp_date( 'Y', $start_timestamp ) === wp_date( 'Y', $end_timestamp ) ) {
			if ( wp_date( 'm', $start_timestamp ) === wp_date( 'm', $end_timestamp ) ) {
				return sprintf(
					/* translators: 1: start month/day, 2: end day, 3: year. */
					__( '%1$s-%2$s, %3$s', 'equine-event-manager' ),
					wp_date( 'M j', $start_timestamp ),
					wp_date( 'j', $end_timestamp ),
					wp_date( 'Y', $start_timestamp )
				);
			}

			return sprintf(
				/* translators: 1: start month/day, 2: end month/day, 3: year. */
				__( '%1$s-%2$s, %3$s', 'equine-event-manager' ),
				wp_date( 'M j', $start_timestamp ),
				wp_date( 'M j', $end_timestamp ),
				wp_date( 'Y', $start_timestamp )
			);
		}

		return sprintf(
			/* translators: 1: start date, 2: end date. */
			__( '%1$s - %2$s', 'equine-event-manager' ),
			wp_date( 'M j, Y', $start_timestamp ),
			wp_date( 'M j, Y', $end_timestamp )
		);
	}

	/**
	 * Sanitize a money value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_money_value( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( $value ) : '';
		$value = preg_replace( '/[^0-9.]/', '', $value );

		if ( '' === $value ) {
			return '0.00';
		}

		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Sanitize an optional money value while preserving blank input.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_optional_money_value( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( $value ) : '';
		$value = preg_replace( '/[^0-9.]/', '', $value );

		if ( '' === $value ) {
			return '';
		}

		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Render a notice.
	 *
	 * @param string $message Message.
	 * @param string $type Notice type.
	 * @return string
	 */
	private function render_notice( $message, $type = 'info' ) {
		return sprintf(
			'<div class="eem-reservation-notice eem-reservation-notice--%1$s" role="%3$s">%2$s</div>',
			esc_attr( $type ),
			esc_html( $message ),
			'error' === $type ? 'alert' : 'status'
		);
	}

	/**
	 * Print reservation form assets in the frontend footer when the shortcode is rendered inside filtered content.
	 *
	 * @return void
	 */
	public function render_frontend_form_assets_in_footer() {
		if ( is_admin() || ! self::$reservation_form_assets_needed ) {
			return;
		}

		$this->render_form_styles();
	}

	/**
	 * Render minimal front-end styles for the reservation form.
	 */
	private function render_form_styles() {
		static $rendered = false;

		if ( $rendered ) {
			return;
		}

		$rendered = true;
		?>
		<style>
			.eem-reservation-form-wrap {
				max-width: 1120px;
				--eem-app-card-gap: 0.875rem;
			}
			.eem-reservation-form-wrap,
			.eem-reservation-form-wrap > *,
			.eem-reservation-form,
			.eem-event-details-card,
			.eem-venue-map-card,
			.eem-reservation-section,
			.eem-payment-layout,
			.eem-payment-main,
			.eem-payment-sidebar,
			.eem-payment-sidebar__inner {
				width: 100%;
				max-width: none;
				min-width: 0;
				box-sizing: border-box;
			}
			.eem-reservation-form-wrap:focus,
			.eem-reservation-form-wrap:focus-visible {
				outline: none;
				box-shadow: none;
			}
			.eem-reservation-event-hero {
				display: grid;
				grid-template-columns: minmax(280px, 0.84fr) minmax(0, 1.16fr);
				gap: var(--eem-app-card-gap);
				align-items: stretch;
				margin-bottom: var(--eem-app-card-gap);
			}
			.eem-reservation-event-hero__media {
				min-width: 0;
			}
			.eem-reservation-event-media-card {
				display: grid;
				gap: var(--eem-app-card-gap);
				height: 100%;
				padding: 20px;
				border: 1px solid #dbe4f0;
				border-radius: 18px;
				background: linear-gradient(180deg, #ffffff 0%, #f7fafc 100%);
				box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
			}
			.eem-reservation-event-media-card__visual {
				display: flex;
				align-items: center;
				justify-content: center;
				min-height: 420px;
				border: 1px solid #dbe4f0;
				border-radius: 18px;
				overflow: hidden;
				background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
			}
			.eem-reservation-event-media-card__visual.has-image img {
				display: block;
				width: 100%;
				height: 100%;
				object-fit: cover;
			}
			.eem-reservation-event-media-card__placeholder {
				display: grid;
				gap: 12px;
				justify-items: center;
				padding: 24px;
				text-align: center;
				color: rgba(255, 255, 255, 0.88);
			}
			.eem-reservation-event-media-card__placeholder-icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 76px;
				height: 76px;
				padding: 0 16px;
				border-radius: 22px;
				background: rgba(255, 255, 255, 0.14);
				color: #ffffff;
				font-size: 13px;
				font-weight: 800;
				letter-spacing: 0.12em;
				text-transform: uppercase;
			}
			.eem-reservation-event-media-card__actions {
				display: flex;
			}
			.eem-reservation-event-media-card__button {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 100%;
				min-height: 48px;
				padding: 12px 18px;
				border-radius: 16px;
				background: #111827;
				color: #ffffff;
				font-size: 14px;
				font-weight: 800;
				text-decoration: none;
			}
			.eem-reservation-event-media-card__button:hover,
			.eem-reservation-event-media-card__button:focus {
				color: #ffffff;
				text-decoration: none;
				background: #0f172a;
			}
			.eem-event-details-card,
			.eem-venue-map-card {
				margin-bottom: 22px;
				padding: 24px;
				border: 1px solid #dbe4f0;
				border-radius: 18px;
				background: linear-gradient(180deg, #ffffff 0%, #f7fafc 100%);
				box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
			}
			.eem-reservation-event-hero > .eem-event-details-card {
				height: 100%;
				margin-bottom: 0;
			}
			.eem-event-details-card__eyebrow {
				display: inline-flex;
				align-items: center;
				width: fit-content;
				margin-bottom: 8px;
				padding: 6px 12px;
				border-radius: 999px;
				background: #eef2f6;
				color: #5f6b7a;
				font-size: 12px;
				font-weight: 800;
				letter-spacing: 0.08em;
				text-transform: uppercase;
			}
			.eem-reservation-form-wrap .eem-event-details-card__title {
				margin: 0 0 10px;
				color: #0f172a;
				font-size: 28px !important;
				font-weight: 800;
				line-height: 1.16;
				letter-spacing: -0.02em;
				text-transform: none;
			}
			.eem-event-details-card__meta-label {
				margin: 0 0 4px;
				color: #64748b;
				font-size: 12px;
				font-weight: 700;
				letter-spacing: 0.08em;
				text-transform: uppercase;
			}
			.eem-event-details-card__facts {
				display: flex;
				flex-wrap: wrap;
				gap: 16px 28px;
				margin: 0 0 14px;
				color: #475569;
				font-size: 15px;
				line-height: 1.5;
			}
			.eem-event-details-card__fact {
				display: inline-flex;
				align-items: center;
				gap: 6px;
			}
			.eem-event-details-card__fact-icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 20px;
				min-width: 20px;
				color: #cf2e2e;
				line-height: 1;
			}
			.eem-event-details-card__fact-icon svg {
				display: block;
				width: 18px;
				height: 18px;
			}
			.eem-event-details-card__fact-separator {
				color: #94a3b8;
				font-weight: 600;
			}
			.eem-event-details-card__fact strong {
				color: #0f172a;
				font-weight: 700;
			}
			.eem-event-details-card__fact a {
				color: #2563eb;
				text-decoration: none;
			}
			.eem-event-details-card__fact a:hover,
			.eem-event-details-card__fact a:focus {
				text-decoration: underline;
			}
			.eem-event-details-card__meta,
			.eem-event-details-card__location,
			.eem-event-details-card__summary,
			.eem-venue-map-card__header p {
				margin: 0;
				color: #475569;
				font-size: 15px;
				line-height: 1.6;
			}
			.eem-event-details-card__times {
				display: flex;
				flex-wrap: wrap;
				gap: 14px;
				margin-top: 18px;
			}
			.eem-event-details-card__time-card {
				min-width: 180px;
				padding: 14px 16px;
				border: 1px solid #dbe4f0;
				border-radius: 16px;
				background: #f8fbff;
				box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
			}
			.eem-event-details-card__time-label {
				display: block;
				margin-bottom: 4px;
				color: #7c8ca5;
				font-size: 11px;
				font-weight: 800;
				letter-spacing: 0.1em;
				text-transform: uppercase;
			}
			.eem-event-details-card__time-value {
				display: block;
				color: #0f172a;
				font-size: 18px;
				line-height: 1.3;
			}
			.eem-event-details-card__summary {
				margin-top: 14px;
			}
			.eem-event-details-card__map-link {
				margin-top: 22px;
				padding-top: 22px;
				border-top: 1px solid #e2e8f0;
			}
			.eem-event-details-card__map-link a {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				color: #2563eb;
				font-size: 14px;
				font-weight: 600;
				text-decoration: none;
			}
			.eem-event-details-card__map-link-icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 16px;
				min-width: 16px;
				color: #cf2e2e;
			}
			.eem-event-details-card__map-link-icon svg {
				display: block;
				width: 16px;
				height: 16px;
			}
			.eem-event-details-card__map-link a:hover,
			.eem-event-details-card__map-link a:focus {
				text-decoration: underline;
			}
			.eem-event-details-card__venue {
				display: grid;
				gap: 4px;
				margin-top: 18px;
				padding-top: 18px;
				border-top: 1px solid #e2e8f0;
				color: #0f172a;
			}
			.eem-rv-lot-selector-row {
				align-items: start;
			}
			.eem-rv-lot-selector-row__details {
				min-height: 100%;
				padding: 14px 16px;
				border: 1px solid #dbe4f0;
				border-radius: 16px;
				background: #f8fbff;
			}
			.eem-rv-lot-selector-row__title,
			.eem-rv-lot-selector-row__description {
				margin: 0;
			}
			.eem-rv-lot-selector-row__title {
				color: #0f172a;
				font-size: 14px;
				font-weight: 700;
			}
			.eem-rv-lot-selector-row__description {
				margin-top: 6px;
				color: #64748b;
				font-size: 14px;
				line-height: 1.5;
			}
			.eem-stall-assignment-selector {
				display: grid;
				gap: 14px;
				margin-top: 14px;
				padding: 18px;
				border: 1px solid #dbe4f0;
				border-radius: 18px;
				background: #f8fbff;
			}
			.eem-stall-assignment-selector__toggle-row,
			.eem-stall-assignment-selector__toolbar {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
			}
			.eem-stall-assignment-selector__toggle-row {
				padding: 0;
			}
			.eem-stall-assignment-selector__toggle-copy {
				display: grid;
				gap: 4px;
				flex: 1 1 220px;
			}
			.eem-stall-assignment-selector__toggle-title {
				margin: 0;
				color: #0f172a;
				font-size: 16px;
				font-weight: 800;
				line-height: 1.3;
			}
			.eem-stall-assignment-selector__toggle-note {
				margin: 0;
				color: #64748b;
				font-size: 14px;
				line-height: 1.55;
			}
			.eem-stall-assignment-selector__panel {
				display: grid;
				gap: 16px;
			}
			.eem-stall-assignment-selector--collapsed .eem-stall-assignment-selector__panel,
			.eem-stall-assignment-selector__panel[hidden] {
				display: none !important;
			}
			.eem-stall-assignment-selector__copy h4 {
				margin: 0 0 4px;
				font-size: 16px;
				line-height: 1.3;
			}
			.eem-stall-assignment-selector__copy p {
				margin: 0;
				color: #64748b;
				font-size: 14px;
				line-height: 1.55;
			}
			.eem-stall-assignment-selector__barn-field {
				display: grid;
				gap: 8px;
				flex: 1 1 240px;
				max-width: 320px;
			}
			.eem-stall-assignment-selector__barn-field span {
				color: #0f172a;
				font-size: 13px;
				font-weight: 800;
				letter-spacing: 0.06em;
				text-transform: uppercase;
			}
			.eem-stall-assignment-selector__barn-field select {
				width: 100%;
			}
			.eem-stall-assignment-selector__map-link {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 8px;
				padding: 10px 14px;
				border: 1px solid #cbd5e1;
				border-radius: 999px;
				background: #fff;
				color: #0f172a;
				font-size: 14px;
				font-weight: 700;
				text-decoration: none;
				white-space: nowrap;
			}
			.eem-stall-assignment-selector__map-link-icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 16px;
				min-width: 16px;
				color: #cf2e2e;
			}
			.eem-stall-assignment-selector__map-link-icon svg {
				display: block;
				width: 16px;
				height: 16px;
			}
			.eem-stall-assignment-selector__map-link:hover,
			.eem-stall-assignment-selector__map-link:focus {
				text-decoration: none;
				border-color: #0f172a;
			}
			.eem-stall-assignment-selector__groups {
				display: grid;
				gap: 16px;
			}
			.eem-stall-assignment-selector__legend {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
			}
			.eem-stall-assignment-selector__legend-item {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				padding: 8px 12px;
				border-radius: 999px;
				font-size: 12px;
				font-weight: 700;
			}
			.eem-stall-assignment-selector__legend-item::before {
				content: '';
				width: 10px;
				height: 10px;
				border-radius: 999px;
				background: currentColor;
			}
			.eem-stall-assignment-selector__legend-item--available {
				background: #eaf8ee;
				color: #1f7a3d;
			}
			.eem-stall-assignment-selector__legend-item--reserved {
				background: #fdecec;
				color: #b42318;
			}
			.eem-stall-assignment-selector__legend-item--blocked {
				background: #eef2f6;
				color: #667085;
			}
			.eem-stall-assignment-selector__group {
				display: grid;
				gap: 10px;
			}
			.eem-stall-assignment-selector__group[hidden],
			.eem-stall-assignment-selector__group.is-hidden {
				display: none !important;
			}
			.eem-stall-assignment-selector__group-title {
				color: #0f172a;
				font-size: 13px;
				font-weight: 800;
				letter-spacing: 0.08em;
				text-transform: uppercase;
			}
			.eem-stall-assignment-selector__grid {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
			}
			.eem-stall-assignment-selector__unit {
				position: relative;
				display: inline-flex;
				align-items: center;
			}
			.eem-stall-assignment-selector__unit input {
				position: absolute;
				opacity: 0;
				pointer-events: none;
			}
			.eem-stall-assignment-selector__unit span {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 74px;
				padding: 10px 14px;
				border: 1px solid #cbd5e1;
				border-radius: 999px;
				background: #fff;
				color: #0f172a;
				font-size: 14px;
				font-weight: 700;
				transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease, opacity 0.2s ease;
			}
			.eem-stall-assignment-selector__unit input:checked + span {
				border-color: #0f172a;
				background: #0f172a;
				color: #fff;
			}
			.eem-stall-assignment-selector__unit input:focus + span {
				outline: 2px solid #2563eb;
				outline-offset: 2px;
			}
			.eem-stall-assignment-selector__unit[data-status="available"] span {
				border-color: #73c98f;
				background: #eaf8ee;
				color: #1f7a3d;
			}
			.eem-stall-assignment-selector__unit[data-status="reserved"] span {
				border-color: #efb4b4;
				background: #fdecec;
				color: #b42318;
			}
			.eem-stall-assignment-selector__unit[data-status="blocked"] span {
				border-color: #d0d5dd;
				background: #eef2f6;
				color: #667085;
			}
			.eem-stall-assignment-selector__unit[data-status="reserved"] input,
			.eem-stall-assignment-selector__unit[data-status="blocked"] input {
				display: none;
			}
			.eem-stall-assignment-selector__unit[data-disabled="true"] {
				pointer-events: none;
			}
			.eem-stall-assignment-selector__unit[data-disabled="true"] span {
				opacity: 0.72;
			}
			.eem-stall-assignment-selector__unit[data-status="available"] input:checked + span {
				border-color: #0f172a;
				background: #0f172a;
				color: #fff;
			}
			@media (max-width: 782px) {
				.eem-stall-assignment-selector__toggle-row,
				.eem-stall-assignment-selector__toolbar {
					align-items: stretch;
				}
				.eem-stall-assignment-selector__barn-field,
				.eem-stall-assignment-selector__toggle-copy,
				.eem-stall-assignment-selector__map-link {
					max-width: none;
					width: 100%;
				}
				.eem-stall-assignment-selector__map-link {
					min-height: 48px;
				}
				.eem-stall-assignment-selector__unit span {
					min-width: 64px;
					padding: 10px 12px;
				}
			}
			.eem-venue-map-card__header {
				display: grid;
				gap: 6px;
				margin-bottom: 16px;
			}
			.eem-venue-map-card__header h3 {
				margin: 0;
				font-size: 18px;
			}
			.eem-venue-map-card__image {
				display: block;
				width: 100%;
				border-radius: 14px;
				border: 1px solid #dbe4f0;
			}
			.eem-reservation-form {
				display: grid;
				gap: 22px;
			}
			.eem-reservation-section {
				display: grid;
				gap: 14px;
				padding: 20px 0;
				border-bottom: 1px solid #e2e8f0;
			}
			.eem-reservation-section--instructions {
				padding: 18px;
				border: 1px solid #dbe4f0;
				border-radius: 18px;
				background: #f8fbff;
				box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
			}
			.eem-reservation-section--instructions .eem-reservation-section__title {
				color: #0f172a;
			}
			.eem-reservation-section--instructions .eem-reservation-help {
				color: #64748b;
			}
			.eem-reservation-section__title {
				margin: 0;
				min-width: 0;
			}
			.eem-reservation-section-heading {
				display: flex;
				justify-content: space-between;
				gap: 16px;
				align-items: baseline;
			}
			.eem-reservation-section-heading--collapsible {
				align-items: center;
			}
			.eem-reservation-section-heading--collapsible .eem-reservation-section__title {
				flex: 1 1 auto;
			}
			.eem-reservation-section__body {
				display: grid;
				gap: 14px;
			}
			.eem-group-reservation-toggle {
				display: flex !important;
				align-items: center;
				justify-content: space-between;
				gap: 18px;
				padding: 16px 18px;
				border: 1px solid #dbe4f0;
				border-radius: 16px;
				background: #f8fbff;
			}
			.eem-group-reservation-toggle__copy {
				display: grid;
				gap: 4px;
			}
			.eem-group-reservation-toggle__copy small {
				color: #64748b;
				font-size: 13px;
				font-weight: 500;
				line-height: 1.5;
			}
			.eem-group-reservation-toggle__switch {
				position: relative;
				display: inline-flex;
				flex: 0 0 auto;
			}
			.eem-group-reservation-toggle__switch input {
				position: absolute;
				inset: 0;
				opacity: 0;
				cursor: pointer;
			}
			.eem-group-reservation-toggle__track {
				position: relative;
				display: inline-flex;
				align-items: center;
				width: 58px;
				height: 34px;
				border-radius: 999px;
				background: #cbd5e1;
				transition: background 0.2s ease;
			}
			.eem-group-reservation-toggle__track::after {
				content: "";
				position: absolute;
				top: 4px;
				left: 4px;
				width: 26px;
				height: 26px;
				border-radius: 999px;
				background: #fff;
				box-shadow: 0 2px 6px rgba(15, 23, 42, 0.2);
				transition: transform 0.2s ease;
			}
			.eem-group-reservation-toggle__switch input:checked + .eem-group-reservation-toggle__track {
				background: #52b788;
			}
			.eem-group-reservation-toggle__switch input:checked + .eem-group-reservation-toggle__track::after {
				transform: translateX(24px);
			}
			.eem-group-reservation-fields {
				display: grid;
				gap: 16px;
			}
			.eem-group-reservation-fields[hidden] {
				display: none !important;
			}
			.eem-group-riders-list {
				display: grid;
				gap: 14px;
			}
			.eem-group-rider-card {
				display: grid;
				gap: 12px;
				padding: 16px 18px;
				border: 1px solid #dbe4f0;
				border-radius: 16px;
				background: #ffffff;
			}
			.eem-group-rider-card h4 {
				margin: 0;
				font-size: 15px;
				line-height: 1.3;
			}
			.eem-reservation-section--collapsed .eem-reservation-section__body {
				display: none;
			}
			.eem-reservation-section-toggle {
				position: relative;
				display: inline-flex;
				flex: 0 0 auto;
				line-height: 0;
			}
			.eem-reservation-section-toggle input {
				position: absolute;
				inset: 0;
				opacity: 0;
				cursor: pointer;
			}
			.eem-reservation-section-toggle__track {
				position: relative;
				display: inline-flex;
				align-items: center;
				width: 58px;
				height: 34px;
				border-radius: 999px;
				background: #cbd5e1;
				transition: background 0.2s ease;
			}
			.eem-reservation-section-toggle__track::after {
				content: "";
				position: absolute;
				top: 4px;
				left: 4px;
				width: 26px;
				height: 26px;
				border-radius: 999px;
				background: #fff;
				box-shadow: 0 2px 6px rgba(15, 23, 42, 0.2);
				transition: transform 0.2s ease;
			}
			.eem-reservation-section-toggle input:focus-visible + .eem-reservation-section-toggle__track {
				outline: 2px solid #93c5fd;
				outline-offset: 3px;
			}
			.eem-reservation-section-toggle input:checked + .eem-reservation-section-toggle__track {
				background: #52b788;
			}
			.eem-reservation-section-toggle input:checked + .eem-reservation-section-toggle__track::after {
				transform: translateX(24px);
			}
			.eem-rate-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 4px 10px;
				border-radius: 999px;
				background: #dcfce7;
				color: #166534;
				font-size: 12px;
				font-weight: 800;
				line-height: 1;
				text-transform: uppercase;
				letter-spacing: 0.04em;
				white-space: nowrap;
			}
			.eem-rate-badge--inline {
				margin-left: 10px;
				vertical-align: middle;
			}
			.eem-rate-badge--addon {
				background: #111827;
				color: #ffffff;
			}
			.eem-reservation-section-heading span,
			.eem-reservation-help {
				color: #475569;
				font-size: 14px;
			}
			.eem-reservation-help--early-bird {
				color: #166534;
				font-weight: 600;
			}
			.eem-reservation-help--inventory {
				color: #0f172a;
				font-weight: 600;
			}
			.eem-reservation-grid {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 16px;
			}
			.eem-reservation-grid--single {
				grid-template-columns: minmax(0, 1fr);
			}
			.eem-reservation-grid--two {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}
			.eem-reservation-grid--three {
				grid-template-columns: repeat(3, minmax(0, 1fr));
			}
			.eem-reservation-grid--hidden {
				display: none;
			}
			.eem-product-list {
				display: grid;
				gap: 0;
			}
			.eem-product-list__head {
				display: grid;
				grid-template-columns: minmax(0, 1fr) auto;
				gap: 20px;
				padding: 20px 0 14px;
				border-bottom: 1px solid #e2e8f0;
				color: #0f172a;
				font-size: 13px;
				font-weight: 800;
				letter-spacing: 0.04em;
				text-transform: uppercase;
			}
			.eem-product-list__head span:last-child {
				text-align: right;
			}
			.eem-product-line-item {
				display: grid;
				grid-template-columns: minmax(0, 1fr) auto;
				gap: 20px;
				align-items: center;
				padding: 22px 0;
				border-bottom: 1px solid #e2e8f0;
			}
			.eem-product-line-item:last-child {
				border-bottom: 0;
			}
			.eem-product-line-item--readonly {
				opacity: 0.58;
			}
			.eem-product-line-item__title {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 8px;
				color: #0f172a;
				font-size: 16px;
				font-weight: 800;
				line-height: 1.3;
			}
			.eem-product-line-item__description {
				margin-top: 4px;
				color: #475569;
				font-size: 14px;
				line-height: 1.5;
			}
			.eem-product-line-item__qty {
				display: flex;
				justify-content: flex-end;
			}
			.eem-section-subtotal {
				display: flex;
				justify-content: space-between;
				align-items: center;
				gap: 16px;
				margin-top: 8px;
				padding-top: 18px;
				border-top: 1px solid #e2e8f0;
				color: #0f172a;
				font-size: 16px;
				font-weight: 800;
				line-height: 1.3;
			}
			.eem-section-subtotal strong {
				font-size: 18px;
				font-weight: 800;
			}
			.eem-reservation-form label {
				display: grid;
				gap: 6px;
				font-size: 14px;
				font-weight: 600;
			}
			.eem-reservation-form input,
			.eem-reservation-form select,
			.eem-reservation-form textarea {
				width: 100%;
				min-height: 42px;
				padding: 9px 11px;
				border: 1px solid #cbd5e1;
				border-radius: 4px;
				background: #fff;
				font: inherit;
			}
			.eem-reservation-form textarea {
				min-height: 110px;
			}
			.eem-reservation-section--special-requests textarea {
				margin-top: 20px;
			}
			.eem-checkbox-control {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 38px;
				height: 38px;
				cursor: pointer;
			}
			.eem-checkbox-control input[type="checkbox"] {
				width: 28px;
				height: 28px;
				min-height: 28px;
				margin: 0;
				padding: 0;
				cursor: pointer;
				accent-color: #111827;
			}
			.eem-product-line-item--disabled {
				opacity: 0.55;
			}
			.eem-product-line-item--disabled .eem-checkbox-control {
				cursor: not-allowed;
			}
			.eem-product-line-item--disabled .eem-checkbox-control input[type="checkbox"] {
				cursor: not-allowed;
			}
			.eem-reservation-grid--stay-controls {
				grid-template-columns: 0.72fr 1fr 1fr 0.72fr;
				align-items: end;
			}
			.eem-stay-type-field {
				min-width: 0;
			}
			.eem-stay-night-field {
				display: grid;
				gap: 8px;
				min-width: 0;
			}
			.eem-stay-night-field__label {
				color: #475569;
				font-size: 14px;
				font-weight: 600;
			}
			.eem-stay-night-field__value {
				display: flex;
				align-items: center;
				min-height: 42px;
				padding: 9px 11px;
				border: 1px solid #cbd5e1;
				border-radius: 4px;
				background: #f8fafc;
			}
			.eem-stay-night-field strong {
				color: #0f172a;
				font-size: 14px;
				font-weight: 700;
				line-height: 1.35;
			}
			.eem-reservation-help--tight {
				margin-top: -8px;
			}
			.eem-weekend-package-summary {
				margin-top: 14px;
			}
			.eem-weekend-package-field {
				display: grid;
				gap: 6px;
				padding: 12px 14px;
				border: 1px solid #cbd5e1;
				border-radius: 4px;
				background: #f8fafc;
			}
			.eem-weekend-package-field span {
				color: #475569;
				font-size: 14px;
				font-weight: 600;
			}
			.eem-weekend-package-field strong {
				color: #0f172a;
				font-size: 16px;
				font-weight: 700;
				line-height: 1.35;
			}
			.eem-reservation-help--emphasis {
				font-style: italic;
				font-weight: 700;
				color: #0f172a;
			}
			.eem-reservation-section-title--spaced {
				display: block;
				margin-bottom: 10px;
			}
			.eem-phone-field {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 0 12px;
				border: 1px solid #cbd5e1;
				border-radius: 4px;
				background: #fff;
			}
			.eem-phone-field__flag {
				font-size: 18px;
				line-height: 1;
			}
			.eem-phone-field input {
				border: 0;
				padding-left: 0;
				padding-right: 0;
				box-shadow: none;
			}
			.eem-phone-field input:focus {
				box-shadow: none;
			}
			.eem-quantity-control {
				display: grid;
				grid-template-columns: 52px minmax(62px, 80px) 52px;
				width: fit-content;
				border: 1px solid #d1d5db;
				border-radius: 999px;
				overflow: hidden;
				background: #ffffff;
				box-shadow: none;
			}
			.eem-quantity-control--readonly {
				border-color: #d1d5db;
				background: #ffffff;
			}
			.eem-quantity-control input {
				width: 100%;
				min-height: 44px;
				padding: 8px;
				border: 0;
				text-align: center;
				background: #ffffff !important;
				font-size: 18px;
				font-weight: 700;
				color: #111827 !important;
				appearance: textfield;
				box-shadow: none !important;
			}
			.eem-quantity-control input::-webkit-outer-spin-button,
			.eem-quantity-control input::-webkit-inner-spin-button {
				margin: 0;
				-webkit-appearance: none;
			}
			.eem-quantity-button {
				min-height: 44px;
				border: 0 !important;
				background: #e5e7eb !important;
				box-shadow: none !important;
				color: #111827 !important;
				font-size: 22px;
				font-weight: 700;
				cursor: pointer;
			}
			.eem-quantity-control--readonly .eem-quantity-button,
			.eem-quantity-control--readonly input {
				color: #9ca3af !important;
			}
			.eem-quantity-control--readonly .eem-quantity-button {
				background: #f3f4f6 !important;
			}
			.eem-quantity-button:hover,
			.eem-quantity-button:focus {
				background: #d1d5db !important;
			}
			.eem-payment-summary {
				display: grid;
				gap: 0;
				width: 100%;
				max-width: none;
				border: 1px solid #e2e8f0;
				border-radius: 4px;
				overflow: hidden;
				background: #fff;
			}
			.eem-payment-summary-row {
				display: flex;
				justify-content: space-between;
				gap: 16px;
				padding: 11px 14px;
				border-bottom: 1px solid #e2e8f0;
				color: #334155;
				font-size: 14px;
			}
			.eem-payment-summary-row[hidden] {
				display: none;
			}
			.eem-payment-summary-row:last-child {
				border-bottom: 0;
			}
			.eem-payment-summary-row strong {
				color: #0f172a;
				white-space: nowrap;
			}
			.eem-payment-summary-row--total {
				background: #f8fafc;
				color: #0f172a;
				font-size: 16px;
				font-weight: 700;
			}
			.eem-payment-card-field-wrap {
				display: grid;
				gap: 10px;
				margin-top: 18px;
			}
			.eem-payment-checkout-block {
				display: grid;
				gap: 14px;
			}
			.eem-payment-layout {
				display: grid;
				grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.8fr);
				gap: 28px;
				align-items: start;
			}
			.eem-payment-main {
				display: grid;
				gap: 18px;
			}
			.eem-payment-sidebar__inner {
				position: sticky;
				top: 24px;
				display: grid;
				gap: 12px;
				padding: 20px;
				border: 1px solid #dbe4f0;
				border-radius: 16px;
				background: #f8fafc;
			}
			.eem-venue-agreement-card {
				margin-top: 14px;
				padding: 18px 20px;
				border: 1px solid #f5cf69;
				border-radius: 14px;
				background: #fff8e1;
				color: #5f4b16;
			}
			.eem-venue-agreement-card p {
				margin: 0;
				font-size: 14px;
				line-height: 1.7;
			}
			.eem-venue-agreement-card a {
				color: #2563eb;
				font-weight: 700;
				text-decoration: none;
			}
			.eem-venue-agreement-card a:hover,
			.eem-venue-agreement-card a:focus {
				text-decoration: underline;
			}
			.eem-checkout-subsection-title {
				margin: 0;
				color: #0f172a;
			}
			.eem-checkout-subsection-title--field {
				display: block;
				margin-bottom: 10px;
			}
			.eem-payment-card-grid {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 16px;
			}
			.eem-payment-card-field {
				display: grid;
				gap: 6px;
			}
			.eem-payment-card-field--full {
				grid-column: 1 / -1;
			}
			.eem-payment-card-label {
				display: grid;
				gap: 6px;
			}
			.eem-stripe-card-element {
				min-height: 44px;
				padding: 12px 14px;
				border: 1px solid #cbd5e1;
				border-radius: 4px;
				background: #fff;
				cursor: text;
			}
			.eem-stripe-card-element.StripeElement--focus {
				border-color: #94a3b8;
			}
			.eem-stripe-card-element.StripeElement--invalid {
				border-color: #b91c1c;
			}
			.eem-stripe-card-error,
			.eem-reservation-help--error {
				color: #b91c1c;
				font-weight: 600;
			}
			.eem-payment-card-help {
				margin: 0;
			}
			.eem-reservation-submit[disabled] {
				opacity: 0.7;
				cursor: wait;
			}
			.eem-reservation-submit {
				width: fit-content;
				padding: 11px 18px;
				border: 0;
				border-radius: 4px;
				background: #0f172a;
				color: #fff;
				font-weight: 700;
				cursor: pointer;
			}
			.eem-reservation-submit-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				align-items: center;
			}
			.eem-invoice-mode-card {
				display: grid;
				grid-template-columns: 1fr;
				gap: 14px;
				margin-bottom: 20px;
				padding: 16px 18px;
				border: 1px solid #dbe4ef;
				border-radius: 12px;
				background: #f8fafc;
			}
			.eem-invoice-mode-card__copy {
				display: grid;
				gap: 14px;
				min-width: 0;
				max-width: 100%;
			}
			.eem-invoice-mode-card__copy h4 {
				margin: 0 0 6px;
			}
			.eem-invoice-mode-card__copy .eem-reservation-help {
				margin: 0;
			}
			.eem-invoice-mode-actions {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				justify-content: space-between;
				gap: 14px;
				width: 100%;
			}
			.eem-invoice-mode-toggle {
				display: inline-flex;
				align-items: center;
				gap: 12px;
				justify-self: start;
				white-space: nowrap;
			}
			.eem-invoice-mode-toggle .eem-inline-toggle-control__label {
				white-space: nowrap;
			}
			.eem-reservation-submit-actions--invoice-mode {
				justify-content: flex-start;
				align-items: stretch;
				flex-wrap: wrap;
				margin-left: 0;
			}
			.eem-payment-checkout-block--admin-invoice,
			.eem-payment-card-field-wrap--admin-invoice {
				margin-top: 0;
			}
			.eem-reservation-submit--secondary {
				background: #ffffff;
				color: #0f172a;
				border: 1px solid #0f172a;
			}
			.eem-reservation-notice {
				margin: 0 0 .875rem;
				padding: .875rem 1rem;
				border: 1px solid #dfe7f0;
				border-left: 4px solid #8ca3bb;
				border-radius: 8px;
				background: #ffffff;
				color: #334155;
				box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
				line-height: 1.55;
			}
			.eem-reservation-notice--success {
				border-left-color: #55b985;
			}
			.eem-reservation-notice--error {
				border-left-color: #d35d4e;
			}
			[data-eem-invoice-billing-block][hidden] {
				display: none !important;
			}
			@media (max-width: 980px) {
				.eem-reservation-form-wrap {
					max-width: 100%;
				}
				.eem-reservation-event-hero {
					grid-template-columns: 1fr;
				}
				.eem-reservation-grid,
				.eem-reservation-grid--three,
				.eem-reservation-grid--stay-controls,
				.eem-payment-layout,
				.eem-payment-card-grid {
					grid-template-columns: 1fr;
				}
				.eem-reservation-grid--two {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
				.eem-reservation-section-heading {
					align-items: flex-start;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card {
					padding: 20px;
					border-radius: 20px;
				}
				.eem-event-details-card__facts {
					gap: 12px 18px;
				}
				.eem-event-details-card__times {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
				.eem-payment-sidebar__inner {
					position: static;
				}
				.eem-invoice-mode-card__copy {
					max-width: 100%;
				}
			}
			@media (max-width: 760px) {
				.eem-reservation-grid,
				.eem-reservation-grid--two,
				.eem-reservation-grid--three {
					grid-template-columns: 1fr;
					display: grid;
				}
				.eem-reservation-section-heading {
					display: flex;
					grid-template-columns: none;
					justify-content: space-between;
					align-items: center;
					flex-wrap: nowrap;
				}
				.eem-payment-card-grid {
					grid-template-columns: 1fr;
				}
				.eem-payment-layout {
					grid-template-columns: 1fr;
				}
				.eem-reservation-submit-actions {
					flex-direction: column;
					align-items: stretch;
				}
				.eem-invoice-mode-card {
					flex-direction: column;
					align-items: flex-start;
				}
				.eem-invoice-mode-actions,
				.eem-reservation-submit-actions--invoice-mode {
					width: 100%;
					justify-content: stretch;
				}
				.eem-reservation-submit,
				.eem-reservation-submit--secondary {
					width: 100%;
				}
				.eem-rv-lot-selector-row__details {
					padding: 12px 14px;
				}
				.eem-event-details-card__time-card {
					min-width: 0;
				}
				.eem-payment-sidebar__inner {
					position: static;
				}
				.eem-product-list__head {
					display: none;
				}
				.eem-product-line-item {
					grid-template-columns: 1fr;
				}
				.eem-product-line-item__qty {
					justify-content: flex-start;
				}
				.eem-quantity-control {
					width: 100%;
					grid-template-columns: 64px minmax(0, 1fr) 64px;
				}
				.eem-quantity-control input {
					min-width: 0;
				}
				.eem-product-line-item--checkbox {
					grid-template-columns: minmax(0, 1fr) auto;
					align-items: center;
				}
				.eem-product-line-item--checkbox .eem-product-line-item__qty {
					justify-content: flex-end;
				}
				.eem-rate-badge--inline {
					margin-left: 6px;
				}
				.eem-rate-badge {
					padding: 3px 8px;
					font-size: 10px;
				}
			}
			@media (max-width: 640px) {
				.eem-reservation-form-wrap {
					margin-left: -4px;
					margin-right: -4px;
				}
				.eem-reservation-event-media-card {
					padding: 16px;
					border-radius: 18px;
				}
				.eem-reservation-event-media-card__visual {
					min-height: 280px;
					border-radius: 16px;
				}
				.eem-event-details-card,
				.eem-venue-map-card {
					margin-bottom: 18px;
					padding: 18px 16px;
					border-radius: 18px;
				}
				.eem-reservation-form-wrap .eem-event-details-card__title {
					font-size: 24px !important;
					line-height: 1.12;
				}
				.eem-event-details-card__facts {
					display: grid;
					grid-template-columns: 1fr;
					gap: 10px;
				}
				.eem-event-details-card__times {
					grid-template-columns: 1fr;
					gap: 10px;
				}
				.eem-event-details-card__time-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-invoice-mode-card {
					border-radius: 18px;
				}
				.eem-reservation-form {
					gap: 18px;
				}
				.eem-reservation-section {
					gap: 12px;
					padding: 16px 0;
				}
				.eem-reservation-section-heading,
				.eem-reservation-section-heading--collapsible {
					gap: 10px;
				}
				.eem-product-line-item,
				.eem-product-line-item--checkbox {
					grid-template-columns: 1fr;
					gap: 14px;
					align-items: start;
				}
				.eem-product-line-item__qty,
				.eem-product-line-item--checkbox .eem-product-line-item__qty {
					justify-content: flex-start;
				}
				.eem-reservation-form input,
				.eem-reservation-form select,
				.eem-reservation-form textarea,
				.eem-phone-field,
				.eem-stripe-card-element,
				.eem-stay-night-field__value,
				.eem-weekend-package-field {
					min-height: 48px;
					border-radius: 12px;
				}
				.eem-reservation-form textarea {
					min-height: 132px;
				}
				.eem-payment-summary {
					border-radius: 14px;
				}
				.eem-payment-summary-row {
					flex-direction: column;
					align-items: flex-start;
					gap: 6px;
				}
				.eem-payment-summary-row strong {
					white-space: normal;
				}
				.eem-reservation-submit,
				.eem-reservation-submit--secondary,
				.eem-invoice-mode-actions .eem-inline-toggle-control,
				.eem-invoice-mode-actions [data-eem-invoice-action] {
					width: 100%;
				}
				.eem-invoice-mode-actions,
				.eem-reservation-submit-actions--invoice-mode {
					flex-direction: column;
					align-items: stretch;
				}
				.eem-invoice-mode-toggle {
					width: 100%;
					justify-content: space-between;
				}
			}
			@media (max-width: 1100px) {
				.eem-payment-layout {
					grid-template-columns: 1fr;
				}
				.eem-payment-sidebar__inner {
					position: static;
				}
				.eem-event-details-card__facts {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 12px 16px;
				}
				.eem-event-details-card__times {
					display: grid;
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
				.eem-reservation-grid--stay-controls {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
			}
			@media (max-width: 860px) {
				.eem-reservation-form-wrap {
					max-width: 100%;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card {
					padding: 18px 16px;
					border-radius: 20px;
				}
				.eem-reservation-grid,
				.eem-reservation-grid--two,
				.eem-reservation-grid--three,
				.eem-reservation-grid--stay-controls,
				.eem-payment-card-grid {
					grid-template-columns: 1fr;
				}
				.eem-event-details-card__facts,
				.eem-event-details-card__times {
					grid-template-columns: 1fr;
				}
				.eem-product-line-item,
				.eem-product-line-item--checkbox {
					grid-template-columns: 1fr;
					gap: 14px;
					align-items: start;
				}
				.eem-product-line-item__qty,
				.eem-product-line-item--checkbox .eem-product-line-item__qty {
					justify-content: flex-start;
				}
				.eem-reservation-submit,
				.eem-reservation-submit--secondary {
					width: 100%;
					min-height: 48px;
				}
				.eem-invoice-mode-actions,
				.eem-reservation-submit-actions--invoice-mode {
					width: 100%;
					flex-direction: column;
					align-items: stretch;
					gap: 12px;
				}
				.eem-invoice-mode-toggle {
					width: 100%;
					justify-content: space-between;
				}
			}
			@media (max-width: 700px) {
				.eem-reservation-form-wrap {
					padding-bottom: 18px;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-payment-sidebar__inner {
					border-radius: 22px;
					box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
				}
				.eem-reservation-section {
					padding: 18px 0;
				}
				.eem-reservation-section-heading {
					gap: 12px;
				}
				.eem-event-details-card__title {
					letter-spacing: -0.02em;
				}
				.eem-product-line-item {
					padding: 18px 0;
				}
				.eem-quantity-control {
					width: 100%;
					grid-template-columns: 64px minmax(0, 1fr) 64px;
				}
				.eem-quantity-button,
				.eem-quantity-control input,
				.eem-reservation-submit,
				.eem-reservation-submit--secondary,
				.eem-invoice-mode-actions .eem-inline-toggle-control,
				.eem-invoice-mode-actions [data-eem-invoice-action] {
					min-height: 50px;
				}
				.eem-reservation-form input,
				.eem-reservation-form select,
				.eem-reservation-form textarea,
				.eem-phone-field,
				.eem-stripe-card-element,
				.eem-stay-night-field__value,
				.eem-weekend-package-field {
					border-radius: 14px;
				}
				.eem-reservation-form-wrap .eem-event-details-card__title {
					font-size: 22px !important;
					line-height: 1.08;
				}
				.eem-event-details-card__facts {
					display: grid;
					grid-template-columns: 1fr;
					gap: 12px;
				}
				.eem-event-details-card__fact {
					display: grid;
					grid-template-columns: 18px minmax(0, 1fr);
					align-items: start;
					column-gap: 10px;
					row-gap: 2px;
				}
				.eem-event-details-card__fact-separator {
					display: none;
				}
				.eem-event-details-card__fact-icon {
					width: 18px;
					min-width: 18px;
					margin-top: 2px;
				}
				.eem-event-details-card__fact-icon svg {
					width: 16px;
					height: 16px;
				}
			}
			@media (max-width: 520px) {
				.eem-reservation-form-wrap {
					margin-left: 0;
					margin-right: 0;
				}
				.eem-reservation-event-media-card {
					padding: 14px;
					border-radius: 16px;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-payment-sidebar__inner {
					padding: 16px 14px;
					border-radius: 20px;
				}
				.eem-event-details-card__eyebrow,
				.eem-payment-summary-row,
				.eem-reservation-help,
				.eem-product-line-item__description {
					font-size: 13px;
				}
				.eem-reservation-form-wrap .eem-event-details-card__title {
					font-size: 20px !important;
				}
				.eem-event-details-card__facts {
					gap: 10px;
				}
				.eem-event-details-card__time-value {
					font-size: 17px;
				}
				.eem-product-line-item__title {
					font-size: 15px;
				}
				.eem-payment-summary-row--total {
					font-size: 15px;
				}
			}
			@media (max-width: 900px) {
				.eem-reservation-form-wrap {
					max-width: 100%;
					padding-left: 16px;
					padding-right: 16px;
					box-sizing: border-box;
				}
				.eem-reservation-form {
					gap: 20px;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-payment-sidebar__inner {
					border-radius: 22px;
				}
				.eem-reservation-form input,
				.eem-reservation-form select,
				.eem-reservation-form textarea,
				.eem-phone-field,
				.eem-stripe-card-element,
				.eem-stay-night-field__value,
				.eem-weekend-package-field,
				.eem-quantity-button,
				.eem-quantity-control input,
				.eem-reservation-submit,
				.eem-reservation-submit--secondary {
					font-size: 16px;
				}
				.eem-reservation-submit,
				.eem-reservation-submit--secondary {
					border-radius: 16px;
				}
				.eem-payment-summary {
					border-radius: 18px;
				}
			}
			@media (max-width: 600px) {
				.eem-reservation-form-wrap {
					padding-bottom: 20px;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-payment-sidebar__inner {
					padding: 16px 14px;
					border-radius: 20px;
				}
				.eem-reservation-form {
					gap: 18px;
				}
				.eem-reservation-section {
					padding: 16px 14px;
				}
				.eem-product-line-item {
					padding: 16px 0;
				}
				.eem-product-line-item__title {
					font-size: 15px;
					line-height: 1.4;
				}
				.eem-product-line-item__description,
				.eem-reservation-help,
				.eem-payment-summary-row {
					font-size: 14px;
					line-height: 1.55;
				}
				.eem-payment-summary-row strong {
					font-size: 15px;
				}
			}
			@media (max-width: 640px) {
				.eem-reservation-form-wrap {
					padding-left: 14px;
					padding-right: 14px;
					margin-left: 0 !important;
					margin-right: 0 !important;
					width: 100%;
					max-width: 100%;
					box-sizing: border-box;
					overflow-x: hidden;
				}
				.eem-reservation-section-heading {
					gap: 10px;
				}
				.eem-reservation-form-wrap,
				.eem-reservation-form,
				.eem-reservation-section,
				.eem-invoice-mode-card,
				.eem-invoice-mode-card__copy,
				.eem-invoice-mode-actions,
				.eem-reservation-submit-actions--invoice-mode,
				.eem-payment-layout,
				.eem-payment-main,
				.eem-payment-sidebar,
				.eem-payment-sidebar__inner,
				.eem-payment-summary,
				.eem-payment-summary-row,
				.eem-order-summary-card,
				.eem-card-field-wrap,
				.eem-payment-card-field-wrap,
				.eem-payment-checkout-block,
				.eem-payment-checkout-block--admin-invoice {
					width: 100%;
					max-width: 100%;
					min-width: 0;
					box-sizing: border-box;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-payment-sidebar__inner,
				.eem-order-summary-card,
				.eem-payment-checkout-block,
				.eem-payment-card-field-wrap {
					margin-left: 0 !important;
					margin-right: 0 !important;
					width: 100%;
					max-width: 100%;
					box-sizing: border-box;
					overflow: hidden;
				}
				.eem-invoice-mode-card {
					display: grid;
					grid-template-columns: 1fr;
					gap: 14px;
					overflow: hidden;
				}
				.eem-invoice-mode-card__copy {
					max-width: 100%;
				}
				.eem-invoice-mode-actions,
				.eem-reservation-submit-actions--invoice-mode {
					display: grid;
					grid-template-columns: 1fr;
					gap: 12px;
					margin-left: 0;
				}
				.eem-invoice-mode-toggle {
					display: inline-flex;
					align-items: center;
					justify-content: flex-start;
					justify-self: start;
					gap: 10px;
					white-space: normal;
					width: auto !important;
					max-width: 100%;
				}
				.eem-invoice-mode-toggle .eem-inline-toggle-control__label,
				.eem-invoice-mode-card__copy .eem-reservation-help,
				.eem-reservation-help,
				.eem-product-line-item__description {
					white-space: normal;
					word-break: normal;
					overflow-wrap: anywhere;
				}
				.eem-invoice-mode-actions {
					justify-items: start;
				}
				.eem-invoice-mode-actions .eem-inline-toggle-control__track {
					justify-self: start;
				}
				.eem-invoice-mode-actions .eem-inline-toggle-control,
				.eem-invoice-mode-actions [data-eem-invoice-action],
				.eem-reservation-submit,
				.eem-reservation-submit--secondary {
					width: 100%;
					max-width: 100%;
				}
				.eem-payment-summary-row {
					padding: 12px 14px;
				}
				.eem-payment-summary-row strong,
				.eem-payment-summary-row span {
					word-break: break-word;
					overflow-wrap: anywhere;
				}
				.eem-reservation-form textarea {
					width: 100%;
					max-width: 100%;
				}
				.eem-reservation-form input,
				.eem-reservation-form select,
				.eem-reservation-form textarea,
				.eem-phone-field,
				.eem-stripe-card-element,
				.eem-stay-night-field__value,
				.eem-weekend-package-field,
				.eem-quantity-control,
				.eem-quantity-control input {
					width: 100%;
					max-width: 100%;
					min-width: 0;
					box-sizing: border-box;
				}
			}
			@media (max-width: 480px) {
				.eem-reservation-form-wrap {
					padding-left: 12px;
					padding-right: 12px;
				}
				.eem-event-details-card,
				.eem-venue-map-card,
				.eem-invoice-mode-card,
				.eem-group-reservation-toggle,
				.eem-group-rider-card,
				.eem-payment-sidebar__inner,
				.eem-order-summary-card,
				.eem-payment-checkout-block,
				.eem-payment-card-field-wrap {
					padding: 14px 12px !important;
					border-radius: 16px !important;
				}
				.eem-reservation-form {
					gap: 16px;
				}
				.eem-reservation-section {
					padding: 14px 12px;
					gap: 10px;
				}
				.eem-reservation-form input,
				.eem-reservation-form select,
				.eem-reservation-form textarea,
				.eem-phone-field,
				.eem-stripe-card-element,
				.eem-stay-night-field__value,
				.eem-weekend-package-field,
				.eem-quantity-button,
				.eem-quantity-control input,
				.eem-reservation-submit,
				.eem-reservation-submit--secondary,
				.eem-invoice-mode-actions .eem-inline-toggle-control,
				.eem-invoice-mode-actions [data-eem-invoice-action] {
					min-height: 48px !important;
					border-radius: 12px !important;
					font-size: 16px !important;
				}
				.eem-reservation-form textarea {
					min-height: 128px;
				}
				.eem-invoice-mode-toggle {
					grid-template-columns: 1fr auto;
				}
				.eem-payment-summary-row,
				.eem-product-line-item__description,
				.eem-reservation-help {
					font-size: 13px;
					line-height: 1.5;
				}
				.eem-payment-summary-row {
					padding: 12px;
				}
			}
			/* Mobile-first app refresh for the reservation experience. */
			.eem-reservation-form-wrap {
				--eem-app-border: #e5e7eb;
				--eem-app-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 10px 24px rgba(15, 23, 42, 0.04);
				--eem-app-shadow-soft: 0 1px 2px rgba(15, 23, 42, 0.03), 0 6px 18px rgba(15, 23, 42, 0.03);
				--eem-app-surface: #ffffff;
				--eem-app-surface-muted: #f8fafc;
				--eem-app-card-radius: 8px;
				--eem-app-control-radius: 8px;
				padding-left: 16px;
				padding-right: 16px;
				border-radius: 0;
				background: transparent;
				box-sizing: border-box;
			}
			.eem-reservation-form {
				gap: 16px;
			}
			.eem-event-details-card,
			.eem-venue-map-card,
			.eem-group-reservation-toggle,
			.eem-group-rider-card,
			.eem-invoice-mode-card,
			.eem-payment-sidebar__inner {
				border: 1px solid var(--eem-app-border);
				border-radius: 24px;
				background: var(--eem-app-surface);
				box-shadow: var(--eem-app-shadow);
			}
			.eem-reservation-section {
				gap: 12px;
				padding: 18px 16px;
				border: 1px solid var(--eem-app-border);
				border-radius: 22px;
				background: #ffffff;
				box-shadow: var(--eem-app-shadow-soft);
			}
			.eem-reservation-form-wrap .eem-event-details-card__title {
				letter-spacing: -0.02em;
			}
			.eem-event-details-card__eyebrow {
				display: inline-flex;
				align-items: center;
				width: fit-content;
				padding: 6px 10px;
				border-radius: 999px;
				background: #eef2f6;
				color: #5f6b7a;
			}
			.eem-product-list__head {
				display: none;
			}
			.eem-product-line-item,
			.eem-product-line-item--checkbox {
				grid-template-columns: 1fr;
				gap: 14px;
				align-items: start;
			}
			.eem-product-line-item__qty,
			.eem-product-line-item--checkbox .eem-product-line-item__qty {
				justify-content: flex-start;
			}
			.eem-payment-layout,
			.eem-reservation-grid,
			.eem-reservation-grid--two,
			.eem-reservation-grid--three,
			.eem-reservation-grid--stay-controls,
			.eem-payment-card-grid,
			.eem-event-details-card__facts,
			.eem-event-details-card__times {
				grid-template-columns: 1fr;
			}
			.eem-reservation-form input,
			.eem-reservation-form select,
			.eem-reservation-form textarea,
			.eem-phone-field,
			.eem-stripe-card-element,
			.eem-stay-night-field__value,
			.eem-weekend-package-field,
			.eem-reservation-submit,
			.eem-reservation-submit--secondary {
				min-height: 50px;
				border-radius: 14px;
			}
			.eem-reservation-submit,
			.eem-reservation-submit--secondary,
			.eem-invoice-mode-actions .eem-inline-toggle-control,
			.eem-invoice-mode-actions [data-eem-invoice-action] {
				width: 100%;
			}
			.eem-payment-summary {
				border-radius: 18px;
			}
			.eem-payment-summary-row {
				flex-direction: column;
				align-items: flex-start;
				gap: 6px;
			}
			.eem-reservation-form,
			.eem-reservation-section,
			.eem-reservation-grid,
			.eem-reservation-grid--two,
			.eem-reservation-grid--three,
			.eem-reservation-grid--stay-controls,
			.eem-payment-card-grid,
			.eem-event-details-card__facts,
			.eem-event-details-card__times,
			.eem-invoice-mode-card,
			.eem-invoice-mode-actions,
			.eem-payment-layout,
			.eem-payment-main,
			.eem-payment-sidebar,
			.eem-payment-sidebar__inner {
				min-width: 0;
				box-sizing: border-box;
			}
			@media (max-width: 640px) {
				.eem-reservation-grid,
				.eem-reservation-grid--two,
				.eem-reservation-grid--three,
				.eem-reservation-grid--stay-controls,
				.eem-payment-card-grid,
				.eem-event-details-card__facts,
				.eem-event-details-card__times,
				.eem-payment-layout {
					grid-template-columns: 1fr !important;
				}
				.eem-reservation-form input,
				.eem-reservation-form select,
				.eem-reservation-form textarea,
				.eem-phone-field,
				.eem-stripe-card-element,
				.eem-stay-night-field__value,
				.eem-weekend-package-field,
				.eem-quantity-control,
				.eem-quantity-control input,
				.eem-reservation-submit,
				.eem-reservation-submit--secondary {
					width: 100%;
					max-width: 100%;
					min-width: 0;
					box-sizing: border-box;
				}
			}
			@media (min-width: 760px) {
				.eem-reservation-form-wrap {
					padding: 0;
				}
				.eem-reservation-grid--two,
				.eem-payment-card-grid,
				.eem-event-details-card__times,
				.eem-event-details-card__facts {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
				.eem-product-list__head {
					display: grid;
				}
				.eem-product-line-item {
					grid-template-columns: minmax(0, 1fr) auto;
					align-items: center;
				}
				.eem-product-line-item--checkbox {
					grid-template-columns: minmax(0, 1fr) auto;
					align-items: center;
				}
				.eem-product-line-item__qty,
				.eem-product-line-item--checkbox .eem-product-line-item__qty {
					justify-content: flex-end;
				}
				.eem-payment-summary-row {
					flex-direction: row;
					align-items: center;
				}
			}
			@media (min-width: 1040px) {
				.eem-reservation-form-wrap {
					max-width: 1320px;
					padding: 0;
				}
				.eem-reservation-workspace {
					display: grid;
					grid-template-columns: minmax(0, 1.45fr) minmax(360px, 1fr);
					column-gap: var(--eem-app-card-gap);
					row-gap: var(--eem-app-card-gap);
					align-items: start;
				}
				.eem-reservation-workspace__main {
					display: grid;
					gap: var(--eem-app-card-gap);
					min-width: 0;
				}
				.eem-reservation-workspace__rail {
					display: block;
					width: 100%;
					min-width: 0;
					justify-self: stretch;
					align-self: start;
					position: sticky;
					top: 24px;
				}
				.eem-reservation-summary-card {
					width: 100%;
					max-width: none;
				}
				.eem-reservation-summary-card__sticky {
					position: static;
					top: auto;
					display: grid;
					gap: 14px;
					width: 100%;
				}
				.eem-payment-layout {
					grid-template-columns: minmax(0, 1.2fr) minmax(300px, 0.82fr);
				}
				.eem-reservation-grid--three {
					grid-template-columns: repeat(3, minmax(0, 1fr));
				}
				.eem-reservation-grid--stay-controls {
					grid-template-columns: 0.72fr 1fr 1fr 0.72fr;
				}
			}
			.eem-reservation-workspace {
				display: grid;
				gap: var(--eem-app-card-gap);
			}
			.eem-reservation-workspace__main {
				display: grid;
				gap: var(--eem-app-card-gap);
			}
			.eem-reservation-workspace__main,
			.eem-reservation-workspace__rail {
				min-width: 0;
			}
			.eem-reservation-summary-card {
				width: 100%;
				min-width: 0;
			}
			.eem-reservation-summary-card__sticky {
				width: 100%;
				box-sizing: border-box;
				display: grid;
				gap: var(--eem-app-card-gap);
				padding: 20px;
				border: 1px solid var(--eem-app-border);
				border-radius: var(--eem-app-card-radius);
				background: #ffffff;
				box-shadow: var(--eem-app-shadow-soft);
			}
			.eem-reservation-summary-card .eem-payment-summary {
				border: 1px solid #dbe4f0;
				border-radius: var(--eem-app-card-radius);
				overflow: hidden;
				background: #ffffff;
			}
			.eem-reservation-summary-card .eem-payment-summary-row {
				padding: 14px 16px;
			}
			.eem-reservation-section--payment {
				padding: 22px;
				border: 1px solid var(--eem-app-border);
				border-radius: var(--eem-app-card-radius);
				background: #ffffff;
				box-shadow: var(--eem-app-shadow);
			}
			.eem-reservation-section--payment .eem-payment-checkout-block,
			.eem-reservation-section--payment .eem-payment-card-field-wrap {
				padding: 18px;
				border: 1px solid #dbe4f0;
				border-radius: var(--eem-app-card-radius);
				background: #f8fbff;
			}
			.eem-reservation-section--payment .eem-payment-card-field-wrap {
				margin-top: 16px;
			}
			.eem-reservation-section--payment .eem-reservation-submit {
				margin-top: 16px;
			}
			.eem-reservation-section--instructions {
				box-shadow: var(--eem-app-shadow-soft);
			}
			@media (max-width: 1039px) {
				.eem-reservation-workspace__rail {
					position: static;
					top: auto;
				}
				.eem-reservation-summary-card__sticky {
					position: static;
				}
			}
			@media (max-width: 640px) {
				.eem-reservation-section--payment {
					padding: 18px 16px;
					border-radius: var(--eem-app-card-radius);
				}
				.eem-reservation-section--payment .eem-payment-checkout-block,
				.eem-reservation-section--payment .eem-payment-card-field-wrap {
					padding: 16px 14px;
					border-radius: var(--eem-app-card-radius);
				}
				.eem-reservation-summary-card__sticky {
					padding: 16px 14px;
					border-radius: var(--eem-app-card-radius);
				}
			}
			/* Final frontend card polish */
			.eem-reservation-event-media-card,
			.eem-reservation-event-media-card__visual,
			.eem-event-details-card,
			.eem-venue-map-card,
			.eem-reservation-section,
			.eem-reservation-section--instructions,
			.eem-reservation-section--payment,
			.eem-reservation-summary-card,
			.eem-group-reservation-toggle,
			.eem-group-rider-card,
			.eem-invoice-mode-card,
			.eem-payment-sidebar__inner,
			.eem-reservation-summary-card__sticky,
			.eem-reservation-summary-card .eem-payment-summary,
			.eem-reservation-section--payment .eem-payment-checkout-block,
			.eem-reservation-section--payment .eem-payment-card-field-wrap,
			.eem-event-details-card__time-card,
			.eem-rv-lot-selector-row__details,
			.eem-stall-assignment-selector,
			.eem-venue-agreement-card {
				border-radius: var(--eem-app-card-radius) !important;
			}
			.eem-reservation-event-media-card,
			.eem-event-details-card,
			.eem-venue-map-card,
			.eem-reservation-section,
			.eem-reservation-section--payment,
			.eem-group-rider-card,
			.eem-invoice-mode-card,
			.eem-payment-sidebar__inner,
			.eem-reservation-summary-card__sticky,
			.eem-reservation-section--payment .eem-payment-checkout-block,
			.eem-reservation-section--payment .eem-payment-card-field-wrap {
				border-color: var(--eem-app-border);
				background: var(--eem-app-surface);
				box-shadow: var(--eem-app-shadow-soft);
			}
			.eem-reservation-event-media-card {
				background: var(--eem-app-surface);
				box-shadow: var(--eem-app-shadow-soft);
			}
			.eem-reservation-event-media-card__visual,
			.eem-reservation-section--instructions,
			.eem-group-reservation-toggle,
			.eem-event-details-card__time-card,
			.eem-rv-lot-selector-row__details,
			.eem-stall-assignment-selector,
			.eem-reservation-summary-card .eem-payment-summary,
			.eem-payment-summary-row--total {
				border-color: var(--eem-app-border);
				background: var(--eem-app-surface-muted);
				box-shadow: none;
			}
			.eem-payment-summary-row {
				border-bottom-color: var(--eem-app-border);
			}
			.eem-reservation-form input,
			.eem-reservation-form select,
			.eem-reservation-form textarea,
			.eem-phone-field,
			.eem-stripe-card-element,
			.eem-stay-night-field__value,
			.eem-weekend-package-field,
			.eem-reservation-submit,
			.eem-reservation-submit--secondary {
				border-radius: var(--eem-app-control-radius);
			}
			/* Final strict frontend card radius layer. */
			.eem-reservation-event-media-card,
			.eem-reservation-event-media-card__visual,
			.eem-event-details-card,
			.eem-venue-map-card,
			.eem-reservation-section,
			.eem-reservation-section--instructions,
			.eem-reservation-section--payment,
			.eem-reservation-summary-card,
			.eem-reservation-summary-card__sticky,
			.eem-reservation-summary-card .eem-payment-summary,
			.eem-payment-summary,
			.eem-group-reservation-toggle,
			.eem-group-rider-card,
			.eem-invoice-mode-card,
			.eem-payment-sidebar__inner,
			.eem-reservation-section--payment .eem-payment-checkout-block,
			.eem-reservation-section--payment .eem-payment-card-field-wrap,
			.eem-event-details-card__time-card,
			.eem-rv-lot-selector-row__details,
			.eem-stall-assignment-selector,
			.eem-venue-agreement-card {
				border-radius: var(--eem-app-card-radius) !important;
			}
			.eem-event-details-card__eyebrow {
				background: #f3f4f6;
				color: #6b7280;
			}
			.eem-venue-agreement-card {
				display: grid;
				gap: 8px;
				padding: 12px 14px;
				border: 1px solid #f3d27a;
				background: #fff7db;
				box-shadow: none;
				color: #7c5a00;
			}
			.eem-venue-agreement-card p {
				margin: 0;
				font-size: 14px;
				line-height: 1.55;
				color: inherit;
			}
			.eem-venue-agreement-card a {
				color: #8b5e00;
				font-weight: 700;
				text-decoration: underline;
			}
			.eem-venue-agreement-card a:hover,
			.eem-venue-agreement-card a:focus {
				color: #6f4b00;
			}
			@media (min-width: 1040px) {
				.eem-reservation-summary-card__sticky {
					padding: 20px;
					box-shadow: var(--eem-app-shadow);
				}
			}
		</style>
		<script>
			var enStripeForms = new WeakMap();
			var enStripeLoaderPromise = null;

			document.addEventListener('click', function(event) {
				var button = event.target.closest('.eem-quantity-button');
				var input;
				var nextValue;

				if (!button) {
					return;
				}

				input = button.parentNode.querySelector('input[type="number"]');

				if (!input) {
					return;
				}

				nextValue = parseInt(input.value || '0', 10) + parseInt(button.getAttribute('data-eem-quantity-step') || '0', 10);
				nextValue = Math.max(0, nextValue);

				if (input.hasAttribute('max')) {
					nextValue = Math.min(nextValue, parseInt(input.getAttribute('max') || '0', 10));
				}

				input.value = nextValue;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			});

			document.addEventListener('input', function(event) {
				var form = event.target.closest('.eem-reservation-form');

				if (form) {
					updateReservationTotals(form);
				}
			});

			document.addEventListener('change', function(event) {
				var form = event.target.closest('.eem-reservation-form');

				if (form) {
					resetStripePaymentState(form);
					syncSectionStayDateFields(form, 'stall', event.target);
					syncSectionStayDateFields(form, 'rv', event.target);
					normalizePhoneField(form);
					updateProductPricing(form);
					updateReservationTotals(form);
				}
			});

			document.addEventListener('blur', function(event) {
				var form = event.target.closest('.eem-reservation-form');

				if (!form) {
					return;
				}

				if (event.target.name === 'phone') {
					normalizePhoneField(form);
				}
			}, true);

			initializeReservationForms(document);
			document.addEventListener('DOMContentLoaded', function() {
				initializeReservationForms(document);
			});
			window.addEventListener('load', function() {
				initializeReservationForms(document);
			});

			function loadStripeLibrary() {
				var existingScript;

				if (typeof Stripe !== 'undefined') {
					return Promise.resolve();
				}

				if (enStripeLoaderPromise) {
					return enStripeLoaderPromise;
				}

				enStripeLoaderPromise = new Promise(function(resolve, reject) {
					existingScript = document.querySelector('script[data-eem-stripe-js]');

					if (existingScript) {
						existingScript.addEventListener('load', function() {
							resolve();
						}, { once: true });
						existingScript.addEventListener('error', function() {
							reject(new Error('Stripe.js could not be loaded.'));
						}, { once: true });
						return;
					}

					existingScript = document.createElement('script');
					existingScript.src = 'https://js.stripe.com/v3/';
					existingScript.async = true;
					existingScript.defer = true;
					existingScript.dataset.enStripeJs = '1';
					existingScript.onload = function() {
						resolve();
					};
					existingScript.onerror = function() {
						reject(new Error('Stripe.js could not be loaded.'));
					};
					document.head.appendChild(existingScript);
				});

				return enStripeLoaderPromise;
			}

			function initializeReservationForms(root) {
				(root || document).querySelectorAll('.eem-reservation-form').forEach(function(form) {
					initializeInvoiceActionButtons(form);
					initializeInvoiceBillingToggle(form);
					initializeStripeCardField(form);
					bindStripeSubmitHandler(form);
					initializeCollapsibleReservationSections(form);
					initializeGroupReservationFields(form);
					initializeStallAssignmentSelector(form);
					normalizePhoneField(form);
					syncReservationSectionToggles(form);
					syncSectionStayDateFields(form, 'stall');
					syncSectionStayDateFields(form, 'rv');
					syncRvAddonAvailability(form);
					syncGeneralAddonAvailability(form);
					updateProductPricing(form);
					updateReservationTotals(form);
				});
			}

			function initializeInvoiceActionButtons(form) {
				var actionField = form.querySelector('[name="en_invoice_action_mode"]');

				if (!actionField || actionField.dataset.enInvoiceActionReady === '1') {
					return;
				}

				actionField.dataset.enInvoiceActionReady = '1';

				form.querySelectorAll('[data-eem-invoice-action]').forEach(function(button) {
					button.addEventListener('click', function() {
						actionField.value = button.getAttribute('data-eem-invoice-action') || 'charge_now';
					});
				});
			}

			function initializeInvoiceBillingToggle(form) {
				var toggle = form.querySelector('[data-eem-invoice-billing-toggle]');
				var actionField = form.querySelector('[name="en_invoice_action_mode"]');
				var sendButton = form.querySelector('[data-eem-invoice-send-button]');
				var chargeButton = form.querySelector('[data-eem-invoice-charge-button]');
				var billingBlocks = form.querySelectorAll('[data-eem-invoice-billing-block]');
				var managedFields;

				if (!toggle || toggle.dataset.enInvoiceBillingReady === '1') {
					return;
				}

				toggle.dataset.enInvoiceBillingReady = '1';
				managedFields = form.querySelectorAll('[data-eem-required-for-charge="1"], input[name="authorize_card_number"], input[name="authorize_card_code"], select[name="authorize_exp_month"], select[name="authorize_exp_year"]');

				function syncInvoiceBillingState() {
					var chargingNow = !!toggle.checked;

					if (actionField) {
						actionField.value = chargingNow ? 'charge_now' : 'send_payment_link';
					}

					if (sendButton) {
						sendButton.hidden = chargingNow;
					}

					if (chargeButton) {
						chargeButton.hidden = !chargingNow;
					}

					billingBlocks.forEach(function(block) {
						block.hidden = !chargingNow;
						block.style.display = chargingNow ? '' : 'none';
					});

					managedFields.forEach(function(field) {
						if (!field.dataset.enOriginalRequired) {
							field.dataset.enOriginalRequired = field.required ? '1' : '0';
						}

						field.required = chargingNow && field.dataset.enOriginalRequired === '1';
						field.disabled = !chargingNow;
					});
				}

				toggle.addEventListener('change', syncInvoiceBillingState);
				syncInvoiceBillingState();
			}

			function getInvoiceActionMode(form) {
				var actionField = form.querySelector('[name="en_invoice_action_mode"]');

				return actionField ? String(actionField.value || '') : '';
			}

			function initializeCollapsibleReservationSections(form) {
				form.querySelectorAll('[data-eem-section-collapse-toggle]').forEach(function(toggle) {
					var section = toggle.closest('.eem-reservation-section');
					var body = section ? section.querySelector('[data-eem-section-collapse-body]') : null;

					if (!section || !body || toggle.dataset.enCollapseReady === '1') {
						return;
					}

					toggle.dataset.enCollapseReady = '1';
					setReservationSectionCollapsed(section, !toggle.checked);

					toggle.addEventListener('change', function() {
						setReservationSectionCollapsed(section, !toggle.checked);
					});
				});
			}

			function setReservationSectionCollapsed(section, collapsed) {
				var toggle = section.querySelector('[data-eem-section-collapse-toggle]');
				var body = section.querySelector('[data-eem-section-collapse-body]');
				var title = section.querySelector('.eem-reservation-section__title');
				var label = title ? title.textContent.replace(/\s+/g, ' ').trim() : 'Reservation';

				if (!toggle || !body) {
					return;
				}

				section.classList.toggle('eem-reservation-section--collapsed', !!collapsed);
				body.hidden = !!collapsed;
				toggle.checked = !collapsed;
				toggle.setAttribute('aria-label', (collapsed ? 'Open ' : 'Collapse ') + label + ' section');
			}

			function initializeGroupReservationFields(form) {
				var toggle = form.querySelector('[data-eem-group-toggle]');
				var fields = form.querySelector('[data-eem-group-fields]');
				var countInput = form.querySelector('[data-eem-group-count]');
				var list = form.querySelector('[data-eem-group-riders-list]');

				if (!toggle || !fields || !countInput || !list || toggle.dataset.enGroupReady === '1') {
					return;
				}

				toggle.dataset.enGroupReady = '1';

				function renderRiderFields() {
					var count = parseInt(countInput.value || '0', 10);
					var existingValues = [];

					count = Math.max(1, count || 1);
					countInput.value = count;

					list.querySelectorAll('.eem-group-rider-card').forEach(function(card) {
						existingValues.push({
							first_name: (card.querySelector('[data-eem-group-first-name]') || {}).value || '',
							last_name: (card.querySelector('[data-eem-group-last-name]') || {}).value || ''
						});
					});

					list.innerHTML = '';

					for (var index = 0; index < count; index += 1) {
						var rider = existingValues[index] || { first_name: '', last_name: '' };
						var card = document.createElement('div');
						card.className = 'eem-group-rider-card';
						card.innerHTML =
							'<h4>Rider ' + (index + 1) + '</h4>' +
							'<div class=\"eem-reservation-grid eem-reservation-grid--two\">' +
								'<label><span>First Name <strong>*</strong></span><input type=\"text\" name=\"group_riders[' + index + '][first_name]\" value=\"' + escapeHtml(rider.first_name) + '\" data-eem-group-first-name /></label>' +
								'<label><span>Last Name <strong>*</strong></span><input type=\"text\" name=\"group_riders[' + index + '][last_name]\" value=\"' + escapeHtml(rider.last_name) + '\" data-eem-group-last-name /></label>' +
							'</div>';
						list.appendChild(card);
					}
				}

				function syncGroupState() {
					var enabled = !!toggle.checked;
					fields.hidden = !enabled;
					countInput.disabled = !enabled;

					if (enabled) {
						renderRiderFields();
					} else {
						list.innerHTML = '';
					}
				}

				toggle.addEventListener('change', syncGroupState);
				countInput.addEventListener('change', renderRiderFields);
				countInput.addEventListener('input', renderRiderFields);
				syncGroupState();
			}

			function initializeStallAssignmentSelector(form) {
				var selector = form.querySelector('.eem-stall-assignment-selector');
				var toggle;
				var panel;
				var barnSelect;

				if (!selector || selector.dataset.enStallAssignmentReady === '1') {
					return;
				}

				toggle = selector.querySelector('[data-eem-stall-assignment-toggle]');
				panel = selector.querySelector('[data-eem-stall-assignment-panel]');
				barnSelect = selector.querySelector('[data-eem-stall-barn-select]');

				if (!toggle || !panel || !barnSelect) {
					return;
				}

				selector.dataset.enStallAssignmentReady = '1';

				function syncStallAssignmentState() {
					var isEnabled = !!toggle.checked;

					panel.hidden = !isEnabled;
					selector.classList.toggle('eem-stall-assignment-selector--collapsed', !isEnabled);

					if (!isEnabled) {
						selector.querySelectorAll('input[type="checkbox"][name="preferred_stall_units[]"]').forEach(function(input) {
							input.checked = false;
						});
					}

					syncStallAssignmentAvailability(form);
				}

				toggle.addEventListener('change', syncStallAssignmentState);
				barnSelect.addEventListener('change', function() {
					syncStallAssignmentAvailability(form);
				});

				[
					'[name="stall_arrival_date"]',
					'[name="stall_departure_date"]',
					'[name="stall_qty"]'
				].forEach(function(selectorQuery) {
					var field = form.querySelector(selectorQuery);

					if (!field) {
						return;
					}

					field.addEventListener('change', function() {
						syncStallAssignmentAvailability(form);
					});
					field.addEventListener('input', function() {
						syncStallAssignmentAvailability(form);
					});
				});

				selector.querySelectorAll('input[type="checkbox"][name="preferred_stall_units[]"]').forEach(function(input) {
					input.addEventListener('change', function() {
						syncStallAssignmentAvailability(form);
					});
				});

				syncStallAssignmentState();
			}

			function syncStallAssignmentAvailability(form) {
				var selector = form.querySelector('.eem-stall-assignment-selector');
				var toggle;
				var panel;
				var barnSelect;
				var config;
				var blockedUnits;
				var occupiedMap;
				var requestedDates;
				var stallQty;
				var selectedUnits;
				var selectedBarn;

				if (!selector) {
					return;
				}

				toggle = selector.querySelector('[data-eem-stall-assignment-toggle]');
				panel = selector.querySelector('[data-eem-stall-assignment-panel]');
				barnSelect = selector.querySelector('[data-eem-stall-barn-select]');
				config = parseJsonAttribute(form.dataset.stallAssignmentConfig);
				blockedUnits = Array.isArray(config.blocked_units) ? config.blocked_units : [];
				occupiedMap = config.occupied && typeof config.occupied === 'object' ? config.occupied : {};
				requestedDates = getAssignmentDateKeysForFrontend(getFieldValue(form, 'stall_arrival_date'), getFieldValue(form, 'stall_departure_date'));
				stallQty = Math.max(0, getNumberFieldValue(form, 'stall_qty'));
				selectedUnits = [];
				selectedBarn = barnSelect ? String(barnSelect.value || '') : '';

				selector.querySelectorAll('input[type="checkbox"][name="preferred_stall_units[]"]:checked').forEach(function(input) {
					selectedUnits.push(String(input.value || ''));
				});

				selector.querySelectorAll('[data-eem-stall-barn-group]').forEach(function(group) {
					var groupBarn = group.getAttribute('data-stall-barn') || '';
					var shouldHideGroup = !!selectedBarn && groupBarn !== selectedBarn;

					group.hidden = shouldHideGroup;
					group.classList.toggle('is-hidden', shouldHideGroup);
				});

				selector.querySelectorAll('[data-eem-stall-unit]').forEach(function(unitLabel) {
					var input = unitLabel.querySelector('input[type="checkbox"]');
					var unit = unitLabel.getAttribute('data-stall-unit') || '';
					var unitBarn = unitLabel.getAttribute('data-stall-barn') || '';
					var isBlocked = blockedUnits.indexOf(unit) !== -1;
					var isReserved = !isBlocked && stallUnitHasDateOverlap(occupiedMap[unit], requestedDates);
					var status = isBlocked ? 'blocked' : (isReserved ? 'reserved' : 'available');
					var isSelected = !!(input && input.checked);
					var atSelectionLimit = stallQty > 0 && selectedUnits.length >= stallQty;
					var isInSelectedBarn = !selectedBarn || unitBarn === selectedBarn;
					var shouldDisable = !toggle.checked || !panel || panel.hidden || !isInSelectedBarn || status !== 'available' || (atSelectionLimit && !isSelected);

					if (input && (status !== 'available' || !isInSelectedBarn) && input.checked) {
						input.checked = false;
						isSelected = false;
					}

					if (input) {
						input.disabled = shouldDisable;
					}

					unitLabel.hidden = !isInSelectedBarn;
					unitLabel.dataset.status = status;
					unitLabel.dataset.disabled = shouldDisable ? 'true' : 'false';
					unitLabel.setAttribute('aria-disabled', shouldDisable ? 'true' : 'false');
				});
			}

			function getAssignmentDateKeysForFrontend(arrivalDate, departureDate) {
				var arrivalParts = String(arrivalDate || '').split('-');
				var departureParts = String(departureDate || '').split('-');
				var currentUtc;
				var endUtc;
				var keys = [];

				if (arrivalParts.length !== 3 || departureParts.length !== 3) {
					return keys;
				}

				currentUtc = Date.UTC(parseInt(arrivalParts[0], 10), parseInt(arrivalParts[1], 10) - 1, parseInt(arrivalParts[2], 10));
				endUtc = Date.UTC(parseInt(departureParts[0], 10), parseInt(departureParts[1], 10) - 1, parseInt(departureParts[2], 10));

				if (isNaN(currentUtc) || isNaN(endUtc) || endUtc <= currentUtc) {
					return keys;
				}

				while (currentUtc < endUtc) {
					keys.push(new Date(currentUtc).toISOString().slice(0, 10));
					currentUtc += 86400000;
				}

				return keys;
			}

			function stallUnitHasDateOverlap(occupiedDates, requestedDates) {
				if (!Array.isArray(occupiedDates) || !occupiedDates.length || !Array.isArray(requestedDates) || !requestedDates.length) {
					return false;
				}

				return requestedDates.some(function(dateKey) {
					return occupiedDates.indexOf(dateKey) !== -1;
				});
			}

			function escapeHtml(value) {
				return String(value || '')
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/\"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

			function initializeStripeCardField(form) {
				var stripeKey = form.dataset.stripePublishableKey || '';
				var cardNumberTarget = form.querySelector('[data-eem-stripe-card-number]');
				var cardExpiryTarget = form.querySelector('[data-eem-stripe-card-expiry]');
				var cardCvcTarget = form.querySelector('[data-eem-stripe-card-cvc]');
				var errorTarget = form.querySelector('[data-eem-stripe-card-error]');
				var initAttempts = parseInt(form.dataset.stripeInitAttempts || '0', 10) || 0;
				var stripe;
				var elements;
				var style;
				var cardNumber;
				var cardExpiry;
				var cardCvc;
				var handleElementChange;

				if (enStripeForms.has(form)) {
					return;
				}

				if (form.dataset.stripeEnabled !== '1' || !stripeKey || !cardNumberTarget || !cardExpiryTarget || !cardCvcTarget) {
					return;
				}

				if (typeof Stripe === 'undefined') {
					if (initAttempts >= 6) {
						setStripeError(form, 'Secure payment fields could not load. Please refresh the page and try again.');
						return;
					}

					form.dataset.stripeInitAttempts = String(initAttempts + 1);
					loadStripeLibrary()
						.then(function() {
							window.setTimeout(function() {
								initializeStripeCardField(form);
							}, 50);
						})
						.catch(function(error) {
							setStripeError(form, error && error.message ? error.message : 'Secure payment fields could not load. Please refresh the page and try again.');
						});

					return;
				}

				stripe = Stripe(stripeKey);
				elements = stripe.elements();
				style = {
					base: {
						color: '#0f172a',
						fontFamily: 'inherit',
						fontSize: '16px',
						fontSmoothing: 'antialiased',
						'::placeholder': {
							color: '#94a3b8'
						}
					},
					invalid: {
						color: '#b91c1c',
						iconColor: '#b91c1c'
					}
				};
				cardNumber = elements.create('cardNumber', {
					style: style
				});
				cardExpiry = elements.create('cardExpiry', {
					style: style
				});
				cardCvc = elements.create('cardCvc', {
					style: style
				});
				handleElementChange = function(event) {
					if (!errorTarget) {
						return;
					}

					if (event.error && event.error.message) {
						errorTarget.textContent = event.error.message;
						errorTarget.hidden = false;
					} else {
						errorTarget.textContent = '';
						errorTarget.hidden = true;
					}
				};

				try {
					cardNumber.mount(cardNumberTarget);
					cardExpiry.mount(cardExpiryTarget);
					cardCvc.mount(cardCvcTarget);
				} catch (error) {
					setStripeError(form, error && error.message ? error.message : 'Secure payment fields could not load. Please refresh the page and try again.');
					return;
				}
				cardNumber.on('change', handleElementChange);
				cardExpiry.on('change', handleElementChange);
				cardCvc.on('change', handleElementChange);
				cardNumberTarget.addEventListener('click', function() {
					cardNumber.focus();
				});
				cardExpiryTarget.addEventListener('click', function() {
					cardExpiry.focus();
				});
				cardCvcTarget.addEventListener('click', function() {
					cardCvc.focus();
				});

				enStripeForms.set(form, {
					stripe: stripe,
					cardNumber: cardNumber,
					cardExpiry: cardExpiry,
					cardCvc: cardCvc,
					isSubmitting: false
				});

				window.setTimeout(function() {
					var iframeCount = form.querySelectorAll('.eem-stripe-card-element iframe').length;

					if (!iframeCount) {
						setStripeError(form, 'Secure payment fields could not load. Please refresh the page and try again.');
					}
				}, 800);
			}

			function bindStripeSubmitHandler(form) {
				form.addEventListener('submit', function(event) {
					var stripeState = enStripeForms.get(form);
					var total;
					var invoiceActionMode = getInvoiceActionMode(form);

					if (!stripeState || form.dataset.paymentGateway !== 'stripe' || form.dataset.stripeEnabled !== '1') {
						return;
					}

					if (invoiceActionMode === 'send_payment_link') {
						return;
					}

					total = parseCurrency(getTotalText(form, 'total'));

					if (total <= 0) {
						return;
					}

					if (stripeState.isSubmitting) {
						event.preventDefault();
						return;
					}

					if (getFieldValue(form, 'stripe_payment_intent_id')) {
						return;
					}

					event.preventDefault();
					stripeState.isSubmitting = true;
					setSubmitDisabled(form, true);
					setStripeError(form, '');

					createStripePaymentIntent(form)
						.then(function(intent) {
							return stripeState.stripe.confirmCardPayment(intent.client_secret, {
								payment_method: {
									card: stripeState.cardNumber,
									billing_details: {
										name: [getFieldValue(form, 'billing_first_name'), getFieldValue(form, 'billing_last_name')].join(' ').trim() || [getFieldValue(form, 'first_name'), getFieldValue(form, 'last_name')].join(' ').trim(),
										email: getFieldValue(form, 'email'),
										phone: getFieldValue(form, 'phone'),
										address: {
											line1: getFieldValue(form, 'billing_address_1'),
											line2: getFieldValue(form, 'billing_address_2'),
											city: getFieldValue(form, 'billing_city'),
											state: getFieldValue(form, 'billing_state'),
											postal_code: getFieldValue(form, 'billing_postal_code'),
											country: normalizeCountryCode(getFieldValue(form, 'billing_country'))
										}
									}
								}
							});
						})
						.then(function(result) {
							if (result.error) {
								throw new Error(result.error.message || 'Stripe payment failed.');
							}

							if (!result.paymentIntent || result.paymentIntent.status !== 'succeeded') {
								throw new Error('Stripe payment did not complete successfully.');
							}

							form.querySelector('[name="stripe_payment_intent_id"]').value = result.paymentIntent.id;
							form.submit();
						})
						.catch(function(error) {
							setStripeError(form, error && error.message ? error.message : 'Stripe payment failed.');
							stripeState.isSubmitting = false;
							setSubmitDisabled(form, false);
						});
				});
			}

			function createStripePaymentIntent(form) {
				var formData = new FormData(form);

				formData.append('action', 'equine_event_manager_create_stripe_payment_intent');

				return fetch(form.dataset.stripeCreateIntentUrl || '', {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
					.then(function(response) {
						return response.json().then(function(payload) {
							if (!response.ok || !payload || !payload.success || !payload.data) {
								throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Unable to start Stripe payment.');
							}

							return payload.data;
						});
					});
			}

			function resetStripePaymentState(form) {
				var stripeState = enStripeForms.get(form);
				var intentField = form.querySelector('[name="stripe_payment_intent_id"]');

				if (intentField) {
					intentField.value = '';
				}

				if (stripeState) {
					stripeState.isSubmitting = false;
				}
			}

			function syncReservationSectionToggles(form) {
				form.querySelectorAll('[data-eem-section-toggle]').forEach(function(toggle) {
					var sectionKey = toggle.getAttribute('data-eem-section-toggle');
					var isEnabled = !!toggle.checked;

					var sections = form.querySelectorAll('[data-eem-section="' + sectionKey + '"]');

					if (!sections.length) {
						return;
					}

					sections.forEach(function(section) {
						section.hidden = !isEnabled;
						setSectionEnabledState(section, isEnabled);

						if (!isEnabled) {
							resetSectionInputs(section);
						}
					});
				});
			}

			function setSectionEnabledState(section, isEnabled) {
				section.querySelectorAll('input, select, textarea, button').forEach(function(field) {
					field.disabled = !isEnabled;
				});
			}

			function resetSectionInputs(section) {
				section.querySelectorAll('input[type="number"]').forEach(function(input) {
					input.value = '0';
				});

				section.querySelectorAll('input[type="checkbox"]').forEach(function(input) {
					input.checked = false;
				});
			}

			function syncRvAddonAvailability(form) {
				var rvQty = getNumberFieldValue(form, 'rv_qty');
				var hasRvSelection = rvQty > 0;

				form.querySelectorAll('.eem-product-line-item--rv-addon').forEach(function(lineItem) {
					var checkbox = lineItem.querySelector('input[type="checkbox"]');

					if (!checkbox) {
						return;
					}

					checkbox.disabled = !hasRvSelection;
					checkbox.setAttribute('aria-disabled', hasRvSelection ? 'false' : 'true');
					lineItem.classList.toggle('eem-product-line-item--disabled', !hasRvSelection);

					if (!hasRvSelection) {
						checkbox.checked = false;
					}
				});
			}

			function syncGeneralAddonAvailability(form) {
				var stallQty = getNumberFieldValue(form, 'stall_qty');
				var rvQty = getNumberFieldValue(form, 'rv_qty');

				form.querySelectorAll('.eem-product-line-item--general-addon').forEach(function(lineItem) {
					var appliesTo = lineItem.getAttribute('data-addon-applies-to') || 'any';
					var control = lineItem.querySelector('.eem-quantity-control');
					var input = control ? control.querySelector('input[type="number"]') : null;
					var buttons = control ? control.querySelectorAll('.eem-quantity-button') : [];
					var isEnabled = true;

					if (appliesTo === 'stall') {
						isEnabled = stallQty > 0;
					} else if (appliesTo === 'rv') {
						isEnabled = rvQty > 0;
					} else {
						isEnabled = stallQty > 0 || rvQty > 0;
					}

					lineItem.classList.toggle('eem-product-line-item--disabled', !isEnabled);

					if (input) {
						input.disabled = !isEnabled;

						if (!isEnabled) {
							input.value = '0';
						}
					}

					buttons.forEach(function(button) {
						button.disabled = !isEnabled;
					});
				});
			}

			function syncRvLotDetails(form) {
				var lotSelect = form.querySelector('[name="rv_lot"]');
				var summary = form.querySelector('[data-rv-lot-summary]');
				var title = form.querySelector('[data-rv-lot-summary-title]');
				var description = form.querySelector('[data-rv-lot-summary-description]');
				var rvQtyInput = form.querySelector('[name="rv_qty"]');
				var stayType = getFieldValue(form, 'rv_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				var matrix = parseJsonAttribute(form.dataset.rvLotPricing);
				var lotKey;
				var lotData;
				var rate;
				var rateLabel;
				var descriptionHtml = '';
				var inventory;
				var remaining;

				if (!lotSelect || !summary || !title || !description) {
					return;
				}

				lotKey = getFieldValue(form, 'rv_lot');
				lotData = matrix && matrix[lotKey] ? matrix[lotKey] : null;
				inventory = lotData && lotData.inventory ? lotData.inventory : null;
				remaining = inventory && inventory.remaining !== null && inventory.remaining !== undefined ? parseInt(inventory.remaining, 10) : null;

				if (!lotData) {
					title.textContent = '';
					description.textContent = '';
					title.hidden = true;
					description.hidden = true;
					summary.hidden = true;

					if (rvQtyInput) {
						rvQtyInput.removeAttribute('max');
					}
					return;
				}

				rate = parseCurrency(lotData[stayType]);
				rateLabel = stayType === 'weekend' ? 'Weekend Rate' : 'Nightly Rate';
				title.textContent = lotData.label || 'Selected RV lot';
				title.hidden = false;

				if (lotData.description) {
					descriptionHtml += escapeReservationHtml(lotData.description);
				}

				if (rate > 0) {
					if (descriptionHtml) {
						descriptionHtml += ' - ';
					}

					descriptionHtml += '<strong>' + escapeReservationHtml(rateLabel + ': ' + formatReservationMoney(rate)) + '</strong>';
				}

				if (inventory) {
					if (descriptionHtml) {
						descriptionHtml += '<br>';
					}

					if (inventory.sold_out) {
						descriptionHtml += '<strong>' + escapeReservationHtml('Sold Out') + '</strong>';
					} else if (remaining !== null && !isNaN(remaining)) {
						descriptionHtml += '<strong>' + escapeReservationHtml(remaining + ' space' + (remaining === 1 ? '' : 's') + ' remaining') + '</strong>';
					} else {
						descriptionHtml += '<strong>' + escapeReservationHtml('Unlimited availability') + '</strong>';
					}
				}

				description.innerHTML = descriptionHtml;
				description.hidden = !descriptionHtml;
				summary.hidden = false;

				if (rvQtyInput) {
					if (inventory && inventory.sold_out) {
						rvQtyInput.value = '0';
						rvQtyInput.setAttribute('max', '0');
					} else if (remaining !== null && !isNaN(remaining)) {
						rvQtyInput.setAttribute('max', String(Math.max(0, remaining)));

						if (parseInt(rvQtyInput.value || '0', 10) > remaining) {
							rvQtyInput.value = String(Math.max(0, remaining));
						}
					} else {
						rvQtyInput.removeAttribute('max');
					}
				}
			}

			function escapeReservationHtml(value) {
				return String(value || '')
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

			function updateProductPricing(form) {
				var stallStayType = getFieldValue(form, 'stall_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				var rvStayType = getFieldValue(form, 'rv_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				var rvAddonPricingMatrix = parseJsonAttribute(form.dataset.rvAddonPricing);
				var rvLotPricingMatrix = parseJsonAttribute(form.dataset.rvLotPricing);
				var selectedRvLot = getFieldValue(form, 'rv_lot');
				var stallRate = parseCurrency(form.dataset[stallStayType === 'weekend' ? 'stallWeekendRate' : 'stallNightlyRate']);
				var rvRate = parseCurrency(rvLotPricingMatrix && rvLotPricingMatrix[selectedRvLot] ? rvLotPricingMatrix[selectedRvLot][rvStayType] : form.dataset[rvStayType === 'weekend' ? 'rvWeekendRate' : 'rvNightlyRate']);

				form.querySelectorAll('[data-dynamic-price-label]').forEach(function(label) {
					var type = label.getAttribute('data-dynamic-price-label');
					var dynamicKey = label.getAttribute('data-dynamic-price-key');
					var baseLabel = label.getAttribute('data-product-base-label') || '';
					var price = stallRate;
					var stayType = stallStayType;
					var stayTypeLabel = stayType === 'weekend' ? 'weekend' : 'per night';
					var titleText = label.querySelector('.eem-product-line-item__title-text');

					if (type === 'rv') {
						price = rvRate;
						stayType = rvStayType;
						stayTypeLabel = stayType === 'weekend' ? 'each' : 'per night';
					} else if (type === 'rv_addon') {
						price = parseCurrency(rvAddonPricingMatrix && rvAddonPricingMatrix[dynamicKey] ? rvAddonPricingMatrix[dynamicKey][rvStayType] : 0);
						stayType = rvStayType;
						stayTypeLabel = '';
					}

					if (titleText) {
						titleText.textContent = baseLabel + ' - ' + formatReservationMoney(price) + (stayTypeLabel ? ' ' + stayTypeLabel : '');
					} else {
						label.textContent = baseLabel + ' - ' + formatReservationMoney(price) + (stayTypeLabel ? ' ' + stayTypeLabel : '');
					}
				});

				syncRvLotDetails(form);

				form.querySelectorAll('[data-static-price]').forEach(function(label) {
					var baseLabel = label.getAttribute('data-product-base-label') || '';
					var staticPrice = label.getAttribute('data-static-price') || '$0.00';
					var staticPriceSuffix = label.getAttribute('data-static-price-suffix') || '';
					var titleText = label.querySelector('.eem-product-line-item__title-text');

					if (titleText) {
						titleText.textContent = baseLabel + ' - ' + staticPrice + staticPriceSuffix;
					} else {
						label.textContent = baseLabel + ' - ' + staticPrice + staticPriceSuffix;
					}
				});
			}

			function updateReservationTotals(form) {
				syncReservationSectionToggles(form);
				syncRvAddonAvailability(form);
				syncGeneralAddonAvailability(form);
				var stallStayType = getFieldValue(form, 'stall_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				var rvStayType = getFieldValue(form, 'rv_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				var rvAddonPricingMatrix = parseJsonAttribute(form.dataset.rvAddonPricing);
				var generalAddonPricingMatrix = parseJsonAttribute(form.dataset.generalAddonPricing);
				var rvLotPricingMatrix = parseJsonAttribute(form.dataset.rvLotPricing);
				var stallRate = parseCurrency(form.dataset[stallStayType === 'weekend' ? 'stallWeekendRate' : 'stallNightlyRate']);
				var selectedRvLot = getFieldValue(form, 'rv_lot');
				var rvRate = parseCurrency(rvLotPricingMatrix && rvLotPricingMatrix[selectedRvLot] ? rvLotPricingMatrix[selectedRvLot][rvStayType] : form.dataset[rvStayType === 'weekend' ? 'rvWeekendRate' : 'rvNightlyRate']);
				var stallUnits = getBillableStayUnits(getFieldValue(form, 'stall_arrival_date'), getFieldValue(form, 'stall_departure_date'), stallStayType);
				var rvUnits = getBillableStayUnits(getFieldValue(form, 'rv_arrival_date'), getFieldValue(form, 'rv_departure_date'), rvStayType);
				var requiredShavingsPrice = parseCurrency(form.dataset.requiredShavingsPrice);
				var groupToggle = form.querySelector('[data-eem-group-toggle]');
				var groupEnabled = !!(groupToggle && groupToggle.checked);
				var groupRiderCount = groupEnabled ? Math.max(1, getNumberFieldValue(form, 'group_rider_count')) : 0;
				var groupGroundsFeeEnabled = form.dataset.groupGroundsFeeEnabled === '1';
				var groupGroundsFeeAmount = parseCurrency(form.dataset.groupGroundsFeeAmount);
				var groupDepositEnabled = form.dataset.groupDepositEnabled === '1';
				var groupDepositAmount = parseCurrency(form.dataset.groupDepositAmount);
				var feeType = form.dataset.feeType || 'none';
				var feeValue = parseCurrency(form.dataset.feeValue);
				var stallQty = getNumberFieldValue(form, 'stall_qty');
				var rvQty = getNumberFieldValue(form, 'rv_qty');
				var rvAddonSubtotals = {};
				var generalAddonSubtotals = {};
				var groupGroundsFeeSubtotal = groupEnabled && groupGroundsFeeEnabled ? groupRiderCount * groupGroundsFeeAmount : 0;
				var groupDepositSubtotal = groupEnabled && groupDepositEnabled ? groupRiderCount * groupDepositAmount : 0;
				var groupSubtotal = groupGroundsFeeSubtotal + groupDepositSubtotal;
				var requiredShavingsEnabled = form.dataset.requiredShavingsEnabled === '1';
				var requiredShavingsQty = requiredShavingsEnabled ? stallQty * (parseInt(form.dataset.requiredShavingsPerStall || '0', 10) || 0) : 0;
				var stallSubtotal = stallQty * stallRate * stallUnits;
				var requiredShavingsSubtotal = requiredShavingsQty * requiredShavingsPrice;
				var rvSubtotal = rvQty * rvRate * rvUnits;
				var generalAddonsSubtotal = 0;
				var stallSectionSubtotal = stallSubtotal + requiredShavingsSubtotal;
				var subtotal = 0;
				var fees = 0;
				var total = 0;

				Object.keys(rvAddonPricingMatrix || {}).forEach(function(addonKey) {
					var isSelected = !!form.querySelector('[name="rv_addon_' + addonKey + '"]:checked');
					var addonSubtotal = isSelected ? rvQty * parseCurrency(rvAddonPricingMatrix[addonKey] ? rvAddonPricingMatrix[addonKey][rvStayType] : 0) * rvUnits : 0;

					rvAddonSubtotals[addonKey] = addonSubtotal;
					rvSubtotal += addonSubtotal;
				});

				Object.keys(generalAddonPricingMatrix || {}).forEach(function(addonKey) {
					var addonQty = getNumberFieldValue(form, 'general_addon_' + addonKey + '_qty');
					var addonSubtotal = addonQty * parseCurrency(generalAddonPricingMatrix[addonKey] ? generalAddonPricingMatrix[addonKey].price : 0);

					generalAddonSubtotals[addonKey] = addonSubtotal;
					generalAddonsSubtotal += addonSubtotal;
				});

				subtotal = stallSubtotal + requiredShavingsSubtotal + rvSubtotal + generalAddonsSubtotal + groupSubtotal;
				fees = calculateReservationFee(subtotal, feeType, feeValue);
				total = subtotal + fees;

				setTotal(form, 'stall_subtotal', stallSubtotal);
				setTotal(form, 'stall_section_subtotal', stallSectionSubtotal);
				setTotal(form, 'required_shavings_subtotal', requiredShavingsSubtotal);
				setTotal(form, 'rv_subtotal', rvSubtotal);
				setTotal(form, 'rv_section_subtotal', rvSubtotal);
				setTotal(form, 'general_addons_subtotal', generalAddonsSubtotal);
				setTotal(form, 'group_rider_grounds_fee_subtotal', groupGroundsFeeSubtotal);
				setTotal(form, 'group_rider_deposit_subtotal', groupDepositSubtotal);
				setTotal(form, 'group_subtotal', groupSubtotal);
				Object.keys(generalAddonPricingMatrix || {}).forEach(function(addonKey) {
					setTotal(form, 'general_addon_' + addonKey + '_subtotal', generalAddonSubtotals[addonKey] || 0);
				});
				Object.keys(rvAddonPricingMatrix || {}).forEach(function(addonKey) {
					setTotal(form, 'rv_addon_' + addonKey + '_subtotal', rvAddonSubtotals[addonKey] || 0);
				});
				setTotal(form, 'fees', fees);
				setTotal(form, 'total', total);
				setReadonlyQuantity(form, 'required_shavings', requiredShavingsQty);
				setReadonlyQuantity(form, 'group_rider_grounds_fee', groupRiderCount);
				setReadonlyQuantity(form, 'group_rider_deposit', groupRiderCount);
				toggleSummaryRow(form, 'stall_subtotal', stallSubtotal > 0);
				toggleSummaryRow(form, 'required_shavings_subtotal', requiredShavingsEnabled && stallQty > 0 && requiredShavingsQty > 0);
				toggleSummaryRow(form, 'rv_subtotal', rvSubtotal > 0);
				toggleSummaryRow(form, 'group_rider_grounds_fee_subtotal', groupGroundsFeeSubtotal > 0);
				toggleSummaryRow(form, 'group_rider_deposit_subtotal', groupDepositSubtotal > 0);
				Object.keys(generalAddonPricingMatrix || {}).forEach(function(addonKey) {
					toggleSummaryRow(form, 'general_addon_' + addonKey + '_subtotal', (generalAddonSubtotals[addonKey] || 0) > 0);
				});
				Object.keys(rvAddonPricingMatrix || {}).forEach(function(addonKey) {
					toggleSummaryRow(form, 'rv_addon_' + addonKey + '_subtotal', (rvAddonSubtotals[addonKey] || 0) > 0);
				});
				toggleSummaryRow(form, 'fees', fees > 0);
				syncStallAssignmentAvailability(form);
			}

			function parseJsonAttribute(value) {
				if (!value) {
					return {};
				}

				try {
					return JSON.parse(value);
				} catch (error) {
					return {};
				}
			}

			function getBillableStayUnits(arrivalDate, departureDate, stayType) {
				var arrivalParts;
				var departureParts;
				var arrivalUtc;
				var departureUtc;
				var diffDays;

				if (stayType === 'weekend') {
					return 1;
				}

				if (!arrivalDate || !departureDate) {
					return 1;
				}

				arrivalParts = String(arrivalDate).split('-');
				departureParts = String(departureDate).split('-');

				if (arrivalParts.length !== 3 || departureParts.length !== 3) {
					return 1;
				}

				arrivalUtc = Date.UTC(parseInt(arrivalParts[0], 10), parseInt(arrivalParts[1], 10) - 1, parseInt(arrivalParts[2], 10));
				departureUtc = Date.UTC(parseInt(departureParts[0], 10), parseInt(departureParts[1], 10) - 1, parseInt(departureParts[2], 10));

				if (isNaN(arrivalUtc) || isNaN(departureUtc)) {
					return 1;
				}

				diffDays = Math.round((departureUtc - arrivalUtc) / 86400000);

				return Math.max(1, diffDays);
			}

			function syncSectionStayDateFields(form, section, changedField) {
				var controls = form.querySelector('[data-section-stay-controls="' + section + '"]');
				var arrival = form.querySelector('[name="' + section + '_arrival_date"]');
				var departure = form.querySelector('[name="' + section + '_departure_date"]');
				var stayType = getFieldValue(form, section + '_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				var minDate;
				var maxDate;
				var weekendStart;
				var weekendEnd;
				var stayDetailsHelp = controls ? controls.querySelector('.eem-section-stay-controls__help') : null;
				var nightlySummary = controls ? (controls.dataset.nightlySummary || '') : '';
				var weekendSummary = controls ? (controls.dataset.weekendSummary || '') : '';
				var weekendSummaryWrap = controls ? controls.querySelector('.eem-weekend-package-summary') : null;
				var nightCountSummary = controls ? controls.querySelector('[data-stay-nights-summary]') : null;
				var arrivalFieldWrap = controls ? controls.querySelector('.eem-stay-date-field--arrival') : null;
				var departureFieldWrap = controls ? controls.querySelector('.eem-stay-date-field--departure') : null;

				if (!controls || !arrival || !departure) {
					return;
				}

				if (!getFieldValue(form, section + '_stay_type') && form.querySelector('[name="' + section + '_stay_type"][data-default-stay-type]')) {
					form.querySelector('[name="' + section + '_stay_type"]').value = form.querySelector('[name="' + section + '_stay_type"]').dataset.defaultStayType;
					stayType = getFieldValue(form, section + '_stay_type') === 'weekend' ? 'weekend' : 'nightly';
				}

				minDate = stayType === 'weekend' ? (controls.dataset.weekendStartDate || '') : (form.dataset.nightlyStartDate || getDateFieldBoundary(arrival, departure, 'min'));
				maxDate = stayType === 'weekend' ? (controls.dataset.weekendEndDate || controls.dataset.weekendStartDate || '') : (form.dataset.nightlyEndDate || getDateFieldBoundary(arrival, departure, 'max'));
				weekendStart = controls.dataset.weekendStartDate || minDate;
				weekendEnd = controls.dataset.weekendEndDate || maxDate;

				if (stayType === 'weekend') {
					if (stayDetailsHelp && weekendSummary) {
						stayDetailsHelp.textContent = weekendSummary;
					}

					if (arrivalFieldWrap) {
						arrivalFieldWrap.hidden = true;
					}

					if (departureFieldWrap) {
						departureFieldWrap.hidden = true;
					}

					if (weekendSummaryWrap) {
						weekendSummaryWrap.hidden = false;
					}

					if (weekendStart) {
						arrival.value = weekendStart;
					}

					if (weekendEnd) {
						departure.value = weekendEnd;
					}

					syncDateSelectOptions(arrival, departure, weekendStart, weekendEnd, changedField);
					lockDateSelectValue(arrival, weekendStart);
					lockDateSelectValue(departure, weekendEnd);
					lockDateField(arrival, true);
					lockDateField(departure, true);
					updateStayNightCountSummary(nightCountSummary, weekendStart, weekendEnd);
					return;
				}

				if (stayDetailsHelp && nightlySummary) {
					stayDetailsHelp.textContent = nightlySummary;
				}

				if (arrivalFieldWrap) {
					arrivalFieldWrap.hidden = false;
				}

				if (departureFieldWrap) {
					departureFieldWrap.hidden = false;
				}

				if (weekendSummaryWrap) {
					weekendSummaryWrap.hidden = true;
				}

				lockDateField(arrival, false);
				lockDateField(departure, false);

				if (!arrival.value && arrival.dataset.defaultDate) {
					arrival.value = arrival.dataset.defaultDate;
				}

				if (!departure.value && departure.dataset.defaultDate) {
					departure.value = departure.dataset.defaultDate;
				}

				if (minDate && arrival.value && arrival.value < minDate) {
					arrival.value = minDate;
				}

				if (maxDate && arrival.value && arrival.value > maxDate) {
					arrival.value = maxDate;
				}

				if (minDate && departure.value && departure.value < minDate) {
					departure.value = minDate;
				}

				if (maxDate && departure.value && departure.value > maxDate) {
					departure.value = maxDate;
				}

				if (arrival.value && departure.value && arrival.value > departure.value) {
					if (hasFieldNameSuffix(changedField, 'departure_date')) {
						arrival.value = departure.value;
					} else {
						departure.value = arrival.value;
					}
				}

				syncDateSelectOptions(arrival, departure, minDate, maxDate, changedField);
				updateStayNightCountSummary(nightCountSummary, arrival.value, departure.value);
			}

			function updateStayNightCountSummary(summaryNode, arrivalDate, departureDate) {
				var nightCount;

				if (!summaryNode) {
					return;
				}

				nightCount = getSelectedNightCount(arrivalDate, departureDate);
				summaryNode.textContent = formatStayNightCountLabel(nightCount);
			}

			function getSelectedNightCount(arrivalDate, departureDate) {
				var arrivalParts;
				var departureParts;
				var arrivalUtc;
				var departureUtc;
				var diffDays;

				if (!arrivalDate || !departureDate) {
					return 1;
				}

				arrivalParts = String(arrivalDate).split('-');
				departureParts = String(departureDate).split('-');

				if (arrivalParts.length !== 3 || departureParts.length !== 3) {
					return 1;
				}

				arrivalUtc = Date.UTC(parseInt(arrivalParts[0], 10), parseInt(arrivalParts[1], 10) - 1, parseInt(arrivalParts[2], 10));
				departureUtc = Date.UTC(parseInt(departureParts[0], 10), parseInt(departureParts[1], 10) - 1, parseInt(departureParts[2], 10));

				if (isNaN(arrivalUtc) || isNaN(departureUtc)) {
					return 1;
				}

				diffDays = Math.round((departureUtc - arrivalUtc) / 86400000);

				return Math.max(1, diffDays);
			}

			function formatStayNightCountLabel(nightCount) {
				nightCount = Math.max(1, parseInt(nightCount || '1', 10) || 1);

				return nightCount + ' ' + (nightCount === 1 ? 'Night' : 'Nights');
			}


			function lockDateField(field, isLocked) {
				if (!field) {
					return;
				}

				field.setAttribute('aria-disabled', isLocked ? 'true' : 'false');

				if (field.tagName === 'INPUT') {
					field.readOnly = !!isLocked;
				}
			}


			function lockDateSelectValue(field, lockedValue) {
				if (!field || field.tagName !== 'SELECT') {
					return;
				}

				Array.prototype.forEach.call(field.options, function(option) {
					if (!option.value) {
						option.disabled = false;
						return;
					}

					option.disabled = option.value !== lockedValue;
				});
			}

			function getDateFieldBoundary(arrival, departure, boundaryType) {
				var fields = [arrival, departure];
				var values = [];

				fields.forEach(function(field) {
					if (!field) {
						return;
					}

					if (field.tagName === 'SELECT') {
						Array.prototype.forEach.call(field.options, function(option) {
							if (option.value) {
								values.push(option.value);
							}
						});
					} else {
						if (field.getAttribute(boundaryType)) {
							values.push(field.getAttribute(boundaryType));
						}
					}
				});

				if (!values.length) {
					return '';
				}

				values.sort();

				return boundaryType === 'min' ? values[0] : values[values.length - 1];
			}

			function syncDateSelectOptions(arrival, departure, minDate, maxDate, changedField) {
				if (!arrival || !departure || arrival.tagName !== 'SELECT' || departure.tagName !== 'SELECT') {
					return;
				}

				updateSelectOptions(arrival, minDate, departure.value || maxDate);
				updateSelectOptions(departure, arrival.value || minDate, maxDate);

				if (arrival.selectedOptions.length && arrival.selectedOptions[0].disabled) {
					arrival.value = getNearestEnabledOptionValue(arrival, hasFieldNameSuffix(changedField, 'departure_date') ? 'last' : 'first');
				}

				if (departure.selectedOptions.length && departure.selectedOptions[0].disabled) {
					departure.value = getNearestEnabledOptionValue(departure, hasFieldNameSuffix(changedField, 'arrival_date') ? 'first' : 'last');
				}
			}

			function hasFieldNameSuffix(field, suffix) {
				return !!(field && field.name && field.name.slice(-suffix.length) === suffix);
			}

			function updateSelectOptions(field, minDate, maxDate) {
				Array.prototype.forEach.call(field.options, function(option) {
					var shouldDisable = false;

					if (!option.value) {
						option.disabled = false;
						return;
					}

					if (minDate && option.value < minDate) {
						shouldDisable = true;
					}

					if (maxDate && option.value > maxDate) {
						shouldDisable = true;
					}

					option.disabled = shouldDisable;
				});
			}

			function getNearestEnabledOptionValue(field, direction) {
				var options = Array.prototype.filter.call(field.options, function(option) {
					return option.value && !option.disabled;
				});

				if (!options.length) {
					return '';
				}

				return direction === 'last' ? options[options.length - 1].value : options[0].value;
			}

			function normalizePhoneField(form) {
				var phone = form.querySelector('[name="phone"]');
				var cleaned;
				var digitsOnly;

				if (!phone) {
					return;
				}

				if (!phone.value) {
					phone.value = '+1 ';
					return;
				}

				cleaned = String(phone.value).replace(/[^\d+]/g, '');
				digitsOnly = cleaned.replace(/\D+/g, '');

				if (digitsOnly.length >= 10) {
					var localDigits = digitsOnly.slice(-10);
					var countryDigits = digitsOnly.slice(0, -10);

					if (!countryDigits || /^1+$/.test(countryDigits)) {
						phone.value = '+1 ' + localDigits;
						return;
					}
				}

				if (digitsOnly.length === 11 && digitsOnly.charAt(0) === '1') {
					phone.value = '+1 ' + digitsOnly.substring(1);
					return;
				}

				if (digitsOnly.length === 10) {
					phone.value = '+1 ' + digitsOnly;
					return;
				}

				if (cleaned.charAt(0) !== '+') {
					if (cleaned.length === 10) {
						phone.value = '+1 ' + cleaned;
						return;
					}

					if (cleaned.length === 11 && cleaned.charAt(0) === '1') {
						phone.value = '+1 ' + cleaned.substring(1);
						return;
					}
				}

				if (cleaned === '+1' || cleaned === '1') {
					phone.value = '+1 ';
				}
			}

			function setStripeError(form, message) {
				var errorTarget = form.querySelector('[data-eem-stripe-card-error]');

				if (!errorTarget) {
					return;
				}

				errorTarget.textContent = message || '';
				errorTarget.hidden = !message;
			}

			function setSubmitDisabled(form, isDisabled) {
				form.querySelectorAll('.eem-reservation-submit').forEach(function(submitButton) {
					submitButton.disabled = !!isDisabled;
				});
			}

			function getTotalText(form, key) {
				var output = form.querySelector('[data-eem-total="' + key + '"]');

				return output ? output.textContent : '0';
			}

			function normalizeCountryCode(country) {
				var mapping = {
					'United States': 'US',
					'Canada': 'CA',
					'Mexico': 'MX',
					'Australia': 'AU',
					'New Zealand': 'NZ',
					'United Kingdom': 'GB',
					'Ireland': 'IE',
					'France': 'FR',
					'Germany': 'DE',
					'Italy': 'IT',
					'Spain': 'ES',
					'Netherlands': 'NL',
					'Belgium': 'BE',
					'Sweden': 'SE',
					'Norway': 'NO',
					'Denmark': 'DK',
					'Finland': 'FI',
					'Switzerland': 'CH',
					'Austria': 'AT',
					'Portugal': 'PT',
					'Brazil': 'BR',
					'Argentina': 'AR',
					'Chile': 'CL',
					'Colombia': 'CO',
					'South Africa': 'ZA',
					'Japan': 'JP',
					'Singapore': 'SG',
					'Hong Kong': 'HK',
					'United Arab Emirates': 'AE'
				};

				return mapping[country] || 'US';
			}

			function getFieldValue(form, name) {
				var field = form.querySelector('[name="' + name + '"]');

				return field ? field.value : '';
			}

			function getNumberFieldValue(form, name) {
				return Math.max(0, parseInt(getFieldValue(form, name) || '0', 10) || 0);
			}

			function parseCurrency(value) {
				return parseFloat(String(value || '0').replace(/[^0-9.-]+/g, '')) || 0;
			}

			function calculateReservationFee(subtotal, feeType, feeValue) {
				if (subtotal <= 0) {
					return 0;
				}

				if (feeType === 'flat') {
					return feeValue;
				}

				if (feeType === 'percentage') {
					return subtotal * (feeValue / 100);
				}

				return 0;
			}

			function setTotal(form, key, value) {
				var output = form.querySelector('[data-eem-total="' + key + '"]');

				if (output) {
					output.textContent = formatReservationMoney(value);
				}
			}

			function toggleSummaryRow(form, key, shouldShow) {
				var row = form.querySelector('[data-eem-summary-row="' + key + '"]');

				if (row) {
					row.hidden = !shouldShow;
				}
			}

			function formatReservationMoney(value) {
				return '$' + (Math.round((value + Number.EPSILON) * 100) / 100).toFixed(2);
			}

			function setReadonlyQuantity(form, source, value) {
				var control = form.querySelector('[data-eem-quantity-source="' + source + '"]');
				var input;

				if (!control) {
					return;
				}

				input = control.querySelector('input[type="number"]');

				if (input) {
					input.value = Math.max(0, value || 0);
				}
			}
		</script>
		<?php
	}
}
