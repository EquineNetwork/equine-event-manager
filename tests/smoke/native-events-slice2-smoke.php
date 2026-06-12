<?php
/**
 * Native Events Slice 2 smoke — Facebook + Instagram event fields (save +
 * normalized data + frontend render + editor render).
 *
 * Run: wp eval-file tests/smoke/native-events-slice2-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// Seed a published native event with social links.
$eid = wp_insert_post( array(
	'post_type'   => 'en_event',
	'post_status' => 'publish',
	'post_title'  => 'Slice2 Social Smoke Event',
) );
update_post_meta( $eid, '_equine_event_manager_event_start_date', '2026-09-01T09:00' );
update_post_meta( $eid, '_equine_event_manager_event_end_date', '2026-09-03T17:00' );
update_post_meta( $eid, '_en_event_facebook', 'https://facebook.com/smokeevent' );
update_post_meta( $eid, '_en_event_instagram', 'https://instagram.com/smokeevent' );

// Normalized data carries the social block.
$data = EEM_Events::get_normalized_event_data( $eid );
$check( 'normalized data has a social block', isset( $data['social'] ) && is_array( $data['social'] ) );
$check( 'social.facebook normalized', ( $data['social']['facebook'] ?? '' ) === 'https://facebook.com/smokeevent' );
$check( 'social.instagram normalized', ( $data['social']['instagram'] ?? '' ) === 'https://instagram.com/smokeevent' );

// Frontend single-event render shows both links.
$html = do_shortcode( '[en_event id="' . $eid . '"]' );
$check( 'spotlight renders the Facebook link', false !== strpos( $html, 'https://facebook.com/smokeevent' ) );
$check( 'spotlight renders the Instagram link', false !== strpos( $html, 'https://instagram.com/smokeevent' ) );
$check( 'spotlight renders the hero-social container', false !== strpos( $html, 'hero-social' ) );

// Editor render emits both inputs (and the save handler reads them).
$events = new EEM_Events();
ob_start();
$events->render_event_details_meta_box( get_post( $eid ) );
$editor = (string) ob_get_clean();
$check( 'editor renders the Facebook input', false !== strpos( $editor, 'name="en_event_facebook"' ) );
$check( 'editor renders the Instagram input', false !== strpos( $editor, 'name="en_event_instagram"' ) );
$check( 'editor pre-fills the saved Facebook URL', false !== strpos( $editor, 'https://facebook.com/smokeevent' ) );

$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
$check( 'save handler writes _en_event_facebook', false !== strpos( $src, "update_post_meta( \$post_id, '_en_event_facebook'" ) );
$check( 'save handler writes _en_event_instagram', false !== strpos( $src, "update_post_meta( \$post_id, '_en_event_instagram'" ) );

wp_delete_post( (int) $eid, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
