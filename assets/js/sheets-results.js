/**
 * Sheets & Results admin manager (Screen 1) — tab switching, event selector,
 * the inline Add File panel, the WordPress Media Library PDF pickers, and the
 * add/replace/clear/delete AJAX dispatch.
 *
 * Mutations re-render by reloading the page (files go live immediately, so the
 * fresh render IS the confirmation). The toast fires first so the user sees
 * success before the reload.
 */
(function () {
	'use strict';

	var root = document.querySelector('.eem-sheets-results');
	if (!root) { return; }

	var ajaxUrl = root.dataset.ajaxUrl;
	var nonce = root.dataset.nonce;

	function toast(msg, isError) {
		if (window.EEM && EEM.showSaveToast) {
			EEM.showSaveToast(msg, isError ? { variant: 'error' } : undefined);
		}
	}

	/** POST to admin-ajax with the shared nonce; returns a promise of the JSON. */
	function post(action, params) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce);
		Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	/** Reload preserving the current event_id + active tab. */
	function reload(tab) {
		var url = new URL(window.location.href);
		if (tab) { url.searchParams.set('tab', tab); }
		window.location.href = url.toString();
	}

	function activeTab() {
		var active = document.querySelector('.eem-sr-tab.is-active');
		return active ? active.getAttribute('data-sr-tab') : 'drawsheets';
	}

	/** Open the WordPress media frame filtered to PDFs; cb gets the attachment. */
	function pickPdf(cb) {
		if (!window.wp || !wp.media) { toast('Media library unavailable', true); return; }
		var frame = wp.media({
			title: 'Select a PDF',
			library: { type: 'application/pdf' },
			button: { text: 'Use this file' },
			multiple: false
		});
		frame.on('select', function () { cb(frame.state().get('selection').first().toJSON()); });
		frame.open();
	}

	document.addEventListener('click', function (ev) {
		var t = ev.target.closest('[data-eem-action]');
		if (!t || !root.contains(t)) { return; }
		var action = t.dataset.eemAction;

		if (action === 'sr-tab') {
			ev.preventDefault();
			var tab = t.getAttribute('data-sr-tab');
			document.querySelectorAll('.eem-sr-tab').forEach(function (b) {
				b.classList.toggle('is-active', b.getAttribute('data-sr-tab') === tab);
			});
			document.querySelectorAll('[data-sr-panel]').forEach(function (p) {
				p.hidden = p.getAttribute('data-sr-panel') !== tab;
			});
			var url = new URL(window.location.href);
			url.searchParams.set('tab', tab);
			window.history.replaceState({}, '', url.toString());
			return;
		}

		if (action === 'sr-toggle-add') {
			ev.preventDefault();
			var did = t.getAttribute('data-discipline-id');
			var panel = document.querySelector('[data-discipline-panel="' + did + '"]');
			if (panel) { panel.hidden = !panel.hidden; }
			return;
		}

		if (action === 'sr-cancel-add') {
			ev.preventDefault();
			var pnl = t.closest('.eem-sr-add-panel');
			if (pnl) { pnl.hidden = true; }
			return;
		}

		if (action === 'sr-pick-file') {
			ev.preventDefault();
			var fpanel = t.closest('.eem-sr-add-panel');
			pickPdf(function (att) {
				fpanel.querySelector('.eem-sr-f-pdf').value = att.id;
				fpanel.querySelector('.eem-sr-f-pdf-name').textContent = att.filename || att.title || 'Selected';
			});
			return;
		}

		if (action === 'sr-save-entry') {
			ev.preventDefault();
			var sp = t.closest('.eem-sr-add-panel');
			var label = sp.querySelector('.eem-sr-f-label').value.trim();
			var pdf = sp.querySelector('.eem-sr-f-pdf').value;
			if (!label) { toast('Enter a label', true); return; }
			if (!pdf || pdf === '0') { toast('Choose a PDF file', true); return; }
			t.disabled = true;
			post('eem_sr_add_entry', {
				event_id: t.getAttribute('data-event-id'),
				discipline_id: t.getAttribute('data-discipline-id'),
				label: label,
				round: sp.querySelector('.eem-sr-f-round').value,
				entry_date: sp.querySelector('.eem-sr-f-date').value,
				drawsheet_pdf: pdf
			}).then(function (res) {
				if (res && res.success) { toast(res.data.message || 'Saved'); reload('drawsheets'); }
				else { t.disabled = false; toast((res && res.data && res.data.message) || 'Save failed', true); }
			}).catch(function () { t.disabled = false; toast('Save failed', true); });
			return;
		}

		if (action === 'sr-add-discipline') {
			ev.preventDefault();
			var input = root.querySelector('.eem-sr-discipline-input');
			var name = input ? input.value.trim() : '';
			if (!name) { toast('Enter a discipline name', true); return; }
			t.disabled = true;
			post('eem_sr_add_discipline', { event_id: t.getAttribute('data-event-id'), name: name })
				.then(function (res) {
					if (res && res.success) { toast(res.data.message || 'Added'); reload('drawsheets'); }
					else { t.disabled = false; toast((res && res.data && res.data.message) || 'Failed', true); }
				}).catch(function () { t.disabled = false; toast('Failed', true); });
			return;
		}

		if (action === 'sr-replace-pdf') {
			ev.preventDefault();
			var entryId = t.getAttribute('data-entry-id');
			var which = t.getAttribute('data-which') || 'drawsheet';
			pickPdf(function (att) {
				post('eem_sr_set_pdf', { entry_id: entryId, which: which, attachment_id: att.id })
					.then(function (res) {
						if (res && res.success) { toast(res.data.message || 'Saved'); reload(which === 'result' ? 'results' : 'drawsheets'); }
						else { toast((res && res.data && res.data.message) || 'Failed', true); }
					}).catch(function () { toast('Failed', true); });
			});
			return;
		}

		if (action === 'sr-clear-result') {
			ev.preventDefault();
			if (!window.confirm('Remove the result PDF from this row?')) { return; }
			post('eem_sr_set_pdf', { entry_id: t.getAttribute('data-entry-id'), which: 'result', attachment_id: 0 })
				.then(function (res) {
					if (res && res.success) { toast(res.data.message || 'Removed'); reload('results'); }
					else { toast((res && res.data && res.data.message) || 'Failed', true); }
				}).catch(function () { toast('Failed', true); });
			return;
		}

		if (action === 'sr-delete-entry') {
			ev.preventDefault();
			var lbl = t.getAttribute('data-label') || 'this file';
			if (!window.confirm('Delete "' + lbl + '"? This removes its draw sheet and result.')) { return; }
			post('eem_sr_delete_entry', { entry_id: t.getAttribute('data-entry-id') })
				.then(function (res) {
					if (res && res.success) { toast(res.data.message || 'Deleted'); reload(activeTab()); }
					else { toast((res && res.data && res.data.message) || 'Failed', true); }
				}).catch(function () { toast('Failed', true); });
			return;
		}
	});

	// Event selector — navigate to the chosen event.
	document.addEventListener('change', function (ev) {
		var sel = ev.target.closest('[data-eem-action="sr-switch-event"]');
		if (!sel) { return; }
		var url = new URL(window.location.href);
		url.searchParams.set('event_id', sel.value);
		url.searchParams.set('tab', activeTab());
		window.location.href = url.toString();
	});
})();
