/**
 * Entry editor (v1 #1b) — self-contained typeahead + save dispatch.
 *
 * Mirrors the reservation editor's event-connect flow without entangling its
 * reservation-specific JS. The repeating items table reuses the generic
 * reservation-editor-add/remove-repeating-row handlers in admin.js (template +
 * tbody driven), so this file only owns: the event-connect typeahead (search
 * reservations-as-events + select) and the save dispatch (Draft / Publish).
 *
 * Element contract (rendered by EEM_Entries::render):
 *   #eem-entry-typeahead[data-ajax-url|data-search-nonce]
 *   #eem-entry-event-search-input[data-eem-input-action="entry-filter-events"]
 *   #eem-entry-event-search-results
 *   #eem-entry-reservation-input          (hidden — chosen reservation id)
 *   #eem-entry-sticky-save[data-entry-id|data-save-nonce]
 *   [data-eem-action="entry-editor-save-draft" | "entry-editor-publish"]
 *   [data-eem-action="entry-change-event" | "entry-cancel-change"]
 */
(function () {
	'use strict';

	if (!document.querySelector('[data-eem-entry-editor]')) {
		return;
	}

	var searchTimer = null;
	var searchXhr   = null;

	function esc(s) {
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function toast(msg, variant) {
		if (window.EEM && typeof window.EEM.showSaveToast === 'function') {
			window.EEM.showSaveToast(msg, { variant: variant || 'success', sub: '' });
		}
	}

	/* ── Typeahead search ───────────────────────────────────────── */
	function fetchEvents(query) {
		var tah = document.getElementById('eem-entry-typeahead');
		var box = document.getElementById('eem-entry-event-search-results');
		if (!tah || !box) return;

		box.innerHTML = '<div class="eem-event-option-loading">Loading…</div>';
		if (searchXhr) { try { searchXhr.abort(); } catch (e) {} searchXhr = null; }

		var url = (tah.dataset.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php') +
			'?action=eem_entry_search_events&nonce=' + encodeURIComponent(tah.dataset.searchNonce || '') +
			'&term=' + encodeURIComponent(query);

		var xhr = new XMLHttpRequest();
		searchXhr = xhr;
		xhr.open('GET', url, true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;
			searchXhr = null;
			if (xhr.status !== 200) { box.innerHTML = '<div class="eem-event-option-empty">Could not load events.</div>'; return; }
			var resp;
			try { resp = JSON.parse(xhr.responseText); } catch (e) { box.innerHTML = '<div class="eem-event-option-empty">Could not load events.</div>'; return; }
			if (!resp.success || !resp.data || !resp.data.results || !resp.data.results.length) {
				box.innerHTML = '<div class="eem-event-option-empty">No matching events.</div>';
				return;
			}
			var current = String(tah.dataset.currentReservationId || '0');
			box.innerHTML = resp.data.results.map(function (ev) {
				var isCurrent = String(ev.id) === current;
				var badge = isCurrent ? ' <span class="eem-event-option-current-badge">CURRENT</span>' : '';
				var cls = 'eem-event-option' + (isCurrent ? ' is-current' : '');
				return '<div class="' + cls + '" data-eem-action="entry-select-event" data-reservation-id="' + esc(String(ev.id)) + '" data-event-name="' + esc(ev.text) + '">' +
					'<span class="eem-event-option-name">' + esc(ev.text) + badge + '</span>' +
					(ev.dates ? '<span class="eem-event-option-date">' + esc(ev.dates) + '</span>' : '') +
					'</div>';
			}).join('');
		};
		xhr.send();
	}

	function filterEvents(query) {
		if (searchTimer) clearTimeout(searchTimer);
		if (!query) { fetchEvents(''); return; }
		searchTimer = setTimeout(function () { fetchEvents(query); }, 250);
	}

	/* ── Connect / change-event ─────────────────────────────────── */
	function selectEvent(target) {
		var rid = target.getAttribute('data-reservation-id') || '';
		var name = target.getAttribute('data-event-name') || '';
		var hidden = document.getElementById('eem-entry-reservation-input');
		if (hidden) hidden.value = rid;
		var tah = document.getElementById('eem-entry-typeahead');
		if (tah) tah.dataset.currentReservationId = rid;
		var nameEl = document.getElementById('eem-entry-header-name');
		if (nameEl && name) nameEl.textContent = name;
		// First connect happens on the gate — persist as a draft + reload so the
		// server re-renders the full form (Description + Items cards).
		if (document.getElementById('eem-entry-link-gate')) {
			dispatchSave('save_draft', true);
		} else if (tah) {
			tah.style.display = 'none';
		}
	}

	/* ── Save dispatch ──────────────────────────────────────────── */
	function dispatchSave(kind, silent) {
		var bar = document.getElementById('eem-entry-sticky-save');
		if (!bar) return;
		var body = new URLSearchParams();
		body.set('action', 'eem_entry_editor_save');
		body.set('_wpnonce', bar.dataset.saveNonce || '');
		body.set('entry_id', bar.dataset.entryId || '0');
		body.set('save_kind', kind);
		var resInput = document.getElementById('eem-entry-reservation-input');
		body.set('reservation_id', resInput ? (resInput.value || '0') : '0');
		var descEl = document.getElementById('eem-entry-description');
		body.set('description', descEl ? descEl.value : '');
		document.querySelectorAll('#eem-entry-items-list input[name^="eem_entry_items"]').forEach(function (inp) {
			body.append(inp.name, inp.value);
		});

		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (resp) {
			if (resp && resp.success) {
				if (!silent) toast((resp.data && resp.data.message) || 'Saved.', 'success');
				setTimeout(function () { window.location.reload(); }, silent ? 200 : 600);
			} else {
				toast((resp && resp.data && resp.data.message) || 'Save failed.', 'error');
			}
		}).catch(function () { toast('Could not reach the server.', 'error'); });
	}

	/* ── Wiring ─────────────────────────────────────────────────── */
	document.addEventListener('input', function (ev) {
		if (ev.target && ev.target.dataset && ev.target.dataset.eemInputAction === 'entry-filter-events') {
			filterEvents(ev.target.value);
		}
	});
	document.addEventListener('focusin', function (ev) {
		if (ev.target && ev.target.id === 'eem-entry-event-search-input') {
			var box = document.getElementById('eem-entry-event-search-results');
			if (box && 0 === box.children.length) { fetchEvents(''); }
		}
	});
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var sel = t.closest('[data-eem-action="entry-select-event"]');
		if (sel) { ev.preventDefault(); selectEvent(sel); return; }

		if (t.closest('[data-eem-action="entry-change-event"]')) {
			ev.preventDefault();
			var tah = document.getElementById('eem-entry-typeahead');
			if (tah) { tah.style.display = ''; var inp = document.getElementById('eem-entry-event-search-input'); if (inp) inp.focus(); }
			return;
		}
		if (t.closest('[data-eem-action="entry-cancel-change"]')) {
			ev.preventDefault();
			var tah2 = document.getElementById('eem-entry-typeahead');
			if (tah2) tah2.style.display = 'none';
			return;
		}
		if (t.closest('[data-eem-action="entry-editor-save-draft"]')) {
			ev.preventDefault(); dispatchSave('save_draft'); return;
		}
		if (t.closest('[data-eem-action="entry-editor-publish"]')) {
			ev.preventDefault(); dispatchSave('publish'); return;
		}
	});
})();
