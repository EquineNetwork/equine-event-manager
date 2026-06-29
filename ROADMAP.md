# Equine Event Manager — Roadmap & To-Do

> **THIS IS THE TO DO LIST — the only one.** Two lists only: **v1 (pre-launch)** and **v2 (post-launch)**.
> Read this file first every session. Do not invent a parallel task list, and do not reconstruct tasks
> from the codebase. Check items off here in the same step you finish them.

---

## 🔖 SESSION HANDOFF — 2026-06-28 (smoke-suite green-up + feature triage)

**Pick up here tomorrow.** All work below is committed + pushed to `main` (GitHub) with
**NO version bumps** — nothing auto-deployed; everything is dormant until you bump+deploy.

### What landed this session

**1. Smoke suite driven to green (#55).** Started 82 failing files / 11 fatals → now **0 fatals,
~1 remaining failure** (`native-events-slice3`, a v2-gated geocoding smoke — see "Open" below).
~45 smokes fixed. The dominant root cause was the **relational-store migrations** (mig-016
reservation-config table, venue store, division-config table, native-events table, mig-040
section-enabled keys): smokes seeded/read the OLD post-meta location and got empty back. Fix
pattern = seed/read through the canonical repo (`EEM_Reservation_Config`,
`EEM_Division_Config_Repo`, `EEM_Entries::save_entry_fields`, `EEM_Venue::save_detail`) or pass
`get_meta_values(..., prefer_postmeta=true)`.

**2. Seven REAL bugs found + fixed while greening smokes** (not just test edits):
- **Reports date/status filters were 100% non-functional** — `EEM_Reports_Repo::normalize_filters()`
  AND `EEM_Reports_Page::read_filters()` both silently dropped `date_from`/`date_to`/`status`. The
  Reports page date-range + status filtering did nothing. Fixed both layers + `get_filtered_orders`
  now actually applies them. **This is a live v1 bug fix — verify the Reports page filters work.**
- **Native venue rename orphaning** — `EEM_Venue::sync_native_venue` ignored its durable back-ref
  and re-resolved by name, so renaming a native venue spawned a duplicate canonical record. Now
  honors the back-ref. (v2-gated feature, low live impact.)
- **Dead `render_stats()`** in the Venues page (built, never called) — removed per your "if it's not
  used, remove it."
- **Stray `text-decoration: underline`** on `.eem-sc-banner-amber .eem-link-btn` — hygiene rule #8
  violation, removed. (Rebuilt `admin.min.css` after.)
- **Test-seed harness was silently dead** (`tools/seed-test-data.php`): wrote to the dropped
  `en_*` table names (post-#45 rename), omitted the `reservation_id` link, pulled dates from
  post-meta instead of the chart's config range, and didn't record `amount_paid`. Fixed all four —
  **your dev site now seeds realistic orders again** (run `wp eval-file tools/seed-test-data.php`).

**3. Add-On Report (#2) — found already CODE-COMPLETE** (the "paused mid-build" was finished);
smoke passes 12/0. Marked in the v1 list; **just needs your visual verify** (Reports → Add-Ons).

### 2026-06-29 continued (autonomous, all committed dormant + pushed, NO version bumps)

- **#16 Import event dates — ✅ DONE + VERIFIED by Whitney.** Imported Columbiana clone (#18375) shows
  Aug 20–24 as intended. (`import-event-dates-smoke.php` 6/0.) TEC/feed events store no start/end in
  `en_event` post-meta, so `import_setup` backfills the cloned event's dates from the available window
  **only when empty**.
- **Import/Export enhancements (out of #16 verify) — ✅ BUILT + smoke** (`export-selection-smoke.php`
  22/0), all Whitney-directed:
  - **Export section checkboxes** — Event (event + venue + producer), Reservation (map + blocked units +
    config + stay packages + all settings — always travels together), Orders & customers (OFF by
    default). Orders can only export with the Reservation (gated in `build_export` + UI disables the
    Orders box while Reservation is unchecked). `build_export($id, $include)` + export AJAX reads
    `include_*` flags. Export version 1.0→1.1 with an `included` manifest.
  - **Producer** now exports/imports symmetric to venue (created on import + remapped onto the event).
  - **Imports land as Draft** (always, regardless of exported status) so the clone is reviewed before
    going live. Setup-only exports import unlinked; link the event in the reservation editor afterward.
  - Import-JSON file-row top border to match the CSV section.
- **#2 Add-On Report — ✅ DONE + VERIFIED.** Whitney reviewed the populated report (5990). Two polish
  fixes shipped from her review: "Export history cleared" → toast (generic flash-toast bridge); the bare
  "N rows" report footer removed. (`addons-report-smoke.php` 12/0.)
- **#22 Bulk unsubscribe — ✅ BUILT + smoke** (`email-optout-smoke.php` 23/0). New `EEM_Email_Optout`
  (HMAC link, opt-out option, public handler, footer + skip-check) wired into both bulk-send paths;
  transactional sends untouched. **Review the rendered footer + confirmation page before activating.**

### NEXT UP

- **#23 Auto payment-reminder cron — ✅ FULLY BUILT (Option A), ships DISABLED.** Done: `EEM_Payment_Reminder`
  daily cron reuses the payment-link email; **Settings UI shipped** (Settings → Communications →
  "Automatic Payment Reminders" card: enable toggle + First-Reminder-After + Repeat-Every); defaults OFF;
  smoke 33/0; committed dormant. **Only remaining step is Whitney's: open Settings → Communications, set the
  cadence, and tick "Send Reminders" when ready to go live.** Until then it's completely inert.

### Standing constraints carried forward (unchanged)
- **Never bump version without explicit Whitney approval each time.**
- Reservation **5990 RV map is corrupted** — test stall/RV maps on **NTR 6519** only.
- **PR #36 + the Group Names branch MUST be merged before any version bump** (see branch table below).
- One Bash command per call, no chaining/heredocs (CLAUDE.md command hygiene).
- Local PHP binary: `/Applications/Local.app/.../php-8.2.29+0/bin/darwin-arm64/bin/php`; smoke runner:
  `tests/run-all-smokes.php <wp-path> <php-bin> <wp-cli.phar>` (full suite >10min → run backgrounded).

### Open / deferred
- **`native-events-slice3` smoke — ✅ NOW GREEN (the suite is 100% green, 0 failing / 0 fatal).**
  Fixed a REAL bug while finishing it: a **geocoded native venue rendered NO address** because the
  coords-only relational store row (from resolve/geocode) shadowed the post-meta address. Fix:
  `EEM_Venue::get_detail()` now backfills empty store address fields from post-meta on post-id reads,
  and `maybe_geocode_venue` syncs the address into the store alongside the coords. (v2-gated, but a
  genuine fix.) All 5 venue smokes still pass — no regression from the `get_detail` change.
- **P1 (Auth.net charge-amount verification)** — still deferred to launch-prep; needs your Signature
  Key + a real test charge.

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

### AUDIT STATUS — COMPLETE (2026-06-27). I own findings F1–F9 (here) + P1–P5 (next section).

**✅ VERIFIED CORRECT** (seeded real orders via the actual write path; 100/101 harness assertions
+ surcharge suites): all Edit Reservation pricing — stall/RV base, **Stay Packages + per-package
early-bird**, required shavings, **tack exclusion**, **map tab+zone surcharges (stacked)**, %
convenience fee, sales tax; **Edit Dates** math (lengthen=charge / shorten=refund-owed / fee+tax
on delta — the team's date bugs were already fixed by 671); **refund engine**; **front-end Order
Summary** (every charge has a row incl. Additional Shavings — your original "missing" symptom is
resolved on 671); Order Detail totals + receipt totals.

### FINDINGS (functional / math / scale — F-series)
- **F11** ✅ FIXED 2026-06-27 (Local, awaiting sign-off + deploy) — FLAT convenience fee was
  re-doubled per-row on POST-creation edits (Add Items qty + Edit Dates), the same class F7 fixed at
  insert time. Now flat is left once-per-order across all write paths (insert + add-qty + edit-dates);
  percentage unaffected (and is Whitney's config). Verified `f11-flat-fee-once-smoke.php` 5/5.
- **F10** 🔴 CRITICAL ✅ FIXED 2026-06-27 (Local, awaiting sign-off + deploy) — pre-entries were CHARGED
  to the customer at checkout but DROPPED from the stored order (insert never attached
  `pre_entries_subtotal` to a component row, unlike add-ons/group). A $140 stall + $60 pre-entries order
  charged $208 but saved as $145.60 — $62.40 under-recorded, wrong balance/refunds. Found during the
  "keep auditing surfaces" pass. Fix: attach pre-entries to the row + tax base; subtract in the receipt
  breakdown. Capstone harness now 110/110 with a dedicated pre-entry scenario.
- **F6** 🚨 CRITICAL — order system capped at the **250 most-recent rows** (`get_component_rows`
  `LIMIT 250`). Every single-order lookup (Order Detail, Add Items, Edit Dates, Collect Payment,
  refunds, receipts, confirmation email, payment link) AND **Reports revenue + Dashboard revenue
  UNDERCOUNT** past 250 orders. Orders LIST is separately paginated so it looks fine until you
  click in. **Launch-blocker.** Fix: targeted indexed lookups + uncapped reports/dashboard aggregation.
- **F3** 🔴 HIGH — map "Add New Customer" placeholder orders mis-priced: NO tab/zone surcharge,
  bills 1 night (no dates), sparse order. `ajax_stall_create_placeholder` uses a base-rate-only pricer.
- **F4/F4b/F9** ✅ ALL RESOLVED 2026-06-27 (Local, awaiting sign-off + deploy). **F4** — Add Items
  products + custom items now carry the convenience fee via `compose_order_totals()` (single source
  of truth across Collect Payment / Order Detail / receipt). **F4b** — discounts intentionally do NOT
  recompute the fee (Whitney decision); tax is off globally → moot. **F9** — Group grounds-fee, Group
  rider-deposit, and Pre-Entries are now addable item types (reuse the flat-rate product path; fee
  follows; verified with live 4% fee: $75 group fee → +$3.00). Add-qty + Edit Dates already recompute.
- **F8** ✅ FIXED 2026-06-27 (Local, awaiting sign-off + deploy) — imported-order receipt line items
  recomputed (qty×price×nights) and overshot the correct total on CSV imports w/ custom stay labels
  ($285 line over a $137 total). Now the stall base is DERIVED from the stored subtotal so lines always
  reconcile. Capstone harness 101/101; new `f8-imported-receipt-reconcile-smoke.php` 6/6. (Separate
  follow-up flagged: pre-entry charges may not be stored on component rows — needs a reconcile check.)
- **F7** ✅ FIXED 2026-06-27 (Local, awaiting sign-off + deploy) — FLAT convenience fee was stored
  once per component row (double on stall+RV). Now applied once per order (first row), mirroring the
  tax pattern; percentage untouched. Capstone harness 101/101 (`charge-reconcile-allsurfaces-smoke.php`).
- **F1** 🟡 MEDIUM — production build ships without `tools/` → wp-cli fatals (browser unaffected).
  Fix: include `tools/` in build or guard the require with `file_exists()`. (Workaround applied on Local.)
- **F2** (Low, NOT a bug) refund-math smoke stale (`order_key` schema drift) — test drift only.

### NEW FEATURES (Whitney decisions — V1)
- ✅ **Cash/check removes the convenience fee** — DONE 2026-06-27 (Local, awaiting sign-off + deploy). `waive_convenience_fee()` zeroes the fee on each component + tags notes; `compose_order_totals()` reads the marker so EVERY surface goes fee-free; cash handler recomputes the fee-free balance; Paid Cash tab pre-fills it + shows a waiver hint. Verified 12/12 ($504.40→$485.00, fee $19.40→$0, idempotent). Backend-only; card paths untouched. (Decision #2.)
- ✅ **Payment-link/invoice email button** → "Click here to pay" — DONE (was "Review Invoice & Pay Now"); invoice layout + totals verified.
- ✅ **Remove dev/stub UI references** — DONE ("ported in C7" stripped from Settings → Payments tax help).

### 🔍 SHIP-READINESS AUDIT (2026-06-28, independent fresh-eyes review of 2.7.673)
Full document: `SHIP-READINESS-AUDIT.md` (verdict: genuinely good plugin, ~7/10 today → ~9/10
sellable with Tier 1–3 work). **Every item is now tracked as its own task (#26–#57).** Highlights:
- **Already addressed since 2.7.673:** 2.1 (charge-before-insert / retry double-charge) largely
  solved by P3 in 2.7.674; 2.6 (F10 et al deployed) done; 6.3 (tools/ guard) done.
- **✅ #26 (2.4) FIXED 2.7.675:** per-row % fee rounding diverged stored vs charged by a cent on
  multi-row orders (could strand a customer unpaid). Now split once-per-order with exact remainder
  (mirrors tax / flat-fee F7). Capstone harness 119/119 incl. a $12.62+$12.62 rounding-edge scenario.
- **✅ #27 (1.3) FIXED 2.7.675:** `_eem_oc.php` git-rm'd + gitignored (no longer ships).
- **Remaining trivial ship-blocker:** #40 export-ignore leaked .md.
- **Distribution decision (#38):** WordPress.org (remove GitHub self-updater) vs self-hosted/premium
  (keep it — current model). Gates the readme.txt + updater work.
- Tier 2 concurrency (#28–#31), Tier 4 security (#32, #46–#48), Tier 5 UX (#49–#53), Tier 6 process
  (#54–#55), Tier 3 bloat/slim-down (#40–#45).

**Shipped progress (working down by priority):**
- ✅ **2.7.675** — #26 (2.4 % fee rounding) + #27 (1.3 `_eem_oc.php` removed).
- ✅ **2.7.676** — #31 (2.5 Stripe idempotency keys on all PaymentIntent + refund creates),
  #29 (2.2 atomic order-number via `GET_LOCK` — a DB unique index isn't viable since stall+RV
  rows share one number), #30 (2.3 admin quick-add now takes the `eem_checkout_<rid>` lock +
  rejects a stall already occupied for the window via `units_occupied_in_window`). Verified
  `concurrency-hardening-smoke.php` 6/6; capstone harness 119/119.

### DEFERRED TO-DOS (Whitney 2026-06-27 — capture, do later)
- **Settings IA: new "Taxes & Fees" tab.** Move the Convenience Fee + Tax Rate sections out of
  Settings → Payments into a NEW tab named **"Taxes & Fees"**, positioned directly below the Payments
  tab in the settings nav. Pure relocation — same fields/options/save handlers/option keys; just move
  the rendering + add the nav tab. (Task #24.)
- **FINAL comprehensive audit (quality gate — AFTER the v1 list is done).** A multi-part end-of-build
  review (Task #25): (1) eliminate ALL bloat / dead code / dead or unused styling / legacy CSS from
  prior builds — zero chance of future conflicts; (2) confirm nothing is "duct tape and glue" /
  patched — must be professionally built + dependable; (3) re-run styling + money + process audits;
  (4) **fresh-eyes review** — evaluate the plugin as if handed it cold from a stranger and answer
  honestly: is it well-built, or messy / should-be-built-differently? Must be extremely financially
  dependable, zero tolerance for mistakes. This gates "done."

### FIX ORDER + SIGN-OFF
Suggested order: **F6 → F4/F4b/F9 → F3 → P1 → F8 → F7 → P3 → P5 → cash-fee → F1 → email/stub
cleanup.** Every F/P fix except F1 + email/stub changes charge / refund / payment behavior →
confirm the approach + resulting numbers with Whitney; NO version bump or deploy without
approval. Full per-finding detail in `PAYMENT-CALC-AUDIT.md`; surface-by-surface coverage in
`CHARGE-CHECKLIST.md`. Audit harness: `tests/` + the seed-real-order scripts (charge==stored==Σlines+tax).

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



3. [ ] **Full end-to-end customer checkout sweep** — run a real checkout on the NTR 6519 fixture page. Also the recommended way to seed test data (real checkout writes correct `reservation_id` + notes tag + config-based pricing).

5. [ ] **Postmeta → relational de-coupling Phase 1** — remaining gaps: map snapshots, hybrid blocked-units reads, events/venues/producers/divisions editors still on post-meta. Audit plan: `docs/POSTMETA-AUDIT.md`.




10. [ ] **Hide assignment UI when inventory is Bulk/Quantity** — built on branch (Order Detail hides Manage Stall/RV blocks unless numbered/mapped). Needs your verify + merge.

12. [ ] **Auto-save stall/RV maps to the venue** — built on branch (rolling "Auto-saved (latest)" per venue, empty-guard; smokes pass). Needs your verify + merge.


14. [ ] **Remove "X days" countdown chip** on the event-list flyer card. **BLOCKED** — needs Whitney's mockup before starting.

20. [ ] **TEC event list template** — frontend event-list display for TEC-sourced events (part of the deferred frontend-lists work; scope/design TBD).

21. [ ] **Verify + merge PR #36 (styling + audit fixes, this branch).** All built and pushed, needs your click-through then merge: Import/Export page styling + custom file inputs; list-table bottom-border fix (Events/Customers/Term Categories); Daily Movement footer; invoice/refund/payment-received email + report-PDF design-system parity; CSV import hardening (size/type validation, no DB-error leak); order payment-status whitelist; hourly cart-hold cleanup cron; admin BCC send-failure logging; move_uploaded_file error-suppression fix.

22. [ ] **Bulk-notification unsubscribe.** ✅ BUILT + smoke (`email-optout-smoke.php` 23/0); committed dormant (no version bump). `includes/class-eem-email-optout.php` (`EEM_Email_Optout`): HMAC link keyed off `wp_salt('auth')` (no DB token to leak), opt-out stored in the `eem_email_optouts` option (email→timestamp), public `admin-post.php?action=eem_unsubscribe` handler (verify→record→confirmation page), per-recipient footer + `is_opted_out()` skip-check. Wired into **Notifications `dispatch_batch`** + **Email Customers** (both now skip opted-out + append the footer; return a `skipped` count). **Transactional sends are never gated** (smoke asserts `send_invoice_email_for_order` doesn't touch the opt-out gate). **Verify before activating:** review the rendered footer copy + the unsubscribe confirmation page, then send yourself a test Notification and click Unsubscribe.

23. [x] **Auto payment-reminder for unpaid orders. ✅ BUILT (Option A) — ships DISABLED.** Whitney chose Option A: reuse the proven payment-link email (`EEM_Admin::send_invoice_email_for_order`) so the reminder is byte-for-byte the same hotel-style header/footer + "Click here to pay" email as the manual "Send Payment Link" button (Whitney: "just build it so that it matches the style of our other emails"). New class `includes/class-eem-payment-reminder.php` (`EEM_Payment_Reminder`): daily WP-cron (`eem_payment_reminder_sweep`, scheduled on activation, unscheduled on deactivation), finds unpaid/invoice-sent orders older than a configurable min-age (option `eem_payment_reminder_min_age_days`, default 3), dedupes off the existing "Invoice Sent At" note (option `eem_payment_reminder_repeat_days`, default 7; 0 = remind once), 200/run safety cap, logs each sweep to the activity log. **SAFETY: feature defaults OFF** (`eem_payment_reminder_enabled`) — the cron is scheduled but `run_sweep()` no-ops until Whitney flips the option on after reviewing. Reads `payment_status` only; no payment-dispatch changes. Smoke: `tests/smoke/payment-reminder-smoke.php` (33/0 — due/dedupe/repeat-window logic, default-OFF guard, scheduling, canonical-email reuse, **Settings render + save round-trip**). **Settings UI shipped** — Settings → Communications → "Automatic Payment Reminders" card (enable toggle + First-Reminder-After + Repeat-Every; save (re)schedules the cron on enable). Committed dormant (no version bump → no auto-deploy). **Remaining before live use: Whitney opens Settings → Communications and ticks "Send Reminders" when ready.**

---

## 🛑 Payment security / robustness findings (P-series) — owned by the payment-audit chat

These are v1-blocking, found by the styling chat's code/security pass and HANDED TO the payment
chat (which now owns this whole roadmap). They complement the F-series above (F = math/display/
scale; P = gateway/security/robustness). Fix order is in FIX ORDER + SIGN-OFF above. (Audit
verdict: money math, Stripe signature verification, refund capping, and decimal storage all PASS;
the "critical SQL injection" flag was a verified false positive — safe `prepare()` concatenation at
`stay-packages-repo.php:36`.)

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
