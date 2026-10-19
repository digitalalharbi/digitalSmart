# Agents, tools and MCP

## Agents & Automation — automation-first (owner correction, 2026-10-19)

The operating model, in seven parts, each with one job:

| Part | Job | Where |
|---|---|---|
| **Rules** | Deterministic control: the same figures give the same verdict, every time | `RunAutomationRules`, `RuleEvaluator` |
| **Agents** | Analysis and reasoning over the agency's data — scheduled workers that find, compare and propose. Not chatbots: *Ask an agent* is a secondary action on a worker card | `app/Application/Agents/Agent/*` |
| **Approvals** | Governance: a person decides every proposal that would move money or delivery | the tool gateway and Today |
| **Execution** | Provider mutation, only where that platform's write is documented and credentials exist | provider adapters |
| **Routines** | Scheduled operation: when each agent runs | `routes/console.php`, `GetAgentsStatus` |
| **Action history** | Execution evidence: what each rule did or was refused, with before and after | `automation_rule_executions` |
| **Change log** | Rule configuration history: who changed which rule, what, when | `audit_logs` |

The area **Agents & Automation** (`/automation`) has five tabs:

| Tab | What it is |
|---|---|
| Rules | Summary cards (all actions, turn on, turn off, budget, bid, failed, inactive 30 days — each vs the previous period), filters, and one row per rule: on/off, name, scope, conditions as short lines, action, object, mode, last run, last result. Templates open a pre-filled builder. |
| Agents | Seven worker cards (status, role, open recommendations, success rate, routines, last run); a card opens its recent findings. *Ask an agent* is a secondary action. |
| Routines | Every schedule with its agent, last run, last result and next run in Riyadh time. |
| Action history | Every action a rule took or was refused — time, rule, client, project, platform, object, action, before, after, result (completed · waiting approval · no change required · failed · unsupported) and the reason. |
| Change log | Who changed which rule, what (created, enabled, disabled, mode, schedule, conditions, action, scope, archived), when. |

**Rule engine** (`RunAutomationRules`, every 15 minutes): scope (client, project, platform, ad account, campaign);
conditions with a direction each, combined AND / OR; metrics spend, ROAS, CPA, results, CTR, CPC, CPM, days since
start, budget used, cost change, spending with no results, not delivering, rejected, ends within; *for n days*;
schedule continuous / hourly / daily at a time in a time zone; cooldown per rule × campaign; a rule stands down
rather than undo another rule's opposite move (`CONFLICT`); dry run on a saved rule or a draft, writing nothing.

**Modes**: *Monitor* shows the finding and proposes nothing. *Recommend* (default) raises a proposal through the
tool gateway — approval, the project's budget limit, no stacking, cooldown, audit. *Auto* is shown and refused
(`AUTO_NOT_AUTHORISED`) — the database cannot store it — until the owner authorises auto-execution **and** the
platform's write is documented. Bid changes are `unsupported` everywhere; an action whose platform write is not
documented is recorded `unsupported`, never attempted.

**Capability, per action × platform** (`RuleCapability`, checked on save, in the dry run and before every action):
`supported` (write documented, credentials present) · `awaiting_credentials` (documented, no credentials) ·
`unverified` (the write exists but its current reference has not been read) · `unsupported` (bid changes; anything
undocumented). The builder shows each platform's state under the action; a rule whose every chosen platform is
unable is refused on save, and a bid change is refused outright.

**Data sufficiency.** A rule may require a minimum spend, campaign age and number of results before it judges a
campaign; one below them is *insufficient data* — counted in the dry run, never acted on.

**Results.** Completed · No change required · Waiting approval · Failed · Unsupported · **Cooldown** (the same rule
acted on this campaign inside its cooldown — recorded once, shown as *Cooldown active*) · **Conflict prevented**
(another rule moved this campaign the opposite way inside the cooldown; the row names it). No raw payloads.

**Dry run ("Test now")** on a draft or a saved rule: how many campaigns were looked at, matched, would be acted
on, lacked data, and skipped with the reason (unsupported, cooldown, conflict, awaiting credentials). It writes nothing.

**Bulk**: enable, disable, archive, change mode — each rule through its own save, each change in the change log.
Bulk can never set Auto.

**Where the results surface**: proposals land in Today through the same approval gateway as every other proposal;
the daily digest carries counts — triggered, executed, waiting approval, failed — never one message per evaluation.

**Routines** (Riyadh time): Daily performance review, Budget guardian, Tracking review (07:30), Lead quality review,
Creative review, Weekly client report, Monthly executive review (1st of the month, 11:00).

**Metric truth.** A campaign's figures are its campaign-level rows only; its ad groups and ads report the same money
again. `CampaignFiguresLevelTest` pins the aggregate, `CampaignAggregateConsumersTest` pins the portfolio, the rule
dry run and the agents' campaign tool.

**Next expansion — ad group and ad level.** The scope stops at campaign today. Rules on ad groups and ads need the
same aggregate per level and each platform's write at that level; until then the builder offers campaign scope only
rather than a level it cannot judge.


digitalSmart's agent layer is built so that **AI works inside controlled business workflows**: agents observe,
compare and *propose*; people decide; the product controls permissions, limits, provider access, execution and audit.

## What exists, and what it reuses

Audit first, extend second. Most of the "action" machinery already existed and was reused rather than duplicated:

| Required domain | In this product | Status |
|---|---|---|
| Action proposals | `approval_requests` — fingerprint, decision, carry-out, read-back — plus `origin`, `agent`, `evidence`, `risk`, `expires_at` | Built (extended) |
| Approval gateway | `DecideApproval` with fingerprint, `approvals.decide` (+ `budgets.approve` for spend) | Existing |
| Execution engine + reconciliation | `CarryOutApproval`: seven gates, provider write only where documented, confirmed only by reading back (`PROVIDER_WRITES.md`) | Existing |
| Action log | Audit trail + `agent_tool_calls` (append-only, every call incl. refusals) | Built |
| Client rules / project targets | `operating_rules`, resolved project → client → agency default, with the source of each value | Built |
| Tool registry + gateway | `ToolGateway` + 9 `ToolHandler`s; permission, schema, rate limit, log | Built |
| digitalSmart MCP server | `POST /api/mcp` (JSON-RPC 2.0: `initialize`, `ping`, `tools/list`, `tools/call`); `mcp`-only tokens | Built |
| Orchestrator | Fixed order (Analyst → Campaign → Finance → Creative → Leads → Reports → Project operations), conflict resolution (a pause proposal outranks a creative refresh), intent routing EN/AR, caller identity restored | Built |
| Analyst, Campaign, Finance agents | Deterministic, run as the workspace's own agent member | Built |
| Creative agent | CTR down ≥25% week on week on ads seen ≥1,000 times → brief task naming the drop, frequency and the strongest sibling ad | Built |
| Leads agent | Leads, qualified and sales kept apart; fortnight decline; low qualification of *reached* leads; ≥10 leads to judge | Built |
| Reports agent | Weekly brief per client from real figures, per currency; revenue/ROAS only where reported; sources and days covered | Built |
| Project-operations agent | One card per project that has piled up; never counts the agents' own cards | Built |
| Agent findings | `agent_findings` — one open finding per thing per agent, refreshed each run, resolved when no longer seen; Today stream `agents` | Built |
| Ask the agents | `POST /api/agents/ask` — question routed (model or rules) to agents, answered with the asker's own visible findings | Built |
| Model routing | `ModelPurpose` → strong (narrative, planning) / fast (routing, summary); workspace key encrypted, last four only; `AWAITING_MODEL_CREDENTIALS` | Built |
| Routines | analyst 08:45, operations 08:30, leads 10:00 daily; creative Sun+Wed 09:00; weekly briefs Thu 14:00 (Riyadh); finance every 2 h; `automation:run` hourly | Built |
| Automation rules | WHEN … AND … FOR n days THEN alert / propose pause / propose budget cut / task; trust 0–3 per rule, 4 refused | Built |
| Campaign hierarchy | Campaign → ad group → ad with creative previews, CTR trend and frequency; ad-group/ad writes shown disabled | Built |
| Cross-platform builder | One blueprint → each platform's objective and field support → paused drafts + launch requests | Built |
| One-time approval links | WhatsApp/email, keyed hash, one person, one approval, expiry, single use, POST only; delivery `awaiting_credentials` | Built |
| Metric history | `daily_metrics` per campaign / ad group / ad, raw provider payload separate | Existing |
| Outbound provider MCP | Registry from official docs (below); reads would route to a connected official server; writes never route through MCP | Registry built; no connection |

## The rules the code enforces (not the prompt)

- **Permissions are the caller's own.** A tool runs the same use case a screen runs, in the caller's name. The agent
  identity's role reads, opens tasks and *requests* approvals; it holds neither `approvals.decide` nor
  `provider_writes.execute`, and cannot sign in (no password anybody holds).
- **Every tool input is validated** against its declared schema; unknown fields are refused, never ignored.
- **Budget proposals** are refused when the campaign has no daily budget, the provider's budget write is not
  documented, the value is unchanged, the move exceeds the project's `max_budget_change_pct` (agency default 20%), or
  it exceeds `max_daily_budget`.
- **No stacking, no hammering:** a second proposal for a campaign with one pending is refused; another within 24 h is
  refused (cooldown). An agent never opens the same task twice while it is open.
- **Proposals expire** after three days (withdrawn as expired by the routine).
- **No delete tool exists.** New campaigns are not created by agents; the builder makes *paused drafts* and launch
  requests a person approves, and calls no platform.
- **Trust is given, never taken.** A rule's trust level is set by a person; an action above it is refused, level 4
  (platform changes without approval) cannot be stored, and no tool changes a rule.
- **Approval links** are the person's own authority, re-read when pressed; a link-preview GET decides nothing.
- **Targets are never invented.** A project with no target is not judged; the run records how many were left alone.
- **Text is data.** Reasons, campaign names and client words are stored and shown, never followed. A test stores a
  prompt-injection string as a reason and asserts nothing was decided, enacted or raised.
- **MCP tokens** carry only the `mcp` ability and are refused on every other route; a token cannot mint tokens.
- **Tenancy:** every tool, run and proposal is scoped to the caller's workspace and visible projects; another
  workspace's id answers `not_found`.

## Tools

| Tool | Risk | Approval |
|---|---|---|
| `list_campaigns`, `get_campaign`, `get_budget_status`, `get_attention_items`, `get_operating_rules` | read | — |
| `create_task` | internal | — |
| `propose_pause_campaign`, `propose_resume_campaign`, `propose_budget_change` | proposal | always |

## Provider capability (agency's own readiness)

Write support is `Provider::writeSupport()`; only `documented` permits a carry-out attempt. Live state for every
provider remains `AWAITING_CREDENTIALS`.

Official MCP servers, read on each platform's **own** documentation on 2026-10-19 (`ProviderMcp`). Directory listings,
blogs and community servers are not evidence and are not listed.

| Provider | Pause/resume (direct) | Daily budget (direct) | Official MCP server | Its documentation says |
|---|---|---|---|---|
| Google Ads | documented | documented | [developers.google.com](https://developers.google.com/google-ads/api/docs/developer-toolkit/mcp-server) | Read-only: `list_accessible_customers`, `search` (GAQL), `get_resource_metadata` |
| Snapchat | documented | documented | [developers.snap.com](https://developers.snap.com/marketing-api/Ads-MCP/Introduction) — `mcp.snapchat.com/ads` | Read-only; writes "planned for a future release" |
| Meta | documented | documented | [developers.facebook.com](https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-overview) — `mcp.facebook.com/ads` | "Create and edit ads, ad sets, and campaigns" |
| TikTok | unverified | unverified | [ads.tiktok.com help](https://ads.tiktok.com/resources/help/article/about-tiktok-for-business-mcp-server) — `business-api.tiktok.com/open_mcp/…` | Campaign create, pause, budget tools |
| LinkedIn, X, ChatGPT Ads | — | — | None on their own documentation | — |

**Routing.** A read goes to a platform's official MCP server only when the workspace has connected it (none has),
otherwise to the direct API. A write goes to the direct API where its write is documented — the only path with a
read-back that confirms the change — and nowhere otherwise (`mcp_write_not_enabled` for Meta/TikTok, whose MCP servers
document writes). `CarryOutApproval` checks the route before every write. Agents never see a credential.

## Running it

```
php artisan agents:run            # analyst + campaign + finance, every workspace
php artisan agents:run analyst --workspace=<id>
php artisan demo:agency           # seeds client targets from real performance and runs the agents
```

Administrators see runs, routines, tools (calls / refusals in 24 h), provider readiness and MCP tokens at
**Settings → MCP & AI**. Everybody else sees the results as decision cards on **Today**, marked with the agent that
prepared them and the target they were compared against.
