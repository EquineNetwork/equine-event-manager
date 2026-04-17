<?php
/**
 * Reservations custom post type and meta fields.
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the reservation setup CPT.
 */
class EN_Event_Manager_Reservations_CPT {

	const POST_TYPE = 'en_reservation';

	/**
	 * Register the Reservations custom post type.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Reservations', 'en-event-manager' ),
					'singular_name'      => __( 'Reservation', 'en-event-manager' ),
					'add_new'            => __( 'Add Reservation', 'en-event-manager' ),
					'add_new_item'       => __( 'Add Reservation', 'en-event-manager' ),
					'edit_item'          => __( 'Edit Reservation', 'en-event-manager' ),
					'new_item'           => __( 'New Reservation', 'en-event-manager' ),
					'view_item'          => __( 'View Reservation', 'en-event-manager' ),
					'search_items'       => __( 'Search Reservations', 'en-event-manager' ),
					'not_found'          => __( 'No reservations found.', 'en-event-manager' ),
					'not_found_in_trash' => __( 'No reservations found in Trash.', 'en-event-manager' ),
					'menu_name'          => __( 'Reservations', 'en-event-manager' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 20,
				'menu_icon'       => 'dashicons-tickets-alt',
				'capability_type' => 'post',
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Register reservation setup meta boxes.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'en_event_manager_event_link',
			__( 'Event Link', 'en-event-manager' ),
			array( $this, 'render_event_link_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'en_event_manager_stall_rules',
			__( 'Stall Rules', 'en-event-manager' ),
			array( $this, 'render_stall_rules_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'en_event_manager_rv_rules',
			__( 'RV Rules', 'en-event-manager' ),
			array( $this, 'render_rv_rules_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'en_event_manager_fees',
			__( 'Fees', 'en-event-manager' ),
			array( $this, 'render_fees_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'en_reservation_shortcode',
			__( 'Reservation Shortcode', 'en-event-manager' ),
			array( $this, 'render_shortcode_meta_box' ),
			'en_reservation',
			'side',
			'high'
		);
	}

	/**
	 * Enqueue admin assets for reservation add/edit screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$script_dependencies = array( 'jquery' );
		$style_dependencies  = array();

		if ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
			$script_dependencies[] = 'select2';
		} elseif ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
			$script_dependencies[] = 'selectWoo';
		}

		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
			$style_dependencies[] = 'select2';
		} elseif ( wp_style_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_style( 'selectWoo' );
			$style_dependencies[] = 'selectWoo';
		}

		wp_enqueue_style(
			'en-event-manager-admin',
			EN_EVENT_MANAGER_URL . 'admin/css/en-event-manager-admin.css',
			$style_dependencies,
			EN_EVENT_MANAGER_VERSION
		);

		wp_enqueue_script(
			'en-event-manager-admin',
			EN_EVENT_MANAGER_URL . 'admin/js/en-event-manager-admin.js',
			$script_dependencies,
			EN_EVENT_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'en-event-manager-admin',
			'enEventManagerAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'en_event_manager_search_tec_events' ),
				'strings' => array(
					'placeholder' => __( 'Search events by title', 'en-event-manager' ),
					'searching'   => __( 'Searching events...', 'en-event-manager' ),
					'noResults'   => __( 'No events found.', 'en-event-manager' ),
					'error'       => __( 'Event search failed. Please try again.', 'en-event-manager' ),
				),
			)
		);
	}

	/**
	 * AJAX search for The Events Calendar events.
	 */
	public function ajax_search_tec_events() {
		check_ajax_referer( 'en_event_manager_search_tec_events', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to search events.', 'en-event-manager' ) ), 403 );
		}

		if ( ! $this->is_the_events_calendar_available() ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$term    = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$results = $this->search_the_events_calendar_events( $term );

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Render event linkage fields.
	 *
	 * @param WP_Post $post Reservation post.
	 */
	public function render_event_link_meta_box( $post ) {
		wp_nonce_field( 'en_event_manager_save_reservation_meta', 'en_event_manager_reservation_meta_nonce' );

		$data                 = $this->get_meta_values( $post->ID );
		$selected_event_title = $data['event_id'] ? get_the_title( absint( $data['event_id'] ) ) : '';
		$initial_events       = $this->is_the_events_calendar_available() ? $this->query_tec_events_by_start_date( '', 50 ) : array();

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="en_event_source"><?php esc_html_e( 'Event Source', 'en-event-manager' ); ?></label></th>
					<td>
						<select name="en_reservation[event_source]" id="en_event_source">
							<?php if ( $this->is_the_events_calendar_available() ) : ?>
								<option value="tec" <?php selected( $data['event_source'], 'tec' ); ?>><?php esc_html_e( 'The Events Calendar', 'en-event-manager' ); ?></option>
							<?php endif; ?>
							<option value="external" <?php selected( $data['event_source'], 'external' ); ?>><?php esc_html_e( 'External Event', 'en-event-manager' ); ?></option>
						</select>
					</td>
				</tr>
				<?php if ( $this->is_the_events_calendar_available() ) : ?>
					<tr class="en-tec-event-row">
						<th scope="row"><label for="en_event_id"><?php esc_html_e( 'The Events Calendar Event', 'en-event-manager' ); ?></label></th>
						<td>
							<select name="en_reservation[event_id]" id="en_event_id" class="regular-text en-event-manager-tec-event-select" data-placeholder="<?php esc_attr_e( 'Search events by title', 'en-event-manager' ); ?>">
								<option value="0"><?php esc_html_e( 'Select an event', 'en-event-manager' ); ?></option>
								<?php if ( $data['event_id'] && $selected_event_title ) : ?>
									<option value="<?php echo esc_attr( absint( $data['event_id'] ) ); ?>" selected="selected"><?php echo esc_html( $selected_event_title ); ?></option>
								<?php endif; ?>
								<?php foreach ( $initial_events as $event ) : ?>
									<?php if ( absint( $data['event_id'] ) === absint( $event->ID ) ) : ?>
										<?php continue; ?>
									<?php endif; ?>
									<option value="<?php echo esc_attr( $event->ID ); ?>"><?php echo esc_html( get_the_title( $event ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Search by event title. Upcoming events are listed first using The Events Calendar start date.', 'en-event-manager' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="en_external_event_name"><?php esc_html_e( 'External Event Name', 'en-event-manager' ); ?></label></th>
					<td><input name="en_reservation[external_event_name]" id="en_external_event_name" type="text" class="regular-text" value="<?php echo esc_attr( $data['external_event_name'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="en_external_event_id"><?php esc_html_e( 'External Event ID', 'en-event-manager' ); ?></label></th>
					<td><input name="en_reservation[external_event_id]" id="en_external_event_id" type="text" class="regular-text" value="<?php echo esc_attr( $data['external_event_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'What Can Be Sold', 'en-event-manager' ); ?></th>
					<td>
						<label><input name="en_reservation[stalls_enabled]" type="checkbox" value="1" <?php checked( $data['stalls_enabled'], 1 ); ?> /> <?php esc_html_e( 'Stalls enabled', 'en-event-manager' ); ?></label><br />
						<label><input name="en_reservation[rv_enabled]" type="checkbox" value="1" <?php checked( $data['rv_enabled'], 1 ); ?> /> <?php esc_html_e( 'RV enabled', 'en-event-manager' ); ?></label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render stall rules fields.
	 *
	 * @param WP_Post $post Reservation post.
	 */
	public function render_stall_rules_meta_box( $post ) {
		$data = $this->get_meta_values( $post->ID );

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->render_datetime_row( 'stalls_open_at', __( 'Stalls Open Date/Time', 'en-event-manager' ), $data['stalls_open_at'] ); ?>
				<?php $this->render_datetime_row( 'stalls_close_at', __( 'Stalls Close Date/Time', 'en-event-manager' ), $data['stalls_close_at'] ); ?>
				<?php $this->render_currency_row( 'stall_nightly_rate', __( 'Stall Nightly Rate', 'en-event-manager' ), $data['stall_nightly_rate'] ); ?>
				<?php $this->render_currency_row( 'stall_weekend_rate', __( 'Stall Weekend Rate', 'en-event-manager' ), $data['stall_weekend_rate'] ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stall Early Bird', 'en-event-manager' ); ?></th>
					<td><label><input name="en_reservation[stall_early_bird_enabled]" type="checkbox" value="1" <?php checked( $data['stall_early_bird_enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable stall early bird pricing', 'en-event-manager' ); ?></label></td>
				</tr>
				<?php $this->render_datetime_row( 'stall_early_bird_cutoff', __( 'Stall Early Bird Cutoff', 'en-event-manager' ), $data['stall_early_bird_cutoff'] ); ?>
				<?php $this->render_currency_row( 'stall_early_bird_nightly_rate', __( 'Stall Early Bird Nightly Rate', 'en-event-manager' ), $data['stall_early_bird_nightly_rate'] ); ?>
				<?php $this->render_currency_row( 'stall_early_bird_weekend_rate', __( 'Stall Early Bird Weekend Rate', 'en-event-manager' ), $data['stall_early_bird_weekend_rate'] ); ?>
				<tr>
					<th scope="row"><label for="en_required_shavings_per_stall"><?php esc_html_e( 'Required Shavings Per Stall', 'en-event-manager' ); ?></label></th>
					<td><input name="en_reservation[required_shavings_per_stall]" id="en_required_shavings_per_stall" type="number" min="0" step="1" value="<?php echo esc_attr( $data['required_shavings_per_stall'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Additional Shavings', 'en-event-manager' ); ?></th>
					<td><label><input name="en_reservation[additional_shavings_enabled]" type="checkbox" value="1" <?php checked( $data['additional_shavings_enabled'], 1 ); ?> /> <?php esc_html_e( 'Allow additional shavings', 'en-event-manager' ); ?></label></td>
				</tr>
				<?php $this->render_currency_row( 'additional_shavings_price', __( 'Additional Shavings Price', 'en-event-manager' ), $data['additional_shavings_price'] ); ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render RV rules fields.
	 *
	 * @param WP_Post $post Reservation post.
	 */
	public function render_rv_rules_meta_box( $post ) {
		$data = $this->get_meta_values( $post->ID );

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->render_datetime_row( 'rv_open_at', __( 'RV Open Date/Time', 'en-event-manager' ), $data['rv_open_at'] ); ?>
				<?php $this->render_datetime_row( 'rv_close_at', __( 'RV Close Date/Time', 'en-event-manager' ), $data['rv_close_at'] ); ?>
				<?php $this->render_currency_row( 'rv_nightly_rate', __( 'RV Nightly Rate', 'en-event-manager' ), $data['rv_nightly_rate'] ); ?>
				<?php $this->render_currency_row( 'rv_weekend_rate', __( 'RV Weekend Rate', 'en-event-manager' ), $data['rv_weekend_rate'] ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'RV Early Bird', 'en-event-manager' ); ?></th>
					<td><label><input name="en_reservation[rv_early_bird_enabled]" type="checkbox" value="1" <?php checked( $data['rv_early_bird_enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable RV early bird pricing', 'en-event-manager' ); ?></label></td>
				</tr>
				<?php $this->render_datetime_row( 'rv_early_bird_cutoff', __( 'RV Early Bird Cutoff', 'en-event-manager' ), $data['rv_early_bird_cutoff'] ); ?>
				<?php $this->render_currency_row( 'rv_early_bird_nightly_rate', __( 'RV Early Bird Nightly Rate', 'en-event-manager' ), $data['rv_early_bird_nightly_rate'] ); ?>
				<?php $this->render_currency_row( 'rv_early_bird_weekend_rate', __( 'RV Early Bird Weekend Rate', 'en-event-manager' ), $data['rv_early_bird_weekend_rate'] ); ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render fee fields.
	 *
	 * @param WP_Post $post Reservation post.
	 */
	public function render_fees_meta_box( $post ) {
		$data = $this->get_meta_values( $post->ID );

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="en_convenience_fee_type"><?php esc_html_e( 'Convenience Fee Type', 'en-event-manager' ); ?></label></th>
					<td>
						<select name="en_reservation[convenience_fee_type]" id="en_convenience_fee_type">
							<option value="none" <?php selected( $data['convenience_fee_type'], 'none' ); ?>><?php esc_html_e( 'None', 'en-event-manager' ); ?></option>
							<option value="flat" <?php selected( $data['convenience_fee_type'], 'flat' ); ?>><?php esc_html_e( 'Flat', 'en-event-manager' ); ?></option>
							<option value="percentage" <?php selected( $data['convenience_fee_type'], 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'en-event-manager' ); ?></option>
						</select>
					</td>
				</tr>
				<?php $this->render_money_row( 'convenience_fee_value', __( 'Convenience Fee Value', 'en-event-manager' ), $data['convenience_fee_value'] ); ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the generated reservation shortcode sidebar box.
	 *
	 * @param WP_Post $post Reservation post.
	 */
	public function render_shortcode_meta_box( $post ) {
		$this->render_shortcode_control( $post );
	}

	/**
	 * Render the generated reservation shortcode inside the publish box.
	 */
	public function render_submitbox_shortcode() {
		global $post;

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		echo '<div class="misc-pub-section en-event-manager-submitbox-shortcode">';
		echo '<strong>' . esc_html__( 'Reservation Shortcode', 'en-event-manager' ) . '</strong>';
		$this->render_shortcode_control( $post );
		echo '</div>';
	}

	/**
	 * Save reservation setup meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function save_meta( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['en_event_manager_reservation_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['en_event_manager_reservation_meta_nonce'] ) ), 'en_event_manager_save_reservation_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$source   = isset( $_POST['en_reservation'] ) && is_array( $_POST['en_reservation'] ) ? wp_unslash( $_POST['en_reservation'] ) : array();
		$data     = $this->sanitize_meta_submission( $source );

		foreach ( $data as $key => $value ) {
			update_post_meta( $post_id, '_en_' . $key, $value );
		}

		if ( 'publish' === get_post_status( $post_id ) ) {
			$shortcode = $this->get_reservation_shortcode( $post_id );
			update_post_meta( $post_id, '_en_reservation_shortcode', $shortcode );
		} else {
			delete_post_meta( $post_id, '_en_reservation_shortcode' );
		}
	}

	/**
	 * Sync the generated shortcode to the linked TEC event after reservation meta is saved.
	 *
	 * @param int     $post_id Reservation post ID.
	 * @param WP_Post $post Post object.
	 */
	public function sync_shortcode_to_linked_event_after_save( $post_id, $post ) {
		if ( ! $this->can_process_reservation_save( $post_id, $post ) ) {
			return;
		}

		$event_source = sanitize_key( get_post_meta( $post_id, '_en_event_source', true ) );
		$event_id     = absint( get_post_meta( $post_id, '_en_event_id', true ) );
		$shortcode    = $this->get_reservation_shortcode( $post_id );

		if ( 'publish' !== $post->post_status ) {
			delete_post_meta( $post_id, '_en_reservation_shortcode' );
			$this->debug_log(
				sprintf(
					'event sync skipped: reservation_id=%d status=%s is not published.',
					absint( $post_id ),
					sanitize_key( $post->post_status )
				)
			);
			return;
		}

		update_post_meta( $post_id, '_en_reservation_shortcode', $shortcode );

		$this->debug_log(
			sprintf(
				'reservation_id=%d linked_tec_event_id=%d generated_shortcode=%s',
				absint( $post_id ),
				$event_id,
				$shortcode
			)
		);

		if ( 'tec' !== $event_source || ! $event_id ) {
			$this->debug_log( 'event sync skipped: reservation is not linked to a TEC event.' );
			return;
		}

		if ( 'tribe_events' !== get_post_type( $event_id ) ) {
			$this->debug_log( 'event sync skipped: linked post is not tribe_events.' );
			return;
		}

		$this->update_event_reservations_field( $event_id, $shortcode );
	}

	/**
	 * Clear the linked event field when a reservation is trashed or deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function clear_shortcode_from_linked_event( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$event_source = sanitize_key( get_post_meta( $post_id, '_en_event_source', true ) );
		$event_id     = absint( get_post_meta( $post_id, '_en_event_id', true ) );
		$shortcode    = $this->get_reservation_shortcode( $post_id );

		$this->debug_log(
			sprintf(
				'cleanup reservation_id=%d linked_tec_event_id=%d generated_shortcode=%s',
				absint( $post_id ),
				$event_id,
				$shortcode
			)
		);

		if ( 'tec' !== $event_source || ! $event_id || 'tribe_events' !== get_post_type( $event_id ) ) {
			$this->debug_log( 'cleanup skipped: no valid linked TEC event.' );
			return;
		}

		if ( ! $this->event_reservations_field_matches_shortcode( $event_id, $shortcode ) ) {
			$this->debug_log( 'cleanup skipped: event reservations field does not match this reservation shortcode.' );
			return;
		}

		$this->update_event_reservations_field( $event_id, '' );
	}

	/**
	 * Customize reservation list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function filter_columns( $columns ) {
		$new_columns = array();

		$new_columns['cb']           = isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />';
		$new_columns['title']        = __( 'Reservation', 'en-event-manager' );
		$new_columns['event_name']   = __( 'Event', 'en-event-manager' );
		$new_columns['event_source'] = __( 'Event Source', 'en-event-manager' );
		$new_columns['stalls']       = __( 'Stalls', 'en-event-manager' );
		$new_columns['rv']           = __( 'RV', 'en-event-manager' );
		$new_columns['shortcode']    = __( 'Shortcode', 'en-event-manager' );
		$new_columns['date']         = isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'en-event-manager' );

		return $new_columns;
	}

	/**
	 * Render custom reservation list column.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		$data = $this->get_meta_values( $post_id );

		if ( 'event_name' === $column ) {
			echo esc_html( $this->get_event_name( $data ) );
		} elseif ( 'event_source' === $column ) {
			echo esc_html( $this->get_event_source_label( $data['event_source'] ) );
		} elseif ( 'stalls' === $column ) {
			echo $data['stalls_enabled'] ? esc_html__( 'Enabled', 'en-event-manager' ) : esc_html__( 'Disabled', 'en-event-manager' );
		} elseif ( 'rv' === $column ) {
			echo $data['rv_enabled'] ? esc_html__( 'Enabled', 'en-event-manager' ) : esc_html__( 'Disabled', 'en-event-manager' );
		} elseif ( 'shortcode' === $column ) {
			if ( 'publish' === get_post_status( $post_id ) ) {
				printf(
					'<input type="text" class="en-event-manager-list-shortcode" readonly="readonly" value="%s" onclick="this.select();" />',
					esc_attr( $this->get_reservation_shortcode( $post_id ) )
				);
			} else {
				echo esc_html__( 'Publish to generate', 'en-event-manager' );
			}
		}
	}

	/**
	 * Customize updated messages.
	 *
	 * @param array $messages Existing messages.
	 * @return array
	 */
	public function filter_updated_messages( $messages ) {
		$messages[ self::POST_TYPE ] = array(
			0  => '',
			1  => __( 'Reservation updated.', 'en-event-manager' ),
			2  => __( 'Custom field updated.', 'en-event-manager' ),
			3  => __( 'Custom field deleted.', 'en-event-manager' ),
			4  => __( 'Reservation updated.', 'en-event-manager' ),
			5  => __( 'Reservation restored.', 'en-event-manager' ),
			6  => __( 'Reservation published.', 'en-event-manager' ),
			7  => __( 'Reservation saved.', 'en-event-manager' ),
			8  => __( 'Reservation submitted.', 'en-event-manager' ),
			9  => __( 'Reservation scheduled.', 'en-event-manager' ),
			10 => __( 'Reservation draft updated.', 'en-event-manager' ),
		);

		return $messages;
	}

	/**
	 * Sanitize submitted reservation meta.
	 *
	 * @param array $source Raw meta source.
	 * @return array
	 */
	private function sanitize_meta_submission( $source ) {
		$event_source = isset( $source['event_source'] ) ? sanitize_key( $source['event_source'] ) : 'external';

		if ( ! in_array( $event_source, array( 'tec', 'external' ), true ) ) {
			$event_source = 'external';
		}

		if ( 'tec' === $event_source && ! $this->is_the_events_calendar_available() ) {
			$event_source = 'external';
		}

		$event_id = isset( $source['event_id'] ) ? absint( $source['event_id'] ) : 0;

		if ( 'external' === $event_source ) {
			$event_id = 0;
		}

		$data = array(
			'event_source'                    => $event_source,
			'event_id'                        => $event_id,
			'external_event_name'             => isset( $source['external_event_name'] ) ? sanitize_text_field( $source['external_event_name'] ) : '',
			'external_event_id'               => isset( $source['external_event_id'] ) ? sanitize_text_field( $source['external_event_id'] ) : '',
			'stalls_enabled'                  => isset( $source['stalls_enabled'] ) ? 1 : 0,
			'rv_enabled'                      => isset( $source['rv_enabled'] ) ? 1 : 0,
			'stalls_open_at'                  => $this->sanitize_datetime_value( isset( $source['stalls_open_at'] ) ? $source['stalls_open_at'] : '' ),
			'stalls_close_at'                 => $this->sanitize_datetime_value( isset( $source['stalls_close_at'] ) ? $source['stalls_close_at'] : '' ),
			'rv_open_at'                      => $this->sanitize_datetime_value( isset( $source['rv_open_at'] ) ? $source['rv_open_at'] : '' ),
			'rv_close_at'                     => $this->sanitize_datetime_value( isset( $source['rv_close_at'] ) ? $source['rv_close_at'] : '' ),
			'stall_nightly_rate'              => $this->sanitize_money_value( isset( $source['stall_nightly_rate'] ) ? $source['stall_nightly_rate'] : '' ),
			'stall_weekend_rate'              => $this->sanitize_money_value( isset( $source['stall_weekend_rate'] ) ? $source['stall_weekend_rate'] : '' ),
			'stall_early_bird_enabled'        => isset( $source['stall_early_bird_enabled'] ) ? 1 : 0,
			'stall_early_bird_cutoff'         => $this->sanitize_datetime_value( isset( $source['stall_early_bird_cutoff'] ) ? $source['stall_early_bird_cutoff'] : '' ),
			'stall_early_bird_nightly_rate'   => $this->sanitize_money_value( isset( $source['stall_early_bird_nightly_rate'] ) ? $source['stall_early_bird_nightly_rate'] : '' ),
			'stall_early_bird_weekend_rate'   => $this->sanitize_money_value( isset( $source['stall_early_bird_weekend_rate'] ) ? $source['stall_early_bird_weekend_rate'] : '' ),
			'required_shavings_per_stall'     => isset( $source['required_shavings_per_stall'] ) ? absint( $source['required_shavings_per_stall'] ) : 0,
			'additional_shavings_enabled'     => isset( $source['additional_shavings_enabled'] ) ? 1 : 0,
			'additional_shavings_price'       => $this->sanitize_money_value( isset( $source['additional_shavings_price'] ) ? $source['additional_shavings_price'] : '' ),
			'rv_nightly_rate'                 => $this->sanitize_money_value( isset( $source['rv_nightly_rate'] ) ? $source['rv_nightly_rate'] : '' ),
			'rv_weekend_rate'                 => $this->sanitize_money_value( isset( $source['rv_weekend_rate'] ) ? $source['rv_weekend_rate'] : '' ),
			'rv_early_bird_enabled'           => isset( $source['rv_early_bird_enabled'] ) ? 1 : 0,
			'rv_early_bird_cutoff'            => $this->sanitize_datetime_value( isset( $source['rv_early_bird_cutoff'] ) ? $source['rv_early_bird_cutoff'] : '' ),
			'rv_early_bird_nightly_rate'      => $this->sanitize_money_value( isset( $source['rv_early_bird_nightly_rate'] ) ? $source['rv_early_bird_nightly_rate'] : '' ),
			'rv_early_bird_weekend_rate'      => $this->sanitize_money_value( isset( $source['rv_early_bird_weekend_rate'] ) ? $source['rv_early_bird_weekend_rate'] : '' ),
			'convenience_fee_type'            => $this->sanitize_fee_type( isset( $source['convenience_fee_type'] ) ? $source['convenience_fee_type'] : 'none' ),
			'convenience_fee_value'           => $this->sanitize_money_value( isset( $source['convenience_fee_value'] ) ? $source['convenience_fee_value'] : '' ),
		);

		if ( 'percentage' === $data['convenience_fee_type'] && (float) $data['convenience_fee_value'] > 100 ) {
			$data['convenience_fee_value'] = '100.00';
		}

		$data = $this->normalize_date_range( $data, 'stalls_open_at', 'stalls_close_at' );
		$data = $this->normalize_date_range( $data, 'rv_open_at', 'rv_close_at' );

		return $data;
	}

	/**
	 * Get saved meta values with defaults.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_meta_values( $post_id ) {
		$defaults = $this->get_default_meta_values();
		$values   = array();

		foreach ( $defaults as $key => $default ) {
			$value = get_post_meta( $post_id, '_en_' . $key, true );

			$values[ $key ] = '' === $value ? $default : $value;
		}

		return $values;
	}

	/**
	 * Default reservation meta values.
	 *
	 * @return array
	 */
	private function get_default_meta_values() {
		return array(
			'event_source'                    => $this->is_the_events_calendar_available() ? 'tec' : 'external',
			'event_id'                        => 0,
			'external_event_name'             => '',
			'external_event_id'               => '',
			'stalls_enabled'                  => 0,
			'rv_enabled'                      => 0,
			'stalls_open_at'                  => '',
			'stalls_close_at'                 => '',
			'rv_open_at'                      => '',
			'rv_close_at'                     => '',
			'stall_nightly_rate'              => '0.00',
			'stall_weekend_rate'              => '0.00',
			'stall_early_bird_enabled'        => 0,
			'stall_early_bird_cutoff'         => '',
			'stall_early_bird_nightly_rate'   => '0.00',
			'stall_early_bird_weekend_rate'   => '0.00',
			'required_shavings_per_stall'     => 0,
			'additional_shavings_enabled'     => 0,
			'additional_shavings_price'       => '0.00',
			'rv_nightly_rate'                 => '0.00',
			'rv_weekend_rate'                 => '0.00',
			'rv_early_bird_enabled'           => 0,
			'rv_early_bird_cutoff'            => '',
			'rv_early_bird_nightly_rate'      => '0.00',
			'rv_early_bird_weekend_rate'      => '0.00',
			'convenience_fee_type'            => 'none',
			'convenience_fee_value'           => '0.00',
		);
	}

	/**
	 * Check whether The Events Calendar post type is available.
	 *
	 * @return bool
	 */
	private function is_the_events_calendar_available() {
		return post_type_exists( 'tribe_events' );
	}

	/**
	 * Search The Events Calendar events by title, ordered by event start date.
	 *
	 * @param string $term Search term.
	 * @return array
	 */
	private function search_the_events_calendar_events( $term = '' ) {
		if ( ! $this->is_the_events_calendar_available() ) {
			return array();
		}

		$events = $this->query_tec_events_by_start_date( $term, 20 );

		if ( empty( $events ) ) {
			$fallback_query = new WP_Query(
				array(
					'post_type'      => 'tribe_events',
					'post_status'    => array( 'publish', 'future', 'draft' ),
					'posts_per_page' => 20,
					's'              => $term,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			$events = $fallback_query->posts;
		}

		$results = array();

		foreach ( $events as $event ) {
			$results[] = array(
				'id'   => absint( $event->ID ),
				'text' => get_the_title( $event ),
			);
		}

		return $results;
	}

	/**
	 * Query TEC events by _EventStartDate.
	 *
	 * @param string $term Search term.
	 * @param int    $limit Result limit.
	 * @return array
	 */
	private function query_tec_events_by_start_date( $term, $limit ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => absint( $limit ),
				's'              => $term,
				'meta_key'       => '_EventStartDate',
				'orderby'        => 'meta_value',
				'meta_type'      => 'DATETIME',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_EventStartDate',
						'value'   => current_time( 'mysql' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Resolve an event name from meta values.
	 *
	 * @param array $data Meta values.
	 * @return string
	 */
	private function get_event_name( $data ) {
		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$title = get_the_title( absint( $data['event_id'] ) );

			if ( $title ) {
				return $title;
			}
		}

		if ( ! empty( $data['external_event_name'] ) ) {
			return $data['external_event_name'];
		}

		return __( 'Unassigned Event', 'en-event-manager' );
	}

	/**
	 * Get readable event source label.
	 *
	 * @param string $event_source Event source key.
	 * @return string
	 */
	private function get_event_source_label( $event_source ) {
		if ( 'tec' === $event_source ) {
			return __( 'The Events Calendar', 'en-event-manager' );
		}

		return __( 'External Event', 'en-event-manager' );
	}

	/**
	 * Render a datetime field row.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param string $value Field value.
	 */
	private function render_datetime_row( $name, $label, $value ) {
		?>
		<tr>
			<th scope="row"><label for="en_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input name="en_reservation[<?php echo esc_attr( $name ); ?>]" id="en_<?php echo esc_attr( $name ); ?>" type="datetime-local" value="<?php echo esc_attr( $this->format_datetime_for_input( $value ) ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Render a money field row.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 */
	private function render_money_row( $name, $label, $value ) {
		?>
		<tr>
			<th scope="row"><label for="en_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input name="en_reservation[<?php echo esc_attr( $name ); ?>]" id="en_<?php echo esc_attr( $name ); ?>" type="number" min="0" step="0.01" value="<?php echo esc_attr( number_format( (float) $value, 2, '.', '' ) ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Render a currency field row with a dollar prefix.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 */
	private function render_currency_row( $name, $label, $value ) {
		?>
		<tr>
			<th scope="row"><label for="en_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="en-currency-field">
					<span class="en-currency-symbol" aria-hidden="true">$</span>
					<input name="en_reservation[<?php echo esc_attr( $name ); ?>]" id="en_<?php echo esc_attr( $name ); ?>" class="en-currency-input" type="text" inputmode="decimal" value="<?php echo esc_attr( number_format( (float) $value, 2, '.', '' ) ); ?>" />
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a reusable copyable shortcode control.
	 *
	 * @param WP_Post $post Reservation post.
	 */
	private function render_shortcode_control( $post ) {
		if ( ! $post->ID || 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish this reservation to generate the shortcode.', 'en-event-manager' ) . '</p>';
			return;
		}

		$shortcode = $this->get_reservation_shortcode( $post->ID );
		?>
		<p><?php esc_html_e( 'This shortcode is generated automatically for this reservation setup.', 'en-event-manager' ); ?></p>
		<input type="text" class="widefat en-event-manager-shortcode-field" readonly="readonly" value="<?php echo esc_attr( $shortcode ); ?>" onclick="this.select();" />
		<p class="description"><?php esc_html_e( 'Click the field to select and copy it.', 'en-event-manager' ); ?></p>
		<?php
	}

	/**
	 * Sanitize a datetime-local value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_datetime_value( $value ) {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Format saved datetime for datetime-local inputs.
	 *
	 * @param string $value Saved datetime.
	 * @return string
	 */
	private function format_datetime_for_input( $value ) {
		if ( ! $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return date( 'Y-m-d\TH:i', $timestamp );
	}

	/**
	 * Sanitize a money value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_money_value( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( $value ) : '';
		$value = preg_replace( '/[^0-9.]/', '', $value );

		if ( '' === $value ) {
			return '0.00';
		}

		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Determine whether a reservation save should be processed.
	 *
	 * @param int     $post_id Reservation post ID.
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private function can_process_reservation_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build the reservation shortcode for a setup.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return string
	 */
	private function get_reservation_shortcode( $post_id ) {
		return sprintf( '[en_reservation id="%d"]', absint( $post_id ) );
	}

	/**
	 * Update the reservations field on a TEC event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $value Field value.
	 */
	private function update_event_reservations_field( $event_id, $value ) {
		if ( function_exists( 'update_field' ) ) {
			$updated = update_field( 'reservations', $value, $event_id );
			$this->debug_log( 'acf update_field ran for reservations field. result=' . ( $updated ? 'true' : 'false' ) );

			if ( $updated ) {
				$this->debug_log( 'fallback update_post_meta skipped because ACF update_field succeeded.' );
				return;
			}
		} else {
			$this->debug_log( 'acf update_field did not run because ACF is not active.' );
		}

		$updated = update_post_meta( $event_id, 'reservations', $value );
		$this->debug_log( 'fallback update_post_meta ran for reservations field. result=' . ( false === $updated ? 'false' : 'true' ) );
	}

	/**
	 * Check whether the event reservations field currently contains this shortcode.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $shortcode Reservation shortcode.
	 * @return bool
	 */
	private function event_reservations_field_matches_shortcode( $event_id, $shortcode ) {
		if ( function_exists( 'get_field' ) ) {
			$acf_value = get_field( 'reservations', $event_id );

			if ( $shortcode === $acf_value ) {
				return true;
			}
		}

		return $shortcode === get_post_meta( $event_id, 'reservations', true );
	}

	/**
	 * Temporary reservation sync debug logging.
	 *
	 * @param string $message Log message.
	 */
	private function debug_log( $message ) {
		error_log( '[EN Event Manager Reservations] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Sanitize convenience fee type.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_fee_type( $value ) {
		$value = sanitize_key( $value );

		if ( ! in_array( $value, array( 'none', 'flat', 'percentage' ), true ) ) {
			return 'none';
		}

		return $value;
	}

	/**
	 * Clear an invalid close date when it is before or equal to the open date.
	 *
	 * @param array  $data Data values.
	 * @param string $open_key Open date key.
	 * @param string $close_key Close date key.
	 * @return array
	 */
	private function normalize_date_range( $data, $open_key, $close_key ) {
		if ( ! empty( $data[ $open_key ] ) && ! empty( $data[ $close_key ] ) && strtotime( $data[ $close_key ] ) <= strtotime( $data[ $open_key ] ) ) {
			$data[ $close_key ] = '';
		}

		return $data;
	}
}
