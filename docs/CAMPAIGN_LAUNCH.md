# Campaign launch

PRs E–G of the SaaS chain (`docs/SAAS_CAMPAIGN_MASTER.md`). How a campaign goes from an idea to campaigns on several platforms,
created paused, read back, and only then activated. **PR E (the plan and the capability registry), PR F (the launch plan
and its preflight), PR G (approval and queued creation) and PR H (activation and operations) exist**.

## The plan (campaign blueprint)

`campaign_blueprints`, edited through **Campaigns → Campaign plans** (`/campaigns/plans`, `/campaigns/new`) and
`/api/campaign-plans`. One campaign, described once, before any platform's vocabulary, and kept:

| | |
|---|---|
| Who and where | workspace, client, project |
| What for | one of eight intents — awareness, reach, traffic, engagement, video, leads, sales, app installs (`LaunchObjective`) |
| Money and time | daily or lifetime budget in minor units, one currency, start, end (a lifetime budget needs one) |
| Where it runs | ad accounts **bound to the project** (`campaign_blueprint_targets`) |
| Where people land | a tracking link of the project (its UTMs used as they are) or a landing page |
| What counts | a conversion definition of the client |
| Who sees it | countries, ages, gender, languages, interests — intent, translated per platform |
| Where it appears | automatic, or a list of placements |
| What they see | Creative Hub requests of the client (`campaign_blueprint_creatives`) and the ad copy |
| The record | status, version, created by, submitted by and when, approved by and when |

**Nothing is copied and nothing is trusted.** Every reference is checked against the project on every save: an ad account that
is not bound to the project is refused (`account_not_bound`) — the binding is the only way a project may touch an account — and a
tracking link, conversion or creative from another project or client is refused (`reference_outside_project`). A plan holds no
credential.

**A plan never touches a platform.** Saving, submitting, returning and cancelling write only the plan and the audit trail.

### States

`draft → ready_for_review → approved → queued → creating → created_paused → verified → activation_pending → active`, with
`partial`, `failed` and `cancelled` beside them (`BlueprintStatus`). **`approved` means a person approved the plan, never that
anything exists on a platform**; each later state is written only by the step that observed it, and `verified` only after a
read-back.

- A plan can be edited in `draft` and `ready_for_review`. Editing one under review sends it back to draft, clears its submission
  and bumps its **version** — so an approval (PR G) can only ever name the version its approver read.
- Submitting needs at least one ad account, a tracking link or landing page, a country and a headline; the refusal lists what is
  missing. A conversion and creatives are not required to be reviewed — the preflight (F) says what each platform needs.
- Returning to draft and cancelling need a reason, kept on the plan.

### Who may

`campaign_blueprints.view`, `.create`, `.update`, `.submit`: owners, administrators, project managers and media buyers; creatives
may view. A reader sees plans for the projects they can see. Approving, creating on a platform and activating are separate
permissions (`campaign_launch.approve`, `.execute`, `.activate`): administrators hold all three, project managers may approve,
media buyers none. Planning is part of the **campaign launch** feature
(`docs/ENTITLEMENTS.md`): a workspace whose plan lacks it is refused with `feature_not_in_plan`.

## The launch plan and its preflight (PR F)

`GET /api/campaign-plans/{id}/launch-plan`, the wizard's last step. **Read-only**: it validates without creating, and never creates
a campaign to find out whether one could be created.

```
plan → each platform's translation → ProviderCreatePlan per ad account → payload draft in the platform's own field names
     → preflight checks per account and for the whole plan → one state
```

**Per ad account, exactly what would be written** (`ProviderCreatePlan`): one campaign, one ad group, one ad per assigned creative;
the budget (a daily budget split across accounts in minor units, nothing lost); the schedule; the platform's own objective; the
conversion; where people land; and the **initial state** — the platform's documented non-delivering value, sent explicitly at every
level, including where the platform would default to it. The payload draft (`DocumentedPayloadDrafts`) uses each platform's field
names from the 2026-10-24 reading; it is what PR G's adapters start from, never sent from here. **Budgets are totalled per currency,
never across currencies.**

**Preflight states**: `ready`, `warning` (can go ahead; read why), `blocked` (the workspace must fix it), `awaiting_credentials`
(sandbox credentials), `blocked_external` (waiting on the platform), `unsupported` (not confirmed in the platform's documentation;
nothing attempted). Every non-ready check says why and what fixes it. Creation is stopped by anything from `awaiting_credentials` up;
a plan is as ready as its worst check.

| Check | Where | Not ready when |
|---|---|---|
| Plan quota | plan | the workspace's plan includes no campaign launches |
| Accounts, dates, landing | plan | no account; a start in the past; nowhere to land (blocked) or plain HTTP (warning) |
| Tracking link | plan | paused, expired or gone |
| Creatives | plan | none assigned or none with a file (blocked); not approved yet (warning) |
| Conversion | plan | a results objective with no conversion (warning) |
| Binding | account | the account is no longer bound to the project |
| Connection | account | sandbox credentials (`awaiting_credentials`); not connected (blocked); real credentials but no live create ever confirmed (`blocked_external`, naming the access the platform requires) |
| Account, currency, time zone | account | inactive; another currency than the plan (blocked); another time zone (warning) |
| `campaign.create`, `ad_group.create`, `ad.create` | account | not documented by the platform (`unsupported`) |
| Objective | account | the platform has none for this intent (`unsupported`); a near match (warning, with the platform's own note) |
| Targeting, placement | account | a field used that the platform does not document (warning: left out until confirmed); free-text interests (warning: matched by a person) |
| Conversion optimisation | account | not documented (`unsupported`); the project sends no conversions to that platform yet (warning) |
| Platform validation | account | the platform documents validation without creating, but only real credentials can call it (`awaiting_credentials`) |
| Initial state | account | always ready; names the paused value and whether the platform delivers by default |

Budget minimums are not checked: no platform's minimum was read, and an invented rule would be worse than none.

## Provider capability registry

`App\Domain\Integration\Capability\ProviderCapabilityRegistry`, served at `GET /api/integrations/capabilities`. Twenty-six keys —
`campaign.create` to `readback` — for each of Meta, Google Ads, TikTok, Snapchat, LinkedIn, X and ChatGPT Ads, each `documented`,
`unverified`, `unsupported` or `blocked_external`, with the official page it was read on, the API version and the date.
**`unverified` is not a weaker `documented`**: nothing may be attempted on it. The status and budget writes the product already
carries out keep their evidence in `ProviderEntityWrites`, so there is one source for what is attempted.

### What the documentation audit of 2026-10-24 found

Read on each platform's own developer documentation (Meta v25.0/v26.0, Google Ads v26, TikTok v1.3, Snapchat v1, LinkedIn
202610, X v12, ChatGPT Ads v1):

- **Every platform can create campaigns paused, but two deliver unless told otherwise.** TikTok's `operation_status` defaults to
  `ENABLE` at campaign, ad group and ad level, and X's campaign `entity_status` defaults to `ACTIVE`. Several official examples
  create live objects (LinkedIn `ACTIVE`, ChatGPT Ads `active`, Google ad groups and ads `ENABLED`). Every create this product
  makes must send the paused value explicitly; the registry names it per platform (`paused_on_create`, `delivers_by_default`).
- **Validation without creating** is documented only by Meta (`execution_options=["validate_only"]`) and Google Ads
  (`validate_only`). Everywhere else the preflight is local and deterministic.
- **Native idempotency** is documented only by TikTok (`request_id` on campaign create, ten seconds) and ChatGPT Ads
  (`Idempotency-Key`). Elsewhere the product's own idempotency reference and a read-back must prevent duplicates.
- **ChatGPT Ads now has a public advertiser API** (`https://api.ads.openai.com/v1`). The product's earlier "no published API"
  status for it is out of date; the registry records the evidence, and no translator or adapter for it exists yet.
- **TikTok** stops supporting non-Search Legacy Manual Flow Sales, App Promotion and Lead Generation campaigns on its create
  endpoints from 2027-01-01.
- **Every live write needs access only the owner can obtain** — Meta Full access through App Review for other businesses'
  accounts, a Google Ads developer token at Basic or Standard, TikTok and X app review, LinkedIn Advertising API approval, a
  ChatGPT Ads account with an approved brand review. Until then creation stays `BLOCKED_EXTERNAL` in practice whatever the
  documentation says.

Some pages were read through a summarising fetch and two version numbers conflict on the platforms' own pages; anything not stated
on a page read is `unverified`. The registry is re-read before each creation adapter is built (PR G).

## Approval and creation (PR G)

```
approved plan → launch (fingerprint re-checked, quota claimed) → one execution per account, queued
             → worker: create paused → read back → verified → reconciled into campaigns / ad groups / ads → audit
```

- **Approve** (`POST /api/campaign-plans/{id}/approve`, `campaign_launch.approve`): refused while any preflight check is `blocked`.
  The approval records a **fingerprint** of the plan's version and every planned write. Approving creates nothing.
- **Launch** (`POST /api/campaign-plans/{id}/launch`, `campaign_launch.execute`, 202): the launch plan is rebuilt and fingerprinted;
  if anything changed since approval — an edit, an account's name or currency, a translation — the approval is withdrawn, the plan
  goes back to review (`approval_stale`), and nothing is created. Otherwise one `campaign_launches` row keeps the approved launch
  plan as it was, the monthly launch quota is claimed, and each account gets a `campaign_launch_executions` row with a **stable
  idempotency reference** (a hash of the launch and the account):

  | Account's preflight | Execution |
  |---|---|
  | ready, warning, awaiting credentials | `queued` — handed to the worker |
  | waiting on the platform (`blocked_external`) | `blocked_external` — not attempted, no call made |
  | unsupported | `skipped` — not attempted |

- **Never synchronous**: every creation runs on the queue (`CreateCampaignJob`, one try — the queue never repeats a create).
- **Created is not verified.** A platform answering with ids moves an execution to `created`; it becomes `verified` only after the
  campaign, ad group and ads are read back and match the plan (status, budget, currency, schedule, objective, hierarchy). A
  mismatch is `readback_mismatch`, and nothing is changed automatically. Only a verified creation is written into the product's own
  `campaigns`, `ad_groups` and `ads` (keyed on the platform's ids, assigned to the project, paused, labelled with its source), so
  the next sync updates those rows instead of adding a copy, and analytics reads the same structures.
- **Unknown is never retried blindly.** A request that got no answer is `unknown`. `POST /api/launch-executions/{id}/reconcile` looks
  on the platform for what that reference created: found, it is attached and verified like a first creation; not found, it becomes
  `failed` with `not_created` — the only state `retry` accepts. A retry reuses the same reference.
- **Partial launches keep what was created.** All accounts verified: the plan is `verified`. Some created: `partial`. None: `failed`.
  Nothing already created is deleted.
- **What runs today.** A sandbox connection runs the sandbox creator: the whole path executes, and every outcome, record and audit
  entry is labelled **SANDBOX**. A connection with real credentials is recorded `blocked_external` and **no platform is called**: no
  live creation adapter exists yet and no platform has confirmed this product's write access. The live adapters start from the
  payload drafts and the registry, and need the access each platform requires (see "What the documentation audit of 2026-10-24 found" above) — `BLOCKED_EXTERNAL`.
- **Audit**: approval, start (fingerprint, version, counts), every account's result (provider, account, status, source, provider ids,
  read-back, classification, idempotency reference) and every reconcile and retry. No credential or token is recorded anywhere.

## Activation (PR H)

Creating and switching on are separate acts. After a launch is **verified**:

1. **Ask** (`POST /api/campaign-plans/{id}/activation`, `campaign_launch.execute`): the plan becomes `activation_pending`; every
   verified account is marked pending. Nothing is switched on.
2. **Approve** (`POST /api/campaign-plans/{id}/activate`, `campaign_launch.activate` — administrators — and the campaign launch
   feature): the approval screen shows platform, account, budget, schedule, conversion, tracking and audience, and its button stays
   disabled until the approver confirms they have read it. Each account is re-checked — the platform must document
   `campaign.activate` and the account must still be bound to the project — and a refused account is left paused with the reason.
3. **Activate** on the queue (`ActivateCampaignJob`, one try): the account is switched on and read back. Only a confirmed read-back
   makes it `active`, and only then do the product's own campaign, ad group and ad rows read active.

The plan is `active` only when every verified account is confirmed active. Partly: it stays `activation_pending`, saying "Active on
N of M accounts", and the failed accounts can be approved again. None: back to `verified` with the reason. A sandbox connection is
switched on in the sandbox, labelled SANDBOX; real credentials are recorded `blocked_external` with no platform called.

## Operations and attention (PR H)

- **Campaign plans** (`/campaigns/plans`) is the agency's launch board: tabs for every state from draft to active and failed, each
  with its count, and filters by platform (the API also narrows by client, project and owner). Counts are computed over whatever the
  other filters match.
- **Needs attention** (the home screen's queue and Today) carries a `launches` stream, for anybody who may view plans: a creation
  whose outcome is unknown (critical — something may exist on a client's account that nobody has looked at), a read-back that
  differs from the plan (critical), a failed activation, a partial launch, and plans waiting for approval or for activation. Each
  item comes from a record, never a guess.
- **Existing platform writes** — pause, resume, daily budget — already run through the same architecture: their evidence is the
  write table the registry reads (`ProviderEntityWrites`), they need `provider_writes.execute` and the `provider_writes` plan feature
  (`feature:` middleware on the carry-out route), every change goes through an approval with a fingerprint
  (`docs/PROVIDER_WRITES.md`), and every attempt is audited before it leaves.
- **Suspending a workspace never pauses its campaigns on the platforms** (`docs/PLATFORM.md`): it stops this product's own writes
  and scheduled work. Pausing a client's campaigns is an explicit, approved write, never a side effect of billing.

