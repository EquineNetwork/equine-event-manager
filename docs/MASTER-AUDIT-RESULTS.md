# MASTER END-TO-END AUDIT — RESULTS MATRIX

Companion to `MASTER-AUDIT-CHECKLIST.md`. One block per seeded reservation;
each surface reconciled to the penny against the real gateway charge.

Status key: ✅ verified · ❌ bug (with fix ref) · n/a not applicable to this order

---

## RES-ALL — order #91717 (reservation 18738) — Stripe checkout — **$1,178.24**

Everything on at once: 2 stalls via map (one in a **Premium $15/night area** on top of
a **$10/night barn surcharge** → Stall Premium **$140**), required + additional shavings,
1 RV, general add-on (Alfalfa), pre-entry (Stall Cleaning), group (2 riders: Grounds Fee
$200 + Deposit $200), convenience fee 4% (**$42.08**), tax 8% (**$84.16**).

Pre-fee/tax subtotal $1,052.00 → + fee $42.08 + tax $84.16 = **$1,178.24**.

| # | Surface | Result | Notes |
|---|---------|--------|-------|
| 1 | Customer Order Summary (live JS) | ✅ | $1,178.24; "Stall Premium $140" line shows |
| 2 | Gateway charge (Stripe) | ✅ | $1,178.24 == displayed == submit |
| 3 | Confirmation email | ✅ **FIXED** | Was SHORT by tax $84.16 — email itemized fee but no Tax line. Fixed 2.7.711; rows now sum to $1,178.24 |
| 4 | PDF receipt | ✅ | 11 line items reconcile to $1,052 pre-fee/tax; all lines incl. Stall Premium $140, group, pre-entry |
| 5 | Hosted receipt page | ✅ | Same `build_order_line_items` builder |
| 6 | Admin Order Detail | ✅ **FIXED** | Was: Stalls Subtotal showed bundled $912, tax mislabeled "Add-Ons", no Group/Pre-Entry/Tax sections. Fixed 2.7.710 — now un-bundles ($450 stall) + itemizes Group $400 / Pre-Entry $50 / Tax $84.16 |
| 7 | Stall & RV Charts | ✅ | 2 stalls assigned in status map; premium area painted on map snapshot |
| 8 | Reports (orders + revenue) | ✅ | Orders total $1,178.24; Revenue $1,052 + $42.08 + $84.16 = $1,178.24 net, $0 refunded |
| 9 | Activity log | ✅ | `order_create` entry present, queryable by order_key |
| 10 | Send Payment Link | n/a | Direct Stripe checkout, no invoice path |

**Bugs found + fixed via RES-ALL:** 2
- **2.7.710** — Order Detail summary breakdown (bundled stall subtotal, tax shown as add-ons, missing Group/Pre-Entry/Tax sections). Guard: `order-detail-breakdown-smoke.php` (12/0).
- **2.7.711** — Confirmation email missing Tax line (rows summed short by the tax amount). Guard: `email-lineitem-reconcile-smoke.php` (4/0).

Note (non-money, flagged for review): the **Shavings report** spreads required shavings across stay-days (2 bags/day × 5 days = 10) as an operational delivery schedule, while the **charge** is the one-time 2 bags ($20). Revenue/order surfaces charge correctly; the shavings report is a stocking/usage view by design. No money impact.

---

## RES-PKG — Stay Package pricing (reservation 15246 "Elite Barrel Race")

Stall packages: Thu-Sun $95 / Fri-Sun $70 / Sat-Sun $40. RV packages: Thu-Sun $120 / Fri-Sun $85 / Sat-Sun $50.
Verified through the **real authoritative engine** (the exact private chain `ajax_create_stripe_payment_intent`
runs: `get_reservation_meta` → `get_reservation_status` → `sanitize_submission` → `resolve_*_tier_submission`
→ `calculate_submission_totals`), driven with package `$_POST` selections.

| Case | Selection | Expected subtotal | Engine result |
|------|-----------|-------------------|---------------|
| A | stall Thu-Sun ×2 + RV Thu-Sun ×1 | 190 + 120 = 310 | ✅ 310 (+ global fee/tax → 347.20) |
| B | stall Fri-Sun ×3 + RV Fri-Sun ×2 | 210 + 170 = 380 | ✅ 380 (+ fee/tax → 425.60) |
| C | stall Sat-Sun ×1, no RV | 40 | ✅ 40 (+ fee/tax → 44.80) |
| D | **mixed**: stall Thu-Sun ×1 + Sat-Sun ×2 | 95 + 80 = 175 | ✅ 175 (+ fee/tax → 196.00) |

Math: each package bills a **flat price once** (not per-night) × quantity; mixed stay types sum; the global
convenience fee + tax layer on the post-package subtotal and reconcile to the penny.

**Bug found + fixed via RES-PKG:** 1
- **2.7.712** — receipts / confirmation email / Order Detail rendered a live package order's stay type as the raw
  identifier **"Pkg_7"** (and a misleading per-night count) instead of the package **name** "Stall Thu-Sun" + "Package".
  `format_stay_type_label()` fell through to `ucfirst()`. Money was always correct; display label only. Latent
  because all 124 existing package orders are imports with human-label stay types. Guard:
  `package-pricing-engine-smoke.php` (7/0 — engine math + label rendering).

| # | Surface | Result | Notes |
|---|---------|--------|-------|
| 1 | Live Order Summary (JS) | ✅ | `get_package_price_map` feeds the JS the same flat price the server charges |
| 2 | Gateway charge | ✅ | engine subtotal == price×qty (cases A–D) |
| 3 | Confirmation email | ✅ **FIXED** | now shows package name (was "Pkg_7") |
| 4 | PDF receipt | ✅ **FIXED** | stall/RV line: name + "Package" units + correct rate |
| 5 | Hosted receipt | ✅ **FIXED** | same builder |
| 6 | Order Detail | ✅ **FIXED** | Stay Type + Nights rows show name + "Package" |
| 7 | Stall & RV Charts | n/a | quantity-mode reservation (no map cells) |
| 8 | Reports | ✅ | imported package orders reconcile (e.g. #IMP-90709 total 310 = 190+120) |
| 9 | Activity log | ✅ | order_create on live path |
| 10 | Send Payment Link | n/a | — |

---

## Standing observation — global convenience fee + tax (for Whitney)

The convenience fee + tax are **global** (Settings → Taxes & Fees, per task #24), not per-reservation. With them
ON, **every** live checkout adds them, even on reservations (like 15246) whose legacy per-reservation
`convenience_fee_enabled` is 0. This is by design per the task-#24 decision, but worth confirming it's intended —
imported orders carry no fee/tax, so live orders will total slightly higher than the equivalent imported ones.
**Test-config note:** the test site currently has fee 4% + tax 8% ON (enabled for the RES-ALL parity test) — must
be restored to OFF before handing the site back.

---
