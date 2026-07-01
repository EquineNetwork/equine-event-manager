# #5 — Refund admin-added custom charges: design + why it's built as one unit

_Scoping from a full read of `class-eem-refund-engine.php` + `class-eem-order-adjustments-repo.php`, 2026-07-01._

## The problem, precisely

A custom line item (late fee, damage charge, etc.) is added to an order via
`EEM_Order_Adjustments_Repo` and charged through Collect Payment. It is **not** a
reservation component — it has `id, order_key, description, amount` and **no
transaction_id and no refund state**.

The refund engine issues gateway refunds **per component, against each
component's own original transaction** (`refund_engine.php:449` →
`refund_order_component`). Refunds are *placed* by drawing against components
until the requested amount is covered. A custom charge has no component to draw
against, so:

- **Amount path** — `get_order_refundable_ceiling()` = `min(Σ component
  remaining, gross_collected − already_refunded)`. The `Σ component remaining`
  term caps the ceiling at the base charge, so the custom portion can't even be
  requested.
- **Tender path** — after components are exhausted, `remaining_for_tender > 0`
  returns `tender_unplaceable` (`refund_engine.php:486`).

Both **fail safe** (error, never over-refund) — but the collected custom-charge
money is stranded: it cannot be returned through the UI.

## The design (must ship as ONE unit — the pieces are interdependent)

1. **Relax the ceiling to the ledger truth.** Refundable = `gross_collected −
   total_already_refunded`, where already-refunded counts BOTH component refunds
   AND a new adjustment-refund tally. (Removing the `Σ component remaining` cap.)
   ⚠️ **Unsafe to ship alone** — relaxing the ceiling without working placement
   lets a refund be *requested* that then can't be placed.
2. **Placement against the adjustment pool.** When components are exhausted and
   an amount remains, draw it against the order's adjustments: issue a gateway
   refund against a gateway PAYMENT transaction from the ledger (the custom item
   was collected on one), record a ledger `DIRECTION_REFUND`, and stamp an
   **adjustment-refund marker** (new column/note on the adjustments row) so a
   second refund can't double-refund it and the receipt can show it refunded.
3. **Display.** Order Detail / receipt / email show the custom item as refunded
   and the net collected drops by the refunded amount (already true via the
   ledger once step 2 records the refund).

## Why it needs a live Stripe test (pairs with the browser pass)

Step 2 introduces a **new refund primitive**: a gateway refund against a *ledger
payment transaction* rather than a component. Every existing refund path refunds
a component's transaction; this one refunds the Collect-Payment charge that
collected the custom item. The **bookkeeping** (ceiling, ledger entry, adjustment
marker, net-collected math, all-surface reconciliation) is fully smoke-testable.
The **actual gateway refund call** (Stripe partial refund of the Collect-Payment
PaymentIntent) can only be validated against Stripe test mode — the same live
session as the JS-summary browser check.

## Recommendation

Build steps 1–3 as one careful unit with a behavioral smoke for the bookkeeping,
then validate the gateway refund in the Stripe test session (paired with the
browser pass). Do NOT ship the ceiling relaxation (step 1) without the placement
(step 2) — a half-build would let a refund be requested that can't be placed.

**Estimated effort:** ~1 focused session for steps 1–3 + smoke; +the live Stripe
validation. Not a quick contained edit — it's a genuine refund-flow feature.
