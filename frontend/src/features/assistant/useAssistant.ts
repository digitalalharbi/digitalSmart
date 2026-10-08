import { useMutation, useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  askAssistant,
  fetchAnalysis,
  fetchAssistantVocabulary,
  fetchReportProposal,
  investigateFinding,
} from './api';

export function useAssistantVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.assistant.vocabulary(),
    queryFn: fetchAssistantVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

/**
 * An analysis.
 *
 * Held for a minute. The analysers are deterministic over a fixed period, so re-running them because somebody
 * switched tabs and came back would cost two analytics aggregations for an answer that cannot have changed.
 */
export function useAnalysisQuery(clientId: string | null, days: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.assistant.analysis(clientId ?? '', days),
    queryFn: () => fetchAnalysis({ clientId: clientId ?? '', days }),
    enabled: enabled && clientId !== null,
    staleTime: 60_000,
  });
}

export function useFindingQuery(clientId: string | null, days: number, code: string | null) {
  return useQuery({
    queryKey: queryKeys.assistant.finding(clientId ?? '', days, code ?? ''),
    queryFn: () => investigateFinding({ clientId: clientId ?? '', days, code: code ?? '' }),
    enabled: clientId !== null && code !== null,
    staleTime: 60_000,
  });
}

export function useReportProposalQuery(clientId: string | null, days: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.assistant.proposal(clientId ?? '', days),
    queryFn: () => fetchReportProposal({ clientId: clientId ?? '', days }),
    enabled: enabled && clientId !== null,
    staleTime: 60_000,
  });
}

/**
 * Asking a question.
 *
 * A mutation rather than a query, and not because it changes anything — it changes nothing. A question is typed
 * and submitted once, and keying a cache by its text would fill that cache with every half-written question
 * somebody paused in the middle of.
 */
export function useAskMutation() {
  return useMutation({ mutationFn: askAssistant });
}
