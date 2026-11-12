#!/usr/bin/env bash
# Proves scripts/check-load.sh refuses each thing it claims to refuse, the way test-no-paid-model.sh proves the
# governance guard. A guard nobody has seen fail is a guard nobody knows works.
#
# Two kinds of case, because one kind alone would leave a hole:
#
#   Real processes — a sleeping script at <tmp>/vendor/phpunit/phpunit has `vendor/phpunit/phpunit` in its own command
#   line, which is exactly what the guard's `pgrep -f` reads on a real run. These prove the wiring to the process
#   table, which no fixture can.
#
#   Injected process lists and readings (CHECK_LOAD_PS, CHECK_LOAD_PRESSURE, CHECK_LOAD_TOTAL_GB) — these prove the
#   branches a real machine cannot be asked to produce: an idle machine (this one runs suites, and so does CI, so the
#   clean path would otherwise be skipped everywhere and proven nowhere), a kernel at critical pressure, and a machine
#   of a size other than the one the test is running on.
#
# Written for bash 3.2, which is what macOS ships and therefore what the development machine runs: no negative array
# subscripts, no associative arrays. A test that needs a newer bash than the machine it protects is a test nobody runs.
#
# Usage: scripts/test-check-load.sh
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GUARD="$ROOT/scripts/check-load.sh"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
FAKES=""; LAST_FAKE=""

cleanup() { for pid in $FAKES; do kill "$pid" 2>/dev/null; done; rm -rf "$TMP"; }
trap cleanup EXIT

# A sleeping process whose command line contains $1 (a relative path), started from $TMP. The launch happens inside a
# command substitution so the parent shell never reaps it and never prints "Terminated" over the test output.
fake() {
  rel="$1"; shift
  mkdir -p "$TMP/$(dirname "$rel")"
  printf '#!/usr/bin/env bash\nsleep 60\n' > "$TMP/$rel"
  chmod +x "$TMP/$rel"
  LAST_FAKE="$( ("$TMP/$rel" "$@" >/dev/null 2>&1 & echo $!) )"
  FAKES="$FAKES $LAST_FAKE"
  sleep 0.5   # let the kernel publish the command line before pgrep reads it
}

kill_last() { [ -n "$LAST_FAKE" ] && kill "$LAST_FAKE" 2>/dev/null; sleep 0.4; }

# check <name> <expected exit> <text that must appear|-> <command...>
check() {
  name="$1"; want_rc="$2"; want_text="$3"; shift 3
  out="$("$@" 2>&1)"; rc=$?
  if [ "$rc" != "$want_rc" ]; then
    echo "✗ $name — exit $rc, expected $want_rc"; echo "${out:0:400}" | sed 's/^/    /'; FAIL=$((FAIL+1)); return
  fi
  if [ "$want_text" != "-" ] && ! grep -qF "$want_text" <<<"$out"; then
    echo "✗ $name — exit $rc as expected, but the output never said '$want_text'"
    echo "${out:0:400}" | sed 's/^/    /'; FAIL=$((FAIL+1)); return
  fi
  echo "✓ $name"; PASS=$((PASS+1))
}

# refute <name> <expected exit> <text that must NOT appear> <command...>
refute() {
  name="$1"; want_rc="$2"; forbidden="$3"; shift 3
  out="$("$@" 2>&1)"; rc=$?
  if [ "$rc" != "$want_rc" ]; then
    echo "✗ $name — exit $rc, expected $want_rc"; echo "${out:0:400}" | sed 's/^/    /'; FAIL=$((FAIL+1)); return
  fi
  if grep -qF "$forbidden" <<<"$out"; then
    echo "✗ $name — it said '$forbidden' when it should not have"
    echo "${out:0:400}" | sed 's/^/    /'; FAIL=$((FAIL+1)); return
  fi
  echo "✓ $name"; PASS=$((PASS+1))
}

CLEAN="$TMP/ps-clean.txt"; : > "$CLEAN"
PHPUNIT_PS="$TMP/ps-phpunit.txt"
echo "95539 /opt/homebrew/Cellar/php@8.4/bin/php vendor/phpunit/phpunit/phpunit --configuration=backend/phpunit.xml" > "$PHPUNIT_PS"
SERVER_PS="$TMP/ps-server.txt"
echo "77516 php -S 127.0.0.1:8011 backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" > "$SERVER_PS"

echo "== against real processes: the wiring to the process table =="
fake "node_modules/.bin/playwright" test
check "refuses while playwright is running" 1 "another test run" bash "$GUARD"
kill_last

fake "vendor/phpunit/phpunit" --configuration=phpunit.xml
check "refuses while phpunit is running" 1 "another test run" bash "$GUARD"
check "names the process it found" 1 "vendor/phpunit/phpunit" bash "$GUARD"
check "prints the way to queue behind it" 1 "until ! pgrep" bash "$GUARD"
kill_last

fake "artisan" test --parallel
check "refuses while artisan test is running" 1 "another test run" bash "$GUARD"
kill_last

fake "vendor/bin/pest" --filter=Nothing
check "refuses while pest is running" 1 "another test run" bash "$GUARD"
kill_last

fake "php" -S 127.0.0.1:8011
check "refuses a real leaked php -S on the port it was given" 1 "php -S" bash "$GUARD" 8011
kill_last

echo
echo "== against an injected process list: the branches a real machine cannot be asked for =="
check "passes on a machine with no test run" 0 "No other test run" \
  env CHECK_LOAD_PS="$CLEAN" bash "$GUARD"
check "says so when it is reading an injected list" 0 "a test is driving this guard" \
  env CHECK_LOAD_PS="$CLEAN" bash "$GUARD"
check "refuses a phpunit line in the list" 1 "another test run" \
  env CHECK_LOAD_PS="$PHPUNIT_PS" bash "$GUARD"
check "refuses a php -S line on the port it was given" 1 "php -S 127.0.0.1:8011" \
  env CHECK_LOAD_PS="$SERVER_PS" bash "$GUARD" 8011
check "ignores the same php -S line on another port" 0 "No other test run" \
  env CHECK_LOAD_PS="$SERVER_PS" bash "$GUARD" 9999

echo
echo "== memory pressure: Apple's verdict, not ours =="
check "refuses at critical pressure" 1 "critical memory pressure" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_PRESSURE=4 bash "$GUARD"
check "warns at warn pressure and still runs" 0 "warn level" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_PRESSURE=2 bash "$GUARD"
refute "stays quiet at normal pressure" 0 "warn level" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_PRESSURE=1 bash "$GUARD"

echo
echo "== the small-machine advice =="
check "prints the reduced invocation on 8 GB" 0 "PHP_CLI_SERVER_WORKERS=8 bash" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_TOTAL_GB=8 bash "$GUARD"
refute "stays quiet once the worker counts are set" 0 "PHP_CLI_SERVER_WORKERS=8 bash" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_TOTAL_GB=8 E2E_WORKERS=1 PHP_CLI_SERVER_WORKERS=8 bash "$GUARD"
refute "stays quiet on a machine with room" 0 "PHP_CLI_SERVER_WORKERS=8 bash" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_TOTAL_GB=32 bash "$GUARD"

echo
echo "== the override is explicit, and says so on each refusal it passed =="
check "E2E_IGNORE_LOAD=1 passes a busy machine" 0 "continuing anyway" \
  env CHECK_LOAD_PS="$PHPUNIT_PS" E2E_IGNORE_LOAD=1 bash "$GUARD"
check "E2E_IGNORE_LOAD=1 passes critical pressure" 0 "continuing anyway" \
  env CHECK_LOAD_PS="$CLEAN" CHECK_LOAD_PRESSURE=4 E2E_IGNORE_LOAD=1 bash "$GUARD"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
