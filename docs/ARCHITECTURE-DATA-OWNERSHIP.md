# Stall Reservation System — Data Storage & Global Handicaps Integration

**Audience:** Global Handicaps / Equine Network engineering
**Purpose:** Explain where the stall-reservation plugin stores its data today, and the
options for making Global Handicaps (GH) the system of record if/when this system is
adopted alongside memberships, membership IDs, and the GEMS event calendar.
**Status:** Discussion document. None of this is a v1 launch blocker — v1 ships on the
WordPress database as described in §1. §3–§4 are the forward integration paths.

---

## 1. Where the data lives today

The stall reservation system is a **WordPress plugin**. All of its data lives in the
**WordPress MySQL/MariaDB database** — the same database WordPress core uses. There are
two buckets:

### 1a. WordPress core tables (configuration + definitions)
| Data | Storage |
|---|---|
| Reservation setups (an event's stall/RV/add-on config, pricing, capacity) | `wp_posts` + `wp_postmeta` — custom post type `en_reservation`, meta keys prefixed `_en_*` |
| Divisions / entries customers pay to enter | `wp_posts` + `wp_postmeta` — custom post type `en_entry`, meta keys `_en_division_*` |
| Plugin settings (payment gateway config, integration creds, etc.) | `wp_options` |

> **Note for direct readers:** `wp_postmeta` is WordPress's key-value (EAV) store. It is
> awkward to query from an outside application. If GH needs this config data, read it via
> an API or a provided SQL view — not by joining `wp_postmeta` directly.

### 1b. Plugin-owned custom tables (transactions + operational data)
These are clean, relational tables created by the plugin (prefix shown as `wp_`, the live
prefix may differ):

| Table | Holds |
|---|---|
| `wp_en_stall_reservations` | Stall order line-items (one row per stall booking; grouped into orders by an `order_key` hash) |
| `wp_en_rv_reservations` | RV-spot order line-items (same order-grouping model) |
| `wp_eem_division_entries` | Division entrants ledger (who entered which division, qty, paid/unpaid/refunded status) |
| `wp_en_order_adjustments` | Per-order discounts + custom line items |
| `wp_en_activity_log` | Audit trail (order created, payment status changes, refunds, etc.) |
| `wp_eem_event_defaults` | Per-event defaults (cancellation policy, venue map) |
| `wp_en_report_exports` | Cached report export metadata |

**An "order" is not a single row** — it is a set of component rows (stall + RV + entries +
add-ons) grouped by a shared `order_key`, plus a denormalized `reservation_id` for joins.

### 1c. Integration that already exists — GH is already upstream
The plugin **already treats Global Handicaps as an upstream source**: the **GEMS event
calendar** is a live event source the plugin pulls from (`EEM_Gems_Client`, fetch +
normalize + 15‑min cache; live since 2.7.168). Events shown in the reservation system can
already originate in GH. So "GH owns the canonical data" is an established pattern here —
this document is about extending it to customers/memberships and reservation output.

---

## 2. The data access is already abstracted (why this is feasible)

Every read/write path in the plugin goes through a **repository layer**, not scattered SQL:

- `EEM_Orders_Repository` — create/read/update orders, refunds, status changes
- `EEM_Division_Entries` — entrants ledger (record entry, spots-left, status sync)
- `EEM_Stall_Map_Importer` — stall/RV map + inventory definitions
- `EEM_Gems_Client` — GEMS event feed (the existing GH integration)
- `EEM_Customer_Profile_Repo`, `EEM_Reports_Repo`, `EEM_Dashboard_Repo`, etc.

Because writes are funneled through these classes, **the storage backend can be changed
behind them** without rewriting the whole plugin. That is the leverage point for any of the
options below.

---

## 3. Two models for "the reservation data lives in GH's database"

### Model A — Sync (WordPress is the transactional engine, GH is system of record)
- WordPress keeps running the live stall/RV picker, takes the payment, and prevents
  oversell locally.
- On every order/entry create/update, WordPress **pushes** the record to GH (via GH's API
  or a scheduled feed). GH's database becomes the warehouse the Razor app and others read.
- **Pros:** lowest risk, fastest to build, no change to the proven checkout/payment path.
  Re-uses the repository layer (add a "sync on write" step). Survives GH API downtime
  (queue + retry).
- **Cons:** GH's copy is eventually-consistent (seconds behind), not the live source.
- **Build surface (our side):** an outbound sync from the repository write paths + a retry
  queue. **GH provides:** a write/upsert endpoint for orders + entries.

### Model B — GH-primary (the plugin writes reservations/orders directly to GH)
- The repository classes write to **GH's API as the primary store**; local WordPress tables
  become a cache/working copy for the live picker.
- **Pros:** GH is the true single source of record in real time.
- **Cons:** higher risk and effort. Every checkout makes synchronous calls to GH, so GH's
  API must be fast and highly available or checkouts fail. Requires re-pointing each
  repository's persistence and re-implementing inventory/concurrency against GH (see §4).
- **Build surface (our side):** swap each repository's backend to GH's API + a cache layer.
  **GH provides:** the full API surface in §5, **including the atomic inventory endpoint**.

> **Recommendation:** start with **Model A**. It gets GH the data with low risk and keeps
> the money/oversell path on the proven WordPress transaction. Move to Model B only if GH
> must be the real-time authority *and* can commit to the inventory API in §4.

---

## 4. The make-or-break technical requirement: oversell + payment atomicity

This is the single most important thing to align on with GH.

Today, double-booking is prevented at the moment of checkout: inside a **per-event database
lock**, the plugin recomputes live availability and reserves the specific stall/RV spot in
one atomic step **before** charging the card. Unpaid invoice orders also hold inventory, so
the loser of a race is told the spot is taken instead of being charged for it.

- **In Model A**, this stays on the WordPress side — no change. GH receives the already-
  reserved result. ✅ Lowest risk.
- **In Model B**, inventory moves to GH, so **GH's API must provide the same atomic
  guarantee**: a "reserve *this specific* stall/spot for this customer" call that is
  transactionally safe under concurrent requests and returns success/failure. Without it,
  two simultaneous checkouts can both be told a stall is available. This is the hard part —
  it cannot be a simple "insert a row" endpoint; it needs atomic check-and-reserve.

The **payment** must be tied to that reservation: the order record has to be created
transactionally alongside (or immediately after) a confirmed reservation, so a card is never
charged for a spot that couldn't be secured.

---

## 5. API surface GH would need to provide

| Need | Model A | Model B | Notes |
|---|---|---|---|
| **Customer / membership lookup** (by membership ID, email) | optional | required | Lets reservations attach to a GH member identity (we already pull GEMS events this way) |
| **Event lookup** | already via GEMS | already via GEMS | Existing integration |
| **Write/upsert order** (header + line items + entries) | required | required | Idempotent (keyed by our `order_key`) so retries don't duplicate |
| **Order status updates** (paid / refunded / cancelled) | required | required | Drives GH's copy of payment state |
| **Atomic inventory reserve/release** | — | **required** | The §4 concurrency guarantee. The gating dependency for Model B |
| **Read endpoints** for the Razor app | n/a (Razor reads GH directly) | n/a | GH's own concern once data is in their DB |

---

## 6. Quick answers to the questions raised on the call

1. **"Where is the database?"** — WordPress MySQL/MariaDB. WordPress only runs on MySQL/
   MariaDB; it cannot use MS SQL Server or Postgres as its primary database.
2. **"It needs to be in our company SQL."** — If "company SQL" is a **MySQL** server GH
   manages, WordPress can run directly off it with only a `wp-config.php` connection change
   (no code change). If it's a different engine, use Model A/B to land the data in GH.
3. **".NET / Razor app reading the data."** — Recommended: GH consumes the data from **its
   own database** (populated by Model A or B) or via a documented **read API / SQL views** —
   not by querying `wp_postmeta` directly.
4. **"GH should own the data (memberships, IDs, GEMS, reservations)."** — Consistent with how
   the plugin already consumes GEMS. Pick Model A (sync) or Model B (GH-primary); the
   deciding factor is whether GH can provide the **atomic inventory-reserve API** (§4) needed
   for Model B.

---

## 7. Recommended next steps

1. GH confirms the **engine** of "company SQL" (MySQL vs. MS SQL/other).
2. Pick **Model A vs. Model B**.
3. If Model B: GH commits to the **atomic inventory-reserve endpoint** — this is the gating
   item; everything else is straightforward CRUD.
4. Agree the **order/entry payload schema** (we can supply our current order + entrants shape
   as the starting contract).
5. Sequence it as a **v2 integration**; v1 launches on the WordPress database unchanged.
