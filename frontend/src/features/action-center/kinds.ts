import type { AttentionStreamValue } from '@/features/attention';
import type { ApprovalKindValue } from '@/features/approvals';

/** The few words a person sorts their day by. Every item, whatever produced it, is one of these. */
export type ActionType = 'budget' | 'campaign' | 'creative' | 'tracking' | 'task' | 'request';

export const ACTION_TYPES: readonly ActionType[] = [
  'budget',
  'campaign',
  'creative',
  'tracking',
  'task',
  'request',
];

export const APPROVAL_TYPE: Record<ApprovalKindValue, ActionType> = {
  spend_change: 'budget',
  campaign_launch: 'campaign',
  campaign_pause: 'campaign',
  targeting_change: 'campaign',
  creative_publish: 'creative',
  tracking_change: 'tracking',
};

export const STREAM_TYPE: Record<AttentionStreamValue, ActionType | null> = {
  budgets: 'budget',
  campaigns: 'campaign',
  automation: 'campaign',
  tracking: 'tracking',
  tasks: 'task',
  requests: 'request',
  /* Pending approvals are shown as decisions, from the approvals queue itself, so they are not counted twice. */
  approvals: null,
};
