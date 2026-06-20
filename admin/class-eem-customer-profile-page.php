<?php
/**
 * Customer Profile admin page (C9.B).
 *
 * Renders `.mockups/customer_profile_page.html` against live data from
 * EEM_Customer_Profile_Repo. Read-only aggregate model: a "customer" is the set
 * of orders sharing an email, so this page shows identity + KPI stats, order
 * history, reservation history, and a merged activity timeline. The only
 * writable surface is the per-customer Internal Notes box (AJAX-saved to the
 * eem_customer_notes option map).
 *
 * Header actions shipped in v1: Send Email (mailto:) and Export CSV (reuses the
 * C15 report exporter). Edit / Merge / Delete are intentionally absent — they
 * require a first-class customer entity (a wp_eem_customers table) that the
 * read-only model deliberately omits.
 *
 * Reached at admin.php?page=equine-event-manager-customer&customer_email=…; the
 * route is registered as a hidden submenu by
 * EEM_Orders_List_Page::register_customer_profile_stub(), whose callback now
 * points here.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer Profile page controller.
 */
class EEM_Customer_Profile_Page {

	/**
	 * Nonce action for the Internal Notes save + CSV export.
	 */
	const NONCE_ACTION = 'eem_customer_profile';

	/**
	 * Map an order status slug to the shared `.eem-status-{suffix}` CSS class.
	 *
	 * @var array<string,string>
	 */
	private static array $status_class = array(
		'paid'                => 'paid',
		'partially-paid'      => 'partial',
		'unpaid'              => 'unpaid',
		'invoice-sent'        => 'invoice',
		'refunded'            => 'refunded',
		'partially-refunded'  => 'partial',
		'cancelled'           => 'cancelled',
	);

	/**
	 * Map an order type_labels key to its `.eem-type-{suffix}` CSS class.
	 *
	 * @var array<string,string>
	 */
	private static array $type_class = array(
		'stall'   => 'stall',
		'rv'      => 'rv',
		'add_ons' => 'addon',
		'group'   => 'group',
	);

	/**
	 * Register AJAX + admin-post hooks. Called from the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_save_customer_note', array( __CLASS__, 'ajax_save_note' ) );
		add_action( 'admin_post_eem_export_customer_csv', array( __CLASS__, 'handle_export_csv' ) );
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

		$email = isset( $_GET['customer_email'] ) ? sanitize_email( wp_unslash( $_GET['customer_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$repo    = new EEM_Customer_Profile_Repo();
		$profile = '' !== $email ? $repo->get_profile( $email ) : null;

		if ( null === $profile ) {
			self::render_not_found( $email );
			return;
		}

		$orders_url = class_exists( 'EEM_Orders_List_Page' ) ? EEM_Orders_List_Page::url() : admin_url( 'admin.php?page=equine-event-manager-orders' );

		eem_render_page_open( array(
			'title'      => $profile['name'],
			'meta'       => self::header_meta_html( $profile ),
			'actions'    => self::header_actions_html( $profile ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders', 'equine-event-manager' ), 'url' => $orders_url ),
				array( 'label' => $profile['name'] ),
			),
		) );

		self::render_stats( $profile['stats'] );
		self::render_details( $profile );
		self::render_notes( $profile );
		self::render_order_history( $profile['orders'], $email );
		self::render_reservation_history( $profile['reservations'] );
		self::render_activity( $profile['activity'] );

		eem_render_page_close();
	}

	/**
	 * Rich header meta line: email (mailto) · phone · customer-since.
	 *
	 * @param array<string,mixed> $p Profile payload.
	 * @return string
	 */
	private static function header_meta_html( array $p ): string {
		$parts = array();
		if ( '' !== $p['email'] ) {
			$parts[] = sprintf(
				'<a href="%s">%s</a>',
				esc_attr( 'mailto:' . $p['email'] ),
				esc_html( $p['email'] )
			);
		}
		if ( '' !== $p['phone'] ) {
			$parts[] = esc_html( $p['phone'] );
		}
		if ( '' !== $p['customer_since'] ) {
			$parts[] = esc_html( sprintf(
				/* translators: %s: month and year, e.g. "May 2025" */
				__( 'Customer since %s', 'equine-event-manager' ),
				$p['customer_since']
			) );
		}
		return '<div class="eem-cp-subtitle">' . implode( ' <span class="eem-cp-dot">·</span> ', $parts ) . '</div>';
	}

	/**
	 * Header action buttons: Send Email (mailto) + Export CSV.
	 *
	 * @param array<string,mixed> $p Profile payload.
	 * @return string
	 */
	private static function header_actions_html( array $p ): string {
		$buttons = '';

		if ( '' !== $p['email'] ) {
			$buttons .= sprintf(
				'<a class="eem-btn eem-btn-ghost" href="%s">%s</a>',
				esc_attr( 'mailto:' . $p['email'] ),
				esc_html__( 'Send Email', 'equine-event-manager' )
			);
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'         => 'eem_export_customer_csv',
					'customer_email' => rawurlencode( $p['email'] ),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
		$buttons .= sprintf(
			'<a class="eem-btn eem-btn-ghost" href="%s">%s</a>',
			esc_url( $export_url ),
			esc_html__( 'Export CSV', 'equine-event-manager' )
		);

		return $buttons;
	}

	/**
	 * Render a card section header: a blue icon chip + title, plus optional
	 * right-aligned actions markup. Matches the blue card-title icon treatment on
	 * Order Detail / Dashboard.
	 *
	 * @param string $icon_key EEM_Dashboard_Icons glyph key.
	 * @param string $title    Already-translated section title.
	 * @param string $actions  Optional caller-escaped actions HTML (right side).
	 * @return void
	 */
	private static function section_header( string $icon_key, string $title, string $actions = '' ): void {
		$icon = class_exists( 'EEM_Dashboard_Icons' ) ? EEM_Dashboard_Icons::svg( $icon_key ) : '';
		?>
		<section class="eem-section-header">
			<div class="eem-section-header-left">
				<span class="eem-section-icon eem-section-icon--blue"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?></span>
				<h2 class="eem-section-title"><?php echo esc_html( $title ); ?></h2>
			</div>
			<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built escaped markup. ?>
		</section>
		<?php
	}

	/**
	 * KPI stats grid (4 cards).
	 *
	 * @param array<string,mixed> $s Stats payload.
	 * @return void
	 */
	private static function render_stats( array $s ): void {
		$paid   = (int) $s['paid_count'];
		$unpaid = (int) $s['unpaid_count'];
		?>
		<section class="eem-cp-section eem-cp-stats">
			<div class="eem-cp-stats-grid">
				<div class="eem-cp-stat-card eem-cp-stat-card--electric">
					<div class="eem-cp-stat-label"><?php esc_html_e( 'Lifetime Spend', 'equine-event-manager' ); ?></div>
					<div class="eem-cp-stat-value"><?php echo esc_html( $s['lifetime_spend'] ); ?></div>
					<div class="eem-cp-stat-meta"><?php
						echo esc_html( sprintf(
							/* translators: %s: number of orders */
							_n( 'across %s order', 'across %s orders', (int) $s['orders_count'], 'equine-event-manager' ),
							number_format_i18n( (int) $s['orders_count'] )
						) );
					?></div>
				</div>
				<div class="eem-cp-stat-card">
					<div class="eem-cp-stat-label"><?php esc_html_e( 'Total Orders', 'equine-event-manager' ); ?></div>
					<div class="eem-cp-stat-value"><?php echo esc_html( number_format_i18n( (int) $s['orders_count'] ) ); ?></div>
					<div class="eem-cp-stat-meta"><?php
						echo esc_html( sprintf(
							/* translators: 1: paid order count, 2: unpaid order count */
							__( '%1$d paid · %2$d unpaid', 'equine-event-manager' ),
							$paid,
							$unpaid
						) );
					?></div>
				</div>
				<div class="eem-cp-stat-card eem-cp-stat-card--teal">
					<div class="eem-cp-stat-label"><?php esc_html_e( 'Avg Order Value', 'equine-event-manager' ); ?></div>
					<div class="eem-cp-stat-value"><?php echo esc_html( $s['avg_order_value'] ); ?></div>
					<div class="eem-cp-stat-meta"><?php esc_html_e( 'across all orders', 'equine-event-manager' ); ?></div>
				</div>
				<div class="eem-cp-stat-card">
					<div class="eem-cp-stat-label"><?php esc_html_e( 'Last Order', 'equine-event-manager' ); ?></div>
					<div class="eem-cp-stat-value eem-cp-stat-value--sm"><?php echo esc_html( $s['last_order_date'] ); ?></div>
					<div class="eem-cp-stat-meta"><?php echo esc_html( $s['last_order_rel'] ); ?></div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Customer Details: Contact / Billing Address / Account Info.
	 *
	 * @param array<string,mixed> $p Profile payload.
	 * @return void
	 */
	private static function render_details( array $p ): void {
		$billing = $p['billing'];
		?>
		<?php self::section_header( 'users', __( 'Customer Details', 'equine-event-manager' ) ); ?>
		<section class="eem-cp-section eem-cp-details-grid">
			<div class="eem-cp-detail">
				<div class="eem-cp-detail-label"><?php esc_html_e( 'Contact', 'equine-event-manager' ); ?></div>
				<div class="eem-cp-detail-body">
					<?php if ( '' !== $p['email'] ) : ?>
						<a href="<?php echo esc_attr( 'mailto:' . $p['email'] ); ?>"><?php echo esc_html( $p['email'] ); ?></a><br>
					<?php endif; ?>
					<?php echo esc_html( '' !== $p['phone'] ? $p['phone'] : __( 'No phone on file', 'equine-event-manager' ) ); ?>
				</div>
			</div>
			<div class="eem-cp-detail">
				<div class="eem-cp-detail-label"><?php esc_html_e( 'Billing Address', 'equine-event-manager' ); ?></div>
				<div class="eem-cp-detail-body">
					<?php if ( ! empty( $billing['lines'] ) ) : ?>
						<address>
							<?php echo esc_html( $billing['name'] ); ?><br>
							<?php echo wp_kses( implode( '<br>', array_map( 'esc_html', $billing['lines'] ) ), array( 'br' => array() ) ); ?>
						</address>
					<?php else : ?>
						<span class="eem-cp-muted"><?php esc_html_e( 'No billing address on file', 'equine-event-manager' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="eem-cp-detail">
				<div class="eem-cp-detail-label"><?php esc_html_e( 'Account Info', 'equine-event-manager' ); ?></div>
				<div class="eem-cp-detail-body">
					<?php if ( '' !== $p['customer_since'] ) : ?>
						<?php echo esc_html( sprintf(
							/* translators: %s: month and year */
							__( 'Customer since %s', 'equine-event-manager' ),
							$p['customer_since']
						) ); ?><br>
					<?php endif; ?>
					<?php esc_html_e( 'Status:', 'equine-event-manager' ); ?>
					<span class="eem-status-badge eem-status-active"><?php esc_html_e( 'Active', 'equine-event-manager' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Internal Notes (admin-only, AJAX-saved).
	 *
	 * @param array<string,mixed> $p Profile payload.
	 * @return void
	 */
	private static function render_notes( array $p ): void {
		?>
		<?php self::section_header( 'file-text', __( 'Internal Notes', 'equine-event-manager' ) ); ?>
		<section class="eem-cp-section eem-cp-notes"
			data-eem-customer-note
			data-eem-email="<?php echo esc_attr( $p['email'] ); ?>"
			data-eem-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
			<textarea class="eem-cp-notes-input" data-eem-note-input
				placeholder="<?php esc_attr_e( 'Add internal notes about this customer (not visible to the customer)…', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $p['note'] ); ?></textarea>
			<div class="eem-cp-notes-actions">
				<button type="button" class="eem-btn eem-btn-electric" data-eem-action="save-customer-note"><?php esc_html_e( 'Save Notes', 'equine-event-manager' ); ?></button>
			</div>
		</section>
		<?php
	}

	/**
	 * Order History table + mobile cards.
	 *
	 * @param array<int,array<string,mixed>> $orders Order rows.
	 * @param string                         $email  Customer email (for View All).
	 * @return void
	 */
	private static function render_order_history( array $orders, string $email ): void {
		$view_all = class_exists( 'EEM_Orders_List_Page' )
			? EEM_Orders_List_Page::url( array( 's' => $email ) )
			: admin_url( 'admin.php?page=equine-event-manager-orders' );
		$count = count( $orders );
		?>
		<?php
		$order_actions = sprintf(
			'<div class="eem-section-actions"><a class="eem-btn eem-btn-ghost" href="%s">%s</a></div>',
			esc_url( $view_all ),
			esc_html__( 'View All Orders', 'equine-event-manager' )
		);
		self::section_header( 'package', __( 'Order History', 'equine-event-manager' ), $order_actions );
		?>
		<section class="eem-cp-section eem-cp-table-section">
			<div class="eem-table-wrap">
				<table class="eem-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order #', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></th>
							<th class="eem-table-r"><?php esc_html_e( 'Total', 'equine-event-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $o ) : ?>
							<tr>
								<td><a class="eem-cp-cell-link" href="<?php echo esc_url( self::order_url( $o ) ); ?>"><?php echo esc_html( self::order_number_display( $o['order_number'] ) ); ?></a></td>
								<td class="eem-cp-cell-event"><?php echo esc_html( '' !== $o['event_name'] ? $o['event_name'] : '—' ); ?></td>
								<td><?php echo self::type_badges_html( $o['type_labels'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo self::status_badge_html( $o['status_slug'], $o['status_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="eem-cp-cell-date"><?php echo esc_html( $o['date'] ); ?></td>
								<td class="eem-table-r"><?php echo esc_html( $o['total'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="eem-mobile-cards">
				<?php foreach ( $orders as $o ) : ?>
					<div class="eem-mobile-card">
						<div class="eem-mobile-card-top">
							<a class="eem-mobile-card-id" href="<?php echo esc_url( self::order_url( $o ) ); ?>"><?php echo esc_html( self::order_number_display( $o['order_number'] ) ); ?></a>
							<span class="eem-mobile-card-meta"><?php echo esc_html( $o['date'] ); ?></span>
						</div>
						<div class="eem-mobile-card-title"><?php echo esc_html( '' !== $o['event_name'] ? $o['event_name'] : '—' ); ?></div>
						<div class="eem-mobile-card-bottom">
							<div class="eem-mobile-card-badges">
								<?php echo self::type_badges_html( $o['type_labels'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo self::status_badge_html( $o['status_slug'], $o['status_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
							<div class="eem-mobile-card-meta"><?php echo esc_html( $o['total'] ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="eem-cp-table-footer">
				<span class="eem-cp-footer-showing"><?php
					echo esc_html( sprintf(
						/* translators: %s: number of orders */
						_n( 'Showing %s order', 'Showing %s orders', $count, 'equine-event-manager' ),
						number_format_i18n( $count )
					) );
				?></span>
			</div>
		</section>
		<?php
	}

	/**
	 * Reservation History table + mobile cards.
	 *
	 * @param array<int,array<string,mixed>> $reservations Reservation rows.
	 * @return void
	 */
	private static function render_reservation_history( array $reservations ): void {
		?>
		<?php self::section_header( 'calendar', __( 'Reservation History', 'equine-event-manager' ) ); ?>
		<section class="eem-cp-section eem-cp-table-section">
			<div class="eem-table-wrap">
				<table class="eem-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
							<th class="eem-table-c"><?php esc_html_e( 'Orders', 'equine-event-manager' ); ?></th>
							<th class="eem-table-r"><?php esc_html_e( 'Total Spent', 'equine-event-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $reservations as $r ) : ?>
							<tr>
								<td class="eem-cp-cell-event"><?php echo esc_html( '' !== $r['event_name'] ? $r['event_name'] : '—' ); ?></td>
								<td class="eem-cp-cell-date"><?php echo esc_html( '' !== $r['event_dates'] ? $r['event_dates'] : '—' ); ?></td>
								<td><?php echo self::type_badges_html( $r['type_labels'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="eem-table-c"><?php echo esc_html( number_format_i18n( (int) $r['orders'] ) ); ?></td>
								<td class="eem-table-r"><?php echo esc_html( $r['total'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="eem-mobile-cards">
				<?php foreach ( $reservations as $r ) : ?>
					<div class="eem-mobile-card">
						<div class="eem-mobile-card-top"><span class="eem-mobile-card-id"><?php echo esc_html( '' !== $r['event_name'] ? $r['event_name'] : '—' ); ?></span></div>
						<div class="eem-mobile-card-sub"><?php
							echo esc_html( sprintf(
								/* translators: 1: event dates, 2: order count, 3: total spent */
								__( '%1$s · %2$d orders · %3$s', 'equine-event-manager' ),
								'' !== $r['event_dates'] ? $r['event_dates'] : '—',
								(int) $r['orders'],
								$r['total']
							) );
						?></div>
						<div class="eem-mobile-card-badges"><?php echo self::type_badges_html( $r['type_labels'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Activity Log — merged across the customer's orders, via the shared C2 partial.
	 *
	 * @param array<int,array<string,mixed>> $entries Raw activity rows.
	 * @return void
	 */
	private static function render_activity( array $entries ): void {
		if ( class_exists( 'EEM_Order_Telemetry' ) && method_exists( 'EEM_Order_Telemetry', 'enrich_entry_for_render' ) ) {
			$entries = array_map( array( 'EEM_Order_Telemetry', 'enrich_entry_for_render' ), $entries );
		}
		?>
		<?php self::section_header( 'clock', __( 'Activity Log', 'equine-event-manager' ) ); ?>
		<section class="eem-cp-section eem-cp-activity">
			<?php
			if ( function_exists( 'eem_render_activity_log' ) ) {
				eem_render_activity_log( $entries, array(
					'empty_message' => __( 'No activity recorded yet for this customer.', 'equine-event-manager' ),
				) );
			}
			?>
		</section>
		<?php
	}

	// ── Rendering helpers ───────────────────────────────────────────────────

	/**
	 * Build the type-badge cluster HTML for an order/reservation row.
	 *
	 * @param array<string,string> $type_labels slug => label.
	 * @return string
	 */
	private static function type_badges_html( array $type_labels ): string {
		$html = '<div class="eem-type-badges-wrap">';
		foreach ( $type_labels as $slug => $label ) {
			$suffix = self::$type_class[ $slug ] ?? 'stall';
			$html  .= sprintf(
				'<span class="eem-type-badge eem-type-%s">%s</span>',
				esc_attr( $suffix ),
				esc_html( $label )
			);
		}
		return $html . '</div>';
	}

	/**
	 * Build a status-badge HTML span for an order.
	 *
	 * @param string $slug  Status slug.
	 * @param string $label Status label.
	 * @return string
	 */
	private static function status_badge_html( string $slug, string $label ): string {
		$suffix = self::$status_class[ $slug ] ?? 'draft';
		$label  = '' !== $label ? $label : ucwords( str_replace( '-', ' ', $slug ) );
		return sprintf(
			'<span class="eem-status-badge eem-status-%s">%s</span>',
			esc_attr( $suffix ),
			esc_html( $label )
		);
	}

	/**
	 * Order detail URL for a row.
	 *
	 * @param array<string,mixed> $o Order row.
	 * @return string
	 */
	private static function order_url( array $o ): string {
		$key = (string) ( $o['order_key'] ?? '' );
		if ( '' !== $key && class_exists( 'EEM_Order_Detail_Page' ) && method_exists( 'EEM_Order_Detail_Page', 'url' ) ) {
			return EEM_Order_Detail_Page::url( $key );
		}
		return admin_url( 'admin.php?page=equine-event-manager-orders' );
	}

	/**
	 * Format an order number as 5-digit zero-padded `#NNNNN` when numeric.
	 *
	 * @param string $number Raw order number.
	 * @return string
	 */
	private static function order_number_display( string $number ): string {
		if ( class_exists( 'EEM_Orders_List_Page' ) && method_exists( 'EEM_Orders_List_Page', 'format_order_number_display' ) ) {
			return EEM_Orders_List_Page::format_order_number_display( $number );
		}
		return is_numeric( $number ) ? sprintf( '#%05d', (int) $number ) : '#' . $number;
	}

	/**
	 * Graceful "customer not found" card.
	 *
	 * @param string $email Requested email.
	 * @return void
	 */
	private static function render_not_found( string $email ): void {
		$orders_url = class_exists( 'EEM_Orders_List_Page' ) ? EEM_Orders_List_Page::url() : admin_url( 'admin.php?page=equine-event-manager-orders' );
		eem_render_page_open( array(
			'title'      => __( 'Customer Profile', 'equine-event-manager' ),
			'subtitle'   => '' !== $email
				? sprintf(
					/* translators: %s: customer email */
					__( 'No orders found for %s.', 'equine-event-manager' ),
					$email
				)
				: __( 'No customer specified.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders', 'equine-event-manager' ), 'url' => $orders_url ),
				array( 'label' => __( 'Customer Profile', 'equine-event-manager' ) ),
			),
		) );
		?>
		<section class="eem-cp-section" style="padding:32px;text-align:center;color:#50575e;">
			<p><?php esc_html_e( 'This customer has no orders on record yet.', 'equine-event-manager' ); ?></p>
			<p style="margin-top:16px;"><a class="eem-btn eem-btn-electric" href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a></p>
		</section>
		<?php
		eem_render_page_close();
	}

	// ── AJAX + admin-post handlers ──────────────────────────────────────────

	/**
	 * AJAX: save the Internal Notes for a customer.
	 *
	 * @return void
	 */
	public static function ajax_save_note(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$note  = isset( $_POST['note'] ) ? wp_kses_post( wp_unslash( $_POST['note'] ) ) : '';

		if ( '' === $email ) {
			wp_send_json_error( array( 'message' => __( 'Missing customer.', 'equine-event-manager' ) ), 400 );
		}

		$repo = new EEM_Customer_Profile_Repo();
		$repo->save_note( $email, $note );

		wp_send_json_success( array( 'message' => __( 'Notes saved.', 'equine-event-manager' ) ) );
	}

	/**
	 * admin-post: stream a CSV of this customer's order history.
	 *
	 * @return void
	 */
	public static function handle_export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'equine-event-manager' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$email = isset( $_GET['customer_email'] ) ? sanitize_email( wp_unslash( $_GET['customer_email'] ) ) : '';
		if ( '' === $email ) {
			wp_die( esc_html__( 'No customer specified.', 'equine-event-manager' ) );
		}

		$repo    = new EEM_Customer_Profile_Repo();
		$profile = $repo->get_profile( $email );
		if ( null === $profile ) {
			wp_die( esc_html__( 'Customer not found.', 'equine-event-manager' ) );
		}

		$rows = array();
		foreach ( $profile['orders'] as $o ) {
			$rows[] = array(
				self::order_number_display( $o['order_number'] ),
				'' !== $o['event_name'] ? $o['event_name'] : '',
				implode( ' / ', array_values( $o['type_labels'] ) ),
				'' !== $o['status_label'] ? $o['status_label'] : $o['status_slug'],
				$o['date'],
				$o['total'],
			);
		}

		$dataset = array(
			'headers' => array(
				__( 'Order #', 'equine-event-manager' ),
				__( 'Event', 'equine-event-manager' ),
				__( 'Type', 'equine-event-manager' ),
				__( 'Status', 'equine-event-manager' ),
				__( 'Date', 'equine-event-manager' ),
				__( 'Total', 'equine-event-manager' ),
			),
			'rows'    => $rows,
		);

		$exporter = new EEM_Report_Exporter();
		$csv      = $exporter->build_csv( $dataset );
		$filename = 'eem-customer-' . preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $email ) ) . '-' . gmdate( 'Ymd' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
