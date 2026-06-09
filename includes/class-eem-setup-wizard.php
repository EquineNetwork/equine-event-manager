<?php
/**
 * First-run setup wizard — a guided, step-by-step modal that walks a brand-new
 * admin through the required configuration in order (Event Source → Branding →
 * Communications → Payments → SendGrid).
 *
 * It is the prominent first-run layer on top of the passive Dashboard checklist
 * card (EEM_Dashboard_Page::render_setup_checklist), which stays as the ongoing
 * progress reference. Both read the same live completion state from
 * EEM_Setup_Checklist, so they never disagree.
 *
 * Behavior (product decision 2.7.43):
 *   - Renders on every EEM admin page (admin_footer) while the REQUIRED areas
 *     (Event Source, Branding, Communications, Payments — SendGrid is optional)
 *     are not all complete.
 *   - Auto-opens, but intelligently: the JS reopens it only when the user has
 *     made progress since they last closed it (tracked client-side by required-
 *     done count). So it opens at first run, stays out of the way while they
 *     configure a step, then reappears to guide the next step — never spamming
 *     on every click, never covering the form they're filling in.
 *   - Once all required areas are done it stops rendering entirely.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 * @since     2.7.43
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + gates the first-run guided setup wizard modal.
 */
class EEM_Setup_Wizard {

	/**
	 * Required setup keys (SendGrid is intentionally optional — wp_mail is the
	 * fallback). Order matches EEM_Setup_Checklist::items().
	 *
	 * @var string[]
	 */
	const REQUIRED_KEYS = array( 'event_source', 'branding', 'communications', 'payments' );

	/**
	 * Hook the wizard onto the admin footer. Called once from the admin bootstrap.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_footer', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_media' ) );
	}

	/**
	 * Load the WP media library on EEM admin pages while the wizard may show, so
	 * its in-modal logo picker (Branding step) can open the uploader.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_media(): void {
		if ( self::should_render() && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Whether the wizard should render on the current request (EEM admin page,
	 * capable user, required setup not yet complete).
	 *
	 * @return bool
	 */
	public static function should_render(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( self::required_complete() ) {
			return false;
		}
		return self::is_eem_admin_page();
	}

	/**
	 * True when every REQUIRED area is configured (ignores optional SendGrid).
	 *
	 * @return int|bool
	 */
	public static function required_complete(): bool {
		return self::required_done_count() >= count( self::REQUIRED_KEYS );
	}

	/**
	 * Count of completed REQUIRED areas (for progress + the smart re-open rule).
	 *
	 * @return int
	 */
	public static function required_done_count(): int {
		$by_key = array();
		foreach ( EEM_Setup_Checklist::items() as $item ) {
			$by_key[ $item['key'] ] = ! empty( $item['done'] );
		}
		$n = 0;
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! empty( $by_key[ $key ] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Index of the first not-yet-done step (where the wizard should open). Falls
	 * back to 0 when everything shown is done.
	 *
	 * @return int
	 */
	public static function start_step_index(): int {
		$i = 0;
		foreach ( EEM_Setup_Checklist::items() as $item ) {
			if ( empty( $item['done'] ) ) {
				return $i;
			}
			$i++;
		}
		return 0;
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private static function is_eem_admin_page(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.

		// The stall-chart print view renders a standalone print document (opened in
		// its own tab) and doesn't exit, so admin_footer fires there too — but a
		// modal popping over a print page is wrong. Never show the wizard there.
		if ( 'equine-event-manager-stall-chart-print' === $page ) {
			return false;
		}

		if ( 0 === strpos( $page, 'equine-event-manager' ) ) {
			return true;
		}
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = class_exists( 'EEM_Reservations_CPT' ) ? EEM_Reservations_CPT::POST_TYPE : 'en_reservation';
		return (bool) ( $screen && $post_type === $screen->post_type );
	}

	/**
	 * admin_footer callback — render the wizard modal markup when applicable.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! self::should_render() ) {
			return;
		}
		self::render();
	}

	/**
	 * Render the wizard modal. Hidden by default; admin.js opens it (smart
	 * re-open keyed on data-eem-wizard-done-count).
	 *
	 * @return void
	 */
	public static function render(): void {
		$items      = EEM_Setup_Checklist::items();
		$total      = count( $items );
		$start      = self::start_step_index();
		$done_count = self::required_done_count();
		$required   = count( self::REQUIRED_KEYS );

		// Prefill values from live options so the fields are populated.
		$company    = (array) get_option( 'equine_event_manager_company_settings', array() );
		$logo_id    = isset( $company['logo_id'] ) ? absint( $company['logo_id'] ) : 0;
		$logo_url   = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$sup_email  = isset( $company['support_email'] ) ? (string) $company['support_email'] : (string) get_option( 'admin_email', '' );
		$sender     = class_exists( 'EEM_Settings_Repo' ) ? (array) get_option( EEM_Settings_Repo::OPTION_EMAIL_SENDER, array() ) : array();
		$from_name  = isset( $sender['from_name'] ) ? (string) $sender['from_name'] : (string) get_option( 'blogname', '' );
		$from_email = isset( $sender['from_email'] ) ? (string) $sender['from_email'] : (string) get_option( 'admin_email', '' );
		$payment    = (array) get_option( 'equine_event_manager_payment_settings', array() );
		$stripe     = isset( $payment['stripe'] ) && is_array( $payment['stripe'] ) ? $payment['stripe'] : array();
		$has_live   = '' !== trim( (string) ( $stripe['live_publishable_key'] ?? '' ) );
		$integ      = (array) get_option( 'equine_event_manager_integration_settings', array() );
		$sg_key     = isset( $integ['sendgrid_api_key'] ) ? (string) $integ['sendgrid_api_key'] : '';
		$nonce      = wp_create_nonce( 'eem_settings_save' );
		?>
		<div
			class="eem-modal eem-setup-wizard"
			id="eem-setup-wizard"
			role="dialog"
			aria-modal="true"
			aria-labelledby="eem-setup-wizard-title"
			data-eem-wizard-autoopen="1"
			data-eem-wizard-start="<?php echo esc_attr( (string) $start ); ?>"
			data-eem-wizard-done-count="<?php echo esc_attr( (string) $done_count ); ?>"
			data-eem-settings-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-eem-required="<?php echo esc_attr( (string) $required ); ?>"
		>
			<div class="eem-modal-card eem-setup-wizard__card">
				<div class="eem-modal-head">
					<h2 class="eem-modal-title" id="eem-setup-wizard-title"><?php esc_html_e( 'Welcome — let’s get you set up', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-setup-wizard__close" data-eem-action="setup-wizard-close" aria-label="<?php esc_attr_e( 'Close setup guide', 'equine-event-manager' ); ?>">&times;</button>
				</div>

				<div class="eem-setup-wizard__progress">
					<div class="eem-setup-wizard__dots">
						<?php foreach ( $items as $i => $item ) : ?>
							<button
								type="button"
								class="eem-setup-wizard__dot<?php echo ! empty( $item['done'] ) ? ' is-done' : ''; ?>"
								data-eem-action="setup-wizard-goto"
								data-step="<?php echo esc_attr( (string) $i ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: step name */ __( 'Go to %s', 'equine-event-manager' ), $item['label'] ) ); ?>"
							></button>
						<?php endforeach; ?>
					</div>
					<p class="eem-setup-wizard__count" data-eem-wizard-count>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: completed required count, 2: total required */
								__( '%1$d of %2$d required steps done', 'equine-event-manager' ),
								$done_count,
								$required
							)
						);
						?>
					</p>
				</div>

				<div class="eem-modal-body eem-setup-wizard__body">
					<?php foreach ( $items as $i => $item ) :
						$is_required = in_array( $item['key'], self::REQUIRED_KEYS, true );
						$done        = ! empty( $item['done'] );
						$panel       = in_array( $item['key'], array( 'event_source', 'sendgrid' ), true ) ? 'integrations' : $item['key'];
						?>
						<div class="eem-setup-wizard__step" data-step="<?php echo esc_attr( (string) $i ); ?>" data-eem-wizard-panel="<?php echo esc_attr( $panel ); ?>" data-eem-wizard-key="<?php echo esc_attr( $item['key'] ); ?>" <?php echo $i === $start ? '' : 'hidden'; ?>>
							<div class="eem-setup-wizard__step-head">
								<span class="eem-setup-wizard__step-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
								<h3 class="eem-setup-wizard__step-title"><?php echo esc_html( $item['label'] ); ?></h3>
								<span class="eem-setup-wizard__badge eem-setup-wizard__badge--<?php echo $done ? 'done' : ( $is_required ? 'todo' : 'optional' ); ?>" data-eem-wizard-badge>
									<?php echo esc_html( $done ? __( 'Done', 'equine-event-manager' ) : ( $is_required ? __( 'Required', 'equine-event-manager' ) : __( 'Optional', 'equine-event-manager' ) ) ); ?>
								</span>
							</div>
							<?php if ( 0 === $i ) : ?>
								<p class="eem-setup-wizard__intro"><?php esc_html_e( 'A few quick steps and you’ll be ready to take reservations. Fill each one in and click Save & Continue — we’ll save it for you. No need to leave this window.', 'equine-event-manager' ); ?></p>
							<?php endif; ?>
							<p class="eem-setup-wizard__hint"><?php echo esc_html( $item['hint'] ); ?></p>

							<div class="eem-setup-wizard__fields">
								<?php
								switch ( $item['key'] ) :
									case 'event_source':
										$eem_gems_ready = class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured();
										?>
										<label class="eem-setup-wizard__radio">
											<input type="radio" name="payload[source]" value="tec" <?php checked( ! $eem_gems_ready ); ?> />
											<span><strong><?php esc_html_e( 'The Events Calendar (TEC)', 'equine-event-manager' ); ?></strong> — <?php esc_html_e( 'recommended. Reservations link to your live TEC events.', 'equine-event-manager' ); ?></span>
										</label>
										<?php if ( $eem_gems_ready ) : ?>
											<label class="eem-setup-wizard__radio">
												<input type="radio" name="payload[source]" value="feed" <?php checked( true ); ?> />
												<span><strong><?php esc_html_e( 'GEMS Integration', 'equine-event-manager' ); ?></strong> — <?php esc_html_e( 'reservations link to your live GEMS events (via the GEMS for WordPress plugin).', 'equine-event-manager' ); ?></span>
											</label>
											<p class="eem-setup-wizard__note"><?php esc_html_e( 'Native Events is coming soon.', 'equine-event-manager' ); ?></p>
										<?php else : ?>
											<p class="eem-setup-wizard__note"><?php esc_html_e( 'Native Events and the GEMS Integration are coming soon. TEC is the active source for now. (Connect the GEMS for WordPress plugin to enable the GEMS source.)', 'equine-event-manager' ); ?></p>
										<?php endif; ?>
										<?php break;

									case 'branding': ?>
										<div class="eem-field-row">
											<label class="eem-setup-wizard__label"><?php esc_html_e( 'Logo (PNG)', 'equine-event-manager' ); ?></label>
											<div class="eem-logo-upload" data-eem-logo-upload>
												<div class="eem-logo-preview" data-eem-logo-preview>
													<?php if ( $logo_url ) : ?>
														<img src="<?php echo esc_url( $logo_url ); ?>" alt="" />
													<?php else : ?>
														<span class="eem-logo-preview-empty"><?php esc_html_e( 'No logo set', 'equine-event-manager' ); ?></span>
													<?php endif; ?>
												</div>
												<div class="eem-logo-upload-actions">
													<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="logo-pick"><?php esc_html_e( 'Choose / Upload Logo', 'equine-event-manager' ); ?></button>
													<button type="button" class="eem-btn eem-btn-danger" data-eem-action="logo-remove" <?php disabled( 0 === $logo_id ); ?>><?php esc_html_e( 'Remove', 'equine-event-manager' ); ?></button>
												</div>
												<input type="hidden" name="payload[logo_id]" value="<?php echo esc_attr( (string) $logo_id ); ?>" data-eem-logo-id />
											</div>
										</div>
										<div class="eem-field-row">
											<label class="eem-setup-wizard__label" for="eem-wiz-support-email"><?php esc_html_e( 'Support email', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" id="eem-wiz-support-email" type="email" name="payload[support_email]" value="<?php echo esc_attr( $sup_email ); ?>" style="max-width:340px;" />
										</div>
										<?php break;

									case 'communications': ?>
										<div class="eem-field-row">
											<label class="eem-setup-wizard__label" for="eem-wiz-from-name"><?php esc_html_e( 'From name', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" id="eem-wiz-from-name" type="text" name="payload[sender][from_name]" value="<?php echo esc_attr( $from_name ); ?>" style="max-width:340px;" placeholder="<?php esc_attr_e( 'e.g. RSNC', 'equine-event-manager' ); ?>" />
										</div>
										<div class="eem-field-row">
											<label class="eem-setup-wizard__label" for="eem-wiz-from-email"><?php esc_html_e( 'From email', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" id="eem-wiz-from-email" type="email" name="payload[sender][from_email]" value="<?php echo esc_attr( $from_email ); ?>" style="max-width:340px;" />
										</div>
										<?php break;

									case 'payments': ?>
										<input type="hidden" name="payload[selected_gateway]" value="stripe" />
										<div class="eem-setup-wizard__mode" role="radiogroup" aria-label="<?php esc_attr_e( 'Stripe key mode', 'equine-event-manager' ); ?>">
											<label class="eem-setup-wizard__radio"><input type="radio" name="eem_wiz_stripe_mode" value="test" <?php checked( ! $has_live ); ?> data-eem-wizard-mode /> <span><?php esc_html_e( 'Test keys (for testing)', 'equine-event-manager' ); ?></span></label>
											<label class="eem-setup-wizard__radio"><input type="radio" name="eem_wiz_stripe_mode" value="live" <?php checked( $has_live ); ?> data-eem-wizard-mode /> <span><?php esc_html_e( 'Live keys (real charges)', 'equine-event-manager' ); ?></span></label>
										</div>
										<div class="eem-field-row" data-eem-wizard-mode-fields="test" <?php echo $has_live ? 'hidden' : ''; ?>>
											<label class="eem-setup-wizard__label"><?php esc_html_e( 'Test Publishable key', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" type="text" name="payload[stripe][test_publishable_key]" value="<?php echo esc_attr( (string) ( $stripe['test_publishable_key'] ?? '' ) ); ?>" placeholder="pk_test_…" autocomplete="off" />
											<label class="eem-setup-wizard__label" style="margin-top:8px;"><?php esc_html_e( 'Test Secret key', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" type="password" name="payload[stripe][test_secret_key]" value="<?php echo esc_attr( (string) ( $stripe['test_secret_key'] ?? '' ) ); ?>" placeholder="sk_test_…" autocomplete="off" />
										</div>
										<div class="eem-field-row" data-eem-wizard-mode-fields="live" <?php echo $has_live ? '' : 'hidden'; ?>>
											<label class="eem-setup-wizard__label"><?php esc_html_e( 'Live Publishable key', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" type="text" name="payload[stripe][live_publishable_key]" value="<?php echo esc_attr( (string) ( $stripe['live_publishable_key'] ?? '' ) ); ?>" placeholder="pk_live_…" autocomplete="off" />
											<label class="eem-setup-wizard__label" style="margin-top:8px;"><?php esc_html_e( 'Live Secret key', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" type="password" name="payload[stripe][live_secret_key]" value="<?php echo esc_attr( (string) ( $stripe['live_secret_key'] ?? '' ) ); ?>" placeholder="sk_live_…" autocomplete="off" />
										</div>
										<?php break;

									case 'sendgrid': ?>
										<div class="eem-field-row">
											<label class="eem-setup-wizard__label" for="eem-wiz-sendgrid"><?php esc_html_e( 'SendGrid API key', 'equine-event-manager' ); ?></label>
											<input class="eem-field-input" id="eem-wiz-sendgrid" type="password" name="payload[sendgrid_api_key]" value="<?php echo esc_attr( $sg_key ); ?>" placeholder="SG.xxxxxxxx" autocomplete="off" />
											<p class="eem-setup-wizard__note"><?php esc_html_e( 'Optional — leave blank to use the site’s default WordPress mailer. You can add this later.', 'equine-event-manager' ); ?></p>
										</div>
										<?php break;
								endswitch;
								?>
							</div>

							<p class="eem-setup-wizard__error" data-eem-wizard-error hidden></p>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="eem-modal-foot eem-setup-wizard__foot">
					<button type="button" class="eem-btn eem-btn-secondary eem-setup-wizard__back" data-eem-action="setup-wizard-back"><?php esc_html_e( 'Back', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary eem-setup-wizard__next" data-eem-action="setup-wizard-save" data-eem-wizard-total="<?php echo esc_attr( (string) $total ); ?>"><?php esc_html_e( 'Save & Continue', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-secondary eem-setup-wizard__later" data-eem-action="setup-wizard-close"><?php esc_html_e( 'I’ll finish later', 'equine-event-manager' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
