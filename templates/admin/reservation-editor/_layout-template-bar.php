<?php
/**
 * Reservation Editor — "Facility Layout Templates" toolbar (v2 Venues, Slice 3).
 *
 * Renders the "Save Layout" + "Load Layout" buttons that appear inside BOTH the
 * stall builder and the RV builder. A Venue layout is COMBINED — one save/load
 * captures/restores the whole venue (stall grid + RV lots/zones + blocked units
 * + map geometry) — so the two buttons act on the same combined layout regardless
 * of which builder they're clicked from. Reservation id is read by the JS from
 * the `data-eem-reservation-id` attribute on `.eem-reservation-editor-body`.
 *
 * Wiring: assets/js/venue-layouts.js (delegated handlers) →
 * EEM_Venues_Page::ajax_save_layout / ajax_list_layouts / ajax_load_layout.
 *
 * @package EEM_Plugin
 *
 * @var string $context Builder this bar renders in ('stall' | 'rv') — labeling only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$context = isset( $context ) ? (string) $context : 'stall';
?>
<div class="eem-layout-template-bar" data-eem-layout-context="<?php echo esc_attr( $context ); ?>">
	<div class="eem-layout-template-bar__text">
		<h4 class="eem-layout-template-bar__title"><?php esc_html_e( 'Facility Layout Templates', 'equine-event-manager' ); ?></h4>
		<p class="eem-layout-template-bar__hint">
			<?php esc_html_e( 'Save this reservation’s stall &amp; RV layout to its venue to reuse next year, or load a previously saved layout. Save the reservation first to capture recent edits.', 'equine-event-manager' ); ?>
		</p>
	</div>
	<div class="eem-layout-template-bar__actions">
		<button type="button" class="eem-btn eem-btn-primary" data-eem-action="venue-save-layout">
			<?php esc_html_e( 'Save Layout to Venue', 'equine-event-manager' ); ?>
		</button>
		<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="venue-load-layout">
			<?php esc_html_e( 'Load Layout from Venue', 'equine-event-manager' ); ?>
		</button>
	</div>
</div>
<?php
