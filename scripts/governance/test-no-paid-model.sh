#!/usr/bin/env bash
# Proves the guard fails on each thing it forbids — a guard that has never been seen to fail proves nothing.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PASS=0; FAIL=0

check() {
  local label="$1" file="$2" body="$3" expect="$4"
  local tmp; tmp="$(mktemp -d)"
  mkdir -p "$tmp/.github/workflows" "$tmp/scripts/governance"
  cp "$ROOT/.github/workflows/ci.yml" "$tmp/.github/workflows/ci.yml"
  cp "$ROOT/scripts/governance/no-paid-model.sh" "$tmp/scripts/governance/"
  [ -n "$file" ] && printf '%s\n' "$body" > "$tmp/.github/workflows/$file"
  if bash "$tmp/scripts/governance/no-paid-model.sh" >/dev/null 2>&1; then got=pass; else got=fail; fi
  rm -rf "$tmp"
  if [ "$got" = "$expect" ]; then printf '  ✓ %s\n' "$label"; PASS=$((PASS+1)); else printf '  ✗ %s (expected %s, got %s)\n' "$label" "$expect" "$got"; FAIL=$((FAIL+1)); fi
}

echo "no-paid-model guard"
check "the real workflows pass" "" "" pass
check "claude.yml is refused" "claude.yml" $'on: workflow_dispatch\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: echo}]}}' fail
check "an autopilot workflow is refused" "nightly-autopilot.yml" $'on: workflow_dispatch\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: echo}]}}' fail
check "claude-code-action is refused" "review.yml" $'on: pull_request\njobs: {x: {runs-on: ubuntu-latest, steps: [{uses: anthropics/claude-code-action@v1}]}}' fail
check "ANTHROPIC_API_KEY is refused" "lint.yml" $'on: pull_request\njobs: {x: {runs-on: ubuntu-latest, env: {ANTHROPIC_API_KEY: x}, steps: [{run: echo}]}}' fail
check "any secret reference is refused" "deploy.yml" $'on: push\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: "echo ${{ secrets.TOKEN }}"}]}}' fail
check "a model API call is refused" "x.yml" $'on: push\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: curl https://api.anthropic.com/v1/messages}]}}' fail
check "a schedule is refused" "cron.yml" $'on: {schedule: [{cron: "0 * * * *"}]}\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: echo}]}}' fail
check "prose explaining the rule is allowed" "notes.yml" $'# no anthropics/claude-code-action, no ANTHROPIC_API_KEY here\non: workflow_dispatch\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: echo ok}]}}' pass

printf '\n%d passed · %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
