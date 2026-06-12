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

## 🔭 v2 — FEATURE BACKLOG (scoped 2026-06-12)

Full source notes for each live in `CLAUDE.md` → "v2 deferred features".

1. **Notifications** — admin composes an email and **sends it to all customers with an order
   for an event** (schedule changes, weather, gate times). Builds on the existing Email
   Customers modal; add compose UI, audience filters, send log, Emogrifier send path.
   *(Build first — fast, self-contained.)*
2. **Facility Layout Templates** — save a venue's stall/RV grid as a reusable per-venue
   template; clone into next year's reservation with **copy-on-use** (edits never touch the
   original). **Template captures the FULL structural layout** (stall grid + RV lots/zones +
   blocked stalls/lots + map geometry; excludes pricing/dates). Requires persisting normalized
   venue names from GEMS/TEC/Native.
3. **Event Entries** — competition-management entries. **Scope being defined** (relationship to
   the shipped Divisions feature is the open question — see CLAUDE.md). Build last in v2.

---

## 🏗️ v3 — ARCHITECTURE TRACK + DEFERRED FEATURES

*Sequenced after the v2 feature work.*

### Architecture (strategic; see dedicated docs)
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

### Deferred features (moved out of v2, 2026-06-12)
3. **Native Events source** — finish + un-gate the `en_event`/`en_venue`/`en_producer` CPTs
   (currently "Coming Soon"). ~1,500 LOC partially built. Low priority — TEC + GEMS already
   cover event sourcing.
4. **PDF Venue Map → overlay / conversion** *(exploratory)* — upload a PDF venue map; MVP =
   render to image + drop/snap stall hotspots onto it. Needs a server PDF-render dependency.
   Pairs with Facility Layout Templates.

**Suggested v3 sequencing:** architecture #1 (de-coupling funnel) → #2 (GH API); deferred
features as priorities dictate. The de-coupling makes the GH integration clean (sync layer
reads the repository, not postmeta).

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
