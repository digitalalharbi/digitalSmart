import { api } from '@/shared/api/client';
import type {
  ApprovalOutcomeValue,
  CommentVisibilityValue,
  CreativeBoard,
  CreativeRequest,
  CreativeStateValue,
  CreativeVocabulary,
  RecordedSpec,
} from './types';

export async function fetchVocabulary(): Promise<CreativeVocabulary> {
  const { data } = await api.get<{ data: CreativeVocabulary }>('/creative/vocabulary');

  return data.data;
}

export async function fetchBoard(clientId: string | null): Promise<CreativeBoard> {
  const { data } = await api.get<{ data: CreativeBoard }>('/creative/board', {
    params: clientId === null ? {} : { client_id: clientId },
  });

  return data.data;
}

export async function fetchRequest(requestId: string): Promise<CreativeRequest> {
  const { data } = await api.get<{ data: CreativeRequest }>(`/creative/${requestId}`);

  return data.data;
}

export async function fetchRecordedSpecs(): Promise<RecordedSpec[]> {
  const { data } = await api.get<{ data: RecordedSpec[] }>('/creative/specs');

  return data.data;
}

export async function moveRequest(input: {
  requestId: string;
  state: CreativeStateValue;
  reason?: string;
}): Promise<CreativeRequest> {
  const { data } = await api.post<{ data: CreativeRequest }>(`/creative/${input.requestId}/state`, {
    state: input.state,
    ...(input.reason === undefined ? {} : { reason: input.reason }),
  });

  return data.data;
}

export async function decideOnVersion(input: {
  versionId: string;
  outcome: ApprovalOutcomeValue;
  reason?: string;
}): Promise<CreativeRequest> {
  const { data } = await api.post<{ data: CreativeRequest }>(
    `/creative-versions/${input.versionId}/decision`,
    {
      outcome: input.outcome,
      ...(input.reason === undefined ? {} : { reason: input.reason }),
    },
  );

  return data.data;
}

export async function commentOnRequest(input: {
  requestId: string;
  body: string;
  visibility: CommentVisibilityValue;
  versionId?: string;
}): Promise<CreativeRequest> {
  const { data } = await api.post<{ data: CreativeRequest }>(
    `/creative/${input.requestId}/comments`,
    {
      body: input.body,
      visibility: input.visibility,
      ...(input.versionId === undefined ? {} : { version_id: input.versionId }),
    },
  );

  return data.data;
}
