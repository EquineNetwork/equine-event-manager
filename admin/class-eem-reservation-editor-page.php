<?php
/**
 * Reservation Editor page controller — mockup-canonical architecture
 * (C7.X.3 Build-to-Mockup rewrite).
 *
 * Replaces the legacy WP CPT meta-box editor with a custom render page
 * that ships the mockup verbatim per `.mockups/edit_reservation_page.html`.
 * Two-column layout: .eem-edit-main (section cards) + .eem-edit-rail
 * (Publish + Linked Event + Shortcode cards). Mobile drops to single
 * column with a .eem-sticky-save fixed-bottom strip at <768px.
 *
 * Architectural deltas from the C7.B.1–C7.C.1 lineage (all retired):
 *   - Bypasses the shared _page_shell.php — that shell emits
 *     .eem-page-wrap chrome; the mockup uses .eem-plugin-wrap with
 *     different header anatomy (.eem-plugin-title +
 *     .eem-plugin-subtitle + .eem-plugin-meta-line). Other admin
 *     pages still use the shared shell unchanged.
 *   - DELETED: fixed-bottom .eem-save-bar (was always visible) +
 *     #eem-modal-linked-event modal. Save lives in the rail card on
 *     desktop, sticky-save on mobile. Linked event lives in the rail.
 *   - The change-link launcher anchor in the meta-line is removed;
 *     editing the linked event happens in the rail card.
 *
 * Locked icon-tone map (Decision E re-verified against mockup):
 *   description=blue, checkin=teal, eventday=orange, stall=green,
 *   rv=purple, addons=orange, group=green, fees=orange, agreement=navy,
 *   cancellation=red. Only `description` has no enable toggle.
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
	 * Canonical admin URL for the editor for a given reservation. Single
	 * source of truth — all callers (Reservations list rows, Dashboard
	 * upcoming-rows, Orders list event-link, Order Detail "View
	 * Reservation") route through here so the URL pattern stays
	 * consistent and the legacy `get_edit_post_link()` (which returns
	 * the WP CPT `post.php?post=N&action=edit` URL) is no longer the
	 * authority for "edit a reservation".
	 *
	 * @param int $reservation_id
	 * @return string
	 */
	public static function url( $reservation_id ) {
		$reservation_id = (int) $reservation_id;
		if ( $reservation_id <= 0 ) {
			return admin_url( 'admin.php?page=' . self::MENU_SLUG );
		}
		return admin_url( 'admin.php?page=' . self::MENU_SLUG . '&reservation_id=' . $reservation_id );
	}

	/**
	 * Redirect legacy WP CPT edit URL (`post.php?post=N&action=edit`)
	 * to the new editor when the post type is `en_reservation`. Mirrors
	 * the shape of `EEM_Reservations_List_Page::maybe_redirect_old_list()`
	 * which intercepts the legacy list view at
	 * `edit.php?post_type=en_reservation`. Wired to `load-post.php` so
	 * it fires before WordPress renders any of the legacy meta-box
	 * chrome.
	 *
	 * Bookmarked legacy URLs, third-party links, and stray
	 * `get_edit_post_link()` callers we missed in the rewire all
	 * funnel through here.
	 *
	 * @return void
	 */
	public static function maybe_redirect_legacy_edit() {
		$url = self::resolve_legacy_edit_redirect_url();
		if ( null === $url ) {
			return;
		}
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Pure resolver — returns the new editor URL if the current
	 * `$_GET` corresponds to a legacy `en_reservation` edit URL, else
	 * null. Split out from `maybe_redirect_legacy_edit()` so smokes
	 * can verify the redirect target without triggering `exit`.
	 *
	 * @return string|null
	 */
	public static function resolve_legacy_edit_redirect_url() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['post'] ) ) {
			return null;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( '' !== $action && 'edit' !== $action ) {
			return null;
		}
		$post_id = absint( wp_unslash( $_GET['post'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $post_id <= 0 ) {
			return null;
		}
		if ( EEM_Reservations_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}
		return self::url( $post_id );
	}

	/**
	 * Render the editor page. Reads `?reservation_id=N`.
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

		$breadcrumb_segments = eem_reservation_editor_breadcrumb( $reservation_id );
		$source_event_title  = self::resolve_page_title( $post, $reservation_id );
		?>
		<div class="eem-page">
			<?php eem_render_breadcrumb( $breadcrumb_segments ); ?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<h1 class="eem-plugin-title"><?php echo esc_html( $source_event_title ); ?></h1>
					<div class="eem-plugin-subtitle"><?php esc_html_e( 'Configure the reservation setup for this event. Title and dates are mirrored from the linked source event and cannot be edited here.', 'equine-event-manager' ); ?></div>
					<?php require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_meta-line.php'; ?>
				</header>
				<div class="eem-edit-body">
					<main class="eem-edit-main eem-reservation-editor-body" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
						<?php self::render_section_skeletons( $reservation_id ); ?>
					</main>
					<aside class="eem-edit-rail">
						<?php
						// C7.X.15 Issue 7 — partial reversal of C7.X.12 Item 7.
						// Linked Event rail card RESTORED with hybrid placement:
						// meta-line is read-only context, actionable controls
						// (typeahead + Change link + ✕ unlink icon) live here.
						// See _rail-linked-event-card.php docblock for the full
						// rationale + difference from the pre-C7.X.12 shape.
						require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_rail-publish-card.php';
						require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_rail-linked-event-card.php';
						require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_rail-shortcode-card.php';
						?>
					</aside>
				</div>
			</div>
		</div>
		<?php require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_sticky-save-mobile.php'; ?>
		<?php
	}

	/**
	 * Resolve the page-header title. Per RES-ARCH-1 + mockup line 347,
	 * the editor displays the LINKED SOURCE EVENT title — not the
	 * reservation post's own post_title (which is admin-internal).
	 * Falls back to the reservation post title when no event is linked.
	 *
	 * @param WP_Post $post
	 * @param int     $reservation_id
	 * @return string
	 */
	private static function resolve_page_title( $post, $reservation_id ) {
		if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
			$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
			if ( ! empty( $fields['title'] ) ) {
				return (string) $fields['title'];
			}
		}
		return get_the_title( $post );
	}

	/**
	 * Render the 10 section card skeletons in mockup order. Body partials
	 * receive a pre-collected $data array (no inline get_post_meta()).
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
			$body_html     = self::render_section_body( $section['key'], $data, $addons, $reservations_cpt );
			$is_enabled    = self::compute_section_is_enabled( $section['key'], $data );
			$disabled_note = self::section_disabled_note( $section['key'] );
			eem_render_reservation_editor_section( array(
				'key'             => $section['key'],
				'title'           => $section['title'],
				'icon_tone'       => $section['icon_tone'],
				'icon_key'        => $section['icon_key'],
				'enable_toggle'   => $section['enable_toggle'],
				'collapsed'       => $section['collapsed'],
				'body_html'       => $body_html,
				'is_enabled'      => $is_enabled,
				'disabled_note'   => $disabled_note,
				'intro_hint_html' => '',
			) );
		}
	}

	/**
	 * Disabled-note callout per section (mockup line 409/956/etc.).
	 *
	 * @param string $section_key
	 * @return string
	 */
	private static function section_disabled_note( $section_key ) {
		switch ( $section_key ) {
			case 'checkin':
				return __( 'This section is disabled. Enable it to set check-in and check-out times.', 'equine-event-manager' );
			case 'group':
				return __( 'This section is disabled. Enable it to let customers register groups of riders.', 'equine-event-manager' );
			case 'fees':
				return __( 'This section is disabled. Enable it to charge a convenience fee at checkout.', 'equine-event-manager' );
			case 'addons':
				return __( 'This section is disabled. Enable it to offer optional add-ons to customers.', 'equine-event-manager' );
			case 'agreement':
				return __( 'This section is disabled. Enable it to require customers to acknowledge an agreement before booking.', 'equine-event-manager' );
			case 'stall':
				return __( 'This section is disabled. Enable it to offer stall reservations.', 'equine-event-manager' );
			case 'rv':
				return __( 'This section is disabled. Enable it to offer RV reservations.', 'equine-event-manager' );
			case 'eventday':
				return __( 'This section is disabled. Event-day info will be hidden from customers.', 'equine-event-manager' );
			default:
				return '';
		}
	}

	/**
	 * Compute the persisted enabled state for a section's header toggle.
	 *
	 * @param string               $section_key
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	private static function compute_section_is_enabled( $section_key, array $data ) {
		$meta_map = array(
			'checkin'      => 'checkin_checkout_enabled',
			'addons'       => 'general_addons_enabled',
			'group'        => 'group_reservations_enabled',
			'fees'         => 'convenience_fee_enabled',
			'agreement'    => 'venue_agreement_enabled',
			'stall'        => 'stalls_enabled',
			'rv'           => 'rv_enabled',
			'eventday'     => 'event_day_enabled',
			'cancellation' => 'cancellation_enabled',
		);
		if ( 'description' === $section_key ) {
			return true;
		}
		if ( ! isset( $meta_map[ $section_key ] ) || ! isset( $data[ $meta_map[ $section_key ] ] ) ) {
			return true;
		}
		return ! empty( $data[ $meta_map[ $section_key ] ] );
	}

	/**
	 * Dispatch a section key to its body partial and return the rendered HTML.
	 *
	 * @param string                              $key
	 * @param array<string, mixed>                $data
	 * @param array<int, array<string, mixed>>    $addons
	 * @param EEM_Reservations_CPT                $reservations_cpt
	 * @return string
	 */
	private static function render_section_body( $key, $data, $addons, $reservations_cpt ) {
		$wired_map = array(
			'description'  => '_section-description.php',
			'checkin'      => '_section-checkin.php',
			'eventday'     => '_section-eventday.php',
			'stall'        => '_section-stall.php',
			'rv'           => '_section-rv.php',
			'addons'       => '_section-addons.php',
			'group'        => '_section-group.php',
			'fees'         => '_section-fees.php',
			'agreement'    => '_section-agreement.php',
			'cancellation' => '_section-cancellation.php',
		);
		if ( ! isset( $wired_map[ $key ] ) ) {
			return '';
		}
		ob_start();
		require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/' . $wired_map[ $key ];
		return (string) ob_get_clean();
	}

	/**
	 * Canonical section definitions. Order matches mockup top-to-bottom;
	 * icon_tone + enable_toggle + initial collapsed state come straight
	 * from the mockup.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function section_definitions() {
		return array(
			array( 'key' => 'description',  'title' => __( 'Reservation Description', 'equine-event-manager' ), 'icon_tone' => 'blue',   'icon_key' => 'file-text', 'enable_toggle' => false, 'collapsed' => false ),
			array( 'key' => 'checkin',      'title' => __( 'Check-In / Check-Out',    'equine-event-manager' ), 'icon_tone' => 'teal',   'icon_key' => 'clock',     'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'eventday',     'title' => __( 'Event Day Info',          'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'map-pin',   'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'stall',        'title' => __( 'Stall Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'icon_key' => 'grid',      'enable_toggle' => true,  'collapsed' => false ),
			array( 'key' => 'rv',           'title' => __( 'RV Reservations',         'equine-event-manager' ), 'icon_tone' => 'purple', 'icon_key' => 'truck',     'enable_toggle' => true,  'collapsed' => true  ), // mockup line 650
			array( 'key' => 'addons',       'title' => __( 'General Add-Ons',         'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'plus',      'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'group',        'title' => __( 'Group Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'icon_key' => 'users',     'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'fees',         'title' => __( 'Convenience Fee',         'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'dollar',    'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'agreement',    'title' => __( 'Agreement',               'equine-event-manager' ), 'icon_tone' => 'navy',   'icon_key' => 'file',      'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'cancellation', 'title' => __( 'Cancellation Policy',     'equine-event-manager' ), 'icon_tone' => 'red',    'icon_key' => 'shield-x',  'enable_toggle' => true,  'collapsed' => true  ),
		);
	}

	/**
	 * AJAX: save the reservation (post_status flip + per-section field
	 * saves via legacy save_meta() with pre-validation).
	 *
	 * @return void
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

		// Pre-validate before invoking save_meta() — surfaces validation
		// errors to the JS toast instead of silently no-op'ing.
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
	 * AJAX: unlink the current event from the reservation. Clears
	 * `_en_event_id` and `_en_external_event_id`. Source remains so the
	 * editor knows which type of search to offer next. Reservation
	 * title + dates persist as the pre-unlink snapshot.
	 *
	 * @return void
	 */
	public static function ajax_unlink_event() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_reservation_editor', '_eem_editor_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$post           = $reservation_id > 0 ? get_post( $reservation_id ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		delete_post_meta( $reservation_id, '_en_event_id' );
		delete_post_meta( $reservation_id, '_en_external_event_id' );

		wp_send_json_success( array(
			'reservation_id' => $reservation_id,
			'message'        => __( 'Event unlinked.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX: move the reservation to Trash. Returns the Reservations list
	 * URL for the JS to redirect to.
	 *
	 * @return void
	 */
	public static function ajax_trash() {
		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_reservation_editor', '_eem_editor_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$post           = $reservation_id > 0 ? get_post( $reservation_id ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		$result = wp_trash_post( $reservation_id );
		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Unable to move to Trash.', 'equine-event-manager' ) ), 500 );
		}

		wp_send_json_success( array(
			'reservation_id' => $reservation_id,
			'redirect_url'   => admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ),
			'message'        => __( 'Reservation moved to Trash.', 'equine-event-manager' ),
		) );
	}

	/**
	 * Graceful "reservation not found" render.
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
