# Equine Event Manager — Roadmap

**Last updated:** 2026-06-01 (cache-bust 2.3.76 — C10.D landed; see the 2026-06-01 session block below)
**Authoritative source for forward-looking plans.** decisions.md is the source for locked decisions; CLAUDE.md is the source for conventions/rules; this file is the source for what's planned but not yet built.

> **2026-05-31 reconciliation pass (Claude Code).** Whitney asked for a verification
> that this roadmap matches the actual code. Every C10-recon technical claim below was
> re-checked against the repo and **confirmed accurate**:
> - `[en_reservation id="N"]` shortcode is registered and renders the working customer
>   form from `public/class-equine-event-manager-shortcodes.php` (8,639 lines).
> - Stripe is fully operational — `ajax_create_stripe_payment_intent` →
>   `wp_remote_request('https://api.stripe.com/v1/...')`, no SDK.
> - Custom tables `wp_en_stall_reservations` + `wp_en_rv_reservations` exist (activator).
> - C14 invoice page is partially wired (`maybe_render_invoice_payment_page` on
>   `template_redirect`).
>
> The only stale part was the "Current Status" snapshot below, which predated the
> C8 / DS-1.B / 2.3.50–2.3.52 work. Updated to match reality.

## Test-Ready Target
**June 12, 2026** — defined as: C10 customer flow works end-to-end. Admin pages can have rough edges. Real customers can pay through real Stripe (test mode).

## Launch Target
TBD — set after C10 verifies and customer flow proven on staging.

## Current Status (updated 2026-05-31, cache-bust 2.3.52)

**Built & verified (admin-side authoring surfaces):**
- C1–C6 foundation (admin.css/js, activity log, Settings, Reservations list, Orders list, Order Detail) — DONE
- C7 (Edit Reservation editor — all sections, stall row builder, Event Day Info, per-reservation cancellation policy, publish validation, typed-confirm delete) — DONE
- C8 (Stall & RV Charts: list + detail with All/Stalls/RV toggle + Print View) — DONE
- DS-1.A (cross-page design-system fidelity) + DS-1.B (Admin Dashboard) — DONE
- Customer reservation form via `[en_reservation]` shortcode — FUNCTIONAL (C10 is a visual rebuild, not a feature build); Stripe checkout operational

**Recent maintenance (post-C8):**
- 2.3.47–2.3.49 — editor + single-event rebuilds, toggle OFF-state persistence
- 2.3.50 — removed Stall/RV chart enable toggle from the editor; chart presence derived from `_en_stalls_enabled` / `_en_rv_enabled` (write-path + list query only)
- 2.3.51 — Bulk-mode admin auto-assignment UI (conflict-safe, no full reload)
- 2.3.52 — **completed the 2.3.50 gate retirement**: 5 read-path gates still consulted the dead `_en_stall_chart_enabled` meta, leaving the Stall Chart Detail page (and the 2.3.51 auto-assign UI it hosts) showing "disabled". All consumers now derive presence from `_en_stalls_enabled OR _en_rv_enabled` (commit `8302e7c`)
- 2.3.53 — **C10.C landed.** Part 2: Settings → Integrations event source reordered TEC-first (fresh-install default); Native Events + External Feed marked "Coming Soon" with disabled radios + hidden panels (`db937b5`). Part 1: customer `[en_reservation]` form restyled to the `event_page.html` mockup — white section cards, uppercase header bands, mockup field/input chrome, blue toggle, rider cards — via a CSS-only scoped override under `.eem-event-page` (zero markup change → 30-field Stripe payload + every JS hook provably intact; `48d6ccc`).

#### 2026-06-01 session (2.3.54 → 2.3.76) — C10.C/E sign-off batch, two new customer features, name-inheritance, C10.D
- **2.3.54–2.3.64** — event-page polish: hero-only when no reservation, order-summary rebuild (navy header, blue total, green info box, gradient Reserve Now), circular qty steppers, full-width Complete Reservation button, cancellation-policy display, Nights-field height, venue/organizer plain text (TEC has no archive pages), Special Requests header.
- **2.3.65** — **C10.E polish + gate robustness:** hero width 1300 + image/form alignment, brand fonts (IBM Plex Sans + Space Grotesk) page-wide, billing/credit-card sub-cards → mockup `.billing-sub-box`, Total Amount Due gray box removed, producer phone dash-format, **gate robustness** (a gated save can no longer orphan the `_en_event_id` link / lock the admin out), admin notice ×-centering, **Reservation Name/Slug editing removed** (Quick Edit deleted; names inherit the linked event).
- **2.3.66** — **Add-ons ungated** (shavings/hay etc. purchasable without a stall/RV); **Cancellation Policy field removed from Settings** (per-reservation now; stored value preserved); Special Requests card restructured.
- **2.3.68** — **NEW FEATURE: Event Pre-Entries** on the customer form (purchasable class/division pre-entries; card + live totals + Order Summary lines + server charge + order itemization; per-customer cap validated; total-inventory enforcement deferred).
- **2.3.70** — **Check-In/Check-Out → time-only** (editor `type="time"`, `H:i` storage, legacy datetime graceful-convert); rendered as **icon time pills in Stay Details** ("after 10:00am" / "by 4:00pm").
- **2.3.71** — **Event Day Info now renders on the customer form** in Stay Details (Check-In/Check-Out Instructions / What to bring / Parking / Event Contact); bold "Available reservation dates" line.
- **2.3.72** — **Available Reservation Dates save bug fixed** (Stall + RV editor sections shared one field name; the empty one wiped the value on submit — now JS-synced; auto-defaults to event dates via the existing `populate_available_dates_from_event` + manual-edit flag).
- **2.3.74** — hero blue bullet removed; Event Day labels finalized; **phone/email clickable** (`tel:`/`mailto:`) in Stay Details + hero; **NEW: Venue Map card** (editor section above Agreement, PDF/image upload) + "Download Venue Map" link in Stay Details.
- **2.3.75** — **Complete Reservation button clip fixed for real** (root cause: `width:100%` + inherited `margin:0 22px` overflowed the card by 44px; fix = `display:block;width:auto`); Event Day "Appears as:" editor hints removed.
- **2.3.76** — **C10.D LANDED: "Pick Your Stalls" interactive stall picker.** Tap-to-select grid from canonical `_en_stall_rows` (one-sided strips + back-to-back rows with aisle); states available/reserved/blocked/selected; label expander handles `100`/`Y1`/`A-01`; live "N of M selected" + "Your stalls: #X" + max cap; posts `preferred_stall_units[]` (existing server field → zero server changes). Smoke `c10d-stall-picker-smoke` 19/19.

**Next up:** human visual sign-off on the live event page (button, Venue Map upload→download, stall picker, Event Pre-Entries). Then **C11 (Confirmation Email)**. Remaining C10 verification: checkout dispatch (Stripe/Auth.net) deferred to end-of-build testing per Whitney; reserved-stall inventory refinement (date-aware) is a follow-up.
- All search input + breadcrumb + hover conventions locked plugin-wide.
- **Note:** the C10 commit-sequence cache-bust numbers below (e.g. "2.3.38 → 2.3.39") were
  written when current was 2.3.38; bump from the then-current version (2.3.52+) when executing.

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
- **Pre-Entries:** ~~NOT a feature yet~~ → **SHIPPED 2.3.68** as a purchasable customer-form section (Event Pre-Entries), driven by the editor's pre-entries config. Total-inventory enforcement (stock across all orders) still deferred.
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

#### C10.D — Stall picker (row-aware) — ✅ DONE (2.3.76)
- ✅ "Pick Your Stalls" grid rebuilt to the mockup, driven by canonical `_en_stall_rows` (replaces the legacy block-range selector)
- ✅ Back-to-back rows render two sides + aisle divider; one-sided rows render a single strip
- ✅ Touch targets 44px desktop / 36px mobile (responsive)
- ✅ Selection summary card (count + "Your stalls: #X, #Y" + max warning)
- ✅ States: available / hover / selected / reserved (occupied by existing orders) / blocked (`_en_blocked_stalls`)
- ✅ Label expander handles `100`/`Y1`/`A-01`; selected stalls post `preferred_stall_units[]` (existing server field → no server changes)
- ✅ Smoke `tests/smoke/c10d-stall-picker-smoke.php` 19/19
- ⏳ **Follow-up:** "reserved" is currently date-agnostic (any unit occupied by any order is marked Taken). Refine to per-selected-date availability later.

#### C10.E — Order sidebar + mobile drawer — ✅ largely DONE (2.3.59 / 2.3.65)
- ✅ Desktop sticky right-column Order Summary (navy header, event name/dates, line items, blue total, green info box, gradient Reserve Now, secured footer, agreement notice + cancellation policy)
- ✅ Live updates as quantities / stall selections change (incl. Pre-Entries 2.3.68)
- Mobile fixed bottom drawer markup exists; verify on visual pass

#### C10.F — Billing & payment section — ✅ largely DONE (2.3.65 / 2.3.75)
- ✅ Billing address block + Credit Card sub-cards ported to mockup `.billing-sub-box` (eyebrow titles, #FAFBFE fill)
- ✅ Stripe Elements shell + PaymentIntent flow intact; Complete Reservation button fixed (2.3.75)
- ⏳ End-to-end Stripe/Auth.net charge test deferred to end-of-build per Whitney

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

### C11 — Customer Confirmation Email ✅ DONE (2.3.86)
- Mockup: `.mockups/customer_confirmation_email.html`
- Mockup-faithful template (`templates/emails/confirmation.php`) replaces the legacy
  settings-body + token render.
- Emogrifier (`pelago/emogrifier`) installed; `EEM_Mailer::inline_css()` inlines the
  `<style>` block at send-time. Runtime `vendor/` committed (self-contained).
- `EEM_Shortcodes::build_confirmation_email_html()` maps the order payload → template.
- Decision-locks: "Your Assignments" omitted while unassigned; PDF note + hosted link
  withheld until C12; Event Day Info gated on `_en_event_day_enabled`; cancellation
  from `_en_cancellation_policy_override`.
- Fixed in passing: `get_order_stall_breakdown()` read shavings prices UNPREFIXED
  (always 0) — corrected to `_en_`-prefixed so the Required Shavings line splits
  correctly (total unchanged). See decisions.md C11.
- Verified: `tests/smoke/c11-confirmation-email-smoke.php` (29/29).

### C12 — Order Receipt (PDF) + Hosted Order Page 🔨 IN PROGRESS
- Mockup: `.mockups/order_receipt.html`
- **Foundation landed:** Dompdf (`dompdf/dompdf ^2.0`) installed, runtime `vendor/`
  committed, PDF generation verified in the WP runtime.
- **Kickoff decisions (see decisions.md C12):** persist `tax` + `tax_rate` columns
  (migration; zero rows to backfill — orders table empty); token-bearer access for the
  hosted page (unguessable `order_key`, suits anonymous checkout); defer the
  denormalized `reservation_id` column (order payload already resolves it from notes).
- **Key finding:** tax is computed at checkout but never persisted — row totals exclude
  it, so the stored order total understates the charged amount when tax is on.
  Persisting tax fixes that AND corrects the C11 email total.
- **Increment 1 ✅ (2.3.87):** tax persistence — `tax` + `tax_rate` columns on both
  order tables (verified on live DB); checkout writes order tax once (no double-count);
  grouping sums tax + adds it to the order total (also fixes the C11 email total).
  Smoke 7/7. Refund-of-tax flagged as a separate follow-up (payment-adjacent). Live
  checkout write-path verification still pending.
- **Increment 2 ✅ (2.3.88):** receipt template + builder — `templates/receipt/receipt.php`
  (table-based for Dompdf compat) + `build_receipt_html()` (Customer/Billing, Reservation
  Summary cards, itemized totals + Sales Tax line). Extracted shared
  `build_order_line_items()` (C11 email + C12 receipt). Smoke 25/25; C11 still 29/29;
  Dompdf renders a valid PDF. HTML+PDF previews on Desktop.
- **Remaining increments:** (3) PDF generation → attach to confirmation email
  (re-enables C11's PDF note) + downloadable from Order Detail; (4) hosted order page via `template_redirect` +
  `order_key` query var (re-enables C11's hosted link); (5) smokes.

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
