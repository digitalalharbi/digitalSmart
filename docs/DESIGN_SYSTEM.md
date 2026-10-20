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

**Engine: Apache ECharts.** The full method — how the palette was derived and is tested,
the mark specs, the forms, and the rules a chart is not allowed to break — is in
[DATA_VISUALISATION.md](DATA_VISUALISATION.md). The summary a designer needs:

- **Eight categorical slots in a fixed order** (`--ds-chart-1` … `-8`), plus a six-step
  **ordinal ramp** (`--ds-chart-step-*`) for scales whose order is their meaning, and a
  **diverging pair** with a neutral grey midpoint. The order of the categorical slots is
  the colour-blind-safety mechanism and must not be changed without re-deriving it; the
  gates are executable (`palette.gates.test.ts`).
- **Scatter and small-multiple charts carry at most four series.** Any two points can sit
  side by side there, so every pair has to be separable, and four is the measured cap. A
  fifth folds into one "other" row. A ninth hue is never invented.
- **Platform colours are never series colours** — four Meta campaigns would be four shades
  of one blue. They are identifier chips only.
- **Marks are thin and chrome is recessive:** bars ≤ 24px with a 4px rounded data end,
  2px lines, solid hairline gridlines, a 2px gap in the surface colour between touching
  marks rather than a border around them.
- **Lines are never smoothed and gaps are never bridged.** Both invent values that were
  not measured.
- **Never two value scales on one plot.** Two plots sharing a time axis, or both series
  indexed to 100 at a common date, on a named axis.
- **Every chart has a table twin and a source badge,** both from `ChartFrame`, and an
  `ariaLabel` is a required prop. A canvas is invisible to a screen reader and a tooltip
  needs a pointer, so neither can be the only way to read a value.
- **Empty is a sentence, not an empty grid**, and a refresh dims the previous render
  rather than flashing a skeleton.
- **A chart answers a question or it does not ship.** Each one states what it is for above
  it — a scatter plot is not self-explanatory.
- `prefers-reduced-motion` disables animation, read in JavaScript because a canvas is not
  styled by CSS. Charts resize with their container via `ResizeObserver`, since these live
  in a grid whose columns change with the sidebar.
- **A cumulative line stops where the data stops.** The pacing chart's actual series ends
  at the last day a provider reported rather than being carried flat to today: a flat
  cumulative segment means "nothing was spent since", a sync that has not landed means "we
  do not know", and those are different facts about somebody's money.

### Right-to-left

Charts are mirrored, not merely translated: the value axis moves to the other side, the
legend follows the reading direction, bar corner radii flip, and the time axis mirrors —
by **inverting the axis, never by reversing the data**, so every index still means what it
says. ECharts draws its own text and inherits nothing from CSS, so the Arabic font stack is
handed to it explicitly.

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
formatter — `formatMinor` in `shared/lib/format` — to every decimal the currency
has, including above a thousand. These figures get reconciled against a platform's
invoice, and a column where 840.25 keeps its cents while 12,500.00 does not is both
inconsistent to scan and short of the precision someone is checking. Dense tiles
that cannot afford the width use `formatCompact`, which is an explicit choice
rather than a silent threshold.

**How many decimals is a property of the currency, never a constant.**
`shared/lib/money` reads the ISO 4217 exponent for the code beside the figure —
none for JPY, KRW and ISK, two for SAR, AED, USD and EUR, three for KWD, BHD, OMR
and TND — mirroring the server's `CurrencyExponent`, which is the scale the amount
was stored with. The hard-coded hundred this replaced showed a yen at a hundredth
of its value and a dinar at a thousandth, on figures a client is billed for.

The scale is deliberately **not** read from `Intl.NumberFormat`. Its digit counts
are CLDR's cash-display convention rather than the ISO exponent, the two disagree
for eighteen currencies — IQD worst of all, where CLDR says none and ISO publishes
three — and they move with the reader's browser version. So Intl renders the
figure and the table divides it, and `money.test.ts` checks the table against the
server's so the two cannot drift.

A change is coloured by whether it is **good**, which the server decides from the
metric's own direction: CPA falling 20% is green, spend falling 20% is not. The
interface never re-derives that from the sign.

## Visual first, text last (owner direction, 2026-10-19)

The product says things with pictures. A screen opens with figures and charts, then cards, and only then — if at all —
a compact table. Words are the title, a short label, a status and a short action.

**The rules, and the one component that enforces each:**

| Instead of | Use | Where |
|---|---|---|
| A paragraph saying what a page or card is for | `InfoHint` — an ⓘ; hover, focus or tap shows it; a screen reader gets it as the button's description either way | `PageHeader` and `PageSection` descriptions, `CardDescription`, chart subtitles and caveats all become one automatically |
| A long note or caution | `Alert` folds any info/warning text over 110 characters to two lines with *More*; a failure is never folded | `shared/ui/alert.tsx` |
| A table as the main view | Cards first, the table one toggle away (`Cards / Table`) | Projects, Clients, Rules |
| "Spent 62% of budget" | `ProgressBar` / `ProgressRing`, the expected pace as a tick | Finance, project cards |
| "Raise from X to Y" | `CompareBars` or `BeforeAfter` with the change as a pill | decision cards |
| A list of issues | Grouped by kind with a severity ring; identical findings merged (×N); remedy and evidence on expand | Tracking |
| A rule as a sentence | **when** chips → **then** chip → scope, counters under it | Rules |
| "Best: A · Weakest: B" | Two coloured chips with arrows | weekly briefs |

**What stays in words, on purpose:** a failure someone must act on; a figure's source badge (SANDBOX / estimated); and
anything a screen reader needs — the text of every hint is always in the page, so removing it from the pixels removes it
from nobody's reading.

**Tables that remain, and why:** the audit log, the member directory and the platform write matrix are lookups, not
pictures — compared row by row and server-paginated, so a chart over one page would mislead. They are compact and sit
below the figures.

## Components

Primitives live in `frontend/src/shared/ui`, shadcn/ui-style: unstyled behaviour plus
`cva` variants, composed rather than configured. Variant definitions sit in their own
module (`button.variants.ts`) so component files stay fast-refresh friendly.

A primitive moves into `shared/ui` the second time a surface needs it, not the third.
`Choice`/`ChoiceGroup` — a labelled set of mutually exclusive toggles, as a `fieldset`
with a `legend` so a screen reader announces the group before each option — started life
inside the dashboard widget editor and moved out when the planner and the calculator
needed the same thing. Three copies of a toggle is how two of them end up with different
focus rings.
