/**
 * Edit Reservation — Save Layout / Load Layout to Venue (v2 Venues, Slice 3).
 *
 * Delegated handlers for the `venue-save-layout` / `venue-load-layout` buttons
 * rendered by templates/admin/reservation-editor/_layout-template-bar.php inside
 * both the stall and RV builders. Layouts are saved per-type (stall or RV) so
 * each builder saves only its own map/rows data.
 *
 *  - Save: prompts for a layout name → eem_venue_save_layout (snapshots the
 *    reservation's saved layout to its resolved venue).
 *  - Load: eem_venue_list_layouts → picker modal → eem_venue_load_layout
 *    (copy-on-use clone into THIS reservation) → reload so the builders re-render.
 *
 * Modals use the canonical .eem-modal / .eem-modal-card / .eem-modal-head /
 * .eem-modal-body / .eem-modal-foot chrome + .open (C7.X.20 reference pattern).
 * Reservation id is read from .eem-reservation-editor-body[data-eem-reservation-id].
 *
 * @package EEM_Plugin
 */
(function () {
	'use strict';

	function cfg() { return window.eemVenueLayouts || {}; }
	function ajaxUrl() { return cfg().ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php'; }
	function nonce() { return cfg().nonce || ''; }

	function reservationId() {
		var el = document.querySelector('[data-eem-reservation-id]');
		return el ? (el.getAttribute('data-eem-reservation-id') || '0') : '0';
	}

	function toast(msg) {
		if (window.EEM && typeof window.EEM.showSaveToast === 'function') { window.EEM.showSaveToast(msg); }
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce());
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(ajaxUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function openModal(opts) {
		var existing = document.getElementById('eem-venue-layout-modal');
		if (existing) existing.remove();
		var overlay = document.createElement('div');
		overlay.id = 'eem-venue-layout-modal';
		overlay.className = 'eem-modal';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="eem-modal-card">' +
				'<div class="eem-modal-head"><h2 class="eem-modal-title">' + opts.title + '</h2></div>' +
				'<div class="eem-modal-body">' + opts.body + '</div>' +
				'<div class="eem-modal-foot">' +
					'<button type="button" class="eem-btn eem-btn-secondary" data-role="cancel">' + (opts.cancelLabel || 'Cancel') + '</button>' +
					'<button type="button" class="eem-btn eem-btn-primary" data-role="confirm"' + (opts.confirmDisabled ? ' disabled' : '') + '>' + opts.confirmLabel + '</button>' +
				'</div>' +
			'</div>';
		// Append inside the active map builder overlay when open (z-index 100000)
		// so the modal stacks above it. Fall back to document.body otherwise.
		var mbHost = document.querySelector('.eem-mb-overlay.open') || document.body;
		mbHost.appendChild(overlay);
		overlay.classList.add('open');
		var close = function () { overlay.remove(); };
		overlay.querySelector('[data-role="cancel"]').addEventListener('click', close);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
		overlay.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
		overlay._close = close;
		return overlay;
	}

	/* ── Save ──────────────────────────────────────────────────── */
	function typeLabel(t) { return t === ‘rv’ ? ‘RV’ : t === ‘stall’ ? ‘Stall’ : ‘Stall & RV’; }

	function openSave(layoutType) {
		var lt = layoutType || ‘combined’;
		var overlay = openModal({
			title: ‘Save ‘ + typeLabel(lt) + ‘ Layout to Venue’,
			body: ‘<p style="margin:0 0 10px;">Saves this reservation\’s current‘ + typeLabel(lt).toLowerCase() + ‘ layout to its venue so it can be reused on future reservations.</p>’ +
				‘<label class="eem-field-label" for="eem-venue-save-name">Layout name</label>’ +
				‘<input type="text" id="eem-venue-save-name" class="eem-field-input" style="width:100%;box-sizing:border-box;" placeholder="e.g. 2026 Main Barn Layout" autocomplete="off">’,
			confirmLabel: ‘Save Layout’,
			confirmDisabled: true
		});
		var input = overlay.querySelector(‘#eem-venue-save-name’);
		var confirmBtn = overlay.querySelector(‘[data-role="confirm"]’);
		input.addEventListener(‘input’, function () { confirmBtn.disabled = (input.value.trim() === ‘’); });
		overlay.addEventListener(‘keydown’, function (e) { if (e.key === ‘Enter’ && !confirmBtn.disabled) confirmBtn.click(); });
		confirmBtn.addEventListener(‘click’, function () {
			var name = input.value.trim();
			if (!name) return;
			confirmBtn.disabled = true;
			post(‘eem_venue_save_layout’, { reservation_id: reservationId(), name: name, layout_type: lt }).then(function (json) {
				if (json && json.success) {
					overlay._close();
					toast(json.data && json.data.venue_name ? ('Layout saved to ' + json.data.venue_name) : 'Layout saved');
				} else {
					confirmBtn.disabled = false;
					showError(overlay, json && json.data && json.data.message ? json.data.message : 'Could not save the layout.');
				}
			}).catch(function () { confirmBtn.disabled = false; showError(overlay, 'Could not save the layout.'); });
		});
		input.focus();
	}

	/* ── Load ──────────────────────────────────────────────────── */
	function openLoad(layoutType) {
		var lt = layoutType || 'combined';
		// Pass layout_type: '' to fetch ALL layouts for the venue — combined layouts
		// can be loaded from either the stall or RV builder, and hiding them would
		// make the picker empty for all pre-split (combined) saved layouts.
		post('eem_venue_list_layouts', { reservation_id: reservationId(), layout_type: '' }).then(function (json) {
			if (!json || !json.success) {
				alert(json && json.data && json.data.message ? json.data.message : 'Could not load layouts.');
				return;
			}
			var layouts = (json.data && json.data.layouts) || [];
			var venueName = (json.data && json.data.venue_name) || '';
			if (!layouts.length) {
				openModal({
					title: 'Load Layout from Venue',
					body: '<p style="margin:0;">' + (venueName
						? ('No saved ' + typeLabel(lt).toLowerCase() + ' layouts for ' + escapeHtml(venueName) + ' yet. Use “Save Layout” to create one.')
						: 'This reservation isn’t linked to an event yet, so it has no venue to load layouts from.') + '</p>',
					confirmLabel: 'OK'
				}).querySelector('[data-role="confirm"]').addEventListener('click', function () {
					document.getElementById('eem-venue-layout-modal').remove();
				});
				return;
			}
			var rows = layouts.map(function (l, i) {
				return '<label class="eem-venue-load-option">' +
					'<input type="radio" name="eem-venue-load-pick" value="' + l.id + '"' + (i === 0 ? ' checked' : '') + '>' +
					'<span class="eem-venue-load-option__name">' + escapeHtml(l.name) + '</span>' +
					'<span class="eem-venue-load-option__date">' + escapeHtml(l.created) + '</span>' +
				'</label>';
			}).join('');
			var overlay = openModal({
				title: 'Load Layout from Venue',
				body: '<p style="margin:0 0 10px;">Loading a layout <strong>replaces</strong> this reservation’s current stall &amp; RV layout with a copy from ' + escapeHtml(venueName) + '. The saved venue layout is not changed.</p>' +
					'<div class="eem-venue-load-options">' + rows + '</div>',
				confirmLabel: 'Load Layout'
			});
			var confirmBtn = overlay.querySelector('[data-role="confirm"]');
			confirmBtn.addEventListener('click', function () {
				var picked = overlay.querySelector('input[name="eem-venue-load-pick"]:checked');
				if (!picked) return;
				confirmBtn.disabled = true;
				post('eem_venue_load_layout', { reservation_id: reservationId(), layout_id: picked.value }).then(function (resp) {
					if (resp && resp.success) {
						toast('Layout loaded');
						window.location.reload();
					} else {
						confirmBtn.disabled = false;
						showError(overlay, resp && resp.data && resp.data.message ? resp.data.message : 'Could not load the layout.');
					}
				}).catch(function () { confirmBtn.disabled = false; showError(overlay, 'Could not load the layout.'); });
			});
		}).catch(function () { alert('Could not load layouts.'); });
	}

	function showError(overlay, msg) {
		var body = overlay.querySelector('.eem-modal-body');
		var err = overlay.querySelector('.eem-venue-layout-error');
		if (!err) {
			err = document.createElement('p');
			err.className = 'eem-venue-layout-error';
			err.style.cssText = 'margin:10px 0 0;color:var(--eem-error-text,#b91c1c);font-weight:600;';
			body.appendChild(err);
		}
		err.textContent = msg;
	}

	function detectLayoutType(el) {
		var overlay = el.closest('.eem-mb-overlay');
		if (overlay && overlay.__eemTarget) { return overlay.__eemTarget; }
		return 'combined';
	}

	/* Expose on window.EEM so admin.js dispatcher can delegate directly. */
	window.EEM = window.EEM || {};
	window.EEM.venueLayoutSave = function (target) { openSave(detectLayoutType(target)); };
	window.EEM.venueLayoutLoad = function (target) { openLoad(detectLayoutType(target)); };
})();
