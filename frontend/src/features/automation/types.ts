export type RuleMetric =
  | 'cost_per_result'
  | 'roas'
  | 'budget_used'
  | 'no_results'
  | 'rejected'
  | 'ends_within'
  | 'spend'
  | 'conversions'
  | 'ctr'
  | 'cpc'
  | 'cpm'
  | 'campaign_age'
  | 'not_delivering'
  | 'cost_change';
export type RuleSeverity = 'watch' | 'needs_attention' | 'critical';
export type RuleAction =
  | 'alert'
  | 'propose_pause'
  | 'propose_resume'
  | 'propose_budget_cut'
  | 'propose_budget_increase'
  | 'propose_bid_change'
  | 'task';
export type RuleMode = 'monitor' | 'recommend' | 'auto';
export type RuleSchedule = 'continuous' | 'hourly' | 'daily';
export type Operator = 'above' | 'below';
export type ExecutionResult =
  'completed' | 'no_change' | 'waiting_approval' | 'failed' | 'unsupported';
/** 0 watch · 1 alert · 2 propose (default) · 3 act internally. 4 — changing a platform unasked — is not available. */
export type TrustLevel = 0 | 1 | 2 | 3;

export const NO_THRESHOLD: ReadonlySet<RuleMetric> = new Set([
  'no_results',
  'rejected',
  'not_delivering',
]);

/** The usual direction of each metric — the builder's default; a person can flip it. */
export const DEFAULT_OPERATOR: Record<RuleMetric, Operator> = {
  cost_per_result: 'above',
  roas: 'below',
  budget_used: 'above',
  no_results: 'above',
  rejected: 'above',
  ends_within: 'below',
  spend: 'above',
  conversions: 'below',
  ctr: 'below',
  cpc: 'above',
  cpm: 'above',
  campaign_age: 'above',
  not_delivering: 'above',
  cost_change: 'above',
};

export const PCT_ACTIONS: ReadonlySet<RuleAction> = new Set([
  'propose_budget_cut',
  'propose_budget_increase',
  'propose_bid_change',
]);

export const MIN_TRUST: Record<RuleAction, TrustLevel> = {
  alert: 0,
  propose_pause: 2,
  propose_resume: 2,
  propose_budget_cut: 2,
  propose_budget_increase: 2,
  propose_bid_change: 2,
  task: 3,
};

export type RuleCondition = { metric: RuleMetric; threshold: number | null; operator?: Operator };

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
  mode: RuleMode;
  logic: 'all' | 'any';
  providers: string[];
  account_ids: string[];
  campaign_ids: string[];
  object_level: 'campaign' | 'ad_group' | 'ad';
  schedule: RuleSchedule;
  schedule_time: string | null;
  timezone: string;
  cooldown_hours: number;
  last_run_at: string | null;
  last_result: Partial<Record<ExecutionResult | 'matched' | 'cooling', number>> | null;
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
  mode?: RuleMode;
  logic?: 'all' | 'any';
  operator?: Operator;
  providers?: string[];
  account_ids?: string[];
  campaign_ids?: string[];
  object_level?: 'campaign';
  schedule?: RuleSchedule;
  schedule_time?: string | null;
  timezone?: string;
  cooldown_hours?: number;
};

export type SummaryCard = { now: number; before: number };
export type Summary = {
  days: number;
  cards: Record<
    'all' | 'turn_on' | 'turn_off' | 'budget' | 'bid' | 'failed' | 'waiting',
    SummaryCard
  >;
  rules: { total: number; active: number; inactive_30d: number };
};

export type Execution = {
  id: string;
  at: string;
  rule: { id: string; name: string };
  client: { id: string; name: string } | null;
  project: { id: string; name: string } | null;
  provider: string | null;
  object: { level: string; label: string; campaign_id: string | null };
  action: RuleAction | 'notify';
  before: Record<string, number | string | null>;
  after: Record<string, number | string | boolean | null>;
  result: ExecutionResult;
  reason_code: string | null;
  approval_id: string | null;
};

export type ChangeEntry = {
  at: string;
  who: string | null;
  rule_id: string;
  rule: string | null;
  kinds: string[];
};

export type Simulation = {
  matched: number;
  looked_at: number;
  objects: Array<{
    campaign_id: string;
    campaign: string;
    provider: string;
    client: string | null;
    project: string | null;
    currency: string | null;
    values: Record<string, number | null>;
    action: string;
    before: Record<string, number | string | null>;
    after: Record<string, number | string | null>;
    capability: string;
  }>;
};

export type Capability = {
  provider: string;
  label: string;
  credentials: 'connected' | 'awaiting_credentials';
  metrics: RuleMetric[];
  actions: Record<RuleAction, 'supported' | 'unsupported'>;
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
    conditions: rule.conditions.map(({ metric, threshold, operator }) => ({
      metric,
      threshold,
      operator: operator ?? DEFAULT_OPERATOR[metric],
    })),
    for_days: rule.for_days,
    action: rule.action,
    action_pct: rule.action_pct,
    trust_level: rule.trust_level,
    mode: rule.mode === 'auto' ? 'recommend' : rule.mode,
    logic: rule.logic,
    operator: rule.operator,
    providers: rule.providers,
    account_ids: rule.account_ids,
    campaign_ids: rule.campaign_ids,
    schedule: rule.schedule,
    schedule_time: rule.schedule_time,
    timezone: rule.timezone,
    cooldown_hours: rule.cooldown_hours,
  };
}
