# Checkout-Validation Audit (2026-06-29)

Systematic sweep of every "you can't check out unless X" gate, triggered by the
all-or-nothing stall-pick bug (a map reservation let a customer set qty 3, pick
only 2 stalls, and still check out). Goal: prove each gate **blocks before any
charge** and find the rest of that bug class.

Method: behavioral — drives the real `validate_submission()` (the authoritative
server gate that runs in BOTH `ajax_create_stripe_payment_intent` and
`handle_reservation_submission` *before* the charge) against the canonical
reservation #18375, mutating one field at a time off a known-valid baseline.
Guard: `tests/smoke/checkout-validation-gates-smoke.php` (14/0).

## Finding (fixed this pass)

**OVER-PICK not server-validated on map reservations.** The "don't choose more
stall numbers than you're reserving" check (`validate_submission` ~line 3989) only
runs inside the `stall_chart_enabled` block. A v4 **map** reservation has
`stall_chart_enabled = 0` (it picks from the `_en_stall_map` snapshot instead), so
a crafted POST could submit MORE picks than the quantity and skip the check —
risking over-assignment of stalls for a qty-priced order. Fixed: added an
exact_map over-pick gate for stalls (gated `!stall_chart_enabled` to avoid a
duplicate error) and an equivalent gate for the RV map. (2.7.708)

## Gate status

**Behaviorally verified (seeded violation → asserted rejection, + a clean
baseline):**

| Gate | Result |
|---|---|
| Contact name/email/phone present | ✅ blocks |
| Email format | ✅ blocks |
| Phone (too-short / un-normalizable; bare 10-digit auto-normalizes to +1 and is correctly accepted) | ✅ blocks |
| Billing details complete (customer charge path) | ✅ blocks |
| Submission token present | ✅ blocks |
| At-least-one purchasable item | ✅ blocks |
| Group: rider count ≥ 1 when enabled | ✅ blocks |
| Group: every rider name filled | ✅ blocks |
| Stall map: partial pick (N of M) | ✅ blocks |
| Stall map: over-pick (more than qty) | ✅ blocks (fixed this pass) |
| Stall stay dates: departure before arrival | ✅ blocks |
| Stall stay dates: outside available window | ✅ blocks |
| Complete valid stall pick (positive control) | ✅ passes |
| Valid full order (positive control) | ✅ passes |

**Code-reviewed present, but not seeded (reservation #18375 doesn't have these
features configured — verify on a fixture that does):**

- Required-documents-at-checkout gate (`required_documents_enabled` + a required doc).
- Pre-entry per-customer cap + division spots-left (needs a pre-entry / division option).
- Sold-out / inventory-remaining / max-stalls-per-customer (needs inventory state).
- RV lot selection required + RV lot sold-out/remaining (`rv_lot_selection_enabled`).
- RV map partial/over-pick (no RV-map fixture exists on the site; covered by the
  shared code path + `map-pick-incomplete-notice-smoke.php`).
- Stalls/RV "not open" window gates (status-dependent).
- Weekend/weekly package date-matching.

All of the above are present in `validate_submission` / `validate_section_stay_selection`
and were confirmed by reading the code; they just need a configured fixture to
exercise behaviorally.

## Client-side surfacing

Every gate surfaces to the customer: `validate_submission` runs inside the
PaymentIntent AJAX, so a violation returns its message via `setStripeError` at the
moment they try to pay — no charge is created. The stall/RV **map** gates
additionally show a live inline warning (`[data-eem-stall-incomplete]`) and block
the submit before the pay step (`bindStallPickGuard` + `syncStallPickWarning`),
because a silently-incomplete map is the easiest mistake to make.

## Follow-ups

- Behaviorally exercise the config-conditional gates on a fixture that enables
  required documents, pre-entries/divisions, RV lot selection, and inventory caps.
- Consider promoting more gates to live inline prompts (today most only surface at
  the pay step, which is acceptable but less friendly than the map's inline warning).
