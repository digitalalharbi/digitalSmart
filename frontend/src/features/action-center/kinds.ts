import type { AttentionItem, AttentionStreamValue } from '@/features/attention';
import type { ApprovalKindValue } from '@/features/approvals';

/** The few words a person sorts their day by. Every item, whatever produced it, is one of these. */
export type ActionType =
  'budget' | 'campaign' | 'creative' | 'lead' | 'report' | 'tracking' | 'task' | 'request';

export const ACTION_TYPES: readonly ActionType[] = [
  'budget',
  'campaign',
  'creative',
  'lead',
  'report',
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
  /* Agent findings are sorted by what they are about — see `itemType`. */
  agents: 'task',
  /* Pending approvals are shown as decisions, from the approvals queue itself, so they are not counted twice. */
  approvals: null,
  /* A launch waiting on somebody is about campaigns. */
  launches: 'campaign',
};

/** The kind of one item: by stream, except an agent's finding, which is the kind of thing it is about. */
export function itemType(item: AttentionItem): ActionType | null {
  if (item.stream !== 'agents') {
    return STREAM_TYPE[item.stream];
  }

  const code = item.reason?.code ?? '';

  if (code.startsWith('CREATIVE_')) return 'creative';
  if (code.startsWith('LEADS_')) return 'lead';
  if (code.startsWith('REPORT')) return 'report';

  return 'task';
}
