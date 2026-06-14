<?php
/**
 * Branded Add/Edit Venue editor page.
 *
 * Replaces the default WordPress `en_venue` post editor with a card-based
 * branded editor matching the Event editor pattern. 2-column layout (main +
 * rail), mobile sticky save bar, AJAX persistence.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Venue editor page controller.
 */
class EEM_Venue_Editor_Page {

	/**
	 * Admin menu slug (hidden submenu).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-venue-editor';

	/**
	 * Post type edited here.
	 *
	 * @var string
	 */
	const POST_TYPE = 'en_venue';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE = 'eem_venue_editor';

	/**
	 * Wire AJAX save + legacy editor redirects.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_venue_editor_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'maybe_redirect_new' ) );
		add_action( 'load-post.php', array( __CLASS__, 'maybe_redirect_legacy_edit' ) );
	}

	/**
	 * Build the editor URL for a venue post id.
	 *
	 * @param int $venue_id Venue post id.
	 * @return string
	 */
	public static function url( int $venue_id ): string {
		return add_query_arg(
			array( 'page' => self::MENU_SLUG, 'venue_id' => $venue_id ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * On post-new.php?post_type=en_venue, create a draft and redirect.
	 *
	 * @return void
	 */
	public static function maybe_redirect_new(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::POST_TYPE !== $post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
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
		wp_safe_redirect( self::url( $new_id ) );
		exit;
	}

	/**
	 * On post.php?post=N&action=edit for en_venue, redirect to branded editor.
	 *
	 * @return void
	 */
	public static function maybe_redirect_legacy_edit(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( $post_id < 1 || 'edit' !== $action ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		wp_safe_redirect( self::url( $post_id ) );
		exit;
	}

	/**
	 * Render the full branded editor page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$venue_id = isset( $_GET['venue_id'] ) ? absint( wp_unslash( $_GET['venue_id'] ) ) : 0;
		$post     = $venue_id > 0 ? get_post( $venue_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			echo '<div class="eem-page"><div class="eem-plugin-wrap"><div class="eem-edit-body"><p>' . esc_html__( 'That venue could not be found.', 'equine-event-manager' ) . '</p><p><a class="eem-btn eem-btn-secondary" href="' . esc_url( EEM_Venues_Page::url() ) . '">' . esc_html__( 'Back to Venues', 'equine-event-manager' ) . '</a></p></div></div></div>';
			return;
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-skeleton.php';

		$is_new = '' === trim( (string) $post->post_title ) && 'draft' === $post->post_status;
		$data   = self::read_venue( $venue_id, $post );
		$nonce  = wp_create_nonce( self::NONCE );
		?>
		<div class="eem-page eem-event-editor">
			<?php
			eem_render_breadcrumb( array(
				array( 'label' => __( 'Venues', 'equine-event-manager' ), 'url' => EEM_Venues_Page::url() ),
				array( 'label' => $is_new ? __( 'Add Venue', 'equine-event-manager' ) : __( 'Edit Venue', 'equine-event-manager' ) ),
			) );
			?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<h1 class="eem-plugin-title"><?php echo $is_new ? esc_html__( 'Add Venue', 'equine-event-manager' ) : esc_html__( 'Edit Venue', 'equine-event-manager' ); ?></h1>
						<div class="eem-plugin-subtitle"><?php esc_html_e( 'Set the venue name, address, contact info, and coordinates. Linked events inherit this location automatically.', 'equine-event-manager' ); ?></div>
					</div>
				</header>

				<form class="eem-event-editor-form" id="eem-venue-editor-form"
					data-venue-id="<?php echo esc_attr( (string) $venue_id ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-list-url="<?php echo esc_url( EEM_Venues_Page::url() ); ?>"
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
			<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="venue-editor-save" data-save-kind="save_draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-btn eem-btn-electric" data-eem-action="venue-editor-save" data-save-kind="publish"><?php echo $data['status'] === 'publish' ? esc_html__( 'Update Venue', 'equine-event-manager' ) : esc_html__( 'Publish Venue', 'equine-event-manager' ); ?></button>
		</div>

		<?php self::print_editor_js(); ?>
		<?php
	}

	/**
	 * Read venue post data into a flat array.
	 *
	 * @param int     $venue_id Venue post id.
	 * @param WP_Post $post     Venue post object.
	 * @return array<string,mixed>
	 */
	private static function read_venue( int $venue_id, WP_Post $post ): array {
		$cat_ids = wp_get_object_terms( $venue_id, 'en_venue_category', array( 'fields' => 'ids' ) );
		return array(
			'id'          => $venue_id,
			'title'       => (string) $post->post_title,
			'description' => (string) $post->post_content,
			'status'      => (string) $post->post_status,
			'address_1'   => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_address_1', true ),
			'address_2'   => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_address_2', true ),
			'city'        => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_city', true ),
			'state'       => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_state', true ),
			'postal_code' => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_postal_code', true ),
			'phone'       => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_phone', true ),
			'website'     => (string) get_post_meta( $venue_id, '_equine_event_manager_venue_website', true ),
			'lat'         => (string) get_post_meta( $venue_id, '_en_venue_lat', true ),
			'lng'         => (string) get_post_meta( $venue_id, '_en_venue_lng', true ),
			'category_ids' => is_array( $cat_ids ) ? array_map( 'intval', $cat_ids ) : array(),
		);
	}

	/**
	 * Render the main column section cards.
	 *
	 * @param array<string,mixed> $d Venue data.
	 * @return void
	 */
	private static function render_main_cards( array $d ): void {
		eem_render_reservation_editor_section( array(
			'key' => 'venue-name', 'title' => __( 'Venue Name', 'equine-event-manager' ),
			'icon_tone' => 'navy', 'icon_key' => 'map-pin', 'enable_toggle' => false,
			'body_html' => self::body_name( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'venue-details', 'title' => __( 'Address & Contact', 'equine-event-manager' ),
			'icon_tone' => 'blue', 'icon_key' => 'map-pin', 'enable_toggle' => false,
			'body_html' => self::body_details( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'venue-description', 'title' => __( 'Description', 'equine-event-manager' ),
			'icon_tone' => 'blue', 'icon_key' => 'file-text', 'enable_toggle' => false,
			'body_html' => self::body_description( $d ),
		) );
	}

	/**
	 * Body HTML for the Name section card.
	 */
	private static function body_name( array $d ): string {
		ob_start();
		?>
		<div class="eem-event-editor-grid">
			<label class="eem-event-editor-field eem-event-editor-field--full">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Name', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[title]" value="<?php echo esc_attr( $d['title'] ); ?>" class="regular-text" />
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Body HTML for the Address & Contact section card.
	 */
	private static function body_details( array $d ): string {
		$maps_key_set = '' !== EEM_Events::get_google_maps_api_key();
		ob_start();
		?>
		<div class="eem-event-editor-grid">
			<label class="eem-event-editor-field eem-event-editor-field--full">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Address Line 1', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[address_1]" value="<?php echo esc_attr( $d['address_1'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field eem-event-editor-field--full">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Address Line 2', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[address_2]" value="<?php echo esc_attr( $d['address_2'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'City', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[city]" value="<?php echo esc_attr( $d['city'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'State', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[state]" value="<?php echo esc_attr( $d['state'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Postal Code', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[postal_code]" value="<?php echo esc_attr( $d['postal_code'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[phone]" value="<?php echo esc_attr( $d['phone'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field eem-event-editor-field--full">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></span>
				<input type="url" name="venue[website]" value="<?php echo esc_attr( $d['website'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Latitude', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[lat]" value="<?php echo esc_attr( $d['lat'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Auto-filled from address', 'equine-event-manager' ); ?>" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Longitude', 'equine-event-manager' ); ?></span>
				<input type="text" name="venue[lng]" value="<?php echo esc_attr( $d['lng'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Auto-filled from address', 'equine-event-manager' ); ?>" />
			</label>
			<p class="eem-event-editor-field eem-event-editor-field--full eem-event-editor-field__description" style="margin:0;">
				<?php
				if ( $maps_key_set ) {
					esc_html_e( 'Coordinates power the events map view. Leave blank to auto-fill from the address on save (via Google). Enter values manually to override.', 'equine-event-manager' );
				} else {
					esc_html_e( 'Add a Google Maps API key under Settings → Integrations to auto-fill coordinates from the address, or enter Latitude/Longitude manually.', 'equine-event-manager' );
				}
				?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Body HTML for the Description section card.
	 */
	private static function body_description( array $d ): string {
		ob_start();
		?>
		<div class="eem-event-editor-grid">
			<label class="eem-event-editor-field eem-event-editor-field--full">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></span>
				<textarea name="venue[description]" rows="6" class="large-text"><?php echo esc_textarea( $d['description'] ); ?></textarea>
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the right rail (Publish + Categories + Saved Layouts).
	 *
	 * @param array<string,mixed> $d Venue data.
	 * @return void
	 */
	private static function render_rail( array $d ): void {
		$terms = get_terms( array( 'taxonomy' => 'en_venue_category', 'hide_empty' => false ) );
		?>
		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Publish', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<button type="button" class="eem-btn eem-btn-secondary eem-rail-btn" data-eem-action="venue-editor-save" data-save-kind="save_draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
				<div class="eem-rail-divider"></div>
				<div class="eem-publish-row"><?php esc_html_e( 'Status:', 'equine-event-manager' ); ?> <strong id="eem-venue-status-label"><?php echo 'publish' === $d['status'] ? esc_html__( 'Published', 'equine-event-manager' ) : esc_html__( 'Draft', 'equine-event-manager' ); ?></strong></div>
				<div class="eem-rail-divider"></div>
				<button type="button" class="eem-btn eem-btn-electric eem-rail-btn" data-eem-action="venue-editor-save" data-save-kind="publish"><?php echo 'publish' === $d['status'] ? esc_html__( 'Update Venue', 'equine-event-manager' ) : esc_html__( 'Publish Venue', 'equine-event-manager' ); ?></button>
			</div>
		</div>

		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Venue Categories', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<div class="eem-checklist">
					<?php if ( is_wp_error( $terms ) || empty( $terms ) ) : ?>
						<span class="eem-checklist-empty"><?php esc_html_e( 'No categories yet.', 'equine-event-manager' ); ?></span>
					<?php else : ?>
						<?php foreach ( $terms as $t ) : ?>
							<label class="eem-checklist-item"<?php echo (int) $t->parent > 0 ? ' style="padding-left:14px;"' : ''; ?>>
								<input type="checkbox" name="venue[categories][]" value="<?php echo esc_attr( (string) $t->term_id ); ?>" <?php checked( in_array( (int) $t->term_id, $d['category_ids'], true ) ); ?> />
								<?php echo ( (int) $t->parent > 0 ? '— ' : '' ) . esc_html( $t->name ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div class="eem-checklist-footer"><a class="eem-add-term-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'equine-event-manager-venue-categories' ), admin_url( 'admin.php' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '+ Add New Category', 'equine-event-manager' ); ?></a></div>
			</div>
		</div>

		<?php self::render_layouts_card( $d ); ?>
		<?php
	}

	/**
	 * Render the Saved Stall / RV Layouts rail card.
	 *
	 * @param array<string,mixed> $d Venue data.
	 * @return void
	 */
	private static function render_layouts_card( array $d ): void {
		if ( ! class_exists( 'EEM_Venue' ) ) {
			return;
		}
		$venue_id = EEM_Venue::find_for_native_venue( (int) $d['id'], (string) $d['title'] );
		$layouts  = $venue_id > 0 ? EEM_Venue::get_layouts( $venue_id ) : array();
		$nonce    = wp_create_nonce( 'eem_venue_layout' );
		?>
		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Saved Layouts', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<div class="eem-venue-layouts" data-eem-venue-layouts data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<?php if ( empty( $layouts ) ) : ?>
						<p class="eem-venue-layouts__empty"><?php esc_html_e( 'No saved layouts yet. Build a stall or RV map on a reservation at this venue, then use "Save Layout" to store it here for reuse.', 'equine-event-manager' ); ?></p>
					<?php else : ?>
						<ul class="eem-venue-layouts__list">
							<?php foreach ( $layouts as $layout ) : ?>
								<li class="eem-venue-layouts__row" data-layout-id="<?php echo esc_attr( (string) $layout['id'] ); ?>">
									<span class="eem-venue-layouts__name"><?php echo esc_html( (string) $layout['name'] ); ?></span>
									<span class="eem-venue-layouts__actions">
										<button type="button" class="button-link" data-eem-action="venue-layout-rename"><?php esc_html_e( 'Rename', 'equine-event-manager' ); ?></button>
										<button type="button" class="button-link button-link-delete" data-eem-action="venue-layout-delete"><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></button>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p class="eem-venue-layouts__hint"><?php esc_html_e( 'Layouts are shared by every event held at this venue.', 'equine-event-manager' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline JS for save button + layout actions.
	 *
	 * @return void
	 */
	private static function print_editor_js(): void {
		?>
		<script id="eem-venue-editor-js">
		(function () {
			var form = document.getElementById('eem-venue-editor-form');
			if (!form) { return; }

			function collect() {
				var data = { action: 'eem_venue_editor_save', nonce: form.dataset.nonce, venue_id: form.dataset.venueId };
				form.querySelectorAll('[name^="venue["]').forEach(function (el) {
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
				var body = new URLSearchParams();
				Object.keys(data).forEach(function (k) {
					if (Array.isArray(data[k])) { data[k].forEach(function (v) { body.append(k, v); }); }
					else { body.append(k, data[k]); }
				});
				if (btn) { btn.disabled = true; }
				fetch(form.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (btn) { btn.disabled = false; }
						if (res && res.success) {
							if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast(res.data.message || 'Saved'); }
							var sl = document.getElementById('eem-venue-status-label');
							if (sl && res.data.status) { sl.textContent = res.data.status === 'publish' ? 'Published' : 'Draft'; }
						} else {
							var msg = (res && res.data && res.data.message) ? res.data.message : 'Save failed';
							if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast(msg, { variant: 'error' }); }
							else { alert(msg); }
						}
					})
					.catch(function () { if (btn) { btn.disabled = false; } if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast('Save failed', { variant: 'error' }); } });
			}

			// Layout actions (rename/delete).
			function layoutPost(action, params) {
				var root = document.querySelector('[data-eem-venue-layouts]');
				if (!root) { return Promise.resolve({}); }
				var body = new URLSearchParams();
				body.append('action', action); body.append('nonce', root.dataset.nonce);
				Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
				return fetch(root.dataset.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }).then(function (r) { return r.json(); });
			}

			document.addEventListener('click', function (ev) {
				var t = ev.target.closest('[data-eem-action]');
				if (!t) { return; }
				var action = t.dataset.eemAction;

				if (action === 'venue-editor-save') {
					ev.preventDefault();
					save(t.dataset.saveKind || 'save_draft', t);
				} else if (action === 'venue-layout-rename') {
					ev.preventDefault();
					var row = t.closest('.eem-venue-layouts__row');
					if (!row) { return; }
					var nameEl = row.querySelector('.eem-venue-layouts__name');
					var current = nameEl ? nameEl.textContent : '';
					var next = window.prompt(<?php echo wp_json_encode( __( 'Rename layout:', 'equine-event-manager' ) ); ?>, current);
					if (next === null || !(next = next.trim()) || next === current) { return; }
					layoutPost('eem_venue_rename_layout', { layout_id: row.getAttribute('data-layout-id'), name: next }).then(function (res) {
						if (res && res.success) { if (nameEl) { nameEl.textContent = next; } }
						else { window.alert((res && res.data && res.data.message) || <?php echo wp_json_encode( __( 'Could not rename the layout.', 'equine-event-manager' ) ); ?>); }
					});
				} else if (action === 'venue-layout-delete') {
					ev.preventDefault();
					var row = t.closest('.eem-venue-layouts__row');
					if (!row) { return; }
					if (!window.confirm(<?php echo wp_json_encode( __( 'Delete this saved layout? This cannot be undone.', 'equine-event-manager' ) ); ?>)) { return; }
					layoutPost('eem_venue_delete_layout', { layout_id: row.getAttribute('data-layout-id') }).then(function (res) {
						if (res && res.success) { row.parentNode.removeChild(row); }
						else { window.alert((res && res.data && res.data.message) || <?php echo wp_json_encode( __( 'Could not delete the layout.', 'equine-event-manager' ) ); ?>); }
					});
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: persist venue data.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$venue_id = isset( $_POST['venue_id'] ) ? absint( wp_unslash( $_POST['venue_id'] ) ) : 0;
		$post     = $venue_id > 0 ? get_post( $venue_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Venue not found.', 'equine-event-manager' ) ), 404 );
		}

		$raw       = isset( $_POST['venue'] ) && is_array( $_POST['venue'] ) ? wp_unslash( $_POST['venue'] ) : array();
		$save_kind = isset( $_POST['save_kind'] ) ? sanitize_key( wp_unslash( $_POST['save_kind'] ) ) : 'save_draft';
		$status    = 'publish' === $save_kind ? 'publish' : 'draft';

		$title = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		if ( 'publish' === $status && '' === trim( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'A venue name is required to publish.', 'equine-event-manager' ) ), 422 );
		}

		wp_update_post( array(
			'ID'           => $venue_id,
			'post_title'   => $title,
			'post_content' => isset( $raw['description'] ) ? wp_kses_post( $raw['description'] ) : '',
			'post_status'  => $status,
		) );

		// Address + contact fields.
		$text_fields = array( 'address_1', 'address_2', 'city', 'state', 'postal_code', 'phone' );
		foreach ( $text_fields as $field ) {
			$value = isset( $raw[ $field ] ) ? sanitize_text_field( $raw[ $field ] ) : '';
			update_post_meta( $venue_id, '_equine_event_manager_venue_' . $field, $value );
		}
		// Website.
		update_post_meta( $venue_id, '_equine_event_manager_venue_website', isset( $raw['website'] ) ? esc_url_raw( $raw['website'] ) : '' );

		// Coordinates.
		$manual_lat = isset( $raw['lat'] ) ? trim( $raw['lat'] ) : '';
		$manual_lng = isset( $raw['lng'] ) ? trim( $raw['lng'] ) : '';
		if ( '' !== $manual_lat && '' !== $manual_lng && is_numeric( $manual_lat ) && is_numeric( $manual_lng ) ) {
			update_post_meta( $venue_id, '_en_venue_lat', (float) $manual_lat );
			update_post_meta( $venue_id, '_en_venue_lng', (float) $manual_lng );
			update_post_meta( $venue_id, '_en_venue_geocoded_address', '' );
		} else {
			EEM_Events::maybe_geocode_venue( $venue_id );
		}

		// Categories.
		$cat_ids = isset( $raw['categories'] ) && is_array( $raw['categories'] ) ? array_map( 'absint', $raw['categories'] ) : array();
		wp_set_object_terms( $venue_id, $cat_ids, 'en_venue_category', false );

		// Sync canonical EEM_Venue record.
		if ( class_exists( 'EEM_Venue' ) ) {
			EEM_Venue::sync_native_venue( $venue_id );
		}

		wp_send_json_success( array(
			'message' => __( 'Venue saved.', 'equine-event-manager' ),
			'status'  => $status,
		) );
	}
}
