<?php
/**
 * Activity Log render partial (ODET-7 + CDET-5).
 *
 * Renders a chronological list of EEM_Activity_Log entries with the
 * mockup's colored-icon visual (create=green, edit=amber, info=blue,
 * refund=red, etc.). Caller fetches the entries; this partial only
 * draws the HTML.
 *
 * Usage:
 *   $entries = EEM_Activity_Log::get_for_order( $order_id );
 *   eem_render_activity_log( $entries );
 *
 * Entry payload contract (all optional, all fall back to type defaults):
 *   [
 *       'title' => 'Order edited by Whitney Mitchell (admin)',
 *       'meta'  => 'Shavings Qty: 2 → 4 · +$20.00 balance owed',
 *       'diff'  => [...]  // free-form, ignored by render but available to callers
 *   ]
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_activity_log' ) ) {
	/**
	 * Render an array of activity log entries.
	 *
	 * @param array $entries Rows from EEM_Activity_Log::get_for_order/_reservation.
	 * @param array $args {
	 *     @type string $empty_message Optional. Copy shown when entries is empty.
	 * }
	 * @return void
	 */
	function eem_render_activity_log( array $entries, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'empty_message' => __( 'No activity recorded yet.', 'equine-event-manager' ),
			)
		);

		if ( empty( $entries ) ) {
			printf(
				'<p class="eem-activity-log-empty">%s</p>',
				esc_html( $args['empty_message'] )
			);
			return;
		}

		echo '<ul class="eem-activity-log">';
		foreach ( $entries as $entry ) {
			eem_render_activity_log_entry( $entry );
		}
		echo '</ul>';
	}
}

if ( ! function_exists( 'eem_render_activity_log_entry' ) ) {
	/**
	 * Render a single activity log entry.
	 *
	 * @param array $entry Row from EEM_Activity_Log.
	 * @return void
	 */
	function eem_render_activity_log_entry( array $entry ) {
		$event_type = isset( $entry['event_type'] ) ? (string) $entry['event_type'] : '';
		$payload    = isset( $entry['payload'] ) && is_array( $entry['payload'] ) ? $entry['payload'] : array();
		$actor      = isset( $entry['actor_label'] ) ? (string) $entry['actor_label'] : '';
		$actor_type = isset( $entry['actor_type'] ) ? (string) $entry['actor_type'] : 'system';
		$created_at = isset( $entry['created_at'] ) ? (string) $entry['created_at'] : '';

		$variant = eem_activity_log_variant_for( $event_type );
		$title   = ! empty( $payload['title'] ) ? (string) $payload['title'] : eem_activity_log_default_title( $event_type, $actor, $actor_type );
		$meta    = ! empty( $payload['meta'] ) ? (string) $payload['meta'] : '';
		$stamp   = eem_activity_log_format_stamp( $created_at );

		?>
		<li class="eem-activity-log-entry eem-activity-log-entry--<?php echo esc_attr( $variant ); ?>">
			<span class="eem-activity-log-icon" aria-hidden="true"><?php echo esc_html( eem_activity_log_glyph_for( $variant ) ); ?></span>
			<div class="eem-activity-log-body">
				<div class="eem-activity-log-title"><?php echo esc_html( $title ); ?></div>
				<?php if ( '' !== $meta ) : ?>
					<div class="eem-activity-log-meta"><?php echo esc_html( $meta ); ?></div>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $stamp['display'] ) : ?>
				<time class="eem-activity-log-stamp" datetime="<?php echo esc_attr( $stamp['iso'] ); ?>"><?php echo esc_html( $stamp['display'] ); ?></time>
			<?php endif; ?>
		</li>
		<?php
	}
}

if ( ! function_exists( 'eem_activity_log_variant_for' ) ) {
	/**
	 * Map event type → visual variant (drives icon color).
	 *
	 * @param string $event_type Event type constant value.
	 * @return string One of: create, edit, info, refund, assignment, notification.
	 */
	function eem_activity_log_variant_for( $event_type ) {
		$map = array(
			'order_created'               => 'create',
			'order_edited'                => 'edit',
			'status_changed'              => 'info',
			'refund_processed'            => 'refund',
			'assignment_changed'          => 'assignment',
			'notification_sent'           => 'notification',
			'special_instructions_edited' => 'edit',
		);

		/**
		 * Filter the icon variant for a given event type. Add-ons that introduce
		 * custom event types can map them onto an existing variant or supply a
		 * new one (the CSS will need a matching .eem-activity-log-entry--xyz rule).
		 */
		return apply_filters(
			'eem_activity_log_variant',
			isset( $map[ $event_type ] ) ? $map[ $event_type ] : 'info',
			$event_type
		);
	}
}

if ( ! function_exists( 'eem_activity_log_glyph_for' ) ) {
	/**
	 * Single-character glyph rendered inside the colored icon container.
	 * Plain ASCII so it prints anywhere without needing an icon font.
	 *
	 * @param string $variant Variant key from eem_activity_log_variant_for().
	 * @return string
	 */
	function eem_activity_log_glyph_for( $variant ) {
		switch ( $variant ) {
			case 'create':       return '+';
			case 'edit':         return '~';
			case 'refund':       return '$';
			case 'assignment':   return '⇄';
			case 'notification': return '@';
			case 'info':
			default:             return 'i';
		}
	}
}

if ( ! function_exists( 'eem_activity_log_default_title' ) ) {
	/**
	 * Fallback title when the caller didn't include one in payload.
	 *
	 * @param string $event_type Event type.
	 * @param string $actor      Actor display name.
	 * @param string $actor_type 'admin' | 'customer' | 'system'.
	 * @return string
	 */
	function eem_activity_log_default_title( $event_type, $actor, $actor_type ) {
		$by = '';
		if ( '' !== $actor ) {
			$by = sprintf(
				/* translators: 1: actor name, 2: actor type label. */
				__( ' by %1$s (%2$s)', 'equine-event-manager' ),
				$actor,
				$actor_type
			);
		}

		$titles = array(
			'order_created'               => __( 'Order created', 'equine-event-manager' ),
			'order_edited'                => __( 'Order edited', 'equine-event-manager' ),
			'status_changed'              => __( 'Status changed', 'equine-event-manager' ),
			'refund_processed'            => __( 'Refund processed', 'equine-event-manager' ),
			'assignment_changed'          => __( 'Assignment changed', 'equine-event-manager' ),
			'notification_sent'           => __( 'Notification sent', 'equine-event-manager' ),
			'special_instructions_edited' => __( 'Special instructions edited', 'equine-event-manager' ),
		);

		$base = isset( $titles[ $event_type ] ) ? $titles[ $event_type ] : __( 'Activity', 'equine-event-manager' );

		return $base . $by;
	}
}

if ( ! function_exists( 'eem_activity_log_format_stamp' ) ) {
	/**
	 * Format a stored UTC datetime into a display string + ISO attribute value.
	 *
	 * @param string $created_at MySQL DATETIME in UTC.
	 * @return array{ display: string, iso: string }
	 */
	function eem_activity_log_format_stamp( $created_at ) {
		if ( '' === $created_at ) {
			return array( 'display' => '', 'iso' => '' );
		}

		$timestamp = strtotime( $created_at . ' UTC' );
		if ( false === $timestamp ) {
			return array( 'display' => '', 'iso' => '' );
		}

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		return array(
			'display' => wp_date( $date_format . ' · ' . $time_format, $timestamp ),
			'iso'     => gmdate( 'c', $timestamp ),
		);
	}
}
