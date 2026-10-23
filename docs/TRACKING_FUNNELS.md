# Tracking & Funnels

First-party tracking from an advertisement to a sale, owned by digitalSmart. Ad platforms, GA4 and GTM are *destinations
and evidence*, never the source of truth. Delivered in six bounded pull requests; this file states what each one holds
and is updated by each.

| PR | Scope | Status |
|---|---|---|
| A | Tracking links, redirect, visitor/session/touchpoint, event store, UTM and click-ID capture, bot marking | `IMPLEMENTED_NOT_VERIFIED` until merged and CI-green |
| B | Form builder, public forms, submissions, submission → lead | `IMPLEMENTED_NOT_VERIFIED` until merged and CI-green |
| C | Funnel builder and analytics, journey timeline | not started |
| D | Tracking health, debug mode, GTM / GA4 destination | `IMPLEMENTED_NOT_VERIFIED` until merged and CI-green |
| E | Provider conversion mapping, async dispatch, browser/server dedup, consent policy, retention | `IMPLEMENTED_NOT_VERIFIED` until merged and CI-green |
| F | Report sections, QR, mobile/browser hardening | not started |

Nothing here is `LIVE_VERIFIED` for any provider. No provider receives anything from this module yet.

## PR A — what exists

```
ad → /api/r/{token} → link_click + session_start → 302 to the destination (+ utm + documented click ids + ds_tp)
destination page → POST /api/public/track {touchpoint, event, event_id} → landing_view, form_view, form_start, …
```

- **Tables:** `tracking_links`, `tracking_link_templates`, `tracking_sessions`, `tracking_events`. Every row carries a
  workspace and a project; a project belongs to a client, so tracking is isolated like all other work. Public identifiers
  (`public_token` 10 chars, visitor/session/touchpoint 20, event ids) are random base-62 and mean nothing; UUID primary
  keys never leave the product.
- **Permissions:** `tracking.view` reads, `tracking.manage` builds. A client viewer holds neither.
- **Short address:** `{TRACKING_PUBLIC_BASE_URL}/r/{token}`; by default `APP_URL/api/r/{token}`. A branded domain only has
  to route `/r/*` to `/api/r/*` of this application.
- **Templates:** `{{platform}} {{client}} {{project}} {{campaign}} {{ad_group}} {{ad}} {{creative}}`. Unknown variables are
  left as typed so a typo shows; a variable with no value yields no parameter, never the word "null".

## Security

- The destination is validated **when the link is saved** (http/https, a real public host, no credentials, not a private,
  loopback, link-local or single-label host, not this installation) and is read from the row — **never from the request**.
  `?url=` / `?redirect=` / `?next=` are ignored. This is what stops a link becoming an open redirect.
- A missing, paused or expired link answers one identical bare 404 and records nothing. A beacon always answers the same
  202 whatever it was, so neither endpoint can be used to probe for valid tokens or tenants.
- Public endpoints are rate limited per address (`RATE_LIMIT_TRACKING`, default 240/min), bodies over 4 KB are dropped,
  and the beacon reads a `text/plain` body so a destination page can use `sendBeacon` without a cross-origin preflight.
- A browser may report only behaviour (`landing_view`, `form_view`, `form_start`, `form_step_completed`,
  `form_validation_error`, `whatsapp_click`). `link_click`, `form_submit`, `lead_*`, `sale_completed` are written by this
  product and are refused from a browser. Project, link and visitor come from the stored touchpoint, never the request.
- **No form values are kept.** Event metadata keeps only `step`, `field_key`, `error_code`, `form_token`, each ≤ 80 chars.
  The landing path is stored without its query string or fragment.
- No fingerprinting: device and browser are coarse classes (`mobile|tablet|desktop`, `chrome|safari|…`), never a model or
  version. The visitor cookie `ds_v` is first-party, HttpOnly, SameSite=Lax, 180 days, on the redirecting host only.

## Click identifiers

Kept only when the parameter is one a provider documents: `gclid`, `gbraid`, `wbraid` (Google), `fbclid` (Meta), `ttclid`
(TikTok), `ScCid` (Snapchat), `li_fat_id` (LinkedIn), `twclid` (X). OpenAI Ads documents none, so none is captured. Values
must look like an identifier (≤ 512 chars of URL-safe characters). A click identifier is evidence an ad was clicked, never
that anything was bought. They are forwarded to the destination so the platforms' own tags keep working.

## Bot / duplicate / test classification

`TrafficClass` = `valid | suspected_bot | duplicate | test`. The heuristic (`BotHeuristic`) looks at the user-agent
string only: empty or very short, or naming a known crawler, link-preview fetcher, headless browser or HTTP library. It
does not fingerprint, look at addresses, or detect bots that dress as browsers. **It is a guess, documented as one.**
Events are marked, never deleted; counts exclude everything but `valid`, and the number excluded is shown beside them.
`duplicate` is the dedup key `(project, event_id)`: a repeat delivery is one event.

## Honesty about what is known

- **Clicks** are recorded by this product, so zero clicks is a true zero.
- **Landing views** are reported by the destination page. A link with clicks and no views shows `null` with the reason
  *the destination page has not reported* and state `partial` — never `0`.
- Click-identifier coverage is `null` with no clicks.
- Consent: stored on session and event (`unknown | granted | denied | not_required`); `unknown` at redirect. The consent
  *policy* that gates marketing destinations lands with PR E; until then nothing is dispatched anywhere.

## Retention

Raw events are not kept indefinitely. The configurable retention job and the aggregate tables arrive with PR E; until then
no raw event is exported or dispatched, and nothing here is described as anonymised.

## PR B — forms, submissions and the lead they make

```
builder (Forms tab) → publish → /f/{token}  (SPA, public)  →  GET /api/public/forms/{token}   (sanitised definition)
visitor → POST /api/public/forms/{token}/submit → form_submissions → [creates_lead] → leads (one lead system) → lead_created
```

- **Tables:** `forms`, `form_fields`, `form_submissions`; `leads.custom` (jsonb) for project-specific answers (city, budget,
  property type). `form_submissions` is the system of record — answers are kept exactly as given, even if the lead is later
  merged. No spreadsheet is involved anywhere.
- **Field types:** text, email, phone, number, textarea, select, radio, checkbox, date, hidden, consent. Each has an Arabic
  and an English label, placeholder, help text, required flag, validation (`min_length`, `max_length`, `min`, `max`),
  default, options and one **visibility rule** (`field` + `equals | not_equals | in | filled` + value). The same rule is
  evaluated by the page and by the server (`FieldVisibility` / `isShown`); a field hidden by its rule is never required and
  its value is dropped.
- **Mapping:** each field may fill `name`, `email`, `phone`, `message` or `custom.<key>` on the lead. A form that creates
  leads must map a name and a phone or email, checked when saved and again when published.
- **Server judges:** only fields the form defines and that were visible are kept; unknown keys are dropped. Phone numbers
  accept Arabic-Indic digits and are stored as digits; the existing Saudi phone normalisation and 24-hour de-duplication
  then apply, so the same person submitting twice is one lead (`lead_created` is written once).
- **The lead carries the visit:** campaign parameters, click identifier and referrer come from the touchpoint's click event,
  not from the page. A touchpoint from another project is ignored.
- **Public surface:** unpublished, archived and unknown tokens answer the same 404. The public definition contains no
  workspace, client or project id, no `maps_to`, no internal notes. Redirect-after-submit is validated like a link
  destination. A hidden `website` field catches scripts; rate limit and body limit as for the beacon.
- **Privacy in the event store:** the page reports `form_view`, `form_start`, `form_validation_error` with a field *key* and
  an error *code* only. Answers reach `form_submissions` (and the lead) and never `tracking_events` (tested).
- **Mobile / RTL:** one column, 16 px inputs, 44 px targets, `dir`/`lang` from the form's own language with a switch, safe
  areas; verified with axe and a no-sideways-scroll check on Chromium and Mobile Safari.
- **Not in B:** consent *policy*, retention, funnels, owner assignment/SLA, journey timeline rows from submissions (C),
  provider dispatch (E). Consent is recorded as given and `unknown` otherwise.

## PR C — funnels, breakdowns, the tracked path and follow-up

- **Funnel stages are a closed list** (`FunnelSource`): `link_click, landing_view, form_start, form_submit, lead_created,
  lead_contacted, lead_qualified, appointment_booked, opportunity_created, sale_completed`. A stored funnel
  (`funnels`, per project) picks from it **in that order** — the journey's order — which is what makes every rate divide by
  the stage before it. There is no way to choose another denominator. A project with no funnel gets the standard lead funnel.
- **Cohort funnel.** The population is the *visits* (valid sessions) in a window, optionally for one link. Each visit carries
  what became of it: landing / form-start events, its submission, its lead, and that lead's own timestamps. Counts per unit:
  visits, submissions, distinct leads. Everything is `valid` traffic; the number of excluded visits is returned beside it.
- **Honesty rules (all in `FunnelCalculator`, unit-tested):** a step a destination page reports (landing, form start) is
  `null` + reason when there were clicks and the page reported nothing; a rate is `null` + reason when the step before it
  is empty, unreported, or is *smaller than this one* (an earlier step is not being recorded); the overall rate is last ÷
  first, never anything else; medians are over journeys that reached both steps in order, so a lead merged into an older
  contact cannot produce a negative wait. Capped at 100 000 visits (`truncated` says so).
- **Appointment and opportunity** are new `lead_events` types recorded by a person (`POST /api/leads/{lead}/milestones`,
  needs `leads.manage`; never in the future; they do not move the stage).
- **Breakdowns** by platform, campaign, ad group, ad, creative (from the link), device, browser, referrer, UTM source,
  landing path, date and hour (UTC). The dimension is chosen from a closed list. No city or precise location is collected,
  so none is offered.
- **The tracked path of a lead** (`GET /api/leads/{lead}/timeline`): the click, landing, form and submission events of the
  sessions that submitted for this lead, plus its pipeline events — each with its source and evidence. A lead with no tracked
  visit says *earlier touchpoints may have happened; they were not recorded here* instead of showing a bare creation.
- **Follow-up** (`GET /api/tracking/analytics/follow-up`): leads, contact rate, SLA compliance against a chosen target
  (default 60 min — a query parameter, not a stored promise), not yet answered / overdue, median time to first *attempt*
  (the first move out of "new") and to first *contact* (the existing `first_contacted_at`, set only when somebody got through),
  qualified. Attempts and contacts stay the two different facts the lead system already distinguishes.
- **Not in C:** spend-joined metrics (CPL, CPQL, CAC, ROAS per link) — no campaign binding exists on a link yet, so they are
  not computed rather than approximated by name; automatic owner assignment / task / notification on lead creation; both
  stay open and are reported as such in the final acceptance.

## PR D — tracking health, the test console, and Tag Manager / GA4

- **Health** (`GET /api/tracking/health`, per project or per link): sixteen-plus checks in five groups — first-party, destinations
  (GTM, GA4), browser pixels, server conversions, quality (click identifiers, deduplication, attribution, consent). States
  are the shared vocabulary `LIVE SYNCED STALE PARTIAL SANDBOX MANUAL ESTIMATED ATTRIBUTED NOT_CONFIGURED
  AWAITING_CREDENTIALS UNSUPPORTED`; each carries its **evidence** so a reader can disagree.
- **What is never green:** a provider **pixel** — digitalSmart does not observe a pixel fire in a browser, so every browser
  channel is `NOT_CONFIGURED` with how to verify it in the provider's own tool. A **server** channel is
  `AWAITING_CREDENTIALS` with no connection, `SANDBOX` with sandbox credentials, `UNSUPPORTED` where no server path is
  documented (X, OpenAI Ads), and `NOT_CONFIGURED` when connected but nothing is mapped — which is true until PR E.
  **Deduplication** is `NOT_CONFIGURED`: it is claimed only when a provider confirms both copies.
- **First-party** is `LIVE` (event in 24 h), `SYNCED` (this week), `STALE` (older), `PARTIAL` (clicks recorded, no landing
  page reporting — the snippet is missing) or `NOT_CONFIGURED`. Click-identifier coverage and attribution coverage are `LIVE`/
  `ATTRIBUTED` at or above 70 % and `PARTIAL` below it; consent is `PARTIAL` while any visit is `unknown`.
- **GA4 is read-only.** The current integration reads a property; it has no write path, so digitalSmart does **not** send
  GA4 events and says so in the check. Events reach GA4 through Tag Manager.
- **GTM** is the browser event layer, not the source of truth. A project's data layer names default to `ds_<event>` (the
  prefix keeps them off GTM's built-in names) and can be overridden per project (`tracking_settings`); names beginning
  `gtm` are refused. The generated **snippet** reads the touchpoint from the link, reports `landing_view`, pushes the mapped
  event to `dataLayer` and exposes `dsTrack()`; it never reads or sends what a visitor types. The public form pushes
  `form_view`, `form_start` and `form_submit` with the same map.
- **Test tracking:** `POST /api/tracking/links/{link}/test` starts a visit marked `test`. Everything that follows is kept and
  shown in the **event console** (time, event, source, status, event id, destination — polled every two seconds), is left out
  of every count and funnel, and creates **no lead**. Nothing is ever sent to a provider from test traffic; there is no
  dispatch yet, and the console says *not dispatched* rather than leaving the column blank.

## PR E — conversion mapping, dispatch, consent and retention

- **Provider capability catalogue** (`ProviderConversionCatalogue`, read on 2026-10-23, source URL stored with each entry):
  - **Meta — DOCUMENTED:** Conversions API; `event_name` is a standard *or custom* name, so the name is not validated here
    (the standard list was not read); browser and server copies de-duplicate on event name + `event_id` within 48 hours.
  - **Snapchat — DOCUMENTED:** Conversions API with a closed list of event names (there is **no** lead event — `SIGN_UP` or a
    `CUSTOM_EVENT_n`); `event_id` + `event_name` de-duplicate, and the pixel's `client_dedup_id` must equal `event_id`.
  - **Google Ads — DOCUMENTED, with a caveat:** offline click conversions (`UploadClickConversions`) need a `gclid`, `gbraid`
    or `wbraid` and a conversion action of type `UPLOAD_CLICKS`. Google states that from 2026-06-15 the method fails for a
    developer token that has not already used it and points new integrations at the Data Manager API, which has **not** been
    read — so a new token may be refused. A lead with no Google click identifier is *skipped*, not guessed.
  - **TikTok, LinkedIn — UNVERIFIED:** an events API exists but its documentation could not be read in a machine-readable form;
    nothing can be mapped. **X, ChatGPT / OpenAI Ads — UNSUPPORTED.**
- **Mapping** (`conversion_mappings`): a digitalSmart event (`form_submit lead_created lead_qualified appointment_booked
  opportunity_created sale_completed`) → a provider event the catalogue accepts. Refusals name what the provider does document.
- **Dispatch** (`provider_event_dispatches`): one row for **every** conversion that matched a mapping — sent, failed, unknown,
  or **skipped with its reason** (`consent`, `test_traffic`, `suspected_bot`, `duplicate_traffic`, `no_click_id`,
  `not_connected`, `live_transport_blocked`, `no_tracked_visit`) — unique per mapping and event id, so a repeat is one
  dispatch. Later stages of a lead (`lead_qualified`, `appointment_booked`, `opportunity_created`, `sale_completed`) are
  *offline* conversions carrying the consent, traffic class and click identifiers of the visit that produced the lead, with the
  deterministic id `lead-{lead}-{event}`.
- **Browser + server de-duplication:** the page pushes the submission's `event_id` to the data layer and the server dispatches
  the same id. `server_sent_at` is recorded; `provider_received` and `deduplicated` stay **null** unless a provider's own
  response says so — they are never inferred from our sending.
- **Retries:** transient failures retry with backoff (6 tries, same event id); a rejection is final; an `unknown` outcome
  (a request that may have been applied) is **never retried automatically** — a person retries it, with the same event id.
- **Sending:** the only sender simulates, for **sandbox** credentials, and says so (`source_state = SANDBOX`). A connection
  with live credentials is `live_transport_blocked`: a live sender per provider needs that provider's approved app, the
  account owner's authorisation and a real account to verify against — `BLOCKED_EXTERNAL`. Nothing is `LIVE_VERIFIED`.
- **Consent policy** per project: `required` (default; only granted consent is sent) or `not_required` (the owner's statement
  about their audience — not legal advice). A visitor who denied is never sent under either.
- **Retention** per project (30–1095 days, default 400): `tracking:purge` (daily 04:15) rolls raw events older than that into
  `tracking_daily_rollups` (a count per day, event, traffic class and link), deletes them and their sessions, strips the
  visitor/session/touchpoint identifiers from older submissions (the submission and its lead are kept) and click identifiers
  from older dispatches.

## PR F — reports, QR and the edges

- **Report section.** `SectionKind::Tracking` with a `TrackingView` (`links`, `forms`, `funnel`, `follow_up`, `health`). A
  tracking section is refused without a view, and with an unknown one. `EloquentTrackingReportReader` reuses the same
  analytics and health read models the Tracking area reads, so a report and the screen cannot disagree, and returns a fixed
  table: `columns` and `rows`, each cell a number, a word or null. Null renders as "not reported" in the page, the CSV
  and the workbook; a funnel step no page reported is null, not 0.
- **Client-safe.** Nothing in a tracking table identifies a person, a link or a record. `TrackingSectionTest` asserts that the
  internal render and the shared payload contain no lead name, phone number, destination host, link token, click id, form
  token or project id, and that another client's report reads only its own client.
- **QR.** Drawn client-side (`QrCode.tsx`, medium error correction, four-module quiet zone). A link meant for print is created
  with channel `offline_qr`; its scans are ordinary clicks of the same short link.
- **Calls.** `CallTrackingAdapter` and `CallEvidence` are a contract only. The caller's number is not a field.
- **WhatsApp.** `whatsapp_click` is not a lead. `whatsapp_conversation_started` has no evidence source here (it needs
  WhatsApp's own webhook) and is refused from the browser beacon.
- **Browsers.** `tracking-forms.spec.ts` is in the compatibility set: Chromium, Mobile Safari, Firefox, WebKit, Mobile
  Chromium. Not covered: real devices and a manual screen-reader pass.

## Revenue & operations A — campaign binding and cost

- **Binding.** `tracking_links.campaign_id / ad_group_id / ad_id / creative_id` (nullable, `ON DELETE SET NULL`), with a CHECK that a level
  cannot be named without the one above it. `CampaignBindingResolver` is the single place the hierarchy is checked.
- **Spend scope.** A link's spend is the spend of the entity it is bound to at that entity's own level; the campaign row is
  never the sum of its ads. DIRECT = no competing link. A competitor is another link on the same entity or a link bound
  inside it. UNAVAILABLE is the default and carries its reason. ALLOCATED is opt-in (`allocate=clicks`), same-entity only,
  labelled with method, share and low confidence.
- **Cost.** `CostMetrics` divides spend by a count of the same link: null with a reason for missing spend, unreported count or
  zero count. A zero *spend* still prices a unit at zero (that is a fact).
- **Revenue.** Two sources, shown apart: `lead_value` (won value recorded on the link's leads) and `store_attributed` (paid
  orders carrying the link's campaign tag, last-recorded evidence, only when the tag is unique to the link). ROAS per source,
  one currency, spend that is DIRECT or ALLOCATED.
- **Not here:** CRM won revenue and platform-reported revenue per link — neither is joined to a tracking link.

## Revenue & operations B — lead automation and the response time

- `lead_automation_settings` (per project), `lead_round_robin` (the pointer, locked while choosing), `leads.assignment_reason`.
- `AutomateNewLead` runs as the system from `SubmitForm`, only for a lead that submission created, and each step (assign, task,
  notify) is its own attempt: a failure is reported and cannot undo the lead.
- `LeadSla::judge` is pure: elapsed time is absolute, `APPROACHING_AT = 0.75`, the promise is judged on the **attempt** (the lead's
  first move out of `new`, the same definition the follow-up report uses), contact is separate. The workspace time zone only
  decides how a deadline is written (`due_at`, the task's due date).
- Queue groups are exclusive; "no response" is an attempt more than 24 hours old with no contact.
- Notification kinds added: `lead_sla_approaching`, `lead_sla_overdue`, `lead_qualified`, `lead_appointment_booked`,
  `lead_sale_completed` (all need `leads.view`; sentences carry no prospect detail).
- Not built: business-hours clocks (the promise runs on elapsed time), reassignment on SLA breach.
