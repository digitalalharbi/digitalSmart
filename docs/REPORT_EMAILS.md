# Reports to a client: the link, the staff digests, and the email

**State on 2026-11-05: built and CI-tested; not deployed, and no email has ever left this product.** Nothing here is
`LIVE_VERIFIED`. A green CI run and a working localhost are not evidence of either.

Three things are easy to confuse and are kept apart in the code, the schema and the interface.

| | What it is | Who receives it | State |
|---|---|---|---|
| **1. Share link** | `/shared-reports/{token}` — a client-safe report read with no account. Live or frozen, pausable, revocable, expires within 90 days, token stored as a hash (`docs/REPORTING.md`) | Whoever holds the link | `IMPLEMENTED_NOT_VERIFIED` — needs a public server |
| **2. Staff digests and alerts** | `digests:performance`, `alerts:raise` — daily/weekly/monthly summaries and alerts in the notification centre, per person, in their own scope | Workspace members | `IMPLEMENTED_NOT_VERIFIED` in-app; email/WhatsApp copies `AWAITING_CREDENTIALS` |
| **3. Report email to a client** | A fresh link to one client-safe report, emailed to one outside address, weekly or monthly | Somebody outside the agency who agreed | `IMPLEMENTED_NOT_VERIFIED`; sending is `AWAITING_CREDENTIALS` |

A **scheduled export** (`reports:run-schedules`) is none of these: it makes a file for a member to download. It emails nobody.

## 3. The report email

`reports.share` is required to add, list, pause, resume or remove a recipient; the report must be `client_safe`.

- **Consent is recorded, not assumed.** Adding a recipient needs a sentence saying how they agreed (`consent_note`,
  a `CHECK` refuses an empty one). It is kept with the row and in the audit entry.
- **Once per period.** `report_email_deliveries` is unique on `(subscription_id, period_key)` — ISO week or month. A second
  run, or a second worker, finds the period claimed.
- **Re-authorised on every send.** The send acts as the member who added the recipient and makes the link through
  `ShareReport`. If they left, lost `reports.share`, can no longer see the report, or it stopped being client-safe, the
  subscription is `halted` with the reason and nothing is sent.
- **The email carries a link and the report's title — no figures.** Each email gets a new link (14 days weekly, 45 monthly).
- **The recipient can stop.** Every email has a `List-Unsubscribe` header and a link to `/report-emails/unsubscribe/{token}`;
  the stop is a POST behind a button, so a mail scanner unsubscribes nobody. The token is stored hashed and replaced with each
  email. The agency cannot resume somebody who unsubscribed.
- **Retry.** A failed period is retried on later hourly runs, three attempts in all, then left `failed` for a person.

### What a delivery row can say

`pending` · `provider_accepted` · `failed` · `awaiting_credentials` · `skipped`.

There is deliberately **no `delivered`**. `provider_accepted` means the mail server took the message. Arrival, bounce and
complaint are the provider's later knowledge and need its webhook, which is not built (below).

### The mail transport

`LaravelMailTransport` sends only when all three hold, and otherwise records `awaiting_credentials` and mints no link:

1. `MAIL_MAILER` is a mailer that transmits (`log` and `array` do not);
2. `MAIL_FROM_ADDRESS` is on a domain the agency controls (placeholders are refused);
3. `MAIL_DELIVERY_ENABLED=true` — a separate switch, so credentials present on a staging server are not a decision to
   email clients from it.

All three live in the server's `shared/.env`. No mail credential belongs in GitHub, a workflow or a document
(`docs/EXECUTION_POLICY.md`).

## What only the owner can supply

| # | Needed | Why |
|---|---|---|
| 1 | A server (staging or production): host, SSH user, port, deploy path, host key; a domain with TLS; PostgreSQL, Redis, a supervisor for Horizon, cron for `schedule:run` | Nothing public exists; `AWAITING_SERVER_ACCESS` (`docs/DEPLOYMENT.md`) |
| 2 | A mail provider account (SMTP or API) and its credentials, entered on the server | The product has no sender |
| 3 | A sender domain with SPF and DKIM published (DMARC recommended), and the `From` address to use | Without them the message is refused or lands in spam |
| 4 | A decision: which provider, and whether bounce/complaint webhooks are wanted | Decides whether `delivered` / `bounced` can ever be written |
| 5 | A real test inbox the owner controls | The only acceptable proof of arrival |
| 6 | Written approval before the first email to a real client | A send is outward-facing and not reversible |

## Acceptance — to be run on a real server, and recorded here when it is

Not run. Each line needs evidence (a timestamp, the deployed sha, a screenshot or a log line) before any status changes.

1. Deploy a named sha; `GET /api/health` answers 200; `schedule:run` and Horizon are running.
2. Create a client-safe report on data labelled as test data; open its share link in a browser with no session, on a phone
   and a desktop, in Arabic and English; pause, resume and revoke it; confirm an expired link says so.
3. Add the test inbox as a recipient with a consent note; wait for `reports:email-due`; confirm the row reads
   `provider_accepted` **and the message is in the inbox**; open the link from the email.
4. Run the command again in the same period: no second message.
5. Demote the member who added the recipient: the next period is `skipped` and the subscription `halted`.
6. Press the unsubscribe link: the next period sends nothing.
7. Turn `MAIL_DELIVERY_ENABLED` off: the next period is `awaiting_credentials` and no link is made.

## Not built

- Provider webhooks for delivered / bounced / complained (needs the provider chosen in row 4).
- Email copies of staff digests and alerts: `HonestChannelDelivery` still records `awaiting_credentials`; wiring it to the
  transport is a separate change, after one real send has been proven.
- A PDF attachment. The email carries a link on purpose: a forwarded link expires, a forwarded file does not.
- WhatsApp delivery.
