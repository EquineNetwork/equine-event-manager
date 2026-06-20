# Equine Event Manager — Roadmap & To-Do

---

## 🔖 SESSION HANDOFF — 2026-06-20 (v2.7.515, on `main`)

**v1 is complete and live.** The sessions since 2.7.466 have been a deep visual/UX polish pass
plus a batch of targeted Order-Detail / receipt / Stall-Chart / frontend features on top of v1.
There is **no open task on the active list** — pick the next thread from the
**RECOMMENDED EXECUTION ORDER** below (next up: inventory/concurrency audit → financial-security
audit → mobile/PWA polish) unless Whitney directs otherwise.

**What the recent sessions shipped (2.7.466 → 2.7.515), newest first:**
- **Order Detail (2.7.510, 2.7.515):** editable arrival/departure dates (refund on shorter stay
  via the refund engine, balance-due on longer); status-aware payment footer (Order Total /
  Amount Paid / Balance Due) + balance-driven Payment-Outstanding banner; per-unit **Rate** row on
  the stall/RV cards; Order Summary section labels (Stalls Subtotal / Fees Total / Custom Items
  Total) + empty Add-Ons hidden; **Check-In / Check-Out** action button (green/amber/electric,
  synced with Daily Movement via `wp_eem_order_checkin`); assigned stalls/RV lots now render on the
  order (read from component notes — the old `stall_units_csv` field was never populated); Print
  Receipt button (auto-print) replacing Download Receipt.
- **Required Documents (2.7.484–486, 493, 2.7.515):** admin-defined required-doc list on Edit
  Reservation; order-attached upload storage + endpoints; per-doc **Take Photo** (camera capture),
  Upload/Replace, and **Mark Satisfied** (in-person verify, mig-033 `satisfied` columns); amber
  "Documents Outstanding" banner on the order; removed the docs card from the customer receipt.
- **Customer receipt (2.7.515):** totals now match Order Detail (custom line items + discount +
  Amount Paid + Balance Due); **"Stay Assignments"** block (assigned stalls/RV lots); Emogrifier
  path unchanged.
- **Stall & RV Charts (2.7.515):** "Assign Stalls" lands in **By Location – Map**; clicking an
  available stall in assign-mode places the pending order directly + redirects back to the order;
  full-bleed bold-electric assign banner above the units; map chips load at **30px**; full-bleed
  unsaved-suggestion notice. Mockup `.mockups/stall_chart_detail.html` rebuilt to current state.
- **Edit Reservation — Early Bird (2.7.504–509):** per-package Early Bird discounted price (stalls
  + rv, mig-032); EB gated on the Schedule toggle; EB cutoff constrained to the open→close window;
  Schedule + Early Bird reordered above Pricing Mode with inline EB rate inputs; package column
  titles + `$` prefix.
- **Frontend customer event page (2.7.501–503, 2.7.515):** electric primary CTA + navy-outline
  secondary; card-header titles = bare bright-blue icon + navy uppercase, left-aligned; Reserve
  Now / Complete Reservation buttons bright blue; radius + input-border standardization.
- **Admin house-style sweep (2.7.469–500):** Daily Movement, Stall Charts list, Reports (filter
  restructure + PDF-as-print-view + Facility Cleaning report + export history), Dashboard (Today's
  Movement + Facility cards), Orders / Order Detail / Reservations / Entries-Divisions / Sheets /
  Customers / Settings — blue filter bars, floating cards on gray, electric major-action CTAs,
  plugin-wide badge sweep, legacy-button consolidation to `.eem-btn-electric`.
- **Reports (2.7.511–514, parallel chat):** orders print header (event + dates) + landscape;
  independent print_columns vs CSV column sets; trimmed print columns.
- **Stability (2.7.467–468):** broke an infinite recursion in the reservation-config
  migration/hydration; fixed config backfill silently dropping `stall_rows` on oversized varchar.

**Known watch-outs (still current):**
- Reservation **5990's RV map is corrupted** (do-not-touch per memory) — test maps on **NTR 6519**.
- The Order-Detail Rate row exposes a pre-existing data quirk on heavily-edited test orders: a
  stored component `subtotal` can drift from `unit_price × qty × nights` after repeated Add-Items /
  date edits (e.g. #90801 shows $121 vs rate-implied $135). Real cleanly-created orders reconcile;
  worth a hardening pass on the Add-Items subtotal recompute if it recurs.
- RV lot name/number split still keys on the **last space** in the label — verify against real GEMS
  labels if odd splits appear.

---

### Previous handoff — 2026-06-18 (v2.7.466)

**Picking up on the laptop? START HERE:**
1. **Setting the laptop up for the first time?** Read **`docs/LAPTOP-SETUP.md`** — it has the exact
   recipe to mirror the desktop dev workflow (clone repo + Local Export/Import + symlink the plugin
   so Claude's edits preview live). Tell laptop-Claude: *"read docs/LAPTOP-SETUP.md and walk me
   through it."* The DB (reservations/orders/maps) is NOT in git — it comes over via Local Export.
2. **Already set up?** `git checkout main` then `git pull`. Everything is on **`main`** now —
   the `v4-stall-mapping` branch was merged (fast-forward) and pushed; `main` is the source of truth
   and is 1 commit ahead (the laptop-setup doc). No branch juggling needed.

**Where we left off / pick up here (in priority order):**
- ✅ **#229 — critical error when trashing a draft reservation — DONE (2026-06-18, verified on laptop @ 2.7.467).** Could not reproduce on current code; trashing drafts (list-row Trash + editor Trash button) works clean. The "critical error" was the migration-recursion crash that took down every admin page on the stale-DB upgrade path — fixed in 2.7.467 (commit 6b7f1e8). Verified + approved by Whitney in-browser.
- ✅ **#234 — backfill smoke coverage — DONE (2026-06-18, @ 2.7.467).** Two new green smokes:
  `stall-status-readiness-smoke.php` (17) covers `EEM_Stall_Status_Repo` set/bulk/needs-cleaning
  with canonical read-back; `print-view-show-view-smoke.php` (17) covers the print VIEW/SHOW/all_rv
  variants. Move-customer flow already covered by stall-per-night-move + rv-night-move smokes.
- ✅ **#235 — RV lot name/number split verified CORRECT (2026-06-18, on NTR 6519's real data).** The last-space split (`strrpos(' ')`, admin lines 4265 + 6805) is the exact inverse of label construction (`zone . ' ' . num`, admin:5300); `num` from `expand_label_range()` never has an internal space, so single- AND multi-word zone names plus prefixed/padded lots (Y1, A-01) all round-trip. NTR 6519: 25/25 labels correct, 0 mismatches. Only breaks on externally-sourced labels not built by this path (no-space `A12`, non-numeric tail) — not a real risk. No code change.

**What this session shipped (Stall & RV Charts + Daily Movement + all Print Views):**
- **By Location – List → readiness grid.** Per-stall-night status (`wp_eem_stall_status`):
  Occupied (shows customer name) / Available / Cleaning. Click a cell to change; bulk-update
  dropdown + Apply; Barn/Zone filter dropdown. Auto-flip to Cleaning on customer check-out.
  Stores via `EEM_Stall_Status_Repo` (`get_status_map` / `set_cell_status` / `bulk_set_status` /
  `mark_order_stalls_needs_cleaning`). AJAX: `eem_stall_cell_status_set`, `eem_stall_bulk_status_set`.
- **Restored "move customer to another stall"** on the readiness grid (occupied cells re-open the
  existing cell-action menu → destination-mode → scope modal → `eem_move_stall_assignment`).
- **Map view regression fixed** — `EEM.renderStallMaps()` init guard pointed at the renamed
  `sc-inv-select` action; maps render again. Added Stall Units / RV Lots section dividers + a
  full-bleed light-blue summary band styled like Daily Movement (status-colored dots).
- **Menu** — Events + Producers now appear ONLY when Native Events is the *active* event source
  (`get_default_event_source() === 'native'`); TEC/GEMS collapse the menu to Venues only.
- **Print Views — full rework.** Filter bar mirrors the live screen: SHOW (All/Stalls/RV) + VIEW
  (By Location / By Customer); 3-way rows toggle Assigned only / All Stalls / All RV. By Location
  tables: dropped Customer + Order # cols, occupied night pills show the customer name; barn name
  row moved ABOVE the per-barn column header; page-break per new barn; clean row breakpoints.
  By Customer: SHOW-variant columns (Customer · Arrival · Departure · Barn · Stall # · RV Lot ·
  RV Lot # · Status) — Order # was added then removed per Whitney; check-in Status pill.
- **Status color standard (everywhere):** Checked In = blue, Available = green (always),
  Pending Arrival = red, Checked Out = amber, Cleaning = purple.
- **"New set style" light-blue band (`#f0f4fb` + `#d9e2f2` borders, navy text)** applied to all
  print section bands, all table headers (print + Daily Movement), the DM print "Print/Save PDF"
  button fixed off-brand `#3b82f6` → brand `#1668F2`.

**Known follow-ups / watch-outs:**
- RV lot name/number split keys on the **last space** in the lot label (`"Zone 2 8"` → `Zone 2` / `8`).
  Verify against real GEMS lot labels — non-`name<space>number` labels could split wrong.
- Reservation **5990 RV map is corrupted** (do-not-touch per memory); verify maps on **NTR 6519**.
- ✅ Task #229 RESOLVED (2026-06-18, @ 2.7.467): critical error when trashing a draft reservation. Root cause was the migration-recursion crash (commit 6b7f1e8), not a trash-specific bug; trashing drafts verified clean on the laptop.
- A dead `$or_num` assignment remains in the print By Customer loop (harmless; tidy when convenient).
- All verification this session was via browser/print-preview on Local; no smoke tests were added
  for the new readiness store / print rework — worth backfilling (task #234).
- 6-dates-per-row Daily Movement print grid was applied but **not browser-verified** — confirm on
  the laptop once Local is up (print preview the Daily Movement page).

---


**Canonical, version-controlled to-do list.** Last updated 2026-06-20 · plugin at **v2.7.515**
· on `main`. **v1 is complete and launch-ready.** Authoritative decision history
lives in `CLAUDE.md`; deep architecture in `docs/ARCHITECTURE-DATA-OWNERSHIP.md`,
`docs/ARCHITECTURE-VENUES.md`, and `docs/WORKPLAN-postmeta-decouple.md`.

The sections below are grouped by **version tier** (v2/v3/v4). The **EXECUTION ORDER** is a
separate axis — it cuts across the tiers, prioritising *protect the live business → serve the
device customers use → finish small threads → strategic refactor → net-new features → months-out
API/native last.*

---

## 🥇 RECOMMENDED EXECUTION ORDER (set 2026-06-13)

*Live system handling money + selling out in minutes → protect first, refactor later.*

1. **Strict inventory / concurrency audit** (sellouts) — oversell/double-charge is the worst case.
2. **Financial-security audit + `docs/SECURITY-AUDIT-REPORT.md`** — pairs with #1 as one
   "bulletproof the transactional core" pass.
3. **Mobile-experience + PWA polish — CUSTOMER *and* ADMIN (tablet + phone)** — customers buy on
   phones during sellouts AND admins run this on the fly ringside; both surfaces must be VERY
   responsive. Doable now (the PWA also gives "app feel" while native is months out).
4. **Venue Slice 2** — `en_venue` → canonical-table write-through (finishes the venue thread).
5. ✅ **Repo cleanup — delete dead docs** (dead dev docs removed 2.7.317).
6. **Entry-aware Dashboard headline metrics** — small additive admin visibility.
7. ✅ **Postmeta → relational de-coupling** — COMPLETE (2.7.311–2.7.318).
   `EEM_Reservation_Config` repo skeleton (P1), relational table `wp_eem_reservation_config`
   with backfill migration (P2.1), reads from table (P2.2), query helpers as SQL JOINs (P2.3),
   repo save writes table-only (P2.4), CPT `save_meta()` writes relational-primary with
   postmeta fallback (2.7.318), migration 017 drops `_en_*` config postmeta rows (2.7.318).
   Storage backend is now a one-class swap to GH/.NET.
8. **Sheets & Results — CSV / Google Sheets / external URLs.**
9. **Event Entries — competition management.**
10. **PDF venue map → stall-grid overlay** (exploratory).
11. **Global Handicaps data-ownership / API integration** — months out (Whitney).
12. **Native mobile app (v4)** — API-gated; comes with/after #11.

---

## 🔒 Concurrency hardening — follow-ups from the 2.7.515 re-audit

From the **ADDENDUM (2026-06-20, v2.7.515)** in `docs/INVENTORY-CONCURRENCY-REPORT.md`. The customer
checkout + all stall/RV assignment paths are confirmed safe; these are the **admin money-path** gaps.
None are HIGH (each needs near-simultaneous admin action or a double-submit). **MED-4 is the only one
that can move real money twice — payment path, change one-at-a-time + live Auth.net test per CLAUDE.md.**

- [x] **MED-3 — Edit-Dates "charge" branch is unlocked** — ✅ **DONE (2.7.520).** Wrapped
  `handle_ajax_edit_dates` in `acquire_assignment_lock(reservation_id)` (`eem_checkout_<rid>`) with the
  component rows re-read in-lock; a duplicate submit re-reads the updated dates → delta 0 → no
  double-apply. Refund branch uses a different lock key (no deadlock). *(Residual: a concurrent
  Add-Items on the same rows isn't under the same lock — separate, lower-likelihood; revisit if it
  surfaces.)*
- [ ] **MED-4 — Admin Collect Payment (Auth.net) double-charge window** (`shortcodes.php`
  `ajax_collect_payment_authorize_charge:8284`). Non-atomic `already_paid` check vs the live charge →
  a double-click can fire two authCaptures. **Fix:** per-order `GET_LOCK` around read→charge→mark +
  re-read status in-lock; tighten Auth.net `duplicateWindow`. ⚠️ payment path — sign-off + live test.
- [ ] **LOW-6 — `add_component_quantity` subtotal drift** (`orders-repository.php:595`, `:645-668`).
  Adds the priced delta onto the stored subtotal instead of re-deriving `unit_price × qty × nights`, so a
  pre-existing drift compounds. This is the root of the **#90801 $121-vs-$135** mismatch. **Fix:**
  recompute the row from rate×qty×nights after a qty bump (decide if manual price overrides are supported
  first), or a one-time reconciliation pass.
- [x] **LOW-5 — custom-item double-submit** — ✅ **DONE (2.7.520).** Add-Items confirm button stays
  disabled through the post-success reload (re-enabled only on failure), so a fast second click can't
  duplicate the line item. *(Server-side dedup remains a possible future hardening.)*
- [ ] **LOW-3/4 — minor recheck windows (open):** Stripe confirm has no already-paid recheck (no 2nd
  charge though — payment path, needs sign-off); mark-paid-manual non-atomic (duplicate note, no card
  charged). Low priority; add short locks / idempotency rechecks.

---

## ✅ v1 — DONE (shipped)

**Core v1 build list (1–9):**
1. ✅ Entries restructure — `en_entry` CPT + styled editor + custom list (2.7.201–207)
2. ✅ Inventory concurrent-assign backstop — advisory locks on all admin write paths (2.7.202)
3. ✅ By-Location print refinement — dense "Assigned only" + "All stalls" toggle (2.7.203)
4. ✅ Per-night RV lot moves (2.7.204)
5. ✅ Readable event URLs for existing reservations (2.7.210)
6. ⏸️ Payment-key encryption — **DEFERRED by decision** (accept-risk; not built)
7. ✅ Bulk "Send Payment Link" on Orders (2.7.211)
8. ✅ More transactional emails — added Payment-Received (2.7.212)
9. ✅ Orders soft-delete / Trash lifecycle (2.7.213)

**Entries → Divisions rework:**
- ✅ Single-Division data model + editor + list; entrants ledger `wp_eem_division_entries` +
  spots cap (in-lock) + customer & Create-Order fold; Division detail page + stats; polish
  (full-width KPI cards, canonical toolbar, Entry-type badge, "Past" pill) (2.7.214–222)

**Notifications — DONE (2.7.224–226).** Dedicated page: pick an event → build an audience
(Include [All/Stall/RV/Add-on/a Division's entrants/Group] − optional Exclude + optional Payment
filter) with a live recipient count → compose → batched send (25/req) → history. Recipients from
orders (incl. division-only) + the division ledger; Emogrifier-inlined.

**Venues + Facility Layout Templates — DONE (2.7.229–231).** Source-agnostic Venue entity
(relational tables) owns saved layouts; TEC/GEMS resolve into it via `EEM_Venue`. "Save Layout /
Load Layout" on both the stall and RV builders (copy-on-use clone; full combined structural
layout). Unified under the native `en_venue` "Venues" surface at 2.7.249 (see Remaining #3).

**Native Events — DONE (2.7.234–256).** Un-gated `en_event`/`en_venue`/`en_producer` CPTs
(selectable in Settings → Integrations); Facebook + Instagram event fields; venue geocoding
(Google Geocoding); frontend calendar via `[en_events]` (list / `images="no"` / month / map);
Google Maps API-key setting; **no tickets**. Branded admin pages (Venues / Producers / Events /
Categories list pages + branded Add/Edit Event editor) replacing the raw WP screens (2.7.251–256).

**Sheets & Results — DONE (2.7.258–271).** Draw-sheet / result PDF system per event
(`en_discipline` taxonomy, `wp_eem_sheet_entries`): admin manager page, event-editor section,
additive public event-list buttons, public per-event page; discipline rename/delete; tied-together
demo seeder. Mockups imported to `.mockups/` (`screen1–4` + scope doc).

**Optional feature toggles — DONE (2.7.269–271).** Entries + Sheets & Results disableable
per-site via Settings → Add-Ons (official `.eem-toggle` control). On-for-existing / off-for-new
installs; turning one off hides it everywhere without deleting data.

**Auth.net live charge — VERIFIED (2026-06-12).** Two live admin Collect Payment charges
succeeded; the last launch gate is cleared.

**Health:** smoke suite **154 files, 0 failures**. All committed + pushed.

---

## 🚦 v1 — REMAINING BEFORE LAUNCH

**Nothing.** v1 is feature-complete and launch-ready.

---

## 🔧 POLISH / FOLLOW-UPS — ✅ DONE (2.7.272–275)

1. ✅ **Synced 5 Native Events admin mockups to as-built** (producers Location column removed;
   events Status → lifecycle badge; venues/categories/add_event already matched).
2. ✅ **Saved layouts on the `en_venue` editor** — "Saved Stall / RV Layouts" meta box (list +
   rename/delete), backed by native en_venue → canonical `EEM_Venue` resolution so two events at
   the same venue share one layout set (2.7.274).
3. ✅ **`filter="upcoming/ongoing/past/all"` alias** for `[en_events]` + new `ongoing` timeframe
   (2.7.272).
4. ✅ **Event-Setup completeness meter** on the event editor rail (progress bar + 6-item
   checklist, live-updating) (2.7.273).
5. ✅ **Dashboard Add-Ons card** — Entries (divisions/entrants) + Sheets & Results
   (draw-sheets/results/awaiting) activity, gated on the feature flags (2.7.275).

---

## 🔧 OPEN FOLLOW-UPS (actionable; no launch blocker)

**Immediate (carried from the 2026-06-18 Stall Charts / print session — do these first):**

0a. ✅ **DONE (2026-06-18, @ 2.7.467) — critical error when trashing a draft reservation (#229).**
    Root cause was the migration-recursion crash (commit 6b7f1e8) that 500'd every admin page on
    the stale-DB upgrade path, not a trash-specific bug. Verified clean on the laptop (list-row
    Trash + editor Trash button), approved by Whitney.
0b. ✅ **DONE (2026-06-18, @ 2.7.467) — backfill smoke coverage (#234).** `stall-status-readiness-smoke.php`
    (17 assertions: `set_cell_status` / `bulk_set_status` / `mark_order_stalls_needs_cleaning` with
    canonical read-back) + `print-view-show-view-smoke.php` (17: VIEW/SHOW/all_rv variants). Move-customer
    flow already covered by stall-per-night-move + rv-night-move smokes. Commit 2e442bd.

0d. ✅ **DONE (2026-06-18, @ 2.7.468, commit 5fb5217) — config backfill dropped `stall_rows` on oversized
    varchar.** ROOT CAUSE was NOT migration 005 (innocent). It was `insert_from_values()` using
    `$wpdb->replace()`, which fails ATOMICALLY when any one field is invalid for its column: a legacy
    unsanitized `_en_checkin_time` datetime (`YYYY-MM-DDTHH:MM`, 16 chars) overflowed `checkin_time
    varchar(10)`, so the WHOLE row was rejected — silently dropping `stall_rows` + every other column.
    RV survived only because migration 027 re-creates the row with rv_rows. **This is what actually ate
    the 'Super Sort' (res 43) stall map on Whitney's live desktop DB** — res 43 had a datetime check-in,
    6519 did not. Fix: `cast_for_db()` truncates varchar/char to declared width so one oversized field
    can't fail the row; stray field self-heals on next save. Smoke: `config-oversized-varchar-smoke.php`
    (8). NOTE: res 43's map is recoverable from `.dev-fixtures/db-snapshot-2.3.46-premigration.sql` if Whitney wants it.

0e. ✅ **DONE (2026-06-18, commit 50ceca8) — repaired drifted smokes.** `print-view-assigned-only` +
    `rv-night-move`: whitespace-normalized source before substring checks (survives `=`-alignment),
    updated to the per-inventory print vars (`stall_assigned_only`/`rv_assigned_only`) + refactored
    matrix-caller signature, and skip known-corrupt reservation 5990 in the fixture finder. Both green
    (10/0 +1 skip, 18/0).
0c. ✅ **DONE (2026-06-18) — RV lot name/number split verified CORRECT on NTR 6519.** The last-space
    split (admin 4265 + 6805) is the exact inverse of label construction (`zone . ' ' . num`,
    admin:5300); `num` from `expand_label_range()` has no internal space, so single/multi-word zones
    and prefixed/padded lots all round-trip. NTR 6519: 25/25 correct. Only breaks on labels NOT
    built by this path (externally-sourced no-space / non-numeric-tail) — not a real risk. No code change.

1. **Entry-aware Dashboard headline metrics.** Entry/division revenue **already flows into Total
   Revenue / Total Orders / This Week** (entries fold into the order subtotal at checkout —
   `shortcodes.php:3644`, `$subtotal += $pre_entries_subtotal`; the dashboard reads order totals).
   What's missing is *visibility*: entries are summed into the aggregates but never broken out as
   their own headline figure. When Entries is ON, add an entry-focused KPI/metric (e.g. "Entries
   Sold" count + entry revenue) to the dashboard's top metric row, matching the 4-color KPI card
   style — alongside the existing Add-Ons summary card. Consider the same treatment for Sheets &
   Results if useful. Gate on the feature flags. (Verified 2.7.281: revenue wiring is correct; this
   is an additive surfacing task, not a bug fix.)
   - **Dashboard completeness — make sure nothing is left out (Whitney 2026-06-14).**
     - **Native Events → "Upcoming Events" card.** When the Native Events feature/source is ON, add
       a dedicated dashboard card listing upcoming `en_event` events (date · title · venue),
       parallel to the existing "Upcoming Reservations" card. Gate on Native Events being enabled.
     - **Entries card.** Entries already shows in the Add-Ons card (divisions/entered counts +
       Manage link). Confirm that's prominent enough; give Entries its own card if the Add-Ons line
       isn't sufficient. Goal: an admin sees Entries activity at a glance when the feature is on.

2. **Mobile-experience + PWA polish — CUSTOMER *and* ADMIN (binding: Whitney 2026-06-13).** Make
   the whole plugin feel like a real app on phones AND tablets — *without* leaving WordPress
   (Vercel/native is the v4 headless track, gated on the v2/v3 API; a PWA on the current pages is
   the right move now and a clean stepping stone to it). **Both surfaces are in scope and both must
   be VERY responsive at tablet + phone widths** — admins run this on the fly from a phone/tablet
   (assigning stalls, taking payments, checking orders ringside), so the admin pages are NOT a
   "later, via the headless app" item. Scope:
   - **(a) Customer-facing responsive/touch audit** — `[en_reservation]` checkout, event pages,
     public sheets/results: large touch targets, sticky bottom action bars (mockups spec
     `sticky-save`), AJAX-not-reload, loading/skeleton states, the stall/RV picker on small screens.
   - **(b) Admin responsive/touch audit (tablet + phone)** — every branded admin page (Dashboard,
     Orders + Order Detail, Reservations, Reservation editor, Stall & RV Charts + the chart/picker,
     Entries, Sheets & Results, Venues/Producers/Events, Notifications, Reports, Settings). Tables
     collapse to mobile cards (pattern already exists), toolbars wrap/stack, the stall-chart grid is
     pan/zoom usable on a phone, modals + forms fit small screens, 38px controls stay tap-friendly.
     Audit at two breakpoints explicitly: **tablet (~768px) and phone (~390px).**
   - **(c) PWA wrapper** — `manifest.json` + service worker + install prompt for "Add to Home
     Screen" (full-screen, splash, offline shell), covering both the customer route and the admin.
   Deliverable on the current stack; the eventual v4 client replaces it behind the same URLs.

---

## 🧭 ARCHITECTURE TRAJECTORY — "off WordPress" path (the why behind v2 → v4)

The binding direction is *"not chained to WordPress forever — WordPress is a replaceable
front-end, API-first/headless."* That destination (a PWA or native mobile app) is reached in
**four moves, each enabling the next.** Each is necessary but NOT sufficient on its own:

1. **Decouple (v2)** — move data + business rules out of `wp_postmeta`/CPTs into relational tables
   behind repository classes. *Frees the data.* This is the foundation; without it any later client
   would be screen-scraping WP's EAV mess or facing a rewrite.
2. **API (v3)** — stand up a REST/GraphQL layer exposing those repositories as the canonical
   contract (the §8 order/entry payload). *This is the thing a PWA/native app actually talks to* —
   apps need an API, not direct DB access. Lands with the GH data-ownership integration.
3. **Swap/add front-ends (v4)** — web, **PWA, native mobile**, or GH's own platform, all hitting
   the same API. No data migration needed at that point because data + rules already sit behind it.

Key nuance: a PWA *could* technically be built against WP's REST API today, but it would be brittle
(reading EAV/CPT data directly). The v2 decouple is what turns "possible but fragile" into "clean
and durable." **Keep every new persistence behind a repository + prefer relational tables over
`wp_postmeta` so the eventual API is clean** — that's the cheap v1/v2 guardrail that keeps v4 easy.

---

## 🔭 v2 — ARCHITECTURE TRACK (next up; build order set 2026-06-13)

**Recommended order: #1 (`en_venue` unification) → #2 (postmeta de-coupling).** The venue work
is smaller, lower-risk, builds directly on the just-shipped venue resolution (2.7.274), and is a
proving ground for the relational-migration pattern that #2 then applies at scale.

1. **`en_venue` → canonical `EEM_Venue` unification** — the source-aware *resolution* + the venue
   editor's Saved-Layouts meta box already landed (2.7.274). What remains: persist the link
   between each `en_venue` post and its canonical `EEM_Venue` row (so they are durably one record,
   not re-resolved each time), and make the `en_venue` editor write venue identity/address through
   to the relational store. Venue tables are already relational — no big migration; this is the
   warm-up that de-risks #2.
2. **Postmeta → relational de-coupling** *(binding direction: "not chained to WordPress forever")*
   — move reservation/division config out of `wp_postmeta` into relational tables behind an
   `EEM_Reservation_Config` repository, making WordPress a replaceable front-end. **Phase 1
   (funnel) is the recommended first move** — low-risk, independently valuable. Full plan:
   `docs/WORKPLAN-postmeta-decouple.md`.

### v2 — Features

3. **"Weekly Rate" pricing in Edit Reservations (Whitney 2026-06-14).** Add a THIRD pricing option
   alongside Nightly and Weekend Rate — **Nightly · Weekend Rate · Weekly Rate** — for BOTH stalls
   and RVs. Same logic/shape as the existing Weekend Rate (a flat rate charged once for a "weekly"
   stay, with its own enable toggle + rate field + package dates + Early-Bird variant, mirroring the
   Weekend Rate rows). Touches the stay-type toggles + pricing fields in the Edit Reservation
   editor (stall + RV sections), the customer checkout pricing math (`calculate_submission_totals`),
   and the at-least-one-stay-type constraint. Follow the Weekend Rate implementation as the template
   (see docs/decisions.md pricing rules + `weekend_rate`/`weekend_price` in the orders repo + CPT).
   Est. ~1 session.

4. **Paddock Assignments (Whitney 2026-06-14).** In the stall map grid, allow adjacent stall chips
   to be merged into a single bookable "paddock" unit. Admin selects adjacent chips → names the
   paddock (e.g. "Paddock 1") → sets a flat or nightly rate independent of stall pricing. The merged
   unit books and invoices as one line item; renders as a wider block on the stall chart. Reuses the
   full stall engine (assignment, chart, orders) — the merge just groups chips into one bookable
   unit with its own pricing. Est. ~2 sessions.

5. **Upload .xlsx → Stall Grid (Whitney 2026-06-14).** "Upload Layout" button in the stall row
   builder (and on Venue layouts) accepts an `.xlsx` file and auto-generates stall rows from it.
   Parser uses PHP's built-in `ZipArchive` + `SimpleXML` — no new Composer dependencies. Concept
   proven against the COJT-SUMMER stall chart: barns/sections + stall number ranges parse cleanly.
   Includes a **"Download Example Template"** link so users know the expected format (simple 2-section
   template, not the complex COJT layout). Est. ~2 sessions.

---

## 🏗️ v3 — ARCHITECTURE TRACK + DEFERRED FEATURES

*Sequenced after v2.*

1. **Global Handicaps data ownership / API integration** — make GH the system of record for
   reservation data (they already own memberships + GEMS events). Two models (Sync vs.
   GH-primary); gating dependency is GH providing an **atomic inventory-reserve API**. Full
   spec + payload contract: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`. Do **after** the v2 postmeta
   de-coupling (the sync layer reads the repository, not postmeta).
2. **Event Entries — competition management** *(moved from v2, 2026-06-13; future, beyond selling
   entry spots which Divisions already does)* — horse/rider details per entry, results/placings/
   times, payouts/added money/jackpot, multiple go-rounds + draw order. Extends the Divisions
   entrants ledger.
3. **PDF Venue Map → overlay / conversion** *(exploratory)* — upload a PDF venue map; MVP =
   render to image + drop/snap stall hotspots onto it. Needs a server PDF-render dependency.
   Pairs with Facility Layout Templates.
4. **Sheets & Results — more source types beyond PDF.** Today each draw-sheet/result is a single
   uploaded PDF (`drawsheet_pdf` / `result_pdf` attachment ids). Extend the entry to accept other
   sources: **CSV upload** (render as a table on the public page), a **Google Sheets connection**
   (live link/embed, or pull-and-cache), and a plain **external URL** (link out to a results
   provider). Needs a source-type column on `wp_eem_sheet_entries` + per-type render + the admin
   "Add File" panel growing a type picker. Keep PDF as the default.
5. **"Accept Deposits" option (Whitney 2026-06-14).** Let the admin enable a **deposit** on stall
   and/or RV reservations: set a deposit amount (per the reservation, on stalls and/or RVs), the
   customer pays just the deposit online at checkout, and the **remaining balance is collected at
   the event by cash or card**. Needs: a deposit toggle + amount field in the Edit Reservation
   pricing (stall + RV), checkout charging the deposit instead of the full total (order records the
   deposit paid + balance due), and a "balance due / collect at event" state surfaced on the Order
   (so admin can take the rest via the existing Collect Payment flow or mark cash-paid). Touches
   pricing math, order totals/`amount_due`, and the Collect Payment / Order Detail surfaces.

### Hardening / audits (v3)

5. **VERY thorough financial-security audit + a referenceable report.** A deeper pass than the
   Jun-2026 audit (which fixed refund double-spend, Stripe payment-method reuse, and oversell via
   advisory locks — see `[[security-inventory-hardening]]`). Cover the full money path end-to-end:
   Stripe + Authorize.net charge/refund dispatch, amount/total integrity (no client-trusted prices;
   server recomputes), idempotency on charge + refund, webhook auth + replay, discount/tax/
   convenience-fee math, payment-key handling, capability + nonce on every money endpoint, and
   activity-log completeness. **Deliverable: commit a `docs/SECURITY-AUDIT-REPORT.md` to the repo**
   the dev team can refer to — scope, threat model, findings (with severity), what's mitigated and
   how, and accepted-risk items.
6. **STRICT inventory / concurrency audit for high-demand sellouts.** Entries + stall/RV
   reservations can sell out in minutes with many simultaneous buyers. Audit every reserve/assign
   write path for race-free, exactly-once allocation under heavy concurrency: the `GET_LOCK`
   advisory-lock coverage (all 5 admin assign paths + checkout), atomic check-and-reserve before
   charging, the entries spots-cap (`wp_eem_division_entries` ledger), double-submit / refresh /
   back-button replays, and DB-level uniqueness as a backstop (the notes→table migration still
   deferred — re-evaluate it here). Goal: provably no oversell, no lost-update, no double-charge no
   matter how many people hit "buy" at once. Document the guarantees alongside the security report.
7. **Repo / distribution cleanup.** A lot of dev-only and superseded files are tracked. Two facets:
   (a) **delete the genuinely dead** — historical/process docs no longer needed (candidates:
   `BACKLOG.md`, `CLEANUP.md`, `OVERHAUL_REPORT.md`, `SESSION-HANDOFF.md`, `WALKTHROUGH.md`, the
   per-chunk `docs/AUDIT-*.md`, stray `.mockups/*.csv` + `.mockups/.archive/` + `.mockups/
   generated-reference/`); (b) **keep but EXCLUDE from the shipped plugin ZIP** the dev-reference
   that's still useful in the repo (`.mockups/`, `docs/`, `tests/`, `scripts/`, `tools/`, `phpcs.xml`,
   `composer.*`) via `.gitattributes export-ignore` / the `build-release.yml` workflow, so the
   distributed plugin carries only runtime code. **Keep at root:** `ROADMAP.md`, `CLAUDE.md`,
   `README.md`. Verify each deletion with a full-project grep first (some docs are cross-referenced),
   and confirm the keep/delete list with Whitney before removing anything.

---

## 📱 v4 — HEADLESS CLIENTS (not soon; the payoff of the v2/v3 architecture work)

*Strictly gated on v2 (decouple) + v3 (API) being done — these are clients of the API, not new
data models. Not happening for a while; listed so the architecture stays aimed at it.*

1. **PWA (installable web app)** — offline-capable, installable customer/admin web app served from
   the same API. No app-store dependency; fastest path to a "real app" feel.
2. **Native mobile app** — iOS/Android client over the same API contract. Same backend as the PWA;
   differs only in distribution + device integration (camera for check-in, push, etc.).

**Readiness gate (do NOT start v4 until all true):** (a) reservation/division config lives in
relational tables behind repositories, not `wp_postmeta`; (b) a stable, versioned API exposes the
§8 order/entry payload + the atomic inventory-reserve endpoint; (c) auth works outside the WP admin
cookie session. Until then, every v1–v3 chunk just keeps paying down the guardrail (repositories +
relational tables) so this stays cheap when the time comes.

---

## 📚 Reference documents
- `CLAUDE.md` — authoritative decisions, conventions, v1/v2 source notes, chunk history.
- `docs/ARCHITECTURE-VENUES.md` — source-agnostic Venue model + resolver + Facility Layout
  Templates design.
- `docs/ARCHITECTURE-DATA-OWNERSHIP.md` — data storage, GH integration models, payload
  contract, WordPress-replaceable principle.
- `docs/WORKPLAN-postmeta-decouple.md` — the postmeta→relational migration plan + estimate.
- `OVERHAUL_REPORT.md` — before/after of the original Codex-overhaul effort.
