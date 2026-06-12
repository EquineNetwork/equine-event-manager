<?php
/**
 * EEM_Entries — the "Entries" feature (v1).
 *
 * Each Entry is a first-class, reservation-linked record carrying a Description
 * plus a list of purchasable line items (title / inventory / max-per-customer /
 * price). Entries are managed under Event Manager → Orders → Entries through a
 * custom editor that MIRRORS the Edit Reservation experience: the admin searches
 * for an event, connects it, then fills the Description + the Pre-Entries
 * line-items card. On the customer event page the items surface as another
 * purchasable card and fold into the reservation order at checkout.
 *
 * Linkage: an Entry points at a RESERVATION (the plugin's per-event handle), so
 * the customer resolver {@see self::get_for_reservation()} is a trivial direct
 * match and we side-step the reservation event-typeahead's one-to-one filter
 * (which would hide events that already have a reservation — exactly the events
 * an Entry needs). Entries therefore use their own event search that returns
 * reservations-as-events without that exclusion.
 *
 * Named generically ("Entries", not "Pre-Entries") so the concept can later grow
 * into contestant entries (disciplines/fees) without a rename.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries CPT + custom styled editor + reservation-scoped resolver.
 */
class EEM_Entries {

	/** @var string The Entry custom post type. */
	const POST_TYPE = 'en_entry';

	/** @var string Custom editor page slug (hidden submenu, like the reservation editor). */
	const EDITOR_SLUG = 'equine-event-manager-entry-editor';

	/** @var string Custom list page slug (visible submenu, like the reservations list). */
	const LIST_SLUG = 'equine-event-manager-entries';

	/** @var string Linked-reservation meta key. */
	const META_RESERVATION = '_en_entry_reservation_id';

	/** @var string Description meta key (string). */
	const META_DESCRIPTION = '_en_entry_description';

	/** @var string Line-items meta key (array of {title,price,inventory,max_per_customer}). */
	const META_ITEMS = '_en_entry_items';

	/**
	 * Wire the CPT, custom editor route, redirects, AJAX handlers and list columns.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		// Priority 30: after EEM_Admin::register_menu (priority 20) creates the
		// Orders parent menu, but before normalize_event_manager_submenu_order
		// (priority 1001) reorders the submenu per $preferred_order. This ordering
		// is what lets the Entries list submenu attach AND land below Orders.
		add_action( 'admin_menu', array( __CLASS__, 'register_routes' ), 30 );
		add_action( 'current_screen', array( __CLASS__, 'maybe_redirect_old_list' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'maybe_redirect_new_entry' ) );
		add_action( 'load-post.php', array( __CLASS__, 'maybe_redirect_legacy_edit' ) );
		add_action( 'wp_ajax_eem_entry_editor_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_eem_entry_search_events', array( __CLASS__, 'ajax_search_events' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	/**
	 * Register the `en_entry` post type under the Orders menu. No `editor`
	 * support — the custom editor at {@see self::EDITOR_SLUG} replaces the WP
	 * meta-box screen (the classic new/edit URLs redirect there).
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Entries', 'equine-event-manager' ),
					'singular_name'      => __( 'Entry', 'equine-event-manager' ),
					'add_new'            => __( 'Add Entry', 'equine-event-manager' ),
					'add_new_item'       => __( 'Add Entry', 'equine-event-manager' ),
					'edit_item'          => __( 'Edit Entry', 'equine-event-manager' ),
					'new_item'           => __( 'New Entry', 'equine-event-manager' ),
					'view_item'          => __( 'View Entry', 'equine-event-manager' ),
					'search_items'       => __( 'Search Entries', 'equine-event-manager' ),
					'not_found'          => __( 'No entries yet.', 'equine-event-manager' ),
					'not_found_in_trash' => __( 'No entries in Trash.', 'equine-event-manager' ),
					'all_items'          => __( 'Entries', 'equine-event-manager' ),
					'menu_name'          => __( 'Entries', 'equine-event-manager' ),
				),
				'public'          => false,
				'show_ui'         => true,
				// Hidden from the menu — the custom styled list page (LIST_SLUG)
				// is the menu entry; the WP CPT list redirects to it.
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array( 'create_posts' => 'manage_options' ),
				'hierarchical'    => false,
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Register the visible Entries list submenu (under Orders) + the hidden
	 * custom-editor route, mirroring the reservations list + editor pair.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Visible list page under the Orders menu.
		add_submenu_page(
			'equine-event-manager-orders',
			__( 'Entries', 'equine-event-manager' ),
			__( 'Entries', 'equine-event-manager' ),
			'manage_options',
			self::LIST_SLUG,
			array( __CLASS__, 'render_list' )
		);

		// Hidden editor route (parent '' — routable but not a nav entry).
		add_submenu_page(
			'',
			__( 'Edit Entry', 'equine-event-manager' ),
			'',
			'manage_options',
			self::EDITOR_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The styled Entries list page URL.
	 *
	 * @return string
	 */
	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . self::LIST_SLUG );
	}

	/**
	 * Redirect the WP CPT list (`edit.php?post_type=en_entry`) to the styled
	 * custom list page. Wired to `current_screen`.
	 *
	 * @param mixed $screen Current screen object.
	 * @return void
	 */
	public static function maybe_redirect_old_list( $screen ): void {
		if ( ! is_object( $screen ) || 'edit-' . self::POST_TYPE !== $screen->id || 'edit' !== $screen->base ) {
			return;
		}
		wp_safe_redirect( self::list_url(), 302 );
		exit;
	}

	/**
	 * Render the styled Entries list (mirrors the Reservations list chrome:
	 * Equine breadcrumb + header + "+ New Entry" button + bordered card table).
	 *
	 * @return void
	 */
	public static function render_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		$entries = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		eem_render_page_open( array(
			'title'      => __( 'Entries', 'equine-event-manager' ),
			'subtitle'   => __( 'Purchasable entry items connected to an event. Each entry surfaces as a card on that event\'s customer reservation page and folds into the order at checkout.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Entries', 'equine-event-manager' ) ),
			),
			'actions'    => sprintf(
				'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
				esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ),
				esc_html__( 'New Entry', 'equine-event-manager' )
			),
			'wrap'       => true,
		) );
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Entry', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Items', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="5" class="eem-table-empty">
							<?php
							printf(
								/* translators: %s: New Entry link */
								esc_html__( 'No entries yet. %s to create your first one.', 'equine-event-manager' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Add an entry', 'equine-event-manager' ) . '</a>'
							);
							?>
						</td></tr>
					<?php else : ?>
						<?php foreach ( $entries as $e ) :
							$edit_url = self::editor_url( (int) $e->ID );
							$rid      = (int) get_post_meta( $e->ID, self::META_RESERVATION, true );
							$event    = $rid > 0 ? self::reservation_label( $rid )['title'] : '';
							$items    = get_post_meta( $e->ID, self::META_ITEMS, true );
							$count    = is_array( $items ) ? count( $items ) : 0;
							$status   = get_post_status( $e );
							$is_pub   = ( 'publish' === $status );
							?>
							<tr>
								<td><a class="eem-res-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( '' !== $event ? $event : get_the_title( $e ) ); ?></a></td>
								<td><?php echo $event ? esc_html( $event ) : '<span class="eem-orders-count is-zero">' . esc_html__( '— not connected —', 'equine-event-manager' ) . '</span>'; ?></td>
								<td><?php echo esc_html( (string) $count ); ?></td>
								<td><span class="eem-res-status eem-res-status--<?php echo $is_pub ? 'active' : 'draft'; ?>"><?php echo esc_html( $is_pub ? __( 'Published', 'equine-event-manager' ) : __( 'Draft', 'equine-event-manager' ) ); ?></span></td>
								<td><a class="eem-btn eem-btn-sm" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		eem_render_page_close( array( 'wrap' => true ) );
	}

	/**
	 * Editor URL for an entry id (0 → a fresh draft is created on first visit).
	 *
	 * @param int $entry_id Entry post id.
	 * @return string
	 */
	public static function editor_url( int $entry_id ): string {
		$base = admin_url( 'admin.php?page=' . self::EDITOR_SLUG );
		return $entry_id > 0 ? $base . '&entry_id=' . $entry_id : $base;
	}

	/**
	 * Intercept `post-new.php?post_type=en_entry`: create a draft Entry and
	 * redirect to the custom editor. Mirrors the reservation editor.
	 *
	 * @return void
	 */
	public static function maybe_redirect_new_entry(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['post_type'] ) || self::POST_TYPE !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$new_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'draft',
			'post_title'  => __( 'New Entry', 'equine-event-manager' ),
		) );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return;
		}

		wp_safe_redirect( self::editor_url( (int) $new_id ), 302 );
		exit;
	}

	/**
	 * Redirect the legacy `post.php?post=N&action=edit` URL for en_entry posts
	 * to the custom editor.
	 *
	 * @return void
	 */
	public static function maybe_redirect_legacy_edit(): void {
		$url = self::resolve_legacy_edit_redirect_url();
		if ( null === $url ) {
			return;
		}
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Pure resolver for the legacy-edit redirect (split out so smokes can verify
	 * the target without triggering exit).
	 *
	 * @return string|null
	 */
	public static function resolve_legacy_edit_redirect_url(): ?string {
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
		if ( $post_id <= 0 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}
		return self::editor_url( $post_id );
	}

	/**
	 * Resolve a reservation's display label (its linked event title + dates) for
	 * the editor header + the search results.
	 *
	 * @param int $reservation_id Reservation id.
	 * @return array{title:string,dates:string}
	 */
	private static function reservation_label( int $reservation_id ): array {
		$title = '';
		$dates = '';
		if ( $reservation_id > 0 ) {
			$title = (string) get_the_title( $reservation_id );
			if ( class_exists( 'EEM_Reservation_Source_Resolver' ) && class_exists( 'EEM_Dashboard_Repo' ) ) {
				$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
				if ( ! empty( $fields['title'] ) ) {
					$title = (string) $fields['title'];
				}
				$dates = EEM_Dashboard_Repo::format_date_range(
					isset( $fields['start_date'] ) ? (string) $fields['start_date'] : '',
					isset( $fields['end_date'] ) ? (string) $fields['end_date'] : ''
				);
			}
		}
		return array( 'title' => $title, 'dates' => $dates );
	}

	/**
	 * Render the custom Entry editor (mirrors the reservation editor chrome).
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// Shared editor helpers: breadcrumb, field-row, and the section-card
		// skeleton (icon chip + padded header + body) used by the reservation
		// editor — reused so the entry cards match it exactly.
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_breadcrumb.php';
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-skeleton.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$post = $entry_id > 0 ? get_post( $entry_id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			echo '<div class="eem-page"><div class="eem-plugin-wrap"><header class="eem-plugin-header"><h1 class="eem-plugin-title">' . esc_html__( 'Entry not found', 'equine-event-manager' ) . '</h1></header><p style="padding:20px">' . esc_html__( 'That entry could not be loaded.', 'equine-event-manager' ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Back to Entries', 'equine-event-manager' ) . '</a></p></div></div>';
			return;
		}

		$reservation_id = (int) get_post_meta( $entry_id, self::META_RESERVATION, true );
		$description    = (string) get_post_meta( $entry_id, self::META_DESCRIPTION, true );
		$items          = get_post_meta( $entry_id, self::META_ITEMS, true );
		$items          = is_array( $items ) ? $items : array();

		$label            = self::reservation_label( $reservation_id );
		$has_linked_event = ( $reservation_id > 0 && '' !== $label['title'] );
		$header_title     = $has_linked_event ? $label['title'] : __( 'New Entry', 'equine-event-manager' );

		$search_nonce = wp_create_nonce( 'eem_entry_search' );
		$save_nonce   = wp_create_nonce( 'eem_entry_editor' );
		$status       = get_post_status( $post );
		$is_published = ( 'publish' === $status );

		$fmt_money = static function ( $v ) {
			return number_format( (float) $v, 2, '.', '' );
		};
		?>
		<div class="eem-page" data-eem-entry-editor data-entry-id="<?php echo esc_attr( (string) $entry_id ); ?>">
			<?php
			eem_render_breadcrumb( array(
				array(
					'label' => __( 'Entries', 'equine-event-manager' ),
					'url'   => admin_url( 'edit.php?post_type=' . self::POST_TYPE ),
				),
				array( 'label' => $header_title ),
			) );
			?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<div class="eem-res-name-edit-wrap">
							<div class="eem-res-name-view">
								<?php if ( $has_linked_event ) : ?>
									<span class="eem-res-name-eyebrow"><?php esc_html_e( 'Entry for', 'equine-event-manager' ); ?></span>
								<?php endif; ?>
								<h1 class="eem-plugin-title" id="eem-entry-header-name"><?php echo esc_html( $header_title ); ?></h1>
							</div>
						</div>
						<div class="eem-plugin-header-meta" id="eem-entry-header-meta"><?php
							echo esc_html__( 'Editing Entry', 'equine-event-manager' ) . ' #' . esc_html( (string) $entry_id );
							if ( '' !== $label['dates'] ) {
								echo ' &nbsp;&middot;&nbsp; ' . esc_html( $label['dates'] );
							}
						?></div>

						<input type="hidden" id="eem-entry-reservation-input" value="<?php echo esc_attr( (string) $reservation_id ); ?>">

						<div class="eem-header-typeahead" id="eem-entry-typeahead"
							style="<?php echo $has_linked_event ? 'display:none;' : ''; ?>"
							data-current-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"
							data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
							data-search-nonce="<?php echo esc_attr( $search_nonce ); ?>">
							<input type="text" class="eem-event-search-input" id="eem-entry-event-search-input"
								placeholder="<?php esc_attr_e( "Search events\xe2\x80\xa6", 'equine-event-manager' ); ?>"
								data-eem-input-action="entry-filter-events" autocomplete="off">
							<div class="eem-event-search-results" id="eem-entry-event-search-results"></div>
							<?php if ( $has_linked_event ) : ?>
								<button type="button" class="eem-header-typeahead-cancel" data-eem-action="entry-cancel-change">
									<?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
					<?php if ( $has_linked_event ) : ?>
						<div class="eem-header-actions">
							<button type="button" class="eem-header-action-change" data-eem-action="entry-change-event">
								<?php esc_html_e( 'Change Event', 'equine-event-manager' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</header>

				<div class="eem-edit-body">
					<main class="eem-edit-main">
						<?php if ( ! $has_linked_event ) : ?>
							<div class="eem-reservation-link-gate" role="status" id="eem-entry-link-gate">
								<div class="eem-reservation-link-gate__icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
								</div>
								<h2 class="eem-reservation-link-gate__title"><?php esc_html_e( 'Connect an event to get started', 'equine-event-manager' ); ?></h2>
								<p class="eem-reservation-link-gate__text">
									<?php esc_html_e( 'Search for the event in the box above and choose it. The entry takes its name from the connected event and its items appear on that event\'s customer reservation page.', 'equine-event-manager' ); ?>
								</p>
							</div>
						<?php endif; ?>

						<?php if ( $has_linked_event ) : ?>
						<?php
						// Build each card's body, then render through the shared
						// reservation-editor section helper so the Entry cards get the
						// SAME chrome as Edit Reservation (icon chip + padded header).

						// Description card body.
						ob_start();
						eem_render_editor_field_row( array(
							'label'        => __( 'Description', 'equine-event-manager' ),
							'label_sub'    => __( 'Optional intro shown above the entry items on the customer page', 'equine-event-manager' ),
							'control_html' => '<textarea class="eem-field-input" id="eem-entry-description" rows="3" placeholder="' . esc_attr__( 'e.g. Pre-purchase your class entries below.', 'equine-event-manager' ) . '">' . esc_textarea( $description ) . '</textarea>',
						) );
						$eem_desc_body = (string) ob_get_clean();

						// Entry Items card body.
						ob_start();
						?>
								<table class="eem-repeat-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Event Title', 'equine-event-manager' ); ?></th>
											<th style="width:110px"><?php esc_html_e( 'Inventory', 'equine-event-manager' ); ?></th>
											<th style="width:120px"><?php esc_html_e( 'Max Per Customer', 'equine-event-manager' ); ?></th>
											<th style="width:140px"><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></th>
											<th style="width:40px"></th>
										</tr>
									</thead>
									<tbody id="eem-entry-items-list">
										<?php foreach ( $items as $ii => $item ) :
											$i_title = isset( $item['title'] ) ? (string) $item['title'] : '';
											$i_inv   = ( isset( $item['inventory'] ) && (int) $item['inventory'] > 0 ) ? (string) (int) $item['inventory'] : '';
											$i_max   = ( isset( $item['max_per_customer'] ) && (int) $item['max_per_customer'] > 0 ) ? (string) (int) $item['max_per_customer'] : '';
											$i_price = isset( $item['price'] ) ? $fmt_money( $item['price'] ) : '0.00';
											?>
											<tr>
												<td><input class="eem-repeat-input" type="text" name="eem_entry_items[<?php echo (int) $ii; ?>][title]" value="<?php echo esc_attr( $i_title ); ?>"></td>
												<td><input class="eem-repeat-input" type="number" min="0" style="width:90px" name="eem_entry_items[<?php echo (int) $ii; ?>][inventory]" value="<?php echo esc_attr( $i_inv ); ?>" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>"></td>
												<td><input class="eem-repeat-input" type="number" min="1" step="1" style="width:90px" name="eem_entry_items[<?php echo (int) $ii; ?>][max_per_customer]" value="<?php echo esc_attr( $i_max ); ?>" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>"></td>
												<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" min="0" step="0.01" name="eem_entry_items[<?php echo (int) $ii; ?>][price]" value="<?php echo esc_attr( $i_price ); ?>"></div></td>
												<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<button class="eem-btn-add" type="button"
									data-eem-action="reservation-editor-add-repeating-row"
									data-eem-repeating-template="eem-entry-item-row-template"
									data-eem-repeating-tbody="eem-entry-items-list">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
									<?php esc_html_e( 'Add Item', 'equine-event-manager' ); ?>
								</button>
								<template id="eem-entry-item-row-template"><tr>
									<td><input class="eem-repeat-input" type="text" name="eem_entry_items[__index__][title]" value="" placeholder="<?php esc_attr_e( 'Entry title', 'equine-event-manager' ); ?>"></td>
									<td><input class="eem-repeat-input" type="number" min="0" style="width:90px" name="eem_entry_items[__index__][inventory]" value="" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>"></td>
									<td><input class="eem-repeat-input" type="number" min="1" step="1" style="width:90px" name="eem_entry_items[__index__][max_per_customer]" value="" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>"></td>
									<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" min="0" step="0.01" name="eem_entry_items[__index__][price]" value="0.00"></div></td>
									<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
								</tr></template>
						<?php
						$eem_items_body = (string) ob_get_clean();

						// Render both cards through the shared section helper.
						eem_render_reservation_editor_section( array(
							'key'           => 'entry-description',
							'title'         => __( 'Description', 'equine-event-manager' ),
							'icon_tone'     => 'blue',
							'icon_key'      => 'file-text',
							'enable_toggle' => false,
							'collapsed'     => false,
							'body_html'     => $eem_desc_body,
						) );
						eem_render_reservation_editor_section( array(
							'key'           => 'entry-items',
							'title'         => __( 'Entry Items', 'equine-event-manager' ),
							'icon_tone'     => 'green',
							'icon_key'      => 'package',
							'enable_toggle' => false,
							'collapsed'     => false,
							'body_html'     => $eem_items_body,
						) );
						?>
						<?php endif; ?>
					</main>
				</div>
			</div>
		</div>

		<!-- Sticky save bar (entry-editor actions) -->
		<div class="eem-sticky-save" id="eem-entry-sticky-save"
			data-entry-id="<?php echo esc_attr( (string) $entry_id ); ?>"
			data-save-nonce="<?php echo esc_attr( $save_nonce ); ?>">
			<div class="eem-sticky-save-status eem-sticky-save-status--<?php echo $is_published ? 'published' : 'draft'; ?>">
				<span class="eem-sticky-save-dot" aria-hidden="true"></span>
				<span data-eem-entry-status><?php echo esc_html( $is_published ? __( 'Published', 'equine-event-manager' ) : __( 'Draft', 'equine-event-manager' ) ); ?></span>
			</div>
			<div class="eem-sticky-save-spacer"></div>
			<div class="eem-sticky-save-actions">
				<button type="button" class="eem-btn-save-draft" data-eem-action="entry-editor-save-draft">
					<span><?php esc_html_e( 'Save as Draft', 'equine-event-manager' ); ?></span>
				</button>
				<button type="button" class="eem-btn-update" data-eem-action="entry-editor-publish">
					<?php echo esc_html( $is_published ? __( 'Update Entry', 'equine-event-manager' ) : __( 'Publish Entry', 'equine-event-manager' ) ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: search reservations (as events) for the entry event-connect typeahead.
	 * Returns reservations-as-events WITHOUT the one-to-one exclusion that the
	 * reservation editor's event search applies.
	 *
	 * @return void
	 */
	public static function ajax_search_events(): void {
		check_ajax_referer( 'eem_entry_search', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		$reservations = get_posts( array(
			'post_type'      => EEM_Reservations_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $term,
		) );

		$results = array();
		foreach ( $reservations as $r ) {
			$label     = self::reservation_label( (int) $r->ID );
			$results[] = array(
				'id'         => (string) $r->ID,
				'text'       => '' !== $label['title'] ? $label['title'] : get_the_title( $r ),
				'start_date' => '',
				'dates'      => $label['dates'],
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: persist the entry (description + linked reservation + items). The
	 * post_title inherits the connected event title so the list shows it.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		check_ajax_referer( 'eem_entry_editor', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;
		if ( $entry_id <= 0 || self::POST_TYPE !== get_post_type( $entry_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'equine-event-manager' ) ), 404 );
		}

		$save_kind      = isset( $_POST['save_kind'] ) ? sanitize_key( wp_unslash( $_POST['save_kind'] ) ) : 'publish';
		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$description    = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		// Validate the linked reservation.
		if ( $reservation_id > 0 && EEM_Reservations_CPT::POST_TYPE !== get_post_type( $reservation_id ) ) {
			$reservation_id = 0;
		}
		if ( 'publish' === $save_kind && $reservation_id <= 0 ) {
			wp_send_json_error( array(
				'message' => __( 'Connect an event before publishing this entry.', 'equine-event-manager' ),
				'code'    => 'entry_requires_event',
			), 422 );
		}

		$result = self::save_entry_fields(
			$entry_id,
			$reservation_id,
			$description,
			isset( $_POST['eem_entry_items'] ) ? wp_unslash( $_POST['eem_entry_items'] ) : array(),
			$save_kind
		);

		wp_send_json_success( array(
			'message'  => ( 'publish' === $save_kind ) ? __( 'Entry published.', 'equine-event-manager' ) : __( 'Draft saved.', 'equine-event-manager' ),
			'entry_id' => $entry_id,
			'title'    => $result['title'],
		) );
	}

	/**
	 * Persist an entry's fields (description + reservation + sanitized items) and
	 * sync its title (inherits the connected event title) + status. Split out of
	 * {@see self::ajax_save()} so the write path is testable without wp_die().
	 *
	 * @param int    $entry_id       Entry post id.
	 * @param int    $reservation_id Connected reservation id (0 = none).
	 * @param string $description    Sanitized description.
	 * @param mixed  $raw_items      Raw posted `eem_entry_items` value.
	 * @param string $save_kind      'publish' | 'save_draft'.
	 * @return array{title:string,status:string,items:array<int,array<string,mixed>>}
	 */
	public static function save_entry_fields( int $entry_id, int $reservation_id, string $description, $raw_items, string $save_kind ): array {
		$items = self::sanitize_items( $raw_items );

		update_post_meta( $entry_id, self::META_RESERVATION, $reservation_id );
		update_post_meta( $entry_id, self::META_DESCRIPTION, $description );
		update_post_meta( $entry_id, self::META_ITEMS, $items );

		$label     = self::reservation_label( $reservation_id );
		$new_title = '' !== $label['title'] ? $label['title'] : __( 'New Entry', 'equine-event-manager' );
		$status    = ( 'publish' === $save_kind ) ? 'publish' : 'draft';
		wp_update_post( array(
			'ID'          => $entry_id,
			'post_title'  => $new_title,
			'post_status' => $status,
		) );

		return array( 'title' => $new_title, 'status' => $status, 'items' => $items );
	}

	/**
	 * Sanitize the posted line-items array into the canonical item shape.
	 *
	 * @param mixed $raw Posted `eem_entry_items` value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_items( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			if ( '' === trim( $title ) ) {
				continue; // drop blank rows.
			}
			$out[] = array(
				'title'            => $title,
				'price'            => number_format( (float) ( isset( $row['price'] ) ? $row['price'] : 0 ), 2, '.', '' ),
				'inventory'        => isset( $row['inventory'] ) ? absint( $row['inventory'] ) : 0,
				'max_per_customer' => isset( $row['max_per_customer'] ) ? absint( $row['max_per_customer'] ) : 0,
			);
		}
		return $out;
	}

	/**
	 * List-table columns: Event + item count alongside the title.
	 *
	 * @param array<string,string> $columns Default columns.
	 * @return array<string,string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['eem_entry_event'] = __( 'Event', 'equine-event-manager' );
				$out['eem_entry_items'] = __( 'Items', 'equine-event-manager' );
			}
		}
		return $out;
	}

	/**
	 * Render a custom list-table column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Entry post id.
	 * @return void
	 */
	public static function column_value( string $column, int $post_id ): void {
		if ( 'eem_entry_event' === $column ) {
			$rid = (int) get_post_meta( $post_id, self::META_RESERVATION, true );
			echo $rid > 0 ? esc_html( get_the_title( $rid ) ) : '<span style="color:#b91c1c">' . esc_html__( '— not connected —', 'equine-event-manager' ) . '</span>';
		} elseif ( 'eem_entry_items' === $column ) {
			$items = get_post_meta( $post_id, self::META_ITEMS, true );
			echo esc_html( (string) ( is_array( $items ) ? count( $items ) : 0 ) );
		}
	}

	/**
	 * Resolve published Entries linked to a reservation into the customer-pipeline
	 * option shape (keyed `entry_{postID}_{itemIndex}`, each with
	 * title/price/inventory/max_per_customer). Each Entry post expands into one
	 * option per line item. Consumed by the customer-page render, pricing matrix,
	 * checkout validation, totals and order notes.
	 *
	 * @param int $reservation_id Reservation (event) id.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_for_reservation( $reservation_id ): array {
		$reservation_id = absint( $reservation_id );
		if ( $reservation_id <= 0 ) {
			return array();
		}

		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin-defined set.
				array(
					'key'   => self::META_RESERVATION,
					'value' => $reservation_id,
				),
			),
		) );

		$options = array();
		foreach ( $posts as $p ) {
			$items = get_post_meta( $p->ID, self::META_ITEMS, true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $idx => $item ) {
				$title = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
				if ( '' === $title ) {
					continue;
				}
				$options[ 'entry_' . $p->ID . '_' . (int) $idx ] = array(
					'title'            => $title,
					'price'            => number_format( (float) ( isset( $item['price'] ) ? $item['price'] : 0 ), 2, '.', '' ),
					'inventory'        => isset( $item['inventory'] ) ? absint( $item['inventory'] ) : 0,
					'max_per_customer' => isset( $item['max_per_customer'] ) ? absint( $item['max_per_customer'] ) : 0,
				);
			}
		}

		return $options;
	}
}
