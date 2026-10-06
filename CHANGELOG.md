# Changelog

All notable changes to digitalSmart are recorded here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[Semantic Versioning](https://semver.org/).

## Phase 16 — customer journeys

Three of the four things this phase asked for are delivered. The fourth is blocked by the data rather than by
the work, and naming that precisely is the most useful outcome here.

### The blocker, named

**Multi-touch customer journeys are not buildable from the data this product has.** Google Analytics' Data API
returns aggregated reports — tables of dimensions and metrics — not per-user event streams, and there is no
user-level identifier available through it. The documented route to event-level data is a **BigQuery export**,
a separate integration this product does not read.

So the clicks, sessions, page views and ad impressions before a conversion are **absent rather than
nonexistent**. What would unblock it: a GA4 → BigQuery export (needs a Cloud project with billing and costs
money per query, which makes it a decision for whoever pays) or first-party tracking (larger, and brings consent
obligations). Both are recorded in `docs/JOURNEYS.md` rather than half-built.

### Added

- `app/Domain/Journeys/` — `IdentityEvidence`, `TouchpointKind`, `Touchpoint` and `Journey`.
- `JourneyReadModel` and its Eloquent implementation, `GetJourney`, `JourneyResource`,
  `GET /api/leads/{lead}/journey`, `docs/JOURNEYS.md` and 16 tests.
- `email_key` and `phone_key` on `store_customers`, so a person can be recognised across surfaces.
- `ContactPoint` moved to `App\Domain\Shared\ValueObject`, because recognising a person is the same problem
  whether the record is an enquiry or an order — and two modules normalising a phone number differently would be
  two modules that disagree about how many customers a client has.

### Fixed

- **The journey lookup was not visibility-scoped.** An out-of-reach lead answered with an empty journey rather
  than a 404, which confirms the id exists — itself across the tenant boundary. Now checked on the lead lookup
  as well as inside the contact resolution, because the second is reachable on its own.

### Decisions worth recording

- **A single record is never drawn as a journey.** `is_multi_touch` exists so an interface says "one enquiry,
  nothing else recorded" rather than rendering a one-step funnel — which reads as a *complete picture* and gets
  acted on as one: a reader concludes the customer appeared from nowhere, or that the channel deserves no credit.
  `span_in_days` is null rather than zero for the same reason, and a zero would be averaged into a cycle time as
  though the customer had bought instantly.
- **The journey has no attribution of its own.** Each touchpoint keeps what it carried, and no touchpoint
  inherits another's. Deciding which touch earned the credit is a model — first-touch, last-touch, linear and
  time-decay disagree by design — and a model presented as a fact is what this product refuses. The response
  offers `earliest_attributed_touchpoint` as a fact about *that record*, and a test asserts there is no
  journey-level campaign field.
- **`IdentityEvidence` mirrors the attribution discipline**, because deciding two records are one person changes
  what a client is told about their funnel. The reliability line is drawn **above** the shared click identifier:
  it is strong about the click and weak about the person, since a shared device or a forwarded link puts one
  person's click on another's order. Worth showing to somebody who can judge it; not worth counting in a funnel.
- **Matching is exact and never on a name.** Two people called Mohammed Al-Harbi are two people. The cost of a
  missed link is one unlinked journey; the cost of a wrong link is a record nobody can disentangle afterwards.
- **The platform's own customer link outranks anything this product infers.** The shop made it — the customer
  signed in, or the platform matched them itself.
- **Resolved on read, never stored.** A stored identity graph goes stale the moment the next order lands and
  nobody dares rebuild it; resolving on read means the answer is always made of rows a person can go and look at.
- **Existing store customers are not backfilled with normalised keys.** A half-normalised key would match some
  records and not others, which is worse than none — the next sync computes them properly, and until then an
  unlinked journey is honestly unlinked rather than inconsistently linked.
- **The limitations are in the response, not only in the documentation**, and are assembled per journey: the
  person reading the page is the one who would otherwise draw the wrong conclusion.

### Not yet in place

- **Multi-touch touchpoints**, as above. This is the phase's honest ceiling.
- **No Journey Explorer surface.** Worth building once there is more than one kind of touchpoint to show.
- **No manual merge** of two records a human knows are one person.

## Phase 15 — lead intelligence

Built around one sentence: **a lead is a person who did something, and a click is not a lead.**

### Added

- `app/Domain/Leads/` — `LeadStage`, `LeadSourceKind`, `LeadFunnel`, `ContactPoint` and
  `InvalidLeadTransition`.
- `leads` and `lead_events` tables, `EloquentLeadRepository`, `EloquentLeadReadModel`, `RecordLead`,
  `ChangeLeadStage`, `ListLeads`, `GetLeadCentre`, four endpoints, `leads.view` / `leads.manage`, three audit
  actions, `docs/LEADS.md` and 47 tests.

### Decisions worth recording

- **`LeadSourceKind` has no member for a click, a tap or a form view.** Every member is a completed action by a
  person — a form submitted, a message received, a call answered, an order placed — so no value exists to pass
  that would turn a counted signal into a lead. The rule is enforced by absence rather than by a check somebody
  could remove, and there is a test asserting the absence. An agency reporting WhatsApp clicks as leads reports
  a cost per lead a fraction of the real one, wins the next month's budget on it, and cannot explain afterwards
  why the client's sales did not move.
- **Signals sit beside the leads and are never summed with them, and no ratio between the two is published.** A
  tap and a lead do not share a denominator: the same person taps three times, taps and then phones instead, or
  taps on a device the form never loaded on. Four hundred taps and one conversation is a real shape for a month,
  and showing both answers the question an agency has while making the thing it must never do impossible to do
  by accident. The response carries a note instead of a rate.
- **Every rate's denominator is the defensible one, and every rate is null rather than zero when empty.** Wins
  divide by *closed* leads — an open lead is not a loss, and a pipeline a fortnight old is mostly open.
  Qualification divides by leads somebody *reached* — a lead nobody has phoned has not failed to qualify. `0%`
  and "nothing to measure" look identical on a card and mean opposite things.
- **There is deliberately no lead-to-session rate.** Sessions come from an analytics property and leads from a
  form; dividing one by the other assumes every session had the chance to convert. That comparison belongs where
  both numbers' sources are named.
- **`contacted` is an attempt, not contact.** Three voicemails are not contact, so `first_contacted_at` is set
  when a lead reaches a stage that means somebody got through. That is what makes `hours_to_first_contact` the
  figure that distinguishes a bad campaign from a slow sales team.
- **A disqualification must say why**, in the use case and in a CHECK constraint. "Not a real enquiry", "out of
  area", "price" and "wrong product" lead to four different conversations with a client paying for leads. The
  reason is cleared when a lead moves on, because leaving it attached to a lead that was later qualified makes
  the record say something nobody believes — and a reopened lead loses its closing time, because a stale
  `closed_at` makes every cycle-time figure wrong.
- **A Saudi phone number written four ways is one lead.** `+966555001122`, `00966555001122`, `0555001122` and
  `555001122` normalise to one key; counting them separately would report four times the volume at a quarter of
  the cost. The leading zero is a national trunk prefix rather than part of the number, which is why dropping it
  is right here and would be wrong elsewhere.
- **E-mails are lower-cased and nothing more.** No Gmail dot-stripping: it is right for Gmail and wrong for a
  server that distinguishes addresses, and merging two real people is worse than counting one twice. Nothing
  matches on a name at all.
- **Deduplication runs for a day.** Within it the same phone is one person resubmitting; beyond it, a new
  enquiry about something else. An enquiry with no contact details is always new, because refusing the second
  would lose one that happened.
- **A follow-up can improve an attribution, never weaken it, and only wholly.** An in-app browser strips the
  parameters and the customer resubmits properly, so taking the stronger arrival is better than a lead stuck as
  unattributed — but a half-merged attribution, this submission's click id beside the previous one's campaign,
  would be a link to a campaign nobody can verify.
- **A media buyer may read the pipeline and not qualify a lead.** A cost per *qualified* lead is the only honest
  measure of their work, and judging the quality of what you bought is not a judgement to hand to the buyer.
- **One attribution vocabulary shared with the orders**, including the CHECK requiring evidence to be backed by
  a field, so a cross-surface report holds both figures to one standard of proof.
- **The unattributed campaign row is always produced.** For leads it is usually the largest — most enquiries
  carry no parameters and many arrive by phone — so hiding it would make every named campaign look responsible
  for far more of the pipeline than it is.
- **Rates are computed in the domain, not in SQL.** A win rate written as a SQL expression is a win rate nobody
  reviews, and the wrong denominator is the commonest defect in this area.
- Contact columns are hidden on the model by default, and no resource puts them in a client-portal response:
  they belong to the agency's client's prospects.

### Not yet in place

- **No CRM bridge.** The source kind exists and nothing populates it; a CRM integration needs a provider, its
  OAuth, and a decision about which system owns a stage when both have one.
- **No automatic lead from an order**, because "every customer is a lead" is true for some clients and nonsense
  for others.
- **No lead merge**, and **no lead scoring** — a score is a model, and a model presented as a fact is what this
  product refuses.
- **No frontend surface.**

## Phase 14 — store analytics and revenue sources

Where the commerce domain becomes a figure somebody quotes to a client. Nearly all of it is about what is
excluded from a total, which is the only part of a revenue report that is hard to get right and impossible
to spot afterwards.

### Added

- `StoreAnalyticsReadModel` and its Eloquent implementation, `GetStoreAnalytics`, five DTOs, a
  `StoreAnalyticsResource`, and `GET /api/commerce/analytics`. 29 tests.

### Fixed

- **A `source` alias shadowed the orders table's own `source` column.** PostgreSQL resolves a `GROUP BY`
  name to the real column in preference to a select alias, so the channel breakdown grouped by the honesty
  label instead of the UTM source and the query failed outright. Aliased `channel_source` now.
- **The evidence rank aggregate returned each group's strongest evidence, not its weakest.** The rank is
  `100 - confidence`, so the weakest link is the *highest* rank — `max`, not `min`. Getting it backwards
  labelled every group with its best evidence, which is exactly the flattering this figure must not do, and
  it would have been invisible on the page.
- **Coupon-only orders vanished from the campaign breakdown.** They carry no `utm_campaign`; the campaign
  they name is the code somebody minted, as `OrderAttribution::campaignName()` already knew. The query now
  applies the same fallback — they were not wrong before, they were simply absent.

### Decisions worth recording

- **`revenue_source` is required, with no default and no "all" value.** A Salla total counts invoices; a
  GA4 total counts what a browser reported before the payment failed. A request that did not name its
  authority is refused rather than answered, because the answer would be quoted to a client.
- **Only `paid` and `partially_refunded` orders count.** A pending cash-on-delivery order is a real order
  and is not money. In this market that is most of a busy week, so they are reported *beside* the total —
  counting them would overstate every report until the courier either collects or comes back, and
  reporting them nowhere would hide real demand.
- **Revenue is net of refunds, with gross beside it.** A refund should be visible rather than inferred from
  a smaller number.
- **Average order value is null when nothing counted.** A denominator of zero has no average, and
  rendering one as "0.00" invites a reader to compare it with last month's.
- **Attribution coverage travels with every summary.** A channel report at 30% coverage is a different
  document from one at 95%, and the figure that tells them apart belongs beside it rather than in a
  footnote.
- **A breakdown row carries the weakest evidence anything in it rests on**, not the strongest. A group's
  total is only as defensible as its flimsiest link.
- **`by_campaign` names a campaign only where the evidence can name one.** A bare `utm_source` names a
  channel; splitting it across a client's campaigns would be inventing the split.
- **The unattributed row is produced, not dropped.** It is usually the largest, and omitting it so the
  percentages add to a hundred is how a channel report comes to overstate every channel in it. It is
  omitted only when it would be empty.
- **The summary's honesty label is the weakest of its rows**, so a total spanning a sandbox store and a
  live one is a sandbox figure. This is the one number in the product that must never be flattered.
- **Product rows report no refunds.** A refund is recorded against an order, and splitting it across lines
  would be a guess at which item came back. It is on the summary, where it is a fact rather than an
  allocation.
- **Breakdowns are limited and therefore do not add up to the summary**, which is why the summary travels
  with them: a reader sees the difference as "the rest" rather than concluding the total is wrong.
- **Visibility is the same rule as the measurement sources'** — the clients of the actor's projects,
  applied in SQL — so the two surfaces cannot disagree about who can see what.

### The page

- `src/features/commerce/` — `StoreAnalyticsPage`, `BreakdownTable`, `RevenueChart`, money helpers, types,
  api and hooks; a `/commerce` nav item; English and Arabic translations; 11 component tests and 4
  end-to-end tests.
- **The revenue-source selector is the first control and is never defaulted silently.** What it is set to
  is repeated in words on the total, in one of three sentences: an invoiced figure is the closest thing to
  what the client earned, a reported one is a measurement rather than a record, and a modelled one carries
  an explicit instruction not to show it to a client as actual.
- **Pending orders get their own amber banner**, naming the amount and why it is excluded.
- **Coverage sits beside the breakdowns and turns amber below 70%**, because it changes what every row
  below it can be read as. A coupon-backed row is styled as a warning rather than as neutral, since the
  number beside it is the one most likely to be acted on wrongly.
- Summary figures are built as `MetricValue`s so the shared card carries null-as-absent and the source
  badge, rather than reimplementing either differently.
- The chart draws refunds as a second series rather than netting them away: a flat month caused by returns
  is a different problem from a month where nobody bought.
- The query key includes the revenue source, because changing it asks a different question rather than
  refining the answer — caching two authorities under one key would show one total under the other's
  label.
- A plural key was written in i18next's v3 `_plural` spelling, which silently falls back to the singular;
  corrected to `_one`/`_other`.

### Not yet in place
- **Mixed currencies are not converted.** A client selling in two has no single total, and summing minor
  units across them would be wrong by the exchange rate. A proper answer needs a rates source and a
  decision about which day's rate.
- **No new versus returning split.** The data is there; the definition is a product decision rather than a
  query.

## Phase 13 — Zid

The second store platform, read against the published API v1 — and the test of whether the Phase 11
domain was secretly Salla-shaped.

### Added

- `ZidMapper`, `ZidSyncAdapter`, `ZidWebhookVerifier` and four fixture files in the documented response
  shapes. `docs/ZID.md` and 50 tests.

### Fixed

- **Zid's order webhooks would all have been silently dropped.** The receiving use case held a
  hard-coded list of actionable topics written while implementing Salla — `order.created`,
  `order.updated`, `order.status.updated` — and Zid's events are named `order.create`,
  `order.status.update`, `order.payment_status.update`. Every Zid order webhook would have been recorded,
  marked `ignored` and discarded, with the endpoint looking healthy and the journal filling with
  `ignored` rows that are an ordinary sight. Caught by the first Zid test that expected work to happen.
  Topics are now matched by the `order.` prefix: over-matching costs one journal row, because the worker
  refuses any payload carrying no order, while under-matching costs a client's orders.
- **The webhook verifier port now takes the whole header bag** rather than two Salla-shaped arguments.
  Salla signs the body and sends its own headers; Zid sends Basic credentials in `Authorization`. Those
  are not the same security property and cannot be hidden behind one signature.

### Decisions worth recording

- **Zid fits the normalised domain natively**, which is the most useful thing learned in this phase. It
  publishes `payment_status` alongside `order_status`, so no inference is needed at all — where Salla
  needed a documented boolean to recover the payment axis from one workflow status. The two-axis design
  was a judgement call made before either provider existed.
- **An unknown order status maps to `processing`.** Zid lets a merchant define custom codes, so an
  unrecognised one is ordinary rather than a defect, and guessing from its name is what this product
  refuses to do everywhere. `processing` claims only that the order is in progress and is *not settled*,
  so the sync keeps re-reading until the order reaches a state this product understands. A state we
  cannot interpret should keep us looking, not stop us.
- **`reverse_in_progress` is not `returned`**, for the same reason: `returned` is settled, and an order
  mid-reversal is still moving. The exact code survives in `raw_fulfilment_status`.
- **Zid's Basic Auth is implemented as what it is.** It proves the caller knows a password and says
  nothing about the body; the secret travels on every request, base64 being an encoding rather than
  encryption; and there is no timestamp or nonce, so a captured delivery can be replayed indefinitely.
  What makes that harmless is the upsert, and the documentation says so rather than implying parity with
  an HMAC. The comparison is still constant time — a weaker mechanism is no reason to leak the password
  through timing as well.
- **A Salla signature is refused on the Zid endpoint.** Each platform's verifier is separate so the
  weaker mechanism's assumptions cannot leak into the stronger one's code, and so accepting a mechanism
  Zid does not use is not possible.
- **Marketplace masking is handled structurally, not by a flag.** Nothing in this product writes a
  customer from an order payload, so Zid's masked placeholder has no route into the customers table. The
  order is still stored — it is a real sale — and its customer id links to nothing, which is the truth. A
  fabricated e-mail in a client's customer list would be indistinguishable from a real one afterwards.
- **`order_total`, never `order_total_string`.** The second is formatted for display, including Arabic
  digits where the locale asks, and parsing it would work until a store switched language.
- **Arabic is preferred for localised names**, because these are Saudi stores and the Arabic name is the
  one on the merchant's screen.
- **`is_infinite` stock is stored as null.** Reporting unlimited stock as zero would pause a campaign on
  a product that is always available.
- **`total_order_count_per_status` is ignored.** This product derives its own counts from the orders it
  stored, so a figure a reader can reconcile is never replaced by one they cannot.

### Not yet in place

- **The live transport**, on a Partner app install and the OAuth handshake.
- **No scheduled syncing**, and **no webhook subscription management** — creating a subscription is a
  write, and this integration does not write.

## Phase 12 — Salla

The first store platform, read against the published Merchant API v2 and mapped into the Phase 11 shape
rather than being allowed to define it. Read-only, and still BLOCKED_EXTERNAL on a Partner app install.

### Added

- `SallaMapper`, `SallaSyncAdapter`, `CommerceFixtures` and four fixture files in the documented
  response shapes.
- `SallaWebhookVerifier`, `StoreWebhookVerifiers`, `ReceiveStoreWebhook`, `ProcessStoreWebhook`,
  `ProcessStoreWebhookJob`, `EloquentStoreLocator`, `EloquentWebhookEventReader` and a public
  `POST /api/webhooks/stores/{provider}` endpoint, rate limited by merchant.
- `SyncStore`, the reconciliation sync. `docs/SALLA.md` and 58 tests.

### Fixed

- **An order's amount breakdown can now be absent.** Phase 11 defaulted subtotal, discount, shipping and
  tax to zero; Salla's order *list* reports a total and no breakdown at all. A stored zero reads as
  "nothing was charged for shipping", and a subtotal report built on it is confidently incorrect rather
  than visibly absent — the exact conflation this product refuses elsewhere. The columns are nullable and
  the amounts CHECK was rewritten, because `NULL >= 0` is unknown rather than true.

### Decisions worth recording

- **Salla's single status axis is mapped onto two, from documented facts only.** Five of its twelve slugs
  state the payment outright and the mapper quotes the documentation in each branch. The other seven
  describe a parcel.
- **For those seven, `is_pending_payment` is read rather than the slug guessed at.** This is the decision
  that matters most in this market: cash on delivery is normal here, so `shipped` means "the money has
  not arrived" for one order and "it arrived last week" for another. Mapping the slug to `paid` would
  overstate a COD store's revenue by everything in transit; mapping it to `pending` would understate a
  prepaid store's to nothing. Salla publishes a boolean that answers exactly this question, and reading
  it is the difference between a fact and a guess from a label. Absent, it falls back to `pending`,
  because an agency that finds its revenue low goes looking while one that finds it high has already sent
  the report.
- **A refund outranks the workflow status.** Salla will show an order as `delivered` while the money has
  gone back, and the two axes let both be true.
- **Pages are walked in sequence**, because Salla documents that a page out of order returns an empty
  response and caches sequential requests for fifteen minutes. A sync that parallelised its pages, or
  resumed from page four after a crash, would read nothing and report success — a silent failure rather
  than an error, so the fixture loader reproduces the behaviour.
- **Webhook signatures are hashed over the raw body**, never a re-encoding. `json_decode` then
  `json_encode` changes key order, unicode escaping and float formatting; an implementation that
  re-encodes appears to work until a payload carries an Arabic product name, and then rejects every
  webhook from every Arabic store — which here is most of them. The fixture body is Arabic so this cannot
  regress unnoticed.
- **Constant-time comparison**, because `===` on a hash leaks it through timing one byte at a time to
  anybody who can send requests and measure.
- **An unconfigured secret verifies nothing**, and so does an unrecognised strategy. A deployment that
  forgot the secret rejects webhooks instead of accepting forgeries: a gap somebody notices rather than a
  silent door.
- **The signature decides which workspace a delivery belongs to.** Stores are unique per workspace, so
  two agencies can both register one shop, each with its own installed app and secret. Every candidate is
  found and the signature checked against each one's own secret. Routing by first match would put one
  agency's client's orders into the other's workspace — a cross-tenant leak nothing downstream could
  detect.
- **Every delivery is answered 2xx, including refusals.** A non-2xx makes Salla redeliver, and
  redelivering a payload that will never verify achieves nothing but filling the journal.
- **Salla sends no event id, and it does not matter.** The work is an upsert keyed on the order's own id,
  so idempotency comes from the data — which is stronger than idempotency by an identifier the provider
  does not send. No identity is synthesised from a payload hash, because two genuinely distinct events can
  hash identically and dropping the second would lose a real sale.
- **The worker reads the payload back from the journal row**, the only payload that has been through the
  signature check, and refuses an unverified row even though the endpoint never queues one. A job can be
  enqueued by anything with queue access, and this is the last gate before a payload becomes revenue.
- **Webhooks and the reconciliation sync share one mapper**, with a test asserting they agree. A second
  mapper is how a client's revenue comes to change whenever a nightly sync overwrites what a webhook wrote
  an hour earlier.
- **A webhook writes `Live` and a sync writes `Synced`**, so a reader can see which way a figure arrived.
- **Customers and products are read before orders**, because an order line names a product and reading
  orders first would leave nulls the next run quietly fixes — with a window in which a client's product
  report was missing its best-selling item for no visible reason.
- **Order timestamps keep their time zone.** A Riyadh store read as UTC moves three hours of every
  evening's sales onto the previous day, which shifts revenue between months at a month boundary.
- **A customer's mobile is read as a string with its country code**, because Salla sends it as an integer
  and a phone number that has been through an integer may no longer dial.
- **Webhooks are rate limited by merchant, not by IP.** Providers deliver from shared infrastructure, so
  an IP key would let one busy shop throttle every other shop on the platform.

### Not yet in place

- **The live transport**, on a Partner app install and the OAuth handshake.
- **Order details are not fetched per order**; the breakdown is left null. One request per order is a
  quota decision worth making deliberately.
- **No scheduled syncing**, and **no webhook subscription management** — creating a subscription is a
  write, and this integration does not write.

## Phase 11 — the commerce domain

One normalised shape for every store platform, written before either provider so that neither gets to
define it.

### Added

- `app/Domain/Commerce/` — `PaymentState`, `FulfilmentState`, `RevenueSource`, `AttributionEvidence`
  and `OrderAttribution`.
- Eight tables: `stores`, `store_customers`, `store_products`, `store_product_variants`,
  `store_orders`, `store_order_items`, `store_refunds`, `store_shipments`, `store_webhook_events`.
- `CommerceSyncAdapter` (the contract Salla and Zid implement), `CommerceStore` (the write port),
  `WebhookEventJournal`, their Eloquent implementations, nine record DTOs, `docs/ECOMMERCE.md` and 32
  tests.

### Decisions worth recording

- **Payment and fulfilment are separate columns.** Every platform has both axes. A single `status`
  makes "delivered but refunded" unrepresentable — an entirely ordinary thing for a shop, where the
  customer received the goods, complained and got their money back — and the first thing anybody does
  with a single status is sum revenue by it. Each provider's own strings are kept verbatim alongside,
  because a mapping will be disputed the first time a client's figures differ from their platform
  dashboard.
- **Only `paid` and `partially_refunded` recognise revenue.** The kept part of a partial refund is real
  money; the refund comes off the amount, not off the order's existence. `pending` and `authorised` do
  not count, because cash on delivery that never arrives is not a sale — and counting it overstates
  every report in the week before the courier comes back, which in this region is a large share of
  orders.
- **A discount is a positive amount taken off**, never a negative one added. Mixing those conventions
  across providers is how a discount comes to increase revenue, and a CHECK refuses a negative.
- **`RevenueSource` exists so figures are never silently merged.** An ad platform reports what it
  believes it caused, an analytics property what a browser said before the payment failed, a store what
  was invoiced, a CRM what a person recorded. Adding two together produces a number no one can defend
  and everyone will quote.
- **Attribution records only what the order carried.** Not the hour it arrived, not the customer's
  city, not being the only order on a day a campaign ran. Those correlations are invisible once they
  reach a report, and acting on them means telling a client which campaign earned their money on the
  strength of a coincidence.
- **The evidence has levels, and a bare `utm_source` is not one that names a campaign.** It names a
  channel; a report splitting revenue across a client's Meta campaigns on that basis would be inventing
  the split. A coupon code sits deliberately below half confidence, because a code is typed by somebody
  who may have had it from a friend, an influencer or an aggregator.
- **`none` is a legitimate and common answer**, and the one a dishonest implementation would quietly
  fill in. Analytics placeholders — `(not set)`, `(none)` — are treated as absent, because stored
  verbatim they become a campaign literally called "(not set)" in a client's report.
- **A database CHECK requires evidence to be backed by a field.** An order claiming campaign parameters
  with no parameters on it is the one defect here that no report could reveal afterwards, so a second
  write path cannot produce it.
- **Everything upserts**, because every sync re-reads a restatement window and an insert would multiply
  a client's revenue by the number of runs — which would look exactly like growth. Order lines, refunds
  and shipments are *replaced*; variants are *upserted*, because order items reference them and deleting
  one would lose which variant a past sale was for; and an item's product reference is nullable, because
  an order can name a product somebody has since deleted and the line is still a real sale.
- **Customer lifetime totals are derived from stored orders**, and a customer re-sync cannot overwrite
  them. The provider's own total includes orders this workspace never read and counts refunds on its own
  terms; the figure that reconciles against the orders in front of the reader is worth more than the one
  that is larger.
- **`orders_synced_through` moves forward only**, settled in SQL, so a backfill of an older window
  cannot make the next sync skip the days between — the silent half of that failure.
- **The webhook insert uses `ON CONFLICT DO NOTHING` rather than catching the violation.** In PostgreSQL
  a constraint violation aborts the whole surrounding transaction, so catching one would turn a harmless
  redelivery into a failed request for any caller inside a transaction — and a handler that also writes
  an order will be. Found while writing the test for it.
- **An event with no provider id is processed anyway.** This product will not invent an identity from a
  payload hash: two genuinely distinct events can hash identically, and silently dropping the second
  would lose a real sale.
- **An unverified signature is recorded as `rejected`, not discarded.** The arrival is how somebody
  finds out an endpoint is being probed, and writing down which it was is the only way to be sure
  afterwards that it was not acted on.

### Not yet in place

- **No provider reads anything yet**; the adapters are Phases 12 and 13.
- **No HTTP surface**; store analytics arrive with Phase 14.
- **No cursors or run journalling**, deferred to Phase 12 where a real provider's pagination determines
  their shape.
- **Payments are folded onto the order.** A separate table earns its place when a platform reports split
  payments.

## Phase 10 — the Tracking Center

The surface that makes the measurement layer usable: one page answering the question an agency
cannot otherwise answer — is any of this actually working?

### Added

- `src/features/tracking/` — `TrackingCenterPage`, `IssueList`, `SourceRegistration`,
  `ConversionEditor`, types, api and hooks over all seven measurement endpoints.
- A `/tracking` nav item gated on `tracking.view`, English and Arabic translations, 11 component
  tests and 10 end-to-end tests.

### Changed

- Conversion save failures now show **the server's own message** rather than a generic one. Every
  refusal names the thing to change — which raw name is already taken, that a primary must also be a
  conversion — and the generic string threw that away. The fallback is now reserved for a failure
  with no message at all.

### Decisions worth recording

- **The issue list comes first.** A page opening on a tidy table of sources invites the conclusion
  that everything is fine, which is precisely the conclusion this product exists to prevent. There
  is an end-to-end test asserting the order, because it is a product decision rather than a layout
  one.
- **Evidence and remedy are on the page, not behind a tooltip.** A tracking centre that says
  "purchase event missing" without saying how it knows is one people stop believing after the first
  false alarm — and a false alarm is certain, because the product only sees what a provider reports.
  The evidence is what lets somebody disagree, which is the only reason they will trust it when it is
  right.
- **An empty issue list is a rendered state with its own words.** Silence is the goal, so a blank
  area would read as a loading failure.
- **Registering a property is discovery-driven.** Nobody can be expected to type
  `properties/412006881` correctly, and a mistyped id produces a source that syncs nothing while
  looking perfectly configured. The list is deliberately unfiltered, with the site and `G-` id beside
  each entry, because an agency's Google account holds properties for clients it has left.
- **The conversion editor never infers a meaning.** Raw names come from typing or from the property's
  key events; the meaning is the person's choice, and a suggestion applies one only where the
  product can cite it. The intent-signal warning appears at the moment of choosing, not only in the
  issue list later.
- **A sync reports its caveats.** A bare "synced" toast would hide that a run read 8% of the data,
  and the person who pressed the button is the one about to quote the number.
- **`0` and `—` are rendered differently for an observed count.** "Asked and never happened" and
  "nobody looked" are different facts, and collapsing them turns the second into the first.
- **An unreadable evidence value renders as a dash**, not `[object Object]`, which would look like a
  bug in the product rather than a gap in the formatting.

### Not yet in place

- **Container readiness is not on this page.** The endpoint is complete; placing a live provider call
  beside data that always loads needs a design decision worth making deliberately.
- **No charts.** An event-count trend was left out on purpose: this page answers "is it working", and
  a sparkline invites reading it as performance.

## Phase 9 — Google Tag Manager

Container readiness: whether what somebody built is deployed and able to fire. Read-only
permanently, and still BLOCKED_EXTERNAL for live credentials.

### Added

- **`TagContainerReadAdapter`**, `GoogleTagManagerReadAdapter` and `GoogleTagManagerMapper` over the
  Tag Manager API v2: `accounts`, `containers`, `versions:live`, `workspaces/{w}/status`.
- **`ContainerReadiness`**, a pure domain assessment, plus `ContainerFacts` and `TagFacts`.
- Six new `TrackingIssue` codes sharing the tracking vocabulary: `container_never_published`,
  `container_unpublished_changes`, `tag_paused`, `tag_without_trigger`, `tag_trigger_missing`,
  `measurement_id_not_in_container`.
- `ListTagContainers`, `GetContainerReadiness`, two endpoints, `docs/GOOGLE_TAG_MANAGER.md`, and 29
  tests.

### Decisions worth recording

- **A container is assessed, not synced.** It reports configuration rather than events, so it cannot
  be judged by freshness and has no place in the sync contract — forcing it there would mean an
  empty `fetchEventCounts()`, which reads as "measured nothing" rather than "not that kind of
  thing".
- **Readiness is its own endpoint, not part of `/measurement/health`.** Health is derived from our
  own tables and always answers; readiness needs a live call that can be slow, rate-limited or
  unauthorised. Folding them together would let a provider outage empty the tracking page of facts
  it already had.
- **The live version is read, never a workspace.** A workspace holds what somebody is working on.
  An agency believing tracking is configured because the workspace looks right is the single most
  common failure in this area, and reading the wrong one would make this product a participant in
  it. A container nobody published returns that as its only finding, because paused tags and missing
  triggers inside an unpublished container would bury the one fact that matters.
- **A tag's name is never grounds for a finding.** "GA4 – Purchase" is a label somebody typed; it may
  fire on a thank-you page, on every page, or on a button nobody clicks. Reporting "purchase
  tracking is configured" because a tag was called that would be confidently wrong in the one place
  an agency cannot afford to be, and the client would have no way to tell. The name appears only as
  a finding's subject, and a container whose sole tag is called that produces no findings at all.
- **Built-in triggers are never accused of being missing.** Every container has undeletable All
  Pages, Consent Initialization and Initialization triggers that the API does not return in the
  trigger list, and nearly every real tag fires on All Pages — so a naive comparison reports the
  whole container as broken. This was caught by the first realistic fixture. The threshold is chosen
  so the failure mode is **silence rather than a wrong accusation**: a missed dangling trigger costs
  one warning, a false one on every tag empties the page of meaning immediately. An id that is not a
  plain integer is likewise left alone — an id this code cannot parse is not one it understands well
  enough to call broken.
- **The measurement-id check is absence of evidence, not evidence of absence.** It scans for the
  id's own shape at any depth under any key, because which parameter key holds a measurement id
  depends on the tag template: finding the id is a fact about the container, assuming a key would be
  a guess. A miss is a warning phrased as a question, with the ids it *did* find as evidence, because
  a container can load a property through a custom template or a server-side container that this
  product cannot see. Telling somebody to "add the tag" when it is already there is how a tracking
  page loses its authority.
- **Unpublished changes span workspaces.** `getStatus` is per workspace and a container can have
  several; changes in any of them are unpublished from the live site's point of view.
- **A source pointing at an unreadable container fails with a 404.** Silently assessing a different
  container would be the worst available outcome.

### Not yet in place

- **The live transport**, on the same gate as Analytics.
- **Variables and folders** are read past rather than assessed: nothing honest follows from a
  variable's existence, and inventing findings to make the page look thorough is the opposite of the
  point.
- **Consent-mode configuration** is visible in `consentSettings` and deliberately not assessed.
  Whether a setup is compliant is a legal judgement about a jurisdiction, not a structural fact, and
  this product will not imply it has made one.

## Phase 8 — Google Analytics 4

The first source that fills `observed_event_counts`. Built against the published Admin API v1beta
and Data API v1beta contracts, read-only permanently, and still BLOCKED_EXTERNAL for live
credentials.

### Added

- **`MeasurementSyncAdapter`** and its registry, separate from the advertising ones: an ad platform
  is asked about campaigns and spend, an analytics property about which raw events arrived.
- **`GoogleAnalyticsMapper`** and **`GoogleAnalyticsSyncAdapter`** covering `accountSummaries.list`,
  `properties.dataStreams.list`, `properties.keyEvents.list`, `properties.getMetadata` and
  `runReport`.
- **`ReportQuality`** — GA4's own statements about the completeness of its answer, mapped to the
  honesty label a figure carries.
- **`SyncMeasurementSource`**, `DiscoverAnalyticsProperties`, `SuggestConversions`,
  `EloquentObservedEventWriter`, and `MeasurementFixtures` — a fixture loader that keeps whole
  responses rather than rows.
- Three endpoints: property discovery, conversion suggestions, and sync. `docs/GOOGLE_ANALYTICS.md`
  and 60 tests.

### Fixed

- **GA4, Tag Manager, Salla and Zid could not actually be connected.** Phase 6 added them to the
  provider registry with adapters, credential keys and sandbox accounts, and the
  `provider_connections` CHECK constraint written in Phase 2 still listed only the six advertising
  platforms plus OpenAI. A provider therefore appeared on the integrations page, offered a Connect
  action, accepted credentials through validation — and then failed at the insert with a constraint
  violation. Found by the first test that tried to connect GA4. The constraint is widened from the
  enum rather than dropped, since it is the reason an unknown provider string cannot reach a table
  half the product reads.

### Decisions worth recording

- **Report rows are read through their headers, never by position.** A `runReport` row is a
  positional array of `{value}`. Reading `dimensionValues[0]` because that is the order we asked for
  would work until somebody added a dimension, at which point every date becomes an event name —
  silently, with the figures still looking plausible. The fixtures list `eventName` before `date`
  so a positional reader fails the suite immediately, and the fixtures are stored as whole
  responses rather than rows precisely so the headers exist to be read.
- **The value metric is asked for, not assumed.** Custom metrics differ per property, and a
  property with no revenue data blocks its currency metrics with `NO_REVENUE_METRICS` — requesting
  one anyway turns every sync into a 400. So `getMetadata` is read and a metric GA4 itself declares
  `TYPE_CURRENCY` is used. A property reporting no money is read for counts only, which is the
  honest outcome: a hard-coded name we had not verified would be an invented metric, and a stored
  zero would be worse.
- **GA4's completeness signals decide the figures' label.** Sampled, thresholded, truncated, or
  rolled into `(other)` — each makes the run `PARTIAL` rather than `SYNCED`, with one sentence per
  cause. A tool that reads the rows and drops the flags reports 412 leads with the same confidence
  whether it saw all of them or 4% of them, and the client is told 412 either way. A sampling
  fraction under 1% is shown to two decimals, because "0%" for a 0.4% sample reads as "no data"
  when it means the figure is a two-hundredfold extrapolation.
- **An empty report is not a defect.** `emptyReason` is recorded and deliberately not counted as
  partial: a property with no traffic in the window is a fact about the client.
- **`(other)` is never stored as an event name or a date.** As a name it would offer somebody a
  conversion called "(other)" to map; as a date, a row we cannot place is dropped rather than
  parked on today, which would move someone else's traffic into this morning.
- **Key events are suggestions, and the citation bar is strict.** A normalised event is proposed
  only for names Google documents with a defined meaning. `generate_lead` is **not** proposed,
  because it does not appear on either page the map was built from — a suggestion the product
  cannot cite is a guess wearing a citation's clothes. The cost of asking is one dropdown; the cost
  of getting one wrong is a conversion that counts the wrong thing for a year.
- **Roll-up properties are flagged.** A roll-up counts the same event its source properties do, so
  measuring a client through both doubles every figure, and nothing later in the product could
  detect that it had happened.
- **Discovery is unfiltered.** An agency's Google account holds properties for clients it has left,
  and that is exactly the list a person must read carefully rather than one to prune for them.
- **The sync re-reads a window instead of resuming from a high-water mark.** Analytics figures keep
  moving after the day closes, so "since last time" would freeze every day at its first, lowest
  value. The writer upserts on `(source, raw name, date)` — inserting would multiply a client's
  conversions by the number of times the sync had run, which would look exactly like growth.
- **The weakest label in a run wins**, rather than labelling page by page: a chart totalling a
  complete day and an estimated one is an estimate, and a reader cannot see which rows contributed.
- **`last_synced_at` is written even on a failure**, because "we tried and got nowhere" is a
  different fact from "nobody has asked". **`last_event_at` only moves forward**, settled in SQL
  with `greatest()`, so a backfill of an older range cannot age a healthy source into a critical
  warning.
- **`Page` moved to `App\Application\Shared\DTO\ProviderPage`.** One page from somebody else's
  API is not an advertising concept, and measurement needed it without coupling to advertising.

### Not yet in place

- **The live transport.** Needs a Google Cloud project with both APIs enabled, an allow-listed
  OAuth redirect URI, and a user who can read the property. Real credentials fail loudly and name
  what is missing; there is no path that invents a count.
- **Currency exponents.** Revenue rounds at two decimal places through the shared `Money` value
  object, so JPY and KWD are wrong. A pre-existing limitation shared with every advertising mapper,
  recorded rather than worked around in one mapper.

## Phase 7 — the measurement foundation

Where a number gets its meaning. Every later phase — store analytics, lead intelligence,
journeys, attribution, the AI analysis, the generated decks — resolves conversions through this
layer, so a guessed meaning here would make all of them confidently wrong in a way no chart
reveals.

### Added

- **Measurement sources** (`measurement_sources`): what reports events or orders for a client —
  a GA4 property, a GTM container, an ad account, a store, a CRM, a website form, a figure
  somebody types. Client-scoped by default, so a project read returns its own sources *and* the
  client-level ones it inherits.
- **`NormalizedEvent`** — a fourteen-case vocabulary, each case answering whether it is a
  conversion by nature and whether it is an intent signal only.
- **The conversion registry** (`event_definitions`, `event_mappings`): a named thing a project
  counts, and the raw event names that mean it.
- **`observed_event_counts`** — one source, one raw name, one day, with an optional value in
  minor units. The aggregate the diagnosis reads.
- **Tracking health** — `GET /api/measurement/health`: a pure diagnosis over ten issue codes,
  every one carrying the evidence it rests on and a remedy, sorted worst-first.
- `MeasurementFreshness` (`live`, `synced`, `stale`, `silent`, `never_synced`), `TrackingIssue`,
  `TrackingSeverity`, `MeasurementSourceKind`.
- Nine endpoints, `tracking.view` / `tracking.manage`, five audit actions, two new docs —
  `docs/MEASUREMENT.md` and `docs/TRACKING.md` — and 52 tests.

### Decisions worth recording

- **Nothing infers meaning from an event name.** One property's `contact` is a form submission,
  another's is a phone tap, a third fires it on the contact page. A raw name counts towards a
  conversion only because somebody mapped it, and that mapping has an author and an audit
  entry. The cost is that a person has to do the mapping; the alternative is revenue figures
  nobody can defend. `purchase_complete` arriving unmapped is reported as unmapped.
- **`stale` and `silent` are deliberately different states.** Stale means the figures are old.
  Silent means the source is syncing perfectly and measuring nothing — and that is the failure
  this whole area exists to catch, because a report built on it comes out quietly empty rather
  than visibly broken. Zero looks exactly like a bad month.
- **A source's kind must match its provider's category.** Registering a Salla store as an
  analytics property would put invoiced revenue into the column that holds what a browser
  reported. Those disagree by design, and once they share a column nobody can say which is
  wrong. The Phase 6 registry already knows each provider's category, so the check costs one
  comparison.
- **Only a store and a CRM are revenue authorities.** An analytics property reports what a
  browser said a sale was worth — measured before the payment failed or the item came back. An
  ad platform reports what it believes it caused, which is a claim about its own
  effectiveness. Neither is an invoice.
- **Intent signals are flagged, not refused.** A WhatsApp click is not a conversation and a form
  start is not a submission. An agency may genuinely want to count clicks; the danger is
  somebody reading the figure as leads six weeks later, so the product names it and lets them
  decide.
- **Freshness and the whole diagnosis are derived on every read.** A stored diagnosis is wrong
  the moment the next sync lands, and a stale warning is worse than no warning: it teaches
  people to ignore the list, which costs more than the one issue it got wrong.
- **`critical` is narrow on purpose.** It means something the client is paying to have measured
  is not being measured at all. A list where everything is urgent is a list nobody reads, and
  this feature is worth nothing the moment it stops being read.
- **Every issue carries its evidence.** A tracking centre that says "purchase event missing"
  without saying how it knows is one people stop believing after the first false alarm — and a
  false alarm is certain, because this product can only see what a provider reports to it. The
  evidence is what lets somebody disagree, which is the only reason they will trust it when it
  is right.
- **An edit replaces a definition's mappings outright, and the audit keeps both sides.** "Leads
  halved in March" is sometimes a campaign and sometimes a mapping somebody edited, and nothing
  else can tell them apart afterwards.
- **`observed_count` is null when no window was asked for, and `0` when one was and nothing was
  seen.** Answering "0" to a question nobody asked reads as "never happened".
- **Sessions and touchpoints were moved out of this phase to Phase 16.** They are identity
  questions, and answering them without identity evidence and a confidence level would be the
  guessing this phase exists to refuse.

### Not yet in place

- **Nothing populates `observed_event_counts` in production.** GA4 (Phase 8) and the commerce
  adapters (Phases 11–13) fill it. Until then a real workspace's honest tracking answer is
  `never_synced` — which is the correct answer, not a gap in the feature.

## Phase 6 — the provider registry

One canonical catalogue of every platform, consumed by both ends. The registry existed in
miniature already — an enum of providers with capabilities and auth methods — and this makes
it the thing the directive asks for: the single source of truth for provider vocabulary.

### Added

- `ProviderCategory` (advertising, analytics, tag management, commerce), `ProviderApiStatus`
  (generally available, requires approval, no public API), `ProviderEntity` and
  `ProviderRateLimit`.
- Four new providers in the registry with connection adapters, honest statuses and sandbox
  accounts: **Google Analytics 4**, **Google Tag Manager**, **Salla** and **Zid**. Their
  sync adapters come in their own phases; the registry entries are what make them appear
  with a status somebody can act on.
- Every registry field is published on `GET /api/integrations/providers`, and the
  integrations page now groups providers by category — eleven cards in one grid is a list to
  scan rather than a page to read.

### Decisions worth recording

- **The registry and the connection state travel on one response.** Splitting them would
  mean every page needing a provider's *name* had to join two endpoints, which is how a
  second list of platform labels gets written — the drift this registry exists to prevent.
- **`api_status` is separate from `blocked_external`.** The second says a workspace cannot
  proceed; the first says why — a programme to be admitted to, an app to register, or no
  public API at all. Those need different actions from different people, and rendering all
  three as "not connected" sends somebody looking for a setting that cannot help them.
- **Capabilities that span categories declare no category.** Discovering accounts, reading
  reporting figures and receiving webhooks are things an ad platform, an analytics property
  and a store all do. Filing them under a convenient one would have made the registry's own
  consistency check meaningless — and that check is what stops a store claiming it can
  report campaign metrics.
- **GA4 and Tag Manager declare no write capability at all**, though both publish write
  APIs. Changing somebody's measurement setup from a reporting tool is a hazard, not a
  feature, and the registry is where that decision is visible rather than implicit in what
  we happened not to build.
- **An undocumented rate limit is null, never a guess.** Tag Manager's is published and
  surprising — 0.25 QPS and 10,000 requests a day, both per *Google Cloud project*, so every
  workspace on one deployment shares it, and exceeding it answers 403 rather than 429. Salla
  and Zid publish nothing, so they say so and back off on the provider's own throttling.
- `ProviderCategory::totalsWith()` encodes the rule the whole taxonomy is for: figures never
  total across categories, because an ad platform's claimed revenue, an analytics property's
  recorded revenue and a store's invoiced revenue are three different numbers.

### Tests

Nine registry tests, including that a provider claiming an API must have documentation and
entities to go with it, that the read-only integrations declare no writes, and that no
provider borrows another category's capability. The existing architecture test caught the
four new providers before any of this ran — it asserts every provider in the enum has an
adapter, which is what it was written for. Backend 676 passed, frontend 173.

## Phase 5 — budgets, pacing and forecasting

Completes Phase 5. The governance hierarchy from CLAUDE.md, the arithmetic a client's
money is judged by, and the two surfaces that make it usable.

### Added

- `budgets` and `budget_allocations`: an approved client budget split across platforms,
  campaigns and ad groups as a self-referencing tree. Five CHECK constraints carry the
  rules, including that a budget marked approved cannot exist without the name of whoever
  approved it.
- `budgets.view`, `budgets.manage` and `budgets.approve`. The third is separate and
  **media buyers do not have it**: drafting a plan and committing a client to a figure are
  different acts, and that separation is the only thing making "approved" mean anything.
  Client viewers have none of the three — what a client may see is decided by explicit
  sharing in Phase 7, not by a role that happens to open a screen.
- `Pacing`, pure arithmetic over four facts: the budget, the period, actual spend and
  today. Seven statuses, because "over budget" and "spending too fast" are different
  problems with different answers.
- Pacing, the alert list and forecast rates are **derived on read**, never stored. A
  stored pacing figure is wrong the moment the next sync lands, and a stale alert is worse
  than none because it teaches people to ignore the list.
- A forecast in both directions — budget → results and target → budget — from the
  workspace's own delivery.
- The immutable change log the spec asks for, written to the existing audit trail rather
  than a second history table. Approving a budget is a **security event**: committing a
  client's money has to be as findable as a credential change.

### Decisions worth recording

- **An approved budget's amount, period and currency cannot be edited.** A figure that
  can be quietly revised says nothing about what a client agreed to. Changing one means
  archiving it and approving a new one, which leaves both on the record; allocations stay
  editable, which is how a plan adapts without the commitment moving.
- **Allocations may sum to less than the budget, never more.** The unallocated remainder
  is reported at every level: it is a decision nobody has made yet, and seeing it is how
  someone remembers to make it.
- **Pacing is even by definition**, with the 10% tolerance published alongside the figure.
  A weighted pace is a forecast wearing a plan's clothes, and a reader comparing today's
  spend against "expected" has to know exactly what expected means.
- **Spend is read at campaign level only.** `daily_metrics` holds the same spend three
  times, once per level; adding them would roughly triple it, and the result would look
  entirely plausible next to a budget.
- **No industry benchmarks.** Forecast rates come from the workspace's own observed
  delivery or they do not come at all. A platform with fewer than three days of delivery
  gets a reason instead of a number; bands are the 25th–75th percentile of the account's
  own daily rate rather than a percentage chosen to look like a range; headline rates are
  weighted (total spend over total clicks) rather than an average of daily rates, which
  would let a quiet day with two clicks count as much as a busy one.
- **Every projection is labelled `estimated`**, carries its assumptions in words, names
  what it could not work out, and publishes a version string identifying the sample — so a
  figure quoted in a meeting can be reproduced, and a different version means the numbers
  have moved. The disclaimer is in the payload rather than left to the interface to
  remember.

### Refusals, each of which would otherwise produce a plausible screen

- A **draft** is not paced: a variance from a figure nobody agreed to means nothing.
- Spend that **cannot be totalled** — two currencies, a currency the budget is not in, or
  sources that cannot combine — yields no total *and no pacing*, with the reason. Pacing
  against a partial figure would understate spend, which is the one direction that
  overcharges a client without anybody noticing.
- An exchange rate is **never invented** to reconcile a budget with spend in another
  currency.
- An allocation planned before its campaign exists has nothing to compare with, and says
  so rather than showing a zero.
- A budget whose spend could not be totalled still appears in the alert list, with its
  reason. Dropping it would make the list look reassuringly short.

### The two surfaces

- A **Media Planner** holding the budget list, the draft form, approve and archive, the
  allocation editor and the pacing panel on one screen, because those are one
  conversation. A planner looking at an overspending platform wants to move money now.
- The **allocation editor** shows the remainder at every level and does not auto-fill it:
  spreading the leftover would be a media decision made on somebody's behalf and called a
  convenience. Over-allocation warns here and is refused by the server — the warning is
  the fast feedback, the refusal is the rule.
- Platform choices come from the **provider registry**, filtered to providers whose API
  can report metrics. No page keeps its own list of platform names any more; the labels
  that were briefly hard-coded here are gone.
- A **pacing chart** answering one question: is this budget going to land where it was
  meant to? Cumulative spend against the even pace, with the approved budget as a line.
  The actual series stops at the last reported day rather than running flat to today,
  because a flat cumulative line means "spent nothing since" and a missing sync means "we
  do not know".
- A **Budget Calculator** in both directions, showing each figure's band, its confidence,
  the assumptions in words and the sample version — and a reason instead of a number where
  a platform has too little delivery.
- `Choice`/`ChoiceGroup` promoted out of the dashboard widget editor into shared UI, so
  the planner, the calculator and the builder share one labelled-toggle pattern rather
  than three copies.

### Tests

20 domain tests on the pacing arithmetic alone, including that a budget nobody has set is
reported as *unknown* rather than "on track"; 58 backend feature tests covering approval,
visibility, the allocation tree, pacing against really-synced spend and the forecast's
refusals; 30 frontend tests including that a role which may plan is not offered approval,
and that an untotallable spend shows its reason rather than a zero; 9 Playwright specs.
Backend 668 passed, frontend 173, E2E 136, PHPStan level 8, Pint, ESLint and Prettier
clean.

## LinkedIn sync

Sixth and last provider with a published contract. Field names, enum values and
response shapes were read from LinkedIn's reference, which is the best documented of the
six — full field tables with complete enumerations.

### Added

- `LinkedInMapper` and `LinkedInSyncAdapter` for the versioned `/rest/` API, with 28
  mapper tests and 12 sync tests.
- `_date_objects`, a fixture-loader directive that writes a date as
  `{year, month, day}` integers. LinkedIn sends no date string anywhere in a reporting
  response, so without it either the fixture or the mapper would have to use a shape no
  live row has.

### The hazard, recorded in three places

**A LinkedIn "campaign" is an ad set.** Its hierarchy is account → campaignGroup →
campaign → creative, and the *campaign* is what carries targeting, a bid, a budget and
an objective. So a campaign group lands at our campaign level, a campaign at our ad
group level, and a creative at our ad level. Get it wrong and every roll-up in the
product is off by one level, which is why it is stated in the adapter, the mapper,
docs/INTEGRATIONS.md and the fixture README rather than left to be deduced — and why the
sync test asserts the whole chain creative → campaign → campaign group.

### Conventions handled

- Money is a **decimal string inside an object** (`{"amount": "180000.00",
  "currencyCode": "SAR"}`), and analytics money is a bare decimal string that may carry
  more than two decimals (`"19.91833"`). Rounded half-up at the minor unit, as their
  billing does.
- **Schedules are epoch milliseconds**; read as seconds a 2025 date becomes the year
  57560.
- Ids are URNs in some places and longs in others, so everything is reduced to the
  trailing id.
- **`servingStatuses` decides delivery, not `status`.** `RUNNABLE` is its only serving
  value, so an ACTIVE group that has spent its budget is reported as not delivering with
  `CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD` carried through untranslated.
- `CANCELED` maps to *removed*, not *completed*: it is permanent and not
  re-activatable, and "completed" would say it ran its course.
- A creative has no format field — the ad format is on the campaign — and often no name
  either, so it is labelled by the post it sponsors.
- A creative names only its campaign, so the campaign group is resolved from the
  campaign already synced: the third provider running whose ad level needs that.
- `approximateMemberReach` is deduplicated members and only returned for ranges of 92
  days or less, which is why the insight window is 92. A parent's reach is below the sum
  of its children's, and the fixtures encode that so a test cannot pass by summing the
  wrong level.
- `conversionValueInLocalCurrency` is one total rather than a figure per type, so it is
  the row's revenue and no breakdown row carries a value. LinkedIn does publish
  conversion worth, unlike X.
- `JOB_APPLICANTS` is filed as *other*, not *leads*: a job applicant is not a sales
  lead, and hiring spend in a conversion chart a client reads as pipeline would mislead.

### Not read

The post a creative sponsors — where its headline, image and link live — and the revenue
attribution finder, which needs a CRM connected to Business Manager. LinkedIn also warns
that its reach and demographic figures differ slightly between reporting levels because
they are approximate; nothing here reconciles that away.

## X Ads sync

### Added

- `XAdsMapper` and `XAdsSyncAdapter` for X Ads API v12, with field names, metric names
  and response shapes read from X's published reference. 32 mapper tests and 15 sync
  tests.
- Fixtures in X's real stats shape, including a campaign that reports nothing at all
  and a line item whose metrics X omits entirely rather than zeroing.

### Conventions handled

X is the first provider here that is shaped differently rather than merely spelled
differently:

- **Performance is a time series.** One row holds one array per metric, aligned to the
  requested range — so a row is a range, not a day, and bucket *i* is the start plus
  *i*. The adapter contract now returns a *list* of records per row for this reason; a
  mapper reading only the first bucket would silently discard every other day.
- **The objective is on the line item**, so an X campaign carries none at all rather
  than one borrowed from a child.
- **Reach is a separate endpoint**, so every X row reports no reach rather than
  impressions standing in for people.
- **Auth is OAuth 1.0a** — the only provider here that is not OAuth 2.0, and the only
  one with no refresh token to rotate.
- A promoted tweet carries no campaign id and no name: the campaign is resolved through
  the line item, and the row is labelled by its tweet.
- A card is assembled from components (MEDIA, SWIPEABLE_MEDIA, DETAILS, BUTTON) and X
  derives `card_type` from them, so the medium, headline, button and destination are
  read from the components. A SWIPEABLE_MEDIA component is what makes a carousel.
- A metric with no data arrives as `null`. X documents null and zero as equivalent for
  it; they are not equivalent here, so the null survives.

### Fixed

- **The insight window was clamped instead of split.** Each adapter states how much a
  provider will answer in one request, and the sync shortened the range to fit. X is
  the first provider whose ceiling (7 days) is less than its attribution lookback (28),
  which meant it could never re-read its own attribution window: a purchase attributed
  ten days back would have stayed invisible and the client would have seen a worse ROAS
  than they earned. The range is now asked for in consecutive windows.
- **`CANCELLED_REQUEST` (408) and `LOCK_ACQUISITION_TIMEOUT` (423) were filed as
  unknown**, and so reported to an operator as something to fix. Both are documented X
  conditions that clear on retry, and both are now classified as transient.

### Not verified

`reasons_not_servable` and `effective_status` have no published enumerations — X's
examples show only `PAUSED_BY_ADVERTISER`/`INCOMPLETE` and `UNKNOWN`/`RUNNING`, and the
fixtures use nothing else. Only those two reason codes are interpreted; any other still
yields "not delivering" because `servable: false` says so, and the code reaches the
interface untranslated. Card types beyond the one documented example are read by their
leading token rather than matched against an invented list.

## Snapchat sync

### Added

- `SnapchatMapper` and `SnapchatSyncAdapter`, with field names, enum values and
  response shapes read from Snap's published reference. 27 mapper tests and 11
  sync tests.
- An `unwrap()` hook on the sync adapter base. Snapchat wraps every list item as
  `{sub_request_status, <entity>: {…}}` because a batch can partly succeed, so a
  failed row carries no entity and is dropped rather than mapped into a record with
  an empty id. Unwrapping is a provider concern, not a mapper one.
- The store now resolves an ad's campaign through its ad group when the provider does
  not supply one. Snapchat's ad object carries only `ad_squad_id`; without this the
  sync would silently discard that provider's entire ad level.

### Conventions handled

Snapchat is the fourth distinct set in four providers: money in **micros** (like
Google), a lifetime **spend cap** rather than a lifetime budget, a click called a
**swipe** with no separate link-click metric, reach as `uniques`, quartiles as
**counts** (unlike Google's rates), `bid_micro: 0` meaning auto-bid rather than a
zero bid, and delivery state in a `delivery_status` array because `status` is only
ACTIVE or PAUSED.

### One unverified assumption, recorded rather than hidden

Every money field Snap publishes carries a `_micro` suffix **except** the stats
metric `spend`. It is mapped as micro-currency for consistency with the rest of their
API, which is the likely reading — but it could not be confirmed, and if it is wrong
spend is understated by a factor of 10,000. The fixtures encode the same assumption,
so no test can catch it; only a live response can. Flagged prominently in
`docs/INTEGRATIONS.md` and `docs/ROADMAP.md` as the most consequential open item
across all four providers.

## Phase 4 — the dashboard builder

Completes Phase 4. The foundation commit's schema and ports now have use cases,
repositories, an API and a working builder behind them.

### Added

- Saved views: `/api/analytics/views` with full CRUD. A **preset** is stored rather
  than the dates it resolved to, so a view called "last 28 days" still means that next
  month; an explicit range is stored literally, because a closed month is a legitimate
  thing to save. The query is rebuilt into a real `AnalyticsRequest` on the way in, so
  a view saved today still parses in six months.
- Dashboards: `/api/dashboards` with CRUD plus duplicate. The widget set is replaced
  rather than merged, omitting `widgets` leaves the canvas alone, and `widgets: []`
  empties it — three distinct instructions, all supported.
- A builder UI covering add, remove, reorder, resize, retitle, chart type, metrics,
  grouping, widget-level filter overrides, audience, duplicate and save. Responsive,
  Arabic RTL, dark mode, with loading, empty, error and permission states.
- Grid arithmetic as pure functions (`layout.ts`) with 14 unit tests: every reorder
  and resize re-flows into a valid layout, so an invalid one is prevented rather than
  refused on save.
- 57 backend tests, 32 frontend tests and 11 Playwright specs.

### Decisions worth recording

- **Reordering and resizing are buttons, not drag-and-drop.** Drag-only reordering
  fails WCAG 2.2's dragging criterion, does not work with a keyboard, and is awkward on
  touch. Pointer dragging can be layered on the same model later.
- **Each widget runs its own query** rather than the dashboard running one and slicing
  it. That costs requests and buys the thing that makes a dashboard useful: widgets can
  ask different questions. TanStack Query dedupes identical ones.
- **A duplicate is always private**, whatever the original was. Inheriting a shared
  audience would publish a draft the moment it was created.
- **Visibility is applied in SQL**, not filtered from a response: a private record
  belonging to someone else is never in the result set.

### Fixed

Four real defects, found by the tests rather than by review:

- Clicking "New dashboard" then Save would **overwrite an existing dashboard**. The
  landing effect re-selected one while the draft was open, and the save target was read
  from that selection. The selection is now derived rather than synced into state by an
  effect, which makes the whole class of bug impossible rather than merely fixed — and
  the ESLint rule about setting state in an effect was pointing at exactly this.
- The page read `.description` off a dashboard narrowed only against `undefined`, so a
  query settling with `null` crashed the whole surface instead of showing an empty state.
- A save mutation resolving with nothing threw inside `onSuccess`, turning a successful
  write into an error the reader could not act on.
- **Reusing a name returned a `500` with the raw SQL in it.** Dashboard and saved-view
  names are unique per owner, enforced by an index that nothing asked about first, so
  naming a second dashboard "Weekly review" failed as an insert — and in any
  environment with debug on, the response carried the constraint name, the column
  list, the workspace and user ids and the whole statement. Both use cases now check
  the name and answer `422 name_already_used` naming the offending dashboard. The
  duplicate endpoint is the exception: it chose "(copy)" itself, so it climbs to
  "(copy 2)" rather than failing the caller for a name they never typed.

### Testing

The E2E harness ran the API on PHP's built-in server with a single worker, which
accepts one connection at a time. Two browser projects run at once and every widget
issues its own analytics query, so a sign-in could queue behind several half-second
aggregations until the browser reported the server unreachable — which reads exactly
like an application bug. `scripts/e2e.sh` now forks workers, and the dashboard spec
namespaces its records per project, because both projects share one database and one
demo account.

## Phase 4 — dashboard and saved-view foundation

Schema, domain rules and ports only. The use cases, API and UI are **not** done, so
the feature is not yet reachable from the product — see docs/ROADMAP.md.

### Added

- `saved_views`, `dashboards` and `dashboard_widgets` tables. Two CHECK constraints
  carry rules the application would otherwise have to remember: a project-scoped
  view cannot exist without a project, and a widget cannot be stored outside the
  12-column canvas.
- `Visibility`, with three states rather than a boolean — the third exists because an
  agency's real need is a dashboard one client's team can see and colleagues working
  for a different client cannot.
- `WidgetType`, which declares the shape of question each type can answer, so a
  share-of-total across 90 dates is refused rather than drawn.
- `GridPlacement`, which raises on an impossible placement rather than clamping:
  clamping moves a widget the reader deliberately placed, and an off-grid widget
  vanishes from the dashboard with nothing to say it was there.
- 12 domain tests covering those rules.

## Phase 4 — analytics interface

### Added

- **ECharts** behind a wrapper that registers only the chart types in use, reads its
  palette from the OKLCH design tokens at render time, resizes via `ResizeObserver`,
  honours `prefers-reduced-motion`, and requires an `ariaLabel` — a canvas is
  invisible to a screen reader, so a chart without one is a hole in the page.
- Chart series tokens (`--ds-chart-1` … `--ds-chart-8`), deliberately separate from
  the platform colours so a chart of four Meta campaigns is not four shades of one
  blue, and lifted in dark mode without changing hue.
- An analytics surface: scorecard, trend chart driven by the tile a reader selects,
  spend breakdown, efficiency-against-scale scatter, and a detail table that keeps
  each entity's platform id.
- A campaign explorer drilling platform → campaign → ad group → ad, where each step
  narrows the scorecard and chart as well as the table.
- An overview screen showing four headline figures and spend by platform.
- `MetricCard`, `SourceBadge` and `SourceNotice`: a null figure renders as an em
  dash with the server's reason beside it, anything not `live` is badged, and the
  notice sits above the numbers so a reader meets the caveat first.
- Full Arabic translation of every new surface, and charts mirrored rather than
  merely translated.
- 42 new frontend tests and 8 new Playwright specs, including one asserting that a
  sandbox surface says so before showing a figure.

### Changed

- The two chart surfaces are lazily loaded. The initial bundle went from 454 kB
  gzipped to 243 kB, with the 209 kB chart chunk arriving on first visit. This also
  required keeping the page components out of `features/analytics/index.ts`: the
  overview screen importing a hook from that barrel pulled the whole engine back
  into the initial bundle.
- `formatCurrency` keeps the minor unit on every amount. It previously dropped the
  decimals above a thousand for density, which is wrong for figures reconciled
  against a platform's invoice — and made a column where 840.25 kept its cents and
  12,500.00 did not.
- The metric vocabulary moved to `shared/metrics`. `shared/ui` needs it, and a
  shared component importing from a feature inverts the dependency — caught by the
  lint rule rather than by review.
- `/analytics` and `/campaigns` nav items are gated on `analytics.view` rather than
  `projects.view_assigned`: offering a surface to a role that would receive a 403 is
  worse than not offering it.
- The demo seeder connects three providers in sandbox mode, binds them and runs the
  real sync. An earlier version of that file said it never would; the reasoning
  still holds, and what makes it safe is the labelling downstream rather than the
  absence of data — a demo workspace with no performance data cannot demonstrate or
  test the analytics layer at all.

## Phase 4 — analytics layer

### Added

- `Metric` and `Dimension` domain enums: 28 metrics with their format, precision,
  direction, dependencies and whether they may be summed across entities.
- `AnalyticsReadModel` and its SQL implementation, answering scorecard, series and
  breakdown from one query with one set of filters.
- `GET /api/analytics` and `GET /api/analytics/catalogue`.
- `analytics.view`, `analytics.export` and `saved_views.manage` permissions, and
  `access:sync` to bring existing workspaces' roles in line with the code.
- 28 analytics tests, asserted against data the real sync wrote from the SANDBOX
  fixtures rather than hand-built rows — so the aggregation cannot agree with
  itself while disagreeing with the ingestion.

### Notable refusals, each returning null with a reason

- **One level only.** A campaign total is the campaign row, never the sum of its
  ads. Both levels hold figures for the same money, and reading them together
  roughly doubles every total.
- **No summed reach.** It counts deduplicated people, so adding two campaigns'
  reach double-counts anyone who saw both. Reported per entity, refused across
  entities, with the count of entities in the message.
- **No cross-currency money.** Converting needs a rate, a date and a decision
  about which rate, none of which belongs in a read model.
- **No ratio from a zero denominator**, and no percentage change from a period
  with nothing in it. "Up from nothing" has no percentage, and +∞ or +100% is a
  number someone will quote in a meeting.

### Fixed

- `SyncAccessCatalogue` reached into Infrastructure from the Interface layer — a
  real layering violation the architecture test caught. It now goes through an
  Application port.
- Two tests used `limit(1)->update()`. PostgreSQL has no `LIMIT` on `UPDATE`, so
  that quietly rewrites the whole table or nothing; they target rows by key.

## TikTok sync

### Added

- `TikTokMapper` and `TikTokSyncAdapter`, mapping structure and the reporting
  envelope from TikTok's own reference: `budget` as a float in the account
  currency, `operation_status` versus `secondary_status`, `{dimensions, metrics}`
  rows with every value a string, and the literal `"-"` sentinel for an
  unavailable metric — which becomes null, never zero.
- 23 mapper tests and 3 cross-provider sync tests, including one proving three
  providers land in the same tables without colliding on campaign ids.

### Deliberately not added

- TikTok's click, video and revenue metric names. Its supported-metrics reference
  is only reachable from inside its own documentation SPA, so those names could
  not be confirmed. The mapper keeps a `VERIFIED_METRICS` list, leaves the
  unverifiable metrics **null**, and carries every unrecognised metric into
  `metadata.unmapped_metrics` untouched. A gap can be labelled; a plausible
  figure in the wrong column cannot be detected.

### Fixed

- TikTok's `bid_price: 0` (returned when bidding is automatic and the real target
  is `conversion_bid_price`) was being read as a bid of zero, which asserts
  something about the campaign rather than reporting an absence.

## Google Ads sync

### Added

- `GoogleAdsMapper` and `GoogleAdsSyncAdapter`, reading campaigns, ad groups, ads
  and insights from SANDBOX fixtures in protobuf-JSON shape. Field names were
  taken from the published **protobuf definitions** rather than the HTML field
  reference, which renders client-side — and that immediately corrected a mapping
  a reasonable guess would have got wrong: v22 has no `metrics.video_views`. The
  metric is `videoTrueviewViews`, and quartile completion exists only as a rate.
- 38 new tests covering micros conversion, shared budgets, `primaryStatus` as the
  real delivery signal, fractional conversions, and the metrics Google simply
  does not publish.
- `ConversionActionType::StoreVisit`, which Google reports as a category.

### Changed

- The sync adapter base class now owns the paging loop and the sandbox gate, so a
  provider contributes only its fixture names and its row mapping. Adding Google
  took a 90-line adapter instead of a 200-line one.
- `EloquentAdvertisingStore` memoises its id and parent maps for the lifetime of
  a sync, invalidating per model on write. Without it every page re-read the whole
  campaign, ad-group and creative tables; a Google sync test went from 34s to
  0.2s.
- Sandbox fixtures now page structure at 2 rows and performance at 50. Structure
  stays tiny so every sync crosses page boundaries and cursor handling is
  exercised; paging a fortnight of ad-level rows two at a time bought no coverage
  and cost every test run.
- The fixture loader supports a `_date_paths` directive, so a provider that nests
  its date (Google puts it under `segments`) gets it where a live response would
  put it.

### Documentation

- `docs/INTEGRATIONS.md` gains the Google Ads field-mapping table, including the
  metrics Google does not expose and why they stay null rather than being
  approximated.

## Phase 3 — campaign data model, sync and normalised metrics

### Added

- Advertising schema: `campaigns`, `ad_groups`, `creatives`, `creative_assets`,
  `ads`, `daily_metrics`, `conversion_metrics`, `sync_runs`, `sync_cursors`,
  `provider_errors`.
- Normalised advertising vocabulary (`EntityStatus`, `CampaignObjective`,
  `BudgetMode`, `ConversionActionType`, `CreativeFormat`, `AdEntityType`,
  `SyncKind`, `SyncStatus`, `ProviderErrorCategory`) and the `Money` /
  `MetricSet` value objects.
- `MetricSource` on every metric row, with aggregate rules: a total inherits its
  weakest input's label, and sandbox or estimated figures never combine with
  actuals.
- `AdvertisingSyncAdapter` port and `MetaSyncAdapter`, reading campaigns, ad sets,
  ads, creatives and insights from SANDBOX fixtures shaped like real Graph API
  responses. `MetaMapper` handles Meta's minor-unit budget strings, decimal spend
  strings, `effective_status`, the ODAX objective rename, and nested `actions`
  categories that must not be summed.
- Idempotent `AdvertisingStore`, a `SyncJournal` recording every run and failure,
  the `SyncExternalAccount` / `SyncBoundAccount` use cases, a per-account-unique
  queued job with provider-aware backoff, and the `advertising:sync` command.
- 51 new tests: Meta normalisation, sandbox fixture loading and pagination,
  advertising domain rules, and an end-to-end sync covering isolation, hierarchy
  linking, null-versus-zero, source labelling, idempotency and the journal.

### Changed

- `Money::toDecimal()` now returns an exact decimal **string** built with integer
  arithmetic, and the lossy float conversion moved to `toFloat()`. A class whose
  reason to exist is that floats cannot hold 0.1 should not hand one back at the
  last step.
- Sandbox-ness moved from the adapter to `Credentials::isSandbox()`: whether a
  connection is a sandbox decides how every number it produces is labelled, which
  is a business rule rather than an adapter detail. A connection counts as a
  sandbox only when *every* required credential is the token.
- `conversion_metrics.attribution_window` is not nullable and defaults to
  `'default'`. PostgreSQL treats `NULL`s as distinct in a unique index, so a
  nullable column there made every re-sync insert a second row — conversions
  doubled on each re-read. Caught by the idempotency test.
- Test memory limit raised to 512M: the architecture tests parse every source
  file, so the suite's need grows with the codebase.

### Documentation

- `docs/DATA_MODEL.md`, `docs/INTEGRATIONS.md` and `docs/ROADMAP.md` updated,
  including an explicit record of why sandbox performance data now exists after
  an earlier decision against it, and what makes it safe.
- `backend/database/fixtures/providers/README.md` documents the fixtures'
  provenance and the single way they depart from a verbatim API response.


## [Unreleased]

### Added — Phase 0: foundation

**Backend**

- Laravel 12 API-only application on PHP 8.4, with the Blade/web scaffolding removed.
- Clean Architecture layers (`Domain`, `Application`, `Infrastructure`, `Interface`)
  plus a composition root, enforced by Pest architecture tests.
- PostgreSQL as the only datastore, with UUIDv7 primary keys on API-facing entities.
- Redis for cache, queue, session and broadcasting; Horizon supervising workers;
  Reverb serving WebSockets.
- `GET /api/health` probing database, cache and queue with a three-valued status
  (`ok`/`degraded`/`down`); 503 only when a dependency is down.
- Security baseline: strict CORS allow-list, Sanctum stateful SPA auth, CSRF, named
  rate limiters (`api`, `health`, `auth`), security headers, stack-disclosure removal,
  validated `X-Request-Id` correlation, secret redaction, production password policy.
- Horizon dashboard access fails closed outside local development.
- Tooling: Pest 4 on PHPUnit 12, PHPStan/Larastan level 8, Pint with strict rules,
  Scramble-generated OpenAPI at `/docs/api`.

**Frontend**

- React 19 + Vite + TypeScript SPA with strict type checking, feature-based structure
  and a lint rule preventing cross-feature deep imports.
- Design system tokens in OKLCH with light and dark themes, bridged into Tailwind v4,
  plus shadcn/ui-style primitives.
- Bilingual shell: Arabic RTL and English LTR with logical layout properties, Arabic
  typography and Latin numerals for figure reconciliation.
- Application shell with rail navigation, a focus-trapped mobile drawer, theme and
  language toggles, skip-to-content and visible focus management.
- Overview page reporting live system health; every unbuilt surface states which phase
  delivers it instead of showing fabricated data.
- Axios client with CSRF priming, locale propagation and a normalised `ApiError`;
  TanStack Query defaults that do not retry definitive 4xx answers.

**Project**

- GitHub Actions CI: backend (Pint, PHPStan, Pest against PostgreSQL and Redis),
  frontend (tsc, ESLint, Prettier, Vitest, build) and end-to-end (Playwright on
  Chromium and mobile Safari).
- `docker-compose.yml` for PostgreSQL and Redis; `Makefile` for setup, dev and verify.
- Documentation set under `docs/`, including six architecture decision records.

### Added — Phase 1 (in progress): identity, workspaces, clients

**Backend**

- Workspace → Client → Project schema with UUIDv7 keys, status check
  constraints and the indexes scoped queries depend on.
- Permission catalogue derived from one enum; six system roles with explicit,
  non-hierarchical permission sets; enforcement in the Application layer behind
  an AuthorizationGuard rather than in controllers or route middleware.
- Sanctum session authentication, workspace switching re-verified against live
  membership on every request, and configurable rate limits with secure
  defaults.
- Clients end to end: search, status filters, pagination, create, edit, domain
  status transitions and soft delete.
- Append-only audit trail covering sign-ins, failed attempts and every client
  mutation, with secrets redacted from recorded diffs.

**Frontend**

- Sign-in page, session gate, and a 401 handler that returns the person to sign
  in from anywhere rather than leaving a shell of failing requests.
- Account menu with workspace switching; switching discards every query cached
  for the previous workspace.
- Permission-aware navigation, and a clients surface with real loading, empty,
  filtered-empty, forbidden and error states, in English and Arabic.

**Projects**

- Full lifecycle with the assignment model: visibility restricted in SQL for
  roles without `projects.view_all`, assignment as the grant that reveals a
  project, and removal as the revocation.
- Domain status transitions with spend permission recorded on every change; a
  project cannot be moved to another client.
- SPA surface that distinguishes "you are not assigned to anything yet" from
  "this workspace has no projects", because they call for different actions.

**Administration**

- Workspace member administration — role changes, suspension, reinstatement and
  removal — with the last active owner protected against demotion, suspension
  and removal.
- Audit log viewer, workspace-scoped, filterable to security events, showing who
  acted and from where.

**Fixed**

- `SESSION_DOMAIN` was set to `localhost`, so no cookie was issued for
  `127.0.0.1` and sign-in silently failed to persist. Found by exercising the
  real browser flow.
- The actor context was not cleared when no active workspace could be resolved,
  so a member suspended or removed mid-session could continue acting on
  permissions they no longer held. The guard now denies an unbound context
  outright.

### Added — Phase 2: integrations foundation

- Seven provider adapters (Meta, Google Ads, TikTok, Snapchat, X, LinkedIn,
  ChatGPT Ads) behind one stable port, each declaring the capabilities it
  actually implements. An architecture test fails the build if an adapter
  claims something its platform cannot do.
- `ProviderConnection → ExternalAccount → ProjectIntegrationBinding`: the
  binding is the only thing that lets a project read an ad account. Connecting
  a platform and discovering its accounts expose nothing by themselves.
- Credentials encrypted with AES-256-GCM before storage, redacted when dumped,
  hidden from serialisation, and never returned by the API — which reports only
  which keys are held.
- `BLOCKED_EXTERNAL` made executable: real credentials produce a 503 naming
  what is missing and stating that the gap is the provider's approval rather
  than the operator's mistake; a platform with no published API produces a 501
  and declares no capabilities at all.
- Sandbox mode exercises the full workflow against clearly labelled `SANDBOX`
  fixtures — account structure only, never a fabricated metric.
- Integrations surface showing every platform's real status, what each can do,
  which credentials are still needed, and per-project access grants, in English
  and Arabic.

### Blocked

- Live ad-platform handshakes are `BLOCKED_EXTERNAL` pending credentials and provider
  approvals; see `docs/INTEGRATIONS.md`.
- Presentation identity awaits the Smart Code reference deck; the theme/token
  abstraction is built first.
