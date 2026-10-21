/**
 * The figures the hero chart can be pointed at, in the order an agency reads them. One list for every surface that
 * draws a hero chart, so "which figures can I plot" has one answer.
 */
export const HERO_METRICS = [
  'spend',
  'revenue',
  'roas',
  'conversions',
  'cpa',
  'clicks',
  'ctr',
] as const;
