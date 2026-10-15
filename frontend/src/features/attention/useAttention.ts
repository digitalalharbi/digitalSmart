import { useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchAttention } from './api';
import type { AttentionAudience, AttentionStreamValue } from './types';

/**
 * What is waiting on this person.
 *
 * One request, not six. The server unions the modules, which is what keeps this surface honest at agency
 * scale and keeps the count on it equal to the count on the page each item opens.
 *
 * Thirty seconds, like the boards: several people work the same queue, and an item somebody else cleared
 * five minutes ago should not still be asking.
 */
export function useAttentionQuery(
  streams: AttentionStreamValue[] = [],
  limit?: number,
  audience: AttentionAudience = 'all',
) {
  return useQuery({
    queryKey: queryKeys.attention.queue({ streams, limit: limit ?? null, audience }),
    queryFn: () => fetchAttention(streams, limit, audience),
    staleTime: 30_000,
  });
}
