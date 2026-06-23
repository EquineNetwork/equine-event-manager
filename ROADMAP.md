# Equine Event Manager — Roadmap & To-Do

> **THIS IS THE TO DO LIST — the only one.** Two lists only: **v1 (pre-launch)** and **v2 (post-launch)**.
> Read this file first every session. Do not invent a parallel task list, and do not reconstruct tasks
> from the codebase. Check items off here in the same step you finish them. CLEANUP.md, the README
> implementation checklist, and the `.mockups/*_scope.md` files are NOT to-do lists — every still-open
> item from them has been folded into the v1 list below.

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

### 🔍 For Review (awaiting Whitney's visual verification)
> Completed in a remote session on the `claude/plugin-github-deployment-7m9ntj` branch. Code-complete + lint-clean + smoke written; NOT yet visually verified or merged to `main`. Walk through each, verify on Local, then check off and move to Done.

- [ ] **Order Detail "Special Instructions" editable** (was #19). Inline editor on the Order Detail full-width card: an **Edit** button reveals a textarea + **Save Changes / Cancel** bar; saves via the new `eem_order_save_special_instructions` AJAX endpoint (capability + per-reservation nonce gated) back to the reservation's `_en_special_instructions` meta, updates in place + toast. Newlines now render as `<br>`. Note: the text is reservation-level (no other consumer in the codebase), so an edit shows on every order for that reservation. Smoke: `tests/smoke/order-special-instructions-smoke.php`.
- [ ] **Add-On Report** (was #4). New **Add-Ons** report in EEM → Reports (CSV + PDF + ZIP, via the existing generic exporter). *All reservations* → a per-add-on summary (Add-On / Orders / Total Quantity, sorted by quantity). *Single reservation* → a **per-day worksheet**: one row per event day, one column per add-on type, cells = quantity needed that day (orders whose stall stay covers the day × their add-on qty), with a pinned TOTALS row. Add-ons parsed from the order-notes `Add-On: <name> \| Qty: N` lines the checkout writes. **Per-day semantics decision to confirm:** an add-on's quantity is spread across every day of the order's stall stay (the same model as the Shavings worksheet — correct for consumables like hay/bedding); stand-alone add-on purchases with no dated stay are listed in a separate "Add-ons without a dated stay" note section rather than dropped. Smoke: `tests/smoke/add-ons-report-smoke.php` (summary + per-day math + CSV/PDF export). **Verify against a reservation that sold real add-ons; confirm the per-day spread matches how you think about add-on fulfillment.**
- [ ] **Order Detail "Paid" badge vs Balance-Due banner** (was #6). Investigation found the contradiction was **already largely resolved** in shipped code (header badge override + balance-driven banner + balance-driven Order Summary all agree). This session made it **verifiable + regression-guarded**: extracted the badge override into a discrete, unit-tested `compute_display_status()` helper; fixed a stale docstring that still claimed the banner is "silently absent when Paid"; added a smoke asserting all three surfaces agree on edited-up vs fully-paid orders. **Behavior unchanged** — verify on an order that was paid then had a line item added (badge, banner, and summary should all say Balance Due). Smoke: `tests/smoke/order-paid-badge-consistency-smoke.php`.
- [ ] **Customer page consumes the group fields** (was #20). The customer event page now reads the two group fields the editor was already saving: (a) shows the admin-authored **Group Description** as a styled blurb in the Group Reservation section; (b) enforces **Riders Per Group** max on the rider-count input — `max` attribute + stepper clamp + a "Maximum N riders per reservation." note + **server-side validation** rejecting over-max submissions (blank/0 = unlimited). Smoke: `tests/smoke/group-riders-max-smoke.php` (real `validate_submission()` invoked, 4 cases incl. singular/plural). **Verify on a group-enabled reservation — NTR 6519 or a group fixture.**

- [ ] **Seeders populate `reservation_id`** (was #23). Both synthetic seeders now stamp a real reservation post id onto seeded order rows so they JOIN to a reservation like production checkout rows do. `tools/seed-test-data.php` uses the discovered `$target['id']` (the reservation post it's already seeding against) on both the stall + RV inserts; `scripts/seed-orders.php` discovers the most-recent `en_reservation` post and stamps it (falls back to 0 when none exists). Makes `scripts/dev-backfill-seed-reservation-ids.php` redundant for fresh seeds. **Verify on Local:** re-run a seeder, then confirm seeded orders carry a non-NULL `reservation_id` (and the Order Detail "Edit Reservation" link resolves).

### 🔲 Remaining
1. [ ] Global mobile visual polish — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.
2. [ ] Excel stall map import (.xlsx → stall rows + map grid)
3. [ ] Map Builder search bar (search/highlight/scroll for large maps)
4. ➡️ _Moved to **For Review** (done this session — new Add-Ons report, summary + per-day worksheet, CSV/PDF)._
5. [ ] Full end-to-end customer checkout sweep (needs NTR 6519 fixture page). NOTE (2026-06-23): this is also the recommended way to SEED test data — real checkout writes a correct `reservation_id` column + notes tag + config-based pricing (production-representative), unlike the synthetic seeders. Prerequisite: a live NTR 6519 customer event page to run checkouts through.
6. ➡️ _Moved to **For Review** (done this session — already resolved in shipped code; made testable + regression-guarded)._
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
19. ➡️ _Moved to **For Review** (done this session — Order Detail Special Instructions inline editor)._
20. ➡️ _Moved to **For Review** (done this session — customer page consumes group description + riders-per-group max)._
21. [ ] Dashboard "Needs Attention": agreement-signature row (needs per-order signature tracking; not emitted today)
22. [ ] Events flyer variant: `show_flyer` thumbnail + countdown badge (today `flyer="yes"` only adds a "View Flyer" link)
23. ➡️ _Moved to **For Review** (done this session — both seeders now stamp `reservation_id`)._
24. [ ] RV amenities/hookups on reservations — in the Edit Reservation editor, let admin identify what each RV lot (or RV spot type) offers: 30 amp / 50 amp / water / sewage, etc. Display on the customer frontend as labeled icon chips (matching the existing "RV Spot Type" card style — electric/water icons with labels). Build approach TBD — locked in; discuss before implementing.
25. [ ] Stall & RV Charts — layout chip status colors + click-to-set status. Define distinct chip colors for booked / cleaning / blocked / etc.; make chips clickable to mark a unit as Cleaning / Checked Out / Checked In / etc. Colors + interaction details TBD — discuss before implementing.
26. [ ] Stall & RV Charts — add a blue metrics bar (matching the Daily Movement metrics bar) at the top of the page showing important metrics.
27. [ ] Hotel-style 15-min cart hold (NOT implemented today). When a customer selects a stall/RV lot it should be held for a time window (~15 min) and shown as taken to other customers during that window, then auto-released if checkout isn't completed. NOTE: actual double-booking is already prevented at submit via the per-reservation advisory lock (the race loser is told the unit is taken and is NOT charged) — this item is the UX hold-while-in-cart enhancement, not a correctness fix. Needs: hold/expiry state on stall+RV tables, session-tied claim, availability query counting active holds, and a cron/cleanup to expire abandoned holds. Discuss design before implementing.

---

## 📋 v2 — Post-launch

1. [ ] GH Draw Outs
2. [ ] QR Code Generator
3. [ ] Push Notifications (PWA browser push)
4. [ ] Accept Deposits (deposit vs balance at checkout)
5. [ ] Global Handicaps API integration (GH as system-of-record). Full write-up: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`.
6. [ ] PWA + responsive/touch (full offline-capable app). Scaffolding shipped (manifest + SW + install prompt, 2.7.580); full version is v2.
7. [ ] Native mobile app (iOS/Android over the same API contract)
8. [ ] Update plugin language to .NET? (exploratory — port the plugin's logic off PHP/WordPress to a .NET backend; ties into the "not chained to WordPress forever" / GH-as-system-of-record direction in `docs/ARCHITECTURE-DATA-OWNERSHIP.md`. Confirm scope + intent before any work.)
9. [ ] Add orders to Apple Wallet + Google Wallet (passes for confirmed orders — likely tied into the confirmation email + hosted order page).

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
