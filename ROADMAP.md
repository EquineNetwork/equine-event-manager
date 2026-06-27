# Equine Event Manager — Roadmap & To-Do

> **THIS IS THE TO DO LIST — the only one.** Two lists only: **v1 (pre-launch)** and **v2 (post-launch)**.
> Read this file first every session. Do not invent a parallel task list, and do not reconstruct tasks
> from the codebase. Check items off here in the same step you finish them.

---

## 🔖 SESSION HANDOFF — 2026-06-27

---

## ⚠️ BRANCHES WAITING TO MERGE — DO THIS BEFORE NEXT PLUGIN UPDATE

Before any version bump or release, these branches MUST be merged to `main` first:

| Branch | PR | What's in it |
|---|---|---|
| `claude/page-styling-template-jwx3ez` | PR #36 | Import/Export styling; list-page rounded border fix (Events, Customers, Term Categories); Daily Movement footer; invoice/refund/payment-received email restyle to design system; report PDF color tokens; **audit fixes A2 (CSV import hardening), A7 (order-status whitelist), A8 (cart-hold cleanup cron), A9 (admin BCC failure logging), A11 (move_uploaded_file suppression)**; ROADMAP #15/#17/#18/#19 done |

**How to merge when ready:** Whitney approves → merge PR on GitHub → confirm `main` has the changes → then bump version as normal.

---

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

## 💰 PAYMENT & CALCULATION AUDIT — TOP PRIORITY (2026-06-27)

> The system will take **hundreds of thousands of dollars** immediately. EVERY chargeable
> input and EVERY money surface must be 100% correct. Full detail in two committed docs:
> **`CHARGE-CHECKLIST.md`** (the exhaustive card-by-card list) + **`PAYMENT-CALC-AUDIT.md`**
> (findings + method). This block is the durable index so nothing is missed.

### LOCKED DECISIONS (Whitney, 2026-06-27 — do not re-litigate)
1. **Convenience fee = 4% of subtotal, applies to EVERY product/line item** (stalls, RV,
   shavings, add-ons, group fees, pre-entries, custom line items) on EVERY charge path.
2. **Cash/check is the ONLY exception, BACKEND ONLY.** Frontend checkout is always card →
   always charges the fee. The Collect Payment **"Paid Cash"** tab (cash or check) is the
   single place that REMOVES the fee (recalc total w/o fee). Send Link + Charge Card are
   card → keep the fee. Rationale: the fee is a pass-through of the merchant card fee.
3. **Sales tax stays OFF** (Apply Tax unchecked, 0%). Keep the feature (shavings are a
   physical good; state law may vary) but do not enable. Audit the tax MATH anyway.
4. **No dev/stub references in UI** — remove "ported in C7" etc. All work is done.

### MONEY SURFACES (every dollar must appear + reconcile on each)
Customer: checkout Order Summary (live JS) · confirmation email · hosted receipt · PDF
receipt · **payment-link/invoice email** (itemized + "pay" button). Admin: Order Detail ·
print receipt · Orders list (Total / **Total Paid** / Balance) · Create Order · **Collect
Payment** (Send Link / Charge Card / Paid Cash) · Dashboard revenue · Reports · Activity Log.
Ground truth: the actual gateway charge + stored totals.

### ORDER-CREATION & RECALC PATHS (each must inherit FULL reservation pricing)
Customer checkout · Create Order · **Map "Add New Customer" placeholder** · Send Link/Open
Tab · Add Items (stall/RV/product/custom) · Edit Dates (lengthen=Balance Due,
shorten=Refund Owed) · Discount apply/remove.

### FINDINGS SO FAR
- **F1** (Med) Production build ships without `tools/` → wp-cli fatals (browser unaffected). Workaround applied.
- **F2** (Low) refund-math smoke stale (`order_key` schema drift) — test only, not a bug.
- **F3** 🔴 (HIGH) Map "Add New Customer" placeholder orders mis-priced: NO map surcharge,
  no dates→1 night, sparse order. `ajax_stall_create_placeholder` uses base-rate-only pricer.
- **F4** 🔴 (HIGH) Add Items: products + custom items inserted FLAT — no convenience fee/tax
  (stalls/RV get it). Per Decision #1 this is a bug; must apply fee+tax to every added line.
- **F5** Edit Dates: verify shorten=Refund Owed, lengthen=Balance Due, fee/tax recompute on delta.
- **NEW** Cash-skips-fee (Decision #2) not yet built on the Paid Cash flow.
- Charge calculator itself reads every input incl. Stay Packages + per-package early bird
  (verified by code trace + green money smokes). Invoice email already itemizes + has a pay
  button ("Review Invoice & Pay Now").

### TASK TRACKER
Active task list IDs #1–#13 (this session). Build a seeding+verification harness, then work
`CHARGE-CHECKLIST.md` row by row: seed real orders across every input × scenario × surface,
assert Σ(lines)+fee+tax−discount == charged total, fix display/recalc bugs, flag charge-math
changes (F3/F4/cash) for Whitney sign-off before they go live.

### OPEN (answered) DECISIONS NEEDING FIX-WORK
- F3, F4, and cash-skips-fee all CHANGE charged amounts → implement, but get Whitney's
  explicit OK on the resulting numbers before deploying. No version bump without approval.

---

## ⚠️ CANONICAL STALL POPOVER OPTION SET (anti-drift guard — DO NOT let these diverge again)

Both the By Location **List** popover and **Map** popover MUST expose the SAME options. This is the 3rd time they've drifted. When editing either, mirror the change in the other.

- **Available cell:** assign customer (search) · **+ Add New Customer** (inline First/Last → Save & Assign) · Block.
- **Assigned/tack cell:** header = customer name · meta = Order # + Group + Shavings · Move to different stall · View order · Mark as Tack / Unmark Tack · Mark as VIP / Remove VIP · Remove from stall.
- **Map-only exclusion:** NO check-in / checkout on the map (assignment-focused). Check-in/out lives on the List / Daily Movement.

Code locations: List = `openAssignPickModal()` + server menu in `assets/js/admin.js`; Map = `eemSmapOpenPop()` in `assets/js/admin.js` + `ajax_stall_map_action()` ops in `admin/class-equine-event-manager-admin.php`.

---

## 📋 v1 — Must ship before launch (next week)

> Everything in this list is blocking. Anything not here and not in the v2 list is not planned. When an item is done AND Whitney has verified it, delete it (don't leave a checked-off marker). Numbers are stable IDs — don't reuse them; `A`-prefixed items came from the pre-launch audit and several are owned by the desktop payment-audit chat (marked below — do not edit those here).

- [ ] **Group Names feature — verify when groups are actually in use (not yet).** Shipped 2.7.650 + branch follow-ups. Verify: (1) admin adds names in the editor Group Names table; (2) customer event page shows the strict-list Group dropdown; (3) assign/change/remove group from the map popover; (4) sidebar Groups filter (only when groups enabled); (5) group shows on order detail; (6) **Grounds Fee + Rider Deposit charges show on the customer Order Summary AND admin Order Detail** with correct per-rider totals. Editor-cleanup commit `1bc0432` on the branch is NOT yet merged/deployed — bump + merge when ready to verify.

0. [ ] **Stall & RV Charts — rethink the toolbar layout (Whitney sleeping on it, 2026-06-27).** The 2.7.657 cleanup shipped but feels "clunky." THE core problem (Whitney, said twice): **the filters/controls MOVE on every one of the 3 views** (By Customer / By Location—List / By Location—Map) — that inconsistency is the jarring part. Goal: **ONE consistent control layout that stays put across all 3 views**, with **Show + View anchored together, left-aligned directly under the page title**, identical position on every view. Today they sit top-right and the surrounding controls (Search, Barns, Quick-view, sidebar) reflow per view. Tomorrow: design a single fixed toolbar; Show/View never move; only the contents that genuinely don't apply to a view (e.g. Bulk update, barn tabs) hide in place rather than reshuffling everything. Don't start until Whitney confirms the direction. Header moves + sidebar declutter from 2.7.657 are self-contained commits → easy to revert individually if she wants a different base.


1. [ ] **Global mobile visual polish** — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.

2. [ ] **Add-On Report** — per-day add-on quantities, CSV + PDF. **Decisions locked (2026-06-27, paused mid-build):** (a) per-day model = count each add-on on EVERY day of the order's stay (daily-consumable, mirrors Shavings daily report); (b) scope = GENERAL add-ons only (shavings has its own report). Mirror `shavings_report` structure (summary across reservations + per-day rows for a single reservation); register in `EEM_Reports_Repo::REPORTS` + `get_report()` dispatch + reports page UI + exporter + PDF. General add-on per-order qty comes from order notes ("Add-On: NAME | Qty: N | ...").

3. [ ] **Full end-to-end customer checkout sweep** — run a real checkout on the NTR 6519 fixture page. Also the recommended way to seed test data (real checkout writes correct `reservation_id` + notes tag + config-based pricing).

5. [ ] **Postmeta → relational de-coupling Phase 1** — remaining gaps: map snapshots, hybrid blocked-units reads, events/venues/producers/divisions editors still on post-meta. Audit plan: `docs/POSTMETA-AUDIT.md`.

7. [ ] **Generate Assignments — single customer's stalls kept contiguous** — built on branch (Pass 1.6 `assign_order_contiguous_stalls`; smokes pass). Needs your verify + merge.

8. [ ] **Convenience Fee → global Settings → Payments** — built on branch (global fee, ships disabled/$0, editor Fees section removed; smokes pass). Needs your verify + merge.

9. [ ] **Display-math parity across all 4 surfaces** — built on branch (admin Order Detail uses the canonical breakdown; receipt/Order Detail itemize surcharges; Σ rows == total guard). Needs your verify + merge. Live side-by-side of customer checkout JS vs receipt is folded into #3.

10. [ ] **Hide assignment UI when inventory is Bulk/Quantity** — built on branch (Order Detail hides Manage Stall/RV blocks unless numbered/mapped). Needs your verify + merge.

12. [ ] **Auto-save stall/RV maps to the venue** — built on branch (rolling "Auto-saved (latest)" per venue, empty-guard; smokes pass). Needs your verify + merge.

13. [ ] **Restyle "View Event" overview page** to match plugin design system.

14. [ ] **Remove "X days" countdown chip** on the event-list flyer card. **BLOCKED** — needs Whitney's mockup before starting.

16. [ ] **Import/Export: event-level dates not carried** into the imported reservation.

20. [ ] **TEC event list template** — frontend event-list display for TEC-sourced events (part of the deferred frontend-lists work; scope/design TBD).

21. [ ] **Verify + merge PR #36 (styling + audit fixes, this branch).** All built and pushed, needs your click-through then merge: Import/Export page styling + custom file inputs; list-table bottom-border fix (Events/Customers/Term Categories); Daily Movement footer; invoice/refund/payment-received email + report-PDF design-system parity; CSV import hardening (size/type validation, no DB-error leak); order payment-status whitelist; hourly cart-hold cleanup cron; admin BCC send-failure logging; move_uploaded_file error-suppression fix.

22. [ ] **Bulk-notification unsubscribe.** Per-recipient opt-out: HMAC-signed (WP-salt keyed) unsubscribe link in bulk emails (Notifications page + Email Customers), a public handler that records the opt-out, a skip-check before non-transactional sends. Transactional sends (confirmation, refund, payment, invoice, cancellation) always send regardless. Approved, not started.

23. [ ] **Auto payment-reminder for unpaid orders.** Daily WP-cron finds unpaid orders past a configurable age and sends the existing `PAYMENT_REMINDER` template, with dedupe so an order isn't reminded repeatedly. Reads payment_status + sends email only (no payment-dispatch changes). Approved, not started.

---

## 🛑 Owned by the desktop payment-audit chat — do NOT edit these here

These are v1-blocking too, but the desktop chat is working the payment code live. Left here untouched so the two chats don't collide. (Audit verdict: money math, Stripe signature verification, refund capping, and decimal storage all PASS; the "critical SQL injection" flag was a verified false positive — safe `prepare()` concatenation at `stay-packages-repo.php:36`.)

P1. [ ] **Authorize.net response doesn't verify the charged amount** matches the server total. Stripe does (`shortcodes.php:4648`); the Auth.net path (`shortcodes.php:~8859`) only checks `responseCode === '1'` + `transId`. Add the amount-match assertion. *(Most important real finding.)*

P2. [ ] **Authorize.net error_log dumps full payload** to debug.log (`shortcodes.php:~8864`). WP_DEBUG-gated, but filter sensitive fields.

P3. [ ] **Charge happens before order insert** (`shortcodes.php` ~3260 charge / ~3266 insert). Crash between = charged but no stall. Persist a pending order first, or auto-void the charge if the insert throws. Significant rework — discuss first.

P4. [ ] **Refund-notes regex preserves the minus sign** (`refund-engine.php:56`). Mitigated by a `max(0.0,…)` clamp; drop the `\-` for belt-and-suspenders.

P5. [ ] **Payment-gateway key at-rest encryption.** Encrypt secret keys (Stripe secret/webhook, Auth.net transaction key) in `equine_event_manager_payment_settings`, keyed off WP salts, with a one-time migration for existing keys. Settings-layer only (`class-eem-settings-repo.php` read + `class-eem-settings-page.php` save); keep getter return contracts identical so payment dispatch is untouched. **MUST be live-verified (real test charge) before merge.** Sequence last.

**Coordination note for the desktop chat:** P1 and P3 both edit the Authorize.net block in `shortcodes.php` — do them together to avoid a self-conflict.

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
