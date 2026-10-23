export type FunnelSource =
  | 'link_click'
  | 'landing_view'
  | 'form_start'
  | 'form_submit'
  | 'lead_created'
  | 'lead_contacted'
  | 'lead_qualified'
  | 'appointment_booked'
  | 'opportunity_created'
  | 'sale_completed';

/** In the order of a journey. A funnel's stages must follow this order; the server refuses any other. */
export const FUNNEL_SOURCES: FunnelSource[] = [
  'link_click',
  'landing_view',
  'form_start',
  'form_submit',
  'lead_created',
  'lead_contacted',
  'lead_qualified',
  'appointment_booked',
  'opportunity_created',
  'sale_completed',
];

export type FunnelStageDef = {
  source: FunnelSource;
  label_ar: string | null;
  label_en: string | null;
  required: boolean;
};

export type StoredFunnel = {
  id: string;
  project_id: string;
  name: string;
  stages: FunnelStageDef[];
};

export type StageResult = FunnelStageDef & {
  unit: 'session' | 'submission' | 'lead';
  /** Null means "not reported", with `reason` — never zero. */
  count: number | null;
  state: 'live' | 'partial';
  reason: string | null;
  conversion_from_previous: number | null;
  conversion_reason: string | null;
  drop_off: number | null;
  drop_off_rate: number | null;
  median_seconds_from_previous: number | null;
};

export type FunnelAnalytics = {
  name: string | null;
  is_standard: boolean;
  stages: StageResult[];
  overall_conversion: number | null;
  overall_reason: string | null;
  truncated: boolean;
  excluded_sessions: number;
  from: string;
  to: string;
};

export type BreakdownRow = {
  key: string;
  sessions: number;
  landing_views: number | null;
  form_starts: number | null;
  submissions: number;
  leads: number;
  qualified: number;
  sales: number;
};

export const DIMENSIONS = [
  'platform',
  'campaign',
  'ad_group',
  'ad',
  'creative',
  'device',
  'browser',
  'referrer',
  'utm_source',
  'landing',
  'date',
  'hour',
] as const;

export type Dimension = (typeof DIMENSIONS)[number];

export type FollowUp = {
  sla_minutes: number;
  leads: number;
  contacted: number;
  attempted: number;
  qualified: number;
  uncontacted: number;
  overdue_uncontacted: number;
  contact_rate: number | null;
  sla_compliance: number | null;
  median_seconds_to_first_contact: number | null;
  median_seconds_to_first_attempt: number | null;
};

export type TimelineEvent = {
  at: string;
  name: string;
  source: 'first_party' | 'server' | 'lead_pipeline';
  evidence: Record<string, unknown>;
};

export type LeadTimeline = { events: TimelineEvent[]; tracked: boolean; note: string | null };
