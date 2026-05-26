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
		<div class="eem-reservation-editor-body" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
			<?php self::render_section_skeletons( $reservation_id ); ?>
			<?php
			// C7.B.2: save bar — sticky-bottom (Decision A), navy band
			// (Decision B), button labels driven by post_status
			// (Decision C). Partial accepts $args for Order Detail
			// re-use in C7.F (Decision J).
			$args = array(
				'primary_action' => 'publish' === $post->post_status ? 'update' : 'draft',
				'data_attrs'     => array( 'eem-reservation-id' => (string) $reservation_id ),
			);
			require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_save-bar.php';

			// C7.B.2: Linked Event modal (Q14.b). Hidden by default;
			// JS launcher opens it on click of the meta-line change
			// link.
			require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_modal-linked-event.php';
			?>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Render the 10 section card skeletons in mockup order. C7.C.1
	 * wires real bodies for 6 sections (description, checkin, addons,
	 * group, fees, agreement); stall + rv defer to C7.C.2; eventday +
	 * cancellation defer to C7.D + C7.E respectively. Per Decision A,
	 * each section dispatches to a render_*_body() method that returns
	 * captured HTML; per Decision B, body partials receive a pre-
	 * collected `$data` array (no inline get_post_meta()).
	 *
	 * @param int $reservation_id
	 * @return void
	 */
	private static function render_section_skeletons( $reservation_id ) {
		$reservations_cpt = new EEM_Reservations_CPT();
		$data             = $reservations_cpt->get_editor_meta_values( $reservation_id );
		$addons_context   = $reservations_cpt->get_editor_general_addons_context( $reservation_id );
		$addons           = isset( $addons_context['addons'] ) ? (array) $addons_context['addons'] : array();

		$sections = self::section_definitions();
		foreach ( $sections as $section ) {
			$body_html  = self::render_section_body( $section['key'], $data, $addons, $reservations_cpt );
			$is_enabled = self::compute_section_is_enabled( $section['key'], $data );
			eem_render_reservation_editor_section( array(
				'key'           => $section['key'],
				'title'         => $section['title'],
				'icon_tone'     => $section['icon_tone'],
				'icon_key'      => $section['icon_key'], // C7.B.3 — Feather glyph
				'enable_toggle' => $section['enable_toggle'],
				'collapsed'     => $section['collapsed'],
				'body_html'     => $body_html,
				'is_enabled'    => $is_enabled,      // C7.C.1.1 — Desync C fix
			) );
		}
	}

	/**
	 * Compute the persisted enabled state for a section's header toggle.
	 * Used by section-skeleton's `is_enabled` arg (C7.C.1.1 Desync C fix
	 * — header-toggle CSS class now matches the underlying meta state,
	 * was previously hardcoded `--on` regardless). Map keys are the
	 * legacy `_en_*_enabled` post-meta names; description is always-on
	 * by definition (no enable toggle); stall/rv/eventday/cancellation
	 * stay placeholder until their respective sub-chunks land.
	 *
	 * @param string               $section_key
	 * @param array<string, mixed> $data        Reservation meta values.
	 * @return bool
	 */
	private static function compute_section_is_enabled( $section_key, array $data ) {
		$meta_map = array(
			'checkin'      => 'checkin_checkout_enabled',
			'addons'       => 'general_addons_enabled',
			'group'        => 'group_reservations_enabled',
			'fees'         => 'convenience_fee_enabled',
			'agreement'    => 'venue_agreement_enabled',
			'stall'        => 'stalls_enabled',          // C7.C.2
			'rv'           => 'rv_enabled',              // C7.C.2
			'eventday'     => 'event_day_enabled',       // C7.D — meta key TBD
			'cancellation' => 'cancellation_enabled',    // C7.E — meta key TBD
		);
		if ( 'description' === $section_key ) {
			return true; // always-on per Decision E
		}
		if ( ! isset( $meta_map[ $section_key ] ) || ! isset( $data[ $meta_map[ $section_key ] ] ) ) {
			return true; // default to on if we don't yet know
		}
		return ! empty( $data[ $meta_map[ $section_key ] ] );
	}

	/**
	 * Dispatch a section key to its body partial and return the rendered
	 * HTML (C7.C.1 Decision A — per-method dispatch). Sections still
	 * awaiting wiring fall through to a placeholder so the chrome stays
	 * intact while the data layer lands across C7.C.2 / C7.D / C7.E.
	 *
	 * @param string                              $key
	 * @param array<string, mixed>                $data
	 * @param array<int, array<string, mixed>>    $addons
	 * @param EEM_Reservations_CPT                $reservations_cpt
	 * @return string  Pre-rendered HTML; trusted by section-skeleton wp_kses_post pass.
	 */
	private static function render_section_body( $key, $data, $addons, $reservations_cpt ) {
		$wired_map = array(
			'description' => '_section-description.php',
			'checkin'     => '_section-checkin.php',
			'addons'      => '_section-addons.php',
			'group'       => '_section-group.php',
			'fees'        => '_section-fees.php',
			'agreement'   => '_section-agreement.php',
		);
		if ( ! isset( $wired_map[ $key ] ) ) {
			return '<p class="eem-field-hint">' .
				esc_html__( 'Section body wires in a later C7 sub-chunk (C7.C.2 for Stall + RV, C7.D for Event Day Info, C7.E for Cancellation Policy).', 'equine-event-manager' ) .
				'</p>';
		}
		ob_start();
		require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/' . $wired_map[ $key ];
		return (string) ob_get_clean();
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
			array( 'key' => 'description',  'title' => __( 'Reservation Description', 'equine-event-manager' ), 'icon_tone' => 'blue',   'icon_key' => 'file-text', 'enable_toggle' => false, 'collapsed' => false ),
			array( 'key' => 'checkin',      'title' => __( 'Check-In / Check-Out',    'equine-event-manager' ), 'icon_tone' => 'teal',   'icon_key' => 'clock',     'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'eventday',     'title' => __( 'Event Day Info',          'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'map-pin',   'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'stall',        'title' => __( 'Stall Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'icon_key' => 'grid',      'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'rv',           'title' => __( 'RV Reservations',         'equine-event-manager' ), 'icon_tone' => 'purple', 'icon_key' => 'truck',     'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'addons',       'title' => __( 'General Add-Ons',         'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'plus',      'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'group',        'title' => __( 'Group Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'icon_key' => 'users',     'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'fees',         'title' => __( 'Convenience Fee',         'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'dollar',    'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'agreement',    'title' => __( 'Agreement',               'equine-event-manager' ), 'icon_tone' => 'navy',   'icon_key' => 'file',      'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'cancellation', 'title' => __( 'Cancellation Policy',     'equine-event-manager' ), 'icon_tone' => 'red',    'icon_key' => 'shield-x',  'enable_toggle' => true,  'collapsed' => true  ),
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
	 * AJAX: save the reservation (post_status flip + future per-section
	 * field saves). C7.B.2 dispatcher SHELL per Decision D — handles
	 * post_status changes only; per-section meta saves wire in C7.C.
	 *
	 * Accepts `action` ∈ {save_draft, publish, update}; flips
	 * post_status accordingly.
	 *
	 * @return void  Always emits wp_send_json_*; terminates.
	 */
	public static function ajax_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_reservation_editor', '_eem_editor_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$action_kind    = isset( $_POST['save_kind'] ) ? sanitize_key( wp_unslash( $_POST['save_kind'] ) ) : '';

		$post = $reservation_id > 0 ? get_post( $reservation_id ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		$new_status = null;
		switch ( $action_kind ) {
			case 'save_draft':
				$new_status = 'draft';
				break;
			case 'publish':
			case 'update':
				$new_status = 'publish';
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown save action.', 'equine-event-manager' ) ), 400 );
		}

		if ( $new_status !== $post->post_status ) {
			$result = wp_update_post( array(
				'ID'          => $reservation_id,
				'post_status' => $new_status,
			), true );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}
		}

		// C7.C.1.1 — pre-validate before invoking save_meta(). Without
		// this, save_meta()'s cross-section validation (e.g. checkin
		// enabled + empty times) silently aborts the entire write
		// phase while AJAX returns success — the toast lies and no
		// fields persist. Pre-validation surfaces errors back through
		// wp_send_json_error so the JS toast shows the real reason.
		// Save_meta still re-validates internally (defense in depth);
		// the duplicate cost is ~3ms.
		if ( isset( $_POST['en_reservation'] ) && is_array( $_POST['en_reservation'] ) ) {
			$cpt        = new EEM_Reservations_CPT();
			$source_raw = wp_unslash( $_POST['en_reservation'] );
			$existing   = $cpt->get_meta_values( $reservation_id );
			$candidate  = $cpt->sanitize_meta_submission( $source_raw, $existing );
			$errors     = $cpt->validate_meta_submission( $candidate );

			if ( ! empty( $errors ) ) {
				wp_send_json_error( array(
					'message' => $errors[0],
					'errors'  => array_values( $errors ),
					'code'    => 'validation_failed',
				), 422 );
			}

			// Validation passed — hand the payload to legacy save_meta()
			// per Decision C (reuse the 93-field sanitization surface).
			$_POST['equine_event_manager_reservation_meta_nonce'] = wp_create_nonce( 'equine_event_manager_save_reservation_meta' );
			$refreshed = get_post( $reservation_id );
			$cpt->save_meta( $reservation_id, $refreshed );
		}

		wp_send_json_success( array(
			'reservation_id' => $reservation_id,
			'post_status'    => $new_status,
			'primary_action' => 'publish' === $new_status ? 'update' : 'draft',
			'message'        => 'publish' === $new_status
				? __( 'Reservation published.', 'equine-event-manager' )
				: __( 'Draft saved.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX: change the reservation's linked event (Q14.b modal save).
	 * Validates source ∈ {native, tec, feed} + non-empty event_id;
	 * updates the appropriate meta keys; returns refreshed meta-line
	 * HTML for the JS to DOM-replace (per Decision K).
	 *
	 * @return void  Always emits wp_send_json_*; terminates.
	 */
	public static function ajax_change_linked_event() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_reservation_editor', '_eem_editor_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$source         = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$event_id_raw   = isset( $_POST['event_id'] ) ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) ) : '';

		$post = $reservation_id > 0 ? get_post( $reservation_id ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		// Source validation — accept canonical native/tec/feed.
		if ( ! in_array( $source, array( 'native', 'tec', 'feed' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event source.', 'equine-event-manager' ) ), 400 );
		}

		// event_id validation — non-empty required.
		if ( '' === trim( $event_id_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Select an event before saving.', 'equine-event-manager' ) ), 400 );
		}

		update_post_meta( $reservation_id, '_en_event_source', $source );
		if ( in_array( $source, array( 'native', 'tec' ), true ) ) {
			update_post_meta( $reservation_id, '_en_event_id', absint( $event_id_raw ) );
		} else {
			update_post_meta( $reservation_id, '_en_external_event_id', $event_id_raw );
		}

		// Refresh the meta-line HTML — Decision K — DOM replacement.
		$meta_html = self::build_meta_line_html( $reservation_id );

		wp_send_json_success( array(
			'reservation_id' => $reservation_id,
			'source'         => $source,
			'event_id'       => $event_id_raw,
			'meta_line_html' => $meta_html,
			'message'        => __( 'Linked event updated.', 'equine-event-manager' ),
		) );
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
