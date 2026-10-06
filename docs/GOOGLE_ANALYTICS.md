# Google Analytics 4

How digitalSmart reads a GA4 property, and what it refuses to conclude from it.

The measurement records this fills are in [MEASUREMENT.md](MEASUREMENT.md); the diagnosis built
on them is in [TRACKING.md](TRACKING.md).

## Status

**BLOCKED_EXTERNAL — configuration and consent, not approval.**

Unlike the advertising platforms there is no programme to be admitted to. The live calls need:

1. a Google Cloud project with the **Analytics Admin API** and the **Analytics Data API**
   enabled;
2. an OAuth client whose redirect URI is allow-listed for this deployment;
3. a Google user who can read the property, granting the
   `https://www.googleapis.com/auth/analytics.readonly` scope.

Until a workspace has all three, every call fails loudly and names what is missing. There is no
code path that invents an event count for a live connection — the transport is the one thing
stubbed, and it is stubbed by refusal. With the literal credential `sandbox` the adapter reads
response-shaped fixtures instead, labelled `SANDBOX` on every row.

**Read-only, permanently.** Both APIs can write. This product will not: changing somebody's
measurement configuration from a reporting tool is not a feature, it is a way to break a setup
nobody can then explain. The registry records that as no write capability at all.

## The endpoints used

| Purpose | Call |
| ------- | ---- |
| Accounts and properties | `GET https://analyticsadmin.googleapis.com/v1beta/accountSummaries` |
| Streams, for recognising a property | `GET …/v1beta/properties/{id}/dataStreams` |
| What the owner marked as key | `GET …/v1beta/properties/{id}/keyEvents` |
| Which metrics this property has | `GET https://analyticsdata.googleapis.com/v1beta/properties/{id}/metadata` |
| The counts | `POST https://analyticsdata.googleapis.com/v1beta/properties/{id}:runReport` |

The report asked for is deliberately narrow:

```
dimensions: [{ name: "date" }, { name: "eventName" }]
metrics:    [{ name: "eventCount" }, … the property's currency metric, if it has one]
dateRanges: [{ startDate: "Y-m-d", endDate: "Y-m-d" }]
```

`keepEmptyRows` is omitted. A name with no events on a day is not a row worth storing, and
asking for them multiplies the response by the size of the event vocabulary for no new
information.

## Rows are read through their headers, never by position

A `runReport` row is a **positional** array of `{value}` objects. The only safe way to read one
is through `dimensionHeaders` and `metricHeaders`, which describe the columns.

This is the single most important implementation decision in the adapter. Reading
`dimensionValues[0]` because that is the order we asked for would work right up until somebody
added a dimension to the request — at which point every date in the database would become an
event name, silently, with the figures still looking plausible.

The sandbox fixtures list `eventName` **before** `date` on purpose, so a positional reader fails
the test suite immediately.

## The value metric is asked for, not assumed

Which metrics a property has is a property-level fact. Custom metrics differ between properties,
and a property with no revenue data **blocks** its currency metrics outright with
`NO_REVENUE_METRICS` — requesting one anyway turns every sync into a `400`.

So the adapter reads `getMetadata` and picks a metric GA4 itself declares as
`type: "TYPE_CURRENCY"` with no `blockedReasons`. It prefers `purchaseRevenue` when the property
has it and takes the first available currency metric otherwise, deterministically — a sync that
chose differently each run would produce a revenue series that moved for no reason anybody could
find.

**A property with no currency metric is read for counts only.** That is the honest outcome: a
hard-coded metric name we had not verified would be an invented metric, and a stored zero would
be worse.

## What GA4 says about the completeness of its own answer

GA4 is unusually honest about this, and the product has to be at least as honest. A response's
`metadata` can report four separate things, each meaning a figure read from it is not a total:

| Field | Meaning |
| ----- | ------- |
| `samplingMetadatas[]` | The report was **sampled**. `samplesReadCount / samplingSpaceSize` is the fraction of events actually analysed, one block per date range. |
| `subjectToThresholding` | Rows with few users were **withheld for privacy**, so a count is a floor. |
| `dataTruncationReasons[]` | Rows were **dropped**. |
| `dataLossFromOtherRow` | High-cardinality values were rolled into **`(other)`** and can no longer be counted separately. |

Every one of these produces a number that looks exact. A tool that reads the rows and discards
the flags reports 412 leads with the same confidence whether it saw all of them or 4% of them —
and the client is told 412 either way.

So the flags are mapped to `ReportQuality`, which decides the figure's source label:

- any of the four → **`PARTIAL`**, never `SYNCED`;
- sandbox → **`SANDBOX`**, which outranks everything;
- otherwise → `SYNCED`.

`ReportQuality::caveats()` turns them into sentences a person can act on, one per cause, because
a response can be sampled *and* thresholded and collapsing the two would hide whichever is
cheaper to fix. A sampling fraction below 1% is formatted to two decimals: "0%" for a 0.4%
sample reads as "no data" when it actually means the figure is a two-hundredfold extrapolation.

`emptyReason` is recorded and deliberately **not** treated as a quality problem. A property with
no traffic in the window is a fact about the client, not a defect in the read.

## `(other)` is never stored as an event name

GA4 returns a literal `(other)` row when cardinality is exceeded — in the event-name column and
sometimes in the date column. Both are skipped:

- as an **event name**, storing it would offer somebody a conversion called "(other)" to map, and
  whatever they mapped it to would be wrong;
- as a **date**, a row we cannot place on a day is dropped rather than parked on today, which
  would move somebody else's traffic into this morning.

Its presence is reported as a caveat instead, which is where it belongs.

## Discovery is unfiltered, and registration is a separate act

`GET /api/measurement/providers/google_analytics/properties` returns **every** property the
connection can read, including the ones belonging to clients the agency no longer works with.
That is precisely the list a person has to read carefully, not one the product should prune on
their behalf.

Each property carries its data streams, because the site and the `G-` measurement id are how
somebody recognises it as their client's. A list of numeric property ids is unusable for the one
decision that screen exists to support.

**Roll-up properties are flagged** (`is_aggregate`). A roll-up counts the same event its source
properties do, so measuring one client through both would double every figure — and nothing
later in the product could detect that it had happened.

## Key events are suggestions, never conversions

`GET /api/measurement/sources/{source}/suggestions` lists what the property's owner marked as a
key event. Nothing is created. A person confirms each one, and that confirmation is what writes
an event definition with an author and an audit entry.

GA4 marking an event key is **GA4's opinion** — often a default, sometimes left by whoever set
the property up years ago. What this agency counts for this client is a different question.

The suggestion proposes a normalised event only for names Google documents with a defined
meaning:

| Documented name | Proposed as |
| --------------- | ----------- |
| `purchase` | Purchase |
| `view_item` | Product view |
| `add_to_cart` | Add to cart |
| `begin_checkout` | Checkout started |
| `sign_up` | Sign-up |

Everything else returns `normalized_event: null` and the person chooses — **including
`generate_lead`**, which is not on either page the map was built from (the GA4 recommended events
reference and the ecommerce measurement guide). That is deliberate: a suggestion the product
cannot cite is a guess wearing a citation's clothes. The cost of asking is one dropdown; the cost
of getting one wrong is a conversion that counts the wrong thing for a year.

Each suggestion also carries `counted_once_per_session`. It changes what the number means —
counted per session, two purchases in one visit are one key event — and somebody reconciling that
against an order count would otherwise conclude the tracking is broken.

Already-mapped names are flagged, so confirming the same list twice cannot create a second
definition claiming one raw event.

## Syncing

`POST /api/measurement/sources/{source}/sync` reads the property and writes
`observed_event_counts`. Three decisions in it are worth more than the plumbing:

- **It re-reads a 14-day window rather than resuming from a high-water mark.** Analytics figures
  keep moving after the day closes — late hits, modelled conversions filling in, reprocessing —
  so a sync that only fetched "since last time" would freeze every day at its first, lowest
  value. The writer upserts, so re-reading corrects the past instead of fossilising it.
- **The weakest label in the run wins.** If any page came back sampled, every row that run wrote
  is `PARTIAL`. Labelling page by page would be more precise and less useful: a chart totalling a
  complete day and an estimated one is an estimate.
- **A failure part-way keeps what arrived.** Days already written are real and stay, the run is
  `partial`, and the message says what stopped it. What is never acceptable is a run reporting
  success while missing days.

The writer upserts on `(measurement_source_id, raw_event_name, date)`. The failure this prevents
would look exactly like growth: inserting instead would multiply a client's conversions by the
number of times the sync had run.

`last_synced_at` is written even on a failed run — "we tried and got nowhere" is a different fact
from "nobody has asked". `last_event_at` only ever moves **forward**, settled in SQL with
`greatest()`, so a backfill of an older range cannot age a healthy source into a critical
warning.

## Quota

A standard property, per the published quotas:

| Quota | Limit |
| ----- | ----- |
| Core tokens per property per day | 200,000 |
| Core tokens per property per hour | 40,000 |
| Core tokens per project per property per hour | 14,000 |
| Core concurrent requests per property | 10 |
| Core server errors per project per property per hour | 10 |

Exhaustion is `429 RESOURCE_EXHAUSTED` and should be retried with exponential backoff. `500
INTERNAL` and `503 UNAVAILABLE` likewise — mindful that ten server errors in an hour blocks the
project/property pair outright, so a tight retry loop is how a workspace locks itself out.
`403 PERMISSION_DENIED` means the connected user cannot read the property, and `401
UNAUTHENTICATED` means the token needs refreshing; neither should be retried as-is.

## Known limitation

Revenue is converted through the shared `Money` value object, which rounds at two decimal
places. That is correct for SAR, AED, USD and EUR and **wrong for zero-exponent currencies such
as JPY and three-exponent ones such as KWD**. It is a limitation of that value object shared with
every advertising mapper rather than something introduced here, and it is recorded rather than
worked around locally — a second rounding rule living in one mapper is how two parts of a product
come to disagree about an amount.

## Tests

- `backend/tests/Unit/Infrastructure/Measurement/GoogleAnalyticsMapperTest.php` — the mapping,
  against raw response shapes with no transport. Includes the header-order case that catches a
  positional reader.
- `backend/tests/Feature/Measurement/GoogleAnalyticsSyncTest.php` — discovery, suggestions and
  sync over HTTP, the sampling caveats, the upsert, and the two refusals that matter most: real
  credentials fail loudly, and a failed run still records the attempt.
