#!/usr/bin/env bash
# No GitHub workflow may invoke a model, hold a secret, or run on a schedule — owner decision, 2026-10-19.
#
# GitHub is source control, CI and deployment for digitalSmart. Development runs from a Claude Code session on the
# owner's machine. A workflow that calls Claude or Anthropic — or any model API — is a paid development path through
# GitHub, and this check is what stops one from coming back quietly in a later change. It is the first CI job and part
# of the local pre-push hook (.githooks/pre-push).
#
# Comments in workflow files are ignored, so a workflow may explain this rule in prose without failing it.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOWS="$ROOT/.github/workflows"

python3 - "$WORKFLOWS" <<'PY'
import pathlib, re, sys
import yaml

root = pathlib.Path(sys.argv[1])
files = sorted(list(root.glob('*.yml')) + list(root.glob('*.yaml')))
failures = []

FORBIDDEN_TEXT = {
    'claude-code-action': 'the Claude Code GitHub action',
    'anthropics/': 'an Anthropic action',
    'ANTHROPIC_API_KEY': 'an Anthropic API key',
    'api.anthropic.com': 'a call to the Anthropic API',
    'OPENAI_API_KEY': 'an OpenAI API key',
    'api.openai.com': 'a call to the OpenAI API',
    'secrets.': 'a secret reference (no workflow holds a secret)',
}

for path in files:
    name = path.name
    raw = path.read_text()
    code = '\n'.join(line for line in raw.splitlines() if not line.lstrip().startswith('#'))

    if re.match(r'^(claude|autopilot)', name, re.I) or 'autopilot' in name.lower():
        failures.append(f'{name}: a workflow named like the model path exists')

    for needle, what in FORBIDDEN_TEXT.items():
        if needle.lower() in code.lower():
            failures.append(f'{name}: {what} ({needle})')

    parsed = yaml.safe_load(raw) or {}
    triggers = parsed.get(True, parsed.get('on', {}))
    keys = set(triggers) if isinstance(triggers, (dict, list)) else {triggers}
    if 'schedule' in keys:
        failures.append(f'{name}: a schedule trigger (nothing runs unattended)')

ci = root / 'ci.yml'
if not ci.exists():
    failures.append('ci.yml is missing — the rule must not be met by deleting CI')
else:
    jobs = (yaml.safe_load(ci.read_text()) or {}).get('jobs') or {}
    for job in ('backend', 'frontend'):
        if job not in jobs:
            failures.append(f'ci.yml has no {job} job — the rule must not be met by breaking CI')

if failures:
    print('✗ A workflow can incur a charge or invoke a model:')
    for failure in failures:
        print('  -', failure)
    sys.exit(1)

print(f'✓ {len(files)} workflow(s): no model, no key, no secret, no schedule; CI jobs intact')
PY
