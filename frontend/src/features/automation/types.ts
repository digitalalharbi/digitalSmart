export type RuleMetric =
  'cost_per_result' | 'roas' | 'budget_used' | 'no_results' | 'rejected' | 'ends_within';
export type RuleSeverity = 'watch' | 'needs_attention' | 'critical';
export type RuleAction = 'alert' | 'propose_pause' | 'propose_budget_cut' | 'task';
/** 0 watch · 1 alert · 2 propose (default) · 3 act internally. 4 — changing a platform unasked — is not available. */
export type TrustLevel = 0 | 1 | 2 | 3;

export const NO_THRESHOLD: ReadonlySet<RuleMetric> = new Set(['no_results', 'rejected']);

export const MIN_TRUST: Record<RuleAction, TrustLevel> = {
  alert: 0,
  propose_pause: 2,
  propose_budget_cut: 2,
  task: 3,
};

export type RuleCondition = { metric: RuleMetric; threshold: number | null };

export type Rule = {
  id: string;
  name: string;
  metric: RuleMetric;
  metric_label: string;
  operator: 'above' | 'below';
  threshold: number | null;
  window_days: number;
  severity: RuleSeverity;
  is_active: boolean;
  client: { id: string; name: string } | null;
  project: { id: string; name: string } | null;
  author_name: string | null;
  conditions: Array<RuleCondition & { metric_label: string }>;
  for_days: number;
  action: RuleAction;
  action_pct: number | null;
  trust_level: TrustLevel;
};

export type RuleDraft = {
  name: string;
  metric: RuleMetric;
  threshold: number | null;
  window_days?: number;
  severity?: RuleSeverity;
  is_active?: boolean;
  client_id?: string | null;
  project_id?: string | null;
  conditions?: RuleCondition[];
  for_days?: number;
  action?: RuleAction;
  action_pct?: number | null;
  trust_level?: TrustLevel;
};

/** The whole rule as a draft — so changing one switch never quietly resets its action or trust. */
export function draftOf(rule: Rule): RuleDraft {
  return {
    name: rule.name,
    metric: rule.metric,
    threshold: rule.threshold,
    window_days: rule.window_days,
    severity: rule.severity,
    client_id: rule.client?.id ?? null,
    project_id: rule.project?.id ?? null,
    is_active: rule.is_active,
    conditions: rule.conditions.map(({ metric, threshold }) => ({ metric, threshold })),
    for_days: rule.for_days,
    action: rule.action,
    action_pct: rule.action_pct,
    trust_level: rule.trust_level,
  };
}
