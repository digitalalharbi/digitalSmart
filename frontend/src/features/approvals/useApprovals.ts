import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  decideApproval,
  fetchApproval,
  fetchApprovalQueue,
  fetchApprovalVocabulary,
  carryOutApproval,
  recordEnactment,
  requestApproval,
  withdrawApproval,
  type QueueScope,
} from './api';

export function useApprovalVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.approvals.vocabulary(),
    queryFn: fetchApprovalVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function useApprovalQueueQuery(scope: QueueScope, enabled = true) {
  return useQuery({
    queryKey: queryKeys.approvals.queue({
      clientId: scope.clientId ?? null,
      kind: scope.kind ?? null,
      spendOnly: scope.spendOnly ?? false,
    }),
    queryFn: () => fetchApprovalQueue(scope),
    enabled,
    staleTime: 15_000,
  });
}

export function useApprovalQuery(requestId: string | null) {
  return useQuery({
    queryKey: queryKeys.approvals.detail(requestId ?? ''),
    queryFn: () => fetchApproval(requestId ?? ''),
    enabled: requestId !== null,
    /*
     * Short, deliberately shorter than the queue's. The fingerprint on screen is what a decision sends back,
     * so a proposal held longer than a few seconds is a 409 a reader cannot explain.
     */
    staleTime: 5_000,
  });
}

/** Everything that writes invalidates the module, for one reason stated once below. */
function useApprovalMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      /*
       * Both queues and the open request. Nothing is patched in place: the counts are aggregated server-side
       * and the ages are computed against one server moment, so adjusting them here would be a second
       * implementation of arithmetic that knows, for instance, that an outstanding change is aged from its
       * decision rather than from its request.
       */
      void client.invalidateQueries({ queryKey: queryKeys.approvals.all() });
    },
  });
}

export function useRequestApprovalMutation() {
  return useApprovalMutation(requestApproval);
}

export function useDecideApprovalMutation() {
  return useApprovalMutation(decideApproval);
}

export function useWithdrawApprovalMutation() {
  return useApprovalMutation(withdrawApproval);
}

export function useEnactmentMutation() {
  return useApprovalMutation(recordEnactment);
}

/**
 * Carrying a change out on the platform.
 *
 * Its own mutation rather than a flag on the enactment one, because the two are different acts with different
 * permissions: recording an enactment is a person saying what they did, and this asks the platform. The mutation
 * keeps its own result so the surface can show the outcome — including a failure, which leaves the request
 * unchanged and would otherwise look like nothing happened.
 */
export function useCarryOutMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: carryOutApproval,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.approvals.all() });
    },
  });
}
