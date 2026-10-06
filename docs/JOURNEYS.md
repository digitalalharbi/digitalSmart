# Customer journeys

What one person did, and — the more important half of this document — what digitalSmart cannot know about it.

## The blocker, stated first

**Multi-touch customer journeys are not buildable from the data this product has.**

The roadmap asked for touchpoints, identity evidence, identity confidence and a Journey Explorer. Three of
those four are delivered. Touchpoints are only *partly* possible, and the reason is worth being exact about:

> Google Analytics' Data API returns **aggregated reports** — tables of dimensions and metrics — not per-user
> event streams. There is no user-level identifier available through it. The documented route to event-level
> data is a **BigQuery export**, which is a separate integration this product does not read.

So the clicks, sessions, page views and ad impressions that precede a conversion are **absent rather than
nonexistent**, and nothing in this phase pretends otherwise.

What *can* be assembled is a person's history of **conversions**: their enquiries and their orders, linked on
normalised contact details. That is genuinely useful — "this customer enquired on the 1st and bought on the
10th" is a fact an agency cannot currently get anywhere — and it is not a journey in the sense the phrase
usually means.

### What would unblock it

Either of:

- **GA4 → BigQuery export**, which gives event-level rows with a pseudonymous user id. Needs a Google Cloud
  project with billing, the export configured on the property, and BigQuery read credentials. It also costs
  money per query, which makes it a decision for whoever pays rather than a default.
- **First-party tracking**, where this product serves its own endpoint and records touches itself. That is a
  larger piece of work and brings consent obligations with it.

Neither is a small addition, and both are recorded here rather than half-built.

## Why a single record is never drawn as a journey

`Journey::isMultiTouch()` exists so an interface can say *"one enquiry, nothing else recorded"* rather than
rendering a one-step funnel.

The distinction matters because a one-step funnel reads as a **complete picture**: this customer appeared from
nowhere and bought. A reader acts on that — they conclude the channel that produced the enquiry deserves no
credit, or that the customer had no prior contact. Both may be false, and the product has no way to know.

So `span_in_days` is null for a single touchpoint rather than zero, and the response carries an explicit
limitation saying it is not a journey yet.

## The journey has no attribution of its own

Each touchpoint keeps the attribution **it** carried. The journey does not acquire one.

Deciding which touch earned the credit is a *model* — first-touch, last-touch, linear and time-decay disagree
with each other by design — and a model presented as a fact is the thing this product refuses. That choice
belongs to the attribution phase, and whatever it chooses must be labelled as modelled when it arrives.

What the journey does offer is `earliest_attributed_touchpoint`: the first record that carried an attribution,
presented as a fact about **that record**. It is not called "the journey's campaign", and there is a test
asserting the response has no such field.

Likewise, a touchpoint never inherits another's attribution. An enquiry carrying `utm_campaign=autumn` and an
order carrying nothing stay that way — letting the order inherit it would manufacture a link the order never
had, which is precisely how an attributed revenue figure becomes indefensible.

## Identity evidence

The same discipline the attribution evidence carries, applied to identity instead of to credit. Deciding that
an enquiry and an order came from one person changes what a client is told about their funnel, and deciding it
wrongly merges two people's histories into one record nobody can disentangle afterwards.

| `IdentityEvidence` | Confidence | Reliable? | What it is |
| ------------------ | ---------- | --------- | ---------- |
| `platform_customer` | 95 | yes | The shop's own customer record is on the order. The platform made the link, not this product |
| `exact_phone` | 85 | yes | The same normalised phone. In this market, the identifier people give consistently |
| `exact_email` | 80 | yes | The same normalised address |
| `shared_click_identifier` | 45 | **no** | Strong about the *click*, weak about the *person*: a shared device or a forwarded link puts one person's click on another's order |
| `none` | 0 | no | Nothing links them. Common, and honest |

`isReliable()` draws the line above the click identifier on purpose: a shared-device match is worth **showing
to somebody who can judge it** and is not worth **counting in a funnel**.

## Matching is exact, and never on a name

Only normalised contact details, through the shared `ContactPoint` value object — the same one the leads module
uses, so an enquiry and an order are recognised as one person by one rule. Two modules normalising a phone
number differently would be two modules that disagree about how many customers a client has.

**No fuzzy matching.** No "same city and similar spelling", and no name matching at all: two people called
Mohammed Al-Harbi are two people. The cost of missing a link is one unlinked journey; the cost of a wrong link
is a record that cannot be untangled.

A Saudi number written four ways — `0555001122`, `555001122`, `+966555001122`, `00966555001122` — is one
person, which is what makes the lead-to-order link work at all. E-mails are matched case-insensitively and
otherwise untouched.

An enquiry with no contact details has `none` evidence and an unlinked journey, because it genuinely cannot be
joined to anything.

## Resolved on read, never stored

There is no identity graph table. A stored graph goes stale the moment the next order lands and nobody dares
rebuild it; resolving on read means the answer is always made of rows a person can go and look at for
themselves — the same reasoning as pacing, freshness and tracking health.

The cost is a join per request, which is cheap because both sides are indexed on their contact keys. Phase 11's
`store_customers` gained `email_key` and `phone_key` in this phase for exactly that: normalising a phone number
in SQL is not possible, since no amount of `lower()` makes `0555001122` and `+966555001874` comparable.

Existing customer rows are deliberately **not** backfilled. A half-normalised key would match some records and
not others, which is worse than none: the next sync computes them properly, and until then an unlinked journey
is honestly unlinked rather than inconsistently linked.

## Visibility

A lead the actor cannot reach is **not addressable** — `404`, not an empty journey. An empty journey would
confirm the id exists, which is itself across the tenant boundary. Checked both on the lead lookup and inside
the contact resolution, because the second is reachable on its own.

Gated on `leads.view`, because a journey is mostly a person's enquiries and carries their contact details.

## API

`GET /api/leads/{lead}/journey` — see [API.md](API.md#leads).

The response carries `limitations` as data rather than leaving them to documentation, because the person reading
the page is the one who would otherwise draw the wrong conclusion. They are assembled per journey: a
single-touch journey is told it is single-touch, and a weakly linked one is told why.

## Tests

`backend/tests/Feature/Leads/JourneyTest.php` — the refusals first: a single record is not a journey, the
missing marketing is stated, the journey has no campaign of its own, and two different phone numbers are two
different people.
