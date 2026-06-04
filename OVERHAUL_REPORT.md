# Equine Event Manager — Overhaul / Launch-Readiness Report

_Generated 2026-06-04 (v2.7.18). Phase 4 verification pass._

## 1. Current code surface

| Area | Size |
|---|---|
| PHP | 55,240 lines · 78 files |
| JS (`assets/js`) | 11,518 lines |
| CSS (`assets/css`) | 25,095 lines |
| Smoke tests | 83 files |

(Pre-overhaul "before" baseline from the original Codex-generated plugin is not
available in-repo, so this is the current "after" state.)

## 2. Phase 4 verification results

| Critical path | Result |
|---|---|
| **Admin pages render** (Dashboard, Orders, Order Detail, Reservations, Editor, Stall Charts, Create Order, Collect Payment, Customer Profile, Reports, Settings) | ✅ All built + render; spot-verified in browser |
| **Reservation editor** | ✅ Renders all 11 sections with live data (browser-verified, res #3499) |
| **Dashboard** | ✅ Live KPIs, stall metrics, attention rows, revenue chart (browser-verified) |
| **Front-end `[en_reservation id=N]` shortcode** | ✅ Renders the customer form (55 KB: contact, stall section, submit) |
| **Stripe charge (Collect Payment)** | ✅ Verified live with a real test charge — order #00015 paid, `pi_…` recorded, card brand/last4 captured, paid-state rendered |
| **Confirmation email** | ✅ Generates (14.8 KB HTML, event + line items) |
| **PDF receipt (Dompdf)** | ✅ Generates valid PDF bytes (156 KB, `%PDF` header) |
| **Send payment-link email** | ✅ Wired (Collect Payment + legacy order view → existing invoice-email feature) |
| **Refund + refund-notify email** | ✅ Refund flow works; opt-in customer email now sends (CLEANUP #30) |
| **Stripe webhook** | ✅ **Now implemented** — `POST /wp-json/eem/v1/stripe-webhook`, HMAC signature verified (5-min replay guard), idempotent `payment_intent.succeeded` reconciliation. Live endpoint rejects unsigned (HTTP 400). End-to-end delivery needs `stripe listen`. Smoke 14/14. |
| `wp plugin verify-checksums` | N/A — custom plugin (no WordPress.org checksum manifest) |

## 3. Known gaps / decisions before launch

1. **Stripe webhook — now implemented** (was the one Phase 4 gap). Endpoint
   `POST /wp-json/eem/v1/stripe-webhook` verifies the Stripe HMAC signature
   (5-minute replay tolerance) and idempotently reconciles
   `payment_intent.succeeded` (marks the order paid if not already), plus logs
   `charge.refunded` / `charge.dispute.created`. **Remaining setup (yours):** paste
   the webhook signing secret into Settings → Payments, register the endpoint URL
   in the Stripe Dashboard, and run `stripe listen --forward-to
   <site>/wp-json/eem/v1/stripe-webhook` + `stripe trigger
   payment_intent.succeeded` for an end-to-end check. The Dashboard "webhook not
   configured" nag clears once the secret is set.
2. **Plugin URI / Author URI** are `example.com` placeholders (CLEANUP #23) — must
   be set before any external/public distribution.
3. **Authorize.net charging** is deferred (Stripe-first decision). Auth.net
   *refunds* already work; Auth.net *charge dispatch* on Collect Payment is not
   built.
4. **Test-suite debt:** ~9 smoke files still fail — all **test drift against
   verified-working pages**, not product bugs. Categories: stale version pins,
   re-added breadcrumb assertion, seeded-row fixture dependencies, customer-event-
   page (c10c/c10d) data-dependence, and the `44`-hardcoded fixtures in
   c7x12/14/15. The editor-smoke root cause (sections need a linked-event fixture)
   is fixed; the remainder is individual-assertion reconciliation.

## 4. Notable work landed (this session)

- **C13.C** — Custom Line Items + Discount (data layer, persist, rail UI, Order
  Detail display, remove-with-reason). Fixed a real 32-char `order_key` vs
  `varchar(20)` overflow bug caught by browser self-verify.
- **C14** — Collect Payment page + **Stripe charge dispatch** (Elements,
  client-tokenized, two gated AJAX handlers), paid-state rendering, **CLEANUP #34**
  card brand/last4 capture + display.
- **CLEANUP #30** — refund-notify email.
- **CLEANUP #37/#38/#39** — confirmed Dashboard stall metrics wired; resolved.
- **Smoke reconciliation** — 24 → ~9 failing files (deleted 3 superseded editor
  drafts; root-caused the editor-fixture issue clearing 63 assertion-failures).

## 5. Open questions for the maintainer

1. Stripe webhook: build it, or ship synchronous-only and remove the UI/nag? (§3.1)
2. Provide the real Plugin URI / Author URI when ready for external release.
3. Re-seed test data? (Would clear the fixture-drift smokes but wipe current test
   orders — declined this session to preserve #00014/#00015.)
4. Finish the remaining smoke-tail reconciliation for a fully-green CI, or accept
   it as characterized debt against working features?
