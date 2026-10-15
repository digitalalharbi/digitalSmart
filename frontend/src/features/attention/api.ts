import { api } from '@/shared/api/client';
import type { AttentionAudience, AttentionQueue, AttentionStreamValue } from './types';

export async function fetchAttention(
  streams: AttentionStreamValue[],
  limit?: number,
  audience: AttentionAudience = 'all',
): Promise<AttentionQueue> {
  const { data } = await api.get<AttentionQueue>('/attention', {
    params: {
      ...(streams.length === 0 ? {} : { stream: streams }),
      ...(limit === undefined ? {} : { limit }),
      ...(audience === 'all' ? {} : { audience }),
    },
  });

  /* The whole envelope, not `data.data`: the meta carries the scope, and the scope is load-bearing. */
  return data;
}
