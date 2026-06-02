# C13.B.2 Pre-Audit — Reuse the customer-form engine for live Create-Order sections

**Status:** analysis only (no code). Prepared autonomously so B.2 can start fast.
**Goal:** when a reservation is selected on Create Order, render *live* Stall / RV /
Add-Ons / Group sections with working qty steppers, stall picker, date selectors,
and a **live Order Summary total** — by reusing the customer reservation-form
pricing engine rather than re-implementing it.

## What the engine looks like today

- The modern engine is `EEM_Shortcodes::render_reservation( $atts )` (`[en_reservation id=N]`),
  ~9k lines. It renders the **whole** customer form: Contact Info, Stall, RV, Add-Ons,
  Group, Special Requests, **its own Order Summary rail**, and **its own payment/submit**.
- Pricing is computed client-side by the inline JS in the shortcode output
  (`updateReservationTotals`, `updateProductPricing`, `syncStallPicker`, etc.) and
  validated/persisted server-side by the existing checkout submission pipeline
  (the same one that creates orders from `{prefix}en_stall_reservations` /
  `en_rv_reservations` rows).
- The standalone `en_stall_reservation_form` / `en_rv_reservation_form` shortcodes are
  **legacy** (`render_legacy_event_form`) — NOT the modern engine. Do not build on them.
- There is **no** clean "render just the sections" public method to call. The section
  markup + pricing are produced inside `render_reservation`'s monolith.

## The three viable architectures

### Option A — Embed the full form (`do_shortcode`)
Render `[en_reservation id=N]` into the Create Order main column when a reservation
is chosen; enqueue `public.css`/`public.js` on the admin page so it styles + prices.
- ✅ Zero pricing duplication; pixel-faithful; fastest to *display*.
- ❌ The embedded form carries its **own** Contact + Summary + payment + "Reserve Now",
  which duplicate/conflict with the Create Order chrome (its contact card, rail summary,
  payment hand-off). Would need to hide the create-order duplicates and reconcile layout.
- ❌ The form's submit goes through the **customer checkout** pipeline (creates an order
  as if the customer submitted). For an *admin* order we'd need to intercept that submit
  (flag admin-created, attach custom items + discount, route to the right success state).
- ❌ Loading front-end CSS/JS inside wp-admin risks style bleed against admin chrome.

### Option B — Extract section renderers (refactor)
Pull the Stall/RV/Add-Ons/Group section render + the pricing JS out of
`render_reservation` into reusable methods (`render_stall_section($data)`, …) that both
the customer form and Create Order call.
- ✅ Cleanest long-term; Create Order keeps its own contact/rail/save; one pricing source.
- ❌ Large, risky refactor of a 9k-line monolith that the customer form (just approved at
  item 9) depends on — high regression surface for a recently-signed-off flow.

### Option C — Hybrid (recommended starting point)
Reuse the section **markup + the public.js pricing engine** (Option A's display win) but
submit through a **create-order-specific** handler (Option B's clean save):
1. AJAX returns the rendered section HTML for the chosen reservation (call a thin wrapper
   that renders only the section partials from the engine).
2. Inject it into the Create Order section-card bodies; enqueue the pricing JS so steppers
   + the picker + totals work; mirror the computed total into the Create Order rail.
3. On "create order", collect the same field names the customer form posts (render-collect-
   post, the canonical pattern) and run them through the **existing order-creation pipeline**
   server-side — flagged admin-created, with custom line items + discount layered on (C13.C).

This avoids the big refactor now, avoids the duplicate-chrome problem (we drive our own
rail), and reuses the real pricing + the real order-creation code.

## Decisions to lock at B.2 kickoff (need Whitney)

1. **Architecture:** A (embed) / B (extract) / **C (hybrid, recommended)**.
2. **Order creation path:** does the admin order go through the **same** submission
   pipeline as a customer checkout (admin-initiated, same validation/row-writes), or a
   **separate** create-order save? (Recommend: same pipeline, with an `admin_created` flag,
   so pricing/validation/refund/receipt all "just work".)
3. **Contact source of truth:** keep the Create Order contact card (and feed it into the
   pipeline), or let an embedded form own contact? (Recommend: keep our card.)
4. **Asset loading:** confirm it's acceptable to enqueue `public.js` (pricing) on this one
   admin page. (Recommend: yes, scoped to this page slug; watch for CSS bleed and namespace
   the styles if needed.)

## Risks / watch-items
- Front-end pricing JS assumes `.eem-event-page` / `.eem-reservation-form` wrappers — the
  Create Order embed must reproduce the wrapper the JS hooks onto, or the steppers won't fire.
- `max_input_vars` / field-name collisions when the section fields post alongside the
  create-order fields — namespace or validate.
- The customer form was just approved; **any** extraction (Option B) must re-run the full
  customer-form smoke set before merge.

## Suggested B.2 sub-chunking (if hybrid)
- **B.2.a** — section-render AJAX wrapper + inject markup into the cards (display only).
- **B.2.b** — enqueue + bind the pricing JS; mirror the live total into the rail.
- **B.2.c** — render-collect-post into the existing order pipeline (admin-created flag).
  *(C13.C custom items + discount layer on top; C13.D finalizes save + Send-Link email.)*
