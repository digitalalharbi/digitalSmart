# Operations

The board an agency runs its week from: what is open, what is late, what nobody has dated, and
who is carrying it.

Phase 20 delivers **tasks**. Approvals, client requests, notifications and realtime are the rest
of the phase and are not in this slice; what is not built is listed at the end.

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

## Not done in this slice

- **The approvals engine.** Creative approvals exist and attach to a version (see
  `docs/CREATIVE.md`); a general engine for budget, campaign and release sign-off — including the
  gate Phase 24's provider writes will need — is the next slice.
- **Client requests.** Intake from a client, triage, and the link to the work it produces.
- **Notifications and realtime.** Reverb is configured on the backend; nothing broadcasts yet and
  the frontend has no Echo client. Until it does, the board is refreshed by its fifteen-second
  staleness window, not pushed.
- **Comments on a task.** Creative requests have them with an explicit audience. Tasks do not yet,
  and adding them without the same audience rule would be the leak that module refuses.
- **Checklists and subtasks**, and **time tracking**. Neither has a question in this product that
  the state machine and `working_days` do not already answer.
- **Recurring tasks.** A repeating task is a schedule, and a schedule needs somewhere to say what
  happens when an occurrence is missed. Left until there is a reason to answer that.
