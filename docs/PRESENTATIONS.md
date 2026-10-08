# Presentations

A deck is a report, laid out for a room.

Phase 24 delivers the **theme/token abstraction**, four decks composed from figures this product already holds, an
**editable PowerPoint** writer, and a PDF that is the preview printed from the browser.

## The theme comes first, and why

The brief is explicit: *do not hard-code a final presentation identity before the reference deck is provided; build
the theme/token abstraction first.*

Smart Code's own presentation identity has not been provided to this repository. No reference deck, no brand
guidelines, no palette. A product that guessed at one would have the guess baked into every renderer, every slide
layout and every exported file — and replacing it later would be a rewrite rather than a configuration change.

So `App\Domain\Presentation\PresentationTheme` is a value object of tokens: page size in points, colours **named by
role** (a token called "blue" cannot be rebranded), a type scale, and the series ramp. Every renderer consumes it,
and **nothing downstream knows a hex value** — a test holds that by checking each colour is a role.

The default is `provisional()`, derived from the product's own interface tokens, and `isProvisional` is **true**. It
is said on the deck, on the page and in the file's own properties, because a placeholder presented as an agency's
brand is worse than an obvious placeholder.

**Two fonts, not one.** Arabic set in a Latin face is Arabic that has been let down by whoever made the deck. The
theme names both and the renderer picks by script, per string rather than per deck — a slide legitimately carries
an Arabic headline over a Latin platform name.

## A deck is a report

`ComposeDeck` assembles a report definition for the deck's own window, hands it to **`RenderReport`** — the same
aggregation the reporting surface and the client portal read — and lays the rendered sections out as slides.

That is the whole design. A presentation generator with its own analytics would be a second set of numbers to
reconcile, and the reconciliation always happens in front of a client.

The report is **never saved**: nothing in the reporting module gains a row because somebody generated a deck.

| Deck | Period | Compares | Composed from |
| ---- | ------ | -------- | ------------- |
| Weekly report | Last 7 days | the 7 before | a client's delivery |
| Monthly report | Last calendar month | the month before | a client's delivery |
| Closing report | Last 90 days | **nothing** | a client's delivery |
| Media plan | the budget's own | — | a budget and its allocations |

**The period is part of what a deck means**, not a parameter. A "weekly report" over ninety days is a different
document wearing the same name, and a reader who received both in one month could not tell them apart from the
title.

**The closing report does not compare.** There is nothing after an engagement to compare it with, and inviting the
comparison would have a reader treat the end of a flight as a trend.

## The caveats are a slide

On screen, a comparability refusal is a sentence above the figures. In a room, read out loud by somebody who may
not have built the deck, it has to be a **slide** — a footnote in six-point type is a footnote the projector crops.

`SlideKind::Caveat` is assembled from the report's own refusals rather than written for the occasion: the
comparability reason, each section's caveats, whether the period is still running, and the weakest source behind
anything in the deck. It sits immediately after the figures it qualifies, not at the end, because a caveat on the
last slide is a caveat read after the conversation has moved on.

It is **omitted entirely when there is nothing to qualify**. A slide saying "no caveats" is a slide that teaches a
reader to skip the caveat slide.

## Every figure is formatted once

A deck is rendered twice — into a PPTX file and into a page — so `Slide` carries **strings**, not numbers.
`MetricText` formats each figure once in the application layer: money from minor units into major with its
currency, a ratio as a percentage, a count with separators. Neither renderer does arithmetic and neither formats
anything.

Formatted independently in each, a figure would eventually differ: a different rounding, a different thousands
separator, a currency still in halalas on one of them. The series keep raw values too, because a chart needs
numbers — and no renderer may label a point with anything but the string.

`MetricText` is deliberately **not** the same formatter as `ReportCsv`'s. A CSV is read by a spreadsheet: no
separators, no symbols, a plain decimal point, because anything else arrives as text and stops being a number.
This is read by a person in a room. Two formatters with two stated purposes, rather than one that serves neither.

**Western digits, always**, as everywhere else in the product: a client reconciling an agency's figure against a
platform dashboard is comparing two strings.

## The commentary slide is the assistant's

The prose on the last slide is `Analyse`'s own narrative, carrying the same evidence, the same confidence rules and
the same attribution — including, when a language model wrote it, **that a model wrote it**, on the slide.

A deck whose commentary was generated by other means would be a deck whose commentary nobody could check against
the rest of it.

## PPTX for editing, PDF from the browser

This is the split, and both halves are deliberate.

**PowerPoint is written server-side and is really editable.** Text runs, native tables, and native PPTX charts with
their own embedded data — a reader can click a bar and see the figure behind it, and the deck survives being
re-themed. An agency changes a title, drops a slide, adds one of their own; a deck they cannot open is a deck they
re-make by hand, and "editable" is the requirement.

**The PDF is the preview, printed.** There is no server-side PDF endpoint, and that is a decision rather than a
gap. The library's own PDF path goes through HTML and DomPDF, which would mean:

- Arabic shaping that is not the browser's,
- no access to the self-hosted IBM Plex Sans Arabic the rest of the product sets Arabic in,
- and a third chart renderer.

Three ways to disagree with what somebody previewed. Printing the page instead means the PDF **is** the preview:
same shaping, same faces, same charts, nothing to keep in step. The print stylesheet lives in `index.css` and does
three things — landscape with no page margin, one slide per page with `break-inside: avoid`, and everything that is
not the deck hidden.

### A download that works everywhere

The endpoint reads the written file and returns it with an explicit `Content-Length`, rather than streaming it from
disk and deleting it afterwards. That was not the first implementation: `download()` with `deleteFileAfterSend()`
passed through the test kernel and returned **500** through a real server, because PHP's development server closes
a binary file response early and the proxy in front of it reports a reset connection. A deck is tens of kilobytes,
so holding one in memory costs nothing measurable — and a download that works only under some servers is a
download somebody will hit on the day they need it.

## The media plan

Composed from a budget somebody has already allocated. **It presents a plan the agency made, not one this product
invented** — the same reason the assistant's `Plan` mode is deferred.

Three decisions:

- **The plan is drawn whether or not the budget is approved.** The budgets module reports *pacing* only against an
  approved budget, because a variance from an unapproved figure means nothing — but the allocation *is* the plan,
  and a deck that showed nothing until approval would be useless for the one occasion it is most needed:
  presenting a plan for approval. The status is on the title slide, in words.
- **The unallocated remainder is its own figure**, not a subtraction left to the reader. It is either deliberate or
  an oversight, and a deck that hid it makes both look the same.
- **Spend is not netted against allocation silently.** The two sit side by side, because "80% spent" is a different
  fact from "80% spent with three weeks left", and the pacing module is the one that knows the difference.

## What is not composed

`DeferredDeck::CampaignProposal`, published with its reason rather than offered as a template that produces three
empty slides.

A proposal is a document an agency **writes**: the objective, the audience, the creative idea, what success would
look like. None of those is in this product at the point a deck is generated, and composing one from last month's
delivery would produce a proposal shaped entirely by what was easiest to measure. The slide shapes and the theme
are built and waiting; what is missing is the brief.

## What is not built

- **No stored themes.** The theme is the provisional default, and swapping it is a change in one place. Per-workspace
  themes — the mapping of a reference deck into tokens — need the reference deck first, which is the whole point of
  the abstraction.
- **No slide images or logos.** No asset store, and a deck with a placeholder logo is worse than one without.
- **No editing a deck in the product.** Slides are composed and exported; rearranging them happens in PowerPoint,
  which is what asking for an editable file means.
- **No scheduled delivery.** Same reason as reporting: a sender identity and a bounce story, and a deck that
  silently failed to send is worse than one nobody automated.
- **No per-slide visibility controls.** The deck's audience is client-safe throughout; which slides a particular
  client sees is the kind of per-recipient decision the client portal makes, and making it twice in two modules
  would be two answers.
