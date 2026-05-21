# Core Plugin Implementation Checklist

This checklist translates the base plugin extension contract into concrete implementation work for the existing Equine Event Manager codebase.

The purpose of this phase is to prepare the main plugin for a future interactive map and stall builder add-on without changing how standard quantity-based reservations work today.

## Outcome

When this checklist is complete, the base plugin should:

- support a reservation-level stall selection mode concept
- expose stable hooks for a future add-on
- centralize stall rendering, parsing, validation, and pricing decisions
- remain fully backward compatible for standard quantity-based reservations

## Working Rule

Do not build map UI in the base plugin.

This phase is only about:

- introducing the right concepts
- refactoring hard-coded assumptions
- exposing extensibility points

## Phase 1: Reservation Configuration Foundation

### File

- `includes/class-equine-event-manager-reservations-cpt.php`

### Tasks

1. Add a new reservation meta field for stall selection mode.

Suggested field:

- `stall_selection_mode`

Suggested default:

- `quantity`

Suggested initial allowed values:

- `quantity`
- `exact_map`

2. Optionally add a second reservation meta field for inventory mode.

Suggested field:

- `stall_inventory_mode`

Suggested initial allowed values:

- `aggregate`
- `exact_units`

3. Add sanitization for the new fields in reservation save handling.

4. Add defaults for the new fields in the reservation meta defaults array.

5. Add admin descriptions clarifying:

- quantity mode is the standard built-in reservation method
- exact-map mode is reserved for a future add-on

6. Add helper methods in the reservation CPT or shared reservation data layer:

- `get_stall_selection_mode( $data )`
- `get_stall_inventory_mode( $data )`
- `is_exact_stall_selection_enabled( $data )`

### Acceptance Criteria

- every reservation has a persisted stall selection mode
- legacy reservations default safely to `quantity`
- the new fields do not change existing checkout behavior

## Phase 2: Front-End Stall Rendering Refactor

### File

- `public/class-equine-event-manager-shortcodes.php`

### Current Problem

The stall quantity UI is embedded directly inside the main reservation renderer, making it difficult for an add-on to replace.

### Tasks

1. Extract the stall selection rendering into a dedicated method.

Suggested method:

- `render_stall_selection_ui( $reservation_id, $data, $status, $context = array() )`

2. Extract the current quantity-based selector into a dedicated method.

Suggested method:

- `render_quantity_stall_selection_ui( $reservation_id, $data, $status, $context = array() )`

3. Add a method that resolves the current stall selection mode.

Suggested method:

- `get_resolved_stall_selection_mode( $reservation_id, $data )`

4. Add rendering hooks around the stall selection UI.

Suggested hooks and filters:

- `eem_stall_selection_mode`
- `eem_before_stall_selection_ui`
- `eem_render_stall_selection_ui`
- `eem_after_stall_selection_ui`

5. Make the default quantity selector render only when:

- the resolved mode is `quantity`
- no add-on has overridden rendering

### Acceptance Criteria

- the main form no longer hard-codes the stall quantity UI inline
- future add-ons can replace the stall selector without replacing the whole form
- quantity mode still renders exactly as expected

## Phase 3: Submission Parsing Refactor

### File

- `public/class-equine-event-manager-shortcodes.php`

### Current Problem

Submission parsing is currently tied directly to quantity fields such as `stall_qty`.

### Tasks

1. Extract stall-related submission parsing into a dedicated method.

Suggested method:

- `get_stall_submission_payload( $reservation_id, $data )`

2. Keep standard fields in the normalized payload:

- `stall_qty`
- `tack_stall_qty`
- `stall_stay_type`
- `stall_arrival_date`
- `stall_departure_date`

3. Add future-facing normalized fields to the payload shape:

- `stall_selection_mode`
- `selected_stall_units`
- `selected_stall_labels`
- `stall_billable_quantity`

4. Add a filter that allows add-ons to modify the normalized stall payload.

Suggested filter:

- `eem_submission_data`

5. Merge the normalized stall payload back into the main submission array before validation.

### Acceptance Criteria

- all stall submission parsing happens in one place
- the base plugin still works with quantity-only inputs
- add-ons can inject exact-stall data without replacing the full submission parser

## Phase 4: Stall Selection Detection Refactor

### File

- `public/class-equine-event-manager-shortcodes.php`

### Current Problem

The plugin currently assumes stall selection exists only when `stall_qty + tack_stall_qty > 0`.

### Tasks

1. Extract stall-selection detection into a dedicated method.

Suggested method:

- `has_stall_selection( $submission, $data, $status, $reservation_id )`

2. Keep the current quantity-based rule as the default implementation.

3. Add a filter so add-ons can redefine what counts as a stall selection.

Suggested filter:

- `eem_has_stall_selection`

4. Replace all direct inline checks of `stall_qty + tack_stall_qty > 0` where they represent selection detection rather than display-only logic.

### Acceptance Criteria

- the base plugin has one canonical stall-selection detection method
- future exact-map selections can be recognized without fake quantity hacks

## Phase 5: Billable Quantity Refactor

### File

- `public/class-equine-event-manager-shortcodes.php`

### Current Problem

Pricing and validation derive directly from quantity fields.

### Tasks

1. Extract billable stall quantity resolution into a dedicated method.

Suggested method:

- `get_stall_billable_quantity( $submission, $data, $status, $reservation_id )`

2. Default behavior should continue to return:

- `absint( $submission['stall_qty'] ) + absint( $submission['tack_stall_qty'] )`

3. Add a filter for future exact-unit overrides.

Suggested filter:

- `eem_stall_billable_quantity`

4. Update totals logic to use the resolved billable quantity instead of raw inline math.

5. Update required shavings calculations to use the resolved billable stall quantity where appropriate.

### Acceptance Criteria

- totals can be driven by exact selected units in the future
- existing quantity calculations remain unchanged today

## Phase 6: Validation Hooks

### File

- `public/class-equine-event-manager-shortcodes.php`

### Tasks

1. Keep existing core validation for:

- billing fields
- stay dates
- quantity limits
- RV rules
- add-on rules

2. Add a post-core validation filter for add-ons.

Suggested filter:

- `eem_validate_submission_errors`

3. Add a stall-specific validation filter after stall logic runs.

Suggested filter:

- `eem_validate_stall_selection_errors`

4. Ensure a future add-on can:

- validate exact selected units
- reject stale or unavailable stalls
- reject invalid holds

### Acceptance Criteria

- core validation remains authoritative for built-in features
- add-ons can block checkout with exact-inventory validation errors

## Phase 7: Reservation Status and Inventory Filters

### File

- `public/class-equine-event-manager-shortcodes.php`

### Current Problem

Reservation status is currently computed around aggregate inventory assumptions.

### Tasks

1. Add a filter to the reservation status payload.

Suggested filter:

- `eem_reservation_status`

2. Add a filter to stall inventory remaining calculations.

Suggested filter:

- `eem_stall_inventory_remaining`

3. Ensure the status payload remains generic enough for future add-ons.

Examples:

- `stalls_open`
- `stalls_sold_out`
- `stall_inventory_remaining`
- `stalls_bookable`

4. Document that exact-map mode may override aggregate inventory calculations.

### Acceptance Criteria

- add-ons can override availability logic cleanly
- the base status payload remains stable for standard reservations

## Phase 8: Totals and Pricing Filters

### File

- `public/class-equine-event-manager-shortcodes.php`

### Tasks

1. Add a filter around stall unit price resolution.

Suggested filter:

- `eem_stall_unit_price`

2. Add a filter around stall subtotal resolution.

Suggested filter:

- `eem_stall_subtotal`

3. Add a final totals filter after all core totals are computed.

Suggested filter:

- `eem_submission_totals`

4. Ensure future add-ons can:

- override per-stall pricing
- derive stall subtotal from selected units
- add selection summaries without changing payment code

### Acceptance Criteria

- core pricing remains intact by default
- future exact-map pricing overrides can be layered on top safely

## Phase 9: Order Persistence Hooks

### File

- `public/class-equine-event-manager-shortcodes.php`

### Current Problem

Order inserts are tightly coupled to the current core tables and notes format.

### Tasks

1. Add a pre-insert action before order rows are inserted.

Suggested action:

- `eem_before_insert_reservation_orders`

2. Add a post-insert action after core rows are inserted.

Suggested action:

- `eem_after_insert_reservation_orders`

3. Add a filter around generated order notes.

Suggested filter:

- `eem_order_notes`

4. Add a filter for a normalized order metadata payload.

Suggested filter:

- `eem_order_meta_payload`

5. Include basic future-facing metadata in the payload shape:

- `stall_selection_mode`
- `selected_units`
- `selection_summary`

### Acceptance Criteria

- add-ons can save related exact-stall assignment records after core order creation
- the base plugin does not need to store geometry or map-specific data

## Phase 10: Front-End Payload Contract

### File

- `public/class-equine-event-manager-shortcodes.php`

### Tasks

1. Create a normalized front-end payload array for reservation rendering.

Examples:

- reservation identifiers
- selection mode
- dates
- rate information
- inventory summary

2. Add a filter for the payload.

Suggested filter:

- `eem_reservation_frontend_payload`

3. Use this payload as the canonical place where a future add-on can attach:

- selected-mode metadata
- map-assignment identifiers
- map-rendering prerequisites

### Acceptance Criteria

- front-end extensions do not need to scrape markup or rederive reservation state

## Phase 11: Admin Messaging and UX Guardrails

### Files

- `includes/class-equine-event-manager-reservations-cpt.php`
- optional admin assets if needed

### Tasks

1. Make the reservation editor explain the difference between:

- standard quantity-based reservations
- exact-map reservations that depend on an add-on

2. If `exact_map` mode is chosen without the future add-on present, decide the guardrail behavior.

Recommended behavior:

- allow the value to exist
- display a clear admin notice that exact-map functionality requires the add-on
- fall back safely on the front end or prevent publish if needed

3. Avoid exposing map-specific configuration fields in core.

### Acceptance Criteria

- admins understand the concept without expecting unfinished map behavior from core alone

## Phase 12: Code Comments and Internal Documentation

### Files

- relevant PHP files
- `docs/base-plugin-extension-contract.md`

### Tasks

1. Add concise code comments at each new extension seam.

2. Document expected filter signatures in code comments where hooks are introduced.

3. Update the contract doc if final hook names differ from the planned names.

4. Keep the implementation checklist updated if scope changes.

### Acceptance Criteria

- future add-on work does not require re-reading large parts of the codebase to discover extension points

## Suggested Implementation Order

Build in this order:

1. Reservation meta for `stall_selection_mode`
2. Helper methods for selection mode resolution
3. Stall UI extraction and rendering hooks
4. Submission parsing extraction
5. Stall-selection detection extraction
6. Billable quantity extraction
7. Validation hooks
8. Status and inventory hooks
9. Totals and pricing hooks
10. Order persistence hooks
11. Front-end payload filter
12. Admin messaging and documentation

## Definition of Done for Base Plugin

The core plugin is ready for add-on development when:

- reservations store a stall selection mode
- the front-end stall selection area is replaceable
- stall submission parsing is centralized
- billable quantity is no longer hard-coded to raw fields
- validation can be extended by add-ons
- inventory and status logic can be overridden
- totals can be filtered safely
- order insert lifecycle is hookable
- order notes and metadata are extensible
- quantity-based reservations still work exactly as before

## Not Part of This Phase

Do not build these in the base plugin preparation phase:

- map editor screens
- shape drawing tools
- region geometry storage
- exact-inventory hold tables
- drag-and-drop assignment UI
- customer-facing interactive map rendering

Those belong to the future add-on.
