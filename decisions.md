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

### RES-4. New Status column with lifecycle badges
**Decided:** 2026-05-22

A new "Status" column shows each reservation's lifecycle state as a pill badge. Four states:

| State | Badge | Visible | Notes |
|---|---|---|---|
| **Active** | green | Default list view | Reservation is published, accepting customer orders |
| **Draft** | amber | Default list view | Admin has saved but not published yet (or set back to draft) |
| **Archived** | grey | Hidden from default view | Past events admin wants to retain but not see daily. Use the filter to show. |
| **Trashed** | red | Hidden from default view (in WP trash) | Soft delete; recoverable |

**No filter tabs at the top** — keep the table simple. Admins who want to see Drafts or Archived sort by the column or use the future filter dropdown (currently the toolbar has "All dates" — that placeholder is where a status filter could live in v2).

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

