<?php
/**
 * Reservation editor save bar partial (C7.B.2).
 *
 * Sticky-bottom save strip per Decision A + B. Buttons:
 *   - Cancel (always visible, ghost on navy)
 *   - Save Draft + Publish (when post_status === 'draft')
 *   - Update (when post_status === 'publish')
 *
 * Decision J: partial accepts $args so Order Detail (C7.F) can reuse
 * the same shared component with different button labels. Default args
 * match the Reservation Editor case; Order Detail will pass overrides.
 *
 * Expected $args shape (all optional):
 *   nonce_action  string  Default 'eem_reservation_editor'.
 *   nonce_name    string  Default '_eem_editor_nonce'.
 *   cancel_url    string  Default = Reservations list URL.
 *   cancel_label  string  Default __('Cancel').
 *   primary_action string  'draft'|'publish'|'update' (drives the
 *                          two-state Draft/Publish vs Update layout).
 *                          Per Decision C — derived from post_status.
 *   draft_label    string  Default __('Save Draft').
 *   publish_label  string  Default __('Publish').
 *   update_label   string  Default __('Update').
 *   data_attrs     array   Extra data-* attrs on the save bar root
 *                          (e.g. ['eem-reservation-id' => 123]).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 *
 * Expects $args (array) in scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'nonce_action'   => 'eem_reservation_editor',
	'nonce_name'     => '_eem_editor_nonce',
	'cancel_url'     => admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ),
	'cancel_label'   => __( 'Cancel', 'equine-event-manager' ),
	'primary_action' => 'draft',
	'draft_label'    => __( 'Save Draft', 'equine-event-manager' ),
	'publish_label'  => __( 'Publish', 'equine-event-manager' ),
	'update_label'   => __( 'Update', 'equine-event-manager' ),
	'data_attrs'     => array(),
);
$args = array_merge( $defaults, isset( $args ) && is_array( $args ) ? $args : array() );

$data_attr_html = '';
foreach ( $args['data_attrs'] as $k => $v ) {
	$data_attr_html .= ' data-' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
}
?>
<div class="eem-save-bar"<?php echo $data_attr_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php wp_nonce_field( $args['nonce_action'], $args['nonce_name'], false, true ); ?>
	<a class="eem-btn eem-btn-secondary" href="<?php echo esc_url( $args['cancel_url'] ); ?>" data-eem-action="reservation-editor-cancel">
		<?php echo esc_html( $args['cancel_label'] ); ?>
	</a>
	<div class="eem-save-bar__primary">
		<?php if ( 'update' === $args['primary_action'] ) : ?>
			<button type="button" class="eem-btn eem-btn-primary" data-eem-action="reservation-editor-update">
				<?php echo esc_html( $args['update_label'] ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="reservation-editor-save-draft">
				<?php echo esc_html( $args['draft_label'] ); ?>
			</button>
			<button type="button" class="eem-btn eem-btn-primary" data-eem-action="reservation-editor-publish">
				<?php echo esc_html( $args['publish_label'] ); ?>
			</button>
		<?php endif; ?>
	</div>
</div>
