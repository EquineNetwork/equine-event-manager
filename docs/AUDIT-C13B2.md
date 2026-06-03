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

## LOCKED at kickoff (2026-06-02)

- **Architecture: Hybrid.** Render the reservation's section markup + bind the real
  pricing engine; keep Create Order's own contact card, rail summary, and a
  create-order-specific save.
- **Order creation: unpaid via the existing pipeline.** "Create Order" writes the
  order through the same order-creation code a customer checkout uses (same rows,
  validation, pricing) but in an **unpaid/pending** state with an `admin_created`
  flag and **NO charge**. Payment is collected later via Send-Link or C14.

### Implementation finding that shapes the build (de-risked before coding)

The customer form's **pricing JS is an inline `<script>` printed on `wp_footer`**
(`EEM_Shortcodes::render_frontend_form_assets_in_footer`, ~line 8305), and public.css
is enqueued from that same footer hook. Consequences:

1. **No AJAX/innerHTML injection of the sections** — inline `<script>` injected via
   `innerHTML` does NOT execute, so the steppers/picker/totals would be dead.
2. Therefore B.2 is **server-side, reload-based**: the reservation picker navigates to
   `?page=…-create-order&reservation_id=N`; on that load the page renders the embedded
   `[en_reservation id=N]` form (its inline pricing JS is then in the document and runs)
   and must **manually emit the footer assets on `admin_footer`** (call
   `render_frontend_form_assets_in_footer()` for this page) so public.css + the inline
   pricing engine print on the admin page.
3. public.css is scoped to `.eem-event-page` (the shortcode wrapper), so enqueuing it on
   this one admin page is low-bleed.
4. **`render_frontend_form_assets_in_footer()` hard-guards `if ( is_admin() ) return;`**
   (line ~8277) and the inline pricing JS is in a **private** `render_form_styles()`. So
   B.2.a must add a small public seam on `EEM_Shortcodes` (e.g.
   `emit_form_assets_for_admin()`) that calls `EEM_Events::render_frontend_styles()` +
   the (now-exposed) form-styles JS without the is_admin guard, and hook it on
   `admin_footer` for the create-order page only. Verify late-enqueued public.css actually
   prints on admin (it works on wp_footer; confirm the admin_footer equivalent).

### Embedded-form DOM map (exact selectors for the scope-hide)

`do_shortcode('[en_reservation id=N]')` outputs (all under `.eem-event-page`):
- `.eem-reservation-workspace` → `.eem-reservation-workspace__main` (form column) + `.eem-reservation-workspace__rail` (summary + payment + "Reserve Now" submit).
- In `__main`, in order: **Contact** = first `.eem-reservation-section` (title "Contact Information"; also holds the Group Name field) → **Stay Details** `.eem-reservation-section--instructions` → **sections** `.eem-reservation-section[data-eem-section="stall"|"rv"|"addons"]` (the ones to KEEP) → group + special-requests sections.

Scope-hide plan (keep Create Order's own chrome):
- Hide the embedded **rail**: `.eem-co-embedded-form .eem-reservation-workspace__rail { display:none }` (clean — removes the form's summary + payment + submit; Create Order's rail is authoritative).
- Hide the embedded **contact** section so Create Order's contact card stays the source of truth — target the first `.eem-reservation-section` in `__main`. Re-point the customer-lookup autofill to the create-order contact card (already `[data-eem-co-contact]`), OR keep the embedded contact and hide Create Order's card instead (decide at build; the Group Name field lives in the embedded contact, which argues for keeping the embedded contact + hiding ours).
- Hide Create Order's 4 **stub section cards** + its special-requests card when embedded (the form supplies those).

### Build status (started 2026-06-02)

- ✅ Asset seam shipped: `EEM_Shortcodes::emit_form_assets_for_admin()` (public; emits the inline pricing JS bypassing the is_admin footer guard). Harmless until called.
- ⬜ Remaining B.2.a (next session, needs browser-verify): the reload-based embed in
  `EEM_Create_Order_Page::render()` (print public.css `<link>` + `do_shortcode` the form +
  call the seam), the JS reload-on-select, and the scope-hide CSS above. **Browser-verify
  the steppers/picker fire + no admin-chrome bleed before declaring B.2.a done.**

### Refined sub-chunks (hybrid, reload-based)

- **B.2.a** — server-side embed: on `?reservation_id=N`, render the `[en_reservation]`
  form into the main column (replacing the 4 stub section cards), pre-select the picker,
  and emit the footer assets on `admin_footer`. CSS scope-hides the embedded form's own
  contact / summary / payment / submit (keep only the live sections). *(Supersedes B.1's
  AJAX section-config for the embedded case; B.1 stays as the no-reservation default.)*
- **B.2.b** — mirror the embedded form's computed total into the Create Order rail
  summary (read the form's running total; render our own summary lines + Total).
- **B.2.c** — render-collect-post the embedded form's fields through the existing
  order-creation pipeline with the `admin_created` flag, unpaid, + the create-order
  contact. *(C13.C custom items + discount layer on; C13.D Send-Link email.)*

## (Original) Decisions considered at kickoff

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
