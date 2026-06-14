<?php
/**
 * Branded Add/Edit Producer editor page.
 *
 * Replaces the default WordPress `en_producer` post editor with a card-based
 * branded editor matching the Event editor pattern. 2-column layout (main +
 * rail), mobile sticky save bar, AJAX persistence.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Producer editor page controller.
 */
class EEM_Producer_Editor_Page {

	/**
	 * Admin menu slug (hidden submenu).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-producer-editor';

	/**
	 * Post type edited here.
	 *
	 * @var string
	 */
	const POST_TYPE = 'en_producer';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE = 'eem_producer_editor';

	/**
	 * Wire AJAX save + legacy editor redirects.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_producer_editor_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'maybe_redirect_new' ) );
		add_action( 'load-post.php', array( __CLASS__, 'maybe_redirect_legacy_edit' ) );
	}

	/**
	 * Build the editor URL for a producer post id.
	 *
	 * @param int $producer_id Producer post id.
	 * @return string
	 */
	public static function url( int $producer_id ): string {
		return add_query_arg(
			array( 'page' => self::MENU_SLUG, 'producer_id' => $producer_id ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * On post-new.php?post_type=en_producer, create a draft and redirect.
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
	 * On post.php?post=N&action=edit for en_producer, redirect to branded editor.
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
		$producer_id = isset( $_GET['producer_id'] ) ? absint( wp_unslash( $_GET['producer_id'] ) ) : 0;
		$post        = $producer_id > 0 ? get_post( $producer_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			echo '<div class="eem-page"><div class="eem-plugin-wrap"><div class="eem-edit-body"><p>' . esc_html__( 'That producer could not be found.', 'equine-event-manager' ) . '</p><p><a class="eem-btn eem-btn-secondary" href="' . esc_url( EEM_Producers_Page::url() ) . '">' . esc_html__( 'Back to Producers', 'equine-event-manager' ) . '</a></p></div></div></div>';
			return;
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-skeleton.php';

		$is_new = '' === trim( (string) $post->post_title ) && 'draft' === $post->post_status;
		$data   = self::read_producer( $producer_id, $post );
		$nonce  = wp_create_nonce( self::NONCE );
		?>
		<div class="eem-page eem-event-editor">
			<?php
			eem_render_breadcrumb( array(
				array( 'label' => __( 'Producers', 'equine-event-manager' ), 'url' => EEM_Producers_Page::url() ),
				array( 'label' => $is_new ? __( 'Add Producer', 'equine-event-manager' ) : __( 'Edit Producer', 'equine-event-manager' ) ),
			) );
			?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<h1 class="eem-plugin-title"><?php echo $is_new ? esc_html__( 'Add Producer', 'equine-event-manager' ) : esc_html__( 'Edit Producer', 'equine-event-manager' ); ?></h1>
						<div class="eem-plugin-subtitle"><?php esc_html_e( 'Set the producer name, contact info, and website. Linked events inherit this organizer profile automatically.', 'equine-event-manager' ); ?></div>
					</div>
				</header>

				<form class="eem-event-editor-form" id="eem-producer-editor-form"
					data-producer-id="<?php echo esc_attr( (string) $producer_id ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-list-url="<?php echo esc_url( EEM_Producers_Page::url() ); ?>"
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
			<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="producer-editor-save" data-save-kind="save_draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-btn eem-btn-electric" data-eem-action="producer-editor-save" data-save-kind="publish"><?php echo $data['status'] === 'publish' ? esc_html__( 'Update Producer', 'equine-event-manager' ) : esc_html__( 'Publish Producer', 'equine-event-manager' ); ?></button>
		</div>

		<?php self::print_editor_js(); ?>
		<?php
	}

	/**
	 * Read producer post data into a flat array.
	 *
	 * @param int     $producer_id Producer post id.
	 * @param WP_Post $post        Producer post object.
	 * @return array<string,mixed>
	 */
	private static function read_producer( int $producer_id, WP_Post $post ): array {
		$cat_ids = wp_get_object_terms( $producer_id, 'en_producer_category', array( 'fields' => 'ids' ) );
		return array(
			'id'           => $producer_id,
			'title'        => (string) $post->post_title,
			'description'  => (string) $post->post_content,
			'status'       => (string) $post->post_status,
			'contact_name' => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_contact_name', true ),
			'email'        => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_email', true ),
			'phone'        => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_phone', true ),
			'website'      => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_website', true ),
			'category_ids' => is_array( $cat_ids ) ? array_map( 'intval', $cat_ids ) : array(),
		);
	}

	/**
	 * Render the main column section cards.
	 *
	 * @param array<string,mixed> $d Producer data.
	 * @return void
	 */
	private static function render_main_cards( array $d ): void {
		eem_render_reservation_editor_section( array(
			'key' => 'producer-name', 'title' => __( 'Producer Name', 'equine-event-manager' ),
			'icon_tone' => 'navy', 'icon_key' => 'user', 'enable_toggle' => false,
			'body_html' => self::body_name( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'producer-details', 'title' => __( 'Contact Details', 'equine-event-manager' ),
			'icon_tone' => 'blue', 'icon_key' => 'info', 'enable_toggle' => false,
			'body_html' => self::body_details( $d ),
		) );
		eem_render_reservation_editor_section( array(
			'key' => 'producer-description', 'title' => __( 'Description', 'equine-event-manager' ),
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
				<input type="text" name="producer[title]" value="<?php echo esc_attr( $d['title'] ); ?>" class="regular-text" />
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Body HTML for the Contact Details section card.
	 */
	private static function body_details( array $d ): string {
		ob_start();
		?>
		<div class="eem-event-editor-grid">
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Primary Contact', 'equine-event-manager' ); ?></span>
				<input type="text" name="producer[contact_name]" value="<?php echo esc_attr( $d['contact_name'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></span>
				<input type="email" name="producer[email]" value="<?php echo esc_attr( $d['email'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?></span>
				<input type="text" name="producer[phone]" value="<?php echo esc_attr( $d['phone'] ); ?>" class="regular-text" />
			</label>
			<label class="eem-event-editor-field">
				<span class="eem-event-editor-field__label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></span>
				<input type="url" name="producer[website]" value="<?php echo esc_attr( $d['website'] ); ?>" class="regular-text" />
			</label>
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
				<textarea name="producer[description]" rows="6" class="large-text"><?php echo esc_textarea( $d['description'] ); ?></textarea>
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the right rail (Publish + Categories).
	 *
	 * @param array<string,mixed> $d Producer data.
	 * @return void
	 */
	private static function render_rail( array $d ): void {
		$terms = get_terms( array( 'taxonomy' => 'en_producer_category', 'hide_empty' => false ) );
		?>
		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Publish', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<button type="button" class="eem-btn eem-btn-secondary eem-rail-btn" data-eem-action="producer-editor-save" data-save-kind="save_draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
				<div class="eem-rail-divider"></div>
				<div class="eem-publish-row"><?php esc_html_e( 'Status:', 'equine-event-manager' ); ?> <strong id="eem-producer-status-label"><?php echo 'publish' === $d['status'] ? esc_html__( 'Published', 'equine-event-manager' ) : esc_html__( 'Draft', 'equine-event-manager' ); ?></strong></div>
				<div class="eem-rail-divider"></div>
				<button type="button" class="eem-btn eem-btn-electric eem-rail-btn" data-eem-action="producer-editor-save" data-save-kind="publish"><?php echo 'publish' === $d['status'] ? esc_html__( 'Update Producer', 'equine-event-manager' ) : esc_html__( 'Publish Producer', 'equine-event-manager' ); ?></button>
			</div>
		</div>

		<div class="eem-rail-card">
			<div class="eem-rail-header"><span class="eem-rail-title"><?php esc_html_e( 'Producer Categories', 'equine-event-manager' ); ?></span></div>
			<div class="eem-rail-body">
				<div class="eem-checklist">
					<?php if ( is_wp_error( $terms ) || empty( $terms ) ) : ?>
						<span class="eem-checklist-empty"><?php esc_html_e( 'No categories yet.', 'equine-event-manager' ); ?></span>
					<?php else : ?>
						<?php foreach ( $terms as $t ) : ?>
							<label class="eem-checklist-item"<?php echo (int) $t->parent > 0 ? ' style="padding-left:14px;"' : ''; ?>>
								<input type="checkbox" name="producer[categories][]" value="<?php echo esc_attr( (string) $t->term_id ); ?>" <?php checked( in_array( (int) $t->term_id, $d['category_ids'], true ) ); ?> />
								<?php echo ( (int) $t->parent > 0 ? '— ' : '' ) . esc_html( $t->name ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div class="eem-checklist-footer"><a class="eem-add-term-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'equine-event-manager-producer-categories' ), admin_url( 'admin.php' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '+ Add New Category', 'equine-event-manager' ); ?></a></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline JS for save button.
	 *
	 * @return void
	 */
	private static function print_editor_js(): void {
		?>
		<script id="eem-producer-editor-js">
		(function () {
			var form = document.getElementById('eem-producer-editor-form');
			if (!form) { return; }

			function collect() {
				var data = { action: 'eem_producer_editor_save', nonce: form.dataset.nonce, producer_id: form.dataset.producerId };
				form.querySelectorAll('[name^="producer["]').forEach(function (el) {
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
							var sl = document.getElementById('eem-producer-status-label');
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
				if (!t) { return; }
				var action = t.dataset.eemAction;
				if (action === 'producer-editor-save') {
					ev.preventDefault();
					save(t.dataset.saveKind || 'save_draft', t);
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: persist producer data.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$producer_id = isset( $_POST['producer_id'] ) ? absint( wp_unslash( $_POST['producer_id'] ) ) : 0;
		$post        = $producer_id > 0 ? get_post( $producer_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Producer not found.', 'equine-event-manager' ) ), 404 );
		}

		$raw       = isset( $_POST['producer'] ) && is_array( $_POST['producer'] ) ? wp_unslash( $_POST['producer'] ) : array();
		$save_kind = isset( $_POST['save_kind'] ) ? sanitize_key( wp_unslash( $_POST['save_kind'] ) ) : 'save_draft';
		$status    = 'publish' === $save_kind ? 'publish' : 'draft';

		$title = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		if ( 'publish' === $status && '' === trim( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'A producer name is required to publish.', 'equine-event-manager' ) ), 422 );
		}

		wp_update_post( array(
			'ID'           => $producer_id,
			'post_title'   => $title,
			'post_content' => isset( $raw['description'] ) ? wp_kses_post( $raw['description'] ) : '',
			'post_status'  => $status,
		) );

		// Contact fields.
		$contact_name = isset( $raw['contact_name'] ) ? sanitize_text_field( $raw['contact_name'] ) : '';
		$email        = isset( $raw['email'] ) ? sanitize_email( $raw['email'] ) : '';
		$phone        = isset( $raw['phone'] ) ? sanitize_text_field( $raw['phone'] ) : '';
		$website      = isset( $raw['website'] ) ? esc_url_raw( $raw['website'] ) : '';

		update_post_meta( $producer_id, '_equine_event_manager_producer_contact_name', $contact_name );
		update_post_meta( $producer_id, '_equine_event_manager_producer_email', $email );
		update_post_meta( $producer_id, '_equine_event_manager_producer_phone', $phone );
		update_post_meta( $producer_id, '_equine_event_manager_producer_website', $website );

		// Categories.
		$cat_ids = isset( $raw['categories'] ) && is_array( $raw['categories'] ) ? array_map( 'absint', $raw['categories'] ) : array();
		wp_set_object_terms( $producer_id, $cat_ids, 'en_producer_category', false );

		wp_send_json_success( array(
			'message' => __( 'Producer saved.', 'equine-event-manager' ),
			'status'  => $status,
		) );
	}
}
