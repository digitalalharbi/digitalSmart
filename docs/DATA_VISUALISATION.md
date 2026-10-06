# Data visualisation

Charts are the part of this product most able to mislead, and the part least likely to be
reviewed for it. A wrong number gets queried. A chart that *implies* something wrong gets
believed, screenshotted, and put in front of a client.

So the rules here are mechanical, and the measurable ones are tests
(`frontend/src/shared/ui/chart/palette.gates.test.ts`, `marks.test.ts`). The point of a
test is that it fails when somebody who has never read this document changes a palette.

## The procedure

Colour comes last, and it is computed rather than chosen.

1. **Decide whether it is a chart at all.** A single current figure is a stat tile, not a
   one-bar bar chart. Two headline numbers are two tiles. More than about seven classes
   that all carry meaning is a table.
2. **Pick the form from the data's job** — magnitude, identity, order, polarity, change
   over time. See the table below.
3. **Pick the colour by the job the colour does** — identity, order, polarity or status.
   Four jobs, four mechanisms, no overlap.
4. **Apply the mark specs.** They are in one module, `shared/ui/chart/marks.ts`, so they
   are the same on every page.
5. **Add the table twin and the source badge.** `ChartFrame` does both, which is why
   every chart goes in one.

### The job → the form

| What the reader has to do | Form | Colour job |
| --- | --- | --- |
| Compare magnitudes | bars; columns for short labels | one hue (slot 1) |
| Read a trend | line; area for a single series | one hue |
| Tell distinct series apart | multi-line, grouped or stacked bars | categorical |
| Follow an ordered scale (funnel, tiers, bands) | bars, in stage order | **ordinal ramp** |
| See one series against context | emphasis: one in colour, the rest grey | one hue + grey |
| See which side of a baseline | diverging bars | diverging poles |
| See part of a whole, at a glance | stacked bar, or a donut of ≤ 6 slices | categorical |

**Emphasis is the most underused form in this product.** When the story is "this one
campaign moved", eight categorical hues bury it. One hue plus grey is usually the honest
answer to "make this chart clearer".

## Colour

### Categorical — identity

Eight slots, `--ds-chart-1` … `--ds-chart-8`, in a **fixed order that must not be
changed**. The order is not cosmetic: it is the mechanism that keeps neighbouring series
apart for a colour-blind reader, and it was derived by searching lightness and chroma
steps *and* orderings against the published thresholds, in both themes at once, with slot
1 held in the brand family so a single-series chart wears the product's colour.

Measured on the surfaces charts are actually drawn on — a white card, and `#13151a` in
dark mode:

| | light | dark |
| --- | --- | --- |
| worst adjacent pair, protanopia/deuteranopia simulated | ΔE 14.0 | ΔE 11.6 |
| worst adjacent pair, ordinary vision | ΔE 20.7 | ΔE 18.0 |
| contrast against the surface | all eight ≥ 3:1 | all eight ≥ 3:1 |

ΔE is Euclidean distance in OKLab ×100. The gates are 8 for the simulated pairs and 15
for ordinary vision; deficiency is simulated with Machado, Oliveira & Fernandes (2009) at
full severity, which is part of the standard rather than an implementation detail — a
different simulation model would need the thresholds recalibrated with it.

Two consequences worth knowing before designing a chart:

- **Adjacent pairs are what is gated for bars, stacks and lines,** because slots are
  handed out in sequence and never skipped, so slot 3 is only ever beside 2 and 4.
- **Scatter, bubble and small multiples cap at four series.** There is no assignment
  order in a scatter — any two points can land side by side — so every pair has to clear
  the gate, and the measured cap is four. A fifth series folds into one "other" row or
  the chart facets. **A ninth hue is never generated**: it looks plausible and is
  indistinguishable from an existing slot under colour blindness. `seriesColour` repeats
  the last slot past the cap on purpose, so the mistake is visible rather than silent.

Platform brand colours are deliberately not series colours: four campaigns on Meta would
otherwise be four shades of one blue. They exist only as identifier chips.

### Ordinal — order

One hue, six steps, `--ds-chart-step-1` … `-6`. For scales where swapping two categories
would change the meaning: funnel stages, size tiers, age bands. Monotone in lightness,
every adjacent gap ≥ 0.06 in OKLCH L, and the step nearest the surface still clears 2:1 —
a stage with the fewest leads is still a visible bar rather than nothing.

Use `stepColour(palette, index, total)`, which spreads across the whole ramp rather than
taking the first *n* steps: three stages in three barely-different blues say nothing about
their order.

**Never put an ordinal ramp on nominal categories.** Shading each campaign's bar by its
own value spends the identity channel restating what bar length already shows, and it
fails the categorical gates by construction.

### Diverging — polarity

`--ds-chart-pole-low` ↔ `--ds-chart-pole-high`, indigo and brick, with a **neutral grey
midpoint**. The midpoint has to read as "nothing": a hue there says the middle of the
scale is itself a state, which is the one thing a diverging scale must not say. Two cool
poles fail for the same reason, which is why this is indigo ↔ brick and not indigo ↔ teal.

### Status — state

`--ds-success`, `--ds-warning`, `--ds-danger`, `--ds-info`. Reserved meaning, never
"series 9", and always shipped with an icon and a label — which is what lets a status
colour carry meaning to somebody who cannot see its hue. When a series genuinely *means*
good or bad (an error rate, a pass/fail) it wears status tokens; when it is merely
identity it wears categorical. Never both in one chart.

## Marks

All in `shared/ui/chart/marks.ts`, all fixed:

| Mark | Spec |
| --- | --- |
| bar / column | ≤ 24px thick, 4px rounded at the data end, square at the baseline |
| line | 2px, round join and cap, **never smoothed** |
| marker | ≥ 8px, with a 2px ring in the surface colour |
| area | the series hue at 10% opacity — a wash, never a block |
| gridlines & axes | one step off the surface, **solid 1px**, recessive |

Two of these are honesty rules rather than taste:

- **A line is never smoothed.** A spline passes through values nobody measured and
  overshoots the real extremes, so a reader takes a peak off the chart that is not in the
  data.
- **A gap is never drawn through.** `connectNulls` is off everywhere. A bridged gap says
  delivery continued at some level on a day it had stopped.

### Two spacers, instead of borders

A **2px gap in the surface colour** separates touching marks — every segment of a stack,
every adjacent bar — and a **2px ring in the surface colour** sits on markers so they
stay legible where they overlap. A border drawn around a mark adds ink that is not data;
`palette.surface` is the card's colour for exactly this reason, not the page's.

### Labels

Selectively. A number beside every point is chaos and goes unread; the endpoint, the
extreme, or the one series the story is about. A label only goes *inside* a mark when it
fits with padding — otherwise outside the bar end, or in the tooltip and the table.
Nothing is ever clipped.

**Text never wears the data colour.** Marks carry the series colour; labels, values and
legends use the text tokens. A light categorical hue is illegible as text, and identity
arrives from the coloured mark beside the label rather than from the label itself.

## Never two scales on one plot

The rule this codebase gets asked about most, and the one with a test
(`shared/metrics/charts.test.ts`).

Where a second y-axis lines up with the first is an arbitrary choice. Whatever that choice
is, the two lines appear to cross and diverge at moments that mean nothing — so the chart
*invents* a correlation. "Conversions track spend" is exactly the sort of claim a budget
gets approved on, and a dual-axis chart will support it whether or not it is true.

Three honest alternatives, in order of preference here:

1. **Two plots sharing one time axis,** with one linked crosshair. What the analytics
   trend chart does: each series keeps its own units and its own baseline, and the
   comparison a reader wants is still a single glance.
2. **Index both to 100 at their common starting point,** on one axis, with the axis named
   so a reader does not mistake it for a percentage. What a dashboard widget does, because
   it is too small for two plots.
3. **Two charts.**

## Every chart has a table

`ChartFrame` renders a chart/table switch, in the open rather than hidden behind an
accessibility setting, because plenty of readers prefer the table.

It is not a courtesy. A chart encodes with position and colour, and some readers get
neither: a colour-blind reader on a five-series chart, somebody on a screen reader,
somebody printing in grey. A tooltip helps none of them — it needs a pointer, and a canvas
tells a screen reader nothing at all. The table is also the **relief** the contrast rule
depends on: where a mark sits below 3:1, the published rule allows it only if the values
are readable another way.

## Every chart says where its figures came from

`ChartFrame` takes a `source`, and it is not decorative. A chart drawn from sandbox
fixtures and a chart drawn from a client's live spend look identical, and only one of them
may be acted on. The vocabulary is the same one every figure in this product carries —
live, synced, stale, partial, sandbox, manual, estimated — and `caveats` carries what the
chart is silent about, as data rather than as documentation, because the person reading
the page is the one who would otherwise draw the wrong conclusion.

## Empty is a sentence

Axes with no marks read as zero. "Nothing has been recorded for this period" and "the
result was zero" mean opposite things to somebody deciding a budget, so `ChartFrame`
renders a sentence instead of an empty grid.

For the same reason a refresh **dims the previous render** rather than flashing a
skeleton: the figures on screen are still the best answer available, and a layout that
jumps every minute is a layout nobody can read.

## Right to left

Charts are mirrored, not merely translated: the value axis moves to the other side, the
legend follows the reading direction, bar corner radii flip, and the time axis mirrors.

**The time axis mirrors by inverting the axis, never by reversing the data.** Reversed
data has to be reversed identically in every series and in every tooltip that looks a row
up by its index, and the first place that forgets plots one campaign's number against
another's name. Inverting leaves every index meaning what it says.

ECharts draws its own text and inherits nothing from CSS, so the Arabic font stack is
handed to it explicitly — otherwise axis labels fall back to a Latin-first face.

## Re-deriving the palette

If a palette has to change, re-derive it rather than nudging a hex:

1. Choose the hue families and which slot the brand holds.
2. For each hue, search lightness and chroma steps inside the mode's band for the set that
   maximises the worst adjacent pair in **both** themes at once — one hue set and one order
   serve both, only the steps differ.
3. Once the gates clear with margin, switch the objective to what a reader actually
   experiences: slots that clear 3:1 on their own, and an even chroma across the set. A
   palette optimised for separation alone is muddy; one optimised for chroma alone is neon.
4. Write the result into `frontend/src/styles/tokens.css` and run
   `npx vitest run src/shared/ui/chart/palette.gates.test.ts`.

A found constraint worth recording: **the dark interface accent (`--ds-brand-400`, L 0.706)
sits above the lightness band a mark needs on a graphite surface.** That is why chart slot
1 and `--ds-accent` are not the same value in dark mode, and why "just use the accent"
does not work there.

## Engine

**Apache ECharts**, for three reasons specific to this product: it renders to canvas, so a
90-day ad-level scatter stays interactive where SVG libraries stall; it supports a true
right-to-left layout rather than mirrored text; and it treats `null` as a gap rather than
zero, which every chart here depends on.

Only the chart types in use are registered (`shared/ui/chart/Chart.tsx`). The full bundle
is roughly a megabyte, so adding a type is a deliberate edit, and the engine sits in a
lazily loaded chunk.

The tokens are OKLCH, which ECharts cannot use — it does not merely pass colours through,
it parses them to lift a hue on hover and to build a gradient, with a parser that predates
OKLCH. So `shared/ui/chart/colour.ts` resolves every token to sRGB hex at render time.
That is also the module the palette gates measure with, which means the numbers in this
document are computed from the same code that draws the charts.
