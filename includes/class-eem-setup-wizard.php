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
					<p class="eem-setup-wizard__count">
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
						?>
						<div class="eem-setup-wizard__step" data-step="<?php echo esc_attr( (string) $i ); ?>" <?php echo $i === $start ? '' : 'hidden'; ?>>
							<div class="eem-setup-wizard__step-head">
								<span class="eem-setup-wizard__step-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
								<h3 class="eem-setup-wizard__step-title"><?php echo esc_html( $item['label'] ); ?></h3>
								<?php if ( $done ) : ?>
									<span class="eem-setup-wizard__badge eem-setup-wizard__badge--done"><?php esc_html_e( 'Done', 'equine-event-manager' ); ?></span>
								<?php elseif ( $is_required ) : ?>
									<span class="eem-setup-wizard__badge eem-setup-wizard__badge--todo"><?php esc_html_e( 'Required', 'equine-event-manager' ); ?></span>
								<?php else : ?>
									<span class="eem-setup-wizard__badge eem-setup-wizard__badge--optional"><?php esc_html_e( 'Optional', 'equine-event-manager' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( 0 === $i ) : ?>
								<p class="eem-setup-wizard__intro"><?php esc_html_e( 'A few quick steps and you’ll be ready to take reservations. We’ll walk you through each one in order — start here.', 'equine-event-manager' ); ?></p>
							<?php endif; ?>
							<p class="eem-setup-wizard__hint"><?php echo esc_html( $item['hint'] ); ?></p>
							<a class="eem-btn <?php echo $done ? 'eem-btn-secondary' : 'eem-btn-primary'; ?> eem-setup-wizard__cta" data-eem-action="setup-wizard-cta" href="<?php echo esc_url( $item['url'] ); ?>">
								<?php
								echo $done
									? esc_html( sprintf( /* translators: %s: step name */ __( 'Review %s', 'equine-event-manager' ), $item['label'] ) )
									: esc_html( sprintf( /* translators: %s: step name */ __( 'Set up %s →', 'equine-event-manager' ), $item['label'] ) );
								?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="eem-modal-foot eem-setup-wizard__foot">
					<button type="button" class="eem-btn eem-btn-secondary eem-setup-wizard__back" data-eem-action="setup-wizard-back"><?php esc_html_e( 'Back', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-primary eem-setup-wizard__next" data-eem-action="setup-wizard-next" data-eem-wizard-total="<?php echo esc_attr( (string) $total ); ?>"><?php esc_html_e( 'Next', 'equine-event-manager' ); ?></button>
					<button type="button" class="eem-btn eem-btn-secondary eem-setup-wizard__later" data-eem-action="setup-wizard-close"><?php esc_html_e( 'I’ll finish later', 'equine-event-manager' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
