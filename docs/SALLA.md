# Salla

How digitalSmart reads a Salla store, and the two places where Salla's shape and this product's
shape genuinely disagree.

The normalised domain this maps into is in [ECOMMERCE.md](ECOMMERCE.md).

## Status

**BLOCKED_EXTERNAL.** Needs a Salla Partner app, a merchant who installs it, and the OAuth handshake:

- authorisation `https://accounts.salla.sa/oauth2/auth`
- token and refresh `https://accounts.salla.sa/oauth2/token`
- merchant details `https://accounts.salla.sa/oauth2/user/info`
- `offline_access` must be requested or **no refresh token is issued at all**

With real credentials every call fails and names what is missing. With the literal credential
`sandbox` the adapter reads response-shaped fixtures. There is no path that invents an order — which
for a commerce integration means no path that invents revenue.

Scopes used: `orders.read`, `products.read`, `customers.read`. Nothing is written, ever.

## Endpoints

| Purpose | Call |
| ------- | ---- |
| Orders | `GET https://api.salla.dev/admin/v2/orders` |
| One order, with its amount breakdown and shipments | `GET …/v2/orders/{id}` |
| Products | `GET …/v2/products` |
| Customers | `GET …/v2/customers` |

### Pages must be requested in order

Salla documents that **a page requested out of sequence returns an empty response**, and that
sequential page requests are cached for fifteen minutes.

This is the kind of constraint that produces a silent failure rather than an error: a sync that
parallelised its pages, or resumed from page four after a crash, would read nothing and report
success. So the cursor carries a page number, pages are walked `1, 2, 3 …`, and the fixture loader
reproduces the behaviour exactly.

`per_page=30` is the documented maximum and what the adapter uses.

## Salla has one status axis; this product has two

Salla's twelve documented slugs mix the two questions:

| Slug | Payment | Fulfilment |
| ---- | ------- | ---------- |
| `payment_pending` | pending — *"payment … has not been received yet"* | unfulfilled |
| `waiting_for_payment_confirmation` | pending — *"payment has been made … awaiting confirmation"* | unfulfilled |
| `payment_failed` | unpaid — *"payment attempt was unsuccessful"* | unfulfilled |
| `under_review` | *not stated* | processing |
| `in_progress` | *not stated* | processing |
| `shipped` | *not stated* | shipped |
| `delivering` | *not stated* | shipped |
| `delivered` | *not stated* | delivered |
| `completed` | paid — *"successfully paid for and delivered"* | delivered |
| `canceled` | voided — *"will not be processed further"* | cancelled |
| `restored` / `restoring` | *not stated* | returned |

Five slugs state the payment outright, and the mapper quotes the documentation in each branch. The
other seven describe a parcel and say nothing about money.

### The seven that say nothing use a documented boolean, not a guess

**This matters enormously in this market.** Cash on delivery is normal in Saudi Arabia, so `shipped`
means "the money has not arrived" for one order and "the money arrived last week" for another. Mapping
the slug to `paid` would overstate a COD store's revenue by everything in transit; mapping it to
`pending` would understate a prepaid store's to nothing.

Salla publishes **`is_pending_payment`**, a boolean that exists to answer exactly this. The mapper
reads it:

- `true` → `pending`
- `false` → `paid`
- absent → `pending`

The fallback understates deliberately. An agency that finds its revenue figure low goes looking; one
that finds it high has already sent the report.

### A refund outranks the workflow status

Salla will show an order as `delivered` while the money has gone back. Any refund on the order sets
`refunded` or `partially_refunded` regardless of the slug, and the fulfilment axis still says the goods
arrived — which is the whole reason the two axes exist.

### Salla's slug is kept verbatim

In `raw_payment_status` and `raw_fulfilment_status`. The first time a client's figures differ from
their Salla dashboard, somebody will ask which status this product read, and the answer has to be on
the record rather than reconstructed from the mapper.

## The order list carries no amount breakdown

`GET /orders` returns `total: {amount, currency}` and nothing else. The subtotal, shipping, tax and
discounts live only on `GET /orders/{id}`, one request per order.

So a mapped order from the list has a **total and nulls**, and null means *not reported* rather than
zero. Phase 11 originally defaulted those columns to zero; contact with the real API showed that to be
wrong in exactly the way this product refuses elsewhere — a stored zero reads as "nothing was charged
for shipping", and a subtotal report built on it is confidently incorrect rather than visibly absent.
The columns are now nullable.

## Money and time

- Salla sends amounts as `{amount, currency}`, bare numbers and numeric strings depending on the
  endpoint. All three are accepted; anything else is null.
- **Discounts are summed into one positive amount taken off.** Salla reports `amounts.discounts` as a
  list and has been seen to express a discount as a negative; mixing those conventions across
  providers is how a discount comes to increase revenue.
- **Order timestamps carry their time zone.** Salla sends `{date, timezone_type, timezone}`, and a
  Riyadh store read as UTC would move three hours of every evening's sales onto the previous day —
  which quietly shifts revenue between months at a month boundary.
- **A payment time is filled only when Salla reports one.** Guessing the order date would put a
  payment before the money moved.
- **A customer's mobile is read as a string with its country code.** Salla sends it as an integer,
  which drops a leading zero — and a phone number that has been through an integer may no longer dial.

## Attribution

Salla exposes the coupon on the order. UTM parameters and click identifiers reach an order only when
the merchant's theme passes them through, so they are read where they appear and their absence is
reported as `none` rather than filled in. A coupon alone is weak evidence and is scored as such — see
[ECOMMERCE.md](ECOMMERCE.md#attribution-never-falsely-assign-an-order-to-a-campaign).

## Webhooks

`POST /api/webhooks/stores/salla` — unauthenticated by necessity, verified by signature.

Salla sends three headers and uses one of two documented strategies:

| Header | Meaning |
| ------ | ------- |
| `X-Salla-Security-Strategy` | `Signature` or `Token` |
| `X-Salla-Signature` | the HMAC, or the token itself |

**Signature strategy:** `HMAC-SHA256(secret, rawRequestBody)`, hex. Three rules, each of which has
been a real vulnerability in somebody's integration:

1. **Hash the raw body.** `json_decode` then `json_encode` changes key order, unicode escaping and
   float formatting. An implementation that re-encodes appears to work until a payload carries an
   Arabic product name — and then rejects every webhook from every Arabic store, which here is most of
   them. There is a test with an Arabic product name for exactly this.
2. **Compare in constant time.** `===` on a hash leaks it through timing, one byte at a time, to
   anybody who can send requests and measure.
3. **An unconfigured secret verifies nothing.** No secret means `false`, so a deployment that forgot
   one rejects webhooks instead of accepting forgeries. The failure mode is a gap somebody notices,
   not a silent door. An unrecognised strategy is likewise `false`.

The secret is read from the connection's `webhook_secret` credential.

### Two workspaces can register the same merchant

Stores are unique per `(workspace, provider, external_id)`, so two agencies may both manage one shop —
and each will have installed its own app with its own secret.

**The signature decides whose delivery it is.** Every candidate store is found, the signature is
checked against each one's own secret, and the one that verifies gets the order. Routing by first match
would put one agency's client's orders into the other's workspace — a cross-tenant leak nothing
downstream could detect. There is a test named after it.

### Everything is answered 2xx, including refusals

A non-2xx makes Salla redeliver, and redelivering a payload that will never verify achieves nothing but
filling the journal. Outcomes:

| Outcome | Meaning |
| ------- | ------- |
| `queued` | verified, actionable, work dispatched |
| `ignored` | verified, but a topic this product does not act on — a normal thing to receive |
| `rejected` | the signature did not verify. Recorded, never processed |
| `duplicate` | already delivered |
| `unknown_store` | no store registered for that merchant. The one case that leaves no trace |

Actionable topics: `order.created`, `order.updated`, `order.status.updated`, `order.payment.updated`,
`order.refunded`, `order.cancelled`, `order.shipment.created`, `order.shipment.updated`,
`order.deleted`.

### Salla sends no event id, and it does not matter

Its envelope is `{event, merchant, created_at, data}`. The journal's unique index therefore cannot
deduplicate Salla deliveries — and does not need to, because **the work is an upsert keyed on the
order's own id.** Idempotency by data is stronger than idempotency by an identifier the provider does
not send, and a redelivered `order.payment.updated` writes the same row.

This product will not synthesise an id from a payload hash: two genuinely distinct events can hash
identically, and silently dropping the second would lose a real sale.

### The work happens on a queue

The endpoint verifies, records and dispatches. Nothing touches an order inside the request, because the
provider is waiting on the response — and a handler that read an order from the API inline would turn
one slow call into a timeout, a timeout into a redelivery, and a redelivery into a queue of duplicates.

The worker reads the payload **back from the journal row**, which is the only payload that has been
through the signature check, and refuses a row that was not verified even though the endpoint never
queues one. A job can be enqueued by anything with queue access, and this is the last gate before a
payload becomes a client's revenue figure.

Rate limited at 600 a minute, keyed by **merchant** rather than IP: providers deliver from shared
infrastructure, so an IP key would let one busy shop throttle every other shop on the platform. A 429
is the right answer — Salla redelivers and nothing is lost.

## Reconciliation sync

`SyncStore` re-reads a fortnight on every run. Webhooks are faster and they are **not sufficient**: one
dropped delivery, one deploy during a busy hour, one Salla incident, and a client's revenue is quietly
short for that window with nothing to indicate it.

The order is customers, then products, then orders. An order line names a product and an order names a
customer, so reading orders first would store nulls for both — which the next run would fix, leaving a
window in which a client's product report was missing its best-selling item for no visible reason.

**The two paths share one mapper.** A second mapper for webhooks is how they would come to disagree
about a status, and the disagreement would show up as a client's revenue changing whenever a nightly
sync overwrote what a webhook wrote an hour earlier. There is a test asserting they agree.

A webhook writes `MetricSource::Live`; the sync writes `Synced`, or `Sandbox` for fixtures. A reader
can see which way a figure arrived.

## What is not done

- **The live transport**, on the OAuth gate above.
- **No scheduled syncing.** `SyncStore` is queue-ready and driven directly today; scheduling belongs
  with the phase that owns job orchestration.
- **Order details are not fetched per order.** The list's total is used and the breakdown left null. One
  request per order is a quota decision worth making deliberately rather than by default — Salla allows
  a store 30 records a request, so a month of a busy shop would cost hundreds of extra calls.
- **No webhook subscription management.** Salla subscriptions are created in the Partner dashboard or
  through an API this product does not call, because creating them is a write.
- **Shipment and refund topics write only through the order they belong to.** Salla's shipment payloads
  sometimes carry no order, and those are ignored for the reconciliation sync to pick up.

## Tests

- `backend/tests/Unit/Infrastructure/Commerce/SallaMapperTest.php` — every documented status slug, the
  COD-versus-prepaid distinction, money, time zones, attribution and lines.
- `backend/tests/Feature/Commerce/SallaWebhookTest.php` — the signature, the forgery, the missing
  secret, the unknown strategy, the Arabic body, the cross-tenant routing, and the redelivery.
- `backend/tests/Feature/Commerce/SallaSyncTest.php` — paging, linking, what counts as revenue, the
  derived customer total, idempotency across runs, and agreement with the webhook path.
