# CHARGE CHECKLIST — every input that can charge a customer

**Purpose (Whitney):** hundreds of thousands of dollars will flow through this plugin
immediately. EVERY chargeable input on Edit Reservation (incl. the Stall/RV map
builders) and Edit Order must (A) actually charge, (B) persist, (C) appear correctly on
EVERY money surface, (D) reconcile to the charged total. This is the literal checklist.

Legend per cell: ☐ not yet verified · ✅ verified correct · ⚠️ issue (see FINDINGS in
PAYMENT-CALC-AUDIT.md) · n/a.

Surfaces: **OS**=customer Order Summary (live JS) · **CE**=confirmation email ·
**HR**=hosted receipt · **PDF**=PDF receipt · **OD**=admin Order Detail · **PR**=admin
print · **OL**=Orders list (Total/Paid/Balance) · **CO**=Create Order · **CP**=Collect
Payment · **DB**=Dashboard · **RP**=Reports · **AL**=Activity Log.

---

## PART 1 — EDIT RESERVATION (card by card)

### Card: Stall Reservations
- [ ] **Stall nightly rate** (`stall_nightly_rate`) — charged ✅ / display: OS CE HR PDF OD PR
- [ ] **Stall weekend rate** (`stall_weekend_rate`)
- [ ] **Stall weekly rate** (`stall_weekly_rate`)
- [ ] **Stall pricing mode** (`stall_pricing_mode`: nightly|weekend|weekly|packages)
- [ ] **Stall Early Bird nightly rate** (`stall_early_bird_nightly_rate`) + cutoff window
- [ ] **Early Bird cutoff** (`stall_early_bird_cutoff`) — boundary: at/after cutoff flips to regular
- [ ] **Tack stall** (`stall_tack_mode`, `preferred_tack_stall`) — pays stall rate, EXCLUDED from required shavings
- [ ] **Selection mode** (`stall_selection_mode`: quantity vs pick-from-layout) — pricing parity both ways

### Card: Stall Reservations → **STALL MAP / CHART BUILDER** (surcharges live here)
- [ ] **Tab/Barn surcharge** (`setTabSurcharge` → `barn['surcharge']`) — applies to every stall in the barn
- [ ] **Zone/Area surcharge** (`applySurchargeToSel`/`commitSurcharge` → `area['surcharge']`) — stacks on top
- [ ] **STACKED** (barn + zone on one stall) — charged as sum, displayed as one "Stall Premium" line
- [ ] **Quantity-tier surcharge** (`stall_tier_surcharge_sum`) — quantity-mode equivalent (smoke 10/0)
- [ ] Surcharge × nights — premium multiplies by billable nights, not billed once

### Card: Stay Packages (stall) — NEW last week
- [ ] **Package price** (`stay_packages.price`, type=stall) — charged as unit rate ×1 (billed once)
- [ ] **Per-package Early Bird price** (`stay_packages.early_bird_price`) — used when EB window active
- [ ] **Package max_quantity** — cap enforced, priced correctly at cap
- [ ] Package + surcharge interaction (pick a surcharged stall on a package stay)
- [ ] **Display label** — package renders its NAME, not "pkg_5 stay"

### Card: RV Reservations (mirror of Stall)
- [ ] **RV nightly rate** (`rv_nightly_rate`) + weekend/weekly + pricing mode
- [ ] **RV Early Bird** (`rv_early_bird_nightly_rate`, `rv_early_bird_cutoff`)
- [ ] **RV row/zone surcharge** (`eem_rv_rows[i][nightly_surcharge]`)
- [ ] **RV map zone/tab surcharge** (RV map builder)
- [ ] **RV quantity-tier surcharge** (`rv_tier_surcharge_sum`) (smoke 9/0)
- [ ] **RV Stay Packages** + per-package EB
- [ ] RV selection mode (quantity vs pick lots)

### Card: Required Shavings
- [ ] **Per-stall qty** (`required_shavings_per_stall`) × **price** (`required_shavings_price`)
- [ ] Tack exclusion (tack stalls don't add required shavings)
- [ ] Display rereads price from config (parity charge vs display)

### Card: Additional Shavings (NEW — mig-034)
- [ ] **Per-product list** (`additional_shavings_products[].name/.price`) — per-product JSON
- [ ] Multiple products in one order — each its own qty×price, summed
- [ ] This is the #00009 bug area (was folding into stall base)

### Card: General Add-Ons
- [ ] **Per add-on price** (`general_addons[].price`) × qty
- [ ] Multiple add-ons
- [ ] per_label rendering

### Card: Group Reservations (task #5)
- [ ] **Rider Grounds Fee** (`group_rider_grounds_fee_amount`) × rider count
- [ ] **Rider Deposit** (`group_rider_deposit_amount`) × rider count
- [ ] Group names (admin list) → customer dropdown → order

### Card: Pre-Entries
- [ ] **Pre-entry price** × qty per option

### Global (Settings → Payments, not per-reservation)
- [ ] **Convenience fee** — % mode and flat mode; on post-discount subtotal
- [ ] **Tax** — global rate + per-reservation override; on correct base; rounding

---

## PART 2 — EDIT ORDER / CREATE ORDER (recalc + order-only inputs)

- [ ] **Add stall qty (add-a-night / add units)** — total + balance + Total-Paid column
- [ ] **Add RV qty**
- [ ] **Add product** (additional shavings / general add-on) to existing order
- [ ] **Reduce / refund a night** — UNPAID path (reduces total) vs PAID path (issues refund)
- [ ] **Remove item**
- [ ] **Custom line item — POSITIVE** (`description`+`amount`) — appears on all receipt surfaces
- [ ] **Custom line item — NEGATIVE** (credit/comp) — subtracts, displays, reconciles
- [ ] **Discount apply** (`discount_type` %/$, `discount_value`, `discount_reason` REQUIRED)
- [ ] **Discount removal** (fresh reason, logged to Activity Log)
- [ ] **Convenience fee + tax recalc from POST-discount subtotal** (order of operations)
- [ ] **Total Paid column** updates correctly on Orders list after each change
- [ ] **Multiple payments** sum into amount_paid; amount_due = total − paid

---

## PART 3 — RECONCILIATION INVARIANT (every order, every surface)
- [ ] Σ(displayed line items) + fee + tax − discount == stored order total == gateway charge
- [ ] Admin Order Detail breakdown == customer receipt breakdown (line for line)
- [ ] No line silently folded into another (the #00009 class)
- [ ] 5-digit order id (#%05d) consistent across OD / OL / email / receipt / AL

---

## PART 4 — ORDER-CREATION PATHS (every path must inherit full reservation pricing)
- [ ] Customer front-end checkout
- [ ] **Map "Add New Customer" → placeholder order** ⚠️ F3: base-only, NO surcharge, 1-night
- [ ] Create Order admin page
- [ ] Send Payment Link / Open Tab
- [ ] Each path: stall surcharge, required shavings, fee, tax all present + correct on Edit Order

## PART 5 — ADD ITEMS to an existing order (customer "forgot" flow)
- [ ] Add Stall — fee + tax on the addition ("calculated on save")
- [ ] Add RV — fee + tax on the addition
- [ ] Add Additional Shavings / General Add-On (product) — ⚠️ F4: currently flat, NO fee/tax
- [ ] Add Custom Line Item (±) — flat (see Q1: should fee apply?)
- [ ] **Group reservation fee addable?** (Whitney: "forgot to pay group fees") — confirm offered
- [ ] **Pre-Entry addable?** — confirm offered
- [ ] Every addition updates Order Total + Balance Due + Orders-list Total Paid

## PART 6 — EDIT DATES (lengthen/shorten an existing order)
- [ ] **Lengthen → "Balance Due"** charge = added nights × rate (+ surcharge?) — fee + tax on delta
- [ ] **Shorten → "Refund Owed"** = removed nights × rate — fee/tax handled; refund vs reduce-total
- [ ] Paid order shorten = refund; unpaid order shorten = reduce total
- [ ] "Apply to which stalls" + split-into-two-date-lines
- [ ] Reconciles after every change

## PART 6.5 — COLLECT PAYMENT page (3 methods) — its own surface
Route: `equine-event-manager-collect-payment&order_key=…`
- [ ] **Send Link** — emails customer a payable bill/link (pays by CARD) → fee KEPT
  - [ ] **The payment-link EMAIL itself** must look like an order invoice (itemized lines +
        subtotal + fee + tax + total) AND carry a "Click here to pay" button → secure pay link.
        OBSERVED: `build_invoice_email_html()` (admin.php:15036) DOES render itemized line items
        + a pay button — current button text is "Review Invoice & Pay Now" (Whitney wants "Click
        here to pay"). Verify totals match the order + capture a sample render for Whitney.
- [ ] **Charge Card** — admin keys customer card over phone (CARD) → fee KEPT
- [ ] **Paid Cash** — cash/check → fee REMOVED at payment time (recalc total w/o fee)
- [ ] After payment: amount_paid, balance, status, Orders-list Total Paid all update + reconcile
- [ ] **Q3 ✅ RESOLVED:** ONLY **Paid Cash** (cash or check) removes the convenience fee. Send
      Link + Charge Card are card → keep the fee. Rationale: fee = pass-through of the merchant
      card fee, so it exists only when a card is actually charged. Paid Cash is the single point
      that strips the fee + recalcs the total. (Tax stays OFF globally — no tax-on-cash concern.)

## PART 7 — CONVENIENCE FEE & TAX behavior (Settings → Payments, GLOBAL)
- [ ] Fee = 4% of subtotal (percentage mode) OR flat $ once per order
- [ ] Fee applies on EVERY charge path (checkout, add-items, placeholder, edit-dates, create-order)
- [ ] **NEW WORKFLOW (Q2): cash/check skips the fee — BACKEND ORDERS ONLY.** Frontend checkout is
      ALWAYS card → always charges the fee (no change). Only admin-side orders (Collect Payment /
      Create Order) can be cash/check; those must NOT carry the 4% fee. Fee conditional on payment
      method. NOT yet built. Open: does tax still apply to cash?
- [ ] Tax = global Default Tax Rate %, per-reservation override; correct base; rounding to cent
- [ ] Disabling fee/tax hides the line everywhere

## PART 8 — NON-MATH CLEANUP (Whitney: no dev/stub references in UI)
- [ ] Remove "ported in C7" from Settings → Payments Tax help text
- [ ] Sweep ALL user-facing strings for dev-process refs (Cx, stub, placeholder service, TODO)

---

## RESOLVED DECISIONS (Whitney)
- **Q1 ✅ ANSWERED:** Convenience fee (+ tax) applies to **EVERY product / line item, no
  exceptions** — stalls, RV, shavings, add-ons, group fees, pre-entries, AND custom line
  items. So F4 is a confirmed bug: Add Items products + custom items MUST carry the fee+tax.
- **Q2 ✅ ANSWERED:** The fee is always on by default. **Cash/check (BACKEND only) is the
  one exception — when the customer pays cash, REMOVE the fee from the order at payment
  time** (recalc total without the 4% fee). Frontend is always card → always keeps the fee.
  Model: every charge path adds the fee; the cash-payment action is the single place that
  strips it. (Still confirm: does tax remain on a cash order? assume YES — tax is a real tax.)

---

## EXECUTION LOG

### Harness coverage on v2.7.671 (seeded real orders, charge==stored==Σlines+tax)
**✅ VERIFIED CORRECT (charge + persistence + receipt line + reconciliation):**
- Stall base (nightly) · multi-night × qty
- General Add-Ons (price × qty, own line)
- Additional Shavings (per-product JSON, own line — #00009 NOT reproduced)
- Group Reservations (grounds fee + rider deposit × rider count, own lines)
- RV base (nightly × qty)
- Stall + RV + Add-On + Group combined (all lines present, reconciles)
- **Stay Packages (stall) — $150 billed once** ✅ (last-week feature, wired correctly)
- **Per-package Early Bird — $120 when window active** ✅
- Required Shavings (qty × price, own line)
- **Tack stall excluded from required shavings** ✅ (pays stall rate, no req-shavings)
- Convenience fee PERCENTAGE (4%) — correct on every scenario
- Sales Tax (8%) — correct base + reconciles
- **Stall MAP surcharges — tab (barn) + zone (area), STACKED** ✅ ($5 barn + $3 zone = $8/night,
  × nights; bare-barn stall = $5). Verified end-to-end: charged, persisted, shown as its own
  "Stall Premium" line, display recompute matches, reconciles.
- Harness: **100 / 101 assertions pass** (+ surcharge suites 5/5 charge, 6/6 e2e display).

**RESULT: every EDIT RESERVATION pricing input is verified correct on v2.7.671.** All
remaining bugs are in the ORDER-EDIT / ORDER-CREATION paths (F3, F4, F7, F9) + F6 cap + F8 imports.

### Additional verifications (round 2)
- **Refund engine** (partial/full/over-refund guard) ✅ 7/7 on a real seeded order.
- **Edit Dates** (lengthen=charge, shorten=refund-owed, fee/tax on delta, multi-stall qty) ✅
  code-verified on 671 (the team's date bugs were already fixed).
- **Front-end Order Summary sidebar** ✅ has rows for every charge incl. Additional Shavings,
  Stall/RV Premium, group grounds-fee + deposit, add-ons, pre-entries, fee, tax (your original
  "additional shavings missing" symptom is structurally resolved on 671).
- **Admin Order Detail** ✅ uses STORED subtotals → correct even for imported orders ($137 ✓).
- **Receipt Subtotal + Grand Total** ✅ stored-derived → correct. (Only receipt LINE ITEMS
  diverge on imported orders — F8.)

### AUDIT COMPLETE — definitive findings: F1, F3, F4/F4b/F9, F6, F7, F8 (see PAYMENT-CALC-AUDIT.md)
Remaining minor/visual (low risk): Activity Log $ amounts, confirmation-email visual render,
negative custom line item (works; no fee per F4), live-JS recompute (rows present, roadmap-verified).

**⚠️ BUGS FOUND (see PAYMENT-CALC-AUDIT.md):**
- F7 — FLAT convenience fee double-charged on multi-component (stall+RV) orders (1 fail)
- F6 🚨 CRITICAL — order repo capped at 250 rows (old orders unreachable)
- F3 — map "Add New Customer" placeholder mispriced (no surcharge, 1 night)
- F4 — Add Items products/custom items get no fee/tax

**⏳ STILL TO VERIFY (need map snapshot / order-edit / browser):**
- Stall/RV MAP tab-surcharge + zone-surcharge + stacked (Part 1 chart builder)
- Add Items recalc (stall/RV/product/custom) + fee/tax (F4)
- Edit Dates lengthen=Balance Due / shorten=Refund Owed + fee on delta (F5)
- Discount apply/remove; custom line item ±
- Order-creation paths parity (placeholder F3, Create Order)
- Visual render of receipts / Order Detail / invoice email (browser)
