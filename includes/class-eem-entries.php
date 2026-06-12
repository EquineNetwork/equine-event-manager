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

		$rid       = (int) get_post_meta( $division_id, self::META_RESERVATION, true );
		$res_label = $rid > 0 ? self::reservation_label( $rid ) : array( 'title' => '', 'end_date' => '' );
		$event     = (string) $res_label['title'];
		$is_past   = self::event_is_past( (string) $res_label['end_date'] );
		$div_name  = (string) get_post_meta( $division_id, self::META_DIVISION_NAME, true );
		$price     = (string) get_post_meta( $division_id, self::META_PRICE, true );
		$spots_raw = get_post_meta( $division_id, self::META_SPOTS, true );
		$spots_int = ( '' === (string) $spots_raw || (int) $spots_raw <= 0 ) ? 0 : (int) $spots_raw;

		$entered   = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::entered_count( $division_id ) : 0;
		$left      = ( $spots_int > 0 ) ? max( 0, $spots_int - $entered ) : null;
		$oversold  = ( $spots_int > 0 && $entered > $spots_int ) ? ( $entered - $spots_int ) : 0;
		$entrants  = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::get_entrants( $division_id ) : array();

		$title = '' !== $event && '' !== $div_name ? $event . ' - ' . $div_name : get_the_title( $post );

		// "Edit Division" + (when connected) "View Event" actions.
		$actions  = sprintf(
			'<a class="eem-btn eem-btn-electric" href="%s">%s</a>',
			esc_url( self::editor_url( $division_id ) ),
			esc_html__( 'Edit Division', 'equine-event-manager' )
		);
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
		<?php if ( $is_past ) : ?>
			<p class="eem-notice-inline"><span class="eem-res-status eem-res-status--past"><?php esc_html_e( 'Past', 'equine-event-manager' ); ?></span> <span class="eem-orders-count"><?php esc_html_e( 'This event has already ended.', 'equine-event-manager' ); ?></span></p>
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
		<div class="eem-dashboard-kpi-grid eem-division-stat-grid">
			<div class="eem-dashboard-kpi-card eem-dashboard-kpi-card--blue">
				<div class="eem-dashboard-kpi-label"><?php esc_html_e( 'Entered', 'equine-event-manager' ); ?></div>
				<div class="eem-dashboard-kpi-value"><?php echo esc_html( (string) $entered ); ?></div>
			</div>
			<div class="eem-dashboard-kpi-card eem-dashboard-kpi-card--green">
				<div class="eem-dashboard-kpi-label"><?php esc_html_e( 'Spots', 'equine-event-manager' ); ?></div>
				<div class="eem-dashboard-kpi-value"><?php echo esc_html( $spots_int > 0 ? (string) $spots_int : __( 'Unlimited', 'equine-event-manager' ) ); ?></div>
			</div>
			<div class="eem-dashboard-kpi-card eem-dashboard-kpi-card--<?php echo esc_attr( $left_tone ); ?>">
				<div class="eem-dashboard-kpi-label"><?php esc_html_e( 'Spots Left', 'equine-event-manager' ); ?></div>
				<div class="eem-dashboard-kpi-value"><?php echo esc_html( null === $left ? '—' : (string) $left ); ?></div>
				<?php if ( $oversold > 0 ) : ?>
					<div class="eem-dashboard-kpi-sub eem-dashboard-kpi-tone--down"><?php
						echo esc_html( sprintf(
							/* translators: %d: count oversold by. */
							__( 'Oversold by %d', 'equine-event-manager' ),
							$oversold
						) );
					?></div>
				<?php endif; ?>
			</div>
		</div>

		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Qty', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Order', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entrants ) ) : ?>
						<tr><td colspan="5" class="eem-table-empty"><?php esc_html_e( 'No entries yet.', 'equine-event-manager' ); ?></td></tr>
					<?php else : ?>
						<?php
						$status_labels = array(
							'paid'      => __( 'Paid', 'equine-event-manager' ),
							'unpaid'    => __( 'Unpaid', 'equine-event-manager' ),
							'refunded'  => __( 'Refunded', 'equine-event-manager' ),
							'cancelled' => __( 'Cancelled', 'equine-event-manager' ),
						);
						foreach ( $entrants as $row ) :
							$ekey   = (string) $row['order_key'];
							$order  = ( '' !== $ekey && class_exists( 'EEM_Orders_Repository' ) ) ? ( new EEM_Orders_Repository() )->get_order( $ekey ) : null;
							$ord_no = ( is_array( $order ) && ! empty( $order['order_number'] ) ) ? sprintf( '#%05d', (int) $order['order_number'] ) : '—';
							$ord_url = ( '' !== $ekey && class_exists( 'EEM_Orders_List_Page' ) ) ? EEM_Orders_List_Page::order_detail_url( $ekey ) : '';
							$st     = (string) $row['status'];
							$st_lbl = isset( $status_labels[ $st ] ) ? $status_labels[ $st ] : ucfirst( $st );
							?>
							<tr>
								<td><?php echo esc_html( '' !== (string) $row['customer_name'] ? (string) $row['customer_name'] : (string) $row['email'] ); ?></td>
								<td><?php echo esc_html( (string) (int) $row['qty'] ); ?></td>
								<td><?php
									if ( '' !== $ord_url && '—' !== $ord_no ) {
										echo '<a class="eem-res-name" href="' . esc_url( $ord_url ) . '">' . esc_html( $ord_no ) . '</a>';
									} else {
										echo esc_html( $ord_no );
									}
								?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ) ); ?></td>
								<td><span class="eem-status-badge eem-status-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_lbl ); ?></span></td>
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view dispatch.
		$detail_id = isset( $_GET['division_id'] ) ? absint( wp_unslash( $_GET['division_id'] ) ) : 0;
		if ( $detail_id > 0 ) {
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
			$rid       = (int) get_post_meta( $e->ID, self::META_RESERVATION, true );
			$res_label = $rid > 0 ? self::reservation_label( $rid ) : array( 'title' => '', 'end_date' => '' );
			$event     = (string) $res_label['title'];
			$is_past   = self::event_is_past( (string) $res_label['end_date'] );
			$div_name  = (string) get_post_meta( $e->ID, self::META_DIVISION_NAME, true );
			$price     = (string) get_post_meta( $e->ID, self::META_PRICE, true );
			$spots     = get_post_meta( $e->ID, self::META_SPOTS, true );
			$spots_int = ( '' === (string) $spots || (int) $spots <= 0 ) ? 0 : (int) $spots;
			$entered   = class_exists( 'EEM_Division_Entries' ) ? EEM_Division_Entries::entered_count( (int) $e->ID ) : 0;
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
				'oversold'  => ( $spots_int > 0 && $entered > $spots_int ) ? ( $entered - $spots_int ) : 0,
				'is_pub'    => $is_pub,
				'is_past'   => $is_past,
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

		if ( ! empty( $rows ) ) :
			?>
			<div class="eem-list-toolbar">
				<div class="eem-list-toolbar-left">
					<select class="eem-toolbar-select" data-eem-input-action="entries-filter-event" data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>">
						<option value=""><?php esc_html_e( 'All events', 'equine-event-manager' ); ?></option>
						<?php foreach ( $events as $eid => $etitle ) : ?>
							<option value="<?php echo esc_attr( (string) $eid ); ?>"><?php echo esc_html( $etitle ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="eem-list-toolbar-right">
					<span class="eem-item-count" data-eem-entries-count><?php
						echo esc_html( sprintf(
							/* translators: %d: division count. */
							_n( '%d division', '%d divisions', count( $rows ), 'equine-event-manager' ),
							count( $rows )
						) );
					?></span>
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
						<th class="eem-sortable" data-eem-sort="status" data-eem-sort-type="text"><?php esc_html_e( 'Status', 'equine-event-manager' ); ?><span class="eem-sort-ind" aria-hidden="true"></span></th>
						<th><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
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
								data-sort-status="<?php echo esc_attr( $r['is_pub'] ? '1' : '0' ); ?>">
								<td><a class="eem-res-name" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $r['name'] ); ?></a></td>
								<td><?php echo '' !== $r['event'] ? esc_html( $r['event'] ) : '<span class="eem-orders-count is-zero">' . esc_html__( '— not connected —', 'equine-event-manager' ) . '</span>'; ?></td>
								<td><?php echo '' !== $r['price'] ? esc_html( '$' . number_format( (float) $r['price'], 2 ) ) : '<span class="eem-orders-count is-zero">—</span>'; ?></td>
								<td>
									<?php
									echo esc_html( $r['entered'] . ' / ' . ( $r['spots_int'] > 0 ? (string) $r['spots_int'] : __( 'Unlimited', 'equine-event-manager' ) ) );
									if ( $r['oversold'] > 0 ) {
										echo ' <span class="eem-status-badge eem-status-refunded">' . esc_html(
											/* translators: %d: count oversold by. */
											sprintf( __( 'oversold by %d', 'equine-event-manager' ), $r['oversold'] )
										) . '</span>';
									}
									?>
								</td>
								<td>
								<span class="eem-res-status eem-res-status--<?php echo $r['is_pub'] ? 'active' : 'draft'; ?>"><?php echo esc_html( $r['is_pub'] ? __( 'Published', 'equine-event-manager' ) : __( 'Draft', 'equine-event-manager' ) ); ?></span>
								<?php if ( ! empty( $r['is_past'] ) ) : ?>
									<span class="eem-res-status eem-res-status--past"><?php esc_html_e( 'Past', 'equine-event-manager' ); ?></span>
								<?php endif; ?>
							</td>
								<td><a class="eem-btn eem-btn-sm" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						<tr class="eem-entries-empty-filtered" hidden><td colspan="6" class="eem-table-empty"><?php esc_html_e( 'No divisions for this event.', 'equine-event-manager' ); ?></td></tr>
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
	 * @return array{title:string,dates:string,end_date:string}
	 */
	private static function reservation_label( int $reservation_id ): array {
		$title    = '';
		$dates    = '';
		$end_date = '';
		if ( $reservation_id > 0 ) {
			$title = (string) get_the_title( $reservation_id );
			if ( class_exists( 'EEM_Reservation_Source_Resolver' ) && class_exists( 'EEM_Dashboard_Repo' ) ) {
				$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
				if ( ! empty( $fields['title'] ) ) {
					$title = (string) $fields['title'];
				}
				$end_date = isset( $fields['end_date'] ) ? (string) $fields['end_date'] : '';
				$dates    = EEM_Dashboard_Repo::format_date_range(
					isset( $fields['start_date'] ) ? (string) $fields['start_date'] : '',
					$end_date
				);
			}
		}
		return array( 'title' => $title, 'dates' => $dates, 'end_date' => $end_date );
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

		$reservation_id = (int) get_post_meta( $entry_id, self::META_RESERVATION, true );
		$description    = (string) get_post_meta( $entry_id, self::META_DESCRIPTION, true );
		$division_name  = (string) get_post_meta( $entry_id, self::META_DIVISION_NAME, true );
		$price_raw      = get_post_meta( $entry_id, self::META_PRICE, true );
		$spots_raw      = get_post_meta( $entry_id, self::META_SPOTS, true );
		$max_raw        = get_post_meta( $entry_id, self::META_MAX, true );

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
							'control_html' => '<div class="eem-price-wrap"><span class="eem-price-prefix">$</span><input class="eem-field-input eem-price-input" type="number" min="0" step="0.01" id="eem-division-price" value="' . esc_attr( $price_val ) . '" placeholder="0.00"></div>',
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
							'control_html' => '<textarea class="eem-field-input" id="eem-entry-description" rows="3" placeholder="' . esc_attr__( 'e.g. Pre-purchase your division entry below.', 'equine-event-manager' ) . '">' . esc_textarea( $description ) . '</textarea>',
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
							'body_html'     => $eem_division_body,
						) );
						eem_render_reservation_editor_section( array(
							'key'           => 'entry-description',
							'title'         => __( 'Description', 'equine-event-manager' ),
							'icon_tone'     => 'blue',
							'icon_key'      => 'file-text',
							'enable_toggle' => false,
							'collapsed'     => false,
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

		update_post_meta( $entry_id, self::META_RESERVATION, $reservation_id );
		update_post_meta( $entry_id, self::META_DESCRIPTION, $description );
		update_post_meta( $entry_id, self::META_DIVISION_NAME, $division_name );
		update_post_meta( $entry_id, self::META_PRICE, $price );
		update_post_meta( $entry_id, self::META_SPOTS, $spots );
		update_post_meta( $entry_id, self::META_MAX, $max );

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
			$rid = (int) get_post_meta( $post_id, self::META_RESERVATION, true );
			echo $rid > 0 ? esc_html( get_the_title( $rid ) ) : '<span style="color:#b91c1c">' . esc_html__( '— not connected —', 'equine-event-manager' ) . '</span>';
		} elseif ( 'eem_entry_price' === $column ) {
			$price = (string) get_post_meta( $post_id, self::META_PRICE, true );
			echo '' !== $price ? esc_html( '$' . number_format( (float) $price, 2 ) ) : '—';
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
			$title = trim( (string) get_post_meta( $p->ID, self::META_DIVISION_NAME, true ) );
			if ( '' === $title ) {
				continue;
			}
			$options[ 'entry_' . $p->ID ] = array(
				'title'            => $title,
				'price'            => number_format( (float) get_post_meta( $p->ID, self::META_PRICE, true ), 2, '.', '' ),
				'inventory'        => absint( get_post_meta( $p->ID, self::META_SPOTS, true ) ),
				'max_per_customer' => absint( get_post_meta( $p->ID, self::META_MAX, true ) ),
				'division_id'      => (int) $p->ID,
			);
		}

		return $options;
	}
}
