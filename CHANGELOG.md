# Changelog

All notable changes to digitalSmart are recorded here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[Semantic Versioning](https://semver.org/).

## CI that actually runs, and protection a server enforces

The owner chose option C from `docs/CI_STATUS.md`: the repository is public, which is what makes both
GitHub Actions and rulesets free. Everything below followed the same day.

### Changed

- **The repository is public.** The history was scanned first rather than afterwards: 261 commits, no
  `.env`, no private key, no `APP_KEY`, no provider token — only `.env.example` files and a 1×1 PNG test
  fixture. Every stored credential in this installation is the literal `sandbox`, so there was never a live
  secret to leak. The cost is stated plainly in `docs/CI_STATUS.md`: the code, the documents and the whole
  history are now readable, indexable and forkable by anyone.
- **`ci.yml` gains a `merge_group` trigger**, so the checks run against the combination a merge queue
  actually builds — the pull request on top of `main` and whatever is queued ahead of it.

### Added

- **Ruleset `main protection` (id 25378817), active.** Pull request required, the branch must be up to date,
  review threads resolved, no force-push, no deletion. It is the thing GitHub refused with *"Upgrade to
  GitHub Pro or make this repository public"* four hours earlier.
- **`Governance` is a required check**, which CampaignsHub's equivalent ruleset does not have. The no-fees
  rule is the one a careless commit breaks silently, so it is enforced by the server rather than by manners.
- **The admin bypass is deliberate, and recorded as such.** `docs/CHANGE_MANAGEMENT.md` documents an
  emergency direct push to `main`; a rule with no bypass would make that procedure an untruth. The
  consequence is stated rather than left flattering: against the owner's own account the ruleset yields, so
  `.githooks/pre-push` is still the only thing standing between them and an accidental direct push.

### Not copied, with the reason

- **`merge-queue.yml`.** CampaignsHub's runs on `schedule: '*/15 * * * *'` and reads
  `secrets.MERGE_QUEUE_TOKEN`. `scripts/governance/no-paid-model.sh` refuses both, so copying it verbatim
  would fail the `governance` job — which every other job waits on — and take CI down in the name of
  improving it. Stripped of the schedule and the secret it is five kilobytes of shell doing what the
  platform does natively and serially. GitHub's own merge queue is used instead.
- **`deploy-production.yml`.** Four `secrets.` references, and no host for them to point at.
  `BLOCKED_EXTERNAL`: a hosting account. Recorded in `docs/PRODUCTION.md` rather than half-built, including
  the decision it would force — the no-secrets rule was written against *model* keys and would need the
  owner's word to admit a deploy credential.
- **`claude.yml` and `campaignshub-autopilot.yml`.** The paid model path the owner excluded on 2026-10-19.
  The guard fails on a workflow merely *named* like either.

### Found the moment CI could speak again

- **`Frontend` is red on `main`, and has been invisibly.** `src/shared/lib/money.test.ts` reads the
  backend's `CurrencyExponent.php` with `?raw` — deliberately, because a currency-exponent table copied into
  two stacks with nothing comparing them is a table that drifts, and a drifted exponent misreports a
  client's spend by a factor of ten. Vite denies reads above the package root; the allow-list that permits
  it lives in `frontend/vite.config.ts`, which is in **another session's uncommitted work**. Half the change
  is on `main` and half is not. Not fixed here — those files are not this session's to stage — but it now
  blocks every merge, so it is the first item in `docs/RESUME_STATE.md`.
- **`Backend` — 2055 passed, 1 failed**, and the cause was established before anything was edited.
  `AgencyScaleTest > it keeps the agency views within their stated query budget` reported
  `/api/attention used 18 statements` against a budget of 17.

  Not a regression and not an N+1. The invariance test beside it — the one asserting the count does not
  grow with the agency — **passed**, so the extra statements are constant. A probe dumping all eighteen
  showed why: 3 for authentication and permissions, then 2 campaigns, 3 budgets, 2 tracking, 4 approvals,
  1 requests, 1 tasks — 16, exactly the figure the budget of 17 was set against on 2026-10-15 when the
  queue was halved — plus **one query each** from two streams added afterwards and never accounted for:
  `operating_rules` (Automation, 2026-10-18) and `agent_findings` (Agents, 2026-10-19).

  The budget is now **19**: the measured 18 plus the +1 slack this entry adopted when it was halved,
  rather than the +2 the rest of the file uses, so a third stream added without thought still fails here.
  The three approval reads were checked rather than assumed to be duplication — they ask three different
  questions (awaiting decision by age, approved and awaiting a hand, recently settled), each with its own
  filter and ordering, and all three predate the budget.

  The stale prose went with it: two comments still said the queue consults *six* modules, and
  `docs/AGENCY_SCALE.md` said six streams and 17 statements. The millisecond figure in that table is
  marked as **not** re-measured rather than quietly carried over, because a number nobody has taken again
  is worth less than one that says when it was taken.

- This is the argument for the whole change, made by accident on its first run: both defects had been
  sitting on `main` behind a CI that failed in four seconds without executing a step.

## Production deployment pipeline and the corrected secret policy

### Added

- `.github/workflows/deploy-production.yml` — a merge to `main` deploys over SSH (GitHub → server, never polling);
  one deploy at a time; `production` environment; pinned host key; `AWAITING_SERVER_ACCESS` until a server exists.
- `scripts/deploy/` — release-based deploy: build beside the live release, migrate forward only and stop on failure,
  cache, atomic `current` switch, reload FPM / Horizon / queue / Reverb where configured, health on `/api/health`,
  the frontend, Horizon and boot; switch back on an unhealthy result; `deployments.log`. Never `migrate:fresh`,
  `db:wipe`, `db:seed` or `demo:agency`.
- `scripts/check-disk.sh` — 10 GB before E2E; the deploy refuses below 2 GB.
- `docs/DEPLOYMENT.md` — layout, first-time setup, secrets, history, rollback (application vs database), disk.

### Changed

- Secret policy: no secret value in any file, literal, commit, log or document; encrypted `PRODUCTION_*` secrets
  allowed for deployment infrastructure only, in `deploy-production.yml` only. The guard scans every tracked file
  for keys and tokens, refuses unapproved, misplaced and dynamic secret reads, a deploy from anything but `main`,
  and destructive database commands in deploy files (21 cases).
- `CLAUDE.md` reflects reality: public repository, ruleset on `main`, Actions active, one session per branch and
  worktree, the disk threshold.

## Governance: one source of truth, one path to main, no fees

### Added

- `CLAUDE.md` **session bootstrap**: `origin/main` is the single source of truth; every session starts with
  `git fetch origin`, `git log --oneline -10 origin/main`, `git status`, then `docs/RESUME_STATE.md`.
- **Path to main**: request → branch → tests → PR → CI → review → merge; branch names `claude/<n>-<slug>`,
  `feat/…`, `fix/…`, `chore/…`; no direct push to `main` (`.githooks/pre-push`).
- **No-fees execution policy** (owner decision): GitHub never invokes a model; no `claude.yml`, no autopilot, no
  `anthropics/claude-code-action`, no `ANTHROPIC_API_KEY`, no secrets, no schedules in any workflow.
  `scripts/governance/no-paid-model.sh` enforces it as the first CI job and in the pre-push hook;
  `test-no-paid-model.sh` proves it fails on each forbidden thing.
- `.github/pull_request_template.md` and issue templates (bug, improvement, integration activation).
- `docs/RESUME_STATE.md`, `docs/CHANGE_MANAGEMENT.md`, `docs/EXECUTION_POLICY.md`, `docs/CI_STATUS.md`.

### Known

- GitHub Actions does not start on this private repository (account billing state, verified), and server-side
  branch protection is unavailable on the plan while private. Three free options are evaluated in
  `docs/CI_STATUS.md`; the owner decides.

## The business layer: specialist agents, rules that propose, one-time approvals, cross-platform drafts

### Added

- **Specialist agents.** Creative (tiring ads → brief task with the drop, frequency and the strongest sibling ad),
  Leads (leads, qualified and sales kept apart; fortnight decline; low qualification of reached leads), Reports
  (weekly brief per client from real figures, per currency, revenue only where reported) and Project operations
  (one card per project that has piled up). Findings are stored, refreshed each run and resolved when no longer
  seen; Today shows them as picture-first cards with the agent that prepared them.
- **Orchestrator:** fixed order with conflict resolution (a pause proposal outranks a creative refresh), intent
  routing in English and Arabic, and *Ask the agents* answered in the asker's own scope.
- **Model routing:** strong model for narrative and planning, fast model for routing and summaries; a workspace's
  own key, encrypted, last four only; `AWAITING_MODEL_CREDENTIALS` without one. A routing model may only name agents.
- **Campaign hierarchy:** a campaign opens into ad groups and ads with creative previews, click-through trend and
  frequency; ad-group and ad writes are shown disabled with the reason.
- **Rules:** WHEN … AND … FOR n days THEN alert / propose pause / propose a budget cut / open a task, with a trust
  level per rule (0 watch · 1 alert · 2 propose · 3 also open tasks; 4 refused). New metrics: rejected, ends within.
  `automation:run` hourly; proposals go through the same limits and approval as everyone else's.
- **One-time approval links** for WhatsApp and email: keyed hash, one person, one approval, expiry, single use, POST
  only, permission and proposal re-read when pressed. Delivery is `awaiting_credentials` until a provider is set.
- **Cross-platform builder:** one blueprint, each platform's own objective, field-by-field supported / partial /
  unsupported, paused drafts and a launch request each. No platform is called.
- **Bulk actions** on Campaigns: ask to pause/resume, ask to change daily budget, open tasks — per campaign through
  its own use case.
- **Shared period** (7/30/90 days and comparison) across Overview, Campaigns, Performance and Leads.
- **Provider MCP registry** from each platform's own documentation (checked 2026-10-19); writes never route through
  MCP — the direct API with read-back is the only write path.
- **Demo:** SANDBOX creative artwork, ad groups and ads, a lead-quality problem, four rules, every agent's output.

### Not built

- Live WhatsApp/email delivery, live platform MCP connections, model calls without a key, campaign creation on a
  platform, ad-group/ad writes. The commerce surfaces read their own period until the money/format work there lands.

## Simplification, the agency command centre, and the agent layer

### Added

- **Shell:** grey page, one rounded workspace, a floating navigation pill with the graphite active state, an icon
  rail, orange accent with a graphite primary action. Ten areas replace twenty destinations; the top bar is
  role-aware; each area's pages are tabs inside it. Language-aware fonts (DIN Next Arabic slot, IBM Plex Sans Arabic
  fallback, JetBrains Mono for diagnostics).
- **Overview command centre:** eight figures, the spend line, campaign-health ring, top clients, platform mix,
  lead funnel, what needs you, recent activity. Role homes appear above it for non-executive roles.
- **Today:** one list of decisions. Approvals are approve / change / reject in one click, with before-and-after or
  metric-against-target on the card and the agent that prepared it.
- **Rules:** one-tap templates and when/then cards; the only outcome of a rule is an item on Today.
- **Clients:** cards carry health rings, spend, results and alerts. **Projects:** six tabs, rings for health,
  budget and work, and a targets-and-limits card.
- **Campaigns:** figures, spend line, health ring and platform mix; a short filter bar with more filters.
- **Chart system:** CampaignsHub chart language — monotone soft lines, dashed grid, 62% rings, 6px bars, card
  tooltips — shared by the SVG primitives and the ECharts theme; time stays chronological in Arabic.
- **Agents:** operating rules (targets and limits resolved project → client → default); a tool gateway with nine
  business-level tools; digitalSmart's own MCP server with `mcp`-only tokens; Analyst, Campaign and Finance agents
  running as the workspace's own member on routines; budget and status proposals refused in code over limits,
  as duplicates, or inside a day; proposals expire. Settings → MCP & AI for administrators. See `docs/AGENTS.md`.

### Fixed

- A client's portfolio page carried the whole agency's leads and sales.
- Closing any state-opened dialog returned focus to the page body.
- Overview said "no spend recorded" when spend spanned currencies.

## UX restructure and the sandbox agency

The agency surfaces re-composed around how the work is done, and a realistic demo to prove them against. Ways to
run it, and what is in it, are in `docs/DEMO.md`.

### Added

- **Project workspace:** a real hub — scope in the header (client, owner, dates, budget, campaign health,
  readiness), nine tabs, and a right-hand panel for the team, alerts, background work and recent changes.
- **Campaign control:** ad-account, period and *my projects* filters; summary / detailed; add-task from a card;
  ready-made views. A campaign's own page gains a context sidebar and the assistant's reading of its client.
- **Creative hub:** gallery, board and table with shape-true previews, and a desk for workload, lateness,
  review, readiness, deadlines and recent approvals. Board cards now carry their version count and the shape of
  the newest file.
- **Reports and presentations:** report covers and an executive / detailed reading; a preview-first deck with a
  slide rail, one large slide, and the whole deck on print.
- **Administration:** sectioned — people, roles & permissions (read-only, from the live role table), setup,
  audit, system health, and *Advanced diagnostics* as the one place for technical detail.
- **Digests:** a monthly executive digest beside the morning, budget and weekly ones.
- **Contextual assistant:** issue, figures, recommendation (only when the evidence is firm) and confidence, on
  client, project and campaign.
- **Sandbox notice and tour** on every screen of a demo workspace.

### Changed

- The agency overview's project matrix is bounded (the sixty that most need a look) and says whose each project is.
- Every grid item may shrink below its content; a long campaign name no longer widens a phone screen.
- Access-denied pages have a first-level heading; clients are kept to their portal on every route.
- System health moved from the overview to Administration → Advanced diagnostics.

### Fixed

- Role names printing as translation keys in Administration (a new section title had overwritten the role
  names); caught by a test that now fails on any visible key.
- Analytics filters, metric cards and provider cards overflowing at phone width.

## Agency scale: the control plane, measured

The agency surfaces — one place to see every campaign, client, budget, lead and store — built over the
tenancy model that was already right, and measured at the constitution's size. The audit is
`docs/AGENCY_SCALE.md`; every rule in it is MET or BLOCKED, with the file or test that carries it.

### Added

- **Surfaces:** Campaign Control Center with a review queue and bulk placement; Agency Portfolio, client
  portfolio and a project workspace hub; Budget Control Center; Attention queue with *my clients / my
  projects*; role-shaped Home; global search and command palette with working context, pins and recents;
  stores across clients; lead scopes (all / my clients / my projects / my leads).
- **Integrations:** agency- and client-owned connections, the unassigned-account queue, a central sync
  monitor, per-provider pacing against published limits, and history reads for a chosen 30/90/180/365-day
  window on the lowest-priority queue.
- **Operations:** queued CSV/Excel exports with status, notification and owner-only download; scheduled
  exports (daily / weekly / monthly) that run as their owner and halt with a reason when access is lost;
  digests that ring the bell once; saved views (private / project team / workspace) on three surfaces;
  internal tags with bulk apply; project, campaign and data-quality readiness; client offboarding that
  stops what recurs and deletes nothing; cross-client benchmarks that are internal-only and suppressed
  below three clients.
- **Proof:** `AgencyScaleSeeder` (100 clients, 230 projects, 300 accounts, 2,000 campaigns, 180,000 daily
  rows, 5,000 leads, 20,000 orders); statement-count invariance and absolute budgets with a wall-clock
  ceiling; a sweep of thirty-six agency-wide endpoints for another client's data; agency-surface e2e on
  desktop and mobile, with accessibility, Arabic and dark.

### Fixed

- **A budget read that took four minutes in six statements** (a correlated sub-select per metric row,
  over statistics the planner did not have). 58 ms now. Attention and the portfolio read the same
  function and were equally slow.
- **A lead list that issued a `COUNT` per lead.**
- **Twelve screens that listed a hundred clients and silently dropped the 101st** now search instead of
  list, composers and forms included.
- **A locale collision:** the integration monitor's strings had replaced the work board's namespace.
- **A month-boundary e2e** that passed for half of every month.
- **A sign-out that could be undone by a late response:** an answer to a request begun while signed in
  carried the old session's cookie and overwrote the new one, signing the person back in behind the login
  screen — one run in four under load. Sign-out now withdraws everything in flight first.

### Known

- `AttributionPage`, `MediaPlannerPage` and `StoreAnalyticsPage` still cap client pickers at a hundred;
  they were under another session's uncommitted edit. Recorded in `docs/AGENCY_SCALE.md`.

## Live commissioning: the instrument, and an honest ledger of what has never been reached

The product's own work is closed. This begins the other half — making real accounts answer — and the first
true statement about it is that **no live credential exists in this installation**: every `.env` provider key
is empty, and every stored connection holds the literal `sandbox`.

### Added

- **`php artisan commission:readout {provider}`** — the instrument commissioning is done with. It asks a
  provider what it can be made to do and prints only what came back: credential state, health, discovery,
  which metrics an account actually exposes, currency, timezone, pagination, API calls spent, declared write
  capabilities, and the provider's own words when it refuses.
- **It writes nothing**, which a test pins by counting rows before and after. A readout against a client's
  live account cannot leave a figure in this product's tables for somebody to mistake later for a sync.
- **A sandbox run can never report a live verdict**, whatever it reads, because the fixtures are in this
  repository. The rule is in `CommissioningReadout::level()` rather than in an operator's judgement, and a
  test asserts the absence of `LIVE_READ_VERIFIED` from a sandbox run.
- **A provider refusal is a finding, not a crash.** "Google refused this developer token" is the most useful
  sentence a commissioning run can produce; swallowing it into a stack trace would lose it.
- **It refuses to guess which workspace's credentials to spend** when a provider is configured in more than
  one.
- **`docs/LIVE_COMMISSIONING.md`** — the ledger: one row per provider with connection, auth, discovery, read,
  metrics, currency, timezone, webhook, write, evidence level and the exact blocker. Four evidence levels,
  and a status table that distinguishes *awaiting credentials* from *awaiting external approval* from
  *failed external*.

### What the first readout already established, with no credential at all

- **TikTok reports `clicks 0/21`, `revenue 0/21`, `videoViews 0/21`** — deliberately unmapped fields, now
  visible as gaps rather than inferable from zeroes. They stay unmapped until a real response or the current
  official schema says otherwise.
- **Google Ads reports `linkClicks 0/21`**, which is correct: Google has no link-click concept. The readout
  publishes the coverage count so nobody reads the zero as "nobody clicked".
- **Meta reports no account timezone at campaign level** — recorded as *not reported* rather than filled in
  from the workspace.
- **Snapchat's spend unit remains the one unverified assumption** in the advertising layer, and the ledger
  says what changes if it proves wrong: mapper, fixtures, tests and any rows already synced.

### Found by commissioning, and fixed

- **The Integrations page could not tell a fixture-backed connection from a live one.** Both read
  *Connected*. Every figure in the product already carries `SANDBOX`, so no report was dishonest — but this
  is the page where somebody decides a provider is ready to be reported from, and it said nothing. It now
  carries *Sandbox credentials* beside the status, with `is_sandbox` published by `GET
  /api/integrations/providers`.

### Strengthened

- **The sign-out back-route test now asserts the security property, not the URL.** A signed-out person must
  be shown no client data, whatever the address bar says — and `RequireSession` renders a skeleton until the
  session read answers, so a starved harness delays the redirect without ever exposing a row. The old
  assertion could not tell those two apart; the new one fails if a single client row renders, which is the
  defect that was actually found. The redirect is still asserted, on the budget the rest of this suite uses
  for a server shared by two browser projects.
- Traced in a browser on an idle machine, the real sequence is `POST /auth/logout → 204`, then
  `GET /auth/me → 401`, then `/login`. That trace is why the remaining failures under load are read as a
  starved server rather than a live session.

### Corrected

- `docs/PRODUCTION.md` claimed an error tracker was "a key and an endpoint away". Nothing in the code or
  config reads a DSN, so the line now says what it actually takes: install the package, set the DSN, add the
  channel to `LOG_STACK`. The `stderr` channel is already defined, so a platform collecting stdout needs only
  `LOG_STACK=stderr`.

### Gates

1 785 backend tests (6 430 assertions), 581 frontend tests, 398 Playwright tests — 2 flaky on mobile Safari
under full-suite load, both retried green and neither in a file this pass touched — PHPStan level 8 clean,
Pint clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## The home screen now asks the server what needs doing

### Added

- **`AttentionPanel` on the overview**, reading `GET /api/attention`. One request, not six: the union
  happens server-side, which is what keeps the count on this panel equal to the count on the page each
  item opens, and what keeps it honest at a hundred projects.
- **The scope line is the server's, printed.** The panel does not decide which streams a reader can see
  and does not phrase a single item's sentence — both come from the modules that own them. Joining the
  stream names goes through `Intl.ListFormat`, because English wants "budgets, tracking and approvals"
  and Arabic wants its own conjunction and its own comma.
- **A failed stream is shown as a warning above the list**, so a broken request and a quiet week never
  render the same way.
- **The count is of the whole queue**, not of the six rows on screen.

### Changed

- The client-side attention vocabulary shrank to what the client actually owns — the panel's own chrome.
  Item sentences, entity kinds, stream labels and action labels are published by the server with every
  row, and a second copy in the locale files could only drift from them.

## One queue, six modules, and no seventh opinion

### Added

- **`GET /api/attention`** — everything waiting on a person, unioned from the modules that already know:
  campaign health, budget pacing, tracking issues, approval ages, promises to clients, overdue work.
- **No new table and no alert engine.** Each of those six already computes its own findings, and a seventh
  place computing them again would be free to disagree with the six screens a reader can open to check —
  and the version on the home screen is the one nobody checks. The campaign source literally calls the
  Campaign Control Center, so a campaign's verdict here and on its own row are the same verdict.
- **Permission decides membership, not presentation.** Each stream names what it takes to read it and is
  skipped entirely when the reader lacks it. A finance manager and a creative lead open the same endpoint
  and the server assembles different lists. Client requests require two permissions, not one, because the
  query behind them serves a client's own view as well.
- **The scope is published.** "Nothing needs attention" means something different to each role, and an
  empty list without the streams behind it is a reassurance the product has not earned.
- **A stream that fails is named.** Otherwise a broken query and a quiet week render identically, and the
  broken one renders as the reassuring one.
- **Worst first, then oldest**, with an undated item sorting last rather than first: unknown age is not
  old age, and the thing ignored longest is the one most likely to have been forgotten rather than
  deferred.
- **Three owner states, kept distinct on the wire.** A name; `null` for "nobody holds this", which the
  interface prints; and the key absent entirely for a kind that has no owner — a budget belongs to a
  client, not to a person, and printing *Unassigned* against one would invent a gap.

### Fixed

- **Every card on the task board came back with three nulls where names belong.** `TaskView` has carried
  `clientName`, `projectName` and `assigneeName` since the board was built and the API publishes all
  three — but the board query built on `visible()`, which returns a bare `tasks` builder with no joins.
  The detail view joined correctly, which is why opening a card showed what the card itself could not.
  Found because the attention queue asked a board card who was holding it and got nothing back. Nothing
  had caught it in the board's own tests, which asserted on titles and states and never on a name.

## A campaign can be ACTIVE on Meta and critical here

That sentence is the whole argument for the Campaign Control Center. A surface
showing only the platform's own status hides the row somebody is looking for, and a
media buyer with a hundred campaigns and twenty minutes needs the hundred narrowed
to the six.

### Added

- **`CampaignHealth`** — the product's six words, the same ones a client, a project, a budget, a tracking
  source and a creative already use. **Not** the provider's status: both are published, side by side, and
  neither replaces the other.
- **`CampaignHealthPolicy`** — the rules, as a policy object rather than as a `CASE` expression. A
  judgement belongs where it can be read, argued with and tested exhaustively, and there has to be exactly
  one copy of it. Twenty-two unit tests cover every branch.
- **Every verdict carries a sentence.** *Needs attention* and nothing else sends somebody to the platform
  to work out why, which is the trip this product exists to remove. No column names, no thresholds, no
  provider codes in it.
- **Staleness outranks performance.** A live campaign with no figures for two days is critical even when
  every number on its row looks healthy, because performance computed from figures that stopped arriving
  is a statement about the past wearing the clothes of a statement about now.
- **Objective-aware results** — `CampaignObjective::resultColumn()`. A reach campaign is read on reach, a
  traffic campaign on link clicks, a sales campaign on conversions. Zero conversions is a critical finding
  for a lead campaign and no finding at all for a reach one, which never had results to be missing.
- **`GET /api/campaigns/control`** — client, project, account, status, health, result, cost per result,
  ROAS where valid, budget, last sync; filters on all of them plus search, every one applied in SQL.

### Honesty

- **No ROAS for an objective that does not measure revenue.** Null for leads, traffic, awareness and the
  rest, whatever the revenue column happens to hold. A lead campaign carrying revenue from a mis-mapped
  conversion must not be handed a return it cannot support.
- **No cost per result where the figure would be invented.** "Cost per reach" is not a metric anybody uses.
- **No verdict on four days of data.** Seven reported days minimum, because advertising has a weekly
  rhythm and a Tuesday-to-Thursday sample against a Saturday-to-Monday one produces a change that is a
  calendar artefact. "Not enough data" is a state, never folded into healthy.
- **A null result is not a zero.** `sum()` over all-null returns null, and the policy reads that as a
  measurement gap rather than as a performance verdict — the difference between a tracking problem and an
  accusation.
- **A capped assessment says so.** Health cannot be filtered in SQL without a second copy of the rules, so
  verdicts are computed over a bounded read ordered by spend. `assessed` and `total` both ship, so an
  interface can say "the 2,000 largest of 2,140" instead of implying it looked at everything.

### Measured

211 ms and five statements over 2,000 campaigns and 180,000 daily rows, with all 2,000 assessed. The
window aggregates arrive as lateral subqueries; the scale test asserts the statement count does not move
when the agency triples.

## A hundred clients, and the first honest measurement of what that costs

### Added

- **`AgencyScaleSeeder`** — a synthetic agency at the size the constitution names: 100 clients, 230
  projects, 300 external accounts, 2,000 campaigns, 180,000 daily metric rows, built in about twenty
  seconds. Separate from the demo seeder on purpose. The demo is small, coherent and hand-checked, which
  is what makes it good for reading screens and useless as performance evidence: a dashboard that is
  instant over three clients says nothing about a dashboard over a hundred.
- **Nothing in it is realistic in content** — generated names, a seeded pseudo-random walk for spend — and
  it is flagged `is_demo` throughout, because a figure from this workspace must never be mistaken for a
  figure about a client being billed. What is realistic is the shape: a long tail of small clients beside
  a few large ones, accounts bound to several projects, and one account in twenty bound to nothing, so
  the ambiguous and unbound ownership states both have a population.
- **Deterministic**, by a seeded linear congruential walk rather than `random_int`. A fixture that changes
  between runs makes every regression arguable.
- **It rebuilds its own workspace rather than accumulating.** A fixture that is a function of how many
  times it has been run is not a fixture.
- **`tests/Feature/System/AgencyScaleTest.php`** — the acceptance test, written as an **invariance**
  assertion rather than a wall-clock one. Each agency view must issue the *same number of SQL statements*
  over a three-times-larger agency. Wall-clock budgets fail on a loaded laptop and pass on a fast one
  while the actual defect — a query inside a loop — is invisible to both.
- **Statement budgets** beside it, set at the measured cost plus two and named in the test, each asserted
  against a response that actually returned rows: a 200 over an empty result set passes any budget.
- **`config/agency_scale.php`** so a test can build a smaller but identically *shaped* agency, and build
  two sizes in one process — which is what the invariance assertions need.

### Measured

At full scale: the campaign ownership queue 186 ms in 5 statements, an analytics breakdown by provider
377 ms in 6, by campaign 454 ms in 7, clients and projects under 10 ms in 5. Recorded in
`docs/AGENCY_SCALE.md` with what they do and do not say. The honest reading: statement counts are flat and
analytics is slow in *six* statements rather than six hundred — an aggregate problem, not an N+1 one, and
the first thing that will need a summary table.

## Whose credentials, and whose campaign

Two findings from the constitution audit, both in the tenancy model, both small in code and large in what
they allow.

### Added

- **`ConnectionOwner`** — a connection says whether it carries the agency's own credentials or ones a
  client granted directly. The old table was `unique(workspace_id, provider)` and its comment argued that
  two live connections to one platform would make "which credentials synced this?" unanswerable. The
  argument was right; the conclusion was too strong. The agency runs its own Business Manager *and* holds
  credentials from clients who will not move their ad account under it. Provenance never came from there
  being one candidate — every account, store and measurement source already carries
  `provider_connection_id`.
- **Two partial unique indexes** replace the one total index: at most one agency connection per platform,
  at most one per client per platform. A plain composite unique would not do it — Postgres treats NULLs as
  distinct, so every agency-owned row would pass.
- **`campaigns.project_id`** and **`CampaignOwnership`** — assigned, implied, ambiguous, unbound. Bindings
  are unique on `(project_id, external_account_id)` precisely so one ad account can serve several projects,
  which is the normal agency shape; every campaign on such an account previously had an owner nothing could
  name. `ambiguous` and `unbound` both mean "no project" and stay distinct, because one is fixed by
  assigning the campaign and the other by binding the account.
- **`GET /api/campaigns/ownership`** — the review queue. Counts are computed over the whole population
  rather than the page, because "eleven need assigning" is a figure somebody acts on and "eleven on this
  page" is one that changes when they scroll.
- **`PUT /api/campaigns/{campaign}/project`** — assign or clear. A security-class audit entry, because it
  decides whose figures a campaign's spend joins and who can see it.

### Changed

- **`findForProvider` is gone**, replaced by `agencyConnection` plus `forClient` and `listForProvider`.
  The rename is the point: with both kinds of connection present, a lookup by provider alone can read one
  client's data on the agency's token. Seventeen call sites now say which they mean.
- Nothing sets a campaign's project during sync. Defaulting it would be the silent binding the constitution
  forbids, and the only other available signal — the campaign's name — is the one inference it rules out.

### Fixed

- **A campaign cannot be assigned to a project its account is not bound to.** Without that rule,
  assignment would have been a second and quieter way to grant a project sight of an ad account, in an
  installation that runs competing clients side by side. The legal set is published as `candidate_projects`
  and re-checked server-side.

## One word for "is this all right?", and four primitives the product kept rebuilding

Before this, a reader moving from Tracking to Budgets to Integrations met three different vocabularies for
the same question. Tracking said `stale`; budgets said *at risk*; a connection was *degraded*. Each was
reasonable on its own page and none of them helped somebody holding all three.

### Added

- **One health vocabulary** — Healthy / Watch / Needs attention / Critical / Inactive / **Not enough data** —
  in `shared/ui/health.model.ts`, with `HealthPill` and `HealthDot` to render it. It is business-facing by
  design: the technical state underneath does not go away, it moves to the detail view where somebody is
  actually diagnosing.
- **"Not enough data" is a state, not a gap.** A campaign with four days of history has no trend, and
  collapsing that into *healthy* is how a product comes to look confident about something it never measured.
  A test pins the two apart.
- **Nothing is carried by colour alone.** Every state ships its own glyph and its own word; the dot form
  carries an accessible name. A reader who cannot separate amber from red, or who is reading a printed
  report, loses nothing.
- **`AttentionList`** — the one shape for "waiting on a person". Worst first, then oldest, because the thing
  ignored longest is the thing most likely forgotten rather than deferred. One action per item; no
  diagnostics in the card. An unowned item prints *Unassigned* rather than a blank — it is the item most
  likely to rot.
- **`ActivityFeed`** — who did what, when. `null` actor renders as *System*: a scheduled sync is not
  something a person did, and attributing it to the last human who touched the record is a quiet lie about
  who did what.
- **`ContextDrawer`** — detail without losing the list behind it. Opens from the inline *end*, so in Arabic
  it lands opposite the list rather than on top of it, and becomes a full sheet below `sm`.
- **`InsightCard`** — one or two sentences, an observation and never a recommendation. "Google Ads is 62% of
  spend and 41% of conversions" is a reading of what is on screen; "move budget to Meta" is advice this
  product is not positioned to give to a client being billed on these numbers.
- **`formatRelativeTime` / `ageInDays`** — via `Intl.RelativeTimeFormat`, because Arabic inflects the noun by
  count and three days is not `3 يوم`. A hand-rolled pluraliser gets this wrong silently, and a test asserts
  the Arabic form.

### Fixed

- **A drawer opened without a Radix `Trigger` dropped focus onto `<body>` when it closed.** Radix restores
  focus to its own trigger, and this drawer deliberately has none — it opens from a row, a card, a menu item.
  A keyboard user who opened the fourth row had to tab from the top of the page to reach the fifth.
  `ContextDrawer` now records where focus was and returns it, with a test.
- **An absent drawer description no longer claims one.** The usual workaround — an empty
  `Dialog.Description` — satisfies the warning by pointing `aria-describedby` at a blank element, which
  promises a screen-reader user a sentence and then reads them nothing.

## Clients as cards, and what a card refuses to show

The clients page was a five-column table, which reads like an inventory of a thing that is actually a list of
relationships. It opens on cards now, with the table one click away and carrying every fact it carried before.

**What the card does not show is the deliberate part.** The obvious content for a card this size is spend,
revenue and ROAS — and the clients endpoint returns none of them, because those figures are scoped to a
period, a platform and a revenue authority this screen has not chosen. Showing them would mean inventing a
number or quietly picking a window on the reader's behalf. The card carries the name, the industry, the
status, the number of projects and the contact, which is what the list actually knows.

The avatar is initials rather than a generated mark: nothing in this product stores a client's logo, and
something that looked like a brand asset would be a claim about a file nobody uploaded.

### Found by the gates

- **Three end-to-end specs addressed a layout rather than a fact** — the clients list, the Arabic digits check
  and the tracking commissioning journey all reached for `getByRole('row')`. Each now addresses what it is
  actually asserting: the clients spec checks both views carry the same facts, the digits check switches to
  the table because the digits are its subject, and the tracking journey names the client's card.
- Each client card is a named `article`, which is both the accessible shape for a self-contained record and
  the handle those tests needed.

### Gates

1 785 backend tests, 597 frontend tests, **402 Playwright tests with no failures and no flakes**, PHPStan
level 8 clean, Pint clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## A design system, and the shell rebuilt on it

The product worked and did not look like it. This is the first slice of a system-wide redesign: the tokens,
the shell, the primitives every page shares, and one page migrated end to end to prove the system holds.

### The foundation

- **The canvas is a measurable step darker than a card.** That one change is what lets borders drop to a
  hairline and shadows to almost nothing: a card now separates by its own surface rather than by being drawn
  around. Every later decision — soft borders, faint shadows, large radii — depends on it.
- **Dark mode is designed, not inverted.** The canvas is the darkest layer and each card sits a measured step
  above it, because in dark mode a shadow is a grey smear and the *surface* step is what carries elevation.
  Lifting the raised surface put tertiary ink at 4.45:1 against it, which the contrast test caught; the ink
  moved rather than the surface, because tertiary carries table headers and source labels.
- **One page rhythm**: `--ds-page-gutter` and `--ds-section-gap`, so the whole application breathes at one
  cadence instead of each page choosing its own padding.
- Shadows rebuilt as wide and faint with a one-pixel contact edge — a card resting a millimetre off the
  canvas rather than cut out and dropped on it.

### The shell

- **The rail is a panel on the canvas**, inset with its own surface, radius and hairline, so chrome and
  content read as one system rather than a strip glued to the window edge.
- **The active item is a filled pill.** A reader should find where they are without reading a label, and a
  tint across a 17rem row is too diffuse to do that. Collapsed is a real mode: labels go, the pill becomes
  square, and section headings become a rule rather than three truncated letters.
- **The topbar is chrome, not a bar**: translucent material, no border, the same gutter as the page beneath it
  so the search field lines up with the first card. Its controls sit on one surface as a single cluster.

### The primitives

- **`PageHeader` and `PageSection`.** Every page wrote its own header; the gap between title and content
  ranged from 12 to 32 pixels and filters landed wherever there was room. One `h1`, actions that wrap rather
  than squeeze, filters on their own row.
- **`DataTable`.** Thirteen tables each styled their own header row — three fills, two divider weights, four
  padding scales. The surface is now one decision: no header fill, no vertical rules, small-caps tertiary
  headings, hairline row dividers, and the keyboard-reachable scroll region built in.
- **`TrendBadge`**, and a `MetricCard` rebuilt around it: the figure dominates, the label is an overline, the
  change is a pill coloured by whether it is *good* rather than whether it is up.
- Buttons became pills, a step taller; cards took the larger radius and roomier padding.

### Charts

- Tooltips are cards — radius, shadow, padding — because the tooltip is the one chart surface a reader looks
  *at* rather than through. The category baseline rule is gone, the grid is lighter, and the chart chrome
  tokens are authored in the token file rather than left to the chart module's fallbacks.
- **Two changes were reverted by tests that were right.** Dashing the gridlines: a dashed rule reads as a
  projection, and these charts carry real threshold lines — a dashed target against a dashed grid is
  invisible. Widening bars past 24px: the leftover space in a band is what makes a row of bars readable.

### Migrated

Fourteen surfaces now share the system rather than each inventing it: **Overview, Clients, Projects,
Campaigns, Analytics, Operations, Approvals, Requests, Creative Hub, Tracking, Reports, Dashboards,
Presentations, Notifications, Integrations, Administration and the AI assistant.** Each one's bespoke
`<header>` became `PageHeader` — one `h1`, actions that wrap rather than squeeze, filters on their own row —
and the Clients, Projects and Administration tables moved onto `DataTable`. The analytics breakdown keeps its
own markup (its colspan logic is doing real work) and wears the same surface.

Four tables are deliberately untouched: they belong to another session's in-flight money-formatting work, and
taking them would have meant resolving someone else's change as part of a redesign.

### On a phone, a row is a card

A desktop table squeezed onto 375 pixels is a horizontal scroll that hides most of its columns behind a
gesture — which is what the clients table did, and what the brief for this redesign names explicitly. Below
`sm` the header row is hidden, each row becomes a card, and every cell carries its own column label.

It is still a real `<table>`: the row and column relationships a screen reader depends on are unchanged, and
the minimum width that forces the desktop layout now applies from `sm` up only, because on a phone it would
reintroduce exactly the scroll the card layout removes.

### The consistency sweep

- **Nineteen files still drew cards at the old radius.** A panel carrying a hairline and `rounded-xl` is a
  card; they are all `rounded-2xl` now, so no surface in the product is a different shape from its neighbour.
- The one remaining hand-written table head — the overview's health panel — wears `ds-overline` like every
  other.
- Status pills took their final geometry: soft tint, no saturated fill, one line height. A badge is a label,
  and a row of loud ones turns a table into a traffic light.
- The sweep found **no hard-coded colours** anywhere in the feature code. Every surface is already a token.

### Gates

596 frontend tests, **400 Playwright tests with no failures and no flakes** — the whole suite against the
redesigned surfaces, axe WCAG 2.2 AA among them, in both browsers and in Arabic — TypeScript strict clean,
ESLint and Prettier clean, production build. The token contrast suite (30 pairs, both themes) is green, which
is what caught the one real regression above.

## A sign-out that failed looked exactly like one that worked

The end-to-end suite caught this under load, and it is the most serious thing found in this pass: after
clicking *Sign out*, the back route to the client list rendered **three clients, with names and industries**.

The mechanism, in order:

1. The sign-out `POST` failed with **no response** — the suite's own configuration documents this happening on
   a loaded machine, and the application reports it correctly.
2. The mutation navigated to the sign-in screen from `onSettled`, which fires for a failure as readily as for a
   success. The URL said `/login`, so the first assertion passed.
3. The server had never been told, so the session was still live. The back route was served, and
   `RequireSession` was right to render it: the session read answered `200`.
4. The cache had already been cleared on `onSettled`, so the screen emptied — which is what makes the failure
   *look* like a sign-out instead of an error.

### Fixed

- **The request is retried on a dropped connection.** Signing out twice signs you out once, which is exactly
  the test the client's retry rule applies — the same reasoning the sign-in mutation documents, and the reason
  a password change deliberately does not.
- **The navigation and the cache clear moved to `onSuccess`.** A sign-out that did not reach the server leaves
  the person where they were, with their session and their screen intact.
- **The failure is shown in the menu it was clicked in**, because the only useful response is to try again on
  a connection that works.
- **A backend test now proves the next read is refused**, driven through a real sign-in rather than `actingAs`:
  the thing under test is the session, and a test that acts as a user and then logs out is testing a guard. It
  calls `Auth::forgetGuards()` between the two, and says why — `RequestGuard` caches the user it resolved, so
  without it the assertion passes against a guard's recollection of somebody who has gone.

Nothing was weakened to make the suite green: the end-to-end test asserts exactly what it always did, and the
product now behaves the way it claimed to.

### Gates

1 776 backend tests (6 402 assertions), 579 frontend tests, 398 Playwright tests — no failures and no flakes
— PHPStan level 8 clean, Pint clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## A const kept only to make a type, and a gate claim that was wrong

`MEMBERSHIP_STATUSES` lost its `export` in the sweep above, and that turned it into a value nothing reads:
ESLint's `no-unused-vars` says so precisely — *assigned a value but only used as a type*. It is now a union,
and nothing ships to a browser to produce one.

**The honest part of this entry**: the commit before it claimed ESLint clean, and it was not. The run that
would have caught it was launched and its output was not read before the commit. The lesson is the same one
the `vi.mock` duplicate-key episode taught — a gate is only a gate if its result is read — and the figure in a
commit message is a measurement, not a hope.

### Gates

1 775 backend tests (6 396 assertions), 576 frontend tests, 397 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build. Each one read this time.

## A closure ledger, and two absences recorded as decisions

### Added

- **`docs/CLOSURE.md`** — one row per product area: what is implemented, the tests that fail if it stops
  being true, the production evidence, the exact external thing it waits for, and a status. Three blocker
  codes and nothing else allowed to stand in for them, because "integration pending" is not a status anybody
  can act on. Linked from the README's status line, which pointed only at the per-phase roadmap.
- **`docs/SECURITY.md`: there is no teams entity**, and that is a decision. People are workspace members with
  a role, and the grouping that decides access is project membership — the thing that actually governs who can
  see a client's data. A teams layer would be a second grouping with no permission of its own, and the first
  question asked of it would be which of the two wins when they disagree.

### Verified for the ledger

- The CRM ownership rule, end to end: a lead whose stage a CRM owns cannot be moved here, a stage merely
  *called* "Won" is not a win, and an open deal's quoted amount is never recorded as revenue.
- The client portal's resources name no owner, assignee, actor or internal field at all — the disclosure rule
  is enforced by what the queries select, not by what a template happens to render.
- All thirteen product areas carry both backend feature tests and end-to-end coverage.

### Gates

1 775 backend tests (6 396 assertions), 576 frontend tests, 397 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## Three dead port methods, and a document that described an unbuilt flow

### Removed

- **`ExternalAccountRepository::forConnection`** and **`IntegrationBindingRepository::countForAccount`**, both
  implemented and called by nothing — and both second answers to questions the integration read model already
  answers, `accountsForWorkspace` and `ExternalAccountView::bindingCount`. Two implementations of one figure is
  one that can disagree with the other in the place nobody has looked.

### Changed

- **`docs/INTEGRATIONS.md` described a guided access flow that does not exist.** It promised "OAuth where
  possible" and a per-provider wizard; what the product has is an operator pasting credentials, which is enough
  for every read in it. The section now says that, and names `ProviderAdapter::authorisationUrl()` for what it
  is: implemented prep with no caller, because a URL is half a handshake — the other half needs a redirect URI
  allow-listed in somebody's developer console, a callback route, a signed `state` and a token exchange against
  a live endpoint. `BLOCKED_EXTERNAL_CREDENTIALS`, named rather than left looking like a loose end.
- **Runbook 8: the nightly passes have not run.** Stale figures everywhere at once, a store never read, and a
  workspace refused a feature its role allows are the same fault with three faces, and the first check is
  whether anything runs `schedule:run`. It also separates a stalled `withoutOverlapping` lock from a stopped
  queue, which look identical from the figures.

### Verified, not changed

- **Every column that carries an assignment**: `tasks.assignee_id`, `leads.owner_id`,
  `client_requests.owner_id`, `creative_requests.owner_id` — a migration-wide sweep for every
  user-referencing column returns exactly these four, all behind `OwnerDirectory` for assignment and
  `AssignedWorkRepository` for release.
- **Every navigation item reaches a real page.** The placeholder route remains as the guard its own test
  describes, and nothing uses it.
- **No exported frontend symbol is unreferenced**, and no port method outside those two was uncalled.

### Gates

1 775 backend tests (6 396 assertions), 576 frontend tests, 397 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## Two lists kept by hand, and four exports nothing imported

A sweep for exported symbols nothing calls, closing what it found rather than listing it.

### Added

- **The notification centre reads what can arrive from the server.** It had a hand-written sentence naming
  *four* things that put something in the feed; the server announces **ten**, and one of those —
  `lead_assigned` — had been missing from this feature's own type union for two phases. A hand-kept list of
  what a product can tell you is exactly where that drift hides, because it reads as prose rather than as data
  nobody checked. The panel is open by default when the feed is empty, which is when a reader needs it.
- **The rule, not the slugs.** The vocabulary carries the permission each kind requires, and the page does not
  print it: a reader needs to know that a kind reaches only people who can already see what it is about, not
  that the gate is spelled `leads.view`. A client viewer reaches this page.
- **The member directory filters by status and by role.** `ListMembers` has taken both since the module was
  built and nothing sent either, so the directory beside a suspend control could not answer "who is
  suspended". `invited` is deliberately not offered: this installation never writes one, and an always-empty
  filter reads as a broken filter. An empty result says the filters did it rather than leaving a blank table.

### Removed

- The `export` from `SUPPORTED_LOCALES`, `LOCALE_DIRECTION`, `summaryMetric`, `oklabFromLinear` and
  `MEMBERSHIP_STATUSES` — each used only inside its own module. A smaller public surface is a smaller set of
  things that can drift; the sweep now returns nothing.

### Found by the gates

- **Two label-wrapping faults, one of them a real accessibility defect.** A `<label>` that wraps its own
  `<select>` puts the control inside the label's text, so the accessible name of the new status filter would
  have been "Status Any status" — wrong to hear, and untestable by name. Both filters use the `Field`
  primitive, which labels by `htmlFor`.
- A premise that counted every `combobox` on the page to mean "no role control". The two filters are not
  privileged — narrowing a directory you may read is reading — so the test now asks for the per-person control
  by name.
- One flake under full-suite load, in a test this slice does not touch: the sign-out back-route check on
  Chromium. It passes in isolation with retries off, and the configured retry absorbed it. Recorded rather
  than papered over.

### Gates

1 775 backend tests (6 396 assertions), 576 frontend tests, 397 Playwright tests (1 flaky, retried green),
PHPStan level 8 clean, Pint clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## Nothing ran the three commands that keep the product current

`advertising:sync`, `commerce:sync` and `access:sync` were each written, tested and registered — and no
schedule existed. The kind of gap no test can see from inside a command: each command's own tests pass while
the product quietly ages.

### Added

- **A schedule**, with a test that pins it. `advertising:sync` hourly, because a dashboard is otherwise as old
  as the last person who pressed a button. `commerce:sync` at 03:30, catching what webhooks dropped — nightly
  rather than hourly because each adapter's restatement window is measured in days, so an hourly pass would
  spend a platform's rate limit re-reading the same window to find the same nothing. `access:sync` at 03:00, so
  a deploy that adds a permission reaches workspaces that existed before it rather than only new ones.
- **`withoutOverlapping` on all three.** Each queues work rather than doing it, so a run starting while the
  previous one is still dispatching would double every job in flight — and the per-account and per-store jobs
  are unique, so the duplicates would be dropped silently and nobody would learn the schedule was too tight.
- `docs/PRODUCTION.md` states the one line a host owes this: `schedule:run` every minute, and
  `php artisan schedule:list` to check it is doing what the table says.

### Gates

1 775 backend tests (6 396 assertions), 570 frontend tests, 396 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## The demo was intermittently self-contradictory

Found by a full backend run: `DemoBoardTest` failed with the product's own error — *that person cannot open
this, so putting it in their name would leave them owning work they never see*. The rule was right; the demo
was wrong, about one run in ten.

Every "the first one" in the demo seeder read `orderBy('created_at')->first()`, and the timestamp columns are
`timestamp(0)`. The three clients and four projects are written inside one second, so between ties Postgres
returns whichever row it likes — and two sections could disagree: the creative put on the *Seasonal campaign*
project while their task was opened on *Always-on performance*. The board then refused the assignment,
correctly, and the seeder died.

### Fixed

- **A deterministic tiebreaker at all seventeen sites**: `orderBy('created_at')->orderBy('id')`. The ids are
  UUIDv7, so ordering by id is both stable and creation-ordered — no second source of truth about which record
  came first.
- **A test that pins the demo's coherence rather than its contents**: every assigned task's owner is checked
  against `OwnerDirectory` — the same directory the assignment itself consults — so the test cannot drift from
  the rule it is protecting. Five consecutive runs, green.

### Gates

1 775 backend tests (6 396 assertions), 570 frontend tests, 396 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## A store could be connected to the agency and never to a client

The largest hole the sweep has found since project membership. `SyncStore` was written, tested and reachable
from **nothing** — no route, no job, no command — and `CommerceSyncAdapter::discoverStores` was implemented by
both Salla and Zid and called by nobody. Underneath both: a `stores` row could only be written by a seeder.

So a real installation could connect Salla on the integrations page, have its webhook deliveries
cryptographically verified, and then reject every one of them as `unknown_store` — because nothing in the
product could say *which client that merchant belongs to*. The store revenue page would show an empty total
with no explanation available anywhere on it.

### Added

- **`ConnectStore`** and `POST /api/commerce/stores`: say that this merchant's orders are this client's
  revenue. Takes only the provider, the merchant id and the client — the name, currency, domain and timezone
  are read back from the platform by merchant id, because a typed currency is the most expensive field in this
  product to get wrong and nothing downstream could detect it.
- **`GET /api/commerce/stores/connectable`**: every merchant the workspace's commerce connections can read,
  with the ones already registered **shown** rather than filtered out, naming the client that has them. A
  merchant missing from a list reads as "the connection cannot see it", and somebody then goes looking at the
  platform for a problem that is not there.
- **`GET /api/commerce/stores`**: which stores this workspace reads, with **both** `last_synced_at` and
  `orders_synced_through`. They are different facts — when somebody last asked, and how far through the orders
  the answer got — and the second moves only when orders landed, so a failing store has the first and not the
  second. That is the state a single "last updated" column hides.
- **`POST /api/commerce/stores/{store}/read`** and **`commerce:sync`**: the reconciliation read, by hand and on
  a schedule, queued per store through `ReadStoreJob` (unique per store, because both platforms page
  sequentially and a second walk interleaved with the first reads from a cache it poisoned). Webhooks are
  faster and not sufficient: one dropped delivery, one deploy during a busy hour, one platform incident, and a
  client's revenue is quietly short for that window with nothing on any screen to indicate it.
- **The outcome is reported, not reduced to a tick.** Succeeded, partial, failed and skipped are four facts: a
  partial read wrote real orders and keeps them, and a skipped one means the platform has no adapter to read
  with. Sandbox reads say sandbox.
- **`AuditAction::StoreConnected`** — a security-relevant action, beside the integration bindings: from then on
  every order that merchant reports counts towards that client's revenue, and the people on that client's
  projects can read its customers. **`StoreRead`** records each read with its honesty label; a scheduled read
  is recorded with **no actor**, which the trail shows as the system rather than attributing a nightly job to
  whoever last signed in.
- **One store belongs to one client**, and the refusal names the client that has it. Two registrations would
  count every order twice; a unique index sits behind the check for the race.
- **A client the caller cannot see is a `404`.** Deciding whose revenue a merchant's orders become is not a
  decision to make about a client the decider cannot otherwise reach.

### Changed

- **The demo seeder connects its store through `ConnectStore`** instead of writing the row, so the demo
  demonstrates a state a real owner can reach — and the store now carries the platform's own name.
- **The store revenue page says once that a role cannot read revenue**, rather than reporting a `403` as a load
  failure a retry might fix. The stores panel stays silent on the same `403`: a reader refused access was
  being told twice that something had gone wrong.

### Found by the gates

- **The architecture test caught the console command reaching into Infrastructure** to enumerate workspaces.
  The question moved onto the port as `StoreDirectory::readTargets()`, which returns each target with its
  workspace attached — the same shape the advertising scheduler's targets have, and for the same reason.
- A test premise named "not found" where the product correctly answers `403`: the name was wrong, and a real
  cross-tenant `404` case was added beside it.

### Gates

1 775 backend tests (6 396 assertions), 570 frontend tests, 396 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## The Tracking Center could not register a container or remove a source

Two dead ends, found by sweeping the frontend for exported hooks nothing calls.

**A tag container could not be registered.** The readiness panel is the only screen in this product that can
tell a change *saved* in Tag Manager from a change actually *published* — the single most common reason a tag
that looks correct is not firing. It reads containers registered as sources for the client, and nothing could
register one: `GET /api/measurement/containers` had a route, a use case, tests and `useContainersQuery`, with no
caller. The panel's empty state told an agency to connect a container and offered no way to do it.

**A source could never be removed.** `DELETE /api/measurement/sources/{source}` had the same shape of hole. A
mistyped property, a container belonging to a client the agency no longer works with, or a GA4 roll-up
registered beside the properties it aggregates — each was permanent, and the last of those doubles every figure
it touches.

### Added

- **Discovery registers either kind.** The registration panel asks what is being connected — an analytics
  property or a tag container — and keeps the two apart, as the rest of the product does: a property reports
  events, a container delivers the tags that send them. The copy says a container buys the readiness check
  rather than a source of figures, because calling it "what is measuring" would invite somebody to expect
  events from it.
- **The `GTM-` id beside every container name**, because an agency's account holds four containers called
  "Website" and the public id is the only thing that tells them apart. A container that cannot deliver web tags
  is labelled as such rather than silently offered.
- **Registered by `path`, not by the `GTM-` id.** It is what Tag Manager's own API addresses a container by, and
  the readiness check matches on either — so the stable one costs nothing and survives a rename.
- **The container list is fetched only when that tab is open.** It spends the workspace's credentials on
  Google's API, which is not something to do on the chance somebody wants it.
- **Removing a source names what it takes with it**, in a dialog rather than a row click: the day-by-day counts
  read through it are deleted, conversion definitions survive but lose the mapping that named it, and leads it
  reported keep their record and stop naming it. It sits beside "Read now" in the same row and it is the
  irreversible one.
- Removal is `tracking.manage`, like registration. A reader who can only look at the tracking picture is
  offered neither control rather than a disabled one that would always be refused.

### Removed

- `useMeasurementSourcesQuery` and `useConversionsQuery`, both unreachable. The health read already returns the
  sources and the definitions, judged against each other; a second copy fetched beside it is a second answer to
  the same question that can disagree with the first while one page shows both. Their `api.ts` functions and
  query keys went with them.

### Found by the gates

- The registration control is disabled until a client is chosen, which is correct — a source is bound to one
  client — and is what the first version of the container test tripped over.
- Three selector faults in the new end-to-end journey, each a real statement about the product: a client name
  carrying `[chromium]` is not a regular expression; the scope picker's `<label>` wraps its `<select>`, so the
  label's text carries every option and an exact match finds nothing; and "Nothing is measuring this work yet"
  appears **twice** on a client nothing measures — once as the panel's empty state and once as the issue list's
  critical finding, which is the page working as designed.
- One pre-existing flake observed under full-suite load: the conversion test's saved name not appearing within
  twenty seconds on mobile Safari. It passes in isolation on both browsers, with retries off, and the
  configured retry absorbed it. Recorded rather than papered over.

### Gates

1 752 backend tests (6 328 assertions), 560 frontend tests, 389 Playwright tests (1 flaky, retried green),
PHPStan level 8 clean, Pint clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## Removing somebody left their work in their name

The last of the four assignment columns, approached from the other side. `DELETE /api/members/{user}` removed a
membership and nothing else, so a departed colleague's name stayed on every task, lead, client request and
creative brief they had been holding — and the boards went on showing work owned by somebody who could not open
it, the workload chart went on counting a person who had gone, and a client request went on waiting for a reply
from nobody. The interface made it worse: one unconfirmed click, on a row, for an irreversible operation.

### Added

- **Removal releases their open work and reports what it released.** `DELETE /api/members/{user}` now answers
  `200` with `{"data":{"released":{"tasks":…,"leads":…,"client_requests":…,"creative_requests":…,"total":…}}}`.
  The items come back as **unassigned**, which is both the fact and the thing somebody has to act on.
- **Released, not reassigned.** Handing eleven items to one colleague from a dialog nobody can see the work
  from would be a worse outcome than publishing the truth. Every board filters for unassigned already.
- **Finished work keeps their name.** Open is each module's own predicate — `TaskState::isOpen`,
  `ClientRequestState::isOpen`, `CreativeState::isOpen`, a lead neither closed nor merged away — so a task
  somebody completed stays the record of who completed it. Rewriting that to say nobody did would be falsifying
  history to tidy a column.
- **`AuditAction::WorkspaceMemberRemoved`**, with the per-kind counts in `changes.released`. Removal had been
  recorded as a *suspension*: one is somebody expected back whose work waits for them, the other is somebody
  gone whose work was handed back, and a trail that calls the second the first answers "what happened to Noura's
  twelve tasks" wrongly.
- **Suspension still releases nothing**, deliberately, and still answers `204`. A suspended member is expected
  back; the directory shows the status beside their name.
- **`GET /api/members/{user}/held-work`** — the same four counts for somebody still here, so the consequence is
  shown **before** the decision. "Remove" over a name holding eleven open items and "remove" over a name holding
  none are different decisions, and a single row-level button rendered them identically. `404` for somebody who
  is not a member of this workspace, rather than a confident zero about a stranger.
- **A confirmation dialog that says what it is about to do, then what it did.** Same four counts either way, so
  the figure read before deciding is the figure checkable afterwards; kinds holding nothing are left out rather
  than listed as zeroes. If the count cannot be read the dialog says so and still offers the removal — refusing
  it because a figure is unavailable would be the wrong trade.
- `AssignedWorkRepository`, `HeldWork` and `EloquentAssignedWorkRepository`: one place that knows which four
  columns carry an assignment, with each module's own open-state predicate rather than a second list of states
  for this repository to keep in step.

### Found by the gates

- **The release has to happen after the membership row goes**, so a concurrent assignment cannot land between
  the two and leave one item behind.
- Two stale premises corrected rather than worked around: `assertNoContent()` where removal now reports a body,
  and `assertOk()` where suspension correctly still has nothing to report.
- ESLint's `no-unnecessary-type-assertion` on a `vi.hoisted` mock state object — typed with an explicit alias
  instead of an inline assertion.

### Gates

1 752 backend tests (6 328 assertions), 555 frontend tests, 388 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## Nobody could be given access to a client's work

The single largest hole found by the closure sweep, and it had been there since Phase 1.

**Project membership is the only thing in this product that grants sight of a client's data** — a lead, a
store's revenue, a campaign's spend, a client's own portal. `ActorVisibilityResolver` says so in four lines and
every read narrows by it. And there was **no way to grant it**: `GET`, `POST` and `DELETE
/api/projects/{project}/members` existed, were tested, and nothing in the application reached them. The projects
page showed a member count and no control.

So the onboarding flow built an hour earlier ended with somebody who could sign in, choose their password, and
then see an empty product — and the only way to fix that was a database write.

### Added

- **`GET /api/projects/{project}/assignable`** — active workspace members who are **not already on the
  project**, with ids, names and their workspace role. No email addresses. Both filters matter: a suspended
  member would be a choice that grants nothing, and somebody already assigned would be a silent no-op.
- **The member count is now the control.** It was the whole of that column and was read-only, so the figure that
  decides who can see a client was a number you could look at.
- **They join in the role they hold in the agency.** People are almost always on a project in their own role,
  and the member directory is where a role changes; asking twice would be asking a question nobody has a
  different answer to.
- **An empty project says what that means** — nobody can reach the client's work through it — rather than
  leaving a blank list to be interpreted. **Removing somebody states the consequence**: they lose sight of that
  client's work, including anything already in their name.
- **A reader without `projects.manage_members` sees the list and no controls.** Who can see a client is worth
  knowing for anybody who can see the project; changing it is a separate permission.
- The candidate list is the project module's own query rather than `OwnerDirectory`'s. That one answers "who can
  already see this thing", for deciding who may own work; this is almost its inverse — the people worth offering
  are the ones who cannot see the project yet.

### Found by the gates

- **A strict-mode violation that was a real ambiguity.** `getByText('Demo Creative')` matched two elements: the
  person on the project and the picker option offering them. The list of people is now named, so a screen reader
  — and a test — can tell the two apart.
- **A `beforeEach` scoped to the wrong block.** The new tests inherited state from the previous describe, which
  is how the empty-project test first read a populated dialog.

### Gates

1 744 backend tests (6 308 assertions), 552 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 386 Playwright tests.

## The creative hub could not move work or hand it over

A schema sweep for every ownership column — `assignee_id`, `owner_id`, `responsible_user_id`, `reviewer_id`,
`approver_id` — returned four assignable entities. Three had just been wired. The fourth,
`creative_requests.owner_id`, could be written **once** by the composer and never again: the board showed an
owner and nothing could change it. And `MoveCreativeRequest` had an endpoint, a contract, tests and
`useMoveRequestMutation` — with no caller anywhere, so the board could not be moved from the interface at all.

### Added

- **`POST /api/creative/{creative}/owner` and `GET /api/creative/{creative}/owners`**, through the same
  `OwnerDirectory` as the other three boards. A brief carries a client's campaign and often their unreleased
  plans, so an owner whose projects do not reach that client would be a name on work they can never see.
  `OpenCreativeRequest` applies the same check, because briefing work already in somebody's name has the same
  consequence as handing it to them later.
- **Move controls from the server's own `allowed_next`**, so a target the board would refuse is never on screen.
  Cancelling asks for its reason *before* sending anything: the server requires one, and that reason is the only
  record of why the work stopped.
- Both hidden without `creative.manage` rather than disabled. The server refuses them either way, and a control
  that never works is worse than no control.
- `owner_id` on the creative resource — the name alone made "who is making this" readable and "give it to
  somebody else" unbuildable.

### Found by the gates

- **A duplicate key in a test mock. Again.** `moveRequest` was already stubbed later in the same object
  literal, so the recorder added at the top was silently overridden and the mutation "never ran" — the same trap
  as the client-request mock an hour earlier. **The typechecker catches it** (`TS1117`), and it had caught both:
  running the test before `npm run typecheck` is what turned a one-line error into two debugging detours.
- **A fixture naming two of six states.** The move buttons take their labels from the vocabulary the server
  publishes, so a partial fixture left them reading `briefed` and `cancelled` in raw snake case. The fixture was
  wrong rather than the fallback.

### Gates

1 742 backend tests (6 299 assertions), 548 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 382 Playwright tests.

## Three boards could not give work to anybody

The task board showed an assignee, the intake queue showed an owner, the lead pipeline showed both — and none of
the three had a control for it. `useAssignOwnerMutation` and `useAssignTaskMutation` existed with no caller;
`POST /api/tasks/{task}/assignee` and `POST /api/client-requests/{id}/owner` had endpoints nothing reached. Every
record belonged to whoever created it, and the workload chart beside the board described a team that was not
working that way.

### One directory, not three

`OwnerDirectory` replaces the lead-specific one written a slice earlier and takes the permission as a parameter.
Three copies of a visibility rule is a rule that disagrees with itself in the one place nobody has looked.

The three conditions are `PermissionAwareRecipientResolver`'s: active membership, the permission to read that
kind of thing, and visibility of the client. The third is the one worth stating plainly — a lead carries a
prospect's phone number and a client request carries a client's own words, so assigning one to somebody whose
projects do not reach that client would put their name in the owner column of a record they can never open, **and
the notification telling them would be suppressed by the same rule, so nobody would ever find out.**

### Added

- **`GET /api/tasks/{task}/assignees` and `GET /api/client-requests/{id}/owners`**, served rather than assembled
  from the member list — which needs `users.view`, a permission somebody working a board has no reason to hold,
  and would offer colleagues the server then refuses.
- **The server refuses an ineligible assignee**, with a message that names the fix rather than lecturing about
  permissions: whoever is assigning cannot grant access themselves. `AssignTask`, `AssignRequestOwner` and
  **`OpenTask`** all check, because opening work already in somebody's name has the same consequence as handing
  it over afterwards.
- **Controls on both panels**, with the hint wired as a *description* rather than folded into the control's
  accessible name — the defect this product fixed once already on the lead owner picker.
- **A client sees no control at all**, not a disabled one, and the query that feeds it is never made: who inside
  the agency is handling a question is internal.
- **`owner_id` on the client-request resource.** The name alone made "who has this" readable and "give it to
  somebody else" unbuildable, because a picker has to know which option is selected.

### Found by the gates

- **The demo seeder was assigning work to somebody who could not see it.** Three tasks went to the media buyer,
  who is on no project — so the demo described work owned by a person who could not open it.
- **And the first fix was the wrong half.** Putting the buyer on a project made the assignments legal and broke
  five end-to-end tests at once: the demo leaves that account assigned to nothing *deliberately*, to
  demonstrate that visibility comes from assignment — an empty campaign list with its reason, a portal narrowed
  to no clients, a notification feed with nothing in it. The demonstration is worth more than the convenience,
  so the **tasks** moved instead, to the manager, who reaches every client by permission. Two assignees, so the
  load-by-person chart still has more than one row.
- **A flake that was a real race.** `budgets` and `dashboards` cleared cookies while a page was still open and
  polling, so its queries came back `401`, the client's interceptor sent the browser to the sign-in screen, and
  that redirect could land *after* the next account's own sign-in and bounce them back out — thirty seconds of
  waiting for a heading on a page the test had been redirected away from. Four sites now leave the page before
  clearing its cookies. Every other account switch in the suite already cleared cookies before anything was
  open, which is why none of them flaked.
- **A test passing for the wrong reason, again.** One task test asserted an assignment that the product should
  refuse; the premise was stale rather than the behaviour wrong. Another expected `403` where the product
  correctly answers `404` — a creative does hold `tasks.manage`, and a task on a project they are not on is *not
  found*, which is the answer that does not confirm it exists.
- **A duplicate key in a test mock.** `assignRequestOwner` was already stubbed later in the same object literal,
  so the recorder added at the top was silently overridden and the mutation "never ran". Last key wins, and
  nothing warns.
- **A React hook placed after an early return**, caught by `react-hooks/rules-of-hooks`: the owner query had
  been written next to the `canTriage` it depends on, which sits below the permission guard.
- **An assertion that raced its own mutation.** A `change` event flushes fewer microtasks than a click, so the
  state was read before TanStack had made the request.

### Gates

1 737 backend tests (6 281 assertions), 544 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 380 Playwright tests with no flakes.

## The workspace was closed to anybody new

Three pieces of vocabulary described this operation and nothing performed it: `Permission::UsersInvite`, granted
to owners and administrators and checked nowhere; `AuditAction::WorkspaceMemberInvited`, declared with nothing
recording it; and `MembershipStatus::Invited`, which nothing ever set. Roles could be changed, memberships
suspended and people removed — but nobody could be added. Every member of every workspace existed because a
seeder created them.

### The argument

An invitation wants an email and this repository has no sender identity, the same block that defers notification
digests. Half-building it — a token generated, stored, expired and never delivered — would have been real work
with a fake outcome.

What is built instead is honest about its own weakness. The administrator gets a one-time password to read out,
and the only thing that makes that acceptable is that it expires on use: `must_change_password` makes
`RequireChosenPassword` refuse every request except reading the session, changing the password and signing out.
"An administrator knows your password" is true for one sign-in rather than indefinitely.

### Added

- **`POST /api/members`**, needing `users.invite` — which now has a caller. 32 random characters, returned once,
  stored nowhere: never in plaintext, never logged, never audited. If the administrator loses it there is nothing
  to look up, and that is the property that makes a read-out password tolerable at all.
- **`RequireChosenPassword`**, refusing everything else with `403` `password_change_required`, and
  `must_choose_password` on the session so the interface can act on it rather than render a wall of refusals. The
  forced dialog is the one modal in the product with **no cancel**: the server answers nothing else, so a cancel
  button would close it onto a product that refuses every request. The way out is signing out, which the copy
  points at.
- **An existing address joins rather than duplicates.** One account per person on an installation, and no
  password is returned because none was created — they keep the one they chose, and resetting it would lock them
  out of the other workspace.
- **`MembershipStatus::Invited` is deliberately still unused**, and the reason is now written down: a member in
  that state cannot sign in at all, so without an activation route it would be an account nobody could ever
  open.
- **The owner role is not offered on the add form.** An owner can remove other owners, so creating one is a
  decision for somebody already in that position — `ChangeMemberRole` is where it happens, with the last-owner
  protection around it.

### Found by the gates

- **The caller's own session was signed out by its own password change.** `AuthenticateSessionForSpa` re-stores
  the request user's hash after the response, and the first implementation wrote the new hash to a freshly loaded
  copy of the row — so the session kept the *old* hash and the next request signed this browser out. Exactly the
  behaviour wanted for every other session and precisely wrong for the one being used. The test asserting the
  product opens again came back `401`.
- **The architecture test caught the middleware reaching into infrastructure** for the flag. It was right: the
  question moved onto the `Authenticator` port, where "may this request proceed" is an application question with
  an infrastructure answer.
- **`email:rfc,dns` refused a real address.** A DNS rule makes form validation depend on a lookup and is wrong
  about domains that receive mail through an A record. Dropped to `email:rfc`.
- **A test passing for the wrong reason.** The enforcement test asserted on `/api/clients`, which a media buyer
  may not read anyway — an ordinary permission denial is also a `403`. Moved to a surface the role can reach and
  asserted on the `code`.
- Two test-harness facts worth writing down: `actingAs()` installs a user resolver for the whole test, so a
  session opened afterwards over HTTP is still answered as the first user; and the auth guard caches the model it
  resolved, so a test must forget its guards to behave like a second request.

### Gates

1 730 backend tests (6 258 assertions), 537 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 376 Playwright tests.

## Nobody could change their own password

Two traces in the repository had described this operation since Phase 0 while the product could not perform it:
`AuditAction::UserPasswordChanged`, declared with nothing recording it, and `bootstrap/app.php` scrubbing
`current_password` out of flashed input. A product with login throttling, failed-attempt auditing and a seeded
demo password that cannot be changed is not finished.

### Added

- **`POST /api/auth/password`**, needing a session and **no permission at all**. A password belongs to the person
  rather than to a role — a media buyer must be able to change theirs without `workspaces.update` — and there is
  no `user_id` parameter, so there is no shape in which the request reaches another account.
- **The current password is required**, and a wrong one is recorded as a failed attempt. A session can be left
  open on a shared machine, and without the check whoever found that screen could lock the owner out; repeated
  attempts against a live session is a signal somebody is sitting at a machine that is not theirs.
- **Twelve characters, no composition rule.** No "one capital and one symbol": those push people towards
  `Password1!`, and a long passphrase beats a short mangled word. The minimum lives on the use case so the form
  rule and the server's refusal cannot disagree.
- **The password you already have is refused** rather than accepted as a no-op. Somebody changing a password they
  believe is known has to end up with a different one, and "saved" over the same password is a false assurance.
- **The dialog is in the account menu**, not on a settings page: a page behind `workspaces.update` would have put
  this out of reach of most of the people who need it. **Nothing reports strength** — a meter is a model's
  opinion rendered as a fact, which this product refuses to do with a client's figures and refuses to do with a
  reader's own password.
- The audit entry carries **the fact and the time**, never the password, its hash or its length.

### The claim that needed middleware to be true

**Every other session using the old password ends; the caller's own survives.** That is the entire point of
changing a password somebody may know — and it is not what happens by default. `logoutOtherDevices()` re-hashes
and no session notices, so the sentence would have been a false claim.

`AuthenticateSessionForSpa` makes it true: the framework's `AuthenticateSession` stores the password hash in the
session and compares it on every request. It is a subclass for one reason — `auth:sanctum` makes `sanctum` the
default guard for the rest of the request, so the framework's version asks a `RequestGuard` for `viaRemember()`
and raises a **500 on every authenticated read**. The existing authentication tests caught that within seconds of
the middleware being registered, which is the argument for having them.

The behaviour is asserted directly rather than assumed: a session holding a stale password hash — precisely the
state another browser would be in — fails its next request, and one holding the current hash does not.

### Not built, with the reason

**Resetting a forgotten password.** A reset needs an email and this repository has no sender identity, the same
block that defers notification digests. Everything else about it would be real work with a fake outcome: a token
generated, stored and expired, and then not delivered. Recorded in `docs/SECURITY.md` rather than half-built.

### Gates

1 721 backend tests (6 223 assertions), 534 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build.

## The client portal was rendering a key path

The contract sweep's third direction: every `t('…')` key the code asks for, checked against the translation
files. Three had no entry anywhere.

Two of them were on **`PortalRevenuePanel`** — the client portal's revenue panel, which is the one surface an
agency's client reads. Its two breakdown tables were titled `commerce.byChannel` and `commerce.byProduct`, keys
that were never written, so the page had been rendering those strings where it should have said "By channel" and
"By product". The agency's own page uses `commerce.breakdown.*`, which exists; the portal had invented names for
the same thing and nothing noticed.

The third was `integrations.usedBy`, a plural that was never written, on the account binding panel.

### Why nothing caught it

A missing key is a silent failure: i18next does not throw and the build does not fail. It renders the key path,
so the defect is visible only to somebody who opens that page in that state — and in this case that somebody was
a client.

### Added

**`src/shared/i18n/keys.test.ts`**, checking both directions, because they fail differently. A `t()` call with no
English entry shows a key path to a reader. An English key with no Arabic entry falls back to English, which is
quieter and worse: the page still reads, in the wrong language, inside an interface that is otherwise right to
left.

Arabic's plural forms are why this cannot be a set comparison — CLDR gives Arabic six categories and English two,
so `clients.count` exists as `_one`/`_other` in one file and six suffixes in the other while the call site names
neither. Template-literal keys are deliberately out of scope: they cannot be resolved without knowing the union,
and a check that guessed would either miss keys or invent them.

The gate was **verified by breaking it**: putting `commerce.byChannel` back failed the test with the key and the
file that used it, which is the failure a reader of that output needs.

### Gates

526 frontend tests, TypeScript strict clean, ESLint and Prettier clean, production build, portal end-to-end
suite green. Backend untouched by this slice.

## Phase 15 addendum — the CRM read had no way to reach it

The contract sweep had a frontend half: every route in `routes/api.php` checked for a caller in the SPA. Of 167,
two had none. One is `POST /api/webhooks/stores/{provider}`, which providers call and the frontend must not. The
other was `POST /api/leads/crm-sync` — the CRM bridge was built, tested, documented and **unreachable**, which is
the same failure as a note nobody can read.

### Added

- **A read on the Lead Center**, where CRM-sourced leads appear. It says *read* rather than sync: the CRM owns a
  deal's stage and nothing here writes one back — which is why `ChangeLeadStage` refuses a CRM-sourced lead — so a
  control offering to "sync" would imply traffic in both directions that does not exist.
- **The client is asked for, not inferred.** The panel refuses to offer a read until one is chosen, because a
  HubSpot portal belongs to one company and a workspace has several clients. The filter at the top of the page is
  where that happens, and nothing guesses a client from a company name.
- **The provider comes from the registry**, not from a hard-coded name: the bridge names HubSpot today, and a page
  that spelled that out would need editing for the second CRM.
- **The panel is absent when no CRM is connected**, rather than present and empty. A panel on every workspace that
  has never connected one would be an advertisement, and the place to connect a provider is the integrations page.

### The honesty gap it exposed

**The read did not say what kind of figures it got.** Every other sync in this product returns its source state
and this one returned three counts with nothing else, so a reader was told "12 deals read" with no indication they
came from fixtures. A count is as capable of being quoted to a client as a chart is.

`source` and `source_label` now travel with the answer, into the audit entry as well — "was that real?" is asked
months later — and the page shows the sandbox warning from the server's own answer rather than guessing it from
the connection, because the providers list carries no sandbox flag and inventing one would have been a second
source of truth.

### Gates

1 712 backend tests (6 199 assertions), 523 frontend tests, 372 Playwright tests, PHPStan level 8 clean, Pint
clean, TypeScript strict clean, ESLint and Prettier clean, production build.

## A sweep of the contracts: a limit nobody honoured, and four dead methods

The lead slice found its work by noticing three repository methods with no caller. That turned out to be worth
doing systematically, so every public method on every Application contract was checked for a call site outside
its own implementation and its tests. Fifteen came back; eleven were legitimate (an adapter contract built ahead
of a blocked transport, a probe called from Infrastructure). Five were not.

### The one that mattered

**`maxRangeDays()` was recorded and ignored.** Every sync adapter publishes the longest range the provider will
answer in one request — 92 days for Salla, Zid and the GA4 adapter. The advertising sync windowed its requests by
its own equivalent from the start. The commerce and measurement syncs **sent the requested range whole**, while
holding an adapter that published the limit they were ignoring.

A first sync with a year's `since` therefore asked for a year, was refused by the provider, and the refusal
arrived on a client's store page as "partial" with nothing anybody could act on. A limit that is recorded and
ignored is worse than one nobody knows, because the adapter looks as though it had been honoured.

- **`DateWindow::split()`** — one implementation for all three modules. The advertising sync had this privately;
  date arithmetic copied into three files is date arithmetic that is off by one in one of them, and the symptom
  is a single missing day in a client's report. Inclusive at both ends, because that is how every adapter filters
  a date: seven days is seven dates and the next window starts on the eighth. A test walks a year and asserts no
  day is lost or read twice.
- **A degenerate range still produces one window**, deliberately: a sync that returned none would leave its
  journal with no record of having been asked, which afterwards reads as a sync that never ran rather than one
  with nothing to do.
- **Not `DateRange`**, which is a validated business range and refuses to end before it starts — right for a
  report somebody asked for, wrong for a transport concern that must still make one request.
- Both syncs are now asserted to make **more** requests for a long range than a short one, as a relation rather
  than a count: the fixture's paging decides the absolute number, and pinning it would make the test a test of
  the fixture.

### Removed

- **`IntegrationReadModel::bindingCounts()`** — declared, implemented, never called, and a second way to compute a
  number the surface already had: `accountsForWorkspace` counts bindings per row in the same query. Not so much
  dead code as a divergence waiting to be noticed by a client.
- **`IntegrationReadModel::accountExists()`** — never called and never tested. Untested code that looks like a
  safeguard is worse than no safeguard, because the next reader assumes it runs.
- **`TaskFilter::forClient()` and `forAssignee()`** — two builders on a readonly object that nothing used. The one
  caller constructs the filter in a single call, which is the shape that makes a forgotten field impossible to
  write; unexercised builders are a second construction path nobody tests and the first thing to drift when a
  field is added.

### Gates

1 711 backend tests (6 196 assertions), PHPStan level 8 clean, Pint clean. Frontend untouched by this slice.

## Phase 15 addendum — working a pipeline

Found while closing the merge gap, not from the roadmap: `LeadRepository::assign`, `setValue` and `addNote` had
been on the contract and in the Eloquent implementation since Phase 15 with **no caller anywhere** — no use case,
no route, no test. `AuditAction::LeadAssigned` existed with nothing recording it. The pipeline list had columns
for an owner and a value that nothing in the product could fill, and `lead_events` had no reader at all.

### The argument

Three dead repository methods are two honest options: build the surfaces or delete them. Building was right. A
pipeline where nobody can be given a lead is not a pipeline — the owner column names whoever imported the record
— and the value column is empty on every row a CRM did not produce. Without notes the only trace of a
conversation is the stage it ended in, which turns a pipeline review into several people remembering
differently.

### Added

- **`GET /api/leads/{lead}/owners`, and an assignment that refuses anybody not in it.** The reason is specific: a
  lead carries a prospect's name and phone number, so assigning one to somebody whose projects do not reach that
  client would put their name in the owner column of a record they can never open — **and the notification
  telling them would be correctly suppressed by the same visibility rule, so nobody would ever find out.** The
  column would name a person who does not know. `EloquentLeadOwnerDirectory` mirrors the three conditions
  `PermissionAwareRecipientResolver` already applies, and a test pins them together: somebody the directory
  offers must be somebody the notification reaches.
- **The list is served rather than assembled from the member list**, which would need `users.view` — a permission
  somebody working a pipeline has no reason to hold — and would offer colleagues the server then refuses. It
  carries ids and names only: a picker needs no email addresses, and nobody in it agreed to have theirs listed.
- **`NotificationKind::LeadAssigned`**, its own kind rather than `TaskAssigned` with a different subject. A task
  has a due date and waits; a lead is a person who has just asked for something, and the figure that decides
  whether the enquiry was worth buying is how long somebody took to answer. The body carries no contact details —
  it says a lead is waiting and when it arrived, which is what makes somebody open it.
- **A value that is said, never inferred.** This is the use case that would have needed an "average deal size",
  and there is none: a value from an average is a figure about *other* leads wearing this one's name. Clearing is
  distinguished from zero — nobody has said, versus somebody saying it is worth nothing — a currency is required
  because a number with no unit is not a figure, nothing is converted, and a CRM-sourced lead is refused for the
  same reason its stage is.
- **Notes, and the history that makes them worth writing.** A note nobody can see is a worse surface than no
  notes at all. `GET /api/leads/{lead}/history` needs **`leads.view`**, not `leads.manage`: reading a pipeline is
  how it gets reviewed, and a media buyer may read it while not writing to it.
- **"Who did this" has an honest answer including when it is nobody.** A CRM sync and an arrival have no actor,
  and the row says "recorded by the system" rather than filling in whoever happened to be signed in.
- Adding a note is the one write in the module that is deliberately **not** audited: the note *is* the record,
  carrying its author and time in `lead_events`, and writing both would be one sentence in two places where only
  one is read. It notifies nobody — a bell for every note is a bell that gets silenced, and then an assignment is
  missed.

### Found by the gates

- **A hint folded into a control's accessible name.** The owner picker was a hand-written `<label>` wrapping the
  select and the hint, so a screen reader read the whole sentence back as the field's name on every focus. The
  `Field` primitive wires a hint as a *description*; the test asking for a combobox called "Owner" is what
  surfaced it.
- **A note that was write-only.** The first end-to-end test asserted the note appeared in the journey panel — it
  does not, because a journey is built from touchpoints and a note is a `lead_event`. Nothing anywhere read
  `lead_events`. That is how the history surface came to be part of this slice rather than a later one.
- **A test that raced an in-flight query**, counting the owner options the moment the panel appeared and reading
  only "Nobody yet". Replaced with a named option and a retrying matcher.

### Gates

1 701 backend tests (6 179 assertions), 518 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 370 Playwright tests.

## Phase 17 addendum — the pattern channel

The last Phase 17 gap: *"The palette clears every gate on colour alone in both themes, so the backup channel for
full colour-blindness, grey print and forced-colors mode is not yet implemented."*

### The argument

Clearing the colour gates is the wrong thing to be reassured by. They measure whether two hues separate for the
common forms of colour blindness; they say nothing about three situations where there is no colour to separate.
Achromatopsia is rare and real. A client printing a report on an office laser gets eight greys, several of them
the same grey. And Windows high-contrast mode repaints the page in the reader's own colours and **cannot repaint
a canvas**, so a chart can end up with marks that no longer separate from what is now behind them.

### Added

- **Patterns, on ECharts' own `aria.decal`** rather than painted onto a canvas by hand: the patterns are ours,
  the drawing is theirs. Checked against the installed library's type definitions and the Apache ECharts
  handbook, which is also where two constraints came from — `aria.decal.show` does nothing without
  `aria.enabled`, and `AriaComponent` has to be registered explicitly since version 5.
- **`aria.label` is switched off while doing it.** Enabling `aria` also makes ECharts generate its own sentence
  describing the chart and put it on the element: a second, machine-written claim about figures this product is
  careful about, competing with the description every chart here is already required to supply and with the table
  beside it. Turned off rather than allowed to contradict them.
- **Dash patterns by slot for line series**, because a decal fills a shape and a line has no fill, so ECharts
  leaves lines untouched. From the same fixed order as the colours, so a line's pattern and its colour always
  name the same series. **Slot 1 is dashed too**: a solid first line beside seven dashed ones would read as the
  real measurement among projections, which is what a dashed line means everywhere else here. A line that is
  *already* dashed is left alone — a target, a limit, a comparison — because overwriting it would turn a plan
  into a measurement.
- **It turns itself on where a reader cannot be expected to configure it**: `forced-colors: active`, and before a
  print. The print case needs `beforeprint` rather than a media query, because a canvas prints whatever was last
  drawn on it — without it, "works on screen" and "works on paper" are different answers, and the paper one is
  the copy a client keeps.
- **A Patterns toggle in `ChartFrame`**, beside the chart/table switch, for what no media query can detect. It is
  offered where the problem is noticed — somebody who cannot tell two series apart is looking at a chart, not at
  a settings page — and it is one persisted preference for the whole product, which the control says in its own
  words: a per-chart switch would mean setting it nine times on a dashboard.
- **The campaigns explorer and the pacing panel moved into `ChartFrame`.** The roadmap called this mechanical and
  worth doing when each was next touched; it stopped being merely cosmetic once the frame acquired the toggle,
  because a chart outside the frame has no way to turn the channel on. The pacing chart's table twin reads
  `pacingSeries`, the same function the chart is drawn from, so the cumulative arithmetic exists once — two
  implementations of it would disagree the first time one changed, side by side on the same card.

### What the gate checks, and what it does not

The eight patterns and the eight dashes are asserted pairwise distinct **as definitions**, which catches a
copy-paste, and turning the channel off is asserted to write the disabling values rather than omit the keys —
`setOption` is called with `notMerge`, so an absent key would leave whatever the instance had and the preference
would look like it only ever turns on.

Whether two patterns *look* different at 24 pixels is a design judgement and is **not** pretended to be
measured, unlike the colour separations. The eight come from four distinguishable families rather than eight
angles of one hatch, which would give four patterns and four near-duplicates. One limitation is stated rather
than implied: a single series carrying a colour per datum — an ordinal bar chart where each bar is a stage — is
one series to ECharts and gets one decal; those charts separate their bars by axis label, which they all have.

### Found by the gates

- **A test was rendering the real ECharts component in jsdom**, which has no `ResizeObserver`, so the component
  threw as it mounted and took the tree with it. It had been passing by a timing accident — the lazy chunk
  resolved after the assertion — and the frame changed the timing. Mocked, as every other chart-bearing test
  does.
- **Two source badges on one card.** Moving the pacing chart into the frame gave it a badge beside the one the
  panel header already carries for the same figures. The panel's own rule, written there earlier for a different
  duplication, settles it: the same claim twice reads as two claims.

### Gates

1 678 backend tests (6 122 assertions), 506 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 368 Playwright tests.

## Phase 15 addendum — merging two leads

`lead_events` had reserved a `merged` type since Phase 15 with nothing writing one. The gap list said why: *"the
operation needs a UI and a decision about whose history survives."*

### The argument

That was one question in form and four in substance, and each had an answer this product's own rules already
implied.

**Whose history survives? Both.** The absorbed lead's events move to the survivor and the absorbed row is kept
and marked, never deleted. A client may already have been told a lead count, so a row that vanishes makes last
month's report unreproducible. `lead_events` cascades from the lead, so deleting it would delete the trail of
everything that happened before anybody realised the two were one person. And the absorbed lead keeps its own
`external_id`, which is where the next CRM or form-provider sync looks — delete the row and that sync creates a
duplicate, so the merge would have to be repeated forever.

**Whose age survives? Always the earlier, whichever record does.** Time-to-first-contact and pipeline duration
are measured from `received_at`, and they are the figures that tell a slow sales team from a bad campaign. A
merge that could reset that clock would shorten every speed-of-response number and flatter whoever did the
merging.

**Which record continues? The CRM's, if either came from one.** The same position `ChangeLeadStage` takes.
Merging a CRM lead away would leave the CRM syncing a record this product had stopped counting, and the two
would disagree forever with nobody able to see why. Two CRM leads cannot be merged here at all — that belongs in
the CRM, which holds both.

**Which stage? Neither the product's call.** A lead that bought and a lead that enquired again are both real,
and the merged record's stage is not derivable from either. It is asked for, and it must be one of the two
leads' current stages: a third would move a lead through a pipeline without recording a move, and the review
that asks "who decided this, and when" would find nothing.

### Added

- **`LeadMergePlan`** — the four rules in one readable place, with `LeadMergeCandidate` reducing a lead to the
  facts the decision turns on so a fifth cannot creep in unnoticed.
- **`LeadsCannotBeMerged`** — five refusals, each with a sentence naming what to do instead, because a merge is
  not reversible by any control this product offers. Merging across clients is the one that is about billing
  rather than history: it would move one client's enquiry into another's count, where nothing downstream could
  tell it had been moved.
- **`GET /api/leads/{lead}/duplicates`** — exact matches on a normalised email or phone, within the same client,
  excluding anything already merged away. No fuzzy names, no score. Every row says which contact point matched,
  because a suggestion with no evidence beside it is one somebody accepts without looking. Deliberately **no**
  time window, unlike the 24-hour deduplication on arrival: that window exists so the product does not decide on
  its own, and this is a human asking the opposite question.
- **The closing time comes from whichever lead held the chosen stage**, never from the clock. Inventing "now"
  would date every closure to the day somebody tidied up two records.
- **Both deliberate exceptions to "a merged lead counts nowhere".** `GET /api/leads/{lead}` still returns it, so
  an old link reaches the record and the reader can be sent to the survivor rather than a 404. And the journey
  still shows **both** enquiries: it is a timeline of what happened to a person, and both of them happened — the
  merge decided they were one person, not that one enquiry never arrived.
- **The absorbed lead's stated value is written into the merge note** rather than dropped where the survivor
  already has one. Somebody said it, and it is part of the history even when it is not the figure the record
  carries. Nothing is converted between currencies.
- **A deliberate duplicate pair in the demo seed** — one person, two routes, eighteen days apart, with the two
  names spelled differently. The spelling is the point: the suggestion is made on the number.

### Found by the gates

- **A disabled query reports `isPending` forever**, so the merge panel showed a permanent loading card to a
  reader who may not merge — an operation that is not theirs. Caught by the permission test.
- **The journey panel's test mock resolved `null`** where the server answers `404`. The mock was handing the
  component a value its own type says cannot arrive; now it rejects, as the server does.

### Gates

1 678 backend tests (6 122 assertions), 490 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 366 Playwright tests.

## Phase 14 addendum — new versus returning customers

The Phase 14 gap list said the split was *"a product decision (returning within what window?) rather than a
query."*

### The argument

That framing was the obstacle, and it was wrong. A window is not a definition, it is a knob: pick ninety days
and the headline is one number, pick thirty and it is another, and a figure that moves with a setting nobody
agreed on is not a measurement. The question with one answer is the other one — **was this customer's first
order with this store inside the period, or before it?** Nobody has to agree on a window to agree on that, so
there is no decision to wait for.

What *does* need care is the thing the window was hiding. A store's history in this product begins when its
integration was connected. The customer whose earliest synced order sits exactly at that edge may have been
buying for five years, and counting them as new would make every freshly connected store look like a triumph in
its first month — while the number was really reporting how long the integration had been running.

### Added

- **`CustomerRecency` — three buckets, and the third is the point.** `New`, `Returning` and `Unknown`, where
  `Unknown` is "started before this store was connected". The boundary is the earliest order ever synced for the
  store, so it moves backwards as history accumulates and the bucket empties on its own rather than needing a
  migration.
- **`CustomerMix::newShare()` returns null rather than a number** when the unknown bucket is a fifth or more of
  the period's revenue. A share over a denominator that is mostly "we cannot tell" is exactly the figure that
  ends up in a client report with nobody remembering the caveat. The boundary is inclusive — **at** a fifth it is
  already withheld — and is pinned by its own unit test, because a reader will ask what happens there.
- **Two reasons for no share, told apart.** Nothing was sold, or too much cannot be classified.
  `unavailableReason()` says which, because only the second one gets better on its own, and a reader deciding
  whether to wait a month needs to know which they have.
- **Orders with no customer identifier are excluded from the mix**, not pooled into `unknown`. A guest checkout
  is an order with nobody to classify; a bucket holding both would mean neither.
- **The surface carries no percentages beside the buckets.** This was found by a test rather than by reading:
  the first version of the panel had a share column, and because `new_share` *is* the new bucket's share of
  revenue, that column recomputed — client-side, from numbers that must be shown — the exact figure the server
  had declined to publish. The withholding rule would have held only in the one place a reader was not looking.
  The column is gone; the buckets carry counts and revenue, and the weight of the unclassified bucket is legible
  from its revenue beside the others.
- **The client portal composes the same component on the same figures.** A client told a campaign brought
  revenue deserves to know whether it brought customers.

### Deliberately not built

- **"Active recently" and reactivation.** Both need a window, and that window is a business decision about this
  agency's clients. A reactivation figure additionally needs to know a customer *stopped*, which is
  unobservable: the only evidence is an absence, and an absence looks identical to a customer who has not come
  back yet. The honest shape, if the window is ever decided, is a named cohort carrying the window in its label
  — "bought in the last 90 days" — never a bare "active customers". Recorded in
  [`docs/ECOMMERCE.md`](docs/ECOMMERCE.md#new-versus-returning-customers).

### Gates

1 647 backend tests (6 041 assertions), 484 frontend tests, PHPStan level 8 clean, Pint clean, TypeScript strict
clean, ESLint and Prettier clean, production build, 364 Playwright tests.

## Phase 15 addendum — the CRM bridge

`LeadSourceKind::Crm` had existed since Phase 15 with nothing populating it.

### The argument

The note in `docs/LEADS.md` said why it was outstanding: a CRM integration needs a named provider, its OAuth, and
*"a decision about which system owns a stage when both have one — that last part is a product question rather
than an engineering one."*

That decision turned out to be inferable from this product's own rules rather than from a preference. **The CRM
owns the stage.** The alternative is to let both systems write it, and that fails in a specific way: whichever
wrote last wins, nobody can tell which that was, and the figure a client is shown depends on the order of two
jobs. A sales team loses a day's work to a sync, and the product that lost it reports success.

### Added

- **`CrmStageMeaning` — the rule the whole bridge turns on, and a refusal more than a mapping.** A CRM's stages
  are named by the people using it: "Proposal sent", "Mudawalat", "Stage 3", "Hot". Reading a meaning out of a
  label is the mistake this product refuses everywhere else, so the meaning comes from the metadata the CRM
  publishes about the stage — HubSpot's `probability` and `isClosed`, which are structured statements rather than
  names. Closed at exactly 1.0 is won, closed at exactly 0.0 is lost, **everything else is open and nothing more
  is claimed**.
- **A stage called "Won" that is not marked closed comes back open.** The fixtures carry exactly that — a stage
  labelled "Won" at `probability: 0.9, isClosed: false`, an ordinary pipeline somebody built by hand — and a test
  asserts the lead behind it stays open with no value. A product that trusted the word would report a sale that
  has not happened and put its amount in a revenue figure.
- **An open stage maps to no stage of this product's own.** `New`, `Contacted` and `Qualified` are its words for
  its own pipeline, and a CRM's middle stages do not map onto them. The lead keeps whatever stage it had and
  carries the CRM's own label beside it, attributed to the CRM: two vocabularies, both visible, neither
  pretending to be the other.
- **`ChangeLeadStage` refuses a CRM-sourced lead**, with a message naming where to move it instead — somebody
  working a pipeline needs an instruction rather than a rule — and the pipeline list puts that sentence where the
  controls would have been rather than hiding them.
- **A re-read is the CRM answering again**, which is the opposite of the rule for every other source: there a
  human owns the stage and a later arrival must not touch it. That asymmetry is why the field is called
  `crmStage` rather than `stage`.
- **An open deal's amount is not a value.** It is a quote — a hope, often a round number somebody typed — and a
  pipeline total built from hopes is the figure that makes a forecast indefensible. Only a closed-won amount is
  recorded.
- **Nothing is attributed.** A CRM holds no click identifier and no UTM parameters, so every CRM lead arrives
  with evidence `none` and shows up in the Lead Center's unattributed row, honestly. Reading a campaign out of a
  deal's name would be the false assignment this product refuses everywhere else.
- **A closing time is never "now."** The schema requires a closed lead to carry one; a deal may have closed last
  week, and stamping the sync's own clock on it would make every turnaround figure wrong by however long the
  integration took to arrive.
- `Provider::HubSpot` with a new `ProviderCategory::Crm`, its own capabilities (`pipeline_discovery`, `deal_sync`,
  `contact_sync` — **not** `customer_sync`, which belongs to commerce, because a store customer is somebody who
  bought and a CRM contact is somebody a salesperson is talking to), published rate limits, and the endpoints read
  on 2026-10-08 recorded in `HubSpotAdapter` with their dates.
- `docs/CRM.md`, with the ownership decision, the stage table and what the bridge deliberately does not do.

### The three HubSpot conventions that each have a test

Everything in `properties` is a **string** — `amount` is `"48000.00"`, `isClosed` is `"true"` — so a strict
comparison against a boolean finds every stage open. An empty string is **not a zero**: a deal with no amount
publishes `"amount": ""`, and reading that as 0.00 puts a deal worth nothing into a revenue figure. And a
`dealstage` is an **internal id**, not a label, which is why a deal is never read without its pipeline.

### Fixed — a typed union that lied took a page down

Adding a provider put a slug on the integrations page that `ProviderLogo`'s colour map had no entry for. The
lookup was `undefined`, reading a property off it threw, and the whole page became an error boundary.

The slug union is typed, which is exactly why it was trusted: **a server string cast into a union is not checked
by anything at runtime.** The union is now complete *and* the component renders an unknown slug as its own
initials on a neutral chip, because the server owns the provider list — a new provider missing its colour should
be a cosmetic gap somebody notices, never an outage. Its own test file records the defect, and this is the second
time in this session that a frontend union was quietly behind the server; the other was the notification kinds.

### Blocked externally

An app in a HubSpot developer account, and an account that installs it and grants the read scopes. Until both
exist the adapter serves fixtures shaped like the published responses and **refuses real credentials** rather
than quietly serving fixtures to a workspace that had configured a real connection — which would put invented
deals in front of a client. A test asserts that refusal.

## Phase 20 addendum — the boards are live

Three boards can be open on one screen while somebody else moves something on another.

### The argument

The notification channel already had the right design, and it was being used for one thing. A board is a
different question — everybody who may read it wants to know it moved, not one named person — so the question was
whether a workspace-wide channel could be made safe.

It can, on one condition: **the payload carries nothing.** `BoardChanged` sends the board's name and a timestamp,
so the browser's only possible response is to read the board again over the authenticated endpoint, where project
visibility already decides which rows that person gets. A payload carrying the row would have to answer that
question a second time, and the second answer is the one that eventually differs.

### Added

- `OperationsBoard` — three boards, each naming the permission a listener must hold, and that permission is the
  whole of the access control on the channel. A client viewer holds neither `tasks.view` nor `approvals.view` and
  cannot subscribe to the agency's boards at all.
- **The client-request channel is `submit`, not `view`**, so a client's own queue updates on their screen too. It
  is the permission that let them raise a request, and the board they get back is already narrowed to their own —
  the channel grants no reading the endpoint would not.
- `ChannelAuthorizer::mayWatchBoard`, checking the permission **through the subscriber's role in that workspace**
  rather than through the session's resolved permissions: a member of two workspaces has two roles. Re-checked on
  every subscription, like membership, because a socket authorised once would keep nudging for as long as the tab
  stayed open. A board name nobody publishes is refused rather than treated as a board with no permission — the
  channel name is attacker-supplied text.
- `BoardSignaller` as a port, so no use case imports a broadcaster and the whole thing is a no-op in a test. Its
  implementation **catches and reports**, exactly as the audit recorder and `Announce` do: a task that moved and
  whose signal threw would leave the move recorded, the board un-nudged, and the caller shown a failure for
  something that did happen.
- Sixteen use cases signal after their write — every move on the three boards, with a task raised from a request
  signalling **both**, because the request's own row changed too.
- `useBoardRealtime`, which invalidates rather than inserts, and **returns whether this page is actually being
  pushed to** — set when the subscription exists rather than from a configuration value that was present at boot.
- `LiveBadge` on all three boards, saying either way. Realtime being off is not a failure — every move is written
  to the database and arrives on the next read — but a reader who believes the page is live and sees nothing move
  concludes the product is broken, and then stops trusting the figures on it.

### Noted while testing

React's StrictMode mounts, unmounts and mounts again, so a channel's cleanup legitimately runs more than once.
The test asserts the cleanup is **idempotent** rather than pinning a call count, which would be pinning React's
development behaviour instead of the property worth having.

## Phase 20 addendum — files on a client request

"Screenshots attached" was a sentence a client could write and not act on.

### The argument

A client reporting that three product images are wrong has a screenshot of it. Without somewhere to put the
screenshot they describe it, the agency guesses, and the thread takes three more messages — or the file goes by
e-mail, which puts it outside every record this module keeps.

So files, with every rule the messages beside them already follow. The security argument is the whole of the
design, because an upload is the one thing on a request that can be dangerous as well as confidential.

### Added

- `AttachmentType` — an allow-list decided by the file's **own bytes**, sniffed with `finfo`. Not the extension
  and not the `Content-Type` header, both of which the uploader chose; Laravel's `mimes:` validation reads the
  header, so it is deliberately unused. The test that matters uploads an HTML document called `brief.png`, which
  an extension check and a header check both pass.
- **SVG is refused**, with the reason in the message. An SVG is a document that can carry `<script>`, every
  stored-XSS in a file-upload feature is an SVG served inline, and "export it as a PNG" is an answer a client can
  act on. A zip is refused for the mirror-image reason: a .xlsx and an arbitrary zip are the same magic bytes, and
  telling them apart would mean trusting the extension again.
- **Nothing is served inline, including images.** `Content-Disposition: attachment`, the stored sniffed type,
  `nosniff`, and `default-src 'none'; sandbox` on the response itself. A PDF can carry JavaScript a browser's own
  viewer will run.
- **Nothing of the uploader's reaches the filesystem.** The stored name is a generated identifier plus the
  extension of the sniffed type; a test uploads `../../etc/passwd.png` and asserts where it did not go.
- **The audience rule, unchanged and in SQL.** A client's upload is forced to client-visible — their own file must
  reach the person they are talking to, and a CHECK refuses anything else — while an agency member chooses every
  time with no default. An internal attachment is excluded from a client's read in the WHERE clause, and an id
  from another client's request answers 404 rather than 403, because a 403 confirms it exists.
- **Every read goes through the API**, so `AttachmentStore` offers no public URL: a signed bucket link would be a
  second place the audience rule has to hold. Downloads are audited — who took a copy of somebody else's file.
- **A file is not an answer.** `first_answered_at` is deliberately untouched by an upload: an agency that attached
  a screenshot and said nothing has not answered the client, and a figure that counted it would report a
  responsive queue while the client waited for a sentence.
- A missing file answers **404**, not 500. A restore from a database backup without the disk behind it produces
  exactly that state, and the honest answer is that the file is not there.
- `readableSize` in the shared formatters, in binary units — what every operating system shows for a file, so a
  reader comparing this against their own file manager sees the same figure.

### Not built, and said rather than implied

**There is no virus scanner.** No scanning service is attached to this repository. The defences are the
allow-list, the five-megabyte limit, a generated name and a download that is never a document in this origin —
a file is treated throughout as somebody else's file. `docs/OPERATIONS.md` says so where an operator will read
it, and says where a scanner would go: between the controller and the use case, the one place the bytes exist in
memory.

**The bucket is BLOCKED_EXTERNAL.** `ATTACHMENT_DISK` is the private local disk here; production wants object
storage and no cloud account is attached. Only the driver changes — nothing above `AttachmentStore` knows which
it is.

### Found while testing

`UploadedFile::fake()` overrides `getMimeType()` with a type derived from the **filename**. Every assertion in
this feature is about what a sniffer says about the bytes, so the fake would have made the whole suite pass by
agreeing with the extension — the precise mistake the production code refuses to make. The tests build real
uploads over real temporary files instead, and the comment above the helper says why.

## Phase 20 addendum — the client is told

The intake queue could be moved, promised, declined and delivered without the person who raised it ever
learning.

### The argument

A declined request carries a required reason that is *shown to the client* — and nothing told the client the
request had been declined. The reason was there for somebody who happened to look. That is the same failure as
not requiring one: an intake queue that answers into a void, where a client's only way to learn anything is to
open the portal and compare it against their memory.

### Added

- **Four notification kinds**, taking the enum from five to nine. Four rather than one because the recipient and
  the action differ: a client told their request moved has nothing to do, a client asked for information has, an
  agency told a client replied has, and "the agency answered you" goes the other way entirely. Collapsing them
  would mean one notification whose recipient depended on a field, and a disclosure decided by a field is a
  disclosure somebody gets wrong.
- **`client_request_replied` closes a gap nobody had noticed**: a request answered once and then replied to is a
  client waiting again, and `client_request_submitted` only ever fired on the first message.
- **`needs_information` is its own kind**, because it is the one move that puts the work back on the client — and
  "waiting on you" is the only figure a notification centre leads with usefully.
- **An internal note announces nothing.** Not a quieter notification: none. A bell saying "the agency replied"
  for a note the client cannot read would be the cruellest bug in this module, and it is asserted from the
  client's own session.
- **The notification carries the state, not the message.** The message is on the request, where the audience rule
  decides who may read it; copying it into a bell would be a second place that rule has to hold.
- **The three client-facing drafts name one recipient and carry no project scope** — the only place in this
  product that omits it. The audience is the author of the request, so "could they have read this" is answered by
  their having written it, and a client who had been taken off their own project would otherwise be dropped from
  the one message they are owed. A test asserts a client viewer on an unrelated project is told nothing.
- **`submittedById` on the request view**, and deliberately not withheld from a client-facing read the way the
  *name* is: a name tells a client which of their own words were re-typed by somebody else, while an id is who a
  notification goes to. `ClientRequestResource` does not serialise it.
- **A "since you last looked" panel in the client's portal.** The portal has no bell and should not have one, but
  a notification with nowhere to be read is a notification that was not sent. Unread only, no history, absent
  entirely when there is nothing to say — a permanently visible panel reading "nothing new" is furniture.
- A migration that widens the `notifications_kind_check` constraint from the enum rather than from a list typed
  twice, and whose `down()` deletes the rows the narrower constraint would reject instead of leaving a migration
  that cannot be rolled back.
- The demo now answers **one** of the client's own requests. Every client-raised request in it was previously
  ignored or still open, so the loop this module exists to close could not be seen at all — and the portal's new
  panel would have been empty in the only workspace anybody looks at.

### Fixed

The frontend's `NotificationKindValue` union had been missing `client_request_submitted` since Phase 20. The
labels come from the server, so nothing was visibly broken; the type was simply lying, and a union that lies is
worse than no union because it makes a `kind` that stops being announced invisible to the build.

## Phase 10 addendum — container readiness has a surface

The endpoint had been built since Phase 9; nothing in the interface called it.

### The argument

`GET /api/measurement/sources/{source}/readiness` and its hooks existed and were unused, which meant the one
thing this product can say about a tag container — whether the **published** version refers to the property
somebody registered — was reachable only by reading the OpenAPI document. A capability with no surface is a
capability the people who need it do not have.

### Added

- `ContainerReadiness`, as the Tracking Center's **fourth** panel. The order is the design: the first three are
  derived from this product's own tables and always answer, and this one reaches Tag Manager on the workspace's
  credentials. A slow or unauthorised provider cannot empty the page of facts it already had.
- **Asked per container, not for all of them.** Each answer is a live provider call, and a page that fired one
  per container on load would be slow in proportion to how much a client has set up.
- **Published state, separated from saved state.** "Edited since publishing" is the commonest reason a tag that
  looks correct is not firing, and this is the only screen that can tell the difference.
- **The measurement id cross-check**, which is why the panel is worth building: expected comes from the client's
  *own* analytics sources rather than from anything typed on the screen, so a container publishing a different
  property than the one somebody registered shows up here and nowhere else.
- **No freshness.** A container reports configuration, not events; labelling every container stale because no
  event arrived would teach a reader to ignore the word.
- **A failed read shows the provider's own words.** Reporting "no issues" after a call that never arrived is the
  worse of the two possible wrong answers.
- **The permission is published rather than the panel hidden.** Reading a container needs `tracking.manage`, and
  a reader who cannot see why the panel is empty assumes the containers are not connected.
- The demo workspace now seeds a sandbox tag container **whose published version refers to a different id than
  the analytics source beside it** — a demo where the two agreed would demonstrate nothing.

## Phase 21 addendum — the report as a workbook

The export the reporting phase shipped without.

### The argument

CSV was the only export, and a CSV is a file where every cell is text. A column of spend arrives in Excel as
text, so the first thing anybody does is retype it; a date arrives as a string and sorts alphabetically, which
is fine until a period crosses a year. That is the whole reason somebody asks for XLSX rather than CSV, and it
is not a formatting preference — a retyped column is a column that can differ from the report it came from.

Both exports stay. A CSV is what a script reads; a workbook is what a person opens.

### Added

- `XlsxWriter` behind `ReportWorkbookWriter`, and `GET /api/reports/{report}/xlsx`. One `GetReport`, one set of
  figures: the workbook cannot disagree with the CSV or with the screen, because none of them computes anything.
- **Types, which is the point.** A number is numeric, a date is a real date with a date format, money carries
  its currency in the cell's own number format and a percentage is a true percentage that survives being
  charted rather than a number that happens to be out of a hundred.
- **The caveats are repeated on every sheet.** A workbook makes this harder than a CSV rather than easier: a
  reader opens the tab they were sent to and never sees sheet one. The source state, the period and the
  comparability verdict sit above every section's table. A file whose warnings live on a tab nobody opens is a
  file with no warnings.
- **An unavailable figure is written as its reason, as text, in the numeric column**, where it is visibly not a
  number and refuses to be summed. A blank cell is added as zero by whoever opens the file next, and this
  product's whole position is that a missing figure is not a zero.
- **The source state is in the file's own properties** as well as its cells, so the claim is attached to the
  file rather than to a cell somebody can delete.
- **Sheet names are made unique rather than truncated into a collision.** Two sections can legitimately share a
  title over different metrics, and Excel refuses a duplicate sheet name instead of disambiguating it.
- The export is tested by **opening the file** with the library a spreadsheet application would use — the
  assertion is about the artefact, not the code that wrote it — and the end-to-end suite takes delivery of the
  download and checks it is a readable archive.

### Fixed — invalid ARIA on every loading state

Seven skeleton panels carried `aria-label` on a plain `<div>`, where it is **prohibited**: a generic element
takes no accessible name, so the label was invalid ARIA and the panels were silent to a screen reader. The
accessibility gate caught it as an *intermittent* failure, which is the whole reason it survived — a loading
state is on screen for a second, and nobody is auditing during that second.

`LoadingRegion` is the one place it is fixed, with `role="status"`: the role permits the name, and it makes the
region a polite live region so the announcement happens when the skeleton appears rather than only if somebody
navigates into it. A unit test now asserts the **role** rather than the label, so the next one fails
deterministically instead of once in ten runs.

### Still not built

PDF, deliberately. It stays a print stylesheet rather than a headless browser, for the reason the presentation
module gives on its own page: a second rendering engine is a second thing to disagree with the screen, and its
Arabic shaping would not be the browser's.

## Phase 26 — production hardening

What this product needs to run for somebody other than its author.

### The argument

The brief names eight things: security, performance, load, queue resilience, observability, backup and
restore, WCAG 2.2 AA, deployment and runbooks. Six of them are buildable here. Two are not, and saying which
is the first act of hardening — there is no hosting account, no staging environment and no error tracker
attached to this repository, so **no figure in `docs/PRODUCTION.md` is a production figure**, and that
document opens by saying so rather than letting a reader assume otherwise.

The rest is the honesty rule applied to the product's own plumbing: a probe that cannot see something must say
it could not look.

### Added

- **A queue is judged by the age of its oldest waiting job, not its depth.** The previous probe reported *ok*
  for any backlog under a thousand, so a worker that died with three jobs queued was indistinguishable from
  an idle, healthy queue — and a dead worker means a sync that never ran and a figure on a dashboard that
  nobody refreshed. Past five minutes, nothing is consuming it. Five rather than one, because the longest
  backoff this product schedules is fifteen minutes and a probe that cries wolf at a working retry is a probe
  somebody turns off.
- **`WorkBacklog` can answer "I could not look".** Redis publishes a depth and no ages, so the probe's detail
  says *"this driver does not publish job ages, so a stalled worker is not detected here"* rather than
  reporting a clean bill of health it has no basis for. An empty backlog and an unreadable one both have a
  null timestamp; conflating them is the mistake the flag exists to prevent.
- **A failed job is degraded on the first one, not the tenth.** It is the same class of fact as an approved
  change nobody carried out: something somebody believes happened and did not. A failed sync leaves
  yesterday's spend on a dashboard; a failed webhook leaves an order out of a client's revenue. Neither is
  visible in a queue depth of zero — the queue is empty *because* the work was abandoned. No threshold,
  deliberately: the first quiet failure is the one worth reading.
- **Query budgets as scale invariance rather than a ceiling.** The query count must not grow with the number
  of rows — which needs no magic figure, and catches the one performance defect that is invisible on a
  developer's three seeded rows and takes a page down on a client with four hundred. Asserted on the client
  list, the approvals queue and the work board, each run twice with ten times the rows.
- **`make a11y`** — axe-core over the published `wcag2a`/`wcag2aa`/`wcag21a`/`wcag21aa`/`wcag22aa` rule sets,
  against the running application, signed in as a real role: five surfaces, both browser projects, and once in
  Arabic right to left. Twelve gates. What automated rules *cannot* check is written down rather than implied,
  because a green run means "no machine-detectable violation", which is a smaller claim than "accessible".
- **`tokens.contrast.test.ts`** — the cheaper half of the same gate. It parses `tokens.css` and measures every
  ink against every surface it can be read on, in both themes, at 4.5:1 for text and 3:1 for the focus ring.
  Parsed rather than copied: a palette asserted against its own duplicate is not a gate.
- **`ScrollRegion`** — one primitive for the fourteen places a table or a board scrolls sideways. A region
  that scrolls with no way into it from the keyboard hides its own content: a pointer can drag it and a
  keyboard user never sees the last three columns.
- **`make backup` and `make restore`, with a manifest.** The dump is accompanied by every table's row count,
  the server version and the migration count, and the restore compares against it in a scratch database that
  is then dropped. The manifest is the whole point: an archive that loads is not an archive that is complete,
  and the only check that means anything is a count taken at dump time against one taken after. The
  comparison was itself tested by tampering with a manifest — it reported the mismatch and exited non-zero.
- **`make load`**, preferring k6 and falling back to a curl sampler that says it is a sampler. Three findings
  came out of building it, all properties of the product rather than the harness: the rate limiter is **per
  user**, so a run driven by one account measures the limiter; signing in is throttled at five attempts a
  minute, so a harness that re-authenticates locks itself out on its second run and the cookie jars are
  therefore kept; and a 403 is a **correct** answer, so the harness probes every session/endpoint pair once
  and drives only the readable ones, printing what it excluded — a pair that should be readable disappearing
  from that list is a permission regression.
- `docs/PRODUCTION.md`, `docs/RUNBOOKS.md` (seven procedures), and two threat notes in `docs/SECURITY.md`.

### Fixed — five real accessibility defects, found by the new gates

- **Tertiary ink measured 3.66:1** against the sunken surface and 4.03:1 against white, both under AA. It is
  not decoration: it carries table headers, timestamps and the source labels this product's honesty rests on.
  Re-derived to a new ramp step at 4.80:1 on the worst surface.
- **Amber measured 3.07:1 on its own badge tint** — the worst pair in the system, and the colour of a caveat.
- **The info ink** cleared white and then failed the canvas and its own tint.
- **Dark-mode badges measured 3.39–4.11:1**, because a dark tint washes the text's own hue over the surface and
  moves the backdrop *towards* the text instead of away from it. The tint went from 16% to 6%, one alpha for
  all four: the tint is decoration and the text is information, so the decoration gave way.
- **Fourteen scrollable regions had no keyboard route into them.** Fixed once, in a shared primitive.

Every replacement step was found by searching the ramp for the lightest one that clears the threshold on every
surface it appears on — not chosen by eye, and not the threshold lowered.

### Blocked externally

Container images and a pipeline, a staging environment, an error tracker, a metrics backend, an uptime monitor
and a backup schedule. Each needs a hosting account, a key or a host that only the company can provide.
`docs/PRODUCTION.md` closes with that list rather than ending on what exists, because a production document
that names only its own strengths is the one somebody trusts by mistake.

## Phase 25 — provider write operations

Everything else in this product reads. This is the one place it writes.

### The argument

The brief's condition is the whole design: **only where the API officially supports it**. Not where the
provider's own interface offers the change, and not where it is obvious that an API would — the failure mode here
is not a wrong number on a screen but a campaign paused that should not have been, or a client's budget set twice.

So support is a three-state answer rather than a boolean. `documented` means that provider's current reference
was read for that operation and the endpoint and fields were recorded. `unverified` means it very likely works
and nobody here has read it — which is not a smaller yes, and is the state TikTok and X are in. `not supported`
is the rest. Only the first permits an attempt.

### Added

- `app/Domain/Integration/` — `WriteOperation` (three: pause, resume, set daily budget, each mapped to the
  approval kind that authorises it and the capability it needs), `WriteSupport`, `WriteTarget`, `WriteFailure`,
  `WriteOutcome`, `WriteRequest`, and `Exception/WriteRefused` with seven named refusals.
- **The campaign is read back.** `WriteOutcome::confirmed()` cannot be constructed without the state the provider
  reported on a subsequent read. An accepted request is not a confirmation: an API that returns 200 and applies
  the change a minute later, or not at all, is a normal API.
- **Seven gates in `CarryOutApproval`**, and the fingerprint is among them. A proposal amended after a decision is
  a different change and needs a different approval — which is the same guarantee the decision itself rests on,
  applied to the act rather than the record.
- **`WriteTarget` reads a free-form proposal and refuses anything ambiguous.** It needs `provider`,
  `external_account_id` and `external_campaign_id`, plus an amount for a budget change. A proposal saying "pause
  the retail campaign" is not a campaign id, and a product that matched it by name would eventually pause a
  different campaign with a similar name. A pause carrying an amount is also refused: that is a proposal
  describing two changes while asking approval for one, which nobody could read correctly.
- **`provider_writes.execute`, which is not `approvals.decide`.** Approving a change and being the credentials
  that move a client's money are different acts, and a workspace that collapsed them would have the person who
  authorised a budget rise also be the only record that it happened.
- **The attempt is audited before the request leaves.** An outcome of `unknown` — a timeout, a dropped connection
  — has no response to record, and an entry written beforehand is the only thing that can say a request was sent
  at all. Confirmation and failure each add their own entry with the provider's own words and request id.
- **`safe_to_retry` is false for exactly two failures**, and the surface says so in words. `rejected`, because
  the provider understood and declined and will decline again; and `unknown`, because a second attempt after an
  unknown outcome is how a budget gets set twice. A reader told only "it failed" presses the button again.
- **A failed write leaves the request exactly where it was** — approved, awaiting a hand. Not abandoned: nobody
  decided against the change. That is what lets somebody retry it, or go and do it by hand and record that.
- **`SandboxCampaignWriter` refuses real credentials** rather than quietly simulating a live account, and every
  outcome it returns carries `MetricSource::Sandbox` — into the audit entry for ever, and onto the screen beside
  the confirmation. A simulated pause recorded as a real one is the one failure this feature must not have.
- **A request form on the approvals queue.** Asking is its own permission and the queue is where somebody asks,
  and until now nothing in the interface could raise one. The proposal is entered as fields rather than a shape,
  because this product deliberately does not model each platform's schema to describe a change to it — and the
  fingerprint a decision names covers exactly the characters that were typed. The kind's consequence is stated at
  the moment of asking, not only at the moment of deciding: somebody who raises a spend change believing approval
  raises the budget writes the client an email that is wrong by one working day.
- **`carry_out_blocked_because` is published rather than the control hidden.** Most approvals name no platform
  campaign — that is the ordinary case — and the reason is what sends a reader to the platform, which is the right
  place once they know why.
- `docs/PROVIDER_WRITES.md`; `POST /api/approvals/{approval}/carry-out` in `docs/API.md`; two threat notes in
  `docs/SECURITY.md`.

### Blocked externally

The live HTTP adapters, for every provider in the table. Each needs an app approved in that provider's developer
programme for a write scope (Meta `ads_management`, Google Ads a developer token at standard access, Snapchat and
LinkedIn their own review), the account owner's OAuth authorisation for it, and a real ad account to verify
against — a write cannot be verified against a fixture. The contract, the gates, the audit, the failure taxonomy
and the surface are all real and tested; only the HTTP call is simulated, and the product says so everywhere the
result appears.

### Changed

- **The demo workspace seeds a sixth approval** — approved, waiting, and naming the sandbox ad account and an
  obviously fictional campaign, so the surface can demonstrate both halves: a request this product can carry out,
  and four that it cannot and says why. `DemoBoardTest` asserts exactly one of the waiting requests names a
  campaign, so the demonstration cannot quietly become two.
- **The roadmap's duplicate phase 24 is corrected.** Provider writes and production hardening were numbered 24
  and 25 beside the presentation generator's 24; they are 25 and 26.

## Phase 24 — the presentation generator

A deck is a report, laid out for a room.

### The argument

The brief is explicit: build the theme/token abstraction **first**, and do not hard-code a presentation identity
before the reference deck arrives. Smart Code's has not been provided to this repository — no deck, no guidelines,
no palette — and a guess would have been baked into every renderer, every layout and every exported file, making
its replacement a rewrite rather than a configuration change.

### Added

- `app/Domain/Presentation/` — `PresentationTheme` (tokens, **colours named by role** so a token called "blue"
  cannot be rebranded), `DeckKind`, `SlideKind`, `DeferredDeck`.
- **The provisional theme says so**, on the deck, on the page and in the file's own properties. A placeholder
  presented as an agency's brand is worse than an obvious placeholder, and a test holds every colour to being a
  role rather than a hue.
- **Two faces, picked per string.** Arabic set in a Latin font is Arabic that has been let down by whoever made
  the deck, and a slide legitimately carries an Arabic headline over a Latin platform name — so RTL and the font
  are decided per paragraph, not per deck.
- **A deck is composed through `RenderReport`**, the same aggregation the reporting surface and the client portal
  read. A generator with its own analytics would be a second set of numbers to reconcile, and the reconciliation
  always happens in front of a client. Nothing is saved: the reporting module gains no row because somebody
  generated a deck.
- **The period is part of what a deck means**, not a parameter. A "weekly report" over ninety days is a different
  document wearing the same name. The closing report compares against **nothing**, because there is nothing after
  an engagement to compare it with and inviting the comparison would have a reader treat the end of a flight as a
  trend.
- **The caveats are a slide.** On screen a comparability refusal is a sentence; in a room, read out loud by
  somebody who may not have built the deck, a footnote in six-point type is a footnote the projector crops. It
  sits immediately after the figures it qualifies, and is omitted entirely when there is nothing to qualify — a
  slide saying "no caveats" teaches a reader to skip the caveat slide.
- **Every figure is formatted once**, in `MetricText`, because a deck is rendered twice. Formatted independently
  in each renderer a figure would eventually differ: a different rounding, a different separator, a currency still
  in halalas on one of them. Deliberately *not* the same formatter as the CSV's, which is read by a spreadsheet
  and must carry no separators at all.
- **The commentary slide is the assistant's own narrative**, with its attribution: when a language model wrote it,
  the slide says so. A deck whose commentary came from elsewhere would be a deck nobody could check against the
  rest of it.
- **A real, editable PowerPoint**: text runs, native tables, native charts with embedded data. An agency changes a
  title, drops a slide, adds one of their own — a deck they cannot open is a deck they re-make by hand.
- **The media plan draws the allocation whether or not the budget is approved.** The budgets module paces only an
  approved budget, because a variance from an unapproved figure means nothing; but the allocation *is* the plan,
  and a deck that showed nothing until approval would be useless for the one occasion it is most needed. The
  status is on the title slide, in words, and the unallocated remainder is its own figure rather than a
  subtraction left to the reader.
- **`campaign_proposal` is published as deferred.** A proposal is a document an agency writes — the objective, the
  audience, the creative idea — and composing one from last month's delivery would shape it entirely by what was
  easiest to measure. The slide shapes and the theme are built and waiting; what is missing is the brief.
- `docs/PRESENTATIONS.md`, and `phpoffice/phppresentation` as the PPTX writer.

### Changed

- **An unauthenticated request that did not ask for JSON now answers 401 rather than 500.** Found by an
  end-to-end test fetching a file download, which a browser does without an `Accept: application/json` header:
  the framework's default sends a guest to a route named `login`, this backend is API-only and has none, and the
  redirect threw. A server error where the honest answer is "you are not signed in".
- **The deck download reads the file and sends it with an explicit length**, rather than streaming it from disk
  and deleting it afterwards. The streamed version passed through the test kernel and returned 500 through a real
  server, because PHP's development server closes a binary file response early and the proxy reports a reset
  connection. A download that works only under some servers is one somebody will hit on the day they need it.
- **The end-to-end placeholder test was replaced by an exhaustive one.** It visited whichever nav surface was
  still a placeholder and asserted the phase on it — Analytics, then Reports, then the assistant, then
  Presentations. Every navigable surface is now implemented, so `routes.test.tsx` walks **every** nav item and
  fails if any still falls through to the placeholder. One example became the whole set, and a nav item added
  without a page now fails at build rather than when somebody clicks it.

## Phase 23 — the assistant

What the figures say, with the figures.

### The argument

One sentence holds this module up: **the product computes every number, and the model only writes the prose.**

The alternative is the obvious design — hand a question to a language model, let it decide which figures to fetch
and do the arithmetic. That is an assistant whose denominators were chosen by a language model, and a wrong
denominator is the one error in analytics nobody catches, because the answer looks fine.

### Added

- `app/Domain/Intelligence/` — `AssistantMode` (four, with `DeferredMode` carrying the fifth and its reason),
  `Finding`, `Evidence`, `FindingSeverity`, `Confidence`, `QuestionIntent`.
- **`NarrativeGuard`, which makes the claim checkable rather than aspirational.** Every number in a model's prose
  is matched against the figures the product computed; anything unmatched means the model produced a figure, and
  the narrative is **discarded**. The tolerance is the precision the prose chose — two decimals buys half of the
  last place — because a relative tolerance would have let "1,250,001" pass as a restatement of "1,250,000", and
  years are not exempted because that is the hole "up 2024%" walks through.
- **The permitted list is every figure in the evidence and every number in the brief's own text**, read by the
  same scanner the guard uses. The rule is therefore: the model may restate the brief and nothing more.
- **The product's own narrative is held to the same standard**, and a test asserts it. A guard holding a model to
  a rule the product does not keep would be a guard worth nothing.
- **The product's narrative is composed first, unconditionally.** A reader never waits on a third party to find
  out what their own figures did, so a missing, slow or overloaded model costs them a paragraph rather than a
  request — and the response says who wrote what they got.
- **A refused narrative is published, not swallowed.** `model_rejected` with its reason, on the response and on
  the page: a guard nobody can see working is a guard nobody will maintain.
- **Five analysers, each a pure function over one fetched context** — no database, no clock, no HTTP. That is what
  lets "a doubling of cost per click on nine clicks is not a finding" be a rule somebody reads and tests, and it
  means two analysers cannot disagree about what the period's spend was.
- **Measurement first, and the period's own shape always.** A conversion figure that cannot be trusted outranks a
  conversion figure that moved; and without a finding that always speaks, a quiet period produces an empty page —
  which says "nothing is wrong" when it means "nothing matched".
- **The refusals.** No rate below thirty events, emitted as a finding rather than as silence; no change from a
  base of zero; no total of zero where there is no data; no recommendation on indicative evidence; and no verdict
  on where budget should go, because a search click and a feed click are not the same click and this product can
  see the price and not the plan.
- **Analysers a reader may not run are named.** An answer that quietly differed by role would leave the reader
  with fewer permissions unaware a question had gone unasked.
- **A closed list of six question intents, matched by keyword, Arabic beside English.** `Ask` runs the full
  analysis and then *selects* from it, so a question cannot choose its own denominator — and a question matching
  nothing is refused with the list, because an assistant that guesses will eventually answer a different question
  convincingly and nobody will notice.
- **The Messages API adapter**, built against the published contract and BLOCKED_EXTERNAL on a key this repository
  cannot hold: `x-api-key` and `anthropic-version: 2023-06-01`, no `temperature` (deprecated after Claude Opus
  4.6) and no `thinking` (the accepted settings differ across the models this could run against; adaptive is the
  default on all of them), one retry on 429/500/504/529 and none on an answer. The whole contract is tested
  against documented-shape fixtures.
- **The brief carries no client identity.** Sending a third party "Alhambra's cost per click doubled" discloses a
  commercial fact about a named company; sending "the account's cost per click doubled" discloses a number.
- **`Report` proposes and saves nothing**, and validates the definition through the reporting module's own
  `assertValid()` first — a proposal that module would refuse costs a reader the time to find out. Proposed as
  internal, always: a client-safe report is one somebody has read as the client would.
- **`Plan` is published as deferred, with its reason.** A media plan needs the objective, the budget and the
  flight dates somebody agreed with the client, and drafting one from performance alone would produce a plan
  shaped entirely by what was easiest to measure last month. A mode that appeared in the interface and produced
  nothing would be a placeholder wearing a feature's clothes.
- `docs/INTELLIGENCE.md`.

### Changed

- **`ClientVisibility` — one implementation of "may this reader see this client".** Reporting, the client portal
  and the assistant all ask it, and three nearly-identical SQL expressions of one rule would eventually be three
  rules with the loosest one deciding. The assistant checks it **before** reading the client's name, so a reader
  cannot learn that a client exists by asking about one they are not assigned to.
- **`TrackingHealthComposer` is now shared by the assistant too**, under its own authorization. The tracking
  finding and the Tracking Center give one diagnosis of one site.
- The analysers read `MetricSet`'s own source state rather than a per-figure one. The DTO carries one state for
  everything in it precisely so a reader gets one badge rather than seven, and reaching past that was a second
  answer to a question already answered.
- The navigation's assistant entry now requires `analytics.view`. An assistant that answered questions a reader
  may not ask directly would be a route around every other gate in this product.
- The end-to-end placeholder assertion moved to `/presentations` and Phase 24, which is the drift it exists to
  catch.

## Phase 22 — the client portal

What each client can read, who decided it, and when.

### The argument

Until this phase a client's access was a permission on a role: `analytics.view` sat on `ClientViewer` and every
client in a workspace shared it. **That is not a decision anybody took about a particular client** — it was taken
once, about a category, by whoever first needed a client to see a chart.

The question an agency actually faces is asked per account. *Can Alhambra see last month's spend? Who let them?
Since when? Can we stop it this afternoon without taking it away from everybody else?* A role answers none of
those, so the grant moved: four surfaces per client, each exposed by a named person at a recorded time, each
revocable on its own.

### Added

- `app/Domain/Portal/` — `PortalModule` (four exposable surfaces), `WithheldModule` (seven that never will be,
  each with its reason), `AlwaysAvailable` (one that cannot be withheld), `PortalState`.
- **Three closed lists rather than one.** A portal built the other way round — every surface available, each
  behind a toggle — makes exposing the agency's own reasoning a *configuration mistake* rather than an
  impossibility, and configuration mistakes get made on a Friday by whoever is covering. A test holds the three
  lists disjoint: a surface in two of them would be a product that exposes something it also publishes a refusal
  for, and whichever list the caller read would decide the answer.
- **The refusals are published, with reasons.** "Not implemented yet" and "deliberately never" are different
  answers and only one of them should be given twice; without the sentence, why a client cannot see the budget
  depends on who is asked. The configuration screen lists them beside what *can* be exposed — the same reason the
  attribution surface shows the models it cannot compute instead of hiding them.
- **The client's intake thread is not a setting.** A client writes half of that conversation and a person is
  entitled to read a conversation they are part of. A switch there would either control nothing or let an agency
  withhold the answer to a question the client themselves asked.
- **`portal.read` — the only thing in this product that says somebody belongs to the client.** Project assignment
  does not: a creative is assigned to a client's project and is plainly not their employee, which is exactly the
  bug a test found here before the permission existed. Precedence is load-bearing, because the owner role holds
  every permission by construction: a reader with `portal.view` is staff previewing, and a reader with
  `portal.read` and without it is the client.
- **An agency preview reads the client's pages through the same code**, flagged `is_preview` in every response.
  Reading a page as the client is the thing to do before switching a module on; a preview built from a different
  query would be a preview of something nobody will see, and an agency member who mistook one for their own view
  would conclude half the product had disappeared.
- **Suspension keeps the grants and closes on the next request.** They are what the portal goes back to and the
  answer to "what could they see while it was on"; clearing them would make resuming an act of reconstruction
  from somebody's memory of a meeting. The reason is required and **internal** — "payment overdue" is a
  commercial conversation and a portal page is the worst place to have it, so the client is told the portal is off
  and to make contact.
- **Exposed-with-nothing-behind-it is a figure on the page**, not something a reader works out by opening each
  client. Every module declares what must exist first, and the screen says so *before* the switch is thrown: an
  empty surface shown to a client reads as the agency having done nothing.
- **Five audit actions, all of them security events** — filed beside "who connected a platform" rather than
  beside "who renamed a report". One entry per module *naming it*, because an entry saying "the set is now
  performance, reports" cannot answer "when did they get the revenue figures"; withdrawal as its own action,
  because "when could they see it" and "when did that stop" are two questions; and nothing written when nothing
  changed, because a trail that logged an unchanged form would bury the five real entries in fifty.
- **The client's own application**, in its own shell: no agency sidebar, no bell, no account menu, and navigation
  **built from what they may open** — so a module that was not exposed is absent rather than refused, and the
  server answers 404 rather than 403 for the same reason. A menu item that returned 403 would tell a client a
  surface about them exists and is being kept from them.
- **A fixed metric set on the client's performance surface**, with reach and frequency absent because neither adds
  up across campaigns, and the comparison period named because a change figure with an unstated baseline is one
  somebody reads against whatever period they had in mind. The period is said to end yesterday, so a client
  comparing it with a platform dashboard that includes today does not conclude the figures are wrong.
- **Attributed revenue is refused through the other door too.** Offering it as a portal revenue source would be
  the disclosure `WithheldModule::Attribution` exists to prevent, arriving by a different route.
- Ten CHECK constraints across two tables, with the module vocabulary generated from the enum.
- `docs/PORTAL.md`.

### Changed

- **`analytics.view` removed from `ClientViewer`.** The point of the phase, and the reason two feature tests
  changed their subject rather than their assertion: a client no longer reads the agency's dashboards or saved
  views at all, and what they may read is decided per client on their own portal. A dashboard is also the
  agency's own composition — which widgets, in which order, against which saved view — and that is a working
  document rather than a report written to be read.
- **`GetStoreAnalytics` publishes its permission so the endpoint can apply it first.** Found by an end-to-end
  test: the commerce endpoint validated its required `revenue_source` before checking the permission, so a reader
  who may not see revenue at all was told which parameter to add. A 422 naming a parameter is a statement that the
  endpoint exists and would answer. The portal's own revenue read has the same ordering, expressed as a domain
  exception raised after the access check.
- **`TrackingHealthComposer` extracted from `GetTrackingHealth`.** The portal needed the same diagnosis under
  different authorization — the agency gates on `tracking.view`, the portal on whether the module was exposed —
  and duplicating the composition would have produced two diagnoses of one site, with the client reading the one
  nobody maintained.
- **`ApiError` carries the response body.** Some of this product's refusals *are* the answer: asking for revenue
  without naming who counted it comes back with the authorities the surface accepts. A caller that could only
  read `message` would have to re-fetch a vocabulary the refusal already handed it.
- **`ReportShared` and `ReportShareRevoked` are now security events.** They were not, and a share link hands a
  client's performance data to whoever holds it — a data-access grant in everything but name.
- Two end-to-end premises moved rather than two assertions weakening. The empty-notification-bell test had used
  the owner (who holds every permission) and then the client (who no longer renders the agency shell); it now
  uses the buyer, who holds the permissions and is deliberately on no project, so the resolver's visibility check
  excludes them from every subject in the workspace.

## Phase 21 — reporting

The one artefact in this product that leaves the building: a saved question, the figures that answer
it today, a frozen copy of the figures that answered it last month, and a link a client can read
without an account.

### The argument

Every other surface here is read by the people who built it. A report is pasted into a deck, quoted
in a meeting the agency is not in, and read six weeks later by somebody deciding whether to renew.
A dashboard that is briefly wrong gets refreshed; **a report that is wrong is a sentence an account
manager has already said out loud.**

So the module spends its effort on the four things a reporting feature usually leaves to the reader:
whether the comparison is arithmetically sound, whether the figures are live or frozen, whether the
period has finished, and who may read it and until when.

### Added

- `app/Domain/Reporting/` — `ReportWindow` (eight cases; **every relative window ends yesterday**,
  because today is still accumulating and a partial last day reads as a drop in performance),
  `ComparisonMode`, `Comparability`, `SectionKind`, `ReportAudience`, `RenderMode`, `ShareState`.
- **Comparability is computed, not assumed.** "Spend is up 40%" is the most quoted sentence an agency
  produces and it is worthless when the periods differ in length. Three refusals with codes —
  `unequal_length`, `period_incomplete`, `overlapping` — and a fourth, `not_requested`, that
  separates *we will not show you a change* from *you did not ask for one*. **When a comparison is
  refused the reason goes where the percentages would have been**, above the sections: a reader who
  finds no comparison and no explanation does the arithmetic themselves, against whatever period
  they pick.
- **It deliberately says nothing about seasonality.** `Comparable` means the arithmetic is sound, not
  that the comparison is meaningful — comparing Ramadan with the month after it is a bad comparison
  and no code can know that. Claiming otherwise is the more dangerous error, because a reader told a
  comparison is valid stops thinking about it.
- **A report is a rule, not two dates.** `last_30_days` still answers its question next month;
  `custom` is the only window carrying dates, and a CHECK enforces both-or-neither.
- **A shape decides which metrics it accepts.** Caps of 6/4/3/8, and **non-additive metrics refused
  in a ranking or a table** — reach counts deduplicated people, so a per-platform reach column is not
  merely wrong, it has no meaning. The builder does not offer it there at all: a form that offers
  what the server rejects teaches people to distrust the form.
- **Only the scorecard carries the comparison.** A ranking's rows are different entities each period,
  so "Meta is up 12%" against a ranking that did not contain Meta last month is a sentence with no
  referent.
- **Snapshots return the stored payload unchanged** — not re-rendered, not merged with today's
  figures. The only way to answer "the report said 1.2 million" months later, when the platform has
  since restated the number. `live` or `snapshot` is on the document, not inferred.
- **Audience is a rewrite, not a filter.** A client-safe report is written to be read by the client —
  in its title, its metrics and what was left out — so a new report defaults to **internal**. The
  internal note is never loaded on a client-safe render, `ShareReport` refuses to share an internal
  report at all, and `ReadSharedReport` passes `ClientSafe` explicitly so no future configuration can
  make the unauthenticated path serve an internal render. Three locks on one door.
- **A link is a credential.** 48 random bytes, stored as SHA-256 plus a 12-character hint, looked up
  by hash; no endpoint returns a token and the interface says so when it shows one. `expires_at` is
  NOT NULL and capped at 90 days — a link with no end outlives the engagement, the account manager
  and sometimes the relationship. Revocation requires a reason, as a CHECK.
- **`ShareState::Expired` is derived, never stored.** A stored flag needs something to write it, and
  whatever that is would be the thing that fails to run — leaving a link expired in principle and
  working in practice.
- **Views are counted, not logged.** A count and a last-viewed time; not who, and not when each time.
  An agency legitimately wants to know whether the client opened it; a log of a client's reading
  habits is data this product has no reason to hold.
- **One unauthenticated read**, `GET /api/shared-reports/{token}`: the token is in the path and
  nothing else is, the route is constrained to `[A-Fa-f0-9]{96}`, it **always answers 200 with a
  state** — a 404 on a link a client was sent reads as a broken site and they ask for it again
  rather than learning it lapsed — and **a token nobody issued gets the same answer as a revoked
  one**, because telling a visitor the difference tells anybody guessing tokens when they have
  guessed one. Its own rate limit, per address, because there is no user to key one to.
- **`/shared-reports/:token`** — the page the link actually opens, outside the shell and outside
  `RequireSession`: no navigation of any kind, because there is nowhere to go and a chrome full of
  controls leading to a sign-in screen would invite a visitor to try them. It renders the state in
  the reader's own language rather than echoing the server's English, and offers no export.
- **The CSV is a document, not a dump** — a header block carrying the period, the source state,
  live-or-frozen and the comparison warning; major units, because a spreadsheet is read by a person;
  an unavailable figure writes its reason rather than a blank, because a blank becomes a zero in the
  next formula; and a cell beginning `=`, `+`, `-` or `@` is prefixed with an apostrophe, because a
  campaign name is attacker-controlled text as far as a spreadsheet is concerned.
- **Sharing is its own permission.** A media buyer may build a report and not send one: a buyer
  reporting on their own performance to a client, with nobody reading it first, is the one review this
  module exists to keep. The interface says why rather than hiding the control.
- Ten CHECK constraints across four tables, with the window, comparison, audience and section-kind
  vocabularies generated from their enums so the database and the domain cannot drift.
- `docs/REPORTING.md`.

### Changed

- `DateRange` **moved into the domain** (`App\Domain\Shared\ValueObject\DateRange`) from the
  analytics application layer, where the reporting domain could not reach it without inverting the
  dependency the architecture tests enforce. Twelve files repointed; it now throws
  `Domain\Shared\Exception\InvalidDateRange`.
- Nothing in this module computes a metric. Every section is one `AnalyticsReadModel` call, so a
  report carries the same figures, source states and unavailability reasons as the analytics surface —
  including `MetricSource::weakest()` rolling up to the section and then to the document. A reporting
  feature with its own aggregation is a second set of numbers to reconcile, and the reconciliation
  always happens in front of a client.

## Phase 20 — operations: client requests

What a client asked for, what was said back, and who promised what — on one surface that serves both
the agency and the client, with the server deciding what each may see.

### The argument

`client_requests.submit` is the only permission in this product that lets somebody outside the agency
write anything, and a client viewer holds it. That is the point: **an intake queue only agency staff
can write to is just another task list.**

The figure the module exists for is a pair — **waiting on us** beside **waiting on them**. A single
"open" count lets a conversation stalled on the client hide behind work the agency has not started,
and only one of those is the agency's to fix.

### Added

- `app/Domain/Operations/` — `ClientRequestState` (including `NeedsInformation`, the state that stops
  a stall hiding), `ClientRequestKind`, `StatedUrgency`, `MessageAudience`, `IntakeLoad`.
- **Internal messages are excluded in SQL for a client-facing reader**, not filtered after loading. A
  row that was selected has been hydrated into a DTO and is one careless refactor from a response.
  The absence of `client_requests.view` is what makes a reader client-facing.
- **The agency's plan of work is withheld too** — the tasks raised to answer a request, the owner's
  name, the name of whoever filed it. `promised_by_name` is deliberately *not* withheld: a promise was
  made *to* the client, so who made it is part of the promise.
- **A promise carries the person who made it**, as a CHECK: `(promised_at IS NULL) = (promised_by IS
  NULL)`. Its own use case and its own audit action, recording **both sides** of a change — an entry
  with only the new date cannot answer "it was the eighth last week" — and writing an entry when a
  promise is withdrawn rather than leaving silence.
- **The client's urgency is not the agency's priority.** `StatedUrgency` is its own enum for exactly
  that reason: one field for both would let a client set an agency's priorities by typing. Nothing
  orders anything by it, every card says *"Client said: …"*, and a task raised from a request takes the
  agency's own priority, defaulted to normal.
- **Raising work links, it does not convert.** The request keeps its state, its promised date and the
  half of the conversation a client can see. A question is refused — it needs an answer, not a card —
  and so is work against a settled request, because reopening is a decision with its own record.
- **Only a client-visible message from the agency counts as answering.** `first_answered_at` is
  stamped once; writing to each other is not answering, and a figure that counted internal notes would
  report a responsive queue while the client heard nothing.
- **No default audience anywhere**, and the send button restates the choice: a control set once and
  forgotten is the one that leaks. A client's own message is forced client-visible by the use case and
  by a CHECK, so an interface bug cannot hide somebody's words from themselves.
- **Delivered work can be reopened.** "This is not what I asked for" is the most important message in
  this module, and a terminal state would make the honest response a second request.
- **Patience is two days**, deliberately shorter than approvals' three: an internal request waiting
  over a long weekend is untidy, and a client waiting that long has been ignored. Past it the page says
  *"That is not a busy queue — it is somebody who has not heard back."*
- `client_requests` and `client_request_messages`, a repository, six use cases, two queries, a
  controller with nine routes, three permissions, five audit actions, and `client_request` added to the
  task and approval subject vocabularies.
- `frontend/src/features/requests/` — one page for both sides, the conversation with every message
  marked by audience, and two charts. Twenty tests, twelve end-to-end.
- Client-requests half of `docs/OPERATIONS.md`, and seven demo requests whose shape is deliberately
  uncomfortable: one ignored for five days, one waiting on the client, one accepted with no promised
  date, one open question.

### Fixed

- **A detail read that ran thirty-six queries, and nothing noticed.** The spawned-task lookup went
  through the task board — six queries by design, one per column — and the request was re-read after
  every write. Now `TaskRepository::forSubject()` (one query) plus an immutable
  `ClientRequestView::withTasks()`, so raising a task re-reads the task list rather than the whole
  request. Four queries over the module's own data, regardless of conversation length, and guarded by
  `ClientRequestQueryBudgetTest` — a **query count**, not a duration: a duration fails on a loaded
  machine and teaches people to re-run it.
- **An audit assertion that passed for the wrong reason.** It ordered two entries written inside the
  same second by timestamp, so it asserted whichever row Postgres returned — and would have kept
  passing with the value it meant to check missing. Entries are now found by the content under
  assertion, and `ClientRequestAuditTest` names the actor, action, subject and workspace on every one.
- **An audit entry that called an internal note the first answer**, contradicting the column it
  described. Found by the test above.
- **The internal owner's name reached the client**, along with the name of whoever filed the request.
  Found by a test written to check exactly that.
- **`?? 'unset'` used to look for a null value** in two assertions, which cannot distinguish an absent
  key from a null one — so the filter matched nothing and the tests failed for a reason unrelated to
  the code.
- **A nav icon nobody registered.** `navigation.test.ts` caught `/client-requests` wanting `Inbox`,
  which is the drift that once left three surfaces sharing one icon.
- **Two Playwright projects colliding on one seeded database.** A fixed task title was created twice
  and a strict locator then resolved to two buttons — a shared-fixture collision that reads as a
  product defect. Titles are now named per project and per retry, like the suite's sign-in helpers.
- **A stale test premise my own change invalidated**: the owner's notification centre is no longer
  empty, because a client now raises a request in the demo and the owner is correctly told. The
  empty-state test moved to the media buyer, who holds the permission and is on no project — so the
  recipient resolver excludes them, which is the rule working.
- **A Pest helper name collision** (`budgetScenario`) that was fatal in the full suite and invisible
  when the file ran alone.

### Changed

- **A creative no longer sees the intake queue.** Their work arrives as a brief and as tasks; the queue
  carries every client's commercial asks and the agency's reasoning about them, which is wider than the
  role works from.

## Phase 20 — operations: notifications and realtime

Four kinds of notification, a bell that counts what is waiting rather than what is unread, and a
WebSocket layer whose payload deliberately carries nothing worth reading.

### The argument

A notification is a **disclosure**. "Raise the Ramadan daily budget is waiting for your decision"
names a client, a campaign and an intention. So the rule is the same one every read in this product
follows: a person may be told about something only if they could have read it.

And a notification centre that announces every state change teaches people to ignore the bell — after
which the one notification that mattered, an approval for a change to live spend, arrives in a stream
nobody opens.

### Added

- `app/Domain/Notifications/` — `NotificationKind` (four kinds, each declaring the permission its
  recipient must hold), `NotificationSubject`, `NotificationDigest`.
- **The audience is resolved by rule, never by a list of user ids.**
  `PermissionAwareRecipientResolver` checks three things in SQL: active membership of the workspace,
  the permission the kind requires **plus the second one the kind names**, and visibility of what it
  is about. A spend change reaches only the people who hold `budgets.approve` — telling five people
  when three cannot approve it is how a bell comes to be ignored, and it invites somebody to a
  decision the server refuses.
- **Nobody is told what they just did.** `Announce` drops the actor before writing. Unassigning tells
  nobody: there is no new owner, and notifying the person it was taken from is a complaint rather than
  a task.
- **Announcing never fails the action it describes**, like the audit recorder. An approval recorded
  whose notification threw would leave a decision made, a requester never told, and a caller shown a
  failure for something that did happen.
- **`waiting`, not `unread`, is the number on the bell.** Unread measures how long somebody has been
  away; waiting is a to-do list. Being told an answer is unread without being waiting. The bell shows
  no badge when nothing is waiting and a plain dot — not a count — for the merely unread, because a
  dot that is always there is a dot nobody reads.
- **One row per recipient**, fanned out in one insert, because a row with a list of recipients cannot
  record that one of them has read it. Deliberately not Laravel's own table, which is polymorphic,
  untyped and has no workspace column.
- **The sentence is stored as announced**, never re-rendered. A notification about something since
  deleted keeps its wording and loses only its link.
- **Reading needs no permission, and that is correct**: rows are scoped to the reader's own id and the
  permission was checked when the notification was written. Gating the read again would make a
  notification somebody was already sent disappear when they lost a permission.
- Marking read is scoped by workspace **and** user in the WHERE clause; somebody else's row answers
  **404, not 403**, because "forbidden" would confirm it exists.
- **One private channel per person per workspace.** `ChannelAuthorizer` re-checks identity and active
  membership on every subscription, so a socket cannot outlive the access that opened it.
  `/broadcasting/auth` now carries `web` and `auth` middleware.
- **The socket payload is a signal, not the notification** — id, kind, needs-action, timestamp, and
  nothing a person would read. The browser refetches over the authenticated endpoint. One extra
  request buys the property that a notification's contents never travel over a socket.
- `notifications` table, store, resolver, broadcaster, `Announce`, three more use cases, a controller
  with four routes, and announcements raised from `OpenTask`, `AssignTask`, `RequestApproval`,
  `DecideApproval` and `RecordEnactment`.
- `frontend/src/shared/realtime/echo.ts` — Echo and Pusher as **dynamic imports**, 73 kB a build that
  never connects never pays for. The notification centre and the bell, with fourteen tests.
- Notifications half of `docs/OPERATIONS.md`, including the testing trap below.

### Fixed

- **"Live" claimed on one side's evidence.** A checkout with `BROADCAST_CONNECTION=reverb` and no app
  key told every reader the page was live while nothing was subscribed — the exact failure the message
  warns about, caught by the end-to-end suite. Fixed at the source twice: the server's
  `isConfigured()` now requires a key rather than a connection name, and the page requires the
  server's answer **and** its own browser having a key.
- **A set of channel tests that passed while asserting nothing.** `Broadcast::channel()` registers on
  whichever broadcaster was default when the channels file loaded, and neither the `null` nor the
  `log` driver consults channel callbacks — so an unregistered channel and a denied one produce the
  same 403. The file now re-requires the channels against the test driver, and the test expecting a
  subscription to **succeed** is what proves the rule is wired.
- **Opening a task already assigned told nobody.** Only re-assignment announced, which meant the
  commonest way work gets handed over was the one with no notification.
- Two `npm audit` criticals that arrived with `laravel-echo`'s transitive dependencies, resolved.

### Not done

- No digest, grouping or collapsing — that is where a notification centre starts deciding what
  somebody has already seen, and being wrong about it.
- No preferences: four kinds that each need action is not a stream worth filtering, and the screen
  would mostly be an invitation to switch off the approval notifications.
- No e-mail or push delivery, and no realtime on the boards themselves — pushing a board means
  reconciling a server-aggregated figure with a local mutation, which done badly produces drifting
  counts.

## Phase 20 — operations: the approvals engine

Who asked to change what, who said yes, and — the part nothing else in this space records — whether
anybody ever did it.

### The argument

**Approval is not execution.** This product does not write to the advertising platforms; those
adapters are a later phase. So approving a request to pause a campaign pauses nothing: a person goes
and does it in the platform afterwards. Every tool in this space treats the approval as the end of
the story, which is how an agency tells a client a campaign was stopped on Tuesday and learns from
the invoice that it ran all week.

This module cannot close that gap by acting. It refuses to hide it.

### Added

- `app/Domain/Operations/` — `ApprovalKind`, `ApprovalState`, `EnactmentState`, `ApprovalAge`,
  `ApprovalLoad`, `ProposalFingerprint`, and three exceptions that each name a thing an approval
  trail must not be asked to record.
- **Every kind says it is not carried out here, and why.** `isEnactedByThisProduct()` is false for
  all six, with a per-kind sentence naming what is missing rather than "not supported". A unit test
  asserts it for every case, so a write adapter landing is a deliberate change to that test rather
  than a silent one.
- **An approved change waits on a person.** It moves to `awaiting_hand`, and only somebody recording
  *"I have made this change"* moves it to carried-out, with their name and the time. The audit entry
  carries `verified_by_product: false`, because an audit of live spend has to tell somebody's word
  from a reading off a platform — and this product only has the former.
- **The queue publishes how much approved work nobody has carried out**, and the age of the oldest.
  Not a process metric: a list of things somebody currently believes that may not be true.
- **A decision names the proposal it read.** `ProposalFingerprint` hashes a canonical form — keys
  sorted at every depth, list order preserved — and a decision must send back the fingerprint its
  screen displayed. A mismatch is **409 with the proposal as it stands**, not 422: the request was
  correct when its sender read the screen, and only a re-read resolves it. Without this the race is
  silent and the record claims somebody approved a change they never saw.
- **Amendment is allowed only while pending**, which is what makes that check real rather than
  theoretical. Nothing settled is ever re-decided: a changed proposal is a new request naming the one
  it replaces, and both decisions stay on the record — the rule an approved budget already follows.
- **Some kinds need a second permission.** A spend change or a launch needs `budgets.approve` on top
  of `approvals.decide`, so the person who signs off what a client spends is the person who signs off
  changing it. **Pausing needs none**: stopping spend is the safe direction, and requiring a budget
  approver to halt a runaway campaign is how it keeps running over a weekend.
- **Asking and deciding are separate permissions.** A media buyer may ask and may not decide — a
  buyer who could approve their own change to a client's spend removes the only check on it. They do
  record having made the change, because they are the person who goes and makes it.
- Seven schema CHECKs: a rationale is required, a decline and a withdrawal each carry their own
  reason, a decided state requires both a timestamp and a fingerprint in both directions, enactment
  cannot exist without approval, a carried-out change records when, and an abandoned one says why.
- `approval_requests` table, repository, five use cases, two queries, `ApprovalController`,
  `ApprovalResource`, eight routes, three permissions, five audit actions.
- `frontend/src/features/approvals/` — two queues side by side, the detail panel that says *before*
  the buttons that approving will not make the change, the proposal shown field by field with its
  version, and two charts. Fifteen tests, seven end-to-end.
- Approvals half of `docs/OPERATIONS.md`, and five demo requests — one approved and carried out, one
  approved and still waiting nine days, because a queue where everything was done the same day
  demonstrates nothing.

### Changed

- The demo workspace now **assigns one account to a project**. Nobody was assigned to anything, so
  four of the five demo accounts saw empty screens and assignment-based visibility — which every
  project-scoped surface in this product enforces — could not be demonstrated at all. The media buyer
  is left deliberately unassigned, because the other half of that rule matters just as much and the
  end-to-end suite asserts both halves.

### Not done

- Raising a request from the interface. The API accepts one and the demo seeds five; a generic
  key-value form would invite exactly the half-stated proposal this module refuses, so it waits for
  per-kind fields worth asking for.
- Notifications, which is what an approval waiting for a decision is for, and the next slice.
- Provider writes, which are what would make an approval enactable. Until then this engine is the
  gate they will pass through, and it says so on every request.

## Phase 20 — operations: the board

Tasks, their state machine, and the figures a work board usually hides. Approvals, client requests,
notifications and realtime are the rest of this phase and are not in this slice; `docs/OPERATIONS.md` lists
what is missing.

### The argument

A work board is the easiest surface in a product to make dishonest, because the dishonest version is also
the tidier one. "Three overdue" beside neat columns reads as a team in control; it is frequently a team with
forty tasks nobody has dated, where three describes the small minority of work that *could* be late.

So this slice publishes the counts a board hides and refuses the arithmetic a board fudges.

### Added

- `app/Domain/Operations/` — `TaskState` with its legal moves as data, `TaskPriority`, `DueHorizon`,
  `TaskSubjectType`, `TaskWorkload`, and the two refusals they carry.
- **Undated work is its own bucket, never "later".** `DueHorizon` has five cases and the fifth is the point:
  work with no due date is work that *cannot be late*, which is a different fact. Folding it into "later" is
  how a board reports a pipeline it does not have. It is drawn as its own bar and counted in its own figure
  on the page, beside the overdue one.
- **Only open work is overdue.** Work finished a week late is history, not outstanding; counting it leaves a
  figure nobody can ever clear.
- **The overdue figure says when it cannot be read as lateness.** Once less than 80% of the open board
  carries a date, `TaskWorkload::isLatenessReportable()` is false and the page says so above the chart and
  outside its frame, so the sentence survives the switch to the table view. The threshold is a product
  decision with its reasoning beside it, the same judgement attribution coverage makes at 70%.
- **A completion rate over settled work, not over the board.** Done plus cancelled is the denominator: a
  team that has just taken on fifty tasks has not got worse at finishing things, and dividing by everything
  would say it had. Null, never zero, when nothing has settled.
- **Blocked does not lead to done**, and the refusal is named rather than generic, because it is the move
  people actually try. The shortcut loses both the fact that somebody did the work and any way to measure
  what the block cost. The message lists where the card can go.
- **Five schema CHECKs**: blocked needs a reason, cancelled needs its own, `(state = 'done') =
  (completed_at IS NOT NULL)` in both directions, `due_on` is a `date` rather than a timestamp, and a
  subject link is whole or absent.
- **A task's subject is a link, never a claim.** Tasks point at a creative request, lead, campaign, budget
  or project without a foreign key — `TaskSubjectType` states the trade — so the read model resolves every
  subject on every read and the interface says *"this has been deleted"* rather than rendering a dead link
  as a live one.
- `tasks` table, repository, four use cases (`OpenTask`, `MoveTask`, `AssignTask`, `UpdateTask`), two
  queries, `TaskController`, `TaskResource`, seven routes.
- `tasks.view` / `tasks.manage`, granted to owners, administrators, project managers, media buyers and
  creatives. **Not to a client viewer**: an agency's reasons for work being blocked are an internal
  conversation, and what a client may see is Phase 22's portal rather than a role that happens to open a
  screen.
- Four audit actions, with assignment as its own rather than part of an update — "who was asked to do this"
  is the entry people go looking for, and a generic "updated" buries it.
- `frontend/src/features/operations/` — the board, the detail panel, a composer, and two charts: the due
  profile and the per-person load. Fifteen tests.
- `docs/OPERATIONS.md`, and seven demo tasks whose shape is deliberately untidy — one overdue, one blocked
  with its reason, two undated, one unowned — because a demo of tidy work demonstrates none of this.

### Changed

- **Cards and counts come from different queries.** A column publishes `total` (the whole column, in SQL)
  beside `tasks` (the capped page on screen). Counting the rows that came back would be one query fewer and
  a wrong number the day a client got busy.
- `assignee_id=me` and `=unassigned` are resolved on the server. A front end sending its own idea of who the
  actor is would be one session-restore defect away from showing somebody else's week.
- A `PATCH` with an empty `due_on` **clears** it. A board where a deadline can be set but never removed
  accumulates dates nobody agreed to, and then the overdue figure is noise.

### Fixed

- **Two timeouts that made the frontend suite flaky under load**, not two defects in the code they caught.
  Vitest's 5s per-test ceiling and Testing Library's 1s `findBy` ceiling are both spent largely on building
  one of thirty-odd parallel jsdom environments, so a test would pass alone and fail in the full run —
  blaming whatever was touched last. Raised to 15s and 5s with the reasoning recorded in place. Both are
  waiting budgets, not assertions: nothing in this suite asserts how fast a render is, so neither ceiling
  can hide a regression, and a genuinely hung test still fails. Five consecutive full runs clean.

## Phase 19 — creative operations

Briefs, versions, approvals, comments, and the checking of a file against what a platform asks for. The
phase began with research and the research is what shaped it.

### The finding

**The advertising platforms do not publish their creative specifications in any form a program can read.**
Established rather than assumed: Meta's Marketing API reference states that limits exist and points at a
marketing page rendered by JavaScript, whose own hub does not carry the numbers either; TikTok's business
portal is a JavaScript shell with nothing in the fetched document; the third-party guides that aggregate
these numbers disagree with each other and go stale silently. **Snapchat is the exception** — its developer
documentation states concrete numbers, and those are recorded.

So a specification in this product is **data a person recorded, with the page they read it on and the date
they read it**, and most placements have none. Saying so is the feature: the list of placements with no
recorded requirement is the list an agency's work goes out on unchecked.

### Added

- `app/Domain/Creative/` — `CreativeState` with its legal moves as data, `SpecVerdict`, `SpecRequirement`,
  `SpecBound`, `SpecProvenance`, `AssetMeasurements`, `CreativeSpec`, `SpecReport`, `ApprovalOutcome`,
  `CommentVisibility` and `AssetKind`.
- **Five verdicts, and only one of them is "valid".** `pass`, `fail`, `unchecked`, `no_spec`, `stale`. An
  asset's overall verdict is the **weakest** of its checks, because eleven passes and one unmeasured
  dimension is an unchecked asset and a green tick over a set containing an unknown is how a creative tool
  misleads somebody until the platform rejects the upload. **Only a measured failure blocks a publish** —
  blocking on this product's own gaps would teach people to click past warnings.
- **Specifications go stale and say so.** Past six months a pass becomes `stale` and a failure stays a
  failure: an asset inside a requirement nobody has re-read since last year is not evidence that today's
  platform agrees, while an asset outside one is still worth looking at.
- **Bounds say what the platform said** — "at least", "at most", "one of", "between" — because flattening
  them into one shape would mean inventing a maximum nobody published, and an invented maximum rejects files
  the platform accepts. An empty bound is legal and reports `unchecked`.
- **An approval belongs to a version.** `creative_approvals` keys on the version and there is deliberately no
  approval column on the request. A new version onto approved work sends it back to review and the earlier
  approval stays attached to the file it was about; `newest_is_approved` makes a stale "approved" state
  visible. Decisions are appended rather than replaced, so a file that was once rejected still shows it.
- **Sending work back must say why**, enforced in the use case, the state machine and a database CHECK.
- **A comment states its audience**, with no default in the DTO or the column, and the client-facing filter
  lives in the query rather than in the caller.
- `GET /api/creative/vocabulary` so no board carries its own copy of the legal moves; `GET /api/creative/specs`
  so an agency can see exactly what is covered; the board, the request, the state change, versions, comments,
  and the decision endpoint **under the version** rather than the request.
- **The Creative Hub** (`features/creative`): a read-only board, a request detail showing both numbers for
  every check and the page each requirement came from, the stale-approval warning, an explicit comment
  audience, and a workload chart on the ordinal ramp — because the columns are stages of one process, so the
  order belongs in the colour.
- 24 domain tests, 24 feature tests, 10 page tests, 7 end-to-end tests, and `docs/CREATIVE.md`.
- Demo seeding: five briefs covering an approval, a rejection with its reason, a passing check, a failing
  check, a placement with no recorded specification, and a brief with no placement at all.

### Fixed

- **The test suite died partway through with a fatal error naming a vendor file.** The architecture tests
  parse every source file, so the suite's memory need grows with the codebase: 512M carried it to roughly
  1,125 tests and was exhausted at about 1,150, in the Analytics suite rather than in whatever had grown.
  Raised to 1G, with the reasoning recorded — the failure mode reads as a broken test, which is what makes the
  headroom worth having.

### Decisions worth recording

- **Specifications are not workspace-scoped and are read-only through the API.** They describe a platform
  rather than a client, so one recording serves every tenant — and a workspace able to edit them would be
  editing every other workspace's checks.
- **The board cannot approve or cancel.** An approval needs a version and a decider and a cancellation needs a
  reason; a column drag has neither, and the trail would record that somebody approved something without
  saying who approved what.
- **A requirement an asset kind cannot have is not reported as unchecked.** A duration check on an image is
  inapplicable, and filling a review with warnings nobody can act on is how reviewers learn to ignore them.
- **Every write re-reads through the query a GET uses**, so a POST answers with exactly the shape a read does.
  A write response missing the `spec` block would work until somebody relied on the field.

## Phase 18 — attribution

The phase that turns "somebody bought something" into "this campaign earned the money" — the most
quotable claim this product makes, and the easiest one to make indefensibly. So most of what landed
here is a refusal.

### Added

- `app/Domain/Attribution/` — `AttributionModel`, `AttributionRequirement`, `AttributionCoverage` and
  `CampaignLink`. One member of the model enum has `isModelled() === false`, and only one: `last_recorded`
  reports the campaign the order arrived carrying, which is a record. Everything else is an opinion, and
  every surface showing one is obliged to say so.
- **Three models declared and refused.** Linear, position-based and time-decay need the sequence of
  marketing touches before a conversion, which this product does not hold — the same finding Phase 16
  recorded about journeys. Asking for one returns a 422 naming what is missing and what would unblock it
  (a GA4 → BigQuery export, or first-party tracking). They are declared rather than omitted because an
  absent option gets asked for again every quarter, and because computing something shaped like time-decay
  from a single recorded touch would produce a number named after a model it is not.
- **Coverage is a required part of every answer**, built from the same scan as the rows. It publishes the
  unattributed revenue as its own figure, both coverage rates, the share of attributed conversions that
  name a campaign rather than a channel, the **weakest** evidence in the set, and a revenue-weighted
  confidence. The reportable threshold is 70% rather than 90%, because direct traffic, returning customers
  and messaging apps that strip parameters land in the unattributed bucket legitimately and a threshold
  nobody can reach is one everybody learns to ignore.
- **Spend joined to attributed revenue, with the join's own honesty.** `CampaignLink` records how firmly
  each row ties to an ad campaign, and only an exact campaign-name match carries spend — case and
  surrounding space folded, nothing looser, because `autumn-range` and `autumn-range-v2` are two campaigns.
  ROAS is null wherever it would be meaningless: no spend, zero spend, a link that does not join spend, or
  two different currencies. Spend the orders never mentioned is reported rather than dropped.
- **A comparison endpoint**, which is the honest surface. A model selector teaches a reader that one
  option is right; running all of them and reporting where they disagree teaches the truth. A
  disagreement is material above a fifth of the larger figure, and a campaign absent from a model's rows
  is a zero when that model's breakdown was not truncated and null when it was — the first is a real
  disagreement, the second would be a pagination artefact reported as one.
- `GET /api/attribution/models`, `GET /api/attribution`, `GET /api/attribution/comparison`, 19 unit tests,
  25 feature tests, and `docs/ATTRIBUTION.md`.
- **The attribution surface** (`features/attribution`), which leads with coverage rather than with the
  campaign rows: an attributed figure read as a total is the specific way this page could mislead. A
  meter rather than a chart for that — one ratio against one limit — amber below the threshold, with the
  sentence that says to treat the rows as a sample. The selector shows the models that cannot be
  computed, disabled and explained. The campaign chart greys the rows that cannot carry spend, and the
  comparison is grouped rather than stacked, with its headline outside the frame so it survives the
  switch to the table view.
- **The demo workspace now syncs a sandbox Salla storefront**, so commerce and attribution have orders.
  What it demonstrates is the failure rather than the success: one campaign matched to real sandbox spend
  with a ROAS of 0.21, one coupon-only order naming a campaign nobody connected, and most of the sandbox's
  spend unmatched — roughly what a real account looks like before anybody fixes its tagging, and it
  exercises every branch of the join rather than the flattering one.

### Fixed

- **No sandbox order was both paid and carrying campaign parameters**, so the Salla fixtures never
  exercised attributed revenue at all — the figure this whole phase is built on. The existing attributed
  order is cash on delivery and correctly maps to pending, which is a realistic case worth keeping, so a
  paid order carrying marketing parameters was added beside it.

### Decisions worth recording

- **Every model runs over one scan of the same rows and groups in PHP.** Aggregating each model in its own
  SQL statement would be faster and would mean a disagreement between two models could come from the two
  queries differing rather than from the models differing — which is exactly what the comparison surface
  exists to measure. The cost is a row-level read bounded by the date range; a materialised attribution
  table is the answer when that stops being acceptable, and it would keep the same grouping code.
- **`first_recorded` is a model of the person's earliest recorded *conversion*, not their first ad.** For a
  customer who enquired through one campaign and bought through another it credits the enquiry — a
  defensible view of what began the relationship, and not the same claim as "first touch" usually means.
  The lookback window is published with the answer because a different one credits different campaigns.
- **A first-time customer is credited with what their own order carried.** A first-recorded model that
  reported nothing for them would understate every campaign that wins new business, which is most of what
  an agency is paid for.
- **No separate permission.** `analytics.view`, because attribution is a view of money already readable
  through analytics and commerce, and a separate permission would let somebody be granted the modelled
  view and denied the recorded one.

## Phase 17 — the visualisation layer

Charts are the part of this product most able to mislead and least likely to be reviewed for it. This
phase made the measurable parts measurable, and shipped the two surfaces that were waiting on them.

### Fixed

- **The dark chart palette never applied.** A second `:root` block at the end of `tokens.css` redefined
  `--ds-chart-1…8` after `[data-theme='dark']` at equal specificity, so the lifted dark series colours were
  dead code and dark mode drew light-mode marks on a graphite card.
- **The palette that did apply was unsafe.** Measured against a white surface it failed three gates: two slots
  below the chroma floor, a worst adjacent pair at ΔE 5.2 under deuteranopia against a gate of 8, and ΔE 13.4
  under ordinary vision against a floor of 15 — gold beside green and aqua beside green, which a tenth of male
  readers could not have separated. A comment claimed the first three slots were colour-blind safe; nobody had
  measured it.
- **The analytics trend chart was dual-axis.** Where a second y-scale lines up with the first is arbitrary, so
  the two lines appeared to cross and diverge at moments that meant nothing — a chart that invents a correlation,
  under a heading inviting a budget decision.
- **The Lead Center summed money across currencies** and published no currency at all, so a workspace with
  Saudi and Emirati leads received a confident figure made of two different units. Totals are now withheld with
  the reason stated; the counts are untouched, because a lead is a lead whatever it was priced in.
- **Three surfaces shared the overview icon** in the sidebar, because an unregistered icon name falls back
  silently. Registered, and asserted.
- **`formatPercent` was being handed rates out of 100.** A `formatRate` helper now takes the shape the API
  actually publishes, and the names say which is which.

### Added

- `shared/ui/chart/colour.ts` — OKLCH→sRGB, OKLab distance, and the Machado 2009 deficiency transforms. ECharts
  parses colours to lift a hue on hover with a parser that predates OKLCH, so every token is resolved to hex
  before it reaches a canvas. The same module the gates measure with, so the documented numbers come from the
  code that draws the charts.
- `palette.gates.test.ts` — 24 assertions over the palette as `tokens.css` declares it, including the measured
  four-series cap for scatter and small multiples.
- `shared/ui/chart/marks.ts` and `marks.test.ts` — the mark specs in one place, with tests for the rules whose
  wrong version looks normal: no smoothing, no bridged gaps, no dashed gridlines, a surface gap instead of a
  border, and mirroring by inverting an axis rather than reversing data.
- `ChartFrame` and `ChartTable` — the source badge, the caveats, and a chart/table switch in the open.
- **The Lead Center** (`features/leads`) — the four-step funnel on the ordinal ramp, taps in their own chart with
  their own axis and no ratio between them, breakdowns by route and campaign that keep the unattributed row and
  fold the tail into a stated "other", and a pipeline table whose time-to-first-contact column shows "not yet"
  rather than zero hours.
- **The journey timeline**, inside the Lead Center — a timeline rather than a chart, which is the honest form
  for two or three records, with the identity evidence, the single-record statement and the response's own
  limitations rendered as data.
- Demo seeding for both: 17 leads through the real `RecordLead` and `ChangeLeadStage` use cases, and a sandbox
  GA4 property synced for the tap counts, so the surface demonstrates its refusals instead of showing four empty
  states.
- **A chart in the Tracking Center** answering the page's only real question — is any of this
  working — with three states kept apart: a count, a never-seen zero sorted to the top because it is
  the finding, and a null drawn as a gap because "we did not look" and "we looked and found nothing"
  are opposite statements.
- **One retry for a request that got no response at all**, in `shared/api/client.ts`. A request that
  came back with a status got an answer and is never retried; one that came back with nothing may have
  been unlucky, and "the server could not be reached" is a conclusion a single failed connection does
  not support. GET and HEAD by default, plus signing in, which is the one write in this product that
  can be repeated without repeating its effect.
- **A table twin for every chart whose figures appear nowhere else** — the analytics trend, the
  commerce trend, the overview trend, every Lead Center chart and the Tracking Center's. The two
  analytics charts without one plot values the breakdown table at the foot of that page already
  holds, by the same dimension.
- `docs/DATA_VISUALISATION.md`, with the design system's chart section rewritten around it.

### The end-to-end suite — and the API defect behind it

Five fixes. The last one is a real product defect that two previous investigations of the same
error had attributed to something else; it was found by making the test harness report what the
browser saw instead of what the server's log claimed.

- **`POST /api/auth/login` answered an already-authenticated request with a 302 to a web route**,
  because it carried Laravel's `guest` middleware. Wrong twice over for an API: a client is owed a
  status and a body it can act on, and the redirect's target is absolute against `APP_URL`, so for
  a single-page app served from another origin the browser blocks the redirect outright and the only
  thing the application can honestly report is that the server could not be reached. Signing in
  while already signed in is a legitimate act — somebody switching accounts — it still requires the
  second account's password, and the authenticator regenerates the session on every successful
  attempt, so the middleware was protecting nothing. Removed, with tests for the switch, for a wrong
  password with a session already open, and for the absence of a redirect status.

- **The API proxy must not keep connections alive to `php -S`.** Node has pooled proxy
  connections by default since 19; the development server binds a kept-alive connection to one
  forked worker and closes it on its own schedule, so Node eventually picks a dead socket, the
  proxy returns nothing, and the application correctly reports that the server could not be
  reached. It appeared only under WebKit, which reuses more connections — which is why one browser
  project failed and the other did not, and why the failures moved between projects run to run. The
  suite went from 148 passed / 1 failed / 1 flaky in roughly 25 minutes to **162 passed in 6
  minutes with no hard failures**.
- **Every name a test creates now carries the attempt number.** The records the suite creates are
  unique per client and period, which the server enforces, so a test that failed *after* saving
  could never pass on retry: the second attempt's save was correctly refused, the form stayed open,
  and the test timed out on a control that never appeared. That turned every flake into a hard
  failure whose message described the retry rather than the original fault, and it hid the proxy
  problem for weeks.
- **The sign-in helper now reports what the browser saw** — every auth response status and every
  failed request — when it gives up. Two separate investigations of this error reached the wrong
  conclusion by reading `artisan serve`'s own log, which under forked workers mis-pairs timings and
  reported a bcrypt verification as taking 0.02ms. The first run with the diagnostic in place named
  the cause in one line.
- **A separate TypeScript project for `e2e`.** While it sat inside the application's project it
  inherited `vitest/globals`, so a Playwright spec could reference Vitest's `expect` or `describe`,
  typecheck cleanly, and fail at run time with an error pointing at the assertion rather than the
  import that was never made.
- `tests/Feature/System/CorsTest.php` — the API's preflight handling, which nothing asserted. An
  API that does not answer a preflight looks to a browser exactly like an API that is down, and
  neither message mentions CORS.
- **The conversion editor no longer picks a client for you.** It defaulted to whichever client
  sorted first, which is a consequential choice made on somebody's behalf — a conversion definition
  decides what that client's reports count. The default also failed silently: the draft is
  initialised once, so when the client list arrived after the form opened the preselection did not
  happen and the save button stayed disabled with nothing on screen explaining why. Found by a test
  that waited 45 seconds for a button that was never going to enable.

### Decisions worth recording

- **The funnel has four steps, and the fourth is not "qualified".** Each step is a genuine superset of the next.
  The available qualified figure is a current-stage count, and a lead since won passed through it uncounted, so
  placing it between "got through" and "won" would draw a funnel that narrows and then widens — from which a
  reader would reasonably conclude leads were being won without qualification.
- **The dark interface accent sits above the band a chart mark needs.** `--ds-brand-400` at L 0.706 is lighter
  than the dark chart band allows, which is why chart slot 1 and `--ds-accent` are not one value in dark mode.
  Found by the search, not by eye.
- **Scatter charts cap at four series.** There is no assignment order in a scatter, so every pair has to clear
  the gate rather than only the neighbours. Past the cap `seriesColour` repeats a slot, so the mistake is visible
  rather than a generated ninth hue that looks plausible and is indistinguishable under colour blindness.
- **No currency conversion.** A converted total needs a rate, a date for that rate, and an owner for the
  decision; picking one silently would be inventing the most quotable number on the page.

## Phase 16 — customer journeys

Three of the four things this phase asked for are delivered. The fourth is blocked by the data rather than by
the work, and naming that precisely is the most useful outcome here.

### The blocker, named

**Multi-touch customer journeys are not buildable from the data this product has.** Google Analytics' Data API
returns aggregated reports — tables of dimensions and metrics — not per-user event streams, and there is no
user-level identifier available through it. The documented route to event-level data is a **BigQuery export**,
a separate integration this product does not read.

So the clicks, sessions, page views and ad impressions before a conversion are **absent rather than
nonexistent**. What would unblock it: a GA4 → BigQuery export (needs a Cloud project with billing and costs
money per query, which makes it a decision for whoever pays) or first-party tracking (larger, and brings consent
obligations). Both are recorded in `docs/JOURNEYS.md` rather than half-built.

### Added

- `app/Domain/Journeys/` — `IdentityEvidence`, `TouchpointKind`, `Touchpoint` and `Journey`.
- `JourneyReadModel` and its Eloquent implementation, `GetJourney`, `JourneyResource`,
  `GET /api/leads/{lead}/journey`, `docs/JOURNEYS.md` and 16 tests.
- `email_key` and `phone_key` on `store_customers`, so a person can be recognised across surfaces.
- `ContactPoint` moved to `App\Domain\Shared\ValueObject`, because recognising a person is the same problem
  whether the record is an enquiry or an order — and two modules normalising a phone number differently would be
  two modules that disagree about how many customers a client has.

### Fixed

- **The journey lookup was not visibility-scoped.** An out-of-reach lead answered with an empty journey rather
  than a 404, which confirms the id exists — itself across the tenant boundary. Now checked on the lead lookup
  as well as inside the contact resolution, because the second is reachable on its own.

### Decisions worth recording

- **A single record is never drawn as a journey.** `is_multi_touch` exists so an interface says "one enquiry,
  nothing else recorded" rather than rendering a one-step funnel — which reads as a *complete picture* and gets
  acted on as one: a reader concludes the customer appeared from nowhere, or that the channel deserves no credit.
  `span_in_days` is null rather than zero for the same reason, and a zero would be averaged into a cycle time as
  though the customer had bought instantly.
- **The journey has no attribution of its own.** Each touchpoint keeps what it carried, and no touchpoint
  inherits another's. Deciding which touch earned the credit is a model — first-touch, last-touch, linear and
  time-decay disagree by design — and a model presented as a fact is what this product refuses. The response
  offers `earliest_attributed_touchpoint` as a fact about *that record*, and a test asserts there is no
  journey-level campaign field.
- **`IdentityEvidence` mirrors the attribution discipline**, because deciding two records are one person changes
  what a client is told about their funnel. The reliability line is drawn **above** the shared click identifier:
  it is strong about the click and weak about the person, since a shared device or a forwarded link puts one
  person's click on another's order. Worth showing to somebody who can judge it; not worth counting in a funnel.
- **Matching is exact and never on a name.** Two people called Mohammed Al-Harbi are two people. The cost of a
  missed link is one unlinked journey; the cost of a wrong link is a record nobody can disentangle afterwards.
- **The platform's own customer link outranks anything this product infers.** The shop made it — the customer
  signed in, or the platform matched them itself.
- **Resolved on read, never stored.** A stored identity graph goes stale the moment the next order lands and
  nobody dares rebuild it; resolving on read means the answer is always made of rows a person can go and look at.
- **Existing store customers are not backfilled with normalised keys.** A half-normalised key would match some
  records and not others, which is worse than none — the next sync computes them properly, and until then an
  unlinked journey is honestly unlinked rather than inconsistently linked.
- **The limitations are in the response, not only in the documentation**, and are assembled per journey: the
  person reading the page is the one who would otherwise draw the wrong conclusion.

### Not yet in place

- **Multi-touch touchpoints**, as above. This is the phase's honest ceiling.
- **No Journey Explorer surface.** Worth building once there is more than one kind of touchpoint to show.
- **No manual merge** of two records a human knows are one person.

## Phase 15 — lead intelligence

Built around one sentence: **a lead is a person who did something, and a click is not a lead.**

### Added

- `app/Domain/Leads/` — `LeadStage`, `LeadSourceKind`, `LeadFunnel`, `ContactPoint` and
  `InvalidLeadTransition`.
- `leads` and `lead_events` tables, `EloquentLeadRepository`, `EloquentLeadReadModel`, `RecordLead`,
  `ChangeLeadStage`, `ListLeads`, `GetLeadCentre`, four endpoints, `leads.view` / `leads.manage`, three audit
  actions, `docs/LEADS.md` and 47 tests.

### Decisions worth recording

- **`LeadSourceKind` has no member for a click, a tap or a form view.** Every member is a completed action by a
  person — a form submitted, a message received, a call answered, an order placed — so no value exists to pass
  that would turn a counted signal into a lead. The rule is enforced by absence rather than by a check somebody
  could remove, and there is a test asserting the absence. An agency reporting WhatsApp clicks as leads reports
  a cost per lead a fraction of the real one, wins the next month's budget on it, and cannot explain afterwards
  why the client's sales did not move.
- **Signals sit beside the leads and are never summed with them, and no ratio between the two is published.** A
  tap and a lead do not share a denominator: the same person taps three times, taps and then phones instead, or
  taps on a device the form never loaded on. Four hundred taps and one conversation is a real shape for a month,
  and showing both answers the question an agency has while making the thing it must never do impossible to do
  by accident. The response carries a note instead of a rate.
- **Every rate's denominator is the defensible one, and every rate is null rather than zero when empty.** Wins
  divide by *closed* leads — an open lead is not a loss, and a pipeline a fortnight old is mostly open.
  Qualification divides by leads somebody *reached* — a lead nobody has phoned has not failed to qualify. `0%`
  and "nothing to measure" look identical on a card and mean opposite things.
- **There is deliberately no lead-to-session rate.** Sessions come from an analytics property and leads from a
  form; dividing one by the other assumes every session had the chance to convert. That comparison belongs where
  both numbers' sources are named.
- **`contacted` is an attempt, not contact.** Three voicemails are not contact, so `first_contacted_at` is set
  when a lead reaches a stage that means somebody got through. That is what makes `hours_to_first_contact` the
  figure that distinguishes a bad campaign from a slow sales team.
- **A disqualification must say why**, in the use case and in a CHECK constraint. "Not a real enquiry", "out of
  area", "price" and "wrong product" lead to four different conversations with a client paying for leads. The
  reason is cleared when a lead moves on, because leaving it attached to a lead that was later qualified makes
  the record say something nobody believes — and a reopened lead loses its closing time, because a stale
  `closed_at` makes every cycle-time figure wrong.
- **A Saudi phone number written four ways is one lead.** `+966555001122`, `00966555001122`, `0555001122` and
  `555001122` normalise to one key; counting them separately would report four times the volume at a quarter of
  the cost. The leading zero is a national trunk prefix rather than part of the number, which is why dropping it
  is right here and would be wrong elsewhere.
- **E-mails are lower-cased and nothing more.** No Gmail dot-stripping: it is right for Gmail and wrong for a
  server that distinguishes addresses, and merging two real people is worse than counting one twice. Nothing
  matches on a name at all.
- **Deduplication runs for a day.** Within it the same phone is one person resubmitting; beyond it, a new
  enquiry about something else. An enquiry with no contact details is always new, because refusing the second
  would lose one that happened.
- **A follow-up can improve an attribution, never weaken it, and only wholly.** An in-app browser strips the
  parameters and the customer resubmits properly, so taking the stronger arrival is better than a lead stuck as
  unattributed — but a half-merged attribution, this submission's click id beside the previous one's campaign,
  would be a link to a campaign nobody can verify.
- **A media buyer may read the pipeline and not qualify a lead.** A cost per *qualified* lead is the only honest
  measure of their work, and judging the quality of what you bought is not a judgement to hand to the buyer.
- **One attribution vocabulary shared with the orders**, including the CHECK requiring evidence to be backed by
  a field, so a cross-surface report holds both figures to one standard of proof.
- **The unattributed campaign row is always produced.** For leads it is usually the largest — most enquiries
  carry no parameters and many arrive by phone — so hiding it would make every named campaign look responsible
  for far more of the pipeline than it is.
- **Rates are computed in the domain, not in SQL.** A win rate written as a SQL expression is a win rate nobody
  reviews, and the wrong denominator is the commonest defect in this area.
- Contact columns are hidden on the model by default, and no resource puts them in a client-portal response:
  they belong to the agency's client's prospects.

### Not yet in place

- **No CRM bridge.** The source kind exists and nothing populates it; a CRM integration needs a provider, its
  OAuth, and a decision about which system owns a stage when both have one.
- **No automatic lead from an order**, because "every customer is a lead" is true for some clients and nonsense
  for others.
- **No lead merge**, and **no lead scoring** — a score is a model, and a model presented as a fact is what this
  product refuses.
- **No frontend surface.**

## Phase 14 — store analytics and revenue sources

Where the commerce domain becomes a figure somebody quotes to a client. Nearly all of it is about what is
excluded from a total, which is the only part of a revenue report that is hard to get right and impossible
to spot afterwards.

### Added

- `StoreAnalyticsReadModel` and its Eloquent implementation, `GetStoreAnalytics`, five DTOs, a
  `StoreAnalyticsResource`, and `GET /api/commerce/analytics`. 29 tests.

### Fixed

- **A `source` alias shadowed the orders table's own `source` column.** PostgreSQL resolves a `GROUP BY`
  name to the real column in preference to a select alias, so the channel breakdown grouped by the honesty
  label instead of the UTM source and the query failed outright. Aliased `channel_source` now.
- **The evidence rank aggregate returned each group's strongest evidence, not its weakest.** The rank is
  `100 - confidence`, so the weakest link is the *highest* rank — `max`, not `min`. Getting it backwards
  labelled every group with its best evidence, which is exactly the flattering this figure must not do, and
  it would have been invisible on the page.
- **Coupon-only orders vanished from the campaign breakdown.** They carry no `utm_campaign`; the campaign
  they name is the code somebody minted, as `OrderAttribution::campaignName()` already knew. The query now
  applies the same fallback — they were not wrong before, they were simply absent.

### Decisions worth recording

- **`revenue_source` is required, with no default and no "all" value.** A Salla total counts invoices; a
  GA4 total counts what a browser reported before the payment failed. A request that did not name its
  authority is refused rather than answered, because the answer would be quoted to a client.
- **Only `paid` and `partially_refunded` orders count.** A pending cash-on-delivery order is a real order
  and is not money. In this market that is most of a busy week, so they are reported *beside* the total —
  counting them would overstate every report until the courier either collects or comes back, and
  reporting them nowhere would hide real demand.
- **Revenue is net of refunds, with gross beside it.** A refund should be visible rather than inferred from
  a smaller number.
- **Average order value is null when nothing counted.** A denominator of zero has no average, and
  rendering one as "0.00" invites a reader to compare it with last month's.
- **Attribution coverage travels with every summary.** A channel report at 30% coverage is a different
  document from one at 95%, and the figure that tells them apart belongs beside it rather than in a
  footnote.
- **A breakdown row carries the weakest evidence anything in it rests on**, not the strongest. A group's
  total is only as defensible as its flimsiest link.
- **`by_campaign` names a campaign only where the evidence can name one.** A bare `utm_source` names a
  channel; splitting it across a client's campaigns would be inventing the split.
- **The unattributed row is produced, not dropped.** It is usually the largest, and omitting it so the
  percentages add to a hundred is how a channel report comes to overstate every channel in it. It is
  omitted only when it would be empty.
- **The summary's honesty label is the weakest of its rows**, so a total spanning a sandbox store and a
  live one is a sandbox figure. This is the one number in the product that must never be flattered.
- **Product rows report no refunds.** A refund is recorded against an order, and splitting it across lines
  would be a guess at which item came back. It is on the summary, where it is a fact rather than an
  allocation.
- **Breakdowns are limited and therefore do not add up to the summary**, which is why the summary travels
  with them: a reader sees the difference as "the rest" rather than concluding the total is wrong.
- **Visibility is the same rule as the measurement sources'** — the clients of the actor's projects,
  applied in SQL — so the two surfaces cannot disagree about who can see what.

### The page

- `src/features/commerce/` — `StoreAnalyticsPage`, `BreakdownTable`, `RevenueChart`, money helpers, types,
  api and hooks; a `/commerce` nav item; English and Arabic translations; 11 component tests and 4
  end-to-end tests.
- **The revenue-source selector is the first control and is never defaulted silently.** What it is set to
  is repeated in words on the total, in one of three sentences: an invoiced figure is the closest thing to
  what the client earned, a reported one is a measurement rather than a record, and a modelled one carries
  an explicit instruction not to show it to a client as actual.
- **Pending orders get their own amber banner**, naming the amount and why it is excluded.
- **Coverage sits beside the breakdowns and turns amber below 70%**, because it changes what every row
  below it can be read as. A coupon-backed row is styled as a warning rather than as neutral, since the
  number beside it is the one most likely to be acted on wrongly.
- Summary figures are built as `MetricValue`s so the shared card carries null-as-absent and the source
  badge, rather than reimplementing either differently.
- The chart draws refunds as a second series rather than netting them away: a flat month caused by returns
  is a different problem from a month where nobody bought.
- The query key includes the revenue source, because changing it asks a different question rather than
  refining the answer — caching two authorities under one key would show one total under the other's
  label.
- A plural key was written in i18next's v3 `_plural` spelling, which silently falls back to the singular;
  corrected to `_one`/`_other`.

### Not yet in place
- **Mixed currencies are not converted.** A client selling in two has no single total, and summing minor
  units across them would be wrong by the exchange rate. A proper answer needs a rates source and a
  decision about which day's rate.
- **No new versus returning split.** The data is there; the definition is a product decision rather than a
  query.

## Phase 13 — Zid

The second store platform, read against the published API v1 — and the test of whether the Phase 11
domain was secretly Salla-shaped.

### Added

- `ZidMapper`, `ZidSyncAdapter`, `ZidWebhookVerifier` and four fixture files in the documented response
  shapes. `docs/ZID.md` and 50 tests.

### Fixed

- **Zid's order webhooks would all have been silently dropped.** The receiving use case held a
  hard-coded list of actionable topics written while implementing Salla — `order.created`,
  `order.updated`, `order.status.updated` — and Zid's events are named `order.create`,
  `order.status.update`, `order.payment_status.update`. Every Zid order webhook would have been recorded,
  marked `ignored` and discarded, with the endpoint looking healthy and the journal filling with
  `ignored` rows that are an ordinary sight. Caught by the first Zid test that expected work to happen.
  Topics are now matched by the `order.` prefix: over-matching costs one journal row, because the worker
  refuses any payload carrying no order, while under-matching costs a client's orders.
- **The webhook verifier port now takes the whole header bag** rather than two Salla-shaped arguments.
  Salla signs the body and sends its own headers; Zid sends Basic credentials in `Authorization`. Those
  are not the same security property and cannot be hidden behind one signature.

### Decisions worth recording

- **Zid fits the normalised domain natively**, which is the most useful thing learned in this phase. It
  publishes `payment_status` alongside `order_status`, so no inference is needed at all — where Salla
  needed a documented boolean to recover the payment axis from one workflow status. The two-axis design
  was a judgement call made before either provider existed.
- **An unknown order status maps to `processing`.** Zid lets a merchant define custom codes, so an
  unrecognised one is ordinary rather than a defect, and guessing from its name is what this product
  refuses to do everywhere. `processing` claims only that the order is in progress and is *not settled*,
  so the sync keeps re-reading until the order reaches a state this product understands. A state we
  cannot interpret should keep us looking, not stop us.
- **`reverse_in_progress` is not `returned`**, for the same reason: `returned` is settled, and an order
  mid-reversal is still moving. The exact code survives in `raw_fulfilment_status`.
- **Zid's Basic Auth is implemented as what it is.** It proves the caller knows a password and says
  nothing about the body; the secret travels on every request, base64 being an encoding rather than
  encryption; and there is no timestamp or nonce, so a captured delivery can be replayed indefinitely.
  What makes that harmless is the upsert, and the documentation says so rather than implying parity with
  an HMAC. The comparison is still constant time — a weaker mechanism is no reason to leak the password
  through timing as well.
- **A Salla signature is refused on the Zid endpoint.** Each platform's verifier is separate so the
  weaker mechanism's assumptions cannot leak into the stronger one's code, and so accepting a mechanism
  Zid does not use is not possible.
- **Marketplace masking is handled structurally, not by a flag.** Nothing in this product writes a
  customer from an order payload, so Zid's masked placeholder has no route into the customers table. The
  order is still stored — it is a real sale — and its customer id links to nothing, which is the truth. A
  fabricated e-mail in a client's customer list would be indistinguishable from a real one afterwards.
- **`order_total`, never `order_total_string`.** The second is formatted for display, including Arabic
  digits where the locale asks, and parsing it would work until a store switched language.
- **Arabic is preferred for localised names**, because these are Saudi stores and the Arabic name is the
  one on the merchant's screen.
- **`is_infinite` stock is stored as null.** Reporting unlimited stock as zero would pause a campaign on
  a product that is always available.
- **`total_order_count_per_status` is ignored.** This product derives its own counts from the orders it
  stored, so a figure a reader can reconcile is never replaced by one they cannot.

### Not yet in place

- **The live transport**, on a Partner app install and the OAuth handshake.
- **No scheduled syncing**, and **no webhook subscription management** — creating a subscription is a
  write, and this integration does not write.

## Phase 12 — Salla

The first store platform, read against the published Merchant API v2 and mapped into the Phase 11 shape
rather than being allowed to define it. Read-only, and still BLOCKED_EXTERNAL on a Partner app install.

### Added

- `SallaMapper`, `SallaSyncAdapter`, `CommerceFixtures` and four fixture files in the documented
  response shapes.
- `SallaWebhookVerifier`, `StoreWebhookVerifiers`, `ReceiveStoreWebhook`, `ProcessStoreWebhook`,
  `ProcessStoreWebhookJob`, `EloquentStoreLocator`, `EloquentWebhookEventReader` and a public
  `POST /api/webhooks/stores/{provider}` endpoint, rate limited by merchant.
- `SyncStore`, the reconciliation sync. `docs/SALLA.md` and 58 tests.

### Fixed

- **An order's amount breakdown can now be absent.** Phase 11 defaulted subtotal, discount, shipping and
  tax to zero; Salla's order *list* reports a total and no breakdown at all. A stored zero reads as
  "nothing was charged for shipping", and a subtotal report built on it is confidently incorrect rather
  than visibly absent — the exact conflation this product refuses elsewhere. The columns are nullable and
  the amounts CHECK was rewritten, because `NULL >= 0` is unknown rather than true.

### Decisions worth recording

- **Salla's single status axis is mapped onto two, from documented facts only.** Five of its twelve slugs
  state the payment outright and the mapper quotes the documentation in each branch. The other seven
  describe a parcel.
- **For those seven, `is_pending_payment` is read rather than the slug guessed at.** This is the decision
  that matters most in this market: cash on delivery is normal here, so `shipped` means "the money has
  not arrived" for one order and "it arrived last week" for another. Mapping the slug to `paid` would
  overstate a COD store's revenue by everything in transit; mapping it to `pending` would understate a
  prepaid store's to nothing. Salla publishes a boolean that answers exactly this question, and reading
  it is the difference between a fact and a guess from a label. Absent, it falls back to `pending`,
  because an agency that finds its revenue low goes looking while one that finds it high has already sent
  the report.
- **A refund outranks the workflow status.** Salla will show an order as `delivered` while the money has
  gone back, and the two axes let both be true.
- **Pages are walked in sequence**, because Salla documents that a page out of order returns an empty
  response and caches sequential requests for fifteen minutes. A sync that parallelised its pages, or
  resumed from page four after a crash, would read nothing and report success — a silent failure rather
  than an error, so the fixture loader reproduces the behaviour.
- **Webhook signatures are hashed over the raw body**, never a re-encoding. `json_decode` then
  `json_encode` changes key order, unicode escaping and float formatting; an implementation that
  re-encodes appears to work until a payload carries an Arabic product name, and then rejects every
  webhook from every Arabic store — which here is most of them. The fixture body is Arabic so this cannot
  regress unnoticed.
- **Constant-time comparison**, because `===` on a hash leaks it through timing one byte at a time to
  anybody who can send requests and measure.
- **An unconfigured secret verifies nothing**, and so does an unrecognised strategy. A deployment that
  forgot the secret rejects webhooks instead of accepting forgeries: a gap somebody notices rather than a
  silent door.
- **The signature decides which workspace a delivery belongs to.** Stores are unique per workspace, so
  two agencies can both register one shop, each with its own installed app and secret. Every candidate is
  found and the signature checked against each one's own secret. Routing by first match would put one
  agency's client's orders into the other's workspace — a cross-tenant leak nothing downstream could
  detect.
- **Every delivery is answered 2xx, including refusals.** A non-2xx makes Salla redeliver, and
  redelivering a payload that will never verify achieves nothing but filling the journal.
- **Salla sends no event id, and it does not matter.** The work is an upsert keyed on the order's own id,
  so idempotency comes from the data — which is stronger than idempotency by an identifier the provider
  does not send. No identity is synthesised from a payload hash, because two genuinely distinct events can
  hash identically and dropping the second would lose a real sale.
- **The worker reads the payload back from the journal row**, the only payload that has been through the
  signature check, and refuses an unverified row even though the endpoint never queues one. A job can be
  enqueued by anything with queue access, and this is the last gate before a payload becomes revenue.
- **Webhooks and the reconciliation sync share one mapper**, with a test asserting they agree. A second
  mapper is how a client's revenue comes to change whenever a nightly sync overwrites what a webhook wrote
  an hour earlier.
- **A webhook writes `Live` and a sync writes `Synced`**, so a reader can see which way a figure arrived.
- **Customers and products are read before orders**, because an order line names a product and reading
  orders first would leave nulls the next run quietly fixes — with a window in which a client's product
  report was missing its best-selling item for no visible reason.
- **Order timestamps keep their time zone.** A Riyadh store read as UTC moves three hours of every
  evening's sales onto the previous day, which shifts revenue between months at a month boundary.
- **A customer's mobile is read as a string with its country code**, because Salla sends it as an integer
  and a phone number that has been through an integer may no longer dial.
- **Webhooks are rate limited by merchant, not by IP.** Providers deliver from shared infrastructure, so
  an IP key would let one busy shop throttle every other shop on the platform.

### Not yet in place

- **The live transport**, on a Partner app install and the OAuth handshake.
- **Order details are not fetched per order**; the breakdown is left null. One request per order is a
  quota decision worth making deliberately.
- **No scheduled syncing**, and **no webhook subscription management** — creating a subscription is a
  write, and this integration does not write.

## Phase 11 — the commerce domain

One normalised shape for every store platform, written before either provider so that neither gets to
define it.

### Added

- `app/Domain/Commerce/` — `PaymentState`, `FulfilmentState`, `RevenueSource`, `AttributionEvidence`
  and `OrderAttribution`.
- Eight tables: `stores`, `store_customers`, `store_products`, `store_product_variants`,
  `store_orders`, `store_order_items`, `store_refunds`, `store_shipments`, `store_webhook_events`.
- `CommerceSyncAdapter` (the contract Salla and Zid implement), `CommerceStore` (the write port),
  `WebhookEventJournal`, their Eloquent implementations, nine record DTOs, `docs/ECOMMERCE.md` and 32
  tests.

### Decisions worth recording

- **Payment and fulfilment are separate columns.** Every platform has both axes. A single `status`
  makes "delivered but refunded" unrepresentable — an entirely ordinary thing for a shop, where the
  customer received the goods, complained and got their money back — and the first thing anybody does
  with a single status is sum revenue by it. Each provider's own strings are kept verbatim alongside,
  because a mapping will be disputed the first time a client's figures differ from their platform
  dashboard.
- **Only `paid` and `partially_refunded` recognise revenue.** The kept part of a partial refund is real
  money; the refund comes off the amount, not off the order's existence. `pending` and `authorised` do
  not count, because cash on delivery that never arrives is not a sale — and counting it overstates
  every report in the week before the courier comes back, which in this region is a large share of
  orders.
- **A discount is a positive amount taken off**, never a negative one added. Mixing those conventions
  across providers is how a discount comes to increase revenue, and a CHECK refuses a negative.
- **`RevenueSource` exists so figures are never silently merged.** An ad platform reports what it
  believes it caused, an analytics property what a browser said before the payment failed, a store what
  was invoiced, a CRM what a person recorded. Adding two together produces a number no one can defend
  and everyone will quote.
- **Attribution records only what the order carried.** Not the hour it arrived, not the customer's
  city, not being the only order on a day a campaign ran. Those correlations are invisible once they
  reach a report, and acting on them means telling a client which campaign earned their money on the
  strength of a coincidence.
- **The evidence has levels, and a bare `utm_source` is not one that names a campaign.** It names a
  channel; a report splitting revenue across a client's Meta campaigns on that basis would be inventing
  the split. A coupon code sits deliberately below half confidence, because a code is typed by somebody
  who may have had it from a friend, an influencer or an aggregator.
- **`none` is a legitimate and common answer**, and the one a dishonest implementation would quietly
  fill in. Analytics placeholders — `(not set)`, `(none)` — are treated as absent, because stored
  verbatim they become a campaign literally called "(not set)" in a client's report.
- **A database CHECK requires evidence to be backed by a field.** An order claiming campaign parameters
  with no parameters on it is the one defect here that no report could reveal afterwards, so a second
  write path cannot produce it.
- **Everything upserts**, because every sync re-reads a restatement window and an insert would multiply
  a client's revenue by the number of runs — which would look exactly like growth. Order lines, refunds
  and shipments are *replaced*; variants are *upserted*, because order items reference them and deleting
  one would lose which variant a past sale was for; and an item's product reference is nullable, because
  an order can name a product somebody has since deleted and the line is still a real sale.
- **Customer lifetime totals are derived from stored orders**, and a customer re-sync cannot overwrite
  them. The provider's own total includes orders this workspace never read and counts refunds on its own
  terms; the figure that reconciles against the orders in front of the reader is worth more than the one
  that is larger.
- **`orders_synced_through` moves forward only**, settled in SQL, so a backfill of an older window
  cannot make the next sync skip the days between — the silent half of that failure.
- **The webhook insert uses `ON CONFLICT DO NOTHING` rather than catching the violation.** In PostgreSQL
  a constraint violation aborts the whole surrounding transaction, so catching one would turn a harmless
  redelivery into a failed request for any caller inside a transaction — and a handler that also writes
  an order will be. Found while writing the test for it.
- **An event with no provider id is processed anyway.** This product will not invent an identity from a
  payload hash: two genuinely distinct events can hash identically, and silently dropping the second
  would lose a real sale.
- **An unverified signature is recorded as `rejected`, not discarded.** The arrival is how somebody
  finds out an endpoint is being probed, and writing down which it was is the only way to be sure
  afterwards that it was not acted on.

### Not yet in place

- **No provider reads anything yet**; the adapters are Phases 12 and 13.
- **No HTTP surface**; store analytics arrive with Phase 14.
- **No cursors or run journalling**, deferred to Phase 12 where a real provider's pagination determines
  their shape.
- **Payments are folded onto the order.** A separate table earns its place when a platform reports split
  payments.

## Phase 10 — the Tracking Center

The surface that makes the measurement layer usable: one page answering the question an agency
cannot otherwise answer — is any of this actually working?

### Added

- `src/features/tracking/` — `TrackingCenterPage`, `IssueList`, `SourceRegistration`,
  `ConversionEditor`, types, api and hooks over all seven measurement endpoints.
- A `/tracking` nav item gated on `tracking.view`, English and Arabic translations, 11 component
  tests and 10 end-to-end tests.

### Changed

- Conversion save failures now show **the server's own message** rather than a generic one. Every
  refusal names the thing to change — which raw name is already taken, that a primary must also be a
  conversion — and the generic string threw that away. The fallback is now reserved for a failure
  with no message at all.

### Decisions worth recording

- **The issue list comes first.** A page opening on a tidy table of sources invites the conclusion
  that everything is fine, which is precisely the conclusion this product exists to prevent. There
  is an end-to-end test asserting the order, because it is a product decision rather than a layout
  one.
- **Evidence and remedy are on the page, not behind a tooltip.** A tracking centre that says
  "purchase event missing" without saying how it knows is one people stop believing after the first
  false alarm — and a false alarm is certain, because the product only sees what a provider reports.
  The evidence is what lets somebody disagree, which is the only reason they will trust it when it is
  right.
- **An empty issue list is a rendered state with its own words.** Silence is the goal, so a blank
  area would read as a loading failure.
- **Registering a property is discovery-driven.** Nobody can be expected to type
  `properties/412006881` correctly, and a mistyped id produces a source that syncs nothing while
  looking perfectly configured. The list is deliberately unfiltered, with the site and `G-` id beside
  each entry, because an agency's Google account holds properties for clients it has left.
- **The conversion editor never infers a meaning.** Raw names come from typing or from the property's
  key events; the meaning is the person's choice, and a suggestion applies one only where the
  product can cite it. The intent-signal warning appears at the moment of choosing, not only in the
  issue list later.
- **A sync reports its caveats.** A bare "synced" toast would hide that a run read 8% of the data,
  and the person who pressed the button is the one about to quote the number.
- **`0` and `—` are rendered differently for an observed count.** "Asked and never happened" and
  "nobody looked" are different facts, and collapsing them turns the second into the first.
- **An unreadable evidence value renders as a dash**, not `[object Object]`, which would look like a
  bug in the product rather than a gap in the formatting.

### Not yet in place

- **Container readiness is not on this page.** The endpoint is complete; placing a live provider call
  beside data that always loads needs a design decision worth making deliberately.
- **No charts.** An event-count trend was left out on purpose: this page answers "is it working", and
  a sparkline invites reading it as performance.

## Phase 9 — Google Tag Manager

Container readiness: whether what somebody built is deployed and able to fire. Read-only
permanently, and still BLOCKED_EXTERNAL for live credentials.

### Added

- **`TagContainerReadAdapter`**, `GoogleTagManagerReadAdapter` and `GoogleTagManagerMapper` over the
  Tag Manager API v2: `accounts`, `containers`, `versions:live`, `workspaces/{w}/status`.
- **`ContainerReadiness`**, a pure domain assessment, plus `ContainerFacts` and `TagFacts`.
- Six new `TrackingIssue` codes sharing the tracking vocabulary: `container_never_published`,
  `container_unpublished_changes`, `tag_paused`, `tag_without_trigger`, `tag_trigger_missing`,
  `measurement_id_not_in_container`.
- `ListTagContainers`, `GetContainerReadiness`, two endpoints, `docs/GOOGLE_TAG_MANAGER.md`, and 29
  tests.

### Decisions worth recording

- **A container is assessed, not synced.** It reports configuration rather than events, so it cannot
  be judged by freshness and has no place in the sync contract — forcing it there would mean an
  empty `fetchEventCounts()`, which reads as "measured nothing" rather than "not that kind of
  thing".
- **Readiness is its own endpoint, not part of `/measurement/health`.** Health is derived from our
  own tables and always answers; readiness needs a live call that can be slow, rate-limited or
  unauthorised. Folding them together would let a provider outage empty the tracking page of facts
  it already had.
- **The live version is read, never a workspace.** A workspace holds what somebody is working on.
  An agency believing tracking is configured because the workspace looks right is the single most
  common failure in this area, and reading the wrong one would make this product a participant in
  it. A container nobody published returns that as its only finding, because paused tags and missing
  triggers inside an unpublished container would bury the one fact that matters.
- **A tag's name is never grounds for a finding.** "GA4 – Purchase" is a label somebody typed; it may
  fire on a thank-you page, on every page, or on a button nobody clicks. Reporting "purchase
  tracking is configured" because a tag was called that would be confidently wrong in the one place
  an agency cannot afford to be, and the client would have no way to tell. The name appears only as
  a finding's subject, and a container whose sole tag is called that produces no findings at all.
- **Built-in triggers are never accused of being missing.** Every container has undeletable All
  Pages, Consent Initialization and Initialization triggers that the API does not return in the
  trigger list, and nearly every real tag fires on All Pages — so a naive comparison reports the
  whole container as broken. This was caught by the first realistic fixture. The threshold is chosen
  so the failure mode is **silence rather than a wrong accusation**: a missed dangling trigger costs
  one warning, a false one on every tag empties the page of meaning immediately. An id that is not a
  plain integer is likewise left alone — an id this code cannot parse is not one it understands well
  enough to call broken.
- **The measurement-id check is absence of evidence, not evidence of absence.** It scans for the
  id's own shape at any depth under any key, because which parameter key holds a measurement id
  depends on the tag template: finding the id is a fact about the container, assuming a key would be
  a guess. A miss is a warning phrased as a question, with the ids it *did* find as evidence, because
  a container can load a property through a custom template or a server-side container that this
  product cannot see. Telling somebody to "add the tag" when it is already there is how a tracking
  page loses its authority.
- **Unpublished changes span workspaces.** `getStatus` is per workspace and a container can have
  several; changes in any of them are unpublished from the live site's point of view.
- **A source pointing at an unreadable container fails with a 404.** Silently assessing a different
  container would be the worst available outcome.

### Not yet in place

- **The live transport**, on the same gate as Analytics.
- **Variables and folders** are read past rather than assessed: nothing honest follows from a
  variable's existence, and inventing findings to make the page look thorough is the opposite of the
  point.
- **Consent-mode configuration** is visible in `consentSettings` and deliberately not assessed.
  Whether a setup is compliant is a legal judgement about a jurisdiction, not a structural fact, and
  this product will not imply it has made one.

## Phase 8 — Google Analytics 4

The first source that fills `observed_event_counts`. Built against the published Admin API v1beta
and Data API v1beta contracts, read-only permanently, and still BLOCKED_EXTERNAL for live
credentials.

### Added

- **`MeasurementSyncAdapter`** and its registry, separate from the advertising ones: an ad platform
  is asked about campaigns and spend, an analytics property about which raw events arrived.
- **`GoogleAnalyticsMapper`** and **`GoogleAnalyticsSyncAdapter`** covering `accountSummaries.list`,
  `properties.dataStreams.list`, `properties.keyEvents.list`, `properties.getMetadata` and
  `runReport`.
- **`ReportQuality`** — GA4's own statements about the completeness of its answer, mapped to the
  honesty label a figure carries.
- **`SyncMeasurementSource`**, `DiscoverAnalyticsProperties`, `SuggestConversions`,
  `EloquentObservedEventWriter`, and `MeasurementFixtures` — a fixture loader that keeps whole
  responses rather than rows.
- Three endpoints: property discovery, conversion suggestions, and sync. `docs/GOOGLE_ANALYTICS.md`
  and 60 tests.

### Fixed

- **GA4, Tag Manager, Salla and Zid could not actually be connected.** Phase 6 added them to the
  provider registry with adapters, credential keys and sandbox accounts, and the
  `provider_connections` CHECK constraint written in Phase 2 still listed only the six advertising
  platforms plus OpenAI. A provider therefore appeared on the integrations page, offered a Connect
  action, accepted credentials through validation — and then failed at the insert with a constraint
  violation. Found by the first test that tried to connect GA4. The constraint is widened from the
  enum rather than dropped, since it is the reason an unknown provider string cannot reach a table
  half the product reads.

### Decisions worth recording

- **Report rows are read through their headers, never by position.** A `runReport` row is a
  positional array of `{value}`. Reading `dimensionValues[0]` because that is the order we asked for
  would work until somebody added a dimension, at which point every date becomes an event name —
  silently, with the figures still looking plausible. The fixtures list `eventName` before `date`
  so a positional reader fails the suite immediately, and the fixtures are stored as whole
  responses rather than rows precisely so the headers exist to be read.
- **The value metric is asked for, not assumed.** Custom metrics differ per property, and a
  property with no revenue data blocks its currency metrics with `NO_REVENUE_METRICS` — requesting
  one anyway turns every sync into a 400. So `getMetadata` is read and a metric GA4 itself declares
  `TYPE_CURRENCY` is used. A property reporting no money is read for counts only, which is the
  honest outcome: a hard-coded name we had not verified would be an invented metric, and a stored
  zero would be worse.
- **GA4's completeness signals decide the figures' label.** Sampled, thresholded, truncated, or
  rolled into `(other)` — each makes the run `PARTIAL` rather than `SYNCED`, with one sentence per
  cause. A tool that reads the rows and drops the flags reports 412 leads with the same confidence
  whether it saw all of them or 4% of them, and the client is told 412 either way. A sampling
  fraction under 1% is shown to two decimals, because "0%" for a 0.4% sample reads as "no data"
  when it means the figure is a two-hundredfold extrapolation.
- **An empty report is not a defect.** `emptyReason` is recorded and deliberately not counted as
  partial: a property with no traffic in the window is a fact about the client.
- **`(other)` is never stored as an event name or a date.** As a name it would offer somebody a
  conversion called "(other)" to map; as a date, a row we cannot place is dropped rather than
  parked on today, which would move someone else's traffic into this morning.
- **Key events are suggestions, and the citation bar is strict.** A normalised event is proposed
  only for names Google documents with a defined meaning. `generate_lead` is **not** proposed,
  because it does not appear on either page the map was built from — a suggestion the product
  cannot cite is a guess wearing a citation's clothes. The cost of asking is one dropdown; the cost
  of getting one wrong is a conversion that counts the wrong thing for a year.
- **Roll-up properties are flagged.** A roll-up counts the same event its source properties do, so
  measuring a client through both doubles every figure, and nothing later in the product could
  detect that it had happened.
- **Discovery is unfiltered.** An agency's Google account holds properties for clients it has left,
  and that is exactly the list a person must read carefully rather than one to prune for them.
- **The sync re-reads a window instead of resuming from a high-water mark.** Analytics figures keep
  moving after the day closes, so "since last time" would freeze every day at its first, lowest
  value. The writer upserts on `(source, raw name, date)` — inserting would multiply a client's
  conversions by the number of times the sync had run, which would look exactly like growth.
- **The weakest label in a run wins**, rather than labelling page by page: a chart totalling a
  complete day and an estimated one is an estimate, and a reader cannot see which rows contributed.
- **`last_synced_at` is written even on a failure**, because "we tried and got nowhere" is a
  different fact from "nobody has asked". **`last_event_at` only moves forward**, settled in SQL
  with `greatest()`, so a backfill of an older range cannot age a healthy source into a critical
  warning.
- **`Page` moved to `App\Application\Shared\DTO\ProviderPage`.** One page from somebody else's
  API is not an advertising concept, and measurement needed it without coupling to advertising.

### Not yet in place

- **The live transport.** Needs a Google Cloud project with both APIs enabled, an allow-listed
  OAuth redirect URI, and a user who can read the property. Real credentials fail loudly and name
  what is missing; there is no path that invents a count.
- **Currency exponents.** Revenue rounds at two decimal places through the shared `Money` value
  object, so JPY and KWD are wrong. A pre-existing limitation shared with every advertising mapper,
  recorded rather than worked around in one mapper.

## Phase 7 — the measurement foundation

Where a number gets its meaning. Every later phase — store analytics, lead intelligence,
journeys, attribution, the AI analysis, the generated decks — resolves conversions through this
layer, so a guessed meaning here would make all of them confidently wrong in a way no chart
reveals.

### Added

- **Measurement sources** (`measurement_sources`): what reports events or orders for a client —
  a GA4 property, a GTM container, an ad account, a store, a CRM, a website form, a figure
  somebody types. Client-scoped by default, so a project read returns its own sources *and* the
  client-level ones it inherits.
- **`NormalizedEvent`** — a fourteen-case vocabulary, each case answering whether it is a
  conversion by nature and whether it is an intent signal only.
- **The conversion registry** (`event_definitions`, `event_mappings`): a named thing a project
  counts, and the raw event names that mean it.
- **`observed_event_counts`** — one source, one raw name, one day, with an optional value in
  minor units. The aggregate the diagnosis reads.
- **Tracking health** — `GET /api/measurement/health`: a pure diagnosis over ten issue codes,
  every one carrying the evidence it rests on and a remedy, sorted worst-first.
- `MeasurementFreshness` (`live`, `synced`, `stale`, `silent`, `never_synced`), `TrackingIssue`,
  `TrackingSeverity`, `MeasurementSourceKind`.
- Nine endpoints, `tracking.view` / `tracking.manage`, five audit actions, two new docs —
  `docs/MEASUREMENT.md` and `docs/TRACKING.md` — and 52 tests.

### Decisions worth recording

- **Nothing infers meaning from an event name.** One property's `contact` is a form submission,
  another's is a phone tap, a third fires it on the contact page. A raw name counts towards a
  conversion only because somebody mapped it, and that mapping has an author and an audit
  entry. The cost is that a person has to do the mapping; the alternative is revenue figures
  nobody can defend. `purchase_complete` arriving unmapped is reported as unmapped.
- **`stale` and `silent` are deliberately different states.** Stale means the figures are old.
  Silent means the source is syncing perfectly and measuring nothing — and that is the failure
  this whole area exists to catch, because a report built on it comes out quietly empty rather
  than visibly broken. Zero looks exactly like a bad month.
- **A source's kind must match its provider's category.** Registering a Salla store as an
  analytics property would put invoiced revenue into the column that holds what a browser
  reported. Those disagree by design, and once they share a column nobody can say which is
  wrong. The Phase 6 registry already knows each provider's category, so the check costs one
  comparison.
- **Only a store and a CRM are revenue authorities.** An analytics property reports what a
  browser said a sale was worth — measured before the payment failed or the item came back. An
  ad platform reports what it believes it caused, which is a claim about its own
  effectiveness. Neither is an invoice.
- **Intent signals are flagged, not refused.** A WhatsApp click is not a conversation and a form
  start is not a submission. An agency may genuinely want to count clicks; the danger is
  somebody reading the figure as leads six weeks later, so the product names it and lets them
  decide.
- **Freshness and the whole diagnosis are derived on every read.** A stored diagnosis is wrong
  the moment the next sync lands, and a stale warning is worse than no warning: it teaches
  people to ignore the list, which costs more than the one issue it got wrong.
- **`critical` is narrow on purpose.** It means something the client is paying to have measured
  is not being measured at all. A list where everything is urgent is a list nobody reads, and
  this feature is worth nothing the moment it stops being read.
- **Every issue carries its evidence.** A tracking centre that says "purchase event missing"
  without saying how it knows is one people stop believing after the first false alarm — and a
  false alarm is certain, because this product can only see what a provider reports to it. The
  evidence is what lets somebody disagree, which is the only reason they will trust it when it
  is right.
- **An edit replaces a definition's mappings outright, and the audit keeps both sides.** "Leads
  halved in March" is sometimes a campaign and sometimes a mapping somebody edited, and nothing
  else can tell them apart afterwards.
- **`observed_count` is null when no window was asked for, and `0` when one was and nothing was
  seen.** Answering "0" to a question nobody asked reads as "never happened".
- **Sessions and touchpoints were moved out of this phase to Phase 16.** They are identity
  questions, and answering them without identity evidence and a confidence level would be the
  guessing this phase exists to refuse.

### Not yet in place

- **Nothing populates `observed_event_counts` in production.** GA4 (Phase 8) and the commerce
  adapters (Phases 11–13) fill it. Until then a real workspace's honest tracking answer is
  `never_synced` — which is the correct answer, not a gap in the feature.

## Phase 6 — the provider registry

One canonical catalogue of every platform, consumed by both ends. The registry existed in
miniature already — an enum of providers with capabilities and auth methods — and this makes
it the thing the directive asks for: the single source of truth for provider vocabulary.

### Added

- `ProviderCategory` (advertising, analytics, tag management, commerce), `ProviderApiStatus`
  (generally available, requires approval, no public API), `ProviderEntity` and
  `ProviderRateLimit`.
- Four new providers in the registry with connection adapters, honest statuses and sandbox
  accounts: **Google Analytics 4**, **Google Tag Manager**, **Salla** and **Zid**. Their
  sync adapters come in their own phases; the registry entries are what make them appear
  with a status somebody can act on.
- Every registry field is published on `GET /api/integrations/providers`, and the
  integrations page now groups providers by category — eleven cards in one grid is a list to
  scan rather than a page to read.

### Decisions worth recording

- **The registry and the connection state travel on one response.** Splitting them would
  mean every page needing a provider's *name* had to join two endpoints, which is how a
  second list of platform labels gets written — the drift this registry exists to prevent.
- **`api_status` is separate from `blocked_external`.** The second says a workspace cannot
  proceed; the first says why — a programme to be admitted to, an app to register, or no
  public API at all. Those need different actions from different people, and rendering all
  three as "not connected" sends somebody looking for a setting that cannot help them.
- **Capabilities that span categories declare no category.** Discovering accounts, reading
  reporting figures and receiving webhooks are things an ad platform, an analytics property
  and a store all do. Filing them under a convenient one would have made the registry's own
  consistency check meaningless — and that check is what stops a store claiming it can
  report campaign metrics.
- **GA4 and Tag Manager declare no write capability at all**, though both publish write
  APIs. Changing somebody's measurement setup from a reporting tool is a hazard, not a
  feature, and the registry is where that decision is visible rather than implicit in what
  we happened not to build.
- **An undocumented rate limit is null, never a guess.** Tag Manager's is published and
  surprising — 0.25 QPS and 10,000 requests a day, both per *Google Cloud project*, so every
  workspace on one deployment shares it, and exceeding it answers 403 rather than 429. Salla
  and Zid publish nothing, so they say so and back off on the provider's own throttling.
- `ProviderCategory::totalsWith()` encodes the rule the whole taxonomy is for: figures never
  total across categories, because an ad platform's claimed revenue, an analytics property's
  recorded revenue and a store's invoiced revenue are three different numbers.

### Tests

Nine registry tests, including that a provider claiming an API must have documentation and
entities to go with it, that the read-only integrations declare no writes, and that no
provider borrows another category's capability. The existing architecture test caught the
four new providers before any of this ran — it asserts every provider in the enum has an
adapter, which is what it was written for. Backend 676 passed, frontend 173.

## Phase 5 — budgets, pacing and forecasting

Completes Phase 5. The governance hierarchy from CLAUDE.md, the arithmetic a client's
money is judged by, and the two surfaces that make it usable.

### Added

- `budgets` and `budget_allocations`: an approved client budget split across platforms,
  campaigns and ad groups as a self-referencing tree. Five CHECK constraints carry the
  rules, including that a budget marked approved cannot exist without the name of whoever
  approved it.
- `budgets.view`, `budgets.manage` and `budgets.approve`. The third is separate and
  **media buyers do not have it**: drafting a plan and committing a client to a figure are
  different acts, and that separation is the only thing making "approved" mean anything.
  Client viewers have none of the three — what a client may see is decided by explicit
  sharing in Phase 7, not by a role that happens to open a screen.
- `Pacing`, pure arithmetic over four facts: the budget, the period, actual spend and
  today. Seven statuses, because "over budget" and "spending too fast" are different
  problems with different answers.
- Pacing, the alert list and forecast rates are **derived on read**, never stored. A
  stored pacing figure is wrong the moment the next sync lands, and a stale alert is worse
  than none because it teaches people to ignore the list.
- A forecast in both directions — budget → results and target → budget — from the
  workspace's own delivery.
- The immutable change log the spec asks for, written to the existing audit trail rather
  than a second history table. Approving a budget is a **security event**: committing a
  client's money has to be as findable as a credential change.

### Decisions worth recording

- **An approved budget's amount, period and currency cannot be edited.** A figure that
  can be quietly revised says nothing about what a client agreed to. Changing one means
  archiving it and approving a new one, which leaves both on the record; allocations stay
  editable, which is how a plan adapts without the commitment moving.
- **Allocations may sum to less than the budget, never more.** The unallocated remainder
  is reported at every level: it is a decision nobody has made yet, and seeing it is how
  someone remembers to make it.
- **Pacing is even by definition**, with the 10% tolerance published alongside the figure.
  A weighted pace is a forecast wearing a plan's clothes, and a reader comparing today's
  spend against "expected" has to know exactly what expected means.
- **Spend is read at campaign level only.** `daily_metrics` holds the same spend three
  times, once per level; adding them would roughly triple it, and the result would look
  entirely plausible next to a budget.
- **No industry benchmarks.** Forecast rates come from the workspace's own observed
  delivery or they do not come at all. A platform with fewer than three days of delivery
  gets a reason instead of a number; bands are the 25th–75th percentile of the account's
  own daily rate rather than a percentage chosen to look like a range; headline rates are
  weighted (total spend over total clicks) rather than an average of daily rates, which
  would let a quiet day with two clicks count as much as a busy one.
- **Every projection is labelled `estimated`**, carries its assumptions in words, names
  what it could not work out, and publishes a version string identifying the sample — so a
  figure quoted in a meeting can be reproduced, and a different version means the numbers
  have moved. The disclaimer is in the payload rather than left to the interface to
  remember.

### Refusals, each of which would otherwise produce a plausible screen

- A **draft** is not paced: a variance from a figure nobody agreed to means nothing.
- Spend that **cannot be totalled** — two currencies, a currency the budget is not in, or
  sources that cannot combine — yields no total *and no pacing*, with the reason. Pacing
  against a partial figure would understate spend, which is the one direction that
  overcharges a client without anybody noticing.
- An exchange rate is **never invented** to reconcile a budget with spend in another
  currency.
- An allocation planned before its campaign exists has nothing to compare with, and says
  so rather than showing a zero.
- A budget whose spend could not be totalled still appears in the alert list, with its
  reason. Dropping it would make the list look reassuringly short.

### The two surfaces

- A **Media Planner** holding the budget list, the draft form, approve and archive, the
  allocation editor and the pacing panel on one screen, because those are one
  conversation. A planner looking at an overspending platform wants to move money now.
- The **allocation editor** shows the remainder at every level and does not auto-fill it:
  spreading the leftover would be a media decision made on somebody's behalf and called a
  convenience. Over-allocation warns here and is refused by the server — the warning is
  the fast feedback, the refusal is the rule.
- Platform choices come from the **provider registry**, filtered to providers whose API
  can report metrics. No page keeps its own list of platform names any more; the labels
  that were briefly hard-coded here are gone.
- A **pacing chart** answering one question: is this budget going to land where it was
  meant to? Cumulative spend against the even pace, with the approved budget as a line.
  The actual series stops at the last reported day rather than running flat to today,
  because a flat cumulative line means "spent nothing since" and a missing sync means "we
  do not know".
- A **Budget Calculator** in both directions, showing each figure's band, its confidence,
  the assumptions in words and the sample version — and a reason instead of a number where
  a platform has too little delivery.
- `Choice`/`ChoiceGroup` promoted out of the dashboard widget editor into shared UI, so
  the planner, the calculator and the builder share one labelled-toggle pattern rather
  than three copies.

### Tests

20 domain tests on the pacing arithmetic alone, including that a budget nobody has set is
reported as *unknown* rather than "on track"; 58 backend feature tests covering approval,
visibility, the allocation tree, pacing against really-synced spend and the forecast's
refusals; 30 frontend tests including that a role which may plan is not offered approval,
and that an untotallable spend shows its reason rather than a zero; 9 Playwright specs.
Backend 668 passed, frontend 173, E2E 136, PHPStan level 8, Pint, ESLint and Prettier
clean.

## LinkedIn sync

Sixth and last provider with a published contract. Field names, enum values and
response shapes were read from LinkedIn's reference, which is the best documented of the
six — full field tables with complete enumerations.

### Added

- `LinkedInMapper` and `LinkedInSyncAdapter` for the versioned `/rest/` API, with 28
  mapper tests and 12 sync tests.
- `_date_objects`, a fixture-loader directive that writes a date as
  `{year, month, day}` integers. LinkedIn sends no date string anywhere in a reporting
  response, so without it either the fixture or the mapper would have to use a shape no
  live row has.

### The hazard, recorded in three places

**A LinkedIn "campaign" is an ad set.** Its hierarchy is account → campaignGroup →
campaign → creative, and the *campaign* is what carries targeting, a bid, a budget and
an objective. So a campaign group lands at our campaign level, a campaign at our ad
group level, and a creative at our ad level. Get it wrong and every roll-up in the
product is off by one level, which is why it is stated in the adapter, the mapper,
docs/INTEGRATIONS.md and the fixture README rather than left to be deduced — and why the
sync test asserts the whole chain creative → campaign → campaign group.

### Conventions handled

- Money is a **decimal string inside an object** (`{"amount": "180000.00",
  "currencyCode": "SAR"}`), and analytics money is a bare decimal string that may carry
  more than two decimals (`"19.91833"`). Rounded half-up at the minor unit, as their
  billing does.
- **Schedules are epoch milliseconds**; read as seconds a 2025 date becomes the year
  57560.
- Ids are URNs in some places and longs in others, so everything is reduced to the
  trailing id.
- **`servingStatuses` decides delivery, not `status`.** `RUNNABLE` is its only serving
  value, so an ACTIVE group that has spent its budget is reported as not delivering with
  `CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD` carried through untranslated.
- `CANCELED` maps to *removed*, not *completed*: it is permanent and not
  re-activatable, and "completed" would say it ran its course.
- A creative has no format field — the ad format is on the campaign — and often no name
  either, so it is labelled by the post it sponsors.
- A creative names only its campaign, so the campaign group is resolved from the
  campaign already synced: the third provider running whose ad level needs that.
- `approximateMemberReach` is deduplicated members and only returned for ranges of 92
  days or less, which is why the insight window is 92. A parent's reach is below the sum
  of its children's, and the fixtures encode that so a test cannot pass by summing the
  wrong level.
- `conversionValueInLocalCurrency` is one total rather than a figure per type, so it is
  the row's revenue and no breakdown row carries a value. LinkedIn does publish
  conversion worth, unlike X.
- `JOB_APPLICANTS` is filed as *other*, not *leads*: a job applicant is not a sales
  lead, and hiring spend in a conversion chart a client reads as pipeline would mislead.

### Not read

The post a creative sponsors — where its headline, image and link live — and the revenue
attribution finder, which needs a CRM connected to Business Manager. LinkedIn also warns
that its reach and demographic figures differ slightly between reporting levels because
they are approximate; nothing here reconciles that away.

## X Ads sync

### Added

- `XAdsMapper` and `XAdsSyncAdapter` for X Ads API v12, with field names, metric names
  and response shapes read from X's published reference. 32 mapper tests and 15 sync
  tests.
- Fixtures in X's real stats shape, including a campaign that reports nothing at all
  and a line item whose metrics X omits entirely rather than zeroing.

### Conventions handled

X is the first provider here that is shaped differently rather than merely spelled
differently:

- **Performance is a time series.** One row holds one array per metric, aligned to the
  requested range — so a row is a range, not a day, and bucket *i* is the start plus
  *i*. The adapter contract now returns a *list* of records per row for this reason; a
  mapper reading only the first bucket would silently discard every other day.
- **The objective is on the line item**, so an X campaign carries none at all rather
  than one borrowed from a child.
- **Reach is a separate endpoint**, so every X row reports no reach rather than
  impressions standing in for people.
- **Auth is OAuth 1.0a** — the only provider here that is not OAuth 2.0, and the only
  one with no refresh token to rotate.
- A promoted tweet carries no campaign id and no name: the campaign is resolved through
  the line item, and the row is labelled by its tweet.
- A card is assembled from components (MEDIA, SWIPEABLE_MEDIA, DETAILS, BUTTON) and X
  derives `card_type` from them, so the medium, headline, button and destination are
  read from the components. A SWIPEABLE_MEDIA component is what makes a carousel.
- A metric with no data arrives as `null`. X documents null and zero as equivalent for
  it; they are not equivalent here, so the null survives.

### Fixed

- **The insight window was clamped instead of split.** Each adapter states how much a
  provider will answer in one request, and the sync shortened the range to fit. X is
  the first provider whose ceiling (7 days) is less than its attribution lookback (28),
  which meant it could never re-read its own attribution window: a purchase attributed
  ten days back would have stayed invisible and the client would have seen a worse ROAS
  than they earned. The range is now asked for in consecutive windows.
- **`CANCELLED_REQUEST` (408) and `LOCK_ACQUISITION_TIMEOUT` (423) were filed as
  unknown**, and so reported to an operator as something to fix. Both are documented X
  conditions that clear on retry, and both are now classified as transient.

### Not verified

`reasons_not_servable` and `effective_status` have no published enumerations — X's
examples show only `PAUSED_BY_ADVERTISER`/`INCOMPLETE` and `UNKNOWN`/`RUNNING`, and the
fixtures use nothing else. Only those two reason codes are interpreted; any other still
yields "not delivering" because `servable: false` says so, and the code reaches the
interface untranslated. Card types beyond the one documented example are read by their
leading token rather than matched against an invented list.

## Snapchat sync

### Added

- `SnapchatMapper` and `SnapchatSyncAdapter`, with field names, enum values and
  response shapes read from Snap's published reference. 27 mapper tests and 11
  sync tests.
- An `unwrap()` hook on the sync adapter base. Snapchat wraps every list item as
  `{sub_request_status, <entity>: {…}}` because a batch can partly succeed, so a
  failed row carries no entity and is dropped rather than mapped into a record with
  an empty id. Unwrapping is a provider concern, not a mapper one.
- The store now resolves an ad's campaign through its ad group when the provider does
  not supply one. Snapchat's ad object carries only `ad_squad_id`; without this the
  sync would silently discard that provider's entire ad level.

### Conventions handled

Snapchat is the fourth distinct set in four providers: money in **micros** (like
Google), a lifetime **spend cap** rather than a lifetime budget, a click called a
**swipe** with no separate link-click metric, reach as `uniques`, quartiles as
**counts** (unlike Google's rates), `bid_micro: 0` meaning auto-bid rather than a
zero bid, and delivery state in a `delivery_status` array because `status` is only
ACTIVE or PAUSED.

### One unverified assumption, recorded rather than hidden

Every money field Snap publishes carries a `_micro` suffix **except** the stats
metric `spend`. It is mapped as micro-currency for consistency with the rest of their
API, which is the likely reading — but it could not be confirmed, and if it is wrong
spend is understated by a factor of 10,000. The fixtures encode the same assumption,
so no test can catch it; only a live response can. Flagged prominently in
`docs/INTEGRATIONS.md` and `docs/ROADMAP.md` as the most consequential open item
across all four providers.

## Phase 4 — the dashboard builder

Completes Phase 4. The foundation commit's schema and ports now have use cases,
repositories, an API and a working builder behind them.

### Added

- Saved views: `/api/analytics/views` with full CRUD. A **preset** is stored rather
  than the dates it resolved to, so a view called "last 28 days" still means that next
  month; an explicit range is stored literally, because a closed month is a legitimate
  thing to save. The query is rebuilt into a real `AnalyticsRequest` on the way in, so
  a view saved today still parses in six months.
- Dashboards: `/api/dashboards` with CRUD plus duplicate. The widget set is replaced
  rather than merged, omitting `widgets` leaves the canvas alone, and `widgets: []`
  empties it — three distinct instructions, all supported.
- A builder UI covering add, remove, reorder, resize, retitle, chart type, metrics,
  grouping, widget-level filter overrides, audience, duplicate and save. Responsive,
  Arabic RTL, dark mode, with loading, empty, error and permission states.
- Grid arithmetic as pure functions (`layout.ts`) with 14 unit tests: every reorder
  and resize re-flows into a valid layout, so an invalid one is prevented rather than
  refused on save.
- 57 backend tests, 32 frontend tests and 11 Playwright specs.

### Decisions worth recording

- **Reordering and resizing are buttons, not drag-and-drop.** Drag-only reordering
  fails WCAG 2.2's dragging criterion, does not work with a keyboard, and is awkward on
  touch. Pointer dragging can be layered on the same model later.
- **Each widget runs its own query** rather than the dashboard running one and slicing
  it. That costs requests and buys the thing that makes a dashboard useful: widgets can
  ask different questions. TanStack Query dedupes identical ones.
- **A duplicate is always private**, whatever the original was. Inheriting a shared
  audience would publish a draft the moment it was created.
- **Visibility is applied in SQL**, not filtered from a response: a private record
  belonging to someone else is never in the result set.

### Fixed

Four real defects, found by the tests rather than by review:

- Clicking "New dashboard" then Save would **overwrite an existing dashboard**. The
  landing effect re-selected one while the draft was open, and the save target was read
  from that selection. The selection is now derived rather than synced into state by an
  effect, which makes the whole class of bug impossible rather than merely fixed — and
  the ESLint rule about setting state in an effect was pointing at exactly this.
- The page read `.description` off a dashboard narrowed only against `undefined`, so a
  query settling with `null` crashed the whole surface instead of showing an empty state.
- A save mutation resolving with nothing threw inside `onSuccess`, turning a successful
  write into an error the reader could not act on.
- **Reusing a name returned a `500` with the raw SQL in it.** Dashboard and saved-view
  names are unique per owner, enforced by an index that nothing asked about first, so
  naming a second dashboard "Weekly review" failed as an insert — and in any
  environment with debug on, the response carried the constraint name, the column
  list, the workspace and user ids and the whole statement. Both use cases now check
  the name and answer `422 name_already_used` naming the offending dashboard. The
  duplicate endpoint is the exception: it chose "(copy)" itself, so it climbs to
  "(copy 2)" rather than failing the caller for a name they never typed.

### Testing

The E2E harness ran the API on PHP's built-in server with a single worker, which
accepts one connection at a time. Two browser projects run at once and every widget
issues its own analytics query, so a sign-in could queue behind several half-second
aggregations until the browser reported the server unreachable — which reads exactly
like an application bug. `scripts/e2e.sh` now forks workers, and the dashboard spec
namespaces its records per project, because both projects share one database and one
demo account.

## Phase 4 — dashboard and saved-view foundation

Schema, domain rules and ports only. The use cases, API and UI are **not** done, so
the feature is not yet reachable from the product — see docs/ROADMAP.md.

### Added

- `saved_views`, `dashboards` and `dashboard_widgets` tables. Two CHECK constraints
  carry rules the application would otherwise have to remember: a project-scoped
  view cannot exist without a project, and a widget cannot be stored outside the
  12-column canvas.
- `Visibility`, with three states rather than a boolean — the third exists because an
  agency's real need is a dashboard one client's team can see and colleagues working
  for a different client cannot.
- `WidgetType`, which declares the shape of question each type can answer, so a
  share-of-total across 90 dates is refused rather than drawn.
- `GridPlacement`, which raises on an impossible placement rather than clamping:
  clamping moves a widget the reader deliberately placed, and an off-grid widget
  vanishes from the dashboard with nothing to say it was there.
- 12 domain tests covering those rules.

## Phase 4 — analytics interface

### Added

- **ECharts** behind a wrapper that registers only the chart types in use, reads its
  palette from the OKLCH design tokens at render time, resizes via `ResizeObserver`,
  honours `prefers-reduced-motion`, and requires an `ariaLabel` — a canvas is
  invisible to a screen reader, so a chart without one is a hole in the page.
- Chart series tokens (`--ds-chart-1` … `--ds-chart-8`), deliberately separate from
  the platform colours so a chart of four Meta campaigns is not four shades of one
  blue, and lifted in dark mode without changing hue.
- An analytics surface: scorecard, trend chart driven by the tile a reader selects,
  spend breakdown, efficiency-against-scale scatter, and a detail table that keeps
  each entity's platform id.
- A campaign explorer drilling platform → campaign → ad group → ad, where each step
  narrows the scorecard and chart as well as the table.
- An overview screen showing four headline figures and spend by platform.
- `MetricCard`, `SourceBadge` and `SourceNotice`: a null figure renders as an em
  dash with the server's reason beside it, anything not `live` is badged, and the
  notice sits above the numbers so a reader meets the caveat first.
- Full Arabic translation of every new surface, and charts mirrored rather than
  merely translated.
- 42 new frontend tests and 8 new Playwright specs, including one asserting that a
  sandbox surface says so before showing a figure.

### Changed

- The two chart surfaces are lazily loaded. The initial bundle went from 454 kB
  gzipped to 243 kB, with the 209 kB chart chunk arriving on first visit. This also
  required keeping the page components out of `features/analytics/index.ts`: the
  overview screen importing a hook from that barrel pulled the whole engine back
  into the initial bundle.
- `formatCurrency` keeps the minor unit on every amount. It previously dropped the
  decimals above a thousand for density, which is wrong for figures reconciled
  against a platform's invoice — and made a column where 840.25 kept its cents and
  12,500.00 did not.
- The metric vocabulary moved to `shared/metrics`. `shared/ui` needs it, and a
  shared component importing from a feature inverts the dependency — caught by the
  lint rule rather than by review.
- `/analytics` and `/campaigns` nav items are gated on `analytics.view` rather than
  `projects.view_assigned`: offering a surface to a role that would receive a 403 is
  worse than not offering it.
- The demo seeder connects three providers in sandbox mode, binds them and runs the
  real sync. An earlier version of that file said it never would; the reasoning
  still holds, and what makes it safe is the labelling downstream rather than the
  absence of data — a demo workspace with no performance data cannot demonstrate or
  test the analytics layer at all.

## Phase 4 — analytics layer

### Added

- `Metric` and `Dimension` domain enums: 28 metrics with their format, precision,
  direction, dependencies and whether they may be summed across entities.
- `AnalyticsReadModel` and its SQL implementation, answering scorecard, series and
  breakdown from one query with one set of filters.
- `GET /api/analytics` and `GET /api/analytics/catalogue`.
- `analytics.view`, `analytics.export` and `saved_views.manage` permissions, and
  `access:sync` to bring existing workspaces' roles in line with the code.
- 28 analytics tests, asserted against data the real sync wrote from the SANDBOX
  fixtures rather than hand-built rows — so the aggregation cannot agree with
  itself while disagreeing with the ingestion.

### Notable refusals, each returning null with a reason

- **One level only.** A campaign total is the campaign row, never the sum of its
  ads. Both levels hold figures for the same money, and reading them together
  roughly doubles every total.
- **No summed reach.** It counts deduplicated people, so adding two campaigns'
  reach double-counts anyone who saw both. Reported per entity, refused across
  entities, with the count of entities in the message.
- **No cross-currency money.** Converting needs a rate, a date and a decision
  about which rate, none of which belongs in a read model.
- **No ratio from a zero denominator**, and no percentage change from a period
  with nothing in it. "Up from nothing" has no percentage, and +∞ or +100% is a
  number someone will quote in a meeting.

### Fixed

- `SyncAccessCatalogue` reached into Infrastructure from the Interface layer — a
  real layering violation the architecture test caught. It now goes through an
  Application port.
- Two tests used `limit(1)->update()`. PostgreSQL has no `LIMIT` on `UPDATE`, so
  that quietly rewrites the whole table or nothing; they target rows by key.

## TikTok sync

### Added

- `TikTokMapper` and `TikTokSyncAdapter`, mapping structure and the reporting
  envelope from TikTok's own reference: `budget` as a float in the account
  currency, `operation_status` versus `secondary_status`, `{dimensions, metrics}`
  rows with every value a string, and the literal `"-"` sentinel for an
  unavailable metric — which becomes null, never zero.
- 23 mapper tests and 3 cross-provider sync tests, including one proving three
  providers land in the same tables without colliding on campaign ids.

### Deliberately not added

- TikTok's click, video and revenue metric names. Its supported-metrics reference
  is only reachable from inside its own documentation SPA, so those names could
  not be confirmed. The mapper keeps a `VERIFIED_METRICS` list, leaves the
  unverifiable metrics **null**, and carries every unrecognised metric into
  `metadata.unmapped_metrics` untouched. A gap can be labelled; a plausible
  figure in the wrong column cannot be detected.

### Fixed

- TikTok's `bid_price: 0` (returned when bidding is automatic and the real target
  is `conversion_bid_price`) was being read as a bid of zero, which asserts
  something about the campaign rather than reporting an absence.

## Google Ads sync

### Added

- `GoogleAdsMapper` and `GoogleAdsSyncAdapter`, reading campaigns, ad groups, ads
  and insights from SANDBOX fixtures in protobuf-JSON shape. Field names were
  taken from the published **protobuf definitions** rather than the HTML field
  reference, which renders client-side — and that immediately corrected a mapping
  a reasonable guess would have got wrong: v22 has no `metrics.video_views`. The
  metric is `videoTrueviewViews`, and quartile completion exists only as a rate.
- 38 new tests covering micros conversion, shared budgets, `primaryStatus` as the
  real delivery signal, fractional conversions, and the metrics Google simply
  does not publish.
- `ConversionActionType::StoreVisit`, which Google reports as a category.

### Changed

- The sync adapter base class now owns the paging loop and the sandbox gate, so a
  provider contributes only its fixture names and its row mapping. Adding Google
  took a 90-line adapter instead of a 200-line one.
- `EloquentAdvertisingStore` memoises its id and parent maps for the lifetime of
  a sync, invalidating per model on write. Without it every page re-read the whole
  campaign, ad-group and creative tables; a Google sync test went from 34s to
  0.2s.
- Sandbox fixtures now page structure at 2 rows and performance at 50. Structure
  stays tiny so every sync crosses page boundaries and cursor handling is
  exercised; paging a fortnight of ad-level rows two at a time bought no coverage
  and cost every test run.
- The fixture loader supports a `_date_paths` directive, so a provider that nests
  its date (Google puts it under `segments`) gets it where a live response would
  put it.

### Documentation

- `docs/INTEGRATIONS.md` gains the Google Ads field-mapping table, including the
  metrics Google does not expose and why they stay null rather than being
  approximated.

## Phase 3 — campaign data model, sync and normalised metrics

### Added

- Advertising schema: `campaigns`, `ad_groups`, `creatives`, `creative_assets`,
  `ads`, `daily_metrics`, `conversion_metrics`, `sync_runs`, `sync_cursors`,
  `provider_errors`.
- Normalised advertising vocabulary (`EntityStatus`, `CampaignObjective`,
  `BudgetMode`, `ConversionActionType`, `CreativeFormat`, `AdEntityType`,
  `SyncKind`, `SyncStatus`, `ProviderErrorCategory`) and the `Money` /
  `MetricSet` value objects.
- `MetricSource` on every metric row, with aggregate rules: a total inherits its
  weakest input's label, and sandbox or estimated figures never combine with
  actuals.
- `AdvertisingSyncAdapter` port and `MetaSyncAdapter`, reading campaigns, ad sets,
  ads, creatives and insights from SANDBOX fixtures shaped like real Graph API
  responses. `MetaMapper` handles Meta's minor-unit budget strings, decimal spend
  strings, `effective_status`, the ODAX objective rename, and nested `actions`
  categories that must not be summed.
- Idempotent `AdvertisingStore`, a `SyncJournal` recording every run and failure,
  the `SyncExternalAccount` / `SyncBoundAccount` use cases, a per-account-unique
  queued job with provider-aware backoff, and the `advertising:sync` command.
- 51 new tests: Meta normalisation, sandbox fixture loading and pagination,
  advertising domain rules, and an end-to-end sync covering isolation, hierarchy
  linking, null-versus-zero, source labelling, idempotency and the journal.

### Changed

- `Money::toDecimal()` now returns an exact decimal **string** built with integer
  arithmetic, and the lossy float conversion moved to `toFloat()`. A class whose
  reason to exist is that floats cannot hold 0.1 should not hand one back at the
  last step.
- Sandbox-ness moved from the adapter to `Credentials::isSandbox()`: whether a
  connection is a sandbox decides how every number it produces is labelled, which
  is a business rule rather than an adapter detail. A connection counts as a
  sandbox only when *every* required credential is the token.
- `conversion_metrics.attribution_window` is not nullable and defaults to
  `'default'`. PostgreSQL treats `NULL`s as distinct in a unique index, so a
  nullable column there made every re-sync insert a second row — conversions
  doubled on each re-read. Caught by the idempotency test.
- Test memory limit raised to 512M: the architecture tests parse every source
  file, so the suite's need grows with the codebase.

### Documentation

- `docs/DATA_MODEL.md`, `docs/INTEGRATIONS.md` and `docs/ROADMAP.md` updated,
  including an explicit record of why sandbox performance data now exists after
  an earlier decision against it, and what makes it safe.
- `backend/database/fixtures/providers/README.md` documents the fixtures'
  provenance and the single way they depart from a verbatim API response.


## [Unreleased]

### Added — Phase 0: foundation

**Backend**

- Laravel 12 API-only application on PHP 8.4, with the Blade/web scaffolding removed.
- Clean Architecture layers (`Domain`, `Application`, `Infrastructure`, `Interface`)
  plus a composition root, enforced by Pest architecture tests.
- PostgreSQL as the only datastore, with UUIDv7 primary keys on API-facing entities.
- Redis for cache, queue, session and broadcasting; Horizon supervising workers;
  Reverb serving WebSockets.
- `GET /api/health` probing database, cache and queue with a three-valued status
  (`ok`/`degraded`/`down`); 503 only when a dependency is down.
- Security baseline: strict CORS allow-list, Sanctum stateful SPA auth, CSRF, named
  rate limiters (`api`, `health`, `auth`), security headers, stack-disclosure removal,
  validated `X-Request-Id` correlation, secret redaction, production password policy.
- Horizon dashboard access fails closed outside local development.
- Tooling: Pest 4 on PHPUnit 12, PHPStan/Larastan level 8, Pint with strict rules,
  Scramble-generated OpenAPI at `/docs/api`.

**Frontend**

- React 19 + Vite + TypeScript SPA with strict type checking, feature-based structure
  and a lint rule preventing cross-feature deep imports.
- Design system tokens in OKLCH with light and dark themes, bridged into Tailwind v4,
  plus shadcn/ui-style primitives.
- Bilingual shell: Arabic RTL and English LTR with logical layout properties, Arabic
  typography and Latin numerals for figure reconciliation.
- Application shell with rail navigation, a focus-trapped mobile drawer, theme and
  language toggles, skip-to-content and visible focus management.
- Overview page reporting live system health; every unbuilt surface states which phase
  delivers it instead of showing fabricated data.
- Axios client with CSRF priming, locale propagation and a normalised `ApiError`;
  TanStack Query defaults that do not retry definitive 4xx answers.

**Project**

- GitHub Actions CI: backend (Pint, PHPStan, Pest against PostgreSQL and Redis),
  frontend (tsc, ESLint, Prettier, Vitest, build) and end-to-end (Playwright on
  Chromium and mobile Safari).
- `docker-compose.yml` for PostgreSQL and Redis; `Makefile` for setup, dev and verify.
- Documentation set under `docs/`, including six architecture decision records.

### Added — Phase 1 (in progress): identity, workspaces, clients

**Backend**

- Workspace → Client → Project schema with UUIDv7 keys, status check
  constraints and the indexes scoped queries depend on.
- Permission catalogue derived from one enum; six system roles with explicit,
  non-hierarchical permission sets; enforcement in the Application layer behind
  an AuthorizationGuard rather than in controllers or route middleware.
- Sanctum session authentication, workspace switching re-verified against live
  membership on every request, and configurable rate limits with secure
  defaults.
- Clients end to end: search, status filters, pagination, create, edit, domain
  status transitions and soft delete.
- Append-only audit trail covering sign-ins, failed attempts and every client
  mutation, with secrets redacted from recorded diffs.

**Frontend**

- Sign-in page, session gate, and a 401 handler that returns the person to sign
  in from anywhere rather than leaving a shell of failing requests.
- Account menu with workspace switching; switching discards every query cached
  for the previous workspace.
- Permission-aware navigation, and a clients surface with real loading, empty,
  filtered-empty, forbidden and error states, in English and Arabic.

**Projects**

- Full lifecycle with the assignment model: visibility restricted in SQL for
  roles without `projects.view_all`, assignment as the grant that reveals a
  project, and removal as the revocation.
- Domain status transitions with spend permission recorded on every change; a
  project cannot be moved to another client.
- SPA surface that distinguishes "you are not assigned to anything yet" from
  "this workspace has no projects", because they call for different actions.

**Administration**

- Workspace member administration — role changes, suspension, reinstatement and
  removal — with the last active owner protected against demotion, suspension
  and removal.
- Audit log viewer, workspace-scoped, filterable to security events, showing who
  acted and from where.

**Fixed**

- `SESSION_DOMAIN` was set to `localhost`, so no cookie was issued for
  `127.0.0.1` and sign-in silently failed to persist. Found by exercising the
  real browser flow.
- The actor context was not cleared when no active workspace could be resolved,
  so a member suspended or removed mid-session could continue acting on
  permissions they no longer held. The guard now denies an unbound context
  outright.

### Added — Phase 2: integrations foundation

- Seven provider adapters (Meta, Google Ads, TikTok, Snapchat, X, LinkedIn,
  ChatGPT Ads) behind one stable port, each declaring the capabilities it
  actually implements. An architecture test fails the build if an adapter
  claims something its platform cannot do.
- `ProviderConnection → ExternalAccount → ProjectIntegrationBinding`: the
  binding is the only thing that lets a project read an ad account. Connecting
  a platform and discovering its accounts expose nothing by themselves.
- Credentials encrypted with AES-256-GCM before storage, redacted when dumped,
  hidden from serialisation, and never returned by the API — which reports only
  which keys are held.
- `BLOCKED_EXTERNAL` made executable: real credentials produce a 503 naming
  what is missing and stating that the gap is the provider's approval rather
  than the operator's mistake; a platform with no published API produces a 501
  and declares no capabilities at all.
- Sandbox mode exercises the full workflow against clearly labelled `SANDBOX`
  fixtures — account structure only, never a fabricated metric.
- Integrations surface showing every platform's real status, what each can do,
  which credentials are still needed, and per-project access grants, in English
  and Arabic.

### Blocked

- Live ad-platform handshakes are `BLOCKED_EXTERNAL` pending credentials and provider
  approvals; see `docs/INTEGRATIONS.md`.
- Presentation identity awaits the Smart Code reference deck; the theme/token
  abstraction is built first.
