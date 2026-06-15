# Postmeta Decouple — Remaining Entity Audit

**Purpose:** Inventory of every entity that still uses `wp_postmeta` for
business data, with decoupling status and effort estimate for the API layer.
Last updated 2026-06-15, plugin version **2.7.318**.

---

## Status key

| Status | Meaning |
|---|---|
| **DONE** | Fully relational — API-ready |
| **PARTIAL** | Has a relational table but some reads/writes still go through postmeta |
| **NOT STARTED** | All data in postmeta or wp_options |

---

## Entity inventory

### 1. Reservation Config — **DONE**

- **Table:** `wp_eem_reservation_config` (~100 scalar + 16 JSON columns)
- **Repository:** `EEM_Reservation_Config` — single access point, typed getters/setters
- **Postmeta calls remaining:** 1 (fallback path in `EEM_Reservation_Config::hydrate()` for
  unmigrated rows — will never fire after mig-016 backfill)
- **Postmeta rows:** Deleted by mig-017; only non-config linkage keys remain:
  `_en_reservation_shortcode`, `_en_reservation_linked_tec_event`,
  `_en_reservation_linked_native_event`, `_en_tec_event_linked_reservation`,
  `_en_native_event_linked_reservation`, `_en_source_event_start_date`
- **API readiness:** Ready. The repo IS the API data source.

### 2. Orders (Stall + RV) — **DONE**

- **Tables:** `wp_en_stall_reservations`, `wp_en_rv_reservations`
- **Repository:** `EEM_Orders_Repository` — all CRUD goes through custom tables
- **Postmeta calls:** 0 (orders never used postmeta)
- **API readiness:** Ready. Already fully relational since day one.

### 3. Division Entries — **DONE**

- **Table:** `wp_eem_division_entries`
- **Repository:** `EEM_Entries` (entrant ledger methods)
- **Postmeta calls:** 0 for the entrant ledger (the relational table)
- **Note:** The Division CPT (`en_entry`) itself still uses postmeta for its
  config (price, spots cap, status) — see #6 below
- **API readiness:** Ledger data is ready. Division config needs decoupling.

### 4. Venues — **DONE**

- **Tables:** `wp_eem_venues`, `wp_eem_venue_source_map`, `wp_eem_venue_layouts`
- **Repository:** `EEM_Venue_Resolver`
- **Postmeta calls:** 14 in venue editor page (CPT presentation meta: address
  fields, geocoding lat/lng, social links) — these are the `en_venue` CPT's own
  meta, not business data the API needs to expose
- **API readiness:** Ready for the venue entity itself. The CPT editor meta is
  WordPress-admin-only presentation data.

### 5. Event Defaults — **DONE**

- **Table:** `wp_eem_event_defaults`
- **Repository:** used via direct queries in cancellation-policy code
- **Postmeta calls:** 0
- **API readiness:** Ready.

### 6. Divisions / Classes (en_entry CPT config) — **NOT STARTED**

- **Current storage:** `wp_postmeta` on `en_entry` posts
- **Keys:** `_en_entry_price`, `_en_entry_spots_cap`, `_en_entry_status`,
  `_en_entry_event_id`, `_en_entry_event_source`, plus ~10 more
- **Call count:** ~26 `get/update_post_meta` calls in `class-eem-entries.php`
- **Effort:** Small (~0.5 week). Clean CPT with few keys. Mirror the
  `EEM_Reservation_Config` pattern: flat table, repo class, backfill migration.
- **API priority:** Medium. Divisions are a v2 feature; the entrant ledger
  (already relational) is the high-value query surface.

### 7. Native Events (en_event CPT) — **NOT STARTED**

- **Current storage:** `wp_postmeta` on `en_event` posts
- **Keys:** `_equine_event_manager_event_start_date`, `_equine_event_manager_event_end_date`,
  `_equine_event_manager_event_venue_id`, `_equine_event_manager_event_producer_id`,
  `_equine_event_manager_event_flyer_file_id`, `_equine_event_manager_event_location_label`,
  `_equine_event_manager_event_cta_label`, `_equine_event_manager_event_featured`,
  `_en_event_facebook`, `_en_event_instagram`, plus Elementor integration keys
- **Call count:** ~140 `get/update_post_meta` calls in `class-equine-event-manager-events.php`
  + 26 in `class-eem-event-editor-page.php`
- **Effort:** Medium (~1–1.5 weeks). Many keys but mostly scalar. The v2 Native
  Events subsystem is the largest remaining postmeta consumer.
- **Complication:** Events are source-polymorphic (TEC / GEMS / Native). TEC and
  GEMS events don't use postmeta at all — they're fetched from external
  APIs/feeds. Only Native Events use postmeta. The API layer needs to present a
  unified event interface regardless of source.
- **API priority:** High for the API layer — events are the root entity
  everything else hangs off. But the decoupling only affects the Native Events
  source; TEC/GEMS are already external.

### 8. Native Venues (en_venue CPT) — **PARTIAL**

- **Current storage:** `wp_postmeta` for address, geocoding, social links
- **Relational:** `wp_eem_venues` + `wp_eem_venue_source_map` hold the
  source-agnostic identity and cross-source mapping
- **Call count:** 14 in venue editor page
- **Keys:** `_equine_event_manager_venue_address`, `_equine_event_manager_venue_city`,
  `_equine_event_manager_venue_state`, `_equine_event_manager_venue_zip`,
  `_equine_event_manager_venue_country`, `_en_venue_lat`, `_en_venue_lng`,
  `_en_venue_geocoded_address`
- **Effort:** Small (~0.5 week). Few keys, mostly strings. Add columns to
  `wp_eem_venues` or a sibling detail table.
- **API priority:** Medium. Venue identity is already relational; the address/geo
  detail would enrich the API response but isn't blocking.

### 9. Native Producers (en_producer CPT) — **DONE**

- **Table:** `wp_eem_producers`
- **Repository:** `EEM_Producer_Repo` — static `get()`, `get_field()`, `save()`
- **Postmeta calls:** 0 (fallback path in repo only fires pre-migration)
- **Migrations:** mig-018 (backfill from postmeta), mig-019 (drop postmeta rows)
- **API readiness:** Ready. Decoupled at v2.7.319.

### 10. Sheets & Results — **DONE**

- **Table:** `wp_eem_sheet_entries`
- **Postmeta calls:** 0
- **API readiness:** Ready.

### 11. Settings — **NOT APPLICABLE**

- **Current storage:** `wp_options` via `EEM_Settings_Repo`
- **Note:** Settings are site-wide config, not per-entity data. They don't need
  the same decoupling treatment — `wp_options` is the correct storage for
  site-scoped settings. The API layer reads them via the existing repo.
- **API readiness:** Ready (read-only via `EEM_Settings_Repo`).

### 12. Reservation linkage keys — **STAYS IN POSTMETA**

- **Keys:** `_en_reservation_shortcode`, `_en_reservation_linked_tec_event`,
  `_en_reservation_linked_native_event`, `_en_tec_event_linked_reservation`,
  `_en_native_event_linked_reservation`, `_en_source_event_start_date`
- **Rationale:** These are WP-internal cross-post linkage keys used by
  `meta_query` in `WP_Query` for admin list filtering. They don't carry business
  data the API would expose. Moving them to a relational table would require
  replacing every `WP_Query` `meta_query` that references them — high cost, low
  value. They stay in postmeta as WordPress plumbing.

---

## Summary table

| # | Entity | Status | Postmeta calls | Effort | API priority |
|---|---|---|---|---|---|
| 1 | Reservation Config | **DONE** | 1 (fallback) | — | Ready |
| 2 | Orders | **DONE** | 0 | — | Ready |
| 3 | Division Entries (ledger) | **DONE** | 0 | — | Ready |
| 4 | Venues (identity) | **DONE** | 0 | — | Ready |
| 5 | Event Defaults | **DONE** | 0 | — | Ready |
| 6 | Division Config | NOT STARTED | 26 | 0.5 wk | Medium |
| 7 | Native Events | NOT STARTED | 166 | 1–1.5 wk | High |
| 8 | Native Venues (detail) | PARTIAL | 14 | 0.5 wk | Medium |
| 9 | Producers | **DONE** | 0 | — | Ready |
| 10 | Sheets & Results | **DONE** | 0 | — | Ready |
| 11 | Settings | N/A | 0 | — | Ready |

**Bottom line:** 7 of 10 business entities are fully decoupled and API-ready
today. The remaining 3 (Division Config, Native Events, Native Venue detail)
total ~2–2.5 weeks of work, but **none of them block the initial API layer** —
the API can launch exposing the 7 ready entities and add the remaining 3
incrementally as they're decoupled.
