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
				'key'   => 'event_source',
				'label' => __( 'Event Source', 'equine-event-manager' ),
				'hint'  => __( 'Choose where your events come from (e.g., The Events Calendar) and connect it. Reservations link to events from this source.', 'equine-event-manager' ),
				'done'  => self::event_source_done(),
				'url'   => self::settings_url( 'integrations' ),
			),
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
	 * True when the current user has dismissed the optional SendGrid row. Since
	 * 2.7.50 the dismiss control lives only on the SendGrid action (the four
	 * required areas can be completed but never dismissed).
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
		return ! empty( self::pending_actions() );
	}

	/**
	 * The ordered list of OUTSTANDING onboarding actions for the Dashboard card.
	 *
	 * Completed required areas are omitted entirely (they "drop off" — Whitney's
	 * 2.7.50 product decision). Once all four required areas are configured, a
	 * prominent "create your first reservation" action appears (until a published
	 * reservation exists), because a reservation is the gateway to orders + stall
	 * charts. The optional SendGrid row is the ONLY dismissable action.
	 *
	 * The card hides itself when this returns empty.
	 *
	 * @return array<int, array{type:string,key:string,label:string,hint:string,url:string,cta:string,tone:string,dismissable:bool}>
	 */
	public static function pending_actions(): array {
		$by_key = array();
		foreach ( self::items() as $it ) {
			$by_key[ $it['key'] ] = $it;
		}

		$rows = array();

		// 1. Incomplete REQUIRED setup areas, in canonical order.
		$required_incomplete = 0;
		foreach ( array( 'event_source', 'branding', 'communications', 'payments' ) as $k ) {
			if ( empty( $by_key[ $k ]['done'] ) ) {
				$required_incomplete++;
				$rows[] = array(
					'type'        => 'setup',
					'key'         => $k,
					'label'       => $by_key[ $k ]['label'],
					'hint'        => $by_key[ $k ]['hint'],
					'url'         => $by_key[ $k ]['url'],
					'cta'         => __( 'Set up', 'equine-event-manager' ),
					'tone'        => 'electric',
					'dismissable' => false,
				);
			}
		}

		// 2. Setup complete → create the first reservation (gateway to orders +
		//    stall charts). Drops off once a published reservation exists.
		if ( 0 === $required_incomplete && ! self::has_published_reservation() ) {
			$rows[] = array(
				'type'        => 'reservation',
				'key'         => 'first_reservation',
				'label'       => __( 'Create your first reservation', 'equine-event-manager' ),
				'hint'        => __( 'Link an event, then set your stalls / RV spaces, add-ons, and pricing. You need a published reservation before you can take orders or build stall charts.', 'equine-event-manager' ),
				'url'         => self::new_reservation_url(),
				'cta'         => __( 'Create Reservation', 'equine-event-manager' ),
				'tone'        => 'amber',
				'dismissable' => false,
			);
		}

		// 3. Optional SendGrid — the only dismissable row.
		if ( empty( $by_key['sendgrid']['done'] ) && ! self::is_dismissed() ) {
			$rows[] = array(
				'type'        => 'sendgrid',
				'key'         => 'sendgrid',
				'label'       => $by_key['sendgrid']['label'],
				'hint'        => $by_key['sendgrid']['hint'],
				'url'         => $by_key['sendgrid']['url'],
				'cta'         => __( 'Set up', 'equine-event-manager' ),
				'tone'        => 'electric',
				'dismissable' => true,
			);
		}

		return $rows;
	}

	/**
	 * Whether at least one published reservation exists (gates the
	 * "create your first reservation" onboarding action).
	 *
	 * @return bool
	 */
	private static function has_published_reservation(): bool {
		$post_type = class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation';
		$ids       = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		return ! empty( $ids );
	}

	/**
	 * Admin URL that starts a brand-new reservation (same entry point as the
	 * Reservations list "+ New Reservation" button).
	 *
	 * @return string
	 */
	private static function new_reservation_url(): string {
		$post_type = class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation';
		return admin_url( 'post-new.php?post_type=' . $post_type );
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
	 * AJAX: dismiss the optional SendGrid onboarding row for the current user.
	 * Cap + nonce gated. The four required areas are never dismissable.
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
	 * Event source is done once the admin has explicitly chosen + saved a source
	 * on Settings → Events (the `eem_event_source_confirmed` flag, set by
	 * EEM_Settings_Page::save_integrations_panel). A fresh install is NOT
	 * considered done just because TEC is technically available — the admin must
	 * consciously connect it, which is the whole point of this onboarding step.
	 *
	 * @return bool
	 */
	private static function event_source_done(): bool {
		return self::is_event_source_confirmed();
	}

	/**
	 * Whether the admin has connected an event source. True once they explicitly
	 * save a source on Settings → Events (the `eem_event_source_confirmed` flag).
	 *
	 * Lazy backfill: sites that pre-date this onboarding step won't have the flag,
	 * but a PUBLISHED reservation can only exist if a source was already connected
	 * and used (publishing requires a linked event). So if any published
	 * reservation exists, we set the flag and treat the source as confirmed —
	 * existing/upgraded sites are never told to "reconnect," while a truly fresh
	 * install (zero reservations) still gets the onboarding step.
	 *
	 * Used by both this checklist and the Settings → Events radio render so the two
	 * stay in lock-step.
	 *
	 * @return bool
	 */
	public static function is_event_source_confirmed(): bool {
		if ( get_option( 'eem_event_source_confirmed', false ) ) {
			return true;
		}

		$post_type = class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation';
		$existing  = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		if ( ! empty( $existing ) ) {
			update_option( 'eem_event_source_confirmed', 1, false );
			return true;
		}

		return false;
	}

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
