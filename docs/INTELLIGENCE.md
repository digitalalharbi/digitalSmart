# The assistant

What the figures say, with the figures.

Phase 23 delivers **Ask**, **Analyse**, **Investigate** and **Report**: five analysers over a client and a period,
every finding carrying the evidence it rests on, and a language model that writes the prose and is refused when it
states a number the evidence does not contain.

## The one sentence this module rests on

**The product computes every number. The model only writes the prose.**

Everything else here follows from it. An assistant built the other way round — a model handed a question, deciding
which figures to fetch and doing the arithmetic — is an assistant whose denominators were chosen by a language
model. A wrong denominator is the one error in analytics nobody catches, because the answer looks fine.

So the order is: the analysers produce findings from the same analytics every other surface reads; the findings are
complete, with their figures, before a model is involved at all; the model is given those findings and asked to
join them into readable prose; and the prose is then **checked, number by number**, against the evidence.

## The guard

`NarrativeGuard` is what makes the sentence above checkable rather than aspirational.

Every figure the product computed goes into the brief's permitted list. Every number in the model's prose is
extracted and matched against it. Anything unmatched means the model produced a figure — by arithmetic it was not
asked to do, by misreading, or by invention — and **the narrative is discarded**: the reader gets the product's own
sentences, and the response says the model's answer was refused and why.

Four decisions in the matching:

- **It compares numerically, not as strings.** "4.2" and "4.20" are the same figure, and a string comparison would
  reject a model for rounding.
- **The tolerance is the precision the prose chose.** A figure written to two decimals may be out by half of the
  last place; one written to none may be out by half a unit. A relative tolerance was the obvious alternative and
  the wrong one: a tenth of a percent of 1,250,000 is 1,250, so "1,250,001" would have passed as a restatement of
  "1,250,000".
- **Thousands separators are normalised, both ways.** A model writing "1,250,000" or "1 250 000" is writing the
  figure it was given. One writing "1,250,001" is not.
- **Years are not an exemption.** It would be convenient to let four-digit numbers through, and that is the hole
  "up 2024%" walks through. Prose that needs a date gets it from the evidence.

The permitted list is deliberately generous in one respect and strict in another: it contains every figure in the
evidence **and every number appearing anywhere in the brief's own text** — the period, the headlines, the labels.
The rule is therefore *the model may restate the brief and nothing more*. The reading of what counts as a number is
`FigureScanner`'s, shared by the brief and the guard, because two regular expressions that almost agreed would
refuse prose for a difference nobody intended.

**The deterministic narrative is held to the same standard**, and a test asserts it. If the product's own prose
could state an unsupported figure, the guard would be holding a model to a rule the product does not keep.

## What happens when there is no model

Everything, except the better prose.

`NoLanguageModel` is bound whenever no API key is configured — which is **every environment in this repository**:
a model credential is a production secret this repository cannot hold, so the live call is `BLOCKED_EXTERNAL`. The
assistant still answers: the findings, the evidence, the confidence and the recommendations are all the product's,
and `DeterministicNarrative` composes the paragraphs.

`Narrator` composes the product's narrative **first, unconditionally**, and only then asks a model. A reader never
waits on a third party to find out what their own figures did, and a model that is missing, slow, rate-limited or
wrong costs them a paragraph rather than a request. Three outcomes, and the response says which:

| Outcome | `written_by` | `model_rejected` |
| ------- | ------------ | ---------------- |
| No model, or unreachable | `product` | false |
| A model answered and the guard passed it | `model` | false |
| A model answered and the guard refused it | `product` | **true**, with the reason |

The third is published rather than swallowed. A guard nobody can see working is a guard nobody will maintain.

## The adapter

Built against the published Messages API contract: `POST /v1/messages` with `x-api-key` and
`anthropic-version: 2023-06-01`, a body of `model`, `max_tokens`, `system` and `messages`, and a response whose
`content` is an array of blocks of which the `text` ones carry the prose. Errors are
`{"type":"error","error":{"type","message"},"request_id"}` with the documented statuses, and `request-id` is on
every response.

Three decisions about the request:

- **No `temperature`.** Deprecated for models after Claude Opus 4.6, and this is a task with one right answer.
  Variation is not a feature here.
- **No `thinking` configuration.** The models this could run against differ in which settings they accept — some
  require adaptive, some reject `disabled` — and adaptive is the default on all of them. Sending nothing is the
  one choice correct across the range.
- **One retry, on the statuses the documentation says to retry**: 429 honouring `retry-after`, and 500, 504 and
  529 with a short backoff. Everything else is an answer, and answers are never retried.

**The brief carries no client identity.** Not the client's name, not a project, not a campaign — only each
finding's subject, which is the account, a platform, or measurement. Sending a third party "Alhambra's cost per
click doubled" discloses a commercial fact about a named company; sending "the account's cost per click doubled"
discloses a number. The interface puts the name back around the prose, where it never left the workspace.

The whole contract is tested against fixtures of the documented shapes, so the day a key exists the only untested
thing is the network.

## The analysers

Each is a **pure function over an `AnalysisContext`** — no database, no clock, no HTTP. Everything is fetched once
before they run, which is what lets "a doubling of cost per click on nine clicks is not a finding" be a rule
somebody reads and tests rather than a condition buried in a query. It also means two analysers cannot disagree
about what the period's spend was.

They run in this order, which is the order a reader sees:

| Analyser | What it says | Needs |
| -------- | ------------ | ----- |
| `TrackingBlindSpotAnalyser` | Whether the conversion figures mean anything | `tracking.view` |
| `DeliveryStoppedAnalyser` | A platform that was spending and has stopped | `analytics.view` |
| `EfficiencyDriftAnalyser` | What a click and a conversion cost, and whether that moved | `analytics.view` |
| `SpendConcentrationAnalyser` | Where the money went, when most of it went to one place | `analytics.view` |
| `PeriodShapeAnalyser` | What the period was, whether or not anything was wrong | `analytics.view` |

Measurement is first because a conversion figure that cannot be trusted outranks a conversion figure that moved.
`PeriodShapeAnalyser` is last and **always speaks** — without it a quiet period produces an empty page, and an
empty page says "nothing is wrong" when it means "nothing matched".

**Analysers a reader may not run are named**, not dropped. An assistant that silently omitted a finding would be
an assistant whose answers quietly differed by role, and the reader with fewer permissions would never know a
question had gone unasked.

### What they refuse to say

- **A rate with too small a denominator.** Below 30 events the answer is "not enough delivery to say", emitted as
  a finding of its own rather than as silence — because a reader who sees nothing about efficiency concludes
  efficiency was fine.
- **A change from a base of zero.** Everything is an infinite increase on nothing, and reporting that as a
  percentage is how a dashboard comes to claim growth of forty thousand percent.
- **A total of zero where there is no data.** `PeriodShapeAnalyser` says the period recorded nothing, which has a
  different cause: a period before the account was connected looks exactly like a period in which nothing ran.
- **A recommendation on indicative evidence.** A recommendation is a sentence telling somebody to move a client's
  money, and "we think, from twelve clicks" is not a reason to move it. Most findings carry none, and the surface
  says why rather than leaving a blank.
- **A verdict on where budget should go.** `SpendConcentrationAnalyser` reports concentration and, when the
  concentrated platform is also the expensive one, says so as a question. A search click and a feed click are not
  the same click: this product can see the price and not the plan.

## Confidence is derived

`Confidence` takes three inputs, each of which can ruin a finding on its own: the source state, the denominator,
and whether both periods have data. Firm needs a current provider read; stale, partial and manual figures are
tentative; sandbox and estimated are indicative, whatever the denominator. There is no model involved — a
confidence a language model wrote would be a number with no derivation, which is what this class exists to avoid.

## Asking a question

`QuestionIntent` is a closed list of six, matched by keyword against a lower-cased question, with Arabic terms
beside the English because the interface is bilingual and a reader asking in Arabic is not asking a lesser
question.

`Ask` **runs the full analysis and then selects from it**, rather than running a query the question described. The
figures are therefore computed the same way whatever is typed, so a question cannot choose its own denominator: a
reader asking "why did our cost per click go up" and a reader asking for an analysis see the same arithmetic.

**A question matching no intent is refused**, with the recognised questions listed beside the box, and the findings
are dropped rather than offered as a consolation — handing somebody an analysis they did not ask for, in answer to
a question that was not understood, teaches them that the assistant answered.

## What it will not do

`AssistantMode::enactsAnything()` returns false for every mode, and a test asserts it. Nothing here writes a
report, a budget, or a byte to an advertising platform. The assistant proposes; a person decides, in the module
that owns the decision — the same argument the approvals engine makes about why an approval is not an execution.

`Report` proposes a definition and **validates it through `ReportDefinition::assertValid()`** before returning it,
because a proposal the reporting module would refuse costs a reader the time to find out. It proposes `internal`
and never `client_safe`: a client-safe report is one written to be read by the client, and nothing assembled from
an analysis has been read by anybody yet.

## What is not built

- **`Plan`** — a media plan needs the objective, the budget and the flight dates somebody agreed with the client,
  and this product holds none of those at the point the assistant runs. Drafting one from performance alone would
  produce a plan shaped entirely by what was easiest to measure last month. It is published in the vocabulary as
  deferred, with that reason: a mode that appeared in the interface and produced nothing would be a placeholder
  wearing a feature's clothes.
- **No campaign briefs and no presentation narratives.** Both are Phase 24's subject, and both are writing rather
  than analysis.
- **No budget, pacing or attribution analysers.** Three obvious additions, each needing a judgement this phase did
  not make: pacing needs the agency's own flight dates, and an attribution finding needs the coverage caveat
  explained by somebody who can answer a question about it.
- **No anomaly detection over time.** The analysers compare two periods. A model of what normal looks like for a
  given account needs more history than a thirty-day window and a statistical claim this product is not yet in a
  position to make honestly.
- **Nothing is stored.** An answer is computed on request and not kept, so "what did the assistant say last week"
  is unanswerable. A stored answer would need to carry the figures as they were, which is what a report snapshot
  already does.
