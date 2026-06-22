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
		var root = document.querySelector('[data-venue-nonce]');
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
			'<div class="eem-modal-card"' + (opts.wide ? ' style="max-width:720px;"' : '') + '>' +
				'<div class="eem-modal-head' + (opts.danger ? ' eem-modal-head--danger' : '') + '">' +
					'<h2 class="eem-modal-title' + (opts.danger ? ' eem-modal-title--danger' : '') + '">' + opts.title + '</h2>' +
				'</div>' +
				'<div class="eem-modal-body">' + opts.body + '</div>' +
				'<div class="eem-modal-foot">' +
					(opts.hideCancel ? '' : '<button type="button" class="eem-btn eem-btn-secondary" data-role="cancel">' + (opts.cancelLabel || 'Cancel') + '</button>') +
					'<button type="button" class="eem-btn ' + (opts.danger ? 'eem-btn-danger' : 'eem-btn-primary') + '" data-role="confirm"' + (opts.confirmDisabled ? ' disabled' : '') + '>' + opts.confirmLabel + '</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(overlay);
		overlay.classList.add('open');

		var close = function () { overlay.remove(); };
		var cancelBtn = overlay.querySelector('[data-role="cancel"]');
		if (cancelBtn) cancelBtn.addEventListener('click', close);
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

	function openView(layoutId, name) {
		var overlay = openModal({
			title: name || 'Layout Preview',
			body: '<p style="text-align:center;color:#666;">Loading layout&hellip;</p>',
			confirmLabel: 'Close',
			hideCancel: true,
			wide: true
		});
		overlay.querySelector('[data-role="confirm"]').addEventListener('click', function () { overlay._close(); });
		var body = overlay.querySelector('.eem-modal-body');
		postLayout('eem_venue_view_layout', { layout_id: layoutId }, function (data) {
			body.innerHTML = buildGridPreview(data);
		}, function (msg) {
			body.innerHTML = '<p style="color:var(--eem-error-text);">' + escapeHtml(msg || 'Could not load layout.') + '</p>';
		});
	}

	function buildGridPreview(data) {
		var html = '';
		var stallRows = data.stall_rows || [];
		var rvRows = data.rv_rows || [];
		var stallMap = data.stall_map || {};
		var rvMap = data.rv_map || {};
		var hasStalls = stallRows.length > 0 || hasBarns(stallMap);
		var hasRv = rvRows.length > 0 || hasBarns(rvMap);

		if (hasStalls) {
			if (hasStalls && hasRv) html += '<h3 style="margin:0 0 8px;font-size:14px;font-weight:600;">Stalls</h3>';
			html += stallRows.length > 0 ? renderRowsPreview(stallRows) : renderMapGrid(stallMap);
		}
		if (hasRv) {
			if (hasStalls && hasRv) html += '<h3 style="margin:16px 0 8px;font-size:14px;font-weight:600;">RV Lots</h3>';
			html += rvRows.length > 0 ? renderRowsPreview(rvRows) : renderMapGrid(rvMap);
		}
		if (!html) html = '<p style="color:#666;">This layout has no map data.</p>';
		return html;
	}

	function renderRowsPreview(rows) {
		var html = '';
		rows.forEach(function (row) {
			var name = row.name || row.row_name || '';
			if (name) {
				html += '<div style="font-size:12px;font-weight:600;color:#031B4E;margin:8px 0 4px;">' + escapeHtml(name) + '</div>';
			}
			html += '<div style="display:flex;flex-wrap:wrap;gap:3px;margin-bottom:8px;">';
			var labels = expandRowLabels(row);
			labels.forEach(function (lbl) {
				html += '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 4px;border-radius:4px;font-size:11px;font-weight:500;background:#f3f4f5;border:1px solid #dcdcde;color:#1d2327;">' + escapeHtml(lbl) + '</span>';
			});
			html += '</div>';
		});
		return html;
	}

	function expandRowLabels(row) {
		var labels = [];
		var sides = ['top_side', 'bottom_side'];
		var hasSide = false;
		sides.forEach(function (sideKey) {
			var side = row[sideKey];
			if (side && side.first_label !== undefined && side.last_label !== undefined) {
				hasSide = true;
				labels = labels.concat(expandRange(side.first_label, side.last_label));
			}
		});
		if (!hasSide && row.first_label !== undefined && row.last_label !== undefined) {
			labels = expandRange(row.first_label, row.last_label);
		}
		return labels;
	}

	function expandRange(first, last) {
		var out = [];
		var fNum = parseInt(first, 10);
		var lNum = parseInt(last, 10);
		if (!isNaN(fNum) && !isNaN(lNum) && String(fNum) === String(first) && String(lNum) === String(last)) {
			var step = fNum <= lNum ? 1 : -1;
			for (var i = fNum; step > 0 ? i <= lNum : i >= lNum; i += step) {
				out.push(String(i));
			}
			return out;
		}
		var prefixMatch = String(first).match(/^([A-Za-z]+[-_]?)(\d+)$/);
		var lastMatch = String(last).match(/^([A-Za-z]+[-_]?)(\d+)$/);
		if (prefixMatch && lastMatch && prefixMatch[1] === lastMatch[1]) {
			var prefix = prefixMatch[1];
			var s = parseInt(prefixMatch[2], 10);
			var e = parseInt(lastMatch[2], 10);
			var pad = prefixMatch[2].length;
			var dir = s <= e ? 1 : -1;
			for (var j = s; dir > 0 ? j <= e : j >= e; j += dir) {
				out.push(prefix + String(j).padStart(pad, '0'));
			}
			return out;
		}
		out.push(String(first));
		if (String(first) !== String(last)) out.push(String(last));
		return out;
	}

	function hasBarns(map) { return map && Array.isArray(map.barns) && map.barns.length > 0; }

	function renderMapGrid(map) {
		var html = '';
		(map.barns || []).forEach(function (barn) {
			if (barn.name) html += '<div style="font-size:12px;font-weight:600;color:#031B4E;margin:8px 0 4px;">' + escapeHtml(barn.name) + '</div>';
			html += '<div style="display:flex;flex-wrap:wrap;gap:3px;margin-bottom:8px;">';
			(barn.grid || []).forEach(function (row) {
				row.forEach(function (cell) {
					if (cell.type === 'stall' || cell.type === 'rv') {
						html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:4px;font-size:11px;font-weight:500;background:#f3f4f5;border:1px solid #dcdcde;color:#1d2327;">' + escapeHtml(cell.label || '') + '</span>';
					} else if (cell.type === 'land' || cell.type === 'empty') {
						html += '<span style="display:inline-block;width:36px;height:36px;"></span>';
					}
				});
			});
			html += '</div>';
		});
		return html;
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

	function openDeleteVenue(venueId, venueName) {
		var overlay = openModal({
			title: 'Delete venue?',
			danger: true,
			body: '<p style="margin:0 0 6px;color:var(--eem-error-text);font-weight:600;">This permanently removes "' + escapeHtml(venueName) + '" and all its saved layouts.</p>' +
				'<p style="margin:0;">Reservations already built from its layouts keep their copy.</p>',
			confirmLabel: 'Delete Venue'
		});
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			postLayout('eem_venue_delete', { venue_id: venueId }, function () {
				overlay._close();
				toast('Venue deleted');
				window.location.reload();
			}, function (msg) {
				confirmBtn.disabled = false;
				alert(msg || 'Could not delete the venue.');
			});
		});
	}

	document.addEventListener('click', function (e) {
		var view = e.target.closest('[data-eem-action="venue-layout-view"]');
		if (view) {
			e.preventDefault();
			openView(view.getAttribute('data-layout-id'), view.getAttribute('data-layout-name'));
			return;
		}
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
			return;
		}
		var venueDel = e.target.closest('[data-eem-action="venue-delete"]');
		if (venueDel) {
			e.preventDefault();
			openDeleteVenue(venueDel.getAttribute('data-venue-id'), venueDel.getAttribute('data-venue-name'));
		}
	});
})();
