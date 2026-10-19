import type {
  EntityLevel,
  Operator,
  RuleAction,
  RuleCondition,
  RuleDraft,
  RuleMetric,
  RuleMode,
} from './types';

/**
 * Ready rules, one click to start: each opens the builder filled in, to adjust and save — never a rule switched on
 * blind. Thresholds are in each campaign's own currency. Creative fatigue and tracking failures are watched by the
 * Creative agent and the tracking checks, and are listed as such rather than duplicated.
 */
export type Template = {
  key: string;
  /** Campaign unless stated: an ad-group template judges ad groups on their own figures, an ad template ads. */
  level?: EntityLevel;
  metric: RuleMetric;
  operator?: Operator;
  threshold: number | null;
  conditions?: RuleCondition[];
  windowDays: number;
  forDays?: number;
  action: RuleAction;
  actionPct?: number;
  mode?: RuleMode;
  severity: 'watch' | 'needs_attention' | 'critical';
};

export const TEMPLATES: Template[] = [
  {
    key: 'scaleWinners',
    metric: 'spend',
    threshold: 150,
    conditions: [
      { metric: 'roas', operator: 'above', threshold: 4 },
      { metric: 'campaign_age', operator: 'above', threshold: 2 },
    ],
    windowDays: 7,
    action: 'propose_budget_increase',
    actionPct: 20,
    severity: 'watch',
  },
  {
    key: 'cutLosers',
    metric: 'spend',
    threshold: 500,
    conditions: [
      { metric: 'roas', operator: 'below', threshold: 3 },
      { metric: 'campaign_age', operator: 'above', threshold: 2 },
    ],
    windowDays: 7,
    action: 'propose_budget_cut',
    actionPct: 20,
    severity: 'needs_attention',
  },
  {
    key: 'pauseNoSale',
    metric: 'spend',
    threshold: 1000,
    conditions: [{ metric: 'conversions', operator: 'below', threshold: 1 }],
    windowDays: 7,
    action: 'propose_pause',
    severity: 'critical',
  },
  {
    key: 'budgetNear',
    metric: 'budget_used',
    threshold: 90,
    windowDays: 7,
    action: 'alert',
    mode: 'monitor',
    severity: 'needs_attention',
  },
  {
    key: 'cpaSpike',
    metric: 'cost_change',
    threshold: 30,
    windowDays: 7,
    forDays: 2,
    action: 'propose_budget_cut',
    actionPct: 15,
    severity: 'needs_attention',
  },
  {
    key: 'roasDrop',
    metric: 'roas',
    threshold: 2,
    windowDays: 7,
    forDays: 2,
    action: 'propose_budget_cut',
    actionPct: 15,
    severity: 'needs_attention',
  },
  {
    key: 'noLeads',
    metric: 'no_results',
    threshold: null,
    windowDays: 3,
    forDays: 2,
    action: 'propose_pause',
    severity: 'critical',
  },
  {
    key: 'noPurchases',
    metric: 'no_results',
    threshold: null,
    windowDays: 7,
    action: 'alert',
    mode: 'monitor',
    severity: 'critical',
  },
  {
    key: 'endDate',
    metric: 'ends_within',
    threshold: 3,
    windowDays: 7,
    action: 'alert',
    mode: 'monitor',
    severity: 'watch',
  },
  {
    key: 'cutWeakAdSets',
    level: 'ad_group',
    metric: 'spend',
    threshold: 200,
    conditions: [
      { metric: 'roas', operator: 'below', threshold: 2 },
      { metric: 'campaign_age', operator: 'above', threshold: 3 },
    ],
    windowDays: 7,
    forDays: 2,
    action: 'propose_pause',
    severity: 'needs_attention',
  },
  {
    key: 'scaleWinningAdSets',
    level: 'ad_group',
    metric: 'spend',
    threshold: 100,
    conditions: [
      { metric: 'roas', operator: 'above', threshold: 4 },
      { metric: 'campaign_age', operator: 'above', threshold: 3 },
    ],
    windowDays: 7,
    action: 'propose_budget_increase',
    actionPct: 15,
    severity: 'watch',
  },
  {
    key: 'pauseFatiguedAds',
    level: 'ad',
    metric: 'ctr',
    operator: 'below',
    threshold: 0.8,
    conditions: [
      { metric: 'spend', operator: 'above', threshold: 100 },
      { metric: 'campaign_age', operator: 'above', threshold: 14 },
    ],
    windowDays: 7,
    forDays: 2,
    action: 'propose_pause',
    severity: 'needs_attention',
  },
  {
    key: 'pauseZeroConversionAds',
    level: 'ad',
    metric: 'spend',
    threshold: 150,
    conditions: [{ metric: 'conversions', operator: 'below', threshold: 1 }],
    windowDays: 7,
    action: 'propose_pause',
    severity: 'critical',
  },
  {
    key: 'spendSpike',
    metric: 'budget_used',
    threshold: 130,
    windowDays: 1,
    action: 'alert',
    mode: 'monitor',
    severity: 'critical',
  },
];

/** Watched elsewhere already; listed so the set is the whole picture. */
export const COVERED: Array<{ key: string; by: 'tracking' | 'creative' }> = [
  { key: 'tracking', by: 'tracking' },
];

export function draftFrom(
  template: Template,
  name: string,
  scope: { client_id?: string | null; project_id?: string | null },
): RuleDraft {
  return {
    name,
    metric: template.metric,
    ...(template.operator === undefined ? {} : { operator: template.operator }),
    threshold: template.threshold,
    window_days: template.windowDays,
    severity: template.severity,
    is_active: true,
    conditions: template.conditions ?? [],
    logic: 'all',
    for_days: template.forDays ?? 1,
    action: template.action,
    action_pct: template.actionPct ?? null,
    mode: template.mode ?? (template.action === 'alert' ? 'monitor' : 'recommend'),
    schedule: 'hourly',
    timezone: 'Asia/Riyadh',
    cooldown_hours: 24,
    object_level: template.level ?? 'campaign',
    ...scope,
  };
}
