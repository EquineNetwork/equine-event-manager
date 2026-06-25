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

## 🔨 In progress tonight (will fill in as I go)
