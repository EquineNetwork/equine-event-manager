# V1 Full Verification Pass — Coverage Matrix & Gap Status

_Living doc. Tracks the "verify every pricing combination reconciles across every money surface" launch gate (ROADMAP #62). Started 2026-07-01._

## Method

Two layers of verification:
1. **Behavioral smokes (in-process)** — drive the REAL engine (`calculate_submission_totals` →
   `insert_reservation_orders` → the real render methods) and reconcile each surface to the penny.
   This catches math + surface-divergence bugs repeatably. The bulk of the matrix.
2. **Real browser checkout (with Whitney)** — a maximal test order through the live form + Stripe test
   mode, confirming the **customer-facing JS Order Summary** equals the actual charge. This is the one
   surface no in-process smoke can reach (it's rendered by JS in the browser).

## Surfaces (each order must reconcile across all)

CO-JS = customer checkout Order Summary (JS) · CHG = gateway charge · OD = admin Order Detail ·
PDF = PDF receipt · HOST = hosted order page · EMAIL = confirmation email · RPT = Reports.

## Status by area (2026-07-01)

**Strongly covered (behavioral):** the pricing calculator, the write/persist path, refunds, discounts,
custom line items, and reconciliation at CHG/OD/PDF/EMAIL for the common combinations. See the coverage
audit for the full smoke-by-smoke map.

| Gap (from coverage audit) | Risk | Status |
|---|---|---|
| **CO-JS vs CHG** — customer JS summary vs actual charge, ALL combos | 🔴 highest (displayed ≠ charged) | **OPEN** — needs the real-browser session (JS-rendered, no in-process reach). |
| **Weekly (stall+RV)** billed correctly (once, not ×nights) | 🟠 | ✅ **CLOSED** 2.7.734 — `stay-type-matrix-allsurfaces-smoke` (weekly bills once; reconciles CHG→stored→EMAIL). No bug found. |
| **RV weekend / weekly** pricing at charge | 🟠 | ✅ **CLOSED** — same smoke. |
| **RV early-bird** | 🟠 | ◻️ partial — nightly early-bird covered elsewhere; RV early-bird render TBD. |
| **Surcharge tier orders — money reconciles** | 🟠 | ✅ **CLOSED** — `surcharge-order-render-reconcile-smoke` (2026-07-01): premium-tier surcharge folds into the charge, charge == stored, Σ email lines == stored (surcharge dollars reach the document, not dropped). |
| **Surcharge tier orders — separate "Stall Premium" LINE** | 🟡 | ◻️ **BROWSER-PASS ITEM** — `get_order_stall_surcharge_total()` re-reads the reservation tier config + a stored "Stall Tiers:" note to break the premium into its own line; the synthetic harness can't reproduce that. **VERIFY on a configured QUANTITY-MODE tier reservation** that a real surcharge order shows a "Stall Premium" line on Order Detail / receipt / email (not silently folded into the stall line). |
| **Packages at RENDER surfaces (OD/PDF)** | 🟠 | ◻️ OPEN — charge + email proven (charge-reconcile); OD/PDF itemization not yet asserted. |
| **Hosted order page** pricing content (maximal order) | 🟡 | ◻️ OPEN — `c12-hosted` covers lookup only; needs a reconcile assertion. |
| **Reports** for surcharge/package/group/shavings orders | 🟡 | ◻️ OPEN — `c15a` seeds plain orders only. |
| **Mixed maximal order at PDF/EMAIL** | 🟡 | ◻️ partial — indirect via `surface-render-integrity`. |

## Findings so far

- **Weekly / weekend / RV-non-nightly pricing is CORRECT** — bills once per unit (not × night-count),
  fee + tax compute on the right subtotal, and the email itemization reconciles to the stored/charged
  total. Previously untested (rate was `0.0` in every prior smoke); now guarded.
- **Minor fix landed alongside (2.7.734):** `get_stall_assignment_unit_pool()` accessed
  `$data['stall_chart_stall_blocks']` without the `isset()` guard its sibling line already had — a PHP 8
  "undefined array key" notice on checkout for map-less reservations. Guarded.

## Remaining plan

1. **Real-browser JS-summary parity** (with Whitney) — the one 🔴 gap.
2. Extend render-surface coverage: OD/PDF itemization for **package + surcharge** orders; a **hosted-page
   reconcile** on a maximal order; **Reports** on a composite order.
3. New smokes land green in the suite (now counted — see `run-all-smokes.php` tally fix, 2026-07-01).
