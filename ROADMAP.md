# Equine Event Manager — Roadmap & To-Do

---

## 🔖 SESSION HANDOFF — 2026-06-22 (design-system mobile-first pass)

**Current state:** v2.7.577 on `main`. Phase 0 (390px audit) complete — all admin + customer pages screenshotted at desktop/tablet/mobile. One fix shipped: mobile customer cards border-top separators (2.7.577).

**Active work: Design-system + mobile-first pass (item #2 on the master to-do list).**
Daily Movement is the UX reference for ALL viewports. This is the locked execution plan — DO NOT DRIFT:

### Phase 1 — Build shared `.eem-responsive-table` primitive ⟵ YOU ARE HERE
One CSS + tiny render-helper pattern that auto-collapses any `.eem-*-table` into labeled stacked cards below ≤767px (the way Daily Movement does it). Canonical tokens (padding/radius/page-bg) fixed in the same edit. Written ONCE; every list page inherits it.

### Phase 2 — Apply + browser-verify per page (priority order)
Orders → Reservations → Stall & RV Charts → Order Detail → Reports → Settings → customer checkout. Each page gets the table primitive + a browser check at mobile width.

### Phase 3 — Touch + PWA polish
44px tap targets globally via a `:where()` rule, sticky toolbars on list pages, then PWA wrapper (manifest + service worker + install prompt).

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

- [ ] **Global mobile-first pass — make every page as touch-friendly as Daily Movement (Whitney 2026-06-21).** ⟵ MERGED with the page-background, card-padding, and button-radius polish sweeps above (Whitney 2026-06-21): do them together in this one design pass, since both rework the same components — normalize padding/radius/bg in the same edit that adds the mobile reflow. Execution order overall: (1) finish end-to-end customer-checkout sweep, (2) THIS pass, (3) payment-path fixes (sign-off), (4) PWA wrapper, (5) v2/v3. Today pages are *responsive* (don't break/overflow at phone width — the ~2.7.287 overflow-clip pass) but NOT *mobile-first*: only Daily Movement + the customer checkout truly reflow for a phone. Data-dense pages (Orders, Reservations, Stall & RV Charts, Reports, Order Detail) shrink/scroll their wide tables instead of collapsing to cards. Daily Movement is the baseline standard: (1) wide table → stacked labeled cards below ~767px; (2) 44px touch targets; (3) sticky filter/day-summary context; (4) bigger scannable type. **Phased plan:**
  - **Phase 0 — 390px audit.** Browser-drive every admin + customer page at phone width; score each *breaks / scrolls-only / reflows-to-cards*; produce a per-page punch-list.
  - **Phase 1 — shared `.eem-responsive-table` primitive.** One CSS+helper pattern that auto-collapses any `.eem-*-table` into labeled cards under the breakpoint (the way DM does it). Built once; list pages inherit it.
  - **Phase 2 — apply + browser-verify per page** in priority order: Orders → Reservations → Stall & RV Charts → Order Detail → Reports → Settings → customer checkout.
  - **Phase 3 — touch + PWA polish.** 44px tap targets globally via a `:where()` rule, sticky toolbars, then the PWA wrapper (manifest + install) — folds into / supersedes the v2 "PWA + responsive/touch" item (#19).

- [ ] **Page-background color sweep** — some admin pages still show the bare WordPress gray (`#f0f0f1`) below/around the plugin content instead of the plugin's page background. Confirmed visible on **Stall & RV Charts** (gray band fills the area below the reservations table). Audit every admin page and ensure the plugin background color paints the full content area (`#wpcontent` / `#wpbody-content` / `.eem-page` wrapper) edge-to-edge — no WP gray showing through on any page. Likely a missing `body.eem-shell-page` background rule or a `.eem-page` that doesn't stretch to full height.
- [ ] **Global card padding consistency sweep** — audit every admin card (`.eem-card`, `.eem-div-detail-card`, page-body sections, toolbar rows, stat grids) for padding drift; establish a single canonical inner-padding token and normalize all cards to it.
- [x] **Mockup cleanup — RESOLVED 2026-06-21: nothing to delete.** All three suspected files are LIVE: `reservation_overview_page.html` (hidden "View Event" submenu, browser-verified on-brand); `events_admin_page.html` (referenced in `class-eem-events-list-page.php`); `event_entry_editor_page.html` is the mockup for the LIVE **Division editor** (route `equine-event-manager-entry-editor` — UI relabeled "Entries"→"Division" but the slug + mockup filename kept the "entry" name; browser-confirmed in use at `entry_id=13646`). `division_editor_page.html` never existed — the old "superseded by" note was wrong. Do NOT delete any of these.
- [ ] Global control/button radius sweep → 8px (currently base `.eem-btn` = 4px, `input.eem-field-input` = 3px; mockups want 8px). Includes locked primary/secondary/danger button system + dead legacy control-CSS / `!important` strip.
- [ ] Space Grotesk → IBM Plex Sans plugin-wide.
- [ ] **MED-4** — Admin Collect Payment (Auth.net) double-charge window. Non-atomic `already_paid` check vs live charge → double-click can fire two authCaptures. Fix: per-order `GET_LOCK` around read→charge→mark. ⚠️ payment path — Whitney sign-off + live test required.
- [ ] **LOW-3/4** — Minor Stripe confirm no already-paid recheck (no 2nd charge risk) + mark-paid-manual non-atomic duplicate note. Low priority.
- [ ] **Excel stall map import** — let admins upload an Excel (.xlsx) file with their stall layout; plugin reads the cell grid and auto-generates stall rows + map grid. Excel cells map cleanly to the Map Builder's row/column structure (no OCR needed). Avoids manually entering backwards-numbered or complex multi-barn layouts. Whitney flagged 2026-06-22.
- [ ] **Sticky toolbars on list pages** — pin filter/action toolbars below the WP admin bar on scroll. Deferred 2026-06-22: tricky interaction between `overflow-x: auto` on `.eem-list-card` (tablet horizontal scroll), WP admin bar fixed positioning (32px desktop / 46px mobile), and vertical space cost on small screens. Solvable but fiddly; do when convenient.
- [ ] **BUG: Reports filter dropdown clipped** — `.eem-reports-filter-card` inherits `overflow:hidden` from `.eem-card`, clipping the Choices.js reservation search dropdown. Fix: add `overflow:visible` to `.eem-reports-filter-card`. Whitney flagged 2026-06-22.
- [ ] **BUG: Create Order reservation date format** — "Choose Reservation" card shows raw ISO dates (`2026-06-26 – 2026-06-28`) instead of the plugin's canonical `Jun 26 – Jun 28` format. Whitney flagged 2026-06-22.
- [ ] **BUG: Frontend not reflecting backend reservation changes** — customer checkout reads group rider toggles (Grounds Fee, Rider Deposit) and other fields from stale post-meta instead of the config table. Fix written (comprehensive config-table overlay in `get_reservation_meta`), not yet committed. Whitney flagged 2026-06-22.
- [ ] **BUG: Currency input fields not vertically centered** — `$` and `#` prefix/suffix boxes on price inputs misaligned. Fix written (`align-items: center` on all price-wrap containers), not yet committed. Whitney flagged 2026-06-22.
- [ ] **Global Zoom control styling** — unify the `– Zoom +` control across Map Builder, Stall & RV Charts, and customer picker. Use the Charts page version as canonical. Whitney flagged 2026-06-22.
- [ ] **Map Builder search bar** — add a search input to the Map Builder so admins can search for a stall/lot number, highlight it on the grid, and scroll to it. Useful for large 300+ stall maps. Whitney flagged 2026-06-22.
- [ ] **Add-On Report** — new report under Reports showing add-on quantities sold per day of the reservation. Columns: Add-On Name | Day 1 (date) | Day 2 | … | Day N | Total. Data source: order line items matching the reservation's configured add-ons. CSV + PDF export. Per-day breakdown method TBD (currently add-ons are flat qty, not per-day). Whitney flagged 2026-06-22.
- [ ] **Dashboard "Upcoming Reservations" chips** — style doesn't match Reservations list badge style. Should be filled colored pills with Stall/RV/Group labels. Whitney flagged 2026-06-22.
- [ ] **Stall Charts "By List" default for Numbered+Quantity** — when a reservation uses Numbered inventory + Quantity customer selection (no map), "By Location" tab has nothing to render. Disable/hide "By Location" and default to "By List" view. Whitney flagged 2026-06-22.
- [x] Reports — visual verify Customer List + Refund Log render correctly in browser. **DONE 2026-06-21**: both PDF print views render on-brand (branded header, populated tables, footer). Minor data note: Refund Log "Reservation" column blank for order #90011 — likely the per-order reservation_id denormalization gap, not a render bug.
- [~] **Full end-to-end functionality sweep** — FIRST PASS DONE 2026-06-21 (browser page-load + console-error sweep of all admin surfaces + key transactional pages). Results below. NOT yet exhaustive on customer-side checkout (blocked on fixture — see gap).
  - **Clean (no console errors, on-brand, render correctly):** Dashboard, Orders list, Order Detail (#90801), Create Order, Events, Reservations, Stall & RV Charts (page-bg fix confirmed live), Daily Movement, Event Entries/Divisions list, Sheets & Results, Customers, Notifications, Reports (Customer List + Refund Log PDFs), Settings (vertical nav intact), Native Events customer calendar ([en_events]).
  - 🐛 **BUG (payment-path — needs Whitney sign-off): Collect Payment shows "paid in full" for edited-after-payment orders.** `class-eem-collect-payment-page.php` line 128 computes `$total_due` as GROSS order total (never subtracts `amount_paid`); line 137 gates paid/unpaid on `payment_status === 'paid'` alone. When an order is edited (items added) after being paid, status stays `paid` but a real balance exists → Collect Payment says "paid in full," hides the charge form, and the balance is uncollectable from that page. Order #90801: Order Detail correctly shows $102.50 due; Collect Payment wrongly shows $0/paid. **Fix:** compute `$amount_paid = (float)($order['amount_paid'] ?? 0)`, `$balance = max(0, $total_due - $amount_paid)`, gate on `$balance > 0.005` (not status alone), pass `$balance` (not gross) to amount-due + payment cards. Mirror Order Detail's logic.
  - ⚠️ **UX inconsistency:** Order Detail shows a green "Paid" badge while also showing a Balance-Due banner on edited orders. Expected Order-Edit state, but reads contradictory. Consider a "Partially paid" / "Balance due" badge state.
  - ℹ️ Many seed orders show "Unassigned Event" / blank reservation (also seen in Refund Log) — orders not linked to a reservation. Likely the per-order `reservation_id` denormalization gap, or intentional seed data. Verify whether real (non-seed) orders ever land unassigned.
  - **Coverage gap:** customer-facing `[en_reservation]` checkout form not fully exercised — the only fixture pages point at the corrupted Super Sort (5990) or a map test. Need an `[en_reservation id="6519"]` fixture page (NTR 6519, the healthy reservation) to verify customer checkout end-to-end.
  - Smoke suite (`tests/run-all-smokes.php`) ran but is heavily polluted by environmental noise (smokes shell out to bare `php` not on PATH → `env: php: No such file`; seed-data preconditions; mockup MD5 drift). Not a reliable functionality signal as-is — worth a separate cleanup pass to make the runner pass `php` on PATH to the child smokes.

---

## 🔵 Strategic (v2+)

### v2 — Architecture + Features

1. **`en_venue` → canonical `EEM_Venue` unification** — persist the link between `en_venue` post and its `EEM_Venue` row; make the venue editor write through to the relational store.
2. **Postmeta → relational de-coupling** — move reservation/division config out of `wp_postmeta` into relational tables. Phase 1 (funnel) is the recommended first move. Full plan: `docs/WORKPLAN-postmeta-decouple.md`.
3. ~~**Weekly Rate pricing** — third pricing option alongside Nightly and Weekend Rate, for stalls + RVs. Mirror Weekend Rate implementation.~~ ✅ Done
4. ~~**Paddock Assignments** — merge adjacent stall chips into a bookable paddock unit with its own rate.~~ ✅ Done
5. **Upload .xlsx → Stall Grid** — parse `.xlsx` into stall rows via `ZipArchive` + `SimpleXML`; no new Composer deps. Include "Download Example Template" link.
6. **Repo / distribution cleanup** — delete dead dev docs; `.gitattributes export-ignore` so shipped plugin ZIP carries only runtime code.

### v3 — Architecture + Deferred Features

1. **PDF Venue Map → overlay** — upload a PDF venue map; render to image + drop/snap stall hotspots. Pairs with Facility Layout Templates.
2. **Sheets & Results — more source types** — CSV upload, Google Sheets link, external URL. Source-type column on `wp_eem_sheet_entries` + per-type render.
3. **Financial-security audit** — full money path (Stripe + Auth.net charge/refund, amount integrity, idempotency, webhook auth, capability + nonce). Deliverable: `docs/SECURITY-AUDIT-REPORT.md`.
4. **Strict concurrency audit** — every reserve/assign write path under high-demand sellout conditions; document guarantees.

### v4 — Headless Clients *(gated on v2 decouple + v3 API)*

1. **PWA + responsive/touch** — responsive/touch audit on all admin + customer pages (tablet ~768px, phone ~390px); offline-capable, installable web app (`manifest.json` + service worker + install prompt) over the v3 API.
2. **Native mobile app** — iOS/Android over the same API contract.

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
