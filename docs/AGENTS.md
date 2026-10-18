# Agents, tools and MCP

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
| Orchestrator, Analyst, Campaign, Finance agents | `AgentOrchestrator` + three deterministic agents, run as the workspace's own agent member | Built |
| Routines | `agents:run analyst` daily 08:45 Riyadh; `agents:run finance` every 2 h; existing sync, digests, scheduled reports | Built |
| Automation rules | `automation_rules` (WHEN metric THEN item on Today), templates in the UI | Built |
| Metric history | `daily_metrics` per campaign / ad group / ad, raw provider payload separate | Existing |
| Creative, Leads, Reports, Project-ops agents | Not built as agents. Their analysis exists as product features (assistant analysers, lead funnel, report builder, attention queue) | **Not built** |
| Outbound MCP gateway (consuming provider MCP servers) | Not built. Providers are reached through the direct adapters only | **Not built** |
| WhatsApp approvals | Not built. In-app and email digests exist | **Not built** |
| LLM in the agents | None. Agents are deterministic; the assistant's model path is guarded and `BLOCKED_EXTERNAL` without a key | By design |

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
- **No delete tool exists.** New campaigns are not created by agents.
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

| Provider | Pause/resume | Daily budget | Official MCP server (as reported, 2026-10) |
|---|---|---|---|
| Meta | documented | documented | Reported official (open beta, April 2026; read/write scopes) — secondary sources, not verified against Meta's docs |
| Google Ads | documented | documented | Official, **read-only** (GAQL search) — secondary sources |
| Snapchat | documented | documented | Official, **read-only** — [Snap developer docs](https://developers.snap.com/marketing-api/Ads-MCP/Introduction) |
| LinkedIn | documented | documented | None found |
| TikTok | unverified | unverified | Reported official (May 2026) — secondary sources, not verified |
| X, ChatGPT Ads | unverified / not supported | — | None found |

An outbound MCP connection would be classified `OFFICIAL`, `TRUSTED_INTERNAL`, `APPROVED_THIRD_PARTY` or `UNVERIFIED`,
and only the first two could ever be routed to for a mutation. None is connected today; the direct adapters remain
the only route, and the agents never see a credential.

## Running it

```
php artisan agents:run            # analyst + campaign + finance, every workspace
php artisan agents:run analyst --workspace=<id>
php artisan demo:agency           # seeds client targets from real performance and runs the agents
```

Administrators see runs, routines, tools (calls / refusals in 24 h), provider readiness and MCP tokens at
**Settings → MCP & AI**. Everybody else sees the results as decision cards on **Today**, marked with the agent that
prepared them and the target they were compared against.
