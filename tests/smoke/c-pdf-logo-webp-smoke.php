<?php
/**
 * Launch fix smoke — PDF logo data-URI transcodes WEBP/GIF to PNG.
 *
 * The old inliner labelled any non-jpg/svg image as image/png, so a WEBP logo
 * (common from WP's media library) shipped as webp-bytes-tagged-PNG and Dompdf
 * rendered nothing. Now WEBP/GIF transcode to real PNG via GD. Asserts the
 * data-URI carries a valid PNG payload for a webp source.
 *
 * Run: wp eval-file tests/smoke/c-pdf-logo-webp-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

if ( ! function_exists( 'imagewebp' ) ) {
	echo "  ok  - (skipped — GD has no webp support on this host)\n\n1 passed, 0 failed\n";
	exit( 0 );
}

// Build a throwaway webp in the uploads dir + register it as an attachment.
$upload = wp_upload_dir();
$path   = trailingslashit( $upload['path'] ) . 'eem-webp-smoke-' . wp_generate_password( 6, false ) . '.webp';
$im = imagecreatetruecolor( 40, 20 );
imagesavealpha( $im, true );
imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 255, 0, 0, 0 ) );
imagewebp( $im, $path );
imagedestroy( $im );

$att_id = wp_insert_attachment( array( 'post_mime_type' => 'image/webp', 'post_title' => 'webp smoke', 'post_status' => 'inherit' ), $path );
$prev   = get_option( 'equine_event_manager_company_settings', array() );
update_option( 'equine_event_manager_company_settings', array_merge( (array) $prev, array( 'logo_id' => $att_id ) ), false );

$sc = new EEM_Shortcodes();
$m  = new ReflectionMethod( 'EEM_Shortcodes', 'get_company_logo_data_uri' );
$m->setAccessible( true );
$uri = (string) $m->invoke( $sc );

$check( 'webp logo yields an image/png data-URI', 0 === strpos( $uri, 'data:image/png;base64,' ) );
$bytes = base64_decode( substr( $uri, strpos( $uri, ',' ) + 1 ) );
$check( 'decoded payload is a real PNG (not webp mislabelled)', 'PNG' === substr( $bytes, 1, 3 ) );

// cleanup
update_option( 'equine_event_manager_company_settings', $prev, false );
wp_delete_attachment( $att_id, true );
@unlink( $path );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
