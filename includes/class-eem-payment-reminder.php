<?php
/**
 * Auto payment-reminder for unpaid orders (ROADMAP #23).
 *
 * A daily WP-cron sweep that finds unpaid / invoice-sent orders older than a
 * configurable age and re-sends the SAME branded payment-link email the admin
 * "Send Payment Link" button uses (EEM_Admin::send_invoice_email_for_order — the
 * hotel-style header/footer + "Click here to pay" template), so a customer never
 * gets a different-looking email. Dedupe is driven by the "Invoice Sent At" note
 * that send_invoice_email_for_order already stamps, so an order isn't reminded
 * again within the repeat window (and a freshly-manually-invoiced order is left
 * alone too).
 *
 * SAFETY: ships DISABLED. The cron is scheduled but no-ops until the
 * `eem_payment_reminder_enabled` option is turned on — auto-customer-email must be
 * an explicit opt-in. Reads payment_status only; never touches payment dispatch.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily unpaid-order payment-reminder sweep. All-static.
 */
class EEM_Payment_Reminder {

	/**
	 * WP-cron hook for the daily sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'eem_payment_reminder_sweep';

	/**
	 * Master on/off option. Defaults OFF — no auto-emails until explicitly enabled.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'eem_payment_reminder_enabled';

	/**
	 * Option: minimum order age (days) before a first reminder. Default 3.
	 *
	 * @var string
	 */
	const OPTION_MIN_AGE = 'eem_payment_reminder_min_age_days';

	/**
	 * Option: minimum days between reminders for the same order (0 = once only).
	 * Default 7.
	 *
	 * @var string
	 */
	const OPTION_REPEAT = 'eem_payment_reminder_repeat_days';

	/**
	 * Safety cap on reminders sent per sweep run.
	 *
	 * @var int
	 */
	const MAX_PER_RUN = 200;

	/**
	 * Order statuses eligible for a reminder.
	 *
	 * @var string[]
	 */
	const ELIGIBLE_STATUSES = array( 'unpaid', 'invoice-sent' );

	/**
	 * Bind the cron handler. Idempotent.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sweep' ) );
	}

	/**
	 * Ensure the daily sweep is scheduled. Safe to call on every activation.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled sweep (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Is the reminder feature turned on?
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, 0 );
	}

	/**
	 * Minimum order age (days) before the first reminder.
	 *
	 * @return int
	 */
	public static function min_age_days(): int {
		return max( 0, (int) get_option( self::OPTION_MIN_AGE, 3 ) );
	}

	/**
	 * Minimum days between reminders for one order (0 = remind once, never repeat).
	 *
	 * @return int
	 */
	public static function repeat_days(): int {
		return max( 0, (int) get_option( self::OPTION_REPEAT, 7 ) );
	}

	/**
	 * Timestamp of the last payment-link contact for an order, parsed from the
	 * "Invoice Sent At" note across the order's components (0 if never contacted).
	 *
	 * @param array<string,mixed> $order Grouped order payload.
	 * @return int Unix timestamp, or 0.
	 */
	public static function last_contact_ts( array $order ): int {
		$latest = 0;
		foreach ( (array) ( $order['components'] ?? array() ) as $component ) {
			$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';
			if ( '' === $notes ) {
				continue;
			}
			if ( preg_match( '/^\s*Invoice Sent At:\s*(.+)$/mi', $notes, $m ) ) {
				$ts = strtotime( trim( $m[1] ) );
				if ( $ts && $ts > $latest ) {
					$latest = $ts;
				}
			}
		}
		return $latest;
	}

	/**
	 * Is this order due for a reminder right now? Pure (testable) — no side effects.
	 *
	 * @param array<string,mixed> $order  Grouped order payload.
	 * @param int                 $now_ts Reference "now" timestamp.
	 * @return bool
	 */
	public static function is_due( array $order, int $now_ts ): bool {
		$status = (string) ( $order['status_slug'] ?? ( $order['payment_status'] ?? '' ) );
		$status = str_replace( '_', '-', $status );
		if ( ! in_array( $status, self::ELIGIBLE_STATUSES, true ) ) {
			return false;
		}
		$email = (string) ( $order['email'] ?? '' );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		$created = isset( $order['created_at'] ) ? strtotime( (string) $order['created_at'] ) : 0;
		if ( ! $created ) {
			return false;
		}
		// Too new for a first reminder.
		if ( $created > $now_ts - ( self::min_age_days() * DAY_IN_SECONDS ) ) {
			return false;
		}
		$last = self::last_contact_ts( $order );
		if ( $last > 0 ) {
			$repeat = self::repeat_days();
			if ( 0 === $repeat ) {
				return false; // already contacted once; never repeat.
			}
			if ( $last > $now_ts - ( $repeat * DAY_IN_SECONDS ) ) {
				return false; // contacted too recently.
			}
		}
		return true;
	}

	/**
	 * Send one reminder by reusing the canonical payment-link email. The send path
	 * stamps "Invoice Sent At" (our dedupe marker) and flips the order to
	 * invoice-sent, exactly like the manual "Send Payment Link" button.
	 *
	 * @param array<string,mixed> $order Grouped order payload.
	 * @param EEM_Admin|null       $admin Optional shared admin instance.
	 * @return bool True on a successful send.
	 */
	public static function send_reminder( array $order, ?EEM_Admin $admin = null ): bool {
		if ( ! class_exists( 'EEM_Admin' ) ) {
			return false;
		}
		$admin  = $admin ?: new EEM_Admin( true );
		$result = $admin->send_invoice_email_for_order( $order );
		return true === $result;
	}

	/**
	 * Cron handler: sweep unpaid orders and send due reminders. No-ops unless the
	 * feature is enabled.
	 *
	 * @return void
	 */
	public static function run_sweep(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! class_exists( 'EEM_Orders_Repository' ) || ! class_exists( 'EEM_Admin' ) ) {
			return;
		}

		$repo  = new EEM_Orders_Repository();
		$admin = new EEM_Admin( true );
		$now   = (int) current_time( 'timestamp' );
		$sent  = 0;

		foreach ( $repo->get_orders( '', 'date', 'asc', '' ) as $order ) {
			if ( $sent >= self::MAX_PER_RUN ) {
				break;
			}
			if ( ! self::is_due( $order, $now ) ) {
				continue;
			}
			if ( self::send_reminder( $order, $admin ) ) {
				$sent++;
			}
		}

		if ( $sent > 0 && class_exists( 'EEM_Activity_Log' ) ) {
			EEM_Activity_Log::write(
				EEM_Activity_Log::NOTIFICATION_SENT,
				array(
					'channel' => 'payment_reminder_sweep',
					'sent'    => $sent,
					'min_age' => self::min_age_days(),
					'repeat'  => self::repeat_days(),
				),
				array(
					'actor_type' => 'system',
				)
			);
		}
	}
}
