# Equine Event Manager — Roadmap & To-Do

**Canonical, version-controlled to-do list.** Last updated 2026-06-12 · plugin at **v2.7.223**
· branch `v4-stall-mapping`. Authoritative decision history lives in `CLAUDE.md`; deep
architecture in `docs/ARCHITECTURE-DATA-OWNERSHIP.md` and `docs/WORKPLAN-postmeta-decouple.md`.

---

## ✅ v1 — DONE (shipped this cycle)

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

**Entries → Divisions rework (post-list request):**
- ✅ Slice 1 — single-Division data model + editor + list (2.7.214)
- ✅ Slice 2 — entrants ledger `wp_eem_division_entries` + spots cap (in-lock) + customer & Create-Order fold (2.7.215)
- ✅ Slice 3 — Division detail page + list Entered/Spots stats + oversold note (2.7.216)
- ✅ Polish — full-width KPI cards, canonical list toolbar, Entry type badge on Orders, Choices dropdown width (2.7.217–219)
- ✅ "Past" pill on divisions whose event has ended (2.7.222)

**Fixes shipped:**
- ✅ Frontend stall/RV picker: theme red-border bleed on tabs/zoom + wrong button font (2.7.220–221)
- ✅ Orders bulk select-all checkbox (double-toggle no-op) (2.7.223)

**Health:** smoke suite **135 files / 3,613 assertions / 0 failures**. All committed + pushed.

---

## 🚦 v1 — REMAINING BEFORE LAUNCH

**Nothing.** v1 is feature-complete and launch-ready.

- ✅ **Auth.net live charge — VERIFIED (2026-06-12).** Two live test charges run through the
  admin Collect Payment "Charge Card" path; both succeeded. The last launch gate is cleared.

---

## 🔭 v2 — FEATURE BACKLOG (logged, not started)

Full source notes for each live in `CLAUDE.md` → "v2 deferred features".

1. **Native Events source** — finish + un-gate the `en_event`/`en_venue`/`en_producer` CPTs
   (currently "Coming Soon"). ~1,500 LOC partially built. (v1 sources are TEC + GEMS.)
2. **Event Entries** — take contestant entries for events (disciplines, entry fees, entrant
   roster). Competition-management; distinct from the customer-page Divisions/Pre-Entries.
3. **Facility Layout Templates** — save a venue's stall/RV grid as a reusable per-venue
   template; clone into next year's reservation with **copy-on-use** (edits never touch the
   original). Requires persisting normalized venue names from GEMS/TEC/Native.
4. **PDF Venue Map → overlay / conversion** *(exploratory)* — upload a PDF venue map; MVP =
   render to image + drop/snap stall hotspots onto it (manual overlay) reusing the grid data
   model. Pairs with Facility Layout Templates.
5. **Notifications** — admin composes an email and **sends it to all customers with an order
   for an event** (schedule changes, weather, gate times). Builds on the existing Email
   Customers modal; add compose UI, audience filters, send log, Emogrifier send path.

---

## 🏗️ v3 — ARCHITECTURE TRACK (strategic; see dedicated docs)

*Moved out of v2 (2026-06-12) — these are the platform/headless-foundation efforts, sequenced
after the v2 feature work.*

1. **Postmeta → relational de-coupling** *(binding direction: "not chained to WordPress
   forever")* — move reservation/division config out of `wp_postmeta` into relational tables
   behind an `EEM_Reservation_Config` repository, making WordPress a replaceable front-end.
   **Phase 1 (funnel) is the recommended first move** — low-risk, independently valuable.
   ~2.5–4 weeks. Full plan: `docs/WORKPLAN-postmeta-decouple.md`. **Do this before the GH API.**
2. **Global Handicaps data ownership / API integration** — make GH the system of record for
   reservation data (they already own memberships + GEMS events). Two models (Sync vs.
   GH-primary); gating dependency is GH providing an **atomic inventory-reserve API**. Full
   spec + payload contract: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`. Enables a future native
   mobile app via the same API contract.

**Suggested v3 sequencing:** v3 #1 Phase 1 (de-coupling funnel) → v3 #2 GH API. The de-coupling
makes the GH integration clean (sync layer reads the repository, not postmeta). Note v2 #3
(Facility Layout Templates) benefits from v3 #1's config rows, so if Templates is prioritized
early it may pull v3 #1 forward.

---

## ❌ KILLED — do not reintroduce
- **Scheduled / recurring report exports** (cron + email) — rejected 2026-06-12.
- **"Add to Show Bill"** deferred-payment record — covered by open unpaid orders; rejected 2026-06-09.

---

## 📚 Reference documents
- `CLAUDE.md` — authoritative decisions, conventions, v1/v2 source notes, chunk history.
- `docs/ARCHITECTURE-DATA-OWNERSHIP.md` — data storage, GH integration models, payload
  contract, WordPress-replaceable principle.
- `docs/WORKPLAN-postmeta-decouple.md` — the postmeta→relational migration plan + estimate.
- `OVERHAUL_REPORT.md` — before/after of the original Codex-overhaul effort.
