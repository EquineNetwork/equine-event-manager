# Current State — EEM Codebase Inventory

**Generated 2026-06-15 at plugin version 2.7.322.**
**Updated 2026-06-15: all 10 business entities fully decoupled (postmeta Phase 1 complete).**

---

## 1. Entities fully decoupled from postmeta

All 10 business entities are now API-ready. Zero postmeta calls remain in
production code paths (fallback paths exist only for pre-migration compatibility
and will never fire after the corresponding backfill migration runs).

| Entity | Table(s) | Repository class | Decoupled at |
|---|---|---|---|
| **Reservation Config** | `wp_eem_reservation_config` | `EEM_Reservation_Config` | v2.7.168 |
| **Orders (Stall)** | `wp_en_stall_reservations` | `EEM_Orders_Repository` | Day 1 |
| **Orders (RV)** | `wp_en_rv_reservations` | `EEM_Orders_Repository` | Day 1 |
| **Division Entries (ledger)** | `wp_eem_division_entries` | `EEM_Entries` | v2.7.240 |
| **Division Config** | `wp_eem_division_config` | `EEM_Division_Config_Repo` | v2.7.321 |
| **Venues (identity)** | `wp_eem_venues` + `wp_eem_venue_source_map` | `EEM_Venue_Resolver` | v2.7.200 |
| **Venue Layouts** | `wp_eem_venue_layouts` | `EEM_Venue_Resolver` | v2.7.200 |
| **Venue Detail (native)** | `wp_eem_venues` (detail columns) | `EEM_Venue::get_detail()` | v2.7.320 |
| **Event Defaults** | `wp_eem_event_defaults` | direct queries | v2.7.280 |
| **Producers** | `wp_eem_producers` | `EEM_Producer_Repo` | v2.7.319 |
| **Native Events** | `wp_eem_native_events` | `EEM_Native_Event_Repo` | v2.7.322 |
| **Sheets & Results** | `wp_eem_sheet_entries` | direct queries | v2.7.258 |

### Remaining postmeta (by design — not candidates for decoupling)

| Category | Keys | Rationale |
|---|---|---|
| **Reservation linkage** | `_en_reservation_shortcode`, `_en_*_linked_*`, `_en_source_event_start_date` | WP-internal cross-post linkage used by `WP_Query` meta_query for admin list filtering. Not business data. |
| **Feed/external event cache** | `_en_external_event_*`, `_en_event_feed_url`, `_en_venue_*` on reservation posts | Denormalized copies of external event data snapshotted onto the reservation. The source of truth is the external API, not these keys. |
| **TEC overlay meta** | `_equine_event_manager_event_flyer_*`, `_equine_event_manager_event_featured` on `tribe_events` posts | Our data stored on TEC's posts — can't be moved to our tables without losing the TEC association. |

---

## 2. Complete custom table list (14 tables)

```
wp_en_stall_reservations      — stall order line items
wp_en_rv_reservations         — RV order line items
wp_eem_reservation_config     — reservation configuration (~100 scalar + 16 JSON cols)
wp_eem_division_entries       — division/class entrant ledger
wp_eem_division_config        — division price/spots/name config
wp_eem_event_defaults         — per-event default policies
wp_eem_sheet_entries          — draw sheets and results
wp_eem_venues                 — source-agnostic venue entities
wp_eem_venue_source_map       — venue cross-source identity mapping
wp_eem_venue_layouts          — saved facility layout templates
wp_eem_producers              — producer detail fields
wp_eem_native_events          — native event config (dates, venue, producer, social, etc.)
```

**12 custom tables total.** Full schema documented in `docs/schema.md`.

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

## 5. GEMS / Global Handicaps integration

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

## 6. Normalized event shape (cross-source)

The plugin normalizes events from all 3 sources into a common array shape via
`get_normalized_event_data()`. This is the **canonical contract** any Laravel API
events endpoint must reproduce:

```php
[
    'event_id'       => int,        // WP post ID (native/TEC) or external UID (GEMS)
    'source'         => string,     // 'native' | 'tec' | 'feed'
    'title'          => string,
    'content_raw'    => string,     // post body (empty for GEMS)
    'excerpt'        => string,
    'start_date'     => string,     // 'Y-m-d' or 'Y-m-d H:i:s'
    'end_date'       => string,
    'venue_name'     => string,
    'location'       => string,     // "City, State" display string
    'venue'          => array,      // { name, address_1, city, state, postal_code, lat, lng, ... }
    'producer'       => array,      // { name, phone, email, website, ... }
    'featured'       => bool,
    'featured_image' => string,     // URL
    'hero_image'     => string,     // URL (flyer > thumbnail fallback)
    'flyer_url'      => string,     // URL
    'reservation_id' => int,        // linked reservation (0 if none)
    'cta_label'      => string,     // native only; '' for TEC/GEMS
    'social'         => array,      // native only: { facebook, instagram }
    'categories'     => string[],   // taxonomy terms (native: en_event_category; TEC: tribe_events_cat)
    'tags'           => string[],   // taxonomy terms
]
```

**GEMS adds extra fields** not in the native/TEC shape: `external_event_id`,
`venue_address`, `venue_city`, `venue_state`, `venue_zip`, `event_type`,
`ref_id`, `logo`. These travel on the feed-event array but are NOT part of the
unified shape above — they're consumed by the reservation-editor event picker
and snapshotted onto the reservation as postmeta.

---

## 7. Stall/RV spatial data (JSON columns on wp_eem_reservation_config)

The reservation config table stores spatial layout data as JSON in 16 `longtext`
columns. These are the candidates for normalization into proper relational tables
in the Laravel schema:

| JSON column | Content | Normalization target |
|---|---|---|
| `stall_rows` | Array of stall row definitions (name, first, last, prefix, step) | → `stall_rows` table |
| `stall_map` | Grid geometry (cells with x/y/label/row assignment) | → `stall_cells` or `stall_map_cells` table |
| `stall_chart_stall_blocks` | Per-order stall assignments (order_key → stall units) | → columns on `stall_orders` or a `stall_assignments` table |
| `blocked_stalls` | Array of blocked stall identifiers | → `blocked_units` table or flag column |
| `stall_chart_blocked_stall_units` | Legacy blocked-stall format | Same as above |
| `rv_rows` | Array of RV row definitions | → `rv_rows` table |
| `rv_zones` | Array of RV zone configs | → `rv_zones` table |
| `rv_lots` | Array of RV lot definitions | → `rv_lots` table |
| `rv_map` | RV grid geometry | → `rv_map_cells` table |
| `stall_chart_rv_blocks` | Per-order RV assignments | → columns on `rv_orders` or `rv_assignments` table |
| `blocked_rv_lots` | Blocked RV lot identifiers | → flag column or `blocked_units` |
| `stall_chart_blocked_rv_units` | Legacy blocked-RV format | Same as above |
| `general_addons` | Add-on config objects (name, price, type) | → `addons` table |
| `rv_addons` | RV-specific add-on config objects | → same `addons` table with type column |
| `event_pre_entries` | Pre-entry item configs | → `pre_entry_items` table |
| `extra_json` | Catch-all for unmapped keys | Audit at migration time |

---

## 8. Tech stack summary

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

## 9. Codebase size

| Type | File count |
|---|---|
| PHP | 326 |
| JS | 11 |
| CSS | 2 main (`admin.css`, `admin-legacy.css`) + shortcode public CSS |
