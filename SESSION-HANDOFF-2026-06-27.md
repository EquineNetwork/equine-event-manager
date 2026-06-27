# Session Handoff — 2026-06-27 (cloud session)

Single source of truth for what shipped this session, what was decided, and what's
still open (and who owns it). Picks up after `c6-complete` era work; this session
ran versions **2.7.658 → 2.7.667**.

---

## ✅ Shipped & live (this session)

| Version | What |
|---|---|
| 2.7.658 | Stall & RV Charts — merged stalls-first View switcher (later reverted to two dropdowns) |
| 2.7.659 | Charts: dropped redundant H1, two-dropdown toolbar (Stalls/RV/Both + View), fixed Stall Charts **list** Event Start/End columns (now resolve via `EEM_Reservation_Source_Resolver::resolve_event_fields` — they were reading empty `_en_start_date`) |
| 2.7.660 | Charts list cleanup: dropped redundant **Barn column** (kept barn divider rows), moved Quick-View chips into the bulk/barn row, consolidated check-in legend into the gray/navy **STALL UNITS** band, weekday dates ("Thu, June 25"), **By-Customer check-in status quick filters** (All/Pending/Checked-In/Checked-Out) |
| 2.7.661 | STALL UNITS band recolored to barn-row gray + navy text |
| 2.7.662 | **Add-Ons report** (Reports page) + By-Customer sort affordance (⇅) + barn name moved under stall numbers in By-Customer |
| 2.7.663 | **Daily Movement**: no cell truncation (scroll horizontally instead), Arrival/Departure click-to-sort |
| 2.7.664 | **Mobile M0 foundation**: `.eem-table-scroll`/`.eem-table-wrap` scroll wrappers, `--eem-tap-min: 44px` tap targets, page max-width guard |
| 2.7.665 | Mobile: modals fit small screens (capped height + internal scroll) |
| 2.7.666 | **List tables matched to Daily Movement density** (`.eem-table`: 14px font, 14×16 padding, navy text) across Orders/Reservations/Customers/Events/Notifications/Reports/Producers |
| 2.7.667 | By-Customer chart table + Order Detail table density matched to DM baseline |

### Feature/behavior summary
- **RV add-ons fully removed** (deprecated; replaced by map surcharges). No legacy orders used them — confirmed by Whitney. Removed from charge path, admin display, config/meta, repo, docs. *Note for smoke triage: any remaining `*rv_addon*` test assertions are stale and should be deleted/updated.*
- **Convenience fee → global Settings only** (pure global, no per-reservation override; ships disabled/$0; admin sets it in Settings → Payments after deploy). `EEM_Settings_Repo::get_convenience_fee()` / `get_convenience_fee_amount()`.
- **Contiguous stall assignment** — single order's stalls kept in a consecutive run within one barn (auto-assign Pass 1.6).
- **Venue auto-save layout** — every reservation save upserts one rolling "Auto-saved (latest)" layout per venue (`EEM_Venue::auto_save_reservation_layout`).
- **#9 display-math parity** — frontend / customer receipt / admin receipt / Order Detail reconcile to charged total; structural invariant smokes.
- **Add-Ons report** — `addons` slug in `EEM_Reports_Repo`; daily view = dynamic per-add-on columns counted on every day of stay + "Total Purchased" note section; summary = per-event units. 12-assertion smoke passing.
- **Add-On qty source**: order notes `Add-On: NAME | Qty: N | ...` parsed by `parse_addons_from_notes()`.

---

## 🔍 Audit findings (important — saved future rework)

- **#5 Postmeta → relational decoupling is essentially ALREADY BUILT.** `wp_eem_reservation_config` mirrors the full reservation config surface (event source, enable flags, modes, dates, all pricing, convenience fee, shavings, descriptions, venue, check-in) with config-first read → postmeta fallback → lazy backfill (`EEM_Reservation_Config`). Orders already relational (`wp_en_stall_reservations`/`wp_en_rv_reservations`). Remaining postmeta = whole-blob JSON (`_en_stall_rows`, `_en_*_map`, blocked lists) that isn't queried by column, plus Event-CPT meta. No high-value Phase 1 left.
- **The plugin is already broadly mobile-responsive.** Lists use `.eem-desktop-table` + `.eem-mobile-cards`, chart tables have `.eem-chart-table-scroll`, dashboard/order-detail grids stack, settings nav reflows, customer event page has 26 media queries. The #1 "mobile" ask reduced to **density-matching to Daily Movement** (done for all data tables this session) + device-only visual checks.

---

## 🔭 Open items (and owners)

1. **Mobile — device visual check** *(→ Claude Code desktop, local site)*
   - Density match to Daily Movement is DONE for all data tables (list tables, By-Customer, Order Detail).
   - Remaining is screenshot-dependent and best on the local site: customer **event page** (esp. the stall/RV **map picker** on phones) and the **Edit Reservation editor** `1fr 280px` rail at phone widths. Foundation (scroll wrappers, tap targets, modal fit) already shipped.

2. **Green the smoke suite** *(→ Claude Code desktop, local MySQL site)*
   - Cloud run on 2.7.666: 3346 pass / 460 fail / 76 files — **almost all environmental** (bare SQLite sandbox, no fixtures: needs reservation #43 / imports / GEMS). Plugin itself is healthy (loads, no fatals; all self-contained smokes pass).
   - Runner now passes `--allow-root` (`tests/run-all-smokes.php`).
   - Playbook: seed fixtures first (`wp eem seed_demo` needs reservation #43; `tools/seed-test-data.php` needs a configured-chart reservation) → re-run → triage: (a) genuine product bugs → fix, (b) stale tests (e.g. removed RV add-ons) → update/delete, (c) pure-env (GEMS/SQLite) → leave. **Do not edit shipping code to satisfy the SQLite sandbox.**

3. **Payment / checkout sweep** *(→ separate chat, in progress)* — full money-path audit (Stripe + Auth.net). Not taking real payments until ~end of next week.

---

## 📌 Standing facts / guardrails
- Develop on branch `claude/festive-heisenberg-muha01`; squash-merge PRs to `main`; site auto-updates from `main`.
- **Never bump version without explicit approval.** Test stall/RV maps on **NTR 6519** only (reservation 5990 RV map is corrupted). Site is on **Stripe** to avoid live Auth.net charges.
- One Bash command per call (no chaining/heredocs); use Write/Edit for file content.
- Daily Movement table is the **density baseline** for all data tables (14px font, 14×16 cell padding, navy `#0d1b3e` text, 10×16 uppercase headers).
