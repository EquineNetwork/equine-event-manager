# Payment & Calculation Audit — 2026-06-27

**Goal (Whitney):** Every dollar configured on Edit Reservation (and every Edit-Order
adjustment + custom line item) must display AND calculate correctly on every money
surface. Hunt the "computed-but-dropped-from-display" bug class and the edit-order
recalc bugs that broke the team walkthrough.

**Run mode:** solo, fix-as-I-go, document everything here, report at the end.
Hold version bumps + live-gateway changes for Whitney sign-off.

**Code under audit:** repo synced to `origin/main` @ **v2.7.663**. Local site runs the
deployed build **v2.7.662** (one daily commit behind, #24 = stall-chart display only,
does not touch payment math — valid for this audit).

---

## The 13 money surfaces

Customer: (1) checkout Order Summary [live JS] · (2) confirmation email · (3) hosted
receipt · (4) PDF receipt.
Admin: (5) Order Detail · (6) admin print/receipt · (7) Orders list (Total / **Total
Paid** / Balance) · (8) Create Order · (9) Collect Payment · (10) Dashboard revenue ·
(11) Reports · (12) Activity Log amounts.
Ground truth: (13) actual gateway charge + stored order total/payments.

## Pricing inputs to trace (from Edit Reservation editor)

stall base · per-stall surcharge · barn/map-tab surcharge · early-bird/tiered (package)
· RV base · RV premium/zone surcharge · required shavings · additional shavings
(per-product list) · general add-ons · group grounds-fee + per-rider deposit · tack
(pays stall rate, excluded from required shavings) · convenience fee (global, %/flat) ·
tax (global + per-reservation override) · pre-entries.
Edit-Order only: custom line items (+/- amount) · discount (order adjustments).

## Architecture (source of truth)

- `EEM_Shortcodes::calculate_submission_totals()` (shortcodes ~4720) — THE charge
  calculator. subtotal = stalls + rv + addons + pre-entries + group; + convenience fee
  + tax. **No discount line here** — discounts are order-level adjustments
  (`EEM_Order_Adjustments_Repo`), applied on Create Order / Collect Payment / Order
  Detail. Early-bird is a stay-package rate tier (baked into `get_current_rate`).
- `build_order_line_items()` (~6240) — shared display builder (receipt + email).
  Emits dedicated lines incl. Additional Shavings (6318) + Stall Premium + RV Premium.
- `get_order_stall_breakdown()` (~6953) — splits stall subtotal into base / required
  shavings / additional shavings (per-product JSON), premium-free. 2.3.86 fixed the
  `_en_` prefix bug that zeroed shavings lines.

---

## FINDINGS

### F1 — Production build ships without `tools/` → wp-cli fatals on Local (BUILD/PACKAGING)
**Severity:** Medium (blocks CLI tooling; NOT a customer-facing crash).
**Detail:** `includes/class-equine-event-manager.php:124` does
`if (defined('WP_CLI') && WP_CLI) require_once 'tools/seed-demo-data.php';`. The deployed
build strips `tools/` entirely, so every wp-cli invocation against the Local site throws
a fatal (missing required file). Browser requests are unaffected (WP_CLI undefined).
**Workaround applied:** copied `tools/` into the deployed plugin dir so the harness runs.
**Recommended fix:** either include `tools/` in the build, or guard the require with
`file_exists()`. Flag for Whitney — do not change build pipeline without approval.

---

## TEST BASELINE (deployed v2.7.662, existing smokes)

| Smoke | Result | Covers |
|---|---|---|
| order-totals-math-smoke | 26/0 ✅ | charge calc: early-bird, stall+RV surcharge, group on/off, pre-entries, fee flat/% |
| order-breakdown-cross-surface-smoke | 11/0 ✅ | admin==customer breakdown incl. per-product additional shavings (#00009 bug) |

| admin-totals-reconcile-smoke | 4/0 ✅ | Σ(rows) == stored total, nothing dropped |
| order-edit-math-smoke | 9/0 ✅ | add-items: stall/RV price×qty×nights, %fee carried |
| refund-math-smoke | 3/10 ❌ STALE | see F2 — test-drift, not a product bug |

### F2 — refund-math-smoke is stale (schema drift), NOT a refund bug
**Severity:** Low (test only). **Detail:** the smoke seeds `en_stall_reservations`
with an `order_key` column that no longer exists in the live schema (current columns
have no `order_key`; orders group differently now). The INSERT fails → `component()`
reads an empty row → `payment_status` undefined → 10 cascade failures. The live refund
flow was verified working end-to-end on 2026-06-10. **Action:** refund correctness will
be re-verified via a REAL seeded order through the actual refund engine (not this direct
seed); the smoke itself should be rewritten to the current schema (flag, low priority).

**Key methodology pivot:** direct-table-seed smokes are drifting from schema. The
reliable verification path (per ROADMAP #3) is to create orders through the REAL
checkout/order-creation code, then read all surfaces back. Shifting the live-scenario
phase to that approach.

---

## MASTER CHARGE-INPUT WIRING MATRIX (from CURRENT editor, not the seeder)

Whitney's key concern: lots added to Edit Reservation in the last week (esp. **Stay
Packages**) may exist in the UI but never wired to charge/display. For each input:
charged? (read by `calculate_submission_totals`) · persisted? · displayed (shared
`build_order_line_items` / `get_order_stall_breakdown`)? Verdicts below are from code
trace; **empirical seed-and-render proof pending (harness)**.

| Charge input | Editor field(s) | Charged? | Displayed line | Notes |
|---|---|---|---|---|
| Stall base (nightly/weekend/weekly) | stall_*_rate, stall_pricing_mode | ✅ get_current_rate | "Stall Res." | |
| Stall Early Bird (nightly) | stall_early_bird_nightly_rate + _cutoff | ✅ get_current_rate (cutoff window) | folded into Stall Res rate | NEW-ish |
| **Stay Packages (stall)** | stay_packages table: price | ✅ get_current_rate pkg_<id> | Stall Res (pkg price ×1) | **NEW last week** |
| **Per-package Early Bird (stall)** | stay_packages.early_bird_price | ✅ get_current_rate (10607) | folded into pkg price | **NEW last week** |
| Stall premium/zone surcharge | map surcharge / tiers | ✅ get_stall_zone_surcharge_for_units | "Stall Premium" | smoke 10/0 |
| Tack stall | stall_tack_mode, preferred_tack_stall | ✅ pays stall rate, excl. req shavings | within Stall Res | |
| Required shavings | required_shavings_per_stall, _price | ✅ | "Required Shavings" | display rereads price from config |
| Additional shavings (per-product) | additional_shavings_products[].price | ✅ per-product JSON | "Additional Shavings" | **NEW (mig-034)**; #00009 bug area |
| RV base | rv_*_rate, rv_pricing_mode | ✅ | "RV Res." | |
| RV Early Bird + packages | rv_early_bird_*, rv stay_packages | ✅ get_current_rate | RV Res | **NEW last week** |
| RV premium/zone surcharge | eem_rv_rows[].nightly_surcharge | ✅ get_rv_zone_surcharge | "RV Premium" | smoke 9/0 |
| General add-ons | general_addons[].price | ✅ | "General Add-On" | |
| Group grounds fee | group_rider_grounds_fee_amount | ✅ | "Group Res." | task #5 |
| Group rider deposit | group_rider_deposit_amount | ✅ | "Group Res." | task #5 |
| Pre-entries | pre_entry_*_qty | ✅ | "Pre-Entry" | |
| Convenience fee (GLOBAL now) | Settings→Payments | ✅ calculate_convenience_fee | "Fee" | moved from editor last week |
| Tax (global + override) | Settings + per-res override | ✅ | tax row | |
| **Custom line item (order screen)** | Create/Edit Order: description+amount | order-adjustments | must surface on receipts | **verify pos & NEG** |
| **Discount (order screen)** | discount_type/value/reason | order-adjustments | navy chip + line | reason REQUIRED |

**Code-trace verdict:** every editor charge input IS read by the calculator — no
obviously-unwired field found. The risk is narrower than "never wired": (a) DISPLAY
label/line gaps on newer inputs (packages may render "pkg_5 stay"), (b) the live
customer JS Order Summary, (c) edit-order recalc, (d) custom line item / discount
surfacing on receipts. **Harness will seed each input for empirical proof.**

### F3 — Manual map "Add New Customer" placeholder orders are MIS-PRICED (HIGH) 🔴
**Path:** Stall/RV Chart map popover → "Add New Customer" → Save & Assign →
`eemSmapCreatePlaceholder()` (admin.js:7454) → `ajax_stall_create_placeholder()`
(class-equine-event-manager-admin.php:7288).
**Whitney's report:** "manually create a customer on the map → it creates an order →
totals were wrong and the Edit Order screen was missing a lot of data."
**Root cause — the placeholder prices via `price_base_rate_addition()` which is BASE
RATE ONLY:**
- **(a) NO surcharge.** A specific stall is chosen (may be premium/surcharged barn+zone)
  but `price_base_rate_addition` explicitly adds no surcharge ("no specific unit → no
  surcharge", shortcodes.php:4862). Premium stalls are UNDERCHARGED; no "Stall Premium"
  line on Edit Order. Fix: resolve the picked stall's surcharge via
  `get_stall_zone_surcharge_for_units` / `EEM_Stall_Map_Importer::surcharge_for_unit`
  (needs barn context for the label) and add `surcharge × nights` to subtotal.
- **(b) NO dates → 1 night.** The map quick-add form (`showAddCustomerForm`, admin.js:7653)
  collects only first/last name; `eemSmapCreatePlaceholder(container,label,f,l)` passes no
  arrival/departure, so nights = `max(1, …)` = 1 regardless of the event's real multi-night
  stay → UNDERCHARGE on multi-night events. Fix: prefill the modal with the reservation's
  stay window (or require dates).
- **(c) Sparse order.** Only stall base + required shavings + fee + tax; no additional
  shavings / add-ons / group / pre-entries; `event_source='placeholder'`, empty
  email/phone → Edit Order screen looks "missing data."
**Severity:** HIGH — real undercharge on premium stalls + multi-night. Charge-math change
→ **flagged for Whitney sign-off before implementing** (do not silently alter charge).
**Checklist impact:** adds a whole "ORDER-CREATION PATHS" dimension — every path that
mints an order must inherit the full reservation pricing, not just customer checkout.

### F4 — Add Items: products + custom items get NO convenience fee / tax (HIGH) 🔴
**Path:** Order Detail → Add Items → `ajax_add_items()` (class-eem-order-detail-page.php:2746).
- **Stall / RV** → `add_component_quantity($order_key, $type, $qty, $priced)` — $priced carries
  fee config + tax; modal says "Fees & tax calculated on save." ✅
- **Product (Additional Shavings / General Add-On)** → `EEM_Order_Adjustments_Repo::insert_custom_item()`
  as a FLAT amount (price × qty). **No convenience fee, no tax.** ⚠️ At checkout these
  same products ARE in the subtotal the convenience fee + tax compute on — so adding them
  later UNDERCHARGES the fee/tax vs the customer path. Modal hint reads only "Charge: $10.00".
- **Custom line item** → also flat via `insert_custom_item()`, no fee/tax (may be intended —
  see QUESTION Q1).
**Severity:** HIGH — inconsistent fee/tax = revenue under-collection on the most common
"customer forgot an add-on, admin adds it later" flow (Whitney's exact words).
**Open question Q1 (asked Whitney):** should the convenience fee + tax apply to (a) added
products [likely YES — match checkout], (b) custom line items [ambiguous]?
**Q1 ANSWERED — yes, fee+tax on EVERY line item.** CONFIRMED at the composition layer:
`EEM_Collect_Payment_Page` line 128 `$total_due = $base_total + $custom_total - $discount_amt`
— custom items/products are added at FLAT amount; `$fees` is the original component-row fee,
NOT recomputed to include custom items, and no tax is applied to them. So every product/
custom item added via Add Items undercharges fee+tax vs the checkout path.
**Related concern (F4b):** the same line subtracts `$discount_amt` from `$base_total` WITHOUT
recomputing the convenience fee + tax on the POST-discount subtotal — but the roadmap
Discount-handling spec says fee + tax should recalc from the post-discount subtotal. So a
discount may leave the fee/tax computed on the pre-discount amount (customer overpays fee+tax
after a discount). Needs explicit verification + decision (likely a bug per the spec).

### F5 (to verify) — Edit Dates shorten must say "Refund Owed", lengthen "Balance Due"
**Path:** Order Detail → Edit Dates modal. **CODE-VERIFIED CORRECT on v2.7.671**
(`handle_ajax_edit_dates`, admin.php:10689): lengthen (delta>0, 'charge') adds
`unit×qty×delta` + fee delta + tax delta → Balance Due, flips paid→partially_paid; shorten
(delta<0, 'reduce') lowers subtotal/fee/tax/total — paid order then shows Refund Due (manual
refund on demand), unpaid order's balance shrinks. The two exact bugs Whitney hit have
fix-comments in-code: (a) multi-stall orders billed only 1 stall (now `stall_qty+tack`), (b)
fee/tax dropped on the delta (now recomputed via price_base_rate_addition's global fee/tax).
Subset/split + per-stall apply present. **Remaining: confirm live in browser** (math reads
correct; the team's breakage was apparently fixed 662→671). NOT currently a bug.
**Watch:** the flat-fee delta logic is `calc_fee(new_sub)` = flat value, so fee_add for a
single-component lengthen = 0 (flat fee doesn't grow) — correct; but cross-check against F7
(flat fee on multi-component at CREATE time is still double-counted — different code path).

### F6 — 🚨 CRITICAL: order system is hard-capped at the 250 most-recent rows
**File:** `includes/class-equine-event-manager-orders-repository.php` — `get_component_rows()`
line 2829: `SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 250` (then `array_slice 250`).
**Impact:** `get_grouped_orders()` only ever sees the 250 newest stall rows + 250 newest RV
rows. EVERY single-order operation routes through it:
- `get_order($order_key)` (line 128) → linear scan of the capped set → **Order Detail**,
  **Add Items**, **Edit Dates**, **Collect Payment**, **refunds**, **print/receipt**.
- `get_order_by_submission_token` / `get_order_by_invoice_token` / `get_order_by_order_key`
  → **confirmation email**, **hosted receipt**, **PDF**, **payment link**.
**Consequence:** once a venue accumulates >250 stall (or >250 RV) component rows — a single
large event can do this alone — the OLDEST orders silently become **unreachable**: cannot be
viewed, charged, refunded, or receipted. They still exist in the DB and on the (separately
paginated) Orders LIST, but every action that opens a specific order fails to find it.
**NOT mitigated by the list page:** the Orders list uses a different repo
(`EEM_Orders_List_Repo::get_paginated`, 25/page, uncapped) — so orders APPEAR in the list but
their detail/receipt/refund actions 404. That divergence makes it look fine until you click.
**Severity:** CRITICAL / launch-blocker for a high-volume payment system.
**Recommended fix (architectural — flag for Whitney, do NOT silently patch):** single-order
lookups must query the specific order's rows directly by order_key/token/order_number
(indexed WHERE), not rebuild + scan a capped full-table grouping. At minimum remove/raise the
250 cap, but the real fix is targeted queries. Touches the core order repo → Whitney sign-off.
**Currently on Local:** 222 stall / 181 RV rows after cleanup — just under the cap, so not yet
triggering, but it WILL in production.
**BLAST RADIUS CONFIRMED — every aggregate money surface is hit, not just single-order lookups:**
- **Reports** (`EEM_Reports_Repo`, line 78: `orders_repo->get_orders()`): Revenue report, Orders
  report, Reservations summary, Refund Log all iterate the capped set → **revenue UNDERCOUNTS**
  once >250 orders. You cannot trust the revenue numbers.
- **Dashboard** (`EEM_Dashboard_Repo`, line 101: reflects `get_grouped_orders`): Total Revenue
  KPI, Revenue-by-Reservation chart, This Week, Recent Orders all undercount.
- The ONLY uncapped order surface is the Orders LIST (`EEM_Orders_List_Repo::get_paginated`).
This elevates F6 from "old orders unmanageable" to "**revenue reporting is silently wrong past
250 orders**" — unambiguous launch-blocker.

---

## HARNESS ENGINE — BUILT & GREEN (task #7 done)
`scratchpad/charge-audit-harness.php` seeds real orders via the actual write path
(`insert_reservation_orders`), reloads via the consumer, and asserts per surface. Core
6-scenario baseline on **v2.7.671: PASS=36 / FAIL=0** — charge math, persistence, reload,
reconciliation invariant (Σ lines + tax == stored total == charge), and every expected line
present (incl. Additional Shavings as its OWN line — #00009 class NOT reproduced here).
**Note:** convenience fee is GLOBAL now and currently DISABLED in Settings → Payments, so
these scenarios ran fee=0 (correct for current config). Next: enable global fee + tax and
re-run; expand matrix to packages, per-package early-bird, tack exclusion, required shavings,
map tab+zone surcharges, multi-product, discounts.
**Harness lessons (not product bugs):** (a) submission tokens MUST be pure hex+hyphen —
`extract_submission_token_from_notes` matches `/[a-f0-9-]+/i`; (b) `get_grouped_orders`
caches per-instance — use a fresh repo per reload; (c) reserve_order_number repeats within
one CLI request (object-cache quirk; not a production path).

### F7 — FLAT convenience fee double-charged on multi-component orders (MEDIUM)
**File:** `public/class-equine-event-manager-shortcodes.php` `insert_reservation_orders()`
line ~5476: `$row_fee = $this->calculate_convenience_fee( $row_subtotal, $data );` — computed
PER component row. For a PERCENTAGE fee this is linear and correct (4%·stall + 4%·rv =
4%·total). For a FLAT fee it returns the full flat amount on EVERY row, so a stall+RV order
persists the flat fee TWICE.
**Proof (harness scenario 7, flat $25, stall+RV):** charge total = $197.80 (subtotal $160 +
$25 fee once + $12.80 tax) — but stored/grouped total = $222.80 (fee counted twice).
**Impact:** the customer is charged correctly ($197.80 via `$totals['total']`), but Order
Detail / receipt / Orders-list TOTAL and BALANCE are overstated by one extra flat fee per
extra component — a fully-paid order shows a phantom $25 balance due.
**Scope:** only when the global fee TYPE is `flat` AND the order has >1 component (stall+RV).
Current Local config is `percentage 4%` → NOT affected today, but the flat option exists.
**Fix (charge-math — flag for Whitney):** apply a flat fee ONCE per order (e.g. on the first
persisted row only, mirroring how tax is split — stall row takes it, RV row takes 0), so the
sum of row fees equals the calculator's single flat fee.

### Stay Packages + per-package Early Bird — VERIFIED CORRECT ✅
Harness scenarios 8 & 9 (v2.7.671): package priced at $150 (×1, billed once) and $120 when
the early-bird window is active — both charged, persisted, displayed, and reconciled to the
penny. The last-week Stay Packages work is wired end-to-end. Percentage fee + 8% tax also
reconcile across all 8 other scenarios. Harness now: **80 / 81 assertions pass** (the 1 fail =
F7 flat-fee).

### F8 — 🟡 MEDIUM (DOWNGRADED after browser+render verify): receipt LINE ITEMS diverge on IMPORTED orders only
**CORRECTED SCOPE — not an overcharge, not Order Detail, not the totals:**
- Admin **Order Detail = CORRECT** (uses stored `stall_subtotal`; #IMP-90697 shows $137 ✓).
- Receipt **Subtotal + Grand Total = CORRECT** (stored-derived: `total − fees − tax`; $137 ✓).
- Only the receipt/email/PDF **itemized LINE ITEMS + per-section breakdown** are recomputed via
  `get_order_stall_breakdown` (`qty × unit_price × billable_stay_units`) and can diverge.
- **Proven:** #IMP-90697 receipt shows a "Thursday-sunday" line of **$285** above a Subtotal/
  Total of **$137** — internally inconsistent (line ≠ total), but the customer is charged the
  correct $137.
**Scope:** ONLY orders whose stored `unit_price × nights ≠ stored subtotal` — i.e. CSV-IMPORTED
orders with custom/weekend stay-type LABELS ("Thursday-Sunday") that `get_billable_stay_units`
doesn't treat as bill-once. **Real checkout orders are unaffected** (stay types are
`nightly`/`weekend`/`pkg_*`, which compute consistently — all seeded harness orders reconciled).
**Impact:** imported-order receipts look broken (line items don't match the correct total).
Not an overcharge. **Severity MEDIUM** (was provisionally HIGH before render-verify).
**Fix:** derive receipt line items from stored amounts (base = stored stall_subtotal − shavings
− surcharge), OR make the importer store a consistent unit_price/nights/subtotal, OR have
get_billable_stay_units recognize imported stay labels. Single-source-of-truth per roadmap #9.
**(ORIGINAL provisional finding kept below for trail:)**
### F8-orig (provisional, superseded by the corrected scope above)
receipts/Order Detail RECOMPUTE lines from rate×nights, diverge from charge
**File:** `get_order_stall_breakdown()` (shortcodes ~6976) computes
`row_base = stall_quantity × unit_price × get_billable_stay_units(arrival,departure,stay_type)`
— i.e. the displayed line is RECONSTRUCTED from rate×qty×nights, NOT taken from the stored
charged subtotal. `build_order_line_items` then renders that. The same applies to per-bag
shavings (recomputed at config price) and RV.
**Divergence proven on REAL orders (60 checked, only 2 reconciled):**
- #IMP-90707 (imported, weekend "Friday–Sunday" stay): stored $70 (billed once), receipt
  recomputes `1×$70×2 nights = $140` → **receipt shows 2× the charge.** Cause:
  `get_billable_stay_units` returns 1 only for literal `weekend`/`weekly`/`pkg_*`; a custom/
  imported stay-type label falls through to a raw night count.
- #0002 (NOT imported): receipt $539.40 vs stored $504.40 — stall lines over by one night.
- Pattern across the IMP-* set: receipts ~2× the stored/charged total.
**Why seeded orders reconcile:** calculator-created NIGHTLY orders have
`subtotal == qty×price×nights` by construction, so the recompute happens to match. The bug is
masked for that path and EXPOSED for imports, weekend/custom stay labels, and edited orders.
**Impact:** customer receipt + admin Order Detail show totals that disagree with what was
actually charged (the #00009 class, root-caused). Imported orders (Whitney uses CSV import)
are dramatically wrong.
**Fix direction (flag for Whitney):** display must reconcile to the STORED charged amounts —
either derive the base line from `stored stall_subtotal − shavings − surcharge` (don't
recompute from rate×nights), or make `get_billable_stay_units` authoritative for every stored
stay-type. Roadmap #9 intended this single-source-of-truth fix but `get_order_stall_breakdown`
still reconstructs. Touches every receipt surface → sign-off + careful re-verify.
**Minor:** `date_create_from_format(null)` deprecation at shortcodes.php:11155 (null dates) —
fold into the same fix.

### F9 — Add Items can't add Group fees or Pre-Entries (MEDIUM, UX/feature gap)
**File:** `render_add_items_modal` (order-detail-page.php:2363). Item types offered: Stall, RV
(from `get_addable_inventory`), Additional Shavings + General Add-Ons (from
`get_addable_products`), Custom Line Item. **NOT offered: Group reservation fees (grounds fee
+ rider deposit), Pre-Entries.** Whitney's stated use case "customer forgot to pay their group
reservation fees, add them later" is unsupported — the only workaround is a Custom Line Item,
which (F4) carries no fee/tax and isn't itemized as a group charge.
**Fix:** add Group grounds-fee + rider-deposit and Pre-Entry as addable item types (priced
from reservation config, fee+tax applied per F4 fix).

### F4/F4b/F9 ROOT CAUSE (one issue, several symptoms)
The order's convenience fee + tax are FROZEN at checkout-time. Post-creation adjustments do
NOT recompute them: custom items + products added flat (F4); discount subtracts without
recomputing fee/tax on post-discount subtotal (F4b); group/pre-entry not addable at all (F9).
The paths that DO recompute correctly: Add stall/RV qty (`add_component_quantity`) and Edit
Dates (per-row fee/tax recompute). Fix should make the order's fee + tax DERIVED from the
current (components + custom items − discount) subtotal everywhere they're displayed/charged,
so every surface and every adjustment stays consistent.

(more below as the run continues)
