<?php
/* RETIRED C8 — Linked Event rail card retired; event-anchor header in
   class-eem-reservation-editor-page.php takes over all linked-event
   affordances (Change Event button + inline typeahead). This file is
   no longer required from the editor page controller. Preserved for
   archaeology / rollback reference. */

/**
 * Reservation Editor — right-rail Linked Event card.
 *
 * C7.X.15 Issue 7 — RESTORED (partial reversal of C7.X.12 Item 7).
 * Whitney's walkthrough decision: hybrid placement — meta-line
 * reverts to read-only context, actionable linked-event controls
 * return to the right rail. Rationale: meta-line action links
 * cluttered the workflow-first signal; WP admin convention puts
 * post-meta in the right rail; "first step" workflow signal
 * benefits from the rail card being prominent.
 *
 * Differences from the pre-C7.X.12 (original C7.X.3) shape:
 *   - "Change" affordance is now an explicit text link (was implicit
 *     via the typeahead behavior alone — admins didn't realize
 *     they could change).
 *   - "Unlink" is a terse icon-only ✕ button with aria-label +
 *     hover tooltip (was the verbose "✕ Unlink" word). Per Whitney's
 *     spec: change is the regular action; unlink is the rare action
 *     and benefits from minimal visual weight.
 *
 * Structure:
 *   .eem-rail-card > .eem-rail-header "Linked Event"
 *                  > .eem-rail-body
 *                      > .eem-rail-hint copy
 *                      > .eem-event-search typeahead input (inline)
 *                      > .eem-event-linked display block (if linked):
 *                          - .eem-event-linked-name
 *                          - .eem-event-linked-date
 *                          - .eem-event-linked-actions:
 *                              - a "Change" text link
 *                              - button.eem-event-unlink-icon "×"
 *
 * Click handlers (admin.js):
 *   - data-eem-action="reservation-editor-event-change" — opens the
 *     typeahead focus (or future modal). Today: confirms unlink then
 *     reloads so admin can pick from the inline typeahead.
 *   - data-eem-action="reservation-editor-event-unlink" — confirm
 *     then dispatch the existing AJAX unlink handler.
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
$display_title  = '' !== $venue && '' !== $title ? $venue . ' – ' . $title : $title;
$has_link       = '' !== $title;
?>
<div class="eem-rail-card">
	<div class="eem-rail-header">
		<span class="eem-rail-title"><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?></span>
	</div>
	<div class="eem-rail-body">
		<p class="eem-rail-hint"><?php esc_html_e( 'Link this reservation to an event from your active event source. The reservation title and dates mirror the linked event.', 'equine-event-manager' ); ?></p>
		<input class="eem-event-search" type="text" placeholder="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-event-search" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>" autocomplete="off" />
		<?php if ( $has_link ) : ?>
			<div class="eem-event-linked">
				<div class="eem-event-linked-meta" style="min-width:0">
					<div class="eem-event-linked-name"><a href="#"><?php echo esc_html( $display_title ); ?></a></div>
					<?php if ( '' !== $date_range ) : ?>
						<div class="eem-event-linked-date"><?php echo esc_html( $date_range ); ?></div>
					<?php endif; ?>
					<div class="eem-event-linked-actions">
						<a href="#" class="eem-event-linked-change" data-eem-action="reservation-editor-event-change" data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"><?php esc_html_e( 'Change', 'equine-event-manager' ); ?></a>
						<button type="button" class="eem-event-unlink-icon" aria-label="<?php esc_attr_e( 'Unlink event', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Unlink event', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-event-unlink" data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
