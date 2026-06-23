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
			'<div class="eem-modal-card"' + (opts.wide ? ' style="max-width:900px;max-height:88vh;"' : '') + '>' +
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
			html += hasBarns(stallMap) ? renderMapGrid(stallMap) : renderRowsPreview(stallRows);
		}
		if (hasRv) {
			if (hasStalls && hasRv) html += '<h3 style="margin:16px 0 8px;font-size:14px;font-weight:600;">RV Lots</h3>';
			html += hasBarns(rvMap) ? renderMapGrid(rvMap) : renderRowsPreview(rvRows);
		}
		if (!html) html = '<p style="color:#666;">This layout has no map data.</p>';
		return html;
	}

	function renderRowsPreview(rows) {
		var html = '';
		rows.forEach(function (row) {
			var name = row.name || row.row_name || '';
			if (name) {
				html += '<div style="font-size:12px;font-weight:600;color:var(--eem-navy,#031B4E);margin:8px 0 4px;">' + escapeHtml(name) + '</div>';
			}
			var labels = expandRowLabels(row);
			html += '<div class="eem-mb-preview-grid" style="grid-template-columns:repeat(' + labels.length + ',42px);grid-auto-rows:38px;margin-bottom:12px;">';
			labels.forEach(function (lbl, i) {
				html += '<div class="eem-mb-cust-cell stall" style="grid-column:' + (i + 1) + ';grid-row:1;">' + escapeHtml(lbl) + '</div>';
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
		if (!hasSide) {
			var f = row.first_label !== undefined ? row.first_label : row.first;
			var l = row.last_label !== undefined ? row.last_label : row.last;
			if (f !== undefined && l !== undefined) {
				labels = expandRange(f, l);
			}
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

	function sameLm(grid, r, c, label) {
		return r >= 0 && c >= 0 && r < grid.length && c < (grid[0] ? grid[0].length : 0) &&
			grid[r][c].type === 'landmark' && grid[r][c].label === label;
	}

	function renderMapGrid(map) {
		var html = '<div class="eem-mb-preview-scroll" style="max-height:70vh;">';
		(map.barns || []).forEach(function (barn) {
			var grid = barn.grid || [];
			if (!grid.length) return;
			var cols = grid[0] ? grid[0].length : 0;
			if (!cols) return;
			if (barn.name) html += '<div style="font-size:12px;font-weight:600;color:var(--eem-navy,#031B4E);margin:8px 0 4px;">' + escapeHtml(barn.name) + '</div>';
			html += '<div class="eem-mb-preview-grid" style="grid-template-columns:repeat(' + cols + ',42px);grid-auto-rows:38px;">';
			var consumed = {};
			for (var r = 0; r < grid.length; r++) {
				for (var c = 0; c < cols; c++) {
					if (consumed[r + ',' + c]) continue;
					var cell = grid[r][c];
					if (cell && cell.type === 'landmark') {
						var w = 1;
						while (sameLm(grid, r, c + w, cell.label)) w++;
						var h = 1;
						while (sameLm(grid, r + h, c, cell.label)) h++;
						for (var rr = r; rr < r + h; rr++) {
							for (var cc = c; cc < c + w; cc++) {
								consumed[rr + ',' + cc] = 1;
							}
						}
						html += '<div class="eem-mb-cust-cell landmark" style="grid-column:' + (c + 1) + ' / span ' + w + ';grid-row:' + (r + 1) + ' / span ' + h + ';width:auto;height:auto;">' + escapeHtml(cell.label || '') + '</div>';
					} else if (cell && (cell.type === 'stall' || cell.type === 'rv')) {
						html += '<div class="eem-mb-cust-cell stall" style="grid-column:' + (c + 1) + ';grid-row:' + (r + 1) + ';">' + escapeHtml(cell.label || '') + '</div>';
					} else {
						html += '<div class="eem-mb-cust-cell gap" style="grid-column:' + (c + 1) + ';grid-row:' + (r + 1) + ';"></div>';
					}
				}
			}
			html += '</div>';
		});
		html += '</div>';
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
			title: 'Move to Trash?',
			danger: true,
			body: '<p style="margin:0 0 6px;font-weight:600;">"' + escapeHtml(venueName) + '" will be moved to the Trash.</p>' +
				'<p style="margin:0;">You can restore it later from the Trash tab.</p>',
			confirmLabel: 'Move to Trash'
		});
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			postLayout('eem_venue_delete', { venue_id: venueId }, function () {
				overlay._close();
				toast('Venue moved to Trash');
				window.location.reload();
			}, function (msg) {
				confirmBtn.disabled = false;
				alert(msg || 'Could not move the venue to Trash.');
			});
		});
	}

	function restoreVenue(venueId) {
		postLayout('eem_venue_restore', { venue_id: venueId }, function () {
			toast('Venue restored');
			var row = document.querySelector('tr[data-venue-id="' + venueId + '"]');
			if (row) row.remove();
		}, function (msg) {
			alert(msg || 'Could not restore the venue.');
		});
	}

	function openDeletePermanently(venueId, venueName) {
		var overlay = openModal({
			title: 'Delete permanently?',
			danger: true,
			body: '<p style="margin:0 0 6px;color:var(--eem-error-text);font-weight:600;">This permanently removes "' + escapeHtml(venueName) + '" and all its saved layouts.</p>' +
				'<p style="margin:0;">This action cannot be undone.</p>',
			confirmLabel: 'Delete Permanently'
		});
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			postLayout('eem_venue_delete_permanently', { venue_id: venueId }, function () {
				overlay._close();
				toast('Venue permanently deleted');
				var row = document.querySelector('tr[data-venue-id="' + venueId + '"]');
				if (row) row.remove();
			}, function (msg) {
				confirmBtn.disabled = false;
				alert(msg || 'Could not delete the venue.');
			});
		});
	}

	/* ── Bulk actions ──────────────────────────────────────────── */

	function updateBulkBar() {
		/* No-op — bulk bar is always visible in the toolbar. */
	}

	function bulkDelete() {
		var checked = document.querySelectorAll('.eem-venue-cb:checked');
		if (!checked.length) return;
		var ids = Array.prototype.map.call(checked, function (cb) { return cb.value; });
		var overlay = openModal({
			title: 'Move ' + ids.length + (ids.length === 1 ? ' venue' : ' venues') + ' to Trash?',
			danger: true,
			body: '<p style="margin:0;font-weight:600;">' + ids.length + (ids.length === 1 ? ' venue' : ' venues') + ' will be moved to the Trash.</p>',
			confirmLabel: 'Move to Trash'
		});
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			bulkAjax('eem_venue_bulk_delete', ids, overlay, 'Venues moved to Trash');
		});
	}

	function bulkRestore() {
		var checked = document.querySelectorAll('.eem-venue-cb:checked');
		if (!checked.length) return;
		var ids = Array.prototype.map.call(checked, function (cb) { return cb.value; });
		bulkAjax('eem_venue_bulk_restore', ids, null, 'Venues restored');
	}

	function bulkDeletePermanently() {
		var checked = document.querySelectorAll('.eem-venue-cb:checked');
		if (!checked.length) return;
		var ids = Array.prototype.map.call(checked, function (cb) { return cb.value; });
		var overlay = openModal({
			title: 'Delete ' + ids.length + (ids.length === 1 ? ' venue' : ' venues') + ' permanently?',
			danger: true,
			body: '<p style="margin:0;color:var(--eem-error-text);font-weight:600;">This permanently removes ' + ids.length + (ids.length === 1 ? ' venue' : ' venues') + ' and all saved layouts. This cannot be undone.</p>',
			confirmLabel: 'Delete Permanently'
		});
		var confirmBtn = overlay.querySelector('[data-role="confirm"]');
		confirmBtn.addEventListener('click', function () {
			confirmBtn.disabled = true;
			bulkAjax('eem_venue_bulk_delete_permanently', ids, overlay, 'Venues permanently deleted');
		});
	}

	function bulkAjax(action, ids, overlay, successMsg) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce());
		ids.forEach(function (id) { body.append('venue_ids[]', id); });
		fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (overlay) overlay._close();
				if (json && json.success) {
					toast(json.data && json.data.message ? json.data.message : successMsg);
					ids.forEach(function (id) {
						var row = document.querySelector('tr[data-venue-id="' + id + '"]');
						if (row) row.remove();
					});
					updateBulkBar();
				} else {
					alert(json && json.data && json.data.message ? json.data.message : 'Operation failed.');
				}
			})
			.catch(function () { if (overlay) overlay._close(); alert('Operation failed.'); });
	}

	document.addEventListener('change', function (e) {
		if (e.target.matches('[data-eem-action="venues-toggle-all"]')) {
			var state = e.target.checked;
			document.querySelectorAll('.eem-venue-cb').forEach(function (cb) { cb.checked = state; });
			updateBulkBar();
		}
		if (e.target.matches('.eem-venue-cb')) {
			var all = document.querySelectorAll('.eem-venue-cb');
			var allChecked = document.querySelectorAll('.eem-venue-cb:checked');
			var toggle = document.querySelector('[data-eem-action="venues-toggle-all"]');
			if (toggle) toggle.checked = all.length > 0 && all.length === allChecked.length;
			updateBulkBar();
		}
	});

	document.addEventListener('click', function (e) {
		if (e.target.closest('[data-eem-action="venues-bulk-apply"]')) {
			var sel = document.querySelector('[data-eem-venues-bulk-action]');
			if (sel && sel.value === 'delete') {
				bulkDelete();
			} else if (sel && sel.value === 'restore') {
				bulkRestore();
			} else if (sel && sel.value === 'delete-permanently') {
				bulkDeletePermanently();
			}
			return;
		}
		var venueRestore = e.target.closest('[data-eem-action="venue-restore"]');
		if (venueRestore) {
			e.preventDefault();
			restoreVenue(venueRestore.getAttribute('data-venue-id'));
			return;
		}
		var venueDelPerm = e.target.closest('[data-eem-action="venue-delete-permanently"]');
		if (venueDelPerm) {
			e.preventDefault();
			openDeletePermanently(venueDelPerm.getAttribute('data-venue-id'), venueDelPerm.getAttribute('data-venue-name'));
			return;
		}
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
			return;
		}
		var saveDetail = e.target.closest('[data-eem-action="venue-save-detail"]');
		if (saveDetail) {
			e.preventDefault();
			saveVenueDetail();
		}
	});

	function saveVenueDetail() {
		var wrap = document.querySelector('.eem-venue-detail');
		if (!wrap) return;
		var venueId = wrap.getAttribute('data-venue-id');
		var fd = new FormData();
		fd.append('action', 'eem_venue_save_detail');
		fd.append('nonce', nonce());
		fd.append('venue_id', venueId);
		var fields = ['venue_name', 'address_1', 'address_2', 'city', 'state', 'postal_code', 'phone', 'website', 'lat', 'lng'];
		fields.forEach(function (f) {
			var el = wrap.querySelector('[name="' + f + '"]');
			if (el) fd.append(f, el.value);
		});
		var btn = wrap.querySelector('[data-eem-action="venue-save-detail"]');
		if (btn) btn.disabled = true;
		fetch(ajaxUrl(), { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (btn) btn.disabled = false;
				if (j.success) {
					toast(j.data && j.data.message ? j.data.message : 'Venue saved.');
				} else {
					toast((j.data && j.data.message) || 'Save failed.');
				}
			})
			.catch(function () {
				if (btn) btn.disabled = false;
				toast('Save failed.');
			});
	}
})();
