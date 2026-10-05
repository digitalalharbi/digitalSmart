# Integrations

## Status: `BLOCKED_EXTERNAL`

No ad-platform integration can perform a live handshake yet. Every provider below needs
credentials or an approval that only the account owner can obtain. **This blocks the
live handshake only** — adapter contracts, the credential model, UI, sandbox fixtures
and tests are built regardless, and the rest of the roadmap continues unaffected.

Nothing in this repository fabricates a successful connection or invents metrics. An
unconfigured provider reports exactly that.

## What is needed, per provider

| Provider   | Required to unblock                                                                 | Env keys |
| ---------- | ----------------------------------------------------------------------------------- | -------- |
| Meta Ads   | Meta app (Business type), Marketing API access, `ads_read`/`ads_management` review, redirect URI allow-listed | `META_ADS_CLIENT_ID`, `META_ADS_CLIENT_SECRET` |
| Google Ads | Google Cloud OAuth client, **developer token** approved for production by Google     | `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN` |
| TikTok Ads | TikTok for Business developer app, Marketing API approval                            | `TIKTOK_ADS_APP_ID`, `TIKTOK_ADS_APP_SECRET` |
| Snapchat   | Snap Business app with Marketing API access                                          | `SNAPCHAT_ADS_CLIENT_ID`, `SNAPCHAT_ADS_CLIENT_SECRET` |
| X Ads      | X developer account with **Ads API access** (separately approved, often paid)         | `X_ADS_CONSUMER_KEY`, `X_ADS_CONSUMER_SECRET` |
| LinkedIn   | LinkedIn app with Advertising API access (partner programme approval)                 | `LINKEDIN_ADS_CLIENT_ID`, `LINKEDIN_ADS_CLIENT_SECRET` |
| ChatGPT Ads| Programme availability and API access — **not generally available**; contract and capability set unknown until access is granted | `OPENAI_ADS_API_KEY` |

These are business/account actions, not engineering work: they need the owner's
identity, legal acceptance and in some cases payment. That is why they are the one
class of thing the build stops for.

## How the boundary is enforced in code

`BLOCKED_EXTERNAL` is not a comment here, it is behaviour:

- An adapter given **real credentials** throws `ProviderNotConfigured` and the
  API answers `503` with `blocked_external: true`. The message says plainly that
  digitalSmart has not been granted API access — not that the operator entered
  something wrong, because they did not.
- A provider with **no published API** (ChatGPT Ads) throws `ProviderUnavailable`
  and answers `501`. It declares *no* capabilities, so the interface renders no
  controls for it.
- Nothing fabricates a successful connection, and nothing invents a metric.

### Sandbox mode

Entering `sandbox` for every credential of a provider switches that connection
to recorded fixtures, so the whole flow — discovery, binding, project access,
revocation — can be exercised before any provider grants access.

Sandbox accounts are named `SANDBOX — …` and are asserted as such by the test
suite.

Phase 3 extended sandbox mode to campaign structure and performance, which needs
saying carefully, because an earlier version of this document said there would
never be sandbox metrics. The reasoning behind that line still holds — a
fabricated spend figure in front of a client is the one failure this project
treats as unrecoverable — but refusing to have any numbers at all made the whole
ingestion, analytics and reporting layer impossible to build or test before a
provider grants access, which is worse.

What exists instead is a labelling guarantee strong enough to make the fixtures
safe:

- Every row written from a sandbox connection carries
  `MetricSource::Sandbox` in its own `source` column, decided once in
  `SyncBoundAccount` where the credentials are known, and there is no code path
  below that which can relabel it.
- Aggregates inherit their weakest input (`MetricSource::weakest`), so a single
  sandbox row makes the whole total read `SANDBOX`. Sandbox figures will not
  combine with actuals at all (`mixesWith`), so a mixed total cannot be formed
  by accident.
- A connection reads fixtures only when **every** required credential is
  literally the string `sandbox`. Half-filled is a configuration mistake, not a
  sandbox, and is treated as one.
- Real credentials still refuse outright. There is no fallback from "cannot call
  the API" to "show the fixtures", which is the specific failure mode that would
  put invented numbers on a client's report.

The fixtures themselves are described in
`backend/database/fixtures/providers/README.md`, including where their numbers
came from. Each file holds what the provider's API returns — its own field names,
value types and enum strings, from the published schema — so the mapper the tests
exercise is the mapper that will face the live endpoint. The only departure from
a verbatim response is that dates are stored as relative offsets and resolved at
read time, so the sandbox does not age into irrelevance.

Where a provider publishes no contract, its fixture directory is simply absent
and `SyncAdapterRegistry::supports()` returns false. Nothing invents a shape.

## Architecture

Provider logic sits behind stable ports. No provider-specific code ever appears in a
controller or a domain model.

```
Application ports ── ProviderAdapter          (connect, discover, health)
                  │      ├─ MetaAdsAdapter
                  │      ├─ GoogleAdsAdapter
                  │      ├─ TikTokAdsAdapter
                  │      ├─ SnapchatAdsAdapter
                  │      ├─ XAdsAdapter
                  │      ├─ LinkedInAdsAdapter
                  │      └─ OpenAIAdsAdapter
                  │
                  └─ AdvertisingSyncAdapter   (campaigns, ad groups, ads,
                         ├─ MetaSyncAdapter     creatives, insights)
                         ├─ GoogleAdsSyncAdapter
                         └─ TikTokSyncAdapter
```

`ProviderAdapter` and `AdvertisingSyncAdapter` are deliberately separate. A
provider can be connected long before any sync is implemented, and the health of
a connection should not depend on the sync surface. The registries reflect that:
every provider has a `ProviderAdapter`, enforced by a test, while
`SyncAdapterRegistry::supports()` is allowed to answer false — and when it does,
asking for an adapter raises `ProviderUnavailable` rather than returning a stub.

Each provider adapter exposes: auth/connect, account discovery, campaign
discovery, creative requirements, campaign mutation *where supported*, error
normalisation, rate-limit handling and a health check.

### The sync contract

Every `AdvertisingSyncAdapter` implementation must:

- return normalised records — no provider-shaped type crosses the boundary;
- preserve provider ids and raw status strings, because the caller needs both;
- report `null` for a metric the provider does not expose, never `0`;
- paginate with an opaque cursor the caller stores and hands back;
- raise `ProviderRequestFailed` with retry guidance rather than sleeping inside
  the adapter and blocking a worker;
- be re-runnable for the same date range without duplicating anything.

It also declares two numbers the sync scheduler depends on:
`attributionLookbackDays()`, how far back conversions keep landing against past
dates, and `maxInsightRangeDays()`, the widest range to request at once.

### Running a sync

`advertising:sync` queues one `SyncBoundAccountJob` per external account that at
least one project has bound **with sync enabled**. The job is unique per account,
so two runs cannot race on the same upsert keys, and backs off (60s, 5m, 15m)
because every provider here rate-limits per account.

Structure is read in dependency order — campaigns, ad groups, creatives, ads —
since an ad references both an ad group and a creative. Performance is then read
per level, because a parent's figures are not the sum of its children's. Failure
is partial by design: if ads sync and performance does not, the ads are kept and
the run is recorded as `partial`. What is never acceptable is a run that reports
success while missing days.

### Isolation model (mandatory)

```
ProviderConnection ──▶ ExternalAccount ──▶ ProjectIntegrationBinding ──▶ Project
```

A project syncs **only** the external accounts explicitly bound to it. There is no
implicit inheritance from workspace or client level.

### Capability flags

Adapters declare what they can actually do. The UI reads those flags: a provider that
cannot mutate budgets does not render a budget-edit control. We never implement a
write path a provider does not support, and never simulate one.

## Credential handling

- Stored encrypted (AES-256-GCM via `APP_KEY`) on `ProviderConnection`.
- Never returned by an API resource, never logged, never placed in a URL.
- Refresh tokens are rotated by a queued job before expiry; failures raise an
  actionable connection-health alert rather than silently degrading.
- **Client platform passwords are never requested or stored** — see `docs/SECURITY.md`.

## Client onboarding

Rather than asking for passwords, each provider gets a guided access flow:
OAuth where possible, otherwise Business Manager / Business Center asset sharing,
partner access, account IDs, pixels/datasets, pages and conversion assets — with a
per-provider checklist showing what is still missing.

## Provider field mappings

Each mapper reads one provider's documented schema. The Meta mapping is
implemented; the rest follow as their fixtures and mappers land.

The implemented mappers share a base class that owns the paging loop and the
sandbox gate, so each provider contributes only two things: which fixture answers
which request, and how to read one of its rows.

### Meta Marketing API — implemented

Conventions that a naive mapping gets wrong, and which `MetaMapper` handles
explicitly:

| Meta behaviour | Why it matters |
| -------------- | -------------- |
| `daily_budget` / `lifetime_budget` / `bid_amount` are numeric **strings in minor units** (`"250000"` = 2,500.00) | Read as a decimal, a budget is overstated a hundredfold and pacing never fires |
| `spend` is a **decimal string** in the account currency, unlike budgets | Two different conventions in one response |
| `status` is what the advertiser set; `effective_status` folds in parent state, schedule and ad review | Explains "active but not delivering"; both are stored |
| ODAX renamed objectives to `OUTCOME_*`, but older campaigns keep the legacy names | Dropping the legacy set silently reclassifies live campaigns as "other" |
| `actions` / `action_values` are arrays of `{action_type, value}`, with **nested categories** (`omni_purchase` contains `purchase`) | Summing the array triples the purchase count and divides ROAS by three |
| A campaign with neither budget set has its budget on its ad sets | Mapped to `BudgetMode::Inherited`, not to zero |
| Attribution is configurable up to 28-day click | Sync re-reads 28 days rather than trusting a watermark |

An unrecognised status or objective maps to `Unknown` / `Other`. A value Meta
adds next year must not silently become "active".

### Google Ads API (v22) — implemented

Field names here were read from the **published protobuf definitions** rather
than from the HTML field reference, which renders client-side. That immediately
corrected something a reasonable guess would have got wrong: there is no
`metrics.video_views` in v22. The metric is `videoTrueviewViews`, and quartile
completion exists only as a *rate*.

| Google behaviour | Why it matters |
| ---------------- | -------------- |
| Money is **micros** (millionths), not minor units | A micros value read as minor units overstates a budget 10,000-fold |
| int64 fields arrive as **strings** in protobuf JSON, and keys are lowerCamelCase | `"180000000"`, not `180000000`, and `costMicros`, not `cost_micros` |
| A campaign's budget is a separate `CampaignBudget` resource joined into the row, and may be **shared** across campaigns | A shared budget still sets this campaign's spend, but pacing must know it is not this campaign's alone |
| `status` is ENABLED/PAUSED/REMOVED; whether it *delivers* is `primaryStatus` (ELIGIBLE, LIMITED, MISCONFIGURED, LEARNING…) | Three ENABLED campaigns can be healthy, budget-constrained and broken, and look identical without it |
| There is **no objective field** | Intent is inferred from `advertisingChannelType` + `advertisingChannelSubType`; the pair is stored in `raw_objective` so the inference is auditable |
| `conversions` is a **fractional double** under Google's attribution | Rounding it makes our CPA disagree with Google's own interface |
| There is **no general deduplicated reach** and **no link-click metric** distinct from `clicks` | Both stay null. Approximating reach from impressions would invent a number the platform refuses to give, and frequency would inherit the lie |
| Quartile completion is a **percentage of impressions**, not a count | Derived exactly, with the original rate kept in metadata |
| There is **no separate creative resource** — the ad *is* the creative | The ad's own id is reused, rather than inventing a parent that does not exist |
| An ad can be ENABLED and `DISAPPROVED`, or `APPROVED_LIMITED` | Rejected and not-delivering respectively; "active" would hide a real problem behind a green label |
| Targeting is a set of separate criterion resources | Reported as `[]` — "not read" — rather than summarised from nothing |

Responsive ads carry many headlines and descriptions and Google chooses which
serve, so all of them are stored as creative assets. Call-to-action text is
generated by Google and has no field to read, so it stays null.

### TikTok Marketing API — implemented, deliberately partial

Structure and the reporting envelope are mapped from TikTok's own reference.
Metric *names* are not, and that gap is explicit rather than filled in.

| TikTok behaviour | Why it matters |
| ---------------- | -------------- |
| `budget` is a **float in the account currency** | A third convention after Meta's minor-unit strings and Google's micros; reading it as either is out by 100x or 10,000x |
| `bid_price` is `0` when bidding is automatic and the real target is `conversion_bid_price` | A zero read as a bid claims something about the campaign; it is treated as unset |
| `operation_status` is only ENABLE/DISABLE — the switch the advertiser set | Four fixture campaigns are all ENABLE: one delivers, one is over budget, one is in review, one is disapproved |
| `secondary_status` is what is actually happening | `CAMPAIGN_STATUS_BUDGET_EXCEED`, `CAMPAIGN_STATUS_REVIEW_DISAPPROVED`, `CAMPAIGN_STATUS_ADVERTISER_AUDIT`, … |
| `BUDGET_MODE_INFINITE` means the budget sits on the ad groups | Mapped to `Unlimited`, never to zero — "no money allocated" is a different and wrong story |
| Reporting rows are `{dimensions, metrics}`, **every value a string** | Including `spend`; nothing is a number on the wire |
| An unavailable metric is the literal **`"-"`** | Casting it yields `0`, which reads as "nobody was reached". It becomes null |
| Campaigns carry no schedule — ad groups do | Campaign dates stay null rather than borrowing an ad group's |
| There is no separate creative resource, and no headline field | The ad id is reused; `ad_text` is the whole caption and is not split into a title |
| Pagination is a page **number**, not a cursor | The opaque sandbox cursor stands in; nothing above the adapter interprets either |

**What is not mapped, and why.** TikTok's supported-metrics reference is only
reachable from inside its own documentation SPA and opens in a tab the browser
pane blocks, so its click, video and revenue metric names could not be confirmed.
Rather than guess them, `TikTokMapper` keeps a `VERIFIED_METRICS` list and
normalises only those; `clicks`, `linkClicks`, `videoViews`, `videoCompletions`,
`engagements` and `revenueMinor` stay **null**, and every unrecognised metric is
carried into `metadata.unmapped_metrics` untouched. A chart can label a gap. It
cannot detect a plausible figure sitting in the wrong column — and a later
verification pass can map those names without re-syncing, because the values were
never discarded.

### Snapchat, X and LinkedIn — not implemented

`SyncAdapterRegistry::supports()` returns false for these three, and asking for an
adapter raises `ProviderUnavailable`. There is no stub, because a generic adapter
could only guess at field names. Each needs its own mapper against its own
documented schema and its own fixture set.

> **Caveat.** The Meta mappings were written from Meta's published schema
> Several of its reference pages render their field tables client-side and could
> not be read directly, so the Meta mappings must be re-verified against the live
> API once credentials exist — specifically the insight field names, the complete
> `action_type` vocabulary, and the per-level availability of `reach` and
> `frequency`. The Google mappings came from the protobuf definitions and are on
> firmer ground, but the *semantics* of `primaryStatus` reasons and the objective
> inference still want checking against a real account. TikTok's structure fields
> are confirmed; its metric names are the open item above. The fixtures make a
> mismatch a failing test rather than a wrong chart, but they cannot substitute
> for that verification.
