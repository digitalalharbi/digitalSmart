# Reporting

A saved question, the figures that answer it today, a frozen copy of the figures that answered it
last month, and a link that lets a client read either one without an account.

Phase 21 delivers the **report builder**, **saved reports**, **snapshots**, **CSV and XLSX export**, and
**shared links** with their own unauthenticated read. What is not built is listed at the end.

## The question this module is for

Every other surface in this product is read by the people who built it. A report is the one
artefact that leaves the building: it is pasted into a deck, quoted in a meeting the agency is not
in, and read six weeks later by somebody deciding whether to renew.

That changes what correctness means. A dashboard that is briefly wrong is a dashboard somebody
refreshes. A report that is wrong is a sentence an account manager has already said out loud. So
this module spends its effort on four things a reporting feature usually leaves to the reader:

1. **Whether the comparison is arithmetically sound** — computed, not assumed.
2. **Whether the figures are live or frozen** — stated on the document, every time.
3. **Whether the period has finished** — stated, because a month-to-date report is the one most
   likely to be compared against a whole month.
4. **Who can read it, and until when** — a link is a credential, and it expires.

## A report is a rule, not two dates

`App\Domain\Reporting\ReportWindow` stores a period as a rule — `last_30_days`, `last_month`,
`month_to_date` — rather than as a pair of dates. A report saved in October still answers its
question in November without being edited, which is the only version of "saved report" that gets
used twice.

Two rules come with it.

**Every relative window ends yesterday.** Today is still accumulating: a platform that has
reported four hours of a day will make the last point of every chart dip, and a reader who sees
that dip reads it as a drop in performance. Only `month_to_date` deliberately includes today, and
it declares itself incomplete.

**`custom` is the only window with dates**, and a CHECK enforces both-or-neither: a custom window
with one date is a report that renders a period nobody chose.

## Comparability is computed

`App\Domain\Reporting\Comparability` is the centrepiece. "Spend is up 40%" is the most quoted
sentence an agency produces and it is worthless when the two periods are not the same length. So
three refusals are computed rather than left to the reader:

| Code | What it catches |
| ---- | --------------- |
| `unequal_length` | 30 days against a calendar month, or this month-to-date against all of last month. The percentage measures the calendar as much as the campaigns. |
| `period_incomplete` | A running period against a finished one. Equal in length only because the earlier window was cut to match, and the current one is still filling. |
| `overlapping` | Periods that share days, so part of the change is the same data counted twice. |

A fourth code, `not_requested`, separates "we will not show you a change" from "you did not ask
for one" — a distinction the surface needs, because the first requires a sentence and the second
requires silence.

**What `Comparable` does not mean.** It means the arithmetic is sound. It does not mean the
comparison is *meaningful*: comparing Ramadan with the month after it is a bad comparison and no
code here can know that. Claiming otherwise would be the more dangerous error, because a reader
who has been told a comparison is valid stops thinking about it. The caveat on
`same_period_last_year` and `previous_period` says so in the vocabulary, on the builder, before
the report exists.

**When a comparison is refused, the reason replaces the percentages.** It is one sentence above
the sections rather than a footnote below them, because a reader who finds no comparison and no
explanation does the arithmetic themselves — against whatever period they happen to pick.

## A shape decides which metrics it will accept

`App\Domain\Reporting\SectionKind` publishes, per shape, which metrics it takes and how many:

| Shape | Cap | Accepts | Carries a comparison |
| ----- | --- | ------- | -------------------- |
| Headline figures (scorecard) | 6 | any metric | yes |
| Over time (trend) | 4 | any metric | no |
| A ranking (breakdown) | 3 | additive metrics only | no |
| A table | 8 | additive metrics only | no |

**Non-additive metrics are refused in a ranking or a table**, which is `Metric::isAdditiveAcrossEntities()`
doing the work: reach counts *people*, deduplicated, so the same person reached by two campaigns
is one person. A per-platform reach column is not a number that is merely wrong — it is a number
with no meaning. The builder does not offer it there at all, rather than offering it and having
the server refuse: a form that offers something the server rejects teaches people to distrust the
form.

**Only the scorecard carries the comparison.** A trend already shows change as its shape, and a
ranking's rows are a different set of entities in each period — "Meta is up 12%" against a
ranking that did not contain Meta last month is a sentence with no referent.

## Snapshots, and why they are not a cache

A snapshot stores the rendered payload and never recalculates it. It is the only way to answer
"the report said 1.2 million" months later, when the platform has since restated the figures — and
platforms do restate: late conversions, refunds and currency corrections all move a number that
was correct when it was read.

The stored payload is returned **unchanged**. Not re-rendered, not merged with today's figures,
not partially refreshed. The moment it was taken is inside the payload, so the document says when
it was true.

`RenderMode` — `live` or `snapshot` — is on the rendered report and on the badge in the header.
A link that quietly recomputed would make "the report said this" unanswerable.

## Audience is a rewrite, not a filter

`ReportAudience` has two values and the distinction is load-bearing.

A **client-safe** report is one written to be read by the client: in its title, in which metrics
are on it, in what was left out. It is not the internal report with the notes stripped. So
marking a report client-safe is an edit somebody makes deliberately, and a new report defaults to
**internal** — a default of client-safe would make a report written in shorthand shareable by
omission.

`internal_note` is never read on a client-safe render. Not filtered after the fact:
`RenderReport` does not load it on that path, and `ShareReport` refuses to share an internal
report at all. `ReadSharedReport` then passes `ReportAudience::ClientSafe` explicitly rather than
taking it from the report, so no future configuration can make the unauthenticated path serve an
internal render. Three locks on one door, because the thing behind it is an agency's private
commentary about a client.

## A link is a credential

`report_shares` holds a token the server cannot read back:

- **48 random bytes**, hex-encoded to 96 characters.
- Stored as its **SHA-256 hash**, plus a 12-character hint so a row is identifiable in the
  interface.
- Looked up **by hash**. There is no endpoint that returns a token, and the interface says so when
  it shows one: a link somebody lost is a link they should replace rather than recover.
- `expires_at` is **NOT NULL**, capped at `ShareReport::MAX_DAYS` (90). A link with no end is a
  credential with no end — it outlives the engagement, the account manager and sometimes the
  relationship.
- Revocation requires a **reason**, enforced by a CHECK. Somebody revokes a link because something
  went to the wrong address, and that is a fact worth keeping.

`ShareState::Expired` is **derived, never stored**. A stored `expired` would need something to
write it, and whatever that is would be the thing that fails to run — leaving a link that is
expired in principle and working in practice.

**Views are counted, not logged.** A share row carries a count and a last-viewed time. Not who,
and not when each time: an agency legitimately wants to know whether the client opened the
report, and a log of a client's reading habits is data this product has no reason to hold.

## The one unauthenticated read

`GET /api/shared-reports/{token}` is the only route in this product that answers without a
session. Four things about it are deliberate.

**The token is in the path and nothing else is.** No report id, no client id, no date parameter —
no input a visitor could change to reach a different document. The route is constrained to
`[A-Fa-f0-9]{96}`, so anything that is not a token shape never reaches the application.

**It always answers 200 with a state.** A revoked or expired link gets a sentence, not a 404: a
link a client was sent returning "not found" reads as a broken site, and they ask for it again
rather than learning it lapsed.

**A token nobody issued gets the same answer as a revoked one.** Telling a visitor the difference
between "never existed" and "turned off" tells anybody guessing tokens when they have guessed one.

**It is rate-limited separately** — `RATE_LIMIT_SHARED_REPORTS`, twenty a minute per address. The
authenticated API's limit is per user; this one has no user, and a bare token in a URL is the one
thing in this product worth brute forcing.

The page behind it (`/shared-reports/:token`) is outside the application shell and outside
`RequireSession`: no sidebar, no bell, no account menu, no navigation of any kind. There is
nowhere else to go from there, and a chrome full of controls leading to a sign-in screen would
invite a visitor to try them. It renders the state in the reader's own language rather than
echoing the server's English, and it offers no export — a visitor holds a token, not an account,
and a download route that accepted one would be a second unauthenticated read to get right.

## The CSV is a document, not a dump

`ReportCsv` writes a header block before the rows: the report name, the period, the source state,
whether the figures are live or frozen, and the comparison warning if there is one. A CSV that
opens as bare numbers loses every qualification the screen carried, and the screen's
qualifications are the reason this module exists.

Three details:

- **Major units.** A spreadsheet is read by a person, not by the API, and a column of halalas is
  a column of wrong numbers.
- **An unavailable figure writes its reason**, not a blank. A blank cell in a spreadsheet becomes
  a zero in the next formula somebody writes against it.
- **Formula injection is blocked.** A cell beginning `=`, `+`, `-` or `@` is prefixed with an
  apostrophe. A campaign name is attacker-controlled text as far as a spreadsheet is concerned.

## The workbook is the same document, typed

`XlsxWriter` writes the same render as a spreadsheet, and the difference is the one thing a CSV cannot do:
**types**. In a CSV every cell is text, so a column of spend arrives in Excel as text and the first thing
anybody does is retype it; a date arrives as a string and sorts alphabetically, which is fine until a period
crosses a year. Here a number is a number, a date is a date, money carries its currency in the cell's own
format and a percentage is a true percentage that survives being charted.

Both exports stay. A CSV is what a script reads; a workbook is what a person opens.

Three decisions worth naming:

- **The caveats are repeated on every sheet.** A workbook makes this harder than a CSV rather than easier,
  because a reader opens the tab they were sent to and never sees sheet one. The source state, the period and
  the comparability verdict are at the top of every section's sheet. A file whose warnings live on a tab nobody
  opens is a file with no warnings.
- **An unavailable figure is written as its reason, as text, in a numeric column** — where it is visibly not a
  number and refuses to be summed. A blank cell would be added as zero by whoever opens the file next.
- **The source state is in the file's own properties**, not only in its cells. A workbook built from sandbox
  fixtures and one built from a client's real spend are identical as numbers, and only one may be acted on.

The export is tested by **opening the file** with the same library a spreadsheet application would use — the
assertion is about the artefact, not about the code that wrote it — and the end-to-end suite takes delivery of
the download and checks it is a readable archive.

## What the schema enforces

Ten CHECK constraints across four tables, each with an obvious wrong version that is invisible
once the data is in. The ones worth naming:

| Rule | Why it is in the schema |
| ---- | ----------------------- |
| `btrim(name) <> ''` on a report | An unnamed report in a list is a report nobody opens. |
| A custom window has both dates or neither | One date renders a period nobody chose. |
| Custom dates are ordered | A reversed range is an empty report that looks like a quiet month. |
| A section has at least one metric | An empty section renders as a heading with nothing under it. |
| Every section metric is a known metric | A typo in a payload would otherwise become a column nobody can explain. |
| A ranking or table has a dimension; the others have none | Half a grouping renders as a grouping and groups nothing. |
| A snapshot's period is ordered | A frozen copy is the one thing nobody can re-derive. |
| `expires_at` NOT NULL | See above: a link with no end is a credential with no end. |
| Revocation has a reason | The record of why a client's link stopped working. |

The window, comparison, audience and section-kind vocabularies are CHECKs generated from their
enums, so the database and the domain cannot drift.

## Permissions

| Permission | What it opens |
| ---------- | ------------- |
| `reports.view` | Read saved reports and run them |
| `reports.manage` | Build, change and delete reports, and take snapshots |
| `reports.share` | Create and revoke links that let somebody outside the workspace read a report |

Sharing is **its own permission**, and a media buyer has the first two and not the third. A buyer
reporting on their own performance to a client, with nobody reading it first, is the one review
this module exists to keep. The interface says why rather than hiding the control: hiding it
produces a support question, the sentence produces a conversation.

Project visibility applies as everywhere else — a reader without `projects.view_all` sees reports
narrowed to their assignments, in SQL.

## Where the figures come from

Nothing here computes a metric. Every section is one `AnalyticsReadModel` call, so a report
carries exactly the same figures, the same source states and the same unavailability reasons as
the analytics surface — including `MetricSource::weakest()` rolling up to the section and then to
the document. A report whose weakest section is `SANDBOX` says `SANDBOX` in its header.

This is why the module is Phase 21 and not Phase 6: a reporting feature with its own aggregation
is a second set of numbers to reconcile, and the reconciliation always happens in front of a
client.

## What is not built

- **PDF export.** CSV and XLSX both ship; PDF is intended as a print stylesheet rather than a headless
  browser — a rendering pipeline that can disagree with the screen is a second source of truth. The
  presentation module takes the same position for the same reason, and says so on the page.
- **Scheduled delivery.** No "email this on the 1st". It needs a sender identity and a bounce
  story, and a report that silently failed to send is worse than one nobody automated.
- **Annotations.** "Spend dropped because the account was suspended on the 14th" is the single
  most useful sentence on a report and it has nowhere to live yet.
- **Pivot-style ad-hoc exploration.** The builder composes fixed shapes; an arbitrary pivot is a
  different product and would need its own honesty rules about denominators.
- **Branding on the shared page.** It carries the product mark and the report, not the agency's
  logo. A white-label surface is Phase 22's decision to make, with the client portal.
