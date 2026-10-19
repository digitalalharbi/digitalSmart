import type { RuleAction, RuleCondition, RuleDraft, RuleMetric, TrustLevel } from './types';

/**
 * Ready-made rules, one tap each.
 *
 * Only what the product can really watch is offered as a switch. Tracking failures and creative fatigue are watched
 * already — by the tracking checks and the Creative agent — and are listed as such rather than duplicated. Every
 * template that acts does so as a proposal a person approves (trust 2); none changes a platform.
 */
export type Template = {
  key: string;
  metric: RuleMetric;
  /** The default limit, in the unit the metric uses: money in the campaign's currency, ROAS as a ratio, percent, days. */
  threshold: number | null;
  windowDays: number;
  severity: 'watch' | 'needs_attention' | 'critical';
  conditions?: RuleCondition[];
  forDays?: number;
  action?: RuleAction;
  actionPct?: number;
  trust?: TrustLevel;
};

export const TEMPLATES: Template[] = [
  {
    key: 'roasDrop',
    metric: 'roas',
    threshold: 2,
    windowDays: 7,
    severity: 'needs_attention',
    forDays: 2,
    action: 'propose_budget_cut',
    actionPct: 15,
  },
  {
    key: 'cpaUp',
    metric: 'cost_per_result',
    threshold: 100,
    windowDays: 7,
    severity: 'needs_attention',
    forDays: 3,
    action: 'propose_budget_cut',
    actionPct: 15,
  },
  {
    key: 'cplUp',
    metric: 'cost_per_result',
    threshold: 50,
    windowDays: 7,
    severity: 'needs_attention',
    forDays: 3,
  },
  {
    key: 'budgetNear',
    metric: 'budget_used',
    threshold: 90,
    windowDays: 7,
    severity: 'needs_attention',
  },
  { key: 'overspend', metric: 'budget_used', threshold: 110, windowDays: 3, severity: 'critical' },
  { key: 'spendSpike', metric: 'budget_used', threshold: 130, windowDays: 1, severity: 'critical' },
  {
    key: 'noLeads',
    metric: 'no_results',
    threshold: null,
    windowDays: 3,
    severity: 'critical',
    forDays: 2,
    action: 'propose_pause',
  },
  { key: 'noSales', metric: 'no_results', threshold: null, windowDays: 7, severity: 'critical' },
  { key: 'adRejected', metric: 'rejected', threshold: null, windowDays: 7, severity: 'critical' },
  { key: 'endDate', metric: 'ends_within', threshold: 3, windowDays: 7, severity: 'watch' },
  {
    key: 'pauseLosers',
    metric: 'cost_per_result',
    threshold: 150,
    windowDays: 7,
    severity: 'critical',
    conditions: [{ metric: 'roas', threshold: 1 }],
    forDays: 3,
    action: 'propose_pause',
  },
];

/** Watched elsewhere already; listed so the page is the whole picture. */
export const COVERED: Array<{ key: string; by: 'tracking' | 'creative' }> = [
  { key: 'tracking', by: 'tracking' },
  { key: 'fatigue', by: 'creative' },
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
    conditions: template.conditions ?? [],
    for_days: template.forDays ?? 1,
    action: template.action ?? 'alert',
    action_pct: template.action === 'propose_budget_cut' ? (template.actionPct ?? 15) : null,
    trust_level: template.trust ?? 2,
    ...scope,
  };
}
