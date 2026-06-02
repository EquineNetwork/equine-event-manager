/**
 * Equine Event Manager — Admin JavaScript (Phase 3)
 *
 * Vanilla JS, no jQuery. All helpers exposed under window.EEM.
 *
 * Interaction model:
 *   - Markup carries data-eem-action="actionName" (+ supporting data-* attrs).
 *   - A single delegated click handler on document.body dispatches by action.
 *   - This avoids inline onclick= attributes and keeps event wiring centralized.
 *
 * Helpers implemented (per CLAUDE.md Phase 3 spec):
 *   EEM.toggleSection(sectionId)
 *   EEM.toggleSectionEnabled(toggleEl, sectionId, ev)
 *   EEM.toggleSwitch(el)
 *   EEM.toggleStay(btn)
 *   EEM.applyControls(controller)
 *   EEM.applyFeeTypeVisibility(scope)
 *   EEM.tagToggle(host)
 *   EEM.tagFilter(input)
 *   EEM.tagPick(item)
 *   EEM.tagRemove(chip)
 *   EEM.showSaveToast(message, options)
 *
 * Mockup source: .mockups/edit_reservation_page.html (section/toggle/tag-select),
 *                .mockups/settings_page.html (toast pattern).
 */
(function () {
	'use strict';

	var EEM = window.EEM || {};
	window.EEM = EEM;

	/* ─────────────────────────────────────────────────────────────
	 * Section collapse + enable
	 * ───────────────────────────────────────────────────────────── */

	/** Toggle the body of a collapsible section by id. */
	EEM.toggleSection = function (sectionId) {
		var body = document.getElementById('body-' + sectionId);
		var hdr = document.getElementById('hdr-' + sectionId);
		if (body) body.classList.toggle('hidden');
		if (hdr) hdr.classList.toggle('eem-section-collapsed');
	};

	/**
	 * Section "Enabled" toggle in the header — flips toggle on/off AND
	 * collapses the body when off. Used by Stalls/RV/Add-Ons/Group/Fees/etc.
	 */
	EEM.toggleSectionEnabled = function (toggleEl, sectionId, ev) {
		if (ev) ev.stopPropagation();
		var on = !toggleEl.classList.contains('on');
		toggleEl.classList.toggle('on', on);
		toggleEl.classList.toggle('off', !on);
		var body = document.getElementById('body-' + sectionId);
		var hdr = document.getElementById('hdr-' + sectionId);
		if (body) body.classList.toggle('hidden', !on);
		if (hdr) hdr.classList.toggle('eem-section-collapsed', !on);
	};

	/* ─────────────────────────────────────────────────────────────
	 * Toggle switch + declarative data-controls visibility
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Set a switch on/off, then run applyControls so any data-controls
	 * targets show/hide in sync.
	 */
	function setSwitchState(toggleEl, on) {
		toggleEl.classList.toggle('on', on);
		toggleEl.classList.toggle('off', !on);
		EEM.applyControls(toggleEl);
	}

	EEM.toggleSwitch = function (el) {
		setSwitchState(el, !el.classList.contains('on'));
	};

	/**
	 * Stay-type pill (Nightly / Weekend Rate) toggle. Enforces the
	 * at-least-one-stay-type constraint with an inline hint.
	 */
	EEM.toggleStay = function (btn) {
		var on = !btn.classList.contains('active');

		if (!on) {
			var group = btn.closest('.eem-stay-types');
			var active = group ? group.querySelectorAll('.eem-stay-type-btn.active').length : 0;
			if (active <= 1) {
				flashStayHint(group);
				return;
			}
		}

		btn.classList.toggle('active', on);
		var innerToggle = btn.querySelector('.eem-toggle');
		if (innerToggle) {
			innerToggle.classList.toggle('on', on);
			innerToggle.classList.toggle('off', !on);
		}
		EEM.applyControls(btn);
	};

	function flashStayHint(group) {
		if (!group) return;
		var host = group.parentElement;
		var hint = host.querySelector('.eem-stay-hint');
		if (!hint) {
			hint = document.createElement('span');
			hint.className = 'eem-stay-hint eem-field-hint';
			hint.style.cssText = 'color:#b91c1c;margin-top:6px;display:block';
			hint.textContent = 'At least one stay type must remain enabled.';
			host.appendChild(hint);
		}
		hint.style.opacity = '1';
		clearTimeout(hint._eemTimer);
		hint._eemTimer = setTimeout(function () {
			hint.style.transition = 'opacity .4s';
			hint.style.opacity = '0';
		}, 1800);
		setTimeout(function () {
			hint.style.transition = '';
		}, 2400);
	}

	/**
	 * applyControls — declarative show/hide driven by data-controls.
	 * Any element with class .eem-toggle or .eem-stay-type-btn carrying
	 * data-controls="rowId1 rowId2 ..." will reveal/hide the listed IDs
	 * when its on/active state changes.
	 */
	EEM.applyControls = function (controller) {
		var ids = (controller.dataset.controls || '').trim();
		if (!ids) return;
		var on = controller.classList.contains('on') || controller.classList.contains('active');
		ids.split(/\s+/).forEach(function (id) {
			var row = document.getElementById(id);
			if (row) row.style.display = on ? '' : 'none';
		});
	};

	/* ─────────────────────────────────────────────────────────────
	 * Fee type → value field visibility (Edit Reservation → Fees)
	 * Fee type "None" hides the value row; "Flat" or "Percent" shows it
	 * with the appropriate prefix/suffix.
	 * ───────────────────────────────────────────────────────────── */

	EEM.applyFeeTypeVisibility = function (scope) {
		var ctx = scope || document;
		var activeBtn = ctx.querySelector('.eem-fee-type-btn.active');
		if (!activeBtn) return;
		var type = activeBtn.dataset.feeType || 'none';
		var valueRow = ctx.querySelector('#row-fee-value');
		var flat = ctx.querySelector('#fee-val-flat');
		var pct = ctx.querySelector('#fee-val-pct');

		if (!valueRow) return;

		if (type === 'none') {
			valueRow.style.display = 'none';
			return;
		}

		valueRow.style.display = '';
		if (flat) flat.style.display = type === 'flat' ? '' : 'none';
		if (pct) pct.style.display = type === 'pct' ? '' : 'none';
	};

	function selectFeeType(btn) {
		var group = btn.parentElement;
		if (!group) return;
		group.querySelectorAll('.eem-fee-type-btn').forEach(function (b) {
			b.classList.remove('active');
		});
		btn.classList.add('active');
		EEM.applyFeeTypeVisibility(btn.closest('.eem-card-body') || document);
	}

	/* ─────────────────────────────────────────────────────────────
	 * Mode buttons (Quantity / Exact Map, etc.) — mutually-exclusive group
	 * ───────────────────────────────────────────────────────────── */

	function selectMode(btn) {
		var group = btn.parentElement;
		if (!group) return;
		group.querySelectorAll('.eem-mode-btn').forEach(function (b) {
			b.classList.remove('active');
		});
		btn.classList.add('active');
		// Dispatch a custom event so page-specific code can react.
		btn.dispatchEvent(new CustomEvent('eem:mode-change', {
			bubbles: true,
			detail: { value: btn.dataset.modeValue || btn.textContent.trim() }
		}));
	}

	/* ─────────────────────────────────────────────────────────────
	 * Tag multi-select (Blocked Stall Numbers / Blocked RV Lots / etc.)
	 * Match mockup .tag-select pattern but with eem- prefix.
	 * ───────────────────────────────────────────────────────────── */

	/** Open the dropdown for a tag-select host and focus its search input. */
	EEM.tagToggle = function (host) {
		host.classList.add('open');
		var search = host.querySelector('.eem-tag-search');
		if (search) search.focus();
	};

	/**
	 * Bug D fix: populate (or refresh) a tag dropdown from a list of labels.
	 * Preserves items already selected (chip exists) so they stay marked.
	 * Called before EEM.tagFilter() so the filter has items to act on.
	 *
	 * @param {HTMLElement} input  The .eem-tag-search input element.
	 * @param {string[]}    labels Array of label strings from row-builder data.
	 */
	function populateTagDropdownFromLabels(input, labels) {
		var host = input.closest('.eem-tag-select');
		if (!host) return;
		var dropdown = host.querySelector('.eem-tag-dropdown');
		if (!dropdown) return;

		// Build a set of currently-selected chip values so we can mark them.
		var selectedValues = {};
		host.querySelectorAll('.eem-tag-chip').forEach(function (chip) {
			if (chip.dataset.value) { selectedValues[chip.dataset.value] = true; }
		});

		// Rebuild dropdown items from the live labels list.
		// Remove all existing items (not the empty-state element).
		dropdown.querySelectorAll('.eem-tag-dropdown-item').forEach(function (el) { el.remove(); });

		var emptyEl = dropdown.querySelector('.eem-tag-dropdown-empty');
		labels.forEach(function (label) {
			var item = document.createElement('div');
			item.className = 'eem-tag-dropdown-item' + (selectedValues[label] ? ' selected' : '');
			item.dataset.value = label;
			item.textContent = label;
			item.setAttribute('data-eem-action', 'tag-pick');
			if (emptyEl) {
				dropdown.insertBefore(item, emptyEl);
			} else {
				dropdown.appendChild(item);
			}
		});
	}

	/** Filter dropdown items by data-value substring as user types. */
	EEM.tagFilter = function (input) {
		var host = input.closest('.eem-tag-select');
		if (!host) return;
		var needle = input.value.trim().toLowerCase();
		var items = host.querySelectorAll('.eem-tag-dropdown-item');
		var visibleCount = 0;
		items.forEach(function (item) {
			var value = (item.dataset.value || item.textContent).toLowerCase();
			var match = value.indexOf(needle) !== -1;
			item.style.display = match ? '' : 'none';
			if (match) visibleCount++;
		});
		// Show "no matches" empty state if all items hidden
		var empty = host.querySelector('.eem-tag-dropdown-empty');
		if (empty) empty.style.display = visibleCount === 0 ? '' : 'none';
	};

	/** Click an item in the dropdown — convert to chip + mark selected. */
	EEM.tagPick = function (item) {
		if (item.classList.contains('selected')) return;
		var host = item.closest('.eem-tag-select');
		if (!host) return;
		var inputContainer = host.querySelector('.eem-tag-select-input');
		var search = host.querySelector('.eem-tag-search');
		if (!inputContainer || !search) return;

		var value = item.dataset.value || item.textContent.trim();
		var label = item.dataset.label || item.textContent.trim();

		var chip = document.createElement('span');
		chip.className = 'eem-tag-chip';
		chip.dataset.value = value;
		chip.innerHTML = escapeHtml(label) +
			'<button type="button" class="eem-tag-chip-remove" data-eem-action="tag-remove" aria-label="Remove">&times;</button>';
		inputContainer.insertBefore(chip, search);

		item.classList.add('selected');
		updateTagHiddenInput(host);
		search.value = '';
		EEM.tagFilter(search);
	};

	/** Click ✕ on a chip — remove chip + un-select dropdown item. */
	EEM.tagRemove = function (chip) {
		var host = chip.closest('.eem-tag-select');
		if (!host) return;
		var value = chip.dataset.value;
		chip.remove();
		if (value) {
			var item = host.querySelector('.eem-tag-dropdown-item[data-value="' + cssEscape(value) + '"]');
			if (item) item.classList.remove('selected');
		}
		updateTagHiddenInput(host);
	};

	/** Sync the host's hidden <input> with current chip values for form submission. */
	function updateTagHiddenInput(host) {
		var hidden = host.querySelector('input[type="hidden"]');
		if (!hidden) return;
		var values = Array.prototype.map.call(
			host.querySelectorAll('.eem-tag-chip'),
			function (chip) { return chip.dataset.value; }
		);
		hidden.value = values.join(',');
		hidden.dispatchEvent(new Event('change', { bubbles: true }));
	}

	/* ─────────────────────────────────────────────────────────────
	 * Save toast — single global container
	 * Position controlled entirely by CSS (.eem-toast-wrap).
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Show a transient toast.
	 *
	 * @param {string} message  Title text shown bold.
	 * @param {object} [options]
	 * @param {string} [options.sub]      Optional smaller secondary line.
	 *                                     Defaults to "Your changes have been applied."
	 * @param {string} [options.variant]  'success' (default) or 'error'.
	 * @param {number} [options.duration] Ms before auto-dismiss. Default 3200.
	 */
	EEM.showSaveToast = function (message, options) {
		var opts = options || {};
		var wrap = document.querySelector('.eem-toast-wrap');
		if (!wrap) {
			wrap = document.createElement('div');
			wrap.className = 'eem-toast-wrap';
			document.body.appendChild(wrap);
		}

		var sub = opts.sub === undefined ? 'Your changes have been applied.' : opts.sub;
		var variant = opts.variant === 'error' ? 'eem-toast--error' : '';
		var duration = typeof opts.duration === 'number' ? opts.duration : 3200;

		var toast = document.createElement('div');
		toast.className = 'eem-toast ' + variant;
		toast.innerHTML =
			'<div class="eem-toast-icon">' + (opts.variant === 'error' ? '!' : '✓') + '</div>' +
			'<div class="eem-toast-body">' +
				'<div class="eem-toast-title">' + escapeHtml(message) + '</div>' +
				(sub ? '<div class="eem-toast-sub">' + escapeHtml(sub) + '</div>' : '') +
			'</div>' +
			'<button type="button" class="eem-toast-close" data-eem-action="toast-dismiss" aria-label="Dismiss">&times;</button>';
		wrap.appendChild(toast);

		// Force reflow before adding .show so the transition runs.
		// eslint-disable-next-line no-unused-expressions
		toast.offsetHeight;
		toast.classList.add('show');

		var dismiss = function () {
			toast.classList.remove('show');
			setTimeout(function () { toast.remove(); }, 300);
		};

		setTimeout(dismiss, duration);
		toast._eemDismiss = dismiss;
		return toast;
	};

	/* ─────────────────────────────────────────────────────────────
	 * Dropdown open/close (meatballs row menu, More menu, etc.)
	 * Click target with data-eem-action="dropdown-toggle" opens the
	 * nearest .eem-dropdown or .eem-row-menu-wrap; click outside closes.
	 * ───────────────────────────────────────────────────────────── */

	/* C7.X.19 Issue 2 — flip-up detection: after opening, measure whether the
	   dropdown clips below the nearest overflow:hidden container (.eem-page-wrap
	   or .eem-card). C7.X.18 checked against window.innerHeight only; the actual
	   clipping boundary is .eem-page-wrap (overflow:hidden), whose bottom edge is
	   inside the viewport — so spaceBelow was always positive and the class never
	   fired. Now check the minimum of the container bottom and the viewport bottom. */
	function toggleDropdown(trigger) {
		var host = trigger.closest('.eem-dropdown, .eem-row-menu-wrap');
		if (!host) return;
		var isOpen = host.classList.contains('open');
		closeAllDropdowns();
		if (!isOpen) {
			host.classList.add('open');
			// getBoundingClientRect() forces a synchronous reflow so the
			// display:block from .open is already included in the measurement.
			var drop = host.querySelector('.eem-row-dropdown');
			if (drop) {
				var dropRect   = drop.getBoundingClientRect();
				var clipEl     = host.closest('.eem-page-wrap') || host.closest('.eem-card');
				var bottomBound = clipEl
					? Math.min( clipEl.getBoundingClientRect().bottom, window.innerHeight )
					: window.innerHeight;
				if (dropRect.bottom > bottomBound) {
					host.classList.add('eem-row-menu-wrap--flip-up');
				}
			}
		}
	}

	/* ─────────────────────────────────────────────────────────────
	 * Template card — toggle open/closed + lazy-init TinyMCE on first open.
	 * Card markup: <article.eem-template-card>
	 *   <header.eem-template-card-head data-eem-action="template-toggle">
	 *   <div.eem-template-card-body>
	 *     <textarea[data-eem-tinymce-target]>...</textarea>
	 *
	 * WP's bundled wp.editor.initialize() takes a textarea id and a settings
	 * object, returning a wired-up TinyMCE instance. We initialize on first
	 * expand to keep page-load light (5 editors × ~200KB of WP-TinyMCE deps
	 * is a lot to fire eagerly).
	 * ───────────────────────────────────────────────────────────── */
	function toggleTemplateCard(headEl) {
		var card = headEl.closest('.eem-template-card');
		if (!card) return;

		var willOpen = !card.classList.contains('is-open');
		card.classList.toggle('is-open', willOpen);

		if (willOpen && !card._eemTinymceReady) {
			initTemplateCardEditor(card);
			card._eemTinymceReady = true;
		}
	}

	/* ─────────────────────────────────────────────────────────────
	 * Placeholder chip → click-to-copy
	 * Chip markup carries data-eem-value="{{token}}" + .is-copied state
	 * for visual feedback. Modern clipboard API with execCommand fallback
	 * so it works on older browsers / non-https admins.
	 * ───────────────────────────────────────────────────────────── */
	function copyPlaceholderChip(chip) {
		var value = chip.dataset.eemValue || chip.textContent.trim();
		if (!value) return;

		var done = function () {
			chip.classList.add('is-copied');
			clearTimeout(chip._eemCopyTimer);
			chip._eemCopyTimer = setTimeout(function () {
				chip.classList.remove('is-copied');
			}, 1500);
		};

		if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
			navigator.clipboard.writeText(value).then(done, function () {
				fallbackCopy(value, done);
			});
		} else {
			fallbackCopy(value, done);
		}
	}

	function fallbackCopy(value, done) {
		var temp = document.createElement('textarea');
		temp.value = value;
		temp.style.position = 'fixed';
		temp.style.opacity = '0';
		document.body.appendChild(temp);
		temp.select();
		try { document.execCommand('copy'); } catch (e) { /* clipboard blocked, no-op */ }
		document.body.removeChild(temp);
		done();
	}

	/* ─────────────────────────────────────────────────────────────
	 * Settings form save (delegated submit handler)
	 * Posts <form data-eem-settings-form> to admin-ajax.php via fetch +
	 * FormData. Server response shape: { success: bool, data: { message }}.
	 * Either path → toast.
	 * ───────────────────────────────────────────────────────────── */
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		if (!form || !form.matches || !form.matches('[data-eem-settings-form]')) return;
		ev.preventDefault();

		// If TinyMCE is mounted, push its content back into the underlying textarea
		// before serializing — otherwise the form sees stale textarea content.
		if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
			window.tinymce.triggerSave();
		}

		submitSettingsForm(form);
	});

	function submitSettingsForm(form) {
		var submitBtn = form.querySelector('button[type="submit"]');
		if (submitBtn) submitBtn.disabled = true;

		var data = new FormData(form);

		fetch(form.getAttribute('action'), {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && json.success) {
					EEM.showSaveToast(
						(json.data && json.data.message) || 'Saved.'
					);
				} else {
					var msg = (json && json.data && json.data.message) || 'Save failed.';
					EEM.showSaveToast(msg, { variant: 'error', sub: '' });
				}
			})
			.catch(function () {
				EEM.showSaveToast('Could not reach the server.', { variant: 'error', sub: '' });
			})
			.then(function () {
				if (submitBtn) submitBtn.disabled = false;
			});
	}

	/* ─────────────────────────────────────────────────────────────
	 * Send-test-email — per-template card button
	 * Posts to admin-ajax.php action=eem_send_test_email with the
	 * template id + the form's existing nonce. Disables the button
	 * during the request to prevent double-sends.
	 * ───────────────────────────────────────────────────────────── */
	function sendTestEmail(btn) {
		var templateId = btn.dataset.eemTemplateId;
		if (!templateId) return;

		var form = btn.closest('[data-eem-settings-form]');
		var nonceInput = form ? form.querySelector('input[name="nonce"]') : null;
		var nonce = nonceInput ? nonceInput.value : '';
		if (!nonce) {
			EEM.showSaveToast('Missing nonce — refresh the page.', { variant: 'error', sub: '' });
			return;
		}

		btn.disabled = true;
		var originalLabel = btn.textContent;
		btn.textContent = 'Sending…';

		var data = new FormData();
		data.append('action', 'eem_send_test_email');
		data.append('template_id', templateId);
		data.append('nonce', nonce);

		var ajaxUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php');

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && json.success) {
					EEM.showSaveToast(
						(json.data && json.data.message) || 'Test email sent.'
					);
				} else {
					var msg = (json && json.data && json.data.message) || 'Send failed.';
					EEM.showSaveToast(msg, { variant: 'error', sub: '' });
				}
			})
			.catch(function () {
				EEM.showSaveToast('Could not reach the server.', { variant: 'error', sub: '' });
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = originalLabel;
			});
	}

	/* ─────────────────────────────────────────────────────────────
	 * C9 — Customer Profile internal notes save.
	 * Posts to admin-ajax.php action=eem_save_customer_note with the
	 * customer email + nonce from the [data-eem-customer-note] host and
	 * the textarea contents. Toast on success/failure.
	 * ───────────────────────────────────────────────────────────── */
	function saveCustomerNote(btn) {
		var host = btn.closest('[data-eem-customer-note]');
		if (!host) return;
		var input = host.querySelector('[data-eem-note-input]');
		var email = host.getAttribute('data-eem-email') || '';
		var nonce = host.getAttribute('data-eem-nonce') || '';
		if (!email || !nonce) {
			EEM.showSaveToast('Missing customer or nonce — refresh the page.', { variant: 'error', sub: '' });
			return;
		}

		btn.disabled = true;
		var originalLabel = btn.textContent;
		btn.textContent = 'Saving…';

		var data = new FormData();
		data.append('action', 'eem_save_customer_note');
		data.append('email', email);
		data.append('note', input ? input.value : '');
		data.append('nonce', nonce);

		var ajaxUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php');

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && json.success) {
					EEM.showSaveToast((json.data && json.data.message) || 'Notes saved.');
				} else {
					var msg = (json && json.data && json.data.message) || 'Save failed.';
					EEM.showSaveToast(msg, { variant: 'error', sub: '' });
				}
			})
			.catch(function () {
				EEM.showSaveToast('Could not reach the server.', { variant: 'error', sub: '' });
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = originalLabel;
			});
	}

	/* ─────────────────────────────────────────────────────────────
	 * Source picker (Integrations event source + Payments processor).
	 * Radio change → host gets .is-selected, siblings lose it, the
	 * matching .eem-source-detail block becomes visible (others hide).
	 * Pure delegation — wired here, not inside a panel-specific handler.
	 * ───────────────────────────────────────────────────────────── */
	document.addEventListener('change', function (ev) {
		var radio = ev.target;
		if (!radio || !radio.matches || radio.type !== 'radio') return;

		var row = radio.closest('.eem-source-row');
		if (!row) return;

		var group = row.closest('[data-eem-source-group]');
		if (group) {
			group.querySelectorAll('.eem-source-row').forEach(function (r) {
				r.classList.toggle('is-selected', r.contains(radio));
			});
		}

		// Show the matching source-detail block (Integrations panel) by data-eem-source-value
		var value = row.dataset.eemSourceValue;
		if (value) {
			document.querySelectorAll('[data-eem-source-detail]').forEach(function (detail) {
				if (detail.dataset.eemSourceDetail === value) {
					detail.removeAttribute('hidden');
				} else {
					detail.setAttribute('hidden', 'hidden');
				}
			});
		}
	});

	/* ─────────────────────────────────────────────────────────────
	 * Branding — WP media library logo pick + remove
	 * Uses wp.media (available on admin pages that wp_enqueue_media'd
	 * — Settings page calls it via wp_enqueue_editor side-effect).
	 * ───────────────────────────────────────────────────────────── */
	function pickLogo(btn) {
		if (!window.wp || !window.wp.media) {
			EEM.showSaveToast('WP media library not available.', { variant: 'error', sub: '' });
			return;
		}
		var host = btn.closest('[data-eem-logo-upload]');
		if (!host) return;

		var frame = window.wp.media({
			title: 'Choose Business Logo',
			button: { text: 'Use this logo' },
			multiple: false,
			library: { type: 'image' }
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var idInput   = host.querySelector('[data-eem-logo-id]');
			var preview   = host.querySelector('[data-eem-logo-preview]');
			var removeBtn = host.querySelector('[data-eem-action="logo-remove"]');

			if (idInput) idInput.value = attachment.id || '';
			if (preview && attachment.url) {
				preview.innerHTML = '<img src="' + attachment.url + '" alt="" />';
			}
			if (removeBtn) removeBtn.disabled = false;
		});

		frame.open();
	}

	function removeLogo(btn) {
		var host = btn.closest('[data-eem-logo-upload]');
		if (!host) return;
		var idInput = host.querySelector('[data-eem-logo-id]');
		var preview = host.querySelector('[data-eem-logo-preview]');
		if (idInput) idInput.value = '0';
		if (preview) preview.innerHTML = '<span class="eem-logo-preview-empty">No logo set</span>';
		btn.disabled = true;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Integrations — Test Feed URL button
	 * Calls the existing equine_event_manager_test_feed_url AJAX action
	 * (registered by EEM_Events) with the current value of the Feed URL input.
	 * ───────────────────────────────────────────────────────────── */
	function testFeedUrl(btn) {
		var input = document.getElementById('eem-feed-url');
		if (!input) return;
		var url = (input.value || '').trim();
		if (!url) {
			EEM.showSaveToast('Enter a feed URL first.', { variant: 'error', sub: '' });
			return;
		}

		btn.disabled = true;
		var originalLabel = btn.textContent;
		btn.textContent = 'Testing…';

		var data = new FormData();
		data.append('action', 'equine_event_manager_test_feed_url');
		data.append('feed_url', url);

		// Try to find a nonce in any nearby form (this AJAX action might use
		// its own nonce name; if it 403s the toast will say so and admin
		// can investigate). The existing handler in EEM_Events may have its
		// own nonce setup; here we just POST what we have.
		var form = btn.closest('form');
		var nonceInput = form ? form.querySelector('input[name="nonce"]') : null;
		if (nonceInput) data.append('nonce', nonceInput.value);

		var ajaxUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php');
		fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && json.success) {
					var count = json.data && json.data.count ? json.data.count : '';
					EEM.showSaveToast(count ? ('Feed OK — ' + count + ' events found.') : 'Feed reachable.', { sub: '' });
				} else {
					var msg = (json && json.data && json.data.message) || 'Feed test failed.';
					EEM.showSaveToast(msg, { variant: 'error', sub: '' });
				}
			})
			.catch(function () {
				EEM.showSaveToast('Could not reach the server.', { variant: 'error', sub: '' });
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = originalLabel;
			});
	}

	/* Initialize wp.editor TinyMCE on a single textarea (must have an id).
	   Used by:
	     - Template card lazy-init (initTemplateCardEditor) — fires on first
	       card expand to keep page load light.
	     - Eager-init pass (initEagerTinyMceTargets) — fires on DOMContentLoaded
	       for textareas NOT inside a template card (e.g. Communications panel
	       Policies fields, which are always visible). */
	function initTinyMceOn(textarea) {
		if (!textarea || !textarea.id) return;

		// Graceful no-op if wp.editor isn't available (e.g., wp_enqueue_editor
		// wasn't called server-side). Textarea remains plain — admin can still
		// edit raw HTML.
		if (!window.wp || !window.wp.editor || typeof window.wp.editor.initialize !== 'function') {
			return;
		}

		try {
			window.wp.editor.initialize(textarea.id, {
				tinymce: {
					wpautop: true,
					toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
					menubar: false,
					branding: false,
					statusbar: false
				},
				quicktags: {
					buttons: 'strong,em,link,ul,ol,li'
				},
				mediaButtons: false
			});
		} catch (e) {
			// Logged for the smoke-test debug.log pass; doesn't crash the page.
			if (window.console && window.console.warn) {
				window.console.warn('EEM: TinyMCE initialize failed for ' + textarea.id, e);
			}
		}
	}

	function initTemplateCardEditor(card) {
		var textarea = card.querySelector('[data-eem-tinymce-target]');
		initTinyMceOn(textarea);
	}

	/* Eager-init pass: find all [data-eem-tinymce-target] textareas that are
	   NOT inside a .eem-template-card (those stay lazy via toggleTemplateCard)
	   and initialize them right away. Covers the Communications panel's
	   Policies fields (Cancellation Policy + Terms & Conditions) which are
	   always visible and don't have an expand toggle. */
	function initEagerTinyMceTargets() {
		var targets = document.querySelectorAll('[data-eem-tinymce-target]');
		targets.forEach(function (textarea) {
			if (textarea.closest('.eem-template-card')) return;
			initTinyMceOn(textarea);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initEagerTinyMceTargets);
	} else {
		initEagerTinyMceTargets();
	}

	/* ─────────────────────────────────────────────────────────────
	 * C4.C — Reservations list row actions.
	 *
	 * submitReservationAction builds a hidden form pointed at
	 * admin-post.php, fills in the reservation_id + nonce + action,
	 * and submits — gives us the browser-native redirect-with-message
	 * UX without maintaining AJAX state in the meatballs dropdown.
	 *
	 * The Email Customers flow uses an AJAX modal instead because the
	 * compose step needs round-tripping data; helpers below cover open,
	 * close, recipient-count preload, and form submit.
	 * ───────────────────────────────────────────────────────────── */
	function submitReservationAction(target, actionName, nonceAction, extraFields) {
		var reservationId = target.dataset.reservationId;
		if (!reservationId) return;
		var nonce = window.eemRowActions && window.eemRowActions.nonces && window.eemRowActions.nonces[nonceAction];
		if (!nonce) {
			if (window.console && window.console.warn) {
				window.console.warn('EEM: missing nonce for ' + actionName);
			}
			return;
		}
		var adminPostUrl = (window.eemRowActions && window.eemRowActions.adminPostUrl) || (window.ajaxurl || '').replace('admin-ajax.php', 'admin-post.php');
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = adminPostUrl;
		form.style.display = 'none';
		var fields = [
			['action', actionName],
			['reservation_id', reservationId],
			['_eem_action_nonce', nonce]
		];
		// C7.X.17 Issue D3 — allow callers to inject extra form fields
		// (e.g. confirmation_title for the typed-confirm delete modal).
		if (extraFields && typeof extraFields === 'object') {
			Object.keys(extraFields).forEach(function (k) {
				fields.push([k, extraFields[k]]);
			});
		}
		fields.forEach(function (pair) {
			var i = document.createElement('input');
			i.type = 'hidden';
			i.name = pair[0];
			i.value = pair[1];
			form.appendChild(i);
		});
		document.body.appendChild(form);
		form.submit();
	}

	/* C7.X.17 Issue D3 — Typed-confirmation modal for Delete Permanently.
	   C7.X.20 fix: corrected CSS class names + added classList.add('open').
	   C7.X.21 UX change: typed confirmation is now the constant string "DELETE"
	   (case-sensitive uppercase) instead of the reservation title. Simpler and
	   consistent for all permanent-delete actions plugin-wide. Server-side
	   also validates the posted confirmation === "DELETE". */
	function openDeletePermanentlyModal(target) {
		var resId = target.dataset.reservationId;
		if (!resId) return;

		// Remove any existing modal first (e.g. if opened twice quickly)
		var existing = document.getElementById('eem-delete-perm-overlay');
		if (existing) existing.remove();

		/* C7.X.21: confirmation word is the constant "DELETE" (case-sensitive). */
		var CONFIRM_WORD = 'DELETE';

		var overlay = document.createElement('div');
		overlay.id  = 'eem-delete-perm-overlay';
		overlay.className = 'eem-modal';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-labelledby', 'eem-delete-perm-title');
		overlay.innerHTML =
			'<div class="eem-modal-card">' +
				'<div class="eem-modal-head eem-modal-head--danger">' +
					'<h2 class="eem-modal-title eem-modal-title--danger" id="eem-delete-perm-title">Delete reservation permanently?</h2>' +
				'</div>' +
				'<div class="eem-modal-body">' +
					'<p style="margin:0 0 10px;color:var(--eem-error-text);font-weight:600;">' +
						'This cannot be undone. The reservation, all linked orders, and all audit history will be permanently deleted.' +
					'</p>' +
					'<p style="margin:0 0 6px;">To confirm, type <strong>DELETE</strong> below:</p>' +
					'<input type="text" id="eem-delete-perm-input" class="eem-field-input" style="width:100%;box-sizing:border-box;" placeholder="Type DELETE to confirm" autocomplete="off">' +
				'</div>' +
				'<div class="eem-modal-foot">' +
					'<button type="button" id="eem-delete-perm-cancel" class="eem-btn eem-btn--secondary">Cancel</button>' +
					'<button type="button" id="eem-delete-perm-confirm" class="eem-btn eem-btn--danger" disabled>Delete Permanently</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(overlay);
		overlay.classList.add('open');

		var input      = overlay.querySelector('#eem-delete-perm-input');
		var confirmBtn = overlay.querySelector('#eem-delete-perm-confirm');
		var cancelBtn  = overlay.querySelector('#eem-delete-perm-cancel');

		input.addEventListener('input', function () {
			confirmBtn.disabled = (input.value !== CONFIRM_WORD);
		});
		cancelBtn.addEventListener('click', function () {
			overlay.remove();
		});
		overlay.addEventListener('click', function (e) {
			// Close on backdrop click (outside the .eem-modal-card box)
			if (e.target === overlay) overlay.remove();
		});
		confirmBtn.addEventListener('click', function () {
			if (input.value !== CONFIRM_WORD) return;
			overlay.remove();
			submitReservationAction(
				target,
				'eem_reservation_delete_permanently',
				'eem_reservation_delete_permanently',
				{ confirmation_title: CONFIRM_WORD }
			);
		});

		// Keyboard: Escape closes, Enter in input tries confirm
		overlay.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') overlay.remove();
			if (e.key === 'Enter' && !confirmBtn.disabled) confirmBtn.click();
		});

		input.focus();
	}

	/* C5.G.2 — Orders row-action helper. Mirror of submitReservationAction
	   pointed at the eemOrderRowActions JS-localized nonces + the order_key
	   form field (vs reservation_id). C5.C registered the PHP handlers +
	   the JS-localized nonces but never authored the JS handler arms —
	   added here so Print Receipt + meatballs items actually dispatch. */
	function submitOrderAction(target, actionName, nonceAction, confirmMessage) {
		var orderKey = target.dataset.orderKey;
		if (!orderKey) return;
		if (confirmMessage && !window.confirm(confirmMessage)) return;
		var nonce = window.eemOrderRowActions && window.eemOrderRowActions.nonces && window.eemOrderRowActions.nonces[nonceAction];
		if (!nonce) {
			if (window.console && window.console.warn) {
				window.console.warn('EEM: missing nonce for ' + actionName);
			}
			return;
		}
		var adminPostUrl = (window.eemOrderRowActions && window.eemOrderRowActions.adminPostUrl) || (window.ajaxurl || '').replace('admin-ajax.php', 'admin-post.php');
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = adminPostUrl;
		form.style.display = 'none';
		[
			['action', actionName],
			['order_key', orderKey],
			['_eem_action_nonce', nonce]
		].forEach(function (pair) {
			var i = document.createElement('input');
			i.type = 'hidden';
			i.name = pair[0];
			i.value = pair[1];
			form.appendChild(i);
		});
		document.body.appendChild(form);
		form.submit();
	}

	/* C5.G.2 — Orders Bulk Refund modal trigger. Reads the bulk-action
	   <select> + collects checked order_keys from row checkboxes into
	   the modal's hidden field, then opens the modal. Per ORD-2 modal
	   confirmation flow — the actual POST happens from the modal's
	   Confirm button (handler below). */
	/* ── C6.C — Bulk refund engine (AJAX-driven sequential) ──
	   Replaces the C5.D form-POST stub with a real queue-driven engine.
	   3-state modal (intro / processing / summary). Per-order AJAX call
	   to wp_ajax_eem_order_bulk_refund_step (which computes refund amount
	   server-side at call time — no client amount, guarantees retry
	   safety against parallel admin actions). Continue past failures,
	   surface per-order outcomes, render summary at end with retry-failed
	   affordance. Option-3 batch error attribution per C6.C kickoff Q2. */

	// Per-modal-open state. Reset on each open.
	var _bulkRefundState = {
		queue: [],          // remaining order_keys to process
		inFlight: null,     // order_key currently being processed
		successes: [],      // [{order_key, refunded_amount, was_noop}]
		failures: []        // [{order_key, code, message}]
	};

	function setBulkRefundModalState(modal, state) {
		// state ∈ 'intro' | 'processing' | 'summary'
		modal.classList.remove('eem-bulk-refund--state-intro', 'eem-bulk-refund--state-processing', 'eem-bulk-refund--state-summary');
		modal.classList.add('eem-bulk-refund--state-' + state);
		var primaryBtn = modal.querySelector('[data-eem-bulk-refund-primary-btn]');
		if (primaryBtn) {
			if ('intro' === state) {
				primaryBtn.textContent = 'Confirm refund';
				primaryBtn.disabled = false;
				primaryBtn.hidden = false;
				primaryBtn.setAttribute('data-eem-action', 'orders-bulk-refund-confirm');
			} else if ('processing' === state) {
				primaryBtn.hidden = true;
			} else if ('summary' === state) {
				primaryBtn.hidden = (_bulkRefundState.failures.length === 0);
				primaryBtn.disabled = false;
				primaryBtn.textContent = 'Retry failed (' + _bulkRefundState.failures.length + ')';
				primaryBtn.setAttribute('data-eem-action', 'orders-bulk-refund-retry');
			}
		}
	}

	function openOrdersBulkRefundModal() {
		var bulkSelect = document.querySelector('[data-eem-orders-bulk-action]');
		if (!bulkSelect || bulkSelect.value !== 'refund') {
			window.alert('Pick "Refund Selected" before clicking Apply.');
			return;
		}
		var checked = document.querySelectorAll('input.eem-orders-row-cb:checked');
		if (!checked.length) {
			window.alert('Select at least one order before clicking Apply.');
			return;
		}
		var keys = Array.prototype.map.call(checked, function (cb) { return cb.value; });
		var modal = document.getElementById('eem-orders-bulk-refund-modal');
		if (!modal) return;

		// Reset state for fresh modal open.
		_bulkRefundState = { queue: keys.slice(), inFlight: null, successes: [], failures: [] };

		var keysField = modal.querySelector('[data-eem-bulk-refund-keys]');
		if (keysField) keysField.value = keys.join(',');
		var summary = modal.querySelector('[data-eem-orders-bulk-refund-summary]');
		if (summary) summary.textContent = 'Refund ' + keys.length + ' selected order' + (keys.length === 1 ? '' : 's') + '?';

		setBulkRefundModalState(modal, 'intro');
		modal.classList.add('open');
		modal.setAttribute('aria-hidden', 'false');
	}

	function closeOrdersBulkRefundModal() {
		var modal = document.getElementById('eem-orders-bulk-refund-modal');
		if (!modal) return;
		modal.classList.remove('open');
		modal.setAttribute('aria-hidden', 'true');
	}

	// User clicked "Confirm refund" in intro state — collect inputs,
	// switch to processing state, start the queue.
	function startBulkRefundQueue() {
		var modal = document.getElementById('eem-orders-bulk-refund-modal');
		if (!modal) return;
		var keys = _bulkRefundState.queue.slice();
		if (!keys.length) return;

		var reasonField = modal.querySelector('[data-eem-bulk-refund-reason]');
		var reason = reasonField ? reasonField.value : '';
		var nonceField = modal.querySelector('input[name="_eem_bulk_refund_nonce"]');
		var nonce = nonceField ? nonceField.value : '';

		// Seed the processing-state progress list with one entry per order.
		var progressList = modal.querySelector('[data-eem-bulk-refund-progress-list]');
		if (progressList) {
			progressList.innerHTML = '';
			keys.forEach(function (k) {
				var li = document.createElement('li');
				li.className = 'eem-bulk-refund-progress-item eem-bulk-refund-progress-item--queued';
				li.setAttribute('data-order-key', k);
				li.innerHTML = '<span class="eem-bulk-refund-progress-status">⏵</span> <span class="eem-bulk-refund-progress-key">' + k.substring(0, 12) + '…</span> <span class="eem-bulk-refund-progress-detail">Queued</span>';
				progressList.appendChild(li);
			});
		}

		setBulkRefundModalState(modal, 'processing');

		// Kick off the queue.
		processNextBulkRefundStep(reason, nonce);
	}

	function processNextBulkRefundStep(reason, nonce) {
		var modal = document.getElementById('eem-orders-bulk-refund-modal');
		if (!modal) return;
		if (!_bulkRefundState.queue.length) {
			showBulkRefundSummary(modal);
			return;
		}

		var orderKey = _bulkRefundState.queue.shift();
		_bulkRefundState.inFlight = orderKey;
		updateBulkRefundProgressItem(modal, orderKey, 'in-flight', '⟳', 'Processing…');

		var formData = new FormData();
		formData.append('action', 'eem_order_bulk_refund_step');
		formData.append('order_key', orderKey);
		formData.append('reason', reason);
		formData.append('_eem_bulk_refund_nonce', nonce);

		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(function (response) {
			return response.json().catch(function () { return { success: false, data: { order_key: orderKey, code: 'parse_error', message: 'Server returned unparseable response.' } }; });
		}).then(function (json) {
			_bulkRefundState.inFlight = null;
			if (json && json.success && json.data) {
				var d = json.data;
				_bulkRefundState.successes.push({ order_key: orderKey, refunded_amount: d.refunded_amount || 0, was_noop: !!d.was_noop });
				var detail = d.was_noop ? 'Already refunded' : 'Refunded $' + Number(d.refunded_amount).toFixed(2);
				updateBulkRefundProgressItem(modal, orderKey, 'success', '✓', detail);
			} else {
				var err = (json && json.data) ? json.data : { code: 'unknown', message: 'Unknown error' };
				_bulkRefundState.failures.push({ order_key: orderKey, code: err.code, message: err.message });
				updateBulkRefundProgressItem(modal, orderKey, 'failure', '✗', err.message || err.code || 'Failed');
			}
			// Process next regardless of success/failure — option-3 continue-past-failures.
			processNextBulkRefundStep(reason, nonce);
		}).catch(function () {
			_bulkRefundState.inFlight = null;
			_bulkRefundState.failures.push({ order_key: orderKey, code: 'network', message: 'Network error' });
			updateBulkRefundProgressItem(modal, orderKey, 'failure', '✗', 'Network error');
			processNextBulkRefundStep(reason, nonce);
		});
	}

	function updateBulkRefundProgressItem(modal, orderKey, statusClass, glyph, detail) {
		var item = modal.querySelector('.eem-bulk-refund-progress-item[data-order-key="' + orderKey + '"]');
		if (!item) return;
		item.classList.remove('eem-bulk-refund-progress-item--queued', 'eem-bulk-refund-progress-item--in-flight', 'eem-bulk-refund-progress-item--success', 'eem-bulk-refund-progress-item--failure');
		item.classList.add('eem-bulk-refund-progress-item--' + statusClass);
		var statusEl = item.querySelector('.eem-bulk-refund-progress-status');
		var detailEl = item.querySelector('.eem-bulk-refund-progress-detail');
		if (statusEl) statusEl.textContent = glyph;
		if (detailEl) detailEl.textContent = detail;
	}

	function showBulkRefundSummary(modal) {
		var totals = modal.querySelector('[data-eem-bulk-refund-summary-totals]');
		var failList = modal.querySelector('[data-eem-bulk-refund-failure-list]');

		if (totals) {
			var successCount = _bulkRefundState.successes.length;
			var failCount    = _bulkRefundState.failures.length;
			var totalRefunded = 0;
			_bulkRefundState.successes.forEach(function (s) { totalRefunded += Number(s.refunded_amount) || 0; });
			totals.innerHTML = '<div class="eem-bulk-refund-totals-line"><strong>' + successCount + '</strong> refunded · <strong>' + failCount + '</strong> failed</div>' +
				(totalRefunded > 0 ? '<div class="eem-bulk-refund-totals-amount">$' + totalRefunded.toFixed(2) + ' total refunded</div>' : '');
		}

		if (failList) {
			failList.innerHTML = '';
			_bulkRefundState.failures.forEach(function (f) {
				var li = document.createElement('li');
				li.className = 'eem-bulk-refund-failure-item';
				li.innerHTML = '<span class="eem-bulk-refund-failure-key">' + f.order_key.substring(0, 12) + '…</span> <span class="eem-bulk-refund-failure-msg">' + (f.message || f.code || 'Failed') + '</span>';
				failList.appendChild(li);
			});
		}

		setBulkRefundModalState(modal, 'summary');
	}

	// Retry failed: re-queue just the failed order_keys, run again.
	// Critical: each retried step still gets a fresh server-side
	// remaining_refundable compute — JS does NOT pass an amount, so
	// parallel admin actions between batch and retry are handled
	// correctly by the server.
	function retryFailedBulkRefunds() {
		var modal = document.getElementById('eem-orders-bulk-refund-modal');
		if (!modal) return;
		var failedKeys = _bulkRefundState.failures.map(function (f) { return f.order_key; });
		if (!failedKeys.length) return;

		// Reset for the retry pass. Failures roll forward into the new
		// queue; previous-pass successes stay in the running tally.
		_bulkRefundState.queue = failedKeys;
		_bulkRefundState.failures = [];
		_bulkRefundState.inFlight = null;

		var reasonField = modal.querySelector('[data-eem-bulk-refund-reason]');
		var reason = reasonField ? reasonField.value : '';
		var nonceField = modal.querySelector('input[name="_eem_bulk_refund_nonce"]');
		var nonce = nonceField ? nonceField.value : '';

		// Re-seed the progress list with just the retry items.
		var progressList = modal.querySelector('[data-eem-bulk-refund-progress-list]');
		if (progressList) {
			progressList.innerHTML = '';
			failedKeys.forEach(function (k) {
				var li = document.createElement('li');
				li.className = 'eem-bulk-refund-progress-item eem-bulk-refund-progress-item--queued';
				li.setAttribute('data-order-key', k);
				li.innerHTML = '<span class="eem-bulk-refund-progress-status">⏵</span> <span class="eem-bulk-refund-progress-key">' + k.substring(0, 12) + '…</span> <span class="eem-bulk-refund-progress-detail">Queued (retry)</span>';
				progressList.appendChild(li);
			});
		}

		setBulkRefundModalState(modal, 'processing');
		processNextBulkRefundStep(reason, nonce);
	}

	/* ── C6.B — Single-order Refund Order modal (Order Detail page) ──
	   Triggered by the More menu's "Refund Order" item. Reuses the C5.D
	   modal vocabulary; this is a single-order AJAX flow (not the C5.D
	   bulk redirect flow). On confirm: POST to wp-admin/admin-ajax.php,
	   handle JSON response with in-place fragments (option-3 UX per
	   the C6.B kickoff), fall back to toast+reload when the handler
	   sets requires_reload=true (mixed-gateway partial failure case). */

	function openOrderRefundModal() {
		var modal = document.getElementById('eem-order-refund-modal');
		if (!modal) return;
		// Reset any prior error surface from a previous open.
		var errEl = modal.querySelector('[data-eem-order-refund-error]');
		if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
		modal.classList.add('open');
		modal.setAttribute('aria-hidden', 'false');
		closeAllDropdowns();
		// Focus the amount field for fast keyboard flow.
		var amt = modal.querySelector('#eem-order-refund-amount');
		if (amt) setTimeout(function () { amt.focus(); amt.select(); }, 50);
	}

	function closeOrderRefundModal() {
		var modal = document.getElementById('eem-order-refund-modal');
		if (!modal) return;
		modal.classList.remove('open');
		modal.setAttribute('aria-hidden', 'true');
	}

	function showOrderRefundError(message) {
		var modal = document.getElementById('eem-order-refund-modal');
		if (!modal) return;
		var errEl = modal.querySelector('[data-eem-order-refund-error]');
		if (!errEl) { window.alert(message); return; }
		errEl.textContent = message;
		errEl.hidden = false;
	}

	function applyOrderRefundFragments(payload) {
		// 1) Status badge in the .eem-page-meta header (first .eem-status-badge match).
		var headerBadge = document.querySelector('.eem-page-meta .eem-status-badge');
		if (headerBadge && payload.status_badge_html) {
			var tmp = document.createElement('div');
			tmp.innerHTML = payload.status_badge_html.trim();
			var newBadge = tmp.firstChild;
			if (newBadge) headerBadge.replaceWith(newBadge);
		}

		// 2) Payment-outstanding banner.
		var banner = document.querySelector('.eem-order-payment-banner');
		if (banner) {
			if (payload.banner_html) {
				// Status still outstanding (rare for a refund-flow but
				// possible if amount was partial AND order wasn't paid
				// to begin with). Replace the banner's content block.
				var bannerContent = banner.querySelector('.eem-order-payment-banner__content');
				if (bannerContent) {
					var tmp2 = document.createElement('div');
					tmp2.innerHTML = payload.banner_html.trim();
					var newContent = tmp2.querySelector('.eem-order-payment-banner__content');
					if (newContent) bannerContent.replaceWith(newContent);
				}
			} else {
				// Order is no longer in an outstanding state — remove banner entirely.
				banner.remove();
			}
		}

		// 3) Refund History block in the Payment Details sidebar.
		var history = document.querySelector('[data-eem-refund-history]');
		if (history && payload.refund_history_html) {
			var tmp3 = document.createElement('div');
			tmp3.innerHTML = payload.refund_history_html.trim();
			var newHistory = tmp3.firstChild;
			if (newHistory) {
				newHistory.setAttribute('data-eem-refund-history', '');
				history.replaceWith(newHistory);
			}
		}

		// 4) Toast confirmation.
		if (window.EEM && typeof window.EEM.showSaveToast === 'function') {
			window.EEM.showSaveToast('Refund of $' + Number(payload.refunded_amount).toFixed(2) + ' processed.');
		}
	}

	function submitOrderRefundForm() {
		var modal = document.getElementById('eem-order-refund-modal');
		if (!modal) return;
		var form = modal.querySelector('[data-eem-order-refund-form]');
		if (!form) return;

		// Reset prior error surface.
		var errEl = modal.querySelector('[data-eem-order-refund-error]');
		if (errEl) { errEl.hidden = true; errEl.textContent = ''; }

		// Client-side sanity check — server enforces authoritatively, but
		// catch obviously bogus inputs without a round-trip.
		var amtInput = modal.querySelector('#eem-order-refund-amount');
		var amount = amtInput ? parseFloat(amtInput.value) : 0;
		if (!amount || amount <= 0) {
			showOrderRefundError('Refund amount must be greater than zero.');
			return;
		}

		// Disable the Confirm button while in-flight.
		var confirmBtn = modal.querySelector('[data-eem-action="order-refund-single-confirm"]');
		if (confirmBtn) confirmBtn.disabled = true;

		var formData = new FormData(form);

		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(function (response) {
			return response.json().catch(function () { return { success: false, data: { message: 'Unexpected server response.' } }; });
		}).then(function (json) {
			if (confirmBtn) confirmBtn.disabled = false;

			if (!json || !json.success) {
				var msg = (json && json.data && json.data.message) ? json.data.message : 'Refund failed.';
				showOrderRefundError(msg);
				return;
			}

			// Fallback condition: handler sets requires_reload=true when
			// in-place update isn't safe (mixed-gateway partial failure,
			// data shape it can't cleanly express). Toast + reload.
			if (json.data && json.data.requires_reload) {
				if (window.EEM && typeof window.EEM.showSaveToast === 'function') {
					window.EEM.showSaveToast('Refund processed. Reloading…');
				}
				setTimeout(function () { window.location.reload(); }, 600);
				return;
			}

			// In-place update path (option 3).
			applyOrderRefundFragments(json.data || {});
			closeOrderRefundModal();
		}).catch(function () {
			if (confirmBtn) confirmBtn.disabled = false;
			showOrderRefundError('Network error. Please try again.');
		});
	}

	/* Bulk action — collects selected row ids from the table checkboxes,
	   stuffs them into the hidden _eem_selected_ids field, then submits
	   the bulk form. The PHP handler explodes on comma + absint each. */
	function submitBulkAction(applyBtn) {
		var form = applyBtn.closest('form');
		if (!form) return;
		var sel = form.querySelector('[data-eem-bulk-action]');
		var hidden = form.querySelector('[data-eem-bulk-selected-ids]');
		if (!sel || !hidden) return;

		// Find the reservations list checkboxes (in the desktop table OR
		// the mobile cards). Both render <input name="reservation_ids[]">.
		var checked = document.querySelectorAll('input[name="reservation_ids[]"]:checked');
		var ids = Array.prototype.map.call(checked, function (cb) { return cb.value; });
		hidden.value = ids.join(',');

		// Confirm on trash bulk.
		if (sel.value === 'trash' && ids.length > 0) {
			if (!window.confirm('Move ' + ids.length + ' reservation' + (ids.length === 1 ? '' : 's') + ' to Trash?')) return;
		}
		form.submit();
	}

	/* Select-all checkbox in the table header — toggles every row
	   checkbox + every mobile-card checkbox together. */
	document.addEventListener('change', function (ev) {
		var t = ev.target;
		if (!t || !t.matches) return;
		// Header checkbox = the th.eem-col-cb's input.
		if (t.matches('th.eem-col-cb input[type="checkbox"]')) {
			document.querySelectorAll('input[name="reservation_ids[]"]').forEach(function (cb) {
				cb.checked = t.checked;
			});
		}
	});

	function openEmailCustomersModal(target) {
		var reservationId = target.dataset.reservationId;
		var modal = document.getElementById('eem-email-customers-modal');
		if (!modal || !reservationId) return;

		var resInput = modal.querySelector('input[name="reservation_id"]');
		if (resInput) resInput.value = reservationId;
		var summary = modal.querySelector('[data-eem-recipient-summary]');
		if (summary) summary.textContent = 'Loading recipient count…';

		modal.classList.add('open');
		modal.setAttribute('aria-hidden', 'false');
		closeAllDropdowns();

		// Pre-load recipient count.
		var nonce = modal.querySelector('input[name="_eem_email_customers_nonce"]');
		if (!nonce || !nonce.value || !window.ajaxurl) return;
		var data = new FormData();
		data.append('action', 'eem_email_customers_count');
		data.append('reservation_id', reservationId);
		data.append('_eem_email_customers_nonce', nonce.value);
		fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success && summary) {
					var n = json.data && json.data.count ? json.data.count : 0;
					summary.textContent = n === 1
						? 'This will email 1 customer with an order against this reservation.'
						: 'This will email ' + n + ' customers with an order against this reservation.';
				}
			})
			.catch(function () {
				if (summary) summary.textContent = 'Recipient count unavailable — proceed only if you know who you\'re emailing.';
			});
	}

	function closeEmailCustomersModal() {
		var modal = document.getElementById('eem-email-customers-modal');
		if (!modal) return;
		modal.classList.remove('open');
		modal.setAttribute('aria-hidden', 'true');
		var form = modal.querySelector('[data-eem-email-customers-form]');
		if (form) form.reset();
	}

	function sendEmailCustomersForm() {
		var modal = document.getElementById('eem-email-customers-modal');
		if (!modal || !window.ajaxurl) return;
		var form = modal.querySelector('[data-eem-email-customers-form]');
		if (!form) return;

		var sendBtn = modal.querySelector('[data-eem-action="email-customers-send"]');
		if (sendBtn) sendBtn.disabled = true;

		var data = new FormData(form);
		data.append('action', 'eem_email_customers');

		fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success) {
					EEM.showSaveToast((json.data && json.data.message) || 'Sent.');
					closeEmailCustomersModal();
				} else {
					var msg = (json && json.data && json.data.message) || 'Send failed.';
					EEM.showSaveToast(msg, { variant: 'error', sub: '' });
				}
			})
			.catch(function () {
				EEM.showSaveToast('Send failed — network error.', { variant: 'error', sub: '' });
			})
			.then(function () {
				if (sendBtn) sendBtn.disabled = false;
			});
	}

	/* ─────────────────────────────────────────────────────────────
	 * C6.E.2 — Add Note form (Order Detail Activity Log).
	 *
	 * Submit flow:
	 *   1. Read textarea value, abort if empty after trim (button is
	 *      disabled-when-empty anyway, but defend against any stale
	 *      enabled-state).
	 *   2. Disable submit + clear prior error.
	 *   3. POST form data via fetch → admin-ajax.php (action is in
	 *      the form's hidden 'action' input).
	 *   4. On success: prepend returned entry HTML into the activity-
	 *      list mount node, update the count badge, clear the textarea
	 *      (re-disables submit via the input listener below), and
	 *      surface a toast confirmation.
	 *   5. On error: show inline error from server message, re-enable
	 *      submit so the user can edit + retry.
	 * ───────────────────────────────────────────────────────────── */

	function submitAddNoteForm(target) {
		var form = target.closest('[data-eem-add-note-form]');
		if (!form) return;
		var section = form.closest('.eem-order-activity');
		var textarea = form.querySelector('[data-eem-add-note-textarea]');
		var errEl    = form.querySelector('[data-eem-add-note-error]');
		var btn      = form.querySelector('[data-eem-add-note-submit]');
		var note     = textarea ? (textarea.value || '').trim() : '';

		if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
		if (!note) return;  // defensive — button should be disabled

		if (btn) btn.disabled = true;

		var formData = new FormData(form);
		// Defensively re-set trimmed value so server sees the same thing JS validated.
		formData.set('note', note);

		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(function (response) {
			return response.json().catch(function () { return { success: false, data: { message: 'Unexpected server response.' } }; });
		}).then(function (json) {
			if (!json || !json.success) {
				var msg = (json && json.data && json.data.message) ? json.data.message : 'Failed to save note.';
				if (errEl) { errEl.textContent = msg; errEl.hidden = false; }
				if (btn) btn.disabled = false;
				return;
			}

			// Prepend the entry HTML — list partial may have rendered
			// the empty-state <p> instead of a <ul>, in which case
			// replace the empty paragraph with a fresh <ul> wrapping
			// the new entry.
			if (section) {
				var listMount = section.querySelector('[data-eem-activity-list]');
				if (listMount && json.data && json.data.html) {
					var ul = listMount.querySelector('ul.eem-activity-log');
					if (!ul) {
						listMount.innerHTML = '<ul class="eem-activity-log">' + json.data.html + '</ul>';
					} else {
						ul.insertAdjacentHTML('afterbegin', json.data.html);
					}
				}
				// Update count badge — server returns authoritative new_count.
				if (json.data && typeof json.data.new_count !== 'undefined') {
					var countBadge = section.querySelector('[data-eem-activity-count]');
					if (countBadge) {
						var n = parseInt(json.data.new_count, 10) || 0;
						countBadge.textContent = (n === 1) ? '1 entry' : (n + ' entries');
					}
				}
			}

			// Reset textarea (input listener re-disables submit).
			if (textarea) {
				textarea.value = '';
				textarea.dispatchEvent(new Event('input', { bubbles: true }));
			}

			if (window.EEM && typeof window.EEM.showSaveToast === 'function') {
				window.EEM.showSaveToast('Note added.');
			}
		}).catch(function () {
			if (errEl) { errEl.textContent = 'Network error. Please try again.'; errEl.hidden = false; }
			if (btn) btn.disabled = false;
		});
	}

	/* Enable/disable the Add Note submit button based on textarea content.
	   Wired via document-level input event delegation alongside the
	   existing tag-search input handler. */
	document.addEventListener('input', function (ev) {
		var t = ev.target;
		if (!t || !t.matches || !t.matches('[data-eem-add-note-textarea]')) return;
		var form = t.closest('[data-eem-add-note-form]');
		if (!form) return;
		var btn = form.querySelector('[data-eem-add-note-submit]');
		if (!btn) return;
		btn.disabled = ('' === (t.value || '').trim());
	});

	/* 2.3.72 — "Available Reservation Dates" renders in BOTH the Stall and RV
	   editor sections under the SAME field name (en_reservation[available_*_date]).
	   On submit PHP keeps only the last same-named input, so editing one section's
	   date while the other stays empty silently wiped the value. Mirror any edit to
	   every same-named input so the two instances never diverge. Fires on both
	   `input` (typing) and `change` (date-picker selection). */
	function eemSyncSharedDateInputs(t) {
		if (!t || !t.name) return;
		if (t.name !== 'en_reservation[available_start_date]' && t.name !== 'en_reservation[available_end_date]') return;
		document.querySelectorAll('input[name="' + t.name + '"]').forEach(function (el) {
			if (el !== t) { el.value = t.value; }
		});
	}
	document.addEventListener('input', function (ev) { eemSyncSharedDateInputs(ev.target); });
	document.addEventListener('change', function (ev) { eemSyncSharedDateInputs(ev.target); });

	function closeAllDropdowns() {
		document.querySelectorAll('.eem-dropdown.open, .eem-row-menu-wrap.open')
			.forEach(function (host) {
				host.classList.remove('open');
				host.classList.remove('eem-row-menu-wrap--flip-up'); /* C7.X.18 Issue C */
			});
	}

	function closeAllTagSelects(except) {
		document.querySelectorAll('.eem-tag-select.open').forEach(function (host) {
			if (host !== except) host.classList.remove('open');
		});
	}

	/* ─────────────────────────────────────────────────────────────
	 * Delegated event dispatcher
	 * Single document-level click handler dispatches by data-eem-action.
	 * ───────────────────────────────────────────────────────────── */

	var actions = {
		'section-toggle': function (target) {
			EEM.toggleSection(target.dataset.eemSection);
		},
		'section-enabled': function (target, ev) {
			EEM.toggleSectionEnabled(target, target.dataset.eemSection, ev);
		},
		'switch-toggle': function (target) {
			EEM.toggleSwitch(target);
		},
		'stay-toggle': function (target) {
			EEM.toggleStay(target);
		},
		'fee-type-select': function (target) {
			selectFeeType(target);
		},
		'mode-select': function (target) {
			selectMode(target);
		},
		'tag-open': function (target) {
			var host = target.closest('.eem-tag-select');
			if (host) EEM.tagToggle(host);
		},
		'tag-pick': function (target) {
			EEM.tagPick(target);
		},
		'tag-remove': function (target) {
			var chip = target.closest('.eem-tag-chip');
			if (chip) EEM.tagRemove(chip);
		},
		'dropdown-toggle': function (target) {
			toggleDropdown(target);
		},
		'toast-dismiss': function (target) {
			var toast = target.closest('.eem-toast');
			if (toast && typeof toast._eemDismiss === 'function') {
				toast._eemDismiss();
			} else if (toast) {
				toast.remove();
			}
		},
		'template-toggle': function (target) {
			toggleTemplateCard(target);
		},
		'placeholder-copy': function (target) {
			copyPlaceholderChip(target);
		},
		'send-test-email': function (target) {
			sendTestEmail(target);
		},
		'save-customer-note': function (target) {
			saveCustomerNote(target);
		},
		'stall-chart-toggle-groups': function (target) {
			var pressed = target.getAttribute('aria-pressed') === 'true';
			target.setAttribute('aria-pressed', pressed ? 'false' : 'true');
			target.classList.toggle('is-active', !pressed);
			if (typeof eemApplyStallChartFilter === 'function') {
				eemApplyStallChartFilter(target.closest('.eem-stall-chart-tab-panel') || document.body);
			}
		},
		'stall-chart-toggle-tack': function (target) {
			var pressed = target.getAttribute('aria-pressed') === 'true';
			target.setAttribute('aria-pressed', pressed ? 'false' : 'true');
			target.classList.toggle('is-active', !pressed);
			if (typeof eemApplyStallChartFilter === 'function') {
				eemApplyStallChartFilter(target.closest('.eem-stall-chart-tab-panel') || document.body);
			}
		},
		'logo-pick': function (target) {
			pickLogo(target);
		},
		'logo-remove': function (target) {
			removeLogo(target);
		},
		'test-feed-url': function (target) {
			testFeedUrl(target);
		},
		/* C4.C — Reservations list row actions. The first four are
		   admin-post form submits (JS builds a hidden form + submits
		   so we redirect-with-message rather than maintaining AJAX
		   state in the dropdown). Email Customers is AJAX because
		   it has a compose modal. */
		/* FIX 3/5 (2.3.43) — Duplicate now in row actions; uses AJAX + redirect
		   so the admin lands on the new reservation's Edit page. */
		'reservation-duplicate-ajax': function (target) {
			duplicateReservationAjax(target);
		},
		/* Kept for backward-compat (old meatball button form path).
		   FIX 4 removes the meatball entry; this handler stays so any
		   existing admin-post form submits still work. */
		'reservation-duplicate': function (target) {
			submitReservationAction(target, 'eem_reservation_duplicate', 'eem_reservation_duplicate');
		},
		'reservation-trash': function (target) {
			if (!window.confirm('Move this reservation to Trash?')) return;
			submitReservationAction(target, 'eem_reservation_trash', 'eem_reservation_trash');
		},
		'reservation-restore': function (target) {
			submitReservationAction(target, 'eem_reservation_restore', 'eem_reservation_restore');
		},
		/* C7.X.17 Issue D3 — typed-confirm modal replaces the simple window.confirm.
		   openDeletePermanentlyModal handles the modal, typed-title validation,
		   and submits with confirmation_title extra field when confirmed. */
		'reservation-delete-permanently': function (target) {
			openDeletePermanentlyModal(target);
		},
		'bulk-apply': function (target, ev) {
			// Hook the bulk form's submit — collect selected reservation ids
			// from the table checkboxes into the hidden input, validate basics,
			// then let the form submit normally to admin-post.php.
			ev.preventDefault();
			submitBulkAction(target);
		},
		'reservation-export-roster': function (target) {
			submitReservationAction(target, 'eem_reservation_export_roster', 'eem_reservation_export_roster');
		},
		'reservation-email-customers': function (target) {
			openEmailCustomersModal(target);
		},
		/* FIX 1 (2.3.43) — Pencil inline-edit in the editor header. */
		'res-name-edit': function (target) {
			openResNameEdit(target);
		},
		'res-name-save': function (target) {
			saveResNameEdit(target);
		},
		'res-name-cancel': function () {
			cancelResNameEdit();
		},
		/* FIX 5 (2.3.42) — Quick Edit inline row. */
		'reservation-quick-edit': function (target) {
			openQuickEdit(target);
		},
		'reservation-quick-edit-save': function (target) {
			saveQuickEdit(target);
		},
		'reservation-quick-edit-cancel': function (target) {
			closeQuickEdit(target.dataset.reservationId);
		},
		'email-customers-close': function () {
			closeEmailCustomersModal();
		},
		'email-customers-send': function () {
			sendEmailCustomersForm();
		},
		/* C5.G.2 — Orders list row actions. Pattern parallels the
		   C4.C reservation-* arms; submitOrderAction helper above
		   handles nonce lookup via window.eemOrderRowActions. */
		'order-print-receipt': function (target) {
			submitOrderAction(target, 'eem_order_print_receipt', 'eem_order_print_receipt');
		},
		'order-trash': function (target) {
			submitOrderAction(target, 'eem_order_trash', 'eem_order_trash', 'Move this order to Trash?');
		},
		'order-resend-notification': function (target) {
			submitOrderAction(target, 'eem_order_resend_notification', 'eem_order_resend_notification');
		},
		'order-export-csv': function (target) {
			submitOrderAction(target, 'eem_order_export_csv', 'eem_order_export_csv');
		},
		'orders-toggle-all': function (target) {
			// Dispatcher already called ev.preventDefault() which suppresses
			// the browser's default checkbox-toggle, so flip manually first
			// before propagating to row checkboxes.
			target.checked = !target.checked;
			var checked = !!target.checked;
			document.querySelectorAll('input.eem-orders-row-cb').forEach(function (cb) { cb.checked = checked; });
		},
		'orders-bulk-apply': function () {
			openOrdersBulkRefundModal();
		},
		'orders-bulk-refund-close': function () {
			closeOrdersBulkRefundModal();
		},
		'orders-bulk-refund-confirm': function () {
			startBulkRefundQueue();
		},
		'orders-bulk-refund-retry': function () {
			retryFailedBulkRefunds();
		},
		/* C6.E.1 — Activity log section collapsible toggle.
		   Click on the .eem-order-activity__toggle div flips .collapsed
		   on the parent .eem-order-activity section. CSS hides
		   .eem-order-activity__list when .collapsed is set. */
		'activity-toggle': function (target) {
			var section = target.closest('.eem-order-activity');
			if (!section) return;
			var collapsed = section.classList.toggle('collapsed');
			target.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		},
		/* C6.B — Single-order Refund modal (Order Detail page). */
		'order-refund-single': function () {
			openOrderRefundModal();
		},
		'order-refund-single-close': function () {
			closeOrderRefundModal();
		},
		'order-refund-single-confirm': function () {
			submitOrderRefundForm();
		},
		/* C6.E.2 — Add Note form submit (Order Detail activity log). */
		'add-note-submit': function (target) {
			submitAddNoteForm(target);
		},

		/* C8 — header event-anchor delegation. Replaces inline onclick
		   handlers that silently failed because the functions are defined
		   inside the IIFE scope, not on window. */
		'header-change-event': function () {
			changeLinkedEvent();
		},
		'header-cancel-change': function () {
			cancelChangeEvent();
		},
		'header-select-event': function (target) {
			selectLinkedEvent(target.dataset.eventId);
		},
		'toggle-inventory-mode': function (target) {
			toggleInventoryMode(target);
		},
		'toggle-stall-inventory-type': function (target) {
			toggleStallInventoryType(target);
		},
		'toggle-stall-customer-selection': function (target) {
			toggleStallCustomerSelection(target);
		},

		/* C8 — Stall row builder */
		'stall-add-row': function () {
			stallAddRow();
		},
		'stall-delete-row': function (target) {
			stallDeleteRow(target);
		},

		/* C8 — RV zones + row builder */
		'rv-add-zone': function () {
			rvAddZone();
		},
		'rv-delete-zone': function (target) {
			rvDeleteZone(target);
		},
		'rv-add-row': function () {
			rvAddRow();
		},
		'rv-delete-row': function (target) {
			rvDeleteRow(target);
		},

		/* C8 — Event Pre-Entries */
		'pre-entry-add': function () {
			preEntryAdd();
		},
		'pre-entry-delete': function (target) {
			preEntryDelete(target);
		}
	};

	document.addEventListener('click', function (ev) {
		var actionTarget = ev.target.closest('[data-eem-action]');
		if (actionTarget) {
			var name = actionTarget.dataset.eemAction;
			if (actions[name]) {
				ev.preventDefault();
				actions[name](actionTarget, ev);
				return;
			}
		}

		// Click outside any open dropdown / tag-select closes them.
		if (!ev.target.closest('.eem-dropdown, .eem-row-menu-wrap')) {
			closeAllDropdowns();
		}
		if (!ev.target.closest('.eem-tag-select')) {
			closeAllTagSelects();
		}
	});

	// Tag-search uses input event, not click — wire separately.
	// Bug D fix: before filtering, populate dropdown from live row-builder data
	// so blocked stalls / blocked RV lots searches work without pre-populated items.
	document.addEventListener('input', function (ev) {
		var input = ev.target;
		if (input.matches && input.matches('.eem-tag-search')) {
			var target = input.dataset.eemTagTarget;
			if (target === 'eem-blocked-stalls-select') {
				populateTagDropdownFromLabels(input, getStallLabels());
			} else if (target === 'eem-blocked-rv-lots-select') {
				populateTagDropdownFromLabels(input, getRvLotLabels());
			}
			EEM.tagFilter(input);
		}
	});

	/* C8 — header event search input delegation.
	   Replaces oninput="filterEventOptions(this.value)" inline handler
	   which silently failed because filterEventOptions lives inside the
	   IIFE scope, not on window. Wired at module level (document exists
	   immediately; no DOMContentLoaded wrapper needed). */
	document.addEventListener('input', function (ev) {
		if (ev.target && ev.target.dataset.eemInputAction === 'header-filter-events') {
			filterEventOptions(ev.target.value);
		}
	});

	/* C8 — Row builder / zone / pre-entry input delegation. */
	document.addEventListener('input', function (ev) {
		var inp = ev.target;
		if (!inp || !inp.dataset) return;
		var ia = inp.dataset.eemInputAction;
		if (!ia) return;
		if (ia === 'stall-row-input')  { stallRowInputChange(inp); }
		if (ia === 'stall-row-layout') { stallRowLayoutChange(inp); }
		if (ia === 'rv-zone-input')    { rvZoneInputChange(inp); }
		if (ia === 'rv-row-input')     { rvRowInputChange(inp); }
		if (ia === 'rv-row-layout')    { rvRowLayoutChange(inp); }
		/* FIX 4 (2.3.42) — Reservation Details card auto-mirror. */
		if (ia === 'res-name-input')   { resNameInput(inp); }
		if (ia === 'res-slug-input')   { resSlugInput(inp); }
	});

	/* Selects fire 'change', not 'input' — wire a separate listener. */
	document.addEventListener('change', function (ev) {
		var inp = ev.target;
		if (!inp || !inp.dataset) return;
		var ia = inp.dataset.eemInputAction;
		if (!ia) return;
		if (ia === 'stall-row-layout') { stallRowLayoutChange(inp); }
		if (ia === 'rv-row-layout')    { rvRowLayoutChange(inp); }
		/* V1: Zone dropdown on row card — update row's color indicator immediately. */
		if (ia === 'rv-row-input' && inp.dataset.field === 'zone_id') {
			var rowCard = inp.closest('.eem-row-card');
			if (rowCard) rvUpdateRowZoneIndicator(rowCard);
		}
	});

	// Escape key closes open overlays.
	document.addEventListener('keydown', function (ev) {
		if (ev.key !== 'Escape') return;
		closeAllDropdowns();
		closeAllTagSelects();
		var banner = document.querySelector('.eem-destination-banner.open');
		if (banner) banner.classList.remove('open');
	});

	/* ─────────────────────────────────────────────────────────────
	 * Initial pass — apply data-controls visibility on page load
	 * ───────────────────────────────────────────────────────────── */

	function initControls() {
		document.querySelectorAll('.eem-toggle[data-controls], .eem-stay-type-btn[data-controls]')
			.forEach(EEM.applyControls);
		document.querySelectorAll('.eem-fee-type-group')
			.forEach(function (group) { EEM.applyFeeTypeVisibility(group); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initControls);
	} else {
		initControls();
	}

	/* ─────────────────────────────────────────────────────────────
	 * Utilities
	 * ───────────────────────────────────────────────────────────── */

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function cssEscape(value) {
		if (window.CSS && typeof window.CSS.escape === 'function') {
			return window.CSS.escape(value);
		}
		return String(value).replace(/[^a-zA-Z0-9_-]/g, function (c) {
			return '\\' + c;
		});
	}

	/* ─────────────────────────────────────────────────────────────
	   C7.B.1 — Reservation Editor section toggles
	   Skeleton-level interactivity for collapsing sections + flipping
	   the "Enabled" toggle. State is visual-only in C7.B.1 — no
	   persistence yet (save dispatcher lands in C7.B.2 + per-section
	   wiring in C7.C).
	   ───────────────────────────────────────────────────────────── */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		// C7.B.2.1 — check enable BEFORE collapse. The enable toggle
		// is nested inside the section-header (which carries the
		// collapse action), so a click on the toggle matches BOTH
		// selectors via t.closest(). Previous ordering returned out of
		// the entire handler in the collapse branch when it noticed
		// the click came from an enable toggle — meaning the enable
		// handler below it never fired. Swap the order.
		var enable = t.closest('[data-eem-action="reservation-editor-toggle-enabled"]');
		if (enable) {
			ev.stopPropagation(); // don't trigger the parent collapse handler
			var key = enable.dataset.eemSection;
			if (!key) return;
			var toggle = enable.querySelector('.eem-toggle');
			var body2 = document.getElementById('body-' + key);
			if (!toggle) return;
			var nowOn = toggle.classList.contains('eem-toggle--on');
			toggle.classList.toggle('eem-toggle--on', !nowOn);
			toggle.classList.toggle('eem-toggle--off', nowOn);
			if (body2) {
				body2.classList.toggle('eem-section-body--disabled', nowOn);
			}
			// C7.C.1.1 — Desync A/B fix. Header toggle is now the
			// authoritative visible control; flip the matching body
			// hidden input so save_meta sees the new persisted state.
			// Selector matches the partial-emitted
			// `<input data-eem-section-enabled="<key>">` mirror.
			if (body2) {
				var hidden = body2.querySelector('input[type="hidden"][data-eem-section-enabled="' + key + '"]');
				if (hidden) { hidden.value = nowOn ? '0' : '1'; }
			}
			// C7.C.1.2 — disabling a section also collapses its body
			// to header-only (no wasted vertical space, no implied
			// interactability). Enabling re-expands. The chevron click
			// handler still independently toggles collapse, so users
			// can chevron-expand a disabled section to peek at filled
			// data (which renders with the striped overlay applied
			// above). `card` + `header` derived from the body's nearest
			// ancestors so we don't depend on ID conventions.
			var card2   = body2 ? body2.closest('.eem-reservation-editor-section') : null;
			var header2 = card2 ? card2.querySelector('.eem-section-header') : null;
			if (card2 && body2) {
				// nowOn === true means user just turned the section OFF
				card2.classList.toggle('eem-section-collapsed', nowOn);
				body2.classList.toggle('eem-section-body--hidden', nowOn);
				if (header2) { header2.classList.toggle('is-open', !nowOn); }
			}
			return;
		}

		var collapse = t.closest('[data-eem-action="reservation-editor-toggle-collapse"]');
		if (collapse) {
			var sectionKey = collapse.dataset.eemSection;
			if (!sectionKey) return;
			var card = document.getElementById('card-' + sectionKey);
			var body = document.getElementById('body-' + sectionKey);
			if (!card || !body) return;
			card.classList.toggle('eem-section-collapsed');
			body.classList.toggle('eem-section-body--hidden');
			collapse.classList.toggle('is-open');
			// Bug-fix: persist section collapse state so it survives
			// save+reload. Key: eem-section-STATE-{rid}-{cardId}
			try {
				var stickyBar = document.getElementById('eem-sticky-save');
				var rid = stickyBar ? (stickyBar.dataset.eemReservationId || '0') : '0';
				var cardId = card.id || '';
				if (cardId && window.sessionStorage) {
					var isNowCollapsed = card.classList.contains('eem-section-collapsed');
					sessionStorage.setItem(
						'eem-section-STATE-' + rid + '-' + cardId,
						isNowCollapsed ? 'collapsed' : 'expanded'
					);
				}
			} catch (e) { /* sessionStorage unavailable — degrade silently */ }
		}
	});

	/* ─────────────────────────────────────────────────────────────
	   C7.B.2 — Reservation Editor save bar + Linked Event modal
	   Save bar dispatches Save Draft / Publish / Update via AJAX.
	   Modal opens via meta-line launcher, source-mode picker drives
	   body switch (typeahead for native+tec, URL input for feed),
	   typeahead debounced search hits a (placeholder for now) WP_Query
	   endpoint, Save confirms then dispatches the change AJAX.
	   ───────────────────────────────────────────────────────────── */

	var EEM_EDITOR_AJAX_URL = (window.ajaxurl || '/wp-admin/admin-ajax.php');

	function eemReservationEditorNonce() {
		// C7.X.15 Issue 2A — the original lookup queried `.eem-save-bar`,
		// which was retired at C7.X.3. The nonce input now lives in the
		// rail Publish card; use a generic name-based query so future
		// markup moves don't re-break this.
		var input = document.querySelector('input[name="_eem_editor_nonce"]');
		return input ? input.value : '';
	}

	function eemReservationEditorPostAjax(action, params) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('_eem_editor_nonce', eemReservationEditorNonce());
		Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
		return fetch(EEM_EDITOR_AJAX_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function eemSaveBarToast(message, variant) {
		if (window.EEM && typeof window.EEM.showSaveToast === 'function') {
			window.EEM.showSaveToast(message, { variant: variant || 'success', sub: '' });
		}
	}

	/* C7.C.1 — serialize every `en_reservation[*]` field in the editor
	   body so the AJAX dispatcher can hand the payload to the legacy
	   EEM_Reservations_CPT::save_meta() 93-field handler. Unchecked
	   checkboxes / radios deliberately skipped to match WP's native
	   form-submit behavior — save_meta()'s sanitizer treats absent
	   `*_enabled` keys as "off". */
	function eemCollectEditorFields() {
		var body = document.querySelector('.eem-reservation-editor-body');
		if (!body) return [];
		var out = [];
		// C8-fix: broadened to also capture eem_* C8 fields and the two
		// bare-named mode inputs. Originally only `en_reservation[*]`
		// (legacy namespace) was collected, silently dropping every C8
		// field (stall_rows, rv_zones, selection modes, blocked lots,
		// pre-entries, max-per-customer, etc.) from every AJAX save.
		body.querySelectorAll(
			'input[name^="en_reservation"], select[name^="en_reservation"], textarea[name^="en_reservation"], ' +
			'input[name^="eem_"], select[name^="eem_"], textarea[name^="eem_"], ' +
			'input[name="stall_selection_mode"], input[name="rv_selection_mode"]'
		).forEach(function (el) {
			if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
			// C7.C.1.1 — hidden section-enabled mirrors with value="0"
			// must be SKIPPED to mirror unchecked-checkbox behavior the
			// legacy save_meta() sanitizer expects (`isset($source[X])
			// ? 1 : 0` pattern — presence means on, absence means off).
			// Without this, every section header toggle would persist
			// as "on" because the hidden input is always present.
			if (el.type === 'hidden' && el.hasAttribute('data-eem-section-enabled') && '1' !== el.value) return;
			// C7.C.1.4.A — same skip for sub-section toggle mirrors
			// (toggle-label-row hidden inputs). Without this, the
			// legacy `isset($source[X_enabled]) ? 1 : 0` sanitize
			// pattern treats value='0' as enabled, breaking the
			// off-state persistence for grounds-fee / deposit / etc.
			if (el.type === 'hidden' && el.hasAttribute('data-eem-subsection-enabled') && '1' !== el.value) return;
			// C7.X.4 — same skip for stay-type pair hidden mirrors.
			if (el.type === 'hidden' && el.hasAttribute('data-eem-stay-type-mirror') && '1' !== el.value) return;
			out.push([el.name, el.value]);
		});
		return out;
	}

	function eemDispatchSave(kind) {
		// C7.X.15 Issue 2A — the original lookup queried `.eem-save-bar`,
		// which was retired at C7.X.3. Source the reservation-id from
		// any element carrying the data attribute (rail Publish card,
		// sticky-save mobile, or .eem-reservation-editor-body all carry
		// it). On success, reload — the rail Publish card is PHP-rendered
		// so a reload re-renders it with state-correct buttons (replaces
		// the pre-C7.X.15 client-side eemUpdateSaveBarButtons morphing
		// which is now no-op since `.eem-save-bar` is gone).
		var src = document.querySelector('[data-eem-reservation-id]');
		if (!src) return;
		var rid = src.getAttribute('data-eem-reservation-id');
		if (!rid) return;
		var body = new URLSearchParams();
		body.set('action', 'eem_reservation_editor_save');
		body.set('_eem_editor_nonce', eemReservationEditorNonce());
		body.set('reservation_id', rid);
		body.set('save_kind', kind);
		eemCollectEditorFields().forEach(function (pair) { body.append(pair[0], pair[1]); });
		// 2.3.78 — the linked-event id input lives in the header typeahead, OUTSIDE
		// .eem-reservation-editor-body, so eemCollectEditorFields() never collected
		// it. That meant a New Reservation could never persist its event link
		// (save saw event_id absent → the gate-robustness guard kept the existing 0),
		// so the gate never lifted and the title never mirrored the event. Submit it
		// explicitly. Only when non-zero, so an empty picker can't clear an existing
		// link (the guard preserves it).
		var linkedEventInput = document.getElementById('eem-linked-event-id-input');
		if (linkedEventInput && linkedEventInput.value && '0' !== String(linkedEventInput.value)) {
			body.set('en_reservation[event_id]', linkedEventInput.value);
		}
		fetch(EEM_EDITOR_AJAX_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (resp) {
			if (resp && resp.success) {
				eemSaveBarToast(resp.data && resp.data.message ? resp.data.message : 'Saved.', 'success');
				// Brief delay so the toast is visible before reload swaps
				// the rail buttons. 600ms is short enough to feel snappy.
				setTimeout(function () { window.location.reload(); }, 600);
			} else {
				// C7.C.1.1 — surface the actual validation error from
				// the server, not a generic "Save failed." that hid the
				// real reason. ajax_save now returns wp_send_json_error
				// with `message` carrying the first validation-error
				// string and `errors` carrying the full list.
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
				// C7.X.16 Issue I — publish-gate failure surfaces with a
				// per-section error map + first_section key. Highlight
				// every failed section's card with .eem-section-invalid
				// + scroll-and-focus the first failure.
				if (resp && resp.data && 'publish_validation_failed' === resp.data.code) {
					// Strip any prior invalid highlights.
					document.querySelectorAll('.eem-reservation-editor-section.eem-section-invalid')
						.forEach(function (c) { c.classList.remove('eem-section-invalid'); });
					// Apply highlight to each failed section.
					var errs = (resp.data.errors && typeof resp.data.errors === 'object') ? resp.data.errors : {};
					Object.keys(errs).forEach(function (sk) {
						var card = document.getElementById('card-' + sk);
						if (card) card.classList.add('eem-section-invalid');
					});
					// Scroll the first failed section into view + focus.
					var firstKey = resp.data.first_section || '';
					if (firstKey) {
						var firstCard = document.getElementById('card-' + firstKey);
						if (firstCard) {
							firstCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
							// Brief auto-clear after 6s so the highlights
							// don't persist into other interactions; user
							// re-trying publish will re-trigger if still
							// invalid.
							setTimeout(function () {
								document.querySelectorAll('.eem-reservation-editor-section.eem-section-invalid')
									.forEach(function (c) { c.classList.remove('eem-section-invalid'); });
							}, 6000);
						}
					}
				}
				eemSaveBarToast(msg, 'error');
			}
		}).catch(function () {
			eemSaveBarToast('Could not reach the server.', 'error');
		});
	}

	// C7.X.15 Issue 2A — `eemUpdateSaveBarButtons` REMOVED. It morphed
	// the legacy save bar's primary-button container client-side after
	// a save; that ancestor element was retired at C7.X.3 (replaced by
	// the rail Publish card). The function had been dead since C7.X.3
	// because its container lookup always returned null. The new flow
	// reloads the page on save success — the rail Publish card is
	// PHP-rendered with state-correct buttons.

	/* Save bar click handlers */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		if (t.closest('[data-eem-action="reservation-editor-save-draft"]')) {
			ev.preventDefault();
			eemDispatchSave('save_draft');
			return;
		}
		if (t.closest('[data-eem-action="reservation-editor-publish"]') ||
		    t.closest('[data-eem-action="reservation-editor-update"]')) {
			ev.preventDefault();
			var saveKind = t.closest('[data-eem-action="reservation-editor-publish"]') ? 'publish' : 'update';
			eemDispatchSave(saveKind);
			return;
		}
		/* Cancel anchor is a real href — let the browser navigate, no dispatch needed */
	});

	/* C7.C.1 — Repeating-row helper handlers (add + remove). Shared
	   surface so C7.C.2's Stall Rows / RV Lot Zones reuse the same
	   wiring. Each helper container exposes its config via
	   `data-eem-repeating-*` attrs (template id + tbody id). New row
	   indices are derived from `tbody.children.length` to keep
	   `name="…[N][field]"` stable across add + remove cycles. */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var addBtn = t.closest('[data-eem-action="reservation-editor-add-repeating-row"]');
		if (addBtn) {
			ev.preventDefault();
			// C7.X.11 — read template/tbody IDs from the BUTTON when it
			// carries them (C7.X.4+ mockup-canonical partials in
			// _section-addons.php + _section-rv.php). Fall back to the
			// .eem-repeating-row-helper ancestor for any caller still
			// using the (now-orphan) _repeating-row-helper.php partial.
			// Before C7.X.11, the handler ONLY read from the ancestor;
			// the bare-button partials had no ancestor, so clicks
			// silently no-op'd. Bug shipped at C7.X.4, caught at
			// C7.X.10 visual verify (Whitney VV-6).
			var source = addBtn.hasAttribute('data-eem-repeating-template')
				? addBtn
				: addBtn.closest('.eem-repeating-row-helper');
			if (!source) return;
			var templateId = source.getAttribute('data-eem-repeating-template');
			var tbodyId    = source.getAttribute('data-eem-repeating-tbody');
			var template   = document.getElementById(templateId);
			var tbody      = document.getElementById(tbodyId);
			if (!template || !tbody) return;
			var clone = template.content ? template.content.cloneNode(true) : null;
			if (!clone) {
				// Fallback for browsers without <template>.content support.
				var wrapper = document.createElement('tbody');
				wrapper.innerHTML = template.innerHTML;
				clone = wrapper.firstElementChild;
			}
			var nextIndex = tbody.children.length;
			// Rewrite __index__ tokens to the new row index across every
			// attribute on every descendant of the cloned row.
			var walker = document.createTreeWalker(clone, NodeFilter.SHOW_ELEMENT, null, false);
			var nodes = [];
			var n;
			while ((n = walker.nextNode())) { nodes.push(n); }
			nodes.forEach(function (node) {
				Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
					if (attr.value && attr.value.indexOf('__index__') !== -1) {
						node.setAttribute(attr.name, attr.value.split('__index__').join(String(nextIndex)));
					}
				});
			});
			tbody.appendChild(clone);
			return;
		}

		var removeBtn = t.closest('[data-eem-action="reservation-editor-remove-repeating-row"]');
		if (removeBtn) {
			ev.preventDefault();
			var row = removeBtn.closest('tr');
			if (row && row.parentNode) { row.parentNode.removeChild(row); }
			return;
		}
	});

	/* C7.C.1 — Fee-type visibility helper. The Convenience Fee section's
	   `[convenience_fee_type]` select toggles between None / Flat /
	   Percentage. The render_fee_value_row() helper outputs both $ and
	   % flavors with corresponding row classes; we show only the row
	   matching the current selection. None hides both. */
	function eemApplyFeeTypeVisibility(select) {
		if (!select) return;
		var value = select.value;
		var scope = select.closest('.eem-editor-fields') || document;
		var flatRow    = scope.querySelector('.eem-fee-value-row--flat');
		var percentRow = scope.querySelector('.eem-fee-value-row--percentage');
		if (flatRow)    { flatRow.style.display    = ('flat' === value)       ? '' : 'none'; }
		if (percentRow) { percentRow.style.display = ('percentage' === value) ? '' : 'none'; }
	}
	document.addEventListener('change', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		var sel = t.closest('[data-eem-action="reservation-editor-fee-type-change"]');
		if (sel) { eemApplyFeeTypeVisibility(sel); }
	});
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-eem-action="reservation-editor-fee-type-change"]').forEach(eemApplyFeeTypeVisibility);
	});

	/* ─────────────────────────────────────────────────────────────
	   C7.C.1.4.A — Sub-section toggle (toggle-label-row) handler +
	   applyControls() global re-evaluate + fee-mode pill handler.

	   Sub-section toggle pattern (mockup line 147 .toggle-label-row):
	   one click flips the `.eem-toggle--on/--off` indicator class
	   AND the sibling hidden input's value AND re-evaluates conditional
	   visibility across the entire editor body (Decision D: global
	   re-evaluate; row hidden if ANY covering controller is off).

	   data attributes (per Decision G):
	     data-eem-action="reservation-editor-toggle-subsection"
	     data-eem-controls="eem-ctrl--token-1 eem-ctrl--token-2"
	     data-eem-subsection-enabled="<slug>"  (on the hidden input)

	   Dependent rows carry `eem-ctrl--<token>` class plus the
	   `eem-row--hidden` class when initially off (computed PHP-side).
	   ───────────────────────────────────────────────────────────── */
	function eemApplyControls() {
		var editor = document.querySelector('.eem-reservation-editor-body');
		if (!editor) return;
		// Gather the set of "off" controller tokens — any toggle-label-row
		// whose hidden input value is not '1' contributes its data-eem-
		// controls tokens to the off-set. Union semantics: a row hides if
		// ANY of its controllers is off.
		var offTokens = {};
		editor.querySelectorAll('[data-eem-action="reservation-editor-toggle-subsection"]').forEach(function (ctrl) {
			var hidden = ctrl.querySelector('input[type="hidden"][data-eem-subsection-enabled]');
			if (!hidden || '1' === hidden.value) return;
			var tokens = (ctrl.getAttribute('data-eem-controls') || '').split(/\s+/).filter(Boolean);
			tokens.forEach(function (tok) { offTokens[tok] = true; });
		});
		// Walk every row that carries any eem-ctrl-- token, apply or
		// remove eem-row--hidden based on the offTokens union.
		editor.querySelectorAll('.eem-field-row').forEach(function (row) {
			var rowOff = false;
			row.classList.forEach(function (cls) {
				if (cls.indexOf('eem-ctrl--') === 0 && offTokens[cls]) { rowOff = true; }
			});
			row.classList.toggle('eem-row--hidden', rowOff);
		});
	}

	/* Sub-section toggle click handler */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		var toggleRow = t.closest('[data-eem-action="reservation-editor-toggle-subsection"]');
		if (!toggleRow) return;
		ev.preventDefault();
		ev.stopPropagation();
		var indicator = toggleRow.querySelector('.eem-toggle');
		var hidden    = toggleRow.querySelector('input[type="hidden"][data-eem-subsection-enabled]');
		if (!indicator || !hidden) return;
		var nowOn = indicator.classList.contains('eem-toggle--on');
		indicator.classList.toggle('eem-toggle--on', !nowOn);
		indicator.classList.toggle('eem-toggle--off', nowOn);
		hidden.value = nowOn ? '0' : '1';
		eemApplyControls();
	});

	/* Fee-mode pill triplet handler (None / Flat / Percentage) */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		var btn = t.closest('[data-eem-action="reservation-editor-fee-mode"]');
		if (!btn) return;
		ev.preventDefault();
		var mode = btn.getAttribute('data-eem-fee-mode');
		if (!mode) return;
		var modes = btn.closest('.eem-fee-modes');
		if (modes) {
			modes.querySelectorAll('.eem-fee-mode-btn').forEach(function (b) {
				b.classList.toggle('eem-fee-mode-btn--active', b === btn);
			});
		}
		// Update the hidden mirror that persists the selection.
		var section = btn.closest('.eem-reservation-editor-section');
		var mirror  = section ? section.querySelector('[data-eem-fee-mode-mirror]') : null;
		if (mirror) { mirror.value = mode; }
		// Toggle conditional fee rows via the eem-ctrl-- class system.
		// Flat row carries eem-ctrl--fee-flat, Percentage row carries
		// eem-ctrl--fee-pct; both hidden when mode === 'none'.
		if (section) {
			var flatRow = section.querySelector('.eem-ctrl--fee-flat');
			var pctRow  = section.querySelector('.eem-ctrl--fee-pct');
			if (flatRow) { flatRow.classList.toggle('eem-row--hidden', 'flat' !== mode); }
			if (pctRow)  { pctRow.classList.toggle('eem-row--hidden',  'percentage' !== mode); }
		}
	});

	/* Initial applyControls() pass on page load */
	document.addEventListener('DOMContentLoaded', eemApplyControls);

	/* Bug-fix: restore per-section collapse/expand state from sessionStorage
	   so that section open/closed state survives a save+reload cycle.
	   Key pattern: eem-section-STATE-{reservationId}-{cardId} */
	document.addEventListener('DOMContentLoaded', function () {
		try {
			if (!window.sessionStorage) { return; }
			var stickyBar = document.getElementById('eem-sticky-save');
			var rid = stickyBar ? (stickyBar.dataset.eemReservationId || '0') : '0';
			document.querySelectorAll('.eem-reservation-editor-section[id]').forEach(function (card) {
				var key = 'eem-section-STATE-' + rid + '-' + card.id;
				var saved = sessionStorage.getItem(key);
				if (!saved) { return; }
				var body = document.getElementById('body-' + card.id.replace(/^card-/, ''));
				if (saved === 'collapsed') {
					card.classList.add('eem-section-collapsed');
					if (body) { body.classList.add('eem-section-body--hidden'); }
				} else if (saved === 'expanded') {
					card.classList.remove('eem-section-collapsed');
					if (body) { body.classList.remove('eem-section-body--hidden'); }
				}
			});
		} catch (e) { /* sessionStorage unavailable — degrade silently */ }
	});

	/* V1: Apply initial zone color indicators to seeded RV row cards on load. */
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('#eem-rv-row-builder-list .eem-row-card').forEach(function (card) {
			rvUpdateRowZoneIndicator(card);
		});
	});

	/* Linked Event modal — launcher + source-mode picker + typeahead + Save */
	function eemModalOpen(id) {
		var modal = document.getElementById(id);
		if (!modal) return;
		modal.classList.add('open');
	}
	function eemModalClose(id) {
		var modal = document.getElementById(id);
		if (!modal) return;
		modal.classList.remove('open');
	}

	function eemLinkedEventSetSource(modal, sourceKey) {
		var btns = modal.querySelectorAll('.eem-source-mode-btn');
		btns.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-eem-source') === sourceKey); });
		var pickers = modal.querySelectorAll('[data-eem-source-picker]');
		pickers.forEach(function (p) {
			var match = p.getAttribute('data-eem-source-picker') === sourceKey;
			p.hidden = !match;
		});
		modal.setAttribute('data-eem-current-source', sourceKey);
		var err = modal.querySelector('.eem-modal-linked-event__error');
		if (err) { err.hidden = true; err.textContent = ''; }
	}

	function eemReadModalEventId(modal) {
		var source = modal.getAttribute('data-eem-current-source') || 'native';
		if ('feed' === source) {
			var feedInput = modal.querySelector('.eem-modal-linked-event__feed-url');
			return feedInput ? feedInput.value.trim() : '';
		}
		var hidden = modal.querySelector('.eem-modal-linked-event__selected-id');
		return hidden ? hidden.value.trim() : '';
	}

	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var launcher = t.closest('[data-eem-action="reservation-editor-launch-linked-event-modal"]');
		if (launcher) {
			ev.preventDefault();
			var modal = document.getElementById('eem-modal-linked-event');
			if (!modal) return;
			var currentSource = launcher.getAttribute('data-eem-current-source') || 'native';
			eemLinkedEventSetSource(modal, currentSource);
			eemModalOpen('eem-modal-linked-event');
			return;
		}

		var closer = t.closest('[data-eem-action="reservation-editor-modal-close"]');
		if (closer) {
			ev.preventDefault();
			eemModalClose(closer.getAttribute('data-eem-modal') || 'eem-modal-linked-event');
			return;
		}

		var sourceBtn = t.closest('.eem-source-mode-btn');
		if (sourceBtn && sourceBtn.closest('#eem-modal-linked-event')) {
			ev.preventDefault();
			var modal2 = document.getElementById('eem-modal-linked-event');
			eemLinkedEventSetSource(modal2, sourceBtn.getAttribute('data-eem-source'));
			return;
		}

		var saver = t.closest('[data-eem-action="reservation-editor-linked-event-save"]');
		if (saver) {
			ev.preventDefault();
			var modal3 = document.getElementById('eem-modal-linked-event');
			var source = modal3.getAttribute('data-eem-current-source') || 'native';
			var eventId = eemReadModalEventId(modal3);
			var errEl = modal3.querySelector('.eem-modal-linked-event__error');
			if (!eventId) {
				if (errEl) { errEl.textContent = 'Select an event before saving.'; errEl.hidden = false; }
				return;
			}
			/* Decision H: confirmation prompt before AJAX dispatch */
			if (!window.confirm(
				'Changing the linked event will trigger rate recalculation, stall-chart re-resolution, and other downstream changes on next reservation save. Continue?'
			)) { return; }
			var rid = saver.getAttribute('data-eem-reservation-id');
			eemReservationEditorPostAjax('eem_reservation_editor_change_linked_event', {
				reservation_id: rid,
				source: source,
				event_id: eventId
			}).then(function (resp) {
				if (resp && resp.success) {
					/* Decision K: DOM-replace the meta-line + close modal + toast */
					if (resp.data && resp.data.meta_line_html) {
						var existing = document.querySelector('.eem-reservation-editor-meta-line');
						if (existing && existing.parentElement) {
							var tmp = document.createElement('div');
							tmp.innerHTML = resp.data.meta_line_html;
							var fresh = tmp.querySelector('.eem-reservation-editor-meta-line');
							if (fresh) { existing.parentElement.replaceChild(fresh, existing); }
						}
					}
					eemModalClose('eem-modal-linked-event');
					eemSaveBarToast(resp.data && resp.data.message ? resp.data.message : 'Updated.', 'success');
				} else {
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Update failed.';
					if (errEl) { errEl.textContent = msg; errEl.hidden = false; }
				}
			}).catch(function () {
				if (errEl) { errEl.textContent = 'Could not reach the server.'; errEl.hidden = false; }
			});
		}
	});

	/* Typeahead input — debounced "echo what was typed" placeholder.
	   C7.B.2 ships the input/results scaffolding; real WP_Query
	   endpoint wires in C7.C alongside the per-section data layer. */
	var eemTypeaheadTimer = null;
	document.addEventListener('input', function (ev) {
		var t = ev.target;
		if (!t || !t.matches || !t.matches('.eem-event-typeahead-input')) return;
		clearTimeout(eemTypeaheadTimer);
		var input = t;
		var sourceKey = input.getAttribute('data-eem-typeahead');
		var resultsEl = document.querySelector('[data-eem-typeahead-results="' + sourceKey + '"]');
		if (!resultsEl) return;
		var q = input.value.trim();
		if (q.length < 2) { resultsEl.hidden = true; resultsEl.innerHTML = ''; return; }
		eemTypeaheadTimer = setTimeout(function () {
			resultsEl.hidden = false;
			resultsEl.innerHTML = '<div class="eem-event-typeahead-empty">Search endpoint wires in C7.C. Click a placeholder result to populate the selected event id.</div>' +
				'<a class="eem-event-typeahead-result" href="#" data-eem-event-id="999">' +
				'<div class="eem-event-typeahead-result-title">Placeholder event (id 999)</div>' +
				'<div class="eem-event-typeahead-result-meta">Matches query: ' + q.replace(/[<>]/g, '') + '</div></a>';
		}, 250);
	});

	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		var result = t.closest('.eem-event-typeahead-result');
		if (!result) return;
		ev.preventDefault();
		var eventId = result.getAttribute('data-eem-event-id') || '';
		var modal = document.getElementById('eem-modal-linked-event');
		if (!modal) return;
		var hidden = modal.querySelector('.eem-modal-linked-event__selected-id');
		if (hidden) hidden.value = eventId;
		var title = result.querySelector('.eem-event-typeahead-result-title');
		var sourceKey = modal.getAttribute('data-eem-current-source') || 'native';
		var input = modal.querySelector('.eem-event-typeahead-input[data-eem-typeahead="' + sourceKey + '"]');
		if (input && title) input.value = title.textContent.trim();
		var resultsEl = modal.querySelector('[data-eem-typeahead-results="' + sourceKey + '"]');
		if (resultsEl) { resultsEl.hidden = true; resultsEl.innerHTML = ''; }
	});

	/* ─────────────────────────────────────────────────────────────
	   DS-1.B — Dashboard range filter dispatch
	   Full-page reload (no AJAX) on <select> change. Mirrors the
	   Reports filter pattern; keeps the JS surface minimal.
	   ───────────────────────────────────────────────────────────── */
	document.addEventListener('change', function (ev) {
		var t = ev.target;
		if (!t || !t.matches || !t.matches('[data-eem-action="dashboard-range-change"]')) {
			return;
		}
		var url = new URL(window.location.href);
		url.searchParams.set('range', t.value);
		window.location.href = url.toString();
	});

	/* ═════════════════════════════════════════════════════════════
	   C7.X.2 — Mockup-canonical Reservation Editor JS handlers.
	   Build-to-Mockup-Period port. Adds the mockup's canonical
	   behavior shapes alongside existing C7.B/C handlers (old
	   handlers stay until final commit retires them with the
	   smoke assertions that reference them).

	   New handlers (all sourced from mockup script block lines
	   1224–1381):
	     - applyControls(ctrl): ID-based data-controls visibility
	     - toggleStay(btn): stay-type click + at-least-one validation
	     - flashStayHint(group): red error text fade-in
	     - toggleFeeMode(btn): mutex pill triplet + applyFeeModeVisibility
	     - applyFeeModeVisibility(): show #row-fee-flat OR #row-fee-pct
	     - lockChevronWhenDisabled: chevron click bail-out on disabled
	     - enableLabelTextFlip: JS-side "Enabled" ↔ "Disabled" update
	     - cancellation override state handlers
	     - rail-card button handlers (Preview / Save Draft / Update /
	       Trash / Unlink)
	     - zone color preset picker
	   ═════════════════════════════════════════════════════════════ */

	/* Mockup applyControls() — ID-based. Reads data-controls (space-
	   separated row IDs) from controller, hides/reveals each listed row
	   based on controller's on state.
	   C7.X.9 — controller on-state is read from the canonical mockup
	   classes ONLY (`eem-toggle--on` for toggle-label-row wrappers and
	   inner toggles, `eem-stay-type-btn--active` for stay-type pills).
	   The earlier read also accepted bare `on` / `active` tokens, but
	   those duplicates were never toggled off by the click handlers, so
	   any controller that ever shipped them was stuck reading
	   `on=true` forever. Partials no longer emit the duplicates; this
	   read is the source-of-truth for state.
	   C7.X.9 — also toggles the `eem-row--hidden` class (CSS rule has
	   `display:none !important`) rather than just inline style. Rows
	   that PHP renders initially hidden carry `eem-row--hidden`, and
	   inline `style.display = ''` alone could never reveal them
	   because the class wins specificity. */
	function eemApplyControlsById(controller) {
		if (!controller) return;
		var ids = (controller.getAttribute('data-controls') || '').trim();
		if (!ids) return;
		var on = controller.classList.contains('eem-toggle--on') || controller.classList.contains('eem-stay-type-btn--active');
		if (!on) {
			// Toggle-label-row wrappers carry data-controls but the
			// canonical state class lives on the inner .eem-toggle
			// (C7.X.9 — wrapper duplicates stripped). Fall back to
			// inspecting the inner toggle so the wrapper can still be
			// the data-controls source.
			var inner = controller.querySelector(':scope > .eem-toggle');
			if (inner && inner.classList.contains('eem-toggle--on')) on = true;
		}
		ids.split(/\s+/).forEach(function (id) {
			var row = document.getElementById(id);
			if (!row) return;
			row.classList.toggle('eem-row--hidden', !on);
			// Clear any stale inline style.display from earlier
			// pre-C7.X.9 toggle clicks; class is now the sole gate.
			row.style.display = '';
		});
	}

	/* Stay-type button click with at-least-one validation.
	   Block deactivating the LAST active stay-type in a group;
	   flash red hint instead. */
	function eemFlashStayHint(group) {
		if (!group) return;
		var parent = group.parentElement;
		if (!parent) return;
		var hint = parent.querySelector('.eem-stay-hint');
		if (!hint) {
			hint = document.createElement('span');
			hint.className = 'eem-stay-hint eem-field-hint';
			hint.textContent = 'At least one stay type must remain enabled.';
			parent.appendChild(hint);
		}
		hint.classList.add('eem-stay-hint--show');
		clearTimeout(hint._eemT);
		hint._eemT = setTimeout(function () { hint.classList.remove('eem-stay-hint--show'); }, 2200);
	}

	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		/* Stay-type button — mockup line 152 .stay-type-btn */
		var stayBtn = t.closest('[data-eem-action="reservation-editor-toggle-stay-type"]');
		if (stayBtn) {
			ev.preventDefault();
			var group = stayBtn.closest('.eem-stay-types');
			var turningOn = !stayBtn.classList.contains('eem-stay-type-btn--active');
			if (!turningOn && group) {
				var active = group.querySelectorAll('.eem-stay-type-btn--active');
				if (active.length <= 1) {
					eemFlashStayHint(group);
					return;
				}
			}
			stayBtn.classList.toggle('eem-stay-type-btn--active', turningOn);
			// Inner toggle indicator
			var inner = stayBtn.querySelector('.eem-toggle');
			if (inner) {
				inner.classList.toggle('eem-toggle--on', turningOn);
				inner.classList.toggle('eem-toggle--off', !turningOn);
			}
			// Hidden mirror for persistence
			var mirror = stayBtn.querySelector('input[type="hidden"][data-eem-stay-type-mirror]');
			if (mirror) mirror.value = turningOn ? '1' : '0';
			eemApplyControlsById(stayBtn);
			return;
		}

		/* Toggle-label-row click — mockup line 147 .toggle-label-row.
		   data-controls listed on the WRAPPER, applied via the inner
		   toggle's on/off state. */
		var tlr = t.closest('[data-eem-action="reservation-editor-toggle-switch-row"]');
		if (tlr) {
			ev.preventDefault();
			ev.stopPropagation();
			var ti = tlr.querySelector('.eem-toggle');
			if (!ti) return;
			var turningOnT = ti.classList.contains('eem-toggle--off');
			ti.classList.toggle('eem-toggle--on', turningOnT);
			ti.classList.toggle('eem-toggle--off', !turningOnT);
			var hi = tlr.querySelector('input[type="hidden"][data-eem-subsection-enabled]');
			if (hi) hi.value = turningOnT ? '1' : '0';
			eemApplyControlsById(tlr);
			return;
		}
	});

	/* Fee-mode pill triplet — mockup line 158 .fee-modes / .fee-mode-btn */
	function eemApplyFeeModeVisibility() {
		var active = document.querySelector('.eem-fee-mode-btn.eem-fee-mode-btn--active');
		var mode = active ? active.getAttribute('data-eem-fee-mode') : 'none';
		var flat = document.getElementById('row-fee-flat');
		var pct  = document.getElementById('row-fee-pct');
		if (flat) flat.style.display = ('flat' === mode) ? '' : 'none';
		if (pct)  pct.style.display  = (('pct' === mode) || ('percentage' === mode)) ? '' : 'none';
	}

	/* Mockup toggleEnabled — updates enable-label text on click.
	   C7.C.1.4.A computed initial text PHP-side but didn't flip on
	   click; this closes that drift (J4 in audit). Also locks
	   chevron expansion of disabled sections (E7 / J3 in audit). */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var enable2 = t.closest('[data-eem-action="reservation-editor-toggle-enabled"]');
		if (enable2) {
			// Update enable-label text per mockup line 1268
			var key2 = enable2.dataset.eemSection;
			if (key2) {
				var lbl = enable2.querySelector('.eem-enable-toggle__label[data-eem-enable-label="' + key2 + '"]');
				if (lbl) {
					var toggleOn = enable2.querySelector('.eem-toggle').classList.contains('eem-toggle--on');
					// existing handler ran FIRST and already flipped the class; toggleOn now reflects POST-click state
					lbl.textContent = toggleOn ? 'Enabled' : 'Disabled';
				}
			}
		}

		// C7.X.9 — chevron-lock handler removed. Per the canonical UX
		// spec, users CAN chevron-expand a disabled section to peek at
		// the filled data (read-only-ish state with .eem-section-body
		// --disabled striped overlay applied at PHP render time). The
		// previous lock handler also had a wrong-target body query
		// (`collapse2.parentElement.parentElement.querySelector` walked
		// past the card to the container, grabbing the FIRST section's
		// body instead of the clicked section's body), which caused
		// cross-section --hidden state to get stuck. Both problems
		// disappear with the handler removed.
	});

	/* ── Fee-mode pill click ── */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		var fb = t.closest('[data-eem-action="reservation-editor-fee-mode"]');
		if (!fb) return;
		ev.preventDefault();
		var grp = fb.closest('.eem-fee-modes');
		if (grp) grp.querySelectorAll('.eem-fee-mode-btn').forEach(function (b) {
			b.classList.toggle('eem-fee-mode-btn--active', b === fb);
		});
		var mode = fb.getAttribute('data-eem-fee-mode');
		var section = fb.closest('.eem-reservation-editor-section');
		var mirror2 = section ? section.querySelector('[data-eem-fee-mode-mirror]') : null;
		if (mirror2) mirror2.value = mode;
		eemApplyFeeModeVisibility();
	});

	/* ── Cancellation Policy override state ── */
	function eemUpdateCancellationOverrideState() {
		var section = document.getElementById('card-cancellation');
		var textarea = document.getElementById('en_cancellation_policy_override');
		var statusHint = document.getElementById('eem-cancellation-status-hint');
		if (!section || !textarea || !statusHint) return;
		var hasOverride = textarea.value.trim().length > 0;
		section.classList.toggle('eem-cancellation-overridden', hasOverride);
		if (hasOverride) {
			statusHint.innerHTML = '<strong style="color:var(--eem-electric)">Using this reservation\'s custom policy</strong> (event default is overridden)';
		} else {
			statusHint.textContent = 'Currently using event default. Type to customize.';
		}
	}
	window.eemRestoreCancellationDefault = function () {
		var textarea = document.getElementById('en_cancellation_policy_override');
		if (!textarea) return;
		if (textarea.value.trim().length > 0) {
			if (!confirm('Discard this reservation\'s custom cancellation policy and restore the event default?')) return;
		}
		textarea.value = '';
		eemUpdateCancellationOverrideState();
		textarea.focus();
	};

	/* Zone color picker removed — colors are derived from the auto-palette
	   (getZoneColor()) by zone index. Swatches are display-only. */

	/* ── Zone row add/remove ── */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var zoneAdd = t.closest('[data-eem-action="reservation-editor-zone-add"]');
		if (zoneAdd) {
			ev.preventDefault();
			var list = document.getElementById('eem-lot-zones-list');
			var tmpl = document.getElementById('eem-lot-zone-row-template');
			if (!list || !tmpl) return;
			var clone = tmpl.content ? tmpl.content.cloneNode(true) : null;
			if (!clone) {
				var w = document.createElement('div');
				w.innerHTML = tmpl.innerHTML;
				clone = w.firstElementChild;
			}
			var nextIdx = list.children.length;
			(function rewrite(node) {
				if (node.nodeType === 1) {
					Array.prototype.slice.call(node.attributes || []).forEach(function (a) {
						if (a.value && a.value.indexOf('__index__') !== -1) {
							node.setAttribute(a.name, a.value.split('__index__').join(String(nextIdx)));
						}
					});
					Array.prototype.slice.call(node.childNodes).forEach(rewrite);
				}
			})(clone);
			list.appendChild(clone);
			return;
		}

		var zoneDel = t.closest('[data-eem-action="reservation-editor-zone-delete"]');
		if (zoneDel) {
			ev.preventDefault();
			var row = zoneDel.closest('.eem-zone-row');
			if (row && row.parentNode) row.parentNode.removeChild(row);
			return;
		}
	});

	/* ── Agreement upload + remove (C7.X.15 Issue 2B) ──────────────
	   `Upload` / `Replace` button opens the WordPress Media Library
	   (wp.media), restricts MIME to PDF, persists the chosen
	   attachment id to the `_en_venue_agreement_file_id` hidden input,
	   and updates the file-row display in place (filename + View
	   link + Replace/Delete affordances) without a page reload.
	   `Remove file` clears the hidden input + collapses the row back
	   to the empty state.

	   Latent bug shipped pre-C7.X.15 — the button rendered but no
	   handler existed; clicks silently no-op'd. Surfaced by Whitney's
	   C7.X.15 button audit. */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var uploadBtn = t.closest('[data-eem-action="reservation-editor-agreement-upload"]');
		if (uploadBtn) {
			ev.preventDefault();
			if (!(window.wp && wp.media)) {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Media Library not loaded.', { variant: 'error' });
				return;
			}
			var frame = wp.media({
				title: 'Choose Agreement PDF',
				button: { text: 'Use this PDF' },
				library: { type: 'application/pdf' },
				multiple: false
			});
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				if (!att || !att.id) return;
				// Persist attachment id to the hidden input.
				var hidden = document.getElementById('en_venue_agreement_file_id');
				if (hidden) hidden.value = String(att.id);
				// Update the file-row display in place.
				var fileRow  = uploadBtn.closest('.eem-file-row');
				if (!fileRow) return;
				var fileName = fileRow.querySelector('[data-eem-file-name]');
				if (fileName) {
					fileName.textContent = att.filename || att.title || 'agreement.pdf';
					fileName.classList.remove('eem-file-name-empty');
				}
				// Insert/refresh the View link.
				var viewLink = fileRow.querySelector('.eem-view-link');
				if (!viewLink) {
					viewLink = document.createElement('a');
					viewLink.className = 'eem-view-link';
					viewLink.target = '_blank';
					viewLink.rel = 'noopener noreferrer';
					viewLink.textContent = 'View';
					fileRow.insertBefore( viewLink, uploadBtn );
				}
				viewLink.href = att.url || '#';
				// Swap button label Upload → Replace.
				uploadBtn.textContent = 'Replace';
				// Insert the Remove button if not already present.
				if ( ! fileRow.querySelector('[data-eem-action="reservation-editor-agreement-remove"]') ) {
					var rm = document.createElement('button');
					rm.type = 'button';
					rm.className = 'eem-btn-file-del';
					rm.setAttribute('aria-label', 'Remove file');
					rm.setAttribute('data-eem-action', 'reservation-editor-agreement-remove');
					rm.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>';
					uploadBtn.insertAdjacentElement('afterend', rm);
				}
			});
			frame.open();
			return;
		}

		var removeBtn = t.closest('[data-eem-action="reservation-editor-agreement-remove"]');
		if (removeBtn) {
			ev.preventDefault();
			var hidden2 = document.getElementById('en_venue_agreement_file_id');
			if (hidden2) hidden2.value = '0';
			var fileRow2 = removeBtn.closest('.eem-file-row');
			if (!fileRow2) return;
			var fileName2 = fileRow2.querySelector('[data-eem-file-name]');
			if (fileName2) {
				fileName2.textContent = 'No agreement file uploaded yet';
				fileName2.classList.add('eem-file-name-empty');
			}
			var view2 = fileRow2.querySelector('.eem-view-link');
			if (view2 && view2.parentNode) view2.parentNode.removeChild(view2);
			var upBtn = fileRow2.querySelector('[data-eem-action="reservation-editor-agreement-upload"]');
			if (upBtn) upBtn.textContent = 'Upload';
			if (removeBtn.parentNode) removeBtn.parentNode.removeChild(removeBtn);
			return;
		}

		/* ── Venue Map upload + remove (2.3.74) ───────────────────────
		   Same flow as the Agreement uploader, but the venue map may be a
		   PDF OR an image (no MIME restriction). Persists to the
		   en_venue_map_image_id hidden input. */
		var vmUploadBtn = t.closest('[data-eem-action="reservation-editor-venuemap-upload"]');
		if (vmUploadBtn) {
			ev.preventDefault();
			if (!(window.wp && wp.media)) {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Media Library not loaded.', { variant: 'error' });
				return;
			}
			var vmFrame = wp.media({ title: 'Choose Venue Map (PDF or image)', button: { text: 'Use this file' }, multiple: false });
			vmFrame.on('select', function () {
				var att = vmFrame.state().get('selection').first().toJSON();
				if (!att || !att.id) return;
				var vmHidden = document.getElementById('en_venue_map_image_id');
				if (vmHidden) vmHidden.value = String(att.id);
				var vmRow = vmUploadBtn.closest('.eem-file-row');
				if (!vmRow) return;
				var vmName = vmRow.querySelector('[data-eem-file-name]');
				if (vmName) { vmName.textContent = att.filename || att.title || 'venue-map'; vmName.classList.remove('eem-file-name-empty'); }
				var vmView = vmRow.querySelector('.eem-view-link');
				if (!vmView) {
					vmView = document.createElement('a');
					vmView.className = 'eem-view-link';
					vmView.target = '_blank';
					vmView.rel = 'noopener noreferrer';
					vmView.textContent = 'View';
					vmRow.insertBefore(vmView, vmUploadBtn);
				}
				vmView.href = att.url || '#';
				vmUploadBtn.textContent = 'Replace';
				if (!vmRow.querySelector('[data-eem-action="reservation-editor-venuemap-remove"]')) {
					var vmRm = document.createElement('button');
					vmRm.type = 'button';
					vmRm.className = 'eem-btn-file-del';
					vmRm.setAttribute('aria-label', 'Remove file');
					vmRm.setAttribute('data-eem-action', 'reservation-editor-venuemap-remove');
					vmRm.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>';
					vmUploadBtn.insertAdjacentElement('afterend', vmRm);
				}
			});
			vmFrame.open();
			return;
		}
		var vmRemoveBtn = t.closest('[data-eem-action="reservation-editor-venuemap-remove"]');
		if (vmRemoveBtn) {
			ev.preventDefault();
			var vmHidden2 = document.getElementById('en_venue_map_image_id');
			if (vmHidden2) vmHidden2.value = '0';
			var vmRow2 = vmRemoveBtn.closest('.eem-file-row');
			if (!vmRow2) return;
			var vmName2 = vmRow2.querySelector('[data-eem-file-name]');
			if (vmName2) { vmName2.textContent = 'No venue map uploaded yet'; vmName2.classList.add('eem-file-name-empty'); }
			var vmView2 = vmRow2.querySelector('.eem-view-link');
			if (vmView2 && vmView2.parentNode) vmView2.parentNode.removeChild(vmView2);
			var vmUp = vmRow2.querySelector('[data-eem-action="reservation-editor-venuemap-upload"]');
			if (vmUp) vmUp.textContent = 'Upload';
			if (vmRemoveBtn.parentNode) vmRemoveBtn.parentNode.removeChild(vmRemoveBtn);
			return;
		}
	});

	/* ── Stall Map upload + remove (2.3.23 UX polish) ─────────────────────────
	   `Upload` button opens the WP Media Library (no type restriction — stall
	   maps can be PDF or image).  Writes the attachment id to the hidden input
	   `#eem-stall-map-id`, updates the `#eem-stall-map-name` span, shows the
	   View link and the remove button.  `Remove` clears the hidden input and
	   collapses the row back to the empty state.
	   Parallel fix to the agreement handler above — the button rendered but no
	   JS handler was wired (same latent bug). */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var stallMapUploadBtn = t.closest('[data-eem-action="stall-map-upload"]');
		if (stallMapUploadBtn) {
			ev.preventDefault();
			if (!(window.wp && wp.media)) {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Media Library not loaded.', { variant: 'error' });
				return;
			}
			var stallMapFrame = wp.media({
				title: 'Choose Stall Map',
				button: { text: 'Use this file' },
				multiple: false
			});
			stallMapFrame.on('select', function () {
				var att = stallMapFrame.state().get('selection').first().toJSON();
				if (!att || !att.id) return;
				var hidden = document.getElementById('eem-stall-map-id');
				if (hidden) hidden.value = String(att.id);
				var nameSpan = document.getElementById('eem-stall-map-name');
				if (nameSpan) nameSpan.textContent = att.filename || att.title || '';
				var fileRow = stallMapUploadBtn.closest('.eem-file-row');
				if (!fileRow) return;
				// Insert/refresh the View link.
				var viewLink = fileRow.querySelector('.eem-view-link');
				if (!viewLink) {
					viewLink = document.createElement('a');
					viewLink.className = 'eem-view-link';
					viewLink.target = '_blank';
					viewLink.rel = 'noopener noreferrer';
					viewLink.textContent = 'View file';
					fileRow.insertBefore(viewLink, stallMapUploadBtn);
				}
				viewLink.href = att.url || '#';
				// Show remove button.
				var rmBtn = fileRow.querySelector('[data-eem-action="stall-map-remove"]');
				if (rmBtn) rmBtn.style.display = '';
			});
			stallMapFrame.open();
			return;
		}

		var stallMapRemoveBtn = t.closest('[data-eem-action="stall-map-remove"]');
		if (stallMapRemoveBtn) {
			ev.preventDefault();
			var hidden2 = document.getElementById('eem-stall-map-id');
			if (hidden2) hidden2.value = '0';
			var nameSpan2 = document.getElementById('eem-stall-map-name');
			if (nameSpan2) nameSpan2.textContent = '';
			var fileRow2 = stallMapRemoveBtn.closest('.eem-file-row');
			if (fileRow2) {
				var view2 = fileRow2.querySelector('.eem-view-link');
				if (view2 && view2.parentNode) view2.parentNode.removeChild(view2);
			}
			stallMapRemoveBtn.style.display = 'none';
			return;
		}
	});

	/* ── RV Lot Map upload + remove (2.3.23 UX polish) ─────────────────────────
	   New field added to the RV section in 2.3.23.  Same pattern as the Stall
	   Map handler above.  Writes to `#eem-rv-lot-map-id` / `#eem-rv-lot-map-name`. */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var rvMapUploadBtn = t.closest('[data-eem-action="rv-lot-map-upload"]');
		if (rvMapUploadBtn) {
			ev.preventDefault();
			if (!(window.wp && wp.media)) {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Media Library not loaded.', { variant: 'error' });
				return;
			}
			var rvMapFrame = wp.media({
				title: 'Choose RV Lot Map',
				button: { text: 'Use this file' },
				multiple: false
			});
			rvMapFrame.on('select', function () {
				var att = rvMapFrame.state().get('selection').first().toJSON();
				if (!att || !att.id) return;
				var hidden = document.getElementById('eem-rv-lot-map-id');
				if (hidden) hidden.value = String(att.id);
				var nameSpan = document.getElementById('eem-rv-lot-map-name');
				if (nameSpan) nameSpan.textContent = att.filename || att.title || '';
				var fileRow = rvMapUploadBtn.closest('.eem-file-row');
				if (!fileRow) return;
				// Insert/refresh the View link.
				var viewLink = fileRow.querySelector('.eem-view-link');
				if (!viewLink) {
					viewLink = document.createElement('a');
					viewLink.className = 'eem-view-link';
					viewLink.target = '_blank';
					viewLink.rel = 'noopener noreferrer';
					viewLink.textContent = 'View file';
					fileRow.insertBefore(viewLink, rvMapUploadBtn);
				}
				viewLink.href = att.url || '#';
				// Show remove button.
				var rmBtn = fileRow.querySelector('[data-eem-action="rv-lot-map-remove"]');
				if (rmBtn) rmBtn.style.display = '';
			});
			rvMapFrame.open();
			return;
		}

		var rvMapRemoveBtn = t.closest('[data-eem-action="rv-lot-map-remove"]');
		if (rvMapRemoveBtn) {
			ev.preventDefault();
			var hidden2 = document.getElementById('eem-rv-lot-map-id');
			if (hidden2) hidden2.value = '0';
			var nameSpan2 = document.getElementById('eem-rv-lot-map-name');
			if (nameSpan2) nameSpan2.textContent = '';
			var fileRow2 = rvMapRemoveBtn.closest('.eem-file-row');
			if (fileRow2) {
				var view2 = fileRow2.querySelector('.eem-view-link');
				if (view2 && view2.parentNode) view2.parentNode.removeChild(view2);
			}
			rvMapRemoveBtn.style.display = 'none';
			return;
		}
	});

	/* ── Stall Chart Detail — delegated handlers (2.3.24 port) ──────────────
	   All stall chart UI interactions wire through data-eem-action delegation.
	   No inline onclick= used on the stall chart page. */

	/* ── Stall Charts LIST PAGE — status tab filter (2.3.25) ── */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var filterTab = t.closest('[data-eem-action="sc-filter-tab"]');
		if (filterTab) {
			ev.preventDefault();
			var status = filterTab.getAttribute('data-status') || 'all';
			// Update active tab
			document.querySelectorAll('[data-eem-action="sc-filter-tab"]').forEach(function (tab) {
				tab.classList.toggle('active', tab === filterTab);
			});
			// Filter table rows + mobile cards
			var rows  = document.querySelectorAll('#eem-sc-list-tbody tr[data-sc-status]');
			var cards = document.querySelectorAll('.eem-sc-mobile-card[data-sc-status]');
			rows.forEach(function (row) {
				row.classList.toggle('eem-sc-hidden', status !== 'all' && row.getAttribute('data-sc-status') !== status);
			});
			cards.forEach(function (card) {
				card.classList.toggle('eem-sc-hidden', status !== 'all' && card.getAttribute('data-sc-status') !== status);
			});
		}
	});

	/* ── Stall Charts LIST PAGE — search filtering (2.3.25) ── */
	document.addEventListener('input', function (ev) {
		var t = ev.target;
		if (!t || t.getAttribute('data-eem-input-action') !== 'sc-list-search') return;
		var q = (t.value || '').toLowerCase().trim();
		var rows  = document.querySelectorAll('#eem-sc-list-tbody tr[data-sc-title]');
		var cards = document.querySelectorAll('.eem-sc-mobile-card[data-sc-title]');
		rows.forEach(function (row) {
			row.classList.toggle('eem-sc-hidden', q.length > 0 && row.getAttribute('data-sc-title').indexOf(q) === -1);
		});
		cards.forEach(function (card) {
			card.classList.toggle('eem-sc-hidden', q.length > 0 && card.getAttribute('data-sc-title').indexOf(q) === -1);
		});

		// Show the "No reservations match your filters" state when the search
		// hides every row (2.3.50).
		var anyVisible = false;
		rows.forEach(function (row) {
			if (!row.classList.contains('eem-sc-hidden')) anyVisible = true;
		});
		var noMatch = document.getElementById('eem-sc-no-match');
		if (noMatch) noMatch.style.display = (q.length > 0 && !anyVisible) ? '' : 'none';
	});

	/* ── Stall Chart DETAIL: centralised inv/tab state ── */
	function eemScApplyState(inv, tab) {
		// 1. Inventory toggle buttons active state.
		document.querySelectorAll('[data-eem-action="sc-inv-switch"]').forEach(function (btn) {
			btn.classList.toggle('active', btn.getAttribute('data-inv') === inv);
		});

		// 2. View tab buttons active state.
		document.querySelectorAll('[data-eem-action="stall-chart-switch-view"]').forEach(function (btn) {
			var v = btn.getAttribute('data-view');
			btn.classList.toggle('active', v === tab);
			btn.setAttribute('aria-selected', v === tab ? 'true' : 'false');
		});

		// 3. Show / hide main panels.
		var locPanel  = document.getElementById('eem-stall-chart-panel-location');
		var custPanel = document.getElementById('eem-stall-chart-panel-customer');
		if (locPanel)  locPanel.style.display  = tab === 'location' ? '' : 'none';
		if (custPanel) custPanel.style.display = tab === 'customer'  ? '' : 'none';

		// 4. Show / hide stall section and RV section within the location panel.
		var stallSection = document.getElementById('eem-sc-loc-stalls');
		var rvSection    = document.getElementById('eem-sc-loc-rv');
		if (stallSection) stallSection.style.display = inv === 'rv'     ? 'none' : '';
		if (rvSection)    rvSection.style.display    = inv === 'stalls' ? 'none' : '';

		// 5. Barn tabs: only in stalls mode. Zone tabs: only in rv mode.
		var barnTabsWrap = document.getElementById('eem-sc-barn-tabs');
		var zoneTabsWrap = document.getElementById('eem-sc-zone-tabs');
		if (barnTabsWrap) {
			var hasBarnOptions = barnTabsWrap.querySelectorAll('button').length > 1;
			barnTabsWrap.style.display = (inv === 'stalls' && hasBarnOptions) ? '' : 'none';
		}
		if (zoneTabsWrap) {
			var hasZoneOptions = zoneTabsWrap.querySelectorAll('button').length > 1;
			zoneTabsWrap.style.display = (inv === 'rv' && hasZoneOptions) ? '' : 'none';
		}

		// 6. Reset barn + zone filters to "all" when the inventory mode changes.
		document.querySelectorAll('.eem-stall-chart-barn-tab').forEach(function (b) {
			b.classList.toggle('active', b.getAttribute('data-barn') === 'all');
		});
		document.querySelectorAll('.eem-stall-chart-zone-tab').forEach(function (b) {
			b.classList.toggle('active', b.getAttribute('data-zone') === 'all');
		});
		document.querySelectorAll('#eem-sc-loc-stalls [data-barn]').forEach(function (row) {
			if (!row.classList.contains('eem-stall-chart-barn-tab') && !row.classList.contains('eem-stall-chart-zone-tab')) {
				row.style.display = '';
			}
		});
		document.querySelectorAll('#eem-sc-loc-rv [data-zone]').forEach(function (row) {
			if (!row.classList.contains('eem-stall-chart-barn-tab') && !row.classList.contains('eem-stall-chart-zone-tab')) {
				row.style.display = '';
			}
		});

		// 7. Filter customer rows by inventory type.
		var custRows = document.querySelectorAll('#eem-stall-chart-panel-customer [data-has-stalls]');
		custRows.forEach(function (row) {
			var hasStalls = row.getAttribute('data-has-stalls') === '1';
			var hasRv     = row.getAttribute('data-has-rv')     === '1';
			var show = inv === 'all' ||
				(inv === 'stalls' && hasStalls) ||
				(inv === 'rv'     && hasRv);
			row.style.display = show ? '' : 'none';
		});
		var custEmptyNote = document.querySelector('#eem-stall-chart-panel-customer .eem-stall-chart-empty-note');
		if (custEmptyNote && custRows.length > 0) {
			var anyVisible = Array.prototype.slice.call(custRows).some(function (r) {
				return r.style.display !== 'none';
			});
			custEmptyNote.hidden = anyVisible;
		}

		// 8. Persist state in URL without page reload.
		if (window.history && window.history.replaceState) {
			try {
				var url = new URL(window.location.href);
				url.searchParams.set('inv', inv);
				url.searchParams.set('tab', tab);
				window.history.replaceState(null, '', url.toString());
			} catch (e) { /* swallow in ancient browsers */ }
		}

		// 9. Store current state.
		window._eemScInv = inv;
		window._eemScTab = tab;
	}

	/**
	 * POST the auto-assign request and swap the dynamic chart region in place.
	 *
	 * @param {string} orderKey   Empty = reservation-wide; otherwise scope to one order.
	 * @param {Element} triggerBtn Button that initiated the request (disabled during flight).
	 */
	function eemRunAutoAssign(orderKey, triggerBtn) {
		var cfg = window.eemStallChart || {};
		var body = new URLSearchParams();
		body.set('action', 'eem_auto_assign');
		body.set('_wpnonce', cfg.autoAssignNonce || '');
		body.set('reservation_id', String(cfg.reservationId || ''));
		if (orderKey) body.set('order_key', orderKey);
		body.set('inv', window._eemScInv || 'all');
		body.set('tab', window._eemScTab || 'location');
		if (triggerBtn) triggerBtn.disabled = true;
		fetch((cfg.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php'), {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (resp) {
			if (triggerBtn) triggerBtn.disabled = false;
			var data = (resp && resp.data) || {};
			// Swap the chart region whenever the server returned fresh markup
			// (both success and the "no inventory" 409 path carry html).
			if (data.html) {
				var region = document.getElementById('eem-stall-chart-dynamic');
				if (region) {
					region.innerHTML = data.html;
					eemScApplyState(window._eemScInv || 'all', window._eemScTab || 'location');
				}
			}
			if (window.EEM && window.EEM.showSaveToast) {
				var variant = (resp && resp.success && !data.has_shortfall) ? 'success' : 'error';
				window.EEM.showSaveToast(data.message || (resp && resp.success ? 'Assignments generated.' : 'Auto-assign failed.'), { variant: variant, sub: '' });
			}
		}).catch(function () {
			if (triggerBtn) triggerBtn.disabled = false;
			if (window.EEM && window.EEM.showSaveToast) {
				window.EEM.showSaveToast('Could not reach the server.', { variant: 'error', sub: '' });
			}
		});
	}

	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		// Auto-Assign All (action-bar "Generate Assignments" + issues "Auto-Assign All")
		var autoAll = t.closest('[data-eem-action="stall-chart-auto-assign-all"]');
		if (autoAll) {
			ev.preventDefault();
			eemRunAutoAssign('', autoAll);
			return;
		}

		// Auto-Assign a single order (issues-row button)
		var autoOrder = t.closest('[data-eem-action="stall-chart-auto-assign-order"]');
		if (autoOrder) {
			ev.preventDefault();
			eemRunAutoAssign(autoOrder.getAttribute('data-order-key') || '', autoOrder);
			return;
		}

		// Inventory toggle (All / Stalls / RV)
		var invBtn = t.closest('[data-eem-action="sc-inv-switch"]');
		if (invBtn) {
			ev.preventDefault();
			eemScApplyState(invBtn.getAttribute('data-inv') || 'all', window._eemScTab || 'location');
			return;
		}

		// View tab switching (By Location / By Customer)
		var viewTab = t.closest('[data-eem-action="stall-chart-switch-view"]');
		if (viewTab) {
			ev.preventDefault();
			eemScApplyState(window._eemScInv || 'all', viewTab.getAttribute('data-view') || 'location');
			return;
		}

		// Barn filter tabs
		var barnTab = t.closest('[data-eem-action="stall-chart-filter-barn"]');
		if (barnTab) {
			ev.preventDefault();
			var barn = barnTab.getAttribute('data-barn');
			document.querySelectorAll('.eem-stall-chart-barn-tab').forEach(function (b) { b.classList.remove('active'); });
			barnTab.classList.add('active');
			document.querySelectorAll('[data-barn]').forEach(function (row) {
				if (row.classList.contains('eem-stall-chart-barn-tab')) return; // skip tab buttons
				row.style.display = (barn === 'all' || row.getAttribute('data-barn') === barn) ? '' : 'none';
			});
			return;
		}

		// Zone filter tabs (RV lots)
		var zoneTab = t.closest('[data-eem-action="stall-chart-filter-zone"]');
		if (zoneTab) {
			ev.preventDefault();
			var zone = zoneTab.getAttribute('data-zone');
			document.querySelectorAll('.eem-stall-chart-zone-tab').forEach(function (b) { b.classList.remove('active'); });
			zoneTab.classList.add('active');
			document.querySelectorAll('#eem-sc-loc-rv [data-zone]').forEach(function (row) {
				if (row.classList.contains('eem-stall-chart-barn-tab') || row.classList.contains('eem-stall-chart-zone-tab')) return;
				row.style.display = (zone === 'all' || row.getAttribute('data-zone') === zone) ? '' : 'none';
			});
			return;
		}

		// Cell pill click — show popover (canonical action: stall-pill-click; legacy alias: stall-chart-pill-click)
		var pill = t.closest('[data-eem-action="stall-pill-click"]') || t.closest('[data-eem-action="stall-chart-pill-click"]');
		if (pill) {
			ev.stopPropagation();
			var menu = document.getElementById('eem-stall-chart-cell-menu');
			if (!menu) return;
			if (menu.classList.contains('open') && window._eemActivePill === pill) {
				menu.classList.remove('open');
				window._eemActivePill = null;
				return;
			}
			var customer = pill.getAttribute('data-customer-name') || pill.getAttribute('data-customer') || '—';
			var orderKey = pill.getAttribute('data-order-id') || pill.getAttribute('data-order-key') || '';
			var orderNum = pill.getAttribute('data-order-number') || '';
			var special  = pill.getAttribute('data-special-requests') || '';
			var groupNm  = pill.getAttribute('data-group-name') || '';
			var srcStall = pill.getAttribute('data-stall') || '';
			var srcDate  = pill.getAttribute('data-date') || '';
			window._scActiveOrderId   = orderKey;
			window._scActiveStall     = srcStall;
			window._scActiveDate      = srcDate;
			window._scCustomerName    = customer;
			window._scActivePillEl    = pill;
			// V1 #5: set the tack toggle button's label from the pill's state.
			window._scActiveIsTack = pill.getAttribute('data-is-tack') === '1';
			var tackLabel = document.querySelector('#eem-stall-chart-tack-btn [data-eem-tack-btn-label]');
			if (tackLabel) tackLabel.textContent = window._scActiveIsTack ? 'Unmark Tack Stall' : 'Mark as Tack Stall';
			var titleEl = document.getElementById('eem-stall-chart-menu-title');
			var subEl = document.getElementById('eem-stall-chart-menu-subtitle');
			if (titleEl) titleEl.textContent = customer;
			if (subEl) {
				var esc = function (s) {
					return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
				};
				var orderUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php').replace('admin-ajax.php', '') + 'admin.php?page=equine-event-manager-order&order_key=' + encodeURIComponent(orderKey);
				// Show the human-readable order NUMBER (#NNNNN), not the internal key.
				var html = 'Order: <a href="' + orderUrl + '" onclick="event.stopPropagation()">' + esc(orderNum || '—') + '</a>';
				if (groupNm) {
					html += '<span class="eem-stall-chart-menu-group"><strong>Group:</strong> ' + esc(groupNm) + '</span>';
				}
				if (special) {
					html += '<span class="eem-stall-chart-menu-special"><strong>Special requests:</strong> ' + esc(special) + '</span>';
				}
				subEl.innerHTML = html;
			}
			window._eemActivePill = pill;
			window._eemActiveOrderKey = orderKey;
			var rect = pill.getBoundingClientRect();
			menu.style.left = (rect.left + window.scrollX) + 'px';
			menu.style.top = (rect.bottom + window.scrollY + 6) + 'px';
			menu.classList.add('open');
			return;
		}

		// Popover: View Order button
		var viewOrderBtn = t.closest('[data-eem-action="stall-chart-view-order"]');
		if (viewOrderBtn) {
			if (window._eemActiveOrderKey) {
				var url = (window.ajaxurl || '/wp-admin/admin-ajax.php').replace('admin-ajax.php', '') + 'admin.php?page=equine-event-manager-order&order_key=' + encodeURIComponent(window._eemActiveOrderKey);
				window.open(url, '_blank');
			}
			var menuClose = document.getElementById('eem-stall-chart-cell-menu');
			if (menuClose) { menuClose.classList.remove('open'); }
			window._eemActivePill = null;
			return;
		}

		// Print view — open standalone print page in a new tab.
		// data-print-url is injected server-side with the correct reservation_id.
		var printBtn = t.closest('[data-eem-action="stall-chart-print"]');
		if (printBtn) {
			ev.preventDefault();
			var printUrl = printBtn.getAttribute('data-print-url');
			if (printUrl) {
				window.open(printUrl, '_blank', 'noopener');
			}
			return;
		}

		// View Active Order (canonical alias of stall-chart-view-order)
		var viewActive = t.closest('[data-eem-action="view-active-order"]');
		if (viewActive) {
			ev.preventDefault();
			if (window._scActiveOrderId) {
				var u = (window.ajaxurl || '/wp-admin/admin-ajax.php').replace('admin-ajax.php', '') + 'admin.php?page=equine-event-manager-order&order_key=' + encodeURIComponent(window._scActiveOrderId);
				window.open(u, '_blank');
			}
			var m3 = document.getElementById('eem-stall-chart-cell-menu');
			if (m3) m3.classList.remove('open');
			return;
		}

		// Enter destination mode
		var tackBtn = t.closest('[data-eem-action="toggle-tack-stall"]');
			if (tackBtn) {
				ev.preventDefault();
				var tCfg = window.eemStallChart || {};
				var tBody = new URLSearchParams();
				tBody.set('action', 'eem_toggle_tack_stall');
				tBody.set('_wpnonce', tCfg.moveNonce || '');
				tBody.set('order_id', window._scActiveOrderId || '');
				tBody.set('stall', window._scActiveStall || '');
				var tMenu = document.getElementById('eem-stall-chart-cell-menu');
				if (tMenu) tMenu.classList.remove('open');
				var pillEl = window._scActivePillEl;
				fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), { method: 'POST', credentials: 'same-origin', body: tBody })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (json && json.success) {
							var isTack = !!(json.data && json.data.is_tack);
							if (pillEl) {
								var key = pillEl.getAttribute('data-order-key');
								var stall = pillEl.getAttribute('data-stall');
								document.querySelectorAll('.eem-occ-pill--reserved[data-order-key="' + key + '"][data-stall="' + stall + '"]').forEach(function (p) {
									p.classList.toggle('eem-occ-pill--tack', isTack);
									p.setAttribute('data-is-tack', isTack ? '1' : '0');
									var existing = p.querySelector('.eem-occ-pill__tack-dot');
									if (isTack && !existing) {
										var dot = document.createElement('span');
										dot.className = 'eem-occ-pill__tack-dot';
										dot.setAttribute('aria-hidden', 'true');
										p.insertBefore(dot, p.querySelector('.eem-occ-chevron'));
									} else if (!isTack && existing) {
										existing.remove();
									}
								});
							}
							EEM.showSaveToast((json.data && json.data.message) || 'Updated.');
						} else {
							EEM.showSaveToast((json.data && json.data.message) || 'Could not update tack designation.', { variant: 'error', sub: '' });
						}
					})
					.catch(function () { EEM.showSaveToast('Could not reach the server.', { variant: 'error', sub: '' }); });
				return;
			}

			var moveBtn = t.closest('[data-eem-action="move-to-different-stall"]');
		if (moveBtn) {
			ev.preventDefault();
			document.body.classList.add('destination-mode');
			document.body.classList.add('eem-has-destination-banner');
			var banner = document.getElementById('eem-destination-banner');
			if (banner) banner.style.display = '';
			var nameEl = document.getElementById('eem-destination-customer-name');
			if (nameEl) nameEl.textContent = window._scCustomerName || '—';
			var m4 = document.getElementById('eem-stall-chart-cell-menu');
			if (m4) m4.classList.remove('open');
			return;
		}

		// Cancel destination mode
		var cancelDest = t.closest('[data-eem-action="cancel-destination-mode"]');
		if (cancelDest) {
			ev.preventDefault();
			document.body.classList.remove('destination-mode');
			document.body.classList.remove('eem-has-destination-banner');
			var banner2 = document.getElementById('eem-destination-banner');
			if (banner2) banner2.style.display = 'none';
			window._scDestStall = null;
			window._scDestDate  = null;
			return;
		}

		// Click an Available pill while in destination mode → open scope modal
		var availPill = t.closest('[data-eem-action="stall-available-click"]');
		if (availPill && document.body.classList.contains('destination-mode')) {
			ev.preventDefault();
			window._scDestStall = availPill.getAttribute('data-stall') || '';
			window._scDestDate  = availPill.getAttribute('data-date') || '';
			var overlay = document.getElementById('eem-scope-modal-overlay');
			var info    = document.getElementById('eem-scope-modal-current');
			if (info) {
				info.innerHTML = '<strong>' + (window._scCustomerName || '—') + '</strong> &mdash; ' +
					'Stall ' + (window._scActiveStall || '—') + ' on ' + (window._scActiveDate || '—') +
					' &rarr; Stall ' + (window._scDestStall || '—');
			}
			var errEl = document.getElementById('eem-scope-modal-error');
			if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
			if (overlay) overlay.style.display = 'flex';
			return;
		}

		// Close scope modal
		var closeScope = t.closest('[data-eem-action="close-scope-modal"]');
		if (closeScope) {
			ev.preventDefault();
			var ov2 = document.getElementById('eem-scope-modal-overlay');
			if (ov2) ov2.style.display = 'none';
			return;
		}

		// Confirm move (POST AJAX)
		var confirmBtn = t.closest('[data-eem-action="confirm-move"]');
		if (confirmBtn) {
			ev.preventDefault();
			var cfg = window.eemStallChart || {};
			var scopeEl = document.querySelector('input[name="eem_move_scope"]:checked');
			var scope = scopeEl ? scopeEl.value : 'this-night';
			var body = new URLSearchParams();
			body.set('action', 'eem_move_stall_assignment');
			body.set('_wpnonce', cfg.moveNonce || '');
			body.set('order_id', window._scActiveOrderId || '');
			body.set('source_stall', window._scActiveStall || '');
			body.set('source_date', window._scActiveDate || '');
			body.set('destination_stall', window._scDestStall || '');
			body.set('scope', scope);
			confirmBtn.disabled = true;
			fetch((cfg.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php'), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (r) { return r.json(); }).then(function (resp) {
				confirmBtn.disabled = false;
				if (resp && resp.success) {
					var ov3 = document.getElementById('eem-scope-modal-overlay');
					if (ov3) ov3.style.display = 'none';
					document.body.classList.remove('destination-mode');
					document.body.classList.remove('eem-has-destination-banner');
					var bn = document.getElementById('eem-destination-banner');
					if (bn) bn.style.display = 'none';
					if (window.EEM && window.EEM.showSaveToast) {
						window.EEM.showSaveToast((resp.data && resp.data.message) || 'Moved.', { variant: 'success' });
					}
					window.location.reload();
				} else {
					var errEl2 = document.getElementById('eem-scope-modal-error');
					var msg = (resp && resp.data && resp.data.message) || 'Move failed.';
					if (errEl2) { errEl2.textContent = msg; errEl2.style.display = ''; }
				}
			}).catch(function () {
				confirmBtn.disabled = false;
				var errEl3 = document.getElementById('eem-scope-modal-error');
				if (errEl3) { errEl3.textContent = 'Could not reach the server.'; errEl3.style.display = ''; }
			});
			return;
		}

		// Change Event — show typeahead
		var changeBtn = t.closest('[data-eem-action="stall-chart-change-event"]');
		if (changeBtn) {
			ev.preventDefault();
			var changeBtnEl = document.getElementById('eem-stall-chart-change-btn');
			if (changeBtnEl) changeBtnEl.style.display = 'none';
			var typeahead = document.getElementById('eem-header-typeahead');
			if (typeahead) typeahead.style.display = '';
			var searchInput = document.getElementById('eem-header-event-input');
			if (searchInput) { searchInput.value = ''; searchInput.focus(); }
			return;
		}

		// Cancel change event
		var cancelBtn = t.closest('[data-eem-action="stall-chart-cancel-change"]');
		if (cancelBtn) {
			ev.preventDefault();
			var typeahead2 = document.getElementById('eem-header-typeahead');
			if (typeahead2) typeahead2.style.display = 'none';
			var changeBtn2 = document.getElementById('eem-stall-chart-change-btn');
			if (changeBtn2) changeBtn2.style.display = '';
			return;
		}

		// Close cell menu when clicking outside
		var menu2 = document.getElementById('eem-stall-chart-cell-menu');
		if (menu2 && menu2.classList.contains('open') && !menu2.contains(t)) {
			menu2.classList.remove('open');
			window._eemActivePill = null;
		}
	});

	// Stall chart filter — search box + barn tabs + "Show by group" (V1 D2).
	// Shared so the search input and the group toggle apply the same logic.
	function eemApplyStallChartFilter(panel) {
		if (!panel) return;
		var input = panel.querySelector('.eem-stall-chart-search-input');
		var q = input ? input.value.toLowerCase().trim() : '';
		var barnFilter = document.querySelector('.eem-stall-chart-barn-tab.active');
		var activeBarn = barnFilter ? barnFilter.getAttribute('data-barn') : 'all';
		var groupToggle = panel.querySelector('[data-eem-action="stall-chart-toggle-groups"]');
		var groupsOnly = !!groupToggle && groupToggle.getAttribute('aria-pressed') === 'true';
		var tackToggle = panel.querySelector('[data-eem-action="stall-chart-toggle-tack"]');
		var tackOnly = !!tackToggle && tackToggle.getAttribute('aria-pressed') === 'true';
		var rows = Array.prototype.slice.call(panel.querySelectorAll('[data-stall-chart-search]'));
		var visible = 0;
		rows.forEach(function (row) {
			var haystack = (row.getAttribute('data-stall-chart-search') || '').toLowerCase();
			var barn = (row.getAttribute('data-barn') || '').toLowerCase();
			var hasGroup = (row.getAttribute('data-group') || '').trim() !== '';
			var hasTack = (row.getAttribute('data-has-tack') || '0') === '1';
			var matchesSearch = !q || haystack.indexOf(q) !== -1;
			// Empty barn = By-Customer rows; they ignore the By-Location barn tabs.
			var matchesBarn = activeBarn === 'all' || barn === '' || barn === activeBarn;
			var matchesGroup = !groupsOnly || hasGroup;
			var matchesTack = !tackOnly || hasTack;
			var show = matchesSearch && matchesBarn && matchesGroup && matchesTack && !row.classList.contains('eem-chart-barn-row');
			row.hidden = !show;
			if (show) visible++;
		});
		var emptyNote = panel.querySelector('.eem-stall-chart-empty-note');
		if (emptyNote) emptyNote.hidden = visible > 0;
	}

	document.addEventListener('input', function (ev) {
		var t = ev.target;
		if (!t || !t.classList.contains('eem-stall-chart-search-input')) return;
		eemApplyStallChartFilter(t.closest('.eem-stall-chart-tab-panel') || document.body);
	});

	// Stall chart event typeahead input
	document.addEventListener('input', function (ev) {
		var t = ev.target;
		if (!t || t.getAttribute('data-eem-input-action') !== 'stall-chart-filter-events') return;
		var q = t.value.toLowerCase().trim();
		var container = document.getElementById('eem-stall-chart-event-results');
		if (!container) return;
		var options = window._eemStallChartEvents || [];
		var results = options.filter(function (ev2) {
			return !q || ev2.name.toLowerCase().indexOf(q) !== -1 || (ev2.dates && ev2.dates.toLowerCase().indexOf(q) !== -1);
		});
		if (!results.length) {
			container.innerHTML = '<div class="eem-header-event-option-empty">' + (window.EEM && window.EEM.i18n && window.EEM.i18n.noMatchingEvents ? window.EEM.i18n.noMatchingEvents : 'No matching events.') + '</div>';
			return;
		}
		container.innerHTML = results.map(function (ev2) {
			var tag = ev2.current ? ' <span class="eem-header-event-option-tag">Current</span>' : '';
			return '<div class="eem-header-event-option' + (ev2.current ? ' is-current' : '') + '" data-eem-action="stall-chart-select-event" data-event-id="' + ev2.id + '"><span class="eem-header-event-option-name">' + ev2.name + '</span><span class="eem-header-event-option-dates">' + ev2.dates + tag + '</span></div>';
		}).join('');
	});

	// Initialise stall chart inv/tab state from URL params on page load.
	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('[data-eem-action="sc-inv-switch"]')) return;
		try {
			var params  = new URLSearchParams(window.location.search);
			var initInv = params.get('inv') || 'all';
			var initTab = params.get('tab') || 'location';
			if (['all', 'stalls', 'rv'].indexOf(initInv) === -1) initInv = 'all';
			if (['location', 'customer'].indexOf(initTab) === -1) initTab = 'location';
			eemScApplyState(initInv, initTab);
		} catch (e) { /* ignore */ }
	});

	/* ── Rail-card click handlers (Preview / Move to Trash / Unlink Event) ── */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;

		var trashBtn = t.closest('[data-eem-action="reservation-editor-trash"]');
		if (trashBtn) {
			ev.preventDefault();
			if (!confirm('Move this reservation to Trash? You can restore it from the Trash list within 30 days.')) return;
			var bar = document.querySelector('.eem-edit-rail [data-eem-reservation-id], .eem-reservation-editor-body[data-eem-reservation-id]');
			var rid = bar ? bar.getAttribute('data-eem-reservation-id') : '';
			if (!rid) return;
			var body = new URLSearchParams();
			body.set('action', 'eem_reservation_editor_trash');
			body.set('_eem_editor_nonce', (document.querySelector('input[name="_eem_editor_nonce"]') || {}).value || '');
			body.set('reservation_id', rid);
			fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (r) { return r.json(); }).then(function (resp) {
				if (resp && resp.success && resp.data && resp.data.redirect_url) {
					window.location.href = resp.data.redirect_url;
				} else if (window.EEM && window.EEM.showSaveToast) {
					window.EEM.showSaveToast((resp.data && resp.data.message) || 'Trash failed.', { variant: 'error' });
				}
			}).catch(function () {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Could not reach the server.', { variant: 'error' });
			});
			return;
		}

		// C7.X.12 Item 7 — "(change)" handler from the meta-line.
		// Rail card retirement means the typeahead UI moves inline.
		// Minimum-functional flow this commit: confirm + unlink, page
		// reloads to "(link event)" state for the admin to pick a new
		// event. Full inline typeahead modal is a focused follow-up
		// (CLEANUP follow-up — keeps this commit scoped to the
		// retirement + inline affordance change, not new UI work).
		var changeBtn = t.closest('[data-eem-action="reservation-editor-event-change"]');
		if (changeBtn) {
			ev.preventDefault();
			if (!confirm('Change the linked event? The current link will be cleared. You can then link a new event from the rail card.')) return;
			var changeBar = document.querySelector('.eem-reservation-editor-body[data-eem-reservation-id]');
			var changeRid = changeBar ? changeBar.getAttribute('data-eem-reservation-id') : '';
			if (!changeRid) return;
			var changeBody = new URLSearchParams();
			changeBody.set('action', 'eem_reservation_editor_unlink_event');
			changeBody.set('_eem_editor_nonce', (document.querySelector('input[name="_eem_editor_nonce"]') || {}).value || '');
			changeBody.set('reservation_id', changeRid);
			fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: changeBody.toString()
			}).then(function (r) { return r.json(); }).then(function (resp) {
				if (resp && resp.success) {
					window.location.reload();
				} else if (window.EEM && window.EEM.showSaveToast) {
					window.EEM.showSaveToast((resp.data && resp.data.message) || 'Change failed.', { variant: 'error' });
				}
			}).catch(function () {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Could not reach the server.', { variant: 'error' });
			});
			return;
		}

		var unlinkBtn = t.closest('[data-eem-action="reservation-editor-event-unlink"]');
		if (unlinkBtn) {
			ev.preventDefault();
			if (!confirm('Unlink this reservation from its source event? The reservation will keep its title + dates as a snapshot but lose the live event link.')) return;
			var bar2 = document.querySelector('.eem-reservation-editor-body[data-eem-reservation-id]');
			var rid2 = bar2 ? bar2.getAttribute('data-eem-reservation-id') : '';
			if (!rid2) return;
			var body2 = new URLSearchParams();
			body2.set('action', 'eem_reservation_editor_unlink_event');
			body2.set('_eem_editor_nonce', (document.querySelector('input[name="_eem_editor_nonce"]') || {}).value || '');
			body2.set('reservation_id', rid2);
			fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body2.toString()
			}).then(function (r) { return r.json(); }).then(function (resp) {
				if (resp && resp.success) {
					window.location.reload();
				} else if (window.EEM && window.EEM.showSaveToast) {
					window.EEM.showSaveToast((resp.data && resp.data.message) || 'Unlink failed.', { variant: 'error' });
				}
			}).catch(function () {
				if (window.EEM && window.EEM.showSaveToast) window.EEM.showSaveToast('Could not reach the server.', { variant: 'error' });
			});
			return;
		}
	});

	/* ── On page load: initial visibility for ID-based controls,
	   fee-mode visibility, cancellation override state ── */
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-eem-action="reservation-editor-toggle-switch-row"], [data-eem-action="reservation-editor-toggle-stay-type"]').forEach(eemApplyControlsById);
		eemApplyFeeModeVisibility();
		var ct = document.getElementById('en_cancellation_policy_override');
		if (ct) {
			ct.addEventListener('input', eemUpdateCancellationOverrideState);
			eemUpdateCancellationOverrideState();
		}
		/* C8 — init inventory displays on page load. Stall uses the V1 #4
		   inventory-type input; RV still uses the legacy mode button. */
		if (document.getElementById('eem-stall-inventory-type-input')) updateStallInventoryDisplay();
		if (document.querySelector('.eem-mode-btn[data-section="rv"]'))   updateRvInventoryDisplay();
		/* V1 (2.3.22): zone-qty Avail Qty inputs removed from zone rows — listener removed.
		 * RV inventory is now computed from row lot counts via updateRvInventoryDisplay(). */
	});

/* ── Linked Events — live AJAX typeahead wired to TEC events (FIX 1, 2.3.41) ── */
var _eventSearchTimer = null;
var _eventSearchXhr   = null;

function changeLinkedEvent() {
	var btn = document.getElementById('eem-header-action-change');
	if (btn) btn.style.display = 'none';
	var tah = document.getElementById('eem-header-typeahead');
	if (tah) tah.style.display = '';
	var inp = document.getElementById('eem-event-search-input');
	if (inp) { inp.value = ''; inp.focus(); }
	fetchEventOptions('');
}

function cancelChangeEvent() {
	var tah = document.getElementById('eem-header-typeahead');
	if (tah) tah.style.display = 'none';
	var btn = document.getElementById('eem-header-action-change');
	if (btn) btn.style.display = '';
}

function selectLinkedEvent(eventId) {
	var container = document.getElementById('eem-event-search-results');
	var chosen    = container ? container.querySelector('[data-event-id="' + eventId + '"]') : null;

	if (chosen) {
		// Pull display text from the rendered result row.
		var nameSpan = chosen.querySelector('.eem-event-option-name');
		var dateSpan = chosen.querySelector('.eem-event-option-date');
		var rawName  = nameSpan ? nameSpan.textContent.replace(/\s*CURRENT\s*$/i, '').trim() : '';
		var dateStr  = dateSpan ? dateSpan.textContent.trim() : '';

		var nameEl = document.getElementById('eem-header-event-name');
		if (nameEl && rawName) nameEl.textContent = rawName;

		var metaEl = document.getElementById('eem-header-meta');
		if (metaEl && dateStr) {
			// Replace everything after "·" (or append date if no "·" yet).
			metaEl.textContent = metaEl.textContent.replace(/\s*[·•].*$/, '').trim() + ' · ' + dateStr;
		}
	}

	// Update hidden form field and typeahead data attribute.
	var hiddenInput = document.getElementById('eem-linked-event-id-input');
	if (hiddenInput) hiddenInput.value = eventId;
	var tah = document.getElementById('eem-header-typeahead');
	if (tah) tah.dataset.currentEventId = eventId;

	cancelChangeEvent();

	// 2.3.77 — when the link GATE is showing (new / unlinked reservation), the
	// configuration form isn't rendered yet; setting the hidden event id only
	// updated the header, so the gate persisted and "the form never loads."
	// Persist the chosen event as a draft and reload so the server re-renders
	// the full editor. Scoped to the gate so the "Change Event" flow on an
	// already-configured reservation keeps its manual-save behavior.
	if (document.querySelector('.eem-reservation-link-gate') && typeof eemDispatchSave === 'function') {
		eemDispatchSave('save_draft');
	}
}

function fetchEventOptions(query) {
	var tah = document.getElementById('eem-header-typeahead');
	if (!tah) return;
	var ajaxUrl   = tah.dataset.ajaxUrl   || '';
	var nonce     = tah.dataset.searchNonce || '';
	var currentId = String(tah.dataset.currentEventId || '0');

	var container = document.getElementById('eem-event-search-results');
	if (!container) return;

	container.innerHTML = '<div class="eem-event-option-loading">Loading…</div>';

	// Cancel any in-flight request.
	if (_eventSearchXhr) { try { _eventSearchXhr.abort(); } catch (e) {} _eventSearchXhr = null; }

	// Exclude the reservation being edited so its own linked event still shows
	// (and so taken-by-others events are filtered server-side — one-to-one guard).
	var excludeRid = String(tah.dataset.reservationId || '0');
	var params = 'action=equine_event_manager_search_tec_events&nonce=' + encodeURIComponent(nonce) + '&term=' + encodeURIComponent(query) + '&reservation_id=' + encodeURIComponent(excludeRid);
	var xhr = new XMLHttpRequest();
	_eventSearchXhr = xhr;
	xhr.open('GET', ajaxUrl + '?' + params, true);
	xhr.onreadystatechange = function () {
		if (xhr.readyState !== 4) return;
		_eventSearchXhr = null;
		if (xhr.status !== 200) {
			container.innerHTML = '<div class="eem-event-option-empty">Could not load events.</div>';
			return;
		}
		var resp;
		try { resp = JSON.parse(xhr.responseText); } catch (e) {
			container.innerHTML = '<div class="eem-event-option-empty">Could not load events.</div>';
			return;
		}
		if (!resp.success || !resp.data || !resp.data.results || !resp.data.results.length) {
			container.innerHTML = '<div class="eem-event-option-empty">No matching events.</div>';
			return;
		}
		container.innerHTML = resp.data.results.map(function (ev) {
			var isCurrent = String(ev.id) === currentId;
			var badge     = isCurrent ? ' <span class="eem-event-option-current-badge">CURRENT</span>' : '';
			var cls       = 'eem-event-option' + (isCurrent ? ' is-current' : '');
			var dateStr   = ev.start_date ? _eemFormatEventDate(ev.start_date) : '';
			return '<div class="' + cls + '" data-eem-action="header-select-event" data-event-id="' + escapeHtml(String(ev.id)) + '">' +
			       '<span class="eem-event-option-name">' + escapeHtml(ev.text) + badge + '</span>' +
			       (dateStr ? '<span class="eem-event-option-date">' + escapeHtml(dateStr) + '</span>' : '') +
			       '</div>';
		}).join('');
	};
	xhr.send();
}

function _eemFormatEventDate(mysqlDate) {
	if (!mysqlDate) return '';
	// MySQL format: "YYYY-MM-DD HH:MM:SS" — replace space with T for ISO 8601.
	var d = new Date(mysqlDate.replace(' ', 'T'));
	if (isNaN(d.getTime())) return mysqlDate;
	return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function filterEventOptions(query) {
	// Debounced entry-point for the input listener; empty queries fire immediately.
	if (_eventSearchTimer) clearTimeout(_eventSearchTimer);
	if (!query) { fetchEventOptions(''); return; }
	_eventSearchTimer = setTimeout(function () { fetchEventOptions(query); }, 250);
}

function stallMappedIsActive() {
	// Scenario B (V1 #4): "mapped" computed-inventory behaviour now follows the
	// Stall Inventory Type = numbered control (both numbered combos show the row
	// builder + computed inventory).
	var inv = document.getElementById('eem-stall-inventory-type-input');
	return !!inv && inv.value === 'numbered';
}

/* Scenario B (V1 #4): keep the legacy hidden stall mode input in sync with the
   two new controls (exact_map iff numbered + pick_layout). */
function syncStallLegacyMode() {
	var inv = document.getElementById('eem-stall-inventory-type-input');
	var sel = document.getElementById('eem-stall-customer-selection-input');
	var legacy = document.getElementById('eem-stall-selection-mode-input');
	if (inv && sel && legacy) {
		legacy.value = (inv.value === 'numbered' && sel.value === 'pick_layout') ? 'exact_map' : 'quantity';
	}
}

/* Stall Inventory Type toggle (quantity_only / numbered). Drives the Stall Row
   Builder + inventory-input visibility and enables/disables Pick-from-layout. */
function toggleStallInventoryType(btn) {
	var isNumbered = btn.dataset.type === 'numbered';
	document.querySelectorAll('[data-eem-action="toggle-stall-inventory-type"]').forEach(function (b) {
		b.classList.toggle('active', b === btn);
	});
	var inv = document.getElementById('eem-stall-inventory-type-input');
	if (inv) inv.value = btn.dataset.type;

	var panel    = document.getElementById('eem-stall-mapped-content');
	var editable = document.getElementById('eem-stall-inventory-input');
	var computed = document.getElementById('eem-stall-inventory-computed');
	if (panel)    panel.style.display    = isNumbered ? '' : 'none';
	if (editable) editable.style.display = isNumbered ? 'none' : '';
	if (computed) computed.style.display = isNumbered ? '' : 'none';

	var typeHint = document.querySelector('.eem-stall-inventory-type-hint');
	if (typeHint) {
		typeHint.textContent = isNumbered
			? 'Specific stall numbers exist — define them in the Stall Row Builder below.'
			: 'Sell a total count with no specific stall identities.';
	}

	// Pick-from-layout requires numbered stalls.
	var pickBtn = document.querySelector('[data-eem-action="toggle-stall-customer-selection"][data-selection="pick_layout"]');
	if (pickBtn) {
		pickBtn.disabled = !isNumbered;
		pickBtn.classList.toggle('is-disabled', !isNumbered);
		if (!isNumbered && pickBtn.classList.contains('active')) {
			var qtyBtn = document.querySelector('[data-eem-action="toggle-stall-customer-selection"][data-selection="quantity"]');
			if (qtyBtn) toggleStallCustomerSelection(qtyBtn);
		}
	}

	syncStallLegacyMode();
	updateStallInventoryDisplay();
}

/* Customer Selection toggle (quantity / pick_layout). */
function toggleStallCustomerSelection(btn) {
	if (btn.disabled) return;
	document.querySelectorAll('[data-eem-action="toggle-stall-customer-selection"]').forEach(function (b) {
		b.classList.toggle('active', b === btn);
	});
	var sel = document.getElementById('eem-stall-customer-selection-input');
	if (sel) sel.value = btn.dataset.selection;

	var selHint = document.querySelector('.eem-stall-customer-selection-hint');
	if (selHint) {
		selHint.textContent = btn.dataset.selection === 'pick_layout'
			? 'Customers select specific stalls from your layout at checkout.'
			: 'Customers pick how many stalls they need; you assign specific stalls on the Stall & RV Charts page.';
	}
	syncStallLegacyMode();
}

function rvMappedIsActive() {
	var btn = document.querySelector('.eem-mode-btn.active[data-section="rv"]');
	return btn && btn.dataset.mode === 'mapped';
}

function toggleInventoryMode(btn) {
	var section = btn.dataset.section; /* 'stall' or 'rv' */
	/* Update active button */
	document.querySelectorAll('.eem-mode-btn[data-section="' + section + '"]').forEach(function(b) {
		b.classList.remove('active');
	});
	btn.classList.add('active');
	/* Update hidden meta input */
	var metaInput = document.getElementById('eem-' + section + '-selection-mode-input');
	if (metaInput) metaInput.value = btn.dataset.mode === 'mapped' ? 'exact_map' : 'quantity';
	/* Show/hide mapped content panel */
	var panel = document.getElementById('eem-' + section + '-mapped-content');
	if (panel) panel.style.display = btn.dataset.mode === 'mapped' ? '' : 'none';
	/* Swap inventory field */
	var editable = document.getElementById('eem-' + section + '-inventory-input');
	var computed  = document.getElementById('eem-' + section + '-inventory-computed');
	if (editable) editable.style.display = btn.dataset.mode === 'mapped' ? 'none' : '';
	if (computed)  computed.style.display  = btn.dataset.mode === 'mapped' ? '' : 'none';
	/* Update hint text */
	var hintEl = btn.parentElement && btn.parentElement.parentElement
		? btn.parentElement.parentElement.querySelector('.eem-inventory-mode-hint') : null;
	if (hintEl) {
		hintEl.textContent = btn.dataset.mode === 'mapped'
			? (section === 'stall'
				? 'Customers select specific stalls from your layout at checkout'
				: 'Customers select specific lots from your layout at checkout')
			: (section === 'stall'
				? 'Customers pick how many stalls they need at checkout; admin assigns specific stalls on the Stall & RV Charts page'
				: 'Customers pick how many lots they need at checkout; admin assigns specific lots on the Stall & RV Charts page');
	}
	/* Refresh computed display */
	if (section === 'stall') updateStallInventoryDisplay();
	else updateRvInventoryDisplay();
}

function updateStallInventoryDisplay() {
	if (!stallMappedIsActive()) return;
	/* Stall total = sum of stall-label counts across all row cards,
	   minus the number of blocked-stall chips. */
	var total = 0;
	document.querySelectorAll('#eem-stall-row-builder-list .eem-row-card').forEach(function (card) {
		var layout = card.querySelector('[data-eem-input-action="stall-row-layout"]');
		var isB2B  = layout && layout.value === 'back-to-back';
		if (isB2B) {
			var tFirst = card.querySelector('[data-role="top-first"]');
			var tLast  = card.querySelector('[data-role="top-last"]');
			var bFirst = card.querySelector('[data-role="bot-first"]');
			var bLast  = card.querySelector('[data-role="bot-last"]');
			total += stallLabelsBetween(tFirst ? tFirst.value : '', tLast ? tLast.value : '').length;
			total += stallLabelsBetween(bFirst ? bFirst.value : '', bLast ? bLast.value : '').length;
		} else {
			var first = card.querySelector('[data-role="first"]');
			var last  = card.querySelector('[data-role="last"]');
			total += stallLabelsBetween(first ? first.value : '', last ? last.value : '').length;
		}
	});
	var blocked = document.querySelectorAll('#eem-blocked-stalls-select .eem-tag-chip').length;
	total = Math.max(0, total - blocked);
	var numEl = document.getElementById('eem-stall-inventory-number');
	if (numEl) numEl.textContent = total;
	/* Update summary line */
	var rows   = document.querySelectorAll('#eem-stall-row-builder-list .eem-row-card').length;
	var sumEl  = document.getElementById('eem-stall-row-summary');
	if (sumEl) sumEl.innerHTML = '<strong style="color:#031B4E">' + rows + ' ' + (rows === 1 ? 'row' : 'rows') + ' · ' + total + ' stalls total</strong> across this reservation';
}

function updateRvInventoryDisplay() {
	if (!rvMappedIsActive()) return;
	/* V1: RV total = sum of lot-label counts across all row cards, minus blocked.
	   This matches the stall computation and is the V1 inventory truth. */
	var total = 0;
	document.querySelectorAll('#eem-rv-row-builder-list .eem-row-card').forEach(function (card) {
		var layout = card.querySelector('[data-eem-input-action="rv-row-layout"]');
		var isB2B  = layout && layout.value === 'back-to-back';
		if (isB2B) {
			var tFirst = card.querySelector('[data-role="top-first"]');
			var tLast  = card.querySelector('[data-role="top-last"]');
			var bFirst = card.querySelector('[data-role="bot-first"]');
			var bLast  = card.querySelector('[data-role="bot-last"]');
			total += stallLabelsBetween(tFirst ? tFirst.value : '', tLast ? tLast.value : '').length;
			total += stallLabelsBetween(bFirst ? bFirst.value : '', bLast ? bLast.value : '').length;
		} else {
			var first = card.querySelector('[data-role="first"]');
			var last  = card.querySelector('[data-role="last"]');
			total += stallLabelsBetween(first ? first.value : '', last ? last.value : '').length;
		}
	});
	var blocked = document.querySelectorAll('#eem-blocked-rv-lots-select .eem-tag-chip').length;
	total = Math.max(0, total - blocked);
	var numEl = document.getElementById('eem-rv-inventory-number');
	if (numEl) numEl.textContent = total;
	/* Update lot row summary */
	var rows  = document.querySelectorAll('#eem-rv-row-builder-list .eem-row-card').length;
	var lots  = 0;
	document.querySelectorAll('#eem-rv-row-builder-list .eem-row-card').forEach(function (card) {
		var layout = card.querySelector('[data-eem-input-action="rv-row-layout"]');
		var isB2B  = layout && layout.value === 'back-to-back';
		if (isB2B) {
			var tFirst = card.querySelector('[data-role="top-first"]');
			var tLast  = card.querySelector('[data-role="top-last"]');
			var bFirst = card.querySelector('[data-role="bot-first"]');
			var bLast  = card.querySelector('[data-role="bot-last"]');
			lots += stallLabelsBetween(tFirst ? tFirst.value : '', tLast ? tLast.value : '').length;
			lots += stallLabelsBetween(bFirst ? bFirst.value : '', bLast ? bLast.value : '').length;
		} else {
			var first = card.querySelector('[data-role="first"]');
			var last  = card.querySelector('[data-role="last"]');
			lots += stallLabelsBetween(first ? first.value : '', last ? last.value : '').length;
		}
	});
	var sumEl = document.getElementById('eem-rv-row-summary');
	if (sumEl) sumEl.innerHTML = '<strong style="color:#031B4E">' + rows + ' ' + (rows === 1 ? 'row' : 'rows') + ' · ' + lots + ' lots total</strong> across this reservation';
}

/* ─────────────────────────────────────────────────────────────
 * C8 — stallLabelsBetween: compute stall/lot label sequence
 * Handles: integer (100–111), prefixed (Y1–Y12), padded (A-01–A-12).
 * Returns array, capped at 50 labels.
 * ───────────────────────────────────────────────────────────── */
function stallLabelsBetween(first, last) {
	first = String(first || '').trim();
	last  = String(last  || '').trim();
	if (!first || !last) return [];

	/* Pure integers */
	if (/^\d+$/.test(first) && /^\d+$/.test(last)) {
		var a = parseInt(first, 10), b = parseInt(last, 10);
		if (isNaN(a) || isNaN(b)) return [];
		var step = a <= b ? 1 : -1;
		var out = [];
		for (var n = a; step > 0 ? n <= b : n >= b; n += step) {
			out.push(String(n));
			if (out.length >= 50) break;
		}
		return out;
	}

	/* Prefixed: split into (prefix, numeric-suffix).
	   Supports: Y1 → prefix="Y", num=1
	             A-01 → prefix="A-", num=1 (zero-padded preserved) */
	var prefixRe = /^([A-Za-z][A-Za-z0-9\-]*)(\d+)$/;
	var mF = first.match(prefixRe);
	var mL = last.match(prefixRe);
	if (mF && mL && mF[1] === mL[1]) {
		var prefix  = mF[1];
		var padLen  = mF[2].length > 1 && mF[2][0] === '0' ? mF[2].length : 0;
		var numA    = parseInt(mF[2], 10);
		var numB    = parseInt(mL[2], 10);
		if (isNaN(numA) || isNaN(numB)) return [];
		var stepP = numA <= numB ? 1 : -1;
		var outP  = [];
		for (var i = numA; stepP > 0 ? i <= numB : i >= numB; i += stepP) {
			var s = String(i);
			if (padLen > 0) { while (s.length < padLen) s = '0' + s; }
			outP.push(prefix + s);
			if (outP.length >= 50) break;
		}
		return outP;
	}

	/* Fallback: just return [first, last] if both present */
	return [first, last];
}

/* ─────────────────────────────────────────────────────────────
 * Bug D fix: getStallLabels / getRvLotLabels
 * Enumerate every stall/lot label defined in the row builder lists
 * so the tag-search (blocked stalls / blocked RV lots) can populate
 * its dropdown from live row-builder data instead of requiring items
 * to already exist as static DOM nodes.
 * ───────────────────────────────────────────────────────────── */
function getStallLabels() {
	var labels = [];
	var seen   = {};
	var list   = document.getElementById('eem-stall-row-builder-list');
	if (!list) return labels;
	var cards = list.querySelectorAll('.eem-row-card');
	cards.forEach(function(card) {
		var isBtB = card.querySelector('.eem-row-card-sides') &&
		            card.querySelector('.eem-row-card-sides').style.display !== 'none';
		if (isBtB) {
			var tf = card.querySelector('[data-role="top-first"]');
			var tl = card.querySelector('[data-role="top-last"]');
			var bf = card.querySelector('[data-role="bot-first"]');
			var bl = card.querySelector('[data-role="bot-last"]');
			stallLabelsBetween(tf ? tf.value : '', tl ? tl.value : '').forEach(function(l) {
				if (!seen[l]) { seen[l] = true; labels.push(l); }
			});
			stallLabelsBetween(bf ? bf.value : '', bl ? bl.value : '').forEach(function(l) {
				if (!seen[l]) { seen[l] = true; labels.push(l); }
			});
		} else {
			var fIn = card.querySelector('[data-role="first"]');
			var lIn = card.querySelector('[data-role="last"]');
			stallLabelsBetween(fIn ? fIn.value : '', lIn ? lIn.value : '').forEach(function(l) {
				if (!seen[l]) { seen[l] = true; labels.push(l); }
			});
		}
	});
	return labels;
}

function getRvLotLabels() {
	var labels = [];
	var seen   = {};
	var list   = document.getElementById('eem-rv-row-builder-list');
	if (!list) return labels;
	var cards = list.querySelectorAll('.eem-row-card');
	cards.forEach(function(card) {
		var isBtB = card.querySelector('.eem-row-card-sides') &&
		            card.querySelector('.eem-row-card-sides').style.display !== 'none';
		if (isBtB) {
			var tf = card.querySelector('[data-role="top-first"]');
			var tl = card.querySelector('[data-role="top-last"]');
			var bf = card.querySelector('[data-role="bot-first"]');
			var bl = card.querySelector('[data-role="bot-last"]');
			stallLabelsBetween(tf ? tf.value : '', tl ? tl.value : '').forEach(function(l) {
				if (!seen[l]) { seen[l] = true; labels.push(l); }
			});
			stallLabelsBetween(bf ? bf.value : '', bl ? bl.value : '').forEach(function(l) {
				if (!seen[l]) { seen[l] = true; labels.push(l); }
			});
		} else {
			var fIn = card.querySelector('[data-role="first"]');
			var lIn = card.querySelector('[data-role="last"]');
			stallLabelsBetween(fIn ? fIn.value : '', lIn ? lIn.value : '').forEach(function(l) {
				if (!seen[l]) { seen[l] = true; labels.push(l); }
			});
		}
	});
	return labels;
}

/* ─────────────────────────────────────────────────────────────
 * V1 RV zone model — getZoneColor palette (shared by zone swatches
 * and row card zone indicators). Per-lot painting is V2 backlog.
 * ───────────────────────────────────────────────────────────── */

/**
 * Return a hex color from the canonical zone palette for the given
 * zero-based zone index. Returns 'transparent' for absent / null.
 *
 * @param  {number|string|null} zoneIndex
 * @return {string}
 */
function getZoneColor(zoneIndex) {
	var palette = ['#DC2626', '#2563EB', '#16A34A', '#CA8A04', '#9333EA', '#EA580C'];
	if (zoneIndex === null || zoneIndex === undefined || zoneIndex === '' || zoneIndex === '-1') {
		return 'transparent';
	}
	var idx = parseInt(zoneIndex, 10);
	if (isNaN(idx)) return 'transparent';
	return palette[idx % palette.length] || '#9CA3AF';
}

/**
 * Update the row card's zone color indicator when the zone dropdown changes.
 * Applies a left-border color to the row card matching the selected zone's
 * palette color. A visually lightweight indicator — no per-lot dots.
 *
 * @param {HTMLElement} rowCard  The .eem-row-card element.
 * @return {void}
 */
function rvUpdateRowZoneIndicator(rowCard) {
	var zoneSel = rowCard.querySelector('[data-field="zone_id"]');
	if (!zoneSel) return;
	var zoneId = zoneSel.value;
	var color  = zoneId !== '' ? getZoneColor(parseInt(zoneId, 10)) : '#D9E2F2';
	rowCard.style.borderLeftColor = color;
	rowCard.style.borderLeftWidth = zoneId !== '' ? '4px' : '1px';
}

/* ─────────────────────────────────────────────────────────────
 * C8 — generateStallPreview: fill the .eem-stall-row-layout div
 * ───────────────────────────────────────────────────────────── */
function generateStallPreview(rowCard) {
	var layoutSel  = rowCard.querySelector('[data-eem-input-action="stall-row-layout"], [data-eem-input-action="rv-row-layout"]');
	var layout     = layoutSel ? layoutSel.value : 'one-sided';
	var previewDiv = rowCard.querySelector('.eem-stall-row-layout');
	var countEl    = rowCard.querySelector('.eem-row-card-count');
	if (!previewDiv) return;

	// Detect whether this is an RV row card (uses rv-row-layout / rv-row-input)
	// so we render .eem-stall-box divs styled with the row's zone color.
	// V1: no per-lot clicking or zone dots — zone is assigned at row level.
	var isRvRow = !!rowCard.querySelector('[data-eem-input-action="rv-row-layout"], [data-eem-input-action="rv-row-input"]');

	/**
	 * Build the HTML string for a single lot or stall cell.
	 * Both stall and RV rows render plain .eem-stall-box divs.
	 * RV rows get a subtle background tint from the row's zone color.
	 *
	 * @param  {string} l  Lot or stall label
	 * @return {string}
	 */
	function cellHtml(l) {
		if (!isRvRow) {
			return '<div class="eem-stall-box">' + escapeHtml(l) + '</div>';
		}
		// V1: zone color comes from the row's Zone dropdown, not per-lot assignment.
		var zoneSel = rowCard.querySelector('[data-field="zone_id"]');
		var zoneId  = zoneSel ? zoneSel.value : '';
		var color   = zoneId !== '' ? getZoneColor(parseInt(zoneId, 10)) : '';
		var style   = color ? ' style="border-bottom:2px solid ' + escapeHtml(color) + '"' : '';
		return '<div class="eem-stall-box"' + style + '>' + escapeHtml(l) + '</div>';
	}

	if (layout === 'back-to-back') {
		previewDiv.classList.add('eem-back-to-back');
		var topFirst = rowCard.querySelector('[data-role="top-first"]');
		var topLast  = rowCard.querySelector('[data-role="top-last"]');
		var botFirst = rowCard.querySelector('[data-role="bot-first"]');
		var botLast  = rowCard.querySelector('[data-role="bot-last"]');
		var topLabels = stallLabelsBetween(topFirst ? topFirst.value : '', topLast ? topLast.value : '');
		var botLabels = stallLabelsBetween(botFirst ? botFirst.value : '', botLast ? botLast.value : '');
		var topHtml = topLabels.map(cellHtml).join('');
		var botHtml = botLabels.map(cellHtml).join('');
		previewDiv.innerHTML =
			'<div class="eem-stall-row-side">' + topHtml + '</div>' +
			'<div class="eem-stall-row-aisle" title="Aisle"></div>' +
			'<div class="eem-stall-row-side">' + botHtml + '</div>';
		var unitWord = isRvRow ? 'lots' : 'stalls';
		if (countEl) countEl.textContent = (topLabels.length + botLabels.length) + ' ' + unitWord + ' \xb7 Back-to-back (' + topLabels.length + ' top, ' + botLabels.length + ' bottom)';
	} else {
		previewDiv.classList.remove('eem-back-to-back');
		var first = rowCard.querySelector('[data-role="first"]');
		var last  = rowCard.querySelector('[data-role="last"]');
		var labels = stallLabelsBetween(first ? first.value : '', last ? last.value : '');
		previewDiv.innerHTML = labels.map(cellHtml).join('');
		var unitWord2 = isRvRow ? 'lots' : 'stalls';
		if (countEl) countEl.textContent = labels.length + ' ' + unitWord2 + ' \xb7 One-sided';
	}
}

/* ─────────────────────────────────────────────────────────────
 * C8 — Stall row builder helpers
 * ───────────────────────────────────────────────────────────── */
function stallAddRow() {
	var list = document.getElementById('eem-stall-row-builder-list');
	if (!list) return;
	var idx  = list.querySelectorAll('.eem-row-card').length;
	var card = document.createElement('div');
	card.className   = 'eem-row-card';
	card.dataset.rowIndex = idx;
	card.innerHTML =
		'<div class="eem-row-card-top">' +
			'<div class="eem-row-card-field">' +
				'<span class="eem-row-card-field-label">Row Name</span>' +
				'<input type="text" name="eem_stall_rows[' + idx + '][name]" value="" data-eem-input-action="stall-row-input">' +
			'</div>' +
			'<div class="eem-row-card-field eem-row-card-field-layout">' +
				'<span class="eem-row-card-field-label">Layout</span>' +
				'<select name="eem_stall_rows[' + idx + '][layout]" data-eem-input-action="stall-row-layout">' +
					'<option value="one-sided" selected>One-sided</option>' +
					'<option value="back-to-back">Back-to-back</option>' +
				'</select>' +
			'</div>' +
			'<button class="eem-row-card-delete" type="button" title="Delete row" data-eem-action="stall-delete-row">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>' +
			'</button>' +
		'</div>' +
		'<div class="eem-row-card-one-sided">' +
			'<div class="eem-row-card-field"><span class="eem-row-card-field-label">First Stall Label</span><input type="text" name="eem_stall_rows[' + idx + '][first]" value="" data-role="first" data-eem-input-action="stall-row-input"></div>' +
			'<div class="eem-row-card-field"><span class="eem-row-card-field-label">Last Stall Label</span><input type="text" name="eem_stall_rows[' + idx + '][last]" value="" data-role="last" data-eem-input-action="stall-row-input"></div>' +
		'</div>' +
		'<div class="eem-row-card-sides" style="display:none">' +
			'<div class="eem-side-block"><div class="eem-side-block-label">Top Side</div><div class="eem-side-block-row">' +
				'<div class="eem-row-card-field"><span class="eem-row-card-field-label">First</span><input type="text" name="eem_stall_rows[' + idx + '][top_first]" value="" data-role="top-first" data-eem-input-action="stall-row-input"></div>' +
				'<div class="eem-row-card-field"><span class="eem-row-card-field-label">Last</span><input type="text" name="eem_stall_rows[' + idx + '][top_last]" value="" data-role="top-last" data-eem-input-action="stall-row-input"></div>' +
			'</div></div>' +
			'<div class="eem-side-block"><div class="eem-side-block-label">Bottom Side</div><div class="eem-side-block-row">' +
				'<div class="eem-row-card-field"><span class="eem-row-card-field-label">First</span><input type="text" name="eem_stall_rows[' + idx + '][bot_first]" value="" data-role="bot-first" data-eem-input-action="stall-row-input"></div>' +
				'<div class="eem-row-card-field"><span class="eem-row-card-field-label">Last</span><input type="text" name="eem_stall_rows[' + idx + '][bot_last]" value="" data-role="bot-last" data-eem-input-action="stall-row-input"></div>' +
			'</div></div>' +
		'</div>' +
		'<div>' +
			'<div class="eem-row-card-preview-label">Preview <span class="eem-row-card-count"></span></div>' +
			'<div class="eem-stall-row-layout"></div>' +
		'</div>';
	list.appendChild(card);
	updateStallInventoryDisplay();
}

function stallDeleteRow(btn) {
	var card = btn.closest('.eem-row-card');
	if (card) card.remove();
	updateStallInventoryDisplay();
}

function stallRowInputChange(input) {
	var card = input.closest('.eem-row-card');
	if (card) generateStallPreview(card);
	updateStallInventoryDisplay();
}

function stallRowLayoutChange(select) {
	var card = select.closest('.eem-row-card');
	if (!card) return;
	var isB2B    = select.value === 'back-to-back';
	var oneSided = card.querySelector('.eem-row-card-one-sided');
	var sides    = card.querySelector('.eem-row-card-sides');
	if (oneSided) oneSided.style.display = isB2B ? 'none' : '';
	if (sides)    sides.style.display    = isB2B ? '' : 'none';
	generateStallPreview(card);
	updateStallInventoryDisplay();
}

/* ─────────────────────────────────────────────────────────────
 * C8 — RV zone + row builder helpers
 * ───────────────────────────────────────────────────────────── */
function rvAddZone() {
	var list = document.getElementById('eem-lot-zones-list');
	var tmpl = document.getElementById('eem-lot-zone-row-template');
	if (!list || !tmpl) return;
	var idx  = list.querySelectorAll('.eem-zone-row').length;
	var frag = tmpl.content.cloneNode(true);
	/* Replace __index__ placeholders */
	frag.querySelectorAll('[name]').forEach(function (el) {
		el.name = el.name.replace('__index__', idx);
	});
	frag.querySelectorAll('[data-zone-index]').forEach(function (el) {
		el.dataset.zoneIndex = idx;
	});
	list.appendChild(frag);
	/* Apply auto-palette color to the new zone's swatch — must happen AFTER
	   appendChild (fragment is consumed), so we query the live DOM. */
	var newRow = list.querySelectorAll('.eem-zone-row')[idx];
	if (newRow) {
		var swatch = newRow.querySelector('.eem-zone-color-swatch');
		if (swatch) { swatch.style.background = getZoneColor(idx); }
	}
	updateRvInventoryDisplay();
}

function rvDeleteZone(btn) {
	var row = btn.closest('.eem-zone-row');
	if (row) row.remove();
	/* Re-apply auto-palette colors to remaining zones — their visual indices
	   shift after a deletion so each swatch needs to reflect its new position. */
	document.querySelectorAll('#eem-lot-zones-list .eem-zone-row').forEach(function (zRow, zi) {
		var swatch = zRow.querySelector('.eem-zone-color-swatch');
		if (swatch) { swatch.style.background = getZoneColor(zi); }
	});
	updateRvInventoryDisplay();
}

// V2 BACKLOG: rvCountUnassignedLots, openUnassignedLotsWarning, rvRebuildPaintDropdown
// were removed in V1 (2.3.22) when per-lot painting was deferred. See docs/c10-contracts.md.

function rvZoneInputChange() {
	updateRvInventoryDisplay();
}

function rvAddRow() {
	var list = document.getElementById('eem-rv-row-builder-list');
	if (!list) return;
	var idx  = list.querySelectorAll('.eem-row-card').length;
	var card = document.createElement('div');
	card.className   = 'eem-row-card';
	card.dataset.rowIndex = idx;
	/* Build zone options from current zone list */
	var zoneOpts = '<option value="">Unassigned</option>';
	document.querySelectorAll('#eem-lot-zones-list .eem-zone-row').forEach(function (zRow, zi) {
		var nameEl = zRow.querySelector('.eem-zone-name-input');
		var name   = nameEl ? (nameEl.value.trim() || 'Zone ' + (zi + 1)) : 'Zone ' + (zi + 1);
		zoneOpts  += '<option value="' + zi + '">' + name + '</option>';
	});
	card.innerHTML =
		'<div class="eem-row-card-top">' +
			'<div class="eem-row-card-field"><span class="eem-row-card-field-label">Row Name</span><input type="text" name="eem_rv_rows[' + idx + '][name]" value="" data-eem-input-action="rv-row-input"></div>' +
			'<div class="eem-row-card-field eem-row-card-field-layout"><span class="eem-row-card-field-label">Layout</span><select name="eem_rv_rows[' + idx + '][layout]" data-eem-input-action="rv-row-layout"><option value="one-sided" selected>One-sided</option><option value="back-to-back">Back-to-back</option></select></div>' +
			'<div class="eem-row-card-field eem-row-card-field-layout"><span class="eem-row-card-field-label">Zone</span><select name="eem_rv_rows[' + idx + '][zone_id]" data-eem-input-action="rv-row-input" data-field="zone_id">' + zoneOpts + '</select></div>' +
			'<button class="eem-row-card-delete" type="button" title="Delete row" data-eem-action="rv-delete-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button>' +
		'</div>' +
		'<div class="eem-row-card-one-sided">' +
			'<div class="eem-row-card-field"><span class="eem-row-card-field-label">First Lot Label</span><input type="text" name="eem_rv_rows[' + idx + '][first]" value="" data-role="first" data-eem-input-action="rv-row-input"></div>' +
			'<div class="eem-row-card-field"><span class="eem-row-card-field-label">Last Lot Label</span><input type="text" name="eem_rv_rows[' + idx + '][last]" value="" data-role="last" data-eem-input-action="rv-row-input"></div>' +
		'</div>' +
		'<div class="eem-row-card-sides" style="display:none">' +
			'<div class="eem-side-block"><div class="eem-side-block-label">Top Side</div><div class="eem-side-block-row"><div class="eem-row-card-field"><span class="eem-row-card-field-label">First</span><input type="text" name="eem_rv_rows[' + idx + '][top_first]" value="" data-role="top-first" data-eem-input-action="rv-row-input"></div><div class="eem-row-card-field"><span class="eem-row-card-field-label">Last</span><input type="text" name="eem_rv_rows[' + idx + '][top_last]" value="" data-role="top-last" data-eem-input-action="rv-row-input"></div></div></div>' +
			'<div class="eem-side-block"><div class="eem-side-block-label">Bottom Side</div><div class="eem-side-block-row"><div class="eem-row-card-field"><span class="eem-row-card-field-label">First</span><input type="text" name="eem_rv_rows[' + idx + '][bot_first]" value="" data-role="bot-first" data-eem-input-action="rv-row-input"></div><div class="eem-row-card-field"><span class="eem-row-card-field-label">Last</span><input type="text" name="eem_rv_rows[' + idx + '][bot_last]" value="" data-role="bot-last" data-eem-input-action="rv-row-input"></div></div></div>' +
		'</div>' +
		'<div><div class="eem-row-card-preview-label">Preview <span class="eem-row-card-count"></span></div><div class="eem-stall-row-layout"></div></div>';
	list.appendChild(card);
	updateRvInventoryDisplay();
}

function rvDeleteRow(btn) {
	var card = btn.closest('.eem-row-card');
	if (card) card.remove();
	updateRvInventoryDisplay();
}

function rvRowInputChange(input) {
	var card = input.closest('.eem-row-card');
	if (card) {
		var layoutSel = card.querySelector('[data-eem-input-action="rv-row-layout"]');
		if (layoutSel) {
			/* Temporarily alias so generateStallPreview works for RV cards */
			var orig = layoutSel.dataset.eemInputAction;
			layoutSel.dataset.eemInputAction = 'rv-row-layout';
			generateStallPreview(card);
			layoutSel.dataset.eemInputAction = orig;
		} else {
			generateStallPreview(card);
		}
	}
	updateRvInventoryDisplay();
}

function rvRowLayoutChange(select) {
	var card = select.closest('.eem-row-card');
	if (!card) return;
	var isB2B    = select.value === 'back-to-back';
	var oneSided = card.querySelector('.eem-row-card-one-sided');
	var sides    = card.querySelector('.eem-row-card-sides');
	if (oneSided) oneSided.style.display = isB2B ? 'none' : '';
	if (sides)    sides.style.display    = isB2B ? '' : 'none';
	generateStallPreview(card);
	updateRvInventoryDisplay();
}

/* ─────────────────────────────────────────────────────────────
 * C8 — Event Pre-Entries helpers
 * ───────────────────────────────────────────────────────────── */
function preEntryAdd() {
	var tbody = document.getElementById('eem-pre-entries-list');
	var tmpl  = document.getElementById('eem-pre-entry-row-template');
	if (!tbody || !tmpl) return;
	var idx  = tbody.querySelectorAll('tr').length;
	var frag = tmpl.content.cloneNode(true);
	frag.querySelectorAll('[name]').forEach(function (el) {
		el.name = el.name.replace('__index__', idx);
	});
	tbody.appendChild(frag);
}

function preEntryDelete(btn) {
	var row = btn.closest('tr');
	if (row) row.remove();
}

/* C8 — initialise previews for seeded rows on page load */
(function () {
	function initPreviews() {
		document.querySelectorAll('#eem-stall-row-builder-list .eem-row-card, #eem-rv-row-builder-list .eem-row-card').forEach(function (card) {
			generateStallPreview(card);
		});
		updateStallInventoryDisplay();
		updateRvInventoryDisplay();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initPreviews);
	} else {
		initPreviews();
	}
})();

/* ─────────────────────────────────────────────────────────────
 * FIX 4 (2.3.42) — Reservation Details card: Name + Slug inputs
 *
 * When the user types in #eem-res-name:
 *   - If non-empty → set #eem-res-name-overridden to '1'
 *   - If empty     → set to '0' (auto-mirror re-enabled on save)
 *   - If slug is not overridden → auto-slugify the typed name
 *     into #eem-res-slug so the user sees a live preview.
 *
 * When the user types in #eem-res-slug:
 *   - Non-empty → set #eem-res-slug-overridden to '1'
 *   - Empty     → set to '0'
 * ───────────────────────────────────────────────────────────── */

function resNameInput(input) {
	var nameOverridden = document.getElementById('eem-res-name-overridden');
	var slugInput      = document.getElementById('eem-res-slug');
	var slugOverridden = document.getElementById('eem-res-slug-overridden');
	if (!nameOverridden) return;
	var hasValue = '' !== input.value.trim();
	nameOverridden.value = hasValue ? '1' : '0';
	// Auto-mirror slug only when slug is not manually overridden.
	if (slugInput && slugOverridden && '1' !== slugOverridden.value) {
		slugInput.value = eemToSlug(input.value);
	}
}

function resSlugInput(input) {
	var slugOverridden = document.getElementById('eem-res-slug-overridden');
	if (!slugOverridden) return;
	slugOverridden.value = '' !== input.value.trim() ? '1' : '0';
}

/** Minimal client-side slug preview — mirrors sanitize_title()'s output
    for common ASCII strings.  Server is the authoritative sanitize_title()
    call; this is display-only. */
function eemToSlug(str) {
	return str
		.toLowerCase()
		.replace(/[^a-z0-9\s-]/g, '')
		.trim()
		.replace(/[\s]+/g, '-');
}

/* ─────────────────────────────────────────────────────────────
 * FIX 5 (2.3.42) — Quick Edit inline row on Reservations list
 *
 * Flow:
 *   1. Click "Quick Edit" in the meatballs dropdown.
 *   2. JS closes the dropdown, dismisses any open QE row, inserts a
 *      new .eem-quick-edit-row <tr> below the clicked row.
 *   3. "Save" fires AJAX to wp_ajax_eem_reservation_quick_edit.
 *      On success, updates the row's .eem-res-name cell and closes the
 *      QE row.  Shows a toast on success or error.
 *   4. "Cancel" closes the QE row without saving.
 * ───────────────────────────────────────────────────────────── */

function openQuickEdit(target) {
	var id  = target.dataset.reservationId;
	var row = document.querySelector('tr[data-reservation-id="' + id + '"]');
	if (!row) return;
	// Close any open quick-edit rows first.
	closeAnyOpenQuickEdit();
	var qr = buildQuickEditRow(id, row);
	row.insertAdjacentElement('afterend', qr);
	var nameInput = qr.querySelector('.eem-qe-name');
	if (nameInput) nameInput.focus();
}

function buildQuickEditRow(id, sourceRow) {
	var nameAnchor  = sourceRow.querySelector('.eem-res-name');
	var currentName = nameAnchor ? nameAnchor.textContent.trim() : '';
	var tr = document.createElement('tr');
	tr.className = 'eem-quick-edit-row';
	tr.dataset.quickEditId = id;
	tr.innerHTML =
		'<td colspan="7"><div class="eem-qe-inner">' +
			'<div class="eem-qe-fields">' +
				'<div class="eem-qe-field">' +
					'<label class="eem-qe-label" for="eem-qe-name-' + id + '">' + eemEscHtml('Reservation Name') + '</label>' +
					'<input type="text" class="eem-field-input eem-qe-name" id="eem-qe-name-' + id + '" value="' + eemEscAttr(currentName) + '" autocomplete="off">' +
					'<p class="eem-field-hint">' + eemEscHtml('Clear to mirror linked event name.') + '</p>' +
				'</div>' +
				'<div class="eem-qe-field">' +
					'<label class="eem-qe-label" for="eem-qe-slug-' + id + '">' + eemEscHtml('Slug') + '</label>' +
					'<div class="eem-slug-wrap">' +
						'<span class="eem-slug-prefix">/reservation/</span>' +
						'<input type="text" class="eem-field-input eem-slug-input eem-qe-slug" id="eem-qe-slug-' + id + '" value="" autocomplete="off">' +
					'</div>' +
					'<p class="eem-field-hint">' + eemEscHtml('Clear to auto-generate from name.') + '</p>' +
				'</div>' +
			'</div>' +
			'<div class="eem-qe-actions">' +
				'<button type="button" class="eem-btn eem-btn-electric eem-qe-save" data-eem-action="reservation-quick-edit-save" data-reservation-id="' + eemEscAttr(id) + '">' + eemEscHtml('Save') + '</button>' +
				'<button type="button" class="eem-btn eem-qe-cancel" data-eem-action="reservation-quick-edit-cancel" data-reservation-id="' + eemEscAttr(id) + '">' + eemEscHtml('Cancel') + '</button>' +
			'</div>' +
		'</div></td>';
	return tr;
}

function saveQuickEdit(target) {
	var id  = target.dataset.reservationId;
	var qr  = document.querySelector('.eem-quick-edit-row[data-quick-edit-id="' + id + '"]');
	if (!qr) return;
	var name    = qr.querySelector('.eem-qe-name').value.trim();
	var slug    = qr.querySelector('.eem-qe-slug').value.trim();
	var nonce   = window.eemRowActions && window.eemRowActions.nonces
		? (window.eemRowActions.nonces.eem_reservation_quick_edit || '')
		: '';
	var saveBtn = qr.querySelector('[data-eem-action="reservation-quick-edit-save"]');
	if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

	var fd = new FormData();
	fd.append('action',              'eem_reservation_quick_edit');
	fd.append('reservation_id',      id);
	fd.append('eem_res_name',        name);
	fd.append('eem_res_slug',        slug);
	fd.append('_eem_quick_edit_nonce', nonce);

	var ajaxUrl = typeof window.ajaxurl !== 'undefined'
		? window.ajaxurl
		: (window.eemRowActions ? window.eemRowActions.adminPostUrl.replace('admin-post.php', 'admin-ajax.php') : '');

	fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
		.then(function (r) { return r.json(); })
		.then(function (json) {
			if (json.success) {
				var row = document.querySelector('tr[data-reservation-id="' + id + '"]');
				if (row && json.data && json.data.name) {
					var nameEl = row.querySelector('.eem-res-name');
					if (nameEl) nameEl.textContent = json.data.name;
				}
				closeQuickEdit(id);
				EEM.showSaveToast(json.data && json.data.message ? json.data.message : 'Reservation updated.');
			} else {
				if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
				EEM.showSaveToast(
					(json.data && json.data.message) ? json.data.message : 'Save failed.',
					{ type: 'error' }
				);
			}
		})
		.catch(function () {
			if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
			EEM.showSaveToast('Save failed — please try again.', { type: 'error' });
		});
}

function closeQuickEdit(id) {
	var qr = document.querySelector('.eem-quick-edit-row[data-quick-edit-id="' + id + '"]');
	if (qr) qr.remove();
}

function closeAnyOpenQuickEdit() {
	document.querySelectorAll('.eem-quick-edit-row').forEach(function (qr) { qr.remove(); });
}

/** Minimal HTML-attribute escaping for JS-generated markup. */
function eemEscAttr(str) {
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#x27;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');
}

/** Minimal HTML entity escaping for JS-generated text nodes. */
function eemEscHtml(str) {
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');
}

/* ─────────────────────────────────────────────────────────────
 * FIX 1 (2.3.43) — Pencil inline-edit for reservation name
 *   in the editor page header.
 *
 * Flow:
 *   1. Click pencil icon → `openResNameEdit()` hides the h1 view
 *      and reveals the inline input + Save / Cancel buttons.
 *   2. "Save" → `saveResNameEdit()` POSTs to wp_ajax_eem_rename_reservation.
 *      On success, updates the h1 text and restores the view state.
 *   3. "Cancel" → `cancelResNameEdit()` restores the view without saving.
 * ───────────────────────────────────────────────────────────── */

function openResNameEdit() {
	var view   = document.getElementById('eem-res-name-view');
	var editor = document.getElementById('eem-res-name-inline-edit');
	if (!view || !editor) return;
	view.style.display   = 'none';
	editor.style.display = 'flex';
	var input = document.getElementById('eem-res-name-inline-input');
	if (input) { input.focus(); input.select(); }
}

function saveResNameEdit(target) {
	var editor = document.getElementById('eem-res-name-inline-edit');
	if (!editor) return;
	var input  = document.getElementById('eem-res-name-inline-input');
	var name   = input ? input.value.trim() : '';
	var resId  = editor.dataset.reservationId;
	var nonce  = editor.dataset.renameNonce;

	/* Disable save while in flight. */
	var saveBtn = target;
	if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

	var fd = new FormData();
	fd.append('action',           'eem_rename_reservation');
	fd.append('reservation_id',   resId);
	fd.append('eem_res_name',     name);
	fd.append('_eem_rename_nonce', nonce);

	var ajaxUrl = typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '';

	fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
		.then(function (r) { return r.json(); })
		.then(function (json) {
			if (json.success) {
				/* Update the h1 display text to the resolved name. */
				var h1 = document.getElementById('eem-header-event-name');
				if (h1 && json.data && json.data.name) {
					/* Replace all text nodes in the h1 (avoid overwriting the button). */
					h1.childNodes.forEach(function (node) {
						if (node.nodeType === Node.TEXT_NODE) {
							node.textContent = json.data.name + ' ';
						}
					});
				}
				/* Also sync the input's own value so Cancel → re-edit shows current name. */
				if (input) input.value = json.data && json.data.name ? json.data.name : name;
				cancelResNameEdit();
				EEM.showSaveToast(json.data && json.data.message ? json.data.message : 'Name saved.');
			} else {
				if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
				EEM.showSaveToast(
					(json.data && json.data.message) ? json.data.message : 'Save failed.',
					{ type: 'error' }
				);
			}
		})
		.catch(function () {
			if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
			EEM.showSaveToast('Save failed — please try again.', { type: 'error' });
		});
}

function cancelResNameEdit() {
	var view   = document.getElementById('eem-res-name-view');
	var editor = document.getElementById('eem-res-name-inline-edit');
	if (!view || !editor) return;
	editor.style.display = 'none';
	view.style.display   = 'flex';
}

/* ─────────────────────────────────────────────────────────────
 * FIX 5 (2.3.43) — Duplicate row action via AJAX.  Returns a
 * redirect URL pointing at the new reservation's Edit page.
 * ───────────────────────────────────────────────────────────── */

function duplicateReservationAjax(target) {
	var id    = target.dataset.reservationId;
	if (!id) return;
	var nonce = window.eemRowActions && window.eemRowActions.nonces
		? (window.eemRowActions.nonces.eem_reservation_duplicate || '')
		: '';

	/* Disable the clicked link while in-flight to prevent double-click. */
	target.style.pointerEvents = 'none';
	target.style.opacity       = '0.5';

	var ajaxUrl = typeof window.ajaxurl !== 'undefined'
		? window.ajaxurl
		: (window.eemRowActions ? window.eemRowActions.adminPostUrl.replace('admin-post.php', 'admin-ajax.php') : '');

	var fd = new FormData();
	fd.append('action',           'eem_reservation_duplicate_ajax');
	fd.append('reservation_id',   id);
	fd.append('_eem_action_nonce', nonce);

	fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
		.then(function (r) { return r.json(); })
		.then(function (json) {
			// FIX 1 (2.3.44) — stay on list page; reload so new draft row appears.
			if (json.success && json.data && json.data.new_reservation_id) {
				EEM.showSaveToast(json.data.message || 'Reservation duplicated as draft.');
				setTimeout(function () { window.location.reload(); }, 1200);
			} else {
				target.style.pointerEvents = '';
				target.style.opacity       = '';
				EEM.showSaveToast(
					(json.data && json.data.message) ? json.data.message : 'Duplicate failed.',
					{ type: 'error' }
				);
			}
		})
		.catch(function () {
			target.style.pointerEvents = '';
			target.style.opacity       = '';
			EEM.showSaveToast('Duplicate failed — please try again.', { type: 'error' });
		});
}

})();

/* ===== C15.D — Reports filter UX (date presets, custom-flip, localStorage,
   live export-filter sync). Self-contained; only runs on the Reports page. ===== */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}

	ready(function () {
		var form = document.getElementById('eem-reports-filters');
		if (!form) { return; }

		var STORAGE_KEY  = 'eem_reports_filter_state';
		var preset       = form.querySelector('[data-eem-date-preset]');
		var dateInputs   = Array.prototype.slice.call(form.querySelectorAll('[data-eem-date-input]'));
		var fromInput    = dateInputs[0] || null;
		var toInput      = dateInputs[1] || null;
		var resSelect    = form.querySelector('[name="reservation_id"]');
		var statusSelect = form.querySelector('[name="status"]');

		function pad(n) { return (n < 10 ? '0' : '') + n; }
		function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

		function currentState() {
			return {
				reservation_id: resSelect ? resSelect.value : '0',
				date_preset: preset ? preset.value : 'custom',
				date_from: fromInput ? fromInput.value : '',
				date_to: toInput ? toInput.value : '',
				status: statusSelect ? statusSelect.value : ''
			};
		}

		// Push the live filter state into every export form's hidden inputs +
		// persist to localStorage, so an export uses what's on screen now.
		function syncExports() {
			var s = currentState();
			Array.prototype.forEach.call(document.querySelectorAll('[data-eem-export-filter]'), function (inp) {
				var key = inp.getAttribute('data-eem-export-filter');
				if (Object.prototype.hasOwnProperty.call(s, key)) { inp.value = s[key]; }
			});
			try { localStorage.setItem(STORAGE_KEY, JSON.stringify(s)); } catch (e) {}
		}

		function applyPreset(val) {
			if (val === 'custom') { syncExports(); return; }
			if (val === 'all') {
				if (fromInput) { fromInput.value = ''; }
				if (toInput) { toInput.value = ''; }
				syncExports();
				return;
			}
			var to = new Date();
			var from = new Date();
			if (val === 'last-7') { from.setDate(to.getDate() - 6); }
			else if (val === 'last-30') { from.setDate(to.getDate() - 29); }
			else if (val === 'last-90') { from.setDate(to.getDate() - 89); }
			else if (val === 'this-year') { from = new Date(to.getFullYear(), 0, 1); }
			if (fromInput) { fromInput.value = ymd(from); }
			if (toInput) { toInput.value = ymd(to); }
			syncExports();
		}

		function flipToCustom() {
			if (preset) { preset.value = 'custom'; }
			syncExports();
		}

		function restore() {
			// Honor explicit URL filters over any saved state.
			if (/[?&](reservation_id|date_from|date_to|status)=/.test(window.location.search)) { syncExports(); return; }
			var raw = null;
			try { raw = localStorage.getItem(STORAGE_KEY); } catch (e) {}
			if (!raw) { syncExports(); return; }
			var s = null;
			try { s = JSON.parse(raw); } catch (e) {}
			if (s && typeof s === 'object') {
				if (resSelect && s.reservation_id != null) { resSelect.value = s.reservation_id; }
				if (preset && s.date_preset) { preset.value = s.date_preset; }
				if (fromInput && s.date_from != null) { fromInput.value = s.date_from; }
				if (toInput && s.date_to != null) { toInput.value = s.date_to; }
				if (statusSelect && s.status != null) { statusSelect.value = s.status; }
			}
			syncExports();
		}

		if (preset) { preset.addEventListener('change', function () { applyPreset(preset.value); }); }
		dateInputs.forEach(function (inp) { inp.addEventListener('change', flipToCustom); });
		if (resSelect) { resSelect.addEventListener('change', syncExports); }
		if (statusSelect) { statusSelect.addEventListener('change', syncExports); }

		restore();
	});
})();
