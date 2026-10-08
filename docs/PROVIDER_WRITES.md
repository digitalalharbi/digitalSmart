# Provider write operations

Everything else in this product reads. This is the one place it writes to somebody's advertising account, and
the rules below exist because the failure mode is not a wrong number on a screen — it is a campaign paused that
should not have been, or a budget set twice.

Three sentences state the whole design:

1. **An approval is not an instruction.** Approving a change records a decision. Carrying it out is a second,
   separate act, and the surface says so before either button is pressed.
2. **A write is attempted only where the provider's own documentation was read for that operation.** Not where
   the provider's UI offers the change, and not where it seems reasonable that an API would.
3. **A write is confirmed only by reading the campaign back.** An accepted request is not a confirmation.

## What can be carried out

| Operation | Authorised by approval kind | Needs capability |
|---|---|---|
| `pause_campaign` | `campaign_pause` | `campaign_status_mutation` |
| `resume_campaign` | `campaign_launch` | `campaign_status_mutation` |
| `set_campaign_daily_budget` | `spend_change` | `budget_mutation` |

Three operations, and the table is the complete list. Every other approval kind — a targeting change, a creative
going live, a conversation with a client about a figure — is refused with `kind_not_executable`, which is the
ordinary answer rather than a gap.

### Provider support

`Provider::writeSupport()` returns one of three states, and only `documented` permits an attempt.

| Provider | Pause / resume | Daily budget | Endpoint read |
|---|---|---|---|
| Meta | documented | documented | `POST /{campaign_id}` with `status=ACTIVE\|PAUSED`, or `daily_budget` in minor units |
| Google Ads | documented | documented | `CampaignService.mutateCampaigns` / `CampaignBudgetService.mutateCampaignBudgets`, both with `update_mask` |
| Snapchat | documented | documented | `PATCH /v1/adaccounts/{ad_account_id}/campaigns/{campaign_id}`; `daily_budget_micro` has a documented minimum of 20,000,000 |
| LinkedIn | documented | documented | `POST /rest/adAccounts/{id}/adCampaigns/{id}` with `X-RestLi-Method: PARTIAL_UPDATE` and `patch.$set` |
| TikTok, X | unverified | unverified | — |
| everything else | not supported | not supported | — |

`unverified` is deliberate and is not a smaller `documented`. These providers very likely do support the
operation; nobody here has read the current reference for it, and a product that attempted a write on that basis
would be guessing with a client's money. The exact endpoint and fields read for each `documented` entry are
recorded in `Provider::writeReference()` and written into the audit entry for every attempt, so a reader a year
later can tell which contract the attempt was built against.

## The seven gates

`CarryOutApproval` refuses, in order, before anything leaves the process:

| Gate | Refusal | Why it is first |
|---|---|---|
| Permission | 403 | `provider_writes.execute`, which is not `approvals.decide`. Approving a change and being the credentials that move money are different acts. |
| State | `not_approved` | Nothing carries out a pending, declined or withdrawn request. |
| Enactment | `already_settled` | A confirmed or abandoned request has had its answer. This is also what stops a second click repeating a write. |
| Fingerprint | `proposal_changed` | The decision names the proposal it read. If the proposal was amended since, this is a different change and needs a different approval. |
| Target | `proposal_names_no_campaign` | The proposal must name `provider`, `external_account_id` and `external_campaign_id` — and, for a budget change, `daily_budget_minor`. A proposal saying "pause the retail campaign" is not a campaign id, and matching by name would eventually pause a different campaign. |
| Documentation | `provider_not_documented` | See the table above. |
| Connection and capability | `not_connected`, `capability_missing` | The workspace's own connection supplies the credentials; no connection, no write. |

A refusal is answered **409** with its `code`, not 422: the request was well formed and was correct when its
sender read the screen.

## What the audit records

Three actions, and the first is written **before** the request leaves:

- `provider_write_attempted` — the account, the campaign, the amount, and the documentation reference the
  attempt was built against. An outcome of `unknown` has no response to record, and an entry written beforehand
  is the only thing that can say a request was sent at all.
- `provider_write_confirmed` — the state read back, the provider's request id, and the source state.
- `provider_write_failed` — the failure, the provider's own message, its request id, and whether retrying is safe.

The source state is on the entry for ever. A write against sandbox credentials is a `SANDBOX` entry, and an
audit trail recording a simulated pause as a real one is worse than no trail.

## Failures, and the one that must not be retried

| Failure | Safe to retry |
|---|---|
| `not_authorised`, `not_found`, `conflict`, `rate_limited`, `unavailable` | yes |
| `rejected` | **no** — the provider understood and declined; the same request declines again |
| `unknown` | **no** — a timeout or dropped connection, where nobody knows whether the change was made |

`unknown` is the reason the enum has a seventh case. A reader told only "it failed" presses the button again,
and a second attempt after an unknown outcome is how a campaign gets paused twice or a budget set twice. The
surface says so in words — *"Do not try this again until somebody has checked the platform"* — and the API
carries `safe_to_retry: false`.

A failed attempt leaves the approval exactly where it was: approved, awaiting a hand. Not abandoned — nobody has
decided against the change — and certainly not confirmed. That is what lets somebody retry it or go and do it by
hand and record that instead.

## No live adapter yet

`SandboxCampaignWriter` is the only writer registered. It refuses outright if handed credentials that are not
sandbox credentials — it does not quietly simulate a real account — and every outcome it returns carries
`MetricSource::Sandbox`, which the surface labels on the same panel as the confirmation.

Writing the live adapters is a **BLOCKED_EXTERNAL** item for every provider in the table. Each one needs:

- an app in the provider's developer programme, approved for the write scope (Meta `ads_management`, Google Ads
  a developer token at standard access, Snapchat and LinkedIn their own review);
- the account owner's OAuth authorisation for that scope, which only the company holds;
- credentials for a real ad account to test against, since a write cannot be verified against a fixture.

Until those exist the contract, the gates, the audit, the failure taxonomy and the surface are all real and
tested; only the HTTP call is simulated, and the product says so everywhere the result appears.

## Surface

`POST /api/approvals/{approval}/carry-out` returns the request as it now stands **and** the outcome beside it,
whatever it was — a failed write leaves the request unchanged, so the response has to carry the reason rather
than leave a reader to infer it.

On the approvals screen the platform button appears only where `can_be_carried_out_here` is true and the reader
holds `provider_writes.execute`. Where it is false, `carry_out_blocked_because` is **published rather than the
control hidden**: the next thing a reader does is open the platform, which is the right thing once they know why.

"I have made this change" stays beside it. A person making the change by hand and this product making it are
both legitimate, and the record says which happened — the note on a provider-confirmed enactment names the
provider rather than a person.

## Tests

- `tests/Unit/Domain/ProviderWriteTest.php` — the operation/kind mapping, the three support states, the write
  target read out of a free-form proposal, and the refusals that are not retryable.
- `tests/Feature/Integration/CarryOutApprovalTest.php` — the seven gates, the audit pair around an attempt, the
  enactment recorded only on a read-back, and the 409 bodies.
- `src/features/approvals/ApprovalsPage.test.tsx` — the control's two absences (not documented, no permission),
  the confirmation with its sandbox label, and the failure panel with its do-not-retry sentence.
- `e2e/approvals.spec.ts` — raises a request naming a sandbox campaign, approves it, carries it out, and asserts
  the source label on the confirmation.
