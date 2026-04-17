<?php
/**
 * Admin pages for EN Event Manager.
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Orders and Reports admin pages.
 */
class EN_Event_Manager_Admin {

	/**
	 * Orders repository.
	 *
	 * @var EN_Event_Manager_Orders_Repository
	 */
	private $orders_repository;

	/**
	 * Set up admin dependencies.
	 */
	public function __construct() {
		$this->orders_repository = new EN_Event_Manager_Orders_Repository();
	}

	/**
	 * Register admin pages under the Reservations CPT menu.
	 */
	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=en_reservation',
			__( 'Orders', 'en-event-manager' ),
			__( 'Orders', 'en-event-manager' ),
			'manage_options',
			'en-event-manager-orders',
			array( $this, 'render_orders_page' )
		);

		add_submenu_page(
			'edit.php?post_type=en_reservation',
			__( 'Reports', 'en-event-manager' ),
			__( 'Reports', 'en-event-manager' ),
			'manage_options',
			'en-event-manager-reports',
			array( $this, 'render_reports_page' )
		);
	}

	/**
	 * Render the Orders list page.
	 */
	public function render_orders_page() {
		$this->guard_admin_page();

		$event_filter = isset( $_GET['event_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['event_filter'] ) ) : '';
		$orders       = $this->orders_repository->get_orders( $event_filter );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orders', 'en-event-manager' ); ?></h1>
			<p><?php esc_html_e( 'Customer reservations from stall and RV reservation tables will appear here.', 'en-event-manager' ); ?></p>

			<form method="get">
				<input type="hidden" name="post_type" value="en_reservation" />
				<input type="hidden" name="page" value="en-event-manager-orders" />
				<label for="event_filter" class="screen-reader-text"><?php esc_html_e( 'Filter by event', 'en-event-manager' ); ?></label>
				<input type="search" id="event_filter" name="event_filter" value="<?php echo esc_attr( $event_filter ); ?>" placeholder="<?php esc_attr_e( 'Filter by event', 'en-event-manager' ); ?>" />
				<?php submit_button( __( 'Filter', 'en-event-manager' ), 'secondary', '', false ); ?>
				<?php if ( '' !== $event_filter ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=en_reservation&page=en-event-manager-orders' ) ); ?>"><?php esc_html_e( 'Clear', 'en-event-manager' ); ?></a>
				<?php endif; ?>
			</form>

			<?php $this->render_orders_table( $orders ); ?>
		</div>
		<?php
	}

	/**
	 * Render the reports placeholder page.
	 */
	public function render_reports_page() {
		$this->guard_admin_page();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'en-event-manager' ); ?></h1>
			<p><?php esc_html_e( 'Reservation reports will be added in a future version.', 'en-event-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render orders rows.
	 *
	 * @param array $orders Order rows.
	 */
	private function render_orders_table( $orders ) {
		?>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Event Name', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Customer Name', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Type', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Quantity', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Total', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Payment Status', 'en-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Created Date', 'en-event-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $orders ) ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No orders found.', 'en-event-manager' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<td><?php echo esc_html( $order['id'] ); ?></td>
							<td><?php echo esc_html( $order['event_name'] ); ?></td>
							<td><?php echo esc_html( $order['customer_name'] ); ?></td>
							<td><?php echo esc_html( $order['type'] ); ?></td>
							<td><?php echo esc_html( absint( $order['quantity'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $order['total'], 2 ) ); ?></td>
							<td><?php echo esc_html( $order['payment_status'] ); ?></td>
							<td><?php echo esc_html( $order['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Ensure the current user can access plugin admin pages.
	 */
	private function guard_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'en-event-manager' ) );
		}
	}
}
