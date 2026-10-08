# Runbooks

What to do at three in the morning, written for somebody who did not build this.

Each one states **the symptom**, then what to check, then what to do — in that order, because the first
mistake under pressure is acting on a theory instead of a reading. Every command assumes the repository root.

A note that applies to all of them: `GET /api/health` is the first thing to read, always. It names which
dependency is unhappy, and it distinguishes *degraded* (serving, but work is not happening) from *down*
(cannot serve). Guessing which one it is costs more than the request.

---

## 1. The queue has stopped

**Symptom.** `/api/health` reports `queue` degraded with *"nothing appears to be consuming it"*, or figures on
dashboards stop advancing while the API stays responsive.

**Check.**

```bash
curl -s https://api.example/api/health | jq '.data.services[] | select(.name == "queue")'
php artisan horizon:status
php artisan queue:monitor redis:default --max=100
```

**Do.**

1. If Horizon is not running, start it. The supervisor should; if it does not, that is the incident.
2. If Horizon is running but paused — `php artisan horizon:continue`. A deploy that called
   `horizon:terminate` without the supervisor restarting it looks exactly like this.
3. If Horizon is running and consuming nothing, a job is stuck past its timeout. `php artisan horizon:terminate`
   lets the supervisor restart the workers; in-flight jobs are retried according to their own `backoff`.
4. **Do not purge the queue to clear the alert.** The backlog is work somebody is waiting for — a provider
   sync, a webhook, an export. Deleting it makes the figures permanently wrong and silent about it.

**Afterwards.** The oldest waiting job's age is in the probe's detail. If it exceeded fifteen minutes, some
provider sync will have been retried to exhaustion and is now in the failed store — runbook 2.

---

## 2. Jobs have failed

**Symptom.** `/api/health` reports `failed_jobs` degraded: *"N job(s) gave up, the oldest X ago — each one is
work that did not happen"*.

**Why this is an alert and not a statistic.** A failed job is the same class of fact as an approved change
nobody carried out: something somebody believes happened, and did not. A failed sync leaves yesterday's spend
on a dashboard. A failed webhook leaves an order out of the revenue a client is being shown. Neither
announces itself, and neither is visible in a queue depth of zero — the queue is empty *because* the work was
abandoned.

**Check.**

```bash
php artisan queue:failed

# The exception itself, which the list truncates — and the one thing worth reading before retrying:
psql -d digitalsmart -c "
  select uuid, queue, failed_at, left(exception, 200)
  from failed_jobs order by failed_at desc limit 10
"
```

**Do.**

1. Read the exception before retrying anything. A provider returning 401 will return 401 again; retrying it
   fifty times is how an account gets rate-limited on top of being broken.
2. A transport failure or a provider outage — `php artisan queue:retry <uuid>`, or `--queue=default` for all
   of one queue.
3. A credential failure — fix the credential first (`/api/integrations`), then retry.
4. A code defect — do not retry. Fix, deploy, then retry, because a retry runs the *old* payload against the
   *new* code and that combination is the one nobody tested.
5. Once a job is retried and succeeded, **check what it was meant to refresh.** A sync that failed for two days
   and then succeeded leaves a gap in the metrics; the figures behind it were stale while it was failing, and
   anybody who quoted them was quoting stale numbers.

---

## 3. A provider has stopped answering

**Symptom.** 502s from `/api/integrations/...`, `provider_request_failed` in the response body, or a provider's
health endpoint reporting degraded.

**Check.** The response says what is wrong, deliberately:

```bash
curl -s https://api.example/api/integrations/providers/meta/health | jq
```

- `provider_not_configured` (503) names the exact missing credential. Nothing is broken; something was never
  issued. `docs/INTEGRATIONS.md` has the per-provider list.
- `provider_unavailable` (501) means the capability does not exist to be used. No credential would help.
- `provider_request_failed` (502) carries `retryable` and `retry_after_seconds` from the provider itself.

**Do.** Honour `retry_after_seconds`; the queue's own backoff already does. Nothing here needs a deploy, and
nothing here should be worked around by disabling the adapter — a provider absent from a report is better than
a provider silently replaced by a stale figure. Every metric already carries its source state, so the UI will
say `STALE` on its own.

---

## 4. A write to a platform had an unknown outcome

**Symptom.** A carry-out returned `is_confirmed: false` with `failure: "unknown"` and `safe_to_retry: false`.

**This is the one runbook where the wrong move is the obvious one.** An unknown outcome means the request left
and no answer came back — a timeout, a dropped connection. The change may or may not have been made, and
pressing the button again is how a campaign gets paused twice or a budget set twice.

**Do.**

1. **Open the platform and look.** That is the only source of truth, and it is why the surface says *"Do not
   try this again until somebody has checked the platform."*
2. If the change *was* made — record it with "I have made this change" on the approval. The record then says a
   person confirmed it, which is honest: this product did not read it back.
3. If it was **not** made — carry it out again. The request is still approved and still awaiting a hand,
   because a failed write deliberately leaves the approval exactly where it was.
4. Either way the attempt is already in the audit trail: `provider_write_attempted` was written *before* the
   request left, with the account, the campaign and the documentation reference. That entry is what proves a
   request was sent at all.

See `docs/PROVIDER_WRITES.md`.

---

## 5. Restore the database

**Symptom.** Data loss, a bad migration, or a restore drill.

**Check first.** Which archive, and what it should contain:

```bash
ls -lt storage/backups/*.dump | head
cat storage/backups/<archive>.dump.manifest
```

**Rehearse, every time, even under pressure.** The verify path restores into a scratch database, compares every
table against the manifest, and drops it again:

```bash
scripts/restore.sh storage/backups/<archive>.dump --verify
```

A `MISMATCH` line means the archive is not what its manifest says. Try the previous one; do not restore an
archive that failed its own comparison over a live database on the theory that it is probably fine.

**Then restore.**

```bash
# Into a database you name, to compare before switching:
scripts/restore.sh storage/backups/<archive>.dump --into digitalsmart_restored

# Or over the live one. Destructive, and it asks for the database name by hand:
PGDATABASE=digitalsmart scripts/restore.sh storage/backups/<archive>.dump --force
```

**Afterwards.**

1. `php artisan migrate --force` — an archive is from the schema it was taken on, which may be a release behind.
2. `php artisan queue:flush` only if the archive predates jobs that are now meaningless. Think first.
3. Tell the clients whose data moved. A restore that silently rolls back a day of work is a restore that makes
   this product's figures untrustworthy, which is the one thing it cannot recover from.

---

## 6. Rotate `APP_KEY`

**Read this whole runbook before starting.** `APP_KEY` encrypts every stored provider credential. Rotating it
without re-encrypting them makes all of them unreadable, and they cannot be recovered — each one has to be
re-issued by its provider, which means going back through every developer programme.

**Do.**

1. Back up and verify first (runbook 5). This is the step that makes the rest reversible.
2. Add the new key as `APP_PREVIOUS_KEYS` **before** changing `APP_KEY`, so the old ciphertexts still decrypt.
3. Re-encrypt the stored credentials — every row in `provider_connections`, read with the previous key and
   written with the new one.
4. Only then remove the previous key.

**If the key was already rotated without step 2**, stop. Restore the database from before the rotation
(runbook 5) and start again. Do not attempt to "fix" individual rows: a credential decrypted with the wrong
key is not an error, it is noise, and writing that back is how a recoverable mistake becomes a permanent one.

---

## 7. A client says a figure is wrong

**Not an outage, and the most important runbook here.** Every figure in this product carries a source state
precisely so this conversation can be had with evidence instead of apologies.

**Check, in this order.**

1. **What source does the screen say?** `LIVE`, `SYNCED`, `STALE`, `PARTIAL`, `SANDBOX`, `MANUAL`, `ESTIMATED`
   or `ATTRIBUTED`. Half of these conversations end here: a `SANDBOX` figure is a fixture, and a `STALE` one
   has a sync behind it that needs runbook 1 or 2.
2. **Which number are they comparing it with?** A Google Ads conversion, a GA4 key event, a Salla order and a
   CRM-won deal are four different records of possibly the same thing. This product refuses to add them, and
   `docs/ROADMAP.md` has the table that names each one.
3. **If it is attributed revenue** — which model, and what coverage? `/api/attribution` publishes both, and a
   model's figure is only as good as the share of revenue it could attribute at all.
4. **If it is a rate** — what was the denominator? This product refuses a rate without a valid one, so a
   missing rate is an answer: there was nothing legitimate to divide by.
5. **If it came from a report** — was it a snapshot? A snapshot returns its stored payload unchanged, which is
   the only way to answer "the report said this" after a platform restated a figure. The live report will
   disagree with it, and both are correct.

**Do.** Answer with the source state and the evidence. Never adjust a figure to match a platform's dashboard:
the two disagree for documented reasons, and a number edited to agree with a screenshot is a number nobody can
defend afterwards.
