# 3. Sanctum stateful session auth for the first-party SPA

- **Status:** Accepted
- **Date:** 2026-10-04

## Context

The SPA and API are first-party. The alternatives were bearer tokens in browser storage,
Sanctum's stateful cookie mode, or a full OAuth2 server (Passport).

## Decision

Sanctum stateful ("SPA") authentication with encrypted session cookies and CSRF
protection. Sanctum API tokens remain available for machine clients. Passport is
explicitly out of scope unless digitalSmart itself becomes an OAuth2 provider.

OAuth connections to ad platforms are **integrations**, not application auth, and never
mix with the user's session.

## Consequences

- Tokens never touch `localStorage`, so XSS cannot exfiltrate a long-lived credential.
- CSRF becomes relevant: the API client primes `/sanctum/csrf-cookie` and sends
  `X-XSRF-TOKEN`; CORS must be a strict allow-list with credentials enabled.
- SPA and API must share a registrable domain in deployed environments, and
  `SANCTUM_STATEFUL_DOMAINS` must list the exact host *and port*.
