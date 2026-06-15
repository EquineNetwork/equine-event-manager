# Current State — EEM Codebase Inventory

**Generated 2026-06-15 at plugin version 2.7.318.**

---

## 1. Entities fully decoupled from postmeta

| Entity | Table(s) | Repository class | Postmeta calls |
|---|---|---|---|
| **Reservation Config** | `wp_eem_reservation_config` | `EEM_Reservation_Config` | 1 (dead-code fallback) |
| **Orders (Stall)** | `wp_en_stall_reservations` | `EEM_Orders_Repository` | 0 |
| **Orders (RV)** | `wp_en_rv_reservations` | `EEM_Orders_Repository` | 0 |
| **Division Entries (ledger)** | `wp_eem_division_entries` | `EEM_Entries` | 0 |
| **Venues (identity)** | `wp_eem_venues` + `wp_eem_venue_source_map` | `EEM_Venue_Resolver` | 0 |
| **Venue Layouts** | `wp_eem_venue_layouts` | `EEM_Venue_Resolver` | 0 |
| **Event Defaults** | `wp_eem_event_defaults` | direct queries | 0 |
| **Sheets & Results** | `wp_eem_sheet_entries` | direct queries | 0 |

**All 6 core business entities are API-ready today.**

---

## 2. Entities still using postmeta

| Entity | CPT slug | Postmeta calls | Key files | Significance |
|---|---|---|---|---|
| **Native Events** | `en_event` | ~166 | `class-equine-event-manager-events.php`, `class-eem-event-editor-page.php` | Largest remaining consumer. All event config (dates, venue link, producer link, flyer, featured flag, social URLs). Only affects Native Events source — TEC and GEMS are external APIs. |
| **Divisions (config)** | `en_entry` | ~26 | `class-eem-entries.php` | Division price, spots cap, status, event linkage. The entrant ledger is already relational. |
| **Native Venues** | `en_venue` | ~14 | `class-eem-venue-editor-page.php`, `class-equine-event-manager-events.php` | Address fields, geocoding lat/lng. Venue identity is already relational in `wp_eem_venues`. |
| **Producers** | `en_producer` | ~8 | `class-eem-producer-editor-page.php` | Website, email, phone, social links, logo/banner. Small surface. |
| **Reservation linkage** | `en_reservation` | ~40 | `class-equine-event-manager-reservations-cpt.php` | Non-config keys (`_en_reservation_shortcode`, `_en_*_linked_*`, `_en_source_event_start_date`). These are WP-internal cross-post linkage used by `WP_Query` meta_query for admin list filtering. Not business data — stays in postmeta as WordPress plumbing. |

---

## 3. Existing API endpoints

**One endpoint exists:**

```
POST /wp-json/eem/v1/stripe-webhook
```

Registered in `class-equine-event-manager-shortcodes.php:7083`. Handles Stripe
webhook events (payment confirmation, refund notifications). No authentication
beyond Stripe's webhook signature verification.

**No other REST API endpoints exist.** All admin operations use WordPress AJAX
(`wp_ajax_*` actions). All customer-facing operations use form POST to the
shortcode handler.

---

## 4. Authentication systems

**WordPress user auth only.** No API key system, no JWT, no OAuth, no application
passwords configured. The Stripe webhook endpoint validates via Stripe's own
webhook secret signature — not WordPress auth.

GEMS integration uses the external GEMS API's JWT token (`gems_key`) stored in
`wp_options`, but this is an outbound credential (EEM calling GEMS), not an
inbound auth system.

---

## 5. Complete custom table list (MySQL)

```
wp_en_stall_reservations      — stall order line items
wp_en_rv_reservations         — RV order line items
wp_eem_reservation_config     — reservation configuration (decoupled from postmeta)
wp_eem_division_entries       — division/class entrant ledger
wp_eem_event_defaults         — per-event default policies
wp_eem_sheet_entries          — draw sheets and results
wp_eem_venues                 — source-agnostic venue entities
wp_eem_venue_source_map       — venue cross-source identity mapping
wp_eem_venue_layouts          — saved facility layout templates
```

**9 custom tables total.** Full schema documented in `docs/schema.md`.

---

## 6. GEMS / Global Handicaps integration

**Yes — a working integration exists.** Built in GEMS Slices 1–4 (v2.7.168),
shipped and live.

**File:** `includes/class-eem-gems-client.php` (~230 LOC)

**What it does today:**

- Fetches the event schedule from `GET /api/Schedule/{assnId}` using JWT Bearer auth
- Normalizes GEMS event records into the plugin's canonical feed-event shape
- Caches results for 15 minutes via WP transients
- Settings UI in Settings → Integrations allows configuring `feed_gems_base_url`,
  `feed_gems_token` (JWT), and `feed_gems_assn` (Association ID)
- Falls back to the standalone GEMS WordPress plugin's `gems_key` / `gems_assn`
  wp_options if EEM's own settings are empty
- Builds flyer image URLs from `{FLYER_IMAGE_BASE}/{refId}.jpg`
- Used by the reservation editor's event picker and linked-event display surfaces
  when event source = "gems"

**Base URL hardcoded as constant:**
```php
const DEFAULT_BASE_URL = 'https://webdataapi-ehbahmadepazg8e3.centralus-01.azurewebsites.net';
const FLYER_IMAGE_BASE = 'https://www.globalhandicaps.com/images/schedule';
```

**Endpoints currently called:**
- `GET /api/Schedule/{assnId}` — full event schedule (LIVE, WORKING)

**Endpoints NOT yet called but known from the GEMS API:**
- `GET /api/ResultsEvent/{assnId}` — event results list
- `GET /api/EventResults/{eventUID}` — detailed results for a specific event

**Credential storage:** Per-site in `wp_options` under
`equine_event_manager_integration_settings` (keys: `feed_gems_base_url`,
`feed_gems_token`, `feed_gems_assn`). There is NO per-tenant/per-producer
credential storage — the current architecture is single-tenant (one WordPress
install = one association).

---

## 7. Tech stack summary

| Layer | Technology |
|---|---|
| **Language** | PHP 7.4+ (plugin targets 7.4; dev environment runs 8.2.29) |
| **Framework** | WordPress 6.0+ (plugin, not theme) |
| **Frontend** | Vanilla JavaScript (no framework), CSS custom properties |
| **Dependencies (composer)** | `dompdf/dompdf` (PDF generation), `pelago/emogrifier` (email CSS inlining) |
| **Dev dependencies** | PHP_CodeSniffer + WPCS |
| **Payment processors** | Stripe (API + webhooks), Authorize.net (Accept.js) |
| **External API** | GEMS Web Data API (Azure-hosted, JWT auth) |
| **Source control** | GitHub (`EquineNetwork/equine-event-manager`, private) |
| **Hosting** | WordPress on Local (dev); production hosting not visible from codebase |
| **No Node.js / npm / .NET / Python / Ruby** in the project |

---

## 8. Codebase size

| Type | File count |
|---|---|
| PHP | 326 |
| JS | 11 |
| CSS | 2 main (`admin.css`, `admin-legacy.css`) + shortcode public CSS |
