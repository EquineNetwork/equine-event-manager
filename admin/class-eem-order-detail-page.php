<?php
/**
 * Order Detail page controller (C6.A — replaces legacy
 * EEM_Admin::render_order_details_page with a mockup-faithful page).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that displays a single order's details per the mockup
 * at .mockups/order_detail_page.html (the post-VIS rewrite).
 *
 * URL convention: admin.php?page=equine-event-manager-order&order_key=...
 * (established by EEM_Orders_List_Page::order_detail_url() in C5.C — the
 * Orders list's `#NNNNN`, "View Order", and "Refund Order" actions all
 * point here).
 *
 * Page architecture (per VIS-3):
 *
 *   eem-page (outer)
 *   ├── breadcrumb
 *   └── eem-page-wrap (single bordered card containing everything)
 *       ├── eem-page-header (title-band INSIDE card)
 *       │   ├── eem-page-header-left (title + status/type badges + meta line)
 *       │   └── eem-page-actions     (Back, Edit Reservation, More dropdown)
 *       └── eem-page-body
 *           ├── eem-order-payment-banner    (conditional — Unpaid/Invoice/Partial)
 *           ├── eem-order-body              (2-col grid: 1fr + 320px)
 *           │   ├── eem-order-main          (left: Stall/RV/Add-On/Group cards)
 *           │   └── eem-order-side          (right: Summary + Payment Details)
 *           ├── eem-order-full-width        (Special Instructions)
 *           └── eem-order-activity          (Activity Log — C6.E will flesh out)
 *
 * Layout-shell verification (per CLAUDE.md):
 *   - Pattern: single bordered card (.eem-page-wrap) with title-band header
 *     inside. Departs from WP-default `wrap`+h1-outside-card convention.
 *   - Outer-structure check 4.5: matches the existing _page_shell.php
 *     wrap=true branch exactly. New `meta` arg added to the shell in C6.A
 *     for the badges row + meta line below the title.
 *
 * Display-only in C6.A. Refund/CSV/Trash handlers + activity-log auto-
 * fire telemetry land in C6.B/C/D/E.
 *
 * @since 2.2.0
 */
class EEM_Order_Detail_Page {

	/**
	 * Menu slug used by the URL convention. Matches the slug Orders list
	 * already wires links to via EEM_Orders_List_Page::order_detail_url().
	 */
	const MENU_SLUG = 'equine-event-manager-order';

	/**
	 * Lazily-instantiated EEM_Shortcodes helper used to recompute the RV
	 * premium-lot surcharge for the informational breakout line.
	 *
	 * @var EEM_Shortcodes|null
	 */
	private $shortcodes_helper = null;

	/**
	 * Per-request memo of recomputed RV surcharge totals, keyed by order_key.
	 *
	 * @var array<string, float>
	 */
	private $rv_surcharge_cache = array();

	/**
	 * Per-request memo of recomputed stall surcharge totals, keyed by order_key.
	 *
	 * @var array<string, float>
	 */
	private $stall_surcharge_cache = array();

	/**
	 * Register Order Detail as a HIDDEN submenu page (parent=null) — same
	 * pattern as EEM_Orders_List_Page::register_customer_profile_stub().
	 * The page is reachable only via direct URL, never sidebar nav.
	 *
	 * Wired to admin_menu in includes/class-equine-event-manager.php.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'',
			__( 'Order Detail', 'equine-event-manager' ),
			__( 'Order Detail', 'equine-event-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_callback' )
		);
	}

	/**
	 * Static menu callback — wraps the instance render so register_page()
	 * can stay a one-liner.
	 *
	 * @return void
	 */
	public static function render_callback() {
		( new self() )->render();
	}

	/**
	 * Build an Order Detail URL.
	 *
	 * @param string                       $order_key  Required order key.
	 * @param array<string, string|int>    $extra_args Optional extra query args.
	 * @return string
	 */
	public static function url( $order_key, array $extra_args = array() ) {
		return EEM_Orders_List_Page::order_detail_url( $order_key, $extra_args );
	}

	/**
	 * Main render entry point. Looks up the order, dispatches to section
	 * renderers. Renders a "not found" card when the order_key is missing
	 * or doesn't resolve.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		$order     = '' !== $order_key
			? ( new EEM_Orders_Repository() )->get_order( $order_key )
			: null;

		if ( ! is_array( $order ) ) {
			$this->render_not_found( $order_key );
			return;
		}

		$reservation_id   = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
		$event_fields     = $reservation_id > 0
			? EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id )
			: array( 'title' => '', 'start_date' => '', 'end_date' => '', 'venue' => '' );
		$event_title      = '' !== $event_fields['title']
			? $event_fields['title']
			: ( isset( $order['event_label'] ) ? (string) $order['event_label'] : '' );
		$order_number_str = self::format_order_number( $order );
		$status_slug      = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : 'unpaid';
		$status_css       = EEM_Orders_List_Page::status_slug_to_css_class( $status_slug );
		$status_label     = isset( $order['status_label'] ) ? (string) $order['status_label'] : __( 'Unpaid', 'equine-event-manager' );

		// A "paid" order whose total grew after line-item edits has a real uncollected
		// balance. The green "Paid" badge contradicts the Payment Outstanding banner,
		// so override the display badge to amber "Balance Due" in that case.
		if ( 'paid' === $status_slug && $this->compute_balance_due( $order ) > 0.005 ) {
			$status_css   = 'unpaid';
			$status_label = __( 'Balance Due', 'equine-event-manager' );
		}

		eem_render_page_open( array(
			'title'      => $order_number_str,
			'meta'       => $this->build_header_meta_html( $order, $event_title, $reservation_id, $status_slug, $status_css, $status_label ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders',       'equine-event-manager' ), 'url' => EEM_Orders_List_Page::url() ),
				array( 'label' => $order_number_str ),
			),
			'actions'    => $this->build_header_actions_html( $order, $reservation_id ),
		) );

		$this->render_payment_banner( $order, $status_slug );
		$this->render_docs_outstanding_banner( $order, $reservation_id );

		// Close the top card (header + payment banner) so the order content floats
		// as its own white cards on the gray page background (matches Dashboard).
		// Hand-close .eem-page-body + .eem-page-wrap, wrap the rest in a gray
		// sections container, and close .eem-page ourselves at the end.
		echo '</div><!-- /.eem-page-body --></div><!-- /.eem-page-wrap -->';
		echo '<div class="eem-order-detail-sections">';

		?>
		<div class="eem-order-body">
			<div class="eem-order-main">
				<?php $this->render_stall_card( $order, $event_title );  ?>
				<?php $this->render_rv_card( $order, $event_title );     ?>
				<?php $this->render_addon_card( $order );                ?>
				<?php $this->render_group_card( $order );                ?>
			</div>
			<aside class="eem-order-side">
				<?php $this->render_summary_card( $order );              ?>
				<?php $this->render_payment_details_card( $order );      ?>
			</aside>
		</div>

		<?php $this->render_special_instructions_card( $reservation_id ); ?>

		<?php $this->render_order_notes_card( $order ); ?>

		<?php $this->render_required_documents_card( $order, $reservation_id ); ?>

		<?php
		/*
		 * SAVE BAR — DEFERRED to C7 per CLEANUP #33.
		 *
		 * Mockup: .mockups/order_detail_page.html lines 586-592 spec a
		 * Cancel + "Save Changes" pair that lives here, between the
		 * Special Instructions card and the Activity Log section. C6
		 * scope is display-only (refund/CSV/trash live in the header
		 * More menu) — there is no inline-editable field to save yet,
		 * so the bar would dispatch nowhere. C7 wires an inline-edit
		 * save flow and reinstates this region.
		 *
		 * Grep targets for the C7 implementer: "CLEANUP #33" OR
		 * "mockup lines 586-592". Both appear in this single comment
		 * so either search lands here.
		 */
		?>

		<?php $this->render_activity_log( $order, $reservation_id ); ?>

		<?php echo '</div><!-- /.eem-order-detail-sections -->'; ?>

		<?php $this->render_refund_modal( $order ); ?>

		<?php $this->render_edit_dates_modal( $order ); ?>

		<?php $this->render_cancel_modal( $order ); ?>
		<?php $this->render_mark_paid_modal( $order ); ?>

		<?php $this->render_add_items_modal( $order ); ?>

		<?php $this->render_remove_discount_modal( $order ); ?>
		<?php $this->render_add_discount_modal( $order ); ?>

		<?php
		echo '</div><!-- /.eem-page -->';
	}

	/**
	 * Render a graceful "order not found" card when order_key is invalid.
	 *
	 * @param string $order_key
	 * @return void
	 */
	private function render_not_found( $order_key ) {
		eem_render_page_open( array(
			'title'      => __( 'Order Not Found', 'equine-event-manager' ),
			'subtitle'   => '' !== $order_key
				? sprintf(
					/* translators: %s: order key */
					__( 'No order found for key: %s', 'equine-event-manager' ),
					$order_key
				)
				: __( 'No order key was provided.', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Orders',          'equine-event-manager' ), 'url' => EEM_Orders_List_Page::url() ),
				array( 'label' => __( 'Order Not Found', 'equine-event-manager' ) ),
			),
		) );
		?>
		<div class="eem-order-not-found">
			<p><?php esc_html_e( 'The order you requested could not be found. It may have been deleted, or the URL may be malformed.', 'equine-event-manager' ); ?></p>
			<p><a class="eem-btn eem-btn-electric" href="<?php echo esc_url( EEM_Orders_List_Page::url() ); ?>"><?php esc_html_e( 'Back to Orders', 'equine-event-manager' ); ?></a></p>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Build the header meta-line HTML (status badge + type badges + date /
	 * event / customer line). Plugged into the shell's `meta` slot so it
	 * renders below the title inside .eem-page-header-left.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $event_title
	 * @param int                  $reservation_id
	 * @param string               $status_slug
	 * @param string               $status_css
	 * @param string               $status_label
	 * @return string  Pre-escaped HTML safe for shell's wp_kses_post() pass.
	 */
	private function build_header_meta_html( array $order, $event_title, $reservation_id, $status_slug, $status_css, $status_label ) {
		$created_at   = isset( $order['created_at'] ) ? (string) $order['created_at'] : '';
		$date_label   = EEM_Orders_List_Page::format_date_label( $created_at );
		$time_label   = '' !== $created_at ? date_i18n( __( 'g:i A', 'equine-event-manager' ), strtotime( $created_at ) ) : '';
		$type_keys    = $this->derive_type_keys( $order );
		$type_labels  = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		// C6.A.2 fix: the order's top-level customer email key is 'email'
		// (not 'customer_email' — see EEM_Orders_Repository::get_order).
		// CLAUDE.md Note 3 captures the silent-degrade lesson.
		$customer_email = isset( $order['email'] ) ? (string) $order['email'] : '';
		$customer_name  = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$reservation_url = $reservation_id > 0 ? EEM_Reservation_Editor_Page::url( (int) $reservation_id ) : '';

		ob_start();
		?>
		<div class="eem-order-meta-badges">
			<span class="eem-status-badge eem-status-<?php echo esc_attr( $status_css ); ?>"><?php echo esc_html( $status_label ); ?></span>
			<?php foreach ( $type_keys as $key ) : ?>
				<?php if ( isset( $type_labels[ $key ] ) ) : ?>
					<span class="eem-type-badge eem-type-badge--<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $type_labels[ $key ] ); ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<div class="eem-order-meta-line">
			<?php if ( '' !== $date_label ) : ?>
				<span><strong><?php echo esc_html( $date_label ); ?></strong><?php
					if ( '' !== $time_label ) {
						/* translators: %s: time-of-day label */
						echo ' ' . esc_html( sprintf( __( 'at %s', 'equine-event-manager' ), $time_label ) );
					}
				?></span>
			<?php endif; ?>
			<?php if ( '' !== $event_title ) : ?>
				<span><?php esc_html_e( 'Event:', 'equine-event-manager' ); ?>
					<?php if ( '' !== $reservation_url ) : ?>
						<a href="<?php echo esc_url( $reservation_url ); ?>"><?php echo esc_html( $event_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $event_title ); ?>
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<?php if ( '' !== $customer_name ) : ?>
				<span><?php esc_html_e( 'Customer:', 'equine-event-manager' ); ?>
					<?php if ( '' !== $customer_email ) : ?>
						<a href="<?php echo esc_url( EEM_Orders_List_Page::customer_profile_url( $customer_email ) ); ?>"><?php echo esc_html( $customer_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $customer_name ); ?>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the right-side action-bar HTML — Back to Orders + Edit
	 * Reservation + More dropdown.
	 *
	 * @param array<string, mixed> $order
	 * @param int                  $reservation_id
	 * @return string  Pre-escaped HTML safe for shell's wp_kses_post() pass.
	 */
	private function build_header_actions_html( array $order, $reservation_id ) {
		$back_url = EEM_Orders_List_Page::url();

		// Customer check-in state (shared with Daily Movement via the
		// wp_eem_order_checkin store). Drives the quick Check-In / Check-Out
		// button that replaces "Back to Orders" in the action row.
		$order_number   = isset( $order['order_number'] ) ? (string) $order['order_number'] : '';
		$checkin_status = '';
		if ( (int) $reservation_id > 0 && '' !== $order_number ) {
			if ( ! class_exists( 'EEM_Stall_Status_Repo' ) ) {
				require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-stall-status-repo.php';
			}
			$checkin_status = EEM_Stall_Status_Repo::get_order_checkin( (int) $reservation_id, $order_number );
		}

		// C12 — hosted receipt links (token-bearer order_key).
		$order_key        = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$receipt_view_url = '' !== $order_key ? add_query_arg( array( 'eem_receipt' => $order_key ), home_url( '/' ) ) : '';
		// Print view = hosted receipt document with an auto-print trigger so the
		// admin lands on the print dialog (browser → save as PDF / print).
		$receipt_print_url = '' !== $order_key ? add_query_arg( array( 'eem_receipt' => $order_key, 'print' => '1' ), home_url( '/' ) ) : '';

		ob_start();
		?>
		<?php // Add Items is the primary action and leads the row (Whitney 2026-06-19).
		// Edit Reservation button removed per the same pass. ?>
		<?php if ( ! isset( $order['status_slug'] ) || 'cancelled' !== $order['status_slug'] ) : ?>
			<a class="eem-btn eem-btn-electric" href="#" data-eem-action="order-add-items"><?php esc_html_e( 'Add Items', 'equine-event-manager' ); ?></a>
		<?php endif; ?>
		<?php
		// Quick check-in / check-out toggle (replaces Back to Orders — the
		// breadcrumb still links back). Label + target advance with state:
		// pending → Check In, checked-in → Check Out, checked-out → Re-Check In.
		if ( (int) $reservation_id > 0 && '' !== $order_number ) :
			if ( 'checked_in' === $checkin_status ) {
				$ci_label = __( 'Check Out', 'equine-event-manager' );
				$ci_target = 'checked_out';
				$ci_class = 'eem-btn-checkin eem-btn-checkin--out';
			} elseif ( 'checked_out' === $checkin_status ) {
				$ci_label = __( 'Re-Check In', 'equine-event-manager' );
				$ci_target = 'checked_in';
				$ci_class = 'eem-btn-checkin eem-btn-checkin--re';
			} else {
				$ci_label = __( 'Check In', 'equine-event-manager' );
				$ci_target = 'checked_in';
				$ci_class = 'eem-btn-checkin eem-btn-checkin--in';
			}
			$ci_nonce = wp_create_nonce( 'eem_order_checkin_' . (int) $reservation_id . '_' . $order_number );
			?>
			<button type="button" class="eem-btn <?php echo esc_attr( $ci_class ); ?>" data-eem-action="order-checkin-set"
				data-reservation-id="<?php echo esc_attr( (string) (int) $reservation_id ); ?>"
				data-order-number="<?php echo esc_attr( $order_number ); ?>"
				data-target="<?php echo esc_attr( $ci_target ); ?>"
				data-nonce="<?php echo esc_attr( $ci_nonce ); ?>"><?php echo esc_html( $ci_label ); ?></button>
		<?php endif; ?>
		<?php if ( '' !== $receipt_print_url ) : ?>
			<a class="eem-btn eem-btn-ghost" href="<?php echo esc_url( $receipt_print_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print Receipt', 'equine-event-manager' ); ?></a>
		<?php endif; ?>
		<div class="eem-row-menu-wrap eem-order-detail-more">
			<button type="button" class="eem-btn eem-btn-ghost" data-eem-action="dropdown-toggle" aria-haspopup="true" aria-expanded="false"><?php esc_html_e( 'More', 'equine-event-manager' ); ?> &#9662;</button>
			<div class="eem-row-dropdown">
				<?php if ( '' !== $receipt_view_url ) : ?>
					<a class="eem-row-dd-item" href="<?php echo esc_url( $receipt_view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Receipt', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
				<a class="eem-row-dd-item" href="#" data-eem-action="order-export-csv-single"><?php esc_html_e( 'Export CSV', 'equine-event-manager' ); ?></a>
				<a class="eem-row-dd-item" href="#" data-eem-action="order-refund-single"><?php esc_html_e( 'Refund Order', 'equine-event-manager' ); ?></a>
				<?php if ( ! isset( $order['status_slug'] ) || 'cancelled' !== $order['status_slug'] ) : ?>
					<a class="eem-row-dd-item eem-row-dd-item--danger" href="#" data-eem-action="order-cancel-single"><?php esc_html_e( 'Cancel Order', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
				<a class="eem-row-dd-item eem-row-dd-item--danger" href="#" data-eem-action="order-trash"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Conditional Payment Outstanding banner. Renders when the order's
	 * status is one of the outstanding-balance states (Unpaid, Invoice
	 * Sent, Partially Paid); silently absent when Paid / Refunded /
	 * Cancelled.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $status_slug
	 * @return void
	 */
	private function render_payment_banner( array $order, $status_slug ) {
		// Terminal states never nag.
		if ( in_array( $status_slug, array( 'cancelled', 'refunded', 'partially-refunded' ), true ) ) {
			return;
		}

		// Balance-driven: any real outstanding amount surfaces the banner —
		// including a fully-"paid" order whose total rose after a stall/RV or
		// custom line item was added (the delta is genuinely uncollected).
		//
		// Open orders are the exception: they carry a $0 balance (no price set
		// yet), so the balance check would suppress the banner. Whitney wants the
		// same Payment Outstanding banner to prompt the admin to set a price and
		// collect — so render it for 'open' regardless of balance, with
		// price-not-set messaging and no dollar amount.
		$is_open = ( 'open' === $status_slug );
		$amount  = $this->compute_balance_due( $order );
		if ( ! $is_open && $amount <= 0.005 ) {
			return;
		}

		if ( $is_open ) {
			$msg = __( 'No price has been set yet. Collect payment to set a price and charge the customer.', 'equine-event-manager' );
		} elseif ( 'invoice-sent' === $status_slug ) {
			$msg = __( 'Invoice has been sent. Awaiting payment.', 'equine-event-manager' );
		} elseif ( 'unpaid' === $status_slug ) {
			$msg = __( 'No payment received yet for this order.', 'equine-event-manager' );
		} else {
			$msg = __( 'A balance is due after the order was updated.', 'equine-event-manager' );
		}
		?>
		<div class="eem-order-payment-banner" role="status">
			<div class="eem-order-payment-banner__left">
				<div class="eem-order-payment-banner__icon" aria-hidden="true">!</div>
				<div class="eem-order-payment-banner__content">
					<div class="eem-order-payment-banner__title"><?php esc_html_e( 'Payment Outstanding', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment-banner__meta">
						<?php if ( $is_open ) : ?>
							<?php echo esc_html( $msg ); ?>
						<?php else : ?>
							<span class="eem-order-payment-banner__amount"><?php echo esc_html( '$' . number_format_i18n( $amount, 2 ) ); ?></span>
							<?php
							/* translators: %s: human-readable reason */
							echo ' ' . esc_html( sprintf( __( 'has not been collected for this order. %s', 'equine-event-manager' ), $msg ) );
							?>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
			// DS-1.A: converted from <button data-eem-action="..."> to
			// <a href="..."> per HANDOFF Edit 2. Collect Payment is its
			// own admin page (`equine-event-manager-collect-payment`)
			// reached by URL navigation, not a JS dispatch. Real charge
			// dispatch logic lands in C14.
			$collect_url = EEM_Orders_List_Page::collect_payment_url(
				isset( $order['order_key'] ) ? (string) $order['order_key'] : ''
			);
			?>
			<div class="eem-order-payment-banner__actions">
				<a class="eem-btn eem-btn-collect-banner" href="<?php echo esc_url( $collect_url ); ?>">
					<?php esc_html_e( 'Collect Payment', 'equine-event-manager' ); ?>
				</a>
				<?php // Mark a cash / check (in-person) payment without running a card. Only the
				// states the manual-payment path accepts (unpaid / invoice-sent). ?>
				<?php if ( in_array( $status_slug, array( 'unpaid', 'invoice-sent' ), true ) ) : ?>
					<a class="eem-btn eem-btn-ghost eem-btn-mark-paid" href="#" data-eem-action="order-mark-paid"><?php esc_html_e( 'Mark as Paid', 'equine-event-manager' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Outstanding required-documents banner — amber, mirrors the Payment
	 * Outstanding banner. Renders when the reservation requires documents and
	 * one or more are neither uploaded nor marked satisfied for this order, so
	 * the stall office knows check-in is blocked on paperwork.
	 *
	 * @param array<string, mixed> $order
	 * @param int                  $reservation_id
	 * @return void
	 */
	private function render_docs_outstanding_banner( array $order, int $reservation_id ): void {
		if ( ! class_exists( 'EEM_Order_Documents' ) || $reservation_id <= 0 ) {
			return;
		}
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key ) {
			return;
		}
		$enabled = class_exists( 'EEM_Reservation_Config' )
			? ! empty( EEM_Reservation_Config::for( $reservation_id )->get( 'required_documents_enabled', 0 ) )
			: false;
		if ( ! $enabled ) {
			return;
		}
		$outstanding = EEM_Order_Documents::outstanding_requirements( $order_key, $reservation_id );
		if ( empty( $outstanding ) ) {
			return;
		}
		$count = count( $outstanding );
		?>
		<div class="eem-order-payment-banner eem-order-docs-banner" role="status">
			<div class="eem-order-payment-banner__left">
				<div class="eem-order-payment-banner__icon" aria-hidden="true">!</div>
				<div class="eem-order-payment-banner__content">
					<div class="eem-order-payment-banner__title"><?php esc_html_e( 'Documents Outstanding', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment-banner__meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: count, 2: comma-separated document names */
								_n(
									'%1$d required document is missing: %2$s. Check-in may be blocked until it is provided.',
									'%1$d required documents are missing: %2$s. Check-in may be blocked until they are provided.',
									$count,
									'equine-event-manager'
								),
								$count,
								implode( ', ', $outstanding )
							)
						);
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Stall Reservation card — visible only when the order includes a
	 * stall component.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $event_title
	 * @return void
	 */
	private function render_stall_card( array $order, $event_title ) {
		$qty = isset( $order['stall_quantity'] ) ? (int) $order['stall_quantity'] : 0;
		if ( $qty <= 0 ) {
			return;
		}

		$arrival   = isset( $order['stall_arrival_date'] )   ? (string) $order['stall_arrival_date']   : '';
		$departure = isset( $order['stall_departure_date'] ) ? (string) $order['stall_departure_date'] : '';
		$stay_type = isset( $order['stall_stay_type'] )      ? (string) $order['stall_stay_type']      : '';
		$nights    = $this->compute_nights( $arrival, $departure );
		$required  = isset( $order['required_shavings_qty'] )   ? (int) $order['required_shavings_qty']   : 0;
		$additional = isset( $order['additional_shavings_qty'] ) ? (int) $order['additional_shavings_qty'] : 0;
		$assigned  = $this->extract_assigned_stalls( $order );
		$subtotal  = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
		// Per-unit rate (so the card's math is visible): unit_price × qty × nights.
		$unit_price = $this->component_unit_price( $order, 'stall' );

		// Deep-link to this reservation's Stall & RV Charts page, where stalls are
		// assigned / auto-assigned. (The page accepts ?reservation_id=N.)
		$res_id     = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
		// Use the spatial Map view only when the reservation has an imported barn map.
		// Reservations in Numbered+Quantity mode have no barn_map, so force the List
		// view instead — otherwise the map area renders blank (no barns to draw).
		$res_has_stall_map = $res_id > 0
			&& class_exists( 'EEM_Stall_Map_Importer' )
			&& ! empty( EEM_Stall_Map_Importer::get_for_reservation( $res_id )['barns'] );
		$charts_url = $res_id > 0
			? add_query_arg(
				array(
					'page'           => 'equine-event-manager-stall-charts',
					'reservation_id' => $res_id,
					// Stall assignment is a stalls-only flow — open the Stalls
					// inventory, not Both (Whitney 2026-06-20).
					'inv'            => 'stalls',
					// Land in the By Location – Map view when a spatial map exists;
					// otherwise fall back to List so the page is never blank.
					'tab'            => $res_has_stall_map ? 'map' : 'list',
					// Order-context assign flow (#219): carries this order so the
					// chart shows the assign banner + click-to-assign modal.
					'assign_order'   => isset( $order['order_key'] ) ? (string) $order['order_key'] : '',
					'assign_kind'    => 'stall',
				),
				admin_url( 'admin.php' )
			)
			: '';

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></div>
					<?php if ( '' !== $event_title ) : ?>
						<div class="eem-order-card__subtitle"><?php echo esc_html( $event_title ); ?></div>
					<?php endif; ?>
				</div>
				<div class="eem-order-card__header-right">
					<?php if ( $nights > 0 ) : ?>
						<span class="eem-order-card__nights"><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $arrival && '' !== $departure ) : ?>
						<button type="button" class="eem-btn eem-btn-secondary eem-btn-sm" data-eem-action="order-edit-dates-open"
							data-component="stall"
							data-arrival="<?php echo esc_attr( $arrival ); ?>"
							data-departure="<?php echo esc_attr( $departure ); ?>"
							data-per-night="<?php echo esc_attr( number_format( $unit_price * $qty, 2, '.', '' ) ); ?>"
							<?php $eem_fee = $this->edit_dates_fee_data( $res_id, 'stall', $stay_type ); ?>
							data-fee-type="<?php echo esc_attr( $eem_fee['fee_type'] ); ?>"
							data-fee-value="<?php echo esc_attr( number_format( $eem_fee['fee_value'], 4, '.', '' ) ); ?>"
							data-tax-rate="<?php echo esc_attr( number_format( $eem_fee['tax_rate'], 4, '.', '' ) ); ?>"
							data-section-label="<?php esc_attr_e( 'stall', 'equine-event-manager' ); ?>"><?php esc_html_e( 'Edit Dates', 'equine-event-manager' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
			<table class="eem-detail-table">
				<tr><td><?php esc_html_e( 'Stay Type', 'equine-event-manager' ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $stay_type ) ) ); ?></td></tr>
				<?php if ( $nights > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $arrival ) : ?>
					<tr><td><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $arrival ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $departure ) : ?>
					<tr><td><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $departure ) ); ?></td></tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'Stall Quantity', 'equine-event-manager' ); ?></td><td><?php echo esc_html( (string) $qty ); ?></td></tr>
				<?php if ( $unit_price > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Rate', 'equine-event-manager' ); ?></td><td><?php
						echo esc_html(
							$nights > 0
								? sprintf(
									/* translators: 1: rate, 2: stall qty, 3: "stall(s)", 4: nights, 5: "night(s)" */
									__( '$%1$s × %2$d %3$s × %4$d %5$s', 'equine-event-manager' ),
									number_format_i18n( $unit_price, 2 ),
									$qty,
									_n( 'stall', 'stalls', $qty, 'equine-event-manager' ),
									$nights,
									_n( 'night', 'nights', $nights, 'equine-event-manager' )
								)
								: sprintf(
									/* translators: 1: rate, 2: stall qty, 3: "stall(s)" */
									__( '$%1$s × %2$d %3$s', 'equine-event-manager' ),
									number_format_i18n( $unit_price, 2 ),
									$qty,
									_n( 'stall', 'stalls', $qty, 'equine-event-manager' )
								)
						);
					?></td></tr>
				<?php endif; ?>
				<?php if ( $required > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Required Shavings', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d bag', '%d bags', $required, 'equine-event-manager' ), $required ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $additional > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Additional Shavings', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d bag', '%d bags', $additional, 'equine-event-manager' ), $additional ) ); ?></td></tr>
				<?php endif; ?>
				<?php $stall_surcharge = $this->stall_surcharge_total( $order ); ?>
				<?php if ( $stall_surcharge > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Includes Stall Premium', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $stall_surcharge, 2 ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="eem-detail-table__subtotal"><td><?php esc_html_e( 'Stall Subtotal', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
			</table>
			<div class="eem-stall-assignment">
				<div class="eem-stall-assignment__label"><?php esc_html_e( 'Assigned Stall Units', 'equine-event-manager' ); ?></div>
				<?php if ( '' !== $assigned ) : ?>
					<div class="eem-stall-assignment__value">
						<span class="eem-stall-assignment__badge"><?php echo esc_html( $assigned ); ?></span>
					</div>
				<?php endif; ?>
				<?php
				// Only offer the assign/manage button when the reservation actually
				// has stall inventory configured — otherwise the chart is a dead-end
				// "No facility map / no units" page (Whitney 2026-06-20).
				$stalls_configured = $res_id > 0 && class_exists( 'EEM_Reservations_CPT' )
					&& EEM_Reservations_CPT::section_enabled( $res_id, 'stalls_enabled' );
				?>
				<?php if ( '' !== $charts_url && $stalls_configured ) : ?>
					<div class="eem-stall-assignment__action">
						<a class="eem-btn eem-btn-electric" href="<?php echo esc_url( $charts_url ); ?>"><?php
							echo '' !== $assigned
								? esc_html__( 'Manage Stall Assignment', 'equine-event-manager' )
								: esc_html__( 'Assign Stalls', 'equine-event-manager' );
						?></a>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * RV Reservation card — visible only when the order includes an RV
	 * component.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $event_title
	 * @return void
	 */
	/**
	 * Recompute the RV premium-lot surcharge baked into the order's RV subtotal,
	 * for display as an informational breakout line. Delegates to the canonical
	 * EEM_Shortcodes recompute so the figure matches the receipt / email exactly.
	 * Result is memoized per order_key for the lifetime of this request.
	 *
	 * @param array $order Grouped order payload.
	 * @return float Premium-lot surcharge included in the RV subtotal.
	 */
	private function rv_surcharge_total( array $order ): float {
		if ( ! class_exists( 'EEM_Shortcodes' ) ) {
			return 0.0;
		}

		$key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';

		if ( '' !== $key && isset( $this->rv_surcharge_cache[ $key ] ) ) {
			return $this->rv_surcharge_cache[ $key ];
		}

		if ( null === $this->shortcodes_helper ) {
			$this->shortcodes_helper = new EEM_Shortcodes();
		}

		$total = $this->shortcodes_helper->get_order_rv_surcharge_total( $order );

		if ( '' !== $key ) {
			$this->rv_surcharge_cache[ $key ] = $total;
		}

		return $total;
	}

	/**
	 * Stall twin of rv_surcharge_total — informational "Stall Premium" breakout
	 * recomputed via the canonical EEM_Shortcodes helper. Memoized per order_key.
	 *
	 * @param array $order Grouped order payload.
	 * @return float Premium surcharge included in the stall subtotal.
	 */
	private function stall_surcharge_total( array $order ): float {
		if ( ! class_exists( 'EEM_Shortcodes' ) ) {
			return 0.0;
		}

		$key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';

		if ( '' !== $key && isset( $this->stall_surcharge_cache[ $key ] ) ) {
			return $this->stall_surcharge_cache[ $key ];
		}

		if ( null === $this->shortcodes_helper ) {
			$this->shortcodes_helper = new EEM_Shortcodes();
		}

		$total = $this->shortcodes_helper->get_order_stall_surcharge_total( $order );

		if ( '' !== $key ) {
			$this->stall_surcharge_cache[ $key ] = $total;
		}

		return $total;
	}

	private function render_rv_card( array $order, $event_title ) {
		$qty = isset( $order['rv_quantity'] ) ? (int) $order['rv_quantity'] : 0;
		if ( $qty <= 0 ) {
			return;
		}

		$arrival   = isset( $order['rv_arrival_date'] )   ? (string) $order['rv_arrival_date']   : '';
		$departure = isset( $order['rv_departure_date'] ) ? (string) $order['rv_departure_date'] : '';
		$stay_type = isset( $order['rv_stay_type'] )      ? (string) $order['rv_stay_type']      : '';
		$nights    = $this->compute_nights( $arrival, $departure );
		$rv_addons = isset( $order['rv_type'] ) && is_array( $order['rv_type'] ) ? implode( ', ', $order['rv_type'] ) : '';
		$subtotal  = isset( $order['rv_subtotal'] ) ? (float) $order['rv_subtotal'] : 0.0;
		$unit_price = $this->component_unit_price( $order, 'rv' );

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></div>
					<?php if ( '' !== $event_title ) : ?>
						<div class="eem-order-card__subtitle"><?php echo esc_html( $event_title ); ?></div>
					<?php endif; ?>
				</div>
				<div class="eem-order-card__header-right">
					<?php if ( $nights > 0 ) : ?>
						<span class="eem-order-card__nights"><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $arrival && '' !== $departure ) : ?>
						<button type="button" class="eem-btn eem-btn-secondary eem-btn-sm" data-eem-action="order-edit-dates-open"
							data-component="rv"
							data-arrival="<?php echo esc_attr( $arrival ); ?>"
							data-departure="<?php echo esc_attr( $departure ); ?>"
							data-per-night="<?php echo esc_attr( number_format( $unit_price * $qty, 2, '.', '' ) ); ?>"
							<?php $eem_fee = $this->edit_dates_fee_data( $rv_res_id, 'rv', $stay_type ); ?>
							data-fee-type="<?php echo esc_attr( $eem_fee['fee_type'] ); ?>"
							data-fee-value="<?php echo esc_attr( number_format( $eem_fee['fee_value'], 4, '.', '' ) ); ?>"
							data-tax-rate="<?php echo esc_attr( number_format( $eem_fee['tax_rate'], 4, '.', '' ) ); ?>"
							data-section-label="<?php esc_attr_e( 'RV', 'equine-event-manager' ); ?>"><?php esc_html_e( 'Edit Dates', 'equine-event-manager' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
			<table class="eem-detail-table">
				<tr><td><?php esc_html_e( 'Stay Type', 'equine-event-manager' ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $stay_type ) ) ); ?></td></tr>
				<?php if ( $nights > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Nights', 'equine-event-manager' ); ?></td><td><?php echo esc_html( sprintf( _n( '%d Night', '%d Nights', $nights, 'equine-event-manager' ), $nights ) ); ?></td></tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'RV Quantity', 'equine-event-manager' ); ?></td><td><?php echo esc_html( (string) $qty ); ?></td></tr>
				<?php if ( $unit_price > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Rate', 'equine-event-manager' ); ?></td><td><?php
						echo esc_html(
							$nights > 0
								? sprintf(
									/* translators: 1: rate, 2: lot qty, 3: "lot(s)", 4: nights, 5: "night(s)" */
									__( '$%1$s × %2$d %3$s × %4$d %5$s', 'equine-event-manager' ),
									number_format_i18n( $unit_price, 2 ),
									$qty,
									_n( 'lot', 'lots', $qty, 'equine-event-manager' ),
									$nights,
									_n( 'night', 'nights', $nights, 'equine-event-manager' )
								)
								: sprintf(
									/* translators: 1: rate, 2: lot qty, 3: "lot(s)" */
									__( '$%1$s × %2$d %3$s', 'equine-event-manager' ),
									number_format_i18n( $unit_price, 2 ),
									$qty,
									_n( 'lot', 'lots', $qty, 'equine-event-manager' )
								)
						);
					?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $arrival ) : ?>
					<tr><td><?php esc_html_e( 'Arrival Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $arrival ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $departure ) : ?>
					<tr><td><?php esc_html_e( 'Departure Date', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $this->format_long_date( $departure ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( '' !== $rv_addons ) : ?>
					<tr><td><?php esc_html_e( 'RV Add-Ons', 'equine-event-manager' ); ?></td><td><?php echo esc_html( $rv_addons ); ?></td></tr>
				<?php endif; ?>
				<?php $rv_surcharge = $this->rv_surcharge_total( $order ); ?>
				<?php if ( $rv_surcharge > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Includes RV Premium Lots', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $rv_surcharge, 2 ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="eem-detail-table__subtotal"><td><?php esc_html_e( 'RV Subtotal', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
			</table>
			<?php
			// Deep-link to this reservation's Stall & RV Charts page for RV-lot assignment.
			$rv_res_id     = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
			$rv_charts_url = $rv_res_id > 0
				? add_query_arg(
					array(
						'page'           => 'equine-event-manager-stall-charts',
						'reservation_id' => $rv_res_id,
						// RV-lot assignment is an RV-only flow — open the RV inventory
						// + map view (Whitney 2026-06-20).
						'inv'            => 'rv',
						'tab'            => 'map',
						'assign_order'   => isset( $order['order_key'] ) ? (string) $order['order_key'] : '',
						'assign_kind'    => 'rv',
					),
					admin_url( 'admin.php' )
				)
				: '';
			?>
			<?php if ( '' !== $rv_charts_url ) : ?>
				<?php $assigned_rv = $this->extract_assigned_rv_lots( $order ); ?>
				<div class="eem-stall-assignment">
					<div class="eem-stall-assignment__label"><?php esc_html_e( 'Assigned RV Lots', 'equine-event-manager' ); ?></div>
					<?php if ( '' !== $assigned_rv ) : ?>
						<div class="eem-stall-assignment__value">
							<span class="eem-stall-assignment__badge"><?php echo esc_html( $assigned_rv ); ?></span>
						</div>
					<?php endif; ?>
					<?php
					// Only offer the assign/manage button when the reservation has RV
					// inventory configured (Whitney 2026-06-20).
					$rv_configured = $rv_res_id > 0 && class_exists( 'EEM_Reservations_CPT' )
						&& EEM_Reservations_CPT::section_enabled( $rv_res_id, 'rv_enabled' );
					?>
					<?php if ( $rv_configured ) : ?>
						<div class="eem-stall-assignment__action">
							<a class="eem-btn eem-btn-electric" href="<?php echo esc_url( $rv_charts_url ); ?>"><?php
								echo '' !== $assigned_rv
									? esc_html__( 'Manage RV Assignment', 'equine-event-manager' )
									: esc_html__( 'Assign RV Lots', 'equine-event-manager' );
							?></a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add-Ons card — visible only when the order has add-on type-label
	 * (shavings, general add-ons, etc.).
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_addon_card( array $order ) {
		$type_keys = $this->derive_type_keys( $order );
		if ( ! in_array( 'addon', $type_keys, true ) ) {
			return;
		}

		$required   = isset( $order['required_shavings_qty'] )   ? (int) $order['required_shavings_qty']   : 0;
		$additional = isset( $order['additional_shavings_qty'] ) ? (int) $order['additional_shavings_qty'] : 0;
		$total_qty  = $required + $additional;
		$subtotal   = $this->compute_addon_subtotal( $order );

		// Required shavings are billed WITHIN the Stall Reservation subtotal, and
		// their quantities are already itemized on the Stall card ("Required
		// Shavings: N bags"). The add-on subtotal is a residual (total − stall −
		// rv − fees), so for a stall order whose shavings are folded into the
		// stall subtotal it resolves to $0. Rendering the card in that case
		// produced a misleading "Shavings (×N) $0.00" line. Only render when
		// there's a genuine add-on charge — this mirrors the order-summary
		// sidebar gate (`$addon_subtotal > 0`) so the two stay consistent.
		if ( $subtotal <= 0 ) {
			return;
		}

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'package' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></div>
					<div class="eem-order-card__subtitle"><?php esc_html_e( 'Additional products included in this order', 'equine-event-manager' ); ?></div>
				</div>
			</div>
			<table class="eem-detail-table">
				<?php if ( $total_qty > 0 ) : ?>
					<tr><td><?php echo esc_html( sprintf( __( 'Shavings (×%d)', 'equine-event-manager' ), $total_qty ) ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="eem-detail-table__subtotal"><td><?php esc_html_e( 'Add-On Subtotal', 'equine-event-manager' ); ?></td><td><?php echo esc_html( '$' . number_format_i18n( $subtotal, 2 ) ); ?></td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Group Reservation card — visible only when the order has the
	 * 'group' type-label.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_group_card( array $order ) {
		$type_keys = $this->derive_type_keys( $order );
		if ( ! in_array( 'group', $type_keys, true ) ) {
			return;
		}

		// Group rider data lives in the order's notes string. We promoted
		// EEM_Admin::parse_group_rider_count_from_notes /
		// parse_group_rider_names_from_notes from private to public static
		// in C6.A.2 specifically so this renderer can reuse them — single
		// source of truth for the parse format.
		$notes = isset( $order['notes'] ) ? (string) $order['notes'] : '';
		$names = EEM_Admin::parse_group_rider_names_from_notes( $notes );
		$count = EEM_Admin::parse_group_rider_count_from_notes( $notes );
		if ( $count < 1 ) {
			$count = ! empty( $names )
				? count( $names )
				: ( isset( $order['components'] ) && is_array( $order['components'] ) ? count( $order['components'] ) : 1 );
		}

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div>
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Group Reservation', 'equine-event-manager' ); ?></div>
					<div class="eem-order-card__subtitle"><?php esc_html_e( 'Group rider details captured on this reservation', 'equine-event-manager' ); ?></div>
				</div>
			</div>
			<table class="eem-detail-table">
				<tr><td><?php esc_html_e( 'Rider Count', 'equine-event-manager' ); ?></td><td><?php echo esc_html( (string) $count ); ?></td></tr>
			</table>
			<?php if ( ! empty( $names ) ) : ?>
				<div class="eem-rider-list">
					<?php foreach ( $names as $i => $name ) : ?>
						<div class="eem-rider-row">
							<span class="eem-rider-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<span class="eem-rider-name"><?php echo esc_html( $name ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Order Summary sidebar card — section-by-section subtotals + grand
	 * total. Renders only the sections present in the order.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_summary_card( array $order ) {
		$stall_subtotal = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
		$rv_subtotal    = isset( $order['rv_subtotal'] )    ? (float) $order['rv_subtotal']    : 0.0;
		$fees           = isset( $order['fees'] ) ? (float) $order['fees'] : 0.0;
		$total          = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$addon_subtotal = $this->compute_addon_subtotal( $order );

		$stall_nights = $this->compute_nights(
			isset( $order['stall_arrival_date'] )   ? (string) $order['stall_arrival_date']   : '',
			isset( $order['stall_departure_date'] ) ? (string) $order['stall_departure_date'] : ''
		);
		$rv_nights    = $this->compute_nights(
			isset( $order['rv_arrival_date'] )   ? (string) $order['rv_arrival_date']   : '',
			isset( $order['rv_departure_date'] ) ? (string) $order['rv_departure_date'] : ''
		);
		$required     = isset( $order['required_shavings_qty'] )   ? (int) $order['required_shavings_qty']   : 0;
		$additional   = isset( $order['additional_shavings_qty'] ) ? (int) $order['additional_shavings_qty'] : 0;
		$addon_qty    = $required + $additional;

		// C13.C: order-level adjustments (custom line items + discount). These
		// aren't part of the component-row totals, so the grand total is recomputed
		// from $total + custom items − the discount's snapshotted reduction.
		$order_key    = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$adjustments  = ( '' !== $order_key && class_exists( 'EEM_Order_Adjustments_Repo' ) )
			? EEM_Order_Adjustments_Repo::get_for_order( $order_key )
			: array( 'custom_items' => array(), 'discount' => null, 'custom_items_total' => 0.0 );
		$custom_items      = $adjustments['custom_items'];
		$custom_items_total = (float) $adjustments['custom_items_total'];
		$discount          = $adjustments['discount'];
		$discount_amount   = is_array( $discount ) ? (float) $discount['amount'] : 0.0;
		$total             = $total + $custom_items_total - $discount_amount;
		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Order Summary', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-order-summary__body">
				<?php if ( $stall_subtotal > 0.005 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Stall Reservation', 'equine-event-manager' ); ?></span>
							<?php if ( $stall_nights > 0 ) : ?>
								<span class="eem-order-summary__section-badge eem-order-summary__section-badge--stall"><?php echo esc_html( sprintf( _n( '%d night', '%d nights', $stall_nights, 'equine-event-manager' ), $stall_nights ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php $stall_summary_surcharge = $this->stall_surcharge_total( $order ); ?>
						<?php if ( $stall_summary_surcharge > 0 ) : ?>
							<div class="eem-order-summary__line"><span><?php esc_html_e( 'Includes Stall Premium', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $stall_summary_surcharge, 2 ) ); ?></span></div>
						<?php endif; ?>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Stalls Subtotal', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $stall_subtotal, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( $rv_subtotal > 0.005 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'RV Reservation', 'equine-event-manager' ); ?></span>
							<?php if ( $rv_nights > 0 ) : ?>
								<span class="eem-order-summary__section-badge eem-order-summary__section-badge--rv"><?php echo esc_html( sprintf( _n( '%d night', '%d nights', $rv_nights, 'equine-event-manager' ), $rv_nights ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php $rv_summary_surcharge = $this->rv_surcharge_total( $order ); ?>
						<?php if ( $rv_summary_surcharge > 0 ) : ?>
							<div class="eem-order-summary__line"><span><?php esc_html_e( 'Includes RV Premium Lots', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $rv_summary_surcharge, 2 ) ); ?></span></div>
						<?php endif; ?>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'RV Subtotal', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $rv_subtotal, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( $addon_subtotal > 0.005 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></span>
							<?php if ( $addon_qty > 0 ) : ?>
								<span class="eem-order-summary__section-badge eem-order-summary__section-badge--addon"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $addon_qty, 'equine-event-manager' ), $addon_qty ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $addon_qty > 0 ) : ?>
							<div class="eem-order-summary__line"><span><?php echo esc_html( sprintf( __( 'Shavings (×%d)', 'equine-event-manager' ), $addon_qty ) ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $addon_subtotal, 2 ) ); ?></span></div>
						<?php endif; ?>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Add-Ons Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $addon_subtotal, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( $fees > 0 ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Fees', 'equine-event-manager' ); ?></span>
						</div>
						<div class="eem-order-summary__line"><span><?php esc_html_e( 'Non-Refundable Convenience Fee', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $fees, 2 ) ); ?></span></div>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Fees Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $fees, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $custom_items ) ) : ?>
					<div class="eem-order-summary__section">
						<div class="eem-order-summary__section-header">
							<span class="eem-order-summary__section-title"><?php esc_html_e( 'Custom Line Items', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__section-badge eem-order-summary__section-badge--addon"><?php echo esc_html( sprintf( _n( '%d item', '%d items', count( $custom_items ), 'equine-event-manager' ), count( $custom_items ) ) ); ?></span>
						</div>
						<?php foreach ( $custom_items as $item ) : ?>
							<div class="eem-order-summary__line eem-order-summary__line--removable">
								<span><?php echo esc_html( $item['description'] ); ?></span>
								<span class="eem-order-summary__line-right">
									<?php echo esc_html( '$' . number_format_i18n( (float) $item['amount'], 2 ) ); ?>
									<button type="button" class="eem-line-remove" data-eem-action="order-remove-custom-item" data-item-id="<?php echo esc_attr( (string) $item['id'] ); ?>" data-item-desc="<?php echo esc_attr( $item['description'] ); ?>" data-order-key="<?php echo esc_attr( $order_key ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'eem_remove_custom_item_' . $order_key ) ); ?>" aria-label="<?php esc_attr_e( 'Remove line item', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove line item', 'equine-event-manager' ); ?>">&times;</button>
								</span>
							</div>
						<?php endforeach; ?>
						<div class="eem-order-summary__section-subtotal"><span><?php esc_html_e( 'Custom Items Total', 'equine-event-manager' ); ?></span><span><?php echo esc_html( '$' . number_format_i18n( $custom_items_total, 2 ) ); ?></span></div>
					</div>
				<?php endif; ?>
				<?php if ( is_array( $discount ) && $discount_amount > 0 ) : ?>
					<div class="eem-order-summary__discount" data-eem-order-discount>
						<div class="eem-order-summary__discount-line">
							<span class="eem-order-summary__discount-label"><?php esc_html_e( 'Discount', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__discount-val"><?php echo esc_html( '−$' . number_format_i18n( $discount_amount, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__discount-reason">
							<span class="eem-order-summary__discount-chip"><?php echo esc_html( $discount['reason'] ); ?></span>
							<button type="button" class="eem-order-summary__discount-remove" data-eem-action="order-remove-discount-open"><?php esc_html_e( 'Remove', 'equine-event-manager' ); ?></button>
						</div>
					</div>
				<?php elseif ( ! isset( $order['status_slug'] ) || 'cancelled' !== $order['status_slug'] ) : ?>
					<div class="eem-order-summary__add-discount">
						<button type="button" class="eem-link-btn" data-eem-action="order-add-discount-open"><?php esc_html_e( '+ Add Discount', 'equine-event-manager' ); ?></button>
					</div>
				<?php endif; ?>
				<?php
				// Payment breakdown so paid vs. outstanding is never ambiguous.
				// $total is the recomputed grand total (incl. custom items /
				// discount, computed above). amount_paid is the authoritative
				// collected figure; the balance is the difference.
				$summary_status = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : '';
				$amount_paid    = $this->compute_amount_paid( $order );
				$balance_due    = round( max( 0.0, $total - $amount_paid ), 2 );
				// Overpaid (e.g. after shortening a stay): the customer is owed money
				// back. Surfaced as "Refund Owed" with a one-click refund button.
				$refund_owed    = round( max( 0.0, $amount_paid - $total ), 2 );
				$is_refunded    = ( 'refunded' === $summary_status || 'partially-refunded' === $summary_status );
				// Auth.net can only refund a full charged component, not an arbitrary
				// partial — flag it so the Refund Owed UI can warn before the click.
				$owed_gateway   = '';
				foreach ( (array) ( isset( $order['components'] ) ? $order['components'] : array() ) as $oc ) {
					if ( ! empty( $oc['payment_gateway'] ) ) {
						$owed_gateway = (string) $oc['payment_gateway'];
						break;
					}
				}
				?>
				<div class="eem-order-summary__totals">
					<?php if ( $is_refunded ) : ?>
						<div class="eem-order-summary__grand-total">
							<span class="eem-order-summary__grand-label"><?php esc_html_e( 'Total Refunded', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__grand-val"><?php echo esc_html( '$' . number_format_i18n( $total, 2 ) ); ?></span>
						</div>
					<?php elseif ( $balance_due > 0.005 ) : ?>
						<div class="eem-order-summary__pay-line">
							<span><?php esc_html_e( 'Order Total', 'equine-event-manager' ); ?></span>
							<span><?php echo esc_html( '$' . number_format_i18n( $total, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__pay-line eem-order-summary__pay-line--paid">
							<span><?php esc_html_e( 'Amount Paid', 'equine-event-manager' ); ?></span>
							<span><?php echo esc_html( '−$' . number_format_i18n( $amount_paid, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__grand-total eem-order-summary__grand-total--due">
							<span class="eem-order-summary__grand-label"><?php esc_html_e( 'Balance Due', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__grand-val"><?php echo esc_html( '$' . number_format_i18n( $balance_due, 2 ) ); ?></span>
						</div>
					<?php elseif ( $refund_owed > 0.005 && ! $is_refunded ) : ?>
						<div class="eem-order-summary__pay-line">
							<span><?php esc_html_e( 'Order Total', 'equine-event-manager' ); ?></span>
							<span><?php echo esc_html( '$' . number_format_i18n( $total, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__pay-line eem-order-summary__pay-line--paid">
							<span><?php esc_html_e( 'Amount Paid', 'equine-event-manager' ); ?></span>
							<span><?php echo esc_html( '−$' . number_format_i18n( $amount_paid, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__grand-total eem-order-summary__grand-total--refund">
							<span class="eem-order-summary__grand-label"><?php esc_html_e( 'Refund Owed', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__grand-val"><?php echo esc_html( '$' . number_format_i18n( $refund_owed, 2 ) ); ?></span>
						</div>
						<div class="eem-order-summary__refund-action">
							<button type="button" class="eem-btn eem-btn-primary eem-btn-sm" data-eem-action="order-refund-owed" data-refund-amount="<?php echo esc_attr( number_format( $refund_owed, 2, '.', '' ) ); ?>">
								<?php echo esc_html( sprintf( /* translators: %s: refund amount */ __( 'Refund $%s to customer', 'equine-event-manager' ), number_format_i18n( $refund_owed, 2 ) ) ); ?>
							</button>
							<?php if ( 'authorize_net' === $owed_gateway ) : ?>
								<p class="eem-order-summary__refund-note"><?php esc_html_e( 'Authorize.net can only refund a full charged component. If this partial refund is declined, refund the full stall/RV charge from the More → Refund Order panel instead.', 'equine-event-manager' ); ?></p>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<div class="eem-order-summary__grand-total">
							<span class="eem-order-summary__grand-label"><?php esc_html_e( 'Total Paid', 'equine-event-manager' ); ?></span>
							<span class="eem-order-summary__grand-val"><?php echo esc_html( '$' . number_format_i18n( $total, 2 ) ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The stored per-unit rate (unit_price) for a section's first component row.
	 * Used to surface the rate on the reservation card so the line math
	 * (rate × qty × nights = subtotal) is visible. Returns 0.0 when absent.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $component 'stall' | 'rv'
	 * @return float
	 */
	private function component_unit_price( array $order, string $component ): float {
		foreach ( (array) ( isset( $order['components'] ) ? $order['components'] : array() ) as $c ) {
			if ( isset( $c['table'] ) && $component === $c['table'] ) {
				$repo = new EEM_Orders_Repository();
				$raw  = $repo->get_component_row( $component, (int) $c['row_id'] );
				if ( is_array( $raw ) && isset( $raw['unit_price'] ) ) {
					return (float) $raw['unit_price'];
				}
			}
		}
		return 0.0;
	}

	/**
	 * Resolve the reservation's convenience-fee + tax config so the Edit Dates
	 * modal can show (and the server can charge) the per-night delta WITH the fee
	 * and tax, matching customer checkout. Returns fee_type ('none'|'flat'|
	 * 'percentage'), fee_value, and tax_rate (percent). Falls back to a no-fee /
	 * no-tax shape when the reservation or pricer is unavailable.
	 *
	 * @param int    $reservation_id Reservation post ID.
	 * @param string $section        'stall' | 'rv'.
	 * @param string $stay_type      Component stay type.
	 * @return array{fee_type:string,fee_value:float,tax_rate:float}
	 */
	private function edit_dates_fee_data( ?int $reservation_id, string $section, string $stay_type ): array {
		$reservation_id = (int) $reservation_id;
		$out = array( 'fee_type' => 'none', 'fee_value' => 0.0, 'tax_rate' => 0.0 );
		if ( $reservation_id < 1 || ! class_exists( 'EEM_Shortcodes' ) ) {
			return $out;
		}
		if ( null === $this->shortcodes_helper ) {
			$this->shortcodes_helper = new EEM_Shortcodes();
		}
		$priced = $this->shortcodes_helper->price_base_rate_addition( $reservation_id, $section, 1, $stay_type, '', '' );
		if ( ! empty( $priced['fee_enabled'] ) ) {
			$out['fee_type']  = (string) ( $priced['fee_type'] ?? 'none' );
			$out['fee_value'] = (float) ( $priced['fee_value'] ?? 0.0 );
		}
		$out['tax_rate'] = (float) ( $priced['tax_rate'] ?? 0.0 );
		return $out;
	}

	/**
	 * The order's true grand total including order-level adjustments
	 * (component totals + custom line items − discount). The repo's
	 * $order['total'] is component-only; this layers the adjustments the
	 * Order Summary / Collect Payment surfaces add on top.
	 *
	 * @param array<string, mixed> $order
	 * @return float
	 */
	private function compute_grand_total( array $order ): float {
		$base = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$custom = 0.0;
		$discount = 0.0;
		if ( '' !== $order_key && class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			$adj      = EEM_Order_Adjustments_Repo::get_for_order( $order_key );
			$custom   = isset( $adj['custom_items_total'] ) ? (float) $adj['custom_items_total'] : 0.0;
			$discount = ( isset( $adj['discount'] ) && is_array( $adj['discount'] ) ) ? (float) $adj['discount']['amount'] : 0.0;
		}
		return round( $base + $custom - $discount, 2 );
	}

	/**
	 * Amount actually collected on the order. Reads the authoritative
	 * amount_paid aggregate (mig-029). Legacy fallback: a 'paid' order with no
	 * recorded amount_paid is treated as having collected its component total
	 * (any custom items added afterward remain the unpaid portion).
	 *
	 * @param array<string, mixed> $order
	 * @return float
	 */
	private function compute_amount_paid( array $order ): float {
		$paid = isset( $order['amount_paid'] ) ? (float) $order['amount_paid'] : 0.0;
		if ( $paid <= 0.005 && isset( $order['status_slug'] ) && 'paid' === $order['status_slug'] ) {
			$paid = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		}
		return round( $paid, 2 );
	}

	/**
	 * Outstanding balance: grand total − amount collected, floored at 0.
	 *
	 * @param array<string, mixed> $order
	 * @return float
	 */
	private function compute_balance_due( array $order ): float {
		return round( max( 0.0, $this->compute_grand_total( $order ) - $this->compute_amount_paid( $order ) ), 2 );
	}

	/**
	 * Compute the add-on subtotal from order totals (no per-line price data
	 * is currently persisted — shavings are the only add-on). Equals the
	 * residual: total - stall - rv - fees.
	 *
	 * @param array<string, mixed> $order
	 * @return float
	 */
	private function compute_addon_subtotal( array $order ) {
		$total          = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$stall_subtotal = isset( $order['stall_subtotal'] ) ? (float) $order['stall_subtotal'] : 0.0;
		$rv_subtotal    = isset( $order['rv_subtotal'] )    ? (float) $order['rv_subtotal']    : 0.0;
		$fees           = isset( $order['fees'] ) ? (float) $order['fees'] : 0.0;
		return max( 0.0, $total - $stall_subtotal - $rv_subtotal - $fees );
	}

	/**
	 * Payment Details sidebar card — customer block + processor +
	 * transaction details + refund history.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_payment_details_card( array $order ) {
		// NOTE (C6.A.2 fix): the C6.A first-draft used keys 'customer_email'
		// and 'customer_phone' which don't exist on the order shape. The
		// actual top-level keys are 'email' and 'phone' (see
		// EEM_Orders_Repository::get_order). Captured as CLAUDE.md Note 3
		// (missing-key silent-degrade lesson).
		$customer_email = isset( $order['email'] ) ? (string) $order['email'] : '';
		$customer_name  = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$customer_phone = isset( $order['phone'] ) ? (string) $order['phone'] : '';
		$gateway        = isset( $order['payment_gateway'] ) ? (string) $order['payment_gateway'] : '';
		$status_slug    = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : 'unpaid';
		$captured       = in_array( $status_slug, array( 'paid', 'partially-refunded', 'refunded' ), true );

		$first_component = isset( $order['components'][0] ) && is_array( $order['components'][0] ) ? $order['components'][0] : array();
		$transaction_id  = isset( $first_component['transaction_id'] ) ? (string) $first_component['transaction_id'] : '';

		// Card brand/last4 (CLEANUP #34 — now captured by the C14 collect-payment
		// confirm handler into the component notes as "Card Brand"/"Card Last4").
		$card_notes = isset( $first_component['notes'] ) ? (string) $first_component['notes'] : '';
		$card_brand = preg_match( '/Card Brand:\s*(.+)/i', $card_notes, $m ) ? trim( $m[1] ) : '';
		$card_last4 = preg_match( '/Card Last4:\s*(.+)/i', $card_notes, $m ) ? trim( $m[1] ) : '';

		// Manual (cash/check) payments record the method in the component notes —
		// surface it as the Processor value instead of the generic gateway label.
		$manual_method = preg_match( '/Manual Payment Method:\s*(.+)/i', $card_notes, $m ) ? trim( $m[1] ) : '';
		if ( '' !== $manual_method ) {
			/* translators: %s: cash/check method label, e.g. "Cash" or "Check #1042" */
			$gateway = sprintf( __( 'Manual — %s', 'equine-event-manager' ), $manual_method );
		}

		// Payments ledger — the authoritative per-tender record. When present it
		// drives the Tenders breakdown + Refund History so a mixed card+cash order
		// shows each method separately instead of collapsing to one Processor label.
		// Orders created before the ledger shipped (and not yet backfilled) fall back
		// to the legacy single-gateway display below.
		$order_key   = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$ledger      = ( '' !== $order_key && class_exists( 'EEM_Order_Payments_Repo' ) )
			? EEM_Order_Payments_Repo::get_for_order( $order_key )
			: array();
		$has_ledger  = ! empty( $ledger );
		$led_payments = array();
		$led_refunds  = array();
		foreach ( $ledger as $led_entry ) {
			$led_label = EEM_Order_Payments_Repo::tender_label( $led_entry['gateway'], $led_entry['method'] );
			if ( EEM_Order_Payments_Repo::DIRECTION_REFUND === $led_entry['direction'] ) {
				$led_refunds[] = array(
					'amount' => (float) $led_entry['amount'],
					'label'  => $led_label,
					'txn'    => (string) $led_entry['transaction_id'],
					'when'   => (string) $led_entry['created_at'],
				);
			} else {
				$led_payments[] = array(
					'amount' => (float) $led_entry['amount'],
					'label'  => $led_label,
					'txn'    => (string) $led_entry['transaction_id'],
					'when'   => (string) $led_entry['created_at'],
				);
			}
		}

		?>
		<div class="eem-card eem-order-card">
			<div class="eem-order-card__header">
				<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Payment Details', 'equine-event-manager' ); ?></div>
			</div>
			<div class="eem-order-payment__body">
				<?php if ( '' !== $customer_name ) : ?>
					<div class="eem-order-payment__label"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment__customer-name">
						<?php if ( '' !== $customer_email ) : ?>
							<a href="<?php echo esc_url( EEM_Orders_List_Page::customer_profile_url( $customer_email ) ); ?>"><?php echo esc_html( $customer_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $customer_name ); ?>
						<?php endif; ?>
					</div>
					<div class="eem-order-payment__val">
						<?php if ( '' !== $customer_email ) : ?>
							<a href="mailto:<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></a><br>
						<?php endif; ?>
						<?php if ( '' !== $customer_phone ) : ?>
							<?php echo esc_html( $customer_phone ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $has_ledger ) : ?>
					<div class="eem-order-payment__label"><?php esc_html_e( 'Tenders', 'equine-event-manager' ); ?></div>
					<div class="eem-order-payment__val">
						<?php foreach ( $led_payments as $led_p ) : ?>
							<div class="eem-order-payment__tender-line">
								<strong><?php echo esc_html( $led_p['label'] ); ?></strong>
								&mdash; $<?php echo esc_html( number_format( $led_p['amount'], 2 ) ); ?>
								<?php if ( '' !== $led_p['txn'] ) : ?>
									<span class="eem-order-payment__mono"><?php echo esc_html( $led_p['txn'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $led_p['when'] ) : ?>
									<span class="eem-order-payment__hint"><?php echo esc_html( $led_p['when'] ); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<?php if ( empty( $led_payments ) ) : ?>
							<span class="eem-order-payment__hint"><?php esc_html_e( 'No payments recorded', 'equine-event-manager' ); ?></span>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<?php if ( '' !== $gateway ) : ?>
						<div class="eem-order-payment__label"><?php esc_html_e( 'Processor', 'equine-event-manager' ); ?></div>
						<div class="eem-order-payment__val"><?php echo esc_html( $gateway ); ?></div>
					<?php endif; ?>

					<?php if ( '' !== $transaction_id ) : ?>
						<div class="eem-order-payment__label"><?php esc_html_e( 'Transaction ID', 'equine-event-manager' ); ?></div>
						<div class="eem-order-payment__val eem-order-payment__mono"><?php echo esc_html( $transaction_id ); ?></div>
					<?php endif; ?>

					<?php if ( '' !== $card_brand || '' !== $card_last4 ) : ?>
						<div class="eem-order-payment__label"><?php esc_html_e( 'Card', 'equine-event-manager' ); ?></div>
						<div class="eem-order-payment__val"><?php echo esc_html( trim( ucfirst( $card_brand ) . ( '' !== $card_last4 ? ' •••• ' . $card_last4 : '' ) ) ); ?></div>
					<?php endif; ?>
				<?php endif; ?>

				<div class="eem-order-payment__label"><?php esc_html_e( 'Captured', 'equine-event-manager' ); ?></div>
				<div class="eem-order-payment__val">
					<?php if ( $captured ) : ?>
						<strong><?php esc_html_e( 'Yes', 'equine-event-manager' ); ?></strong>
					<?php else : ?>
						<strong>&mdash;</strong> <span class="eem-order-payment__hint">(<?php esc_html_e( 'awaiting payment', 'equine-event-manager' ); ?>)</span>
					<?php endif; ?>
				</div>

				<?php
				// Render PERSISTED refunds on page load. The JS only fills this
				// after an in-page refund, so without this a reload (or an
				// out-of-band reconcile) shows "No refunds processed" even when the
				// order is refunded. Each refunded component records "Refunded
				// Amount" / "Last Refund Transaction" / "Last Refunded At" in notes.
				$eem_refund_lines = array();
				if ( $has_ledger ) {
					// Ledger is authoritative — each refund entry already carries its
					// tender label, amount, transaction id, and timestamp.
					foreach ( $led_refunds as $led_r ) {
						if ( $led_r['amount'] <= 0.009 ) {
							continue;
						}
						$eem_refund_lines[] = array(
							'amount' => $led_r['amount'],
							'label'  => $led_r['label'],
							'txn'    => $led_r['txn'],
							'when'   => $led_r['when'],
						);
					}
				} else {
					foreach ( (array) ( $order['components'] ?? array() ) as $eem_rc ) {
						$eem_rc_notes = isset( $eem_rc['notes'] ) ? (string) $eem_rc['notes'] : '';
						if ( '' === $eem_rc_notes || ! preg_match( '/Refunded Amount:\s*([0-9]+(?:\.[0-9]+)?)/i', $eem_rc_notes, $eem_ra ) ) {
							continue;
						}
						$eem_amt = (float) $eem_ra[1];
						if ( $eem_amt <= 0.009 ) {
							continue;
						}
						$eem_refund_lines[] = array(
							'amount' => $eem_amt,
							'label'  => '',
							'txn'    => preg_match( '/Last Refund Transaction:\s*(.+)/i', $eem_rc_notes, $eem_rt ) ? trim( $eem_rt[1] ) : '',
							'when'   => preg_match( '/Last Refunded At:\s*(.+)/i', $eem_rc_notes, $eem_rw ) ? trim( $eem_rw[1] ) : '',
						);
					}
				}
				?>
				<div class="eem-order-payment__label eem-order-payment__label--sep"><?php esc_html_e( 'Refund History', 'equine-event-manager' ); ?></div>
				<div class="eem-order-payment__val<?php echo empty( $eem_refund_lines ) ? ' eem-order-payment__hint' : ''; ?>" data-eem-refund-history>
					<?php if ( empty( $eem_refund_lines ) ) : ?>
						<?php esc_html_e( 'No refunds processed', 'equine-event-manager' ); ?>
					<?php else : ?>
						<?php foreach ( $eem_refund_lines as $eem_line ) : ?>
							<div class="eem-order-payment__refund-line">
								<strong>$<?php echo esc_html( number_format( $eem_line['amount'], 2 ) ); ?></strong>
								<?php if ( ! empty( $eem_line['label'] ) ) : ?> <span class="eem-order-payment__hint"><?php echo esc_html( $eem_line['label'] ); ?></span><?php endif; ?>
								<?php if ( '' !== $eem_line['when'] ) : ?> <span class="eem-order-payment__hint"><?php echo esc_html( $eem_line['when'] ); ?></span><?php endif; ?>
								<?php if ( '' !== $eem_line['txn'] ) : ?> <span class="eem-order-payment__mono"><?php echo esc_html( $eem_line['txn'] ); ?></span><?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Special Instructions card — full-width below the 2-col grid.
	 *
	 * Inline-editable: the reservation's `_en_special_instructions` meta is
	 * surfaced in a textarea with a Save Changes bar, saved over AJAX via
	 * `eem_special_instructions_set`. The value is reservation-level (it applies
	 * to every order on the reservation), so the hint flags that scope. Falls
	 * back to a read-only em-dash when there is no reservation to key against.
	 *
	 * @param int $reservation_id
	 * @return void
	 */
	private function render_special_instructions_card( $reservation_id ) {
		$reservation_id = (int) $reservation_id;
		$text  = $reservation_id > 0 ? (string) get_post_meta( $reservation_id, '_en_special_instructions', true ) : '';
		$nonce = $reservation_id > 0 ? wp_create_nonce( 'eem_special_instructions_' . $reservation_id ) : '';
		?>
		<div class="eem-order-full-width">
			<div class="eem-card eem-order-card eem-order-instructions"
				data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
				<div class="eem-order-card__header">
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'file' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Special Instructions', 'equine-event-manager' ); ?></div>
				</div>
				<?php if ( $reservation_id > 0 ) : ?>
					<div class="eem-order-notes__body">
						<div class="eem-order-notes__field">
							<textarea class="eem-field-input eem-order-notes__input eem-order-instructions__input" rows="4" placeholder="<?php esc_attr_e( 'Add special instructions for this reservation…', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
							<p class="eem-order-notes__hint"><?php esc_html_e( 'Applies to the entire reservation (every order), not just this order.', 'equine-event-manager' ); ?></p>
							<div class="eem-order-notes__actions">
								<button type="button" class="eem-btn eem-btn-electric eem-btn-sm" data-eem-action="order-instructions-save"><?php esc_html_e( 'Save Changes', 'equine-event-manager' ); ?></button>
								<span class="eem-order-notes__status" role="status" aria-live="polite"></span>
							</div>
						</div>
					</div>
				<?php else : ?>
					<div class="eem-order-instructions__body">
						<p class="eem-order-instructions__text eem-order-instructions__text--empty">&mdash;</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Order Notes card — full-width, below Special Instructions.
	 *
	 * Surfaces order-level data that lives in the order's notes blob: the
	 * customer's confirmation number(s) and any genuine customer-entered
	 * free-text (both read-only), plus an EDITABLE admin "Note" the staff can
	 * type and save. The admin note is the same `Admin Note:` line surfaced in
	 * the Stall Chart Notes column and on Daily Movement, so editing it here
	 * keeps every surface in sync. Saved over AJAX via `eem_order_admin_note_set`.
	 *
	 * Deliberately separate from the Activity Log (system event trail) and from
	 * Special Instructions (the reservation-level instructions). Always renders
	 * because the editable note field is always available.
	 *
	 * @param array<string,mixed> $order Order row (must carry 'notes' + 'order_key').
	 * @return void
	 */
	private function render_order_notes_card( array $order ): void {
		$notes     = isset( $order['notes'] ) ? (string) $order['notes'] : '';
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$conf      = class_exists( 'EEM_Admin' ) ? EEM_Admin::parse_confirmation_numbers_from_notes( $notes ) : '';
		$text      = class_exists( 'EEM_Admin' ) ? EEM_Admin::parse_customer_notes_from_order_notes( $notes ) : '';

		// Current admin note (single line, matches EEM_Admin::get_order_note_value).
		$admin_note = '';
		if ( preg_match( '/(?:^|\n)Admin Note:\s*(.+?)(?:\n|$)/i', $notes, $m ) ) {
			$admin_note = trim( $m[1] );
		}
		$nonce = '' !== $order_key ? wp_create_nonce( 'eem_order_admin_note_' . $order_key ) : '';
		?>
		<div class="eem-order-full-width">
			<div class="eem-card eem-order-card eem-order-notes"
				data-order-key="<?php echo esc_attr( $order_key ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
				<div class="eem-order-card__header">
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Order Notes', 'equine-event-manager' ); ?></div>
				</div>
				<div class="eem-order-notes__body">
					<?php if ( '' !== $conf ) : ?>
						<div class="eem-order-notes__field">
							<span class="eem-order-notes__label"><?php esc_html_e( 'Confirmation #', 'equine-event-manager' ); ?></span>
							<span class="eem-order-notes__value"><?php echo esc_html( $conf ); ?></span>
						</div>
					<?php endif; ?>
					<?php if ( '' !== trim( $text ) ) : ?>
						<div class="eem-order-notes__field">
							<span class="eem-order-notes__label"><?php esc_html_e( 'Customer Notes', 'equine-event-manager' ); ?></span>
							<p class="eem-order-notes__text"><?php echo nl2br( esc_html( trim( $text ) ) ); ?></p>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $order_key ) : ?>
						<div class="eem-order-notes__field">
							<label class="eem-order-notes__label" for="eem-order-note-input"><?php esc_html_e( 'Note', 'equine-event-manager' ); ?></label>
							<textarea id="eem-order-note-input" class="eem-field-input eem-order-notes__input" rows="3" placeholder="<?php esc_attr_e( 'Add a note about this order…', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $admin_note ); ?></textarea>
							<p class="eem-order-notes__hint"><?php esc_html_e( 'Also shows in the Stall Chart Notes column and on Daily Movement.', 'equine-event-manager' ); ?></p>
							<div class="eem-order-notes__actions">
								<button type="button" class="eem-btn eem-btn-electric eem-btn-sm" data-eem-action="order-notes-save"><?php esc_html_e( 'Save Note', 'equine-event-manager' ); ?></button>
								<span class="eem-order-notes__status" role="status" aria-live="polite"></span>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Required Documents card — lists every document the reservation defines
	 * and lets the admin upload (or replace) a file on the customer's behalf.
	 * Uploaded files stream through the authenticated download endpoint.
	 *
	 * Renders only when the reservation has the Required Documents section
	 * enabled with at least one named requirement. Uploads dispatch to the
	 * shared `eem_upload_required_doc` AJAX endpoint keyed by the order_key.
	 *
	 * @param array<string,mixed> $order          Order row.
	 * @param int                 $reservation_id Owning reservation post ID.
	 * @return void
	 */
	private function render_required_documents_card( array $order, int $reservation_id ): void {
		if ( ! class_exists( 'EEM_Order_Documents' ) || $reservation_id <= 0 ) {
			return;
		}
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key ) {
			return;
		}
		$enabled = class_exists( 'EEM_Reservation_Config' )
			? ! empty( EEM_Reservation_Config::for( $reservation_id )->get( 'required_documents_enabled', 0 ) )
			: false;
		$names = EEM_Order_Documents::requirement_names( $reservation_id );
		if ( ! $enabled || empty( $names ) ) {
			return;
		}
		$uploaded = EEM_Order_Documents::get_for_order( $order_key );
		$nonce    = wp_create_nonce( 'eem_required_doc' );
		?>
		<div class="eem-order-full-width">
			<div class="eem-card eem-order-card eem-order-docs"
				data-eem-order-docs
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-order-key="<?php echo esc_attr( $order_key ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<div class="eem-order-card__header">
					<div class="eem-order-card__title"><?php echo EEM_Dashboard_Icons::svg( 'paperclip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Required Documents', 'equine-event-manager' ); ?></div>
				</div>
				<div class="eem-order-docs__body">
					<?php foreach ( $names as $req ) :
						$row          = isset( $uploaded[ $req ] ) ? $uploaded[ $req ] : null;
						$has_file     = $row && ! empty( $row['file_name'] );
						$is_satisfied = $row && ! empty( $row['satisfied'] );
						$dl           = $has_file ? EEM_Order_Documents::download_url( $order_key, $req ) : '';
						$state_class  = $has_file ? ' is-uploaded' : ( $is_satisfied ? ' is-satisfied' : '' );
						?>
						<div class="eem-order-doc-row<?php echo esc_attr( $state_class ); ?>" data-doc-name="<?php echo esc_attr( $req ); ?>">
							<div class="eem-order-doc-row__main">
								<span class="eem-order-doc-row__name"><?php echo esc_html( $req ); ?></span>
								<span class="eem-order-doc-row__file" data-doc-file>
									<?php if ( $has_file ) : ?>
										<a href="<?php echo esc_url( $dl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $row['original_name'] ); ?></a>
									<?php elseif ( $is_satisfied ) : ?>
										<span class="eem-order-doc-row__satisfied-note"><?php esc_html_e( 'Verified in person', 'equine-event-manager' ); ?></span>
									<?php else : ?>
										<em class="eem-order-doc-row__missing"><?php esc_html_e( 'Not provided', 'equine-event-manager' ); ?></em>
									<?php endif; ?>
								</span>
							</div>
							<div class="eem-order-doc-row__actions">
								<label class="eem-order-doc-row__cam">
									<input type="file" accept="image/*" capture="environment" data-eem-doc-input />
									<span class="eem-btn eem-btn-ghost eem-btn-sm eem-order-doc-row__btn"><?php esc_html_e( 'Take Photo', 'equine-event-manager' ); ?></span>
								</label>
								<label class="eem-order-doc-row__upload">
									<input type="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp" data-eem-doc-input />
									<span class="eem-btn eem-btn-ghost eem-btn-sm eem-order-doc-row__btn"><?php echo $has_file ? esc_html__( 'Replace', 'equine-event-manager' ) : esc_html__( 'Upload', 'equine-event-manager' ); ?></span>
								</label>
								<?php if ( $is_satisfied ) : ?>
									<button type="button" class="eem-btn eem-btn-sm eem-order-doc-row__satisfy is-satisfied" data-eem-doc-satisfy="0"><?php esc_html_e( 'Satisfied ✓', 'equine-event-manager' ); ?></button>
								<?php else : ?>
									<button type="button" class="eem-btn eem-btn-sm eem-order-doc-row__satisfy is-primary" data-eem-doc-satisfy="1"><?php esc_html_e( 'Mark Satisfied', 'equine-event-manager' ); ?></button>
								<?php endif; ?>
							</div>
							<span class="eem-order-doc-row__status" aria-live="polite"></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<script>
		(function(){
			var card = document.querySelector('[data-eem-order-docs]');
			if ( ! card || card.dataset.eemDocsBound ) { return; }
			card.dataset.eemDocsBound = '1';
			var ajaxUrl = card.getAttribute('data-ajax-url');
			var orderKey = card.getAttribute('data-order-key');
			var nonce = card.getAttribute('data-nonce');
			var T = {
				uploading: <?php echo wp_json_encode( __( 'Uploading…', 'equine-event-manager' ) ); ?>,
				saving:    <?php echo wp_json_encode( __( 'Saving…', 'equine-event-manager' ) ); ?>,
				failed:    <?php echo wp_json_encode( __( 'Upload failed. Please try again.', 'equine-event-manager' ) ); ?>
			};
			card.querySelectorAll('.eem-order-doc-row').forEach(function(row){
				var status = row.querySelector('.eem-order-doc-row__status');
				var name = row.getAttribute('data-doc-name');

				// Upload + camera-capture inputs (both carry data-eem-doc-input).
				row.querySelectorAll('[data-eem-doc-input]').forEach(function(input){
					input.addEventListener('change', function(){
						if ( ! input.files || ! input.files.length ) { return; }
						var file = input.files[0];
						status.textContent = T.uploading;
						var fd = new FormData();
						fd.append('action', 'eem_upload_required_doc');
						fd.append('nonce', nonce);
						fd.append('order_key', orderKey);
						fd.append('requirement', name);
						fd.append('file', file);
						fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
							.then(function(r){ return r.json(); })
							.then(function(res){
								if ( res && res.success ) {
									// Reload so the row state + outstanding-docs banner re-render.
									window.location.reload();
								} else {
									status.textContent = (res && res.data && res.data.message) ? res.data.message : T.failed;
								}
							})
							.catch(function(){ status.textContent = T.failed; });
					});
				});

				// Mark Satisfied / Undo (in-person verification, no file).
				var satisfyBtn = row.querySelector('[data-eem-doc-satisfy]');
				if ( satisfyBtn ) {
					satisfyBtn.addEventListener('click', function(){
						var to = satisfyBtn.getAttribute('data-eem-doc-satisfy');
						status.textContent = T.saving;
						satisfyBtn.disabled = true;
						var fd = new FormData();
						fd.append('action', 'eem_mark_doc_satisfied');
						fd.append('nonce', nonce);
						fd.append('order_key', orderKey);
						fd.append('requirement', name);
						fd.append('satisfied', to);
						fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
							.then(function(r){ return r.json(); })
							.then(function(res){
								if ( res && res.success ) {
									window.location.reload();
								} else {
									satisfyBtn.disabled = false;
									status.textContent = (res && res.data && res.data.message) ? res.data.message : T.failed;
								}
							})
							.catch(function(){ satisfyBtn.disabled = false; status.textContent = T.failed; });
					});
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Single-order Refund modal (C6.B). Reuses the C5.D `.eem-modal*`
	 * vocabulary (overlay, card, head, body, foot, close button, split
	 * footer) and the C1.2 field primitives (`.eem-field-label`,
	 * `.eem-field-textarea`). Zero new modal-chrome CSS in C6.B — only
	 * the refund-amount field row + summary block are additive.
	 *
	 * Markup is rendered once per page load, populated client-side when
	 * the More menu's "Refund Order" item dispatches `order-refund-single`.
	 * The amount field defaults to the order's outstanding balance; user
	 * can override down for partial refunds. Server-side validation in
	 * EEM_Admin::process_amount_refund() enforces > 0 and <= remaining
	 * refundable balance (which already accounts for prior partial
	 * refunds via get_component_refunded_amount).
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_refund_modal( array $order ) {
		$order_key       = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$customer_name   = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$total           = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		$default_amount  = number_format( $total, 2, '.', '' );

		// Per-tender breakdown from the payments ledger. When the order has a
		// ledger we let the admin choose how much to refund to each tender (the
		// card portion routes to the gateway; cash/check is recorded as a manual
		// refund). Orders without a ledger fall back to the single-amount field.
		$tenders = ( '' !== $order_key && class_exists( 'EEM_Order_Payments_Repo' ) )
			? EEM_Order_Payments_Repo::summary_by_method( $order_key )
			: array();
		$refundable_tenders = array();
		foreach ( $tenders as $eem_t ) {
			if ( $eem_t['refundable'] > 0.009 ) {
				$refundable_tenders[] = $eem_t;
			}
		}
		$has_tenders = ! empty( $refundable_tenders );

		?>
		<div class="eem-modal" id="eem-order-refund-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-refund-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-refund-title"><?php esc_html_e( 'Refund Order', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-refund-single-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-order-refund-form>
					<?php wp_nonce_field( 'eem_refund_single_' . $order_key, '_eem_refund_single_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_refund_single" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />

					<?php if ( $has_tenders ) : ?>
						<p class="eem-order-refund-summary">
							<?php
							printf(
								/* translators: 1: customer name */
								esc_html__( 'Refund this order for %1$s. Choose how much to return to each payment method. The card portion is reversed through the processor; cash / check is recorded as a manual refund.', 'equine-event-manager' ),
								'<strong>' . esc_html( $customer_name ) . '</strong>'
							);
							?>
						</p>

						<?php foreach ( $refundable_tenders as $eem_t ) : ?>
							<div class="eem-field-row eem-order-refund-amount-row">
								<label class="eem-field-label" for="eem-order-refund-tender-<?php echo esc_attr( $eem_t['key'] ); ?>">
									<?php
									printf(
										/* translators: 1: tender label (e.g. Authorize.net / Cash), 2: refundable amount */
										esc_html__( '%1$s — up to $%2$s', 'equine-event-manager' ),
										esc_html( $eem_t['label'] ),
										esc_html( number_format( $eem_t['refundable'], 2 ) )
									);
									?>
									<?php if ( ! $eem_t['is_gateway'] ) : ?>
										<span class="eem-order-payment__hint"><?php esc_html_e( '(manual)', 'equine-event-manager' ); ?></span>
									<?php endif; ?>
								</label>
								<div class="eem-order-refund-amount-input">
									<span class="eem-order-refund-amount-prefix">$</span>
									<input
										type="number"
										id="eem-order-refund-tender-<?php echo esc_attr( $eem_t['key'] ); ?>"
										name="tenders[<?php echo esc_attr( $eem_t['key'] ); ?>]"
										step="0.01"
										min="0"
										max="<?php echo esc_attr( number_format( $eem_t['refundable'], 2, '.', '' ) ); ?>"
										value="0.00"
										data-eem-refundable="<?php echo esc_attr( number_format( $eem_t['refundable'], 2, '.', '' ) ); ?>"
									/>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="eem-order-refund-summary">
							<?php
							printf(
								/* translators: 1: customer name */
								esc_html__( 'Refund this order for %1$s. The amount will be reversed to the original payment method via the order\'s configured processor.', 'equine-event-manager' ),
								'<strong>' . esc_html( $customer_name ) . '</strong>'
							);
							?>
						</p>

						<div class="eem-field-row eem-order-refund-amount-row">
							<label class="eem-field-label" for="eem-order-refund-amount"><?php esc_html_e( 'Refund amount', 'equine-event-manager' ); ?></label>
							<div class="eem-order-refund-amount-input">
								<span class="eem-order-refund-amount-prefix">$</span>
								<input type="number" id="eem-order-refund-amount" name="amount" step="0.01" min="0.01" value="<?php echo esc_attr( $default_amount ); ?>" required />
							</div>
							<p class="eem-field-hint">
								<?php
								printf(
									/* translators: %s: order total */
									esc_html__( 'Defaults to the full order total ($%s). Lower this for a partial refund.', 'equine-event-manager' ),
									esc_html( number_format( $total, 2 ) )
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-order-refund-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<textarea class="eem-field-textarea" id="eem-order-refund-reason" name="reason" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Customer disputed shavings quantity', 'equine-event-manager' ); ?>"></textarea>
					</div>

					<div class="eem-field-row eem-order-refund-notify-row">
						<label class="eem-order-refund-notify">
							<input type="checkbox" name="notify" value="1" />
							<?php esc_html_e( 'Email the customer a refund confirmation', 'equine-event-manager' ); ?>
						</label>
					</div>

					<div class="eem-order-refund-error" data-eem-order-refund-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-refund-single-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-refund-single-confirm"><?php esc_html_e( 'Confirm refund', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Edit-Dates modal — lets the admin change a stall/RV section's arrival /
	 * departure dates so reports reflect actual usage (e.g. a no-show that
	 * shortens the billable stay). One shared modal, populated client-side from
	 * the clicked "Edit Dates" button's data attributes (component, current
	 * dates, per-night cost). When the night count changes the modal surfaces a
	 * money choice: refund the per-night delta (shorter) or add it to the
	 * balance due (longer). Dispatches eem_order_edit_dates.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_edit_dates_modal( array $order ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key ) {
			return;
		}
		?>
		<div class="eem-modal" id="eem-order-edit-dates-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-edit-dates-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-edit-dates-title"><?php esc_html_e( 'Edit Dates', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-edit-dates-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-edit-dates-form>
					<?php wp_nonce_field( 'eem_edit_dates_' . $order_key, '_eem_edit_dates_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_edit_dates" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
					<input type="hidden" name="component" value="" data-eem-edit-dates-component />
					<input type="hidden" name="money_action" value="none" data-eem-edit-dates-money />

					<p class="eem-order-refund-summary" data-eem-edit-dates-section-label></p>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-edit-dates-arrival"><?php esc_html_e( 'Arrival date', 'equine-event-manager' ); ?></label>
						<input type="date" class="eem-field-input" id="eem-edit-dates-arrival" name="arrival" required />
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-edit-dates-departure"><?php esc_html_e( 'Departure date', 'equine-event-manager' ); ?></label>
						<input type="date" class="eem-field-input" id="eem-edit-dates-departure" name="departure" required />
					</div>

					<div class="eem-edit-dates-impact" data-eem-edit-dates-impact hidden>
						<p class="eem-edit-dates-impact__text" data-eem-edit-dates-impact-text></p>
						<div class="eem-edit-dates-money" data-eem-edit-dates-money-choice>
							<label class="eem-order-refund-notify">
								<input type="radio" name="money_choice" value="apply" data-eem-edit-dates-money-apply />
								<span data-eem-edit-dates-money-apply-label></span>
							</label>
							<label class="eem-order-refund-notify">
								<input type="radio" name="money_choice" value="none" checked />
								<?php esc_html_e( 'Update dates only — no payment change', 'equine-event-manager' ); ?>
							</label>
						</div>
					</div>

					<div class="eem-order-refund-error" data-eem-edit-dates-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-edit-dates-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-edit-dates-confirm"><?php esc_html_e( 'Save Dates', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Cancel-order modal — captures an optional reason and a notify toggle, then
	 * dispatches eem_order_cancel_single. Cancelling is terminal: it frees the
	 * reserved stalls/RV lots and emails the customer, but does NOT refund any
	 * payment (the admin refunds separately if money is owed back).
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_cancel_modal( array $order ) {
		if ( isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'] ) {
			return; // Already cancelled — no modal needed.
		}
		$order_key     = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$customer_name = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		?>
		<div class="eem-modal" id="eem-order-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-cancel-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-cancel-title"><?php esc_html_e( 'Cancel Order', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-cancel-single-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-order-cancel-form>
					<?php wp_nonce_field( 'eem_cancel_single_' . $order_key, '_eem_cancel_single_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_cancel_single" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />

					<p class="eem-order-refund-summary">
						<?php
						printf(
							/* translators: 1: customer name */
							esc_html__( 'Cancel this order for %1$s. The reserved stalls / RV lots are freed for others, and the customer is emailed a cancellation notice. This does NOT refund any payment — refund separately first if money is owed back.', 'equine-event-manager' ),
							'<strong>' . esc_html( $customer_name ) . '</strong>'
						);
						?>
					</p>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-order-cancel-reason"><?php esc_html_e( 'Reason (optional)', 'equine-event-manager' ); ?></label>
						<textarea class="eem-field-textarea" id="eem-order-cancel-reason" name="reason" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Customer can no longer attend', 'equine-event-manager' ); ?>"></textarea>
					</div>

					<div class="eem-field-row eem-order-refund-notify-row">
						<label class="eem-order-refund-notify">
							<input type="checkbox" name="notify" value="1" checked />
							<?php esc_html_e( 'Email the customer a cancellation notice', 'equine-event-manager' ); ?>
						</label>
					</div>

					<div class="eem-order-refund-error" data-eem-order-cancel-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-cancel-single-close"><?php esc_html_e( 'Keep order', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-danger" data-eem-action="order-cancel-single-confirm"><?php esc_html_e( 'Cancel order', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Mark-as-paid modal — records a manual cash / check (in-person) payment on an
	 * unpaid order without running a card. Mirrors the AJAX-modal pattern of the
	 * Cancel/Refund modals; the confirm posts to the eem_order_mark_paid_single
	 * handler, which calls EEM_Orders_Repository::mark_order_paid_manually(). Only
	 * rendered for the outstanding states the manual-payment path accepts.
	 *
	 * @param array<string, mixed> $order Grouped order payload.
	 * @return void
	 */
	private function render_mark_paid_modal( array $order ) {
		$status_slug = isset( $order['status_slug'] ) ? (string) $order['status_slug'] : '';
		if ( ! in_array( $status_slug, array( 'unpaid', 'invoice-sent' ), true ) ) {
			return;
		}
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$amount    = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
		?>
		<div class="eem-modal" id="eem-order-mark-paid-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-mark-paid-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-mark-paid-title"><?php esc_html_e( 'Mark as Paid', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-mark-paid-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-order-mark-paid-form>
					<?php wp_nonce_field( 'eem_mark_paid_single_' . $order_key, '_eem_mark_paid_single_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_mark_paid_single" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />

					<p class="eem-order-refund-summary">
						<?php
						printf(
							/* translators: 1: order total amount */
							esc_html__( 'Record a cash or check payment of %1$s collected in person. This marks the order Paid and is logged to the activity history. It does NOT run a card.', 'equine-event-manager' ),
							'<strong>' . esc_html( '$' . number_format_i18n( $amount, 2 ) ) . '</strong>'
						);
						?>
					</p>

					<div class="eem-field-row">
						<span class="eem-field-label"><?php esc_html_e( 'Payment method', 'equine-event-manager' ); ?></span>
						<label class="eem-order-mark-paid-method"><input type="radio" name="method" value="cash" checked data-eem-mark-paid-method /> <?php esc_html_e( 'Cash', 'equine-event-manager' ); ?></label>
						<label class="eem-order-mark-paid-method"><input type="radio" name="method" value="check" data-eem-mark-paid-method /> <?php esc_html_e( 'Check', 'equine-event-manager' ); ?></label>
					</div>

					<div class="eem-field-row" data-eem-mark-paid-check-row hidden>
						<label class="eem-field-label" for="eem-order-mark-paid-check"><?php esc_html_e( 'Check number (optional)', 'equine-event-manager' ); ?></label>
						<input type="text" class="eem-field-input" id="eem-order-mark-paid-check" name="check_number" maxlength="40" placeholder="<?php esc_attr_e( 'e.g. 1042', 'equine-event-manager' ); ?>" />
					</div>

					<div class="eem-order-refund-error" data-eem-order-mark-paid-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-mark-paid-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-mark-paid-confirm"><?php esc_html_e( 'Mark as Paid', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Remove-discount modal — captures the REQUIRED fresh reason for removing an
	 * applied discount (logged as a new Activity Log entry per the discount
	 * schema). Only rendered when the order actually carries a discount.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_remove_discount_modal( array $order ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			return;
		}
		if ( null === EEM_Order_Adjustments_Repo::get_discount( $order_key ) ) {
			return;
		}
		?>
		<div class="eem-modal" id="eem-order-remove-discount-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-remove-discount-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-remove-discount-title"><?php esc_html_e( 'Remove Discount', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-remove-discount-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-remove-discount-form>
					<?php wp_nonce_field( 'eem_remove_discount_' . $order_key, '_eem_remove_discount_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_remove_discount" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
					<p class="eem-order-refund-summary"><?php esc_html_e( 'Removing the discount restores the order total. A reason is required and recorded in the Activity Log.', 'equine-event-manager' ); ?></p>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-order-remove-discount-reason"><?php esc_html_e( 'Reason', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label>
						<textarea class="eem-field-textarea" id="eem-order-remove-discount-reason" name="reason" rows="3" maxlength="500" required placeholder="<?php esc_attr_e( 'e.g. Discount applied in error', 'equine-event-manager' ); ?>"></textarea>
					</div>
					<div class="eem-order-refund-error" data-eem-remove-discount-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-remove-discount-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-remove-discount-confirm"><?php esc_html_e( 'Remove discount', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Add-Items modal — adds reservation inventory (stalls / RV lots) or a custom
	 * line item to the order at any time (Order Edit). The "Item type" select is
	 * populated from the reservation's enabled sections (via
	 * EEM_Shortcodes::get_addable_inventory) plus an always-present Custom Line
	 * Item option. Per-stay-type rates + the available date window are embedded as
	 * data attributes so the client can show a live base-price preview; the
	 * authoritative price is recomputed server-side on submit.
	 *
	 * Not rendered for cancelled orders.
	 *
	 * @param array<string, mixed> $order Grouped order payload.
	 * @return void
	 */
	private function render_add_items_modal( array $order ) {
		if ( isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'] ) {
			return;
		}
		$order_key      = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$reservation_id = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
		if ( '' === $order_key ) {
			return;
		}

		$shortcodes = ( $reservation_id > 0 && class_exists( 'EEM_Shortcodes' ) ) ? new EEM_Shortcodes() : null;
		$inventory  = $shortcodes
			? $shortcodes->get_addable_inventory( $reservation_id )
			: array( 'stall' => null, 'rv' => null, 'available_start' => '', 'available_end' => '' );
		// Flat-rate sellable products (Additional Shavings + General Add-Ons) the
		// reservation has enabled — scanned live so the dropdown always reflects
		// what's actually for sale rather than a hardcoded Stall/RV/Custom list.
		$products = $shortcodes ? $shortcodes->get_addable_products( $reservation_id ) : array();

		// Prefill billing dates from the order's existing section, else the
		// reservation's available window.
		$default_arrival   = (string) ( $order['stall_arrival_date'] ?? $order['rv_arrival_date'] ?? $inventory['available_start'] ?? '' );
		$default_departure = (string) ( $order['stall_departure_date'] ?? $order['rv_departure_date'] ?? $inventory['available_end'] ?? '' );
		?>
		<div class="eem-modal" id="eem-order-add-items-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-add-items-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-add-items-title"><?php esc_html_e( 'Add Items', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-add-items-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-add-items-form
					data-default-arrival="<?php echo esc_attr( $default_arrival ); ?>"
					data-default-departure="<?php echo esc_attr( $default_departure ); ?>">
					<?php wp_nonce_field( 'eem_add_items_' . $order_key, '_eem_add_items_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_add_items" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />

					<p class="eem-order-refund-summary"><?php esc_html_e( 'Add inventory or a custom charge to this order. New charges are added to the balance due and logged to the activity history.', 'equine-event-manager' ); ?></p>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-add-items-type"><?php esc_html_e( 'Item type', 'equine-event-manager' ); ?></label>
						<select class="eem-field-input" id="eem-add-items-type" name="item_type" data-eem-add-items-type>
							<?php foreach ( array( 'stall', 'rv' ) as $section ) : ?>
								<?php if ( ! empty( $inventory[ $section ] ) ) : ?>
									<option value="<?php echo esc_attr( $section ); ?>"
										data-rates="<?php echo esc_attr( wp_json_encode( $inventory[ $section ]['rates'] ) ); ?>"
										data-staytypes="<?php echo esc_attr( wp_json_encode( $inventory[ $section ]['stay_types'] ) ); ?>">
										<?php echo esc_html( $inventory[ $section ]['label'] ); ?>
									</option>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( ! empty( $products ) ) : ?>
								<optgroup label="<?php esc_attr_e( 'Add-Ons & Shavings', 'equine-event-manager' ); ?>">
									<?php foreach ( $products as $product ) : ?>
										<option value="product"
											data-product-key="<?php echo esc_attr( $product['key'] ); ?>"
											data-price="<?php echo esc_attr( (string) (float) $product['price'] ); ?>">
											<?php echo esc_html( $product['label'] . ' — $' . number_format_i18n( (float) $product['price'], 2 ) ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
							<option value="custom"><?php esc_html_e( 'Custom Line Item', 'equine-event-manager' ); ?></option>
						</select>
						<input type="hidden" name="product_key" value="" data-eem-add-items-product-key />
					</div>

					<?php // Inventory fields (stall / rv) — hidden when item type is custom. ?>
					<div data-eem-add-items-inventory>
						<div class="eem-field-row" data-eem-add-items-staytype-row>
							<label class="eem-field-label" for="eem-add-items-staytype"><?php esc_html_e( 'Stay type', 'equine-event-manager' ); ?></label>
							<select class="eem-field-input" id="eem-add-items-staytype" name="stay_type" data-eem-add-items-staytype>
								<?php
								// Seeded from the first section's options; refreshed by JS on type change.
								$first = ! empty( $inventory['stall'] ) ? $inventory['stall'] : ( ! empty( $inventory['rv'] ) ? $inventory['rv'] : null );
								if ( $first ) :
									foreach ( $first['stay_types'] as $value => $label ) :
										?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php
									endforeach;
								endif;
								?>
							</select>
						</div>
						<div class="eem-field-row">
							<label class="eem-field-label" for="eem-add-items-qty"><?php esc_html_e( 'Quantity', 'equine-event-manager' ); ?></label>
							<input type="number" class="eem-field-input" id="eem-add-items-qty" name="qty" min="1" step="1" value="1" data-eem-add-items-qty />
						</div>
						<div class="eem-field-row eem-field-row--split" data-eem-add-items-dates-row>
							<div>
								<label class="eem-field-label" for="eem-add-items-arrival"><?php esc_html_e( 'Arrival', 'equine-event-manager' ); ?></label>
								<input type="date" class="eem-field-input" id="eem-add-items-arrival" name="arrival" value="<?php echo esc_attr( $default_arrival ); ?>" data-eem-add-items-arrival />
							</div>
							<div>
								<label class="eem-field-label" for="eem-add-items-departure"><?php esc_html_e( 'Departure', 'equine-event-manager' ); ?></label>
								<input type="date" class="eem-field-input" id="eem-add-items-departure" name="departure" value="<?php echo esc_attr( $default_departure ); ?>" data-eem-add-items-departure />
							</div>
						</div>
						<p class="eem-order-add-items-preview" data-eem-add-items-preview></p>
					</div>

					<?php // Custom line item fields — hidden unless item type is custom. ?>
					<div data-eem-add-items-custom hidden>
						<div class="eem-field-row">
							<label class="eem-field-label" for="eem-add-items-desc"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label>
							<input type="text" class="eem-field-input" id="eem-add-items-desc" name="description" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. Late fee', 'equine-event-manager' ); ?>" />
						</div>
						<div class="eem-field-row">
							<label class="eem-field-label" for="eem-add-items-amount"><?php esc_html_e( 'Amount', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label>
							<input type="number" class="eem-field-input" id="eem-add-items-amount" name="amount" step="0.01" placeholder="0.00" />
						</div>
					</div>

					<div class="eem-order-refund-error" data-eem-add-items-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-add-items-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-add-items-confirm"><?php esc_html_e( 'Add to order', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX — remove an order's discount with a required fresh reason.
	 *
	 * Capability + nonce gated. Validates the reason is non-empty, removes the
	 * discount via the adjustments repo, and logs an order_discount_removed
	 * Activity Log entry (order_key + reason in payload). Responds with success +
	 * requires_reload so the client refreshes the recomputed total.
	 *
	 * @return void
	 */
	public static function ajax_remove_discount(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_ajax_referer( 'eem_remove_discount_' . $order_key, '_eem_remove_discount_nonce' );

		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( '' === trim( $reason ) ) {
			wp_send_json_error( array(
				'message' => __( 'A reason is required to remove the discount.', 'equine-event-manager' ),
				'code'    => 'reason_required',
			), 422 );
		}

		if ( '' === $order_key || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		$existing = EEM_Order_Adjustments_Repo::get_discount( $order_key );
		if ( null === $existing ) {
			wp_send_json_error( array(
				'message' => __( 'This order has no discount to remove.', 'equine-event-manager' ),
				'code'    => 'no_discount',
			), 404 );
		}

		$removed = EEM_Order_Adjustments_Repo::remove_discount( $order_key );
		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => __( 'The discount could not be removed. Please try again.', 'equine-event-manager' ) ), 500 );
		}

		if ( class_exists( 'EEM_Activity_Log' ) ) {
			EEM_Activity_Log::write(
				'order_discount_removed',
				array(
					'order_key'       => $order_key,
					'discount_type'   => isset( $existing['type'] ) ? (string) $existing['type'] : '',
					'discount_value'  => isset( $existing['value'] ) ? (float) $existing['value'] : 0.0,
					'discount_amount' => isset( $existing['amount'] ) ? (float) $existing['amount'] : 0.0,
					'reason'          => $reason,
				),
				array(
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
				)
			);
		}

		wp_send_json_success( array(
			'requires_reload' => true,
			'message'         => __( 'Discount removed.', 'equine-event-manager' ),
		) );
	}

	/**
	 * Add-discount modal — type (dollar/percent) + value + required reason. Only
	 * rendered when the order has no discount yet and isn't cancelled.
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_add_discount_modal( array $order ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			return;
		}
		if ( null !== EEM_Order_Adjustments_Repo::get_discount( $order_key ) ) {
			return; // already has a discount.
		}
		if ( isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'] ) {
			return;
		}
		?>
		<div class="eem-modal" id="eem-order-add-discount-modal" role="dialog" aria-modal="true" aria-labelledby="eem-order-add-discount-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-order-add-discount-title"><?php esc_html_e( 'Add Discount', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="order-add-discount-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" method="post" data-eem-add-discount-form>
					<?php wp_nonce_field( 'eem_add_discount_' . $order_key, '_eem_add_discount_nonce' ); ?>
					<input type="hidden" name="action" value="eem_order_add_discount" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
					<p class="eem-order-refund-summary"><?php esc_html_e( 'Discounts apply to the order subtotal. A reason is required and recorded in the Activity Log.', 'equine-event-manager' ); ?></p>
					<div class="eem-field-row eem-field-row--split">
						<div>
							<label class="eem-field-label" for="eem-add-discount-type"><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></label>
							<select class="eem-field-input" id="eem-add-discount-type" name="discount_type">
								<option value="dollar"><?php esc_html_e( 'Dollar ($)', 'equine-event-manager' ); ?></option>
								<option value="percent"><?php esc_html_e( 'Percent (%)', 'equine-event-manager' ); ?></option>
							</select>
						</div>
						<div>
							<label class="eem-field-label" for="eem-add-discount-value"><?php esc_html_e( 'Amount', 'equine-event-manager' ); ?></label>
							<input type="number" class="eem-field-input" id="eem-add-discount-value" name="discount_value" min="0" step="0.01" placeholder="0.00" />
						</div>
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-add-discount-reason"><?php esc_html_e( 'Reason', 'equine-event-manager' ); ?> <span class="eem-req">*</span></label>
						<input type="text" class="eem-field-input" id="eem-add-discount-reason" name="discount_reason" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. Repeat-customer courtesy', 'equine-event-manager' ); ?>" />
					</div>
					<div class="eem-order-refund-error" data-eem-add-discount-error hidden></div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="order-add-discount-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="order-add-discount-confirm"><?php esc_html_e( 'Apply discount', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX — apply a discount to an order (type + value + required reason). The
	 * discount amount is resolved against the order's stall + RV + custom-item
	 * subtotal and persisted via the adjustments repo; an order_discount_applied
	 * Activity Log entry records it. Capability + nonce gated.
	 *
	 * @return void
	 */
	public static function ajax_add_discount(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_ajax_referer( 'eem_add_discount_' . $order_key, '_eem_add_discount_nonce' );

		$type   = isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : 'dollar';
		$type   = ( 'percent' === $type ) ? 'percent' : 'dollar';
		$value  = isset( $_POST['discount_value'] ) ? round( (float) wp_unslash( $_POST['discount_value'] ), 2 ) : 0.0;
		$reason = isset( $_POST['discount_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_reason'] ) ) : '';

		if ( '' === trim( $reason ) ) {
			wp_send_json_error( array( 'message' => __( 'A reason is required.', 'equine-event-manager' ), 'code' => 'reason_required' ), 422 );
		}
		if ( $value <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a discount amount greater than zero.', 'equine-event-manager' ), 'code' => 'value_required' ), 422 );
		}
		if ( '' === $order_key || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}
		if ( null !== EEM_Order_Adjustments_Repo::get_discount( $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'This order already has a discount. Remove it first to change it.', 'equine-event-manager' ), 'code' => 'discount_exists' ), 409 );
		}

		$order = ( new EEM_Orders_Repository() )->get_order( $order_key );
		if ( ! is_array( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		$subtotal = (float) ( $order['stall_subtotal'] ?? 0 ) + (float) ( $order['rv_subtotal'] ?? 0 );
		$customs  = EEM_Order_Adjustments_Repo::get_custom_items( $order_key );
		foreach ( $customs as $ci ) {
			$subtotal += (float) $ci['amount'];
		}
		if ( 'percent' === $type && $value > 100 ) {
			wp_send_json_error( array( 'message' => __( 'A percentage discount cannot exceed 100%.', 'equine-event-manager' ), 'code' => 'value_too_large' ), 422 );
		}

		$discount_id = EEM_Order_Adjustments_Repo::set_discount( $order_key, $type, $value, $reason, $subtotal );
		if ( false === $discount_id ) {
			wp_send_json_error( array( 'message' => __( 'The discount could not be applied. Please try again.', 'equine-event-manager' ) ), 500 );
		}

		if ( class_exists( 'EEM_Activity_Log' ) ) {
			$resolved = EEM_Order_Adjustments_Repo::resolve_discount_amount( $type, $value, $subtotal );
			EEM_Activity_Log::write(
				'order_discount_applied',
				array(
					'order_key'       => $order_key,
					'discount_type'   => $type,
					'discount_value'  => $value,
					'discount_amount' => $resolved,
					'reason'          => $reason,
				),
				array( 'actor_type' => 'admin', 'actor_id' => get_current_user_id() )
			);
		}

		wp_send_json_success( array(
			'requires_reload' => true,
			'message'         => __( 'Discount applied.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX — remove a single custom line item from an order.
	 *
	 * Capability + per-order nonce gated. Deletes the adjustment row via the
	 * adjustments repo (scoped to order_key + kind so a stray id can't touch
	 * another order), logs an order_custom_item_removed Activity Log entry, and
	 * responds with requires_reload so the recomputed total refreshes.
	 *
	 * @return void
	 */
	public static function ajax_remove_custom_item(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_ajax_referer( 'eem_remove_custom_item_' . $order_key, '_wpnonce' );

		$item_id = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		if ( '' === $order_key || $item_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Custom line items are unavailable.', 'equine-event-manager' ) ), 500 );
		}

		$removed = EEM_Order_Adjustments_Repo::delete_custom_item( $order_key, $item_id );
		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => __( 'The line item could not be removed (it may have already been deleted).', 'equine-event-manager' ) ), 404 );
		}

		if ( class_exists( 'EEM_Activity_Log' ) ) {
			EEM_Activity_Log::write(
				'order_custom_item_removed',
				array( 'order_key' => $order_key, 'item_id' => $item_id ),
				array( 'actor_type' => 'admin', 'actor_id' => get_current_user_id() )
			);
		}

		wp_send_json_success( array(
			'requires_reload' => true,
			'message'         => __( 'Line item removed.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX — add an item to an existing order at any time (Order Edit).
	 *
	 * Supports three item types:
	 *   - 'stall' / 'rv' — adds $qty units of the reservation's inventory at the
	 *     base rate, priced via EEM_Shortcodes::price_base_rate_addition() and
	 *     persisted via EEM_Orders_Repository::add_component_quantity() (bumps an
	 *     existing component row or clones a new one). amount_paid is left
	 *     untouched so the addition surfaces as balance due.
	 *   - 'custom' — adds a free-form custom line item (description + amount) via
	 *     the order adjustments repo.
	 *
	 * Capability + nonce gated. Logs an order_item_added Activity Log entry and
	 * responds with requires_reload so the client refreshes the recomputed total.
	 *
	 * @return void
	 */
	public static function ajax_add_items(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		check_ajax_referer( 'eem_add_items_' . $order_key, '_eem_add_items_nonce' );

		if ( '' === $order_key ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}

		$repo  = new EEM_Orders_Repository();
		$order = $repo->get_order( $order_key );
		if ( ! is_array( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'equine-event-manager' ) ), 404 );
		}
		if ( isset( $order['status_slug'] ) && 'cancelled' === $order['status_slug'] ) {
			wp_send_json_error( array( 'message' => __( 'Cannot add items to a cancelled order.', 'equine-event-manager' ) ), 422 );
		}

		$item_type = isset( $_POST['item_type'] ) ? sanitize_key( wp_unslash( $_POST['item_type'] ) ) : '';

		/* ---- Custom line item ---- */
		if ( 'custom' === $item_type ) {
			$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
			$amount      = isset( $_POST['amount'] ) ? round( (float) wp_unslash( $_POST['amount'] ), 2 ) : 0.0;
			if ( '' === trim( $description ) ) {
				wp_send_json_error( array( 'message' => __( 'A description is required.', 'equine-event-manager' ), 'code' => 'description_required' ), 422 );
			}
			if ( abs( $amount ) < 0.005 ) {
				wp_send_json_error( array( 'message' => __( 'Enter a non-zero amount.', 'equine-event-manager' ), 'code' => 'amount_required' ), 422 );
			}
			if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
				wp_send_json_error( array( 'message' => __( 'Custom line items are unavailable.', 'equine-event-manager' ) ), 500 );
			}
			$inserted = EEM_Order_Adjustments_Repo::insert_custom_item( $order_key, $description, $amount );
			if ( false === $inserted ) {
				wp_send_json_error( array( 'message' => __( 'The line item could not be added. Please try again.', 'equine-event-manager' ) ), 500 );
			}

			self::log_item_added( $order_key, array(
				'item_type'   => 'custom',
				'description' => $description,
				'amount'      => $amount,
			) );

			wp_send_json_success( array(
				'requires_reload' => true,
				'message'         => __( 'Line item added.', 'equine-event-manager' ),
			) );
		}

		/* ---- Flat-rate product (Additional Shavings / General Add-On) ---- */
		if ( 'product' === $item_type ) {
			$reservation_id = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
			$product_key    = isset( $_POST['product_key'] ) ? sanitize_text_field( wp_unslash( $_POST['product_key'] ) ) : '';
			$qty            = isset( $_POST['qty'] ) ? max( 1, (int) wp_unslash( $_POST['qty'] ) ) : 0;
			if ( $reservation_id < 1 ) {
				wp_send_json_error( array(
					'message' => __( 'This order is not linked to a reservation, so add-ons cannot be priced. Add a custom line item instead.', 'equine-event-manager' ),
					'code'    => 'no_reservation',
				), 422 );
			}
			if ( $qty < 1 ) {
				wp_send_json_error( array( 'message' => __( 'Enter a quantity of at least 1.', 'equine-event-manager' ), 'code' => 'qty_required' ), 422 );
			}
			if ( ! class_exists( 'EEM_Shortcodes' ) || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
				wp_send_json_error( array( 'message' => __( 'Pricing engine unavailable.', 'equine-event-manager' ) ), 500 );
			}

			// Re-resolve the product price server-side from reservation config —
			// never trust a client-sent amount.
			$catalog = ( new EEM_Shortcodes() )->get_addable_products( $reservation_id );
			$match   = null;
			foreach ( $catalog as $candidate ) {
				if ( isset( $candidate['key'] ) && $candidate['key'] === $product_key ) {
					$match = $candidate;
					break;
				}
			}
			if ( null === $match ) {
				wp_send_json_error( array( 'message' => __( 'That add-on is no longer available on this reservation.', 'equine-event-manager' ), 'code' => 'bad_product' ), 422 );
			}

			$amount      = round( (float) $match['price'] * $qty, 2 );
			$description  = $qty > 1
				? sprintf( '%s × %d', $match['label'], $qty )
				: (string) $match['label'];
			$inserted = EEM_Order_Adjustments_Repo::insert_custom_item( $order_key, $description, $amount );
			if ( false === $inserted ) {
				wp_send_json_error( array( 'message' => __( 'The item could not be added. Please try again.', 'equine-event-manager' ) ), 500 );
			}

			self::log_item_added( $order_key, array(
				'item_type'   => 'product',
				'description' => $description,
				'qty'         => $qty,
				'unit_price'  => (float) $match['price'],
				'amount'      => $amount,
			) );

			wp_send_json_success( array(
				'requires_reload' => true,
				'message'         => sprintf(
					/* translators: 1: quantity, 2: product label */
					__( 'Added %1$d × %2$s.', 'equine-event-manager' ),
					$qty,
					$match['label']
				),
			) );
		}

		/* ---- Stall / RV inventory ---- */
		if ( 'stall' === $item_type || 'rv' === $item_type ) {
			$reservation_id = isset( $order['reservation_id'] ) ? (int) $order['reservation_id'] : 0;
			if ( $reservation_id < 1 ) {
				wp_send_json_error( array(
					'message' => __( 'This order is not linked to a reservation, so inventory cannot be priced. Add a custom line item instead.', 'equine-event-manager' ),
					'code'    => 'no_reservation',
				), 422 );
			}

			$qty       = isset( $_POST['qty'] ) ? max( 1, (int) wp_unslash( $_POST['qty'] ) ) : 0;
			$stay_type = isset( $_POST['stay_type'] ) ? sanitize_text_field( wp_unslash( $_POST['stay_type'] ) ) : '';
			$arrival   = isset( $_POST['arrival'] ) ? sanitize_text_field( wp_unslash( $_POST['arrival'] ) ) : '';
			$departure = isset( $_POST['departure'] ) ? sanitize_text_field( wp_unslash( $_POST['departure'] ) ) : '';
			if ( $qty < 1 ) {
				wp_send_json_error( array( 'message' => __( 'Enter a quantity of at least 1.', 'equine-event-manager' ), 'code' => 'qty_required' ), 422 );
			}

			if ( ! class_exists( 'EEM_Shortcodes' ) ) {
				wp_send_json_error( array( 'message' => __( 'Pricing engine unavailable.', 'equine-event-manager' ) ), 500 );
			}
			$priced = ( new EEM_Shortcodes() )->price_base_rate_addition( $reservation_id, $item_type, $qty, $stay_type, $arrival, $departure );
			if ( (float) $priced['unit_price'] <= 0.0 ) {
				wp_send_json_error( array(
					'message' => __( 'No base rate is configured for that stay type on this reservation.', 'equine-event-manager' ),
					'code'    => 'no_rate',
				), 422 );
			}

			$ok = $repo->add_component_quantity( $order_key, $item_type, $qty, $priced );
			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => __( 'The item could not be added. Please try again.', 'equine-event-manager' ) ), 500 );
			}

			self::log_item_added( $order_key, array(
				'item_type'  => $item_type,
				'qty'        => $qty,
				'stay_type'  => (string) $priced['stay_type'],
				'unit_price' => (float) $priced['unit_price'],
				'nights'     => (int) $priced['nights'],
				'subtotal'   => (float) $priced['subtotal'],
			) );

			wp_send_json_success( array(
				'requires_reload' => true,
				'message'         => sprintf(
					/* translators: 1: quantity, 2: item label */
					__( 'Added %1$d × %2$s.', 'equine-event-manager' ),
					$qty,
					'rv' === $item_type ? __( 'RV Lot', 'equine-event-manager' ) : __( 'Stall', 'equine-event-manager' )
				),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Unknown item type.', 'equine-event-manager' ), 'code' => 'bad_type' ), 422 );
	}

	/**
	 * Write an order_item_added Activity Log entry for an Order-Edit addition.
	 *
	 * @param string               $order_key Order key the item was added to.
	 * @param array<string, mixed> $payload   Item descriptor (type + amounts).
	 * @return void
	 */
	private static function log_item_added( string $order_key, array $payload ): void {
		if ( ! class_exists( 'EEM_Activity_Log' ) ) {
			return;
		}
		EEM_Activity_Log::write(
			'order_item_added',
			array_merge( array( 'order_key' => $order_key ), $payload ),
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
			)
		);
	}

	/**
	 * Activity Log section — real render (C6.E.1).
	 *
	 * Fetches entries via EEM_Activity_Log::get_for_order_key (added in
	 * C6.E.1 to bridge the order_id=NULL / order_key-in-payload gap from
	 * C6.D telemetry), enriches each entry's payload with a render-ready
	 * title via EEM_Order_Telemetry::enrich_entry_for_render, then hands
	 * off to the shared C2 partial eem_render_activity_log() for the
	 * actual entry HTML.
	 *
	 * Collapsible: default-expanded; toggle chevron flips on click via
	 * the activity-toggle JS dispatch arm. Show-all-capped-at-100 per
	 * the C6.E kickoff decision (pagination deferred — typical order has
	 * <20 entries; revisit if anyone hits the 100 cap).
	 *
	 * C6.E.2 will add the Add Note form below the entries list inside
	 * this same section.
	 *
	 * @param array<string, mixed> $order
	 * @param int                  $reservation_id
	 * @return void
	 */
	private function render_activity_log( array $order, $reservation_id ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		$entries   = '' !== $order_key
			? EEM_Activity_Log::get_for_order_key( $order_key, 100 )
			: array();

		// CLEANUP #26 — also surface reservation-config edits (the
		// `reservation_edited` diff entries logged on save) for the reservation
		// behind this order, so an order's Activity Log shows the underlying setup
		// changes as context. Merge, de-dupe by row id, and re-sort newest-first.
		$reservation_id = absint( $reservation_id );
		if ( $reservation_id > 0 ) {
			$res_entries = EEM_Activity_Log::get_for_reservation( $reservation_id, 100 );
			$seen        = array();
			foreach ( $entries as $e ) {
				if ( isset( $e['id'] ) ) {
					$seen[ (int) $e['id'] ] = true;
				}
			}
			foreach ( $res_entries as $e ) {
				if ( ! isset( $e['id'] ) || empty( $seen[ (int) $e['id'] ] ) ) {
					$entries[] = $e;
				}
			}
			usort( $entries, static function ( $a, $b ) {
				$ca = isset( $a['created_at'] ) ? (string) $a['created_at'] : '';
				$cb = isset( $b['created_at'] ) ? (string) $b['created_at'] : '';
				$cmp = strcmp( $cb, $ca ); // newest first
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 );
			} );
		}

		// Enrich each entry with a render-ready title so the C2 partial's
		// default-title resolver doesn't fall through on our C6.D event
		// type taxonomy (which it wouldn't recognize otherwise).
		$entries = array_map( array( 'EEM_Order_Telemetry', 'enrich_entry_for_render' ), $entries );

		$count = count( $entries );
		?>
		<div class="eem-order-activity" data-eem-activity-section>
			<div class="eem-order-activity__toggle" data-eem-action="activity-toggle" role="button" tabindex="0" aria-expanded="true">
				<div class="eem-order-activity__toggle-left">
					<span class="eem-order-activity__title"><?php echo EEM_Dashboard_Icons::svg( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-authored inline SVG. ?> <?php esc_html_e( 'Activity Log', 'equine-event-manager' ); ?></span>
					<span class="eem-order-activity__count" data-eem-activity-count><?php
						echo esc_html( sprintf(
							/* translators: %s: number of activity log entries */
							_n( '%s entry', '%s entries', $count, 'equine-event-manager' ),
							number_format_i18n( $count )
						) );
					?></span>
				</div>
				<span class="eem-order-activity__chevron" aria-hidden="true">▾</span>
			</div>
			<div class="eem-order-activity__list" data-eem-activity-list>
				<?php
				// C2 partial. Entries already enriched; partial reuses
				// the .eem-activity-log-* CSS class taxonomy shipped in C2.4.
				if ( function_exists( 'eem_render_activity_log' ) ) {
					eem_render_activity_log( $entries, array(
						'empty_message' => __( 'No activity recorded yet for this order.', 'equine-event-manager' ),
					) );
				}
				?>
			</div>
			<?php $this->render_add_note_form( $order ); ?>
		</div>
		<?php
	}

	/**
	 * Add-Note form (C6.E.2) — single textarea + Submit button at the
	 * bottom of the Activity Log section. AJAX-submitted via the
	 * `add-note-submit` dispatch arm in admin.js. On success the AJAX
	 * handler returns pre-rendered entry HTML which JS prepends to the
	 * `[data-eem-activity-list]` mount node, bumps the count badge, and
	 * shows a toast confirmation.
	 *
	 * Validation contract — server is authoritative (cap, nonce, trim,
	 * maxlength 2000, order_key exists); client mirrors trim + maxlength
	 * + disable-on-empty/in-flight for UX. HTML maxlength="2000" attr
	 * provides browser-level enforcement on top.
	 *
	 * Decisions locked at C6.E parent kickoff:
	 *   Q1 position — (a) bottom of activity log section
	 *   Q2 fields   — (a) single textarea + Submit
	 *   Q3 valid    — three-layer (HTML maxlength + JS disable + server)
	 *   Q4 attrib   — "Admin note by {actor_label}" w/ "Admin note" fallback
	 *
	 * @param array<string, mixed> $order
	 * @return void
	 */
	private function render_add_note_form( array $order ) {
		$order_key = isset( $order['order_key'] ) ? (string) $order['order_key'] : '';
		if ( '' === $order_key ) {
			return;
		}
		?>
		<form class="eem-add-note-form" data-eem-add-note-form data-eem-order-key="<?php echo esc_attr( $order_key ); ?>">
			<?php wp_nonce_field( 'eem_order_add_note_' . $order_key, '_eem_add_note_nonce' ); ?>
			<input type="hidden" name="action" value="eem_order_add_note" />
			<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
			<label class="screen-reader-text" for="eem-add-note-textarea-<?php echo esc_attr( $order_key ); ?>"><?php esc_html_e( 'Add an internal note about this order', 'equine-event-manager' ); ?></label>
			<textarea
				class="eem-add-note-form__textarea"
				id="eem-add-note-textarea-<?php echo esc_attr( $order_key ); ?>"
				name="note"
				rows="3"
				maxlength="2000"
				placeholder="<?php esc_attr_e( 'Add an internal note about this order…', 'equine-event-manager' ); ?>"
				data-eem-add-note-textarea
			></textarea>
			<div class="eem-add-note-form__error" data-eem-add-note-error hidden></div>
			<div class="eem-add-note-form__actions">
				<button
					type="button"
					class="eem-btn eem-btn-primary"
					data-eem-action="add-note-submit"
					data-eem-add-note-submit
					disabled
				><?php esc_html_e( 'Add Note', 'equine-event-manager' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Derive type-key set from the order's `type` string. Returns the
	 * canonical display order: stall, rv, addon, group.
	 *
	 * @param array<string, mixed> $order
	 * @return string[]
	 */
	private function derive_type_keys( array $order ) {
		$type_str = isset( $order['type'] ) ? strtolower( (string) $order['type'] ) : '';
		$keys     = array();
		if ( false !== strpos( $type_str, 'stall' ) ) { $keys[] = 'stall'; }
		if ( false !== strpos( $type_str, 'rv' ) )    { $keys[] = 'rv'; }
		if ( false !== strpos( $type_str, 'add' ) )   { $keys[] = 'addon'; }
		if ( false !== strpos( $type_str, 'group' ) ) { $keys[] = 'group'; }
		return $keys;
	}

	/**
	 * Format an order number for header display ("#NNNNN") via the shared
	 * EEM_Orders_List_Page helper. Wrapper exists so callers don't have
	 * to remember which class owns the helper.
	 *
	 * @param array<string, mixed> $order
	 * @return string
	 */
	private static function format_order_number( array $order ) {
		$raw = isset( $order['order_number'] ) ? (string) $order['order_number'] : '';
		return EEM_Orders_List_Page::format_order_number_display( $raw );
	}

	/**
	 * Compute the night count between arrival and departure date strings.
	 *
	 * @param string $arrival   YYYY-MM-DD or empty.
	 * @param string $departure YYYY-MM-DD or empty.
	 * @return int  Nights (>= 0).
	 */
	private function compute_nights( $arrival, $departure ) {
		if ( '' === $arrival || '' === $departure ) {
			return 0;
		}
		$a = strtotime( $arrival );
		$d = strtotime( $departure );
		if ( ! $a || ! $d || $d <= $a ) {
			return 0;
		}
		return (int) round( ( $d - $a ) / DAY_IN_SECONDS );
	}

	/**
	 * Format a YYYY-MM-DD date to the mockup's "Friday, May 8, 2026" style.
	 *
	 * @param string $ymd
	 * @return string
	 */
	private function format_long_date( $ymd ) {
		$ts = '' === $ymd ? 0 : strtotime( $ymd );
		return $ts ? date_i18n( __( 'l, F j, Y', 'equine-event-manager' ), $ts ) : (string) $ymd;
	}

	/**
	 * Extract assigned stall unit labels from order components. Components
	 * carry `stall_units_csv` (legacy field). Returns comma-joined list
	 * with each unit prefixed by `#` per mockup, or empty when none.
	 *
	 * @param array<string, mixed> $order
	 * @return string
	 */
	private function extract_assigned_stalls( array $order ) {
		return $this->extract_assigned_units( $order, 'Assigned Stall Units' );
	}

	/**
	 * Comma-joined assigned RV lots for the order, parsed from the
	 * "Assigned RV Lots:" component-note line. '' when none assigned.
	 *
	 * @param array<string, mixed> $order
	 * @return string
	 */
	private function extract_assigned_rv_lots( array $order ) {
		return $this->extract_assigned_units( $order, 'Assigned RV Lots' );
	}

	/**
	 * Shared parser: pull a comma-separated unit list from the given
	 * component-note label (the canonical store the Stall/RV Chart assign flow
	 * writes), prefixing each with '#'. Returns '' when nothing is assigned.
	 *
	 * @param array<string, mixed> $order
	 * @param string               $note_label e.g. 'Assigned Stall Units' | 'Assigned RV Lots'
	 * @return string
	 */
	private function extract_assigned_units( array $order, string $note_label ): string {
		$units = array();
		if ( ! empty( $order['components'] ) && is_array( $order['components'] ) ) {
			$pattern = '/' . preg_quote( $note_label, '/' ) . ':\s*(.+)/i';
			foreach ( $order['components'] as $component ) {
				if ( ! is_array( $component ) || empty( $component['notes'] ) ) {
					continue;
				}
				if ( ! preg_match( $pattern, (string) $component['notes'], $m ) ) {
					continue;
				}
				foreach ( explode( ',', trim( $m[1] ) ) as $unit ) {
					$u = trim( $unit );
					if ( '' !== $u ) {
						$units[] = '#' . ltrim( $u, '#' );
					}
				}
			}
		}
		return implode( ', ', array_unique( $units ) );
	}
}
