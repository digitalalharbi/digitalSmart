# Operations

The board an agency runs its week from: what is open, what is late, what nobody has dated, and
who is carrying it.

Phase 20 delivers **tasks** and the **approvals engine**. Client requests, notifications and
realtime are the rest of the phase; what is not built is listed at the end.

## The question this module is for

A work board is the easiest surface in a product to make dishonest, because the dishonest version
is also the tidier one. A board that reports "three overdue" beside a wall of neat columns looks
like a team in control. It is frequently a team with forty tasks nobody has put a date on, where
"three overdue" describes the small minority of work that was capable of being late at all.

So this module publishes the figures a board usually hides, and refuses the arithmetic a board
usually fudges.

## What the schema enforces

Five rules are CHECK constraints rather than conventions, because each has an obvious wrong
version and none of them is visible once the data is in.

| Rule | Why it is in the schema |
| ---- | ----------------------- |
| `state = 'blocked'` requires a non-empty `blocked_reason` | A column of unexplained blocked cards is a column nobody can clear. The reason names who to chase. |
| `state = 'cancelled'` requires a non-empty `cancelled_reason` | Deciding not to do work is a decision somebody will be asked to defend. |
| `(state = 'done') = (completed_at IS NOT NULL)` | Both directions. "Done" with no time is a claim with no evidence; a completion time on work that is not done is a leftover from a reopening that would quietly enter the next turnaround average. |
| `due_on` is a `date` | "Due Thursday" has no time in it. Storing a timestamp invents a deadline nobody set, and then everything due today is overdue by lunchtime. |
| `(subject_type IS NULL) = (subject_id IS NULL)` | Half a link renders as a link and goes nowhere. |

The state vocabulary and the priority scale are CHECKs from their enums, so a typo in a payload
cannot create a sixth column nobody styled.

## The five answers about when work is due

`App\Domain\Operations\DueHorizon` is the whole honesty argument of this module in one enum:

- **Overdue** — open, and past its date.
- **Due today** — open, and due today. Due today until the day ends, which is why `due_on` is a
  date.
- **Due within a week**
- **Later**
- **No due date** — its own bucket.

Two rules come with it.

**Undated work is not "later".** It is work that *cannot be late*, which is a different fact, and
folding the two together is how a board reports a pipeline it does not have. Undated is drawn as
its own bar, counted in its own figure on the page, and never absorbed.

**Only open work is overdue.** Work finished a week after its due date was late; it is not
outstanding. Counting it would leave an overdue figure nobody could ever clear.

### When the overdue figure may be read as lateness

`TaskWorkload::isLatenessReportable()` is false once less than **80%** of the open board carries a
date. The threshold is a product decision, not a measurement, which is why it is a named constant
with its reasoning beside it — the same judgement attribution coverage makes at 70%. Below it the
page says, above the chart and outside the frame so it survives the table view:

> N tasks are overdue, but M have no due date at all — only X% of the open board carries one…
> Read the overdue figure as a statement about the dated work, not about all of it.

## The rates, and when they are null

| Figure | Denominator | Null when |
| ------ | ----------- | --------- |
| Completion rate | **Settled** work: done + cancelled | Nothing has settled |
| Dated share | Open work | Nothing is open |

The completion denominator is settled work rather than the whole board on purpose. A team that has
just taken on fifty new tasks has not become worse at finishing things, and dividing by everything
would say it had.

A rate with no denominator is null, never zero: `0%` and "nothing to measure" look identical on a
card and mean opposite things.

## The board's moves

`TaskState` declares the legal transitions as data, and the API publishes them at
`GET /api/tasks/vocabulary` so the interface can omit an impossible target rather than letting
somebody discover the rule by being refused.

```
todo        → in_progress, blocked, cancelled
in_progress → in_review, blocked, done, cancelled
blocked     → in_progress, cancelled
in_review   → done, in_progress, cancelled
done        → in_progress
cancelled   → todo
```

**Blocked does not lead to done.** The refusal is named rather than generic, because it is the move
people actually try: the block cleared, the work got done, the card jumped. Recording that loses
both the fact that somebody did the work and any way to measure what the block cost. The message
says so, and lists where the card *can* go.

**Finished work can be reopened.** A real board does this; making it impossible only means somebody
edits a row by hand. Reopening clears `completed_at`, so a second completion is recorded as the
second completion.

**`started_at` is stamped once**, on the first move into progress and never again, so "how long did
this take" measures from when somebody picked the work up rather than from the last time it was
unblocked. `working_days` is null when there is no start — unknown, not zero.

**A reason is never cleared on the way out.** Work blocked on a client's legal review was blocked on
a client's legal review, and that stays true afterwards. It is the answer to "why did this take
three weeks", and the detail panel keeps showing it after the work has moved on.

## What a task is about

A task may carry a link to a creative request, a lead, a campaign, a budget or a project. It is a
**link, never a claim**: attaching a task to a brief says somebody has work to do concerning it,
not that the brief changed, and closing the task changes nothing about the subject. The two have
separate state machines on purpose — a board that advanced a brief because somebody ticked a
checklist item would produce an approval trail nobody could rely on.

There is **no foreign key** behind it. A polymorphic key cannot be expressed in one column, and
five nullable keys would make the common read a five-way join for a label. The cost is that a
subject can be deleted underneath a task, so the read model resolves every subject on every read
and reports a missing one as missing. The interface then says *"This creative request has been
deleted"* rather than rendering a link that looks live and goes nowhere.

## Who can see what

| Task | Visible to |
| ---- | ---------- |
| On a project the actor is assigned to | Them |
| On a client they have any project with, but no project of its own | Them |
| With neither a project nor a client — the agency's own internal work | Anyone the permission admits |

The first two rules are the ones the creative board and the stores already use, written the same
way so three surfaces cannot disagree about who can see what. The third is new and worth stating:
there is no client relationship to scope internal work by, and withholding it would hide the board
from the people who keep it.

`tasks.view` and `tasks.manage` are granted to owners, administrators, project managers, media
buyers and creatives. **A client viewer has neither.** An agency's board carries its own reasons
for work being blocked and its own estimates of how long things take; a client seeing them is an
internal conversation leaking. What a client may see is Phase 22's portal, decided explicitly
rather than by a role that happens to open a screen.

`assignee_id=me` and `assignee_id=unassigned` are resolved **on the server**. A front end sending
its own idea of who the actor is would be one session-restore defect away from showing somebody
else's week.

## Permissions

One permission to read and one to write, deliberately not split the way creative and budget
approval are. Nothing about moving a card is a judgement somebody else needs protecting from, and a
board where only a manager may tick something done is a board kept by hand — which means the board
is fiction and the figures above it are too.

## The two charts

Both follow `docs/DATA_VISUALISATION.md`, and both use the **ordinal** ramp rather than categorical
hues, because both scales are ordered: swapping two steps would change the meaning.

**When the open work is due** — the five horizons, soonest first, darkest at the late end. Its
reason for existing is its last bar. Closed work is excluded; the caveats say both things.

**Who is carrying the work** — open tasks per person, **stacked** by priority. This is the one
place in the product where stacking is right: the segments of a person's bar are parts of their own
open load, and the total is a real figure somebody is accountable for. (The attribution comparison
next door is grouped for the opposite reason — those bars are alternative accounts of the same
money, and their sum exists nowhere.)

The unassigned pile is a row like any other, sorted last however big it is. Leaving it out would be
the flattering version — a comfortable team drawn beside a stack of work nobody has agreed to do —
and leading with it would read as somebody called "Unassigned" being overworked. Rows past the
chart's cap of ten are left to the table, which has all of them, and the frame says how many are
not drawn: a bar labelled "Other" names nobody, and this chart is read to find out who to talk to.

## Cards and counts come from different queries

`board()` returns a capped page per column. `workload()` and `load()` each aggregate the whole
filtered board in SQL. Deriving the figures from the rows that came back would be one query fewer
and a wrong number the day a client got busy — the kind that reads as right for months. Every
column therefore publishes both `total` (the column) and `tasks` (the page), and `is_truncated`
tells the interface to say the cards are a page.

All three reads take the **same filter object**, because a figure drawn over a wider population
than the cards beside it is the same defect in a different place.

## The API

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/tasks/vocabulary` | States, legal moves, the priority scale, the due buckets |
| `GET` | `/api/tasks/board` | `client_id`, `project_id`, `assignee_id` (`me` / `unassigned` / a uuid), `priority`, `subject_type`, `subject_id`, `per_column` |
| `POST` | `/api/tasks` | |
| `GET` | `/api/tasks/{task}` | |
| `PATCH` | `/api/tasks/{task}` | Title, description, priority, due date, project |
| `POST` | `/api/tasks/{task}/state` | |
| `POST` | `/api/tasks/{task}/assignee` | Absent `assignee_id` unassigns |

A `PATCH` with `due_on` present and empty **clears** the date. The alternative — treating empty as
"leave it" — makes a deadline something that can be set but never removed, and a board that
accumulates dates nobody agreed to has an overdue figure made of noise.

An unrecognised enum value is a 422 naming the field and listing what is allowed, never a silent
fall back to a default: a filter somebody mistyped and a filter nobody sent are different
situations, and treating the second as the first answers a narrowed question with the whole board.


---

# Approvals

## The distinction the whole module is built around

**Approval is not execution.**

This product does not write to the advertising platforms. Provider write operations are a later
phase, so approving a request to pause a campaign pauses nothing — a person goes and does it in the
platform's own interface afterwards. Every other tool in this space treats the approval as the end
of the story, which is how an agency can tell a client a campaign was stopped on Tuesday and find
out from the invoice that it ran all week.

This module cannot close that gap by acting. It can only refuse to hide it:

- `ApprovalKind::isEnactedByThisProduct()` returns **false** for every kind, and says why per kind
  rather than "not supported". A unit test asserts it for all six, so when a write adapter lands,
  that test is the thing somebody has to change deliberately.
- An approved request moves to **`awaiting_hand`**, not to done.
- Only a person recording *"I have made this change"* moves it to `confirmed`, with their name and
  the time. The audit entry carries `verified_by_product: false`, because an audit of live spend
  changes has to be able to tell somebody's word from a reading taken off a platform — and this
  product only has the former.
- The queue publishes **how much approved work nobody has carried out**, and how old the oldest is.
  That figure is not a process metric. It is a list of things somebody currently believes that may
  not be true.

The interface says it before the buttons, not after the decision. A decider who presses Approve
believing the budget is now raised will tell a client so.

## A decision names the proposal it read

`ProposalFingerprint` is a SHA-256 over a **canonical** form of the proposal — keys sorted at every
depth, list order preserved, because the order of a list is part of what it says. A decision must
send back the fingerprint its screen displayed.

If the proposal was amended in between, the decision is refused with **409 Conflict** and the
proposal as it now stands. It is not a 422: the request was well formed and was correct when its
sender read the screen, and answering with a validation code would send a client down the "fix your
input" path for something only a re-read resolves.

Without this the race is silent, and the record claims somebody approved a change they never saw —
which looks exactly like a correct approval afterwards. It is the same defect a creative approval
avoids by attaching to a version rather than to a request.

The hash is canonical so that a key reordering does not trip the rule. A check that fired on
serialisation order would teach people to click past it.

## Nothing settled is ever re-decided

Approved, declined and withdrawn are **terminal**. A settled request cannot be amended or decided
again, because a trail in which one request was both declined and approved cannot say which
decision the client was told about.

A changed proposal after a decision is a **new request**, which may name the one it replaces
through `supersedes_id`. Both decisions stay on the record. It is the rule an approved budget
already follows — the figure a client agreed is archived rather than quietly revised.

Amendment is allowed **while pending**, which is what makes the fingerprint check real rather than
theoretical: anyone holding the old proposal is refused and sent back to read the new one.

## The kinds

| Kind | Moves money | Decided with, in addition to `approvals.decide` |
| ---- | ----------- | ---------------------------------------------- |
| Launch a campaign | yes | `budgets.approve` |
| Pause a campaign | yes | — |
| Change spend | yes | `budgets.approve` |
| Change targeting | yes | — |
| Publish creative | no | `creative.approve` |
| Change tracking | no | `tracking.manage` |

Targeting counts as moving money. It does not change how much is spent, but it changes who it is
spent on — the same conversation with the client, and the same thing they ask about afterwards.

The second permission exists so that the person who signs off what a client spends is the person
who signs off changing it. Without it an approvals administrator quietly becomes a budget approver.

**Pausing needs no second permission.** Stopping spend is the safe direction, and requiring a budget
approver to halt a runaway campaign is how a runaway campaign keeps running over a weekend.

## Permissions

| Permission | Who has it |
| ---------- | ---------- |
| `approvals.view` | Owner, administrator, project manager, media buyer, creative |
| `approvals.request` | The same — asking, withdrawing, and recording that a change was made |
| `approvals.decide` | Owner, administrator, project manager |

A media buyer may ask and may not decide. A buyer who could approve their own change to a client's
spend removes the only check on it — the same reason they may plan a budget and not approve one.
They do record having made the change, because they are the person who goes and makes it.

A client viewer has none of these.

## What the schema enforces

Seven CHECK constraints, each for a rule with an obvious wrong version:

| Rule | Why |
| ---- | --- |
| `rationale` is non-empty | A change to a client's spend with no stated reason is not something anybody can approve. |
| A decline carries a reason | It is what the next proposal is built on. |
| A withdrawal carries its own | Otherwise it is a request that vanished, which is indistinguishable from one dropped to avoid a no. |
| `(state IN ('approved','declined')) = (decided_at IS NOT NULL AND decided_fingerprint IS NOT NULL)` | Both directions. A decision always says when, and always says which proposal it read. |
| `enactment = 'not_required' OR state = 'approved'` | No row can claim a declined change was carried out. |
| `enactment = 'confirmed'` requires `enacted_at` | A change carried out records when. |
| `enactment = 'abandoned'` requires a note | Not carrying out an approved change is a second decision, and the one a client asks about. |

Plus the same whole-or-absent subject rule tasks follow.

`enactment` accepts only `confirmed` and `abandoned` from a caller. `not_required` and
`awaiting_hand` are the module's own states, and letting a caller write them would be a way to clear
the outstanding queue without anybody doing anything.

## The rates, and when they are null

| Figure | Denominator | Null when |
| ------ | ----------- | --------- |
| Approval rate | Approved + declined | Nothing decided |
| Carried-out rate | Confirmed + abandoned | No enactment settled |

Withdrawals are excluded from the approval denominator: a request the asker took back was never
decided, so it says nothing about how decisions go.

Work still waiting is excluded from the carried-out denominator: it is not yet a failure to act.

The approval rate is published even when it is 100%, deliberately. An agency that approves
everything does not have an approvals process, it has a formality — and that is worth knowing about
your own organisation.

## Two queues, two clocks

A pending request is aged from **when it was asked**. An approved one awaiting a hand is aged from
**when it was approved** — because the question then is not how long the decision took but how long
the change has gone unmade, and measuring from the request would flatter a slow decision followed by
prompt action.

Both lists are sorted **oldest first**. A queue sorted newest-first hides exactly the row somebody
needs to see.

`ApprovalLoad::PATIENCE_DAYS` is three: a judgement about an agency's rhythm, not a measurement,
which is why it is a named constant with the reasoning beside it. A request to change live spend that
has sat for longer than a long weekend is either not important or not being read.

## The charts

**Where the waiting is** — the two queues as grouped bars on one ordered age axis. Colour encodes
which queue (identity, categorical hues in fixed order); position encodes how long (the ordered
scale). **Grouped, not stacked**: a request nobody has answered plus a change nobody has made is a
total that describes neither problem, and the two have different owners. Drawing them together is the
point — an agency looking at one queue sees a process that works.

**What is waiting for a decision** — pending requests by kind, one series and one colour, no legend.
The kinds are named on the axis, so colour would re-encode a fact already stated in words. Which
kinds move money is a badge in the table rather than a second hue, so colour keeps doing one job.
Kinds with nothing waiting are left out of the chart and kept in the table: a bar of zero reads as a
measurement of nothing, while a zero in a table is information.

## The approvals API

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/approvals/vocabulary` | Kinds (with `is_enacted_here` and why not), states, enactments, age buckets |
| `GET` | `/api/approvals/queue` | `client_id`, `kind`, `spend_only`, `limit` |
| `POST` | `/api/approvals` | |
| `GET` | `/api/approvals/{approval}` | |
| `PATCH` | `/api/approvals/{approval}` | Amend a pending proposal; moves the fingerprint |
| `POST` | `/api/approvals/{approval}/decision` | Requires `fingerprint`; 409 on a mismatch |
| `POST` | `/api/approvals/{approval}/withdrawal` | Requester's act, with a reason |
| `POST` | `/api/approvals/{approval}/enactment` | `confirmed` or `abandoned` only |

## What the proposal is

A JSONB object, shown to a decider as a table, **never summarised**. This product deliberately does
not model a platform's targeting schema in order to describe a change to it: a half-modelled schema
quietly drops the field that mattered, and the approval would then be of something nobody stated. A
nested value is rendered as JSON rather than coerced to a string, because `[object Object]` is a
field a decider cannot read — on exactly the proposals that needed the most care.

An empty proposal is refused. "Approve this" with nothing in it is a signature on a blank page.

---

## Not done in this slice

- **Client requests.** Intake from a client, triage, and the link to the work it produces.
- **Notifications and realtime.** Reverb is configured on the backend; nothing broadcasts yet and
  the frontend has no Echo client. Until it does, the board and the queue are refreshed by their
  fifteen-second staleness window, not pushed. An approval waiting for a decision is exactly the
  thing a notification is for, so this is the next slice.
- **Provider write operations**, which is what would make an approval enactable. Phase 24. Until
  then the engine is the gate those writes will pass through, and it says so on every request.
- **Raising a request from the interface.** The API accepts one and the demo seeds five; the surface
  reads and decides. A composer needs a per-kind form, and a generic key-value form would invite
  exactly the half-stated proposal the module refuses — so it waits until the kinds that matter have
  fields worth asking for.
- **An approval attached to a budget record.** A budget has its own approval, with its own rule that
  an agreed figure cannot be revised. Pointing this engine at the same state would be a second path
  to it, which is the one thing worse than two approval trails.
- **Comments on a task.** Creative requests have them with an explicit audience. Tasks do not yet,
  and adding them without the same audience rule would be the leak that module refuses.
- **Checklists and subtasks**, and **time tracking**. Neither has a question in this product that
  the state machine and `working_days` do not already answer.
- **Recurring tasks.** A repeating task is a schedule, and a schedule needs somewhere to say what
  happens when an occurrence is missed. Left until there is a reason to answer that.
