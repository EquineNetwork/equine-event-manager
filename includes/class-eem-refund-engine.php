<?php
/**
 * EEM_Refund_Engine — the refund kernel (CLEANUP #27).
 *
 * Owns gateway-dispatch (Stripe + Authorize.net), per-component amount
 * distribution, and refund bookkeeping/persistence. Extracted verbatim from
 * EEM_Admin (behavior-preserving) so the refund logic lives in one focused,
 * testable type. EEM_Admin keeps thin 1-line delegators for backward
 * compatibility, so the single-refund AJAX path, the itemized-refund path, and
 * the bulk-refund path all keep calling the same EEM_Admin method names.
 *
 * Four stateless EEM_Admin helpers the kernel needs (get_payment_settings,
 * get_order_note_value, get_component_refunded_item_ids, upsert_order_note_line)
 * remain on EEM_Admin (they have many non-refund callers) and are reached here
 * via the injected \$admin reference.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_Refund_Engine {

	/** @var EEM_Admin Host admin instance, for the shared stateless helpers. */
	private $admin;

	/** @var EEM_Orders_Repository Orders repo used for component persistence + reloads. */
	private $orders_repository;

	/**
	 * @param EEM_Admin $admin Host admin instance (provides the shared helpers).
	 */
	public function __construct( $admin ) {
		$this->admin             = $admin;
		$this->orders_repository = new EEM_Orders_Repository();
	}

	public function get_component_refunded_amount( $component ) {
		$notes = isset( $component['notes'] ) ? (string) $component['notes'] : '';
		$value = $this->admin->get_order_note_value( $notes, 'Refunded Amount' );

		if ( '' !== $value ) {
			return max( 0, (float) preg_replace( '/[^0-9.\-]/', '', $value ) );
		}

		if ( isset( $component['payment_status'] ) && 'refunded' === $component['payment_status'] ) {
			return isset( $component['total'] ) ? max( 0, (float) $component['total'] ) : 0.0;
		}

		return 0.0;
	}

	public function get_component_remaining_refundable_amount( $component ) {
		$total_amount    = isset( $component['total'] ) ? max( 0, (float) $component['total'] ) : 0.0;
		$refunded_amount = $this->get_component_refunded_amount( $component );

		return max( 0, $total_amount - $refunded_amount );
	}

	public function persist_component_refund( $component, $refund_amount, $refund_transaction_id, $refunded_item_ids ) {
		$current_refunded_amount = $this->get_component_refunded_amount( $component );
		$new_refunded_amount     = max( 0, $current_refunded_amount + (float) $refund_amount );
		$total_amount            = isset( $component['total'] ) ? max( 0, (float) $component['total'] ) : 0.0;
		$new_status              = $new_refunded_amount + 0.009 >= $total_amount ? 'refunded' : 'partially_refunded';
		$existing_item_ids       = $this->admin->get_component_refunded_item_ids( $component );
		$merged_item_ids         = array_values( array_unique( array_filter( array_merge( $existing_item_ids, $refunded_item_ids ) ) ) );
		$notes                   = isset( $component['notes'] ) ? (string) $component['notes'] : '';
		$notes                   = $this->admin->upsert_order_note_line( $notes, 'Refunded Amount', number_format( $new_refunded_amount, 2, '.', '' ) );
		$notes                   = $this->admin->upsert_order_note_line( $notes, 'Refunded Items', implode( ', ', $merged_item_ids ) );
		$notes                   = $this->admin->upsert_order_note_line( $notes, 'Last Refund Transaction', sanitize_text_field( $refund_transaction_id ) );
		$notes                   = $this->admin->upsert_order_note_line( $notes, 'Last Refunded At', current_time( 'mysql' ) );

		return $this->orders_repository->update_component_fields(
			$component['table'],
			$component['row_id'],
			array(
				'payment_status'        => $new_status,
				'refund_transaction_id' => sanitize_text_field( $refund_transaction_id ),
				'refunded_at'           => current_time( 'mysql' ),
				'notes'                 => $notes,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function process_amount_refund( $order_key, $requested_amount, $reason = '' ) {
		$order = $this->orders_repository->get_order( $order_key );

		if ( ! $order ) {
			return new WP_Error( 'order_not_found', __( 'Order not found.', 'equine-event-manager' ) );
		}

		$requested_amount = (float) $requested_amount;

		if ( $requested_amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Refund amount must be greater than zero.', 'equine-event-manager' ) );
		}

		$total_remaining = 0.0;
		$components      = isset( $order['components'] ) && is_array( $order['components'] ) ? $order['components'] : array();

		foreach ( $components as $component ) {
			$total_remaining += $this->get_component_remaining_refundable_amount( $component );
		}

		// Float-tolerant comparison — accept amounts within $0.01 of the
		// remaining balance (handles UI rounding without rejecting valid
		// "refund full remaining" requests).
		if ( $requested_amount > $total_remaining + 0.009 ) {
			return new WP_Error(
				'exceeds_remaining',
				sprintf(
					/* translators: 1: requested amount, 2: remaining refundable */
					__( 'Refund amount $%1$s exceeds the remaining refundable balance of $%2$s.', 'equine-event-manager' ),
					number_format( $requested_amount, 2 ),
					number_format( $total_remaining, 2 )
				)
			);
		}

		$remaining_to_refund   = $requested_amount;
		$refunded_components   = array();

		foreach ( $components as $component ) {
			if ( $remaining_to_refund <= 0.009 ) {
				break;
			}

			$component_available = $this->get_component_remaining_refundable_amount( $component );
			if ( $component_available <= 0.009 ) {
				continue;
			}

			$component_refund_amount = min( $remaining_to_refund, $component_available );

			$refund_result = $this->refund_order_component( $component, $component_refund_amount );
			if ( is_wp_error( $refund_result ) ) {
				return $refund_result;
			}

			$persisted = $this->persist_component_refund( $component, $component_refund_amount, $refund_result, array() );
			if ( ! $persisted ) {
				return new WP_Error(
					'persist_failed',
					__( 'The refund was sent to the gateway, but the order record could not be updated afterward.', 'equine-event-manager' )
				);
			}

			$refunded_components[] = array(
				'transaction_id' => (string) $refund_result,
				'amount'         => $component_refund_amount,
				'table'          => isset( $component['table'] ) ? (string) $component['table'] : '',
				'row_id'         => isset( $component['row_id'] ) ? (int) $component['row_id'] : 0,
			);

			$remaining_to_refund -= $component_refund_amount;
		}

		// Reload to capture the recomputed payment_status across components.
		$updated_order = $this->orders_repository->get_order( $order_key );

		// C6.C prep — activity-log write moved into the kernel so both
		// single-order (C6.B) and bulk (C6.C) callers get telemetry for
		// free. Pre-C6.C this lived in handle_ajax_refund_single only.
		if ( class_exists( 'EEM_Activity_Log' ) ) {
			$current_user = wp_get_current_user();
			EEM_Activity_Log::write(
				'order_refund',
				array(
					'order_key'        => $order_key,
					'amount'           => $requested_amount,
					'reason'           => $reason,
					'components'       => $refunded_components,
					'new_status_slug'  => isset( $updated_order['status_slug'] ) ? (string) $updated_order['status_slug'] : 'partially-refunded',
				),
				array(
					'actor_type'  => 'user',
					'actor_id'    => (int) get_current_user_id(),
					'actor_label' => $current_user ? (string) $current_user->display_name : '',
				)
			);
		}

		return array(
			'refunded_amount'  => $requested_amount,
			'components'       => $refunded_components,
			'new_status_slug'  => isset( $updated_order['status_slug'] ) ? (string) $updated_order['status_slug'] : 'partially-refunded',
			'new_status_label' => isset( $updated_order['status_label'] ) ? (string) $updated_order['status_label'] : __( 'Partially Refunded', 'equine-event-manager' ),
			'reason'           => $reason,
		);
	}

	public function refund_order_component( $component, $amount = 0.0 ) {
		if ( 'stripe' === $component['payment_gateway'] ) {
			return $this->refund_with_stripe( $component, $amount );
		}

		if ( 'authorize_net' === $component['payment_gateway'] ) {
			return $this->refund_with_authorize_net( $component, $amount );
		}

		return new WP_Error( 'unsupported_gateway', __( 'This order does not have a supported payment gateway for refunds yet.', 'equine-event-manager' ) );
	}

	public function refund_with_stripe( $component, $amount = 0.0 ) {
		$settings = $this->admin->get_payment_settings();
		$stripe   = $settings['stripe'];
		$secret   = 'live' === $stripe['mode'] ? $stripe['live_secret_key'] : $stripe['test_secret_key'];

		if ( empty( $secret ) ) {
			return new WP_Error( 'missing_credentials', __( 'Stripe credentials are missing.', 'equine-event-manager' ) );
		}

		$body = array();

		if ( 0 === strpos( $component['transaction_id'], 'pi_' ) ) {
			$body['payment_intent'] = $component['transaction_id'];
		} else {
			$body['charge'] = $component['transaction_id'];
		}

		if ( $amount > 0 ) {
			$body['amount'] = max( 1, (int) round( $amount * 100 ) );
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/refunds',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 300 || empty( $payload['id'] ) ) {
			$message = ! empty( $payload['error']['message'] ) ? $payload['error']['message'] : __( 'Stripe refund failed.', 'equine-event-manager' );
			return new WP_Error( 'stripe_refund_failed', $message );
		}

		return sanitize_text_field( $payload['id'] );
	}

	public function refund_with_authorize_net( $component, $amount = 0.0 ) {
		$remaining_amount = $this->get_component_remaining_refundable_amount( $component );

		if ( $amount > 0 && abs( $remaining_amount - $amount ) > 0.009 ) {
			return new WP_Error( 'authorize_partial_refund_unsupported', __( 'Authorize.net refunds currently need to be refunded as a full charged component in the admin.', 'equine-event-manager' ) );
		}

		$settings      = $this->admin->get_payment_settings();
		$authorize_net = $settings['authorize_net'];
		$login_id      = 'live' === $authorize_net['mode'] ? $authorize_net['live_api_login'] : $authorize_net['test_api_login'];
		$transaction_key = 'live' === $authorize_net['mode'] ? $authorize_net['live_transaction_key'] : $authorize_net['test_transaction_key'];

		if ( empty( $login_id ) || empty( $transaction_key ) ) {
			return new WP_Error( 'missing_credentials', __( 'Authorize.net credentials are missing.', 'equine-event-manager' ) );
		}

		$endpoint = 'live' === $authorize_net['mode']
			? 'https://api.authorize.net/xml/v1/request.api'
			: 'https://apitest.authorize.net/xml/v1/request.api';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'createTransactionRequest' => array(
							'merchantAuthentication' => array(
								'name'           => $login_id,
								'transactionKey' => $transaction_key,
							),
							'transactionRequest'     => array(
								'transactionType' => 'voidTransaction',
								'refTransId'      => $component['transaction_id'],
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$result  = isset( $payload['transactionResponse']['responseCode'] ) ? (string) $payload['transactionResponse']['responseCode'] : '';

		if ( '1' !== $result ) {
			$message = ! empty( $payload['messages']['message'][0]['text'] ) ? $payload['messages']['message'][0]['text'] : __( 'Authorize.net refund/void failed. Settled refunds may require more card detail storage.', 'equine-event-manager' );
			return new WP_Error( 'authorize_refund_failed', $message );
		}

		return ! empty( $payload['transactionResponse']['transId'] ) ? sanitize_text_field( $payload['transactionResponse']['transId'] ) : 'authorize-net-refund';
	}
}
