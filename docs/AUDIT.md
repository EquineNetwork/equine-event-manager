# AUDIT.md — Equine Event Manager Plugin

Phase 1 audit per `CLAUDE.md`. Read-only assessment of the existing codebase against the README spec and the 14 HTML mockups.

> **Status:** This audit reflects the state of `cleanup/pass-1-dead-code` (3 commits past `main`): the en-→equine rename, removal of the dead PDF receipt stub chain (-225 LOC), and extraction of the inline frontend-style heredoc to `public/css/equine-event-manager-public.css` (-685 net LOC in PHP).

---

## 1. File inventory

| File | LOC | Purpose |
|---|---|---|
| `equine-event-manager.php` | 44 | Bootstrap: defines constants, registers activation hook, instantiates main class |
| `includes/class-equine-event-manager.php` | 149 | Hook wiring (`run()`) — registers every action/filter in one place |
| `includes/class-equine-event-manager-activator.php` | 225 | `register_activation_hook` target. Creates custom tables, runs migrations |
| `includes/class-equine-event-manager-mailer.php` | 221 | Static `Equine_Event_Manager_Mailer::send_html_email()` helper |
| `includes/class-equine-event-manager-orders-repository.php` | 1,722 | Order CRUD against custom tables; queries, formatting, sums |
| `includes/class-equine-event-manager-events.php` | 4,055 | Native event CPT + TEC integration + event feed parsing + event shortcodes + 2 widgets |
| `includes/class-equine-event-manager-reservations-cpt.php` | 3,760 | `en_reservation` CPT registration + meta save/sanitize + admin metaboxes |
| `admin/class-equine-event-manager-reservation-editor.php` | 975 | Reservation editor shell (header/overview/styling for CPT edit screen) |
| `admin/class-equine-event-manager-admin.php` | 10,000 | Every admin page: Dashboard, Orders, Order Detail, Invoicing, Stall Charts, Reports, Settings + handlers |
| `admin/css/equine-event-manager-admin.css` | 12,343 | Single admin stylesheet |
| `public/class-equine-event-manager-shortcodes.php` | 10,923 | Front-end reservation form, validation, Stripe + Authorize.net checkout, invoice payment page, emails |
| `public/css/equine-event-manager-public.css` | 682 | Extracted in pass-1: front-end event shortcode styles |

**Total: 46,099 LOC** (PHP: 32,074 / CSS: 13,025 / JS: 0)

### Notable absences

- **No JS files exist.** Mockups expect `assets/js/admin.js` with delegated event handlers (`toggleSection`, `applyControls`, `tagToggle`, `showSaveToast`, etc.). Currently zero JavaScript shipped — anything interactive is either WP-core JS, inline `onclick`, or doesn't exist yet.
- **No REST routes.** Zero `register_rest_route` calls. The `app-platform-readiness-checklist.md` calls for this; it's future work.
- **No `assets/` directory.** Spec says CSS lives at `assets/css/admin.css`. Currently at `admin/css/…`.

---

## 2. PHP class inventory

| Class | File | Role |
|---|---|---|
| `Equine_Event_Manager` | `includes/class-equine-event-manager.php` | Main hook wiring controller |
| `Equine_Event_Manager_Activator` | `includes/class-equine-event-manager-activator.php` | Activation/migration |
| `Equine_Event_Manager_Mailer` | `includes/class-equine-event-manager-mailer.php` | Static email helper |
| `Equine_Event_Manager_Orders_Repository` | `includes/class-equine-event-manager-orders-repository.php` | Order CRUD |
| `Equine_Event_Manager_Reservations_CPT` | `includes/class-equine-event-manager-reservations-cpt.php` | Reservation CPT |
| `Equine_Event_Manager_Events` | `includes/class-equine-event-manager-events.php` | Event CPT + feed integration |
| `Equine_Event_Manager_Upcoming_Events_Widget` | `includes/class-equine-event-manager-events.php:3921` | WP_Widget subclass |
| `Equine_Event_Manager_Featured_Event_Widget` | `includes/class-equine-event-manager-events.php:3994` | WP_Widget subclass |
| `Equine_Event_Manager_Admin` | `admin/class-equine-event-manager-admin.php` | Admin pages |
| `Equine_Event_Manager_Reservation_Editor` | `admin/class-equine-event-manager-reservation-editor.php` | CPT edit shell |
| `Equine_Event_Manager_Shortcodes` | `public/class-equine-event-manager-shortcodes.php` | Front-end form |

11 classes. Spec convention is `EEM_*` — all current classes use the `Equine_Event_Manager_*` long form. **Conventions drift, Phase 2 rename track.**

### Largest single methods (Phase 3 port candidates)

| Method | File | LOC | Maps to mockup |
|---|---|---|---|
| `render_reservation()` | shortcodes.php:104 | **878** | `event_page.html` |
| `render_settings_page()` | admin.php:5390 | **668** | `settings_page.html` |
| `render_stall_chart_page()` | admin.php:2092 | **299** | `stall_charts_page.html` + `stall_chart_detail.html` |
| `render_order_details_page()` | admin.php:4348 | ~430 | `order_detail_page.html` |
| `render_dashboard_page()` | admin.php:1382 | ~230 | `dashboard_page.html` |

These five methods are ~2,500 LOC of inline-HTML page renderers. Each maps cleanly to a single mockup. Phase 3 will port each one against its mockup.

---

## 3. Data layer

### Custom Post Types

| Slug | Registered in | Notes |
|---|---|---|
| `en_reservation` | `reservations-cpt.php:24` | Canonical. Used for reservation templates. **Matches spec.** |
| `en_event` | `events.php:730` | Native events (conditional, behind feature flag) |
| `en_venue` | `events.php:811` | Native event venues |
| `en_producer` | `events.php:836` | Native event producers |

**Missing per spec §1:** `en_order` (orders) and `en_stall_chart` (stall charts) — both stored as custom-table rows instead.

### Custom database tables

| Table | Created in | Purpose |
|---|---|---|
| `{prefix}en_stall_reservations` | `activator.php:78` | Stall order rows |
| `{prefix}en_rv_reservations` | `activator.php:79` | RV order rows |
| `{prefix}en_report_exports` | `activator.php:162` | CSV export log |

**Drift:** README §13 specifies `wp_eem_` DB prefix. Current code uses `en_`. **This is a production-data rename — flagged as an open question, not autonomous.**

### Post meta keys (`en_reservation` CPT)

All prefixed `_en_*`, matching spec §3 canonical. Sampled set (~40 keys observed):

- Description/dates: `_en_description`, `_en_available_start_date`, `_en_available_end_date`, `_en_start_date`, `_en_end_date`
- Stall: `_en_stalls_enabled`, `_en_stall_inventory`, `_en_stall_available_*`, `_en_stall_chart_*`
- RV: `_en_rv_enabled`, `_en_rv_inventory`, `_en_rv_lots`, `_en_rv_lot_selection_enabled`, `_en_rv_addons`, `_en_rv_available_*`
- Add-ons: `_en_general_addons_enabled`, `_en_general_addons`
- Group: `_en_group_reservations_enabled`, `_en_group_rider_deposit_*`, `_en_group_rider_grounds_fee_*`
- Venue: `_en_venue_map_enabled`, `_en_venue_map_image_id`, `_en_venue_map_download_url`, `_en_venue_address`, `_en_venue_name`
- Event link: `_en_event_id`, `_en_native_event_id`, `_en_external_event_*`, `_en_event_source`, `_en_use_global_event_source`

**The README §3 spec lists meta keys not yet in code:**
- `_en_checkin_enabled`, `_en_checkin_time`, `_en_checkout_time`
- `_en_stall_stay_nightly`, `_en_stall_stay_weekend`, `_en_stall_weekend_start`, `_en_stall_weekend_end`
- `_en_stall_schedule_enabled`, `_en_stall_open_at`, `_en_stall_close_at`
- `_en_stall_nightly_rate`, `_en_stall_weekend_rate`
- `_en_stall_eb_enabled`, `_en_stall_eb_cutoff`, `_en_stall_eb_nightly_rate`, `_en_stall_eb_weekend_rate`
- `_en_stall_shavings_required`, `_en_stall_shavings_per_stall`, `_en_stall_shavings_price`
- `_en_stall_assignments_enabled`, `_en_stall_mode`, `_en_stall_blocks`, `_en_stall_blocked_numbers`, `_en_stall_map_file`
- `_en_rv_*` mirror fields for the same stay-type/EB/schedule pattern
- `_en_agreement_enabled`, `_en_agreement_file`, `_en_agreement_label`
- `_en_fees_enabled`, `_en_fee_label`, `_en_fee_type`, `_en_fee_value`

The current code stores some of this denormalized into JSON arrays (`_en_stall_chart_stall_blocks`, `_en_rv_lots`). **The mockup data model is richer and more typed than what's stored today.** This is a Phase 2/Phase 3 data-model expansion, may need a migration depending on what's already in your test data.

### Options (`get_option` keys)

- `equine_event_manager_company_settings`
- `equine_event_manager_payment_settings`
- `equine_event_manager_reservation_message_settings`
- `equine_event_manager_special_requests_description`

Plus standard WP options (`admin_email`, `date_format`, `time_format`, `from_email`, `support_email`).

The four plugin options use the `equine_event_manager_*` prefix instead of canonical `eem_*`. **Conventions drift.** Renaming options is a migration, flagged below.

### Shortcodes registered

| Shortcode | Handler | Spec status |
|---|---|---|
| `en_reservation` | `Shortcodes::render_reservation` | **Canonical** ✓ |
| `en_stall_reservation_form` | `Shortcodes::render_stall_reservation_form` | Useful — looks up reservation by `event_id` (Elementor) |
| `en_rv_reservation_form` | `Shortcodes::render_rv_reservation_form` | Useful — same pattern as above for RV |
| `equine_event_manager_event_reservation` | `Shortcodes::render_event_reservation_shortcode` | **Drift** — long-form, should consolidate |
| `equine_event_manager_events` | `Events::render_events_shortcode` | **Drift** — should be `en_events` |
| `equine_event_manager_event` | `Events::render_event_shortcode` | **Drift** — should be `en_event` |

### Hooks wired

The main loader (`Equine_Event_Manager::run()`) registers ~50 actions/filters: CPT lifecycle, admin menu/columns, `admin_post_*` form handlers, `wp_ajax_*` Stripe handlers, optional native-events + TEC integration hooks. All `admin_post_*` and `wp_ajax_*` action names use the `equine_event_manager_*` prefix — these are renaming candidates but also affect external links and posted forms, so they're a coordinated rename (HTML form actions + JS endpoints + PHP handler all need to move together).

---

## 4. Asset inventory

### CSS

- `admin/css/equine-event-manager-admin.css` — **12,343 lines**, ~4,660 `.eem-*` class refs, ~171 legacy `.en-*` class refs, plus WP-shell helpers (`.postbox`, `.widefat`, `.wp-*`).
- `public/css/equine-event-manager-public.css` — **682 lines** (extracted in pass-1; classes still use `.equine-event-manager-event-*` prefix, needs `.eem-event-*` rename).

Three `wp_enqueue_style()` calls total: reservation editor shell, backend admin shell, frontend event spotlight.

### Inline styles in PHP

- **5 inline `<style>` blocks** scattered across admin.php (lines 663, 6495, 7338), reservation-editor.php (108), shortcodes.php (7004). Mostly for print views and reservation-editor "critical CSS" fallbacks.
- **116 inline `style=""` attributes**: 97 in shortcodes.php, 18 in admin.php, 1 in reservations-cpt.php. Anti-pattern — should land in `assets/css/admin.css` during Phase 3.

### JavaScript

**None.** Mockups assume helpers like `toggleSection`, `toggleSectionEnabled`, `toggleSwitch`, `toggleStay`, `applyControls`, `applyFeeTypeVisibility`, `tagToggle`, `tagFilter`, `tagPick`, `tagRemove`, `showSaveToast`. Phase 3 deliverable is `assets/js/admin.js` carrying these.

---

## 5. Findings — categorized per CLAUDE.md §1

### A. Dead code

| Item | Location | Notes |
|---|---|---|
| ~~PDF receipt stub chain~~ | ~~shortcodes.php:3641–3865~~ | **Already removed in pass-1** (commit `4ce…`). Returned empty string, no callers. Real Dompdf implementation needed per spec §1 + §16. |
| ~~`render_frontend_styles` 700-line heredoc~~ | ~~events.php:2120~~ | **Already extracted in pass-1** to `public/css/equine-event-manager-public.css`. |
| `maybe_redirect_legacy_event_manager_admin_routes` | admin.php:370 | **NOT dead.** Initial audit miscalled this. Bug-fix log shows it was added to handle stale `post_type=en_reservation` query params on current admin URLs (bookmarks, screen-options state). Keep. |
| `render_event_reservation_shortcode` | shortcodes.php:62 | Possibly redundant with `render_reservation`. The shortcode `[equine_event_manager_event_reservation]` is non-canonical anyway. Verify no live pages use it before consolidating. |
| Various `if ( method_exists( $this->shortcodes, 'X' ) )` checks | main loader, lines 113–119 | Defensive checks against a class the loader literally just instantiated. Codex pattern. Safe to inline-remove during Phase 2. |

### B. Duplicated logic

| Pattern | Locations | Resolution |
|---|---|---|
| Stall vs RV stay-type / schedule / EB / inventory logic | Repeated as parallel implementations throughout shortcodes.php, reservations-cpt.php, admin.php | Most stall/RV pairs could collapse to a single helper parameterized by component type. Big Phase 2 win, but risky — many small differences may be intentional. |
| `[en_stall_reservation_form]` + `[en_rv_reservation_form]` | shortcodes.php:1058, 1076 | Both delegate to `render_legacy_event_form(event_id, type)` which finds a reservation by event_id then calls `render_reservation`. **Consolidate to single `[en_reservation event_id="..."]` form** that takes either `id` or `event_id`. Keep behaviour. |
| Order-notes parsing | admin.php has 10+ `parse_*_from_notes()` helpers (`parse_general_addon_quantities_from_notes`, `parse_group_charge_breakdown_from_notes`, etc.) | The orders table stores structured data as freeform `notes` text and parses it back. **This is the highest-impact duplication in the codebase** — should be a real schema or a single typed serialization. Phase 2 candidate; affects data model, ask before migrating. |
| Print-view inline styles | 3 separate `<style>` blocks in admin.php (order print, reservation overview print, plus settings) | Consolidate into a single print stylesheet during Phase 3. |
| Sanitize-then-redirect-then-notice pattern | `redirect_to_order_notice`, `redirect_to_reservation_overview_notice`, `redirect_to_stall_chart_notice`, `redirect_to_reservation_notice_destination` (admin.php:9770–9846) | Four near-identical helpers. Collapse to one. |

### C. Over-abstraction

| Pattern | Notes |
|---|---|
| 200+ small private methods in `admin.php` | Many are one-liners (`format_money`, `format_phone_label`, `format_stay_type_label`). Per CLAUDE.md "default to inline unless used 3+ times" — keep `format_money`, but several of these are single-call wrappers. Phase 2 pass. |
| 40+ private form-field renderer methods in `reservations-cpt.php` (`render_datetime_field_row`, `render_textarea_field_row`, etc., 2822–3346) | These exist because the meta-box uses ad-hoc field rendering. Phase 3 port to mockup HTML will replace this whole layer with consistent `.field-row` markup — most can be deleted then. |
| Defensive `if ( method_exists( $this, '...' ) )` self-checks | A handful sprinkled around the codebase. Unnecessary in a single-instance plugin. |
| `Equine_Event_Manager_Mailer` static class with one public method | Borderline — only used 3 times. Could inline as a function but the abstraction barely costs anything. Leave. |

### D. Conventions drift (per README §13)

| Surface | Current | Canonical | Renaming cost |
|---|---|---|---|
| Class names | `Equine_Event_Manager_*` (11 classes) | `EEM_*` | Mechanical search/replace across PHP — affects autoload & class refs only |
| `admin_post_*` action names | `equine_event_manager_*` (10 hooks) | `eem_*` per general pattern | **Coordinated** — affects PHP handlers, HTML form `action=` URLs, any external links |
| `wp_ajax_*` actions | `equine_event_manager_*` (~5 hooks) | `eem_*` | Coordinated with JS callers (currently no JS, so easier) |
| Option keys | `equine_event_manager_*` (4 keys) | `eem_*` per pattern | **Migration required** — old keys → new keys, fallback read |
| CSS file path | `admin/css/equine-event-manager-admin.css` | `assets/css/admin.css` | Plus update enqueue path |
| CSS class names in public CSS | `.equine-event-manager-event-*` | `.eem-event-*` | PHP emitting markup + CSS file in lockstep |
| Custom table prefix | `{wp_prefix}en_*` | `{wp_prefix}eem_*` | **Migration required** — table rename on activate, fallback queries during transition |
| Plugin file name | `equine-event-manager.php` | matches slug ✓ | n/a |
| Text domain | `equine-event-manager` | matches ✓ | n/a |
| Post meta prefix | `_en_*` | `_en_*` ✓ | n/a |
| Reservation shortcode | `[en_reservation]` | `[en_reservation]` ✓ | n/a |

The good news: **all data-stored prefixes (post meta `_en_*`, shortcode `[en_*]`) already match canonical.** Drift is concentrated in PHP class names, action hook names, and the few non-canonical shortcodes — which are all rename-only (no migration). The two surfaces that need real migration are options keys and DB table names.

### E. Missing functionality (in mockups, not in code)

| Mockup feature | Status |
|---|---|
| Section collapse/expand chevron in card header | No JS exists; missing entirely |
| Section enable toggle that also collapses body | Missing |
| `data-controls` driven conditional row visibility | Missing; current admin uses ad-hoc PHP conditionals to skip rendering |
| At-least-one-stay-type constraint with inline hint | Stay types stored as fields but not enforced with the mockup's hint UI |
| Tag multi-select for blocked stalls/RV lots | Current code has free-entry textarea + a basic select; mockup has searchable chip UI |
| Save-success toast | No toast UI at all; saves use WP admin notices |
| Dashboard "Needs Attention" links | Dashboard renders but doesn't have the cross-screen linking pattern |
| `+ New Reservation` button on Reservations list page | Spec §1 calls for visible button on list screen; bug-fix log says it was added as banner buttons (line 28) — verify in mockup port |
| Fees value row hides on type=None; switches input on flat vs pct | Fees feature itself not in current code at all (no `_en_fees_*` meta keys observed) |
| PDF receipt via Dompdf | Stub removed; needs real implementation |
| Customer confirmation email HTML template | Mailer exists but the rich HTML template from `customer_confirmation_email.html` isn't ported |
| Reports CSV export history with file naming pattern | Logging exists in `en_report_exports` table; file naming convention `equine-event-manager-report-{id}-{date}.csv` vs spec's pattern — verify |
| Drag-and-drop stall assignment (SortableJS) | Current chart is a search/print board; no drag-and-drop |
| Print View teal button on chart detail | Spec wants a print view; bug log mentions print mode exists (line 64) — verify mockup parity |
| 6-tab Settings: General, Events, Payments, Email, Notifications, Advanced | Current settings page is one giant 668-line method; needs tab structure per mockup |

### F. Wrong functionality (in code, conflicts with mockups)

| Conflict | Notes |
|---|---|
| Settings page is one form | Mockup is 6 distinct tabs with per-tab save |
| Order details lacks the More ▾ dropdown | Spec wants More ▾ with Refund/Resend/Edit/Delete in one dropdown; current admin has separate buttons. Cosmetic — Phase 3 port. |
| Stall-chart "view mode dropdown" with two modes | Current code has `Stall Assignments View` and `Stall Counts Per Night` (bug-fix log line 112); mockup is the canonical truth — verify these match |
| Inline-style sprinkles (116 of them) | Should not exist; Phase 3 will remove as pages get ported |
| Stripe-only checkout in mockups; current code also includes Authorize.net | The codebase has Authorize.net refund handling; mockups don't mention it. **Ask** — is Authorize.net live and needs to stay? |
| Native Events feature (en_event/en_venue/en_producer CPTs) | Whole subsystem behind a feature flag; not in mockups. Bug-fix log shows it as a real feature with Settings toggle. **Ask** — keep or strip? |
| TEC (The Events Calendar) integration | Conditional. Not in mockups but a legitimate plugin-integration concern. **Ask** — supported officially? |

---

## 6. Open questions for you

Things I'd otherwise act on but stopped per CLAUDE.md "Ask me first":

1. **Authorize.net** — keep or strip? It's in the code with refund handling; mockups are Stripe-only. If live customers use it, that's a "feature in code not in mockups."
2. **Native Events subsystem** (`en_event`/`en_venue`/`en_producer` CPTs, ~1,500 LOC in events.php) — keep or strip? Mockups assume events come from a feed (Settings tab). If you've moved fully to the feed model, the native events code is large dead weight.
3. **TEC integration** — same question. Conditional on TEC being active; ~500 LOC. Keep if any producer uses TEC, drop otherwise.
4. **DB table prefix** rename `{wp}en_*` → `{wp}eem_*`. Cost: write activator migration, dual-read during transition. Worth it for convention conformance? Or accept the drift and document.
5. **Option key** rename `equine_event_manager_*` → `eem_*`. Same trade-off.
6. **Spec-fields not in code** (Check-In/Check-Out times, stall stay-types nightly/weekend, EB pricing, agreement, fees with flat/pct types). These look like deliberate spec additions. Confirm they should be added in Phase 2/3 rather than features I missed somewhere.
7. **PDF receipt** — Dompdf in scope per spec §16. Should I plan to add as a bundled dependency or load conditionally?
8. **`[equine_event_manager_event_reservation]` shortcode** (long-form). Is this on any pages? If not, safe to drop in Phase 2.

---

## 7. Recommended Phase 2 order

Per CLAUDE.md §51-62 ("Slim down" — delete dead, collapse duplicates, flatten over-abstraction, align conventions):

1. **(answers needed first)** Decide Native Events / TEC / Authorize.net keep-or-drop. If any goes, that's the single biggest delete pass.
2. **Consolidate shortcodes** — keep `[en_reservation]` + the event-ID-aware variants (`en_stall_reservation_form`, `en_rv_reservation_form`); remove or alias the `equine_event_manager_*` long-form shortcodes pending your confirmation.
3. **Class rename** `Equine_Event_Manager_*` → `EEM_*`. Mechanical, single commit per class.
4. **Move CSS to `assets/css/admin.css`** + rename remaining legacy `.en-*` selectors (171 instances).
5. **Collapse the four `redirect_to_*_notice` helpers** into one.
6. **Collapse stall/RV parallel paths** where safe. Stop if a path-specific quirk shows up — note in audit and move on.
7. **Order-notes structured-text → real schema.** This is the big one; coordinate with you on a migration plan first.
8. *(optional, can defer to Phase 3)* Skip the per-field render helpers in `reservations-cpt.php` — they'll be replaced wholesale when porting `edit_reservation_page.html` markup.

After Phase 2, expected LOC drop:
- ~1,500 from Native Events removal (if dropped)
- ~500 from TEC integration (if dropped)
- ~300 from shortcode/helper consolidation
- ~500 from CSS legacy purge
- Modest from class renames (no LOC change, just churn)

Realistic Phase 2 target: 35k–40k LOC down to ~30k before Phase 3 mockup port.

---

## 8. Out of scope for this audit

- Performance profiling (no slow query analysis, no enqueue audit beyond file counts)
- Security review (no nonce/escaping/sanitization deep-dive — that's `/security-review` territory)
- The 14 mockup HTML files were sampled, not read end-to-end. Each Phase 3 page port will re-read its mockup completely.
- Stripe webhook implementation status (referenced in spec; not deeply verified)
- i18n coverage (`__()` usage exists; not exhaustively audited)

---

## 9. Pass-1 commits on this branch

Already shipped on `cleanup/pass-1-dead-code`:

1. `0fe2e6b` — Rename plugin: en-event-manager → equine-event-manager (baseline)
2. *(pass-1 dead-code commit)* — Remove unused PDF receipt stub chain (-225 LOC)
3. *(pass-1 CSS extraction commit)* — Move 700-line frontend CSS heredoc out of events.php

Net effect: -910 LOC of PHP, +682 LOC of dedicated CSS file. Behavior unchanged.
