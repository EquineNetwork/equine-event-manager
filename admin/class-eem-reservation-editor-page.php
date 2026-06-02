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
	 * Intercept `post-new.php?post_type=en_reservation` and redirect to the
	 * custom editor with a freshly-created draft reservation. Wired to
	 * `load-post-new.php` so the WP default new-post screen never renders.
	 *
	 * @return void
	 */
	public static function maybe_redirect_new_reservation() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['post_type'] ) ) {
			return;
		}
		if ( EEM_Reservations_CPT::POST_TYPE !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => EEM_Reservations_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => __( 'New Reservation', 'equine-event-manager' ),
			)
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			// Fall through to WP default editor on failure (graceful degradation).
			return;
		}

		wp_safe_redirect( self::url( $new_id ), 302 );
		exit;
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

		// FIX 2 (2.3.44) — Mirror on page load: if the linked TEC event was renamed
		// since the last save, sync the reservation post_title/post_name now so the
		// header and breadcrumb always show the current event title without requiring
		// the admin to manually save first.
		self::apply_mirror( $reservation_id );

		$source_event_title  = self::resolve_page_title( $post, $reservation_id );

		// C8 — Resolve event dates for the event-anchor header meta line.
		$event_dates = '';
		if ( class_exists( 'EEM_Reservation_Source_Resolver' ) && class_exists( 'EEM_Dashboard_Repo' ) ) {
			$_c8_fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
			$event_dates = EEM_Dashboard_Repo::format_date_range(
				isset( $_c8_fields['start_date'] ) ? (string) $_c8_fields['start_date'] : '',
				isset( $_c8_fields['end_date'] )   ? (string) $_c8_fields['end_date']   : ''
			);
		}

		// FIX 1 — wire typeahead to real TEC events. Reverse-lookup gives the
		// authoritative currently-linked event ID; nonce authorises the search AJAX.
		$_cpt_obj             = new EEM_Reservations_CPT();
		$current_tec_event_id = $_cpt_obj->get_tec_event_id_for_reservation( $reservation_id );
		$_search_nonce        = wp_create_nonce( 'equine_event_manager_search_tec_events' );

		// 2.3.56 — Hard gate: a reservation must be linked to an event before its
		// configuration form can be filled out. "Linked" is the presence of an
		// event reference, which is exactly what the editor writes on save:
		//   - native / TEC sources  -> `_en_event_id`
		//   - feed / external source -> `_en_external_event_id`
		//   - belt-and-braces: a TEC event reverse-lookup hit.
		$has_linked_event = ( absint( get_post_meta( $reservation_id, '_en_event_id', true ) ) > 0 )
			|| ( '' !== trim( (string) get_post_meta( $reservation_id, '_en_external_event_id', true ) ) )
			|| ( $current_tec_event_id > 0 );
		?>
		<div class="eem-page">
			<?php
			eem_render_breadcrumb( array(
				array(
					'label' => __( 'Reservations', 'equine-event-manager' ),
					'url'   => admin_url( 'admin.php?page=' . EEM_Reservations_List_Page::MENU_SLUG ),
				),
				array(
					'label' => $source_event_title,
				),
			) );
			?>
			<div class="eem-plugin-wrap">
				<!-- C8 — Event-anchor header replaces .eem-plugin-subtitle + meta-line.
				     Rail Linked Event card retired; typeahead moves to the plugin header. -->
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<!-- 2.3.56 — Reservation name is read-only; it ALWAYS inherits the
						     linked event title. The pencil inline-edit was removed — admins
						     can no longer rename a reservation. -->
						<div class="eem-res-name-edit-wrap">
							<div class="eem-res-name-view" id="eem-res-name-view">
								<?php if ( $has_linked_event ) : ?>
									<span class="eem-res-name-eyebrow"><?php esc_html_e( 'Reservation Details for', 'equine-event-manager' ); ?></span>
								<?php endif; ?>
								<h1 class="eem-plugin-title" id="eem-header-event-name"><?php echo esc_html( $source_event_title ); ?></h1>
							</div>
						</div>
						<div class="eem-plugin-header-meta" id="eem-header-meta"><?php
							echo esc_html__( 'Editing Reservation', 'equine-event-manager' );
							echo ' #' . esc_html( (string) $reservation_id );
							if ( '' !== $event_dates ) {
								echo ' &nbsp;&middot;&nbsp; ' . esc_html( $event_dates );
							}
						?></div>
						<!-- Inline typeahead — shown when Change Event clicked, OR always
						     open when no event is linked yet (the hard-gate picker). -->
						<input type="hidden"
							id="eem-linked-event-id-input"
							name="en_reservation[event_id]"
							value="<?php echo esc_attr( (string) $current_tec_event_id ); ?>">
						<div class="eem-header-typeahead" id="eem-header-typeahead"
							style="<?php echo $has_linked_event ? 'display:none;' : ''; ?>"
							data-current-event-id="<?php echo esc_attr( (string) $current_tec_event_id ); ?>"
							data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"
							data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
							data-search-nonce="<?php echo esc_attr( $_search_nonce ); ?>">
							<input type="text"
								class="eem-event-search-input"
								id="eem-event-search-input"
								placeholder="<?php esc_attr_e( "Search events\xe2\x80\xa6", 'equine-event-manager' ); ?>"
								data-eem-input-action="header-filter-events"
								autocomplete="off">
							<div class="eem-event-search-results" id="eem-event-search-results"></div>
							<button type="button" class="eem-header-typeahead-cancel" data-eem-action="header-cancel-change">
								<?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?>
							</button>
						</div>
					</div>
					<?php if ( $has_linked_event ) : ?>
						<div class="eem-header-actions">
							<?php
							// 2.3.83 — "View Event" opens the linked event's public page in
							// a new tab so admins can preview what customers see.
							$eem_event_permalink = $current_tec_event_id ? get_permalink( $current_tec_event_id ) : '';
							if ( $eem_event_permalink ) :
								?>
								<a class="eem-btn-primary eem-header-action-view"
									href="<?php echo esc_url( $eem_event_permalink ); ?>"
									target="_blank"
									rel="noopener noreferrer">
									<?php esc_html_e( 'View Event', 'equine-event-manager' ); ?>
								</a>
							<?php endif; ?>
							<button type="button"
								class="eem-header-action-change"
								id="eem-header-action-change"
								data-eem-action="header-change-event">
								<?php esc_html_e( 'Change Event', 'equine-event-manager' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</header>
				<div class="eem-edit-body">
					<?php if ( $has_linked_event ) : ?>
						<main class="eem-edit-main eem-reservation-editor-body" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
							<?php self::render_section_skeletons( $reservation_id ); ?>
						</main>
					<?php else : ?>
						<?php
						// 2.3.56 — Hard gate. No linked event yet: show only the link
						// prompt. The event picker is already open in the header above;
						// once an event is chosen and saved, the page reloads with the
						// full configuration form. The reservation name + dates are then
						// inherited from the linked event.
						?>
						<div class="eem-reservation-link-gate" role="status">
							<div class="eem-reservation-link-gate__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
							</div>
							<h2 class="eem-reservation-link-gate__title"><?php esc_html_e( 'Link an event to get started', 'equine-event-manager' ); ?></h2>
							<p class="eem-reservation-link-gate__text">
								<?php esc_html_e( 'Search for the event in the box above, choose it, then click Save. The reservation takes its name and dates from the linked event, so it must be linked before you can configure stalls, RV spaces, add-ons, and pricing.', 'equine-event-manager' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_sticky-save-bar.php'; ?>
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
			case 'venuemap':
				return __( 'This section is disabled. Enable it to upload a venue map customers can download.', 'equine-event-manager' );
			case 'stall':
				return __( 'This section is disabled. Enable it to offer stall reservations.', 'equine-event-manager' );
			case 'rv':
				return __( 'This section is disabled. Enable it to offer RV reservations.', 'equine-event-manager' );
			case 'event_pre_entries':
				return __( 'This section is disabled. Enable it to add class or competition entries customers can purchase.', 'equine-event-manager' );
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
			'venuemap'           => 'venue_map_enabled',
			'stall'              => 'stalls_enabled',
			'rv'                 => 'rv_enabled',
			'event_pre_entries'  => 'event_pre_entries_enabled',
			'eventday'           => 'event_day_enabled',
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
	 * C7.X.16 Issue I — per-section publish-gate validator.
	 *
	 * Returns an array of `[section_key => error_message]` for every
	 * section that is enabled but doesn't satisfy its "valid when ON"
	 * criteria. Called by `ajax_save()` when the resulting status is
	 * `publish` (covers `save_kind='publish'` AND `save_kind='update'`).
	 * Save Draft skips the gate entirely — drafts are explicitly
	 * allowed to be incomplete per Whitney's design rule.
	 *
	 * Rules per Whitney's C7.X.16 spec:
	 *   - Description: always valid (no toggle)
	 *   - Check-In/Check-Out: both times set
	 *   - Event Day Info: ≥1 of 4 fields filled
	 *   - Stall: ≥1 stay-type ON + that rate >0; if Schedule ON, both
	 *     open/close datetimes; if EB ON, cutoff + ≥1 EB rate
	 *   - RV: same shape as Stall
	 *   - General Add-Ons: ≥1 row with name + price >0
	 *   - Group: Riders Per Group >0
	 *   - Convenience Fee: Flat → amount >0; Percentage → % >0
	 *   - Agreement: PDF file uploaded
	 *   - Cancellation: resolver returns non-empty (override OR event
	 *     default — admin doesn't need to type custom text if event
	 *     default exists)
	 *
	 * @param array<string,mixed> $c              Sanitized candidate meta payload.
	 * @param int                  $reservation_id For resolver calls (cancellation).
	 * @return array<string,string>                Empty = valid; keys = section ids.
	 */
	public static function validate_for_publish( array $c, int $reservation_id ) {
		$err = array();

		// Check-In / Check-Out
		if ( ! empty( $c['checkin_checkout_enabled'] ) ) {
			if ( empty( $c['checkin_time'] ) || empty( $c['checkout_time'] ) ) {
				$err['checkin'] = __( 'Check-In/Check-Out is enabled but Check-In Time and Check-Out Time must both be set.', 'equine-event-manager' );
			}
		}

		// Event Day Info — ≥1 of 4 fields
		if ( ! empty( $c['event_day_enabled'] ) ) {
			$any = ! empty( $c['event_day_checkin'] )
				|| ! empty( $c['event_day_bring'] )
				|| ! empty( $c['event_day_parking'] )
				|| ! empty( $c['event_day_contact'] );
			if ( ! $any ) {
				$err['eventday'] = __( 'Event Day Info is enabled but at least one field (Check-In Instructions, What to Bring, Parking, or Event-Day Contact) must be filled.', 'equine-event-manager' );
			}
		}

		// Stall Reservations
		if ( ! empty( $c['stalls_enabled'] ) ) {
			$nightly = ! empty( $c['stall_nightly_enabled'] );
			$weekend = ! empty( $c['stall_weekend_enabled'] );
			if ( ! $nightly && ! $weekend ) {
				$err['stall'] = __( 'Stall Reservations is enabled but at least one stay type (Nightly or Weekend) must be on.', 'equine-event-manager' );
			} else {
				$nightly_rate_ok = $nightly && (float) ( $c['stall_nightly_rate'] ?? 0 ) > 0;
				$weekend_rate_ok = $weekend && (float) ( $c['stall_weekend_rate'] ?? 0 ) > 0;
				if ( ! $nightly_rate_ok && ! $weekend_rate_ok ) {
					$err['stall'] = __( 'Stall Reservations needs a rate above $0 for at least one enabled stay type.', 'equine-event-manager' );
				} elseif ( ! empty( $c['stall_schedule_enabled'] ) && ( empty( $c['stalls_open_at'] ) || empty( $c['stalls_close_at'] ) ) ) {
					$err['stall'] = __( 'Stall Reservations: Schedule is enabled but Open and Close datetimes must both be set.', 'equine-event-manager' );
				} elseif ( ! empty( $c['stall_early_bird_enabled'] ) ) {
					$eb_cutoff_ok    = ! empty( $c['stall_early_bird_cutoff'] );
					$eb_nightly_rate = $nightly && (float) ( $c['stall_early_bird_nightly_rate'] ?? 0 ) > 0;
					$eb_weekend_rate = $weekend && (float) ( $c['stall_early_bird_weekend_rate'] ?? 0 ) > 0;
					if ( ! $eb_cutoff_ok || ( ! $eb_nightly_rate && ! $eb_weekend_rate ) ) {
						$err['stall'] = __( 'Stall Reservations: Early Bird is enabled but cutoff date and at least one Early Bird rate must be set.', 'equine-event-manager' );
					}
				}
			}
		}

		// RV Reservations — parallel to Stall
		if ( ! empty( $c['rv_enabled'] ) ) {
			$nightly = ! empty( $c['rv_nightly_enabled'] );
			$weekend = ! empty( $c['rv_weekend_enabled'] );
			if ( ! $nightly && ! $weekend ) {
				$err['rv'] = __( 'RV Reservations is enabled but at least one stay type (Nightly or Weekend) must be on.', 'equine-event-manager' );
			} else {
				$nightly_rate_ok = $nightly && (float) ( $c['rv_nightly_rate'] ?? 0 ) > 0;
				$weekend_rate_ok = $weekend && (float) ( $c['rv_weekend_rate'] ?? 0 ) > 0;
				if ( ! $nightly_rate_ok && ! $weekend_rate_ok ) {
					$err['rv'] = __( 'RV Reservations needs a rate above $0 for at least one enabled stay type.', 'equine-event-manager' );
				} elseif ( ! empty( $c['rv_schedule_enabled'] ) && ( empty( $c['rv_open_at'] ) || empty( $c['rv_close_at'] ) ) ) {
					$err['rv'] = __( 'RV Reservations: Schedule is enabled but Open and Close datetimes must both be set.', 'equine-event-manager' );
				} elseif ( ! empty( $c['rv_early_bird_enabled'] ) ) {
					$eb_cutoff_ok    = ! empty( $c['rv_early_bird_cutoff'] );
					$eb_nightly_rate = $nightly && (float) ( $c['rv_early_bird_nightly_rate'] ?? 0 ) > 0;
					$eb_weekend_rate = $weekend && (float) ( $c['rv_early_bird_weekend_rate'] ?? 0 ) > 0;
					if ( ! $eb_cutoff_ok || ( ! $eb_nightly_rate && ! $eb_weekend_rate ) ) {
						$err['rv'] = __( 'RV Reservations: Early Bird is enabled but cutoff date and at least one Early Bird rate must be set.', 'equine-event-manager' );
					}
				}
			}
		}

		// General Add-Ons — ≥1 row with name + price >0
		if ( ! empty( $c['general_addons_enabled'] ) ) {
			$addons = isset( $c['general_addons'] ) && is_array( $c['general_addons'] ) ? $c['general_addons'] : array();
			$valid_row = false;
			foreach ( $addons as $row ) {
				$name  = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
				$price = isset( $row['price'] ) ? (float) $row['price'] : 0.0;
				if ( '' !== $name && $price > 0 ) { $valid_row = true; break; }
			}
			if ( ! $valid_row ) {
				$err['addons'] = __( 'General Add-Ons is enabled but at least one add-on with a name and a price above $0 is required.', 'equine-event-manager' );
			}
		}

		// Group Reservations — Riders Per Group is optional (blank = unlimited,
		// 2.3.82). No minimum-count gate; the field's own min="1" stops a
		// nonsensical 0 when a value IS entered.

		// Convenience Fee
		if ( ! empty( $c['convenience_fee_enabled'] ) ) {
			$type  = isset( $c['convenience_fee_type'] ) ? (string) $c['convenience_fee_type'] : 'none';
			$value = isset( $c['convenience_fee_value'] ) ? (float) $c['convenience_fee_value'] : 0.0;
			if ( 'none' === $type ) {
				$err['fees'] = __( 'Convenience Fee is enabled but Fee Type must be Flat or Percentage (not None).', 'equine-event-manager' );
			} elseif ( $value <= 0 ) {
				$err['fees'] = 'flat' === $type
					? __( 'Convenience Fee (Flat): amount must be above $0.', 'equine-event-manager' )
					: __( 'Convenience Fee (Percentage): percentage must be above 0.', 'equine-event-manager' );
			}
		}

		// Agreement — PDF file uploaded
		if ( ! empty( $c['venue_agreement_enabled'] ) ) {
			$file_id = isset( $c['venue_agreement_file_id'] ) ? (int) $c['venue_agreement_file_id'] : 0;
			if ( $file_id <= 0 ) {
				$err['agreement'] = __( 'Agreement is enabled but an agreement PDF must be uploaded.', 'equine-event-manager' );
			}
		}

		// Cancellation Policy — resolver returns non-empty (override OR
		// event default OR global). Resolver is the source of truth so
		// admins don't need to retype if event default is configured.
		if ( ! empty( $c['cancellation_enabled'] ) ) {
			// FIX 3 (2.3.47) — the typed override is authoritative and must be
			// honored unconditionally. The previous code only read the override
			// *inside* a `class_exists( 'EEM_Cancellation_Policy' )` guard, but
			// that class is not defined anywhere in the plugin, so the guard was
			// always false: the override text was never read and this gate
			// errored on every publish even when the admin had filled it in.
			// Read the candidate override first; only consult the (optional)
			// event-default resolver as a fallback when it actually exists.
			$resolved = isset( $c['cancellation_policy_override'] ) ? trim( (string) $c['cancellation_policy_override'] ) : '';
			if ( '' === $resolved
				&& class_exists( 'EEM_Cancellation_Policy' )
				&& method_exists( 'EEM_Cancellation_Policy', 'resolve_for_reservation' ) ) {
				$resolved = (string) EEM_Cancellation_Policy::resolve_for_reservation( $reservation_id );
			}
			if ( '' === trim( $resolved ) ) {
				$err['cancellation'] = __( 'Cancellation Policy is enabled but no policy text is set — either fill the override here or configure an event default on the linked event.', 'equine-event-manager' );
			}
		}

		return $err;
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
			'stall'             => '_section-stall.php',
			'rv'                => '_section-rv.php',
			'event_pre_entries' => '_section-event-pre-entries.php',
			'addons'            => '_section-addons.php',
			'group'        => '_section-group.php',
			'fees'         => '_section-fees.php',
			'venuemap'     => '_section-venuemap.php',
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
			array( 'key' => 'rv',                'title' => __( 'RV Reservations',         'equine-event-manager' ), 'icon_tone' => 'purple', 'icon_key' => 'truck',     'enable_toggle' => true,  'collapsed' => true  ), // mockup line 650
			array( 'key' => 'event_pre_entries', 'title' => __( 'Event Pre-Entries',       'equine-event-manager' ), 'icon_tone' => 'teal',   'icon_key' => 'plus',      'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'addons',            'title' => __( 'General Add-Ons',         'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'plus',      'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'group',        'title' => __( 'Group Reservations',      'equine-event-manager' ), 'icon_tone' => 'green',  'icon_key' => 'users',     'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'fees',         'title' => __( 'Convenience Fee',         'equine-event-manager' ), 'icon_tone' => 'orange', 'icon_key' => 'dollar',    'enable_toggle' => true,  'collapsed' => true  ),
			array( 'key' => 'venuemap',     'title' => __( 'Venue Map',               'equine-event-manager' ), 'icon_tone' => 'teal',   'icon_key' => 'map-pin',   'enable_toggle' => true,  'collapsed' => true  ),
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

		// One-to-one guard: refuse to link an event that already has another
		// active (non-trashed) reservation. The event picker hides taken events
		// client-side; this is the server-side backstop against double-booking
		// (stale dropdown, race, or a direct request).
		if ( isset( $_POST['en_reservation']['event_id'] ) ) {
			$requested_event_id = absint( wp_unslash( $_POST['en_reservation']['event_id'] ) );
			if ( $requested_event_id > 0 ) {
				$guard_cpt = new EEM_Reservations_CPT();
				$conflict  = $guard_cpt->get_active_linked_reservation_id_for_event( $requested_event_id, $reservation_id );
				if ( $conflict > 0 ) {
					wp_send_json_error( array(
						'message' => __( 'That event already has a reservation. Each event can have only one reservation.', 'equine-event-manager' ),
						'code'    => 'event_already_linked',
					), 409 );
				}
			}
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

			// C7.X.16 Issue I — per-section publish-gate validation.
			// Runs ONLY when the resulting status is `publish` (covers
			// save_kind='publish' AND save_kind='update' which both
			// land in publish state; save_kind='save_draft' skips per
			// Whitney's "Drafts allowed to be incomplete" rule).
			// Defense in depth — client-side mirror in admin.js gives
			// immediate UX; this server gate is the source of truth.
			if ( 'publish' === $new_status ) {
				$publish_errors = self::validate_for_publish( $candidate, $reservation_id );
				if ( ! empty( $publish_errors ) ) {
					$first_key  = array_key_first( $publish_errors );
					$count      = count( $publish_errors );
					$summary    = 1 === $count
						? $publish_errors[ $first_key ]
						: sprintf(
							/* translators: %d: number of sections needing attention */
							_n( '%d section needs attention before publishing.', '%d sections need attention before publishing.', $count, 'equine-event-manager' ),
							$count
						);
					wp_send_json_error( array(
						'message'       => $summary,
						'errors'        => $publish_errors,
						'first_section' => $first_key,
						'count'         => $count,
						'code'          => 'publish_validation_failed',
					), 422 );
				}
			}

			$_POST['equine_event_manager_reservation_meta_nonce'] = wp_create_nonce( 'equine_event_manager_save_reservation_meta' );
			$refreshed = get_post( $reservation_id );
			$cpt->save_meta( $reservation_id, $refreshed );
		}

		// ── C8 mapped-layout meta (not routed through en_reservation[]) ──

		// Stall rows
		if ( isset( $_POST['eem_stall_rows'] ) && is_array( $_POST['eem_stall_rows'] ) ) {
			$stall_rows_raw = wp_unslash( $_POST['eem_stall_rows'] );
			$stall_rows_clean = array();
			foreach ( (array) $stall_rows_raw as $row ) {
				if ( ! is_array( $row ) ) continue;
				$stall_rows_clean[] = array(
					'name'      => sanitize_text_field( (string) ( $row['name']      ?? '' ) ),
					'layout'    => in_array( (string) ( $row['layout'] ?? '' ), array( 'one-sided', 'back-to-back' ), true ) ? (string) $row['layout'] : 'one-sided',
					'first'     => sanitize_text_field( (string) ( $row['first']     ?? '' ) ),
					'last'      => sanitize_text_field( (string) ( $row['last']      ?? '' ) ),
					'top_first' => sanitize_text_field( (string) ( $row['top_first'] ?? '' ) ),
					'top_last'  => sanitize_text_field( (string) ( $row['top_last']  ?? '' ) ),
					'bot_first' => sanitize_text_field( (string) ( $row['bot_first'] ?? '' ) ),
					'bot_last'  => sanitize_text_field( (string) ( $row['bot_last']  ?? '' ) ),
				);
			}
			update_post_meta( $reservation_id, '_en_stall_rows', $stall_rows_clean );
		}

		// Blocked stalls
		if ( isset( $_POST['eem_blocked_stalls'] ) ) {
			$bs_raw = wp_unslash( $_POST['eem_blocked_stalls'] );
			$blocked_stalls_clean = array_map( 'sanitize_text_field', (array) $bs_raw );
			update_post_meta( $reservation_id, '_en_blocked_stalls', array_values( array_filter( $blocked_stalls_clean ) ) );
		}

		// Scenario B (V1 #4): two independent settings — Stall Inventory Type +
		// Customer Selection. Write both new keys plus the DERIVED legacy
		// `_en_stall_selection_mode` so every existing reader stays consistent.
		// Falls back to the legacy single field if (for any reason) only it posts.
		if ( isset( $_POST['stall_inventory_type'] ) || isset( $_POST['stall_customer_selection'] ) || isset( $_POST['stall_selection_mode'] ) ) {
			if ( isset( $_POST['stall_inventory_type'] ) || isset( $_POST['stall_customer_selection'] ) ) {
				$inv_type = EEM_Reservations_CPT::sanitize_stall_inventory_type( isset( $_POST['stall_inventory_type'] ) ? wp_unslash( $_POST['stall_inventory_type'] ) : 'quantity_only' );
				$cust_sel = EEM_Reservations_CPT::sanitize_stall_customer_selection( isset( $_POST['stall_customer_selection'] ) ? wp_unslash( $_POST['stall_customer_selection'] ) : 'quantity' );
			} else {
				$legacy_submit = sanitize_key( wp_unslash( $_POST['stall_selection_mode'] ) );
				$legacy_submit = in_array( $legacy_submit, array( 'quantity', 'exact_map' ), true ) ? $legacy_submit : 'quantity';
				$inv_type      = ( 'exact_map' === $legacy_submit ) ? 'numbered' : 'quantity_only';
				$cust_sel      = ( 'exact_map' === $legacy_submit ) ? 'pick_layout' : 'quantity';
			}
			if ( 'quantity_only' === $inv_type ) {
				$cust_sel = 'quantity'; // pick-from-layout requires numbered stalls.
			}
			update_post_meta( $reservation_id, '_en_stall_inventory_type', $inv_type );
			update_post_meta( $reservation_id, '_en_stall_customer_selection', $cust_sel );
			update_post_meta( $reservation_id, '_en_stall_selection_mode', EEM_Reservations_CPT::derive_stall_selection_mode( $inv_type, $cust_sel ) );
		}

		// RV selection mode (Bulk = 'quantity', Mapped = 'exact_map')
		if ( isset( $_POST['rv_selection_mode'] ) ) {
			$rv_mode_raw = sanitize_key( wp_unslash( $_POST['rv_selection_mode'] ) );
			update_post_meta( $reservation_id, '_en_rv_selection_mode', in_array( $rv_mode_raw, array( 'quantity', 'exact_map' ), true ) ? $rv_mode_raw : 'quantity' );
		}

		// Max stalls per customer — Enforced at checkout (C10 scope) — zero/empty = unlimited.
		if ( array_key_exists( 'eem_stall_max_per_customer', $_POST ) ) {
			$smax = absint( wp_unslash( $_POST['eem_stall_max_per_customer'] ) );
			update_post_meta( $reservation_id, '_en_stall_max_per_customer', $smax > 0 ? $smax : '' );
		}

		// Max RV lots per customer — Enforced at checkout (C10 scope) — zero/empty = unlimited.
		if ( array_key_exists( 'eem_rv_max_per_customer', $_POST ) ) {
			$rmax = absint( wp_unslash( $_POST['eem_rv_max_per_customer'] ) );
			update_post_meta( $reservation_id, '_en_rv_max_per_customer', $rmax > 0 ? $rmax : '' );
		}

		// Stall map attachment ID
		if ( isset( $_POST['eem_stall_map_id'] ) ) {
			update_post_meta( $reservation_id, '_en_stall_map_id', absint( wp_unslash( $_POST['eem_stall_map_id'] ) ) );
		}

		// RV lot map attachment ID (2.3.23)
		if ( isset( $_POST['eem_rv_lot_map_id'] ) ) {
			update_post_meta( $reservation_id, '_en_rv_lot_map_id', absint( wp_unslash( $_POST['eem_rv_lot_map_id'] ) ) );
		}

		// RV zones
		if ( isset( $_POST['eem_rv_zones'] ) && is_array( $_POST['eem_rv_zones'] ) ) {
			$rv_zones_raw = wp_unslash( $_POST['eem_rv_zones'] );
			$rv_zones_clean = array();
			foreach ( (array) $rv_zones_raw as $zone ) {
				if ( ! is_array( $zone ) ) continue;
				$rv_zones_clean[] = array(
					'name'          => sanitize_text_field( (string) ( $zone['name']          ?? '' ) ),
					'color'         => sanitize_text_field( (string) ( $zone['color']         ?? '#1668F2' ) ),
					'nightly'       => number_format( (float) ( $zone['nightly']              ?? 0 ), 2, '.', '' ),
					'weekend'       => number_format( (float) ( $zone['weekend']              ?? 0 ), 2, '.', '' ),
					'available_qty' => absint( $zone['available_qty'] ?? 0 ),
				);
			}
			update_post_meta( $reservation_id, '_en_rv_zones', $rv_zones_clean );
		}

		// RV rows
		if ( isset( $_POST['eem_rv_rows'] ) && is_array( $_POST['eem_rv_rows'] ) ) {
			$rv_rows_raw = wp_unslash( $_POST['eem_rv_rows'] );
			$rv_rows_clean = array();
			foreach ( (array) $rv_rows_raw as $row ) {
				if ( ! is_array( $row ) ) continue;
				// V1 zone model: each row has a single zone_id (the zone the whole row belongs to).
				// Lots in a row without a zone_id are unavailable to customers at checkout.
				// See: docs/c10-contracts.md
				$rv_rows_clean[] = array(
					'name'      => sanitize_text_field( (string) ( $row['name']      ?? '' ) ),
					'layout'    => in_array( (string) ( $row['layout'] ?? '' ), array( 'one-sided', 'back-to-back' ), true ) ? (string) $row['layout'] : 'one-sided',
					'first'     => sanitize_text_field( (string) ( $row['first']     ?? '' ) ),
					'last'      => sanitize_text_field( (string) ( $row['last']      ?? '' ) ),
					'top_first' => sanitize_text_field( (string) ( $row['top_first'] ?? '' ) ),
					'top_last'  => sanitize_text_field( (string) ( $row['top_last']  ?? '' ) ),
					'bot_first' => sanitize_text_field( (string) ( $row['bot_first'] ?? '' ) ),
					'bot_last'  => sanitize_text_field( (string) ( $row['bot_last']  ?? '' ) ),
					'zone_id'   => sanitize_text_field( (string) ( $row['zone_id']   ?? '' ) ),
				);
			}
			update_post_meta( $reservation_id, '_en_rv_rows', $rv_rows_clean );
		}

		// Blocked RV lots
		if ( isset( $_POST['eem_blocked_rv_lots'] ) ) {
			$bl_raw = wp_unslash( $_POST['eem_blocked_rv_lots'] );
			$blocked_rv_clean = array_map( 'sanitize_text_field', (array) $bl_raw );
			update_post_meta( $reservation_id, '_en_blocked_rv_lots', array_values( array_filter( $blocked_rv_clean ) ) );
		}

		// V2 BACKLOG — RV per-lot zone assignments (Paint Mode) removed in V1 (2.3.22).
		// In V1, zones are assigned at row level (each rv_row has a zone_id field).
		// Per-lot painting (_en_rv_lot_zone_assignments) is deferred to V2.
		// C10 ENFORCEMENT CONTRACT: rows with no zone_id = lots in that row are unavailable.
		// See: docs/c10-contracts.md

		// Event Pre-Entries enabled flag.
		// FIX 2 (2.3.47) — write unconditionally. The header toggle's only form
		// field is a hidden `data-eem-section-enabled` mirror that the editor JS
		// collector SKIPS when off (it submits nothing for an off toggle, to
		// match the legacy `isset() ? 1 : 0` convention). The previous
		// `isset()` guard therefore never ran on toggle-OFF, so the prior ON
		// value lingered and the section "came back" enabled after saving it
		// off. Mirror the stall-chart handling above: absent / not "1" = 0.
		update_post_meta(
			$reservation_id,
			'_en_event_pre_entries_enabled',
			isset( $_POST['eem_event_pre_entries_enabled'] ) && '1' === (string) wp_unslash( $_POST['eem_event_pre_entries_enabled'] ) ? 1 : 0
		);

		// Event Pre-Entries rows
		if ( isset( $_POST['eem_event_pre_entries'] ) && is_array( $_POST['eem_event_pre_entries'] ) ) {
			$pe_raw = wp_unslash( $_POST['eem_event_pre_entries'] );
			$pe_clean = array();
			foreach ( (array) $pe_raw as $entry ) {
				if ( ! is_array( $entry ) ) continue;
				$pe_max = absint( $entry['max_per_customer'] ?? 0 );
				$pe_clean[] = array(
					'title'           => sanitize_text_field( (string) ( $entry['title']     ?? '' ) ),
					'inventory'       => absint( $entry['inventory'] ?? 0 ),
					'price'           => number_format( (float) ( $entry['price'] ?? 0 ), 2, '.', '' ),
					// Enforced at checkout (C10 scope) — zero/empty = unlimited.
					'max_per_customer' => $pe_max > 0 ? $pe_max : '',
				);
			}
			update_post_meta( $reservation_id, '_en_event_pre_entries', $pe_clean );
		}

		// FIX 2 (2.3.43/2.3.44) — Unconditional name/slug auto-mirror on every save.
		// Logic extracted into apply_mirror() so the same path fires on ajax_save(),
		// render() page-load, and on_tec_event_save() (FIX 2 expansion, 2.3.44).
		self::apply_mirror( $reservation_id );

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
	 * AJAX: rename the reservation — called by the pencil inline-edit form in the
	 * editor header (FIX 1, 2.3.43).  Accepts `eem_res_name` (the new title; empty
	 * = clear override and mirror immediately from the linked event title).  Sets
	 * the `_eem_reservation_name_overridden` meta flag accordingly so the next
	 * `ajax_save()` FIX-2 mirror block picks up the correct state.
	 *
	 * Nonce: `eem_rename_reservation` created inline in `render()` and posted as
	 * `_eem_rename_nonce`.
	 *
	 * @return void  wp_send_json_*; never returns.
	 */
	public static function ajax_rename(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_rename_reservation', '_eem_rename_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$post           = $reservation_id > 0 ? get_post( $reservation_id ) : null;
		if ( ! $post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found.', 'equine-event-manager' ) ), 404 );
		}

		// 2.3.56 — Reservation names are no longer editable; they always inherit
		// the linked event name. Any submitted name is ignored: this endpoint now
		// only ever resets the title to the current linked-event title (defensive
		// — the pencil UI that called it has been removed).
		$new_name = '';
		if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
			$_src     = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
			$new_name = isset( $_src['title'] ) ? (string) $_src['title'] : '';
		}
		if ( '' === $new_name ) {
			$new_name = (string) get_the_title( $reservation_id );
		}

		wp_update_post( array(
			'ID'         => $reservation_id,
			'post_title' => $new_name,
			'post_name'  => sanitize_title( $new_name ),
		) );

		wp_send_json_success( array(
			'name'    => $new_name,
			'message' => __( 'Reservation name is inherited from the linked event.', 'equine-event-manager' ),
		) );
	}

	// ── FIX 2 (2.3.44) ─────────────────────────────────────────────────────────

	/**
	 * Sync the reservation's post_title and post_name to the current linked-event
	 * title when the corresponding override flag is 0.  Called from:
	 *
	 *  - `render()`              — page-load sync so header is always current.
	 *  - `ajax_save()`           — on every AJAX save (replaces inline 2.3.43 block).
	 *  - `on_tec_event_save()`   — pushed to all linked reservations when TEC event saved.
	 *
	 * No-ops when both override flags are 1, or when no linked event title can be
	 * resolved.  Safe to call multiple times in the same request.
	 *
	 * @param int $reservation_id `en_reservation` post ID.
	 * @return void
	 */
	private static function apply_mirror( int $reservation_id ): void {
		// 2.3.56 — Reservations ALWAYS inherit the linked event name. The admin
		// can no longer override the name or slug (override flags retired), so
		// this mirrors unconditionally. When no event is linked yet, the existing
		// placeholder title is left untouched.
		if ( ! class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
			return;
		}

		$src           = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
		$mirrored_name = isset( $src['title'] ) ? (string) $src['title'] : '';
		if ( '' === $mirrored_name ) {
			// No linked event — keep whatever placeholder title the post has.
			return;
		}

		// Only write when something actually changed (avoids a needless
		// wp_update_post + slug churn on every page load).
		$current = (string) get_post_field( 'post_title', $reservation_id );
		if ( $current === $mirrored_name ) {
			return;
		}

		wp_update_post( array(
			'ID'         => $reservation_id,
			'post_title' => $mirrored_name,
			'post_name'  => sanitize_title( $mirrored_name ),
		) );
	}

	/**
	 * Hook: `save_post_tribe_events` — when admin saves a TEC event, propagate the
	 * event's new title to every `en_reservation` linked to it (via `_en_event_id`
	 * meta) that has NOT set a manual name or slug override.
	 *
	 * Skips revisions and autosaves.  Fires at priority 20 (after TEC's own hooks
	 * so the event title is fully committed before we read it).
	 *
	 * @param int      $event_id  TEC event post ID.
	 * @param \WP_Post $post      The saved post object.
	 * @return void
	 */
	public static function on_tec_event_save( int $event_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $event_id ) || wp_is_post_autosave( $event_id ) ) {
			return;
		}

		// Find all reservations whose _en_event_id points at this event.
		$linked = get_posts( array(
			'post_type'      => EEM_Reservations_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_en_event_id',
					'value' => (string) $event_id,
				),
			),
		) );

		foreach ( (array) $linked as $reservation_id ) {
			self::apply_mirror( (int) $reservation_id );
		}
	}

	// ── End FIX 2 (2.3.44) ──────────────────────────────────────────────────────

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
