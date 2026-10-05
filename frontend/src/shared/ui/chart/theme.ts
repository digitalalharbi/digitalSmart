/**
 * ECharts theming from the design tokens.
 *
 * The tokens are CSS custom properties in OKLCH, which ECharts cannot resolve —
 * it draws to a canvas and needs literal colours. So they are read from the
 * computed style of the document root at render time. The consequence is that the
 * chart follows a theme switch and the brand accent without any colour being
 * duplicated in TypeScript, which is the only way two screens cannot drift apart.
 */
export type ChartPalette = {
  series: string[];
  grid: string;
  axis: string;
  axisLabel: string;
  tooltipSurface: string;
  tooltipBorder: string;
  comparison: string;
  threshold: string;
  textPrimary: string;
  textSecondary: string;
  accent: string;
  success: string;
  danger: string;
  fontFamily: string;
};

/** Values used before the document is available (tests, SSR). */
const FALLBACK: ChartPalette = {
  series: ['#4f46e5', '#0ea5a4', '#d97706', '#dc2626', '#9333ea', '#16a34a', '#2563eb', '#f59e0b'],
  grid: 'rgba(17, 24, 39, 0.08)',
  axis: '#9ca3af',
  axisLabel: '#6b7280',
  tooltipSurface: '#ffffff',
  tooltipBorder: 'rgba(17, 24, 39, 0.12)',
  comparison: '#9ca3af',
  threshold: '#6b7280',
  textPrimary: '#111827',
  textSecondary: '#4b5563',
  accent: '#4f46e5',
  success: '#16a34a',
  danger: '#dc2626',
  fontFamily: 'Inter, sans-serif',
};

function readVar(style: CSSStyleDeclaration, name: string, fallback: string): string {
  const value = style.getPropertyValue(name).trim();
  return value === '' ? fallback : value;
}

export function readChartPalette(): ChartPalette {
  if (typeof document === 'undefined' || typeof getComputedStyle !== 'function') {
    return FALLBACK;
  }

  const style = getComputedStyle(document.documentElement);

  const series = [1, 2, 3, 4, 5, 6, 7, 8].map((index, position) =>
    readVar(style, `--ds-chart-${index}`, FALLBACK.series[position] ?? FALLBACK.series[0]!),
  );

  return {
    series,
    grid: readVar(style, '--ds-chart-grid', FALLBACK.grid),
    axis: readVar(style, '--ds-chart-axis', FALLBACK.axis),
    axisLabel: readVar(style, '--ds-chart-axis-label', FALLBACK.axisLabel),
    tooltipSurface: readVar(style, '--ds-chart-tooltip-surface', FALLBACK.tooltipSurface),
    tooltipBorder: readVar(style, '--ds-chart-tooltip-border', FALLBACK.tooltipBorder),
    comparison: readVar(style, '--ds-chart-comparison', FALLBACK.comparison),
    threshold: readVar(style, '--ds-chart-threshold', FALLBACK.threshold),
    textPrimary: readVar(style, '--ds-text-primary', FALLBACK.textPrimary),
    textSecondary: readVar(style, '--ds-text-secondary', FALLBACK.textSecondary),
    accent: readVar(style, '--ds-accent', FALLBACK.accent),
    success: readVar(style, '--ds-success', FALLBACK.success),
    danger: readVar(style, '--ds-danger', FALLBACK.danger),
    /*
     * ECharts draws text itself and inherits nothing from CSS, so the family has
     * to be handed to it. In Arabic that must be the mixed stack: a chart axis
     * carries Arabic labels beside Latin numerals and currency codes, and the
     * Latin-first stack would render the Arabic in a fallback face.
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
