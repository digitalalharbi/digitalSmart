# E-commerce

One normalised shape for every store platform, and the rules that keep a client's revenue figure
defensible.

Salla has its own document ([SALLA.md](SALLA.md)) and so does Zid ([ZID.md](ZID.md)). This one is
about the shape they are both read into, which was written **before either of them** — otherwise the
first platform integrated becomes the vocabulary the second has to be bent into, and an agency
reporting on one client per platform ends up comparing two different meanings of "paid".

## Payment and fulfilment are separate axes

Every store platform has both. digitalSmart keeps them in two columns, and that is the single most
consequential decision in this schema.

| `PaymentState` | `FulfilmentState` |
| -------------- | ----------------- |
| `unpaid`, `pending`, `authorised`, `paid`, `partially_refunded`, `refunded`, `voided` | `unfulfilled`, `processing`, `shipped`, `delivered`, `returned`, `cancelled` |

A single `status` column makes **"delivered but refunded" unrepresentable** — an entirely ordinary
thing for a shop, where the customer received the goods, complained, and got their money back. And
the first thing anybody does with a single status is sum revenue by it.

Each provider's own status strings are carried through untouched in `raw_payment_status` and
`raw_fulfilment_status`. A mapping will be disputed the first time a client's figures differ from
their platform dashboard, and the provider's word for it has to still be on the record when that
happens.

### What counts as revenue

`PaymentState::recognisesRevenue()` is true for **`paid` and `partially_refunded` only**.

- `partially_refunded` counts because the part that was kept is real money: the refund comes off the
  amount, not off the order's existence.
- `pending` and `authorised` do **not** count. Cash on delivery that never arrives is not a sale, and
  counting it overstates every report in the week before the courier comes back — which in this
  region is a large share of orders.

## Money

Integer minor units with the currency beside them, everywhere. A float would drift; an amount with no
unit is the first thing somebody adds to another one.

**A discount is stored as a positive amount that was taken off**, never a negative one that was
added. Mixing those two conventions across providers is how a discount comes to increase revenue, and
a CHECK constraint refuses a negative.

## Who may speak for revenue

`RevenueSource`: `platform_reported`, `google_analytics`, `salla`, `zid`, `crm`, `manual`,
`attributed`.

**These are never silently merged.** A client's Google Ads conversions, their GA4 purchases, their
Salla orders and their CRM sales will all disagree by design, because each counts something
different:

- an ad platform reports what it believes it **caused** — a claim about its own effectiveness;
- an analytics property reports what a **browser** said a sale was worth, measured before the payment
  failed or the item came back;
- a store reports what was **invoiced**;
- a CRM reports what a **person recorded**.

Adding two of those together produces a number no one can defend and everyone will quote. So every
revenue figure names its source, a reader always chooses which one they are looking at, and the only
arithmetic across sources is a deliberate, labelled comparison.

`isInvoiced()` is true for a store and a CRM only. `isModelled()` is true for `attributed`, so
modelled revenue can never be rendered as actual.

## Attribution: never falsely assign an order to a campaign

The rule this area exists under. An order is credited to a campaign **only when the order itself says
so** — not by the hour it arrived, not by the customer's city, not by being the only order on a day a
campaign ran. Those correlations are tempting, they are invisible once they reach a report, and acting
on them means telling a client which campaign earned their money on the strength of a coincidence.

So every link carries its evidence, and the evidence has a strength:

| `AttributionEvidence` | Confidence | Names a campaign? | What it is |
| --------------------- | ---------- | ----------------- | ---------- |
| `click_identifier` | 95 | yes | `gclid`, `fbclid`, `ttclid` — the platform generated it when the click happened |
| `utm_parameters` | 80 | yes | A campaign **and** a source. Put there deliberately |
| `partial_utm` | 50 | no | Source or medium only. Names a channel, not a campaign |
| `coupon_code` | 35 | yes, weakly | Typed by a person who may have had it from anywhere |
| `referrer` | 25 | no | A channel at best, lost entirely inside most mobile apps |
| `none` | 0 | no | The order said nothing |

`none` is the most important case and the one a dishonest implementation would quietly fill in.
Direct traffic, organic, a returning customer and a UTM lost to an in-app browser all land there, and
the correct report says **unattributed** rather than dividing them among whichever campaigns were
running.

`partial_utm` exists because a bare `utm_source=meta` says traffic came from Meta and says nothing
about which campaign. A report that split revenue across a client's Meta campaigns on that basis
would be inventing the split.

`OrderAttribution::from()` does the derivation, so a Salla order and a Zid order carrying the same
parameters reach the same verdict. Analytics placeholders — `(not set)`, `(none)`, `undefined` — are
treated as **absent**, because stored verbatim they become a campaign literally called "(not set)" in
a client's report, which reads as a campaign somebody named badly rather than as a blank.

### The constraint that makes this enforceable

```sql
CHECK (attribution_evidence = 'none'
       OR utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL
       OR click_identifier IS NOT NULL OR coupon_code IS NOT NULL OR referrer IS NOT NULL)
```

An order claiming campaign parameters with no parameters on it is the one defect in this area that no
report could reveal afterwards. It is refused by the database, so a second write path cannot produce
it.

## New versus returning customers

`GET /api/commerce/analytics` returns a `customer_mix` on every response: three buckets, and the share of
revenue that came from customers new to the store — **or the reason there is no share.**

**There is no window, and that is the point.** "Returning within ninety days" sounds like a definition and is
really a knob: move it and the headline moves with it, which is how a figure stops being a measurement. The
question this product answers instead has one answer for any given period — *was this customer's first order
with this store inside the period, or before it?* Nobody has to agree on a window to agree on that.

**The third bucket is why the figure is publishable.** A store's history in this product begins when its
integration was connected. A customer whose earliest synced order sits at that edge may have been buying for
years, so they are `unknown` — not `new`. Calling them new would make every freshly connected store look like a
triumph in its first month, and the number would really be measuring how long the integration had been running.
The boundary is the earliest order ever synced for the store, so it moves backwards as history accumulates and
the bucket empties on its own.

**The share is withheld, not estimated, when the unknown bucket is a fifth or more of the period's revenue.**
`new_share` is then `null` and `unavailable_reason` carries a sentence saying which of the two reasons applies:
nothing was sold at all, or too much cannot be classified. Only the second gets better on its own, which is why
they are distinguished rather than both rendered as a dash. The boundary is inclusive — **at** exactly a fifth
the share is already withheld — and it is pinned by its own unit test, because a reader will ask.

**Orders with no customer identifier are excluded from the mix entirely**, not pooled into `unknown`. A guest
checkout is not an unclassifiable customer; it is an order with nobody to classify, and a bucket that mixed the
two would mean neither.

**The surface shows no percentages beside the buckets.** `new_share` is the new bucket's share of revenue, so a
share column next to the revenue it is computed from would hand a reader the exact figure the server declined to
publish. The buckets carry their customer counts and their revenue; the weight of the unclassified bucket is
legible from its revenue beside the others. The client portal composes the same component on the same figures —
a client told a campaign brought revenue deserves to know whether it brought customers.

**What is deliberately not built: "active recently" and reactivation.** Both need a window — thirty days,
ninety, a year — and that window is a business decision about this agency's clients, not something to infer. A
reactivation figure also needs to know a customer *stopped*, which is unobservable: the only evidence is an
absence, and an absence is indistinguishable from a customer who simply has not come back yet. If the window is
decided, the honest shape is a named cohort with the window in its label — "bought in the last 90 days" — never
a bare "active customers".

## Tables

| Table | Notes |
| ----- | ----- |
| `stores` | Client-scoped and required: a store with no client is orders nobody can report on. Unique per workspace, provider and external id |
| `store_customers` | The client's customers. Lifetime totals are **derived**, not taken from the provider |
| `store_products`, `store_product_variants` | `stock` is nullable: untracked is not zero |
| `store_orders` | The two state axes, money, attribution, revenue source, and the honesty label. The amount **breakdown is nullable**: Salla's order list reports a total and nothing else, and zero would read as "nothing was charged for shipping" |
| `store_order_items` | Name and price **as sold**, not as the catalogue reads today |
| `store_refunds` | Individually, with dates |
| `store_shipments` | Several per order — a split delivery is two shipments of one sale |
| `store_webhook_events` | Every arrival, with whether its signature verified |

### Everything upserts

Every sync re-reads a restatement window, because orders keep changing after they are placed — paid,
shipped, refunded, cancelled. So the same order arrives repeatedly, and **an insert rather than an
upsert would multiply a client's revenue by the number of times the sync had run** — a defect that
would look exactly like growth.

Three things follow from that:

- **Order lines are replaced, not merged.** The lines are the whole truth about what was sold, so a
  line removed in an amendment has to stop counting. Refunds and shipments go the same way.
- **Variants are upserted, not replaced.** An order item references a variant; deleting it to rewrite
  it would null that reference and lose which variant a past sale was for.
- **A product reference is nullable.** An order can name a product since deleted from the catalogue,
  and the line is still a real line of a real sale. Dropping it would change last quarter's revenue
  because somebody tidied up this morning.

### Customer lifetime totals are derived

`recalculateCustomerTotals()` computes them from the orders actually stored, counting only states that
recognise revenue and subtracting what came back. A customer re-sync deliberately cannot overwrite
them.

The provider's own lifetime total includes orders this workspace may never have read and counts
refunds on its own terms, so the two will differ. **The figure that reconciles against the orders in
front of the reader is worth more than the one that is larger.**

### `orders_synced_through` moves forward only

Settled in SQL with `greatest()`. A backfill of an older window reads genuine orders with older
timestamps, and letting those win would make the next sync either re-read months it had already
covered or — worse, because it is silent — skip the days between.

## Webhooks

The webhook journal records every arrival **before** anything is done with the payload. Three
constraints, which are all the same constraint:

1. the request must be answered in milliseconds;
2. the work must happen on a queue;
3. a redelivery must not do the work twice.

Idempotency is a unique index on `(provider, external_event_id)`, and the insert uses **`ON CONFLICT
DO NOTHING`** rather than catching the violation. That is not a style preference: in PostgreSQL a
constraint violation aborts the whole surrounding transaction, so catching one would turn a harmless
redelivery into a failed request for any caller inside a transaction — and a webhook handler that
also writes an order will be.

Zero rows inserted means "already seen", which is the signal to answer `200` and do nothing.

**A provider that sends no event id is processed anyway.** This product will not invent an identity
from a payload hash: two genuinely distinct events — two items of the same product added a second
apart — can hash identically, and silently dropping the second would lose a real sale.

**Two workspaces can register the same merchant**, because stores are unique per
`(workspace, provider, external_id)` and two agencies may both manage one shop. The signature decides
whose delivery it is: every candidate store is found and the signature is checked against each one's own
secret. Routing by first match would put one agency's client's orders into the other's workspace, which
nothing downstream could detect.

**An unverified signature is recorded as `rejected` and never processed.** The arrival is a fact worth
keeping — it is how somebody finds out an endpoint is being probed — and writing down which it was is
the only way to be sure afterwards that it was not acted on.

Statuses: `received`, `queued`, `processed`, `ignored`, `failed`, `rejected`. `ignored` is not a
failure: a topic this product does not act on is a normal thing to receive, and a wall of `failed`
rows would make the real failures unfindable.

## Personal data

`store_customers` holds names, emails, phone numbers and cities **belonging to the agency's client's
customers** — not to the agency. It is held because matching an order to a lead in Phase 15 needs it.
It is never serialised into a client-portal response, and `docs/SECURITY.md` records the retention
question it raises.

## Connecting a store to a client

The decision the whole module rests on, and for three phases nothing could make it. A store belongs to exactly
one client — that is what makes a merchant's orders somebody's revenue — and the `stores` row could only be
written by a seeder. A real installation that connected Salla had a connection, no store, and a webhook journal
full of `unknown_store`.

`ConnectStore` is that decision, and four things about it are deliberate:

| Decision | Why |
| -------- | --- |
| The platform's details are read back, not typed | Name, currency, domain and timezone come from discovery by merchant id. Every amount from this store is stored in its currency, and nothing downstream could detect a wrong one |
| The merchant must still be visible to the connection | Registering an id the connection cannot see creates a row whose every read fails for a reason the row cannot explain |
| One merchant, one client — and the refusal names the client | Two registrations would count every order twice. A unique index sits behind the check for the race |
| The client is checked for **visibility**, not existence | Deciding whose revenue a merchant's orders become is not a decision to make about a client the decider cannot otherwise reach. A client they cannot see is a `404` |

It is audited as `store.connected`, a security-relevant action: from then on every order that merchant reports
counts towards that client's revenue, and the people on that client's projects can read its customers.

## Reading a store, by hand and on a schedule

`SyncStore` — the reconciliation read this module was built around — was reachable from nothing: no route, no
job, no command. Webhooks were the only way an order could arrive, which is exactly the arrangement the
restatement window exists to compensate for.

- **`POST /api/commerce/stores/{store}/read`** is the button for the morning somebody notices a figure looks
  low. It returns the outcome whole: succeeded, partial, failed or skipped, with the honesty label.
- **`commerce:sync`** is the nightly pass, queueing `ReadStoreJob` per store. Unique per store, because both
  Salla and Zid page sequentially and a second walk interleaved with the first reads from a cache it poisoned.
- A scheduled read is recorded with **no actor**, which the trail shows as the system rather than attributing a
  nightly job to whoever last signed in.

The demo seeder now connects its store through `ConnectStore` rather than writing the row, so the demo
demonstrates a state a real owner can reach.

## What this phase does not do

- **No live provider credentials.** Salla (Phase 12) and Zid (Phase 13) implement `CommerceSyncAdapter`, and
  with the literal credential `sandbox` both read response-shaped fixtures. The live handshake needs a partner
  app and a merchant who installs it — `BLOCKED_EXTERNAL_CREDENTIALS`, recorded in
  [SALLA.md](SALLA.md) and [ZID.md](ZID.md). There is no path that invents an order.
- **The analytics surface is `GET /api/commerce/analytics`** (Phase 14), which requires a named revenue
  source on every request — see [API.md](API.md#commerce). It counts only orders whose payment state
  recognises revenue, reports net of refunds, keeps pending orders beside the total rather than inside
  it, and produces an unattributed row rather than distributing it.
- **No sync bookkeeping beyond the watermark.** Cursors and run journalling are deliberately deferred
  to Phase 12, where a real provider's pagination determines their shape. Building bookkeeping before
  the thing that needs it is how it ends up fitting nothing.

## Tests

- `backend/tests/Unit/Domain/CommerceTest.php` — the state axes, revenue sources and every attribution
  rule, as plain objects.
- `backend/tests/Feature/Commerce/CommerceStoreTest.php` — the upserts, the line replacement, derived
  customer totals, the forward-only watermark, webhook idempotency, and the database refusing an order
  that claims evidence it does not carry.
