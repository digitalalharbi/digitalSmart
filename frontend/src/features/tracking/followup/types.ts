export type AssignmentMode = 'none' | 'specific' | 'project_default' | 'round_robin';

export type SlaStateValue = 'within' | 'approaching' | 'overdue' | 'attempted' | 'contacted';

export type AutomationSettings = {
  project_id: string;
  enabled: boolean;
  assignment_mode: AssignmentMode;
  owner_id: string | null;
  create_task: boolean;
  task_title_ar: string | null;
  task_title_en: string | null;
  task_priority: 'low' | 'normal' | 'high' | 'urgent';
  notify_owner: boolean;
  sla_minutes: number | null;
};

export type QueueItem = {
  id: string;
  name: string | null;
  source: string | null;
  stage: string;
  owner: { id: string; name: string | null } | null;
  assignment_reason: string | null;
  received_at: string;
  first_attempt_at: string | null;
  first_contacted_at: string | null;
  sla_state: SlaStateValue | null;
  due_at: string | null;
  sla_met: boolean | null;
};

export type QueueGroupKey =
  'new' | 'approaching' | 'overdue' | 'attempted' | 'no_response' | 'qualified';

export type FollowUp = {
  settings: AutomationSettings;
  owners: { id: string; name: string }[];
  timezone: string;
  groups: Record<QueueGroupKey, { count: number; items: QueueItem[] }>;
};
