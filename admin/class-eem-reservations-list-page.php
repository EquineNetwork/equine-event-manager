<?php
/**
 * Reservations list page controller (C4 — replaces WP-native
 * edit.php?post_type=en_reservation with a custom mockup-faithful page).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that lists reservations per the mockup at
 * .mockups/reservations_page.html.
 *
 * Architecture (per CLAUDE.md layout-shell verification step 4):
 *   Bordered list-card with five stacked internal sections divided by
 *   horizontal borders — status tabs → toolbar (#fafafa) → desktop
 *   table → mobile cards (hidden on desktop) → pagination footer
 *   (#fafafa). Sits below a `.eem-page-header` row (title + "+ New
 *   Reservation" CTA). Container chrome comes from the shared
 *   `.eem-page` shell (`templates/admin/_page_shell.php`).
 *
 * Departure from WordPress admin convention: this page does NOT extend
 * `WP_List_Table` — the mockup's chrome (custom status-tabs strip,
 * #fafafa toolbar with two filter groups, distinct mobile-card mode,
 * bespoke pagination footer) is too far from the WP-native list-table
 * defaults to be worth fighting the framework. Same Path B pattern we
 * used for the Settings page port in C3.
 *
 * C4 sub-chunks land here in order:
 *   C4.A — Scaffold + menu rewire + redirect-from-old-list (this file)
 *   C4.B — Full render body (status tabs / toolbar / table / footer / mobile)
 *   C4.C — Row actions + Email Customers modal
 *   C4.D — Bulk actions + sort / filter / pagination + smoke test
 */
class EEM_Reservations_List_Page {

	const MENU_SLUG = 'equine-event-manager-reservations';

	/**
	 * Build the admin URL for this page with optional query args
	 * (status tab, search, paged, etc.). Used by render() + by the
	 * old-list redirect.
	 *
	 * @param array<string, string|int> $args
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Localize the row-action nonces + admin-post URL so admin.js can
	 * dispatch admin_post submits without re-fetching nonces. Hooked
	 * to admin_enqueue_scripts; runs only when the eem-admin script is
	 * registered (i.e. on a Phase 3 page).
	 *
	 * @return void
	 */
	public static function localize_row_action_nonces() {
		if ( ! wp_script_is( 'eem-admin', 'registered' ) ) {
			return;
		}
		wp_localize_script( 'eem-admin', 'eemRowActions', array(
			'adminPostUrl' => admin_url( 'admin-post.php' ),
			'nonces'       => array(
				'eem_reservation_duplicate'     => wp_create_nonce( 'eem_reservation_duplicate' ),
				'eem_reservation_trash'         => wp_create_nonce( 'eem_reservation_trash' ),
				'eem_reservation_restore'       => wp_create_nonce( 'eem_reservation_restore' ),
				'eem_reservation_export_roster' => wp_create_nonce( 'eem_reservation_export_roster' ),
			),
		) );
	}

	/**
	 * Render the page. Renders the standalone page header + a bordered
	 * .eem-list-card containing status tabs, toolbar, desktop table,
	 * mobile cards, and pagination footer per the mockup at
	 * .mockups/reservations_page.html.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// Read query state. Filter handlers land in C4.D; for C4.B we
		// honour the params if present so the page round-trips on its
		// own ?status= / ?paged= links.
		$active_tab = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$tabs       = EEM_Reservations_List_Repo::status_tabs();
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'all';
		}
		$search      = isset( $_GET['s'] )        ? sanitize_text_field( wp_unslash( $_GET['s'] ) )    : '';
		$paged       = isset( $_GET['paged'] )    ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) )  : 1;
		$orderby     = isset( $_GET['orderby'] )  ? sanitize_key( wp_unslash( $_GET['orderby'] ) )   : 'event_dates';
		$order       = isset( $_GET['order'] )    ? sanitize_key( wp_unslash( $_GET['order'] ) )     : 'asc';
		$date_filter = isset( $_GET['eem_date'] ) ? sanitize_text_field( wp_unslash( $_GET['eem_date'] ) ) : '';

		// Validate date filter (yyyy-mm only).
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $date_filter ) ) {
			$date_filter = '';
		}

		$counts = EEM_Reservations_List_Repo::counts_by_tab();
		$page   = EEM_Reservations_List_Repo::get_paginated( array(
			'status'      => $active_tab,
			'search'      => $search,
			'orderby'     => $orderby,
			'order'       => $order,
			'paged'       => $paged,
			'per_page'    => 25,
			'date_filter' => $date_filter,
		) );

		eem_render_page_open(
			array(
				'title'      => __( 'Reservations', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Reservations', 'equine-event-manager' ) ),
				),
				'actions'    => sprintf(
					'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
					esc_url( admin_url( 'post-new.php?post_type=' . EEM_Reservations_List_Repo::POST_TYPE ) ),
					esc_html__( 'New Reservation', 'equine-event-manager' )
				),
				'wrap'       => false,
			)
		);

		?>
		<?php $this->render_action_notice(); ?>
		<div class="eem-list-card eem-reservations-list" data-eem-reservations-list>
			<?php $this->render_status_tabs( $active_tab, $counts ); ?>
			<?php $this->render_toolbar( $search, $date_filter, $page['total'], $active_tab ); ?>
			<?php $this->render_desktop_table( $page['items'], $orderby, $order, $active_tab ); ?>
			<?php $this->render_mobile_cards( $page['items'], $active_tab ); ?>
			<?php $this->render_table_footer( $page ); ?>
		</div>
		<?php $this->render_email_customers_modal(); ?>
		<?php

		eem_render_page_close( array( 'wrap' => false ) );
	}

	/**
	 * Inline notice rendered after admin_post handlers redirect back
	 * with ?eem_notice=… on the URL. Renders as a dismissible WP notice
	 * inline above the list card.
	 *
	 * @return void
	 */
	private function render_action_notice() {
		$code = isset( $_GET['eem_notice'] ) ? sanitize_key( wp_unslash( $_GET['eem_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$bulk_count = isset( $_GET['eem_bulk_count'] ) ? absint( wp_unslash( $_GET['eem_bulk_count'] ) ) : 0;
		$messages = array(
			'duplicated'            => array( 'type' => 'success', 'text' => __( 'Reservation duplicated as draft.', 'equine-event-manager' ) ),
			'trashed'               => array( 'type' => 'success', 'text' => __( 'Reservation moved to Trash.', 'equine-event-manager' ) ),
			'restored'              => array( 'type' => 'success', 'text' => __( 'Reservation restored from Trash.', 'equine-event-manager' ) ),
			'bulk_trashed'          => array( 'type' => 'success', 'text' => sprintf(
				/* translators: %d: number of reservations moved to trash */
				_n( '%d reservation moved to Trash.', '%d reservations moved to Trash.', $bulk_count, 'equine-event-manager' ),
				$bulk_count
			) ),
			'bulk_edit_unsupported' => array( 'type' => 'warning', 'text' => __( 'Bulk Edit is not available yet — it will land in a future release. Use the per-row Edit link for now.', 'equine-event-manager' ) ),
			'bulk_no_selection'     => array( 'type' => 'warning', 'text' => __( 'Pick at least one reservation before clicking Apply.', 'equine-event-manager' ) ),
			'bulk_no_action'        => array( 'type' => 'warning', 'text' => __( 'Pick a bulk action before clicking Apply.', 'equine-event-manager' ) ),
			'denied'                => array( 'type' => 'error',   'text' => __( 'You do not have permission to perform that action.', 'equine-event-manager' ) ),
			'notfound'              => array( 'type' => 'error',   'text' => __( 'Reservation not found.', 'equine-event-manager' ) ),
			'failed'                => array( 'type' => 'error',   'text' => __( 'Action failed. Check the WordPress error log for details.', 'equine-event-manager' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		$m = $messages[ $code ];
		printf(
			'<div class="notice notice-%1$s is-dismissible" style="margin-bottom:12px;"><p>%2$s</p></div>',
			esc_attr( $m['type'] ),
			esc_html( $m['text'] )
		);
	}

	/**
	 * Email Customers compose modal. Hidden by default; opened via
	 * data-eem-action="reservation-email-customers" on a meatballs item.
	 * JS populates the reservation id + recipient count before opening.
	 *
	 * NOTE: no mockup file depicts this modal — see CLEANUP.md entry #5.
	 * Designed against the C1.4 .eem-modal component + brand-guide tokens.
	 *
	 * @return void
	 */
	private function render_email_customers_modal() {
		?>
		<div class="eem-modal" id="eem-email-customers-modal" role="dialog" aria-modal="true" aria-labelledby="eem-email-customers-title" aria-hidden="true">
			<div class="eem-modal-card">
				<header class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-email-customers-title"><?php esc_html_e( 'Email Customers', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="email-customers-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</header>
				<form class="eem-modal-body" data-eem-email-customers-form>
					<input type="hidden" name="reservation_id" value="" />
					<?php wp_nonce_field( 'eem_email_customers', '_eem_email_customers_nonce' ); ?>
					<p class="eem-email-customers-summary" data-eem-recipient-summary>
						<?php esc_html_e( 'Recipients will load when the modal opens.', 'equine-event-manager' ); ?>
					</p>
					<div class="eem-field-row" style="margin-top:14px;">
						<label class="eem-field-label" for="eem-email-customers-subject"><?php esc_html_e( 'Subject', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<input class="eem-field-input" id="eem-email-customers-subject" type="text" name="subject" required maxlength="200" />
						</div>
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-email-customers-body"><?php esc_html_e( 'Message', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<textarea class="eem-field-textarea" id="eem-email-customers-body" name="body" rows="8" required></textarea>
							<p class="eem-field-hint"><?php esc_html_e( 'Plain text — line breaks are preserved. HTML is stripped before sending.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
				</form>
				<footer class="eem-modal-foot eem-modal-foot--split">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="email-customers-close"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="email-customers-send"><?php esc_html_e( 'Send to customers', 'equine-event-manager' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────────────────────────────────────────
	 * Row-action handlers (admin_post + AJAX). All verify nonce + cap
	 * + reservation_id existence before mutating; redirect back to the
	 * list with ?eem_notice=<code> for the inline notice renderer.
	 * Static so the loader can hook them without instantiating.
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Duplicate a reservation as a new draft. All post meta is copied;
	 * the title is suffixed with " (Copy)". Activity log entry written.
	 *
	 * @return void  Redirects + exits; never returns.
	 */
	public static function handle_duplicate() {
		$reservation_id = self::check_action_request( 'eem_reservation_duplicate' );

		$source = get_post( $reservation_id );
		if ( ! $source || EEM_Reservations_List_Repo::POST_TYPE !== $source->post_type ) {
			self::redirect_with_notice( 'notfound' );
		}

		$new_id = wp_insert_post( array(
			'post_type'   => EEM_Reservations_List_Repo::POST_TYPE,
			'post_status' => 'draft',
			'post_title'  => sprintf( '%s %s', $source->post_title, __( '(Copy)', 'equine-event-manager' ) ),
		), true );

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			self::redirect_with_notice( 'failed' );
		}

		// Copy all post meta verbatim — reservations are configuration
		// records, not customer-facing content, so a verbatim clone is
		// the expected behaviour (admin tweaks dates/title afterwards).
		$meta = get_post_meta( $reservation_id );
		foreach ( $meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		EEM_Activity_Log::write(
			'reservation_duplicated',
			array(
				'source_reservation_id' => $reservation_id,
				'new_reservation_id'    => $new_id,
			),
			array(
				'reservation_id' => $new_id,
				'actor_type'     => 'admin',
				'actor_id'       => get_current_user_id(),
			)
		);

		self::redirect_with_notice( 'duplicated' );
	}

	/**
	 * Move a reservation to Trash. Native wp_trash_post; activity log
	 * captures the lifecycle change.
	 *
	 * @return void  Redirects + exits.
	 */
	public static function handle_trash() {
		$reservation_id = self::check_action_request( 'eem_reservation_trash' );
		if ( ! self::trash_one( $reservation_id ) ) {
			self::redirect_with_notice( 'failed' );
		}
		self::redirect_with_notice( 'trashed', array( 'status' => 'trash' ) );
	}

	/**
	 * Reusable trash primitive — moves one reservation to Trash + writes
	 * the activity log entry. Used by both handle_trash (single-row
	 * action) and handle_bulk (bulk Move to Trash). Returns true on
	 * success so the caller can decide redirect / response shape.
	 *
	 * @param int $reservation_id
	 * @return bool
	 */
	private static function trash_one( $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		if ( $reservation_id <= 0 ) {
			return false;
		}
		if ( EEM_Reservations_List_Repo::POST_TYPE !== get_post_type( $reservation_id ) ) {
			return false;
		}
		$result = wp_trash_post( $reservation_id );
		if ( ! $result ) {
			return false;
		}
		EEM_Activity_Log::write(
			'reservation_trashed',
			array( 'reservation_id' => $reservation_id ),
			array(
				'reservation_id' => $reservation_id,
				'actor_type'     => 'admin',
				'actor_id'       => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Restore a reservation from Trash. wp_untrash_post restores the
	 * previous post_status (typically 'publish' or 'draft').
	 *
	 * @return void  Redirects + exits.
	 */
	public static function handle_restore() {
		$reservation_id = self::check_action_request( 'eem_reservation_restore' );

		$result = wp_untrash_post( $reservation_id );
		if ( ! $result ) {
			self::redirect_with_notice( 'failed' );
		}

		EEM_Activity_Log::write(
			'reservation_restored',
			array( 'reservation_id' => $reservation_id ),
			array(
				'reservation_id' => $reservation_id,
				'actor_type'     => 'admin',
				'actor_id'       => get_current_user_id(),
			)
		);

		self::redirect_with_notice( 'restored' );
	}

	/**
	 * Export a CSV roster for one reservation. Streams the CSV directly
	 * to the browser (Content-Disposition: attachment); does NOT use
	 * the eem_notice redirect path because the response body IS the CSV.
	 *
	 * Roster shape: one row per stall or RV order with customer name,
	 * email, phone, qty, dates, status, order number.
	 *
	 * @return void  Streams + exits.
	 */
	public static function handle_export_roster() {
		$reservation_id = self::check_action_request( 'eem_reservation_export_roster' );

		global $wpdb;
		$stall_table = $wpdb->prefix . 'en_stall_reservations';
		$rv_table    = $wpdb->prefix . 'en_rv_reservations';
		$needle      = '%Reservation setup ID: ' . $reservation_id . '%';

		// Same notes-based lookup the orders-count uses. C4.D / C11
		// may swap to a denormalized reservation_id column if perf
		// becomes a concern.
		$stall_orders = $wpdb->get_results( $wpdb->prepare(
			"SELECT 'Stall' AS kind, customer_name, email, phone, stall_qty AS qty, stay_type, arrival_date, departure_date, payment_status, order_number FROM `{$stall_table}` WHERE notes LIKE %s ORDER BY created_at DESC",
			$needle
		), ARRAY_A );
		$rv_orders = $wpdb->get_results( $wpdb->prepare(
			"SELECT 'RV' AS kind, customer_name, email, phone, rv_qty AS qty, stay_type, arrival_date, departure_date, payment_status, order_number FROM `{$rv_table}` WHERE notes LIKE %s ORDER BY created_at DESC",
			$needle
		), ARRAY_A );
		$rows = array_merge( (array) $stall_orders, (array) $rv_orders );

		$slug     = sanitize_title( get_the_title( $reservation_id ) );
		$filename = sprintf( 'eem-roster-%s-%s.csv', $slug !== '' ? $slug : (string) $reservation_id, gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, array( 'Kind', 'Customer', 'Email', 'Phone', 'Qty', 'Stay Type', 'Arrival', 'Departure', 'Payment Status', 'Order #' ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array(
				$r['kind'],
				$r['customer_name'],
				$r['email'],
				$r['phone'],
				$r['qty'],
				$r['stay_type'],
				$r['arrival_date'],
				$r['departure_date'],
				$r['payment_status'],
				$r['order_number'],
			) );
		}
		fclose( $fh );
		exit;
	}

	/**
	 * Bulk-action dispatcher. Handles the toolbar's bulk-action <select>
	 * + Apply form. Supported actions:
	 *   - 'edit'  → redirects back with eem_notice=bulk_edit_unsupported;
	 *               WP-native bulk edit is intentionally not wired (the
	 *               new Phase 3 page can't reuse WP_List_Table's bulk
	 *               edit UI without bringing back the legacy chrome).
	 *               When this is needed it'll get its own modal in a
	 *               future chunk.
	 *   - 'trash' → loops through selected ids, calls trash_one() on
	 *               each, redirects with a count summary.
	 *
	 * @return void  Exits.
	 */
	public static function handle_bulk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_notice( 'denied' );
		}
		check_admin_referer( 'eem_reservations_bulk', '_eem_bulk_nonce' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$status = isset( $_POST['status'] )      ? sanitize_key( wp_unslash( $_POST['status'] ) )      : 'all';
		$raw    = isset( $_POST['_eem_selected_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['_eem_selected_ids'] ) ) : '';
		$ids    = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		if ( empty( $ids ) ) {
			self::redirect_with_notice( 'bulk_no_selection', array( 'status' => $status ) );
		}

		switch ( $action ) {
			case 'trash':
				$count = 0;
				foreach ( $ids as $id ) {
					if ( self::trash_one( $id ) ) {
						$count++;
					}
				}
				self::redirect_with_notice(
					$count > 0 ? 'bulk_trashed' : 'failed',
					array( 'status' => 'trash', 'eem_bulk_count' => $count )
				);
				break;

			case 'edit':
				self::redirect_with_notice( 'bulk_edit_unsupported', array( 'status' => $status ) );
				break;

			default:
				self::redirect_with_notice( 'bulk_no_action', array( 'status' => $status ) );
				break;
		}
	}

	/**
	 * AJAX handler — send the compose-modal email to every customer
	 * with an order against the reservation. Returns JSON with the
	 * recipient count + delivery summary.
	 *
	 * @return void  wp_send_json_*; never returns.
	 */
	public static function handle_email_customers_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_email_customers', '_eem_email_customers_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$subject        = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body           = isset( $_POST['body'] )    ? wp_strip_all_tags( wp_unslash( $_POST['body'] ) )    : '';

		if ( $reservation_id <= 0 || '' === $subject || '' === $body ) {
			wp_send_json_error( array( 'message' => __( 'Subject and message are required.', 'equine-event-manager' ) ), 400 );
		}

		$recipients = self::resolve_recipients_for_reservation( $reservation_id );
		if ( empty( $recipients ) ) {
			wp_send_json_error( array( 'message' => __( 'No customers found for this reservation.', 'equine-event-manager' ) ), 404 );
		}

		$sender = class_exists( 'EEM_Settings_Repo' ) ? EEM_Settings_Repo::get_email_sender() : array();
		$headers = array();
		if ( ! empty( $sender['from_name'] ) && ! empty( $sender['from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $sender['from_name'], $sender['from_email'] );
		}
		if ( ! empty( $sender['reply_to'] ) ) {
			$headers[] = sprintf( 'Reply-To: %s', $sender['reply_to'] );
		}

		$sent   = 0;
		$failed = 0;
		foreach ( $recipients as $email ) {
			if ( wp_mail( $email, $subject, $body, $headers ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		EEM_Activity_Log::write(
			EEM_Activity_Log::NOTIFICATION_SENT,
			array(
				'channel'         => 'bulk_email_customers',
				'recipient_count' => count( $recipients ),
				'sent'            => $sent,
				'failed'          => $failed,
				'subject'         => $subject,
			),
			array(
				'reservation_id' => $reservation_id,
				'actor_type'     => 'admin',
				'actor_id'       => get_current_user_id(),
			)
		);

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: sent count, 2: total recipient count */
				_n( 'Sent to %1$d of %2$d recipient.', 'Sent to %1$d of %2$d recipients.', count( $recipients ), 'equine-event-manager' ),
				$sent,
				count( $recipients )
			),
			'sent'    => $sent,
			'failed'  => $failed,
		) );
	}

	/**
	 * AJAX recipient-count peek so the modal can show the count before
	 * the user composes. Cheap (one query per table).
	 *
	 * @return void
	 */
	public static function handle_email_customers_count_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_email_customers', '_eem_email_customers_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		if ( $reservation_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Reservation id required.', 'equine-event-manager' ) ), 400 );
		}

		$recipients = self::resolve_recipients_for_reservation( $reservation_id );
		wp_send_json_success( array( 'count' => count( $recipients ) ) );
	}

	/**
	 * Distinct customer emails for a reservation across both stall + RV
	 * orders. De-duplicated by lowercase email.
	 *
	 * @param int $reservation_id
	 * @return string[]
	 */
	private static function resolve_recipients_for_reservation( $reservation_id ) {
		global $wpdb;
		$stall_table = $wpdb->prefix . 'en_stall_reservations';
		$rv_table    = $wpdb->prefix . 'en_rv_reservations';
		$needle      = '%Reservation setup ID: ' . absint( $reservation_id ) . '%';

		$stall_emails = (array) $wpdb->get_col( $wpdb->prepare( "SELECT email FROM `{$stall_table}` WHERE notes LIKE %s AND email <> ''", $needle ) );
		$rv_emails    = (array) $wpdb->get_col( $wpdb->prepare( "SELECT email FROM `{$rv_table}` WHERE notes LIKE %s AND email <> ''", $needle ) );

		$all = array_merge( $stall_emails, $rv_emails );
		$dedupe = array();
		foreach ( $all as $e ) {
			$lower = strtolower( trim( (string) $e ) );
			if ( '' !== $lower && is_email( $lower ) ) {
				$dedupe[ $lower ] = $e;
			}
		}
		return array_values( $dedupe );
	}

	/**
	 * Shared front-door for the admin_post handlers — verifies nonce
	 * + cap + reservation_id presence, exits/redirects on failure,
	 * returns the validated reservation id on success.
	 *
	 * @param string $action_name  Nonce action name (e.g. 'eem_reservation_trash').
	 * @return int                 Validated reservation id.
	 */
	private static function check_action_request( $action_name ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_notice( 'denied' );
		}
		check_admin_referer( $action_name, '_eem_action_nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		if ( $reservation_id <= 0 ) {
			self::redirect_with_notice( 'notfound' );
		}
		if ( EEM_Reservations_List_Repo::POST_TYPE !== get_post_type( $reservation_id ) ) {
			self::redirect_with_notice( 'notfound' );
		}

		return $reservation_id;
	}

	/**
	 * Redirect back to the list with a notice code. Optional extra
	 * query args (e.g. ['status' => 'trash']) get forwarded so the
	 * destination tab makes sense for the action just taken.
	 *
	 * @param string $code
	 * @param array<string, string|int> $extra
	 * @return void  Exits.
	 */
	private static function redirect_with_notice( $code, array $extra = array() ) {
		wp_safe_redirect( self::url( array_merge( array( 'eem_notice' => $code ), $extra ) ) );
		exit;
	}

	/**
	 * Status tabs strip at top of the list card (All / Published / Draft
	 * / Trash) with per-tab counts. Mockup lines 244–252.
	 *
	 * @param string             $active_tab
	 * @param array<string, int> $counts
	 * @return void
	 */
	private function render_status_tabs( $active_tab, array $counts ) {
		$tabs = array(
			'all'     => EEM_Reservations_List_Repo::tab_label( 'all' ),
			'publish' => EEM_Reservations_List_Repo::tab_label( 'publish' ),
			'draft'   => EEM_Reservations_List_Repo::tab_label( 'draft' ),
			'trash'   => EEM_Reservations_List_Repo::tab_label( 'trash' ),
		);
		?>
		<nav class="eem-status-tabs" aria-label="<?php esc_attr_e( 'Filter by status', 'equine-event-manager' ); ?>">
			<?php
			$first = true;
			foreach ( $tabs as $id => $label ) :
				$is_active = ( $id === $active_tab );
				$count     = isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0;
				?>
				<?php if ( ! $first ) : ?><span class="eem-status-tab-sep" aria-hidden="true">|</span><?php endif; ?>
				<a class="eem-status-tab<?php echo $is_active ? ' is-active' : ''; ?>"
				   href="<?php echo esc_url( self::url( array( 'status' => $id ) ) ); ?>"
				   <?php echo $is_active ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
					<span class="eem-status-tab-count">(<?php echo esc_html( number_format_i18n( $count ) ); ?>)</span>
				</a>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Toolbar — bulk actions select + Apply, date filter + Filter,
	 * search box + Search button + item count. Mockup lines 254–270.
	 *
	 * Form structure:
	 *   - Bulk-action form wraps the bulk select + Apply. Posts to
	 *     admin-post.php?action=eem_reservations_bulk so the existing
	 *     redirect-with-notice pattern handles the response.
	 *   - Date-filter form wraps the date select + Filter button.
	 *     GET ?eem_date=…&page=… reload to apply the filter.
	 *   - Search form wraps the search input + Search Reservations
	 *     button. Both Enter-in-input AND click-on-button submit.
	 *   - .eem-item-count stays outside any form (semantic — it's
	 *     a display element, not a form control).
	 *
	 * @param string $search      Current search term (echoed back into the input).
	 * @param string $date_filter Current date filter (yyyy-mm) or ''.
	 * @param int    $total       Total matching items (for the "N items" pill).
	 * @param string $active_tab  Active status tab (forwarded as a hidden field
	 *                            so search/filter stays on the same tab).
	 * @return void
	 */
	private function render_toolbar( $search, $date_filter, $total, $active_tab ) {
		$date_options = $this->get_date_filter_options();
		?>
		<div class="eem-list-toolbar">
			<div class="eem-list-toolbar-left">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eem-bulk-form">
					<input type="hidden" name="action" value="eem_reservations_bulk" />
					<input type="hidden" name="status" value="<?php echo esc_attr( $active_tab ); ?>" />
					<?php wp_nonce_field( 'eem_reservations_bulk', '_eem_bulk_nonce' ); ?>
					<input type="hidden" name="_eem_selected_ids" data-eem-bulk-selected-ids value="" />
					<select class="eem-toolbar-select" name="bulk_action" data-eem-bulk-action>
						<option value=""><?php esc_html_e( 'Bulk actions', 'equine-event-manager' ); ?></option>
						<option value="edit"><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></option>
						<option value="trash"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></option>
					</select>
					<button type="submit" class="eem-toolbar-btn" data-eem-action="bulk-apply"><?php esc_html_e( 'Apply', 'equine-event-manager' ); ?></button>
				</form>
				<form method="get" class="eem-date-filter-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="status" value="<?php echo esc_attr( $active_tab ); ?>" />
					<?php if ( '' !== $search ) : ?>
						<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<?php endif; ?>
					<select class="eem-toolbar-select" name="eem_date">
						<option value=""><?php esc_html_e( 'All dates', 'equine-event-manager' ); ?></option>
						<?php foreach ( $date_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $date_filter, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="eem-toolbar-btn"><?php esc_html_e( 'Filter', 'equine-event-manager' ); ?></button>
				</form>
			</div>
			<div class="eem-list-toolbar-right">
				<form method="get" class="eem-search-form" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="status" value="<?php echo esc_attr( $active_tab ); ?>" />
					<?php if ( '' !== $date_filter ) : ?>
						<input type="hidden" name="eem_date" value="<?php echo esc_attr( $date_filter ); ?>" />
					<?php endif; ?>
					<div class="eem-search-wrap">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input class="eem-search-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search reservations…', 'equine-event-manager' ); ?>" />
					</div>
					<button type="submit" class="eem-toolbar-btn"><?php esc_html_e( 'Search Reservations', 'equine-event-manager' ); ?></button>
				</form>
				<span class="eem-item-count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: total item count (already number_format_i18n'd) */
							_n( '%s item', '%s items', $total, 'equine-event-manager' ),
							number_format_i18n( $total )
						)
					);
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Build the date-filter dropdown options — every month that has at
	 * least one reservation falling within it (per nightly start/end).
	 *
	 * @return array<string, string>  yyyy-mm => "Month YYYY" label.
	 */
	private function get_date_filter_options() {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value <> ''
			 ORDER BY meta_value DESC",
			'_en_nightly_start_date'
		) );
		$months = array();
		foreach ( (array) $rows as $date_string ) {
			$ts = strtotime( (string) $date_string );
			if ( ! $ts ) {
				continue;
			}
			$key = gmdate( 'Y-m', $ts );
			if ( ! isset( $months[ $key ] ) ) {
				$months[ $key ] = date_i18n( 'F Y', $ts );
			}
		}
		return $months;
	}

	/**
	 * Desktop table — checkbox / Reservation / Event Dates / Type /
	 * Status / Orders / Actions columns. Sortable headers send the user
	 * to ?orderby=…&order=…. C4.D wires real sort handling; C4.B emits
	 * the header links so the markup is forward-compatible.
	 *
	 * @param WP_Post[] $items
	 * @param string    $orderby
	 * @param string    $order
	 * @return void
	 */
	private function render_desktop_table( array $items, $orderby, $order, $active_tab = 'all' ) {
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead>
					<tr>
						<th class="eem-col-cb"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'equine-event-manager' ); ?>" /></th>
						<?php $this->render_sortable_th( 'title',       __( 'Reservation', 'equine-event-manager' ), $orderby, $order ); ?>
						<?php $this->render_sortable_th( 'event_dates', __( 'Event Dates', 'equine-event-manager' ), $orderby, $order ); ?>
						<th><?php esc_html_e( 'Type', 'equine-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'equine-event-manager' ); ?></th>
						<?php $this->render_sortable_th( 'orders',      __( 'Orders',     'equine-event-manager' ), $orderby, $order ); ?>
						<th><?php esc_html_e( 'Actions', 'equine-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="7" class="eem-table-empty"><?php esc_html_e( 'No reservations match the current filters.', 'equine-event-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $post ) : ?>
							<?php $this->render_table_row( $post, $active_tab ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One sortable column header. Renders the column label + a sort-icon
	 * stack; clicking flips order. Active sort column gets is-sorted.
	 *
	 * @param string $key            Sort key ('title' | 'event_dates' | 'orders').
	 * @param string $label
	 * @param string $current_orderby
	 * @param string $current_order
	 * @return void
	 */
	private function render_sortable_th( $key, $label, $current_orderby, $current_order ) {
		$is_active = ( $key === $current_orderby );
		$next_order = ( $is_active && 'asc' === $current_order ) ? 'desc' : 'asc';
		$href = self::url( array(
			'orderby' => $key,
			'order'   => $next_order,
		) );
		$classes = 'sortable' . ( $is_active ? ' is-sorted is-sorted--' . $current_order : '' );
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
	 * One desktop table row. Renders all 7 columns including the
	 * conditional Stall Chart icon and the meatballs dropdown.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	private function render_table_row( $post, $active_tab = 'all' ) {
		$id           = (int) $post->ID;
		$edit_url     = get_edit_post_link( $id );
		$dates        = EEM_Reservations_List_Repo::get_event_date_range_label( $id );
		$badges       = EEM_Reservations_List_Repo::get_type_badges( $id );
		$orders_count = EEM_Reservations_List_Repo::get_orders_count_for_reservation( $id );
		$has_stalls   = in_array( 'stall', $badges, true );
		$status_id    = $this->derive_status_id( $post );
		$status_label = $this->status_label_for( $status_id );
		$is_trashed   = ( 'trashed' === $status_id );
		?>
		<tr data-reservation-id="<?php echo esc_attr( $id ); ?>">
			<td class="eem-col-cb"><input type="checkbox" name="reservation_ids[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'equine-event-manager' ), get_the_title( $post ) ) ); ?>" /></td>
			<td><a class="eem-res-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
			<td><span class="eem-event-dates"><?php echo esc_html( $dates !== '' ? $dates : '—' ); ?></span></td>
			<td><?php $this->render_type_badges( $badges ); ?></td>
			<td><span class="eem-res-status eem-res-status--<?php echo esc_attr( $status_id ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
			<td><span class="eem-orders-count<?php echo $orders_count === 0 ? ' is-zero' : ''; ?>"><?php echo esc_html( number_format_i18n( $orders_count ) ); ?></span></td>
			<td><?php $this->render_row_actions( $id, $has_stalls, $is_trashed ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Type badge set inside a row (Stall / RV / Add-On / Group).
	 *
	 * @param string[] $badges
	 * @return void
	 */
	private function render_type_badges( array $badges ) {
		$labels = array(
			'stall' => __( 'Stall',  'equine-event-manager' ),
			'rv'    => __( 'RV',     'equine-event-manager' ),
			'addon' => __( 'Add-On', 'equine-event-manager' ),
			'group' => __( 'Group',  'equine-event-manager' ),
		);
		if ( empty( $badges ) ) {
			echo '<span class="eem-type-badges-empty">—</span>';
			return;
		}
		echo '<div class="eem-type-badges">';
		foreach ( $badges as $b ) {
			if ( ! isset( $labels[ $b ] ) ) {
				continue;
			}
			printf(
				'<span class="eem-type-badge eem-type-badge--%1$s">%2$s</span>',
				esc_attr( $b ),
				esc_html( $labels[ $b ] )
			);
		}
		echo '</div>';
	}

	/**
	 * Row actions cell — conditional Stall Chart icon + meatballs menu.
	 * Meatballs items render as buttons whose data-eem-action handlers
	 * land in C4.C; the dropdown toggle reuses the C1.5 generic
	 * dropdown-toggle delegated handler.
	 *
	 * @param int  $reservation_id
	 * @param bool $has_stalls
	 * @return void
	 */
	private function render_row_actions( $reservation_id, $has_stalls, $is_trashed = false ) {
		$stall_chart_url = add_query_arg(
			array(
				'page'           => 'equine-event-manager-stall-chart',
				'reservation_id' => $reservation_id,
			),
			admin_url( 'admin.php' )
		);
		$front_end_url   = get_permalink( $reservation_id );
		$orders_url      = add_query_arg(
			array(
				'page'           => 'equine-event-manager-orders',
				'reservation_id' => $reservation_id,
			),
			admin_url( 'admin.php' )
		);
		$menu_id = 'eem-res-menu-' . $reservation_id;
		?>
		<div class="eem-actions-cell">
			<?php if ( $has_stalls ) : ?>
				<a class="eem-action-icon-btn eem-action-icon-btn--stall-chart" href="<?php echo esc_url( $stall_chart_url ); ?>" title="<?php esc_attr_e( 'Stall Chart', 'equine-event-manager' ); ?>" aria-label="<?php esc_attr_e( 'Stall Chart', 'equine-event-manager' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
				</a>
			<?php endif; ?>
			<div class="eem-row-menu-wrap">
				<button type="button" class="eem-more-btn" data-eem-action="dropdown-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="<?php echo esc_attr( $menu_id ); ?>" title="<?php esc_attr_e( 'More actions', 'equine-event-manager' ); ?>">···</button>
				<div class="eem-row-dropdown" id="<?php echo esc_attr( $menu_id ); ?>" role="menu">
					<?php if ( $front_end_url ) : ?>
						<a class="eem-row-dd-item" href="<?php echo esc_url( $front_end_url ); ?>" target="_blank" rel="noopener" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
							<?php esc_html_e( 'View on Front-End', 'equine-event-manager' ); ?>
						</a>
					<?php endif; ?>
					<a class="eem-row-dd-item" href="<?php echo esc_url( $orders_url ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg>
						<?php esc_html_e( 'View Orders', 'equine-event-manager' ); ?>
					</a>
					<button type="button" class="eem-row-dd-item" data-eem-action="reservation-duplicate" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
						<?php esc_html_e( 'Duplicate', 'equine-event-manager' ); ?>
					</button>
					<button type="button" class="eem-row-dd-item" data-eem-action="reservation-export-roster" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export Roster (CSV)', 'equine-event-manager' ); ?>
					</button>
					<button type="button" class="eem-row-dd-item" data-eem-action="reservation-email-customers" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
						<?php esc_html_e( 'Email Customers', 'equine-event-manager' ); ?>
					</button>
					<?php if ( $is_trashed ) : ?>
						<button type="button" class="eem-row-dd-item" data-eem-action="reservation-restore" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
							<?php esc_html_e( 'Restore', 'equine-event-manager' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="eem-row-dd-item eem-row-dd-danger" data-eem-action="reservation-trash" data-reservation-id="<?php echo esc_attr( $reservation_id ); ?>" role="menuitem">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
							<?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Mobile cards view — rendered alongside the desktop table, hidden
	 * on desktop via CSS and shown at <768px. Each card carries the same
	 * meatballs menu as the desktop row.
	 *
	 * @param WP_Post[] $items
	 * @return void
	 */
	private function render_mobile_cards( array $items, $active_tab = 'all' ) {
		?>
		<div class="eem-mobile-reservations">
			<?php foreach ( $items as $post ) :
				$id           = (int) $post->ID;
				$edit_url     = get_edit_post_link( $id );
				$dates        = EEM_Reservations_List_Repo::get_event_date_range_label( $id );
				$badges       = EEM_Reservations_List_Repo::get_type_badges( $id );
				$orders_count = EEM_Reservations_List_Repo::get_orders_count_for_reservation( $id );
				$has_stalls   = in_array( 'stall', $badges, true );
				$status_id    = $this->derive_status_id( $post );
				$status_label = $this->status_label_for( $status_id );
				$is_trashed   = ( 'trashed' === $status_id );
				?>
				<div class="eem-mobile-res-card">
					<a class="eem-mob-res-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
					<div class="eem-mob-res-dates"><?php echo esc_html( $dates !== '' ? $dates : '—' ); ?></div>
					<div class="eem-mob-res-bottom">
						<div class="eem-mob-res-badges">
							<?php $this->render_type_badges( $badges ); ?>
							<span class="eem-res-status eem-res-status--<?php echo esc_attr( $status_id ); ?>"><?php echo esc_html( $status_label ); ?></span>
							<span class="eem-orders-count<?php echo $orders_count === 0 ? ' is-zero' : ''; ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: order count (already number_format_i18n'd) */
										_n( '%s order', '%s orders', $orders_count, 'equine-event-manager' ),
										number_format_i18n( $orders_count )
									)
								);
								?>
							</span>
						</div>
						<div class="eem-mob-res-actions">
							<?php $this->render_row_actions( $id, $has_stalls, $is_trashed ); ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Table footer with pagination + "showing N–M of T" info. Mockup
	 * lines 468–477.
	 *
	 * @param array{ items: WP_Post[], total: int, total_pages: int, page: int, per_page: int } $page
	 * @return void
	 */
	private function render_table_footer( array $page ) {
		$total       = (int) $page['total'];
		$current     = (int) $page['page'];
		$total_pages = max( 1, (int) $page['total_pages'] );
		$per_page    = (int) $page['per_page'];

		$start = $total > 0 ? ( ( $current - 1 ) * $per_page ) + 1 : 0;
		$end   = min( $start + $per_page - 1, $total );

		$prev_url = $current > 1 ? self::url( array( 'paged' => $current - 1 ) ) : '';
		$next_url = $current < $total_pages ? self::url( array( 'paged' => $current + 1 ) ) : '';
		?>
		<div class="eem-table-footer">
			<span class="eem-table-footer-info">
				<?php
				if ( $total > 0 ) {
					echo esc_html( sprintf(
						/* translators: 1: first item, 2: last item, 3: total */
						__( 'Showing %1$s–%2$s of %3$s reservations', 'equine-event-manager' ),
						number_format_i18n( $start ),
						number_format_i18n( $end ),
						number_format_i18n( $total )
					) );
				} else {
					esc_html_e( 'No reservations to display.', 'equine-event-manager' );
				}
				?>
			</span>
			<?php if ( $total_pages > 1 ) : ?>
				<nav class="eem-pagination" aria-label="<?php esc_attr_e( 'Reservations pagination', 'equine-event-manager' ); ?>">
					<?php if ( $prev_url ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( $prev_url ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'equine-event-manager' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
						</a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></span>
					<?php endif; ?>

					<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
						<?php if ( $i === $current ) : ?>
							<span class="eem-page-btn active" aria-current="page"><?php echo esc_html( number_format_i18n( $i ) ); ?></span>
						<?php else : ?>
							<a class="eem-page-btn" href="<?php echo esc_url( self::url( array( 'paged' => $i ) ) ); ?>"><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>

					<?php if ( $next_url ) : ?>
						<a class="eem-page-btn" href="<?php echo esc_url( $next_url ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'equine-event-manager' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
						</a>
					<?php else : ?>
						<span class="eem-page-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></span>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Map a WP post_status to our lifecycle id (active/draft/archived/trashed).
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	private function derive_status_id( $post ) {
		switch ( $post->post_status ) {
			case 'publish': return 'active';
			case 'draft':   return 'draft';
			case 'private': return 'archived';
			case 'trash':   return 'trashed';
			default:        return 'draft';
		}
	}

	/**
	 * Friendly UI label for a lifecycle id.
	 *
	 * @param string $status_id
	 * @return string
	 */
	private function status_label_for( $status_id ) {
		switch ( $status_id ) {
			case 'active':   return __( 'Active',   'equine-event-manager' );
			case 'draft':    return __( 'Draft',    'equine-event-manager' );
			case 'archived': return __( 'Archived', 'equine-event-manager' );
			case 'trashed':  return __( 'Trashed',  'equine-event-manager' );
			default:         return __( 'Unknown',  'equine-event-manager' );
		}
	}

	/**
	 * Redirect requests for the old WP-native CPT list at
	 * `edit.php?post_type=en_reservation` to the new custom page. Wired
	 * to `current_screen` so the redirect fires before WP renders the
	 * legacy list-table chrome.
	 *
	 * Direct hits on a single en_reservation edit screen pass through
	 * untouched — only the list view is intercepted.
	 *
	 * @param WP_Screen $screen
	 * @return void
	 */
	public static function maybe_redirect_old_list( $screen ) {
		if ( ! is_object( $screen ) ) {
			return;
		}
		if ( 'edit-' . EEM_Reservations_List_Repo::POST_TYPE !== $screen->id ) {
			return;
		}
		if ( 'edit' !== $screen->base ) {
			return;
		}

		// Preserve any passthrough query args we want forwarded to the
		// new page — currently just the status tab if WP supplied one.
		$forward = array();
		if ( isset( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tabs   = EEM_Reservations_List_Repo::status_tabs();
			if ( isset( $tabs[ $status ] ) ) {
				$forward['status'] = $status;
			}
		}

		wp_safe_redirect( self::url( $forward ), 302 );
		exit;
	}
}
