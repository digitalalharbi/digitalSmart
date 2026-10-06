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

## Tests

`backend/tests/Unit/Domain/TrackingHealthTest.php` is organised around the restraints above:
*what it refuses to call a fault*, *what it treats as urgent*, *what it will not infer*. The
feature tests in `backend/tests/Feature/Measurement/MeasurementTest.php` drive the same rules
through HTTP, including the case where a correctly configured project produces nothing at all.
