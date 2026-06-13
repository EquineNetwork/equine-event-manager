<?php
/**
 * Sheets & Results admin manager page (Screen 1).
 *
 * One page, two tabs (Draw Sheets + Results), scoped to a single native event
 * chosen in the "Viewing event" selector. Documents are grouped by discipline
 * (the `en_discipline` taxonomy assigned to the event). Draw sheet and result
 * are two PDF columns on one EEM_Sheet_Entries row, so the Results tab is the
 * same rows viewed through their result slot — adding a draw sheet auto-creates
 * the mirrored result placeholder, and "Upload Result PDF" just fills the
 * result column.
 *
 * Relationship wiring: this page hangs off the native `en_event` post (the hub).
 * The selector lists `en_event` posts the same way the branded Events list does,
 * so it stays consistent with the rest of the native-events admin. Disciplines
 * assigned here power the public per-event Sheets & Results page (Slice 5) and
 * the conditional event-card buttons (Slice 4). Entries/Divisions remain a
 * sibling concern that hangs off the same event via the reservation — they are
 * not coupled to disciplines, by design.
 *
 * @package EEM_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sheets & Results manager page controller.
 */
class EEM_Sheets_Results_Page {

	/**
	 * Admin menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'equine-event-manager-sheets-results';

	/**
	 * AJAX nonce action (shared by all mutating endpoints on this page).
	 *
	 * @var string
	 */
	const NONCE = 'eem_sheets_results';

	/**
	 * Wire the AJAX handlers for the page's mutations.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_eem_sr_add_discipline', array( __CLASS__, 'ajax_add_discipline' ) );
		add_action( 'wp_ajax_eem_sr_add_entry', array( __CLASS__, 'ajax_add_entry' ) );
		add_action( 'wp_ajax_eem_sr_set_pdf', array( __CLASS__, 'ajax_set_pdf' ) );
		add_action( 'wp_ajax_eem_sr_delete_entry', array( __CLASS__, 'ajax_delete_entry' ) );
		add_action( 'wp_ajax_eem_sr_render_section', array( __CLASS__, 'ajax_render_section' ) );
	}

	/**
	 * Build the page URL, optionally focused on an event + tab.
	 *
	 * @param int    $event_id Event post id (0 = no focus).
	 * @param string $tab      'drawsheets' or 'results'.
	 * @return string
	 */
	public static function url( int $event_id = 0, string $tab = 'drawsheets' ): string {
		$args = array( 'page' => self::MENU_SLUG );
		if ( $event_id > 0 ) {
			$args['event_id'] = $event_id;
		}
		if ( 'results' === $tab ) {
			$args['tab'] = 'results';
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Native events available for the selector (published + draft), title-sorted.
	 *
	 * @return WP_Post[]
	 */
	private static function selector_events(): array {
		return get_posts( array(
			'post_type'   => EEM_Events::EVENT_POST_TYPE,
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );
	}

	/**
	 * Lifecycle band for an event: [css-class, label] derived from its dates.
	 * Mirrors the Events list page's lifecycle badge (Upcoming / Ongoing / Past).
	 *
	 * @param string $start Y-m-d start date.
	 * @param string $end   Y-m-d end date.
	 * @return array{0:string,1:string}
	 */
	private static function lifecycle( string $start, string $end ): array {
		if ( '' === $start ) {
			return array( 'eem-status-upcoming', __( 'Upcoming', 'equine-event-manager' ) );
		}
		$today = gmdate( 'Y-m-d' );
		$end   = '' !== $end ? $end : $start;
		if ( $end < $today ) {
			return array( 'eem-status-archived', __( 'Past Event', 'equine-event-manager' ) );
		}
		if ( $start <= $today && $today <= $end ) {
			return array( 'eem-status-active', __( 'Ongoing', 'equine-event-manager' ) );
		}
		return array( 'eem-status-upcoming', __( 'Upcoming', 'equine-event-manager' ) );
	}

	/**
	 * Human date-range label for an event (e.g. "Jun 14–18, 2026").
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return string
	 */
	private static function date_range_label( string $start, string $end ): string {
		if ( '' === $start ) {
			return '';
		}
		$s = strtotime( $start );
		$e = '' !== $end ? strtotime( $end ) : $s;
		if ( false === $s ) {
			return '';
		}
		if ( false === $e || $e === $s ) {
			return date_i18n( 'M j, Y', $s );
		}
		if ( gmdate( 'Y-m', $s ) === gmdate( 'Y-m', $e ) ) {
			return date_i18n( 'M j', $s ) . '–' . date_i18n( 'j, Y', $e );
		}
		return date_i18n( 'M j', $s ) . ' – ' . date_i18n( 'M j, Y', $e );
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

		$events = self::selector_events();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only nav params.
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$tab      = ( isset( $_GET['tab'] ) && 'results' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'results' : 'drawsheets';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Default to the first event when none is in the URL.
		if ( 0 === $event_id && ! empty( $events ) ) {
			$event_id = (int) $events[0]->ID;
		}
		$event = $event_id > 0 ? get_post( $event_id ) : null;
		if ( $event && EEM_Events::EVENT_POST_TYPE !== $event->post_type ) {
			$event = null;
		}

		$nonce = wp_create_nonce( self::NONCE );
		?>
		<div class="eem-page eem-sheets-results"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
			<?php
			eem_render_breadcrumb( array(
				array( 'label' => __( 'Sheets & Results', 'equine-event-manager' ) ),
			) );
			?>
			<div class="eem-plugin-wrap">
				<header class="eem-plugin-header">
					<div class="eem-plugin-header-left">
						<h1 class="eem-plugin-title"><?php esc_html_e( 'Sheets & Results', 'equine-event-manager' ); ?></h1>
						<div class="eem-plugin-subtitle"><?php esc_html_e( 'Upload and manage draw sheets and results for any event. Files go live immediately when saved.', 'equine-event-manager' ); ?></div>
					</div>
				</header>

				<?php if ( empty( $events ) ) : ?>
					<div class="eem-sr-empty">
						<p><strong><?php esc_html_e( 'No events yet', 'equine-event-manager' ); ?></strong></p>
						<p><?php esc_html_e( 'Create an event first — draw sheets and results attach to an event.', 'equine-event-manager' ); ?></p>
						<a class="eem-btn eem-btn-electric" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=en_event' ) ); ?>"><?php esc_html_e( 'Add Event', 'equine-event-manager' ); ?></a>
					</div>
				<?php else : ?>
					<?php self::render_event_selector( $events, $event_id, $event ); ?>
					<?php if ( $event ) : ?>
						<div class="eem-sr-body">
							<?php self::render_body_inner( $event_id, $tab ); ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Event selector band (dropdown + date pill + lifecycle pill).
	 *
	 * @param WP_Post[]    $events   Available events.
	 * @param int          $event_id Selected event id.
	 * @param WP_Post|null $event    Selected event post.
	 * @return void
	 */
	private static function render_event_selector( array $events, int $event_id, $event ): void {
		$start = $event ? (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true ) : '';
		$end   = $event ? (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true ) : '';
		$range = self::date_range_label( $start, $end );
		list( $life_class, $life_label ) = self::lifecycle( $start, $end );
		?>
		<div class="eem-sr-selector">
			<span class="eem-sr-selector-label"><?php esc_html_e( 'Viewing event:', 'equine-event-manager' ); ?></span>
			<select class="eem-field-select eem-sr-event-select" data-eem-action="sr-switch-event">
				<?php foreach ( $events as $ev ) : ?>
					<option value="<?php echo esc_attr( (string) $ev->ID ); ?>" <?php selected( $event_id, $ev->ID ); ?>><?php echo esc_html( '' !== trim( (string) $ev->post_title ) ? $ev->post_title : __( '(untitled event)', 'equine-event-manager' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $range ) : ?>
				<span class="eem-sr-meta-pill"><?php echo esc_html( $range ); ?></span>
			<?php endif; ?>
			<span class="eem-status-badge <?php echo esc_attr( $life_class ); ?> eem-sr-status-pill"><?php echo esc_html( $life_label ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render the embedded Sheets & Results section for the Event editor (Screen
	 * 2). Same body as the manager page — tabs, discipline groups, add panels,
	 * rows — minus the event selector (the editor is already scoped to one
	 * event). Mutations re-render via the AJAX fragment endpoint (no full reload)
	 * so unsaved event-editor field edits aren't lost. Returns a string for use
	 * as a section card's `body_html`.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	public static function render_embedded_section( int $event_id ): string {
		$nonce = wp_create_nonce( self::NONCE );
		ob_start();
		?>
		<div class="eem-sheets-results eem-sr-embedded"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
			<div class="eem-sr-embed-bar">
				<span class="eem-field-hint"><?php esc_html_e( 'Files go live immediately when saved.', 'equine-event-manager' ); ?></span>
				<a class="eem-btn eem-btn-secondary" href="<?php echo esc_url( self::url( $event_id ) ); ?>"><?php esc_html_e( 'Manage in Sheets & Results', 'equine-event-manager' ); ?></a>
			</div>
			<div class="eem-sr-body">
				<?php self::render_body_inner( $event_id, 'drawsheets' ); ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Tabs + the two tab bodies for the selected event (no selector).
	 *
	 * @param int    $event_id Selected event id.
	 * @param string $tab      Active tab.
	 * @return void
	 */
	private static function render_body_inner( int $event_id, string $tab ): void {
		$groups = EEM_Sheet_Entries::get_for_event_grouped_by_discipline( $event_id );
		$counts = EEM_Sheet_Entries::counts( $event_id );
		?>
		<div class="eem-sr-tabs">
			<button type="button" class="eem-sr-tab <?php echo 'drawsheets' === $tab ? 'is-active' : ''; ?>" data-eem-action="sr-tab" data-sr-tab="drawsheets">
				<?php esc_html_e( 'Draw Sheets', 'equine-event-manager' ); ?> <span class="eem-sr-tab-count"><?php echo esc_html( (string) $counts['drawsheets'] ); ?></span>
			</button>
			<button type="button" class="eem-sr-tab <?php echo 'results' === $tab ? 'is-active' : ''; ?>" data-eem-action="sr-tab" data-sr-tab="results">
				<?php esc_html_e( 'Results', 'equine-event-manager' ); ?> <span class="eem-sr-tab-count"><?php echo esc_html( (string) $counts['results'] ); ?></span>
			</button>
		</div>

		<div class="eem-sr-panel" data-sr-panel="drawsheets" <?php echo 'drawsheets' === $tab ? '' : 'hidden'; ?>>
			<?php self::render_add_discipline_bar( $event_id ); ?>
			<?php self::render_drawsheets( $event_id, $groups ); ?>
		</div>
		<div class="eem-sr-panel" data-sr-panel="results" <?php echo 'results' === $tab ? '' : 'hidden'; ?>>
			<?php self::render_results( $event_id, $groups ); ?>
		</div>
		<?php
	}

	/**
	 * "Add Discipline" inline control — creates/assigns an en_discipline term to
	 * the event so a new group appears (the discipline-assignment path the
	 * mockup assumes already happened).
	 *
	 * @param int $event_id Event id.
	 * @return void
	 */
	private static function render_add_discipline_bar( int $event_id ): void {
		?>
		<div class="eem-sr-add-discipline">
			<input type="text" class="eem-field-input eem-sr-discipline-input" placeholder="<?php esc_attr_e( 'New discipline (e.g. Barrel Racing)…', 'equine-event-manager' ); ?>" />
			<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="sr-add-discipline" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Add Discipline', 'equine-event-manager' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Draw Sheets tab — one group per discipline with an Add File panel + rows.
	 *
	 * @param int   $event_id Event id.
	 * @param array $groups   Grouped entries.
	 * @return void
	 */
	private static function render_drawsheets( int $event_id, array $groups ): void {
		if ( empty( $groups ) ) {
			echo '<div class="eem-sr-empty-mini"><p>' . esc_html__( 'No disciplines yet. Add a discipline above to start uploading draw sheets.', 'equine-event-manager' ) . '</p></div>';
			return;
		}
		foreach ( $groups as $g ) {
			$did       = (int) $g['discipline_id'];
			$with_pdf  = array_filter( $g['entries'], static function ( $e ) {
				return $e['drawsheet_pdf'] > 0;
			} );
			$file_count = count( $with_pdf );
			?>
			<div class="eem-sr-group">
				<div class="eem-sr-group-head">
					<div class="eem-sr-group-name"><?php echo esc_html( $g['discipline_name'] ); ?> <span class="eem-sr-group-count"><?php echo esc_html( sprintf( /* translators: %d: file count */ _n( '%d file', '%d files', $file_count, 'equine-event-manager' ), $file_count ) ); ?></span></div>
					<button type="button" class="eem-sr-add-file-btn" data-eem-action="sr-toggle-add" data-discipline-id="<?php echo esc_attr( (string) $did ); ?>"><?php esc_html_e( '+ Add File', 'equine-event-manager' ); ?></button>
				</div>
				<?php self::render_add_panel( $event_id, $did ); ?>
				<?php
				if ( empty( $with_pdf ) ) {
					echo '<div class="eem-sr-empty-mini"><p>' . esc_html__( 'No draw sheets uploaded yet for this discipline.', 'equine-event-manager' ) . '</p></div>';
				} else {
					foreach ( $with_pdf as $e ) {
						self::render_drawsheet_row( $e );
					}
				}
				?>
			</div>
			<?php
		}
	}

	/**
	 * A single draw-sheet row (download / replace / delete).
	 *
	 * @param array $e Entry row.
	 * @return void
	 */
	private static function render_drawsheet_row( array $e ): void {
		$url  = $e['drawsheet_pdf'] > 0 ? (string) wp_get_attachment_url( $e['drawsheet_pdf'] ) : '';
		$meta = self::row_meta( $e );
		?>
		<div class="eem-sr-row">
			<div class="eem-sr-row-icon"><?php self::pdf_icon(); ?></div>
			<div class="eem-sr-row-info">
				<span class="eem-sr-row-name"><?php echo esc_html( '' !== $e['label'] ? $e['label'] : __( '(untitled)', 'equine-event-manager' ) ); ?></span>
				<div class="eem-sr-row-meta"><?php echo esc_html( $meta ); ?></div>
			</div>
			<span class="eem-status-badge eem-status-active eem-sr-live-badge"><?php esc_html_e( 'Live', 'equine-event-manager' ); ?></span>
			<div class="eem-sr-row-actions">
				<?php if ( '' !== $url ) : ?>
					<a class="eem-sr-action-btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Download', 'equine-event-manager' ); ?>"><?php self::download_icon(); ?></a>
				<?php endif; ?>
				<button type="button" class="eem-sr-action-btn" data-eem-action="sr-replace-pdf" data-entry-id="<?php echo esc_attr( (string) $e['id'] ); ?>" data-which="drawsheet" title="<?php esc_attr_e( 'Replace PDF', 'equine-event-manager' ); ?>"><?php self::replace_icon(); ?></button>
				<button type="button" class="eem-sr-action-btn is-danger" data-eem-action="sr-delete-entry" data-entry-id="<?php echo esc_attr( (string) $e['id'] ); ?>" data-label="<?php echo esc_attr( $e['label'] ); ?>" title="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>"><?php self::trash_icon(); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Results tab — mirrored rows; amber "Upload Result PDF" where none yet.
	 *
	 * @param int   $event_id Event id.
	 * @param array $groups   Grouped entries.
	 * @return void
	 */
	private static function render_results( int $event_id, array $groups ): void {
		if ( empty( $groups ) ) {
			echo '<div class="eem-sr-empty-mini"><p>' . esc_html__( 'No disciplines yet. Results rows appear automatically once draw sheets are uploaded.', 'equine-event-manager' ) . '</p></div>';
			return;
		}
		foreach ( $groups as $g ) {
			// Result rows mirror draw-sheet rows: only entries that have a draw
			// sheet produce a result slot.
			$rows     = array_filter( $g['entries'], static function ( $e ) {
				return $e['drawsheet_pdf'] > 0;
			} );
			$total    = count( $rows );
			$uploaded = count( array_filter( $rows, static function ( $e ) {
				return $e['result_pdf'] > 0;
			} ) );
			?>
			<div class="eem-sr-group">
				<div class="eem-sr-group-head">
					<div class="eem-sr-group-name"><?php echo esc_html( $g['discipline_name'] ); ?>
						<span class="eem-sr-group-count">
						<?php
						echo $total > 0
							? esc_html( sprintf( /* translators: 1: uploaded count, 2: total */ __( '%1$d of %2$d uploaded', 'equine-event-manager' ), $uploaded, $total ) )
							: esc_html__( '—', 'equine-event-manager' );
						?>
						</span>
					</div>
				</div>
				<?php
				if ( empty( $rows ) ) {
					echo '<div class="eem-sr-empty-mini"><p>' . esc_html__( 'No draw sheets added yet. Results rows appear automatically once draw sheets are uploaded.', 'equine-event-manager' ) . '</p></div>';
				} else {
					foreach ( $rows as $e ) {
						self::render_result_row( $e );
					}
				}
				?>
			</div>
			<?php
		}
	}

	/**
	 * A single result row — Live when a result PDF exists, else amber upload CTA.
	 *
	 * @param array $e Entry row.
	 * @return void
	 */
	private static function render_result_row( array $e ): void {
		$meta = self::row_meta( $e );
		if ( $e['result_pdf'] > 0 ) {
			$url = (string) wp_get_attachment_url( $e['result_pdf'] );
			?>
			<div class="eem-sr-row">
				<div class="eem-sr-row-icon"><?php self::pdf_icon(); ?></div>
				<div class="eem-sr-row-info">
					<span class="eem-sr-row-name"><?php echo esc_html( '' !== $e['label'] ? $e['label'] : __( '(untitled)', 'equine-event-manager' ) ); ?></span>
					<div class="eem-sr-row-meta"><?php echo esc_html( $meta ); ?></div>
				</div>
				<span class="eem-status-badge eem-status-active eem-sr-live-badge"><?php esc_html_e( 'Live', 'equine-event-manager' ); ?></span>
				<div class="eem-sr-row-actions">
					<?php if ( '' !== $url ) : ?>
						<a class="eem-sr-action-btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Download', 'equine-event-manager' ); ?>"><?php self::download_icon(); ?></a>
					<?php endif; ?>
					<button type="button" class="eem-sr-action-btn" data-eem-action="sr-replace-pdf" data-entry-id="<?php echo esc_attr( (string) $e['id'] ); ?>" data-which="result" title="<?php esc_attr_e( 'Replace PDF', 'equine-event-manager' ); ?>"><?php self::replace_icon(); ?></button>
					<button type="button" class="eem-sr-action-btn is-danger" data-eem-action="sr-clear-result" data-entry-id="<?php echo esc_attr( (string) $e['id'] ); ?>" title="<?php esc_attr_e( 'Remove result PDF', 'equine-event-manager' ); ?>"><?php self::trash_icon(); ?></button>
				</div>
			</div>
			<?php
		} else {
			?>
			<div class="eem-sr-row eem-sr-row--pending">
				<div class="eem-sr-row-icon eem-sr-row-icon--pending"><?php self::pdf_icon(); ?></div>
				<div class="eem-sr-row-info">
					<span class="eem-sr-row-name"><?php echo esc_html( '' !== $e['label'] ? $e['label'] : __( '(untitled)', 'equine-event-manager' ) ); ?></span>
					<div class="eem-sr-row-meta eem-sr-row-meta--pending"><?php esc_html_e( 'No result uploaded yet', 'equine-event-manager' ); ?></div>
				</div>
				<div class="eem-sr-row-actions">
					<button type="button" class="eem-btn eem-sr-upload-result-btn" data-eem-action="sr-replace-pdf" data-entry-id="<?php echo esc_attr( (string) $e['id'] ); ?>" data-which="result"><?php esc_html_e( 'Upload Result PDF', 'equine-event-manager' ); ?></button>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Inline Add File panel (hidden until "+ Add File" is clicked).
	 *
	 * @param int $event_id     Event id.
	 * @param int $discipline_id Discipline term id.
	 * @return void
	 */
	private static function render_add_panel( int $event_id, int $discipline_id ): void {
		?>
		<div class="eem-sr-add-panel" data-discipline-panel="<?php echo esc_attr( (string) $discipline_id ); ?>" hidden>
			<div class="eem-sr-add-grid">
				<div class="eem-sr-add-field">
					<label class="eem-sr-add-label"><?php esc_html_e( 'Label', 'equine-event-manager' ); ?></label>
					<input type="text" class="eem-field-input eem-sr-f-label" placeholder="<?php esc_attr_e( 'e.g. Open 5D Long Go', 'equine-event-manager' ); ?>" />
				</div>
				<div class="eem-sr-add-field">
					<label class="eem-sr-add-label"><?php esc_html_e( 'Round', 'equine-event-manager' ); ?></label>
					<select class="eem-field-select eem-sr-f-round">
						<option value=""><?php esc_html_e( 'No round', 'equine-event-manager' ); ?></option>
						<?php foreach ( EEM_Sheet_Entries::rounds() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="eem-sr-add-field">
					<label class="eem-sr-add-label"><?php esc_html_e( 'Date', 'equine-event-manager' ); ?></label>
					<input type="date" class="eem-field-input eem-sr-f-date" />
				</div>
				<div class="eem-sr-add-field">
					<label class="eem-sr-add-label"><?php esc_html_e( 'PDF File', 'equine-event-manager' ); ?></label>
					<div class="eem-sr-file-pick">
						<input type="hidden" class="eem-sr-f-pdf" value="0" />
						<span class="eem-sr-f-pdf-name"><?php esc_html_e( 'No file selected', 'equine-event-manager' ); ?></span>
						<button type="button" class="eem-btn-upload" data-eem-action="sr-pick-file"><?php esc_html_e( 'Upload PDF', 'equine-event-manager' ); ?></button>
					</div>
				</div>
			</div>
			<div class="eem-sr-add-actions">
				<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="sr-cancel-add"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
				<button type="button" class="eem-btn eem-btn-electric" data-eem-action="sr-save-entry" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-discipline-id="<?php echo esc_attr( (string) $discipline_id ); ?>"><?php esc_html_e( 'Save & Publish', 'equine-event-manager' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Row meta string ("1st Go · Jun 14"), omitting empty parts.
	 *
	 * @param array $e Entry row.
	 * @return string
	 */
	private static function row_meta( array $e ): string {
		$parts = array();
		$round = EEM_Sheet_Entries::round_label( $e['round'] );
		if ( '' !== $round ) {
			$parts[] = $round;
		}
		if ( '' !== $e['entry_date'] ) {
			$ts = strtotime( $e['entry_date'] );
			if ( false !== $ts ) {
				$parts[] = date_i18n( 'M j', $ts );
			}
		}
		return implode( ' · ', $parts );
	}

	/* ---- inline SVG icon helpers (match the mockup) ----------------------- */

	/** @return void */
	private static function pdf_icon(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
	}

	/** @return void */
	private static function download_icon(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
	}

	/** @return void */
	private static function replace_icon(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0115-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 01-15 6.7L3 16"/></svg>';
	}

	/** @return void */
	private static function trash_icon(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>';
	}

	/* ---- AJAX handlers ---------------------------------------------------- */

	/**
	 * Shared guard for all mutating endpoints: cap + nonce.
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Validate that a posted event id is a real native event.
	 *
	 * @param int $event_id Event id.
	 * @return void Sends a JSON error + exits when invalid.
	 */
	private static function require_event( int $event_id ): void {
		if ( $event_id <= 0 || EEM_Events::EVENT_POST_TYPE !== get_post_type( $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'equine-event-manager' ) ), 404 );
		}
	}

	/**
	 * AJAX: create (if needed) + assign a discipline term to the event.
	 *
	 * @return void
	 */
	public static function ajax_add_discipline(): void {
		self::guard();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		self::require_event( $event_id );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === trim( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a discipline name.', 'equine-event-manager' ) ), 422 );
		}

		$term = term_exists( $name, EEM_Sheet_Entries::TAXONOMY );
		if ( ! $term ) {
			$term = wp_insert_term( $name, EEM_Sheet_Entries::TAXONOMY );
		}
		if ( is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => $term->get_error_message() ), 400 );
		}
		$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		wp_set_object_terms( $event_id, $term_id, EEM_Sheet_Entries::TAXONOMY, true );

		wp_send_json_success( array(
			'discipline_id' => $term_id,
			'message'       => __( 'Discipline added.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX: add a draw-sheet entry (creates the mirrored result slot implicitly).
	 *
	 * @return void
	 */
	public static function ajax_add_entry(): void {
		self::guard();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		self::require_event( $event_id );

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$pdf   = isset( $_POST['drawsheet_pdf'] ) ? absint( wp_unslash( $_POST['drawsheet_pdf'] ) ) : 0;
		if ( '' === trim( $label ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a label for this file.', 'equine-event-manager' ) ), 422 );
		}
		if ( $pdf <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Choose a PDF file to upload.', 'equine-event-manager' ) ), 422 );
		}

		$id = EEM_Sheet_Entries::add_entry( array(
			'event_id'      => $event_id,
			'discipline_id' => isset( $_POST['discipline_id'] ) ? absint( wp_unslash( $_POST['discipline_id'] ) ) : 0,
			'label'         => $label,
			'round'         => isset( $_POST['round'] ) ? sanitize_key( wp_unslash( $_POST['round'] ) ) : '',
			'entry_date'    => isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : '',
			'drawsheet_pdf' => $pdf,
		) );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the file.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success( array(
			'entry_id' => $id,
			'message'  => __( 'Draw sheet added.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX: set (or clear) one PDF column on an entry — Upload Result / Replace.
	 *
	 * @return void
	 */
	public static function ajax_set_pdf(): void {
		self::guard();
		$entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;
		$which    = isset( $_POST['which'] ) && 'result' === sanitize_key( wp_unslash( $_POST['which'] ) ) ? 'result' : 'drawsheet';
		$pdf      = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;

		$entry = EEM_Sheet_Entries::get( $entry_id );
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'equine-event-manager' ) ), 404 );
		}
		self::require_event( (int) $entry['event_id'] );

		if ( ! EEM_Sheet_Entries::set_pdf( $entry_id, $which, $pdf ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the file.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success( array(
			'message' => 0 === $pdf ? __( 'File removed.', 'equine-event-manager' ) : __( 'File saved.', 'equine-event-manager' ),
		) );
	}

	/**
	 * AJAX: delete an entry entirely (both draw-sheet + result slots).
	 *
	 * @return void
	 */
	public static function ajax_delete_entry(): void {
		self::guard();
		$entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;
		$entry    = EEM_Sheet_Entries::get( $entry_id );
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'equine-event-manager' ) ), 404 );
		}
		self::require_event( (int) $entry['event_id'] );

		if ( ! EEM_Sheet_Entries::delete_entry( $entry_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete.', 'equine-event-manager' ) ), 500 );
		}
		wp_send_json_success( array( 'message' => __( 'Deleted.', 'equine-event-manager' ) ) );
	}

	/**
	 * AJAX: return the freshly-rendered body fragment (tabs + panels) + counts
	 * for an event. Used by the embedded editor section to re-render after a
	 * mutation without a full page reload (which would discard unsaved event
	 * fields).
	 *
	 * @return void
	 */
	public static function ajax_render_section(): void {
		self::guard();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		self::require_event( $event_id );
		$tab = ( isset( $_POST['tab'] ) && 'results' === sanitize_key( wp_unslash( $_POST['tab'] ) ) ) ? 'results' : 'drawsheets';

		ob_start();
		self::render_body_inner( $event_id, $tab );
		$html = (string) ob_get_clean();

		wp_send_json_success( array(
			'html'   => $html,
			'counts' => EEM_Sheet_Entries::counts( $event_id ),
		) );
	}

	/* ---- public per-event page (Screen 4) --------------------------------- */

	/**
	 * Group a discipline's entries by their date (Y-m-d), preserving order.
	 * Entries with no date collect under the '' key (rendered without a day
	 * label). Only entries carrying the requested PDF column are included.
	 *
	 * @param array  $entries Discipline entries.
	 * @param string $which   'drawsheet' (rows needing a draw-sheet PDF) or
	 *                        'result' (mirror set: rows that HAVE a draw sheet).
	 * @return array<string,array>
	 */
	private static function group_by_day( array $entries, string $which ): array {
		$days = array();
		foreach ( $entries as $e ) {
			if ( 'result' === $which ) {
				if ( $e['drawsheet_pdf'] <= 0 ) {
					continue; // result rows mirror draw sheets only.
				}
			} elseif ( $e['drawsheet_pdf'] <= 0 ) {
				continue;
			}
			$key = $e['entry_date'];
			if ( ! isset( $days[ $key ] ) ) {
				$days[ $key ] = array();
			}
			$days[ $key ][] = $e;
		}
		return $days;
	}

	/**
	 * Render the public per-event Sheets & Results page (Screen 4): navy hero
	 * with breadcrumb + status copy + tabs, then discipline groups with day
	 * labels and PDF-link rows. The Results tab shows a "Coming soon" pill for a
	 * mirrored row whose result PDF hasn't been uploaded yet.
	 *
	 * @param int $event_id Native en_event post id.
	 * @return string Empty string when the event is invalid.
	 */
	public static function render_public_page( int $event_id ): string {
		if ( $event_id <= 0 || EEM_Events::EVENT_POST_TYPE !== get_post_type( $event_id ) ) {
			return '';
		}
		$title  = get_the_title( $event_id );
		$start  = (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true );
		$end    = (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true );
		$range  = self::date_range_label( $start, $end );
		list( $life_class, $life_label ) = self::lifecycle( $start, $end );

		$venue_id   = (int) get_post_meta( $event_id, '_equine_event_manager_event_venue_id', true );
		$venue_name = $venue_id > 0 ? get_the_title( $venue_id ) : '';
		$location   = (string) get_post_meta( $event_id, '_equine_event_manager_event_location_label', true );

		$status_copy = $life_label;
		if ( 'eem-status-active' === $life_class ) {
			$status_copy = __( 'Ongoing — results update as rounds complete', 'equine-event-manager' );
		}

		$groups   = EEM_Sheet_Entries::get_for_event_grouped_by_discipline( $event_id );
		$counts   = EEM_Sheet_Entries::counts( $event_id );
		$events_url = '';
		$slug = EEM_Events::get_event_rewrite_slug();
		if ( $slug ) {
			$events_url = home_url( user_trailingslashit( $slug ) );
		}

		ob_start();
		?>
		<div class="eem-sr-public">
			<div class="eem-sr-public-hero">
				<div class="eem-sr-public-eyebrow">
					<?php if ( $events_url ) : ?><a href="<?php echo esc_url( $events_url ); ?>"><?php esc_html_e( 'Events', 'equine-event-manager' ); ?></a> / <?php endif; ?>
					<a href="<?php echo esc_url( (string) get_permalink( $event_id ) ); ?>"><?php echo esc_html( $title ); ?></a> / <?php esc_html_e( 'Sheets & Results', 'equine-event-manager' ); ?>
				</div>
				<h1 class="eem-sr-public-title"><?php echo esc_html( $title ); ?></h1>
				<div class="eem-sr-public-meta">
					<?php if ( '' !== $range ) : ?><span><?php echo esc_html( $range ); ?></span><?php endif; ?>
					<?php
					$venue_bits = array_filter( array( $venue_name, $location ) );
					if ( ! empty( $venue_bits ) ) :
						?>
						<span><?php echo esc_html( implode( ' · ', $venue_bits ) ); ?></span>
					<?php endif; ?>
					<span class="eem-sr-public-status"><?php echo esc_html( $status_copy ); ?></span>
				</div>
				<div class="eem-sr-public-tabs">
					<button type="button" class="eem-sr-public-tab is-active" data-eem-pub-tab="drawsheets"><?php esc_html_e( 'Draw Sheets', 'equine-event-manager' ); ?> <span class="eem-sr-public-tab-count"><?php echo esc_html( (string) $counts['drawsheets'] ); ?></span></button>
					<button type="button" class="eem-sr-public-tab" data-eem-pub-tab="results"><?php esc_html_e( 'Results', 'equine-event-manager' ); ?> <span class="eem-sr-public-tab-count"><?php echo esc_html( (string) $counts['results'] ); ?></span></button>
				</div>
			</div>

			<div class="eem-sr-public-body" data-eem-pub-panel="drawsheets">
				<?php self::render_public_discipline_list( $groups, 'drawsheet' ); ?>
			</div>
			<div class="eem-sr-public-body" data-eem-pub-panel="results" hidden>
				<?php self::render_public_discipline_list( $groups, 'result' ); ?>
			</div>
		</div>
		<script>
		(function () {
			var root = document.currentScript.closest('.eem-sr-public');
			if (!root) { return; }
			function show(tab) {
				root.querySelectorAll('.eem-sr-public-tab').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-eem-pub-tab') === tab); });
				root.querySelectorAll('[data-eem-pub-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-eem-pub-panel') !== tab; });
			}
			root.addEventListener('click', function (ev) {
				var t = ev.target.closest('[data-eem-pub-tab]');
				if (t) { show(t.getAttribute('data-eem-pub-tab')); }
			});
			var params = new URLSearchParams(window.location.search);
			if (params.get('tab') === 'results') { show('results'); }
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the discipline list for one public tab.
	 *
	 * @param array  $groups Grouped entries.
	 * @param string $which  'drawsheet' or 'result'.
	 * @return void
	 */
	private static function render_public_discipline_list( array $groups, string $which ): void {
		$any = false;
		foreach ( $groups as $g ) {
			$days = self::group_by_day( $g['entries'], $which );
			?>
			<div class="eem-sr-pub-disc">
				<div class="eem-sr-pub-disc-title"><?php echo esc_html( $g['discipline_name'] ); ?></div>
				<?php
				$rows_rendered = false;
				foreach ( $days as $date => $entries ) {
					if ( '' !== $date ) {
						$ts = strtotime( $date );
						if ( false !== $ts ) {
							echo '<div class="eem-sr-pub-day">' . esc_html( date_i18n( 'l, M j', $ts ) ) . '</div>';
						}
					}
					foreach ( $entries as $e ) {
						$rows_rendered = true;
						$any           = true;
						self::render_public_item( $e, $which );
					}
				}
				if ( ! $rows_rendered ) {
					$msg = 'result' === $which
						? __( 'No results posted yet. Check back as the event progresses.', 'equine-event-manager' )
						: __( 'No draw sheets posted yet. Check back closer to the event.', 'equine-event-manager' );
					echo '<div class="eem-sr-pub-empty">' . esc_html( $msg ) . '</div>';
				}
				?>
			</div>
			<?php
		}
		if ( empty( $groups ) ) {
			echo '<div class="eem-sr-pub-empty eem-sr-pub-empty--all">' . esc_html__( 'Nothing posted yet. Check back closer to the event.', 'equine-event-manager' ) . '</div>';
		}
		unset( $any );
	}

	/**
	 * Render a single public PDF-link row (or "Coming soon" pill on the Results
	 * tab when the result PDF isn't uploaded yet).
	 *
	 * @param array  $e     Entry row.
	 * @param string $which 'drawsheet' or 'result'.
	 * @return void
	 */
	private static function render_public_item( array $e, string $which ): void {
		$round = EEM_Sheet_Entries::round_label( $e['round'] );
		$name  = '' !== $e['label'] ? $e['label'] : __( '(untitled)', 'equine-event-manager' );

		if ( 'result' === $which && $e['result_pdf'] <= 0 ) {
			// Mirrored result not uploaded yet.
			$meta = '' !== $round ? $round . ' · ' . __( 'Not yet posted', 'equine-event-manager' ) : __( 'Not yet posted', 'equine-event-manager' );
			?>
			<div class="eem-sr-pub-item eem-sr-pub-item--pending">
				<div class="eem-sr-pub-item-info">
					<span class="eem-sr-pub-item-name"><?php echo esc_html( $name ); ?></span>
					<span class="eem-sr-pub-item-meta"><?php echo esc_html( $meta ); ?></span>
				</div>
				<span class="eem-sr-pub-soon"><?php esc_html_e( 'Coming soon', 'equine-event-manager' ); ?></span>
			</div>
			<?php
			return;
		}

		$pdf_id = 'result' === $which ? $e['result_pdf'] : $e['drawsheet_pdf'];
		$url    = $pdf_id > 0 ? (string) wp_get_attachment_url( $pdf_id ) : '';
		$meta   = '' !== $round ? $round . ' · PDF' : 'PDF';
		?>
		<a class="eem-sr-pub-item" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
			<div class="eem-sr-pub-item-info">
				<span class="eem-sr-pub-item-name"><?php echo esc_html( $name ); ?></span>
				<span class="eem-sr-pub-item-meta"><?php echo esc_html( $meta ); ?></span>
			</div>
			<span class="eem-sr-pub-item-arrow" aria-hidden="true">&rsaquo;</span>
		</a>
		<?php
	}
}
