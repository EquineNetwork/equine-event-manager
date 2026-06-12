<?php
/**
 * Notifications admin page (v2 Notifications, Slice 2).
 *
 * Event Manager → Notifications. Pick an event, build an audience
 * (Include − Exclude + Payment), see a live recipient count, compose, and send.
 * Recipient resolution + segment math live in {@see EEM_Notifications} (Slice 1);
 * the batched send pipeline + history land in Slice 3.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + wires the Notifications page.
 */
class EEM_Notifications_Page {

	/** @var string Visible submenu slug under the Event Manager parent. */
	const MENU_SLUG = 'equine-event-manager-notifications';

	/** @var int Recipients sent per AJAX batch (keeps each request well under timeout). */
	const BATCH_SIZE = 25;

	/** @var string Transient key prefix for an in-flight send job. */
	const JOB_PREFIX = 'eem_notif_job_';

	/**
	 * Bootstrap: register the menu route (admin_menu @30 — after the parent
	 * menu exists @20, before the submenu re-order @1001) + AJAX handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_route' ), 30 );
		add_action( 'wp_ajax_eem_notifications_event_meta', array( __CLASS__, 'ajax_event_meta' ) );
		add_action( 'wp_ajax_eem_notifications_count', array( __CLASS__, 'ajax_count' ) );
		add_action( 'wp_ajax_eem_notifications_send_start', array( __CLASS__, 'ajax_send_start' ) );
		add_action( 'wp_ajax_eem_notifications_send_step', array( __CLASS__, 'ajax_send_step' ) );
	}

	/**
	 * Add the visible "Notifications" submenu under Event Manager.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		add_submenu_page(
			'equine-event-manager',
			__( 'Notifications', 'equine-event-manager' ),
			__( 'Notifications', 'equine-event-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Events available to notify — published reservations that have at least one
	 * order, newest first. Returns [reservation_id => label].
	 *
	 * @return array<int,string>
	 */
	private static function notifiable_events(): array {
		$events = array();
		$posts  = get_posts( array(
			'post_type'      => EEM_Reservations_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		foreach ( $posts as $p ) {
			$events[ (int) $p->ID ] = get_the_title( $p );
		}
		return $events;
	}

	/**
	 * Render the Notifications page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}
		require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/_page_shell.php';

		$events   = self::notifiable_events();
		$segments = EEM_Notifications::segment_options();
		$nonce    = wp_create_nonce( 'eem_notifications' );

		eem_render_page_open( array(
			'title'      => __( 'Notifications', 'equine-event-manager' ),
			'subtitle'   => __( 'Email customers for an event. Pick the event, build an audience, then compose and send.', 'equine-event-manager' ),
			'breadcrumb' => array( array( 'label' => __( 'Notifications', 'equine-event-manager' ) ) ),
			'wrap'       => true,
		) );
		?>
		<div class="eem-notifications" data-eem-notifications data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( empty( $events ) ) : ?>
				<p class="eem-table-empty"><?php esc_html_e( 'No events yet. Create a reservation first.', 'equine-event-manager' ); ?></p>
			<?php else : ?>
				<div class="eem-notif-grid">
					<div class="eem-notif-field">
						<label class="eem-field-label" for="eem-notif-event"><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></label>
						<select class="eem-field-select" id="eem-notif-event" data-eem-notif-event data-eem-choices data-eem-choices-search="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>">
							<option value=""><?php esc_html_e( 'Select an event…', 'equine-event-manager' ); ?></option>
							<?php foreach ( $events as $eid => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $eid ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="eem-notif-audience" data-eem-notif-audience hidden>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-include"><?php esc_html_e( 'Send to', 'equine-event-manager' ); ?></label>
							<select class="eem-field-select" id="eem-notif-include" data-eem-notif-include>
								<?php foreach ( $segments as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-exclude"><?php esc_html_e( 'But not', 'equine-event-manager' ); ?></label>
							<select class="eem-field-select" id="eem-notif-exclude" data-eem-notif-exclude>
								<option value=""><?php esc_html_e( '— no exclusion —', 'equine-event-manager' ); ?></option>
								<?php foreach ( $segments as $key => $label ) : ?>
									<?php if ( 'all' === $key ) { continue; } ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-payment"><?php esc_html_e( 'Payment', 'equine-event-manager' ); ?></label>
							<select class="eem-field-select" id="eem-notif-payment" data-eem-notif-payment>
								<option value="all"><?php esc_html_e( 'Any', 'equine-event-manager' ); ?></option>
								<option value="paid"><?php esc_html_e( 'Paid only', 'equine-event-manager' ); ?></option>
								<option value="unpaid"><?php esc_html_e( 'Unpaid only', 'equine-event-manager' ); ?></option>
							</select>
						</div>
						<p class="eem-notif-count" data-eem-notif-count>
							<span class="eem-status-badge eem-status-active" data-eem-notif-count-badge>0</span>
							<span data-eem-notif-count-label><?php esc_html_e( 'recipients', 'equine-event-manager' ); ?></span>
						</p>
					</div>

					<div class="eem-notif-compose" data-eem-notif-compose hidden>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-subject"><?php esc_html_e( 'Subject', 'equine-event-manager' ); ?></label>
							<input class="eem-field-input" id="eem-notif-subject" type="text" maxlength="200" data-eem-notif-subject />
						</div>
						<div class="eem-notif-field">
							<label class="eem-field-label" for="eem-notif-body"><?php esc_html_e( 'Message', 'equine-event-manager' ); ?></label>
							<textarea class="eem-field-input eem-field-textarea" id="eem-notif-body" rows="9" data-eem-notif-body></textarea>
						</div>
						<div class="eem-notif-actions">
							<button type="button" class="eem-btn eem-btn-electric" data-eem-action="notifications-send"><?php esc_html_e( 'Send Notification', 'equine-event-manager' ); ?></button>
							<span class="eem-notif-send-status" data-eem-notif-status></span>
						</div>
					</div>
				</div>

				<div class="eem-notif-history" data-eem-notif-history>
					<h2 class="eem-notif-history-title"><?php esc_html_e( 'Recent notifications', 'equine-event-manager' ); ?></h2>
					<?php self::render_history(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		eem_render_page_close( array( 'wrap' => true ) );
	}

	/**
	 * Render the recent-notifications history table (Slice 3 fills the data;
	 * Slice 2 ships the container + empty state).
	 *
	 * @return void
	 */
	private static function render_history(): void {
		$entries = class_exists( 'EEM_Activity_Log' )
			? EEM_Activity_Log::get_recent_by_type( EEM_Activity_Log::NOTIFICATION_SENT, 25 )
			: array();
		if ( empty( $entries ) ) {
			echo '<p class="eem-table-empty">' . esc_html__( 'No notifications sent yet.', 'equine-event-manager' ) . '</p>';
			return;
		}
		?>
		<div class="eem-desktop-table">
			<table class="eem-table">
				<thead><tr>
					<th><?php esc_html_e( 'Sent', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Event', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Audience', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Recipients', 'equine-event-manager' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $entries as $e ) :
						$p     = is_array( $e['payload'] ?? null ) ? $e['payload'] : array();
						$rid   = (int) ( $e['reservation_id'] ?? 0 );
						$event = $rid > 0 ? get_the_title( $rid ) : '—';
						$sent  = (int) ( $p['sent'] ?? 0 );
						$total = (int) ( $p['recipient_count'] ?? 0 );
						$fail  = (int) ( $p['failed'] ?? 0 );
						?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' g:ia', (string) ( $e['created_at'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( $event ); ?></td>
							<td><?php echo esc_html( (string) ( $p['audience'] ?? '—' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $p['subject'] ?? '' ) ); ?></td>
							<td><?php
								echo esc_html( sprintf( '%d / %d', $sent, $total ) );
								if ( $fail > 0 ) {
									echo ' <span class="eem-status-badge eem-status-refunded">' . esc_html( sprintf( /* translators: %d: failed count */ __( '%d failed', 'equine-event-manager' ), $fail ) ) . '</span>';
								}
							?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX: event meta — divisions (for the Include/Exclude dropdowns) + the
	 * baseline "all customers" recipient count for the picked event.
	 *
	 * @return void
	 */
	public static function ajax_event_meta(): void {
		self::guard();
		$rid       = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$divisions = array();
		foreach ( EEM_Notifications::event_divisions( $rid ) as $did => $name ) {
			$divisions[] = array( 'value' => 'division:' . $did, 'label' => $name );
		}
		wp_send_json_success( array(
			'divisions' => $divisions,
			'count'     => EEM_Notifications::count( $rid, 'all' ),
		) );
	}

	/**
	 * AJAX: live recipient count for the current audience selection.
	 *
	 * @return void
	 */
	public static function ajax_count(): void {
		self::guard();
		$rid     = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$include = isset( $_POST['include'] ) ? sanitize_text_field( wp_unslash( $_POST['include'] ) ) : 'all';
		$exclude = isset( $_POST['exclude'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude'] ) ) : '';
		$payment = isset( $_POST['payment'] ) ? sanitize_key( wp_unslash( $_POST['payment'] ) ) : 'all';
		wp_send_json_success( array( 'count' => EEM_Notifications::count( $rid, $include, $exclude, $payment ) ) );
	}

	/**
	 * AJAX: start a send job. Validates, resolves the recipient list, stashes it
	 * in a transient, and returns a token + total so the client can step through
	 * batches. Resolving once up front keeps each batch step cheap.
	 *
	 * @return void
	 */
	public static function ajax_send_start(): void {
		self::guard();
		$rid     = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$include = isset( $_POST['include'] ) ? sanitize_text_field( wp_unslash( $_POST['include'] ) ) : 'all';
		$exclude = isset( $_POST['exclude'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude'] ) ) : '';
		$payment = isset( $_POST['payment'] ) ? sanitize_key( wp_unslash( $_POST['payment'] ) ) : 'all';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';

		if ( $rid <= 0 || '' === $subject || '' === $body ) {
			wp_send_json_error( array( 'message' => __( 'Pick an event and enter a subject + message.', 'equine-event-manager' ) ), 400 );
		}
		$recipients = EEM_Notifications::resolve_recipients( $rid, $include, $exclude, $payment );
		if ( empty( $recipients ) ) {
			wp_send_json_error( array( 'message' => __( 'That audience has no recipients.', 'equine-event-manager' ) ), 404 );
		}

		$token = md5( uniqid( (string) $rid, true ) );
		set_transient( self::JOB_PREFIX . $token, array(
			'reservation_id' => $rid,
			'subject'        => $subject,
			'body'           => $body,
			'audience'       => self::audience_description( $rid, $include, $exclude, $payment ),
			'recipients'     => $recipients,
			'sent'           => 0,
			'failed'         => 0,
		), HOUR_IN_SECONDS );

		wp_send_json_success( array( 'token' => $token, 'total' => count( $recipients ), 'batch' => self::BATCH_SIZE ) );
	}

	/**
	 * AJAX: send one batch of an in-flight job. Returns running totals + the
	 * next offset; on completion writes the activity-log entry + clears the job.
	 *
	 * @return void
	 */
	public static function ajax_send_step(): void {
		self::guard();
		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$job    = $token ? get_transient( self::JOB_PREFIX . $token ) : false;
		if ( ! is_array( $job ) || empty( $job['recipients'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This send session expired. Please try again.', 'equine-event-manager' ) ), 410 );
		}

		$recipients = $job['recipients'];
		$total      = count( $recipients );
		$batch      = array_slice( $recipients, $offset, self::BATCH_SIZE );

		$result      = self::dispatch_batch( $batch, (string) $job['subject'], (string) $job['body'], (int) $job['reservation_id'] );
		$job['sent']   += $result['sent'];
		$job['failed'] += $result['failed'];

		$next = $offset + self::BATCH_SIZE;
		$done = $next >= $total;

		if ( $done ) {
			EEM_Activity_Log::write(
				EEM_Activity_Log::NOTIFICATION_SENT,
				array(
					'channel'         => 'notifications_page',
					'audience'        => (string) $job['audience'],
					'subject'         => (string) $job['subject'],
					'recipient_count' => $total,
					'sent'            => (int) $job['sent'],
					'failed'          => (int) $job['failed'],
				),
				array(
					'reservation_id' => (int) $job['reservation_id'],
					'actor_type'     => 'admin',
					'actor_id'       => get_current_user_id(),
				)
			);
			delete_transient( self::JOB_PREFIX . $token );
		} else {
			set_transient( self::JOB_PREFIX . $token, $job, HOUR_IN_SECONDS );
		}

		wp_send_json_success( array(
			'sent'        => (int) $job['sent'],
			'failed'      => (int) $job['failed'],
			'total'       => $total,
			'next_offset' => $next,
			'done'        => $done,
		) );
	}

	/**
	 * Send one batch of recipients (the per-message send loop, split out of
	 * {@see self::ajax_send_step} so the write path is testable without wp_die).
	 * Each message is Emogrifier-inlined by EEM_Mailer.
	 *
	 * @param string[] $emails         Recipient emails.
	 * @param string   $subject        Subject.
	 * @param string   $body           Plain-text body.
	 * @param int      $reservation_id Owning event (context for telemetry).
	 * @return array{sent:int,failed:int}
	 */
	public static function dispatch_batch( array $emails, string $subject, string $body, int $reservation_id ): array {
		$sender  = class_exists( 'EEM_Settings_Repo' ) ? EEM_Settings_Repo::get_email_sender() : array();
		$headers = array();
		if ( ! empty( $sender['from_name'] ) && ! empty( $sender['from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $sender['from_name'], $sender['from_email'] );
		}
		if ( ! empty( $sender['reply_to'] ) ) {
			$headers[] = sprintf( 'Reply-To: %s', $sender['reply_to'] );
		}
		$html   = self::wrap_body( $subject, $body );
		$sent   = 0;
		$failed = 0;
		foreach ( $emails as $email ) {
			$ok = EEM_Mailer::send_html_email( $email, $subject, $html, $headers, array(
				'type'           => 'notification',
				'reservation_id' => $reservation_id,
				'recipient'      => $email,
			) );
			if ( true === $ok ) { $sent++; } else { $failed++; }
		}
		return array( 'sent' => $sent, 'failed' => $failed );
	}

	/**
	 * Minimal branded HTML wrapper so the plain-text compose body renders as a
	 * proper email (and gives EEM_Mailer's Emogrifier pass something to inline).
	 *
	 * @param string $subject Email subject (rendered as a heading).
	 * @param string $body    Plain-text body.
	 * @return string
	 */
	private static function wrap_body( string $subject, string $body ): string {
		$style = 'font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1d2327;';
		return '<div style="' . esc_attr( $style ) . '">'
			. '<h2 style="color:#031B4E;margin:0 0 14px;">' . esc_html( $subject ) . '</h2>'
			. '<div>' . nl2br( esc_html( $body ) ) . '</div>'
			. '</div>';
	}

	/**
	 * Human-readable audience description for the activity-log entry + history.
	 *
	 * @param int    $rid     Reservation id.
	 * @param string $include Include segment.
	 * @param string $exclude Exclude segment.
	 * @param string $payment Payment filter.
	 * @return string
	 */
	private static function audience_description( int $rid, string $include, string $exclude, string $payment ): string {
		$label = static function ( $seg ) use ( $rid ) {
			if ( 0 === strpos( (string) $seg, 'division:' ) ) {
				$divs = EEM_Notifications::event_divisions( $rid );
				$did  = (int) substr( $seg, strlen( 'division:' ) );
				return isset( $divs[ $did ] ) ? sprintf( /* translators: %s: division name */ __( '%s entrants', 'equine-event-manager' ), $divs[ $did ] ) : __( 'a division', 'equine-event-manager' );
			}
			$opts = EEM_Notifications::segment_options();
			return $opts[ $seg ] ?? $seg;
		};
		$desc = (string) $label( $include );
		if ( '' !== $exclude ) {
			$desc .= ' ' . sprintf( /* translators: %s: excluded segment */ __( '(not %s)', 'equine-event-manager' ), $label( $exclude ) );
		}
		if ( 'paid' === $payment ) {
			$desc .= ' · ' . __( 'paid', 'equine-event-manager' );
		} elseif ( 'unpaid' === $payment ) {
			$desc .= ' · ' . __( 'unpaid', 'equine-event-manager' );
		}
		return $desc;
	}

	/**
	 * Shared AJAX guard — capability + nonce.
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'equine-event-manager' ) ), 403 );
		}
		check_ajax_referer( 'eem_notifications', 'nonce' );
	}
}
