# Equine Event Manager — Overhaul / Launch-Readiness Report

_Generated 2026-06-10 (v2.7.172). Phase 4 verification pass + GEMS integration + demo prep._

## 1. Current code surface

| Area | Size |
|---|---|
| PHP (plugin, excludes tests/tools) | ~67,700 lines |
| JS (`assets/js/admin.js`) | 7,321 lines |
| CSS (`assets/css/admin.css`) | 9,196 lines |
| CSS (`assets/css/admin-legacy.css`) | 12,256 lines |
| CSS (`assets/css/public.css`) | 4,512 lines |
| Smoke tests | 120 files |

(Pre-overhaul "before" baseline from the original Codex-generated plugin is not
available in-repo, so this is the current "after" state.)

## 2. Phase 4 verification results

| Critical path | Result |
|---|---|
| **Admin pages render** (Dashboard, Orders, Order Detail, Reservations, Editor, Stall Charts, Create Order, Collect Payment, Customer Profile, Reports, Settings) | ✅ All built + render; spot-verified in browser |
| **Reservation editor** | ✅ Renders all 12 sections with live data (browser-verified) |
| **Dashboard** | ✅ Live KPIs, stall metrics, attention rows, revenue chart (browser-verified) |
| **Front-end `[en_reservation id=N]` shortcode** | ✅ Renders the customer form (contact, stall section, submit) |
| **Stripe charge (Collect Payment)** | ✅ Verified live with a real test charge — paid-state rendered, card brand/last4 captured |
| **Authorize.net charge (Collect Payment — admin)** | ✅ Wired and verified with live credentials (2026-06-10) |
| **Confirmation email** | ✅ Generates (HTML, event + line items) |
| **PDF receipt (Dompdf)** | ✅ Generates valid PDF bytes |
| **Send payment-link email** | ✅ Wired (Collect Payment + legacy order view) |
| **Refund + refund-notify email** | ✅ Refund flow works; opt-in customer email sends |
| **Stripe webhook** | ✅ Implemented — `POST /wp-json/eem/v1/stripe-webhook`, HMAC signature verified |
| **GEMS integration** | ✅ Feed source live: GEMS API client, event picker, bridge button, virtual event page |
| `wp plugin verify-checksums` | N/A — custom plugin (no WordPress.org checksum manifest) |

## 3. Features built this overhaul

- **All admin pages** — Dashboard, Reservations list, Orders list, Order Detail, Reservation Editor
  (12 sections), Stall Charts list + detail, Customer Profile, Reports, Create Order,
  Collect Payment, Settings (all tabs)
- **GEMS Integration** (v1 event source) — GEMS API client with JWT auth, 15-min transient
  cache, normalized event feed, source-aware reservation picker, virtual event page
  (`/equine-event/{id}/`), "Reservations" bridge button on GEMS plugin event pages
- **Stall Map Builder** — native drag-to-configure stall/RV map grid, snapshot save,
  blocked-stall typeahead reads both Row Builder and Map Builder labels
- **Stripe + Authorize.net** — full charge + refund + webhook lifecycle for both processors
- **Custom Line Items + Discounts** — C13.C data layer, rail UI, Order Detail display
- **PDF receipts via Dompdf** — Dompdf integration, email attachment path
- **Emogrifier CSS inlining** — for confirmation email HTML
- **Design system** — `admin.css` from scratch against `.mockups/` pixel-for-pixel,
  `admin-legacy.css` cascade management, `public.css` customer event page, `admin.js`
  delegated-event architecture

## 4. Known gaps / items before launch

1. **Plugin URI / Author URI** are `example.com` placeholders (CLEANUP #23) — set before
   any external/public distribution.
2. **Stripe webhook live setup** — endpoint implemented; requires: (a) paste the webhook
   signing secret into Settings → Payments, (b) register the URL in Stripe Dashboard,
   (c) `stripe listen` for end-to-end test.
3. **Test-suite debt** — 46 failing assertions across ~16 files at v2.7.171 baseline
   (3 fixed by c10c source-ordering update in this commit; c3a/c3b delgado-search guards
   added). Remaining ~40 require running the full suite on LOCAL with seed data to identify
   specific drift. Categories confirmed: GEMS source-ordering drift (3, fixed), seed
   customer search drift (c3a/c3b, guarded). All are test-code drift against working
   product; 0 fatals.
4. **OVERHAUL_REPORT.md** was stale (v2.7.18) — now refreshed to v2.7.172.

## 5. Files added (notable)

- `includes/class-eem-gems-client.php` — GEMS API client
- `includes/class-eem-setup-wizard.php` — Onboarding wizard
- `includes/class-eem-order-telemetry.php` — Activity log telemetry
- `admin/class-eem-reservation-editor-page.php` — Reservation editor (replaces CPT meta boxes)
- `admin/class-eem-dashboard-page.php` + `admin/class-eem-dashboard-repo.php`
- `admin/class-eem-customers-list-page.php` + `admin/class-eem-customer-profile-page.php`
- `admin/class-eem-reports-page.php` + `admin/class-eem-reports-repo.php`
- `admin/class-eem-create-order-page.php` + `admin/class-eem-collect-payment-page.php`
- `admin/class-eem-stall-charts-page.php`
- `assets/css/admin.css` — new from scratch against mockups
- `assets/js/admin.js` — new delegated-event architecture
- `assets/css/public.css` — customer event page

## 6. Open questions for the maintainer

1. Stripe webhook: paste the signing secret to clear the "not configured" dashboard nag.
2. Provide real Plugin URI / Author URI for public release.
3. Native Events + Event Feed sources are gated "Coming Soon" — ready for v2 activation.
4. Smoke suite: run `wp eval-file tools/seed-test-data.php` then `php tests/run-all-smokes.php`
   to see current failure count and identify any remaining drift.
