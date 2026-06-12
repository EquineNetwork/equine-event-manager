<?php
/**
 * Venues admin page (v2 Facility Layout Templates, Slice 2).
 *
 * Lists every canonical Venue resolved by EEM_Venue (source-agnostic — fed by
 * TEC + GEMS today, Native Events in v3) with its saved-layout and source-mapping
 * counts. Drilling into a venue shows its saved Facility Layout Templates (rename
 * / delete) plus the event sources that point at it. Nested UNDER "Stall & RV
 * Charts" in the Event Manager menu (layouts are stall/RV grids) per
 * docs/ARCHITECTURE-VENUES.md §4 — there is never a second "Venues" nav entry.
 *
 * Read-mostly: the Venues themselves are created implicitly by the resolver when
 * reservations link to events; this page does not create venues directly. Layout
 * rename/delete are the only mutations, dispatched via AJAX.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Venues list + detail page controller.
 */
class EEM_Venues_Page {

	/**
	 * Visible submenu slug (sits under Stall & RV Charts in the menu order).
	 */
	const MENU_SLUG = 'equine-event-manager-venues';

	/**
	 * Register AJAX handlers (the submenu itself is registered by EEM_Admin
	 * alongside the other Event Manager submenus so the parent-slug attachment
	 * and ordering stay single-sourced).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_venue_rename_layout', array( __CLASS__, 'ajax_rename_layout' ) );
		add_action( 'wp_ajax_eem_venue_delete_layout', array( __CLASS__, 'ajax_delete_layout' ) );
	}

	/**
	 * Build a Venues-page URL with query args layered on the base.
	 *
	 * @param array<string,mixed> $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Human-readable label for an event-source key.
	 *
	 * @param string $source Source key (tec|gems|native|...).
	 * @return string
	 */
	public static function source_label( string $source ): string {
		switch ( $source ) {
			case 'tec':
				return __( 'The Events Calendar', 'equine-event-manager' );
			case 'gems':
				return __( 'GEMS', 'equine-event-manager' );
			case 'native':
				return __( 'Native Events', 'equine-event-manager' );
			default:
				return ucfirst( $source );
		}
	}

	/**
	 * Render dispatcher — list view, or detail view when ?venue_id=N is present.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param.
		$venue_id = isset( $_GET['venue_id'] ) ? absint( wp_unslash( $_GET['venue_id'] ) ) : 0;
		if ( $venue_id > 0 ) {
			self::render_detail( $venue_id );
			return;
		}
		self::render_list();
	}

	/**
	 * Venues list view.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$venues = EEM_Venue::all_with_counts();

		eem_render_page_open(
			array(
				'title'      => __( 'Venues', 'equine-event-manager' ),
				'subtitle'   => sprintf(
					/* translators: %s: total venue count */
					_n( '%s venue', '%s venues', count( $venues ), 'equine-event-manager' ),
					number_format_i18n( count( $venues ) )
				),
				'breadcrumb' => array(
					array( 'label' => __( 'Stall & RV Charts', 'equine-event-manager' ), 'href' => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ) ),
					array( 'label' => __( 'Venues', 'equine-event-manager' ) ),
				),
			)
		);
		?>
		<div class="eem-venues">
			<p class="eem-venues-intro"><?php esc_html_e( 'A venue is a real-world place that owns reusable stall &amp; RV layouts. Venues appear here automatically as reservations link to events; save a layout from a reservation builder to reuse it next year.', 'equine-event-manager' ); ?></p>
			<?php if ( empty( $venues ) ) : ?>
				<div class="eem-venues-empty">
					<h3><?php esc_html_e( 'No venues yet', 'equine-event-manager' ); ?></h3>
					<p><?php esc_html_e( 'Link a reservation to an event, then use “Save Layout” on the stall or RV builder to capture that venue’s layout for reuse.', 'equine-event-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="eem-table eem-venues-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Venue', 'equine-event-manager' ); ?></th>
							<th class="eem-table-c"><?php esc_html_e( 'Saved Layouts', 'equine-event-manager' ); ?></th>
							<th class="eem-table-c"><?php esc_html_e( 'Event Sources', 'equine-event-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $venues as $v ) : ?>
							<?php $detail = self::url( array( 'venue_id' => (int) $v['id'] ) ); ?>
							<tr>
								<td><a class="eem-venues-name" href="<?php echo esc_url( $detail ); ?>"><?php echo esc_html( $v['name'] ); ?></a></td>
								<td class="eem-table-c"><?php echo esc_html( number_format_i18n( (int) $v['layout_count'] ) ); ?></td>
								<td class="eem-table-c"><?php echo esc_html( number_format_i18n( (int) $v['source_count'] ) ); ?></td>
								<td class="eem-table-r"><a class="eem-btn eem-btn-secondary eem-btn-sm" href="<?php echo esc_url( $detail ); ?>"><?php esc_html_e( 'View', 'equine-event-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Single-venue detail view (saved layouts + source mappings).
	 *
	 * @param int $venue_id Venue id.
	 * @return void
	 */
	private static function render_detail( int $venue_id ): void {
		$venue = EEM_Venue::get( $venue_id );
		if ( null === $venue ) {
			eem_render_page_open(
				array(
					'title'      => __( 'Venue not found', 'equine-event-manager' ),
					'breadcrumb' => array(
						array( 'label' => __( 'Venues', 'equine-event-manager' ), 'href' => self::url() ),
						array( 'label' => __( 'Not found', 'equine-event-manager' ) ),
					),
				)
			);
			echo '<div class="eem-venues"><div class="eem-venues-empty"><p>' . esc_html__( 'That venue no longer exists.', 'equine-event-manager' ) . '</p><p><a class="eem-btn eem-btn-secondary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Venues', 'equine-event-manager' ) . '</a></p></div></div>';
			eem_render_page_close();
			return;
		}

		$layouts = EEM_Venue::get_layouts( $venue_id );
		$sources = EEM_Venue::get_source_mappings( $venue_id );
		$nonce   = wp_create_nonce( 'eem_venue_layout' );

		eem_render_page_open(
			array(
				'title'      => $venue['name'],
				'subtitle'   => sprintf(
					/* translators: %s: saved-layout count */
					_n( '%s saved layout', '%s saved layouts', count( $layouts ), 'equine-event-manager' ),
					number_format_i18n( count( $layouts ) )
				),
				'breadcrumb' => array(
					array( 'label' => __( 'Stall & RV Charts', 'equine-event-manager' ), 'href' => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ) ),
					array( 'label' => __( 'Venues', 'equine-event-manager' ), 'href' => self::url() ),
					array( 'label' => $venue['name'] ),
				),
			)
		);
		?>
		<div class="eem-venues eem-venue-detail" data-venue-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="eem-card eem-venue-card">
				<div class="eem-card-head"><h2 class="eem-card-title"><?php esc_html_e( 'Saved Layouts', 'equine-event-manager' ); ?></h2></div>
				<div class="eem-card-body">
					<?php if ( empty( $layouts ) ) : ?>
						<p class="eem-venue-empty-note"><?php esc_html_e( 'No saved layouts for this venue yet. Use “Save Layout” on a reservation’s stall or RV builder to capture one.', 'equine-event-manager' ); ?></p>
					<?php else : ?>
						<table class="eem-table eem-venue-layouts-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Layout', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Saved', 'equine-event-manager' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $layouts as $l ) : ?>
									<tr data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>">
										<td class="eem-venue-layout-name"><?php echo esc_html( $l['name'] ); ?></td>
										<td><?php echo esc_html( self::format_date( (string) $l['created_at'] ) ); ?></td>
										<td class="eem-table-r">
											<button type="button" class="eem-btn eem-btn-secondary eem-btn-sm" data-eem-action="venue-layout-rename" data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>" data-layout-name="<?php echo esc_attr( (string) $l['name'] ); ?>"><?php esc_html_e( 'Rename', 'equine-event-manager' ); ?></button>
											<button type="button" class="eem-btn eem-btn-danger eem-btn-sm" data-eem-action="venue-layout-delete" data-layout-id="<?php echo esc_attr( (string) (int) $l['id'] ); ?>" data-layout-name="<?php echo esc_attr( (string) $l['name'] ); ?>"><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="eem-card eem-venue-card">
				<div class="eem-card-head"><h2 class="eem-card-title"><?php esc_html_e( 'Event Sources', 'equine-event-manager' ); ?></h2></div>
				<div class="eem-card-body">
					<p class="eem-venue-empty-note"><?php esc_html_e( 'These event sources resolve to this venue. The same physical place reached through multiple sources or seasons unifies here.', 'equine-event-manager' ); ?></p>
					<?php if ( ! empty( $sources ) ) : ?>
						<table class="eem-table eem-venue-sources-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Source Venue Name', 'equine-event-manager' ); ?></th>
									<th><?php esc_html_e( 'Source ID', 'equine-event-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sources as $s ) : ?>
									<tr>
										<td><span class="eem-status-badge eem-venue-source-badge"><?php echo esc_html( self::source_label( (string) $s['source'] ) ); ?></span></td>
										<td><?php echo esc_html( '' !== (string) $s['source_venue_name'] ? (string) $s['source_venue_name'] : '—' ); ?></td>
										<td><?php echo esc_html( '' !== (string) $s['source_venue_id'] ? (string) $s['source_venue_id'] : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		eem_render_page_close();
	}

	/**
	 * Format a stored MySQL datetime to the site date format.
	 *
	 * @param string $mysql_datetime Stored datetime.
	 * @return string
	 */
	private static function format_date( string $mysql_datetime ): string {
		$ts = strtotime( $mysql_datetime );
		if ( ! $ts ) {
			return $mysql_datetime;
		}
		return date_i18n( (string) get_option( 'date_format', 'M j, Y' ), $ts );
	}

	/* ── AJAX ───────────────────────────────────────────────────── */

	/**
	 * Shared guard for the layout AJAX handlers (cap + nonce).
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_venue_layout', 'nonce' );
	}

	/**
	 * AJAX: rename a saved layout.
	 *
	 * @return void
	 */
	public static function ajax_rename_layout(): void {
		self::guard();
		$layout_id = isset( $_POST['layout_id'] ) ? absint( wp_unslash( $_POST['layout_id'] ) ) : 0;
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( $layout_id <= 0 || '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'A layout name is required.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! EEM_Venue::rename_layout( $layout_id, $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not rename the layout.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success( array( 'name' => $name ) );
	}

	/**
	 * AJAX: delete a saved layout.
	 *
	 * @return void
	 */
	public static function ajax_delete_layout(): void {
		self::guard();
		$layout_id = isset( $_POST['layout_id'] ) ? absint( wp_unslash( $_POST['layout_id'] ) ) : 0;
		if ( $layout_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid layout.', 'equine-event-manager' ) ), 400 );
		}
		if ( ! EEM_Venue::delete_layout( $layout_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete the layout.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success();
	}
}
