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
		// Audience + Compose stay visible at all times (Whitney 2026-06-20); picking
		// an event just refreshes the division options and the recipient count.
		var rid = currentEvent();
		if (!rid) { return; }
		post('eem_notifications_event_meta', { reservation_id: rid }).then(function (resp) {
			if (!resp || !resp.success || !resp.data) { return; }
			addDivisionOptions(resp.data.divisions || []);
			if (includeSel) { includeSel.value = 'all'; }
			if (excludeSel) { excludeSel.value = ''; }
			if (paymentSel) { paymentSel.value = 'all'; }
			setCount(resp.data.count || 0);
		}).catch(function () {});
	}

	if (eventSel) { eventSel.addEventListener('change', onEventChange); }
	[includeSel, excludeSel, paymentSel].forEach(function (sel) {
		if (sel) { sel.addEventListener('change', refreshCount); }
	});

	/* ── Batched send ──────────────────────────────────────────── */
	var sendBtn  = root.querySelector('[data-eem-action="notifications-send"]');
	var status   = root.querySelector('[data-eem-notif-status]');
	var subjectEl = root.querySelector('[data-eem-notif-subject]');
	var bodyEl    = root.querySelector('[data-eem-notif-body]');

	function setStatus(msg) { if (status) { status.textContent = msg || ''; } }

	function stepSend(token, offset, total) {
		post('eem_notifications_send_step', { token: token, offset: offset }).then(function (resp) {
			if (!resp || !resp.success || !resp.data) {
				setStatus((resp && resp.data && resp.data.message) || 'Send failed.');
				if (sendBtn) { sendBtn.disabled = false; }
				return;
			}
			var d = resp.data;
			setStatus('Sending… ' + Math.min(d.next_offset, d.total) + ' / ' + d.total);
			if (d.done) {
				setStatus('Sent to ' + d.sent + ' of ' + d.total + (d.failed ? ' (' + d.failed + ' failed)' : '') + '.');
				if (sendBtn) { sendBtn.disabled = false; }
				if (subjectEl) { subjectEl.value = ''; }
				if (bodyEl) { bodyEl.value = ''; }
				setTimeout(function () { window.location.reload(); }, 1500); // refresh history
			} else {
				stepSend(token, d.next_offset, total);
			}
		}).catch(function () {
			setStatus('Could not reach the server.');
			if (sendBtn) { sendBtn.disabled = false; }
		});
	}

	if (sendBtn) {
		sendBtn.addEventListener('click', function () {
			var rid = currentEvent();
			var subject = subjectEl ? subjectEl.value.trim() : '';
			var body = bodyEl ? bodyEl.value.trim() : '';
			if (!rid || !subject || !body) { setStatus('Pick an event and enter a subject + message.'); return; }
			sendBtn.disabled = true;
			setStatus('Preparing…');
			post('eem_notifications_send_start', {
				reservation_id: rid,
				include: includeSel ? includeSel.value : 'all',
				exclude: excludeSel ? excludeSel.value : '',
				payment: paymentSel ? paymentSel.value : 'all',
				subject: subject, body: body
			}).then(function (resp) {
				if (!resp || !resp.success || !resp.data) {
					setStatus((resp && resp.data && resp.data.message) || 'Could not start the send.');
					sendBtn.disabled = false;
					return;
				}
				stepSend(resp.data.token, 0, resp.data.total);
			}).catch(function () {
				setStatus('Could not reach the server.');
				sendBtn.disabled = false;
			});
		});
	}
})();
