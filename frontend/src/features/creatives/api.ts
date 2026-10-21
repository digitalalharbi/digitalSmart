import { api } from '@/shared/api/client';
import type { CreativePayload, CreativeQuery } from './types';

export async function fetchCreatives(query: CreativeQuery): Promise<CreativePayload> {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === '') {
      continue;
    }

    if (Array.isArray(value)) {
      for (const item of value) {
        params.append(`${key}[]`, String(item));
      }
      continue;
    }

    params.set(key, String(value));
  }

  const { data } = await api.get<{ data: CreativePayload }>(`/analytics/creatives?${params}`);

  return data.data;
}
