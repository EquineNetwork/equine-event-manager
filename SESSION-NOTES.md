# Session Notes — Bridge Doc

**Purpose:** Captures conversational nuance, calibration data, and
in-flight context that ISN'T already in CLAUDE.md, commit messages,
or CLEANUP.md. Read after `git pull` on a new machine to pick up
momentum across Claude Code sessions.

**Last updated:** 2026-05-27 — C7.X.10 group sub-toggles + affix-seam fix-up landed; awaiting Whitney visual verify + item 7 (Linked Event rail card) decision

---

## C7.X.10 fix-up — group sub-toggles + affix seam (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1402/1402 green (was 1381; +21 from new
`c7x10-toggle-affix-smoke.php`). Whitney to visual-verify after re-running
the seed (canonical event date keys + Step-1 backfill landed).

**3 findings from C7.X.9 visual verify on res 44 consolidated:**

1. **VV-2 — group sub-toggles non-functional.** Whitney called this
   "Fees-section"; actual scope is `_section-group.php` (Grounds Fee +
   Deposit toggles). Group was the only remaining partial still on the
   retired class-token controls system (`eem-ctrl--grounds-amt`,
   `eem-ctrl--deposit-amt`). C7.X.9's `eemApplyControlsById` does
   `document.getElementById(id)` per token, so class tokens silently
   no-op'd. **Fix:** converted to ID-based controls
   (`row-group-grounds-amt`, `row-group-deposit-amt`); dropped
   `row_classes` (legacy noise). 4 operative lines + audit-trail
   comment.

2. **VV-3 — affix seam (currency $ chip / percent % chip) — CSS
   specificity collision.** Root cause: 6 distinct `!important` blocks
   in `admin-legacy.css` target `input[type="number"]` and apply
   `border-radius: 8px !important`, overriding our
   `.eem-price-input { border-radius: 0 var(--eem-radius) var(--eem-
   radius) 0 }`. Exactly the C4 lesson recurring. **Process miss:**
   C7.X.4 form-control port shipped without running CLAUDE.md's
   "Prospective form-control port checklist". The checklist would
   have flagged this at C7.X.4 commit time and cost ~5 min to add the
   exclusions instead of two visual-verify cycles ~weeks later.
   Future form-control ports must run the checklist as part of
   pre-commit review.
   **Fix:** added `:not(.eem-price-input):not(.eem-pct-input)` to
   every `input[type="number"]` selector in admin-legacy.css. 19
   occurrences total, all covered. Count-based smoke assertion
   guards against future regression where someone adds a 7th block
   without the exclusion.
   **Specificity check on line 2690 non-!important block:** `body
   .eem-shell-page--editor input[type="number"]` = (0,2,2) beats
   `.eem-price-input` = (0,1,0). Required remediation despite no
   `!important`. Confirmed remediated.

3. **VV-5 — section order matches mockup. DROPPED.** Verification
   trail: `.mockups/edit_reservation_page.html` `.section-title`
   enumeration matches `EEM_Reservation_Editor_Page::section_definitions()`
   render order on res 44 exactly:
   1. description / Reservation Description
   2. checkin / Check-In / Check-Out
   3. eventday / Event Day Info
   4. stall / Stall Reservations
   5. rv / RV Reservations
   6. addons / General Add-Ons
   7. group / Group Reservations
   8. fees / Convenience Fee
   9. agreement / Agreement
   10. cancellation / Cancellation Policy

**Process-miss note (calibration paying forward):**
C7.X.4's port checklist miss cost ~2 visual-verify cycles + this
fix-up commit. The pattern: when shipping a NEW form-control
component (`.eem-search-input`, `.eem-price-input`, `.eem-pct-input`,
or future `.eem-time-input` etc.) the developer must:
  (a) `grep -nE 'input\[type="..."\]' admin-legacy.css` to find every
      block targeting that input type.
  (b) Add `:not(.eem-<new-component>)` to EVERY occurrence in the
      same commit that introduces the component.
  (c) State in commit message that the checklist was run + N
      exclusions added.
The C7.X.4 commit didn't do (a)/(b)/(c). Going forward, the smoke
"every input[type=...] in admin-legacy carries :not(.eem-XYZ)" pattern
landed in C7.X.10 is the structural enforcement — copy it forward
when shipping any new form-control class.

**C7.X.10 commit:** `[hash filled after commit]`

**Seed script update (tests/seeds/seed-reservation-44-link-event.php):**
C7.X.9's seed picked the first available event (TEC, 2026-06-26),
which broke c4d-smoke's sort assertion that depends on res 44's
start_date being 2025-03-10. C7.X.10 update:
  - Step 1 prefers a native `en_event` with start_date 2025-03-10
    (checks both `_equine_event_manager_event_start_date` canonical
    key AND `_en_event_start_date` legacy mirror).
  - Step 2 seeds a fresh native event with that date if no native
    matches (skips TEC fallback because TEC events have arbitrary
    dates).
  - Step 1 reuses prior-seeded "2025 Spring Classic" native event
    on re-run (idempotent, no duplicate piling).
  - Post-pick: belt-and-braces backfill writes the canonical
    `_equine_event_manager_event_*_date` keys from `_en_event_*_date`
    if missing. The native event resolver reads the canonical keys;
    the seed previously only wrote `_en_*` so the resolver returned
    empty start/end dates even with a linked native event.
  - Resolver output after seed: title='2025 Spring Classic (seeded
    by C7.X.9)', date_range='Mar 10, 2025 – Mar 12, 2025'. c4d sort
    + date-filter assertions pass.

**c7c1-4-smoke updates (3 assertions):**
Updated to match the new ID-based controls architecture:
  - grounds-fee toggle: data-controls accepts `row-group-grounds-amt`
    OR (backward-compat) `row-grounds-amt` OR
    `eem-ctrl--grounds-amt`. Backward-compat fallbacks kept until
    C16 wholesale legacy strip.
  - Grounds Fee Amount row VISIBLE: matches new
    `id="row-group-grounds-amt"` shape OR old `eem-ctrl--grounds-amt`
    class shape.
  - Deposit Amount row HIDDEN: same boundary handling.

**Files touched (6 modified + 1 new smoke):**
- `templates/admin/reservation-editor/_section-group.php` —
  Grounds Fee + Deposit toggles converted to ID-based controls
  (4 operative lines + audit-trail comments)
- `assets/css/admin-legacy.css` — `:not(.eem-price-input):not(.eem-pct
  -input)` added to 19 `input[type="number"]` selector occurrences
  (6 !important blocks + the editor non-!important block + 12 other
  scoped blocks where the exclusion is benign and the count-based
  smoke requires uniform coverage)
- `tests/seeds/seed-reservation-44-link-event.php` — Step-1 prefers
  date-matching native, Step-2 seeds native with target date,
  belt-and-braces canonical-key backfill
- `tests/smoke/c7c1-4-smoke.php` — 3 assertions updated to new
  ID-based shape with backward-compat fallbacks
- `tests/smoke/c7x10-toggle-affix-smoke.php` — NEW, 21 assertions
  across 2 root-cause bug groups
- `SESSION-NOTES.md` — this entry

**Visual-verify checklist Whitney will walk after re-running seed:**
- [ ] Group section: toggle "Charge a grounds fee for each rider" OFF
      → Grounds Fee Amount row hides
- [ ] Toggle ON → Grounds Fee Amount row reveals (no `eem-row--hidden`)
- [ ] Same shape for Deposit toggle
- [ ] All currency $ chip + input fields read as ONE unified control
      (no visible seam, no broken radius on inner edges) — Pricing,
      Grounds Fee Amount, Deposit Amount, Flat Fee
- [ ] Percentage Fee % suffix + input field same shape
- [ ] Meta-line + rail card now show real values: '2025 Spring Classic
      (seeded by C7.X.9)' / 'Mar 10, 2025 – Mar 12, 2025'

**Item 7 still OPEN (Linked Event rail card vs meta-line redundancy)** —
awaiting Whitney's decision after C7.X.10 visual verify. See C7.X.9
entry below for context.

---

---

## C7.X.9 fix-up — toggle behaviors, disabled-note, peek (landed 2026-05-27)

**Status:** committed + pushed. Smoke 1381/1381 green (was 1359; +22 from new
`c7x-toggle-behaviors-smoke.php`). Whitney to visual-verify after running the
seed script.

**The 6 items from Whitney's visual-verify report consolidated into 3 root-
cause bugs + 1 seed gap + 1 open question:**

1. **Item 1 — seed data.** Reservation 44 ("2025 Spring Classic") shipped
   with no event-link meta. Fixed by `tests/seeds/seed-reservation-44-link-
   event.php` (re-runnable). Run with:
   `wp eval-file tests/seeds/seed-reservation-44-link-event.php`
   Script picks a native `en_event` if available, else TEC `tribe_events`,
   else seeds a minimal native fallback. Wires `_en_event_source` +
   `_en_event_id` + `_en_use_global_event_source=0` and rewrites the
   resolver sort-cache key. Prints before/after + resolver output.

2. **Item 2 — disabled-note unconditional emission.** Root cause: skeleton
   partial always emits the note when arg is non-empty; CSS had no
   `display:none` default. Fix: CSS gate in `admin.css`:
   - `.eem-section-disabled-note { display: none; ... }` (default hide)
   - `.eem-section-body--disabled .eem-section-disabled-note { display: block; ... }`
     (descendant override)
   No `!important` — straight specificity. Skeleton continues to emit the
   note unconditionally so JS toggle-OFF reveals it via ancestor class
   without re-render.

3. **Items 4 + 5 — stay-types + sub-section toggles non-functional. Single
   bug.** Root cause: partials emitted duplicate stale state-class tokens
   (` active` on stay-type-btn, ` on`/` off` on toggle-label-row wrapper +
   inner toggle) on top of canonical `eem-stay-type-btn--active` /
   `eem-toggle--on/--off`. Click handlers only flipped the canonical
   classes; bare duplicates were never toggled off, so
   `eemApplyControlsById` read `on=true` forever.
   Fix:
   - `_partial-stay-type-pair.php`: stripped ` active`, `' on'`/`' off'`
     from `$active_cls` and `$tog_cls`.
   - `_partial-toggle-label-row.php`: stripped `$wrapper_state` entirely
     (wrapper no longer carries `on`/`off`); inner `.eem-toggle` no
     longer carries duplicate.
   - `eemApplyControlsById` in `admin.js`: state-class read narrowed to
     canonical classes only. Added fallback to inspect inner
     `.eem-toggle` for toggle-label-row wrappers (which carry
     `data-controls` but no state class).
   - **Secondary fix in same function:** now toggles `eem-row--hidden`
     CSS class (which has `display:none !important`) instead of just
     `style.display = ''`. Inline style alone could never reveal an
     initially-hidden row because the class wins specificity.

4. **Item 3 — collapse-on-disable / chevron-lock. Bug + spec mismatch.**
   Live PHP render is correct at first paint (verified: stall card has
   `eem-section-collapsed`, body has `eem-section-body--hidden
   eem-section-body--disabled`). Whitney's observation came from clicking
   the chevron to peek — which is the canonical UX per item 6 — but the
   then-existing lock handler at `admin.js:2296-2311` mis-targeted the
   body via `collapse2.parentElement.parentElement.querySelector
   ('.eem-section-body')` (walks past the card to the container, grabs
   the FIRST section body, not the clicked one), so:
     (a) lock didn't actually re-collapse the clicked section
     (b) wrong section's body got stuck `--hidden`
   Fix: deleted the lock handler entirely. Peek-while-disabled is the
   canonical UX per item 6 ("user CAN still click the chevron to expand
   and peek at the fields"). Body keeps `--disabled` chrome (striped
   overlay + pointer-events:none) when expanded by chevron click.

5. **Item 6 — peek-while-disabled.** Spec, not a bug. The chevron-lock
   removal above implements it. Chevron toggles collapse independently of
   the enable state; section body keeps `--disabled` chrome on peek.

6. **Item 7 — Linked Event rail card vs meta-line redundancy. OPEN —
   awaiting Whitney's decision.** Both surfaces display the linked event.
   Rail card adds typeahead/change/unlink (meta-line is read-only).
   Possible paths if removing the rail card:
   - (a) Make meta-line clickable to launch typeahead
   - (b) Add "(change)" link next to meta-line
   - (c) Keep rail card (status quo)
   - (d) Remove linked-event editing from editor entirely
   Whitney to decide after visual-verifying items 1-6 with reservation 44
   linked to a real event (see seed script above).

**C7.X.9 commit:** `[hash filled after commit]` — see `git log` for the
final hash and message.

**Smoke regression coverage shipped in `c7x-toggle-behaviors-smoke.php`:**
   22 assertions across 4 groups. ABSENCE-assertions used per
   Whitney's review note — smoke fails if anyone re-introduces the stale
   `active` / `on` / `off` duplicates OR re-adds the lock handler OR
   removes the CSS gate. Defense against C16-style regression.

**Files touched (7):**
- `assets/css/admin.css` — 6 LOC modified (disabled-note gate)
- `assets/js/admin.js` — `eemApplyControlsById` rewritten (~12 LOC); lock
  handler at 2296-2311 deleted (~16 LOC removed); replaced with a comment
  explaining the canonical UX decision (~10 LOC).
- `templates/admin/reservation-editor/_partial-stay-type-pair.php` — 9
  LOC modified (strip duplicates + new audit-trail comment)
- `templates/admin/reservation-editor/_partial-toggle-label-row.php` — 13
  LOC modified (strip `$wrapper_state` + duplicates + new audit-trail
  comment)
- `tests/seeds/seed-reservation-44-link-event.php` — NEW, 134 LOC
- `tests/smoke/c7x-toggle-behaviors-smoke.php` — NEW, 138 LOC
- `SESSION-NOTES.md` — this entry.

**Visual-verify checklist Whitney will walk after running the seed:**
- [ ] Disabled-note appears ONLY when section is toggled OFF
- [ ] Stay Types (Nightly/Weekend) toggle hides/reveals conditional rows
- [ ] Sub-section toggles (Schedule / Early Bird / Required Shavings)
      hide/reveal rows
- [ ] Chevron-expand on disabled sections shows the body with striped
      `--disabled` chrome (peek works)
- [ ] Linked Event meta-line + rail card both render real values for
      reservation 44 (sets up item 7 decision)

**Handoff notes for next strategic chat:**
- C7.X is functionally complete pending Whitney's visual-verify of C7.X.9
  + her item 7 decision.
- If item 7 lands as "remove rail card," the work is a small partial
  retirement + meta-line interactivity wire — likely 1 commit, ~150 LOC.
- If item 7 lands as "keep both," the editor is shipped and the next
  chunk is C8 (Stall Charts).
- The chevron-lock removal in C7.X.9 means the JS at `admin.js:2296-2311`
  region is now a comment block. If C16 wholesale-strips legacy editor
  code, that comment block can also go.
- The CSS-gate pattern landed in C7.X.9 (`display:none` default + a
  descendant `display:block` override, no `!important`) is the canonical
  shape for any future "always-emit-in-DOM, gate-via-ancestor-class"
  visibility pattern. See `.eem-section-disabled-note` rules in
  `admin.css`.

---

## Original C7.X retroactive port summary (carry forward)

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
