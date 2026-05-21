<?php
/**
 * Reservation editor screen controller.
 *
 * @package EEM_Plugin
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

		wp_enqueue_style(
			'equine-event-manager-admin-shell',
			EQUINE_EVENT_MANAGER_URL . 'admin/css/equine-event-manager-admin.css',
			array(),
			defined( 'EQUINE_EVENT_MANAGER_VERSION' ) ? EQUINE_EVENT_MANAGER_VERSION : false
		);
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
		$href    = EQUINE_EVENT_MANAGER_URL . 'admin/css/equine-event-manager-admin.css';

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
	 * Register reservation setup meta boxes.
	 *
	 * @param WP_Post|null $post Current post.
	 * @return void
	 */
	public function register_meta_boxes( $post = null ) {
		add_meta_box(
			'equine_event_manager_event_link',
			__( 'Event Link', 'equine-event-manager' ),
			array( $this, 'render_event_link_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'equine_event_manager_reservation_description',
			__( 'Reservation Description', 'equine-event-manager' ),
			array( $this, 'render_reservation_description_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'equine_event_manager_available_dates',
			__( 'Available Reservation Dates', 'equine-event-manager' ),
			array( $this, 'render_available_dates_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'equine_event_manager_checkin_checkout',
			__( 'Check-In/Check-Out', 'equine-event-manager' ),
			array( $this, 'render_checkin_checkout_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_stall_rules',
			__( 'Stall Reservations', 'equine-event-manager' ),
			array( $this, 'render_stall_rules_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_rv_rules',
			__( 'RV Reservations', 'equine-event-manager' ),
			array( $this->reservations_cpt, 'render_rv_rules_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_general_addons',
			__( 'General Add-Ons', 'equine-event-manager' ),
			array( $this, 'render_general_addons_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_group_reservations',
			__( 'Group Reservations', 'equine-event-manager' ),
			array( $this, 'render_group_reservations_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_venue_map',
			__( 'Venue Map', 'equine-event-manager' ),
			array( $this, 'render_venue_map_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_venue_agreement',
			__( 'Agreement', 'equine-event-manager' ),
			array( $this, 'render_venue_agreement_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'equine_event_manager_fees',
			__( 'Fees', 'equine-event-manager' ),
			array( $this, 'render_fees_meta_box' ),
			EEM_Reservations_CPT::POST_TYPE,
			'normal',
			'default'
		);

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

	/**
	 * Build quick actions for the reservation workspace overview.
	 *
	 * @param WP_Post $post Reservation post.
	 * @param array   $summary Reservation summary.
	 * @param array   $data Reservation meta values.
	 * @return array<int, array<string, string|bool>>
	 */
	private function get_overview_actions( $post, $summary, $data ) {
		$actions = array();

		if ( ! $post instanceof WP_Post || empty( $post->ID ) ) {
			return $actions;
		}

		$preview_url = home_url( user_trailingslashit( EEM_Events::VIRTUAL_EVENT_ROUTE_BASE . '/' . absint( $post->ID ) ) );
		$actions[]   = array(
			'label'        => __( 'Preview Reservation Page', 'equine-event-manager' ),
			'url'          => $preview_url,
			'target'       => '_blank',
			'is_secondary' => false,
		);

		if ( ! empty( $data['event_id'] ) ) {
			$actions[] = array(
				'label'        => __( 'Edit Linked Event', 'equine-event-manager' ),
				'url'          => get_edit_post_link( absint( $data['event_id'] ), '' ),
				'target'       => '',
				'is_secondary' => true,
			);
		}

		if ( ! empty( $data['stall_chart_enabled'] ) || ! empty( $data['rv_lot_selection_enabled'] ) ) {
			$actions[] = array(
				'label'        => __( 'Open Assignment Chart', 'equine-event-manager' ),
				'url'          => admin_url( 'admin.php?page=equine-event-manager-stall-chart&reservation_id=' . absint( $post->ID ) ),
				'target'       => '',
				'is_secondary' => true,
			);
		}

		return array_values(
			array_filter(
				$actions,
				function ( $action ) {
					return ! empty( $action['url'] );
				}
			)
		);
	}

	/**
	 * Render the workspace tools sidebar card.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_workspace_tools_meta_box( $post ) {
		if ( ! $post instanceof WP_Post || empty( $post->ID ) ) {
			return;
		}

		$summary          = $this->reservations_cpt->get_editor_summary( $post->ID );
		$data             = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		$overview_actions = $this->get_overview_actions( $post, $summary, $data );

		if ( empty( $overview_actions ) ) {
			echo '<p>' . esc_html__( 'No workspace actions are available yet.', 'equine-event-manager' ) . '</p>';
			return;
		}
		?>
		<ul class="eem-editor-workspace-actions">
			<?php foreach ( $overview_actions as $action ) : ?>
				<li>
					<a class="button<?php echo empty( $action['is_secondary'] ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $action['url'] ); ?>"<?php echo ! empty( $action['target'] ) ? ' target="' . esc_attr( $action['target'] ) . '" rel="noopener noreferrer"' : ''; ?>>
						<?php echo esc_html( $action['label'] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Render event linkage fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_event_link_meta_box( $post ) {
		$context = $this->reservations_cpt->get_editor_event_link_context( $post->ID );
		$data    = $context['data'];

		wp_nonce_field( 'equine_event_manager_save_reservation_meta', 'equine_event_manager_reservation_meta_nonce' );
		?>
		<input type="hidden" name="en_reservation[use_global_event_source]" id="en_use_global_event_source" value="1" />
		<input type="hidden" name="en_reservation[event_source]" id="en_event_source" value="<?php echo esc_attr( $context['default_event_source'] ); ?>" />
		<input type="hidden" name="en_reservation[event_feed_url]" id="en_event_feed_url" value="<?php echo esc_attr( $context['default_feed_url'] ); ?>" />
		<input type="hidden" name="en_reservation[external_event_name]" id="en_external_event_name" value="<?php echo esc_attr( $data['external_event_name'] ); ?>" />
		<input type="hidden" name="en_reservation[external_event_id]" id="en_external_event_id" value="<?php echo esc_attr( $data['external_event_id'] ); ?>" />
		<table class="form-table" role="presentation">
			<tbody>
				<?php if ( $context['tec_events_enabled'] ) : ?>
					<tr class="en-tec-event-row">
						<th scope="row"><label for="en_event_id"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></label></th>
						<td>
							<select name="en_reservation[event_id]" id="en_event_id" class="regular-text" data-placeholder="<?php esc_attr_e( 'Search events by title', 'equine-event-manager' ); ?>">
								<option value="0"><?php esc_html_e( 'Select an event', 'equine-event-manager' ); ?></option>
								<?php if ( $data['event_id'] && ! empty( $context['selected_event_title'] ) ) : ?>
									<option value="<?php echo esc_attr( absint( $data['event_id'] ) ); ?>" selected="selected" data-start-date="<?php echo esc_attr( $context['selected_event_dates']['start_date'] ); ?>" data-end-date="<?php echo esc_attr( $context['selected_event_dates']['end_date'] ); ?>"><?php echo esc_html( $context['selected_event_title'] ); ?></option>
								<?php endif; ?>
								<?php foreach ( $context['initial_events'] as $event ) : ?>
									<?php if ( absint( $data['event_id'] ) === absint( $event['id'] ) ) : ?>
										<?php continue; ?>
									<?php endif; ?>
									<option value="<?php echo esc_attr( $event['id'] ); ?>" data-start-date="<?php echo esc_attr( $event['start_date'] ); ?>" data-end-date="<?php echo esc_attr( $event['end_date'] ); ?>"><?php echo esc_html( $event['title'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Search and select an event from The Events Calendar. Upcoming events are listed first using the event start date.', 'equine-event-manager' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( $context['native_events_enabled'] ) : ?>
					<tr class="en-native-event-row">
						<th scope="row"><label for="en_native_event_id"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></label></th>
						<td>
							<select name="en_reservation[native_event_id]" id="en_native_event_id" class="regular-text">
								<option value="0"><?php esc_html_e( 'Select an event', 'equine-event-manager' ); ?></option>
								<?php foreach ( $context['native_events'] as $event ) : ?>
									<option value="<?php echo esc_attr( $event['id'] ); ?>" <?php selected( 'native' === $context['default_event_source'] ? absint( $data['event_id'] ) : 0, $event['id'] ); ?> data-start-date="<?php echo esc_attr( $event['start_date'] ); ?>" data-end-date="<?php echo esc_attr( $event['end_date'] ); ?>">
										<?php echo esc_html( $event['title'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Search and select an event from Native Events.', 'equine-event-manager' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( 'feed' === $context['default_event_source'] ) : ?>
					<tr class="en-feed-event-row">
						<th scope="row"><label for="en_feed_event_id"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></label></th>
						<td>
							<select id="en_feed_event_id" class="regular-text" data-placeholder="<?php esc_attr_e( 'Search feed events by title, venue, or producer', 'equine-event-manager' ); ?>" data-feed-url="<?php echo esc_attr( $context['default_feed_url'] ); ?>">
								<option value="0"><?php esc_html_e( 'Select a feed event', 'equine-event-manager' ); ?></option>
								<?php if ( ! empty( $context['selected_feed_event'] ) ) : ?>
									<option
										value="<?php echo esc_attr( $context['selected_feed_event']['external_event_id'] ); ?>"
										selected="selected"
										data-external-event-id="<?php echo esc_attr( $context['selected_feed_event']['external_event_id'] ); ?>"
										data-title="<?php echo esc_attr( $context['selected_feed_event']['title'] ); ?>"
										data-start-date="<?php echo esc_attr( $context['selected_feed_event']['start_date'] ); ?>"
										data-end-date="<?php echo esc_attr( $context['selected_feed_event']['end_date'] ); ?>"
										data-venue-name="<?php echo esc_attr( $context['selected_feed_event']['venue_name'] ); ?>"
										data-location="<?php echo esc_attr( $context['selected_feed_event']['location'] ); ?>"
										data-content-raw="<?php echo esc_attr( wp_strip_all_tags( (string) $context['selected_feed_event']['content_raw'] ) ); ?>"
									><?php echo esc_html( $context['selected_feed_event']['title'] ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Search and select an event from the configured External Feed URL. This will save the feed event link, title, and reservation date range.', 'equine-event-manager' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render reservation description fields shown on the Stay Details card.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_reservation_description_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="en_reservation_description"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></label></th>
					<td>
						<textarea name="en_reservation[reservation_description]" id="en_reservation_description" rows="5" class="large-text"><?php echo esc_textarea( $data['reservation_description'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown on the front-end Stay Details card above the default reservation date and rate instructions.', 'equine-event-manager' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render available reservation dates fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_available_dates_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		?>
		<input type="hidden" name="en_reservation[available_dates_manually_edited]" id="en_available_dates_manually_edited" value="<?php echo esc_attr( $data['available_dates_manually_edited'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Select the dates for which you would like customers to be able to make reservations.', 'equine-event-manager' ); ?></p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="en_available_start_date"><?php esc_html_e( 'Date Range', 'equine-event-manager' ); ?></label></th>
					<td>
						<div>
							<input name="en_reservation[available_start_date]" id="en_available_start_date" type="date" value="<?php echo esc_attr( $data['available_start_date'] ); ?>" />
							<span aria-hidden="true">-</span>
							<input name="en_reservation[available_end_date]" id="en_available_end_date" type="date" value="<?php echo esc_attr( $data['available_end_date'] ); ?>" />
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render check-in/check-out fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_checkin_checkout_meta_box( $post ) {
		$data              = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		$checkin_fallback  = ! empty( $data['stalls_open_at'] ) ? $data['stalls_open_at'] : $data['available_start_date'];
		$checkout_fallback = ! empty( $data['stalls_close_at'] ) ? $data['stalls_close_at'] : $data['available_end_date'];
		?>
		<div>
			<div class="en-section-toggle-row">
				<label class="en-section-toggle-control">
					<input name="en_reservation[checkin_checkout_enabled]" id="en_checkin_checkout_enabled" type="checkbox" value="1" data-en-section-toggle="checkin-checkout" <?php checked( $data['checkin_checkout_enabled'], 1 ); ?> />
					<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable check-in/check-out', 'equine-event-manager' ); ?></span>
					<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'Set the customer check-in and check-out time for all reservations.', 'equine-event-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $this->reservations_cpt->render_editor_datetime_row( 'checkin_time', __( 'Check-In Time', 'equine-event-manager' ), $data['checkin_time'], '', '', $checkin_fallback ); ?>
					<?php $this->reservations_cpt->render_editor_datetime_row( 'checkout_time', __( 'Check-Out Time', 'equine-event-manager' ), $data['checkout_time'], '', '', $checkout_fallback ); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render stall reservation configuration fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_stall_rules_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_stall_context( $post->ID );
		?>
		<div>
		<div class="en-section-toggle-row">
			<label class="en-section-toggle-control">
				<input name="en_reservation[stalls_enabled]" id="en_stalls_enabled" type="checkbox" value="1" data-en-section-toggle="stall" <?php checked( $data['stalls_enabled'], 1 ); ?> />
				<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable stall reservations', 'equine-event-manager' ); ?></span>
				<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
			</label>
		</div>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->reservations_cpt->render_editor_textarea_row( 'stall_description', __( 'Reservation Description', 'equine-event-manager' ), $data['stall_description'], __( 'Describe stall amenities, bedding details, or other reservation notes shown on the front end.', 'equine-event-manager' ) ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stay Types', 'equine-event-manager' ); ?></th>
					<td>
						<div class="en-stay-type-toggle-group">
							<label class="en-inline-toggle-control">
								<input name="en_reservation[stall_nightly_enabled]" id="en_stall_nightly_enabled" type="checkbox" value="1" <?php checked( $data['stall_nightly_enabled'], 1 ); ?> />
								<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
								<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Nightly', 'equine-event-manager' ); ?></span>
							</label>
							<label class="en-inline-toggle-control">
								<input name="en_reservation[stall_weekend_enabled]" id="en_stall_weekend_enabled" type="checkbox" value="1" <?php checked( $data['stall_weekend_enabled'], 1 ); ?> />
								<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
								<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Weekend Rate', 'equine-event-manager' ); ?></span>
							</label>
						</div>
						<p class="description"><?php esc_html_e( 'Enable one or both stall stay types. Weekend Rate uses the stall weekend package dates below.', 'equine-event-manager' ); ?></p>
					</td>
				</tr>
				<?php $this->reservations_cpt->render_editor_date_range_row( 'stall_weekend_package_start_date', 'stall_weekend_package_end_date', __( 'Weekend Package Dates', 'equine-event-manager' ), $data['stall_weekend_package_start_date'], $data['stall_weekend_package_end_date'], __( 'Customers choosing Stall Weekend Rate will automatically use this package date range.', 'equine-event-manager' ), 'en-weekend-package-row en-rate-mode-row en-rate-mode-group--stall' ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reservation Schedule', 'equine-event-manager' ); ?></th>
					<td>
						<label class="en-inline-toggle-control">
							<input name="en_reservation[stall_schedule_enabled]" id="en_stall_schedule_enabled" type="checkbox" value="1" <?php checked( $data['stall_schedule_enabled'], 1 ); ?> />
							<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Schedule Stall Reservations', 'equine-event-manager' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Turn this on to open and close stall reservations on specific dates and times.', 'equine-event-manager' ); ?></p>
					</td>
				</tr>
				<?php $this->reservations_cpt->render_editor_datetime_row( 'stalls_open_at', __( 'Stalls Open Date/Time', 'equine-event-manager' ), $data['stalls_open_at'], '', 'en-schedule-row en-schedule-group--stall' ); ?>
				<?php $this->reservations_cpt->render_editor_datetime_row( 'stalls_close_at', __( 'Stalls Close Date/Time', 'equine-event-manager' ), $data['stalls_close_at'], '', 'en-schedule-row en-schedule-group--stall' ); ?>
				<?php $this->reservations_cpt->render_editor_number_row( 'stall_inventory', __( 'Available Stall Inventory', 'equine-event-manager' ), $data['stall_inventory'], __( 'Leave blank for unlimited inventory. Once inventory reaches zero, customers will see a sold out message.', 'equine-event-manager' ) ); ?>
				<?php $this->reservations_cpt->render_editor_currency_row( 'stall_nightly_rate', __( 'Stall Nightly Rate', 'equine-event-manager' ), $data['stall_nightly_rate'], array( 'mode' => 'nightly', 'group' => 'stall' ) ); ?>
				<?php $this->reservations_cpt->render_editor_currency_row( 'stall_weekend_rate', __( 'Stall Weekend Rate', 'equine-event-manager' ), $data['stall_weekend_rate'], array( 'mode' => 'weekend', 'group' => 'stall' ) ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stall Early Bird', 'equine-event-manager' ); ?></th>
					<td>
						<label class="en-inline-toggle-control">
							<input name="en_reservation[stall_early_bird_enabled]" id="en_stall_early_bird_enabled" type="checkbox" value="1" <?php checked( $data['stall_early_bird_enabled'], 1 ); ?> />
							<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Enable stall early bird pricing', 'equine-event-manager' ); ?></span>
						</label>
					</td>
				</tr>
				<?php $this->reservations_cpt->render_editor_datetime_row( 'stall_early_bird_cutoff', __( 'Stall Early Bird Cutoff', 'equine-event-manager' ), $data['stall_early_bird_cutoff'], '', 'en-early-bird-row en-early-bird-group--stall' ); ?>
				<?php $this->reservations_cpt->render_editor_currency_row( 'stall_early_bird_nightly_rate', __( 'Stall Early Bird Nightly Rate', 'equine-event-manager' ), $data['stall_early_bird_nightly_rate'], array( 'mode' => 'nightly', 'group' => 'stall', 'row_classes' => 'en-early-bird-row en-early-bird-group--stall' ) ); ?>
				<?php $this->reservations_cpt->render_editor_currency_row( 'stall_early_bird_weekend_rate', __( 'Stall Early Bird Weekend Rate', 'equine-event-manager' ), $data['stall_early_bird_weekend_rate'], array( 'mode' => 'weekend', 'group' => 'stall', 'row_classes' => 'en-early-bird-row en-early-bird-group--stall' ) ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Required Shavings', 'equine-event-manager' ); ?></th>
					<td>
						<label class="en-inline-toggle-control">
							<input name="en_reservation[required_shavings_enabled]" id="en_required_shavings_enabled" type="checkbox" value="1" <?php checked( $data['required_shavings_enabled'], 1 ); ?> />
							<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Require shavings with each stall', 'equine-event-manager' ); ?></span>
						</label>
					</td>
				</tr>
				<tr class="en-required-shavings-row">
					<th scope="row"><label for="en_required_shavings_per_stall"><?php esc_html_e( 'Required Shavings Per Stall', 'equine-event-manager' ); ?></label></th>
					<td><input name="en_reservation[required_shavings_per_stall]" id="en_required_shavings_per_stall" type="number" min="0" step="1" value="<?php echo esc_attr( $data['required_shavings_per_stall'] ); ?>" /></td>
				</tr>
				<?php $this->reservations_cpt->render_editor_currency_row( 'required_shavings_price', __( 'Required Shavings Price Per Bag', 'equine-event-manager' ), $data['required_shavings_price'], array( 'row_classes' => 'en-required-shavings-row' ) ); ?>
				<?php $this->reservations_cpt->render_editor_stall_chart_rows( $data ); ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render venue map settings.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_venue_map_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		?>
		<div>
		<div class="en-section-toggle-row">
			<label class="en-section-toggle-control">
				<input name="en_reservation[venue_map_enabled]" id="en_venue_map_enabled" type="checkbox" value="1" data-en-section-toggle="venue-map" <?php checked( $data['venue_map_enabled'], 1 ); ?> />
				<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable venue map', 'equine-event-manager' ); ?></span>
				<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
			</label>
		</div>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->reservations_cpt->render_editor_text_row( 'venue_map_download_url', __( 'Download Venue Map URL', 'equine-event-manager' ), $data['venue_map_download_url'], __( 'Optional URL for the title-card "Download Venue Map" link.', 'equine-event-manager' ) ); ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render venue agreement settings.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_venue_agreement_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		?>
		<div>
		<div class="en-section-toggle-row">
			<label class="en-section-toggle-control">
				<input name="en_reservation[venue_agreement_enabled]" id="en_venue_agreement_enabled" type="checkbox" value="1" data-en-section-toggle="agreement" <?php checked( $data['venue_agreement_enabled'], 1 ); ?> />
				<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable agreement', 'equine-event-manager' ); ?></span>
				<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
			</label>
		</div>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->reservations_cpt->render_editor_file_field_row( 'venue_agreement_file_id', __( 'Agreement File', 'equine-event-manager' ), $data['venue_agreement_file_id'], __( 'Upload the agreement form customers should review before submitting.', 'equine-event-manager' ) ); ?>
				<?php $this->reservations_cpt->render_editor_text_row( 'venue_agreement_file_label', __( 'Agreement Link Label', 'equine-event-manager' ), $data['venue_agreement_file_label'], __( 'Shown on the front end for the agreement file link, such as Venue Agreement or Rider Agreement.', 'equine-event-manager' ) ); ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render general add-on fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_general_addons_meta_box( $post ) {
		$context = $this->reservations_cpt->get_editor_general_addons_context( $post->ID );
		$data    = $context['data'];
		$addons  = $context['addons'];
		?>
		<div>
		<div class="en-section-toggle-row">
			<label class="en-section-toggle-control">
				<input name="en_reservation[general_addons_enabled]" id="en_general_addons_enabled" type="checkbox" value="1" data-en-section-toggle="general-addons" <?php checked( $data['general_addons_enabled'], 1 ); ?> />
				<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable general add-ons', 'equine-event-manager' ); ?></span>
				<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
			</label>
		</div>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<td colspan="2">
						<div class="en-admin-structured-table">
						<p class="description"><?php esc_html_e( 'Use general add-ons for items like hay, extra bedding, or other optional products that can be sold alongside stalls or RV reservations.', 'equine-event-manager' ); ?></p>
						<table class="widefat striped en-general-addon-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Add-On Name', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Per', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Action', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody id="en_general_addons_rows">
								<?php foreach ( $addons as $addon_index => $addon ) : ?>
									<tr class="en-general-addon-row">
										<td><div class="en-admin-table-field"><input type="text" class="regular-text" name="en_reservation[general_addons][<?php echo esc_attr( $addon_index ); ?>][name]" value="<?php echo esc_attr( $addon['name'] ); ?>" /></div></td>
										<td><div class="en-admin-table-field"><input type="text" class="regular-text" name="en_reservation[general_addons][<?php echo esc_attr( $addon_index ); ?>][description]" value="<?php echo esc_attr( $addon['description'] ); ?>" /></div></td>
										<td><div class="en-admin-table-field"><input type="hidden" name="en_reservation[general_addons][<?php echo esc_attr( $addon_index ); ?>][applies_to]" value="any" /><div class="en-currency-field en-rv-addon-price-field"><span class="en-currency-symbol">$</span><input name="en_reservation[general_addons][<?php echo esc_attr( $addon_index ); ?>][price]" type="text" class="en-currency-input" inputmode="decimal" value="<?php echo esc_attr( number_format( (float) $addon['price'], 2, '.', '' ) ); ?>" /></div></div></td>
										<td><div class="en-admin-table-field"><input type="text" class="regular-text" name="en_reservation[general_addons][<?php echo esc_attr( $addon_index ); ?>][per_label]" value="<?php echo esc_attr( isset( $addon['per_label'] ) ? $addon['per_label'] : '' ); ?>" placeholder="<?php esc_attr_e( 'bale', 'equine-event-manager' ); ?>" /></div></td>
										<td><div class="en-admin-table-field en-admin-table-field--action"><button type="button" class="en-icon-delete-button en-remove-general-addon" aria-label="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></div></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button button-secondary" id="en_add_general_addon"><?php esc_html_e( 'Add Add-On', 'equine-event-manager' ); ?></button></p>
						<template id="en-general-addon-row-template">
							<tr class="en-general-addon-row">
								<td><div class="en-admin-table-field"><input type="text" class="regular-text" name="en_reservation[general_addons][__index__][name]" value="" /></div></td>
								<td><div class="en-admin-table-field"><input type="text" class="regular-text" name="en_reservation[general_addons][__index__][description]" value="" /></div></td>
								<td><div class="en-admin-table-field"><input type="hidden" name="en_reservation[general_addons][__index__][applies_to]" value="any" /><div class="en-currency-field en-rv-addon-price-field"><span class="en-currency-symbol">$</span><input name="en_reservation[general_addons][__index__][price]" type="text" class="en-currency-input" inputmode="decimal" value="0.00" /></div></div></td>
								<td><div class="en-admin-table-field"><input type="text" class="regular-text" name="en_reservation[general_addons][__index__][per_label]" value="" placeholder="<?php esc_attr_e( 'bale', 'equine-event-manager' ); ?>" /></div></td>
								<td><div class="en-admin-table-field en-admin-table-field--action"><button type="button" class="en-icon-delete-button en-remove-general-addon" aria-label="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></div></td>
							</tr>
						</template>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render group reservation fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_group_reservations_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		?>
		<div>
		<div class="en-section-toggle-row">
			<label class="en-section-toggle-control">
				<input name="en_reservation[group_reservations_enabled]" id="en_group_reservations_enabled" type="checkbox" value="1" data-en-section-toggle="group-reservations" <?php checked( $data['group_reservations_enabled'], 1 ); ?> />
				<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable group reservations', 'equine-event-manager' ); ?></span>
				<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
			</label>
		</div>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Group Reservation Logic', 'equine-event-manager' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'When enabled, customers can turn on a group reservation on the front end, enter the rider count, and provide a first and last name for each rider.', 'equine-event-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rider Grounds Fee', 'equine-event-manager' ); ?></th>
					<td>
						<label class="en-inline-toggle-control">
							<input name="en_reservation[group_rider_grounds_fee_enabled]" id="en_group_rider_grounds_fee_enabled" type="checkbox" value="1" <?php checked( $data['group_rider_grounds_fee_enabled'], 1 ); ?> />
							<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Charge a grounds fee for each rider in the group reservation.', 'equine-event-manager' ); ?></span>
						</label>
					</td>
				</tr>
				<?php $this->reservations_cpt->render_editor_currency_row( 'group_rider_grounds_fee_amount', __( 'Rider Grounds Fee Amount', 'equine-event-manager' ), $data['group_rider_grounds_fee_amount'], array( 'disabled' => empty( $data['group_rider_grounds_fee_enabled'] ), 'row_classes' => 'en-group-fee-row en-group-fee-row--grounds' ) ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rider Deposit', 'equine-event-manager' ); ?></th>
					<td>
						<label class="en-inline-toggle-control">
							<input name="en_reservation[group_rider_deposit_enabled]" id="en_group_rider_deposit_enabled" type="checkbox" value="1" <?php checked( $data['group_rider_deposit_enabled'], 1 ); ?> />
							<span class="en-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="en-inline-toggle-control__label"><?php esc_html_e( 'Require a deposit for each rider in the group reservation.', 'equine-event-manager' ); ?></span>
						</label>
					</td>
				</tr>
				<?php $this->reservations_cpt->render_editor_currency_row( 'group_rider_deposit_amount', __( 'Rider Deposit Amount', 'equine-event-manager' ), $data['group_rider_deposit_amount'], array( 'disabled' => empty( $data['group_rider_deposit_enabled'] ), 'row_classes' => 'en-group-fee-row en-group-fee-row--deposit' ) ); ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render fee fields.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return void
	 */
	public function render_fees_meta_box( $post ) {
		$data = $this->reservations_cpt->get_editor_meta_values( $post->ID );
		?>
		<div>
		<div class="en-section-toggle-row">
			<label class="en-section-toggle-control">
				<input name="en_reservation[convenience_fee_enabled]" id="en_convenience_fee_enabled" type="checkbox" value="1" data-en-section-toggle="fees" <?php checked( $data['convenience_fee_enabled'], 1 ); ?> />
				<span class="en-section-toggle-control__label"><?php esc_html_e( 'Enable convenience fee', 'equine-event-manager' ); ?></span>
				<span class="en-section-toggle-control__track" aria-hidden="true"><span class="en-section-toggle-control__thumb"></span></span>
			</label>
		</div>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->reservations_cpt->render_editor_text_row( 'convenience_fee_label', __( 'Fee Label', 'equine-event-manager' ), $data['convenience_fee_label'], __( 'This label appears on the front-end payment summary.', 'equine-event-manager' ) ); ?>
				<tr>
					<th scope="row"><label for="en_convenience_fee_type"><?php esc_html_e( 'Convenience Fee Type', 'equine-event-manager' ); ?></label></th>
					<td>
						<select name="en_reservation[convenience_fee_type]" id="en_convenience_fee_type">
							<option value="none" <?php selected( $data['convenience_fee_type'], 'none' ); ?>><?php esc_html_e( 'None', 'equine-event-manager' ); ?></option>
							<option value="flat" <?php selected( $data['convenience_fee_type'], 'flat' ); ?>><?php esc_html_e( 'Flat', 'equine-event-manager' ); ?></option>
							<option value="percentage" <?php selected( $data['convenience_fee_type'], 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'equine-event-manager' ); ?></option>
						</select>
					</td>
				</tr>
				<?php $this->reservations_cpt->render_editor_fee_value_row( 'convenience_fee_value', __( 'Convenience Fee Value', 'equine-event-manager' ), $data['convenience_fee_value'], $data['convenience_fee_type'] ); ?>
			</tbody>
		</table>
		</div>
		<?php
	}

}
