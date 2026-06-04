# C14 Collect Payment — Pre-Audit (read-only)

**Status:** analysis only. No code written. Surfaces the decision-locks for the
**gated** payment-dispatch work so the architecture is approved before any charge
code lands. Mockup: `.mockups/collect_payment_page.html` (466 lines). Route:
`equine-event-manager-collect-payment?order_key=<key>` (currently a DS-1.A stub
at `admin/class-eem-collect-payment-page.php`, 115 lines).

## Mockup walkthrough enumeration

| # | Section (mockup line) | Content | Reuse / new | Gated? |
|---|---|---|---|---|
| 1 | Breadcrumb (229) | logo / Orders / #order / Collect Payment | `eem_render_breadcrumb` | no |
| 2 | Plugin header (238) | "Collect Payment — Order #N" + "amend → order detail" link | new (small) | no |
| 3 | Payment-outstanding banner (252) | amount due + invoice-sent date | **reuse** shipped `.eem-order-payment-banner` (Order Detail) | no |
| 4 | Customer + Order info card (268) | read-only customer/email/reservation/type/invoice-status | new render from `$order` (read-only) | no |
| 5 | Order Items card (307) | read-only line items (stall/shavings/fee) | reuse order-summary line shapes | no |
| 6 | Amount Due rail (344) | summary lines + **discount apply/remove** + Total Due | **reuse C13.C discount component** (`.eem-co-discount*` / repo) | no |
| 7 | Payment card — Send Link tab (395) | resend payment-link email + preview + "Resend Payment Link" | new | **YES — email send** |
| 8 | Payment card — Charge Card tab (407) | card fields + "Charge $X" + "Secured by Stripe" | new | **YES — payment dispatch + PCI** |
| 9 | Empty state (437) | "No Order Specified" + Back to Orders | new (small) | no |

## Existing infrastructure (grounding)

- **Stripe charges are client-tokenized today.** Customer checkout uses Stripe.js
  Elements; the server only receives `stripe_payment_intent_id` (raw PAN never
  hits PHP — `shortcodes.php:438/1902/2833`). The mockup's raw card-number/CVC
  inputs are **visual placeholders**; the real Charge Card tab MUST use Stripe
  Elements (and Authorize.net Accept.js) for the same reason — PCI + no server-side
  card handling.
- **Refund path is the charge template, inverted.** `refund_with_stripe()`
  (admin.php:9159) does `wp_remote_post('https://api.stripe.com/v1/refunds', ...)`
  with the settings secret key. A charge would POST to `/v1/payment_intents`
  (confirm) similarly. No SDK — direct REST via `wp_remote_post`.
- **Processor settings** via `get_payment_settings()` → `{selected_gateway,
  stripe:{mode,live/test secret}, authorize_net:{...}}`. Both gateways supported.
- **Discount = already built (C13.C).** The rail discount apply/remove is the
  exact component shipped in C13.C — repo (`EEM_Order_Adjustments_Repo`), CSS
  (`.eem-co-discount*`), JS helpers. Reuse wholesale; no new discount logic.
- **Order lookup** by `order_key` via `EEM_Orders_Repository::get_order()` →
  read-only display data (customer, email, reservation, components, totals).
- **Remaining-balance** via `EEM_Admin::get_order_remaining_refundable()` analog;
  for collect we need amount-OWED (total − paid), distinct from refundable.

## Files-touched (estimate)

| File | Work | Bucket |
|---|---|---|
| `admin/class-eem-collect-payment-page.php` | replace stub: order lookup + read-only render (banner, customer card, items, amount-due rail w/ discount reuse, payment tabs shell, empty state) | PHP render |
| `assets/css/admin.css` | mostly reuse (banner, discount, summary, tabs from C13.C/C6); net-new small (`.eem-cp-*` wrappers, read-only field-value) | CSS (carve-out) |
| `assets/js/admin.js` | payment-tab switch (reuse create-order pattern); discount reuse; **charge + send-link handlers = GATED** | JS |
| `admin/class-equine-event-manager-admin.php` or page class | **charge dispatch handler (Stripe PaymentIntent + Authnet) — GATED**; Send-Link email — GATED | PHP (gated) |
| `includes/class-equine-event-manager.php` | AJAX action registration for charge/send-link (gated) | wiring |
| `tests/smoke/c14*-smoke.php` | render + discount reuse + (gated handlers when built) | smoke |

**LOC:** the read-only display + discount/tab reuse is **light** (CSS carve-out
applies — banner/discount/summary/tabs all shipped). The charge dispatch +
Send-Link + card brand/last4 capture (CLEANUP #34) are the heavy, **gated** parts.

## Decision-locks (need explicit approval — payment-gated)

1. **Charge dispatch architecture.** Confirm Stripe Elements (client tokenization
   → server confirms PaymentIntent via `wp_remote_post`) + Authorize.net Accept.js,
   mirroring checkout + the refund REST pattern. **No raw PAN server-side.** This
   is the PCI-correct path and the only one I'll implement.
2. **Build order — split the gate.** Proposal: build the **non-gated** page now
   (read-only order display + amount-due rail + discount reuse + tab *shells* +
   empty state + route), and leave the **Charge** handler and **Send-Link email**
   as clearly-marked gated stubs until you approve dispatch. Mirrors how C13
   shipped (page built, charge deferred).
3. **Send-Link email.** Resending the payment-link email is a send-on-behalf
   action (gated). Wire the UI now; gate the actual `wp_mail`.
4. **Card brand/last4 capture (CLEANUP #34).** On a successful charge, capture
   brand + last4 into order meta and re-enable the Order Detail Payment Details
   card block. Lands with the gated charge handler.
5. **Discount reuse.** Confirm the rail discount reuses the C13.C component +
   repo as-is (recommended — zero new discount logic).

## Recommended proceed

Build decision-lock #2's **non-gated half** now (viewable page, real order data,
working discount), and hold the **Charge + Send-Link** handlers for explicit
approval of #1/#3. That delivers a usable Collect Payment page immediately while
keeping every real-money / send-on-behalf action behind your go-ahead.
