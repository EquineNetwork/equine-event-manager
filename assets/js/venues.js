/**
 * Venues detail page — saved-layout rename + delete (v2 Facility Layout Templates).
 *
 * Delegated handlers for the `venue-layout-rename` / `venue-layout-delete`
 * buttons rendered by EEM_Venues_Page::render_detail(). Both open a modal built
 * with the canonical `.eem-modal` / `.eem-modal-card` / `.eem-modal-head` /
 * `.eem-modal-body` / `.eem-modal-foot` chrome + `.open` (C7.X.20 reference
 * pattern — JS-built modals MUST use these exact class names or they render
 * invisibly). On success the row is updated/removed in place and a toast fires.
 *
 * AJAX: eem_venue_rename_layout / eem_venue_delete_layout, guarded by the
 * `eem_venue_layout` nonce stamped on `.eem-venue-detail[data-venue-nonce]`.
 *
 * @package EEM_Plugin
 */
(function () {
	'use strict';

	function ajaxUrl() {
		return (window.eemVenues && window.eemVenues.ajaxUrl) || (window.ajaxurl || '/wp-admin/admin-ajax.php');
	}

	function nonce() {
		var root = document.querySelector('.eem-venue-detail');
		return root ? (root.getAttribute('data-venue-nonce') || '') : '';
	}

	function toast(msg) {
		if (window.EEM && typeof window.EEM.showSaveToast === 'function') {
			window.EEM.showSaveToast(msg);
		}
	}

	/* Build + open a modal; returns the overlay element. */
	function openModal(opts) {
		var existing = document.getElementById('eem-venue-modal');
		if (existing) existing.remove();

		var overlay = document.createElement('div');
		overlay.id = 'eem-venue-modal';
		overlay.className = 'eem-modal';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="eem-modal-card">' +
				'<div class="eem-modal-head' + (opts.danger ? ' eem-modal-head--danger' : '') + '">' +
					'<h2 class="eem-modal-title' + (opts.danger ? ' eem-modal-title--danger' : '') + '">' + opts.title + '</h2>' +
				'</div>' +
				'<div class="eem-modal-body">' + opts.body + '</div>' +
				'<div class="eem-modal-foot">' +
					'<button type="button" class="eem-btn eem-btn-secondary" data-role="cancel">' + (opts.cancelLabel || 'Cancel') + '</button>' +
					'<button type="button" class="eem-btn ' + (opts.danger ? 'eem-btn-danger' : 'eem-btn-primary') + '" data-role="confirm"' + (opts.confirmDisabled ? ' disabled' : '') + '>' + opts.confirmLabel + '</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(overlay);
		overlay.classList.add('open');

		var close = function () { overlay.remove(); };
		overlay.querySelector('[data-role="cancel"]').addEventListener('click', close);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
		overlay.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
		overlay._close = close;
		return overlay;
	}

	function openRename(layoutId, currentName) {
		var overlay = openModal({
			title: 'Rename layout',
			body: '<label class="eem-field-label" for="eem-venue-rename-input">Layout name</label>' +
				'<input type="text" id="eem-venue-rename-input" class="eem-field-input" style="width:100%;box-sizing:border-box;" autocomplete="off">',
			confirmLabel: 'Save',
			confirmDisabled: true
		});
		var input = overlay.querySelector('#eem-venue-rename-input');
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		input.value = currentName || '';
		var validate = function () {
			var v = input.value.trim();
			confirmBtn.disabled = (v === '' || v === (currentName || ''));
		};
		input.addEventListener('input', validate);
		overlay.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !confirmBtn.disabled) confirmBtn.click(); });
		confirmBtn.addEventListener('click', function () {
			var name = input.value.trim();
			if (!name) return;
			confirmBtn.disabled = true;
			postLayout('eem_venue_rename_layout', { layout_id: layoutId, name: name }, function () {
				var cell = document.querySelector('tr[data-layout-id="' + layoutId + '"] .eem-venue-layout-name');
				if (cell) cell.textContent = name;
				var btn = document.querySelector('[data-eem-action="venue-layout-rename"][data-layout-id="' + layoutId + '"]');
				if (btn) btn.setAttribute('data-layout-name', name);
				overlay._close();
				toast('Layout renamed');
			}, function (msg) {
				confirmBtn.disabled = false;
				alert(msg || 'Could not rename the layout.');
			});
		});
		input.focus();
		input.select();
	}

	function openDelete(layoutId, name) {
		var overlay = openModal({
			title: 'Delete layout?',
			danger: true,
			body: '<p style="margin:0 0 6px;color:var(--eem-error-text);font-weight:600;">This removes the saved layout “' + escapeHtml(name) + '”.</p>' +
				'<p style="margin:0;">Reservations already built from it keep their copy — only the saved template is removed.</p>',
			confirmLabel: 'Delete Layout'
		});
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			postLayout('eem_venue_delete_layout', { layout_id: layoutId }, function () {
				var row = document.querySelector('tr[data-layout-id="' + layoutId + '"]');
				if (row) row.remove();
				overlay._close();
				toast('Layout deleted');
			}, function (msg) {
				confirmBtn.disabled = false;
				alert(msg || 'Could not delete the layout.');
			});
		});
	}

	function postLayout(action, data, onOk, onErr) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce());
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success) { onOk(json.data || {}); }
				else { onErr(json && json.data && json.data.message ? json.data.message : ''); }
			})
			.catch(function () { onErr(''); });
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	document.addEventListener('click', function (e) {
		var rename = e.target.closest('[data-eem-action="venue-layout-rename"]');
		if (rename) {
			e.preventDefault();
			openRename(rename.getAttribute('data-layout-id'), rename.getAttribute('data-layout-name'));
			return;
		}
		var del = e.target.closest('[data-eem-action="venue-layout-delete"]');
		if (del) {
			e.preventDefault();
			openDelete(del.getAttribute('data-layout-id'), del.getAttribute('data-layout-name'));
		}
	});
})();
