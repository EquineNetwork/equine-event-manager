# App Platform Readiness Checklist

This checklist tracks the work needed to keep Equine Event Manager fully available in WordPress while preparing it to support a future role-based mobile app.

The goal is not to replace WordPress. The goal is to make WordPress one supported channel of the product and to prevent more core logic from being trapped in shortcode rendering or WordPress-only request flows.

## Current State

The current codebase is still WordPress-first:

- plugin bootstrap and hook wiring live in [equine-event-manager.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/equine-event-manager.php:1) and [includes/class-equine-event-manager.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/includes/class-equine-event-manager.php:1)
- reservation setup is stored as a WordPress custom post type in [includes/class-equine-event-manager-reservations-cpt.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/includes/class-equine-event-manager-reservations-cpt.php:1)
- customer booking, pricing, checkout, and invoice flows are concentrated in [public/class-equine-event-manager-shortcodes.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/public/class-equine-event-manager-shortcodes.php:1)
- admin workflows and payment/refund/export actions are concentrated in [admin/class-equine-event-manager-admin.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/admin/class-equine-event-manager-admin.php:1)
- reservation order persistence is handled through WordPress-managed tables in [includes/class-equine-event-manager-activator.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/includes/class-equine-event-manager-activator.php:1) and [includes/class-equine-event-manager-orders-repository.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/includes/class-equine-event-manager-orders-repository.php:1)

## Success Criteria

When this checklist is complete:

- WordPress remains a first-class supported experience
- the plugin uses shared service classes for business logic
- app-safe REST APIs exist for the main customer and admin workflows
- auth and roles support customer, admin, and staff experiences
- WordPress and the app can read and write the same event data
- live event operations can stay in sync across desktop and mobile

## Phase 1: Architecture and Data Contracts

- [ ] Document the core product entities: reservation setup, event, order, invoice, payment, refund, user, role
- [ ] Define the source of truth for each entity and field
- [ ] Document the canonical reservation submission shape for future app and API clients
- [ ] Document the canonical order payload shape returned to admin and mobile clients
- [ ] Document role definitions and permissions for `customer`, `admin`, `staff`, and `super_admin`
- [ ] Identify every place the code depends on shortcode request flow, `admin_post`, `wp_ajax`, or direct `$_POST` handling

## Phase 2: Extract Shared Business Logic

- [ ] Introduce an `includes/services/` layer for framework-aware but UI-independent service classes
- [ ] Extract reservation status, availability, and validation logic from [public/class-equine-event-manager-shortcodes.php](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/public/class-equine-event-manager-shortcodes.php:1)
- [ ] Extract pricing and fee calculation into a dedicated service class
- [ ] Extract reservation submission parsing into a dedicated service class
- [ ] Extract order creation and payment state transitions into dedicated service classes
- [ ] Extract invoice lookup and invoice payment logic into dedicated service classes
- [ ] Extract reusable admin actions such as refunds, exports, and invoice sending out of page-controller methods where practical
- [ ] Keep WordPress screens and shortcodes calling the new service layer so behavior stays consistent

## Phase 3: API-First Readiness

- [ ] Add a plugin REST controller namespace for app-safe endpoints
- [ ] Add authenticated endpoints for current-user profile and role lookup
- [ ] Add endpoints for event list and event detail
- [ ] Add endpoints for reservation availability and pricing preview
- [ ] Add endpoints for reservation/order creation
- [ ] Add endpoints for invoice payment lookup and payment submission
- [ ] Add endpoints for admin order search, detail, refund, and status actions
- [ ] Add endpoints for reservation setup read/write operations needed by future app-based admins
- [ ] Standardize JSON response formats and error payloads
- [ ] Add capability and role checks to every endpoint

## Phase 4: Auth, Identity, and Sync

- [ ] Decide whether mobile auth is WordPress-user-based in phase 1 or externally managed
- [ ] Define token-based auth requirements for mobile clients
- [ ] Define how app roles map to WordPress capabilities
- [ ] Add audit logging for admin-side mutation actions
- [ ] Add timestamps or version markers needed for reliable sync
- [ ] Add a near-real-time refresh strategy for live event screens
- [ ] Plan websocket or event-based realtime only after core API flows are stable

## Phase 5: WordPress Compatibility Guardrails

- [ ] Preserve shortcode-based booking while the API layer is introduced
- [ ] Preserve current WordPress admin workflows during service extraction
- [ ] Avoid putting new core business rules directly into shortcode rendering methods
- [ ] Avoid putting new core mutation logic directly into `admin_post_*` handlers
- [ ] Keep CPT/meta storage compatible unless there is a deliberate migration plan
- [ ] Add migration notes for any schema or metadata changes required for app support

## Phase 6: Mobile App MVP Readiness

- [ ] Define the minimum mobile customer experience: login, event list, reservation flow, payment, reservation history
- [ ] Define the minimum mobile admin experience: login, dashboard, order lookup, reservation overview, refunds, operational check-in tools
- [ ] Define the minimum mobile staff experience: event lookup, order lookup, check-in, occupancy awareness
- [ ] Identify which admin workflows stay WordPress-only in MVP
- [ ] Define the screen-level data each app role needs from the API

## Phase 7: Testing and Launch Safety

- [ ] Add service-layer unit coverage for pricing, fees, validation, and order-building rules
- [ ] Add API tests for critical customer and admin endpoints
- [ ] Add regression coverage for WordPress booking and admin flows after refactors
- [ ] Add test data fixtures for reservation setups, orders, invoices, and refunds
- [ ] Validate idempotency for payment and order submission paths
- [ ] Validate role-based access control for customer, admin, and staff actions
- [ ] Run event-day sync testing with one desktop WordPress user and one mobile-style client against the same dataset

## Recommended First Implementation Slice

This is the lowest-risk place to begin in code:

- [ ] Create a pricing service and move fee and total calculations out of shortcode rendering
- [ ] Create a reservation submission parser service
- [ ] Create an order creation service that can be reused by both WordPress and future API requests
- [ ] Add a REST controller scaffold with a read-only health or current-user endpoint
- [ ] Add a read-only reservation detail endpoint for app consumption

## Notes

- Because the plugin is only on a test site right now, this is the right time to make structural changes before real user data and habits form around the WordPress-only architecture.
- This checklist should stay focused on app-platform readiness, not general feature expansion.
- The separate extension contract in [docs/base-plugin-extension-contract.md](/Users/whitneymitchell/Library/Mobile%20Documents/com~apple~CloudDocs/Projects/Equine%20Event%20Manager/docs/base-plugin-extension-contract.md:1) should continue to guide add-on compatibility work in parallel.
