import { api } from '@/shared/api/client';
import type {
  ClientRequest,
  ClientRequestKindValue,
  ClientRequestStateValue,
  IntakeQueue,
  MessageAudienceValue,
  RequestVocabulary,
  StatedUrgencyValue,
} from './types';

export type IntakeScope = {
  clientId?: string | null;
  kind?: ClientRequestKindValue | null;
  /** True to show only what the agency is holding up, excluding what is waiting on a client. */
  onTheAgencyOnly?: boolean;
};

export async function fetchRequestVocabulary(): Promise<RequestVocabulary> {
  const { data } = await api.get<{ data: RequestVocabulary }>('/client-requests/vocabulary');

  return data.data;
}

export async function fetchIntakeQueue(scope: IntakeScope): Promise<IntakeQueue> {
  const params: Record<string, string> = {};

  if (scope.clientId) {
    params.client_id = scope.clientId;
  }

  if (scope.kind) {
    params.kind = scope.kind;
  }

  if (scope.onTheAgencyOnly === true) {
    params.on_the_agency = '1';
  }

  const { data } = await api.get<{ data: IntakeQueue }>('/client-requests/queue', { params });

  return data.data;
}

export async function fetchClientRequest(requestId: string): Promise<ClientRequest> {
  const { data } = await api.get<{ data: ClientRequest }>(`/client-requests/${requestId}`);

  return data.data;
}

export async function submitClientRequest(input: {
  clientId: string;
  kind: ClientRequestKindValue;
  title: string;
  detail?: string;
  projectId?: string | null;
  urgency?: StatedUrgencyValue;
  wantedBy?: string | null;
}): Promise<ClientRequest> {
  const { data } = await api.post<{ data: ClientRequest }>('/client-requests', {
    client_id: input.clientId,
    kind: input.kind,
    title: input.title,
    ...(input.detail === undefined ? {} : { detail: input.detail }),
    ...(input.projectId ? { project_id: input.projectId } : {}),
    ...(input.urgency === undefined ? {} : { stated_urgency: input.urgency }),
    ...(input.wantedBy ? { wanted_by: input.wantedBy } : {}),
  });

  return data.data;
}

export async function triageClientRequest(input: {
  requestId: string;
  state: ClientRequestStateValue;
  reason?: string;
}): Promise<ClientRequest> {
  const { data } = await api.post<{ data: ClientRequest }>(
    `/client-requests/${input.requestId}/state`,
    {
      state: input.state,
      ...(input.reason === undefined ? {} : { reason: input.reason }),
    },
  );

  return data.data;
}

export async function promiseClientRequest(input: {
  requestId: string;
  /** An empty string withdraws the promise. */
  promisedAt: string;
}): Promise<ClientRequest> {
  const { data } = await api.post<{ data: ClientRequest }>(
    `/client-requests/${input.requestId}/promise`,
    input.promisedAt === '' ? {} : { promised_at: input.promisedAt },
  );

  return data.data;
}

export async function assignRequestOwner(input: {
  requestId: string;
  ownerId: string | null;
}): Promise<ClientRequest> {
  const { data } = await api.post<{ data: ClientRequest }>(
    `/client-requests/${input.requestId}/owner`,
    input.ownerId === null ? {} : { owner_id: input.ownerId },
  );

  return data.data;
}

export async function answerClientRequest(input: {
  requestId: string;
  audience: MessageAudienceValue;
  body: string;
}): Promise<ClientRequest> {
  const { data } = await api.post<{ data: ClientRequest }>(
    `/client-requests/${input.requestId}/messages`,
    { audience: input.audience, body: input.body },
  );

  return data.data;
}

export async function raiseTaskForRequest(input: {
  requestId: string;
  title: string;
  priority?: string;
}): Promise<ClientRequest> {
  const { data } = await api.post<{ data: ClientRequest }>(
    `/client-requests/${input.requestId}/tasks`,
    {
      title: input.title,
      ...(input.priority === undefined ? {} : { priority: input.priority }),
    },
  );

  return data.data;
}
