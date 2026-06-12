<?php
/**
 * EEM_Entries — the "Entries" feature (v1).
 *
 * Replaces the per-reservation "Pre-Entries" editor section with a first-class,
 * reservation-linked entity: each Entry is one purchasable line item (name +
 * price + inventory + per-customer cap) attached to a reservation (which is the
 * plugin's handle for an event instance). Entries are managed under
 * Event Manager → Orders → Entries; on the customer event page they surface as
 * another purchasable card and fold into the reservation order at checkout.
 *
 * Named generically ("Entries", not "Pre-Entries") so the concept can later grow
 * into contestant entries (disciplines/fees) without a rename.
 *
 * The customer-facing render, pricing, validation, totals and order-note
 * pipeline all consume {@see self::get_for_reservation()}, which returns the same
 * option shape the legacy reservation-meta resolver did — so nothing downstream
 * had to change.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries CPT + admin meta box + reservation-scoped resolver.
 */
class EEM_Entries {

	/** @var string The Entry custom post type. */
	const POST_TYPE = 'en_entry';

	/** @var string Linked-reservation meta key. */
	const META_RESERVATION = '_en_entry_reservation_id';

	/** @var string Price meta key (decimal string). */
	const META_PRICE = '_en_entry_price';

	/** @var string Inventory cap meta key (0 = unlimited). */
	const META_INVENTORY = '_en_entry_inventory';

	/** @var string Max-per-customer meta key (0 = unlimited). */
	const META_MAX = '_en_entry_max';

	/**
	 * Register the CPT, meta box, save handler and list-table columns.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	/**
	 * Register the `en_entry` post type under the Orders menu.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
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
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'equine-event-manager-orders',
				'show_in_rest'       => false,
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'capabilities'       => array( 'create_posts' => 'manage_options' ),
				'hierarchical'       => false,
				'supports'           => array( 'title' ),
				'has_archive'        => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	/**
	 * Register the Entry Details meta box.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			'eem-entry-details',
			__( 'Entry Details', 'equine-event-manager' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the Entry Details fields (event/reservation, price, inventory, cap).
	 *
	 * @param WP_Post $post The entry being edited.
	 * @return void
	 */
	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'eem_entry_save', 'eem_entry_nonce' );

		$reservation_id = (int) get_post_meta( $post->ID, self::META_RESERVATION, true );
		$price          = (string) get_post_meta( $post->ID, self::META_PRICE, true );
		$inventory      = (string) get_post_meta( $post->ID, self::META_INVENTORY, true );
		$max            = (string) get_post_meta( $post->ID, self::META_MAX, true );

		$reservations = get_posts( array(
			'post_type'      => EEM_Reservations_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<style>
			.eem-entry-field { margin: 0 0 14px; }
			.eem-entry-field label { display: block; font-weight: 600; margin-bottom: 4px; }
			.eem-entry-field .description { color: #6B7A99; }
			.eem-entry-field input[type="number"], .eem-entry-field input[type="text"], .eem-entry-field select { width: 100%; max-width: 420px; }
		</style>
		<p class="eem-entry-field">
			<label for="eem-entry-reservation"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></label>
			<select id="eem-entry-reservation" name="eem_entry_reservation_id">
				<option value="0"><?php esc_html_e( '— Select an event —', 'equine-event-manager' ); ?></option>
				<?php foreach ( $reservations as $r ) : ?>
					<option value="<?php echo esc_attr( (string) $r->ID ); ?>" <?php selected( $reservation_id, $r->ID ); ?>><?php echo esc_html( get_the_title( $r ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'The event this entry belongs to. It will show as a card on that event\'s customer reservation page.', 'equine-event-manager' ); ?></span>
		</p>
		<p class="eem-entry-field">
			<label for="eem-entry-price"><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></label>
			<input type="number" id="eem-entry-price" name="eem_entry_price" step="0.01" min="0" value="<?php echo esc_attr( '' !== $price ? $price : '0.00' ); ?>">
			<span class="description"><?php esc_html_e( 'Price per entry the customer pays.', 'equine-event-manager' ); ?></span>
		</p>
		<p class="eem-entry-field">
			<label for="eem-entry-inventory"><?php esc_html_e( 'Inventory', 'equine-event-manager' ); ?></label>
			<input type="number" id="eem-entry-inventory" name="eem_entry_inventory" step="1" min="0" value="<?php echo esc_attr( $inventory ); ?>" placeholder="<?php esc_attr_e( 'Blank or 0 = unlimited', 'equine-event-manager' ); ?>">
			<span class="description"><?php esc_html_e( 'Total available. Blank or 0 = unlimited.', 'equine-event-manager' ); ?></span>
		</p>
		<p class="eem-entry-field">
			<label for="eem-entry-max"><?php esc_html_e( 'Max Per Customer', 'equine-event-manager' ); ?></label>
			<input type="number" id="eem-entry-max" name="eem_entry_max" step="1" min="0" value="<?php echo esc_attr( $max ); ?>" placeholder="<?php esc_attr_e( 'Blank or 0 = unlimited', 'equine-event-manager' ); ?>">
			<span class="description"><?php esc_html_e( 'Most a single customer may buy. Blank or 0 = unlimited.', 'equine-event-manager' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Persist the Entry Details fields.
	 *
	 * @param int     $post_id The entry post id.
	 * @param WP_Post $post    The entry post.
	 * @return void
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['eem_entry_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eem_entry_nonce'] ) ), 'eem_entry_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_RESERVATION, isset( $_POST['eem_entry_reservation_id'] ) ? absint( wp_unslash( $_POST['eem_entry_reservation_id'] ) ) : 0 );
		update_post_meta( $post_id, self::META_PRICE, isset( $_POST['eem_entry_price'] ) ? number_format( (float) wp_unslash( $_POST['eem_entry_price'] ), 2, '.', '' ) : '0.00' );
		update_post_meta( $post_id, self::META_INVENTORY, isset( $_POST['eem_entry_inventory'] ) ? absint( wp_unslash( $_POST['eem_entry_inventory'] ) ) : 0 );
		update_post_meta( $post_id, self::META_MAX, isset( $_POST['eem_entry_max'] ) ? absint( wp_unslash( $_POST['eem_entry_max'] ) ) : 0 );
	}

	/**
	 * List-table columns: Event + Price alongside the title.
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
	 * @param int    $post_id Entry post id.
	 * @return void
	 */
	public static function column_value( string $column, int $post_id ): void {
		if ( 'eem_entry_event' === $column ) {
			$rid = (int) get_post_meta( $post_id, self::META_RESERVATION, true );
			echo $rid > 0 ? esc_html( get_the_title( $rid ) ) : '<span style="color:#b91c1c">' . esc_html__( '— not linked —', 'equine-event-manager' ) . '</span>';
		} elseif ( 'eem_entry_price' === $column ) {
			echo esc_html( '$' . number_format_i18n( (float) get_post_meta( $post_id, self::META_PRICE, true ), 2 ) );
		}
	}

	/**
	 * Resolve published Entries linked to a reservation, in the same option shape
	 * the legacy reservation-meta pre-entry resolver returned (keyed by a stable
	 * `entry_{id}` key, with title/price/inventory/max_per_customer). Consumed by
	 * the customer-page render, pricing matrix, checkout validation, totals and
	 * order notes.
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
			$title = trim( (string) get_the_title( $p ) );
			if ( '' === $title ) {
				continue;
			}
			$options[ 'entry_' . $p->ID ] = array(
				'title'            => $title,
				'price'            => number_format( (float) get_post_meta( $p->ID, self::META_PRICE, true ), 2, '.', '' ),
				'inventory'        => absint( get_post_meta( $p->ID, self::META_INVENTORY, true ) ),
				'max_per_customer' => absint( get_post_meta( $p->ID, self::META_MAX, true ) ),
			);
		}

		return $options;
	}
}
