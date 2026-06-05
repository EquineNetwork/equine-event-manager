# Walkthrough & Deploy Notes

**Current version: 2.7.43** · suite: **94/94 green** · branch: `main` (pushed to `github.com/EquineNetwork/equine-event-manager`)

This is the launch-prep reference for the staging walkthrough. It covers how to deploy, what to clear, what to test, what's new, and the few things that need you (not automatable).

---

## 1. Deploy to staging

1. Pull latest `main` (v2.7.42) onto the WP Engine staging site.
2. **Clear caches** — this is required or front-end fixes won't appear:
   - **WP Engine** → admin bar **Caching → Clear all caches** (or the User Portal). WP Engine full-page-caches HTML aggressively.
   - **Elementor** → **Tools → Regenerate Files & Data** (the customer event page is Elementor-built).
   - Hard-refresh the browser (Cmd/Ctrl + Shift + R).
   - *(There is no caching plugin installed — only Elementor + WP Engine's server cache.)*

---

## 2. What changed this session (2.7.31 → 2.7.42)

**Bug fixes**
- Receipt redirect after checkout was showing as raw text — `wp_kses_post` was stripping the `<script>`; now redirects correctly to the hosted receipt.
- Pick-your-stalls checkout with 0 stalls picked now **confirms** ("we'll auto-assign — Cancel to pick, OK to continue") instead of silently auto-assigning.
- Stall & RV Charts list summary ("Available / Reserved / Blocked") now derives from the real occupancy grid (was showing impossible counts like "38 Reserved" on a 21-stall chart).
- Reservations list **Type** column now shows Stall/RV badges for row-builder reservations (was only showing Add-On/Group).
- Order Detail no longer shows a misleading "Shavings (×N) $0.00" add-on line.
- Hardened several undefined-array-key warnings (reservation save, invoice email, dashboard) — log is now clean.
- Gated reservation debug logging behind `WP_DEBUG` (no more production log spam).

**New feature — First-run setup wizard** (your request)
- A guided **step-by-step modal** auto-opens for new admins, walking them through the required setup in order (Event Source → Branding → Communications → Payments → SendGrid), so they know what to connect first. Each step explains what it's for and links straight to the right Settings panel.
- It only appears while the four **required** areas aren't done (SendGrid is optional). It reopens to guide the next step as they make progress, and disappears once required setup is complete. The Dashboard checklist card stays as the ongoing reference.
- **To see it:** it shows on a fresh/incomplete setup. The fastest way to demo it is **Danger Zone → Erase all data & start fresh** (below) — after the wipe, the wizard greets you as a brand-new user.

**New feature — Erase all data / fresh start** (your request)
- **Settings → Danger Zone → "Erase all data & start fresh"** — type `ERASE`, confirm, and the plugin wipes all its data, re-seeds the just-installed baseline, and drops you on the Dashboard with onboarding back at 0. No reinstall needed.
- **Danger Zone → "Also delete all data when the plugin is deleted"** (off by default) — when on, deleting the plugin from the Plugins screen also wipes its data.
- Never touches TEC events or your media library.

**Test suite**
- Greened the previously-stale suite and added regression smokes for every fix above. 93/93 files passing.

---

## 3. Walkthrough checklist (suggested order)

- [ ] **Dashboard** — setup checklist shows completion; KPI cards populate.
- [ ] **Create Order** (admin) — load a reservation, use the qty stepper, try Send Link / Charge Card / **Save as Open Tab** (open tab creates an unpaid order, no charge/email).
- [ ] **Orders list** — status filter tabs, Collect buttons on unpaid rows.
- [ ] **Order Detail** — summary reconciles; activity log present.
- [ ] **Stall & RV Charts** — list stats now reconcile; open a chart, check the occupancy grid.
- [ ] **Customer checkout** (front-end event page) — toggles, stall picker, live totals; **pick 0 stalls → confirm prompt**; complete a **test** payment and confirm it **redirects to the receipt**.
- [ ] **Reports** — generate/export each report.
- [ ] **Customers / Customer Profile** — list + a profile.
- [ ] **Settings → Danger Zone** — confirm the panel + the erase modal (type ERASE). Use it to reset and re-walk the **new-customer** experience if desired.

---

## 4. Needs you (not automatable / by design)

- **Live card charge → receipt** — requires entering a real (test-mode) card number. The whole flow up to the charge is verified; the charge + redirect is the one human step.
- **The actual data erase** — the Danger Zone wipe is operator-triggered (typed `ERASE` confirm). It's never run by tests or automatically. Use it on staging when you want a clean new-user run.
- **Production go-live config** — live Stripe keys, live webhook, SendGrid API key.
- **After erasing on staging**, do a quick pass of the main admin pages in the empty state (no reservations yet) to confirm they read cleanly as a brand-new install.

---

## 5. Verification done this session

- Full smoke suite: **93/93**.
- Comprehensive warning sweep: **0 plugin warnings** across 83 paths (every order type × Order Detail render, 38 invoice/refund email builds, customer profiles, all reports × filter variations).
- Live browser verification: Dashboard, Create Order (+ Save as Open Tab → unpaid order), Orders list + filter, Stall charts list + detail, Customers + Profile, Reservations list + editor, Settings (vertical-nav layout), customer checkout + live totals + stall-pick confirm (3 runtime cases), and the **Danger Zone modal** (visible, typed-confirm gating, server rejects wrong word with no deletion).
