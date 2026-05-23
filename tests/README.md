# tests/

Versioned test code for the Equine Event Manager plugin. Established in C6.5.A
(Phase 3 professionalization sprint, CLEANUP entry #17) — replaces the
pre-C6.5 convention of dropping ad-hoc smoke scripts into `/tmp/` (which were
lost on machine reboot and not reviewable across machines).

## Layout

```
tests/
├── README.md                ← this file
└── smoke/
    ├── run-all.sh           ← single-command runner; aggregates exit code
    ├── c4a-smoke.php        ← C4.A: Reservations list repo + page controller
    ├── c4c-smoke.php        ← C4.C: row actions + Email Customers modal
    ├── c4d-smoke.php        ← C4.D: bulk actions + sort/filter/pagination
    ├── c5a-smoke.php        ← C5.A: Orders list repo + page controller
    ├── c5b-smoke.php        ← C5.B: full table render + CSS additions
    ├── c5c-smoke.php        ← C5.C: row action handlers + Collect button
    └── c5d-smoke.php        ← C5.D: toolbar dispatcher + bulk Refund stub
```

Total at C6.5.A landing: **7 smokes, 273 assertions, baseline green**.

## Smoke convention

Each `*-smoke.php` file is a free-standing script that:

1. Assumes WordPress is already bootstrapped — runs via `wp eval-file`, not
   directly via `php`. The first line checks `function_exists( 'get_option' )`
   and bails if the script was invoked without a WP context.
2. Exercises one chunk's render path + a handful of repo / helper assertions.
3. Prints `  ✓ ` / `  ✗ ` lines per assertion as it goes.
4. Ends with a single line of the form:
   ```
   === RESULT: N passed, M failed ===
   ```
   The runner parses this line via `grep`/`sed`; the format is load-bearing.
5. Exits 0 regardless of internal pass/fail. Aggregation + non-zero exit on
   failure is the runner's job, not the individual smoke's.

## Running

### All smokes

```bash
bash tests/smoke/run-all.sh
```

Output ends with:

```
==========================================
TOTAL: 273 passed, 0 failed
==========================================
```

Exit code is `0` on all-pass, `1` on any failure, `2` on environment problems
(missing PHP binary, wp-cli, or WP site root).

### A single smoke

```bash
PHP=/path/to/php
WPCLI=/path/to/wp-cli.phar
SITE="/path/to/wp/install"
cd "$SITE" && "$PHP" "$WPCLI" eval-file /path/to/tests/smoke/c5d-smoke.php
```

### Environment overrides

The runner defaults to the Local-by-Flywheel install used during Phase 3
development. Override any of these to point at a different install:

| Variable    | Default                                                                                                |
|-------------|--------------------------------------------------------------------------------------------------------|
| `EEM_PHP`   | `/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/...`        |
| `EEM_WPCLI` | `/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar`                     |
| `EEM_SITE`  | `/Users/whitneymitchell/Local Sites/en-event-manager/app/public`                                       |

Example for a different machine:

```bash
EEM_PHP=$(which php) \
EEM_WPCLI=$(which wp) \
EEM_SITE=/var/www/html \
bash tests/smoke/run-all.sh
```

## Adding a new smoke

1. Create `tests/smoke/cNx-smoke.php` (e.g. `c6a-smoke.php` for C6.A).
2. Use this skeleton:

   ```php
   <?php
   /**
    * CNx smoke — what this covers.
    */
   if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
   $pass = 0; $fail = 0; $log = array();
   function ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
       if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
       else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
   }

   echo "\n=== CNx SMOKE ===\n";

   wp_set_current_user( 1 );
   // ... exercise the chunk's surface, calling ok( ... ) per assertion ...

   foreach ( $log as $line ) { echo $line, "\n"; }
   echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
   ```

3. Confirm `bash tests/smoke/run-all.sh` discovers and runs it. No registration
   step required — the runner globs `*-smoke.php` automatically.

4. Commit the new smoke alongside the chunk it exercises.

## What lives in `scripts/` vs `tests/`

| `tests/`                                       | `scripts/`                                  |
|------------------------------------------------|---------------------------------------------|
| Assertion-bearing smoke + (future) unit tests. | Dev-only setup utilities (seeders, fixtures). |
| Safe to re-run any time.                       | May mutate the database; run intentionally. |
| Required to pass before any merge to `main`.   | Not part of the green-gate.                 |

See `scripts/README.md` for the seeder catalogue.

## CLEANUP cross-references

- **Entry #17 (C6.5 Bucket 1 — professionalization):** this directory was the
  primary deliverable of C6.5.A.
- **Entry #20 (recurring dead-code audit):** the smoke gate is what makes the
  audit's removal proposals safe — every C5.5-style cleanup re-runs the full
  suite before merging.
