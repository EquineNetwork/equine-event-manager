# C10 Enforcement Contract — RV Lot Zone Assignments

**Locked: 2026-05-30**

---

## Overview

This document defines the canonical contract between the admin-side RV lot Paint Mode
(C7/C8 — reservation editor) and the customer-facing RV checkout page (C10).

---

## The Rule

A lot is **available to customers** if and only if it has an explicit zone assignment in
`_en_rv_lot_zone_assignments`.

A lot is **unavailable to customers** if:
- It has no entry in `_en_rv_lot_zone_assignments`, OR
- Its entry is `null`, OR
- Its entry is an empty string `""`

This rule applies **regardless of zone pricing**, reservation status, or any other
condition. The zone assignment is the single canonical availability signal.

---

## Admin UI Signal

Lots without a zone assignment display a **grey dot (`#9CA3AF`)** in the reservation
editor's RV Row Builder. The hint text reads:

> "Lots with a grey dot are unassigned and won't be available to customers. Use Paint
> Mode above to assign each lot to a zone."

When an admin clicks Publish or Update Reservation with one or more unassigned lots,
a confirmation modal appears:

> "N lot(s) are unassigned and won't be available to customers. Publish anyway?"

Admins can dismiss (Cancel) or proceed (Publish Anyway). This is a warning, not a
hard block — drafts with unassigned lots are permitted.

---

## Data Shape

`_en_rv_lot_zone_assignments` is stored as a JSON-encoded associative array:

```json
{
  "0": { "A1": "0", "A2": "1", "A3": "0" },
  "1": { "B1": "2" }
}
```

Keys at the outer level are row indices (string-cast integers, matching the row order
in `_en_rv_rows`). Keys at the inner level are lot labels (strings). Values are zone
indices (string-cast integers, matching the order in `_en_rv_zones`).

**Absent key = unassigned = unavailable.** No default zone is auto-assigned.

---

## C10 Implementation Requirement

When the customer-facing checkout renderer (C10) builds the lot selection UI for an
RV row, it MUST:

1. Load `_en_rv_lot_zone_assignments` for the reservation.
2. For each lot in each row, check whether an explicit zone assignment exists.
3. **Exclude lots with no assignment** from the selectable / bookable pool.
4. Do NOT fall back to a default zone. Do NOT treat "no entry" as "zone 0".

Code comment required at the C10 filter point:

```php
// C10 ENFORCEMENT CONTRACT (2026-05-30):
// Lots absent from _en_rv_lot_zone_assignments (or with null/empty zoneIndex)
// are UNAVAILABLE to customers. Do NOT auto-fill with a default zone.
// See: docs/c10-contracts.md
```

---

## Why Not Auto-Assign in the Admin?

Previous versions of the plugin defaulted unassigned lots to the first zone index
(`'0'`), making them appear identical to intentionally-painted lots. This caused:

- Lots the admin hadn't yet painted to be silently available at checkout
- The admin having no visual signal that unpainted lots were live

The fix (2.3.21, 2026-05-30):
- Admin JS `getDefaultZoneForLot()` returns `''` (unassigned) for lots with no saved
  assignment — never `'0'`
- The grey dot gives admins a clear signal before publishing
- The publish warning gives a final checkpoint

---

## Migration Note

Reservations saved before 2.3.21 may have lots in `_en_rv_lot_zone_assignments` with
zone index `"0"` that were auto-assigned by the old default behavior. Those entries
are explicit zone assignments (zone 0 IS a valid zone) and are treated as available
by C10. Only absent/null/empty entries are treated as unavailable.

---

## Related Files

| File | Role |
|---|---|
| `assets/js/admin.js` | `getDefaultZoneForLot()`, `rvCountUnassignedLots()`, `openUnassignedLotsWarning()` |
| `templates/admin/reservation-editor/_section-rv.php` | Lot zone assignment init, hint text |
| `admin/class-eem-reservation-editor-page.php` | `_en_rv_lot_zone_assignments` save handler |
| `assets/css/admin.css` | Grey dot CSS fallback for `[data-zone-id=""]` |
| `.mockups/edit_reservation_page.html` | Visual reference: grey dot, publish warning |
