# Inventory & Concurrency Report — How the Reservation/Entry Inventory System Is Built

**Audience:** Equine Network dev + ops team.
**Question this answers:** *When a popular event opens and hundreds of people try to grab stalls / RV
lots / entry spots in the same few minutes, can the plugin handle that load without **overbooking** or
**double-booking** the same unit?*
**Short answer:** **Yes for the customer checkout path** — it is serialized per event and re-checks
availability at the last possible moment, so two buyers can never both win the same stall/lot/spot.
There are a small number of **known, lower-severity gaps** (one admin-only double-book path, one
payment-replay double-*charge* window, and the absence of a database-level uniqueness backstop) that are
documented below with severity and the exact fix. None of them affect the core "two customers can't buy
the same stall" guarantee.

Audit date: 2026-06-13 · plugin v2.7.281 · branch `v4-stall-mapping`. This report supersedes/extends the
June-2026 hardening pass and is the reference for the v3 "strict inventory/concurrency audit" item.

---

## 1. What "inventory" means here

The plugin sells four kinds of finite inventory, all scoped to a single **reservation** (an
`en_reservation` post that hangs off an event):

| Inventory | How a unit is identified | Where availability comes from |
|---|---|---|
| **Stalls — mapped** | a specific stall label (e.g. `A-12`) on the venue map | the configured stall map minus already-assigned/blocked stalls |
| **Stalls / RV — quantity** | a count, no specific unit | configured total minus sum already sold |
| **RV lots — mapped** | a specific lot/zone | configured lots minus assigned/blocked |
| **Division / event entries** | a "spot" in a division | the division's spots cap minus held entries |

Stall/RV assignments are stored on the **order** (per-customer) and aggregated back to the reservation;
entries are held in a dedicated ledger table (`wp_eem_division_entries`). Promotions, refunds and
cancellations release inventory through the order-status lifecycle.

---

## 2. The one rule that makes it safe: a single locked critical section

Every customer purchase — stalls, RV, quantity, **and** entry spots — flows through exactly **one**
inventory-creating function:

```
handle_reservation_submission()        public/class-equine-event-manager-shortcodes.php
```

That function wraps its entire "decide what's available → take payment → write the order" sequence in a
**MySQL advisory lock** keyed to the reservation:

```php
$checkout_lock = 'eem_checkout_' . absint( $reservation_id );          // line 2337
$got_lock      = SELECT GET_LOCK($checkout_lock, 20);                   // line 2338  (20s wait)
if ( 1 !== $got_lock ) {  return "Another checkout is finishing…";  }   // FAIL-SAFE: refuse, never proceed
try {
    $live_status = get_reservation_status(...);     // 2346  ← fresh availability, INSIDE the lock
    $errors      = validate_submission(...);        // 2347  ← re-checks oversell against $live_status
    if ( $errors ) return;                          // loser is rejected here, before any charge/order
    $payment     = process_payment_submission(...); // 2353  ← charge
    $insert      = insert_reservation_orders(...);  // 2359  ← write order + entries (token-dedup inside)
} finally {
    SELECT RELEASE_LOCK($checkout_lock);            // 2361  ← always released, even on exception
}
```

Why this is the whole ballgame:

- **The availability snapshot is read *inside* the lock** (line 2346), so it already reflects any order a
  competing buyer committed a millisecond earlier. There is no "check earlier, write later" window
  (no TOCTOU race).
- **The re-validation (line 2347) runs against that fresh snapshot.** If the stall/lot/spot the customer
  picked got taken while they were filling the form, they're rejected *before* any money moves.
- **The lock is per-reservation** (`eem_checkout_{id}`). Two buyers for the *same* event are serialized;
  buyers for *different* events run fully in parallel — so the lock protects correctness without
  bottlenecking the whole site.
- **It fails safe.** If the lock can't be acquired within 20 seconds (extreme contention), the request is
  *refused* with a "please try again" notice — it never proceeds unserialized.
- **It always releases** via `finally`, and MySQL also auto-releases the lock if the request connection
  dies mid-flight (locks are connection-scoped), so a crashed request can't wedge an event.

This is the same lock key the admin assignment tools use (next section), so admin actions and customer
checkouts also mutually exclude.

---

## 3. Division / event entry spots — same lock, atomic cap

Entry spots are the highest-velocity inventory (rodeo divisions sell out fastest), so they get special
mention:

- The cap check — `EEM_Division_Entries::spots_left()` (called from `validate_submission`, ~line 2736) —
  runs **inside** the checkout lock (via the re-validate at 2347).
- The ledger insert — `record_entry()` (~line 4202, called from `insert_reservation_orders`) — also runs
  **inside** the same lock.
- So "is there a spot left?" and "claim the spot" are one atomic step under one lock. Two simultaneous
  buyers cannot both pass `spots_left()` and both insert — the loser re-validates *after* the winner's row
  exists and is rejected.

Spots are held for both `paid` and `unpaid` orders (an unpaid invoice still reserves the spot);
refund/cancel frees them via the order-status hooks.

---

## 4. Replay / double-submit protection (refresh, double-click, back button)

Every page render embeds a unique idempotency token (`en_submission_token`, a fresh UUID per load,
line 457). On submit:

- `insert_reservation_orders()` checks `has_processed_submission_token()` (line 3799) and marks it
  processed (line 4102) — both **inside** the lock.
- A double-click / refresh / back-button re-POST with the same token returns `duplicate` and creates **no
  second order**.
- For Stripe, the PaymentIntent is additionally bound to the token (`hash_equals`, line 3513) and to the
  reservation (line 3497), so a paid intent can't be replayed into a new form.

→ **No duplicate *orders* are ever created from replay.** (See gap MED-1 for the one place this does not
also prevent a duplicate *charge*.)

---

## 5. Admin assignment paths

Five admin write paths can also change stall/RV assignments. All acquire the **same**
`eem_checkout_{reservation_id}` lock (via `EEM_Admin::acquire_assignment_lock()`,
admin/class-equine-event-manager-admin.php:3052, GET_LOCK 15s), so admin edits and customer checkouts
serialize against each other:

| Admin path | Locked | Re-checks conflicts in-lock | Fail-safe on timeout |
|---|---|---|---|
| `ajax_stall_map_action` (assign/unassign/block/tack) | ✅ | ✅ | ✅ refuses |
| `ajax_move_stall_assignment` (per-night drag move) | ✅ | ✅ | ✅ refuses |
| `ajax_auto_assign` (auto-fill) | ✅ | ✅ | ✅ refuses |
| `handle_generate_stall_assignments` (bulk generate) | ✅ | ✅ | ⚠️ ignores timeout (gap LOW-1) |
| `handle_update_order_assignments` (Order Detail override) | ✅ | ⚠️ **no cross-order re-check** (gap MED-2) | ⚠️ ignores timeout |

---

## 6. Behaviour under heavy load (the "ton of traffic" question)

- **Throughput shape:** correctness is enforced *per reservation*, not globally. 500 people hitting 50
  different events run with full parallelism; 500 people hitting *one* hot event are processed one-at-a-
  time *for the commit step only* (read-availability→charge→write), which is milliseconds each. The form
  rendering, validation, and payment-gateway round-trip for everyone else proceeds concurrently; only the
  final atomic commit queues.
- **No oversell at the queue:** because each request re-reads availability the instant it holds the lock,
  the Nth buyer for the last stall sees it gone and is rejected cleanly — the queue drains to exactly the
  real capacity, then everyone after gets "sold out," never a phantom oversell.
- **Backpressure:** the 20s lock wait is the safety valve. Under pathological contention a request waits
  up to 20s then refuses (asks the customer to retry) rather than proceeding unsafely. This is the correct
  trade-off for money/inventory — *refuse, don't double-book.*
- **Caveat — this is single-database serialization, not a distributed queue.** It is correct and more than
  sufficient for "sells out in minutes" human traffic on a normal WP/MySQL host. It is *not* designed for
  bot-storm / flash-sale-bot scale; if that ever becomes a threat, the v3→API/relational track (see
  ROADMAP) is where a proper reservation queue + DB constraints would live.

---

## 7. Guarantees we can currently claim

1. **No oversell / double-allocation on customer checkout** — stalls (mapped + quantity), RV lots, and
   division spots. The decide→charge→write critical section is serialized per reservation and re-reads
   availability fresh inside the lock; the loser is refused before any order or charge.
2. **Entry spots cap is exactly-once safe** — checked and claimed under the same lock.
3. **No duplicate orders** from double-click / refresh / back-button / paid-intent replay.
4. **Admin assignment tools and customer checkout mutually exclude** via one shared lock key; three of the
   five admin paths also fail safe on lock timeout.
5. **Create Order (admin) inherits all checkout protections** — it routes through the same shortcode path.
6. **Inventory is released correctly** on refund/cancel through the order-status lifecycle.

---

## 8. Known gaps — risk register

| ID | Severity | What | Where | Effect | Fix |
|---|---|---|---|---|---|
| **MED-1** | MEDIUM | Auth.net charge fires *before* the duplicate-token check | charge at `shortcodes.php:2353`; dedup at `:3799` (inside insert) | A replayed Auth.net submission creates no second *order* but can fire a second *charge*. Backstop today = Auth.net's server-side Duplicate Window (default ~120s). Risk = replay after that window, or with an amount delta. Stripe unaffected (confirm-only of a bound intent). | Move `has_processed_submission_token()` ahead of `process_payment_submission()`, and/or send an explicit idempotency / `duplicateWindow` to Auth.net. **Payment-path change — verify carefully, one-at-a-time.** |
| **MED-2** | MEDIUM | Order Detail manual override writes admin-chosen stalls without re-checking they're free on another order | `handle_update_order_assignments` `admin.php:4766` (writes ~`:4799`, no cross-order scan) | Two admins (or admin + auto-assign) editing two orders' override forms can assign the *same* stall; the lock serializes the writes but neither rejects the collision → silent double-book. Admin-only, low concurrency. | Add the same other-order conflict scan `ajax_stall_map_action` uses (`admin.php:3218`) inside the lock before writing. |
| **LOW-1** | LOW | Two admin paths ignore the lock-acquire return (fail *open* on 15s timeout) | `handle_update_order_assignments:4798`, `handle_generate_stall_assignments:10123` | On a genuinely contended reservation these could run unserialized. Rare (15s timeout), admin-only. | Branch on the boolean like the AJAX handlers do; refuse on timeout. |
| **LOW-2** | LOW | No DB-level UNIQUE backstop | `wp_eem_division_entries` has only non-unique keys (`class-eem-division-entries.php:70-71`); stall/RV assignments live in free-text order `notes` | The advisory lock is the *sole* guard. Any future writer that forgets the lock, or a direct DB op, has no database safety net. All current writers are locked, so this is latent structural risk, not an active bug. | Notes→table migration + `UNIQUE(reservation_id, unit, date)`; `UNIQUE(division_id, order_key)` on the ledger. **Needs a data-migration — schedule deliberately.** |

---

## 9. Recommended hardening (priority order)

1. **MED-2 + LOW-1 (admin paths)** — small, non-payment, low-risk. Add the cross-order conflict re-check to
   the Order Detail override and make both admin paths fail safe on lock timeout. *(Safe to do now.)*
2. **MED-1 (Auth.net replay double-charge)** — real money-path fix; reorder the dedup check ahead of the
   charge and/or set an Auth.net idempotency guard. *(Payment code — do as a single, verified change.)*
3. **LOW-2 (DB UNIQUE backstop)** — the durable structural fix. Normalize stall/RV assignments + entry
   holds into dedicated tables with UNIQUE constraints so the database itself refuses a double-book even if
   application code ever slips. This is the right thing to land *with* the v2/v3 postmeta→relational
   de-coupling, since it's the same storage-normalization work.

---

## 10. How to prove it under load (recommended next step)

Static audit shows the locking is correct; a **concurrency test** would turn "we believe" into "we
measured." Recommended harness (can be added to `tests/`):

1. Seed one reservation with a known tiny capacity (e.g. 5 stalls / 5 entry spots).
2. Fire N=50 concurrent checkout POSTs (parallel curl / a small PHP fork or a k6/Apache-bench script)
   targeting the last unit.
3. Assert: **exactly 5 orders succeed, the other 45 get a clean "sold out" rejection, and the orders table
   never contains two assignments of the same stall** (and the ledger never exceeds the spots cap).
4. Repeat with a mix of two events to confirm cross-event parallelism.

This directly validates the "ton of traffic, no overbooking" claim end-to-end rather than by inspection.

---

## Appendix — key file:line map

- Checkout lock + critical section: `public/class-equine-event-manager-shortcodes.php:2337-2362`
- Availability re-read in-lock: `:2346` · re-validate: `:2347` · charge: `:2353` · insert: `:2359`
- Idempotency token: render `:457` · check `:3799` · mark `:4102` · helper `:6339`
- Stripe intent↔token bind: `:3497`, `:3513`
- Entry spots cap: `EEM_Division_Entries::spots_left()` (called ~`:2736`), `record_entry()` ~`:4202`
- Admin shared lock: `admin/class-equine-event-manager-admin.php:3052` (`acquire_assignment_lock`)
- Admin paths: `ajax_stall_map_action:3104` · `ajax_move_stall_assignment:5582` · `ajax_auto_assign:5755` ·
  `handle_generate_stall_assignments:10123` · `handle_update_order_assignments:4766`
- Entries ledger schema (no UNIQUE): `includes/class-eem-division-entries.php:60-73`

---

## ADDENDUM — 2026-06-20 · plugin v2.7.515 · re-audit of admin & newer write paths

The original audit was done at v2.7.281. ~230 versions of new admin write paths have landed since
(editable order dates, click-to-assign, per-order check-in, custom line items / discount, admin
Collect Payment). This addendum re-audits those paths. **The customer-checkout oversell guarantee
(§2) and all stall/RV assignment paths remain fully locked + re-checked — no new oversell exposure.**
The new gaps cluster in the admin *money* paths. Nothing is HIGH (each needs near-simultaneous admin
action or a double-submit; Auth.net's Duplicate Window is a partial backstop).

### Confirmed still-safe (re-verified at v2.7.515)
- `ajax_stall_map_action` (`admin.php:3359`) — assignment lock + in-lock cross-order conflict
  re-check before assign (`:3473-3484`) and block (`:3421-3430`). No oversell.
- `ajax_move_stall_assignment` (`:6013`) — lock + in-lock per-date destination conflict scan.
- `ajax_assign_order_to_unit` (`:6183`, the new order-context "click an available unit" flow) —
  lock + nonce + cap + in-lock conflict check.
- `EEM_Refund_Engine::process_amount_refund` (`refund-engine.php:118`) — per-order `GET_LOCK`,
  remaining-balance read + `exceeds_remaining` guard run inside the lock. The new **Edit-Dates
  refund branch routes through this** guarded method, so the refund half is protected.
- `ajax_order_checkin_set` (`:6347`) → `set_order_checkin` (`stall-status-repo.php:175`) —
  `INSERT … ON DUPLICATE KEY UPDATE` on the `(reservation_id, order_number)` unique key. Idempotent.

### New risk-register rows

| ID | Sev | What | Where | Fix |
|---|---|---|---|---|
| MED-3 | MED | **Edit-Dates "charge" branch** mutates `subtotal`/`total`/`tax`/`payment_status` with **no lock** and no idempotency token → lost-update vs a concurrent Edit-Dates or Add-Items on the same rows; a fast double-POST adds the delta twice. Admin-only, no money moves on this branch (raises Balance Due only). | `admin.php` `handle_ajax_edit_dates:9654`, charge branch `:9756-9781`, date write `:9723-9731` | Wrap the handler in `acquire_assignment_lock($reservation_id)` and **re-`get_order` inside the lock** before computing new subtotals (same pattern as the stall handlers; different lock key than the refund engine → no self-deadlock). Optionally add a one-shot edit token. |
| MED-4 | MED | **Admin Collect Payment (Auth.net)** `already_paid` check is **non-atomic** vs the live charge — two requests can both pass the `payment_status==='paid'` read and both fire an Auth.net authCapture for the same balance → real double-*charge* on a double-click. Sibling of customer-side MED-1. Backstop today = Auth.net Duplicate Window (~120s). | `shortcodes.php` `ajax_collect_payment_authorize_charge:8284` (check `:8296` vs charge `:8314`, mark `:8320`) | Per-order `GET_LOCK` around read→charge→mark; re-read `payment_status` in-lock; refuse if paid. Belt-and-suspenders: Auth.net idempotency / tighter `duplicateWindow`. **PAYMENT PATH — change one-at-a-time + verify live (CLAUDE.md).** |
| LOW-3 | LOW | Stripe `ajax_collect_payment_confirm` has no already-paid recheck (re-marks/re-logs only; a PaymentIntent can only succeed once → no 2nd charge). | `shortcodes.php:8192` | Add already-paid short-circuit + lock for symmetry. |
| LOW-4 | LOW | `handle_ajax_mark_paid_single` status recheck non-atomic → duplicate "paid" write/note (no card charged — cash/check). | `admin.php:9925-9929` | Recheck in a short lock / confirm `mark_order_paid_manually` is idempotent. |
| LOW-5 | LOW | Add-discount/custom-item double-submit windows. Discount self-heals (`set_discount` delete-then-insert → single row). Custom items are an unconditional INSERT → a double-submit dups the line item. | `class-eem-order-detail-page.php:2121/2249`, `order-adjustments-repo.php:153` | Disable-on-submit / dedup window for custom items. |
| LOW-6 | LOW | `add_component_quantity` **adds** the priced delta onto the stored subtotal; never re-derives `unit_price × qty × nights`, so a pre-existing stored-vs-rate drift (the #90801 case: stored $121 vs rate-implied $135) carries forward and compounds. Data-integrity, not concurrency. | `orders-repository.php` `add_component_quantity:595`, existing-row branch `:645-668` | Recompute the full row as `unit_price × new_qty × nights` after bumping qty (overwrites any intentional manual price override — decide if overrides are supported first), OR a one-time reconciliation for rows where `subtotal != unit_price × qty × nights`. |

**Recommended next action (needs Whitney sign-off — payment-adjacent):** harden **MED-3** and **MED-4**
with the same per-order `GET_LOCK` + in-lock recheck the assignment/refund code already uses. MED-4 is
the only one that can move real money twice; do it one-at-a-time with a live Auth.net test per the
CLAUDE.md payment rule. LOW-6 is the data-drift quirk behind the #90801 rate-vs-subtotal mismatch.
