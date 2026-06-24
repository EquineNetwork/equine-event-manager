/**
 * EEM — Choices.js initialiser (CLEANUP #21).
 *
 * Upgrades the Orders event filter + Reservations date filter from a native
 * <select> to a searchable Choices.js dropdown (typeahead + keyboard nav), which
 * scales past ~50 events where the native select becomes an unusable long scroll.
 *
 * Targets any `select[data-eem-choices]`. Choices keeps the original <select> in
 * the DOM and dispatches a native `change` event on it when a choice is made, so
 * the existing inline `onchange="this.form.submit()"` auto-submit still fires.
 *
 * @package EEM_Plugin
 */
( function () {
	'use strict';

	function initEemChoices() {
		if ( typeof window.Choices === 'undefined' ) {
			return;
		}

		var selects = document.querySelectorAll( 'select[data-eem-choices]' );
		Array.prototype.forEach.call( selects, function ( sel ) {
			if ( sel.dataset.eemChoicesReady ) {
				return;
			}
			sel.dataset.eemChoicesReady = '1';

			/* eslint-disable no-new */
			new window.Choices( sel, {
				searchEnabled: true,
				searchResultLimit: 50,
				shouldSort: false, // preserve the server-provided option order
				itemSelectText: '', // no "Press to select" hint
				// allowHTML:true so Choices injects the option's already-escaped
				// innerHTML once instead of escaping it a SECOND time — otherwise a
				// title like "Stars & Stripes" (server-rendered as "Stars &amp;
				// Stripes") displays the literal "Stars &amp; Stripes". Safe here
				// because every option label is server-side esc_html'd, so no active
				// HTML can reach the DOM.
				allowHTML: true,
				searchPlaceholderValue: sel.getAttribute( 'data-eem-choices-search' ) || 'Search…',
				position: 'bottom',
				classNames: {
					containerOuter: 'choices eem-choices',
				},
			} );
			/* eslint-enable no-new */
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initEemChoices );
	} else {
		initEemChoices();
	}
}() );
