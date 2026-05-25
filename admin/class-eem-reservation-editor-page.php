<?php
/**
 * Reservation Editor page controller — Path A custom-render (C7.B.1).
 *
 * Replaces the WP CPT meta-box editor with a mockup-faithful custom
 * page per `.mockups/edit_reservation_page.html`. Per Q2 lock: meta-
 * boxes retire in C7; this controller owns the editor surface end-to-
 * end.
 *
 * C7.B.1 scope: render scaffold + section skeletons + meta-line readout.
 * NO data wiring (sections render placeholder bodies — Decision G).
 * NO save dispatcher (C7.B.2). NO Linked Event modal (C7.B.2 per Q14.b).
 * NO Event Defaults modal (C7.E per Q9). NO Event Day Info data (C7.D).
 * NO Cancellation Policy override + event default readout (C7.E).
 *
 * Architecture per VIS-3 / DS-1.B Dashboard precedent:
 *   .eem-page
 *     .eem-page-wrap (bordered card)
 *       .eem-page-header (title + meta-line + actions)
 *       .eem-page-body
 *         .eem-reservation-editor-body
 *           10 stacked .eem-reservation-editor-section cards
 *
 * Locked icon-tone map (re-verified against mockup per Decision E):
 *   description=blue, checkin=teal, eventday=orange, stall=green,
 *   rv=purple, addons=orange, group=green, fees=orange, agreement=navy,
 *   cancellation=red. Only `description` has no enable toggle (always-on).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 2.3.0
 */
class EEM_Reservation_Editor_Page {

	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'equine-event-manager-reservation-editor';

	/**
	 * Render the editor page. Reads `?reservation_id=N` per Decision B.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$reservation_id = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$post = $reservation_id > 0 ? get_post( $reservation_id ) : null;

		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			self::render_not_found();
			return;
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-skeleton.php';
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_breadcrumb-helper.php';

		eem_render_page_open( array(
			'title'      => get_the_title( $post ),
			'subtitle'   => '',
			'breadcrumb' => eem_reservation_editor_breadcrumb( $reservation_id ),
			'meta'       => self::build_meta_line_html( $reservation_id ),
			'actions'    => self::build_header_actions_html(),
		) );
		?>
		<div class="eem-reservation-editor-body">
			<?php self::render_section_skeletons(); ?>
			<?php /* C7.B.2: save bar lands here */ ?>
			<div class="eem-reservation-editor-savebar-placeholder" aria-hidden="true">
				<?php esc_html_e( 'Save bar — coming in C7.B.2 (Draft / Update / Publish actions).', 'equine-event-manager' ); ?>
			</div>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Render the 10 section card skeletons in mockup order. Each
	 * section's body is a placeholder string per Decision G; real
	 * bodies wire in C7.C / C7.D / C7.E.
	 *
	 * @return void
	 */
	private static function render_section_skeletons() {
		$placeholder_body = '<p class="eem-field-hint">' .
			esc_html__( 'Section body wires in a later C7 sub-chunk (C7.C for existing fields, C7.D for Event Day Info, C7.E for Cancellation Policy).', 'equine-event-manager' ) .
			'</p>';

		$sections = self::section_definitions();
		foreach ( $sections as $section ) {
			eem_render_reservation_editor_section( array(
				'key'           => $section['key'],
				'title'         => $section['title'],
				'icon_tone'     => $section['icon_tone'],
				'enable_toggle' => $section['enable_toggle'],
				'collapsed'     => $section['collapsed'],
				'body_html'     => $placeholder_body,
			) );
		}
	}

	/**
	 * Canonical section definitions (locked per Decision E mockup re-
	 * verification). Order matches mockup top-to-bottom. icon_tone +
	 * enable_toggle + initial collapsed state come straight from the
	 * mockup; titles flow through __() for i18n.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function section_definitions() {
		return array(
			array( 'key' => 'description',  'title' => __( 'Reservation Description', 'equine-event-manager' ), 'icon_tone' => 'blue',   'enable_toggle' => false, 'collapsed' => false ),
			array( 'key' => 'checkin',      'title' => __( 'Check-In / Check-Out',    'equine-event-manager' ), 'icon_tone' => 'teal',   'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'eventday',     'title' => __( 'Event Day Info',          'equine-event-manager' ), 'icon_tone' => 'orange', 'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'stall',        'title' => __( 'Stall Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'rv',           'title' => __( 'RV Reservations',         'equine-event-manager' ), 'icon_tone' => 'purple', 'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'addons',       'title' => __( 'General Add-Ons',         'equine-event-manager' ), 'icon_tone' => 'orange', 'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'group',        'title' => __( 'Group Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'fees',         'title' => __( 'Convenience Fee',         'equine-event-manager' ), 'icon_tone' => 'orange', 'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'agreement',    'title' => __( 'Agreement',               'equine-event-manager' ), 'icon_tone' => 'navy',   'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'cancellation', 'title' => __( 'Cancellation Policy',     'equine-event-manager' ), 'icon_tone' => 'red',    'enable_toggle' => true,  'collapsed' => true  ),
		);
	}

	/**
	 * Meta-line HTML (Linked Event readout) for the page-header `meta`
	 * slot — pre-escaped per shell partial's wp_kses_post() pass.
	 *
	 * @param int $reservation_id
	 * @return string
	 */
	private static function build_meta_line_html( $reservation_id ) {
		ob_start();
		require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_meta-line.php';
		return (string) ob_get_clean();
	}

	/**
	 * Header action bar (right side of the page header). C7.B.1 ships
	 * a placeholder; C7.B.2 adds the Draft/Publish save buttons per
	 * Q2 verification finding.
	 *
	 * @return string
	 */
	private static function build_header_actions_html() {
		$res_url = esc_url( admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ) );
		ob_start();
		?>
		<a class="eem-btn eem-btn-ghost" href="<?php echo $res_url; ?>"><?php esc_html_e( 'Back to Reservations', 'equine-event-manager' ); ?></a>
		<?php /* C7.B.2: Draft / Update / Publish buttons land here */ ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Graceful "reservation not found" render — used when ?reservation_id
	 * is missing/invalid. Mirrors the C6 Order Detail not-found shape.
	 *
	 * @return void
	 */
	private static function render_not_found() {
		eem_render_page_open( array(
			'title'      => __( 'Reservation Not Found', 'equine-event-manager' ),
			'breadcrumb' => array(
				array(
					'label' => __( 'Reservations', 'equine-event-manager' ),
					'url'   => admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ),
				),
			),
		) );
		?>
		<div class="eem-reservation-editor-not-found">
			<p><?php esc_html_e( 'No reservation matched the requested ID. It may have been deleted, or the link may be incorrect.', 'equine-event-manager' ); ?></p>
			<p><a class="eem-btn eem-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Back to Reservations', 'equine-event-manager' ); ?></a></p>
		</div>
		<?php
		eem_render_page_close();
	}
}
