<?php
/**
 * Customers list admin page (V1 commit #3).
 *
 * A top-level "Customers" list (WooCommerce-style) of every customer who has
 * interacted with the plugin, aggregated by email via
 * EEM_Customer_Profile_Repo::get_customer_list(). Columns: Customer (Last,
 * First-sortable) | Email | Total Orders | Total Spent | Last Activity. Search by
 * name/email, sortable headers, and pagination (paginated from the start per the
 * locked Q6 decision). Each row links to the existing read-only Customer Profile
 * page (`?page=equine-event-manager-customer&customer_email=…`).
 *
 * Read-only aggregate model — there is no customer entity, so rows are keyed by
 * email and the page is purely a navigational index into the profiles.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customers list page controller.
 */
class EEM_Customers_List_Page {

	/**
	 * Visible top-level submenu slug (plural — distinct from the hidden singular
	 * `equine-event-manager-customer` profile route).
	 */
	const MENU_SLUG = 'equine-event-manager-customers';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Build a Customers-list URL with the given query args layered on the base.
	 *
	 * @param array<string,mixed> $args
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list nav.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_name';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'desc' : 'asc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed_orderby = array( 'last_name', 'name', 'orders', 'spent', 'activity' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'last_name';
		}

		$repo = new EEM_Customer_Profile_Repo();
		$data = $repo->get_customer_list(
			array(
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'paged'    => $paged,
				'per_page' => self::PER_PAGE,
			)
		);

		eem_render_page_open(
			array(
				'title'      => __( 'Customers', 'equine-event-manager' ),
				'subtitle'   => sprintf(
					/* translators: %s: total customer count */
					_n( '%s customer', '%s customers', $data['total'], 'equine-event-manager' ),
					number_format_i18n( $data['total'] )
				),
				'breadcrumb' => array(
					array( 'label' => __( 'Customers', 'equine-event-manager' ) ),
				),
			)
		);

		self::render_toolbar( $search );
		self::render_table( $data, $orderby, $order, $search );
		self::render_pagination( $data, $orderby, $order, $search );

		eem_render_page_close();
	}

	/**
	 * Search toolbar (GET form).
	 *
	 * @param string $search
	 * @return void
	 */
	private static function render_toolbar( string $search ): void {
		?>
		<div class="eem-customers-toolbar">
			<form class="eem-search-wrap" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<svg class="eem-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input type="search" name="s" class="eem-search-input" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search', 'equine-event-manager' ); ?>" />
			</form>
		</div>
		<?php
	}

	/**
	 * Customers table (desktop) + mobile cards.
	 *
	 * @param array<string,mixed> $data    Repo payload.
	 * @param string              $orderby Active sort column.
	 * @param string              $order   asc|desc.
	 * @param string              $search  Active search.
	 * @return void
	 */
	private static function render_table( array $data, string $orderby, string $order, string $search ): void {
		$rows = $data['rows'];
		?>
		<div class="eem-customers-table-wrap">
			<table class="eem-table eem-customers-table">
				<thead>
					<tr>
						<?php self::sortable_th( 'last_name', __( 'Customer', 'equine-event-manager' ), $orderby, $order, $search ); ?>
						<th><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></th>
						<?php self::sortable_th( 'orders', __( 'Total Orders', 'equine-event-manager' ), $orderby, $order, $search, 'eem-table-c' ); ?>
						<?php self::sortable_th( 'spent', __( 'Total Spent', 'equine-event-manager' ), $orderby, $order, $search, 'eem-table-r' ); ?>
						<?php self::sortable_th( 'activity', __( 'Last Activity', 'equine-event-manager' ), $orderby, $order, $search ); ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5" class="eem-customers-empty"><?php esc_html_e( 'No customers found.', 'equine-event-manager' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $profile_url = self::profile_url( $row['email'] ); ?>
							<tr>
								<td><a class="eem-customers-name" href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( EEM_Admin::format_customer_last_first( (string) $row['name'] ) ); ?></a></td>
								<td><a class="eem-customers-email" href="<?php echo esc_attr( 'mailto:' . $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></td>
								<td class="eem-table-c"><?php echo esc_html( number_format_i18n( (int) $row['orders'] ) ); ?></td>
								<td class="eem-table-r"><?php echo esc_html( $row['spent'] ); ?></td>
								<td><?php echo esc_html( $row['last_activity'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="eem-mobile-cards eem-customers-mobile">
			<?php foreach ( $rows as $row ) : ?>
				<div class="eem-mobile-card">
					<div class="eem-mobile-card-top">
						<a class="eem-mobile-card-id" href="<?php echo esc_url( self::profile_url( $row['email'] ) ); ?>"><?php echo esc_html( EEM_Admin::format_customer_last_first( (string) $row['name'] ) ); ?></a>
						<span class="eem-mobile-card-meta"><?php echo esc_html( $row['last_activity'] ); ?></span>
					</div>
					<div class="eem-mobile-card-sub"><?php echo esc_html( $row['email'] ); ?></div>
					<div class="eem-mobile-card-bottom">
						<span class="eem-mobile-card-meta"><?php
							echo esc_html( sprintf(
								/* translators: %s: number of orders */
								_n( '%s order', '%s orders', (int) $row['orders'], 'equine-event-manager' ),
								number_format_i18n( (int) $row['orders'] )
							) );
						?></span>
						<span class="eem-mobile-card-meta"><?php echo esc_html( $row['spent'] ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a sortable column header.
	 *
	 * @param string $key     orderby key.
	 * @param string $label   Header text.
	 * @param string $current Active orderby.
	 * @param string $order   Active order.
	 * @param string $search  Active search (preserved across sort).
	 * @param string $th_class Extra <th> class (alignment).
	 * @return void
	 */
	private static function sortable_th( string $key, string $label, string $current, string $order, string $search, string $th_class = '' ): void {
		$is_active  = ( $key === $current );
		// Numeric columns default to descending on first click; name to ascending.
		$default    = in_array( $key, array( 'orders', 'spent', 'activity' ), true ) ? 'desc' : 'asc';
		$next_order = $is_active ? ( 'asc' === $order ? 'desc' : 'asc' ) : $default;
		$href       = self::url(
			array(
				'orderby' => $key,
				'order'   => $next_order,
				'paged'   => 1,
				's'       => $search,
			)
		);
		$classes = trim( 'sortable ' . $th_class . ( $is_active ? ' is-sorted is-sorted--' . $order : '' ) );
		?>
		<th class="<?php echo esc_attr( $classes ); ?>">
			<a href="<?php echo esc_url( $href ); ?>">
				<?php echo esc_html( $label ); ?>
				<span class="eem-sort-icon" aria-hidden="true"><span></span><span></span></span>
			</a>
		</th>
		<?php
	}

	/**
	 * Pagination footer.
	 *
	 * @param array<string,mixed> $data
	 * @param string              $orderby
	 * @param string              $order
	 * @param string              $search
	 * @return void
	 */
	private static function render_pagination( array $data, string $orderby, string $order, string $search ): void {
		$pages = (int) $data['pages'];
		$paged = (int) $data['paged'];
		$total = (int) $data['total'];
		if ( $total < 1 ) {
			return;
		}

		$base = array(
			'orderby' => $orderby,
			'order'   => $order,
			's'       => $search,
		);
		$first = ( $paged - 1 ) * (int) $data['per_page'] + 1;
		$last  = min( $total, $paged * (int) $data['per_page'] );
		?>
		<div class="eem-table-footer">
			<div class="eem-table-footer-info"><?php
				echo esc_html( sprintf(
					/* translators: 1: first row, 2: last row, 3: total */
					__( 'Showing %1$s–%2$s of %3$s', 'equine-event-manager' ),
					number_format_i18n( $first ),
					number_format_i18n( $last ),
					number_format_i18n( $total )
				) );
			?></div>
			<?php if ( $pages > 1 ) : ?>
				<div class="eem-pagination-controls">
					<?php if ( $paged > 1 ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $paged - 1 ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'equine-event-manager' ); ?>">‹</a>
					<?php else : ?>
						<span class="eem-page-btn eem-page-btn--disabled" aria-disabled="true">‹</span>
					<?php endif; ?>
					<?php foreach ( self::page_window( $paged, $pages ) as $p ) : ?>
						<?php if ( '…' === $p ) : ?>
							<span class="eem-page-ellipsis">…</span>
						<?php elseif ( (int) $p === $paged ) : ?>
							<span class="eem-page-btn active" aria-current="page"><?php echo esc_html( number_format_i18n( (int) $p ) ); ?></span>
						<?php else : ?>
							<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => (int) $p ) ) ) ); ?>"><?php echo esc_html( number_format_i18n( (int) $p ) ); ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( $paged < $pages ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $paged + 1 ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'equine-event-manager' ); ?>">›</a>
					<?php else : ?>
						<span class="eem-page-btn eem-page-btn--disabled" aria-disabled="true">›</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Compute the page-number window (with … gaps) around the current page.
	 *
	 * @param int $paged
	 * @param int $pages
	 * @return array<int, int|string>
	 */
	private static function page_window( int $paged, int $pages ): array {
		if ( $pages <= 7 ) {
			return range( 1, $pages );
		}
		$out   = array( 1 );
		$start = max( 2, $paged - 1 );
		$end   = min( $pages - 1, $paged + 1 );
		if ( $start > 2 ) {
			$out[] = '…';
		}
		for ( $p = $start; $p <= $end; $p++ ) {
			$out[] = $p;
		}
		if ( $end < $pages - 1 ) {
			$out[] = '…';
		}
		$out[] = $pages;
		return $out;
	}

	/**
	 * Customer Profile URL for an email (reuses the Orders-list helper so the
	 * route convention stays single-sourced).
	 *
	 * @param string $email
	 * @return string
	 */
	private static function profile_url( string $email ): string {
		if ( class_exists( 'EEM_Orders_List_Page' ) && method_exists( 'EEM_Orders_List_Page', 'customer_profile_url' ) ) {
			return EEM_Orders_List_Page::customer_profile_url( $email );
		}
		return add_query_arg(
			array( 'page' => 'equine-event-manager-customer', 'customer_email' => rawurlencode( $email ) ),
			admin_url( 'admin.php' )
		);
	}
}
