<?php
/**
 * First-run guided setup modals for Stall (and RV) reservations.
 *
 * The first time an admin enables Stall Reservations on this site, a modal opens
 * that asks a few plain-language questions about how they run stalls, explains
 * each option, and — per Whitney's "configure + explain" decision — pre-fills the
 * real stall controls in the editor from the answers (the admin then reviews and
 * tweaks the live fields). It auto-opens ONCE per site (a per-site option flag),
 * never again; a small "Setup guide" affordance can reopen it on demand.
 *
 * The RV modal mirrors this with RV-specific questions and its own flag.
 *
 * Build note: this class renders the modal chrome + questions and exposes the
 * pending-state flag; the open/branch/apply wiring lives in assets/js/admin.js
 * (eemStallSetup*), which flips the actual editor controls so nothing is
 * persisted until the admin saves the reservation normally.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 * @since     2.7.54
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static render + AJAX flag persistence for the stall/RV first-run modals.
 */
class EEM_Stall_Setup_Wizard {

	/** Per-site option flag — set once the stall modal has been seen/completed. */
	const STALL_FLAG = 'eem_stall_setup_seen';

	/** Per-site option flag for the RV modal. */
	const RV_FLAG = 'eem_rv_setup_seen';

	/** Nonce action guarding the "mark seen" AJAX endpoint. */
	const NONCE = 'eem_stall_setup_seen';

	/**
	 * Register the admin_footer render + the AJAX endpoint.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_footer', array( __CLASS__, 'maybe_render' ) );
		add_action( 'wp_ajax_eem_stall_setup_seen', array( __CLASS__, 'ajax_mark_seen' ) );
	}

	/**
	 * Whether the current admin screen is the reservation editor page (the only
	 * place these modals are relevant).
	 *
	 * @return bool
	 */
	private static function is_editor_page(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'equine-event-manager-reservation-editor' === $page;
	}

	/**
	 * Render the stall (and RV) modals in the footer of the editor page.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! current_user_can( 'edit_posts' ) || ! self::is_editor_page() ) {
			return;
		}
		self::render_stall_modal();
	}

	/**
	 * Whether the stall modal should auto-open (flag not yet set on this site).
	 *
	 * @return bool
	 */
	public static function stall_pending(): bool {
		return ! get_option( self::STALL_FLAG, false );
	}

	/**
	 * AJAX: mark a setup modal as seen for the whole site so it never auto-opens
	 * again. Body param `which` = 'stall' | 'rv'. Cap + nonce gated.
	 *
	 * @return void
	 */
	public static function ajax_mark_seen(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : 'stall';
		update_option( 'rv' === $which ? self::RV_FLAG : self::STALL_FLAG, 1, false );
		wp_send_json_success();
	}

	/**
	 * One question step with radio-card options.
	 *
	 * @param array{key:string,title:string,sub:string,options:array<int,array{value:string,label:string,desc:string}>} $step Step config.
	 * @param int                                                                                                        $idx  Zero-based step index.
	 * @return void
	 */
	private static function render_step( array $step, int $idx ): void {
		?>
		<div class="eem-stall-setup__step" data-step="<?php echo (int) $idx; ?>" data-key="<?php echo esc_attr( $step['key'] ); ?>" <?php echo 0 === $idx ? '' : 'hidden'; ?>>
			<h3 class="eem-stall-setup__q"><?php echo esc_html( $step['title'] ); ?></h3>
			<?php if ( ! empty( $step['sub'] ) ) : ?>
				<p class="eem-stall-setup__qsub"><?php echo esc_html( $step['sub'] ); ?></p>
			<?php endif; ?>
			<div class="eem-stall-setup__options">
				<?php foreach ( $step['options'] as $oi => $opt ) : ?>
					<label class="eem-stall-setup__opt">
						<input type="radio" name="eem_stall_q_<?php echo esc_attr( $step['key'] ); ?>" value="<?php echo esc_attr( $opt['value'] ); ?>" <?php checked( 0, $oi ); ?> />
						<span class="eem-stall-setup__opt-body">
							<span class="eem-stall-setup__opt-label"><?php echo esc_html( $opt['label'] ); ?></span>
							<span class="eem-stall-setup__opt-desc"><?php echo esc_html( $opt['desc'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the stall setup modal (chrome + question steps + summary).
	 *
	 * @return void
	 */
	public static function render_stall_modal(): void {
		$steps = self::stall_steps();
		?>
		<div class="eem-modal eem-stall-setup" id="eem-stall-setup-wizard"
			data-eem-stall-setup
			data-eem-pending="<?php echo self::stall_pending() ? '1' : '0'; ?>"
			data-eem-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<div class="eem-modal-card eem-stall-setup__card">
				<div class="eem-modal-head">
					<h2 class="eem-stall-setup__title"><?php esc_html_e( 'Set up your stalls', 'equine-event-manager' ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="stall-setup-close" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</div>
				<div class="eem-modal-body eem-stall-setup__body">
					<p class="eem-stall-setup__intro"><?php esc_html_e( 'A few quick questions and we\'ll set your stall options the right way. You can change anything afterwards.', 'equine-event-manager' ); ?></p>
					<?php foreach ( $steps as $i => $step ) : ?>
						<?php self::render_step( $step, $i ); ?>
					<?php endforeach; ?>
					<div class="eem-stall-setup__step eem-stall-setup__summary" data-step="<?php echo count( $steps ); ?>" hidden>
						<h3 class="eem-stall-setup__q"><?php esc_html_e( 'Here\'s your stall setup', 'equine-event-manager' ); ?></h3>
						<p class="eem-stall-setup__qsub"><?php esc_html_e( 'We\'ll apply these to the form. Review and fine-tune the details (rates, stall rows, dates) below.', 'equine-event-manager' ); ?></p>
						<ul class="eem-stall-setup__summary-list" data-eem-stall-summary></ul>
					</div>
				</div>
				<div class="eem-modal-foot eem-stall-setup__foot">
					<button type="button" class="eem-btn eem-btn-secondary" data-eem-action="stall-setup-back" hidden><?php esc_html_e( 'Back', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-stall-setup__skip" data-eem-action="stall-setup-close"><?php esc_html_e( 'Skip — I\'ll set it up myself', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary" data-eem-action="stall-setup-next"><?php esc_html_e( 'Next', 'equine-event-manager' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The ordered stall question steps. The 'selection' step is shown only when
	 * Q1 = numbered (JS branching keys off data-key="inventory").
	 *
	 * @return array<int, array{key:string,title:string,sub:string,branch:?string,options:array}>
	 */
	private static function stall_steps(): array {
		return array(
			array(
				'key'   => 'inventory',
				'title' => __( 'Do your stalls have specific numbers?', 'equine-event-manager' ),
				'sub'   => __( 'This decides whether you manage a stall chart or just a count.', 'equine-event-manager' ),
				'options' => array(
					array( 'value' => 'quantity_only', 'label' => __( 'No — I just sell a number of stalls', 'equine-event-manager' ), 'desc' => __( 'Customers reserve "how many" with no specific stall identities. Simplest to run.', 'equine-event-manager' ) ),
					array( 'value' => 'numbered', 'label' => __( 'Yes — stalls have specific numbers/locations', 'equine-event-manager' ), 'desc' => __( 'You\'ll define your stall rows, and a stall chart is generated for assignment.', 'equine-event-manager' ) ),
				),
			),
			array(
				'key'    => 'selection',
				'title'  => __( 'How do customers choose their stalls?', 'equine-event-manager' ),
				'sub'    => __( 'Only matters when stalls are numbered.', 'equine-event-manager' ),
				'branch' => 'inventory=numbered',
				'options' => array(
					array( 'value' => 'quantity', 'label' => __( 'They just pick how many', 'equine-event-manager' ), 'desc' => __( 'You assign the actual stall numbers later from the chart.', 'equine-event-manager' ) ),
					array( 'value' => 'pick_layout', 'label' => __( 'They pick exact stalls from a map', 'equine-event-manager' ), 'desc' => __( 'Customers tap specific stalls on your layout at checkout.', 'equine-event-manager' ) ),
				),
			),
			array(
				'key'   => 'staytype',
				'title' => __( 'How do customers book stalls?', 'equine-event-manager' ),
				'sub'   => __( 'You can offer one or both pricing types.', 'equine-event-manager' ),
				'options' => array(
					array( 'value' => 'nightly', 'label' => __( 'Per night', 'equine-event-manager' ), 'desc' => __( 'Charged per night of the stay.', 'equine-event-manager' ) ),
					array( 'value' => 'weekend', 'label' => __( 'Weekend package', 'equine-event-manager' ), 'desc' => __( 'One flat rate for a weekend package window.', 'equine-event-manager' ) ),
					array( 'value' => 'both', 'label' => __( 'Both', 'equine-event-manager' ), 'desc' => __( 'Offer nightly and weekend; the customer chooses.', 'equine-event-manager' ) ),
				),
			),
			array(
				'key'   => 'shavings',
				'title' => __( 'Do you require shavings with each stall?', 'equine-event-manager' ),
				'sub'   => '',
				'options' => array(
					array( 'value' => 'no', 'label' => __( 'No', 'equine-event-manager' ), 'desc' => __( 'No mandatory shavings charge.', 'equine-event-manager' ) ),
					array( 'value' => 'yes', 'label' => __( 'Yes — add a required shavings charge', 'equine-event-manager' ), 'desc' => __( 'A shavings fee is added to every stall. Set the price below after setup.', 'equine-event-manager' ) ),
				),
			),
			array(
				'key'   => 'schedule',
				'title' => __( 'Do stall reservations open and close on set dates?', 'equine-event-manager' ),
				'sub'   => '',
				'options' => array(
					array( 'value' => 'no', 'label' => __( 'No — always open', 'equine-event-manager' ), 'desc' => __( 'Customers can reserve any time the reservation is live.', 'equine-event-manager' ) ),
					array( 'value' => 'yes', 'label' => __( 'Yes — on specific dates/times', 'equine-event-manager' ), 'desc' => __( 'Reservations open and close on a schedule you set below.', 'equine-event-manager' ) ),
				),
			),
		);
	}
}
