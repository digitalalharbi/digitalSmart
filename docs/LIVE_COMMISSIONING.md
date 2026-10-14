# Live commissioning ledger

What each external provider has actually been made to do, in this installation, as of the last readout.

This is not the closure ledger. [`docs/CLOSURE.md`](CLOSURE.md) records whether the product's own work is
finished; this records whether a real account has ever answered. The two are deliberately separate, because a
module can be complete and have touched nothing outside this repository.

## How a row gets its status

Every field below is read by `php artisan commission:readout {provider}` rather than asserted by a person.
The command asks the provider what it can be made to do and prints only what came back: which credentials are
configured, whether they are this repository's fixtures or a real account's, what discovery returned, which
metrics the account actually exposes, the currency and timezone it reports, and what the provider said when it
refused. It **writes nothing** — a readout against a client's live account cannot leave a figure behind that
somebody later mistakes for a sync — and it never prints a credential value.

| Status | Means |
| ------ | ----- |
| `NOT_CONFIGURED` | No connection exists in any workspace |
| `AWAITING_CREDENTIALS` | A connection exists holding the literal `sandbox` credential, or a key is missing |
| `AWAITING_EXTERNAL_APPROVAL` | Credentials exist; the provider has not admitted this application to the programme |
| `CONNECTED` | Real credentials, and the provider answered a health check |
| `SYNCING` | A scheduled sync is running against the real account |
| `LIVE_VERIFIED` / `READ_ONLY_VERIFIED` / `WRITE_VERIFIED` | See the evidence levels below |
| `FAILED_EXTERNAL` | Real credentials, and the provider refused. The refusal is recorded verbatim |

And four evidence levels, which the readout decides rather than the operator:

| Level | Earned by |
| ----- | --------- |
| `CODE_VERIFIED` | The adapter exists and its tests pass. Nothing external has been reached |
| `SANDBOX_VERIFIED` | Fixtures were read through the real mapper. Proves the shape, proves nothing about an account |
| `LIVE_READ_VERIFIED` | A real credential reached the provider and real rows came back |
| `LIVE_WRITE_VERIFIED` | A real mutation was accepted **and read back** |

**A sandbox run can never produce a live level**, whatever it returns, because the fixtures are in this
repository. That rule is in `CommissioningReadout::level()` and a test pins it.

## The ledger

Read on **2026-10-14** by `php artisan commission:readout` against the development database. Every connection
in this installation holds the literal `sandbox` credential; no live credential exists anywhere, and no
`.env` provider key is set.

| Provider | Connection | Auth | Discovery | Read | Metrics | Currency / timezone | Webhook | Write | Evidence | Blocker |
| -------- | ---------- | ---- | --------- | ---- | ------- | ------------------- | ------- | ----- | -------- | ------- |
| **Google Ads** | AWAITING_CREDENTIALS | sandbox only | 2 fixture accounts | 21 campaign rows / 7 days / 1 call | spend, impressions, clicks, conversions, revenue populated; `linkClicks` 0/21 — Google has no link-click concept | SAR · Asia/Riyadh | n/a | declared: status + budget mutation | SANDBOX_VERIFIED | Google Cloud OAuth client **and a developer token approved for production** (`BLOCKED_EXTERNAL_APPROVAL`) |
| **Google Analytics 4** | AWAITING_CREDENTIALS | sandbox only | 4 fixture properties | key events + revenue metric read | `purchaseRevenue` exposed; 3 key events | property-reported | n/a | none declared | SANDBOX_VERIFIED | OAuth client with `analytics.readonly` and a user who can read the property (`BLOCKED_EXTERNAL_CREDENTIALS`) |
| **Google Tag Manager** | AWAITING_CREDENTIALS | sandbox only | 3 fixture containers | published version read: v18, 5 tags, unpublished changes | 3 measurement ids found in the published container | n/a | n/a | none declared | SANDBOX_VERIFIED | The same OAuth client with the Tag Manager API enabled and container read access (`BLOCKED_EXTERNAL_CREDENTIALS`) |
| **Meta Ads** | AWAITING_CREDENTIALS | sandbox only | 3 fixture accounts | 21 campaign rows / 7 days / 1 call | spend, impressions, clicks, link clicks populated; conversions 19/21, revenue 12/21 — correct, not every campaign has a purchase | SAR · not reported at campaign level | n/a | declared: status + budget mutation | SANDBOX_VERIFIED | Meta app with Marketing API access and `ads_read` review (`BLOCKED_EXTERNAL_APPROVAL`) |
| **TikTok Ads** | AWAITING_CREDENTIALS | sandbox only | 1 fixture account | 21 campaign rows / 7 days / 1 call | spend, impressions, conversions populated; **clicks, revenue, video views 0/21** — deliberately unmapped, see below | SAR · Asia/Riyadh | n/a | declared: status + budget mutation | SANDBOX_VERIFIED | TikTok for Business app with Marketing API approval (`BLOCKED_EXTERNAL_APPROVAL`) |
| **Snapchat Ads** | NOT_CONFIGURED | — | — | — | — | — | n/a | declared by the adapter | CODE_VERIFIED | Snap Business app with Marketing API access (`BLOCKED_EXTERNAL_APPROVAL`). The spend-unit assumption is **unverified** — see below |
| **X Ads** | NOT_CONFIGURED | — | — | — | — | — | n/a | not supported | CODE_VERIFIED | X developer app plus Ads API access, and a per-user OAuth 1.0a token (`BLOCKED_EXTERNAL_APPROVAL`) |
| **LinkedIn Ads** | NOT_CONFIGURED | — | — | — | — | — | n/a | not supported | CODE_VERIFIED | LinkedIn app with Advertising API access, partner programme approval (`BLOCKED_EXTERNAL_APPROVAL`) |
| **ChatGPT Ads** | NOT_CONFIGURED | — | — | — | — | — | n/a | none | CODE_VERIFIED | Programme not generally available. `supports()` is false and the adapter raises `ProviderUnavailable`; the API surface must be re-read at commissioning rather than assumed from this repository's notes |
| **Salla** | AWAITING_CREDENTIALS | sandbox only | 1 fixture store | 3 orders / 7 days / 1 call, more pages available | order totals not yet compared with a merchant admin | SAR · Asia/Riyadh | endpoint live, signature verified against fixtures | none declared | SANDBOX_VERIFIED | Salla Partners app and a merchant who installs it (`BLOCKED_EXTERNAL_CREDENTIALS`) |
| **Zid** | NOT_CONFIGURED | — | — | — | — | — | endpoint live, Basic Auth verified against fixtures | none declared | CODE_VERIFIED | Zid partner app and a merchant who installs it (`BLOCKED_EXTERNAL_CREDENTIALS`) |
| **HubSpot** | AWAITING_CREDENTIALS | sandbox only | pipelines read by the lead module | deals read by the CRM bridge | stage metadata, not stage names | n/a | n/a | none — the CRM owns the stage | SANDBOX_VERIFIED | HubSpot developer app and an account that installs it (`BLOCKED_EXTERNAL_CREDENTIALS`) |

## What the readout already tells us, before any credential arrives

**TikTok's three empty columns are a decision, not a gap.** `clicks`, `revenue` and `videoViews` come back
null because this repository would not guess TikTok's metric names from its web console, and the directive
for this phase says the same: replace a deliberately-null field only when it is verified from the actual API
response or the current official schema. The readout publishes `clicks 0/21` so the gap is visible rather
than inferred from a zero.

**Snapchat's spend unit is the one unverified assumption in the advertising layer.** It is recorded in
`docs/INTEGRATIONS.md` and must be confirmed against a real account before any Snapchat figure is shown to a
client. If it proves wrong, the mapper, the fixtures, the tests and any already-synced rows all change —
which is why the readout is run **before** a sync, not after.

**Meta reports no account timezone at campaign level.** The readout says `not reported` rather than assuming
the workspace's. A live account may expose it elsewhere; that is a question for the first live readout.

## What has to happen for a row to move

1. The owner obtains the credential or approval in the Blocker column. These are business actions — identity,
   legal acceptance, sometimes payment — not engineering work.
2. The keys go into the environment, never into the repository. `.env.example` names every one.
3. An operator connects the provider in the product (Integrations), which stores them encrypted.
4. `php artisan commission:readout {provider} --workspace={id}` is run, and its output is pasted into this
   file with the date. That is the evidence; a status with no readout behind it is not one.
5. Only then is an account bound to a project and a sync allowed to run.

## Writes stay off until one is read back

No provider is `LIVE_WRITE_VERIFIED`, and none can be until a mutation has been accepted **and the value read
back from the provider**. The product already refuses to confirm a write it has not re-read — see
`docs/PROVIDER_WRITES.md`, which lists the seven gates a write passes before anything leaves the process, and
records `unknown` as its own outcome rather than folding it into failure.

Commissioning a write means one provider, one action, one safe account, in that order.

## What this installation cannot commission yet, and what each waits for

Nothing in this list is engineering work that has been skipped.

| Step from the commissioning plan | Waits for |
| -------------------------------- | --------- |
| Google Ads live readout, then GA4, then GTM | A Google Cloud OAuth client; for Ads, a developer token approved for production |
| Meta, TikTok, Snapchat live readouts | Each platform's developer programme admission |
| LinkedIn, X, ChatGPT Ads | Partner/programme approval. ChatGPT Ads' API surface must be re-read at commissioning rather than taken from this repository's notes |
| Salla and Zid live stores | A partner app per platform and a merchant who installs it |
| HubSpot | A developer app and an account that installs it |
| **Cross-source reconciliation** for one real period | Google + GA4 + one ad platform + one store, all live. The comparison is only worth building against figures that can disagree for real reasons — building it against fixtures would teach the wrong lesson, because fixtures agree |
| **One end-to-end live journey** (campaign → ad → landing page → tracking event → GA4 → order/lead → store/CRM → attribution → report) | The same four, plus a real campaign with real spend |
| **Any `LIVE_WRITE_VERIFIED`** | A live read first, then one provider, one action, one safe account — and a mutation read back from the provider |
| **AI assistant against a real model** | `ANTHROPIC_API_KEY`. The contract, the evidence grounding, the numeric guard and the workspace isolation are built and tested against documented-shape fixtures |
| **Staging, observability, scheduler proof** | A host. See `docs/PRODUCTION.md` — the schedule exists and is tested; what cannot be proven here is that a host runs `schedule:run` |

## Readout history

| Date | Provider | Result | Who |
| ---- | -------- | ------ | --- |
| 2026-10-14 | all | Every configured provider `SANDBOX_VERIFIED`; Snapchat, X, LinkedIn, ChatGPT Ads and Zid `NOT_CONFIGURED`. No live credential exists in this installation | Commissioning pass |
