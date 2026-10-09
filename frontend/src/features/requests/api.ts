import { api } from '@/shared/api/client';
import type {
  ClientRequest,
  ClientRequestKindValue,
  ClientRequestStateValue,
  IntakeQueue,
  MessageAudienceValue,
  RequestOwner,
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

/**
 * Upload one file to a request.
 *
 * `FormData`, so the browser sets its own multipart boundary — and deliberately **no** `Content-Type` header of
 * our own, which would break the boundary. The server sniffs the bytes rather than trusting what arrives here,
 * so nothing on this side needs to be clever about the type.
 */
export async function attachToClientRequest(input: {
  requestId: string;
  audience: MessageAudienceValue;
  file: File;
}): Promise<ClientRequest> {
  const body = new FormData();
  body.append('audience', input.audience);
  body.append('file', input.file);

  const { data } = await api.post<{ data: ClientRequest }>(
    `/client-requests/${input.requestId}/attachments`,
    body,
  );

  return data.data;
}

/**
 * Where one attachment's bytes are.
 *
 * A plain link, so the browser handles the download — and it is always a download: the server answers with
 * `Content-Disposition: attachment` and the type it stored, never inline.
 */
export function attachmentUrl(attachmentId: string): string {
  return `/api/client-request-attachments/${attachmentId}`;
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

/**
 * Who this request can be given to.
 *
 * Served rather than assembled from the member directory: a client request carries a client's own words, and an
 * owner whose projects do not reach that client could never open it — so every name offered is one the server
 * will accept.
 */
export async function fetchRequestOwners(requestId: string): Promise<RequestOwner[]> {
  const { data } = await api.get<{ data: RequestOwner[] }>(`/client-requests/${requestId}/owners`);

  return data.data;
}
