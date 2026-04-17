<?php
/**
 * Shortcodes for EN Event Manager.
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers reservation shortcode shells.
 */
class EN_Event_Manager_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public function register() {
		add_shortcode( 'en_reservation', array( $this, 'render_reservation' ) );
		add_shortcode( 'en_stall_reservation_form', array( $this, 'render_stall_reservation_form' ) );
		add_shortcode( 'en_rv_reservation_form', array( $this, 'render_rv_reservation_form' ) );
	}

	/**
	 * Render the generated reservation shortcode placeholder.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_reservation( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'en_reservation'
		);

		$reservation_id = absint( $atts['id'] );

		ob_start();
		?>
		<div class="en-event-manager-reservation-form-shell" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>">
			<h2><?php esc_html_e( 'Event Reservation', 'en-event-manager' ); ?></h2>
			<p><?php esc_html_e( 'Reservation form coming soon.', 'en-event-manager' ); ?></p>
			<?php if ( $reservation_id ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: Reservation setup ID. */
						esc_html__( 'Reservation ID: %d', 'en-event-manager' ),
						$reservation_id
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render the stall reservation form placeholder.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_stall_reservation_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id'          => 0,
				'external_event_id' => '',
				'event_source'      => 'wordpress',
			),
			$atts,
			'en_stall_reservation_form'
		);

		return $this->render_placeholder(
			__( 'Stall Reservation Form', 'en-event-manager' ),
			$atts
		);
	}

	/**
	 * Render the RV reservation form placeholder.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_rv_reservation_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id'          => 0,
				'external_event_id' => '',
				'event_source'      => 'wordpress',
			),
			$atts,
			'en_rv_reservation_form'
		);

		return $this->render_placeholder(
			__( 'RV Reservation Form', 'en-event-manager' ),
			$atts
		);
	}

	/**
	 * Render a shared shortcode placeholder.
	 *
	 * @param string $title Placeholder title.
	 * @param array  $atts Shortcode attributes.
	 * @return string
	 */
	private function render_placeholder( $title, $atts ) {
		$event_id          = absint( $atts['event_id'] );
		$external_event_id = sanitize_text_field( $atts['external_event_id'] );
		$event_source      = sanitize_key( $atts['event_source'] );

		ob_start();
		?>
		<div class="en-event-manager-reservation-form-shell" data-event-id="<?php echo esc_attr( $event_id ); ?>" data-event-source="<?php echo esc_attr( $event_source ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php esc_html_e( 'Reservation form coming soon.', 'en-event-manager' ); ?></p>
			<?php if ( $event_id ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: Event ID. */
						esc_html__( 'Event ID: %d', 'en-event-manager' ),
						$event_id
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $external_event_id ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: External event ID. */
						esc_html__( 'External event ID: %s', 'en-event-manager' ),
						esc_html( $external_event_id )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}
}
