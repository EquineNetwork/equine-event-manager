<?php
/**
 * 2.3.49 — Path B Phase 2: event page hero rebuilt to .mockups/event_page.html.
 *
 * render_normalized_event_markup() now emits the mockup hero (.hero >
 * .hero-inner > .hero-img-col + .hero-info-col with tags/title/dates/bullets/
 * meta-grid/CTAs) inside an .eem-event-page wrapper. When a linked reservation
 * exists the [en_reservation] shortcode renders into the page below the hero;
 * its workspace now carries the mockup alias classes (page-body / form-col /
 * order-sidebar). With no linked reservation the hero + event body render and
 * NO "not available" notice is shown. A TEC draft preview is left to TEC via a
 * new is_preview() guard.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function fix2349_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== 2.3.49 — EVENT PAGE HERO SMOKE ===\n";

wp_set_current_user( 1 );

$events = new EEM_Events();
$render = new ReflectionMethod( 'EEM_Events', 'render_normalized_event_markup' );
$render->setAccessible( true );

// Real published reservation so the linked-case shortcode actually renders.
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => '2.3.49 Hero ' . wp_generate_password( 6, false, false ),
) );

$base_event = array(
	'event_id'       => 999001,
	'source'         => 'tec',
	'title'          => '2026 Southeast Region Super Sort',
	'content_raw'    => '<ul><li>Early Bird Stall Pricing: $145/stall/weekend.</li><li>2 bags of shavings required per stall at check-in.</li></ul>',
	'excerpt'        => '',
	'start_date'     => '2026-05-07',
	'end_date'       => '2026-05-09',
	'venue_name'     => 'Georgia National Fairgrounds',
	'location'       => 'Perry, GA',
	'venue'          => array(
		'address_display' => "401 Larry Walker Pkwy\nPerry, GA 31069",
		'map_query'       => '401 Larry Walker Pkwy, Perry, GA 31069',
		'filter_url'      => '',
	),
	'producer'       => array(
		'name'       => 'RSNC USA',
		'email'      => 'office@rsnc.us',
		'phone'      => '970-897-2901',
		'website'    => '',
		'filter_url' => '',
	),
	'featured'       => true,
	'featured_image' => 'https://example.com/flyer.jpg',
	'hero_image'     => 'https://example.com/flyer.jpg',
	'flyer_url'      => '',
	'reservation_id' => $rid,
	'cta_label'      => '',
	'categories'     => array( 'RSNC Production Events' ),
	'tags'           => array(),
);

$linked_html = $render->invoke( $events, $base_event, true, true );

// Shortcode source — the page-body / form-col / order-sidebar aliases are a
// source contract on the workspace markup. The live two-column workspace only
// renders when a section is bookable (open dates + inventory), so we assert the
// aliasing here and verify the rendered two-column layout in the browser step.
$sc_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

// ── Assertion 1 — hero + page-body structural wrappers present ────
echo "\n[1] Linked render: hero wrapper + page-body alias contract\n";
fix2349_ok( 'output contains <div class="hero">',
	false !== strpos( $linked_html, '<div class="hero">' ), $pass, $fail, $log );
fix2349_ok( 'workspace div carries the page-body alias (source)',
	(bool) preg_match( '~class="eem-reservation-workspace page-body"~', $sc_src ), $pass, $fail, $log );
fix2349_ok( 'output wrapped in .eem-event-page',
	false !== strpos( $linked_html, 'class="eem-event-page"' ), $pass, $fail, $log );

// ── Assertion 2 — hero contains all required parts ────────────────
echo "\n[2] Hero contents: tags / title / dates / bullets / meta-grid / CTAs\n";
$bits = array(
	'hero-tags'      => 'hero-tags',
	'tag-featured'   => 'tag-featured',
	'tag-prod'       => 'tag-prod',
	'hero-title'     => 'hero-title',
	'hero-dates'     => 'hero-dates',
	'hero-bullets'   => 'hero-bullets',
	'hero-meta-grid' => 'hero-meta-grid',
	'hero-ctas'      => 'hero-ctas',
	'btn-reserve'    => 'btn-reserve',
	'btn-directions' => 'btn-directions',
);
foreach ( $bits as $label => $needle ) {
	fix2349_ok( "hero contains .{$label}",
		false !== strpos( $linked_html, $needle ), $pass, $fail, $log );
}
fix2349_ok( 'title bound from event data',
	false !== strpos( $linked_html, '2026 Southeast Region Super Sort' ), $pass, $fail, $log );
fix2349_ok( 'date range formatted (May 7, 2026 – May 9, 2026)',
	false !== strpos( $linked_html, 'May 7, 2026' ) && false !== strpos( $linked_html, 'May 9, 2026' ),
	$pass, $fail, $log );
fix2349_ok( 'bullets derived from content list items',
	false !== strpos( $linked_html, 'Early Bird Stall Pricing' ), $pass, $fail, $log );
fix2349_ok( 'producer category surfaced as .tag-prod text',
	false !== strpos( $linked_html, 'RSNC Production Events' ), $pass, $fail, $log );
fix2349_ok( 'directions URL uses maps.google.com/?q=',
	false !== strpos( $linked_html, 'https://maps.google.com/?q=' ), $pass, $fail, $log );
fix2349_ok( 'reserve CTA anchors #reservation-form',
	false !== strpos( $linked_html, 'href="#reservation-form"' ), $pass, $fail, $log );

// ── Assertion 3 — CSS classes exist in public.css ─────────────────
echo "\n[3] public.css carries the hero / page-body / drawer selectors\n";
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );
foreach ( array(
	'.hero', '.hero-inner', '.hero-info-col', '.hero-tags', '.tag-featured',
	'.tag-prod', '.hero-title', '.hero-dates', '.hero-bullets', '.hero-meta-grid',
	'.hero-ctas', '.page-body', '.form-col', '.order-sidebar', '.mobile-order-drawer',
) as $sel ) {
	fix2349_ok( "public.css defines {$sel}",
		false !== strpos( $css, $sel ), $pass, $fail, $log );
}

// ── Assertion 4 — is_preview() guard added to the filter ──────────
echo "\n[4] is_preview() guard in filter_single_event_content\n";
$events_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
fix2349_ok( 'filter returns early on is_preview()',
	(bool) preg_match( '~if\s*\(\s*is_preview\(\)\s*\)\s*\{\s*return\s+\$content;~', $events_src ),
	$pass, $fail, $log );

// ── Assertion 5 — linked reservation form renders inside form-col ─
echo "\n[5] Linked: reservation form mounts inside .form-col\n";
fix2349_ok( 'reservation mount id="reservation-form" present',
	false !== strpos( $linked_html, 'id="reservation-form"' ), $pass, $fail, $log );
fix2349_ok( 'workspace main carries form-col alias (source)',
	(bool) preg_match( '~class="eem-reservation-workspace__main form-col"~', $sc_src ), $pass, $fail, $log );
fix2349_ok( 'rail carries order-sidebar alias (source)',
	(bool) preg_match( '~class="eem-reservation-workspace__rail order-sidebar"~', $sc_src ), $pass, $fail, $log );
$hero_pos = strpos( $linked_html, '<div class="hero">' );
$form_pos = strpos( $linked_html, 'id="reservation-form"' );
fix2349_ok( 'form renders below the hero',
	$hero_pos !== false && $form_pos !== false && $form_pos > $hero_pos, $pass, $fail, $log );

// ── Assertion 6 — no linked reservation: hero only, no form ──
// 2.3.54 — Behavior change: with no linked reservation the page renders the
// event info (hero) ONLY; the .eem-event-body fallback was removed because it
// re-printed the event description and duplicated the hero copy. Most events
// have no reservation tied to them and should just show the hero.
echo "\n[6] No reservation: hero only, no body, no form, no error notice\n";
$no_link            = $base_event;
$no_link['reservation_id'] = 0;
$no_link_html       = $render->invoke( $events, $no_link, true, true );
fix2349_ok( 'hero still renders without a reservation',
	false !== strpos( $no_link_html, '<div class="hero">' ), $pass, $fail, $log );
fix2349_ok( 'event body fallback NOT rendered (2.3.54)',
	false === strpos( $no_link_html, 'eem-event-body' ), $pass, $fail, $log );
fix2349_ok( 'no reservation-form mount',
	false === strpos( $no_link_html, 'id="reservation-form"' ), $pass, $fail, $log );
fix2349_ok( 'no page-body (workspace) emitted',
	false === strpos( $no_link_html, 'page-body' ), $pass, $fail, $log );
fix2349_ok( 'no "not available" notice',
	false === stripos( $no_link_html, 'not available' ), $pass, $fail, $log );
fix2349_ok( 'no Reserve CTA when unlinked',
	false === strpos( $no_link_html, 'btn-reserve' ), $pass, $fail, $log );

// ── Assertion 7 — Elementor builder mode still cedes ──────────────
echo "\n[7] Source: Elementor builder guard intact\n";
fix2349_ok( 'filter cedes when _elementor_edit_mode === builder',
	(bool) preg_match( "~'builder'\s*===\s*\(string\)\s*get_post_meta\(\s*\\\$post_id,\s*'_elementor_edit_mode'~", $events_src ),
	$pass, $fail, $log );

// ── Assertion 8 — TEC archive/listing not intercepted (is_singular) ─
echo "\n[8] Source: is_singular() guard preserved\n";
fix2349_ok( 'filter bails when ! is_singular( $post_type )',
	(bool) preg_match( '~if\s*\(\s*!\s*is_singular\(\s*\$post_type\s*\)\s*\)~', $events_src ),
	$pass, $fail, $log );

// ── Cleanup ───────────────────────────────────────────────────────
wp_delete_post( $rid, true );

// ── Cache-bust ────────────────────────────────────────────────────
echo "\n[Cache-bust] EQUINE_EVENT_MANAGER_VERSION >= 2.3.49\n";
fix2349_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.49',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.49', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
