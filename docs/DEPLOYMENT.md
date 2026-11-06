# Deployment

```
Claude Code → branch → pull request → required CI (governance, backend, frontend; E2E as configured)
           → protected main → Deploy Production (GitHub Actions → SSH) → health check → live
```

**State on 2026-10-19: `AWAITING_SERVER_ACCESS`.** The workflow and the server script are built and guarded; there is
no production server yet (`docs/PRODUCTION.md`). Until the owner supplies the values below and sets
`PRODUCTION_ENABLED=true`, a merge to `main` runs only the job that says so — no deployment is recorded.

## Assessment of the available server — 2026-11-06

The owner's hosting account was read, read-only, to answer one question: is there already a server this product
could deploy to? **There is one, and it is not available for this.** Recorded here so the question is not
re-opened from nothing in six months, and so nobody assumes the existing server is spare.

**What exists.** One VPS, on a one-vCPU plan with 4 GB of memory and a 50 GB disk (Ubuntu 24.04 LTS). It is not
idle: it serves production for two of the owner's other projects. There is **no domain for digitalSmart in the
account at all** — not a spare one, not a subdomain.

**What it has left.** Measured over roughly 24 hours, not sampled once:

| | Measured |
|---|---|
| CPU | 25–57% of its single core, averaging about 30% |
| Memory | 2.1–2.5 GB of 4 GB in use — roughly 1.8 GB free |
| Disk | 31.9 GB of 50 GB in use — 18–20 GB free |

**The critical reason it is refused, and it is a build rather than a capacity argument.** This pipeline builds on
the server: `scripts/deploy/deploy-production.sh` runs `composer install`, then `npm ci && npm run build`, inside
the new release directory on **every** deploy. A Vite production build of this frontend on one core with about
1.8 GB free is the most likely point of failure in the whole procedure, and the likely failure is the kernel's
out-of-memory killer rather than a clean error — on a host that is serving two other live products while it
happens. A deploy of digitalSmart would then be an outage of somebody else's. The disk is the lesser problem: the
deploy refuses below 2 GB free, which today's 18–20 GB clears, and a release directory plus `node_modules` plus
the five kept releases eat into that from the first deploy onwards.

It also runs against the owner's standing rule in `~/.claude/CLAUDE.md` never to mix projects or deployments, but
the memory argument stands on its own.

**This is an engineering judgement, not a measured failure.** Nothing was installed, built, deployed or changed on
that server; no deploy was attempted. What is measured is the headroom, and what is reasoned from it is the build
step. **The server decision is the owner's** — a larger plan, a build moved off the server, or a second machine are
all his to choose between, and none of them is taken here.

## What raising `AWAITING_SERVER_ACCESS` actually takes

In this order, because each one is useless without the one before it:

1. **A separate server** for digitalSmart — not the one above. Enough memory for a Node build beside PHP-FPM,
   Postgres, Redis, Horizon and Reverb, or a deploy that builds elsewhere and ships the artefact.
2. **A domain for digitalSmart**, with DNS and a certificate. The account holds none, so this is a purchase or a
   delegation rather than a setting.
3. **The server's own details** — SSH user, port, deploy path, host key, web-server arrangement, process names —
   and `shared/.env` plus `shared/deploy.env` filled on the server (*First-time setup*, below).
4. **The six `PRODUCTION_*` secrets** in the `production` environment, and the repository variable
   `PRODUCTION_ENABLED=true` (*Secrets*, below).

Until all four exist, `AWAITING_SERVER_ACCESS` is the honest status and nothing here is `LIVE_VERIFIED`.

## What triggers a deploy

| Event | Deploys? |
|---|---|
| A merge to `main` (push to `main`) | Yes — the merged commit |
| *Run workflow* on `main` (manual re-deploy, e.g. after `shared/.env` changed) | Yes |
| A pull request, any other branch, a schedule | **Never** — the governance guard fails the build if the workflow ever allows it |

One deploy at a time (`concurrency: production-deploy`). A newer merge waits for the running deploy instead of
cancelling it mid-migration; GitHub keeps only the newest pending run, so an older queued deploy is superseded.

## The server

Push-based: the runner connects over SSH; the server never polls GitHub.

```
$PRODUCTION_PATH/
  repo/                   clean clone of digitalalharbi/digitalSmart — detached at the deployed commit, never edited
  releases/<sha>/         one exported release per deployed commit (no .git); the last 5 are kept
  shared/.env             APP_ENV=production, APP_DEBUG=false, every runtime/provider/model credential — never in Git
  shared/storage/         Laravel storage (uploads, logs, cache) — survives every deploy
  shared/deploy.env       which services this server runs (below)
  shared/deployments.log  time · previous · new · status · duration, one line per deploy
  shared/DEPLOYED_SHA     the live commit
  current -> releases/<sha>
```

### What a deploy does (`scripts/deploy/deploy-production.sh`, reviewed on main like any other code)

1. **Preflight** — full commit sha that is on `main`; `repo/` is a clean clone; `shared/.env` exists with
   `APP_ENV=production` and `APP_DEBUG=false`; at least 2 GB free; a lock so two deploys never overlap.
2. **Build beside the live release** — `git archive` of the commit into `releases/<sha>`; `storage` and `.env`
   linked from `shared/`; `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`;
   `npm ci && npm run build` (the Vite dev server never runs in production).
3. **Migrate, forward only** — `php artisan migrate --force`. If it fails the deploy stops: the previous release is
   still live and nothing is restarted.
   Then `php artisan access:sync`, so every workspace's system roles hold the permissions the release adds before anybody
   reaches a screen that needs them (idempotent; roles a workspace created for itself are untouched).
4. **Caches** — `optimize:clear`, `config:cache`, `route:cache`, `view:cache`.
5. **Switch** `current` atomically (`ln -sfn` + `mv -T`).
6. **Reload** — PHP-FPM (if named), `horizon:terminate` (the supervisor restarts it on `current`), `queue:restart`,
   Reverb (if named). The scheduler is a per-minute cron on `current/backend/artisan schedule:run`, so it reads the new
   code by itself. No worker keeps running old code.
7. **Health** — `GET /api/health` must answer 200 (it probes the database, Redis, the queue and the failed-job store
   and answers 503 when any is down), the built frontend must answer, Horizon must report running, and the
   application must boot. Twelve tries, five seconds apart.
8. **On an unhealthy result** — `current` is switched back to the previous release and reloaded, the deploy is
   recorded as failed, and the workflow fails. **Migrations are not rolled back** (below).

**Never on production:** `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, `db:seed`, `demo:agency`.
The governance guard fails the build if a deploy file contains one as a command.

### First-time setup (once, by the owner or with the owner)

```bash
sudo mkdir -p $PRODUCTION_PATH/{releases,shared/storage} && sudo chown -R deploy: $PRODUCTION_PATH
git clone https://github.com/digitalalharbi/digitalSmart.git $PRODUCTION_PATH/repo
cp backend/.env.example $PRODUCTION_PATH/shared/.env   # then fill it on the server; APP_ENV=production, APP_DEBUG=false
cp -R backend/storage/. $PRODUCTION_PATH/shared/storage/
```

`shared/deploy.env` names what this server actually runs — nothing is restarted that is not named:

```bash
APP_URL_HEALTH=https://app.example.com/api/health      # required
FRONTEND_URL_CHECK=https://app.example.com/            # the built SPA
PHP_FPM_SERVICE=php8.4-fpm                             # reloaded with sudo systemctl reload; omit if not used
USE_HORIZON=true                                       # horizon:terminate + status check
REVERB_RESTART_COMMAND="sudo supervisorctl restart digitalsmart-reverb"   # omit if Reverb is not deployed
```

The web server serves `current/frontend/dist` for the SPA and `current/backend/public` for `/api`, `/sanctum`,
`/up`; Horizon and Reverb run under a supervisor pointed at `current/`; cron runs `schedule:run` every minute.

## Secrets

**No secret value may exist in a repository file, a workflow literal, a commit, a log or a document.** GitHub Actions
encrypted secrets are allowed for **deployment infrastructure only**, in the `production` environment:

| Secret | What |
|---|---|
| `PRODUCTION_HOST` | The server's host name or IP |
| `PRODUCTION_USER` | The SSH user that owns `$PRODUCTION_PATH` |
| `PRODUCTION_SSH_KEY` | A private key used for nothing but deploying |
| `PRODUCTION_PORT` | SSH port (defaults to 22) |
| `PRODUCTION_PATH` | The deploy root above |
| `PRODUCTION_KNOWN_HOSTS` | The server's host key line(s) — the runner refuses a server that cannot prove it is ours |

Repository variable (not secret): `PRODUCTION_ENABLED=true` once the above exist.

Provider credentials (Meta, Google, TikTok, Snapchat, LinkedIn, X, WhatsApp, mail) and model keys are **not**
GitHub secrets: they live in the server's `shared/.env` or the product's encrypted store. The guard
(`scripts/governance/no-paid-model.sh`) allows `${{ secrets.PRODUCTION_* }}` only in `deploy-production.yml`, refuses
any other secret name anywhere, refuses dynamic secret reads, and scans every tracked file for private keys and token
shapes. GitHub masks secrets in logs; the key is written to a mode-600 file and removed when the job ends.

## Sync status

Is `main` green, and is that what is live — without opening GitHub.

- **`scripts/sync-status.sh`** prints one line from `git` and the GitHub CLI: branch and commit, *Synced / Changes pending /
  Ahead / Behind*, this branch's CI, `main`'s CI, and the deployment. Green is fine, amber is running or pending, red is
  failed, grey is waiting on something outside the repository (no server) or unknown (offline). `--fetch` refreshes
  `origin` first, `--json` is for a status line or a notifier. One read per source — no loop; run it again to refresh.
- **Administration → Advanced diagnostics** shows `main` and Deploy as two dots with words. It reads the public GitHub
  API (no credential), cached five minutes server-side, and needs `workspaces.view`. Set `DEPLOYED_SHA_FILE` in
  `shared/.env` to `$PRODUCTION_PATH/shared/DEPLOYED_SHA` so *live* and *behind main* can be told apart; without it
  the deployment dot stays grey, *waiting* — which is the truth until a server exists.
- Neither shows a token, a run id or a log, and nothing here is visible to a client.

## History

Each deploy is visible three ways: GitHub's **Environments → production** history (commit, time, status), the run's
summary (commit, trigger, actor, finish time, duration), and `shared/deployments.log` on the server (previous and new
commit, status, duration). The merge commit on `main` names the pull request.

## Rollback

**Application** — deterministic and fast, because the previous releases are still on disk:

```bash
cd $PRODUCTION_PATH
ls -1t releases/                        # newest first; DEPLOYED_SHA and deployments.log say what was live
ln -sfn releases/<previous-sha> current.next && mv -Tf current.next current
php current/backend/artisan config:cache && php current/backend/artisan route:cache
php current/backend/artisan horizon:terminate && php current/backend/artisan queue:restart
curl -fsS https://app.example.com/api/health
```

Then revert the bad commit on a branch and merge it, so `main` matches what is live; the next deploy proceeds normally.

**Database** — a separate decision, never automatic. Most migrations are additive and the previous release runs
against the newer schema. Where one is not, roll forward with a corrective migration; `migrate:rollback --step=1`
is used only when that migration's `down()` has been read and proven safe for the data now in it. Restore from backup
(`scripts/restore.sh`) is the last resort.

## Disk space

Heavy local work (E2E, builds) starts with `scripts/check-disk.sh` — 10 GB free by default; `scripts/e2e.sh` runs it
first. The server deploy refuses to start with less than 2 GB free. When space is short, investigate read-only first
(`du -sh` on caches, worktrees, logs, Playwright output) and delete only what is known to be disposable — never a
database, a production file, another project's working tree or uncommitted work without authorisation.
