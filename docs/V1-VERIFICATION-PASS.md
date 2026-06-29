# V1 Full Verification Pass — results (2026-06-29)

Whitney's launch-readiness pass: "a FULL data seeding / checkout / test of every
single process and combination and surface to make sure everything is working and
calculating one more time." Two layers: (1) server-side behavioral suite across
every combination/surface, (2) live-browser parity (displayed == charged ==
receipt) for the high-risk customer JS.

## Layer 1 — server-side suite: GREEN

Ran the full **175-file smoke suite — 2,980 assertions, 0 failures** (after
resolving 9 below). Exercises real seeded orders through the real write/read paths
and reconciles each surface with strict invariants (charge == stored ==
Σ receipt line items + fee + tax; nothing dropped/double-counted).

Combinations covered behaviorally: stall (base/bulk/early-bird/packages/tack),
RV (+ zone surcharges), required + additional shavings, add-ons, group (grounds +
deposit), pre-entries (+ division spots), %-fee, FLAT-fee (once-per-order guard),
tax, discounts ($/%), custom line items, cash/check fee-skip. Surfaces:
charge, stored order, Order Detail, receipt line items, confirmation email (c11),
PDF/hosted receipt (c12), refund math.

The 9 failures the run surfaced were all resolved (2.7.708/709):
- **OVER-PICK gap (real bug, fixed)** — over-picking on a v4 map reservation wasn't
  server-validated (the units>qty check was gated on `stall_chart_enabled`, 0 for
  map reservations). Added exact_map over-pick gates for stall + RV.
- **4 stale minified-CSS** — my zoom + all-or-nothing CSS edits weren't rebuilt;
  ran `tools/build-assets.php`. PRODUCTION serves the `.min` files, so this was
  required for those fixes to ship.
- **2 stale ledger-era tests** — the Order Detail payment card moved to the C14
  payments ledger; the old tests asserted the pre-ledger path. Product behavior
  verified correct; guards made ledger-aware.

## Layer 2 — live-browser JS parity: GREEN for the high-risk combos

The smokes prove the SERVER calc. The one surface they can't reach is the live
customer Order Summary JS (where past bugs lived: the group $200-on-load clamp,
the tack divergence). Verified by real Stripe test checkouts that the **displayed
total == the actual charge == the receipt total**:

| Combination | Displayed | Charged | Receipt | Order |
|---|---|---|---|---|
| Maximal multi-component (stall + RV + req & add'l shavings + add-on + group grounds + deposit) **+ 4% fee + 8% tax** | $987.84 | $987.84 | $987.84 | #91715 |
| Stall + tack (tack-excluded shavings) | $312.00 | $312.00 | $312.00 | #91648/9 |
| Group reservation (2 riders) | $416.00 | $416.00 | $416.00 | #91647 |

The maximal cart is the strongest single proof — it stacks every section plus the
fee-on-combined-subtotal + tax (the F7 once-per-order + rounding paths) and still
reconciles to the penny end-to-end.

## Notes / follow-ups

- **Config observation:** the convenience fee + tax were both toggled OFF in
  Settings on the test site (a reconcile smoke restores to the prior value). I
  enabled 4%/8% for the parity test and restored to off. **If production needs the
  convenience fee, confirm it's ON in Settings → Taxes & Fees before launch.**
- Remaining lower-risk live checks (subsets of the maximal already verified):
  single-axis RV-only, early-bird, weekend/weekly stay types; and a visual pass of
  the rendered confirmation email + PDF (their data is already smoke-reconciled).
- Config-conditional validation gates (required docs, pre-entry caps, RV lot,
  inventory caps) remain code-reviewed; need a configured fixture to seed live
  (the only one with some — 5990 — is the corrupted do-not-touch reservation).
