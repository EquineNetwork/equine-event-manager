<?php
/**
 * Settings repository — non-template Settings page groups (SET-5..SET-7).
 *
 * Three independent wp_options entries, each holding a small structured array:
 *
 *   eem_email_sender   { from_name, from_email, reply_to }            (SET-7)
 *   eem_tax_settings   { apply, default_rate, label }                 (SET-6)
 *   eem_policies       { cancellation, terms }                        (SET-5)
 *
 * Separate options rather than one because the three groups are edited
 * independently on the Communications + Payments panels and have unrelated
 * cache semantics — a Tax change shouldn't dirty Email Sender, and vice versa.
 *
 * Tax helper:
 *   get_tax_rate_for_reservation( $reservation_id )  → effective rate (per-
 *   reservation override via _en_reservation_tax_rate meta if set, else the
 *   global default). C3.D wires this into the checkout/pricing flow. C7
 *   later adds the UI field on Edit Reservation that lets admins set the
 *   per-reservation override (until then, only the global default applies).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static repository for the three non-template Settings groups.
 */
class EEM_Settings_Repo {

	const OPTION_EMAIL_SENDER = 'eem_email_sender';
	const OPTION_TAX          = 'eem_tax_settings';
	const OPTION_POLICIES     = 'eem_policies';

	/** Reservation meta key for the per-reservation tax-rate override (C7 surfaces the UI field). */
	const RESERVATION_TAX_META = '_en_reservation_tax_rate';

	/* ─────────────────────────────────────────────────────────────
	 * Email Sender (SET-7)
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * @return array{from_name:string, from_email:string, reply_to:string}
	 */
	public static function get_email_sender() {
		$stored = get_option( self::OPTION_EMAIL_SENDER, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$site_email = get_option( 'admin_email' );

		return array(
			'from_name'  => isset( $stored['from_name'] )  ? (string) $stored['from_name']  : (string) get_bloginfo( 'name' ),
			'from_email' => isset( $stored['from_email'] ) ? (string) $stored['from_email'] : (string) $site_email,
			'reply_to'   => isset( $stored['reply_to'] )   ? (string) $stored['reply_to']   : '',
		);
	}

	/**
	 * @param array{from_name?:string, from_email?:string, reply_to?:string} $sender
	 * @return bool
	 */
	public static function update_email_sender( array $sender ) {
		$next = array(
			'from_name'  => isset( $sender['from_name'] )  ? sanitize_text_field( (string) $sender['from_name'] )  : '',
			'from_email' => isset( $sender['from_email'] ) ? sanitize_email( (string) $sender['from_email'] )      : '',
			'reply_to'   => isset( $sender['reply_to'] )   ? sanitize_email( (string) $sender['reply_to'] )        : '',
		);

		return (bool) update_option( self::OPTION_EMAIL_SENDER, $next, false );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Tax (SET-6)
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * @return array{apply:bool, default_rate:float, label:string}
	 */
	public static function get_tax() {
		$stored = get_option( self::OPTION_TAX, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'apply'        => ! empty( $stored['apply'] ),
			'default_rate' => isset( $stored['default_rate'] ) ? (float) $stored['default_rate'] : 0.0,
			'label'        => isset( $stored['label'] ) && '' !== $stored['label'] ? (string) $stored['label'] : __( 'Sales Tax', 'equine-event-manager' ),
		);
	}

	/**
	 * @param array{apply?:bool, default_rate?:float|string, label?:string} $tax
	 * @return bool
	 */
	public static function update_tax( array $tax ) {
		// Clamp rate to [0, 100]; coerce blank/non-numeric to 0.
		$rate = isset( $tax['default_rate'] ) ? (float) $tax['default_rate'] : 0.0;
		if ( $rate < 0 ) {
			$rate = 0.0;
		} elseif ( $rate > 100 ) {
			$rate = 100.0;
		}

		$next = array(
			'apply'        => ! empty( $tax['apply'] ),
			'default_rate' => $rate,
			'label'        => isset( $tax['label'] ) ? sanitize_text_field( (string) $tax['label'] ) : '',
		);

		return (bool) update_option( self::OPTION_TAX, $next, false );
	}

	/**
	 * Effective tax rate for a reservation (0..100 percent). C3.D wires this
	 * into the pricing pipeline. C7 adds the UI field that writes the override
	 * meta; until then this method always returns the global default when tax
	 * is enabled, or 0.0 when disabled.
	 *
	 * @param int $reservation_id  Reservation post id. 0 = global default only.
	 * @return float  Percentage rate (e.g. 7.5 for 7.5%). Returns 0.0 when tax is disabled.
	 */
	public static function get_tax_rate_for_reservation( $reservation_id = 0 ) {
		$tax = self::get_tax();
		if ( ! $tax['apply'] ) {
			return 0.0;
		}

		$reservation_id = absint( $reservation_id );
		if ( $reservation_id > 0 ) {
			$override = get_post_meta( $reservation_id, self::RESERVATION_TAX_META, true );
			// Override applies only when admin has set a numeric value (including 0 for tax-exempt).
			if ( '' !== $override && null !== $override ) {
				$override_rate = (float) $override;
				if ( $override_rate >= 0 && $override_rate <= 100 ) {
					return $override_rate;
				}
			}
		}

		return (float) $tax['default_rate'];
	}

	/* ─────────────────────────────────────────────────────────────
	 * Policies (SET-5)
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * @return array{cancellation:string, terms:string}
	 */
	public static function get_policies() {
		$stored = get_option( self::OPTION_POLICIES, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'cancellation' => isset( $stored['cancellation'] ) ? (string) $stored['cancellation'] : '',
			'terms'        => isset( $stored['terms'] )        ? (string) $stored['terms']        : '',
		);
	}

	/**
	 * Both policy bodies sanitize via wp_kses_post — light formatting is welcome
	 * (lists, bold, links) but scripts/iframes/style are blocked.
	 *
	 * @param array{cancellation?:string, terms?:string} $policies
	 * @return bool
	 */
	public static function update_policies( array $policies ) {
		$next = array(
			'cancellation' => isset( $policies['cancellation'] ) ? wp_kses_post( (string) $policies['cancellation'] ) : '',
			'terms'        => isset( $policies['terms'] )        ? wp_kses_post( (string) $policies['terms'] )        : '',
		);

		return (bool) update_option( self::OPTION_POLICIES, $next, false );
	}
}
