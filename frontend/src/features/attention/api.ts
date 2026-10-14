import { api } from '@/shared/api/client';
import type { AttentionQueue, AttentionStreamValue } from './types';

export async function fetchAttention(
  streams: AttentionStreamValue[],
  limit?: number,
): Promise<AttentionQueue> {
  const { data } = await api.get<AttentionQueue>('/attention', {
    params: {
      ...(streams.length === 0 ? {} : { stream: streams }),
      ...(limit === undefined ? {} : { limit }),
    },
  });

  /* The whole envelope, not `data.data`: the meta carries the scope, and the scope is load-bearing. */
  return data;
}
