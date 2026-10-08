import { api } from '@/shared/api/client';
import type { AssistantAnswer, AssistantVocabulary, ReportProposal } from './types';

export async function fetchAssistantVocabulary(): Promise<AssistantVocabulary> {
  const { data } = await api.get<{ data: AssistantVocabulary }>('/assistant/vocabulary');

  return data.data;
}

export async function fetchAnalysis(input: {
  clientId: string;
  days: number;
}): Promise<AssistantAnswer> {
  const { data } = await api.get<{ data: AssistantAnswer }>('/assistant/analyse', {
    params: { client_id: input.clientId, days: input.days },
  });

  return data.data;
}

/**
 * A question in words.
 *
 * A POST because the question is content rather than a parameter: a client's question in a URL would end up in
 * every access log the request passes through, and a question names what somebody is worried about.
 */
export async function askAssistant(input: {
  clientId: string;
  days: number;
  question: string;
}): Promise<AssistantAnswer> {
  const { data } = await api.post<{ data: AssistantAnswer }>('/assistant/ask', {
    client_id: input.clientId,
    days: input.days,
    question: input.question,
  });

  return data.data;
}

export async function investigateFinding(input: {
  clientId: string;
  days: number;
  code: string;
}): Promise<AssistantAnswer> {
  const { data } = await api.get<{ data: AssistantAnswer }>(`/assistant/findings/${input.code}`, {
    params: { client_id: input.clientId, days: input.days },
  });

  return data.data;
}

export async function fetchReportProposal(input: {
  clientId: string;
  days: number;
}): Promise<ReportProposal> {
  const { data } = await api.get<{ data: ReportProposal }>('/assistant/report', {
    params: { client_id: input.clientId, days: input.days },
  });

  return data.data;
}
