# Session Notes — Bridge Doc

**Purpose:** This file captures conversational nuance, calibration
data, and in-flight context that ISN'T already in CLAUDE.md, commit
messages, or CLEANUP.md. Read it after `git pull` on a new machine
to pick up momentum across Claude Code sessions.

**Last updated:** 2026-05-26 (end of C7.C.1.4.A session)

---

## Current chunk state

**Last landed:** `38fb142` — docs: retire iCloud-sync rule; canonical
remote is GitHub. (And `fba7058` before it — C7.C.1.4.A: Mockup-
canonical chrome retroactive port for 4 sections.)

**Awaiting:** Visual verify on C7.C.1.4.A. The 4 mockup-canonical
sections (description / checkin / group / fees) need to be eyeballed
in the browser against the mockup. Expected mid-flight state:
4 sections on mockup-canonical chrome, 4 still on legacy table-form
chrome (addons / agreement / stall / rv). That contrast IS expected
and called out in the commit body.

**On greenlight:** C7.C.1.4.B kicks off. Scope locked (4 remaining
partials rewritten to mockup-canonical chrome). Same shared-helper
pattern as .A. Smoke target ~1,330 (1,254 + ~75 new from c7c1-4
extensions + c7c2-1 updates).

**C7 lineage remaining after .4.B:**
- C7.C.2.2 — Lot Zones (NEW `_eem_rv_lot_zones` meta) + RV Add-Ons
  + read-only Stall + Lot Layout summary widgets
- C7.D — Event Day Info section data wiring
- C7.E — Cancellation Policy (per-reservation override + event-default
  inherited-banner UX)
- C7.F — Reservation Editor → Order Detail save-bar reuse
- C7.G — Polish pass (right-rail Status/Visibility/Published/Preview/
  Trash; missing-from-mockup save-bar elements; sub-section toggle
  body/header desync cleanup if not absorbed by .4.B)

User has indicated they want to switch to browser-based Claude after
C7 closes. That's the natural break (entire Edit Reservation page
lineage done; C8 starts a fresh page port for Stall Charts).

---

## Calibration data from today's session

### LOC estimates — partial-rewrite multipliers

**C7.C.2.1 baseline:** partials ran ~86% over plan-time estimate.
Audit shipped a kickoff at ~404 taxed; actual landed ~754 taxed.

**C7.C.1.4.A revision:** partials with shared-helper extraction
(_partial-field-row.php, _partial-toggle-label-row.php) ran ~18%
UNDER plan-time. Audit shipped at ~1,426 taxed; actual landed at
~615 taxed (calibration-buffered worst case projection was ~1,825;
came in well under).

**Forward calibration rule:** when partials use shared helpers,
multiply per-partial LOC by ~0.7x of the naive estimate. When
partials hand-roll markup without shared helpers, use the C7.C.2.1
calibration (×1.86 over naive). Shared-helper compression is the
biggest lever for partial LOC.

### 40% alarm shape

User locked: "every 40% alarm trip triggers a split." Precedent:
C7.B → .1/.2, C7.C → .1/.2, C7.C.2 → .2.1/.2.2, C7.C.1.4 → .4.A/.4.B.
Don't surface this as a decision in kickoff proposals — apply it
automatically when the 40% alarm trips against the plan-time taxed
estimate, propose the split as the recommended path. User accepts
splits without debate when justified by the alarm.

### Smoke target prediction

When predicting smoke targets, account for ~10-30% drift from initial
projection. User's reaction is calibrated to honest estimates; don't
inflate to "look ambitious" and don't deflate to "leave room."

---

## User communication patterns

### Decision-shape discipline (the big one)

User strongly distinguishes between (a) genuine new architectural
decisions for THIS chunk and (b) already-decided patterns being
re-applied to new scope. Only (a) belongs in kickoff decision-lock
lists. Re-surfacing (b) as questions is treated as the same drift
pattern that prompted the Mockup Walkthrough Pre-Audit rule.

If you find yourself surfacing 6+ "decisions" in a kickoff proposal,
audit them: 4 are probably already-decided patterns from CLEANUP or
earlier chunks. Surface only the 1-2 genuinely-new architectural
choices. The rest get a one-line "applying precedent" mention.

### Honest LOC estimates required

User has explicitly said "don't underestimate" and "estimate honestly."
When plan-time estimate is in the right ballpark, say so. When the
worst-case projection trips the 40% alarm, surface a split proactively
— don't try to fit everything in one commit if the math says it'll
trip the alarm.

### Visual verify gates

User does visual verify after every chunk commit. Don't propose
multi-commit chunks that bypass visual-verify gates. If a chunk lands
with a visible regression (chevron invisible, double toggles, save
bug), user catches it at visual verify and triggers a fix-up
sub-chunk. C7.C.1 → C7.C.1.1 → .1.2 → .1.3 → .1.4 lineage is the
canonical example.

### Calls out audit drift directly

If user says "audit-shape problem" or "systemic," they're flagging
that the issue isn't this one chunk — it's a pattern across multiple
chunks. The fix is usually a new standing rule in CLAUDE.md + a
retroactive correction chunk. See the Mockup Walkthrough Pre-Audit
rule's introduction for the canonical example.

### Tone

Terse, technical, explicit. Skip pleasantries. Use tables for
multi-item enumeration. Decision tables work better than prose.
Code-fenced examples better than narrative. Avoid hedging language
that doesn't carry information.

---

## Active CLEANUP entries most likely to surface in upcoming chunks

- **#44** — Section-enabled meta keys rename (`_en_*_enabled` →
  `_eem_section_enabled_{key}`). Deferred to C16 with C10/C11/C12
  read-side cascade. Don't propose this in C7 chunks.
- **#45** — C7.C.1.4.B pending (4 remaining partials). This is the
  NEXT chunk; not a deferral but a forward-pointer.
- **#46** — Legacy `render_editor_*_row()` helpers wholesale strip
  at C16. Callable retained transitionally. After C7.C.1.4.B lands,
  NO editor code calls these — but non-editor callers may exist.
- **#47** — New `_en_group_description` + `_en_group_riders_per_group`
  meta keys (C7.C.1.4.A) — customer-facing C10 cascade pending.
- **Cancellation Policy architecture (C7.E)** — global cancellation
  policy DEPRECATED; per-reservation override is canonical; global
  Settings textarea is read-no-write after migration. See CLAUDE.md
  "Architecture decisions in flight" section.

---

## Workflow notes (post-iCloud-retirement)

- **Canonical remote:** `github.com/enwmitchell/equine-event-manager`
  (private). Push after every commit via `git push origin main`.
- **Multi-machine flow:** `git pull origin main` before starting work
  on a different machine. `git push origin main` after every commit.
- **No more iCloud:** the iCloud copy at `~/Library/Mobile Documents/
  com~apple~CloudDocs/Projects/Equine Event Manager/` is no longer
  maintained. Don't sync to it. See CLAUDE.md "Standing rule —
  Post-commit sync" section for the full context.
- **Mockup-presence smoke retained:** Still guards against in-repo
  `.mockups/` wipes (`rm`, bad merge resolution, etc.) even without
  iCloud in the loop.

---

## Browser-Claude / Codespaces context

User considering switching to browser-based Claude (Codespaces with
Claude extension) after C7 closes. Stage 2 setup not yet done. If
user asks about this:
- Don't recommend claude.ai/chat for active coding work — no tool
  access; the chunk-execution workflow breaks
- Codespaces with Claude extension is the right shape — needs
  `.devcontainer/` config + WordPress + wp-cli + smoke-test fixture
  replication (~60 min setup chunk)
- Recommend Codespaces Stage 2 as a discrete chunk between C7 close
  and C8 kickoff — natural project boundary

---

## Quick-resume checklist for new Claude on a different machine

1. Read CLAUDE.md (standing rules)
2. Read CLEANUP.md (deferred items + decisions)
3. Read this file (SESSION-NOTES.md) for verbal nuance
4. `git log --oneline -10` (see recent chunks)
5. Run `bash tests/smoke/run-all.sh` to confirm green baseline
6. User will probably say something like "let's continue with C7.C.1.4.B"
   — at that point you have everything you need
