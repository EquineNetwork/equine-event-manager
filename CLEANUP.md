# CLEANUP.md

Standing tracker for everything that should be deleted/removed during Phase 3 but isn't yet. Reviewed at every chunk merge.

## Rules (standing for the rest of Phase 3)

1. **Every chunk that touches legacy code adds entries here** for anything that "should be removed eventually but isn't this chunk's job."
2. **Every chunk that COMPLETES a legacy area checks this file** for entries that can NOW be removed because the dependency is gone — and removes them in the same chunk.
3. **Reviewed at every chunk merge.** Entries that have been sitting for **3+ chunks** without progress are flagged for forced cleanup in the next chunk.
4. **Don't leave commented-out legacy code in commits.** Delete it; git history is the reference. Same goes for "TODO: remove" stubs — those go here, not in code.
5. **The last commit of Phase 3** processes any remaining entries and verifies nothing legacy ships to production.

Each entry includes: what, where (file:line if applicable), why deferred, when added, and the chunk/condition that unblocks deletion.

---

## Active entries

### 47. New `_en_group_description` + `_en_group_riders_per_group` meta keys — customer-facing C10 cascade
- **What:** C7.C.1.4.A introduced two new reservation post-meta keys per mockup canon (mockup lines 958–970, Decision N1): `_en_group_description` (textarea — what a group reservation includes) and `_en_group_riders_per_group` (int — max riders one customer can register). Mockup-canonical fields with no legacy equivalent; added non-destructively (Option L1 pattern).
- **Why deferred:** Editor save-side wires these in C7.C.1.4.A. Customer-facing C10 (Customer Event Page) needs to (a) display the group description text on the group-reservations toggle, (b) enforce the riders-per-group max on the rider-count input. Neither is wired today; values persist but aren't yet consumed by the customer flow.
- **Added in:** C7.C.1.4.A
- **Unblocks deletion:** C10 (Customer Event Page port). Add the readers in the same chunk that ports the customer event-page group section. No data migration needed — keys are read-as-empty for pre-C7.C.1.4.A reservations and that's the correct fallback behavior.
- **Status:** awaiting C10

### 46. Legacy `EEM_Reservations_CPT::render_editor_*_row()` helpers — wholesale strip
- **What:** Six private render helpers (`render_textarea_row`, `render_text_row`, `render_currency_row`, `render_datetime_row`, `render_date_range_row`, `render_number_row`) plus the public `render_editor_*_row()` wrappers and `render_editor_fee_value_row()` / `render_editor_file_field_row()` / `render_editor_stall_chart_rows()`. They emit WordPress meta-box chrome (`<table class="form-table"><tr><th><td>`) and CSS classes (`.eem-currency-field`, `.eem-rate-mode-row`, `.eem-date-range-fields`, `.eem-inline-toggle-control`, etc.) that have NO rules in `admin.css` and fall through to browser defaults.
- **Why deferred:** C7.C.1.4.A retires the helpers as callers for 4 of the 8 wired sections (description / checkin / group / fees) by rewriting their partials to use mockup-canonical chrome via `_partial-field-row.php` + `_partial-toggle-label-row.php`. C7.C.1.4.B retires the helpers for the remaining 4 (addons / agreement / stall / rv). Once C7.C.1.4.B lands, NOTHING in the editor calls these helpers anymore. Marking `@deprecated` in this commit + retaining callable as transitional safety in case of non-editor callers I haven't audited.
- **Added in:** C7.C.1.4.A
- **Unblocks deletion:** C16 polish wholesale-strip. By that point, C7.C.1.4.B has landed (all 8 sections on mockup-canonical chrome) and any remaining non-editor callers will have surfaced during ~9 chunks of running smoke. Delete the helpers + their CSS-classes-without-rules outright.
- **Status:** ⚠️ NOT dead — DO NOT strip (re-audited C16, v2.7.123). The premise that C7.C.1.4.B retired all callers is outdated: `render_editor_datetime_row` / `render_editor_textarea_row` / `render_editor_currency_row` / `render_editor_number_row` / `render_editor_date_range_row` / `render_editor_text_row` / `render_editor_file_field_row` / `render_editor_stall_chart_rows` still have ~30 live callers across `admin/class-equine-event-manager-reservation-editor.php` (checkin/checkout times, stall description, stall rates, schedule, early-bird, file field, stall-chart rows, venue fields) AND `includes/class-equine-event-manager-reservations-cpt.php` (RV section + event-details section). The live editor (browser-verified at C16) renders THROUGH these helpers. Deleting them would break the editor. If a future chunk wants to retire them, it must first migrate every one of those callers to `_partial-field-row.php` chrome — a real porting task, not a release-cut strip. Entry kept open but reclassified from "dead" to "still-live legacy chrome."

### 45. Mockup-canonical chrome retroactive port — C7.C.1.4.B
- **What:** C7.C.1.4.A ported description / checkin / group / fees to mockup-canonical chrome. The remaining 4 wired sections (addons / agreement / stall / rv) are still on the legacy `<table class="form-table">` + `render_editor_*_row()` helper chrome.
- **Why deferred:** C7.C.1.4 trips the 40% alarm if all 8 sections rewritten in one commit. Split per precedent.
- **Added in:** C7.C.1.4.A
- **Unblocks deletion:** N/A — this is the next-chunk pointer, not a code-deletion deferral. Execute C7.C.1.4.B after .A visual-verify passes.
- **Status:** awaiting C7.C.1.4.B kickoff

### 44. Reservation Editor section-enabled meta keys — rename to `_eem_section_enabled_{key}` canonical
- **What:** Each section's enabled state lives in legacy `_en_*_enabled` post-meta keys (`_en_checkin_checkout_enabled`, `_en_general_addons_enabled`, `_en_group_reservations_enabled`, `_en_convenience_fee_enabled`, `_en_venue_agreement_enabled`, plus `_en_stalls_enabled` + `_en_rv_enabled` from C7.C.2). Names are inconsistent (`checkin_checkout_enabled` vs `general_addons_enabled` vs `convenience_fee_enabled` — no shared prefix) and inherited from the Codex-era meta-box schema. Canonical scheme: `_eem_section_enabled_{section_key}` (e.g. `_eem_section_enabled_checkin`, `_eem_section_enabled_addons`, `_eem_section_enabled_fees`).
- **Why deferred (Option A vs Option C audit at C7.C.1.1):** Renaming requires coordinated cascade — customer-facing surfaces (C10 customer event page / C11 confirmation email / C12 receipt + hosted page) all read the legacy keys today. Splitting the rename across chunks would create a backend-writes-new-keys / frontend-reads-old-keys window that silently breaks customer-facing state for every reservation saved through the new editor. C7.C.1.1 took Option C (hidden-input mirrors the legacy name on the editor body; no rename) precisely to avoid that breakage window.
- **Added in:** C7.C.1.1
- **Unblocks deletion:** C16 polish pass — single coordinated commit window that lands C10/C11/C12 read-side migration in the same commit as the save-side rename. Migration step: snapshot existing `_en_*_enabled` values into `_eem_section_enabled_{key}` for every existing reservation in the activator's `maybe_upgrade` (flag-gated, one-time), then strip the legacy read paths.
- **Status:** ✅ RESOLVED in C16 (v2.7.124). Implemented as a single coordinated change:
  - `EEM_Reservations_CPT::SECTION_ENABLED_MAP` (7 fields → short keys) + `section_enabled_meta_key()` / `read_section_enabled_raw()` (new-first, **legacy-fallback**) / `section_enabled()` / `section_enabled_exists()` helpers.
  - Single write site (meta save loop) writes only the canonical `_eem_section_enabled_<shortkey>` key; central read loop + every literal read (cpt, admin, orders-repo, list-repo, shortcodes) + the legacy-default `metadata_exists` guards all route through the resolver. Two `WP_Query` meta_queries OR canonical+legacy keys.
  - `eem-mig-007-section-enabled-rename.php` (flag-gated, idempotent) snapshots every legacy value onto the canonical key for all reservations; legacy keys LEFT in place read-no-write as the fallback + historical record.
  - Verified live: migration populated canonical keys with exact values (incl. `0`s) for an all-on (5990) and mixed (6038) reservation; editor reads + AJAX-save write round-trip both confirmed against the canonical keys.

### 43. Throwaway audit scripts — `c7c1-audit-roundtrip.php` + `c7c1-audit-savemeta.php` (already deleted)
- **What:** Two diagnostic scripts used to root-cause the C7.C.1 save-bug + double-toggle bug. Lived briefly under `tests/smoke/` to share the smoke runner's bootstrap path; deleted at C7.C.1.1 close per Decision F.
- **Why deferred:** N/A — already deleted. Entry kept here as a record of the diagnostic pattern in case future similar bugs need the same approach.
- **Added in:** C7.C.1.1 (deletion in same commit)
- **Unblocks deletion:** N/A — already gone. Pattern documented: render the page, extract every `name^="en_reservation"` field exactly as the JS collector would, dump $_POST shape, call `ajax_save()` with the realistic shape, read back via canonical consumer. CLAUDE.md now codifies this as the canonical render-then-collect-then-post round-trip smoke discipline.
- **Status:** done

### 42. Reservation editor save bar — missing mockup right-rail elements (deferred to C7.G)
- **What:** The mockup's canonical save UI is a right-rail `.rail-card` Publish card (mockup lines 1149-1180) containing **more affordances** than C7.B.2 shipped. Specifically missing from the current fixed-bottom save bar:
  - Status display ("Status: Published")
  - Visibility display ("Visibility: Public")
  - Published-date display ("Published: Apr 17, 2026")
  - **Preview Frontend Form** button
  - **Move to Trash** button
- **Why deferred:** Path A retired the side rails, so these affordances need to land somewhere in the single-column layout — either in the meta-line under the title, inside the save bar's primary group, or in a More dropdown (the Order Detail pattern). Decision pending; C7.G polish pass is the right venue once we have full context across all the editor surfaces.
- **Added in:** C7.B.2.2 (audit triggered by Whitney's "navy band feels too heavy" visual-verify call — re-audit of mockup save UI surfaced the missing right-rail elements that the original C7.B audit didn't fully account for when Path A retired the side rails).
- **Unblocks deletion:** C7.G polish pass — fold all 5 missing affordances into the fixed-bottom save bar OR distribute across header actions + More dropdown. Likely shape: status/visibility/date as a small meta strip ABOVE the buttons in the save bar; Preview + Move to Trash as ghost buttons next to Cancel.
- **Status:** awaiting C7.G

### 40. Dashboard Needs Attention — C11-dependent rows
- **What:** `EEM_Dashboard_Repo::attention_items()` row 4 ("— customers haven't signed the agreement") ships as an em-dash placeholder pending C11 (Customer Confirmation Email + agreement-signature tracking). Live position: `includes/class-eem-dashboard-repo.php` → `attention_items()` → `em_dash => true` entry with `icon_key => 'mail'`.
- **Why deferred:** Agreement-signature data doesn't exist yet — C11 introduces the agreement workflow + signature timestamps.
- **Added in:** DS-1.B
- **Unblocks deletion:** C11 close. Swap the em-dash title for `sprintf( _n('%d customer hasn\'t signed…','%d customers haven\'t signed…', $count) )`; populate `desc` with the relevant event names; remove the `em_dash` marker.
- **Status:** awaiting C11

### 39. Dashboard — C8-dependent rows (stall/RV unassigned + This Week stalls assigned)
- **What:** Three em-dash placeholders in `includes/class-eem-dashboard-repo.php` pending C8 (Stall Charts) — (a) `attention_items()` rows 1, 3, 5 (stalls unassigned, RV lot issues, stall chart not configured), (b) `this_week()` row 5 (Stalls assigned).
- **Why deferred:** Stall-chart assignment data doesn't exist — C8 builds it.
- **Added in:** DS-1.B
- **Unblocks deletion:** C8 close. Replace each em-dash row with the real query against the stall-chart tables / repo helpers that C8 ships; remove the `em_dash` markers.
- **Status:** ✅ RESOLVED — wired live via `EEM_Admin::for_compute()->get_dashboard_stall_metrics()` (DS-1.B follow-up). Browser-verified 2026-06-04: Needs Attention shows real "N stalls unassigned — Event" + This Week "Stalls assigned" renders a value.

### 38. Dashboard Upcoming Reservations — stall progress bars
- **What:** `EEM_Dashboard_Repo::upcoming_reservations()` ships each row's `stall_progress` block as `assigned => '—'`, `total => '—'`, `pct => 0`, `tone => 'red'`, `em_dash => true`. The mockup shows numeric assigned/total + colored fill bar; we render the chrome but the numbers are em-dashes pending C8.
- **Why deferred:** Per-reservation stall-assignment counts come from C8 stall-chart data.
- **Added in:** DS-1.B
- **Unblocks deletion:** C8 close. Inside the `foreach` in `upcoming_reservations()`, compute `$assigned` + `$total` from the chart config for `$res_id`, derive `pct = $assigned/$total * 100`, set `tone` via thresholds (≥80% green, ≥50% amber, <50% red).
- **Status:** ✅ RESOLVED — `stall_progress_for()` computes real assigned/total (DS-1.B follow-up). Browser-verified 2026-06-04: Upcoming Reservations shows "21 / 50" with a real fill bar.

### 37. Dashboard Unassigned Stalls KPI
- **What:** KPI card #4 in `EEM_Dashboard_Repo::kpi_cards()` ships with `value => '—'` and `em_dash => true`. Mockup shows a count (e.g. "34") + "Needs attention across N events" subtitle.
- **Why deferred:** Per-event unassigned-stall counts come from C8.
- **Added in:** DS-1.B
- **Unblocks deletion:** C8 close. Replace the em-dash with `SUM(total_stalls - assigned_stalls)` across all active stall charts; populate `sub` with affected-event count.
- **Status:** ✅ RESOLVED — `unassigned_stalls_kpi()` computes the real total (DS-1.B follow-up). Browser-verified 2026-06-04: KPI shows "29" with "21 of 50 stalls assigned" subtitle.

### 41. Stub page mockup chrome cosmetic (preview-only, resolved at C13/C14)
- **What:** DS-1.A.1's iframe-isolated stub pages (Create Order, Collect Payment) render canonical mockups via `<iframe srcdoc>`. The mockups in `.mockups/` contain simulated WordPress admin chrome (admin bar + left sidebar stubs) because they were designed as standalone-previewable HTML files. Inside the live admin's iframe, this produces visible double-chrome — once from the real WP shell wrapping the iframe, once from the mockup's simulated shell inside it.
- **Why deferred:** Stub pages are explicitly labeled "Visual preview only — Coming in C13/C14." Functional implementations in C13 (Create Order) and C14 (Collect Payment) will pull from the page-body sections of the mockups only, ignoring the chrome stubs, so double-chrome resolves automatically at functional build time.
- **Added in:** DS-1.A.1.1
- **Unblocks deletion:** C13 + C14. Closes implicitly when both ship.
- **Status:** no DS-1 action required

### 36. Dev-seed `reservation_id` gap — 25/26 seeded orders lack a reservation_id, blocking visual verify of reservation-dependent UI
- **What:** The dev seed (`scripts/seed-orders.php` or whichever shipper populates the SEED-NNN orders) creates orders without a populated `reservation_id` on the order row. Audit during C6.A.3 found 25 of 26 seeded orders have `reservation_id = NULL/0`. Only 1 order has it set.
- **Why this matters now:** The C6.A.2 "Edit Reservation" header button (and any future render code that conditions on `reservation_id > 0`, like C7's inline-edit flow) will silently hide for almost every seeded order. Visual verification of the button — and any reservation-derived data in the order detail render — becomes essentially impossible without manually back-filling `reservation_id` on individual seed orders.
- **Why deferred (not fixed now):** Out of C6.A.3 scope (pre-merge polish chunk, not a seed-data overhaul). The render code is correct — it gracefully degrades when `reservation_id` is empty. The fix is on the seed-data side.
- **Added in:** C6.A.3
- **Dev-side stopgap (DS-1.A.1.2):** `scripts/dev-backfill-seed-reservation-ids.php` ships a one-shot wp-cli script that appends a round-robin `Reservation setup ID: N` tag to every SEED-* row in `wp_en_stall_reservations` + `wp_en_rv_reservations` that lacks one (matching the regex `EEM_Orders_Repository::extract_reservation_id_from_notes` uses). Idempotent — re-runs skip already-tagged rows. Run via `wp eval-file scripts/dev-backfill-seed-reservation-ids.php` from the WP install root. Dev DB confirmed clean post-run (30/30 orders resolve to a reservation post). Run again after any re-seed.
- **Unblocks deletion:** **C7 kickoff** (Edit Reservation editor) — C7 will need seeded reservation_ids for its own visual verification, so the seeder fix becomes a prerequisite for C7 work rather than optional cleanup. Required work: update `scripts/seed-orders.php` (or equivalent) to (a) ensure each seeded order references a real reservation post in the `en_reservations` (or whatever) CPT, AND (b) populate the `reservation_id` column on the order row to that post's ID. May also require seeding the reservation posts themselves if the dev DB doesn't have enough. **Stopgap script can then be deleted.**
- **Status:** dev DB unblocked via stopgap; real seeder fix still owed at C7 kickoff

### 35. Git committer attribution — hostname-derived email exposes machine name in commit history
- **What:** Existing commits on `phase-3/c6-order-detail` (and likely all branches since the repo was cloned to `~/Projects/equine-event-manager`) carry the auto-derived attribution `Whitney Mitchell <whitneymitchell@Whitneys-iMac.local>`. Git falls back to this when `user.email` isn't set in any config scope (system/global/local). Functional locally, but the hostname segment (`Whitneys-iMac.local`) becomes part of the public commit metadata if the repo ever ships to WordPress.org SVN, GitHub, or any public mirror.
- **Why deferred:** Pre-release concern, not today's problem. The plugin is on a private dev branch — no public exposure yet. Mid-branch git config changes only fix attribution going forward, not the existing history; a `git filter-branch` or `git filter-repo` rewrite would touch every commit and is meaningfully larger work than a single chunk should absorb.
- **Added in:** C6.A.2 close (observed in commit `282c92a` post-commit warning)
- **Unblocks deletion:** Pre-release prep chunk (likely C16 or the dedicated release-cut chunk). Required work: (a) `git config --global user.email <real@email>` + `git config --global user.name "Whitney Mitchell"` on dev machine, (b) decide whether to rewrite existing commit history via `git filter-repo` (preserves authorship intent, mass-rewrites SHAs — disruptive for any open branches) or accept the existing-history attribution and only fix forward (zero SHA churn, leaves hostname in history). Recommendation: fix-forward unless we need clean history for compliance/audit reasons. Both options are cheap; the choice is between disruption-now-clean-history-forever vs no-disruption-mixed-history.
- **Status:** awaiting C16 / release-cut

### 34. Order Detail Payment Details — card brand / last4 display block deferred
- **What:** The mockup at `.mockups/order_detail_page.html` lines 548-554 specs a "Card" row in the Payment Details sidebar (VISA badge + `•••• 4242` masked number). C6.A.2 OMITS this block entirely.
- **Why deferred:** The C6.A.2 meta-existence audit probed candidate keys `_en_card_brand`, `_en_card_last4`, `_en_payment_card_brand`, `_en_payment_card_last4`, `_en_card_brand_normalized`, `_en_cc_brand`, `_en_cc_last4`, `_en_stripe_card_brand`, `_en_stripe_card_last4`, `_card_brand`, `_card_last4` against all seeded reservations AND ran a broad `LIKE '%card%' OR '%last4%' OR '%brand%' OR '%cc_%'` scan across the entire `wp_postmeta` table — zero hits. Card brand/last4 are not persisted anywhere in the current data shape. Per C6.A.2 discipline ("honest representation beats fake '—'"), the block is omitted rather than shipping placeholder rows.
- **Added in:** C6.A.2
- **Unblocks deletion:** C14 (Collect Payment admin page — that's where Stripe/Auth.net charge dispatch happens, so it's the natural place to capture `payment_method_details.brand` + `last4` from the PaymentIntent response). Required work: (a) capture `payment_method_details.card.brand` + `payment_method_details.card.last4` from the Stripe PaymentIntent (and the Auth.net equivalent) at the moment of charge, (b) persist to `_en_card_brand` + `_en_card_last4` post_meta on the reservation, (c) re-add the Card display block to `EEM_Order_Detail_Page::render_payment_details_card` with a graceful degrade for orders predating capture. Inline comment marker at the omission point references "CLEANUP #34".
- **Status:** awaiting C14 (chunk recategorization, post-handoff Step 2 — was C10/C11)

### 33. Order Detail save bar — deferred to C7 (inline-edit save flow)
- **What:** The mockup at `.mockups/order_detail_page.html` lines 586-592 specs a Cancel + "Save Changes" pair that sits between the Special Instructions card and the Activity Log section. C6.A.2 OMITS this region.
- **Why deferred:** C6 scope is display-only (refund/CSV/trash live in the header More menu). There is no inline-editable field on the Order Detail page yet, so the save bar would dispatch nowhere. C7 lands the inline-edit save flow (Special Instructions editor + line-item edits) and reinstates the save bar at the same DOM position.
- **Added in:** C6.A.2
- **Unblocks deletion:** C7 (Order Detail inline edits). Inline comment marker in `EEM_Order_Detail_Page::render()` between `render_special_instructions_card()` and `render_activity_log()` references BOTH "CLEANUP #33" and "mockup lines 586-592" — single grep target for the C7 implementer.
- **Status:** awaiting C7

### 32. Activity log get_for_order_key — indexed order_key column instead of LIKE-on-JSON
- **What:** `EEM_Activity_Log::get_for_order_key( $order_key, $limit )` (added in C6.E.1) queries the `payload` column with `LIKE '%"order_key":"<key>"%'`. No index on payload — every call scans the whole table.
- **Why deferred:** Admin-only view (Order Detail page), infrequent visits, typical order has fewer than 20 activity entries. At v2.2.0 scale (single tenant, ~thousands of activity rows across all orders) the LIKE scan is fast enough that a fix isn't blocking. The `$limit` floor (default 100, internal cap 500) bounds the worst case. Real fix is a proper indexed column — but that's a schema migration that doesn't belong inside a UI-render chunk.
- **Shape of the fix:**
  1. Activator migration adds `order_key VARCHAR(64) NOT NULL DEFAULT ''` column to `wp_en_activity_log` + index on `(order_key, created_at DESC)`.
  2. Backfill UPDATE pulls `order_key` out of existing payloads: `UPDATE wp_en_activity_log SET order_key = JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_key')) WHERE order_key = '' AND payload LIKE '%"order_key":%'`.
  3. EEM_Activity_Log::write() captures `$payload['order_key']` and writes the dedicated column.
  4. EEM_Activity_Log::get_for_order_key() switches to `WHERE order_key = %s`.
- **Added in:** C6.E.1 (audit-time surfacing).
- **Sequence:** before any production deployment with high activity-log volume, OR alongside C16 polish. Trivial in isolation (~40 LOC schema + ~10 LOC method swap); the real cost is the backfill verification.
- **Unblocks:** O(1) lookup per order regardless of table size; reporting / analytics features that scan by order_key.
- **Status:** queued; LIKE-on-JSON acceptable at v2.2.0 scale.

### 31. Activity log event-type sanitization quirk — dotted names normalize to flat strings
- **What:** `EEM_Activity_Log::write()` runs `sanitize_key()` on the `$event_type` argument. `sanitize_key` strips dots, so code-level event names diverge from the strings actually persisted to the `wp_en_activity_log.event_type` column:
  - `'order.create'`              → stored as `'ordercreate'`
  - `'order.refund'`              → stored as `'orderrefund'`
  - `'order.payment_received'`    → stored as `'orderpayment_received'`
  - `'order.status_change'`       → stored as `'orderstatus_change'`
  - `'order.email_sent'`          → stored as `'orderemail_sent'`
- **Why deferred:** Pattern matches the pre-existing `order.refund` writes shipped in C6.B/C6.C, so the persisted data is internally consistent — no historical-row migration needed. Functional impact is zero today because no code currently queries by event_type. The break is latent: future query-by-event-type code (admin filters, reporting, analytics export) will surface the divergence as "I wrote `'order.create'` and queried `'order.create'`, why zero rows?".
- **Three fix options (decide pre-production):**
  - **(a) Document the mapping explicitly** in `EEM_Activity_Log::write()` docblock + add a `normalize_event_type()` helper that callers MUST use for both writes and queries. Lowest-effort but rule-by-discipline only — easy to forget.
  - **(b) Switch to underscore-separated event names** at every call site (`order_create`, `order_refund`, `order_payment_received`, etc.). Makes `sanitize_key` a no-op, eliminates the divergence entirely. Code edit cost is moderate (touches every write site in C6.B/C/D); historical rows still carry the dotted-sanitized form so adds a quirky transition window unless backfilled.
  - **(c) Bypass sanitize_key for this specific field** — rewrite `EEM_Activity_Log::write` to use a custom whitelist regex like `/^[a-z0-9._-]+$/` that preserves dots. Cleanest semantically; requires the most thinking about what's actually a safe event-type charset.
- **Recommended:** (b) — underscore-separated names. Simplest mental model long-term; the historical-row backfill is a one-line `UPDATE wp_en_activity_log SET event_type = REPLACE(...) WHERE event_type LIKE 'order%'` migration.
- **Added in:** C6.D (surfaced when c6d-smoke initially queried for dotted names and got zero hits).
- **Sequence:** before any production deployment that will ship a query-by-event-type feature. Folds naturally into C11 (mailer telemetry surfacing) or C16 polish — whichever first introduces an event-type filter UI.
- **Status:** quirk documented; behavioral fix queued.

### 30. Refund-notify email wiring for C6.B notify checkbox
- **What:** The C6.B single-order refund modal carries a "Notify customer" checkbox; its checked state is captured in the form payload (currently sent as `notify` POST field, surfaced via the activity-log payload's `notify` key when refund processes). The actual email send — rendering an "Event Cancelled — Refund Processed" template and shipping it via EEM_Mailer — is **not wired** in C6.B.
- **Why deferred:** The "Event Cancelled — Refund Processed" template doesn't exist yet (Communications panel from C3.B shipped the template UI but not this specific template). Wiring an unsendable email is hollow. C11 lands SendGrid transport + the remaining canonical templates (refund-processed, payment-reminder, etc.) together — refund-notify rides along naturally.
- **Shape of the fix (lands with C11):**
  1. Add `refund_processed` template to `EEM_Email_Templates_Repo::ids()` with subject + body defaults.
  2. In `EEM_Admin::handle_ajax_refund_single` (and the bulk equivalent in `handle_ajax_bulk_refund_step`), after the refund succeeds, if `notify=1` was passed, render the refund_processed template with refund context (amount, order_number, customer_name, etc.) and ship via `EEM_Mailer::send_html_email` with `type='refund_notification'` context. The C6.D email-sent telemetry hook will then write a corresponding `order.email_sent` activity-log entry automatically.
  3. JS modal: surface "Notification email sent to {customer}" in the success toast when notify was checked.
- **Added in:** C6.D (refund-notify scope decision during the telemetry chunk).
- **Sequence:** lands with C11 (SendGrid + canonical templates work).
- **Unblocks:** the C6.B notify checkbox stops being decorative.
- **Status:** ✅ RESOLVED 2026-06-04. Added the "Notify customer" checkbox (opt-in, default unchecked) to the C6 refund modal; `EEM_Admin::send_refund_email_for_order()` + `build_refund_email_html()` render and ship a "Refund Processed" email (type=refund_notification) via EEM_Mailer when `notify=1`; `handle_ajax_refund_single` returns `notification_sent`; JS toast appends "Customer notified by email." Smoke c30 16/16.

### 29. Bulk refund order-fetch optimization
- **What:** Each `process_amount_refund` call invokes `get_grouped_orders()` (full table scan in orders-repository line ~460). For a 20-order batch this is 20 scans; for a 50-order batch, 50. Address by adding `get_orders_by_keys(array)` repo method + engine-level caching so a bulk batch performs ONE scan and serves all step calls from the cached result.
- **Why deferred:** Admin-only operation + bulk refunds are infrequent (post-event cancellations, typically), so the perf impact is real but not blocking. C6.C ships sequentially-correct functionality first; the optimization is a polish item.
- **Shape of the fix:**
  1. New repo method: `EEM_Orders_Repository::get_orders_by_keys( array $order_keys ): array<string,array>`. Returns map keyed by order_key. Single `get_grouped_orders()` call internally, filtered.
  2. Bulk-step handler (or a wrapper) accepts an optional `$batch_token` query arg. Engine maintains a transient/object-cache map keyed by token holding the get_grouped_orders result for the batch duration (~5 min TTL).
  3. Per-step handler reads from the cached map when present, falls back to fresh `get_order` when not.
  4. JS bulk runner generates a `batch_token = crypto.randomUUID()` on modal open, includes it on every step call.
- **Added in:** C6.C (audit-time surfacing).
- **Sequence:** any time after C6 closes; folds naturally into C16 polish or its own focused chunk. CLEANUP #27 (EEM_Refund_Engine extraction) is a natural co-landing because both touch the same per-order/per-batch boundary.
- **Unblocks:** larger bulk batches (100+ orders) without quadratic-feeling delays.
- **Status:** queued; functional impact only at large batch sizes.

### 28. AJAX smoke harness for wp_die paths
- **What:** Subshell wp-cli for isolated `wp_send_json_*` / `wp_die` paths so AJAX handlers can be exercised end-to-end in smokes without killing the runner. Surfaced during C6.B; current workaround is gate-only testing + manual browser verify.
- **Why deferred:** Discovered mid-C6.B when c6b-smoke tried to invoke `EEM_Admin::handle_ajax_refund_single()` directly and the call exited the PHP process. The workaround (assert capability + nonce gates separately, defer end-to-end coverage to manual browser verify) is sufficient for individual AJAX endpoints but does not scale — C6.C bulk-engine will compound the gap, and C7+ will all need it.
- **Root cause:** `wp_send_json_error` / `wp_send_json_success` invoke `wp_die()` which in CLI context calls `_default_wp_die_handler` and ultimately PHP's `die()`. Both `wp_die_handler` and `wp_die_ajax_handler` filters fire BEFORE the handler is invoked (handler-selection-time), but the default CLI handler still exits the process — the filter mechanism is about WHICH handler runs, not about preventing the exit.
- **Shape of the fix:**
  1. New `tests/smoke/harness/ajax-subshell.sh` — takes a PHP snippet, runs it via `wp eval` in a SUBPROCESS, returns stdout (the JSON response) + exit code as captured strings to the calling smoke.
  2. New `tests/smoke/harness/ajax-helpers.php` — wrapper functions like `ajax_post_assert_success($action, $payload)` and `ajax_post_assert_error($action, $payload, $expected_code)` that smoke files call instead of invoking the handler directly.
  3. C6.B's [5] section gets a follow-on subshell-driven test that POSTs to `wp_ajax_eem_order_refund_single` with bogus nonce → asserts JSON `{success:false, data:{code:'nonce'}}`. Same pattern for capability + happy-path (against a refund-able test order with a mocked gateway).
- **Added in:** C6.B (smoke limitation surfaced during the AJAX gate testing).
- **Sequence:** Before C6.C if possible (would give C6.C bulk engine real end-to-end coverage from the start), otherwise immediately after C6.E close as part of the C6 chunk-end audit work. Trivial in isolation (~80 LOC bash + ~60 LOC PHP helpers); the real cost is retrofitting existing AJAX smokes (c6b currently, c6c when it lands) to use the new pattern.
- **Unblocks:** end-to-end AJAX coverage for every present + future AJAX endpoint. Reduces the "manual browser verify" surface considerably.
- **Status:** queued; gate-only workaround in place for C6.B and C6.C as a deliberate stopgap.

### 27. Extract EEM_Refund_Engine — gateway-dispatch + amount-distribution + persistence
- **What:** C6.B introduced `EEM_Admin::process_amount_refund( $order_key, $amount, $reason )` as a public adapter around the legacy private refund stack (`refund_order_component` → gateway dispatch → `persist_component_refund`). Wraps were chosen over class extraction in C6.B to keep the chunk small and let C6.C's bulk-engine requirements clarify the contract. **After C6.C ships**, extract into a clean `EEM_Refund_Engine` class:
  1. Move `refund_order_component` + `refund_with_stripe` + `refund_with_authorize_net` + `get_component_refunded_amount` + `get_component_remaining_refundable_amount` + `persist_component_refund` off EEM_Admin into the new class.
  2. Move `process_amount_refund` to `EEM_Refund_Engine::process_amount_refund( $order_key, $amount, $reason )`.
  3. EEM_Admin retains the AJAX endpoint (`handle_ajax_refund_single`) but delegates the actual refund work to the engine.
  4. C6.C bulk engine becomes `EEM_Refund_Engine::process_bulk( $order_keys, $reason )` — same kernel, looped.
  5. Smoke coverage expands: engine-level unit assertions (math helpers, distribution logic), AJAX-level integration assertions (capability/nonce/payload shape).
- **Why deferred:** C6.B alone doesn't have the surface area to justify a new class; the wrapping pattern is sufficient for one caller. C6.C bulk-engine adds a second caller and an error-attribution dimension (per-order success/failure tracking, partial-batch recovery) that genuinely warrants its own type. Extracting before C6.C ships would also lock in a contract that C6.C might want to evolve.
- **Added in:** C6.B (during the C6.B kickoff Q1 decision).
- **Sequence:** between C6.C close and C6.D start, OR folded into C16 polish — call it at C6.C close based on how complex the bulk engine's per-order error attribution turns out to be.
- **Unblocks deletion:** ~6 private methods migrate cleanly off EEM_Admin, which is already CLEANUP #1 territory (the full legacy file strip).
- **Status:** queued; C6.B uses the wrap pattern as a stopgap.

### 26. Activity Log save_meta diff logger — deferred from C6.D
- **What:** C6.D delivers 5 simple-event auto-fire hooks for the Activity Log (order created, payment received, refund processed, email sent, status changes). The **6th auto-fire path** — admin edits via the reservation CPT save_meta — is deferred as its own chunk because it's a different problem shape: requires reading old → new diff and formatting a per-meta-key change-list display (like the mockup's `"Shavings Qty: 2 → 4"`). The other 5 hooks are simple "an event happened" inserts; this one needs:
  1. **Snapshot meta values BEFORE save** (via `pre_post_update` or a `save_post_en_reservation` priority-5 hook that fires before the cpt's own save_meta at priority 10) into a transient or a request-scoped static cache.
  2. **Diff old vs new** after save_meta completes — read the same meta keys back, compute per-key changes.
  3. **Format the diff** for the activity log payload (struct: `{ field, old, new, label }`) — Activity Log render partial then shows the strikethrough → arrow → new value treatment.
  4. **Filter "noise" meta keys** that change on every save (e.g. cache timestamps, derived fields) so the log doesn't fill with irrelevant entries.
- **Why deferred:** distinct from the 5 simple-event hooks both in mechanism (pre/post snapshot pair vs single insert) and in display rendering (diff struct vs single message). Folding into C6.D would double the chunk's complexity and delay the Activity Log shipping at all.
- **Added in:** C6.A (during the C6 chunk-planning conversation as the Q3 deferred scope).
- **Sequence:** between C7 (Edit Reservation editor port — increases save_meta surface area) and C8 (Stall Charts — orthogonal). Likely chunk name "C7.5 activity-log diff logger" or rolled into C16 polish.
- **Unblocks:** the mockup's "Order edited by X" activity entry with field-level diff display.
- **Status:** queued; deferred from C6.D scope decision.

### 25. ~~VIS-4 deviation — Settings save buttons use navy instead of Electric Blue~~ ✅ Resolved in DS-1.A
- **What:** All 6 Settings tab save buttons (Integrations, Branding, Communications, Shortcodes, Payments, Add-Ons) render with navy background `#031B4E` instead of the Electric Blue `#1668F2` required by VIS-4 for primary CTAs. Affected class is likely `.btn-dark` or `.eem-btn-navy` (TBD at fix time via grep).
- **Why deferred:** discovered during the C6 mockup audit; trivial class swap but doesn't belong inside C6 itself.
- **Fix:** Replace the navy class with `.eem-btn-electric` (established VIS-4 primary CTA class per the C5.G.11 reversal). 6-12 LOC change across the Settings page template, possibly a shared button-row partial.
- **Risk:** very low — pure visual swap, no behavior change, no DB or markup-structure impact.
- **Added in:** C6.A (during C6 mockup orientation pass).
- **Sequence:** between C6 close and C7 start, as a small dedicated cleanup chunk (`c6.cleanup-vis4` or bundled with other small VIS deviations surfaced during C6's end-of-chunk audit).
- **Status:** ✅ Resolved in DS-1.A — `.eem-btn-primary` CSS rule in `assets/css/admin.css` flipped from `background: var(--eem-navy)` to `background: var(--eem-electric)`. Affects all `.eem-btn-primary` callers (Settings panel save buttons confirmed; no other shipped call sites per grep). Closed 2026-05-23 in the DS-1.A commit.

### 23. Plugin URI + Author URI placeholders — must be set before external release
- **What:** `equine-event-manager.php` plugin header carries placeholder URIs (`https://example.com/equine-event-manager`) for both `Plugin URI` and `Author URI`. Same placeholders in `composer.json` `support.source` / `support.issues`. These are fine for in-development / private use but MUST be replaced before:
  - WordPress.org plugin directory submission
  - Distribution outside the dev team (zip → customer / handover)
  - Any publication that surfaces the header (`wp plugin list` output, plugin page in `/wp-admin/plugins.php`)
- **Why deferred:** Real URLs (repo, support site, author homepage) are not yet decided. Promoting the plugin past the in-development boundary is itself a discrete decision — bundle the URI update with that decision rather than guess now.
- **Added in:** C6.5.C
- **Pre-release checklist (run when promoting):**
  1. Replace both `Plugin URI` + `Author URI` lines in `equine-event-manager.php`.
  2. Update `composer.json` → `support.source` and `support.issues`.
  3. If publishing to WordPress.org, also remove the `Update URI: false` line in the plugin header (it suppresses WP.org update checks — intentional for in-development copies, wrong for published ones).
  4. Audit `README.md` for any placeholder URLs and update them too.
- **Status:** placeholders shipped intentionally; awaiting external-release decision.

### 22. ~~RES-ARCH-1 non-conformance — reservation title + dates read from post, not source event~~ ✅ Resolved in C6.6
- **What was wrong:** Per decisions.md RES-ARCH-1, reservation's user-visible title and event dates are read-only mirrors of the source event (Native / TEC / External Feed). Pre-C6.6 code violated this: `EEM_Reservations_List_Page::render_table_row()` + `render_mobile_cards()` called `get_the_title( $post )` (reservation post_title), `EEM_Reservations_List_Repo::get_event_date_range_label()` read `_en_nightly_*_date` / `_en_weekend_*_date` from reservation post_meta, and `get_date_filter_options()` queried the same meta key.
- **How it was resolved (C6.6, 2026-05-23):**
  1. New `EEM_Reservation_Source_Resolver` class at `includes/class-eem-reservation-source-resolver.php` — thin façade over the existing `EEM_Events::get_normalized_reservation_event_data()` that returns just the RES-ARCH-1 trio (title, start_date, end_date, venue) + convenience accessors.
  2. Migrated 4 call sites on the Reservations list (render_table_row title + dates + orders-count link target; render_mobile_cards same; get_date_filter_options dropdown query; get_event_date_range_label proxied to resolver + @deprecated for C16 removal).
  3. Hybrid cache strategy: pure resolver for display reads, single narrow cache key `_en_source_event_start_date` (constant `EEM_Reservation_Source_Resolver::SORT_CACHE_META_KEY`) for the `orderby=event_dates` SQL path + the date_filter month range. Written by `save_post_en_reservation` priority 30 hook.
  4. Smoke updates: c4a now seeds a native source event + linked reservation and asserts the resolver returns source-event fields; c4d carries a self-healing backfill for any legacy pre-C6.6 seed reservations.
- **Migration impact on production data:** None. The four deprecated keys (`_en_nightly_*_date`, `_en_weekend_*_date`) had zero production writers — they were write-only-by-test (confirmed by the C6.6 audit grep). The `get_event_date_range_label` proxy means existing callers we may have missed still work.
- **Known limitation tracked in CLEANUP #24 below:** the sort cache is reservation-side-written only. Source-event changes don't push to linked reservations. Acceptable for in-development deployments; #24 must land before production where source events are edited frequently.
- **Closed:** C6.6 (2026-05-23). Tag: `c6.6-complete`.

### 24. Source-event → reservation sync handler (RES-ARCH-1 follow-on)
- **What:** The C6.6 resolver + sort cache work writes the cache from the reservation side only. If a source event's start_date changes (Native CPT save / TEC API edit / Feed refresh) AFTER a reservation is linked, the reservation's `_en_source_event_start_date` cache stays stale until the reservation itself is next saved. Display reads through the resolver are always correct (live dispatch), but the sort/filter SQL uses the cache.
- **Why deferred:** Source events in the typical deployment shape are set up once per season and rarely edited. The reservation-side cache refresh on every reservation save handles the common case. The other-direction sync handler is meaningful overhead (3 source-type hooks: `save_post_en_event`, `save_post_tribe_events`, post-feed-refresh transient bust) for a low-probability staleness window. Defer until there's a production deployment where source events are edited frequently.
- **Scope when picked up:**
  1. `save_post_en_event` hook → find all `en_reservation` posts with `_en_event_id = $event_id` AND `_en_event_source = 'native'`, call `EEM_Reservation_Source_Resolver::cache_source_event_start_date()` on each.
  2. Same pattern for `save_post_tribe_events` + `_en_event_source = 'tec'`.
  3. For feed: harder — no save hook on external feeds; would need a wp-cron job that walks feed-sourced reservations + refreshes caches when their feed transients expire.
  4. Add coverage to c4d-smoke that edits a source event and asserts linked reservations' cache key updates.
- **Added in:** C6.6 (resolver chunk closure).
- **Sequence:** before any production deployment where source events are edited frequently. Probably runs alongside or after C16 polish.
- **Unblocks:** removes the stale-cache failure mode for the sort/filter SQL.
- **Status:** known limitation documented; safe to defer for in-development use.

### 21. Searchable Event-filter dropdown (Choices.js) — UX scaling, not polish
- **What:** Orders + Reservations both use a native `<select>` for the Event filter. Becomes unwieldy past ~50 events (long scroll, no typeahead, no in-place filtering). Replace with Choices.js — adds searchable typeahead + better keyboard navigation. Apply to BOTH list pages for parity. Style the Choices.js shell to match EEM design tokens: navy borders, Electric Blue focus ring, proper border-radius matching `.eem-toolbar-select`. Audit Reservations during this chunk for similar issues with the Date filter dropdown + any future filters (Type, etc.) that might need the same treatment.
- **Why deferred:** Current native `<select>` works fine for the seeded test data (~3 events) but visibly degrades at production scale. Not a polish issue — a UX scaling issue that becomes a real blocker before user testing.
- **Added in:** C5.G.10
- **Sequence:** After C6 (Order Detail) or C7 (Edit Reservation), whichever has lighter dependencies. **Tag as "UX scaling" — do NOT defer to C16.** Must land before user testing because users with real event histories will hit the unusable native-select first.
- **Estimated scope:** ~100–150 LOC across PHP enqueue + JS init + CSS shell styling. Adding Choices.js requires explicit user approval (third-party JS library — per CLAUDE.md decision policy "Adding any third-party JS library — confirm the choice with me").
- **Unblocks deletion:** N/A — this is an additive UX upgrade, not legacy code removal.
- **Status:** queued; awaiting sequencing decision

### 20. Recurring dead-code audit after each chunk merge
- **What:** Run a focused dead-code sweep AFTER each chunk merges to main, BEFORE the next chunk starts. Check four categories:
  - **(a)** Old page-render callbacks that got replaced when menu swaps happened (precedent: `EEM_Admin::render_settings_page` deleted in C3.D.4 after C3.D.2 swap; `EEM_Admin::render_orders_page` body still present after C5.E swap — flag for C5.5 audit).
  - **(b)** CSS classes defined in admin.css that no longer have any markup using them. Grep-verify each class name → if zero hits in PHP render code, delete.
  - **(c)** PHP helper methods no longer called by any active code path. Grep-verify each `private function` against all callers; if zero, delete.
  - **(d)** JS handlers bound to data-eem-action selectors that no longer exist in any rendered markup. Audit admin.js dispatch table against PHP render output.
- **Why a standing practice, not a one-time:** Phase 3 chunks have been replacing components (C5.F-toolbar dropped 3 component classes; C5.G.3 dropped the `.eem-reservations-list` wrapper; C5.G.7 reverted then re-removed `.eem-btn-navy`). Each chunk produces orphans. Without the sweep they accumulate into a wholesale audit (C16 territory) which is far harder than catching incrementally. Lessons-learned cost: it took C5.F-toolbar + C5.G to discover the `.eem-orders-toolbar` legacy-CSS class collision because nobody audited after C5.B introduced the collision-prone name.
- **Process shape:** small "C{n}.5" audit chunk between merges. Single commit. Findings: a punch-list of removed selectors / methods / hooks with grep verification of zero remaining callers, plus the actual deletions.
- **Added in:** C5.G.10
- **Sequence:** Recurring — first instance is "C5.5 dead code audit" before C6 begins. Then "C6.5" before C7, "C7.5" before C8, etc.
- **Status:** queued; C5.5 ready to run after the C5 merge completes

### 19. Bucket 3 — End-of-build polish + asset pipeline
- **What:** Final-build hardening that doesn't make sense earlier. Three concerns:
  - **Asset build pipeline:** CSS minification, JS bundling/minification, source maps. Currently `admin.css` + `admin.js` ship un-minified.
  - **Lint configs:** ESLint config for `assets/js/`, Stylelint config for `assets/css/`. Run in CI + pre-commit hook.
  - **Performance pass:** query caching audit (object cache hits on legacy `EEM_Orders_Repository::get_grouped_orders` are unverified), lazy-enqueue audit (legacy `wp_enqueue_script` calls that fire on every admin page even when not needed), transient hot-path identification.
  - **Wholesale admin-legacy.css strip:** entry #1 wholesale-strip lands here too — by end-of-build every page is ported and admin-legacy.css can be removed wholesale (was C16-tagged in entry #1).
- **Why deferred:** Build pipeline + lint configs need the codebase to be feature-stable so the build doesn't have to re-tune for every chunk. Performance pass needs all pages built so the audit is comprehensive. admin-legacy.css strip needs every page ported.
- **Added in:** C5.G.10
- **Sequence:** End of Phase 3 (with C16 / Polish Pass).
- **Unblocks deletion:** assets/css/admin-legacy.css (per entry #1); legacy CSS rules each Phase 3 chunk excluded via `:not()` chains (those exclusion chains get reverted once the parent rule is gone).
- **Status:** queued; final-build bucket

### 18. Bucket 2 — Developer documentation
- **What:** Three docs deliverables for any future developer onboarding to the codebase:
  - **Expand README.md** with a "Developer onboarding" section: local dev setup (Local-by-Flywheel + WP version + wp-cli), how to run smoke scripts via `bash tests/smoke/run-all.sh` (versioned under `tests/smoke/` as of C6.5.A; was `/tmp/` pre-C6.5), branch naming convention (phase-3/cN-shortname), the chunk-based workflow.
  - **New CONTRIBUTING.md** explaining the chunk-based workflow + the per-chunk hygiene rules (the 7 rules currently in CLAUDE.md) + the LOC alarm protocol + the layout-shell verification procedure.
  - **New docs/ARCHITECTURE.md** documenting: the repo pattern (`EEM_*_Repo` static query helpers + `EEM_*_Page` controllers), the page-shell pattern (`templates/admin/_page_shell.php` + `eem_render_page_open/_close`), body-class scoping (the `eem-shell-page--{page}` convention from C4.5), the dual-repo rationale (`Projects/equine-event-manager` for git work + iCloud copy for visual review), and the JS dispatch pattern (`data-eem-action` delegated handlers).
- **Why deferred:** Mid-build docs go stale fast — each chunk adds patterns that would need re-documenting. Better to wait until the chunk vocabulary is stable.
- **Added in:** C5.G.10
- **Sequence:** After C7 (Edit Reservation) or C8 (Stall Charts), whichever lands first. At that point the core patterns will have been exercised across ~6 page ports and won't shift significantly.
- **Unblocks deletion:** N/A (additive docs).
- **Status:** queued; mid-build bucket

### 17. Bucket 1 — Plugin professionalization (sprint promoted to BEFORE C6)

> **Numbering note (C6.5.A, 2026-05-23):** Chunk-planning conversation referred to this entry as "#16" — the file numbers it **#17**. Using the file's number to avoid drift. Anything saying "Bucket 1 / CLEANUP #16" in commit messages or chunk plans refers to this entry.

- **What:** Move the codebase from "in-progress overhaul" to "shippable plugin" shape:
  - ✅ **Move smoke scripts + seeders into versioned directories** (was `/tmp/c4a-smoke.php` … `c5d-smoke.php` + `c5-seed.php`). Landed in C6.5.A: smokes live under `tests/smoke/` with `tests/smoke/run-all.sh` runner (single command, aggregated exit code); seeder at `scripts/seed-orders.php`. See `tests/README.md` + `scripts/README.md` for invocation.
  - ✅ **Add `composer.json`** declaring the plugin's PHP requirement (>=7.4) + dev dependencies (squizlabs/php_codesniffer, wp-coding-standards/wpcs, phpcompatibility/phpcompatibility-wp, dealerdirect/phpcodesniffer-composer-installer). Manifest committed in C6.5.B; `composer install` is a developer-setup step (vendor/ gitignored). Composer scripts: `lint`, `lint:summary`, `lint:fix`, `test` (maps to the smoke runner).
  - ✅ **Add `phpcs.xml`** configured to WordPress + WordPress-Extra + WordPress-Docs + PHPCompatibilityWP rulesets, text-domain pinned to `equine-event-manager`, prefix rules (`eem_`, `EEM_`, `_en_`, `en_`), excludes (`tests/`, `scripts/`, `vendor/`, `node_modules/`, `.mockups/`, asset CSS/JS). Audit deferred to dev setup — see the C6.5.B commit body for the exact command, or `composer run lint:summary` once `composer install` is run.
  - ✅ **License headers audit** on every PHP file. Compact `@license GPL-2.0-or-later` + `@copyright 2024-2026 Whitney Mitchell` lines added inside the existing `@package EEM_Plugin` docblocks across all 20 admin/includes/public/templates PHP files. `tests/` + `scripts/` excluded per phpcs.xml carve-out (echo-heavy, no docblocks expected). Landed in C6.5.C.
  - ✅ **Properly format the plugin header** in `equine-event-manager.php` per [WP plugin header conventions](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) — converted from bare `/* */` to `/** */` docblock; added Plugin URI, Requires at least (6.0), Tested up to (6.8), Requires PHP (7.4), Author URI, License, License URI, Domain Path, Update URI (false, suppresses WP.org update checks for in-development copies). Placeholder URIs flagged in CLEANUP entry #23 for pre-release fix. Landed in C6.5.C.
- **Why deferred:** Premature standardization mid-build slows iteration. By post-C5 the codebase has stable patterns; standardizing now locks them in for the rest of the build.
- **Added in:** C5.G.10
- **Sequence:** **"C6.5" sprint BEFORE C6** (promoted from the original between-C6-and-C7 slot). Rationale: shipping C6 into versioned infra (tests/, composer.json, phpcs.xml, license headers, full plugin header) avoids retrofitting all of that after C6 lands. C6 builds on top of stable infra from its first commit instead of getting decorated post-hoc. Updated chunk sequence: **C6.5.A → C6.5.B → C6.5.C → C6 → C6.6 → C7**.
- **Unblocks deletion:** N/A (additive infra).
- **Status:** ✅ All 5 items complete. C6.5.A (test infra), C6.5.B (composer + phpcs), C6.5.C (license + plugin header). C6.5 sprint closed.

### 16. Customer Profile chunk sequencing — link targets pre-wired
- **What:** Orders list page wires customer-name spans as `<a class="eem-customer-name" href="admin.php?page=equine-event-manager-customer&customer_email=X">` anchors and order-number spans as `<a class="eem-order-num" href="admin.php?page=equine-event-manager-order&order_key=Y">` anchors. Order Detail destination (`equine-event-manager-order` slug) is the existing legacy `EEM_Admin::render_order_detail_page` callback — C6 replaces. Customer Profile destination (`equine-event-manager-customer` slug) is a hidden placeholder admin page registered by `EEM_Orders_List_Page::register_customer_profile_stub()` with `EEM_Orders_List_Page::render_customer_profile_stub()` as the callback — renders a "Customer Profile is on the planned roadmap" card.
- **Why deferred:** Customer Profile is NOT currently sequenced in the Phase 3 chunk plan (C1–C16). The page needs sequencing before C16 — otherwise these anchors land on a permanent placeholder. Listing here so the next chunk-planning conversation explicitly slots it in.
- **URL convention to honor when the real chunk lands:**
  - Customer Profile: `admin.php?page=equine-event-manager-customer&customer_email={email}` — keyed by customer email since order rows don't carry a customer_id.
  - Order Detail: `admin.php?page=equine-event-manager-order&order_key={key}` — keyed by the legacy order_key.
  - Both URLs additionally accept `&panel=refund` / `&panel=collect` extras per C5.C's `order_detail_url()` helper.
- **Added in:** C5.G.8
- **Unblocks deletion:** Customer Profile chunk (when sequenced) replaces the stub callback in `EEM_Orders_List_Page::register_customer_profile_stub()` with the real page registration. The stub method + the placeholder render method can be removed (or repurposed if the real page wants the same shell pattern). The `CUSTOMER_PROFILE_MENU_SLUG` constant stays — it's the URL convention contract.
- **Status:** stub shipped; awaiting Customer Profile chunk to be sequenced into the Phase 3 plan

### 15. ~~Bulk refund async engine (REF-3 / ORD-2)~~ ✅ Resolved in C6.C
- **What was deferred:** `EEM_Orders_List_Page::handle_bulk_refund` (C5.D) validated the modal POST and redirected with a `bulk_refund_deferred` notice — no refunds were actually processed.
- **How it was resolved (C6.C, 2026-05-23):**
  1. New AJAX endpoint `wp_ajax_eem_order_bulk_refund_step` on EEM_Admin processes one order per call. Server computes refund amount via `get_order_remaining_refundable($order_key)` at call time (no client-supplied amount) — retry-safety property documented in the handler's docblock.
  2. JS layer (`runBulkRefundQueue` + `processNextBulkRefundStep` in admin.js) drives the batch sequentially via per-order AJAX calls. Per-order outcomes (success / failure / was_noop) attribute cleanly to individual order_keys.
  3. Modal 3-state UI: **intro** (confirm form + tab-close warning) → **processing** (per-order progress list with live status glyphs) → **summary** (totals + failure list + "Retry failed (N)" button).
  4. Continue-past-failures (option-3 batch error attribution per C6.C kickoff Q2): every order processes regardless of upstream failures; failures collected into a separate list with retry affordance.
  5. Retry re-validates remaining_refundable at call time (smoke-verified): parallel admin actions between batch and retry do not produce stale-amount refunds.
  6. Activity-log writing moved into the `process_amount_refund` kernel so both single (C6.B) and bulk (C6.C) callers inherit telemetry without duplication.
  7. Notification email (the `notify=1` checkbox) wiring is part of C6.D — the email-send hook joins the 5 auto-fire telemetry points there; the checkbox value is captured in the activity-log payload now and surfaces in the C6.D mailer integration when it lands.
- **Closed:** C6.C (2026-05-23). Reuse-pattern audit confirmed `process_amount_refund` works clean in the bulk loop — `EEM_Refund_Engine` extraction (CLEANUP #27) remains queued for post-C6 polish.
- **Known follow-on:** CLEANUP #29 (bulk refund order-fetch optimization) surfaced during the C6.C audit — admin-only + infrequent perf concern, deferred.

### 14. Orders soft-delete schema (Move to Trash for orders)

### 14. Orders soft-delete schema (Move to Trash for orders)
- **What:** `EEM_Orders_List_Page::handle_trash` is a stub. Per ORD-3 ("Move to Trash (renamed from the original 'Delete Order') is WP-standard soft delete: the order is recoverable from the trash for 30 days") the orders list should support reversible trash semantics, but the underlying `wp_en_stall_reservations` / `wp_en_rv_reservations` tables have no `trashed_at` column. The handler currently redirects with a `?eem_notice=order_trash_deferred` warning rather than fall back to the legacy hard-delete (`EEM_Orders_Repository::delete_order`), which would surprise users expecting soft semantics.
- **Why deferred:** Schema migration via dbDelta is its own chunk-shaped piece of work AND the related "purge after 30 days" cron + a Trash status badge + a Restore handler all need to ship together to be coherent.
- **Added in:** C5.C
- **Unblocks deletion:** A future C11-or-later chunk that adds the `trashed_at` column on both order-component tables, wires a daily purge cron, adds an orders Trash status filter + Restore meatballs item on trashed rows, and replaces the stub redirect with a real soft-delete call.
- **Status:** stub shipped; awaiting schema chunk

### 1. `assets/css/admin-legacy.css` — full file
- **What:** 12,376-line legacy admin CSS, renamed from `admin/css/equine-event-manager-admin.css` during Phase 2. **Damage scope substantially worse than the original entry suggested** — see findings below.
- **Why deferred:** Each Phase 3 page-port chunk migrates rules from this file into the new `assets/css/admin.css`. Deleting it before all pages are ported would break unported screens visually.
- **Added in:** C1 (Phase 2 cleanup tag — kept through Phase 3 transition)
- **C4-discovered damage profile:** the file is not just "12k lines to migrate" — it contains **massive duplication of `!important` overrides on common selectors**. Empirically catalogued during c4-polish-2 form-control remediation:
  - **6 distinct `!important` blocks** target `body.eem-shell-page input[type="*"] / select / textarea` (lines ~219, ~5946, ~6606, ~7637, ~8297, ~11849 + their `body.post-type-en_reservation` mirrors). Collectively they force `min-height: 42–44px`, `padding: 0.65rem 0.85rem`, border, border-radius (now `var(--eem-radius)` in all 6 after C7.X.19), background (kills SVG chevron backgrounds), box-shadow, color, and font-family on every form control. ~180 LOC of redundant overrides.
  - **~15 distinct `.button` / `.button-primary` / `.button-secondary` blocks** (lines 109, 124, 134, 5881, 5894, 5901, 6539, 6552, 6561, 7569, 7584, 7592, 8206, 8222, 8235, 8247, 11829, 11838, 11844) similarly stack overrides. New `.eem-toolbar-btn` style components were unaffected because the legacy blocks target the WP `.button` class, but any future component using a bare `<button>` element under shell pages will need similar audit.
  - Each Phase 3 component port that introduces form-control elements has to defend against ALL 6 blocks. c4-polish-2 added `:not(.eem-search-input)` and `:not(.eem-toolbar-select)` exclusions to 22 selector lines across the 6 input/select blocks. Documented as a recurring tax in CLAUDE.md hygiene rule #7 with a new prospective-port checklist (see CLAUDE.md C4-discoveries section).
- **C7.X.19 form-control block inventory (canonical reference — updated after radius eradication):** The 6 form-control `!important` blocks are the cascade authority for ALL form controls on shell pages. Block 6 (~line 11849) is the cascade WINNER — the last `!important` source always prevails. New component classes must add `:not(.eem-your-component)` exclusions to ALL 6 blocks, not just block 1.

  | Block | Approx. line | Selector shape | Key forced properties (post C7.X.19) |
  |-------|--------------|----------------|--------------------------------------|
  | 1 | ~219 | `body.eem-shell-page:not(.eem-page--orders-guide) input[type="text"]:not(.eem-field-input) …` | `border-radius: var(--eem-radius) !important`, min-height, padding, border, font |
  | 2 | ~5946 | Same selector family (second context layer) | `border-radius: var(--eem-radius) !important`, background, box-shadow |
  | 3 | ~6606 | Same selector family (third context layer) | `border-radius: var(--eem-radius) !important`, color, font-family |
  | 4 | ~7637 | Same selector family (fourth context layer) | `border-radius: var(--eem-radius) !important`, padding variants |
  | 5 | ~8297 | Same selector family (fifth context layer) | `border-radius: var(--eem-radius) !important` |
  | 6 | ~11849 | Same selector family incl. `select:not(.eem-dashboard-range-select):not(.eem-list-select):not(.eem-toolbar-select):not(.eem-field-select)` and `textarea:not(.eem-field-input):not(.eem-field-textarea)` | `border-radius: var(--eem-radius) !important` — **CASCADE WINNER** |

- **C7.X.19 intentional 12px exceptions (CLEANUP-documented — do NOT convert to token):**
  - `.eem-orders-guide__toolbar input[type="search"]` — guide-section scoped, intentional card-level pill treatment (CLEANUP entry comment in admin-legacy.css)
  - `#titlediv input`, `.postbox input`, `.eem-editor-overview-card input` — WP editor / postbox containers, scoped away from broad shell-page blocks
  - Mobile responsive table row inputs (media query block)
  - Invoicing picker affixed controls (`.eem-invoice-picker-affixed`)
  These are all narrowly scoped selectors — they do NOT match the broad `body.eem-shell-page:not(...) input[type="text"]:not(.eem-field-input)` pattern and therefore are NOT cascade winners for plugin form-control elements. They are harmless but should remain documented so C16 wholesale strip doesn't accidentally remove the intentional exceptions.
- **Unblocks deletion:** Final Phase 3 commit (C16), after C3.D + C4 + C5 + C6 + C7 + C8 + C9 + C10 + C11 + C12 + C16 + C14 + C15 each migrate their page's rules out. Also delete the second `wp_enqueue_style( 'eem-admin-legacy', … )` call in `EEM_Admin::enqueue_backend_shell_styles` + `EEM_Reservation_Editor::enqueue_editor_shell_styles`.
- **C16 remediation scope (revised — substantially larger than originally estimated):** rather than per-page `:not()` decoupling for every new component, C16 should **strip the entire form-control + button restyle stacks wholesale**. Six form-control blocks ÷ ~180 LOC each + fifteen button blocks ÷ ~250 LOC each ≈ **~430 LOC of legacy CSS** are pure `!important` cartels duplicating each other. Removing them outright (rather than piecemeal-excluding from each) is cleaner. Risk: any legacy admin screen still relying on the 44px form-control look will look slightly different after C16 — accepted, since by C16 every page is ported anyway.
- **C16 ACTUAL outcome (v2.7.123) — form-control cartel STRIPPED, button cartel DEFERRED (corrected analysis):**
  - **Form-control cartel: DONE.** The 6 generic FC blocks (`body.eem-shell-page:not(--settings):not(--reservations-list):not(--orders) input/select/textarea` + their `body.post-type-en_reservation` comma-mirrors) were removed wholesale — 8 rule blocks / 163 lines (script `/tmp/eem_legacy_strip.js`, dry-run-then-write, brace-balance verified). These cleanly mirror ported-page-excluded-generic + dead-post-type selectors with no still-live bundling, so removal is coherent. Browser-verified across reservation-editor, Create Order, Settings, Dashboard: the only plugin controls the cartel still actively styled were `.eem-mb-input` / `.eem-mb-num` / `.eem-tag-search` on the editor (all already have admin.css rules); post-strip they fall to admin.css + WP-core forms.css (normal 40px WP input look) — no breakage.
  - **Button cartel: NOT stripped — the original "~15 button blocks" assumption was WRONG.** Those button rules comma-bundle a **still-live** `body.eem-shell-page .wp-core-ui .button` selector (NO port-exclusion chain) that styles WP buttons across ALL admin pages, including the ported ones, to the plugin look. They are partly load-bearing; deleting them (or half of a mirrored base/hover pair) would regress live button chrome on every page. The page-component button blocks (`.eem-stall-chart-actions .button`, `.eem-order-detail-toolbar-card__actions .button`, etc.) are likewise intentional. Button-cartel cleanup, if ever done, needs the live `.wp-core-ui .button` styling first migrated into admin.css as a proper (non-`!important`) component rule — a separate, larger task, not a release-cut strip. CLEANUP #13 (search-button seam) was hoping this strip would fix it; it won't, since the button overrides remain.
  - **Dead `body.post-type-en_reservation` postbox chrome** (`#titlediv`/`#submitdiv`/`#titlewrap` blocks) left in place — dead (that body class no longer renders; the CPT edit screen redirects to the custom `equine-event-manager-reservation-editor` page) but out of scope for the form-control strip; harmless.
- **Status:** form-control cartel ✅ stripped in C16 (v2.7.123); button cartel deferred (corrected scope — see above).

### 2. `admin/images/equine-event-manager-logo.png` — duplicate of `assets/images/logo.png`
- **What:** Pre-existing legacy logo PNG used by `EEM_Reservation_Editor::render_editor_header` (admin/class-equine-event-manager-reservation-editor.php:377).
- **Why deferred:** Phase 3 added a parallel copy at `assets/images/logo.png` for the breadcrumb partial; the legacy file still has one live consumer in the Reservation Editor's header shim.
- **Added in:** C1 (flagged during the C1 wrap-up)
- **Unblocks deletion:** C7 (Edit Reservation port), when `render_editor_header` is rewritten or removed. At that point: switch the surviving reference to `assets/images/logo.png` (or remove if no longer needed) and `git rm admin/images/equine-event-manager-logo.png`.
- **Status:** unchanged since C1

### 3. ~~`EEM_Admin::render_settings_page` — legacy 662-line method~~ ✅ Resolved in C3.D.4
- **What:** Original Settings page renderer in `admin/class-equine-event-manager-admin.php`. Was wired as the menu callback through C3.A–C3.C so the live Settings page kept working during the parallel build-up.
- **Resolution:** Method + its docblock deleted (lines 5311–5975, −665 LOC). All seven settings getters it used (`get_company_settings`, `get_feature_settings`, `get_integration_settings`, `get_receipt_settings`, `get_reservation_message_settings`) plus `render_admin_notice` / `render_brand_banner` retained — confirmed in use by other admin pages.
- **Closed in:** C3.D.4 (post browser-verification of the new EEM_Settings_Page)

### 4. Communications-panel sub-section method scope
- **What:** `EEM_Settings_Page` private methods `render_communications_sender_section`, `render_communications_templates_section`, `render_communications_template_card`, `render_communications_policies_section`, plus the helpers `build_sample_placeholder_values` and `apply_placeholders`.
- **Why noted (not cleanup yet):** These are real code, not legacy. Tracking here only because they're SETTINGS-SPECIFIC helpers — if any other panel in C3.C reuses the `apply_placeholders` substitution pattern, that one should be extracted to a shared place. Re-evaluate at C3.C wrap-up.
- **Added in:** C3.B
- **Unblocks deletion:** N/A — this is a "verify generalization" item, not a removal item. Drop the entry if C3.C confirms no extraction is needed.
- **Status:** to re-evaluate at C3.C wrap-up

### 5. Four deferred mockups (decisions.md "Pending Mockups" + C4 modal)
- **What:** `create_order_page.html`, `customer_detail_page.html`, Cancel Event button amendment on `edit_reservation_page.html`, **Email Customers compose modal** (no mockup file).
- **Why deferred:** Mockups not built. The corresponding code surfaces:
  - Invoicing → New Order mode is a "Coming next release" placeholder; the canonical mockup `create_order_page.html` landed in handoff Step 1 and the chunk that builds the page is **C16 (Create Order admin page)** post-handoff (was conceptually C12 in the pre-handoff roadmap before C11 was split).
  - Customer Detail link on Order Detail card (C9 — ODET-5) renders as plain text, not a link.
  - Cancel Event button on Edit Reservation (C7) is omitted; the bulk-refund engine is still built so the button can be added in a future chunk.
  - Email Customers modal (C4.C — reservations list meatballs item): no mockup depicts the compose UI. C4.C ships a minimal subject/body/Send modal designed to the brand-guide token system; spec-faithful redesign drops in when the mockup lands.
- **Added in:** Phase 3 plan (first 3) + C4.C (modal)
- **Unblocks deletion:** N/A (these are stubs waiting for spec, not legacy to remove). When mockups land, the stubs get replaced with real implementations. Track here to make sure they don't ship as placeholders to production.
- **Status:** awaiting spec

### 6. `[equine_event_manager_event_reservation]` long-form shortcode
- **What:** Deprecated-alias shortcode in `public/class-equine-event-manager-shortcodes.php:49` + handler at line ~62. Marked `@deprecated` in P2.2 but kept active because Elementor templates on the test site use it.
- **Why deferred:** User confirmed it's actively used. Keep until at least one full event cycle has run on the new `[en_reservation]` / `[en_stall_reservation_form]` shortcodes without breakage.
- **Added in:** P2.2
- **Unblocks deletion:** Post-Phase-3, after a full event cycle confirms no live page still depends on it. Removal is then: drop `add_shortcode` registration + `render_event_reservation_shortcode` method + the `find_reservation_by_event_id` helper if it has no other callers.
- **Status:** indefinite hold

### 7. ~~Settings panel stub methods (5 of 6)~~ ✅ Resolved in C3.C
- **What:** `EEM_Settings_Page::render_integrations_panel`, `render_branding_panel`, `render_shortcodes_panel`, `render_payments_panel`, `render_addons_panel` — were all stubs in C3.A.
- **Resolution:** All five replaced with real implementations across C3.C.1–C3.C.5. No stubs remain in `EEM_Settings_Page`.
- **Closed in:** C3.C.5 (Add-Ons was the last)

### 12. `.eem-page a` global anchor color rule — recurring specificity bully
- **What:** `assets/css/admin.css:172` sets `.eem-page a { color: var(--eem-electric); }` as a page-wide default. Specificity (0,1,1) wins against every class-level color rule (typically 0,1,0), forcing every anchor inside the EEM admin chrome to Electric Blue unless explicitly fought.
- **Why deferred:** Every new component with an anchor element has to chain `a.` on its color rules to win specificity. Documented regressions so far:
  - C4.B "+ New Reservation" button text invisible on electric-on-electric (hotfix `ab2fa05`)
  - C4-polish-1 status tabs forced to Electric Blue when mockup specifies gray
  - C4-polish-1 reservation title links blocked from Navy default per mockup
  Each was patched locally with `a.` chains. The recurring pattern is the real issue — every future component port (C5, C6, C7, C8...) will hit the same trap on first render.
- **Added in:** c4-polish-1 (issue identified across all of C4)
- **Unblocks deletion:** C16 polish pass. Two refactor candidates:
  - **(a) Remove the default `color` on `.eem-page a`** — require every component CSS to color its own anchors. Forces all current `a.` chains to become unnecessary; new components don't trip the trap. Risk: any unstyled anchor reverts to WP/browser default link blue, which differs from `--eem-electric`. Mitigation: sweep all anchors at audit time and ensure each has a class with explicit color.
  - **(b) Scope the rule** to `.eem-page a:not([class*="eem-"])` — applies only to anchors with no `eem-*` class. Cleverer but more brittle; depends on every component anchor having an `eem-*` class.
  - **Prefer (a)** — cleaner contract, even if it requires a one-time anchor-color audit.
- **Status:** ✅ RESOLVED in C16 (v2.7.122) via option (b): `.eem-page a` color + text-decoration scoped to `:not([class*="eem-"])`. Anchor-rendered `eem-*` components (`.eem-btn-*` etc.) now own their own color; bare content links still get `--eem-electric`. Option (a) was the stated preference but (b) is lower-risk for a release-cut and achieves the same outcome without a full anchor-color sweep.

### 13. Search input + button visual attachment didn't fully land in C4
- **What:** The Reservations list search pair (`.eem-search-input` + `.eem-search-btn`) was supposed to render visually attached per mockup line 65 (input right corners squared, button left corners squared, button no left border, zero gap between them). After 4 rounds of polish (c4-polish-1 attached treatment, c4-polish-2 specificity bumps, round-3 source-order reorder, round-4 flex-gap fix), the gap between the two elements closed but the button's corner-radius and possibly its left border still don't render per spec.
- **Why deferred:** The cascade analysis points to my class rules at (0,3,0) winning over WP-core's (0,1,1) — they SHOULD apply. Live admin.css being served matches HEAD (verified via curl + grep). But empirically the visual still shows the button with all four corners rounded and a visible seam break. Diagnosis ran out of cheap leads; needed a DevTools cascade dump from Whitney to pin which rule is actually winning, and we chose to accept current state per the hard-stop rule and move on rather than keep guessing.
- **Symptoms still visible at c4-close:**
  - Button border-radius not asymmetric per `.eem-list-toolbar .eem-search-btn { border-radius: 0 4px 4px 0 }` rule (round-3 source-order reorder confirmed in commit 7bbcf5b but visual unchanged)
  - Possibly button left border still present despite `.eem-search-btn { border-left: none }` rule
  - Functionally: search input + button both work; clicking either submits the form. Visual-only defect.
- **Added in:** c4-polish-2 (Whitney accepted current state after 4 polish rounds)
- **Unblocks deletion:** C16 polish pass, OR sooner if it turns out to be downstream of the admin-legacy.css wholesale strip planned for C16 (entry #1). Worth a DevTools investigation when that strip lands — most likely a legacy `!important` block on `button` element that I missed in the c4-polish-2 form-control sweep (the sweep covered `input` + `select` + `textarea`, not `button`). Verify by inspecting the search button in DevTools after the wholesale strip; if the seam visibility improves, the legacy bare-button overrides were the cause.
- **Status:** accepted at c4-close; revisit in C16

### 10. Bulk Edit on Reservations list — handler returns "unsupported" notice
- **What:** The Reservations list bulk-action dropdown offers `Edit` and `Move to Trash` per RES-3. C4.D wires Move to Trash end-to-end; Edit currently redirects with `eem_notice=bulk_edit_unsupported` ("Bulk Edit is not available yet — it will land in a future release. Use the per-row Edit link for now.").
- **Why deferred:** WP-native bulk edit relies on `WP_List_Table`'s inline-edit machinery which the Phase 3 custom page (Path B) deliberately doesn't extend. A proper bulk-edit UX needs its own modal (similar to the Email Customers modal) with the fields-to-change form. Scope is meaningfully larger than C4.D could accommodate and the per-row Edit link covers the immediate use case.
- **Added in:** C4.D
- **Unblocks deletion:** Future chunk (likely C16 polish or a dedicated follow-up). Either ship a real bulk-edit modal, or remove the `Edit` option from the dropdown and the corresponding handler branch. Don't ship a "not available yet" notice to production.
- **Status:** awaiting decision

### 11. `orderby=orders` sort uses PHP two-pass instead of SQL
- **What:** `EEM_Reservations_List_Repo::get_paginated` honors `orderby=orders` by fetching up to 500 candidate posts, computing the orders count per post in PHP, sorting, then hand-paginating. SQL ORDER BY can't be used directly because the orders count is derived from `notes LIKE '%Reservation setup ID: N%'` across two tables.
- **Why deferred:** Works fine at the scale of a single venue's reservation list (typically <100 reservations). Becomes a problem if a producer has 500+ reservations or a multi-tenant deployment.
- **Added in:** C4.D
- **Unblocks deletion:** C12 (Order Receipt + Hosted Order Page — receipt-rendering chunk owns the per-order schema work). When per-order rows gain a denormalized `reservation_id` column (per CLEANUP entry #9), the sort can become a proper SQL JOIN + COUNT + ORDER BY. Also drop the 500-row safety cap.
- **Status:** awaiting C12 (chunk recategorization, post-handoff Step 2 — was C11)

### 9. Per-order persisted `total` columns exclude tax allocation
- **What:** `wp_en_stall_reservations.total` and `wp_en_rv_reservations.total` columns store `subtotal + convenience_fee` only — they do NOT include the tax that was actually charged at checkout. C3.D.1 wires tax into the *aggregate* `$totals['total']` (what the customer pays via Stripe / Auth.net), but defers the per-order allocation question.
- **Why deferred:** Tax allocation between split stall + rv orders is a real product decision (proportional to subtotal? all on stall? add a dedicated `tax` column on each table?), AND a dedicated `tax` schema column requires a dbDelta migration. Both are receipts/email-breakout shaped work.
- **Added in:** C3.D.1
- **Unblocks deletion:** C12 (Order Receipt + Hosted Order Page — receipt-rendering needs accurate per-order totals). At that point: add `tax` column to both `en_stall_reservations` and `en_rv_reservations` via dbDelta in EEM_Activator, allocate `$totals['tax']` proportionally during insert in `insert_reservation_orders`, update `total` to include tax, and surface as a line item on the customer receipt + admin order detail.
- **Status:** awaiting C12 (chunk recategorization, post-handoff Step 2 — was C11)

### 52. Preview button C10 customer-facing destination
- **What:** `_rail-publish-card.php` Preview button is currently rendered `<button disabled>` with tooltip "Customer preview available after C10 ships." per C7.X.16 Issue D3. The button preserves visual presence in the rail card; clicking does nothing.
- **Why deferred:** `en_reservation` CPT is registered `public => false / rewrite => false / has_archive => false`. The customer-facing surface is the `[en_reservation id="N"]` shortcode rendered on whatever WP page the admin embeds it on — there is no canonical "preview URL the plugin owns." C10 (Customer Event Page port) is the chunk that wires the customer-facing render properly.
- **Added in:** C7.X.16
- **Unblocks deletion:** C10 — wire the button to either (a) a query-arg admin preview page rendering the shortcode in an admin-styled wrapper, OR (b) the canonical front-end page once it ships. Remove the `disabled` + `aria-disabled` + tooltip; restore the click dispatch.
- **Status:** awaiting C10

### 53. Media Library modal — plugin selector leakage into WP chrome
- **What:** C7.X.16 Issue E applied a defensive `z-index: 200000` raise to `.media-modal-backdrop, .media-modal` to fix Whitney's bleed-through of WP admin sidebar "Menu ▾" toggle into the Agreement Upload modal.
- **Root cause 1 (C7.X.17):** WP's `.media-modal-backdrop` ships with `opacity: 0.7` — admin chrome bleeds through at 30% visibility. Fix: backdrop uses `background: rgba(0,0,0,0.7) !important` + `opacity: 1 !important` + `z-index: 199999`; modal at `z-index: 200000`.
- **Root cause 2 (C7.X.18):** Plugin's `.button:not(.button-primary):not(.button-link-delete)` selectors in admin-legacy.css also catch WP's `.button.button-link.media-frame-menu-toggle` inside the modal, applying gradient + border chrome to the "Menu" link-button. Fix: added `:not(.button-link)` to both leaking button blocks (lines ~5931 and ~6585 in admin-legacy.css).
- **Status:** RESOLVED in C7.X.17 (backdrop opacity) + C7.X.18 (button selector leakage). Structural defense #13 added to CLAUDE.md.

### 51. Linked Event rail card retired (C7.X.12 Item 7)
- **What:** `templates/admin/reservation-editor/_rail-linked-event-card.php` — DELETED in C7.X.12. Linked-event editing migrated inline to the meta-line via `(change)` / `(unlink)` action links.
- **Why deferred items (none for the partial itself — fully gone):** Two adjacent loose ends remain:
  1. JS handler at admin.js:1834 retains `addBtn.closest('.eem-repeating-row-helper')` fallback (C7.X.11). Unrelated to this entry — same C16 strip candidate as #50.
  2. The `(change)` flow in C7.X.12 is a functional "confirm → unlink → reload to (link event) affordance" flow. A proper inline typeahead modal launched from `(change)` is a focused follow-up if/when there's product appetite for the polish. Low priority — current 2-click flow is functional.
- **Status:** partial deletion complete. Follow-up #2 is product-optional polish.

### 50. Orphan partial `_repeating-row-helper.php`
- **What:** `templates/admin/reservation-editor/_repeating-row-helper.php` — emits a `.eem-repeating-row-helper` wrapper div carrying `data-eem-repeating-template` + `data-eem-repeating-tbody` attrs for the C7.C.1-era add-row JS handler.
- **Why deferred:** Confirmed zero active callers (`grep -rn "_repeating-row-helper\|eem_render_repeating_row_helper\|repeating-row-helper.php" templates/ admin/ includes/` returns nothing). Not auto-included via `require_all` / `glob()` / autoloader. The C7.X.4 mockup-canonical port retired this wrapper in `_section-addons.php` + `_section-rv.php`; they now emit the data-attrs directly on `<button class="eem-btn-add">`. The partial file was not deleted, and the JS handler at admin.js:1831 retains a fallback `.closest('.eem-repeating-row-helper')` path in case any future caller resurrects the wrapper (C7.X.11 fix).
- **Added in:** C7.X.11 (orphan since C7.X.4)
- **Unblocks deletion:** Never blocks anything — truly dead. C16 wholesale strip should delete the file AND remove the JS handler's ancestor-fallback branch (by C16 all C7.X.* iterations are settled and no future caller will resurrect).
- **Status:** ✅ RESOLVED in C16 (v2.7.122). File `git rm`'d; admin.js add-repeating-row handler simplified to read template/tbody IDs directly from the button (ancestor fallback removed).

### 8. `render_panel_stub` helper itself
- **What:** `EEM_Settings_Page::render_panel_stub( $panel_id )` — the "Coming soon" placeholder card used during the C3.A → C3.C build-up.
- **Why deferred (not deleted now):** Still useful infrastructure if a future panel needs a placeholder during its build-up, AND `render_panel( $panel_id )` falls through to it via `method_exists` lookup if any `render_<id>_panel` method is missing. Removing it would change failure mode from "shows placeholder" to "fatal" — worse UX.
- **Added in:** C3.C
- **Unblocks deletion:** Never, intentionally. Leave as the safety net. Drop this entry on next review if we agree it stays.
- **Status:** keep indefinitely; review-and-drop-entry candidate

---

## Removed entries (history)

*Entries are moved here when their cleanup actually ships, so we can audit what got removed and when.*

— *(empty so far)*
