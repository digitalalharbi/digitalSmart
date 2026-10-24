# Access requests and enquiries

A visitor asks for an account or sends an enquiry; the people who answer requests try it, decide, and — for an account — invite the person, who
creates the account by accepting. One table (`access_requests`), widened; not a CRM.

```
Visitor → /request-access or /contact → NEW → CONTACTED / WAITING_FOR_REPLY / QUALIFIED → APPROVED | REJECTED | CLOSED
                                                                                   │
                                          approved account request → invitation → accepted (account created) → first sign-in
```

## Public side

- `POST /api/public/access-requests` — one generic `202` whether the request was kept, was a repeat, was dropped by the honeypot, or the
  installation takes none. Rate limited per address, a hidden field, a 16 KB body limit, no account created, nothing sent.
- Kinds: `account_access`, `general_enquiry`, `demo_request`, `partnership`, `other`. Preferred contact: email, phone, WhatsApp. `need` is asked
  only of an account request.
- A repeat (same normalised email, or same normalised phone, inside 30 days) is **kept and linked** (`possible_duplicate_of`), never dropped; the
  visitor cannot tell. The earliest of a run is the one later ones link to, so there are no chains.
- `GET /api/public/invitations/{token}` and `POST …/accept` — the same answer for any token that is not a live invitation.

## Statuses and milestones

Statuses are what the people answering decide: `new`, `contacted`, `waiting_for_reply`, `qualified`, `approved`, `rejected`, `closed`. What happens to
an approved person afterwards are **milestones with their own times**, not further statuses: `invited_at`, `invitation_shared_at`,
`invitation_opened_at`, `accepted_at`, `account_user_id`, `first_login_at`.

| Milestone | Evidence |
|---|---|
| shared | a person pressed "I have sent it". Nothing here sends the link. |
| opened | the invitation page was loaded with the token (the SPA's call, not a link preview) |
| accepted / account created | the invited person submitted the page; only then is a user and membership written |
| first sign-in | the first successful sign-in of that user (`LogIn`), once |

## Contact is recorded, never claimed

"Mark email sent", "call attempted", "contacted", "WhatsApp contacted" record what a **person says they did**. There is no mail or messaging credential,
so both channels report `awaiting_credentials` on the page and in the API, and no event, status or invitation is ever "delivered". An attempt
sets `first_attempt_at`; only "contacted" / "WhatsApp contacted" set `first_contacted_at` and move a `new` request to `contacted`.

## Response time

Per workspace (`access_request_settings`: 1 minute to 7 days, default 60) and a default owner. Judged by the lead pipeline's own rule (`LeadSla`):
within / approaching (75%) / overdue, and **attempted ≠ reached**. A request put away untried is shown as closed, not overdue.
`access-requests:alerts` (every five minutes) tells the people who may answer requests, through the notification centre (dedupe, cooldown, severity,
mute settings): received, approaching, overdue, approved, and an invitation unaccepted for 48 hours. Notification text names no one.

## Approval and invitation

Approving (account requests only) creates nobody. An invitation is a separate act: a non-owner role and the projects the person will reach. The
token is 48 random bytes, stored only as a SHA-256, shown once, valid seven days; making a new one withdraws the old. Accepting creates the user with
the password **the person chose** (twelve characters minimum, nobody else ever knows it) or, for an address that already has an account,
joins it to the workspace without touching its password.

Rejecting requires an internal reason; a message for the person is optional, kept on the request, and shown nowhere public.

## Audit

`access.request_created|assigned|status_changed|contacted|noted|approved|rejected`, `access.invitation_created|accepted`. The entries carry kinds, roles and
counts — never a name, address, number, note, reason, token or password (tested). Approval and the invitation actions are security events.

## Not built

Email and WhatsApp delivery (`AWAITING_CREDENTIALS`); reassignment on breach; business-hours response clocks; a public "contact us" link on the landing page
(the route exists; the page stays one screen with *request an account* and *sign in*).
