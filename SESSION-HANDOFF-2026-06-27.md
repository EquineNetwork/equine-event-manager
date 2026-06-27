# Session Handoff — 2026-06-27 (cloud session)

Single source of truth for what shipped this session, what was decided, and what's
still open (and who owns it). Picks up after `c6-complete` era work; this session
ran versions **2.7.658 → 2.7.670** (+ a CI workflow). See the "Cloud-session
addendum" at the bottom for the latest health work and the local-setup playbook.

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

---

## Cloud-session addendum (through 2.7.670 + CI)

### Shipped since the report above
- **2.7.668** — `EEM_Formatter::format_order_number` dedupe (6 sites + 4 helpers) + IMP- prefix bug fix.
- **2.7.669** — deleted 21 confirmed-dead methods (−1,089 LOC). Kept `neutralize_csv_cell` + `eemWizardSnooze` (audit false-positives, actually live). `eem_is_cancellation_policy_enabled` left for #34.
- **2.7.670** — `EEM_Stall_Map_Importer::sanitize_snapshot()` defensive guard (corrupted-map landmine).
- **CI** — `.github/workflows/ci.yml`: PHP lint (7.4/8.2) + JS syntax + no-WP smokes on every PR.

### Remaining work — LOCAL-SETUP PLAYBOOK (do on the Local site: real MySQL + fixtures)

These were intentionally NOT done in the cloud sandbox because they can't be integration-verified there (SQLite, no fixtures). Each is sized + sequenced for the desktop session.

**PREP (once): bootstrap fixtures so the smoke suite can actually run**
1. Confirm Local site is on MySQL and the plugin is active.
2. Ensure reservation **#43** exists with stall/RV config, OR adjust `tools/seed-demo-data.php` to your fixture id. Then `wp eem seed_demo`.
3. `wp eval-file tools/seed-test-data.php` (needs a configured-chart reservation).
4. Baseline: `php tests/run-all-smokes.php <wp-path> <php-bin> <wp-cli.phar>` (runner already passes `--allow-root`).

**#25 — Green the smoke suite** (after PREP). Triage remaining fails into: real bugs (fix), stale tests (delete/update — esp. any `*rv_addon*` assertions; RV add-ons were removed), pure-env (skip). Goal: green on Local.

**#26 (finish) — full-suite CI job.** Once #25 is green on a CI fixture, add a job to `ci.yml`: `services: mysql`, install WP, `wp eem seed_demo`, run `tests/run-all-smokes.php`, fail on any FAIL. Make it a required check on `main`.

**#38 — extract `EEM_Reservation_Validator`** (medium). In `class-equine-event-manager-reservations-cpt.php`: move validation/transient-notice logic (`get_validation_*`, the publish-gate validators) into a new `includes/class-eem-reservation-validator.php`; update callers in the CPT + editor page. Verify with the c7c1-4 / publish-validation smokes (need fixtures). Also delete the deprecated `EEM_Reservation_Editor` class (2.4.0) once confirmed nothing loads it.

**#37 — split the admin god-object** (large, 15,450 LOC). Extract in this order, one PR each, running the full suite + manual click-through between: (a) `EEM_Admin_Exports` (CSV/PDF/ZIP handlers), (b) `EEM_Admin_Bulk_Operations` (refund queue / trash / send-link), (c) `EEM_Admin_Reports` (print-preview renders), (d) leave hooks/shell in a lean dispatcher. Watch hook registrations + `wp_ajax_*` callbacks — they must keep resolving. Verify every admin page loads + every AJAX action still fires.

**#33 — strip `admin-legacy.css`** (large/risky). Migrate still-used rules into `admin.css` properly scoped (no `!important`), delete the legacy file + its enqueue. Use DevTools per-page to confirm no component loses styling. Do page-family by page-family.

**#34 — cancellation-policy migration** (needs Whitney sign-off — touches stored data). One-time migration snapshots global `cancellation_policy` option → each reservation's `_eem_cancellation_policy_override`; confirm all display surfaces read override-else-event-default; strip deprecated global Settings UI; then delete `eem_is_cancellation_policy_enabled()`.

**Mobile device checks (#18/#19/#21/#24)** — density already matched to Daily Movement app-wide. Remaining is screenshot-only: customer event page + stall/RV **map picker** on phones, and the Edit Reservation editor `1fr 280px` rail at phone widths. Load on a real device at 320/375/768 and fix specifics.

**Payments (#27/#28/#29/#30)** — owned by the separate payment-audit chat. Coordinate before touching money paths.

**#32 — pre-launch backup** — confirm WP Engine backup covers launch + take a manual DB+uploads snapshot before first real charge.
