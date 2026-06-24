# Staging Fixes Log — June 2026

Durable record of every issue found during the WP Engine staging shake-out, with
**root cause** and **concrete fix / prevention** so they don't recur. Newest work
at the bottom. Session task IDs in brackets.

---

## ✅ FIXED & SHIPPED

### Stay Packages pricing mode reverted to nightly (v2.7.583)
- **Symptom:** Selecting "Stay Packages" → Update → reverts to Nightly. Saved on local, not staging.
- **Root cause:** Pricing mode was stored ONLY in the `wp_eem_reservation_config` table; on the staging install that column write wasn't taking while every post-meta field saved.
- **Fix:** Mirror `stall_pricing_mode` / `rv_pricing_mode` to post meta (`_en_stall_pricing_mode` / `_en_rv_pricing_mode`) on save (editor + import) and read it back as a fallback in `get_meta_values` (table wins, then post meta, then 'nightly').
- **Prevention:** Any new "table-only" config field must have a post-meta fallback OR a smoke that round-trips it through `get_meta_values`.

### RV Lot Rows: new rows showed a stale "Zone" dropdown + broken surcharge affix (v2.7.584)
- **Root cause:** `rvAddRow()` JS still built the pre-merge Zone dropdown after zones were merged into rows; and `.eem-row-card-field input` overrode the currency-affix CSS at equal specificity.
- **Fix:** JS now emits the Nightly Surcharge affix (mirrors the PHP row); scoped the affix CSS (`.eem-row-card-field .eem-zone-price-wrap …`) to win.
- **Prevention:** When a PHP partial changes shape, update the matching JS row-builder in the same commit. Affix controls inside row cards need scoped CSS.

### Imported orders not linked to their reservation — empty By Customer, "0 orders", wrong dashboard (v2.7.585)
- **Symptom:** Stall Chart By Customer empty; map "Search customer" → "No match"; dashboard reservation showed 0 orders / $0; RV badge missing; "stall chart not configured" false warning.
- **Root cause:** `create_order_seed()` derived `reservation_id` by PARSING the order NOTES ("Reservation setup ID: N") instead of reading the authoritative `reservation_id` COLUMN. Imported orders carry the OLD source id in notes; the column is correct.
- **Fix:** Prefer the `reservation_id` column (fall back to notes for legacy rows). This corrected every `get_orders()` consumer at once. Also: dashboard RV badge detects via `rv_quantity` (not just `has_rv`); "configured" check now recognizes a connected stall/RV MAP (barns) + rv_rows, not only stall_rows/rv_zones.
- **Prevention:** Always read denormalized columns as the source of truth; notes-parsing is a legacy fallback only. New per-reservation rollups must filter by the column.

### STALL # showed bogus duplicate 1..qty; Arrival/Departure blank (v2.7.586)
- **Symptom:** Unassigned orders showed STALL # "1", "1,2", "1,2,3,4" (repeating stall #1 across customers); Arrival/Departure columns all "—".
- **Root cause #1:** By Customer rendered auto-SUGGESTED allocations as if assigned; with no dates the allocator couldn't space units, so every order collided on 1..qty.
- **Root cause #2:** CSV-imported package orders stored only a stay-type LABEL ("Thursday-Sunday"), never resolving it to calendar dates → arrival/departure NULL.
- **Fix:** (a) By Customer shows ONLY saved/assigned units ("—" until assigned). (b) Migration #039 derives arrival/departure/effective dates from each order's stay-type label matched to the reservation's stay packages by weekday. **Note:** the WHERE had to match `arrival_date IS NULL OR = '0000-00-00'` — comparing a DATE column to `''` is invalid under strict SQL mode and silently voids the predicate.
- **Prevention:** Never show unsaved suggestions as committed data. Never compare DATE/DATETIME columns to `''` — use `IS NULL` / `'0000-00-00'`. Orders must resolve stay-type → dates at creation (see #17 import fix below).

### Dashboard RV parity + `&amp;` double-encoding + By Location default (v2.7.587–588)
- **[#18] Dashboard RV parity** — "Rv" → "RV"; Upcoming Reservations card shows RV count alongside stalls; This Week card adds RV assigned. Shipped 2.7.587.
- **[#15] `&amp;` double-encoding** in reservation dropdowns (Daily Movement etc.) — fixed in the Choices dropdowns. Shipped 2.7.587.
- **[#5] Default to By Location** when orders exist but nothing assigned — landing view now defaults to By Location — List. Shipped 2.7.588 (and reinforced 2.7.591: configured/empty-state handling).

### Earlier (same shake-out)
- **CSV import**: shavings-only + RV-only-with-shavings customers handled; IMP- order numbers.
- **Import/Export tool** for full reservation setup (event + venue + config + packages + orders).
- **PWA install banner** disabled (deferred to v2) — was a real EEM feature, not a WP Engine prompt.
- **IMP- prefix** preserved in order-number display; **ALL-CAPS customer names** normalized (import-time + migration #038).
- **Link Reservation typeahead** clipping fixed.

### WP Engine environment gotchas (learned the hard way)
- The in-WordPress "WP Engine → Caching → Clear all caches" does **page + object cache only — NOT PHP OPcache**. After a plugin update, class files can serve stale bytecode until a real OPcache reset (portal / redeploy).
- Plugin **File Editor is disabled** (DISALLOW_FILE_EDIT) — can't read live files via wp-admin.
- The plugin self-updater **does** replace files; verify a version actually went live via the `?ver=` query string on enqueued assets.

---

## 🟡 IMPLEMENTED — pending Whitney visual verification (do NOT redo)

> Work done in a **Claude Code web session (2026-06-24)** on branch
> **`claude/session-context-recovery-4c6f8m`** (draft PR open). NOT yet merged to
> `main` and NOT visually verified. Desktop: review the branch/PR rather than
> re-implementing. No version bump applied (awaiting Whitney's OK per ROADMAP).

- **[#14] Barn filter on By Customer** — added a barn `<select>` ("All Barns" + one
  option per configured barn) to the By Customer filter row, mirroring the By
  Location barn filter. Implementation:
  - PHP (`admin/class-equine-event-manager-admin.php`): dropdown emitted in the By
    Customer filter row, gated on `'rv' !== $inv && ! empty( $barn_options )`
    (hidden in RV-only mode / when no named barns). Each customer `<tr>` now emits
    `data-barns="<space-separated barn slugs>"` computed from the order's stall
    units via `$unit_block_map` (same `sanitize_html_class(strtolower())`
    normalization as the dropdown option values, so slugs align).
  - JS (`assets/js/admin.js`): `eemApplyStallChartFilter` prefers a panel-local
    `.eem-cust-barn-select` when present and matches rows whose `data-barns` token
    list includes the selected slug; a `change` listener re-runs the filter; the
    inventory-switch handler resets the select to "all" and recomputes so a stale
    barn filter can't leave rows hidden after switching Stalls/RV/All.
  - **Behavior decision (flag for Whitney):** selecting a specific barn hides
    RV-only orders (they have no barn). "All Barns" shows everything. If you'd
    rather RV-only orders always remain visible, say so and it's a one-line change.
  - Stacks correctly with the existing search box, Show-by-group, and Tack filters.
  - `php -l` + `node --check` both clean. Not yet browser-verified on a live chart.

## 🔜 IN THE CURRENT BATCH (not yet shipped — designs locked)

- **[#16] Confirmation # → Order Notes card** (LOCKED): exclude Confirmation Numbers from the Special Requests display; add an "Order Notes" card below Special Instructions for free-text internal notes (separate from the Activity Log audit trail); put the confirmation # in its own labeled field + log "Imported with confirmation # X".
- **[#17] Shavings count** (LOCKED — all three): a Shavings column in By Customer, on Daily Movement arrivals, and on the stall map cell / assignment chip. Also: CSV import should resolve stay-type → dates at creation so future imports don't need migration #039.
- **[#2] Assign from Order detail** — no assignment affordance on the order page.
- **[#3] Unify By Location List + Map click menus** — both should offer assign / cleaning / checked-in / tack / block (biggest item).
- **[#4] Consistent status colors** across List chips + Map cells (green=available, blue=assigned, red=blocked, orange=tack).
- **[#10] Dashboard date wording**: "In 3 days" (event) vs "Opens in 1 day" (reservation) reads as conflicting.
