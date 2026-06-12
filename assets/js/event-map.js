/**
 * Events map view ([en_events view="map"]) — Native Events (v3).
 *
 * Defines the global `eemInitEventMaps` callback that the Google Maps JS loader
 * invokes once the API is ready. For each `.eem-event-map[data-events]` container
 * it builds a map, drops a marker per event (venue coordinates), wires an info
 * window linking to the event, and fits the viewport to all pins.
 *
 * @package EEM_Plugin
 */
(function () {
	'use strict';

	function buildMap(container) {
		if (container.dataset.eemMapReady === '1') { return; }
		var pins;
		try { pins = JSON.parse(container.getAttribute('data-events') || '[]'); }
		catch (e) { pins = []; }
		if (!pins.length || !(window.google && google.maps)) { return; }
		container.dataset.eemMapReady = '1';

		var map = new google.maps.Map(container, {
			zoom: 6,
			mapTypeControl: false,
			streetViewControl: false,
			fullscreenControl: true
		});
		var bounds = new google.maps.LatLngBounds();
		var info = new google.maps.InfoWindow();

		pins.forEach(function (pin) {
			var pos = { lat: Number(pin.lat), lng: Number(pin.lng) };
			if (isNaN(pos.lat) || isNaN(pos.lng)) { return; }
			var marker = new google.maps.Marker({ position: pos, map: map, title: pin.title || '' });
			bounds.extend(pos);
			marker.addListener('click', function () {
				info.setContent(infoHtml(pin));
				info.open(map, marker);
			});
		});

		if (!bounds.isEmpty()) {
			map.fitBounds(bounds);
			// Don't over-zoom when there's a single pin.
			google.maps.event.addListenerOnce(map, 'idle', function () {
				if (map.getZoom() > 13) { map.setZoom(13); }
			});
		}
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function infoHtml(pin) {
		var html = '<div class="eem-event-map-info">';
		if (pin.url) {
			html += '<a class="eem-event-map-info__title" href="' + esc(pin.url) + '">' + esc(pin.title) + '</a>';
		} else {
			html += '<span class="eem-event-map-info__title">' + esc(pin.title) + '</span>';
		}
		if (pin.date) { html += '<div class="eem-event-map-info__date">' + esc(pin.date) + '</div>'; }
		if (pin.venue) { html += '<div class="eem-event-map-info__venue">' + esc(pin.venue) + '</div>'; }
		html += '</div>';
		return html;
	}

	window.eemInitEventMaps = function () {
		var maps = document.querySelectorAll('.eem-event-map[data-events]');
		for (var i = 0; i < maps.length; i++) { buildMap(maps[i]); }
	};

	// If Google Maps was already present when this script ran (e.g. another map
	// on the page), initialize immediately.
	if (window.google && google.maps) { window.eemInitEventMaps(); }
})();
