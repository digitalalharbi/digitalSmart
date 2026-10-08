# The client portal

What each client can read, who decided it, and when.

Phase 22 delivers the **disclosure model** — a per-client, per-surface grant with an audit trail — and the
**client's own application**: its own shell, its own navigation, and four surfaces the agency switches on one at
a time.

## The question this module is for

Until this phase, a client's access was a permission on a role. `analytics.view` sat on `ClientViewer`, and every
client in a workspace shared it. That is not a decision anybody took about a particular client: it was a decision
taken once, about a category, by whoever first needed a client to see a chart.

The question an agency actually faces is narrower and is asked per account. *Can Alhambra see last month's spend?
Who let them? Since when? And can we stop it this afternoon without taking it away from everybody else?* A role
cannot answer any of those.

So the portal is four grants per client, each one exposed by a named person at a recorded time, each revocable on
its own, and all of them switchable off together with a reason.

## Three lists, not one

A portal built the other way round — every surface in the product available, each behind a toggle — makes exposing
the agency's own reasoning a *configuration mistake* rather than an impossibility. Configuration mistakes get made
on a Friday by whoever is covering. So the vocabulary is closed, and it comes in three parts.

### What can be exposed

`App\Domain\Portal\PortalModule`, four cases. Membership is a judgement about **whose information a surface
carries**, not about how sensitive it feels:

| Module | What the client reads | Needs first |
| ------ | --------------------- | ----------- |
| Performance | Spend, delivery, the ratios that follow, conversions and their cost — their own projects only | a project bound to an advertising account |
| Reports | The reports marked client-safe for them. Never an internal one, whatever the grant says | — |
| Store revenue | Orders and revenue from their own store, with the authority named on every figure | a connected store |
| Tracking | Whether measurement on their own site is working, and what is wrong with it | a measurement source |

Two properties earn a surface a place here. It is **the client's own information**, or a statement about their own
property. And it has **a client-facing form that already exists, enforced in the query** — reports have an
audience, client requests exclude internal messages in SQL. A surface that would need a new "hide the internal
parts" pass to be safe is not ready to be in this enum, because that pass is one careless refactor from being
skipped.

### What will never be exposed

`WithheldModule`, seven cases, each carrying its reason. The enum exists because *"not implemented yet"* and
*"deliberately never"* are different answers and only one of them should be given twice. Every quarter somebody
asks why the client cannot see the budget; without this, the answer depends on who is asked.

| Withheld | Because |
| -------- | ------- |
| Budgets and pacing | A budget carries the agency's own allocation thinking — what was held back, what was moved and when. What a client is owed is what was spent on their behalf, which is on the performance surface. |
| The work board | A board carries the agency's own reasons for things being blocked and its own estimates. A client reading one ends up managing the agency's week. |
| Approvals | The trail records who authorised a change and who hesitated. It stays honest only while it is internal. |
| The Lead Center | Named people with the agency's own qualification notes. A client has their own system of record for those people, and a half-attributed second list is how one lead gets counted twice. |
| Attribution | Every figure except the recorded one is a model, and a modelled number read as a record is the most expensive mistake this product can cause. |
| Customer journeys | A reconstruction from identity evidence of varying confidence. Presented without that evidence it reads as a recording of what a person did. |
| The audit trail | It names agency staff and every action they took. A personnel record as much as a security one. |

These are not permissions and there is no toggle behind them. The configuration surface lists them *beside* the
modules that can be exposed, with the sentence — the same reason the attribution surface shows the models it
cannot compute instead of hiding them.

**Attributed revenue is refused through the other door too.** `GetPortalRevenue::available()` excludes
`RevenueSource::Attributed`, because offering it as a revenue source would be the same disclosure arriving by a
different route.

### What cannot be withheld

`AlwaysAvailable`, one case: the client's own intake thread. A client writes half of that conversation, and a
person is entitled to read a conversation they are part of. A switch here would either control nothing or let an
agency withhold the answer to a question the client themselves asked.

It is published beside the other two lists so the reason travels with it, and a test holds all three disjoint — a
surface in two lists would be a product that exposes something it also publishes a refusal for, and whichever list
the caller read would decide the answer.

## Who is a client

Nothing else in this product says *"this person belongs to the client rather than to the agency"*. Project
assignment does not: a creative is assigned to a client's project and is plainly not their employee — which is
exactly the bug a test found here before `portal.read` existed.

So it is a permission, held by `ClientViewer` and by no other role:

| Permission | Who | What it opens |
| ---------- | --- | ------------- |
| `portal.read` | the client's own people | The client side of the portal, for the clients whose projects they are assigned to |
| `portal.view` | agency staff | The configuration, read-only — and the client's pages as a **preview** |
| `portal.manage` | the account's owners | Switching a portal on or off, and changing what it exposes |

**Precedence is load-bearing.** The owner role holds *every* permission by construction, so `portal.read` alone
cannot mean "is a client". A reader holding `portal.view` is staff previewing; a reader with `portal.read` and
without it is the client. Both ends apply that rule — the server to decide what may be read, the router to decide
which application to render.

A media buyer holds `view` and not `manage`: knowing whether a client can already see this week's figures is a
question anybody handling the account asks, often with the client on the phone, and widening what somebody outside
the agency can read is a different act.

## The preview

An agency member with `portal.view` reads the client's pages **through the same code**, and every response says
`is_preview`. Reading a page as the client is the thing to do before switching a module on — the same argument the
reporting module makes about a client-safe report — and a preview built from a different query would be a preview
of something nobody will see.

It is flagged because an agency member who mistook a preview for their own view of the product would conclude half
of it had disappeared.

## Suspension

A portal is **suspended, not deleted**, when an engagement pauses or a dispute starts.

- **The grants survive.** They are what the portal goes back to, and they are the answer to "what could they see
  while it was on". Clearing them would make resuming an act of reconstruction from somebody's memory of a meeting.
- **It takes effect on the next request**, not at a billing boundary. Somebody suspends a portal because something
  should stop being readable *now*.
- **The reason is required and internal.** Required because a portal switched off with no reason is a row nobody
  can act on. Internal because "payment overdue" is a commercial conversation and a portal page is the worst place
  to have it — the client is told the portal is off and to make contact, which is the outcome either way.
- **Resuming is its own act** with its own entry naming what came back. "We turned it back on" and "we turned it
  back on including the revenue figures" are different sentences, and the second is the one somebody is asked
  about.

`PortalState::NotConfigured` is distinct from `Suspended` for the same reason the reporting module distinguishes
"no comparison requested" from "a comparison that cannot be made": one is a decision nobody has taken, the other
is a decision somebody took. The client is told which.

## What the schema enforces

| Rule | Why it is in the schema |
| ---- | ----------------------- |
| A portal row means configured | `NotConfigured` has no representation: the absence of a row *is* that state. A nullable state column would allow a configured portal in no state, and whichever path created it would be the one nobody tested. |
| A module row means currently exposed | Withdrawing deletes the row. The history lives in the audit trail, which is permanent and already the answer to "who let them see that"; a soft-deleted second copy would be a second answer, and the two would disagree the first time a backfill touched one. |
| Suspension carries its reason, both ways | A portal switched off with no reason is a row nobody can act on, and a reason left on a resumed portal reads as a current one. |
| One portal per client | Two rows would mean two answers to "can this client read their reports", and the query that happened to order differently would win. |
| The module vocabulary is a CHECK from the enum | A surface this product has decided not to expose cannot be exposed by a payload with a typo in it. |

## The trail

Five actions, and all five are **security events** — filed beside "who connected a platform" rather than beside
"who renamed a report", because that is what they are:

`portal.enabled` · `portal.suspended` · `portal.resumed` · `portal.module_exposed` · `portal.module_withdrawn`

Three rules about what gets written:

- **One entry per module, naming it.** An entry saying "the set is now performance, reports" cannot answer "when
  did they get the revenue figures", which is the question this trail is kept for.
- **Withdrawal is its own action**, not an exposure with a flag. The two questions asked of the trail are "when
  could they see it" and "when did that stop", and an entry answering one by implication answers neither.
- **Nothing is recorded when nothing changed.** Saving a form unchanged is not a disclosure, and a trail that
  logged it would bury the five real entries in fifty.

An exposure entry also records `had_data_behind_it` — whether the module could show anything at the moment it was
switched on.

## Exposed with nothing behind it

Each module declares what must exist before it can show anything, and the agency's surface says so **before** the
switch is thrown. An empty surface exposed to a client does not read as "not connected yet"; it reads as the agency
having done nothing.

The count of exposed-but-empty modules is a badge on the client's card and a figure on their detail, rather than
something a reader works out by opening each client in turn — it is the figure that embarrasses somebody.

## The client's own application

Its own shell, not the agency's with things hidden: no sidebar of agency surfaces, no notification bell, no command
palette, no account menu. A client signing in is redirected to it.

**The navigation is built from what the reader may open**, which is why there is nothing on it to be refused. A
menu item that answered 404 would tell a client a surface about them exists and is being kept from them, and that
is a sentence about the agency's intentions rather than a feature. For the same reason a module that was not
exposed answers **404 and not 403**.

Four things on the performance surface are deliberate:

- **The metric set is fixed and is not a parameter.** A client who can add metrics will eventually add two that
  cannot be compared, or one whose denominator is missing, with nobody from the agency present to explain the
  result. The seven are the money, the delivery it bought, the two ratios that follow, and the conversions with
  their cost.
- **Reach and frequency are absent.** Both are legitimate and neither adds up across campaigns, so on a surface
  covering a whole account they would be either wrong or blank for a reason the client cannot act on.
- **The comparison is always the period immediately before, and it is named.** A client cannot choose it, so it
  cannot be chosen badly — and a change figure whose baseline is unstated is one somebody reads against whatever
  period they had in mind.
- **The period is said to end yesterday.** A client comparing this with a platform dashboard that includes today
  would otherwise conclude the agency's figures are wrong.

Every figure carries the source state the pipeline produced. A client whose figures come from a provider still
awaiting its authorization reads `SANDBOX`, in those words, exactly as the agency does.

The revenue surface keeps the **required revenue source** with no default, and builds its chooser from the server's
own refusal so it cannot offer a source the surface would reject. The tracking surface shares
`TrackingHealthComposer` with the agency's own: two diagnoses of one site would be two answers, and the one the
client read would be the one nobody maintained.

## What is not built

- **No client login of its own.** A client is a workspace member with a role. Self-service sign-up, a separate
  password policy and a branded sign-in page are a product decision rather than a missing function.
- **No branding.** The portal carries the product's mark and the client's name, not the agency's logo. White
  labelling means stylesheet-level theming per workspace, and doing it badly is worse than not doing it.
- **No per-project narrowing inside a portal.** A client reads their whole account. An agency wanting to show one
  project and not another has no way to say so yet.
- **No notification when a module is exposed.** The client is not told "you can now see your revenue". It should
  arguably be an e-mail, which needs a sender identity and a bounce story.
- **No scheduled suspension.** A portal that should close at the end of an engagement has to be closed by
  somebody.
