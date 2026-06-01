<?php
/**
 * Reservation Editor — "Venue Map" section body (2.3.74).
 *
 * A single file-upload field for a full venue map (PDF or image). When a file
 * is present the section auto-enables (server-side, see EEM_Reservations_CPT
 * sanitize_meta_submission venue_map auto-enable) and a "Download Venue Map"
 * link surfaces at the bottom of the customer-facing Stay Details card. This is
 * the place to upload a whole-venue map when a stall/RV chart alone isn't enough.
 *
 * Uses the same .eem-file-row chrome + media-uploader handlers as the Agreement
 * section (data-eem-action="reservation-editor-venuemap-upload" / "-remove").
 *
 * Locals contract:
 *   $data  array  reservation meta values
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $data */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';

$file_id   = isset( $data['venue_map_image_id'] ) ? (int) $data['venue_map_image_id'] : 0;
$file_url  = $file_id > 0 ? wp_get_attachment_url( $file_id ) : '';
$file_name = $file_id > 0 ? basename( get_attached_file( $file_id ) ?: '' ) : '';
$has_file  = $file_id > 0 && '' !== $file_name;
?>
<input type="hidden" name="en_reservation[venue_map_enabled]" data-eem-section-enabled="venuemap" value="<?php echo ! empty( $data['venue_map_enabled'] ) ? '1' : '0'; ?>" />
<?php
ob_start();
?>
<div class="eem-file-row">
	<div class="eem-file-name">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
		<?php if ( $has_file ) : ?>
			<span data-eem-file-name><?php echo esc_html( $file_name ); ?></span>
		<?php else : ?>
			<span class="eem-file-name-empty" data-eem-file-name><?php esc_html_e( 'No venue map uploaded yet', 'equine-event-manager' ); ?></span>
		<?php endif; ?>
	</div>
	<?php if ( $has_file ) : ?>
		<a class="eem-view-link" href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'equine-event-manager' ); ?></a>
	<?php endif; ?>
	<button class="eem-btn-upload" type="button" data-eem-action="reservation-editor-venuemap-upload"><?php echo $has_file ? esc_html__( 'Replace', 'equine-event-manager' ) : esc_html__( 'Upload', 'equine-event-manager' ); ?></button>
	<?php if ( $has_file ) : ?>
		<button class="eem-btn-file-del" type="button" aria-label="<?php esc_attr_e( 'Remove file', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-venuemap-remove">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
		</button>
	<?php endif; ?>
	<input type="hidden" name="en_reservation[venue_map_image_id]" id="en_venue_map_image_id" value="<?php echo esc_attr( (string) $file_id ); ?>" data-eem-venuemap-file-id />
</div>
<?php
$file_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Venue Map File', 'equine-event-manager' ),
	'label_sub'    => __( 'PDF or image', 'equine-event-manager' ),
	'control_html' => $file_html,
	'hint'         => __( 'Upload a full venue map. When present, a "Download Venue Map" link shows on the customer Stay Details card. Use this when a stall/RV chart alone is not enough.', 'equine-event-manager' ),
) );
