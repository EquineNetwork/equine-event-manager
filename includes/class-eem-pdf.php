<?php
/**
 * PDF rendering helper (C12).
 *
 * Thin wrapper around Pelago's bundled Dompdf for turning self-contained HTML
 * (the order receipt template) into PDF bytes. Degrades gracefully: when Dompdf
 * is unavailable (vendor/ absent) callers get an empty string and should skip
 * the PDF rather than fatal.
 *
 * Security: remote resource loading is DISABLED. The receipt embeds its logo as
 * a data URI and its brand fonts are pre-registered in Dompdf's bundled font
 * directory, so no network fetch is needed at render time — and disabling remote
 * loading closes the SSRF surface that order-controlled content could open.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders HTML to PDF via Dompdf.
 */
class EEM_PDF {

	/**
	 * Whether the Dompdf engine is loadable.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( '\\Dompdf\\Dompdf' );
	}

	/**
	 * Render self-contained HTML to PDF bytes.
	 *
	 * @param string $html        Full HTML document (with its own <style>).
	 * @param string $paper       Paper size slug (default 'letter').
	 * @param string $orientation 'portrait' | 'landscape'.
	 * @return string PDF bytes, or '' when Dompdf is unavailable or render fails.
	 */
	public static function render( string $html, string $paper = 'letter', string $orientation = 'portrait' ): string {
		if ( '' === trim( $html ) || ! self::is_available() ) {
			return '';
		}

		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'defaultFont', 'DejaVu Sans' );

			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( $paper, 'landscape' === $orientation ? 'landscape' : 'portrait' );
			$dompdf->render();

			$output = $dompdf->output();

			return ( is_string( $output ) && '%PDF' === substr( $output, 0, 4 ) ) ? $output : '';
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EEM_PDF render failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return '';
		}
	}
}
