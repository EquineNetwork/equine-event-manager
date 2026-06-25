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

### Confirmation # leaked into Special Requests → Order Notes card (v2.7.592)
- **Symptom:** Imported customers' confirmation number(s) showed up inside the customer "Special Requests" text (order detail, stall-chart by-customer note + map tooltip).
- **Root cause:** The import writes "Confirmation Numbers: X" into the order `notes` blob. `get_special_requests_from_order_notes()` strips known metadata lines before display, but its strip regex did not list `Confirmation Numbers:` (nor `Card Brand`/`Card Last4`/`Manual Payment Method`), so those lines leaked through as free-text.
- **Fix:** (a) Added `Confirmation Numbers?:` + the card/manual-payment lines to the strip regex — kills the leak everywhere special-requests is shown. (b) New **Order Notes card** on the Order Detail page (below Special Instructions, separate from the Activity Log) that surfaces the confirmation # in its own labeled field plus any genuine customer free-text. Two new static parsers on `EEM_Admin`: `parse_confirmation_numbers_from_notes()` + `parse_customer_notes_from_order_notes()`. Card renders nothing when there's neither a conf # nor a note (no empty chrome).
- **Prevention:** The notes blob is a multi-purpose store — any new "Label: value" metadata line written into it MUST also be added to the strip regex(es) or it will leak into the customer-facing free-text. Activity Log (system trail) and Order Notes (customer/admin text) are deliberately separate surfaces.

### Shavings count surfaced per customer + on the assigned stall pill (v2.7.593)
- **Need:** Barn crews need to know how many bags of shavings to drop per customer/stall. Daily Movement already showed it; the Stall Chart did not.
- **Fix:** (a) **By Customer** view gets a **Shavings** column (purple bag badge, "N bags", "—" when none) — sits in the `col-stall` group so it follows the Stalls/Both Show toggle. (b) The **assigned stall pill popover** (by-location map/list) now shows a "Shavings: N bags" line; cell carries a `data-shavings` attribute. Bag count = `required_shavings_qty + additional_shavings_qty`, summed per order by the repo. (c) Daily Movement unchanged (already correct).
- **Data note:** shavings is stored per ORDER (not per individual stall), so the count shown is the order total. Most barrel-race customers have 1–2 stalls; the popover/column make the per-customer total explicit, which is what the crews actually pull by.
- **Prevention:** any new per-order operational quantity (bedding, hay, etc.) follows this same pattern — add to the `build_stall_chart_rows()` row array + the grid `occupant`/`cells` arrays + the pill `data-*` + the popover render.

### Consistent stall-state colors across List + Map + chips (v2.7.594)
- **Symptom:** The same stall state showed different colors depending on the view. Blocked was GRAY in the By-Location List cells and on the spatial Map, but RED on the status chips. Available was GREEN in the List but WHITE on the Map. Tack had no distinct color in the List (rendered blue + a small badge).
- **Canonical palette (locked):** green = available · blue = booked · red = blocked · orange = tack · purple = cleaning.
- **Fix:** (a) **List** (`.eem-loc-cell--*`): blocked gray→red; new `.eem-loc-cell--occupied.is-tack` orange (PHP adds `is-tack` to tack cells). (b) **Map** (`.eem-smap-stall`): base/available white→green; `is-blocked` gray-hatch→red-hatch; added `is-cleaning` purple; legend swatches avail→green, block→red; dot-mode colors aligned (available green, blocked red, cleaning purple). (c) Availability summary `--blocked` stat gray→red. Barn-stat dots + occupancy pills + status chips already matched.
- **Scope note:** `.eem-smap-stall` is exclusive to the Stall Charts map; the Map Builder uses `.eem-mb-cell`, so its white-canvas aesthetic is untouched.
- **Prevention:** any new stall-state surface MUST use the five canonical colors above. Don't introduce a fourth gray "blocked" or white "available" — grep `--blocked`/`--available`/`is-blocked` before adding state CSS.

### Assign from Order Detail + import mirrors section-enabled flags (v2.7.595) — #2 + #20
- **Symptom:** No "Assign Stalls" / "Assign RV" button on imported orders' detail pages. The deep-link assign flow (#219) existed, but the button was gated on `EEM_Reservations_CPT::section_enabled('stalls_enabled')`.
- **Root cause:** The config TABLE had `stalls_enabled=1`/`rv_enabled=1`, but the import never wrote the matching POST META (`_eem_section_enabled_stalls`, etc.). `section_enabled()` reads post meta only, so it returned false → button hidden, editor sections collapsed — until the admin opened + re-saved the reservation editor.
- **Fix:** (a) **Import write-path** now mirrors EVERY `SECTION_ENABLED_MAP` flag from the config values to canonical post meta (alongside the existing pricing-mode mirror). Fresh imports work without a save. (b) **Migration #040** backfills existing imported reservations: queries the config table columns directly (no config-object hydration → memory-safe), writes the canonical post meta only when neither canonical nor legacy key exists (never clobbers an admin-saved value). Verified on local: 332 keys backfilled, reservation 15246 now reports `stalls_enabled/rv_enabled = true`, idempotent on re-run.
- **Prevention:** Any data path that creates a reservation by writing the config table (import, future API, clone) MUST also mirror the section-enabled flags to post meta, OR every `section_enabled()`-gated surface will treat the reservation as "off." The config table is the source of truth; post meta is the read-cache many gates still use.

---

## 🔜 IN THE CURRENT BATCH (not yet shipped — designs locked)

- **[#14] Barn filter on By Customer** — it has a Barn column but no Barn filter like By Location.
- **[#15] `&amp;` double-encoding** in reservation dropdowns (Daily Movement etc.). Likely the Choices.js enhancement double-encoding the option label; native breadcrumb renders fine. Fix: decode-before-escape and/or configure the select-enhancer not to re-encode. Repair any title actually stored encoded.
- **[#18] Dashboard RV parity**: "Rv" → "RV"; Upcoming Reservations card show RV count (e.g. 94/94) alongside stalls; "This Week" card add RV assigned.
- **[#2] Assign from Order detail** — no assignment affordance on the order page.
- **[#3] Unify By Location List + Map click menus** — both should offer assign / cleaning / checked-in / tack / block (biggest item).
- **[#5] Default to By Location** when orders exist but nothing assigned (lower priority now — By Customer is populated).
- **[#10] Dashboard date wording**: "In 3 days" (event) vs "Opens in 1 day" (reservation) reads as conflicting.
