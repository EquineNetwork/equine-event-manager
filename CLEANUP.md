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

### 22. RES-ARCH-1 non-conformance — reservation title + dates read from post, not source event
- **What:** Per decisions.md RES-ARCH-1 (added 2026-05-23), the reservation's user-visible title and event dates are read-only mirrors of the source event (Native / TEC / External Feed). Current code violates this: `EEM_Reservations_List_Page::render_table_row()` + `render_mobile_cards()` call `get_the_title( $post )` (reads `wp_posts.post_title` on the reservation), and `EEM_Reservations_List_Repo::get_event_date_range_label()` reads `_en_nightly_start_date` / `_en_nightly_end_date` / `_en_weekend_start_date` / `_en_weekend_end_date` from reservation post_meta. None of the six call sites resolves to a source event.
- **Why deferred:** The fix requires a new source-event resolver (single function returning `[ title, start_date, end_date ]` from the active source) plus refactor of every call site that reads title/dates from the reservation. Substantial — definitely a discrete chunk. Plus an in-flight cache-invalidation decision: do title/dates become resolver-only (no cache, slower list-page renders) or do they stay in post_meta as a derived cache written by a source-change sync handler? That trade-off needs discussion, not improvisation.
- **Added in:** C5.G.13 (defect discovered during the C5.G.10 RES-ARCH-1 verification audit)
- **Migration plan:**
  1. Build `EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id )` returning `[ 'title' => string, 'start_date' => string, 'end_date' => string, 'venue' => string ]` from the active source (dispatches to Native CPT / TEC API / External Feed per the Settings event-source mode).
  2. Update `EEM_Reservations_List_Repo::get_event_date_range_label()` to call the resolver instead of reading post_meta directly.
  3. Update `EEM_Reservations_List_Page::render_table_row()` + `render_mobile_cards()` to call the resolver for the title instead of `get_the_title()`.
  4. Decide cache-invalidation discipline (resolver-only vs. cached-meta-with-sync-handler). If cached: write a `EEM_Reservation_Source_Sync::on_source_change( $source_event_id )` action that pushes title + dates to the linked reservation's post_meta + post_title.
  5. C7 Edit Reservation editor renders title + dates as read-only labels with "Linked to: {source event}" hint instead of input fields.
- **Sequence target:** **Between C6 and C7** — must precede C7 because C7 is where the user-facing UI lands (read-only labels instead of input fields). Candidate chunk name "C6.6 source-event resolver" (slotted AFTER C6 and AFTER the C6.5 professionalization sprint per entry #17, BEFORE the C7 editor port). Updated sequence as of C6.5.A: **C6.5 → C6 → C6.6 → C7** (C6.5 promoted ahead of C6, see entry #17).
- **Unblocks deletion:** the four nightly/weekend date meta keys MAY survive as a derived cache (per the migration-plan step 4 decision) — if they do, they retain a single writer (the sync handler) instead of the current admin-input writer. If they don't, the four meta keys + the date-range-label helper become resolver-call-only.
- **Status:** non-conforming code identified + documented; awaiting the C6.6 resolver chunk

### 21. Searchable Event-filter dropdown (Choices.js) — UX scaling, not polish
- **What:** Orders + Reservations both use a native `<select>` for the Event filter. Becomes unwieldy past ~50 events (long scroll, no typeahead, no in-place filtering). Replace with Choices.js — adds searchable typeahead + better keyboard navigation. Apply to BOTH list pages for parity. Style the Choices.js shell to match EEM design tokens: navy borders, Electric Blue focus ring, proper border-radius matching `.eem-toolbar-select`. Audit Reservations during this chunk for similar issues with the Date filter dropdown + any future filters (Type, etc.) that might need the same treatment.
- **Why deferred:** Current native `<select>` works fine for the seeded test data (~3 events) but visibly degrades at production scale. Not a polish issue — a UX scaling issue that becomes a real blocker before user testing.
- **Added in:** C5.G.10
- **Sequence:** After C6 (Order Detail) or C7 (Edit Reservation), whichever has lighter dependencies. **Tag as "UX scaling" — do NOT defer to C13.** Must land before user testing because users with real event histories will hit the unusable native-select first.
- **Estimated scope:** ~100–150 LOC across PHP enqueue + JS init + CSS shell styling. Adding Choices.js requires explicit user approval (third-party JS library — per CLAUDE.md decision policy "Adding any third-party JS library — confirm the choice with me").
- **Unblocks deletion:** N/A — this is an additive UX upgrade, not legacy code removal.
- **Status:** queued; awaiting sequencing decision

### 20. Recurring dead-code audit after each chunk merge
- **What:** Run a focused dead-code sweep AFTER each chunk merges to main, BEFORE the next chunk starts. Check four categories:
  - **(a)** Old page-render callbacks that got replaced when menu swaps happened (precedent: `EEM_Admin::render_settings_page` deleted in C3.D.4 after C3.D.2 swap; `EEM_Admin::render_orders_page` body still present after C5.E swap — flag for C5.5 audit).
  - **(b)** CSS classes defined in admin.css that no longer have any markup using them. Grep-verify each class name → if zero hits in PHP render code, delete.
  - **(c)** PHP helper methods no longer called by any active code path. Grep-verify each `private function` against all callers; if zero, delete.
  - **(d)** JS handlers bound to data-eem-action selectors that no longer exist in any rendered markup. Audit admin.js dispatch table against PHP render output.
- **Why a standing practice, not a one-time:** Phase 3 chunks have been replacing components (C5.F-toolbar dropped 3 component classes; C5.G.3 dropped the `.eem-reservations-list` wrapper; C5.G.7 reverted then re-removed `.eem-btn-navy`). Each chunk produces orphans. Without the sweep they accumulate into a wholesale audit (C13 territory) which is far harder than catching incrementally. Lessons-learned cost: it took C5.F-toolbar + C5.G to discover the `.eem-orders-toolbar` legacy-CSS class collision because nobody audited after C5.B introduced the collision-prone name.
- **Process shape:** small "C{n}.5" audit chunk between merges. Single commit. Findings: a punch-list of removed selectors / methods / hooks with grep verification of zero remaining callers, plus the actual deletions.
- **Added in:** C5.G.10
- **Sequence:** Recurring — first instance is "C5.5 dead code audit" before C6 begins. Then "C6.5" before C7, "C7.5" before C8, etc.
- **Status:** queued; C5.5 ready to run after the C5 merge completes

### 19. Bucket 3 — End-of-build polish + asset pipeline
- **What:** Final-build hardening that doesn't make sense earlier. Three concerns:
  - **Asset build pipeline:** CSS minification, JS bundling/minification, source maps. Currently `admin.css` + `admin.js` ship un-minified.
  - **Lint configs:** ESLint config for `assets/js/`, Stylelint config for `assets/css/`. Run in CI + pre-commit hook.
  - **Performance pass:** query caching audit (object cache hits on legacy `EEM_Orders_Repository::get_grouped_orders` are unverified), lazy-enqueue audit (legacy `wp_enqueue_script` calls that fire on every admin page even when not needed), transient hot-path identification.
  - **Wholesale admin-legacy.css strip:** entry #1 wholesale-strip lands here too — by end-of-build every page is ported and admin-legacy.css can be removed wholesale (was C13-tagged in entry #1).
- **Why deferred:** Build pipeline + lint configs need the codebase to be feature-stable so the build doesn't have to re-tune for every chunk. Performance pass needs all pages built so the audit is comprehensive. admin-legacy.css strip needs every page ported.
- **Added in:** C5.G.10
- **Sequence:** End of Phase 3 (with C13 / Polish Pass).
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
  - **License headers audit** on every PHP file. Plugin needs consistent licensing. — queued for C6.5.C.
  - **Properly format the plugin header** in `equine-event-manager.php` per [WP plugin header conventions](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) — version, license, requires-at-least, tested-up-to, network, update URI. — queued for C6.5.C.
- **Why deferred:** Premature standardization mid-build slows iteration. By post-C5 the codebase has stable patterns; standardizing now locks them in for the rest of the build.
- **Added in:** C5.G.10
- **Sequence:** **"C6.5" sprint BEFORE C6** (promoted from the original between-C6-and-C7 slot). Rationale: shipping C6 into versioned infra (tests/, composer.json, phpcs.xml, license headers, full plugin header) avoids retrofitting all of that after C6 lands. C6 builds on top of stable infra from its first commit instead of getting decorated post-hoc. Updated chunk sequence: **C6.5.A → C6.5.B → C6.5.C → C6 → C6.6 → C7**.
- **Unblocks deletion:** N/A (additive infra).
- **Status:** C6.5.A done (test infra); C6.5.B done (composer + phpcs); C6.5.C queued (license + plugin header).

### 16. Customer Profile chunk sequencing — link targets pre-wired
- **What:** Orders list page wires customer-name spans as `<a class="eem-customer-name" href="admin.php?page=equine-event-manager-customer&customer_email=X">` anchors and order-number spans as `<a class="eem-order-num" href="admin.php?page=equine-event-manager-order&order_key=Y">` anchors. Order Detail destination (`equine-event-manager-order` slug) is the existing legacy `EEM_Admin::render_order_detail_page` callback — C6 replaces. Customer Profile destination (`equine-event-manager-customer` slug) is a hidden placeholder admin page registered by `EEM_Orders_List_Page::register_customer_profile_stub()` with `EEM_Orders_List_Page::render_customer_profile_stub()` as the callback — renders a "Customer Profile is on the planned roadmap" card.
- **Why deferred:** Customer Profile is NOT currently sequenced in the Phase 3 chunk plan (C1–C13). The page needs sequencing before C13 — otherwise these anchors land on a permanent placeholder. Listing here so the next chunk-planning conversation explicitly slots it in.
- **URL convention to honor when the real chunk lands:**
  - Customer Profile: `admin.php?page=equine-event-manager-customer&customer_email={email}` — keyed by customer email since order rows don't carry a customer_id.
  - Order Detail: `admin.php?page=equine-event-manager-order&order_key={key}` — keyed by the legacy order_key.
  - Both URLs additionally accept `&panel=refund` / `&panel=collect` extras per C5.C's `order_detail_url()` helper.
- **Added in:** C5.G.8
- **Unblocks deletion:** Customer Profile chunk (when sequenced) replaces the stub callback in `EEM_Orders_List_Page::register_customer_profile_stub()` with the real page registration. The stub method + the placeholder render method can be removed (or repurposed if the real page wants the same shell pattern). The `CUSTOMER_PROFILE_MENU_SLUG` constant stays — it's the URL convention contract.
- **Status:** stub shipped; awaiting Customer Profile chunk to be sequenced into the Phase 3 plan

### 15. Bulk refund async engine (REF-3 / ORD-2)
- **What:** `EEM_Orders_List_Page::handle_bulk_refund` validates the modal POST (cap + nonce + at least one valid order_key) and then redirects with `?eem_notice=bulk_refund_deferred&eem_bulk_count=N` — no refunds are actually processed. Per REF-3 / ORD-2 the engine is: queue refunds asynchronously via the merchant API one at a time (respecting rate limits), update Order state per the REF-2 status rules, write activity log entries, send the "Event Cancelled — Refund Processed" notification email to each customer (when notify=1), and collect failures into a "Needs Attention" list. None of that exists yet.
- **Why deferred:** The async queue + progress UI + error collection are sizeable in their own right, AND the Order Detail page (C6) is where the SINGLE-order refund flow lives that this engine ultimately calls per-order — building the engine in isolation from C6's per-order refund code path would duplicate plumbing. Build C6's single-order refund first, then lift the per-order helper into a queue runner.
- **Added in:** C5.D
- **Unblocks deletion:** C6 (Order Detail port) — once a `refund_single_order( $order_key, $amount, $reason, $notify )` helper exists, `handle_bulk_refund` calls it in a loop (sync first cut; async queue follows once the UX needs progress feedback).
- **Status:** dispatcher shipped; awaiting engine in C6

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
  - **6 distinct `!important` blocks** target `body.eem-shell-page input[type="*"] / select / textarea` (lines 142, 5910, 6569, 7600, 8260, 11812 + their `body.post-type-en_reservation` mirrors). Collectively they force `min-height: 42-44px`, `padding: 0.65rem 0.85rem`, border, border-radius (8-12px depending on block), background (kills SVG chevron backgrounds), box-shadow, color, and font-family on every form control. ~180 LOC of redundant overrides.
  - **~15 distinct `.button` / `.button-primary` / `.button-secondary` blocks** (lines 109, 124, 134, 5881, 5894, 5901, 6539, 6552, 6561, 7569, 7584, 7592, 8206, 8222, 8235, 8247, 11829, 11838, 11844) similarly stack overrides. New `.eem-toolbar-btn` style components were unaffected because the legacy blocks target the WP `.button` class, but any future component using a bare `<button>` element under shell pages will need similar audit.
  - Each Phase 3 component port that introduces form-control elements has to defend against ALL 6 blocks. c4-polish-2 added `:not(.eem-search-input)` and `:not(.eem-toolbar-select)` exclusions to 22 selector lines across the 6 input/select blocks. Documented as a recurring tax in CLAUDE.md hygiene rule #7 with a new prospective-port checklist (see CLAUDE.md C4-discoveries section).
- **Unblocks deletion:** Final Phase 3 commit, after C3.D + C4 + C5 + C6 + C7 + C8 + C9 + C12 each migrate their page's rules out. Also delete the second `wp_enqueue_style( 'eem-admin-legacy', … )` call in `EEM_Admin::enqueue_backend_shell_styles` + `EEM_Reservation_Editor::enqueue_editor_shell_styles`.
- **C13 remediation scope (revised — substantially larger than originally estimated):** rather than per-page `:not()` decoupling for every new component, C13 should **strip the entire form-control + button restyle stacks wholesale**. Six form-control blocks ÷ ~180 LOC each + fifteen button blocks ÷ ~250 LOC each ≈ **~430 LOC of legacy CSS** are pure `!important` cartels duplicating each other. Removing them outright (rather than piecemeal-excluding from each) is cleaner. Risk: any legacy admin screen still relying on the 44px form-control look will look slightly different after C13 — accepted, since by C13 every page is ported anyway.
- **Status:** active remediation in progress per Phase 3 chunks; full strip queued for C13

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

### 12. `.eem-page a` global anchor color rule — recurring specificity bully
- **What:** `assets/css/admin.css:172` sets `.eem-page a { color: var(--eem-electric); }` as a page-wide default. Specificity (0,1,1) wins against every class-level color rule (typically 0,1,0), forcing every anchor inside the EEM admin chrome to Electric Blue unless explicitly fought.
- **Why deferred:** Every new component with an anchor element has to chain `a.` on its color rules to win specificity. Documented regressions so far:
  - C4.B "+ New Reservation" button text invisible on electric-on-electric (hotfix `ab2fa05`)
  - C4-polish-1 status tabs forced to Electric Blue when mockup specifies gray
  - C4-polish-1 reservation title links blocked from Navy default per mockup
  Each was patched locally with `a.` chains. The recurring pattern is the real issue — every future component port (C5, C6, C7, C8...) will hit the same trap on first render.
- **Added in:** c4-polish-1 (issue identified across all of C4)
- **Unblocks deletion:** C13 polish pass. Two refactor candidates:
  - **(a) Remove the default `color` on `.eem-page a`** — require every component CSS to color its own anchors. Forces all current `a.` chains to become unnecessary; new components don't trip the trap. Risk: any unstyled anchor reverts to WP/browser default link blue, which differs from `--eem-electric`. Mitigation: sweep all anchors at audit time and ensure each has a class with explicit color.
  - **(b) Scope the rule** to `.eem-page a:not([class*="eem-"])` — applies only to anchors with no `eem-*` class. Cleverer but more brittle; depends on every component anchor having an `eem-*` class.
  - **Prefer (a)** — cleaner contract, even if it requires a one-time anchor-color audit.
- **Status:** awaiting C13

### 13. Search input + button visual attachment didn't fully land in C4
- **What:** The Reservations list search pair (`.eem-search-input` + `.eem-search-btn`) was supposed to render visually attached per mockup line 65 (input right corners squared, button left corners squared, button no left border, zero gap between them). After 4 rounds of polish (c4-polish-1 attached treatment, c4-polish-2 specificity bumps, round-3 source-order reorder, round-4 flex-gap fix), the gap between the two elements closed but the button's corner-radius and possibly its left border still don't render per spec.
- **Why deferred:** The cascade analysis points to my class rules at (0,3,0) winning over WP-core's (0,1,1) — they SHOULD apply. Live admin.css being served matches HEAD (verified via curl + grep). But empirically the visual still shows the button with all four corners rounded and a visible seam break. Diagnosis ran out of cheap leads; needed a DevTools cascade dump from Whitney to pin which rule is actually winning, and we chose to accept current state per the hard-stop rule and move on rather than keep guessing.
- **Symptoms still visible at c4-close:**
  - Button border-radius not asymmetric per `.eem-list-toolbar .eem-search-btn { border-radius: 0 4px 4px 0 }` rule (round-3 source-order reorder confirmed in commit 7bbcf5b but visual unchanged)
  - Possibly button left border still present despite `.eem-search-btn { border-left: none }` rule
  - Functionally: search input + button both work; clicking either submits the form. Visual-only defect.
- **Added in:** c4-polish-2 (Whitney accepted current state after 4 polish rounds)
- **Unblocks deletion:** C13 polish pass, OR sooner if it turns out to be downstream of the admin-legacy.css wholesale strip planned for C13 (entry #1). Worth a DevTools investigation when that strip lands — most likely a legacy `!important` block on `button` element that I missed in the c4-polish-2 form-control sweep (the sweep covered `input` + `select` + `textarea`, not `button`). Verify by inspecting the search button in DevTools after the wholesale strip; if the seam visibility improves, the legacy bare-button overrides were the cause.
- **Status:** accepted at c4-close; revisit in C13

### 10. Bulk Edit on Reservations list — handler returns "unsupported" notice
- **What:** The Reservations list bulk-action dropdown offers `Edit` and `Move to Trash` per RES-3. C4.D wires Move to Trash end-to-end; Edit currently redirects with `eem_notice=bulk_edit_unsupported` ("Bulk Edit is not available yet — it will land in a future release. Use the per-row Edit link for now.").
- **Why deferred:** WP-native bulk edit relies on `WP_List_Table`'s inline-edit machinery which the Phase 3 custom page (Path B) deliberately doesn't extend. A proper bulk-edit UX needs its own modal (similar to the Email Customers modal) with the fields-to-change form. Scope is meaningfully larger than C4.D could accommodate and the per-row Edit link covers the immediate use case.
- **Added in:** C4.D
- **Unblocks deletion:** Future chunk (likely C13 polish or a dedicated follow-up). Either ship a real bulk-edit modal, or remove the `Edit` option from the dropdown and the corresponding handler branch. Don't ship a "not available yet" notice to production.
- **Status:** awaiting decision

### 11. `orderby=orders` sort uses PHP two-pass instead of SQL
- **What:** `EEM_Reservations_List_Repo::get_paginated` honors `orderby=orders` by fetching up to 500 candidate posts, computing the orders count per post in PHP, sorting, then hand-paginating. SQL ORDER BY can't be used directly because the orders count is derived from `notes LIKE '%Reservation setup ID: N%'` across two tables.
- **Why deferred:** Works fine at the scale of a single venue's reservation list (typically <100 reservations). Becomes a problem if a producer has 500+ reservations or a multi-tenant deployment.
- **Added in:** C4.D
- **Unblocks deletion:** C11 (EMAIL-5 / order schema). When per-order rows gain a denormalized `reservation_id` column (per CLEANUP entry #9), the sort can become a proper SQL JOIN + COUNT + ORDER BY. Also drop the 500-row safety cap.
- **Status:** awaiting C11

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
