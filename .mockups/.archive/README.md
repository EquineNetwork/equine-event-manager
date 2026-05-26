# `.mockups/.archive/` — historical mockup references

Mockup files that were once part of the active inventory but have been
retired from the canonical `.mockups/` set. Kept here as version-controlled
reference material for future audit work. **Not** consumed by any
runtime code or active port chunk.

## Inventory

### `invoicing_page.html`

Retired when the post-handoff roadmap split the legacy single "Invoicing"
page into two focused admin pages:

- **C13 — Create Order** (mockup: `.mockups/create_order_page.html`)
- **C14 — Collect Payment** (mockup: `.mockups/collect_payment_page.html`)

See `CLAUDE.md` → "Phase 3 chunk roadmap" → C13 / C14 entries for the
split rationale. This archive copy is the May 23 mockup-audit version,
preserved in case it's useful as historical context during C13 / C14
implementation (e.g. confirming a UI element existed previously, or
checking how the legacy invoicing flow handled an edge case the split
mockups don't explicitly cover).

The runtime presence-smoke (`tests/smoke/mockups-presence-smoke.php`)
test [2] explicitly asserts that `invoicing_page.html` is **not**
present at the top of `.mockups/` — that assertion stays green because
this archive copy lives under `.archive/`, not at the top level. The
scandir-based stray-file guard in test [3] excludes `.archive` from
its inventory check.

## Standing rules

- Files under `.archive/` are git-tracked and travel with the repo.
- The `.mockups/`-excluded-from-iCloud-sync standing rule applies to
  everything in here too — `.archive/` rides under the same umbrella;
  no separate exclusion needed.
- Do not move files OUT of `.archive/` back into the active mockup set
  without a re-audit conversation. If a chunk needs to re-promote an
  archived mockup, treat it as a fresh canonical mockup import.
