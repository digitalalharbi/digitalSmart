# Product

**digitalSmart** (by Smart Code) is an advertising operating system: one place where an
agency plans, buys, produces, approves, measures and reports paid media across
Snapchat, TikTok, Meta, Google Ads, X, LinkedIn and ChatGPT Ads.

## Who it serves

| Role             | Needs                                                                 |
| ---------------- | --------------------------------------------------------------------- |
| Project manager  | Intake, scope, budget approval, task flow, client communication        |
| Media buyer      | Account bindings, campaign structure, pacing, optimisation evidence    |
| Content/creative | Briefs, specs per placement, versioning, approval trail                |
| Client           | Clear reporting, approvals, no access to anyone else's data            |
| Administrator    | Workspaces, roles, permissions, integrations, auditability             |

## Domains

Identity & access · organizations/workspaces · users, teams, roles & permissions ·
clients · projects · campaign requests & intake · platform access center · integrations
& provider connections · external ad accounts · campaigns/ad groups/ads · budgets,
pacing & spend limits · media planner & forecasting · creative hub · creative
specifications & validation · approvals · tasks & kanban · notifications · metrics
warehouse · analytics & dashboards · reports & shared client reports · recommendations
& alerts · AI assistant · presentation generator · files & brand assets · audit logs ·
settings & feature flags.

## Principles

**Isolation is the product.** `Workspace → Client → Project`, with explicit
project-level bindings to external ad accounts. An agency runs competitors in one
installation; a leak is an existential failure, not a bug.

**Never fabricate business data.** No invented metrics, no simulated integration
success, no projection presented as a guarantee. Forecasts always expose their
assumptions, ranges and benchmark version. Demo data is labelled `DEMO`.

**AI recommends; people decide.** Any action that changes live budget, status,
targeting or spend requires explicit human approval, unless a workspace automation
policy explicitly authorises it. Recommendations carry their evidence.

**Capability honesty.** If a provider's API cannot do something, the UI does not offer
it. Capability flags drive the interface.

**Bilingual by design.** Arabic (RTL) and English (LTR) are both first-class and
designed — not a mirrored afterthought. Numerals stay Latin so figures reconcile
against platform dashboards.

## What exists today (Phase 0)

A runnable, verified foundation: API-only Laravel backend on Clean Architecture, React
SPA with the design system and bilingual shell, PostgreSQL + Redis + Horizon + Reverb
wired and proven, full test/lint/static-analysis gate and CI. The Overview page reports
live system health; every other surface states which phase delivers it rather than
showing a fake dashboard.

Delivery continues in vertical slices — see `docs/ROADMAP.md`.
