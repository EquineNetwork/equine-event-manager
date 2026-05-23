# scripts/

Dev-only setup utilities for the Equine Event Manager plugin. **Not auto-
invoked, not part of the green-gate, may mutate the database.** Run
intentionally via `wp eval-file` from a WordPress install you don't mind
modifying.

Established in C6.5.A (CLEANUP entry #17). For assertion-bearing smokes /
unit tests, see `tests/` instead.

## Catalogue

| Script                  | Purpose                                                                                       |
|-------------------------|-----------------------------------------------------------------------------------------------|
| `seed-orders.php`       | Inserts 25 orders covering 6 payment-status × 5 type combos. Idempotent — re-running cleans   |
|                         | previously-inserted seed rows (`DELETE WHERE order_number LIKE 'SEED-%'`) before re-inserting. |
|                         | Used during C5 development to populate the Orders list with realistic filter/sort coverage.    |

## Running

```bash
PHP=/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php
WPCLI=/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar
SITE="/Users/whitneymitchell/Local Sites/en-event-manager/app/public"

cd "$SITE" && "$PHP" "$WPCLI" eval-file /Users/whitneymitchell/Projects/equine-event-manager/scripts/seed-orders.php
```

Adjust paths to match your install.

## Adding a new script

1. Create `scripts/<verb>-<thing>.php` (kebab-case, action-first).
2. Make it **idempotent** — re-running must not double-up data.
3. Print a one-line summary at the end (`Inserted N orders`, `Reset X options`, etc.).
4. Add a row to the Catalogue table above.
5. Commit alongside the chunk that needed it.

## Why a separate directory from `tests/`

`tests/` is the green-gate — anything there must pass before merge and must not
mutate persistent state. `scripts/` is the toolbox — utilities you run on
purpose, often once, often against a throwaway database. Keeping them apart
means the test runner (`tests/smoke/run-all.sh`) can glob `*-smoke.php`
without accidentally executing a seeder.
