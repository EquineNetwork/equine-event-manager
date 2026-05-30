# C10 Enforcement Contract — RV Lot Zone Assignments

**Locked: 2026-05-30 · Updated to V1 model: 2026-05-30**

---

## Overview

This document defines the canonical contract between the admin-side RV lot Row Builder
(reservation editor, Mapped mode) and the customer-facing RV checkout page (C10).

---

## V1 Zone Model (2.3.22+)

### The Rule

A lot is **available to customers** if and only if:
1. The lot belongs to a row that has a non-empty `zone_id` field, AND
2. The zone referenced by `zone_id` exists in `_en_rv_zones`

A lot is **unavailable to customers** if:
- Its row has no `zone_id` (empty string `""` or absent), OR
- Its row's `zone_id` references a zone that no longer exists

This rule applies **regardless of zone pricing**. Zone assignment at row level is the
single canonical availability signal in V1.

### Admin UI Signal

RV row cards in Mapped mode have a Zone dropdown. Rows without a zone selection show
no left-border color indicator (grey border). Rows with a zone get a colored left
border matching the zone's auto-palette color.

The hint text on the Lot Rows field reads:
> "Each row must be assigned to a zone. Rows without a zone assignment are unavailable
> to customers at checkout."

### Data Shape

`_en_rv_rows` is stored as a JSON-encoded array of row objects:

```json
[
  { "name": "RV Row A", "layout": "one-sided", "first": "1", "last": "12", "zone_id": "0" },
  { "name": "RV Row B", "layout": "back-to-back", "top_first": "13", "top_last": "18",
    "bot_first": "19", "bot_last": "24", "zone_id": "1" }
]
```

`zone_id` is the string-cast index into `_en_rv_zones` (0-based). Empty string = unassigned.

`_en_rv_zones` is stored as a JSON-encoded array:

```json
[
  { "name": "Red Lot",  "nightly": "35.00", "weekend": "90.00" },
  { "name": "Blue Lot", "nightly": "25.00", "weekend": "65.00" }
]
```

### C10 Implementation Requirement

When the customer-facing checkout renderer (C10) builds the lot selection UI:

1. Load `_en_rv_rows` and `_en_rv_zones` for the reservation.
2. For each row, check whether `zone_id` is non-empty and references a valid zone.
3. **Exclude all lots in rows with no zone assignment** from the selectable/bookable pool.
4. Do NOT fall back to a default zone. An empty `zone_id` means the row is explicitly
   unconfirmed/not-for-sale.

Code comment required at the C10 filter point:

```php
// C10 ENFORCEMENT CONTRACT (2026-05-30, V1 model):
// RV rows with empty/absent zone_id are UNAVAILABLE to customers.
// Availability is set at ROW level in V1 — all lots in an unassigned row
// are withheld from the customer's lot selection UI.
// See: docs/c10-contracts.md
```

---

## V2 BACKLOG — Per-Lot Painting (deferred from V1)

The following features were considered for V1 but deferred to unblock the
June 12, 2026 test-ready milestone:

### Per-lot zone assignment

- Admin clicks individual lots to assign them to zones
- Useful when a row contains lots from multiple zones (e.g., premium corner
  spots vs. standard interior lots in the same physical row)
- V1 assigns zones at row level only — all lots in a row share one zone

### Per-lot color dots

- Visual indicator of zone membership at lot granularity
- V1 uses row card left-border color as the zone indicator (lightweight,
  row-level signal)

### Per-zone Avail Qty (admin-entered cap)

- Separate inventory cap independent of configured lots
- E.g., "Red Lot has 24 physical lots but cap sales at 20 to keep 4 reserved"
- V1 uses row lot count as inventory truth (computed from First/Last label ranges)

### Bulk-with-zones mode

- Quantity per zone without the row builder
- E.g., "I have 40 RV lots in 2 zones: 20 Red, 20 Blue" without naming each lot
- V1 has only: Bulk (no zones) and Mapped (rows with zone dropdowns)

### Sub-row zone assignment

- Within a back-to-back row, Top Side and Bottom Side could belong to different zones
- V1: the entire row (both sides) shares one zone

---

## Historical Note — V1 Transition (2.3.21 → 2.3.22)

In version 2.3.21, a per-lot painting model was briefly shipped:
- `_en_rv_lot_zone_assignments` post-meta tracked individual lot→zone mappings
- Lots without a saved assignment showed a grey dot (#9CA3AF) and were unavailable
- A publish warning appeared when unassigned lots existed

In version 2.3.22 this was removed in favor of the simpler row-level zone model.
The `_en_rv_lot_zone_assignments` meta key is no longer written or read by the plugin.
Existing reservations with that key stored are unaffected — it will simply be ignored.

---

## Related Files

| File | Role |
|---|---|
| `assets/js/admin.js` | `rvUpdateRowZoneIndicator()`, `rvAddRow()`, `updateRvInventoryDisplay()` |
| `templates/admin/reservation-editor/_section-rv.php` | Row Zone dropdown, row builder init |
| `admin/class-eem-reservation-editor-page.php` | `zone_id` field in `_en_rv_rows` save |
| `assets/css/admin.css` | `.eem-zone-color-swatch` (still used in zones table) |
| `.mockups/edit_reservation_page.html` | Visual reference: zone dropdown on row cards |
