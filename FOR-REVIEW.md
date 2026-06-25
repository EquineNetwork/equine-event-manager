# For Review — walk this list tomorrow

Every change below is **committed + pushed to GitHub**. To see them on staging:
**update the plugin once to the latest version, then clear OPcache** (WP Engine portal)
or wait a few minutes for the cache to cycle. Each row says where to look and what to check.

> ⚠️ If something "isn't showing," it's almost certainly OPcache (not a bug) — the code is
> verified working on Local. Re-update / clear cache before assuming it's broken.

---

## ✅ Shipped today (2.7.592 → 2.7.599)

| # | Version | Where to look | What you should see |
|---|---------|---------------|---------------------|
| 16 | 2.7.592 / 2.7.598 | **Order detail → Order Notes card** | Confirmation # in its own labeled row (no longer buried in Special Requests). An editable **Note** field with a **Save Note** button — type a note, Save, refresh: it sticks. Same note appears in the Stall Chart Notes column + Daily Movement. |
| 17 | 2.7.593 | **Stall & RV Charts → By Customer** + map pill | A **Shavings** column (purple "N bags" badge). Click an assigned stall on the map → popover shows "Shavings: N bags." |
| 4 | 2.7.594 | **Stall & RV Charts → List + Map** | Consistent colors everywhere: **green = available, blue = booked, red = blocked, orange = tack, purple = cleaning**. (Blocked used to be gray; available on the map used to be white.) |
| 2 + 20 | 2.7.595 | **Order detail** (imported orders) | The **Assign Stalls / Assign RV** buttons now appear (they were hidden on imported orders). Migration backfilled the section flags. |
| 14 | 2.7.596 | **Stall & RV Charts → By Customer** | A **Barn filter** dropdown (All Barns / each barn / Unassigned), next to the search. |
| 10 | 2.7.597 | **Dashboard** | Upcoming Events + Upcoming Reservations now phrase the same event the same way ("In N days") — no more "In 3 days" vs "Opens in 1 day" conflict. |
| — | 2.7.599 | (infrastructure) | OPcache now auto-flushes on every plugin update so changes stop looking stale. |

---

## 🔨 Tonight's work — CLICK-TEST these on Local before the demo

| # | Version | Where to look | What to click-test |
|---|---------|---------------|--------------------|
| 3 (slice 1) | 2.7.600 | **Stall & RV Charts → By Location → List** | Click an **available (green)** stall cell → the little menu now has a 3rd option **Block** (red dot) under Available / Cleaning. Click Block → the stall should turn **red (blocked)** in both List and Map. This routes through the same proven endpoint the Map's Block uses. ⚠️ **Please click-test.** |
| 3 (slice 2) | 2.7.601 | **Stall & RV Charts → By Location → List**, click an **assigned (blue)** stall | The cell popover (Move / View order / Tack) now also has a red **Remove from stall** option → click it → the order is unassigned and the stall goes back to **green/Available**. Uses the Map's proven unassign endpoint. ⚠️ **Please click-test.** (Also fixed: the Block from slice 1 now correctly re-applies the Show/Stalls/RV filter after it updates.) |

### #3 status (the full menu unification)
- **Done tonight:** List available-cell menu can now **Block** (it previously only did Available / Cleaning) — closes the biggest List-vs-Map gap, using the Map's exact `eem_stall_map_action` endpoint + region-swap.
- **Still to do (needs a live click-test session):** add **Checked-in** + **Unassign** to the List *occupied* popover, and make the List + Map popovers visually identical. These touch more interactive wiring; held so they can be built with live clicking rather than blind. Full plan is in `docs/STAGING-FIXES-LOG.md` under "[#3]".

---

## ⏳ Not done yet (need you)
- **#3 (rest)** — Checked-in + Unassign on the occupied popover, and visual parity between the List and Map popovers. Needs a live click-test session.
- **#19 [Later]** — remove the "X days" countdown chip on the event-flyer card. **Blocked: need your mockup.**
- **#21 [Later]** — restyle the "View Event" overview page to match the plugin design. Safe/display-only; can do anytime.
