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

# Collect smoke files: any *-smoke.php in this dir, plus known fix-*.php
mapfile -t SMOKE_FILES < <( find "$SCRIPT_DIR" -maxdepth 1 -type f \( -name '*-smoke.php' -o -name 'fix-*.php' \) | sort )

PASS=0
FAIL=0
FAILED_FILES=()

for FILE in "${SMOKE_FILES[@]}"; do
	if [[ -n "$FILTER" && "$FILE" != *"$FILTER"* ]]; then
		continue
	fi
	BASENAME="$( basename "$FILE" )"
	# Run each smoke file through wp-cli eval-file so $wpdb etc. are bootstrapped.
	OUTPUT="$( "$PHP_BIN" "$WP_CLI" eval-file "$FILE" 2>&1 )"
	if [[ "$VERBOSE" -eq 1 ]]; then
		echo "===== $BASENAME ====="
		echo "$OUTPUT"
		echo
	fi
	SUMMARY="$( echo "$OUTPUT" | tail -n 1 )"
	if echo "$SUMMARY" | grep -qE '0 failed'; then
		PASS=$(( PASS + 1 ))
		[[ "$VERBOSE" -eq 0 ]] && echo "PASS  $BASENAME — $SUMMARY"
	else
		FAIL=$(( FAIL + 1 ))
		FAILED_FILES+=( "$BASENAME" )
		echo "FAIL  $BASENAME — $SUMMARY"
	fi
done

echo "------------------------------------------------------------"
echo "Files: $(( PASS + FAIL ))   Passed: $PASS   Failed: $FAIL"
if [[ "$FAIL" -gt 0 ]]; then
	printf 'Failing file: %s\n' "${FAILED_FILES[@]}"
	exit 1
fi
exit 0
