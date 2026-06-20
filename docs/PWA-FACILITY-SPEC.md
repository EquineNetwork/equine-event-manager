# PWA + Facility-Staff Role — Feature Spec

**Status:** Planning (v2). Part of roadmap execution-order **#3 "Mobile / PWA polish."**
**Author of vision:** Whitney. **Drafted:** 2026-06-20.
**One-line:** Turn the plugin into an installable, mobile-first PWA with a **two-role** model —
full **Admin** and a scoped, PII-free **Facility staff** login — so venues can run stall/RV
operations from a tablet in as few taps as possible.

---

## 1. Why

Facilities run the stalls/RV lots on the ground, ringside, often on a tablet over flaky venue
Wi-Fi. Today the only login is a full WordPress administrator, which exposes customer data, orders,
money, and settings to anyone who needs to do nothing but check people in and turn stalls over.
We want:

- A **Facility staff** login that does the physical work and **nothing else** — no customer info,
  no order info, no money, no settings.
- The whole experience **installable + full-screen + fast on a tablet** (PWA), with the
  **fewest possible taps** to get the job done.
- The existing **Admin** retains full power (orders, charges, refunds, reservations, settings).

Sticky value: when a facility runs its operation inside our system, that's real lock-in.

---

## 2. The two roles

### 🛠️ Admin (existing — `administrator` / `manage_options`)
Superuser. Everything: orders, **money charges + refunds**, reservations, stall/RV management,
reports, settings, the facility screens too. No change to *what* they can do — but their
money/management flows get the **mobile-first, fewest-taps** treatment (see §5).

### 👷 Facility staff (NEW — `eem_facility_staff` role + `eem_manage_stalls` capability)
A scoped, **PII-free, unit-centric** operations login.

**CAN see/do:**
- See **all stalls + RV lots for their event** as physical units (by number/label).
- See unit **status**: occupied / available / **needs cleaning** / checked-in / checked-out.
- **Check guests in and out.**
- **Mark cleaning / turnover** (cleaned → available).
- **Assign / move / block** stalls + RV lots (the physical management).
- Filter to a working queue (e.g. "needs cleaning").

**CANNOT see/do (hard deny, enforced server-side):**
- ❌ Any **customer information** — names, contact, anything personal.
- ❌ Any **order information** — order numbers, line items, history.
- ❌ Anything to do with **money** — prices, totals, charges, refunds, balances.
- ❌ Reservations editor, Settings, Reports, Notifications, Customers, Dashboard money cards, the
   "By Customer" stall view, the Daily Movement customer table.

The Facility view is essentially **the By-Location readiness grid + the Map**, with **every customer
name and order number stripped out** — just unit numbers + color-coded status + tap-to-change.

---

## 3. Scoping — a facility login sees only *its event*

- A Facility user is **tied to a specific event/reservation** (or its venue) at setup time, and only
  ever sees that event's stalls/RV lots.
- Prevents Venue A's staff from seeing Venue B's map.
- **OPEN DECISION (scope granularity):** assign by **event/reservation** (re-assigned per event) vs
  by **venue** (sees all events at that venue automatically). Leaning event/reservation for v2
  simplicity; venue-scoping is a natural v2.x upgrade once `en_venue` ownership is fully wired.
- Enforcement: every Facility-reachable query + AJAX handler filters by the user's assigned
  event/reservation **and** checks `eem_manage_stalls`. Hidden in the UI **and** blocked on the server.

---

## 4. Check-in model — OPEN DECISION (per-party vs per-unit)

Today check-in is **per-order/per-customer** ("the guest arrived → covers all their stalls").
Facility staff can't see the guest, so the natural model is **per-unit**: tap **Stall 100 → Checked
In / Cleaned**; the server records it against whoever is assigned there, without ever showing the name.

**DECISION NEEDED:** when a guest holds stalls 100/101/102 and facility checks in 100 —
- **(A) Per-party:** 101 + 102 flip too (whole party arrives at once).
- **(B) Per-unit:** each stall is checked-in / cleaned independently (ground-ops default; each unit
  turns over on its own schedule).

Recommendation: **(B) per-unit**, with an optional admin-side per-order rollup view. *Confirm before
build.*

---

## 5. Mobile-first / fewest-taps (the north star)

Applies to **both** roles. The PWA wrapper only feels good if the screens under it are touch-fast.

**Facility (tablet):**
- App opens **directly onto the stall/RV map (or readiness grid) for their event** — no menu, no
  event picker when they run one event. The work *is* the home screen.
- **One tap** to change a unit's status (Checked In / Cleaned / etc.). Big tap targets, status
  colors readable at a glance, optional "needs cleaning" filter so turnover staff see only their queue.
- No navigation chrome they don't need; no WordPress dashboard.

**Admin (phone/tablet):**
- Money/management flows (collect payment, refund, check-in, assign, create order) reachable in
  **1–2 taps** from an order or the dashboard; big targets; **fewer modal steps** — audit current
  flows and cut taps.

---

## 6. PWA wrapper

- **Web app manifest** — name, icons, `display: standalone` (full-screen), theme colors. Installable
  "Add to Home Screen."
- **Service worker** — cache the plugin's static assets (CSS/JS/logo) for instant paint + resilience
  on flaky venue Wi-Fi; a friendly **offline fallback** screen.
- **ONLINE-ONLY WRITES (non-negotiable).** Do **not** queue stall/RV/check-in writes offline for
  later sync. The entire oversell/double-book protection depends on writes being **serialized live on
  the server** (advisory locks — see `docs/INVENTORY-CONCURRENCY-REPORT.md`). Offline write queuing
  would reintroduce double-booking. Offline = read-cached shell + "reconnect to make changes."
- **HTTPS required** for install/service workers — test installability on staging/production (Local
  dev is http; the responsive/touch polish is previewable locally).
- wp-admin is full-page-loads (not an SPA), so the SW does asset-caching + offline fallback, not
  app-shell SPA behavior. Fine for this use case.

---

## 7. Security

This role is **security-sensitive** but, because Facility staff have **no route to PII/order/money
data at all**, the surface is small and easy to reason about: grant exactly `eem_manage_stalls`,
deny everything else, scope to the assigned event.

- Every Facility-reachable page + AJAX handler: check `eem_manage_stalls` **and** apply the event
  scope. Hidden in UI **and** blocked server-side (no relying on "they can't see the link").
- Strip PII at the data layer for Facility responses — never send customer names / order numbers /
  amounts to a Facility session even if a template would render them.
- Pair this build with roadmap **#2 financial-security audit** so the new role is locked down from
  day one. The concurrency audit (`docs/INVENTORY-CONCURRENCY-REPORT.md`) already inventoried every
  write handler that needs the capability/scope check.

---

## 8. Build phases (in order)

1. **Facility role + scoped, PII-free ops view.** Custom role + `eem_manage_stalls` cap; scope to
   assigned event; a By-Location readiness grid + Map with all customer/order data stripped;
   per-unit check-in/clean (pending §4 decision); capability + scope enforced on every handler.
2. **Mobile-first / fewest-taps pass.** Facility home = the map/grid, one-tap status; audit + cut
   taps on the admin money/management flows for touch.
3. **PWA wrapper.** Manifest + service worker (asset cache + offline fallback), installable +
   full-screen; online-only writes.

The admin-does-everything piece already exists; the real build is #1 (scoped multi-tenant login) and
#2 (mobile optimization). #3 (PWA) is the polish on top.

---

## 9. Open decisions (need Whitney)

- **§4 — check-in per-party (A) vs per-unit (B).** Recommend B.
- **§3 — facility scope by event/reservation vs by venue.** Recommend event/reservation for v2.
- Capability edge cases: should Facility see a **count** of arrivals/departures (no names) on a
  Daily-Movement-style summary, or purely the unit grid? (Counts are PII-free and useful.)
