# Leads

How digitalSmart records an enquiry, and the one sentence the whole area is built around.

> **A lead is a person who did something. A click is not a lead.**

## Why that sentence is load-bearing

An agency that reports WhatsApp clicks as leads reports a cost per lead a fraction of the real one, wins the
next month's budget on the strength of it, and cannot explain afterwards why the client's sales did not move.
The same applies to form views reported as submissions and taps reported as conversations.

The product makes the mistake structurally impossible rather than merely discouraged:

- `LeadSourceKind` has **no member** for a click, a tap or a form view. Every one of its members is a
  completed action by a person: a form **submitted**, a message **received**, a call **answered**, an order
  **placed**.
- There is exactly one way a lead enters the product — `RecordLead` — and it takes a `LeadSourceKind`. No code
  path leads from a counted signal to a lead row, because no value exists to pass.
- Counted signals do appear in the Lead Center, side by side with the leads and **never summed with them**.

## Signals and leads, side by side

The measurement layer already counts intent signals — `whatsapp_click`, `phone_click`, `form_start` — as daily
event totals. The Lead Center shows those in their own column.

Four hundred taps and one conversation is a real shape for a month, and showing both answers the question an
agency actually has: *are our taps turning into conversations?*

**No ratio is published between the two columns.** A tap and a lead do not share a denominator: the same person
taps three times, taps and then phones instead, or taps on a device the form never loaded on. The gap is
information; a percentage would be a claim. The response carries a note saying so instead of a rate.

## Every rate has a denominator somebody could defend

The arithmetic lives in `LeadFunnel`, in the domain, because a win rate written as a SQL expression is a win
rate nobody reviews — and the wrong denominator is the commonest defect in this whole area.

| Rate | Denominator | Why not the obvious one |
| ---- | ----------- | ----------------------- |
| Win rate | **Closed** leads | Dividing by every lead calls a pipeline a fortnight old a disaster, because it is mostly open. An open lead is not a loss. |
| Qualification rate | Leads somebody **reached** | A lead nobody has phoned yet has not failed to qualify. |
| Contact rate | Leads that **arrived** | The operational figure, and the one that says a client is paying for leads nobody rings before the win rate does. |

**Every rate returns null rather than zero when its denominator is empty.** `0%` and "nothing to measure" look
identical on a card and mean opposite things: the first invites a comparison with last month, the second does
not.

There is deliberately **no lead-to-click or lead-to-session rate**, because there is no honest denominator for
one here. Sessions come from an analytics property and leads from a form or a CRM; dividing one by the other
assumes every session had the chance to convert. That comparison belongs where both numbers' sources are
named, and it is Phase 17's problem.

## Contacted is not contact

`contacted` means somebody tried. `qualified`, `disqualified`, `won` and `lost` mean somebody got through.

Three voicemails are not contact, and counting them as such is how a client comes to believe their leads were
worked. `first_contacted_at` is therefore set when a lead reaches a stage that means contact, not when
somebody marks it as attempted — and `hours_to_first_contact` is derived from it.

That figure decides whether a lead was worth buying. A lead contacted within an hour converts at a different
rate from one contacted next week, and without it an agency cannot tell a bad campaign from a slow sales team.

## A disqualification must say why

Enforced in the use case *and* by a database CHECK.

"Not a real enquiry", "out of area", "price" and "wrong product" lead to four different conversations with a
client paying for leads. A disqualified pile with no reasons is the least useful thing an agency can hand back.

The reason is cleared when a lead moves on, because leaving "wrong product" attached to a lead that was later
qualified makes the record say something nobody believes. And a lead that is reopened loses its closing time,
because a stale `closed_at` makes every cycle-time figure quietly wrong — a CHECK requires the two to agree.

## Recognising the same person twice

`ContactPoint` normalises a phone and an e-mail for matching only. The normalised values are stored in
`phone_key` and `email_key`, are never shown, and are never matched on a name — two people called Mohammed
Al-Harbi are two people, and merging them would put one client's enquiry in another's record.

**A Saudi number arrives four ways.** `+966555001122`, `00966555001122`, `0555001122` and `555001122` are one
phone, and an agency that counts them as four leads reports four times the volume at a quarter of the cost.
The leading zero is a national trunk prefix rather than part of the number, which is why dropping it is correct
here and would be wrong for a country that keeps one.

E-mails are lower-cased and trimmed and **nothing more**. No Gmail dot-stripping: treating `a.b@gmail.com` and
`ab@gmail.com` as one person is right for Gmail and wrong for a server that distinguishes them, and merging
two real people is worse than counting one twice.

### The deduplication window

Twenty-four hours, and the number is a judgement rather than a constant of nature. Within it, the same phone
submitting twice is almost always one person resubmitting; beyond it, it is a new enquiry about something else.
Both errors cost something, and a day is where an agency's own instinct puts the line.

An enquiry with **no contact details at all** is recorded as a new lead every time. There is no way to tell two
anonymous enquiries apart, and refusing the second would lose one that happened.

### A follow-up can improve an attribution, never weaken it

A real pattern: an in-app browser strips the campaign parameters, the customer opens the site properly and
submits again. The second arrival's attribution is taken **only when it is stronger**, and then wholly rather
than field by field — a half-merged attribution (this submission's click id beside the previous one's campaign)
would be a link to a campaign nobody can verify.

Blank fields are filled from a later arrival; populated ones are not. The first touch is the one that earned
the credit.

## Merging two leads a human knows are one person

`POST /api/leads/{lead}/merge`, needing `leads.manage`, after reading `GET /api/leads/{lead}/duplicates`.

The roadmap recorded this as blocked on "a decision about whose history survives". That decision came apart
into four, and each had an answer this product's own rules already implied. They live in `LeadMergePlan`, which
is the one place to read them.

**Both histories survive.** The absorbed lead's `lead_events` move to the survivor, and the absorbed row is
**kept and marked**, never deleted. Three reasons: a client may already have been told a lead count, and a row
that vanishes makes last month's report unreproducible; `lead_events` cascades from the lead, so deleting it
would delete the trail of everything that happened before anybody realised the two were one person; and the
absorbed lead keeps its own `external_id`, which is where the next sync of a CRM or form provider will look —
if the row were gone the sync would create a duplicate and the merge would have to be repeated forever.

**The earlier arrival's age survives, whichever record does.** Time-to-first-contact and pipeline duration are
measured from `received_at` and they are the figures that tell a slow sales team from a bad campaign. If a merge
could reset that clock, every merge would shorten every speed-of-response number and flatter whoever did the
merging. The survivor always ends up with `min(received_at)`.

**A CRM-sourced lead is always the survivor**, even when it arrived second — the same position
`ChangeLeadStage` takes. Merging one away would leave the CRM syncing a record this product had stopped
counting, and the two would disagree forever with nobody able to see why. **Two CRM leads cannot be merged
here at all:** that belongs in the CRM, which holds both, and the refusal says so.

**The stronger attribution survives, wholly.** Exactly the rule a re-arrival follows, including the part about
equal evidence: two leads at the same level of proof are not an improvement on each other, and swapping one
campaign for another would change what a client is told for no reason anybody could give.

**The surviving stage is asked for, never inferred.** A lead that bought and a lead that enquired again are
both real, and which stage the merged record belongs in is not derivable from either. The caller states it and
it must be **one of the two leads' current stages** — a third would move a lead through a pipeline without
recording a move, and the review that asks "who decided this, and when" would find nothing. The `closed_at` and
the disqualification reason come from whichever lead held the chosen stage, so a closure keeps its real date
instead of being dated to the day somebody tidied up two records.

### What else moves, and what does not

| Field | Rule |
| ----- | ---- |
| Name, email, phone, message, landing page | Blanks filled from the absorbed lead. Each is an independent fact about one person — knowing their phone from one enquiry and their email from another leaves both true — which is why this is not the "half-merge" the attribution rule forbids |
| Owner | Taken only where the survivor has none |
| Value | Taken only where the survivor has none, and never converted between currencies. Where both leads carry a value, the absorbed figure is written into the merge note rather than dropped: somebody stated it, and it is part of the history even when it is not the figure the record carries |
| `first_contacted_at`, `qualified_at` | The **earlier** of the two. If somebody was contacted on the Tuesday, they were contacted on the Tuesday, whichever record recorded it |
| `external_id` | Stays on the record that owns it. Nothing is moved, so each provider's re-read still finds its own row |

### What the suggestions will and will not match on

Exact matches on a normalised email address or phone number, within the same client, excluding anything already
merged away. **No fuzzy names, no score, no "looks similar".** A merge is not reversible by any control this
product offers, so the only evidence it acts on is evidence that cannot be wrong about identity — and every row
carries `matched_on_label` saying which contact point matched, because a suggestion with no evidence beside it
is one somebody accepts without looking.

There is **no time window here**, unlike the deduplication above. That window exists so this product does not
decide on its own; this is a human asking the opposite question, and an enquiry from the same number six months
ago is exactly what they want to see.

### Afterwards

A merged-away lead is counted **nowhere**: both the pipeline list and the Lead Center's funnel exclude
`merged_into_id IS NOT NULL`. `GET /api/leads/{id}` is the deliberate exception, so a reader following an old
link gets the record and can be sent to the survivor rather than a 404.

**The journey is the other deliberate exception.** It still shows both enquiries, because it is a timeline of
what happened to a person and both of them did happen — the merge decided they were one person, not that one of
the enquiries never arrived. That is what makes "they came back in September" readable.

Refusals come back as `leads_cannot_be_merged`: the same lead twice, two different clients (which would move
one client's enquiry into another's count), a lead already merged away (a chain would make "how many leads is
this really" unanswerable), two CRM leads, and a stage neither lead is in. Every merge is audited as
`leads_merged`, naming the survivor, the absorbed lead by id, and whether the age and the attribution were
inherited — because both change figures a client may already have been shown.

## Working a pipeline: ownership, value and notes

The pipeline list had columns for all three and nothing in the product could fill them. An owner column nothing
can set names whoever imported the lead; a value column nothing can set is empty on every row a CRM did not
produce; and with no notes the only record of a conversation is the stage it ended in, which turns a pipeline
review into several people remembering differently.

### The owner has to be somebody who can see the lead

`GET /api/leads/{lead}/owners` serves the list, and `POST /api/leads/{lead}/owner` refuses anybody not in it.
The reason is specific rather than procedural: a lead carries a prospect's name and phone number, so assigning
one to somebody whose projects do not reach that client would put their name in the owner column of a record
they can never open — **and the notification telling them would be correctly suppressed by the same visibility
rule, so nobody would ever find out.** The column would name a person who does not know.

The three conditions are the ones `PermissionAwareRecipientResolver` already applies to notifications: active
membership, `leads.view`, and visibility of the client. `EloquentOwnerDirectory` mirrors it, and a test pins the
two together — somebody the directory offers must be somebody the assignment notification reaches.

The directory is **shared with the task board and the intake queue**, taking the permission as a parameter:
three copies of a visibility rule is a rule that disagrees with itself in the one place nobody has looked. See
[OPERATIONS.md](OPERATIONS.md#who-work-can-be-given-to).

The list carries **ids and names only**. A picker needs no email addresses, and the people in it did not agree
to have theirs listed to whoever is working a pipeline.

**Unassigning is a first-class outcome.** A pipeline that can only ever add an owner accumulates leads belonging
to whoever touched them first, and every workload figure after that describes a team that does not exist.
Nobody is notified: there is no new owner, and telling the person it was taken from would be a complaint rather
than a task.

**A CRM-sourced lead can still be assigned.** Ownership is not a stage. The CRM owns where a deal stands in its
own pipeline; who in this agency is chasing it is this product's business.

### A value is said, never inferred

`POST /api/leads/{lead}/value`. There is no "average deal size" anywhere in this product and this is the use case
that would have needed one: a value taken from an average is a figure about *other* leads wearing this one's
name, and once it is in the column nothing downstream can tell it from a quote somebody actually gave.

- **Clearing it is not the same as zero.** Null means nobody has said; zero is a claim that the lead is worth
  nothing, which somebody has to make deliberately. Both are accepted and they are different fields in the audit
  entry.
- **A value needs a currency**, because a number with no unit is not a figure — and this workspace has clients
  in more than one. Nothing is ever converted.
- **A CRM-sourced lead is refused**, exactly as its stage is: the CRM holds the amount, and a figure typed here
  would be overwritten by the next sync — after both numbers had been quoted to the same client.

### Notes, and the history that makes them worth writing

`POST /api/leads/{lead}/notes` writes into `lead_events`; `GET /api/leads/{lead}/history` reads it back. The read
is the point: **a note nobody can see is a worse surface than having no notes at all**, because somebody records
what happened on a call and the next person reviewing the pipeline never learns it is there.

The history needs `leads.view` rather than `leads.manage`. Reading a pipeline is how it gets reviewed, and a
media buyer may read the pipeline — cost per *qualified* lead being the only honest measure of their work — while
not being allowed to write to it.

**"Who did this" has an honest answer including when it is nobody.** A CRM sync and an arrival have no actor, and
the row says so rather than filling in whoever happened to be signed in. An invented answer is worse than an
absent one, and the question is exactly what a pipeline review asks.

Adding a note is the one write in this module that is **not** audited, deliberately: the note *is* the record. It
carries its author and its time in `lead_events`, which is what an audit entry would have said, and writing both
would be the same sentence in two places where only one is ever read. It also notifies nobody — a bell for every
note is a bell that gets silenced, and then an assignment is missed.

## Attribution uses one vocabulary with the orders

The same `AttributionEvidence` levels, the same confidences, the same database CHECK requiring the evidence to
be backed by a field on the record. A cross-surface report comparing leads with revenue is therefore comparing
two figures held to one standard of proof, rather than two standards.

`by_campaign` names a campaign only where the evidence can name one, and the unattributed row is always
produced. For leads that bucket is usually **enormous** — most enquiries carry no parameters, and a great many
arrive by phone — so hiding it would make every named campaign look responsible for a far larger share of the
pipeline than it is.

## A total needs a currency, or it is not a total

Money on the Lead Center is **nullable, and arrives with the currency it is in**. Null means the
figure is not reportable, which is the case whenever the window's valued leads came in more than
one currency.

Postgres adds minor units across currencies without complaint, so the obvious implementation
produces a confident figure made of two different units: 250,000 halalas plus 100,000 fils is not
350,000 of anything. It is not wrong by a percentage — it is not a number.

So the read model selects `count(distinct currency)` alongside every sum, and where that count is
not exactly one it reports null amounts and a null currency, with `currency_count` saying how many
there were. The **counts are untouched**: a lead is a lead whatever it was priced in, and only the
money is withheld. The interface then says *"these leads carried two different currencies, so their
totals are not shown — filter to one client to see a total"*, which is a fact somebody can act on,
rather than a dash nobody can account for.

No conversion is attempted. A converted total needs a rate, a date for that rate, and a decision
about who owns it; a product that picked one silently would be inventing the most quotable number on
the page.

## Permissions

`leads.view` and `leads.manage`.

| Role | View | Manage |
| ---- | ---- | ------ |
| Admin | yes | yes |
| Project manager | yes | yes |
| Media buyer | **yes** | no |
| Creative | no | no |
| Client viewer | no | no |

A media buyer reads the pipeline, because a cost per *qualified* lead is the only honest measure of their work.
They do not qualify leads: **judging the quality of what you bought is not a judgement to hand to the buyer.**

## Personal data

`leads` holds names, e-mail addresses, phone numbers and messages belonging to the agency's **client's
prospects** — not to the agency. The model hides the contact columns by default, no resource includes them in a
client-portal response, and `docs/SECURITY.md` carries the retention question.

## What this phase does not do

- ~~**No CRM bridge.**~~ Built, with HubSpot as the named provider — see `docs/CRM.md`. The product question
  this note raised turned out to be inferable from this product's own rules rather than a preference: **the CRM
  owns the stage**, because two systems both writing it means whichever wrote last wins and the figure a client
  sees depends on the order of two jobs. `ChangeLeadStage` refuses a CRM-sourced lead and says where to move it
  instead. A stage's *meaning* comes from the metadata the CRM publishes about it and never from its label, which
  is the same refusal this product applies to a tag name.
- **No automatic lead from an order.** `store_order_id` exists on the table and the link is not created
  automatically, because "every customer is a lead" is true for some clients and nonsense for others.
- ~~**No merge of two leads a human knows are one person.**~~ Built — see
  [Merging two leads](#merging-two-leads-a-human-knows-are-one-person). The "decision about which record's
  history survives" turned out to be four decisions, each answerable from this product's existing rules: both
  histories survive, the earlier arrival's age survives whichever record does, a CRM-sourced lead is always the
  survivor, and the stronger attribution moves wholly. The only thing left for a human is the surviving stage,
  which is asked for because it is a judgement and not derivable from either record.
- **No scoring.** A lead score is a model, and a model presented as a fact is the thing this product refuses.
  It belongs with the AI phase, labelled as modelled.
- **No cross-source rate.** There is deliberately no lead-to-click or lead-to-session figure. Sessions come
  from an analytics property and leads from a form or a CRM; dividing one by the other assumes every session had
  the chance to convert. The two columns are shown side by side instead — see above.

## The surface

`frontend/src/features/leads` — the Lead Center page, and the first place in this product where the
honesty rules are visible rather than merely enforced.

**The funnel has four steps, and the fourth is not "qualified".** Arrived → somebody tried → got
through → won. Each is a genuine superset of the next, so the bars can only shorten. The available
qualified figure is how many leads sit at that stage *now*, and a lead that has since been won passed
through it without being counted in it — so placing that number between "got through" and "won" would
draw a funnel that narrows and then widens, from which a reader would reasonably conclude that leads
were being won without qualification. The chart says why the step is missing, beside the chart.

The bars use the **ordinal ramp** rather than eight categorical hues, because swapping two of these
stages would change the meaning: the order is the information. See
[DATA_VISUALISATION.md](DATA_VISUALISATION.md).

**Taps sit in their own chart with their own axis**, in a different colour slot from the lead charts,
with both totals stated in one sentence and no percentage between them. One shared axis would invite
the eye to read the two as parts of one quantity, which is exactly the mistake the endpoint was built
to prevent.

**Time to first contact is a column, not a detail**, and a lead nobody has rung shows "not yet"
rather than zero hours — which is the opposite of true and the most actionable thing on the row.

A disqualification collects its reason in the interface before sending, because the server refuses
one without it and because that reason is the only record of why somebody judged a prospect
worthless.

Every chart carries a table twin, and the pipeline's own figures are reachable without a pointer or
colour vision.

## Tests

- `backend/tests/Unit/Domain/LeadsTest.php` — the absent source kinds, the stage semantics, every rate and its
  denominator, and the four spellings of a Saudi phone number.
- `backend/tests/Feature/Leads/LeadPipelineTest.php` — recording, deduplication, the attribution upgrade, the
  disqualification reason, the reopened lead, the signals column, the media buyer who may read but not qualify,
  and the three currency cases: one currency named, two currencies withheld, none reported.
- `frontend/src/features/leads/LeadCentrePage.test.tsx` — the refusals as the reader meets them: no rate between
  taps and leads, qualified absent from the funnel with its reason, a null rate explained rather than shown as
  zero, a withheld mixed-currency total, the unattributed row still present, and a single record called what it
  is.
- `frontend/e2e/leads.spec.ts` — the same refusals in a browser, in both languages, plus the table twin and the
  role that may not see the surface at all.
