# Scope: Sheets & Results — Draw Sheets & Results PDF System

## Overview
Build a Sheets & Results system that ties draw sheet and result PDFs to Event records in the Equine Event Manager (EEM) WordPress plugin. Admin uploads once, public pages render automatically. No manual page building, no manual link creation, no duplicating pages.

## Reference Mockups
All four screens are in `results_drawsheets_system.html`. These are the design source of truth — match them exactly for layout, colors, typography, and component patterns.

The existing event list shortcodes are also being modified as part of this scope (see Shortcodes section). The mockup file `results_drawsheets_system.html` Screen 3 shows the updated event card designs — use those as the reference for modifying the existing shortcode output, do not build new shortcodes from scratch.

---

## Screen 1 — Admin: Sheets & Results Page

### Registration
- Register a single top-level EEM submenu page called **Sheets & Results**
- Do NOT register separate Draw Sheets and Results menu items — one page, two tabs
- Slug: `eem-sheets-results`

### Event Selector
- Dropdown at top of page listing all EEM events (post_type=en_event)
- Selecting an event reloads the page with that event's documents via `?page=eem-sheets-results&event_id=X`
- Show event date and status pills next to the selector (Ongoing = green, Upcoming = blue)

### Tab Structure
- Two tabs: **Draw Sheets** (always first) and **Results**
- Default active tab: Draw Sheets
- Tab switching via URL param `?tab=drawsheets` or `?tab=results`
- Each tab shows document count badge

### Draw Sheets Tab
Documents are grouped by discipline. Disciplines are sourced from the event's registered discipline taxonomy (`en_discipline`).

Each discipline group has:
- Discipline name + file count
- **Add File** button (dashed border) that toggles an inline add panel

Inline add panel fields:
- Label (text input, e.g. "Open 5D Long Go")
- Round (select: 1st Go, 2nd Go, Short Go, Finals, Average, Qualifications, Top 15 Points, Other)
- Date (date input)
- PDF File (upload button — uploads to WordPress Media Library, stores attachment ID)
- Save & Publish button / Cancel button

On save, the draw sheet entry is stored and a **mirrored placeholder row is automatically created in Results** with the same label, discipline, round, and date — but no PDF attached yet.

Each existing draw sheet row shows:
- PDF icon, label, date meta
- Live status badge
- Download, Replace PDF, and Delete action buttons

### Results Tab
- Rows are **auto-generated from draw sheet entries** — admin cannot manually add result rows
- Rows with no PDF uploaded yet: amber "Upload Result PDF" button inline, amber background tint
- Rows with PDF uploaded: Live status badge, download/replace/delete actions
- Discipline header shows **"X of Y uploaded"** count
- Disciplines with no draw sheets show empty state: "No draw sheets added yet. Results rows appear automatically once draw sheets are uploaded."
- Files go live immediately on save — no draft/publish workflow

### Data Model
New custom table or CPT meta — store per event:

```
eem_sheet_entry {
  id
  event_id         (post ID)
  discipline       (term ID from en_discipline)
  label            (text)
  round            (enum)
  date             (date)
  drawsheet_pdf    (attachment ID, nullable)
  result_pdf       (attachment ID, nullable)
  created_at
  updated_at
}
```

Draw sheet and result are two fields on the same row — not two separate records.

---

## Screen 2 — Admin: Event Edit Page — Sheets & Results Section

Add a **Sheets & Results** collapsible section card to the Event edit page (add_meta_box), consistent with the existing section card pattern in the plugin (Space Grotesk title, orange section icon, same field-row styles).

### Section Content
- Two tabs: **Draw Sheets** (first) and **Results** — same tab pattern as Screen 1
- Compact view: shows discipline groups with file rows, Add File button per discipline
- Add File opens the same inline panel as Screen 1
- Results tab shows the auto-mirrored rows with Upload Result PDF inline action
- **"Manage in Sheets & Results"** button in section header links to the full Screen 1 page filtered to this event
- Right rail card: **Sheets & Results** summary showing Draw Sheets count, Results count, note that both buttons appear on the public listing, and "Open Sheets & Results →" link

### Behavior
- Saving a draw sheet entry here uses the same backend as Screen 1 (same data model, same auto-mirror logic)
- No separate save needed — section saves with the event on Update Event

---

## Screen 3 — Public: Event List Shortcodes

### Modify Existing Shortcodes
The existing EEM event list shortcodes render event cards. **Modify the existing shortcode output** to match the updated card designs in Screen 3 of `results_drawsheets_system.html`. Do not build new shortcodes — update the existing render functions.

### Two Card Variants
The shortcode must support both card styles via a `show_flyer` attribute:

**No Flyer (default):**
- Left: dark date bar (navy for upcoming/ongoing, gray #374151 for past) with month, day, year
- Right: event title, status pill, venue + date meta, action buttons

**With Flyer (`show_flyer="true"`):**
- Left: 140px wide thumbnail using the event featured image
- Countdown badge overlaid top-left: "X days" for upcoming, green "Ongoing" badge for ongoing events, no badge for past
- Right: same content as no-flyer variant
- Graceful fallback: if no featured image, show a dark gradient placeholder

### Conditional Buttons
Buttons appear on event cards **only when the corresponding content exists**:
- **Draw Sheets** button (teal outline style): shown when the event has ≥1 draw sheet PDF uploaded
- **Results** button (navy filled style): shown when the event has ≥1 result PDF uploaded
- Neither button appears if no files have been uploaded for that event
- For past events, the primary CTA changes from "Reserve Now" to "View Event"

### Four Shortcode Filter Variants
Implement these as either separate shortcodes or a single shortcode with a `filter` attribute. Recommend single shortcode approach:

```
[eem_events]                    — all events (default)
[eem_events filter="upcoming"]  — start date in the future
[eem_events filter="ongoing"]   — start date past, end date in the future
[eem_events filter="past"]      — end date in the past
```

The public Results/Draw Sheets pages use:
- `[eem_events filter="past,ongoing"]` — shows events that could have results
- `[eem_events filter="upcoming,ongoing"]` — shows events that could have draw sheets

Additional supported attributes (carry over from existing shortcode if already present, add if not):
```
[eem_events filter="upcoming" show_flyer="true" limit="10" producer="123" venue="456"]
```

### Status Pill Logic
- Upcoming: blue pill, "Upcoming"
- Ongoing: green pill, "Ongoing"  
- Past: gray pill, "Past"
- Status is calculated server-side from event start/end dates at render time

---

## Screen 4 — Public: Per-Event Sheets & Results Page

### Page Generation
- Each event gets a rewrite URL: `/events/{event-slug}/sheets-and-results/`
- Register rewrite rule on plugin activation
- Template rendered by plugin (not a WordPress page/post)

### Hero Section
- Dark navy background (#031B4E)
- Breadcrumb: Events / {Event Name} / Sheets & Results
- Event title (Space Grotesk, 26px, white)
- Meta row: date range, venue + city/state, status with contextual copy ("Ongoing — results updating as rounds complete" / "Upcoming" / "Past")
- Two tabs pinned to bottom of hero: **Draw Sheets** (first, default active) and **Results**
- Active tab underline uses #C8FF00 (neon lime accent)
- Tab count badges

### Tab Content
Both tabs render the same structure:
- Discipline group heading (uppercase, gray, with full-width rule)
- Day label (date, uppercase, small, with partial rule)
- Result items: PDF icon, label, meta (round + "PDF"), chevron arrow — entire row is a link that opens the PDF
- Empty state per discipline: icon + "No [draw sheets/results] posted yet" + contextual subtext
- "Coming soon" pill on result rows where the placeholder exists but no PDF uploaded yet (shown only on Results tab, not Draw Sheets)

### Page Does Not Require WordPress Page
The page is registered via rewrite rules and rendered entirely by the plugin. No WordPress page needs to exist. A shortcode `[eem_sheets_results]` should also be supported as a fallback for themes that need it on a specific page.

---

## Acceptance Criteria

- [ ] Single "Sheets & Results" sidebar item in EEM admin, no separate Draw Sheets / Results items
- [ ] Event selector on Sheets & Results page loads correct event documents
- [ ] Adding a draw sheet entry automatically creates a mirrored result placeholder row
- [ ] Result rows show amber "Upload Result PDF" state when no PDF is attached
- [ ] Result rows go Live immediately on PDF upload — no publish step
- [ ] Sheets & Results section appears on Event edit page with same tab/row UI
- [ ] Draw Sheets button appears on event cards only when ≥1 draw sheet PDF exists
- [ ] Results button appears on event cards only when ≥1 result PDF exists
- [ ] `show_flyer="true"` renders flyer thumbnail variant with countdown badge
- [ ] `[eem_events filter="upcoming|ongoing|past"]` filters work correctly
- [ ] Per-event public page renders at `/events/{slug}/sheets-and-results/`
- [ ] Draw Sheets tab is always first on all admin and public surfaces
- [ ] No manual page creation required for any public-facing document page
- [ ] All UI matches `results_drawsheets_system.html` mockups exactly
