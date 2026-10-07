# Attribution

Attribution is where this product makes its most quotable claim — "this campaign earned the money" —
and where that claim is easiest to make indefensibly. So this document is mostly about what the
attribution layer refuses to do.

## One of the models is not a model

| Model | What it claims | Modelled? |
| --- | --- | --- |
| **As recorded on the order** (`last_recorded`) | The campaign written onto the order at checkout | **No** — a record |
| **First recorded conversion** (`first_recorded`) | The campaign on the earliest conversion recorded for the same person | Yes |
| **Paid click, as recorded** (`paid_last_click`) | As recorded, counting only conversions whose link is a paid one | Yes |
| Linear | Equal credit to every touch | Yes — **cannot be computed** |
| Position based | Most credit to the first and last touch | Yes — **cannot be computed** |
| Time decay | Credit decaying with time to conversion | Yes — **cannot be computed** |

`AttributionModel::isModelled()` is false for exactly one member, and every surface showing one of
these figures is obliged to act on it. `last_recorded` reports what the order arrived carrying, which
in practice makes it a last-click figure — but last-click *as recorded*, with no assumption about what
came before. Everything else is an opinion, and opinions get labelled.

## Three models are declared and refused

The three that need the **sequence of marketing touches** before a conversion cannot be computed,
because this product does not hold that sequence. Google Analytics' Data API returns aggregated
reports rather than per-user event streams, with no user-level identifier; the documented route to
event-level data is a BigQuery export this product does not read. The touches are **absent rather than
nonexistent** — the same finding Phase 16 recorded about journeys.

Asking for one returns a 422 naming what is missing and what would unblock it:

- a **GA4 → BigQuery export**, which needs a Cloud project with billing and costs money per query, so
  it is a decision for whoever pays; or
- **first-party tracking** served by this product, which is larger and brings consent obligations.

They are declared rather than omitted on purpose. An option that is absent gets asked for again every
quarter; one that is present and explains itself does not. And computing something shaped like
time-decay from a single recorded touch would produce a number named after a model it is not, which
nobody downstream could detect.

## Coverage travels with every figure

`AttributionCoverage` is a required constructor argument of the response, built from the same scan as
the rows. Without it, "Meta earned 180,000" reads as a share of the month; if 40% of orders carried any
marketing information at all, it is a share of a share, and the other 60% is **not** distributed among
the campaigns — it is unknown.

Published: conversions and attributed conversions, revenue and attributed revenue, the **unattributed
revenue as its own figure** (left to subtraction it goes uncomputed), both coverage rates, the share of
attributed conversions that name a campaign rather than a channel, the **weakest** evidence in the set,
and a revenue-weighted confidence.

The reportable threshold is **70%**, not 90%. Direct traffic, returning customers, app-to-web journeys
and messaging apps that strip parameters all land in the unattributed bucket legitimately, and a
threshold nobody can reach is one everybody learns to ignore. Below it the interface leads with the
coverage rather than the rows.

Every rate is null rather than zero when its denominator is empty: `0%` coverage says tagging is
broken, "no orders in this period" says nothing happened, and they look identical on a card.

## Spend and revenue are two authorities on one row

Putting ad spend next to attributed revenue is the most useful thing this layer does and is a join
across two sources of truth. `CampaignLink` records how firmly each row is tied to an ad campaign, and
**only an exact campaign-name match carries spend**:

| Link | Carries spend | Why |
| --- | --- | --- |
| `exact_name` | yes | The order's campaign parameter equals an ad campaign's name, compared case-insensitively with surrounding space removed — and nothing looser |
| `click_not_resolved` | no | A platform click identifier. Resolving one to a campaign needs the platform's click-level API, which this product does not read |
| `no_matching_campaign` | no | No campaign of that name exists in the connected accounts — renamed, deleted, or on an account nobody connected |
| `channel_only` | no | A source or medium but no campaign. Dividing the revenue among the channel's campaigns would be inventing the split |

`autumn-range` and `autumn-range-v2` are two campaigns. There is no prefix matching and no fuzzy
similarity, because a merged row attaches a budget decision to the wrong campaign and the reader never
finds out.

**ROAS is null wherever it would be meaningless**: a row whose link does not join spend, a row with
revenue and no spend (where an infinite ROAS would be reported as the month's triumph), and a row whose
revenue and spend are in different currencies — riyals over dirhams looks entirely ordinary and means
nothing.

**Spend the orders never mentioned is reported**, as `unmatched_spend_minor`. It is half the question
an agency is asked and the half a report listing only winning campaigns leaves out: money spent on
campaigns no order ever named is a tagging problem with a size, not a gap.

## The comparison is the honest surface

`GET /api/attribution/comparison` runs every available model over one period and reports **where they
disagree**. A page offering a model selector teaches a reader that one option is the right one; this
teaches the truth, which is that the models disagree by construction and the size of the disagreement
on this client's own campaigns is the only way to say how much the choice matters.

A disagreement is **material above 20%** of the larger figure. Below that the choice of model is a
detail; above it the choice is the story, and a report naming one campaign as the month's winner may be
naming the wrong one.

A campaign missing from one model's rows means one of two things, and the difference decides whether a
disagreement can be reported at all:

- the model's breakdown was **not** truncated, so it saw every campaign and credited this one nothing —
  a **zero**, and "half a million under one model, nothing under the other" is the most extreme
  disagreement there is;
- the breakdown **was** truncated by the row limit, so the campaign may only have fallen outside the top
  rows — **unknown**, and recording it as zero would manufacture a disagreement out of a pagination
  artefact.

## The lookback window is a modelling choice

`first_recorded` looks back a stated number of days for an earlier conversion by the same person, and
the window is published with the answer. A 7-day window and a 90-day window credit different campaigns
for the same orders, and neither is more correct.

A first-time customer with no earlier conversion is credited with what their **own** order carried,
rather than being dropped: a model that reported nothing for them would understate every campaign that
wins new business, which is most of what an agency is paid for.

Matching is on normalised phone numbers and e-mail addresses only, through the keys Phase 16 added. No
names: two people called Mohammed Al-Harbi are two people, and a wrong merge moves one customer's
revenue onto another customer's campaign.

## Why every model runs over one scan

`EloquentAttributionReadModel` reads the period's orders once and groups them in PHP, for every model.
Aggregating each model in its own SQL statement would be faster and would mean a disagreement between
two models could come from the two queries differing rather than from the models differing — which is
precisely what the comparison surface is supposed to measure.

The cost is a row-level read bounded by the requested range. That is fine at agency scale, and the
first thing to replace when it stops being: a materialised attribution table rebuilt as orders sync
would keep the same grouping code and feed it from a smaller set.

## Permissions

`analytics.view`, the same permission the rest of the reporting surface uses. Attribution is a view of
money already readable through analytics and commerce; a separate permission would let somebody be
granted the modelled view and denied the recorded one, which is backwards.

## The sandbox demonstrates the failure, not the success

The demo workspace's synced orders produce: one campaign matched to real sandbox spend with a ROAS of
0.21, one coupon-only order naming a campaign nobody connected, and most of the sandbox's spend
unmatched. That is roughly what a real account looks like before anybody fixes its tagging, and it
exercises every branch of the join rather than the flattering one.

## API

- `GET /api/attribution/models` — the catalogue, including what cannot be computed and why.
- `GET /api/attribution` — one model. **`model` and `revenue_source` are both required with no
  defaults**: each is a decision that changes the answer, and a default would make it on somebody's
  behalf.
- `GET /api/attribution/comparison` — every available model over the same period, and the disagreements.

See [API.md](API.md#attribution).

## Tests

- `backend/tests/Unit/Domain/AttributionTest.php` — the refusals: a model that will not be computed, a
  rate with no denominator, a ROAS that will not be divided, a spread that will not be hidden.
- `backend/tests/Feature/Attribution/AttributionTest.php` — the endpoints, including loose name
  matching refused, two currencies refused, the paid-only filter, the lookback window, the first-recorded
  disagreement, and the truncation rule in both directions.
