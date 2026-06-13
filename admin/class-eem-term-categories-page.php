<?php
/**
 * Branded taxonomy "Categories" admin page (Native Events Admin B).
 *
 * Replaces the default WordPress `edit-tags.php` screen for the plugin's three
 * hierarchical category taxonomies — Event / Venue / Producer Categories — with
 * a single mockup-faithful page (`.mockups/taxonomy_categories_admin_page.html`):
 * a left "Add / Edit Category" form panel and a right sortable, searchable term
 * table with a per-row action dropdown and bulk delete.
 *
 * The class is taxonomy-agnostic: one render path parametrized by the current
 * admin page slug via {@see EEM_Term_Categories_Page::pages()}. Three distinct
 * menu slugs each bind to {@see EEM_Term_Categories_Page::render()}; the slug in
 * `$_GET['page']` selects the taxonomy config.
 *
 * Add / edit / delete / bulk-delete run through `admin-post.php` handlers (so the
 * Post/Redirect/Get pattern can issue a clean redirect with a notice before any
 * output), all capability- and nonce-gated.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Term-categories management page controller.
 */
class EEM_Term_Categories_Page {

	/**
	 * Capability required for every read and write path on this page.
	 *
	 * @var string
	 */
	const CAP = 'manage_options';

	/**
	 * Per-page configuration keyed by admin menu slug.
	 *
	 * Each entry: taxonomy slug, the object post type the taxonomy attaches to,
	 * the page title + subtitle, and the plural object label used in the
	 * "View {Objects}" row-action link.
	 *
	 * @return array<string,array{taxonomy:string,post_type:string,title:string,subtitle:string,object_plural:string}>
	 */
	private static function pages(): array {
		return array(
			'equine-event-manager-event-categories'    => array(
				'taxonomy'      => 'en_event_category',
				'post_type'     => 'en_event',
				'title'         => __( 'Event Categories', 'equine-event-manager' ),
				'subtitle'      => __( 'Organize events into categories. Categories can be nested and assigned to events at any time.', 'equine-event-manager' ),
				'object_plural' => __( 'Events', 'equine-event-manager' ),
			),
			'equine-event-manager-venue-categories'    => array(
				'taxonomy'      => 'en_venue_category',
				'post_type'     => 'en_venue',
				'title'         => __( 'Venue Categories', 'equine-event-manager' ),
				'subtitle'      => __( 'Organize venues into categories. Categories can be nested and assigned to venues at any time.', 'equine-event-manager' ),
				'object_plural' => __( 'Venues', 'equine-event-manager' ),
			),
			'equine-event-manager-producer-categories' => array(
				'taxonomy'      => 'en_producer_category',
				'post_type'     => 'en_producer',
				'title'         => __( 'Producer Categories', 'equine-event-manager' ),
				'subtitle'      => __( 'Organize producers into categories. Categories can be nested and assigned to producers at any time.', 'equine-event-manager' ),
				'object_plural' => __( 'Producers', 'equine-event-manager' ),
			),
		);
	}

	/**
	 * All admin page slugs this controller serves.
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys( self::pages() );
	}

	/**
	 * Wire the admin-post.php write handlers. Menu pages register in EEM_Admin.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_eem_save_term', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_eem_delete_term', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_eem_bulk_delete_terms', array( __CLASS__, 'handle_bulk_delete' ) );
	}

	/**
	 * Map a taxonomy slug to the branded page slug that manages it.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string Page slug, or empty string if the taxonomy is not managed here.
	 */
	public static function slug_for_taxonomy( string $taxonomy ): string {
		foreach ( self::pages() as $slug => $cfg ) {
			if ( $cfg['taxonomy'] === $taxonomy ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Resolve the config for a given page slug (empty array if unknown).
	 *
	 * @param string $slug Admin page slug.
	 * @return array<string,string>
	 */
	private static function config_for_slug( string $slug ): array {
		$pages = self::pages();
		return isset( $pages[ $slug ] ) ? $pages[ $slug ] : array();
	}

	/**
	 * The page slug currently being requested (read-only nav param).
	 *
	 * @return string
	 */
	private static function current_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Build an admin.php URL for a page slug with optional extra query args.
	 *
	 * @param string              $slug Admin page slug.
	 * @param array<string,mixed> $args Extra query args.
	 * @return string
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Redirect back to a page slug with a notice (Post/Redirect/Get).
	 *
	 * @param string $slug   Destination page slug.
	 * @param string $notice Notice key (mapped to copy in render_notice()).
	 * @param string $type   Notice type: success|error|info.
	 * @param string $detail Optional extra detail (e.g. a WP_Error message).
	 * @return void
	 */
	private static function redirect( string $slug, string $notice, string $type, string $detail = '' ): void {
		$args = array(
			'en_notice' => $notice,
			'en_type'   => $type,
		);
		if ( '' !== $detail ) {
			$args['en_detail'] = rawurlencode( $detail );
		}
		wp_safe_redirect( self::url( $slug, $args ) );
		exit;
	}

	/**
	 * Render the page: header, notice, then the split form/table body.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$slug = self::current_slug();
		$cfg  = self::config_for_slug( $slug );
		if ( empty( $cfg ) ) {
			wp_die( esc_html__( 'Unknown categories page.', 'equine-event-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list params.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'name', 'count' ), true ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
		$order    = isset( $_GET['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'desc' : 'asc';
		$edit_id  = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$edit_term = null;
		if ( $edit_id > 0 ) {
			$maybe = get_term( $edit_id, $cfg['taxonomy'] );
			if ( $maybe instanceof WP_Term ) {
				$edit_term = $maybe;
			}
		}

		eem_render_page_open( array(
			'title'      => $cfg['title'],
			'subtitle'   => $cfg['subtitle'],
			'breadcrumb' => array( array( 'label' => $cfg['title'] ) ),
		) );

		self::render_notice();

		echo '<div class="eem-term-split">';
		self::render_form_panel( $slug, $cfg, $edit_term );
		self::render_table_panel( $slug, $cfg, $search, $orderby, $order );
		echo '</div>';

		eem_render_page_close();
	}

	/**
	 * Render the success/error notice banner from the redirect query params.
	 *
	 * @return void
	 */
	private static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a PRG notice.
		if ( empty( $_GET['en_notice'] ) ) {
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['en_notice'] ) );
		$type   = isset( $_GET['en_type'] ) && in_array( $_GET['en_type'], array( 'success', 'error', 'info' ), true ) ? sanitize_key( wp_unslash( $_GET['en_type'] ) ) : 'info';
		$detail = isset( $_GET['en_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['en_detail'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$map = array(
			'term_added'         => __( 'Category added.', 'equine-event-manager' ),
			'term_updated'       => __( 'Category updated.', 'equine-event-manager' ),
			'term_deleted'       => __( 'Category deleted.', 'equine-event-manager' ),
			'term_bulk_deleted'  => __( 'Categories deleted.', 'equine-event-manager' ),
			'term_name_required' => __( 'A category name is required.', 'equine-event-manager' ),
			'term_save_failed'   => __( 'The category could not be saved.', 'equine-event-manager' ),
			'term_delete_failed' => __( 'The category could not be deleted.', 'equine-event-manager' ),
			'term_bulk_none'     => __( 'Select at least one category to delete.', 'equine-event-manager' ),
		);

		$message = isset( $map[ $notice ] ) ? $map[ $notice ] : '';
		if ( '' === $message ) {
			return;
		}
		// Bulk delete: the detail string ("N categories deleted.") is the full
		// message. Save failure: append the WP_Error reason to the generic copy.
		if ( '' !== $detail && 'term_bulk_deleted' === $notice ) {
			$message = $detail;
		} elseif ( '' !== $detail && 'term_save_failed' === $notice ) {
			$message .= ' ' . $detail;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible eem-term-notice"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Left "Add / Edit Category" form panel.
	 *
	 * @param string        $slug      Page slug (round-tripped to the handler).
	 * @param array         $cfg       Page config.
	 * @param WP_Term|null  $edit_term Term being edited, or null for add mode.
	 * @return void
	 */
	private static function render_form_panel( string $slug, array $cfg, ?WP_Term $edit_term ): void {
		$is_edit = $edit_term instanceof WP_Term;
		$tree    = self::tree_for( $cfg['taxonomy'] );
		$exclude = array();
		if ( $is_edit ) {
			$exclude   = self::descendant_ids( $tree, $edit_term->term_id );
			$exclude[] = $edit_term->term_id;
		}
		?>
		<div class="eem-term-form-panel">
			<h2 class="eem-term-form-title"><?php echo $is_edit ? esc_html__( 'Edit Category', 'equine-event-manager' ) : esc_html__( 'Add New Category', 'equine-event-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eem_save_term" />
				<input type="hidden" name="eem_page" value="<?php echo esc_attr( $slug ); ?>" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $edit_term->term_id ); ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( 'eem_save_term' ); ?>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-term-name"><?php esc_html_e( 'Name', 'equine-event-manager' ); ?></label>
					<input class="eem-field-input" id="eem-term-name" name="term_name" type="text" value="<?php echo $is_edit ? esc_attr( $edit_term->name ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. Reining', 'equine-event-manager' ); ?>" required />
					<p class="eem-field-hint"><?php esc_html_e( 'The name is how it appears on your site.', 'equine-event-manager' ); ?></p>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-term-slug"><?php esc_html_e( 'Slug', 'equine-event-manager' ); ?></label>
					<input class="eem-field-input" id="eem-term-slug" name="term_slug" type="text" value="<?php echo $is_edit ? esc_attr( $edit_term->slug ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. reining', 'equine-event-manager' ); ?>" />
					<p class="eem-field-hint"><?php esc_html_e( 'The slug is the URL-friendly version of the name — lowercase letters, numbers, and hyphens only.', 'equine-event-manager' ); ?></p>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-term-parent"><?php esc_html_e( 'Parent Category', 'equine-event-manager' ); ?></label>
					<select class="eem-field-select" id="eem-term-parent" name="term_parent">
						<option value="0"><?php esc_html_e( 'None', 'equine-event-manager' ); ?></option>
						<?php self::render_parent_options( $tree, 0, 0, $is_edit ? (int) $edit_term->parent : 0, $exclude ); ?>
					</select>
					<p class="eem-field-hint"><?php esc_html_e( 'Assign a parent to create a hierarchy — e.g. Reining under Western.', 'equine-event-manager' ); ?></p>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-term-desc"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></label>
					<textarea class="eem-field-input eem-field-textarea" id="eem-term-desc" name="term_description" placeholder="<?php esc_attr_e( 'Optional description…', 'equine-event-manager' ); ?>"><?php echo $is_edit ? esc_textarea( $edit_term->description ) : ''; ?></textarea>
					<p class="eem-field-hint"><?php esc_html_e( 'The description is not shown publicly by default.', 'equine-event-manager' ); ?></p>
				</div>

				<div class="eem-term-form-actions">
					<button type="submit" class="eem-btn eem-btn-electric">
						<?php echo $is_edit ? esc_html__( 'Update Category', 'equine-event-manager' ) : esc_html__( '+ Add Category', 'equine-event-manager' ); ?>
					</button>
					<?php if ( $is_edit ) : ?>
						<a class="eem-btn eem-btn-secondary" href="<?php echo esc_url( self::url( $slug ) ); ?>"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Right table panel: toolbar (bulk + search), term table, footer.
	 *
	 * @param string $slug    Page slug.
	 * @param array  $cfg     Page config.
	 * @param string $search  Search query.
	 * @param string $orderby Sort column: name|count.
	 * @param string $order   Sort direction: asc|desc.
	 * @return void
	 */
	private static function render_table_panel( string $slug, array $cfg, string $search, string $orderby, string $order ): void {
		$rows = self::list_rows( $cfg['taxonomy'], $search, $orderby, $order );
		$count = count( $rows );
		?>
		<div class="eem-term-table-panel">
			<div class="eem-term-toolbar">
				<form class="eem-term-bulk-form" id="eem-term-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="eem_bulk_delete_terms" />
					<input type="hidden" name="eem_page" value="<?php echo esc_attr( $slug ); ?>" />
					<?php wp_nonce_field( 'eem_bulk_delete_terms' ); ?>
					<select class="eem-toolbar-select" name="bulk_action" aria-label="<?php esc_attr_e( 'Bulk actions', 'equine-event-manager' ); ?>">
						<option value=""><?php esc_html_e( 'Bulk actions', 'equine-event-manager' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></option>
					</select>
					<button type="submit" class="eem-toolbar-btn" data-eem-action="eem-term-bulk-apply"><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
				</form>

				<form class="eem-term-search-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>" />
					<span class="eem-search-wrap eem-search-wrap--attached">
						<svg class="eem-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search categories…', 'equine-event-manager' ); ?>" />
					</span>
					<button type="submit" class="eem-toolbar-btn eem-search-btn"><?php esc_html_e( 'Search', 'equine-event-manager' ); ?></button>
				</form>

				<span class="eem-item-count">
					<?php
					/* translators: %s: number of categories. */
					echo esc_html( sprintf( _n( '%s item', '%s items', $count, 'equine-event-manager' ), number_format_i18n( $count ) ) );
					?>
				</span>
			</div>

			<div class="eem-desktop-table">
				<table class="eem-table eem-term-table">
					<thead>
						<tr>
							<th class="eem-col-cb"><input type="checkbox" data-eem-action="eem-term-toggle-all" aria-label="<?php esc_attr_e( 'Select all', 'equine-event-manager' ); ?>" /></th>
							<?php self::sortable_th( $slug, __( 'Name', 'equine-event-manager' ), 'name', $orderby, $order, $search ); ?>
							<th><?php esc_html_e( 'Slug', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></th>
							<?php self::sortable_th( $slug, __( 'Count', 'equine-event-manager' ), 'count', $orderby, $order, $search ); ?>
							<th class="eem-table-r"><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="6" class="eem-table-empty"><?php esc_html_e( 'No categories match your filters.', 'equine-event-manager' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php self::render_table_row( $slug, $cfg, $row['term'], (int) $row['depth'] ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="eem-table-footer">
				<span class="eem-table-footer-info">
					<?php
					/* translators: %s: number of categories. */
					echo esc_html( sprintf( _n( '%s category', '%s categories', $count, 'equine-event-manager' ), number_format_i18n( $count ) ) );
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single term row in the table.
	 *
	 * @param string  $slug  Page slug.
	 * @param array   $cfg   Page config.
	 * @param WP_Term $term  The term.
	 * @param int     $depth Hierarchy depth (for the name indent prefix).
	 * @return void
	 */
	private static function render_table_row( string $slug, array $cfg, WP_Term $term, int $depth ): void {
		$edit_url   = self::url( $slug, array( 'edit' => $term->term_id ) );
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'eem_delete_term',
					'term_id'  => $term->term_id,
					'eem_page' => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			'eem_delete_term_' . $term->term_id
		);
		$view_url   = add_query_arg(
			array(
				'post_type'        => $cfg['post_type'],
				$cfg['taxonomy']   => $term->slug,
			),
			admin_url( 'edit.php' )
		);
		$prefix = $depth > 0 ? str_repeat( '— ', $depth ) : '';
		$count  = (int) $term->count;
		?>
		<tr>
			<td class="eem-col-cb"><input type="checkbox" name="term_ids[]" value="<?php echo esc_attr( (string) $term->term_id ); ?>" form="eem-term-bulk-form" aria-label="<?php echo esc_attr( $term->name ); ?>" /></td>
			<td>
				<a class="eem-term-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $prefix . $term->name ); ?></a>
			</td>
			<td><span class="eem-term-slug"><?php echo esc_html( $term->slug ); ?></span></td>
			<td><?php echo '' !== $term->description ? esc_html( $term->description ) : '<span class="eem-term-muted">—</span>'; ?></td>
			<td><span class="eem-term-count<?php echo 0 === $count ? ' eem-term-count-zero' : ''; ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span></td>
			<td>
				<div class="eem-actions-cell">
					<div class="eem-row-menu-wrap">
						<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-term-menu-<?php echo esc_attr( (string) $term->term_id ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
						<div class="eem-row-dropdown" id="eem-term-menu-<?php echo esc_attr( (string) $term->term_id ); ?>" role="menu">
							<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $edit_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></a>
							<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $view_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><?php /* translators: %s: plural object label, e.g. Events. */ printf( esc_html__( 'View %s', 'equine-event-manager' ), esc_html( $cfg['object_plural'] ) ); ?></a>
							<a class="eem-row-dd-item eem-row-dd-danger" role="menuitem" href="<?php echo esc_url( $delete_url ); ?>" data-eem-action="eem-term-delete" data-term-name="<?php echo esc_attr( $term->name ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></a>
						</div>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a sortable table header cell with the sort-direction icon + link.
	 *
	 * @param string $slug    Page slug.
	 * @param string $label   Column label.
	 * @param string $key     Sort key (name|count).
	 * @param string $orderby Active sort column.
	 * @param string $order   Active sort direction.
	 * @param string $search  Current search (preserved in the sort link).
	 * @return void
	 */
	private static function sortable_th( string $slug, string $label, string $key, string $orderby, string $order, string $search ): void {
		$is_active = ( $orderby === $key );
		$next      = ( $is_active && 'asc' === $order ) ? 'desc' : 'asc';
		$args      = array( 'orderby' => $key, 'order' => $next );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$class = 'sortable' . ( $is_active ? ' is-sorted is-' . $order : '' );
		printf(
			'<th class="%1$s"><a href="%2$s">%3$s <span class="eem-sort-icon" aria-hidden="true"><span></span><span></span></span></a></th>',
			esc_attr( $class ),
			esc_url( self::url( $slug, $args ) ),
			esc_html( $label )
		);
	}

	/**
	 * Build the ordered list of rows (term + depth) for the table.
	 *
	 * Default (orderby=name) renders the full hierarchy depth-first with depth
	 * indentation. Searching or sorting by count flattens to a depth-0 list.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $search   Search query.
	 * @param string $orderby  Sort column.
	 * @param string $order    Sort direction.
	 * @return array<int,array{term:WP_Term,depth:int}>
	 */
	private static function list_rows( string $taxonomy, string $search, string $orderby, string $order ): array {
		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$flat = ( '' !== $search ) || ( 'count' === $orderby );

		if ( ! $flat ) {
			$tree = self::tree_for( $taxonomy, $terms );
			$out  = array();
			self::flatten_tree( $tree, 0, 0, $order, $out );
			return $out;
		}

		// Flat list — filter by search, sort by the chosen column.
		$rows = array();
		foreach ( $terms as $t ) {
			if ( '' !== $search && false === stripos( $t->name, $search ) && false === stripos( $t->slug, $search ) ) {
				continue;
			}
			$rows[] = $t;
		}
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $order ) {
				if ( 'count' === $orderby ) {
					$cmp = (int) $a->count <=> (int) $b->count;
				} else {
					$cmp = strcasecmp( $a->name, $b->name );
				}
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);
		return array_map(
			static function ( $t ) {
				return array( 'term' => $t, 'depth' => 0 );
			},
			$rows
		);
	}

	/**
	 * Group terms by parent id for hierarchy walking.
	 *
	 * @param string         $taxonomy Taxonomy slug.
	 * @param WP_Term[]|null $terms    Pre-fetched terms, or null to fetch.
	 * @return array<int,WP_Term[]> Parent id => child terms.
	 */
	private static function tree_for( string $taxonomy, ?array $terms = null ): array {
		if ( null === $terms ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				return array();
			}
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		return $by_parent;
	}

	/**
	 * Depth-first flatten of the term tree into ordered (term, depth) rows.
	 *
	 * @param array<int,WP_Term[]> $by_parent Parent id => children.
	 * @param int                  $parent    Parent id to descend from.
	 * @param int                  $depth     Current depth.
	 * @param string               $order     Sibling sort direction (by name).
	 * @param array                $out       Accumulator (by reference).
	 * @return void
	 */
	private static function flatten_tree( array $by_parent, int $parent, int $depth, string $order, array &$out ): void {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}
		$siblings = $by_parent[ $parent ];
		usort(
			$siblings,
			static function ( $a, $b ) use ( $order ) {
				$cmp = strcasecmp( $a->name, $b->name );
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);
		foreach ( $siblings as $t ) {
			$out[] = array( 'term' => $t, 'depth' => $depth );
			self::flatten_tree( $by_parent, (int) $t->term_id, $depth + 1, $order, $out );
		}
	}

	/**
	 * Recursively collect all descendant term ids of a term.
	 *
	 * @param array<int,WP_Term[]> $by_parent Parent id => children.
	 * @param int                  $id        Term id.
	 * @return int[]
	 */
	private static function descendant_ids( array $by_parent, int $id ): array {
		$ids = array();
		if ( ! empty( $by_parent[ $id ] ) ) {
			foreach ( $by_parent[ $id ] as $child ) {
				$ids[] = (int) $child->term_id;
				$ids   = array_merge( $ids, self::descendant_ids( $by_parent, (int) $child->term_id ) );
			}
		}
		return $ids;
	}

	/**
	 * Render hierarchical <option> rows for the parent-category select.
	 *
	 * @param array<int,WP_Term[]> $by_parent Parent id => children.
	 * @param int                  $parent    Parent id to descend from.
	 * @param int                  $depth     Current depth (for the indent).
	 * @param int                  $selected  Currently-selected parent id.
	 * @param int[]                $exclude   Term ids to skip (self + descendants).
	 * @return void
	 */
	private static function render_parent_options( array $by_parent, int $parent, int $depth, int $selected, array $exclude ): void {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}
		$siblings = $by_parent[ $parent ];
		usort(
			$siblings,
			static function ( $a, $b ) {
				return strcasecmp( $a->name, $b->name );
			}
		);
		foreach ( $siblings as $t ) {
			if ( in_array( (int) $t->term_id, $exclude, true ) ) {
				continue;
			}
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $t->term_id ),
				selected( $selected, (int) $t->term_id, false ),
				esc_html( str_repeat( '— ', $depth ) . $t->name )
			);
			self::render_parent_options( $by_parent, (int) $t->term_id, $depth + 1, $selected, $exclude );
		}
	}

	/**
	 * admin-post.php handler: insert or update a term.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'equine-event-manager' ) );
		}
		check_admin_referer( 'eem_save_term' );

		$slug = isset( $_POST['eem_page'] ) ? sanitize_key( wp_unslash( $_POST['eem_page'] ) ) : '';
		$cfg  = self::config_for_slug( $slug );
		if ( empty( $cfg ) ) {
			wp_die( esc_html__( 'Unknown categories page.', 'equine-event-manager' ) );
		}

		$name      = isset( $_POST['term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['term_name'] ) ) : '';
		$term_slug = isset( $_POST['term_slug'] ) ? sanitize_title( wp_unslash( $_POST['term_slug'] ) ) : '';
		$parent    = isset( $_POST['term_parent'] ) ? absint( wp_unslash( $_POST['term_parent'] ) ) : 0;
		$desc      = isset( $_POST['term_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['term_description'] ) ) : '';
		$term_id   = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;

		if ( '' === $name ) {
			self::redirect( $slug, 'term_name_required', 'error' );
		}

		$args = array(
			'parent'      => $parent,
			'description' => $desc,
		);
		if ( '' !== $term_slug ) {
			$args['slug'] = $term_slug;
		}

		if ( $term_id > 0 ) {
			$args['name'] = $name;
			$result       = wp_update_term( $term_id, $cfg['taxonomy'], $args );
			$ok_notice    = 'term_updated';
		} else {
			$result    = wp_insert_term( $name, $cfg['taxonomy'], $args );
			$ok_notice = 'term_added';
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( $slug, 'term_save_failed', 'error', $result->get_error_message() );
		}
		self::redirect( $slug, $ok_notice, 'success' );
	}

	/**
	 * admin-post.php handler: delete a single term.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'equine-event-manager' ) );
		}
		$term_id = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0;
		$slug    = isset( $_GET['eem_page'] ) ? sanitize_key( wp_unslash( $_GET['eem_page'] ) ) : '';
		check_admin_referer( 'eem_delete_term_' . $term_id );

		$cfg = self::config_for_slug( $slug );
		if ( empty( $cfg ) || $term_id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'equine-event-manager' ) );
		}

		$result = wp_delete_term( $term_id, $cfg['taxonomy'] );
		if ( true === $result ) {
			self::redirect( $slug, 'term_deleted', 'success' );
		}
		self::redirect( $slug, 'term_delete_failed', 'error' );
	}

	/**
	 * admin-post.php handler: bulk-delete selected terms.
	 *
	 * @return void
	 */
	public static function handle_bulk_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'equine-event-manager' ) );
		}
		check_admin_referer( 'eem_bulk_delete_terms' );

		$slug = isset( $_POST['eem_page'] ) ? sanitize_key( wp_unslash( $_POST['eem_page'] ) ) : '';
		$cfg  = self::config_for_slug( $slug );
		if ( empty( $cfg ) ) {
			wp_die( esc_html__( 'Unknown categories page.', 'equine-event-manager' ) );
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = ( isset( $_POST['term_ids'] ) && is_array( $_POST['term_ids'] ) ) ? array_map( 'absint', wp_unslash( $_POST['term_ids'] ) ) : array();

		if ( 'delete' !== $action || empty( $ids ) ) {
			self::redirect( $slug, 'term_bulk_none', 'error' );
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( true === wp_delete_term( $id, $cfg['taxonomy'] ) ) {
				$deleted++;
			}
		}
		/* translators: %d: number of categories deleted. */
		$detail = sprintf( _n( '%d category deleted.', '%d categories deleted.', $deleted, 'equine-event-manager' ), $deleted );
		self::redirect( $slug, 'term_bulk_deleted', 'success', $detail );
	}
}
