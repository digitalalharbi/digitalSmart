/**
 * ECharts theming from the design tokens.
 *
 * The tokens are CSS custom properties in OKLCH, which ECharts cannot use: it draws to
 * a canvas, and it does not merely pass colours through — it parses them to lift a hue
 * on hover and to build an area gradient, with a parser that predates OKLCH. So each
 * token is read from the computed style of the document root and resolved to a plain
 * hex here. The consequence is that a chart follows a theme switch and the brand accent
 * without a single colour being duplicated in TypeScript, which is the only way two
 * screens cannot drift apart.
 */
import { hexFromOklch } from './colour';

export type ChartPalette = {
  /** The eight categorical slots, in their fixed order. Never cycled, never reordered. */
  series: string[];
  /** One hue, light → dark, for scales where the order is the meaning. */
  steps: string[];
  /** A diverging scale: cool pole, neutral middle, warm pole. */
  poles: { low: string; lowWeak: string; mid: string; highWeak: string; high: string };
  grid: string;
  axis: string;
  axisLabel: string;
  tooltipSurface: string;
  tooltipBorder: string;
  comparison: string;
  threshold: string;
  textPrimary: string;
  textSecondary: string;
  textTertiary: string;
  /** The surface a chart is drawn on — the colour of the gap between touching marks. */
  surface: string;
  accent: string;
  success: string;
  warning: string;
  danger: string;
  fontFamily: string;
};

/**
 * How many categorical series a chart form can carry.
 *
 * `adjacent` covers bars, stacks and lines, where slots are handed out in order so
 * only neighbours ever touch. `anyPair` covers scatter, bubble and small multiples,
 * where any two marks can land side by side and so every pair has to clear the gate —
 * which it does only for the first four slots. Past the cap the answer is to fold the
 * tail into one "other" row or to facet, never to invent a ninth colour: a generated
 * hue is indistinguishable from an existing one under colour blindness.
 *
 * Measured, not asserted — see `palette.gates.test.ts`.
 */
export const seriesCap = { adjacent: 8, anyPair: 4 } as const;

/**
 * One pattern per categorical slot, in the same fixed order as the colours.
 *
 * The order matters for the same reason it matters for the hues: slot 3 is only ever drawn beside
 * slots 2 and 4, so the pairs that have to separate are the neighbouring ones. Shuffling this list
 * would put two similar patterns side by side.
 *
 * Shapes are ECharts `decal` objects — `dashArrayX`/`dashArrayY` with a `rotation` draw a line
 * pattern, and a `symbol` draws a repeated mark. The eight are built from four distinguishable
 * families rather than eight angles of the same hatch: hatching at two opposing angles, a
 * horizontal and a vertical rule, a dot, a ring, a wide-spaced hatch and a cross grid. Angles alone
 * would give four patterns and four near-duplicates.
 *
 * **What the gate checks and what it cannot.** `palette.gates.test.ts` asserts the eight are
 * pairwise distinct as definitions, which catches a copy-paste; whether two patterns *look*
 * different at 24 pixels is a design judgement and is not pretended to be measured, unlike the
 * colour separations, which are computed.
 */
export const seriesDecals = [
  { dashArrayX: [1, 0], dashArrayY: [4, 3], rotation: Math.PI / 4 },
  { dashArrayX: [1, 0], dashArrayY: [4, 3], rotation: -Math.PI / 4 },
  { dashArrayX: [1, 0], dashArrayY: [4, 4], rotation: 0 },
  { dashArrayX: [1, 0], dashArrayY: [4, 4], rotation: Math.PI / 2 },
  { symbol: 'circle', symbolSize: 0.4, dashArrayX: [4, 3], dashArrayY: [4, 3] },
  { symbol: 'rect', symbolSize: 0.5, dashArrayX: [6, 4], dashArrayY: [6, 4] },
  { dashArrayX: [1, 0], dashArrayY: [2, 8], rotation: Math.PI / 4 },
  { dashArrayX: [[6, 6]], dashArrayY: [6, 6], rotation: 0 },
] as const;

/**
 * One dash pattern per slot, for line series, which a decal cannot reach.
 *
 * Lengths rather than angles, because a line has one direction. The first is deliberately **not**
 * solid: if slot 1 stayed solid while the rest dashed, a reader would read the solid one as the
 * real measurement and the others as projections — which is what a dashed line means everywhere
 * else in this product.
 */
export const seriesDashes = [
  [10, 4],
  [2, 3],
  [12, 3, 2, 3],
  [6, 3],
  [2, 2, 8, 2],
  [14, 5],
  [4, 2, 1, 2],
  [8, 3, 3, 3],
] as const;

/**
 * Values used before the document is available — unit tests and any render that
 * happens before styles apply. These are the resolved tokens, not stand-ins, so a
 * chart never flashes a colour the design system does not contain.
 */
const FALLBACK: ChartPalette = {
  series: ['#c24a2c', '#3860bb', '#00a68f', '#b3910b', '#8a2b60', '#036819', '#a37fde', '#a73e3b'],
  steps: ['#8cb3ff', '#729cf3', '#5985e4', '#406dd6', '#3159b8', '#244699'],
  poles: {
    low: '#3860bb',
    lowWeak: '#8aa7e8',
    mid: '#e5e6e9',
    highWeak: '#e79b92',
    high: '#a73e3b',
  },
  grid: 'rgba(20, 20, 20, 0.08)',
  axis: '#9ca3af',
  axisLabel: '#6b6b6b',
  tooltipSurface: '#ffffff',
  tooltipBorder: 'rgba(20, 20, 20, 0.12)',
  comparison: '#9ca3af',
  threshold: '#6b6b6b',
  textPrimary: '#141414',
  textSecondary: '#525252',
  textTertiary: '#6b6b6b',
  surface: '#ffffff',
  accent: '#c24a2c',
  success: '#1f6f46',
  warning: '#7c5a12',
  danger: '#b03a34',
  fontFamily: 'Sora, sans-serif',
};

function readVar(style: CSSStyleDeclaration, name: string, fallback: string): string {
  const value = style.getPropertyValue(name).trim();

  if (value === '') {
    return fallback;
  }

  /*
   * A browser hands back whatever the author wrote, so an OKLCH token arrives as
   * `oklch(...)`. Resolved to hex here; anything already in a form ECharts
   * understands — `rgb()`, a hex, a keyword — is passed through untouched.
   */
  return hexFromOklch(value) ?? value;
}

export function readChartPalette(): ChartPalette {
  if (typeof document === 'undefined' || typeof getComputedStyle !== 'function') {
    return FALLBACK;
  }

  const style = getComputedStyle(document.documentElement);

  const series = [1, 2, 3, 4, 5, 6, 7, 8].map((index, position) =>
    readVar(style, `--ds-chart-${index}`, FALLBACK.series[position] ?? FALLBACK.series[0]!),
  );

  const steps = [1, 2, 3, 4, 5, 6].map((index, position) =>
    readVar(style, `--ds-chart-step-${index}`, FALLBACK.steps[position] ?? FALLBACK.steps[0]!),
  );

  return {
    series,
    steps,
    poles: {
      low: readVar(style, '--ds-chart-pole-low', FALLBACK.poles.low),
      lowWeak: readVar(style, '--ds-chart-pole-low-weak', FALLBACK.poles.lowWeak),
      mid: readVar(style, '--ds-chart-pole-mid', FALLBACK.poles.mid),
      highWeak: readVar(style, '--ds-chart-pole-high-weak', FALLBACK.poles.highWeak),
      high: readVar(style, '--ds-chart-pole-high', FALLBACK.poles.high),
    },
    grid: readVar(style, '--ds-chart-grid', FALLBACK.grid),
    axis: readVar(style, '--ds-chart-axis', FALLBACK.axis),
    axisLabel: readVar(style, '--ds-chart-axis-label', FALLBACK.axisLabel),
    tooltipSurface: readVar(style, '--ds-chart-tooltip-surface', FALLBACK.tooltipSurface),
    tooltipBorder: readVar(style, '--ds-chart-tooltip-border', FALLBACK.tooltipBorder),
    comparison: readVar(style, '--ds-chart-comparison', FALLBACK.comparison),
    threshold: readVar(style, '--ds-chart-threshold', FALLBACK.threshold),
    textPrimary: readVar(style, '--ds-text-primary', FALLBACK.textPrimary),
    textSecondary: readVar(style, '--ds-text-secondary', FALLBACK.textSecondary),
    textTertiary: readVar(style, '--ds-text-tertiary', FALLBACK.textTertiary),
    /*
     * The surface, not the page behind it: the 2px gap that separates two touching
     * bars is drawn in the colour of the card, so on a sunken panel it would show as
     * a pale seam.
     */
    surface: readVar(style, '--ds-surface', FALLBACK.surface),
    accent: readVar(style, '--ds-accent', FALLBACK.accent),
    success: readVar(style, '--ds-success', FALLBACK.success),
    warning: readVar(style, '--ds-warning', FALLBACK.warning),
    danger: readVar(style, '--ds-danger', FALLBACK.danger),
    /*
     * ECharts draws text itself and inherits nothing from CSS, so the family has to be
     * handed to it. In Arabic that must be the mixed stack: a chart axis carries Arabic
     * labels beside Latin numerals and currency codes, and the Latin-first stack would
     * render the Arabic in a fallback face.
     */
    fontFamily:
      document.documentElement.dir === 'rtl'
        ? readVar(style, '--ds-font-mixed', FALLBACK.fontFamily)
        : readVar(style, '--ds-font-sans', FALLBACK.fontFamily),
  };
}

/** Colour for a platform chip or series, by provider slug. */
export function platformColour(provider: string): string {
  if (typeof document === 'undefined' || typeof getComputedStyle !== 'function') {
    return FALLBACK.accent;
  }

  const style = getComputedStyle(document.documentElement);
  const slug = provider === 'google_ads' ? 'google' : provider;

  return readVar(style, `--ds-platform-${slug}`, FALLBACK.accent);
}
