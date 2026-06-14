# Security Audit Report — Equine Event Manager

**Audience:** Equine Network dev + ops team. This is the reference for questions about the
financial security of the plugin.
**Scope:** the money path end-to-end (charge, refund, amount integrity, idempotency, authorization,
webhooks, data exposure) plus a pointer to the companion inventory/oversell audit.
**Audit point:** v2.7.283 · 2026-06-14 · branch `v4-stall-mapping`. Security is point-in-time; re-run
the smokes (below) after any change to checkout, payment, refund, or order code.
**Companion doc:** [`INVENTORY-CONCURRENCY-REPORT.md`](INVENTORY-CONCURRENCY-REPORT.md) — the deep dive
on oversell / double-booking under load. This report consolidates the security-relevant findings from
both passes.

---

## 1. Threat model — what we are defending

The plugin takes real money for stall / RV / entry reservations through **Stripe** and
**Authorize.net** (Auth.net is the launch processor; Stripe is built + tested but unused at launch).
The assets to protect, in priority order:

1. **Customer's card / money** — never double-charge, never charge the wrong amount, never expose card
   data.
2. **The business's inventory** — never oversell / double-book a stall, lot, or entry spot (covered in
   the companion report).
3. **The business's money** — never over-refund, never refund what wasn't captured, never let an
   unauthorized user move money.
4. **Audit trail** — every money movement is logged with amount + actor.

Adversaries considered: a malicious/curious *customer* (tampering with prices, replaying requests,
IDOR on other orders), a *lower-privileged WP user* (reaching admin money endpoints), a *network
attacker* (forged webhooks/replays), and *accidental concurrency* (double-clicks, retries, two admins).

---

## 2. Methodology

Two independent read-only audit passes (inventory/concurrency and financial), each enumerating every
write path, tracing the charged amount from source to gateway, and checking authorization, idempotency,
and replay on each endpoint. Findings were graded CRITICAL / HIGH / MEDIUM / LOW. Safe, non-behavioral
fixes (locking, conflict re-checks) were applied immediately with regression smokes; changes that alter
charge/refund *behavior* or require a data migration are listed as recommendations, to be done as
single, verified changes.

---

## 3. Posture summary

**The transactional core is sound.** Every charge amount is recomputed server-side (no client-trusted
prices), every admin money endpoint is capability + nonce gated, refunds cannot exceed what was
captured, webhooks are signature-verified with an amount re-check, and no raw card data is ever logged
or stored. Two genuine double-charge gaps on the **launch processor (Auth.net)** were found; **one
(invoice path) is fixed in this pass**, the **other (main-checkout replay) is documented and pending a
verified payment-code change**. Inventory oversell is prevented by per-reservation advisory locks on
every write path, with two admin gaps fixed this pass.

---

## 4. Findings register

Status legend: ✅ fixed this pass · 📝 documented, pending go-ahead · ✔️ verified still-holding (prior
fix) · ⚠️ accepted risk.

| ID | Sev | Status | Finding | Where | Notes / fix |
|---|---|---|---|---|---|
| **F1** | HIGH | ✅ **fixed 2.7.283** | Hosted-invoice payment path charged with **no lock + no dedup** → concurrent/replayed invoice POSTs could double-charge (launch processor). | `shortcodes.php` `handle_invoice_payment_submission` | Wrapped in a per-invoice `eem_invoice_<md5>` advisory lock with an in-lock fresh payable re-read before charge; released in `finally`. Smoke `invoice-payment-lock-smoke.php`. |
| **MED-1** | MEDIUM | ✅ **fixed 2.7.284** | Main `[en_reservation]` checkout fired the Auth.net charge *before* the duplicate-submission-token check (`charge` at `shortcodes.php:2353`, dedup inside insert at `:3799`). A replayed submission created no duplicate *order* but could fire a second *charge*. Stripe unaffected. | `shortcodes.php` `handle_reservation_submission` | Now checks `has_processed_submission_token()` ahead of `process_payment_submission()`, under the same checkout lock, returning the identical duplicate-success shape (confirmation render unchanged). Token only marks-processed on success, so retries after a decline still charge. Smoke `checkout-replay-guard-smoke.php`. |
| **MED-2** | MEDIUM | ✅ **fixed 2.7.282** | Order Detail manual assignment override wrote admin-chosen stalls/lots with **no cross-order conflict check** → two admins could double-book a unit. | `admin.php` `handle_update_order_assignments` | Added `find_assignment_conflict()` run inside the per-reservation lock; rejects any unit already assigned to another order. Smoke `assignment-conflict-smoke.php`. |
| **LOW-1** | LOW | ✅ **fixed 2.7.282** | Two admin assign paths ignored the lock-acquire return (fail *open* on a 15s timeout). | `admin.php` `handle_update_order_assignments`, `handle_generate_stall_assignments` | Now refuse with a notice when the lock can't be acquired. |
| **LOW-2** | LOW | 📝 pending | No DB-level **UNIQUE** backstop: stall/RV assignments live in free-text order `notes`; the entry ledger (`wp_eem_division_entries`) has only non-unique keys. The advisory lock is the sole guard. | `class-eem-division-entries.php:60-73` etc. | Notes→table migration + `UNIQUE(reservation_id, unit, date)` and `UNIQUE(division_id, order_key)`. Land **with** the v2/v3 postmeta→relational de-coupling (same storage-normalization work). Needs a data migration. |
| **F2** | LOW | 📝 ops note | Auth.net error path `error_log`s the full decoded gateway **response** (masked PAN only, never raw card) under `WP_DEBUG`. | `shortcodes.php:7302` | Acceptable (WP_DEBUG-gated, masked). Ensure production `debug.log` is non-public, or trim the logged payload. |
| **F3** | LOW | 📝 functional | `refund_with_authorize_net` voids and **rejects partial refunds**; settled Auth.net transactions can't be voided. Fails *safe* (no over-refund) but admins can't partially/post-settlement refund from the UI. | `class-eem-refund-engine.php:316-321` | Functional limitation, not a security hole. Implement Auth.net `refundTransaction` (credit) for settled/partial refunds when wanted. |
| **KEYS** | LOW | ⚠️ accepted | Stripe/Auth.net keys stored plaintext in `wp_options`. | Settings | WP-ecosystem norm; at-rest encryption keyed from `wp-config` gives little protection vs the DB-breach threat (attacker usually has the key too). Decision recorded; revisit only with a real secrets vault. |

---

## 5. Clean dimensions (audited, found solid)

These were specifically checked and are **correct** as of v2.7.283:

1. **Amount integrity — no client-trusted prices.** Every charged amount is recomputed server-side from
   reservation config × server-validated quantities (`calculate_submission_totals()`,
   `shortcodes.php`). Auth.net charges the server total; Stripe verifies `intent.amount === round(total*100)`;
   admin Collect Payment recomputes `get_order_amount_due()` server-side. **No path lets a POSTed
   price/total reach a gateway charge.**
2. **Authorization on money endpoints.** `current_user_can('manage_options')` + nonce on every admin
   money handler — single/bulk refund, cancel, bulk send-link, Create Order, Collect Payment
   create-intent/confirm/charge, remove-discount. No lower-privilege reachability found.
3. **Refund integrity.** Over-refund serialized behind `GET_LOCK('eem_refund_'+md5(key))`; a
   `refunded_amount` decimal ledger (reads `max(column, notes)`); `exceeds_remaining` + non-positive
   guards. Cannot over-refund or refund beyond captured.
4. **Webhook auth + replay (Stripe).** HMAC-SHA256 signature, 300s tolerance, `hash_equals`; the route
   requires `status==='succeeded'` **and** `amount_received >= order total` before marking paid;
   idempotent on already-paid; underpayment logged + left unpaid.
5. **Gateway response validation (Auth.net).** Requires `responseCode === '1'` **and** a non-empty
   `transId` before marking paid; unreadable response → failure, never silent-paid.
6. **Discount / tax / fee math.** Discount applies to subtotal; fee + tax recompute from the
   post-discount subtotal; discount clamped to `[0, subtotal]`; `discount_reason` server-required (422);
   per-order tax allocated to the cent.
7. **Data exposure.** No raw card number / CVV / gateway secret is ever logged, stored, or echoed. Card
   fields flow `$_POST` → gateway → discarded; only card brand/last4 (post-charge) is persisted.
8. **Injection / XSS / IDOR / SQL.** Prior audit found these clean (parameterized queries, output
   escaping, ownership checks); CSV exports neutralize formula injection.
9. **Checkout oversell.** Charge + insert serialized under `eem_checkout_{reservation_id}` with a fresh
   in-lock availability re-validate. (Full detail in the companion inventory report.)

---

## 6. Recommendations (priority order)

*(Both known double-charge vectors on the launch processor — F1 and MED-1 — are now closed.)*

1. **LOW-2 — add the DB UNIQUE backstop.** The durable structural fix; pairs with the v2/v3
   postmeta→relational de-coupling.
2. **F3 — Auth.net partial/settled refunds** via `refundTransaction` when the workflow needs it.
3. **F2 — confirm production `debug.log` is non-public** (or trim the Auth.net response log).
4. **Add a concurrency load-test** (per the inventory report §10) to validate the no-oversell /
   no-double-charge guarantees end-to-end under real parallel load, not just by inspection.

---

## 7. Regression smokes guarding these findings

Run `bash tests/smoke/run-all.sh`; the security-relevant guards are:

- `security-money-path-highs-smoke.php` — refund lock, Stripe intent bind, webhook re-check, etc.
- `refund-ledger-column-smoke.php` — refund ledger round-trip / over-refund guard.
- `security-low-hardening-smoke.php` — discount cap, CSV-injection neutralization.
- `assignment-lock-smoke.php` — the 5 admin assign paths share the checkout lock.
- `assignment-conflict-smoke.php` — MED-2 cross-order double-book is blocked (real seeded orders).
- `invoice-payment-lock-smoke.php` — F1 hosted-invoice lock + in-lock re-read.
- `checkout-replay-guard-smoke.php` — MED-1 replay short-circuits before the charge.

---

## 8. History

- **Jun 2026 (v2.7.187–202):** initial audit — fixed refund over-refund, Stripe intent reuse, Stripe
  webhook amount re-check, agreement-PDF MIME gate, refund numeric ledger, discount >100% cap, CSV
  injection; closed the admin concurrent-assign race (5 paths on the shared lock).
- **Jun 2026 (v2.7.282–283, this report):** strict inventory/concurrency + financial re-audit — fixed
  MED-2 (admin cross-order double-book), LOW-1 (fail-safe lock), F1 (hosted-invoice double-charge);
  documented MED-1, LOW-2, F2, F3 and the accepted plaintext-keys risk.
