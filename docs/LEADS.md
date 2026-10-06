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

## Attribution uses one vocabulary with the orders

The same `AttributionEvidence` levels, the same confidences, the same database CHECK requiring the evidence to
be backed by a field on the record. A cross-surface report comparing leads with revenue is therefore comparing
two figures held to one standard of proof, rather than two standards.

`by_campaign` names a campaign only where the evidence can name one, and the unattributed row is always
produced. For leads that bucket is usually **enormous** — most enquiries carry no parameters, and a great many
arrive by phone — so hiding it would make every named campaign look responsible for a far larger share of the
pipeline than it is.

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

- **No CRM bridge.** `LeadSourceKind::Crm` exists and nothing populates it. A CRM integration needs a named
  provider, its OAuth, and a decision about which system owns a stage when both have one — that last part is a
  product question rather than an engineering one.
- **No automatic lead from an order.** `store_order_id` exists on the table and the link is not created
  automatically, because "every customer is a lead" is true for some clients and nonsense for others.
- **No merge of two leads a human knows are one person.** The event type is reserved; the operation needs a UI
  and a decision about which record's history survives.
- **No scoring.** A lead score is a model, and a model presented as a fact is the thing this product refuses.
  It belongs with the AI phase, labelled as modelled.
- **No frontend surface.** The Lead Center's endpoint is complete and tested.

## Tests

- `backend/tests/Unit/Domain/LeadsTest.php` — the absent source kinds, the stage semantics, every rate and its
  denominator, and the four spellings of a Saudi phone number.
- `backend/tests/Feature/Leads/LeadPipelineTest.php` — recording, deduplication, the attribution upgrade, the
  disqualification reason, the reopened lead, the signals column, and the media buyer who may read but not
  qualify.
