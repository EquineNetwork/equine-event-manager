# Session Notes — Bridge Doc

**Purpose:** Captures conversational nuance, calibration data, and
in-flight context that ISN'T already in CLAUDE.md, commit messages,
or CLEANUP.md. Read after `git pull` on a new machine to pick up
momentum across Claude Code sessions.

**Last updated:** 2026-05-29 — **C8.G mockup** — Full inventory model restructure. Replaced "Stall Charts" toggle + "Stall Selection Mode" toggle with "Inventory Mode (Bulk | Mapped)" + "Customer Selection (Quantity Picker | Interactive Map)" on both Stall and RV sides. stall-mapped-content wraps row-builder + blocked stalls + stall map upload + Customer Selection. rv-mapped-content wraps zone list (now with "+ Nightly" / "+ Weekend" surcharge labels + surcharge hint) + lot row-builder + blocked lots + Customer Selection. Interactive Map button carries "Coming soon" badge (.badge-coming-soon). New JS: stallMappedIsActive(), rvMappedIsActive(), toggleInventoryMode(), toggleCustomerSelection(). Removed: toggleMode(), toggleLotMode(), addStallRow(), deleteStallRow(), stallChartsIsOn(), rvChartsIsOn(). Updated: updateStallInventoryDisplay() + updateRvInventoryDisplay() use new detection functions; toggleSwitchRow() charts special-case lines removed; DOMContentLoaded stall qty tbody listener removed. CSS selectors #stall-charts-content / #rv-lot-charts-content retargeted to new IDs. Commit 5512e7c. 2550 lines.

**Last updated (prev):** 2026-05-28 — **C8.F mockup** — Available Stall/RV Inventory fields become computed read-only when Charts enabled (WooCommerce variant-stock pattern). Charts OFF = editable input. Charts ON = bold computed number + "(computed from row quantities / stall layout / zone quantities / lot layout)" + helper text. Original admin value preserved in DOM (input hidden, not cleared). Functions: updateStallInventoryDisplay(), updateRvInventoryDisplay(). Wired to: toggleSwitchRow (charts toggles only), toggleMode, toggleLotMode, add/delete row/zone, DOMContentLoaded (init + delegated input listeners). 2649 lines.

**Last updated (prev):** 2026-05-28 — **C8.E mockup** — RV Lot Zones now IS the Quantity Based content (Lot Selection Mode toggle moved to top of rv-lot-charts-content); Avail Qty input added to each zone row (data-role="zone-qty", Red=6 Blue=18); old "X lots" computed display + refreshZoneCounts() + .zone-count CSS fully removed; RV Add-Ons reordered to bottom of RV section. Note: stall side still has its own separate quantity table since stalls have no zone concept. 2499 lines.

**Last updated (prev):** 2026-05-28 — **C8.D mockup** — Two fixes: (1) Stall Quantity table Add Stall Row + trash icons wired (addStallRow / deleteStallRow); (2) RV Lot Selection Mode mirrored to Stall pattern — Lot Zones wrapped in #rv-mode-quantity-content, row-builder + Blocked RV Lots wrapped in #rv-mode-exact-content, toggleRvMode() replaced with toggleLotMode(). 2507 lines.

**Last updated (prev):** 2026-05-28 — **C8.C mockup** — Nested label indentation fixed (Option A: display:block on .field-row inside stall/rv chart containers); chart-modal-* subsystem removed entirely (Preview Full Chart retired; stall_chart_detail.html is the dedicated chart view). 2474 lines.

**Last updated (prev prev):** 2026-05-28 — **C8.B mockup** — RV Lot Charts toggle added to RV Reservations section; duplicate surcharge-based Lot Zones retired (nightly/weekend version from stall-charts-content is the keeper); "Lot Layout / Manage Lot Layout" stub retired from RV Reservations (mirrors prior Stall Layout stub retirement). Mockup only — 2613 lines.

**Last updated (prev):** 2026-05-28 — **C7.X.21** — Three fix chunks landed: C7.X.19 (radius literal eradication + flip-up container boundary), C7.X.20 (Delete Permanently modal invisible — wrong CSS class names), C7.X.21 (typed-confirm changed from reservation title to constant "DELETE"). Version 2.3.10. All smokes green: c7x21 15/15, c7x20 19/19, c7x19 13/13, c7x18 31/31. BROWSER VERIFY STILL PENDING for C7.X.21 (DELETE flow end-to-end).

---

## C7.X.16 — Whitney's C7.X.15 walkthrough fix-ups (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1557/1557 green (was 1520; +37 from new `c7x16-walkthrough-fixups-smoke.php`).

### 9 issues consolidated

| Issue | Action | Verdict |
|---|---|---|
| A — main column double padding | `.eem-reservation-editor-body { padding: 18px 18px }` stripped (real source, not `.eem-edit-main`). Mockup already matched canon — no mockup edit. | Fixed |
| B — repeating-table border-radius | No code change. C7.X.15 actually landed; Whitney saw cached CSS. C7.X.16 cache-bust to 2.3.5 resolves. New smoke confirms all 4 classes use `var(--eem-radius-sm)`. | Cache-bust |
| C — legacy SELECT exclusion sweep | All 37 bare `select` selectors in admin-legacy.css now carry `:not(.eem-dashboard-range-select):not(.eem-list-select):not(.eem-toolbar-select):not(.eem-field-select)` exclusion chain. Same C7.X.10/11 pattern, applied to SELECT in legacy file. | Fixed |
| D1 — Preview label | "Preview Frontend Form" → "Preview" | Fixed |
| D2 — Preview underline-on-hover | `a.eem-btn-preview:hover` umbrella selector + `text-decoration: none` forces override of `.eem-page a:hover` (DS-1.A.1 lesson) | Fixed |
| D3 — Preview 404 | Per recommendation (a): `<button disabled>` with tooltip "Customer preview available after C10 ships." Preserves visual presence; clicks no-op. CLEANUP #52 queues C10 wire-through. | Deferred to C10 |
| E — Media Library modal bleed | Z-index audit found NO conflict in our CSS (highest is 500; modal is 100050). Defensive raise `.media-modal-backdrop, .media-modal { z-index: 200000 }`. No `!important` (cascade order wins). CLEANUP #53 queues C16 DevTools investigation. | Defensive fix |
| F — meta-line shows "(no event linked)" on res 44 | Data regression, not code. `_en_event_id` got cleared (likely by Whitney's C7.X.15 (unlink) click during verify). Resolver correctly returns empty when unlinked → meta-line correctly displays the empty state. **Post-commit op:** re-run `wp eval-file tests/seeds/seed-reservation-44-link-event.php` to restore linkage. | No code change |
| G — Trash row Restore-only | Added Delete Permanently button to trash branch + JS handler with "cannot be undone" confirm + `handle_delete_permanently()` page method (guards: trash status required) + admin-post hook wired + nonce in localized row-action map. Activity log writes `reservation_deleted_permanently` before the actual delete. | Fixed |
| H — Count vs list mismatch | `counts_by_tab()` rewrote — no longer uses `wp_count_posts()`; now iterates each tab through a count-only WP_Query (matching `get_paginated()`'s query path) and returns `found_posts`. Aligns count and list under identical pre_get_posts filtering. | Fixed |
| I — Publish-gate validator | **Heaviest piece (~290 LOC).** Per-section validator + server gate + JS highlight + CSS + smoke. Architecture per Whitney's audit-approval. Save Draft bypasses gate. | Fixed |

### Issue F operational re-seed

The seed script is idempotent and non-destructive. Run AFTER commit lands:

```bash
wp eval-file tests/seeds/seed-reservation-44-link-event.php
```

This restores `_en_event_source='native'` + `_en_event_id=<native event>` + the canonical `_equine_event_manager_event_*_date` keys on the seeded native event. Meta-line will then render real values: "2025 Spring Classic (seeded by C7.X.9)" / "Mar 10, 2025 – Mar 12, 2025".

Note: the (change) and (unlink) handlers BOTH have confirm prompts already (verified in audit — Clarification A check found existing prompts; updated the (change) prompt wording to reference "rail card" instead of stale "meta-line" reference from pre-C7.X.15 Item 7 retirement era).

### Issue I architecture detail (locked decisions)

- **Server gate triggers when RESULTING STATUS is `publish`** (not just `save_kind === 'publish'`). Covers both `save_kind === 'publish'` AND `save_kind === 'update'` — Clarification B applied. Save Draft is the ONLY path that bypasses the gate.
- **Per-section rules** implemented exactly to Whitney's spec (Check-In times, Event Day ≥1 of 4, Stall stay-type+rate+schedule+EB, RV parallel, Add-Ons row+price>0, Group riders>0, Fees Flat/Pct amount>0, Agreement file_id, Cancellation resolver non-empty).
- **Cancellation rule** uses `EEM_Cancellation_Policy::resolve_for_reservation()` if override is blank — confirms admin doesn't need to retype if event default exists (Whitney's clarification 3).
- **Toast wording (Clarification C):** single failure → specific section message; multi-failure → "N sections need attention before publishing." Implemented via `_n()` translatable string + count-based branching.
- **Highlight CSS:** `.eem-reservation-editor-section.eem-section-invalid { border-color: #b91c1c; box-shadow: 0 0 0 1px #b91c1c; }` + header tint. Auto-clears after 6s; reapplies on next publish click.
- **Scroll:** `firstCard.scrollIntoView({ behavior: 'smooth', block: 'start' })`.
- **NO "Publish anyway" override** — Whitney's explicit direction. Admin must fix or toggle off.

### CLAUDE.md additions (2 new structural-defense entries)

1. **Cross-stylesheet cascade enumeration extends to SELECT in admin-legacy.css too** — the C7.X.13/15 prefix discipline tied WP core specificity in admin.css, but admin-legacy.css has its own (often higher-specificity) blocks that need parallel `:not()` exclusions. Pattern generalizes: any new plugin form-control class must check BOTH admin.css AND admin-legacy.css. Operational `grep` pattern documented.

2. **Pre-publish validation as a pattern** — multi-section editor publish-gate architecture (PHP source of truth + server gate + JS highlight + CSS + smoke). Generalizes to C13 Create Order, C14 Collect Payment, future toggle-gated editors.

### Files changed (10 modified + 1 NEW smoke)

- `equine-event-manager.php` — version 2.3.4 → 2.3.5
- `assets/css/admin.css` — Issue A padding strip, Issue D2 hover umbrella, Issue D3 disabled state, Issue E modal z-index raise, Issue I `.eem-section-invalid` highlight
- `assets/css/admin-legacy.css` — 37 bare-select selectors get `:not()` exclusion chain (Issue C)
- `assets/js/admin.js` — Issue I publish-validation response handler (highlight + scroll + auto-clear), Issue G delete-permanently arm, retired-context (change) confirm wording
- `templates/admin/reservation-editor/_rail-publish-card.php` — Preview button label + render shape (Issues D1/D3)
- `admin/class-eem-reservation-editor-page.php` — `validate_for_publish()` method (~150 LOC, Issue I) + ajax_save gate
- `admin/class-eem-reservations-list-page.php` — Delete Permanently UI + `handle_delete_permanently()` method + nonce localization (Issue G)
- `includes/class-eem-reservations-list-repo.php` — `counts_by_tab()` rewrite (Issue H)
- `includes/class-equine-event-manager.php` — admin_post hook for delete-permanently (Issue G)
- 4 existing smokes (c4d, c7c1, c7x12, c7x14, c7x15) — assertion drift fixes for Issue C/F/I cascade effects
- `tests/smoke/c7x16-walkthrough-fixups-smoke.php` — NEW, 37 assertions across 9 issue groups
- `CLAUDE.md` — 2 new structural-defense entries
- `CLEANUP.md` — 2 new entries (#52 Preview C10 wire, #53 Modal z-index root-cause investigation)
- `SESSION-NOTES.md` — this entry

### C7.X.16 commit: `[hash filled after commit]`

### Whitney's SHORT consolidated visual verify (post-commit + post-re-seed)

1. **Issue A:** main column content starts at 18px top / 22px left from editor card edge (DevTools check). Right rail unchanged.
2. **Issue B:** repeating-table inputs (RV Add-Ons, General Add-Ons, Lot Zones) render 3px border-radius.
3. **Issue C:** Dashboard "Last 30 days" select matches editor select height.
4. **Issue D:** Preview button reads "Preview" (no "Frontend Form"), no underline on hover, button is visually muted (disabled state), tooltip on hover says "Customer preview available after C10 ships."
5. **Issue E:** Agreement Upload modal opens with native WP Media Library chrome (no admin sidebar bleed).
6. **Issue F:** Meta-line shows "2025 Spring Classic (seeded by C7.X.9)" / "Mar 10, 2025 – Mar 12, 2025" (after seed re-run).
7. **Issue G:** Trash list row meatballs shows both Restore AND Delete Permanently. Delete Permanently triggers confirm with "cannot be undone" warning.
8. **Issue H:** Draft (N) tab header count matches the list "Showing N–N of N" line. Same for all tabs.
9. **Issue I:** Toggle a section ON without filling its required fields, click Publish. Save blocks, toast surfaces specific error (1 failure) or "N sections need attention before publishing." (multi), section card highlighted red, scroll moves to first failed section. Save Draft still works without validation.

---

---

## C7.X.15 — Whitney's walkthrough fix-ups (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1520/1520 green (was 1496; +24 from new `c7x15-walkthrough-fixups-smoke.php`, plus net +0 on updated c7b1/c7c1/c7c2-1/c7x-build-to-mockup/c7x12/c7x14 assertions reflecting Issue 7 reversal).

### 7 issues consolidated

| Issue | Status | LOC |
|---|---|---|
| 1 — main column "double padding" | NOT MODIFIED — values match mockup canon exactly; surfaced as DevTools follow-up question for Whitney | 0 |
| 2A — Publish / Save Draft / Update dead | Fixed — generic selectors, reload-on-success, dead `eemUpdateSaveBarButtons` removed | ~15 |
| 2B — Agreement Upload button dead | Fixed — `wp_enqueue_media()` on editor page + click handlers for wp.media flow + in-place file-row update | ~50 |
| 2-structural — button-handler enumeration smoke | NEW — every `data-eem-action` button in editor must have a JS handler; typeahead-search skip-listed pending backend | ~25 |
| 3 — WP core `input, select { margin: 0 1px }` leak | Fixed — `margin: 0;` reset on all prefixed `input./textarea./select.` rules; smoke walks every prefixed rule block | ~12 |
| 4 — repeating-table border-radius | Fixed — 4 classes (`.eem-repeat-input`, `.eem-repeat-price-in`, `.eem-zone-name-input`, `.eem-zone-price-in`) use `var(--eem-radius-sm)` (3px) for tighter chrome per Whitney's pixel-explicit direction | ~6 |
| 5 — select dropdowns WP core override | Fixed — `select.` prefix on 4 classes (`.eem-dashboard-range-select`, `.eem-list-select`, `.eem-toolbar-select`, `.eem-field-select`); select-enumeration smoke (extends C7.X.11 pattern to SELECT) | ~25 |
| 6 — folded into Issue 7 | N/A | 0 |
| 7 — Linked Event hybrid restoration | RESTORED — partial reversal of C7.X.12 Item 7. Rail card returns with typeahead + Change link + ✕ icon Unlink. Meta-line reverts to read-only context. Mockup updated. MD5 bumped. | ~120 |

### Item 7 reversal rationale (logged)

C7.X.12 Item 7 retired the rail Linked Event card and moved actions inline to the meta-line. Rationale at the time: reclaim ~250px of right-rail vertical, avoid duplicate display of linked event info between meta-line and rail.

C7.X.15 Issue 7 partially reverses that decision based on Whitney's walkthrough findings:
- Meta-line action links cluttered the workflow-first signal ("a lot of text here")
- WP admin convention puts post-meta in the right rail; admins expect actionable controls there
- "First step" workflow signal (the meta-line at the top) benefits from being pure read-only context

New hybrid: **meta-line is read-only context (workflow signal); rail card carries actionable controls (Change link + ✕ icon Unlink)**. Best of both worlds. Mockup updated to match. Unlink is intentionally terse icon-only (with aria-label + title tooltip) per Whitney's "less verbose" spec — change is the regular action and gets a text link, unlink is the rare action and stays out of the way visually.

This is a product-decision-driven reversal, not a bug-driven one. Item 7's original retirement was a reasonable interpretation of the spec at the time; visual verify with the actual editor in front of Whitney revealed the better answer was hybrid.

### 3 CLAUDE.md structural-defense additions (per Whitney's directive)

1. **Cross-stylesheet cascade enumeration must extend to SELECT, not just INPUT.** WP core forms.css applies same-specificity rules to `<select>` and `<textarea>` too. Plugin classes on those elements need parallel `select.classname` / `textarea.classname` prefixes. Going forward, the cross-stylesheet enumeration rule applies to all three form-control tags uniformly.

2. **Cross-stylesheet enumeration must check ALL declared properties, not just the property that surfaced the visible bug.** C7.X.13 was scoped narrowly to `border-radius`; the same WP core rule also declared `margin: 0 1px` which surfaced as a separate visual bug at C7.X.15. Operational rule: dump the full WP-core rule block, list every property, cross-check our matching rule sets each one (or explicitly resets — `margin: 0`, `min-height: auto`, etc.).

3. **Button-handler enumeration smoke.** Same shape as the form-control class enumeration smoke from C7.X.11, applied to button-action attributes. Render the page, extract every `data-eem-action="..."` attribute on buttons/links, assert each appears as a `t.closest('[data-eem-action="..."]')` match in JS source. Catches "shipped a button with no handler" bugs — exactly the latent agreement-upload bug surfaced this commit. Skip-list typeahead-search / backend-pending actions explicitly with a comment.

### Implementation decisions made (Whitney's three clarifications)

- **Clarification A — Issue 2B Media Library scope:** all 4 sub-requirements met. PDF MIME restriction (`library: { type: 'application/pdf' }`), persist attachment id to `_en_venue_agreement_file_id` hidden input, file-row display updates in place (filename + View link + Replace label + Delete button injected), Delete button clears the id and reverts to empty state. ~50 LOC, within the original ~40 estimate's tolerance.
- **Clarification B — Issue 7 typeahead placement:** inline in rail card (the pre-C7.X.12 pattern), not modal-launched. Confirmed.
- **Clarification C — Cache-bust verification:** pre-flight grep confirmed all 3 CSS/JS enqueues use `$ver` (constant). 2 hardcoded `2.3.3` literals (plugin header + constant) both updated atomically to 2.3.4. No drift.

### Issue 4 token-scope question (surfaced for Whitney's follow-up)

`--eem-radius` (4px) is the editor-wide standard for form inputs + buttons + chips (7 active usages site-wide). `--eem-radius-sm` (3px) is for tighter contexts. C7.X.15 applied `--eem-radius-sm` to 4 repeating-table input classes per Whitney's pixel-explicit direction. **If she wants `--eem-radius-sm` applied more broadly** (e.g. all editor inputs at 3px instead of 4px), that's a follow-up token-scope change touching many classes. Not in C7.X.15 scope. Surface for Whitney's review.

### C7.X.15 commit: `[hash filled after commit]`

### Files changed (12)

- `equine-event-manager.php` — version bump 2.3.3 → 2.3.4
- `admin/class-equine-event-manager-admin.php` — `wp_enqueue_media()` on editor page (~6 LOC)
- `admin/class-eem-reservation-editor-page.php` — re-add rail-linked-event-card require
- `assets/css/admin.css` — `margin: 0;` resets, `--eem-radius-sm` token swaps, `select.` prefixes, dead-code cleanup, new `.eem-event-linked-actions` + `.eem-event-unlink-icon` rules
- `assets/js/admin.js` — `eemDispatchSave` + `eemReservationEditorNonce` generic-selector fix, removed dead `eemUpdateSaveBarButtons`, new agreement-upload + agreement-remove handlers
- `templates/admin/reservation-editor/_meta-line.php` — REVERTED to read-only (Issue 7)
- `templates/admin/reservation-editor/_rail-linked-event-card.php` — RESTORED + modified per spec (Change text link + ✕ icon Unlink) (Issue 7)
- `.mockups/edit_reservation_page.html` — Issue 7 hybrid restoration (meta-line strip, rail card restore)
- `tests/smoke/mockups-presence-smoke.php` — MD5 bump for edit_reservation_page.html
- `tests/smoke/c7b1-smoke.php`, `c7c1-smoke.php`, `c7c2-1-smoke.php`, `c7x-build-to-mockup-smoke.php`, `c7x12-affix-agreement-meta-smoke.php`, `c7x14-responsive-and-sweep-smoke.php` — stale assertions updated to Issue 7 hybrid shape
- `tests/smoke/c7x15-walkthrough-fixups-smoke.php` — NEW, 24 assertions across 7 issue groups
- `CLAUDE.md` — 3 new structural-defense entries (cross-stylesheet enumeration extended to SELECT, all-properties enumeration, button-handler enumeration smoke)
- `SESSION-NOTES.md` — this entry

### Whitney's SHORT consolidated visual verify

- [ ] Currency $ chip + input still seamless (Issue 3 margin fix didn't regress C7.X.13 prefix work)
- [ ] Repeating-table inputs render 3px border-radius
- [ ] Dashboard "Last 30 days" select matches standard editor select height
- [ ] Publish / Save Draft / Update buttons functional (Cluster A fix)
- [ ] Agreement section "Upload" button launches WP Media Library (Cluster B fix)
- [ ] Meta-line is read-only context (no action links)
- [ ] Right rail: Publish → Linked Event (typeahead + Change link + ✕ icon unlink) → Shortcode
- [ ] Issue 1 padding visual sanity check — if it still feels doubled, Whitney inspects in DevTools and reports the specific element pair

---

---

## C7.X.14 — C7 final closeout (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1496/1496 green (was 1465; +31 from `c7x14-responsive-and-sweep-smoke.php`). One walkthrough check away from C7 closing.

### What landed in this consolidated closeout pass

1. **VV-7 responsive breakpoints — already mockup-canonical, smoke added.** Audit found that admin.css already shipped the canonical mockup `@media` breakpoints (1024px → grid `1fr 260px`; 767px → grid `1fr`, rail static + order:-1, sticky-save reveals). Pre-existing work — `c7x14-responsive-and-sweep-smoke.php` adds presence-assertions so future regressions trip immediately.

2. **Two MORE unprefixed input classes prefixed with `input.`** (extending C7.X.13 WP-core specificity tie to remaining vulnerable inputs surfaced during the regression sweep):
   - `.eem-repeat-input` → `input.eem-repeat-input` (Add-On row name + per-unit inputs)
   - `.eem-zone-name-input` → `input.eem-zone-name-input` (RV Lot Zone name input)
   `:focus` variants too. Both are `<input type="text">` — same WP-core forms.css (0,1,1) override pattern as the number inputs from C7.X.13. Whitney didn't sight them because they're standalone inputs (not affix patterns), so the 2px-vs-4px corner difference is visually subtle. Fixed proactively.

3. **Full-editor regression sweep — render res 44, every section enumerated.** Per-section element-shape inventory cross-checked against `.mockups/edit_reservation_page.html`:
   - 10 sections render in canonical order (description → cancellation) ✓
   - Section element counts (field-rows, toggle-label-rows, stay-type-btns, price-wraps, etc.) match expected shape per partial ✓
   - Rail card count is 2 (Publish + Shortcode; Linked Event retired per Item 7) ✓
   - Meta-line carries (change) + (unlink) action links ✓
   - Agreement Label field renders ✓
   - `.eem-price-wrap align-items: stretch` intact (C7.X.12 seam fix) ✓
   - `input.eem-price-input` prefix intact (C7.X.13 WP-core tie) ✓
   - Group section sub-toggles use ID-based controls (VV-2 fix intact) ✓
   - Sticky-save mobile partial renders ✓
   No structural drift found.

4. **CLAUDE.md addition — "Full-editor regression sweep" as the canonical pre-release verification step.** The sweep this commit ran (render the canonical seed reservation, enumerate per-section element shape, cross-check against mockup) is now codified as a discipline. Prevents future "I forgot to check section X" misses.

5. **Forward-compat version assertions.** C7.X.13's `EQUINE_EVENT_MANAGER_VERSION === 2.3.2` hard pin updated to `version_compare(..., '2.3.2', '>=')`. C7.X.11's same fix from earlier still in place. Future cache-bust bumps don't trip these smokes.

6. **EQUINE_EVENT_MANAGER_VERSION 2.3.2 → 2.3.3.** Cache-bust for the two prefix bumps.

### Files changed (5)

- `assets/css/admin.css` — 2 more selector prefixes (`input.eem-repeat-input`, `input.eem-zone-name-input` + their `:focus` variants) ~6 LOC
- `equine-event-manager.php` — version bump 2 LOC (constant + header)
- `tests/smoke/c7x14-responsive-and-sweep-smoke.php` — NEW, 30 assertions across 3 groups (responsive breakpoints, 2 new prefixes, full-editor sweep)
- `tests/smoke/c7x13-wp-core-specificity-smoke.php` — version assertion made forward-compatible
- `CLAUDE.md` — "Full-editor regression sweep" discipline added as a closeout sub-step
- `SESSION-NOTES.md` — this entry

### NO OTHER C7 LOOSE ENDS FOUND

Scan of CLEANUP.md surfaced only deferred items explicitly tagged to C8 / C10 / C16 / DS-1 (`#47` group meta keys → C10; `#46` legacy render helpers → C16; `#45` C7.C.1.4.B → already done; `#44` `_en_*_enabled` rename → C16; `#43` audit scripts → already deleted; `#42` save bar → already shipped via rail Publish card). None in C7 scope.

SESSION-NOTES "awaiting Whitney" mentions are all closed: C7.X.9 visual verify (done), Item 7 decision (locked + landed in C7.X.12), C7.X.10/11 visual verify (done).

### Walkthrough checklist for Whitney's final pass

Open reservation 44, scroll top to bottom:

- [ ] **Currency seam (VV-3)** — every `$` chip + input pair on the editor reads as one unified rectangle, no visible seam. Sites to spot-check: Pricing (description), RV Nightly/Weekend Rate, Group Grounds Fee Amount + Deposit Amount, Convenience Fee Flat, General Add-On price rows, RV Add-On price rows, RV Lot Zone surcharge.
- [ ] **Agreement Label (VV-4)** — Agreement section toggled ON shows a single-line text input ABOVE the Agreement PDF row, placeholder "Agreement name (ex: Venue Agreement)".
- [ ] **Linked Event meta-line (Item 7)** — meta-line at top shows event title with `(change)` and `(unlink)` small text links inline. Right rail has Publish + Shortcode cards only, no Linked Event card.
- [ ] **Responsive (VV-7, optional)** — narrow the viewport below 1024px: rail shrinks from 300px to 260px. Below 767px: rail collapses ABOVE main column, fixed-bottom sticky save bar appears.

If all four pass, C7 closes.

---

---

## C7.X.13 fix-up — WP core forms.css specificity tie (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1465/1465 green (was 1451; +14 from `c7x13-wp-core-specificity-smoke.php`).

### Root cause — FIFTH commit on VV-3, finally the right file

After C7.X.10 (admin-legacy.css `:not()` exclusions on 3 classes), C7.X.11 (extended to 5 classes + structural enumeration smoke), C7.X.12 (flex align-items fix), Whitney's verify of C7.X.12 caught a residual seam: `.eem-price-input` left border-radius still visibly rounded.

**The winner was WP core**, not admin-legacy.css. `wp-admin/css/forms.css:42-56`:
```css
input[type="email"], input[type="number"], input[type="search"], ... {
    border-radius: 2px;
    border: 1px solid #949494;
    ...
}
```
Specificity `input[type="number"]` = **(0,1,1)**. Our `.eem-price-input` = **(0,1,0)**. WP wins, rounds all four corners to 2px → left corners rounded → seam.

FIVE commits looked at admin-legacy.css exhaustively. None of them grepped WP core CSS. The C7.X.11 structural enumeration smoke caught form-control classes inside our own codebase but didn't cross-check WP core's same-specificity overrides.

### Why this stayed hidden through the prior fixes

Until C7.X.12's `align-items: stretch` fix, the alignment gap was the dominant visual artifact — it masked the 2px rounding-on-left that WP core was producing. Once C7.X.12 closed the alignment gap and the chip/input butted edge-to-edge, the small 2px curve on the input's left became the visible-and-obvious bug. Whitney sighted it immediately. The bug was always there; just hidden behind a worse bug.

### Fix shape

Bump our 5 affix/field input class selectors from `.classname` to `input.classname`. Specificity (0,1,0) → (0,1,1) — ties WP core. At a tie, cascade order wins; admin.css enqueues after WP forms.css → our rule wins.

| Was | Becomes |
|---|---|
| `.eem-price-input` | `input.eem-price-input` |
| `.eem-pct-input` | `input.eem-pct-input` |
| `.eem-repeat-price-in` | `input.eem-repeat-price-in` |
| `.eem-zone-price-in` | `input.eem-zone-price-in` |
| `.eem-field-input` | `input.eem-field-input` (+ siblings `textarea.eem-field-textarea`, `select.eem-field-select`) |

`:focus`, `::placeholder` variants too. ~12 selector lines updated in admin.css. EQUINE_EVENT_MANAGER_VERSION bumped 2.3.1 → 2.3.2 for cache-bust.

### Process-miss escalation — paying forward

**Container-flex parity check (C7.X.12) extended with cross-stylesheet cascade enumeration (C7.X.13).** When the cascade question IS the right question (after container parity verified), the enumeration of "every rule that could win" must cover ALL CSS sources:
- Plugin's own admin.css + admin-legacy.css
- **WP core** — `wp-admin/css/forms.css` (canonical first-place for form-control overrides), `dashboard.css`, `common.css`, `wp-admin.css`
- Active theme CSS (if applicable to the surface)

CLAUDE.md `Mockup Walkthrough Pre-Audit` section gained the WP-core grep step. For form-control classes specifically, `wp-admin/css/forms.css:42-56` is now flagged as the canonical first place to check — it applies `border-radius`, `border`, `background`, `color`, `box-shadow` to every standard input type at specificity (0,1,1). Any plugin class on those inputs MUST either prefix with element tag (`input.classname`) or use a parent-descendant compound to win the cascade.

Sibling-test variant added: **when an affix-pattern visual works for one input class but not another, check whether the working one carries an `input.` prefix and the broken one doesn't**. (None of our 5 carried the prefix; visual correctness was masked by the prior alignment bug.)

### C7.X.13 commit: `[hash filled after commit]`

### Files changed (4)
- `assets/css/admin.css` — 5 affix/field class selectors prefixed with `input.` (+ siblings for textarea/select), `:focus` + `::placeholder` variants updated. Audit-trail comment block at `.eem-price-input` documents the WP-core cause + cascade-tie rationale.
- `equine-event-manager.php` — `EQUINE_EVENT_MANAGER_VERSION` 2.3.1 → 2.3.2 (plugin header `* Version:` in lockstep)
- `tests/smoke/c7x13-wp-core-specificity-smoke.php` — NEW, 14 assertions:
  - PRESENCE: each of 5 prefixed `input.classname` selectors present
  - ABSENCE: zero bare unprefixed `.classname` rule-openings (comments excluded via pre-scan strip)
  - PRESENCE: `.eem-price-input` border-radius shape (left corners zero) intact
  - PRESENCE: WP core forms.css contains the overriding `input[type="number"] { border-radius: 2px; }` rule (root-cause confirmation, fails fast if WP version changes the selector shape)
  - PRESENCE: `EQUINE_EVENT_MANAGER_VERSION === 2.3.2`
- `tests/smoke/c7x11-affix-add-buttons-smoke.php` — version assertions made forward-compatible via `version_compare(..., '2.3.1', '>=')` so future cache-bust bumps don't trip the smoke
- `CLAUDE.md` — cross-stylesheet cascade enumeration sub-step added to Container-flex parity check
- `SESSION-NOTES.md` — this entry

### Whitney's check (one-line, no DevTools required)

- [ ] `.eem-price-input` left corners now FLAT against the chip on res 44. The seam closes, finally.

---

---

## C7.X.12 fix-up — three deliverables consolidated (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1451/1451 green (was 1419; +32 from new `c7x12-affix-agreement-meta-smoke.php`). Whitney to do SHORT visual verify (3 quick checks per spec).

### Deliverable 1 — VV-3 REAL fix

After **four** commits chasing CSS specificity (C4 → C7.X.4 → C7.X.10 → C7.X.11), the actual root cause of the visible seam on `.eem-price-input` affix sites was a **flex-alignment** bug, NOT a cascade bug. The C7.X.10/11 `:not()` exclusion work was correct AND necessary — it just wasn't the visual fix on its own.

Side-by-side that should have been done at audit time (and now is, per the new CLAUDE.md sub-step):

| Wrap | `align-items` | Visual result |
|---|---|---|
| `.eem-price-wrap` (BEFORE C7.X.12) | `center` | Chip + input render at natural heights, centered → visible vertical gap top + bottom of chip = "two boxes" seam |
| `.eem-pct-wrap` (working from C1.2) | `stretch` | Chip stretches to input height → one continuous rectangle |
| `.eem-repeat-price-wrap` (Add-On) | `stretch` (always was) | Fine after C7.X.11 cascade fix added |
| `.eem-zone-price-wrap` (RV Lot Zone) | `stretch` (always was) | Fine after C7.X.11 cascade fix added |

**Full mockup-canon pass landed in admin.css** (took the optional aesthetic alignments per Whitney's direction):
- `.eem-price-wrap` `align-items: center` → `stretch` (THE seam fix)
- `.eem-price-symbol` ADDED `display: flex; align-items: center;` (so `$` stays centered in the now-stretched chip)
- `.eem-price-symbol` border `var(--eem-border-input)` (1.5px) → `1px solid #8c8f94` (mockup canon)
- `.eem-price-symbol` background `#f6f7f7` → `#f3f4f5` (mockup canon)
- `.eem-price-input` border `var(--eem-border-input)` (1.5px) → `1px solid #8c8f94`
- `.eem-price-input` padding `8px 11px` → `8px 12px` (mockup canon)
- `.eem-price-input:focus` ADDED `box-shadow: 0 0 0 2px rgba(22, 104, 242, 0.12)` (mockup canon focus glow)

C7.X.10/11 `:not()` exclusions + C7.X.11 enumeration smoke KEPT. They're correct, they're necessary, they're structural defense for future form-control ports. They just weren't the visual fix here on their own.

### Deliverable 2 — VV-4 Agreement Label field

New admin-editable text field surfaces customer-facing link text for the agreement.

**Meta key naming decision:** `_en_venue_agreement_link_label`. Three meta keys now make up the `venue_agreement_*` family:
| Key | Purpose |
|---|---|
| `venue_agreement_label` | Checkbox label text — "I agree to the venue terms and conditions." (existing, unchanged) |
| `venue_agreement_file_label` | Admin display label for the file — "Agreement" (existing, unchanged) |
| `venue_agreement_link_label` | Customer-facing link text — empty default, falls back to literal "Venue Agreement" (NEW, C7.X.12) |

The conflict (existing `venue_agreement_label`) was caught at wire time; per the standing "ask before renaming stored data" rule, the new field gets a distinct name rather than renaming the existing one. This sidesteps a stored-data migration.

**Wired surfaces:**
- `_section-agreement.php` partial renders the input ABOVE the Agreement PDF row, placeholder verbatim "Agreement name (ex: Venue Agreement)"
- CPT `sanitize_meta_submission` reads + sanitizes via `sanitize_text_field`
- CPT + shortcode `get_default_meta_values` declare `''` default
- Customer-facing event-page yellow callout (`public/class-equine-event-manager-shortcodes.php:1065-1075`) reads the new key first, falls back to `venue_agreement_file_label` (3rd-tier fallback for pre-existing reservations that set the older admin display key), falls back to literal "Venue Agreement" when both blank
- `.mockups/edit_reservation_page.html` updated — Agreement section now includes the new field row above PDF row, mockup MD5 in `mockups-presence-smoke.php` updated to match

### Deliverable 3 — Item 7 Linked Event rail card retirement

Right-rail "Linked Event" card retired entirely. Linked-event editing moved inline to the meta-line.

**Changes:**
- `_meta-line.php` — added `(change)` + `(unlink)` action links inline next to the linked event title. When no event is linked, renders single `(link event)` affordance.
- `_rail-linked-event-card.php` partial DELETED from disk (`git rm`).
- Editor page no longer `require`s the rail partial.
- Right rail now: Publish card → Shortcode card. Reclaimed ~250px vertical for future cards + Publish breathing room.
- `assets/css/admin.css` — added `.eem-meta-action` / `.eem-meta-action--danger` rules for the inline action links.
- `assets/js/admin.js` — new `reservation-editor-event-change` click handler. Reuses the existing unlink dispatcher for a confirm-then-unlink-then-reload flow. **Full inline typeahead modal deferred** — current scope retires the rail card + delivers a functional change-flow without dragging into new UI work. Typeahead modal is a focused follow-up if Whitney wants it (low priority — the (link event) affordance after unlink is a working 2-click flow).
- `_rail-linked-event-card.php` formal deletion logged in CLEANUP. The JS handler retains the `.eem-repeating-row-helper` fallback path (the C7.X.11 fix) and the `(unlink)` handler is identical to the rail-card's, so no JS orphan code added.
- `.mockups/edit_reservation_page.html` updated — meta-line shows the new (change)/(unlink) pattern, rail card section replaced with an explanatory comment block referring back to the meta-line.

### Pre-flight + during-flight regression handling

Stale assertions from prior chunks updated to reflect the new architecture:
- `c7b1-smoke.php` — "3 rail cards (Publish, Linked Event, Shortcode)" → "2 rail cards (Publish, Shortcode — Linked Event retired)"
- `c7c1-smoke.php` — "rail Linked Event card renders" → "NO rail Linked Event card"
- `c7c2-1-smoke.php` — same shape
- `c7x-build-to-mockup-smoke.php` — count + Linked-Event presence + search-input assertions all updated; positive guard added for the meta-line action-link replacement

`mockups-presence-smoke.php` MD5 for `edit_reservation_page.html` updated to `30251dd5ea2eab3b4ce3c2ba5d5945d3` matching the C7.X.12 mockup edits.

### Process-miss escalation — "CSS-cascade tunnel vision" (paying forward)

C4's `.eem-search-input` underline taught us to ask "which rule wins the cascade?" That lens IS the right one for some bugs (border-radius `!important` blocks) and the WRONG one for others (flex container alignment). C7.X.10/11 over-applied the lens.

**Structural fix landed in CLAUDE.md** (new sub-step under Mockup Walkthrough Pre-Audit):

> Container-flex parity check is the canonical audit sub-step for any affix/group/composite layout. Paste both the mockup's container `display`/`align-items`/`gap`/`flex-*` AND ours side-by-side BEFORE asking "which rule wins?". Identify drift FIRST; THEN — only if container properties match — proceed to cascade-specificity questions.

The operational test added to the sub-step: **find a sibling component in the same codebase that uses the same pattern and works. If its container properties differ from the broken one's, the bug is container-level.** `.eem-pct-wrap` was the sibling for `.eem-price-wrap`. We didn't check across three commits.

Adds ~5-10 minutes to pre-audit. Would have saved three C7.X.* commits.

### C7.X.12 commit: `[hash filled after commit]`

### Files touched (9 modified + 1 NEW smoke + 1 DELETED partial)

- `assets/css/admin.css` — `.eem-price-*` rules brought to mockup canon (7 changes) + new `.eem-meta-action` rules for the inline meta-line links (3 LOC)
- `templates/admin/reservation-editor/_section-agreement.php` — Agreement Label row added above PDF row (~12 LOC)
- `includes/class-equine-event-manager-reservations-cpt.php` — `venue_agreement_link_label` in sanitize + defaults (~2 LOC + comments)
- `public/class-equine-event-manager-shortcodes.php` — customer-facing fallback chain (link_label → file_label → literal) (~12 LOC) + defaults declaration (~1 LOC)
- `templates/admin/reservation-editor/_meta-line.php` — REWROTE for new action-links pattern (~50 LOC, was ~25)
- `admin/class-eem-reservation-editor-page.php` — removed rail-linked-event require + 4-line comment (~6 LOC delta)
- `assets/js/admin.js` — new `reservation-editor-event-change` click handler (~25 LOC)
- `templates/admin/reservation-editor/_rail-linked-event-card.php` — **DELETED** via `git rm`
- `.mockups/edit_reservation_page.html` — meta-line action links + Agreement Label row + retired rail card block (3 edits)
- `tests/smoke/c7b1-smoke.php`, `c7c1-smoke.php`, `c7c2-1-smoke.php`, `c7x-build-to-mockup-smoke.php` — stale assertions updated to new architecture
- `tests/smoke/mockups-presence-smoke.php` — MD5 hash updated for edit_reservation_page.html
- `tests/smoke/c7x12-affix-agreement-meta-smoke.php` — NEW, 32 assertions across 3 deliverables
- `CLAUDE.md` — Container-flex parity check sub-step added to Mockup Walkthrough Pre-Audit
- `SESSION-NOTES.md` — this entry

### Visual-verify checklist (Whitney's three short checks)

- [ ] Currency $ chip + input read as one unified rectangle on res 44 — no seam, matches mockup Stall Nightly Rate
- [ ] Agreement section toggled ON: new "Agreement Label" text input ABOVE the Agreement PDF row, placeholder "Agreement name (ex: Venue Agreement)"
- [ ] Linked Event area: meta-line at top shows "2025 Spring Classic (seeded by C7.X.9)" with small "(change)" and "(unlink)" links; right rail has Publish + Shortcode only (no Linked Event card)

If all three pass, C7.X.12 closes and the editor is architecturally complete.

---

---

## C7.X.11 fix-up — affix recurrence + add-buttons + structural enumeration (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1419/1419 green (was 1402; +17 from
new `c7x11-affix-add-buttons-smoke.php`). Whitney to visual-verify with
**DevTools "Disable cache" enabled** in Network tab (isolates cache-bust
from missing-class-enumeration fix).

**2 root-cause bugs from C7.X.10 visual verify + 1 structural deliverable:**

1. **VV-3 recurrence — Add-On price seam.** `.eem-repeat-price-in`
   (the price input in `_section-addons.php` + `_section-rv.php`
   repeating-row tables) was missing from the C7.X.10 exclusion list.
   Whitney's review of C7.X.10 covered `.eem-price-input` +
   `.eem-pct-input`; the manual class enumeration didn't include
   `.eem-repeat-price-in` (same fragmented-naming pattern across
   editor partials). Legacy `border-radius: 8px !important` still
   won on Add-On price inputs, breaking the seam.

   **+ 2 MORE classes the structural smoke caught.** When the
   enumeration smoke ran on the rendered editor, it found 5 distinct
   `<input type="number">` classes — not just the 3 the audit
   enumerated. `.eem-field-input` (quantity-style inputs) +
   `.eem-zone-price-in` (RV Lot Zone surcharge affix) ALSO need the
   exclusion, both shipped pre-C7.X.10 without it. The smoke caught
   exactly the gap the manual checklist missed — first run, first
   commit. **This is the structural deliverable working as designed.**

   **Fix:** extend exclusion list from 3 → 5 classes on all 19
   `input[type="number"]` selectors in admin-legacy.css.

2. **VV-6 — Add-row buttons non-functional since C7.X.4.** JS handler
   at admin.js:1831 did
   `addBtn.closest('.eem-repeating-row-helper')` to find template +
   tbody IDs. C7.X.4 mockup-canonical partials emit those attrs ON
   the button itself (no wrapper). The `.eem-repeating-row-helper`
   wrapper lives only in `_repeating-row-helper.php` — a partial
   that's been orphan since C7.X.4 (zero active callers, verified).
   Result: handler returned early on every click. **Bug was latent
   from C7.X.4 to C7.X.10; never caught at visual verify until
   Whitney clicked "+ Add Add-On" on res 44.**

   **Fix:** handler reads attrs from `addBtn` directly when present,
   falls back to ancestor for any (orphan) caller still using the
   wrapper. The fallback is C16-removable along with the orphan
   partial (CLEANUP entry #50).

3. **STRUCTURAL — form-control class enumeration cross-check smoke.**
   C4 → C7.X.4 → C7.X.10 → C7.X.11 = FOUR iterations of the same
   class of bug: developer ships a new form-control class, forgets
   to add `:not(.classname)` exclusions in admin-legacy.css. The
   C7.X.10 process-miss note said "checklist must be run." VV-3
   recurring on `.eem-repeat-price-in` proved the checklist as
   written is insufficient — relies on developer memory.

   **C7.X.11 lands the structural fix:** new
   `c7x11-affix-add-buttons-smoke.php` section [2] enumerates every
   distinct class on `<input type="number">` elements in the
   editor's live rendered HTML, then asserts every enumerated class
   appears as `:not(.classname)` in every `input[type="number"]`
   selector in admin-legacy.css. The smoke trips if a future
   chunk:
     - Ships a new form-control class without adding exclusions
     - Adds a new `!important` block without including the
       exclusion list
   Removes the "did I remember to enumerate?" reliance. Tested in
   anger on C7.X.11's own run — caught `.eem-field-input` +
   `.eem-zone-price-in` immediately. **The recurring-bug cycle is
   broken.**

**Pre-flight verifications run before edits (per Whitney's
clarifications):**
- CSS enqueue uses `EQUINE_EVENT_MANAGER_VERSION` constant as `$ver`
  for both admin.css and admin-legacy.css. ✓
- Zero hardcoded `'2.3.0'` string literals in PHP outside `@since
  2.3.0` docblocks (those mark introduction version of code, not
  current plugin version — stay at 2.3.0). The plugin header `*
  Version:` + the `EQUINE_EVENT_MANAGER_VERSION` constant were
  bumped together to 2.3.1. ✓
- Zero auto-include / `glob()` / `scandir()` patterns pulling in
  `_repeating-row-helper.php`. Truly orphan. ✓

**Cache-bust:** `EQUINE_EVENT_MANAGER_VERSION` → `2.3.1`. Bumps
`?ver=2.3.1` on both CSS files. Forces fresh download on every
browser regardless of cache policy. Whitney won't fight cache
invalidation this session.

**C7.X.11 commit:** `[hash filled after commit]`

**Files touched (6):**
- `equine-event-manager.php` — 2 LOC (plugin header `Version:` +
  `EQUINE_EVENT_MANAGER_VERSION` constant, both `2.3.0` → `2.3.1`)
- `assets/css/admin-legacy.css` — 19 lines modified (5-class
  exclusion now: `.eem-price-input`, `.eem-pct-input`,
  `.eem-repeat-price-in`, `.eem-zone-price-in`, `.eem-field-input`)
- `assets/js/admin.js` — ~14 LOC (button-attr-first read with
  ancestor fallback + audit-trail comment)
- `tests/smoke/c7x11-affix-add-buttons-smoke.php` — NEW, 17
  assertions across 4 groups. Section [2] is THE structural
  deliverable.
- `CLEANUP.md` — new entry #50 (orphan `_repeating-row-helper.php`
  partial → C16)
- `SESSION-NOTES.md` — this entry

**Visual-verify checklist Whitney will walk (DevTools "Disable cache"
enabled in Network tab):**
- [ ] Currency $ chip + input unified on Pricing fields (description),
      RV Nightly/Weekend Rate, Group Grounds Fee + Deposit Amount,
      Convenience Fee Flat mode
- [ ] Currency $ chip + input unified on General Add-On Price rows
      AND RV Add-On Price rows (`.eem-repeat-price-in`)
- [ ] Currency $ chip + input unified on RV Lot Zone surcharge
      (`.eem-zone-price-in`) — bonus catch from structural smoke
- [ ] Percentage % suffix unified on Convenience Fee Percentage mode
- [ ] Click "+ Add Add-On" → new empty row appends to General
      Add-Ons table
- [ ] Click "+ Add RV Add-On" → new empty row appends to RV
      Add-Ons table

**Process-miss escalation note (paying forward):**
The recurring-bug pattern across C4 / C7.X.4 / C7.X.10 / C7.X.11 is
NOT a "developer didn't run the checklist" problem — it's a "manual
enumeration is fragile" problem. The structural fix is automation:
the enumeration cross-check smoke landed in C7.X.11 watches every
`<input type="number">` rendered by the editor and demands matching
admin-legacy.css coverage. Going forward:
  - Future form-control ports do NOT need to manually update the
    exclusion list at commit time — the smoke will fail at commit
    time and tell the developer exactly which classes need
    exclusions.
  - The pattern generalizes: any other `input[type="<X>"]` family
    with `!important` legacy blocks can land a parallel enumeration
    smoke. (Today only `[type="number"]` has this concentration;
    revisit if `[type="text"]` etc. accumulates similar problems.)
  - The checklist in CLAUDE.md "Prospective form-control port
    checklist" should be updated to point at this smoke instead of
    relying on manual enumeration. (Not landed in C7.X.11 to keep
    commit tight; will land if/when CLAUDE.md gets a maintenance
    pass.)

**Item 7 still OPEN (Linked Event rail card vs meta-line redundancy)** —
awaiting Whitney's decision after C7.X.11 visual verify.

---

---

## C7.X.10 fix-up — group sub-toggles + affix seam (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1402/1402 green (was 1381; +21 from new
`c7x10-toggle-affix-smoke.php`). Whitney to visual-verify after re-running
the seed (canonical event date keys + Step-1 backfill landed).

**3 findings from C7.X.9 visual verify on res 44 consolidated:**

1. **VV-2 — group sub-toggles non-functional.** Whitney called this
   "Fees-section"; actual scope is `_section-group.php` (Grounds Fee +
   Deposit toggles). Group was the only remaining partial still on the
   retired class-token controls system (`eem-ctrl--grounds-amt`,
   `eem-ctrl--deposit-amt`). C7.X.9's `eemApplyControlsById` does
   `document.getElementById(id)` per token, so class tokens silently
   no-op'd. **Fix:** converted to ID-based controls
   (`row-group-grounds-amt`, `row-group-deposit-amt`); dropped
   `row_classes` (legacy noise). 4 operative lines + audit-trail
   comment.

2. **VV-3 — affix seam (currency $ chip / percent % chip) — CSS
   specificity collision.** Root cause: 6 distinct `!important` blocks
   in `admin-legacy.css` target `input[type="number"]` and apply
   `border-radius: 8px !important`, overriding our
   `.eem-price-input { border-radius: 0 var(--eem-radius) var(--eem-
   radius) 0 }`. Exactly the C4 lesson recurring. **Process miss:**
   C7.X.4 form-control port shipped without running CLAUDE.md's
   "Prospective form-control port checklist". The checklist would
   have flagged this at C7.X.4 commit time and cost ~5 min to add the
   exclusions instead of two visual-verify cycles ~weeks later.
   Future form-control ports must run the checklist as part of
   pre-commit review.
   **Fix:** added `:not(.eem-price-input):not(.eem-pct-input)` to
   every `input[type="number"]` selector in admin-legacy.css. 19
   occurrences total, all covered. Count-based smoke assertion
   guards against future regression where someone adds a 7th block
   without the exclusion.
   **Specificity check on line 2690 non-!important block:** `body
   .eem-shell-page--editor input[type="number"]` = (0,2,2) beats
   `.eem-price-input` = (0,1,0). Required remediation despite no
   `!important`. Confirmed remediated.

3. **VV-5 — section order matches mockup. DROPPED.** Verification
   trail: `.mockups/edit_reservation_page.html` `.section-title`
   enumeration matches `EEM_Reservation_Editor_Page::section_definitions()`
   render order on res 44 exactly:
   1. description / Reservation Description
   2. checkin / Check-In / Check-Out
   3. eventday / Event Day Info
   4. stall / Stall Reservations
   5. rv / RV Reservations
   6. addons / General Add-Ons
   7. group / Group Reservations
   8. fees / Convenience Fee
   9. agreement / Agreement
   10. cancellation / Cancellation Policy

**Process-miss note (calibration paying forward):**
C7.X.4's port checklist miss cost ~2 visual-verify cycles + this
fix-up commit. The pattern: when shipping a NEW form-control
component (`.eem-search-input`, `.eem-price-input`, `.eem-pct-input`,
or future `.eem-time-input` etc.) the developer must:
  (a) `grep -nE 'input\[type="..."\]' admin-legacy.css` to find every
      block targeting that input type.
  (b) Add `:not(.eem-<new-component>)` to EVERY occurrence in the
      same commit that introduces the component.
  (c) State in commit message that the checklist was run + N
      exclusions added.
The C7.X.4 commit didn't do (a)/(b)/(c). Going forward, the smoke
"every input[type=...] in admin-legacy carries :not(.eem-XYZ)" pattern
landed in C7.X.10 is the structural enforcement — copy it forward
when shipping any new form-control class.

**C7.X.10 commit:** `[hash filled after commit]`

**Seed script update (tests/seeds/seed-reservation-44-link-event.php):**
C7.X.9's seed picked the first available event (TEC, 2026-06-26),
which broke c4d-smoke's sort assertion that depends on res 44's
start_date being 2025-03-10. C7.X.10 update:
  - Step 1 prefers a native `en_event` with start_date 2025-03-10
    (checks both `_equine_event_manager_event_start_date` canonical
    key AND `_en_event_start_date` legacy mirror).
  - Step 2 seeds a fresh native event with that date if no native
    matches (skips TEC fallback because TEC events have arbitrary
    dates).
  - Step 1 reuses prior-seeded "2025 Spring Classic" native event
    on re-run (idempotent, no duplicate piling).
  - Post-pick: belt-and-braces backfill writes the canonical
    `_equine_event_manager_event_*_date` keys from `_en_event_*_date`
    if missing. The native event resolver reads the canonical keys;
    the seed previously only wrote `_en_*` so the resolver returned
    empty start/end dates even with a linked native event.
  - Resolver output after seed: title='2025 Spring Classic (seeded
    by C7.X.9)', date_range='Mar 10, 2025 – Mar 12, 2025'. c4d sort
    + date-filter assertions pass.

**c7c1-4-smoke updates (3 assertions):**
Updated to match the new ID-based controls architecture:
  - grounds-fee toggle: data-controls accepts `row-group-grounds-amt`
    OR (backward-compat) `row-grounds-amt` OR
    `eem-ctrl--grounds-amt`. Backward-compat fallbacks kept until
    C16 wholesale legacy strip.
  - Grounds Fee Amount row VISIBLE: matches new
    `id="row-group-grounds-amt"` shape OR old `eem-ctrl--grounds-amt`
    class shape.
  - Deposit Amount row HIDDEN: same boundary handling.

**Files touched (6 modified + 1 new smoke):**
- `templates/admin/reservation-editor/_section-group.php` —
  Grounds Fee + Deposit toggles converted to ID-based controls
  (4 operative lines + audit-trail comments)
- `assets/css/admin-legacy.css` — `:not(.eem-price-input):not(.eem-pct
  -input)` added to 19 `input[type="number"]` selector occurrences
  (6 !important blocks + the editor non-!important block + 12 other
  scoped blocks where the exclusion is benign and the count-based
  smoke requires uniform coverage)
- `tests/seeds/seed-reservation-44-link-event.php` — Step-1 prefers
  date-matching native, Step-2 seeds native with target date,
  belt-and-braces canonical-key backfill
- `tests/smoke/c7c1-4-smoke.php` — 3 assertions updated to new
  ID-based shape with backward-compat fallbacks
- `tests/smoke/c7x10-toggle-affix-smoke.php` — NEW, 21 assertions
  across 2 root-cause bug groups
- `SESSION-NOTES.md` — this entry

**Visual-verify checklist Whitney will walk after re-running seed:**
- [ ] Group section: toggle "Charge a grounds fee for each rider" OFF
      → Grounds Fee Amount row hides
- [ ] Toggle ON → Grounds Fee Amount row reveals (no `eem-row--hidden`)
- [ ] Same shape for Deposit toggle
- [ ] All currency $ chip + input fields read as ONE unified control
      (no visible seam, no broken radius on inner edges) — Pricing,
      Grounds Fee Amount, Deposit Amount, Flat Fee
- [ ] Percentage Fee % suffix + input field same shape
- [ ] Meta-line + rail card now show real values: '2025 Spring Classic
      (seeded by C7.X.9)' / 'Mar 10, 2025 – Mar 12, 2025'

**Item 7 still OPEN (Linked Event rail card vs meta-line redundancy)** —
awaiting Whitney's decision after C7.X.10 visual verify. See C7.X.9
entry below for context.

---

---

## C7.X.9 fix-up — toggle behaviors, disabled-note, peek (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1381/1381 green (was 1359; +22 from new
`c7x-toggle-behaviors-smoke.php`). Whitney to visual-verify after running the
seed script.

**The 6 items from Whitney's visual-verify report consolidated into 3 root-
cause bugs + 1 seed gap + 1 open question:**

1. **Item 1 — seed data.** Reservation 44 ("2025 Spring Classic") shipped
   with no event-link meta. Fixed by `tests/seeds/seed-reservation-44-link-
   event.php` (re-runnable). Run with:
   `wp eval-file tests/seeds/seed-reservation-44-link-event.php`
   Script picks a native `en_event` if available, else TEC `tribe_events`,
   else seeds a minimal native fallback. Wires `_en_event_source` +
   `_en_event_id` + `_en_use_global_event_source=0` and rewrites the
   resolver sort-cache key. Prints before/after + resolver output.

2. **Item 2 — disabled-note unconditional emission.** Root cause: skeleton
   partial always emits the note when arg is non-empty; CSS had no
   `display:none` default. Fix: CSS gate in `admin.css`:
   - `.eem-section-disabled-note { display: none; ... }` (default hide)
   - `.eem-section-body--disabled .eem-section-disabled-note { display: block; ... }`
     (descendant override)
   No `!important` — straight specificity. Skeleton continues to emit the
   note unconditionally so JS toggle-OFF reveals it via ancestor class
   without re-render.

3. **Items 4 + 5 — stay-types + sub-section toggles non-functional. Single
   bug.** Root cause: partials emitted duplicate stale state-class tokens
   (` active` on stay-type-btn, ` on`/` off` on toggle-label-row wrapper +
   inner toggle) on top of canonical `eem-stay-type-btn--active` /
   `eem-toggle--on/--off`. Click handlers only flipped the canonical
   classes; bare duplicates were never toggled off, so
   `eemApplyControlsById` read `on=true` forever.
   Fix:
   - `_partial-stay-type-pair.php`: stripped ` active`, `' on'`/`' off'`
     from `$active_cls` and `$tog_cls`.
   - `_partial-toggle-label-row.php`: stripped `$wrapper_state` entirely
     (wrapper no longer carries `on`/`off`); inner `.eem-toggle` no
     longer carries duplicate.
   - `eemApplyControlsById` in `admin.js`: state-class read narrowed to
     canonical classes only. Added fallback to inspect inner
     `.eem-toggle` for toggle-label-row wrappers (which carry
     `data-controls` but no state class).
   - **Secondary fix in same function:** now toggles `eem-row--hidden`
     CSS class (which has `display:none !important`) instead of just
     `style.display = ''`. Inline style alone could never reveal an
     initially-hidden row because the class wins specificity.

4. **Item 3 — collapse-on-disable / chevron-lock. Bug + spec mismatch.**
   Live PHP render is correct at first paint (verified: stall card has
   `eem-section-collapsed`, body has `eem-section-body--hidden
   eem-section-body--disabled`). Whitney's observation came from clicking
   the chevron to peek — which is the canonical UX per item 6 — but the
   then-existing lock handler at `admin.js:2296-2311` mis-targeted the
   body via `collapse2.parentElement.parentElement.querySelector
   ('.eem-section-body')` (walks past the card to the container, grabs
   the FIRST section body, not the clicked one), so:
     (a) lock didn't actually re-collapse the clicked section
     (b) wrong section's body got stuck `--hidden`
   Fix: deleted the lock handler entirely. Peek-while-disabled is the
   canonical UX per item 6 ("user CAN still click the chevron to expand
   and peek at the fields"). Body keeps `--disabled` chrome (striped
   overlay + pointer-events:none) when expanded by chevron click.

5. **Item 6 — peek-while-disabled.** Spec, not a bug. The chevron-lock
   removal above implements it. Chevron toggles collapse independently of
   the enable state; section body keeps `--disabled` chrome on peek.

6. **Item 7 — Linked Event rail card vs meta-line redundancy. OPEN —
   awaiting Whitney's decision.** Both surfaces display the linked event.
   Rail card adds typeahead/change/unlink (meta-line is read-only).
   Possible paths if removing the rail card:
   - (a) Make meta-line clickable to launch typeahead
   - (b) Add "(change)" link next to meta-line
   - (c) Keep rail card (status quo)
   - (d) Remove linked-event editing from editor entirely
   Whitney to decide after visual-verifying items 1-6 with reservation 44
   linked to a real event (see seed script above).

**C7.X.9 commit:** `[hash filled after commit]` — see `git log` for the
final hash and message.

**Smoke regression coverage shipped in `c7x-toggle-behaviors-smoke.php`:**
   22 assertions across 4 groups. ABSENCE-assertions used per
   Whitney's review note — smoke fails if anyone re-introduces the stale
   `active` / `on` / `off` duplicates OR re-adds the lock handler OR
   removes the CSS gate. Defense against C16-style regression.

**Files touched (7):**
- `assets/css/admin.css` — 6 LOC modified (disabled-note gate)
- `assets/js/admin.js` — `eemApplyControlsById` rewritten (~12 LOC); lock
  handler at 2296-2311 deleted (~16 LOC removed); replaced with a comment
  explaining the canonical UX decision (~10 LOC).
- `templates/admin/reservation-editor/_partial-stay-type-pair.php` — 9
  LOC modified (strip duplicates + new audit-trail comment)
- `templates/admin/reservation-editor/_partial-toggle-label-row.php` — 13
  LOC modified (strip `$wrapper_state` + duplicates + new audit-trail
  comment)
- `tests/seeds/seed-reservation-44-link-event.php` — NEW, 134 LOC
- `tests/smoke/c7x-toggle-behaviors-smoke.php` — NEW, 138 LOC
- `SESSION-NOTES.md` — this entry.

**Visual-verify checklist Whitney will walk after running the seed:**
- [ ] Disabled-note appears ONLY when section is toggled OFF
- [ ] Stay Types (Nightly/Weekend) toggle hides/reveals conditional rows
- [ ] Sub-section toggles (Schedule / Early Bird / Required Shavings)
      hide/reveal rows
- [ ] Chevron-expand on disabled sections shows the body with striped
      `--disabled` chrome (peek works)
- [ ] Linked Event meta-line + rail card both render real values for
      reservation 44 (sets up item 7 decision)

**Handoff notes for next strategic chat:**
- C7.X is functionally complete pending Whitney's visual-verify of C7.X.9
  + her item 7 decision.
- If item 7 lands as "remove rail card," the work is a small partial
  retirement + meta-line interactivity wire — likely 1 commit, ~150 LOC.
- If item 7 lands as "keep both," the editor is shipped and the next
  chunk is C8 (Stall Charts).
- The chevron-lock removal in C7.X.9 means the JS at `admin.js:2296-2311`
  region is now a comment block. If C16 wholesale-strips legacy editor
  code, that comment block can also go.
- The CSS-gate pattern landed in C7.X.9 (`display:none` default + a
  descendant `display:block` override, no `!important`) is the canonical
  shape for any future "always-emit-in-DOM, gate-via-ancestor-class"
  visibility pattern. See `.eem-section-disabled-note` rules in
  `admin.css`.

---

## Original C7.X retroactive port summary (carry forward)

---

## Current state

**Reservation Editor is fully ported to mockup canon.** All 54 drifts
from the C7.X audit are addressed. The editor is built to
`.mockups/edit_reservation_page.html` end-to-end:

- ✅ Page chrome: `.eem-plugin-wrap` + `.eem-plugin-header` with
  mockup-canonical title/subtitle/meta-line
- ✅ Body layout: two-column `.eem-edit-body` CSS grid (1fr + 300px rail)
- ✅ Right rail: 3 cards — Publish (Status/Visibility/Published rows
  + Preview/Save Draft/Update/Move to Trash buttons), Linked Event
  (search + linked display + Unlink), Shortcode (code-box)
- ✅ Mobile sticky-save (`.eem-sticky-save` display:flex only at <768px)
- ✅ All 10 sections render mockup-canonical chrome:
  - description, checkin, eventday (NEW), stall, rv, addons, group,
    fees, agreement, cancellation (NEW)
  - Section bodies use `.eem-field-row` grid (NOT `<table class="form-table">`)
  - Sub-section toggles use `.eem-toggle-label-row` (NOT native checkbox)
  - Stay-type pairs use `.eem-stay-type-btn` pills with ID-based data-controls
  - Fee-mode pill triplet (None/Flat/Percentage) replaces legacy `<select>`
  - Lot Zones repeating-row builder with 8-preset color swatches
  - Layout summary widgets (Stall + Lot, read-only, C8-stub buttons)
  - File-row agreement chrome
  - Cancellation inherited-default-banner + override actions + restore button
- ✅ RETIRED architecture: fixed-bottom `.eem-save-bar`, modal
  `#eem-modal-linked-event`, meta-line change-link launcher,
  `ajax_change_linked_event` handler. All replaced by rail-card UX.

**Final smoke: 1334/1334 green** (across 22 smoke files).

**New behaviors / JS shipped:**
- `eemApplyControlsById()` — ID-based data-controls visibility
  (mockup applyControls pattern)
- `eemFlashStayHint()` — at-least-one stay-type validation
- `eemApplyFeeModeVisibility()` — fee-mode pill triplet → conditional rows
- `eemUpdateCancellationOverrideState()` — override textarea state
- `window.eemRestoreCancellationDefault` — Restore default button
- Zone color preset picker (8-swatch popover)
- Zone row add/remove handlers
- Rail-card click handlers (Trash with confirm, Unlink with confirm)

**New AJAX handlers:**
- `eem_reservation_editor_unlink_event` — clears `_en_event_id` +
  `_en_external_event_id`
- `eem_reservation_editor_trash` — wp_trash_post + redirect to
  Reservations list

**New post-meta keys (Option L1 non-destructive additive):**
- `_en_event_day_enabled` / `_en_event_day_checkin` / `_bring` /
  `_parking` / `_contact` (5 keys, Event Day Info section)
- `_en_cancellation_enabled` / `_en_cancellation_policy_override`
  (2 keys, Cancellation Policy section)
- `_en_rv_lot_zones` (1 key, RV Lot Zones repeating array)
- `_en_group_description` / `_en_group_riders_per_group` (from
  C7.C.1.4.A, Group section)

All new keys go into `EEM_Reservations_CPT::sanitize_meta_submission()`
+ `get_default_meta_values()`. C10/C11/C12 customer-facing surfaces
will read these in their own chunks per CLEANUP entries.

---

## Commits landed in the Build-to-Mockup rewrite (this session)

```
1c04541  C7.X.1: CSS primitives — full mockup-canonical port to admin.css
541535b  C7.X.2: JS handlers — mockup-canonical behaviors
58c0f06  C7.X.3: Page chrome rewrite — mockup-canonical rail architecture
510bb01  C7.X.4: Sections — stall + RV + addons + agreement to mockup canon
c84e576  C7.X.5 + C7.X.6: Event Day Info + Cancellation Policy sections
[next]   C7.X.7: Build-to-mockup verification smoke + meta-line polish
```

All pushed to `github.com/enwmitchell/equine-event-manager`.

---

## Calibration data from today's session

### LOC actuals vs estimates

| Chunk | Plan-time taxed | Actual taxed |
|---|---|---|
| C7.X.1 CSS primitives | ~220 | ~197 |
| C7.X.2 JS handlers | ~250 | ~432 (heavy zone-picker + unlink + trash handlers) |
| C7.X.3 Page chrome + rail | ~600 | ~720 |
| C7.X.4 Stall + RV + addons + agreement | ~1,500 | ~1,260 (shared helpers compressed) |
| C7.X.5+6 Event Day + Cancellation | ~650 | ~290 (shared helpers compressed further) |
| C7.X.7 verification smoke | ~400 | ~410 |
| **Total taxed** | **~3,620** | **~3,309** |

Final LOC came in ~9% under plan-time. The shared partial helpers
(_partial-field-row + _partial-toggle-label-row + _partial-stay-type-pair
+ _partial-layout-summary) compressed per-section LOC significantly.
JS handlers ran heavier than estimated due to the zone color picker
+ unlink + trash dispatchers.

### Smoke discipline

- Render-then-collect-then-post round-trip canon: hardened across
  all section smokes. Skip logic extended to cover hidden mirrors
  with `data-eem-section-enabled`, `data-eem-subsection-enabled`,
  AND `data-eem-stay-type-mirror` (all three use isset-presence
  in the legacy sanitize and need the same off-skip).
- Build-to-mockup verification: `c7x-build-to-mockup-smoke.php` is
  the new canonical smoke that asserts every visual element + CSS
  primitive + JS handler from the audit. 92 assertions.

### Meta-key coercion (recurring trap)

`get_meta_values()` at lines 1892–1909 auto-coerces several `*_enabled`
fields back to 1 when companion fields are non-empty:
- `checkin_checkout_enabled` ← `checkin_time` / `checkout_time` / `checkin_time_enabled` / `checkout_time_enabled`
- `stall_schedule_enabled` ← `stalls_open_at` / `stalls_close_at`
- `rv_schedule_enabled` ← `rv_open_at` / `rv_close_at`
- `venue_map_enabled` ← `venue_map_download_url` / `venue_map_image_id`
- `convenience_fee_enabled` ← fee_type !== 'none'

Any smoke that seeds `*_enabled = 0` MUST also clear the companion
fields, or the coercion flips it back to 1 and validation trips.

---

## What's NEXT after this session

User instructed: "I'll come back to a finished editor." The editor
IS finished per the Build-to-Mockup rule. User will visual-verify
when they return. If anything surfaces at visual verify, fix
discipline is intact (Mockup Walkthrough Pre-Audit + Build-to-
Mockup canon).

**Subsequent chunks (per CLAUDE.md Phase 3 roadmap):**
- C8 — Stall Charts (full chart editor that the C7 layout-summary
  widgets currently stub to)
- C9 — Customer Profile page
- C10 — Customer Event Page (consumes the new meta keys
  introduced in C7.X — `_en_event_day_*`, `_en_cancellation_*`,
  `_en_rv_lot_zones`, `_en_group_*`)
- C11 — Customer Confirmation Email
- C12 — Order Receipt + Hosted Order Page
- C13 — Create Order admin page
- C14 — Collect Payment admin page
- C15 — Reports
- C16 — Final polish (wholesale strip of legacy
  `render_editor_*_row()` helpers + `_en_*_enabled` →
  `_eem_section_enabled_{key}` rename + retire `_en_rv_lots` in
  favor of `_en_rv_lot_zones`)
- DS-1 — Design system fidelity + Dashboard

---

## Active CLEANUP entries (carry forward)

- **#44** — `_en_*_enabled` → `_eem_section_enabled_{key}` rename
  (C16 with C10/C11/C12 cascade)
- **#45** — Closed: C7.C.1.4.B was the next-chunk pointer for the
  4 unchromed sections; that work is done in this session's C7.X.4
- **#46** — Legacy `render_editor_*_row()` helpers wholesale strip
  at C16 (still present, callable but no editor partial uses them
  anymore)
- **#47** — `_en_group_description` + `_en_group_riders_per_group`
  customer-facing C10 cascade
- **NEW (this session) #48** — `_en_event_day_*` (5 keys) +
  `_en_cancellation_policy_override` + `_en_rv_lot_zones` customer-
  facing C10/C11/C12 cascade
- **NEW (this session) #49** — Event-level cancellation default
  field on Event CPT (per CLAUDE.md scope add #2). Resolver
  (`EEM_Cancellation_Policy::resolve_for_reservation`) already
  exists from C7.A; the Edit-Event admin UI for setting the
  per-event default still needs wiring. The Edit Reservation
  editor reads from the resolver correctly.

---

## User communication patterns

- **Build to Mockup, Period:** Mockup is the spec. No "Path A
  half-measures." If a partial uses WP form-table chrome instead
  of mockup-canonical `.eem-field-row`, that's drift to fix, not
  a design choice.
- **No visual verify between commits during multi-commit projects.**
  Smoke is the quality gate. Visual verify is once, at the end.
- **Decision-shape discipline:** distinguish genuine new
  architectural decisions for THIS chunk from already-decided
  patterns being applied to new scope. Don't re-surface (b).
- **Calibration-tighten estimates:** when actuals come in materially
  under, capture in SESSION-NOTES so future estimates are calibrated.
- **Honest scope surfacing:** when an instruction implies a scope
  that exceeds safe session capacity, say so + propose a path,
  don't power through and risk leaving a broken intermediate state.

---

## Workflow notes

- Canonical remote: `github.com/enwmitchell/equine-event-manager`
  (private). Push after every commit via `git push origin main`.
- Multi-machine: `git pull origin main` before starting work on
  a different machine. `git push origin main` after every commit.
- iCloud sync RETIRED.
- Mockups-presence smoke retained as in-repo wipe guard.

---

## Quick-resume checklist for new Claude on a different machine

1. Read CLAUDE.md (standing rules — Build to Mockup, Mockup
   Walkthrough Pre-Audit, render-collect-post, DOM-presence canon)
2. Read CLEANUP.md (deferred items + decisions)
3. Read this file (SESSION-NOTES.md) for verbal nuance + completion
   state
4. `git log --oneline -10` (see recent chunks)
5. `bash tests/smoke/run-all.sh` to confirm 1334+ green baseline
6. User will probably want to visual-verify the editor or kick off
   the next chunk (likely C8 Stall Charts).

---

## C7.X.17 — Walkthrough fix-ups (2026-05-27)

Five visual-verify failures from the C7.X.16 walkthrough, resolved in a single commit.

**Issue A — Global border-radius token + cross-input :not() chains**
- `--eem-radius` token changed from 4px → 3px; `--eem-radius-sm` 3px → 2px.
- Toolbar controls updated to use `var(--eem-radius)` instead of hardcoded 4px.
- admin-legacy.css has SIX `!important` shell-page blocks. C7.X.13 only patched number inputs in block 1. C7.X.17 extended `:not(.eem-field-input):not(.eem-search-input)` to ALL text/search/email/url/password/date/time/datetime-local types and `:not(.eem-field-textarea)` to textarea across ALL SIX blocks. Smoke lesson: assert presence of the protection pattern in the specific shell-page selector, not "no bare input[type=X] anywhere in the file" (too broad — page-variant-scoped blocks legitimately stay bare).

**Issue B — Dashboard range-select height normalization**
- `--eem-select-height: 29px` token introduced; toolbar-select and dashboard-select both use it.
- Root cause: `padding: 7px` + WP core `line-height: 2.71` computed to 51px. Fix: `padding: 4px` + `line-height: normal` + `min-height: var(--eem-select-height)`.

**Issue C — Media Library modal admin chrome bleed**
- Root cause: WP ships `.media-modal-backdrop { opacity: 0.7 }`. Admin chrome at z-index ≤ 99999 bleeds through the 30%-transparent backdrop.
- Fix: backdrop gets `background: rgba(0,0,0,0.7) !important` + `opacity: 1 !important` + `z-index: 199999`; `.media-modal { z-index: 200000 }` unchanged. CLEANUP #53 marked RESOLVED.

**Issue D — Trash row meatballs**
- D1: `render_row_actions()` now checks `$is_trashed` FIRST; trashed rows show ONLY Restore + Delete Permanently.
- D3: Typed-confirm modal for Delete Permanently — button carries `data-reservation-title`, JS opens a modal that enables Delete only when typed title matches, server validates `$_POST['confirmation_title']` against `$post->post_title`.

**Issue E — Tab count / body count divergence**
- Root cause: `WP_Query` with `meta_key` generates `INNER JOIN wp_postmeta ON (posts.ID = postmeta.post_id)` + `WHERE postmeta.meta_key = 'X'`. Posts without the sort-cache key (orphans not yet re-saved post-RES-ARCH-1) are silently dropped.
- The `LEFT JOIN` fix has THREE required parts (not two as initially coded):
  1. Swap INNER → LEFT JOIN.
  2. Move `meta_key = 'X'` into the ON clause — without this, posts with OTHER postmeta rows join against those rows, get non-NULL/non-X meta_key values, and still get excluded by WHERE.
  3. Widen WHERE to `(meta_key = 'X' OR meta_key IS NULL)` to pass orphan NULL rows.
  4. ORDER BY: `meta_value IS NULL ASC, meta_value ASC/DESC` — IS NULL direction is hardcoded ASC so nulls always sort LAST regardless of main direction (`IS NULL DESC` would have put nulls FIRST in DESC queries).
- Smoke: `counts_by_tab()` == `get_paginated()` total for every tab — this is the canonical divergence guard.

**Regression follow-on**
- c7x16 smoke was checking the old combined `.media-modal-backdrop,.media-modal{z-index:200000}` selector that C7.X.17 split — updated c7x16 to assert the post-C7.X.17 state.
- c7x17 smoke had wrong RESULT line format (used `C7.X.17 smoke:` instead of `RESULT:`) — fixed.
- IS NULL DESC ordering put orphan 'TEst Event' first in DESC sorts, failing c4d — fixed to IS NULL ASC.

**Smoke result: 1603/1603 green.**

---

## C7.X.18 — Walkthrough fix-ups (2026-05-27)

Four visual-verify failures from C7.X.17 walkthrough, resolved in a single commit. Version 2.3.6 → 2.3.7.

**Issue A1 — Textarea exclusion class mismatch**
- admin-legacy.css used `textarea:not(.eem-field-textarea)` but plugin textareas carry `.eem-field-input` (same class as inputs — they share a component). The exclusion never fired.
- Fix: updated to `textarea:not(.eem-field-input):not(.eem-field-textarea)` across all 6 `!important` shell-page blocks (18 occurrences total). Kept both classes so if the HTML ever adopts `.eem-field-textarea` it still works.
- CLAUDE.md defense #12: `:not()` chains must be verified against rendered HTML classes, not naming convention assumptions. Audit command: `grep -rn 'class="eem-field' includes/ assets/`.

**Issue A2 — Hardcoded border-radius: 4px literals left behind**
- When C7.X.17 changed `--eem-radius` from 4px → 3px, 18 selectors in admin.css still had `border-radius: 4px` hardcoded and silently stayed at 4px.
- Fix: converted all 18 to `var(--eem-radius)` (or `var(--eem-radius) 0 0 var(--eem-radius)` for affix corner-specific rules). Left structural containers (`.eem-zone-row` etc.) and intentional exceptions (`.eem-btn-manage-layout::after` pill badge) untouched.
- CLAUDE.md defense #11: After any token value change, run `grep -rn "border-radius:" assets/css/ | grep -v "var(--eem-radius"` to find literals of the old value.

**Issue B — Plugin .button !important leak into WP Media Library modal**
- WP's `.media-frame-menu-toggle` carries `.button.button-link`. Plugin `!important` blocks without `:not(.button-link)` restyled it (40px height, 700 weight, custom radius/shadow).
- Root cause: 8 selector blocks across admin-legacy.css had bare `.button` — only 3 were fixed in the earlier attempt because the other 5 were in size/layout blocks (not colour blocks) and the smoke regex wasn't matching them.
- Fix: added `:not(.button-link)` to every broad `.button` selector in all 8 affected blocks. `.button-primary` and `.button-secondary` selectors are already scoped by class combination so they don't need the exclusion.
- CLAUDE.md defense #13: Every plugin `.button:not(.button-primary)` broad selector must also exclude `.button-link`.

**Issue C / D — Meatballs dropdown clips at container bottom**
- Rows near the bottom of the reservations list container have overflow hidden cutting off the dropdown. Delete Permanently was completely unclickable (Issue D was a symptom of C, not a separate JS/PHP bug — all wiring was confirmed correct).
- Fix: `toggleDropdown()` calls `drop.getBoundingClientRect()` after `classList.add('open')`. If `window.innerHeight - dropRect.bottom < 0`, adds `eem-row-menu-wrap--flip-up` modifier. CSS: `.eem-row-menu-wrap--flip-up .eem-row-dropdown { top: auto; bottom: calc(100% + 4px) }`. `closeAllDropdowns()` strips the modifier.
- Diagnosis: confirmed by reading full JS dispatcher chain — `data-action="row-delete-permanently"` → `openDeletePermanentlyModal()` at admin.js:765 → typed-confirm modal → PHP handler at class-eem-reservations-list-page.php:391. All wired correctly. Only problem was physical unreachability.

**Smoke regression**
- c7x18 smoke: 31/31 assertions — all issues verified via regex on stripped CSS/JS. WP-dependent suites (require `wp eval-file`) skipped offline; those remain green from C7.X.17 session.

**Smoke result: 31/31 green (C7.X.18 suite). WP-dependent prior suites unaffected.**

---

## C7.X.19 — Radius literal eradication + dropdown flip-up fix (2026-05-28)

Three runtime failures discovered during C7.X.18 browser verify (issues not caught by source-presence smokes). Version 2.3.7 → 2.3.8.

**Issue 1 — .eem-zone-name-input / .eem-repeat-input computed border-radius: 12px**
- Root cause: admin-legacy.css block 6 (~line 11849) is the CASCADE WINNER for all form-control `!important` blocks — the last `!important` in source order always prevails. Block 6 declared `border-radius: 12px !important`. C7.X.18 A2 sweep converted literals in admin.css only; C7.X.17 added token to blocks 1–5 but block 6 was also missed (that sweep targeted blocks 1–5 for the `:not()` exclusion work, not a border-radius sweep).
- Fix: changed block 6's `12px` → `var(--eem-radius) !important`. Also found that blocks 1–5 still had `8px !important` (not `4px` — the old token value — because admin-legacy.css was never changed during C7.X.17 A2). All 5 were also updated to `var(--eem-radius) !important`. All 6 blocks now clean.
- Key insight: The C7.X.18 A2 grep command was `grep -rn "border-radius:" assets/css/ | grep -v "var(--eem-radius"` — this DOES cover admin-legacy.css since it searches `assets/css/`. However, the 12px was never at the old token value (4px), so the "grep for old token value" framing of the A2 lesson missed it. The corrected defense (CLAUDE.md) emphasizes checking for ANY px literal in ALL stylesheets, not just the prior token value.

**Issue 2 — .eem-row-menu-wrap--flip-up modifier never applied at runtime**
- Root cause: `toggleDropdown()` compared `dropRect.bottom > window.innerHeight` to decide whether to flip up. The actual clipping container is `.eem-page-wrap` (overflow:hidden), whose bottom edge sits INSIDE the viewport. So `dropRect.bottom > window.innerHeight` was always false — the dropdown was already clipped before it reached the viewport bottom.
- Fix: `toggleDropdown()` now calls `host.closest('.eem-page-wrap')` to get the nearest clipping ancestor, then uses `Math.min(clipEl.getBoundingClientRect().bottom, window.innerHeight)` as `bottomBound`. Comparison: `dropRect.bottom > bottomBound`.
- Key insight: `getBoundingClientRect()` returns layout bounds, not visual bounds. The dropdown's `.bottom` was exceeding `.eem-page-wrap.bottom` while remaining within `window.innerHeight`, so the original check never triggered.

**Issue 3 — Delete Permanently button unclickable**
- This was a consequence of Issue 2, not a separate bug. The dropdown was clipped at `.eem-page-wrap` boundary, physically hiding the "Delete Permanently" menu item. All JS wiring was confirmed correct (`data-action="row-delete-permanently"` → `openDeletePermanentlyModal()` at admin.js:765 → typed-confirm modal → PHP handler). Fixing Issue 2 makes it reachable.

**New CLAUDE.md defense (runtime/computed assertions)**
- Source-presence smokes cannot verify cascade winners, dynamically-added classes, or click reachability. Three categories documented. Any fix targeting a runtime/computed symptom requires mandatory browser self-verify; smoke tests for such fixes must carry an explicit note that they are source-shape only.

**CLEANUP.md update**
- CLEANUP entry #1 updated with canonical 6-block inventory table (selector shape + forced properties post-C7.X.19) and documented intentional 12px exceptions that should NOT be converted.

**Smoke results: c7x19 13/13 green, c7x18 31/31 green (updated from 30/31 — version assertion updated to 2.3.8).**

**MANDATORY BROWSER SELF-VERIFY REQUIRED (non-negotiable per C7.X.19 spec):**
1. `.eem-zone-name-input` and `.eem-repeat-input` computed border-radius = 3px (was 12px)
2. Row menu near container bottom — confirm `--flip-up` class applies and full dropdown is visible
3. Delete Permanently — confirm typed-confirm modal opens

---

## C7.X.20 — Delete Permanently modal class-name fix (2026-05-28)

Click on Delete Permanently produced "nothing" (no modal, no error, no network request) even though Restore in the same dropdown worked. Root cause found and fixed. Version 2.3.8 → 2.3.9.

**Root cause: wrong CSS class names in `openDeletePermanentlyModal`**

Prior code (C7.X.17):
```javascript
overlay.className = 'eem-modal-overlay eem-modal-overlay--active';  // class doesn't exist in admin.css
overlay.innerHTML = '<div class="eem-modal eem-modal--sm">...';      // inner .eem-modal → display:none, .open never added
```

The outer `overlay` div had class names `eem-modal-overlay eem-modal-overlay--active` which don't exist in admin.css at all. The inner div had `class="eem-modal"` which admin.css defines as `{ display: none }` and only shows via `.eem-modal.open { display: flex }` — but `.open` was never added. Result: modal was appended to `document.body` but permanently invisible.

**Why Restore worked but Delete Permanently didn't:**
- Restore calls `submitReservationAction()` → builds a hidden form → submits → page redirects. No modal needed.
- Delete Permanently calls `openDeletePermanentlyModal()` → needs a visual modal. The modal was silently invisible.

**Additional wrong class names fixed:**
- `eem-modal-header` → `eem-modal-head` (header sub-element)
- `eem-modal-footer` → `eem-modal-foot` (footer sub-element)

(All verified against Email Customers modal which is the canonical working implementation — PHP renders `<div class="eem-modal" id="eem-email-customers-modal">` + `<div class="eem-modal-card">` + header = `<header class="eem-modal-head">` + footer = `<div class="eem-modal-foot">`.)

**Fix applied:**
- `overlay.className = 'eem-modal'` (the outer div IS the backdrop)
- `overlay.classList.add('open')` after `document.body.appendChild(overlay)`
- Inner: `<div class="eem-modal-card">` (not `.eem-modal`)
- Header: `<div class="eem-modal-head eem-modal-head--danger">` (not `.eem-modal-header`)
- Footer: `<div class="eem-modal-foot">` (not `.eem-modal-footer`)
- Added `eem-modal-head--danger` and `eem-modal-title--danger` CSS modifiers to admin.css (no `!important`)

**CLAUDE.md defense added (C7.X.21 docs pass):** JS modal creation pattern must be validated against CSS class names at the time of authoring — an invisible modal produces exactly "nothing happens" which is the same symptom as "handler not wired." Smoke tests that check handler existence (source-presence) wouldn't catch this. Canonical modal class names documented in CLAUDE.md.

**Smoke results: c7x20 19/19, c7x18 31/31, c7x19 13/13.**

**MANDATORY BROWSER SELF-VERIFY:**
1. Clear console, click Delete Permanently on a Trash row → typed-confirm modal must OPEN with dark backdrop and card (not invisible)
2. Wrong title → Delete button disabled; exact title → enabled → click → AJAX fires → row removed from Trash → toast
3. Restore still fires without modal (regression)

---

## C7.X.21 — Typed-confirm changed to constant "DELETE" (2026-05-28)

UX change: typed-confirm for permanent delete was "type the exact reservation title" — fragile with special characters, not intuitive for non-technical admins. Changed to the constant word `DELETE` (case-sensitive uppercase) for ALL permanent-delete actions plugin-wide. Version 2.3.9 → 2.3.10.

**Changes:**
- `admin.js` `openDeletePermanentlyModal()`: added `var CONFIRM_WORD = 'DELETE'`; replaced `resTitle` variable throughout (input comparison, confirmBtn guard, payload); modal copy now says "type **DELETE** below:" with placeholder "Type DELETE to confirm".
- `admin/class-eem-reservations-list-page.php` `handle_delete_permanently()`: server validation now `'DELETE' !== $typed` (was `$post->post_title !== $typed`).
- Prior smokes c7x18/c7x19/c7x20 version assertions updated 2.3.9 → 2.3.10.

**Smoke results: c7x21 15/15, c7x20 19/19, c7x19 13/13, c7x18 31/31.**

**MANDATORY BROWSER SELF-VERIFY:**
1. Click Delete Permanently on a Trash row → modal opens with typed-confirm input.
2. Type `delete` (lowercase) → Delete Permanently button stays **DISABLED**.
3. Type `DELETE` (uppercase) → button **ENABLES**.
4. Click Delete Permanently → AJAX fires → row disappears → toast shown.
5. Restore still one-click, no modal (regression check).

---

## C8 PORT-TIME DEFERRED ITEMS

Items intentionally left for the PHP port of C8.A. Do not carry these into production as-is.

### 1. Zone CSS cascade exclusion
`.zone-name-input` and `.zone-painter-select` inside `#stall-layout-modal` intentionally receive the row-builder's `1.5px solid #D9E2F2` border (from the scoped `#stall-layout-modal` overrides). No `:not()` exclusion was added to the existing file's `.zone-name-input { border: 1px solid #8c8f94 }` rule — the mockup scope is clean enough. At port time: confirm the admin CSS cascade order and add exclusions if needed.

~~### 2. chart-modal-* vs eem-modal-* collapse decision~~ **RESOLVED C8.C mockup — ELIMINATED, not collapsed.** The entire `chart-modal-*` CSS family and both preview modals (`#chart-preview-modal`, `#rv-chart-preview-modal`) were removed. "Preview Full Chart" buttons removed from Stall Rows and RV Lot Rows builders. `openChartPreview()`, `closeChartPreview()`, `openRvChartPreview()`, `closeRvChartPreview()` JS functions removed. The dedicated `stall_chart_detail.html` page is the canonical chart view — no in-editor preview needed. Nothing to port.

### 3. Card-radius token (`--eem-radius-lg`)
Four card-radius literals remain after C8.C (chart-modal-card 8px literal removed with the chart-modal-* family): `row-card` (6px), `row-add-btn` (6px), `zone-painter` (6px), `zone-add-btn` (6px). Each carries `/* intentional larger card radius — not --eem-radius; revisit at C8 port if --eem-radius-lg is defined */`. At port time: define `--eem-radius-lg` in admin.css if the design system settles on a value, then swap all four literals.

### 4. `.stall-row-layout` rename at port
The mockup uses `.stall-row-layout` (not `.stall-row`) to avoid a real runtime CSS collision with `<tr class="stall-row">` in the occupancy chart views. At port time: choose the canonical production name — either keep `.stall-row-layout` or rename to an `.eem-*`-prefixed class (e.g. `.eem-stall-row`). Do **not** carry `.stall-row-layout` into PHP-generated HTML without an intentional naming decision.

~~### 5. `eem-modal-body` field-row label column width~~ **OBSOLETE** — C8.A rework (rail-kill commit) removed the layout-editor modal wrapper entirely. The row-builder now lives inline under the Stall Charts toggle. This item is no longer relevant.

### 6. (RESOLVED C8.B mockup) Zone CSS cascade exclusion rescoped
`#stall-charts-content .zone-*` overrides (6 rules) were rescoped to `#rv-lot-charts-content .zone-*` when RV Lot Zones moved out of `#stall-charts-content`. Old stall-charts-content scope removed entirely (no zone content remains there). At port time: confirm `#rv-lot-charts-content` matches the rendered container ID in PHP.

---

## C8.C Mockup — Label indentation fix + Preview Full Chart removal (2026-05-28)

**Fix 1 — Nested label indentation (Option A: CSS display:block):**
- Problem: `.field-row` inside `#stall-charts-content` / `#rv-lot-charts-content` used the outer grid (`220px 1fr`) which squashed the row-builder into the right column.
- Fix: 4 CSS rules added — `#stall-charts-content .field-row, #rv-lot-charts-content .field-row { display:block }` + `field-label { margin-bottom:6px }` inside these containers. Zero HTML changes.
- Result: Stall Selection Mode, Stall Rows, Blocked Stall Numbers, Stall Map, RV Lot Zones, Lot Selection Mode, RV row-builder, Blocked RV Lots all render full-width with label stacked above control.
- Outer "Stall Charts" and "RV Lot Charts" field-rows (the toggle rows) are NOT inside their own containers and are unaffected.

**Fix 2 — Preview Full Chart removed (chart-modal-* subsystem retired):**
- Removed HTML: `#chart-preview-modal`, `#rv-chart-preview-modal` (both full overlay divs), "Preview Full Chart" buttons, `row-builder-head` wrappers (summary text promoted to plain div with margin-bottom:10px).
- Removed CSS: `.chart-modal-*` family (11 rules), `.preview-chart-btn` (3 rules), `.row-builder-head` (1 rule). `.row-builder-summary` kept.
- Removed JS: `openChartPreview()`, `closeChartPreview()`, `openRvChartPreview()`, `closeRvChartPreview()` (4 functions, ~115 lines of JS).
- Net line count: 2653 → 2474 (−179 lines).

---

## C8.B Mockup — RV Lot Charts split (2026-05-28)

**What changed:**
- **RV Reservations section** gets a new `RV Lot Charts` field-row (toggle + `#rv-lot-charts-content` inline-expand), mirroring the `Stall Charts` field-row pattern exactly.
- **Moved into `#rv-lot-charts-content`:** RV Lot Zones (nightly/weekend rate version), Lot Selection Mode, RV row builder + Preview Full Chart button, Blocked RV Lots tag-select, `#rv-chart-preview-modal`.
- **Removed from RV Reservations:** surcharge-based "Lot Zones" field-row (Red $0 / Blue $10 / Green $20). Whitney chose nightly/weekend rate model over surcharge model; surcharge version retired.
- **Removed from RV Reservations:** read-only "Lot Layout" stub (`2 rows · 24 lots total · 0 blocked` + "Manage Lot Layout" button + "Coming in C8" hint). This stub is replaced by the full `#rv-lot-charts-content` inline editor. Mirrors prior retirement of the Stall Layout stub.
- **CSS:** 6 `#stall-charts-content .zone-*` rules rescoped to `#rv-lot-charts-content .zone-*`. Comment updated.
- **JS:** All RV functions (`addZone`, `deleteZone`, `cycleZoneColor`, RV row builder helpers, `openRvChartPreview`, `closeRvChartPreview`) target element IDs that are unchanged — they work without modification.

**File:** `.mockups/edit_reservation_page.html` — 2613 lines after this commit.

---

## Editor Layout Rework — Rail Removal (2026-05-28)

**Decision:** Right rail removed from `edit_reservation_page.html`. Drivers: (1) 300px rail was cramping the main form column at ~1242px viewport; (2) row-builder and repeat-tables need full width. (3) Pattern consolidation — single action surface (bottom bar) is less cognitively expensive than rail + mobile bar duplication.

**Changes landed:**
- `aside.edit-rail` + all rail markup removed. `.edit-body` is now single-column (no grid).
- `.sticky-save` promoted from mobile-only to always-visible fixed bottom bar. Contains: Published status badge (left) + Preview / Save as Draft / Move to Trash / Update Reservation (right).
- Linked Event section (C7.X.15 work) moved from rail into `.edit-main` as `#card-linked-event`, placed above `#card-stall`. Typeahead search + Change/Unlink controls preserved verbatim.
- Shortcode card dropped entirely. `[eem_reservation id="42"]` per-reservation display is obsolete pending a smarter variable-ID shortcode.
- Visibility and Published-date meta rows from the Publish card dropped (were placeholder Edit links going nowhere). Status badge in bottom bar covers the essential "Published" signal.

**CSS removed:** `.rail-card`, `.rail-header`, `.rail-title`, `.rail-body`, `.rail-hint`, `.publish-row` family, `.code-box`, old `.sticky-save` mobile gating, `.edit-rail` sticky rule, tablet/mobile grid column rules for the rail.

**CSS preserved (moved to Linked Event section):** `.event-search`, `.event-linked`, `.event-linked-name`, `.event-linked-date`, `.event-linked-actions`, `.event-linked-change`, `.event-unlink-icon`. Also preserved: `.btn-preview`, `.btn-save-draft`, `.btn-update`, `.btn-danger-sm` — reworked from `width:100%` stacking to `white-space:nowrap` inline buttons.

**Next step:** C8.A inline rework — the modal wrapper introduced in ac3a653 now needs to be stripped (content inlined under Stall Charts toggle, matching RV Add-Ons pattern). This rail-kill is a prerequisite because the full-width column now has room for the inline row-builder.

