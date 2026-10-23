# Tracking & Funnels

First-party tracking from an advertisement to a sale, owned by digitalSmart. Ad platforms, GA4 and GTM are *destinations
and evidence*, never the source of truth. Delivered in six bounded pull requests; this file states what each one holds
and is updated by each.

| PR | Scope | Status |
|---|---|---|
| A | Tracking links, redirect, visitor/session/touchpoint, event store, UTM and click-ID capture, bot marking | `IMPLEMENTED_NOT_VERIFIED` until merged and CI-green |
| B | Form builder, public forms, submissions, submission → lead | not started |
| C | Funnel builder and analytics, journey timeline | not started |
| D | Tracking health, debug mode, GTM / GA4 destination | not started |
| E | Provider conversion mapping, async dispatch, browser/server dedup, consent policy, retention | not started |
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
