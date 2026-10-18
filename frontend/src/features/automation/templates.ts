import type { RuleDraft, RuleMetric } from './types';

/**
 * Ready-made rules, one tap each.
 *
 * Only what the product can really watch is offered as a switch. The rest are listed, honestly, as not available
 * yet with the reason — a rule that looks enabled and cannot fire is worse than no rule.
 */
export type Template = {
  key: string;
  metric: RuleMetric;
  /** The default limit, in the unit the metric uses: money in the campaign's currency, ROAS as a ratio, percent. */
  threshold: number | null;
  windowDays: number;
  severity: 'watch' | 'needs_attention' | 'critical';
};

export const TEMPLATES: Template[] = [
  {
    key: 'budgetNear',
    metric: 'budget_used',
    threshold: 90,
    windowDays: 7,
    severity: 'needs_attention',
  },
  { key: 'roasDrop', metric: 'roas', threshold: 2, windowDays: 7, severity: 'needs_attention' },
  {
    key: 'cpaUp',
    metric: 'cost_per_result',
    threshold: 100,
    windowDays: 7,
    severity: 'needs_attention',
  },
  { key: 'noResults', metric: 'no_results', threshold: null, windowDays: 3, severity: 'critical' },
];

/** Offered so the list is the whole picture, and marked so nobody mistakes it for a switch. */
export const UNAVAILABLE: Array<{ key: string; why: 'covered' | 'soon' }> = [
  { key: 'tracking', why: 'covered' },
  { key: 'adRejected', why: 'soon' },
  { key: 'endDate', why: 'soon' },
  { key: 'fatigue', why: 'soon' },
  { key: 'spendSpike', why: 'soon' },
];

export function draftFrom(
  template: Template,
  name: string,
  scope: { client_id?: string | null; project_id?: string | null },
): RuleDraft {
  return {
    name,
    metric: template.metric,
    threshold: template.threshold,
    window_days: template.windowDays,
    severity: template.severity,
    is_active: true,
    ...scope,
  };
}
