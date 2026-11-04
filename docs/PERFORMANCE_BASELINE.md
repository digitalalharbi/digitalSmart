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
| `GET /api/attention` | **249 KB** | 745 ms | **471 ms** | **600 ms** |

## Reading it

- Everything but `/api/attention` answers in under 60 ms once warm. `/api/attention` is the outlier on both time and size and is the first
  optimisation target: its payload is larger than every other endpoint combined, and the Overview reads it on every visit.
- Not measured: LCP, INP and CLS (no browser trace yet), query counts per request, the front-end bundle, concurrent load, and anything on
  a real server. Do not quote these numbers as production performance.
