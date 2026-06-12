/**
 * Notifications page (v2) — audience builder + live recipient count.
 *
 * Event select → fetch the event's divisions (appended as Include/Exclude
 * options) + baseline count. Any audience change → refresh the live count.
 * Send dispatch lands in Slice 3 (the button is present but inert until then).
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-eem-notifications]');
	if (!root) { return; }

	var ajaxUrl = (window.eemNotifications && window.eemNotifications.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
	var nonce   = root.getAttribute('data-nonce') || '';

	var eventSel   = root.querySelector('[data-eem-notif-event]');
	var audience   = root.querySelector('[data-eem-notif-audience]');
	var compose    = root.querySelector('[data-eem-notif-compose]');
	var includeSel = root.querySelector('[data-eem-notif-include]');
	var excludeSel = root.querySelector('[data-eem-notif-exclude]');
	var paymentSel = root.querySelector('[data-eem-notif-payment]');
	var countBadge = root.querySelector('[data-eem-notif-count-badge]');

	function post(action, extra) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce);
		Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
		return fetch(ajaxUrl, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function currentEvent() { return eventSel ? eventSel.value : ''; }

	function setCount(n) { if (countBadge) { countBadge.textContent = String(n); } }

	function clearDivisionOptions(sel) {
		if (!sel) { return; }
		Array.prototype.slice.call(sel.querySelectorAll('option[data-division]')).forEach(function (o) { o.remove(); });
	}

	function addDivisionOptions(divisions) {
		[includeSel, excludeSel].forEach(function (sel) {
			clearDivisionOptions(sel);
			if (!sel) { return; }
			divisions.forEach(function (d) {
				var o = document.createElement('option');
				o.value = d.value;
				o.textContent = '— ' + d.label;       // em-dash prefix to set divisions apart
				o.setAttribute('data-division', '1');
				sel.appendChild(o);
			});
		});
	}

	function refreshCount() {
		var rid = currentEvent();
		if (!rid) { setCount(0); return; }
		post('eem_notifications_count', {
			reservation_id: rid,
			include: includeSel ? includeSel.value : 'all',
			exclude: excludeSel ? excludeSel.value : '',
			payment: paymentSel ? paymentSel.value : 'all'
		}).then(function (resp) {
			if (resp && resp.success && resp.data) { setCount(resp.data.count); }
		}).catch(function () {});
	}

	function onEventChange() {
		var rid = currentEvent();
		if (!rid) {
			if (audience) { audience.hidden = true; }
			if (compose) { compose.hidden = true; }
			return;
		}
		post('eem_notifications_event_meta', { reservation_id: rid }).then(function (resp) {
			if (!resp || !resp.success || !resp.data) { return; }
			addDivisionOptions(resp.data.divisions || []);
			if (includeSel) { includeSel.value = 'all'; }
			if (excludeSel) { excludeSel.value = ''; }
			if (paymentSel) { paymentSel.value = 'all'; }
			setCount(resp.data.count || 0);
			if (audience) { audience.hidden = false; }
			if (compose) { compose.hidden = false; }
		}).catch(function () {});
	}

	if (eventSel) { eventSel.addEventListener('change', onEventChange); }
	[includeSel, excludeSel, paymentSel].forEach(function (sel) {
		if (sel) { sel.addEventListener('change', refreshCount); }
	});
})();
