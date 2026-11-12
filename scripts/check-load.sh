#!/usr/bin/env bash
# Refuse heavy work on a machine that is already busy. The companion to check-disk.sh, and grounded the same way:
# on 2026-11-12 this repository's E2E suite and another project's PHPUnit suite ran at once on an 8 GB machine, and
# the measured cost was the whole machine — the E2E stack alone (sixteen `php -S` workers ≈650 MB, two browser workers
# with their headless Chromium ≈900 MB, Vite ≈76 MB) took swap from 3.4 GB to 6.3 GB with 187 MB of RAM unused.
# Every "flaky under full-suite load" and "starved server" entry in CHANGELOG.md was diagnosed as exactly that state.
#
# Two refusals and one measurement:
#   1. Another test suite is already running. A suite that shares a machine with another produces failures that are
#      about the machine, and the hours then go into investigating code that was never wrong.
#   2. The kernel reports critical memory pressure. No threshold is invented here: `kern.memorystatus_vm_pressure_level`
#      is Apple's own verdict (1 normal, 2 warn, 4 critical), and at critical the kernel kills processes — a suite
#      started there is killed rather than failed, which reads as a failing test.
#   Warn-level pressure is reported, never refused: on a small machine it is the ordinary state, and a guard that
#   refuses the ordinary state is a guard somebody switches off.
#
# Read-only. It starts nothing, kills nothing and deletes nothing.
#
# Usage: scripts/check-load.sh [api-port]            (default port: 8011, or $API_PORT)
#        E2E_IGNORE_LOAD=1 scripts/check-load.sh      skips both refusals, deliberately and visibly
#
# The three CHECK_LOAD_* variables below exist so scripts/test-check-load.sh can prove every branch, including the
# critical-pressure refusal, which cannot be provoked on a real kernel on demand. Without them the clean-machine cases
# could never be asserted anywhere: this machine runs suites, and so does CI. Each one announces itself on stderr, so a
# real run cannot be fooled quietly, and none of them is a smaller bypass than E2E_IGNORE_LOAD already is.
set -euo pipefail

API_PORT="${1:-${API_PORT:-8011}}"
IGNORE="${E2E_IGNORE_LOAD:-0}"
PS_FIXTURE="${CHECK_LOAD_PS:-}"

# One pattern list, whichever source it is read from — so the test cannot pass against a second, kinder list. The
# leading bracket in each alternative keeps the pattern from matching the command line doing the matching.
SUITES_RE="[p]laywright test|[v]endor/phpunit/phpunit|[a]rtisan test|[v]endor/bin/pest|[p]hp -S 127.0.0.1:${API_PORT}"

if [ -n "$PS_FIXTURE" ]; then
  echo "⚠ process list read from $PS_FIXTURE, not from this machine (a test is driving this guard)." >&2
  BUSY="$(grep -E "$SUITES_RE" "$PS_FIXTURE" 2>/dev/null || true)"
else
  BUSY="$(pgrep -fl "$SUITES_RE" 2>/dev/null || true)"
fi

if [ -n "$BUSY" ]; then
  COUNT="$(printf '%s\n' "$BUSY" | grep -c . || true)"
  echo "✗ ${COUNT} process(es) from another test run are already on this machine:" >&2
  printf '%s\n' "$BUSY" | while IFS= read -r line; do echo "    ${line:0:120}" >&2; done
  echo "  Wait for it, or queue behind it:" >&2
  echo "    until ! pgrep -f '[v]endor/phpunit/phpunit' >/dev/null; do sleep 30; done && bash scripts/e2e.sh" >&2
  echo "  A leaked 'php -S' on port ${API_PORT} from an earlier run counts here too — see scripts/e2e.sh on orphans." >&2
  if [ "$IGNORE" = "1" ]; then
    echo "  E2E_IGNORE_LOAD=1 — continuing anyway." >&2
  else
    exit 1
  fi
fi

if [ -n "${CHECK_LOAD_PRESSURE:-}" ]; then
  echo "⚠ memory pressure taken as ${CHECK_LOAD_PRESSURE}, not from the kernel (a test is driving this guard)." >&2
  PRESSURE="$CHECK_LOAD_PRESSURE"
else
  PRESSURE="$(sysctl -n kern.memorystatus_vm_pressure_level 2>/dev/null || echo 1)"
fi

if [ -n "${CHECK_LOAD_TOTAL_GB:-}" ]; then
  echo "⚠ total memory taken as ${CHECK_LOAD_TOTAL_GB} GB, not from the hardware (a test is driving this guard)." >&2
  TOTAL_GB="$CHECK_LOAD_TOTAL_GB"
else
  TOTAL_GB="$(sysctl -n hw.memsize 2>/dev/null | awk '{printf "%d", $1 / 1073741824}' || echo 0)"
fi

if [ "$PRESSURE" -ge 4 ]; then
  echo "✗ The kernel reports critical memory pressure. A suite started now is killed, not failed." >&2
  echo "  Close what is holding memory and run it again; nothing here is wrong with the code." >&2
  if [ "$IGNORE" = "1" ]; then
    echo "  E2E_IGNORE_LOAD=1 — continuing anyway." >&2
  else
    exit 1
  fi
fi

[ "$PRESSURE" -ge 2 ] && echo "⚠ Memory pressure is at warn level; the run will be slower and may flake."

if [ "$TOTAL_GB" -gt 0 ] && [ "$TOTAL_GB" -le 8 ] \
  && [ -z "${E2E_WORKERS:-}" ] && [ -z "${PHP_CLI_SERVER_WORKERS:-}" ]; then
  echo "⚠ ${TOTAL_GB} GB of RAM with the default worker counts (2 browser × 16 server ≈ 1.6 GB measured)."
  echo "  Halving both keeps the 8-servers-per-browser-worker ratio that scripts/e2e.sh documents:"
  echo "    E2E_WORKERS=1 PHP_CLI_SERVER_WORKERS=8 bash scripts/e2e.sh"
fi

echo "✓ No other test run; memory pressure ${PRESSURE} (1 normal, 2 warn, 4 critical)"
