# Database Schema — Equine Event Manager

**Source of truth for the API layer.** Every custom table the plugin owns, with
columns, types, indexes, and foreign-key relationships. Last updated 2026-06-15
at plugin version **2.7.318**.

WP-native tables (`wp_posts`, `wp_postmeta`, `wp_options`, `wp_terms`,
`wp_term_relationships`) are used by CPTs and settings but are **not documented
here** — they follow the standard WordPress schema. Only plugin-owned tables
appear below.

---

## 1. `wp_en_stall_reservations`

**Purpose:** One row per stall order line item (a customer's stall purchase for a
specific event). The primary order/transaction table for the stall side.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `event_source` | `varchar(100)` | NO | `''` | `tec` / `gems` / `native` |
| `event_id` | `bigint unsigned` | YES | NULL | WP post ID (TEC/native) |
| `external_event_id` | `varchar(191)` | NO | `''` | GEMS event ID |
| `customer_name` | `varchar(191)` | NO | `''` | |
| `email` | `varchar(191)` | NO | `''` | |
| `phone` | `varchar(50)` | NO | `''` | |
| `stall_qty` | `int unsigned` | NO | `0` | |
| `tack_stall_qty` | `int unsigned` | NO | `0` | |
| `stay_type` | `varchar(100)` | NO | `''` | nightly / weekend / weekly |
| `arrival_date` | `date` | YES | NULL | |
| `departure_date` | `date` | YES | NULL | |
| `required_shavings_qty` | `int unsigned` | NO | `0` | |
| `additional_shavings_qty` | `int unsigned` | NO | `0` | |
| `unit_price` | `decimal(10,2)` | NO | `0.00` | |
| `subtotal` | `decimal(10,2)` | NO | `0.00` | |
| `convenience_fee` | `decimal(10,2)` | NO | `0.00` | |
| `total` | `decimal(10,2)` | NO | `0.00` | |
| `tax` | `decimal(10,2)` | NO | `0.00` | |
| `tax_rate` | `decimal(6,3)` | NO | `0.000` | |
| `payment_status` | `varchar(50)` | NO | `pending` | pending / completed / refunded / cancelled |
| `payment_gateway` | `varchar(50)` | NO | `''` | stripe / authnet |
| `order_number` | `varchar(20)` | NO | `''` | 5-digit zero-padded display number |
| `transaction_id` | `varchar(191)` | NO | `''` | Stripe/Auth.net txn ID |
| `refund_transaction_id` | `varchar(191)` | NO | `''` | |
| `refunded_at` | `datetime` | YES | NULL | |
| `refunded_amount` | `decimal(10,2)` | NO | `0.00` | |
| `notes` | `text` | YES | NULL | JSON: group name, add-ons, stall assignments |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |
| `reservation_id` | `bigint unsigned` | NO | `0` | FK → `wp_posts.ID` (en_reservation CPT) |
| `trashed_at` | `datetime` | YES | NULL | Soft-delete timestamp |

**Indexes:** `PRIMARY(id)`, `event_id`, `external_event_id`, `payment_status`,
`order_number`, `created_at`, `reservation_id`.

---

## 2. `wp_en_rv_reservations`

**Purpose:** One row per RV order line item. Mirrors stall table structure.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `event_source` | `varchar(100)` | NO | `''` | |
| `event_id` | `bigint unsigned` | YES | NULL | |
| `external_event_id` | `varchar(191)` | NO | `''` | |
| `customer_name` | `varchar(191)` | NO | `''` | |
| `email` | `varchar(191)` | NO | `''` | |
| `phone` | `varchar(50)` | NO | `''` | |
| `rv_qty` | `int unsigned` | NO | `0` | |
| `rv_type` | `varchar(100)` | NO | `''` | |
| `stay_type` | `varchar(100)` | NO | `''` | |
| `arrival_date` | `date` | YES | NULL | |
| `departure_date` | `date` | YES | NULL | |
| `unit_price` | `decimal(10,2)` | NO | `0.00` | |
| `subtotal` | `decimal(10,2)` | NO | `0.00` | |
| `convenience_fee` | `decimal(10,2)` | NO | `0.00` | |
| `total` | `decimal(10,2)` | NO | `0.00` | |
| `tax` | `decimal(10,2)` | NO | `0.00` | |
| `tax_rate` | `decimal(6,3)` | NO | `0.000` | |
| `payment_status` | `varchar(50)` | NO | `pending` | |
| `payment_gateway` | `varchar(50)` | NO | `''` | |
| `order_number` | `varchar(20)` | NO | `''` | |
| `transaction_id` | `varchar(191)` | NO | `''` | |
| `refund_transaction_id` | `varchar(191)` | NO | `''` | |
| `refunded_at` | `datetime` | YES | NULL | |
| `refunded_amount` | `decimal(10,2)` | NO | `0.00` | |
| `notes` | `text` | YES | NULL | |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |
| `reservation_id` | `bigint unsigned` | NO | `0` | FK → `wp_posts.ID` |
| `trashed_at` | `datetime` | YES | NULL | |

**Indexes:** same as stall table.

---

## 3. `wp_eem_reservation_config`

**Purpose:** One row per reservation — the full configuration (pricing, dates,
stall/RV setup, policies, add-ons). Replaced `wp_postmeta` for all `_en_*` and
`_eem_section_enabled_*` config keys as of 2.7.318.

**~100 scalar columns + 16 JSON columns + `extra_json` catch-all.** Full column
listing:

### Scalar columns

| Column | Type | Default | Notes |
|---|---|---|---|
| `reservation_id` | `bigint unsigned` | — | PK, FK → `wp_posts.ID` |
| `use_global_event_source` | `tinyint(1)` | `0` | |
| `event_source` | `varchar(32)` | NULL | tec / gems / native |
| `event_id` | `varchar(191)` | NULL | |
| `event_feed_url` | `varchar(2048)` | NULL | |
| `external_event_name` | `varchar(255)` | NULL | |
| `external_event_id` | `varchar(191)` | NULL | |
| `stalls_enabled` | `tinyint(1)` | `0` | |
| `rv_enabled` | `tinyint(1)` | `0` | |
| `stall_selection_mode` | `varchar(32)` | NULL | quantity / pick_from_layout |
| `stall_inventory_type` | `varchar(32)` | NULL | bulk / mapped |
| `stall_customer_selection` | `varchar(32)` | NULL | |
| `rv_selection_mode` | `varchar(32)` | NULL | |
| `rv_inventory_type` | `varchar(32)` | NULL | |
| `rv_customer_selection` | `varchar(32)` | NULL | |
| `nightly_enabled` | `tinyint(1)` | `0` | |
| `weekend_enabled` | `tinyint(1)` | `0` | |
| `weekly_enabled` | `tinyint(1)` | `0` | |
| `stall_nightly_enabled` | `tinyint(1)` | `0` | |
| `stall_weekend_enabled` | `tinyint(1)` | `0` | |
| `stall_weekly_enabled` | `tinyint(1)` | `0` | |
| `rv_nightly_enabled` | `tinyint(1)` | `0` | |
| `rv_weekend_enabled` | `tinyint(1)` | `0` | |
| `rv_weekly_enabled` | `tinyint(1)` | `0` | |
| `available_start_date` | `varchar(20)` | NULL | |
| `available_end_date` | `varchar(20)` | NULL | |
| `weekend_package_start_date` | `varchar(20)` | NULL | |
| `weekend_package_end_date` | `varchar(20)` | NULL | |
| `stall_weekend_package_start_date` | `varchar(20)` | NULL | |
| `stall_weekend_package_end_date` | `varchar(20)` | NULL | |
| `rv_weekend_package_start_date` | `varchar(20)` | NULL | |
| `rv_weekend_package_end_date` | `varchar(20)` | NULL | |
| `stall_weekly_package_start_date` | `varchar(20)` | NULL | |
| `stall_weekly_package_end_date` | `varchar(20)` | NULL | |
| `rv_weekly_package_start_date` | `varchar(20)` | NULL | |
| `rv_weekly_package_end_date` | `varchar(20)` | NULL | |
| `available_dates_manually_edited` | `tinyint(1)` | `0` | |
| `sync_stay_selections` | `tinyint(1)` | `0` | |
| `stall_description` | `text` | NULL | |
| `stall_schedule_enabled` | `tinyint(1)` | `0` | |
| `stalls_open_at` | `varchar(10)` | NULL | |
| `stalls_close_at` | `varchar(10)` | NULL | |
| `stall_inventory` | `varchar(20)` | NULL | |
| `rv_description` | `text` | NULL | |
| `rv_schedule_enabled` | `tinyint(1)` | `0` | |
| `rv_open_at` | `varchar(10)` | NULL | |
| `rv_close_at` | `varchar(10)` | NULL | |
| `rv_inventory` | `varchar(20)` | NULL | |
| `stall_nightly_rate` | `decimal(10,2)` | `0.00` | |
| `stall_weekend_rate` | `decimal(10,2)` | `0.00` | |
| `stall_weekly_rate` | `decimal(10,2)` | `0.00` | |
| `stall_early_bird_enabled` | `tinyint(1)` | `0` | |
| `stall_early_bird_cutoff` | `varchar(20)` | NULL | |
| `stall_early_bird_nightly_rate` | `decimal(10,2)` | `0.00` | |
| `stall_early_bird_weekend_rate` | `decimal(10,2)` | `0.00` | |
| `stall_early_bird_weekly_rate` | `decimal(10,2)` | `0.00` | |
| `rv_nightly_rate` | `decimal(10,2)` | `0.00` | |
| `rv_weekend_rate` | `decimal(10,2)` | `0.00` | |
| `rv_weekly_rate` | `decimal(10,2)` | `0.00` | |
| `rv_early_bird_enabled` | `tinyint(1)` | `0` | |
| `rv_early_bird_cutoff` | `varchar(20)` | NULL | |
| `rv_early_bird_nightly_rate` | `decimal(10,2)` | `0.00` | |
| `rv_early_bird_weekend_rate` | `decimal(10,2)` | `0.00` | |
| `rv_early_bird_weekly_rate` | `decimal(10,2)` | `0.00` | |
| `convenience_fee_label` | `varchar(255)` | NULL | |
| `convenience_fee_enabled` | `tinyint(1)` | `0` | |
| `convenience_fee_type` | `varchar(32)` | NULL | dollar / percent |
| `convenience_fee_value` | `decimal(10,2)` | `0.00` | |
| `required_shavings_enabled` | `tinyint(1)` | `0` | |
| `required_shavings_per_stall` | `int` | `0` | |
| `required_shavings_price` | `decimal(10,2)` | `0.00` | |
| `additional_shavings_enabled` | `tinyint(1)` | `0` | |
| `additional_shavings_description` | `varchar(255)` | NULL | |
| `additional_shavings_price` | `decimal(10,2)` | `0.00` | |
| `reservation_description` | `text` | NULL | |
| `event_details_summary` | `text` | NULL | |
| `venue_name` | `varchar(255)` | NULL | |
| `event_location` | `varchar(255)` | NULL | |
| `venue_address` | `text` | NULL | |
| `checkin_checkout_enabled` | `tinyint(1)` | `0` | |
| `checkin_time_enabled` | `tinyint(1)` | `0` | |
| `checkout_time_enabled` | `tinyint(1)` | `0` | |
| `checkin_time` | `varchar(10)` | NULL | |
| `checkout_time` | `varchar(10)` | NULL | |
| `venue_map_enabled` | `tinyint(1)` | `0` | |
| `venue_map_download_url` | `varchar(2048)` | NULL | |
| `venue_map_image_id` | `bigint` | `0` | FK → `wp_posts.ID` (attachment) |
| `venue_map_caption` | `varchar(255)` | NULL | |
| `venue_agreement_enabled` | `tinyint(1)` | `0` | |
| `venue_agreement_file_id` | `bigint` | `0` | FK → `wp_posts.ID` (attachment) |
| `venue_agreement_file_label` | `varchar(255)` | NULL | |
| `venue_agreement_label` | `text` | NULL | |
| `venue_agreement_link_label` | `varchar(255)` | NULL | |
| `venue_agreement_text` | `longtext` | NULL | |
| `general_addons_enabled` | `tinyint(1)` | `0` | |
| `group_reservations_enabled` | `tinyint(1)` | `0` | |
| `group_description` | `text` | NULL | |
| `group_riders_per_group` | `varchar(10)` | NULL | |
| `group_rider_grounds_fee_enabled` | `tinyint(1)` | `0` | |
| `group_rider_grounds_fee_amount` | `decimal(10,2)` | `0.00` | |
| `group_rider_deposit_enabled` | `tinyint(1)` | `0` | |
| `group_rider_deposit_amount` | `decimal(10,2)` | `0.00` | |
| `event_day_enabled` | `tinyint(1)` | `0` | |
| `event_day_checkin` | `text` | NULL | |
| `event_day_bring` | `text` | NULL | |
| `event_day_parking` | `text` | NULL | |
| `event_day_contact` | `text` | NULL | |
| `cancellation_enabled` | `tinyint(1)` | `0` | |
| `cancellation_policy_override` | `longtext` | NULL | |
| `stall_tack_mode` | `varchar(32)` | NULL | off / admin / customer |
| `stall_max_per_customer` | `varchar(10)` | NULL | |
| `rv_max_per_customer` | `varchar(10)` | NULL | |
| `stall_map_file_id` | `bigint` | `0` | |
| `rv_lot_selection_enabled` | `tinyint(1)` | `0` | |
| `rv_addons_enabled` | `tinyint(1)` | `0` | |
| `stall_map_id` | `bigint` | `0` | |
| `rv_lot_map_id` | `bigint` | `0` | |
| `event_pre_entries_enabled` | `tinyint(1)` | `0` | |
| `updated_at` | `datetime` | `CURRENT_TIMESTAMP` | |

### JSON columns (stored as `longtext`, decoded on read)

| Column | Content shape |
|---|---|
| `stall_chart_stall_blocks` | Array of stall-block assignment objects |
| `stall_chart_rv_blocks` | Array of RV-block assignment objects |
| `stall_chart_blocked_stall_units` | Array of blocked stall unit strings |
| `stall_chart_blocked_rv_units` | Array of blocked RV unit strings |
| `rv_lots` | Array of RV lot definition objects |
| `general_addons` | Array of add-on config objects |
| `rv_lot_zones` | Array of RV zone config objects |
| `rv_addons` | Array of RV add-on config objects |
| `stall_map` | Stall map geometry (grid cells + labels) |
| `rv_map` | RV map geometry |
| `event_pre_entries` | Array of pre-entry item configs |
| `stall_rows` | Array of stall row definitions |
| `blocked_stalls` | Array of blocked stall identifiers |
| `rv_zones` | Array of RV zone definitions |
| `rv_rows` | Array of RV row definitions |
| `blocked_rv_lots` | Array of blocked RV lot identifiers |
| `extra_json` | Catch-all for keys not in the manifest |

**Indexes:** `PRIMARY(reservation_id)`, `event_lookup(event_source, event_id)`,
`stalls_enabled`, `rv_enabled`.

**FK relationships:** `reservation_id` → `wp_posts.ID` (CPT `en_reservation`).

---

## 4. `wp_eem_division_entries`

**Purpose:** Entrant ledger for divisions/classes (one row per entrant per
division per order).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `division_id` | `bigint unsigned` | NO | — | FK → `wp_posts.ID` (CPT `en_entry`) |
| `order_key` | `varchar(64)` | NO | `''` | Links to stall/RV order `order_number` |
| `customer_name` | `varchar(191)` | NO | `''` | |
| `email` | `varchar(191)` | NO | `''` | |
| `qty` | `int` | NO | `1` | |
| `status` | `varchar(20)` | NO | `unpaid` | unpaid / paid / cancelled |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY(id)`, `division_id`, `order_key`.

---

## 5. `wp_eem_event_defaults`

**Purpose:** Per-event default policies and venue-map overrides. Keyed by
`(event_id, event_source)` composite — works across all three event sources.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `event_id` | `varchar(191)` | NO | — | PK (composite) |
| `event_source` | `varchar(32)` | NO | `native` | PK (composite) |
| `cancellation_policy` | `longtext` | YES | NULL | |
| `venue_map_image_id` | `bigint unsigned` | NO | `0` | |
| `venue_map_download_url` | `varchar(2048)` | YES | NULL | |
| `venue_map_caption` | `varchar(255)` | YES | NULL | |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY(event_id, event_source)`, `event_source`.

---

## 6. `wp_eem_producers`

**Purpose:** Producer (event organizer) detail fields. One row per `en_producer`
CPT post. Decoupled from postmeta at v2.7.319.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `producer_id` | `bigint unsigned` | NO | — | PK, matches `wp_posts.ID` |
| `contact_name` | `varchar(191)` | NO | `''` | |
| `email` | `varchar(191)` | NO | `''` | |
| `phone` | `varchar(50)` | NO | `''` | |
| `website` | `varchar(500)` | NO | `''` | |
| `imported_tec_organizer_id` | `bigint unsigned` | NO | `0` | TEC organizer post ID (0 if not imported) |

**Indexes:** `PRIMARY(producer_id)`.

**Repository:** `EEM_Producer_Repo` — static methods `get()`, `get_field()`, `save()`.

---

## 7. `wp_eem_sheet_entries`

**Purpose:** Draw sheets and results for Sheets & Results feature. One row per
sheet/result entry per event.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `event_id` | `bigint unsigned` | NO | — | FK → event post ID |
| `discipline_id` | `bigint unsigned` | NO | `0` | FK → `en_discipline` term |
| `label` | `varchar(191)` | NO | `''` | |
| `round` | `varchar(40)` | NO | `''` | |
| `entry_date` | `date` | YES | NULL | |
| `drawsheet_pdf` | `bigint unsigned` | NO | `0` | attachment ID |
| `result_pdf` | `bigint unsigned` | NO | `0` | attachment ID |
| `sort_order` | `int` | NO | `0` | |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY(id)`, `event_id`, `discipline_id`.

---

## 8. `wp_eem_venues`

**Purpose:** Source-agnostic venue entity. Normalized across TEC, GEMS, and
Native Events sources.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `name` | `varchar(191)` | NO | `''` | |
| `normalized_key` | `varchar(191)` | NO | `''` | Lowercased deduplication key |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY(id)`, `normalized_key`.

---

## 9. `wp_eem_venue_source_map`

**Purpose:** Maps source-specific venue identifiers to the canonical
`wp_eem_venues` row.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `venue_id` | `bigint unsigned` | NO | — | FK → `wp_eem_venues.id` |
| `source` | `varchar(32)` | NO | `''` | tec / gems / native |
| `source_venue_id` | `varchar(191)` | NO | `''` | |
| `source_venue_name` | `varchar(191)` | NO | `''` | |

**Indexes:** `PRIMARY(id)`, `venue_id`, `source_lookup(source, source_venue_id)`.

---

## 10. `wp_eem_venue_layouts`

**Purpose:** Saved facility layouts (stall/RV grid configs) per venue. Reusable
templates for recurring events.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `venue_id` | `bigint unsigned` | NO | — | FK → `wp_eem_venues.id` |
| `name` | `varchar(191)` | NO | `''` | |
| `layout_json` | `longtext` | YES | NULL | Full stall/RV grid config |
| `based_on_id` | `bigint unsigned` | NO | `0` | Lineage: layout this was cloned from |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY(id)`, `venue_id`.

---

## CPTs (WordPress `wp_posts` + `wp_postmeta`)

These entities use the WordPress CPT system. Listed for completeness:

| CPT slug | Purpose | Postmeta status |
|---|---|---|
| `en_reservation` | Reservation events | **DECOUPLED** — config in `wp_eem_reservation_config`; only linkage keys remain in postmeta |
| `en_entry` | Divisions/classes | Uses postmeta (26 calls); entrant ledger in `wp_eem_division_entries` |
| `en_event` | Native Events | Uses postmeta (140+ calls across events class + editor) |
| `en_venue` | Native Venues | Uses postmeta (14 calls in editor); canonical venue in `wp_eem_venues` |
| `en_producer` | Native Producers | Uses postmeta (8 calls in editor) |

## Settings (WordPress `wp_options`)

Plugin settings use `wp_options` via `EEM_Settings_Repo`:

| Option key pattern | Content |
|---|---|
| `eem_email_sender_*` | Email sender config |
| `eem_tax_*` | Tax settings |
| `eem_policies_*` | Policy settings |
| `equine_event_manager_*` | Legacy global settings (event source, Stripe keys, etc.) |
| `eem_mig_*_complete` | Migration completion flags |

---

## Foreign-key map

```
wp_posts (en_reservation)
  └─► wp_eem_reservation_config.reservation_id
  └─► wp_en_stall_reservations.reservation_id
  └─► wp_en_rv_reservations.reservation_id

wp_posts (en_entry / division)
  └─► wp_eem_division_entries.division_id

wp_eem_venues.id
  └─► wp_eem_venue_source_map.venue_id
  └─► wp_eem_venue_layouts.venue_id
```

Note: foreign keys are enforced at the application layer (PHP repo classes), not
as SQL `FOREIGN KEY` constraints — standard WordPress pattern since WP uses
MyISAM-compatible DDL.
