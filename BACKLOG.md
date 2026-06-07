# Equine Event Manager — Backlog

Single source of truth for the launch worklist. Updated 2026-06-06.

---

## ✅ Done (recent)

Auto-update (no token) · setup wizard + finish toasts · dashboard checklist reshape
+ create-first-reservation CTA · empty-state CTAs (Create Order / Stall Charts /
Reports) · event-search preload · media-modal CSS fix · stall + RV setup wizards ·
"Uninstall" rename · header URIs · 5-digit order-ID audit · bulk Publish/Draft ·
inactive-processor field locking · Open-Tab/open-invoice confirmed built ·
**publish gate: Numbered stalls require ≥1 row, Mapped RV requires ≥1 lot (v2.7.60)**.

### v2 correctness + polish — shipped 2026-06-06

- **v2 #1 (v2.7.61)** — front/back parity audit. Found the reported "stall/RV show
  when disabled, groups hidden when enabled" was a reservation mismatch (renderer +
  Create Order already gate correctly). Fixed the real bug: group/add-on/pre-entry-only
  reservations now render on the customer form (`other_bookable` gate); gated the
  customer "Group Name" field behind the Group Reservations toggle.
- **v2 #2 (v2.7.62)** — RV Mapped publish gate now also requires ≥1 pricing zone and
  ≥1 zone-assigned lot row.
- **v2 #3 (v2.7.63)** — section open/closed state survives save+reload.
- **v2 #4 (v2.7.64)** — "Tack Stall Selection" admin toggle gates the customer
  tack-stall selector.
- **v2 #5 (v2.7.65)** — stall + RV layout clusters wrapped in a shaded
  `.eem-layout-group` panel (matches front-end "Pick Your Stalls").

---

## 🔧 v2 — Polish + smaller features + walkthrough bugs

> **Governing principle (binding):** The **Edit Reservation form is the single
> source of truth.** Every section enable/disable and every field value there
> must drive BOTH the customer event page (`[en_reservation]` shortcode) AND the
> Create Order admin form. Neither downstream surface may show, hide, or default
> anything independently of Edit Reservation.

### Correctness bugs (highest priority)

1. **Front / back / Create-Order parity audit.** Edit Reservation must be the
   single source of truth (see principle above). Known breaks from the walkthrough:
   - Stall Reservations + RV Reservations **disabled** on admin still **render on
     the customer event page**. They must not appear when disabled.
   - Group Reservations **enabled** on admin does **not** render on the customer
     event page. It must appear when enabled.
   - The customer contact-card **"Group Name"** field must show **only** when
     Group Reservations is enabled on admin.
   - Do a full field-by-field, section-by-section parity sweep: enable toggles,
     rates, stay types, inventory modes, zones, add-ons, pre-entries, fees,
     deposits, descriptions — everything on Edit Reservation must reflect on the
     customer form and on Create Order. Build a parity checklist and verify each.

2. **RV Mapped publish-gate — add ZONES requirement.** Row-count gating already
   ships (v2.7.60). Still needed: block publish when Mapped is selected but no
   RV Lot **Zones** exist (and/or lot rows aren't assigned to a zone), with a
   message that points the admin at the Zones step. Zones are easy to miss.

3. **Section card open-state persists across save.** When an enabled section is
   expanded and the admin clicks Update Reservation, the card currently collapses
   back to closed. It should stay open if it was open.

### Editor polish

4. **Tack Stall admin toggle.** Add an on/off control in Edit Reservation ›
   Stall Reservations that governs whether the customer-facing "Using one for
   tack? (optional)" selector appears. Some events don't want customers
   designating a tack stall. (Also a parity item — toggle off ⇒ selector gone on
   the customer form.)

5. **Visually group the dependent layout fields.** Wrap the stall config chain
   (Inventory Type → Customer Selection → Available Stall Inventory → Max Stalls →
   Stall Rows → Blocked Stall Numbers → Stall Map) in a shaded blue-gray panel
   like the front-end "Pick your stalls" card, so it reads as one interdependent
   group. Same treatment for the RV chain (Inventory Mode → Available RV Inventory
   → Max RV Lots → RV Lot Zones → Lot Rows → Blocked RV Lots).

### Carried over from prior v2 — blocked/deferred (need a decision)

6. **Cancellation-policy cleanup** — ✅ **DONE (v2.7.66).** The substantive work was
   already shipped in prior chunks: per-reservation resolver
   (`eem_resolve_cancellation_policy`), editor section, the snapshot migration
   (eem-mig-001, registered + already run on Local), Settings global textarea
   removed (2.3.66), customer checkout reading the per-reservation override. v2.7.66
   stripped the two remaining stale global references in active code (the Settings
   email-preview sample no longer reads the deprecated `cancellation_policy`
   wp_option; the token description points at Edit Reservation). The empty wp_option
   is read-no-write and dropped on uninstall.
7. **BEM status-badge normalization** — 🟡 **Deferred (optional).** Zero user-facing
   change; pure internal churn with regression risk. Low value — skip until a
   broader DS pass.
8. **Order-cancellation email** + cancel action — ✅ **DONE (v2.7.67).** Built the
   whole flow: a "Cancel Order" action (Order Detail More-menu + Orders-list bulk),
   `cancel_order()` repo method (marks cancelled, frees stall/RV inventory, logs to
   activity), and a branded customer cancellation email carrying the reservation's
   cancellation policy. Cancel does NOT auto-refund — payment record is preserved
   for a separate refund. Browser-verified end-to-end + 23/23 smoke.
9. **Bulk "Send Payment Link"** on Orders — ⛔ **Payment-gated** (needs live keys;
   payments moved to LAST per your call).

---

## 🚀 v3 — Major / standalone builds

9.  **`admin-legacy.css` wholesale strip** — port every page off the ~12K-line
    legacy stylesheet, then delete it. Page-by-page, verified.
10. **Scheduled / recurring report exports** (cron + email + retry handling).

---

## 🧭 v4 — Alternate event sources

11. **Native Events source completion** (~1,500 LOC; in-plugin
    `en_event` / `en_venue` / `en_producer`).
12. **External Feed URL source** (external JSON/XML endpoint; currently "Coming Soon").

> v1 event source: **The Events Calendar (TEC)** is the only fully-working source.
> Native + Feed are deferred to v4.

---

## 💳 LAST — Payment block (deferred until accounts are set up)

**Whitney:** Finish Stripe live account (bank/verification) · enter Live keys +
webhook secret · Authorize.net setup · one live test charge (end-to-end + webhook
reconcile).

**Then build:** Bulk refund vs live Stripe · card brand/last4 capture →
re-enable Payment Details card · Authorize.net full charge flow · refund
confirmation email.
