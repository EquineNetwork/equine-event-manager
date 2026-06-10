<?php
/**
 * Smoke — first-run setup checklist completion logic (2.7.24).
 *
 * Verifies each required area flips done=true only when its real option value
 * is configured, that is_complete() gates on all four, that should_show()
 * respects completion + the per-user dismiss flag, and that the items expose
 * the correct Settings-panel URLs.
 *
 * Run: wp eval-file tests/smoke/c-setup-checklist-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

wp_set_current_user( 1 );

// Snapshot options + dismiss meta so we restore the site afterwards.
$snap = array(
	'company'     => get_option( 'equine_event_manager_company_settings', null ),
	'sender'      => get_option( EEM_Settings_Repo::OPTION_EMAIL_SENDER, null ),
	'payment'     => get_option( 'equine_event_manager_payment_settings', null ),
	'integration' => get_option( 'equine_event_manager_integration_settings', null ),
);
$dismiss_snap = get_user_meta( get_current_user_id(), EEM_Setup_Checklist::DISMISS_META, true );
$es_snap      = get_option( 'eem_event_source_confirmed', null );

// Helper to map items keyed by their 'key' for easy assertion.
$by_key = static function (): array {
	$out = array();
	foreach ( EEM_Setup_Checklist::items() as $it ) { $out[ $it['key'] ] = $it; }
	return $out;
};

/* ── Empty state — nothing configured ───────────────────────── */
delete_option( 'equine_event_manager_company_settings' );
delete_option( EEM_Settings_Repo::OPTION_EMAIL_SENDER );
delete_option( 'equine_event_manager_payment_settings' );
delete_option( 'equine_event_manager_integration_settings' );
delete_option( 'eem_event_source_confirmed' );
delete_user_meta( get_current_user_id(), EEM_Setup_Checklist::DISMISS_META );

// Event Source can be backfill-confirmed when the site already has a published
// reservation (publishing requires a linked event). The other four areas are
// purely option-driven, so they're undone after the deletes above. Compute the
// event-source state to keep this assertion environment-robust.
$es_done = EEM_Setup_Checklist::is_event_source_confirmed() ? 1 : 0;

$items = $by_key();
$check( 'empty: branding not done',       false === $items['branding']['done'] );
$check( 'empty: communications not done', false === $items['communications']['done'] );
$check( 'empty: payments not done',       false === $items['payments']['done'] );
$check( 'empty: sendgrid not done',       false === $items['sendgrid']['done'] );
$check( 'empty: is_complete() false',     false === EEM_Setup_Checklist::is_complete() );
$check( 'empty: should_show() true',      true === EEM_Setup_Checklist::should_show() );
$check( 'empty: only event-source may be backfilled', $es_done === EEM_Setup_Checklist::completed_count() );

// URLs point at the right panels.
$check( 'branding URL → panel=branding',             false !== strpos( $items['branding']['url'], 'panel=branding' ) );
$check( 'communications URL → panel=communications', false !== strpos( $items['communications']['url'], 'panel=communications' ) );
$check( 'payments URL → panel=payments',             false !== strpos( $items['payments']['url'], 'panel=payments' ) );
$check( 'sendgrid URL → panel=integrations',         false !== strpos( $items['sendgrid']['url'], 'panel=integrations' ) );

/* ── Branding: needs logo AND support email ─────────────────── */
update_option( 'equine_event_manager_company_settings', array( 'logo_id' => 42, 'support_email' => '' ), false );
$items = $by_key();
$check( 'branding: logo only (no email) still not done', false === $items['branding']['done'] );
update_option( 'equine_event_manager_company_settings', array( 'logo_id' => 42, 'support_email' => 'help@example.com' ), false );
$items = $by_key();
$check( 'branding: logo + email → done', true === $items['branding']['done'] );

/* ── Communications: needs from_name + from_email ───────────── */
update_option( EEM_Settings_Repo::OPTION_EMAIL_SENDER, array( 'from_name' => 'RSNC', 'from_email' => '' ), false );
$items = $by_key();
$check( 'communications: name only → not done', false === $items['communications']['done'] );
update_option( EEM_Settings_Repo::OPTION_EMAIL_SENDER, array( 'from_name' => 'RSNC', 'from_email' => 'no-reply@example.com' ), false );
$items = $by_key();
$check( 'communications: name + email → done', true === $items['communications']['done'] );

/* ── Payments: needs a complete Stripe key pair ─────────────── */
update_option( 'equine_event_manager_payment_settings', array( 'selected_gateway' => 'stripe', 'stripe' => array( 'test_publishable_key' => 'pk_test_x' ) ), false );
$items = $by_key();
$check( 'payments: pub only → not done', false === $items['payments']['done'] );
update_option( 'equine_event_manager_payment_settings', array( 'selected_gateway' => 'stripe', 'stripe' => array( 'test_publishable_key' => 'pk_test_x', 'test_secret_key' => 'sk_test_x' ) ), false );
$items = $by_key();
$check( 'payments: pub + secret → done', true === $items['payments']['done'] );

/* ── SendGrid: needs api key ────────────────────────────────── */
update_option( 'equine_event_manager_integration_settings', array( 'sendgrid_api_key' => 'SG.abc123' ), false );
$items = $by_key();
$check( 'sendgrid: key present → done', true === $items['sendgrid']['done'] );

/* ── All required + sendgrid done → setup complete ──────────── */
// Confirm the event source explicitly so the smoke is self-contained (no reliance
// on a published-reservation backfill, which a fresh/empty site won't have).
update_option( 'eem_event_source_confirmed', 1, false );
$check( 'all set: is_complete() true', true === EEM_Setup_Checklist::is_complete() );

// 2.7.50 behavior: completed required areas DROP OFF the card. With no published
// reservation yet, the card surfaces the "create your first reservation" next-step,
// so it stays visible (and shows NO setup rows).
//
// The dev box has seeded published reservations, which would legitimately drop the
// onboarding row. Force the empty-site "no published reservation" state for the
// onboarding assertions with a non-destructive posts_pre_query short-circuit on
// en_reservation publish queries; removed before the restore block below.
$force_no_reservation = static function ( $posts, $q ) {
	$pt = $q->get( 'post_type' );
	if ( 'en_reservation' === $pt || ( is_array( $pt ) && in_array( 'en_reservation', $pt, true ) ) ) {
		return array();
	}
	return $posts;
};
add_filter( 'posts_pre_query', $force_no_reservation, 10, 2 );

$types = static function (): array {
	return array_map( static function ( $r ) { return $r['type']; }, EEM_Setup_Checklist::pending_actions() );
};
$check( 'all set: completed required areas dropped off (no setup rows)', ! in_array( 'setup', $types(), true ) );
$check( 'all set, no reservation: create-first-reservation row present', in_array( 'reservation', $types(), true ) );
$check( 'all set, no reservation: should_show() true', true === EEM_Setup_Checklist::should_show() );

/* ── SendGrid is the ONLY dismissable row ───────────────────── */
delete_option( 'equine_event_manager_integration_settings' ); // sendgrid undone → eligible to show
$check( 'sendgrid undone: sendgrid row present before dismiss', in_array( 'sendgrid', $types(), true ) );
update_user_meta( get_current_user_id(), EEM_Setup_Checklist::DISMISS_META, 1 );
$check( 'dismissed: is_dismissed() true', true === EEM_Setup_Checklist::is_dismissed() );
$check( 'sendgrid dismiss removes only the sendgrid row', ! in_array( 'sendgrid', $types(), true ) );
$check( 'dismiss does NOT hide the card while other actions remain', true === EEM_Setup_Checklist::should_show() );

remove_filter( 'posts_pre_query', $force_no_reservation, 10 );

/* ── Restore the site ───────────────────────────────────────── */
foreach ( array(
	'equine_event_manager_company_settings'     => $snap['company'],
	EEM_Settings_Repo::OPTION_EMAIL_SENDER       => $snap['sender'],
	'equine_event_manager_payment_settings'     => $snap['payment'],
	'equine_event_manager_integration_settings' => $snap['integration'],
) as $opt => $val ) {
	if ( null === $val ) { delete_option( $opt ); } else { update_option( $opt, $val, false ); }
}
if ( null === $es_snap ) { delete_option( 'eem_event_source_confirmed' ); } else { update_option( 'eem_event_source_confirmed', $es_snap, false ); }
if ( '' === $dismiss_snap ) { delete_user_meta( get_current_user_id(), EEM_Setup_Checklist::DISMISS_META ); }
else { update_user_meta( get_current_user_id(), EEM_Setup_Checklist::DISMISS_META, $dismiss_snap ); }

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
