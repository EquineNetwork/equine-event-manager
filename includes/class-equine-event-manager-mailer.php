<?php
/**
 * Shared email delivery helper.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SendGrid-aware mail helper with WordPress mail fallback.
 */
class EEM_Mailer {

	/**
	 * Integration settings option name.
	 */
	const INTEGRATION_SETTINGS_OPTION = 'equine_event_manager_integration_settings';

	/**
	 * Send an HTML email using SendGrid when configured, or wp_mail otherwise.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Subject line.
	 * @param string $html HTML message body.
	 * @param array  $headers Optional mail headers.
	 * @param array  $context Optional. C6.D telemetry context. {
	 *     @type string $type           Required for telemetry-capture: 'invoice' | 'checkout_confirmation' |
	 *                                  'reservation_confirmation' | 'email_customers' | 'test_email' | future
	 *                                  caller type. Empty = caller has no telemetry intent.
	 *     @type string $order_key      When applicable — surfaces email in the order's activity log.
	 *     @type int    $reservation_id When applicable.
	 *     @type string $event_label    Optional event-name context for forensics.
	 *     @type mixed  ...             Caller-specific extras (recipient_label, template_id, etc.).
	 * }
	 *                          Backward-compatible: existing callers without context still send fine
	 *                          but their activity-log telemetry payload will be empty.
	 * @return true|WP_Error
	 */
	public static function send_html_email( $to, $subject, $html, $headers = array(), $context = array(), $attachments = array() ) {
		$to      = sanitize_email( (string) $to );
		$subject = wp_strip_all_tags( (string) $subject );
		$html    = self::inline_css( (string) $html );
		$headers = is_array( $headers ) ? $headers : array();
		$context = is_array( $context ) ? $context : array();
		// Attachments are absolute file paths; drop anything unreadable.
		$attachments = array_values( array_filter( (array) $attachments, static function ( $path ) {
			return is_string( $path ) && '' !== $path && is_readable( $path );
		} ) );

		if ( '' === $to || ! is_email( $to ) ) {
			return new WP_Error( 'equine_event_manager_mail_invalid_to', __( 'A valid recipient email address is required before sending this message.', 'equine-event-manager' ) );
		}

		$api_key = self::get_sendgrid_api_key();

		$result = '' !== $api_key
			? self::send_via_sendgrid( $api_key, $to, $subject, $html, $headers, $attachments )
			: self::send_via_wp_mail( $to, $subject, $html, $headers, $attachments );

		// C6.D — emit eem_email_sent telemetry on a successful send.
		// EEM_Order_Telemetry::on_email_sent listens; writes order.email_sent
		// activity-log entry when the context payload identifies an order.
		// Non-order emails (test-email from Settings, etc.) pass through
		// silently because the listener requires context.order_key.
		if ( true === $result ) {
			/**
			 * @since 2.2.0 (C6.D)
			 * @param array $payload
			 */
			do_action( 'eem_email_sent', array(
				'to'      => $to,
				'subject' => $subject,
				'context' => $context,
			) );
		}

		return $result;
	}

	/**
	 * Inline a message's <style> block into element style attributes.
	 *
	 * Email clients (Outlook desktop, Gmail mobile, some Yahoo configs) strip or
	 * ignore <style> tags, so transactional templates author their CSS in a
	 * <style> block for readability and rely on this send-time pass (Pelago's
	 * Emogrifier) to inline it. Degrades gracefully: if Emogrifier is unavailable
	 * (vendor/ absent) or the HTML carries no <style>/<html> wrapper, the original
	 * HTML is returned unchanged.
	 *
	 * @param string $html Message HTML, possibly containing a <style> block.
	 * @return string HTML with CSS inlined, or the input unchanged on failure.
	 */
	public static function inline_css( $html ) {
		$html = (string) $html;

		if ( '' === $html
			|| false === stripos( $html, '<style' )
			|| ! class_exists( '\\Pelago\\Emogrifier\\CssInliner' ) ) {
			return $html;
		}

		try {
			return \Pelago\Emogrifier\CssInliner::fromHtml( $html )
				->inlineCss()
				->render();
		} catch ( \Throwable $e ) {
			// Never let an inlining failure block the send — fall back to raw HTML.
			return $html;
		}
	}

	/**
	 * Deliver mail with WordPress wp_mail.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Subject line.
	 * @param string $html HTML message body.
	 * @param array  $headers Optional mail headers.
	 * @param array  $attachments Optional absolute file paths to attach.
	 * @return true|WP_Error
	 */
	private static function send_via_wp_mail( $to, $subject, $html, $headers, $attachments = array() ) {
		$sent = wp_mail( $to, $subject, $html, $headers, (array) $attachments );

		if ( ! $sent ) {
			return new WP_Error( 'equine_event_manager_mail_wp_mail_failed', __( 'WordPress could not send the email. Please verify your site mailer configuration and try again.', 'equine-event-manager' ) );
		}

		return true;
	}

	/**
	 * Deliver mail through the SendGrid API.
	 *
	 * @param string $api_key SendGrid API key.
	 * @param string $to Recipient email.
	 * @param string $subject Subject line.
	 * @param string $html HTML body.
	 * @param array  $headers Optional headers.
	 * @param array  $attachments Optional absolute file paths to attach.
	 * @return true|WP_Error
	 */
	private static function send_via_sendgrid( $api_key, $to, $subject, $html, $headers, $attachments = array() ) {
		$parsed_headers = self::parse_headers( $headers );
		$from_name      = ! empty( $parsed_headers['from_name'] ) ? $parsed_headers['from_name'] : get_bloginfo( 'name' );
		$from_email     = ! empty( $parsed_headers['from_email'] ) && is_email( $parsed_headers['from_email'] ) ? $parsed_headers['from_email'] : get_option( 'admin_email', '' );
		$reply_to_email = ! empty( $parsed_headers['reply_to_email'] ) && is_email( $parsed_headers['reply_to_email'] ) ? $parsed_headers['reply_to_email'] : '';

		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'equine_event_manager_mail_missing_from', __( 'A valid From email address is required before sending mail through SendGrid.', 'equine-event-manager' ) );
		}

		$payload = array(
			'personalizations' => array(
				array(
					'to'      => array(
						array(
							'email' => $to,
						),
					),
					'subject' => $subject,
				),
			),
			'from'             => array(
				'email' => $from_email,
				'name'  => $from_name,
			),
			'content'          => array(
				array(
					'type'  => 'text/plain',
					'value' => self::build_plain_text_body( $html ),
				),
				array(
					'type'  => 'text/html',
					'value' => $html,
				),
			),
		);

		if ( $reply_to_email ) {
			$payload['reply_to'] = array(
				'email' => $reply_to_email,
			);
		}

		$sg_attachments = array();
		foreach ( (array) $attachments as $path ) {
			if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
				continue;
			}
			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $bytes || '' === $bytes ) {
				continue;
			}
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$type = 'pdf' === $ext ? 'application/pdf' : 'application/octet-stream';
			$sg_attachments[] = array(
				'content'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'filename'    => basename( $path ),
				'type'        => $type,
				'disposition' => 'attachment',
			);
		}
		if ( ! empty( $sg_attachments ) ) {
			$payload['attachments'] = $sg_attachments;
		}

		$response = wp_remote_post(
			'https://api.sendgrid.com/v3/mail/send',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'equine_event_manager_sendgrid_transport_failed', sprintf( __( 'SendGrid could not be reached: %s', 'equine-event-manager' ), $response->get_error_message() ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = __( 'SendGrid rejected the email request. Please verify the API key and sender details.', 'equine-event-manager' );

			if ( ! empty( $body['errors'][0]['message'] ) ) {
				$message = sanitize_text_field( $body['errors'][0]['message'] );
			}

			return new WP_Error( 'equine_event_manager_sendgrid_failed', $message );
		}

		return true;
	}

	/**
	 * Parse common mail headers into structured values.
	 *
	 * @param array $headers Header strings.
	 * @return array
	 */
	private static function parse_headers( $headers ) {
		$parsed = array(
			'from_name'      => '',
			'from_email'     => '',
			'reply_to_email' => '',
		);

		foreach ( $headers as $header ) {
			$header = (string) $header;

			if ( 0 === stripos( $header, 'From:' ) ) {
				$value = trim( substr( $header, 5 ) );

				if ( preg_match( '/^(.*)<([^>]+)>$/', $value, $matches ) ) {
					$parsed['from_name']  = trim( wp_strip_all_tags( trim( $matches[1], "\"' \t\n\r\0\x0B" ) ) );
					$parsed['from_email'] = sanitize_email( trim( $matches[2] ) );
				} elseif ( is_email( $value ) ) {
					$parsed['from_email'] = sanitize_email( $value );
				}
			} elseif ( 0 === stripos( $header, 'Reply-To:' ) ) {
				$value = trim( substr( $header, 9 ) );

				if ( preg_match( '/<([^>]+)>$/', $value, $matches ) ) {
					$parsed['reply_to_email'] = sanitize_email( trim( $matches[1] ) );
				} elseif ( is_email( $value ) ) {
					$parsed['reply_to_email'] = sanitize_email( $value );
				}
			}
		}

		return $parsed;
	}

	/**
	 * Build a readable plain-text fallback from HTML.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private static function build_plain_text_body( $html ) {
		$text = wp_strip_all_tags( preg_replace( '/<(br|\\/p|\\/div|\\/li|\\/tr)>/i', "\n", (string) $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * Get the saved SendGrid API key.
	 *
	 * @return string
	 */
	private static function get_sendgrid_api_key() {
		$settings = get_option( self::INTEGRATION_SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) || empty( $settings['sendgrid_api_key'] ) ) {
			return '';
		}

		return trim( (string) $settings['sendgrid_api_key'] );
	}
}
