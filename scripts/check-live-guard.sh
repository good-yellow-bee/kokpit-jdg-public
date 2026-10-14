#!/bin/sh
set -eu
vendor/bin/phpunit --filter LiveGuard "$@" || exit 3
output=$(mktemp)
trap 'rm -f "$output"' EXIT HUP INT TERM
status=0
CONNECTORS_ENABLED=0 vendor/bin/phpunit --testsuite live >"$output" 2>&1 || status=$?
cat "$output"
[ "$status" -eq 2 ] && grep -Fxq 'Live tests require CONNECTORS_ENABLED=1.' "$output" || exit 4
printf '%s\n' 'Live guard OK: unit tests passed; disabled-connectors refusal verified (exit 2).'
