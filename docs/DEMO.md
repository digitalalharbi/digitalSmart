# The sandbox agency

A realistic Smart Code working day, on your own machine, with nothing live in it. Every figure is a **SANDBOX** fixture
— not spend, not provider data, not a client's — and every screen of the demo workspace says so.

This is development documentation. The accounts below exist only in a local database built by the command below, and
the command refuses to run in production.

## Start it

```bash
# once: API, database and the web app
cd backend && php artisan migrate && php artisan demo:agency   # rebuilds around itself; safe to re-run
php artisan serve --port=8001                                   # the API
cd ../frontend && npm run dev                                   # the app, on the port Vite prints
```

`php artisan demo:agency --fresh` wipes the *local* database and rebuilds everything from nothing.

Open the app and sign in with any account below. The password for all of them is `password`.

| Role | Account | Lands on |
| --- | --- | --- |
| Executive / owner | `owner@demo.digitalsmart.test` | the agency overview: managed spend, ranking, project health |
| Administrator | `admin@demo.digitalsmart.test` | the overview, plus Administration |
| Project manager | `manager@demo.digitalsmart.test` | their projects and the campaigns to look at first |
| Media buyer | `buyer@demo.digitalsmart.test` | their campaigns, their spend, what is drifting |
| Finance | `finance@demo.digitalsmart.test` | budgets at risk, plan against pace against forecast |
| Creative | `creative@demo.digitalsmart.test` | the creative pipeline and desk |
| Client | `client@demo.digitalsmart.test` | their own portal and nothing else |

## What is in it

- **Sixty clients** across eight industries, with names that read like an agency's list; a hundred and fifty or so
  projects, each dated, with a team.
- **Seven platforms** — Meta, Google Ads, TikTok, Snapchat, X, LinkedIn, ChatGPT Ads — each holding *sandbox
  credentials* (the integrations page says so), and **GA4, Tag Manager, Salla, Zid and HubSpot** sources per client.
- **Sixty days of daily figures** shaped like real accounts: launch ramps, steady growth, decline, creative fatigue,
  recovery, overspend and broken tracking; weekends dip, pay-day weeks spike. Awareness campaigns never show a ROAS.
- **The working day:** campaigns healthy, declining, overspending, ending soon; tracking healthy, silent and never
  read; budgets approved, planned by platform, ahead and behind; leads in every stage; orders, some traced to
  marketing; creative in every state with files and decisions; tasks (some overdue); approvals waiting; client
  requests; reports with frozen snapshots; client portals for the first five clients; this morning's digests.

Everything the seeder writes is tagged (`agd-` in a slug or external id, `is_demo` on clients and projects, `source =
sandbox` on every daily row), so re-running replaces exactly what it made and touches nothing else.

## The journeys, proven

`frontend/e2e/journeys.spec.ts` walks one working day per role against this data, and runs only when the stack was
built with it:

```bash
E2E_AGENCY=1 scripts/e2e.sh e2e/journeys.spec.ts --project=chromium
```

A — the media buyer finds their campaigns, opens one and raises a task about it. B — the project manager opens a
project and works through its tabs. C — finance reads budgets, then plan, pace and forecast. D — the creative opens
the desk, switches view and opens a request. E — the client reads only their own portal, is turned away from the
agency's pages, and the API says no rather than the page merely hiding a link. F — the executive goes agency →
client → project → campaign.

`frontend/e2e/responsive.spec.ts` measures every main surface at phone width, in English and Arabic, and fails if any is
wider than its screen.

## What it deliberately is not

- **Not live.** Real provider connections stay `AWAITING_CREDENTIALS`; nothing here calls a platform. Live
  commissioning is a separate, explicit step (`docs/LIVE_COMMISSIONING.md`).
- **Not a way to change a real campaign.** The only action offered on a campaign is adding a task about it; changing
  a live status is not offered at all until the approvals flow can carry it out (`docs/PROVIDER_WRITES.md`).
- **Not the Smart Code presentation identity.** Decks use a provisional theme until a reference deck is supplied.

## Known limits

- Server-written sentences — a campaign's health reason, an attention item, a provider's status — are English in the
  Arabic interface. The product's own labels are translated; the sentences the server composes are not yet.
- A saved view cannot yet be pinned; ready-made views (critical campaigns, budget risk, my leads…) cover the common
  morning questions.
