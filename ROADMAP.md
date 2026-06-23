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

---

## 📋 v1 — Pre-launch (remaining)

1. [ ] Global mobile visual polish — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.
2. [ ] Excel stall map import (.xlsx → stall rows + map grid)
3. [ ] Map Builder search bar (search/highlight/scroll for large maps)
4. [ ] Add-On Report (per-day add-on quantities, CSV + PDF)
5. [ ] Dashboard "Upcoming Reservations" chips (should be filled colored pills matching Reservations list)
6. [ ] Full end-to-end customer checkout sweep (needs NTR 6519 fixture page)
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
20. [ ] Postmeta → relational de-coupling (Phase 1 funnel). Full plan: `docs/WORKPLAN-postmeta-decouple.md`.
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
35. [ ] Dev tooling: seeder populates `reservation_id` on seeded orders (replace the stopgap backfill script)

---

## 📋 v2 — Post-launch

1. [ ] GH Draw Outs
2. [ ] QR Code Generator
3. [ ] Push Notifications (PWA browser push)
4. [ ] Accept Deposits (deposit vs balance at checkout)
5. [ ] Global Handicaps API integration (GH as system-of-record). Full write-up: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`.
6. [ ] PWA + responsive/touch (full offline-capable app). Scaffolding shipped (manifest + SW + install prompt, 2.7.580); full version is v2.
7. [ ] Native mobile app (iOS/Android over the same API contract)

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
