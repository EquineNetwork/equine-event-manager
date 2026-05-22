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
	private function render_shortcodes_panel()     { $this->render_panel_stub( 'shortcodes' ); }
	private function render_payments_panel()       { $this->render_panel_stub( 'payments' ); }
	private function render_addons_panel()         { $this->render_panel_stub( 'addons' ); }

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
				<span class="eem-settings-save-hint">
					<?php esc_html_e( 'Saving is wired in C3.B.3 — this button currently has no effect.', 'equine-event-manager' ); ?>
				</span>
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
			case 'branding':
			case 'shortcodes':
			case 'addons':
			default:
				/* translators: 1: panel label */
				$message = sprintf( __( 'Saving the %s panel is not wired yet — coming in a later sub-chunk.', 'equine-event-manager' ), self::panels()[ $panel ]['label'] );
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
	 * Payments save — dispatches tax to its repo. Stripe / Authorize.net
	 * credential persistence lands in C3.C alongside the panel UI.
	 *
	 * @param array $payload Expected shape: [ tax => [...] ]
	 * @return array<int, string>
	 */
	private function save_payments_panel( array $payload ) {
		$errors = array();

		if ( isset( $payload['tax'] ) && is_array( $payload['tax'] ) ) {
			if ( ! EEM_Settings_Repo::update_tax( $payload['tax'] ) ) {
				$errors[] = 'tax';
			}
		}

		return $errors;
	}
}
