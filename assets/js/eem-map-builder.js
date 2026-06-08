/**
 * EEM Native Map Builder
 * ----------------------
 * In-plugin, spreadsheet-free facility-map authoring for the Edit Reservation
 * editor. Replaces the Google-Sheet connector. Opened from a section's
 * "Build / Edit Map" button via EEM.openMapBuilder(target); hydrates from a
 * `<script type="application/json" id="eem-map-seed-{target}">` seed, lets the
 * admin draw zones/stalls/landmarks (drag-select + fill-series), and saves the
 * built grid to the eem_map_builder_save AJAX handler — which snapshots it into
 * the same _en_stall_map / _en_rv_map shape every consumer already reads.
 *
 * target 'stall' speaks barns/stalls; 'rv' speaks zones/lots (zone-qualified —
 * lot numbers may repeat across zones).
 */
(function () {
	'use strict';
	if (!window.EEM) { window.EEM = {}; }

	// ---- module state (one builder open at a time) ----
	var B = {
		zones: [], active: 0, tool: 'fill', sel: null, drag: null,
		history: [], future: [],
		fill: { start: '1', step: 1, dir: 'lr' },
		lm: { name: 'Wash Rack' },
		target: 'stall', overlay: null, dirty: false,
		inline: false, host: null, zoom: 1
	};
	var ZMIN = 0.5, ZMAX = 1.8, ZBASE_C = 46, ZBASE_R = 42;

	function noun(plural) {
		var rv = B.target === 'rv';
		return plural ? (rv ? 'lots' : 'stalls') : (rv ? 'lot' : 'stall');
	}
	function zoneNoun(plural) {
		var rv = B.target === 'rv';
		return plural ? (rv ? 'zones' : 'barns') : (rv ? 'zone' : 'barn');
	}

	function mkGrid(rows, cols) {
		var g = [];
		for (var r = 0; r < rows; r++) { g.push([]); for (var c = 0; c < cols; c++) { g[r].push({ type: 'gap', label: '' }); } }
		return g;
	}
	function Z() { return B.zones[B.active]; }
	function snapshot() { B.history.push(JSON.stringify(B.zones)); if (B.history.length > 60) { B.history.shift(); } B.future = []; B.dirty = true; updateUndo(); }
	function updateUndo() {
		var u = q('#eem-mb-undo'), rd = q('#eem-mb-redo');
		if (u) { u.disabled = !B.history.length; }
		if (rd) { rd.disabled = !B.future.length; }
	}
	function q(sel) { return B.overlay ? B.overlay.querySelector(sel) : null; }
	function qa(sel) { return B.overlay ? Array.prototype.slice.call(B.overlay.querySelectorAll(sel)) : []; }

	function countStalls(z) { var n = 0; z.grid.forEach(function (row) { row.forEach(function (c) { if (c.type === 'stall') { n++; } }); }); return n; }
	function totalStalls() { var s = 0; B.zones.forEach(function (z) { s += countStalls(z); }); return s; }
	function findDups(z) {
		// RV lots repeat per zone, so dup-highlighting only applies to stalls.
		if (B.target === 'rv') { return {}; }
		var seen = {}, dups = {};
		B.zones.forEach(function (zz) { zz.grid.forEach(function (row) { row.forEach(function (c) { if (c.type === 'stall') { if (seen[c.label]) { dups[c.label] = 1; } seen[c.label] = 1; } }); }); });
		return dups;
	}
	function sameLm(z, r, c, label) { return r >= 0 && c >= 0 && r < z.grid.length && c < z.grid[0].length && z.grid[r][c].type === 'landmark' && z.grid[r][c].label === label; }
	function inSel(r, c) { if (!B.sel) { return false; } var s = B.sel; return r >= Math.min(s.r1, s.r2) && r <= Math.max(s.r1, s.r2) && c >= Math.min(s.c1, s.c2) && c <= Math.max(s.c1, s.c2); }

	// Fit the active zone's grid width to the visible scroll viewport so the whole
	// map is in view; zoom in/out then pans within the capped viewport.
	function fitZoom() {
		var z = Z(); if (!z) { return; }
		var cols = z.grid[0] ? z.grid[0].length : 0;
		var scroll = q('#eem-mb-gridscroll') || q('.eem-mb-gridscroll');
		if (!cols || !scroll) { B.zoom = 1; render(); return; }
		var avail = scroll.clientWidth - 28; // padding allowance
		var want = avail / (cols * ZBASE_C);
		B.zoom = Math.max(ZMIN, Math.min(1, want));
		render();
	}

	function render() {
		var z = Z();
		var rv = q('#eem-mb-rowval'), cv = q('#eem-mb-colval');
		if (rv) { rv.textContent = z.grid.length; }
		if (cv) { cv.textContent = z.grid[0] ? z.grid[0].length : 0; }
		var dups = findDups(z);
		var g = q('#eem-mb-grid');
		var cols = z.grid[0] ? z.grid[0].length : 0;
		var cw = Math.round(ZBASE_C * B.zoom), ch = Math.round(ZBASE_R * B.zoom);
		g.style.gridTemplateColumns = 'repeat(' + cols + ',' + cw + 'px)';
		g.style.gridAutoRows = ch + 'px';
		g.innerHTML = '';
		var consumed = {};
		for (var r = 0; r < z.grid.length; r++) {
			for (var c = 0; c < cols; c++) {
				if (consumed[r + ',' + c]) { continue; }        // covered by a merged landmark
				var cell = z.grid[r][c];
				var d = document.createElement('div');
				d.setAttribute('data-r', r); d.setAttribute('data-c', c);
				d.style.gridColumn = (c + 1);
				d.style.gridRow = (r + 1);
				if (cell.type === 'landmark') {
					// collapse the same-label rectangle into ONE spanning block so the
					// label spans the whole region (no per-cell clipping) and covers the
					// internal gridlines.
					var w = 1; while (sameLm(z, r, c + w, cell.label)) { w++; }
					var h = 1; while (sameLm(z, r + h, c, cell.label)) { h++; }
					for (var rr = r; rr < r + h; rr++) { for (var cc = c; cc < c + w; cc++) { consumed[rr + ',' + cc] = 1; } }
					d.className = 'eem-mb-cell landmark';
					d.style.gridColumn = (c + 1) + ' / span ' + w;
					d.style.gridRow = (r + 1) + ' / span ' + h;
					if (h > w) { d.classList.add('vert'); }
					var lab = document.createElement('span'); lab.className = 'eem-mb-lmlabel'; lab.textContent = cell.label; d.appendChild(lab);
				} else if (cell.type === 'stall') {
					d.className = 'eem-mb-cell stall';
					if (inSel(r, c)) { d.classList.add('sel'); }
					if (dups[cell.label]) { d.classList.add('dup'); }
					d.textContent = cell.label;
				} else {
					d.className = 'eem-mb-cell gap';
					if (inSel(r, c)) { d.classList.add('sel'); }
				}
				g.appendChild(d);
			}
		}
		renderTabs(); updateCount(); updateUndo();
	}

	function renderTabs() {
		var t = q('#eem-mb-tabs'); if (!t) { return; }
		t.innerHTML = '';
		B.zones.forEach(function (z, i) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'eem-mb-tab' + (i === B.active ? ' active' : '');
			b.innerHTML = escapeHtml(z.name) + (B.zones.length > 1 ? ' <span class="x" aria-label="Delete">&times;</span>' : '');
			b.onclick = function (ev) {
				if (ev.target.classList.contains('x')) {
					mbConfirm('Delete ' + zoneNoun(false) + ' “' + z.name + '”? This removes its ' + countStalls(z) + ' ' + noun(true) + '.', function () {
						snapshot(); B.zones.splice(i, 1); B.active = Math.max(0, B.active - (i <= B.active ? 1 : 0)); B.sel = null; render(); renderControls();
					});
					return;
				}
				B.active = i; B.sel = null; render(); renderControls();
			};
			b.ondblclick = function () { mbPrompt('Rename ' + zoneNoun(false), z.name, function (n) { snapshot(); z.name = n; render(); }); };
			t.appendChild(b);
		});
		var add = document.createElement('button');
		add.type = 'button'; add.className = 'eem-mb-tab-add';
		add.textContent = '+ Add ' + cap(zoneNoun(false));
		add.onclick = function () {
			mbPrompt(cap(zoneNoun(false)) + ' name', cap(zoneNoun(false)) + ' ' + (B.zones.length + 1), function (n) {
				snapshot(); B.zones.push({ name: n, grid: mkGrid(5, 10) }); B.active = B.zones.length - 1; B.sel = null; render(); renderControls();
			});
		};
		t.appendChild(add);
	}

	function updateCount() {
		var el = q('#eem-mb-count');
		if (el) { el.textContent = B.zones.length + ' ' + zoneNoun(B.zones.length !== 1) + ' · ' + totalStalls() + ' ' + noun(true); }
	}

	// ---- fill series ----
	function parseStart(s) { var m = String(s).match(/^(.*?)(\d+)$/); return m ? { prefix: m[1], num: parseInt(m[2], 10), pad: m[2].length } : { prefix: s, num: null, pad: 0 }; }
	function nextLabel(p, i, step) { if (p.num === null) { return p.prefix + (i ? i + 1 : ''); } var s = String(p.num + i * step); while (s.length < p.pad) { s = '0' + s; } return p.prefix + s; }
	function applyFill() {
		if (!B.sel) { toast('Drag a block of cells first'); return; }
		var z = Z(), s = B.sel;
		var r1 = Math.min(s.r1, s.r2), r2 = Math.max(s.r1, s.r2), c1 = Math.min(s.c1, s.c2), c2 = Math.max(s.c1, s.c2);
		var order = [], r, c;
		if (B.fill.dir === 'lr') { for (r = r1; r <= r2; r++) { for (c = c1; c <= c2; c++) { order.push([r, c]); } } }
		else if (B.fill.dir === 'rl') { for (r = r1; r <= r2; r++) { for (c = c2; c >= c1; c--) { order.push([r, c]); } } }
		else if (B.fill.dir === 'tb') { for (c = c1; c <= c2; c++) { for (r = r1; r <= r2; r++) { order.push([r, c]); } } }
		else { for (c = c1; c <= c2; c++) { for (r = r2; r >= r1; r--) { order.push([r, c]); } } }
		var p = parseStart(B.fill.start || '1'), step = B.fill.step || 1;
		snapshot();
		// Fill every selected cell — the admin selects exactly what should be a
		// stall/lot; anything they don't select stays an aisle.
		var i = 0;
		order.forEach(function (rc) {
			z.grid[rc[0]][rc[1]] = { type: 'stall', label: nextLabel(p, i, step) }; i++;
		});
		toast('Filled ' + i + ' ' + noun(true) + ' (' + (B.fill.start || '1') + '→' + nextLabel(p, i - 1, step) + ')');
		B.fill.start = nextLabel(p, i, step);
		B.sel = null; render(); renderControls();
	}

	// ---- landmark ----
	function applyLandmark() {
		if (!B.sel) { toast('Drag a block of cells first'); return; }
		var name = B.lm.name.trim(); if (!name) { toast('Name the landmark first'); return; }
		var z = Z(), s = B.sel;
		var r1 = Math.min(s.r1, s.r2), r2 = Math.max(s.r1, s.r2), c1 = Math.min(s.c1, s.c2), c2 = Math.max(s.c1, s.c2);
		snapshot();
		for (var r = r1; r <= r2; r++) { for (var c = c1; c <= c2; c++) { z.grid[r][c] = { type: 'landmark', label: name }; } }
		toast('Added "' + name + '"'); B.sel = null; render(); renderControls();
	}

	// ---- erase / clear / resize ----
	function eraseCell(r, c) { Z().grid[r][c] = { type: 'gap', label: '' }; }
	function clearGrid() {
		var z = Z(), n = countStalls(z);
		var doClear = function () {
			snapshot();
			var rows = z.grid.length, cols = z.grid[0] ? z.grid[0].length : 0;
			z.grid = mkGrid(rows, cols); B.sel = null; B.fill.start = '1'; render(); renderControls(); toast('Cleared “' + z.name + '”');
		};
		if (n) { mbConfirm('Clear all ' + n + ' ' + noun(true) + ' from “' + z.name + '”? You can Undo.', doClear); } else { doClear(); }
	}
	function resizeGrid(which, delta) {
		var z = Z(), cols = z.grid[0] ? z.grid[0].length : 0, rows = z.grid.length, lost;
		if (which === 'row') {
			if (delta < 0) {
				if (rows <= 1) { return; }
				lost = z.grid[rows - 1].filter(function (c) { return c.type === 'stall'; }).length;
				var doRow = function () { snapshot(); z.grid.pop(); B.sel = null; render(); };
				if (lost) { mbConfirm('Removing this row deletes ' + lost + ' labeled ' + noun(true) + '. Continue?', doRow); } else { doRow(); }
			} else { snapshot(); z.grid.push(mkGrid(1, cols)[0]); B.sel = null; render(); }
		} else {
			if (delta < 0) {
				if (cols <= 1) { return; }
				lost = z.grid.filter(function (row) { return row[cols - 1].type === 'stall'; }).length;
				var doCol = function () { snapshot(); z.grid.forEach(function (row) { row.pop(); }); B.sel = null; render(); };
				if (lost) { mbConfirm('Removing this column deletes ' + lost + ' labeled ' + noun(true) + '. Continue?', doCol); } else { doCol(); }
			} else { snapshot(); z.grid.forEach(function (row) { row.push({ type: 'gap', label: '' }); }); B.sel = null; render(); }
		}
	}

	function undo() { if (!B.history.length) { return; } B.future.push(JSON.stringify(B.zones)); B.zones = JSON.parse(B.history.pop()); B.active = Math.min(B.active, B.zones.length - 1); B.sel = null; render(); renderControls(); updateUndo(); }
	function redo() { if (!B.future.length) { return; } B.history.push(JSON.stringify(B.zones)); B.zones = JSON.parse(B.future.pop()); B.active = Math.min(B.active, B.zones.length - 1); B.sel = null; render(); renderControls(); updateUndo(); }

	// ---- inline contextual controls ----
	function renderControls() {
		var p = q('#eem-mb-controls'); if (!p) { return; }
		if (B.tool === 'fill') {
			p.innerHTML =
				'<label class="eem-mb-tc">Start <input type="text" id="eem-mb-fstart" class="eem-mb-input" value="' + escapeAttr(B.fill.start) + '" placeholder="1, A-01, Y1…"></label>' +
				'<label class="eem-mb-tc">Step <input type="number" id="eem-mb-fstep" class="eem-mb-num" value="' + B.fill.step + '" min="1"></label>' +
				'<span class="eem-mb-seg4" id="eem-mb-fdir">' +
					seg('lr', '→ L→R') + seg('rl', '← R→L') + seg('tb', '↓ T→B') + seg('bt', '↑ B→T') +
				'</span>' +
				'<button type="button" class="eem-mb-apply" id="eem-mb-apply">Apply Fill</button>' +
				'<span class="eem-mb-selmeta">No selection</span>';
			q('#eem-mb-fstart').addEventListener('input', function (e) { B.fill.start = e.target.value; });
			q('#eem-mb-fstep').addEventListener('input', function (e) { B.fill.step = parseInt(e.target.value || '1', 10); });
			q('#eem-mb-apply').addEventListener('click', applyFill);
			q('#eem-mb-fdir').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) { return; } qa('#eem-mb-fdir button').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); B.fill.dir = b.getAttribute('data-dir'); });
		} else if (B.tool === 'landmark') {
			p.innerHTML =
				'<label class="eem-mb-tc">Label <input type="text" id="eem-mb-lmname" class="eem-mb-input eem-mb-input-wide" value="' + escapeAttr(B.lm.name) + '" placeholder="Wash Rack, Office, Arena…"></label>' +
				'<button type="button" class="eem-mb-apply" id="eem-mb-lmapply">Mark as Landmark</button>' +
				'<span class="eem-mb-selmeta">No selection</span>';
			q('#eem-mb-lmname').addEventListener('input', function (e) { B.lm.name = e.target.value; });
			q('#eem-mb-lmapply').addEventListener('click', applyLandmark);
		} else {
			p.innerHTML = '';
		}
		updateSelMeta();
	}
	function seg(dir, label) { return '<button type="button" class="' + (B.fill.dir === dir ? 'on' : '') + '" data-dir="' + dir + '">' + label + '</button>'; }
	function updateSelMeta() {
		var el = q('.eem-mb-selmeta'); if (!el) { return; }
		if (!B.sel) { el.textContent = 'No selection'; return; }
		var r = Math.abs(B.sel.r1 - B.sel.r2) + 1, c = Math.abs(B.sel.c1 - B.sel.c2) + 1;
		el.textContent = r + '×' + c + ' selected (' + (r * c) + ' cells)';
	}

	// ---- label inline edit ----
	function startLabelEdit(r, c) {
		var z = Z(), cols = z.grid[0].length, idx = r * cols + c;
		var cellEl = q('#eem-mb-grid').children[idx];
		var cur = z.grid[r][c].type === 'gap' ? '' : z.grid[r][c].label;
		cellEl.innerHTML = '<input class="eem-mb-celledit" value="' + escapeAttr(cur) + '">';
		var inp = cellEl.querySelector('input'); inp.focus(); inp.select();
		var commit = function () { var v = inp.value.trim(); snapshot(); z.grid[r][c] = v ? { type: 'stall', label: v } : { type: 'gap', label: '' }; render(); };
		inp.addEventListener('blur', commit);
		inp.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
			if (ev.key === 'Escape') { inp.value = cur; inp.blur(); }
		});
	}

	// ---- toast ----
	var toastT;
	function toast(m) {
		if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast(m, { variant: 'info', sub: '' }); return; }
		var t = q('#eem-mb-toast'); if (!t) { return; } t.textContent = m; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove('show'); }, 2200);
	}

	// ---- helpers ----
	function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
	function escapeHtml(s) { return String(s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
	function escapeAttr(s) { return String(s).replace(/"/g, '&quot;'); }

	// ---- modal build / open / close ----
	function buildModal() {
		var o = document.createElement('div');
		o.className = 'eem-mb-overlay';
		o.innerHTML =
			'<div class="eem-mb-card">' +
				'<div class="eem-mb-head">' +
					'<div><h2 class="eem-mb-title"></h2><p class="eem-mb-sub">Draw your facility once. Number ' + noun(true) + ' by dragging.</p></div>' +
					'<div class="eem-mb-head-actions"><span class="eem-mb-pill" id="eem-mb-count"></span>' +
						'<button type="button" class="eem-mb-btn" id="eem-mb-preview">Preview</button>' +
						'<button type="button" class="eem-mb-btn" id="eem-mb-cancel">Cancel</button>' +
						'<button type="button" class="eem-mb-btn eem-mb-btn-primary" id="eem-mb-save">Save Map</button></div>' +
				'</div>' +
				'<div class="eem-mb-legend">' +
					'<span class="eem-mb-lg"><i class="eem-mb-sw stall"></i> ' + cap(noun(false)) + ' — sellable</span>' +
					'<span class="eem-mb-lg"><i class="eem-mb-sw gap"></i> Aisle — not sellable</span>' +
					'<span class="eem-mb-lg"><i class="eem-mb-sw lm"></i> Landmark</span>' +
					'<span class="eem-mb-lg"><i class="eem-mb-sw sel"></i> Selection</span>' +
					'<span class="eem-mb-flow">Add a ' + cap(zoneNoun(false)) + ' → Erase aisles → Fill a row → mark Landmarks → Save</span>' +
				'</div>' +
				'<div class="eem-mb-body">' +
					'<div class="eem-mb-toolbar">' +
						tool('select', 'Select', '<path d="M5 3l14 9-6 1.5L11 20 5 3z"/>') +
						tool('fill', 'Fill', '<path d="M4 7h6M4 12h10M4 17h7"/><path d="M16 5l4 4-9 9-4 1 1-4 8-10z"/>') +
						tool('label', 'Label', '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/>') +
						tool('landmark', 'Landmark', '<path d="M20.6 13.4 13.4 20.6a2 2 0 01-2.8 0L3 13V3h10l7.6 7.6a2 2 0 010 2.8z"/><circle cx="8" cy="8" r="1.4"/>') +
						tool('erase', 'Erase', '<path d="M7 21h10M5 13l6-6 8 8-6 6H9l-4-4z"/>') +
						act('clear', 'Clear', '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/>') +
						'<div class="eem-mb-sep"></div>' +
						act('undo', 'Undo', '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 015 5v0a5 5 0 01-5 5H9"/>', 'eem-mb-undo') +
						act('redo', 'Redo', '<path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 00-5 5v0a5 5 0 005 5h6"/>', 'eem-mb-redo') +
					'</div>' +
					'<div class="eem-mb-stage">' +
						'<div class="eem-mb-tabs" id="eem-mb-tabs"></div>' +
						'<div class="eem-mb-gridbar">' +
							'<span class="eem-mb-step">Rows <button type="button" data-resize="row" data-d="-1">−</button><span id="eem-mb-rowval">0</span><button type="button" data-resize="row" data-d="1">+</button></span>' +
							'<span class="eem-mb-step">Cols <button type="button" data-resize="col" data-d="-1">−</button><span id="eem-mb-colval">0</span><button type="button" data-resize="col" data-d="1">+</button></span>' +
							'<span class="eem-mb-zoom"><button type="button" data-zoom="out" title="Zoom out">−</button><button type="button" data-zoom="fit" title="Fit">Fit</button><button type="button" data-zoom="in" title="Zoom in">+</button></span>' +
							'<span class="eem-mb-controls" id="eem-mb-controls"></span>' +
						'</div>' +
						'<div class="eem-mb-gridscroll"><div class="eem-mb-grid" id="eem-mb-grid"></div></div>' +
					'</div>' +
				'</div>' +
				'<div class="eem-mb-toast" id="eem-mb-toast"></div>' +
			'</div>';
		B.overlay = o;
		if (B.inline && B.host) {
			o.classList.add('eem-mb-inline');
			B.host.innerHTML = '';
			B.host.appendChild(o);
		} else {
			document.body.appendChild(o);
		}

		// tool selection
		o.querySelector('.eem-mb-toolbar').addEventListener('click', function (e) {
			var b = e.target.closest('[data-tool],[data-act]'); if (!b) { return; }
			if (b.getAttribute('data-act') === 'undo') { return undo(); }
			if (b.getAttribute('data-act') === 'redo') { return redo(); }
			if (b.getAttribute('data-act') === 'clear') { return clearGrid(); }
			B.tool = b.getAttribute('data-tool'); B.sel = null;
			qa('.eem-mb-tool[data-tool]').forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tool') === B.tool); });
			render(); renderControls();
		});
		// resize steppers
		o.querySelector('.eem-mb-gridbar').addEventListener('click', function (e) {
			var z = e.target.closest('[data-zoom]');
			if (z) {
				var act = z.getAttribute('data-zoom');
				if (act === 'in') { B.zoom = Math.min(ZMAX, B.zoom + 0.2); }
				else if (act === 'out') { B.zoom = Math.max(ZMIN, B.zoom - 0.2); }
				else { fitZoom(); return; }
				render();
				return;
			}
			var b = e.target.closest('[data-resize]'); if (!b) { return; }
			resizeGrid(b.getAttribute('data-resize'), parseInt(b.getAttribute('data-d'), 10));
		});
		// drag-select on the grid
		var grid = o.querySelector('#eem-mb-grid');
		grid.addEventListener('mousedown', function (e) {
			var cl = e.target.closest('.eem-mb-cell'); if (!cl) { return; }
			var r = +cl.getAttribute('data-r'), c = +cl.getAttribute('data-c');
			if (B.tool === 'label') { startLabelEdit(r, c); return; }
			if (B.tool === 'erase') { snapshot(); B.drag = { erase: true }; eraseCell(r, c); render(); return; }
			B.drag = { r1: r, c1: c, moved: false }; B.sel = { r1: r, c1: c, r2: r, c2: c }; render(); updateSelMeta();
		});
		grid.addEventListener('mouseover', function (e) {
			if (!B.drag) { return; }
			var cl = e.target.closest('.eem-mb-cell'); if (!cl) { return; }
			var r = +cl.getAttribute('data-r'), c = +cl.getAttribute('data-c');
			if (B.drag.erase) { eraseCell(r, c); render(); return; }
			if (r !== B.drag.r1 || c !== B.drag.c1) { B.drag.moved = true; }
			B.sel = { r1: B.drag.r1, c1: B.drag.c1, r2: r, c2: c }; render(); updateSelMeta();
		});
		document.addEventListener('mouseup', onUp);
		o.querySelector('#eem-mb-preview').addEventListener('click', previewCustomer);
		o.querySelector('#eem-mb-cancel').addEventListener('click', close);
		o.querySelector('#eem-mb-save').addEventListener('click', save);
		// Backdrop click closes only the floating modal; inline lives in the card.
		if (!B.inline) { o.addEventListener('mousedown', function (e) { if (e.target === o) { close(); } }); }
	}
	// On mouse-up: a Select-tool click (no drag) on a single cell opens its label
	// editor — "select something to change a label".
	function onUp() {
		if (B.drag && !B.drag.erase && B.tool === 'select' && !B.drag.moved && B.sel) {
			var r = B.sel.r1, c = B.sel.c1; B.drag = null; startLabelEdit(r, c); return;
		}
		if (B.overlay) { updateSelMeta(); }
		B.drag = null;
	}

	function tool(name, label, path, id) {
		return '<button type="button" class="eem-mb-tool' + (name === 'fill' ? ' active' : '') + '" data-tool="' + name + '"' + (id ? ' id="' + id + '"' : '') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + path + '</svg>' + label + '</button>';
	}
	function act(name, label, path, id) {
		return '<button type="button" class="eem-mb-tool" data-act="' + name + '"' + (id ? ' id="' + id + '"' : '') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + path + '</svg>' + label + '</button>';
	}

	function hydrate(target) {
		B.target = target;
		var seed = document.getElementById('eem-map-seed-' + target);
		var barns = [];
		if (seed) { try { barns = JSON.parse(seed.textContent || '[]'); } catch (e) { barns = []; } }
		if (!Array.isArray(barns) || !barns.length) {
			B.zones = [{ name: cap(zoneNoun(false)) + ' 1', grid: mkGrid(6, 12) }];
		} else {
			B.zones = barns.map(function (b) {
				var grid = (b.grid || []).map(function (row) { return (row || []).map(function (c) { return { type: (c && c.type) || 'gap', label: (c && c.label) || '' }; }); });
				if (!grid.length) { grid = mkGrid(5, 10); }
				return { name: b.name || (cap(zoneNoun(false))), grid: grid };
			});
		}
		B.active = 0; B.sel = null; B.tool = 'fill'; B.history = []; B.future = []; B.dirty = false; B.zoom = 1; B.fill = { start: '1', step: 1, dir: 'lr' }; B.lm = { name: 'Wash Rack' };
	}

	function open(target) {
		if (B.overlay) { close(); }
		hydrate(target);
		B.host = document.querySelector('[data-eem-map-host="' + target + '"]');
		B.inline = !!B.host;
		buildModal();
		q('.eem-mb-title').textContent = (target === 'rv' ? 'RV Map Builder' : 'Stall Map Builder');
		requestAnimationFrame(function () { B.overlay.classList.add('open'); fitZoom(); });
		render(); renderControls();
	}

	function close() {
		if (!B.overlay) { return; }
		document.removeEventListener('mouseup', onUp);
		B.overlay.parentNode.removeChild(B.overlay);
		B.overlay = null;
		B.inline = false; B.host = null;
	}

	// ---- styled prompt / confirm (replaces native window.prompt / .confirm) ----
	function mbDialog(opts) {
		var o = document.createElement('div');
		o.className = 'eem-mb-dialog';
		o.innerHTML =
			'<div class="eem-mb-dialog-card">' +
				'<div class="eem-mb-dialog-msg">' + escapeHtml(opts.message) + '</div>' +
				(opts.input ? '<input type="text" class="eem-mb-dialog-input" value="' + escapeAttr(opts.value || '') + '">' : '') +
				'<div class="eem-mb-dialog-actions">' +
					'<button type="button" class="eem-mb-btn eem-mb-dialog-cancel">Cancel</button>' +
					'<button type="button" class="eem-mb-btn eem-mb-btn-primary eem-mb-dialog-ok">' + escapeHtml(opts.confirmText || 'OK') + '</button>' +
				'</div>' +
			'</div>';
		(B.overlay || document.body).appendChild(o);
		var inp = o.querySelector('.eem-mb-dialog-input');
		if (inp) { inp.focus(); inp.select(); }
		function done(val) { o.parentNode.removeChild(o); if (opts.onConfirm) { opts.onConfirm(val); } }
		o.querySelector('.eem-mb-dialog-ok').addEventListener('click', function () { done(inp ? inp.value.trim() : true); });
		o.querySelector('.eem-mb-dialog-cancel').addEventListener('click', function () { o.parentNode.removeChild(o); });
		o.addEventListener('mousedown', function (e) { if (e.target === o) { o.parentNode.removeChild(o); } });
		if (inp) { inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { done(inp.value.trim()); } if (e.key === 'Escape') { o.parentNode.removeChild(o); } }); }
		requestAnimationFrame(function () { o.classList.add('open'); });
	}
	function mbPrompt(message, value, cb) { mbDialog({ message: message, input: true, value: value, confirmText: 'OK', onConfirm: function (v) { if (v) { cb(v); } } }); }
	function mbConfirm(message, cb) { mbDialog({ message: message, input: false, confirmText: 'Continue', onConfirm: function () { cb(); } }); }

	// ---- customer-view preview ----
	var preview = { overlay: null, active: 0, picked: {} };
	function previewCustomer() {
		preview.active = 0; preview.picked = {};
		var o = document.createElement('div');
		o.className = 'eem-mb-overlay eem-mb-preview-overlay';
		o.innerHTML =
			'<div class="eem-mb-card eem-mb-preview-card">' +
				'<div class="eem-mb-head"><div><h2 class="eem-mb-title">Customer View — Pick Your ' + cap(noun(true)) + '</h2>' +
					'<p class="eem-mb-sub">Exactly what a customer sees, generated from your map. Aisles &amp; landmarks aren’t selectable.</p></div>' +
					'<button type="button" class="eem-mb-btn eem-mb-btn-primary" id="eem-mb-preview-close">Looks good</button></div>' +
				'<div class="eem-mb-preview-zonebar" id="eem-mb-preview-zones"></div>' +
				'<div class="eem-mb-preview-scroll"><div class="eem-mb-preview-grid" id="eem-mb-preview-grid"></div></div>' +
				'<div class="eem-mb-preview-foot"><span id="eem-mb-preview-count"></span></div>' +
			'</div>';
		document.body.appendChild(o);
		preview.overlay = o;
		requestAnimationFrame(function () { o.classList.add('open'); });
		o.querySelector('#eem-mb-preview-close').addEventListener('click', closePreview);
		o.addEventListener('mousedown', function (e) { if (e.target === o) { closePreview(); } });
		renderPreview();
	}
	function closePreview() { if (preview.overlay) { preview.overlay.parentNode.removeChild(preview.overlay); preview.overlay = null; } }
	function renderPreview() {
		var bar = preview.overlay.querySelector('#eem-mb-preview-zones'); bar.innerHTML = '';
		B.zones.forEach(function (z, i) {
			var b = document.createElement('button'); b.type = 'button';
			b.className = 'eem-mb-preview-tab' + (i === preview.active ? ' active' : ''); b.textContent = z.name;
			b.onclick = function () { preview.active = i; renderPreview(); };
			bar.appendChild(b);
		});
		var z = B.zones[preview.active];
		var g = preview.overlay.querySelector('#eem-mb-preview-grid');
		var cols = z.grid[0] ? z.grid[0].length : 0;
		g.style.gridTemplateColumns = 'repeat(' + cols + ',42px)';
		g.style.gridAutoRows = '38px';
		g.innerHTML = '';
		var consumed = {};
		for (var r = 0; r < z.grid.length; r++) {
			for (var c = 0; c < cols; c++) {
				if (consumed[r + ',' + c]) { continue; }
				var cell = z.grid[r][c];
				var d = document.createElement('div');
				d.style.gridColumn = (c + 1); d.style.gridRow = (r + 1);
				if (cell.type === 'landmark') {
					var w = 1; while (sameLm(z, r, c + w, cell.label)) { w++; }
					var h = 1; while (sameLm(z, r + h, c, cell.label)) { h++; }
					for (var rr = r; rr < r + h; rr++) { for (var cc = c; cc < c + w; cc++) { consumed[rr + ',' + cc] = 1; } }
					d.className = 'eem-mb-cust-cell landmark';
					d.style.gridColumn = (c + 1) + ' / span ' + w; d.style.gridRow = (r + 1) + ' / span ' + h;
					d.style.width = 'auto'; d.style.height = 'auto'; d.textContent = cell.label;
				} else if (cell.type === 'stall') {
					var key = z.name + ' ' + cell.label;
					d.className = 'eem-mb-cust-cell stall' + (preview.picked[key] ? ' picked' : '');
					d.textContent = cell.label;
					d.onclick = (function (k) { return function () { if (preview.picked[k]) { delete preview.picked[k]; } else { preview.picked[k] = 1; } renderPreview(); }; })(key);
				} else {
					d.className = 'eem-mb-cust-cell gap';
				}
				g.appendChild(d);
			}
		}
		preview.overlay.querySelector('#eem-mb-preview-count').textContent = Object.keys(preview.picked).length + ' ' + noun(true) + ' selected';
	}

	// ---- serialize + save ----
	function serializeBarns() {
		return B.zones.map(function (z) { return { name: z.name, grid: z.grid }; });
	}
	function save() {
		var nonceInput = document.querySelector('input[name="_eem_editor_nonce"]');
		var idEl = document.querySelector('[data-reservation-id]');
		var nonce = nonceInput ? nonceInput.value : '';
		var rid = idEl ? idEl.getAttribute('data-reservation-id') : '';
		var btn = q('#eem-mb-save'); btn.disabled = true; btn.textContent = 'Saving…';
		var body = new URLSearchParams();
		body.set('action', 'eem_map_builder_save');
		body.set('_eem_editor_nonce', nonce);
		body.set('reservation_id', rid);
		body.set('target', B.target);
		body.set('barns', JSON.stringify(serializeBarns()));
		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (res) {
			btn.disabled = false; btn.textContent = 'Save Map';
			if (res && res.success) {
				updateSectionStatus(B.target, res.data);
				// keep the seed in sync so re-opening shows the just-saved map
				var seed = document.getElementById('eem-map-seed-' + B.target);
				if (seed) { seed.textContent = JSON.stringify(serializeBarns()); }
				if (window.EEM && EEM.showSaveToast) { EEM.showSaveToast(res.data.message || 'Map saved.', { variant: 'success', sub: '' }); }
				if (!B.inline) { close(); }
			} else {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Could not save the map.';
				toast(msg);
			}
		}).catch(function () { btn.disabled = false; btn.textContent = 'Save Map'; toast('Network error — try again.'); });
	}

	// Update the editor section's "✓ N barns · N stalls" status + data-total.
	function updateSectionStatus(target, data) {
		var isRv = target === 'rv';
		var statusEl = document.querySelector(isRv ? '[data-eem-rv-map-status]' : '[data-eem-stall-map-status]');
		var mapEl = document.querySelector(isRv ? '[data-eem-rv-map]' : '[data-eem-stall-map]');
		var unit = isRv ? 'zone' : 'barn';
		var coll = isRv ? 'lots' : 'stalls';
		if (statusEl) {
			var bits = (data.barns || []).map(function (b) { return escapeHtml(b.name) + ' (' + b.stalls + ')'; }).join(', ');
			var n = data.barns ? data.barns.length : 0;
			statusEl.innerHTML = '<span class="eem-stall-map-ok">✓ ' + n + ' ' + unit + (n === 1 ? '' : 's') + ' · ' + (data.total_stalls || 0) + ' ' + coll + ' total</span> <span class="eem-stall-map-barns">' + bits + '</span>';
		}
		if (mapEl) { mapEl.setAttribute(isRv ? 'data-eem-rv-map-total' : 'data-eem-stall-map-total', String(data.total_stalls || 0)); }
		if (!isRv && typeof window.updateStallInventoryDisplay === 'function') { window.updateStallInventoryDisplay(); }
	}

	EEM.openMapBuilder = open;
})();
