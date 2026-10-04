import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { HealthReport } from './types';

export async function fetchHealth(): Promise<HealthReport> {
  const response = await api.get<{ data: HealthReport }>('/health');
  return response.data.data;
}

export function useHealthQuery() {
  return useQuery({
    queryKey: queryKeys.system.health(),
    queryFn: fetchHealth,
    staleTime: 15_000,
    refetchInterval: 60_000,
  });
}
