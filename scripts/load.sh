#!/usr/bin/env bash
#
# Run the load profile against a stack that is already up.
#
# Prefers k6, which is the real thing: ramping virtual users, tagged thresholds, percentiles over the whole
# run. Where k6 is absent it falls back to a concurrent sampler built out of curl, which measures the same
# endpoints and reports the same percentiles — and says plainly that it is a sampler rather than a load
# generator, because a figure quoted without that distinction is a figure somebody will put in front of a
# client.
#
# Usage:  scripts/load.sh                       # against http://127.0.0.1:8001
#         BASE=https://staging.example scripts/load.sh
#         REQUESTS=400 CONCURRENCY=20 scripts/load.sh
#
# Neither mode is a production figure. A development server is one PHP process tree on a laptop that is also
# running the browser; the number it produces is a floor and a regression signal, not a capacity plan. See
# docs/RUNBOOKS.md for what to run before a release.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="${BASE:-http://127.0.0.1:8001}"
PASSWORD="${PASSWORD:-password}"
REQUESTS="${REQUESTS:-200}"
CONCURRENCY="${CONCURRENCY:-10}"
# The origin Sanctum will accept as first-party.
#
# Not cosmetic and not guessable: `SANCTUM_STATEFUL_DOMAINS` decides it, a request from anywhere else is treated
# as a token request, and the API then answers 401 to a perfectly good session cookie. Read from the backend's
# own .env rather than hard-coded, because the two drifting is exactly how this harness spent its first run
# measuring 401s.
ORIGIN="${ORIGIN:-$(grep -E '^FRONTEND_URL=' "$ROOT/backend/.env" 2>/dev/null | cut -d= -f2-)}"
ORIGIN="${ORIGIN:-http://127.0.0.1:5174}"

# Several accounts, deliberately.
#
# The API's rate limiter is **per user** — 120 requests a minute each — so a run driven by one account measures
# the limiter and not the application. That was this harness's first finding: 200 requests from one session
# came back 429 after the first 120. A realistic profile is several people with dashboards open, which is also
# what the limiter is sized for.
ACCOUNTS=(
  "${EMAIL:-owner@demo.digitalsmart.test}"
  manager@demo.digitalsmart.test
  buyer@demo.digitalsmart.test
  creative@demo.digitalsmart.test
)

curl -fsS --max-time 5 "$BASE/api/health" >/dev/null 2>&1 || {
  echo "Nothing is answering at $BASE. Start the stack first (make dev), or set BASE." >&2
  exit 1
}

if command -v k6 >/dev/null; then
  echo "==> k6 $(k6 version | head -1)"
  exec k6 run -e "BASE=$BASE" -e "EMAIL=$EMAIL" -e "PASSWORD=$PASSWORD" "$ROOT/scripts/load/api.js"
fi

cat <<'NOTE'
==> k6 is not installed, so this is the curl sampler rather than a load test.

    It measures real latency against real endpoints at a fixed concurrency, which catches a
    regression. It does not ramp, does not hold a population of virtual users, and does not
    evaluate the thresholds in scripts/load/api.js.

    Install the real thing before quoting a number:  brew install k6

NOTE

OUT="$(mktemp -d -t ds-load)"
trap 'rm -rf "$OUT"' EXIT

# Sessions are kept between runs, deliberately.
#
# Signing in is throttled at five attempts a minute per address — which is correct, and which means a harness
# that re-authenticates every run locks itself out on the second one. So the cookie jars live outside the
# temporary directory and are reused while they still work.
SESSION_DIR="${SESSION_DIR:-$ROOT/storage/load}"
mkdir -p "$SESSION_DIR"

# Signed in the way the SPA does — CSRF cookie, then the session — so the measured path includes the stateful
# middleware. The `Origin` header is not decoration: Sanctum decides whether a request is first-party from it,
# and without it every call is treated as a token request and answered 401. That was the harness's other
# finding, and it is the same mistake a new client library would make.
signed_in=0

for email in "${ACCOUNTS[@]}"; do
  jar="$SESSION_DIR/jar-$(echo "$email" | tr -c 'a-zA-Z0-9' '_')"

  # Still signed in? Then leave it alone rather than spending one of five attempts a minute.
  if [[ -f "$jar" ]] && curl -fsS -o /dev/null -b "$jar" -H 'Accept: application/json' \
      -H "Origin: $ORIGIN" "$BASE/api/auth/me" 2>/dev/null
  then
    cp "$jar" "$OUT/jar-$signed_in"
    signed_in=$(( signed_in + 1 ))
    continue
  fi

  curl -fsS -c "$jar" "$BASE/sanctum/csrf-cookie" >/dev/null
  token=$(awk '/XSRF-TOKEN/ {print $7}' "$jar" | tail -1 | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')

  if curl -fsS -b "$jar" -c "$jar" -X POST "$BASE/api/auth/login" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -H "X-XSRF-TOKEN: $token" -H "Origin: $ORIGIN" \
    -d "{\"email\":\"$email\",\"password\":\"$PASSWORD\"}" >/dev/null 2>&1
  then
    cp "$jar" "$OUT/jar-$signed_in"
    signed_in=$(( signed_in + 1 ))
  else
    # Almost always the login throttle: five a minute per address, which this loop can exhaust on its own.
    echo "    (could not sign in as $email — skipping it; if this is a repeat run, wait a minute)"
    rm -f "$jar"
  fi
done

(( signed_in > 0 )) || {
  echo "Could not sign in as any demo account. Is the workspace seeded (make fresh)?" >&2
  exit 1
}

echo "==> $signed_in session(s), because the API's rate limiter is per user (120/minute each)"
SESSIONS="$signed_in"

PATHS=(
  "/api/health"
  "/api/analytics?preset=30d&compare=previous_period"
  "/api/analytics?preset=30d&compare=previous_period&dimension=campaign&limit=25"
  "/api/analytics/catalogue"
  "/api/clients?per_page=25"
)

# One request, written to a file per worker slot so the appends cannot interleave.
WORKER="$OUT/worker.sh"
cat >"$WORKER" <<'WORKER_SH'
#!/usr/bin/env bash
set -euo pipefail
index="$1"

# `sed` rather than an array: macOS ships bash 3.2, which has no `mapfile`, and a script that
# only runs on a developer's newer shell is a script that fails on the machine it is needed on.
# One readable (session, path) pair per request, rotating through them so neither a cache nor a single
# user's rate limiter dominates the run.
pair=$(sed -n "$(( index % PAIRS + 1 ))p" "$PAIRS_FILE")
session="${pair%%	*}"
path="${pair#*	}"

curl -s -o /dev/null -b "$OUT/jar-$session" \
  -H 'Accept: application/json' -H "Origin: $ORIGIN" \
  -w '%{http_code} %{time_total}\n' "$BASE$path" >>"$OUT/slot-$(( index % CONCURRENCY ))"
WORKER_SH
chmod +x "$WORKER"

# Which (session, path) pairs are actually readable, probed once before the run.
#
# Roles differ, which is the point of the product: a creative may not read the client list, and the API
# answers 403 — correctly. Driving load through a refusal measures the authorisation layer and calls it a
# failure, so each pair is tried once and only the ones that answer 200 are driven. Anything excluded is
# printed, because a pair that *should* be readable disappearing from this list is a permission regression.
PAIRS_FILE="$OUT/pairs"
: >"$PAIRS_FILE"

for (( session = 0; session < SESSIONS; session++ )); do
  for path in "${PATHS[@]}"; do
    code=$(curl -s -o /dev/null -b "$OUT/jar-$session" -H 'Accept: application/json' \
      -H "Origin: $ORIGIN" -w '%{http_code}' "$BASE$path")

    if [[ "$code" == "200" ]]; then
      printf '%s\t%s\n' "$session" "$path" >>"$PAIRS_FILE"
    else
      echo "    (session $session cannot read $path — $code, excluded from the run)"
    fi
  done
done

PAIRS=$(wc -l <"$PAIRS_FILE" | tr -d ' ')

(( PAIRS > 0 )) || { echo "No readable endpoint for any session." >&2; exit 1; }

export BASE OUT CONCURRENCY PAIRS_FILE PAIRS ORIGIN

echo "==> $REQUESTS requests over $PAIRS readable session/endpoint pairs, $CONCURRENCY at a time, against $BASE"
START=$(python3 -c 'import time;print(time.time())')

seq 0 $(( REQUESTS - 1 )) | xargs -P "$CONCURRENCY" -n 1 "$WORKER"

ELAPSED=$(python3 -c "import time;print(round(time.time() - $START, 2))")

cat "$OUT"/slot-* | python3 -c "
import sys

rows = [line.split() for line in sys.stdin if line.strip()]
times = sorted(float(t) * 1000 for _, t in rows)

# 429 is the limiter working, which is not a fault — but it *is* a measurement of the limiter rather than of
# the application, so it is counted and named separately instead of being folded into either column.
limited = [code for code, _ in rows if code == '429']
failed = [code for code, _ in rows if not code.startswith('2') and code != '429']

def pct(p):
    return times[min(len(times) - 1, int(len(times) * p))]

print()
print(f'  requests      {len(rows)}')
print(f'  failed        {len(failed)}' + (f'  ({\", \".join(sorted(set(failed)))})' if failed else ''))
print(f'  rate-limited  {len(limited)}' + ('   (the limiter, not a fault — but those requests measured it rather than the API)' if limited else ''))
print(f'  elapsed       $ELAPSED s')
print(f'  throughput    {len(rows) / $ELAPSED:.1f} req/s at a concurrency of $CONCURRENCY')
print(f'  p50           {pct(0.50):.0f} ms')
print(f'  p95           {pct(0.95):.0f} ms')
print(f'  p99           {pct(0.99):.0f} ms')
print(f'  slowest       {times[-1]:.0f} ms')
print()
print('  A failure count above zero fails the run, whatever the latency was.')
"

# Exit non-zero on a real error. A 429 does not fail the run; it narrows what the run measured.
cat "$OUT"/slot-* | awk '{ if ($1 !~ /^2/ && $1 != 429) bad++ } END { exit (bad > 0) }'
