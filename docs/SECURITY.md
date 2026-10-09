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

**Why an uploaded file's type is read from its bytes.** An extension and a `Content-Type` header are both chosen
by the uploader, so a file called `brief.png` that is an HTML document passes every check that reads either —
and is then served from this product's own origin, which is a script running as whoever opened it. The type is
sniffed with `finfo` and held to an allow-list that excludes SVG, because an SVG is a document that can carry
`<script>`. The stored name is generated, so nothing of the uploader's reaches the filesystem; downloads are
always `Content-Disposition: attachment` with the stored type, a `default-src 'none'; sandbox` policy and
`nosniff`, so a PDF carrying JavaScript is a file on a disk rather than a document in this origin. **There is no
virus scanner**, which is stated in `docs/OPERATIONS.md` rather than implied, along with where one would go.

**Why no attachment has a URL.** Every read goes through the API, because the audience rule — which decides
whether a client may see a given file — lives there. A signed bucket link would be a second place that rule has
to hold, and the first time the two disagreed a client would be reading an internal file. Downloads are audited
for the same reason a share is: who took a copy of somebody else's file is a question an argument turns on.

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

**Why health is two endpoints rather than one.** `/up` answers whether the process is alive and touches no
dependency; `/api/health` answers whether it can serve and probes four. Collapsing them is a security problem
as much as an availability one: a liveness probe that fails because Redis is unreachable restarts a healthy web
process, which turns a degraded dependency into an outage and loses the in-flight requests that would have
told somebody what was actually wrong. The readiness endpoint is rate-limited on its own budget because it is
the one endpoint an unauthenticated caller is meant to poll.

**Why a failed job is a reportable state.** It is work somebody believes happened and did not — a sync that
leaves yesterday's spend on a dashboard, a webhook that leaves an order out of a client's revenue. Silent
abandoned work is how a product comes to show figures it cannot defend, so any failure at all degrades the
health report rather than waiting for a threshold. See `docs/RUNBOOKS.md`.

## Changing a password

`POST /api/auth/password`, needing a session and **no permission at all**: a password belongs to the person, not
to a role, and a media buyer must be able to change theirs without holding `workspaces.update`. There is no
`user_id` parameter, so there is no shape in which this request reaches somebody else's account.

**The current password is required.** A session can be left open on a shared machine, and without the check
whoever found that screen could lock the owner out of their own account. A wrong one is recorded as a failed
attempt, like a rejected sign-in: repeated attempts against a live session is a signal somebody is sitting at a
machine that is not theirs.

**Every other session ends; this one does not.** Somebody changes their password *because* they think it is
known — a shared laptop, a phone left on a desk, a colleague who has gone — so leaving the other sessions signed
in would mean the change achieved nothing. The caller's own session survives, because signing somebody out of
the screen they are looking at reads as a failure.

That property needs middleware to be true, and saying it without the middleware would have been a false claim.
`AuthenticateSessionForSpa` stores the password hash in the session and compares it on every request, so a
session opened with a password that has since changed fails its next request. It is a subclass for one reason:
`auth:sanctum` makes `sanctum` the default guard for the rest of the request, and the framework's version then
asks a `RequestGuard` for `viaRemember()` and raises a 500 on every authenticated read — caught immediately by
the existing authentication tests. A test asserts the behaviour directly, by putting a stale hash in a session
and watching the next request fail.

**Twelve characters, and no composition rule.** No "one capital and one symbol": those push people towards
`Password1!`, and a long passphrase beats a short mangled word. The minimum lives on the use case so the form
rule and the server's refusal cannot disagree.

**Nothing reports strength.** A meter is a model's opinion rendered as a fact, which is what this product
refuses to do with a figure a client sees and refuses to do with a reader's own password.

**The audit entry carries the fact and the time**, and never the password, its hash or its length.
`bootstrap/app.php` has scrubbed `current_password` from flashed input since Phase 0 — one of two traces that
this operation was always meant to exist. The other was `AuditAction::UserPasswordChanged`, declared with nothing
recording it until now.

### Granting access to a client's work

**Project membership is the only thing that grants sight of a client's data.** A lead, a store's revenue, a
campaign's spend, a client's own portal — each is reachable by the people on the project it belongs to, plus
whoever holds `projects.view_all`. `ActorVisibilityResolver` says it in four lines and every read in the product
narrows by it.

Until this slice there was **no way to grant it.** `GET`, `POST` and `DELETE /api/projects/{project}/members`
existed, were tested, and nothing in the application reached them: the projects page showed a member count and
no control. So an administrator could add a colleague — and then not give them access to anything. The
onboarding flow ended with somebody who could sign in and see an empty product.

`GET /api/projects/{project}/assignable` serves the candidates: **active** workspace members who are **not
already on the project**, with ids, names and their workspace role — no email addresses. Both conditions matter.
A suspended member would be a choice that grants nothing, and somebody already assigned would be a no-op the
reader has to work out for themselves.

| Decision | Why |
| -------- | --- |
| The candidate list is the project module's own, not `OwnerDirectory`'s | That one answers "who can already see this thing", for deciding who may own a piece of work. This is almost its inverse: the people worth offering are the ones who cannot see the project yet |
| They join in the role they hold in the agency | People are almost always on a project in their own role, and the member directory is where a role changes. Asking twice would be asking a question nobody has a different answer to |
| An empty project says what that means | "Nobody is on this project" and, underneath, that the client's work is then visible only to people who can see every project. A blank list leaves a reader to infer it |
| Removing somebody states the consequence | They lose sight of that client's work, **including anything already in their name**. It is the one control in the dialog whose effect reaches outside it |
| A reader without `projects.manage_members` sees the list and no controls | Who can see a client is worth knowing for anybody who can see the project; changing it is a separate permission |

### Adding a colleague, without an email to send

`POST /api/members`, needing `users.invite` — a permission that had been granted to owners and administrators
and **checked nowhere** until this route existed. `AuditAction::WorkspaceMemberInvited` and
`MembershipStatus::Invited` were the other two traces: three pieces of vocabulary describing an operation the
product could not perform. Roles could be changed, memberships suspended and people removed, but the workspace
was closed to anybody new.

**No invitation email, and the product says so rather than implying one is on its way.** The response carries a
one-time password for the administrator to convey. That is a real weakness and the only thing that makes it
acceptable is that it expires on use: `must_change_password` makes `RequireChosenPassword` refuse every request
except reading the session, changing the password and signing out. "An administrator knows your password" is
therefore true for one sign-in rather than indefinitely.

| Decision | Why |
| -------- | --- |
| 32 random characters | Nobody has to remember it — it is read out once and replaced. The only cost of length is the reading |
| Returned once, stored nowhere | Never in plaintext, never logged, never audited. If the administrator loses it there is nothing to look up, which is the property that makes a read-out password tolerable |
| `MembershipStatus::Invited` is **not** used | A member in that state cannot sign in at all, so without an email-based activation route it would be an account nobody could ever open. The status stays reserved for the flow that is blocked |
| An existing address joins rather than duplicates | One account per person on an installation. No password is returned, because none was created: they keep the one they chose, and resetting it would lock them out of the other workspace |
| The owner role is not offered | An owner can remove other owners, so creating one is a decision for somebody already in that position. `ChangeMemberRole` is where it happens, with the last-owner protection around it |
| `email:rfc`, not `email:rfc,dns` | A DNS rule makes form validation depend on a lookup, and is wrong about real addresses: a domain that publishes no MX record still receives mail through its A record |

The forced dialog is the one modal in the product with **no cancel**. The server answers nothing else, so a
cancel button would close it onto a product that refuses every request; the way out is signing out, which the
copy points at.

### Removing somebody, and the work in their name

`DELETE /api/members/{user}`, needing `workspaces.manage_members`. Three things make this safe rather than
merely possible.

**Their identity survives.** The user account and every audit entry stay; only the membership goes. An audit
entry that cannot be attributed is not evidence, so removal never touches the trail of what somebody did.

**Their open work is released, and the counts are returned.** A schema sweep found four tables carrying an
assignment — `tasks.assignee_id`, `leads.owner_id`, `client_requests.owner_id`, `creative_requests.owner_id` —
and before this, removal left a departed person's name on all four. That is not a cosmetic problem: it is a board
showing items owned by somebody who cannot open them, a workload chart counting a person who has gone, and a
client request waiting on a reply from nobody. The work comes back as **unassigned**, which is the fact, and
every board filters for exactly that.

| Decision | Why |
| -------- | --- |
| Released, not reassigned | Handing eleven items to one colleague from a dialog nobody can see the work from would be a worse outcome than publishing the truth. Unassigned is honest and actionable; a guessed owner is neither |
| **Open** work only | A task somebody completed is the record of who completed it. Open is each module's own predicate — `TaskState::isOpen`, `ClientRequestState::isOpen`, `CreativeState::isOpen`, a lead neither closed nor merged away — rather than a list this repository keeps in a second place |
| Released **after** the membership row goes | So a concurrent assignment cannot land between the two and leave one item in a removed person's name |
| The counts are returned and audited | "What happened to Noura's twelve tasks" is asked months later, and the answer has to be in the record rather than inferred from a board that has since moved on. `workspace.member_removed` carries them in `changes.released` |
| Its own audit action | Removal used to be recorded as `workspace.member_suspended`. One is somebody expected back whose work waits for them; the other is somebody gone whose work was handed back. A trail that calls the second the first answers the question wrongly |
| **Suspension releases nothing** | A suspended member is expected back and their work waits for them; the directory shows the status beside their name. Removal is the irreversible one, which is why it is the one that hands the work back |
| The consequence is shown before the click | `GET /api/members/{user}/held-work` returns the same four counts for somebody still here, so the dialog says what it is about to release. "Remove" over a name holding eleven open items and "remove" over a name holding none are different decisions, and a single unconfirmed button rendered them identically |
| The count is behind the same permission | How loaded a named colleague is is a manager's business. `404` for somebody who is not a member of this workspace, rather than a confident zero about a stranger |

### Not built: resetting a forgotten password

A reset needs an email, and this repository has no sender identity — the same block that defers notification
digests. Everything else about it would be real work with a fake outcome: a token this product generates, stores
and expires, and then cannot deliver. It is recorded here rather than half-built, and the honest interim is that
an owner or administrator can set a member's password out of band.

## Secrets

- `.env` is git-ignored; `.env.example` documents every key with an empty value.
- Provider credentials are `BLOCKED_EXTERNAL` until issued — see `docs/INTEGRATIONS.md`.
- Rotate `APP_KEY` only with a planned re-encryption of stored credentials; it is the
  key that protects every provider token. `APP_PREVIOUS_KEYS` has to be set **before** `APP_KEY` changes, or
  every stored credential becomes unreadable and has to be re-issued by its provider — runbook 6 in
  `docs/RUNBOOKS.md` has the order, and what to do if it was already done in the wrong one.
- Database dumps are client data. `.gitignore` refuses `/storage/backups/`, and a dump must never live in a
  bucket the application itself can read: a credential that can reach the backups makes every control above
  decorative.

## Reporting

Security issues are not filed as public issues. Report them to the Smart Code
engineering owner directly.
