/**
 * The chart theme, in one place.
 *
 * Every chart in the product — the hand-drawn SVG primitives in this folder and the ECharts ones under
 * `shared/ui/chart` — takes its geometry and type from here, so a chart on the overview and a chart in a report are
 * visibly the same family. The values are the CampaignsHub chart language, re-expressed in this product's tokens:
 * a hairline dashed grid on the horizontal only, 12px muted ticks, a 2px smooth line with no resting dots, a
 * soft area wash, rounded 6px bars, a ring with a 62% hole, and a tooltip that is a small floating card.
 */
export const CHART = {
  /** Axis and tick labels. */
  tickSize: 12,
  /** Legends and tooltips. */
  textSize: 13,
  lineWidth: 2,
  /** The resting line has no dots; the hovered point is this big, ringed in the surface colour. */
  activeDot: 4,
  /** Gradient under a line: strong at the line, gone at the floor. */
  areaTop: 0.28,
  barRadius: 6,
  /** Ring: hole as a share of the outer radius, and the gap between slices in degrees. */
  ringHole: 0.62,
  ringGapDegrees: 2,
  /** SVG dash for the grid. */
  gridDash: '3 3',
} as const;

/** The tooltip as a card — same surface, border, radius and shadow wherever a chart shows one. */
export const TOOLTIP_CLASS =
  'bg-surface border-line-strong text-content pointer-events-none z-10 min-w-36 rounded-xl border px-3.5 py-2.5 text-[0.8125rem] shadow-md';
