#!/usr/bin/env bash
# Equine Event Manager — smoke test runner
# Self-locating: works regardless of caller's CWD.
# Usage: bash tests/smoke/run-all.sh [-v|--verbose] [substring-filter]
#   -v / --verbose   print every assertion line, not just the RESULT summary
#
# Exit code 0 only when every smoke file passes.

set -uo pipefail

WP_CLI="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar"
PHP_BIN="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
# The plugin repo is symlinked into this Local site; WP-CLI needs the WP root.
WP_PATH="/Users/whitneymitchell/Local Sites/en-event-manager/app/public"

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# Parse args: -v/--verbose flag (any position) + optional positional filter.
VERBOSE=0
FILTER=""
for ARG in "$@"; do
	case "$ARG" in
		-v|--verbose) VERBOSE=1 ;;
		*) FILTER="$ARG" ;;
	esac
done

# Collect smoke files: any *-smoke.php in this dir, plus known fix-*.php.
# Portable (no mapfile — macOS ships bash 3.2): read NUL-safe via while loop.
SMOKE_FILES=()
while IFS= read -r SMOKE_FILE; do
	SMOKE_FILES+=( "$SMOKE_FILE" )
done < <( find "$SCRIPT_DIR" -maxdepth 1 -type f \( -name '*-smoke.php' -o -name 'fix-*.php' \) | sort )

PASS=0
FAIL=0
FAILED_FILES=()

for FILE in "${SMOKE_FILES[@]}"; do
	if [[ -n "$FILTER" && "$FILE" != *"$FILTER"* ]]; then
		continue
	fi
	BASENAME="$( basename "$FILE" )"
	# Run each smoke file through wp-cli eval-file so $wpdb etc. are bootstrapped.
	OUTPUT="$( "$PHP_BIN" "$WP_CLI" --path="$WP_PATH" eval-file "$FILE" 2>&1 )"
	STATUS=$?
	if [[ "$VERBOSE" -eq 1 ]]; then
		echo "===== $BASENAME ====="
		echo "$OUTPUT"
		echo
	fi
	# Find the "... N passed, M failed ..." summary wherever it appears — some
	# smokes print a trailing WP_CLI::success line after it, so tail -n 1 alone
	# misses it. A smoke FAILS if it exited non-zero (WP_CLI::error), any summary
	# reports a non-zero failed count, or the output has a PHP fatal/parse error.
	SUMMARY="$( echo "$OUTPUT" | grep -E 'passed, [0-9]+ failed' | tail -n 1 )"
	[[ -z "$SUMMARY" ]] && SUMMARY="$( echo "$OUTPUT" | tail -n 1 )"
	if [[ "$STATUS" -ne 0 ]] \
		|| echo "$OUTPUT" | grep -qiE 'passed, [1-9][0-9]* failed' \
		|| echo "$OUTPUT" | grep -qiE 'Fatal error|Parse error|Uncaught'; then
		FAIL=$(( FAIL + 1 ))
		FAILED_FILES+=( "$BASENAME" )
		echo "FAIL  $BASENAME — $SUMMARY"
	else
		PASS=$(( PASS + 1 ))
		[[ "$VERBOSE" -eq 0 ]] && echo "PASS  $BASENAME — $SUMMARY"
	fi
done

echo "------------------------------------------------------------"
echo "Files: $(( PASS + FAIL ))   Passed: $PASS   Failed: $FAIL"
if [[ "$FAIL" -gt 0 ]]; then
	printf 'Failing file: %s\n' "${FAILED_FILES[@]}"
	exit 1
fi
exit 0
