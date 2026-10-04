# Security

## Non-negotiables

1. **Never store a client's platform password.** Access is obtained through OAuth,
   Business Manager / Business Center sharing, partner access or account IDs. There is
   no field, form or column anywhere in digitalSmart for a client's platform password.
2. **Authorization is server-side.** Hiding a control in the SPA is a usability choice,
   never a security boundary. Every endpoint enforces a policy or permission gate.
3. **Tenant isolation is enforced in the query**, not in the view. A record outside the
   caller's workspace/project returns 404.
4. **Credentials are encrypted at rest** with Laravel's `encrypted` cast (AES-256-GCM
   via `APP_KEY`). Tokens are never logged, never returned by an API resource and never
   placed in a URL.

## Implemented in Phase 0

| Control                    | Where                                                        |
| -------------------------- | ------------------------------------------------------------ |
| Strict CORS allow-list     | `config/cors.php` — origins from `FRONTEND_URL`, no wildcard  |
| SPA session auth           | Sanctum stateful API; encrypted session cookies               |
| CSRF                       | `XSRF-TOKEN` cookie + `X-XSRF-TOKEN` header, sent by the API client |
| Rate limiting              | `api`, `health`, `auth` limiters (`RouteServiceProvider`)     |
| Security headers           | `SecurityHeaders` middleware (CSP, nosniff, DENY, Referrer-Policy, Permissions-Policy, HSTS on TLS) |
| Stack disclosure removed   | `X-Powered-By` / `Server` stripped at both Symfony and SAPI level |
| Request correlation        | `AssignRequestId`; inbound ids accepted only if valid UUIDs, so logs cannot be injected |
| Secret redaction           | `dontFlash` covers passwords, tokens and client secrets        |
| Password policy            | 12+ chars, mixed case, numbers, symbols, breach-checked in production |
| Mass-assignment protection | `Model::unguard(false)`; attribute discarding throws in non-production |
| Horizon dashboard          | Fails closed — empty allow-list means nobody, outside local dev |

## Threat notes

**Why 404 instead of 403 across tenants.** A 403 confirms a resource exists. For an
agency platform where competitors may be clients of the same workspace, existence is
itself sensitive.

**Why the API has a deny-all CSP.** The API returns JSON only. If a response is ever
coerced into being rendered as a document, the policy prevents script execution. The
SPA is served separately and carries its own policy.

**Why request ids are validated.** An attacker-supplied header lands in log lines; an
unvalidated value allows forged log entries. Only UUIDs are accepted; anything else is
replaced with a freshly generated id.

**Why the queue is "degraded", not "down".** Treating a stalled worker as a hard outage
would take the whole platform offline for a non-critical dependency. The distinction is
encoded in `ServiceStatus`.

## Secrets

- `.env` is git-ignored; `.env.example` documents every key with an empty value.
- Provider credentials are `BLOCKED_EXTERNAL` until issued — see `docs/INTEGRATIONS.md`.
- Rotate `APP_KEY` only with a planned re-encryption of stored credentials; it is the
  key that protects every provider token.

## Reporting

Security issues are not filed as public issues. Report them to the Smart Code
engineering owner directly.
