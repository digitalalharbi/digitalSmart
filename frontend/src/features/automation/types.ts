export type RuleMetric = 'cost_per_result' | 'roas' | 'budget_used' | 'no_results';
export type RuleSeverity = 'watch' | 'needs_attention' | 'critical';

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
};
