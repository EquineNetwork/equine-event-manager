# Architecture — Venues & Facility Layout Templates

**Decided 2026-06-12 (Whitney).** Binding design for the **Venue** concept and the **Facility
Layout Templates** that attach to it. Spans **v2** (Templates) and **v3** (Native Events), so
it lives in its own doc to stay the single reference both build phases follow.

---

## 1. The principle

A **Venue is a real-world place** (a fairgrounds, an arena complex) that **owns saved stall/RV
layouts**. It is **source-agnostic** — it is NOT owned by any event source. The three event
sources (TEC, GEMS, Native Events) each just **point at** one canonical Venue; they do not each
get their own competing venue concept in the plugin.

> Mirrors the existing event-source pattern: `EEM_Reservation_Source_Resolver` already
> normalizes *events* across native/TEC/GEMS into one shape. We do the same for *venues*.

```
   TEC venue (name+id) ─┐
   GEMS venue (name)  ──┼──►  EEM_Venue_Resolver  ──►  ONE canonical Venue  ──►  owns Layout Templates
   Native en_venue (v3)─┘                                (plugin-owned)
```

---

## 2. Canonical Venue entity

- **Storage:** a plugin-owned relational table `wp_eem_venues` (NOT `wp_postmeta`, per the
  "WordPress-replaceable" principle — see `ARCHITECTURE-DATA-OWNERSHIP.md`).
  - `id`, `name` (display), `normalized_key` (slugified name for fuzzy matching), `created_at`.
- **Source mappings:** `wp_eem_venue_source_map` — links a source venue to a canonical venue.
  - `venue_id` (FK), `source` (`tec` | `gems` | `native`), `source_venue_id` (string; the TEC
    venue ID / GEMS venue id / native `en_venue` post id), `source_venue_name` (raw, for audit).
  - One canonical venue can have many source mappings (the same physical place comes from
    multiple sources / multiple years).
- **Layout templates:** `wp_eem_venue_layouts` — the saved grids.
  - `id`, `venue_id` (FK), `name` (e.g. "2025 Main Barn Layout"), `layout_json` (the full
    structural layout — stall grid + RV lots/zones + blocked stalls/lots + map geometry; per
    Whitney: **full structural layout, excludes pricing/dates**), `created_at`, `based_on_id`
    (nullable lineage — "cloned from template N").

---

## 3. The resolver (`EEM_Venue_Resolver`)

`resolve( string $source, string $source_venue_id, string $source_venue_name ): int` → canonical
`venue_id`. Logic:
1. **Exact source-map hit** — `(source, source_venue_id)` already mapped → return its venue_id.
2. **Stable-id match within source** absent, try **normalized-name match** against existing
   canonical venues → if a confident single match, propose linking (admin confirms).
3. **No match** → create a new canonical venue + the source mapping.

**The fuzzy-name guard (prevents duplicate "NTR" venues from name drift):** when matching by
name is ambiguous, surface a one-click **"link this event's venue → {existing Venue}"** admin
step rather than silently creating duplicates. Same UX precedent as the event-source linker.

---

## 4. Navigation (locked)

- **One "Venues" page, nested UNDER "Stall & RV Charts"** (Event Manager → Stall & RV Charts →
  Venues). Layouts are stall/RV grids, so they group with charts.
- **Never a second "Venues" menu.** When Native Events (v3) turns on, creating a native venue
  registers/links into the SAME canonical Venues page — no new nav, no duplication.
- **Producers stay Native-Events-only.** A Producer is *who runs the event*; it has no layout.
  Producers live in the Native Events admin (v3), **not** under Venues. Venues = places with
  layouts; Producers = organizers. Kept separate on purpose.

---

## 5. Facility Layout Templates (the feature on top)

Two layers:
1. **Venues** (§2–§4) — the source-agnostic place + layout store.
2. **Template mechanics** — two explicit buttons on the admin builders (Whitney, 2026-06-12):

   **"Save Layout"** — on **BOTH** the **Stall builder AND the RV builder** in the Edit
   Reservation editor (the Stall Assignments / Stall Row Builder section and the RV lot/zone
   builder section). Clicking it saves the current grid to the event's resolved canonical
   **Venue** → writes a `wp_eem_venue_layouts` row (prompts for a layout name). Captures stall
   grid + RV lots/zones + blocked stalls/lots + map geometry (full structural layout).

   **"Load Layout"** — on the **same two builders** when **building a new reservation**. Shows
   the saved layouts for that event's resolved Venue; picking one does **copy-on-use** — deep-
   clones the `layout_json` into THIS reservation (`based_on_id` records lineage). Edits to the
   reservation NEVER mutate the saved Venue layout. (If the venue has no saved layouts yet, the
   button is shown disabled/empty-state, or hidden.)

   Placement note: both buttons sit in the builder section header/toolbar next to the existing
   grid controls, so "Save Layout" / "Load Layout" are right where the admin is already working
   on the grid. **A Venue layout is COMBINED** (locked) — one saved layout carries the full
   structural picture (stall grid + RV lots/zones + blocked stalls/lots + map geometry), so
   "Save Layout" from either builder captures the whole venue and "Load Layout" from either
   restores the whole venue in one action. (Matches Whitney's "full structural layout" +
   singular "Layout".) The button simply appears in both builder sections for convenience; both
   act on the same combined Venue layout.

---

## 6. Build sequencing

- **v2 (now):** build the canonical Venue layer + resolver + the Venues page (under Stall & RV
  Charts) + save/clone template mechanics. TEC + GEMS feed the resolver today.
- **v3 (Native Events):** the native `en_venue` CPT plugs into the resolver as a third source;
  Producers admin added separately. **Zero rework** to Venues because it was built source-agnostic.
- **v3 (postmeta de-coupling):** `wp_eem_venues*` tables are already relational, so they need no
  migration — they're built the right way from the start.

---

## 7. Why this avoids the mess

- One place for layouts (the canonical Venue), so a layout built for a TEC event and reused for
  a GEMS event next year is the *same* venue + layout — no source silos.
- One nav item forever; Native Events can't collide because it feeds the same store.
- Relational tables from day one → consistent with the headless/WordPress-replaceable direction.
- Producers explicitly fenced off so the Native Events nav doesn't muddy the Venue concept.
