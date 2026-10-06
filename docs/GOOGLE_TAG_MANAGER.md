# Google Tag Manager

How digitalSmart assesses a tag container, and the much longer list of things it refuses to
conclude from one.

The measurement records are in [MEASUREMENT.md](MEASUREMENT.md); the issue vocabulary this shares
is in [TRACKING.md](TRACKING.md).

## Status

**BLOCKED_EXTERNAL — configuration and consent, the same gate as Analytics.** A Google Cloud
project with the Tag Manager API enabled, an OAuth client with an allow-listed redirect URI, and a
user who can read the container, granting
`https://www.googleapis.com/auth/tagmanager.readonly`.

**Read-only, permanently — and this one matters more than most.** The API can create, edit and
publish tags. A reporting tool that could rewrite a client's measurement setup is a tool that will
eventually break one at three in the afternoon with nobody able to say what changed. The registry
records no write capability and there is no code here that would use one.

## A container is assessed, not synced

A container reports **configuration, not events**. It has no counts to contribute, so it cannot be
judged by freshness and it has no place in `MeasurementSyncAdapter` — forcing it there would mean
implementing `fetchEventCounts()` as an empty page, which a reader would reasonably take to mean
"measured nothing" rather than "not that kind of thing".

So there is a third adapter shape, `TagContainerReadAdapter`, and a separate endpoint.

### Why readiness is not part of `/measurement/health`

Tracking health is derived from this product's own tables and always answers. Readiness requires a
live call to Tag Manager, which can be slow, rate-limited or unauthorised. Folding one into the
other would let a provider outage empty the tracking page of facts it already had.

## It reads the live version, never a workspace

```
GET …/v2/accounts/{account}/containers/{container}/versions:live
```

A workspace holds what somebody is working on. The live version is what the site is actually
running. **An agency believing tracking is configured because the workspace looks right is the
single most common failure in this area**, and reading the wrong one would make this product a
participant in it.

A container that has never been published answers `404` on `versions:live`. That is reported as a
fact — `has_live_version: false` — not as an error, because it is the most consequential thing this
whole area can tell somebody: the workspace looks complete and none of it is on the site.

When that is the case, **it is the only finding returned.** Reporting paused tags and missing
triggers inside a container nobody published would bury the one fact that matters.

## What it concludes, and from what

Every finding rests on a structural fact the API states outright.

| Code | Severity | Grounds |
| ---- | -------- | ------- |
| `container_never_published` | critical | `versions:live` has no version |
| `tag_without_trigger` | critical | the tag lists no `firingTriggerId` |
| `tag_trigger_missing` | critical | a `firingTriggerId` is absent from the version's `trigger[]` |
| `tag_paused` | warning | the tag's `paused` flag |
| `container_unpublished_changes` | warning | `workspaces.getStatus` reports `workspaceChange[]` |
| `measurement_id_not_in_container` | warning | an expected id appears nowhere in the published version |

## What it refuses to conclude

### It never reads a tag's purpose from its name

"GA4 – Purchase" is a label somebody typed. It may fire on a thank-you page, on every page, or on
a button nobody clicks. A product that reported "purchase tracking is configured" because a tag was
called that would be confidently wrong in the one place an agency cannot afford to be, and the
client would have no way to tell.

**A tag's name appears only as the subject of a finding — never as grounds for one.** A container
whose only tag is called "GA4 – Purchase" and which fires properly produces *no findings at all*,
and there is a test named after that.

The same applies to the tag `type` string (`gaawe`, `googtag`, `awct`): reported, never
interpreted.

### It does not accuse a tag firing on a built-in trigger

This is the false positive that would have killed the feature on first use.

Every container is created with **All Pages**, **Consent Initialization** and **Initialization**
triggers. They cannot be deleted, and they are **not returned by the triggers list**. Nearly every
tag in a real container fires on All Pages — so a naive `array_diff` against `trigger[]` reports the
entire container as broken.

Container-defined trigger ids are small integers assigned per container (1, 31, 32, …); the
built-ins sit just below the 32-bit signed maximum. Ids at or above two billion are treated as
built-in, and so is any id that is not a plain integer.

The threshold is set so that **the failure mode is silence rather than a wrong accusation**: a
missed dangling trigger costs one warning, while a false one on every tag would empty this page of
meaning immediately.

### The measurement-id check is absence of evidence, not evidence of absence

The one inference here, framed carefully.

The expected ids come from the client's **own registered analytics sources** — not from anything
typed at the endpoint — so the check compares what somebody registered as measuring this client
against what the published container actually refers to. Those two drifting apart is invisible
everywhere else in the product.

The scan looks for the **id's own shape** (`G-`, `GT-`, `AW-`, `UA-`) anywhere in a tag's parameter
tree, under any key, at any depth, walking `list[]` and `map[]` recursively. That is deliberate:
which parameter key holds a measurement id depends on the tag template, and this product does not
claim to know the internal schema of every GTM template in existence. **Finding the id is a fact
about the container; assuming a key would be a guess.** The container's own `tagIds` count as found
too — a Google tag configured at container level is still that property being loaded.

When an expected id is not found it is a **warning**, phrased as something to confirm:

> No tag in the published version of Noon Retail — Web refers to G-7QK2M4XZPL.
> *Confirm how this property is loaded — a custom template or server-side container would not show
> here.*

A container can load a property through a custom template, a lookup variable or a server-side
container, none of which this product can see. The ids it *did* find travel in the evidence, so a
reader can judge the claim instead of trusting it. Telling somebody to "add the tag" when it is
already there is how a tracking page loses its authority.

## Containers are listed separately from being assessed

`GET /api/measurement/containers` is one cheap call. Reading each container's published version is
a call per container, so doing it eagerly would make the page that exists to let somebody *pick* a
container slower the more containers they have.

`public_id` (`GTM-XXXXXX`) is the field that matters: it is what somebody will find in their
client's page source, and the only way to be sure a container is the right one. A numeric container
id proves nothing to anybody.

`is_web` distinguishes a web container from a server-side or app one, so nobody expects web page
events from something measuring elsewhere.

## Unpublished changes span workspaces

`workspaces.getStatus` is addressed per **workspace** —
`accounts/{a}/containers/{c}/workspaces/{w}/status` — and a container can have several, which is how
two people work on it at once. Changes in any workspace count as unpublished: from the live site's
point of view that is exactly what they are, regardless of whose workspace they sit in.

Nothing recorded means nothing known to be unpublished — `false` rather than a guess. Claiming
unpublished changes that do not exist sends somebody to publish a container for no reason, and the
next warning they see carries less weight.

## A source pointing at a container nobody can read fails loudly

If the registered external id matches no container the connection can see, the endpoint returns
`404`. Silently assessing a different container would be the worst available outcome here.

With real credentials and no authorised OAuth client, the endpoint returns `503`
`provider_not_configured`. Reporting "ready" or "never published" would both be fabrications, and
either would be acted on.

## Tests

- `backend/tests/Unit/Domain/ContainerReadinessTest.php` — the assessment as plain objects, with
  the refusals as named tests: the tag name, the built-in trigger, the unparseable id.
- `backend/tests/Feature/Measurement/TagManagerReadinessTest.php` — the endpoints over HTTP against
  a deliberately messy fixture container: a tag with no trigger, a paused debug tag, a genuinely
  dangling trigger, an unpublished edit, and a second container nobody ever published.
