<?php
/**
 * Linked Event modal partial (C7.B.2, per Q14.b).
 *
 * Modal body: source-mode segmented control + within-source event
 * picker. Footer: Cancel / Save.
 *
 * Per Decision F: 3-button segmented control (Native / TEC / Feed).
 * Per Decision G: typeahead for native+tec, URL input for feed.
 * Per Decision H: confirmation prompt fires JS-side before AJAX
 *   dispatch when admin clicks Save.
 *
 * Initial state on open is driven from JS: launcher reads the current
 * reservation's `_en_event_source` + matching event_id meta and pre-
 * selects the segmented control + populates the picker. C7.B.2 ships
 * the markup + the AJAX endpoint that accepts the change; JS launcher +
 * picker behavior lives in admin.js.
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

// Body HTML
ob_start();
?>
<p class="eem-field-hint" style="margin-bottom: 14px;">
	<?php esc_html_e( 'Changing the linked event will trigger downstream changes (rate recalculation, stall-chart re-resolution, etc.) on next reservation save. A confirmation prompt will appear before the change is applied.', 'equine-event-manager' ); ?>
</p>

<div class="eem-modal-linked-event__source-row">
	<div class="eem-field-label"><?php esc_html_e( 'Event source', 'equine-event-manager' ); ?></div>
	<div class="eem-modal-linked-event__source-picker" role="tablist">
		<button type="button" class="eem-source-mode-btn" data-eem-source="native" role="tab"><?php esc_html_e( 'Native Event', 'equine-event-manager' ); ?></button>
		<button type="button" class="eem-source-mode-btn" data-eem-source="tec" role="tab"><?php esc_html_e( 'TEC Event', 'equine-event-manager' ); ?></button>
		<button type="button" class="eem-source-mode-btn" data-eem-source="feed" role="tab"><?php esc_html_e( 'External Feed', 'equine-event-manager' ); ?></button>
	</div>
</div>

<div class="eem-modal-linked-event__picker" data-eem-source-picker="native">
	<div class="eem-field-label" style="margin-top: 16px;">
		<?php esc_html_e( 'Select event', 'equine-event-manager' ); ?>
		<div class="eem-field-label-sub"><?php esc_html_e( 'Type to search by title; results show matching events.', 'equine-event-manager' ); ?></div>
	</div>
	<div class="eem-event-typeahead-wrap">
		<input
			type="text"
			class="eem-field-input eem-event-typeahead-input"
			data-eem-typeahead="native"
			placeholder="<?php esc_attr_e( 'Search native events…', 'equine-event-manager' ); ?>"
			autocomplete="off"
		/>
		<div class="eem-event-typeahead-results" data-eem-typeahead-results="native" hidden></div>
	</div>
</div>

<div class="eem-modal-linked-event__picker" data-eem-source-picker="tec" hidden>
	<div class="eem-field-label" style="margin-top: 16px;">
		<?php esc_html_e( 'Select TEC event', 'equine-event-manager' ); ?>
	</div>
	<div class="eem-event-typeahead-wrap">
		<input
			type="text"
			class="eem-field-input eem-event-typeahead-input"
			data-eem-typeahead="tec"
			placeholder="<?php esc_attr_e( 'Search TEC events…', 'equine-event-manager' ); ?>"
			autocomplete="off"
		/>
		<div class="eem-event-typeahead-results" data-eem-typeahead-results="tec" hidden></div>
	</div>
</div>

<div class="eem-modal-linked-event__picker" data-eem-source-picker="feed" hidden>
	<div class="eem-field-label" style="margin-top: 16px;">
		<?php esc_html_e( 'Feed URL', 'equine-event-manager' ); ?>
		<div class="eem-field-label-sub"><?php esc_html_e( 'Remote JSON feed URL. The reservation will pull event details from this URL.', 'equine-event-manager' ); ?></div>
	</div>
	<input
		type="url"
		class="eem-field-input eem-modal-linked-event__feed-url"
		placeholder="https://example.com/events/12345.json"
	/>
	<p class="eem-field-hint" style="margin-top: 8px;">
		<?php esc_html_e( 'Feed validation lands in C7.C. C7.B.2 accepts the URL as-is.', 'equine-event-manager' ); ?>
	</p>
</div>

<input type="hidden" class="eem-modal-linked-event__selected-id" value="" />
<div class="eem-modal-linked-event__error" hidden role="alert"></div>
<?php
$body_html = (string) ob_get_clean();

// Footer HTML
ob_start();
?>
<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="reservation-editor-modal-close" data-eem-modal="eem-modal-linked-event">
	<?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?>
</button>
<button type="button" class="eem-btn eem-btn-primary" data-eem-action="reservation-editor-linked-event-save" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
	<?php esc_html_e( 'Save', 'equine-event-manager' ); ?>
</button>
<?php
$footer_html = (string) ob_get_clean();

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_modal-helper.php';

eem_render_reservation_editor_modal( array(
	'id'          => 'eem-modal-linked-event',
	'title'       => __( 'Change Linked Event', 'equine-event-manager' ),
	'body_html'   => $body_html,
	'footer_html' => $footer_html,
	'classes'     => '',
) );
