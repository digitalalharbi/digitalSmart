import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { SearchHit } from './types';

type Shortcuts = { pinned: SearchHit[]; recent: SearchHit[] };

/** What this person pinned and last looked at, already resolved against what they may still see. */
export function useShortcutsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.shortcuts.all(),
    queryFn: async (): Promise<Shortcuts> => {
      const { data } = await api.get<{ data: Shortcuts }>('/shortcuts');

      return data.data;
    },
    enabled,
    staleTime: 30_000,
  });
}

export function usePinMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async ({
      hit,
      pinned,
    }: {
      hit: Pick<SearchHit, 'type' | 'id'>;
      pinned: boolean;
    }) => {
      const url = `/shortcuts/pins/${hit.type}/${hit.id}`;

      await (pinned ? api.put(url) : api.delete(url));
    },
    onSuccess: () => client.invalidateQueries({ queryKey: queryKeys.shortcuts.all() }),
  });
}

/**
 * Record that something was opened. Fire-and-forget: a failed write to a convenience list must never
 * get in the way of the navigation it decorates.
 */
export function useVisit() {
  return (hit: Pick<SearchHit, 'type' | 'id'>) => {
    if (hit.type !== 'client' && hit.type !== 'project' && hit.type !== 'campaign') {
      return;
    }

    void api.post('/shortcuts/recents', { type: hit.type, id: hit.id }).catch(() => undefined);
  };
}
