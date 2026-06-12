# Work Plan — Decouple reservation/division config from `wp_postmeta`

**Goal:** make WordPress a *replaceable front-end* (per the binding principle in
`ARCHITECTURE-DATA-OWNERSHIP.md`) by moving reservation + division **configuration** out of
WordPress's `wp_postmeta` (EAV key-value) into **plain relational tables behind a repository
API**. After this, only one class knows where the data physically lives, and the storage
backend (WP tables → GH's DB / a .NET service) becomes a one-class swap.

**Not in scope:** the customer booking UI, payments, the admin chrome — those are front-end
concerns handled separately in the headless roadmap. This plan is the *data layer* only.

**Roadmap tier: v3 (architecture track)** — moved out of v2 on 2026-06-12; runs after the v2
feature backlog. **Not a v1 blocker.** Start after launch, when the API direction is committed.
This is **v3 #1**, sequenced before **v3 #2** (the GH API integration).

---

## 1. Current-state map (measured, not estimated)

| Surface | Count |
|---|---|
| Distinct `_en_*` config keys | 102 |
| `get_post_meta` read sites | 285 |
| `update_post_meta` write sites | 81 |
| `meta_query` / meta-key lookups | 36 |

**Hot files (get/update_post_meta calls):**
| File | calls | live in v1? |
|---|---|---|
| `includes/class-equine-event-manager-events.php` | 121 | **NO — Native Events is v2-deferred ("Coming Soon")** |
| `admin/class-equine-event-manager-admin.php` | 53 | yes (mixed: charts/orders + config) |
| `includes/class-equine-event-manager-reservations-cpt.php` | 51 | yes — **the reservation-config core** |
| `public/class-equine-event-manager-shortcodes.php` | 30 | yes — customer-page reads |
| `includes/class-eem-entries.php` | 26 | yes — **Divisions, already a clean relational table** |
| `admin/class-eem-reservation-editor-page.php` | 20 | yes — editor save/read |

**Key insight that shrinks the job:** ~121 of the 285 sites are in the **Native Events**
subsystem, which is gated off in v1. And the Divisions subsystem (`class-eem-entries.php`)
**already uses a relational table** (`wp_eem_division_entries`) — it's the working template
for this whole effort. So the *live, not-yet-relational* config surface is really the
reservation core: roughly **~120–150 sites across 4 files** (reservations-cpt, shortcodes,
reservation-editor-page, the config slice of admin.php).

**Config subsystems (the 102 keys cluster cleanly — this is the migration's natural unit of work):**
1. **Event linkage / source** — `_en_event_id`, `_en_event_source`, `_en_external_event_id`,
   `_en_source_event_*`, `_en_use_global_event_source`, `_en_event_feed_url`, …
2. **Stall config** — `_en_stalls_enabled`, `_en_stall_rows`, `_en_stall_selection_mode`,
   `_en_stall_inventory_type`, `_en_blocked_stalls`, `_en_stall_chart_*`, `_en_stall_tack_*`,
   `_en_stall_map`, …
3. **RV config** — `_en_rv_enabled`, `_en_rv_rows`, `_en_rv_lots`, `_en_rv_zones`, `_en_rv_map`,
   `_en_rv_selection_mode`, `_en_rv_inventory_type`, `_en_rv_addon_*`, `_en_blocked_rv_lots`, …
4. **Pricing** — `_en_nightly_*`, `_en_weekend_*`
5. **Dates / availability** — `_en_available_start_date`, `_en_available_end_date`, `_en_start_date`
6. **Policies / misc** — cancellation-policy override, event-day info, agreement, etc.

---

## 2. Target architecture

A single **`EEM_Reservation_Config` repository** is the only code that touches storage:

```php
$cfg = EEM_Reservation_Config::for( $reservation_id );   // hydrate once
$cfg->get( 'stall.rows' );                               // typed getter (namespaced)
$cfg->stalls_enabled();                                  // typed convenience accessors
$cfg->set( 'rv.zones', $zones );                         // staged write
$cfg->save();                                            // one transactional flush
EEM_Reservation_Config::query()->for_event( $event_id ); // replaces meta_query lookups
```

- **Phase 1** backs this with `get_post_meta`/`update_post_meta` (no behavior change).
- **Phase 2** swaps the internals to relational tables. Callers never change again.

This mirrors what already exists for Divisions (`EEM_Division_Entries`) and Orders
(`EEM_Orders_Repository`) — proven pattern in this codebase.

---

## 3. Phased plan (committable chunks, smoke-gated like every other chunk)

### PHASE 1 — Funnel everything through the repository (behavior-preserving, ~70% of effort)
*Pure refactor. No table changes, no migration, no user-visible change. The smoke suite is the net.*

- **P1.1 — Build `EEM_Reservation_Config` skeleton** backed by postmeta. Typed getters/setters
  for all 6 subsystems, a hydrate-once cache, a `save()` that batches writes. Key map: the
  102 `_en_*` keys → namespaced accessors. Smoke: round-trip every key through the repo ==
  the raw postmeta value.
- **P1.2 — Route the editor save path** (`reservation-editor-page.php` + `reservations-cpt.php`
  central save) through `$cfg->set()/save()`. ~50 write sites. Smoke: render→collect→post→
  read-back parity (the canonical save-test shape).
- **P1.3 — Route the customer-page + pricing/validation reads** (`shortcodes.php`) through
  `$cfg->get()`. ~30 read sites. Smoke: customer render + checkout totals unchanged.
- **P1.4 — Route the remaining admin reads** (charts, lists, dashboard, reports) through the
  repo. Smoke: each admin page renders identically.
- **P1.5 — Replace the 36 `meta_query` lookups** with `EEM_Reservation_Config::query()` calls
  (still postmeta-backed under the hood for now). Smoke: list counts + sort orders unchanged.

**Exit criteria for Phase 1:** zero `get_post_meta`/`update_post_meta` for `_en_*` config keys
outside `EEM_Reservation_Config`. (A grep guard in the smoke suite enforces it going forward.)
**This phase is independently valuable even if Phase 2 never happens** — it's a strict
codebase improvement with no risk surface.

### PHASE 2 — Swap the backend to relational tables (~30% of effort, the higher-risk part)
- **P2.1 — Schema + migration.** Create the tables (§4), write `eem-mig-NNN` to copy existing
  postmeta → tables (proven pattern; idempotent; flag-gated). Dual-write window: repo writes
  BOTH postmeta and tables, reads from postmeta — proves the tables fill correctly with zero
  risk. Smoke: post-migration, table values == postmeta values for every seeded reservation.
- **P2.2 — Flip reads to tables** inside the repo (still dual-writing). Smoke: full suite green
  reading from tables. Bake on staging.
- **P2.3 — Rewrite the `query()` lookups as SQL JOINs** against the new tables (this is the
  real payoff — fast event/status filters instead of postmeta joins). Smoke: list parity.
- **P2.4 — Stop writing postmeta; drop the dual-write.** Optional: leave a one-way export to
  postmeta if any third-party reads it. Smoke + final regression sweep.

**Exit criteria for Phase 2:** config lives in relational tables; postmeta no longer the source
of truth; the repo is the only access path; storage backend is now a one-class swap to GH/.NET.

---

## 4. Schema sketch (Phase 2)

One row-per-reservation flat table for scalar config + child tables for the repeating
structures (which are JSON-in-postmeta today):

```sql
-- scalar/columnar config (one row per reservation)
CREATE TABLE wp_eem_reservation_config (
  reservation_id   BIGINT UNSIGNED PRIMARY KEY,
  event_source     VARCHAR(32),          -- native | tec | gems
  event_id         VARCHAR(191),         -- normalized across sources
  external_event_id VARCHAR(191) NULL,
  stalls_enabled   TINYINT(1) DEFAULT 0,
  stall_selection_mode VARCHAR(32),
  stall_inventory_type VARCHAR(32),
  rv_enabled       TINYINT(1) DEFAULT 0,
  rv_selection_mode VARCHAR(32),
  available_start_date DATE NULL,
  available_end_date   DATE NULL,
  nightly_price    DECIMAL(10,2) NULL,
  weekend_price    DECIMAL(10,2) NULL,
  -- … remaining scalars …
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY event (event_source, event_id)     -- the index that kills the meta_query pain
);

-- repeating structures (today: JSON blobs in postmeta → real rows)
CREATE TABLE wp_eem_reservation_stall_rows ( id …, reservation_id …, row_index …, side …, first_label …, last_label …, KEY reservation_id );
CREATE TABLE wp_eem_reservation_rv_lots    ( id …, reservation_id …, lot_label …, zone …, inventory …, KEY reservation_id );
-- blocked stalls / rv, zones, addons → similar child tables or a typed JSON column where structure is genuinely free-form
```

> Pragmatic note: not everything must become columns. Genuinely free-form blobs (e.g. the map
> geometry) can stay as a **typed JSON column** on the config row — still relational, still
> queryable, no EAV. The win is "one row per reservation + real indexes," not maximal
> normalization.

---

## 5. Risk + test strategy

- **Biggest risk:** the **36 `meta_query` lookups** (Orders/Reservations list filters + sorts)
  and **anything in the checkout/payment path**. Both get extra coverage + staging bake time.
- **Net:** the 135-file / 3,600-assertion smoke suite makes Phase 1 (behavior-preserving) safe
  to verify mechanically; add a grep-guard smoke that fails if `_en_*` postmeta is touched
  outside the repo.
- **Dual-write window** in Phase 2 means the tables are proven correct *before* anything reads
  from them — the migration can be validated on production data with zero user impact, and
  rolled back by simply continuing to read postmeta.
- **Incremental:** subsystems migrate independently (stall → RV → pricing → dates → policies).
  You can ship Phase 1 fully, then do Phase 2 one subsystem at a time across releases.

---

## 6. Effort estimate

| Phase | Scope | Est. (1 engineer) | Risk |
|---|---|---|---|
| **P1 Funnel** | repo skeleton + route ~120–150 live sites + 36 queries | **1.5–2.5 weeks** | Low (behavior-preserving) |
| **P2 Backend swap** | schema + migration + dual-write + flip reads + SQL queries | **1–1.5 weeks** | Medium (data + payment path) |
| Native Events subsystem (121 sites) | only when un-gating Native Events in v2 | +0.5–1 week | Low (not live) |
| **Total (live config)** | | **~2.5–4 weeks** | |

**Recommended first move:** ship **Phase 1 alone**. It's low-risk, independently valuable
(one-class storage boundary + a real `query()` API), and turns the eventual backend swap into
a contained, well-tested change instead of a sprawling one. Phase 2 then happens whenever the
GH/.NET API direction is locked.

---

## 7. Sequencing relative to other v2 work

- **Do this before** the GH API integration (Model A/B) — the repository + relational tables
  are what the sync/push layer reads from cleanly (no postmeta scraping).
- **Pairs with** Facility Layout Templates (v2) — templates are reservation-config snapshots;
  far easier to clone a config row + child rows than to deep-copy postmeta blobs.
- **Independent of** the Notifications and PDF-overlay v2 items.
