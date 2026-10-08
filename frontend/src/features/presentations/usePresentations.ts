import { useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchDeck, fetchPresentationVocabulary } from './api';
import type { DeckKindValue } from './types';

export function usePresentationVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.presentations.vocabulary(),
    queryFn: fetchPresentationVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

/**
 * A deck.
 *
 * Held for a minute. Composing one costs an analytics aggregation per section plus the assistant's own analysis,
 * and a reader flipping between slides is reading rather than re-asking.
 */
export function useDeckQuery(
  kind: DeckKindValue,
  clientId: string | null,
  budgetId: string | null,
  enabled = true,
) {
  const hasSubject = clientId !== null || budgetId !== null;

  return useQuery({
    queryKey: queryKeys.presentations.deck(kind, clientId, budgetId),
    queryFn: () => fetchDeck({ kind, clientId, budgetId }),
    enabled: enabled && hasSubject,
    staleTime: 60_000,
  });
}
