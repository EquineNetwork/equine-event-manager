# Equine Event Manager — Roadmap

**Last updated:** 2026-05-31
**Authoritative source for forward-looking plans.** decisions.md is the source for locked decisions; CLAUDE.md is the source for conventions/rules; this file is the source for what's planned but not yet built.

## Test-Ready Target
**June 12, 2026** — defined as: C10 customer flow works end-to-end. Admin pages can have rough edges. Real customers can pay through real Stripe (test mode).

## Launch Target
TBD — set after C10 verifies and customer flow proven on staging.

## Current Status (as of file creation)
- C7 (Edit Reservation) — DONE
- C8 (Stall & RV Charts: list + detail with All/Stalls/RV toggle + Print View) — DONE
- Current cache-bust: 2.3.38
- Next up: C10 customer-facing reservation page (BIG ONE — June 12 critical path)
- All search input + breadcrumb + hover conventions locked plugin-wide

---

## V1 RENDERING DECISION (LOCKED 2026-05-30)

**V1 = Shortcode only.** The plugin renders the customer reservation form via `[en_reservation id="N"]`. Plugin renders FORM ONLY, no hero. Hero / event info / page wrapper comes from the host theme or page builder.

Whitney's launch site setup: TEC (The Events Calendar) + Elementor Single Event template. The shortcode drops into the Elementor template.

V2 will revisit this when native EEM events come online — at that point we add Standalone mode (plugin-owned full page WITH hero) as a second option per-reservation.

---

## C10 — Customer-Facing Event Page (CRITICAL PATH for June 12)

Target mockup: `.mockups/event_page.html` (form sections only — skip hero per V1 decision)

### C10 Recon Findings (DO NOT RE-RECON)
- **Stripe:** fully operational. No SDK; direct `wp_remote_post()` to api.stripe.com/v1/. Settings panel has all 4 keys (test/live publishable + secret) + webhook signing secret. PaymentIntent creation via `ajax_create_stripe_payment_intent` works. Stripe.js Elements mount card fields. Webhook handler is C14 work.
- **Shortcode:** `[en_reservation id="N"]` already renders working form (~10,900 lines in public/class-equine-event-manager-shortcodes.php). C10 is visual rebuild, not feature build.
- **Orders:** Custom tables `wp_en_stall_reservations` + `wp_en_rv_reservations`. Grouped by `order_key` (MD5 hash). NO WooCommerce dependency anywhere.
- **Checkout:** Anonymous (no login required).
- **Email:** EEM_Mailer with SendGrid → wp_mail fallback. Customer receipt + admin notification both wired.
- **Add-ons:** Wired both admin + customer side (general add-ons + RV add-ons like water hookups).
- **Pre-Entries:** NOT a feature in the codebase yet (post-C10 backlog).
- **Cart/holds:** DO NOT exist yet. Submission-token idempotency only (transient, 24-hour, prevents double-submit). C10.H adds DB-level locking + 15-min hold timers per Whitney's directive.

### C10 Commit Sequence — DO NOT RESEQUENCE without Whitney's approval

#### C10.A — Public CSS rebuild
- Replace ~4,000 lines of inline CSS in `render_form_styles()` with mockup-faithful CSS
- Form sections only, no hero
- New `assets/js/public.js` stub
- Pure CSS swap — don't touch PHP logic
- Cache-bust 2.3.38 → 2.3.39

#### C10.B — DROPPED (V2 work)
Hero render was originally planned but moved to V2 per the V1 Rendering Decision.

#### C10.C — Form section shells + Settings UI fix (BUNDLED)

**Part 1 — Form sections:**
Port Contact, Stay Details, Stall, RV, Add-Ons, Group, Special Requests, Billing section card chrome to match the new CSS classes from C10.A.

**Part 2 — Settings UI fix:**
- Settings → Integrations page: reorder Event Source options
- TEC first (default selected for fresh installs)
- Native Events second, marked "Coming Soon" with disabled radio
- External Feed URL third, marked "Coming Soon" with disabled radio
- Hide or disable the Native Events and Feed URL connection panels for V1

#### C10.D — Stall picker (row-aware)
- Rebuild stall picker UI to match mockup
- Back-to-back rows with aisle visual divider
- Larger touch targets (44px min desktop, 38-40px mobile)
- Selection summary card below picker
- States: default / hover / selected / reserved / blocked
- Selection counter ("2 of 2 stalls selected")
- Existing JS picker logic mostly wired — primarily a visual rebuild

#### C10.E — Order sidebar + mobile drawer
- Desktop: sticky right-column sidebar with live line items + total + Reserve Now button + agreement notice
- Mobile: fixed bottom drawer with total + Review & Pay button
- Both update in real-time as customer adjusts quantities or stall selections

#### C10.F — Billing & payment section
- Port billing address block (first/last name, address, apt, city/state/zip, country)
- Port credit card block visual shell (Stripe Elements already wired)
- Maintain existing Stripe PaymentIntent flow
- Keep `stripe.confirmCardPayment()` on submit

#### C10.G — Smoke + wire-up + real-world test
- Wire new public.css + public.js to enqueue
- Add smoke assertions for key HTML landmarks (form sections, picker, sidebar, drawer)
- Confirm form submits end-to-end against Whitney's TEC test event
- Order writes to `wp_en_stall_reservations` + `wp_en_rv_reservations`
- Receipt email fires via EEM_Mailer
- Console clean during full purchase flow

#### C10.H — Cart + hold timers + inventory locking
- DB-level locking (SELECT ... FOR UPDATE pattern) to prevent overselling
- 15-minute hold transients when customer adds to cart
- Sold-out states when capacity hits zero (stall blocked from picker, RV lot grayed out)
- Race-condition safe stall allocation
- Required for launch per Whitney's directive (two customers paying for same stall simultaneously = unacceptable for V1 launch)

---

## REMAINING C-SERIES WORK (admin polish, post-C10)

These are admin-side mockup ports that can be done AFTER C10 customer flow works end-to-end. They don't affect the June 12 test-ready milestone.

### C9 — Customer Profile Page
- Mockup: `.mockups/customer_profile_page.html`
- Admin page showing individual customer history across all reservations
- Order history, contact info, lifetime spend
- Likely 1-2 days work

### C11 — Customer Confirmation Email
- Mockup: `.mockups/customer_confirmation_email.html`
- Email template polish — current template works but needs visual upgrade to match mockup
- Includes header, event summary, line items, payment confirmation, agreement
- Tied to admin Communications panel in Settings

### C12 — Order Receipt
- Mockup: `.mockups/order_receipt.html`
- Printable PDF-friendly receipt for orders
- Likely opens from order detail page like Print View opens from stall chart detail
- Customer can print or save PDF after purchase

### C13 — Create Order Page (admin-side manual order creation)
- Mockup: `.mockups/create_order_page.html`
- Admin creates an order manually on behalf of a customer (phone-in reservations)
- Same form structure as customer-facing C10 but admin-initiated
- Stripe charge OR mark as invoice (collect payment later)

### C14 — Collect Payment Page + Stripe webhook handler
- Mockup: `.mockups/collect_payment_page.html`
- Admin sends payment link to customer for invoice-style orders
- Customer arrives at this page via tokenized URL (already partially wired: `maybe_render_invoice_payment_page()`)
- ALSO INCLUDES: Stripe webhook handler
  - Verify webhook signatures using webhook signing secret from Settings
  - Handle `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`
  - Update order status accordingly
  - Transactional emails on webhook events

### C15 — Reports Page
- Mockup: `.mockups/reports_page.html`
- Reports hub menu page with multiple printable reports (see post-C10 backlog for full breakdown)

### C16 — Polish + Launch Verification
- Accessibility audit (ARIA, keyboard nav, color contrast WCAG AA)
- Mobile audit (all pages, all viewports — 390px, tablet, desktop)
- Browser compat (Safari, Firefox, Chrome, Edge — latest 2 versions each)
- Performance pass (CSS strip, lazy load, query optimization)
- Native `alert()`/`confirm()`/`prompt()` audit — replace with consistent UI pattern
- Final cross-mockup audit
- 1-2 days work

### DS-1 — Design System Page (Dashboard)
- Mockup: `.mockups/dashboard_page.html` (DS-1.B mockup exists, stable)
- Plugin dashboard with KPIs, recent orders, upcoming events, quick links
- Lower priority than C-series work — can ship without it

---

## V2 BACKLOG (parked — DO NOT BUILD IN V1)

### From Saturday morning RV painting design discussion (2026-05-30):
1. **Per-lot painting** — click individual lots to assign zones (currently row-level zone assignment only)
2. **Per-lot color dots** — visual zone indicator at lot granularity
3. **Per-zone Avail Qty admin-entered cap** — independent of painted/configured lots
4. **"Painted: N" computed display per zone** — live painted count per zone
5. **Three explicit modes** (Bulk / Bulk-with-zones / Mapped) — currently V1 is two-mode (Bulk / Mapped)
6. **Sub-row zone assignment** — when a row contains lots from multiple zones (premium corners vs. standard interior)
7. **`_en_rv_lot_zone_assignments` meta** — storage for lot-level paint state (cleaned up in V1)

### From C10 architecture discussion (2026-05-30):
8. **Standalone reservation page mode**
   - Per-reservation "Display mode" admin setting (Embed via shortcode / Standalone full page with hero)
   - V2 adds when native EEM events (`en_event` CPT) come online
   - Hero render (event title, dates, bullets, location/producer meta, Reserve Now / Directions CTAs)
   - URL routing for `/reservation/{event-id}` style URLs
   - Mockup `.mockups/event_page.html` has the full hero design already

9. **Native Events event source**
   - Currently shown as "Coming Soon" in Settings → Integrations (V1)
   - V2 work: `en_event` CPT, categories, venues, producers, widgets, shared frontend event template
   - Unlocks Standalone reservation mode (item 8)

10. **External Feed URL event source**
    - Currently shown as "Coming Soon" in Settings → Integrations (V1)
    - V2 work: JSON/XML feed parsing, sync schedule, mapping to reservations

### General V2 ideas (lower priority):
11. **Authorize.Net payment integration** — currently Stripe only; Authorize.Net adapter for clients who prefer it
12. **Customer accounts** — currently anonymous checkout; V2 could add optional login for repeat customers (auto-fill billing, order history)
13. **Multi-language support** — currently English only
14. **Multi-currency support** — currently USD only

---

## POST-C10 BACKLOG (after C10 lands, before launch)

### Pre-Entries Menu Page
- Dedicated tracking view aggregating pre-entry sales across all reservations
- Expected columns: Pre-Entry Name | Reservation | Capacity | Sold | Available | Price | Actions
- Post-C10 because needs customer purchase data flowing
- **NOTE:** Pre-Entries as a customer purchase feature doesn't exist in V1 either — likely V2 work along with this menu page. Confirm scope before building.

### Reports System — Full Reports Hub
Reports menu becomes a hub page with multiple printable reports. Mockup `.mockups/reports_page.html` exists for the hub.

1. **Stall Report** — hotel-itinerary-style by customer (sorted Last, First). Columns: Customer | Arrival | Departure | Nights | Stalls | RV Lots. Three view modes: Stalls only / RV only / Combined. Print-optimized for posting at venue check-in.
2. **Shavings Report** — by stall (delivery-route format): "Stall #100 / Whitney Mitchell / 2 bags". Total at bottom for ordering.
3. **Add-Ons Report** — by size/variant counts (T-shirts: 8S, 12M, 15L, 5XL) for ordering/fulfillment.
4. **Pre-Entries Sign-Up Sheet** — printable roster per class for posting at arena. (Depends on Pre-Entries feature shipping.)
5. **Customer Roster** — catch-all complete event audit list.
6. **Revenue Report** — accounting/reconciliation, broken down by stalls/RV/pre-entries/add-ons.

All post-C10 — need real customer-purchase data flowing first.

### Plugin Health Audit
- EEM_Admin class is 9,168 lines — needs review
- Documentation-only audit, no refactor planned
- Identifies tech debt for V2 cleanup
- Post-C10 work, low priority

---

## POST-LAUNCH BACKLOG (after launch, ongoing)

### Marketing site
- Plugin marketing pages
- Customer testimonials
- Pricing page
- Documentation site

### Plugin marketplace
- Submit to WordPress.org plugin repository (if going free tier)
- Submit to Envato / CodeCanyon (if going commercial)
- Stripe Marketplace listing

### Support infrastructure
- Knowledge base / docs
- Support ticket system
- Onboarding emails for new admins

---

## LOCKED DESIGN CONVENTIONS (cross-reference)

These are in decisions.md and CLAUDE.md — listed here for forward-planning visibility:

- **Fonts:** Space Grotesk (headers) + IBM Plex Sans (body)
- **Breakpoints:** 1280px desktop, 390px phone, mobile-first
- **Border radius:** 3px on admin, 8px on customer-facing
- **Gold (#d4a017):** Featured/Boost only — never general use
- **Search input placeholder:** exactly "Search" (case-sensitive, no longer variants)
- **Search input icon padding:** 25px minimum
- **Breadcrumb links:** dark navy resting, bright blue hover, NO underline
- **Universal hover:** NO underline on hover anywhere (CLAUDE.md hygiene rule #8)
- **Complete-page commits:** never page slices (Standing Rule 17)
- **Mockups must be seeded:** realistic data, no placeholders (Standing Rule 18)
- **Visual parity check:** new pages must match Edit Reservation reference (Standing Rule 19)
- **Deletion safety:** type "DELETE" (case-sensitive), validated client + server
- **WordPress chrome suppression:** `body.eem-shell-page--print` and similar body classes for full-page takeover

---

## DIVISION OF LABOR (locked 2026-05-30)

**Strategic chat (claude.ai):**
- Roadmap maintenance (this file gets updated by Claude Code; strategic chat references it)
- Bigger ports (multi-commit features like C10)
- Recovery doc + chat session log (CHAT_RECOVERY.md)
- Auditing Claude Code reports when second opinion wanted
- Decision parking (new ideas → backlog)
- Locking new conventions when patterns emerge
- Recon prompts before big builds

**Whitney + Claude Code direct:**
- Visual bug fixes
- CSS tweaks
- Convention violations spotted in-browser
- Small adjustments — plain-English requests, no over-engineered prompts
