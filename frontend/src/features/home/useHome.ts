import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';

export type HomeProfileKey =
  'executive' | 'project_manager' | 'media_buyer' | 'finance' | 'creative';

export type HomeProfile = { key: HomeProfileKey; label: string; sections: string[] };

export type Digest = {
  id: string;
  kind: string;
  label: string;
  period_on: string;
  total: number;
  read_at: string | null;
  summary: {
    by_stream: Record<string, number>;
    top: Array<{ message: string; entity: string; client: string | null }>;
  };
};

/** Which home screens this person should see — decided by the server from what they may do. */
export function useHomeProfiles() {
  return useQuery({
    queryKey: queryKeys.home.profiles(),
    queryFn: async (): Promise<{ primary: HomeProfileKey | null; profiles: HomeProfile[] }> => {
      const { data } = await api.get<{
        data: { primary: HomeProfileKey | null; profiles: HomeProfile[] };
      }>('/home');

      return data.data;
    },
    staleTime: 5 * 60_000,
  });
}

export function useDigests() {
  return useQuery({
    queryKey: queryKeys.home.digests(),
    queryFn: async (): Promise<Digest[]> => {
      const { data } = await api.get<{ data: Digest[] }>('/digests');

      return data.data;
    },
    staleTime: 60_000,
  });
}
