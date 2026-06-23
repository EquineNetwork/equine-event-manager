# Equine Event Manager — Roadmap & To-Do

---

## 🔖 SESSION HANDOFF — 2026-06-23

**Current state:** v2.7.580+ on `main`. Separate stall/RV layout saving shipped (migration 037).

**Standing constraints:**
- Never bump version without explicit Whitney approval each time.
- Reservation 5990 RV map is corrupted — test stall/RV maps on **NTR 6519**.
- One Bash command per call, no chaining, no heredocs (see CLAUDE.md command hygiene).
- Desktop, tablet, AND mobile are all equally important — "facilities are going to be using this on tablets a lot."
- Mobile customer cards use border-top separators between them, NOT individual card borders.

---

## ✅ NEW MOCKUPS — ALL DONE (2026-06-21)

All `.mockups/` files fully implemented and verified. Mockup cleanup pending (see Deferred).

---

## ⏸ Deferred (no blocker — do when convenient)

- [~] **Global mobile-first pass — PARTIAL.** Structural scaffolding shipped (2.7.577–580): mobile card border-top separators, `.eem-mobile-cards`/`.eem-desktop-table` class pattern, 44px touch targets via `:where()`, PWA install prompt, sticky toolbars. **NOT yet done:** per-page visual polish to match the Daily Movement standard — consistent row heights, badge sizing parity, spacing/density at phone widths, readable content across all admin pages. Daily Movement is the baseline; every other page needs the same treatment.

- [x] **Page-background color sweep** — **DONE.** `body.eem-shell-page` now uses `var(--eem-bg)` + `min-height: 100vh`; `#wpbody-content` gets `min-height: calc(100vh - 32px)`. No WP gray bleed-through on any page.
- [x] **Global card padding consistency sweep** — **DONE.** Canonical tokens `--eem-card-header-padding: 13px 18px` and `--eem-card-body-padding: 16px 18px` at admin.css:139-140, consumed by `.eem-card-header` and `.eem-card-body`.
- [x] **Mockup cleanup — RESOLVED 2026-06-21: nothing to delete.** All three suspected files are LIVE: `reservation_overview_page.html` (hidden "View Event" submenu, browser-verified on-brand); `events_admin_page.html` (referenced in `class-eem-events-list-page.php`); `event_entry_editor_page.html` is the mockup for the LIVE **Division editor** (route `equine-event-manager-entry-editor` — UI relabeled "Entries"→"Division" but the slug + mockup filename kept the "entry" name; browser-confirmed in use at `entry_id=13646`). `division_editor_page.html` never existed — the old "superseded by" note was wrong. Do NOT delete any of these.
- [x] Global control/button radius sweep → 8px — **DONE.** `--eem-radius: 8px` at admin.css:134, consumed by buttons + inputs plugin-wide.
- [x] Space Grotesk → IBM Plex Sans plugin-wide. **DONE** — all font-family declarations use CSS custom properties (`--eem-font-ui`, `--eem-font-display`) resolving to IBM Plex Sans. Only a comment reference remains.
- [x] **MED-4** — **DONE.** Per-order `GET_LOCK('eem_charge_...')` added to all 3 Collect Payment handlers (Stripe intent, Stripe confirm, Auth.net charge). 10s timeout, order re-read inside lock, `RELEASE_LOCK` in `finally`.
- [x] **LOW-3/4** — **DONE.** Stripe confirm handler now rechecks `payment_status` before processing. `mark_order_paid_manually()` guards against duplicate notes when order already paid.
- [ ] **Excel stall map import** — let admins upload an Excel (.xlsx) file with their stall layout; plugin reads the cell grid and auto-generates stall rows + map grid. Excel cells map cleanly to the Map Builder's row/column structure (no OCR needed). Avoids manually entering backwards-numbered or complex multi-barn layouts. Whitney flagged 2026-06-22.
- [x] **Sticky toolbars on list pages** — **DONE (commit ed60d15, 2.7.580).** Toolbar rows stick below WP admin bar on scroll (32px desktop, 46px tablet, 0px mobile); carded variant excluded.
- [x] **BUG: Reports filter dropdown clipped** — **DONE.** `.eem-reports-filter-card` already has `overflow:visible` in admin.css:11125.
- [x] **BUG: Create Order reservation date format** — **DONE.** All 3 call sites pass dates through `format_date_range()` which outputs `Jun 26–28, 2026` format.
- [x] **BUG: Frontend not reflecting backend reservation changes** — **DONE.** Config-table overlay in `get_reservation_meta()` at shortcodes.php:9325-9353 reads from `EEM_Reservation_Config::for()` and overlays every non-null config value on top of post-meta defaults.
- [x] **BUG: Currency input fields not vertically centered** — **DONE.** `.eem-price-wrap` already has `align-items: center` in admin.css.
- [x] **Global Zoom control styling** — **DONE.** Shared `.eem-zoom` component at admin.css:1182-1186, used across Map Builder, Stall & RV Charts, and customer picker.
- [ ] **Map Builder search bar** — add a search input to the Map Builder so admins can search for a stall/lot number, highlight it on the grid, and scroll to it. Useful for large 300+ stall maps. Whitney flagged 2026-06-22.
- [ ] **Add-On Report** — new report under Reports showing add-on quantities sold per day of the reservation. Columns: Add-On Name | Day 1 (date) | Day 2 | … | Day N | Total. Data source: order line items matching the reservation's configured add-ons. CSV + PDF export. Per-day breakdown method TBD (currently add-ons are flat qty, not per-day). Whitney flagged 2026-06-22.
- [ ] **Dashboard "Upcoming Reservations" chips** — style doesn't match Reservations list badge style. Should be filled colored pills with Stall/RV/Group labels. Whitney flagged 2026-06-22.
- [x] **Stall Charts "By List" default for Numbered+Quantity** — **DONE (commit 6538f8c).** When no spatial map is connected, defaults to By List view.
- [x] Reports — visual verify Customer List + Refund Log render correctly in browser. **DONE 2026-06-21**: both PDF print views render on-brand (branded header, populated tables, footer). Minor data note: Refund Log "Reservation" column blank for order #90011 — likely the per-order reservation_id denormalization gap, not a render bug.
- [~] **Full end-to-end functionality sweep** — FIRST PASS DONE 2026-06-21 (browser page-load + console-error sweep of all admin surfaces + key transactional pages). Results below. NOT yet exhaustive on customer-side checkout (blocked on fixture — see gap).
  - **Clean (no console errors, on-brand, render correctly):** Dashboard, Orders list, Order Detail (#90801), Create Order, Events, Reservations, Stall & RV Charts (page-bg fix confirmed live), Daily Movement, Event Entries/Divisions list, Sheets & Results, Customers, Notifications, Reports (Customer List + Refund Log PDFs), Settings (vertical nav intact), Native Events customer calendar ([en_events]).
  - ✅ ~~**BUG: Collect Payment shows "paid in full" for edited-after-payment orders.**~~ **FIXED.** `collect-payment-page.php:129-130` now computes `$amount_paid` and `$outstanding = max(0, $total_due - $amount_paid)`, gates on actual balance not just status.
  - ⚠️ **UX inconsistency:** Order Detail shows a green "Paid" badge while also showing a Balance-Due banner on edited orders. Expected Order-Edit state, but reads contradictory. Consider a "Partially paid" / "Balance due" badge state.
  - ℹ️ Many seed orders show "Unassigned Event" / blank reservation (also seen in Refund Log) — orders not linked to a reservation. Likely the per-order `reservation_id` denormalization gap, or intentional seed data. Verify whether real (non-seed) orders ever land unassigned.
  - **Coverage gap:** customer-facing `[en_reservation]` checkout form not fully exercised — the only fixture pages point at the corrupted Super Sort (5990) or a map test. Need an `[en_reservation id="6519"]` fixture page (NTR 6519, the healthy reservation) to verify customer checkout end-to-end.
  - Smoke suite (`tests/run-all-smokes.php`) ran but is heavily polluted by environmental noise (smokes shell out to bare `php` not on PATH → `env: php: No such file`; seed-data preconditions; mockup MD5 drift). Not a reliable functionality signal as-is — worth a separate cleanup pass to make the runner pass `php` on PATH to the child smokes.

---

## 🔵 Strategic (v2+)

### v2 — Architecture + Features

1. ~~**`en_venue` → canonical `EEM_Venue` unification**~~ ✅ Done (migration eem-mig-015-native-venue-unify)
2. **Postmeta → relational de-coupling** — move reservation/division config out of `wp_postmeta` into relational tables. Phase 1 (funnel) is the recommended first move. Full plan: `docs/WORKPLAN-postmeta-decouple.md`.
3. ~~**Weekly Rate pricing** — third pricing option alongside Nightly and Weekend Rate, for stalls + RVs. Mirror Weekend Rate implementation.~~ ✅ Done
4. ~~**Paddock Assignments** — merge adjacent stall chips into a bookable paddock unit with its own rate.~~ ✅ Done
5. **Upload .xlsx → Stall Grid** — parse `.xlsx` into stall rows via `ZipArchive` + `SimpleXML`; no new Composer deps. Include "Download Example Template" link.
6. ~~**Repo / distribution cleanup**~~ ✅ Done (`.gitattributes` export-ignore shipped)
7. **GH Draw Outs** — was v1 #4; moved to v2 2026-06-15.
8. **QR Code Generator** — deferred to v2 (Whitney, 2026-06-15).
9. **Push Notifications** — PWA browser push notifications. Deferred to v2 (Whitney, 2026-06-15).
10. **Full permissions matrix** — role-based access controls. Deferred to v2 (Whitney, 2026-06-15).
11. **Event Entries** — competition management: per-discipline entry, entry fees, entrant roster. Distinct from Pre-Entries/Divisions (which are v1-shipped). Deferred to v2 per CLAUDE.md.
12. **Global Handicaps API integration** — GH as system-of-record for reservation data; sync vs GH-primary models. Full write-up: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`. Gated on postmeta de-coupling. Moved from v3 → v2 (Whitney, 2026-06-23).
13. **PWA + responsive/touch** — full offline-capable, installable web app over API. Basic scaffolding shipped (manifest + SW + install prompt, 2.7.580); full version is v2. Moved from v4 → v2 (Whitney, 2026-06-23).
14. **Native mobile app** — iOS/Android over the same API contract. Moved from v4 → v2 (Whitney, 2026-06-23).

### v3 — Architecture + Deferred Features

1. **PDF Venue Map → overlay** — upload a PDF venue map; render to image + drop/snap stall hotspots. Pairs with Facility Layout Templates.
2. **Sheets & Results — more source types** — CSV upload, Google Sheets link, external URL. Source-type column on `wp_eem_sheet_entries` + per-type render.
3. ~~**Financial-security audit**~~ ✅ Done (`docs/SECURITY-AUDIT-REPORT.md` shipped)
4. ~~**Strict concurrency audit**~~ ✅ Done (advisory locks on all write paths)
5. **Accept Deposits** — show full total, then "Deposit due now: $X" and "Balance due at event: $Y" at checkout. Deferred to v3 (Whitney, 2026-06-14).

---

## ✅ v1 — DONE

v1 is feature-complete and live. Key shipped items:

- Core reservation + orders engine (stalls, RV, add-ons, fees, discounts, custom line items)
- Stripe + Authorize.net payment processing (two live Auth.net charges verified 2026-06-12)
- TEC + GEMS event sources (Native Events gated "Coming Soon" in Settings until v2)
- Full admin UI overhaul: Dashboard, Orders, Order Detail, Reservations, Reservation Editor, Stall & RV Charts, Entries/Divisions, Sheets & Results, Venues, Producers, Events, Notifications, Reports, Settings, Customers, Daily Movement — all ported to new design system
- Customer-facing event page (`[en_reservation]`), confirmation email (Emogrifier-inlined), order receipt PDF (Dompdf)
- Required Documents (admin-defined + upload + Mark Satisfied)
- Additional Shavings (structured add-on type, 2.7.521–527)
- Postmeta → relational de-coupling complete (2.7.311–318): `EEM_Reservation_Config` + `wp_eem_reservation_config` table
- Venue entity + Facility Layout Templates (copy-on-use clone)
- Notifications page (audience builder + batched send + history)
- Entries → Divisions (entrants ledger + spots cap + Division detail page)
- Soft-delete / Trash lifecycle for orders
- Bulk "Send Payment Link" on Orders
- Stall & RV Charts: By Location readiness grid (per-stall-night status), By Customer, move-customer flow, print views
- Daily Movement + print view
- Concurrency hardening: MED-3 (edit-dates lock) + LOW-5 (custom-item double-submit) fixed
- Smoke suite: 154 files, 0 failures

**Known watch-outs (still current):**
- Reservation **5990 RV map is corrupted** — test maps on **NTR 6519** only.
- `$or_num` dead assignment in print By Customer loop — harmless, tidy when convenient.
- RV lot name/number split keys on last space in the label — verified correct on NTR 6519 real data; only breaks on externally-sourced labels not built by this plugin's path.

---

## 📚 Reference documents

- `CLAUDE.md` — authoritative decisions, conventions, chunk history, CSS/JS discipline rules.
- `README.md` — data model, file inventory, conditional visibility rules, naming conventions.
- `docs/decisions.md` — product decisions log (TEC integration, refunds, cancellation policy, etc.).
- `docs/BRAND_GUIDE.md` — color tokens, typography scale, component specs.
- `docs/ARCHITECTURE-VENUES.md` — source-agnostic Venue model + resolver + Facility Layout Templates.
- `docs/ARCHITECTURE-DATA-OWNERSHIP.md` — data storage, GH integration models, WordPress-replaceable principle.
- `docs/WORKPLAN-postmeta-decouple.md` — postmeta→relational migration plan + estimate.
- `OVERHAUL_REPORT.md` — before/after of the original Codex-overhaul effort.
