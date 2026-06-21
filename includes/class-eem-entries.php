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

	/** @var string Division name meta key (e.g. "#9.5 Class"). */
	const META_DIVISION_NAME = '_en_division_name';

	/** @var string Division price meta key (2dp decimal string). */
	const META_PRICE = '_en_division_price';

	/** @var string Division spots/inventory meta key (int; 0/blank = unlimited). */
	const META_SPOTS = '_en_division_spots';

	/** @var string Division max-per-customer meta key (int; 0 = unlimited). */
	const META_MAX = '_en_division_max';

	/**
	 * Wire the CPT, custom editor route, redirects, AJAX handlers and list columns.
	 *
	 * @return void
	 */
	public static function register(): void {
		// The CPT always registers so existing data survives a disable (with
		// show_ui following the flag). When Entries is OFF (Settings → Add-Ons),
		// every admin + checkout surface below is skipped.
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		if ( ! EEM_Events::is_entries_enabled() ) {
			return;
		}
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
					'singular_name'      => __( 'Division', 'equine-event-manager' ),
					'add_new'            => __( 'Add Division', 'equine-event-manager' ),
					'add_new_item'       => __( 'Add Division', 'equine-event-manager' ),
					'edit_item'          => __( 'Edit Division', 'equine-event-manager' ),
					'new_item'           => __( 'New Division', 'equine-event-manager' ),
					'view_item'          => __( 'View Division', 'equine-event-manager' ),
					'search_items'       => __( 'Search Entries', 'equine-event-manager' ),
					'not_found'          => __( 'No entries yet.', 'equine-event-manager' ),
					'not_found_in_trash' => __( 'No entries in Trash.', 'equine-event-manager' ),
					'all_items'          => __( 'Entries', 'equine-event-manager' ),
					'menu_name'          => __( 'Entries', 'equine-event-manager' ),
				),
				'public'          => false,
				// Hidden entirely when the Entries feature is OFF (data preserved).
				'show_ui'         => EEM_Events::is_entries_enabled(),
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
			__( 'Event Entries', 'equine-event-manager' ),
			__( 'Event Entries', 'equine-event-manager' ),
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
	 * URL for a single Division's detail view (entrants + spots stats).
	 *
	 * @param int $division_id en_entry post id.
	 * @return string
	 */
	public static function detail_url( int $division_id ): string {
		return admin_url( 'admin.php?page=' . self::LIST_SLUG . '&division_id=' . $division_id );
	}

	/**
	 * Render the single-Division detail view: header (Event - Division),
	 * summary stats (Entered / Spots / Spots Left, oversold note), and the
	 * entrants roster (name, qty, order #, date, paid status). The entrants +
	 * counts come from the EEM_Division_Entries ledger (Slice 2).
	 *
	 * @param int $division_id en_entry post id.
	 * @return void
	 */
	public static function render_detail( int $division_id ): void {
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		$post = $division_id > 0 ? get_post( $division_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			eem_render_page_open( array(
				'title'      => __( 'Division not found', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Entries', 'equine-event-manager' ), 'url' => self::list_url() ),
					array( 'label' => __( 'Not found', 'equine-event-manager' ) ),
				),
				'wrap'       => true,
			) );
			echo '<p style="padding:20px">' . esc_html__( 'That division could not be loaded.', 'equine-event-manager' ) . '</p>';
			eem_render_page_close( array( 'wrap' => true ) );
			return;
		}

		$cfg       = EEM_Division_Config_Repo::get( $division_id );
		$rid       = $cfg['reservation_id'];
		$res_label = $rid > 0 ? self::reservation_label( $rid ) : array( 'title' => '', 'end_date' => '' );
		$event     = (string) $res_label['title'];
		$is_past   = self::event_is_past( (string) $res_label['end_date'] );
		$div_name  = $cfg['division_name'];
		$price     = $cfg['price'];
		$spots_int = $cfg['spots'];

		$entered   = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::entered_count( $division_id ) : 0;
		$left      = ( $spots_int > 0 ) ? max( 0, $spots_int - $entered ) : null;
		$oversold  = ( $spots_int > 0 && $entered > $spots_int ) ? ( $entered - $spots_int ) : 0;
		$entrants  = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::get_entrants( $division_id ) : array();

		$title = '' !== $event && '' !== $div_name ? $event . ' - ' . $div_name : get_the_title( $post );

		// "Edit Division" + (when connected) "View Event" + "Print" actions.
		$print_url = esc_url( add_query_arg( array( 'page' => self::LIST_SLUG, 'division_id' => $division_id, 'print' => '1' ), admin_url( 'admin.php' ) ) );
		$actions  = sprintf(
			'<a class="eem-btn eem-btn-electric" href="%s">%s</a>',
			esc_url( self::editor_url( $division_id ) ),
			esc_html__( 'Edit Division', 'equine-event-manager' )
		);
		$actions = sprintf(
			'<a class="eem-btn" href="%s" target="_blank" rel="noopener">%s</a> ',
			$print_url,
			esc_html__( 'Print', 'equine-event-manager' )
		) . $actions;
		$event_url = ( $rid > 0 && class_exists( 'EEM_Events' ) ) ? EEM_Events::get_reservation_public_url( $rid ) : '';
		if ( $event_url ) {
			$actions = sprintf(
				'<a class="eem-btn" href="%s" target="_blank" rel="noopener noreferrer">%s</a> ',
				esc_url( $event_url ),
				esc_html__( 'View Event', 'equine-event-manager' )
			) . $actions;
		}

		eem_render_page_open( array(
			'title'      => $title,
			'subtitle'   => '' !== $price ? sprintf( /* translators: %s: price */ __( 'Entry fee: $%s per spot', 'equine-event-manager' ), number_format( (float) $price, 2 ) ) : '',
			'breadcrumb' => array(
				array( 'label' => __( 'Entries', 'equine-event-manager' ), 'url' => self::list_url() ),
				array( 'label' => '' !== $div_name ? $div_name : __( 'Division', 'equine-event-manager' ) ),
			),
			'actions'    => $actions,
			'wrap'       => true,
		) );
		?>
		<div class="eem-div-detail-card">
		<?php if ( $is_past ) : ?>
		<div class="eem-notice-inline" role="alert">
			<span class="eem-res-status eem-res-status--past"><?php esc_html_e( 'Past', 'equine-event-manager' ); ?></span>
			<?php esc_html_e( 'This event has already ended.', 'equine-event-manager' ); ?>
		</div>
		<?php endif; ?>

		<?php
		// "Spots Left" tone follows availability: green when spots remain,
		// orange when sold out, red when oversold (mirrors the dashboard KPI
		// accent vocabulary).
		$left_tone = 'green';
		if ( $oversold > 0 ) {
			$left_tone = 'red';
		} elseif ( null !== $left && 0 === $left ) {
			$left_tone = 'orange';
		}
		?>

		<div class="eem-div-stat-grid">
			<div class="eem-div-stat-card">
				<div class="eem-div-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></div>
				<div class="eem-div-stat-text">
					<span class="eem-div-stat-num"><?php echo esc_html( (string) $entered ); ?></span>
					<span class="eem-div-stat-label"><?php esc_html_e( 'Entered', 'equine-event-manager' ); ?></span>
				</div>
			</div>
			<div class="eem-div-stat-card">
				<div class="eem-div-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></div>
				<div class="eem-div-stat-text">
					<span class="eem-div-stat-num"><?php echo esc_html( $spots_int > 0 ? (string) $spots_int : __( 'Unlimited', 'equine-event-manager' ) ); ?></span>
					<span class="eem-div-stat-label"><?php esc_html_e( 'Spots', 'equine-event-manager' ); ?></span>
				</div>
			</div>
			<div class="eem-div-stat-card eem-div-stat-card--<?php echo esc_attr( $left_tone ); ?>">
				<div class="eem-div-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
				<div class="eem-div-stat-text">
					<span class="eem-div-stat-num"><?php echo esc_html( null === $left ? '—' : (string) $left ); ?></span>
					<span class="eem-div-stat-label"><?php esc_html_e( 'Spots Left', 'equine-event-manager' ); ?></span>
					<?php if ( $oversold > 0 ) : ?>
					<span class="eem-div-stat-oversold"><?php echo esc_html( sprintf( /* translators: %d: count oversold by. */ __( 'Oversold by %d', 'equine-event-manager' ), $oversold ) ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php if ( $rid > 0 ) : ?>
		<div class="eem-div-event-band">
			<?php
			$dates_str = '';
			if ( ! empty( $res_label['start_date'] ) ) {
				$dates_str = mysql2date( 'M j', (string) $res_label['start_date'] );
				if ( ! empty( $res_label['end_date'] ) && $res_label['end_date'] !== $res_label['start_date'] ) {
					$dates_str .= ' – ' . mysql2date( 'M j, Y', (string) $res_label['end_date'] );
				} else {
					$dates_str .= ', ' . mysql2date( 'Y', (string) $res_label['start_date'] );
				}
			}
			?>
			<?php if ( '' !== $dates_str ) : ?>
			<span><strong><?php esc_html_e( 'Dates:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $dates_str ); ?></span>
			<?php endif; ?>
			<span><strong><?php esc_html_e( 'Reservation:', 'equine-event-manager' ); ?></strong>
				<a href="<?php echo esc_url( get_edit_post_link( $rid ) ?: '#' ); ?>"><?php echo esc_html( get_the_title( $rid ) ?: (string) $rid ); ?></a>
			</span>
		</div>
		<?php endif; ?>

		<?php
		$status_labels = array(
			'paid'      => __( 'Paid', 'equine-event-manager' ),
			'unpaid'    => __( 'Unpaid', 'equine-event-manager' ),
			'refunded'  => __( 'Refunded', 'equine-event-manager' ),
			'cancelled' => __( 'Cancelled', 'equine-event-manager' ),
		);
		$total_qty = 0;
		foreach ( $entrants as $r ) { $total_qty += (int) $r['qty']; }
		?>

		<div class="eem-div-toolbar">
			<select class="eem-toolbar-select" data-eem-input-action="div-filter-status" aria-label="<?php esc_attr_e( 'Filter by status', 'equine-event-manager' ); ?>">
				<option value=""><?php esc_html_e( 'All Statuses', 'equine-event-manager' ); ?></option>
				<?php foreach ( $status_labels as $val => $lbl ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="eem-search-wrap">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input class="eem-search-input" type="search" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" data-eem-input-action="div-search-entrants" aria-label="<?php esc_attr_e( 'Search entrants', 'equine-event-manager' ); ?>">
			</div>
			<span class="eem-div-toolbar-count" data-eem-div-entry-count>
				<?php echo esc_html( sprintf( /* translators: %d: number of entries. */ _n( '%d entry', '%d entries', count( $entrants ), 'equine-event-manager' ), count( $entrants ) ) ); ?>
			</span>
		</div>

		<div class="eem-desktop-table">
		<table class="eem-table eem-div-entrants-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
					<th class="eem-table-c"><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Order', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></th>
					<th class="eem-table-c"><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entrants ) ) : ?>
					<tr><td colspan="6" class="eem-table-empty"><?php esc_html_e( 'No entries yet.', 'equine-event-manager' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $entrants as $row ) :
						$ekey        = (string) $row['order_key'];
						$order       = ( '' !== $ekey && class_exists( 'EEM_Orders_Repository' ) ) ? ( new EEM_Orders_Repository() )->get_order( $ekey ) : null;
						$ord_no      = ( is_array( $order ) && ! empty( $order['order_number'] ) ) ? sprintf( '#%05d', (int) $order['order_number'] ) : '—';
						$ord_url     = ( '' !== $ekey && class_exists( 'EEM_Orders_List_Page' ) ) ? EEM_Orders_List_Page::order_detail_url( $ekey ) : '';
						$profile_url = class_exists( 'EEM_Orders_List_Page' ) ? EEM_Orders_List_Page::customer_profile_url( (string) $row['email'] ) : '';
						$customer    = '' !== (string) $row['customer_name'] ? (string) $row['customer_name'] : (string) $row['email'];
						$st          = (string) $row['status'];
						$st_lbl      = isset( $status_labels[ $st ] ) ? $status_labels[ $st ] : ucfirst( $st );
					?>
					<tr data-eem-status="<?php echo esc_attr( $st ); ?>" data-eem-search="<?php echo esc_attr( strtolower( $customer ) ); ?>">
						<td>
							<?php if ( '' !== $profile_url ) : ?>
							<a class="eem-res-name" href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $customer ); ?></a>
							<?php else : ?>
							<span class="eem-res-name"><?php echo esc_html( $customer ); ?></span>
							<?php endif; ?>
						</td>
						<td class="eem-table-c"><?php echo esc_html( (string) (int) $row['qty'] ); ?></td>
						<td>
							<?php if ( '' !== $ord_url && '—' !== $ord_no ) : ?>
							<a class="eem-order-num" href="<?php echo esc_url( $ord_url ); ?>"><?php echo esc_html( $ord_no ); ?></a>
							<?php else : ?>
							<span style="color:#94a3b8"><?php echo esc_html( $ord_no ); ?></span>
							<?php endif; ?>
						</td>
						<td style="color:#64748b"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ) ); ?></td>
						<td class="eem-table-c"><span class="eem-status-badge eem-status-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_lbl ); ?></span></td>
						<td style="text-align:right">
							<div class="eem-row-menu-wrap">
								<button class="eem-row-more-btn" data-eem-action="open-row-menu" aria-label="<?php esc_attr_e( 'Row actions', 'equine-event-manager' ); ?>">···</button>
								<div class="eem-row-dropdown" role="menu">
									<a class="eem-row-dd-item" href="<?php echo esc_url( self::editor_url( $division_id ) ); ?>" role="menuitem">
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
										<?php esc_html_e( 'Edit Entry', 'equine-event-manager' ); ?>
									</a>
									<?php if ( '' !== $ord_url ) : ?>
									<a class="eem-row-dd-item eem-row-dd-item--danger" href="<?php echo esc_url( $ord_url ); ?>" role="menuitem">
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="23 7 16 12 23 17"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
										<?php esc_html_e( 'Refund', 'equine-event-manager' ); ?>
									</a>
									<a class="eem-row-dd-item eem-row-dd-item--danger" href="<?php echo esc_url( $ord_url ); ?>" role="menuitem">
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
										<?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?>
									</a>
									<?php endif; ?>
								</div>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<?php if ( ! empty( $entrants ) ) : ?>
			<tfoot>
				<tr>
					<td><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></td>
					<td class="eem-table-c"><?php echo esc_html( (string) $total_qty ); ?></td>
					<td colspan="4"></td>
				</tr>
			</tfoot>
			<?php endif; ?>
		</table>
		</div>

		<?php // Mobile cards — replace the (hidden ≤767) table so entrants stay readable on phones. ?>
		<div class="eem-mobile-cards">
			<?php if ( empty( $entrants ) ) : ?>
				<div class="eem-mobile-card eem-mobile-card--empty"><?php esc_html_e( 'No entries yet.', 'equine-event-manager' ); ?></div>
			<?php else : ?>
				<?php foreach ( $entrants as $row ) :
					$ekey        = (string) $row['order_key'];
					$order       = ( '' !== $ekey && class_exists( 'EEM_Orders_Repository' ) ) ? ( new EEM_Orders_Repository() )->get_order( $ekey ) : null;
					$ord_no      = ( is_array( $order ) && ! empty( $order['order_number'] ) ) ? sprintf( '#%05d', (int) $order['order_number'] ) : '';
					$ord_url     = ( '' !== $ekey && class_exists( 'EEM_Orders_List_Page' ) ) ? EEM_Orders_List_Page::order_detail_url( $ekey ) : '';
					$profile_url = class_exists( 'EEM_Orders_List_Page' ) ? EEM_Orders_List_Page::customer_profile_url( (string) $row['email'] ) : '';
					$customer    = '' !== (string) $row['customer_name'] ? (string) $row['customer_name'] : (string) $row['email'];
					$st          = (string) $row['status'];
					$st_lbl      = isset( $status_labels[ $st ] ) ? $status_labels[ $st ] : ucfirst( $st );
				?>
				<div class="eem-mobile-card" data-eem-status="<?php echo esc_attr( $st ); ?>" data-eem-search="<?php echo esc_attr( strtolower( $customer ) ); ?>">
					<div class="eem-mobile-card-top">
						<?php if ( '' !== $profile_url ) : ?>
						<a class="eem-mobile-card-id" href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $customer ); ?></a>
						<?php else : ?>
						<span class="eem-mobile-card-id"><?php echo esc_html( $customer ); ?></span>
						<?php endif; ?>
						<span class="eem-mobile-card-meta"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ) ); ?></span>
					</div>
					<div class="eem-mobile-card-sub">
						<?php
						/* translators: %d: quantity entered. */
						echo esc_html( sprintf( _n( '%d entry', '%d entries', (int) $row['qty'], 'equine-event-manager' ), (int) $row['qty'] ) );
						?>
						<?php if ( '' !== $ord_no && '' !== $ord_url ) : ?>
						&middot; <a class="eem-order-num" href="<?php echo esc_url( $ord_url ); ?>"><?php echo esc_html( $ord_no ); ?></a>
						<?php elseif ( '' !== $ord_no ) : ?>
						&middot; <?php echo esc_html( $ord_no ); ?>
						<?php endif; ?>
					</div>
					<div class="eem-mobile-card-bottom">
						<div class="eem-mobile-card-badges">
							<span class="eem-status-badge eem-status-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_lbl ); ?></span>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		</div><!-- .eem-div-detail-card -->
		<?php
		eem_render_page_close( array( 'wrap' => true ) );
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

		// A `division_id` param routes to the single-Division detail view
		// (entrants + spots stats) rather than the catalog list.
		// `print=1` on top of division_id routes to the standalone print view.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view dispatch.
		$detail_id = isset( $_GET['division_id'] ) ? absint( wp_unslash( $_GET['division_id'] ) ) : 0;
		if ( $detail_id > 0 ) {
			if ( isset( $_GET['print'] ) && '1' === $_GET['print'] ) {
				self::render_print_view( $detail_id );
				return;
			}
			self::render_detail( $detail_id );
			return;
		}

		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		$entries = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		// Pre-compute each row + collect the distinct events for the filter.
		$rows   = array();
		$events = array(); // reservation_id => title
		foreach ( $entries as $e ) {
			$cfg       = EEM_Division_Config_Repo::get( (int) $e->ID );
			$rid       = $cfg['reservation_id'];
			$res_label = $rid > 0 ? self::reservation_label( $rid ) : array( 'title' => '', 'start_date' => '', 'end_date' => '' );
			$event     = (string) $res_label['title'];
			$ev_status = self::event_status( (string) ( $res_label['start_date'] ?? '' ), (string) $res_label['end_date'] );
			$div_name  = $cfg['division_name'];
			$price     = $cfg['price'];
			$spots_int = $cfg['spots'];
			$entered   = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::entered_count( (int) $e->ID ) : 0;
			$orders    = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::order_count( (int) $e->ID ) : 0;
			$is_pub    = ( 'publish' === get_post_status( $e ) );
			if ( $rid > 0 && '' !== $event ) {
				$events[ $rid ] = $event;
			}
			$rows[] = array(
				'id'        => (int) $e->ID,
				'rid'       => $rid,
				'event'     => $event,
				'name'      => '' !== $div_name ? $div_name : get_the_title( $e ),
				'price'     => $price,
				'spots_int' => $spots_int,
				'entered'   => $entered,
				'orders'    => $orders,
				'oversold'  => ( $spots_int > 0 && $entered > $spots_int ) ? ( $entered - $spots_int ) : 0,
				'is_pub'    => $is_pub,
				'ev_status' => $ev_status,
			);
		}
		asort( $events );

		eem_render_page_open( array(
			'title'      => __( 'Entries', 'equine-event-manager' ),
			'subtitle'   => __( 'Divisions customers pay to enter, connected to an event. Each division surfaces as a purchasable option on that event\'s customer reservation page and folds into the order at checkout.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Entries', 'equine-event-manager' ) ),
			),
			'actions'    => sprintf(
				'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
				esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ),
				esc_html__( 'New Division', 'equine-event-manager' )
			),
			'wrap'       => true,
		) );

		echo '<div class="eem-list-card">';

		if ( ! empty( $rows ) ) :
			?>
			<div class="eem-list-toolbar eem-list-toolbar--in-card">
				<div class="eem-list-toolbar-left">
					<select class="eem-toolbar-select" data-eem-input-action="entries-filter-event" data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>">
						<option value=""><?php esc_html_e( 'All events', 'equine-event-manager' ); ?></option>
						<?php foreach ( $events as $eid => $etitle ) : ?>
							<option value="<?php echo esc_attr( (string) $eid ); ?>"><?php echo esc_html( $etitle ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="eem-list-toolbar-right">
					<div class="eem-search-wrap">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" data-eem-input-action="entries-search" aria-label="<?php esc_attr_e( 'Search divisions', 'equine-event-manager' ); ?>">
					</div>
				</div>
			</div>
			<?php
		endif;
		?>
		<div class="eem-desktop-table">
			<table class="eem-table eem-entries-table" data-eem-entries-table>
				<thead>
					<tr>
						<th class="eem-sortable" data-eem-sort="name" data-eem-sort-type="text"><?php esc_html_e( 'Division', 'equine-event-manager' ); ?><span class="eem-sort-ind" aria-hidden="true"></span></th>
						<th class="eem-sortable" data-eem-sort="event" data-eem-sort-type="text"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?><span class="eem-sort-ind" aria-hidden="true"></span></th>
						<th class="eem-sortable" data-eem-sort="price" data-eem-sort-type="num"><?php esc_html_e( 'Price', 'equine-event-manager' ); ?><span class="eem-sort-ind" aria-hidden="true"></span></th>
						<th class="eem-sortable" data-eem-sort="entered" data-eem-sort-type="num"><?php esc_html_e( 'Entered / Spots', 'equine-event-manager' ); ?><span class="eem-sort-ind" aria-hidden="true"></span></th>
						<th class="eem-sortable" data-eem-sort="orders" data-eem-sort-type="num"><?php esc_html_e( 'Orders', 'equine-event-manager' ); ?><span class="eem-sort-ind" aria-hidden="true"></span></th>
						<th class="eem-table-r"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6" class="eem-table-empty">
							<?php
							printf(
								/* translators: %s: New Division link */
								esc_html__( 'No divisions yet. %s to create your first one.', 'equine-event-manager' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Add a division', 'equine-event-manager' ) . '</a>'
							);
							?>
						</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) :
							$edit_url   = self::editor_url( $r['id'] );
							$detail_url = self::detail_url( $r['id'] );
							?>
							<tr
								data-eem-event-id="<?php echo esc_attr( (string) $r['rid'] ); ?>"
								data-sort-name="<?php echo esc_attr( strtolower( $r['name'] ) ); ?>"
								data-sort-event="<?php echo esc_attr( strtolower( $r['event'] ) ); ?>"
								data-sort-price="<?php echo esc_attr( '' !== $r['price'] ? (string) (float) $r['price'] : '-1' ); ?>"
								data-sort-entered="<?php echo esc_attr( (string) $r['entered'] ); ?>"
								data-sort-orders="<?php echo esc_attr( (string) $r['orders'] ); ?>"
								data-sort-status="<?php echo esc_attr( empty( $r['is_pub'] ) ? '0' : ( 'past' === $r['ev_status'] ? '1' : ( 'ongoing' === $r['ev_status'] ? '2' : '3' ) ) ); ?>">
								<td>
									<a class="eem-res-name" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $r['name'] ); ?></a>
									<?php if ( empty( $r['is_pub'] ) ) : ?>
										<span class="eem-res-status eem-res-status--draft"><?php esc_html_e( 'Draft', 'equine-event-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo '' !== $r['event'] ? esc_html( $r['event'] ) : '<span class="eem-entries-unconnected" title="' . esc_attr__( 'This division has no linked event, so it does not appear on any customer reservation page.', 'equine-event-manager' ) . '">' . esc_html__( 'Not connected', 'equine-event-manager' ) . '</span>'; ?></td>
								<td><?php echo '' !== $r['price'] ? esc_html( '$' . number_format( (float) $r['price'], 2 ) ) : '<span class="eem-orders-count is-zero">—</span>'; ?></td>
								<td>
									<a class="eem-entries-entered-link" href="<?php echo esc_url( $detail_url ); ?>" title="<?php esc_attr_e( 'View entrants', 'equine-event-manager' ); ?>"><?php echo wp_kses_post( self::entered_spots_html( (int) $r['entered'], (int) $r['spots_int'], (int) $r['oversold'] ) ); ?></a>
									<?php if ( $r['oversold'] > 0 ) : ?>
										<span class="eem-status-badge eem-status-oversold"><?php echo esc_html(
											/* translators: %d: count oversold by. */
											sprintf( __( 'oversold by %d', 'equine-event-manager' ), $r['oversold'] )
										); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo $r['orders'] > 0 ? esc_html( number_format_i18n( (int) $r['orders'] ) ) : '<span class="eem-orders-count is-zero">—</span>'; ?></td>
								<td class="eem-table-r">
									<div class="eem-actions-cell">
										<div class="eem-row-menu-wrap">
											<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="eem-entry-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
											<div class="eem-row-dropdown" id="eem-entry-menu-<?php echo esc_attr( (string) (int) $r['id'] ); ?>" role="menu">
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $edit_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></a>
												<a class="eem-row-dd-item" role="menuitem" href="<?php echo esc_url( $detail_url ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></svg><?php esc_html_e( 'View Entrants', 'equine-event-manager' ); ?></a>
												<a class="eem-row-dd-item eem-row-dd-danger" role="menuitem" href="<?php echo esc_url( (string) get_delete_post_link( (int) $r['id'] ) ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></a>
											</div>
										</div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr class="eem-entries-empty-filtered" hidden><td colspan="6" class="eem-table-empty"><?php esc_html_e( 'No divisions for this event.', 'equine-event-manager' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php // Mobile cards — replace the (hidden ≤767) table; carry data-eem-event-id so the event filter applies to them too (admin.js entries filter). ?>
		<div class="eem-mobile-cards eem-entries-mobile">
			<?php if ( empty( $rows ) ) : ?>
				<div class="eem-mobile-card eem-mobile-card--empty">
					<?php
					printf(
						/* translators: %s: New Division link */
						esc_html__( 'No divisions yet. %s to create your first one.', 'equine-event-manager' ),
						'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Add a division', 'equine-event-manager' ) . '</a>'
					);
					?>
				</div>
			<?php else : ?>
				<?php foreach ( $rows as $r ) :
					$edit_url   = self::editor_url( $r['id'] );
					$detail_url = self::detail_url( $r['id'] );
					?>
					<div class="eem-mobile-card" data-eem-event-id="<?php echo esc_attr( (string) $r['rid'] ); ?>" data-search-name="<?php echo esc_attr( strtolower( $r['name'] ) ); ?>" data-sort-entered="<?php echo esc_attr( (string) $r['entered'] ); ?>">
						<div class="eem-mobile-card-top">
							<a class="eem-mobile-card-id" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $r['name'] ); ?><?php if ( empty( $r['is_pub'] ) ) : ?> <span class="eem-res-status eem-res-status--draft"><?php esc_html_e( 'Draft', 'equine-event-manager' ); ?></span><?php endif; ?></a>
							<span class="eem-mobile-card-meta"><?php echo '' !== $r['price'] ? esc_html( '$' . number_format( (float) $r['price'], 2 ) ) : '—'; ?></span>
						</div>
						<div class="eem-mobile-card-sub"><?php echo '' !== $r['event'] ? esc_html( $r['event'] ) : '<span class="eem-entries-unconnected">' . esc_html__( 'Not connected', 'equine-event-manager' ) . '</span>'; ?></div>
						<div class="eem-mobile-card-bottom">
							<div class="eem-mobile-card-badges">
								<a class="eem-mobile-card-metric eem-entries-entered-link" href="<?php echo esc_url( $detail_url ); ?>"><?php echo wp_kses_post( self::entered_spots_html( (int) $r['entered'], (int) $r['spots_int'], (int) $r['oversold'] ) ); ?></a>
								<?php if ( $r['oversold'] > 0 ) : ?>
									<span class="eem-status-badge eem-status-oversold"><?php echo esc_html( sprintf( /* translators: %d: count oversold by. */ __( 'oversold by %d', 'equine-event-manager' ), $r['oversold'] ) ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				<div class="eem-mobile-card eem-mobile-card--empty eem-entries-mobile-empty" hidden><?php esc_html_e( 'No divisions for this event.', 'equine-event-manager' ); ?></div>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $rows ) ) :
			$eem_total_entrants = array_sum( array_map( static function ( $r ) { return (int) $r['entered']; }, $rows ) );
			?>
			<div class="eem-table-footer">
				<span class="eem-table-footer-info">
					<span data-eem-entries-count><?php
						echo esc_html( sprintf(
							/* translators: %d: division count. */
							_n( '%d division', '%d divisions', count( $rows ), 'equine-event-manager' ),
							count( $rows )
						) );
					?></span>
					<span class="eem-table-footer-sep" aria-hidden="true">·</span>
					<span data-eem-entries-entrants><?php
						echo esc_html( sprintf(
							/* translators: %d: total entrant count across listed divisions. */
							_n( '%d total entrant', '%d total entrants', $eem_total_entrants, 'equine-event-manager' ),
							$eem_total_entrants
						) );
					?></span>
				</span>
			</div>
		<?php endif; ?>
		<?php
		echo '</div>';
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
	 * @return array{title:string,dates:string,start_date:string,end_date:string}
	 */
	private static function reservation_label( int $reservation_id ): array {
		$title      = '';
		$dates      = '';
		$start_date = '';
		$end_date   = '';
		if ( $reservation_id > 0 ) {
			$title = (string) get_the_title( $reservation_id );
			if ( class_exists( 'EEM_Reservation_Source_Resolver' ) && class_exists( 'EEM_Dashboard_Repo' ) ) {
				$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
				if ( ! empty( $fields['title'] ) ) {
					$title = (string) $fields['title'];
				}
				$start_date = isset( $fields['start_date'] ) ? (string) $fields['start_date'] : '';
				$end_date   = isset( $fields['end_date'] ) ? (string) $fields['end_date'] : '';
				$dates      = EEM_Dashboard_Repo::format_date_range( $start_date, $end_date );
			}
		}
		return array( 'title' => $title, 'dates' => $dates, 'start_date' => $start_date, 'end_date' => $end_date );
	}

	/**
	 * Derive an event-timing status from its start/end dates — drives the
	 * Scheduled / Ongoing / Past pill on the Divisions list (replaces the old
	 * Published/Draft status per Whitney 2026-06-14). `scheduled` = not started
	 * yet (or no dates / open-ended), `ongoing` = started and not yet ended,
	 * `past` = ended. Display-only; computed at render time.
	 *
	 * @param string $start_date Resolved event start date (strtotime-parseable).
	 * @param string $end_date   Resolved event end date (strtotime-parseable).
	 * @return string One of 'scheduled' | 'ongoing' | 'past'.
	 */
	private static function event_status( string $start_date, string $end_date ): string {
		$today    = strtotime( current_time( 'Y-m-d' ) );
		$start_ts = '' !== trim( $start_date ) ? strtotime( $start_date ) : 0;
		$end_ts   = '' !== trim( $end_date ) ? strtotime( $end_date ) : 0;
		if ( $end_ts && $today > $end_ts ) {
			return 'past';
		}
		if ( $start_ts && $today >= $start_ts ) {
			// Started; still ongoing unless the end date has already passed
			// (handled above). Open-ended (no end date) stays ongoing.
			return 'ongoing';
		}
		return 'scheduled';
	}

	/**
	 * Render the "Entered / Spots" value with colour-coded numbers so the column
	 * reads at a glance (Whitney 2026-06-14): entered in electric blue (red when
	 * oversold), spots in green, muted slash between. Open-ended divisions show
	 * "Unlimited" for spots.
	 *
	 * @param int $entered   Number of entrants.
	 * @param int $spots_int Capacity (0 = unlimited).
	 * @param int $oversold  How many over capacity (0 = within cap).
	 * @return string Safe HTML (all values escaped; echo via wp_kses_post()).
	 */
	private static function entered_spots_html( int $entered, int $spots_int, int $oversold ): string {
		$spots_label = $spots_int > 0 ? number_format_i18n( $spots_int ) : __( 'Unlimited', 'equine-event-manager' );
		$entered_cls = 'eem-entered-count' . ( $oversold > 0 ? ' is-oversold' : '' );
		return '<span class="eem-entered-spots">'
			. '<span class="' . esc_attr( $entered_cls ) . '">' . esc_html( number_format_i18n( $entered ) ) . '</span>'
			. '<span class="eem-entered-sep">/</span>'
			. '<span class="eem-spots-count">' . esc_html( $spots_label ) . '</span>'
			. '</span>';
	}

	/**
	 * Whether a reservation's event has already ended (end date strictly before
	 * today). Display-only — drives the "Past" pill on the Entries list/detail.
	 * Returns false when no end date is resolvable (open-ended / not connected).
	 *
	 * @param string $end_date Resolved event end date (any strtotime-parseable form).
	 * @return bool
	 */
	private static function event_is_past( string $end_date ): bool {
		if ( '' === trim( $end_date ) ) {
			return false;
		}
		$end_ts = strtotime( $end_date );
		if ( ! $end_ts ) {
			return false;
		}
		$today = strtotime( current_time( 'Y-m-d' ) );
		return $end_ts < $today;
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

		$cfg            = EEM_Division_Config_Repo::get( $entry_id );
		$reservation_id = $cfg['reservation_id'];
		$description    = $cfg['description'];
		$division_name  = $cfg['division_name'];
		$price_raw      = $cfg['price'];
		$spots_raw      = $cfg['spots'];
		$max_raw        = $cfg['max_per_customer'];

		$label            = self::reservation_label( $reservation_id );
		$has_linked_event = ( $reservation_id > 0 && '' !== $label['title'] );
		$header_title     = $has_linked_event
			? trim( $label['title'] . ( '' !== $division_name ? ' - ' . $division_name : '' ) )
			: __( 'New Division', 'equine-event-manager' );

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
									<span class="eem-res-name-eyebrow"><?php esc_html_e( 'Division for', 'equine-event-manager' ); ?></span>
								<?php endif; ?>
								<h1 class="eem-plugin-title" id="eem-entry-header-name"><?php echo esc_html( $header_title ); ?></h1>
							</div>
						</div>
						<div class="eem-plugin-header-meta" id="eem-entry-header-meta"><?php
							echo esc_html__( 'Editing Division', 'equine-event-manager' ) . ' #' . esc_html( (string) $entry_id );
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
							<span class="eem-event-search-wrap">
								<svg class="eem-event-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
								<input type="text" class="eem-event-search-input" id="eem-entry-event-search-input"
									placeholder="<?php esc_attr_e( 'Search Events', 'equine-event-manager' ); ?>"
									data-eem-input-action="entry-filter-events" autocomplete="off">
							</span>
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
							<?php
							// "View Event" opens the connected event's public reservation
							// page in a new tab — same control + style as Edit Reservation.
							$eem_event_url = class_exists( 'EEM_Events' ) ? EEM_Events::get_reservation_public_url( $reservation_id ) : '';
							if ( $eem_event_url ) :
								?>
								<a class="eem-btn-primary eem-header-action-view" href="<?php echo esc_url( $eem_event_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'View Event', 'equine-event-manager' ); ?>
								</a>
							<?php endif; ?>
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
									<?php esc_html_e( 'Search for the event in the box above and choose it. The division takes its name from the connected event plus the division name, and appears as a purchasable option on that event\'s customer reservation page.', 'equine-event-manager' ); ?>
								</p>
							</div>
						<?php endif; ?>

						<?php if ( $has_linked_event ) : ?>
						<?php
						// Build each card's body, then render through the shared
						// reservation-editor section helper so the Entry cards get the
						// SAME chrome as Edit Reservation (icon chip + padded header).

						$price_val = ( '' !== (string) $price_raw ) ? $fmt_money( $price_raw ) : '';
						$spots_val = ( '' !== (string) $spots_raw && (int) $spots_raw > 0 ) ? (string) (int) $spots_raw : '';
						$max_val   = ( '' !== (string) $max_raw && (int) $max_raw > 0 ) ? (string) (int) $max_raw : '';

						// Division card body — single set of fields (name + price +
						// spots + max-per-customer). Replaces the old multi-item table.
						ob_start();
						eem_render_editor_field_row( array(
							'label'        => __( 'Division Name', 'equine-event-manager' ),
							'label_sub'    => __( 'Shown to customers; the division title becomes "Event Name - Division Name"', 'equine-event-manager' ),
							'control_html' => '<input class="eem-field-input" type="text" id="eem-division-name" value="' . esc_attr( $division_name ) . '" placeholder="' . esc_attr__( 'e.g. #9.5 Division', 'equine-event-manager' ) . '">',
						) );
						eem_render_editor_field_row( array(
							'label'        => __( 'Price', 'equine-event-manager' ),
							'label_sub'    => __( 'Entry fee per spot', 'equine-event-manager' ),
							'control_html' => '<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" min="0" step="0.01" id="eem-division-price" value="' . esc_attr( $price_val ) . '" placeholder="0.00"></div>',
						) );
						eem_render_editor_field_row( array(
							'label'        => __( 'Spots', 'equine-event-manager' ),
							'label_sub'    => __( 'Total spots available. Leave blank for unlimited.', 'equine-event-manager' ),
							'control_html' => '<input class="eem-field-input" type="number" min="0" step="1" id="eem-division-spots" value="' . esc_attr( $spots_val ) . '" placeholder="' . esc_attr__( 'Unlimited', 'equine-event-manager' ) . '">',
						) );
						eem_render_editor_field_row( array(
							'label'        => __( 'Max Per Customer', 'equine-event-manager' ),
							'label_sub'    => __( 'Cap on how many spots one customer can enter. Leave blank for unlimited.', 'equine-event-manager' ),
							'control_html' => '<input class="eem-field-input" type="number" min="1" step="1" id="eem-division-max" value="' . esc_attr( $max_val ) . '" placeholder="' . esc_attr__( 'Unlimited', 'equine-event-manager' ) . '">',
						) );
						$eem_division_body = (string) ob_get_clean();

						// Description card body.
						ob_start();
						eem_render_editor_field_row( array(
							'label'        => __( 'Description', 'equine-event-manager' ),
							'label_sub'    => __( 'Optional intro shown above the division on the customer page', 'equine-event-manager' ),
							'control_html' => '<textarea class="eem-field-textarea" id="eem-entry-description" rows="3" placeholder="' . esc_attr__( 'e.g. Pre-purchase your division entry below.', 'equine-event-manager' ) . '">' . esc_textarea( $description ) . '</textarea>',
						) );
						$eem_desc_body = (string) ob_get_clean();

						// Render both cards through the shared section helper.
						eem_render_reservation_editor_section( array(
							'key'           => 'division-details',
							'title'         => __( 'Division', 'equine-event-manager' ),
							'icon_tone'     => 'green',
							'icon_key'      => 'package',
							'enable_toggle' => false,
							'collapsed'     => false,
							'collapsible'   => false,
							'body_html'     => $eem_division_body,
						) );
						eem_render_reservation_editor_section( array(
							'key'           => 'entry-description',
							'title'         => __( 'Description', 'equine-event-manager' ),
							'icon_tone'     => 'blue',
							'icon_key'      => 'file-text',
							'enable_toggle' => false,
							'collapsed'     => false,
							'collapsible'   => false,
							'body_html'     => $eem_desc_body,
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
					<?php echo esc_html( $is_published ? __( 'Update Division', 'equine-event-manager' ) : __( 'Publish Division', 'equine-event-manager' ) ); ?>
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
				'message' => __( 'Connect an event before publishing this division.', 'equine-event-manager' ),
				'code'    => 'entry_requires_event',
			), 422 );
		}

		$division_name = isset( $_POST['division_name'] ) ? sanitize_text_field( wp_unslash( $_POST['division_name'] ) ) : '';
		if ( 'publish' === $save_kind && '' === trim( $division_name ) ) {
			wp_send_json_error( array(
				'message' => __( 'Enter a division name before publishing.', 'equine-event-manager' ),
				'code'    => 'division_requires_name',
			), 422 );
		}

		$result = self::save_entry_fields(
			$entry_id,
			$reservation_id,
			$description,
			array(
				'division_name' => $division_name,
				'price'         => isset( $_POST['price'] ) ? wp_unslash( $_POST['price'] ) : '',
				'spots'         => isset( $_POST['spots'] ) ? wp_unslash( $_POST['spots'] ) : '',
				'max'           => isset( $_POST['max'] ) ? wp_unslash( $_POST['max'] ) : '',
			),
			$save_kind
		);

		wp_send_json_success( array(
			'message'  => ( 'publish' === $save_kind ) ? __( 'Division published.', 'equine-event-manager' ) : __( 'Draft saved.', 'equine-event-manager' ),
			'entry_id' => $entry_id,
			'title'    => $result['title'],
		) );
	}

	/**
	 * Persist a division's fields (name + price + spots + max + description +
	 * reservation) and sync its title ("Event Name - Division Name") + status.
	 * Split out of {@see self::ajax_save()} so the write path is testable
	 * without wp_die().
	 *
	 * @param int    $entry_id       Entry/division post id.
	 * @param int    $reservation_id Connected reservation id (0 = none).
	 * @param string $description    Sanitized description.
	 * @param array  $fields         Raw posted division fields (division_name, price, spots, max).
	 * @param string $save_kind      'publish' | 'save_draft'.
	 * @return array{title:string,status:string,division_name:string}
	 */
	public static function save_entry_fields( int $entry_id, int $reservation_id, string $description, array $fields, string $save_kind ): array {
		$division_name = isset( $fields['division_name'] ) ? sanitize_text_field( (string) $fields['division_name'] ) : '';
		$price         = number_format( (float) ( isset( $fields['price'] ) ? $fields['price'] : 0 ), 2, '.', '' );
		$spots         = isset( $fields['spots'] ) ? absint( $fields['spots'] ) : 0;
		$max           = isset( $fields['max'] ) ? absint( $fields['max'] ) : 0;

		EEM_Division_Config_Repo::save( $entry_id, array(
			'reservation_id'   => $reservation_id,
			'description'      => $description,
			'division_name'    => $division_name,
			'price'            => $price,
			'spots'            => $spots,
			'max_per_customer' => $max,
		) );

		$label      = self::reservation_label( $reservation_id );
		$event_name = (string) $label['title'];
		if ( '' !== $event_name && '' !== $division_name ) {
			$new_title = $event_name . ' - ' . $division_name;
		} elseif ( '' !== $division_name ) {
			$new_title = $division_name;
		} elseif ( '' !== $event_name ) {
			$new_title = $event_name;
		} else {
			$new_title = __( 'New Division', 'equine-event-manager' );
		}
		$status = ( 'publish' === $save_kind ) ? 'publish' : 'draft';
		wp_update_post( array(
			'ID'          => $entry_id,
			'post_title'  => $new_title,
			'post_status' => $status,
		) );

		return array( 'title' => $new_title, 'status' => $status, 'division_name' => $division_name );
	}

	/**
	 * List-table columns: Event + price alongside the title.
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
				$out['eem_entry_price'] = __( 'Price', 'equine-event-manager' );
			}
		}
		return $out;
	}

	/**
	 * Render a custom list-table column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Entry/division post id.
	 * @return void
	 */
	public static function column_value( string $column, int $post_id ): void {
		if ( 'eem_entry_event' === $column ) {
			$rid = EEM_Division_Config_Repo::get_field( $post_id, 'reservation_id' );
			echo $rid > 0 ? esc_html( get_the_title( $rid ) ) : '<span style="color:#b91c1c">' . esc_html__( '— not connected —', 'equine-event-manager' ) . '</span>';
		} elseif ( 'eem_entry_price' === $column ) {
			$price = (string) EEM_Division_Config_Repo::get_field( $post_id, 'price' );
			echo '' !== $price && '0.00' !== $price ? esc_html( '$' . number_format( (float) $price, 2 ) ) : '—';
		}
	}

	/**
	 * Resolve published Divisions linked to a reservation into the
	 * customer-pipeline option shape (keyed `entry_{postID}`, each with
	 * title/price/inventory/max_per_customer). One option per Division.
	 * Consumed by the customer-page render, pricing matrix, checkout
	 * validation, totals and order notes.
	 *
	 * @param int $reservation_id Reservation (event) id.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_for_reservation( $reservation_id ): array {
		$reservation_id = absint( $reservation_id );
		if ( $reservation_id <= 0 ) {
			return array();
		}

		if ( EEM_Division_Config_Repo::table_exists() ) {
			global $wpdb;
			$cfg_table = EEM_Division_Config_Repo::table_name();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT dc.division_id, dc.division_name, dc.price, dc.spots, dc.max_per_customer
				 FROM {$cfg_table} dc
				 INNER JOIN {$wpdb->posts} p ON p.ID = dc.division_id AND p.post_type = %s AND p.post_status = 'publish'
				 WHERE dc.reservation_id = %d
				 ORDER BY p.menu_order ASC, p.post_title ASC",
				self::POST_TYPE,
				$reservation_id
			), ARRAY_A ); // phpcs:ignore WordPress.DB

			$options = array();
			foreach ( $rows as $r ) {
				$title = trim( (string) $r['division_name'] );
				if ( '' === $title ) {
					continue;
				}
				$did = (int) $r['division_id'];
				$options[ 'entry_' . $did ] = array(
					'title'            => $title,
					'price'            => number_format( (float) $r['price'], 2, '.', '' ),
					'inventory'        => (int) $r['spots'],
					'max_per_customer' => (int) $r['max_per_customer'],
					'division_id'      => $did,
				);
			}
			return $options;
		}

		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_RESERVATION,
					'value' => $reservation_id,
				),
			),
		) );

		$options = array();
		foreach ( $posts as $p ) {
			$cfg   = EEM_Division_Config_Repo::get( (int) $p->ID );
			$title = trim( $cfg['division_name'] );
			if ( '' === $title ) {
				continue;
			}
			$options[ 'entry_' . $p->ID ] = array(
				'title'            => $title,
				'price'            => $cfg['price'],
				'inventory'        => $cfg['spots'],
				'max_per_customer' => $cfg['max_per_customer'],
				'division_id'      => (int) $p->ID,
			);
		}

		return $options;
	}

	/**
	 * Standalone print view for a single Division's entrant roster.
	 *
	 * Outputs a complete HTML document (no WP admin chrome) matching
	 * .mockups/preentries_print_view.html. Triggered by ?print=1 on the
	 * detail URL; called from render_list() before normal detail dispatch.
	 *
	 * @param int $division_id en_entry post ID.
	 * @return void
	 */
	public static function render_print_view( int $division_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$post = $division_id > 0 ? get_post( $division_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Division not found.', 'equine-event-manager' ) );
		}

		$cfg       = EEM_Division_Config_Repo::get( $division_id );
		$rid       = $cfg['reservation_id'];
		$res_label = $rid > 0 ? self::reservation_label( $rid ) : array( 'title' => '', 'end_date' => '', 'start_date' => '' );
		$event     = (string) $res_label['title'];
		$div_name  = $cfg['division_name'];
		$price     = $cfg['price'];
		$spots_int = $cfg['spots'];

		$entered  = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::entered_count( $division_id ) : 0;
		$left     = ( $spots_int > 0 ) ? max( 0, $spots_int - $entered ) : null;
		$oversold = ( $spots_int > 0 && $entered > $spots_int ) ? ( $entered - $spots_int ) : 0;
		$entrants = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::get_entrants( $division_id ) : array();

		$left_tone = 'green';
		if ( $oversold > 0 ) {
			$left_tone = 'red';
		} elseif ( null !== $left && 0 === $left ) {
			$left_tone = 'orange';
		}

		$logo_url    = esc_url( EQUINE_EVENT_MANAGER_URL . 'assets/images/logo.png' );
		$back_url    = esc_url( self::detail_url( $division_id ) );
		$detail_sub  = $div_name;
		if ( '' !== $event ) {
			$detail_sub .= ' &middot; ' . esc_html( $event );
		}
		if ( $entered > 0 ) {
			$detail_sub .= ' &middot; ' . esc_html( (string) $entered ) . ' entered';
		}

		// Dates from reservation label.
		$dates_str   = '';
		if ( ! empty( $res_label['start_date'] ) ) {
			$fmt = get_option( 'date_format' );
			$dates_str = mysql2date( 'M j', (string) $res_label['start_date'] );
			if ( ! empty( $res_label['end_date'] ) && $res_label['end_date'] !== $res_label['start_date'] ) {
				$dates_str .= ' – ' . mysql2date( 'M j, Y', (string) $res_label['end_date'] );
			} else {
				$dates_str .= ', ' . mysql2date( 'Y', (string) $res_label['start_date'] );
			}
		}

		$total_qty = 0;
		foreach ( $entrants as $row ) {
			$total_qty += (int) $row['qty'];
		}

		$status_labels = array(
			'paid'      => __( 'Paid', 'equine-event-manager' ),
			'unpaid'    => __( 'Unpaid', 'equine-event-manager' ),
			'refunded'  => __( 'Refunded', 'equine-event-manager' ),
			'cancelled' => __( 'Cancelled', 'equine-event-manager' ),
		);

		// Site name for footer.
		$site_name = get_bloginfo( 'name' );
		$printed   = wp_date( 'M j, Y g:i A' );

		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $div_name . ' — ' . __( 'Division Entrants', 'equine-event-manager' ) ); ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'IBM Plex Sans',sans-serif;color:#0d1b3e;background:#fff;font-size:14px;line-height:1.5}
a{text-decoration:none;color:inherit}
.pv-topbar{background:#fff;border-bottom:1px solid #e2e8f4;padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:10}
.pv-topbar-left{display:flex;align-items:center;gap:10px;min-width:0}
.pv-topbar-title{font-size:14px;font-weight:700;color:#0d1b3e}
.pv-topbar-sub{font-size:12px;color:#64748b;margin-top:1px}
.pv-topbar-btns{display:flex;gap:8px;flex-shrink:0}
.btn-pv-print{background:#1668F2;color:#fff;border:1px solid #1668F2;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-pv-print:hover{background:#1257d1;border-color:#1257d1}
.btn-pv-print svg{width:13px;height:13px}
.btn-pv-exit{background:#fff;color:#0d1b3e;border:1px solid #c3c4c7;padding:8px 14px;border-radius:3px;font-size:13px;font-weight:600;font-family:'IBM Plex Sans',sans-serif;cursor:pointer;text-decoration:none}
.btn-pv-exit:hover{background:#e8eef8}
.pv-body{padding:22px 28px;max-width:1000px;margin:0 auto}
.pv-header{margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #1668F2}
.pv-header-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.pv-eyebrow{font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin-bottom:4px}
.pv-header-title{font-family:'IBM Plex Sans',sans-serif;font-size:22px;font-weight:700;color:#0d1b3e;margin-bottom:6px}
.pv-meta{font-size:13px;color:#475569;display:flex;gap:24px;flex-wrap:wrap}
.pv-meta strong{color:#0d1b3e;font-weight:600}
.pv-stats{display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.pv-stat{flex:1;min-width:130px;border:1px solid #e2e8f4;border-radius:10px;padding:12px 16px;background:#f8faff;display:flex;flex-direction:row;align-items:center;gap:12px}
.pv-stat-text{display:flex;flex-direction:column;gap:2px}
.pv-stat-num{font-size:22px;font-weight:700;color:#0d1b3e;line-height:1.1}
.pv-stat-label{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8}
.pv-stat--green .pv-stat-num{color:#15803d}
.pv-stat--orange .pv-stat-num{color:#b45309}
.pv-stat--red .pv-stat-num{color:#b91c1c}
.pv-stat-oversold{display:block;font-size:10px;font-weight:600;color:#b91c1c;margin-top:2px}
.pv-event-band{background:#f0f5ff;border:1px solid #dbe9ff;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12.5px;color:#475569;display:flex;gap:18px;flex-wrap:wrap}
.pv-event-band strong{color:#0d1b3e;font-weight:600}
.pv-table-wrap{border:1px solid #e2e8f4;border-radius:10px;overflow:hidden;margin-bottom:18px}
.pv-table{width:100%;border-collapse:collapse;background:#fff}
.pv-table thead tr{background:#F7F9FC;border-bottom:1px solid #e2e8f4}
.pv-table thead th{padding:10px 12px;font-size:11px;font-weight:700;color:#94a3b8;text-align:left;text-transform:uppercase;letter-spacing:.05em}
.pv-table thead th.c{text-align:center}
.pv-table tbody tr{border-bottom:1px solid #f0f4fb}
.pv-table tbody tr:last-child{border-bottom:none}
.pv-table td{padding:9px 12px;font-size:13px;vertical-align:middle;color:#0d1b3e}
.pv-table td.c{text-align:center}
.pv-customer{font-weight:600;color:#0d1b3e}
.pv-order{color:#1668F2;font-size:12.5px;font-variant-numeric:tabular-nums}
.pv-date{color:#475569;font-size:12.5px}
.pv-qty{font-variant-numeric:tabular-nums;font-weight:600;text-align:center}
.pv-status{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;border:1px solid}
.pv-status-paid{background:#F0FDF4;color:#15803d;border-color:#bbf7d0}
.pv-status-unpaid{background:#FFFBEB;color:#a16207;border-color:#fde68a}
.pv-status-refunded{background:#FEF2F2;color:#b91c1c;border-color:#fecaca}
.pv-status-cancelled{background:#F3F4F6;color:#6b7280;border-color:#d1d5db}
.pv-table-empty{color:#64748b;font-style:italic;text-align:center;padding:24px 12px}
.pv-footer{margin-top:18px;padding-top:12px;border-top:1px solid #e8eaf0;display:flex;justify-content:space-between;font-size:12px;color:#64748b;flex-wrap:wrap;gap:8px}
@media(max-width:767px){
  .pv-topbar{flex-direction:column;align-items:flex-start;gap:10px;padding:12px 14px;height:auto}
  .pv-topbar-btns{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .btn-pv-print,.btn-pv-exit{width:100%;justify-content:center;font-size:12.5px;padding:9px 10px}
  .pv-body{padding:14px}
  .pv-stats{gap:8px}
  .pv-stat{min-width:0;flex:1 1 30%}
}
@media print{
  .pv-topbar{display:none}
  .pv-body{padding:0;max-width:none}
  body{background:#fff;font-size:11px}
  .pv-header-title{font-size:18px}
  .pv-event-band{font-size:10.5px;padding:8px 10px;break-after:avoid;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pv-stats{break-after:avoid}
  .pv-stat{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pv-table thead{display:table-header-group}
  .pv-table thead tr{background:#F7F9FC!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pv-table tbody tr{page-break-inside:avoid;break-inside:avoid}
  .pv-table td{padding:6px 8px;font-size:11px}
  .pv-status{font-size:9.5px;padding:1px 6px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pv-footer{font-size:9.5px}
  @page{margin:0.6in 0.5in 0.7in 0.5in}
}
</style>
</head>
<body>
<div class="pv-topbar">
  <div class="pv-topbar-left">
    <img src="<?php echo $logo_url; ?>" alt="<?php esc_attr_e( 'Equine Event Manager', 'equine-event-manager' ); ?>" style="height:28px;width:auto;display:block;margin-right:12px">
    <div>
      <div class="pv-topbar-title"><?php esc_html_e( 'Print View — Division Entrants', 'equine-event-manager' ); ?></div>
      <div class="pv-topbar-sub"><?php echo wp_kses_post( $detail_sub ); ?></div>
    </div>
  </div>
  <div class="pv-topbar-btns">
    <a href="<?php echo $back_url; ?>" class="btn-pv-exit">← <?php esc_html_e( 'Back to Entries', 'equine-event-manager' ); ?></a>
    <button class="btn-pv-print" onclick="window.print()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      <?php esc_html_e( 'Print / Save PDF', 'equine-event-manager' ); ?>
    </button>
  </div>
</div>
<div class="pv-body">
  <div class="pv-header">
    <div class="pv-header-top">
      <div>
        <div class="pv-eyebrow"><?php esc_html_e( 'Entries · Division Entrants', 'equine-event-manager' ); ?></div>
        <div class="pv-header-title"><?php echo esc_html( $div_name ); ?></div>
        <div class="pv-meta">
          <?php if ( '' !== $price ) : ?>
            <span><strong><?php esc_html_e( 'Entry fee:', 'equine-event-manager' ); ?></strong> $<?php echo esc_html( number_format( (float) $price, 2 ) ); ?> <?php esc_html_e( 'per spot', 'equine-event-manager' ); ?></span>
          <?php endif; ?>
          <?php if ( $spots_int > 0 ) : ?>
            <span><strong><?php esc_html_e( 'Spots:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( (string) $spots_int ); ?> <?php esc_html_e( 'total', 'equine-event-manager' ); ?></span>
          <?php endif; ?>
          <span><strong><?php esc_html_e( 'Entered:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( (string) $entered ); ?></span>
        </div>
      </div>
    </div>
  </div>
  <?php if ( '' !== $event ) : ?>
  <div class="pv-event-band">
    <span><strong><?php esc_html_e( 'Event:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $event ); ?></span>
    <?php if ( '' !== $dates_str ) : ?>
      <span><strong><?php esc_html_e( 'Dates:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( $dates_str ); ?></span>
    <?php endif; ?>
    <?php if ( $rid > 0 ) : ?>
      <span><strong><?php esc_html_e( 'Reservation:', 'equine-event-manager' ); ?></strong> <?php echo esc_html( get_the_title( $rid ) ?: (string) $rid ); ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="pv-stats">
    <div class="pv-stat">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#eff6ff;border-radius:7px;flex-shrink:0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1668F2" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></span>
      <div class="pv-stat-text">
        <span class="pv-stat-num"><?php echo esc_html( (string) $entered ); ?></span>
        <span class="pv-stat-label"><?php esc_html_e( 'Entered', 'equine-event-manager' ); ?></span>
      </div>
    </div>
    <div class="pv-stat">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:#eff6ff;border-radius:8px;flex-shrink:0"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1668F2" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></span>
      <div class="pv-stat-text">
        <span class="pv-stat-label"><?php esc_html_e( 'Spots', 'equine-event-manager' ); ?></span>
        <span class="pv-stat-num"><?php echo esc_html( $spots_int > 0 ? (string) $spots_int : __( 'Unlimited', 'equine-event-manager' ) ); ?></span>
      </div>
    </div>
    <div class="pv-stat pv-stat--<?php echo esc_attr( $left_tone ); ?>">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:#f0fdf4;border-radius:8px;flex-shrink:0"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
      <div class="pv-stat-text">
        <span class="pv-stat-label"><?php esc_html_e( 'Spots Left', 'equine-event-manager' ); ?></span>
        <span class="pv-stat-num"><?php echo esc_html( null === $left ? '—' : (string) $left ); ?></span>
        <?php if ( $oversold > 0 ) : ?>
          <span class="pv-stat-oversold"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Oversold by %d', 'equine-event-manager' ), $oversold ) ); ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="pv-table-wrap">
    <table class="pv-table">
      <thead>
        <tr>
          <th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
          <th class="c"><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></th>
          <th><?php esc_html_e( 'Order', 'equine-event-manager' ); ?></th>
          <th><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></th>
          <th class="c"><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ( empty( $entrants ) ) : ?>
          <tr><td colspan="5" class="pv-table-empty"><?php esc_html_e( 'No entries yet.', 'equine-event-manager' ); ?></td></tr>
        <?php else : ?>
          <?php foreach ( $entrants as $row ) :
            $ekey   = (string) $row['order_key'];
            $order  = ( '' !== $ekey && class_exists( 'EEM_Orders_Repository' ) ) ? ( new EEM_Orders_Repository() )->get_order( $ekey ) : null;
            $ord_no = ( is_array( $order ) && ! empty( $order['order_number'] ) ) ? sprintf( '#%05d', (int) $order['order_number'] ) : '—';
            $st     = (string) $row['status'];
            $st_lbl = isset( $status_labels[ $st ] ) ? $status_labels[ $st ] : ucfirst( $st );
          ?>
          <tr>
            <td class="pv-customer"><?php echo esc_html( '' !== (string) $row['customer_name'] ? (string) $row['customer_name'] : (string) $row['email'] ); ?></td>
            <td class="pv-qty c"><?php echo esc_html( (string) (int) $row['qty'] ); ?></td>
            <td class="pv-order"><?php echo esc_html( $ord_no ); ?></td>
            <td class="pv-date"><?php echo esc_html( mysql2date( 'M j, Y', (string) $row['created_at'] ) ); ?></td>
            <td class="c"><span class="pv-status pv-status-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_lbl ); ?></span></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <?php if ( ! empty( $entrants ) ) : ?>
      <tfoot>
        <tr style="border-top:2px solid #e2e8f4;background:#F7F9FC">
          <td style="padding:9px 12px;font-size:13px;font-weight:700;color:#0d1b3e"><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></td>
          <td style="padding:9px 12px;text-align:center;font-size:13px;font-weight:700;color:#0d1b3e"><?php echo esc_html( (string) $total_qty ); ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="pv-footer">
    <span><?php echo esc_html( $div_name ); ?><?php if ( '' !== $event ) : ?> &middot; <?php echo esc_html( $event ); ?><?php endif; ?></span>
    <span><?php echo esc_html( $site_name ); ?> &middot; <?php echo esc_html( $printed ); ?></span>
  </div>
</div>
</body>
</html>
		<?php
		exit;
	}

}
