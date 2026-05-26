<?php
/**
 * Equine Event Manager — plugin-wide inline SVG icons registry.
 *
 * Single source of truth for every icon glyph rendered across the
 * plugin's admin surfaces. Originally introduced in DS-1.B.1 to cover
 * the Admin Dashboard page; expanded in C7.B.3 with 5 new glyphs
 * for the Reservation Editor section chips (file-text, map-pin, truck,
 * file, shield-x). The class name carries "Dashboard" for historical
 * reasons — kept stable to avoid call-site churn across the 22+ existing
 * Dashboard consumers. Treat as the plugin-wide icon registry.
 *
 * SVG paths extracted verbatim from canonical mockups
 * (`.mockups/dashboard_page.html` for DS-1.B-vintage glyphs;
 * `.mockups/edit_reservation_page.html` for C7.B.3 additions). Each key
 * is registered once even when the same glyph appears in multiple slots
 * (e.g. the `grid` glyph appears on the Unassigned Stalls KPI, the
 * Stall Charts quick-action tile, the "stalls unassigned" attention row,
 * AND the Reservation Editor Stall Reservations section chip).
 *
 * Call sites pass the icon key only — wrapper classes (icon-background
 * tones, sizing) live on the surrounding `<span>` / `<div>` in the
 * render so the glyph itself stays presentation-neutral. Feather Icons
 * naming convention used throughout for consistency.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 2.2.0
 */
class EEM_Dashboard_Icons {

	/**
	 * Return the inline SVG markup for a registered icon key. Returns
	 * empty string for unknown keys — render code MUST pass a valid
	 * key. Output is pre-escaped (we author the SVG paths ourselves;
	 * no user data flows through here).
	 *
	 * @param string $key
	 * @return string
	 */
	public static function svg( $key ) {
		$icons = self::map();
		if ( ! isset( $icons[ $key ] ) ) {
			return '';
		}
		// All paths use stroke="currentColor" so they inherit the parent
		// element's color — wrapper class drives the visible tone.
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. $icons[ $key ]
			. '</svg>';
	}

	/**
	 * @return array<string, string>  key → inner SVG markup
	 */
	private static function map() {
		return array(
			// Header + Quick Action plus glyph (lines 287, 548 of mockup).
			'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
			// Header View Reservations + Upcoming Reservations card title (lines 291, 357).
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
			// KPI Total Revenue (line 316) — dollar sign.
			'dollar' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>',
			// KPI Outstanding Payments + Needs Attention card title + attention row 6 (lines 324, 431, 483).
			'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
			// KPI Total Orders + Recent Orders card title (lines 332, 496) — package.
			'package' => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/>',
			// KPI Unassigned Stalls + Stall Charts quick-action + attention row 1 (lines 340, 438, 552) — grid.
			'grid' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
			// Quick Actions card title (line 542) — lightning.
			'lightning' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
			// Revenue chart card title (line 570) — bar chart.
			'bar-chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
			// This Week card title (line 613) — clock.
			'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
			// Attention row 2 + Collect Payment quick-action tile (lines 447, 556) — credit card.
			'card' => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
			// Attention row 3 (line 456) — alert triangle (RV lot issues).
			'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
			// Attention row 4 (line 465) — mail (agreement signature).
			'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
			// Attention row 5 (line 474) — users.
			'users' => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
			// Export Report quick-action (line 560) — download.
			'download' => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',

			// C7.B.3 — Reservation Editor section chip glyphs.
			// Source: .mockups/edit_reservation_page.html (Feather Icons).

			// Reservation Description (mockup line 372) — file with lines.
			'file-text' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
			// Event Day Info (mockup line 430) — map-pin.
			'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
			// RV Reservations (mockup line 654) — truck/RV.
			'truck' => '<rect x="1" y="6" width="15" height="12" rx="2"/><path d="M16 9h4l3 3v6h-7"/><circle cx="6" cy="20" r="2"/><circle cx="18" cy="20" r="2"/>',
			// Agreement (mockup line 1059) — file with single line (variant of file-text).
			'file' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/>',
			// Cancellation Policy (mockup line 1097) — shield with X.
			'shield-x' => '<path d="M12 22s-8-4.5-8-11.8A8 8 0 0112 2a8 8 0 018 8.2c0 7.3-8 11.8-8 11.8z"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/>',

			// C7.C.1.3 — Reservation Editor section-header collapse chevron.
			// Mockup line 377 (and every other section): down-pointing
			// caret that rotates -90deg into a right-pointing caret when
			// the section is collapsed (CSS rule .eem-section-collapsed
			// .eem-section-chevron). Reusable across future collapse /
			// accordion / dropdown surfaces.
			'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
		);
	}
}
