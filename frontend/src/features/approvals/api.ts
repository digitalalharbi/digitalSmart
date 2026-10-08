import { api } from '@/shared/api/client';
import type {
  ApprovalKindValue,
  ApprovalQueue,
  ApprovalRequest,
  ApprovalVocabulary,
  WriteOutcome,
} from './types';

export type QueueScope = {
  clientId?: string | null;
  kind?: ApprovalKindValue | null;
  spendOnly?: boolean;
};

export async function fetchApprovalVocabulary(): Promise<ApprovalVocabulary> {
  const { data } = await api.get<{ data: ApprovalVocabulary }>('/approvals/vocabulary');

  return data.data;
}

export async function fetchApprovalQueue(scope: QueueScope): Promise<ApprovalQueue> {
  const params: Record<string, string> = {};

  if (scope.clientId) {
    params.client_id = scope.clientId;
  }

  if (scope.kind) {
    params.kind = scope.kind;
  }

  if (scope.spendOnly === true) {
    params.spend_only = '1';
  }

  const { data } = await api.get<{ data: ApprovalQueue }>('/approvals/queue', { params });

  return data.data;
}

export async function fetchApproval(requestId: string): Promise<ApprovalRequest> {
  const { data } = await api.get<{ data: ApprovalRequest }>(`/approvals/${requestId}`);

  return data.data;
}

export async function requestApproval(input: {
  kind: ApprovalKindValue;
  title: string;
  rationale: string;
  proposal: Record<string, unknown>;
  clientId?: string | null;
  projectId?: string | null;
  supersedesId?: string | null;
}): Promise<ApprovalRequest> {
  const { data } = await api.post<{ data: ApprovalRequest }>('/approvals', {
    kind: input.kind,
    title: input.title,
    rationale: input.rationale,
    proposal: input.proposal,
    ...(input.clientId ? { client_id: input.clientId } : {}),
    ...(input.projectId ? { project_id: input.projectId } : {}),
    ...(input.supersedesId ? { supersedes_id: input.supersedesId } : {}),
  });

  return data.data;
}

export async function decideApproval(input: {
  requestId: string;
  outcome: 'approved' | 'declined';
  /*
   * The fingerprint the screen displayed, sent back unchanged. A mismatch answers 409 with the proposal as it
   * stands — the interface then shows the new one rather than retrying, because only a re-read resolves it.
   */
  fingerprint: string;
  reason?: string;
}): Promise<ApprovalRequest> {
  const { data } = await api.post<{ data: ApprovalRequest }>(
    `/approvals/${input.requestId}/decision`,
    {
      outcome: input.outcome,
      fingerprint: input.fingerprint,
      ...(input.reason === undefined ? {} : { reason: input.reason }),
    },
  );

  return data.data;
}

export async function withdrawApproval(input: {
  requestId: string;
  reason: string;
}): Promise<ApprovalRequest> {
  const { data } = await api.post<{ data: ApprovalRequest }>(
    `/approvals/${input.requestId}/withdrawal`,
    { reason: input.reason },
  );

  return data.data;
}

export async function recordEnactment(input: {
  requestId: string;
  enactment: 'confirmed' | 'abandoned';
  note?: string;
}): Promise<ApprovalRequest> {
  const { data } = await api.post<{ data: ApprovalRequest }>(
    `/approvals/${input.requestId}/enactment`,
    {
      enactment: input.enactment,
      ...(input.note === undefined ? {} : { note: input.note }),
    },
  );

  return data.data;
}

/**
 * Carry an approved change out on the platform.
 *
 * The outcome comes back **beside** the request rather than folded into it, and whatever it was: a failed write
 * leaves the approval exactly where it was, so a response that only returned the record would leave a reader to
 * infer the reason from something that had not changed.
 */
export async function carryOutApproval(input: {
  requestId: string;
}): Promise<{ request: ApprovalRequest; outcome: WriteOutcome }> {
  const { data } = await api.post<{ data: ApprovalRequest; outcome: WriteOutcome }>(
    `/approvals/${input.requestId}/carry-out`,
  );

  return { request: data.data, outcome: data.outcome };
}
