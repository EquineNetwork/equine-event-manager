<?php
/**
 * Reservations custom post type and meta fields.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the reservation setup CPT.
 */
class EEM_Reservations_CPT {

	const POST_TYPE = 'en_reservation';
	const VALIDATION_TRANSIENT_PREFIX = 'en_reservation_validation_';

	/**
	 * Canonical section-enabled meta-key map (CLEANUP #44 rename).
	 *
	 * Each reservation section's on/off toggle historically lived under an
	 * inconsistent legacy `_en_<field>_enabled` post-meta key (no shared infix:
	 * `_en_checkin_checkout_enabled` vs `_en_general_addons_enabled` vs
	 * `_en_convenience_fee_enabled`). The canonical scheme is
	 * `_eem_section_enabled_<shortkey>`. This map translates the editor's
	 * un-prefixed form-field name (the array key used throughout the meta save /
	 * read loops) to the canonical short key (the value).
	 *
	 * The legacy `_en_<field>_enabled` keys are migrated to the canonical keys
	 * once by eem-mig-007 and then left in place read-no-write as a historical
	 * record (and as a read fallback for any reservation that predates the
	 * migration). All reads resolve new-first, old-fallback via
	 * {@see self::read_section_enabled_raw()}; the single write site (the meta
	 * save loop) writes only the canonical key via {@see self::section_enabled_meta_key()}.
	 *
	 * @var array<string,string> form-field name => canonical short key
	 */
	const SECTION_ENABLED_MAP = array(
		'stalls_enabled'             => 'stalls',
		'rv_enabled'                 => 'rv',
		'checkin_checkout_enabled'   => 'checkin',
		'additional_shavings_enabled' => 'shavings',
		'general_addons_enabled'     => 'addons',
		'group_reservations_enabled' => 'group',
		'convenience_fee_enabled'    => 'fees',
		'venue_agreement_enabled'    => 'agreement',
		'required_documents_enabled' => 'requireddocs',
	);

	/**
	 * Resolve the canonical post-meta key for a reservation field.
	 *
	 * Returns the canonical `_eem_section_enabled_<shortkey>` key for the seven
	 * section-toggle fields; for every other field returns the standard
	 * `_en_<field>` key so the central save/read loops can call this uniformly.
	 *
	 * @param string $field Un-prefixed form-field name (e.g. `stalls_enabled`).
	 * @return string Fully-qualified post-meta key.
	 */
	public static function section_enabled_meta_key( string $field ): string {
		return isset( self::SECTION_ENABLED_MAP[ $field ] )
			? '_eem_section_enabled_' . self::SECTION_ENABLED_MAP[ $field ]
			: '_en_' . $field;
	}

	/**
	 * Read a section-toggle value, preferring the canonical key, falling back
	 * to the legacy `_en_<field>_enabled` key when the canonical one is absent.
	 *
	 * Use this (or {@see self::section_enabled()}) for EVERY read of a section
	 * toggle so a reservation that has not yet been re-saved post-migration —
	 * or one that slipped past eem-mig-007 — still resolves correctly. Returns
	 * the raw stored value (string), or '' when neither key exists.
	 *
	 * @param int    $post_id Reservation post id.
	 * @param string $field   Un-prefixed form-field name (e.g. `rv_enabled`).
	 * @return mixed Raw post-meta value, or '' when unset.
	 */
	public static function read_section_enabled_raw( int $post_id, string $field ) {
		$canonical = self::section_enabled_meta_key( $field );
		if ( '_en_' . $field === $canonical ) {
			// Not a mapped section toggle — single key, no fallback needed.
			return get_post_meta( $post_id, $canonical, true );
		}
		if ( metadata_exists( 'post', $post_id, $canonical ) ) {
			return get_post_meta( $post_id, $canonical, true );
		}
		return get_post_meta( $post_id, '_en_' . $field, true );
	}

	/**
	 * Boolean convenience wrapper around {@see self::read_section_enabled_raw()}.
	 *
	 * @param int    $post_id Reservation post id.
	 * @param string $field   Un-prefixed form-field name.
	 * @return bool Whether the section is enabled.
	 */
	public static function section_enabled( int $post_id, string $field ): bool {
		return (bool) self::read_section_enabled_raw( $post_id, $field );
	}

	/**
	 * Whether a section-toggle value is stored under EITHER the canonical or the
	 * legacy key (parity with the old `metadata_exists( '_en_<field>' )` checks
	 * that drive first-save legacy-default logic).
	 *
	 * @param int    $post_id Reservation post id.
	 * @param string $field   Un-prefixed form-field name.
	 * @return bool
	 */
	public static function section_enabled_exists( int $post_id, string $field ): bool {
		return metadata_exists( 'post', $post_id, self::section_enabled_meta_key( $field ) )
			|| metadata_exists( 'post', $post_id, '_en_' . $field );
	}

	/**
	 * Field-name → human label map for the edit-diff activity entry (CLEANUP #26).
	 * Keys not listed fall back to a humanized version of the field name.
	 *
	 * @var array<string,string>
	 */
	const DIFF_FIELD_LABELS = array(
		'stalls_enabled'             => 'Stall Reservations',
		'rv_enabled'                 => 'RV Reservations',
		'checkin_checkout_enabled'   => 'Check-In / Check-Out',
		'general_addons_enabled'     => 'General Add-Ons',
		'group_reservations_enabled' => 'Group Reservations',
		'convenience_fee_enabled'    => 'Convenience Fee',
		'venue_agreement_enabled'    => 'Venue Agreement',
		'required_documents_enabled' => 'Required Documents',
		'event_day_enabled'          => 'Event Day Info',
		'stall_nightly_rate'         => 'Stall Nightly Rate',
		'stall_weekend_rate'         => 'Stall Weekend Rate',
		'stall_weekly_rate'          => 'Stall Weekly Rate',
		'rv_nightly_rate'            => 'RV Nightly Rate',
		'rv_weekend_rate'            => 'RV Weekend Rate',
		'rv_weekly_rate'             => 'RV Weekly Rate',
		'stall_inventory'            => 'Stall Inventory',
		'rv_inventory'               => 'RV Inventory',
		'convenience_fee_type'       => 'Convenience Fee Type',
		'convenience_fee_amount'     => 'Convenience Fee Amount',
		'convenience_fee_percent'    => 'Convenience Fee %',
		'required_shavings_price'    => 'Required Shavings Price',
		'stall_tack_mode'            => 'Tack Stall Mode',
		'description'                => 'Reservation Description',
		'cancellation_policy_override' => 'Cancellation Policy',
	);

	/**
	 * Meta keys excluded from the edit-diff (noise that churns on every save or
	 * is a derived/cache value rather than an admin-meaningful field).
	 *
	 * @var array<int,string>
	 */
	const DIFF_NOISE_FIELDS = array(
		'reservation_shortcode',
		'source_event_start_date',
		'event_id',
		'use_global_event_source',
		'event_source',
		'external_event_id',
	);

	/**
	 * Compute a scalar-field diff between two get_meta_values() snapshots
	 * (CLEANUP #26). Only scalar values are compared — nested arrays (stall rows,
	 * add-on lists, maps) are too complex to render as a one-line change and are
	 * skipped. Noise/derived/cache keys are excluded.
	 *
	 * @param array<string,mixed> $before Pre-save meta values.
	 * @param array<string,mixed> $after  Post-save meta values.
	 * @return array<int,array{field:string,label:string,old:string,new:string}>
	 */
	public static function compute_scalar_meta_diff( array $before, array $after ): array {
		$changes = array();
		foreach ( $after as $field => $new_value ) {
			if ( in_array( $field, self::DIFF_NOISE_FIELDS, true ) ) {
				continue;
			}
			if ( is_array( $new_value ) || ( isset( $before[ $field ] ) && is_array( $before[ $field ] ) ) ) {
				continue; // skip complex values
			}
			$old_value = isset( $before[ $field ] ) ? $before[ $field ] : '';
			if ( (string) $old_value === (string) $new_value ) {
				continue;
			}
			$label = isset( self::DIFF_FIELD_LABELS[ $field ] )
				? self::DIFF_FIELD_LABELS[ $field ]
				: ucwords( str_replace( '_', ' ', $field ) );
			$changes[] = array(
				'field' => $field,
				'label' => $label,
				'old'   => (string) $old_value,
				'new'   => (string) $new_value,
			);
		}
		return $changes;
	}

	/**
	 * Snapshot-diff-log helper for reservation edits (CLEANUP #26). Computes the
	 * scalar diff between pre/post-save meta snapshots and, when something changed,
	 * writes a `reservation_edited` activity-log entry with a human-readable
	 * "Label: old → new" change list. No-op when nothing changed or the log class
	 * is unavailable. Caller passes snapshots taken either side of save_meta().
	 *
	 * @param int                 $reservation_id Reservation post ID.
	 * @param array<string,mixed> $before         Pre-save get_meta_values() snapshot.
	 * @param array<string,mixed> $after          Post-save get_meta_values() snapshot.
	 * @return void
	 */
	public static function log_reservation_edit_diff( int $reservation_id, array $before, array $after ): void {
		if ( ! class_exists( 'EEM_Activity_Log' ) || $reservation_id <= 0 ) {
			return;
		}

		$changes = self::compute_scalar_meta_diff( $before, $after );
		if ( empty( $changes ) ) {
			return;
		}

		// Render the "Label: old → new" change list (cap at 8 to keep the entry
		// readable; note the overflow count).
		$shown   = array_slice( $changes, 0, 8 );
		$parts   = array();
		foreach ( $shown as $c ) {
			$old = '' === $c['old'] ? '—' : $c['old'];
			$new = '' === $c['new'] ? '—' : $c['new'];
			$parts[] = $c['label'] . ': ' . $old . ' → ' . $new;
		}
		$meta = implode( ' · ', $parts );
		if ( count( $changes ) > count( $shown ) ) {
			$meta .= ' · ' . sprintf(
				/* translators: %d: number of additional changed fields not shown */
				_n( '+%d more change', '+%d more changes', count( $changes ) - count( $shown ), 'equine-event-manager' ),
				count( $changes ) - count( $shown )
			);
		}

		$actor_id    = get_current_user_id();
		$actor       = $actor_id ? ( get_userdata( $actor_id ) ? get_userdata( $actor_id )->display_name : '' ) : '';
		$title       = '' !== $actor
			? sprintf( /* translators: %s: admin display name */ __( 'Reservation edited by %s', 'equine-event-manager' ), $actor )
			: __( 'Reservation edited', 'equine-event-manager' );

		EEM_Activity_Log::write(
			'reservation_edited',
			array(
				'title'   => $title,
				'meta'    => $meta,
				'changes' => $changes, // structured, for any future consumer
			),
			array(
				'reservation_id' => $reservation_id,
				'actor_type'     => $actor_id ? 'admin' : 'system',
				'actor_id'       => $actor_id ? $actor_id : null,
				'actor_label'    => $actor,
			)
		);
	}

	/**
	 * Register the Reservations custom post type.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Reservations', 'equine-event-manager' ),
					'singular_name'      => __( 'Reservation', 'equine-event-manager' ),
					'add_new'            => __( 'Add Reservation', 'equine-event-manager' ),
					'add_new_item'       => __( 'Add Reservation', 'equine-event-manager' ),
					'edit_item'          => __( 'Edit Reservation', 'equine-event-manager' ),
					'new_item'           => __( 'New Reservation', 'equine-event-manager' ),
					'view_item'          => __( 'View Reservation', 'equine-event-manager' ),
					'search_items'       => __( 'Search Reservations', 'equine-event-manager' ),
					'not_found'          => __( 'No reservations found.', 'equine-event-manager' ),
					'not_found_in_trash' => __( 'No reservations found in Trash.', 'equine-event-manager' ),
					'menu_name'          => __( 'Reservations', 'equine-event-manager' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
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
	 * Register the TEC event reservation meta box.
	 */
	public function register_tec_event_meta_box() {
		if ( ! $this->is_tec_event_source_available() ) {
			return;
		}

		add_meta_box(
			'equine_event_manager_tec_reservation_link',
			__( 'Link Reservation', 'equine-event-manager' ),
			array( $this, 'render_tec_event_meta_box' ),
			'tribe_events',
			'side',
			'default'
		);
	}

	/**
	 * Register the native event reservation meta box.
	 *
	 * @return void
	 */
	public function register_native_event_meta_box() {
		add_meta_box(
			'equine_event_manager_native_event_reservation_link',
			__( 'Link Reservation', 'equine-event-manager' ),
			array( $this, 'render_native_event_meta_box' ),
			EEM_Events::EVENT_POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the native TEC event reservation selector.
	 *
	 * @param WP_Post $post TEC event post.
	 */
	public function render_tec_event_meta_box( $post ) {
		wp_nonce_field( 'equine_event_manager_save_tec_event_meta', 'equine_event_manager_tec_event_meta_nonce' );

		$linked_reservation_id = $this->get_linked_reservation_id_for_event( $post->ID );
		$flyer_file_url        = (string) get_post_meta( $post->ID, '_equine_event_manager_event_flyer_url', true );
		$flyer_file_id         = absint( get_post_meta( $post->ID, '_equine_event_manager_event_flyer_file_id', true ) );
		$flyer_url             = $flyer_file_url ? $flyer_file_url : ( $flyer_file_id ? wp_get_attachment_url( $flyer_file_id ) : '' );
		$flyer_label           = $flyer_file_id ? wp_basename( get_attached_file( $flyer_file_id ) ) : '';
		$reservations          = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<p><?php esc_html_e( 'Select the reservation setup linked to this event. The reservation shortcode will be stored automatically in event meta.', 'equine-event-manager' ); ?></p>
		<p>
			<label class="screen-reader-text" for="equine_event_manager_linked_reservation"><?php esc_html_e( 'Linked Reservation', 'equine-event-manager' ); ?></label>
			<select name="equine_event_manager_linked_reservation" id="equine_event_manager_linked_reservation" class="widefat">
				<option value="0"><?php esc_html_e( 'No linked reservation', 'equine-event-manager' ); ?></option>
				<?php foreach ( $reservations as $reservation ) : ?>
					<option value="<?php echo esc_attr( $reservation->ID ); ?>" <?php selected( $linked_reservation_id, $reservation->ID ); ?>>
						<?php echo esc_html( get_the_title( $reservation ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $linked_reservation_id ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d reservation ID. */
						__( 'Current shortcode: [en_reservation id="%d"]', 'equine-event-manager' ),
						absint( $linked_reservation_id )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<label for="equine_event_manager_tec_flyer_url"><strong><?php esc_html_e( 'Event Flyer PDF URL', 'equine-event-manager' ); ?></strong></label>
			<input type="url" id="equine_event_manager_tec_flyer_url" name="equine_event_manager_tec_flyer_url" class="widefat" value="<?php echo esc_attr( $flyer_file_url ); ?>" placeholder="https://example.com/flyer.pdf" />
			<span class="description"><?php esc_html_e( 'Optional direct PDF URL. If provided, this will be used for the frontend flyer button.', 'equine-event-manager' ); ?></span>
		</p>
		<p><strong><?php esc_html_e( 'Event Flyer PDF', 'equine-event-manager' ); ?></strong></p>
		<div>
			<input type="hidden" id="equine_event_manager_tec_flyer_file_id" name="equine_event_manager_tec_flyer_file_id" value="<?php echo esc_attr( $flyer_file_id ); ?>" />
			<input type="text" class="widefat" value="<?php echo esc_attr( $flyer_label ); ?>" readonly="readonly" placeholder="<?php esc_attr_e( 'No file selected', 'equine-event-manager' ); ?>" />
			<p>
				<button type="button" class="button"><?php esc_html_e( 'Upload File', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-icon-delete-button" aria-label="<?php esc_attr_e( 'Remove flyer file', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove flyer file', 'equine-event-manager' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
				<?php if ( $flyer_url ) : ?>
					<a href="<?php echo esc_url( $flyer_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View file', 'equine-event-manager' ); ?></a>
				<?php else : ?>
					<a href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'View file', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Upload the PDF flyer customers should be able to open from the event page.', 'equine-event-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the native event reservation selector.
	 *
	 * @param WP_Post $post Native event post.
	 * @return void
	 */
	public function render_native_event_meta_box( $post ) {
		wp_nonce_field( 'equine_event_manager_save_native_event_meta', 'equine_event_manager_native_event_meta_nonce' );

		$linked_reservation_id = $this->get_linked_reservation_id_for_event( $post->ID );
		$reservations          = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<p><?php esc_html_e( 'Choose the reservation that should power this event page. Once linked, the event can automatically render the reservation flow.', 'equine-event-manager' ); ?></p>
		<p>
			<label for="equine_event_manager_native_linked_reservation"><strong><?php esc_html_e( 'Reservation Setup', 'equine-event-manager' ); ?></strong></label><br />
			<select name="equine_event_manager_native_linked_reservation" id="equine_event_manager_native_linked_reservation" class="widefat">
				<option value="0"><?php esc_html_e( 'No linked reservation', 'equine-event-manager' ); ?></option>
				<?php foreach ( $reservations as $reservation ) : ?>
					<option value="<?php echo esc_attr( $reservation->ID ); ?>" <?php selected( $linked_reservation_id, $reservation->ID ); ?>>
						<?php echo esc_html( get_the_title( $reservation ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $linked_reservation_id ) : ?>
			<p>
				<strong><?php esc_html_e( 'Current Shortcode', 'equine-event-manager' ); ?></strong><br />
				<code>[en_reservation id="<?php echo esc_html( absint( $linked_reservation_id ) ); ?>"]</code>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save native TEC event reservation meta.
	 *
	 * @param int     $post_id Event post ID.
	 * @param WP_Post $post Event post.
	 */
	public function save_tec_event_meta( $post_id, $post ) {
		if ( ! $this->is_tec_event_source_available() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['equine_event_manager_tec_event_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['equine_event_manager_tec_event_meta_nonce'] ) ), 'equine_event_manager_save_tec_event_meta' ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'tribe_events' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$reservation_id = isset( $_POST['equine_event_manager_linked_reservation'] ) ? absint( wp_unslash( $_POST['equine_event_manager_linked_reservation'] ) ) : 0;
		$this->set_linked_reservation_for_event( $post_id, $reservation_id );
		update_post_meta( $post_id, '_equine_event_manager_event_flyer_url', esc_url_raw( isset( $_POST['equine_event_manager_tec_flyer_url'] ) ? wp_unslash( $_POST['equine_event_manager_tec_flyer_url'] ) : '' ) );
		update_post_meta( $post_id, '_equine_event_manager_event_flyer_file_id', absint( isset( $_POST['equine_event_manager_tec_flyer_file_id'] ) ? wp_unslash( $_POST['equine_event_manager_tec_flyer_file_id'] ) : 0 ) );
	}

	/**
	 * Save native event reservation meta.
	 *
	 * @param int     $post_id Event post ID.
	 * @param WP_Post $post Event post.
	 * @return void
	 */
	public function save_native_event_meta( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['equine_event_manager_native_event_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['equine_event_manager_native_event_meta_nonce'] ) ), 'equine_event_manager_save_native_event_meta' ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || EEM_Events::EVENT_POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$previous_reservation_id = $this->get_linked_reservation_id_for_event( $post_id );
		$reservation_id          = isset( $_POST['equine_event_manager_native_linked_reservation'] ) ? absint( wp_unslash( $_POST['equine_event_manager_native_linked_reservation'] ) ) : 0;
		$this->set_linked_reservation_for_event( $post_id, $reservation_id );

		if ( $previous_reservation_id && $previous_reservation_id !== $reservation_id ) {
			$prev_cfg = EEM_Reservation_Config::for( $previous_reservation_id );
			if ( 'native' === $this->get_effective_event_source_for_reservation( $previous_reservation_id ) && absint( $prev_cfg->get( 'event_id', 0 ) ) === absint( $post_id ) ) {
				$prev_cfg->set( 'event_source', 'external' )
					->set( 'use_global_event_source', 0 )
					->set( 'event_id', 0 )
					->save();
				EEM_Reservation_Config::flush_cache( $previous_reservation_id );
			}
		}

		if ( $reservation_id && self::POST_TYPE === get_post_type( $reservation_id ) ) {
			$link_cfg = EEM_Reservation_Config::for( $reservation_id );
			$link_cfg->set( 'use_global_event_source', 0 )
				->set( 'event_source', 'native' )
				->set( 'event_id', $post_id )
				->save();
			EEM_Reservation_Config::flush_cache( $reservation_id );
		}
	}

	/**
	 * AJAX search for The Events Calendar events.
	 */
	public function ajax_search_tec_events() {
		check_ajax_referer( 'equine_event_manager_search_tec_events', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to search events.', 'equine-event-manager' ) ), 403 );
		}

		if ( ! $this->is_tec_event_source_available() ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$term        = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$exclude_res = isset( $_GET['reservation_id'] ) ? absint( wp_unslash( $_GET['reservation_id'] ) ) : 0;
		$results     = $this->search_the_events_calendar_events( $term, $exclude_res );

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Build assignment summary data for editor and sidebar displays.
	 *
	 * @param int    $post_id Reservation post ID.
	 * @param string $type Assignment type.
	 * @return array
	 */
	public function get_assignment_status_summary( $post_id, $type = 'stall' ) {
		$post_id             = absint( $post_id );
		// 2.3.52 — type-aware chart signal; replaces removed _en_stall_chart_enabled.
		$stalls_enabled      = self::section_enabled( $post_id, 'stalls_enabled' );
		$rv_enabled          = self::section_enabled( $post_id, 'rv_enabled' );
		$summary_cfg         = EEM_Reservation_Config::for( $post_id );
		$stall_blocks        = $summary_cfg->get( 'stall_chart_stall_blocks', array() );
		$stall_units         = $this->expand_chart_units( is_array( $stall_blocks ) ? $stall_blocks : array() );
		$rv_lots             = $summary_cfg->get( 'rv_lots', array() );
		$rv_lot_names        = $this->get_chart_rv_lot_names( is_array( $rv_lots ) ? $rv_lots : array() );
		$orders_repository   = new EEM_Orders_Repository();
		$orders              = array_filter(
			$orders_repository->get_orders( '', 'date', 'asc' ),
			function ( $order ) use ( $post_id ) {
				return absint( isset( $order['reservation_id'] ) ? $order['reservation_id'] : 0 ) === $post_id;
			}
		);

		$total_needed     = 0;
		$total_assigned   = 0;
		$is_rv            = 'rv' === $type;
		$assignment_ready = $is_rv ? ( $rv_enabled || ! empty( $rv_lot_names ) ) : ( $stalls_enabled || ! empty( $stall_units ) );
		$view_label       = $is_rv ? __( 'View RV Chart', 'equine-event-manager' ) : __( 'View Stall Chart', 'equine-event-manager' );
		$empty_message    = $is_rv ? __( 'No RV reservations have been placed on this reservation yet.', 'equine-event-manager' ) : __( 'No stall reservations have been placed on this reservation yet.', 'equine-event-manager' );
		$disabled_message = $is_rv ? __( 'Turn on RV Lot Selection to open the chart and track assigned RV lots.', 'equine-event-manager' ) : __( 'Turn on Stall Assignments to open the chart and track assigned stalls.', 'equine-event-manager' );

		foreach ( $orders as $order ) {
			if ( $is_rv ) {
				$rv_quantity = $this->order_requires_rv_assignment( $order ) ? absint( isset( $order['rv_quantity'] ) ? $order['rv_quantity'] : 0 ) : 0;

				if ( $rv_quantity <= 0 ) {
					continue;
				}

				$total_needed   += $rv_quantity;
				$assigned_units = $this->parse_assignment_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' ) );

				if ( empty( $assigned_units ) ) {
					$assigned_units = $this->parse_assignment_units_string( $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' ) );
				}
			} else {
				$stall_quantity = absint( isset( $order['stall_quantity'] ) ? $order['stall_quantity'] : 0 );

				if ( $stall_quantity <= 0 ) {
					continue;
				}

				$total_needed   += $stall_quantity;
				$assigned_units = $this->parse_assignment_units_string(
					$this->get_order_component_note_value( $order, 'stall', 'Assigned Stall Units' )
				);
			}

			$total_assigned += min( $is_rv ? $rv_quantity : $stall_quantity, count( $assigned_units ) );
		}

		$total_unassigned = max( 0, $total_needed - $total_assigned );

		return array(
			'type'             => $is_rv ? 'rv' : 'stall',
			'title'            => $is_rv ? __( 'RV Assignments', 'equine-event-manager' ) : __( 'Stall Assignments', 'equine-event-manager' ),
			'assignment_ready' => $assignment_ready,
			'total_needed'     => $total_needed,
			'total_assigned'   => $total_assigned,
			'total_unassigned' => $total_unassigned,
			'view_label'       => $view_label,
			'empty_message'    => $empty_message,
			'disabled_message' => $disabled_message,
			'chart_url'        => admin_url( 'admin.php?page=equine-event-manager-stall-charts&reservation_id=' . $post_id ),
			'generate_url'     => wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'equine_event_manager_generate_stall_assignments',
						'reservation_id' => $post_id,
					),
					admin_url( 'admin-post.php' )
				),
				'equine_event_manager_generate_stall_assignments_' . $post_id
			),
		);
	}

	/**
	 * Determine whether an order requires RV assignment handling.
	 *
	 * @param array $order Order payload.
	 * @return bool
	 */
	private function order_requires_rv_assignment( $order ) {
		$rv_quantity = absint( isset( $order['rv_quantity'] ) ? $order['rv_quantity'] : 0 );

		if ( $rv_quantity < 1 ) {
			return false;
		}

		if ( ! empty( $order['rv_arrival_date'] ) || ! empty( $order['rv_departure_date'] ) ) {
			return true;
		}

		if ( ! empty( $order['rv_subtotal'] ) && (float) $order['rv_subtotal'] > 0 ) {
			return true;
		}

		$preferred_rv_lot = $this->get_order_component_note_value( $order, 'rv', 'RV Lot' );

		if ( '' !== $preferred_rv_lot ) {
			return true;
		}

		$assigned_rv_lots = $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Lots' );

		if ( '' === $assigned_rv_lots ) {
			$assigned_rv_lots = $this->get_order_component_note_value( $order, 'rv', 'Assigned RV Units' );
		}

		return '' !== $assigned_rv_lots;
	}

	/**
	 * Get a note value from an order component row.
	 *
	 * @param array  $order Order payload.
	 * @param string $table Component type.
	 * @param string $label Note label.
	 * @return string
	 */
	private function get_order_component_note_value( $order, $table, $label ) {
		foreach ( (array) $order['components'] as $component ) {
			if ( $table !== ( isset( $component['table'] ) ? $component['table'] : '' ) ) {
				continue;
			}

			if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', (string) ( isset( $component['notes'] ) ? $component['notes'] : '' ), $matches ) ) {
				return trim( $matches[1] );
			}
		}

		return '';
	}

	/**
	 * Parse a comma-separated assignment string.
	 *
	 * @param string $value Raw value.
	 * @return array
	 */
	private function parse_assignment_units_string( $value ) {
		$units = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$units = array_map( 'sanitize_text_field', $units );

		return array_values( array_unique( $units ) );
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

		if ( ! isset( $_POST['equine_event_manager_reservation_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['equine_event_manager_reservation_meta_nonce'] ) ), 'equine_event_manager_save_reservation_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$source   = isset( $_POST['en_reservation'] ) && is_array( $_POST['en_reservation'] ) ? wp_unslash( $_POST['en_reservation'] ) : array();
		$existing = $this->get_meta_values( $post_id );
		$data     = $this->sanitize_meta_submission( $source, $existing );
		$errors   = $this->validate_meta_submission( $data );

		if ( ! empty( $errors ) ) {
			$this->store_validation_notice( $post_id, $errors );
			return;
		}

		// One-to-one enforcement: capture old TEC event link before overwriting meta.
		$old_tec_event_id = $this->get_tec_event_id_for_reservation( $post_id );
		$new_tec_event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;

		// 2.3.65 — Gate-robustness guard. The editor's hidden `event_id` field is 0
		// whenever the link gate is showing (including a transient reverse-lookup
		// miss). Writing that 0 through would set `_en_event_id = 0` AND clear the
		// event's reverse link, orphaning the reservation and locking the admin out
		// of the editor with no way back in. Unlinking is an explicit action
		// (ajax_unlink_event) — it must NEVER be a side effect of a normal save. So
		// when the save submits no event but a link already exists, preserve it.
		if ( 0 === $new_tec_event_id ) {
			$existing_en_event_id = absint( EEM_Reservation_Config::for( $post_id )->get( 'event_id', 0 ) );
			if ( $old_tec_event_id > 0 ) {
				$new_tec_event_id  = $old_tec_event_id;
				$data['event_id']  = $old_tec_event_id;
			} elseif ( $existing_en_event_id > 0 ) {
				$data['event_id']  = $existing_en_event_id;
			}
		}

		// Postmeta decouple: write to relational table (primary) with
		// postmeta fallback when the table doesn't exist yet.
		//
		// CRITICAL: use the dirty-update path (set_many()->save() → upsert_dirty),
		// NOT insert_from_values() which does a full-row $wpdb->replace(). The main
		// editor form only submits the scalar/section-enabled fields enumerated in
		// get_default_meta_values(); columns owned by their own AJAX endpoints —
		// the map snapshot (`stall_map`, `stall_map_id`, `stall_map_file_id`),
		// the `stall_rows` builder, RV zones, etc. — are NOT in $data. A full-row
		// replace() wiped every such column on every save (lost maps + layouts).
		// The dirty-update path UPDATEs only the columns present in $data and
		// leaves the separately-managed columns untouched.
		if ( EEM_Reservation_Config::table_exists() ) {
			EEM_Reservation_Config::for( $post_id )->set_many( $data )->save();
			EEM_Reservation_Config::flush_cache( $post_id );
		} else {
			foreach ( $data as $key => $value ) {
				update_post_meta( $post_id, self::section_enabled_meta_key( $key ), $value );
			}
		}

		// Bidirectional one-to-one enforcement for TEC event links.
		$this->enforce_tec_event_link_one_to_one( $post_id, $old_tec_event_id, $new_tec_event_id );

		if ( 'publish' === get_post_status( $post_id ) ) {
			$shortcode = $this->get_reservation_shortcode( $post_id );
			update_post_meta( $post_id, '_en_reservation_shortcode', $shortcode );
		} else {
			delete_post_meta( $post_id, '_en_reservation_shortcode' );
		}
	}

	/**
	 * Render a validation notice after a blocked reservation save.
	 *
	 * @return void
	 */
	public function render_validation_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$errors = $this->get_validation_notice( $post_id );

		if ( ! $screen || self::POST_TYPE !== $screen->post_type || empty( $errors ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible" role="alert"><p>%s</p></div>',
			esc_html( implode( ' ', $errors ) )
		);

		$this->clear_validation_notice( $post_id );
	}

	/**
	 * Build a compact reservation editor summary payload.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return array
	 */
	public function get_editor_summary( $post_id ) {
		$data             = $this->get_meta_values( $post_id );
		$event_source     = $this->get_effective_event_source_for_reservation( $post_id );
		$enabled_sections = array();

		if ( ! empty( $data['stalls_enabled'] ) ) {
			$enabled_sections[] = __( 'Stalls', 'equine-event-manager' );
		}

		if ( ! empty( $data['rv_enabled'] ) ) {
			$enabled_sections[] = __( 'RV', 'equine-event-manager' );
		}

		if ( ! empty( $data['general_addons_enabled'] ) ) {
			$enabled_sections[] = __( 'Add-Ons', 'equine-event-manager' );
		}

		if ( ! empty( $data['group_reservations_enabled'] ) ) {
			$enabled_sections[] = __( 'Groups', 'equine-event-manager' );
		}

		if ( ! empty( $data['venue_map_enabled'] ) ) {
			$enabled_sections[] = __( 'Map', 'equine-event-manager' );
		}

		if ( ! empty( $data['venue_agreement_enabled'] ) ) {
			$enabled_sections[] = __( 'Agreement', 'equine-event-manager' );
		}

		if ( ! empty( $data['convenience_fee_enabled'] ) ) {
			$enabled_sections[] = __( 'Fees', 'equine-event-manager' );
		}

		return array(
			'event_name'         => $this->get_event_name( $data ),
			'event_source'       => $event_source,
			'event_source_label' => $this->get_event_source_label( $event_source ),
			'date_range_label'   => $this->get_event_dates_label( $data ),
			'type_label'         => $this->get_type_label( $data ),
			'sections'           => $enabled_sections,
			'has_linked_event'   => ! empty( $data['event_id'] ) || ! empty( $data['external_event_name'] ),
		);
	}

	/**
	 * Get normalized reservation meta values for editor rendering.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return array
	 */
	public function get_editor_meta_values( $post_id ) {
		return $this->get_meta_values( $post_id );
	}

	/**
	 * Get stall editor context data.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return array
	 */
	public function get_editor_stall_context( $post_id ) {
		return $this->get_meta_values( $post_id );
	}

	/**
	 * Get general add-on editor context data.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return array
	 */
	public function get_editor_general_addons_context( $post_id ) {
		$data = $this->get_meta_values( $post_id );

		return array(
			'data'   => $data,
			'addons' => $this->get_enabled_general_addons( $data, true ),
		);
	}

	/**
	 * Build the event-link context used by the reservation editor.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return array
	 */
	public function get_editor_event_link_context( $post_id ) {
		$data                  = $this->get_meta_values( $post_id );
		$default_event_source  = $this->sanitize_reservation_event_source( EEM_Events::get_default_event_source() );
		$default_feed_url      = EEM_Events::get_default_feed_url();
		$tec_events_enabled    = $this->is_tec_event_source_available();
		$initial_events        = array();
		$native_events_enabled = EEM_Events::is_native_events_enabled();
		$native_events         = array();
		$selected_feed_event   = array();

		// Reverse lookup: the TEC event is the source of truth. Find whichever
		// tribe_events post has _equine_event_manager_reservation_id = this reservation.
		$linked_tec_event_id = $tec_events_enabled ? $this->get_tec_event_id_for_reservation( $post_id ) : 0;

		$selected_event_title = '';
		$selected_event_dates = array( 'start_date' => '', 'end_date' => '' );

		if ( 'tec' === $default_event_source && $linked_tec_event_id ) {
			$raw_title            = get_the_title( $linked_tec_event_id );
			$selected_event_dates = $this->get_tec_event_date_values( $linked_tec_event_id );
			$date_label           = $selected_event_dates['start_date'] ? wp_date( 'M j, Y', strtotime( $selected_event_dates['start_date'] ) ) : '';
			$selected_event_title = $date_label ? $raw_title . ' \xe2\x80\x94 ' . $date_label : $raw_title;
		}

		if ( $tec_events_enabled ) {
			foreach ( $this->query_tec_events_by_start_date( '', 50 ) as $event ) {
				$event_dates = $this->get_tec_event_date_values( absint( $event->ID ) );
				$raw_title   = get_the_title( $event );
				$date_label  = $event_dates['start_date'] ? wp_date( 'M j, Y', strtotime( $event_dates['start_date'] ) ) : '';
				$initial_events[] = array(
					'id'         => absint( $event->ID ),
					'title'      => $date_label ? $raw_title . ' \xe2\x80\x94 ' . $date_label : $raw_title,
					'start_date' => $event_dates['start_date'],
					'end_date'   => $event_dates['end_date'],
				);
			}
		}

		if ( $native_events_enabled ) {
			$selected_native_dates = ( 'native' === $default_event_source && $data['event_id'] ) ? EEM_Events::get_native_event_date_values( absint( $data['event_id'] ) ) : array(
				'start_date' => '',
				'end_date'   => '',
			);

			foreach ( EEM_Events::get_upcoming_native_events( 200 ) as $event ) {
				$event_dates     = EEM_Events::get_native_event_date_values( absint( $event->ID ) );
				$native_events[] = array(
					'id'         => absint( $event->ID ),
					'title'      => get_the_title( $event ),
					'start_date' => ( 'native' === $default_event_source && absint( $data['event_id'] ) === absint( $event->ID ) ) ? $selected_native_dates['start_date'] : $event_dates['start_date'],
					'end_date'   => ( 'native' === $default_event_source && absint( $data['event_id'] ) === absint( $event->ID ) ) ? $selected_native_dates['end_date'] : $event_dates['end_date'],
				);
			}
		}

		if ( 'feed' === $default_event_source && ! empty( $data['external_event_id'] ) ) {
			$selected_feed_event = EEM_Events::get_feed_event_by_external_id( $data['external_event_id'], $default_feed_url );
		}

		// Sync event_id in $data to the authoritative reverse-lookup value for TEC source.
		if ( 'tec' === $default_event_source && $linked_tec_event_id ) {
			$data['event_id'] = $linked_tec_event_id;
		}

		return array(
			'data'                 => $data,
			'default_event_source' => $default_event_source,
			'default_feed_url'     => $default_feed_url,
			'selected_event_title' => $selected_event_title,
			'selected_event_dates' => $selected_event_dates,
			'tec_events_enabled'   => $tec_events_enabled,
			'initial_events'       => $initial_events,
			'native_events_enabled'=> $native_events_enabled,
			'native_events'        => $native_events,
			'selected_feed_event'  => $selected_feed_event,
		);
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

		$event_source = $this->get_effective_event_source_for_reservation( $post_id );
		$event_id     = absint( EEM_Reservation_Config::for( $post_id )->get( 'event_id', 0 ) );
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

		if ( ! in_array( $event_source, array( 'tec', 'native' ), true ) || ! $event_id ) {
			$this->debug_log( 'event sync skipped: reservation is not linked to a supported event source.' );
			return;
		}

		if ( ! in_array( get_post_type( $event_id ), array( 'tribe_events', EEM_Events::EVENT_POST_TYPE ), true ) ) {
			$this->debug_log( 'event sync skipped: linked post is not a supported event type.' );
			return;
		}

		$this->set_linked_reservation_for_event( $event_id, $post_id );
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

		// Cheap pre-check FIRST: read the linked-event id straight from post-meta
		// instead of hydrating the full reservation config. Hydration loads the
		// large stall/RV map JSON, and doing that for every selected row during a
		// bulk Move-to-Trash exhausts PHP memory (the list crashed with a fatal).
		// No linked event (the common case, and every unlinked draft) → nothing to
		// clear, so return before touching the config at all.
		$event_id = absint( get_post_meta( $post_id, '_en_event_id', true ) );
		if ( ! $event_id || ! in_array( get_post_type( $event_id ), array( 'tribe_events', EEM_Events::EVENT_POST_TYPE ), true ) ) {
			$this->debug_log( 'cleanup skipped: no linked event (cheap post-meta check).' );
			return;
		}

		$event_source = $this->get_effective_event_source_for_reservation( $post_id );
		$shortcode    = $this->get_reservation_shortcode( $post_id );

		$this->debug_log(
			sprintf(
				'cleanup reservation_id=%d linked_tec_event_id=%d generated_shortcode=%s',
				absint( $post_id ),
				$event_id,
				$shortcode
			)
		);

		if ( ! in_array( $event_source, array( 'tec', 'native' ), true ) || ! $event_id || ! in_array( get_post_type( $event_id ), array( 'tribe_events', EEM_Events::EVENT_POST_TYPE ), true ) ) {
			$this->debug_log( 'cleanup skipped: no valid linked event.' );
			return;
		}

		if ( 'tribe_events' === get_post_type( $event_id ) && ! $this->event_reservations_field_matches_shortcode( $event_id, $shortcode ) ) {
			$this->debug_log( 'cleanup skipped: event reservations field does not match this reservation shortcode.' );
			return;
		}

		$this->set_linked_reservation_for_event( $event_id, 0 );
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
		$new_columns['title']        = __( 'Reservation', 'equine-event-manager' );
		$new_columns['event_dates']  = __( 'Event Dates', 'equine-event-manager' );
		$new_columns['type']         = __( 'Type', 'equine-event-manager' );
		$new_columns['actions']      = __( 'Actions', 'equine-event-manager' );
		$new_columns['date']         = isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'equine-event-manager' );

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

		try {
			if ( 'event_dates' === $column ) {
				echo esc_html( $this->get_event_dates_label( $data ) );
			} elseif ( 'type' === $column ) {
				echo wp_kses_post( $this->render_type_badges( $this->get_type_label( $data ) ) );
			} elseif ( 'actions' === $column ) {
				$action_links = array(
					sprintf(
					'<a class="eem-shell-icon-button" href="%1$s" aria-label="%2$s" title="%2$s"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></a>',
						esc_url( get_edit_post_link( $post_id, '' ) ),
						esc_html__( 'Edit', 'equine-event-manager' )
					),
					sprintf(
					'<a class="eem-shell-icon-button" href="%1$s" aria-label="%2$s" title="%2$s"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></a>',
						esc_url( admin_url( 'admin.php?page=equine-event-manager-reservation-overview&reservation_id=' . absint( $post_id ) ) ),
						esc_html__( 'View', 'equine-event-manager' )
					),
				);

				if ( ! empty( $data['stall_chart_enabled'] ) ) {
					$action_links[] = sprintf(
					'<a class="eem-shell-icon-button" href="%1$s" aria-label="%2$s" title="%2$s"><span class="dashicons dashicons-grid-view" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></a>',
						esc_url( admin_url( 'admin.php?page=equine-event-manager-stall-charts&reservation_id=' . absint( $post_id ) ) ),
						esc_html__( 'Stall Assignments', 'equine-event-manager' )
					);
				}

				$allowed_action_html = array(
					'div'    => array(
						'class' => true,
					),
					'a'      => array(
						'class'      => true,
						'href'       => true,
						'aria-label' => true,
						'title'      => true,
					),
					'span'   => array(
						'class'       => true,
						'aria-hidden' => true,
					),
					'svg'    => array(
						'viewBox'     => true,
						'fill'        => true,
						'role'        => true,
						'focusable'   => true,
						'aria-hidden' => true,
						'xmlns'       => true,
					),
					'path'   => array(
						'd'               => true,
						'stroke'          => true,
						'stroke-width'    => true,
						'stroke-linejoin' => true,
						'stroke-linecap'  => true,
						'fill'            => true,
					),
					'circle' => array(
						'cx'           => true,
						'cy'           => true,
						'r'            => true,
						'stroke'       => true,
						'stroke-width' => true,
						'fill'         => true,
					),
				);

				echo wp_kses(
					implode( ' ', $action_links ),
					$allowed_action_html
				);
			}
		} catch ( Throwable $exception ) {
			$this->debug_log(
				sprintf(
					'list column render failed: column=%1$s post_id=%2$d message=%3$s',
					sanitize_key( $column ),
					absint( $post_id ),
					$exception->getMessage()
				)
			);

			if ( 'event_dates' === $column ) {
				echo esc_html( $this->get_reservation_date_range_label( $data ) );
			}
		}
	}

	/**
	 * Add custom row actions to the Reservations list table.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post Current post object.
	 * @return array
	 */
	public function filter_row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		return array();
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
			1  => __( 'Reservation updated.', 'equine-event-manager' ),
			2  => __( 'Custom field updated.', 'equine-event-manager' ),
			3  => __( 'Custom field deleted.', 'equine-event-manager' ),
			4  => __( 'Reservation updated.', 'equine-event-manager' ),
			5  => __( 'Reservation restored.', 'equine-event-manager' ),
			6  => __( 'Reservation published.', 'equine-event-manager' ),
			7  => __( 'Reservation saved.', 'equine-event-manager' ),
			8  => __( 'Reservation submitted.', 'equine-event-manager' ),
			9  => __( 'Reservation scheduled.', 'equine-event-manager' ),
			10 => __( 'Reservation draft updated.', 'equine-event-manager' ),
		);

		return $messages;
	}

	/**
	 * Sanitize submitted reservation meta.
	 *
	 * Public since C7.C.1.1 so the Path A AJAX dispatcher
	 * (EEM_Reservation_Editor_Page::ajax_save) can pre-sanitize +
	 * pre-validate the payload BEFORE invoking save_meta() — closing
	 * the toast-lies-about-success bug where validation errors caused
	 * save_meta() to no-op silently while AJAX returned 200/success.
	 * Legacy meta-box callers (now retired) used the private path; the
	 * visibility flip is backwards-compatible — internal calls still
	 * work exactly as before.
	 *
	 * @param array $source Raw meta source.
	 * @param array $existing Existing reservation meta merged with defaults.
	 * @return array
	 */
	public function sanitize_meta_submission( $source, $existing = array() ) {
		$use_global_event_source = ! empty( $source['use_global_event_source'] ) ? 1 : 0;
		$configured_primary_sources = $this->get_configured_primary_event_sources();

		if ( count( $configured_primary_sources ) <= 1 && empty( $existing['use_global_event_source'] ) ) {
			$use_global_event_source = 1;
		}

		$selected_event_source = isset( $source['event_source'] ) ? sanitize_key( $source['event_source'] ) : ( $existing['event_source'] ?? '' );
		$selected_event_source = $this->sanitize_reservation_event_source( $selected_event_source );
		$event_source          = $use_global_event_source ? $this->sanitize_reservation_event_source( EEM_Events::get_default_event_source() ) : $selected_event_source;

		$event_id = 0;

		if ( 'native' === $event_source ) {
			$event_id = isset( $source['native_event_id'] ) ? absint( $source['native_event_id'] ) : 0;
		} else {
			$event_id = isset( $source['event_id'] ) ? absint( $source['event_id'] ) : 0;
		}

		if ( 'external' === $event_source ) {
			$event_id = 0;
		} elseif ( 'native' === $event_source && EEM_Events::EVENT_POST_TYPE !== get_post_type( $event_id ) ) {
			$event_id = 0;
		} elseif ( 'tec' === $event_source && 'tribe_events' !== get_post_type( $event_id ) ) {
			$event_id = 0;
		}

		$existing = wp_parse_args( $existing, $this->get_default_meta_values() );

		$stall_chart_stall_blocks = isset( $source['stall_chart_stall_blocks'] ) && is_array( $source['stall_chart_stall_blocks'] ) ? $this->sanitize_chart_blocks( $source['stall_chart_stall_blocks'] ) : $existing['stall_chart_stall_blocks'];
		$stall_chart_stall_units  = $this->expand_chart_units( $stall_chart_stall_blocks );
		$rv_lots                  = isset( $source['rv_lots'] ) && is_array( $source['rv_lots'] ) ? $this->sanitize_rv_lots( $source['rv_lots'] ) : $existing['rv_lots'];
		$rv_lot_names             = $this->get_chart_rv_lot_names( $rv_lots );

		// Scenario B (V1 #4): resolve the stall mode pair on save. The new
		// two-control editor submits stall_inventory_type + stall_customer_selection;
		// the legacy single select submits stall_selection_mode. Prefer the new
		// controls, else derive the pair from a legacy submit, else keep existing.
		// Then enforce validity + derive the legacy mode so all three persist
		// consistently (save_meta writes every key in this array as _en_{key}).
		if ( isset( $source['stall_inventory_type'] ) || isset( $source['stall_customer_selection'] ) ) {
			$stall_inventory_type     = self::sanitize_stall_inventory_type( isset( $source['stall_inventory_type'] ) ? $source['stall_inventory_type'] : $existing['stall_inventory_type'] );
			$stall_customer_selection = self::sanitize_stall_customer_selection( isset( $source['stall_customer_selection'] ) ? $source['stall_customer_selection'] : $existing['stall_customer_selection'] );
		} elseif ( isset( $source['stall_selection_mode'] ) ) {
			$legacy_submit            = $this->sanitize_stall_selection_mode( $source['stall_selection_mode'] );
			$stall_inventory_type     = ( 'exact_map' === $legacy_submit ) ? 'numbered' : 'quantity_only';
			$stall_customer_selection = ( 'exact_map' === $legacy_submit ) ? 'pick_layout' : 'quantity';
		} else {
			$stall_inventory_type     = self::sanitize_stall_inventory_type( $existing['stall_inventory_type'] );
			$stall_customer_selection = self::sanitize_stall_customer_selection( $existing['stall_customer_selection'] );
		}
		if ( 'quantity_only' === $stall_inventory_type ) {
			$stall_customer_selection = 'quantity';
		}
		$stall_selection_mode = self::derive_stall_selection_mode( $stall_inventory_type, $stall_customer_selection );

		$data = array(
			'use_global_event_source'        => $use_global_event_source,
			'event_source'                    => $event_source,
			'event_id'                        => $event_id,
			'event_feed_url'                  => isset( $source['event_feed_url'] ) ? esc_url_raw( wp_unslash( $source['event_feed_url'] ) ) : '',
			'external_event_name'             => isset( $source['external_event_name'] ) ? sanitize_text_field( $source['external_event_name'] ) : '',
			'external_event_id'               => isset( $source['external_event_id'] ) ? sanitize_text_field( $source['external_event_id'] ) : '',
			'stalls_enabled'                  => isset( $source['stalls_enabled'] ) ? 1 : 0,
			'stall_selection_mode'            => $stall_selection_mode,
			'stall_inventory_type'            => $stall_inventory_type,
			'stall_customer_selection'        => $stall_customer_selection,
			'rv_enabled'                      => isset( $source['rv_enabled'] ) ? 1 : 0,
			'nightly_enabled'                 => isset( $source['nightly_enabled'] ) ? 1 : 0,
			'weekend_enabled'                 => isset( $source['weekend_enabled'] ) ? 1 : 0,
			'stall_nightly_enabled'           => isset( $source['stall_nightly_enabled'] ) ? 1 : 0,
			'stall_weekend_enabled'           => isset( $source['stall_weekend_enabled'] ) ? 1 : 0,
			'stall_weekly_enabled'            => isset( $source['stall_weekly_enabled'] ) ? 1 : 0,
			'rv_nightly_enabled'              => isset( $source['rv_nightly_enabled'] ) ? 1 : 0,
			'rv_weekend_enabled'              => isset( $source['rv_weekend_enabled'] ) ? 1 : 0,
			'rv_weekly_enabled'               => isset( $source['rv_weekly_enabled'] ) ? 1 : 0,
			'available_start_date'            => $this->sanitize_date_value( isset( $source['available_start_date'] ) ? $source['available_start_date'] : '' ),
			'available_end_date'              => $this->sanitize_date_value( isset( $source['available_end_date'] ) ? $source['available_end_date'] : '' ),
			'weekend_package_start_date'      => $this->sanitize_date_value( isset( $source['weekend_package_start_date'] ) ? $source['weekend_package_start_date'] : $existing['weekend_package_start_date'] ),
			'weekend_package_end_date'        => $this->sanitize_date_value( isset( $source['weekend_package_end_date'] ) ? $source['weekend_package_end_date'] : $existing['weekend_package_end_date'] ),
			'stall_weekend_package_start_date' => $this->sanitize_date_value( isset( $source['stall_weekend_package_start_date'] ) ? $source['stall_weekend_package_start_date'] : $existing['stall_weekend_package_start_date'] ),
			'stall_weekend_package_end_date'   => $this->sanitize_date_value( isset( $source['stall_weekend_package_end_date'] ) ? $source['stall_weekend_package_end_date'] : $existing['stall_weekend_package_end_date'] ),
			'rv_weekend_package_start_date'    => $this->sanitize_date_value( isset( $source['rv_weekend_package_start_date'] ) ? $source['rv_weekend_package_start_date'] : $existing['rv_weekend_package_start_date'] ),
			'rv_weekend_package_end_date'      => $this->sanitize_date_value( isset( $source['rv_weekend_package_end_date'] ) ? $source['rv_weekend_package_end_date'] : $existing['rv_weekend_package_end_date'] ),
			'stall_weekly_package_start_date'  => $this->sanitize_date_value( isset( $source['stall_weekly_package_start_date'] ) ? $source['stall_weekly_package_start_date'] : ( $existing['stall_weekly_package_start_date'] ?? '' ) ),
			'stall_weekly_package_end_date'    => $this->sanitize_date_value( isset( $source['stall_weekly_package_end_date'] ) ? $source['stall_weekly_package_end_date'] : ( $existing['stall_weekly_package_end_date'] ?? '' ) ),
			'rv_weekly_package_start_date'     => $this->sanitize_date_value( isset( $source['rv_weekly_package_start_date'] ) ? $source['rv_weekly_package_start_date'] : ( $existing['rv_weekly_package_start_date'] ?? '' ) ),
			'rv_weekly_package_end_date'       => $this->sanitize_date_value( isset( $source['rv_weekly_package_end_date'] ) ? $source['rv_weekly_package_end_date'] : ( $existing['rv_weekly_package_end_date'] ?? '' ) ),
			'available_dates_manually_edited' => isset( $source['available_dates_manually_edited'] ) ? absint( $source['available_dates_manually_edited'] ) : 0,
			'sync_stay_selections'          => 0,
			'stall_description'               => isset( $source['stall_description'] ) ? sanitize_textarea_field( $source['stall_description'] ) : '',
			'stall_schedule_enabled'         => isset( $source['stall_schedule_enabled'] ) ? 1 : 0,
			'stalls_open_at'                  => $this->sanitize_datetime_value( isset( $source['stalls_open_at'] ) ? $source['stalls_open_at'] : '' ),
			'stalls_close_at'                 => $this->sanitize_datetime_value( isset( $source['stalls_close_at'] ) ? $source['stalls_close_at'] : '' ),
			'stall_inventory'                 => $this->sanitize_optional_inventory_value( isset( $source['stall_inventory'] ) ? $source['stall_inventory'] : '' ),
			'rv_description'                  => isset( $source['rv_description'] ) ? sanitize_textarea_field( $source['rv_description'] ) : '',
			'rv_schedule_enabled'            => isset( $source['rv_schedule_enabled'] ) ? 1 : 0,
			'rv_open_at'                      => $this->sanitize_datetime_value( isset( $source['rv_open_at'] ) ? $source['rv_open_at'] : '' ),
			'rv_close_at'                     => $this->sanitize_datetime_value( isset( $source['rv_close_at'] ) ? $source['rv_close_at'] : '' ),
			'rv_inventory'                    => $this->sanitize_optional_inventory_value( isset( $source['rv_inventory'] ) ? $source['rv_inventory'] : '' ),
			'stall_chart_stall_blocks'       => $stall_chart_stall_blocks,
			'stall_chart_blocked_stall_units'=> isset( $source['stall_chart_blocked_stall_units'] ) ? $this->sanitize_chart_unit_list( $source['stall_chart_blocked_stall_units'], $stall_chart_stall_units ) : $existing['stall_chart_blocked_stall_units'],
			'stall_chart_rv_blocks'          => array(),
			'stall_chart_blocked_rv_units'   => isset( $source['stall_chart_blocked_rv_units'] ) ? $this->sanitize_chart_unit_list( $source['stall_chart_blocked_rv_units'], $rv_lot_names ) : $existing['stall_chart_blocked_rv_units'],
			'stall_map_file_id'              => isset( $source['stall_map_file_id'] ) ? absint( $source['stall_map_file_id'] ) : $existing['stall_map_file_id'],
			'rv_lot_selection_enabled'        => isset( $source['rv_lot_selection_enabled'] ) ? 1 : 0,
			'rv_addons_enabled'               => isset( $source['rv_addons_enabled'] ) ? 1 : 0,
			'rv_lots'                         => $rv_lots,
			'stall_nightly_rate'              => isset( $source['stall_nightly_rate'] ) ? $this->sanitize_money_value( $source['stall_nightly_rate'] ) : $existing['stall_nightly_rate'],
			'stall_weekend_rate'              => isset( $source['stall_weekend_rate'] ) ? $this->sanitize_money_value( $source['stall_weekend_rate'] ) : $existing['stall_weekend_rate'],
			'stall_weekly_rate'               => isset( $source['stall_weekly_rate'] ) ? $this->sanitize_money_value( $source['stall_weekly_rate'] ) : ( $existing['stall_weekly_rate'] ?? '0.00' ),
			'stall_early_bird_enabled'        => isset( $source['stall_early_bird_enabled'] ) ? 1 : 0,
			'stall_early_bird_cutoff'         => $this->sanitize_datetime_value( isset( $source['stall_early_bird_cutoff'] ) ? $source['stall_early_bird_cutoff'] : '' ),
			'stall_early_bird_nightly_rate'   => isset( $source['stall_early_bird_nightly_rate'] ) ? $this->sanitize_money_value( $source['stall_early_bird_nightly_rate'] ) : $existing['stall_early_bird_nightly_rate'],
			'stall_early_bird_weekend_rate'   => isset( $source['stall_early_bird_weekend_rate'] ) ? $this->sanitize_money_value( $source['stall_early_bird_weekend_rate'] ) : $existing['stall_early_bird_weekend_rate'],
			'stall_early_bird_weekly_rate'    => isset( $source['stall_early_bird_weekly_rate'] ) ? $this->sanitize_money_value( $source['stall_early_bird_weekly_rate'] ) : ( $existing['stall_early_bird_weekly_rate'] ?? '0.00' ),
			'required_shavings_enabled'       => isset( $source['required_shavings_enabled'] ) ? 1 : 0,
			// Tack Stall mode — 'off' or 'customer' (on). On = buyers flag a tack
			// stall at checkout for the shavings exclusion; the admin assigns the
			// actual tack stall on the Stall Chart ("Mark as Tack Stall").
			'stall_tack_mode'                 => self::sanitize_stall_tack_mode( isset( $source['stall_tack_mode'] ) ? $source['stall_tack_mode'] : ( $existing['stall_tack_mode'] ?? 'off' ) ),
			'required_shavings_per_stall'     => isset( $source['required_shavings_per_stall'] ) ? absint( $source['required_shavings_per_stall'] ) : 0,
			'required_shavings_price'         => isset( $source['required_shavings_price'] ) ? $this->sanitize_money_value( $source['required_shavings_price'] ) : $existing['required_shavings_price'],
			'additional_shavings_enabled'     => isset( $source['additional_shavings_enabled'] ) ? 1 : 0,
			'additional_shavings_description' => isset( $source['additional_shavings_description'] ) ? sanitize_textarea_field( $source['additional_shavings_description'] ) : '',
			'additional_shavings_price'       => $this->sanitize_money_value( isset( $source['additional_shavings_price'] ) ? $source['additional_shavings_price'] : '' ),
			'reservation_description'         => isset( $source['reservation_description'] ) ? sanitize_textarea_field( $source['reservation_description'] ) : '',
			'event_details_summary'           => isset( $source['event_details_summary'] ) ? sanitize_textarea_field( $source['event_details_summary'] ) : '',
			'venue_name'                      => isset( $source['venue_name'] ) ? sanitize_text_field( $source['venue_name'] ) : '',
			'event_location'                  => isset( $source['event_location'] ) ? sanitize_text_field( $source['event_location'] ) : '',
			'venue_address'                   => isset( $source['venue_address'] ) ? sanitize_textarea_field( $source['venue_address'] ) : '',
			// 2.3.48 — value-aware so a submitted "0" persists as OFF instead
			// of being read as "present therefore on". Defense in depth: no
			// longer depends on the JS collector omitting the off-mirror.
			'checkin_checkout_enabled'        => ( isset( $source['checkin_checkout_enabled'] ) && '1' === (string) $source['checkin_checkout_enabled'] ) ? 1 : 0,
			'checkin_time_enabled'            => isset( $source['checkin_time_enabled'] ) ? 1 : 0,
			'checkout_time_enabled'           => isset( $source['checkout_time_enabled'] ) ? 1 : 0,
			// 2.3.70 — check-in / check-out are time-of-day only (no date).
			'checkin_time'                    => $this->sanitize_time_value( isset( $source['checkin_time'] ) ? $source['checkin_time'] : '' ),
			'checkout_time'                   => $this->sanitize_time_value( isset( $source['checkout_time'] ) ? $source['checkout_time'] : '' ),
			'venue_map_enabled'              => isset( $source['venue_map_enabled'] ) ? 1 : 0,
			'venue_map_download_url'          => isset( $source['venue_map_download_url'] ) ? esc_url_raw( wp_unslash( $source['venue_map_download_url'] ) ) : '',
			'venue_map_image_id'              => isset( $source['venue_map_image_id'] ) ? absint( $source['venue_map_image_id'] ) : 0,
			'venue_map_caption'               => isset( $source['venue_map_caption'] ) ? sanitize_text_field( $source['venue_map_caption'] ) : '',
			'venue_agreement_enabled'         => ( isset( $source['venue_agreement_enabled'] ) && '1' === (string) $source['venue_agreement_enabled'] ) ? 1 : 0,
			'venue_agreement_file_id'         => isset( $source['venue_agreement_file_id'] ) ? $this->sanitize_agreement_file_id( $source['venue_agreement_file_id'] ) : 0,
			'venue_agreement_file_label'      => isset( $source['venue_agreement_file_label'] ) ? sanitize_text_field( $source['venue_agreement_file_label'] ) : __( 'Agreement', 'equine-event-manager' ),
			'venue_agreement_label'           => isset( $source['venue_agreement_label'] ) ? sanitize_text_field( $source['venue_agreement_label'] ) : __( 'I agree to the venue terms and conditions.', 'equine-event-manager' ),
			// C7.X.12 VV-4 — customer-facing link text for the agreement
			// in the event-page yellow callout + order summary. Distinct
			// from `venue_agreement_label` (checkbox text) + `venue_
			// agreement_file_label` (admin file display). Empty default
			// — customer-facing renderers fall back to literal "Venue
			// Agreement" when blank.
			'venue_agreement_link_label'      => isset( $source['venue_agreement_link_label'] ) ? sanitize_text_field( $source['venue_agreement_link_label'] ) : '',
			'venue_agreement_text'            => isset( $source['venue_agreement_text'] ) ? sanitize_textarea_field( $source['venue_agreement_text'] ) : '',
			'additional_shavings_enabled'      => ( isset( $source['additional_shavings_enabled'] ) && '1' === (string) $source['additional_shavings_enabled'] ) ? 1 : 0,
			'additional_shavings_products'     => isset( $source['additional_shavings_products'] ) && is_array( $source['additional_shavings_products'] ) ? $this->sanitize_additional_shavings_products( $source['additional_shavings_products'] ) : ( isset( $existing['additional_shavings_products'] ) ? (array) $existing['additional_shavings_products'] : array() ),
			'general_addons_enabled'          => ( isset( $source['general_addons_enabled'] ) && '1' === (string) $source['general_addons_enabled'] ) ? 1 : 0,
			// Required Documents — section toggle + description + repeatable
			// line items (each: name + required-before-checkout flag). Customer
			// uploads attach per-order downstream; this is the admin-defined list.
			'required_documents_enabled'      => ( isset( $source['required_documents_enabled'] ) && '1' === (string) $source['required_documents_enabled'] ) ? 1 : 0,
			'required_documents_description'  => isset( $source['required_documents_description'] ) ? sanitize_textarea_field( $source['required_documents_description'] ) : '',
			'required_documents'              => isset( $source['required_documents'] ) && is_array( $source['required_documents'] ) ? $this->sanitize_required_documents( $source['required_documents'] ) : ( isset( $existing['required_documents'] ) ? (array) $existing['required_documents'] : array() ),
			'group_reservations_enabled'      => isset( $source['group_reservations_enabled'] ) ? 1 : 0,
			// C7.C.1.4.A Decision N1 — NEW meta keys for mockup-canonical
			// group section (line 958-970). Non-destructive additive
			// schema (Option L1 pattern); customer-facing consumers wire
			// in C16 cascade per CLEANUP entry.
			'group_description'               => isset( $source['group_description'] ) ? sanitize_textarea_field( $source['group_description'] ) : '',
			// 2.3.82: blank = unlimited. An empty (or zero) submission stores ''
			// so downstream consumers treat the group size as uncapped.
			'group_riders_per_group'          => ( isset( $source['group_riders_per_group'] ) && '' !== trim( (string) $source['group_riders_per_group'] ) && absint( $source['group_riders_per_group'] ) > 0 )
				? absint( $source['group_riders_per_group'] )
				: '',
			'group_rider_grounds_fee_enabled' => isset( $source['group_rider_grounds_fee_enabled'] ) ? 1 : 0,
			'group_rider_grounds_fee_amount'  => isset( $source['group_rider_grounds_fee_amount'] ) ? $this->sanitize_money_value( $source['group_rider_grounds_fee_amount'] ) : '0.00',
			'group_rider_deposit_enabled'     => isset( $source['group_rider_deposit_enabled'] ) ? 1 : 0,
			'group_rider_deposit_amount'      => isset( $source['group_rider_deposit_amount'] ) ? $this->sanitize_money_value( $source['group_rider_deposit_amount'] ) : '0.00',
			'general_addons'                  => isset( $source['general_addons'] ) && is_array( $source['general_addons'] ) ? $this->sanitize_general_addons( $source['general_addons'] ) : $existing['general_addons'],
			'rv_addons'                       => isset( $source['rv_addons'] ) && is_array( $source['rv_addons'] ) ? $this->sanitize_rv_addons( $source['rv_addons'] ) : $existing['rv_addons'],
			// C7.X.4 — NEW: rv_lot_zones (pricing tiers — color slug from
			// 8-preset palette + name + surcharge). Non-destructive
			// additive schema (Option L1 — see CLEANUP #44).
			'rv_lot_zones'                    => isset( $source['rv_lot_zones'] ) && is_array( $source['rv_lot_zones'] ) ? $this->sanitize_rv_lot_zones( $source['rv_lot_zones'] ) : ( isset( $existing['rv_lot_zones'] ) ? (array) $existing['rv_lot_zones'] : array() ),
			// C7.X.5 — Event Day Info section meta (mockup lines 425-473).
			'event_day_enabled'               => isset( $source['event_day_enabled'] ) ? 1 : 0,
			'event_day_checkin'               => isset( $source['event_day_checkin'] ) ? sanitize_text_field( $source['event_day_checkin'] ) : '',
			'event_day_bring'                 => isset( $source['event_day_bring'] ) ? sanitize_textarea_field( $source['event_day_bring'] ) : '',
			'event_day_parking'               => isset( $source['event_day_parking'] ) ? sanitize_textarea_field( $source['event_day_parking'] ) : '',
			'event_day_contact'               => isset( $source['event_day_contact'] ) ? sanitize_text_field( $source['event_day_contact'] ) : '',
			// C7.X.6 — Cancellation Policy section meta. Per-reservation
			// override (empty = inherit event default). Event-level
			// default lives on the Event CPT (event_defaults table from C7.A).
			'cancellation_enabled'            => isset( $source['cancellation_enabled'] ) ? 1 : 0,
			'cancellation_policy_override'    => isset( $source['cancellation_policy_override'] ) ? sanitize_textarea_field( $source['cancellation_policy_override'] ) : '',
			'rv_nightly_rate'                 => isset( $source['rv_nightly_rate'] ) ? $this->sanitize_money_value( $source['rv_nightly_rate'] ) : $existing['rv_nightly_rate'],
			'rv_weekend_rate'                 => isset( $source['rv_weekend_rate'] ) ? $this->sanitize_money_value( $source['rv_weekend_rate'] ) : $existing['rv_weekend_rate'],
			'rv_weekly_rate'                  => isset( $source['rv_weekly_rate'] ) ? $this->sanitize_money_value( $source['rv_weekly_rate'] ) : ( $existing['rv_weekly_rate'] ?? '0.00' ),
			'rv_early_bird_enabled'           => isset( $source['rv_early_bird_enabled'] ) ? 1 : 0,
			'rv_early_bird_cutoff'            => $this->sanitize_datetime_value( isset( $source['rv_early_bird_cutoff'] ) ? $source['rv_early_bird_cutoff'] : '' ),
			'rv_early_bird_nightly_rate'      => isset( $source['rv_early_bird_nightly_rate'] ) ? $this->sanitize_money_value( $source['rv_early_bird_nightly_rate'] ) : $existing['rv_early_bird_nightly_rate'],
			'rv_early_bird_weekend_rate'      => isset( $source['rv_early_bird_weekend_rate'] ) ? $this->sanitize_money_value( $source['rv_early_bird_weekend_rate'] ) : $existing['rv_early_bird_weekend_rate'],
			'rv_early_bird_weekly_rate'       => isset( $source['rv_early_bird_weekly_rate'] ) ? $this->sanitize_money_value( $source['rv_early_bird_weekly_rate'] ) : ( $existing['rv_early_bird_weekly_rate'] ?? '0.00' ),
			'convenience_fee_label'           => isset( $source['convenience_fee_label'] ) ? sanitize_text_field( $source['convenience_fee_label'] ) : __( 'Non-Refundable Convenience Fee', 'equine-event-manager' ),
			'convenience_fee_enabled'         => isset( $source['convenience_fee_enabled'] ) ? 1 : 0,
			'convenience_fee_type'            => $this->sanitize_fee_type( isset( $source['convenience_fee_type'] ) ? $source['convenience_fee_type'] : 'none' ),
			'convenience_fee_value'           => $this->sanitize_money_value( isset( $source['convenience_fee_value'] ) ? $source['convenience_fee_value'] : '' ),
			'stall_pricing_mode'              => isset( $source['stall_pricing_mode'] ) && in_array( $source['stall_pricing_mode'], array( 'nightly', 'packages', 'both' ), true ) ? $source['stall_pricing_mode'] : ( $existing['stall_pricing_mode'] ?? 'nightly' ),
			'rv_pricing_mode'                 => isset( $source['rv_pricing_mode'] ) && in_array( $source['rv_pricing_mode'], array( 'nightly', 'packages', 'both' ), true ) ? $source['rv_pricing_mode'] : ( $existing['rv_pricing_mode'] ?? 'nightly' ),
		);

		if ( 'percentage' === $data['convenience_fee_type'] && (float) $data['convenience_fee_value'] > 100 ) {
			$data['convenience_fee_value'] = '100.00';
		}

		if ( ! $data['convenience_fee_enabled'] ) {
			$data['convenience_fee_type'] = 'none';
		}

		if ( ! $data['stall_nightly_enabled'] && ! $data['stall_weekend_enabled'] && ! $data['stall_weekly_enabled'] ) {
			$data['stall_nightly_enabled'] = 1;
		}

		if ( ! $data['rv_nightly_enabled'] && ! $data['rv_weekend_enabled'] && ! $data['rv_weekly_enabled'] ) {
			$data['rv_nightly_enabled'] = 1;
		}

		if ( ! $data['required_shavings_enabled'] ) {
			$data['required_shavings_per_stall'] = 0;
		}

		$data['nightly_enabled'] = $data['stall_nightly_enabled'] || $data['rv_nightly_enabled'] ? 1 : 0;
		$data['weekend_enabled'] = $data['stall_weekend_enabled'] || $data['rv_weekend_enabled'] ? 1 : 0;
		$data['weekly_enabled']  = $data['stall_weekly_enabled'] || $data['rv_weekly_enabled'] ? 1 : 0;

		if ( 'feed' === $data['event_source'] && empty( $data['event_feed_url'] ) ) {
			$data['event_feed_url'] = EEM_Events::get_default_feed_url();
		}

		$data = $this->normalize_date_range( $data, 'stalls_open_at', 'stalls_close_at' );
		$data = $this->normalize_date_range( $data, 'rv_open_at', 'rv_close_at' );
		$data = $this->populate_available_dates_from_event( $data );
		$data = $this->normalize_date_only_range( $data, 'available_start_date', 'available_end_date' );
		$data = $this->normalize_date_only_range( $data, 'stall_weekend_package_start_date', 'stall_weekend_package_end_date' );
		$data = $this->normalize_date_only_range( $data, 'rv_weekend_package_start_date', 'rv_weekend_package_end_date' );
		$data = $this->normalize_date_only_range( $data, 'stall_weekly_package_start_date', 'stall_weekly_package_end_date' );
		$data = $this->normalize_date_only_range( $data, 'rv_weekly_package_start_date', 'rv_weekly_package_end_date' );
		$data = $this->normalize_weekend_package_range( $data, 'stall' );
		$data = $this->normalize_weekend_package_range( $data, 'rv' );
		$data = $this->normalize_weekly_package_range( $data, 'stall' );
		$data = $this->normalize_weekly_package_range( $data, 'rv' );

		return $data;
	}

	/**
	 * Validate enabled reservation cards before saving.
	 *
	 * Public since C7.C.1.1 — see sanitize_meta_submission() docblock
	 * for the AJAX pre-validate rationale. Returns the list of
	 * human-readable error strings; empty array means clean.
	 *
	 * @param array $data Sanitized reservation data.
	 * @return string[]
	 */
	public function validate_meta_submission( $data ) {
		$errors = array();

		// The 'feed' source is valid when either a classic External Feed URL is
		// set OR a GEMS Web Data connection is configured (GEMS authenticates with
		// an Association ID + JWT, not a feed URL — so it has no event_feed_url).
		$gems_connected = class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured();
		if ( 'feed' === $data['event_source'] && empty( $data['event_feed_url'] ) && ! $gems_connected ) {
			$errors[] = __( 'No event source is connected. Connect the GEMS for WordPress plugin (or add an External Feed URL) in Settings > Integrations before saving this reservation.', 'equine-event-manager' );
		}

		return $errors;
	}

	/**
	 * Validate stall chart ranges so overlapping unit numbers cannot be saved.
	 *
	 * @param array  $blocks Configured block ranges.
	 * @param string $unit_type_label Human-readable unit type label.
	 * @return string[]
	 */
	private function validate_chart_block_ranges( $blocks, $unit_type_label ) {
		$errors = array();
		$ranges = array();

		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$label = isset( $block['label'] ) ? sanitize_text_field( $block['label'] ) : '';
			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( '' === $label || ! $start || ! $end ) {
				continue;
			}

			$ranges[] = array(
				'label' => $label,
				'start' => min( $start, $end ),
				'end'   => max( $start, $end ),
			);
		}

		usort(
			$ranges,
			function ( $left, $right ) {
				if ( $left['start'] === $right['start'] ) {
					return $left['end'] <=> $right['end'];
				}

				return $left['start'] <=> $right['start'];
			}
		);

		for ( $index = 1; $index < count( $ranges ); $index++ ) {
			$previous = $ranges[ $index - 1 ];
			$current  = $ranges[ $index ];

			if ( $current['start'] <= $previous['end'] ) {
				$errors[] = sprintf(
					/* translators: 1: unit type, 2: previous block label, 3: previous range, 4: current block label, 5: current range */
					__( 'Stall Assignments %1$s ranges cannot overlap. "%2$s" (%3$s) conflicts with "%4$s" (%5$s).', 'equine-event-manager' ),
					$unit_type_label,
					$previous['label'],
					$previous['start'] . '-' . $previous['end'],
					$current['label'],
					$current['start'] . '-' . $current['end']
				);
			}
		}

		return $errors;
	}

	/**
	 * Get saved meta values with defaults.
	 *
	 * Public since C7.C.1.1 — see sanitize_meta_submission() docblock
	 * for the AJAX pre-validate rationale (callers need the merged
	 * existing-with-defaults shape to pass into sanitize).
	 *
	 * @param int  $post_id         Post ID.
	 * @param bool $prefer_postmeta When true, read raw values from post meta
	 *                              ONLY and never consult EEM_Reservation_Config.
	 *                              This is the Phase-1 postmeta reader, used by
	 *                              (a) the one-time config-table backfill migration
	 *                              (016), which runs before any table row exists,
	 *                              and (b) EEM_Reservation_Config::hydrate()'s
	 *                              fallback for reservations lacking a table row.
	 *                              Both callers MUST pass true to break the
	 *                              otherwise-infinite mutual recursion
	 *                              get_meta_values() ↔ Config::for() ↔ hydrate().
	 * @return array
	 */
	public function get_meta_values( $post_id, bool $prefer_postmeta = false ) {
		$defaults = $this->get_default_meta_values();
		$values   = array();

		// Primary source: reservation_config table (postmeta decouple).
		// When the table exists, its row is the authoritative store for all
		// columns it contains. Post meta is the fallback for keys NOT in
		// the table and for reservations that predate the table.
		//
		// $prefer_postmeta short-circuits the table read so this method is a
		// pure postmeta reader — see the @param docblock for why that is
		// required to avoid mutual recursion with Config::for().
		$cfg     = null;
		$cfg_row = array();
		if ( ! $prefer_postmeta && EEM_Reservation_Config::table_exists() ) {
			$cfg     = EEM_Reservation_Config::for( (int) $post_id );
			$cfg_row = $cfg->all();
		}

		foreach ( $defaults as $key => $default ) {
			if ( ! empty( $cfg_row ) && array_key_exists( $key, $cfg_row ) && null !== $cfg_row[ $key ] ) {
				$values[ $key ] = $cfg_row[ $key ];
			} else {
				$value = self::read_section_enabled_raw( $post_id, $key );
				$values[ $key ] = '' === $value ? $default : $value;
			}
		}

		if ( ! metadata_exists( 'post', $post_id, '_en_use_global_event_source' ) ) {
			$legacy_source_config = '' !== (string) get_post_meta( $post_id, '_en_event_source', true )
				|| absint( get_post_meta( $post_id, '_en_event_id', true ) ) > 0
				|| '' !== (string) get_post_meta( $post_id, '_en_event_feed_url', true )
				|| '' !== (string) get_post_meta( $post_id, '_en_external_event_name', true );

			$values['use_global_event_source'] = $legacy_source_config ? 0 : 1;
		}

		$values['event_source'] = $this->get_effective_event_source( $values );

		// Expose the reservation ID for section templates that need it (e.g. Stay Packages query).
		$values['_reservation_id'] = (int) $post_id;

		// Stay Packages pricing mode. Primary store is the reservation_config
		// table, but it is ALSO mirrored to post meta (`_en_stall_pricing_mode`
		// / `_en_rv_pricing_mode`) on save — see EEM_Reservation_Editor_Page.
		// The post-meta mirror is the resilient fallback for environments where
		// the table column read/write isn't taking (observed on a WP Engine
		// staging install, 2.7.583): read the table first, then post meta, then
		// default to 'nightly'.
		if ( $prefer_postmeta ) {
			$values['stall_pricing_mode'] = get_post_meta( $post_id, '_en_stall_pricing_mode', true ) ?: 'nightly';
			$values['rv_pricing_mode']    = get_post_meta( $post_id, '_en_rv_pricing_mode', true ) ?: 'nightly';
		} else {
			$cfg = EEM_Reservation_Config::for( (int) $post_id );
			$values['stall_pricing_mode'] = $cfg->get( 'stall_pricing_mode' ) ?: ( get_post_meta( $post_id, '_en_stall_pricing_mode', true ) ?: 'nightly' );
			$values['rv_pricing_mode']    = $cfg->get( 'rv_pricing_mode' ) ?: ( get_post_meta( $post_id, '_en_rv_pricing_mode', true ) ?: 'nightly' );
		}

		// Scenario B (V1 #4): resolve the inventory-type / customer-selection pair
		// (new keys win; else derived from the legacy mode for pre-migration
		// reservations) and re-derive the legacy selection_mode from the pair so
		// every downstream reader stays consistent.
		$stall_pair = self::resolve_stall_pair( (int) $post_id );
		$values['stall_inventory_type']     = $stall_pair['inventory_type'];
		$values['stall_customer_selection'] = $stall_pair['customer_selection'];
		$values['stall_selection_mode']     = $stall_pair['selection_mode'];

		// v4 RV two-control — same pair resolution for RV.
		$rv_pair = self::resolve_rv_pair( (int) $post_id );
		$values['rv_inventory_type']     = $rv_pair['inventory_type'];
		$values['rv_customer_selection'] = $rv_pair['customer_selection'];
		$values['rv_selection_mode']     = $rv_pair['selection_mode'];

		$stall_start_date = get_post_meta( $post_id, '_en_stall_available_start_date', true );
		$stall_end_date   = get_post_meta( $post_id, '_en_stall_available_end_date', true );
		$rv_start_date    = get_post_meta( $post_id, '_en_rv_available_start_date', true );
		$rv_end_date      = get_post_meta( $post_id, '_en_rv_available_end_date', true );

		if ( '' === $values['available_start_date'] ) {
			$values['available_start_date'] = $stall_start_date ? $stall_start_date : $rv_start_date;
		}

		if ( '' === $values['available_end_date'] ) {
			$values['available_end_date'] = $stall_end_date ? $stall_end_date : $rv_end_date;
		}

		if ( '' === $values['stall_weekend_package_start_date'] ) {
			$values['stall_weekend_package_start_date'] = $values['weekend_package_start_date'] ? $values['weekend_package_start_date'] : $values['available_start_date'];
		}

		if ( '' === $values['stall_weekend_package_end_date'] ) {
			$values['stall_weekend_package_end_date'] = $values['weekend_package_end_date'] ? $values['weekend_package_end_date'] : $values['available_end_date'];
		}

		if ( '' === $values['rv_weekend_package_start_date'] ) {
			$values['rv_weekend_package_start_date'] = $values['weekend_package_start_date'] ? $values['weekend_package_start_date'] : $values['available_start_date'];
		}

		if ( '' === $values['rv_weekend_package_end_date'] ) {
			$values['rv_weekend_package_end_date'] = $values['weekend_package_end_date'] ? $values['weekend_package_end_date'] : $values['available_end_date'];
		}

		if ( ! $values['stall_nightly_enabled'] && ! $values['stall_weekend_enabled'] ) {
			$values['stall_nightly_enabled'] = $values['nightly_enabled'] ? 1 : 0;
			$values['stall_weekend_enabled'] = $values['weekend_enabled'] ? 1 : 0;
		}

		if ( ! $values['rv_nightly_enabled'] && ! $values['rv_weekend_enabled'] ) {
			$values['rv_nightly_enabled'] = $values['nightly_enabled'] ? 1 : 0;
			$values['rv_weekend_enabled'] = $values['weekend_enabled'] ? 1 : 0;
		}

		// Legacy auto-enable inference: only fire when the flag was NEVER
		// explicitly stored (pre-toggle-era reservations). Once a save has
		// written the flag — including an explicit 0 from turning the toggle
		// OFF — respect the stored value. Without the metadata_exists() guard
		// the section flips back ON on every reload because its add-on rows
		// persist. (2.3.48 — matches the use_global_event_source guard above.)
		if ( ! self::section_enabled_exists( $post_id, 'general_addons_enabled' )
			&& empty( $values['general_addons_enabled'] ) && ! empty( $values['general_addons'] ) && is_array( $values['general_addons'] ) ) {
			$values['general_addons_enabled'] = 1;
		}

		if ( empty( $values['rv_addons_enabled'] ) && ! empty( $values['rv_addons'] ) && is_array( $values['rv_addons'] ) ) {
			$values['rv_addons_enabled'] = 1;
		}


		if ( empty( $values['rv_addons'] ) || ! is_array( $values['rv_addons'] ) ) {
			$legacy_rv_addons = array();

			foreach ( $this->get_rv_addon_definitions() as $addon_key => $addon_label ) {
				$is_enabled   = (bool) get_post_meta( $post_id, '_en_rv_addon_' . $addon_key . '_enabled', true );
				$nightly_rate = $this->sanitize_money_value( get_post_meta( $post_id, '_en_rv_addon_' . $addon_key . '_nightly_rate', true ) );
				$weekend_rate = $this->sanitize_money_value( get_post_meta( $post_id, '_en_rv_addon_' . $addon_key . '_weekend_rate', true ) );

				if ( ! $is_enabled && '0.00' === $nightly_rate && '0.00' === $weekend_rate ) {
					continue;
				}

				$legacy_rv_addons[] = array(
					'name'         => $addon_label,
					'description'  => '',
					'nightly_rate' => $nightly_rate,
					'weekend_rate' => $weekend_rate,
				);
			}

			$values['rv_addons'] = $legacy_rv_addons;
		}

		if ( empty( $values['venue_map_enabled'] ) && ( ! empty( $values['venue_map_download_url'] ) || ! empty( $values['venue_map_image_id'] ) ) ) {
			$values['venue_map_enabled'] = 1;
		}

		// 2.3.48 — legacy inference only; an explicitly-stored 0 (toggle turned
		// OFF) must survive reload even though check-in/out times persist.
		if ( ! self::section_enabled_exists( $post_id, 'checkin_checkout_enabled' )
			&& empty( $values['checkin_checkout_enabled'] ) && ( ! empty( $values['checkin_time_enabled'] ) || ! empty( $values['checkout_time_enabled'] ) || ! empty( $values['checkin_time'] ) || ! empty( $values['checkout_time'] ) ) ) {
			$values['checkin_checkout_enabled'] = 1;
		}

		if ( empty( $values['stall_schedule_enabled'] ) && ( ! empty( $values['stalls_open_at'] ) || ! empty( $values['stalls_close_at'] ) ) ) {
			$values['stall_schedule_enabled'] = 1;
		}

		if ( empty( $values['rv_schedule_enabled'] ) && ( ! empty( $values['rv_open_at'] ) || ! empty( $values['rv_close_at'] ) ) ) {
			$values['rv_schedule_enabled'] = 1;
		}

		// Early Bird pricing requires a reservation schedule window — the cutoff
		// only makes sense inside an open→close window. Force it off when the
		// matching schedule toggle is off (mirrors the JS gate so the rule holds
		// even if the request bypasses the UI).
		if ( empty( $values['stall_schedule_enabled'] ) ) {
			$values['stall_early_bird_enabled'] = 0;
		}
		if ( empty( $values['rv_schedule_enabled'] ) ) {
			$values['rv_early_bird_enabled'] = 0;
		}

		// Early Bird cutoff must fall inside the open→close schedule window — clamp
		// it in (mirrors the JS min/max gate; guards against UI bypass / a window
		// edited narrower than an existing cutoff).
		foreach ( array(
			array( 'cut' => 'stall_early_bird_cutoff', 'open' => 'stalls_open_at', 'close' => 'stalls_close_at' ),
			array( 'cut' => 'rv_early_bird_cutoff',    'open' => 'rv_open_at',     'close' => 'rv_close_at' ),
		) as $eem_eb ) {
			$eem_cut = isset( $values[ $eem_eb['cut'] ] ) ? trim( (string) $values[ $eem_eb['cut'] ] ) : '';
			if ( '' === $eem_cut ) {
				continue;
			}
			$eem_open  = isset( $values[ $eem_eb['open'] ] ) ? trim( (string) $values[ $eem_eb['open'] ] ) : '';
			$eem_close = isset( $values[ $eem_eb['close'] ] ) ? trim( (string) $values[ $eem_eb['close'] ] ) : '';
			$eem_cut_ts = strtotime( $eem_cut );
			if ( '' !== $eem_open && $eem_cut_ts && strtotime( $eem_open ) > $eem_cut_ts ) {
				$values[ $eem_eb['cut'] ] = $eem_open;
				$eem_cut_ts               = strtotime( $eem_open );
			}
			if ( '' !== $eem_close && $eem_cut_ts && strtotime( $eem_close ) < $eem_cut_ts ) {
				$values[ $eem_eb['cut'] ] = $eem_close;
			}
		}

		// 2.3.48 — legacy inference only; an explicitly-stored 0 (toggle turned
		// OFF) must survive reload even though the uploaded agreement file_id
		// persists.
		if ( ! self::section_enabled_exists( $post_id, 'venue_agreement_enabled' )
			&& empty( $values['venue_agreement_enabled'] ) && ! empty( $values['venue_agreement_file_id'] ) ) {
			$values['venue_agreement_enabled'] = 1;
		}

		if ( empty( $values['convenience_fee_enabled'] ) && 'none' !== $values['convenience_fee_type'] ) {
			$values['convenience_fee_enabled'] = 1;
		}

		return $values;
	}

	/**
	 * Default reservation meta values.
	 *
	 * @return array
	 */
	/**
	 * Public accessor for the default meta values manifest.
	 *
	 * Used by EEM_Reservation_Config to expose the key manifest without
	 * duplicating it. Internal callers should continue using the private method.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_meta_values_public(): array {
		return $this->get_default_meta_values();
	}

	private function get_default_meta_values() {
		$defaults = array(
			'use_global_event_source'        => 1,
			'event_source'                    => EEM_Events::get_default_event_source(),
			'event_id'                        => 0,
			'event_feed_url'                  => '',
			'external_event_name'             => '',
			'external_event_id'               => '',
			'stalls_enabled'                  => 0,
			'stall_selection_mode'            => 'quantity',
			'stall_inventory_type'            => 'quantity_only',
			'stall_customer_selection'        => 'quantity',
			'rv_selection_mode'               => 'quantity',
			'rv_inventory_type'               => 'bulk',
			'rv_customer_selection'           => 'quantity',
			'rv_enabled'                      => 0,
			'nightly_enabled'                 => 1,
			'weekend_enabled'                 => 1,
			'weekly_enabled'                  => 0,
			'stall_nightly_enabled'           => 1,
			'stall_weekend_enabled'           => 1,
			'stall_weekly_enabled'            => 0,
			'rv_nightly_enabled'              => 1,
			'rv_weekend_enabled'              => 1,
			'rv_weekly_enabled'               => 0,
			'available_start_date'            => '',
			'available_end_date'              => '',
			'weekend_package_start_date'      => '',
			'weekend_package_end_date'        => '',
			'stall_weekend_package_start_date' => '',
			'stall_weekend_package_end_date'   => '',
			'rv_weekend_package_start_date'    => '',
			'rv_weekend_package_end_date'      => '',
			'stall_weekly_package_start_date'  => '',
			'stall_weekly_package_end_date'    => '',
			'rv_weekly_package_start_date'     => '',
			'rv_weekly_package_end_date'       => '',
			'available_dates_manually_edited' => 0,
			'sync_stay_selections'          => 0,
			'stall_description'               => '',
			'stall_schedule_enabled'         => 0,
			'stalls_open_at'                  => '',
			'stalls_close_at'                 => '',
			'stall_inventory'                 => '',
			'rv_description'                  => '',
			'rv_schedule_enabled'            => 0,
			'rv_open_at'                      => '',
			'rv_close_at'                     => '',
			'rv_inventory'                    => '',
			'stall_chart_stall_blocks'       => array(),
			'stall_chart_rv_blocks'          => array(),
			'stall_chart_blocked_stall_units'=> array(),
			'stall_chart_blocked_rv_units'   => array(),
			'stall_map_file_id'              => 0,
			'rv_lot_selection_enabled'        => 0,
			'rv_addons_enabled'               => 0,
			'rv_lots'                         => array(),
			'stall_nightly_rate'              => '0.00',
			'stall_weekend_rate'              => '0.00',
			'stall_weekly_rate'               => '0.00',
			'stall_early_bird_enabled'        => 0,
			'stall_early_bird_cutoff'         => '',
			'stall_early_bird_nightly_rate'   => '0.00',
			'stall_early_bird_weekend_rate'   => '0.00',
			'stall_early_bird_weekly_rate'    => '0.00',
			'required_shavings_enabled'       => 0,
			// v4 Stall Mapping — the imported facility-map snapshot (read-only here;
			// written by EEM_Stall_Map_Importer via AJAX, not the form save map).
			'stall_map'                       => array(),
			'rv_map'                          => array(),
			// Tack Stalls default OFF — admins opt in per reservation.
			'stall_tack_mode'                 => 'off',
			'required_shavings_per_stall'     => 0,
			'required_shavings_price'         => '0.00',
			'additional_shavings_enabled'     => 0,
			'additional_shavings_description' => '',
			'additional_shavings_price'       => '0.00',
			'reservation_description'         => '',
			'event_details_summary'           => '',
			'venue_name'                      => '',
			'event_location'                  => '',
			'venue_address'                   => '',
			'checkin_checkout_enabled'        => 1,
			'checkin_time_enabled'            => 1,
			'checkout_time_enabled'           => 1,
			'checkin_time'                    => '',
			'checkout_time'                   => '',
			'venue_map_enabled'              => 0,
			'venue_map_download_url'          => '',
			'venue_map_image_id'              => 0,
			'venue_map_caption'               => '',
			'venue_agreement_enabled'         => 0,
			'venue_agreement_file_id'         => 0,
			'venue_agreement_file_label'      => __( 'Agreement', 'equine-event-manager' ),
			'venue_agreement_label'           => __( 'I agree to the venue terms and conditions.', 'equine-event-manager' ),
			'venue_agreement_link_label'      => '', // C7.X.12 VV-4 — empty default; customer-facing falls back to "Venue Agreement".
			'venue_agreement_text'            => '',
			'additional_shavings_enabled'      => 0,
			'additional_shavings_products'     => array(),
			'general_addons_enabled'          => 0,
			'group_reservations_enabled'      => 0,
			// C7.C.1.4.A Decision N1 — NEW meta key defaults.
			'group_description'               => '',
			'group_riders_per_group'          => '', // 2.3.82: blank = unlimited.
			'group_rider_grounds_fee_enabled' => 0,
			'group_rider_grounds_fee_amount'  => '0.00',
			'group_rider_deposit_enabled'     => 0,
			'group_rider_deposit_amount'      => '0.00',
			'general_addons'                  => array(),
			'required_documents_enabled'      => 0,
			'required_documents_description'  => '',
			'required_documents'              => array(),
			'rv_lot_zones'                    => array(),
			// C7.X.5 + C7.X.6 — Event Day + Cancellation defaults.
			'event_day_enabled'               => 1,
			'event_day_checkin'               => '',
			'event_day_bring'                 => '',
			'event_day_parking'               => '',
			'event_day_contact'               => '',
			'cancellation_enabled'            => 1,
			'cancellation_policy_override'    => '',
			'rv_addons'                       => array(),
			'rv_nightly_rate'                 => '0.00',
			'rv_weekend_rate'                 => '0.00',
			'rv_weekly_rate'                  => '0.00',
			'rv_early_bird_enabled'           => 0,
			'rv_early_bird_cutoff'            => '',
			'rv_early_bird_nightly_rate'      => '0.00',
			'rv_early_bird_weekend_rate'      => '0.00',
			'rv_early_bird_weekly_rate'       => '0.00',
			'convenience_fee_label'           => __( 'Non-Refundable Convenience Fee', 'equine-event-manager' ),
			'convenience_fee_enabled'         => 0,
			'convenience_fee_type'            => 'none',
			'convenience_fee_value'           => '0.00',
			// C7.X — Per-customer purchase limits. Empty = unlimited (enforced at C10 checkout).
			'stall_max_per_customer'          => '',
			'rv_max_per_customer'             => '',
			// C8 — Event Pre-Entries section. Array empty = no entries yet (template shows seed).
			'event_pre_entries_enabled'       => 0,
			'event_pre_entries'               => array(),
			// C8 — Stall row-builder + blocked stalls + stall map (mapped-layout section).
			// Empty array = no saved rows yet; template falls through to seeded demo rows.
			'stall_rows'                      => array(),
			'blocked_stalls'                  => array(),
			'stall_map_id'                    => 0,
			// C8 — RV zone table, lot row-builder, blocked lots.
			// rv_lot_zone_assignments removed in V1 (2.3.22) — per-lot painting is V2 backlog.
			// See docs/c10-contracts.md for the V1 contract (zone assigned at row level).
			'rv_zones'                        => array(),
			'rv_rows'                         => array(),
			'blocked_rv_lots'                 => array(),
			'rv_lot_map_id'                   => 0,
		);

		return $defaults;
	}

	/**
	 * Get the configured stall selection mode for a reservation.
	 *
	 * @param array $data Reservation configuration values.
	 * @return string
	 */
	public function get_stall_selection_mode( $data ) {
		$mode = isset( $data['stall_selection_mode'] ) ? $this->sanitize_stall_selection_mode( $data['stall_selection_mode'] ) : 'quantity';

		return $mode;
	}

	/**
	 * Determine whether exact stall selection is enabled for a reservation.
	 *
	 * @param array $data Reservation configuration values.
	 * @return bool
	 */
	public function is_exact_stall_selection_enabled( $data ) {
		return 'exact_map' === $this->get_stall_selection_mode( $data );
	}

	/**
	 * Sanitize a stall selection mode value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_stall_selection_mode( $value ) {
		$mode = sanitize_key( $value );

		if ( ! in_array( $mode, array( 'quantity', 'exact_map' ), true ) ) {
			return 'quantity';
		}

		return $mode;
	}

	/**
	 * Scenario B (V1 #4): sanitize the Stall Inventory Type setting.
	 *
	 * @param mixed $value
	 * @return string 'quantity_only' | 'numbered'
	 */
	public static function sanitize_stall_inventory_type( $value ): string {
		$v = sanitize_key( $value );
		return in_array( $v, array( 'quantity_only', 'numbered' ), true ) ? $v : 'quantity_only';
	}

	/**
	 * Scenario B (V1 #4): sanitize the Customer Selection setting.
	 *
	 * @param mixed $value
	 * @return string 'quantity' | 'pick_layout'
	 */
	public static function sanitize_stall_customer_selection( $value ): string {
		$v = sanitize_key( $value );
		return in_array( $v, array( 'quantity', 'pick_layout' ), true ) ? $v : 'quantity';
	}

	/**
	 * Sanitize the Tack Stall mode.
	 *
	 * Three states: 'off' (no tack), 'customer' (on — buyers flag their own tack
	 * stall at checkout for the shavings exclusion), or 'admin' (on, but only the
	 * admin designates the tack stall on the Stall Chart; no checkout flag). Any
	 * unrecognised non-off value normalises to 'customer'.
	 *
	 * @param mixed $value
	 * @return string 'off' | 'customer' | 'admin'
	 */
	public static function sanitize_stall_tack_mode( $value ): string {
		$value = sanitize_key( $value );
		// Preserve any explicit valid mode; empty/unknown defaults to 'off'
		// (Tack Stalls are opt-in per reservation).
		return in_array( $value, array( 'off', 'admin', 'customer' ), true ) ? $value : 'off';
	}

	/**
	 * Scenario B (V1 #4): derive the legacy single-mode value from the new
	 * (inventory_type, customer_selection) pair. The legacy `exact_map` (the
	 * customer picks specific stalls) is true ONLY for numbered + pick_layout;
	 * every other combo behaves as `quantity` for the customer form. This keeps
	 * every existing reader of `_en_stall_selection_mode` working unchanged.
	 *
	 * @param string $inventory_type
	 * @param string $customer_selection
	 * @return string 'quantity' | 'exact_map'
	 */
	public static function derive_stall_selection_mode( string $inventory_type, string $customer_selection ): string {
		$inventory_type     = self::sanitize_stall_inventory_type( $inventory_type );
		$customer_selection = self::sanitize_stall_customer_selection( $customer_selection );
		return ( 'numbered' === $inventory_type && 'pick_layout' === $customer_selection ) ? 'exact_map' : 'quantity';
	}

	/**
	 * Scenario B (V1 #4): resolve a reservation's stall mode triple from post
	 * meta — the single source of truth shared by both meta readers (CPT
	 * get_meta_values + shortcodes get_reservation_meta).
	 *
	 * Precedence: the new keys win when present; otherwise the pair is derived
	 * from the legacy `_en_stall_selection_mode` (pre-migration reservations).
	 * Then `selection_mode` is re-derived from the (possibly enforced) pair so
	 * it's always internally consistent (quantity_only forces customer_selection
	 * to quantity).
	 *
	 * @param int $post_id
	 * @return array{inventory_type:string, customer_selection:string, selection_mode:string}
	 */
	public static function resolve_stall_pair( int $post_id ): array {
		// Direct get_post_meta — NOT EEM_Reservation_Config — because this
		// method is called from get_meta_values() during hydration; using the
		// repo here would create an infinite recursion loop.
		$legacy = (string) get_post_meta( $post_id, '_en_stall_selection_mode', true );
		$legacy = in_array( $legacy, array( 'quantity', 'exact_map' ), true ) ? $legacy : 'quantity';

		if ( metadata_exists( 'post', $post_id, '_en_stall_inventory_type' ) ) {
			$type = self::sanitize_stall_inventory_type( (string) get_post_meta( $post_id, '_en_stall_inventory_type', true ) );
		} else {
			$type = ( 'exact_map' === $legacy ) ? 'numbered' : 'quantity_only';
		}

		if ( metadata_exists( 'post', $post_id, '_en_stall_customer_selection' ) ) {
			$sel = self::sanitize_stall_customer_selection( (string) get_post_meta( $post_id, '_en_stall_customer_selection', true ) );
		} else {
			$sel = ( 'exact_map' === $legacy ) ? 'pick_layout' : 'quantity';
		}

		if ( 'quantity_only' === $type ) {
			$sel = 'quantity'; // Pick-from-layout is invalid without numbered stalls.
		}

		return array(
			'inventory_type'     => $type,
			'customer_selection' => $sel,
			'selection_mode'     => self::derive_stall_selection_mode( $type, $sel ),
		);
	}

	/**
	 * v4 RV two-control — sanitize the RV Inventory Type.
	 *
	 * @param mixed $value
	 * @return string 'bulk' | 'mapped'
	 */
	/**
	 * Update a reservation's post status.
	 *
	 * Abstraction boundary for wp_update_post — keeps controllers
	 * free of direct WP calls for future portability.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $status         Target post status (publish|draft|trash|pending).
	 * @return bool True on success.
	 */
	public static function update_status( int $reservation_id, string $status ): bool {
		$allowed = array( 'publish', 'draft', 'trash', 'pending' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'          => $reservation_id,
				'post_status' => $status,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	public static function sanitize_rv_inventory_type( $value ): string {
		$v = sanitize_key( $value );
		return in_array( $v, array( 'bulk', 'mapped' ), true ) ? $v : 'bulk';
	}

	/**
	 * v4 RV two-control — sanitize the RV Customer Selection.
	 *
	 * @param mixed $value
	 * @return string 'quantity' | 'pick_layout'
	 */
	public static function sanitize_rv_customer_selection( $value ): string {
		$v = sanitize_key( $value );
		return in_array( $v, array( 'quantity', 'pick_layout' ), true ) ? $v : 'quantity';
	}

	/**
	 * v4 RV two-control — derive the legacy `_en_rv_selection_mode` from the new
	 * (inventory_type, customer_selection) pair. `exact_map` (customer picks a
	 * specific lot on the map) is true ONLY for mapped + pick_layout; every other
	 * combo behaves as `quantity`. Mirrors derive_stall_selection_mode so every
	 * existing reader of `_en_rv_selection_mode` keeps working.
	 *
	 * @param string $inventory_type
	 * @param string $customer_selection
	 * @return string 'quantity' | 'exact_map'
	 */
	public static function derive_rv_selection_mode( string $inventory_type, string $customer_selection ): string {
		$inventory_type     = self::sanitize_rv_inventory_type( $inventory_type );
		$customer_selection = self::sanitize_rv_customer_selection( $customer_selection );
		return ( 'mapped' === $inventory_type && 'pick_layout' === $customer_selection ) ? 'exact_map' : 'quantity';
	}

	/**
	 * v4 RV two-control — resolve a reservation's RV mode triple from post meta.
	 *
	 * Precedence mirrors resolve_stall_pair: the new keys win when present;
	 * otherwise the pair is derived from the legacy `_en_rv_selection_mode`
	 * (`exact_map`→mapped+pick_layout, `quantity`→bulk+quantity). No migration
	 * needed. `bulk` forces customer_selection to `quantity`.
	 *
	 * @param int $post_id
	 * @return array{inventory_type:string, customer_selection:string, selection_mode:string}
	 */
	public static function resolve_rv_pair( int $post_id ): array {
		// Direct get_post_meta — NOT EEM_Reservation_Config — because this
		// method is called from get_meta_values() during hydration; using the
		// repo here would create an infinite recursion loop.
		$legacy = (string) get_post_meta( $post_id, '_en_rv_selection_mode', true );
		$legacy = in_array( $legacy, array( 'quantity', 'exact_map' ), true ) ? $legacy : 'quantity';

		if ( metadata_exists( 'post', $post_id, '_en_rv_inventory_type' ) ) {
			$type = self::sanitize_rv_inventory_type( (string) get_post_meta( $post_id, '_en_rv_inventory_type', true ) );
		} else {
			$type = ( 'exact_map' === $legacy ) ? 'mapped' : 'bulk';
		}

		if ( metadata_exists( 'post', $post_id, '_en_rv_customer_selection' ) ) {
			$sel = self::sanitize_rv_customer_selection( (string) get_post_meta( $post_id, '_en_rv_customer_selection', true ) );
		} else {
			$sel = ( 'exact_map' === $legacy ) ? 'pick_layout' : 'quantity';
		}

		if ( 'bulk' === $type ) {
			$sel = 'quantity'; // Pick-from-layout is invalid without mapped lots.
		}

		return array(
			'inventory_type'     => $type,
			'customer_selection' => $sel,
			'selection_mode'     => self::derive_rv_selection_mode( $type, $sel ),
		);
	}

	/**
	 * Build the transient key for a reservation validation notice.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return string
	 */
	private function get_validation_transient_key( $post_id ) {
		return self::VALIDATION_TRANSIENT_PREFIX . get_current_user_id() . '_' . absint( $post_id );
	}

	/**
	 * Store a validation notice for the current editor user.
	 *
	 * @param int      $post_id Reservation post ID.
	 * @param string[] $errors Validation messages.
	 * @return void
	 */
	private function store_validation_notice( $post_id, $errors ) {
		if ( ! $post_id || empty( $errors ) ) {
			return;
		}

		set_transient( $this->get_validation_transient_key( $post_id ), array_values( $errors ), MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Read a stored validation notice for the current editor user.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return string[]
	 */
	private function get_validation_notice( $post_id ) {
		$errors = get_transient( $this->get_validation_transient_key( $post_id ) );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Clear a stored validation notice for the current editor user.
	 *
	 * @param int $post_id Reservation post ID.
	 * @return void
	 */
	private function clear_validation_notice( $post_id ) {
		delete_transient( $this->get_validation_transient_key( $post_id ) );
	}

	/**
	 * Sanitize submitted RV lot rows.
	 *
	 * @param array $lots Raw RV lot rows.
	 * @return array
	 */
	private function sanitize_rv_lots( $lots ) {
		$sanitized = array();

		foreach ( (array) $lots as $lot ) {
			if ( ! is_array( $lot ) ) {
				continue;
			}

			$name = isset( $lot['name'] ) ? sanitize_text_field( $lot['name'] ) : '';
			$description = isset( $lot['description'] ) ? sanitize_text_field( $lot['description'] ) : '';
			$nightly_rate = isset( $lot['nightly_rate'] ) ? $this->sanitize_optional_money_value( $lot['nightly_rate'] ) : '';
			$weekend_rate = isset( $lot['weekend_rate'] ) ? $this->sanitize_optional_money_value( $lot['weekend_rate'] ) : '';
			$inventory = isset( $lot['inventory'] ) ? $this->sanitize_optional_inventory_value( $lot['inventory'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$sanitized[] = array(
				'name'         => $name,
				'description'  => $description,
				'nightly_rate' => $nightly_rate,
				'weekend_rate' => $weekend_rate,
				'inventory'    => $inventory,
			);
		}

		return array_values( $sanitized );
	}

	/**
	 * Normalize RV lot names into an ordered selectable list.
	 *
	 * @param array $lots Sanitized RV lot rows.
	 * @return array
	 */
	private function get_chart_rv_lot_names( $lots ) {
		$names = array();

		foreach ( (array) $lots as $lot ) {
			if ( ! is_array( $lot ) || empty( $lot['name'] ) ) {
				continue;
			}

			$names[] = sanitize_text_field( $lot['name'] );
		}

		$names = array_values( array_unique( array_filter( $names ) ) );
		sort( $names, SORT_NATURAL );

		return $names;
	}

	/**
	 * Sanitize submitted general add-on rows.
	 *
	 * @param array $addons Raw add-on rows.
	 * @return array
	 */
	/**
	 * Sanitize submitted Additional Shavings product rows.
	 *
	 * Each row: name (product type, e.g. "Large Flake") + price per bag.
	 * Rows with a blank name are dropped.
	 *
	 * @param array $products Raw product rows from form submission.
	 * @return array<int,array{name:string,price:string}>
	 */
	private function sanitize_additional_shavings_products( $products ): array {
		$sanitized = array();
		foreach ( (array) $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$name  = isset( $product['name'] ) ? sanitize_text_field( $product['name'] ) : '';
			$price = isset( $product['price'] ) ? $this->sanitize_money_value( $product['price'] ) : '0.00';
			if ( '' === $name ) {
				continue;
			}
			$sanitized[] = array(
				'name'  => $name,
				'price' => $price,
			);
		}
		return array_values( $sanitized );
	}

	private function sanitize_general_addons( $addons ) {
		$sanitized = array();

		foreach ( (array) $addons as $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$name        = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$description = isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '';
			$applies_to  = isset( $addon['applies_to'] ) ? sanitize_key( $addon['applies_to'] ) : 'any';
			$price       = isset( $addon['price'] ) ? $this->sanitize_money_value( $addon['price'] ) : '0.00';
			$per_label   = isset( $addon['per_label'] ) ? sanitize_text_field( $addon['per_label'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			if ( ! in_array( $applies_to, array( 'any', 'stall', 'rv' ), true ) ) {
				$applies_to = 'any';
			}

			$sanitized[] = array(
				'name'        => $name,
				'description' => $description,
				'applies_to'  => $applies_to,
				'price'       => $price,
				'per_label'   => $per_label,
			);
		}

		return array_values( $sanitized );
	}

	/**
	 * Sanitize submitted Required Documents rows. Each row: a name (admin-defined
	 * requirement, e.g. "Coggins") and a required-before-checkout flag. Rows with
	 * a blank name are dropped.
	 *
	 * @param array $docs Raw document rows.
	 * @return array<int,array{name:string,required:int}>
	 */
	private function sanitize_required_documents( $docs ) {
		$sanitized = array();
		foreach ( (array) $docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$name = isset( $doc['name'] ) ? sanitize_text_field( $doc['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$sanitized[] = array(
				'name'     => $name,
				'required' => ( isset( $doc['required'] ) && '1' === (string) $doc['required'] ) ? 1 : 0,
			);
		}
		return array_values( $sanitized );
	}

	/**
	 * Sanitize submitted RV add-on rows.
	 *
	 * @param array $addons Raw add-on rows.
	 * @return array
	 */
	private function sanitize_rv_addons( $addons ) {
		$sanitized = array();

		foreach ( (array) $addons as $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$name        = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$description = isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '';
			// 2.3.83 — `price` is the per-NIGHT add-on rate; `weekend_price` is the
			// flat add-on rate charged with a Weekend Rate stay. Legacy rows that
			// only carried `nightly_rate`/`weekend_rate` map onto the new keys.
			$price = isset( $addon['price'] ) ? $this->sanitize_money_value( $addon['price'] ) : '';
			if ( '' === $price ) {
				$price = isset( $addon['nightly_rate'] ) ? $this->sanitize_money_value( $addon['nightly_rate'] ) : '0.00';
			}
			$weekend_price = isset( $addon['weekend_price'] ) ? $this->sanitize_money_value( $addon['weekend_price'] ) : '';
			if ( '' === $weekend_price ) {
				$weekend_price = isset( $addon['weekend_rate'] ) ? $this->sanitize_money_value( $addon['weekend_rate'] ) : '0.00';
			}

			if ( '' === $name ) {
				continue;
			}

			// Per-zone availability (RV map mode). Empty = available for all zones;
			// otherwise the add-on is only offered when a picked lot is in one of
			// these zones.
			$zones = array();
			if ( isset( $addon['zones'] ) && is_array( $addon['zones'] ) ) {
				foreach ( $addon['zones'] as $z ) {
					$z = sanitize_text_field( (string) $z );
					if ( '' !== $z ) {
						$zones[] = $z;
					}
				}
				$zones = array_values( array_unique( $zones ) );
			}

			$sanitized[] = array(
				'name'          => $name,
				'description'   => $description,
				'price'         => '' !== $price ? $price : '0.00',
				'weekend_price' => '' !== $weekend_price ? $weekend_price : '0.00',
				'zones'         => $zones,
			);
		}

		return array_values( $sanitized );
	}

	/**
	 * Sanitize rv_lot_zones submission (C7.X.4 NEW). Each zone has
	 * a color slug from the 8-preset palette, a non-empty name, and
	 * a non-negative surcharge dollar amount.
	 *
	 * @param array $zones Raw zone rows.
	 * @return array
	 */
	private function sanitize_rv_lot_zones( $zones ) {
		$palette  = array( 'red', 'blue', 'green', 'orange', 'purple', 'navy', 'teal', 'pink' );
		$out      = array();
		foreach ( (array) $zones as $zone ) {
			if ( ! is_array( $zone ) ) {
				continue;
			}
			$name      = isset( $zone['name'] ) ? sanitize_text_field( $zone['name'] ) : '';
			$color     = isset( $zone['color'] ) ? sanitize_key( $zone['color'] ) : '';
			$surcharge = isset( $zone['surcharge'] ) ? $this->sanitize_money_value( $zone['surcharge'] ) : '0.00';
			if ( '' === $name ) {
				continue;
			}
			if ( ! in_array( $color, $palette, true ) ) {
				$color = 'blue';
			}
			$out[] = array(
				'name'      => $name,
				'color'     => $color,
				'surcharge' => $surcharge,
			);
		}
		return array_values( $out );
	}

	/**
	 * Get saved RV add-on definitions.
	 *
	 * @param array $data Reservation meta values.
	 * @param bool  $include_all Whether to include zero-price rows.
	 * @return array
	 */
	private function get_enabled_rv_addons( $data, $include_all = false ) {
		if ( ! $include_all && empty( $data['rv_addons_enabled'] ) ) {
			return array();
		}

		$addons  = isset( $data['rv_addons'] ) && is_array( $data['rv_addons'] ) ? $data['rv_addons'] : array();
		$results = array();

		foreach ( $addons as $index => $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$name        = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$description = isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '';
			// 2.3.83 — `price` is the per-night add-on rate; `weekend_price` the flat
			// add-on rate for a Weekend Rate stay. Legacy single-rate rows map across.
			$price = isset( $addon['price'] ) ? $this->sanitize_money_value( $addon['price'] ) : '';
			if ( '' === $price ) {
				$price = isset( $addon['nightly_rate'] ) ? $this->sanitize_money_value( $addon['nightly_rate'] ) : '0.00';
			}
			$weekend_price = isset( $addon['weekend_price'] ) ? $this->sanitize_money_value( $addon['weekend_price'] ) : '';
			if ( '' === $weekend_price ) {
				$weekend_price = isset( $addon['weekend_rate'] ) ? $this->sanitize_money_value( $addon['weekend_rate'] ) : '0.00';
			}

			if ( '' === $name ) {
				continue;
			}

			$results[ (string) $index ] = array(
				'name'          => $name,
				'description'   => $description,
				'price'         => '' !== $price ? $price : '0.00',
				'weekend_price' => '' !== $weekend_price ? $weekend_price : '0.00',
			);
		}

		return $results;
	}

	/**
	 * Get saved general add-on definitions.
	 *
	 * @param array $data Reservation meta values.
	 * @param bool  $include_all Whether to include zero-price rows.
	 * @return array
	 */
	private function get_enabled_general_addons( $data, $include_all = false ) {
		if ( ! $include_all && empty( $data['general_addons_enabled'] ) ) {
			return array();
		}

		$addons  = isset( $data['general_addons'] ) && is_array( $data['general_addons'] ) ? $data['general_addons'] : array();
		$results = array();

		foreach ( $addons as $index => $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$name        = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$description = isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '';
			$applies_to  = isset( $addon['applies_to'] ) ? sanitize_key( $addon['applies_to'] ) : 'any';
			$price       = isset( $addon['price'] ) ? $this->sanitize_money_value( $addon['price'] ) : '0.00';
			$per_label   = isset( $addon['per_label'] ) ? sanitize_text_field( $addon['per_label'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			if ( ! $include_all && '0.00' === $price ) {
				continue;
			}

			if ( ! in_array( $applies_to, array( 'any', 'stall', 'rv' ), true ) ) {
				$applies_to = 'any';
			}

			$results[ (string) $index ] = array(
				'name'        => $name,
				'description' => $description,
				'applies_to'  => $applies_to,
				'price'       => $price,
				'per_label'   => $per_label,
			);
		}

		return $results;
	}

	/**
	 * Get saved RV lot definitions.
	 *
	 * @param array $data Reservation meta values.
	 * @param bool  $include_all Whether to include rows even without prices.
	 * @return array
	 */
	private function get_enabled_rv_lots( $data, $include_all = false ) {
		$lots = isset( $data['rv_lots'] ) && is_array( $data['rv_lots'] ) ? $data['rv_lots'] : array();
		$results = array();

		foreach ( $lots as $index => $lot ) {
			if ( ! is_array( $lot ) ) {
				continue;
			}

			$name = isset( $lot['name'] ) ? sanitize_text_field( $lot['name'] ) : '';
			$description = isset( $lot['description'] ) ? sanitize_text_field( $lot['description'] ) : '';
			$nightly_rate = isset( $lot['nightly_rate'] ) ? $this->sanitize_optional_money_value( $lot['nightly_rate'] ) : '';
			$weekend_rate = isset( $lot['weekend_rate'] ) ? $this->sanitize_optional_money_value( $lot['weekend_rate'] ) : '';
			$inventory = isset( $lot['inventory'] ) ? $this->sanitize_optional_inventory_value( $lot['inventory'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$results[ (string) $index ] = array(
				'name'         => $name,
				'description'  => $description,
				'nightly_rate' => $nightly_rate,
				'weekend_rate' => $weekend_rate,
				'inventory'    => $inventory,
			);
		}

		return $results;
	}

	/**
	 * Get a single RV lot definition.
	 *
	 * @param array  $data Reservation meta values.
	 * @param string $lot_key Lot key.
	 * @return array|null
	 */
	private function get_rv_lot( $data, $lot_key ) {
		$lots = $this->get_enabled_rv_lots( $data, true );

		return isset( $lots[ (string) $lot_key ] ) ? $lots[ (string) $lot_key ] : null;
	}

	/**
	 * Get the active RV lot rate for a stay type.
	 *
	 * @param array  $data Reservation meta values.
	 * @param string $lot_key Lot key.
	 * @param string $stay_type Stay type.
	 * @return float
	 */
	private function get_rv_lot_rate( $data, $lot_key, $stay_type ) {
		$lot = $this->get_rv_lot( $data, $lot_key );

		if ( ! $lot ) {
			return $this->get_current_rate( $data, 'rv', $stay_type );
		}

		$base_rate     = $this->get_current_rate( $data, 'rv', $stay_type );
		$lot_surcharge = (float) ( 'weekend' === $stay_type ? $lot['weekend_rate'] : $lot['nightly_rate'] );

		return $base_rate + $lot_surcharge;
	}

	/**
	 * Get the fixed RV add-on definitions.
	 *
	 * @return array<string, string>
	 */
	private function get_rv_addon_definitions() {
		return array(
			'electric' => __( 'Electric', 'equine-event-manager' ),
			'water'    => __( 'Water', 'equine-event-manager' ),
			'sewage'   => __( 'Sewage', 'equine-event-manager' ),
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
	 * Get reservation event sources currently available on this site.
	 *
	 * @return array<string, string>
	 */
	private function get_available_reservation_event_sources() {
		$sources = array(
			'feed'     => __( 'Event Feed', 'equine-event-manager' ),
			'external' => __( 'External Event', 'equine-event-manager' ),
		);

		if ( EEM_Events::is_native_events_enabled() ) {
			$sources = array_merge(
				array(
					'native' => __( 'Equine Event Manager Event', 'equine-event-manager' ),
				),
				$sources
			);
		}

		if ( $this->is_tec_event_source_available() ) {
			$sources = array_merge(
				array(
					'tec' => __( 'The Events Calendar', 'equine-event-manager' ),
				),
				$sources
			);
		}

		return $sources;
	}

	/**
	 * Get the primary configured event source strategies for override decisions.
	 *
	 * @return array<int, string>
	 */
	private function get_configured_primary_event_sources() {
		$sources = array(
			$this->sanitize_reservation_event_source( EEM_Events::get_default_event_source() ) => true,
		);

		if ( EEM_Events::is_native_events_enabled() ) {
			$sources['native'] = true;
		}

		if ( $this->is_tec_event_source_available() ) {
			$sources['tec'] = true;
		}

		return array_keys( $sources );
	}

	/**
	 * Sanitize a reservation event source against currently available options.
	 *
	 * @param string $event_source Raw event source.
	 * @return string
	 */
	private function sanitize_reservation_event_source( $event_source ) {
		$event_source      = sanitize_key( (string) $event_source );
		$available_sources = array_keys( $this->get_available_reservation_event_sources() );

		if ( in_array( $event_source, $available_sources, true ) ) {
			return $event_source;
		}

		$default_event_source = sanitize_key( EEM_Events::get_default_event_source() );

		if ( in_array( $default_event_source, $available_sources, true ) ) {
			return $default_event_source;
		}

		return in_array( 'external', $available_sources, true ) ? 'external' : 'feed';
	}

	/**
	 * Resolve the effective event source for a reservation meta payload.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_effective_event_source( $data ) {
		if ( ! empty( $data['use_global_event_source'] ) ) {
			return $this->sanitize_reservation_event_source( EEM_Events::get_default_event_source() );
		}

		return $this->sanitize_reservation_event_source( isset( $data['event_source'] ) ? $data['event_source'] : '' );
	}

	/**
	 * Resolve the effective event source for a saved reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string
	 */
	private function get_effective_event_source_for_reservation( $reservation_id ) {
		$data = $this->get_meta_values( $reservation_id );

		return $this->get_effective_event_source( $data );
	}

	/**
	 * Check whether The Events Calendar can be used as an enabled reservation event source.
	 *
	 * @return bool
	 */
	private function is_tec_event_source_available() {
		return EEM_Events::is_tec_integration_enabled();
	}

	/**
	 * Search The Events Calendar events by title, ordered by event start date.
	 *
	 * @param string $term Search term.
	 * @return array
	 */
	private function search_the_events_calendar_events( $term = '', $exclude_reservation_id = 0 ) {
		if ( ! $this->is_tec_event_source_available() ) {
			return array();
		}

		$events = $this->query_tec_events_by_start_date( $term, 20 );

		if ( empty( $events ) ) {
			$fallback_args = array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => 20,
				's'              => $term,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);

			$category_slug = $this->get_tec_event_category_filter();
			if ( $category_slug ) {
				$fallback_args['tax_query'] = array(
					array(
						'taxonomy' => 'tribe_events_cat',
						'field'    => 'slug',
						'terms'    => $category_slug,
					),
				);
			}

			$events = ( new WP_Query( $fallback_args ) )->posts;
		}

		$results = array();

		foreach ( $events as $event ) {
			$event_id = absint( $event->ID );

			// One-to-one guard: hide events that already have an active
			// (non-trashed) reservation, except the one being edited. Keeps a
			// second reservation from being created against a taken event.
			if ( $this->get_active_linked_reservation_id_for_event( $event_id, absint( $exclude_reservation_id ) ) > 0 ) {
				continue;
			}

			$event_dates = $this->get_tec_event_date_values( $event_id );

			$results[] = array(
				'id'         => $event_id,
				'text'       => get_the_title( $event ),
				'start_date' => $event_dates['start_date'],
				'end_date'   => $event_dates['end_date'],
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
		$args = array(
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
		);

		$category_slug = $this->get_tec_event_category_filter();
		if ( $category_slug ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'tribe_events_cat',
					'field'    => 'slug',
					'terms'    => $category_slug,
				),
			);
		}

		return ( new WP_Query( $args ) )->posts;
	}

	/**
	 * Get TEC event start and end dates formatted for date inputs.
	 *
	 * @param int $event_id Event post ID.
	 * @return array
	 */
	private function get_tec_event_date_values( $event_id ) {
		$start = get_post_meta( $event_id, '_EventStartDate', true );
		$end   = get_post_meta( $event_id, '_EventEndDate', true );

		return array(
			'start_date' => $this->format_date_for_input( $start ),
			'end_date'   => $this->format_date_for_input( $end ? $end : $start ),
		);
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

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$title = get_the_title( absint( $data['event_id'] ) );

			if ( $title ) {
				return $title;
			}
		}

		if ( ! empty( $data['external_event_name'] ) ) {
			return $data['external_event_name'];
		}

		return __( 'Unassigned Event', 'equine-event-manager' );
	}

	/**
	 * Get a readable reservation type list.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_type_label( $data ) {
		$types = array();
		$general_addons_enabled = ! empty( $data['general_addons'] ) && is_array( $data['general_addons'] );

		if ( ! empty( $data['stalls_enabled'] ) ) {
			$types[] = __( 'Stall', 'equine-event-manager' );
		}

		if ( ! empty( $data['rv_enabled'] ) ) {
			$types[] = __( 'RV', 'equine-event-manager' );
		}

		if ( ! empty( $data['required_shavings_enabled'] ) || $general_addons_enabled ) {
			$types[] = __( 'Add-On', 'equine-event-manager' );
		}

		return ! empty( $types ) ? implode( ', ', $types ) : __( 'None', 'equine-event-manager' );
	}

	/**
	 * Render Shopify-style type badges from a comma-separated label string.
	 *
	 * @param string $type_label Comma-separated type label string.
	 * @return string
	 */
	private function render_type_badges( $type_label ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $type_label ) ) );

		if ( empty( $parts ) ) {
			return '';
		}

		$badges = array();
		$styles = array(
			'stall'   => 'background:#eaf1ff !important;border-color:#bad0ff !important;color:#2453a6 !important;',
			'rv'      => 'background:#ecf9ef !important;border-color:#bbe5c8 !important;color:#247548 !important;',
			'addon'   => 'background:#fff7dc !important;border-color:#efd58a !important;color:#9b6a12 !important;',
			'group'   => 'background:#f4ecff !important;border-color:#d7c2ff !important;color:#6c41b7 !important;',
			'default' => '',
		);

		foreach ( $parts as $part ) {
			$label = sanitize_text_field( $part );
			$key   = sanitize_title( $label );

			if ( false !== strpos( $key, 'add-on' ) || false !== strpos( $key, 'addon' ) ) {
				$key   = 'addon';
				$label = __( 'Add-On', 'equine-event-manager' );
			} elseif ( false !== strpos( $key, 'stall' ) ) {
				$key   = 'stall';
				$label = __( 'Stall', 'equine-event-manager' );
			} elseif ( 'rv' === $key || false !== strpos( $key, 'rv-' ) || false !== strpos( $key, '-rv' ) ) {
				$key   = 'rv';
				$label = __( 'RV', 'equine-event-manager' );
			} elseif ( false !== strpos( $key, 'group' ) ) {
				$key   = 'group';
				$label = __( 'Group', 'equine-event-manager' );
			} else {
				$key = 'default';
			}

			$badges[] = sprintf(
				'<span class="eem-shell-badge eem-shell-badge--%1$s"%3$s>%2$s</span>',
				esc_attr( $key ),
				esc_html( $label ),
				! empty( $styles[ $key ] ) ? ' style="' . esc_attr( $styles[ $key ] ) . '"' : ''
			);
		}

		return sprintf(
			'<span class="eem-shell-badges">%s</span>',
			implode( '', $badges )
		);
	}

	/**
	 * Get readable event dates for the reservation list.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_event_dates_label( $data ) {
		$reservation_date_range = $this->get_reservation_date_range_label( $data );

		if ( __( 'Dates unavailable', 'equine-event-manager' ) !== $reservation_date_range ) {
			return $reservation_date_range;
		}

		if ( 'tec' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_dates = $this->get_tec_event_date_values( absint( $data['event_id'] ) );

			if ( ! empty( $event_dates['start_date'] ) ) {
				if ( empty( $event_dates['end_date'] ) || $event_dates['start_date'] === $event_dates['end_date'] ) {
					return $this->format_date_label( $event_dates['start_date'] );
				}

				return sprintf(
					/* translators: 1: start date, 2: end date. */
					__( '%1$s - %2$s', 'equine-event-manager' ),
					$this->format_date_label( $event_dates['start_date'] ),
					$this->format_date_label( $event_dates['end_date'] )
				);
			}
		}

		if ( 'native' === $data['event_source'] && ! empty( $data['event_id'] ) ) {
			$event_dates = EEM_Events::get_native_event_date_values( absint( $data['event_id'] ) );

			if ( ! empty( $event_dates['start_date'] ) ) {
				if ( empty( $event_dates['end_date'] ) || $event_dates['start_date'] === $event_dates['end_date'] ) {
					return $this->format_date_label( $event_dates['start_date'] );
				}

				return sprintf(
					/* translators: 1: start date, 2: end date. */
					__( '%1$s - %2$s', 'equine-event-manager' ),
					$this->format_date_label( $event_dates['start_date'] ),
					$this->format_date_label( $event_dates['end_date'] )
				);
			}
		}

		return $reservation_date_range;
	}

	/**
	 * Get readable reservation date range for the reservation list.
	 *
	 * @param array $data Reservation meta values.
	 * @return string
	 */
	private function get_reservation_date_range_label( $data ) {
		if ( ! empty( $data['available_start_date'] ) ) {
			if ( empty( $data['available_end_date'] ) || $data['available_start_date'] === $data['available_end_date'] ) {
				return $this->format_date_label( $data['available_start_date'] );
			}

			return sprintf(
				/* translators: 1: start date, 2: end date. */
				__( '%1$s - %2$s', 'equine-event-manager' ),
				$this->format_date_label( $data['available_start_date'] ),
				$this->format_date_label( $data['available_end_date'] )
			);
		}

		if ( 'external' === $data['event_source'] ) {
			return __( 'Not linked', 'equine-event-manager' );
		}

		return __( 'Dates unavailable', 'equine-event-manager' );
	}

	/**
	 * Get readable event source label.
	 *
	 * @param string $event_source Event source key.
	 * @return string
	 */
	private function get_event_source_label( $event_source ) {
		return EEM_Events::get_event_source_label( $event_source );
	}

	/**
	 * Sanitize an optional inventory value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_optional_inventory_value( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		return (string) absint( $value );
	}

	/**
	 * Sanitize stall chart block definitions.
	 *
	 * @param array $blocks Raw submitted blocks.
	 * @return array
	 */
	private function sanitize_chart_blocks( $blocks ) {
		$sanitized = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$label = isset( $block['label'] ) ? sanitize_text_field( $block['label'] ) : '';
			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( '' === $label && 0 === $start && 0 === $end ) {
				continue;
			}

			if ( $start && $end && $start > $end ) {
				$temp  = $start;
				$start = $end;
				$end   = $temp;
			}

			if ( '' === $label || ! $start || ! $end ) {
				continue;
			}

			$sanitized[] = array(
				'label' => $label,
				'start' => $start,
				'end'   => $end,
			);
		}

		return array_values( $sanitized );
	}

	/**
	 * Expand chart blocks into a flat list of unit numbers.
	 *
	 * @param array $blocks Chart block definitions.
	 * @return array
	 */
	private function expand_chart_units( $blocks ) {
		$units = array();

		foreach ( (array) $blocks as $block ) {
			$start = isset( $block['start'] ) ? absint( $block['start'] ) : 0;
			$end   = isset( $block['end'] ) ? absint( $block['end'] ) : 0;

			if ( ! $start || ! $end ) {
				continue;
			}

			for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
				$units[] = (string) $number;
			}
		}

		return array_values( array_unique( $units ) );
	}

	/**
	 * Sanitize a selected unit list against the configured pool.
	 *
	 * @param array $values Submitted unit values.
	 * @param array $allowed Allowed unit pool.
	 * @return array
	 */
	private function sanitize_chart_unit_list( $values, $allowed ) {
		$expanded_values = array();

		if ( is_string( $values ) ) {
			$tokens = preg_split( '/[\s,]+/', $values );
		} else {
			$tokens = (array) $values;
		}

		foreach ( array_filter( array_map( 'trim', (array) $tokens ) ) as $token ) {
			$token = sanitize_text_field( $token );

			if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $token, $matches ) ) {
				$start = absint( $matches[1] );
				$end   = absint( $matches[2] );

				if ( $start && $end ) {
					for ( $number = min( $start, $end ); $number <= max( $start, $end ); $number++ ) {
						$expanded_values[] = (string) $number;
					}
				}

				continue;
			}

			if ( preg_match( '/^\d+$/', $token ) ) {
				$expanded_values[] = (string) absint( $token );
			}
		}

		return array_values( array_intersect( array_unique( $expanded_values ), (array) $allowed ) );
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
	 * Sanitize the venue-agreement attachment id, enforcing that it points at a
	 * real PDF.
	 *
	 * The Media Library picker filters to PDFs on the CLIENT side only
	 * (assets/js/admin.js `library: { type: 'application/pdf' }`), which is
	 * trivially bypassable via dev tools or a crafted POST. This is the
	 * authoritative server-side gate: a non-PDF (or non-existent) attachment id
	 * is dropped to 0 so a malicious or mistaken upload can never be persisted
	 * and later served to customers as the "agreement" document.
	 *
	 * @param mixed $raw Submitted attachment id.
	 * @return int Validated PDF attachment id, or 0.
	 */
	private function sanitize_agreement_file_id( $raw ): int {
		$file_id = absint( $raw );

		if ( $file_id <= 0 ) {
			return 0;
		}

		return ( 'application/pdf' === get_post_mime_type( $file_id ) ) ? $file_id : 0;
	}

	/**
	 * Sanitize a time-of-day value to a clean 24-hour `H:i` string.
	 *
	 * Accepts a bare `HH:MM` (the value an `<input type="time">` submits) or any
	 * parseable time/datetime string, and stores only the time component — the
	 * date is irrelevant for check-in / check-out times. Empty / unparseable
	 * input returns an empty string.
	 *
	 * @param string $value Raw value.
	 * @return string `H:i` (e.g. "11:00") or ''.
	 */
	private function sanitize_time_value( $value ): string {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Bare HH:MM (24-hour) from <input type="time">.
		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? '' : date( 'H:i', $timestamp );
	}

	/**
	 * Sanitize a date value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_date_value( $value ) {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return date( 'Y-m-d', $timestamp );
	}

	/**
	 * Format saved datetime for datetime-local inputs.
	 *
	 * @param string $value Saved datetime or legacy time value.
	 * @param string $fallback_value Optional fallback date for legacy time-only values.
	 * @return string
	 */
	private function format_datetime_for_input( $value, $fallback_value = '' ) {
		if ( ! $value ) {
			return '';
		}

		$raw_value = trim( (string) $value );
		$timestamp = strtotime( $raw_value );

		if ( false !== $timestamp && preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw_value ) ) {
			return date( 'Y-m-d\TH:i', $timestamp );
		}

		if ( '' !== $fallback_value ) {
			$fallback_timestamp = strtotime( (string) $fallback_value );

			if ( false !== $fallback_timestamp && false !== $timestamp ) {
				return date( 'Y-m-d', $fallback_timestamp ) . 'T' . date( 'H:i', $timestamp );
			}
		}

		if ( false !== $timestamp && ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw_value ) ) {
			return '';
		}

		if ( false === $timestamp ) {
			return '';
		}

		return date( 'Y-m-d\TH:i', $timestamp );
	}

	/**
	 * Format a saved date/datetime for date inputs.
	 *
	 * @param string $value Saved date or datetime.
	 * @return string
	 */
	private function format_date_for_input( $value ) {
		if ( ! $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $value, $matches ) ) {
			return $matches[0];
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return date( 'Y-m-d', $timestamp );
	}

	/**
	 * Format a saved date value for list-table display.
	 *
	 * @param string $value Saved date value.
	 * @return string
	 */
	private function format_date_label( $value ) {
		$value = $this->format_date_for_input( $value );

		if ( '' === $value ) {
			return '';
		}

		$timezone = wp_timezone();
		$date     = date_create_immutable_from_format( '!Y-m-d', $value, $timezone );

		if ( ! $date ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ), $date->getTimestamp(), $timezone );
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
	 * Sanitize an optional money value while preserving blank input.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_optional_money_value( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( $value ) : '';
		$value = preg_replace( '/[^0-9.]/', '', $value );

		if ( '' === $value ) {
			return '';
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
		$updated = update_post_meta( $event_id, 'reservations', $value );
		$this->debug_log( 'update_post_meta ran for reservations field. result=' . ( false === $updated ? 'false' : 'true' ) );
	}

	/**
	 * Check whether the event reservations field currently contains this shortcode.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $shortcode Reservation shortcode.
	 * @return bool
	 */
	private function event_reservations_field_matches_shortcode( $event_id, $shortcode ) {
		return $shortcode === get_post_meta( $event_id, 'reservations', true );
	}

	/**
	 * Reverse lookup: find the TEC event post linked to a given reservation.
	 *
	 * The TEC event is the single source of truth. This queries tribe_events
	 * posts whose _equine_event_manager_reservation_id meta equals $reservation_id.
	 * Public so the editor page can read it without instantiating internal state.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return int TEC event post ID, or 0 if none found.
	 */
	public function get_tec_event_id_for_reservation( $reservation_id ) {
		$events = get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft', 'private' ),
				'posts_per_page' => 1,
				'meta_key'       => '_equine_event_manager_reservation_id',
				'meta_value'     => absint( $reservation_id ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return ! empty( $events ) ? absint( $events[0] ) : 0;
	}

	/**
	 * Resolve the reservation linked to a TEC event (inverse of
	 * get_tec_event_id_for_reservation()).
	 *
	 * The TEC event is the single source of truth: it stores the linked
	 * reservation id in its _equine_event_manager_reservation_id meta. We read
	 * that directly and confirm the target is a real en_reservation post.
	 *
	 * @param int $event_id TEC event post ID.
	 * @return int Reservation post ID, or 0 if none linked / link is stale.
	 */
	public function get_reservation_id_for_tec_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return 0;
		}
		$reservation_id = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );
		if ( ! $reservation_id ) {
			return 0;
		}
		return self::POST_TYPE === get_post_type( $reservation_id ) ? $reservation_id : 0;
	}

	/**
	 * Get the TEC event category slug filter from integration settings.
	 *
	 * @return string Slug string, or '' if no filter configured.
	 */
	private function get_tec_event_category_filter() {
		$settings = EEM_Events::get_integration_settings();
		return isset( $settings['tec_event_category'] ) ? sanitize_key( $settings['tec_event_category'] ) : '';
	}

	/**
	 * Enforce one-to-one bidirectional TEC event link after reservation save.
	 *
	 * When a reservation is linked to a new TEC event:
	 *   1. The previously linked event loses its reservation link.
	 *   2. Any reservation previously linked to the new event loses its event link.
	 *
	 * The actual write to the new event's meta is handled by
	 * sync_shortcode_to_linked_event_after_save() (priority 20).
	 *
	 * @param int $reservation_id     Reservation post ID being saved.
	 * @param int $old_tec_event_id   TEC event ID that was linked before save (0 if none).
	 * @param int $new_tec_event_id   TEC event ID chosen in the editor (0 to unlink).
	 */
	private function enforce_tec_event_link_one_to_one( $reservation_id, $old_tec_event_id, $new_tec_event_id ) {
		if ( ! $this->is_tec_event_source_available() ) {
			return;
		}

		// Clear old event's link if the event changed.
		if ( $old_tec_event_id && $old_tec_event_id !== $new_tec_event_id ) {
			$old_linked = absint( get_post_meta( $old_tec_event_id, '_equine_event_manager_reservation_id', true ) );
			if ( $old_linked === absint( $reservation_id ) ) {
				delete_post_meta( $old_tec_event_id, '_equine_event_manager_reservation_id' );
				$this->update_event_reservations_field( $old_tec_event_id, '' );
			}
		}

		// If new event was previously linked to a different reservation, clear that reservation's event_id.
		if ( $new_tec_event_id ) {
			$displaced_reservation_id = absint( get_post_meta( $new_tec_event_id, '_equine_event_manager_reservation_id', true ) );
			if ( $displaced_reservation_id && $displaced_reservation_id !== absint( $reservation_id ) ) {
				EEM_Reservation_Config::for( $displaced_reservation_id )
					->set( 'event_id', 0 )
					->save();
				EEM_Reservation_Config::flush_cache( $displaced_reservation_id );
			}

			// 2.3.79 — Write the event's reverse link to THIS reservation immediately.
			// The event-source resolver (EEM_Events::get_normalized_reservation_event_data)
			// resolves the linked event through this reverse meta key, not the forward
			// _en_event_id. The save_post-hook writer (sync_shortcode_to_linked_event_after_save,
			// priority 20) only fires when wp_update_post runs — which it does NOT on a
			// no-status-change draft save (e.g. linking an event from the editor gate).
			// Writing it here keeps the reverse link in sync so apply_mirror() can resolve
			// the event title on the very first link, even when the prior linked reservation
			// was trashed and left a stale reverse pointer.
			$this->set_linked_reservation_for_event( $new_tec_event_id, $reservation_id );
		}
	}

	/**
	 * Get the reservation currently linked to a TEC event.
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	private function get_linked_reservation_id_for_event( $event_id ) {
		$reservation_id = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );

		if ( $reservation_id ) {
			return $reservation_id;
		}

		$shortcode = (string) get_post_meta( $event_id, 'reservations', true );

		if ( preg_match( '/\[en_reservation\s+id="(\d+)"\]/', $shortcode, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Get the ACTIVE (non-trashed, still-existing) reservation linked to a TEC
	 * event, ignoring a reservation we want to exclude (the one being edited).
	 *
	 * One event maps to at most one reservation. A stale reverse pointer to a
	 * trashed or deleted reservation does NOT count — that event is free to
	 * reuse. Used by both the event-picker filter (hide taken events) and the
	 * save-time duplicate guard (block double-booking).
	 *
	 * @param int $event_id               TEC event post ID.
	 * @param int $exclude_reservation_id Reservation to ignore (0 = none).
	 * @return int Active linked reservation ID, or 0 when the event is free.
	 */
	public function get_active_linked_reservation_id_for_event( int $event_id, int $exclude_reservation_id = 0 ): int {
		$reservation_id = $this->get_linked_reservation_id_for_event( $event_id );

		if ( ! $reservation_id || $reservation_id === absint( $exclude_reservation_id ) ) {
			return 0;
		}

		$status = get_post_status( $reservation_id );

		// false = post no longer exists; 'trash' = soft-deleted. Both free the event.
		if ( false === $status || 'trash' === $status ) {
			return 0;
		}

		return $reservation_id;
	}

	/**
	 * Persist the linked reservation on a TEC event using native post meta.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $reservation_id Reservation post ID.
	 */
	private function set_linked_reservation_for_event( $event_id, $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		$post_type      = get_post_type( $event_id );

		if ( $reservation_id && self::POST_TYPE === get_post_type( $reservation_id ) ) {
			update_post_meta( $event_id, '_equine_event_manager_reservation_id', $reservation_id );

			if ( 'tribe_events' === $post_type ) {
				$this->update_event_reservations_field( $event_id, $this->get_reservation_shortcode( $reservation_id ) );
			}
			return;
		}

		delete_post_meta( $event_id, '_equine_event_manager_reservation_id' );

		if ( 'tribe_events' === $post_type ) {
			$this->update_event_reservations_field( $event_id, '' );
		}
	}

	/**
	 * Temporary reservation sync debug logging.
	 *
	 * @param string $message Log message.
	 */
	private function debug_log( $message ) {
		// Informational sync/cleanup tracing — fires on every reservation save.
		// Gate behind WP_DEBUG so production (WP_DEBUG off) stays log-quiet; the
		// genuine error-condition logs (PDF/Authorize.net failures) remain ungated
		// on purpose so ops keep a failure trail regardless of WP_DEBUG.
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		error_log( '[Equine Event Manager Reservations] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
	 * Populate available dates from the linked event until an admin edits them.
	 *
	 * Supports all three event sources: TEC and native events link by numeric
	 * event_id; the feed source (GEMS) links by external_event_id and resolves
	 * its dates from the GEMS schedule. The Available Reservation Dates always
	 * default to the linked event's start/end date until the admin manually
	 * overrides them.
	 *
	 * @param array $data Reservation meta data.
	 * @return array
	 */
	private function populate_available_dates_from_event( $data ) {
		if ( ! empty( $data['available_dates_manually_edited'] ) ) {
			return $data;
		}

		if ( 'tec' === $data['event_source'] ) {
			if ( empty( $data['event_id'] ) ) {
				return $data;
			}
			$event_dates = $this->get_tec_event_date_values( absint( $data['event_id'] ) );
		} elseif ( 'native' === $data['event_source'] ) {
			if ( empty( $data['event_id'] ) ) {
				return $data;
			}
			$event_dates = EEM_Events::get_native_event_date_values( absint( $data['event_id'] ) );
		} elseif ( 'feed' === $data['event_source'] ) {
			if ( empty( $data['external_event_id'] ) ) {
				return $data;
			}
			$feed_url    = isset( $data['event_feed_url'] ) ? (string) $data['event_feed_url'] : '';
			$feed_event  = EEM_Events::get_feed_event_by_external_id( (string) $data['external_event_id'], $feed_url );
			$event_dates = is_array( $feed_event )
				? array(
					'start_date' => isset( $feed_event['start_date'] ) ? (string) $feed_event['start_date'] : '',
					'end_date'   => isset( $feed_event['end_date'] ) ? (string) $feed_event['end_date'] : '',
				)
				: array();
		} else {
			return $data;
		}

		if ( empty( $data['available_start_date'] ) && ! empty( $event_dates['start_date'] ) ) {
			$data['available_start_date'] = $event_dates['start_date'];
		}

		if ( empty( $data['available_end_date'] ) && ! empty( $event_dates['end_date'] ) ) {
			$data['available_end_date'] = $event_dates['end_date'];
		}

		return $data;
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

	/**
	 * Clear an invalid available end date when it is before the start date.
	 *
	 * @param array  $data Data values.
	 * @param string $start_key Start date key.
	 * @param string $end_key End date key.
	 * @return array
	 */
	private function normalize_date_only_range( $data, $start_key, $end_key ) {
		if ( ! empty( $data[ $start_key ] ) && ! empty( $data[ $end_key ] ) && strtotime( $data[ $end_key ] ) < strtotime( $data[ $start_key ] ) ) {
			$data[ $end_key ] = '';
		}

		return $data;
	}

	/**
	 * Ensure the weekend package range stays inside the overall reservation date range.
	 *
	 * @param array $data Reservation meta values.
	 * @return array
	 */
	private function normalize_weekend_package_range( $data, $prefix = '' ) {
		$enabled_key = $prefix ? $prefix . '_weekend_enabled' : 'weekend_enabled';
		$start_key   = $prefix ? $prefix . '_weekend_package_start_date' : 'weekend_package_start_date';
		$end_key     = $prefix ? $prefix . '_weekend_package_end_date' : 'weekend_package_end_date';

		if ( empty( $data['available_start_date'] ) || empty( $data['available_end_date'] ) ) {
			return $data;
		}

		if ( empty( $data[ $enabled_key ] ) ) {
			$data[ $start_key ] = '';
			$data[ $end_key ]   = '';
			return $data;
		}

		if ( empty( $data[ $start_key ] ) ) {
			$data[ $start_key ] = $data['available_start_date'];
		}

		if ( empty( $data[ $end_key ] ) ) {
			$data[ $end_key ] = $data['available_end_date'];
		}

		if ( $data[ $start_key ] < $data['available_start_date'] ) {
			$data[ $start_key ] = $data['available_start_date'];
		}

		if ( $data[ $end_key ] > $data['available_end_date'] ) {
			$data[ $end_key ] = $data['available_end_date'];
		}

		if ( $data[ $end_key ] < $data[ $start_key ] ) {
			$data[ $end_key ] = $data[ $start_key ];
		}

		return $data;
	}

	/**
	 * Clamp/default weekly package dates to the reservation's available window.
	 *
	 * Parallel to normalize_weekend_package_range — operates on `{prefix}_weekly_*` keys.
	 *
	 * @param array  $data   Candidate meta array.
	 * @param string $prefix 'stall' or 'rv'.
	 * @return array Updated meta array.
	 */
	private function normalize_weekly_package_range( array $data, string $prefix = '' ): array {
		$enabled_key = $prefix ? $prefix . '_weekly_enabled' : 'weekly_enabled';
		$start_key   = $prefix ? $prefix . '_weekly_package_start_date' : 'weekly_package_start_date';
		$end_key     = $prefix ? $prefix . '_weekly_package_end_date' : 'weekly_package_end_date';

		if ( empty( $data['available_start_date'] ) || empty( $data['available_end_date'] ) ) {
			return $data;
		}

		if ( empty( $data[ $enabled_key ] ) ) {
			$data[ $start_key ] = '';
			$data[ $end_key ]   = '';
			return $data;
		}

		if ( empty( $data[ $start_key ] ) ) {
			$data[ $start_key ] = $data['available_start_date'];
		}

		if ( empty( $data[ $end_key ] ) ) {
			$data[ $end_key ] = $data['available_end_date'];
		}

		if ( $data[ $start_key ] < $data['available_start_date'] ) {
			$data[ $start_key ] = $data['available_start_date'];
		}

		if ( $data[ $end_key ] > $data['available_end_date'] ) {
			$data[ $end_key ] = $data['available_end_date'];
		}

		if ( $data[ $end_key ] < $data[ $start_key ] ) {
			$data[ $end_key ] = $data[ $start_key ];
		}

		return $data;
	}
}
