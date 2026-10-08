# Tracking health

The diagnosis: what is wrong with a client's measurement, and what to do about it.

The records it reasons over are in [MEASUREMENT.md](MEASUREMENT.md). This document is about
the judgement.

## The problem it exists for

An agency's worst measurement failure is not a broken integration. A broken integration is
loud — somebody sees an error, somebody fixes it. The failure that survives a month of weekly
reviews is this one:

> The GA4 property syncs every hour. The connection is healthy. The dashboard is green. The
> purchase tag was removed in a site deploy three weeks ago, so every revenue figure is zero,
> and zero looks exactly like a bad month.

Nothing else in this product can notice that. A chart has no way to distinguish "no sales"
from "no measurement of sales". So the diagnosis is a first-class feature with its own
severity scale, and the whole design is about keeping it believable.

## Derived on every read, never stored

`GetTrackingHealth` recomputes from the current records on each request. A stored diagnosis is
wrong the moment the next sync lands, and **a stale warning is worse than no warning**: it
teaches people to ignore the list, which costs more than the one issue it got wrong.

The window is 14 days — a fortnight of ordinary delivery — published as `window_days` so a
count of zero reads as "none in fourteen days" rather than a bare zero.

## Where the judgement lives

`TrackingHealth::diagnose()` is a pure static function over `SourceFacts`, `DefinitionFacts`
and a list of unmapped names. No queries, no clock of its own, no provider calls.

The application layer assembles facts; the domain decides. That split is what makes "a source
that syncs but reports nothing is critical" a rule somebody can read and test, rather than a
condition buried in a query — and it is why the rules have tests that state them in four lines
each.

## Every issue carries its evidence

```json
{
  "code": "no_recent_events",
  "severity": "critical",
  "summary": "Retail GA4 is reachable but has reported no events since 5 days ago.",
  "remedy": "Check that the tag is still deployed and firing on the live site.",
  "subject": "01932f…",
  "evidence": { "last_event_at": "2026-10-01T09:00:00+00:00" }
}
```

A tracking centre that says "purchase event missing" without saying *how it knows* is one
people stop believing after the first false alarm — and a false alarm is certain, because this
product can only ever see what a provider reports to it. The evidence is what lets somebody
disagree with the diagnosis, which is the only reason they will trust it when it is right.

Every issue also carries a `remedy`: what a person should do, in one sentence. An issue list
without remedies is a list of complaints.

## Severity

Three levels, because **a list where everything is urgent is a list nobody reads.**

`critical` is narrow on purpose: *something the client is paying to have measured is not being
measured at all.* Everything that merely might be wrong is a `warning`.

Issues sort worst-first, which is the order somebody works through them.

## The issues

| Code | Severity | Says |
| ---- | -------- | ---- |
| `no_source` | critical | Nothing is measuring this work yet. |
| `never_synced` | critical | A source has never been read successfully. |
| `no_recent_events` | critical | Reachable, syncing, reporting nothing. |
| `primary_conversion_unseen` | critical | The thing that defines success here has never happened as far as this product can tell. |
| `duplicate_mapping` | critical | Two definitions claim one raw name, so a total double-counts. |
| `stale_sync` | warning | The last successful read is over 36 hours old. |
| `unmapped_event` | warning | An event is arriving and counting towards nothing. |
| `purchase_without_value` | warning | An event that should carry money is arriving without it. |
| `intent_signal_as_conversion` | warning | A click or a form start is being counted as a completed action. |
| `no_primary_conversion` | warning | Nothing says what success means here. |

### The readiness panel is the fourth panel, and the only one that leaves the building

The Tracking Center's first three panels are derived from this product's own tables and always answer. The
fourth reads a tag container from Tag Manager on the workspace's credentials, which can be slow, rate-limited
or unauthorised — so it loads on its own, fails on its own, and says so. Folding it into the health query would
let a provider outage empty the page of facts it already had.

It is asked **per container**, because each answer is a live provider call and a page that fired one per
container on load would be slow in proportion to how much a client has set up.

Three things it publishes that nothing else can:

- **Published state, not saved state.** A change saved in Tag Manager and never published is not live, and
  "edited since publishing" is the commonest reason a tag that looks correct is not firing.
- **The measurement id cross-check.** The expected ids come from the client's *own* analytics sources rather
  than from anything typed on this screen, so a container publishing a different property than the one somebody
  registered shows up here and nowhere else.
- **Configuration, not events.** No freshness is shown. An absence of events is not a container's fault, and
  labelling every container stale would teach a reader to ignore the word.

A failed read shows the provider's own words. Reporting "no issues" after a call that never arrived is the
worse of the two possible wrong answers, and reading a container needs `tracking.manage` rather than
`tracking.view` — the permission is published rather than the panel hidden, because a reader who cannot see why
it is empty assumes the containers are not connected.

### Container readiness shares this vocabulary

Six more codes — `container_never_published`, `container_unpublished_changes`, `tag_paused`,
`tag_without_trigger`, `tag_trigger_missing`, `measurement_id_not_in_container` — come from
assessing a tag container, and use the same `TrackingIssue` shape, the same severities and the same
evidence rule. They are served by `GET /api/measurement/sources/{source}/readiness` rather than by
the health endpoint, because that one needs a live call to Tag Manager and this one must keep
answering when a provider does not. See [GOOGLE_TAG_MANAGER.md](GOOGLE_TAG_MANAGER.md).

### Why `primary_conversion_unseen` is critical

Either the campaign is failing or the measurement is. Both need somebody today, and the two
are indistinguishable from the dashboard — which is the entire point of saying it out loud.

### Why `duplicate_mapping` is critical rather than a tidiness note

A report showing both definitions shows the same sale twice. That is not an untidy
configuration, it is a wrong number presented as a right one, and it will be read by a client.

## What it refuses to do

These are the restraints that make the rest trustworthy, and each has a test named after it.

### It will not guess from a name

`purchase_complete` looks exactly like a purchase. It is reported as `unmapped_event`, not
counted. See [MEASUREMENT.md](MEASUREMENT.md#the-rule-that-governs-this-layer) — the mapping
is the record of a human decision and nothing substitutes for it.

### It will not call a tag manager silent

A container reports configuration, not events. Passing its event timestamp through would make
every GTM container a permanent critical warning. The caller passes `null`, and the engine
treats that as *not applicable* rather than *nothing arrived*.

### It will not call an absence a fault without qualification

A project with no sources is reported as **not set up yet**, with a remedy that says to connect
something — not as "broken tracking". A project with no conversions defined raises no
`no_primary_conversion`, because a project that has not got there yet is not misconfigured.

### It will not report one cause as two problems

An event that has never fired is not also faulted for arriving without a value. One cause, one
issue, or the list doubles in length for no new information.

### It will not refuse an intent signal

Counting WhatsApp clicks is a legitimate choice. The danger is somebody later reading the
number as leads, so the engine names it as a warning and the API publishes
`is_intent_signal_only` on the definition — and then lets the agency decide.

## Silence is the goal

Working tracking produces an empty issue list and `is_healthy: true`. A page that always has
something on it is a page nobody reads, and this feature is worth nothing the moment it stops
being read.

## API

`GET /api/measurement/health?client_id=&project_id=` — see [API.md](API.md#measurement).
Returns the sources with their freshness, the definitions with what was actually observed in
the window, and the issues. Requires `tracking.view`.

## The surface

`frontend/src/features/tracking` — the Tracking Center, and since Phase 17 a chart that answers
the page's only real question: is any of this working?

**Three states, three different facts**, and the chart keeps them apart:

- **A count.** The event has been seen that many times in the window.
- **Zero.** Asked for, and never seen. Drawn as a visible zero-length bar with its label, because
  this is the finding rather than the absence of one — somebody has declared what a sale looks like
  and nothing has ever matched it. Sorted to the top, since a never-seen conversion buried under
  the busiest events is a finding nobody acts on.
- **Null.** No window was asked for, so nothing was counted. Drawn as a **gap**, not a zero: "we
  did not look" and "we looked and found nothing" are opposite statements, and only one of them
  means tracking is broken.

Conversions and intent signals each get their own colour, their own legend entry, and no total.
Both are in the chart because somebody defined both; the one thing it must not do is let them add
up, because a tap is not a conversation and a form begun is not a form submitted.

## Tests

`backend/tests/Unit/Domain/TrackingHealthTest.php` is organised around the restraints above:
*what it refuses to call a fault*, *what it treats as urgent*, *what it will not infer*. The
feature tests in `backend/tests/Feature/Measurement/MeasurementTest.php` drive the same rules
through HTTP, including the case where a correctly configured project produces nothing at all.
