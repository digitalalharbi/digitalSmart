# The CRM bridge

`LeadSourceKind::Crm` existed from Phase 15 and nothing populated it. The note in `docs/LEADS.md` said why: a CRM
integration needs a named provider, its OAuth, and *"a decision about which system owns a stage when both have
one — that last part is a product question rather than an engineering one."*

That decision is made here, and the answer turned out to be inferable from this product's own rules rather than
from a business preference.

## The CRM owns the stage

A lead that came from a CRM is a **read** of that CRM. Its stage is moved there, not here.

The alternative is to let both systems write it, and that fails in a specific way: whichever wrote last wins,
nobody can tell which that was, and the figure a client is shown depends on the order of two jobs. A sales team
loses a day's work to a sync and the product that lost it reports success.

So `ChangeLeadStage` **refuses** a CRM-sourced lead, with a message naming where to do it instead — somebody
working a pipeline needs an instruction rather than a rule — and the pipeline list puts that sentence where the
controls would have been rather than hiding them. A reader who cannot see why the buttons are missing concludes
the product is broken.

The one exception is the sync itself, which is not an exception: a re-read is the CRM answering again. That is the
opposite of the rule for every other source, where a human owns the stage and a later arrival must not touch it,
and it is why the field on `LeadInput` is called `crmStage` rather than `stage`.

## A stage's meaning comes from metadata, never from its name

This is the rule the whole bridge turns on, and it is a refusal more than a mapping.

A CRM's stages are named by the people using it: "Proposal sent", "Mudawalat", "Stage 3", "Hot". Reading a
meaning out of a label is the mistake this product refuses everywhere else — a tag called `purchase_confirmed` is
not evidence of a purchase — and the difference between a product that can be trusted with a client's revenue and
one that cannot is whether it guesses here.

HubSpot's deal stages publish `probability` (0.0–1.0) and `isClosed`, which are structured statements rather than
names. `CrmStageMeaning` reads only those:

| What the CRM published | What this product concludes | What it records |
|---|---|---|
| closed, probability **exactly** 1.0 | the deal was won | `LeadStage::Won` |
| closed, probability **exactly** 0.0 | the deal was lost | `LeadStage::Lost` |
| not closed, any probability | open | **nothing** — the lead keeps the stage it had |
| closed at any other probability | open | nothing |
| no metadata at all | open | nothing |

Three of those rows are the interesting ones.

**A stage called "Won" that is not marked closed is open.** The fixtures carry exactly that — a stage labelled
"Won" at `probability: 0.9, isClosed: false`, which is an ordinary pipeline somebody built by hand — and a test
asserts the lead behind it comes back open with no value. A product that trusted the word would report a sale
that has not happened and put its amount in a revenue figure.

**Exact values, not thresholds.** HubSpot documents 1.0 for closed-won and 0.0 for closed-lost. A closed stage at
0.9 is not a documented state, and treating it as a win would be this product deciding something the CRM did not
say.

**"We cannot tell" and "it is still running" behave the same.** A custom pipeline with no metadata is open.
Inventing a third state would invite somebody to read an unreadable stage as a lost one.

An open stage maps to **no** `LeadStage`. `New`, `Contacted` and `Qualified` are this product's words for its own
pipeline and a CRM's middle stages do not map onto them — so the lead keeps whatever stage it has and carries the
**CRM's own label** beside it, attributed to the CRM. Two vocabularies, both visible, neither pretending to be the
other.

## What a deal becomes

| Deal | Lead |
|---|---|
| `dealname`, or the first contact's name where there is one | `name` |
| the first associated contact's email and phone | `email`, `phone` |
| `id` | `external_id`, which is how a re-sync recognises it rather than duplicating it |
| the stage's **label**, prefixed with the provider | `source_detail` |
| `createdAt` | `received_at` |
| `amount` — **only on a closed-won deal** | `value_minor` with its currency |
| `closedate`, or `updatedAt` where there is none | `closed_at` |
| nothing | attribution: `OrderAttribution::none()` |

Three of those are decisions rather than mappings.

**An open deal's amount is not a value.** It is a quote — a hope, and often a round number somebody typed — and a
pipeline total built from hopes is the figure that makes a forecast indefensible. Only a closed-won amount is
recorded.

**Nothing is attributed.** A CRM holds no click identifier and no UTM parameters. Reading a campaign out of a
deal's name would be the false assignment this product refuses everywhere else, so every CRM lead arrives with
evidence `none` — and the Lead Center's unattributed row is where it shows up, honestly.

**A closing time is never "now."** The schema requires a closed lead to carry one, because the figure that
matters most about a pipeline is how long it took. A deal may have closed last week, and stamping the sync's own
clock on it would make every turnaround figure wrong by however long the integration took to arrive.

## Reading HubSpot as HubSpot sends it

Three of its conventions are each a defect waiting to be written, and each has a test.

**Everything in `properties` is a string.** `amount` is `"48000.00"`, `probability` is `"0.2"`, `isClosed` is
`"true"`. Code comparing `$metadata['isClosed'] === true` would find every stage open.

**An empty string is not a zero.** A deal with no amount publishes `"amount": ""`. Reading that as 0.00 would put
a deal worth nothing into a revenue figure.

**A `dealstage` is an internal id, not a label.** HubSpot's own documentation says so, and the label is a thing
people rename — which is why a deal is never read without its pipeline.

The contracts read, on **2026-10-08**, are recorded in `HubSpotAdapter` with their endpoints: the deals and
contacts object APIs (`/crm/v3/objects/...`, cursor paginated with `after`, 100 per page, a `properties`
parameter), the deal pipelines and their stages (`/crm/v3/pipelines/deals[/{id}/stages]`), and associations
(`/crm/v4/objects/deal/{id}/associations/contact`, where the HubSpot-defined deal-to-contact type is `3`).

Rate limits are published rather than guessed: **190 requests per app per 10 seconds**, with a daily figure that
depends on the installing account's tier — the lower of the two is recorded. Every response carries
`X-HubSpot-RateLimit-Remaining` and `X-HubSpot-RateLimit-Interval-Milliseconds`; a 429 carries **no** documented
`Retry-After`, so a retry has to pace itself from the interval header rather than wait to be told.

## The surface

`POST /api/leads/crm-sync` had no caller in the application until the Lead Center gained one: the bridge was
built, tested and unreachable, which is the same failure as a note nobody can read.

The control sits on the Lead Center, where CRM-sourced leads appear, and it says **read** rather than sync. The
CRM owns a deal's stage and nothing here writes one back, so a control offering to "sync" would imply traffic in
both directions that does not exist.

**The client is asked for, not inferred.** A HubSpot portal belongs to one company and a workspace has several
clients, so the panel refuses to offer a read until a client is chosen — the filter at the top of the page is
where that happens. Nothing guesses a client from a company name.

**The read reports what it left alone**, beside what it recorded. A bridge reporting only the deals whose stage it
could interpret would look like one that read almost nothing, when in fact declining to interpret is the
behaviour it was built for.

**The read says what kind of figures it got.** Every other sync in this product returns its source state and this
one did not, so a reader was told "12 deals read" with nothing saying they came from fixtures. A count is as
capable of being quoted to a client as a chart is, so `source` travels with the answer, into the audit entry as
well — "was that real?" is asked months later — and a sandbox read is labelled on the page where it was run.

**The panel is absent when no CRM is connected**, rather than present and empty. A panel on every workspace that
has never connected one would be an advertisement, and the place to connect a provider is the integrations page.

## BLOCKED_EXTERNAL

No HubSpot app exists for this repository. What is missing is exactly:

1. **An app in a HubSpot developer account**, giving a client id and secret. Self-service for private use; a
   public listing needs review only to be distributed.
2. **A HubSpot account that installs it** and grants `crm.objects.deals.read`, `crm.objects.contacts.read` and
   the pipeline read for deals.

Until both exist, `HubSpotCrmReadAdapter` serves fixtures shaped exactly like the published responses — and
**refuses real credentials** rather than quietly serving fixtures to a workspace that had configured a real
connection, which would put invented deals in front of a client. A test asserts that refusal.

## What this does not do

- **No write side, ever.** Not a gap: see the first section. The capability list for HubSpot carries no mutation
  at all, and the registry's own consistency check would reject one.
- **No client mapping.** A HubSpot portal is one company's CRM and an agency workspace has several clients, so the
  sync takes the client as a parameter. Nothing infers it from a company name, because a deal's company and an
  agency's client record are two different things with similar text in them.
- **No contact history.** A CRM holds far more about a person than this product has reason to copy, and every
  field copied is a field to protect, retain and eventually delete — see the retention note in `docs/SECURITY.md`.
  A lead keeps a name, an email and a phone number: what a media buyer uses to recognise somebody.
- **No first-sync history beyond thirty days.** A first sync that pulled a CRM's whole history would import deals
  closed years ago as fresh leads, and every "since the lead arrived" figure would be wrong for all of them. A
  longer reach is an import — a different operation, with somebody's attention on it.
- **No multi-currency pipeline total.** Deals arrive in whatever currency each was quoted in, and the Lead
  Center's existing rule applies unchanged: a total across currencies is withheld with the reason stated.
- **No deal-to-order link.** A won deal and a store order may be the same money twice. Joining them needs
  evidence, and "both happened in the same week for the same company" is not evidence.
