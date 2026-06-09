<?php
/**
 * Migration #010 — normalize dotted activity-log event types to underscores
 * (CLEANUP #31).
 *
 * `EEM_Activity_Log::write()` runs `sanitize_key()` on the event type, which
 * strips dots — so code that wrote `order.create` / `order.refund` /
 * `order.payment_received` / `order.status_change` / `order.email_sent` actually
 * persisted the flat forms `ordercreate` / `orderrefund` / etc. The write sites
 * now use underscore names (`order_create`, …) that survive `sanitize_key`
 * unchanged; this migration rewrites the historical flat rows to match so a
 * future query-by-event-type sees a single consistent taxonomy.
 *
 * Idempotent / flag-gated. Only the five legacy flat forms are remapped; rows
 * already using underscores (or unrelated event types) are untouched.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite legacy flat-sanitized event types to their underscore form.
 *
 * @return array{updated:int} Rows updated (for telemetry/verification).
 */
function eem_mig_010_activity_event_type_underscores() {
	global $wpdb;

	$flag = 'eem_mig_010_activity_event_type_underscores_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$table = $wpdb->prefix . 'en_activity_log';

	$map = array(
		'ordercreate'           => 'order_create',
		'orderrefund'           => 'order_refund',
		'orderpayment_received' => 'order_payment_received',
		'orderstatus_change'    => 'order_status_change',
		'orderemail_sent'       => 'order_email_sent',
	);

	$updated = 0;
	foreach ( $map as $old => $new ) {
		$updated += (int) $wpdb->update(
			$table,
			array( 'event_type' => $new ),
			array( 'event_type' => $old ),
			array( '%s' ),
			array( '%s' )
		);
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
