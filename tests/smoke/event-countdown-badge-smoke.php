<?php
/**
 * Smoke — Events flyer variant: countdown badge + flyer thumbnail (ROADMAP #22).
 *
 * Exercises the real EEM_Events::event_countdown() date logic and asserts the
 * event-card render wiring (countdown badge in the meta row, flyer thumbnail
 * with graceful fallback to the "View Flyer" link) + the badge/thumb CSS.
 *
 * Run: wp eval-file tests/smoke/event-countdown-badge-smoke.php
 */

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- real countdown logic (fixed reference now = 2026-06-23) ----------------
$now = strtotime( '2026-06-23' );
$cases = array(
	array( '2026-07-05', '2026-07-08', 'In 12 days', 'upcoming' ),
	array( '2026-06-24', '',           'Tomorrow',      'upcoming' ),
	array( '2026-06-25', '',           'In 2 days',     'upcoming' ),
	array( '2026-06-23', '2026-06-25', 'Starts today',  'now' ),
	array( '2026-06-20', '2026-06-25', 'Happening now', 'now' ),
	array( '2026-06-10', '2026-06-15', 'Ended',         'past' ),
);
foreach ( $cases as $c ) {
	$r = EEM_Events::event_countdown( $c[0], $c[1], $now );
	$check( "countdown '{$c[0]}' -> '{$c[2]}' ({$c[3]})", $r['label'] === $c[2] && $r['tone'] === $c[3] );
}
$r = EEM_Events::event_countdown( '', '', $now );
$check( 'no start date -> empty label', '' === $r['label'] );

// --- card render wiring -----------------------------------------------------
$base = dirname( __DIR__, 2 );
$src  = (string) file_get_contents( $base . '/includes/class-equine-event-manager-events.php' );
$css  = (string) file_get_contents( $base . '/assets/css/public.css' );

$check( 'card computes the countdown', false !== strpos( $src, '$countdown   = self::event_countdown(' ) );
$check( 'card renders the countdown badge', false !== strpos( $src, 'eem-event-card__countdown eem-event-card__countdown--' ) );
$check( 'card computes a best-effort flyer thumbnail', false !== strpos( $src, "wp_get_attachment_image(" ) && false !== strpos( $src, '$flyer_thumb' ) );
$check( 'flyer falls back to the View Flyer link when no thumb', false !== strpos( $src, "'' !== \$flyer_thumb" ) && false !== strpos( $src, 'View Flyer' ) );

$check( 'CSS styles the meta row', false !== strpos( $css, '.eem-event-card__meta-row {' ) );
$check( 'CSS styles the countdown badge', false !== strpos( $css, '.eem-event-card__countdown {' ) );
$check( 'CSS has upcoming + now + past tones', false !== strpos( $css, '.eem-event-card__countdown--upcoming' ) && false !== strpos( $css, '.eem-event-card__countdown--now' ) && false !== strpos( $css, '.eem-event-card__countdown--past' ) );
$check( 'CSS styles the flyer thumbnail', false !== strpos( $css, '.eem-event-card__flyer-thumb {' ) );

echo "\nNOTE: countdown self-verified; flyer thumbnail renders for native events\n";
echo "whose flyer attachment has a WP-generated preview, else the View Flyer link.\n";
echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
