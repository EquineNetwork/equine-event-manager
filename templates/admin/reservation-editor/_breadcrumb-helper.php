<?php
/**
 * Reservation editor breadcrumb segments helper (C7.B.1).
 *
 * Builds the breadcrumb segments array for the editor page. Mockup
 * line 343-348 specifies the breadcrumb hierarchy
 * Plugin Logo > Reservations > <reservation title>
 * — fed to the canonical eem_render_breadcrumb() partial via the
 * shell args.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_reservation_editor_breadcrumb' ) ) {
	/**
	 * @param int $reservation_id
	 * @return array<int, array<string, string>>
	 */
	function eem_reservation_editor_breadcrumb( $reservation_id ) {
		$reservation_id = (int) $reservation_id;
		$title = $reservation_id > 0 ? (string) get_the_title( $reservation_id ) : '';
		if ( '' === $title ) {
			$title = __( 'New Reservation', 'equine-event-manager' );
		}
		return array(
			array(
				'label' => __( 'Reservations', 'equine-event-manager' ),
				'url'   => admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ),
			),
			array(
				'label' => $title,
			),
		);
	}
}
