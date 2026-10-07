# Creative operations

Briefs, versions, approvals, comments, and the checking of a file against what a platform asks for.

## The finding this phase rests on

**The advertising platforms do not publish their creative specifications in any form a program can read.**

That was established rather than assumed, and it shaped everything else here:

- Meta's Marketing API reference states that limits on text, image size and aspect ratio exist, and points at
  `facebook.com/business/ads-guide` — a marketing page rendered by JavaScript, whose own hub does not carry the
  numbers either; they sit behind further navigation.
- TikTok's business API portal is the same: a JavaScript shell with no specifications in the fetched document.
- The third-party guides that aggregate these numbers disagree with each other, and go stale without saying so.
- **Snapchat is the exception.** Its *developer* documentation states concrete numbers for several creative
  types, and those are recorded.

So this product does not claim to know what a platform requires. A specification here is **data a person
recorded, with the page they read it on and the date they read it**, and every verdict computed against one
carries that date. The consequence is that most placements have no specification, and the product says so.

That is useful in itself: the list of placements with no recorded specification is the list of placements an
agency's work goes out on unchecked.

## Five verdicts, and only one of them is "valid"

`SpecVerdict` exists because "valid" is the easiest lie a creative tool can tell.

| Verdict | What it means | Blocks a publish? |
| --- | --- | --- |
| `pass` | Measured, and within the recorded requirement | — |
| `fail` | Measured, and outside it | **Yes** |
| `unchecked` | The requirement exists and the property could not be measured | No |
| `no_spec` | Nobody has recorded a specification for this placement | No |
| `stale` | A specification was found, and is older than this product will vouch for | No |

**An asset's overall verdict is the weakest of its checks**, not an average and not a majority. Eleven passes
and one unchecked dimension is an unchecked asset: a reader deciding whether to publish needs the floor of what
was established, and a green tick over a set containing an unknown is the specific way a creative tool misleads
somebody until the platform rejects the upload.

**Only a measured failure blocks anything.** Blocking on `unchecked` or `no_spec` would make this product's own
gaps into the user's problem, and they would learn to click past the warning — which is how a real failure gets
clicked past too.

**Null means not measured, never zero.** A video whose duration could not be read has an unknown duration, and
a check that treated unknown as zero would report it comfortably inside a three-second minimum.

A requirement a kind of asset cannot have is **not** reported as unchecked: a duration check on an image is
inapplicable, and filling a review with warnings nobody can act on is how reviewers learn to ignore warnings.

## Specifications go stale, and say so

`SpecProvenance::FRESH_DAYS` is 182. Long enough not to nag; short enough that a platform's quiet change is
caught within a reporting cycle rather than after a campaign has run on the wrong sizes.

Past it, a **pass becomes `stale` and a failure stays a failure**. An asset inside a requirement nobody has
checked since last year is not evidence that today's platform agrees; an asset outside one is still worth
looking at.

## Bounds say what the platform said

`SpecBound` can express "at least", "at most", "one of" and "between", because that is what the platforms
publish — a minimum resolution with no maximum, a maximum file size, a list of accepted formats, a duration
range. Flattening those into one shape would mean inventing bounds nobody stated, and an invented maximum
rejects files the platform would have accepted.

An **empty bound is legal**: it means the page named a requirement without giving a number, which happens, and
the check then reports `unchecked` rather than guessing.

Aspect ratios are compared **as numbers with a tolerance**, because `1080×1919` is 9:16 for every practical
purpose and an exact comparison would reject nearly every real export — while a loose one would accept a square
as a vertical.

## An approval belongs to a version

The single rule the rest of the module is built around.

`creative_approvals` has a foreign key to `creative_versions` and there is deliberately **no approval column on
the request**. A client approved a file; the next file is unapproved until somebody says otherwise. Adding a
version onto approved, rejected or in-review work sends the request back to review, and the earlier approval
stays attached to the file it was about.

A tool that carried the approval forward would have a trail claiming the newest work was signed off by somebody
who never saw it — and the agency would find out when the client did.

`newest_is_approved` is computed from the versions rather than read off the state, so a request sitting in
"approved" with a newer unapproved version is visibly stale rather than quietly wrong.

**Decisions are appended, not replaced.** A reviewer who sends work back and later approves the same file has
made two decisions; both are kept and the one that stands is the last. Overwriting the first would lose the fact
that the file was ever rejected, which is half of what a trail is for.

**Sending work back must say why**, enforced in the use case, in the state machine and by a database CHECK.
Three layers for one rule, because the note is the only record of what the reviewer objected to and the next
version is a guess without it.

## The board refuses what it cannot do

`CreativeState` declares its legal moves as data, and a refusal carries the alternatives — "briefed work goes
into production first" is something a person fixes themselves; "invalid transition" is something they file a bug
about. The vocabulary is published at `GET /api/creative/vocabulary` so no interface carries its own copy and
offers a drop target the server refuses.

Two moves the board cannot make:

- **Approving.** An approval needs a version and a decider; a column drag has neither, and the trail would
  record that somebody approved something without saying who approved what. It goes through the version's own
  endpoint.
- **Cancelling without a reason.** "We decided not to make this" is a decision somebody will be asked about in
  three months.

## A comment states its audience

`CommentVisibility` has no default, in the DTO or in the column. An agency's internal note on a client's
creative — *"the client hated the last one, keep it safe"* — reaching that client is the kind of failure that
ends a relationship, and a default is exactly how it would happen. The client-facing filter lives in the query
rather than in the caller, so "the portal forgot it" is not a possible bug.

## Permissions

`creative.view`, `creative.manage`, `creative.approve` — and the third is separate for the same reason
`budgets.approve` is: producing the work and signing it off are different acts, and the separation is the only
thing that makes "approved" mean anything.

| Role | View | Manage | Approve |
| --- | --- | --- | --- |
| Admin | yes | yes | yes |
| Project manager | yes | yes | yes — they are in the conversation with the client |
| Media buyer | yes | — | — a buyer approving the work they asked for removes the only check on it |
| Creative | yes | yes | — does not approve their own work |
| Client viewer | — | — | — the board carries internal notes and unapproved work |

Visibility is still narrowed by project assignment: a creative who is not on the project genuinely cannot see
the work, and a request with no project is reached through its client's projects.

## What this phase does not do

- **No file upload or measurement.** The measurements arrive from the caller; reading them off an uploaded file
  needs object storage and an image/video probe, and that is the next slice. Everything downstream is already
  built for the honest case — a caller that supplies nothing gets `unchecked`, not a pass.
- **No specification editor.** The rows are seeded, because a specification describes a platform rather than a
  client and a workspace able to edit them would be editing every other workspace's checks. Maintaining them
  is a product task, and `GET /api/creative/specs` exists so an agency can see exactly what is covered.
- **No notifications.** "Your work was sent back" belongs with Phase 20, where notifications and realtime live.
- **No client portal.** `CommentVisibility` and the filter are built for it; the surface is Phase 22.

## API

`GET /api/creative/vocabulary` · `GET /api/creative/specs` · `GET /api/creative/board` ·
`POST /api/creative` · `GET /api/creative/{id}` · `POST /api/creative/{id}/state` ·
`POST /api/creative/{id}/versions` · `POST /api/creative/{id}/comments` ·
`POST /api/creative-versions/{id}/decision`

The decision route sits under the **version**, and that is deliberate: a path under the request would invite an
endpoint that approves "the request", which is the one thing an approval trail must not record.

Every write re-reads through the same query a `GET` uses, so a POST answers with exactly the shape a read does.
A write response missing the `spec` block that a read includes would work until somebody relied on the field.

## The surface

`frontend/src/features/creative` — the Creative Hub.

**The board is read-only.** Moving work is a decision with consequences — an approval needs a version and a
decider, a cancellation needs a reason — so the moves live beside the work they describe rather than behind a
drag. A Kanban where a column change can undo an approval is a Kanban that produces a trail nobody can rely on.

**A card says when it has no placement**, because that is why most specification checks honestly report having
checked nothing, and discovering it in the detail panel is discovering it too late.

**The spec panel shows both numbers for every check** — `360 · at least 360 px` rather than "width: pass" — and
the page the requirement was read on with its age. `no_spec` is presented as a gap in this product rather than a
problem with the file, because a reader who thinks the file failed will change a file that was fine.

**A stale approval is announced.** A request reading "approved" whose newest version nobody has decided on gets
a warning saying not to tell the client the latest cut is signed off. That is the one belief an approval trail
must never allow.

**The comment audience is chosen every time and never remembered.** A sticky default is how *"the client hated
the last one"* reaches the client.

`WorkloadChart` uses the **ordinal** ramp rather than categorical hues, because the columns are stages of one
process and the order belongs in the colour; cancelled work is left out, since it is not a stage work passes
through and counting it would make an abandoned brief look like a bottleneck at the end.

## Tests

- `backend/tests/Unit/Domain/CreativeTest.php` — the refusals: a move the board does not allow, a verdict that
  will not say valid, a stale specification downgrading a pass, a bound with no number.
- `backend/tests/Feature/Creative/CreativeTest.php` — the endpoints, including the board refusing to approve,
  a new version undoing an approval, two decisions on one file both kept, a comment with no stated audience
  refused, and each role's reach.
- `frontend/src/features/creative/CreativeHubPage.test.tsx` — the refusals as a reader meets them: the stale
  approval warning, an unmeasured property shown as not measured, `no_spec` framed as this product's gap, the
  reason demanded before sending work back, and the audience chosen explicitly.
- `frontend/e2e/creative.spec.ts` — the same in a browser, in both languages, plus a failing check with both its
  numbers and the roles that may not approve or may not see the hub at all.
