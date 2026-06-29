<?php
/**
 * Per-recipient email opt-out (unsubscribe) for BULK / non-transactional sends.
 *
 * Scope (ROADMAP #22): the Notifications page broadcast + the Email Customers
 * modal are marketing-style bulk sends and must honor an unsubscribe. TRANSACTIONAL
 * sends (order confirmation, refund, payment-link/invoice, payment-received,
 * cancellation) ALWAYS send regardless of opt-out — they are part of the customer's
 * purchase contract, not marketing.
 *
 * How it works:
 *   - Each bulk email gets a per-recipient footer with an unsubscribe link:
 *     admin-post.php?action=eem_unsubscribe&e={email}&s={hmac}
 *   - The HMAC is keyed off the WP `auth` salt (no DB token to store/leak); the
 *     public handler recomputes it with hash_equals() before recording anything.
 *   - Opt-outs are stored in the `eem_email_optouts` option as email => ISO-8601
 *     timestamp. `is_opted_out()` is checked before each bulk send; opted-out
 *     recipients are skipped.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email opt-out registry + unsubscribe link/handler. All-static; no state.
 */
class EEM_Email_Optout {

	/**
	 * Option holding the opt-out map: lowercased email => opt-out timestamp (c).
	 *
	 * @var string
	 */
	const OPTION = 'eem_email_optouts';

	/**
	 * admin-post action slug for the public unsubscribe handler.
	 *
	 * @var string
	 */
	const ACTION = 'eem_unsubscribe';

	/**
	 * Register the public (no-auth) + authed unsubscribe handlers. Idempotent.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
	}

	/**
	 * Normalize an email for storage/compare (trim + lowercase).
	 *
	 * @param string $email Raw email.
	 * @return string Normalized email ('' when not a valid address).
	 */
	private static function normalize( string $email ): string {
		$email = strtolower( trim( $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * HMAC signature for an email's unsubscribe link, keyed off the WP auth salt.
	 *
	 * @param string $email Recipient email.
	 * @return string Hex HMAC, or '' for an invalid address.
	 */
	public static function signature( string $email ): string {
		$norm = self::normalize( $email );
		if ( '' === $norm ) {
			return '';
		}
		return hash_hmac( 'sha256', self::ACTION . '|' . $norm, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time verify of a presented signature against an email.
	 *
	 * @param string $email Recipient email.
	 * @param string $sig   Presented signature.
	 * @return bool
	 */
	public static function verify( string $email, string $sig ): bool {
		$expected = self::signature( $email );
		return '' !== $expected && is_string( $sig ) && hash_equals( $expected, $sig );
	}

	/**
	 * Public unsubscribe URL for a recipient.
	 *
	 * @param string $email Recipient email.
	 * @return string URL, or '' for an invalid address.
	 */
	public static function unsubscribe_url( string $email ): string {
		$norm = self::normalize( $email );
		if ( '' === $norm ) {
			return '';
		}
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'e'      => rawurlencode( $norm ),
				's'      => self::signature( $norm ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Has this email opted out of bulk email?
	 *
	 * @param string $email Recipient email.
	 * @return bool
	 */
	public static function is_opted_out( string $email ): bool {
		$norm = self::normalize( $email );
		if ( '' === $norm ) {
			return false;
		}
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) && isset( $map[ $norm ] );
	}

	/**
	 * Record an opt-out. No-op if already recorded.
	 *
	 * @param string $email Recipient email.
	 * @return bool True if newly recorded (or already present), false on bad input.
	 */
	public static function record_optout( string $email ): bool {
		$norm = self::normalize( $email );
		if ( '' === $norm ) {
			return false;
		}
		$map = get_option( self::OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		if ( ! isset( $map[ $norm ] ) ) {
			$map[ $norm ] = current_time( 'c' );
			update_option( self::OPTION, $map, false );
		}
		return true;
	}

	/**
	 * Branded unsubscribe footer appended to each bulk email (per-recipient link).
	 *
	 * @param string $email Recipient email.
	 * @return string HTML footer, or '' for an invalid address.
	 */
	public static function footer_html( string $email ): string {
		$url = self::unsubscribe_url( $email );
		if ( '' === $url ) {
			return '';
		}
		return '<div style="margin-top:22px;padding-top:14px;border-top:1px solid #e2e8f4;'
			. 'font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#64748b;">'
			. esc_html__( "You're receiving this because you have a reservation with us.", 'equine-event-manager' )
			. ' <a href="' . esc_url( $url ) . '" style="color:#1668F2;">'
			. esc_html__( 'Unsubscribe from these updates', 'equine-event-manager' )
			. '</a>.</div>';
	}

	/**
	 * Public handler for admin-post.php?action=eem_unsubscribe&e=…&s=…
	 *
	 * Verifies the HMAC, records the opt-out, and renders a minimal confirmation
	 * page. Never reveals whether the email exists in the system.
	 *
	 * @return void
	 */
	public static function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public unsubscribe link is HMAC-authenticated, not nonce-based (it arrives from an email, cross-session).
		$email = isset( $_GET['e'] ) ? sanitize_email( wp_unslash( $_GET['e'] ) ) : '';
		$sig   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ok = '' !== $email && self::verify( $email, $sig );
		if ( $ok ) {
			self::record_optout( $email );
		}

		$title = $ok
			? __( "You've been unsubscribed", 'equine-event-manager' )
			: __( 'Unsubscribe link invalid', 'equine-event-manager' );
		$body  = $ok
			? __( "You won't receive any more update emails. Order receipts and payment notices for your reservations will still be sent.", 'equine-event-manager' )
			: __( 'This unsubscribe link is invalid or has expired. No changes were made.', 'equine-event-manager' );

		status_header( $ok ? 200 : 400 );
		nocache_headers();
		wp_die(
			'<h1 style="font-family:Arial,Helvetica,sans-serif;color:#031B4E;">' . esc_html( $title ) . '</h1>'
			. '<p style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1d2327;">' . esc_html( $body ) . '</p>',
			esc_html( $title ),
			array( 'response' => $ok ? 200 : 400 )
		);
	}

	/**
	 * Filter a recipient list down to those who have NOT opted out.
	 *
	 * @param string[] $emails Recipient emails.
	 * @return string[] Emails still eligible for bulk send.
	 */
	public static function filter_recipients( array $emails ): array {
		return array_values( array_filter( $emails, static function ( $e ) {
			return ! self::is_opted_out( (string) $e );
		} ) );
	}
}
