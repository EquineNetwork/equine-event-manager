# C10.C Pre-Audit — Customer Form Section Shells + Settings UI Fix

**Target version:** 2.3.53 · **Mockup:** `.mockups/event_page.html` (850 lines)
**Audited:** 2026-05-31, against code at 2.3.52.

---

## A. Corrected scope (drift from the work order)

The work order states: *"C10.A rebuilt the public CSS in 2.3.46. This commit
ports the form section markup to use those new CSS classes."*

**That is only half true.** Verified against `assets/css/public.css`:

- C10.A authored the **hero** + **event-directory** + page-scaffold CSS
  (`.eem-event-*` BEM classes, plus bare `.page-body` / `.form-col` /
  `.order-sidebar` / `.hero` / `.btn-reserve` / `.agreement-notice` /
  `.mobile-order-drawer`, all scoped under `.eem-event-page`).
- C10.A did **NOT** author the mockup's **form-section vocabulary**. None of
  these exist in public.css (bare or `eem-`-prefixed): `.form-section`,
  `.section-header`, `.section-title`, `.section-desc`, `.section-body`,
  `.field-row`, `.field-group`, `.field-label`, `.field-input`,
  `.field-select`, `.field-textarea`, `.req`, `.phone-wrap`,
  `.products-header`, `.product-row`, `.qty-control`, `.qty-btn`,
  `.subtotal-row`, `.addon-row`, `.rider-card`, `.billing-sub-box`,
  `.complete-btn`, `.toggle`, `.cols-2/3/4`.

**Consequence:** C10.C must AUTHOR ~120 CSS rules (mockup `<style>` lines
48–168) into public.css AND port the render markup. This is roughly 2× the
"just swap classes" framing. Not a blocker — just a corrected size.

## B. Decisions locked (autonomous, following established C10.A precedent)

1. **Class strategy = HYBRID, additive.** The render method already emits
   `class="eem-reservation-workspace page-body"` (eem- + bare together). Port
   continues this: KEEP existing `eem-*` classes (JS hooks + PHP submission +
   smoke selectors all target these), ADD the mockup's bare classes for
   styling. Never remove an `eem-*` class a selector depends on. This is the
   lowest-risk way to restyle a live Stripe checkout.
2. **New form CSS scoped under `.eem-event-page`** (matches C10.A; launch path
   is Path B template-takeover which guarantees that ancestor). Bare names
   stay collision-safe because they never appear unscoped.
3. **Bare mockup classes ported verbatim** (`.form-section`, `.field-row`,
   etc.) — matches mockup + C10.A precedent. No re-prefixing.

## C. Section enumeration (mockup walkthrough)

| # | Section | Mockup lines | Toggle? | C10.C scope | Notes |
|---|---|---|---|---|---|
| 1 | Contact Information | 347–378 | no | full port | name/email/phone; phone-prefix affix |
| 2 | Stay Details | 380–391 | no | full port | info text + available-dates note |
| 3 | Stall Reservations | 393–563 | yes (on) | **shell only** | header+toggle+intro+rate/date row+product rows+subtotal; picker box = C10.D |
| 4 | RV Reservations | 565–613 | yes (on) | **shell only** | same shape as stall; picker = C10.D |
| 5 | Add-Ons | 615–638 | yes (on) | full port | product rows + qty + subtotal |
| 6 | Group Reservation | 640–678 | yes (on) | full port | rider count qty + rider-card rows |
| 7 | Special Requests | 680–691 | no | full port | textarea |
| 8 | Billing & Payment | 693–741 | no | **address only** | billing-sub-box address; card fields = C10.F |

Out of scope (later chunks): C10.D stall/RV picker UI, C10.E order sidebar +
mobile drawer, C10.F card fields, C10.G e2e test, C10.H holds/locking.

## D. Files touched

| File | Change | Est. LOC |
|---|---|---|
| `assets/css/public.css` | author form-section vocabulary (mockup 48–168), scoped `.eem-event-page` | ~330 (×2.5 CSS port × bare names) |
| `public/class-equine-event-manager-shortcodes.php` | add bare mockup classes to the 8 sections' markup in `render_reservation` (lines 115–1030) | ~120 edits, low net LOC |
| `admin/class-eem-settings-page.php` (or settings render) | Part 2: reorder event source, Coming Soon pills, disable radios + panels | ~60 |
| `assets/css/admin.css` | Part 2: `.eem-coming-soon` pill if none exists | ~12 |
| `tests/smoke/c10c-*-smoke.php` | new smoke: section classes present, disabled sections hidden, settings badges | ~90 |
| `equine-event-manager.php` | bump 2.3.53 | 2 |

## E. Risk register

- **Stripe checkout** — billing/payment section is Stripe-adjacent. C10.C only
  touches the billing ADDRESS sub-box (card fields are C10.F). Additive class
  strategy means no submission selector changes. Still: needs browser e2e
  before final sign-off (can't click-test Stripe via WP-CLI).
- **JS selectors** — the customer stall picker JS + qty steppers target eem-
  classes; additive port preserves them. Any rename gets documented.
- **Verification gap** — full confidence requires a browser pass on the live
  event page (res 43). Smoke covers class-presence + disabled-section hiding;
  it cannot prove visual fidelity or Stripe submit.

## F. Execution order (low-risk first)

1. Part 2 Settings Integrations (contained, zero checkout risk) — ship + smoke.
2. Part 1 CSS authoring in public.css (purely additive) — ship.
3. Part 1 markup port, section by section, smoke-render after each.
4. Browser verify on res 43 event page before declaring done.
