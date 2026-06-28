# Ship-Readiness Audit — Equine Event Manager

**Goal of this document:** answer a single question — *"What would we need to fix, improve, or remove before we can sell this plugin to paying customers (incl. listing on WordPress.org) as a clean, sleek, professional product?"*

**Version audited:** 2.7.673
**Date:** 2026-06-28
**Method:** Independent, fresh-eyes review treating the codebase as an unknown download. Seven parallel investigations — security, money/concurrency correctness, architecture/bloat, tests/CI/process, WordPress.org distribution compliance, concrete delete/cleanup inventory, and front-end/UX professionalism. The plugin's own internal docs (HEALTH, CLEANUP, PAYMENT-CALC-AUDIT, CLAUDE.md) were treated as **untrusted** and re-verified; corrections are flagged inline.
**Supersedes:** `PLUGIN-REVIEW.md` (this document absorbs and extends it).
**Audience:** the plugin audit chat — each item is written to drop straight onto a to-do list.

---

## 0. Bottom line

**This is a genuinely good, professionally-built plugin — not a bloated Codex mess.** Dead code is low and already cleaned, security fundamentals are solid, the customer-facing checkout/email/PDF are polished and accessible, money *storage* is correct, and every bundled library is GPL-compatible. A reviewer downloading it cold would think "this is competent."

**But it is NOT sellable-as-is.** The gap to "list it next week" is concentrated and well-defined:

- **4 hard WordPress.org blockers** (no `readme.txt`, a forbidden self-updater, remote Google Fonts, and a web-callable dev script in the zip).
- **5 money/concurrency hardening items** that matter the moment two customers check out at once.
- **A genuinely free ~3,200-LOC + ~340 KB-per-page slim-down** (collapse one-time migrations, retire the legacy stylesheet, minify, strip leaked dev files).
- **A short list of UX polish items** that separate "professional" from "amateur."

**Overall today: ~7/10. Achievable for sale: ~9/10 with the Tier 1–3 work below.** None of the blockers except removing the self-updater carry real engineering risk.

**The single highest-value architectural fact:** the plugin is currently architected to auto-update itself from your private GitHub `main` branch. That mechanism is *incompatible* with WordPress.org hosting and must be removed for a .org listing. Decide early whether you're selling **via WordPress.org** (remove the updater) or **as a self-hosted/premium download** (keep it). That one decision reshapes the rest of the list.

---

## TIER 1 — Blockers to list/sell (do these first)

### 1.1 — No `readme.txt` (WordPress.org REQUIRES it) · effort: M
Only `README.md` exists; there is **no `readme.txt` anywhere**. WordPress.org requires a `readme.txt` with the exact header block (`Contributors`, `Tags`, `Requires at least`, `Tested up to`, `Stable tag`, `License`, `License URI`) and `== Description ==` / `== Installation ==` / `== FAQ ==` / `== Changelog ==` / `== Screenshots ==` sections, validated by the [.org readme validator](https://wordpress.org/plugins/developers/readme-validator/).
**Action:** author `readme.txt` from scratch. `Stable tag` must exactly equal the main-file `Version`. Include the external-services disclosure (1.4) and bundled-library licenses (8.x).

### 1.2 — Bundled self-updater is forbidden on .org · effort: M (the only real eng work in Tier 1)
`includes/plugin-update-checker/` (YahnisElsts PUC v5p7) is wired in `includes/class-eem-updater.php` — `EEM_Updater::init()` → `PucFactory::buildUpdateChecker()` tracking the `main` branch of `github.com/EquineNetwork/equine-event-manager` (`REPO_URL` line 41, `setBranch('main')` line 77). Boot call at `equine-event-manager.php:67` (include at :52). The main file also carries **`Update URI: false`** (line 16), which tells WordPress core to *ignore* .org's updater for this slug — the opposite of what a .org-hosted plugin needs.
**Action (if listing on .org):** delete `includes/plugin-update-checker/`, delete `class-eem-updater.php` and its include/boot calls, and **remove the `Update URI: false` header**. (PUC's license is MIT — fine; the problem is the guideline, not the license.)
**Action (if selling self-hosted/premium instead):** keep it, but this rules out a .org listing. **← decision needed.**

### 1.3 — `_eem_oc.php` ships to every customer · effort: XS (do today)
```php
<?php if(function_exists('opcache_reset')){opcache_reset();} @unlink(__FILE__); echo 'ok';
```
Git-tracked at repo root, **not** in `.gitattributes` export-ignore, so it lands in `wp-content/plugins/` for every install. It is an unauthenticated, web-reachable script with no `ABSPATH` guard that performs a side effect and self-deletes — to a .org reviewer this reads exactly like a backdoor and is a guaranteed rejection.
**Action:** `git rm _eem_oc.php` and add it to `.gitignore`.

### 1.4 — Remote Google Fonts (privacy / "calling home") · effort: S
IBM Plex Sans is loaded from `fonts.googleapis.com` in 6 shipped locations:
`admin/class-equine-event-manager-admin.php:435` · `includes/class-equine-event-manager-events.php:2857` · `admin/class-eem-reports-page.php:567` · `admin/class-eem-daily-movement-page.php:281` · `includes/class-eem-entries.php:1485` · `templates/receipt/receipt.php:38`.
The .org "Calling Home" guideline + GDPR (EU Google-Fonts rulings) require web fonts to be **bundled locally**. The three `class-...:567/281/1485` cases also inject raw `<link>` tags instead of using `wp_enqueue_style`.
**Action:** download IBM Plex Sans into `assets/fonts/`, serve via local `@font-face`, and route the three hardcoded `<link>`s through `wp_enqueue_style`. (The `receipt.php` PDF template may keep an inline `@font-face` but must point at the local file.)

---

## TIER 2 — Money & data integrity (hardening before real concurrent traffic)

> The gateway charge itself is server-authoritative and correct, and failed charges never mark an order paid. Every item below is about what happens *around* the charge. These are standard payment-system hardening, not exotic bugs.

### 2.1 — HIGH · Charge happens before order insert, no DB transaction · effort: M
`process_payment_submission()` charges at `public/class-equine-event-manager-shortcodes.php:3260`, then `insert_reservation_orders()` runs N `$wpdb->insert()` calls with **no transaction/rollback** (`:5322-5828`). Success is an OR-flag across inserts → a partial write returns `success=true`. The submission token is marked processed only *after* a successful insert (`:5733`), so a charge-succeeded-but-insert-failed case shows the customer "could not save" **and a retry can charge them again.**
**Action:** wrap the multi-row insert + side effects in a transaction; mark the submission token processed immediately post-charge.

### 2.2 — HIGH · Non-atomic order-number allocation · effort: S
`reserve_order_number()` does `get_option` → `update_option(n+1)` with no lock (`includes/class-equine-event-manager-orders-repository.php:2687-2722`). The checkout lock is per-reservation but the counter is global, and the admin placeholder path (`admin/class-equine-event-manager-admin.php:7231`) is unlocked → duplicate order numbers, with **no unique constraint** to catch it.
**Action:** atomic allocation (DB auto-increment side table or `GET_LOCK` around the counter) + a unique index on `order_number`.

### 2.3 — HIGH · Admin "add customer to stall" path is unguarded · effort: M
The AJAX placeholder creator (`admin/class-equine-event-manager-admin.php:7188-7385`) takes no advisory lock, does no occupancy check before assigning (`:7349, :7360`), and never writes `eem_stall_status` → can double-book against a live customer checkout or re-assign an occupied stall.
**Action:** route it through the same `GET_LOCK` + availability-recompute + status-table write the customer path uses.

### 2.4 — MEDIUM · Per-row fee rounding diverges stored total from charged total · effort: S
The gateway charges one figure (`round` of the sum), but the order is stored as multiple component rows that each recompute the percentage fee and round independently (`:5582`); `get_grouped_orders()` re-sums the rounded rows. `Σ round(p·rowᵢ) ≠ round(p·Σrowᵢ)`. **Verified:** 3 × $12.30 @ 4% → charged 3838¢, stored 3837¢. This trips the Stripe webhook underpayment guard (`:8805-8822`), which can leave a correctly-charged customer **stuck marked unpaid**. Affects the live 4% percentage config.
**Action:** split the fee once-per-order using the rounded-share + exact-remainder method tax already uses correctly (`:5396-5397`).

### 2.5 — MEDIUM · No Stripe Idempotency-Key · effort: S
PaymentIntent creation (`:7228, :8432, :9337`) and refund creation (`refund-engine.php:523`) omit `Idempotency-Key`. A timed-out create-then-retry can create a second PaymentIntent.
**Action:** add a deterministic `Idempotency-Key` (submission token / order key) to all create calls.

### 2.6 — VERIFY-NOW · Confirm the documented money fixes are actually deployed · effort: XS
`PAYMENT-CALC-AUDIT.md` documents critical fixes as "fixed on Local, awaiting deploy," notably **F10 — pre-entries charged to the customer but dropped from the stored order (~$62/order revenue shortfall)**, plus F3/F4/F11. The live build was noted as running behind.
**Action:** confirm these are in the build that ships to customers. *(Per owner: no live customers yet and the audit chat is merging fixes to `main` — so this is a checklist item, not an active fire.)*

---

## TIER 3 — Bloat removal & slim-down (the "don't look bloated" work — mostly free wins)

### 3.1 — Strip dev files leaking into the zip · effort: XS
`.gitattributes` export-ignore correctly strips `tests/`, `tools/`, `scripts/`, `.mockups/`, `docs/`, `.github/`, `CLAUDE.md`, `CLEANUP.md`, `README.md`, `ROADMAP.md`, `composer.*`, `phpcs.xml` (verified via real `git archive`). But these are **not** stripped and ship to customers: `_eem_oc.php` (delete — see 1.3), `HEALTH-AND-V2-READINESS.md`, `PAYMENT-CALC-AUDIT.md` (38 KB, internal pricing detail), `FOR-REVIEW.md`, `CHARGE-CHECKLIST.md`, `SESSION-HANDOFF-2026-06-27.md`, and **`PLUGIN-REVIEW.md` / this `SHIP-READINESS-AUDIT.md`**.
**Action:** delete `_eem_oc.php` outright (see 1.3). For the internal `.md` docs, **the audit chat decides** between three handlings — none of these reach a customer either way, the difference is repo cleanliness:
  - (a) **Delete from repo entirely** — removes all internal docs including `CLAUDE.md`/`CLEANUP.md`/`ROADMAP.md`. Note: `CLAUDE.md` drives how future Claude sessions behave; deleting it changes that.
  - (b) **Just stop them shipping** — add a `/*.md export-ignore` rule (with re-includes for any runtime-needed MD) so none reach an install, keep them in the repo for reference.
  - (c) **Hybrid** — export-ignore the keepers (`CLAUDE`/`CLEANUP`/`ROADMAP`), delete the transient/dated ones (`HEALTH`, `PAYMENT-CALC-AUDIT`, `FOR-REVIEW`, `CHARGE-CHECKLIST`, `SESSION-HANDOFF`).
  Whichever is chosen, **~99 KB comes off the shipped artifact** and the web-callable script is gone.

### 3.2 — Collapse the 41 one-time migrations · effort: M · saves ~3,145 LOC
`includes/migrations/eem-mig-001..041` = **2,965 LOC**, plus a ~180-LOC runner in `class-equine-event-manager-activator.php:230-410`. **All 41 are no-ops on a fresh install** — `dbDelta` already creates every table in final shape, and there's no legacy data to backfill/drop. They exist solely to upgrade the one existing production site.
**Action:** ship them in one final "legacy upgrade" tag, then in the for-sale baseline delete all 41 + the runner and gate on a single `eem_db_version` baseline. (Or keep one consolidated `eem-mig-baseline.php` → still ~2,700 LOC removed.) **Do this *after* the prefix unification (3.6) so the baseline ships canonical names.**

### 3.3 — Retire `admin-legacy.css` · effort: L · saves ~337 KB/page + 3,191 `!important`
`assets/css/admin-legacy.css` (337 KB, **3,191 `!important`**) is still enqueued on every plugin admin page (`admin/class-equine-event-manager-admin.php:447`), despite its own header saying it's deleted at end of Phase 3. It's the source of the documented "two stylesheets fighting" cascade bugs (form-control radius, button restyle, select min-height). This is the existing CLEANUP #1/#25 "wholesale strip."
**Action:** finish moving owned classes into `admin.css`, then delete the legacy file. Removes the largest single piece of dead weight and ends the `:not()`-chain specificity war.
**Correction to internal docs:** `admin.css` is actually **larger (577 KB)** than the legacy file — both load together (**914 KB of admin CSS per page**), and neither is minified.

### 3.4 — Add a production minification build step · effort: M · ~halves asset transfer
Nothing in the plugin is minified (only the vendored `choices.min.js`). Raw `admin.css` (577 KB) + `admin-legacy.css` (337 KB) + `admin.js` (533 KB) ≈ **1.05 MB of uncompressed admin assets per page**.
**Action:** add a build step that emits `*.min.css`/`*.min.js` and enqueue the minified versions in production (`SCRIPT_DEBUG` toggle for source). Consider code-splitting the 533 KB monolithic `admin.js`.

### 3.5 — Remove confirmed dead write-path · effort: XS
`_en_special_instructions` is written at `admin/class-equine-event-manager-admin.php:7138` (`ajax_special_instructions_set`, hooked :131) and **never read anywhere**.
**Action:** either wire a reader or remove the write + AJAX handler (~15 LOC). Also inline the 4 thin `format_order_number_display()` wrapper shims (`collect-payment-page.php:661`, `orders-list-page.php:802`, `admin.php:5954`, `order-detail-page.php:3137`) to direct `EEM_Formatter::` calls (~25 LOC cosmetic).
**Correction:** the HEALTH doc's "22 dead methods + 2 functions" were **already deleted** in 2.7.669 (−1,089 LOC); spot-checking 15 current private methods found all live. Mark `HEALTH-AND-V2-READINESS.md` superseded so nobody re-chases completed work.

### 3.5b — OUTSTANDING: run a full exhaustive dead-code sweep · effort: M
**This audit did NOT prove the codebase is 100% dead-code-free.** What it established: the *previously-known* dead code (the "22 methods") is gone, a 15-method sample of the current private surface is all live, and one dead write-path remains (3.5). It did **not** exhaustively check every function, method, hook callback, post-meta key, option, JS function, CSS class, or file for reachability across all ~98k LOC. To honestly claim "all dead/unused code removed," a dedicated sweep is still required:
- **PHP:** every `private`/`protected` method and standalone function grepped for a caller; every `add_action`/`add_filter` callback confirmed to exist and fire; every `register_*` confirmed used.
- **Data:** every post-meta key and `wp_option` checked for both a writer *and* a reader (write-only = dead, like `_en_special_instructions`); orphaned custom-table columns.
- **Assets:** every `.eem-*` CSS class checked for a rendering site; every JS function/`data-eem-action` checked for a binding; any never-enqueued asset file.
- **Files:** any `includes/`/`admin/`/`public/` file never `require`d/autoloaded.
**Action:** the audit chat should run this sweep (or commission a follow-up agent pass) and remove what it confirms dead, verifying each removal with a whole-project reference search first.

### 3.6 — Unify naming prefixes · effort: L · **ASK-FIRST (touches stored data)**
Post-meta: `_en_` (59 keys, canonical) vs `_equine_event_manager_` (~25, drift) vs `_eem_` (7 real keys, drift — note most `_eem_*` grep hits are nonce names, not meta). Tables: `wp_eem_` (15, canonical) vs `wp_en_` (5: `en_activity_log`, `en_order_adjustments`, `en_report_exports`, `en_rv_reservations`, `en_stall_reservations`).
**Action:** one consolidated rename migration — ~32 meta keys → `_en_`, 5 tables → `wp_eem_` — plus a reference sweep. Requires owner sign-off and one-time migration code. Do it **before** 3.2 so the baseline schema is canonical and no rename migration ever ships to customers.

---

## TIER 4 — Security follow-ups (no Critical findings; these harden before sale)

### 4.1 — HIGH · Document-download IDOR · effort: M
`includes/class-eem-order-documents.php:593` (`ajax_download`, `nopriv`) + `:565` (`ajax_upload`, `nopriv`). Sole auth is possession of `order_key = md5(build_group_key(...))`, which for admin-created/imported/legacy orders falls back to a hash of *guessable* fields — event id, customer name, email, phone, creation timestamp **to the second** (`orders-repository.php:2998-3013`). An attacker who knows a target's name/email/phone + event can brute-force the second and **download uploaded identity/health PDFs (Coggins, IDs)** or attach files. Normal `[en_reservation]` orders carry a strong 32-char token (safe).
**Action:** guarantee a high-entropy random access token on *every* order; drop the deterministic fallback; order-scope the docs nonce.

### 4.2 — MEDIUM · Authorize.net raw card data transits the server (PCI SAQ-D) · effort: L
`public/class-equine-event-manager-shortcodes.php:7245-7270, 8958-8988` — direct-post AIM reads full PAN + CVV from `$_POST`. Not stored/logged (verified), but it puts the merchant in the strictest PCI scope. Stripe is fully tokenized (Elements).
**Action:** migrate Auth.net to Accept.js client-side tokenization to drop out of SAQ-D.

### 4.3 — MEDIUM · JSON Import trusts the file · effort: M
`admin/class-eem-import-handler.php:701-754` does blind `update_post_meta($id, $key, $val)` with attacker-controlled keys/values; `:818, :834` raw-insert order rows (can fabricate "paid" orders with arbitrary totals/transaction IDs). Admin+nonce gated, so it needs a tricked admin or poisoned file.
**Action:** allowlist meta keys; validate row columns/values.

### 4.4 — LOW · Smaller hardening · effort: S each
- Payment secrets render into settings HTML `value="..."` (masked field, but in page source) — `class-eem-settings-page.php:721`. Make them write-only (pattern exists at `shortcodes.php:9245`).
- Required-docs stored under webroot with `.htaccess`-only (Apache) protection — `order-documents.php:90-96`. URL-reachable on nginx. Store outside webroot or document the caveat. (`.php` upload is correctly blocked.)
- Unauthenticated upload DoS via `ajax_stage` (`:494`, `nopriv`) — 10 MB files, no rate limit, no GC of orphaned staged files. Add throttling + cleanup.
- Payment credentials stored plaintext in a wp_option (`autoload=false`). Normal for WP; note for threat modeling.

---

## TIER 5 — UX / professionalism polish (the "professional vs amateur" tells)

> The customer checkout, confirmation email, and PDF receipt already read as professional — vanilla JS (no jQuery), curated error copy, excellent i18n (457 `__()` calls), above-average accessibility, no placeholder/lorem/debug cruft. These are the gaps that would chip at that impression.

### 5.1 — HIGH · Unlabeled card fields on the hosted invoice page · effort: XS
`shortcodes.php:~7459-7463` renders `placeholder="Card Number"/MM/YYYY/CVV` with **no `<label>` or `aria-label`** — a WCAG failure (the in-form card fields are correctly labeled; only the hosted variant regresses).
**Action:** add proper labels/aria-labels.

### 5.2 — HIGH · Authorize.net gateway errors shown verbatim to customers · effort: S
`shortcodes.php:9041-9049, 9198-9217` pass gateway messages through directly — a config error like "Merchant Login ID or Password is invalid" can surface to a paying customer.
**Action:** whitelist card-decline codes; show a generic "payment couldn't be processed" for everything else.

### 5.3 — MEDIUM · No "Processing…" spinner on the main submit · effort: S
The reservation submit button is only `disabled`-toggled (`:~13942`); on a slow connection the customer sees a dead greyed button during the charge — looks frozen.
**Action:** add a spinner/processing state.

### 5.4 — MEDIUM · `alert()`-based error UX in admin · effort: S
`assets/js/venues.js` (7×, e.g. `:98, :417, :420` "Operation failed.") and `venue-layouts.js` (2×) use native `alert()` — jarring and dated vs. the toast-driven admin, and the strings are **hardcoded English, not translatable**.
**Action:** replace with the existing toast pattern; wrap strings in `__()`.

### 5.5 — LOW · Misc polish · effort: S
- No customer-visible "your hold expired" message (functionally safe, but a generic rejection instead of an explanation).
- Substantive inline styles pasted 4× on the hosted invoice/success pages (`:~7415-7559`) with hardcoded hex duplicating brand tokens — consolidate.
- The ~20 `console.warn` calls in `admin.js` are guarded and diagnostic — **acceptable, leave them.**

---

## TIER 6 — Process / release engineering (so quality holds after sale)

### 6.1 — `main` auto-deploys with effectively no test gate · effort: M
`.github/workflows/ci.yml` runs only `php -l` + `node --check` + **one** of 191 smokes, and explicitly uses `branches-ignore: [main]` while `build-release.yml` auto-builds a release zip on push to main. **No required check protects production.**
**Action:** require a real test job before the release build; fix the broken `composer test` path (points at `tests/smoke/run-all.sh`; canonical runner is `tests/run-all-smokes.php`); enforce `phpcs` (the ruleset is thorough but not run).

### 6.2 — Test suite is ~half theater · effort: L (longer-term)
191 hand-rolled smoke scripts (no PHPUnit). The largest (`c7x-build-to-mockup-smoke.php`, 366 assertions) is ~68% `strpos()` source-presence greps; 52 of 191 grep stylesheet/JS source for class names — these pass whether or not the feature works (the team's own CLAUDE.md is a graveyard of bugs they missed). The money-path tests, by contrast, are genuinely good (cent-accurate, DB round-trips). Last cloud run: 3346 pass / 460 fail / 76 files — never green in CI.
**Action:** convert the highest-value source-presence smokes to behavioral tests; get the full suite green and run it in CI. Lower urgency than Tiers 1–3 but important for long-term dependability.

### 6.3 — Guard the `tools/` require under WP-CLI · effort: XS
`includes/class-equine-event-manager.php:124` `require`s `tools/seed-demo-data.php`, but `tools/` is export-ignored from the build → wp-cli fatals on a production install (PAYMENT-CALC-AUDIT F1).
**Action:** wrap in `file_exists()`.

---

## 7. What's genuinely good — preserve, don't "fix"

- **No SQL injection** anywhere — queries consistently parameterized; table names from `$wpdb->prefix`.
- **Solid nonce + capability discipline** across ~30 admin AJAX handlers (capability *then* nonce).
- **Stripe webhook is correctly verified** — HMAC-SHA256, constant-time `hash_equals`, 5-min replay window (`shortcodes.php:8747`). **Correction:** HEALTH-AND-V2-READINESS.md claims it doesn't verify — that claim is **false** (confirmed by two independent reads).
- **Money storage is correct** — all `decimal(10,2)`, no float-money bug, integer-cent gateway conversion.
- **Charge/refund error handling is real** — failed charges never mark paid; refunds hit the gateway before local write and bail cleanly; Auth.net refund path self-heals gateway quirks.
- **Customer-facing UX is professional** — accessible, localized, curated errors, no placeholder/debug cruft; email CSS inlined via Emogrifier.
- **All bundled libraries are GPL-compatible** — dompdf (LGPL-2.1), php-svg-lib/php-font-lib (LGPL-3/2.1), css-parser/html5/emogrifier/symfony/choices.js (MIT). Choices.js is bundled locally. `composer.json` license is GPL-2.0-or-later. No obfuscation, no hardcoded credentials, no user creation, no core modification.
- **Clean naming/trademark** — no "WordPress" in the slug; Stripe/Authorize.net used only as integration descriptors.
- **Low dead code, audit-trailed cleanups, lean dependency footprint, conditional asset enqueue, 100% consistent i18n text domain.**

---

## 8. Corrections to the team's own docs (found by re-verifying)

1. **Stripe webhook DOES verify its signature** — contrary to HEALTH-AND-V2-READINESS.md (§7 above).
2. **The "22 dead methods + 2 functions" were already deleted** in 2.7.669 — HEALTH doc is stale; mark superseded (3.5).
3. **`admin.css` (577 KB) is larger than `admin-legacy.css` (337 KB)** — internal docs imply the legacy file is the heavy one; both ship, unminified, totaling 914 KB/page (3.3).
4. **The dead-code and order-number-dedup cleanups the docs flag as outstanding are essentially done** — don't re-chase them.

---

## 9. Recommended sequencing for "sellable next week"

1. **Decide the distribution model** (WordPress.org vs self-hosted/premium) — this gates 1.2.
2. **Tier 1** (readme.txt, remove/keep updater, delete `_eem_oc.php`, local fonts) — the listing blockers.
3. **Tier 3.1 + 3.5 + 6.3** — the trivial slim-down/strip wins (an afternoon).
4. **Tier 2** (transaction wrap, atomic order numbers, admin stall lock, fee rounding, idempotency key) + **Tier 4.1** (docs IDOR) — the correctness/security must-fixes; confirm 2.6.
5. **Tier 5.1–5.4** — the visible UX polish.
6. **Tier 3.6 → 3.2 → 3.3 → 3.4** — the larger slim-down (prefix unify, migration collapse, legacy CSS retire, minify), in that order.
7. **Tier 6.1** — lock down the release pipeline so it stays clean.
8. **Tier 4.2/4.3, Tier 6.2** — fast-follows after launch.
