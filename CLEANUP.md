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

### 1. `assets/css/admin-legacy.css` — full file
- **What:** 12,343-line legacy admin CSS, renamed from `admin/css/equine-event-manager-admin.css` during Phase 2.
- **Why deferred:** Each Phase 3 page-port chunk migrates rules from this file into the new `assets/css/admin.css`. Deleting it before all pages are ported would break unported screens visually.
- **Added in:** C1 (Phase 2 cleanup tag — kept through Phase 3 transition)
- **Unblocks deletion:** Final Phase 3 commit, after C3.D + C4 + C5 + C6 + C7 + C8 + C9 + C12 each migrate their page's rules out. Also delete the second `wp_enqueue_style( 'eem-admin-legacy', … )` call in `EEM_Admin::enqueue_backend_shell_styles` + `EEM_Reservation_Editor::enqueue_editor_shell_styles`.
- **Status:** unchanged since C1

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
  - Invoicing → New Order mode is a "Coming next release" placeholder in C12.
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

### 9. Per-order persisted `total` columns exclude tax allocation
- **What:** `wp_en_stall_reservations.total` and `wp_en_rv_reservations.total` columns store `subtotal + convenience_fee` only — they do NOT include the tax that was actually charged at checkout. C3.D.1 wires tax into the *aggregate* `$totals['total']` (what the customer pays via Stripe / Auth.net), but defers the per-order allocation question.
- **Why deferred:** Tax allocation between split stall + rv orders is a real product decision (proportional to subtotal? all on stall? add a dedicated `tax` column on each table?), AND a dedicated `tax` schema column requires a dbDelta migration. Both are receipts/email-breakout shaped work.
- **Added in:** C3.D.1
- **Unblocks deletion:** C11 (Email/Receipt port — EMAIL-5). At that point: add `tax` column to both `en_stall_reservations` and `en_rv_reservations` via dbDelta in EEM_Activator, allocate `$totals['tax']` proportionally during insert in `insert_reservation_orders`, update `total` to include tax, and surface as a line item on the customer receipt + admin order detail.
- **Status:** awaiting C11

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
