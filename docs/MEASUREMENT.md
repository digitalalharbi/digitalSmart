# Measurement

How digitalSmart decides what a number means.

Everything downstream of this document — store analytics, lead intelligence, journeys,
attribution, the AI analysis, the generated presentations — reads conversions. If a
conversion's meaning is guessed, every one of those is confidently wrong in a way no chart
reveals. So this layer holds exactly two records and one derived answer:

| Record                | Question it answers                            |
| --------------------- | ---------------------------------------------- |
| **Measurement source** | What is measuring this client's work?          |
| **Event definition**   | What counts as success here, and which raw event names mean it? |
| **Tracking health**    | Is any of it actually working? (derived, never stored) |

Tracking health has its own document: [TRACKING.md](TRACKING.md).

## The rule that governs this layer

**Nothing in this product infers meaning from an event name.**

One property's `contact` is a form submission. Another's is a phone tap. A third fires it on
the contact *page*. A raw event name counts towards a conversion only because somebody mapped
it, and that mapping is a record with an author, a timestamp and an audit entry.

This is why `purchase_complete` arriving unmapped is reported as unmapped rather than counted
as a purchase, and why the mapping table exists at all instead of a pattern match. The cost is
that somebody has to do the mapping. The alternative is revenue figures nobody can defend.

## Measurement sources

A source is one thing that reports events or orders: a GA4 property, a GTM container, an ad
account, a Salla store, a CRM, a website form, a column somebody types by hand.

### Kinds, and which may speak about money

`MeasurementSourceKind`: `analytics`, `tag_manager`, `ad_platform`, `store`, `crm`,
`website`, `manual`.

Only **`store`** and **`crm`** are revenue authorities (`isRevenueAuthority()`).

An analytics property reports what a *browser* said a purchase was worth — a measurement of a
page, taken before the payment failed, the order was cancelled or the item came back. An ad
platform reports what it *believes it caused*, which is a claim about its own effectiveness.
Neither is an invoice. The distinction is published on every source as
`is_revenue_authority`, and Phase 14's `RevenueSource` selection is built on it.

### A source's kind must match its provider's category

Enforced in `SaveMeasurementSource` against the Phase 6 provider registry:

| Kind          | Required provider category |
| ------------- | -------------------------- |
| `analytics`   | `analytics`                |
| `tag_manager` | `tag_management`           |
| `ad_platform` | `advertising`              |
| `store`       | `commerce`                 |
| `crm`, `website`, `manual` | none — and none may be given |

Registering a Salla store as an analytics property would put invoiced revenue in the column
that holds what a page said. The two disagree *by design*, and once they are in one column
nobody can say which is wrong. The registry already knows each provider's category, so the
check costs one comparison.

The last three kinds take no provider deliberately. A lead somebody took by phone is still a
lead; demanding a platform for it would make the honest cases the unrecordable ones.

### Scope: client-level by default

A source belongs to a client, and optionally to one project. Measurement is agreed with a
client before any project uses it, so a project-scoped read returns **the project's own
sources and the client-level ones it inherits**. Filtering strictly by project would hide the
GA4 property actually doing the measuring.

Visibility is applied in SQL, not in the interface: a source outside the actor's projects is
never in the result set.

### One record per property

A unique index on `(workspace_id, client_id, provider, external_id)`. Two records for one
property would double every count read through it. The repository catches the violation and
returns 422 rather than pre-checking — a check-then-insert can lose a race, an index cannot.

### Freshness is derived, never stored

`MeasurementFreshness::of($lastSyncedAt, $lastEventAt, $now)` — computed on every read,
because freshness is a statement about *now* and a column would describe the moment it was
written.

| State          | Meaning                                                     |
| -------------- | ----------------------------------------------------------- |
| `live`         | Synced recently, events arriving                            |
| `synced`       | Synced recently, no event timestamp applicable              |
| `stale`        | Last successful read over 36 hours ago                      |
| `silent`       | Reachable, but no event for over 48 hours                   |
| `never_synced` | Never read successfully                                     |

**`stale` and `silent` are different problems.** Stale means the figures are old. Silent means
the source is working perfectly and measuring nothing — reports built on it come out quietly
empty rather than visibly broken. Both timestamps are published alongside the state so a
reader can see which.

A tag manager reports configuration, not events, so `GetTrackingHealth` passes `null` for its
last event and it resolves to `synced`. Passing its timestamp through would make every
container a permanent critical warning, which is how a tracking page comes to mean nothing.

## The event vocabulary

`NormalizedEvent` — fourteen cases, deliberately small:

`page_view`, `session_start`, `view_item`, `add_to_cart`, `begin_checkout`, `purchase`,
`generate_lead`, `form_start`, `form_submit`, `whatsapp_click`, `phone_click`, `sign_up`,
`app_install`, `custom_event`.

Two questions each case answers:

- **`isConversionByNature()`** — a sensible default for a form, not a rule. The conversion
  registry decides per project; nobody should have to argue with an interface about whether a
  page view is a goal.
- **`isIntentSignalOnly()`** — true for `whatsapp_click`, `phone_click` and `form_start`.

That second one earns its place. **A click is not a conversation. A form start is not a
submission.** Reporting a cost per WhatsApp click as a cost per lead is the commonest way an
agency overstates its results, and it is usually nobody's fault — the number was simply
labelled once and then read a hundred times. The flag is published as
`is_intent_signal_only` on every definition, and counting one as a conversion raises a
tracking warning rather than a refusal: an agency may genuinely want to count clicks, as long
as nobody later reads the figure as leads.

## Event definitions — the conversion registry

A definition is a named thing a project counts, with the raw event names that mean it.

```
Qualified lead  (generate_lead, primary, no value expected)
  ← generate_lead     from GA4 property 123456
  ← form_submitted    from any source
```

A mapping's `measurement_source_id` may be null, which means **from any source** — a
different statement from "from a source we cannot name".

### Four refusals

| Refused                                | Why |
| -------------------------------------- | --- |
| A definition with no mappings          | Nothing would ever count towards it. A conversion permanently reading zero looks like failure rather than absence, and somebody will optimise against it. |
| The same raw name on two definitions in one scope | Both count every event, so a dashboard showing both shows the same sale twice. |
| The same raw name twice in one definition | Same double count, one record. |
| A primary that is not a conversion     | "The most important thing we are not counting as a conversion" is not a sentence anybody means. |

Checked across the whole scope, not just within the record being saved, because the double
count a reader sees does not care which record caused it. Each refusal is a 422 with
`code: invalid_event_definition` and a message naming the thing to change.

### Editing a definition replaces its mappings outright

The set of raw names *is* the whole truth about what a conversion counts. A name somebody
removed has to stop counting, or it keeps feeding a total nobody thinks it belongs to.

Both sides of every edit go into the audit trail. **"Leads halved in March" is sometimes a
campaign and sometimes a mapping somebody edited**, and the audit entry is the only record
that can tell them apart afterwards.

## Observed events

`observed_event_counts` is an aggregate: one source, one raw event name, one day, a count, and
an optional value in minor units with its currency. A row per event would be a firehose with
no question behind it; every question this product asks is per day.

A value with no currency is a number with no unit, and the first thing anyone would do with it
is add it to another one — so a database CHECK constraint refuses it.

The read model answers two questions, both over the trailing window:

- `countsByRawName()` — what each name was seen to do, and whether any observation carried a
  value.
- `unmappedRawNames()` — names seen that no mapping claims. This is the honest centrepiece:
  an event counting towards nothing is **invisible everywhere else in the product**. It is not
  a zero on a chart, it is simply absent, so this is the only place it can be noticed.

A mapping with a null source matches on name alone, which is what "from any source" means.

## What this layer does not do yet

- **No source populates `observed_event_counts` in production.** GA4 (Phase 8) and the
  commerce adapters (Phases 11–13) are what fill it. The schema, the read model, the
  diagnosis and the API are complete and tested; the sync jobs are their own phases.
- **No identity resolution.** Sessions, touchpoints and identity confidence are Phase 16.
- **No deduplication across sources.** A purchase in GA4 and the same purchase in Salla are
  two observations of one sale, and merging them is Phase 14's job, under an explicit
  `RevenueSource` the user selects. This layer keeps them apart, which is the precondition.

## Permissions

`tracking.view` and `tracking.manage`, granted to Admin, Project Manager and Media Buyer.

**Not to Creative or Client Viewer.** A creative produces work and does not need to see the
wiring. A client sees results: measurement setup is where a number's meaning is decided, and
that is the agency's to answer for.

## Audit

Five actions: `measurement_source_saved`, `measurement_source_deleted`, `conversion_defined`,
`conversion_changed`, `conversion_deleted`.

Deleting either record cascades — observed counts with their source, mappings with their
definition. Both deletions record what the thing used to be, because removing a conversion
changes every historical report that referenced it and the audit trail is the only place that
stays legible afterwards.

## Tests

- `backend/tests/Unit/Domain/TrackingHealthTest.php` — the diagnosis, the freshness states
  and the event vocabulary, as plain objects with no container.
- `backend/tests/Feature/Measurement/MeasurementTest.php` — the endpoints: permissions,
  visibility, every refusal, the audit entries and the health answers.
