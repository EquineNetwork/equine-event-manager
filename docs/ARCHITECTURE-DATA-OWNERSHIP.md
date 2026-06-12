# Stall Reservation System — Data Storage & Global Handicaps Integration

**Audience:** Global Handicaps / Equine Network engineering
**Purpose:** Explain where the stall-reservation plugin stores its data today, and the
options for making Global Handicaps (GH) the system of record if/when this system is
adopted alongside memberships, membership IDs, and the GEMS event calendar.
**Status:** Discussion document. None of this is a v1 launch blocker — v1 ships on the
WordPress database as described in §1. §3–§4 are the forward integration paths.

> **Guiding principle (Equine Network, 2026-06-12): WordPress is a replaceable front-end,
> not the permanent foundation.** The durable assets are (1) the **data** and (2) the
> **business rules** (pricing, oversell/inventory, entries, refunds). Those must be able to
> outlive WordPress. The strategy that delivers this is **API-first**: put the data + rules
> behind an API (GH's, or a dedicated service), and treat the WordPress plugin as *one*
> client of that API — swappable later for a custom web front-end, a mobile app, or
> absorption into GH's platform. v1 launching on WordPress is fine and fast; the discipline
> is to keep it *replaceable* (see §9).

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

### 3a. Native mobile app angle (why API-first matters long term)
A future **native mobile app** is a strong argument in this direction — but the thing that
actually enables a mobile app is an **API-first backend**, not specifically "the data lives
in GH's database." A mobile app can't talk to a MySQL table; it talks to an API.

- WordPress already ships a full **REST API**, so a mobile app *could* be built against the
  plugin directly. But that ties the app to WordPress + the plugin's internal shape.
- The cleaner long-term picture: **GH exposes one API** that the WordPress plugin, the Razor
  web app, **and** a future mobile app all consume — memberships, events (GEMS), and
  reservations behind a single contract, one source of truth. That is exactly what **Model B
  (or at least an API-first design)** delivers, and it future-proofs the ecosystem.
- Net: the mobile-app goal **reinforces investing in the API contract** (§5/§8) early, even
  if you launch on Model A. Designing the sync payload (§8) now as if it were the public API
  keeps the door open to Model B + mobile without rework.

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
5. Sequence it as a **v3 architecture-track effort** (after the v2 feature backlog); v1
   launches on the WordPress database unchanged. Do the postmeta→relational de-coupling
   (`WORKPLAN-postmeta-decouple.md`) first so the sync/API layer reads the repository, not
   postmeta.

---

## 8. Starter payload contract (order + entrants)

This is the **shape the plugin can emit per order** (Model A push, or the body of a
Model B write). It is derived from the plugin's current internal order model — treat it as
a starting point for the contract, not a frozen spec. Money values are decimal strings;
dates are ISO‑8601. `order_key` is the idempotency key (an order is one logical unit even
though internally it is several component rows).

```jsonc
{
  "order_key": "5001a1cf01aaa8a4f955aa61388c54a4",   // idempotency key (stable, unique)
  "order_number": "00020",                            // human-facing, zero-padded #00020
  "source": "equine-event-manager",
  "created_at": "2026-06-12T14:33:00Z",
  "status": "paid",                                   // paid | unpaid | invoice_sent | refunded | partially_refunded | cancelled

  "event": {
    "label": "2026 Southeast Region Super Sort",
    "reservation_id": 5990,                           // our reservation/event instance id
    "gems_event_id": "30597",                         // GH/GEMS id when the event came from GEMS (null otherwise)
    "start_date": "2026-06-26",
    "end_date": "2026-06-28"
  },

  "customer": {
    "name": "Thompson, Emma",
    "email": "emma@example.com",
    "phone": "+1 5551234567",
    "membership_id": null                             // GH membership id if/when resolved (Model A optional, Model B required)
  },

  "billing": {
    "first_name": "Emma", "last_name": "Thompson",
    "address_1": "", "address_2": "", "city": "", "state": "", "postal_code": "", "country": "US"
  },

  "line_items": [
    { "type": "stall",     "label": "Stall #A-12",            "qty": 1, "unit_price": "45.00", "subtotal": "45.00", "stall_number": "A-12", "nights": 2 },
    { "type": "rv",        "label": "RV Spot (Red Lot #14)",  "qty": 1, "unit_price": "10.00", "subtotal": "20.00", "lot": "Red Lot", "spot": "14", "nights": 2 },
    { "type": "entry",     "label": "#9.5 Division",          "qty": 2, "unit_price": "45.00", "subtotal": "90.00", "division_id": 10491 },
    { "type": "addon",     "label": "50-amp Hookup Upgrade",  "qty": 1, "unit_price": "20.00", "subtotal": "20.00" },
    { "type": "custom",    "label": "Late fee",               "qty": 1, "unit_price": "15.00", "subtotal": "15.00" },
    { "type": "fee",       "label": "Non-Refundable Convenience Fee", "qty": 1, "unit_price": "5.00", "subtotal": "5.00" }
  ],

  "discount": {                                        // null when none
    "type": "percent",                                 // dollar | percent
    "value": "10",
    "reason": "Returning customer",
    "amount": "9.00"                                   // resolved dollar amount applied to subtotal
  },

  "totals": {
    "subtotal": "175.00",
    "discount": "9.00",
    "convenience_fee": "5.00",
    "tax": "0.00",
    "total": "171.00",
    "amount_paid": "171.00",
    "amount_refunded": "0.00"
  },

  "payment": {
    "gateway": "authorize_net",                        // stripe | authorize_net
    "transaction_id": "60012345678",
    "card_brand": null,                                // captured at charge time when available
    "card_last4": null
  },

  "entries": [                                          // entrants ledger rows (the authoritative entry records)
    { "division_id": 10491, "division_name": "#9.5 Division", "qty": 2, "status": "paid",
      "customer_name": "Thompson, Emma", "email": "emma@example.com", "created_at": "2026-06-12T14:33:00Z" }
  ]
}
```

**Event hooks the push would fire on (our side already emits these):**
- `eem_order_created` — full order created (checkout or admin Create Order).
- `eem_order_payment_status_changed` — paid / refunded / cancelled transitions (send a
  status patch keyed by `order_key`).

**For Model B only — the inbound call we would make to GH before charging:**
```jsonc
// POST /reservations/reserve   → atomic check-and-reserve, returns success/failure
{
  "event": { "reservation_id": 5990 },
  "holds": [
    { "type": "stall", "stall_number": "A-12", "nights": ["2026-06-26","2026-06-27"] },
    { "type": "rv",    "lot": "Red Lot", "spot": "14", "nights": ["2026-06-26","2026-06-27"] },
    { "type": "entry", "division_id": 10491, "qty": 2 }
  ],
  "customer": { "email": "emma@example.com", "membership_id": null }
}
// 200 { "reserved": true, "hold_id": "..." }  |  409 { "reserved": false, "conflicts": [ ... ] }
```
This `reserve` call is the §4 atomic guarantee — it is the one endpoint GH must implement
correctly (concurrency-safe) for Model B to be viable.

---

## 9. Keeping it WordPress-replaceable (avoiding lock-in)

Per the guiding principle, WordPress should be a *swappable front-end*. Honest assessment of
where the plugin already helps and where the real lock-in is:

**Already portable (low WordPress coupling):**
- The **transactional data** lives in plain relational MySQL tables (`*_stall_reservations`,
  `*_rv_reservations`, `*_eem_division_entries`, `*_order_adjustments`, …). These move to any
  system as-is.
- The **business logic** is funneled through repository classes, not scattered through
  templates — so the rules (pricing, oversell, entries, refunds) are separable from WP even
  though they currently call `wp_*` helpers.

**The real lock-in (what to unwind to be truly portable):**
1. **Reservation + Division *config* lives in `wp_postmeta`** (WordPress's EAV key-value
   store) under `_en_*` keys, and events are WP custom post types. This is the single
   biggest WordPress chain. **Unwinding step:** move that config into clean relational tables
   (or GH's schema). Everything else is already relational.
2. **Customer booking page** is a WP shortcode rendered by the theme (Elementor). In a
   headless future it becomes a front-end calling the API.
3. **Admin UI + auth** are WP admin + WP users. These are the *interim* admin surface; a
   replacement front-end (or GH's platform) takes over later.
4. **Payments** run in the WP request flow (Stripe/Auth.net). Portable, but the charge +
   order-create transaction would move to whatever owns the API.

**The path to "not chained to WordPress":**
1. **Design the §8 payload as the real API contract now** — even while syncing (Model A), so
   the interface is front-end-agnostic from day one.
2. **Migrate the postmeta-backed config to relational tables** (a focused data-layer refactor)
   so the data model no longer depends on WordPress internals.
3. **Stand up the API** (GH-owned, or — since the other team is .NET — a dedicated .NET
   service that owns the DB + API). WordPress keeps writing through it.
4. **Swap the front-end** when ready: a custom web app, a mobile app, or GH's platform —
   WordPress is retired or kept only as one admin client. No data migration needed at that
   point because the data + rules already live behind the API.

**Practical guardrails to apply even during v1 (cheap insurance, no launch cost):**
- Keep all new persistence behind the repository classes (don't write `wp_*` data calls into
  templates/UI).
- Prefer the plugin's relational custom tables over `wp_postmeta` for any *new* structured
  data (the Divisions entrants ledger already follows this).
- Treat the §8 order/entry shape as the canonical contract, not a WordPress array.
