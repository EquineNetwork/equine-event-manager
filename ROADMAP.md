# Equine Event Manager — Roadmap & To-Do

> **THIS IS THE TO DO LIST — the only one.** Two lists only: **v1 (pre-launch)** and **v2 (post-launch)**.
> Read this file first every session. Do not invent a parallel task list, and do not reconstruct tasks
> from the codebase. Check items off here in the same step you finish them.

---

## 🔖 SESSION HANDOFF — 2026-06-27

**Current state:** `main` at **v2.7.649**. All PRs (#6 – #9) merged. Branch `claude/festive-heisenberg-muha01` is up to date with main.

**Verified live this session (rsnc.us, "Columbiana, OH – Northeast Circuit Finals"):**
- Critical bugs #1–#7 (stall release on cancel, assigned-roster cleanup, over-assignment guard, bulk-remove from stall, required shavings pricing, tack-stall identification) — all ✅ verified by Whitney
- Hotel-style 15-min in-cart unit hold (#36) — ✅ verified by Whitney ("yay its working!")
- Tack stall amber chip on customer map — ✅ verified by Whitney
- Tack legend swatch added to customer map — ✅ verified by Whitney

**Shipped but NOT yet verified by Whitney:**
- Stall popover icon/style parity (#8) — Map popover now shows same icons/colors as List popover. Whitney needs to click a stall on the spatial map and confirm the popover matches.

**Standing constraints (do not change these):**
- **Never bump version without explicit Whitney approval each time.**
- Reservation **5990 RV map is corrupted** — test stall/RV maps on **NTR 6519** only.
- One Bash command per call, no chaining, no heredocs (see CLAUDE.md command hygiene).
- Desktop, tablet, AND mobile are all equally important.
- Working cadence: one item at a time, Whitney verifies before marking done.

---

## ⚠️ CANONICAL STALL POPOVER OPTION SET (anti-drift guard — DO NOT let these diverge again)

Both the By Location **List** popover and **Map** popover MUST expose the SAME options. This is the 3rd time they've drifted. When editing either, mirror the change in the other.

- **Available cell:** assign customer (search) · **+ Add New Customer** (inline First/Last → Save & Assign) · Block.
- **Assigned/tack cell:** header = customer name · meta = Order # + Group + Shavings · Move to different stall · View order · Mark as Tack / Unmark Tack · Mark as VIP / Remove VIP · Remove from stall.
- **Map-only exclusion:** NO check-in / checkout on the map (assignment-focused). Check-in/out lives on the List / Daily Movement.

Code locations: List = `openAssignPickModal()` + server menu in `assets/js/admin.js`; Map = `eemSmapOpenPop()` in `assets/js/admin.js` + `ajax_stall_map_action()` ops in `admin/class-equine-event-manager-admin.php`.

---

## 📋 v1 — Open items

### Awaiting Whitney verification
- [ ] **#8 — Stall popover icon/style parity.** Map popover shipped with same icons as List popover. Click a stall on the spatial map → confirm popover shows customer name header, Order # + Shavings line, action icons match List.
- [ ] **Group Names feature — VERIFY LATER (not in use yet).** Shipped 2.7.650 + branch follow-ups. Verify when groups are actually used: (1) admin adds names in the editor Group Names table (Description + Riders Per Group removed; Group Names is the only field); (2) customer event page shows the strict-list Group dropdown; (3) assign/change/remove group from the map popover; (4) sidebar Groups filter (shown only when groups enabled); (5) group shows on order detail; (6) **Grounds Fee + Rider Deposit charges show on the customer Order Summary AND on the admin Order Detail** (verify the per-rider amounts actually appear and total correctly). Editor-cleanup commit `1bc0432` is on the branch and NOT yet merged to main / deployed — bump + merge when ready to verify.

### Active (tackle one at a time)

1. [ ] **Global mobile visual polish** — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.

2. [ ] **Add-On Report** — per-day add-on quantities, CSV + PDF.

3. [ ] **Full end-to-end customer checkout sweep** — run a real checkout on the NTR 6519 fixture page. Also the recommended way to seed test data (real checkout writes correct `reservation_id` + notes tag + config-based pricing).

4. [x] **Full map post-meta → config migration** — stall/RV map snapshots now dual-write to the config table + post-meta; reads are config-first with post-meta fallback + lazy backfill. Shipped 2.7.652 — **awaiting Whitney staging spot-check** (load a stall map, save it, confirm the chart renders). Config-table branch couldn't be exercised on the SQLite harness (table_exists uses MySQL-only SHOW TABLES).

5. [ ] **Postmeta → relational de-coupling Phase 1** — remaining gaps: map snapshots, hybrid blocked-units reads, events/venues/producers/divisions editors still on post-meta. Audit plan: `docs/POSTMETA-AUDIT.md`.

6. [x] **Self-test harness: order-totals math validation.** DONE — `tests/smoke/order-totals-math-smoke.php` drives the canonical charging calculator (`calculate_submission_totals`, the source of truth for what's charged) and asserts every line against hand-math: stalls, required shavings (tack excluded), additional shavings, general add-ons, group grounds-fee + deposit, convenience fee (% + flat), tax, total, and a group-off case. 16/16 assertions pass. Harness via `scripts/dev-sqlite-harness.sh`. FOLLOW-UP (optional, lower priority): extend to assert the customer Order Summary (JS) and admin Order Detail (stored line items) match the calculator end-to-end; and prune the ~465 environmental SQLite smoke failures so the suite roll-up is clean. Run smokes with `php -d opcache.enable_cli=0` (CLI OPcache caches edited files otherwise).

7. [ ] **Generate Assignments — keep a single customer's stalls contiguous.** Today auto-assign only seats multi-ORDER *groups* contiguously (`assign_group_contiguous_stalls`); a single order needing 2+ stalls just takes the lowest-numbered available stalls in pool order, so one customer can be split across the barn (e.g. 238 + 250 instead of 238 + 239). Generalize the contiguous-run helper to also run per-order: try to seat each multi-stall order in a consecutive block within one barn, fall back to scattered lowest-first only when no run is large enough. Code in `EEM_Orders_Repository::auto_assign_units_for_reservation` / `assign_group_contiguous_stalls` (`includes/class-equine-event-manager-orders-repository.php`).

8. [ ] **Move Convenience Fee from per-reservation to global Settings → Payments.** Remove the Convenience Fee section from the Edit Reservation editor; add it to Settings → Payments, positioned **above** the Tax Rate block. Becomes a global default (like Tax). Migration: snapshot existing per-reservation convenience-fee config into the global setting (or keep per-reservation override semantics — decide at kickoff, mirror how Tax does per-reservation override). Touches: editor section removal, Settings → Payments UI + save, and `calculate_submission_totals`'s `calculate_convenience_fee` source (read global instead of `$data`). **Charging math — verify totals before/after on the harness (#6).**

9. [ ] **DISPLAY MATH parity — all four surfaces must match (HIGH / pre-launch).** The customer **frontend Order Summary** (checkout JS), the **customer receipt** (hosted + PDF), the **admin receipt/print**, and the **admin Order Detail** must ALL show the same line items + the same subtotal/fee/tax/total — and must reconcile to what was actually charged. Today they diverge (real example, order #00009: additional shavings charged but missing from receipt + folded into the stall line on Order Detail; tack stall missing from receipt). Root cause class: each surface RECONSTRUCTS the breakdown independently instead of from one source of truth. Work: (a) the `build_order_line_items` / `get_order_stall_breakdown` reconstruction must cover EVERY charge line (stalls, stall premium/surcharge, required shavings, additional shavings [per-product], RV base, RV premium, general add-ons, group grounds fee + deposit, pre-entries, discount, custom items) on every surface; (b) tack-stall + group + assignments shown consistently; (c) **structural guard: a smoke that asserts Σ(displayed line items) + fee + tax == the order's charged total** for representative orders — so nothing can be silently dropped from a receipt again. Partial fixes already shipped for #00009 (additional shavings line + tack on receipt + breakdown price fix); this item is the full sweep + the invariant guard.

10. [ ] **Hide assignment UI when inventory is Bulk/Quantity (no mapping).** On Order Detail, the "Assigned Stall Units / Manage Stall Assignment" and "Assigned RV Lots / Assign RV Lots" blocks show even when the reservation's inventory type is **Bulk** + customer selection is **Quantity** (no specific lots/stalls exist to manage). Admins click the button expecting to manage assignments and there's nothing to manage. Gate these blocks/buttons on the reservation actually using mapped/pick-from-layout inventory (per section: stall + RV independently). When Bulk/Quantity, suppress the assign button (optionally show a "No mapping — quantity only" note).

11. [x] **Special Requests field — renamed + made read-only (DONE, awaiting verify).** Order Detail "Special Instructions" card → renamed **"Special Requests"**, now READ-ONLY showing the customer's checkout free-text (sourced from the order's customer notes, same value as the receipt + Stall Chart column); removed the editable textarea, Save button, and the false "Applies to the entire reservation" helper text; removed the duplicate "Customer Notes" block from the Order Notes card. Decision locked: read-only customer field only (no admin-editable instructions field). Orphaned `eem_special_instructions_set` AJAX handler + `order-instructions-save` JS can be pruned in a later cleanup. Original spec: The customer-facing checkout field is **"Special Requests"** (e.g. "put me on an end row", "stallion accommodations"). Today the customer's text is being routed into **Order Notes → Customer Notes** on Order Detail, while a SEPARATE admin-editable **"Special Instructions"** card exists (scoped "applies to entire reservation"). Desired: the customer's Special Requests is the single canonical field — display it (read-only, NOT editable; it's the customer's words) on Order Detail under the heading **"Special Requests"** (rename the "Special Instructions" card), on the receipt (already shows as "Special Requests"), and on the Stall & RV Charts "Special Requests" column (already reads `get_special_requests_from_order_notes`). **Scope nuance to confirm at kickoff:** the current "Special Instructions" admin card is PER-RESERVATION (all orders) while the customer Special Requests is PER-ORDER — decide whether to (a) drop the per-reservation editable field entirely and show only the read-only per-order customer requests, or (b) keep both (read-only customer Special Requests + a separately-labeled admin note). Whitney's words: "it's not editable, it's what the customer types in at checkout, we should not be editing that." Touches: checkout submission routing, Order Detail render (rename + read-only), confirm stall-chart + receipt read the same source.

### Later (polish, non-blocking)

12. [ ] **Restyle "View Event" overview page** to match plugin design system.

13. [ ] **Remove "X days" countdown chip** on the event-list flyer card. **BLOCKED** — needs Whitney's mockup before starting.

14. [ ] **Restyle squished "Choose File" inputs** on Settings → Import/Export.

15. [ ] **Import/Export: event-level dates not carried** into the imported reservation.

16. [ ] **TEC event list template** — frontend event-list display for TEC-sourced events (part of the deferred frontend-lists work; scope/design TBD).

---

## 📋 v2 — Post-launch

1. [ ] QR Code Generator
2. [ ] Push Notifications (PWA browser push)
3. [ ] Global Handicaps API integration (GH as system-of-record). Full write-up: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`.
4. [ ] PWA + responsive/touch (full offline-capable app). Scaffolding disabled in 2.7.582 — restore from git history when PWA resumes.
5. [ ] Native mobile app (iOS/Android)
6. [ ] Port plugin logic to .NET (exploratory — tied to "not chained to WordPress forever" direction)
7. [ ] Apple Wallet + Google Wallet passes for confirmed orders
8. [ ] Orders list — per-page count control
9. [ ] Excel stall map import (.xlsx → stall rows + map grid)
10. [ ] PDF Venue Map → overlay (upload PDF, drop/snap stall hotspots)
11. [ ] Bypass cleaning phase on checkout — some venues don't clean between reservations; stall should go straight to Available instead of Cleaning. Scope TBD (per-reservation setting or checkout-modal prompt) — discuss before building.
12. [ ] Full permissions matrix (role-based access)
13. [ ] Stall-assignments CSV export (columns: Stall, Barn, Roper ID, Horse, Rider, Phone, Address, City, State, Zip, VIP)
14. [ ] Native Events source (en_event/en_venue/en_producer CPTs, ~1,500 LOC partially built) — keep gated "Coming Soon" in Settings → Integrations until v2
15. [ ] Event Entries — contestant entries (disciplines, fees, entrant roster). Distinct from Pre-Entries.
16. [ ] Facility Layout Templates — save venue stall/RV grid as reusable template tied to a venue; clone on next year's event. Discuss scope at v2 kickoff.
17. [ ] Notifications page v2 — saved reusable segments, per-recipient personalization tokens, opt-out handling (v1 ships basic audience builder + send + history)
18. [ ] Verify post-meta → config-table migration 100% complete (moved from v1)
19. [ ] RV amenities/hookups per lot (30amp/50amp/water/sewage) — in Edit Reservation editor + customer frontend icon chips. Build approach TBD — discuss before implementing. (Moved from v1.)

---

## ✅ Completed this cycle (verified by Whitney)

**Session 2026-06-27 (live walkthrough):**
- Map drag-and-drop assignment — drag a sidebar customer onto an available stall; arms the order, auto-exits when filled (2.7.651) ✅ verified
- Map click-to-assign stuck-mode fix — armed banner + Done/Esc + auto-exit + occupied-cell opens popover (2.7.651) ✅ verified
- Critical bug #1 — Cancelling an order now auto-releases stall/RV assignments
- Critical bug #2 — Cancelled/removed orders no longer appear in chart Assigned roster
- Critical bug #3 — Manage Stall Assignment blocks over-assignment beyond paid qty
- Critical bug #4 — Bulk multi-select "Remove from stall" on chart/map
- Critical bug #5/#6 — Required shavings shown as own priced line (not folded into stall subtotal, not shown under Add-Ons at $0)
- Critical bug #7 — Order Detail marks which assigned stall is the tack stall
- Edit Dates shorten on unpaid orders — now reduces order total instead of attempting refund
- Add Items modal — Stay/Arrival/Departure hidden for add-ons that don't need them
- Stall chart toast — over-assignment error no longer spams; dedupe logic added
- Contact Information autofill blue background — removed via -webkit-autofill box-shadow override
- "Changes can be requested through your account" text — removed (customers have no accounts)
- Tack stall chip — turns amber on customer map when a stall is designated as tack
- Tack legend swatch — amber swatch added to customer map legend
- Hotel-style 15-min in-cart unit hold (#36) — session token, gray "Taken" chip, heartbeat, auto-release

**Prior sessions (verified):**
- Chip name order "Last, First" on spatial map
- Blank/broken "By Location — Map" guard (forces list when no map)
- Payment Outstanding banner on Open orders
- Stall map assign-mode JS crash (THE big one — 453 stalls now render in assign mode)
- Open status badge → amber; Add-On type badge → teal
- Stall Chart — Map + List popover unification (3rd drift fix)
- "Clear All Assignments" button removed (too dangerous)
- Cancel Orders button styling fixed (`.eem-btn-danger`)
- Bulk "Move to Trash" on Orders list
- Stall chart zoom + scroll position preserved across reloads
- Assign search fixed for GEMS-imported orders
- By Customer table sortable by Arrival + Departure
- Spatial map search bar (stall number + customer name)
- Assign popover "Add new customer" (button + flow)
- Assignee name on chips "Last, First" when zoomed
- Scheduling custom message on customer event page
- VIP flag (gold ★ on List/Map/By-Customer + map legend)
- Daily Movement check-in lifecycle + arrival rings + legend
- Additional Shavings JS computation on customer page (Order Summary row)
- TEC date off-by-one fixed (noon UTC parse)
- `[hidden]` override fix for `.eem-field-row { display: grid }` parent
- Order Detail refund-due banner (blue variant when overpaid after date reduction)

---

## 📚 Reference documents

- `CLAUDE.md` — authoritative decisions, conventions, chunk history, CSS/JS discipline rules.
- `README.md` — data model, file inventory, conditional visibility rules, naming conventions.
- `docs/decisions.md` — product decisions log (refunds, cancellation policy, etc.).
- `docs/BRAND_GUIDE.md` — color tokens, typography scale, component specs.
- `docs/ARCHITECTURE-VENUES.md` — source-agnostic Venue model + resolver + Facility Layout Templates.
- `docs/ARCHITECTURE-DATA-OWNERSHIP.md` — data storage, GH integration models, WordPress-replaceable principle.
- `docs/WORKPLAN-postmeta-decouple.md` — postmeta→relational migration plan + estimate.
- `OVERHAUL_REPORT.md` — before/after of the original Codex-overhaul effort.
