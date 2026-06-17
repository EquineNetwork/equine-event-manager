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
		sur: { name: '', amount: '' },
		unit: { label: '' },
		target: 'stall', overlay: null, dirty: false,
		inline: false, host: null, zoom: 1
	};
	var ZMIN = 0.18, ZMAX = 1.8, ZBASE_C = 46, ZBASE_R = 42;

	// Multi-instance support: the stall + RV editors render inline at the same
	// time, so each target keeps its own state object in INSTANCES. `B` always
	// points at the *active* one; a capture-phase listener on each overlay swaps
	// `B` to that overlay's instance before any bubble handler runs, so all the
	// B-referencing helpers operate on the editor the user is touching.
	var INSTANCES = {};
	function activate(target) { if (INSTANCES[target]) { B = INSTANCES[target]; } }

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
	function snapshot() { B.history.push(JSON.stringify(B.zones)); if (B.history.length > 60) { B.history.shift(); } B.future = []; B.dirty = true; updateUndo(); scheduleAutoSave(); }

	// Inline builders auto-persist ~1s after any edit (tab rename/add/delete or
	// drawing) so changes survive a reload without a separate "Save Map" click —
	// e.g. renaming tabs then clicking "Update Reservation" now sticks.
	var _mbAutoSaveT = null;
	function scheduleAutoSave() {
		if (!B.inline) { return; }
		var t = B.target;
		clearTimeout(_mbAutoSaveT);
		_mbAutoSaveT = setTimeout(function () {
			var i = INSTANCES[t];
			if (i && i.overlay) { B = i; save(); }
		}, 1000);
	}
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
	// Fit factor (<=1) for the active zone — the "Fit"/1x anchor the 2x/3x presets multiply.
	function mbFitFactor() {
		var z = Z(); if (!z) { return 1; }
		var cols = z.grid[0] ? z.grid[0].length : 0;
		var scroll = q('#eem-mb-gridscroll') || q('.eem-mb-gridscroll');
		if (!cols || !scroll) { return 1; }
		// clientWidth includes the 18px scroll padding each side; the inline-grid adds
		// (cols-1) 1px gaps + a 1px border each side. Subtract it all and FLOOR the
		// per-cell width so the grid never overflows (right columns were clipped at
		// Fit). render() does round(ZBASE_C*zoom); picking zoom = (integer cw)/ZBASE_C
		// makes that round back to the same integer, so no rounding overflow either.
		var usable = scroll.clientWidth - 36 - (cols - 1) - 2;
		var cw = Math.floor(usable / cols);
		return Math.max(ZMIN, Math.min(1, cw / ZBASE_C));
	}
	function mbSetActive(level) {
		var bar = q('.eem-mb-zoom'); if (!bar) { return; }
		var btns = bar.querySelectorAll('[data-zoom]');
		for (var i = 0; i < btns.length; i++) { btns[i].classList.toggle('is-active', btns[i].getAttribute('data-zoom') === level); }
	}
	// Discrete zoom presets: Fit (whole facility), 2x or 3x of Fit.
	function mbApplyLevel(level) {
		// +/− step the chip size around the fixed 1× default; 'reset' / unknown
		// (e.g. on load) returns to 1× so the map always opens at a readable size.
		var z0 = B.zoom || 1;
		B.zoom = level === 'in' ? Math.min(ZMAX, z0 * 1.2) : level === 'out' ? Math.max(ZMIN, z0 / 1.2) : level === 'reset' ? 1 : z0;
		render();
		mbSetActive(level);
	}
	function fitZoom() { mbApplyLevel('reset'); }

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
		g.style.setProperty('--eem-mb-chip', cw + 'px');  // cell width + label font scale
		g.style.setProperty('--eem-mb-chiph', ch + 'px'); // cell height
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
					// A merged unit (w/h > 1) spans its block as ONE sellable cell;
					// the covered cells are consumed so they don't render separately.
					var uw = (cell.w && cell.w > 1) ? cell.w : 1, uh = (cell.h && cell.h > 1) ? cell.h : 1;
					if (uw > 1 || uh > 1) {
						for (var ur = r; ur < r + uh; ur++) { for (var uc = c; uc < c + uw; uc++) { consumed[ur + ',' + uc] = 1; } }
						d.style.gridColumn = (c + 1) + ' / span ' + uw;
						d.style.gridRow = (r + 1) + ' / span ' + uh;
						d.classList.add('unit');
					}
					if (inSel(r, c)) { d.classList.add('sel'); }
					if (dups[cell.label]) { d.classList.add('dup'); }
					var carea = cell.area ? findArea(z, cell.area) : null;
					// Effective surcharge stacks the whole-tab amount with any painted
					// area amount on this cell ("most layers add").
					var tabAmt = (z.surcharge && z.surcharge.nightly) ? Number(z.surcharge.nightly) : 0;
					var areaAmt = (carea && carea.surcharge && carea.surcharge.nightly) ? Number(carea.surcharge.nightly) : 0;
					var effAmt = tabAmt + areaAmt;
					var tabPkgAmt = pkgAmtOf(z.surcharge);
					var areaPkgAmt = carea ? pkgAmtOf(carea.surcharge) : 0;
					var effPkg = tabPkgAmt + areaPkgAmt;
					if (carea || tabAmt > 0 || tabPkgAmt > 0) {
						d.classList.add('has-surcharge');
						d.style.setProperty('--eem-mb-area', (carea && carea.color) ? carea.color : '#16a34a');
						var tparts = [];
						if (carea) { tparts.push(carea.name); }
						var amtLabel = surLabel(effAmt, effPkg);
						if ('$0' !== amtLabel) { tparts.push(amtLabel); }
						d.title = tparts.join(' ');
					}
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
			var tabPriced = !!(z.surcharge && (EEM_num(z.surcharge.nightly) > 0 || pkgAmtOf(z.surcharge) > 0));
			b.className = 'eem-mb-tab' + (i === B.active ? ' active' : '');
			b.innerHTML = escapeHtml(z.name) +
				' <span class="eem-mb-tab-price' + (tabPriced ? ' on' : '') + '" data-tabprice="1" title="Price the whole ' + escapeAttr(z.name) + '" aria-label="Price the whole ' + escapeAttr(z.name) + '">$</span>' +
				(B.zones.length > 1 ? ' <span class="x" aria-label="Delete">&times;</span>' : '');
			b.onclick = function (ev) {
				if (ev.target.classList.contains('x')) {
					mbConfirm('Delete ' + zoneNoun(false) + ' “' + z.name + '”? This removes its ' + countStalls(z) + ' ' + noun(true) + '.', function () {
						snapshot(); B.zones.splice(i, 1); B.active = Math.max(0, B.active - (i <= B.active ? 1 : 0)); B.sel = null; render(); renderControls(); notifyZones();
					});
					return;
				}
				if (ev.target.getAttribute('data-tabprice')) {
					B.active = i; render(); openTabSurchargeModal(); return;
				}
				B.active = i; B.sel = null; render(); renderControls();
			};
			b.ondblclick = function () { mbPrompt('Rename ' + zoneNoun(false), z.name, function (n) { snapshot(); z.name = n; render(); notifyZones(); }); };
			t.appendChild(b);
		});
		var add = document.createElement('button');
		add.type = 'button'; add.className = 'eem-mb-tab-add';
		add.textContent = '+ Add ' + cap(zoneNoun(false));
		add.onclick = function () {
			mbPrompt(cap(zoneNoun(false)) + ' name', cap(zoneNoun(false)) + ' ' + (B.zones.length + 1), function (n) {
				snapshot(); B.zones.push({ name: n, grid: mkGrid(10, 20) }); B.active = B.zones.length - 1; B.sel = null; render(); renderControls(); notifyZones();
			});
		};
		t.appendChild(add);
	}

	// Tell the editor's zone list that the builder's tabs changed (rename/add/delete
	// from inside the builder), so it can keep the RV Lot Zones rows 1:1 in sync.
	function notifyZones() {
		if (typeof EEM.onMapZonesChanged === 'function') {
			EEM.onMapZonesChanged(B.target, B.zones.map(function (z) { return z.name; }));
		}
	}

	function updateCount() {
		var el = q('#eem-mb-count');
		if (el) { el.textContent = B.zones.length + ' ' + zoneNoun(B.zones.length !== 1) + ' · ' + totalStalls() + ' ' + noun(true); }
	}

	// ---- fill series ----
	function parseStart(s) { var m = String(s).match(/^(.*?)(\d+)$/); return m ? { prefix: m[1], num: parseInt(m[2], 10), pad: m[2].length } : { prefix: s, num: null, pad: 0 }; }
	function nextLabel(p, i, step) { if (p.num === null) { return p.prefix + (i ? i + 1 : ''); } var s = String(p.num + i * step); while (s.length < p.pad) { s = '0' + s; } return p.prefix + s; }
	// Scan every placed stall/lot across all zones and return the label that should
	// follow the highest existing numeric one (same prefix + zero-padding). Lets a
	// re-opened builder continue numbering (…60 → start at 61) instead of resetting
	// to 1. Falls back to '1' when nothing numeric is placed yet.
	function nextStartLabel() {
		var best = null, bestVal = -Infinity;
		B.zones.forEach(function (z) {
			(z.grid || []).forEach(function (row) {
				(row || []).forEach(function (c) {
					if (c && c.type === 'stall') {
						var p = parseStart(c.label);
						if (p.num !== null && p.num > bestVal) { bestVal = p.num; best = p; }
					}
				});
			});
		});
		return best ? nextLabel(best, 1, 1) : '1';
	}
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

	// ---- surcharge areas (Slice 3) — paint a named, priced region onto cells ----
	function areaSlug(name) { var s = String(name).toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, ''); return s || 'area'; }
	function findArea(z, id) { if (!z.areas) { return null; } for (var i = 0; i < z.areas.length; i++) { if (z.areas[i].id === id) { return z.areas[i]; } } return null; }
	function uniqueAreaId(z, base) { var id = base, n = 2; while (findArea(z, id)) { id = base + '_' + n; n++; } return id; }
	// Drop areas no cell references anymore so the registry never leaks orphans.
	function pruneAreas(z) {
		if (!z.areas || !z.areas.length) { return; }
		var used = {};
		z.grid.forEach(function (row) { row.forEach(function (cl) { if (cl.type === 'stall' && cl.area) { used[cl.area] = 1; } }); });
		z.areas = z.areas.filter(function (a) { return used[a.id]; });
	}
	// Live Stay Packages for the active builder target (rv/stall), read from the
	// editor's rendered package rows. Empty when the reservation has no packages —
	// the package surcharge input only appears when there's a package to price.
	function pkgList() {
		var t = (B && B.overlay && B.overlay.__eemTarget) || 'rv';
		var rows = document.querySelectorAll('#eem-' + t + '-packages-tbody .eem-pkg-row[data-package-id]');
		var out = [];
		Array.prototype.forEach.call(rows, function (r) {
			var nm = r.querySelector('.eem-pkg-name-input');
			out.push({ id: r.getAttribute('data-package-id'), name: (nm && nm.value) ? nm.value : '' });
		});
		return out;
	}
	function hasPkgs() { return pkgList().length > 0; }
	// The single flat "+$/package" amount lives under the reserved `_all` wildcard
	// key (per the "flat per-package" decision — one package surcharge, not a matrix).
	function pkgAmtOf(sur) { return (sur && sur.packages && sur.packages._all) ? Number(sur.packages._all) : 0; }
	function setPkgAmt(sur, amt) {
		// PHP serialises an empty packages map as a JSON array ([]), which hydrates
		// into a JS Array. Setting a named prop on an Array works in memory but is
		// dropped by JSON.stringify on save — coerce to a plain object first.
		if (!sur.packages || Array.isArray(sur.packages)) { sur.packages = {}; }
		if (amt > 0) { sur.packages._all = amt; } else { delete sur.packages._all; }
	}

	// Human-readable surcharge summary, e.g. "+$5.00/night, +$100.00/pkg".
	function surLabel(nightly, pkg) {
		var parts = [];
		if (nightly > 0) { parts.push('+$' + nightly.toFixed(2) + '/night'); }
		if (pkg > 0) { parts.push('+$' + pkg.toFixed(2) + '/pkg'); }
		return parts.length ? parts.join(', ') : '$0';
	}

	// ---- selection helpers (the "select → act" model) ----
	// Normalised selection rect, or null when nothing is selected.
	function normSel() {
		if (!B.sel) { return null; }
		var s = B.sel;
		return { r1: Math.min(s.r1, s.r2), r2: Math.max(s.r1, s.r2), c1: Math.min(s.c1, s.c2), c2: Math.max(s.c1, s.c2) };
	}
	// Run cb(cell, r, c) over each sellable (stall) cell in the selection.
	function eachSelStall(z, cb) {
		var s = normSel(); if (!s) { return; }
		for (var r = s.r1; r <= s.r2; r++) { for (var c = s.c1; c <= s.c2; c++) {
			var cell = z.grid[r] && z.grid[r][c];
			if (cell && cell.type === 'stall') { cb(cell, r, c); }
		} }
	}
	// {sellable: int, hasSur: bool, isUnit: bool} describing the current selection.
	function selInfo() {
		var z = Z(), n = 0, hasSur = false, isUnit = false;
		eachSelStall(z, function (cell) {
			n++;
			if (cell.area) { hasSur = true; }
			if ((cell.w && cell.w > 1) || (cell.h && cell.h > 1)) { isUnit = true; }
		});
		return { sellable: n, hasSur: hasSur, isUnit: isUnit };
	}
	// The first painted area found on the selection (for pre-filling the modal).
	function selArea(z) {
		var found = null;
		eachSelStall(z, function (cell) { if (!found && cell.area) { found = findArea(z, cell.area); } });
		return found;
	}

	// ---- surcharge apply / remove (driven by the modal, not inline inputs) ----
	function applySurchargeToSel(name, nightly, pkg) {
		var z = Z(); if (!normSel()) { return; }
		z.areas = z.areas || [];
		var area = null;
		for (var i = 0; i < z.areas.length; i++) { if (z.areas[i].name.toLowerCase() === name.toLowerCase()) { area = z.areas[i]; break; } }
		if (!area) { area = { id: uniqueAreaId(z, areaSlug(name)), name: name, color: '#16a34a', surcharge: { nightly: nightly, packages: {} } }; z.areas.push(area); }
		else { area.name = name; area.surcharge = area.surcharge || { nightly: 0, packages: {} }; area.surcharge.nightly = nightly; }
		setPkgAmt(area.surcharge, pkg);
		snapshot();
		var n = 0;
		eachSelStall(z, function (cell) { cell.area = area.id; n++; });
		pruneAreas(z);
		toast('Priced ' + n + ' ' + noun(true) + ' — “' + name + '” (' + surLabel(nightly, pkg) + ')');
		render(); renderControls();
	}
	function removeSurchargeFromSel() {
		var z = Z(); if (!normSel()) { return; }
		snapshot();
		var n = 0;
		eachSelStall(z, function (cell) { if (cell.area) { delete cell.area; n++; } });
		pruneAreas(z);
		toast(n ? ('Removed surcharge from ' + n + ' ' + noun(true)) : 'No surcharge on the selection');
		render(); renderControls();
	}

	// ---- multi-cell unit — merge a block into ONE sellable unit ----
	function combineUnit(label) {
		var z = Z(), s = normSel(); if (!s) { return; }
		var w = s.c2 - s.c1 + 1, h = s.r2 - s.r1 + 1;
		if (w < 2 && h < 2) { toast('Select 2 or more ' + noun(true) + ' to combine into one'); return; }
		snapshot();
		// Carry any surcharge area already on a selected cell onto the merged unit.
		var area = '';
		eachSelStall(z, function (cell) { if (cell.area) { area = cell.area; } });
		for (var r = s.r1; r <= s.r2; r++) { for (var c = s.c1; c <= s.c2; c++) { z.grid[r][c] = { type: 'gap', label: '' }; } }
		var anchor = { type: 'stall', label: label, w: w, h: h };
		if (area) { anchor.area = area; }
		z.grid[s.r1][s.c1] = anchor;
		pruneAreas(z);
		B.fill.start = nextStartLabel(); // a numbered unit advances the Fill counter
		toast('Combined into one unit “' + label + '” (' + (w * h) + ' cells, counts as 1 lot)');
		B.sel = { r1: s.r1, c1: s.c1, r2: s.r1, c2: s.c1 }; render(); renderControls();
	}
	function splitUnit() {
		var z = Z(), s = normSel(); if (!s) { toast('Select a unit first'); return; }
		var cell = z.grid[s.r1] && z.grid[s.r1][s.c1];
		if (!cell || cell.type !== 'stall' || !(((cell.w || 1) > 1) || ((cell.h || 1) > 1))) { toast('Select a combined unit to split it back'); return; }
		snapshot();
		delete cell.w; delete cell.h;
		toast('Split unit back into single ' + noun(true)); B.sel = null; render(); renderControls();
	}

	// ---- modals (Assign Surcharge / Combine Unit / Whole-tab surcharge) ----
	function openSurchargeModal() {
		var z = Z(); var info = selInfo();
		if (!info.sellable) { toast('Select one or more ' + noun(true) + ' first'); return; }
		var ex = selArea(z);
		var exNight = ex ? EEM_num(ex.surcharge && ex.surcharge.nightly) : 0;
		var exPkg = ex ? pkgAmtOf(ex.surcharge) : 0;
		var showPkg = hasPkgs();
		var body =
			'<label class="eem-mb-fl">Name <span class="eem-mb-fl-opt">(optional)</span>' +
				'<input type="text" class="eem-mb-input eem-mb-input-wide" id="eem-sm-name" value="' + escapeAttr(ex ? ex.name : '') + '" placeholder="Paddocks, Premium, Lakefront…"></label>' +
			'<label class="eem-mb-fl">+ $ / night' +
				'<input type="number" class="eem-mb-num" id="eem-sm-night" min="0" step="0.01" value="' + (exNight ? escapeAttr(String(exNight)) : '') + '" placeholder="0.00"></label>' +
			(showPkg ? '<label class="eem-mb-fl">+ $ / package' +
				'<input type="number" class="eem-mb-num" id="eem-sm-pkg" min="0" step="0.01" value="' + (exPkg ? escapeAttr(String(exPkg)) : '') + '" placeholder="0.00"></label>' : '');
		mbForm({
			title: 'Assign Surcharge',
			subtitle: info.sellable + ' ' + noun(info.sellable !== 1) + ' selected',
			bodyHtml: body,
			primaryLabel: 'Save surcharge',
			secondaryLabel: info.hasSur ? 'Remove surcharge' : '',
			onPrimary: function (card) {
				var name = (card.querySelector('#eem-sm-name').value || '').trim();
				var night = Math.max(0, parseFloat(card.querySelector('#eem-sm-night').value || '0') || 0);
				var pkgEl = card.querySelector('#eem-sm-pkg');
				var pkg = pkgEl ? Math.max(0, parseFloat(pkgEl.value || '0') || 0) : 0;
				if (night <= 0 && pkg <= 0) { toast('Enter a $/night or $/package amount'); return false; }
				applySurchargeToSel(name || 'Premium', night, pkg);
			},
			onSecondary: function () { removeSurchargeFromSel(); }
		});
	}
	function openUnitModal() {
		var info = selInfo();
		if (info.sellable < 2) { toast('Select 2 or more ' + noun(true) + ' to combine'); return; }
		mbForm({
			title: 'Combine into one unit',
			subtitle: info.sellable + ' ' + noun(true) + ' → 1 sellable unit',
			bodyHtml: '<label class="eem-mb-fl">Unit title <span class="eem-mb-fl-opt">(a number or a name)</span>' +
				'<input type="text" class="eem-mb-input eem-mb-input-wide" id="eem-um-label" placeholder="161, Pasture #1, Barn A…"></label>',
			primaryLabel: 'Combine',
			onPrimary: function (card) {
				var label = (card.querySelector('#eem-um-label').value || '').trim();
				if (!label) { toast('Give the unit a title'); return false; }
				combineUnit(label);
			}
		});
	}
	function openTabSurchargeModal() {
		var z = Z();
		var night = EEM_num(z.surcharge && z.surcharge.nightly);
		var pkg = pkgAmtOf(z.surcharge);
		var showPkg = hasPkgs();
		var has = night > 0 || pkg > 0;
		var body =
			'<label class="eem-mb-fl">+ $ / night' +
				'<input type="number" class="eem-mb-num" id="eem-tm-night" min="0" step="0.01" value="' + (night ? escapeAttr(String(night)) : '') + '" placeholder="0.00"></label>' +
			(showPkg ? '<label class="eem-mb-fl">+ $ / package' +
				'<input type="number" class="eem-mb-num" id="eem-tm-pkg" min="0" step="0.01" value="' + (pkg ? escapeAttr(String(pkg)) : '') + '" placeholder="0.00"></label>' : '');
		mbForm({
			title: 'Price the whole “' + z.name + '”',
			subtitle: 'Applies to every ' + noun(false) + ' on this tab (stacks with area prices)',
			bodyHtml: body,
			primaryLabel: 'Save',
			secondaryLabel: has ? 'Remove tab price' : '',
			onPrimary: function (card) {
				var n = Math.max(0, parseFloat(card.querySelector('#eem-tm-night').value || '0') || 0);
				var pEl = card.querySelector('#eem-tm-pkg');
				var p = pEl ? Math.max(0, parseFloat(pEl.value || '0') || 0) : 0;
				setTabSurcharge(n, p);
			},
			onSecondary: function () { setTabSurcharge(0, 0); }
		});
	}
	function setTabSurcharge(nightly, pkg) {
		var z = Z();
		snapshot();
		z.surcharge = z.surcharge || { nightly: 0, packages: {} };
		z.surcharge.nightly = nightly;
		setPkgAmt(z.surcharge, pkg);
		if (nightly > 0 || pkg > 0) { toast('Whole “' + z.name + '” now ' + surLabel(nightly, pkg)); }
		else { toast('Removed tab price from “' + z.name + '”'); }
		render(); renderControls();
	}
	function EEM_num(v) { return v ? Number(v) : 0; }

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
		} else if (B.tool === 'select') {
			// Select → act. Drag a block (or click one lot/unit); the action bar
			// offers Surcharge / Combine / Remove. All detail lives in modals so the
			// toolbar stays clean.
			var info = selInfo();
			if (!info.sellable) {
				p.innerHTML = '<span class="eem-mb-selhint">Drag to select ' + noun(true) + ' — then price, combine, or clear them. Click a unit to edit it.</span>';
			} else {
				p.innerHTML =
					'<span class="eem-mb-selcount">' + info.sellable + ' ' + noun(info.sellable !== 1) + ' selected</span>' +
					'<button type="button" class="eem-mb-apply" id="eem-mb-act-sur">$ Surcharge</button>' +
					(info.sellable >= 2 ? '<button type="button" class="eem-mb-apply" id="eem-mb-act-unit">Combine into Unit</button>' : '') +
					(info.isUnit ? '<button type="button" class="eem-mb-apply eem-mb-apply-ghost" id="eem-mb-act-split">Split Unit</button>' : '') +
					(info.hasSur ? '<button type="button" class="eem-mb-apply eem-mb-apply-ghost" id="eem-mb-act-rem">Remove Surcharge</button>' : '');
				q('#eem-mb-act-sur').addEventListener('click', openSurchargeModal);
				if (q('#eem-mb-act-unit')) { q('#eem-mb-act-unit').addEventListener('click', openUnitModal); }
				if (q('#eem-mb-act-split')) { q('#eem-mb-act-split').addEventListener('click', splitUnit); }
				if (q('#eem-mb-act-rem')) { q('#eem-mb-act-rem').addEventListener('click', removeSurchargeFromSel); }
			}
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
		var commit = function () { var v = inp.value.trim(); snapshot(); z.grid[r][c] = v ? { type: 'stall', label: v } : { type: 'gap', label: '' }; B.fill.start = nextStartLabel(); render(); };
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
						tool('pan', 'Pan', '<path d="M5 9l-3 3 3 3"/><path d="M9 5l3-3 3 3"/><path d="M15 19l-3 3-3-3"/><path d="M19 9l3 3-3 3"/><path d="M2 12h20"/><path d="M12 2v20"/>') +
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
							'<span class="eem-mb-controls" id="eem-mb-controls"></span>' +
							'<span class="eem-mb-zoom">' + '<button type="button" data-zoom="out" title="Zoom out" aria-label="Zoom out">&minus;</button>' + '<button type="button" data-zoom="reset" title="Reset zoom">Zoom</button>' + '<button type="button" data-zoom="in" title="Zoom in" aria-label="Zoom in">+</button>' + '</span>' +
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
			var gsc = q('#eem-mb-gridscroll') || q('.eem-mb-gridscroll');
			if (gsc) { gsc.style.cursor = (B.tool === 'pan') ? 'grab' : ''; }
			render(); renderControls();
		});
		// resize steppers
		o.querySelector('.eem-mb-gridbar').addEventListener('click', function (e) {
			var z = e.target.closest('[data-zoom]');
			if (z) {
				mbApplyLevel(z.getAttribute('data-zoom'));
				return;
			}
			var b = e.target.closest('[data-resize]'); if (!b) { return; }
			resizeGrid(b.getAttribute('data-resize'), parseInt(b.getAttribute('data-d'), 10));
		});
		// drag-select on the grid
		var grid = o.querySelector('#eem-mb-grid');
		grid.addEventListener('mousedown', function (e) {
			if (B.tool === 'pan') {
				var psc = q('#eem-mb-gridscroll') || q('.eem-mb-gridscroll');
				if (psc) { B.pan = { sx: e.clientX, sy: e.clientY, sl: psc.scrollLeft, st: psc.scrollTop, el: psc }; psc.style.cursor = 'grabbing'; e.preventDefault(); }
				return;
			}
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
		window.addEventListener('mousemove', function (e) {
			if (!B.pan) { return; }
			B.pan.el.scrollLeft = B.pan.sl - (e.clientX - B.pan.sx);
			B.pan.el.scrollTop = B.pan.st - (e.clientY - B.pan.sy);
			e.preventDefault();
		});
		o.querySelector('#eem-mb-preview').addEventListener('click', previewCustomer);
		o.querySelector('#eem-mb-cancel').addEventListener('click', close);
		o.querySelector('#eem-mb-save').addEventListener('click', save);
		// Backdrop click closes only the floating modal; inline lives in the card.
		if (!B.inline) { o.addEventListener('mousedown', function (e) { if (e.target === o) { close(); } }); }
	}
	// On mouse-up: a Select-tool click (no drag) on a single cell opens its label
	// editor — "select something to change a label".
	function onUp() {
		if (B.pan) { if (B.pan.el) { B.pan.el.style.cursor = 'grab'; } B.pan = null; return; }
		// Only a real GRID interaction sets B.drag. Rebuild the action bar then —
		// NOT on every document mouseup, or clicking an action-bar button would
		// destroy that button (re-render) before its own click handler can fire.
		var wasGridDrag = !!B.drag;
		B.drag = null;
		if (wasGridDrag && B.tool === 'select') { renderControls(); return; }
		if (B.overlay) { updateSelMeta(); }
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
			B.zones = [{ name: cap(zoneNoun(false)) + ' 1', grid: mkGrid(10, 20) }];
		} else {
			B.zones = barns.map(function (b) {
				var grid = (b.grid || []).map(function (row) { return (row || []).map(function (c) {
					var cell = { type: (c && c.type) || 'gap', label: (c && c.label) || '' };
					if (c && c.area) { cell.area = c.area; }            // painted surcharge area (Slice 3)
					if (c && c.w && c.w > 1) { cell.w = c.w; }          // multi-cell unit span
					if (c && c.h && c.h > 1) { cell.h = c.h; }
					return cell;
				}); });
				if (!grid.length) { grid = mkGrid(10, 20); }
				return { name: b.name || (cap(zoneNoun(false))), grid: grid, areas: (b.areas || []), surcharge: (b.surcharge || null) };
			});
		}
		B.active = 0; B.sel = null; B.tool = 'fill'; B.history = []; B.future = []; B.dirty = false; B.zoom = 1; B.fill = { start: nextStartLabel(), step: 1, dir: 'lr' }; B.lm = { name: 'Wash Rack' };
	}

	function open(target) {
		// Already mounted for this target? Just make it the active instance.
		if (INSTANCES[target] && INSTANCES[target].overlay) { activate(target); return; }
		// Fresh per-target state object (no shared single-instance carryover).
		B = INSTANCES[target] = { overlay: null, inline: false, host: null };
		hydrate(target);
		B.host = document.querySelector('[data-eem-map-host="' + target + '"]');
		B.inline = !!B.host;
		buildModal();
		B.overlay.__eemTarget = target;
		// Capture phase: swap `B` to THIS instance before any bubble handler fires.
		var act = function () { activate(target); };
		B.overlay.addEventListener('mousedown', act, true);
		B.overlay.addEventListener('click', act, true);
		B.overlay.addEventListener('input', act, true);
		q('.eem-mb-title').textContent = (target === 'rv' ? 'RV Map Builder' : 'Stall Map Builder');
		// Capture THIS instance: auto-mount opens stall then rv synchronously, so by
		// the time these rAF callbacks fire the shared `B` points at the last-opened
		// instance. Without capturing, the stall overlay never gets `.open` (it stays
		// opacity:0 → blank). Pin the instance + re-activate before fitZoom.
		var inst = INSTANCES[target];
		requestAnimationFrame(function () {
			if (!inst.overlay) { return; }
			inst.overlay.classList.add('open');
			B = inst;
			fitZoom();
		});
		render(); renderControls();
	}

	// Auto-mount every inline host on load so the editor "just loads" — no button
	// click. The stall + RV editors render side by side in their own cards.
	function autoMountInlineHosts() {
		var hosts = document.querySelectorAll('[data-eem-map-host]');
		Array.prototype.forEach.call(hosts, function (h) {
			var target = h.getAttribute('data-eem-map-host');
			if (!target) { return; }
			if (INSTANCES[target] && INSTANCES[target].overlay) { return; }
			open(target);
			// The old "Build / Edit Map" button is now redundant — the editor is
			// always visible. Hide it to remove the easy-to-miss extra click.
			var btn = document.querySelector('[data-eem-action="open-map-builder"][data-target="' + target + '"]');
			if (btn) { var row = btn.closest('.eem-stall-map-row') || btn; row.style.display = 'none'; }
		});
	}

	function close() {
		if (!B.overlay) { return; }
		var t = B.overlay.__eemTarget;
		B.overlay.parentNode.removeChild(B.overlay);
		B.overlay = null;
		B.inline = false; B.host = null;
		if (t && INSTANCES[t]) { delete INSTANCES[t]; }
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
	// Rich form modal: a title, an optional subtitle, arbitrary body HTML, a primary
	// action and an optional destructive secondary action. onPrimary(card) returning
	// false keeps the modal open (validation failed). Used by the surcharge / unit /
	// tab-price flows so the builder toolbar stays clean.
	function mbForm(opts) {
		var o = document.createElement('div');
		o.className = 'eem-mb-dialog';
		o.innerHTML =
			'<div class="eem-mb-dialog-card eem-mb-form-card">' +
				'<div class="eem-mb-form-title">' + escapeHtml(opts.title || '') + '</div>' +
				(opts.subtitle ? '<div class="eem-mb-form-sub">' + escapeHtml(opts.subtitle) + '</div>' : '') +
				'<div class="eem-mb-form-body">' + (opts.bodyHtml || '') + '</div>' +
				'<div class="eem-mb-dialog-actions">' +
					(opts.secondaryLabel ? '<button type="button" class="eem-mb-btn eem-mb-btn-danger eem-mb-form-secondary">' + escapeHtml(opts.secondaryLabel) + '</button>' : '') +
					'<span class="eem-mb-form-spacer"></span>' +
					'<button type="button" class="eem-mb-btn eem-mb-form-cancel">Cancel</button>' +
					'<button type="button" class="eem-mb-btn eem-mb-btn-primary eem-mb-form-ok">' + escapeHtml(opts.primaryLabel || 'Save') + '</button>' +
				'</div>' +
			'</div>';
		(B.overlay || document.body).appendChild(o);
		var card = o.querySelector('.eem-mb-dialog-card');
		function close() { if (o.parentNode) { o.parentNode.removeChild(o); } }
		o.querySelector('.eem-mb-form-cancel').addEventListener('click', close);
		o.querySelector('.eem-mb-form-ok').addEventListener('click', function () { if (opts.onPrimary && opts.onPrimary(card) === false) { return; } close(); });
		var sec = o.querySelector('.eem-mb-form-secondary');
		if (sec) { sec.addEventListener('click', function () { if (opts.onSecondary) { opts.onSecondary(card); } close(); }); }
		o.addEventListener('mousedown', function (e) { if (e.target === o) { close(); } });
		o.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); }
			if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); if (opts.onPrimary && opts.onPrimary(card) === false) { return; } close(); }
		});
		var f = o.querySelector('input'); if (f) { f.focus(); f.select(); }
		requestAnimationFrame(function () { o.classList.add('open'); });
	}

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
		// Click-and-drag to pan the preview chart; a drag past 4px suppresses the
		// stall-pick click so panning never toggles a stall.
		var psc = o.querySelector('.eem-mb-preview-scroll');
		if (psc) {
			var pp = false, pm = false, px = 0, py = 0, pl = 0, pt = 0;
			psc.addEventListener('mousedown', function (e) {
				if (e.button !== 0) { return; }
				pp = true; pm = false; preview.didPan = false;
				px = e.clientX; py = e.clientY; pl = psc.scrollLeft; pt = psc.scrollTop;
				psc.classList.add('is-panning');
			});
			document.addEventListener('mousemove', function (e) {
				if (!pp) { return; }
				var dx = e.clientX - px, dy = e.clientY - py;
				if (!pm && (Math.abs(dx) > 4 || Math.abs(dy) > 4)) { pm = true; }
				if (pm) { psc.scrollLeft = pl - dx; psc.scrollTop = pt - dy; }
			});
			document.addEventListener('mouseup', function () {
				if (!pp) { return; }
				pp = false; preview.didPan = pm; psc.classList.remove('is-panning');
				if (pm) { setTimeout(function () { preview.didPan = false; }, 0); }
			});
		}
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
					d.onclick = (function (k) { return function () { if (preview.didPan) { return; } if (preview.picked[k]) { delete preview.picked[k]; } else { preview.picked[k] = 1; } renderPreview(); }; })(key);
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
		return B.zones.map(function (z) { return { name: z.name, grid: z.grid, areas: z.areas || [], surcharge: z.surcharge || null }; });
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
	EEM.autoMountMapBuilders = autoMountInlineHosts;

	// ── Zones-drive-tabs API (the RV Lot Zones list is the source of truth) ──
	// The editor calls these when the admin edits the zones list; each keeps the
	// matching builder tab 1:1 and re-renders. Returns false if no live instance.
	EEM.getMapZoneNames = function (target) {
		var i = INSTANCES[target];
		return i ? i.zones.map(function (z) { return z.name; }) : [];
	};
	// Every stall/lot label currently drawn on the map (across all barns/zones),
	// de-duplicated, in draw order. Lets the editor's Blocked-Stalls / Blocked-Lots
	// tag-search source its candidate list from the Map Builder when the layout
	// comes from the interactive map rather than the Row Builder.
	EEM.getMapLabels = function (target) {
		var i = INSTANCES[target];
		if (!i || !i.zones) { return []; }
		var out = [], seen = {};
		i.zones.forEach(function (z) {
			(z.grid || []).forEach(function (row) {
				(row || []).forEach(function (c) {
					if (c && c.type === 'stall' && c.label !== '' && !seen[c.label]) {
						seen[c.label] = 1;
						out.push(c.label);
					}
				});
			});
		});
		return out;
	};
	EEM.renameMapZone = function (target, index, name) {
		var i = INSTANCES[target]; if (!i || !i.zones[index]) { return false; }
		B = i; i.zones[index].name = String(name || '').trim() || i.zones[index].name;
		renderTabs(); updateCount(); return true;
	};
	EEM.addMapZone = function (target, name) {
		var i = INSTANCES[target]; if (!i) { return false; }
		B = i; i.zones.push({ name: String(name || '').trim() || (cap(zoneNoun(false)) + ' ' + (i.zones.length + 1)), grid: mkGrid(10, 20) });
		i.active = i.zones.length - 1; i.sel = null; render(); renderControls(); return true;
	};
	EEM.removeMapZone = function (target, index) {
		var i = INSTANCES[target]; if (!i || i.zones.length <= 1 || index < 0 || index >= i.zones.length) { return false; }
		B = i; i.zones.splice(index, 1); i.active = Math.min(i.active, i.zones.length - 1); i.sel = null; render(); renderControls(); return true;
	};
	// Persist the built map (used after zone-list edits so the rename/add/delete
	// survives reload). Mirrors the Save Map button but callable programmatically.
	EEM.saveMap = function (target) {
		var i = INSTANCES[target]; if (!i) { return; }
		B = i; save();
	};
	// EEM.onMapZonesChanged is set by the editor (admin.js) to mirror builder-
	// initiated tab edits back into the zones list. Do NOT initialise it here —
	// admin.js may load first, and assigning null would clobber its handler.

	// Auto-mount on load so the editor is visible without a click.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', autoMountInlineHosts);
	} else {
		autoMountInlineHosts();
	}
})();
