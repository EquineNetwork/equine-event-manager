# Equine Event Manager — WordPress Plugin Spec

Mockup → implementation spec for a WordPress plugin that manages equestrian event reservations (stalls, RV lots, group rider rosters, add-ons, payments). The 14 HTML files in `.mockups/` are pixel-accurate static mockups of every screen. This document tells the implementer what to build and how the pieces fit together. For workflow and decision policy, see `CLAUDE.md`.

---

## 1. File inventory

All mockup files live in `.mockups/`.

| File | Purpose | Implementation note |
|---|---|---|
| `dashboard_page.html` | Plugin home — metrics, upcoming reservations, needs-attention, recent orders, quick actions | Admin page under EEM menu |
| `orders_page.html` | All orders list with live filters (event, billing status tabs, type chips, search) | Custom list table |
| `order_detail_page.html` | Single order view, More ▾ dropdown, amber unpaid banner | Submenu of orders |
| `reservations_page.html` | List of reservation *templates* (= event-reservation configurations) | Custom post type list |
| `edit_reservation_page.html` | Editor for one reservation template — the largest screen in the plugin | CPT edit screen with custom metaboxes |
| `stall_charts_page.html` | List of stall charts | List table |
| `stall_chart_detail.html` | Editor for assigning customers to specific stalls, with barn filters | Admin page |
| `stall_chart_print_view.html` | Standalone print-friendly chart for barn managers | Public-on-print template, no admin chrome |
| `invoicing_page.html` | Create Order / Collect Payment (two modes, order search picker) | Admin page |
| `reports_page.html` | CSV exports + export history | Admin page |
| `settings_page.html` | 6-tab settings screen | Admin page using tabs |
| `event_page.html` | Customer-facing reservation form | Rendered via shortcode `[en_reservation id="N"]` |
| `customer_confirmation_email.html` | HTML email sent on order completion | Email template |
| `order_receipt.html` | PDF receipt attached to email and downloadable | Use Dompdf or similar |

---

## 2. Brand & typography

**See `BRAND_GUIDE.md` for the canonical color tokens, typography scale, and component specs.** The below is a quick-reference subset.

| Token | Value | Use |
|---|---|---|
| Equine Navy | `#031B4E` | Headings, primary text, dark buttons, primary action |
| Electric Blue | `#1668F2` | Links, active nav, navigation-style action buttons |
| Aqua Teal | `#26D0B5` | Success states, Print View button, accent details |
| Background | `#F7F9FC` | Page backgrounds, light surfaces |
| Border | `#D9E2F2` | Field borders, dividers |
| Text Secondary | `#6B7A99` | Subtext, hints |

**Fonts:** Space Grotesk (display/headings) + IBM Plex Sans (UI/body). Never use WordPress default blue `#2271b1`. Corner radius: 4px on inputs/buttons, 6px on cards.

---

## 3. WordPress integration

### Admin menu structure

Top-level menu **Equine EM** with submenu items in this order:

1. Dashboard
2. Events (placeholder — events come from a connected feed/source defined in Settings)
3. Orders
4. Reservations
5. Stall Charts
6. Invoicing
7. Reports
8. Settings

Sidebar markup pattern is in every admin page — see `.wp-sidebar`, `.wp-sidebar-item`, `.wp-sidebar-sub`.

### Custom post type: Reservation

`edit_reservation_page.html` is the editor for a single Reservation. Use a CPT (`en_reservation`) with custom metaboxes replacing the default Gutenberg/Classic editor. Right rail in the mockup shows Publish, Event Link, Shortcode metaboxes — mirror this structure.

Fields exposed on the form (each maps to a `post_meta` key, prefix `_en_`):

**Reservation Description** (collapsible, always enabled)
- `_en_description` (textarea) — shown on the front-end Stay Details card.

**Available Reservation Dates** (collapsible, always enabled)
- `_en_date_start`, `_en_date_end` (date) — open reservation window.

**Check-In / Check-Out** (collapsible + enable toggle)
- `_en_checkin_enabled` (bool)
- `_en_checkin_time`, `_en_checkout_time` (datetime)

**Stall Reservations** (collapsible + enable toggle)
- `_en_stall_enabled` (bool)
- `_en_stall_description` (textarea)
- `_en_stall_stay_nightly` (bool, default true)
- `_en_stall_stay_weekend` (bool, default false) — at least one of nightly/weekend must be true
- `_en_stall_weekend_start`, `_en_stall_weekend_end` (date) — visible only when weekend is enabled
- `_en_stall_schedule_enabled` (bool)
- `_en_stall_open_at`, `_en_stall_close_at` (datetime) — visible only when schedule is enabled
- `_en_stall_inventory` (int, blank = unlimited)
- `_en_stall_nightly_rate` (decimal) — visible only when nightly is enabled
- `_en_stall_weekend_rate` (decimal) — visible only when weekend is enabled
- `_en_stall_eb_enabled` (bool)
- `_en_stall_eb_cutoff` (datetime), `_en_stall_eb_nightly_rate`, `_en_stall_eb_weekend_rate` (decimal) — visible only when EB enabled; nightly/weekend further gated by stay-type
- `_en_stall_shavings_required` (bool)
- `_en_stall_shavings_per_stall` (int), `_en_stall_shavings_price` (decimal) — visible only when required
- `_en_stall_assignments_enabled` (bool)
- `_en_stall_mode` ("quantity" | "map") — visible only when assignments enabled
- `_en_stall_blocks` (array of `{title, start, end}`) — visible only when assignments enabled
- `_en_stall_blocked_numbers` (array of int) — visible only when assignments enabled
- `_en_stall_map_file` (attachment ID) — visible only when assignments enabled

**RV Reservations** (collapsible + enable toggle) — same pattern as Stall but with RV-specific fields, plus:
- `_en_rv_lot_selection_enabled` (bool)
- `_en_rv_lots` (array of `{name, nightly, weekend, inventory}`) — visible when lot selection enabled
- `_en_rv_blocked_lots` (array of lot IDs) — visible when lot selection enabled
- `_en_rv_addons_enabled` (bool) — when off, hides the RV add-ons table
- `_en_rv_addons` (array of `{name, price}`)

**General Add-Ons** (collapsible + enable toggle)
- `_en_addons_enabled` (bool)
- `_en_addons` (array of `{name, price, description}`)

**Group Reservations** (collapsible + enable toggle)
- `_en_group_enabled` (bool)
- `_en_group_grounds_fee_enabled` (bool)
- `_en_group_grounds_fee_amount` (decimal) — visible only when enabled
- `_en_group_deposit_enabled` (bool)
- `_en_group_deposit_amount` (decimal) — visible only when enabled

**Venue Map** (collapsible + enable toggle)
- `_en_venue_enabled` (bool)
- `_en_venue_download_url` (url)

**Agreement** (collapsible + enable toggle)
- `_en_agreement_enabled` (bool)
- `_en_agreement_file` (attachment ID)
- `_en_agreement_label` (string, default "Agreement")

**Fees** (collapsible + enable toggle)
- `_en_fees_enabled` (bool)
- `_en_fee_label` (string)
- `_en_fee_type` ("none" | "flat" | "pct")
- `_en_fee_value` (decimal) — visible only when type is flat or pct

---

## 4. Conditional visibility rules

Every dependent row in `edit_reservation_page.html` carries `id="row-..."` and is gated by a parent toggle with `data-controls="..."`. JS function `applyControls()` reads `data-controls` and hides the listed IDs when the toggle is off. The full map:

| Controller | Hides when off |
|---|---|
| Stall: Nightly stay-type | `row-stall-rate-nightly`, `row-stall-eb-nightly` |
| Stall: Weekend stay-type | `row-stall-weekend-dates`, `row-stall-rate-weekend`, `row-stall-eb-weekend` |
| Stall: Schedule | `row-stall-open`, `row-stall-close` |
| Stall: Early Bird | `row-stall-eb-cutoff`, `row-stall-eb-nightly`, `row-stall-eb-weekend` |
| Stall: Required Shavings | `row-stall-shavings-qty`, `row-stall-shavings-price` |
| Stall: Stall Assignments | `row-stall-mode`, `row-stall-blocks`, `row-stall-blocked`, `row-stall-map` |
| RV: Nightly stay-type | `row-rv-rate-nightly`, `row-rv-eb-nightly` |
| RV: Weekend stay-type | `row-rv-weekend-dates`, `row-rv-rate-weekend`, `row-rv-eb-weekend` |
| RV: Schedule | `row-rv-open`, `row-rv-close` |
| RV: Early Bird | `row-rv-eb-cutoff`, `row-rv-eb-nightly`, `row-rv-eb-weekend` |
| RV: Lot Selection | `row-rv-lots`, `row-rv-blocked-lots` |
| RV: Enable add-ons | `rv-addons-table-wrap` |
| Group: Grounds Fee | `row-grounds-amt` |
| Group: Deposit | `row-deposit-amt` |
| Fees: type=none | hides `row-fee-value` entirely |
| Fees: type=flat | shows only `fee-val-flat` |
| Fees: type=pct | shows only `fee-val-pct` |

**Constraint:** within a stay-type group, at least one of Nightly/Weekend must stay enabled. JS function `toggleStay()` enforces this and shows a transient hint ("At least one stay type must remain enabled.") when blocked.

**Section enabled toggles** also collapse the section body when off. Function `toggleSectionEnabled()` handles both states.

---

## 5. Tag multi-select pattern

Blocked Stall Numbers and Blocked RV Lots use a tag-style multi-select (`.tag-select` in `edit_reservation_page.html`). Build behaviour:

- Click input area → dropdown opens, search input focuses
- Type → list filters by `data-value`
- Click an item → becomes a removable chip in the input; item gets `.selected` and a ✓ in the dropdown
- Click ✕ on a chip → removes it, un-selects the dropdown item
- Click outside → dropdown closes

For production, recommend wiring to **Select2** or **Choices.js** with prefilled `selected` values from `post_meta`. Markup structure already matches Choices.js conventions closely.

---

## 6. Save success toast

`settings_page.html` shows a top-right toast on save: `.toast` element auto-dismisses after ~3.2s, with brand teal left-border and a checkmark icon. Function `showSaveToast(msg)` creates one on demand. Apply the same pattern to:

- `edit_reservation_page.html` — Update Reservation button (currently no toast wired)
- `stall_chart_detail.html` — when saving chart assignments
- Any other admin page with a save action

The CSS for the toast is currently in `settings_page.html` only; extract to a shared `admin.css` when porting.

---

## 7. Front-end form (`event_page.html`)

Rendered via shortcode `[en_reservation id="42"]` where `id` is the `en_reservation` post ID. The form is a guided customer journey:

1. Stay Details — read from reservation description + dates
2. Customer info
3. Stall selection (if enabled) — quantity or map mode
4. RV selection (if enabled) — lot picker, dates, add-ons
5. Group rider roster (if enabled)
6. General add-ons (if enabled)
7. Special requests (textarea)
8. Agreement checkbox + linked PDF
9. Payment summary with itemized totals + fees
10. Stripe payment

Behaviour notes:
- Dates pre-filled from reservation's `_en_date_start`/`_en_date_end`; clamp customer date inputs to this range.
- If Early Bird cutoff hasn't passed, show EB rates instead of base rates and a small "Early Bird Pricing Applied" badge.
- Required Shavings is non-removable in the cart when enabled — qty determined by stall count.
- Tax-exempt jurisdictions: not currently modelled. Add if needed.

---

## 8. Orders

Order data model (`en_order` CPT or custom table):
- Customer (name, email, phone, billing address)
- Linked reservation (post ID)
- Line items array (section, description, qty, units, rate, total) — see `order_receipt.html` for the structure
- Payment status: `unpaid` | `partial` | `paid` | `refunded`
- Stripe payment intent ID
- Special requests
- Created/updated timestamps

`order_detail_page.html` shows a More ▾ dropdown with: Refund, Resend Confirmation, Edit Order, Delete Order. Implement as a small JS dropdown with confirm dialogs for destructive actions.

---

## 9. Stall charts

`stall_chart_detail.html` is the assignment screen: barns laid out as a grid, each stall draggable from an "unassigned" pool to a barn slot. The mockup is static; the real version needs drag-and-drop (SortableJS is a good fit).

`stall_chart_print_view.html` is a print-only template — no admin chrome, just the chart, intended to be opened in a new tab via the "Print View" teal button on the detail page.

---

## 10. Reports

`reports_page.html` has one form for selecting which reservation to export, an Export CSV button, and an Export History table. Backend:
- Server-side CSV generation streamed to download
- Log each export in a `wp_eem_export_log` table or post meta: timestamp, reservation, scope, file name, user ID
- File naming pattern: `equine-event-manager-report-{reservationId|all}-{YYYYMMDD}-{HHMMSS}.csv`

---

## 11. Settings (6 tabs)

1. **General** — business name, contact info, logo
2. **Events** — event feed URL (Test Feed URL button calls the URL and validates JSON), active source
3. **Payments** — Stripe keys, webhook secret, fee defaults
4. **Email** — sender name/address, confirmation email enable, optional BCC
5. **Notifications** — when to ping admin (new order, payment received, agreement signed, etc.)
6. **Advanced** — debug logging, cache flush, plugin export/import

Each tab has a `Save Settings` button at the bottom that fires `showSaveToast()` on success.

---

## 12. Dashboard

Six metric cards across the top:
- Total reservations
- Total orders
- Revenue (this month)
- Avg order value
- Open orders
- Needs attention count

The **Needs Attention** card lists 6 actionable items, each wrapped in an `<a>` linking to the relevant screen:
- Stall assignment issues → `stall_chart_detail.html`
- Orders awaiting payment → `orders_page.html`
- RV lot issues → `stall_chart_detail.html`
- Unsigned agreements → `orders_page.html`
- Unconfigured stall charts → `stall_chart_detail.html`
- Stripe webhook → `settings_page.html`

For production, these should query real data and link to filtered list views (e.g. orders awaiting payment → orders list with `status=unpaid` filter pre-applied).

---

## 13. Naming conventions

- **Plugin slug:** `equine-event-manager`
- **Text domain:** `equine-event-manager`
- **DB prefix:** `wp_eem_`
- **Post meta prefix:** `_en_`
- **CSS prefix:** `eem-` (rename current generic classes when porting)
- **JS namespace:** `window.EEM = {}` for any public helpers
- **Shortcode prefix:** `en_` (e.g. `[en_reservation]`)

---

## 14. Responsive breakpoints

Mockups use a consistent set:
- **Desktop:** `> 1024px`
- **Tablet:** `≤ 1024px` — WP sidebar collapses, edit-reservation rail narrows
- **Tablet small:** `≤ 960px`
- **Mobile:** `≤ 767px` — admin bar items hide, sidebar hides, form fields stack, sticky save bar appears on Edit Reservation
- **Small mobile:** `≤ 480px` / `≤ 360px` — used in email template for chip wrapping and table scroll

---

## 15. What's intentionally not in the mockups

- Tax calculation
- Multi-currency
- Discount codes
- Refund flow UI (it's just a More ▾ menu item with no dedicated screen)
- Audit log / activity feed
- Multi-tenant / multisite considerations

If any of these are in scope, they'll need new mockups before implementation.

---

## 16. Implementation checklist

When porting to a real plugin:

- [ ] Set up CPTs: `en_reservation`, `en_order`, `en_stall_chart`
- [ ] Build admin menu + page rendering shells using the markup in each `*_page.html`
- [ ] Port CSS to a shared `admin.css` (extract repeated tokens to CSS variables)
- [ ] Replace inline `onclick` handlers with delegated event listeners in `admin.js`
- [ ] Wire AJAX for save actions — return JSON, then call `showSaveToast()` on success
- [ ] Implement Stripe payment intent + webhook
- [ ] Implement email sending with `customer_confirmation_email.html` as the template (use `wp_mail` with `Content-Type: text/html`)
- [ ] Wire PDF generation for `order_receipt.html` using Dompdf
- [ ] Add nonces and capability checks on every form submission
- [ ] Localize all user-facing strings with `__()` and `_e()`
- [ ] Add an uninstall script that removes options + tables when the plugin is deleted (gated by a setting)
