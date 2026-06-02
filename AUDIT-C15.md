# C15 — Reports: Mockup Walkthrough Pre-Audit

**Mockup:** `.mockups/reports_page.html` (618 lines). **Route:** `equine-event-manager-reports` (exists, but renders the LEGACY postbox export form in `EEM_Admin::render_reports_page` — to be replaced with the mockup-faithful page). **Status:** kickoff (no implementation yet).

> Authored 2026-06-01 during the autonomous build run, AFTER C12 completed (2.3.93). C13/C14 were skipped (Stripe/Authorize.net charge dispatch needs Whitney's payment-behavior approval per CLAUDE.md). C15 is fully non-payment → autonomous.

## Section enumeration (mockup top → bottom)

| # | Section | Mockup class | Content / behavior | Data source |
|---|---|---|---|---|
| 1 | Brand banner + breadcrumb + header | `plugin-header` | "Reports" title + subtitle | static |
| 2 | **ZIP export card** | `card card-zip` | per-reservation `<select>` + "Export ZIP (6 reports × CSV + PDF)" button | all reservations; bundles all 6 reports |
| 3 | **Global Filters card** | `card` / `filter-grid` | Reservation select · Date range (preset dropdown `last-30`/`last-7`/`last-90`/`this-year`/`all`/`custom` + two `type=date` inputs) · Order status select · Reset · filter-summary line | reservations list; statuses |
| 4 | "Individual reports" grid | `report-grid` | 6 report cards, each: icon + title + desc + **CSV** + **PDF** export buttons | per report (below) |
| 4a | Orders | `report-icon-orders` | order + customer + items + payment status + totals | `EEM_Orders_Repository::get_grouped_orders` (filtered) |
| 4b | Reservations | `report-icon-reservations` | event-level: dates, total orders, revenue, occupancy %, capacity | reservation meta + orders agg |
| 4c | Revenue | `report-icon-revenue` | revenue by date/reservation/method incl. refunds + fees + **tax** | orders agg (uses new C12 `tax` col) |
| 4d | Stall Occupancy | `report-icon-occupancy` | stall + RV utilization: capacity, fill rate, blocked vs assigned | stall/RV chart data (⚠️ depends on C8) |
| 4e | Customer List | `report-icon-customers` | customers + contact + order count + lifetime value | orders agg by email |
| 4f | Refund Log | (6th card, below cut) | refunds with amount, date, reason | orders w/ `refund_transaction_id` |
| 5 | **Export History** (below grid, per roadmap) | — | rows: `file_exists(cached path)` → `.btn-download` w/ URL, else `.expired-link` re-export anchor | `eem_report_export_logs` option + cached files |

## Roadmap-mandated scope additions (HANDOFF Backend 4 / AUDIT-C12-1)

- **Export caching:** `/wp-content/uploads/eem-reports/` with 30-day auto-purge (hardcode 30 for now). Filenames event-id-based, e.g. `eem-orders-30597-20260424.csv` (so unaffected by 5-digit order-# rule).
- **Filter localStorage persistence:** key `eem_reports_filter_state`, JSON `{reservation_id, date_preset, date_from, date_to, status}`.
- **Date preset JS:** presets auto-fill the two date inputs; manual edit of either input flips the dropdown → `custom`. (Mockup already stubs `onPresetChange()` / `flipToCustom()`.)
- **Export History rows:** `file_exists($cached_export_path)` → render download link with file URL, ELSE render expired-link with a re-export anchor (filename preserved across cache+purge for the row reference).

## Reuse / what exists

- Legacy `render_reports_page` + `equine_event_manager_export_report` admin-post action + `get_report_export_logs()` + `eem_report_export_logs` option → **reuse the export-log + admin-post plumbing**, replace the page render.
- CSV building helpers may already exist in the legacy export path — **grep before writing new ones**.
- **PDF per report:** reuse `EEM_PDF::render()` (C12) — author a simple tabular report template, render to PDF.
- **ZIP:** `ZipArchive` (PHP ext) — verify availability; bundle the 12 files (6 reports × CSV+PDF) into one archive in the cache dir.
- Orders data: `EEM_Orders_Repository::get_grouped_orders()` (now carries `tax`/`tax_rate` from C12).

## Files-touched table (estimate; functional LOC ×1.275 tax, CSS ×1.125)

| File | Work | ~LOC (taxed) |
|---|---|---|
| `admin/class-eem-reports-page.php` (NEW) | Mockup-faithful page render (replaces legacy), filter bar, ZIP card, 6 report cards, history | ~420 |
| `includes/class-eem-reports-repo.php` (NEW) | 6 report query builders → row arrays (filter-aware) | ~520 |
| `includes/class-eem-report-exporter.php` (NEW) | CSV + PDF + ZIP generation, cache write/read, 30-day purge | ~340 |
| `templates/reports/report-pdf.php` (NEW) | Generic tabular report PDF template (Dompdf) | ~120 |
| `assets/css/admin.css` | Filter grid, report cards, ZIP card, history rows (mockup has lots of new classes — high new-CSS, so ×2.5 floor likely applies) | ~700 |
| `assets/js/admin.js` | Date-preset auto-fill, custom-flip, filter localStorage, export dispatch | ~180 |
| `admin/...-admin.php` | Swap menu callback render_reports_page → EEM_Reports_Page::render | ~10 |
| `tests/smoke/c15-*.php` (NEW) | Per-report row shape, CSV/PDF/ZIP round-trip, cache+purge, filter state | ~260 |

## Decision-locks (resolve at kickoff)

1. **Stall Occupancy report depends on C8 (stall-chart assignment data).** If C8 isn't fully landed, either (a) ship the report with assigned/blocked = best-effort from `_en_stall_rows` + order `preferred_stall_units`, or (b) stub that one card "pending C8" like the Dashboard. → Recommend (a) best-effort; note gaps.
2. **PDF per report** — confirm a generic tabular template is acceptable (header + filter summary + table) vs. per-report bespoke layout. → Recommend generic tabular.
3. **ZIP** — requires `ZipArchive`. If absent, degrade to "CSVs only" or a tar. → Check `class_exists('ZipArchive')`; degrade gracefully.
4. **Export caching dir** writability — create `uploads/eem-reports/` on first export; guard with an `index.html` + `.htaccess` deny for direct listing.

## Suggested build sequence (sub-chunks)

1. **C15.A** — Reports repo: the 6 report query builders (filter-aware) + smokes (row shape per report). Pure data, no UI.
2. **C15.B** — Exporter: CSV + cache write/read + 30-day purge + export-log integration + smokes (round-trip).
3. **C15.C** — Page port: mockup-faithful render (filter bar, ZIP card, 6 cards, history) + CSS. Visual verify.
4. **C15.D** — JS: date presets, custom-flip, filter localStorage, export dispatch wiring.
5. **C15.E** — PDF per report (`EEM_PDF` + template) + ZIP bundling + smokes.
6. **C15.F** — finalize: full smoke sweep, docs, version, visual verify.

Each sub-chunk is independently committable and verifiable.
