import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  assignTask,
  fetchAssignees,
  fetchTask,
  fetchTaskBoard,
  fetchTaskVocabulary,
  moveTask,
  openTask,
  updateTask,
  type BoardScope,
} from './api';

/**
 * The board's vocabulary: columns, legal moves, the priority scale and the due buckets.
 *
 * Held for a long time, because it changes when the product deploys rather than when somebody works. Fetched
 * at all — rather than hard-coded — so the interface cannot offer a move the server refuses.
 */
export function useTaskVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.tasks.vocabulary(),
    queryFn: fetchTaskVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function useTaskBoardQuery(scope: BoardScope, enabled = true) {
  return useQuery({
    queryKey: queryKeys.tasks.board({
      clientId: scope.clientId ?? null,
      projectId: scope.projectId ?? null,
      assignee: scope.assignee ?? null,
      priority: scope.priority ?? null,
    }),
    queryFn: () => fetchTaskBoard(scope),
    enabled,
    /*
     * Fifteen seconds, like the creative board. Several people work one board at once, and a stale column
     * is how two of them pick up the same task.
     */
    staleTime: 15_000,
  });
}

export function useTaskQuery(taskId: string | null) {
  return useQuery({
    queryKey: queryKeys.tasks.detail(taskId ?? ''),
    queryFn: () => fetchTask(taskId ?? ''),
    enabled: taskId !== null,
    staleTime: 10_000,
  });
}

/** Everything that writes invalidates the whole module, for one reason stated once below. */
function useTaskMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      /*
       * The board and the open task both, because every write moves a card and changes the figures beside
       * it. Nothing is patched in place: the counts are aggregated server-side over the whole board, and
       * adjusting them in the browser would be a second implementation of arithmetic the domain owns —
       * one that would not know that closed work is never overdue.
       */
      void client.invalidateQueries({ queryKey: queryKeys.tasks.all() });
    },
  });
}

export function useOpenTaskMutation() {
  return useTaskMutation(openTask);
}

export function useMoveTaskMutation() {
  return useTaskMutation(moveTask);
}

export function useAssignTaskMutation() {
  return useTaskMutation(assignTask);
}

export function useUpdateTaskMutation() {
  return useTaskMutation(updateTask);
}

/**
 * Who this task can be given to.
 *
 * Held for a minute: the answer changes when somebody is added to a project, which is rare, and a picker that
 * refetched on every selection would ask the server a question it already knows the answer to.
 */
export function useAssigneesQuery(taskId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.tasks.assignees(taskId ?? ''),
    queryFn: () => fetchAssignees(taskId ?? ''),
    enabled: enabled && taskId !== null,
    staleTime: 60_000,
  });
}
