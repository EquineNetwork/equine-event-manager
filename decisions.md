# Product Decisions

This document captures product decisions made during mockup design that don't fit naturally in the field map or workflow files. Reference this when Phase 3+ implementation hits the relevant questions.

Each decision is dated. If a decision is later overturned, leave the original entry and add a new one below it with the new date and rationale.

---

## TEC Integration — Lifecycle & Boundaries

### TEC-1. Event deletion with linked reservation
**Decided:** 2026-05-21

When a TEC event is deleted (via Move to Trash, bulk delete, or any other normal WP path), the plugin permits deletion but takes a soft-orphan approach:

- Plugin hooks `before_delete_post` / `wp_trash_post` to detect deletion attempts on TEC event posts that have a linked reservation
- Shows a confirmation dialog: "This event has [N] active reservations linked. Deleting will unlink them but preserve all customer records. Continue?"
- On confirm, deletion proceeds. The reservation's `event_id` becomes null.
- Reservations continue working — event name, dates, venue, and other event metadata are already snapshotted on the reservation at creation time (needed for receipt PDFs anyway).
- Customer never sees a difference; receipts and confirmation emails still show the original event details.
- Data layer must gracefully handle `event_id IS NULL` everywhere (don't crash on missing event).

### TEC-2. Event date changes in TEC
**Decided:** 2026-05-21

When a TEC event's date changes AND it has a linked reservation, the plugin requires explicit admin confirmation:

- Plugin hooks TEC's save action to detect date changes
- If linked reservation exists, intercept the save and show a confirmation dialog listing the reservation
- Admin chooses one of:
  - "Update reservation to new dates" — reservation's snapshotted dates update to match
  - "Keep reservation on old dates" — reservation's snapshotted dates stay frozen
- Separate checkbox: "Notify affected customers via email"
- If notify is checked, queue customer emails using the existing email template system with `[event_name]`, `[old_dates]`, `[new_dates]` placeholders

### TEC-3. One event = one reservation
**Decided:** 2026-05-21

Strict 1:1 relationship between TEC events and reservations. A given TEC event can only be linked to one reservation.

- All product types for an event (stalls, RV, group, add-ons, agreement, fees) live as sub-sections within the single reservation — the current Edit Reservation page structure is correct
- When admin creates a new reservation and tries to link to a TEC event that already has a reservation: Event Link search results show that event as disabled/grey with a note "Already linked to: [Existing Reservation Name]"
- Clicking the disabled event prompts: "Edit the existing reservation instead?" with a link to the existing reservation

### TEC-4. Direction of data flow
**Decided:** 2026-05-21

Read-only relationship from plugin to TEC.

- Plugin reads TEC event data: name, dates, venue, custom fields. Caches/snapshots as appropriate.
- Plugin **never** writes back to TEC. No syncing of attendee counts, sold-out status, ticket counts, or any reservation-derived data into TEC fields.
- Source of truth: TEC owns event metadata; plugin owns reservation data. No overlap, no race conditions.

**Specific field ownership (for the customer-facing event page hero):**

| Field | Owner | Notes |
|---|---|---|
| Featured image | TEC | Rendered at top of hero |
| Event title | TEC | Rendered as `<h1>` |
| Event date range | TEC | Rendered below title |
| Event description / bullets | TEC | Rendered in hero info column |
| Venue name + address | TEC | Rendered in Location meta block |
| Producer / organizer | TEC | Rendered in Producer meta block |
| Reservation form (Contact, Stalls, RV, Add-ons, Group, Agreement, Fees) | Plugin | The entire form below the hero |
| Order summary sidebar | Plugin | Total + Complete Reservation button |
| Reservation Description (per-section intro text) | Plugin | Custom per-reservation copy that appears within reservation sections |

When admin needs to change a TEC-owned field, they edit the event in TEC (not in the plugin's Edit Reservation screen). The plugin's Edit Reservation screen only edits plugin-owned fields.

---

## Refunds & Cancellations

### REF-1. Refund initiation paths
**Decided:** 2026-05-21

Refunds can be initiated from either the plugin's Order detail screen OR directly in the merchant dashboard (Stripe/Authorize.net). Both paths must keep order state in sync.

**Plugin → Merchant flow:**
- Admin clicks "Refund" in the plugin's Order detail screen
- Plugin calls merchant API to issue the refund
- On API success, plugin marks the order refunded and logs the event

**Merchant → Plugin flow:**
- Admin issues refund directly in Stripe/Authorize.net dashboard
- Merchant fires a `charge.refunded` (Stripe) or equivalent (Authorize.net) webhook to the plugin's endpoint
- Plugin receives webhook, validates the signature, updates order status to refunded, and logs the event

**Implementation requirements:**
- Public webhook endpoint, signed verification, idempotent (re-receiving the same webhook doesn't double-process)
- Settings → Payments tab includes the webhook URL for admin to paste into Stripe / Authorize.net account settings (one-time setup)
- Order activity log shows refund source: "Refunded via Stripe dashboard on [date]" vs. "Refunded via plugin on [date]"
- Both paths converge on the same Order state values ("Refunded" or "Partially Refunded")

### REF-2. Partial refunds
**Decided:** 2026-05-21

Partial refunds supported. Multiple partial refunds per order allowed.

- Order detail screen "Refund" action opens a panel with:
  - Refund amount field (defaults to remaining refundable amount; admin can enter any amount up to the remaining)
  - Reason field (free text, optional, stored on the refund record)
- Refund processes via merchant API (both Stripe and Authorize.net support partial refunds natively)
- Multiple partial refunds per order allowed
- Order status:
  - `total refunded = 0` → "Paid"
  - `0 < total refunded < order total` → "Partially Refunded"
  - `total refunded = order total` → "Refunded"
- Activity log shows each refund event: timestamp, amount, source (plugin vs webhook), reason
- Webhook handler (REF-1) must support partial refund events from the merchant side too

### REF-3. Bulk refunds (event cancellation)
**Decided:** 2026-05-21 · **Updated:** 2026-05-22 (see ORD-2)

> **Updated 2026-05-22:** ORD-2 splits this into two paths — a "Cancel Event" button on Edit Reservation (implicit selection = all orders against this reservation, the common case) AND checkboxes + Refund Selected on the Orders list (explicit selection for the rare hand-picked case). Both paths feed the same engine described below. See ORD-2 for the reconciliation rationale.

Bulk refunds happen via the Orders list page, not a dedicated event-cancel button.

- Orders list page has existing filters (by event, by status, by date range). Admin filters to the relevant scope.
- Bulk action dropdown gains: "Refund Selected"
- On selection, modal opens: "Refund [N] selected orders for [$total]?" with a reason field and a "Notify customers via email" checkbox
- On confirm:
  - Refunds queue and process asynchronously via the merchant API (one at a time, respecting rate limits)
  - Progress UI shows "Refunding [N] of [M]…" updating live
  - Failures (expired card, deauthorized account, etc.) collected into a "Needs Attention" list at the end with per-order error messages so admin can follow up manually
- Each refund in the bulk creates the same Order record + activity log entries as a single refund — data shape stays consistent regardless of initiation path
- Customer notification email uses a configurable template ("Event Cancelled — Refund Processed") with standard placeholders

### REF-4. Refund window
**Decided:** 2026-05-21

Soft refund window, configured per-reservation, never per-system.

- Edit Reservation → Fees section gains an optional field: "No-refund window (days before event)" with hint "Customers will see this policy on the reservation form. Admin can override at refund time."
- Blank or zero → no window, no warnings ever
- If field has a value (e.g. `7`):
  - When admin attempts a refund within N days of event start (or after event end), modal shows a warning: "⚠️ This reservation is within the no-refund window ([N] days before event). Proceeding will override the published policy. Continue?"
  - Admin can dismiss and proceed. The override is logged in the activity log: "Refund processed during no-refund window by [admin name]"
- Front-end consequence: the customer-facing reservation form displays the refund policy in the Stay Details or Agreement section
  - Default copy auto-generates from the field value: "Refunds are not available within [N] days of the event start date"
  - Admin can override with custom copy in a sibling field if desired

---

## Customer Event Page (`event_page.html`)

### EVT-1. Hero buttons and links
**Decided:** 2026-05-21

- **"Reserve Now" button (hero):** smooth scrolls down to the reservation form (first form section). No focus shift, no auto-submit.
- **"Directions" button (hero):** opens the venue address in the platform's native maps app. iOS user agents → Apple Maps URL. All others → Google Maps. Same address that displays in the Location meta block, URL-encoded.
- **Mobile sticky "Review & Pay" drawer:** at narrow viewports, a sticky bottom drawer shows "Total Amount Due" + a "Review & Pay" button. Tapping the button smooth-scrolls to the Order Summary card. The drawer is hidden on desktop.

### EVT-2. TEC vs plugin-owned hero data
**Decided:** 2026-05-21

The hero section renders from a mix of TEC-owned and plugin-owned fields. See decision **TEC-4** for the full ownership table. The bullet list / event description in the hero is entirely TEC-owned content. Reservation-specific copy (Stay Details, Stall Description, RV Description, etc.) renders below the hero and is owned by the plugin.

### EVT-3. Contact Information section
**Decided:** 2026-05-21

- **Required fields:** First Name, Last Name, Email, Phone.
- **Phone input:** international support with country code dropdown. Defaults to US (+1) but customer can change.
- **New optional field:** "Business / Stable Name" — single-line text input between Last Name and Email. Customers often want this on invoices/receipts.
- **Auto-fill from prior visits:** save First Name + Last Name + Email + Phone + Business Name in `localStorage` after a successful reservation. Pre-populate these fields on subsequent visits. No login required. Customer can edit/clear at any time.

### EVT-4. Per-section Available Reservation Dates
**Decided:** 2026-05-21

Each product section (Stalls, RV, future ones) has its own "Available Reservation Dates" — distinct from the event's actual date range (which is TEC-owned).

- **Removed:** the previously-global "Available Reservation Dates" card from Edit Reservation.
- **Added:** an "Available Reservation Dates" field inside each product section in Edit Reservation (Stall Reservations, RV Reservations).
- Customer-facing rendering: each section shows its own dates in the section subheader.
- Example: event runs May 8–10 in TEC. Admin sets stall reservation dates to May 7–11 (one day arrival cushion + one day departure cushion). Admin sets RV dates to May 8–10 only. Customers see different windows per section.

### EVT-5. Stall Reservations on customer page
**Decided:** 2026-05-21

- **Picker visibility:** the visual stall picker only renders when admin has chosen "Exact Map Selection" mode for this reservation. In "Quantity Based" mode, only the quantity stepper renders (no stall grid).
- **Picks are optional:** customer can leave selections blank — system auto-assigns N stalls (where N is the customer's QTY) from available ones at checkout. Customer-facing copy makes this clear: "If you'd rather have stalls auto-assigned, leave selections blank and we'll assign them after checkout."
- **Validation:** customer cannot pick MORE stalls than their QTY. Customer can pick FEWER (auto-assign fills the gap).
- **Real-time stall availability with pending holds:**
  - Server keeps a "pending hold" record per session — e.g. "Session abc123 holds stalls 124, 125, expires in 15 minutes"
  - Clients poll the hold state at a regular interval (every 10–15 seconds is the pragmatic default; WebSockets are heavier infra and not required for v1)
  - Stalls in someone else's hold render in the grey "reserved/taken" state for other customers
  - Hold expires automatically if customer abandons the page or checkout takes too long
  - On successful checkout, hold converts to a permanent reservation
  - Two customers cannot end up with the same stall — server enforces the hold on checkout regardless of what client thinks

### EVT-6. RV Reservations on customer page
**Decided:** 2026-05-21

RV Reservations mirror Stalls in every meaningful way:

- **Mode toggle:** admin chooses Quantity Based or Exact Map Selection per reservation
- **Builder:** same Row Builder pattern as stalls (One-sided or Back-to-back per row, free-form labels like `1–25`, `A–Z`, `RV-01–RV-25`)
- **Customer picker:** same visual picker pattern when Exact Map Selection is the mode
- **Real-time holds:** same pending-hold system as stalls (decision EVT-5)
- **Picks are optional:** customer can leave selections blank, system auto-assigns at checkout

**Lot Zones (RV-specific concept):**

Where stalls have one flat price, RV lots support multiple pricing zones within a single reservation. Admin defines zones (e.g. "Red Lot $35/night, Blue Lot $25/night") and assigns each lot to a zone via click-to-paint in the admin row builder.

- **Required:** every lot must belong to exactly one zone. The default zone for a new lot is the first defined zone.
- **Zone schema:** `{ id, name, color, nightly_rate, weekend_rate }`
- **Lot-to-zone assignment storage:** mapping of lot label → zone id, scoped per row card (e.g. Row A's lot 1 might be `z1`, lot 7 might be `z2`)
- **Admin UI:** "Paint Mode" dropdown above the row builder. Select a zone → click lots to assign them to that zone. Each lot in the row preview shows a colored dot indicating its current zone. Selecting "Off" disables painting (clicks do nothing). Selecting the same zone the lot is already in is a safe no-op.
- **Deleting a zone:** lots assigned to the deleted zone are reassigned to the first remaining zone. If only one zone exists, deletion is blocked with an alert.
- **Customer-facing rendering:** each clickable lot shows its zone color as a small dot. Tapping a lot adds it to selection at that zone's price. The running total in Order Summary reflects mixed pricing across selected lots.

### EVT-7. Group Reservations
**Decided:** 2026-05-21

- **Per-rider fees** (admin-configurable):
  - "Rider Grounds Fee" — toggleable in admin, with an amount. Charged per rider in the group.
  - "Rider Deposit" — toggleable in admin, with an amount. Charged per rider in the group.
  - Total group cost = QTY × (Grounds + Deposit). $0 if both toggles are off.
- **Rider fields:** First Name + Last Name only for v1. No horse name, no age category, no custom field builder. Defer to v2 if customers ask.
- **Group leader / point of contact:** no separate concept. The person filling out Contact Info IS the de facto group contact. First rider in the roster has no special status.
- **Customer UI:** quantity stepper above the rider list. Increasing QTY adds rider cards (each with First/Last). Decreasing removes the trailing cards.

### EVT-8. Special Requests
**Decided:** 2026-05-21

- **Always rendered** on the customer-facing event page. No admin toggle to disable.
- **Optional field** — customers can leave it blank.
- **Copy is hardcoded** (not admin-configurable): heading "Special Requests" and helper text "Please let us know if you have any special requests for your stay including stallion accommodations, preferred contestant proximity stalling, etc."
- **Storage:** value stored on the order record, shown to admin in Order Detail screen.

### EVT-9. Billing & Payment
**Decided:** 2026-05-21

- **"Billing same as contact" checkbox** at the top of the Billing Details box. Unchecked by default. When checked, billing fields auto-populate from the Contact Info section above and collapse/hide. When unchecked, billing fields reappear and stay independently editable.
- **Credit card UI:** processor-native, not custom-styled. When Stripe is the active processor in Settings, the card section embeds Stripe Elements. When Authorize.net is active, it embeds Authorize.net Accept.js. The plugin never renders raw card number / CVC / expiration inputs. This is the right call for PCI compliance and reduces our attack surface.
- **Payment required at checkout** — no deposits, no pay-later, no invoicing from the customer side. The admin-facing Invoicing screen is a separate workflow (admin-initiated only).

### EVT-10. Order Summary sidebar
**Decided:** 2026-05-21

- **Sticky on desktop.** `position: sticky` with a top offset for the page header. Sidebar stays visible as customer scrolls. On mobile, the sticky bottom drawer (EVT-1) replaces this role; the sidebar collapses into the form flow.
- **Real-time updates with ~300ms debounce.** Any change to a quantity stepper, stall/lot selection, add-on toggle, or rate type triggers an immediate recalc. Text-field interactions (e.g. customer typing a quantity manually) are debounced to avoid recalculating on every keystroke. Final total appears within a third of a second of the last interaction.
- **Two submit buttons, same behavior.** "Reserve Now" (in the sticky sidebar) and "Complete Reservation" (at the bottom of the form) both submit. Same validation, same handler, same flow. Sidebar's button is a convenience for users near the top of a long form; bottom button is the canonical position. If validation fails, both behave identically: scroll to first invalid field, show inline error.

---

## Admin Dashboard (`dashboard_page.html`)

### DASH-1. Welcome bar buttons
**Decided:** 2026-05-22

- **"Create Order"** button → links to new `create_order_page.html` (admin manually enters customer + reservation + line items). This screen doesn't exist yet — Phase 3 will need to build it. Data model: same shape as a customer-initiated order, but `created_via = "admin_manual"` for traceability.
- **"View Reservations"** (renamed from "View Stall Charts") → links to `reservations_page.html`. More useful as a high-level CTA — the reservations list is the most common admin destination from the dashboard.

### DASH-2. Metric range filter
**Decided:** 2026-05-22

- Dropdown above the 4 metric cards lets admin scope the metrics to a date window. Options: **Last 7 days · Last 30 days · Last 90 days · This year · All time**.
- Default to **Last 30 days** on first load.
- Selection persists in `localStorage` per admin (key `eem_dashboard_metric_range`) so the choice sticks across visits and sessions.
- Metric values + delta-comparison labels ("↑ 12% vs last month") recompute based on the selected range.

### DASH-3. Upcoming Reservations card
**Decided:** 2026-05-22

- **Row click target:** entire row links to Edit Reservation for that reservation. Cursor changes to pointer on hover.
- **Stall-assignment progress bar:** click on the bar itself (with event.stopPropagation) opens that reservation's Stall Chart Detail screen. Click anywhere else on the row goes to Edit Reservation. This gives two click targets — bar for chart, rest of row for edit.
- **Sort order:** by "opens in N days" — most imminent first. Reservations that are already open use the days-until-event date instead.
- **"View all →" link** in the card header → `reservations_page.html`.

### DASH-4. Needs Attention card
**Decided:** 2026-05-22

Trigger conditions that generate a Needs Attention entry on dashboard load:

| Condition | Threshold |
|---|---|
| Unassigned stalls within 14 days of event start | Any reservation with `assigned < total` stalls within 14 days |
| Unpaid orders | Any order with status `Unpaid` AND age > 7 days |
| Unsigned agreements | Any order with required agreement = unsigned, event within 7 days |
| Stripe/Authorize.net webhook not configured | Active processor's webhook secret blank in Settings |
| Reservations with no stall chart set up | Reservation enabled stall section but defined zero stall rows, event within 30 days |
| Failed refunds requiring manual follow-up | Refund attempted via plugin or webhook returned an error |
| Customer emails bounced | Bounce notification received from email provider |
| Reservations expiring soon | Event end-date passed but reservation status is not "Archived" |

- Each row is a clickable link to the relevant screen for resolution.
- **Acknowledge / dismiss:** each row has an "Acknowledge" affordance (X icon or right-side action) that hides the item for 7 days. After 7 days, if the underlying condition is still true, the item reappears.
- **Auto-resolution:** if the underlying condition resolves (e.g. stall gets assigned, webhook gets configured), the item disappears on next dashboard load — no manual dismiss needed.
- **Storage:** acknowledged-until timestamps stored per item in a `wp_options` row (e.g. `eem_attention_acks`) keyed by a deterministic item-id derived from the condition + entity. Acks survive logout/login.

### DASH-5. Recent Orders card
**Decided:** 2026-05-22

- Shows the 5 most recent orders across all reservations, sorted by creation date descending, regardless of status.
- Each row is clickable → that order's Order Detail screen.
- "View all →" link in card header → `orders_page.html`.

### DASH-6. Quick Actions card (right sidebar)
**Decided:** 2026-05-22

- **Create Order** → `create_order_page.html` (same target as welcome bar button — duplicate is fine, top button is for navigation-style CTA, side card is for fast common actions)
- **Stall Charts** → `stall_charts_page.html`
- **Collect Payment** → `orders_page.html?status=unpaid` (Orders list pre-filtered to unpaid status)
- **Export Report** → `reports_page.html` (lands on Reports page; admin configures export from there)

### DASH-7. Revenue by Reservation chart (right sidebar)
**Decided:** 2026-05-22

- Bar chart shows the top 5 reservations by **lifetime** revenue, independent of the Metric Range Filter (DASH-2).
- Reasoning: the chart is intentionally a strategic view (which events drive the business overall) rather than a tactical one (what happened in the last 30 days). Tactical metrics are already covered by the filtered metric cards above.

---

## Reservation Setup Architecture

### RES-ARCH-1. Title + event dates are read-only mirrors of the source event
**Decided:** 2026-05-23 (during C5.G verification of the Reservations list)

A reservation post in this plugin represents a **setup configuration** (stall types, capacity, pricing, add-ons, blocked stall numbers, stall-chart layout, etc.) bound to a **source event** owned by an external system. The source-of-truth for identity fields (title, start_date, end_date, venue) is **always the source event**, never the reservation post.

**Source event authority** comes from whichever event source is active in Settings:
- **Native Events** — `en_event` CPT managed inside this plugin
- **The Events Calendar (TEC)** — `tribe_events` CPT managed by the TEC plugin
- **External Feed URL** — pulled from a JSON feed configured in Settings

**Contract on every render:**
1. Reservation title displays = source event's title (resolved live on render)
2. Reservation dates display = source event's start/end dates (resolved live on render)
3. Reservation post stores: a pointer to the source event + the setup configuration meta. It does NOT store its own copy of title/dates as canonical data.
4. The reservation post's `post_title` field exists for WP internal use (admin sidebar search, edit screen URL slug derivation, REST API responses) but is NEVER displayed as the reservation's user-visible name. The user-visible name comes from the resolver.

**UI implications (relevant to C7 Edit Reservation):**
- Title + dates render as read-only labels (`<span>`), not input fields.
- A small note below the title reads "Linked to: {source event name}" with a link to the source-event's native edit screen (if the source supports it).
- The user-visible flow for renaming or rescheduling a reservation is "go edit the source event" — not "edit fields on the reservation post."

**Rationale:**
- Prevents reservations from being orphaned with stale or contradictory names that don't match the linked event (e.g. event renamed in TEC but reservation post keeps the old title).
- Single source of truth simplifies sync logic (one direction: source → reservation cache, never reservation → source).
- Aligns with the existing "three event sources" pluggability that already exists for choosing where events come from (per the README's in-scope-features note); extending that authority to identity fields is the consistent move.

**Canonical resolver (C6.6, 2026-05-23 — formalises RES-ARCH-1 in code):**
- `EEM_Reservation_Source_Resolver::resolve_event_fields( int $reservation_id ): array{title,start_date,end_date,venue}` is the **only** approved entry point for reading reservation title or event dates for display. Convenience accessors: `get_title( int )`, `get_date_range_label( int )`.
- The four pre-C6.6 meta keys (`_en_nightly_start_date` / `_en_nightly_end_date` / `_en_weekend_start_date` / `_en_weekend_end_date`) are deprecated. Direct reads of them are forbidden in new code.
- Cache strategy: **hybrid**. Display reads go live through the resolver (Native/TEC paths are object-cached post_meta reads, Feed path is transient-cached). Sort/filter SQL uses a single narrow cache key `_en_source_event_start_date` (constant `EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY`) written by the `save_post_en_reservation` priority 30 hook.
- Source-event-side sync hook (push from source change → linked reservations' caches) is deferred to CLEANUP #24. Until that lands, source-event edits don't refresh linked reservations' sort caches until the reservation itself is next saved. Acceptable for in-development.
- **Migration was C6.6** (closed 2026-05-23, tag `c6.6-complete`, CLEANUP entry #22 marked resolved).

**Out-of-scope clarifications:**
- Setup configuration meta (`_en_stall_quantity_available`, `_en_stall_chart_enabled`, `_en_stall_rows`, fees, add-ons, blocked stall numbers, etc.) **stays** on the reservation post — that's the reservation's actual owned data.
- The reservation → source-event pointer fields (`_en_event_id`, `_en_native_event_id`, `_en_external_event_id`, `_en_external_event_label`) **stay** as reservation-owned references to the source.
- The orders → reservation linkage (legacy "Reservation setup ID: N" note pattern) is unaffected.

---

## Reservations List (`reservations_page.html`)

### RES-1. Reservation name is the primary edit affordance
**Decided:** 2026-05-22

- Each reservation row's name renders as a blue link → Edit Reservation.
- **Removed** the separate "Edit" icon from the Actions column — it was redundant with the title click.
- Same on mobile cards: `.mob-res-name` is the link.

### RES-2. Row Actions column
**Decided:** 2026-05-22

The action column follows the same pattern as `orders_page.html`: one or more conditional icon buttons + a meatballs (`···`) menu for everything else. Consistency across list screens.

Visible per row:

| Position | Action | Visible when |
|---|---|---|
| 1 | **Stall Chart icon** (links to `stall_chart_detail.html`) | Stalls are enabled in this reservation. Hidden when the reservation has no stall section. |
| 2 | **`···` meatballs menu** | Always |

Meatballs menu contents:
- **View on Front-End** — opens `event_page.html` for this reservation in a new tab
- **View Orders** — `orders_page.html?reservation=<id>` (filtered to this reservation)
- **Duplicate** — clones the reservation as a new draft (next year's event, etc.)
- **Export Roster (CSV)** — downloads a CSV of all customers / riders / stall assignments for this reservation
- **Email Customers** — opens a compose modal that emails all customers with an order against this reservation
- **Move to Trash** (red, danger style) — soft delete via WP trash

**Future conditional icons** (post-v1, when relevant): RV Chart icon (when RV is in Exact Map mode), but for v1 RV Chart lives in the Stall Chart screen as a separate tab — see chart screen decisions when we walk through them.

### RES-3. Bulk Actions
**Decided:** 2026-05-22

- Existing options stand: **Edit**, **Move to Trash**.
- No bulk Duplicate, Archive, or Export Roster for v1. Those are single-row meatballs actions; if customers ask for bulk equivalents we add them later.

### RES-4. New Status column with lifecycle badges + status tabs above table
**Decided:** 2026-05-22 (initial) · **Revised:** 2026-05-22 (added status tabs per mockup)

A new "Status" column shows each reservation's lifecycle state as a pill badge. Four states:

| State | Badge | Visible | Notes |
|---|---|---|---|
| **Active** | green | Default list view | Reservation is published, accepting customer orders |
| **Draft** | amber | Default list view | Admin has saved but not published yet (or set back to draft) |
| **Archived** | grey | Hidden from default view | Past events admin wants to retain but not see daily. Use the filter to show. |
| **Trashed** | red | Hidden from default view (in WP trash) | Soft delete; recoverable |

**Status tabs at top of table** — `All (count) | Published (count) | Draft (count) | Trash (count)` per mockup lines 244–252. Default tab = All. Tabs are the primary filter mechanism for Draft and Trashed reservations; clicking a tab sets `?status=X` on the URL and re-queries. The original RES-4 decision ("no filter tabs") was made before seeing the rendered mockup comparison and was reverted in C4 planning to honor the mockup spec.

### RES-5. New Orders count column (sortable)
**Decided:** 2026-05-22

- Adds a sortable "Orders" column showing the count of orders per reservation.
- Zero counts render dimmed (`color:#8c8f94`) to draw attention to reservations with no orders yet.
- Sort: clicking the header sorts ascending, again descending. Default sort remains Event Dates ascending.
- No Revenue column for v1 — admins who need revenue per reservation use the Revenue by Reservation chart on the dashboard.

---

## Orders List (`orders_page.html`)

### ORD-1. Toolbar filters
**Decided:** 2026-05-22

- **Event filter** (dropdown, top-left of toolbar): "All events" + one option per reservation. Defaults to All. Filters table to orders against the chosen reservation.
- **Billing status tabs** (5 tabs to the right of Event filter): **All · Paid · Unpaid · Refunded · Cancelled**. Click switches the visible set. Default = All.
- **Type chips** (multi-select, second toolbar row): **Stall · RV · Add-On · Group**. Each chip toggles independently; rows showing if they match ANY checked type. Default = all four selected.
- **Search input** filters by customer name, order number, or event name as admin types.
- **Orders count** ("23 orders") on the right updates live based on combined filter state.

### ORD-2. Bulk actions (reconciliation of REF-3)
**Decided:** 2026-05-22

Bulk refund needs two distinct paths because there are two distinct workflows:

1. **Event-cancellation refund** (the common case): show gets cancelled, refund everyone. Implied selection = "all orders against this reservation." Lives on **Edit Reservation**, not the Orders list. Will add a "Cancel Event" button to Edit Reservation that triggers the bulk-refund engine with all orders for that reservation as the selection.
2. **Selective bulk refund** (the rare case): admin picks specific orders to refund (one group of riders requested cancellation, a category of attendee, etc.). Requires hand-picking. Lives on **Orders list** with checkboxes + "Refund Selected" bulk action.

Both paths feed the same underlying bulk-refund engine described in REF-3 (queued async per-order processing, activity log entries, customer notifications, error collection at the end).

**Orders list bulk action UI:**
- Checkbox column added as the first column. Header checkbox toggles all visible rows.
- Bulk action dropdown above the table with one option: **Refund Selected**. (No other bulk actions for v1 — Mark Paid, Resend Notification, Export Selected could be added later if needed.)
- Apply button is enabled when at least one row is selected; "X selected" count shown beside it.

This supersedes the original REF-3 wording that put all bulk refund on the Orders list — the event-cancel path is more natural from the Edit Reservation screen.

### ORD-3. Per-row actions
**Decided:** 2026-05-22

Each row has:
- **Print Receipt icon** (always visible) — generates and downloads/prints a receipt PDF for this order.
- **Collect button** (orange, conditional) — shown only on rows with status `Unpaid` or `Invoice Sent`. Clicking opens the Order Detail screen where admin can manually mark paid (cash collected at the gate, check received, etc.) or initiate a Stripe Terminal collection.
- **Meatballs (`···`) menu** (always) — 6 items: View Order · Edit Reservation · Resend Notification · Export CSV · Refund Order · Move to Trash.

**Conditional behavior:**
- **Refund Order** item is **hidden** when the order's status is Refunded or Cancelled (nothing to refund). Visible for all other statuses including Partially Refunded (since there's still refundable amount left).
- **Move to Trash** (renamed from the original "Delete Order") is WP-standard soft delete: the order is recoverable from the trash for 30 days.

### ORD-4. Order numbering format
**Decided:** 2026-05-22

- **Format:** `#NNNN` zero-padded to 4 digits, globally auto-incrementing across all orders ever created in the plugin.
- **Examples:** `#0001` (very first order ever), `#0028` (28th order ever), `#1234` (1,234th order), `#12345` (10K+ uses 5 digits, no leading zero needed once that high).
- **Not per-reservation** — admin/customer cross-referencing needs a single canonical identifier; per-reservation numbering would have multiple orders with the same number across different events.
- **Not Stripe charge ID** — those are human-unfriendly (`ch_3OK...`) and live alongside, not replace, the order number.

### ORD-5. Status badge set
**Decided:** 2026-05-22

Current 6 states stand: **Paid · Partially Refunded · Invoice Sent · Refunded · Cancelled · Unpaid**.

- No `Pending` state — payment is required at customer checkout (per EVT-9), so an order is only created after payment succeeds or admin manually creates it. No cart-abandonment orders to track.
- No `Failed` state — payment failures don't create an order at all; the customer stays on the checkout form with an inline error from the processor.

---

## Build & Conventions Notes

### CONV-1. Breadcrumb header is part of the production plugin
**Decided:** 2026-05-22

Every mockup HTML file in `.mockups/` includes a breadcrumb bar at the top showing the plugin logo + current page path (e.g. "Plugin Logo / Dashboard", "Plugin Logo / Reservations / Edit Reservation"). **This ships in production.**

**Rationale:**
- WP's left-sidebar nav shows which top-level submenu item you're on, but not which sub-page within it. Deep pages like "Edit Reservation" or "Order Detail" are reached *through* Reservations/Orders and don't have their own sidebar entries. The breadcrumb fills that orientation gap.
- The breadcrumb is also where the plugin's logo/branding lives within the admin area — gives the plugin a sense of identity without conflicting with WordPress chrome.

**Phase 3 PHP implementation:**
- Render the breadcrumb as a partial included at the top of every plugin admin page template (e.g. `templates/admin/_breadcrumb.php`)
- Each page sets its breadcrumb segments via a controller variable: `[ 'Reservations' => 'reservations_page_url', 'Edit Reservation' => null ]` (key = label, value = link or null for current page)
- Style matches the mockup spec (dark navy strip, logo on left, breadcrumb segments separated by " / ", current page in lighter/non-link color)
- WordPress chrome (top admin bar, "+ New", left sidebar, "Howdy, [user]") is rendered by WP core above this — the breadcrumb sits beneath WP's admin bar, above the page content

---

## Order Detail (`order_detail_page.html`)

### ODET-1. Top action bar — Collect Payment is contextual only
**Decided:** 2026-05-22

- **Payment Outstanding banner** at the very top of the page contains the only Collect Payment button. Banner is conditional on order status: visible for `Unpaid` and `Invoice Sent`, hidden for all other statuses.
- **Removed** the duplicate Collect Payment button that previously appeared in the page's action bar (right side of the title row). One button is enough; the banner placement is more contextual because it sits adjacent to the dollar amount owed.
- Banner copy: "Payment Outstanding — $XX.XX has not been collected for this order. [Invoice sent / Manually unpaid / etc.]"
- Clicking Collect Payment opens the same flow as the Collect button on the Orders list — admin can mark paid manually (cash, check, Stripe Terminal) or send invoice.

### ODET-2. Top action bar — 'More' dropdown
**Decided:** 2026-05-22

The action bar has these buttons left-to-right: **Back to Orders · Edit Reservation · More ▾ · Print as PDF**.

The **More dropdown** mirrors the Orders list meatballs menu (ORD-3):
- Export CSV
- Resend Notification
- Refund Order (conditional — hidden when status is Refunded or Cancelled)
- Move to Trash (red, danger style — renamed from "Delete Order")

### ODET-3. Body — admin can edit assignments and line items
**Decided:** 2026-05-22

This screen is hybrid: read-only display of order data + edit-in-place for fulfillment changes. Admin can edit:

- **Stall assignments** (existing): click cells in the stall grid to reassign; Save Changes commits
- **RV lot assignment** (existing): click a lot chip to reassign
- **Order line items** (NEW): stay type, quantity, dates, add-on quantities — all editable. Changes can affect the order total.
- **Special Instructions** (NEW): admin-editable free-form text. Saves with the rest of the order. (Previously this was customer-only.)

**Read-only fields** (cannot edit on this screen):
- Customer name + contact info (admin uses Customer Detail page for that)
- Order number, creation timestamp, payment method (these are immutable)

### ODET-4. Balance-adjustment flow (when edits change the total)
**Decided:** 2026-05-22

When admin's edits change the order total, the system computes the delta vs. amount already collected (Paid minus any refunds). On Save Changes, the system pops one of two modals depending on direction:

**If new total > paid amount (balance owed by customer):**
> Modal: "This change will increase the total by $X. How do you want to collect?"
> - **Send Invoice** — emails the customer a Pay link for the additional amount; order status becomes Invoice Sent (or remains so)
> - **Charge card on file** — re-uses the saved payment method to charge $X immediately; order status remains Paid
> - **Save without collecting** — saves the change; order status becomes Balance Due (admin handles collection later via Collect Payment banner)
> - **Cancel** — abandons the edit

**If new total < paid amount (refund owed to customer):**
> Modal: "This change will reduce the total by $X. How do you want to handle the refund?"
> - **Refund automatically** — runs the refund engine (REF-1/2) for $X; order status updates
> - **Don't refund (keep as credit)** — applies a credit memo to the customer record, no money moves
> - **Cancel the edit** — abandons the change

**If new total = paid amount (no change in $):** save silently, activity log entry only.

**Activity Log integration:** every save triggers an Activity Log entry (see ODET-7) with before/after values and the dollar delta, regardless of which resolution path admin chose.

### ODET-5. Sidebar — Customer card
**Decided:** 2026-05-22

The Customer card on the right sidebar shows:
- **Customer name** — clickable link to a new **Customer Detail page** (`customer_detail_page.html` — to be built, see CUST-* decisions when we walk through it). The page will show all of this customer's orders, contact history, refund history, and a "Lifetime value" summary.
- **"View all orders →" link** — opens orders list filtered to this customer (`orders_page.html?customer=<id>`)
- **Reservation name** (read-only)
- **Contact Information** (email, phone — clickable mailto/tel links)
- **Billing Address** (read-only)
- **Order Number, Created timestamp, Agreement Signed status** (read-only)

### ODET-6. Sidebar — Payment Details card (NEW)
**Decided:** 2026-05-22

Added a new sidebar card "Payment Details" between Customer and Order Summary:

- **Processor** — Stripe or Authorize.net (whichever processed the payment)
- **Card** — brand icon (Visa/MC/etc.) + last 4 digits (e.g. "VISA •••• 4242")
- **Transaction ID** — processor's payment intent ID, monospace font
- **Charge ID** — processor's charge ID, monospace font
- **Captured** — capture timestamp, or "—" with "(awaiting payment)" if uncaptured
- **Refund History** — chronological list of refunds with amount + date + reason; "No refunds processed" if none

The card is always visible (even for unpaid orders, showing the pending state) so admin can quickly verify which card the customer used and where to look in the processor dashboard if needed.

### ODET-7. Activity Log section (NEW, full-width bottom)
**Decided:** 2026-05-22

Added a full-width Activity Log card at the bottom of the page (below the order body two-column layout). Chronological list of all activity, newest first.

**Tracked events:**
- Order created (with creation source: customer page, admin manual entry, import)
- Status changes (Unpaid → Paid, Paid → Partially Refunded, etc.)
- Edits to line items (with before/after values and dollar delta) — every field change is its own entry
- Stall/RV assignment changes (reassignment, auto-assign run)
- Refunds processed (with amount, reason, processor transaction ID, who initiated)
- Notifications sent (invoice email, receipt email, refund confirmation)
- Special Instructions edited (with before/after if changed)

**Entry shape:**
- Colored icon indicating type (create=green, edit=amber, info=blue, refund=red)
- Title (e.g. "Order edited by Whitney Mitchell (admin)")
- Meta line with specifics (e.g. "Shavings Qty: 2 → 4 · +$20.00 balance owed")
- Right-aligned date/time

**Storage:** activity log entries live in a new database table (`{prefix}_eem_activity_log`) with columns for order_id, event_type, payload (JSON), actor (user_id or 'system' or 'customer'), created_at. Indexed by order_id + created_at DESC for fast retrieval.

### ODET-8. Refund modal (REF-1 / REF-2 surface)
**Decided:** 2026-05-22

When admin clicks "Refund Order" from the More menu (or the Refund Order action in any meatballs menu), a modal opens on Order Detail.

**Modal fields:**
- **Refund Amount** — number input, pre-filled with the full refundable amount (Total Paid minus any prior refunds). Admin can edit to any value ≤ refundable amount. Validation: must be > 0 and ≤ refundable balance.
- **Reason** — optional free-form text field (no dropdown of preset reasons for v1, no required-by-default)
- **"Notify customer via email" checkbox** — default checked. When checked, sends the refund confirmation email template.
- **Buttons:** "Refund $X" (primary, dynamically reflects the amount) and "Cancel"

**Behavior:**
- Modal calls the same refund engine as REF-1/2 (plugin-initiated, supports partial via the amount field)
- Processing runs synchronously for a single order (under 10 seconds usually); spinner overlay during the call
- Success: modal closes, banner "Refund processed: $X. Customer notified." (or "not notified" if checkbox was unchecked), status badge updates, activity log entry added, refund history populated in Payment Details sidebar
- Failure: error toast inside the modal with the processor's error message; admin can retry or cancel
- The customer notification email uses a configurable template; default copy: "A refund of $X has been processed for Order #XXXX..."

---

## Pending Mockups (deferred to Phase 3 build)

Mockups referenced by decisions but not yet built. These are net-new screens added during the walkthrough:

- **`create_order_page.html`** — admin manual order entry (referenced by DASH-1, DASH-6)
- **`customer_detail_page.html`** — single customer's lifetime view (referenced by ODET-5)
- **Cancel Event button on `edit_reservation_page.html`** — bulk-refund-all-orders flow (referenced by ORD-2). Existing mockup needs amendment.

---

## Stall & RV Charts List (`stall_charts_page.html`)

### CHRT-1. Page name reflects scope
**Decided:** 2026-05-22

- **Renamed** "Stall Charts" → **"Stall & RV Charts"** throughout: WP sidebar nav label, page title, browser tab, breadcrumb, and any internal references. The list includes both stall barns and RV lots, so the name should acknowledge both.
- The `add_submenu_page()` slug stays `stall-charts` for URL continuity; only the displayed label changes.

### CHRT-2. Barns and RV Lots are separate columns
**Decided:** 2026-05-22

- The previous single "Barns / Lots" column conflated two different concepts (barns hold stalls; lots are RV spots).
- **Now split into two columns:** "Barns" and "RV Lots", each rendering its own tag list.
- Empty cells are valid and meaningful: a reservation with only stalls has an em-dash (—) in the RV Lots column, and vice versa. The empty state communicates "this reservation doesn't have that type configured."
- Visual distinction: barn tags use the standard navy palette (`.barn-tag`, F0F4FB bg). RV lot tags use a purple palette (`.rv-lot-tag`, F5F3FF bg + ddd6fe border) — same purple family used for RV-related elements elsewhere in the plugin.

### CHRT-3. Inline utilization stats per row
**Decided:** 2026-05-22

The reservation cell shows the reservation name + dates + a row of stat dots with counts:
- **Green dot · N Available**
- **Red dot · N Reserved**
- **Grey dot · N Blocked** (admin-blocked stalls/lots from the Blocked Units field in Edit Reservation)

These give at-a-glance utilization for every reservation. For unconfigured reservations (chart not built yet), the stats row shows a single "Not yet configured" indicator with a grey dot.

Stats are calculated on page load from the chart configuration + current reservation assignments. No on-click drill-down behavior — clicking a stat doesn't filter the detail view (deferred to v2 if customers ask).

### CHRT-4. Per-row actions: 2 full-text buttons (not meatballs)
**Decided:** 2026-05-22

This screen intentionally diverges from the meatballs pattern used on `orders_page.html` and `reservations_page.html`:

- **"View Chart"** (configured rows) or **"Set Up Chart"** (unconfigured rows) — single button, opens the chart detail screen. Label is conditional on chart configuration state.
- **"Edit Reservation"** — opens Edit Reservation for fine-tuning the underlying reservation config (toggling stall/RV sections, defining row layouts, etc.)

**Rationale:** the reservations and orders lists have 5–7 possible actions per row, so meatballs makes sense there. This screen has only 2 meaningful actions, both primary workflows, so full-text buttons read clearer. The rule isn't "every list uses meatballs" — it's "use meatballs when the row has more actions than fit cleanly inline."

### CHRT-5. Unified chart detail with tabs for stall + RV
**Decided:** 2026-05-22

For reservations configured with BOTH stalls AND RV lots, "View Chart" opens a single screen with **tabs** — one tab for the Stall Chart, one for the RV Chart. Reservations configured with only one of the two render directly without a tab strip (single-tab screens don't need the tab UI overhead).

This collapses what could have been two separate screens (`stall_chart_detail.html` and a hypothetical `rv_chart_detail.html`) into one. The detail screen `stall_chart_detail.html` is renamed conceptually to "Chart Detail" but keeps the URL slug for continuity.

### CHRT-6. List filters out reservations without stall/RV sections
**Decided:** 2026-05-22

A reservation with NEITHER stall section NOR RV section enabled (e.g. Add-On only or Group only reservation) does **not** appear in this list. Showing it would be misleading — there's no chart to view or configure.

However, a reservation that **has** a stall (or RV) section toggled on but **hasn't built out the row layout yet** DOES appear, with a "Not Configured" status and a "Set Up Chart" button. That's an actionable item for admin, not noise.

### CONV-2. Badge convention: dots on state, no dots on type
**Decided:** 2026-05-22

Two badge styles are used throughout the plugin admin, and they follow different conventions:

**State/status badges (`.status-badge`, `.res-status`, `.chart-status`):**
- Have a colored `::before` dot prefix (green/amber/red/grey)
- Used for things that communicate ongoing situation or health: Paid/Unpaid, Active/Draft, Configured/Not Configured, etc.
- The dot reinforces the color-coded traffic-light meaning at a glance

**Type/category badges (`.type-badge` with `type-stall`, `type-rv`, `type-addon`, `type-group`):**
- No dot prefix
- Used as labels identifying what something is (a Stall reservation, an RV reservation, etc.)
- Color-tinted background only; no need for the dot reinforcement since these aren't communicating state

Phase 3 PHP port should preserve this convention. New badge classes added later should follow it: state = dot, type = no dot.

### CDET-2. Generate Assignments button
**Decided:** 2026-05-22

The "Generate Assignments" button auto-assigns all unassigned customer orders to available stalls/lots, respecting any per-customer preferences captured during checkout (proximity preferences, specific stall picks from EVT-5, section preferences). Runs without a confirmation modal — admin can review the assignments visually on the chart and manually adjust if needed.

### CDET-3. Click-to-move via inline action menu
**Decided:** 2026-05-22 (evolved through iteration)

Click any reserved customer pill in the chart to open an action menu anchored below the pill:
- Customer name at top
- Order # as clickable link (opens Order Detail in new tab)
- "Move to different stall" button — enters destination-select mode
- "View order ↗" link — opens Order Detail in new tab

**Discoverability aids:**
- Help tip above the chart: "Click any customer name to view their order or move them to a different stall."
- Strong hover state on reserved pills: cursor pointer + slight darken + box-shadow lift + small ⋯ icon on the right
- Every reserved pill has `title="Order #XXXX"` HTML tooltip on hover so admin can confirm the order before clicking

**Destination-select mode** (after clicking Move):
- Blue banner pinned to top of page: "Click any green 'Available' cell to move [Customer] there. Or press Cancel to keep the current assignment."
- Available cells pulse with dashed blue outline (subtle 2px → 4px animated box-shadow)
- Reserved/Blocked cells dim to 50% opacity
- Escape key or Cancel button exits

**After clicking an Available cell — scope confirmation modal opens:**
- Title: "Move [Customer] (Order #XXXX) to [Block] · Stall [N]?"
- "Currently assigned" section lists this order's other nights at the same source stall
- Radio options:
  - ◉ Just [Date] (the night you clicked)
  - ○ All N nights at this stall (Order #XXXX)
- Cancel / Move buttons

**v1 scope:** "Move This Stay" only (single night or all nights of one stall). Deferred to v2: Move Entire Reservation (all stalls in the order at once), Swap with another customer.

**Source-pill matching:** by `data-order` attribute scoped to the same source row (same stall). A customer with multiple orders at the same event (e.g. Whitney's 4-stall block + her separate RV order) is treated correctly — moving her Stall 100 reservation doesn't touch her RV lot. A customer with stalls in multiple rows (e.g. Whitney has Stalls 100, 101, 102, 104) is moved stall-by-stall, not all at once.

**Mock visual update:** confirming a move updates the chart in place — source cell becomes "Available," destination cell shows customer name + inherits the data-order. Toast at bottom-right confirms: "✓ Moved [Customer] (one night / all nights) to [destination]. Logged in Activity Log."

### CDET-4. Assignment Issues panel — per-row actions
**Decided:** 2026-05-22

The Assignment Issues panel at the bottom of the chart detail page lists orders with unassigned stalls/RV lots that need attention. Each issue row has:
- Text description: "Order #XXXX ([Customer]) still has N unassigned [stall/RV lot](s)."
- **Auto-Assign** button (primary blue) — runs the auto-assign algorithm for this single order using the same logic as Generate Assignments
- **View Order ↗** link (blue, no button background) — opens Order Detail in new tab

Panel header also has an **Auto-Assign All** button that runs auto-assignment across every order in the list at once, with a confirmation dialog first.

### CDET-5. Activity Log integration
**Decided:** 2026-05-22

Every move and auto-assignment action creates an Activity Log entry on the corresponding order (mirroring the order edit pattern from ODET-7). Examples:
- "Assignment moved: Red Barn Stall 100 (May 7) → Yellow Barn Stall 200 (May 7). By [Admin Name]."
- "Auto-assigned: Red Lot (May 7, May 8). By [Admin Name]."
- "Bulk auto-assign: 10 orders auto-assigned from chart detail. By [Admin Name]."

Each entry includes timestamp, admin user, and before/after stall numbers. Lets customer service trace any reassignment if a customer questions a change.

### CDET-6. Two tabs replace dropdown — "By Location" and "By Customer"
**Decided:** 2026-05-22

The view selector at the top of the chart panel is two tabs (replacing the old "View" dropdown):

- **By Location** (default) — rows are stalls/RV lots, columns are dates, cells show customer assignments. Fulfillment-focused: "where is everyone?" Used for walking the barns at the event.
- **By Customer** — rows are customers, columns are dates + assignments. Customer-service-focused: "what does this person have?" Used for customer phone calls.

Both views show the same underlying data organized differently. Both useful, different mental models.

### CDET-7. Mobile responsiveness
**Decided:** 2026-05-22

All the new interactive components from CDET-3 and CDET-4 ship with mobile-specific overrides below 767px:
- **Cell action menu** fills the screen width up to 320px to avoid popover positioning issues
- **Destination banner** stacks vertically (message above, Cancel right-aligned); body padding-top:88px so content isn't hidden
- **Scope confirmation modal** fills screen minus 12px margin; Cancel/Move stack vertically with Move (primary) on top for thumb reach
- **Assignment Issues panel** rows stack vertically (text above, Auto-Assign + View Order side-by-side); Auto-Assign All goes full-width

Existing mobile patterns continue: tabs scroll horizontally, tables scroll horizontally instead of squishing, stat cards become 2-column, filter rows stack.


---

## Stall Chart Print View (`stall_chart_print_view.html`)

### PRNT-1. Audience: venue staff
**Decided:** 2026-05-22

The printout is designed for venue staff use — people walking the barns at the event, checking who's arrived, fielding "where's my stall?" questions, marking down no-shows. Not the event organizer, not the customers themselves.

This shapes everything: print layout prioritizes scanning by stall number AND by customer name, includes a checkbox for marking arrivals, and uses high-contrast colors that survive low-quality printing.

### PRNT-2. Both layouts ship in one printout
**Decided:** 2026-05-22

The print view contains TWO sections, both rendered:
- **Section 1 — By Location** — stalls as rows, dates as columns. Subdivided by barn (Red, Blue, Yellow), then a separate RV Lots sub-section.
- **Section 2 — By Customer** — customers as rows with their stall/RV assignments listed.

Rationale: staff have two real questions ("where is X stall?" vs "where is X customer?") and flipping back and forth between two layouts on one printout is faster than reprinting. Both sections in one document also means one PDF file to email/share.

Each section has a dark navy header band with a one-line subtitle explaining when to use it ("Walking the barns? Look up by stall number." / "Customer asks 'where am I?' Look up by name.").

### PRNT-3. Minimum columns per assignment
**Decided:** 2026-05-22

Per the audience (venue staff), each row shows the minimum useful info, no more:

**Section 1 — By Location:** Stall # | Customer | Order # | ✓ Arr. | May 7 | May 8 | May 9
**Section 2 — By Customer:** Customer | Order # | ✓ Arr. | Nights | Stall(s) | RV Lot(s)

Order # is included on every row so staff can cross-reference if needed (e.g. "this customer claims to have Stall 100, but the chart says someone else is there — let me look up their order"). Phone, horse count, horse names deferred to v2 if staff requests them later.

### PRNT-4. Check-in column for paper mark-up
**Decided:** 2026-05-22

Every assignment row has an empty `✓ Arr.` (Arrived) column with a small box-outlined checkbox. Staff marks each off with a pen as customers arrive. Available stalls show "—" in that column instead of a checkbox.

Notes column was considered but rejected — staff can write in the margins if needed, and a Notes column would eat space without serving the primary "is everyone here?" task.

### PRNT-5. Occupancy count next to barn headers
**Decided:** 2026-05-22

Each barn header row in Section 1 includes a small pill on the right showing "X of Y occupied" (e.g. "Red Barn · Stalls 100–120 · 5 of 8 occupied"). Lets staff see at a glance which barns are heavily booked and which have lots of empties.

We did NOT visually mute Available rows — staff might still need to consult those rows during the event (someone wants to switch stalls, or a horse needs to be moved). Same visual weight is fine.

### PRNT-6. Print-safe colors
**Decided:** 2026-05-22

Earlier draft used pale tinted backgrounds (pale blue for Reserved, pale green for Available, pale red for Blocked, pale yellow tones in headers). These washed out badly on paper and in low-resolution PDF renders.

Replaced with high-contrast print-safe scheme:
- **Reserved** = solid Electric Blue (#1668F2) background + white text
- **Available** = white background + grey text + thin grey border (intentionally muted)
- **Blocked** = solid dark navy (#1d2327) background + white text (impossible to miss)
- **Barn header** = pale grey background + dark navy left/top borders for visual separation

Print stylesheet uses `-webkit-print-color-adjust: exact` to force the backgrounds to actually print (browsers strip them by default to save toner). `@page` rule sets 10mm top/bottom + 8mm side margins for letter-size paper.

### PRNT-7. Single-document export
**Decided:** 2026-05-22

Print View is one continuous HTML document — staff hits "Print / Save PDF" and gets the whole chart (both sections, both layouts) as one PDF. No multi-file management. Page breaks happen naturally via `break-inside: avoid` on table rows so an individual row isn't split across pages.

If admin only wants one section, they can use browser's "Pages: X-Y" option in the print dialog at the moment of printing.


---

## Create Order / Collect Payment Page (`invoicing_page.html`)

### INV-1. Two-mode page structure
**Decided:** 2026-05-22

The page is one screen with two tabs at the top:
- **New Order** — admin manually creates an order while a customer waits (phone or in-person sales)
- **Collect Payment** — admin searches an existing unpaid/pending order and collects payment on it

These are different enough that admin needs to choose at the start, but related enough that keeping them in one screen reduces nav-flipping. The earlier idea of splitting into two pages was rejected.

### INV-2. Main use case: phone/in-person sales
**Decided:** 2026-05-22

The New Order workflow is built for speed. Admin is talking to a customer on the phone or at a registration table; the form has to flow fast: reservation → customer lookup → contact info → stall/RV/add-on selection → totals → send link or charge card. No friction, no admin-only fields that interrupt the flow.

Replacing a failed online checkout is supported by the same form but is the secondary case.

### INV-3. Customer search at the top of New Order
**Decided:** 2026-05-22

A "Look up customer" card sits above the workspace (between the mode tabs and the form). Admin types name, email, or phone; a typeahead dropdown shows matching customers with email + phone + prior-order count. Click one → contact info card autofills. A "Skip — new customer" button collapses the search if admin is creating a brand-new customer record.

Once picked, the card collapses to a small confirmation strip with a "Change" button for swapping.

Rationale: phone-order admins want to find existing customers fast (frequent buyers, repeat events). Forcing them to type the same name/email/phone they already have in the system would slow them down.

### INV-4. Unlimited custom line items
**Decided:** 2026-05-22

A "Custom Line Items" card sits after Add-Ons. Admin can add as many custom rows as needed; each has Description + Price + Remove button. These appear on the customer's invoice as line items alongside the configured add-ons.

Use cases: late arrival fees, damage charges, transferred credit, manual price overrides for VIPs, etc. The pre-configured-only approach was rejected as too rigid — event admins need to handle one-off situations daily.

Each custom item is logged in the Activity Log so refunds and disputes have a trail.

### INV-5. Order-level discount with required reason
**Decided:** 2026-05-22

A discount control sits in the Order Summary rail, between line items and Total. Default state: a dashed-outline "Apply discount" button. Click to expand:
- Type selector — dollar amount ($) or percentage (%)
- Value field
- **Reason** field (required, free text, logged in Activity Log)
- Applied row showing the discount in green with a Remove link
- Total updates to reflect

Required reason field ensures every discount has a paper trail — protects against admin abuse, makes audits straightforward, lets refund disputes get resolved with full context.

v1 ships order-level discount only. Per-line-item discount deferred — admins can usually accomplish the same with an order-level discount + reason like "complimentary stall 100."

### INV-6. Personal message in invoice email
**Decided:** 2026-05-22

The Send Link payment panel has a "Personal message (optional)" textarea above the email preview. Admin types a note; the email preview below updates in real time so admin sees exactly what the customer will see. The note becomes part of the email body between the greeting and the payment link.

Default template (subject, greeting, balance line, link) is fixed across the system; admins can configure the template in Settings page. The per-order personal message lets admins add context like "Thanks for your phone order — confirmed your stall 102 by the wash rack as requested."

Saves time vs typing a custom email from scratch every time.

### INV-7. Order Summary header — no subtitle
**Decided:** 2026-05-22

The Order Summary card in the workspace rail shows just the title — no subtitle like "Updates as you build the order." The subtitle was clutter; the title alone is clear enough about what the panel does, and the empty space gives more vertical room for the actual summary content.

---

## Orders Page Amendment

### ORD-6. Bulk "Send Payment Reminder" action
**Decided:** 2026-05-22

A new bulk action on the Orders page: select unpaid orders → click "Send Payment Reminder" → all selected customers get a reminder email with their payment link. Uses the same email template as the original invoice with a "Reminder" prefix on the subject line.

Eligibility: works on orders with status Unpaid, Invoice Sent, or Partial. Disabled for Paid/Refunded/Cancelled.

Pattern mirrors ORD-2 (bulk refund). Same checkboxes column, same selection count UI, same bulk-action dropdown.

This satisfies the original idea of a separate "Invoicing" overview page — admins can already filter the Orders page by status to see all unpaid, then act on them in bulk. No new page needed.


---

## Reports Page (`reports_page.html`)

### REP-1. Six report types
**Decided:** 2026-05-22

Six different report exports, each addressing a specific use case:
- **Orders** — transactional bookkeeping (every order with customer, items, payment status, totals)
- **Reservations** — event-level summary (dates, total orders, revenue, occupancy %, capacity)
- **Revenue** — financial reporting (gross, refunds, net broken down by date/reservation) for accountants and tax filings
- **Stall Occupancy** — which stalls/RV lots used when, for venue settlement and post-event analysis
- **Customer List** — contact info + lifetime spend, for CRM exports and mailing lists
- **Refund Log** — every refund issued with reason, date, admin user, for compliance audit trails

These report types are distinct, not overlapping — admin picks based on which question they're answering.

### REP-2. CSV + PDF formats
**Decided:** 2026-05-22

Each report exports in two formats: CSV (universal, opens in Excel/Sheets) and PDF (for printing tax/audit records, sharing with accountants). Each report card has both buttons side by side.

JSON deferred — admins who need API access can request integration features later.

### REP-3. Global filters apply to all reports
**Decided:** 2026-05-22

A single Filters card at the top of the page controls all report exports below. Filters: Reservation, Date range (from/to date pickers), Order status. Admin sets filters once, then exports any report with those filters applied.

Filter footer shows a live summary ("April 1 – April 30, 2026 · 2026 Southeast Region Super Sort · All statuses") so admin always knows what scope they're about to export. Reset Filters link clears back to defaults.

Per-report filter overrides were considered but rejected — too much UI clutter for the gain.

### REP-4. ZIP shortcut at top
**Decided:** 2026-05-22

A separate highlighted card sits ABOVE the filters: "Export all reports for one reservation." Pick a single reservation → click → ZIP file with all 6 reports × CSV+PDF = 12 files in one download.

Use case: end-of-event documentation package. Admin sends the ZIP to the treasurer, venue, or files it for tax records. Saves admin from clicking 12 separate exports.

This card uses the reservation selector only (no date range, no status filter) — it's intentionally the "give me everything for this event" path. For scoped exports, admin uses the filters + individual report cards below.

### REP-5. No scheduled reports
**Decided:** 2026-05-22

The plugin does NOT support scheduled/recurring email reports (e.g. "weekly revenue report every Monday"). Admin exports manually when needed.

Rationale: scheduled emails add server-side cron complexity, recipient management, email deliverability concerns, and configuration overhead — significant scope for a low-frequency use case. Admins who need recurring reports can set a calendar reminder to manually export.

Deferred to a possible v2 if user demand emerges.

### REP-6. Export History at bottom
**Decided:** 2026-05-22

The Export History table preserves a log of every export across all report types and formats. Columns: Exported At | Report (name) | Reservation | Format (CSV/PDF/ZIP) | File Name | Exported By.

Lets admin see what's been pulled recently, who pulled it, and prove an export happened for audit purposes. Mobile view switches to card layout for narrow screens.

History entries are not downloadable from the log (the file isn't stored server-side after export) — the log is a reference record, not a re-download portal. Admin re-runs the export if they need the file again.

### REP-7. Visual color coding for report types
**Decided:** 2026-05-22

Each report type has a colored icon for fast visual scanning:
- Orders: Electric Blue (transactional, the core)
- Reservations: purple (event-level)
- Revenue: green (money in)
- Stall Occupancy: cyan (logistics)
- Customer List: orange (people)
- Refund Log: red (money out, compliance)

Colors aren't load-bearing — just visual cues to help admin scan the grid quickly. Admins remember "the green one" for revenue, "the red one" for refunds.


---

## Settings Page (`settings_page.html`)

### SET-1. Six-panel organization
**Decided:** 2026-05-22

The Settings page uses a left-nav with six panels:
- **Integrations** — TEC (The Events Calendar) connection, event source picker
- **Branding** — colors, logos, visual identity
- **Communications** — Reservation Form text + Email Sender + Email Templates + Policies
- **Shortcodes** — WP shortcode reference admins paste into pages
- **Payments** — Tax Rate + Active Payment Processor (Stripe/Authorize.net) + connection settings
- **Add-Ons** — feature toggles for add-ons (Group, RV, custom items, etc.)

This is what shipped in the existing mockup and remains the right organization.

### SET-2. Five editable email templates
**Decided:** 2026-05-22

The Communications panel includes an "Email Templates" section with five collapsible template cards:
1. **Order Receipt** — sent immediately after successful order/payment
2. **Payment Reminder** — sent for unpaid invoices (bulk reminder from Orders page or per-order)
3. **Refund Confirmation** — sent after a refund is processed (full or partial)
4. **Cancellation** — sent when an order is cancelled (by admin or by customer)
5. **Custom Welcome** — sent to new customers, for first-time-buyer welcomes or VIP greetings

Each card has its own subject and body fields. Cards collapse so the page isn't overwhelming. Order Receipt is the most common — defaults to open. Others are closed by default; admin expands when needed.

### SET-3. Rich text editor + placeholder tokens
**Decided:** 2026-05-22

Each template body uses a rich text editor with basic formatting (Bold / Italic / Underline / Link / Bulleted list). Plain text was rejected as too limiting (admins need to bold key info like balances and dates). Full HTML editor was rejected as too much for a non-technical admin audience.

A **Placeholder reference panel** sits at the top of the Email Templates section listing all available `{{tokens}}` (customer_name, event_name, event_dates, order_number, total, balance, payment_link, stall_assignments, support_phone, support_email, cancellation_policy). Each chip is clickable — copies the placeholder to clipboard so admin can paste it into any template field.

Phase 3 PHP port should use a standard rich text library (TinyMCE — already bundled with WordPress) for the actual editor. The mockup's `contenteditable` divs are for visual approximation only.

### SET-4. "Send test email to me" per template
**Decided:** 2026-05-22

Each template card has a "Send test email to me" button at the bottom. Sends the template (with mock placeholder values filled in) to the admin's user email. Lets admin verify formatting, links, and rendering before saving.

"Preview in browser" (rendering the email in a popup) was considered but rejected — sending a real test email exercises the actual deliverability path (mail server, spam filters, mobile rendering) which a browser preview can't simulate.

### SET-5. Policies section: Cancellation Policy + Terms & Conditions
**Decided:** 2026-05-22

Two separate textareas in a new "Policies" section under Communications:

- **Cancellation Policy** — refund/cancellation specifics. Shown at checkout AND inserted via `{{cancellation_policy}}` placeholder into the Cancellation email template. Example default: "Cancellations more than 14 days before the event receive a full refund. Within 14 days are non-refundable. Within 48 hours forfeit deposits..."
- **Terms & Conditions** — legal-ish, longer text covering health certificates (Coggins), stall changes, damage liability, photography releases, jurisdiction, etc. Shown at checkout — customer must acknowledge by checking a box before paying.

Two separate fields (not one combined) because they serve different functions: the cancellation policy is operational info the customer references later; T&C is a legal agreement they accept at the moment of purchase.

### SET-6. Tax Rate setup
**Decided:** 2026-05-22

New section at the top of the Payments panel:

- **Apply Tax** checkbox — master toggle. Uncheck if admin handles tax outside the plugin (accounting software). Disabling hides tax lines from checkout and receipts entirely.
- **Default Tax Rate** — single global default percentage (e.g. 7.50%). Each individual reservation can override this via its own settings (per CHRT/EVT decisions).
- **Tax Label** — how tax appears on checkout and receipts ("Sales Tax" default; could be "VAT", "GST", "Use Tax", etc.)

Per-line-item non-taxable flags were rejected — too granular for v1. Multiple named tax rates (e.g. "Florida 6%", "Tennessee 7%") rejected — most events are at a single venue, single rate. Per-reservation override covers multi-state organizations who travel.

### SET-7. Email Sender Settings centralized
**Decided:** 2026-05-22

The earlier per-template sender fields (From Name, From Email, Reply-To, Subject, Body for receipts) are now centralized under a single "Email Sender Settings" section that applies to ALL templates. Admin sets the sender identity once. Each template card only handles its own Subject + Body.

Reduces duplicate UI and prevents inconsistent sender identity across template types (which would look unprofessional to customers).


---

## Customer Confirmation Email + Receipt (`customer_confirmation_email.html`, `order_receipt.html`)

### EMAIL-1. Stall assignments shown inline AND link to hosted page
**Decided:** 2026-05-22

The confirmation email includes a "Your Assignments" section with the customer's stalls and RV lots listed with their nights. Below it, a "View or print your order online ↗" link sends the customer to a hosted Order Detail page where they can see/print their order anytime without digging through their inbox.

Rationale: most customers want to see their assignment confirmation immediately in the email (without opening attachments or visiting a webpage). But for re-checking weeks later, having a hosted page they can bookmark is more reliable than an email that might be archived/lost. Both serve different moments in the customer's journey.

### EMAIL-2. PDF receipt as attachment
**Decided:** 2026-05-22

The PDF receipt is attached to the confirmation email (current pattern preserved). This gives customers a downloadable document for their records, accountants, or to print for filing. The email body has all the information visually, but the PDF is the "paper-equivalent" version.

"Download PDF" links in the email body or web-only receipts were rejected — attachments are the universal expectation for transactional receipts (everyone knows where to find them in their email client) and don't depend on a server connection later.

### EMAIL-3. Cancellation Policy shown in both email and receipt
**Decided:** 2026-05-22

The Cancellation Policy text (from Settings, SET-5) appears in TWO places post-purchase:
- **In the confirmation email** — a small policy section near the bottom of the email body
- **On the PDF receipt** — a print-safe policy section before the footer (with `break-inside: avoid` so it doesn't split across pages)

Customer has the policy on hand whether they look at the email or the PDF. They already acknowledged it at checkout (per SET-5), but having it in the post-purchase docs makes it easier to look up later without re-finding the venue website.

### EMAIL-4. What's Next / Event Day Info — per-reservation configurable
**Decided:** 2026-05-22

The confirmation email includes a "What's Next — Event Day Info" section (green-tinted box) with practical event-day information:
- Check-in time and location
- What to bring (e.g. Coggins certificate)
- Parking instructions
- Event hotline phone number

This is **per-reservation configurable** — admin enters event-specific instructions in the reservation's settings on the Edit Reservation page. This means each event can have unique arrival info (different venue, different hours, different rules) without admin needing to edit a global template each time they create a new reservation.

The section is part of the confirmation email (sent at order time), not a separate pre-event reminder. Reasoning: customers often book months in advance, then forget the details. Including arrival info in the original confirmation means they can search their inbox for the event name and find everything they need.

A future pre-event reminder email (sent 1–2 days before the event) is deferred to v2.

### EMAIL-5. Receipt totals show Subtotal + Convenience Fee + Tax
**Decided:** 2026-05-22

The PDF receipt totals section breaks out:
- Line items (existing)
- Subtotal
- Non-Refundable Convenience Fee
- Sales Tax (with the configured % from SET-6)
- Total Amount Paid (grand total)

This makes the math transparent for customer accountants and audit trails. Hidden tax (rolled into line items) is harder to defend if a customer disputes a charge.

---

## Reservation Page Amendment

### EVT-11. Agreement Notice below Order Summary
**Decided:** 2026-05-22

The reservation form's Order Summary sidebar now includes a yellow Agreement Notice sitting directly below the Order Summary card with a small gap:

> ⓘ All transaction fees are non-refundable. Please be sure you have read the [Agreement Link Label] before clicking **SAVE**.

The notice is controlled by the Edit Reservation page's Agreement card (admin side):
1. **Agreement File** (existing) — admin uploads the PDF/document
2. **Agreement Link Label** (existing) — admin types the link text ("Venue Agreement", "Rider Agreement", etc.)
3. **Agreement Notice Text** (NEW) — admin writes the surrounding warning text with a `{{link}}` placeholder where the link should appear

This makes the entire notice editable per-reservation without code changes. Empty agreement file or disabled Agreement section means the notice doesn't show on the front-end. Mobile/desktop layouts both render the notice in the sidebar position with appropriate spacing.


---

## Visual Polish Corrections (mockup deviations)

### VIS-1. Bar background — deviate from mockup #fafafa to #f3f4f5
**Decided:** 2026-05-22 (during C4 polish)

Mockup spec uses `#fafafa` as the background for all "subtle bar" surfaces — toolbars, table headers, footer pagination bars, save bars, modal footers. That value is only 5/255 brightness off white (`#ffffff`), which renders effectively invisible against the white `.eem-list-card` / `.eem-page-wrap` backgrounds that contain them. The "Showing X of Y" footer bar on Reservations made the deficiency obvious — the footer text appeared to float on the page with no visible chrome.

**Correction:** the CSS variable `--eem-bg-alt` (which already centralized the bar background pattern in admin.css) was retargeted from `#fafafa` to `#f3f4f5`. The 12/255 brightness offset gives clear separation from white while staying soft and professional. The value sits between the existing hover state (`#f6f7f7`) and the WP admin body background (`#f0f0f1`), avoiding semantic collision with either.

**Scope:** applies globally via the variable. All current consumers automatically benefit:
- `.eem-list-toolbar` (Reservations toolbar)
- `.eem-table thead tr` (Reservations table header row)
- `.eem-table-footer` (Reservations pagination/info footer)
- `.eem-settings-save-bar` (Settings page save bar)
- `.eem-modal-foot` (modal footers including Email Customers)
- `.eem-toolbar` + `.eem-toolbar-row` (defined for future page ports)
- `.eem-pagination` (when used standalone)

**Excluded** (intentionally still at `#fafafa`):
- `.eem-settings-nav` — structural sidebar identity comes from its border-right divider, not bg contrast. Changing it would over-darken the rail.
- `.eem-logo-preview` — empty-state placeholder with dashed border; different visual role from "bar on white card."

Future Phase 3 chunks should use `var(--eem-bg-alt)` for any bar-style subtle background and inherit this correction automatically. Don't "fix" it back to the mockup value — the mockup is the source the deviation is documented against.

---

## Mockup Audit (Phase 3 prep — pre-shipped-design-system mockups reconciled to shipped state)

These decisions came out of auditing mockups that were designed BEFORE the design system evolved through shipped chunks C3–C5. Each audit walked the mockup against shipped patterns (VIS-1/2/3/4, hover convention, link affordances, order/customer/event format, RES-ARCH-1) and resolved questions where the mockup couldn't be silently corrected without product input. Captured here so Phase 3 implementation knows the intent.

### AUDIT-C7-1. Reservation editor scope decisions
**Decided:** 2026-05-23

The pre-shipped-design-system C7 mockup (`edit_reservation_page.html`) bundled features that didn't all belong in C7. The reservation editor scope was reconciled as follows:

| Section | C7 disposition |
|---|---|
| Reservation Description | In-scope. Chevron-collapsible. No Enable toggle (always active). |
| Check-In / Check-Out | In-scope. Section-level Enable toggle + chevron. Default Disabled. |
| Stall Reservations | In-scope. Pricing + stay types + early bird + required shavings editable. **Stall layout = read-only summary** ("3 rows, 60 stalls total, 4 blocked") with "Manage Stall Layout" button stubbed with C8 badge. Full interactive row builder lives in C8. |
| RV Reservations | In-scope. Pricing + stay types + early bird editable. **Lot Zones (pricing tiers) editable in C7** with color swatch + name + surcharge per zone. **Lot layout = read-only summary** with "Manage Lot Layout" button stubbed with C8 badge. Physical lot painter/zone painter deferred to C8. |
| Exact Map Selection mode | REMOVED. Quantity-based is implicit in C7. |
| Venue Map | REMOVED from C7. Will appear in C8 alongside the layout builders. |
| General Add-Ons | In-scope. Repeating-row editor. |
| Group Reservations | In-scope. Default Disabled. Grounds Fee toggle + amount, Deposit toggle + amount. |
| Agreement | In-scope. Simple PDF file upload field. Customer-facing acknowledgment at checkout is C10's job (already in event_page.html). |
| Fees | In-scope (reversed from earlier remove decision). Convenience Fee per-reservation with three modes: None / Flat $ / Percentage. Mutually exclusive. Conditional row visibility on the value field. **Tax stays global in Settings → Payments** per SET-6. Fees are per-reservation; tax is global. |

### AUDIT-C7-2. Reservation title and dates are read-only mirrors of source event
**Decided:** 2026-05-23

Per RES-ARCH-1, the reservation editor's title and dates **are not editable on this page**. Both are derived from the linked source event.

- The page title (Space Grotesk 22px navy bold in the plugin-header band) renders as a **read-only display element**, not an input. Mirrored from the linked source event's title.
- A meta-line row sits below the title in the header band showing "Linked Event: [event title link]" and "Event Dates: [date range]" with mini-labels above each value (matches order_detail's plugin-meta-line pattern).
- Reservation post_title is mirrored from the linked source event title on save_post hook.
- One event = one reservation always (per TEC-3). No separate admin nickname is needed since the title is the same.
- The reservation owns its CONFIGURATION (stall types, pricing, add-ons, group, agreement, fees) — those ARE editable. Title and dates are NOT.

### AUDIT-C7-3. Section UX — collapsible + Enable toggle + colored icon chips
**Decided:** 2026-05-23

Sections on the reservation editor use a **two-control** pattern in their header:

1. **Enable toggle** (Stall, RV, Check-In, Group, Add-Ons, Agreement, Fees, etc.): data-level on/off. When off, section body shows disabled diagonal-stripe shading + "This section is disabled" note; chevron is locked (can't expand a disabled section). Default state for optional sections (Check-In, Group) is Disabled; default state for required-ish sections (Stall, RV, Add-Ons, Agreement, Fees) is Enabled.
2. **Chevron collapse**: UI-only on/off for screen real-estate. Independent of the Enable toggle. Reservation Description is the ONE section without an Enable toggle — chevron only.

When admin flips Enable→on, section auto-expands. When Enable→off, section auto-collapses AND locks chevron. Default initial state is all sections collapsed; admin expands sections as they enable them.

Each section header has a small **colored icon chip** (28×28px rounded square with light-color bg + matching icon) on the left of the section title for visual rhythm in a long form. Icon palette: blue (description), teal (check-in), green (stall, group), purple (RV), orange (add-ons, fees), navy (agreement). This is a documented deviation from shipped settings/order_detail (which use plain titles); it's permitted on the reservation editor because the form is long and benefits from visual hierarchy.

### AUDIT-C7-4. Right rail Publish card and Save buttons
**Decided:** 2026-05-23

The reservation editor uses a **right-rail sticky card** for publish controls, not a footer save bar. This matches the WordPress Posts/Pages editor convention.

The Publish card includes three WP-Posts-style metadata rows, each with an inline "Edit" link:
- Status: **Published** [Edit]
- Visibility: **Public** [Edit]
- Published: **[date]** [Edit]

Below the metadata: **Preview Frontend Form** (ghost link), **Save as Draft** (ghost), **Update Reservation** (Electric Blue primary per VIS-4), **Move to Trash** (red ghost).

Both Save as Draft + Update Reservation buttons appear on the editor in the mockup. PHP implementation may conditionalize visibility (Save Draft only when status is Draft) but the mockup commits to showing both.

The right rail also includes a **Linked Event card** (search input + currently-linked event with unlink) and a **Shortcode card** (`[eem_reservation id="N"]` per AUDIT-C7-5).

### AUDIT-C7-5. Shortcode prefix
**Decided:** 2026-05-23

Reservation shortcodes use the `eem_` prefix established in C6.5.B phpcs.xml. Format: `[eem_reservation id="..."]`. NOT `[en_reservation]` (which was the placeholder in the pre-audit mockup).

### AUDIT-C7-6. Stay Types validation rule
**Decided:** 2026-05-23

When a section has Stay Types toggles (Stall and RV both do — Nightly / Weekend Rate), at least one stay type must remain enabled while the parent section is Enabled.

UX: when admin attempts to turn off the last-active stay type, the toggle is blocked from flipping, and an inline red error hint appears below the Stay Types row: *"At least one stay type must remain enabled."* Auto-dismisses after ~2.2 seconds.

This applies equally to Stall Stay Types and RV Stay Types.

### AUDIT-C7-7. Conditional row visibility — complete catalog
**Decided:** 2026-05-23

Toggle-driven row visibility within sections, beyond the section-level Enable toggle:

**Stall section:**
- Nightly off → hide Stall Nightly Rate row + Early Bird Nightly Rate row
- Weekend Rate off → hide Weekend Package Dates + Stall Weekend Rate + Early Bird Weekend Rate rows
- Reservation Schedule off → hide Stalls Open + Stalls Close datetime rows
- Stall Early Bird Pricing off → hide Early Bird Cutoff + Early Bird Nightly Rate + Early Bird Weekend Rate rows
- Required Shavings off → hide Shavings Per Stall + Shavings Price Per Bag rows

**RV section (symmetric to Stall):**
- Nightly off → hide RV Nightly Rate + Early Bird Nightly Rate
- Weekend Rate off → hide RV Weekend Package Dates + RV Weekend Rate + Early Bird Weekend Rate
- Reservation Schedule off → hide RV Open + RV Close datetime rows
- RV Early Bird Pricing off → hide Cutoff + EB Nightly + EB Weekend
- **Enable RV Add-Ons** master toggle wraps the RV add-ons table — when off, the entire add-ons table block hides (this is in addition to the per-row delete affordance)

**Group section:**
- Grounds Fee off → hide Grounds Fee Amount row
- Deposit off → hide Deposit Amount row

**Fees section:**
- Fee Type "None" → hide both value rows
- Fee Type "Flat Amount" → show Flat Fee Amount row, hide Percentage row
- Fee Type "Percentage" → show Percentage Fee row, hide Flat row

All visibility logic runs on `DOMContentLoaded` to set initial state correctly per toggle defaults.

---

## Mockup Audit — C8 Stall Charts list (`stall_charts_page.html`)

### AUDIT-C8-1. Page name: "Stall & RV Charts" everywhere
**Decided:** 2026-05-23

The Stall Charts feature is now called **"Stall & RV Charts"** consistently across:
- Sidebar nav entry
- Breadcrumb
- Page title (`.plugin-title`)
- Browser title
- Any link or label that references the page

Rationale: this page (and the detail page) covers both stall and RV physical layouts, not just stalls. The earlier "Stall Charts" shorthand undersold the RV functionality.

**Handoff task for Claude Code:** Update the sidebar entry on the already-shipped pages — `reservations_page.html`, `orders_page.html`, `settings_page.html`, `order_detail_page.html`, `customer_profile_page.html`, `event_page.html`. The change is mechanical: find `<div class="wp-sidebar-sub">Stall Charts</div>` (or active variant) and replace `Stall Charts` with `Stall &amp; RV Charts`. Same string in `eem-admin.php` menu registration. Hands-off rule means I (Claude.ai) did NOT modify these shipped pages directly.

### AUDIT-C8-2. Status tabs on the Stall & RV Charts list
**Decided:** 2026-05-23

The list page has status tabs above the toolbar, matching the shipped Reservations page tab pattern:

- **All (N)** — default active
- **Configured (N)** — chart has barns + layouts set up
- **Partial (N)** — chart has some layout but is incomplete
- **Not Configured (N)** — no layout has been defined yet

Tab styling matches shipped `.status-tab` from reservations_page (Electric Blue underline on active, color-only emphasis).

### AUDIT-C8-3. Status pill casing — UPPERCASE
**Decided:** 2026-05-23

Chart status pills (Configured / Partial / Not Configured) render in **UPPERCASE** with 11px/700/letterspaced styling — matching the Reservations status pill convention, not the Orders Mixed-Case convention.

Decision rationale: chart status describes the chart's lifecycle state (whether the admin has done the setup), which is closer to Reservations lifecycle (ACTIVE / DRAFT) than to Orders metadata (Paid / Invoice Sent).

### AUDIT-C8-4. Barn tag color treatment
**Decided:** 2026-05-23

Barn tags (e.g. "Red Barn / Blue Barn / Green Barn" in the Barns column) keep their **custom soft-blue color** — `#F0F4FB` bg / `#031B4E` text / `#D9E2F2` border. This is distinct from the documented type-badge palette (Stall blue, RV purple, Add-On orange, Group green).

Rationale: barns are a sub-category of the Stall type, not a parallel reservation type. Using the same blue as Stall type badges (`#1668F2`) would risk visual ambiguity. Using plain text loses scan-ability. The custom soft-blue treatment positions barns as their own logical tier.

### AUDIT-C8-5. RV Lot column shows real zone names from C7
**Decided:** 2026-05-23

The RV Lots column on the Stall & RV Charts list shows the **actual zone names** from the linked reservation's C7 Lot Zones config (e.g. "Red Lot / Blue Lot / Green Lot"), not generic placeholders ("RV Lot A"). Where a reservation has no RV zones configured, the cell shows an em-dash `—` (the shipped `.event-dates-empty` placeholder pattern).

### AUDIT-C8-6. Toolbar stays minimal — no Bulk Actions
**Decided:** 2026-05-23

The Stall & RV Charts list toolbar contains only: a date select, a search box, and the item count. **No Bulk Actions, no Filter button.** Charts are managed one at a time; the status tabs from AUDIT-C8-2 already provide the filter affordance.

### AUDIT-C8-7. Empty-state placeholders use em-dash
**Decided:** 2026-05-23

Where a reservation has no barns or no RV lots configured, the corresponding cell on the list page renders the shipped `.event-dates-empty` em-dash placeholder, NOT a custom fake-tag with "No barns set" text in pale color. The em-dash is the documented empty-cell pattern across shipped pages.

---

## Mockup Audit — C8 Stall Chart Detail (`stall_chart_detail.html`)

### AUDIT-C8-8. Customer-name pills are state pills with persistent click affordance
**Decided:** 2026-05-23

Customer-name pills inside the stall chart (`.occ-pill.occ-reserved`) are visually treated as **occupancy state pills** (matching the Available / Blocked pill family in the same column), NOT as customer-name links (which would otherwise follow the navy-bold convention).

Pill styling: `#EEF4FF` background + `#1668F2` Electric Blue text + `#c0d8ff` border — same color family as a Stall type badge. The customer name sits inside the state pill as the label content.

**Click affordance:** every reserved pill has a **persistent chevron-down SVG icon** on the right side at ~30% opacity (full opacity on hover). The pill has right-padding to give the chevron its own space — no overlap with the customer name text. This replaces the original `::after { content: "⋯" }` hack which overlaid the name text and read as a rendering bug.

The chevron makes the click affordance discoverable at-a-glance, before the admin needs to hover. Together with the persistent "Tip" banner at the top of the chart, it should be unambiguous that the customer names are interactive.

### AUDIT-C8-9. "Tip" banner above the stall chart
**Decided:** 2026-05-23

A persistent soft-blue tip banner sits flush at the top of the Stall Charts content card with text: *"💡 Tip: Click any customer name to view their order or move them to a different stall."* Styling: `#F0F4FB` bg + `#D9E2F2` border + navy text + Electric Blue info icon.

Both this tip banner AND the persistent chevron from AUDIT-C8-8 stay. Belt-and-suspenders for first-time admin discoverability. Admin may not realize names are clickable even with the chevron alone; the explicit tip removes any ambiguity.

The original mockup placed the tip with `margin:0 0 12px` which conflicted with the card's `overflow:hidden`. Fix: tip sits as a flush banner inside the content card, immediately below the `.content-card-header`, before the `.filter-row`.

### AUDIT-C8-10. Action bar: no "Back to Overview" button
**Decided:** 2026-05-23

The detail page's action bar contains **only actions, not navigation**:
- Generate Assignments (Electric Blue primary)
- Print View (ghost) — opens `stall_chart_print_view.html` in new tab
- Edit Reservation (ghost) — links to the reservation editor for this reservation

The "Back to Overview" / "Back to All Charts" button is **dropped entirely**. The breadcrumb at the top of the page (`Stall & RV Charts / [reservation name]`) already provides this navigation path.

### AUDIT-C8-11. Barn group rows — mixed-case section break
**Decided:** 2026-05-23

Section-break rows inside the stall chart table (e.g. "Red Barn · Stalls 100–120") render as **mixed-case 13px/600 navy on `#f3f4f5` row background** — matching the thead alt-row treatment. The original UPPERCASE small-caps blue treatment violated VIS-2 (no UPPERCASE small-caps for table content).

### AUDIT-C8-12. Barn filter chips — Electric Blue solid active
**Decided:** 2026-05-23

Barn filter chips ("All Barns / Red Barn / Blue Barn / Yellow Barn") in the filter row use Electric Blue solid active state: `#1668F2` background + white text + `#1668F2` border. This matches the shipped pagination `.page-btn.active` pattern, which is the closest existing convention for "this filter chip is currently active."

Solid navy active (the original treatment) was rejected as visually too heavy for a quick filter chip. The page-level view-tabs ("By Location / By Customer") keep the underline pattern — that's a different role (top-level view switch, not filter).

### AUDIT-C8-13. Print View opens standalone page, not inline overlay
**Decided:** 2026-05-23

The "Print View" button on the detail page opens `stall_chart_print_view.html` in a new tab/window. **The inline `#print-view` overlay from the original mockup is dropped entirely** (~280 lines removed from the detail page).

Rationale: DRY — one source of truth for the print rendering. The dedicated `stall_chart_print_view.html` page (next on the audit queue) is the canonical print rendering and can iterate independently. Maintaining the same content duplicated in two files would create sync risk.

### AUDIT-C8-14. Customer + Order link affordances on the detail page
**Decided:** 2026-05-23

- **Customer name table links** in the Customer Night Count view (`.cust-link`) → wired to `admin.php?page=equine-event-manager-customer&customer_email=...`, styled per shipped customer-name convention (navy bold default, Electric Blue hover, no underline).
- **Order number table links** (`.order-link`) → wired to `admin.php?page=equine-event-manager-order&order_id=...`, styled per shipped order-link convention (navy bold). Order IDs converted to 5-digit zero-padded format (`#00028`, `#00007`) per the standing rule.
- **Popover customer name** (`.cust-popover-name` inside the click-pill move popover) → display-only label, NOT a link. The popover provides explicit action buttons below the name ("Move to different stall", "View order ↗"); making the name itself also a link adds redundancy with competing click targets.


### AUDIT-C8-15. Print View page — `stall_chart_print_view.html`
**Decided:** 2026-05-23

Standalone print page (canonical print-rendering surface; opened in a new tab from the detail page's Print View button). Audit decisions:

- **DEC-A=A** thead → `#f3f4f5` bg + navy text (not dark navy + white)
- **DEC-B=B** section bands → white bg + `3px solid #031B4E` top border (not solid navy fill)
- **DEC-C=B** topbar → light alt-bg + shipped button patterns
- **DEC-D=A** Daily Movement card → soft-blue accent (matches Tip banner pattern)
- **DEC-E** drop stats cards entirely (Total Stalls / Total Customers / etc.) — redundant with the chart itself
- **DEC-F=C** drop section sub-taglines under barn headers
- **DEC-G=B** drop "Section N" numeric prefix on barn headers
- **DEC-H=A** barn header rows → mixed-case (match detail page treatment)
- **DEC-I=A** zebra striping → `#f3f4f5`
- **DEC-J=A** occupancy pills → light-fill matching detail page
- **DEC-K=B** full-word state labels (Reserved / Available / Blocked, not single letters)
- **DEC-L=C** no logo (event name carries the brand identity for this single-page print)
- **DEC-M=A** drop "For:" header line above the event title
- **DEC-N=A** `window.close()` JS on the "Close" button + label "✕ Close"
- **DEC-O=A** check-in column kept (admin uses the printed sheet to track arrivals)
- **DEC-P=A** page title (browser title bar / print header) includes the event name

**Result:** 301-line standalone print page, black-and-white printer friendly, page-break-inside hints on key blocks.

---

## C11 — Order Creation + Payment Collection

### AUDIT-C11-1. Invoicing page split into two pages
**Decided:** 2026-05-23

The original mockup `invoicing_page.html` collapsed Create Order and Collect Payment into one tabbed page. This is **wrong product structure**. Two separate pages instead:

**`create_order_page.html`** — Admin manually creates a new order on behalf of a customer (phone orders, walk-ins). New admin route: `equine-event-manager-create-order`. Launched from the **"+ Create Order" primary button on the shipped Orders list page header**.

**`collect_payment_page.html`** — Admin processes payment on an existing unpaid/invoice-sent order. New admin route: `equine-event-manager-collect-payment`. Receives an `order_id` URL param. Launched from:
- The **"Collect" pill action** on each unpaid-order row in the shipped Orders list
- The **"Collect Payment" button** in the orange payment-banner on the shipped Order Detail page

**Handoff to Claude Code (mechanical edits to shipped files):**

1. `orders_page.html` (shipped) — "+ Create Order" button (header, currently `href="#"`) → wire to `admin.php?page=equine-event-manager-create-order`
2. `orders_page.html` (shipped) — each "Collect" pill in unpaid-order rows (currently `href="#"`) → wire to `admin.php?page=equine-event-manager-collect-payment&order_id=<id>` (template variable per row)
3. `order_detail_page.html` (shipped) — "Collect Payment" button in the orange payment-banner (line ~381 in shipped, currently a `<button>` with no action) → convert to `<a href="admin.php?page=equine-event-manager-collect-payment&order_id=<id>" class="btn-collect-banner">` with the current order's ID
4. **DELETE `invoicing_page.html`** entirely — obsolete; replaced by the two new files above
5. Remove any "Invoicing" sidebar entry that was added during earlier scaffolding (sidebar = Dashboard / Orders / Reservations / Stall & RV Charts / Reports / Settings — NO Invoicing)
6. Remove any `equine-event-manager-invoicing` admin route registration in `eem-admin.php`
7. **Register** the two new admin routes: `equine-event-manager-create-order` and `equine-event-manager-collect-payment`

### AUDIT-C11-2. Create Order — page scope decisions
**Decided:** 2026-05-23

- **PRE-4=A** Customer Search (typeahead) → **kept**. Top of page. Search by name/email/phone with prior-order count shown in results. Skip path for new customers.
- **PRE-5=A** Custom Line Items section → **kept**. Repeating list where admin can add one-off charges (late fee, damage charge, transferred credit). Each row: description input + price input + delete button. Rolls up into Order Summary as line items.
- **PRE-6=A** Discount affordance on Order Summary rail → **kept**. Dollar / percentage toggle, value input, **required reason field** logged in Activity Log. Applied state shows as green confirmation strip with Remove link.
- **PRE-7=B** Payment options on Create Order → **Send Link + Charge Card only.** "Add to Show Bill" affordance **dropped** (deferred to future feature — needs more product thinking around when settlement triggers and where the show bill lives).
- **PRE-11=C** Reservation picker → always present at top of page, optionally pre-filled by `?reservation_id=N` URL param. "Change" affordance preserves ability to swap reservations.
- **PRE-12=B** Page section order: Customer Search → Reservation Picker → Contact Info → Stall Reservations → RV Reservations → Add-Ons → Custom Line Items → Group → Special Requests → (rail: Order Summary + Discount + Payment).

**Rationale for customer-search-first ordering (PRE-12):** the customer typeahead's job is to prevent duplicate customer records. That decision (existing vs new) should be made before form fields populate. Once a customer is picked, contact info autofills; admin then picks the reservation.

### AUDIT-C11-3. Collect Payment — page scope decisions
**Decided:** 2026-05-23

- **PRE-8=A** Page arrives with `order_id` URL param. **No order search picker.** Empty state placeholder if no `order_id` is present.
- **PRE-9=B** Discount affordance present on Amount Due rail. Same UX as Create Order — admin can apply a discount at payment time with required reason field, order total updates accordingly. (Note: amending the order via Order Detail page is still the preferred path for substantive changes; Collect Payment's discount is for in-the-moment adjustments at payment.)
- **DEC-3=A** Empty state placeholder card centered in body: "No Order Specified — return to Orders list" + back button. No search picker fallback (per PRE-8).
- Read-only display of customer + order info. Status badge uses shipped Orders list `status-invoice` palette.
- Three-segment breadcrumb: `Orders / #00021 / Collect Payment`.

### AUDIT-C11-4. Create Order + Collect Payment — visual decisions
**Decided:** 2026-05-23

- **DEC-1=A** Rail Order Summary card header → `#f3f4f5` light alt-bg + navy mixed-case title + `#dcdcde` border-bottom (was solid navy fill). Cross-screen consistency with shipped card-header convention.
- **DEC-2=A** Order Summary total amount renders in **Electric Blue accent** (`#1668F2`). Label "Total" stays navy. Draws the eye to the grand total (the number admin most cares about). Matches Order Detail's grand-total emphasis.

---

## C11 — Customer Confirmation Email

### EMAIL-3 (REVISED). Cancellation Policy removed from email + receipt + hosted page
**Decided:** 2026-05-23 (revises original EMAIL-3 decision)

**REVERSED:** Cancellation Policy is **dropped from all three customer-facing surfaces** (email, receipt, hosted order page).

**Rationale (user-supplied):** policies will change over time and will eventually be **per-reservation** (different events have different cancellation terms). Scaffolding a global cancellation policy that ships with v1 risks technical debt when the proper per-reservation feature is built later. Better to ship without the field and add it correctly when product is ready to design it.

**Handoff to Claude Code:**

In shipped `settings_page.html`:
- Remove the "Cancellation Policy" textarea field from Settings → Notifications/Reservations area (around line 983 in shipped)
- Remove the `{{cancellation_policy}}` placeholder chip from the email-templates placeholder palette (around line 766)
- Remove the entire "Cancellation" email template card (around lines 899–940) including its title, preview, and editable RTE area
- Remove any associated `wp_option` key, settings save handler, and database persistence for the cancellation policy field
- Remove any references to cancellation policy in email template rendering code

### EMAIL-4 (REVISED). Event Day Info — per-reservation configurable
**Decided:** 2026-05-23

The What's Next / Event Day Info content block (Check-in instructions / What to bring / Parking / Event-day contact) is **per-reservation configurable** via a new section on the Reservation editor.

**Rendering surfaces:**
- ✅ Customer confirmation email (when enabled)
- ✅ Hosted order page (Phase 3 implementation)
- ❌ **NOT** on the PDF receipt (per DEC-3 in the receipt audit — receipt stays a transactional/financial document only)

**Editor (`edit_reservation_page.html`) — new section added:**

- Location: Sibling card directly **between Check-In/Check-Out and Stall Reservations** in the section list
- Section title: **Event Day Info**
- Section icon chip: **icon-orange** (28×28, `#FFF7ED` bg + `#c2410c` map-pin SVG icon) — visually differentiated from Check-In/Check-Out's icon-teal clock icon
- Enable toggle: **defaults to ON**
- Helper text: "Customer-facing info shown in the confirmation email, on the hosted order page, and on the PDF receipt. Leave any field blank to omit that line from the email. Disable the section to hide it entirely."
- **Four structured fields** (NOT a rich-text WYSIWYG):

  | Field label in editor | Renders in customer email as |
  |---|---|
  | Check-in instructions | **Check-in opens:** [value] |
  | What to bring | **What to bring:** [value] |
  | Parking | **Parking:** [value] |
  | Event-day contact | **Questions on event day:** Call the event hotline at [value] |

  Each field is optional. If a field is empty, that bullet is omitted from the email. If all four are empty AND the section is disabled, the entire What's Next card in the email is omitted.

  Each editor field has an inline hint showing how it renders in the email: "Appears as: *Check-in opens: [your text]*"

**Why structured fields over WYSIWYG:**
- Non-technical admins fill out simple text inputs
- No formatting decisions
- Consistent rendering across all customer emails
- No risk of admin pasting bad HTML
- Easier to migrate the structure later

**Storage (Phase 3):** WordPress post-meta on the reservation post:
- `_eem_event_day_enabled` (bool)
- `_eem_event_day_checkin` (string)
- `_eem_event_day_bring` (string)
- `_eem_event_day_parking` (string)
- `_eem_event_day_contact` (string)

**Email template rendering (Phase 3 PHP):**
```php
<?php if ($event_day_enabled): ?>
  <div class="whats-next">
    <div class="whats-next-head">
      <svg ...><!-- clock icon --></svg>
      <span>What's Next — Event Day Info</span>
    </div>
    <div class="whats-next-body">
      <?php if ($event_day_checkin): ?>
        <p><strong>Check-in opens:</strong> <?= esc_html($event_day_checkin) ?></p>
      <?php endif; ?>
      <?php if ($event_day_bring): ?>
        <p><strong>What to bring:</strong> <?= esc_html($event_day_bring) ?></p>
      <?php endif; ?>
      <?php if ($event_day_parking): ?>
        <p><strong>Parking:</strong> <?= esc_html($event_day_parking) ?></p>
      <?php endif; ?>
      <?php if ($event_day_contact): ?>
        <p><strong>Questions on event day:</strong> Call the event hotline at <strong><?= esc_html($event_day_contact) ?></strong>.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
```

### AUDIT-C11-5. Confirmation email visual decisions
**Decided:** 2026-05-23

- **DEC-1=B** Mockup keeps `<style>` block for design-time readability. **Phase 3 mailer code inlines CSS at send-time** via a library (Premailer / juice / similar). This is required because Outlook desktop, Gmail mobile, and some Yahoo configurations strip `<style>` blocks. Without inlining, the email would render brokenly in those clients.
- **DEC-2=A** All UPPERCASE small-caps labels in the email converted to mixed-case. Same VIS-2 treatment as admin pages.
- **DEC-3=A** Items table thead → light alt-bg `#f3f4f5` + mixed-case 12px/600 navy. No more dark navy + white UPPERCASE.
- **DEC-4=A** Special Requests palette → soft-blue info accent (`#F0F4FB` + `#D9E2F2`). No more amber warning palette (semantic misapplication).
- **DEC-5=A** All emojis (`📎 📞 ✉️ ✓`) replaced with **inline SVG icons** using `stroke="currentColor"`. Reliable rendering across all email clients.
- **DEC-6 resolved by DEC-7=B** Standard logo placeholder spec (`160×36`, `#e0e0e0` bg, `1px dashed #bbb`, `#999` text). No dark-bg variant needed since header is now white.
- **DEC-7=B** Email header → **white bg with `border-bottom: 3px solid #031B4E`**. Logo + serif event name + dates render on white. Email footer also converted to white with `border-top: 1px solid #e5e7eb` for consistency (previously solid navy band).
- **DEC-8 revised=B (soft Electric Blue info accent)** Confirmation bar treatment evolved through iteration:
  - First decision: green success palette (semantically accurate but visually too aggressive — user feedback rejected)
  - Final: **soft Electric Blue info accent** (`#F0F4FB` bg + `#D9E2F2` border + Electric Blue check circle with white SVG check + navy text + Electric Blue Amount Paid value). Matches the assignments card and PDF attachment note palette — visually integrated with the rest of the email's soft-blue accents.
- **DEC-9=A** Order meta pill dots **removed entirely**. Decorative element with no semantic content.
- **DEC-10=A** Order meta pills → **flat shipped type-badges** (3px radius rectangles, per-type colors): Stall blue / RV purple / Add-On orange / Group green. Matches shipped Orders list type-badge convention.
- **DEC-11=A** No "view in browser" link at top. The greeting paragraph's "view your order online ↗" already serves as the fallback affordance.
- **DEC-12=A** No unsubscribe / mailing preferences link. Purely transactional email — CAN-SPAM doesn't require it; adding one would suggest this is a marketing channel.
- **"Reservation Confirmed" tag dropped** from the header. The (now soft-blue) confirmation bar carries the "you're confirmed" message all by itself; the redundant chip was iterated to teal palette → navy palette → then dropped entirely.
- **Signoff paragraph dropped** ("We look forward to seeing you at the 2026 Southeast Region Super Sort. Thank you for your reservation!"). Email ends cleanly with the Support block + footer.
- **Cancellation Policy dropped** per EMAIL-3 (REVISED) above.

**Typography fixes during build:**
- Body font: `'IBM Plex Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif` (was Helvetica Neue only). Web font with email-safe fallback chain.
- Event title font: `'Space Grotesk'` (was Georgia serif). Matches shipped page titles.
- All border-radii consolidated to 4px (was mixed 5px/6px/8px).

**Order number:** 5-digit format `#00020` (was 4-digit `#0020`).

---

## C11 — PDF Receipt / Hosted Order Page

### AUDIT-C11-6. Receipt page visual decisions
**Decided:** 2026-05-23

`order_receipt.html` serves dual purpose: PDF attached to confirmation email AND hosted page customers reach via "view your order online ↗".

- **DEC-1=A** All UPPERCASE small-caps labels in receipt converted to mixed-case. Same treatment as email + admin pages.
- **DEC-2=B** Items table zebra striping **kept**, color aligned to standard `#f9f9f9` (was custom `#FAFBFE`). Receipt is wider than email and shown in print/PDF context where zebra striping helps eye tracking across rows.
- **DEC-3=B** Event Day Info **NOT** included on the receipt. Receipt stays a transactional/financial document only. (Event Day Info appears on the email and hosted page; the receipt's role is "proof of payment.")
- **DEC-4=A** **Stall Assignments card added** to the receipt — same soft-blue info-accent treatment as the confirmation email. Shows specific stall numbers + RV lot + dates. Receipts at hotels show room numbers; receipts at parking show spot numbers — the equine equivalent is showing stall numbers. The original receipt only had aggregate counts ("1 Stall reserved · 2 nights"); the assignments card adds the operational/transactional specifics customers need at the venue.
- **Cancellation Policy removed entirely** per EMAIL-3 (REVISED).

**Other visual fixes:**
- Items table thead → light alt-bg `#f3f4f5` + mixed-case navy (was dark navy + UPPERCASE white)
- Special Requests → soft-blue info accent (was amber warning palette)
- Order info box → soft-blue info accent aligned to documented `#F0F4FB` / `#D9E2F2`
- Customer/Billing dblocks → standard `#f3f4f5` bg + `#e5e7eb` border (was custom `#F7F9FC` / `#e8eaf0`)
- Reservation cards header → standard alt-bg + `#dcdcde` border-bottom
- Reservation card badges (`.rcb`) → shipped type-badge palette colors (Stall blue, RV purple, Group green); **new `.rcb.addon` orange variant added** for consistency with shipped type palette
- Logo placeholder → standard spec (`160×36`, `#e0e0e0` bg, `#bbb` dashed border) — was `150×42` with `#ccc`
- Body color `#1d2327` (was custom `#1a1a2e`)
- 5-digit order ID `#00020` (was `#0020`)
- Subtotal row gets slight emphasis (bold navy) to break up the line-items vs fees+tax sections
- Print stylesheet enhanced: prints soft-blue accent cards as white for ink savings; zebra disabled in print
- `page-break-inside: avoid` on key blocks (Assignments, Special Requests, Totals, reservation cards) for cleaner page splits

### AUDIT-C11-7. Items-table zebra striping scoped fix (post-delivery patch)
**Decided:** 2026-05-23

Bug found post-delivery: the global `tbody tr:nth-child(even){background:#f9f9f9}` zebra rule (intended for the Purchased Items table per DEC-2) was bleeding into the `.assignments-table` rows, causing the RV Lot row to render with a zebra-color background while the Stalls row stayed transparent. Visible as an unintended color mismatch inside the Your Assignments soft-blue card.

**Fix applied:**
- Scoped the zebra rule from global `tbody tr:nth-child(even)` to `.items-table tbody tr:nth-child(even)`
- Added `class="items-table"` to the Purchased Items `<table>` element so the scoped rule still targets it
- Also scoped the related `tbody tr` border rules (`border-bottom:1px solid #f0f0f1` and `:last-child{border-bottom:none}`) to `.items-table tbody tr` for the same reason
- Print stylesheet override (`tbody tr:nth-child(even){background:#fff}`) also scoped to `.items-table tbody tr:nth-child(even)`
- Added explicit `background:transparent` declarations on `.assignments-table`, its `tr`, and its `td` as belt-and-suspenders against any future global tbody styling

**Lesson for Phase 3:** Avoid global element selectors (`table`, `tbody tr`, `td`) in stylesheets that contain multiple tables with different visual treatments. Always scope to a class. This pattern bit us once; the principle is to prevent it from biting us again as more tables get added to the receipt or other shared-CSS files.

Same scoped-zebra fix was preventively applied to `.assignments-table` in `customer_confirmation_email.html` for consistency, though the email's items-table didn't have the same bleed issue (no shared global tbody rule was present in that file's CSS).

---

## C12 — Reports Page

### AUDIT-C12-1. Reports page audit + visual decisions
**Decided:** 2026-05-23

- **DEC-1=A** Format codes (CSV / PDF / ZIP) stay **UPPERCASE as proper acronyms**. They're not the VIS-2 anti-pattern (decorative UPPERCASE labels); they're legitimate acronyms like "API" or "URL" rendered in their canonical case. Applies to the format column in Export History and the button labels on report cards.
- **DEC-2=B** ZIP export card → **soft-blue info accent** (`#F0F4FB` bg + `#c0d8ff` border). No gradient. No thick Electric Blue border. Matches the established info-accent pattern from chart-help tip banner and Daily Movement card.
- **DEC-3=B** Six-color report-icon palette **kept and documented as permitted deviation**. Each report has a distinct icon chip color for at-a-glance scanning: Orders blue, Reservations purple, Revenue green, Stall Occupancy teal, Customers orange, Refunds red. **Icons converted to chip style** (28×28 light tinted bg + saturated icon color matching tint family, 14×14 SVG) — matches the edit_reservation section-icon chip pattern, not the original saturated-fill 36×36 dark-bg + white-icon treatment.
- **DEC-4=A** Standard **pagination footer** on Export History — matches shipped pagination convention from Reservations/Orders list pages. Page size 20.
- **DEC-5=C** History rows = **static log entries, no click action**. Hover effect for affordance feedback only. Re-export happens via the per-row Download button (DEC-6).
- **DEC-6=B** Per-row **Download button when file still cached** (`.btn-download` ghost button with download icon). When file expired/purged: italic gray "Expired — re-export" text with re-export link routing back to the relevant report card. Server-side: keep generated export files for N days (configurable in Settings → Reports, future), then auto-purge. History row checks file existence to decide which affordance to show.
- **DEC-7=A** Date range filter gets a **preset dropdown** (matches DASH-2 metric range pattern). Options: Last 7 days / Last 30 days (default) / Last 90 days / This year / All time / Custom range. Picking a preset auto-fills the two date inputs. Manually editing dates flips the preset to "Custom." JS hooks in the mockup; full implementation in Phase 3.
- **DEC-8=A** Filter state **persists to localStorage** (key: `eem_reports_filter_state`). Includes reservation selection, date preset + custom dates, and status. Matches DASH-2 dashboard filter persistence pattern. Easy reset via the existing "Reset filters" button.
- **DEC-9=B** **Scheduled reports deferred to v2.** Out of scope for v1 — scheduled reports require non-trivial infrastructure (cron, email delivery, recipient management, failure handling, per-schedule history view). Captured here for future product planning.
- **DEC-10=B** **Direct download behavior** on CSV/PDF buttons. No preview modal. Admin opens the downloaded file in Excel/PDF viewer.

---

## Dashboard

### DASH-AUDIT-1. Dashboard page audit + visual decisions
**Decided:** 2026-05-23

- **DEC-1=A** Single `.plugin-wrap` shell containing everything. **Welcome bar dropped** — greeting + sub absorbed into the page header band. Page header band has title + subtitle + actions slot (matching shipped page-header pattern). Most consistent with VIS-3 across the rest of the app.
- **DEC-2 (2a=A, 2b=status-line subtitle)** Page header:
  - Title: **"Dashboard"** (consistent with every other admin page)
  - Subtitle: status-line format — **"Good morning, Whitney · Thursday, May 21, 2026 · 3 reservations coming up in the next 30 days"**
  - Action slot (right side of header band): **Create Order primary button + View Reservations ghost button**
- **DEC-3=A** Emoji **dropped from greeting**. "Good morning, Whitney" — plain text. Admin pages don't use emojis anywhere else; consistency prevails over marginal warmth gain.
- **DEC-4=C (revised)** Metric card top borders **kept**, but consolidated to **documented palette** only:
  - Total Revenue → Electric Blue `#1668F2`
  - Outstanding Payments → Add-On orange `#c2410c` (semantically "needs payment attention")
  - Total Orders → Group green `#15803d`
  - Unassigned Stalls → Red `#b91c1c` (documented permitted variant)

  Original mockup used `#dc2626` / `#d97706` / `#16a34a` which are sibling shades not in the documented palette. Consolidation kills color drift.
- **DEC-5=A** Quick Actions tiles → **documented info-accent** (`#F0F4FB` bg + `#D9E2F2` border + Electric Blue hover border). Was custom `#F7F9FC` + `#D9E2F2`.
- **DEC-6=A** Revenue chart bars **all Electric Blue** `#1668F2`. Bars differentiated by height alone (color isn't carrying info). Replaces the original 5-color mix that introduced brand teal `#26D0B5`, amber `#d97706`, and red `#dc2626` for no semantic purpose.
- **DEC-7=A** Needs Attention count badge → **documented red palette** (`#FEF2F2` bg + `#b91c1c` text + `#fecaca` border). Same red family used in Assignment Issues card (C8 detail) and Refunds report icon.

### DASH-AUDIT-2. Dashboard color consolidation
**Decided:** 2026-05-23

Dashboard had 40+ unique colors with multiple shades for the same semantic role. Consolidated to documented palette:

- **All greens** → `#15803d` (was 3 different shades: `#16a34a` / `#22c55e` / `#15803d`)
- **All reds** → `#b91c1c` (was 3 different shades: `#dc2626` / `#be123c` / `#b91c1c`)
- **All oranges** → `#c2410c` (was mixed `#d97706` / `#b45309` / `#c2410c`)
- **Purples** → `#6d28d9` (was `#7c3aed` / `#6d28d9` mixed)
- **Removed entirely:** brand teal `#26D0B5` (only used on the dashboard), custom blue-gray `#90aed4`, custom soft-blue hover `#fafbff`
- Status badges (sb-paid / sb-unpaid / sb-invoice / sb-refunded) now use the documented shipped Orders list palette exactly

Final color count: ~37 unique colors, all from documented palette or shipped UI tokens.

### DASH-AUDIT-3. Semantic markup conversion
**Decided:** 2026-05-23

Recent Orders / Upcoming Reservations / Needs Attention rows converted from `onclick="window.location.href=..."` JavaScript navigation to **proper `<a>` tags with hrefs**. Adds:
- Real link semantics (right-click "open in new tab" works)
- Keyboard navigation (Tab + Enter)
- Hover preview in browser status bar
- Screen reader accessibility

Row hover effects updated: row hover triggers `.res-name` / `.attention-title` / `.order-num-badge` color change to Electric Blue (clear feedback that the whole row is the link).

All href URLs wired to `admin.php?page=...` routes.

### DASH-AUDIT-4. Order ID format consistency
**Decided:** 2026-05-23

All dashboard order references converted to **5-digit zero-padded format** (`#00028`, `#00027`, `#00026`, `#00025`, `#00024`) per the standing rule.

---

## Cross-cutting handoff tasks for Claude Code (Phase 3)

This section consolidates all the mechanical edits Claude Code needs to make to shipped/canonical files. The corrected mockups are ready; these edits apply the new routes and remove deprecated concepts.

### Handoff 1: Stall & RV Charts rename (from earlier audit AUDIT-C8-1)
Rename the sidebar entry "Stall Charts" → "Stall & RV Charts" on every shipped admin page:
- `dashboard_page.html` ✅ (already in corrected version)
- `orders_page.html`
- `reservations_page.html`
- `settings_page.html`
- `order_detail_page.html`
- `customer_profile_page.html`
- `event_page.html` (n/a — customer-facing, no sidebar)

Rename the admin route registration in `eem-admin.php` to match.

### Handoff 2: Invoicing page removal + Create Order / Collect Payment routes (from AUDIT-C11-1)
- DELETE shipped/legacy `invoicing_page.html`
- Remove any "Invoicing" sidebar entry (the corrected mockups already have it removed; ensure shipped pages do too)
- Remove any `equine-event-manager-invoicing` admin route registration in `eem-admin.php`
- REGISTER two new admin routes: `equine-event-manager-create-order` (→ `create_order_page.html`) and `equine-event-manager-collect-payment` (→ `collect_payment_page.html`)
- Wire shipped Orders list "+ Create Order" button (currently `href="#"`) → `admin.php?page=equine-event-manager-create-order`
- Wire shipped Orders list "Collect" pill in each unpaid-order row → `admin.php?page=equine-event-manager-collect-payment&order_id=<id>`
- Wire shipped Order Detail "Collect Payment" button in payment-banner → `admin.php?page=equine-event-manager-collect-payment&order_id=<id>` (convert from `<button>` to `<a href="...">`)

### Handoff 3: Cancellation policy concept removal (from EMAIL-3 REVISED)
In shipped `settings_page.html`:
- Remove the "Cancellation Policy" textarea field from Settings → Notifications/Reservations area (around line 983)
- Remove the `{{cancellation_policy}}` placeholder chip from the email-templates placeholder palette (around line 766)
- Remove the entire "Cancellation" email template card (around lines 899–940)
- Remove any associated `wp_option` key, settings save handler, and database persistence
- Remove any references to cancellation policy in email template rendering code

Cancellation policy concept is **deferred entirely** — will eventually be per-reservation, not global. Do not scaffold a global field that will be replaced.

### Handoff 4: Event Day Info implementation (from EMAIL-4 REVISED)
Edit Reservation page (`edit_reservation_page.html` — corrected mockup) includes the new "Event Day Info" section between Check-In/Check-Out and Stall Reservations. Phase 3 must:
- Add WordPress post-meta fields: `_eem_event_day_enabled`, `_eem_event_day_checkin`, `_eem_event_day_bring`, `_eem_event_day_parking`, `_eem_event_day_contact`
- Wire the save handler for the section's toggle + four text fields
- Update the confirmation email template renderer to use the PHP template snippet documented in EMAIL-4 (REVISED) above
- Update the hosted order page renderer to use the same Event Day Info block (NOT the receipt — explicitly excluded)
- When the section is disabled, omit the entire What's Next card from the email; when individual fields are empty, omit only those bullets

### Handoff 5: Email mailer CSS inlining (from AUDIT-C11-5 DEC-1)
The corrected `customer_confirmation_email.html` mockup keeps a `<style>` block for design-time readability. The Phase 3 mailer code must inline CSS at send-time using a library (recommended: Premailer for PHP, or Roadrunner, or juice if migrating to Node). This is REQUIRED — without inlining, the email renders brokenly in Outlook desktop, Gmail mobile app, and some Yahoo configurations.

A comment in the email template's `<head>` flags this requirement to future maintainers.

### Handoff 6: Order ID format — 5-digit zero-padded
Standing rule across all corrected mockups: order numbers display as 5-digit zero-padded (`#00020`, `#00128`, etc.). Phase 3 PHP rendering must use `sprintf('#%05d', $order_id)` or equivalent everywhere an order number is displayed: order detail page, orders list, dashboard, email, receipt, hosted order page, reports export filenames, activity log entries.

### Handoff 7: Reports — per-row Download / Expired affordance (from AUDIT-C12-1 DEC-6)
Phase 3 implements file-cache awareness:
- Generated export files stored in a `/wp-content/uploads/eem-reports/` directory (or similar)
- Files auto-purged after N days (configurable; default 30)
- Reports page history row checks file existence:
  - File exists → render `.btn-download` button with file URL
  - File missing → render `.expired-link` text with re-export link routing back to the relevant report card with the original filter context restored

### Handoff 8: Reports — filter state persistence (from AUDIT-C12-1 DEC-8)
localStorage key: `eem_reports_filter_state`. Stored value: JSON with reservation_id, date_preset, date_from, date_to, status. Hydrated on page load; written on every filter change. "Reset filters" button clears the key and re-renders defaults.

### Handoff 9: Reports — date range preset auto-fill (from AUDIT-C12-1 DEC-7)
The preset dropdown's `onChange` updates the two date inputs:
- `last-7` → 7 days ago to today
- `last-30` → 30 days ago to today
- `last-90` → 90 days ago to today
- `this-year` → Jan 1 of current year to today
- `all` → Jan 1, 2020 (or first export ever) to today
- `custom` → no auto-fill; manual edit only

Manual edit of either date input flips the dropdown to "Custom".

### Handoff 10: Image search / report icon palette documented as permitted deviation (AUDIT-C12-1 DEC-3)
Reports page uses six distinct icon chip colors that don't all exist in the four-color type-badge palette (blue/purple/orange/green). The two additional variants:
- **Teal** (Stall Occupancy): `#F0FDFA` bg + `#0d9488` icon
- **Red** (Refunds): `#FEF2F2` bg + `#b91c1c` icon

These are **documented as permitted deviations** for the six-color report-icon palette specifically. NOT to be reused outside the Reports page report-icon context.

---

## Documented design system tokens — final reference

### Section icon chip palette (28×28, light tinted bg + saturated icon, 14×14 SVG, 4px radius)
Used on `edit_reservation_page.html` section headers, `reports_page.html` report cards, dashboard Quick Actions, dashboard Needs Attention rows.

| Token | Background | Icon color |
|---|---|---|
| icon-blue / qi-blue / icon-blue (attention) | `#EEF4FF` | `#1668F2` |
| icon-green / qi-green | `#F0FDF4` | `#15803d` |
| icon-purple / qi-purple | `#F5F3FF` | `#6d28d9` |
| icon-orange / qi-orange / icon-orange (attention) | `#FFF7ED` | `#c2410c` |
| icon-teal | `#F0FDFA` | `#0d9488` |
| icon-navy | `#EEF4FF` | `#031B4E` |
| icon-red (attention) | `#FEF2F2` | `#b91c1c` |

### Status badge palette (Orders)
Used on shipped Orders list, Order Detail, dashboard Recent Orders, Collect Payment status display.

| Status | Background | Text | Border | Dot |
|---|---|---|---|---|
| Paid | `#F0FDF4` | `#15803d` | `#bbf7d0` | `#15803d` |
| Unpaid | `#FEF2F2` | `#b91c1c` | `#fecaca` | `#b91c1c` |
| Invoice Sent | `#EFF6FF` | `#1d4ed8` | `#bfdbfe` | `#3b82f6` |
| Refunded | `#F5F3FF` | `#6d28d9` | `#ddd6fe` | `#6d28d9` |
| Partial | (TBD — Phase 3) | | | |
| Cancelled | (TBD — Phase 3) | | | |

### Reservation state badge palette
UPPERCASE small-caps (legitimate exception for reservation states).

| State | Background | Text | Border | Dot |
|---|---|---|---|---|
| ACTIVE | `#dcfce7` | `#15803d` | (none) | `#16a34a` |
| DRAFT | `#fef3c7` | `#a16207` | (none) | `#ca8a04` |
| ARCHIVED | `#e5e7eb` | `#52525b` | (none) | `#71717a` |
| TRASHED | `#fee2e2` | `#b91c1c` | (none) | `#dc2626` |

### Type badge palette (Stall/RV/Add-On/Group)
Used everywhere reservation type is visualized: shipped Reservations list, Orders list type column, customer email order meta, receipt reservation cards, dashboard tag chips. Flat 3px radius rectangles. NO dots.

| Type | Background | Text | Border |
|---|---|---|---|
| Stall | `#EEF4FF` | `#1668F2` | `#c0d8ff` |
| RV | `#F5F3FF` | `#6d28d9` | `#ddd6fe` |
| Add-On | `#FFF7ED` | `#c2410c` | `#fed7aa` |
| Group | `#F0FDF4` | `#15803d` | `#bbf7d0` |

### Info accent (soft-blue)
Used for: assignments cards (email + receipt), PDF attachment note, chart-help tip banner, Daily Movement card, ZIP export card on Reports, Order Summary linked-event card, Quick Actions tiles on Dashboard, Order info box on Receipt, Special Requests on email + receipt, confirmation bar on email.

- Background: `#F0F4FB`
- Border: `#D9E2F2`
- Hover background (when interactive): `#EEF4FF`
- Hover border: `#1668F2`

### Plugin-wrap shell border
Outer shell of every admin page: `1px solid #c3c4c7`.

### Inner card border
Cards inside the plugin-wrap: `1px solid #e5e7eb`.

### Card-header border-bottom
`1px solid #dcdcde`.

### Alt-bg (VIS-1)
`#f3f4f5` — used for table headers, settings nav, save bars, footer bars, readonly inputs, payment tabs, card-headers with bg fill.

### Standard logo placeholder
- Width × height: `160px × 36px` (desktop), `120px × 30px` (mobile)
- Background: `#e0e0e0`
- Border: `1px dashed #bbb`
- Radius: `4px`
- Text size: `11px` (10px on mobile)
- Text color: `#999`

### Primary CTA (VIS-4)
- Background: `#1668F2` (Electric Blue)
- Text: `#fff`
- Border: `1px solid #1668F2`
- Hover background: `#1257d1`
- Hover border: `#1257d1`

### Universal hover convention
- `a { text-decoration: none }` everywhere
- `a:hover { text-decoration: none }` — **NEVER underline on hover anywhere in the plugin.** Color change is the hover affordance; underline is not used. This is a hard product decision, not a default.
- Color transitions: navy `#031B4E` → Electric Blue `#1668F2` (or `#1257d1` for primary CTA buttons)

**Implementation rule (enforced 2.3.36):** The root `.eem-page a:hover { text-decoration: none }` rule in admin.css covers all plugin links. Any new component that adds a hover state MUST use only color/background/border changes — never `text-decoration: underline`. Every `text-decoration: underline` on any `:hover` or `:focus` selector in admin.css or admin-legacy.css is a bug and must be removed immediately.

### Order number format
`#XXXXX` — 5-digit zero-padded. Phase 3 implementation uses `sprintf('#%05d', $order_id)`.

---

## CANCELLATION-ARCH (revised) — Per-reservation cancellation policy with event-default inheritance
**Decided:** 2026-05-23 (REVISES the earlier "remove entirely" call from EMAIL-3 REVISED and the prior HANDOFF Edit 4)

**Decision summary:** Cancellation policy is **per-reservation with event-level default inheritance**, NOT globally configured in Settings, NOT removed entirely.

**Rationale for revising the prior "remove entirely" call:** Removing cancellation policy from v1 was the wrong call. Customers form a contract at purchase time under the cancellation policy that was visible at that moment. Without a cancellation policy mechanism, the venue has no defensible terms for refund disputes. The right fix was never "remove the feature" — it was "fix the architecture." The global-Settings approach was wrong (policies vary per event/reservation); the answer is per-reservation with event-default inheritance, not deletion.

### CANCELLATION-ARCH-Q1. Inheritance model
**Decided:** A2 — per-event default, stored in the plugin (not in TEC), inherited at reservation creation, overridable per reservation.

Three options were considered:
- **A1** (rejected): Per-event default stored in TEC. Rejected because cancellation policy is plugin-owned transactional data tied to refund workflows (REF-1, REF-2), not TEC event metadata. Storing in TEC would break the clean TEC-1 through TEC-4 ownership boundary.
- **A2** (chosen): Per-event default stored in the plugin (e.g., `wp_eem_event_defaults` table keyed by event_id). Inherited at reservation creation. Overridable per reservation.
- **B** (rejected): Per-reservation only, no event default. Rejected because it forces admins to retype the same policy on every reservation when most reservations should share one event's terms.

**Admin UI placement for editing event defaults:** Inside Edit Reservation (the plugin doesn't have an Edit Event page — events are TEC posts; admin's primary touchpoint with event-level data is via the linked reservation editor). Visual treatment: "edit the event default ↗" link from inside the Cancellation Policy section — opens an inline editor or modal. Final visual design call deferred to Phase 3 implementation; not blocking for handoff.

### CANCELLATION-ARCH-Q2. Customer-facing display surfaces
**Decided:** All four surfaces — with checkout treated as link-to-modal rather than full block.

| Surface | Treatment |
|---|---|
| Checkout (`event_page.html`) | Single-line agreement above Reserve/Pay button. Link opens modal or inline-expands the full policy text. Implicit-by-submission — no required-checkbox; contract formed by clicking Pay with the link visible. Agreement line placed directly above Pay button (proximity matters for legal force). Standard SaaS / e-commerce pattern (Stripe Checkout, Shopify, Eventbrite). |
| Confirmation email | Full block in `.cancellation-policy` card after Support block. Standard light-gray treatment. |
| PDF receipt | Full block after Totals, before Footer. `page-break-inside: avoid` for print. |
| Hosted order page | Same template as PDF receipt — full block. |

**Implicit-by-submission rationale:** Forcing the modal/expand to be opened before Pay is enforcement theater — customers route around it (open + immediately close without reading). The contract is formed by clicking Pay with the linked agreement visible nearby. Clickwrap jurisprudence (Specht v. Netscape, Nguyen v. Barnes & Noble) treats reasonably-visible linked terms above a submit button as valid contract formation. A required checkbox is overkill for a stall reservation refund policy; reserved for higher-stakes contracts.

### CANCELLATION-ARCH-Q3. Pre-existing reservations behavior
**Decided:** C with override-snapshot refinement — one-time migration writes the existing global `cancellation_policy` wp_option value into each existing reservation's `_eem_cancellation_policy_override` post-meta.

Three options considered:
- **A** (rejected): Inherit from event default at ship time, no migration. Rejected because the venue would suddenly show blank cancellation sections for existing reservations whose events don't have a default set yet — creates legal ambiguity about whether the policy still applies for past purchases.
- **B** (rejected): Migration writes global policy → all events' defaults at ship time. Rejected because future event-default changes would retroactively affect past purchases through the inheritance chain — wrong contract integrity behavior.
- **C with refinement** (chosen): Migration writes global policy → each existing reservation's `_eem_cancellation_policy_override` post-meta directly. Snapshots the policy onto every reservation as it was at purchase time. Future event-default changes don't affect existing reservations because they already have their own override. New reservations use event-default inheritance per the normal flow.

**Why snapshot onto override (not legacy-branch):** Putting the snapshot through the same code path as a freshly-customized reservation — "override is set, use the override" — means no special legacy-reservation code path. Clean.

**After the migration:**
- Global `cancellation_policy` wp_option becomes deprecated (no new writes; still readable until pre-launch cleanup strips it)
- Each existing reservation has its own snapshot in `_eem_cancellation_policy_override` post-meta
- New reservations created after the migration inherit from event default per Q1
- Admins can override per reservation going forward
- Pre-launch cleanup chunk eventually strips the Settings UI + the `wp_option` entirely (the snapshots persist as per-reservation data)

**Result for customers:**
- No customer ever sees their cancellation policy disappear
- Future event-default changes don't retroactively affect past purchases
- Clean architecture: every reservation has policy text by exactly two paths (explicit override stored, or event-default inheritance lookup at render time)

### CANCELLATION-ARCH — Mockup changes applied this session

**`edit_reservation_page.html` (corrected mockup):**
- New section "Cancellation Policy" added at the end (after Agreement)
- Section icon chip: **icon-red** (`#FEF2F2` bg + `#b91c1c` shield-with-X SVG icon) — semantic adjacency to refund flow + contracts
- Enable toggle (defaults to ON; when OFF, omits the policy block from all four customer surfaces for this reservation specifically)
- Inherited-default banner visible when no override is set: "Using event default cancellation policy" with explanatory copy
- Read-only event default textarea (populated from event_id lookup; `#f3f4f5` bg + `cursor: not-allowed`)
- "edit the event default ↗" link (Phase 3 wires to event-default editor)
- Per-reservation override textarea (empty = inherit; non-empty = override)
- Dynamic status hint below override:
  - Empty: "Currently using event default. Type to customize."
  - Non-empty: "Using this reservation's custom policy (event default is overridden)"
- "Restore event default" button appears when override is active; clicking clears with confirmation prompt
- CSS: new `.inherited-default-banner`, `.cancellation-override-actions`, `.btn-link-secondary` classes; `.cancellation-overridden` modifier on the section element drives the conditional rendering of the banner and restore button
- JS: `updateCancellationOverrideState()` listener on the override textarea; `restoreCancellationDefault()` button handler with confirm()

**`customer_confirmation_email.html` (corrected mockup):**
- Cancellation Policy block restored after Support block, before footer
- Treatment: `.cancellation-policy` card — standard light-gray (`#f3f4f5` bg + `#e5e7eb` border) matching the Support block treatment
- Title "Cancellation Policy" in Space Grotesk 13px/700 navy
- Body text in 12.5px `#50575e` line-height 1.55
- Phase 3 sources from `eem_resolve_cancellation_policy($reservation_id)` (override OR event default); block omitted if resolver returns null

**`order_receipt.html` (corrected mockup):**
- Cancellation Policy block restored after Totals, before Footer
- Treatment: same `.cancellation-policy` card pattern as email (consistent across email + receipt + hosted page)
- `page-break-inside: avoid` to keep policy together on one PDF page
- Print stylesheet includes `.cancellation-policy` in the lighten-on-print rule (`background:#fff; border-color:#c0c4cc`) for ink savings
- Phase 3 sources from same resolver function as email

**`settings_page.html`** — unchanged (shipped/hands-off per standing rule). The global `cancellation_policy` field continues to exist in Settings during the transition period; it serves as the source for the one-time migration (Q3 step 1). Pre-launch cleanup chunk strips it after migration runs and per-reservation flow is fully wired. See HANDOFF.md "Edit 4" (revised) for the explicit "leave in place" instruction.

### CANCELLATION-ARCH — Handoff to Claude Code

See HANDOFF.md "Backend 9: Per-reservation cancellation policy (architecture shift)" for the complete Phase 3 implementation plan: data model (9.1), resolution logic (9.2), one-time migration (9.3), edit-reservation UI (9.4), customer-facing surface treatments (9.5), enable-toggle behavior (9.6), and recommended order of implementation (9.7).

---

## 2026-06-01 — Customer Event Page (C10) build decisions

Locked during the C10.C/E sign-off + C10.D build session (cache-bust 2.3.54 → 2.3.76).

### C10-1. Add-ons are stand-alone products (ungated)
**Decided:** 2026-06-01 — *overrides the earlier "keep the gate + popup" choice from the same session.*

General Add-Ons (shavings, hay, etc.) are purchasable **with or without** a stall/RV reservation. The prior "select a stall or RV first" gate (client + server) and its explainer popup were removed. Add-ons like shavings/hay are real products a customer may want independently. Per-item `max_per_customer` still applies.

### C10-2. Cancellation Policy is per-reservation only — Settings field removed
**Decided:** 2026-06-01

The global Cancellation Policy textarea was **removed from Settings → Communications → Policies** (Terms & Conditions stays). Policy is per-reservation (`_eem_cancellation_policy_override`, inheriting the event default). The stored global option is preserved read-only (`update_policies` keeps the value when the key is absent) for legacy Cancellation-email rendering until that path is retired. Advances the "Cancellation policy architecture" shift in CLAUDE.md.

### C10-3. Check-In / Check-Out are time-of-day only
**Decided:** 2026-06-01

Editor Check-In/Check-Out are `type="time"` (no date). Stored as clean 24-hour `H:i`; legacy datetime values convert gracefully on next save. Customer display: icon pills in Stay Details — "after 10:00am" / "by 4:00pm".

### C10-4. Event Day Info renders on the customer form
**Decided:** 2026-06-01

Event Day Info (Check-In/Check-Out Instructions, What to bring, Parking, Event Contact) now renders on the customer form in the **Stay Details** card (previously email/receipt only). Labels finalized: "Check-In/Check-Out Instructions:", "Event Contact:". Editor "Appears as:" field hints removed (inaccurate). Mockup `event_page.html` did not depict this — owner-directed addition.

### C10-5. Event Pre-Entries are purchasable line items
**Decided:** 2026-06-01

Pre-Entries (`{title, inventory, price, max_per_customer}`) render on the customer form as a purchasable section modeled on Add-Ons: quantity steppers, live totals, Order Summary lines, charged at checkout, itemized in order notes. Per-customer cap enforced at submit; total-inventory enforcement deferred. No mockup existed; purchasable model chosen from the data shape.

### C10-6. Venue Map is a per-reservation upload with a Stay Details download link
**Decided:** 2026-06-01

A "Venue Map" card (single PDF/image upload) sits in the Edit Reservation editor just above Agreement. When a file is present, a "Download Venue Map" link shows at the bottom of the customer Stay Details card. For a full venue map, distinct from stall/RV charts. Reuses the `venue_map_*` meta family.

### C10-7. Reservation Name/Slug are not editable; gate robustness
**Decided:** 2026-06-01

Reservation Name + Slug always inherit the linked event (Quick Edit removed). A save can no longer orphan the `_en_event_id` link (the gated-save guard preserves an existing link), so the editor can't lock the admin out.

### C10-8. Phone numbers and emails are always clickable
**Decided:** 2026-06-01

Across the customer event page (hero producer contact + Stay Details Event Contact), phone numbers render as `tel:` links and emails as `mailto:` links.

### C10-9. "Pick Your Stalls" picker — data source + reserved semantics (C10.D)
**Decided:** 2026-06-01

The customer stall picker is built from the canonical `_en_stall_rows` row builder (one-sided / back-to-back), **not** the legacy block-range model. Selected stalls post `preferred_stall_units[]` (existing server field). Customer display shows `#`-prefixed labels (e.g. "#124, #125"). **Reserved ("Taken") is date-agnostic in V1** — any unit occupied by any existing order is marked Taken; per-selected-date availability is a deferred refinement. Blocked = `_en_blocked_stalls` (admin).

### C10-10. Stall + RV share one bookable date window (for now)
**Decided:** 2026-06-01

"Available Reservation Dates" is a single window rendered in both the Stall and RV editor sections (the duplicate-field-name save bug was fixed by JS-syncing the two instances). Auto-defaults to the linked event's dates; admin can override. Independent per-section windows would be a follow-up data-model change.

### C10-11. Card order on the customer form unchanged
**Decided:** 2026-06-01

A proposed reorder (Contact Information inside Billing & Payment; Stay Details first) was **declined** — card order stays as built.

### C10-12. One event ↔ one reservation is enforced by BLOCK, not displace
**Decided:** 2026-06-01

Each TEC event may have at most one reservation. The prior one-to-one logic
*displaced* an existing reservation (silently cleared its `_en_event_id`) when a
second reservation linked the same event — which let two drafts exist for one
event and orphaned the first. New behavior:

1. **Event picker hides taken events.** `ajax_search_tec_events` passes the
   current `reservation_id`; `search_the_events_calendar_events` filters out any
   event that already has an ACTIVE (non-trashed) reservation other than the one
   being edited. A stale reverse pointer to a *trashed* reservation does NOT
   count — that event is free to reuse.
2. **Save-time backstop blocks double-booking.** `ajax_save` rejects (HTTP 409,
   `code: event_already_linked`) any attempt to link an event that already has a
   different active reservation. Surfaced to the editor as an error toast.

New shared helper: `EEM_Reservations_CPT::get_active_linked_reservation_id_for_event(
$event_id, $exclude_reservation_id )` — single source of truth for "is this event
taken?", used by both guards. "Active" = reverse-linked reservation exists and is
not trashed/deleted. (2.3.81)

### C10-13. New reservations start completely blank — no seeded sample rows
**Decided:** 2026-06-01

The section partials rendered demo/sample rows when their meta was empty (Pre-Entries:
Friday Reining / Saturday Cutting; Stall Row Builder: Red Barn Row A/B + Yellow Barn;
RV: Red/Blue Lot + RV Row A/B). On first save those seeds persisted, so every new
reservation arrived pre-filled with content the admin never entered. All four
fallbacks now resolve to empty arrays — a new reservation form is blank and admins
add their own rows. (2.3.82, partials: `_section-event-pre-entries.php`,
`_section-stall.php`, `_section-rv.php`.)

### C10-14. Riders Per Group defaults to blank = unlimited
**Decided:** 2026-06-01

`group_riders_per_group` previously defaulted to 6. It now defaults to blank, meaning
unlimited riders per group. Field shows an "Unlimited" placeholder; an empty/zero
submission stores `''`. The publish-gate that required Riders Per Group ≥ 1 when Group
Reservations is enabled was removed (the field's own `min="1"` still prevents a
nonsensical 0 when a value IS entered). (2.3.82)

### C10-15. "View Event" button in the reservation editor header
**Decided:** 2026-06-01

Added a blue `.eem-btn-primary` "View Event" anchor in the editor header (beside
Change Event), opening the linked event's public permalink in a new tab so admins
can preview the customer view. Shown only when an event is linked. (2.3.83)

### C10-16. Bulk inventory mode hides the stall map on the customer form
**Decided:** 2026-06-01

The C10.D "Pick Your Stalls" map (and the legacy block-range selector) rendered
whenever stall rows existed, regardless of the reservation's Inventory Mode. It
now renders ONLY in Mapped mode (`stall_selection_mode === 'exact_map'`). In Bulk
mode customers just choose a quantity and the admin assigns specific stalls later
on the Stall & RV Charts page. (2.3.83, gate in
`render_quantity_stall_selection_ui`.)

### C10-17. RV add-ons carry separate per-night + weekend pricing
**Decided:** 2026-06-01

RV add-ons now store two rates: `price` (per-night, charged × nights for a Nightly
stay) and `weekend_price` (flat, charged once for a Weekend Rate stay) — e.g.
"water is $10 more per night or $50 for the weekend." The editor RV Add-Ons table
gained a "Weekend" column; `get_current_rv_addon_rate()` returns the weekend rate
for weekend stays and the per-night rate otherwise. The existing billable-units
math (1 for weekend, night-count for nightly) makes the totals correct with no JS
change. Help text added for admins (editor) and customers (form) clarifying the
add-on price is in addition to the selected RV rate. Legacy single-rate rows map
onto the new keys. (2.3.83)

### C10-18. Header button parity + RV add-on help spacing + pre-entry limits
**Decided:** 2026-06-01 (2.3.84)

- View Event / Change Event editor-header buttons share identical box metrics
  (padding, 1px border, radius, font) so they render the same size/shape.
- RV add-on customer help note got top padding so it doesn't touch the product
  divider above it.
- Event Pre-Entries surface their limit on the customer form like RV/stalls: each
  entry shows "X spots left." (its inventory) and the qty stepper caps at the
  smaller of inventory and per-customer max. (Full sold-across-orders inventory
  decrementing still lands with order persistence; the cap shown is the configured
  inventory.)

### C10-19. Admin menu icon = brand mark
**Decided:** 2026-06-01 (2.3.85)

The "Event Manager" top-level admin menu icon was changed from the
`dashicons-tickets-alt` placeholder to the Equine Event Manager brand mark. The
white SVG lives at `assets/images/menu-icon.svg` and is passed to
`add_menu_page()` as a base64 `data:image/svg+xml` URI via
`EEM_Admin::get_menu_icon()` (falls back to the dashicon if the asset is
unreadable). WordPress renders menu icons as a background image and does NOT
recolor them, so the asset is authored white to read on the dark admin menu.

### C11. Customer Confirmation Email — mockup-faithful template + Emogrifier
**Decided / shipped:** 2026-06-01 (2.3.86)

Replaced the legacy settings-textarea-body + token confirmation email with a
mockup-faithful template (`templates/emails/confirmation.php`, from
`.mockups/customer_confirmation_email.html`).

- **CSS inlining:** `pelago/emogrifier ^7.0` installed; runtime `vendor/`
  committed (self-contained, `composer install --no-dev`); autoloader wired into
  bootstrap (guarded). `EEM_Mailer::inline_css()` inlines the `<style>` block at
  send-time and degrades to raw HTML if Emogrifier is unavailable.
- **Renderer:** `EEM_Shortcodes::build_confirmation_email_html($order)` maps the
  grouped order payload → template context, reusing existing breakdown helpers.
  `send_customer_notification_email_for_order()` now calls it; the
  `customer_body` setting is unused for confirmation.
- **Decision-locks honored:** "Your Assignments" omitted while nothing is
  assigned (Bulk-mode stalls are admin-assigned later); PDF attachment note
  withheld until C12 attaches a real PDF; hosted-order link withheld until C12.
- **Conditional sections:** type badges per present section; What's Next (Event
  Day Info) gated on `_en_event_day_enabled`; Cancellation Policy from
  `_en_cancellation_policy_override` (graceful blank); Special Requests from
  order notes.
- **Bug fixed in passing:** `get_order_stall_breakdown()` read
  `required_shavings_price` / `additional_shavings_price` UNPREFIXED, but
  reservation meta is `_en_`-prefixed — so the shavings subtotal was always 0 and
  never split into its own receipt line (it folded into the stall base). Fixed to
  `_en_`-prefixed reads. Order TOTAL is unchanged (stall_subtotal already
  includes shavings); only the per-line split is restored. Affects the admin
  receipt (`build_receipt_email_html`) too, correctly.
- **Verified:** `tests/smoke/c11-confirmation-email-smoke.php` — 29 assertions
  (content-density per card, 5-digit order #, line-item rows, CSS-inline
  round-trip, positive event-day/cancellation, omit cases). Visual preview
  rendered for eyeball comparison against the mockup.

### C12. Order Receipt (PDF) + Hosted Order Page — kickoff + foundation
**Decided:** 2026-06-01 (in progress; Dompdf foundation committed)

Mockup: `.mockups/order_receipt.html`. Reuses the C11 line-items / badges / totals /
cancellation data mapping; net-new is the two-col Customer/Billing header, the
Reservation Summary card grid, a Sales Tax line, PDF generation, and the hosted web
view.

**Kickoff decision-locks (Whitney):**
- **Tax persistence (CLEANUP #9):** ADD `tax` + `tax_rate` columns to the order tables
  (migration) rather than deriving at render. The orders table is currently empty, so
  there is NOTHING to backfill — the migration is risk-free for this install. A
  defensive backfill (derive `tax = total − subtotal − fees`, read rate from the
  reservation; leave historical `total` untouched) is included for other installs.
- **Hosted page access:** TOKEN-BEARER — anyone with the unguessable `order_key` URL can
  view it (like Stripe/Shopify receipts). Required because checkout is anonymous; a
  login/ownership gate would lock customers out of their own receipt.
- **reservation_id column (CLEANUP #11):** DEFER. The grouped order payload already
  resolves `reservation_id` from notes (`extract_reservation_id_from_notes`), so the
  receipt/hosted page don't need a denormalized column. Add it in C15 Reports when a
  SQL JOIN actually needs it.

**Key architectural finding (drives increment 1):** tax is computed at checkout
(`calculate_submission_totals`) but NEVER written to the DB. Per-row `total = subtotal +
fee` excludes tax, so the stored/grouped order total understates the amount actually
charged whenever tax is enabled. Persisting tax (write per-row at checkout, aggregate in
grouping) fixes this AND corrects the C11 email "Total Paid". The convenience fee is the
template to mirror — it's split per-row via `calculate_convenience_fee(row_subtotal)`.

**Foundation landed (committed):** `dompdf/dompdf ^2.0` (v2.0.8) installed alongside
Emogrifier; runtime `vendor/` committed (`composer install --no-dev`); loaded via the
C11 bootstrap autoloader; verified rendering a valid `%PDF` stream in the WP runtime.

**Remaining increments:** (1) tax schema + checkout write + grouping aggregation
[payment-adjacent — verify with a live checkout]; (2) receipt template + builder; (3)
PDF generation → attach to confirmation email (re-enables C11's PDF note) + Order Detail
download; (4) hosted order page (`template_redirect` + `order_key` query var, re-enables
C11's hosted link); (5) smokes.

**Increment 1 — DONE (2.3.87):** Added `tax` + `tax_rate` columns to both
`en_stall_reservations` + `en_rv_reservations` (dbDelta via version-bump upgrade;
verified columns present on the live DB). Checkout writes the order-level tax ONCE
(on the stall row if present, else the RV row — no double-count) plus `tax_rate` on
every row; per-row `total` stays `subtotal + fee` (untouched, so refund/component
logic is unaffected). The grouping (`get_orders` / `get_order_by_submission_token`)
sums `tax`, takes the max `tax_rate`, and adds tax into the grouped `total` so the
order reflects the charged amount (this also corrects the C11 email "Total Paid").
Verified: `tests/smoke/c12-tax-persistence-smoke.php` (7/7) — grouped total =
$208.66 incl. $14.10 tax @ 7.5%, matching the receipt mockup grand total.
**Known follow-up (NOT this chunk, payment-adjacent):** refunds operate on per-row
`total` (subtotal+fee) and therefore do not refund the tax portion — a pre-existing
gap unchanged by this work; revisit when refund-of-tax behavior is specified.
**Live-checkout verification still pending** for the WRITE path (the read/grouping
path is smoke-covered).

**Increment 2 — DONE (2.3.88):** Receipt template + builder.
- `templates/receipt/receipt.php` — mockup port of `order_receipt.html`, authored
  **TABLE-BASED** (not grid/flex) because Dompdf supports neither; renders identically
  in the PDF and the hosted web view, `<style>` read directly by Dompdf (no Emogrifier).
- `EEM_Shortcodes::build_receipt_html($order)` — maps the order to the receipt context
  (Customer/Billing header, Reservation Summary cards for Stall/RV/Group, itemized
  totals → Subtotal → Convenience Fee → Sales Tax (rate%) → Grand Total). Tax line reads
  the persisted `tax`/`tax_rate` from increment 1.
- DRY: extracted the shared `build_order_line_items($order, $include_fee)` helper used by
  BOTH the C11 confirmation email (`include_fee=true`, fee itemized inline) and the C12
  receipt (`include_fee=false`, fee shown in the totals block). C11 smoke re-run 29/29 —
  no regression.
- Verified: `tests/smoke/c12-receipt-render-smoke.php` (25/25) — content density per
  section + Sales Tax (7.5%) $14.10 line + fee-in-totals-not-items + assignments-omitted.
  **Dompdf compatibility confirmed:** the table-based template renders a valid `%PDF`
  (18 KB), de-risking increment 3. HTML + PDF previews written to Desktop for eyeball.

**Increment 2 visual-review fix-ups (2.3.89–2.3.90):**
- Right-edge clipping (2.3.89): `.sheet` was 800px (~600pt) > Letter printable (~554pt);
  narrowed to 700px + `@page` margin 0.5in.
- Fonts (2.3.90): added the Google Fonts `<link>` + a `DejaVu Sans` fallback. With
  `isRemoteEnabled`, Dompdf fetches IBM Plex Sans + Space Grotesk from the link's
  `@font-face` and caches them, so the PDF renders brand-exact (not serif). ⚠️ The
  cache currently lands in `vendor/dompdf/dompdf/lib/fonts/` (committed) — fragile vs a
  `composer install` that could wipe the vendored package's font dir. **Increment 3 TODO:**
  point Dompdf's `fontDir`/`fontCache` at a plugin-owned dir (e.g. `assets/fonts/` or an
  uploads subdir) and register the brand fonts there so they survive reinstalls + work
  without network at render time.
- Logo header artifact (2.3.90): the `<img>` `alt` was the event title, so a failed image
  dumped the title into the header. `alt` now empty; **increment 3 TODO:** embed the logo
  as a data URI so it actually appears in the PDF.
- Customer/Billing gap (2.3.90): the details table set `width:50%` on all 3 cells incl.
  the spacer (150%); switched to a 2-col `table-layout:fixed` with internal padding.
- Billing-in-special-requests + empty billing card: was a TEST-FIXTURE bug (preview/smoke
  used `Billing Details:`/`Special Requests:` labels; real notes use
  `Billing Name:`/`Billing Address:` + freeform). Fixtures corrected; template was right.
- Footer (2.3.90): now a `position:fixed` running footer repeating on every PDF page with
  the Support line + 5-digit order number; web view keeps a normal in-flow footer
  (toggled via `@media`).
- Verified: `c12-receipt-render-smoke.php` 30/30 (adds no-billing-leak + footer order#).

**Increment 3 — DONE (2.3.91–2.3.92): PDF generation + email attach.**
- `EEM_PDF` (includes/class-eem-pdf.php) wraps Dompdf with `isRemoteEnabled=false`
  (SSRF-safe; logo is a data URI, fonts pre-registered) and returns '' on failure.
- `generate_receipt_pdf($order)` → `build_receipt_html($order, for_pdf=true)` (data-URI
  logo via `get_company_logo_data_uri`). Fixed: `esc_url()` strips `data:` URIs by
  default — the template now allows `http/https/data` for the logo src.
- `EEM_Mailer::send_html_email` gained an `$attachments` param (wp_mail path passes file
  paths; SendGrid path base64-encodes as application/pdf).
  `send_customer_notification_email_for_order` generates the PDF, writes a temp file,
  attaches it, sends, deletes the temp file; the "PDF Receipt Attached" note shows only
  when attached. Smokes: PDF 6/6, C11 31/31.

**Increment 4 — DONE (2.3.93): hosted order page + Order Detail download.**
- Token-bearer (unguessable `order_key`). One front-end route
  (`EEM_Shortcodes::maybe_render_hosted_receipt` on `template_redirect`):
  `?eem_receipt=KEY` → receipt web view; `&download=pdf` → streamed PDF
  (Content-Disposition attachment); 404 on unknown key.
- `EEM_Orders_Repository::get_order_by_order_key()` (hash_equals);
  `EEM_Shortcodes::get_hosted_receipt_url($key, $pdf)`. Wired into the C11 email (hosted
  "view your order online" link) and the Order Detail action bar (Download Receipt +
  View/Download dropdown, replacing the C11 stubs). Smoke 10/10.
- Note: real submission tokens are hex (`[a-f0-9-]`); `order_key = md5(token)`.

**C12 COMPLETE (2.3.93).** Pending live verification: checkout tax write-path (one real
test checkout) + browser eyeball of the hosted page + live PDF.

---

## New V1 / V1.1 / V2 scope — strategic chat
**Decided:** 2026-06-01 (source: `BUNDLE_COMBINED_V1_NEW_SCOPE.txt`)

Recon + sequencing: `docs/V1_NEW_SCOPE_RECON.md`. These are locked product decisions; the
implementation sequence still needs Whitney's go-ahead before code starts.

### SCOPE-B. Stall inventory model split into two independent settings (V1)
Replace the single stall "Inventory Mode" with two settings:
- **Stall Inventory Type:** Quantity-only / Numbered
- **Customer Selection:** Quantity / Pick from layout

Three valid combinations (Quantity-only+Quantity, Numbered+Quantity, Numbered+Pick). The
**Numbered+Quantity** combo (admin assigns post-purchase) is new and currently
unrepresentable. **Correction to the bundle:** the existing key is
`_en_stall_selection_mode ∈ {quantity, exact_map}`, **not** `_en_inventory_mode`. Migration
maps `quantity → (quantity_only, quantity)` and `exact_map → (numbered, pick_layout)` via a
one-time version-gated migration into new keys `_en_stall_inventory_type` +
`_en_stall_customer_selection`; the legacy key is preserved as a read fallback.

### SCOPE-D1. Single-buyer contiguous assignment (V1)
Accept current auto-assign behavior (first-N-available in pool order) for V1 — a single
early buyer already receives adjacent stalls. No explicit adjacency allocator in V1.

### SCOPE-D2. Group Name — informational/display only in V1
New **optional free-text "Group Name"** field at checkout, persisted on the order, shown on
Stall Charts pills + a "Show by group" filter chip. **Independent** of the existing
multi-rider "Group Reservation" feature. Group-aware **auto-assign clustering is deferred to
V1.1** (the buy-at-different-times timing problem isn't worth solving for launch).

### SCOPE-H. Tack stall designation (V1)
Included in V1 with: per-stall `is_tack` metadata (recommended storage = a `Tack Stalls: …`
order-notes line mirroring `Assigned Stall Units`, reusing the existing parse helper);
per-reservation tack pricing (`same` default / `discounted $` / `free`); checkout
designation in pick-from-layout mode with live summary recalc; admin chart mark/unmark;
split line items when tack price ≠ regular; visual indicator (design pass) + "Tack Stalls"
filter chip. Per-stall metadata is captured **regardless of pricing** for future
flexibility.

**REVISED + LOCKED model (Whitney, 2026-06-02): tack is PURELY OPERATIONAL — ALWAYS the same
price.** Designating a tack stall just records *which* stall is for equipment vs horse housing;
it never changes what the customer is charged. Therefore:
- **No tack pricing.** The #5a per-reservation pricing setting (same/discounted/free) was
  REMOVED (it was built before this clarification and offered options that never applied).
- **No split line items (#5c) — dropped.** All stalls always cost the same.
- **Two designation paths, both write the same `Tack Stalls:` order-notes line (subset of the
  order's assigned stall units), rendered the same on the chart:**
  1. **Quantity mode** (customer entered a count) → **admin** marks the tack stall on the Stall
     & RV Charts page after purchase. **(#5b)**
  2. **Pick-from-layout mode** (customer picked specific stalls) → the **customer** marks which
     of their chosen stalls is the tack stall at checkout. **(#5d)**
- **Visual treatment (Whitney):** the tack stall's chart pill renders **amber with an amber
  dot**. Plus a **"Tack Stalls" filter** chip on the chart.
- **NB existing groundwork:** a `tack_stall_qty` *count* already flows submission → DB column →
  billable quantity; per-STALL `is_tack` (which specific stalls) is the net-new layer the
  `Tack Stalls:` notes line adds on top — at the same price.

**Build status:** ✅ DONE (2.5.6). #5b admin chart designation + amber by-location (2.5.4);
#5b.2 by-customer amber `Tack: NN` note + "Tack Stalls" filter chip (2.5.5) — tack note is the
source of truth, NOT re-derived from the by-customer allocation pass (which can diverge from the
by-location grid); #5d customer pick-mode designation at checkout (2.5.6) — amber select revealed
by JS once stalls are picked, server-validated to be one of the picks, writes the same
`Tack Stalls:` line. #5a pricing setting removed; #5c not needed. Smokes:
`tack-5b-designation-smoke` 22/22, `tack-5d-customer-pick-smoke` 14/14. ⚠️ Pending Whitney FULL
review + one live pick-mode checkout (the JS reveal + chart round-trip are runtime claims the
smokes assert at source level only).

### SCOPE-F. Special Requests visibility (V1 polish)
Surface the existing customer `notes` "Special Requests" text on the Stall Charts page
(pill tooltip and/or Assignment Issues card) — data exists, just isn't shown there today.

### SCOPE-Customers. New top-level Customers menu (V1) + profile reconciliation
Add a top-level **Customers** menu + a **Customers list** page (WooCommerce-style: Name
Last,First | Email | Total Orders | Total Spent | Last Activity; sortable; search; filter).
**The Customer Profile page already shipped (C9, v2.4.2) as a read-only aggregate keyed by
`customer_email`** — it exceeds the originally-planned V1 "stub," so V1's only net-new
Customers work is the menu + list. Links use the existing `?customer_email=` route (no
`customer_id` — there is no customer entity, per the read-only-aggregate decision locked
earlier 2026-06-01). The "customer name = link" standing rule now has a real destination.
Full profile feature (payment methods, comm log, tier, group memberships) = V2.

### SCOPE-Part4. Venue/organizer unlink — ALREADY DONE
Customer event hero already renders venue + organizer as plain text (not TEC archive
links); phone/email/Directions remain linked. No work needed.

### Deferred
- **V1.1:** D2 group-aware auto-assign; **G1** priority sale windows by customer tier.
- **V2:** full Customer Profile feature.
- **Skipped:** **E** discipline/barn zoning; **G2** priority placement at assignment time
  (covered by manual reassignment); **J** multi-day partial reservations (already covered by
  nightly/weekend pricing).

### Locked answers to the 7 recon questions
**Decided:** 2026-06-01 (Whitney reviewed `docs/V1_NEW_SCOPE_RECON.md` §7). **Green light to
begin the V1 commit sequence.**

1. **C9 Customer Profile = the V1 destination (ACCEPTED).** The already-shipped read-only
   aggregate profile (v2.4.2) is the V1 Customer Profile. Net-new "Customers" work is only
   the top-level menu + list page; reuse `EEM_Customer_Profile_Repo` wholesale.
2. **Scenario B migration = ONE-TIME, version-gated.** Runs once on update to the new
   version; migrates all reservations at once. (Not lazy-on-read.)
3. **Tack stall storage = NOTES-LINE.** `Tack Stalls: …` line on the order, reusing the
   existing per-stall notes parser. Less new code.
4. **Tack split line items = YES.** When tack price ≠ regular, the order summary shows
   separate lines per price tier, e.g. `2 regular @ $50 + 1 tack @ $25`.
5. **Group Name = SEPARATE from "Group Reservation."** Brand-new field, independent of the
   existing multi-rider Group Reservation feature; for stall assignments only in V1. Any
   future merge of the two is out of scope.
6. **Customers list = PAGINATED FROM THE START.** Build pagination into the list page now —
   do not ship an in-memory all-rows aggregation that would need re-architecture in V1.1.
7. **D1 contiguity = ACCEPT current first-N-available.** Acceptable for V1. **Document the
   edge case:** fragmented availability can yield non-contiguous assignments for multi-stall
   orders, so admin should check multi-stall orders after auto-assign. Revisit in V1.1 if
   real usage shows it's a problem.

**Execution rule:** ship each V1 commit individually (never bundled), browser-verify each
before the next, and give each its own cache-bust version number.
