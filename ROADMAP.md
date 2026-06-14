# Equine Event Manager — Roadmap & To-Do

**Canonical, version-controlled to-do list.** Last updated 2026-06-13 · plugin at **v2.7.271**
· branch `v4-stall-mapping`. **v1 is complete and launch-ready.** Remaining work is optional
polish + the v2 feature/architecture backlog and the v3 track. Authoritative decision history
lives in `CLAUDE.md`; deep architecture in `docs/ARCHITECTURE-DATA-OWNERSHIP.md`,
`docs/ARCHITECTURE-VENUES.md`, and `docs/WORKPLAN-postmeta-decouple.md`.

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

1. **Entry-aware Dashboard headline metrics.** Entry/division revenue **already flows into Total
   Revenue / Total Orders / This Week** (entries fold into the order subtotal at checkout —
   `shortcodes.php:3644`, `$subtotal += $pre_entries_subtotal`; the dashboard reads order totals).
   What's missing is *visibility*: entries are summed into the aggregates but never broken out as
   their own headline figure. When Entries is ON, add an entry-focused KPI/metric (e.g. "Entries
   Sold" count + entry revenue) to the dashboard's top metric row, matching the 4-color KPI card
   style — alongside the existing Add-Ons summary card. Consider the same treatment for Sheets &
   Results if useful. Gate on the feature flags. (Verified 2.7.281: revenue wiring is correct; this
   is an additive surfacing task, not a bug fix.)

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

---

## 📚 Reference documents
- `CLAUDE.md` — authoritative decisions, conventions, v1/v2 source notes, chunk history.
- `docs/ARCHITECTURE-VENUES.md` — source-agnostic Venue model + resolver + Facility Layout
  Templates design.
- `docs/ARCHITECTURE-DATA-OWNERSHIP.md` — data storage, GH integration models, payload
  contract, WordPress-replaceable principle.
- `docs/WORKPLAN-postmeta-decouple.md` — the postmeta→relational migration plan + estimate.
- `OVERHAUL_REPORT.md` — before/after of the original Codex-overhaul effort.
