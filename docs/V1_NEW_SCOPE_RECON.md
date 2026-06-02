# V1 New Scope — Recon & Sequencing

**Source:** `BUNDLE_COMBINED_V1_NEW_SCOPE.txt` (strategic-chat additions, 2026-06-01).
**Status:** Recon + roadmap/decisions update only. **No implementation yet** — Whitney
confirms the sequence before any feature code begins.
**Author:** Build chat, 2026-06-01.

> This document is the authoritative recon for the new V1/V1.1/V2 scope. It audits
> current code state (file:line), proposes a commit sequence, and lists the
> architectural questions that need Whitney's decision before code starts.

---

## TL;DR — Top findings / surprises

1. **The Scenario-B migration target is NOT `_en_inventory_mode`.** The bundle assumed
   existing reservations carry `_en_inventory_mode = 'bulk' | 'mapped'`. They don't. The
   real key is **`_en_stall_selection_mode`** with values **`'quantity'` | `'exact_map'`**
   (`admin/class-eem-reservation-editor-page.php:804`, read at
   `public/class-equine-event-manager-shortcodes.php:1479-1481`). The editor UI labels it
   "Inventory Mode" with bulk/mapped buttons but writes `quantity`/`exact_map`. The
   migration plan below targets the **real** key. This correction matters — a migration
   written against `_en_inventory_mode` would silently no-op and leave every reservation
   un-migrated.

2. **Part 4 (venue/organizer unlink) is ALREADY DONE — skip it.** In the customer event
   hero, venue and organizer names already render as **plain `esc_html` text, not links**
   (`includes/class-equine-event-manager-events.php:3181` venue, `:3190` organizer). Phone
   (`tel:`) and email (`mailto:`) are still links (`:3132`, `:3136`); the Directions
   button is a real Google Maps link (`:3100`, `:3203-3205`). No work needed.

3. **C9 Customer Profile shipped this session — richer than the "stub" the bundle
   envisioned.** `EEM_Customer_Profile_Page` (v2.4.2) already renders a full read-only
   profile (stats, order history, reservation history, activity log, internal notes),
   keyed by **`customer_email`** (not `customer_id` — there is no customer entity, per the
   read-only-aggregate decision Whitney locked earlier today). So the **only net-new V1
   "Customers" work is the top-level menu + the Customers *list* page** — the profile
   destination already exists and exceeds the stub spec. The list page can reuse
   `EEM_Customer_Profile_Repo`'s aggregation pattern wholesale.

**Bonus surprise:** there is **no "Group Name" free-text field** today. The existing
"Group Reservation" toggle + rider-count + rider-names (first/last) is a *different*
feature (one order covering multiple riders). D2's "Group Name" is a new, independent
free-text tag for clustering *separate* orders — net-new but small.

---

## PART 1 — Current code state per V1 item

### Scenario B — Inventory model split
**Current:** single setting `_en_stall_selection_mode ∈ {quantity, exact_map}`.
- Write: `admin/class-eem-reservation-editor-page.php:804` (strict-validated).
- Editor UI: `templates/admin/reservation-editor/_section-stall.php:258-293` — "Inventory
  Mode" with `data-mode="bulk"` / `data-mode="mapped"` buttons → hidden input
  `name="stall_selection_mode"`.
- Customer gate: `public/class-equine-event-manager-shortcodes.php:1481`
  (`if ( 'exact_map' === $eem_stall_mode )`) decides picker-vs-quantity.
- Related keys already present: `_en_stall_rows` (Numbered identities — the row builder,
  `:187`, structure = `{name, layout, first/last | top_first/top_last/bot_first/bot_last}`),
  `_en_stall_inventory` (bulk total count, `:6221`), `_en_stalls_enabled` (master toggle),
  `_en_blocked_stalls`.

**Net-new:** split one setting into two (`Stall Inventory Type` + `Customer Selection`);
add the currently-impossible third combination **Numbered + Quantity** (admin assigns
post-purchase); migration; editor UI rework; customer-gate rewrite. **Migration-bearing —
highest risk item.**

### D1 — Single-buyer contiguous assignment
**Current:** auto-assign fills **first-N-available in pool order**, no adjacency preference
(`includes/class-equine-event-manager-orders-repository.php:644-657`
`fill_remaining_chart_units`; pool built ascending by `expand_v1_stall_rows`). A single
buyer assigned into an empty/sequential pool **does** get contiguous numbers; with gaps,
they get the first available (not guaranteed adjacent).
**Net-new:** none required for V1 if "first-available, usually-contiguous" is acceptable.
**Flag:** true contiguity *guarantee* would need an adjacency-preferring allocator (small,
optional). Recommend accept current behavior for V1.

### D2 — Group Name (informational only)
**Current:** no free-text "Group Name" field exists. Group machinery today =
`group_reservation_enabled` toggle (`shortcodes.php:913`), `group_rider_count` (`:932`),
`group_riders[]` names (`:1897-1908`), stored as notes lines (`:3149-3168`); `Group` type
badge set when notes match `Group Reservation: Yes`
(`orders-repository.php:699-701`, `:738-740`, regex `:2038-2042`).
**Net-new (small):** add optional free-text "Group Name" on checkout; persist as a notes
line (e.g. `Group Name: …`); display under customer name on Stall Charts pills + a
"Show by group" filter chip. Stall pills currently show **customer_name only**
(`admin/class-equine-event-manager-admin.php:4174-4185`; by-customer rows `:4237-4247`).

### H — Tack stall designation
**Current:** nothing. Assignments stored as the notes line `Assigned Stall Units: …`,
parsed by `parse_assigned_units_string` (admin class). No per-stall metadata exists.
**Net-new (largest feature):** per-stall `is_tack` flag, per-reservation tack pricing
setting, checkout designation (pick-from-layout), admin chart mark/unmark, split line
items, summary recalc, visual indicator, filter chip. See Part 5 for the data-model rec.

### F — Special Requests visibility (polish)
**Current:** field rendered (`shortcodes.php:974-985`, `name="notes"`), stored in order
notes, extracted by `get_special_requests_from_order_notes` (`:5263`). **NOT surfaced on
Stall Charts** (verified: zero `special_request` references in the chart render methods).
**Net-new (small):** surface the existing text on customer pills (tooltip) and/or the
Assignment Issues card. Same render area as D2's group-name display.

### Customers menu + Customers list page
**Current:** no top-level "Customers" menu, no list page. BUT the profile destination
exists: `EEM_Customer_Profile_Page::render` (slug `equine-event-manager-customer`, hidden
submenu, `?customer_email=`), backed by `EEM_Customer_Profile_Repo` (aggregates orders by
email). Customer-name links already point there from Orders list + Order Detail.
**Net-new:** top-level menu item; a Customers **list** page (aggregate all distinct emails
→ Name (Last, First) | Email | Total Orders | Total Spent | Last Activity; sortable;
search; filter). Reuses the existing repo aggregation pattern.

### Customer Profile stub
**Current:** **already shipped, richer than stub** (see TL;DR #3). **Net-new: none for V1**
beyond pointing the new Customers-list rows at the existing `?customer_email=` URL.

### Part 4 — Venue/organizer unlink
**Current:** **already plain text** (see TL;DR #2). **Net-new: none.** Verify-and-close.

---

## PART 2 — Recommended V1 commit sequence

Ordered by dependency, risk isolation, and independent browser-verifiability. Low-risk
isolated wins first; the migration-bearing chunk (B) alone; the largest new feature (H)
last and only after B is stable.

1. **Part 4 — verify & close** (already done; confirm in browser, note in Roadmap). *0 code commits.*
2. **F — Special Requests visibility on Stall Charts.** Isolated, additive, browser-verifiable. *Complexity 2.*
3. **D2 — Group Name (informational).** Checkout field → notes → Stall Charts pill display + "Show by group" filter chip. Shares the pill-render area with F (do adjacent). *Complexity 3.*
4. **Customers menu + Customers list page.** Reuses `EEM_Customer_Profile_Repo`; profile already exists. Isolated, no migration. *Complexity 3.*
5. **Scenario B — inventory model split + migration.** ⚠️ Migration-bearing; do **alone**, never bundled. Sub-commits: (5a) new meta keys + version-gated one-time migration + backward-compat resolver; (5b) editor two-control UI; (5c) customer-form gate rewrite; (5d) verify (incl. D1 contiguity check folds in here). *Complexity 4-5.*
6. **H — Tack stalls.** After B is stable (tack flow differs by Customer Selection mode). Sub-commits: (6a) per-stall metadata + per-reservation tack pricing field; (6b) checkout designation (pick-from-layout) + summary recalc; (6c) admin chart mark/unmark + split line items; (6d) visual indicator + "Tack Stalls" filter chip; (6e) verify. *Complexity 5.*

**Per-phase commit count (rough):** F=1, D2=1-2, Customers=2-3, B=3-4, H=4-6. **Total V1
new-scope ≈ 11-16 commits.** (Excludes the already-shipped C9 profile and the
already-done Part 4.)

These slot **alongside** the existing remaining roadmap, not instead of it: C13/C14
(payment — gated on Whitney's approval), C16 polish (incl. the Part-3 repo cleanup), and
the pending browser visual-verify of C9 + C15.

---

## PART 3 — Complexity matrix

| Item | Net-new? | Risk | Migration? | Browser-verifiable alone? | Complexity (1-5) | Commits |
|---|---|---|---|---|---|---|
| Part 4 unlink | No (done) | — | No | Yes | — | 0 |
| F — Special Requests | Small | Low | No | Yes | 2 | 1 |
| D2 — Group Name | Small | Low | No | Yes | 3 | 1-2 |
| Customers menu+list | Medium | Low | No | Yes | 3 | 2-3 |
| Scenario B split | Large | **High** | **Yes** | Yes (after migration) | 4-5 | 3-4 |
| D1 contiguity | None (verify) | Low | No | Yes | 1 | 0-1 (folds into B) |
| H — Tack stalls | Large | Med-High | Per-stall meta | Yes | 5 | 4-6 |

---

## PART 4 — Scenario B migration plan

**Corrected source key:** `_en_stall_selection_mode ∈ {quantity, exact_map}` (NOT
`_en_inventory_mode`).

**New meta keys:**
- `_en_stall_inventory_type ∈ {quantity_only, numbered}`
- `_en_stall_customer_selection ∈ {quantity, pick_layout}`

**Mapping (old → new):**
| Old `_en_stall_selection_mode` | `_en_stall_inventory_type` | `_en_stall_customer_selection` |
|---|---|---|
| `quantity` (bulk) | `quantity_only` | `quantity` |
| `exact_map` (mapped) | `numbered` | `pick_layout` |
| *(absent/default)* | `quantity_only` | `quantity` |

The third valid combination **`numbered` + `quantity`** (admin assigns post-purchase) is
**not produced by migration** — it's only reachable via the new editor UI going forward.

**Migration mechanics (recommended):**
- **Version-gated one-time migration** in the activator/upgrade path (deterministic; same
  pattern as the existing reservation-title migration). Iterate every `en_reservation`
  with `_en_stall_selection_mode` set; write the two new keys per the table.
- **Preserve the legacy key** (`_en_stall_selection_mode`) as a read fallback — do not
  delete it in V1.
- **Backward-compatible resolver:** a single helper returns `(inventory_type,
  customer_selection)` preferring the new keys, else deriving from the legacy key, else
  default `(quantity_only, quantity)`. All read sites (customer gate `:1481`, chart config,
  reports) go through the resolver.

**Gate rewrite:** the customer picker (`shortcodes.php:1481`) must show only when
`customer_selection === pick_layout` AND `inventory_type === numbered` AND `_en_stall_rows`
is non-empty — otherwise render the quantity input.

**Risk on existing data:** low if the migration targets the correct key and the resolver
defaults safely. The audit's calibration: "Numbered" is already implied by non-empty
`_en_stall_rows`; "Quantity-only" uses `_en_stall_inventory`. Test on the local seed
(`tools/seed-demo-data.php` writes `exact_map` + rows at `:229`/`:247`) before any staging
run; assert every seeded reservation resolves to the expected pair and the customer form
still gates correctly.

---

## PART 5 — Tack stall data-model recommendation

**Per-stall `is_tack` boolean, regardless of pricing** (per the bundle's "future
flexibility without re-migration" note).

**Storage:** mirror the existing assignment storage. Assignments live as the order-notes
line `Assigned Stall Units: 100,101,102`. Recommend a **parallel notes line
`Tack Stalls: 102`** (the subset of assigned units flagged tack), parseable by the same
`parse_assigned_units_string` helper. This reuses the established parse/serialize path and
keeps tack state co-located with assignment state. (Alternative: a structured
`_en_order_tack_units` meta — cleaner typing but a new storage path + new consumers;
recommend the notes-line for consistency unless Whitney prefers structured.)

**Per-reservation pricing setting (new editor fields, Stall Reservations section):**
- `_en_stall_tack_pricing_mode ∈ {same, discounted, free}` (default `same`)
- `_en_stall_tack_price` (decimal; used only when `discounted`)

**Split line items:** the line-item builder (shared by receipt/email/order-detail) splits
the stall purchase into regular vs tack counts when tack price ≠ regular price, e.g.
`2 regular stalls @ $50 + 1 tack stall @ $25`. When pricing mode = `same`, a single line
with a tack indicator is fine.

**Checkout recalc (pick-from-layout):** extend the existing order-summary recalc JS so
toggling a selected stall's tack flag applies the price delta live. (The recalc logic is in
the shortcodes JS; flagged for extension — exact hook to be located at implementation
time.)

---

## PART 6 — Customers menu architecture

**Data source:** aggregate across orders via `EEM_Orders_Repository::get_orders()`, grouped
by email — exactly what `EEM_Customer_Profile_Repo` already does for one email; generalize
to "all distinct emails." No new table (consistent with the read-only-aggregate decision).

**Performance:** grouped orders are built once per request and cached. A customers list is
O(orders) grouping in memory. **For launch scale (RSNC ≈ hundreds of customers) this is
fine** — render all, client/JS-sortable, simple search. **Flag pagination + a cached
customer index as a V1.1 optimization** if customer counts grow into the thousands; do not
build that perf work for V1.

**URL pattern:** keep the **existing email-keyed** route
`?page=equine-event-manager-customer&customer_email=…` (already built in C9). The bundle's
`?customer_id=N` does **not** apply — there is no customer entity. Customers-list rows link
the name to that URL. This also satisfies the "customer name = link" standing rule with a
real destination.

**Columns (proposed):** Name (Last, First) | Email | Total Orders | Total Spent | Last
Activity. Default sort Last Name A→Z. Search by name/email. (All derivable from the repo's
existing per-customer aggregation.)

---

## PART 7 — Open architectural questions (need Whitney's decision before code)

1. **C9 reconciliation (most important):** Accept the already-shipped read-only profile
   (v2.4.2) as the V1 deliverable — exceeding the "stub" spec — and scope V1 "Customers" to
   **only** the top-level menu + list page? (Recommended. Avoids rebuilding a thinner stub.)
2. **Scenario B migration timing:** version-gated **one-time** migration on plugin update
   (recommended, deterministic, contract-safe), vs lazy migrate-on-read?
3. **Tack stall storage:** notes-line `Tack Stalls: …` (reuses existing parse helpers,
   recommended) vs a structured `_en_order_tack_units` meta?
4. **Tack pricing line-item display:** confirm the split format
   (`2 regular @ $50 + 1 tack @ $25`) on receipt / email / order detail, and that
   `same`-price mode shows a single line + indicator.
5. **D2 Group Name vs existing Group Reservation:** confirm the new free-text "Group Name"
   is **independent** of the existing "Group Reservation" multi-rider feature (a tag to
   cluster *separate* orders), and that both coexist.
6. **Customers list scale:** confirm in-memory aggregation (no pagination perf work) is
   acceptable for launch, with pagination deferred to V1.1.
7. **D1 contiguity:** accept current first-N-available behavior for V1 (single early buyer
   already gets adjacent), or add an explicit adjacency-preferring allocator? (Recommend
   accept for V1.)

---

## Conflicts with in-flight work / blockers

- **No conflict with C13/C14** (payment — separately gated on Whitney's approval).
- **Scenario B vs C10 stall form:** B reworks the customer stall gate
  (`shortcodes.php:1481`) and the editor stall section — the same files C10 touched. No
  active C10 work is in flight, but B should land as its own isolated chunk to keep that
  surface reviewable.
- **H (tack) depends on B** (tack flow branches on Customer Selection mode) — do not start H
  until B is merged and verified.
- **Pending visual-verify** of C9 (Customer Profile) and C15 (Reports) is still open and
  unrelated; doesn't block this scope but should be cleared before launch.
