#!/usr/bin/env bash
#
# tests/smoke/run-all.sh — run every smoke script in this directory.
#
# Established in C6.5.A (CLEANUP entry #17) — moves the pre-C6.5 ad-hoc
# `/tmp/c?-smoke.php` files into a versioned tests/smoke/ directory and
# wraps them in a single-command runner. Exit code is 0 only when every
# smoke passes; any failure flips the exit to 1.
#
# Usage:
#   bash tests/smoke/run-all.sh              # run every *-smoke.php
#   bash tests/smoke/run-all.sh c7x-build    # run only smokes whose filename contains "c7x-build"
#
# The optional first argument is a filename substring filter — run a single
# smoke (or a related family) while iterating, instead of the full suite.
# This keeps every smoke run going through ONE pre-approved command so it
# never triggers a fresh permission prompt. Do NOT hand-roll inline `php -r`
# or `wp eval-file` invocations to run a smoke — route them through here.
#
# Environment overrides:
#   EEM_PHP    Full path to a PHP CLI binary.   (default: Local php 8.2.29)
#   EEM_WPCLI  Full path to wp-cli.phar.        (default: Local wp-cli)
#   EEM_SITE   Absolute path to the WP install. (default: en-event-manager Local site)
#
# Add a new smoke by dropping it into tests/smoke/ — the runner auto-
# discovers any `*-smoke.php` glob match. Tests are expected to:
#   - assume WordPress is already bootstrapped (run via wp eval-file),
#   - print a final line of the form "=== RESULT: N passed, M failed ===",
#   - exit 0 regardless of internal failure (aggregation happens here).
#

set -u

EEM_PHP="${EEM_PHP:-/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"
EEM_WPCLI="${EEM_WPCLI:-/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar}"
EEM_SITE="${EEM_SITE:-/Users/whitneymitchell/Local Sites/en-event-manager/app/public}"

if [[ ! -x "$EEM_PHP" ]]; then
    echo "FATAL: PHP binary not found or not executable at $EEM_PHP" >&2
    echo "       Override with EEM_PHP=/path/to/php bash tests/smoke/run-all.sh" >&2
    exit 2
fi

if [[ ! -f "$EEM_WPCLI" ]]; then
    echo "FATAL: wp-cli.phar not found at $EEM_WPCLI" >&2
    echo "       Override with EEM_WPCLI=/path/to/wp-cli.phar bash tests/smoke/run-all.sh" >&2
    exit 2
fi

if [[ ! -d "$EEM_SITE" ]]; then
    echo "FATAL: WordPress site root not found at $EEM_SITE" >&2
    echo "       Override with EEM_SITE=/path/to/wp/install bash tests/smoke/run-all.sh" >&2
    exit 2
fi

SMOKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOTAL_PASS=0
TOTAL_FAIL=0
FAILED_FILES=()

FILTER="${1:-}"

shopt -s nullglob
ALL_SMOKES=( "$SMOKE_DIR"/*-smoke.php )
shopt -u nullglob

if [[ ${#ALL_SMOKES[@]} -eq 0 ]]; then
    echo "FATAL: no *-smoke.php files found in $SMOKE_DIR" >&2
    exit 2
fi

if [[ -n "$FILTER" ]]; then
    SMOKES=()
    for smoke in "${ALL_SMOKES[@]}"; do
        if [[ "$(basename "$smoke")" == *"$FILTER"* ]]; then
            SMOKES+=( "$smoke" )
        fi
    done
    if [[ ${#SMOKES[@]} -eq 0 ]]; then
        echo "FATAL: no *-smoke.php files matching \"$FILTER\" in $SMOKE_DIR" >&2
        exit 2
    fi
else
    SMOKES=( "${ALL_SMOKES[@]}" )
fi

echo "Running ${#SMOKES[@]} smoke files against $EEM_SITE"
echo ""

for smoke in "${SMOKES[@]}"; do
    name="$(basename "$smoke")"
    # Run from the WP install root so wp-cli auto-detects.
    output=$( cd "$EEM_SITE" && "$EEM_PHP" "$EEM_WPCLI" eval-file "$smoke" 2>&1 )
    result_line=$( echo "$output" | grep -E '^=== RESULT: [0-9]+ passed, [0-9]+ failed ===' | tail -1 )

    if [[ -z "$result_line" ]]; then
        echo "  ✗ ${name} — no RESULT line emitted (smoke errored or has no aggregator)"
        FAILED_FILES+=( "$name" )
        TOTAL_FAIL=$(( TOTAL_FAIL + 1 ))
        continue
    fi

    pass=$( echo "$result_line" | sed -E 's/.*RESULT: ([0-9]+) passed.*/\1/' )
    fail=$( echo "$result_line" | sed -E 's/.*passed, ([0-9]+) failed.*/\1/' )
    TOTAL_PASS=$(( TOTAL_PASS + pass ))
    TOTAL_FAIL=$(( TOTAL_FAIL + fail ))

    if [[ "$fail" -eq 0 ]]; then
        echo "  ✓ ${name} — ${pass}/${pass}"
    else
        echo "  ✗ ${name} — ${pass} passed, ${fail} failed"
        FAILED_FILES+=( "$name" )
    fi
done

echo ""
echo "=========================================="
echo "TOTAL: ${TOTAL_PASS} passed, ${TOTAL_FAIL} failed"
echo "=========================================="

if [[ "$TOTAL_FAIL" -ne 0 ]]; then
    echo ""
    echo "Failed smokes:"
    for f in "${FAILED_FILES[@]}"; do
        echo "  - $f"
    done
    exit 1
fi

exit 0
