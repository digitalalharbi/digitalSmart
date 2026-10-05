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

## Charts (from Phase 4)

Professional and interactive: rich tooltips, comparison overlays, annotations,
threshold/target lines, brush selection on time series, zoom where it helps, and export.
Every chart must be readable in both themes, must not rely on hue alone, and must label
its axes and units.

## Components

Primitives live in `frontend/src/shared/ui`, shadcn/ui-style: unstyled behaviour plus
`cva` variants, composed rather than configured. Variant definitions sit in their own
module (`button.variants.ts`) so component files stay fast-refresh friendly.
