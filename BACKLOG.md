# Equine Event Manager — Backlog

Single source of truth for the launch worklist. Updated 2026-06-06.

---

## ✅ Done (recent)

Auto-update (no token) · setup wizard + finish toasts · dashboard checklist reshape
+ create-first-reservation CTA · empty-state CTAs (Create Order / Stall Charts /
Reports) · event-search preload · media-modal CSS fix · stall + RV setup wizards ·
"Uninstall" rename · header URIs · 5-digit order-ID audit · bulk Publish/Draft ·
inactive-processor field locking · Open-Tab/open-invoice confirmed built ·
**publish gate: Numbered stalls require ≥1 row, Mapped RV requires ≥1 lot (v2.7.60)**.

### v2 correctness + polish — shipped 2026-06-06

- **v2 #1 (v2.7.61)** — front/back parity audit. Found the reported "stall/RV show
  when disabled, groups hidden when enabled" was a reservation mismatch (renderer +
  Create Order already gate correctly). Fixed the real bug: group/add-on/pre-entry-only
  reservations now render on the customer form (`other_bookable` gate); gated the
  customer "Group Name" field behind the Group Reservations toggle.
- **v2 #2 (v2.7.62)** — RV Mapped publish gate now also requires ≥1 pricing zone and
  ≥1 zone-assigned lot row.
- **v2 #3 (v2.7.63)** — section open/closed state survives save+reload.
- **v2 #4 (v2.7.64)** — "Tack Stall Selection" admin toggle gates the customer
  tack-stall selector.
- **v2 #5 (v2.7.65)** — stall + RV layout clusters wrapped in a shaded
  `.eem-layout-group` panel (matches front-end "Pick Your Stalls").

### Walkthrough bugs + polish — shipped 2026-06-07

- **(v2.7.69)** — Stall Row Builder rejects overlapping/duplicate stall numbers
  (publish gate + live red warning in the summary); `.eem-layout-group` top padding.
- **(v2.7.70)** — **Removed back-to-back** stall/RV layout (misleading "aisle").
  Rows are now just Barn/Row Name + first/last range. Migration eem-mig-005 split
  existing back-to-back rows into one-sided. Front-end disclaimer: groupings are
  *not* a facility map — see the Stall Map link.
- **(v2.7.71)** — wording: "Barn/Row Name", "barn/row quantities", "X rows/barns";
  Pre-Entry Inventory blank = "Unlimited" placeholder; RV empty-state padding.

---

## 🐴 Tack stalls — shipped 2026-06-07

- **Tack On/Off (v2.7.73, reworked from v2.7.72's 3-mode)** — editor control under
  Blocked Stall Numbers is a simple Off/On. When **On**, the buyer flags a tack
  stall at checkout; the admin assigns/overrides the *actual* tack stall via the
  existing "Mark as Tack Stall" chip on the Stall Chart. `_en_stall_tack_mode` =
  `off`|`customer`; migration eem-mig-006 (old bool → mode). The admin tag-select +
  `_en_stall_tack_admin_stalls` from v2.7.72 were removed.
- **Shavings exclusion (v2.7.72)** — tack stalls excluded from required shavings
  (still pay the normal stall rate). Server `get_tack_stall_count()` + live-total
  `countTackStalls()`; the designated tack stall is written to the Tack Stalls note.
- **"One-sided" preview label removed (v2.7.73)** — leftover from back-to-back.

---

## 🔧 v2 — Polish + smaller features + walkthrough bugs

> **Governing principle (binding):** The **Edit Reservation form is the single
> source of truth.** Every section enable/disable and every field value there
> must drive BOTH the customer event page (`[en_reservation]` shortcode) AND the
> Create Order admin form. Neither downstream surface may show, hide, or default
> anything independently of Edit Reservation.

### Correctness bugs (highest priority)

1. **Front / back / Create-Order parity audit.** Edit Reservation must be the
   single source of truth (see principle above). Known breaks from the walkthrough:
   - Stall Reservations + RV Reservations **disabled** on admin still **render on
     the customer event page**. They must not appear when disabled.
   - Group Reservations **enabled** on admin does **not** render on the customer
     event page. It must appear when enabled.
   - The customer contact-card **"Group Name"** field must show **only** when
     Group Reservations is enabled on admin.
   - Do a full field-by-field, section-by-section parity sweep: enable toggles,
     rates, stay types, inventory modes, zones, add-ons, pre-entries, fees,
     deposits, descriptions — everything on Edit Reservation must reflect on the
     customer form and on Create Order. Build a parity checklist and verify each.

2. **RV Mapped publish-gate — add ZONES requirement.** Row-count gating already
   ships (v2.7.60). Still needed: block publish when Mapped is selected but no
   RV Lot **Zones** exist (and/or lot rows aren't assigned to a zone), with a
   message that points the admin at the Zones step. Zones are easy to miss.

3. **Section card open-state persists across save.** When an enabled section is
   expanded and the admin clicks Update Reservation, the card currently collapses
   back to closed. It should stay open if it was open.

### Editor polish

4. **Tack Stall admin toggle.** Add an on/off control in Edit Reservation ›
   Stall Reservations that governs whether the customer-facing "Using one for
   tack? (optional)" selector appears. Some events don't want customers
   designating a tack stall. (Also a parity item — toggle off ⇒ selector gone on
   the customer form.)

5. **Visually group the dependent layout fields.** Wrap the stall config chain
   (Inventory Type → Customer Selection → Available Stall Inventory → Max Stalls →
   Stall Rows → Blocked Stall Numbers → Stall Map) in a shaded blue-gray panel
   like the front-end "Pick your stalls" card, so it reads as one interdependent
   group. Same treatment for the RV chain (Inventory Mode → Available RV Inventory
   → Max RV Lots → RV Lot Zones → Lot Rows → Blocked RV Lots).

### Carried over from prior v2 — blocked/deferred (need a decision)

6. **Cancellation-policy cleanup** — ✅ **DONE (v2.7.66).** The substantive work was
   already shipped in prior chunks: per-reservation resolver
   (`eem_resolve_cancellation_policy`), editor section, the snapshot migration
   (eem-mig-001, registered + already run on Local), Settings global textarea
   removed (2.3.66), customer checkout reading the per-reservation override. v2.7.66
   stripped the two remaining stale global references in active code (the Settings
   email-preview sample no longer reads the deprecated `cancellation_policy`
   wp_option; the token description points at Edit Reservation). The empty wp_option
   is read-no-write and dropped on uninstall.
7. **Status-badge normalization** — ✅ **DONE (v2.7.68).** Unified all status badges
   to the CSS-backed legacy `eem-status-{slug}` pattern. Bonus: fixed a latent bug
   where the refund/cancel AJAX badge fragments used a BEM class with no CSS (so
   they rendered unstyled). Zero CSS changes; 23/23 consistency smoke.
8. **Order-cancellation email** + cancel action — ✅ **DONE (v2.7.67).** Built the
   whole flow: a "Cancel Order" action (Order Detail More-menu + Orders-list bulk),
   `cancel_order()` repo method (marks cancelled, frees stall/RV inventory, logs to
   activity), and a branded customer cancellation email carrying the reservation's
   cancellation policy. Cancel does NOT auto-refund — payment record is preserved
   for a separate refund. Browser-verified end-to-end + 23/23 smoke.
9. **Bulk "Send Payment Link"** on Orders — ⛔ **Payment-gated** (needs live keys;
   payments moved to LAST per your call).

---

## 🚀 v3 — Major / standalone builds

9.  **`admin-legacy.css` wholesale strip** — port every page off the ~12K-line
    legacy stylesheet, then delete it. Page-by-page, verified.

> ~~Scheduled / recurring report exports~~ — ❌ **REMOVED (won't do).** Reports are
> per-event, on-demand only; no scheduling/cron needed now or planned.

---

## 🗺️ v4 — Stall Mapping — spreadsheet-driven clickable facility maps

> **v4 — its own group (split from event sources 2026-06-07).** Scoped + validated
> but deferred behind launch; not a v1 item. Stall Mapping is now a standalone v4
> initiative; the Native Events / External Feed event-source work is separate (v5).

**Goal:** a true RSNC-style stall map — customers click stalls in their real
physical positions with live availability, neighbors, and aisles visible —
*without* building a CAD/drag-and-drop editor. Reference: `legacy.rsnc.us/
reservations/stalls/reserveStall` (building tabs → per-barn stall chart; dark =
taken, white = available; click to add to reservation).

**Core insight:** a spreadsheet grid *is* a 2D coordinate system. The cell's
**position is the data** — a number in a cell = a stall at that physical spot, a
blank cell = an aisle/gap, a text cell = a landmark (`ARENA`, `WASH`, `OFFICE`).
The admin "builds the map" in a tool they already know; we only write an importer
+ a grid renderer. No canvas library, no per-facility artwork.

**Stall identity stays label-based** → blocked stalls, tack, chart assignment,
orders, and inventory all keep working untouched. The map is a new *view* over the
same data, not a new data spine.

**SCOPE LOCK (decided 2026-06-07):** Phase A **REPLACES the "Pick from layout"
customer-selection mode**, it is not a parallel feature. Today "Pick from layout"
renders the flat numbered-chip grid built from the Stall Row Builder; under Phase A
that branch renders the spreadsheet-driven facility map instead. The
`Customer Selection` control (Quantity vs Pick from layout) stays; only what the
Pick-from-layout branch *renders* changes.

**Option A (LOCKED):** in **Numbered + Pick from layout** mode the **spreadsheet is
the source of truth** for both the stall *labels* AND their *positions* — it
replaces the Stall Row Builder in that mode. The **Stall Row Builder remains for
Quantity mode only** (where you just need a list of numbers + a count, no map).
Labels stay the spine for blocked/tack/chart/orders, so nothing downstream breaks.

**Validated 2026-06-07** against Whitney's real "Montcrief" test sheet (publish-to-
web → `curl -L`/`wp_remote_get` follows the signed redirect → clean CSV →
`str_getcsv` → 21×24 grid → 251 stalls, 0 dupes, 11 landmark types incl. center +
cross aisles). Throwaway parser confirmed the importer logic end to end.

### Sheet authoring guide (how an admin builds the stall-map sheet) — LOCKED 2026-06-07

The importer reads cell **values + positions only** — the CSV export strips all
colors, borders, and merged-cell formatting. So the authoring rules are:

1. **A number = a stall** at that exact grid position. One number per cell.
2. **A blank cell = an aisle / gap.** Leave it empty.
3. **Text = a marked area** (room, arena, wash rack, office).
4. **Never merge cells.** Merged cells collapse to a single top-left value on CSV
   export, so the footprint is lost. Instead, **drag-fill the area's label across
   its whole footprint** (type once, drag the corner). The importer detects the
   maximal **same-label rectangle** (horizontal AND vertical) and renders the room
   at its exact size — incl. tall blocks (e.g. Watt Arena 1×17) and wide strips
   (Wash Rack). A single label cell still works as a 1×1 fallback.
5. **One tab per barn** (tab name = barn name).
6. **Color is cosmetic** — invisible to the system; use it for your own
   readability if you like, but stall-vs-aisle is decided by number-vs-blank.

Validated 2026-06-07 against Whitney's filled-footprint Montcrief sheet — both
mockups (`.mockups/stall_map_event.html`, `stall_map_admin.html`) render every
room block exactly. The mockups are the binding visual spec for the build.

### Phase A — spreadsheet grid + publish-to-web import (the cheap, no-dependency path)

- **Admin workflow:** one Google Sheet, **one tab per barn** (tab name = barn
  name). Numbers where stalls sit, text for landmarks, blanks for aisles. Then
  **File → Share → Publish to web** → public no-auth CSV URL (per tab / `gid`).
- **Plugin import:** paste the published CSV URL (or upload a `.csv`).
  `wp_remote_get()` → parse grid → **snapshot into `_en_stall_map`** (do NOT render
  live from Google — render from our stored copy; "Refresh from sheet" re-pulls).
  Preview the parsed grid before save so typos are caught.
- **Data model:** `_en_stall_map = { barns: [ { name, grid: [[cell,…],…] } ] }`
  where each `cell = { label, type: 'stall'|'landmark'|'gap' }`. Auto-merge
  contiguous same-label landmark cells into one block.
- **Renderer (customer + admin chart):** CSS grid; stall cells interactive +
  painted by live status (available/reserved/blocked/tack — existing data);
  landmark cells static labeled blocks; blanks are gaps; barn tabs across the top.
  When a pick-from-layout reservation has **no map imported yet**, fall back to the
  legacy flat chip grid (or a "map not configured" notice) so the mode never breaks.
- **Rendering decisions (decided 2026-06-07):**
  - **Stall cells keep the existing chip look** (`.eem-stall-box`), just **smaller**
    for density on a wide grid (24+ cols). Same styling = visual consistency.
  - **Admin Stall Chart page reuses the same grid renderer** — admin and customer
    see the identical facility map.
  - **Customer side = full-screen modal** (`.eem-modal`) to fit big layouts: inline
    form shows a compact "N stalls selected" summary + a "Choose your stalls" button;
    the modal holds the map (barn tabs, click-to-select, live status) + a Done button
    that returns picks to the form.
  - **Mobile (DECIDED 2026-06-07):** full-screen modal + **swipe-scroll** to pan
    the full map at a readable chip size — **NO pinch-zoom** (layouts are inherently
    portrait/landscape, so swiping to reach the rest is simpler + more reliable).

- **Locked from the mockup pass (2026-06-07)** — see `.mockups/stall_map_event.html`
  + `stall_map_admin.html` (the binding visual spec, validated vs. Whitney's real
  published Montcrief + Burnett sheets):
  - **Same-label RECTANGLE merge** for marked areas (drag-fill the footprint → exact
    room, horizontal AND vertical: Centennial Room 5×3, Watt Arena 1×17, etc.).
  - **Vertical text** (`writing-mode: vertical-rl`) on tall-narrow blocks so long
    labels (Watt Arena, Concession, Vet Clinic) fit instead of clipping.
  - **4-digit chips**; explicit grid placement; blank cells = empty aisle tracks.
  - **Multi-barn:** one *Publish to web* URL → auto-discover every barn tab (each
    tab's `gid`+name come from the published doc); a tab per barn on the map.
  - **Connection UI** lives on Edit Reservation → Stall Reservations (pick-from-
    layout area): paste the *Publish to web* URL → Connect/Refresh → "✓ N barns
    found" → preview. Snapshots to `_en_stall_map`; "Refresh" re-pulls.
  - **Admin chrome = the existing plugin Stall & RV Charts page** (title
    `.eem-plugin-title`, KPIs `.eem-stall-chart-stat-card`, buttons `.eem-btn`):
    the map is the new "By Location (Map)" view inside that page, not new chrome.
  - **Group color-coding ON the map** (admin): stalls in the same group reservation
    render with a shared group color accent (reuse the existing Show-by-group /
    zone palette), layered on top of the status fill (assigned/tack/blocked).
  - **Build-phase data seeding (DO when the feature is built):** seed richer demo
    data — multiple **group reservations**, **tack stalls**, **blocked** stalls —
    so the map demonstrates every state with realistic data, not just synthetic
    pseudo-random status.

- **PRE-BUILD DECISIONS LOCKED (2026-06-07, Whitney):**
  1. **Stall numbers are globally unique across barns** in an event (Montcrief
     5001–5262, Burnett 1–473). The **label alone identifies a stall** — no
     barn namespacing needed; blocked/tack/orders/inventory all key off the number.
  2. **Every tab in the connected sheet = a barn.** No tab-picker UI; if the admin
     adds a non-map tab it would render as a barn (document this in the connect UI).
  3. **Scope = stalls AND RV lots** — one unified map system; a barn tab can be an
     RV-lot area. (Bigger than stalls-only, but single codebase.)
  4. **Group-contiguous seating is IN this build.** "Generate Assignments" seats
     group members in **adjacent stalls** so groups cluster on the map (colors next
     to each other). Goal: *always keep a group's stalls together.*
  5. **Group identity model (free text is NOT the grouping key):**
     - **Checkout:** free-text Group Name WITH **autocomplete** suggesting groups
       already used for this event (so the 2nd buyer picks "Smith Barn" instead of
       retyping/misspelling). Normalize (trim / collapse spaces / case-insensitive)
       for matching.
     - **Admin is the source of truth:** on the Stall Chart the admin reconciles /
       merges / assigns orders into a **canonical group** (fixes misspellings like
       "Smtih Barn"). The map seats by the **admin-assigned group**, not raw text.
     - Replaces the current free-text-notes grouping (which would split a group on
       any misspelling across separate orders).
  6. **Available Stall Inventory is map-driven (sum across all barns).** Every cell
     with a number counts as one stall; inventory = total stall cells **summed
     across every barn/tab**. In Option A this replaces the row-builder's
     "computed from barn/row quantities" — the imported map *is* the inventory.
     Importer exposes `count_stalls()` (grand total) + `barn_stall_counts()`
     (per-barn). Validated live: Montcrief 262 + Burnett 414 = **676 total**.
  7. **Per-barn stats panel (admin).** Each barn/tab shows its own breakdown —
     **total / available / reserved / tack / blocked** — not just the grand total.
     Surface on the admin map view + the connect preview. Total comes from the map
     (importer); status counts come from cross-referencing assignment data per
     barn. Importer exposes `barn_stats(snapshot, status_map)` (pure aggregation;
     the admin renderer builds the status_map from orders).
  8. **Customers display as "Last Name, First Name" everywhere (PLUGIN-WIDE).**
     Not just v4 — a cross-cutting convention (orders, customer list/profile, stall
     charts, assign menus, receipts). Add a shared formatter; apply at all display
     points (own task). The admin map's assign control is a **searchable typeahead**
     (events have hundreds of customers), not a plain dropdown.
- **Conventions (v1):** values + positions only — NOT fill color / borders /
  merged cells (CSV export drops formatting; merges blank all but top-left).
  Reading fill-color for zones is a later add.
- **Prefer CSV** (pure-PHP parse, zero deps). `.xlsx` upload would need
  PhpSpreadsheet (a composer dependency) — only add if explicitly wanted.

### Phase B — image-overlay fidelity upgrade (optional, later)

- For facilities that want the exact CAD drawing: admin uploads the floor-plan
  image (reuses the existing Stall Map upload) as a background, positions numbered
  cells on top (row-placement tool: define a label range, click start/end, auto-
  distribute, drag to nudge). Same label-based identity + live-status renderer.
- Bigger lift (drag UI, maybe a light SVG/canvas layer); only justified when a
  facility needs pixel-fidelity to the real building. Grid (Phase A) covers ~90%.

### Phase C — native in-plugin grid builder (eliminate the Google Sheets dependency) — FULLY SCOPED 2026-06-07

> **Whitney's ask:** "A way to cut out Google Sheets dependency. The plugin
> generates a grid where admin can select and drag to populate rows with numbers
> or names to make the maps we use Google Sheets for. Admin sets 'Zones' which
> become the tabs they modify using the drag-and-select system." Future to-do —
> NOT scheduled yet. Phase A (sheet import) ships first and stays as a fallback.

#### 1. Goal & why it matters
Replace "paste a Published Google Sheet URL" with an in-plugin spreadsheet-lite
canvas where the admin builds the facility map directly in the editor. Removes
the Publish-to-web setup friction (the #1 onboarding hurdle), the Google-account /
sharing-settings dependency, and the import round-trip. Instant edits, everything
in WP.

#### 2. The leverage — the back end is ~90% already built
The builder writes the **identical snapshot shape** the importer produces:
`{ source, synced_at, barns:[{ name, kind, rows, cols, grid:[[{type,label}]] }] }`,
`type ∈ {stall, gap, landmark}`, stored on `_en_stall_map` / `_en_rv_map`. So
**every consumer keeps working unchanged**: customer picker, admin By-Location
map, chart matrix, config routing (stall_units / rv_units / zones / barn_map),
inventory, auto-assign, group-contiguity, validators. Phase C is purely a new
**authoring front-end** for an existing, proven data model. The entire cost is
the interactive grid-editor JS + a thin save endpoint.

#### 3. Feature inventory (what the editor must do)
- **Zones = tabs.** Add / rename / delete / reorder named zones. Stall side: a
  zone is a barn ("Montcrief"). RV side: a zone is both the tab AND the pricing
  zone ("Red Lot") — one name, and it auto-feeds the RV Lot Zones pricing list
  (the auto-populate wiring already shipped in the RV two-control work).
- **Per-zone grid canvas.** Resizable grid (add/remove rows + cols, with row/col
  insert + delete handles). Each cell is empty (gap/aisle), a number (stall/lot),
  or text (landmark). Cell classification reuses `classify_cell()` semantics.
- **Drag-select.** Pointer-drag a rectangle of cells; visible highlight; touch
  support (tablet).
- **Fill-series (the headline interaction).** With a rectangle selected, fill
  with an auto-incrementing sequence: start value + step + traversal order.
  **DECIDED 2026-06-07 (Whitney):** ship **two directions only — L→R row-major
  and top→bottom col-major** — those match how the existing Google-Sheet maps are
  already numbered. R→L and serpentine are dropped from v1 (revisit only if a real
  facility needs them). Prefix-aware: increments the numeric tail and preserves
  prefix + zero-padding ("A-01"→"A-02", "Y1"→"Y2"). Option to skip or fill gap
  cells inside the rectangle.
- **Single-cell typing.** Click a cell, type a number/label or landmark text.
- **Landmark regions.** Select a rectangle + type a label → all cells get that
  landmark label; the existing same-label rectangle-merge + vertical-text renderer
  handles display (no merge UI needed; tall-narrow → auto vertical text).
- **Gap/erase brush.** Clear cells back to aisle.
- **Live validation.** Highlight duplicate labels as the admin builds (stall:
  globally unique across barns; RV: zone-qualified so cross-zone repeats OK — the
  exact rule the `target==='rv'` dupe-skip already encodes).
- **Preview customer view.** A button that renders the same map the customer sees
  (reuse the picker renderer) so the admin verifies before saving.
- **Load existing snapshot for editing.** The builder hydrates from whatever is
  already stored in `_en_stall_map` / `_en_rv_map`. Because every map the
  Google-Sheet importer ever produced is ALREADY persisted as a snapshot in
  post-meta (it was never a live fetch), existing sheet-connected reservations
  open straight into the builder for editing — **no migration, no data loss, no
  re-import.** This is what makes a clean Sheets removal safe.

#### 4. Build plan (slices, with sizing)
- **C1 — Data + save layer** *(small-med; mostly back end).* Serialize the builder
  grid ↔ snapshot shape; AJAX `eem_map_builder_save` writing `_en_stall_map` /
  `_en_rv_map` with `source='builder'` + the right `kind`; reuse `find_duplicate_labels`
  (+ the rv skip). Smoke the round-trip (grid → snapshot → grid).
- **C2 — Grid canvas core** *(medium; foundational UI).* Render a resizable DOM
  grid per zone; cell state model (gap/stall/landmark + label); add/remove rows &
  cols; click-to-type a single cell. DOM grid (not canvas) — 676 cells is fine;
  add virtualization only if a facility needs thousands.
- **C3 — Drag-select + fill-series** *(large; the core interaction + trickiest JS).*
  Pointer-event rectangle selection + highlight; the fill-series engine (start /
  step / traversal incl. serpentine, prefix-aware); gap brush; landmark-region
  fill. Touch support.
- **C4 — Zones/tabs** *(medium).* Zone add/rename/delete/reorder; tab switcher;
  per-zone grids; RV zone → pricing auto-feed; duplicate-zone (copy a barn layout).
- **C5 — Editor integration + Sheets removal** *(medium).* Replace the
  "Interactive Stall Map" / "Interactive RV Map" Google-Sheet connector slots
  (paste-URL + Refresh) with a "Build / Edit Map" button opening the builder;
  built-status summary ("✓ 2 barns · 676 stalls"); preview-customer-view. **Rip
  out** the Google-Sheet connector UI + the importer's live-fetch path
  (`EEM_Stall_Map_Importer` fetch/parse, the connect AJAX, the URL meta). Keep the
  snapshot read/write helpers + the `_en_stall_map` / `_en_rv_map` shape untouched
  so stored maps and all consumers keep working.
- **C6 — Polish + verify** *(medium-large).* Undo/redo (command history — drag-fill
  mistakes are common, so this is near-essential not optional); live dup-label
  highlighting; large-grid performance pass; touch QA; full browser verify against
  a rebuilt Montcrief/Burnett + RV map; smokes.

#### 5. Technical decisions (recommended)
- **Vanilla JS, no grid library.** Matches the plugin's no-dependency ethos;
  avoids Handsontable/AG-Grid weight + licensing. The interaction set is bounded
  (select, fill, type, resize) — buildable cleanly in vanilla.
- **DOM grid over canvas.** Simpler hit-testing, text, accessibility; fast enough
  at facility scale (hundreds–low-thousands of cells).
- **Builder is its own JS module** in `admin.js` (or a new `map-builder.js`
  enqueued only on the editor), not inline — it's large + interactive.
- **Undo/redo in v1.** A drag-fill editor without undo is frustrating; include a
  bounded command stack from the start (don't defer to "polish").

#### 6. Kickoff decisions — ALL SETTLED 2026-06-07 (Whitney)
- **Traversal directions:** L→R row-major + top→bottom col-major **only**. Matches
  the existing maps. R→L + serpentine dropped from v1.
- **Fill across gap cells:** **skip gaps** by default; per-fill toggle to number
  through when wanted.
- **Shrink that orphans labelled cells:** **confirm first** — warn "This removes N
  labeled stalls" and require confirmation before deleting.
- **Google Sheets after builder ships:** **REMOVE entirely** (revised 2026-06-07
  after Whitney saw the prototype — supersedes the earlier "demote to seed").
  The native builder is the ONLY map authoring path; it shows when Inventory Type =
  **Mapped**. Rip out the paste-URL connector, the Refresh action, the importer's
  live-fetch/parse, and the Settings sheet-URL plumbing. Existing maps survive
  because they're already stored snapshots (see "Load existing snapshot" above) —
  no migration. Keep `EEM_Stall_Map_Importer`'s snapshot read/write + the meta
  shape; only the Google-fetch surface is removed.
- **Touch:** **desktop-first** — optimize for mouse drag-select; touch usable but
  not pixel-perfect. (Trims C3/C6 effort.)

No open questions remain — Phase C is fully spec'd and ready to schedule.

#### 7. De-scoped (explicitly NOT in Phase C)
- Pixel-perfect CAD / image-overlay (that's Phase B).
- Angled rows / curved aisles / non-uniform cell sizes (flat grid only — same
  limit as the sheet path).
- Real-time multi-admin collaboration.
- Manual cell-merge UI (the renderer auto-merges same-label landmarks).

#### 8. Effort estimate
Substantial — comparable to a multi-slice v4 chunk, **larger than any single v4
slice, smaller than the whole v4 feature**. The back end is nearly free; the cost
concentrates in **C2 + C3** (the spreadsheet-lite editor) and **C6** (undo +
polish). Order-of-magnitude: a few focused sessions. Highest-risk slice is C3
(the fill-series + pointer interaction); lowest-risk is C1 (data round-trip).

**Why it's worth it:** removes the single biggest setup-friction point for new
admins, keeps the whole map lifecycle inside WordPress, and rides entirely on the
data model + renderers already shipped in v4. Phase A remains the quick path until
this lands.

### Honest limits (both phases)

- Flat grid can't express angled rows / curved aisles / non-uniform stall sizes.
  Acceptable — most stall charts are grid-ish.
- Phase A is a clean **schematic**, not artful CAD; fine for *function* (click your
  stall, see availability + neighbors), not pixel-perfect to the building.

### Test fixture (prep)

- Build a representative multi-tab Google Sheet from the RSNC Burnett chart (or a
  synthetic facility) to exercise the importer once Phase A lands. (Claude can
  generate the grid as a pasteable CSV/TSV; it cannot write into a Google Sheet
  directly — no Sheets connector + the browser integration is read-only.)

---

## 🧭 v5 — Alternate event sources

11. **Native Events source completion** (~1,500 LOC; in-plugin
    `en_event` / `en_venue` / `en_producer`).
12. **External Feed URL source** (external JSON/XML endpoint; currently "Coming Soon").

> v1 event source: **The Events Calendar (TEC)** is the only fully-working source.
> Native + Feed are deferred to v5 (split from Stall Mapping, which is its own v4).

---

## 💳 LAST — Payment block (deferred until accounts are set up)

**Whitney:** Finish Stripe live account (bank/verification) · enter Live keys +
webhook secret · Authorize.net setup · one live test charge (end-to-end + webhook
reconcile).

**Then build:** Bulk refund vs live Stripe · card brand/last4 capture →
re-enable Payment Details card · Authorize.net full charge flow · refund
confirmation email.
