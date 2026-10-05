# Provider sandbox fixtures

These files let the whole pipeline — adapter, sync job, normalisation, storage,
analytics, UI — run end to end before any provider has issued us credentials.

## What they are

Each file is shaped like a **real response from that provider's API**, using the
provider's own field names, value types and enum strings as published in its
official documentation. Meta's budgets are numeric strings in minor units,
`spend` is a decimal string, `actions` is an array of `{action_type, value}`
objects, `effective_status` carries `WITH_ISSUES` and `DISAPPROVED` — because
that is what the Graph API returns. Code that reads these fixtures correctly is
code that reads the live API correctly, which is the only reason to have them.

## What they are not

They are not real performance data, and nothing in the product may present them
as such. Every row loaded from here is stored with `source = 'sandbox'`
(`App\Domain\Advertising\MetricSource::Sandbox`), the label travels with the
number through every aggregate, and the interface marks it. A connection only
reads fixtures when its credentials are literally the string `sandbox`.

## Provenance of the numbers

The structural records (campaigns, ad sets, ads, creatives) were written by hand
against the documented node schemas.

The insight rows were generated once, deterministically, from a fixed seed, with
per-ad cost and conversion-rate characters chosen so the analytics layer has
something real to discriminate between: a cheap high-volume video, an efficient
narrow retargeting set, a rejected ad with almost no delivery, a paused campaign
that reports **nothing at all** rather than zero. Weekends lift volume. Parent
levels are not the sum of their children — reach is discounted, as Meta
deduplicates it — so a test cannot pass by summing the wrong level.

## The one departure from a verbatim response

`date_start` / `date_stop` are absent from the stored rows, replaced by
`_day_offset` (days before today). The loader resolves them at read time so the
sandbox always returns a recent window instead of ageing into irrelevance. Keys
beginning with `_` are loader directives and are stripped before any mapper sees
the row.

## Adding a provider

Create `<provider>/` and mirror its real API shapes. Do not invent an entity, a
field or a metric the provider does not document — an empty fixture set is
honest, and a guess becomes a lie the moment the UI renders it.
