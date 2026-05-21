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
**Decided:** 2026-05-21

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
