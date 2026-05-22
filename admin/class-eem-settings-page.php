<?php
/**
 * Settings page controller (SET-1).
 *
 * Renders the six-panel Settings screen using the C1 page shell + breadcrumb
 * partials. Panel routing comes from $_GET['panel'] (defaulting to
 * 'integrations'); each panel renders via a private method named
 * render_<id>_panel().
 *
 * **C3.A scope:** ships the shell + left nav + panel-dispatch skeleton.
 * Every panel renders a "Coming soon" stub (C3.B fills Communications;
 * C3.C fills the rest). The menu callback continues to point at the
 * legacy EEM_Admin::render_settings_page() through C3.A–C; the swap
 * happens in C3.D so the live admin keeps working during build-up.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Settings page (port of the legacy 668-line render_settings_page).
 */
class EEM_Settings_Page {

	/** WP admin menu slug — matches the legacy registration in EEM_Admin::register_menu. */
	const MENU_SLUG = 'equine-event-manager-settings';

	/** Default panel when ?panel= is missing or invalid. */
	const DEFAULT_PANEL = 'integrations';

	/**
	 * Ordered panel registry: id → array{ label, icon }.
	 *
	 * @return array<string, array{label:string, icon:string}>
	 */
	public static function panels() {
		return array(
			'integrations'   => array( 'label' => __( 'Integrations', 'equine-event-manager' ),    'icon' => 'admin-plugins' ),
			'branding'       => array( 'label' => __( 'Branding', 'equine-event-manager' ),        'icon' => 'art' ),
			'communications' => array( 'label' => __( 'Communications', 'equine-event-manager' ),  'icon' => 'email-alt' ),
			'shortcodes'     => array( 'label' => __( 'Shortcodes', 'equine-event-manager' ),      'icon' => 'editor-code' ),
			'payments'       => array( 'label' => __( 'Payments', 'equine-event-manager' ),        'icon' => 'money-alt' ),
			'addons'         => array( 'label' => __( 'Add-Ons', 'equine-event-manager' ),         'icon' => 'admin-plugins' ),
		);
	}

	/**
	 * Render the Settings page. Wired into the admin menu callback in C3.D —
	 * until then, this method is called only by the C3.A smoke test.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$active = $this->active_panel();
		$panels = self::panels();

		eem_render_page_open( array(
			'title'      => __( 'Settings', 'equine-event-manager' ),
			'breadcrumb' => array(
				array( 'label' => __( 'Settings', 'equine-event-manager' ) ),
			),
		) );

		?>
		<div class="eem-settings">
			<aside class="eem-settings-nav" role="navigation" aria-label="<?php esc_attr_e( 'Settings sections', 'equine-event-manager' ); ?>">
				<ul>
					<?php foreach ( $panels as $id => $panel ) :
						$classes = 'eem-settings-nav-item' . ( $id === $active ? ' is-active' : '' );
						?>
						<li>
							<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $this->panel_url( $id ) ); ?>">
								<span class="dashicons dashicons-<?php echo esc_attr( $panel['icon'] ); ?>" aria-hidden="true"></span>
								<span><?php echo esc_html( $panel['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</aside>

			<section class="eem-settings-panel" data-eem-panel="<?php echo esc_attr( $active ); ?>">
				<?php $this->render_panel( $active ); ?>
			</section>
		</div>
		<?php

		eem_render_page_close();
	}

	/**
	 * Resolve the active panel id from $_GET, falling back to DEFAULT_PANEL.
	 *
	 * @return string
	 */
	private function active_panel() {
		$requested = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : '';
		return isset( self::panels()[ $requested ] ) ? $requested : self::DEFAULT_PANEL;
	}

	/**
	 * Build the admin URL for a panel id (preserves the page slug, swaps ?panel).
	 *
	 * @param string $panel_id
	 * @return string
	 */
	private function panel_url( $panel_id ) {
		return add_query_arg(
			array(
				'page'  => self::MENU_SLUG,
				'panel' => $panel_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Dispatch a panel render to its per-panel method.
	 * Each method is render_<id>_panel(); missing methods fall through to the stub.
	 *
	 * @param string $panel_id
	 * @return void
	 */
	private function render_panel( $panel_id ) {
		$method = 'render_' . $panel_id . '_panel';
		if ( method_exists( $this, $method ) ) {
			$this->$method();
		} else {
			$this->render_panel_stub( $panel_id );
		}
	}

	/**
	 * Placeholder block for panels that haven't been ported yet (C3.A through
	 * C3.C-1 phase). Communicates progress without exposing broken UI.
	 *
	 * @param string $panel_id
	 * @return void
	 */
	private function render_panel_stub( $panel_id ) {
		$panels = self::panels();
		$label  = isset( $panels[ $panel_id ] ) ? $panels[ $panel_id ]['label'] : $panel_id;
		?>
		<div class="eem-card">
			<div class="eem-card-header">
				<h2 class="eem-card-title"><?php echo esc_html( $label ); ?></h2>
			</div>
			<div class="eem-card-body">
				<p><?php esc_html_e( 'This panel is being rebuilt as part of the Phase 3 mockup port. The legacy Settings page is still available — the new layout will replace it once all panels are ported.', 'equine-event-manager' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────────────────────────────────────────
	 * Per-panel render methods. All stubs in C3.A; later chunks
	 * replace these one-by-one with real markup.
	 *
	 *   C3.B  → render_communications_panel
	 *   C3.C  → render_integrations_panel, render_branding_panel,
	 *           render_payments_panel, render_shortcodes_panel,
	 *           render_addons_panel
	 * ───────────────────────────────────────────────────────────── */

	private function render_integrations_panel()   { $this->render_panel_stub( 'integrations' ); }
	private function render_branding_panel()       { $this->render_panel_stub( 'branding' ); }
	private function render_communications_panel() { $this->render_panel_stub( 'communications' ); }
	private function render_shortcodes_panel()     { $this->render_panel_stub( 'shortcodes' ); }
	private function render_payments_panel()       { $this->render_panel_stub( 'payments' ); }
	private function render_addons_panel()         { $this->render_panel_stub( 'addons' ); }
}
