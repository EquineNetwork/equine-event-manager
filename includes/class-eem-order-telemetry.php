<?php
/**
 * Order activity-log auto-fire telemetry (C6.D).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for the C6.D telemetry actions and writes corresponding
 * EEM_Activity_Log entries. Centralises the "what does an order event
 * look like in the log?" decision so individual emitters (shortcodes,
 * orders repository, mailer) don't need to know the log-entry format.
 *
 * Event sources covered in C6.D:
 *   - `eem_order_created`              — emitted by EEM_Shortcodes::insert_reservation_orders
 *                                        after successful checkout + duplicate-guard pass.
 *                                        Writes `order.create`.
 *   - `eem_order_payment_status_changed` — emitted by EEM_Orders_Repository at
 *                                          update_order_payment_details + mark_order_paid_manually.
 *                                          Decision tree inside the listener splits to either
 *                                          `order.payment_received` (outstanding → paid)
 *                                          or `order.status_change` (everything else).
 *   - `eem_email_sent`                 — emitted by EEM_Mailer::send_html_email after
 *                                        a successful send. Writes `order.email_sent`
 *                                        when the context payload identifies an order.
 *
 * Explicitly NOT covered here:
 *   - Refund completion — already written inside EEM_Admin::process_amount_refund's
 *     kernel block (added in C6.C). C6.D's smoke includes a regression assertion
 *     that exactly one `order.refund` log entry fires per process_amount_refund
 *     call. Adding a second listener here would duplicate the entry.
 *   - save_meta diff logging on the reservation CPT — deferred to CLEANUP #26.
 *   - Refund-notify email send for the C6.B notify checkbox — deferred to CLEANUP #30
 *     (template + transport land with C11 SendGrid work).
 *
 * @since 2.2.0 (C6.D)
 */
class EEM_Order_Telemetry {

	/**
	 * Outstanding-balance status set. Status changing FROM one of these TO
	 * `paid` is treated as a payment-received event; any other transition
	 * is a generic status_change event.
	 */
	const OUTSTANDING_STATUSES = array( 'unpaid', 'invoice-sent', 'invoice_sent', 'partially-paid', 'partially_paid' );

	/**
	 * Register the three listener hooks. Wired from the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'eem_order_created',                array( __CLASS__, 'on_order_created' ),                10, 1 );
		add_action( 'eem_order_payment_status_changed', array( __CLASS__, 'on_payment_status_changed' ),       10, 1 );
		add_action( 'eem_email_sent',                   array( __CLASS__, 'on_email_sent' ),                   10, 1 );
	}

	/**
	 * Listener: `eem_order_created`. Emitted by
	 * EEM_Shortcodes::insert_reservation_orders after a successful
	 * checkout submission (duplicate-submission tokens short-circuit
	 * the emitter, so this listener never fires twice for the same
	 * submission_token — verified by C6.D smoke).
	 *
	 * @param array<string,mixed> $payload Hook payload (see emitter for keys).
	 * @return void
	 */
	public static function on_order_created( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['order_key'] ) ) {
			return;
		}

		EEM_Activity_Log::write(
			'order.create',
			array(
				'order_key'      => (string) $payload['order_key'],
				'order_number'   => isset( $payload['order_number'] ) ? (string) $payload['order_number'] : '',
				'customer_email' => isset( $payload['customer_email'] ) ? (string) $payload['customer_email'] : '',
				'customer_name'  => isset( $payload['customer_name'] ) ? (string) $payload['customer_name'] : '',
				'payment_status' => isset( $payload['payment_status'] ) ? (string) $payload['payment_status'] : '',
				'total'          => isset( $payload['total'] ) ? (float) $payload['total'] : 0.0,
				'event_label'    => isset( $payload['event_label'] ) ? (string) $payload['event_label'] : '',
				'source'         => isset( $payload['source'] ) ? (string) $payload['source'] : 'unknown',
				'created_at'     => isset( $payload['created_at'] ) ? (string) $payload['created_at'] : '',
			),
			array(
				'actor_type'  => 'customer',
				'actor_label' => isset( $payload['customer_name'] ) ? (string) $payload['customer_name'] : '',
			)
		);
	}

	/**
	 * Listener: `eem_order_payment_status_changed`. Funnel handler — the
	 * decision tree splits transitions into payment_received vs.
	 * status_change based on the (old → new) status pair.
	 *
	 * Outstanding → paid  = order.payment_received
	 * Anything else       = order.status_change
	 *
	 * The `source` field on the payload is the attribution channel that
	 * lets post-hoc forensics tell a gateway-success transition from an
	 * admin-mark-paid from an anomalous "future caller flipped status
	 * without a known cause" path (per C6.D implementation note #1).
	 *
	 * @param array<string,mixed> $payload Hook payload.
	 * @return void
	 */
	public static function on_payment_status_changed( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['order_key'] ) ) {
			return;
		}

		$old_status = isset( $payload['old_status'] ) ? (string) $payload['old_status'] : '';
		$new_status = isset( $payload['new_status'] ) ? (string) $payload['new_status'] : '';

		// No-op if status didn't actually change.
		if ( $old_status === $new_status ) {
			return;
		}

		$is_payment_received = (
			'paid' === $new_status
			&& in_array( $old_status, self::OUTSTANDING_STATUSES, true )
		);

		$event_type = $is_payment_received ? 'order.payment_received' : 'order.status_change';

		EEM_Activity_Log::write(
			$event_type,
			array(
				'order_key'  => (string) $payload['order_key'],
				'old_status' => $old_status,
				'new_status' => $new_status,
				'source'     => isset( $payload['source'] ) ? (string) $payload['source'] : 'unknown',
				'gateway'    => isset( $payload['gateway'] ) ? (string) $payload['gateway'] : '',
				'transaction_id' => isset( $payload['transaction_id'] ) ? (string) $payload['transaction_id'] : '',
			),
			array(
				'actor_type'  => isset( $payload['actor_type'] ) ? (string) $payload['actor_type'] : 'system',
				'actor_id'    => isset( $payload['actor_id'] ) ? (int) $payload['actor_id'] : null,
				'actor_label' => isset( $payload['actor_label'] ) ? (string) $payload['actor_label'] : '',
			)
		);
	}

	/**
	 * Listener: `eem_email_sent`. Emitted by EEM_Mailer::send_html_email
	 * after a wp_mail() that returned true. Writes an `order.email_sent`
	 * activity entry when the context payload identifies an order; for
	 * non-order emails (e.g. test-email from Settings > Communications)
	 * the listener silently skips — there's no order context to attach
	 * the entry to.
	 *
	 * @param array<string,mixed> $payload Hook payload.
	 * @return void
	 */
	public static function on_email_sent( $payload ) {
		if ( ! is_array( $payload ) ) {
			return;
		}

		$context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();

		// Order context required — non-order emails (test-email, etc.) skip.
		$order_key = isset( $context['order_key'] ) ? (string) $context['order_key'] : '';
		if ( '' === $order_key ) {
			return;
		}

		EEM_Activity_Log::write(
			'order.email_sent',
			array(
				'order_key' => $order_key,
				'type'      => isset( $context['type'] ) ? (string) $context['type'] : 'unknown',
				'to'        => isset( $payload['to'] ) ? (string) $payload['to'] : '',
				'subject'   => isset( $payload['subject'] ) ? (string) $payload['subject'] : '',
			),
			array(
				'actor_type'  => 'system',
				'actor_label' => '',
			)
		);
	}
}
