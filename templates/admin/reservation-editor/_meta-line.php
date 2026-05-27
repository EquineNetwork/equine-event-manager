<?php
/**
 * Reservation Editor meta-line (C7.X.15 — read-only after Item 7
 * hybrid restoration).
 *
 * History:
 *   C7.X.3   — read-only meta-line + Linked Event rail card (original)
 *   C7.X.12  — meta-line gains (change) + (unlink) action links;
 *              rail card retired (Item 7)
 *   C7.X.15  — meta-line reverts to read-only context (Issue 7
 *              hybrid restoration); rail card restored with
 *              actionable controls. See SESSION-NOTES C7.X.15
 *              "Item 7 reversal rationale" for the why.
 *
 * Two-column inline meta strip under .eem-plugin-title / -subtitle.
 * Pure read-only context for the workflow-first signal.
 *
 *   [Linked Event]   Perry, GA – 2026 Southeast Region Super Sort
 *   [Event Dates]    May 8, 2026 – May 10, 2026
 *
 * Actionable affordances (change / unlink / link) live in the rail
 * Linked Event card.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 *
 * Expects $reservation_id (int) in scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $reservation_id ) ) {
	return;
}

$reservation_id = (int) $reservation_id;
$fields         = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
$title          = isset( $fields['title'] ) ? (string) $fields['title'] : '';
$venue          = isset( $fields['venue'] ) ? (string) $fields['venue'] : '';
$date_range     = EEM_Dashboard_Repo::format_date_range(
	isset( $fields['start_date'] ) ? (string) $fields['start_date'] : '',
	isset( $fields['end_date'] ) ? (string) $fields['end_date'] : ''
);
$has_link       = '' !== $title;
$display_title  = '' !== $venue && $has_link ? $venue . ' – ' . $title : ( $has_link ? $title : __( '(no event linked)', 'equine-event-manager' ) );
?>
<div class="eem-plugin-meta-line">
	<span>
		<span class="eem-meta-label"><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?></span>
		<span class="eem-meta-value"><?php echo esc_html( $display_title ); ?></span>
	</span>
	<span>
		<span class="eem-meta-label"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></span>
		<span class="eem-meta-value"><?php echo esc_html( '' !== $date_range ? $date_range : '—' ); ?></span>
	</span>
</div>
