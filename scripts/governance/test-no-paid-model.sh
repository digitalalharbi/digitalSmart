#!/usr/bin/env bash
# Proves the governance guard fails on each thing it forbids and passes what it allows — a guard never seen to fail
# proves nothing. Each case copies the real workflows and deploy scripts into a scratch tree and changes one thing.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PASS=0; FAIL=0
KEY_HEADER="-----BEGIN OPENSSH ""PRIVATE KEY-----"   # split so this file never contains a key header

# check <label> <expect pass|fail> <shell snippet run inside the scratch tree>
check() {
  local label="$1" expect="$2" mutate="$3" tmp got
  tmp="$(mktemp -d)"
  mkdir -p "$tmp/.github" "$tmp/scripts/governance"
  cp -R "$ROOT/.github/workflows" "$tmp/.github/workflows"
  [ -d "$ROOT/scripts/deploy" ] && cp -R "$ROOT/scripts/deploy" "$tmp/scripts/deploy"
  cp "$ROOT/scripts/governance/no-paid-model.sh" "$tmp/scripts/governance/"
  (cd "$tmp" && eval "$mutate")
  if bash "$tmp/scripts/governance/no-paid-model.sh" >/dev/null 2>&1; then got=pass; else got=fail; fi
  rm -rf "$tmp"
  if [ "$got" = "$expect" ]; then printf '  ✓ %s\n' "$label"; PASS=$((PASS+1)); else printf '  ✗ %s (expected %s, got %s)\n' "$label" "$expect" "$got"; FAIL=$((FAIL+1)); fi
}

WF=.github/workflows
JOB=$'jobs: {x: {runs-on: ubuntu-latest, steps: [{run: echo}]}}'

echo "governance guard"
check "the real workflows and deploy scripts pass"            pass ':'
check "claude.yml is refused"                                 fail "printf 'on: workflow_dispatch\n$JOB\n' > $WF/claude.yml"
check "an autopilot workflow is refused"                      fail "printf 'on: workflow_dispatch\n$JOB\n' > $WF/nightly-autopilot.yml"
check "claude-code-action is refused"                         fail "printf 'on: pull_request\njobs: {x: {runs-on: ubuntu-latest, steps: [{uses: anthropics/claude-code-action@v1}]}}\n' > $WF/review.yml"
check "ANTHROPIC_API_KEY is refused, even as a secret"        fail "printf 'on: push\njobs: {x: {runs-on: ubuntu-latest, env: {K: \"\${{ secrets.ANTHROPIC_API_KEY }}\"}, steps: [{run: echo}]}}\n' > $WF/x.yml"
check "a model API call is refused"                           fail "printf 'on: push\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: curl https://api.anthropic.com/v1/messages}]}}\n' > $WF/x.yml"
check "a schedule is refused"                                 fail "printf 'on: {schedule: [{cron: \"0 * * * *\"}]}\n$JOB\n' > $WF/cron.yml"
check "an approved deploy secret in the deploy workflow passes" pass "grep -q 'secrets.PRODUCTION_SSH_KEY' $WF/deploy-production.yml"
check "an unapproved secret in the deploy workflow is refused" fail "sed -i.bak 's/secrets.PRODUCTION_PORT/secrets.META_ACCESS_TOKEN/' $WF/deploy-production.yml && rm $WF/*.bak"
check "a deploy secret outside the deploy workflow is refused" fail "printf 'on: push\njobs: {x: {runs-on: ubuntu-latest, env: {H: \"\${{ secrets.PRODUCTION_HOST }}\"}, steps: [{run: echo}]}}\n' > $WF/other.yml"
check "secrets read dynamically are refused"                  fail "printf 'on: push\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: \"echo \${{ toJSON(secrets) }}\"}]}}\n' > $WF/dump.yml"
check "a hard-coded private key in a workflow is refused"     fail "printf 'on: push\njobs: {x: {runs-on: ubuntu-latest, steps: [{run: \"echo $KEY_HEADER\"}]}}\n' > $WF/leak.yml"
check "a hard-coded key in a deploy script is refused"        fail "printf '#!/bin/sh\nKEY=\"$KEY_HEADER\"\n' > scripts/deploy/leak.sh"
check "a deploy on pull_request is refused"                   fail "sed -i.bak 's/^  workflow_dispatch:/  pull_request:/' $WF/deploy-production.yml && rm $WF/*.bak"
check "a deploy from another branch is refused"               fail "sed -i.bak 's/branches: \\[main\\]/branches: [main, develop]/' $WF/deploy-production.yml && rm $WF/*.bak"
check "a deploy without the single concurrency group is refused" fail "sed -i.bak 's/group: production-deploy/group: anything/' $WF/deploy-production.yml && rm $WF/*.bak"
check "migrate:fresh in a deploy script is refused"           fail "printf 'php artisan migrate:fresh --force\n' >> scripts/deploy/deploy-production.sh"
check "demo:agency in the deploy workflow is refused"         fail "printf '      - run: php artisan demo:agency\n' >> $WF/deploy-production.yml"
check "naming them in a comment is allowed"                   pass "printf '# never: migrate:fresh, db:wipe, demo:agency\n' >> scripts/deploy/deploy-production.sh"
check "deleting the backend job from CI is refused"           fail "python3 -c \"import yaml;p='$WF/ci.yml';d=yaml.safe_load(open(p));d['jobs'].pop('backend');d['on']=d.pop(True);open(p,'w').write(yaml.safe_dump(d))\""
check "prose explaining the rules is allowed"                 pass "printf '# no anthropics/claude-code-action, no ANTHROPIC_API_KEY here\non: workflow_dispatch\n$JOB\n' > $WF/notes.yml"

printf '\n%d passed · %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
