# Phase 3 Handoff Summary

This is the actionable to-do list for Claude Code coming out of the mockup audit. Each item below is a concrete change to shipped/canonical files or new files to integrate. The full design context is in `decisions.md`; this file is the punch list.

---

## File operations

### NEW files to integrate (corrected mockups, 10 files)
These are the canonical versions from the audit. Use as-is for HTML/CSS structure; replace mock data with PHP template variables.

| File | What it is | New admin route (if applicable) |
|---|---|---|
| `dashboard_page.html` | Admin dashboard landing page | `equine-event-manager-dashboard` (existing) |
| `edit_reservation_page.html` | Reservation editor (includes new Event Day Info section) | `equine-event-manager-edit-reservation` (existing) |
| `stall_charts_page.html` | Stall & RV Charts list | `equine-event-manager-stall-charts` (existing) |
| `stall_chart_detail.html` | Single chart editor (By Location + By Customer tabs) | `equine-event-manager-stall-chart` (existing) |
| `stall_chart_print_view.html` | Standalone print page for stall chart | (no route; opened in new tab from detail page) |
| `create_order_page.html` | **NEW** — Admin manually creates an order | **NEW route: `equine-event-manager-create-order`** |
| `collect_payment_page.html` | **NEW** — Standalone Collect Payment page | **NEW route: `equine-event-manager-collect-payment`** |
| `reports_page.html` | Admin Reports hub | `equine-event-manager-reports` (existing) |
| `customer_confirmation_email.html` | Customer email template | (mailer template, not a route) |
| `order_receipt.html` | PDF receipt + hosted order page | (rendered as PDF attachment AND as a hosted page) |

### Shipped/hands-off files (6) — DO NOT modify the visual design
These are canonical references and were not changed during the audit. They may need minor mechanical edits per the handoff tasks below, but the visual design is unchanged.

- `reservations_page.html`
- `orders_page.html`
- `settings_page.html`
- `order_detail_page.html`
- `customer_profile_page.html`
- `event_page.html` (customer-facing booking form)

### DELETE
- `invoicing_page.html` — obsolete; split into `create_order_page.html` + `collect_payment_page.html`

---

## Mechanical edits to shipped files

### Edit 1: `orders_page.html` (shipped)
- "+ Create Order" button in page header (currently `href="#"`) → `admin.php?page=equine-event-manager-create-order`
- Each "Collect" pill in unpaid-order rows (currently `href="#"`) → `admin.php?page=equine-event-manager-collect-payment&order_id=<id>` (PHP-template the order ID per row)
- Sidebar: rename "Stall Charts" → "Stall & RV Charts" (if present)
- Sidebar: remove "Invoicing" entry if present

### Edit 2: `order_detail_page.html` (shipped)
- "Collect Payment" button in the orange payment-banner (around line 381) → convert from `<button>` to `<a href="admin.php?page=equine-event-manager-collect-payment&order_id=<id>" class="btn-collect-banner">` with the current order's ID
- Sidebar: rename "Stall Charts" → "Stall & RV Charts"
- Sidebar: remove "Invoicing" entry if present

### Edit 3: `reservations_page.html`, `customer_profile_page.html` (shipped)
- Sidebar: rename "Stall Charts" → "Stall & RV Charts"
- Sidebar: remove "Invoicing" entry if present

### Edit 4: `settings_page.html` (shipped) — Stall & RV Charts rename only
- Sidebar: rename "Stall Charts" → "Stall & RV Charts"
- Sidebar: remove "Invoicing" entry if present

**NOTE — Cancellation Policy in Settings:** The shipped Settings page currently has a global Cancellation Policy field, a `{{cancellation_policy}}` email placeholder, and a Cancellation email template. **Do NOT strip these during this integration pass.** The cancellation architecture has shifted to per-reservation (see "Backend 9: Per-reservation cancellation policy" below). The global Settings UI gets deprecated in a later **pre-launch cleanup chunk** once per-reservation is fully wired AND the one-time migration (Backend 9.3) has run. Until then, the global field continues to exist as the source for the migration step. Leave `settings_page.html` mockup untouched for now.

### Edit 5: `eem-admin.php` (or wherever WP admin routes are registered)
- REGISTER new route: `equine-event-manager-create-order` → loads `create_order_page.html` template
- REGISTER new route: `equine-event-manager-collect-payment` → loads `collect_payment_page.html` template, accepts `order_id` URL param
- REMOVE old route: `equine-event-manager-invoicing` (if present)
- VERIFY: route slug for Stall & RV Charts list matches sidebar text (should be `equine-event-manager-stall-charts` per the existing URL references in corrected mockups)

---

## New backend functionality

### Backend 1: Event Day Info — per-reservation post-meta
Per EMAIL-4 (REVISED) in `decisions.md`:

WordPress post-meta keys on the reservation post:
- `_eem_event_day_enabled` (bool, default false; default true on new reservations is OK if user prefers)
- `_eem_event_day_checkin` (string)
- `_eem_event_day_bring` (string)
- `_eem_event_day_parking` (string)
- `_eem_event_day_contact` (string)

The corrected `edit_reservation_page.html` includes the new "Event Day Info" section (icon-orange chip, between Check-In/Check-Out and Stall Reservations). Wire the save handler for the toggle + four text fields.

These four fields populate the "What's Next — Event Day Info" block in the confirmation email and the hosted order page. NOT on the PDF receipt — explicitly excluded.

PHP rendering snippet for the email template:
```php
<?php if ($event_day_enabled): ?>
  <div class="whats-next">
    <div class="whats-next-head">
      <svg ...><!-- clock icon, see template --></svg>
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

### Backend 2: Email CSS inlining at send-time
Per AUDIT-C11-5 DEC-1 in `decisions.md`:

The corrected `customer_confirmation_email.html` keeps a `<style>` block for design-time readability. The Phase 3 mailer code MUST inline CSS at send-time using a library. Recommended options:
- **Premailer** (PHP) — `https://github.com/Pelago/Emogrifier` or `https://github.com/MyIntervals/emogrifier`
- juice (Node) — if migrating mailer to a Node service later

Without inlining, the email renders brokenly in Outlook desktop, Gmail mobile app, and some Yahoo Mail configurations. This is **non-negotiable** for production email reliability.

A comment block in the email template `<head>` flags this requirement to future maintainers.

### Backend 3: 5-digit order ID rendering everywhere
Standing rule: order numbers display as 5-digit zero-padded (`#00020`, `#00128`, `#01234`).

Phase 3 PHP rendering uses `sprintf('#%05d', $order_id)` (or equivalent) everywhere an order number appears:
- Order detail page
- Orders list rows
- Dashboard Recent Orders
- Confirmation email
- PDF receipt
- Hosted order page
- Reports export filenames (`eem-orders-30597-20260424.csv` is event-id-based, not order-id-based, so unaffected; but per-order activity log entries DO need the 5-digit format)
- Activity log entries

### Backend 4: Reports — export file caching + auto-purge
Per AUDIT-C12-1 DEC-6 in `decisions.md`:

- Generated export files stored in `/wp-content/uploads/eem-reports/` (or similar)
- Auto-purge after N days. Default: 30 days. Make this configurable in Settings (future Settings → Reports tab if added; for now hardcode 30).
- Filenames preserved across cache + purge for the Export History row reference

Reports page Export History row Phase 3 logic:
```php
if (file_exists($cached_export_path)) {
  // render .btn-download with file URL
} else {
  // render .expired-link with re-export link
  // The re-export link routes to the same report card with the original filter context restored
}
```

### Backend 5: Reports — filter state localStorage persistence
Per AUDIT-C12-1 DEC-8 in `decisions.md`:

- localStorage key: `eem_reports_filter_state`
- Stored value: JSON with `{ reservation_id, date_preset, date_from, date_to, status }`
- Hydrated on page load (JS reads localStorage, populates filter controls)
- Written on every filter change
- "Reset filters" button clears the key and re-renders defaults

### Backend 6: Reports — date range preset auto-fill JS
Per AUDIT-C12-1 DEC-7 in `decisions.md`:

The preset dropdown's `onChange` updates the two date inputs:
- `last-7` → 7 days ago to today
- `last-30` (default) → 30 days ago to today
- `last-90` → 90 days ago to today
- `this-year` → Jan 1 of current year to today
- `all` → Jan 1, 2020 (or first export date ever) to today
- `custom` → no auto-fill; manual edit only

Manual edit of either date input flips the dropdown to "Custom".

### Backend 7: Discount handling on Create Order + Collect Payment
Per PRE-6 + PRE-9 in `decisions.md`:

Both pages have a Discount affordance on their right rail. Schema requirements:
- Discount type: `dollar` or `percent` (enum)
- Discount value: decimal
- Discount reason: string, **required**, logged in Activity Log on save
- Discount applies to subtotal; convenience fee + tax recalculate

On Order Detail page, an existing applied discount displays with the order; can be removed (with reason logged) by admin.

### Backend 8: Custom Line Items on Create Order
Per PRE-5 in `decisions.md`:

`create_order_page.html` has a Custom Line Items section where admin can add one-off charges. Schema:
- `description` (string)
- `amount` (decimal — positive or negative; negative would be unusual but allowed)
- Stored as line items on the order alongside the reservation-derived line items
- Appear on the PDF receipt, hosted order page, and confirmation email items table same as any other line item

---

### CSS scoping convention (Phase 3 maintenance note)

**Scope table-related CSS rules to a class, not to bare `tbody tr` / `table` / `td` selectors.** This avoids visual bleed between tables in the same stylesheet.

Specific example: the `order_receipt.html` items-table zebra striping rule (`tbody tr:nth-child(even){background:#f9f9f9}`) was originally written as a global selector and bled into the `.assignments-table` rows, causing an unintended color mismatch. Post-delivery patch scoped it to `.items-table tbody tr:nth-child(even)` and added `class="items-table"` to the items table markup. See AUDIT-C11-7 in `decisions.md` for the full incident log.

When adding new tables to shared CSS files going forward:
- Give every table a meaningful class (`.items-table`, `.assignments-table`, `.audit-log-table`, etc.)
- Scope all table-related rules to that class (`.foo-table thead th`, not bare `thead th`)
- Same applies to other "generic" selectors (`tbody tr`, `td`, `th`) in shared stylesheets

### Backend 9: Per-reservation cancellation policy (architecture shift)
Replaces the original "remove cancellation policy entirely" decision. Cancellation policy is now **per-reservation with event-default inheritance**. Full design rationale in `decisions.md` under "CANCELLATION-ARCH (revised)".

**9.1 — Data model:**

Two storage locations:
- **Event-level default:** Plugin-owned, stored in a plugin database table (e.g. `wp_eem_event_defaults`) keyed by `event_id` (the TEC event post ID). Field: `cancellation_policy` (text). Plugin owns this data per TEC-4 boundary (do NOT store in TEC post meta). Created or updated whenever an admin sets/edits the event default.
- **Per-reservation override:** WordPress post-meta on the reservation post. Key: `_eem_cancellation_policy_override` (string, nullable). When present, takes precedence over the event default. When absent/empty, the reservation falls back to the event default at render time.

**9.2 — Resolution logic (render-time, every customer-facing surface):**
```
function eem_resolve_cancellation_policy($reservation_id) {
    $override = get_post_meta($reservation_id, '_eem_cancellation_policy_override', true);
    if (!empty(trim($override))) return $override;
    $event_id = get_post_meta($reservation_id, '_eem_event_id', true);
    if ($event_id) {
        $default = $wpdb->get_var(...); // SELECT cancellation_policy FROM wp_eem_event_defaults WHERE event_id = $event_id
        if (!empty(trim($default))) return $default;
    }
    return null; // No policy text available
}
```
Surfaces displaying the policy must gracefully omit the block if `null` is returned (don't render an empty card).

**9.3 — One-time migration (ships with the feature, runs once on activation):**

When the per-reservation cancellation policy feature is deployed, the migration:
1. Reads the existing global `cancellation_policy` wp_option value
2. For each reservation post in the database, writes that value into `_eem_cancellation_policy_override` post-meta — **snapshotting the policy onto every existing reservation as it was at purchase time**
3. Marks the migration as complete (one-time flag stored as a wp_option to prevent re-runs)
4. Does NOT delete the global wp_option yet — that happens in the pre-launch cleanup chunk

**Why snapshot to per-reservation (not to event defaults):** preserves contract integrity. The cancellation policy a customer saw at purchase time IS the contract they formed. Storing it on the reservation prevents future event-default changes from retroactively affecting existing customers' policy terms. New reservations created after the migration use event-default inheritance per normal flow.

After migration: every existing reservation has its own snapshot in `_eem_cancellation_policy_override`. The override path in resolution logic (step 9.2) handles them through the same code path as freshly-customized reservations — no legacy branch needed.

**9.4 — Edit Reservation page (`edit_reservation_page.html` — corrected mockup):**

New section added after Agreement, before page bottom:
- Section title: **Cancellation Policy**
- Section icon chip: **icon-red** (semantic adjacency to refund-flow / contracts)
- Enable toggle: defaults to ON (when disabled, omits the policy block from ALL customer-facing surfaces for this reservation specifically — overrides both override and inherited values)
- Body shows two textareas:
  - **Event default** (read-only, populated from `wp_eem_event_defaults.cancellation_policy` for this reservation's event)
  - **This reservation's policy** (override textarea — empty means "use event default"; non-empty means override)
- "Restore event default" button appears when override is active; clicking clears the override textarea (with confirmation prompt if non-empty)
- Status hint below override textarea dynamically updates:
  - Empty: "Currently using event default. Type to customize."
  - Non-empty: "Using this reservation's custom policy (event default is overridden)"
- Link from event-default section: "edit the event default ↗" — opens the event-default editor (TBD where this lives in the admin UI; suggested: a modal launched from this link, OR a future "Event Defaults" admin sub-page; design call to be made in Phase 3 implementation)

**9.5 — Customer-facing display surfaces:**

All four customer touchpoints display the resolved policy text (from step 9.2):

| Surface | Treatment | Mockup |
|---|---|---|
| Checkout — `event_page.html` | Single-line agreement above Reserve/Pay button: "By completing this reservation, I agree to the [Cancellation Policy ↗] and [Terms ↗]". Link opens modal or inline-expands the full policy text. **Implicit-by-submission**: no required-checkbox; agreement is formed by clicking Pay with the link visible. Place agreement line directly above the Pay button (proximity matters for legal force). | Phase 3 update to shipped `event_page.html` |
| Confirmation email — `customer_confirmation_email.html` | Full block in `.cancellation-policy` card after Support block. Standard light-gray (`#f3f4f5` + `#e5e7eb`) treatment matching the Support card. | ✅ Corrected mockup |
| PDF receipt + hosted order page — `order_receipt.html` | Full block in `.cancellation-policy` card after Totals, before Footer. `page-break-inside:avoid` for clean print rendering. | ✅ Corrected mockup |

**9.6 — Per-reservation enable toggle behavior:**

The Cancellation Policy section's enable toggle controls ALL customer-facing rendering for that specific reservation:
- Toggle ON + override text present → display override
- Toggle ON + override empty → display event default (if non-empty), else omit
- Toggle OFF → omit cancellation policy block entirely from all four surfaces for this reservation (rare; covers edge cases like internal/comp reservations that don't need terms display)

**9.7 — Order of implementation work in Phase 3:**

Recommended sequence to keep the system in a working state at every step:
1. Create `wp_eem_event_defaults` table (or similar storage for event-level defaults)
2. Add `_eem_cancellation_policy_override` post-meta read/write to reservation save handler
3. Build `eem_resolve_cancellation_policy()` resolution function
4. Update confirmation email + receipt + hosted order page render code to call resolver
5. Update `event_page.html` checkout to display the agreement-line + modal/expand pattern
6. Build the admin UI for event defaults (modal-from-edit-reservation OR sub-page — design call)
7. Wire the Edit Reservation cancellation section to read event default + write override
8. Run the one-time migration (step 9.3) — snapshots global value to all existing reservations
9. (Pre-launch cleanup chunk) — strip the global Settings UI, `{{cancellation_policy}}` placeholder, Cancellation email template card if still global, and the `wp_option` itself

---

## Deferred features (documented for future scope)

| Feature | Status | Notes |
|---|---|---|
| Scheduled reports (recurring exports) | v2 | Requires cron, email delivery, recipient management, failure handling, per-schedule history |
| Bulk "Send Payment Link" action | v2 | Considered for Orders list but not implemented; deferred per AUDIT-C11-1 |
| "Add to Show Bill" deferred-payment option on Create Order | Future | Dropped per PRE-7; needs product thinking on settlement trigger + show bill data model |
| Email templates beyond confirmation | Phase 3 ad-hoc | Other transactional emails (invoice / payment received / refund / cancellation) likely needed; can be derived from the corrected confirmation email template structure |

---

## Reference: where to find details

| Topic | Reference in `decisions.md` |
|---|---|
| TEC integration boundaries | TEC-1 through TEC-4 |
| Refund workflow + paths | REF-1, REF-2 |
| Settings page sections (originally shipped) | SET-1 through SET-6 |
| Dashboard layout decisions (early) | DASH-1 through DASH-4 |
| Visual conventions (VIS-1/2/3/4) | Search `## Visual conventions` |
| Hover convention | Search `Universal hover convention` |
| Order number format | Search `5-digit zero-padded` |
| C7 reservation editor (sections, icon chips, Publish card) | AUDIT-C7 entries |
| C8 stall charts (3 files: list, detail, print view) | AUDIT-C8-1 through AUDIT-C8-15 |
| C11 Create Order / Collect Payment | AUDIT-C11-1 through AUDIT-C11-4 |
| C11 Confirmation email | AUDIT-C11-5, EMAIL-1 through EMAIL-5 (EMAIL-3 + EMAIL-4 are REVISED) |
| C11 PDF receipt / hosted order page | AUDIT-C11-6 |
| C12 Reports | AUDIT-C12-1 |
| Dashboard audit | DASH-AUDIT-1 through DASH-AUDIT-4 |
| Final design system token reference | Search `Documented design system tokens` at the very bottom |
