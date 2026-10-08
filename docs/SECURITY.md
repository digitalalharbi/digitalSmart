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
| Rate limiting              | `api`, `health`, `auth`, `webhooks`, `shared-reports` limiters, budgets configurable with secure defaults |
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

**Why the login limiter is per account as well as per IP.** A per-IP budget
alone is both too strict and too weak: too strict for an office behind one
address, too weak against an attacker spreading attempts across addresses. The
`auth` limiter applies both, so raising the per-IP budget for a shared address
— or for an end-to-end run — never weakens the protection around an individual
account.

**Why request ids are validated.** An attacker-supplied header lands in log lines; an
unvalidated value allows forged log entries. Only UUIDs are accepted; anything else is
replaced with a freshly generated id.

**Why a shared report link is treated as a credential.** `GET /api/shared-reports/{token}` is
the only route in this product that answers without a session, so the token is the whole
authorisation. It is 48 random bytes, stored as a SHA-256 hash plus a 12-character hint and
looked up by hash — the plaintext exists once, in the response that created it, and no endpoint
returns it again. `expires_at` is NOT NULL and capped at 90 days, because a link with no end is a
credential with no end: it outlives the engagement, the account manager and sometimes the
relationship. The route is constrained to `[A-Fa-f0-9]{96}`, has its own per-address limiter
because there is no user to key one to, and **answers a token nobody issued exactly as it answers
a revoked one** — telling a visitor the difference between "never existed" and "turned off" tells
anybody enumerating tokens when they have guessed one. Views are counted and not logged: an agency
may know whether the report was opened, and a log of a client's reading habits is data this
product has no reason to hold. See `docs/REPORTING.md`.

**Why a client's access is a grant and not a role.** `analytics.view` sat on the client role until Phase 22, which
meant every client in a workspace shared one decision taken once about a category. What a client may read is now
four grants on their own portal, each exposed by a named person at a recorded time and revocable on its own, with
every change filed as a security event. Who counts as a client is `portal.read`, held by no agency role, because
project assignment says what somebody works on and not who they work for — a creative is assigned to a client's
project and is plainly not their employee. See `docs/PORTAL.md`.

**Why no client identity is sent to a language model.** The assistant's brief carries findings, figures and each
finding's subject — the account, a platform, measurement — and no client name, project or campaign. Sending a third
party "Alhambra's cost per click doubled" discloses a commercial fact about a named company; sending "the account's
cost per click doubled" discloses a number. The model key itself is BLOCKED_EXTERNAL: none is held here, and the
assistant works without one because the product computes every figure. See `docs/INTELLIGENCE.md`.

**Why authorization runs before validation.** An endpoint that validates first tells an unauthorised caller which
parameter they left out, and a 422 naming a parameter is a statement that the endpoint exists and would answer.
Two revenue reads had that ordering wrong and were corrected in Phase 22.

**Why making a change on a platform is its own permission.** `provider_writes.execute` is held by no role that
only decides. Approving a change and being the credentials that move a client's money are different acts, and a
workspace that collapsed them would have the person who authorised a budget rise also be the only record that it
happened. The same split is why the carry-out audits the attempt *before* the request leaves: an outcome of
`unknown` has no response to record, and an entry written beforehand is the only thing that can say a request was
sent at all. A write against sandbox credentials carries SANDBOX into the audit entry for ever, because a trail
recording a simulated pause as a real one is worse than no trail. See `docs/PROVIDER_WRITES.md`.

**Why a provider write is refused where the API is merely likely to support it.** `Provider::writeSupport()`
answers `documented`, `unverified` or `not supported`, and only the first permits an attempt. `unverified` is not
a smaller yes: TikTok and X almost certainly do accept these writes, nobody here has read the current reference
for them, and attempting one on that basis would be guessing with somebody else's budget. The endpoint and fields
actually read are recorded per provider and written into every attempt's audit entry, so a reader a year later
can tell which contract the attempt was built against.

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
