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

### F5 (to verify) — Edit Dates shorten must say "Refund Owed", lengthen "Balance Due"
**Path:** Order Detail → Edit Dates modal. Lengthen shows "Charge $X more — balance due"
(screenshot confirms). Need to verify SHORTEN shows a refund-owed path with correct
amount, and that the fee/tax recompute on the night delta (not just base × nights).
Pending harness + live check.

(more below as the run continues)
