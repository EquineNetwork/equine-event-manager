# HANDOFF — EEM Styling chat

**From:** the Payment-Audit chat (desktop, works the payment/money code on `main`).
**Date:** 2026-06-27.
**Why:** We're running two chats in parallel. To avoid collisions we're splitting ownership.
The roadmap on `main` (`ROADMAP.md`) is the single source of truth.

---

## Ownership split (the one rule that prevents collisions)

- **Payment chat (me) owns:** all money / charge / refund / payment code, the order data path,
  AND the `ROADMAP.md` file.
- **You (Styling chat) own:** styling + non-money features + your already-built PR #36.
- **Only the payment chat edits `ROADMAP.md`.** If you want something on the roadmap, tell
  Whitney or leave it in your PR description — don't edit `ROADMAP.md` yourself.

---

## ✅ YOUR LIST (in priority order)

1. **Verify + merge PR #36** (your branch — already built & pushed). Whitney click-through →
   merge. Contents: Import/Export page styling + custom file inputs; list-table bottom-border
   fix (Events/Customers/Term Categories); Daily Movement footer; invoice/refund/payment-received
   email + report-PDF design-system parity; CSV import hardening (size/type validation, no DB-error
   leak); order payment-status whitelist; hourly cart-hold cleanup cron; admin BCC send-failure
   logging; `move_uploaded_file` error-suppression fix.
   **On merge, resolve the `ROADMAP.md` conflict in favor of `main`** (see Git workflow below).

2. **Global mobile visual polish** — per-page pass to the Daily Movement standard (row heights,
   badge sizing, spacing/density). Pure CSS/markup. Scaffolding shipped 2.7.577–580.

3. **Restyle "View Event" overview page** to match the plugin design system.

4. **Import/Export — carry event-level dates** into the imported reservation (currently not
   carried). This is reservation config/dates only — **do not touch order pricing/line-item math.**

5. **TEC event-list template** — frontend event-list display for TEC-sourced events. Scope/design
   TBD; confirm with Whitney before building.

6. **Bulk-notification unsubscribe** — per-recipient opt-out: HMAC-signed (WP-salt-keyed)
   unsubscribe link in bulk emails (Notifications page + Email Customers), a public handler that
   records the opt-out, and a skip-check before non-transactional sends. **Transactional emails
   (confirmation, refund, payment, invoice, cancellation) always send regardless.** Approved.

7. **Remove "X days" countdown chip** on the event-list flyer card. **BLOCKED — needs Whitney's
   mockup first. Do not start until she provides it.**

8. **Stall & RV Charts — toolbar layout rework.** Goal: one consistent control layout that stays
   put across all 3 views (By Customer / List / Map), Show + View anchored together under the page
   title. **BLOCKED — do not start until Whitney confirms the direction** (she's sleeping on it).

9. **Group Names — verify** when groups are actually in use (verification pass, not a build).

---

## ⏸️ GATED — coordinate with me first (these read the order data path I'm fixing)

- **Add-On Report** (per-day add-on quantities, CSV + PDF; paused mid-build). It uses
  `EEM_Reports_Repo`, which I'm rewriting for **F6** (the 250-order cap that makes reports
  undercount). **Wait until I land F6, then build it on the uncapped data path.** Ping me first.
- **Auto payment-reminder for unpaid orders** (daily cron → `PAYMENT_REMINDER` email, with dedupe).
  Email + cron only, **no charge/dispatch changes** — but it finds unpaid orders by iterating the
  order set, which is also F6-affected. **Build after F6** (or use the paginated/uncapped query).
  If you'd rather I take this one entirely, say so.

---

## 🛑 HANDS-OFF — payment chat owns these, do NOT touch

I'm actively fixing bugs across these files (F1–F9 + P1–P5). Touching them will collide:

- `public/class-equine-event-manager-shortcodes.php` — the charge calculator
  (`calculate_submission_totals`), order insert (`insert_reservation_orders`), receipt builders
  (`build_order_line_items`, `build_receipt_html`, `get_order_stall_breakdown`), Stripe + Auth.net
  dispatch.
- `includes/class-equine-event-manager-orders-repository.php` — order grouping + the 250-row cap.
- `includes/class-eem-refund-engine.php`, `includes/class-eem-order-adjustments-repo.php`.
- `admin/class-eem-order-detail-page.php`, `admin/class-eem-collect-payment-page.php`,
  `admin/class-eem-create-order-page.php` — order money rendering / Add Items / Edit Dates.
- The **revenue** paths in `includes/class-eem-reports-repo.php` + `includes/class-eem-dashboard-repo.php`.
- `EEM_Settings_Repo` payment/fee/tax getters; the Settings → Payments save for keys.

**You CAN restyle** non-money admin pages, list tables, emails (visual only — I handle the invoice
"Click here to pay" button text + totals), PDFs (visual), Import/Export UI, charts UI (when
unblocked), and mobile CSS.

---

## 🔧 GIT WORKFLOW — bumping, pushing, merging (follow exactly)

1. **Branches, not main.** Do all work on a feature branch (e.g. `claude/styling-<topic>`). Never
   commit directly to `main`. (I work payment fixes on `main` from the desktop; you PR into it.)
2. **Never bump the version without Whitney's explicit approval each time.** This is a hard standing
   rule. When she approves, bump BOTH spots in `equine-event-manager.php` (the `Version:` header
   line + the `define( 'EQUINE_EVENT_MANAGER_VERSION', ... )`). One bump per release, not per commit.
3. **Push your branch, open a PR.** `git push origin <your-branch>`, then open a PR against `main`.
   Do not push to `main` directly.
4. **Merging is Whitney's call.** Whitney reviews → merges the PR on GitHub → confirm `main` has the
   change → THEN bump the version (only if she approved). The in-WordPress auto-updater watches
   `main`; she updates sites via Plugins → "Update now."
5. **ROADMAP.md conflicts → always take `main`'s version (mine).** Do not edit `ROADMAP.md` on your
   branch. If a merge shows a `ROADMAP.md` conflict, resolve it entirely in favor of `main` (drop
   your side). Easiest: before opening the PR, `git checkout origin/main -- ROADMAP.md` on your
   branch so there's no conflict at all.
6. **Rebase on latest `main` before opening/merging a PR** so you're not behind my payment commits.
7. **Don't bump while my payment fixes are mid-flight** unless Whitney coordinates the order —
   payment fixes change charge math and need their own sign-off + live test before any release.

---

## 🧪 Don't-break rules

- Run the smoke suite before pushing; **do not let any money smoke regress** (order-totals,
  order-breakdown-cross-surface, receipt-line-items-parity, convenience-fee-global, surcharge-tier).
  If a money smoke goes red after your change, you touched something in the hands-off list.
- Command hygiene (from `CLAUDE.md`): one command per Bash call, no `&&`/`;` chaining, no `cd`, no
  heredocs. Use the editor tools for file content.
- Test stall/RV maps on **NTR 6519** only (reservation 5990's RV map is corrupted).

---

## Contact / sync

The roadmap on `main` is the live to-do list — read it first each session, but **don't edit it**.
When you finish an item, tell Whitney; I'll check it off on the roadmap. If something you need is
in my hands-off list, ask and I'll either do it or hand you a safe slice.
