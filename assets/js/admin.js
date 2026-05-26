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

	function toggleDropdown(trigger) {
		var host = trigger.closest('.eem-dropdown, .eem-row-menu-wrap');
		if (!host) return;
		var isOpen = host.classList.contains('open');
		closeAllDropdowns();
		if (!isOpen) host.classList.add('open');
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
	function submitReservationAction(target, actionName, nonceAction) {
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
		[
			['action', actionName],
			['reservation_id', reservationId],
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

	function closeAllDropdowns() {
		document.querySelectorAll('.eem-dropdown.open, .eem-row-menu-wrap.open')
			.forEach(function (host) { host.classList.remove('open'); });
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
	document.addEventListener('input', function (ev) {
		var input = ev.target;
		if (input.matches && input.matches('.eem-tag-search')) {
			EEM.tagFilter(input);
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
		var input = document.querySelector('.eem-save-bar input[name="_eem_editor_nonce"]');
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
		body.querySelectorAll('input[name^="en_reservation"], select[name^="en_reservation"], textarea[name^="en_reservation"]').forEach(function (el) {
			if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
			// C7.C.1.1 — hidden section-enabled mirrors with value="0"
			// must be SKIPPED to mirror unchecked-checkbox behavior the
			// legacy save_meta() sanitizer expects (`isset($source[X])
			// ? 1 : 0` pattern — presence means on, absence means off).
			// Without this, every section header toggle would persist
			// as "on" because the hidden input is always present.
			if (el.type === 'hidden' && el.hasAttribute('data-eem-section-enabled') && '1' !== el.value) return;
			out.push([el.name, el.value]);
		});
		return out;
	}

	function eemDispatchSave(kind) {
		var bar = document.querySelector('.eem-save-bar');
		if (!bar) return;
		var rid = bar.getAttribute('data-eem-reservation-id');
		if (!rid) return;
		var body = new URLSearchParams();
		body.set('action', 'eem_reservation_editor_save');
		body.set('_eem_editor_nonce', eemReservationEditorNonce());
		body.set('reservation_id', rid);
		body.set('save_kind', kind);
		eemCollectEditorFields().forEach(function (pair) { body.append(pair[0], pair[1]); });
		fetch(EEM_EDITOR_AJAX_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (resp) {
			if (resp && resp.success) {
				eemSaveBarToast(resp.data && resp.data.message ? resp.data.message : 'Saved.', 'success');
				eemUpdateSaveBarButtons(resp.data && resp.data.primary_action ? resp.data.primary_action : 'draft');
			} else {
				// C7.C.1.1 — surface the actual validation error from
				// the server, not a generic "Save failed." that hid the
				// real reason. ajax_save now returns wp_send_json_error
				// with `message` carrying the first validation-error
				// string and `errors` carrying the full list.
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
				eemSaveBarToast(msg, 'error');
			}
		}).catch(function () {
			eemSaveBarToast('Could not reach the server.', 'error');
		});
	}

	function eemUpdateSaveBarButtons(primary) {
		var bar = document.querySelector('.eem-save-bar');
		if (!bar) return;
		var primaryContainer = bar.querySelector('.eem-save-bar__primary');
		if (!primaryContainer) return;
		if ('update' === primary) {
			primaryContainer.innerHTML =
				'<button type="button" class="eem-btn eem-btn-savebar-update" data-eem-action="reservation-editor-update">Update</button>';
		} else {
			primaryContainer.innerHTML =
				'<button type="button" class="eem-btn eem-btn-savebar-draft" data-eem-action="reservation-editor-save-draft">Save Draft</button>' +
				' <button type="button" class="eem-btn eem-btn-savebar-publish" data-eem-action="reservation-editor-publish">Publish</button>';
		}
	}

	/* Save bar click handlers */
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (!t || !t.closest) return;
		if (t.closest('[data-eem-action="reservation-editor-save-draft"]')) { ev.preventDefault(); eemDispatchSave('save_draft'); return; }
		if (t.closest('[data-eem-action="reservation-editor-publish"]'))    { ev.preventDefault(); eemDispatchSave('publish');    return; }
		if (t.closest('[data-eem-action="reservation-editor-update"]'))     { ev.preventDefault(); eemDispatchSave('update');     return; }
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
			var container = addBtn.closest('.eem-repeating-row-helper');
			if (!container) return;
			var templateId = container.getAttribute('data-eem-repeating-template');
			var tbodyId    = container.getAttribute('data-eem-repeating-tbody');
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
})();
