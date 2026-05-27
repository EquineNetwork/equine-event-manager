# Session Notes — Bridge Doc

**Purpose:** Captures conversational nuance, calibration data, and
in-flight context that ISN'T already in CLAUDE.md, commit messages,
or CLEANUP.md. Read after `git pull` on a new machine to pick up
momentum across Claude Code sessions.

**Last updated:** 2026-05-26 — Build-to-Mockup retroactive port COMPLETE

---

## Current state

**Reservation Editor is fully ported to mockup canon.** All 54 drifts
from the C7.X audit are addressed. The editor is built to
`.mockups/edit_reservation_page.html` end-to-end:

- ✅ Page chrome: `.eem-plugin-wrap` + `.eem-plugin-header` with
  mockup-canonical title/subtitle/meta-line
- ✅ Body layout: two-column `.eem-edit-body` CSS grid (1fr + 300px rail)
- ✅ Right rail: 3 cards — Publish (Status/Visibility/Published rows
  + Preview/Save Draft/Update/Move to Trash buttons), Linked Event
  (search + linked display + Unlink), Shortcode (code-box)
- ✅ Mobile sticky-save (`.eem-sticky-save` display:flex only at <768px)
- ✅ All 10 sections render mockup-canonical chrome:
  - description, checkin, eventday (NEW), stall, rv, addons, group,
    fees, agreement, cancellation (NEW)
  - Section bodies use `.eem-field-row` grid (NOT `<table class="form-table">`)
  - Sub-section toggles use `.eem-toggle-label-row` (NOT native checkbox)
  - Stay-type pairs use `.eem-stay-type-btn` pills with ID-based data-controls
  - Fee-mode pill triplet (None/Flat/Percentage) replaces legacy `<select>`
  - Lot Zones repeating-row builder with 8-preset color swatches
  - Layout summary widgets (Stall + Lot, read-only, C8-stub buttons)
  - File-row agreement chrome
  - Cancellation inherited-default-banner + override actions + restore button
- ✅ RETIRED architecture: fixed-bottom `.eem-save-bar`, modal
  `#eem-modal-linked-event`, meta-line change-link launcher,
  `ajax_change_linked_event` handler. All replaced by rail-card UX.

**Final smoke: 1334/1334 green** (across 22 smoke files).

**New behaviors / JS shipped:**
- `eemApplyControlsById()` — ID-based data-controls visibility
  (mockup applyControls pattern)
- `eemFlashStayHint()` — at-least-one stay-type validation
- `eemApplyFeeModeVisibility()` — fee-mode pill triplet → conditional rows
- `eemUpdateCancellationOverrideState()` — override textarea state
- `window.eemRestoreCancellationDefault` — Restore default button
- Zone color preset picker (8-swatch popover)
- Zone row add/remove handlers
- Rail-card click handlers (Trash with confirm, Unlink with confirm)

**New AJAX handlers:**
- `eem_reservation_editor_unlink_event` — clears `_en_event_id` +
  `_en_external_event_id`
- `eem_reservation_editor_trash` — wp_trash_post + redirect to
  Reservations list

**New post-meta keys (Option L1 non-destructive additive):**
- `_en_event_day_enabled` / `_en_event_day_checkin` / `_bring` /
  `_parking` / `_contact` (5 keys, Event Day Info section)
- `_en_cancellation_enabled` / `_en_cancellation_policy_override`
  (2 keys, Cancellation Policy section)
- `_en_rv_lot_zones` (1 key, RV Lot Zones repeating array)
- `_en_group_description` / `_en_group_riders_per_group` (from
  C7.C.1.4.A, Group section)

All new keys go into `EEM_Reservations_CPT::sanitize_meta_submission()`
+ `get_default_meta_values()`. C10/C11/C12 customer-facing surfaces
will read these in their own chunks per CLEANUP entries.

---

## Commits landed in the Build-to-Mockup rewrite (this session)

```
1c04541  C7.X.1: CSS primitives — full mockup-canonical port to admin.css
541535b  C7.X.2: JS handlers — mockup-canonical behaviors
58c0f06  C7.X.3: Page chrome rewrite — mockup-canonical rail architecture
510bb01  C7.X.4: Sections — stall + RV + addons + agreement to mockup canon
c84e576  C7.X.5 + C7.X.6: Event Day Info + Cancellation Policy sections
[next]   C7.X.7: Build-to-mockup verification smoke + meta-line polish
```

All pushed to `github.com/enwmitchell/equine-event-manager`.

---

## Calibration data from today's session

### LOC actuals vs estimates

| Chunk | Plan-time taxed | Actual taxed |
|---|---|---|
| C7.X.1 CSS primitives | ~220 | ~197 |
| C7.X.2 JS handlers | ~250 | ~432 (heavy zone-picker + unlink + trash handlers) |
| C7.X.3 Page chrome + rail | ~600 | ~720 |
| C7.X.4 Stall + RV + addons + agreement | ~1,500 | ~1,260 (shared helpers compressed) |
| C7.X.5+6 Event Day + Cancellation | ~650 | ~290 (shared helpers compressed further) |
| C7.X.7 verification smoke | ~400 | ~410 |
| **Total taxed** | **~3,620** | **~3,309** |

Final LOC came in ~9% under plan-time. The shared partial helpers
(_partial-field-row + _partial-toggle-label-row + _partial-stay-type-pair
+ _partial-layout-summary) compressed per-section LOC significantly.
JS handlers ran heavier than estimated due to the zone color picker
+ unlink + trash dispatchers.

### Smoke discipline

- Render-then-collect-then-post round-trip canon: hardened across
  all section smokes. Skip logic extended to cover hidden mirrors
  with `data-eem-section-enabled`, `data-eem-subsection-enabled`,
  AND `data-eem-stay-type-mirror` (all three use isset-presence
  in the legacy sanitize and need the same off-skip).
- Build-to-mockup verification: `c7x-build-to-mockup-smoke.php` is
  the new canonical smoke that asserts every visual element + CSS
  primitive + JS handler from the audit. 92 assertions.

### Meta-key coercion (recurring trap)

`get_meta_values()` at lines 1892–1909 auto-coerces several `*_enabled`
fields back to 1 when companion fields are non-empty:
- `checkin_checkout_enabled` ← `checkin_time` / `checkout_time` / `checkin_time_enabled` / `checkout_time_enabled`
- `stall_schedule_enabled` ← `stalls_open_at` / `stalls_close_at`
- `rv_schedule_enabled` ← `rv_open_at` / `rv_close_at`
- `venue_map_enabled` ← `venue_map_download_url` / `venue_map_image_id`
- `convenience_fee_enabled` ← fee_type !== 'none'

Any smoke that seeds `*_enabled = 0` MUST also clear the companion
fields, or the coercion flips it back to 1 and validation trips.

---

## What's NEXT after this session

User instructed: "I'll come back to a finished editor." The editor
IS finished per the Build-to-Mockup rule. User will visual-verify
when they return. If anything surfaces at visual verify, fix
discipline is intact (Mockup Walkthrough Pre-Audit + Build-to-
Mockup canon).

**Subsequent chunks (per CLAUDE.md Phase 3 roadmap):**
- C8 — Stall Charts (full chart editor that the C7 layout-summary
  widgets currently stub to)
- C9 — Customer Profile page
- C10 — Customer Event Page (consumes the new meta keys
  introduced in C7.X — `_en_event_day_*`, `_en_cancellation_*`,
  `_en_rv_lot_zones`, `_en_group_*`)
- C11 — Customer Confirmation Email
- C12 — Order Receipt + Hosted Order Page
- C13 — Create Order admin page
- C14 — Collect Payment admin page
- C15 — Reports
- C16 — Final polish (wholesale strip of legacy
  `render_editor_*_row()` helpers + `_en_*_enabled` →
  `_eem_section_enabled_{key}` rename + retire `_en_rv_lots` in
  favor of `_en_rv_lot_zones`)
- DS-1 — Design system fidelity + Dashboard

---

## Active CLEANUP entries (carry forward)

- **#44** — `_en_*_enabled` → `_eem_section_enabled_{key}` rename
  (C16 with C10/C11/C12 cascade)
- **#45** — Closed: C7.C.1.4.B was the next-chunk pointer for the
  4 unchromed sections; that work is done in this session's C7.X.4
- **#46** — Legacy `render_editor_*_row()` helpers wholesale strip
  at C16 (still present, callable but no editor partial uses them
  anymore)
- **#47** — `_en_group_description` + `_en_group_riders_per_group`
  customer-facing C10 cascade
- **NEW (this session) #48** — `_en_event_day_*` (5 keys) +
  `_en_cancellation_policy_override` + `_en_rv_lot_zones` customer-
  facing C10/C11/C12 cascade
- **NEW (this session) #49** — Event-level cancellation default
  field on Event CPT (per CLAUDE.md scope add #2). Resolver
  (`EEM_Cancellation_Policy::resolve_for_reservation`) already
  exists from C7.A; the Edit-Event admin UI for setting the
  per-event default still needs wiring. The Edit Reservation
  editor reads from the resolver correctly.

---

## User communication patterns

- **Build to Mockup, Period:** Mockup is the spec. No "Path A
  half-measures." If a partial uses WP form-table chrome instead
  of mockup-canonical `.eem-field-row`, that's drift to fix, not
  a design choice.
- **No visual verify between commits during multi-commit projects.**
  Smoke is the quality gate. Visual verify is once, at the end.
- **Decision-shape discipline:** distinguish genuine new
  architectural decisions for THIS chunk from already-decided
  patterns being applied to new scope. Don't re-surface (b).
- **Calibration-tighten estimates:** when actuals come in materially
  under, capture in SESSION-NOTES so future estimates are calibrated.
- **Honest scope surfacing:** when an instruction implies a scope
  that exceeds safe session capacity, say so + propose a path,
  don't power through and risk leaving a broken intermediate state.

---

## Workflow notes

- Canonical remote: `github.com/enwmitchell/equine-event-manager`
  (private). Push after every commit via `git push origin main`.
- Multi-machine: `git pull origin main` before starting work on
  a different machine. `git push origin main` after every commit.
- iCloud sync RETIRED.
- Mockups-presence smoke retained as in-repo wipe guard.

---

## Quick-resume checklist for new Claude on a different machine

1. Read CLAUDE.md (standing rules — Build to Mockup, Mockup
   Walkthrough Pre-Audit, render-collect-post, DOM-presence canon)
2. Read CLEANUP.md (deferred items + decisions)
3. Read this file (SESSION-NOTES.md) for verbal nuance + completion
   state
4. `git log --oneline -10` (see recent chunks)
5. `bash tests/smoke/run-all.sh` to confirm 1334+ green baseline
6. User will probably want to visual-verify the editor or kick off
   the next chunk (likely C8 Stall Charts).
