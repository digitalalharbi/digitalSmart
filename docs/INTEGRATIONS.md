# Integrations

## Status: `BLOCKED_EXTERNAL`

No ad-platform integration can perform a live handshake yet. Every provider below needs
credentials or an approval that only the account owner can obtain. **This blocks the
live handshake only** — adapter contracts, the credential model, UI, sandbox fixtures
and tests are built regardless, and the rest of the roadmap continues unaffected.

Nothing in this repository fabricates a successful connection or invents metrics. An
unconfigured provider reports exactly that.

## The provider registry

`App\Domain\Integration\Provider` is the **single source of truth for provider
vocabulary**. What a platform is called, what kind of thing it is, what its API can do,
how a workspace authorises it, what records it exposes, how often it may be called and
where its contract is published are all answered there and nowhere else.

| Question | Answered by |
| -------- | ----------- |
| What is it called? | `label()` — never written in a page or a mapper |
| What kind of thing is it? | `category()`: advertising, analytics, tag management, commerce |
| Can we reach its API at all? | `apiStatus()`: generally available, requires approval, or no public API |
| How is it authorised? | `authMethod()` plus `requiredCredentialKeys()` |
| What can its API do? | `capabilities()` |
| What records does it expose? | `supportedEntities()` |
| How often may we call it? | `rateLimit()`, or null where the provider publishes nothing |
| Where is the contract? | `documentationUrl()` |

Four rules hold it honest, each asserted by a test:

- **Capabilities describe the provider's API, not our progress.** `metrics_sync` on X means
  X publishes a reporting API, whether or not an adapter exists. What *we* have built is
  answered by the adapter registry. Conflating the two would make shipping an adapter look
  like a provider gaining a feature.
- **Nothing is inferred from a provider's own interface.** Meta's web console does things
  the Marketing API cannot.
- **A capability belonging to one kind of provider cannot appear on another.** A store
  claiming `campaign_discovery` would be offered as an allocation target and its orders
  compared with an advertising budget. Capabilities that genuinely span categories —
  discovering accounts, reading reporting figures, receiving webhooks — declare no category
  at all rather than being filed under a convenient one.
- **An undocumented rate limit is null, never a guess.** A sync that paces itself against a
  number nobody set is slow for a reason nobody can find; one that trusts an invented
  number gets throttled in production instead of in the retry logic.

The registry travels to the frontend on the same response as the connection state, at
`GET /api/integrations/providers`. That is deliberate: splitting them would mean every page
needing a provider's *name* had to join two endpoints, which is how a second list of
platform labels gets written — the exact drift this registry exists to prevent.

`ProviderCategory::totalsWith()` encodes the rule the taxonomy is for: **figures never
total across categories.** An ad platform's claimed revenue, an analytics property's
recorded revenue and a store's invoiced revenue are three different numbers.

## What is needed, per provider

| Provider   | Required to unblock                                                                 | Env keys |
| ---------- | ----------------------------------------------------------------------------------- | -------- |
| Meta Ads   | Meta app (Business type), Marketing API access, `ads_read`/`ads_management` review, redirect URI allow-listed | `META_ADS_CLIENT_ID`, `META_ADS_CLIENT_SECRET` |
| Google Ads | Google Cloud OAuth client, **developer token** approved for production by Google     | `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN` |
| TikTok Ads | TikTok for Business developer app, Marketing API approval                            | `TIKTOK_ADS_APP_ID`, `TIKTOK_ADS_APP_SECRET` |
| Snapchat   | Snap Business app with Marketing API access                                          | `SNAPCHAT_ADS_CLIENT_ID`, `SNAPCHAT_ADS_CLIENT_SECRET` |
| X Ads      | X developer app, **Ads API access** via X's access form (up to 3 business days), plus a per-user **OAuth 1.0a** access token — X is the one provider here that is not OAuth 2.0 | `X_ADS_CONSUMER_KEY`, `X_ADS_CONSUMER_SECRET` |
| LinkedIn   | LinkedIn app with Advertising API access (partner programme approval)                 | `LINKEDIN_ADS_CLIENT_ID`, `LINKEDIN_ADS_CLIENT_SECRET` |
| Google Analytics 4 | A Google Cloud project with the Analytics Admin and Data APIs enabled, an OAuth client with the redirect URI allow-listed, and a user who can read the property. Scope `https://www.googleapis.com/auth/analytics.readonly`. **No programme to be admitted to** — the blocker is configuration and consent | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| Google Tag Manager | The same Google Cloud project with the Tag Manager API enabled, and a user with read access to the container | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| Salla | An app registered in the Salla Partners Portal, and a merchant who installs it. Self-service | `SALLA_CLIENT_ID`, `SALLA_CLIENT_SECRET` |
| Zid | An app registered in Zid's partner dashboard, and a merchant who installs it. Self-service | `ZID_CLIENT_ID`, `ZID_CLIENT_SECRET` |
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
                         ├─ TikTokSyncAdapter
                         └─ SnapchatSyncAdapter
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

### Snapchat Marketing API — implemented

Field names, enum values and response shapes were read from Snap's published
reference.

| Snapchat behaviour | Why it matters |
| ------------------ | -------------- |
| Every list item is wrapped twice: `{sub_request_status, campaign: {…}}` | A batch can partly succeed, and a failed item carries **no entity**. Those rows are dropped; mapping one anyway writes a record with an empty id |
| Money is **micro-currency** — `daily_budget_micro`, `bid_micro`, `lifetime_spend_cap_micro` | The same convention as Google's micros, and the third distinct one across four providers |
| The lifetime field is a spend **cap**, not a budget | It limits total spend while a daily budget paces delivery, so a campaign with both has a budget *and* a cap, and only one with the cap alone is lifetime-budgeted |
| `status` is only ACTIVE or PAUSED | Why something is not delivering lives in `delivery_status`, an array of reason codes. Three fixture campaigns are all ACTIVE: one spending, one at its spend cap, one with no eligible ad squads |
| A click is a **swipe**, and there is no separate link-click metric | `linkClicks` stays null. Copying swipes into it would make two different CTRs identical for a reason that is ours |
| Reach is `uniques` | Deduplicated, so it is below impressions and cannot be summed across entities |
| Quartiles and `view_completion` are **counts** | Unlike Google, which reports quartiles as rates. Nothing is derived |
| `bid_micro` is `0` under auto-bidding | Treated as unset: a bid of zero is a claim about the campaign, not an absence |
| The **ad object carries no campaign id** | The hierarchy is reached through the ad squad, so the store resolves the campaign from the squad it already synced rather than skipping the ad |
| A creative has one text field, `headline`, and no body | Splitting one field into two would be editorialising the creative |
| `top_snap_media_id` does not say whether it is a still or a video | That lives on the media resource, so the asset kind is `media` rather than a guess |
| Snapchat publishes no aggregate engagement metric | `engagements` stays null. Screen time and attachment views are kept in metadata rather than added into a number nobody defined |
| Pagination is a whole `paging.next_link` URL | The opaque cursor the caller stores is that URL, not a token |

> **One unverified assumption, and it is the expensive one.** Every money field Snap
> publishes carries a `_micro` suffix — except the stats metric `spend`. It is mapped
> as micro-currency for consistency with the rest of their API, which is the likely
> reading, but it could not be confirmed from the documentation. **If it is wrong,
> spend is understated by a factor of 10,000.** This must be checked against a live
> response before any Snapchat figure reaches a client. The sandbox fixtures encode
> the same assumption, so a test will not catch it; only a real response will.

### X Ads API v12 — implemented

Field names, enum values and response shapes were read from X's published
reference. `https://ads-api.x.com/12/` is the base; two versions are live at once and
each is deprecated six months after its successor appears, so the version in the path
is a thing to watch rather than set once.

**Auth is the odd one out.** X is the only provider here that is not OAuth 2.0: every
request is signed with OAuth 1.0a, three-legged, using a consumer key and secret for
the app plus an access token and secret for the user acting on the account. There is
no refresh token to rotate — an OAuth 1.0a token stays valid until the user revokes
it — so reconnection is a user action rather than a scheduled job, which is why
`Provider::X` declares `AuthMethod::OAuth1` and asks for `consumer_key` and
`consumer_secret`.

| X behaviour | Why it matters |
| ----------- | -------------- |
| **Stats are a time series, not a row per date** — `{"id": "dvcz7", "id_data": [{"segment": null, "metrics": {"impressions": [0, 2792, …]}}]}` | One response row is a whole range. Bucket *i* of every metric is the same day, and the day is the requested start plus *i*: the response states its length, not its dates. A mapper reading only the first bucket would silently discard every day but the first, which is why `mapInsights()` returns a *list* |
| A metric with no data comes back as `null`, not an array of zeros | X documents that null and zero are equivalent *for it*. They are not equivalent here, so a null stays null — an aggregate has to be able to say "not reported" rather than claim a measured none. It also means the series length has to be taken from the longest metric rather than the first |
| **The objective is on the line item, not the campaign** | One campaign can be pursuing two objectives at once, so a campaign record carries none at all rather than one borrowed from a child |
| **Reach is a separate endpoint** — `stats/.../reach/campaigns`, with its own range limits | Stats rows carry no reach, so `reach` stays null for every X row. Impressions passed off as people is the easy lie here |
| Money is micros, suffixed `_local_micro` | The same convention as Google and Snapchat; `$5.50` is `5500000` |
| A campaign may carry a daily budget *and* a total budget | The daily figure paces and the total caps, so a campaign with both is daily-budgeted with a cap |
| `entity_status` is only ACTIVE, PAUSED or DRAFT | Why an entity is not serving is in `servable` and `reasons_not_servable`. Only **two** reason codes are documented — `PAUSED_BY_ADVERTISER` and `INCOMPLETE` — so only those two are interpreted; any other reason still yields "not delivering" because `servable: false` says so, and the code itself reaches the interface untranslated |
| Paused is both "paused" and "not servable" | The switch wins: `PAUSED_BY_ADVERTISER` is reported as paused, because that is the state an operator can act on |
| **A promoted tweet carries no campaign id** | The hierarchy is reached through the line item, so the store resolves the campaign from the line item it already synced — the same hole Snapchat's ad object opened |
| A promoted tweet has no name | The tweet *is* the creative. The row is labelled by its tweet id rather than "Untitled" repeated down a list |
| Targeting lives in its own `targeting_criteria` collection | Not on the line item, so `targeting` is an empty object rather than a half-populated one |
| A card is assembled from **components** and X derives `card_type` from them | MEDIA (one `media_key`), SWIPEABLE_MEDIA (`media_keys`, 2–6 — this is how a carousel is made), DETAILS (`title`, `destination`) and BUTTON (`label`, `destination`). `title` and `label` are typed wrappers, `{"type": "ENUM", "value": "INSTALL"}`, so the value is read out rather than the wrapper stringified |
| A card references media by **key**, not URL | Resolving a key to a URL is a separate media-library request, so no thumbnail is reported |
| `url_clicks` is the nearest thing to a link click | It is X's own metric rather than a share of `clicks`, so the two CTRs differ for the platform's reason, not ours |
| X publishes **no conversion total and no conversion value** in these metric groups | `conversions` reports purchases as the headline, as for Snapchat — adding the columns would count one purchase again as a content view and again as a checkout. Every column that reported a figure becomes a breakdown row, and none of them carries a value |
| Synchronous stats answer **at most 7 days**; conversions keep landing for weeks | This is the first provider whose per-request ceiling is *shorter* than its attribution lookback. The sync now asks for the range in consecutive windows rather than clamping it to one request — clamping meant such a provider could never re-read its own attribution window |
| `entity_ids` is capped at 20 per request, and `placement` is one value per request | Both are per-request shapes for the transport to respect, not mapping concerns |
| Metrics finalise after 24 hours; **spend** within three days | A figure read today can still change, which is the same reason every provider here is re-read |

Errors arrive as `{"errors": [{"code": "CAPS_CASE", "message": "…", "parameter": "…"}]}`.
The documented retryable conditions are `TOO_MANY_REQUESTS` (429), `OVER_CAPACITY`
(503), `SERVICE_UNAVAILABLE` (503), `INTERNAL_ERROR` (500), `CANCELLED_REQUEST` (408)
and `LOCK_ACQUISITION_TIMEOUT` (423) — another writer holding the entity. The last two
were being filed as `unknown`, and so reported to an operator as something to fix;
they are now classified as transient. Synchronous stats allow 250 requests per 15
minutes per user, and the asynchronous jobs endpoint 100 concurrent jobs per account.

> **What is not verified.** `reasons_not_servable` and `effective_status` have no
> published enumerations — only `PAUSED_BY_ADVERTISER`/`INCOMPLETE` and
> `UNKNOWN`/`RUNNING` appear in X's examples, and the fixtures use nothing else. Card
> types beyond `UNIFIED` and `IMAGE_APP` are inferred from the one documented example:
> the medium is read from the leading token, so `IMAGE_*` and `VIDEO_*` are recognised
> and anything else is reported as unknown rather than guessed. The LTV conversion
> groups were not mapped at all, which is why no X revenue figure exists.

### LinkedIn Marketing API — implemented

Field names, enum values and response shapes were read from LinkedIn's published
reference. `https://api.linkedin.com/rest/` is the base, and every request carries a
dated `LinkedIn-Version` header plus `X-Restli-Protocol-Version: 2.0.0`. Versions
sunset about a year after release, so the header is something to keep current rather
than set once. Auth is OAuth 2.0 three-legged, with `r_ads` and `r_ads_reporting`.

> **The hierarchy does not line up with ours, and the names actively mislead.**
> LinkedIn's structure is account → campaignGroup → campaign → creative, and its
> *campaign* is what carries targeting, a bid, a budget and an objective — an ad set by
> any other name. So a LinkedIn **campaign group** is our campaign, a LinkedIn
> **campaign** is our ad group, and a **creative** is our ad. Anyone comparing our
> screen with Campaign Manager has to know that, and anyone changing this adapter has to
> know it twice: get it wrong and every roll-up in the product is off by one level.

| LinkedIn behaviour | Why it matters |
| ------------------ | -------------- |
| Money is a **decimal string inside an object** — `{"amount": "180000.00", "currencyCode": "SAR"}` | Not micros and not a bare string: the third money convention across six providers. Analytics money is a bare decimal string that may carry *more than two decimals* (`"19.91833"`), so it is rounded half-up at the minor unit — truncating would lose a halala on every row |
| Schedules are **epoch milliseconds**, and `runSchedule.end` is exclusive | Read as seconds, a 2025 start date becomes the year 57560 |
| Dates in analytics are **three integers** — `dateRange: {start: {year, month, day}}` | There is no date string anywhere in a reporting response, which is why the fixture loader grew a directive that writes that shape rather than the mapper learning a format no live row uses |
| Ids are **URNs in some places and longs in others** | A campaign group's `id` is `603407684`; a creative's is `urn:li:sponsoredCreative:119962155`; parents are always URNs. Everything is reduced to the trailing id, which is what a later request and a stored row both need |
| **`servingStatuses` decides delivery, not `status`** | `status` is what the advertiser set; `servingStatuses` is service-controlled and `RUNNABLE` is its only serving value — every other documented value is a hold. An ACTIVE group that has spent its budget is reported as not delivering, with `CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD` carried through as the raw effective status |
| `CANCELED` is permanent and not re-activatable | Mapped to *removed*, not *completed*: "completed" would say it ran its course |
| One campaign group per account is created automatically and **cannot be edited** by an enterprise account | `backfilled: true` marks it, and that reaches the metadata, because an operator told "you cannot change this" deserves the reason |
| A creative has **no format field** | The ad format is a property of the *campaign*, and a creative only points at a post whose medium would take another call. So a creative's format is `unknown` and the campaign's `format` is kept where it was reported — on the ad group |
| A creative's `name` is optional, and most made in Campaign Manager have none | The row is labelled by the post it sponsors, which an operator can at least match against a content calendar |
| **A creative names only its campaign** | The campaign group is resolved from the campaign already synced — the third provider running whose ad level needs this |
| Targeting **is** on the campaign, as a boolean tree of include/exclude facets | Stored as sent: flattening it would lose the operators that define the audience |
| `approximateMemberReach` is deduplicated members, and only for ranges **≤ 92 days** | Which is why `maxInsightRangeDays()` is 92 — a wider request would still answer, silently without reach. A parent's reach is below the sum of its children's, so summing across levels is as wrong here as it is for Meta |
| `conversionValueInLocalCurrency` is **one total**, not a figure per conversion type | So it is reported as the row's revenue, and no breakdown row carries a value. LinkedIn does publish conversion worth, unlike X |
| `externalWebsiteConversions` is the only conversion *total* published | It is the headline, and its post-click/post-view halves go to metadata rather than becoming rows that would count the same conversion twice |
| `JOB_APPLICANTS` is an objective | Filed as *other*, not *leads*: a job applicant is not a sales lead, and putting hiring spend into a conversion chart a client reads as pipeline would mislead |
| `clicks` and `landingPageClicks` are separate metrics | Both reported as sent, so the two CTRs differ for LinkedIn's reason rather than ours |
| Reporting is **throttled by data volume**: 45 million metric values per 5 minutes | Metrics requested × rows returned. A sync asks for the metrics it maps and no more, which is also why only 20 metrics may be named per request |
| Search endpoints page by **cursor in `metadata.nextPageToken`**; `adAnalytics` does not page at all | Its response is capped at 15,000 elements instead, so a range has to stay small enough to fit |
| Daily data is retained for **6 months** | Beyond that LinkedIn rounds a request to month boundaries, which silently changes what a figure means |

The documented retryable conditions are 429 `TOO_MANY_REQUESTS`, 500 `INTERNAL_ERROR`
and 503 `UNDER_MAINTENANCE`. A 400 arrives as `MISSING_FIELD`,
`INVALID_VALUE_FOR_FIELD`, `START_DATE_AFTER_END_DATE` or `DATE_TOO_EARLY`, and 414
`REQUEST_URI_TOO_LONG` is a real possibility on this API because a reporting request
carries long lists of URNs.

> **What is not claimed.** LinkedIn warns that its own figures differ slightly between
> reporting levels and across different ranges, because reach and demographic metrics
> are approximate; nothing here reconciles that away. The post a creative sponsors is
> not read, so no creative headline, image or link is reported. Revenue attribution
> (`attributedRevenueMetrics`, which needs a connected CRM) is not read either.

### Google Analytics 4 — registry only

`https://analyticsadmin.googleapis.com/v1beta/` for accounts, properties and data streams;
the Data API for reporting. OAuth 2.0 with `analytics.readonly`, and service accounts are
also supported for server-to-server use.

**Read-only by design.** The Admin API can write, and this product never will: changing
somebody's analytics configuration from a reporting tool is not a feature, it is a way to
break a measurement setup nobody can then explain. The registry says so — no capability on
this provider is a write.

Unlike the advertising platforms there is no programme to be admitted to, so its API status
is *generally available*: the blocker is a Cloud project, an OAuth client and a user's
consent. Phase 8 builds the adapter, the property binding and the event mapping.

### Google Tag Manager — registry only

`https://www.googleapis.com/tagmanager/v2/`, hierarchy accounts → containers → workspaces →
tags, triggers and variables, plus container versions.

**Inspection only**, for the same reason: this product reads a container to answer "is the
tracking actually in place", and a dashboard that silently republished somebody's container
is how measurement breaks on a Friday afternoon.

Its published quota is the tightest here and the most surprising: **0.25 queries per second
on a 100-second sliding window and 10,000 requests a day, both per Google Cloud project** —
so every workspace on one deployment draws from the same allowance. Exceeding it answers
**403**, not 429, which is worth knowing because the usual retry heuristics look for 429.

### Salla — registry only

OAuth 2.0 at `https://accounts.salla.sa/oauth2/auth` and `/oauth2/token`, Merchant API at
`https://api.salla.dev/admin/v2/`. Two documented facts shape the connection model:

- A refresh token is issued **only** when `offline_access` is among the requested scopes.
  Without it the connection dies after two weeks with no way back except re-consent.
- **Refresh tokens are single-use.** Salla documents that reusing one — which two concurrent
  jobs would do — can invalidate the authorisation entirely. Refreshing therefore has to be
  serialised per connection rather than attempted opportunistically.

Access tokens last two weeks, refresh tokens a month. Webhooks are verified by an HMAC
SHA-256 signature (`X-Salla-Signature`) or a token strategy, selected per registration.

### Zid — registry only

OAuth 2.0 at `https://oauth.zid.sa/oauth/authorize` and `/oauth/token`, API at
`https://api.zid.sa/v1`.

**Two headers carry two different tokens**, and they are not interchangeable:
`Authorization: Bearer <authorization>` grants access to the API at all, while
`X-Manager-Token` carries the `access_token` and scopes the request to one store. Sending
the same value in both — the obvious mistake — fails in a way that reads like a permissions
problem. Both the manager token and the refresh token last a year, so rotation is a
scheduled concern; Zid recommends refreshing at around ten months.

### ChatGPT Ads — not implemented

`SyncAdapterRegistry::supports()` returns false, asking for an adapter raises
`ProviderUnavailable`, and `Provider::OpenAI` declares no capabilities at all. There is
no stub and nothing inferred, because the programme is not generally available and its
contract is unknown — declaring anything would be a guess the interface would then
present as fact.

> **Caveat.** The Meta mappings were written from Meta's published schema
> Several of its reference pages render their field tables client-side and could
> not be read directly, so the Meta mappings must be re-verified against the live
> API once credentials exist — specifically the insight field names, the complete
> `action_type` vocabulary, and the per-level availability of `reach` and
> `frequency`. The Google mappings came from the protobuf definitions and are on
> firmer ground, but the *semantics* of `primaryStatus` reasons and the objective
> inference still want checking against a real account. TikTok's structure fields
> are confirmed; its metric names are the open item above. Snapchat's field names and
> enums are confirmed; the unit of its `spend` metric is not, which is the most
> consequential open item across all six providers. X's field names, metric names and
> response shape are confirmed; its status reason codes are not enumerated anywhere, so
> only the two it publishes are interpreted. LinkedIn's schemas are the best documented
> of the six — field tables with full enumerations — so the open question there is not
> what its fields are but what its *approximate* metrics mean when they disagree
> between levels, which LinkedIn warns about and nothing here reconciles away. The
> fixtures make a *name* mismatch a
> failing test rather than a wrong chart, but a wrong *unit* is encoded in the fixtures
> too — only a live response settles it.
