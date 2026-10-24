# The platform console and the subscription lifecycle

PR D of the SaaS chain (`docs/SAAS_CAMPAIGN_MASTER.md`). How Smart Code operates tenants, and what a subscription state does to a
workspace.

## Subscription states

`App\Domain\Saas\SubscriptionStatus`, on `workspace_subscriptions.status`. **No state deletes anything.**

| State | Sign in, read | Write | Scheduled work | People in the workspace see |
|---|---|---|---|---|
| `trial` | yes | yes | yes | the trial's end date |
| `active` | yes | yes | yes | nothing |
| `past_due` | yes | yes | yes | that a payment is overdue |
| `grace` | yes | yes | yes | the end of the grace period |
| `suspended` | yes | **no** | **no** | that it is paused, the reason, and who to contact |
| `cancelled` | yes | **no** | **no** | that it has ended; data kept and readable |

- **Writes.** Every non-read request in a suspended or cancelled workspace answers `423 workspace_not_writable` with the state and
  the reason (`EnsureWorkspaceWritable`). Signing out, changing your own password and switching to another workspace still work.
- **Scheduled work.** Advertising syncs, store reads, rule runs, agents and digests all enumerate workspaces through one SQL
  expression (`RunningWorkspaces`), so a stopped workspace is skipped by all of them or by none.
- **Nothing moves on its own.** There is no billing provider connected (`BLOCKED_EXTERNAL`: a billing account and a decision on
  it). A trial or grace end date passing is shown to the operator, who decides. When billing exists, it calls the same
  `ChangeSubscriptionStatus` use case an operator does.
- Every existing workspace was migrated as `active`; nothing about it changed.
- **Suspending never pauses campaigns on the platforms.** It stops this product's writes and scheduled work; the client's live
  campaigns keep running exactly as they were. Pausing them is an explicit, approved platform write (`docs/PROVIDER_WRITES.md`),
  never a side effect of a subscription state.

## Platform operators

A flag on the person (`users.is_platform_operator`), **set only from the console** on the server:

```bash
php artisan platform:operator ops@smartcode.example --by="Mohammed" --reason="On-call rota"
php artisan platform:operator ops@smartcode.example --revoke --by="Mohammed" --reason="Left the rota"
```

Both require a name and a reason and are recorded as `platform.operator_changed`, a security event against no workspace. No
screen makes anybody an operator, so nobody signed in to the product can make themselves one.

**An operator is not a tenant owner.** Being an operator grants no membership: an operator who belongs to no workspace sees no
client, campaign or lead anywhere (`PlatformTest`), and an owner of every workspace is still refused the console. The console
itself shows counts and states — never a client's name, a figure, a message or a credential.

**Support impersonation is not implemented.** If it is built later it needs an explicit reason, a short life, a persistent banner,
audit at start and end naming the workspace, no access to secrets, and no route around provider-write approval. Until then an
operator who needs to see inside a workspace is invited into it like anybody else, by its own administrators.

## The console

`/platform` (outside the workspace shell), from `GET /api/platform/workspaces` and `GET /api/platform/workspaces/{id}`:

- every workspace with its type, plan, subscription state, team and client seats, clients, projects, platform connections by
  status, overrides, and **provisioning status** — complete when it has an active owner, its system roles and a subscription, and
  naming what is missing otherwise;
- one workspace in full: every limit with used and allowed, every feature, the overrides, and its commercial history (creation,
  plan, override and state changes, newest first);
- three acts, each with a required reason and recorded in the workspace's own audit trail under the operator's address: change the
  state, change the plan, record an override.

System health stays where it is (`GET /api/health`, Administration → Advanced diagnostics).

## Creating a workspace

`POST /api/platform/workspaces`, from the console: name, optional address, type, language, time zone, currency, plan, trial or
active (a trial needs a length), and the owner's name and email. In one transaction: the workspace, its system roles, its plan and
state, and the owner. An owner who already has an account joins on it and keeps their own password; otherwise an account is made
with a one-time password, shown to the operator once, that must be replaced at first sign-in.

**Never from a public request.** The access-request flow (`docs/ACCESS_REQUESTS.md` / Administration → Account requests) stays
the way in: a request is read and approved by a person, and the approver then either invites the person into an existing
workspace from the request or asks an operator to create a new one here. No provider connection comes with a new workspace — it
trusts nothing until its own people connect it.

## Agency mode and the Agency Control Center

One agency workspace holds many clients, projects and ad accounts (`ProviderConnection → ExternalAccount →
ProjectIntegrationBinding`); an agency never needs a workspace per client. What the brief calls the Agency Control Center already
exists, in the places a person looks for each part, so no second surface was built beside them:

| Brief | Where |
|---|---|
| Clients, projects, spend, revenue, ROAS, campaign health, needs attention | Overview command centre, Portfolio (`/portfolio`) |
| Leads, qualified leads, appointments, sales, SLA | Overview funnel; Tracking → Operations and Lead queue |
| Tracking health | Tracking → Health; Portfolio column |
| Provider health | Integrations → sync monitor and account queue |
| Open tasks, pending approvals | Today, Work & Approvals |
| Drill-down workspace → client → project → provider → account → campaign → ad group → ad | Portfolio → client → project → Campaigns → Ad Groups → Ads |

A brand or freelancer workspace is the same architecture with the workspace type changing wording and defaults, never
authorisation or isolation.

## Proof

`backend/tests/Feature/Saas/PlatformTest.php`: only an operator reaches the console, and an operator reaches nothing inside a
workspace; operator access is granted and withdrawn only from the console and audited; suspending keeps everything readable,
refuses writes with the reason, stops scheduled work and loses nothing, and resuming restores all of it; creating a workspace
provisions roles, plan, trial and owner, with a one-time password only for a new account and a refusal for a taken address;
plan and override changes from the console are named after the operator. Frontend: `PlatformPage.test.tsx`,
`SubscriptionNotice.test.tsx`.
