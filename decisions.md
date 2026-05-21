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
