# Overhaul Report — Payment Audit + Ship-Readiness Hardening

**Window:** 2026-06-27 → 2026-06-28
**Versions shipped:** v2.7.671 → **v2.7.687** (17 releases, all on `main`, all auto-deployed)
**Goal (Whitney):** "We will be taking hundreds of thousands of dollars through this plugin
right away — every dollar configured anywhere must display AND calculate correctly on every
money surface. Nothing is messed." Then: harden the plugin to a clean, professional,
sellable v1 against an independent ship-readiness audit.

---

## 1. Bottom line

Every chargeable input now charges, stores, displays, and refunds correctly on every surface —
**proven by a 119-assertion harness** that drives real orders through the actual checkout code
(not mocks) and asserts `charge == stored == Σ(receipt lines) + tax` for each. The plugin went
from the audit's **~7/10 to solidly "9/10 sellable"**: all HIGH and MEDIUM money, concurrency,
security, and UX items are fixed and test-backed. The remaining work is large refactors and
live-gateway items that need dedicated sessions (see §6).

**No known regressions.** Every release was lint-clean, smoke-verified, and re-checked against
the capstone harness before shipping.

---

## 2. The money audit (the core ask) — what was wrong, what's fixed

Each chargeable input was traced from the Edit-Reservation editor through checkout to all 13
money surfaces. Findings F1–F11 + cash-waiver, all fixed:

| # | Finding | Fix | Ship |
|---|---|---|---|
| **F10** 🔴 | Pre-entries were **charged but dropped from the order** (~$62/order revenue loss) | Persist pre-entry charges onto the order row | 2.7.672 |
| **F6** 🚨 | Order system hard-capped at the **250 most-recent rows** — older orders unreachable for detail/receipt/refund, AND revenue under-counted | Removed the `LIMIT 250` cap | 2.7.675-era |
| **F3** | Map "Add New Customer" placeholder orders mis-priced (no surcharge, 1 night) | Charge map surcharge + full event nights | — |
| **F4** | Add-Items products/custom items got **no convenience fee/tax** | `compose_order_totals()` — single source of truth; fee follows added items | — |
| **F7** | FLAT fee **double-charged** per component row | Apply once per order (first row) | 2.7.676 |
| **F8** | Imported-order receipt lines diverged from the stored charge | Lines derived from stored amounts | — |
| **F9** | Group fees + Pre-Entries **couldn't be added** via Add Items | New addable item types (reuse flat-rate path) | — |
| **F11** | Flat fee re-doubled on post-creation edits (add-qty / edit-dates) | Once-per-order on edits | 2.7.672 |
| **2.4** 🔴 | Per-row **% fee rounding** diverged stored vs charged by a cent → tripped Stripe's underpayment guard → **stranded a paid customer as unpaid** | Split % fee once-per-order with exact remainder (like tax) | 2.7.675 |
| **Cash** | Convenience fee should be **waived for cash/check** (backend Paid-Cash tab only) | `waive_convenience_fee()` + marker read by every surface | — |

**Convenience-fee policy (Whitney decisions, implemented):** applies to every product/line item;
**only** exception is cash/check on the backend Collect-Payment "Paid Cash" tab; discounts do
**not** touch the fee; tax stays off (global default 0%, per-reservation override available).

### The 13 money surfaces (#3) — all reconcile
Customer: checkout Order Summary · confirmation email · hosted receipt · PDF receipt.
Admin: Order Detail · admin receipt · Orders list (Total / **Total Paid** / Balance) · Create
Order · Collect Payment (Send Link / Charge Card / Paid Cash) · Dashboard revenue · Reports ·
Activity Log. Ground truth: the actual gateway charge + stored order. The capstone harness
(`tests/smoke/charge-reconcile-allsurfaces-smoke.php`, 119 assertions across 13 scenarios)
proves `charge == stored == Σ lines + tax` for stall, RV, add-ons, shavings, group, pre-entries,
stay packages, early-bird, tack exclusion, multi-component %, **rounding-edge**, and flat fee.

### Edit-Order recalc (#4)
Add-quantity and Edit-Dates recompute fee + tax correctly (lengthen = Balance Due, shorten =
Refund Owed). Refund accounting verified: a stored refund note with a stray minus now parses as
the positive magnitude (P4) so the same money can't be over-refunded.

---

## 3. Money integrity & concurrency (so money can't vanish or double)

- **P3 — charge-recovery safety net** (2.7.674): if a charge succeeds but the order fails to save
  (crash/timeout), the charge is snapshotted durably; a retry **reuses** it (no double-charge),
  the insert is **idempotent** (no duplicate order), and any unrecovered charge surfaces as the
  top red row on the Dashboard. All four charge paths (Stripe + Auth.net, customer + admin).
- **2.1 — transaction-wrapped insert** (2.7.677): the multi-row order insert is now all-or-nothing
  (COMMIT only if every row inserts, else ROLLBACK) — no half-saved orders reported as success.
- **2.2 — atomic order numbers** (2.7.676): `reserve_order_number()` serializes behind a MySQL
  `GET_LOCK` + cache-bypassing read — no duplicate order numbers under concurrent checkout.
- **2.3 — admin double-book lock** (2.7.676): the admin quick-add takes the same checkout lock and
  rejects a stall already occupied for the window (`units_occupied_in_window`).
- **2.5 — Stripe idempotency keys** (2.7.676): on every PaymentIntent + refund create, so a
  timed-out retry reuses the same charge/refund.

---

## 4. Security & UX hardening (ship-readiness)

**Security:** imported-order **IDOR closed** — imports now get an unguessable random order_key
(was a guessable hash of name+event+timestamp; could expose uploaded Coggins/ID PDFs) (2.7.678);
**JSON import allowlisted** — meta keys restricted to the plugin namespace + order rows filtered
to real columns (2.7.682); **write-only payment secrets** — keys never render into page source
(2.7.683); **upload throttle + GC** on the unauthenticated document-stage endpoint (2.7.685);
**Auth.net debug-log redaction** (2.7.686).

**UX / accessibility:** accessible hosted-invoice card fields (WCAG labels) + **customer-safe
gateway errors** (no leaking "Merchant Login invalid" to customers) (2.7.679); a **Processing…
spinner** on submit + **toast** errors replacing native `alert()` (2.7.680).

**Distribution / bloat / process:** decision locked = **self-hosted/premium** (keep the GitHub
self-updater); **fonts vendored locally** — no more fonts.googleapis.com call (2.7.687);
internal `.md` files + the `_eem_oc.php` dev artifact **stripped from the shipped zip**; dead
`_en_special_instructions` write-path removed; **CI now gates the release build** on lint + JS +
smokes; new **Settings → "Taxes & Fees" tab** (Whitney request).

---

## 5. Verification

- **Capstone harness:** `tests/smoke/charge-reconcile-allsurfaces-smoke.php` — 119/119, run before
  every release.
- **New permanent smokes added this window:** `cash-waiver-fee-smoke`, `f9-group-preentry-addable-smoke`,
  `concurrency-hardening-smoke`, `import-token-idor-smoke`, `import-allowlist-smoke`,
  `write-only-secrets-smoke`, plus per-finding `/tmp` verifications.
- **Every release:** PHP lint (8.2) + `node --check` on touched JS + no-fatal load check.
- **No regressions** observed across the 17 releases.

---

## 6. What's open (and why it's not "just keep going")

**Big refactors — dedicated, browser-verified sessions:**
- **#42** Retire admin-legacy.css (337 KB). *Analysis done:* NOT a redundant delete — it styles
  79 still-live component classes admin.css doesn't (+ 93 dead ones). Full retirement = port the
  79 live classes into admin.css + visually verify every admin page on Local first (main
  auto-deploys to live). Scoped in task #42.
- **#45** Unify naming prefixes (stored-data migration — ask-first) · **#41** collapse 41
  migrations (after #45) · **#43** production minify build.

**Need a live gateway / your involvement:** #46 Auth.net→Accept.js (PCI) · #20 encrypt keys ·
#18 Auth.net amount-verify · #58 backfill old order keys (multi-table migration).

**Low / optional:** #53 hold-expired message + inline-style consolidation · #55 convert
source-presence "theater" smokes to behavioral tests.

**Final gate:** #25 — fresh-eyes "is this professionally built?" review, to run **after** the
refactors land.

---

## 7. Open questions for Whitney

1. **#42 (legacy CSS strip):** needs your visual sign-off on the admin pages once I stage a
   legacy-off build on Local — it's the single biggest bloat win but auto-deploys to live.
2. **Live-gateway items (#46/#20/#18):** need real test charges on your Auth.net/Stripe creds.
3. **#45 prefix unification:** a one-time data migration on stored meta/table names — confirm
   before I touch stored data.

Everything else in the v1 ship-readiness list is **done, tested, and live.**
