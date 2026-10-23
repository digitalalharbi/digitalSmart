import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import type { OpsFilters, Operations } from './types';

export function useOperationsQuery(
  projectId: string | null,
  from: string,
  to: string,
  filters: OpsFilters,
) {
  return useQuery({
    queryKey: ['tracking', 'operations', projectId, from, to, filters] as const,
    enabled: projectId !== null,
    queryFn: async (): Promise<Operations> => {
      const params: Record<string, string> = { project_id: projectId ?? '', from, to };

      for (const [key, value] of Object.entries(filters)) {
        if (value !== '') {
          params[key] = value;
        }
      }

      return (await api.get<{ data: Operations }>('/tracking/analytics/operations', { params }))
        .data.data;
    },
  });
}
