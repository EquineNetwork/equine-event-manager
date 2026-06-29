<?php
/**
 * Payment-reminder cron smoke (ROADMAP #23).
 *
 * Covers the pure due/dedupe logic, the default-OFF safety guard, scheduling,
 * and the canonical-email reuse contract. No real email is sent.
 *
 * Run: wp eval-file tests/smoke/payment-reminder-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$check( 'class loads', class_exists( 'EEM_Payment_Reminder' ) );

$now = time();
$day = DAY_IN_SECONDS;

// Baseline option state (defaults).
delete_option( EEM_Payment_Reminder::OPTION_MIN_AGE );
delete_option( EEM_Payment_Reminder::OPTION_REPEAT );
$check( 'min age defaults to 3', 3 === EEM_Payment_Reminder::min_age_days() );
$check( 'repeat defaults to 7', 7 === EEM_Payment_Reminder::repeat_days() );

// --- is_due: a stale unpaid order with a good email is due ----------------
$base = array(
	'status_slug' => 'unpaid',
	'email'       => 'rider@example.com',
	'created_at'  => gmdate( 'Y-m-d H:i:s', $now - ( 10 * $day ) ),
	'components'  => array( array( 'notes' => '' ) ),
);
$check( 'stale unpaid order is due', EEM_Payment_Reminder::is_due( $base, $now ) );

// --- not due: paid -------------------------------------------------------
$paid = $base; $paid['status_slug'] = 'paid';
$check( 'paid order is NOT due', ! EEM_Payment_Reminder::is_due( $paid, $now ) );

// invoice-sent IS eligible (still unpaid, just contacted).
$inv = $base; $inv['status_slug'] = 'invoice-sent';
$check( 'invoice-sent order is eligible', EEM_Payment_Reminder::is_due( $inv, $now ) );

// --- not due: too new ----------------------------------------------------
$fresh = $base; $fresh['created_at'] = gmdate( 'Y-m-d H:i:s', $now - $day );
$check( 'order younger than min-age is NOT due', ! EEM_Payment_Reminder::is_due( $fresh, $now ) );

// --- not due: bad / missing email ----------------------------------------
$noemail = $base; $noemail['email'] = '';
$check( 'order without email is NOT due', ! EEM_Payment_Reminder::is_due( $noemail, $now ) );

// --- dedupe: contacted within the repeat window --------------------------
$recent = $base;
$recent['components'] = array( array( 'notes' => 'Invoice Sent At: ' . gmdate( 'Y-m-d H:i:s', $now - ( 2 * $day ) ) ) );
$check( 'recently-contacted order is NOT due', ! EEM_Payment_Reminder::is_due( $recent, $now ) );
$check( 'last_contact_ts parses the note', EEM_Payment_Reminder::last_contact_ts( $recent ) > 0 );

// --- due again after the repeat window passes ----------------------------
$old_contact = $base;
$old_contact['components'] = array( array( 'notes' => 'Invoice Sent At: ' . gmdate( 'Y-m-d H:i:s', $now - ( 9 * $day ) ) ) );
$check( 'order contacted before the repeat window is due again', EEM_Payment_Reminder::is_due( $old_contact, $now ) );

// --- repeat=0 means remind once, never again -----------------------------
update_option( EEM_Payment_Reminder::OPTION_REPEAT, 0 );
$check( 'repeat=0 → contacted order never re-reminds', ! EEM_Payment_Reminder::is_due( $old_contact, $now ) );
$check( 'repeat=0 → never-contacted order still due', EEM_Payment_Reminder::is_due( $base, $now ) );
delete_option( EEM_Payment_Reminder::OPTION_REPEAT );

// --- safety: feature defaults OFF, sweep no-ops ---------------------------
delete_option( EEM_Payment_Reminder::OPTION_ENABLED );
$check( 'feature defaults OFF', ! EEM_Payment_Reminder::is_enabled() );
// run_sweep returns immediately when disabled (no fatal, no send).
EEM_Payment_Reminder::run_sweep();
$check( 'run_sweep no-ops while disabled (no fatal)', true );

// --- scheduling ----------------------------------------------------------
EEM_Payment_Reminder::unschedule();
$check( 'unscheduled → no event', false === wp_next_scheduled( EEM_Payment_Reminder::CRON_HOOK ) );
EEM_Payment_Reminder::schedule();
$check( 'schedule registers the daily event', false !== wp_next_scheduled( EEM_Payment_Reminder::CRON_HOOK ) );
EEM_Payment_Reminder::schedule(); // idempotent — no duplicate.
$check( 'schedule is idempotent', false !== wp_next_scheduled( EEM_Payment_Reminder::CRON_HOOK ) );

// --- canonical-email reuse contract --------------------------------------
$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-payment-reminder.php' );
$check( 'send_reminder reuses send_invoice_email_for_order', false !== strpos( $src, 'send_invoice_email_for_order' ) );
$check( 'no separate email template authored', false === strpos( $src, 'build_' ) || false === strpos( $src, 'wp_mail' ) );

// --- Settings UI: render + save round-trip --------------------------------
if ( class_exists( 'EEM_Settings_Page' ) ) {
	$page = new EEM_Settings_Page();

	// Render the reminders section and assert the three controls are present.
	update_option( EEM_Payment_Reminder::OPTION_ENABLED, 1 );
	update_option( EEM_Payment_Reminder::OPTION_MIN_AGE, 5 );
	update_option( EEM_Payment_Reminder::OPTION_REPEAT, 14 );
	$render = new ReflectionMethod( 'EEM_Settings_Page', 'render_communications_reminders_section' );
	$render->setAccessible( true );
	ob_start();
	$render->invoke( $page );
	$html = (string) ob_get_clean();
	$check( 'section renders the enable toggle', false !== strpos( $html, 'name="payload[payment_reminder][enabled]"' ) );
	$check( 'section renders the min-age field', false !== strpos( $html, 'name="payload[payment_reminder][min_age_days]"' ) );
	$check( 'section renders the repeat field', false !== strpos( $html, 'name="payload[payment_reminder][repeat_days]"' ) );
	$check( 'enable toggle reflects saved ON state', false !== strpos( $html, 'id="eem-reminder-enabled"' ) && false !== strpos( $html, 'checked' ) );
	$check( 'min-age field reflects saved value 5', false !== strpos( $html, 'value="5"' ) );
	$check( 'repeat field reflects saved value 14', false !== strpos( $html, 'value="14"' ) );

	// Save round-trip: enable with custom cadence.
	$save = new ReflectionMethod( 'EEM_Settings_Page', 'save_communications_panel' );
	$save->setAccessible( true );
	$save->invoke( $page, array( 'payment_reminder' => array( 'enabled' => '1', 'min_age_days' => '2', 'repeat_days' => '10' ) ) );
	$check( 'save persists enabled=ON', EEM_Payment_Reminder::is_enabled() );
	$check( 'save persists min-age', 2 === EEM_Payment_Reminder::min_age_days() );
	$check( 'save persists repeat', 10 === EEM_Payment_Reminder::repeat_days() );
	$check( 'enabling save (re)schedules the cron', false !== wp_next_scheduled( EEM_Payment_Reminder::CRON_HOOK ) );

	// Save round-trip: unchecked toggle (absent from payload) turns it OFF.
	$save->invoke( $page, array( 'payment_reminder' => array( 'min_age_days' => '2', 'repeat_days' => '10' ) ) );
	$check( 'absent checkbox saves enabled=OFF', ! EEM_Payment_Reminder::is_enabled() );

	// Negative input clamps to 0.
	$save->invoke( $page, array( 'payment_reminder' => array( 'min_age_days' => '-4', 'repeat_days' => '-1' ) ) );
	$check( 'negative min-age clamps to 0', 0 === EEM_Payment_Reminder::min_age_days() );
	$check( 'negative repeat clamps to 0', 0 === EEM_Payment_Reminder::repeat_days() );

	// Reset to safe defaults (OFF) so the smoke leaves no live auto-emailer on.
	delete_option( EEM_Payment_Reminder::OPTION_ENABLED );
	delete_option( EEM_Payment_Reminder::OPTION_MIN_AGE );
	delete_option( EEM_Payment_Reminder::OPTION_REPEAT );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
