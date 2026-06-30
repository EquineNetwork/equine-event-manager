# MASTER END-TO-END AUDIT CHECKLIST

Built from the reservation config schema (every chargeable option an admin can
enable) + `calculate_submission_totals` (every line the math produces) — NOT from
the existing smokes. Every charging option must be **seeded on a real reservation,
checked out for real, and verified to calculate + display correctly on EVERY
surface.** Work top to bottom; mark each cell.

Status key: ⬜ not done · 🟦 seeded · ✅ verified live · ❌ bug found

---

## A. CHARGING OPTIONS (pricing inputs to exercise)

### Stalls
- [ ] A1. Nightly rate (`stall_nightly_rate`)
- [ ] A2. Weekend package rate (`stall_weekend_rate`, `stall_weekend_enabled`)
- [ ] A3. Weekly package rate (`stall_weekly_rate`, `stall_weekly_enabled`)
- [ ] A4. **Early-bird** — nightly/weekend/weekly early rates + cutoff (`stall_early_bird_enabled`, `stall_early_bird_*_rate`, `stall_early_bird_cutoff`); verify rate switches at cutoff
- [ ] A5. **Stall premium / surcharge** — per barn/zone + per painted area, STACKED, × nights (`surcharge`); map mode AND quantity-tier mode
- [ ] A6. Numbered/map selection (`stall_chart_enabled` / exact_map) vs Bulk (quantity)
- [ ] A7. Tack stall — excluded from required shavings (`stall_tack_mode`)
- [ ] A8. Required shavings — auto qty per stall × price (`required_shavings_enabled/_per_stall/_price`); tack-excluded
- [ ] A9. Additional shavings — per-type, customer qty (`additional_shavings_enabled/_price`)

### RV
- [ ] A10. Nightly rate (`rv_nightly_rate`)
- [ ] A11. Weekend package rate (`rv_weekend_rate/_enabled`)
- [ ] A12. Weekly package rate (`rv_weekly_rate/_enabled`)
- [ ] A13. **RV early-bird** (`rv_early_bird_enabled`, `rv_early_bird_*_rate`, `rv_early_bird_cutoff`)
- [ ] A14. **RV premium / surcharge** — per zone/area, stacked, × nights; map AND quantity-tier mode
- [ ] A15. RV lot selection — specific lot rates (`rv_lot_selection_enabled`)
- [ ] A16. Numbered/map vs Bulk (quantity)

### Add-ons / Entries / Group
- [ ] A17. General add-ons — price × qty (`general_addons_enabled`)
- [ ] A18. Event pre-entries — price × qty, per-customer cap, division spots-left (`event_pre_entries_enabled`)
- [ ] A19. Group rider grounds fee — per rider (`group_rider_grounds_fee_enabled/_amount`)
- [ ] A20. Group rider deposit — per rider (`group_rider_deposit_enabled/_amount`)

### Order-level
- [ ] A21. Convenience fee — % type (`convenience_fee_*`)
- [ ] A22. Convenience fee — FLAT type (once-per-order across multi-component)
- [ ] A23. Convenience fee — card-only skip on CASH / CHECK
- [ ] A24. Tax — global rate + per-reservation override
- [ ] A25. Discount — $ and % (admin Create Order / Collect Payment), reason required, logged
- [ ] A26. Custom line items — admin one-off charges (positive + negative/credit)

### Payment paths
- [ ] A27. Stripe customer checkout
- [ ] A28. Authorize.net customer checkout
- [ ] A29. Cash / check (backend manual)
- [ ] A30. Admin **Create Order** (invoice / open order)
- [ ] A31. Admin **Collect Payment** (charge an existing order — card + cash)
- [ ] A32. **Send Payment Link** (invoice email → customer pays)
- [ ] A33. **Refund** — partial + full (Stripe + Auth.net); over-refund guard
- [ ] A34. **Edit order** — add items, add qty, edit dates (Balance Due / Refund Owed)

---

## B. SURFACES (every place a charge must appear correctly)

For EACH order, reconcile to the penny + confirm every applicable line shows:
1. ⬜ Customer Event Page — live **Order Summary** (JS)
2. ⬜ The actual **gateway charge** (Stripe/Auth.net amount)
3. ⬜ **Confirmation email** (rendered HTML + inlined)
4. ⬜ **PDF receipt**
5. ⬜ **Hosted order / receipt page**
6. ⬜ Admin **Order Detail** (backend)
7. ⬜ **Stall & RV Charts** (assignment + any premium/tack markers)
8. ⬜ **Reports** (orders / revenue / refund log)
9. ⬜ **Activity log** entries
10. ⬜ **Send Payment Link / invoice email**

---

## C. SEEDING PLAN — reservations to build (different mixes)

- [ ] **RES-ALL** — EVERYTHING on: stalls (map, early-bird, surcharge zones+areas, tack, required + additional shavings) + RV (map, surcharge zones, early-bird) + add-ons + pre-entries (with a division + cap) + group (grounds + deposit) + convenience fee % + tax. One real checkout exercising every line at once.
- [ ] **RES-PKG** — weekend + weekly PACKAGE stay types (stall + RV), to exercise package pricing + package-date matching.
- [ ] **RES-FLAT** — FLAT convenience fee + multi-component (stall + RV), to re-prove the once-per-order guard live.
- [ ] **RES-LOT** — RV lot selection (specific lot rates) + RV surcharge.
- [ ] **RES-BACKEND** — admin Create Order → discount ($ + %) + custom line items → Collect Payment (card + cash) → Send Payment Link → partial + full refund. Exercises every backend money path + surface.

---

## D. METHOD

1. Seed each reservation programmatically (config + map snapshots with surcharges) OR via the editor; publish + create its `[en_reservation]` page.
2. Real browser checkout (Stripe test card) for customer-facing; admin UI for backend paths.
3. After each order: open + reconcile ALL 10 surfaces; record pass/fail per line.
4. Any mismatch = STOP, diagnose, fix, re-verify, commit.
5. Findings + the pass/fail matrix go in `docs/MASTER-AUDIT-RESULTS.md`.
