<?php
/**
 * Shared email delivery helper.
 *
 * @package EEM_Plugin
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
	 * @return true|WP_Error
	 */
	public static function send_html_email( $to, $subject, $html, $headers = array() ) {
		$to      = sanitize_email( (string) $to );
		$subject = wp_strip_all_tags( (string) $subject );
		$html    = (string) $html;
		$headers = is_array( $headers ) ? $headers : array();

		if ( '' === $to || ! is_email( $to ) ) {
			return new WP_Error( 'equine_event_manager_mail_invalid_to', __( 'A valid recipient email address is required before sending this message.', 'equine-event-manager' ) );
		}

		$api_key = self::get_sendgrid_api_key();

		if ( '' !== $api_key ) {
			return self::send_via_sendgrid( $api_key, $to, $subject, $html, $headers );
		}

		return self::send_via_wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * Deliver mail with WordPress wp_mail.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Subject line.
	 * @param string $html HTML message body.
	 * @param array  $headers Optional mail headers.
	 * @return true|WP_Error
	 */
	private static function send_via_wp_mail( $to, $subject, $html, $headers ) {
		$sent = wp_mail( $to, $subject, $html, $headers );

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
	 * @return true|WP_Error
	 */
	private static function send_via_sendgrid( $api_key, $to, $subject, $html, $headers ) {
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
