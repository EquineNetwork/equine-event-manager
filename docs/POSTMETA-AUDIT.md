# Post-Meta Audit (task #211)

**Goal (Whitney's directive):** the plugin should be fully off WordPress
`wp_postmeta` for business data — relational config tables are the canonical
store. This audit maps every post-meta read/write so we know exactly what's left
and can plan remediation. **No code was changed by this audit** — remediation
involves data migrations and needs sign-off before it runs.

Scan: 407 post-meta calls across the plugin's PHP (excludes `tests/`, `.mockups/`).

---

## ✅ Re-verification — 2026-06-23 (ROADMAP #10 / #13)

Re-scanned to verify how complete the decouple is. Status against the original audit:

- **#212 (checkout base-rate read) — FIXED.** `EEM_Shortcodes::get_reservation_meta()`
  now overlays the canonical base-rate fields from `wp_eem_reservation_config`
  (see the "Overlay canonical base-rate fields…" helper + the config overlay in
  `get_reservation_meta`). The live pricing risk is closed. Guarded by the new
  ratchet smoke (below).
- **Current live-code business-data post-meta count: 214** (`_en_*` /
  `_equine_event_manager_*` keys in `includes/` + `admin/` + `public/`, excluding
  `includes/migrations/`). This is the **ratchet baseline** — it should only ever
  go down. (Raw total across all PHP is now 477, but +91 of that is the `tools/`
  seeders and 40 is one-time `includes/migrations/` — neither is a live read path.)
- **Regression guard shipped:** `tests/smoke/postmeta-decouple-ratchet-smoke.php`
  implements step 5 below — counts business-data post-meta calls and fails if the
  number rises above 214, and asserts the #212 overlay stays in place. Pure
  file-scan, runs without WordPress.

**Remaining real gaps (verified still on post-meta), highest-value first:**

| Gap | Where | Tracked |
|---|---|---|
| **Map snapshots** `_en_stall_map` / `_en_rv_map` | `class-eem-venues-page.php`, `class-eem-stall-map-importer.php`, Stall Chart reads, `_section-stall.php` fallback | ROADMAP #9 |
| **Blocked-units hybrid reads** `_en_stall_chart_blocked_*` (+ v1 fallbacks `_en_blocked_stalls` / `_en_blocked_rv_lots`) | `shortcodes.php:253,965`, `orders-repository.php:1737-1763`, `admin:2675` | #13 |
| **Events / Venues / Producers / Divisions editors** still read/write post-meta as the live store | `class-eem-event-editor-page.php` (24), `class-eem-venue-editor-page.php` (14), `class-eem-producer-editor-page.php` (8), `class-eem-native-event-repo.php` (12) | #13 |
| **Reservations CPT `_en_*` mirror** writes still mirror config to post-meta | `class-equine-event-manager-reservations-cpt.php` (34) | #13 |

Bottom line: the **reservation builder (setup / pricing / rows) and checkout pricing
are fully on the config table.** What remains is map snapshots (#9), the blocked-units
hybrid, and the CPT-style editors (events/venues/producers/divisions) — each a
sign-off-gated migration, not a quick refactor.

---

## TL;DR

- **The config decouple already happened for the reservation builder.** Reservation
  setup/pricing/maps are written to the relational `wp_eem_reservation_config`
  table via `EEM_Reservation_Config`. Most remaining `_en_*` post-meta reads are
  **legacy/mirror reads** that should route through the config repo.
- **Highest-priority real bug** (already tracked as **#212**): the customer-checkout
  pricing path (`EEM_Shortcodes::get_reservation_meta`) still reads **base rates**
  from post-meta. For reservations built purely through the v4 config builder the
  post-meta value is stale ($0), so a customer could be charged the wrong amount.
  Order-Edit already overlays config rates (2.7.420); checkout does not yet. **This
  is the one to fix first** — it's a live pricing risk, not just cleanup.
- **Events / Venues / Producers / Divisions** are mostly CPT post-meta. These have
  relational tables + backfill migrations (mig-018/020/022/024) but the editors
  still read/write post-meta as the live store. Lower urgency than pricing.

---

## Bucket 1 — Business data on post-meta (remediation targets)

Priority files (10+ business-data post-meta calls):

| File | Hits | What it stores |
|---|---|---|
| `includes/class-equine-event-manager-events.php` | 63 | event source, venue/producer, flyer, reservation linkage |
| `includes/class-equine-event-manager-reservations-cpt.php` | 55 | `_en_*` reservation fields, section toggles, stall/RV config pairs |
| `includes/class-eem-events-repo.php` | 36 | `_en_event_*`, TEC legacy `_Event*` |
| `public/class-equine-event-manager-shortcodes.php` | 27 | event source, RV add-ons, availability — **incl. the #212 rate reads** |
| `admin/class-eem-event-editor-page.php` | 27 | `_equine_event_manager_event_*` read+write |
| `admin/class-equine-event-manager-admin.php` | 21 | event lookup, blocked units, shavings pricing |
| `includes/class-eem-venue.php` | 17 | venue address, lat/lng, canonical link |
| `admin/class-eem-venue-editor-page.php` | 14 | venue contact + location |
| `includes/class-eem-native-event-repo.php` | 12 | native event config |
| `includes/class-eem-reservations-list-repo.php` | 10 | stall/RV quantity, add-ons |
| `admin/class-eem-reservations-list-page.php` | 10 | bulk copy, frontend-URL cache, event link |

Lower-density business-data files: `class-eem-reservation-config.php` (the bulk
config writer), `class-eem-reservation-editor-page.php`, `class-eem-producer-editor-page.php`,
`class-eem-producer-repo.php`, `class-eem-division-config-repo.php`,
`class-equine-event-manager-orders-repository.php` (blocked-unit + availability reads).

### Sub-priority within Bucket 1
1. **Reservation pricing/config reads in `shortcodes.php`** → route through
   `EEM_Reservation_Config`. **Start with #212 (checkout base rates).**
2. **Reservations CPT `_en_*` reads/writes** (`class-equine-event-manager-reservations-cpt.php`)
   → already dual-stored; make config the canonical read, post-meta a derived mirror
   (or drop the mirror once nothing reads it). See `config-table-postmeta-divergence`.
3. **Events/Venues/Producers/Divisions editors** → make the relational repos
   (`EEM_Native_Event_Repo`, `EEM_Venue`, `EEM_Producer_Repo`, `EEM_Division_Config_Repo`)
   the canonical read/write; post-meta becomes derived for WP-native editing only.

---

## Bucket 2 — Migration / one-time backfill code (leave as-is)

Calls inside `includes/migrations/` (mig-001, 003, 004, 005, 006, 007, 018, 020,
022, 024, …) read/write post-meta to move data into relational tables. These are
version-gated one-time runs — correct to keep, not live code.

---

## Bucket 3 — WordPress-native / framework (legitimately fine)

`_elementor_edit_mode`, `_thumbnail_id`/flyer attachment meta, `_edit_lock`,
the `reservations`/`_en_reservation_shortcode` shortcode-cache values, TEC legacy
`_EventStartDate`/`_EventEndDate`/`_EventVenueID` read-only compatibility, the
`_eem_frontend_url_cache` + sort-cache keys, and the per-reservation tax override
(`EEM_Settings_Repo`). Post-meta is the right store for these.

---

## Recommended remediation order (pending Whitney's go-ahead)

1. **#212 — checkout base-rate overlay** (live pricing risk). Extend the config-rate
   overlay from Order-Edit into `get_reservation_meta` so checkout/email/receipt
   price from the config table. **Payment-behavior change — confirm before shipping**
   + size how many reservations have config↔post-meta rate drift.
2. **Reservation config reads** → make `EEM_Reservation_Config` the single source for
   all `_en_*` reservation reads; delete redundant post-meta reads.
3. **Stop writing the post-meta mirror** once (2) confirms nothing reads it (guarded
   behind a smoke that asserts no business-data post-meta read remains).
4. **Events/Venues/Producers/Divisions** repos become canonical; post-meta derived.
5. Add a **CI smoke** that greps for `get_post_meta`/`update_post_meta` on
   business-data keys outside `includes/migrations/` and fails on new ones — keeps
   the codebase from regressing back onto post-meta.

Each step that moves or drops stored data is a migration → gets its own version-gated
`eem-mig-*` file + read-back smoke, and is confirmed with Whitney before it runs.
