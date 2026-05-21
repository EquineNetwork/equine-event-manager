# Base Plugin Extension Contract

This document defines the base-plugin concepts and extension seams required before building an interactive stall map add-on for Equine Event Manager.

The goal is to keep the main plugin as the reservation and payment engine while making it safe for a future add-on to provide exact-stall selection, hold management, and interactive map rendering.

## Purpose

The base plugin must support two realities:

- standard reservations that sell stalls by quantity
- future reservations that sell exact inventory units through an add-on

The base plugin should not know about map geometry, polygons, or section rendering. It should only provide a stable reservation pipeline that allows an add-on to:

- replace the stall selection UI
- contribute normalized selection data
- validate exact-inventory selections
- influence totals
- store exact selection metadata alongside the normal reservation order flow

## Guiding Principles

1. The base plugin remains the source of truth for checkout, payment, order numbers, and receipts.
2. The add-on owns map definitions, region geometry, hold logic, and exact-inventory availability.
3. A reservation uses one stall selection mode at a time.
4. Quantity-based and exact-inventory reservations must share the same checkout pipeline.
5. The base plugin should interact with exact selections through normalized data, not map-specific structures.

## Reservation Concepts

The following concepts should exist in the base plugin before add-on development begins.

### Stall Selection Mode

Each reservation should expose a stall selection mode.

Suggested values:

- `quantity`
- `exact_map`

Initial shipping behavior in the base plugin can remain `quantity` only. The `exact_map` value exists so the concept is stable before the add-on is implemented.

### Stall Inventory Mode

Each reservation should expose how stall inventory is enforced.

Suggested values:

- `aggregate`
- `exact_units`

Recommended pairings:

- standard reservations: `quantity + aggregate`
- mapped reservations: `exact_map + exact_units`

### Billable Quantity

The base plugin should distinguish between:

- customer selection model
- billable quantity model

In quantity mode, the billable quantity comes directly from `stall_qty`.

In exact-map mode, the billable quantity is derived from selected inventory units supplied by the add-on.

## Responsibilities

### Base Plugin Responsibilities

The base plugin is responsible for:

- reservation configuration
- availability windows and default pricing
- customer form shell
- billing details
- payment processing
- order number assignment
- receipt email generation
- core order persistence
- extension hooks and normalized reservation lifecycle

### Add-On Responsibilities

The future add-on will be responsible for:

- venue map storage
- section or tab management
- region geometry
- exact inventory availability
- temporary holds
- exact selection UI
- selected region persistence
- admin tools for blocked and reserved exact units

## Canonical Reservation Modes

The base plugin should treat these modes as first-class concepts even if only one is active today.

### Quantity Mode

- Uses the existing quantity UI
- Uses aggregate inventory checks
- Persists order rows as it does today

### Exact Map Mode

- Replaces the quantity UI with add-on-provided selection UI
- Ignores aggregate stall inventory in favor of exact-unit availability
- Derives billable quantity from selected units
- Persists exact-unit metadata through add-on hooks

## Canonical Submission Shape

The current submission structure is quantity-oriented. The base plugin should evolve toward a normalized structure that can support both modes.

Conceptual submission payload:

```php
array(
	'stall_selection_mode'    => 'quantity',
	'stall_qty'               => 0,
	'tack_stall_qty'          => 0,
	'selected_stall_units'    => array(),
	'selected_stall_labels'   => array(),
	'stall_billable_quantity' => 0,
);
```

Notes:

- `stall_qty` remains valid for legacy and standard reservations.
- `selected_stall_units` will be populated by the future add-on.
- `stall_billable_quantity` becomes the normalized quantity used for pricing and inventory logic.

The base plugin does not need to fully populate these values yet, but its parsing and filters should allow them to exist.

## Canonical Order Metadata

The base plugin should define a normalized order metadata shape that can be extended by add-ons.

Conceptual payload:

```php
array(
	'stall_selection_mode' => 'quantity',
	'selected_units'       => array(),
	'selection_summary'    => '',
);
```

Recommended behavior:

- the core plugin continues inserting its normal reservation rows
- add-ons may store related detail rows in their own tables
- the core plugin should provide filterable order notes and metadata payloads so add-ons can attach human-readable summaries

## Required Base Plugin Refactors

The following refactors should happen in the base plugin before the add-on starts.

### Reservation Configuration

Add reservation meta for:

- `stall_selection_mode`
- optionally `stall_inventory_mode`

Add helper methods such as:

- `get_stall_selection_mode( $data )`
- `get_stall_inventory_mode( $data )`
- `is_exact_stall_selection_enabled( $data )`

### Stall UI Rendering

The stall selection UI should no longer be hard-coded inline in the main front-end renderer.

Instead:

- extract stall selection rendering into a dedicated method
- resolve selection mode before rendering
- allow add-ons to replace or augment the default quantity UI

### Submission Parsing

Stall-related POST parsing should be centralized in one method so add-ons can influence normalized selection data without duplicating the full form parsing process.

### Selection Detection

The logic for "does this submission contain a stall reservation?" should be centralized in one method and made filterable.

The base plugin should not permanently assume that stall selection exists only when `stall_qty + tack_stall_qty > 0`.

### Totals

Stall totals should derive from a normalized billable quantity, not directly from raw quantity fields.

### Order Persistence

The order insert flow should expose before and after hooks and allow add-ons to attach exact-inventory records after the main order row is created.

## Required Hook and Filter Contract

These hooks and filters should be added to the base plugin as the minimum extension surface for the future add-on.

Hook names can still be adjusted before implementation, but this document defines the intended contract.

### Rendering Hooks

- `eem_stall_selection_mode`
- `eem_before_stall_selection_ui`
- `eem_render_stall_selection_ui`
- `eem_after_stall_selection_ui`

Purpose:

- resolve whether the reservation is using quantity or exact-map mode
- allow an add-on to replace the default stall selector

### Submission Hooks

- `eem_submission_data`
- `eem_has_stall_selection`
- `eem_stall_billable_quantity`

Purpose:

- allow add-ons to inject normalized selected-unit data
- let add-ons define what counts as a stall selection
- derive billable quantity from exact selections

### Validation Hooks

- `eem_validate_submission_errors`
- `eem_validate_stall_selection_errors`

Purpose:

- allow add-ons to reject invalid exact selections
- allow hold-validation errors before payment is finalized

### Status and Inventory Hooks

- `eem_reservation_status`
- `eem_stall_inventory_remaining`

Purpose:

- allow add-ons to override aggregate inventory logic when exact inventory is in use
- allow status payloads to reflect exact availability

### Pricing Hooks

- `eem_submission_totals`
- `eem_stall_unit_price`
- `eem_stall_subtotal`

Purpose:

- allow add-ons to derive totals from exact selected units
- support future per-stall price overrides

### Persistence Hooks

- `eem_before_insert_reservation_orders`
- `eem_after_insert_reservation_orders`
- `eem_order_notes`
- `eem_order_meta_payload`

Purpose:

- allow add-ons to attach exact-stall details after core order creation
- extend notes and metadata without replacing core inserts

### Front-End Payload Hooks

- `eem_reservation_frontend_payload`

Purpose:

- expose a normalized front-end data object for future add-ons
- avoid hard-coding data dependencies into templates

## Base Plugin Readiness Checklist

The base plugin is ready for add-on implementation when the following are true:

- reservation settings include a stall selection mode concept
- stall selection mode is resolved through helper methods
- stall UI is rendered through a dedicated, extensible method
- submission parsing is centralized and filterable
- stall-selection detection is no longer hard-coded to quantity fields alone
- billable stall quantity is derived through a dedicated method or filter
- totals are filterable at the submission level
- order inserts expose lifecycle hooks
- order notes and metadata are extendable
- the core plugin does not need to know anything about map geometry

## Decisions To Lock Before Coding

The following decisions should be treated as part of the contract.

1. A reservation uses either `quantity` mode or `exact_map` mode, not both at once.
2. In `exact_map` mode, aggregate stall inventory in core is treated as informational only or ignored entirely.
3. Core reservation rates remain the default price source unless an add-on intentionally overrides pricing.
4. Add-ons are allowed to block checkout when exact-inventory validation or hold validation fails.
5. Selected exact units should be available for admin display and receipt summaries.
6. The base plugin should remain map-agnostic and should not store geometry-related knowledge.

## Suggested Implementation Order

1. Add reservation meta for stall selection mode.
2. Add helper methods for selection mode and inventory mode resolution.
3. Extract stall selection UI into a dedicated method.
4. Add render hooks around the stall UI.
5. Centralize stall submission parsing.
6. Centralize and filter stall-selection detection.
7. Centralize and filter billable stall quantity.
8. Add validation hooks.
9. Add totals hooks.
10. Add order persistence hooks.
11. Add order notes and metadata hooks.
12. Document the final hook signatures in code comments.

## Non-Goals For The Base Plugin

The following are explicitly out of scope for the base plugin foundation work:

- map editor UI
- geometry storage
- section and tab authoring
- exact-unit hold tables
- interactive front-end map rendering
- prior-year owner overlays
- real-time collaborative editing

Those responsibilities belong to the future add-on.

## Success Criteria

This foundation work is successful when the base plugin can support a future add-on that:

- replaces the stall quantity UI with exact-stall selection
- validates selected exact units before payment completion
- calculates totals using normalized billable quantity
- persists exact selected units without rewriting the core payment flow
- leaves standard quantity-based reservations unchanged
