# Performance baseline — local, 2026-11-04

A first measured baseline, so later changes have something to be compared with (DS-P0-UX-01 asks for before/after on the same
dataset, device and network). **It is a local development figure, not a production one**: PHP's built-in server with four workers,
PostgreSQL and Redis on the same laptop, the sandbox agency dataset (`demo:agency`), signed in as the demo owner.

Method: after one cold request, 20 sequential requests per endpoint with `curl`, time to last byte; p50 and p95 of the 20.

| Endpoint | Payload | Cold | p50 | p95 |
|---|---:|---:|---:|---:|
| `GET /api/auth/me` | 1.7 KB | 41 ms | 27 ms | 49 ms |
| `GET /api/clients` | 10 KB | 36 ms | 27 ms | 31 ms |
| `GET /api/reports` | 13 KB | 54 ms | 29 ms | 33 ms |
| `GET /api/analytics?metrics[]=spend&date_preset=30d` | 13 KB | 485 ms | 47 ms | 53 ms |
| `GET /api/attention?limit=6` (what the Overview asks for) | 5 KB | 636 ms | **468 ms** | **571 ms** |
| `GET /api/attention?limit=25` | 17 KB | 487 ms | 454 ms | 492 ms |
| `GET /api/attention` (the whole queue, 425 items) | 249 KB | 455 ms | 457 ms | 489 ms |

## Reading it

- Everything except `/api/attention` answers in under 60 ms once warm.
- `/api/attention` costs about 460 ms **whatever the limit**: asking for 6 items shrinks the payload from 249 KB to 5 KB but not the time,
  so the cost is the server assembling eight streams (425 items in this dataset), not the transfer. That makes it the first optimisation
  target, and the lever is the assembly (or a short, scope-keyed cache), not a smaller response. A cache must key on workspace, the
  reader's visible projects, permissions and the requested streams; it is not done here.
- **Where the 460 ms goes (profiled 2026-11-04, test database seeded to 100 clients / 2,000 campaigns, after `ANALYZE`):** 425 ms in all,
  of which the campaigns stream is about 330 ms (two queries — it runs the control centre's health assessment over every campaign), budgets
  about 57 ms, and the other seven streams 2–10 ms each. So the cost is one assessment, not the number of streams. Moving the health filter into
  SQL would put a second copy of the health rules beside the policy, which the project avoids on purpose; the safe levers are a short cache
  keyed as above or assessing fewer campaigns, and neither is done. Without planner statistics the same request took 2.5 s, which is why a
  freshly loaded database should be analysed.
- Not measured: LCP, INP and CLS (no browser trace yet), query counts per request, the front-end bundle, concurrent load, and anything on
  a real server. Do not quote these numbers as production performance.
