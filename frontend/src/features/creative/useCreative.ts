import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  commentOnRequest,
  decideOnVersion,
  fetchBoard,
  fetchRecordedSpecs,
  fetchRequest,
  fetchVocabulary,
  moveRequest,
} from './api';

/**
 * The board's vocabulary: states, legal moves, asset kinds, comment audiences.
 *
 * Held for a long time, because it changes when the product deploys rather than when somebody works. Fetched
 * at all — rather than hard-coded — so the interface cannot offer a move the server refuses.
 */
export function useCreativeVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.creative.vocabulary(),
    queryFn: fetchVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function useCreativeBoardQuery(clientId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.creative.board({ clientId }),
    queryFn: () => fetchBoard(clientId),
    enabled,
    // A board several people work at once. Fifteen seconds is short enough that two of them do not collide.
    staleTime: 15_000,
  });
}

export function useCreativeRequestQuery(requestId: string | null) {
  return useQuery({
    queryKey: queryKeys.creative.request(requestId ?? ''),
    queryFn: () => fetchRequest(requestId ?? ''),
    enabled: requestId !== null,
    staleTime: 10_000,
  });
}

/**
 * Which placements this product can check work against.
 *
 * Long-lived for the same reason as the vocabulary, and worth its own query: the list of placements with no
 * recorded specification is the list an agency's work goes out on unchecked.
 */
export function useRecordedSpecsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.creative.specs(),
    queryFn: fetchRecordedSpecs,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

/** Everything that writes invalidates the whole module, for one reason stated once below. */
function useCreativeMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      /*
       * The board and the open request both, because every write moves a card between columns and changes the
       * counts above them. Nothing is patched in place: the spec verdicts are composed server-side against the
       * newest recording of a platform's requirements, and recomputing them in the browser would be a second
       * implementation of the rules the domain owns.
       */
      void client.invalidateQueries({ queryKey: queryKeys.creative.all() });
    },
  });
}

export function useMoveRequestMutation() {
  return useCreativeMutation(moveRequest);
}

export function useDecideMutation() {
  return useCreativeMutation(decideOnVersion);
}

export function useCommentMutation() {
  return useCreativeMutation(commentOnRequest);
}
