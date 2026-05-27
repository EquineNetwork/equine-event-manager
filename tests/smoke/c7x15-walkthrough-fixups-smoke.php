<?php
/**
 * C7.X.15 — Whitney's walkthrough fix-ups (7 issues consolidated).
 *
 * Issue 1 — NOT IN SMOKE SCOPE. Whitney's "double padding" was
 *   verified at audit time as no CSS drift (admin.css matches mockup
 *   canon exactly). Surfaced as a perception-or-environment question
 *   in SESSION-NOTES for Whitney's DevTools follow-up.
 *
 * Issue 2A — Publish / Save Draft / Update buttons functional.
 *   eemDispatchSave + eemReservationEditorNonce queried .eem-save-bar
 *   (retired at C7.X.3) → returned early → buttons dead. Fixed with
 *   generic [data-eem-reservation-id] + input[name="_eem_editor_nonce"]
 *   lookups + reload-on-success.
 *
 * Issue 2B — Agreement upload button wired to WP Media Library.
 *   wp_enqueue_media() on editor page + new admin.js handler that
 *   opens wp.media with PDF restriction + persists attachment id +
 *   updates file-row display in place + Remove handler clears the id.
 *
 * Issue 2 STRUCTURAL — button-handler enumeration smoke. Walk every
 *   data-eem-action button in the rendered editor; assert each has a
 *   matching `[data-eem-action="..."]` handler in admin.js. Catches
 *   the "shipped a button with no handler" bug class (which was
 *   exactly the agreement-upload latent bug Whitney's audit surfaced).
 *
 * Issue 3 — `margin: 0;` reset on all `input./textarea./select.`
 *   prefixed selectors so WP core forms.css `input, select
 *   { margin: 0 1px; }` doesn't cascade through.
 *
 * Issue 4 — repeating-table input classes use --eem-radius-sm (3px)
 *   for tighter chrome, per Whitney's pixel-explicit direction.
 *
 * Issue 5 — `select.` prefix on all .eem-* SELECT classes. Plus a
 *   select-class enumeration smoke (same shape as the C7.X.11 form-
 *   control enumeration for INPUT[TYPE="NUMBER"]) extended to SELECT.
 *
 * Issue 6 — folded into Issue 7; no separate assertion.
 *
 * Issue 7 — Linked Event hybrid placement (partial reversal of
 *   C7.X.12 Item 7). Meta-line revertsto read-only context;
 *   rail Linked Event card restored with Change link + ✕ icon Unlink.
 *   c7x12 smoke updated in this commit to reflect the new shape;
 *   this smoke adds positive assertions for the new pieces.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x15_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.15 — WALKTHROUGH FIX-UPS SMOKE ===\n";

$admin_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$admin_ctl = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

wp_set_current_user( 1 );
$_GET['reservation_id'] = 44;
foreach ( array(
	'_en_checkin_checkout_enabled',
	'_en_event_day_enabled',
	'_en_stalls_enabled',
	'_en_rv_enabled',
	'_en_general_addons_enabled',
	'_en_group_reservations_enabled',
	'_en_convenience_fee_enabled',
	'_en_venue_agreement_enabled',
	'_en_cancellation_enabled',
) as $key ) {
	update_post_meta( 44, $key, 1 );
}
ob_start(); EEM_Reservation_Editor_Page::render(); $html = (string) ob_get_clean();
$_GET = array();

// ── [Issue 2A] eemDispatchSave + nonce use generic selectors ─────
echo "\n[2A] Publish / Save Draft / Update — handlers use generic selectors (no .eem-save-bar coupling)\n";
c7x15_ok( 'eemReservationEditorNonce queries input[name="_eem_editor_nonce"] generically',
	false !== strpos( $js_src, "querySelector('input[name=\"_eem_editor_nonce\"]')" ),
	$pass, $fail, $log );
c7x15_ok( 'eemDispatchSave queries generic [data-eem-reservation-id] (not .eem-save-bar)',
	false !== strpos( $js_src, "querySelector('[data-eem-reservation-id]')" ),
	$pass, $fail, $log );
// Anti-pattern guard — neither lookup may reach back to `.eem-save-bar`.
c7x15_ok( 'eemDispatchSave NO longer queries .eem-save-bar (retired ancestor)',
	false === strpos( $js_src, "querySelector('.eem-save-bar')" ),
	$pass, $fail, $log );
c7x15_ok( 'eemReservationEditorNonce NO longer scopes to .eem-save-bar',
	false === strpos( $js_src, ".eem-save-bar input[name=\"_eem_editor_nonce\"]" ),
	$pass, $fail, $log );

// ── [Issue 2B] Agreement upload Media Library wiring ────────────
echo "\n[2B] Agreement upload — wp.media handler + wp_enqueue_media on editor page\n";
c7x15_ok( 'admin.js has reservation-editor-agreement-upload click handler',
	false !== strpos( $js_src, "[data-eem-action=\"reservation-editor-agreement-upload\"]" ),
	$pass, $fail, $log );
c7x15_ok( 'admin.js has reservation-editor-agreement-remove click handler',
	false !== strpos( $js_src, "[data-eem-action=\"reservation-editor-agreement-remove\"]" ),
	$pass, $fail, $log );
c7x15_ok( 'upload handler opens wp.media with PDF MIME restriction',
	false !== strpos( $js_src, "library: { type: 'application/pdf' }" ),
	$pass, $fail, $log );
c7x15_ok( 'admin loader calls wp_enqueue_media() on the reservation-editor screen',
	false !== strpos( $admin_ctl, 'wp_enqueue_media()' )
	&& false !== strpos( $admin_ctl, 'equine-event-manager-reservation-editor' ),
	$pass, $fail, $log );

// ── [Issue 2 STRUCTURAL] button-handler enumeration smoke ───────
echo "\n[2-structural] Button-handler enumeration — every data-eem-action button has a JS handler\n";
preg_match_all( '#data-eem-action="(reservation-editor-[a-z-]+)"#', $html, $btns );
$rendered_actions = array_unique( $btns[1] );
sort( $rendered_actions );
// Skip-list — typeahead `event-search` is a placeholder for a future
// backend search endpoint (rail card emits it as a stub input but it
// doesn't dispatch a click action; the typeahead UI is decorative
// pending the search backend). Excluded from the handler-coverage
// assertion. If/when the search backend lands, the handler should
// match `data-eem-action="reservation-editor-event-search"` and this
// skip can be removed.
$skip_actions = array( 'reservation-editor-event-search' );
$missing_handlers = array();
foreach ( $rendered_actions as $action ) {
	if ( in_array( $action, $skip_actions, true ) ) { continue; }
	if ( false === strpos( $js_src, 'data-eem-action="' . $action . '"' ) ) {
		$missing_handlers[] = $action;
	}
}
c7x15_ok( count( $rendered_actions ) . ' distinct data-eem-action attrs in editor (' . count( $skip_actions ) . ' skipped as backend-pending) — every actionable button has a JS handler',
	empty( $missing_handlers ),
	$pass, $fail, $log,
	empty( $missing_handlers ) ? 'enumerated: ' . implode( ',', $rendered_actions ) : 'missing handlers for: ' . implode( ',', $missing_handlers ) );

// ── [Issue 3] WP core margin reset on prefixed form-control rules ─
echo "\n[3] margin: 0; reset on every input./textarea./select. prefixed rule\n";
// Strip CSS block comments before scanning so audit-trail prose
// mentioning class names doesn't confuse the rule-opener regex.
$css_no_comments = preg_replace( '~/\*.*?\*/~s', '', $admin_css );
// Walk rule openers — find `input.|textarea.|select.eem-...{` (with
// possible selector list before the `{`). Use balanced-brace pairing
// to extract the rule block.
$prefixed_class_groups = array();
$offset = 0;
$len    = strlen( $css_no_comments );
while ( $offset < $len ) {
	// Find the next `{` that closes a selector that contains a prefixed token.
	if ( ! preg_match( '~((?:^|\})\s*[^{}]*?(?:input|textarea|select)\.eem-[a-z-]+[^{}]*?)\{~s', $css_no_comments, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		break;
	}
	$selector_text = $m[1][0];
	$brace_pos     = $m[0][1] + strlen( $m[0][0] );
	$end           = strpos( $css_no_comments, '}', $brace_pos );
	if ( false === $end ) { break; }
	$content = substr( $css_no_comments, $brace_pos, $end - $brace_pos );
	$offset  = $end + 1;
	// Skip :focus / :hover / ::placeholder variants — pseudos don't
	// need margin reset (the base rule's margin: 0 still cascades).
	if ( preg_match( '~:focus|:hover|::placeholder~', $selector_text ) ) { continue; }
	$has_margin_reset = (bool) preg_match( '~(?<![-\w])margin\s*:\s*0\b~', $content );
	$prefixed_class_groups[] = array( 'selector' => trim( $selector_text ), 'has_margin' => $has_margin_reset );
}
$missing_margin = array();
foreach ( $prefixed_class_groups as $rule ) {
	if ( ! $rule['has_margin'] ) { $missing_margin[] = $rule['selector']; }
}
c7x15_ok( count( $prefixed_class_groups ) . ' prefixed form-control rule block(s) — every base rule declares margin: 0',
	empty( $missing_margin ),
	$pass, $fail, $log,
	empty( $missing_margin ) ? '' : 'missing margin: 0 in: ' . implode( ' | ', array_map( function( $s ) { return preg_replace( '/\s+/', ' ', $s ); }, $missing_margin ) ) );

// ── [Issue 4] repeating-table input border-radius uses --eem-radius-sm ─
echo "\n[4] repeating-table input border-radius uses var(--eem-radius-sm) (3px) per Whitney\n";
foreach ( array(
	'eem-repeat-input',
	'eem-repeat-price-in',
	'eem-zone-name-input',
	'eem-zone-price-in',
) as $cls ) {
	// Match the prefixed rule's border-radius declaration; it should
	// reference --eem-radius-sm. Allow either full-shorthand (`var(--eem-
	// radius-sm)`) or asymmetric corner pattern (`0 var(--eem-radius-sm)
	// var(--eem-radius-sm) 0`).
	$has_sm_token = (bool) preg_match(
		'~input\.' . preg_quote( $cls, '~' ) . '\s*\{[^}]*border-radius\s*:\s*[^;]*var\(--eem-radius-sm\)[^;]*;~s',
		$admin_css
	);
	c7x15_ok( "input.{$cls} border-radius uses var(--eem-radius-sm)", $has_sm_token, $pass, $fail, $log );
}

// ── [Issue 5] select. prefix on all .eem-* SELECT classes ────────
echo "\n[5] select. prefix on every .eem-* SELECT class (ties WP core forms.css)\n";
$select_classes = array(
	'eem-field-select',
	'eem-list-select',
	'eem-toolbar-select',
	'eem-dashboard-range-select',
);
foreach ( $select_classes as $cls ) {
	c7x15_ok( "admin.css declares select.{$cls} (specificity-tie to WP core)",
		false !== strpos( $admin_css, "select.{$cls}" ),
		$pass, $fail, $log );
}

// STRUCTURAL — select-class enumeration (extends C7.X.11 pattern to SELECT).
// Render dashboard + reservations-list + orders-list pages along with
// editor to enumerate the full surface where .eem-* on <select> can appear.
$pages_html = $html;
foreach ( array(
	'EEM_Dashboard_Page' => 'render',
	'EEM_Reservations_List_Page' => 'render',
	'EEM_Orders_List_Page' => 'render',
) as $cls => $method ) {
	if ( ! class_exists( $cls ) || ! method_exists( $cls, $method ) ) { continue; }
	$_GET = array();
	ob_start();
	if ( 'EEM_Reservations_List_Page' === $cls || 'EEM_Orders_List_Page' === $cls ) {
		$inst = new $cls();
		$inst->$method();
	} else {
		$cls::$method();
	}
	$pages_html .= ob_get_clean();
}
preg_match_all( '#<select[^>]*class="([^"]+)"#', $pages_html, $sm );
$enumerated = array();
foreach ( $sm[1] as $attr ) {
	foreach ( preg_split( '/\s+/', trim( $attr ) ) as $tok ) {
		if ( '' !== $tok && 0 === strpos( $tok, 'eem-' ) ) {
			$enumerated[ $tok ] = true;
		}
	}
}
$enumerated_keys = array_keys( $enumerated );
sort( $enumerated_keys );
$missing_select_prefix = array();
foreach ( $enumerated_keys as $cls ) {
	if ( false === strpos( $admin_css, "select.{$cls}" ) ) {
		$missing_select_prefix[] = $cls;
	}
}
c7x15_ok( count( $enumerated_keys ) . ' .eem-* class(es) enumerated on <select> elements across editor/dashboard/lists — every one has select. prefix',
	empty( $missing_select_prefix ),
	$pass, $fail, $log,
	empty( $missing_select_prefix ) ? 'enumerated: ' . implode( ',', $enumerated_keys ) : 'missing prefix: ' . implode( ',', $missing_select_prefix ) );

// ── [Issue 7 positive] hybrid restoration assertions ───────────
echo "\n[7] Linked Event hybrid restoration — meta-line read-only + rail card actionable\n";
$_GET = array(); $_GET['reservation_id'] = 44;
ob_start(); EEM_Reservation_Editor_Page::render(); $html44 = (string) ob_get_clean();
$_GET = array();

c7x15_ok( 'rail card emits Change text link (class="eem-event-linked-change")',
	false !== strpos( $html44, 'class="eem-event-linked-change"' ),
	$pass, $fail, $log );
c7x15_ok( 'rail card emits ✕ icon-only Unlink button (class="eem-event-unlink-icon")',
	false !== strpos( $html44, 'class="eem-event-unlink-icon"' ),
	$pass, $fail, $log );
c7x15_ok( 'rail card unlink button has aria-label="Unlink event" for accessibility',
	false !== strpos( $html44, 'aria-label="Unlink event"' ),
	$pass, $fail, $log );
c7x15_ok( 'rail card unlink button has title="Unlink event" tooltip',
	false !== strpos( $html44, 'title="Unlink event"' ),
	$pass, $fail, $log );

// Cache-bust constant.
c7x15_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.4',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.4', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
