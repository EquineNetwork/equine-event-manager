<?php
/**
 * Branded Add/Edit Event editor (Native Events Admin E).
 *
 * Replaces the default WordPress `en_event` post editor (meta box) with a
 * card-based editor modeled on the Edit Reservation layout, per
 * `.mockups/add_event_page.html`: a 2-column edit body (icon section cards on
 * the left, a sticky Publish / Categories / Featured Image / completeness rail
 * on the right) plus a mobile sticky save bar. Reuses the reservation editor's
 * section-card chrome (`eem_render_reservation_editor_section()`) + the existing
 * collapse/enable toggle JS.
 *
 * Saving persists to the SAME meta keys the native event meta box + frontend
 * use (`_equine_event_manager_event_*`, `_en_event_facebook/instagram`), the
 * post title/content/excerpt/status, the en_event_category terms, the featured
 * image, and (optionally) links a reservation by pointing its `_en_event_id` at
 * this event.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event editor page controller.
 */
class EEM_Event_Editor_Page {

	/**
	 * Admin menu slug (hidden submenu).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-event-editor';

	/**
	 * Post type edited here.
	 *
	 * @var string
	 */
	const POST_TYPE = 'en_event';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE = 'eem_event_editor';

	/**
	 * Wire the AJAX save handler + the redirects that bounce the raw WP editor.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_event_editor_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'maybe_redirect_new_event' ) );
		add_action( 'load-post.php', array( __CLASS__, 'maybe_redirect_legacy_edit' ) );
	}

	/**
	 * Build the editor URL for an event id.
	 *
	 * @param int $event_id Event post id.
	 * @return string
	 */
	public static function url( int $event_id ): string {
		return add_query_arg(
			array( 'page' => self::MENU_SLUG, 'event_id' => $event_id ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * On `post-new.php?post_type=en_event`, create a draft event and redirect to
	 * the branded editor (mirrors the Reservation editor's new-post flow). No-op
	 * when native events are disabled.
	 *
	 * @return void
	 */
	public static function maybe_redirect_new_event(): void {
		if ( wp_doing_ajax() || ! EEM_Events::is_native_events_enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::POST_TYPE !== $post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// An all-empty draft (no title/content/excerpt) would be rejected by WP's
		// wp_insert_post_empty_content check (returns 0). Bypass it so the editor
		// opens on a genuinely blank new event.
		add_filter( 'wp_insert_post_empty_content', '__return_false' );
		$new_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'draft',
			'post_title'  => '',
		) );
		remove_filter( 'wp_insert_post_empty_content', '__return_false' );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return;
		}
		wp_safe_redirect( self::url( (int) $new_id ), 302 );
		exit;
	}

	/**
	 * On `post.php?post=N&action=edit` for an en_event, redirect to the branded
	 * editor. No-op when native events are disabled.
	 *
	 * @return void
	 */
	public static function maybe_redirect_legacy_edit(): void {
		if ( wp_doing_ajax() || ! EEM_Events::is_native_events_enabled() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		if ( empty( $_GET['post'] ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( '' !== $action && 'edit' !== $action ) {
			return;
		}
		$post_id = absint( wp_unslash( $_GET['post'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $post_id <= 0 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		wp_safe_redirect( self::url( $post_id ), 302 );
		exit;
	}

	/**
	 * Render the editor page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param.
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$post     = $event_id > 0 ? get_post( $event_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			echo '<div class="eem-page"><div class="eem-plugin-wrap"><div class="eem-edit-body"><p>' . esc_html__( 'That event could not be found.', 'equine-event-manager' ) . '</p><p><a class="eem-btn eem-btn-secondary" href="' . esc_url( EEM_Events_List_Page::url() ) . '">' . esc_html__( 'Back to Events', 'equine-event-manager' ) . '</a></p></div></div></div>';
			return;
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-skeleton.php';

		$is_new      = '' === trim( (string) $post->post_title ) && 'draft' === $post->post_status;
		$data        = self::read_event( $event_id, $post );
		$nonce       = wp_create_nonce( self::NONCE );
		$preview_url = get_permalink( $event_id );

		$topbar_actions = '';
		if ( ! $is_new ) {
			if ( $preview_url ) {
				$topbar_actions .= '<a href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener" class="eem-btn eem-btn-secondary eem-btn-topbar">'
					. '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>'
					. '<span class="eem-btn-topbar-label">' . esc_html__( 'View Event', 'equine-event-manager' ) . '</span></a>';
			}
			$topbar_actions .= '<button type="button" class="eem-btn eem-btn-danger eem-btn-topbar" data-eem-action="event-editor-delete" data-event-id="' . esc_attr( (string) $event_id ) . '" data-event-title="' . esc_attr( (string) $post->post_title ) . '">'
				. '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>'
				. '<span class="eem-btn-topbar-label">' . esc_html__( 'Delete Event', 'equine-event-manager' ) . '</span></button>';
		}

		// Meta line for edit mode: "Event #N · dates · venue".
		$meta_parts = array();
		if ( ! $is_new ) {
			$meta_parts[] = sprintf( esc_html__( 'Event #%d', 'equine-event-manager' ), $event_id );
			if ( '' !== $data['start'] ) {
				$meta_parts[] = date_i18n( 'M j', strtotime( $data['start'] ) )
					. ( '' !== $data['end'] && $data['end'] !== $data['start'] ? '–' . date_i18n( 'j, Y', strtotime( $data['end'] ) ) : ', ' . date_i18n( 'Y', strtotime( $data['start'] ) ) );
			}
			if ( $data['venue_id'] > 0 ) {
				$meta_parts[] = get_the_title( $data['venue_id'] );
			}
		}
		?>
		<div class="eem-page eem-event-editor">
			<?php
			eem_render_breadcrumb(
				array(
					array( 'label' => __( 'Events', 'equine-event-manager' ), 'url' => EEM_Events_List_Page::url() ),
					array( 'label' => $is_new ? __( 'Add Event', 'equine-event-manager' ) : __( 'Edit Event', 'equine-event-manager' ) ),
				),
				$topbar_actions
			);
			?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<?php if ( ! $is_new ) : ?>
							<div class="eem-plugin-eyebrow"><?php esc_html_e( 'Edit Event', 'equine-event-manager' ); ?></div>
							<h1 class="eem-plugin-title"><?php echo esc_html( $post->post_title ); ?></h1>
							<?php if ( ! empty( $meta_parts ) ) : ?>
								<div class="eem-plugin-subtitle"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
							<?php endif; ?>
						<?php else : ?>
							<h1 class="eem-plugin-title"><?php esc_html_e( 'Add Event', 'equine-event-manager' ); ?></h1>
							<div class="eem-plugin-subtitle"><?php esc_html_e( 'Fill in the details below to create a new event.', 'equine-event-manager' ); ?></div>
						<?php endif; ?>
					</div>
				</header>

				<form class="eem-event-editor-form" id="eem-event-editor-form"
					data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-list-url="<?php echo esc_url( EEM_Events_List_Page::url() ); ?>"
					onsubmit="return false;">
					<div class="eem-event-editor-body">
						<div class="eem-edit-main">
							<?php self::render_main_cards( $data ); ?>
						</div>
						<aside class="eem-edit-rail">
							<?php self::render_rail( $data ); ?>
						</aside>
					</div>
				</form>
			</div>
		</div>

		<!-- Mobile sticky save -->
		<div class="eem-sticky-save">
			<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="event-editor-save" data-save-kind="save_draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-btn eem-btn-electric" data-eem-action="event-editor-save" data-save-kind="publish"><?php echo $data['status'] === 'publish' ? esc_html__( 'Update Event', 'equine-event-manager' ) : esc_html__( 'Publish Event', 'equine-event-manager' ); ?></button>
		</div>

		<?php self::print_editor_js(); ?>
		<?php
	}

	/**
	 * Read the event into a flat data array for rendering.
	 *
	 * @param int     $event_id Event id.
	 * @param WP_Post $post     Event post.
	 * @return array<string,mixed>
	 */
	private static function read_event( int $event_id, WP_Post $post ): array {
		$cat_ids = wp_get_object_terms( $event_id, 'en_event_category', array( 'fields' => 'ids' ) );
		return array(
			'id'               => $event_id,
			'title'            => (string) $post->post_title,
			'description'      => (string) $post->post_content,
			'excerpt'          => (string) $post->post_excerpt,
			'status'           => (string) $post->post_status,
			'start'            => (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true ),
			'end'              => (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true ),
			'venue_id'         => (int) get_post_meta( $event_id, '_equine_event_manager_event_venue_id', true ),
			'producer_id'      => (int) get_post_meta( $event_id, '_equine_event_manager_event_producer_id', true ),
			'location_label'   => (string) get_post_meta( $event_id, '_equine_event_manager_event_location_label', true ),
			'cta_label'        => (string) get_post_meta( $event_id, '_equine_event_manager_event_cta_label', true ),
			'facebook'         => (string) get_post_meta( $event_id, '_en_event_facebook', true ),
			'instagram'        => (string) get_post_meta( $event_id, '_en_event_instagram', true ),
			'flyer_id'         => (int) get_post_meta( $event_id, '_equine_event_manager_event_flyer_file_id', true ),
			'featured'         => (int) get_post_meta( $event_id, '_equine_event_manager_event_featured', true ) === 1,
			'thumbnail_id'     => (int) get_post_thumbnail_id( $event_id ),
			'category_ids'     => is_array( $cat_ids ) ? array_map( 'intval', $cat_ids ) : array(),
			'linked_res_id'    => (int) get_post_meta( $event_id, '_eem_event_linked_reservation_id', true ),
		);
	}

	/**
	 * Render the left column section cards.
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return void
	 */
	private static function render_main_cards( array $d ): void {
		echo '<div class="eem-event-cards-wrap">';
		eem_render_reservation_editor_section( array(
			'key' => 'title', 'title' => __( 'Event Title', 'equine-event-manager' ),
			'icon_tone' => 'navy', 'icon_key' => 'file-text', 'enable_toggle' => false,
			'collapsible' => false,
			'body_html' => self::body_title( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'details', 'title' => __( 'Event Details', 'equine-event-manager' ),
			'icon_tone' => 'blue', 'icon_key' => 'calendar', 'enable_toggle' => false,
			'collapsible' => false,
			'body_html' => self::body_details( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'desc', 'title' => __( 'Description', 'equine-event-manager' ),
			'icon_tone' => 'blue', 'icon_key' => 'file', 'enable_toggle' => false,
			'collapsible' => false,
			'body_html' => self::body_description( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'media', 'title' => __( 'Connections & Media', 'equine-event-manager' ),
			'icon_tone' => 'purple', 'icon_key' => 'package', 'enable_toggle' => false,
			'collapsible' => false,
			'body_html' => self::body_media( $d ),
		) );
		if ( EEM_Events::is_sheets_results_enabled() ) {
			eem_render_reservation_editor_section( array(
				'key' => 'sheets', 'title' => __( 'Sheets & Results', 'equine-event-manager' ),
				'icon_tone' => 'orange', 'icon_key' => 'file-text', 'enable_toggle' => false,
				'collapsible' => false,
				'body_html' => EEM_Sheets_Results_Page::render_embedded_section( (int) $d['id'] ),
			) );
		}
		echo '</div>';
	}

	/**
	 * Event Title card body.
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return string
	 */
	private static function body_title( array $d ): string {
		ob_start();
		?>
		<input class="eem-event-title-input" type="text" name="en_event[title]" value="<?php echo esc_attr( $d['title'] ); ?>" placeholder="<?php esc_attr_e( 'Enter event title…', 'equine-event-manager' ); ?>" />
		<p class="eem-field-hint" style="margin-top:8px;"><?php esc_html_e( 'This becomes the page title and is shown in all event cards and listings.', 'equine-event-manager' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Event Details card body (dates, venue, producer, location override).
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return string
	 */
	private static function body_details( array $d ): string {
		$venues    = get_posts( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$producers = get_posts( array( 'post_type' => 'en_producer', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		ob_start();
		?>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Start and end', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<div class="eem-date-range">
					<input class="eem-field-input eem-date-input" type="date" name="en_event[start_date]" value="<?php echo esc_attr( $d['start'] ); ?>" />
					<span class="eem-date-sep"><?php esc_html_e( 'to', 'equine-event-manager' ); ?></span>
					<input class="eem-field-input eem-date-input" type="date" name="en_event[end_date]" value="<?php echo esc_attr( $d['end'] ); ?>" />
				</div>
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Venue', 'equine-event-manager' ); ?></div>
			<div class="eem-field-control">
				<select class="eem-field-select" name="en_event[venue_id]">
					<option value="0"><?php esc_html_e( 'Select a venue…', 'equine-event-manager' ); ?></option>
					<?php foreach ( $venues as $v ) : ?>
						<option value="<?php echo esc_attr( (string) $v->ID ); ?>" <?php selected( $d['venue_id'], $v->ID ); ?>><?php echo esc_html( get_the_title( $v->ID ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="eem-field-hint"><?php esc_html_e( "Can't find it?", 'equine-event-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=en_venue' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add a new venue', 'equine-event-manager' ); ?></a></span>
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Producer', 'equine-event-manager' ); ?></div>
			<div class="eem-field-control">
				<select class="eem-field-select" name="en_event[producer_id]">
					<option value="0"><?php esc_html_e( 'Select a producer…', 'equine-event-manager' ); ?></option>
					<?php foreach ( $producers as $p ) : ?>
						<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( $d['producer_id'], $p->ID ); ?>><?php echo esc_html( get_the_title( $p->ID ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="eem-field-hint"><?php esc_html_e( "Can't find it?", 'equine-event-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=en_producer' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add a new producer', 'equine-event-manager' ); ?></a></span>
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Location Override', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Optional', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<input class="eem-field-input" type="text" name="en_event[location_label]" value="<?php echo esc_attr( $d['location_label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Perry, GA – Mane Arena', 'equine-event-manager' ); ?>" />
				<span class="eem-field-hint"><?php esc_html_e( 'Shown on event cards instead of the venue city/state when you need something more specific.', 'equine-event-manager' ); ?></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Description card body (description + excerpt).
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return string
	 */
	private static function body_description( array $d ): string {
		ob_start();
		?>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Event Description', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Shown on the event page', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<textarea class="eem-field-input eem-field-textarea" name="en_event[description]" placeholder="<?php esc_attr_e( 'Describe the event for attendees…', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $d['description'] ); ?></textarea>
				<span class="eem-field-hint"><?php esc_html_e( 'Displayed on the public event page.', 'equine-event-manager' ); ?></span>
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Excerpt', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Optional short summary', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<textarea class="eem-field-input eem-field-textarea" style="min-height:70px;" name="en_event[excerpt]" placeholder="<?php esc_attr_e( 'Optional hand-crafted summary for listings and widgets…', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $d['excerpt'] ); ?></textarea>
				<span class="eem-field-hint"><?php esc_html_e( 'Used in event cards and widgets if set; otherwise auto-generated from the description.', 'equine-event-manager' ); ?></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Connections & Media card body (CTA label, social, flyer, featured toggle).
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return string
	 */
	private static function body_media( array $d ): string {
		$flyer_name = $d['flyer_id'] > 0 ? get_the_title( $d['flyer_id'] ) : '';
		$cta        = '' !== $d['cta_label'] ? $d['cta_label'] : __( 'Reserve Now', 'equine-event-manager' );
		ob_start();
		?>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Button Label', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'CTA on event cards', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<div class="eem-btn-label-wrap">
					<span class="eem-btn-label-prefix"><?php esc_html_e( 'Button', 'equine-event-manager' ); ?></span>
					<input class="eem-btn-label-input" type="text" name="en_event[cta_label]" value="<?php echo esc_attr( $cta ); ?>" />
				</div>
				<span class="eem-field-hint"><?php esc_html_e( 'Text shown on the primary action button on the event page and cards.', 'equine-event-manager' ); ?></span>
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></div>
			<div class="eem-field-control">
				<input class="eem-field-input" type="url" name="en_event[website]" value="<?php echo esc_attr( (string) get_post_meta( $d['id'], '_en_event_website', true ) ); ?>" placeholder="https://…" />
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Facebook URL', 'equine-event-manager' ); ?></div>
			<div class="eem-field-control">
				<input class="eem-field-input" type="url" name="en_event[facebook]" value="<?php echo esc_attr( $d['facebook'] ); ?>" placeholder="https://facebook.com/…" />
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Instagram URL', 'equine-event-manager' ); ?></div>
			<div class="eem-field-control">
				<input class="eem-field-input" type="url" name="en_event[instagram]" value="<?php echo esc_attr( $d['instagram'] ); ?>" placeholder="https://instagram.com/…" />
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Event Flyer PDF', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Optional', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<div class="eem-file-row">
					<input type="hidden" name="en_event[flyer_id]" id="eem-event-flyer-id" value="<?php echo esc_attr( (string) $d['flyer_id'] ); ?>" />
					<div class="eem-file-name" id="eem-event-flyer-name"><?php echo '' !== $flyer_name ? esc_html( $flyer_name ) : esc_html__( 'No file selected', 'equine-event-manager' ); ?></div>
					<button type="button" class="eem-btn-upload" data-eem-action="event-editor-pick-flyer"><?php esc_html_e( 'Add File', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn-upload" data-eem-action="event-editor-clear-flyer"<?php echo $d['flyer_id'] > 0 ? '' : ' style="display:none;"'; ?> id="eem-event-flyer-clear"><?php esc_html_e( 'Remove', 'equine-event-manager' ); ?></button>
				</div>
				<span class="eem-field-hint"><?php esc_html_e( 'Customers can open or download the flyer from the event page.', 'equine-event-manager' ); ?></span>
			</div>
		</div>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Featured Event', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Widgets & shortcodes', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<label class="eem-toggle-chip" data-eem-action="event-editor-toggle-featured">
					<span class="eem-toggle <?php echo $d['featured'] ? 'eem-toggle--on' : 'eem-toggle--off'; ?>" id="eem-event-featured-toggle"></span>
					<input type="hidden" name="en_event[featured]" id="eem-event-featured-input" value="<?php echo $d['featured'] ? '1' : '0'; ?>" />
					<span><?php esc_html_e( 'Include in featured widgets and shortcodes', 'equine-event-manager' ); ?></span>
				</label>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Link Reservation card body (reservation-setup picker).
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return string
	 */
	private static function body_reservation( array $d ): string {
		$reservations = get_posts( array(
			'post_type'   => EEM_Reservations_CPT::POST_TYPE,
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => 200,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );
		ob_start();
		?>
		<div class="eem-field-row">
			<div class="eem-field-label"><?php esc_html_e( 'Reservation Setup', 'equine-event-manager' ); ?><div class="eem-field-label-sub"><?php esc_html_e( 'Choose or create', 'equine-event-manager' ); ?></div></div>
			<div class="eem-field-control">
				<select class="eem-field-select" name="en_event[reservation_id]" data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search reservations…', 'equine-event-manager' ); ?>">
					<option value="0"><?php esc_html_e( 'No linked reservation', 'equine-event-manager' ); ?></option>
					<?php foreach ( $reservations as $r ) : ?>
						<option value="<?php echo esc_attr( (string) $r->ID ); ?>" <?php selected( $d['linked_res_id'], $r->ID ); ?>><?php echo esc_html( get_the_title( $r->ID ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="eem-field-hint"><?php esc_html_e( 'Choose an existing reservation setup to render its booking flow on this event page.', 'equine-event-manager' ); ?></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the right rail (Publish / Categories / Featured Image / progress).
	 *
	 * @param array<string,mixed> $d Event data.
	 * @return void
	 */
	private static function render_rail( array $d ): void {
		$thumb        = $d['thumbnail_id'] > 0 ? wp_get_attachment_image_url( $d['thumbnail_id'], 'medium' ) : '';
		$terms        = get_terms( array( 'taxonomy' => 'en_event_category', 'hide_empty' => false ) );
		$is_published = 'publish' === $d['status'];
		$status_dot   = $is_published ? '#22c55e' : '#94a3b8';
		$status_label = $is_published ? __( 'Published', 'equine-event-manager' ) : __( 'Draft', 'equine-event-manager' );
		?>
		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Publish', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<button type="button" class="eem-btn eem-btn-secondary eem-rail-btn" data-eem-action="event-editor-save" data-save-kind="save_draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
				<div class="eem-rail-divider"></div>
				<div class="eem-publish-row">
					<span class="eem-status-dot" id="eem-event-status-dot" style="background:<?php echo esc_attr( $status_dot ); ?>"></span>
					<span id="eem-event-status-label"><?php echo esc_html( $status_label ); ?></span>
				</div>
				<div class="eem-rail-divider"></div>
				<button type="button" class="eem-btn eem-btn-electric eem-rail-btn" data-eem-action="event-editor-save" data-save-kind="publish"><?php echo $is_published ? esc_html__( 'Update Event', 'equine-event-manager' ) : esc_html__( 'Publish Event', 'equine-event-manager' ); ?></button>
			</div>
		</div>

		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Link Reservation', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<?php
				$reservations = get_posts( array(
					'post_type'   => EEM_Reservations_CPT::POST_TYPE,
					'post_status' => array( 'publish', 'draft' ),
					'numberposts' => 200,
					'orderby'     => 'title',
					'order'       => 'ASC',
				) );
				?>
				<select class="eem-field-select" name="en_event[reservation_id]" style="width:100%" data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search reservations…', 'equine-event-manager' ); ?>">
					<option value="0"><?php esc_html_e( 'No linked reservation', 'equine-event-manager' ); ?></option>
					<?php foreach ( $reservations as $r ) : ?>
						<option value="<?php echo esc_attr( (string) $r->ID ); ?>" <?php selected( $d['linked_res_id'], $r->ID ); ?>><?php echo esc_html( get_the_title( $r->ID ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="eem-field-hint"><?php esc_html_e( 'Choose an existing reservation to render its booking flow on this event page.', 'equine-event-manager' ); ?></span>
			</div>
		</div>

		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Event Categories', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<div class="eem-checklist">
					<?php if ( is_wp_error( $terms ) || empty( $terms ) ) : ?>
						<span class="eem-checklist-empty"><?php esc_html_e( 'No categories yet.', 'equine-event-manager' ); ?></span>
					<?php else : ?>
						<?php foreach ( $terms as $t ) : ?>
							<label class="eem-checklist-item"<?php echo (int) $t->parent > 0 ? ' style="padding-left:14px;"' : ''; ?>>
								<input type="checkbox" name="en_event[categories][]" value="<?php echo esc_attr( (string) $t->term_id ); ?>" <?php checked( in_array( (int) $t->term_id, $d['category_ids'], true ) ); ?> />
								<?php echo ( (int) $t->parent > 0 ? '— ' : '' ) . esc_html( $t->name ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div class="eem-checklist-footer"><a class="eem-add-term-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'equine-event-manager-event-categories' ), admin_url( 'admin.php' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '+ Add New Category', 'equine-event-manager' ); ?></a></div>
			</div>
		</div>

		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Featured Image', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<input type="hidden" name="en_event[thumbnail_id]" id="eem-event-thumb-id" value="<?php echo esc_attr( (string) $d['thumbnail_id'] ); ?>" />
				<div class="eem-featured-img" id="eem-event-featured-img" data-eem-action="event-editor-pick-thumb">
					<?php if ( '' !== $thumb ) : ?>
						<img src="<?php echo esc_url( $thumb ); ?>" alt="" />
					<?php else : ?>
						<div class="eem-featured-img-placeholder">
							<p><strong><?php esc_html_e( 'Set featured image', 'equine-event-manager' ); ?></strong></p>
							<p><?php esc_html_e( 'Click to upload or select from Media Library', 'equine-event-manager' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
				<a href="#" class="eem-add-term-link" data-eem-action="event-editor-clear-thumb" id="eem-event-thumb-clear"<?php echo $d['thumbnail_id'] > 0 ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Remove featured image', 'equine-event-manager' ); ?></a>
			</div>
		</div>

		<?php
		if ( EEM_Events::is_sheets_results_enabled() ) :
			$sr_counts = EEM_Sheet_Entries::counts( (int) $d['id'] );
			?>
			<div class="eem-rail-card">
				<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Sheets & Results', 'equine-event-manager' ); ?></span></div>
				<div class="eem-rail-body">
					<div class="eem-sr-rail-summary">
						<div class="eem-sr-rail-row"><span class="eem-sr-rail-label"><?php esc_html_e( 'Draw Sheets', 'equine-event-manager' ); ?></span><span class="eem-sr-rail-num" data-sr-rail-drawsheets><?php echo esc_html( (string) $sr_counts['drawsheets'] ); ?></span></div>
						<div class="eem-sr-rail-row"><span class="eem-sr-rail-label"><?php esc_html_e( 'Results', 'equine-event-manager' ); ?></span><span class="eem-sr-rail-num" data-sr-rail-results><?php echo esc_html( (string) $sr_counts['results'] ); ?></span></div>
					</div>
					<div class="eem-rail-divider"></div>
					<div class="eem-rail-hint"><?php esc_html_e( 'The Draw Sheets and Results buttons appear on the public event listing once files are uploaded.', 'equine-event-manager' ); ?></div>
					<a class="eem-btn eem-btn-secondary eem-rail-btn" href="<?php echo esc_url( EEM_Sheets_Results_Page::url( (int) $d['id'] ) ); ?>"><?php esc_html_e( 'Open Sheets & Results →', 'equine-event-manager' ); ?></a>
				</div>
			</div>
		<?php endif; ?>
		<?php self::render_setup_meter( $d ); ?>
		<?php
	}

	/**
	 * Event Setup completeness meter rail card.
	 *
	 * Server-renders the initial done/todo state; the editor JS keeps the dots
	 * and progress bar live as fields change (see print_editor_js). The optional
	 * "Link reservation" item does not count toward the progress percentage.
	 *
	 * @param array<string, mixed> $d Event data array from collect_event_data().
	 * @return void
	 */
	private static function render_setup_meter( array $d ): void {
		$checks = array(
			array( 'key' => 'title', 'label' => __( 'Add title', 'equine-event-manager' ), 'done' => '' !== trim( (string) $d['title'] ), 'optional' => false ),
			array( 'key' => 'dates', 'label' => __( 'Set event dates', 'equine-event-manager' ), 'done' => '' !== trim( (string) $d['start'] ), 'optional' => false ),
			array( 'key' => 'venue', 'label' => __( 'Select a venue', 'equine-event-manager' ), 'done' => (int) $d['venue_id'] > 0, 'optional' => false ),
			array( 'key' => 'desc', 'label' => __( 'Add description', 'equine-event-manager' ), 'done' => '' !== trim( wp_strip_all_tags( (string) $d['description'] ) ), 'optional' => false ),
			array( 'key' => 'thumb', 'label' => __( 'Set featured image', 'equine-event-manager' ), 'done' => (int) $d['thumbnail_id'] > 0, 'optional' => false ),
			array( 'key' => 'reservation', 'label' => __( 'Link reservation', 'equine-event-manager' ), 'done' => (int) $d['linked_res_id'] > 0, 'optional' => true ),
		);

		$required  = array_filter( $checks, static function ( array $c ): bool { return ! $c['optional']; } );
		$total_req = count( $required );
		$done_req  = count( array_filter( $required, static function ( array $c ): bool { return (bool) $c['done']; } ) );
		$pct       = $total_req > 0 ? (int) round( $done_req / $total_req * 100 ) : 0;
		?>
		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Event Setup', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<div class="eem-completeness" id="eem-event-completeness" data-required="<?php echo esc_attr( (string) $total_req ); ?>">
					<div class="eem-completeness-label"><?php esc_html_e( 'Progress', 'equine-event-manager' ); ?></div>
					<div class="eem-completeness-track"><div class="eem-completeness-fill" id="eem-event-completeness-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></div></div>
					<div class="eem-completeness-items">
						<?php foreach ( $checks as $c ) : ?>
							<div class="eem-comp-item <?php echo $c['done'] ? 'is-done' : 'is-todo'; ?>" data-comp="<?php echo esc_attr( (string) $c['key'] ); ?>">
								<span class="eem-comp-dot" aria-hidden="true"></span>
								<?php echo esc_html( (string) $c['label'] ); ?>
								<?php if ( $c['optional'] ) : ?><span class="eem-comp-optional"><?php esc_html_e( '(optional)', 'equine-event-manager' ); ?></span><?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline editor JS — collect form fields, AJAX save, media pickers, toggles.
	 *
	 * @return void
	 */
	private static function print_editor_js(): void {
		?>
		<script id="eem-event-editor-js">
		(function () {
			var form = document.getElementById('eem-event-editor-form');
			if (!form) { return; }

			function pickMedia(opts, cb) {
				if (!window.wp || !wp.media) { return; }
				var frame = wp.media({ title: opts.title, library: opts.library || {}, button: { text: opts.button }, multiple: false });
				frame.on('select', function () { cb(frame.state().get('selection').first().toJSON()); });
				frame.open();
			}

			function collect() {
				var data = { action: 'eem_event_editor_save', nonce: form.dataset.nonce, event_id: form.dataset.eventId };
				form.querySelectorAll('[name^="en_event["]').forEach(function (el) {
					if (el.type === 'checkbox') {
						if (el.checked) { data[el.name] = data[el.name] || []; data[el.name].push(el.value); }
						return;
					}
					data[el.name] = el.value;
				});
				return data;
			}

			function save(kind, btn) {
				var data = collect();
				data.save_kind = kind;
				// Flatten array params (categories[]) for URLSearchParams.
				var body = new URLSearchParams();
				Object.keys(data).forEach(function (k) {
					if (Array.isArray(data[k])) { data[k].forEach(function (v) { body.append(k, v); }); }
					else { body.append(k, data[k]); }
				});
				var label = btn ? btn.textContent : '';
				if (btn) { btn.disabled = true; }
				fetch(form.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (btn) { btn.disabled = false; }
						if (res && res.success) {
							if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast(res.data.message || 'Saved'); }
							var sl = document.getElementById('eem-event-status-label');
							if (sl && res.data.status) { sl.textContent = res.data.status === 'publish' ? 'Published' : 'Draft'; }
						} else {
							var msg = (res && res.data && res.data.message) ? res.data.message : 'Save failed';
							if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast(msg, { variant: 'error' }); }
							else { alert(msg); }
						}
					})
					.catch(function () { if (btn) { btn.disabled = false; } if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast('Save failed', { variant: 'error' }); } });
			}

			document.addEventListener('click', function (ev) {
				var t = ev.target.closest('[data-eem-action]');
				if (!t || !form.contains(t) && !document.querySelector('.eem-sticky-save').contains(t)) { return; }
				var action = t.dataset.eemAction;
				if (action === 'event-editor-save') { ev.preventDefault(); save(t.dataset.saveKind || 'save_draft', t); }
				else if (action === 'event-editor-toggle-featured') {
					ev.preventDefault();
					var tog = document.getElementById('eem-event-featured-toggle');
					var inp = document.getElementById('eem-event-featured-input');
					var on = tog.classList.toggle('eem-toggle--on'); tog.classList.toggle('eem-toggle--off', !on);
					inp.value = on ? '1' : '0';
				}
				else if (action === 'event-editor-pick-flyer') {
					ev.preventDefault();
					pickMedia({ title: 'Select event flyer', button: 'Use this file', library: { type: 'application/pdf' } }, function (att) {
						document.getElementById('eem-event-flyer-id').value = att.id;
						document.getElementById('eem-event-flyer-name').textContent = att.filename || att.title;
						document.getElementById('eem-event-flyer-clear').style.display = '';
					});
				}
				else if (action === 'event-editor-clear-flyer') {
					ev.preventDefault();
					document.getElementById('eem-event-flyer-id').value = '0';
					document.getElementById('eem-event-flyer-name').textContent = '<?php echo esc_js( __( 'No file selected', 'equine-event-manager' ) ); ?>';
					t.style.display = 'none';
				}
				else if (action === 'event-editor-pick-thumb') {
					ev.preventDefault();
					pickMedia({ title: 'Featured image', button: 'Use this image', library: { type: 'image' } }, function (att) {
						document.getElementById('eem-event-thumb-id').value = att.id;
						var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
						document.getElementById('eem-event-featured-img').innerHTML = '<img src="' + url + '" alt="">';
						document.getElementById('eem-event-thumb-clear').style.display = ''; recomputeSetupMeter();
					});
				}
				else if (action === 'event-editor-clear-thumb') {
					ev.preventDefault();
					document.getElementById('eem-event-thumb-id').value = '0'; recomputeSetupMeter();
					document.getElementById('eem-event-featured-img').innerHTML = '<div class="eem-featured-img-placeholder"><p><strong><?php echo esc_js( __( 'Set featured image', 'equine-event-manager' ) ); ?></strong></p><p><?php echo esc_js( __( 'Click to upload or select from Media Library', 'equine-event-manager' ) ); ?></p></div>';
					t.style.display = 'none';
				}
			});
		// Event Setup completeness meter — keep dots + progress bar live as fields change.
				function recomputeSetupMeter() {
					var wrap = document.getElementById('eem-event-completeness');
					if (!wrap) { return; }
					function val(sel) { var el = form.querySelector(sel); return el ? el.value : ''; }
					function filled(v) { v = (v || '').toString().trim(); return v !== '' && v !== '0'; }
					var done = {
						title: filled(val('[name="en_event[title]"]')),
						dates: filled(val('[name="en_event[start_date]"]')),
						venue: filled(val('[name="en_event[venue_id]"]')),
						desc: filled(val('[name="en_event[description]"]')),
						thumb: filled((document.getElementById('eem-event-thumb-id') || {}).value),
						reservation: filled(val('[name="en_event[reservation_id]"]'))
					};
					var required = parseInt(wrap.getAttribute('data-required') || '5', 10);
					var doneReq = 0;
					wrap.querySelectorAll('.eem-comp-item').forEach(function (item) {
						var key = item.getAttribute('data-comp');
						var isDone = !!done[key];
						item.classList.toggle('is-done', isDone);
						item.classList.toggle('is-todo', !isDone);
						if (key !== 'reservation' && isDone) { doneReq++; }
					});
					var fill = document.getElementById('eem-event-completeness-fill');
					if (fill) { fill.style.width = Math.round(doneReq / required * 100) + '%'; }
				}
				form.addEventListener('input', recomputeSetupMeter);
				form.addEventListener('change', recomputeSetupMeter);
			})();
		</script>
		<?php
	}

	/**
	 * AJAX: persist the event from the editor form.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$post     = $event_id > 0 ? get_post( $event_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'equine-event-manager' ) ), 404 );
		}

		$raw       = isset( $_POST['en_event'] ) && is_array( $_POST['en_event'] ) ? wp_unslash( $_POST['en_event'] ) : array();
		$save_kind = isset( $_POST['save_kind'] ) ? sanitize_key( wp_unslash( $_POST['save_kind'] ) ) : 'save_draft';
		$status    = 'publish' === $save_kind ? 'publish' : 'draft';

		$title = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		if ( 'publish' === $status && '' === trim( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'An event title is required to publish.', 'equine-event-manager' ) ), 422 );
		}

		wp_update_post( array(
			'ID'           => $event_id,
			'post_title'   => $title,
			'post_content' => isset( $raw['description'] ) ? wp_kses_post( $raw['description'] ) : '',
			'post_excerpt' => isset( $raw['excerpt'] ) ? sanitize_textarea_field( $raw['excerpt'] ) : '',
			'post_status'  => $status,
		) );

		// Dates (clamp end >= start).
		$start = isset( $raw['start_date'] ) ? sanitize_text_field( $raw['start_date'] ) : '';
		$end   = isset( $raw['end_date'] ) ? sanitize_text_field( $raw['end_date'] ) : '';
		if ( '' !== $start && '' !== $end && $end < $start ) {
			$end = $start;
		}
		update_post_meta( $event_id, '_equine_event_manager_event_start_date', $start );
		update_post_meta( $event_id, '_equine_event_manager_event_end_date', '' !== $end ? $end : $start );
		update_post_meta( $event_id, '_equine_event_manager_event_venue_id', isset( $raw['venue_id'] ) ? absint( $raw['venue_id'] ) : 0 );
		update_post_meta( $event_id, '_equine_event_manager_event_producer_id', isset( $raw['producer_id'] ) ? absint( $raw['producer_id'] ) : 0 );
		update_post_meta( $event_id, '_equine_event_manager_event_location_label', isset( $raw['location_label'] ) ? sanitize_text_field( $raw['location_label'] ) : '' );
		update_post_meta( $event_id, '_equine_event_manager_event_cta_label', isset( $raw['cta_label'] ) && '' !== trim( $raw['cta_label'] ) ? sanitize_text_field( $raw['cta_label'] ) : __( 'Reserve Now', 'equine-event-manager' ) );
		update_post_meta( $event_id, '_equine_event_manager_event_flyer_file_id', isset( $raw['flyer_id'] ) ? absint( $raw['flyer_id'] ) : 0 );
		update_post_meta( $event_id, '_equine_event_manager_event_featured', ! empty( $raw['featured'] ) ? 1 : 0 );
		update_post_meta( $event_id, '_en_event_website', isset( $raw['website'] ) ? esc_url_raw( $raw['website'] ) : '' );
		update_post_meta( $event_id, '_en_event_facebook', isset( $raw['facebook'] ) ? esc_url_raw( $raw['facebook'] ) : '' );
		update_post_meta( $event_id, '_en_event_instagram', isset( $raw['instagram'] ) ? esc_url_raw( $raw['instagram'] ) : '' );

		// Categories.
		$cat_ids = isset( $raw['categories'] ) && is_array( $raw['categories'] ) ? array_map( 'absint', $raw['categories'] ) : array();
		wp_set_object_terms( $event_id, $cat_ids, 'en_event_category', false );

		// Featured image.
		$thumb_id = isset( $raw['thumbnail_id'] ) ? absint( $raw['thumbnail_id'] ) : 0;
		if ( $thumb_id > 0 ) {
			set_post_thumbnail( $event_id, $thumb_id );
		} else {
			delete_post_thumbnail( $event_id );
		}

		// Linked reservation — store on the event AND point the reservation's
		// _en_event_id at this event so its booking flow renders here.
		$res_id  = isset( $raw['reservation_id'] ) ? absint( $raw['reservation_id'] ) : 0;
		$prev_res = (int) get_post_meta( $event_id, '_eem_event_linked_reservation_id', true );
		if ( $res_id !== $prev_res ) {
			if ( $prev_res > 0 && EEM_Reservations_CPT::POST_TYPE === get_post_type( $prev_res ) ) {
				delete_post_meta( $prev_res, '_en_event_id' );
			}
			update_post_meta( $event_id, '_eem_event_linked_reservation_id', $res_id );
			if ( $res_id > 0 && EEM_Reservations_CPT::POST_TYPE === get_post_type( $res_id ) ) {
				update_post_meta( $res_id, '_en_event_id', $event_id );
			}
		}

		wp_send_json_success( array(
			'event_id' => $event_id,
			'status'   => $status,
			'message'  => 'publish' === $status ? __( 'Event published.', 'equine-event-manager' ) : __( 'Draft saved.', 'equine-event-manager' ),
		) );
	}
}
