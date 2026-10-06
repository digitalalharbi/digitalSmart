# Zid

How digitalSmart reads a Zid store, and the four places Zid differs from Salla in ways that matter.

The normalised domain is in [ECOMMERCE.md](ECOMMERCE.md); the other store platform is in
[SALLA.md](SALLA.md).

Writing this integration is what tested whether the Phase 11 domain was secretly Salla-shaped. It was
not — but one place in the webhook path *had* quietly taken Salla's vocabulary as universal, and the
first Zid test caught it. That is recorded below rather than tidied away.

## Status

**BLOCKED_EXTERNAL.** Needs a Zid Partner app, a merchant who installs it, and the OAuth handshake.
With real credentials every call fails and names what is missing; with the literal credential `sandbox`
the adapter reads response-shaped fixtures. Nothing is ever written to Zid.

Scopes used: `orders.read`, `products.read`, `customers.read`.

## Endpoints

| Purpose | Call |
| ------- | ---- |
| Orders | `GET https://api.zid.sa/v1/managers/store/orders?payload_type=detailed&page=1&per_page=15` |
| Products | `GET …/products` |
| Customers | `GET …/customers` |

### Two tokens, not one

Zid authenticates with **both** an `Authorization` header carrying the app's token *and* an
`X-Manager-Token` header carrying the store's. Every other provider here takes a single bearer token.
Worth knowing before somebody wires the transport and spends an afternoon on 401s.

`Accept-Language` decides whether localised fields come back Arabic or English.

### `payload_type` must be `detailed`

It defaults to `simple`, **which omits products entirely**. A sync left on the default would read every
order and no line items, and the result would be a product report that is silently and permanently
empty — not an error, just nothing.

## Zid states both axes, so neither is inferred

This is the difference that matters most, and it is a pleasant one. Zid publishes a dedicated
`payment_status` alongside its `order_status` object:

| `payment_status` | Maps to |
| ---------------- | ------- |
| `paid` | `paid` |
| `pending` | `pending` |
| `refunded` | `refunded` |
| `voided` | `voided` |

Where Salla needed a documented boolean to recover the payment axis from a single workflow status, Zid
simply says it. The two-axis design in Phase 11 was a judgement call made before either provider
existed; Zid is the evidence that it was the right one.

### Order statuses

| `order_status.code` | Fulfilment |
| ------------------- | ---------- |
| `new` | unfulfilled |
| `preparing` | processing |
| `ready` | processing — ready to go out is not gone |
| `indelivery` | shipped |
| `delivered` | delivered |
| `cancelled` | cancelled |
| `reversed` | returned |
| `partially_reversed` | returned |
| `reverse_in_progress` | **processing** |

`reverse_in_progress` is deliberately not `returned`. `Returned` is a *settled* state, and a settled
state tells the sync to stop re-reading an order that is still moving. The exact code survives in
`raw_fulfilment_status`, so nothing is lost by refusing to round it up.

A credit note outranks a `paid` status, because Zid can record a reversal without changing it — and a
part refund has to stay distinguishable from a whole one.

### A merchant can invent its own statuses

Zid lets a store define custom order status codes, in Arabic or otherwise. So an unrecognised code is an
**ordinary occurrence**, not a defect, and guessing from its name is exactly what this product refuses
to do elsewhere.

An unknown code maps to `processing`. That is the least-wrong answer and, more importantly, the most
useful one: it claims only that the order is in progress, and it is **not settled**, so the sync keeps
re-reading the order until it reaches a state this product understands. *A state we cannot interpret
should keep us looking, not stop us.*

The payment axis is unaffected, because Zid stated it outright.

## Money and names

- **`order_total`, never `order_total_string`.** The second is formatted for display — currency symbol,
  separators, Arabic digits where the locale asks — and parsing it would work until a store switched
  language.
- Amounts arrive as numeric **strings** on orders and as numbers elsewhere. Both are accepted; anything
  else is null, meaning *not reported* rather than zero.
- **Localised fields come back as `{ar, en}` maps** when `Accept-Language` is not pinned. Arabic is
  preferred: these are Saudi stores, and the Arabic name is the one on the merchant's screen and in
  their conversations.
- A variant with no name of its own is named from its attributes, e.g. `كينج`.
- **`is_infinite` means stock is not tracked**, so it is stored as null rather than as a number.
  Reporting unlimited stock as zero would pause a campaign on a product that is always available.

## Marketplace orders arrive with the customer masked

When `is_marketplace_order` is true, Zid masks the customer's name, e-mail and phone — what arrives is a
placeholder, not the customer.

This is handled **structurally rather than by a flag**: nothing in this product writes a customer from
an order payload. Customers come only from the customers endpoint, which returns real records. So a
masked placeholder has no route into the customers table at all.

The order is still stored — it is a real sale with real revenue — and its customer id is read and simply
links to nothing, because a marketplace buyer is not a store customer. That is the truth, and it is
better than a fabricated e-mail in a client's customer list which nobody could tell from a real one
afterwards, and which would poison every later attempt to match an order to a lead.

## Webhooks: Basic Auth, not a signature

`POST /api/webhooks/stores/zid`.

**Zid does not sign its payloads.** When a webhook subscription is created, the partner supplies a
`username` and `password`, and Zid sends them back as
`Authorization: Basic base64(username:password)` on every delivery.

That is a weaker property than Salla's HMAC, and the difference changes what this endpoint can promise:

- **It proves the caller knows the password. It does not prove the body is intact.** An HMAC covers the
  bytes; Basic Auth covers nothing but itself. The body is trusted only as far as the transport is,
  which makes HTTPS non-optional here in a way it merely ought to be elsewhere.
- **The secret travels on every request.** Base64 is an encoding, not encryption. The credential is
  effectively a bearer token: rotate it if a log ever captured it, and never reuse it as anything else.
- **No timestamp or nonce, so no replay protection.** A captured delivery can be replayed indefinitely.
  What makes that harmless is the same thing that makes a missing event id harmless: the work is an
  upsert keyed on the order's own id, so a replay rewrites the same row.

The comparison is still constant time. Basic Auth being weaker is no reason to leak the password through
timing on top of it.

Stored in the connection's `webhook_secret` credential, which for Zid holds `username:password`. An
unconfigured credential, a missing header and a non-Basic scheme all verify **nothing** — a deployment
that forgot to configure it rejects deliveries, which somebody notices, rather than accepting whatever
arrives, which nobody does.

**A Salla signature on the Zid endpoint is refused**, and there is a test for it. Each platform's rules
are kept apart so the weaker mechanism's assumptions cannot leak into the stronger one's code, and so
accepting a mechanism Zid does not use is not possible.

### The bug Zid found

The webhook use case had a hard-coded list of actionable topics — `order.created`, `order.updated`,
`order.status.updated` — written while implementing Salla. **Zid's events are named differently**:
`order.create`, `order.status.update`, `order.payment_status.update`.

Every Zid order webhook would therefore have been recorded, marked `ignored`, and silently dropped. The
endpoint would have looked healthy, the journal would have filled with `ignored` rows that are a normal
sight, and a client's orders would only have appeared when the reconciliation sync next ran.

The fix is to match the `order.` prefix instead of a list of names. Over-matching is safe, which is what
makes the simpler rule the right one: the queued worker refuses any payload carrying no order and marks
it ignored, so a future `order.*` topic this product does not understand costs one journal row.
Under-matching costs a client's orders.

This is exactly the failure the roadmap warned about — forcing the first platform's semantics onto the
second — and it was caught by the first Zid test that expected work to happen rather than by a review.

## Reconciliation sync

Identical in shape to Salla's: a fortnight re-read, customers then products then orders, windowed on
Zid's `updated_at` so a month-old order reversed this morning comes back. Orders are labelled
`RevenueSource::Zid`, never a generic total, so a Zid figure and a GA4 figure cannot be added together by
accident.

The webhook path and the sync share one mapper, with a test asserting they agree.

## What is not done

- **The live transport**, on a Partner app install and the OAuth handshake.
- **No scheduled syncing.** Queue-ready and driven directly today.
- **No webhook subscription management.** Zid subscriptions are created in the Partner dashboard or
  through an API this product does not call, because creating one is a write.
- **`total_order_count_per_status` is ignored.** Zid returns a useful summary on the orders list; this
  product derives its own counts from the orders it stored, so a figure a reader can reconcile is never
  replaced by one they cannot.

## Tests

- `backend/tests/Unit/Infrastructure/Commerce/ZidMapperTest.php` — both axes, the custom status, the
  formatted-total trap, localised names, unlimited stock, and the masked marketplace customer.
- `backend/tests/Feature/Commerce/ZidTest.php` — the sync end to end, and every Basic Auth refusal
  including a Salla signature offered to a Zid endpoint.
