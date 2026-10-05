# Design system

An original system for digitalSmart. It takes **principles** from Apple's HIG — clarity,
hierarchy, harmony, restrained materials, calm motion, accessibility — and copies
nothing: no Apple assets, icons, layouts or trade dress. Analytical flexibility is
inspired by products like Adjust/Datascape; workspace operations by CampaignsHub.

## Personality

Intelligent, premium, precise, modern, calm, creative, global. The interface should feel
like an instrument, not an admin template: dense with information, quiet in decoration.

## Tokens

Defined in `frontend/src/styles/tokens.css`, bridged into Tailwind in
`frontend/src/index.css` via `@theme inline`. Components use semantic utilities
(`bg-surface`, `text-content-secondary`, `border-line`) and never raw ramp values, so
light and dark stay correct by construction.

| Group       | Tokens                                                              |
| ----------- | ------------------------------------------------------------------- |
| Brand       | `--ds-brand-50…900` — "smart indigo", the single accent              |
| Neutral     | `--ds-neutral-0…1000` — near-white → graphite, faint cool cast       |
| Surfaces    | `canvas`, `surface`, `surface-raised`, `surface-sunken`, `overlay`, `material` |
| Content     | `text-primary`, `text-secondary`, `text-tertiary`, `text-inverse`, `text-brand` |
| Lines       | `border-subtle`, `border`, `border-strong`, `focus-ring`             |
| Semantic    | `success`, `warning`, `danger`, `info` + matching surfaces           |
| Charts      | `--ds-chart-1…8`, `chart-grid`, `chart-axis`, `chart-threshold`      |
| Radii       | `xs .25rem` → `2xl 1.25rem`                                          |
| Shadows     | `xs`…`lg`, single light source, subtle                               |
| Motion      | `instant 90ms`, `fast 150ms`, `base 220ms`, `slow 300ms` + easings    |

Colour is authored in **OKLCH** so steps are perceptually even and dark mode is a
genuine re-mapping rather than an inversion.

### Theming

`data-theme="light" | "dark"` on `<html>`, applied before first paint to avoid a flash.
The preference (`light`/`dark`/`system`) persists per viewer.

## Platform colours — strict rule

The UI is **not** a rainbow of ad-platform brands. `--ds-platform-*` exists for small
identifier chips, icons and legend swatches only. Platform colours never become
surfaces, navigation, or default chart series. Chart series use the brand-anchored
categorical sequence, which stays legible for colour-vision deficiency and in both
themes.

## Typography

Defined in `frontend/src/styles/typography.css`. Two scripts, treated as equals
rather than one as a translation of the other.

| Script | Face | Why |
| ------ | ---- | --- |
| Latin  | **Inter Variable** | Tight optical tracking at display sizes, excellent tabular figures — which an analytics product leans on constantly. |
| Arabic | **IBM Plex Sans Arabic** | A genuine UI Arabic: real weights, proper glyph shaping, generous x-height. |
| Mono   | System stack | Identifiers, external IDs, raw values. |

Both are **self-hosted** via `@fontsource`, imported from `src/main.tsx`. An
earlier build named both families in CSS without ever loading them, so Arabic
silently fell back to a system face and read cramped — a failure invisible in
code review, which is why `e2e/typography.spec.ts` now asserts the loaded family
by name.

### The two scripts do not share metrics

Pretending they do is the usual cause of cramped Arabic. Three rules follow:

1. **Arabic gets more leading.** At Latin leading, one line's diacritic band
   collides with the descenders above it. Latin `1.55` body → Arabic `1.75`;
   Latin `1.4` headings → Arabic `1.6`.
2. **Arabic is never tracked.** Not negative, not positive. It is cursive:
   positive tracking severs the joins, negative collides the glyphs. Latin
   display text takes `-0.02em` happily. Components must not hard-code
   `tracking-*` on text; the base layer decides per script.
3. **The size scale steps up under RTL.** Arabic reads optically smaller at the
   same px, so `--ds-text-*` is redefined under `:root[dir='rtl']` and every
   Tailwind `text-*` utility follows automatically, because the sizes reference
   those variables live. Display sizes hold, since they are already large.

Measured result on the sign-in screen:

| Role | Latin | Arabic |
| ---- | ----- | ------ |
| body | 14px / 21.7px, tracking normal | 15px / 23.3px, tracking normal |
| h1   | 22px / 30.8px, tracking −0.44px | 23px / 36.8px, **tracking normal** |

### Scale

`2xs 11` · `xs 12` · `sm 13` · `base 14` · `md 16` · `lg 18` · `xl 22` ·
`2xl 28` · `3xl 36` · `4xl 48` (Latin px; RTL steps each up one notch).
Each size carries a paired line-height, so a `text-*` utility sets both and a
heading never inherits body leading.

Weights: 400 regular · 500 medium · 600 semibold · 700 bold. Only these four
Arabic weights are loaded, because only these four are used.

### Numbers

Every compared figure is `tabular-nums lining-nums` — `.ds-numeric`, applied to
all table cells automatically, and `.ds-figure` for KPI values.

**Arabic uses Western digits, deliberately.** A media buyer reconciles these
numbers against Meta, Google and TikTok dashboards, which render Western digits
in every locale; Arabic-Indic digits would force mental translation on exactly
the people who check figures most. Dates follow the active locale; numerals do
not. This is asserted by a test rather than left as a convention.

## Layout

Mobile-first. A 12-column grid (`ds-canvas-grid`) for analytical canvases; a persistent
rail above `lg`, a focus-trapped drawer below it. Generous but efficient spacing: this
is a dense product, and whitespace is used for grouping rather than for air.

## Motion

150–300ms, purposeful, `--ds-ease-standard` by default. Motion communicates origin and
hierarchy; it never decorates. `prefers-reduced-motion` reduces every animation and
transition to a near-instant step, globally.

## Bidirectionality

Arabic and English are both designed, not mirrored. Layout uses logical
properties (`ms-`/`me-`, `ps-`/`pe-`, `start`/`end`) so direction flips
structurally. The initial direction comes from the browser's own locale and is
applied before first paint, so there is no flash of Latin-metric Arabic.

Mixed runs are the normal case here — Arabic prose carrying Latin brand names,
platform names, currency codes and IDs. `--ds-font-mixed` lists Arabic first so
it claims the Arabic range while Inter picks up the Latin, and a sentence does
not visibly change typeface mid-word. Latin fragments inside Arabic (`code`,
`.ds-latin`) keep Inter's metrics rather than inheriting the stepped-up Arabic
size.

Icons are mirrored only where direction carries meaning (navigation chevrons,
progress), never where it does not (logos, clocks, external-link marks).

## Accessibility

Target **WCAG 2.2 AA**.

- Visible focus ring on every interactive element (`:focus-visible`, 2px, offset).
- Skip-to-content link as the first tab stop.
- Colour is never the only signal — status carries an icon and a label.
- Component tests query by role and accessible name, so a regression in semantics fails
  the build.

## Charts

**Engine: Apache ECharts**, chosen over the alternatives for three reasons that
matter for this product specifically: it renders to canvas, so a 90-day ad-level
scatter stays interactive where SVG-based libraries stall; it supports a true
right-to-left layout rather than only mirrored text; and it treats `null` as a gap
rather than zero, which is the behaviour every chart here depends on.

Only the chart types in use are registered (`shared/ui/chart/Chart.tsx`). The full
bundle is roughly a megabyte, and a dashboard that loads slowly is one people stop
opening — so adding a chart type is a deliberate edit, and the whole engine is in a
lazily loaded chunk rather than the initial bundle.

### Series colours

A categorical sequence of its own (`--ds-chart-1` … `--ds-chart-8`), deliberately
**not** the platform colours: a chart of four Meta campaigns would otherwise be
four shades of one blue. Ordered so the first three are distinguishable under the
common forms of colour blindness, and so adjacent pairs differ in lightness as well
as hue — a line read in greyscale or printed must still be legible. Dark mode lifts
each value's lightness while keeping its hue, so a chart does not change identity
when the theme does.

The tokens are OKLCH custom properties, which a canvas cannot resolve, so they are
read from the computed root style at render time. That is what lets a chart follow a
theme switch without a single colour being duplicated in TypeScript.

### Rules every chart follows

- **A gap is a gap.** Days with no data are `null` and the line is *broken*
  (`connectNulls: false`). A line drawn through a gap says delivery continued at
  some level when in fact it stopped — the most misleading thing a performance
  chart can do, and the thing a client would notice last.
- **It answers a question.** Each chart states what it is for: the efficiency
  scatter carries "which campaigns deserve more budget, and which are expensive at
  size?" above it, because a scatter plot is not self-explanatory. A chart without
  a question is decoration.
- **It is readable without the canvas.** `ariaLabel` is a required prop, not an
  optional one, and the figures are also rendered as text in a visually hidden
  caption. A canvas is invisible to a screen reader, so a chart without one is a
  hole in the page.
- **It respects motion preferences.** `prefers-reduced-motion` disables animation,
  read in JavaScript because a canvas is not styled by CSS.
- **It resizes with its container,** via `ResizeObserver` rather than a window
  listener: these live in a grid whose columns change with the sidebar, so a chart
  can need to resize when the window has not.
- **Gridlines stay faint.** They are the most over-weighted element in most
  dashboards. They exist so a reader can judge a value; the data should be the
  loudest thing on the canvas.

### Right-to-left

Charts are mirrored, not merely translated. In Arabic the category axis reverses,
the value axis moves to the other side, the legend follows the reading direction,
and bar corner radii flip. A time series reading left-to-right inside a
right-to-left interface asks the reader to change direction mid-page. ECharts also
draws its own text and inherits nothing from CSS, so the Arabic font stack is
handed to it explicitly — otherwise axis labels fall back to a Latin-first face.

## Showing where a number came from

This is a design rule, not just an engineering one, because it is enforced in the
components.

Every figure the product displays carries a **source state** — `live`, `synced`,
`stale`, `partial`, `sandbox`, `manual`, `estimated` — and the interface marks
anything that is not `live`. For `live` the *absence* of a badge is the statement.
`sandbox` uses the loudest tone available, because a sandbox figure mistaken for a
client's spend is the failure the whole mechanism exists to prevent.

A `SourceNotice` sits **above** the numbers on every analytics surface, so a reader
meets the caveat before drawing a conclusion rather than after.

### A missing figure looks missing

`MetricValue.value` is nullable and the formatter renders null as an em dash,
never zero. Coercing it would turn "the platform does not report this" into "this
performed at zero" — a claim about a campaign rather than a statement about the
data.

A dash alone is not enough, though: the card and the table cell also show the
server's `unavailable_reason`. "Not reported by this platform for the selected
period" and "Reach is deduplicated by the platform, so it cannot be added across 4
entities" are different facts, and both tell the reader what to do next. A reader
who sees only a dash assumes the product is broken.

### Numbers

`.ds-numeric` applies tabular figures, so a column aligns on the decimal in both
scripts. Money crosses the wire in **minor units** and is divided once, at the
formatter, to two decimals — including above a thousand. These figures get
reconciled against a platform's invoice, and a column where 840.25 keeps its cents
while 12,500.00 does not is both inconsistent to scan and short of the precision
someone is checking. Dense tiles that cannot afford the width use `formatCompact`,
which is an explicit choice rather than a silent threshold.

A change is coloured by whether it is **good**, which the server decides from the
metric's own direction: CPA falling 20% is green, spend falling 20% is not. The
interface never re-derives that from the sign.

## Components

Primitives live in `frontend/src/shared/ui`, shadcn/ui-style: unstyled behaviour plus
`cva` variants, composed rather than configured. Variant definitions sit in their own
module (`button.variants.ts`) so component files stay fast-refresh friendly.
