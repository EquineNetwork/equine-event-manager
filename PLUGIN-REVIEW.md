# Plugin Review — Equine Event Manager (fresh-eyes audit)

**Version reviewed:** 2.7.673
**Date:** 2026-06-28
**Method:** Independent code review treating the plugin as an unknown third-party download. The plugin's own internal docs (HEALTH-AND-V2-READINESS.md, CLEANUP.md, PAYMENT-CALC-AUDIT.md, CLAUDE.md) were treated as **untrusted** — claims were re-verified against the code, and discrepancies are flagged inline.
**Scope:** Plugin's own code only — `admin/`, `includes/`, `public/`, `templates/`, `assets/`. Excludes `vendor/`, `includes/plugin-update-checker/`, `tests/`.
**Size:** ~96.6k LOC PHP across 158 files; ~2.9 MB CSS/JS assets; 191 hand-rolled smoke scripts.

---

## 0. Executive verdict

A **genuinely capable, actively-maintained plugin that is better-engineered than its version number (673 patches) and Codex origin suggest.** It is *not* a textbook bloated-Codex plugin: dead code is low, cleanups are audit-trailed, security fundamentals are solid, and the money *storage* and charge/refund *error handling* are real.

The exposure is concentrated in three places: (1) a handful of **money/concurrency gaps around — not inside — the charge**, (2) **two 14–15k-line god-objects** plus a 337 KB legacy stylesheet that still ships, and (3) a **process/release pipeline** that auto-deploys `main` to production with effectively no test gate.

**Overall: ~7/10.** (Coincides with the team's own self-assessment, reached independently.)

**Single most important action item:** verify that the critical money fixes documented in PAYMENT-CALC-AUDIT.md as "fixed on Local, awaiting deploy" (esp. **F10 — pre-entries charged but dropped from the stored order, ~$62/order revenue shortfall**) are actually live. The live build was noted as running behind.

---

## 1. What's genuinely good (preserve — do not "fix")

| Area | Evidence |
|---|---|
| **No SQL injection** | All dynamic SQL parameterized via `$wpdb->prepare`; `IN (...)` clauses use generated `%d` placeholders + `absint` (shortcodes 6743, admin 14395); table names from `$wpdb->prefix`. |
| **Nonce + capability discipline** | Admin AJAX/admin-post handlers consistently pair `current_user_can()` with per-action (often per-order) nonces. Verified across ~30 handlers. |
| **Stripe webhook is correct** | HMAC-SHA256 signature verified with constant-time `hash_equals`, 5-min replay window, already-paid early return (`public/class-equine-event-manager-shortcodes.php:8747-8822`). |
| **Money storage** | All currency columns `decimal(10,2)` (activator.php:492-544); fee/tax rounded to 2dp at source; gateway charges convert to integer cents. No float-money bug. |
| **Charge/refund error handling** | Failed charges never mark paid (Stripe requires `status==='succeeded'`; Auth.net requires `responseCode==='1'` + `transId`). Refunds call gateway **before** local write, bail on `WP_Error` (refund-engine.php:201-204). |
| **WP best practices** | Conditional asset enqueue (EEM screens only, admin.php:368-412); 100% `equine-event-manager` text domain, zero drift; hardened dompdf (`isRemoteEnabled=false`); scoped transients; idempotent dbDelta with composite unique keys + indexes. |
| **Low dead code** | Only 6 TODO/FIXME markers; removed render methods carry "verified zero live callers" audit notes. Not a bloat mess. |
| **Lean dependencies** | composer: dompdf, emogrifier, choices only — all justified, all with graceful degradation if `vendor/` absent. |

**Discrepancy flagged:** HEALTH-AND-V2-READINESS.md claims the Stripe webhook "doesn't verify signature." **This is false** — verified independently in two separate reads. Correct the doc so the verification isn't mistaken for a missing feature.

---

## 2. Money & concurrency (TIER 1 — launch-blockers for concurrent traffic)

> The gateway charge itself is server-authoritative and correct. Every issue below is about what happens *around* the charge.

### 2.1 HIGH — Charge before order insert, no DB transaction
`process_payment_submission()` charges at `public/class-equine-event-manager-shortcodes.php:3260`, then `insert_reservation_orders()` runs N `$wpdb->insert()` calls **with no START TRANSACTION / ROLLBACK** (`:5322-5828`). Success is an OR-flag across inserts → a stall insert succeeding while RV insert fails returns `success=true` with a half-written order. The submission token is marked processed only **after** a successful insert (`:5733`), so charge-succeeded-but-insert-failed returns a generic "could not save" to an already-charged customer (`:3272`) **and a retry can charge again**.
**Fix:** wrap the multi-row insert + side effects in a transaction; mark the submission token processed immediately post-charge (before insert) so a retry can't re-charge.

### 2.2 HIGH — Non-atomic order-number allocation
`reserve_order_number()` does `get_option` then `update_option(n+1)` with no lock/atomic increment (`includes/class-equine-event-manager-orders-repository.php:2687-2722`). The checkout lock is keyed **per-reservation** (`eem_checkout_<id>`) while the counter is **global**; the admin placeholder path (`admin/class-equine-event-manager-admin.php:7231`) is entirely unlocked. → duplicate order numbers. **No unique constraint on `order_number`** to catch it.
**Fix:** atomic allocation (DB auto-increment side table, or `GET_LOCK` around the counter) + add a unique index on `order_number`.

### 2.3 HIGH — Admin stall-assignment path unguarded
The AJAX placeholder creator (`admin/class-equine-event-manager-admin.php:7188-7385`) takes **no advisory lock**, performs **no occupancy check** before assigning (`:7349, :7360`), and **never writes `eem_stall_status`**. → double-book against a concurrent customer checkout, or re-assign an occupied stall.
**Fix:** route the admin path through the same `GET_LOCK` + availability-recompute + status-table write used by the customer path.

### 2.4 MEDIUM — Per-row fee rounding diverges stored total from charged total
Gateway charges a single figure computed once: `total = subtotal + round(fee) + round(tax)` (`:4827, 4861`). But `insert_reservation_orders()` stores **multiple component rows**, recomputing the percentage fee **per row**, each independently rounded (`:5582`); `get_grouped_orders()` re-aggregates by summing the rounded rows (`:1957, 1992`). `Σ round(p·rowᵢ) ≠ round(p·Σrowᵢ)`. **Verified:** 3 × $12.30 @ 4% → charged 3838¢, stored 3837¢.
**Consequence (not cosmetic):** the Stripe webhook underpayment guard compares `amount_received` vs the stored total (`:8805-8822`). When `Σ rows > single-round charge`, `paid_cents < expected_cents` → webhook **returns without marking the order paid** → a correctly-charged customer is stuck unpaid + an `order_payment_amount_mismatch` log entry. Affects multi-component (stall+RV) / multi-stall orders under the **percentage** fee — which is the live config (4%).
**Fix:** split the fee once-per-order using the rounded-share + exact-remainder method tax already uses correctly (`:5396-5397`), or reconcile stored rows to the authoritative `$totals['total']`.

### 2.5 MEDIUM — No Stripe Idempotency-Key
PaymentIntent creation (`:7228, :8432, :9337`) and refund creation (`refund-engine.php:523`) omit `Idempotency-Key`. A timed-out create-then-retry can create a second PaymentIntent. Advisory locks + submission-token binding compensate on create-order paths, but the canonical Stripe protection is absent.
**Fix:** add a deterministic `Idempotency-Key` (e.g. submission token / order key) to all create calls. Small change, high value.

### 2.6 LOW — Refund local-persist-fail divergence
Gateway-refund-succeeds-but-`persist_component_refund()`-fails returns a `persist_failed` error (`refund-engine.php:207-211`) — surfaced to the operator (not silent), but a transient gateway/local drift requiring manual reconciliation.

### Verified clean (concurrency)
- **Customer double-booking:** serialized under `GET_LOCK('eem_checkout_<id>')` with fresh availability recompute inside the lock (`:3225-3270`). **Residual:** authoritative occupancy is parsed from order `notes` text (`:3942-4006`) rather than the `eem_stall_status` table, whose `UNIQUE KEY (reservation_id, stall_unit, night_date)` (stall-status-repo.php:73) goes unconsulted in validation.
- **Count vs body divergence:** FIXED — both list repos use the LEFT-JOIN/`IS NULL` orphan-safe pattern (reservations-list-repo.php:226-250).
- **Unit holds:** sound — `UNIQUE KEY` backstops TOCTOU, cron + opportunistic expiry.
- **Migrations/activation:** safe — 41 flag-gated, each idempotent, flag set after work; destructive DELETEs INNER-JOIN-restricted to backfilled postmeta; `uninstall.php` opt-in (data preserved by default). Minor fragility: migs 027/028/037 self-invoke at file scope relying on the activator to set their flag.

---

## 3. Security (TIER 2)

> No Critical findings. No SQL injection, no unauthenticated RCE, no secrets in code/logs, no PAN/CVV persistence.

### 3.1 HIGH — IDOR: unauthenticated document download via guessable `order_key`
`includes/class-eem-order-documents.php:593` (`ajax_download`, `nopriv` at :359) and `:565` (`ajax_upload`, `nopriv` at :357). Sole authorization is possession of `order_key` (`authorize()`, :532) — no login/capability. `order_key = md5(build_group_key(...))`; `build_group_key()` falls back (when no random submission token exists — orders-repository.php:2998-3013) to a hash of **low-entropy, partly attacker-known fields**: event id, customer name, email, phone, creation timestamp **to the second**. An attacker who knows a target's name/email/phone + event can brute-force the second-precision timestamp to reconstruct the key and **download uploaded identity/health PDFs (Coggins, IDs)** or attach files. Normal `[en_reservation]`-flow orders carry a strong 32-char token (safe); **admin-created / imported / legacy orders are the exposed set.** The shared `nopriv` nonce provides no real boundary.
**Fix:** guarantee a high-entropy random access token on every order; remove the deterministic fallback; order-scope the docs nonce.

### 3.2 MEDIUM — Authorize.net raw card data transits the server (PCI SAQ-D)
`public/class-equine-event-manager-shortcodes.php:7245-7270, 8958-8988` — direct-post (AIM): full PAN + CVV read from `$_POST` and posted to Auth.net. **Not stored, not logged** (verified — only masked responses logged under `WP_DEBUG`). Compliance/architecture concern, not a code leak. Stripe is fully tokenized (Elements).
**Fix/decision:** migrate Auth.net to Accept.js client-side tokenization to drop out of SAQ-D.

### 3.3 MEDIUM — JSON "Import Setup" writes arbitrary meta + DB rows, no allowlist
`admin/class-eem-import-handler.php:701-703, 718-724, 749-754` — blind `update_post_meta($id, $key, $val)` with attacker-controlled key+value; `:818, :834` — raw `$wpdb->insert()` of attacker-controlled order rows (can fabricate "paid" orders with arbitrary totals/transaction IDs). Gated to `manage_options` + nonce → requires a tricked admin or poisoned export file. Data-integrity/trust hole, not direct RCE.
**Fix:** allowlist meta keys; validate row columns/values.

### 3.4 LOW — Payment secrets reflected into settings HTML
`admin/class-eem-settings-page.php:721` (`render_credential_field`, called :606/608/614/630/632). Stripe secret/webhook keys + Auth.net transaction key render into `value="..."` (masked `type="password"` but verbatim in page source). `manage_options`-gated, recoverable via view-source.
**Fix:** write-only fields (blank render, keep stored value on empty submit — pattern already exists at shortcodes.php:9245).

### 3.5 LOW — Required-docs stored under webroot, `.htaccess`-only protection
`includes/class-eem-order-documents.php:90-96`. `wp-content/uploads/eem-required-docs/` protected by `.htaccess` (Apache-only) + random 20-char filenames. On **nginx** the dir is URL-reachable; safe only by filename obscurity. `.php` upload is blocked (extension allowlist + `wp_check_filetype_and_ext` — solid).
**Fix:** store outside webroot, or document the nginx caveat.

### 3.6 LOW — Other
- Plaintext payment credentials at rest in `equine_event_manager_payment_settings` wp_option (`autoload=false`). Normal for WP; note for backup/DB-exposure threat modeling.
- Unauthenticated upload DoS via `ajax_stage` (`order-documents.php:494`, `nopriv`): 10 MB files staged repeatedly, no rate limit, no GC of orphaned staged files.

### Verified clean (security)
REST permission callbacks per route (public `events`/`sheets` controllers are read-only + `post_status==='publish'`-gated); no `echo $_GET/$_POST`; no `unserialize()` on user data; customer-facing templates escape output.

---

## 4. Architecture & bloat (TIER 3)

### 4.1 Two god-objects = ~30% of all plugin PHP
- `admin/class-equine-event-manager-admin.php` — **15,344 lines, 288 functions, 18 AJAX handlers.** Despite rendering being extracted to page classes, still a catch-all: menu registration, settings constants, refund delegation, import, all AJAX. **#1 refactor target.**
- `public/class-equine-event-manager-shortcodes.php` — **14,060 lines, 332 functions.** Entire customer form + stall/RV pickers + cart-hold AJAX + payment submission + Stripe wrapper + webhook + receipt builders + the totals calculator. The §2.4 per-row-vs-single-total split is exactly the kind of subtle divergence this structure breeds.
- Other large: `events.php` (5,244), `reservations-cpt.php` (3,848), `orders-repository.php` (3,428), `order-detail-page.php` (3,225), `settings-page.php` (2,296).
- **No PSR-4 autoloader for own classes** — 75 manual `require_once` (composer autoload used for vendor only). Functionally fine; a maintenance smell at 158 files.

### 4.2 `admin-legacy.css` still ships on every admin page
- `admin.css` — 577 KB / 14,681 lines / 13 `!important` / 1,624 `.eem-` classes. Clean, tokenized.
- `admin-legacy.css` — 337 KB / 10,078 lines / **3,191 `!important`**. Self-described as "grandfathered, disappears at end of Phase 3," but **unconditionally enqueued** as a dependency of `eem-admin` (`admin.php:447`). Every admin page ships ~914 KB CSS, ~37% legacy. The `!important` war is fought, then un-fought with `:not()` exclusion chains — consuming much of CLAUDE.md.
- **Largest single piece of removable weight**, gated on finishing the page ports.
- `admin.js` mirrors this: 533 KB / 11,534-line monolithic bundle (loads conditionally; worth code-splitting).
- Good: `admin.css` ↔ `public.css` (144 KB) are properly separated (only 10 shared classes).

### 4.3 Naming drift — three meta prefixes + two table prefixes
- **Meta keys:** `_en_*` (legacy, 40+ keys), `_equine_event_manager_*` (native-events era), `_eem_*` (modern). Worst drift in the codebase.
- **Tables:** core ledgers use legacy `wp_en_*` (`wp_en_stall_reservations`, `wp_en_rv_reservations`, `wp_en_activity_log`); newer use `wp_eem_*`. The activator flags this as a pending "coordinated cleanup chunk" (activator.php:79).
- The CLAUDE.md convention table is itself self-contradictory (says `_en_` canonical but new code went `_eem_`). Unifying requires a data migration — which is why it hasn't happened.

### 4.4 Database — feature-rich, sane, but heavy
~23 custom tables; CPTs clean (`en_reservation`, `en_event`, `en_venue`, `en_producer`, `en_entry`). Concerns:
- **Dual storage during migration:** producers, division config, native events stored in **both** a table and postmeta with dual-read fallbacks. Drop-postmeta migrations exist but only run if the prior backfill succeeded — **a failed backfill leaves orphan meta forever with no detection.**
- **No foreign keys / cascades anywhere** — deleting a reservation/order orphans ledger rows.
- 3 separate tables just for stall check-in status (defensible audit trail, but complex).

### 4.5 Lower-stakes bloat
41 sequential one-time migration files (many now permanent no-ops on installed sites); 533 KB monolithic `admin.js`; an unusually large meta-documentation layer (CLAUDE.md ~102 KB, CLEANUP.md ~99 KB, plus multiple HANDOFF/AUDIT docs).

---

## 5. Tests, CI & release process (TIER 3 — the scariest *process* findings)

### 5.1 `main` auto-deploys to production with effectively no test gate
- `.github/workflows/ci.yml` runs on PRs / non-main branches: `php -l` syntax lint (PHP 7.4 + 8.2), `node --check`, and exactly **one** smoke (`stall-map-sanitize-smoke.php`). **190 of 191 smokes never run in CI.**
- The workflow explicitly uses `branches-ignore: [main]`, and `build-release.yml` auto-builds a release zip on every push to main. **No required check protects production** — a typo can ship live.
- phpcs.xml is thorough (WordPress + PHPCompatibility + custom prefix/text-domain rules) but **not enforced in CI**.
- `composer test` is **broken** — points at `tests/smoke/run-all.sh`, but the canonical runner is `tests/run-all-smokes.php`.

### 5.2 Test suite is ~half theater
- **Hand-rolled, no framework.** A copy-pasted `ok()` helper in every file; the runner greps a `=== RESULT: N passed ===` line. 191 non-helper smoke files.
- **~half are source-presence greps.** The largest (`tests/smoke/c7x-build-to-mockup-smoke.php`, 366 assertions) is **~68% `strpos()` on rendered HTML / admin.css / admin.js source**. 52 of 191 smokes grep stylesheet/JS source for class-name strings. The team's own CLAUDE.md is a graveyard of bugs these greps passed over (invisible modals, wrong computed values).
- **Real behavioral tests do exist and are good** — `c12-tax-persistence-smoke.php` (totals to the cent via the canonical consumer query), `charge-reconcile-allsurfaces-smoke.php` (110/110 end-to-end charge reconciliation). The money-path coverage is genuine.
- **Suite is not green.** Last cloud run: 3346 pass / 460 fail / 76 files ("almost all environmental" per the handoff — but never run green in CI).

### 5.3 Dev cruft ships in the production zip
- **`_eem_oc.php` ships to production.** Git-tracked, **unauthenticated web-callable** opcache-reset script at root (`opcache_reset(); @unlink(__FILE__)`). Not in `.gitignore`, not in `.gitattributes` export-ignore. Release zip is `git archive HEAD` → it bundles. **Remove from tracking + gitignore.**
- **5 internal dev docs ship to production** — `.gitattributes` export-ignore is stale: `CHARGE-CHECKLIST.md`, `FOR-REVIEW.md`, `HEALTH-AND-V2-READINESS.md`, `PAYMENT-CALC-AUDIT.md`, `SESSION-HANDOFF-*.md`. PAYMENT-CALC-AUDIT.md contains internal pricing/architecture detail. **Add to export-ignore.**
- **F1 (from PAYMENT-CALC-AUDIT.md):** production build ships without `tools/` (export-ignored) but `class-equine-event-manager.php:124` `require`s `tools/seed-demo-data.php` under WP_CLI → wp-cli fatal. **Guard with `file_exists()`.**

### 5.4 Accumulated-patch signal
No literal TODO/FIXME/HACK in own code, but 673 patches manifest as **dense version-stamped fix-comments** (75 `CLEANUP #` / `Fn` / `Cx.` / `SET-` references in the shortcodes file alone) — a signal of accumulated point-fixes rather than refactors.

---

## 6. Prioritized remediation backlog

| # | Severity | Item | Effort |
|---|---|---|---|
| 1 | **Verify-now** | Confirm PAYMENT-CALC-AUDIT fixes (esp. F10 pre-entries revenue shortfall) are actually deployed | Trivial |
| 2 | **HIGH** | Wrap charge→order-insert in a transaction; mark submission token processed pre-insert (§2.1) | Medium |
| 3 | **HIGH** | Atomic order-number allocation + unique constraint (§2.2) | Small |
| 4 | **HIGH** | Lock + occupancy-check + status-table write on admin stall path (§2.3) | Medium |
| 5 | **HIGH** | Document-download IDOR: high-entropy token, drop deterministic fallback (§3.1) | Medium |
| 6 | **MEDIUM** | Fee once-per-order split to stop stored↔charged ±1¢ drift / webhook unpaid wedge (§2.4) | Small |
| 7 | **MEDIUM** | Stripe Idempotency-Key on all create calls (§2.5) | Small |
| 8 | **MEDIUM** | Require a real test gate before main auto-deploy; fix `composer test` path (§5.1) | Small |
| 9 | **MEDIUM** | Auth.net → Accept.js tokenization to exit PCI SAQ-D (§3.2) | Large |
| 10 | **MEDIUM** | Import allowlist meta keys + validate rows (§3.3) | Medium |
| 11 | **LOW** | Remove `_eem_oc.php` from tracking; fix stale `.gitattributes` export-ignore; guard `tools/` require (§5.3) | Trivial |
| 12 | **LOW** | Write-only credential fields; docs dir nginx-safe; ajax_stage rate-limit + GC (§3.4–3.6) | Small |
| 13 | **DEBT** | Split the two god-objects; retire `admin-legacy.css`; unify meta/table prefixes (migration) (§4) | Large |
| 14 | **DEBT** | Convert source-presence smokes to behavioral tests; run full suite in CI (§5.2) | Large |

---

## 7. Doc discrepancies found (fresh-eyes vs. internal docs)

1. **HEALTH-AND-V2-READINESS.md: "Stripe webhook doesn't verify signature" — FALSE.** Verified twice independently; it does (HMAC + `hash_equals` + replay window).
2. **PAYMENT-CALC-AUDIT.md fixes "awaiting deploy"** — confirm against the running build; the live site was noted as running behind.
3. Several CLEANUP.md "awaiting CX" statuses are stale/resolved-by-completion (the doc itself notes this).
