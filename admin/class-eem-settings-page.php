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
	 * Conditional enqueue for Settings-page-specific assets. Hooked in the
	 * main loader on admin_enqueue_scripts; fires only when the current
	 * admin screen is our Settings page so we don't load TinyMCE on every
	 * admin page.
	 *
	 * Loads:
	 *   - WP's bundled TinyMCE via wp_enqueue_editor() so the wp.editor
	 *     JS API is available on the client for lazy per-card init.
	 *
	 * @param string $hook_suffix Current admin screen hook (passed by WP).
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix = '' ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		// Loads tinymce + wp-tinymce + the wp.editor JS API surface.
		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		// Loads wp.media — required by the Branding panel's logo picker.
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
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

	/**
	 * Add-Ons panel (settings_page mockup tab-panel:#panel-addons).
	 *
	 * Future-expansion slot. Empty state copy now; toggles will land here
	 * as add-on packages ship. No save button — nothing to persist yet.
	 *
	 * @return void
	 */
	private function render_addons_panel() {
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php esc_html_e( 'Add-On Access', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<p class="eem-field-hint" style="margin-bottom:14px;">
					<?php esc_html_e( 'Future Equine Event Manager add-ons will appear here so you can enable and configure them from one place.', 'equine-event-manager' ); ?>
				</p>
				<div class="eem-empty-state">
					<div class="eem-empty-state-title"><?php esc_html_e( 'No add-ons available yet', 'equine-event-manager' ); ?></div>
					<div class="eem-empty-state-desc"><?php esc_html_e( 'This tab is reserved for upcoming expansion modules. When add-ons ship, you\'ll see their toggles and configuration here.', 'equine-event-manager' ); ?></div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Shortcodes panel (settings_page mockup tab-panel:#panel-shortcodes).
	 *
	 * Read-only reference list of registered shortcodes. Each row shows the
	 * shortcode + a one-line description; clicking the code box copies it
	 * to the clipboard (reusing the placeholder-copy action from C3.B.3 —
	 * data-eem-action="placeholder-copy" + data-eem-value).
	 *
	 * No save button — nothing to persist.
	 *
	 * @return void
	 */
	private function render_shortcodes_panel() {
		$shortcodes = array(
			array(
				'label' => __( 'Reservation Form', 'equine-event-manager' ),
				'code'  => '[en_reservation id="123"]',
				'hint'  => __( 'Displays a reservation form by post ID. Useful in builder pages, landing pages, or manual reservation links.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'Current Event Page', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_event]',
				'hint'  => __( 'Renders the current event using the shared single-event layout. Works on native event pages, TEC event pages, and reservation-backed routes.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'Single Event by Event ID', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_event id="123"]',
				'hint'  => __( 'Targets a native or TEC event post directly by its post ID.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'Single Event by Reservation', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_event reservation="123"]',
				'hint'  => __( 'Displays a reservation-backed event source (Event Feed or External) using the same shared template.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'All Events — List View', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_events view="list"]',
				'hint'  => __( 'Displays a mixed-source event list using the Event Manager card layout.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'All Events — Calendar View', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_events view="calendar"]',
				'hint'  => __( 'Same mixed event collection in a calendar view.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'Filter by Source', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_events view="list" source="native,tec"]',
				'hint'  => __( 'Comma-separated source filter: native, tec, feed, or external.', 'equine-event-manager' ),
			),
			array(
				'label' => __( 'Lock to a Specific Month', 'equine-event-manager' ),
				'code'  => '[equine_event_manager_events view="calendar" month="2026-05"]',
				'hint'  => __( 'Use the month attribute in YYYY-MM format to lock the calendar to a specific month.', 'equine-event-manager' ),
			),
		);
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php esc_html_e( 'Event Display Shortcodes', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<p class="eem-field-hint" style="margin-bottom:14px;">
					<?php esc_html_e( 'Use these in any page builder, classic editor, or theme content area. The plugin handles event source resolution and shared layout for you. Click any code box to copy.', 'equine-event-manager' ); ?>
				</p>

				<?php foreach ( $shortcodes as $row ) : ?>
					<div class="eem-shortcode-row">
						<div class="eem-shortcode-label"><?php echo esc_html( $row['label'] ); ?></div>
						<div class="eem-shortcode-body">
							<button type="button" class="eem-code-box" data-eem-action="placeholder-copy" data-eem-value="<?php echo esc_attr( $row['code'] ); ?>" title="<?php esc_attr_e( 'Click to copy', 'equine-event-manager' ); ?>">
								<?php echo esc_html( $row['code'] ); ?>
							</button>
							<p class="eem-field-hint"><?php echo esc_html( $row['hint'] ); ?></p>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Payments panel (settings_page mockup tab-panel:#panel-payments).
	 *
	 * Four sections, one form:
	 *   1. Tax Rate (apply + default_rate + label) — uses EEM_Settings_Repo from C3.A
	 *   2. Active Payment Processor (mutually-exclusive picker: stripe / authorize_net)
	 *   3. Stripe Connection (mode + 4 keys + webhook signing secret + test btn)
	 *   4. Authorize.net Connection (mode + 4 credentials + test btn)
	 *
	 * Reads equine_event_manager_payment_settings option directly. Save dispatch
	 * in C3.C.6 (which extends the existing communications/payments dispatcher
	 * branch with the new stripe + authorize_net + processor subkeys).
	 *
	 * Credential rows render via render_credential_field() helper to keep
	 * the body lean — 11 credential fields would otherwise be 11×15 lines
	 * of repeated markup.
	 *
	 * @return void
	 */
	private function render_payments_panel() {
		$tax     = EEM_Settings_Repo::get_tax();
		$payment = wp_parse_args(
			get_option( 'equine_event_manager_payment_settings', array() ),
			array(
				'selected_gateway' => 'stripe',
				'stripe'           => array(),
				'authorize_net'    => array(),
			)
		);
		$payment['stripe']        = wp_parse_args( $payment['stripe'], array(
			'mode'                   => 'test',
			'test_publishable_key'   => '',
			'test_secret_key'        => '',
			'live_publishable_key'   => '',
			'live_secret_key'        => '',
			'webhook_signing_secret' => '',
		) );
		$payment['authorize_net'] = wp_parse_args( $payment['authorize_net'], array(
			'mode'                 => 'test',
			'test_api_login'       => '',
			'test_transaction_key' => '',
			'live_api_login'       => '',
			'live_transaction_key' => '',
		) );

		$active = in_array( $payment['selected_gateway'], array( 'stripe', 'authorize_net' ), true ) ? $payment['selected_gateway'] : 'stripe';
		?>
		<form class="eem-settings-form" data-eem-settings-form data-eem-panel="payments" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="eem_save_settings" />
			<input type="hidden" name="panel" value="payments" />
			<?php wp_nonce_field( 'eem_settings_save', 'nonce' ); ?>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Tax Rate', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<p class="eem-field-hint" style="margin-bottom:14px;">
						<?php esc_html_e( 'Default sales tax applied at checkout. Each reservation can override this in its own settings (Edit Reservation, ported in C7).', 'equine-event-manager' ); ?>
					</p>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-tax-apply"><?php esc_html_e( 'Apply Tax', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<label class="eem-checkbox-row">
								<input type="checkbox" id="eem-tax-apply" name="payload[tax][apply]" value="1" <?php checked( $tax['apply'] ); ?> />
								<span><?php esc_html_e( 'Charge sales tax on orders', 'equine-event-manager' ); ?></span>
							</label>
							<p class="eem-field-hint"><?php esc_html_e( 'Uncheck if you handle tax outside the plugin. Disabling hides tax lines from checkout and receipts.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-tax-rate"><?php esc_html_e( 'Default Tax Rate', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<div class="eem-price-wrap" style="max-width:180px;">
								<input class="eem-price-input" id="eem-tax-rate" type="number" step="0.01" min="0" max="100" name="payload[tax][default_rate]" value="<?php echo esc_attr( $tax['default_rate'] ); ?>" />
								<span class="eem-price-symbol" style="border-left:none;border-right:1.5px solid var(--eem-border);border-radius:0 var(--eem-radius) var(--eem-radius) 0;">%</span>
							</div>
							<p class="eem-field-hint"><?php esc_html_e( 'Applied to all reservations unless overridden. Shown as a line item on checkout and receipts.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-tax-label"><?php esc_html_e( 'Tax Label', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<input class="eem-field-input" id="eem-tax-label" type="text" name="payload[tax][label]" value="<?php echo esc_attr( $tax['label'] ); ?>" style="max-width:280px;" />
							<p class="eem-field-hint"><?php esc_html_e( 'How tax appears on checkout and receipts (e.g. "Sales Tax", "VAT", "GST").', 'equine-event-manager' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Active Payment Processor', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<p class="eem-field-hint" style="margin-bottom:14px;">
						<?php esc_html_e( 'Customers check out using the active processor only. You can configure both below and switch at any time.', 'equine-event-manager' ); ?>
					</p>
					<div class="eem-source-group" data-eem-source-group>
						<?php
						$processors = array(
							'stripe'        => array(
								'title' => __( 'Stripe', 'equine-event-manager' ),
								'desc'  => __( 'Modern card processor with built-in webhooks. Recommended for most setups.', 'equine-event-manager' ),
							),
							'authorize_net' => array(
								'title' => __( 'Authorize.net', 'equine-event-manager' ),
								'desc'  => __( 'Legacy processor for merchants with existing Authorize.net accounts. Refunds and capture both supported.', 'equine-event-manager' ),
							),
						);
						foreach ( $processors as $value => $row ) :
							$checked = ( $value === $active );
							?>
							<label class="eem-source-row<?php echo $checked ? ' is-selected' : ''; ?>" data-eem-source-value="<?php echo esc_attr( $value ); ?>">
								<input type="radio" name="payload[selected_gateway]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?> />
								<span class="eem-source-radio" aria-hidden="true"></span>
								<span class="eem-source-row-body">
									<span class="eem-source-row-head">
										<span class="eem-source-row-title"><?php echo esc_html( $row['title'] ); ?></span>
										<span class="eem-source-status <?php echo $checked ? 'is-active' : 'is-info'; ?>"><?php echo esc_html( $checked ? __( 'Active', 'equine-event-manager' ) : __( 'Inactive', 'equine-event-manager' ) ); ?></span>
									</span>
									<span class="eem-source-row-desc"><?php echo esc_html( $row['desc'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Stripe Connection', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<?php
					$this->render_credential_mode_row( 'stripe-mode', 'payload[stripe][mode]', $payment['stripe']['mode'] );
					$this->render_credential_field( array( 'id' => 'stripe-test-pub',     'name' => 'payload[stripe][test_publishable_key]',   'label' => __( 'Test Publishable Key', 'equine-event-manager' ), 'value' => $payment['stripe']['test_publishable_key'] ) );
					$this->render_credential_field( array( 'id' => 'stripe-test-secret', 'name' => 'payload[stripe][test_secret_key]',        'label' => __( 'Test Secret Key', 'equine-event-manager' ),      'value' => $payment['stripe']['test_secret_key'], 'type' => 'password' ) );
					$this->render_credential_field( array( 'id' => 'stripe-live-pub',     'name' => 'payload[stripe][live_publishable_key]',   'label' => __( 'Live Publishable Key', 'equine-event-manager' ), 'value' => $payment['stripe']['live_publishable_key'] ) );
					$this->render_credential_field( array( 'id' => 'stripe-live-secret', 'name' => 'payload[stripe][live_secret_key]',        'label' => __( 'Live Secret Key', 'equine-event-manager' ),      'value' => $payment['stripe']['live_secret_key'], 'type' => 'password' ) );
					$this->render_credential_field( array(
						'id'    => 'stripe-webhook',
						'name'  => 'payload[stripe][webhook_signing_secret]',
						'label' => __( 'Webhook Signing Secret', 'equine-event-manager' ),
						'value' => $payment['stripe']['webhook_signing_secret'],
						'type'  => 'password',
						'hint'  => __( 'Found in your Stripe Dashboard under Developers → Webhooks. Required to verify payment events.', 'equine-event-manager' ),
					) );
					?>
				</div>
			</section>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Authorize.net Connection', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<?php
					$this->render_credential_mode_row( 'authnet-mode', 'payload[authorize_net][mode]', $payment['authorize_net']['mode'] );
					$this->render_credential_field( array( 'id' => 'authnet-test-login', 'name' => 'payload[authorize_net][test_api_login]',       'label' => __( 'Test API Login ID', 'equine-event-manager' ),     'value' => $payment['authorize_net']['test_api_login'] ) );
					$this->render_credential_field( array( 'id' => 'authnet-test-key',   'name' => 'payload[authorize_net][test_transaction_key]', 'label' => __( 'Test Transaction Key', 'equine-event-manager' ),  'value' => $payment['authorize_net']['test_transaction_key'], 'type' => 'password' ) );
					$this->render_credential_field( array( 'id' => 'authnet-live-login', 'name' => 'payload[authorize_net][live_api_login]',       'label' => __( 'Live API Login ID', 'equine-event-manager' ),     'value' => $payment['authorize_net']['live_api_login'] ) );
					$this->render_credential_field( array( 'id' => 'authnet-live-key',   'name' => 'payload[authorize_net][live_transaction_key]', 'label' => __( 'Live Transaction Key', 'equine-event-manager' ),  'value' => $payment['authorize_net']['live_transaction_key'], 'type' => 'password' ) );
					?>
				</div>
			</section>

			<div class="eem-settings-save-bar">
				<button type="submit" class="eem-btn eem-btn-primary">
					<?php esc_html_e( 'Save Payment Settings', 'equine-event-manager' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render a single credential input row (Stripe / Authorize.net keys).
	 *
	 * @param array $args { id, name, label, value, type?, hint? }
	 * @return void
	 */
	private function render_credential_field( array $args ) {
		$args = wp_parse_args( $args, array( 'type' => 'text', 'hint' => '' ) );
		?>
		<div class="eem-field-row">
			<label class="eem-field-label" for="eem-<?php echo esc_attr( $args['id'] ); ?>"><?php echo esc_html( $args['label'] ); ?></label>
			<div class="eem-field-control">
				<input class="eem-field-input" id="eem-<?php echo esc_attr( $args['id'] ); ?>" type="<?php echo esc_attr( $args['type'] ); ?>" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $args['value'] ); ?>" autocomplete="off" />
				<?php if ( '' !== $args['hint'] ) : ?>
					<p class="eem-field-hint"><?php echo esc_html( $args['hint'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Mode select row (Test / Live) used by both processor sub-sections.
	 *
	 * @param string $id    Element id suffix.
	 * @param string $name  Form name (e.g. payload[stripe][mode]).
	 * @param string $value Current value ('test' or 'live').
	 * @return void
	 */
	private function render_credential_mode_row( $id, $name, $value ) {
		$value = in_array( $value, array( 'test', 'live' ), true ) ? $value : 'test';
		?>
		<div class="eem-field-row">
			<label class="eem-field-label" for="eem-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Mode', 'equine-event-manager' ); ?></label>
			<div class="eem-field-control">
				<select class="eem-field-select" id="eem-<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" style="max-width:160px;">
					<option value="test" <?php selected( 'test', $value ); ?>><?php esc_html_e( 'Test', 'equine-event-manager' ); ?></option>
					<option value="live" <?php selected( 'live', $value ); ?>><?php esc_html_e( 'Live', 'equine-event-manager' ); ?></option>
				</select>
			</div>
		</div>
		<?php
	}

	/**
	 * Branding panel (settings_page mockup tab-panel:#panel-branding).
	 *
	 * Three fields, one section:
	 *   - Business Logo (WP media library upload, attachment id stored)
	 *   - Support Phone
	 *   - Support Email
	 *
	 * Reads/writes equine_event_manager_company_settings option directly
	 * (existing key, populated by the legacy admin). Save dispatch in C3.C.6.
	 *
	 * Logo upload uses WP's wp.media library — JS in C3.C.6 wires the
	 * Upload button. The hidden input carries the attachment id; the
	 * preview img refreshes from the selected attachment URL on pick.
	 *
	 * @return void
	 */
	private function render_branding_panel() {
		$company = wp_parse_args(
			get_option( 'equine_event_manager_company_settings', array() ),
			array(
				'logo_id'       => 0,
				'support_phone' => '',
				'support_email' => get_option( 'admin_email', '' ),
			)
		);

		$logo_id  = absint( $company['logo_id'] );
		$logo_url = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<form class="eem-settings-form" data-eem-settings-form data-eem-panel="branding" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="eem_save_settings" />
			<input type="hidden" name="panel" value="branding" />
			<?php wp_nonce_field( 'eem_settings_save', 'nonce' ); ?>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Branding', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<p class="eem-field-hint" style="margin-bottom:14px;">
						<?php esc_html_e( 'Branding details used on receipts, PDFs, and customer-facing communications.', 'equine-event-manager' ); ?>
					</p>

					<div class="eem-field-row">
						<span class="eem-field-label"><?php esc_html_e( 'Business Logo', 'equine-event-manager' ); ?></span>
						<div class="eem-field-control">
							<div class="eem-logo-upload" data-eem-logo-upload>
								<div class="eem-logo-preview" data-eem-logo-preview>
									<?php if ( $logo_url ) : ?>
										<img src="<?php echo esc_url( $logo_url ); ?>" alt="" />
									<?php else : ?>
										<span class="eem-logo-preview-empty"><?php esc_html_e( 'No logo set', 'equine-event-manager' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="eem-logo-upload-actions">
									<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="logo-pick">
										<?php esc_html_e( 'Upload Logo', 'equine-event-manager' ); ?>
									</button>
									<button type="button" class="eem-btn eem-btn-danger" data-eem-action="logo-remove" <?php disabled( 0 === $logo_id ); ?>>
										<?php esc_html_e( 'Remove', 'equine-event-manager' ); ?>
									</button>
								</div>
								<input type="hidden" name="payload[logo_id]" value="<?php echo esc_attr( $logo_id ); ?>" data-eem-logo-id />
							</div>
							<p class="eem-field-hint">
								<?php esc_html_e( 'Used on printable PDF receipts and email receipt branding. Not used in the plugin admin header. PNG with a transparent background works best.', 'equine-event-manager' ); ?>
							</p>
						</div>
					</div>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-brand-phone"><?php esc_html_e( 'Support Phone', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<input class="eem-field-input" id="eem-brand-phone" type="tel" name="payload[support_phone]" value="<?php echo esc_attr( $company['support_phone'] ); ?>" style="max-width:280px;" />
							<p class="eem-field-hint"><?php esc_html_e( 'General support number. Reservation-specific messaging can override it in the Communications panel.', 'equine-event-manager' ); ?></p>
						</div>
					</div>

					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-brand-email"><?php esc_html_e( 'Support Email', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<input class="eem-field-input" id="eem-brand-email" type="email" name="payload[support_email]" value="<?php echo esc_attr( $company['support_email'] ); ?>" style="max-width:320px;" />
							<p class="eem-field-hint"><?php esc_html_e( 'Default support email across messaging and receipt settings.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<div class="eem-settings-save-bar">
				<button type="submit" class="eem-btn eem-btn-primary">
					<?php esc_html_e( 'Save Branding Settings', 'equine-event-manager' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Integrations panel (CLAUDE.md "In-scope features → Event source"
	 * + settings_page mockup tab-panel:#panel-integrations).
	 *
	 * Two sections in one form:
	 *   1. Event Source — mutually-exclusive picker (Native / TEC / Feed)
	 *      + per-source detail block (only the picked source's detail visible)
	 *   2. Email Delivery — SendGrid API key (optional override of the WP mailer)
	 *
	 * Reads the existing equine_event_manager_integration_settings + ..._feature_settings
	 * options directly. Save dispatch lands in EEM_Settings_Page::save_integrations_panel
	 * (C3.C.6) — until then the Save button submits but the integrations branch of
	 * handle_ajax_save_settings still returns 501.
	 *
	 * @return void
	 */
	private function render_integrations_panel() {
		$integration = wp_parse_args(
			get_option( 'equine_event_manager_integration_settings', array() ),
			array(
				'default_event_source' => 'feed',
				'feed_url'             => '',
				'tec_event_category'   => '',
				'sendgrid_api_key'     => '',
			)
		);

		$source       = sanitize_key( $integration['default_event_source'] );
		if ( ! in_array( $source, array( 'native', 'tec', 'feed' ), true ) ) {
			$source = 'feed';
		}
		$tec_active   = class_exists( 'Tribe__Events__Main' );
		$tec_status   = $tec_active
			? array( 'class' => 'is-active',    'label' => __( 'Available', 'equine-event-manager' ) )
			: array( 'class' => 'is-warning',   'label' => __( 'Plugin not active', 'equine-event-manager' ) );
		?>
		<form class="eem-settings-form" data-eem-settings-form data-eem-panel="integrations" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="eem_save_settings" />
			<input type="hidden" name="panel" value="integrations" />
			<?php wp_nonce_field( 'eem_settings_save', 'nonce' ); ?>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Event Source', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<p class="eem-field-hint" style="margin-bottom:14px;">
						<?php esc_html_e( 'Choose where reservations get their events from. Only one source can be active at a time.', 'equine-event-manager' ); ?>
					</p>

					<div class="eem-source-group" data-eem-source-group>
						<?php
						$sources = array(
							'native' => array(
								'title'  => __( 'Native Events', 'equine-event-manager' ),
								'desc'   => __( 'Use Equine Event Manager as the main event system with native events, categories, venues, producers, widgets, and the shared frontend event template.', 'equine-event-manager' ),
								'status' => array( 'class' => 'is-info', 'label' => __( 'Built-in', 'equine-event-manager' ) ),
							),
							'tec' => array(
								'title'  => __( 'The Events Calendar (TEC)', 'equine-event-manager' ),
								'desc'   => __( 'When the TEC plugin is active, reservations can search and link to live TEC events directly.', 'equine-event-manager' ),
								'status' => $tec_status,
							),
							'feed' => array(
								'title'  => __( 'External Feed URL', 'equine-event-manager' ),
								'desc'   => __( 'Pull events from an external JSON or XML endpoint. Reservations inherit the feed URL automatically when this source is active.', 'equine-event-manager' ),
								'status' => array( 'class' => 'is-info', 'label' => __( 'Available', 'equine-event-manager' ) ),
							),
						);
						foreach ( $sources as $value => $row ) :
							$checked = ( $value === $source );
							?>
							<label class="eem-source-row<?php echo $checked ? ' is-selected' : ''; ?>" data-eem-source-value="<?php echo esc_attr( $value ); ?>">
								<input type="radio" name="payload[source]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?> />
								<span class="eem-source-radio" aria-hidden="true"></span>
								<span class="eem-source-row-body">
									<span class="eem-source-row-head">
										<span class="eem-source-row-title"><?php echo esc_html( $row['title'] ); ?></span>
										<span class="eem-source-status <?php echo esc_attr( $row['status']['class'] ); ?>"><?php echo esc_html( $row['status']['label'] ); ?></span>
									</span>
									<span class="eem-source-row-desc"><?php echo esc_html( $row['desc'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="eem-source-detail" data-eem-source-detail="native" <?php if ( 'native' !== $source ) { echo 'hidden'; } ?>>
						<div class="eem-source-detail-title"><?php esc_html_e( 'Native Events Settings', 'equine-event-manager' ); ?></div>
						<p class="eem-field-hint">
							<?php
							printf(
								/* translators: %s: admin URL for Native Events */
								wp_kses( __( 'Native events are managed under <a href="%s">EEM → Events</a> in the admin sidebar. Producers, venues, and categories are managed there too.', 'equine-event-manager' ), array( 'a' => array( 'href' => array() ) ) ),
								esc_url( admin_url( 'edit.php?post_type=en_event' ) )
							);
							?>
						</p>
					</div>

					<div class="eem-source-detail" data-eem-source-detail="tec" <?php if ( 'tec' !== $source ) { echo 'hidden'; } ?>>
						<div class="eem-source-detail-title"><?php esc_html_e( 'The Events Calendar Connection', 'equine-event-manager' ); ?></div>
						<div class="eem-field-row">
							<label class="eem-field-label" for="eem-tec-category"><?php esc_html_e( 'Event Category Filter', 'equine-event-manager' ); ?></label>
							<div class="eem-field-control">
								<input class="eem-field-input" id="eem-tec-category" type="text" name="payload[tec_event_category]" value="<?php echo esc_attr( $integration['tec_event_category'] ); ?>" placeholder="e.g. equestrian" style="max-width:300px;" />
								<p class="eem-field-hint"><?php esc_html_e( 'Optional. Only TEC events with this category will be searchable when linking to a reservation. Leave blank to allow all events.', 'equine-event-manager' ); ?></p>
							</div>
						</div>
						<div class="eem-field-row">
							<span class="eem-field-label"><?php esc_html_e( 'Connection Status', 'equine-event-manager' ); ?></span>
							<div class="eem-field-control">
								<span class="eem-source-status <?php echo esc_attr( $tec_status['class'] ); ?>"><?php echo esc_html( $tec_status['label'] ); ?></span>
								<span style="margin-left:8px;font-size:13px;color:var(--eem-text);">
									<?php
									echo esc_html( $tec_active
										? __( 'TEC plugin detected and responding.', 'equine-event-manager' )
										: __( 'TEC plugin not detected. Install + activate The Events Calendar to use this source.', 'equine-event-manager' )
									);
									?>
								</span>
							</div>
						</div>
					</div>

					<div class="eem-source-detail" data-eem-source-detail="feed" <?php if ( 'feed' !== $source ) { echo 'hidden'; } ?>>
						<div class="eem-source-detail-title"><?php esc_html_e( 'External Feed URL', 'equine-event-manager' ); ?></div>
						<div class="eem-field-row">
							<label class="eem-field-label" for="eem-feed-url"><?php esc_html_e( 'Feed URL', 'equine-event-manager' ); ?></label>
							<div class="eem-field-control">
								<input class="eem-field-input" id="eem-feed-url" type="url" name="payload[feed_url]" value="<?php echo esc_attr( $integration['feed_url'] ); ?>" placeholder="https://example.com/events.json" />
								<p class="eem-field-hint"><?php esc_html_e( 'JSON or XML event endpoints are both supported. Use the Test Feed URL button to verify the response.', 'equine-event-manager' ); ?></p>
							</div>
						</div>
						<div class="eem-field-row">
							<span class="eem-field-label"><?php esc_html_e( 'Test Connection', 'equine-event-manager' ); ?></span>
							<div class="eem-field-control">
								<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="test-feed-url"><?php esc_html_e( 'Test Feed URL', 'equine-event-manager' ); ?></button>
								<p class="eem-field-hint"><?php esc_html_e( 'Fetches the feed and validates the response structure. Saves nothing.', 'equine-event-manager' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="eem-card">
				<header class="eem-card-header">
					<h2 class="eem-card-title"><?php esc_html_e( 'Email Delivery', 'equine-event-manager' ); ?></h2>
				</header>
				<div class="eem-card-body">
					<p class="eem-field-hint" style="margin-bottom:14px;">
						<?php esc_html_e( 'Optionally route customer receipts and payment-link emails through SendGrid instead of the default WordPress mailer.', 'equine-event-manager' ); ?>
					</p>
					<div class="eem-field-row">
						<label class="eem-field-label" for="eem-sendgrid"><?php esc_html_e( 'SendGrid API Key', 'equine-event-manager' ); ?></label>
						<div class="eem-field-control">
							<input class="eem-field-input" id="eem-sendgrid" type="password" name="payload[sendgrid_api_key]" value="<?php echo esc_attr( $integration['sendgrid_api_key'] ); ?>" placeholder="SG.xxxxxxxxxxxxxxxxxxxx" autocomplete="off" />
							<p class="eem-field-hint"><?php esc_html_e( 'When set, EEM will use SendGrid for customer receipt + payment-link invoice emails. Leave blank to keep using the site\'s default WordPress mail configuration.', 'equine-event-manager' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<div class="eem-settings-save-bar">
				<button type="submit" class="eem-btn eem-btn-primary">
					<?php esc_html_e( 'Save Integrations Settings', 'equine-event-manager' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Communications panel (SET-2, SET-3, SET-5, SET-7). The largest single
	 * panel — three concerns wrapped in one form:
	 *   1. Email Sender Settings (master toggle + BCC + sender identity)
	 *   2. Email Templates (placeholder reference + 5 collapsible cards)
	 *   3. Policies (Cancellation + Terms textareas)
	 *
	 * Form POSTs (via JS wired in C3.B.3) to admin-ajax.php
	 * action=eem_save_settings with panel=communications and a nested
	 * payload[] structure mirroring the repo APIs. Until C3.B.3 ships the
	 * JS submit handler, the Save button renders but is inert.
	 *
	 * Template-card bodies use plain textareas in C3.B.1; C3.B.2 upgrades
	 * them to TinyMCE instances.
	 *
	 * @return void
	 */
	private function render_communications_panel() {
		$sender    = EEM_Settings_Repo::get_email_sender();
		$templates = EEM_Email_Templates_Repo::all();
		$policies  = EEM_Settings_Repo::get_policies();
		$placeholders = EEM_Email_Templates_Repo::placeholders();
		?>
		<form class="eem-settings-form" data-eem-settings-form data-eem-panel="communications" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="eem_save_settings" />
			<input type="hidden" name="panel" value="communications" />
			<?php wp_nonce_field( 'eem_settings_save', 'nonce' ); ?>

			<?php $this->render_communications_sender_section( $sender ); ?>
			<?php $this->render_communications_templates_section( $templates, $placeholders ); ?>
			<?php $this->render_communications_policies_section( $policies ); ?>

			<div class="eem-settings-save-bar">
				<button type="submit" class="eem-btn eem-btn-primary">
					<?php esc_html_e( 'Save Communications Settings', 'equine-event-manager' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Email Sender Settings (SET-7) — 5 fields per the settings_page mockup:
	 * master send toggle, BCC, From Name, From Email, Reply-To.
	 *
	 * @param array $sender Row from EEM_Settings_Repo::get_email_sender().
	 * @return void
	 */
	private function render_communications_sender_section( array $sender ) {
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php esc_html_e( 'Email Sender Settings', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<p class="eem-field-hint" style="margin-bottom:14px;">
					<?php esc_html_e( 'Sender identity and routing for all transactional emails. Applies to every template below.', 'equine-event-manager' ); ?>
				</p>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-sender-send"><?php esc_html_e( 'Send Customer Emails', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<label class="eem-checkbox-row">
							<input type="checkbox" id="eem-sender-send" name="payload[sender][send_customer_emails]" value="1" <?php checked( $sender['send_customer_emails'] ); ?> />
							<span><?php esc_html_e( 'Send transactional emails to customers (receipts, reminders, etc.)', 'equine-event-manager' ); ?></span>
						</label>
					</div>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-sender-bcc"><?php esc_html_e( 'Admin Copy Email', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<input class="eem-field-input" id="eem-sender-bcc" type="email" name="payload[sender][admin_copy_email]" value="<?php echo esc_attr( $sender['admin_copy_email'] ); ?>" placeholder="admin@example.com" />
						<p class="eem-field-hint"><?php esc_html_e( 'Optional. Sends an internal copy of every customer email to this address.', 'equine-event-manager' ); ?></p>
					</div>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-sender-name"><?php esc_html_e( 'From Name', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<input class="eem-field-input" id="eem-sender-name" type="text" name="payload[sender][from_name]" value="<?php echo esc_attr( $sender['from_name'] ); ?>" />
					</div>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-sender-from"><?php esc_html_e( 'From Email', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<input class="eem-field-input" id="eem-sender-from" type="email" name="payload[sender][from_email]" value="<?php echo esc_attr( $sender['from_email'] ); ?>" />
					</div>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-sender-reply"><?php esc_html_e( 'Reply-To Email', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<input class="eem-field-input" id="eem-sender-reply" type="email" name="payload[sender][reply_to]" value="<?php echo esc_attr( $sender['reply_to'] ); ?>" />
						<p class="eem-field-hint"><?php esc_html_e( 'Where replies land. Often the same as From Email, or a monitored support inbox.', 'equine-event-manager' ); ?></p>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Email Templates section (SET-2, SET-3). Renders the placeholder
	 * reference chip strip, then one collapsible card per template id.
	 *
	 * Card bodies in C3.B.1 are simple textareas — they POST clean HTML
	 * already (sanitization happens repo-side via wp_kses_post). C3.B.2
	 * replaces the body textarea with a TinyMCE instance for the editor
	 * experience the mockup specifies.
	 *
	 * @param array $templates    Result of EEM_Email_Templates_Repo::all().
	 * @param array $placeholders Result of EEM_Email_Templates_Repo::placeholders().
	 * @return void
	 */
	private function render_communications_templates_section( array $templates, array $placeholders ) {
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php esc_html_e( 'Email Templates', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<p class="eem-field-hint" style="margin-bottom:14px;">
					<?php
					echo wp_kses_post(
						__( 'Edit the subject and body for each transactional email. Use placeholders like <code>{{customer_name}}</code> to insert dynamic content. Click any chip below to copy the placeholder.', 'equine-event-manager' )
					);
					?>
				</p>

				<div class="eem-placeholder-reference">
					<div class="eem-placeholder-ref-title">
						<?php esc_html_e( 'Available placeholders', 'equine-event-manager' ); ?>
						<span class="eem-placeholder-ref-hint"><?php esc_html_e( 'Click to copy', 'equine-event-manager' ); ?></span>
					</div>
					<div class="eem-placeholder-chips">
						<?php foreach ( $placeholders as $token => $description ) :
							$value = '{{' . $token . '}}';
							?>
							<button type="button" class="eem-placeholder-chip" data-eem-action="placeholder-copy" data-eem-value="<?php echo esc_attr( $value ); ?>" title="<?php echo esc_attr( $description ); ?>">
								<?php echo esc_html( $value ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="eem-template-cards">
					<?php foreach ( EEM_Email_Templates_Repo::ids() as $template_id ) :
						$this->render_communications_template_card( $template_id, $templates[ $template_id ] );
					endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * One template card. Collapsible head + subject input + body editor +
	 * Send-test action row.
	 *
	 * @param string $template_id One of EEM_Email_Templates_Repo's id constants.
	 * @param array  $template    { subject, body }
	 * @return void
	 */
	private function render_communications_template_card( $template_id, array $template ) {
		$label   = EEM_Email_Templates_Repo::label( $template_id );
		$desc    = EEM_Email_Templates_Repo::description( $template_id );
		$subj_id = 'eem-tmpl-subject-' . $template_id;
		$body_id = 'eem-tmpl-body-' . $template_id;
		?>
		<article class="eem-template-card" data-eem-template-id="<?php echo esc_attr( $template_id ); ?>">
			<header class="eem-template-card-head" data-eem-action="template-toggle">
				<div class="eem-template-card-head-text">
					<div class="eem-template-card-title"><?php echo esc_html( $label ); ?></div>
					<?php if ( '' !== $desc ) : ?>
						<div class="eem-template-card-sub"><?php echo esc_html( $desc ); ?></div>
					<?php endif; ?>
				</div>
				<span class="eem-template-card-chevron" aria-hidden="true">▾</span>
			</header>
			<div class="eem-template-card-body">
				<div class="eem-field-row">
					<label class="eem-field-label" for="<?php echo esc_attr( $subj_id ); ?>"><?php esc_html_e( 'Subject', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<input class="eem-field-input" id="<?php echo esc_attr( $subj_id ); ?>" type="text" name="payload[templates][<?php echo esc_attr( $template_id ); ?>][subject]" value="<?php echo esc_attr( $template['subject'] ); ?>" />
					</div>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="<?php echo esc_attr( $body_id ); ?>"><?php esc_html_e( 'Body', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<textarea class="eem-field-textarea eem-template-body" id="<?php echo esc_attr( $body_id ); ?>" name="payload[templates][<?php echo esc_attr( $template_id ); ?>][body]" rows="10" data-eem-tinymce-target><?php echo esc_textarea( $template['body'] ); ?></textarea>
						<p class="eem-field-hint"><?php esc_html_e( 'Plain textarea in C3.B.1 — TinyMCE rich editor lands in C3.B.2.', 'equine-event-manager' ); ?></p>
					</div>
				</div>

				<div class="eem-template-card-actions">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="send-test-email" data-eem-template-id="<?php echo esc_attr( $template_id ); ?>">
						<?php esc_html_e( 'Send test email to me', 'equine-event-manager' ); ?>
					</button>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * Policies section (SET-5): Cancellation Policy + Terms & Conditions
	 * textareas. Both sanitize via wp_kses_post on save.
	 *
	 * @param array $policies Result of EEM_Settings_Repo::get_policies().
	 * @return void
	 */
	private function render_communications_policies_section( array $policies ) {
		?>
		<section class="eem-card">
			<header class="eem-card-header">
				<h2 class="eem-card-title"><?php esc_html_e( 'Policies', 'equine-event-manager' ); ?></h2>
			</header>
			<div class="eem-card-body">
				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-policy-cancel"><?php esc_html_e( 'Cancellation Policy', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<textarea class="eem-field-textarea" id="eem-policy-cancel" name="payload[policies][cancellation]" rows="6"><?php echo esc_textarea( $policies['cancellation'] ); ?></textarea>
						<p class="eem-field-hint"><?php esc_html_e( 'Shown at checkout and inserted into the Cancellation email template via the {{cancellation_policy}} placeholder.', 'equine-event-manager' ); ?></p>
					</div>
				</div>

				<div class="eem-field-row">
					<label class="eem-field-label" for="eem-policy-terms"><?php esc_html_e( 'Terms &amp; Conditions', 'equine-event-manager' ); ?></label>
					<div class="eem-field-control">
						<textarea class="eem-field-textarea" id="eem-policy-terms" name="payload[policies][terms]" rows="8"><?php echo esc_textarea( $policies['terms'] ); ?></textarea>
						<p class="eem-field-hint"><?php esc_html_e( 'Shown at checkout; customer must acknowledge before paying.', 'equine-event-manager' ); ?></p>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/* ─────────────────────────────────────────────────────────────
	 * Save dispatcher (AJAX)
	 *
	 * One endpoint (wp_ajax_eem_save_settings) accepts a `panel` param +
	 * a nested `payload` array. Per-panel save methods dispatch the payload
	 * to the right repo. JSON response on success/failure with a message
	 * the JS layer feeds into EEM.showSaveToast().
	 *
	 * Nonce action: `eem_settings_save` (one per page load — checked here).
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Top-level AJAX handler. Validates auth + nonce, then routes to the
	 * panel-specific save method. Always exits via wp_send_json_*.
	 *
	 * @return void
	 */
	public function handle_ajax_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to save these settings.', 'equine-event-manager' ) ), 403 );
		}

		check_ajax_referer( 'eem_settings_save', 'nonce' );

		$panel   = isset( $_POST['panel'] ) ? sanitize_key( wp_unslash( $_POST['panel'] ) ) : '';
		$payload = isset( $_POST['payload'] ) && is_array( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : array();

		if ( ! isset( self::panels()[ $panel ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown settings panel.', 'equine-event-manager' ) ), 400 );
		}

		$errors = array();
		switch ( $panel ) {
			case 'communications':
				$errors = $this->save_communications_panel( $payload );
				break;

			case 'payments':
				$errors = $this->save_payments_panel( $payload );
				break;

			case 'integrations':
				$errors = $this->save_integrations_panel( $payload );
				break;

			case 'branding':
				$errors = $this->save_branding_panel( $payload );
				break;

			case 'shortcodes':
			case 'addons':
				// Both are read-only panels (Shortcodes is a reference list,
				// Add-Ons is a future-expansion placeholder). Submitting either
				// is a no-op success so the JS submit handler still gets a
				// well-formed response.
				break;

			default:
				/* translators: 1: panel label */
				$message = sprintf( __( 'Saving the %s panel is not wired yet.', 'equine-event-manager' ), self::panels()[ $panel ]['label'] );
				wp_send_json_error( array( 'message' => $message ), 501 );
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error( array(
				'message' => __( 'Some settings could not be saved.', 'equine-event-manager' ),
				'errors'  => $errors,
			), 422 );
		}

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'equine-event-manager' ) ) );
	}

	/**
	 * Communications save — dispatches sender / templates / policies to their repos.
	 * Returns an array of failed-write keys; empty means everything saved.
	 *
	 * @param array $payload Expected shape: [ sender => [...], templates => [...], policies => [...] ]
	 * @return array<int, string>
	 */
	private function save_communications_panel( array $payload ) {
		$errors = array();

		if ( isset( $payload['sender'] ) && is_array( $payload['sender'] ) ) {
			if ( ! EEM_Settings_Repo::update_email_sender( $payload['sender'] ) ) {
				$errors[] = 'sender';
			}
		}

		if ( isset( $payload['templates'] ) && is_array( $payload['templates'] ) ) {
			if ( ! EEM_Email_Templates_Repo::update_all( $payload['templates'] ) ) {
				$errors[] = 'templates';
			}
		}

		if ( isset( $payload['policies'] ) && is_array( $payload['policies'] ) ) {
			if ( ! EEM_Settings_Repo::update_policies( $payload['policies'] ) ) {
				$errors[] = 'policies';
			}
		}

		return $errors;
	}

	/**
	 * Payments save — dispatches Tax to EEM_Settings_Repo and persists the
	 * selected gateway + Stripe + Authorize.net credentials to the existing
	 * equine_event_manager_payment_settings option.
	 *
	 * @param array $payload Expected: { tax: {...}, selected_gateway: 'stripe'|'authorize_net', stripe: {...}, authorize_net: {...} }
	 * @return array<int, string>
	 */
	private function save_payments_panel( array $payload ) {
		$errors = array();

		if ( isset( $payload['tax'] ) && is_array( $payload['tax'] ) ) {
			if ( ! EEM_Settings_Repo::update_tax( $payload['tax'] ) ) {
				$errors[] = 'tax';
			}
		}

		$current = wp_parse_args(
			get_option( 'equine_event_manager_payment_settings', array() ),
			array( 'selected_gateway' => 'stripe', 'stripe' => array(), 'authorize_net' => array() )
		);

		$gateway = isset( $payload['selected_gateway'] ) ? sanitize_key( $payload['selected_gateway'] ) : '';
		if ( in_array( $gateway, array( 'stripe', 'authorize_net' ), true ) ) {
			$current['selected_gateway'] = $gateway;
		}

		if ( isset( $payload['stripe'] ) && is_array( $payload['stripe'] ) ) {
			$current['stripe'] = $this->sanitize_credential_group( $payload['stripe'], array(
				'mode'                   => 'mode',
				'test_publishable_key'   => 'text',
				'test_secret_key'        => 'text',
				'live_publishable_key'   => 'text',
				'live_secret_key'        => 'text',
				'webhook_signing_secret' => 'text',
			) );
		}

		if ( isset( $payload['authorize_net'] ) && is_array( $payload['authorize_net'] ) ) {
			$current['authorize_net'] = $this->sanitize_credential_group( $payload['authorize_net'], array(
				'mode'                 => 'mode',
				'test_api_login'       => 'text',
				'test_transaction_key' => 'text',
				'live_api_login'       => 'text',
				'live_transaction_key' => 'text',
			) );
		}

		if ( ! update_option( 'equine_event_manager_payment_settings', $current, false ) ) {
			// update_option returns false when the value is unchanged — not an
			// actual failure. Only count it as an error if the stored value
			// genuinely doesn't match what we tried to save.
			$readback = get_option( 'equine_event_manager_payment_settings', array() );
			if ( $readback !== $current ) {
				$errors[] = 'payment_settings';
			}
		}

		return $errors;
	}

	/**
	 * Integrations save — Event source picker + per-source fields +
	 * SendGrid key. All keys live in equine_event_manager_integration_settings.
	 * The native_events_enabled feature flag is mirrored from the picker
	 * value so the "Events" sidebar item appears/hides correctly per
	 * CLAUDE.md In-scope-features → Event-source.
	 *
	 * @param array $payload Expected: { source: 'native'|'tec'|'feed', tec_event_category, feed_url, sendgrid_api_key }
	 * @return array<int, string>
	 */
	private function save_integrations_panel( array $payload ) {
		$errors = array();

		$current = wp_parse_args(
			get_option( 'equine_event_manager_integration_settings', array() ),
			array( 'default_event_source' => 'feed', 'feed_url' => '', 'tec_event_category' => '', 'sendgrid_api_key' => '', 'tec_integration_enabled' => 1 )
		);

		$source = isset( $payload['source'] ) ? sanitize_key( $payload['source'] ) : '';
		if ( ! in_array( $source, array( 'native', 'tec', 'feed' ), true ) ) {
			$source = $current['default_event_source'];
		}
		$current['default_event_source']    = $source;
		$current['tec_integration_enabled'] = ( 'tec' === $source ) ? 1 : 0;
		$current['feed_url']                = isset( $payload['feed_url'] ) ? esc_url_raw( (string) $payload['feed_url'] ) : '';
		$current['tec_event_category']      = isset( $payload['tec_event_category'] ) ? sanitize_text_field( (string) $payload['tec_event_category'] ) : '';
		$current['sendgrid_api_key']        = isset( $payload['sendgrid_api_key'] ) ? sanitize_text_field( (string) $payload['sendgrid_api_key'] ) : '';

		if ( false === update_option( 'equine_event_manager_integration_settings', $current, false ) && get_option( 'equine_event_manager_integration_settings' ) !== $current ) {
			$errors[] = 'integration_settings';
		}

		// Mirror to feature_settings so EEM_Events::is_native_events_enabled()
		// resolves consistently with the picker (sidebar visibility).
		$features = wp_parse_args( get_option( 'equine_event_manager_feature_settings', array() ), array( 'native_events_enabled' => 0 ) );
		$features['native_events_enabled'] = ( 'native' === $source ) ? 1 : 0;
		update_option( 'equine_event_manager_feature_settings', $features, false );

		return $errors;
	}

	/**
	 * Branding save — logo attachment id + support phone/email.
	 * Writes to the existing equine_event_manager_company_settings option.
	 *
	 * @param array $payload Expected: { logo_id, support_phone, support_email }
	 * @return array<int, string>
	 */
	private function save_branding_panel( array $payload ) {
		$errors = array();

		$current = wp_parse_args(
			get_option( 'equine_event_manager_company_settings', array() ),
			array( 'logo_id' => 0, 'support_phone' => '', 'support_email' => '' )
		);
		$current['logo_id']       = isset( $payload['logo_id'] )       ? absint( $payload['logo_id'] )                       : 0;
		$current['support_phone'] = isset( $payload['support_phone'] ) ? sanitize_text_field( $payload['support_phone'] )    : '';
		$current['support_email'] = isset( $payload['support_email'] ) ? sanitize_email( $payload['support_email'] )         : '';

		if ( false === update_option( 'equine_event_manager_company_settings', $current, false ) && get_option( 'equine_event_manager_company_settings' ) !== $current ) {
			$errors[] = 'company_settings';
		}

		return $errors;
	}

	/**
	 * Sanitize a credential group (Stripe or Authorize.net) by field-type
	 * whitelist. Unknown payload keys are dropped; missing keys become empty
	 * strings. 'mode' fields clamp to 'test'|'live'.
	 *
	 * @param array               $input    Raw POST payload.
	 * @param array<string,string> $schema   field_name => 'text'|'mode'
	 * @return array<string, string>
	 */
	private function sanitize_credential_group( array $input, array $schema ) {
		$out = array();
		foreach ( $schema as $field => $type ) {
			$value = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
			if ( 'mode' === $type ) {
				$out[ $field ] = in_array( $value, array( 'test', 'live' ), true ) ? $value : 'test';
			} else {
				$out[ $field ] = sanitize_text_field( $value );
			}
		}
		return $out;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Send-test-email (SET-4)
	 *
	 * Per-template "Send test email to me" button hits
	 * wp_ajax_eem_send_test_email. Server renders the chosen template
	 * with sample placeholder values, sends via wp_mail() to the current
	 * admin's email using the Sender repo settings as From / Reply-To
	 * headers. JSON response feeds EEM.showSaveToast on the client.
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * AJAX endpoint — render a template with sample values and email it to
	 * the current admin user.
	 *
	 * @return void  Always exits via wp_send_json_*.
	 */
	public function handle_ajax_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to send test emails.', 'equine-event-manager' ) ), 403 );
		}

		check_ajax_referer( 'eem_settings_save', 'nonce' );

		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		if ( ! in_array( $template_id, EEM_Email_Templates_Repo::ids(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown template.', 'equine-event-manager' ) ), 400 );
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->user_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Your WordPress account has no email address on file.', 'equine-event-manager' ) ), 422 );
		}

		$template = EEM_Email_Templates_Repo::get( $template_id );
		$sample   = $this->build_sample_placeholder_values( $user );
		$subject  = $this->apply_placeholders( $template['subject'], $sample );
		$body     = $this->apply_placeholders( $template['body'], $sample );

		$sender  = EEM_Settings_Repo::get_email_sender();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $sender['from_email'] ) {
			$from_name = '' !== $sender['from_name'] ? $sender['from_name'] : $sender['from_email'];
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $sender['from_email'] );
		}
		if ( '' !== $sender['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $sender['reply_to'];
		}

		$test_subject = sprintf(
			/* translators: %s: template label */
			__( '[TEST] %s', 'equine-event-manager' ),
			$subject
		);

		$sent = wp_mail( $user->user_email, $test_subject, $body, $headers );

		if ( ! $sent ) {
			wp_send_json_error( array(
				'message' => __( 'wp_mail returned false. Check your site\'s mail configuration (SMTP plugin, mail server, etc.).', 'equine-event-manager' ),
			), 500 );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: admin email address */
				__( 'Test email sent to %s.', 'equine-event-manager' ),
				$user->user_email
			),
		) );
	}

	/**
	 * Build the sample-value map used to render placeholders in a test email.
	 * Pulls real values where the system has them (sender from settings,
	 * policy text from settings, user display name) and fills the rest with
	 * representative dummies so the admin sees what the rendered email will
	 * look like in production.
	 *
	 * @param WP_User $user Current admin (recipient of the test).
	 * @return array<string, string>
	 */
	private function build_sample_placeholder_values( $user ) {
		$sender   = EEM_Settings_Repo::get_email_sender();
		$policies = EEM_Settings_Repo::get_policies();

		return array(
			'customer_name'       => $user->display_name,
			'event_name'          => __( 'Sample Show — Spring Classic', 'equine-event-manager' ),
			'event_venue'         => __( 'Sample Equestrian Center', 'equine-event-manager' ),
			'event_address'       => __( '123 Show Lane, Anywhere, USA', 'equine-event-manager' ),
			'event_dates'         => __( 'March 15–17, 2026', 'equine-event-manager' ),
			'order_number'        => '#0001',
			'total'               => '$285.00',
			'balance'             => '$0.00',
			'payment_link'        => admin_url( 'admin.php?page=equine-event-manager-orders' ),
			'stall_assignments'   => __( 'Barn A · Stalls 12, 13', 'equine-event-manager' ),
			'support_phone'       => '555-555-0100',
			'support_email'       => '' !== $sender['from_email'] ? $sender['from_email'] : get_option( 'admin_email' ),
			'cancellation_policy' => '' !== $policies['cancellation'] ? wp_strip_all_tags( $policies['cancellation'] ) : __( '(Cancellation policy not set — configure in Settings → Communications → Policies.)', 'equine-event-manager' ),
		);
	}

	/**
	 * Replace {{token}} occurrences in a string with values from a map.
	 * Unknown tokens are left in place — admins notice and either remove
	 * them or extend the placeholder whitelist.
	 *
	 * @param string                $template String containing zero or more {{tokens}}.
	 * @param array<string, string> $values   token => replacement.
	 * @return string
	 */
	private function apply_placeholders( $template, array $values ) {
		$replacements = array();
		foreach ( $values as $token => $value ) {
			$replacements[ '{{' . $token . '}}' ] = (string) $value;
		}
		return strtr( (string) $template, $replacements );
	}
}
