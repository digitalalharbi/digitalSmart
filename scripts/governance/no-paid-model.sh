#!/usr/bin/env bash
# Governance guard — owner decisions of 2026-10-19. The first CI job, and run by the local pre-push hook.
#
# 1. GitHub never invokes a model: no model action, no model key, no model API call, no claude*/autopilot workflow,
#    no schedule — in any workflow.
# 2. Secrets: no secret VALUE ever exists in a repository file, a workflow literal, a commit or a log. GitHub Actions
#    encrypted secrets are allowed for deployment infrastructure ONLY — the PRODUCTION_* names below, referenced only
#    from deploy-production.yml. Provider, runtime and model credentials stay in the server's environment.
# 3. Production deploys only from main: deploy-production.yml runs on push to main (and a manual re-run of main),
#    never on a pull request, one at a time, and no deploy file contains a destructive database command.
# 4. CI cannot be "fixed" by deleting it: ci.yml keeps its governance, backend and frontend jobs.
#
# Comments are ignored, so a file may explain these rules in prose without failing them.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - "$ROOT" <<'PY'
import pathlib, re, subprocess, sys
import yaml

root = pathlib.Path(sys.argv[1])
workflows = root / '.github' / 'workflows'
files = sorted(list(workflows.glob('*.yml')) + list(workflows.glob('*.yaml')))
failures = []

ALLOWED_SECRETS = {
    'PRODUCTION_HOST', 'PRODUCTION_USER', 'PRODUCTION_SSH_KEY', 'PRODUCTION_PORT', 'PRODUCTION_PATH',
    'PRODUCTION_KNOWN_HOSTS',
}
SECRET_HOME = 'deploy-production.yml'

MODEL_TEXT = {
    'claude-code-action': 'the Claude Code GitHub action',
    'anthropics/': 'an Anthropic action',
    'ANTHROPIC_API_KEY': 'an Anthropic API key',
    'api.anthropic.com': 'a call to the Anthropic API',
    'OPENAI_API_KEY': 'an OpenAI API key',
    'api.openai.com': 'a call to the OpenAI API',
}

# Shapes of real secret values. Assembled from parts so this file never contains one itself.
VALUE_PATTERNS = [
    ('a private key', '-----BEGIN ' + r'[A-Z ]*PRIVATE KEY-----'),
    ('a GitHub token', 'gh' + r'[pousr]_[A-Za-z0-9]{30,}'),
    ('a GitHub fine-grained token', 'github' + r'_pat_[A-Za-z0-9_]{30,}'),
    ('an Anthropic key', 'sk-' + r'ant-[A-Za-z0-9_-]{20,}'),
    ('an OpenAI key', 'sk-' + r'(proj-)?[A-Za-z0-9]{32,}'),
    ('an AWS access key', 'AK' + r'IA[0-9A-Z]{16}'),
    ('a Slack token', 'xo' + r'x[baprs]-[A-Za-z0-9-]{10,}'),
]

DESTRUCTIVE = re.compile(r'\b(migrate:(fresh|reset|refresh)|db:wipe|db:seed|demo:agency)\b')

def code(text, comment='#'):
    return '\n'.join(l for l in text.splitlines() if not l.lstrip().startswith(comment))

for path in files:
    name = path.name
    raw = path.read_text()
    body = code(raw)

    if re.match(r'^(claude|autopilot)', name, re.I) or 'autopilot' in name.lower():
        failures.append(f'{name}: a workflow named like the model path exists')

    for needle, what in MODEL_TEXT.items():
        if needle.lower() in body.lower():
            failures.append(f'{name}: {what} ({needle})')

    for secret in re.findall(r'secrets\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)', body):
        if secret not in ALLOWED_SECRETS:
            failures.append(f'{name}: secrets.{secret} is not an approved deployment secret')
        elif name != SECRET_HOME:
            failures.append(f'{name}: secrets.{secret} outside {SECRET_HOME}')
    if re.search(r'secrets\s*\[', body) or 'toJSON(secrets' in body.replace(' ', ''):
        failures.append(f'{name}: secrets read dynamically')

    parsed = yaml.safe_load(raw) or {}
    triggers = parsed.get(True, parsed.get('on', {}))
    keys = set(triggers) if isinstance(triggers, (dict, list)) else {triggers}
    if 'schedule' in keys:
        failures.append(f'{name}: a schedule trigger (nothing runs unattended)')

    if name == SECRET_HOME:
        if keys - {'push', 'workflow_dispatch'}:
            failures.append(f'{name}: deploys on {sorted(keys - {"push", "workflow_dispatch"})} — only a push to main may deploy')
        push = triggers.get('push') if isinstance(triggers, dict) else None
        if not isinstance(push, dict) or push.get('branches') != ['main']:
            failures.append(f'{name}: push must be limited to branches: [main]')
        if (parsed.get('concurrency') or {}).get('group') != 'production-deploy':
            failures.append(f'{name}: no single production-deploy concurrency group')
        if DESTRUCTIVE.search(body):
            failures.append(f'{name}: a destructive database command')

# Deploy scripts: no destructive command outside comments.
for script in sorted((root / 'scripts' / 'deploy').glob('*.sh')) if (root / 'scripts' / 'deploy').exists() else []:
    if DESTRUCTIVE.search(code(script.read_text())):
        failures.append(f'scripts/deploy/{script.name}: a destructive database command (never on production)')

# No secret value in any tracked file.
try:
    tracked = subprocess.run(['git', '-C', str(root), 'ls-files', '-z'], capture_output=True, check=True).stdout.split(b'\0')
except Exception:
    tracked = [str(p.relative_to(root)).encode() for p in root.rglob('*') if p.is_file() and '.git' not in p.parts]
for rel in tracked:
    if not rel:
        continue
    path = root / rel.decode()
    try:
        text = path.read_text(errors='strict')
    except Exception:
        continue
    for what, pattern in VALUE_PATTERNS:
        if re.search(pattern, text):
            failures.append(f'{rel.decode()}: looks like {what}')

ci = workflows / 'ci.yml'
if not ci.exists():
    failures.append('ci.yml is missing — the rules must not be met by deleting CI')
else:
    jobs = (yaml.safe_load(ci.read_text()) or {}).get('jobs') or {}
    for job in ('governance', 'backend', 'frontend'):
        if job not in jobs:
            failures.append(f'ci.yml has no {job} job — the rules must not be met by breaking CI')

if failures:
    print('✗ Governance:')
    for failure in failures:
        print('  -', failure)
    sys.exit(1)

print(f'✓ {len(files)} workflow(s): no model, no secret value, only approved deployment secrets, deploys only from main; CI jobs intact')
PY
