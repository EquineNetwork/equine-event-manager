# Equine Event Manager — Roadmap & To-Do

---

## 🔖 SESSION HANDOFF — 2026-06-21 (end of session, v2.7.545 pending)

**Current state:** v2.7.544 on `main`. Event editor port (2.7.545) is implemented but not yet committed — pending Whitney version-bump approval.

**Session shipped (2.7.543–544):**
- Dashboard quick fixes: Collect Payment → amber icon; Export Report → purple icon; Today's Movement event title links to Daily Movement; partially-refunded orders reclassified to Refunded tab (was incorrectly falling into Unpaid).
- Event editor (`add_event_page.html` + `screen2_event_edit_documents.html`): full port — eyebrow/H1/meta header, View Event + Delete Event topbar buttons, all cards non-collapsible (one long form), single-bordered `.eem-event-cards-wrap` container, Link Reservation dropdown in the rail. Body class `eem-shell-page--event-editor` (separate from `reservation-editor`). *(Uncommitted — needs 2.7.545 approval.)*

**Next up:**
1. Whitney approves → commit 2.7.545 (event editor)
2. Port remaining new mockups (see TO-DO below)

**Standing constraints:**
- Never bump version without explicit Whitney approval each time.
- Reservation 5990 RV map is corrupted — test stall/RV maps on **NTR 6519**.
- One Bash command per call, no chaining, no heredocs (see CLAUDE.md command hygiene).

---

## 🆕 NEW MOCKUPS — TO-DO

Files in `.mockups/`:

- [x] **`add_event_page.html`** — branded Add/Edit Event editor (implemented, uncommitted as 2.7.545)
- [x] **`screen2_event_edit_documents.html`** — Event editor EDIT state with Sheets & Results section expanded (same commit as above)
- [x] **`screen1_sheets_results_manager.html`** — standalone Sheets & Results admin manager page (card treatment + selector + tabs + discipline groups already fully implemented)
- [x] **`taxonomy_categories_admin_page.html`** — Event Categories taxonomy admin (already fully implemented — PHP + CSS match mockup)
- [x] **`daily_movement_print_view.html`** — Daily Movement print view (`&print=1`); per-day sections, arriving/departing groups
- [x] **`reports_print_view.html`** — Reports print view (grouped table + category summary)
- [x] **`screen3_public_events_list.html`** — customer-facing `[en_events view="list"]` styling (public.css)
- [x] **`screen4_public_sheets_results_page.html`** — customer-facing per-event Sheets & Results page (public.css)
- [x] **`preentries_print_view.html`** — division-entrant print view; new print route + Print button on division detail page

- [ ] **`division_detail_page.html`** — division entrant roster (detail view): topbar shell, 3 KPI stat cards, event band, status-filter toolbar, entrants table with meatball actions (Edit Entry / Refund / Cancel), mobile cards

**Mockup cleanup:** reference-check then delete `reservation_overview_page.html` + `events_admin_page.html` (suspected zero-ref). `event_entry_editor_page.html` superseded by `division_editor_page.html` — remove in the same pass.

---

## ⏸ Deferred (no blocker — do when convenient)

- [ ] Global control/button radius sweep → 8px (currently base `.eem-btn` = 4px, `input.eem-field-input` = 3px; mockups want 8px). Includes locked primary/secondary/danger button system + dead legacy control-CSS / `!important` strip.
- [ ] Space Grotesk → IBM Plex Sans plugin-wide.
- [ ] **MED-4** — Admin Collect Payment (Auth.net) double-charge window. Non-atomic `already_paid` check vs live charge → double-click can fire two authCaptures. Fix: per-order `GET_LOCK` around read→charge→mark. ⚠️ payment path — Whitney sign-off + live test required.
- [ ] **LOW-3/4** — Minor Stripe confirm no already-paid recheck (no 2nd charge risk) + mark-paid-manual non-atomic duplicate note. Low priority.
- [ ] Reports — visual verify Customer List + Refund Log render correctly in browser.

---

## 🔵 Strategic (v2+)

### v2 — Architecture + Features

1. **`en_venue` → canonical `EEM_Venue` unification** — persist the link between `en_venue` post and its `EEM_Venue` row; make the venue editor write through to the relational store.
2. **Postmeta → relational de-coupling** — move reservation/division config out of `wp_postmeta` into relational tables. Phase 1 (funnel) is the recommended first move. Full plan: `docs/WORKPLAN-postmeta-decouple.md`.
3. ~~**Weekly Rate pricing** — third pricing option alongside Nightly and Weekend Rate, for stalls + RVs. Mirror Weekend Rate implementation.~~ ✅ Done
4. ~~**Paddock Assignments** — merge adjacent stall chips into a bookable paddock unit with its own rate.~~ ✅ Done
5. **Upload .xlsx → Stall Grid** — parse `.xlsx` into stall rows via `ZipArchive` + `SimpleXML`; no new Composer deps. Include "Download Example Template" link.
6. **Entry-aware Dashboard metrics** — Entries Sold count + entry revenue KPI card; Upcoming Events card (when Native Events is ON); gate on feature flags.
7. **Mobile + PWA** — responsive/touch audit on all admin + customer pages (tablet ~768px, phone ~390px); PWA wrapper (`manifest.json` + service worker + install prompt).
8. **"Accept Deposits" option** — deposit toggle + amount on stall/RV pricing; customer pays deposit at checkout; balance collected at event via Collect Payment.

### v3 — Architecture + Deferred Features

1. **Global Handicaps data ownership / API integration** — GH as system of record; atomic inventory-reserve API. Full spec: `docs/ARCHITECTURE-DATA-OWNERSHIP.md`.
2. **Event Entries — competition management** — horse/rider details, results/placings, payouts, go-rounds, draw order. Extends the Divisions entrants ledger.
3. **PDF Venue Map → overlay** — upload a PDF venue map; render to image + drop/snap stall hotspots. Pairs with Facility Layout Templates.
4. **Sheets & Results — more source types** — CSV upload, Google Sheets link, external URL. Source-type column on `wp_eem_sheet_entries` + per-type render.
5. **Financial-security audit** — full money path (Stripe + Auth.net charge/refund, amount integrity, idempotency, webhook auth, capability + nonce). Deliverable: `docs/SECURITY-AUDIT-REPORT.md`.
6. **Strict concurrency audit** — every reserve/assign write path under high-demand sellout conditions; document guarantees.
7. **Repo / distribution cleanup** — delete dead dev docs; `.gitattributes export-ignore` so shipped plugin ZIP carries only runtime code.

### v4 — Headless Clients *(gated on v2 decouple + v3 API)*

1. **PWA** — offline-capable, installable web app over the v3 API.
2. **Native mobile app** — iOS/Android over the same API contract.

---

## ✅ v1 — DONE

v1 is feature-complete and live. Key shipped items:

- Core reservation + orders engine (stalls, RV, add-ons, fees, discounts, custom line items)
- Stripe + Authorize.net payment processing (two live Auth.net charges verified 2026-06-12)
- TEC + GEMS event sources (Native Events gated "Coming Soon" in Settings until v2)
- Full admin UI overhaul: Dashboard, Orders, Order Detail, Reservations, Reservation Editor, Stall & RV Charts, Entries/Divisions, Sheets & Results, Venues, Producers, Events, Notifications, Reports, Settings, Customers, Daily Movement — all ported to new design system
- Customer-facing event page (`[en_reservation]`), confirmation email (Emogrifier-inlined), order receipt PDF (Dompdf)
- Required Documents (admin-defined + upload + Mark Satisfied)
- Additional Shavings (structured add-on type, 2.7.521–527)
- Postmeta → relational de-coupling complete (2.7.311–318): `EEM_Reservation_Config` + `wp_eem_reservation_config` table
- Venue entity + Facility Layout Templates (copy-on-use clone)
- Notifications page (audience builder + batched send + history)
- Entries → Divisions (entrants ledger + spots cap + Division detail page)
- Soft-delete / Trash lifecycle for orders
- Bulk "Send Payment Link" on Orders
- Stall & RV Charts: By Location readiness grid (per-stall-night status), By Customer, move-customer flow, print views
- Daily Movement + print view
- Concurrency hardening: MED-3 (edit-dates lock) + LOW-5 (custom-item double-submit) fixed
- Smoke suite: 154 files, 0 failures

**Known watch-outs (still current):**
- Reservation **5990 RV map is corrupted** — test maps on **NTR 6519** only.
- `$or_num` dead assignment in print By Customer loop — harmless, tidy when convenient.
- RV lot name/number split keys on last space in the label — verified correct on NTR 6519 real data; only breaks on externally-sourced labels not built by this plugin's path.

---

## 📚 Reference documents

- `CLAUDE.md` — authoritative decisions, conventions, chunk history, CSS/JS discipline rules.
- `README.md` — data model, file inventory, conditional visibility rules, naming conventions.
- `docs/decisions.md` — product decisions log (TEC integration, refunds, cancellation policy, etc.).
- `docs/BRAND_GUIDE.md` — color tokens, typography scale, component specs.
- `docs/ARCHITECTURE-VENUES.md` — source-agnostic Venue model + resolver + Facility Layout Templates.
- `docs/ARCHITECTURE-DATA-OWNERSHIP.md` — data storage, GH integration models, WordPress-replaceable principle.
- `docs/WORKPLAN-postmeta-decouple.md` — postmeta→relational migration plan + estimate.
- `OVERHAUL_REPORT.md` — before/after of the original Codex-overhaul effort.
