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

1. [ ] Global mobile visual polish — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.
2. [ ] Excel stall map import (.xlsx → stall rows + map grid)
3. [ ] Map Builder search bar (search/highlight/scroll for large maps)
4. [ ] Add-On Report (per-day add-on quantities, CSV + PDF)
5. [ ] Dashboard "Upcoming Reservations" chips (should be filled colored pills matching Reservations list)
6. [ ] Full end-to-end customer checkout sweep (needs NTR 6519 fixture page). NOTE (2026-06-23): this is also the recommended way to SEED test data — real checkout writes a correct `reservation_id` column + notes tag + config-based pricing (production-representative), unlike the synthetic seeders. Prerequisite: a live NTR 6519 customer event page to run checkouts through.
7. [ ] UX: Order Detail "Paid" badge contradicts Balance-Due banner on edited orders
8. [ ] Pre-Entry Import Tool (GH CSV) (#164)
9. [ ] Vendor System (#166)
10. [ ] Facility Layout Templates: Save/Load to icon buttons (#169)
11. [ ] Full map post-meta → config migration (#174)
12. [ ] Move Save Map / Save Layout / Load Layout buttons (#175)
13. [ ] Cross-source venue merge / alias (#176)
14. [ ] Venue layout preview format — grid not white tiles (#178)
15. [ ] Pricing Mode: allow Nightly + Stay Packages both on (#183)
16. [ ] Facility Layouts: Save + Upload icon flow (#198)
17. [ ] Verify post-meta → config-table migration 100% complete (#199)
18. [ ] Print views: move-customer + readiness/print smoke coverage (#234)
19. [ ] Verify RV lot name/number split against real GEMS labels (#235)
20. [ ] Postmeta → relational de-coupling (Phase 1 funnel). Audit + remediation plan: `docs/POSTMETA-AUDIT.md`. (As of 2026-06-23: reservation setup/pricing/rows are on the config table; #212 checkout base-rate read is FIXED; remaining gaps are map snapshots `_en_stall_map`/`_en_rv_map` (see #11) + hybrid blocked-units reads + events/venues/producers/divisions editors still on post-meta.)
21. [ ] Upload .xlsx → Stall Grid (ZipArchive + SimpleXML; "Download Example Template" link)
22. [ ] Event Entries — competition management (disciplines + fees + roster)
23. [ ] PDF Venue Map → overlay (upload PDF, drop/snap stall hotspots)
24. [ ] Sheets & Results — more source types (CSV, Google Sheets, external URL)
25. [ ] Full permissions matrix (role-based access) — needs discussion; may land pre-launch
26. [ ] Order Detail: make "Special Instructions" editable (inline edit + Save Changes bar) — currently display-only
27. [ ] Customer page: consume group fields — show `_en_group_description` text + enforce `_en_group_riders_per_group` max on the rider input (saved today but never read)
28. [ ] Customer Preview button: wire the editor "Preview" button to the live customer event page URL (currently a disabled stub that 404s)
29. [ ] Reservation editor save bar: restore Visibility + Published-date displays (dropped when the rail card was retired)
30. [ ] Dashboard "Needs Attention": agreement-signature row (needs per-order signature tracking; not emitted today)
31. [ ] Events flyer variant: `show_flyer` thumbnail + countdown badge (today `flyer="yes"` only adds a "View Flyer" link)
32. [ ] Events list filter: support multi-value timeframe (`filter="past,ongoing"`) — currently silently falls back to default
33. [ ] Release-prep: set real git committer attribution (user.name / user.email)
34. [ ] Release-prep: drop `Update URI: false` + audit README for placeholder URLs before any public release
35. [ ] Dev tooling: seeder populates `reservation_id` on seeded orders (replace the stopgap backfill script). NOTE (2026-06-23): NOT a seeding blocker — the PRODUCTION checkout/admin paths already write `reservation_id` correctly (shortcodes.php:5036/5147); only the synthetic seeders (`tools/seed-test-data.php`, `scripts/seed-orders.php`) skip it. Prefer seeding via real checkout (#6); this stays as dev-tooling cleanup.
36. [ ] RV amenities/hookups on reservations — in the Edit Reservation editor, let admin identify what each RV lot (or RV spot type) offers: 30 amp / 50 amp / water / sewage, etc. Display on the customer frontend as labeled icon chips (matching the existing "RV Spot Type" card style — electric/water icons with labels). Build approach TBD — locked in; discuss before implementing.
37. [ ] Stall & RV Charts — layout chip status colors + click-to-set status. Define distinct chip colors for booked / cleaning / blocked / etc.; make chips clickable to mark a unit as Cleaning / Checked Out / Checked In / etc. Colors + interaction details TBD — discuss before implementing.
38. [ ] Stall & RV Charts — add a blue metrics bar (matching the Daily Movement metrics bar) at the top of the page showing important metrics.
39. [ ] Hotel-style 15-min cart hold (NOT implemented today). When a customer selects a stall/RV lot it should be held for a time window (~15 min) and shown as taken to other customers during that window, then auto-released if checkout isn't completed. NOTE: actual double-booking is already prevented at submit via the per-reservation advisory lock (the race loser is told the unit is taken and is NOT charged) — this item is the UX hold-while-in-cart enhancement, not a correctness fix. Needs: hold/expiry state on stall+RV tables, session-tied claim, availability query counting active holds, and a cron/cleanup to expire abandoned holds. Discuss design before implementing.
40. [ ] Fix the venue layout preview modal. (Possibly overlaps with #14 — confirm whether this is the same grid-vs-white-tiles issue or a separate modal bug, and merge if so.)
41. [ ] Edit Venue page — add helper text below the "Venue Layouts" card title (explanatory subtitle under the heading).
42. [ ] **Source-switch resilience (BUG).** Existing events/reservations must render + function on the customer-facing frontend regardless of which event source (Native / TEC / GEMS) is currently enabled in Settings. Each reservation's OWN `event_source` must drive its frontend rendering — never the site's global source-mode setting. **Found 2026-06-23:** a Native-sourced reservation's public page (`/equine-event/{id}/`) shows only the event header and **no booking form**, while feed/GEMS and TEC reservations render the full form. The form itself works (the `[en_reservation]` shortcode renders correctly for the native reservation in isolation) — the native event single-page template just never inlines it. Fix so all three sources render the booking form, and so switching the global source never breaks already-created events/reservations.

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
