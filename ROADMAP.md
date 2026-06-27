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

## 📋 v1 — Open items

> **NUMBERING IS PERMANENT (locked 2026-06-27).** Each item's number is a stable ID — never renumber, never reuse. New items get the next unused number. Completed or removed items KEEP their number (marked ✅ done or ~~struck~~) so a number always means the same thing across conversations. Highest number used so far: **17**.

### Awaiting Whitney verification
- [x] **Stall popover icon/style parity** — ✅ verified by Whitney 2026-06-27.
- [x] **Special Requests field** (renamed + read-only, 2.7.653) — ✅ verified by Whitney 2026-06-27.
- [ ] **Group Names feature — VERIFY LATER (not in use yet).** Shipped 2.7.650 + branch follow-ups. Verify when groups are actually used: (1) admin adds names in the editor Group Names table (Description + Riders Per Group removed; Group Names is the only field); (2) customer event page shows the strict-list Group dropdown; (3) assign/change/remove group from the map popover; (4) sidebar Groups filter (shown only when groups enabled); (5) group shows on order detail; (6) **Grounds Fee + Rider Deposit charges show on the customer Order Summary AND on the admin Order Detail** (verify the per-rider amounts actually appear and total correctly). Editor-cleanup commit `1bc0432` is on the branch and NOT yet merged to main / deployed — bump + merge when ready to verify.

### Active (tackle one at a time)

0. [ ] **Stall & RV Charts — rethink the toolbar layout (Whitney sleeping on it, 2026-06-27).** The 2.7.657 cleanup shipped but feels "clunky." THE core problem (Whitney, said twice): **the filters/controls MOVE on every one of the 3 views** (By Customer / By Location—List / By Location—Map) — that inconsistency is the jarring part. Goal: **ONE consistent control layout that stays put across all 3 views**, with **Show + View anchored together, left-aligned directly under the page title**, identical position on every view. Today they sit top-right and the surrounding controls (Search, Barns, Quick-view, sidebar) reflow per view. Tomorrow: design a single fixed toolbar; Show/View never move; only the contents that genuinely don't apply to a view (e.g. Bulk update, barn tabs) hide in place rather than reshuffling everything. Don't start until Whitney confirms the direction. Header moves + sidebar declutter from 2.7.657 are self-contained commits → easy to revert individually if she wants a different base.


1. [ ] **Global mobile visual polish** — per-page pass to match Daily Movement standard (row heights, badge sizing, spacing/density). Scaffolding shipped (2.7.577–580); per-page work not started.

2. [ ] **Add-On Report** — per-day add-on quantities, CSV + PDF. **Decisions locked (2026-06-27, paused mid-build):** (a) per-day model = count each add-on on EVERY day of the order's stay (daily-consumable, mirrors Shavings daily report); (b) scope = GENERAL add-ons only (shavings has its own report). Mirror `shavings_report` structure (summary across reservations + per-day rows for a single reservation); register in `EEM_Reports_Repo::REPORTS` + `get_report()` dispatch + reports page UI + exporter + PDF. General add-on per-order qty comes from order notes ("Add-On: NAME | Qty: N | ...").

3. [ ] **Full end-to-end customer checkout sweep** — run a real checkout on the NTR 6519 fixture page. Also the recommended way to seed test data (real checkout writes correct `reservation_id` + notes tag + config-based pricing).

4. [x] **Full map post-meta → config migration** — stall/RV map snapshots dual-write to the config table + post-meta; reads are config-first with post-meta fallback + lazy backfill. Shipped 2.7.652. ✅ **verified by Whitney 2026-06-27.**

5. [ ] **Postmeta → relational de-coupling Phase 1** — remaining gaps: map snapshots, hybrid blocked-units reads, events/venues/producers/divisions editors still on post-meta. Audit plan: `docs/POSTMETA-AUDIT.md`.

6. [x] **Self-test harness: order-totals math validation.** DONE — `tests/smoke/order-totals-math-smoke.php` drives the canonical charging calculator (`calculate_submission_totals`, the source of truth for what's charged) and asserts every line against hand-math: stalls, required shavings (tack excluded), additional shavings, general add-ons, group grounds-fee + deposit, convenience fee (% + flat), tax, total, and a group-off case. 16/16 assertions pass. Harness via `scripts/dev-sqlite-harness.sh`. FOLLOW-UP (optional, lower priority): extend to assert the customer Order Summary (JS) and admin Order Detail (stored line items) match the calculator end-to-end; and prune the ~465 environmental SQLite smoke failures so the suite roll-up is clean. Run smokes with `php -d opcache.enable_cli=0` (CLI OPcache caches edited files otherwise).

7. [x] **Generate Assignments — keep a single customer's stalls contiguous — DONE (branch), awaiting verify.** New Pass 1.6 `assign_order_contiguous_stalls` seats each single order still needing 2+ stalls in a consecutive run within one barn (runs after the group-contiguous pass, before lowest-first fill); falls through to scattered lowest-first when no run is long enough. Smoke `single-order-contiguous-smoke` (4/4); group-contiguous (12/12) + bulk auto-assign (32/32) + assignment-conflict (11/11) unaffected. Original: **keep a single customer's stalls contiguous.** Today auto-assign only seats multi-ORDER *groups* contiguously (`assign_group_contiguous_stalls`); a single order needing 2+ stalls just takes the lowest-numbered available stalls in pool order, so one customer can be split across the barn (e.g. 238 + 250 instead of 238 + 239). Generalize the contiguous-run helper to also run per-order: try to seat each multi-stall order in a consecutive block within one barn, fall back to scattered lowest-first only when no run is large enough. Code in `EEM_Orders_Repository::auto_assign_units_for_reservation` / `assign_group_contiguous_stalls` (`includes/class-equine-event-manager-orders-repository.php`).

8. [x] **Convenience Fee → global Settings → Payments — DONE (2.7.x branch), awaiting verify.** Single global fee (no per-reservation override, Whitney decision); ships disabled/$0, admin sets amount/type/label in Settings → Payments (new card above Tax). `EEM_Settings_Repo::get_convenience_fee_amount()` is the single source of truth; checkout calculator + add-items pricer + frontend Order Summary all derive from it. Editor Fees section removed. Smokes: convenience-fee-global (10/10), order-totals (26/26), order-edit (9/9). Original: **Move Convenience Fee from per-reservation to global Settings → Payments.** Remove the Convenience Fee section from the Edit Reservation editor; add it to Settings → Payments, positioned **above** the Tax Rate block. Becomes a global default (like Tax). Migration: snapshot existing per-reservation convenience-fee config into the global setting (or keep per-reservation override semantics — decide at kickoff, mirror how Tax does per-reservation override). Touches: editor section removal, Settings → Payments UI + save, and `calculate_submission_totals`'s `calculate_convenience_fee` source (read global instead of `$data`). **Charging math — verify totals before/after on the harness (#6).**

9. [x] **DISPLAY MATH parity — DONE (2.7.655), awaiting verify.** Admin Order Detail now delegates to the canonical `EEM_Shortcodes::get_order_stall_breakdown` (kills the #00009 divergence); admin Order Detail totals + print receipt itemize stall/RV premium surcharges to match the customer receipt and reconcile to the stored total. Guards: `order-breakdown-cross-surface-smoke` (admin==customer, 11/11) + `admin-totals-reconcile-smoke` (Σ rows == Total, 4/4) + pre-entry itemization on receipt/email. RESIDUAL (optional, fold into #3): explicit cross-check that the live customer frontend Order Summary (checkout JS) matches the receipt/Order Detail line-for-line — the charge calculator (`calculate_submission_totals`) is the shared source of truth the JS mirrors, but a side-by-side sweep on a real checkout hasn't been run. Original: **all four surfaces must match (HIGH / pre-launch).** The customer **frontend Order Summary** (checkout JS), the **customer receipt** (hosted + PDF), the **admin receipt/print**, and the **admin Order Detail** must ALL show the same line items + the same subtotal/fee/tax/total — and must reconcile to what was actually charged. Today they diverge (real example, order #00009: additional shavings charged but missing from receipt + folded into the stall line on Order Detail; tack stall missing from receipt). Root cause class: each surface RECONSTRUCTS the breakdown independently instead of from one source of truth. Work: (a) the `build_order_line_items` / `get_order_stall_breakdown` reconstruction must cover EVERY charge line (stalls, stall premium/surcharge, required shavings, additional shavings [per-product], RV base, RV premium, general add-ons, group grounds fee + deposit, pre-entries, discount, custom items) on every surface; (b) tack-stall + group + assignments shown consistently; (c) **structural guard: a smoke that asserts Σ(displayed line items) + fee + tax == the order's charged total** for representative orders — so nothing can be silently dropped from a receipt again. Partial fixes already shipped for #00009 (additional shavings line + tack on receipt + breakdown price fix); this item is the full sweep + the invariant guard.

10. [x] **Hide assignment UI when inventory is Bulk/Quantity — DONE (awaiting verify).** Order Detail now hides the "Assigned Stall Units / Manage Stall Assignment" block unless stalls are `numbered`, and the "Assigned RV Lots / Assign RV Lots" block unless RV is `mapped`. Needs bump+deploy to verify live. Original: On Order Detail, the "Assigned Stall Units / Manage Stall Assignment" and "Assigned RV Lots / Assign RV Lots" blocks show even when the reservation's inventory type is **Bulk** + customer selection is **Quantity** (no specific lots/stalls exist to manage). Admins click the button expecting to manage assignments and there's nothing to manage. Gate these blocks/buttons on the reservation actually using mapped/pick-from-layout inventory (per section: stall + RV independently). When Bulk/Quantity, suppress the assign button (optionally show a "No mapping — quantity only" note).

11. [x] **Special Requests field — renamed + made read-only (DONE, awaiting verify).** Order Detail "Special Instructions" card → renamed **"Special Requests"**, now READ-ONLY showing the customer's checkout free-text (sourced from the order's customer notes, same value as the receipt + Stall Chart column); removed the editable textarea, Save button, and the false "Applies to the entire reservation" helper text; removed the duplicate "Customer Notes" block from the Order Notes card. Decision locked: read-only customer field only (no admin-editable instructions field). Orphaned `eem_special_instructions_set` AJAX handler + `order-instructions-save` JS can be pruned in a later cleanup. Original spec: The customer-facing checkout field is **"Special Requests"** (e.g. "put me on an end row", "stallion accommodations"). Today the customer's text is being routed into **Order Notes → Customer Notes** on Order Detail, while a SEPARATE admin-editable **"Special Instructions"** card exists (scoped "applies to entire reservation"). Desired: the customer's Special Requests is the single canonical field — display it (read-only, NOT editable; it's the customer's words) on Order Detail under the heading **"Special Requests"** (rename the "Special Instructions" card), on the receipt (already shows as "Special Requests"), and on the Stall & RV Charts "Special Requests" column (already reads `get_special_requests_from_order_notes`). **Scope nuance to confirm at kickoff:** the current "Special Instructions" admin card is PER-RESERVATION (all orders) while the customer Special Requests is PER-ORDER — decide whether to (a) drop the per-reservation editable field entirely and show only the read-only per-order customer requests, or (b) keep both (read-only customer Special Requests + a separately-labeled admin note). Whitney's words: "it's not editable, it's what the customer types in at checkout, we should not be editing that." Touches: checkout submission routing, Order Detail render (rename + read-only), confirm stall-chart + receipt read the same source.

12. [x] **Auto-save stall/RV maps to the venue — DONE (branch), awaiting verify.** Every reservation/map save now auto-saves the layout to its venue as a single rolling "Auto-saved (latest)" row (decisions: every save + one rolling per venue). `EEM_Venue::auto_save_layout()` upserts the reserved row with an empty-guard (never clobbers a good map with a blank). Hooked into both `ajax_map_builder_save` (the map builder) and `save_meta` (Update Reservation). Coexists with manual named saves + is loadable. Smoke `venue-auto-save-layout-smoke` (10/10). Original: **Auto-save stall/RV maps to the venue (don't lose maps).** Whitney's concern: a built stall/RV map should automatically persist to the **venue** so it can't get lost (rather than relying on a manual "Save Map" / "Save Layout" action that's easy to forget). On map edit/build, auto-save the layout to the reservation's venue as the venue's current layout, so the next reservation at that venue can reuse it. Relates to the v2 Facility Layout Templates work (copy-on-use clone), but this is the lighter "never lose a map" safety net. Decide at kickoff: auto-save on every edit vs. on publish; one current-layout per venue vs. named templates; interaction with the existing manual Save Layout flow.

### Later (polish, non-blocking)

13. [ ] **Restyle "View Event" overview page** to match plugin design system.

14. [ ] **Remove "X days" countdown chip** on the event-list flyer card. **BLOCKED** — needs Whitney's mockup before starting.

15. [x] **Restyle squished "Choose File" inputs** on Settings → Import/Export. ✅ Done — custom `.eem-file-pick-*` styled picker; also removed subtitle text and Guidelines box from Import CSV card.

16. [ ] **Import/Export: event-level dates not carried** into the imported reservation.

17. [x] **Rounded border on list-page tables** — Events list, Customers list, and Term Categories pages all showed an extra rounded border at the bottom of the table. Fixed by wrapping in `.eem-list-card` and extending the CSS zeroing rule to `.eem-card > .eem-desktop-table`. ✅ Done.

18. [x] **Daily Movement missing table footer** — DM page had no row count or visual closure below each arriving/departing group. Added `.eem-table-footer` row count via `render_group_footer()`. ✅ Done.

19. [x] **Audit all invoice/email templates for design-system parity** ✅ Done. Confirmation email + PDF receipt were already on-brand. Fixed: Invoice email, Refund email, and Payment Received email (all used Arial font, `#111827` text, `#eef2f6` cards, `border-radius:24px`, near-black CTA button — completely off-brand). Restyled all three to match: IBM Plex Sans, `#0d1b3e` navy, `#1668F2` Electric Blue accent, `#F7F9FC` background, 8px radius, `#e2e8f4` borders. Also fixed minor color drift in report PDF (`#031B4E`/`#1d2327` → `#0d1b3e`, header border → Electric Blue `#1668F2`).

20. [ ] **TEC event list template** — frontend event-list display for TEC-sourced events (part of the deferred frontend-lists work; scope/design TBD).

---

## 🔍 Pre-Launch Audit Findings (2026-06-27)

Findings from a 4-agent parallel audit (security, financial integrity, email/data, customer-facing/concurrency). Each item is a discrete to-do with a severity tag. **Items tagged `[PAYMENT]` overlap with the desktop session's payment audit — coordinate before fixing so the two sessions don't collide or contradict.** Nothing here is fixed yet; this is the catalog.

**Overall health:** The plugin audited *well*. Money math is server-side and tamper-resistant, Stripe signature verification + amount binding are correct, MySQL advisory locks prevent stall double-booking, all AJAX handlers have nonce + capability checks, no card data is stored, file uploads are validated. The items below are the gaps worth closing before launch. (One "critical SQL injection" flagged by the security agent was verified a FALSE POSITIVE — `class-eem-stay-packages-repo.php:36` concatenates two separately-`prepare()`'d fragments, which is a safe pattern; no action needed.)

### HIGH / MEDIUM

A1. [ ] **[PAYMENT] Authorize.net response doesn't verify the charged amount** matches the server-calculated total. Stripe does this (`shortcodes.php:4648` compares intent amount to `round(total*100)`); the Auth.net path (`shortcodes.php:~8859`) only checks `responseCode === '1'` + presence of `transId`. Add a parallel amount-match assertion. **Severity: MEDIUM/HIGH.**

A2. [x] **CSV import hardening** (`class-eem-import-handler.php`) ✅ Done (this branch). Added a shared `validate_upload_file()` helper (genuine-upload check + 5 MB size cap + extension whitelist) wired into both the CSV (`ajax_preview`) and JSON (`ajax_import_setup`) upload paths. Stopped echoing `$wpdb->last_error` into the JSON response — both insert-failure rows now return a generic localized message with the row number. **Note:** CSV-injection cell-prefix neutralization (`=`,`+`,`-`,`@`) was NOT added — imported values currently only enter the DB as strings and aren't re-exported as formulas by this handler; revisit if/when a re-export path is added. **Severity: MEDIUM → resolved (injection note deferred).**

A3. [ ] **No unsubscribe / opt-out for bulk notifications.** The Notifications-page bulk send (`class-eem-notifications-page.php:437`) and bulk Email Customers have no opt-out mechanism or preference flag. Transactional emails (confirmation, refund, payment, cancellation) legally don't need it, but bulk/marketing sends should. Decide: add a per-customer opt-out flag + unsubscribe link in bulk emails, or document that bulk send is admin-discretion only. **Severity: MEDIUM.**

A4. [ ] **[PAYMENT] Authorize.net error_log dumps full payload** to `debug.log` (`shortcodes.php:~8864`, `wp_json_encode($payload)`). Gated behind `WP_DEBUG` so dev-only, but the payload may contain transaction details. Filter sensitive fields before logging. **Severity: MEDIUM (dev-only exposure).**

A5. [ ] **[PAYMENT] Charge happens before order insert** (`shortcodes.php` checkout flow: payment at ~line 3260, order insert at ~3266). A crash between the two leaves the customer charged with no stall assigned (recoverable only via manual Stripe/Auth.net refund). The MySQL checkout lock + re-validation make double-booking impossible, but the charge-then-persist ordering is the one window where money and inventory can desync. Consider persisting a pending order first, or wrapping in a tighter try/catch that auto-voids the charge if the insert throws. **Severity: MEDIUM (rare crash window). Significant rework — discuss before tackling.**

### LOW / HOUSEKEEPING

A6. [ ] **[PAYMENT] Refund-notes amount regex preserves the minus sign** (`class-eem-refund-engine.php:56`, `preg_replace('/[^0-9.\-]/','',$value)`). A corrupt/edited notes line could yield a negative parsed amount. Already mitigated by a downstream `max(0.0, ...)` clamp, so low practical risk — drop the `\-` from the character class for belt-and-suspenders. **Severity: LOW (mitigated).**

A7. [x] **Order payment-status has no whitelist** ✅ Done (this branch). Added `EEM_Orders_Repository::VALID_PAYMENT_STATUSES` (paid, pending, unpaid, partially_paid, refunded, partially_refunded, cancelled) and an `in_array()` guard in `update_order_payment_details()` that returns `false` on any out-of-set status. This matters more than first thought: the REST `PUT /orders/{key}` endpoint passes `payment_status` straight through, so the whitelist closes a real external input vector, not just the admin-AJAX path. **Severity: LOW → resolved.**

A8. [x] **Cart-hold cleanup relies on customer traffic, not cron** ✅ Done (this branch). Added an hourly WP-cron sweep: `EEM_Unit_Holds_Repo::CRON_HOOK` (`eem_unit_holds_cleanup`) with `schedule_cleanup()` / `unschedule_cleanup()` helpers. Scheduled in the activator's create-tables pass, handler bound in `EEM_Plugin::run()` (calls `cleanup_expired()`), and cleared via a new `register_deactivation_hook` in the main plugin file. The opportunistic cleanup on form submit / hold attempt stays as a belt-and-suspenders fallback. **Note:** the schedule arms via `activate()`, which on existing installs only re-runs on a version-change upgrade — so on the live site the cron will arm at the next release bump (fine, since launch bumps the version anyway). **Severity: LOW → resolved.**

A9. [x] **Admin BCC receipt send failure is unchecked** ✅ Done (this branch). The admin BCC send result is now captured; on `WP_Error` it logs a diagnostic line (gated behind `WP_DEBUG`, the sanctioned logging pattern) with the order key. Still non-fatal to checkout — the customer email remains the critical path. **Severity: LOW → resolved.**

A10. [x] **Notifications-page bulk send isn't written to the activity log** ✅ Verified FALSE POSITIVE — no change needed. The audit agent looked at the per-batch helper `dispatch_batch()` (line ~437) and missed the completion path: `ajax_send_step()` already writes an `EEM_Activity_Log::NOTIFICATION_SENT` entry (channel `notifications_page`, audience description + sent/failed counts) when the job finishes (`class-eem-notifications-page.php:382-398`), and `render_history()` reads it back. Already correct.

A11. [x] **`@move_uploaded_file()` error suppression** ✅ Done (this branch). Removed the `@` from `move_uploaded_file()` (`class-eem-order-documents.php`) — a failed move now surfaces a diagnostic warning to the log AND still returns the graceful user-facing message. Kept `@chmod()` intentionally (best-effort hardening; a hardened host may disallow chmod and the file is already in a private `.htaccess`-protected dir) with an explanatory inline comment. **Severity: LOW → resolved.**

A12. [ ] **Decide on payment-gateway key storage** (`equine_event_manager_payment_settings` in `wp_options`, plaintext). The security agent flagged this HIGH, but **plaintext-in-wp_options is standard WordPress practice** — virtually every gateway plugin does this, keys are behind `manage_options`, rendered as `type="password"`, and not exposed in page source / JS / REST. Closing it (env vars or at-rest encryption) is a real hardening step but a product decision, not a bug. Decide whether v1 wants it or defers to v2. **Severity: INFO / product decision.**

A13. [ ] **Payment Reminder email template exists but has no automatic trigger** (`EEM_Email_Templates_Repo::PAYMENT_REMINDER`). It can only be sent manually via bulk email. Confirm this is intended (manual-only) or wire an automatic reminder for unpaid orders. **Severity: INFO / confirm intent.**

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
