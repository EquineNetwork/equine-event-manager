<?php
/**
 * Email Templates repository (SET-2, SET-3).
 *
 * Five customer-facing email templates persisted as a single wp_options
 * entry. Each template has a subject + HTML body. TinyMCE renders the
 * body editor on the Communications panel.
 *
 * Storage shape (wp_options key `eem_email_templates`):
 *   [
 *       'order_receipt'        => [ 'subject' => '...', 'body' => '<p>...</p>' ],
 *       'payment_reminder'     => [ 'subject' => '...', 'body' => '<p>...</p>' ],
 *       'refund_confirmation'  => [ 'subject' => '...', 'body' => '<p>...</p>' ],
 *       'cancellation'         => [ 'subject' => '...', 'body' => '<p>...</p>' ],
 *       'custom_welcome'       => [ 'subject' => '...', 'body' => '<p>...</p>' ],
 *   ]
 *
 * Single-option storage chosen over five-options because:
 *   - The templates always load together (one admin screen reads all five).
 *   - One get_option call vs five = cheaper autoload.
 *   - Migrations stay atomic — bumping the template set is one option update.
 *
 * Subject is sanitized via sanitize_text_field. Body is sanitized via
 * wp_kses_post — same allowlist WP uses for post content, lets editorial
 * formatting through, blocks scripts/iframes.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static repository for the five customer email templates.
 */
class EEM_Email_Templates_Repo {

	/** wp_options key holding all five templates. */
	const OPTION_KEY = 'eem_email_templates';

	/** Template id constants — callers use these instead of raw strings. */
	const ORDER_RECEIPT       = 'order_receipt';
	const PAYMENT_REMINDER    = 'payment_reminder';
	const REFUND_CONFIRMATION = 'refund_confirmation';
	const CANCELLATION        = 'cancellation';
	const CUSTOM_WELCOME      = 'custom_welcome';

	/**
	 * Ordered list of all template ids (drives UI ordering on the Communications panel).
	 *
	 * @return array<int, string>
	 */
	public static function ids() {
		return array(
			self::ORDER_RECEIPT,
			self::PAYMENT_REMINDER,
			self::REFUND_CONFIRMATION,
			self::CANCELLATION,
			self::CUSTOM_WELCOME,
		);
	}

	/**
	 * Human-readable label for a template id (used as the card title in UI).
	 *
	 * @param string $template_id One of the ID constants.
	 * @return string Translated label.
	 */
	public static function label( $template_id ) {
		switch ( $template_id ) {
			case self::ORDER_RECEIPT:       return __( 'Order Receipt', 'equine-event-manager' );
			case self::PAYMENT_REMINDER:    return __( 'Payment Reminder', 'equine-event-manager' );
			case self::REFUND_CONFIRMATION: return __( 'Refund Confirmation', 'equine-event-manager' );
			case self::CANCELLATION:        return __( 'Cancellation', 'equine-event-manager' );
			case self::CUSTOM_WELCOME:      return __( 'Custom Welcome', 'equine-event-manager' );
			default:                        return $template_id;
		}
	}

	/**
	 * Description string shown under each template card title.
	 *
	 * @param string $template_id
	 * @return string
	 */
	public static function description( $template_id ) {
		switch ( $template_id ) {
			case self::ORDER_RECEIPT:
				return __( 'Sent immediately after a successful order and payment.', 'equine-event-manager' );
			case self::PAYMENT_REMINDER:
				return __( 'Sent for unpaid invoices, manually or via the Orders bulk action.', 'equine-event-manager' );
			case self::REFUND_CONFIRMATION:
				return __( 'Sent after a refund is processed (full or partial).', 'equine-event-manager' );
			case self::CANCELLATION:
				return __( 'Sent when an order is cancelled by admin or by the customer.', 'equine-event-manager' );
			case self::CUSTOM_WELCOME:
				return __( 'Sent to new customers — first-time buyer welcomes or VIP greetings.', 'equine-event-manager' );
			default:
				return '';
		}
	}

	/**
	 * Default subject + body for a template id. Returned when no stored value exists.
	 *
	 * @param string $template_id
	 * @return array{subject:string, body:string}
	 */
	public static function defaults( $template_id ) {
		$defaults = array(
			self::ORDER_RECEIPT => array(
				'subject' => __( 'Your reservation is confirmed — Order #{{order_number}}', 'equine-event-manager' ),
				'body'    => '<p>' . __( 'Hi {{customer_name}},', 'equine-event-manager' ) . '</p>'
					. '<p>' . __( "Your reservation for {{event_name}} on {{event_dates}} is confirmed. Your total today was {{total}}.", 'equine-event-manager' ) . '</p>'
					. '<p>{{stall_assignments}}</p>'
					. '<p>' . __( 'Questions? Reach us at {{support_email}} or {{support_phone}}.', 'equine-event-manager' ) . '</p>',
			),
			self::PAYMENT_REMINDER => array(
				'subject' => __( 'Reminder: balance due for Order #{{order_number}}', 'equine-event-manager' ),
				'body'    => '<p>' . __( 'Hi {{customer_name}},', 'equine-event-manager' ) . '</p>'
					. '<p>' . __( "Your reservation for {{event_name}} on {{event_dates}} has a remaining balance of {{balance}}.", 'equine-event-manager' ) . '</p>'
					. '<p>' . __( 'Pay now: {{payment_link}}', 'equine-event-manager' ) . '</p>',
			),
			self::REFUND_CONFIRMATION => array(
				'subject' => __( 'A refund has been processed for Order #{{order_number}}', 'equine-event-manager' ),
				'body'    => '<p>' . __( 'Hi {{customer_name}},', 'equine-event-manager' ) . '</p>'
					. '<p>' . __( "We've processed a refund of {{total}} for your reservation on {{event_dates}}. It should appear on your statement within 5–10 business days.", 'equine-event-manager' ) . '</p>'
					. '<p>{{cancellation_policy}}</p>',
			),
			self::CANCELLATION => array(
				'subject' => __( 'Order #{{order_number}} has been cancelled', 'equine-event-manager' ),
				'body'    => '<p>' . __( 'Hi {{customer_name}},', 'equine-event-manager' ) . '</p>'
					. '<p>' . __( "Your reservation for {{event_name}} on {{event_dates}} has been cancelled.", 'equine-event-manager' ) . '</p>'
					. '<p>{{cancellation_policy}}</p>',
			),
			self::CUSTOM_WELCOME => array(
				'subject' => __( 'Welcome from the show team', 'equine-event-manager' ),
				'body'    => '<p>' . __( 'Hi {{customer_name}},', 'equine-event-manager' ) . '</p>'
					. '<p>' . __( "Welcome — we're glad you're joining us at {{event_name}}.", 'equine-event-manager' ) . '</p>',
			),
		);

		if ( ! isset( $defaults[ $template_id ] ) ) {
			return array( 'subject' => '', 'body' => '' );
		}

		return $defaults[ $template_id ];
	}

	/**
	 * Whitelist of placeholder tokens the template editor exposes in the picker.
	 *
	 * Token => human description (used as tooltip / hint copy on the chips).
	 *
	 * @return array<string, string>
	 */
	public static function placeholders() {
		return array(
			'customer_name'       => __( "Customer's full name", 'equine-event-manager' ),
			'event_name'          => __( 'Event name', 'equine-event-manager' ),
			'event_venue'         => __( 'Venue / facility name', 'equine-event-manager' ),
			'event_address'       => __( 'Venue street address', 'equine-event-manager' ),
			'event_dates'         => __( 'Event date range', 'equine-event-manager' ),
			'order_number'        => __( 'Plugin order number (e.g. #0042)', 'equine-event-manager' ),
			'total'               => __( 'Total paid for this order', 'equine-event-manager' ),
			'balance'             => __( 'Outstanding balance (for reminders)', 'equine-event-manager' ),
			'payment_link'        => __( 'Payment URL the customer can click to settle a balance', 'equine-event-manager' ),
			'stall_assignments'   => __( "Customer's assigned stall numbers / RV lots", 'equine-event-manager' ),
			'support_phone'       => __( 'Support phone number from Settings → Branding', 'equine-event-manager' ),
			'support_email'       => __( 'Support email from Settings → Communications', 'equine-event-manager' ),
			'cancellation_policy' => __( 'Cancellation policy text from Settings → Communications → Policies', 'equine-event-manager' ),
		);
	}

	/**
	 * Get a single template (subject + body) with defaults filled in for missing fields.
	 *
	 * @param string $template_id
	 * @return array{subject:string, body:string}
	 */
	public static function get( $template_id ) {
		$all  = self::all();
		return isset( $all[ $template_id ] ) ? $all[ $template_id ] : self::defaults( $template_id );
	}

	/**
	 * Get all five templates with defaults filled in for any missing entries.
	 *
	 * @return array<string, array{subject:string, body:string}>
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$result = array();
		foreach ( self::ids() as $id ) {
			$defaults = self::defaults( $id );
			$row      = isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? $stored[ $id ] : array();
			$result[ $id ] = array(
				'subject' => isset( $row['subject'] ) && '' !== $row['subject'] ? (string) $row['subject'] : $defaults['subject'],
				'body'    => isset( $row['body'] )    && '' !== $row['body']    ? (string) $row['body']    : $defaults['body'],
			);
		}

		return $result;
	}

	/**
	 * Update one template. Unknown ids are rejected. Subject + body are sanitized.
	 *
	 * @param string                          $template_id
	 * @param array{subject?:string,body?:string} $template
	 * @return bool True on success.
	 */
	public static function update( $template_id, array $template ) {
		if ( ! in_array( $template_id, self::ids(), true ) ) {
			return false;
		}

		$all = self::all();
		$all[ $template_id ] = self::sanitize_one( $template );

		return self::write( $all );
	}

	/**
	 * Replace all templates at once. Used by the save_settings AJAX dispatcher.
	 * Unknown ids in the input are silently dropped.
	 *
	 * @param array<string, array{subject?:string,body?:string}> $templates
	 * @return bool
	 */
	public static function update_all( array $templates ) {
		$next = array();
		foreach ( self::ids() as $id ) {
			$row = isset( $templates[ $id ] ) && is_array( $templates[ $id ] ) ? $templates[ $id ] : array();
			$next[ $id ] = self::sanitize_one( $row );
		}

		return self::write( $next );
	}

	/**
	 * Sanitize a single template row.
	 *
	 * @param array $template
	 * @return array{subject:string, body:string}
	 */
	private static function sanitize_one( array $template ) {
		$subject = isset( $template['subject'] ) ? sanitize_text_field( (string) $template['subject'] ) : '';
		$body    = isset( $template['body'] )    ? wp_kses_post( (string) $template['body'] )           : '';
		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Persist the templates array to wp_options.
	 *
	 * @param array $templates
	 * @return bool
	 */
	private static function write( array $templates ) {
		return (bool) update_option( self::OPTION_KEY, $templates, false );
	}
}
