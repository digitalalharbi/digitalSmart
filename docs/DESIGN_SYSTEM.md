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

Inter for Latin, IBM Plex Sans Arabic for Arabic, with a monospace face for identifiers
and raw values. Any compared number — KPI tiles, tables, axes — uses
`tabular-nums lining-nums` (the `.ds-numeric` utility, applied to table cells
automatically) so digits align in columns.

## Layout

Mobile-first. A 12-column grid (`ds-canvas-grid`) for analytical canvases; a persistent
rail above `lg`, a focus-trapped drawer below it. Generous but efficient spacing: this
is a dense product, and whitespace is used for grouping rather than for air.

## Motion

150–300ms, purposeful, `--ds-ease-standard` by default. Motion communicates origin and
hierarchy; it never decorates. `prefers-reduced-motion` reduces every animation and
transition to a near-instant step, globally.

## Bidirectionality

Arabic and English are both designed. Layout uses logical properties (`ms-`/`me-`,
`ps-`/`pe-`, `start`/`end`) so direction flips structurally rather than by mirroring.
Arabic gets its own family and a looser line-height; numerals stay Latin so figures
reconcile against platform dashboards.

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
