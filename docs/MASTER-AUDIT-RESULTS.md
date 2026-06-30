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
