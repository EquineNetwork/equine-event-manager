<?php
/**
 * Notifications admin page (v2 Notifications, Slice 2).
 *
 * Event Manager → Notifications. Pick an event, build an audience
 * (Include − Exclude + Payment), see a live recipient count, compose, and send.
 * Recipient resolution + segment math live in {@see EEM_Notifications} (Slice 1);
 * the batched send pipeline + history land in Slice 3.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + wires the Notifications page.
 */
class EEM_Notifications_Page {

	/** @var string Visible submenu slug under the Event Manager parent. */
	const MENU_SLUG = 'equine-event-manager-notifications';

	/**
	 * Bootstrap: register the menu route (admin_menu @30 — after the parent
	 * menu exists @20, before the submenu re-order @1001) + AJAX handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_route' ), 30 );
		add_action( 'wp_ajax_eem_notifications_event_meta', array( __CLASS__, 'ajax_event_meta' ) );
		add_action( 'wp_ajax_eem_notifications_count', array( __CLASS__, 'ajax_count' ) );
	}

	/**
	 * Add the visible "Notifications" submenu under Event Manager.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		add_submenu_page(
			'equine-event-manager',
			__( 'Notifications', 'equine-event-manager' ),
			__( 'Notifications', 'equine-event-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Events available to notify — published reservations that have at least one
	 * order, newest first. Returns [reservation_id => label].
	 *
	 * @return array<int,string>
	 */
	private static function notifiable_events(): array {
		$events = array();
		$posts  = get_posts( array(
			'post_type'      => EEM_Reservations_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		foreach ( $posts as $p ) {
			$events[ (int) $p->ID ] = get_the_title( $p );
		}
		return $events;
	}

	/**
	 * Render the Notifications page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		$events   = self::notifiable_events();
		$segments = EEM_Notifications::segment_options();
		$nonce    = wp_create_nonce( 'eem_notifications' );

		eem_render_page_open( array(
			'title'      => __( 'Notifications', 'equine-event-manager' ),
			'subtitle'   => __( 'Email customers for an event. Pick the event, build an audience, then compose and send.', 'equine-event-manager' ),
			'breadcrumb' => array( array( 'label' => __( 'Notifications', 'equine-event-manager' ) ) ),
			'wrap'       => true,
		) );
		?>
		<div class="eem-notifications" data-eem-notifications data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( empty( $events ) ) : ?>
				<p class="eem-table-empty"><?php esc_html_e( 'No events yet. Create a reservation first.', 'equine-event-manager' ); ?></p>
			<?php else : ?>
				<div class="eem-notif-grid">
					<div class="eem-notif-field">
						<label class="eem-field-label" for="eem-notif-event"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></label>
						<select class="eem-field-select" id="eem-notif-event" data-eem-notif-event data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>">
							<option value=""><?php esc_html_e( 'Select an event…', 'equine-event-manager' ); ?></option>
							<?php foreach ( $events as $eid => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $eid ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="eem-notif-audience" data-eem-notif-audience hidden>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-include"><?php esc_html_e( 'Send to', 'equine-event-manager' ); ?></label>
							<select class="eem-field-select" id="eem-notif-include" data-eem-notif-include>
								<?php foreach ( $segments as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-exclude"><?php esc_html_e( 'But not', 'equine-event-manager' ); ?></label>
							<select class="eem-field-select" id="eem-notif-exclude" data-eem-notif-exclude>
								<option value=""><?php esc_html_e( '— no exclusion —', 'equine-event-manager' ); ?></option>
								<?php foreach ( $segments as $key => $label ) : ?>
									<?php if ( 'all' === $key ) { continue; } ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-payment"><?php esc_html_e( 'Payment', 'equine-event-manager' ); ?></label>
							<select class="eem-field-select" id="eem-notif-payment" data-eem-notif-payment>
								<option value="all"><?php esc_html_e( 'Any', 'equine-event-manager' ); ?></option>
								<option value="paid"><?php esc_html_e( 'Paid only', 'equine-event-manager' ); ?></option>
								<option value="unpaid"><?php esc_html_e( 'Unpaid only', 'equine-event-manager' ); ?></option>
							</select>
						</div>
						<p class="eem-notif-count" data-eem-notif-count>
							<span class="eem-status-badge eem-status-active" data-eem-notif-count-badge>0</span>
							<span data-eem-notif-count-label><?php esc_html_e( 'recipients', 'equine-event-manager' ); ?></span>
						</p>
					</div>

					<div class="eem-notif-compose" data-eem-notif-compose hidden>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-subject"><?php esc_html_e( 'Subject', 'equine-event-manager' ); ?></label>
							<input class="eem-field-input" id="eem-notif-subject" type="text" maxlength="200" data-eem-notif-subject />
						</div>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-body"><?php esc_html_e( 'Message', 'equine-event-manager' ); ?></label>
							<textarea class="eem-field-input eem-field-textarea" id="eem-notif-body" rows="9" data-eem-notif-body></textarea>
						</div>
						<div class="eem-notif-actions">
							<button type="button" class="eem-btn eem-btn-electric" data-eem-action="notifications-send"><?php esc_html_e( 'Send Notification', 'equine-event-manager' ); ?></button>
							<span class="eem-notif-send-status" data-eem-notif-status></span>
						</div>
					</div>
				</div>

				<div class="eem-notif-history" data-eem-notif-history>
					<h2 class="eem-notif-history-title"><?php esc_html_e( 'Recent notifications', 'equine-event-manager' ); ?></h2>
					<?php self::render_history(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		eem_render_page_close( array( 'wrap' => true ) );
	}

	/**
	 * Render the recent-notifications history table (Slice 3 fills the data;
	 * Slice 2 ships the container + empty state).
	 *
	 * @return void
	 */
	private static function render_history(): void {
		echo '<p class="eem-table-empty">' . esc_html__( 'No notifications sent yet.', 'equine-event-manager' ) . '</p>';
	}

	/**
	 * AJAX: event meta — divisions (for the Include/Exclude dropdowns) + the
	 * baseline "all customers" recipient count for the picked event.
	 *
	 * @return void
	 */
	public static function ajax_event_meta(): void {
		self::guard();
		$rid       = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$divisions = array();
		foreach ( EEM_Notifications::event_divisions( $rid ) as $did => $name ) {
			$divisions[] = array( 'value' => 'division:' . $did, 'label' => $name );
		}
		wp_send_json_success( array(
			'divisions' => $divisions,
			'count'     => EEM_Notifications::count( $rid, 'all' ),
		) );
	}

	/**
	 * AJAX: live recipient count for the current audience selection.
	 *
	 * @return void
	 */
	public static function ajax_count(): void {
		self::guard();
		$rid     = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$include = isset( $_POST['include'] ) ? sanitize_text_field( wp_unslash( $_POST['include'] ) ) : 'all';
		$exclude = isset( $_POST['exclude'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude'] ) ) : '';
		$payment = isset( $_POST['payment'] ) ? sanitize_key( wp_unslash( $_POST['payment'] ) ) : 'all';
		wp_send_json_success( array( 'count' => EEM_Notifications::count( $rid, $include, $exclude, $payment ) ) );
	}

	/**
	 * Shared AJAX guard — capability + nonce.
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_notifications', 'nonce' );
	}
}
