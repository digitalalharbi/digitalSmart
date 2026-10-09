import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  answerClientRequest,
  attachToClientRequest,
  assignRequestOwner,
  fetchClientRequest,
  fetchIntakeQueue,
  fetchRequestOwners,
  fetchRequestVocabulary,
  promiseClientRequest,
  raiseTaskForRequest,
  submitClientRequest,
  triageClientRequest,
  type IntakeScope,
} from './api';

export function useRequestVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.clientRequests.vocabulary(),
    queryFn: fetchRequestVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function useIntakeQueueQuery(scope: IntakeScope, enabled = true) {
  return useQuery({
    queryKey: queryKeys.clientRequests.queue({
      clientId: scope.clientId ?? null,
      kind: scope.kind ?? null,
      onTheAgencyOnly: scope.onTheAgencyOnly ?? false,
    }),
    queryFn: () => fetchIntakeQueue(scope),
    enabled,
    /*
     * Fifteen seconds, like the other two queues. A client and an account manager are often looking at the same
     * request at the same time, and a stale column is how one of them answers a question the other just answered.
     */
    staleTime: 15_000,
  });
}

export function useClientRequestQuery(requestId: string | null) {
  return useQuery({
    queryKey: queryKeys.clientRequests.detail(requestId ?? ''),
    queryFn: () => fetchClientRequest(requestId ?? ''),
    enabled: requestId !== null,
    staleTime: 10_000,
  });
}

/** Everything that writes invalidates the module, for one reason stated once below. */
function useRequestMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      /*
       * The queue and the open request both. Nothing is patched in place: every figure beside the columns is
       * aggregated server-side against one moment, and the visibility of a message is decided server-side too —
       * patching a response into a cache would be a second implementation of both.
       */
      void client.invalidateQueries({ queryKey: queryKeys.clientRequests.all() });
    },
  });
}

export function useSubmitRequestMutation() {
  return useRequestMutation(submitClientRequest);
}

export function useTriageMutation() {
  return useRequestMutation(triageClientRequest);
}

export function usePromiseMutation() {
  return useRequestMutation(promiseClientRequest);
}

export function useAssignOwnerMutation() {
  return useRequestMutation(assignRequestOwner);
}

export function useAttachMutation() {
  return useRequestMutation(attachToClientRequest);
}

export function useAnswerMutation() {
  return useRequestMutation(answerClientRequest);
}

export function useRaiseTaskMutation() {
  return useRequestMutation(raiseTaskForRequest);
}

/** Who this request can be given to. Held for a minute: it changes only when somebody joins a project. */
export function useRequestOwnersQuery(requestId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.clientRequests.owners(requestId ?? ''),
    queryFn: () => fetchRequestOwners(requestId ?? ''),
    enabled: enabled && requestId !== null,
    staleTime: 60_000,
  });
}
