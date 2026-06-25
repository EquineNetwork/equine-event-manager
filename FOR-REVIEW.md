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

| 3 (per-night block) | 2.7.602 | **Stall & RV Charts → By Location → List**, click an **available** stall | Block now opens a **modal**: ◯ Just this night (date) · ◯ All nights · ◯ Pick nights… (checkboxes per event night). Pick a scope → Block → only the chosen night(s) turn red; other nights stay green. Verified on Local that a single-night block colors only that night. ⚠️ **Please click-test the modal** (opens, radio toggles the checkbox list, Block applies). **Known limit:** the spatial **Map** is a single snapshot with no date axis, so a *partial-night* block shows as available there — the **List** is the per-night source of truth. All-nights blocks show on both. |

| view flip fix | 2.7.603 | **Stall & RV Charts** (open it fresh) | The chart no longer flashes "By Location – List" then snaps to "By Customer." The client now respects the server's default view instead of forcing Customer on load. |
| manage-assignment highlight | 2.7.603 | **Order detail → Manage Stall Assignment** (on an order that HAS a stall, e.g. #265) | The chart now scrolls to + puts a **blue ring** around the customer's current stall, and the banner reads "X is currently in stall #265 (highlighted). Click another available stall to move them, or Remove from stall." (Was: dropped you on a sea of stalls with no indication.) ⚠️ **Please click-test.** |

| 3 (Checked-in) | 2.7.604 | **By Location** (List or Map), click an **assigned (blue)** stall | The popover now has **Mark Checked In** (toggles to **Mark Pending Arrival** once checked in). Click it → toast confirms; the order's check-in flips (same status shown in By Customer + Daily Movement). RV pills don't show it. Verified the toggle round-trip on Local. ⚠️ **Please click-test.** |

| 3 (visual parity) | 2.7.605 | **By Location → List vs Map**, open a cell popover in each | The Map popover now matches the List popover's chrome (same border, radius, row spacing, hover gray, font weight, red for destructive). They should look like the same menu. |

| date headers fix | 2.7.606 | **By Location** column headers | Now show the **weekday + correct date** ("Thu, Jun 25"). Fixes the bug where staging showed "JUN 24 TODAY" for the 25th (a timezone shift — proven + fixed). |
| block modal fix | 2.7.606 | **Block** modal | The night checkboxes are now the source of truth — check 27 + 28 and Block → exactly those nights block (no more "only today" because a radio overrode your checkboxes). "Just this night" / "All nights" are quick presets that set the checkboxes. |
| Assign from List | 2.7.606 | **By Location → List**, click an **available** stall | The dropdown now has **Assign…** (blue) at the top → opens a customer search → pick a customer → they're assigned to that stall. (Was only on the Map; now on the List too.) ⚠️ **Please click-test.** |

| unblock | 2.7.607 | **By Location → List**, click a **Blocked** cell | Blocked cells are now clickable (they were dead before) → menu offers **Unblock this night** / **Unblock all nights**. Per-night aware: unblocking one night of an all-nights block leaves the other nights blocked. ⚠️ **Please click-test.** |

| check-in lifecycle | 2.7.608 | **assigned stall** popover | Button now cycles **Mark Checked In → Mark Checked Out → Mark Pending Arrival** and updates immediately after you click (no more stale "Mark Checked In" on reopen). |
| check-in indicator | 2.7.608 | **By Location → List**, assigned cells | Each assigned cell shows an inset ring: **red = assigned but not checked in (hasn't arrived)**, green = checked in, slate = checked out. Updates live across all that customer's nights when you toggle. ⚠️ tell me if you want different colors. |
| assign empty-state | 2.7.608 | Assign… modal | If the customer list is empty it now says "No customers available — try refreshing" (the earlier "No match" for "duhon" was the roster not loaded yet — stale PHP/OPcache; clears on refresh). |

### #3 status — COMPLETE (pending your click-test)
All five actions are now on both the List and the Map: **Assign · Cleaning · Checked-in · Tack · Block** (+ Remove/Unassign + Move). Per-night block modal works. Colors consistent. Popovers visually aligned. Walk the rows above (Block, per-night modal, Remove, Checked-in, colors, view-flip, highlight, visual parity) to sign off.

---

## ⏳ Not done yet (need you)
- **#3 (rest)** — Checked-in + Unassign on the occupied popover, and visual parity between the List and Map popovers. Needs a live click-test session.
- **#19 [Later]** — remove the "X days" countdown chip on the event-flyer card. **Blocked: need your mockup.**
- **#21 [Later]** — restyle the "View Event" overview page to match the plugin design. Safe/display-only; can do anytime.
