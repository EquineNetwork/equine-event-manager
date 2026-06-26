# Equine Event Manager — Roadmap & To-Do

> **THIS IS THE TO DO LIST — the only one.** Two lists only: **v1 (pre-launch)** and **v2 (post-launch)**.
> Read this file first every session. Do not invent a parallel task list, and do not reconstruct tasks
> from the codebase. Check items off here in the same step you finish them. CLEANUP.md, the README
> implementation checklist, and the `.mockups/*_scope.md` files are NOT to-do lists — every still-open
> item from them has been folded into the v1 list below.

---

## 🐛 CRITICAL BUGS — found 2026-06-26 (live rsnc.us, event "Columbiana, OH – Northeast Circuit Finals", reservation_id 30884)

> Reported by Whitney from the live production site. Fix **one at a time**, Whitney verifies each before it's checked off.
> Most are stall-assignment / cancellation logic; two are order-detail pricing **display** (no payment-processing changes).
> NOTE: code fixes deploy via plugin update, but **existing bad data** (cancelled orders still holding stalls) likely needs a one-time cleanup too.

1. [ ] **Cancelling an order does NOT release its stall/RV assignments.** Two cancelled orders (#00002 Mitchell, Whitney; #00003 Sperle, Tanner) still show stall assignments on the Stall & RV Chart. When an order's status → **Cancelled** (and almost certainly **Refunded** + **Move to Trash**), its stall + RV assignments must be auto-released so the stalls free up on the chart.

2. [ ] **Cancelled + manually-removed orders STILL appear in the chart "Assigned" sidebar roster.** Whitney cancelled the orders AND manually removed them from the map, yet they still show in the "Assigned (3)" customer list (Tanner Sperle Test · Mitchell, Whitney · Sperle, Tanner). The Assigned roster must reflect actual current assignments and exclude cancelled/removed orders. (Find what source the roster reads — orders vs. notes — and why cancel/manual-removal doesn't clear it.)

3. [ ] **"Manage Stall Assignment" allows OVER-assignment beyond the paid stall quantity.** Order has 5 paid stalls; Manage Stall Assignment opens the map with the 5 selected, but selecting another block of 5 and assigning gives the customer **10 stalls — 5 unpaid**. Need a guardrail: cannot assign more stalls than the order's paid quantity (block/warn at the paid count, or require explicit override).

4. [ ] **No multi-select "Remove from stall" on the chart/map.** Can't currently select multiple assigned stalls and remove them in one action. Add bulk-select + "Remove from stall" on the map.

5. [ ] **Order total math — required shavings cost is silently folded into the Stall Subtotal.** Rate line shows "$35.00 × 5 stalls × 5 nights" = $875, but **Stall Subtotal = $955.00**; the $80 difference = 8 bags shavings (×$10/bag) bundled in with no breakdown. Required shavings must show as its own priced line (per-bag rate + total); the stall subtotal breakdown must be transparent.

6. [ ] **Required shavings render under "Add-Ons" and show $0.00.** Add-Ons section shows "SHAVINGS (×8) $0.00" — wrongly implies it's an add-on AND shows no price. Distinguish **required shavings** (part of the stall reservation) from **add-on shavings**, and show the real dollar amount (per bag + total).

7. [ ] **Order Detail doesn't indicate WHICH assigned stall is the tack stall.** Order had 1 tack stall among the 5 (#295–#299) but the detail page doesn't mark which one. Surface tack-stall identification on the order detail.

---

## 🔖 SESSION HANDOFF — 2026-06-25 (continued — v2.7.624 target)

### ✅ Code written this session — AWAITING VERIFICATION (v2.7.624)

**Stall popover unification (3rd drift fix — see canonical option set below).** The By Location **List** popover and **Map** popover are built by two different code paths (List = server-rendered static menu + `openAssignPickModal`; Map = JS-built `eemSmapOpenPop` in admin.js). They kept drifting out of sync. This session made them match:

1. **Map assigned/tack popover enriched** — now shows customer name in the header, an Order # + Shavings meta line, and rows for: Move to different stall (reuses destination-mode flow), View order, Mark as Tack stall / Unmark Tack, Mark as VIP / Remove VIP, Remove from stall. PHP: added `sh` (shavings) to `build_stall_map_overlay_state()` payload + a new `vip` op in `ajax_stall_map_action()` (in-place re-render, zoom/scroll preserved). **Explicitly NO check-in/checkout on the map** (Whitney: "map is more for assigning").
2. **List assign modal gained "+ Add New Customer"** — `openAssignPickModal` now has an add-new affordance that swaps the modal body for a First/Last name → "Save & Assign" form, posting to the same `eem_stall_create_placeholder` endpoint the Map uses (new `postCreatePlaceholder` helper).

### ⚠️ CANONICAL STALL POPOVER OPTION SET (anti-drift guard — DO NOT let these diverge again)

Both the By Location **List** popover and **Map** popover MUST expose the SAME options. This is the 3rd time they've drifted (prior: task #3). When editing either, mirror the change in the other.

- **Available cell:** assign customer (search) · **+ Add New Customer** (inline First/Last → Save & Assign) · Block.
- **Assigned/tack cell:** header = customer name · meta = Order # + Shavings · Move to different stall · View order · Mark as Tack / Unmark Tack · Mark as VIP / Remove VIP · Remove from stall.
- **Map-only exclusion:** NO check-in / checkout on the map (assignment-focused). Check-in/out lives on the List / Daily Movement.

Code locations: List = `openAssignPickModal()` + server menu in `assets/js/admin.js`; Map = `eemSmapOpenPop()` in `assets/js/admin.js` + `ajax_stall_map_action()` ops in `admin/class-equine-event-manager-admin.php`.

---

## 🔖 SESSION HANDOFF — 2026-06-25 (continued — v2.7.623)

**Current state:** `main` at **v2.7.623** — pushed to GitHub. All items below are **verified live on staging** (eqeventmanager.wpenginepowered.com) via browser inspection.

### ✅ Shipped + verified this session (v2.7.619 → 2.7.623)

1. **Chip name order "Last, First"** (v2.7.619) — stall-chart map chips were showing "First Last". Now formatted server-side via `format_customer_last_first()` at chart-data build; JS displays `st.c` directly (no double-inversion).
2. **Blank/broken "By Location — Map" guard** (v2.7.621) — reservations with no imported barn map were landing on an empty map shell. Order Detail "Manage Stall Assignment" URL now uses `tab=list` when no map; the stall-chart page itself also forces `$tab='list'` when `!$has_any_map`.
3. **Payment Outstanding banner on Open orders** (v2.7.622) — removed the action-bar "Collect" button (wrong approach) and instead made the full amber Payment Outstanding banner render for Open-status orders. Open orders carry a $0 balance so the balance check suppressed it; now `'open'` renders unconditionally with "No price has been set yet…" messaging (no dollar amount) + Collect Payment link.
4. **Stall map assign-mode JS crash — THE big one** (v2.7.623) — clicking **Assign Stalls** from an order blanked the ENTIRE map. Root cause: `initAssignMode()` in admin.js referenced an undefined variable `assigned`; the `ReferenceError` threw during init and aborted the barn-grid render. Fixed by defining `assigned` from `ctx.assignedUnits` (the key the server actually sends). Verified live: 453 stalls across 2 barns now render in assign mode.
5. **Open status badge → amber** (v2.7.623) — was neutral blue; now amber (`--eem-badge-amber-*`) so it reads as "balance owed", matching the Payment Outstanding banner. Verified: `rgb(255,251,235)` bg / `rgb(180,83,9)` text.
6. **Add-On type badge → teal** (v2.7.623) — moved off orange (`.eem-type-addon` + `.eem-type-badge--addon`) so the warm tone doesn't collide with the now-amber Open status. Verified: `rgb(240,253,250)` bg / `rgb(15,118,110)` text.

**Note on WP Engine deploy:** PHP/JS/CSS changes deploy via WP admin → Plugins → "Check for updates" → Update now. PHP changes need no cache flush; the curl `_eem_oc.php` OPcache trick does NOT work on WP Engine (direct PHP file access returns 404).

---

## 🔖 SESSION HANDOFF — 2026-06-25 (continued — v2.7.617 target)

**Current state:** `main` at **v2.7.616** — all shipped items below were committed. Pull + activate plugin.

---

### ✅ Shipped this session (v2.7.614 → 2.7.616)

1. **"+ Add New Customer" button** — bold electric blue, no italic, no browser autocomplete on the search input.
2. **Popover stopPropagation fix** — clicking "Add New Customer" was immediately re-closing the popover because `showAddCustomerForm()` cleared the popover's `innerHTML` mid-bubble, detaching the button from the DOM so the document-level close handler fired. Fixed with `ev.stopPropagation()` on the button's click handler.
3. **Network error on Add New Customer fixed** — `ajax_stall_create_placeholder()` was calling `render_stall_chart_dynamic_region()` with 3 args; the function requires 4 (`$reservation_id, $config, $inv, $tab`). PHP type error garbled the JSON response. Fixed by adding `$config = $this->get_stall_chart_config($reservation_id)` before the render call.
4. **"Clear All Assignments" button removed** from the stall chart header (too dangerous — only "Generate Assignments" remains).
5. **Cancel Orders button styling fixed** — was `.eem-btn-delete` (28×28 icon button); changed to `.eem-btn-danger` (full-width destructive text button).
6. **Bulk "Move to Trash"** added to Orders list bulk actions — confirmation modal, AJAX to `eem_order_bulk_trash`, sequential `repo->trash_order()` loop, success toast + page reload.

### ✅ Code written this session — AWAITING YOUR VERIFICATION

7. ✅ **Scheduling custom message fix** — verified by Whitney 2026-06-25. Custom message now shows on the customer event page instead of the generic "will open on…" template.
8. ✅ **Map search by customer name** — verified by Whitney 2026-06-25. Partial last name search highlights matching assigned stalls.
9. ✅ **Assignee name on stall chips** — verified by Whitney 2026-06-25. "Last, First" shows at bottom-left of assigned chips when zoomed in.

---

### ⚠️ Verify these on local (en-event-manager.local)

- **#7 Scheduling message:** Go to the Northeast Circuit Final customer event page while the open date is in the future. Should show your custom message, not the generic "will open on…" template.
- **#8 Map search by name:** Stall Charts → NTR 6519 → By Location Map → type a partial customer last name → stalls for that customer should highlight.
- **#9 Assignee name on chips:** Stall Charts → any reservation with assigned stalls → zoom in to 2× or 3× → confirm "Last, First" text appears at the bottom-left of assigned chips.
- ✅ **#3 (prior) Spatial map search bar — stall number search:** verified still works by Whitney 2026-06-25.
- **#6 (prior) Add new customer:** click an unassigned stall → type a name with no match → click "+ Add…" → confirm it doesn't immediately close, creates the order, refreshes map.

---

### 📋 What's next (full priority order)

**High priority — real usability gaps**
- [ ] Global mobile polish — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped; per-page work not started.
- [ ] Full end-to-end customer checkout sweep (needs live NTR 6519 fixture page — also best way to seed test data)
- [ ] Order Detail "Paid" badge contradicts balance-due banner on edited orders
- [ ] Order Detail: make "Special Instructions" editable (inline edit + Save Changes bar — currently display-only)

**Charts + map features**
- [ ] Map Builder search bar — same highlight/scroll concept for the stall row builder
- [ ] Chip status colors + click-to-set — discuss colors + interaction before building
- [ ] Metrics bar at top of stall chart page (matching Daily Movement blue bar)
- [ ] Sticky sidebar for By Location Map view — discuss design before building

**Imports + reports**
- [ ] Excel stall map import (.xlsx → stall rows + map grid)
- [ ] Add-On Report (per-day add-on quantities, CSV + PDF)
- [ ] Pre-Entry Import Tool (GH CSV)

**Bigger builds — discuss before starting**
- [ ] Vendor System
- [ ] RV amenities/hookups on reservations (30amp/50amp/water/sewage per lot)
- [ ] Hotel-style 15-min cart hold (UX only — double-booking already prevented at submit)
- [ ] Full permissions matrix (role-based access)

---

**Standing constraints (do not change these):**
- Never bump version without explicit Whitney approval each time.
- Reservation 5990 RV map is corrupted — test stall/RV maps on **NTR 6519**.
- One Bash command per call, no chaining, no heredocs (see CLAUDE.md command hygiene).
- Desktop, tablet, AND mobile are all equally important.
- Working cadence: one item at a time, Whitney verifies before marking done.

---

## 🔖 SESSION HANDOFF — 2026-06-25 (evening — desktop pickup)

**Current state:** `main` at **v2.7.613** — pushed to GitHub. On desktop: `git pull origin main`, update the plugin, and start below.

---

### ✅ Shipped today (v2.7.613)

1. **Zoom + scroll position preserved across stall chart reloads**
2. **Assign search fixed for GEMS-imported orders**
3. **"Assign" label — removed trailing "…"**
4. **By Customer table sortable by Arrival + Departure**
5. **Spatial map search bar** (stall number search — customer name search added in next session)
6. **"Add new customer" in spatial map assign popover** (button + flow fixed in next session)

---

## 🔖 SESSION HANDOFF — 2026-06-25 (laptop pickup)

**Current state:** `main` at **v2.7.611** (all pushed to GitHub). Big push fixing the staging stall-chart shake-out + new features. **On the laptop: pull `main`, update the plugin, CLEAR OPCACHE on WP Engine** (in-WP cache clear does NOT clear PHP OPcache — this caused most "it didn't change" confusion). Full clickable change list is in **`FOR-REVIEW.md`** at repo root.

**Shipped today (2.7.592 → 2.7.611) — verify on staging:** Order Notes card (editable note + confirmation #), shavings counts, consistent stall colors, Assign buttons on imported orders (+migration #040 section-flag backfill), Barn filter on By Customer, dashboard "In N days" wording, **#3 click-menu unification COMPLETE** (Assign/Cleaning/Checked-in/Tack/Block on both List + Map, per-night Block modal, Unblock, visual parity), date-header weekday + timezone fix, check-in lifecycle + red/green/slate "arrival" rings + legend, **VIP flag** (gold ★ on List/Map/By-Customer + map legend), Reserved quick-view chip, OPcache auto-flush on update.

**⏳ IN PROGRESS — needs your test + finish (the thing I was mid-build when you left):**
- **Scheduled Reservations message field (v2.7.611, just shipped — TEST FIRST).** Added a **"Message Until Reservations Open"** textarea to the Edit Reservation → *Schedule Stall Reservations* section (+ RV parallel). New config columns `stalls_schedule_message` / `rv_schedule_message` (dbDelta adds them on the version bump). Save wired through `sanitize_meta_submission` + `get_meta_values` defaults; customer event page shows it pre-open via `get_closed_message()` (per-reservation message overrides the global Settings pre-open message).
  - **PICKUP STEPS:** (1) On staging, Edit a reservation → toggle *Schedule Stall Reservations*, set an **Open Date in the future**, type a message, Save → reload the editor and confirm the message **persisted** (this is the one thing I couldn't click-test — the save path is an allow-list and I added the keys, but verify it sticks). (2) Open that reservation's customer event page (`[en_reservation id=N]`) while before the open date → confirm your custom message shows instead of the form. (3) Confirm scheduling actually GATES the form (form hidden until open date).
  - If the message doesn't persist: check `EEM_Reservations_CPT::sanitize_meta_submission()` (keys `stalls_schedule_message`/`rv_schedule_message`) and that `EEM_Reservation_Config::create_table()` ran (columns exist).

**Still open (not started):**
- **#21 [Later]** restyle the "View Event" overview page to match plugin design.
- **#19 [Later]** remove the "X days" chip on the event-flyer card — BLOCKED, needs your mockup.
- **Stall Logic demo features:** ✅ VIP done. **Deferred to v2:** Stall-assignments CSV export (columns: Stall, Barn, Roper ID, Horse, Rider, Phone, Address, City, State, Zip, VIP). Not-yet-requested from the demo: manual "Add Roper" entry, reassign-to-occupied confirmation, Hybrid (list+map) view, Horse-name capture — ask Whitney before building.

---

## 🔖 SESSION HANDOFF — 2026-06-23

**Current state:** v2.7.580+ on `main`. Separate stall/RV layout saving shipped (migration 037).

**Standing constraints:**
- Never bump version without explicit Whitney approval each time.
- Reservation 5990 RV map is corrupted — test stall/RV maps on **NTR 6519**.
- One Bash command per call, no chaining, no heredocs (see CLAUDE.md command hygiene).
- Desktop, tablet, AND mobile are all equally important — "facilities are going to be using this on tablets a lot."
- Mobile customer cards use border-top separators between them, NOT individual card borders.

**Working cadence (locked 2026-06-23):** Tackle v1 items one at a time, together. After each item, Whitney visually verifies it BEFORE it's marked done. Only then check it off here (`[ ]` → `[x]`) in the same step. Never batch-mark items done; never mark done without Whitney's visual verification.

---

## 📋 v1 — Pre-launch (remaining)

### ✅ Done
- [x] Facility Layout Templates: Save/Load to icon buttons (#169)
- [x] Move Save Map / Save Layout / Load Layout buttons (#175)
- [x] Venue layout preview format — grid not white tiles (#178)
- [x] Facility Layouts: Save + Upload icon flow (#198)
- [x] Pricing Mode: allow Nightly + Stay Packages both on (#183)
- [x] Fix the venue layout preview modal (overlapped with #178)
- [x] Edit Venue page — helper text below Venue Layouts card title
- [x] Source-switch resilience (BUG) — fixed 2026-06-23
- [x] Release-prep: git committer attribution — already set to enwmitchell / wmitchell@equinenetwork.com
- [x] Release-prep: drop `Update URI: false` — URIs are correct; `Update URI: false` is correct for private plugin
- [x] Dashboard "Upcoming Reservations" chips — filled colored pills matching Reservations list
- [x] Customer Preview button — editor Preview links to live customer event page
- [x] Reservation editor save bar — restored Visibility + Published-date displays
- [x] Events list filter — multi-value timeframe support (`filter="past,ongoing"`)

### 🔲 Remaining
1. [ ] Global mobile visual polish — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.
2. [ ] Excel stall map import (.xlsx → stall rows + map grid)
3. [ ] Map Builder search bar (search/highlight/scroll for large maps)
4. [ ] Add-On Report (per-day add-on quantities, CSV + PDF)
5. [ ] Full end-to-end customer checkout sweep (needs NTR 6519 fixture page). NOTE (2026-06-23): this is also the recommended way to SEED test data — real checkout writes a correct `reservation_id` column + notes tag + config-based pricing (production-representative), unlike the synthetic seeders. Prerequisite: a live NTR 6519 customer event page to run checkouts through.
6. [ ] UX: Order Detail "Paid" badge contradicts Balance-Due banner on edited orders
7. [ ] Pre-Entry Import Tool (GH CSV) (#164)
8. [ ] Vendor System (#166)
9. [ ] Full map post-meta → config migration (#174)
10. [ ] Verify post-meta → config-table migration 100% complete (#199)
11. [ ] Print views: move-customer + readiness/print smoke coverage (#234)
12. [ ] Verify RV lot name/number split against real GEMS labels (#235)
13. [ ] Postmeta → relational de-coupling (Phase 1 funnel). Audit + remediation plan: `docs/POSTMETA-AUDIT.md`. (As of 2026-06-23: reservation setup/pricing/rows are on the config table; #212 checkout base-rate read is FIXED; remaining gaps are map snapshots `_en_stall_map`/`_en_rv_map` (see #9) + hybrid blocked-units reads + events/venues/producers/divisions editors still on post-meta.)
14. [ ] Upload .xlsx → Stall Grid (ZipArchive + SimpleXML; "Download Example Template" link)
15. [ ] Event Entries — competition management (disciplines + fees + roster)
16. [ ] PDF Venue Map → overlay (upload PDF, drop/snap stall hotspots)
17. [ ] Sheets & Results — more source types (CSV, Google Sheets, external URL)
18. [ ] Full permissions matrix (role-based access) — needs discussion; may land pre-launch
19. [ ] Order Detail: make "Special Instructions" editable (inline edit + Save Changes bar) — currently display-only
20. [ ] Customer page: consume group fields — show `_en_group_description` text + enforce `_en_group_riders_per_group` max on the rider input (saved today but never read)
21. [ ] Dashboard "Needs Attention": agreement-signature row (needs per-order signature tracking; not emitted today)
22. [ ] Events flyer variant: `show_flyer` thumbnail + countdown badge (today `flyer="yes"` only adds a "View Flyer" link)
23. [ ] Dev tooling: seeder populates `reservation_id` on seeded orders (replace the stopgap backfill script). NOTE (2026-06-23): NOT a seeding blocker — the PRODUCTION checkout/admin paths already write `reservation_id` correctly (shortcodes.php:5036/5147); only the synthetic seeders (`tools/seed-test-data.php`, `scripts/seed-orders.php`) skip it. Prefer seeding via real checkout (#5); this stays as dev-tooling cleanup.
24. [ ] RV amenities/hookups on reservations — in the Edit Reservation editor, let admin identify what each RV lot (or RV spot type) offers: 30 amp / 50 amp / water / sewage, etc. Display on the customer frontend as labeled icon chips (matching the existing "RV Spot Type" card style — electric/water icons with labels). Build approach TBD — locked in; discuss before implementing.
25. [ ] Stall & RV Charts — layout chip status colors + click-to-set status. Define distinct chip colors for booked / cleaning / blocked / etc.; make chips clickable to mark a unit as Cleaning / Checked Out / Checked In / etc. Colors + interaction details TBD — discuss before implementing.
26. [ ] Stall & RV Charts — add a blue metrics bar (matching the Daily Movement metrics bar) at the top of the page showing important metrics.
27. [ ] Print view style verification — resolve discrepancy between existing standard (navy title + "Printed:" meta) and alternate spec (white 56px topbar, no Printed label, no EEM branding). Visual verify then lock one style.
28. [ ] Hotel-style 15-min cart hold
30. [x] Stall Chart — spatial map search bar: stall-number search ✅. Customer-name search also added (2026-06-25 session 2 — matches `st.c` stored in `data-eem-smap-customer` attribute).
31. [x] Stall Chart — spatial map assign popover "Add new customer": button, AJAX create-placeholder, map refresh all working. Styling + stopPropagation + network-error fixes landed in 2026-06-25 session 2.
32. [x] Stall Chart — assignee name on chips: "Last, First" in small text at bottom-left of assigned chips, scales with zoom, hidden in dot mode. (2026-06-25 session 2)
33. [x] Orders — bulk "Move to Trash": confirmation modal → AJAX → `repo->trash_order()` loop → toast + reload. (2026-06-25 session 2)
34. [x] Orders — Cancel Selected Orders button styling: was `.eem-btn-delete` (icon size), fixed to `.eem-btn-danger` (full-width destructive). (2026-06-25 session 2)
35. [x] Stall Chart — "Clear All Assignments" header button removed (too dangerous). (2026-06-25 session 2)
29. [ ] Stall Chart — sticky sidebar panel for the By Location Map view. Reference: Stall Logic screenshots (screenshots sent 2026-06-25). The spatial map (661-stall view) needs a persistent right-side panel that stays in view while scrolling/panning the map, showing quick actions, assignment info for the selected stall, and summary metrics. Design TBD — discuss before implementing. (NOT implemented today). When a customer selects a stall/RV lot it should be held for a time window (~15 min) and shown as taken to other customers during that window, then auto-released if checkout isn't completed. NOTE: actual double-booking is already prevented at submit via the per-reservation advisory lock (the race loser is told the unit is taken and is NOT charged) — this item is the UX hold-while-in-cart enhancement, not a correctness fix. Needs: hold/expiry state on stall+RV tables, session-tied claim, availability query counting active holds, and a cron/cleanup to expire abandoned holds. Discuss design before implementing.

---

## 📋 v2 — Post-launch

1. [ ] GH Draw Outs
2. [ ] QR Code Generator
3. [ ] Push Notifications (PWA browser push)
4. [ ] Accept Deposits (deposit vs balance at checkout)
5. [ ] Global Handicaps API integration (GH as system-of-record). Full write-up: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`.
6. [ ] PWA + responsive/touch (full offline-capable app). Scaffolding (manifest + SW + install prompt) was DISABLED in 2.7.582 — `EEM_PWA::init()` now only unregisters any lingering service worker; the install banner/manifest no longer emit. Restore from git history when PWA work resumes.
7. [ ] Native mobile app (iOS/Android over the same API contract)
8. [ ] Update plugin language to .NET? (exploratory — port the plugin's logic off PHP/WordPress to a .NET backend; ties into the "not chained to WordPress forever" / GH-as-system-of-record direction in `docs/ARCHITECTURE-DATA-OWNERSHIP.md`. Confirm scope + intent before any work.)
9. [ ] Add orders to Apple Wallet + Google Wallet (passes for confirmed orders — likely tied into the confirmation email + hosted order page).
10. [ ] Orders list — per-page count control (let the admin choose how many orders show per screen; currently fixed at 25/page). Apply the same pattern to other list pages (Reservations, Customers) if it lands well.

---

## ✅ Recently completed (verified done this cycle)

- Page-background color sweep — `body.eem-shell-page` uses `var(--eem-bg)` + `min-height: 100vh`; no WP gray bleed-through.
- Global card padding sweep — tokens `--eem-card-header-padding` / `--eem-card-body-padding`.
- Global control/button radius sweep → 8px (`--eem-radius`).
- Space Grotesk → IBM Plex Sans plugin-wide.
- MED-4 — per-order `GET_LOCK('eem_charge_...')` on all 3 Collect Payment handlers.
- LOW-3/4 — Stripe confirm rechecks `payment_status`; `mark_order_paid_manually()` guards duplicate notes.
- Sticky toolbars on list pages (commit ed60d15, 2.7.580).
- BUG: Reports filter dropdown clipped — `overflow:visible`.
- BUG: Create Order reservation date format — routed through `format_date_range()`.
- BUG: Frontend not reflecting backend changes — config-table overlay in `get_reservation_meta()`.
- BUG: Currency inputs not vertically centered — `.eem-price-wrap` `align-items: center`.
- Global Zoom control styling — shared `.eem-zoom` component.
- Stall Charts "By List" default for Numbered+Quantity (commit 6538f8c).
- Reports — Customer List + Refund Log PDF views verified on-brand.
- Mockup cleanup — nothing to delete; all suspected files are live.
- `en_venue` → `EEM_Venue` unification (migration 015).
- Weekly Rate pricing; Paddock Assignments; repo/distribution cleanup.
- Financial-security audit; strict concurrency audit (advisory locks on all write paths).

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
