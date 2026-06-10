# Session Handoff — GEMS Integration + Demo Prep

**Last updated:** end of the 2026-06-09/10 working session.
**Current version:** `2.7.168` (in `equine-event-manager.php` header + `EQUINE_EVENT_MANAGER_VERSION`).
**Branch:** `v4-stall-mapping` — this is the active dev branch. **`main` is kept fast-forwarded to it** (they point at the same commit). The in-WordPress auto-updater watches `main`, so Whitney updates her sites via Plugins → "Update now".

> Read `CLAUDE.md` first (workflow + binding command-hygiene rules), then `README.md` (data model / naming), then this file for where the last session left off.

---

## How to ship a change (the established loop)

Every change this session followed this loop. Keep doing it:

1. Edit code (use the Edit/Write tools — **command hygiene in CLAUDE.md is binding**: one command per Bash call, no `cd`, no `&&` chaining, no heredocs, write throwaway scripts to `/tmp` or self-delete via `@unlink(__FILE__)`).
2. **Lint:** `php -l <file>` for PHP, `node --check <file>` for JS.
3. **Reset OPcache:** Write a self-deleting `_eem_oc.php` to the WP root, then `curl http://en-event-manager.local/_eem_oc.php`.
4. **Verify on Local** (render check via `wp eval` / `wp eval-file`, or the Chrome MCP for visual).
5. **Bump version** in `equine-event-manager.php` (two places: `Version:` header + the `define()`).
6. **Commit** (end message with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`).
7. **Push to BOTH:** `git push origin v4-stall-mapping` then `git push origin v4-stall-mapping:main`, then `git branch -f main v4-stall-mapping` to keep local `main` synced.

### Local environment (only exists on Whitney's machine — a fresh Claude.ai chat won't have it)
- **Local PHP binary:** `/Users/whitneymitchell/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php`
- **wp-cli:** `/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar`
- **WP path:** `/Users/whitneymitchell/Local Sites/en-event-manager/app/public`
- **Local site URL:** `http://en-event-manager.local`
- **Staging:** `https://eqeventmanager.wpenginepowered.com` (WP Engine). After updating a plugin there, **clear the WP Engine cache** (it caches aggressively — see the rewrite-rules note below).
- **GitHub:** `github.com/EquineNetwork/equine-event-manager` (private).
- Smoke suite: `php tests/run-all-smokes.php <wp-path> <php-bin> <wp-cli.phar>` → last baseline **2727 pass / 46 fail / 0 fatals**. The 46 are test-drift + seed-dependent, **not product bugs** (see Audit below). Seed data: `wp eval-file tools/seed-test-data.php` (idempotent; `@eem-test.local` rows).

---

## What this session built: GEMS Integration (v1 event source)

**Goal:** a third event source alongside TEC and Native — read events from the **GEMS Web Data API** (the customer runs the separate "GEMS for WordPress" plugin). It behaves like the TEC integration: reservations search + link to live GEMS events. Plus a **bridge** so the GEMS plugin's event listing shows a "Reservations" button linking to the EN booking page.

### Key files
- **`includes/class-eem-gems-client.php`** (NEW) — GEMS API client. Fetches `GET /api/Schedule/{assn}` with a Bearer JWT, normalizes each event to the canonical feed shape, 15-min transient cache. Credentials resolve from EEM's own integration settings first, falling back to the standalone GEMS plugin's `gems_key`/`gems_assn` options. Also: `get_reservation_public_url` is NOT here (that's in Events) but the **flyer image** comes from here — GEMS gives no image URL, so `normalize_event` builds it from `refId`: `FLYER_IMAGE_BASE . '/' . refId . '.jpg'` (= `https://www.globalhandicaps.com/images/schedule/{refId}.jpg`). `content_raw` is left EMPTY (it used to carry `eventType`, which rendered as a stray "Non-Sanctioned" bullet). Public bridge styling: `enqueue_bridge_button_styles()` brands the GEMS plugin's `.gems-reservations-btn` Electric Blue.
- **`includes/class-equine-event-manager-events.php`** — `search_feed_events()` / `get_feed_event_by_external_id()` delegate to the GEMS client when configured (GEMS branch BEFORE the empty-feed_url guard). `get_default_event_source()` adds `'feed'` to available sources when GEMS is configured. **The bridge:** `get_reservation_for_external_event($uid)` + the global function `eem_get_reservation_url_for_event($uid)` (queries published `en_reservation` by `_en_external_event_id`). New shared helper `get_reservation_public_url(int $id)` returns the virtual route `/equine-event/{id}/`. Carryover: in `get_normalized_reservation_event_data`, feed dates are authoritative (override the reservation's own window) and `hero_image` is mapped from the feed event's `featured_image`. The virtual page handler `maybe_render_virtual_event_page()` has a **REQUEST_URI fallback** (see WP Engine note) and enqueues `render_frontend_styles()` BEFORE `get_header()`.
- **`admin/class-eem-settings-page.php`** — Settings → Integrations "GEMS Integration" source row (the `'feed'` source, relabeled), GEMS Connection panel + Test Connection button (`handle_ajax_test_gems_connection`). Source order: TEC, GEMS, then Native (Coming Soon) last.
- **`admin/class-eem-reservation-editor-page.php`** — source-aware event typeahead (feed vs TEC), hidden inputs `external_event_id`/`external_event_name`. "View Event" header button uses `get_reservation_public_url`.
- **`includes/class-eem-setup-wizard.php`** — onboarding wizard now offers GEMS as a source (when configured) AND a Stripe/Authorize.net processor picker + Support Phone field.
- **`assets/js/admin.js`** — typeahead `selectLinkedEvent` feed branch; `EEM.getMapLabels` merge for blocked-stalls; frontend `fitSmap` two-axis fit.
- **GEMS plugin side (edited by Whitney in a SEPARATE chat, NOT this repo):** a `gems_reservation_button($eventUID)` helper that calls `eem_get_reservation_url_for_event()` and renders `<a class="gems-reservations-btn">Reservations</a>`. The zip she shared lives at `~/Desktop/gemssettings2.7.zip` (extracted copy was in `/tmp/gemszip27`). The flyer URL scheme (`globalhandicaps.com/images/schedule/{refId}.jpg`) was reverse-engineered from that plugin.

### Live GEMS test connection (on Local, already configured)
Association `236` (NTR), ~18 upcoming events. Test event used throughout: GEMS `eventUID` 43879 / 43986, reservation #6519 ("NTR- Rapid City, SD"), flyer refId 23750.

---

## Full chronological fix list this session (versions 2.7.155 → 2.7.168)

| Ver | Fix |
|---|---|
| 2.7.155 | Onboarding wizard offers GEMS as an event source when connected |
| 2.7.156 | Settings: pin Native Events (Coming Soon) below TEC + GEMS |
| 2.7.157 | GEMS-linked reservations no longer rejected by the legacy Feed-URL save gate (`validate_meta_submission`) |
| 2.7.158 | Available Reservation Dates default to the linked GEMS event's start/end (`populate_available_dates_from_event` feed branch) |
| 2.7.159 | Brand the GEMS "Reservations" bridge button Electric Blue |
| 2.7.160 | GEMS Reservations button 404 fix (virtual route, not `get_permalink` on a `public=>false` CPT) + remove stray "Non-Sanctioned" bullet |
| 2.7.161 | Customer form read canonical `_eem_section_enabled_*` keys (form showed "not available" for new reservations) — routed `get_reservation_meta` through `read_section_enabled_raw` |
| 2.7.162 | GEMS event flyer renders as featured/hero image |
| 2.7.163 | Onboarding Payments step: choose Stripe OR Authorize.net |
| 2.7.164 | "View Event"/"View on Frontend" URLs work for GEMS; Tack Stalls default OFF; wizard Support Phone; stall-setup summary bold labels |
| 2.7.165 | **WP Engine rewrite fallback** — `/equine-event/{id}/` resolved to homepage because WP Engine didn't honor the programmatic `flush_rewrite_rules()`; added a `REQUEST_URI` path-parse fallback in `maybe_render_virtual_event_page` |
| 2.7.166 | Booking form unstyled on the virtual event page — `public.css` was enqueued after `get_header()` (footer-late printing, which WP Engine drops). Now enqueued BEFORE `get_header()` so it lands in `<head>` |
| 2.7.167 | **Blocked Stall Numbers** typeahead couldn't find stalls drawn in the **Map Builder** (only read Row Builder). Added `EEM.getMapLabels(target)` + merged into `getStallLabels`/`getRvLotLabels` |
| 2.7.168 | Map Builder default grid 10×20 (was 6×12); frontend customer picker `fitSmap` fits BOTH axes + 12px min so all chips show on load |
| 2.7.169 | **ATTEMPTED (did NOT work)** theme-hardening for the vertical-headings bug below — see open item #0 |

---

## OPEN ITEMS (none block the demo unless noted)

0. **🔴 NOT FIXED — Customer booking-form headings render VERTICALLY (one letter per line) on staging.** This is the most important open bug. On `/equine-event/{id}/` (the customer event/booking page) under the **staging Elementor theme** ("National Team Roping"), the section headings collapse into a ~15px-wide × ~277px-tall column with text stacked one letter per line: **"Stall Reservations", "Add-Ons", "Event Pre-Entries"** (the `h4.eem-reservation-section__title`) AND the product-list **"PRODUCT"** head. The on/off toggle sits beside the vertical text. **It renders perfectly on Local** (default theme) — so it's host-theme interference, NOT a plugin layout bug per se.

   **What Whitney's DevTools confirmed (on staging):** the heading `div.eem-reservation-section-heading--collapsible` is `display:flex`; the title rule `.eem-reservation-section-heading--collapsible .eem-reservation-section__title { flex: 1 1 auto }` (public.css ~1770) IS applying; `public.css` IS loaded (the 2.7.166 in-`<head>` fix works); the title computes Space Grotesk 12px/700. Yet the `<h4>` is collapsed to ~15px wide.

   **What was tried and FAILED:**
   - **2.7.166** — enqueue `public.css` before `get_header()` so it lands in `<head>`. Necessary (CSS now loads) but did NOT fix the vertical text.
   - **2.7.169** — theme-hardening block in `public.css` scoped to `.eem-event-page` (see ~line 1773): forced `writing-mode: horizontal-tb !important` on ALL `.eem-event-page *`, `word-break/overflow-wrap: normal !important` + `flex: 1 1 auto !important` on the titles + product head, `flex-direction: row !important` on the headings, and pinned `.eem-reservation-section-toggle` to `flex: 0 0 auto !important`. **Whitney reports this did NOT fix it.**

   **What that rules OUT** (because 2.7.169 force-overrode them with `!important` and it's still broken): writing-mode, word-break, overflow-wrap, flex-direction on the heading, flex-grow on the title, and the toggle stealing width. The cause is something ELSE not yet covered.

   **Leading remaining suspects (check these next):**
   1. **The new CSS may not actually be applied** — verify on staging that `public.css?ver=2.7.169` (NOT an older ver) is the loaded file, and that the `.eem-event-page .eem-reservation-section__title { word-break: normal !important; ... }` rule shows in the Styles/Computed panel **without being struck through**. WP Engine caches hard — confirm the cache was cleared and the `?ver` bumped.
   2. **A width/`max-width` constraint on the `<h4>` or an ancestor** (e.g. theme `max-width: min-content`, or a fixed `width`). 2.7.169 set `min-width:0` but NOT `max-width:none`/`width:auto` on the title — add those. A constrained width + `word-break: normal` would wrap at word boundaries (min = "Reservations"), but combined with `overflow-wrap`/hyphenation it can still narrow further.
   3. **A `transform: rotate()` / `text-orientation` on an ancestor.**
   4. **The element is being laid out by an ancestor grid/flex from the theme** that gives it a 0/tiny track.

   **DEFINITIVE next step (needs the live page — do this in the new chat with Whitney's DevTools):** click the vertical `<h4>` → **Computed** tab → read the actual computed values of: `writing-mode`, `word-break`, `overflow-wrap`, `width`, `max-width`, `white-space`, and `flex`/`flex-basis`; then select the PARENT and read `display`, `flex-direction`, `width`. Whichever is the smoking gun (almost certainly a width/max-width or a property whose Computed value still isn't what 2.7.169 forced → meaning the rule isn't winning/loading) points to the exact counter-rule. Files: `assets/css/public.css` heading block ~lines 1757–1805; rules enqueued via `EEM_Events::render_frontend_styles()` with `?ver=EQUINE_EVENT_MANAGER_VERSION`.

1. **🔴 WP Media modal ("Choose Agreement PDF") renders broken** — when the venue-agreement file picker opens, WP's media-library modal layout is jacked (filter misplaced, screen-reader "Load more"/"Jump to first loaded item" buttons visible). The plugin's own `.media-modal` CSS is minimal (z-index/backdrop only at `admin.css:6082`), so a **broad editor-scoped rule is leaking into the modal**. This is a **documented hard-to-reproduce CSS-cascade issue** — `admin.css:6061` (C7.X.16 Issue E) and `CLEANUP.md` already note it needs a **live DevTools probe of computed styles**, not a code-only audit. **Next step: open the agreement-upload modal on Local in Chrome, inspect the misplaced "Filter by date" element's computed styles, find the `body.eem-shell-page--editor` / `body.post-type-en_reservation` rule that's overriding it, and add a `:not()`/media-chrome exclusion (see the C7.X.18 lesson in CLAUDE.md about excluding WP modal chrome).**
2. **🟡 Auth.net admin "Charge Card"** (Collect Payment) — last v1 payment item, **blocked on live credentials** (Whitney's CTO providing Auth.net API login + transaction key). Code path is wired; needs an end-to-end live charge test once creds land.
3. **🟡 Settled-refund path** — Auth.net refunds AFTER settlement need stored card last-4. Offered earlier; **awaiting Whitney's decision** on whether to build for v1.
4. **🟡 Smoke-suite reconciliation** — 46 failing assertions across 16 files are test-drift (stale fixtures/keys) + seed-dependent, not product bugs. Should be cleaned up so CI is green before release. (Old task #96.)
5. **🟡 OVERHAUL_REPORT.md** is stale (says v2.7.18). Refresh before release. (Old task #97.)
6. **⚪ Multi-source TEC+GEMS picker** — Whitney explicitly chose **"Hold for now."** The idea: let the editor event picker search ALL configured sources and badge each result TEC/GEMS, while keeping single-active source. Architecture already tolerates mixed per-reservation sources (`_en_event_source` is stored per reservation), so no data migration needed when it's built. **Don't build unless asked.**

---

## Audit summary (done this session)
Codebase is healthy for the demo: smoke suite 2727 pass / **0 fatals**; customer form renders cleanly; `error_log` is correctly gated (`debug_log()` behind `WP_DEBUG`); blocked-stalls round-trip verified end-to-end (editor finds → saves `_en_blocked_stalls` → subtracted from the map-derived `available_stall_units` pool in `orders-repository.php:1361`). The GEMS flow works end-to-end: GEMS event → blue Reservations button → branded event page (flyer + info) → styled booking form.

---

## Naming / data-model quick reference (full detail in README.md / CLAUDE.md)
- Prefixes: classes `EEM_`, functions `eem_`, post-meta `_en_*` (section toggles are the canonical `_eem_section_enabled_<shortkey>` since migration `eem-mig-007`), CSS `eem-`, JS `window.EEM`, shortcode `[en_reservation]`.
- Section-toggle reads MUST go through `EEM_Reservations_CPT::read_section_enabled_raw()` / `section_enabled()` (canonical key, legacy `_en_<field>` fallback). The 7 mapped fields: stalls, rv, checkin, addons, group, fees, agreement.
- Event source per reservation: `_en_event_source` (native|tec|feed), `_en_event_id` (TEC/native) OR `_en_external_event_id` (feed/GEMS), `_en_use_global_event_source`.
- Customer-facing reservation URL = virtual route `/equine-event/{reservation_id}/` (the `en_reservation` CPT is `public => false`). Built by `EEM_Events::get_reservation_public_url()`.
