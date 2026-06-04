<?php
/**
 * First-run setup checklist — go-live readiness tracker.
 *
 * Computes whether each REQUIRED configuration area has been filled in, from
 * the real saved option values (not a "was shown once" flag). The Dashboard
 * renders this as a dismissible card (see EEM_Dashboard_Page::render_setup_checklist)
 * that links each unfinished area straight to its Settings panel and auto-hides
 * once every required area is configured.
 *
 * Required areas (all four locked as required at the 2.7.24 product decision):
 *   - Branding        → company logo (PNG) + support email
 *   - Communications  → email sender (from name + from email) explicitly saved
 *   - Payments        → a complete publishable+secret key pair for the active gateway
 *   - SendGrid        → API key present
 *
 * Because completion is derived from live config, the card correctly RE-APPEARS
 * if an admin later clears a required value (e.g. removes the Stripe secret key),
 * unless they have explicitly dismissed it via the per-user dismiss flag.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static computation + AJAX dismiss for the Dashboard setup checklist.
 *
 * @since 2.7.24
 */
class EEM_Setup_Checklist {

	/** Per-user meta flag set when the admin dismisses the card. */
	const DISMISS_META = 'eem_setup_checklist_dismissed';

	/** Nonce action guarding the dismiss AJAX endpoint. */
	const DISMISS_NONCE = 'eem_setup_checklist_dismiss';

	/**
	 * Register the dismiss AJAX endpoint. Called once from the admin bootstrap.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_dismiss_setup_checklist', array( __CLASS__, 'ajax_dismiss' ) );
	}

	/**
	 * The ordered list of required setup areas with live completion state.
	 *
	 * @return array<int, array{key:string, label:string, hint:string, done:bool, url:string}>
	 */
	public static function items(): array {
		return array(
			array(
				'key'   => 'branding',
				'label' => __( 'Branding', 'equine-event-manager' ),
				'hint'  => __( 'Upload your PNG logo and support email — used on receipts, PDFs, and customer emails.', 'equine-event-manager' ),
				'done'  => self::branding_done(),
				'url'   => self::settings_url( 'branding' ),
			),
			array(
				'key'   => 'communications',
				'label' => __( 'Communications', 'equine-event-manager' ),
				'hint'  => __( 'Set the from-name and from-email so customer emails come from your business.', 'equine-event-manager' ),
				'done'  => self::communications_done(),
				'url'   => self::settings_url( 'communications' ),
			),
			array(
				'key'   => 'payments',
				'label' => __( 'Payments', 'equine-event-manager' ),
				'hint'  => __( 'Add your Stripe publishable and secret keys so you can charge customers.', 'equine-event-manager' ),
				'done'  => self::payments_done(),
				'url'   => self::settings_url( 'payments' ),
			),
			array(
				'key'   => 'sendgrid',
				'label' => __( 'Email Delivery (SendGrid)', 'equine-event-manager' ),
				'hint'  => __( 'Add your SendGrid API key for reliable delivery of confirmations and receipts.', 'equine-event-manager' ),
				'done'  => self::sendgrid_done(),
				'url'   => self::settings_url( 'integrations' ),
			),
		);
	}

	/**
	 * True when every required area is configured.
	 *
	 * @return bool
	 */
	public static function is_complete(): bool {
		foreach ( self::items() as $item ) {
			if ( empty( $item['done'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * True when the current user has dismissed the card.
	 *
	 * @return bool
	 */
	public static function is_dismissed(): bool {
		return (bool) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
	}

	/**
	 * Whether the Dashboard should render the checklist card right now.
	 *
	 * @return bool
	 */
	public static function should_show(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return ! self::is_complete() && ! self::is_dismissed();
	}

	/**
	 * Count of completed required areas (for the "N of M" progress label).
	 *
	 * @return int
	 */
	public static function completed_count(): int {
		$n = 0;
		foreach ( self::items() as $item ) {
			if ( ! empty( $item['done'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * AJAX: dismiss the card for the current user. Cap + nonce gated.
	 *
	 * @return void
	 */
	public static function ajax_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::DISMISS_NONCE, 'nonce' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────────────────────
	 * Per-area completion checks (read live option values).
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Branding is done when a logo is set and a support email is present.
	 *
	 * @return bool
	 */
	private static function branding_done(): bool {
		$company = get_option( 'equine_event_manager_company_settings', array() );
		if ( ! is_array( $company ) ) {
			return false;
		}
		$logo_id       = isset( $company['logo_id'] ) ? absint( $company['logo_id'] ) : 0;
		$support_email = isset( $company['support_email'] ) ? trim( (string) $company['support_email'] ) : '';
		return $logo_id > 0 && '' !== $support_email;
	}

	/**
	 * Communications is done when the sender option has been explicitly saved
	 * with a from-name and from-email (raw option, so defaults don't count).
	 *
	 * @return bool
	 */
	private static function communications_done(): bool {
		$raw = get_option( EEM_Settings_Repo::OPTION_EMAIL_SENDER, null );
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$from_name  = isset( $raw['from_name'] )  ? trim( (string) $raw['from_name'] )  : '';
		$from_email = isset( $raw['from_email'] ) ? trim( (string) $raw['from_email'] ) : '';
		return '' !== $from_name && '' !== $from_email;
	}

	/**
	 * Payments is done when the active gateway has a complete publishable+secret
	 * key pair (test OR live). Stripe is the default gateway.
	 *
	 * @return bool
	 */
	private static function payments_done(): bool {
		$payment = get_option( 'equine_event_manager_payment_settings', array() );
		if ( ! is_array( $payment ) ) {
			return false;
		}
		$gateway = isset( $payment['selected_gateway'] ) ? (string) $payment['selected_gateway'] : 'stripe';
		$creds   = isset( $payment[ $gateway ] ) && is_array( $payment[ $gateway ] ) ? $payment[ $gateway ] : array();

		if ( 'authorize_net' === $gateway ) {
			$test_ok = '' !== trim( (string) ( $creds['test_api_login'] ?? '' ) )
				&& '' !== trim( (string) ( $creds['test_transaction_key'] ?? '' ) );
			$live_ok = '' !== trim( (string) ( $creds['live_api_login'] ?? '' ) )
				&& '' !== trim( (string) ( $creds['live_transaction_key'] ?? '' ) );
			return $test_ok || $live_ok;
		}

		// Stripe: a complete test pair OR a complete live pair.
		$test_ok = '' !== trim( (string) ( $creds['test_publishable_key'] ?? '' ) )
			&& '' !== trim( (string) ( $creds['test_secret_key'] ?? '' ) );
		$live_ok = '' !== trim( (string) ( $creds['live_publishable_key'] ?? '' ) )
			&& '' !== trim( (string) ( $creds['live_secret_key'] ?? '' ) );
		return $test_ok || $live_ok;
	}

	/**
	 * SendGrid is done when an API key is present.
	 *
	 * @return bool
	 */
	private static function sendgrid_done(): bool {
		$integration = get_option( 'equine_event_manager_integration_settings', array() );
		if ( ! is_array( $integration ) ) {
			return false;
		}
		return '' !== trim( (string) ( $integration['sendgrid_api_key'] ?? '' ) );
	}

	/**
	 * Admin URL for a Settings panel.
	 *
	 * @param string $panel Panel id (branding|communications|payments|integrations).
	 * @return string
	 */
	private static function settings_url( string $panel ): string {
		return add_query_arg(
			array(
				'page'  => 'equine-event-manager-settings',
				'panel' => $panel,
			),
			admin_url( 'admin.php' )
		);
	}
}
