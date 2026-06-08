<?php
/**
 * Reservation editor screen controller.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles reservation editor metaboxes and screen assets.
 */
class EEM_Reservation_Editor {

	/**
	 * Reservations CPT service.
	 *
	 * @var EEM_Reservations_CPT
	 */
	private $reservations_cpt;

	/**
	 * Constructor.
	 *
	 * @param EEM_Reservations_CPT $reservations_cpt Reservations CPT handler.
	 */
	public function __construct( $reservations_cpt ) {
		$this->reservations_cpt = $reservations_cpt;
	}

	/**
	 * Ensure the reservation editor always gets the editor shell body classes.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public function filter_editor_shell_body_class( $classes ) {
		if ( ! $this->is_reservation_editor_request() ) {
			return $classes;
		}

		return trim( $classes . ' eem-shell-page eem-shell-page--header eem-shell-page--editor' );
	}

	/**
	 * Force a stable metabox order for the reservation editor.
	 *
	 * Old saved WordPress screen preferences can keep moving cards back into the
	 * side rail, so we override the layout here instead of relying on user state.
	 *
	 * @param mixed $order Existing order.
	 * @return array<string, string>|mixed
	 */
	public function filter_editor_meta_box_order( $order ) {
		if ( ! $this->is_reservation_editor_request() ) {
			return $order;
		}

		return array(
			'side'     => '',
			'normal'   => 'equine_event_manager_event_link,submitdiv,equine_event_manager_reservation_description,equine_event_manager_available_dates,equine_event_manager_checkin_checkout,equine_event_manager_stall_rules,equine_event_manager_rv_rules,equine_event_manager_general_addons,equine_event_manager_group_reservations,equine_event_manager_venue_map,equine_event_manager_venue_agreement,equine_event_manager_fees',
			'advanced' => '',
		);
	}

	/**
	 * Enqueue the shared backend shell stylesheet directly for reservation edit screens.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_editor_shell_styles( $hook_suffix = '' ) {
		if ( ! $this->is_reservation_editor_request( $hook_suffix ) ) {
			return;
		}

		$ver = defined( 'EQUINE_EVENT_MANAGER_VERSION' ) ? EQUINE_EVENT_MANAGER_VERSION : false;
		wp_enqueue_style( 'eem-admin', EQUINE_EVENT_MANAGER_URL . 'assets/css/admin.css', array(), $ver );
		wp_enqueue_style( 'eem-admin-legacy', EQUINE_EVENT_MANAGER_URL . 'assets/css/admin-legacy.css', array( 'eem-admin' ), $ver );
		wp_enqueue_script( 'eem-admin', EQUINE_EVENT_MANAGER_URL . 'assets/js/admin.js', array(), $ver, true );
		// Native Map Builder modal (replaces the Google-Sheet connector); depends on
		// eem-admin for window.EEM + EEM.showSaveToast.
		wp_enqueue_script( 'eem-map-builder', EQUINE_EVENT_MANAGER_URL . 'assets/js/eem-map-builder.js', array( 'eem-admin' ), $ver, true );
	}

	/**
	 * Print a hard fallback stylesheet hook for reservation editor screens.
	 *
	 * This keeps the editor shell alive even if the normal enqueue path is bypassed
	 * by WordPress edit-screen timing or another plugin/theme admin override.
	 *
	 * @return void
	 */
	public function print_editor_shell_fallback_assets() {
		if ( ! $this->is_reservation_editor_request() ) {
			return;
		}

		$version = defined( 'EQUINE_EVENT_MANAGER_VERSION' ) ? EQUINE_EVENT_MANAGER_VERSION : '';
		$href    = EQUINE_EVENT_MANAGER_URL . 'assets/css/admin.css';

		if ( '' !== $version ) {
			$href = add_query_arg( 'ver', rawurlencode( $version ), $href );
		}
		?>
		<link rel="stylesheet" id="equine-event-manager-editor-shell-fallback" href="<?php echo esc_url( $href ); ?>" media="all" />
		<style id="equine-event-manager-editor-shell-critical">
			body.post-type-en_reservation.post-php .wrap > h1.wp-heading-inline,
			body.post-type-en_reservation.post-php .wrap > a.page-title-action,
			body.post-type-en_reservation.post-php .wrap > hr.wp-header-end,
			body.post-type-en_reservation.post-new-php .wrap > h1.wp-heading-inline,
			body.post-type-en_reservation.post-new-php .wrap > a.page-title-action,
			body.post-type-en_reservation.post-new-php .wrap > hr.wp-header-end {
				display: none !important;
			}
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (!document.body) {
					return;
				}

				document.body.classList.add('eem-shell-page', 'eem-shell-page--header', 'eem-shell-page--editor');

				var wrap = document.querySelector('.wrap');
				if (!wrap) {
					return;
				}

				wrap.classList.add('eem-shell-wrap', 'eem-shell-wrap--header', 'eem-shell-wrap--editor');

				wrap.querySelectorAll('h1.wp-heading-inline, a.page-title-action, hr.wp-header-end').forEach(function (node) {
					node.style.display = 'none';
				});

				var shellHeader = wrap.querySelector('.eem-shell-header');
				if (shellHeader && wrap.firstElementChild !== shellHeader) {
					wrap.insertBefore(shellHeader, wrap.firstChild);
				}

				wrap.querySelectorAll('.equine-event-manager-brand-banner, .equine-event-manager-page-heading, .eem-shell-header + .equine-event-manager-brand-banner').forEach(function (node) {
					node.remove();
				});

				Array.prototype.slice.call(wrap.children).forEach(function (child) {
					if (!child || child === shellHeader || !child.querySelector) {
						return;
					}

					if (child.querySelector('.eem-shell-header')) {
						return;
					}

					if (child.querySelector('#poststuff, #titlediv, #post-body')) {
						return;
					}

					if (child.querySelector('img[src*="equine-event-manager-logo"]')) {
						child.remove();
					}
				});

				Array.prototype.slice.call(wrap.querySelectorAll(':scope > img[src*="equine-event-manager-logo"], :scope > a img[src*="equine-event-manager-logo"], :scope > img[src*="equine-event-manager-mark"], :scope > a img[src*="equine-event-manager-mark"]')).forEach(function (node) {
					var removable = node.closest('a') || node;
					if (removable && removable.parentNode === wrap) {
						removable.remove();
					}
				});

				var titleDiv = document.getElementById('titlediv');
				if (titleDiv) {
					titleDiv.classList.add('eem-editor-title-card');
				}

				var sideSortables = document.getElementById('side-sortables') || wrap.querySelector('#postbox-container-1 .meta-box-sortables');
				var normalSortables = document.getElementById('normal-sortables') || wrap.querySelector('#postbox-container-2 #normal-sortables');
				var advancedSortables = document.getElementById('advanced-sortables') || wrap.querySelector('#postbox-container-2 #advanced-sortables');
				var submitDiv = document.getElementById('submitdiv');
				var eventLinkBox = document.getElementById('equine_event_manager_event_link');

				function moveToSortable(box, sortable) {
					if (!box || !sortable || box.parentNode === sortable) {
						return;
					}

					sortable.appendChild(box);
				}

				function moveToMain(box) {
					if (!box) {
						return;
					}

					if (normalSortables) {
						moveToSortable(box, normalSortables);
						return;
					}

					if (advancedSortables) {
						moveToSortable(box, advancedSortables);
					}
				}

				function enforceEditorLayout() {
					Array.prototype.slice.call(wrap.querySelectorAll('#poststuff .postbox')).forEach(function (box) {
						if (!box || !box.id) {
							return;
						}

						moveToMain(box);
					});

					moveToMain(eventLinkBox);
					moveToMain(submitDiv);
				}

				enforceEditorLayout();
				window.requestAnimationFrame(enforceEditorLayout);
				window.setTimeout(enforceEditorLayout, 120);
				window.setTimeout(enforceEditorLayout, 360);
				window.addEventListener('load', enforceEditorLayout);

				document.querySelectorAll('.postbox').forEach(function (card) {
					card.classList.add('eem-editor-shell-card');
				});
			});
		</script>
		<?php
	}

	/**
	 * Determine whether the current request is editing a reservation.
	 *
	 * @param string $hook_suffix Optional admin hook suffix.
	 * @return bool
	 */
	private function is_reservation_editor_request( $hook_suffix = '' ) {
		$screen = get_current_screen();

		if ( $screen && EEM_Reservations_CPT::POST_TYPE === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return true;
		}

		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return false;
		}

		$post_type = '';
		$post_id   = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( $post_id > 0 ) {
			$post_type = get_post_type( $post_id );
		} elseif ( isset( $_GET['post_type'] ) ) {
			$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}

		return EEM_Reservations_CPT::POST_TYPE === $post_type;
	}

	/**
	 * RETIRED in C7.C.1 — meta-box registration is no longer the editor
	 * surface. The Reservation Editor is now a custom render page
	 * (EEM_Reservation_Editor_Page); this method is intentionally a
	 * no-op so any stray `add_meta_boxes_en_reservation` action wiring
	 * registers zero boxes. The original 11 add-meta-box registrations
	 * have been deleted; do NOT re-add them without a full architectural
	 * conversation. The render_*_meta_box() callbacks below remain only
	 * because nested workspace / event-link rendering still references
	 * shared markup; full deletion happens at end of C7 lineage when
	 * nothing left calls EEM_Reservation_Editor for render.
	 *
	 * @deprecated 2.4.0 No longer registers boxes. See EEM_Reservation_Editor_Page.
	 * @param WP_Post|null $post Current post.
	 * @return void
	 */
	public function register_meta_boxes( $post = null ) {
		// Intentional no-op. See docblock above. C7.C.1 smoke Decision F
		// guard asserts both the static-source absence of the
		// add-meta-box function call in this file AND the runtime
		// absence of registered boxes when the action fires.
		return;
	}

	/**
	 * Render the thin app header above the reservation editor.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_editor_header( $post ) {
		if ( ! $post instanceof WP_Post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}
		$logo_url = EQUINE_EVENT_MANAGER_URL . 'admin/images/equine-event-manager-logo.png';
		?>
		<header class="eem-shell-header">
			<div class="eem-shell-header__inner">
				<div class="eem-shell-header__brand">
					<img class="eem-shell-header__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Equine Event Manager', 'equine-event-manager' ); ?>" width="150">
					<div class="eem-shell-header__copy">
						<h1 class="eem-shell-header__title"><?php esc_html_e( 'Edit Reservation', 'equine-event-manager' ); ?></h1>
					</div>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Render the reservation editor overview card after the title field.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_editor_overview( $post ) {
		if ( ! $post instanceof WP_Post || EEM_Reservations_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		$summary = $post->ID ? $this->reservations_cpt->get_editor_summary( $post->ID ) : array();
		?>
		<div class="postbox eem-editor-overview-card">
			<div class="inside">
				<p>
					<strong><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?>:</strong>
					<?php
					echo esc_html(
						! empty( $summary['has_linked_event'] )
							? $summary['event_name']
							: __( 'No event linked yet', 'equine-event-manager' )
					);
					?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Dates', 'equine-event-manager' ); ?>:</strong>
					<?php echo esc_html( ! empty( $summary['date_range_label'] ) ? $summary['date_range_label'] : __( 'Dates unavailable', 'equine-event-manager' ) ); ?>
				</p>
			</div>
		</div>
		<?php
	}

}
