# Operations

The board an agency runs its week from: what is open, what is late, what nobody has dated, and
who is carrying it.

Phase 20 delivers **tasks**, the **approvals engine**, **notifications with realtime**, and
**client requests**. What is not built is listed at the end.

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

Approving a request to pause a campaign pauses nothing. Every other tool in this space treats the
approval as the end of the story, which is how an agency can tell a client a campaign was stopped on
Tuesday and find out from the invoice that it ran all week.

Since Phase 25 this product can carry three changes out itself — pause, resume, set a daily budget —
and that did not change the sentence above. Carrying one out is a **second act**, by somebody holding
`provider_writes.execute`, on a request that names the campaign, against a provider whose write was
read in its own documentation. Everything else still ends with a person going to the platform. See
`docs/PROVIDER_WRITES.md`.

So the module's job is the same: refuse to hide the gap.

- `ApprovalKind::isEnactedByThisProduct()` returns **false** for every kind, because a kind is the
  wrong place to ask. Whether a given request can be carried out here depends on the request — the
  provider, the ad account and the campaign id it names — and `ApprovalView::canBeCarriedOutHere()`
  is what an interface asks. The kind answers the only unconditional part: approving never makes the
  change by itself. A unit test asserts it for all six, so a change to that claim has to be made
  deliberately.
- An approved request moves to **`awaiting_hand`**, not to done.
- Only a person recording *"I have made this change"* moves it to `confirmed`, with their name and
  the time. The audit entry carries `verified_by_product: false`, because an audit of live spend
  changes has to be able to tell somebody's word from a reading taken off a platform. Where this
  product carried the change out itself, the note names the provider rather than a person and the
  state was read back off the platform — which is the one case where the record is more than a claim.
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

# Notifications

## The list is short on purpose

Four kinds, and the test of whether something belongs on the list is not "is this interesting" but
**"is there somebody with something to do about it"**:

| Kind | Who is told | Permission required |
| ---- | ----------- | ------------------- |
| A task was assigned to you | The new assignee | `tasks.view` |
| A change is waiting for your decision | Everyone who could actually decide it | `approvals.decide`, plus the kind's second permission |
| Your request was answered | The person who asked | `approvals.view` |
| An approved change was carried out | Whoever decided it | `approvals.view` |

A notification centre that announces every state change teaches people to ignore the bell, and then
the one notification that mattered — an approval for a change to live spend, sitting unread — arrives
in a stream nobody reads.

The last kind closes the loop the approvals engine exists to keep open: the person who approved a
change to live spend is the person who should learn whether it actually happened.

## A notification is a disclosure

"Raise the Ramadan daily budget is waiting for your decision" names a client, a campaign and an
intention. So the rule is one sentence, and it is the same rule every read in this product follows:
**a person may be told about something only if they could have read it.**

`PermissionAwareRecipientResolver` resolves that in SQL, as three conditions. Leaving any one out
leaks something:

1. **Active membership of this workspace.** A suspended member keeps their row and loses their
   notifications — otherwise somebody whose access was revoked keeps receiving a running commentary
   on the client they were removed from.
2. **The permission the kind requires**, plus a second where the kind names one. A spend change
   reaches only the people who hold `budgets.approve`: telling five people when three of them cannot
   approve it is how a bell comes to be ignored, and it invites somebody to a decision the server
   refuses.
3. **Visibility of what it is about.** `projects.view_all` sees everything; everybody else is
   narrowed to their assigned projects, with the same "a record with no project is reached through
   its client" rule the boards use.

And **nobody is ever told what they just did.** `Announce` drops the actor before anything is
written. Unassigning tells nobody: there is no new owner, and notifying the person it was taken from
would be a complaint rather than a task.

## The boards are live, and the nudge carries nothing

Three boards can be open on one screen while somebody else moves something on another: tasks, approvals and the
client-request queue. Each has a channel, and the design is the notification channel's, applied to a different
question.

**The payload is a nudge, not a row.** `BoardChanged` carries the board's name and a timestamp — no row, no
title, no state — so the browser's only possible response is to read the board again over the authenticated
endpoint, where project visibility already decides which rows that person gets. A payload carrying the row would
have to answer that question a second time, and the second answer is the one that eventually differs.

**That is what makes a workspace-wide channel safe here**, where a notification needs one per person.
Everybody who may read a board wants to know it moved; the permission on the channel decides who those people
are; and the event itself discloses only that something happened in a workspace the subscriber already belongs
to.

| Channel | Authorised by |
|---|---|
| `workspace.{id}.board.tasks` | `tasks.view` |
| `workspace.{id}.board.approvals` | `approvals.view` |
| `workspace.{id}.board.client_requests` | `client_requests.submit` |

The last one is `submit` rather than `view` deliberately, so a **client's own queue updates on their screen too**
— it is the permission that let them raise a request, and the board they get back is already narrowed to their
own. A client viewer holds neither `tasks.view` nor `approvals.view`, so they cannot subscribe to the agency's
boards at all, and a board name nobody publishes is refused rather than treated as a board with no permission.

**The permission is re-checked on every subscription**, through the subscriber's role in that workspace rather
than through the session's resolved permissions: a member of two workspaces has two roles, and a socket
authorised once would keep nudging for as long as the tab stayed open.

**Signalling must never fail the action it describes.** `BroadcastBoardSignaller` catches and reports, exactly as
the audit recorder and `Announce` do: a task that moved and whose signal threw would leave the move recorded, the
board un-nudged, and the caller shown a failure for something that did happen. Nothing is dispatched when no
actor is bound, which is the ordinary state of a seeder or a console command.

And each board **says whether it is live**, from the subscription rather than from a configuration value. A page
that implied it was live while it was not is a page where somebody waits for an update that never comes.

## Files are the same rule, and a worse leak

A client request takes files, and every rule the messages beside them follow applies unchanged — in SQL, on the
same column, by the same decision. An internal *note* is a sentence; an internal *attachment* is a competitor's
rate card or a screenshot of an argument about the client.

**The type is the file's own.** Decided by `finfo`, from the magic bytes, and never from the extension or the
`Content-Type` header — both of which the uploader chose. Laravel's `mimes:` validation reads the header, so it
is deliberately not used here. The test that matters uploads an HTML document called `brief.png`: an extension
check and a header check both pass it, and only the bytes disagree.

**SVG is refused**, and it is the one omission worth explaining. An SVG is a document that can carry `<script>`,
and every stored-XSS in a file-upload feature is an SVG served inline. Nothing here needs vector art badly
enough, and "export it as a PNG" is an answer a client can act on.

**Nothing is served inline — ever, including images.** `Content-Disposition: attachment`, the stored sniffed
type, `X-Content-Type-Options: nosniff` from the security middleware, and `Content-Security-Policy:
default-src 'none'; sandbox` on the response itself. A PDF can carry JavaScript that a browser's own viewer
will run; as an attachment it is a file on somebody's disk rather than a document in this product's origin.

**Nothing of the uploader's reaches the filesystem.** The stored name is a generated identifier plus the
extension of the *sniffed* type. A path built from an uploaded filename is a path traversal waiting for somebody
to try `../`, and a test uploads `../../etc/passwd.png` to prove it goes nowhere.

**There is no virus scanner, and this says so rather than implying one.** No scanning service is attached to this
repository. The defences are the allow-list, the five-megabyte limit, a name this product generated, and a
download that is never a document in this origin — a file is treated throughout as somebody else's file. An
operator adding a scanner would put it between the controller and `AttachToClientRequest`, which is the one place
the bytes exist in memory.

**Every read goes through the API**, which is why `AttachmentStore` offers no public URL. A signed bucket link
would be a second place the audience rule has to hold, and the first time the two disagreed a client would be
reading an internal file. A download is also **audited**: who took a copy of somebody else's file, and when.

A missing file answers **404**, not 500. A record pointing at bytes that are gone is a real state — a restore
from a database backup without the disk behind it produces exactly that — and the honest answer is that the file
is not there rather than a server error inviting a retry.

## Four of the nine kinds are about a client request

Nine kinds, and four of them exist because a client request has two sides. Collapsing them would mean one
notification whose recipient depended on a field, and a disclosure decided by a field is a disclosure somebody
will eventually get wrong.

| Kind | Who is told | Something to do? |
| ---- | ----------- | ---------------- |
| `client_request_submitted` | The agency's readers of the queue | yes — a client is waiting |
| `client_request_replied` | The same, when a client says something more | yes — a client is waiting **again**, and this is the gap `submitted` left |
| `client_request_moved` | Whoever raised it, usually the client | no — "we accepted it" is news |
| `client_request_needs_information` | The same person, for the one move that puts the work back on them | **yes** |
| `client_request_answered` | The same person, only for a **client-visible** message from the agency | no — reading is not a to-do |

Three of them are permissioned on `client_requests.submit` rather than `.view`, which is the permission a client
viewer actually holds: `.view` is the agency's own view of the queue, and a client deliberately does not have it.

Two details carry the module's position on leaks:

- **An internal note announces nothing.** Not a quieter notification — none. A bell saying "the agency replied"
  for a note the client cannot read would be the cruellest bug here.
- **The three client-facing drafts name one recipient and carry no project scope.** It is the only place in this
  product that omits the scope, and the reason is that the audience is the author of the request: "could they
  have read this" is answered by their having written it. A client viewer who had been taken off their own
  project would otherwise be silently dropped from the one message they are owed.
- **The notification carries the state, not the message.** The message is on the request, where the audience rule
  decides who may read it, and copying it into a bell would be a second place that rule has to hold.

The client's own portal has **no bell**, and should not — it is not the agency's product. It has a quiet
"since you last looked" panel instead: unread only, no history, absent entirely when there is nothing to say. A
notification with nowhere to be read is a notification that was not sent.

## Announcing must never fail the action it describes

`Announce` catches and reports, like the audit recorder, for the same reason: an approval that was
recorded but whose notification threw would leave a decision made, a requester never told, and a
caller shown a failure for something that did happen.

An empty recipient list is a legitimate outcome, not an error. A one-person workspace assigning
themselves a task has nobody to tell, and a workspace where nobody can decide a spend change has a
permissions problem rather than a notification problem.

## Waiting, not unread

| Figure | Means |
| ------ | ----- |
| `unread` | How many notifications have not been opened |
| `waiting` | Unread **and** of a kind the reader is expected to act on |

`waiting` is what the bell carries and what the page leads with. "Twelve unread" measures how long
somebody has been away; "two waiting on you" is a to-do list. Being told an answer is unread without
being waiting, and that distinction is what keeps the number trustworthy.

The bell shows **no badge** when nothing is waiting, and a plain dot — not a count — for things that
are merely unread. A dot that is always there is a dot nobody reads, and a zero in a badge is a
number pretending to be information.

Every count is aggregated in SQL in one grouped query. A badge computed from whatever is in a
browser's cache disagrees with the list below it, and a person who has seen that disagreement stops
believing the badge. Marking one read returns the **whole feed** for the same reason: a client
decrementing the counts itself would be a second implementation of arithmetic that does not know
which kinds count as waiting.

## What the schema holds

One row per recipient — fan-out at write time, in one insert. A single row with a list of recipients
cannot record that one of them has read it, which is the only state that matters.

Deliberately **not** Laravel's own `notifications` table: that one is polymorphic over a notifiable,
carries untyped JSON and has no workspace column, and this module needs the opposite of all three.

**The sentence is stored as it was announced.** `title` and `body` are a record of what was said, not
a template rendered later. A notification whose wording changes when the underlying record changes is
not a record of anything — and a notification about something since deleted keeps its sentence and
loses only its link.

`read_at` is nullable and nothing else records read state. No boolean beside it to disagree with.
Marking something read twice is not an error and does not move the time it was first read.

## Reading them needs no permission

The one query in this product with no permission check, and that is correct: every row is scoped to
the reader's own user id, and the permission that decided whether they could be told was checked when
the notification was written. A second gate would mean somebody who lost a permission could no longer
read a notification they had already been sent — the record of having been told would disappear,
which is worse than not having been told.

Marking read is scoped by workspace **and** user in the WHERE clause, so there is no path where a
guessed id marks somebody else's notification read. A request for somebody else's row answers **404
rather than 403**: "forbidden" would confirm the notification exists and belongs to someone else.

## Realtime

**One private channel per person per workspace**, and nothing broader. A workspace-wide channel would
be shorter and would mean every member's browser receiving every notification and filtering it
client-side — which is not filtering, it is delivery followed by politeness.

`ChannelAuthorizer` is the access control on the realtime layer: identity, plus active membership,
re-checked on **every** subscription. Somebody suspended between opening the page and the next
notification must stop receiving them, and a socket authorised once would otherwise keep delivering
for as long as the tab stayed open. `/broadcasting/auth` carries `web` and `auth` middleware, so an
unauthenticated subscription is a 401 rather than a channel rule quietly returning false.

### The payload is a signal, not the notification

The socket carries the id, the kind, whether it needs action, and when it arrived. **No title, no
body, no subject.** The browser treats the event as "go and read the feed", which it does over the
authenticated endpoint that already decides what a reader may see.

One extra request buys a property worth having: the contents of a notification never travel over a
socket. A payload is the one place in a codebase where a field added without thinking is delivered to
a browser whose permissions nobody re-checked, and this shape makes that impossible rather than
unlikely. It also keeps the shaping of a notification for a reader in exactly one place.

### Realtime is optional, and both sides must agree

With no key there is no WebSocket server to reach: `connectRealtime()` returns null, nothing
subscribes, the page says live updates are off, and notifications arrive on the next read — later,
never missing. Throwing would break a page over a feature it does not need; attempting a connection
anyway would fill every console with retrying socket errors until nobody reads the console.

**"Live" requires both sides**, and taking the server's word alone was a real defect caught by the
end-to-end suite: a checkout with `BROADCAST_CONNECTION=reverb` and no app key told every reader the
page was live while nothing was subscribed. Two fixes, both at the source:

- `isConfigured()` on the server requires a **key**, not just a connection name. A connection with no
  key is a configuration half-finished, not a feature.
- The page requires the server's answer **and** its own browser having a key to subscribe with.

When it is polling, the feed refetches every sixty seconds — long enough not to be chatty, short
enough that a bell is not an hour stale. A page being pushed to does not poll at all.

Echo and Pusher are **dynamic imports**: 73 kB that a build which never connects never pays for.

## The notifications API

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/notifications` | `unread`, `limit` |
| `GET` | `/api/notifications/vocabulary` | The kinds and the permission each requires |
| `POST` | `/api/notifications/{notification}/read` | Returns the whole feed |
| `POST` | `/api/notifications/read-all` | Returns the feed, and how many were cleared |

## A note on testing the channel

`Broadcast::channel()` registers on whichever broadcaster was default when `routes/channels.php` was
loaded, and neither the `null` nor the `log` driver consults the channel callbacks — both have an
empty `auth()`. A test that switched the connection and asserted a refusal would therefore pass
**without the rule existing at all**, because an unregistered channel and a denied one produce the
same 403.

`tests/Feature/Notifications/BroadcastChannelTest.php` re-requires the channels file against the new
driver, and the first test in it — the one expecting a subscription to *succeed* — is what proves the
rule is wired. That trap is recorded in the file, because it is the kind of thing that looks like a
passing test suite.


---

# Client requests

## The one surface a client can write to

`client_requests.submit` is the only permission in this product that lets somebody outside the
agency write anything, and a client viewer holds it. That is the point of the module: **an intake
queue only agency staff can write to is just another task list.**

| Permission | Who holds it |
| ---------- | ------------ |
| `client_requests.submit` | Owner, administrator, project manager, media buyer — **and a client viewer** |
| `client_requests.view` | The same, **except** a client viewer |
| `client_requests.triage` | Owner, administrator, project manager |

The absence of `client_requests.view` is **load-bearing**. A reader without it is treated as
client-facing, and internal messages are excluded in the WHERE clause rather than filtered after
loading — a row that was selected has been hydrated into a DTO and is one careless refactor from a
response.

A creative holds none of these. Their work arrives as a brief in the Creative Hub and as tasks on
the board; the intake queue carries every client's commercial asks and the agency's reasoning about
them, which is a wider view than the role works from.

**One surface serves both sides.** The server decides what each may see and tells the page which
side it is showing through `is_agency_view`. A separate client API would be a second place for the
visibility rules to drift, and the one that drifted would be the one a client reads.

## What is withheld from a client

| Withheld | Why |
| -------- | --- |
| Internal messages | Excluded in SQL. An agency's note about a client reaching that client ends a relationship. |
| The tasks raised to answer them | The agency's plan of work — its assignees, its estimates, its wording — is the same leak an internal note is. |
| `owner_name` | Who inside an agency is handling something is the agency's business, and it is the field a client would chase by name. |
| `submitted_by_name` | A client knows who they are; this would only tell them which of their own words somebody re-typed. |
| The queue's figures and charts | They are about the agency's performance across every client. |

`promised_by_name` is deliberately **not** withheld. A promise was made *to* the client, so who made
it is part of the promise rather than an internal detail.

## Waiting on us, waiting on them

The figure this module exists for is a **pair**:

- `on_the_agency` — submitted, accepted, in progress.
- `on_the_client` — waiting on information.

A single "open" count lets a conversation stalled on the client hide behind work the agency has not
started, and **only one of those is the agency's to fix**. `NeedsInformation` exists as a state for
exactly this reason, and reaching it requires saying *what* is needed — "we need something from you"
is a message nobody can answer, and it is the state most likely to sit for weeks.

## A promise has a person on it

`(promised_at IS NULL) = (promised_by IS NULL)`, as a CHECK. A date an agency committed to is a date
*somebody* committed to; one that appeared with nobody behind it is the date a client quotes in a
meeting and nobody can account for.

`PromiseClientRequest` is its own use case with its own audit action, because a promised date is the
thing a client quotes back. The entry records **both sides** of a change — an entry with only the new
date cannot answer "it was the eighth last week" — and withdrawing a promise writes its own entry
rather than leaving silence.

`wanted_by` is separate and is labelled a **wish**: what the client asked for, not what the agency
agreed.

The queue publishes `unpromised` — accepted work with no agreed date — because *"we are on it"* is
the whole of what those clients have been told, and a queue with no promises can be neither late nor
on time.

## The client's urgency is not the agency's priority

`StatedUrgency` is its own enum, not `TaskPriority`, and the distinction is the whole of it: this is
a **claim the client made**, and the agency's priority is a judgement it makes afterwards. One field
for both would let a client set an agency's priorities by typing, and a board where every card is
urgent is a board nobody triages.

So nothing in this product orders anything by it. It is labelled *"Client said: …"* on every card,
the chart of it says in its caveat that it is not a priority, the composer's hint tells the client it
reorders nobody's work, and a task raised from a request takes the agency's own priority — defaulted
to normal.

## Raising work links, it does not convert

`RaiseTaskForRequest` creates a task whose subject is the request. **The request stays a request**,
with its own state, its own promised date and the half of the conversation a client can see. An
intake queue whose rows vanish into a task board is one where nobody can tell a client what happened
to what they asked for.

Two refusals:

- **Nothing is raised for a question.** A question needs an answer; a board filled with cards whose
  completion is a sentence in a reply is a board nobody trusts. The panel says so rather than hiding
  the control.
- **Nothing is raised against a settled request.** Work to answer something already declined or
  delivered is a mistake or a sign the request should be reopened — and reopening is a decision with
  its own record.

## The messages

| Rule | Where it is enforced |
| ---- | -------------------- |
| No default audience | The controller refuses an absent one; the interface disables the send button until one is chosen, and restates the choice *on* the button |
| A client's own message is always client-visible | Forced by the use case, and by a CHECK: `is_from_client = false OR audience = 'client'` |
| Only a client-visible message from the agency counts as answering | `first_answered_at`, stamped once. Writing to each other is not answering, and a figure that counted internal notes would report a responsive queue while the client heard nothing |

The audit entry records the **audience** and deliberately **not the message body**: the audit log is
not a second place a client's internal notes live, with none of this module's visibility rules on it.

## Delivered work can be reopened

*"This is not what I asked for"* is the most important message in this module. A terminal delivered
state would make the honest response a second request that loses the history of the first.

## What the schema enforces

| Rule | Why |
| ---- | --- |
| `declined` requires a reason | It is shown to the client. A request that vanished from an intake queue with nobody told why is the worst thing this module could do. |
| `needs_information` requires the question | "We need something from you" is unanswerable. |
| `(promised_at IS NULL) = (promised_by IS NULL)` | See above. |
| `audience` has no default | An internal note reaching a client ends a relationship. |
| `is_from_client = false OR audience = 'client'` | An interface bug must not hide a client's own words from them. |
| A client request always has a client | A request with no client is not a client request. |

## Patience

`IntakeLoad::PATIENCE_DAYS` is **two** — deliberately shorter than the approvals queue's three. An
internal request waiting over a long weekend is untidy; a client waiting that long has been ignored.
Past it, the page says so in those words: *"That is not a busy queue — it is somebody who has not
heard back."*

`waiting_for_answer_days` keeps running while a request is unanswered, measured to **now** rather
than returning null — otherwise the row that matters most would report nothing.

## The charts

**Where the open requests are sitting** — the open states in board order, on the ordinal ramp because
they are stages one conversation passes through. Who each stage waits on is in the **table**, not the
colour: colour already carries the order, and a second meaning in the same channel is how a reader
learns to distrust a palette.

**How urgent the clients said it was** — the stated-urgency scale, ordinal because it is ordered, with
a caveat that says in so many words that this is not how the work has been prioritised.

## The requests API

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/client-requests/vocabulary` | States with both waiting-on flags, kinds, the client's urgency scale, audiences |
| `GET` | `/api/client-requests/queue` | `client_id`, `kind`, `on_the_agency`, `per_column` |
| `POST` | `/api/client-requests` | |
| `GET` | `/api/client-requests/{request}` | |
| `POST` | `/api/client-requests/{request}/state` | |
| `POST` | `/api/client-requests/{request}/promise` | An absent date withdraws the promise |
| `POST` | `/api/client-requests/{request}/owner` | An absent owner removes it |
| `POST` | `/api/client-requests/{request}/messages` | |
| `POST` | `/api/client-requests/{request}/tasks` | Links a task; never converts the request |

## Query budgets

`tests/Feature/Operations/ClientRequestQueryBudgetTest.php` asserts a **query count**, not a
duration. It exists because a detail read once ran thirty-six queries and nothing noticed: the
spawned-task lookup went through the task board, which is six queries by design — one per column —
and the request was re-read after every write.

The fix was `TaskRepository::forSubject()`, one query for the tasks about one subject, plus an
immutable `ClientRequestView::withTasks()` so raising a task re-reads the task list rather than the
whole request. A detail read is now four queries over the module's own data regardless of how long
the conversation is.

A duration test fails on a loaded machine and passes on a fast one, which teaches people to re-run
it. A count is deterministic, and the budgets sit a little above what the code does — so a refactor
that adds one query is allowed and one that adds a join per message is not.

## Not done in this slice

- **Attachments.** Not in the model. A client sending a file is the obvious next thing a request
  needs, and it needs a storage story, a scan story and a retention story before it needs a column.
- **A client login of its own.** A client viewer is a workspace member today. Secure per-client links
  and the rest of the portal are Phase 22.
- **Notification of a client when their request moves.** The agency is told when a client asks;
  a client is not yet told when the agency answers, because telling them means e-mail, and e-mail
  means a transport, a template system and an unsubscribe story.
- **A flat, paginated list.** The queue is a board with a per-column cap and server-side filters,
  which is how it is read. A spreadsheet view belongs with Phase 21's reporting.

---

## Not done in the phase

- **Notifications on anything other than the four kinds above.** No digest, no grouping, no
  collapsing: each event is one notification. Grouping is where a notification centre starts deciding
  what somebody has already seen, and it is wrong about it.
- **Notification preferences.** Nobody can turn a kind off. Four kinds that each need action is not
  a stream worth filtering, and a preference screen would be an invitation to switch off the approval
  notifications — which are the ones that matter.
- **E-mail or push delivery.** In-app only. Sending mail needs a transport, a template system and an
  unsubscribe story, none of which this slice would do well.
- **Realtime on the boards themselves.** The bell is pushed to; the task board and the approval queue
  still refresh on their fifteen-second staleness window. Pushing a board means reconciling a
  server-aggregated figure with a local mutation, and doing that badly produces counts that drift.
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
