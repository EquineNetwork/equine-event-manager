# Plugin Health & V2 (PWA/Native) Readiness — 2026-06-27

Synthesis of three read-only audits (dead code, bloat/duplication, PWA/native API
readiness). Goal: a healthy, no-bloat, API-clean core ready to support a V2 PWA or
native app.

**Overall health: ~7/10.** Well-structured, clean repo/data layer, a real REST API
already exists, almost no over-abstraction, all assets in use. The opportunities are
(a) deleting a modest set of genuinely-dead private methods, (b) one duplication to
collapse, (c) splitting one true god-object class, and (d) extracting payment +
assignment logic into services — which doubles as the main V2 API enabler.

---

## 1. Dead code (safe to remove after a confirming grep)

**Rule (CLAUDE.md): verify each with a full-project grep before deleting.** The audit
already grepped; re-confirm per symbol at deletion time.

### Confirmed dead (HIGH confidence) — 22 private methods + 2 functions
- 1 JS function: `eemWizardSnooze()` — assets/js/admin.js ~1233 (zero callers).
- 1 standalone PHP function: `eem_is_cancellation_policy_enabled()` (no non-test callers).
- 22 private/static methods with zero call sites, incl.:
  - orders-repository: `allocate_chart_units()` (2347)
  - admin (god-object): `get_stall_assignment_reservations()` (5494), `render_dashboard_orders_views()` (5592), `render_import_tec_events_notice()` (8854), `submenu_contains_slug()` (648)
  - dashboard-page: `render_kpi_grid()` (218), `render_range_filter()` (198)
  - daily-movement-page: `render_movement_overview()` (970), `render_stat_cards()` (531)
  - reservation-editor-page: `count_usable_rows_with_zone()` (874), `count_valid_zones()` (856)
  - reservations-cpt: `format_datetime_for_input()` (3381), `validate_chart_block_ranges()` (1652)
  - events: `format_event_body_content()` (4302)
  - shortcodes: `build_hosted_docs_section_html()` (8266), `build_receipt_email_html()` (5910), `get_zones_of_rv_units()` (11293), `render_checkbox_product_line_item()` (11915)
  - report-exporter: `neutralize_csv_cell()` (81)
  - reports-repo: `order_in_date_range()` (96), `order_matches_status()` (120)

### ⚠️ NOT dead — do not delete (false-positive guard)
- `EEM_Settings_Page::render_*_panel()` (addons/branding/communications/danger/import_export/integrations/payments/shortcodes) — **called via variable dispatch** `$this->{'render_'.$panel_id.'_panel'}()`. They look unreferenced to grep but are live. Leave them.

### Write-only post-meta (low urgency)
- `_en_special_instructions` (written, never read — likely vestigial; confirm before removing the write).
- `_equine_event_manager_imported_tec_event_id` / `_..._venue_id` (TEC import audit-trail; written, never read — keep as audit data or document intent).

### Deprecated-but-still-used (leave until their removal chunk)
- `EEM_Reservations_List_Repo` deprecated method (~415) — thin proxy, C13 removal target.
- `EEM_Reservation_Editor` (deprecated class 2.4.0) — no longer registers metaboxes; superseded by `EEM_Reservation_Editor_Page`. Candidate for deletion once confirmed nothing loads it.

---

## 2. Bloat / duplication

- **Order-number formatting duplicated 6×** (`sprintf('#%05d', ...)`) across entries.php (×2), reports-repo.php (×3), rest-orders-controller.php. → Extract `EEM_Formatter::format_order_number()`, replace all. (Order Detail + Orders list already have their own `format_order_number_display()` — fold those in too.)
- Price/date formatting scattered but context-specific → **leave** (low value, not true duplication).
- **No meaningful over-abstraction** — wrapper classes (PDF, PWA, Surcharge, Reservation_Editor) all earn their keep.
- **Dead CSS**: ~2–5% estimated (500–1000 lines), hard to pin without a coverage scanner; `admin-legacy.css` (10K lines) is the known grandfathered tech-debt (separate strip task).
- **Largest files**: admin.css 14.6K LOC, admin.js 11.5K LOC, admin-legacy.css 10K LOC — all in use; JS modularization optional.

### God objects
| File | LOC | Methods | Verdict |
|---|---|---|---|
| `admin/class-equine-event-manager-admin.php` | 15,450 | 244 | **HIGH — split** into Hooks / Exports / Bulk-Ops / Reports |
| `includes/...-reservations-cpt.php` | 3,948 | 97 | MEDIUM — extract Validator + Event-Resolver |
| `includes/...-events.php` | 5,280 | 49 | LOW — optionally extract Feed-Parser |
| `includes/...-orders-repository.php` | 3,382 | 88 | OK — appropriate, single-domain |

---

## 3. V2 PWA / native readiness (~60–70% ready)

**Strong foundation:** a deliberate REST API (`eem/v1`, 8 controllers, ~30 routes) returning clean JSON, backed by ~14 reusable repos/services. Reads ~95% covered; facility check-in/out writes 100% covered.

**The gap = transactional writes + auth:**
- **No REST for payment** (charge/refund/create-order) — logic is welded into the checkout shortcode (`EEM_Shortcodes`). Highest-effort, highest-value extraction → `EEM_Payment_Service` + endpoints.
- **No REST for stall assignment** (assign/unassign/auto-assign/available-units) — trapped in admin AJAX handlers; logic ~70% reusable → `EEM_Assignment_Service` + endpoints.
- **No token auth** — WP-cookie-only today. A native app needs bearer-token (or WP application-passwords) + a `wp_eem_api_tokens` table + `/auth/login|refresh|logout`.
- **CORS** headers not set (only matters for a web PWA on a different origin).
- Minor: group-management, stall-map upload, bulk ops not yet REST; Stripe webhook should verify signature.

**Effort to a functional native app: ~2–3 weeks** (token auth ~1–2d, assignment endpoints ~3d, payment endpoints ~5d, payment service refactor ~3d, CORS ~hours). Biggest risk: extracting payment without breaking the live checkout.

**Key V2 kickoff decisions:** payment collection model (Stripe PaymentSheet vs server charge), assignment UI (visual grid vs list picker), offline support y/n, auth scope (facility-only vs full admin), direct-REST vs API-gateway.

---

## 4. Prioritized roadmap

**Tier 1 — quick, safe health wins (do now)**
1. Delete `eemWizardSnooze()` + the 22 confirmed-dead methods (grep-verify each).
2. Extract `EEM_Formatter::format_order_number()`; replace the 6 duplicates.

**Tier 2 — structural health (focused chunks)**
3. Split `class-equine-event-manager-admin.php` god-object (Hooks / Exports / Bulk-Ops / Reports).
4. Extract `EEM_Reservation_Validator` + event-resolver from reservations-cpt.
5. Strip `admin-legacy.css` (existing task).

**Tier 3 — V2 enablers (also improve health by decoupling)**
6. `EEM_Payment_Service` + payment REST endpoints (decouples payment from shortcode).
7. `EEM_Assignment_Service` + assignment REST endpoints.
8. Token auth (`wp_eem_api_tokens` + `/auth/*`) + CORS.

Tier 3 is the bridge: doing it makes the core *both* healthier (logic out of presentation) *and* V2-ready.
