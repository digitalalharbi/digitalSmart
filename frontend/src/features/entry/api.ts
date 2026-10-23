import { useMutation, useQuery } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';

export type AccessNeed = 'self_managed' | 'agency_clients' | 'ad_services';

export const ACCESS_NEEDS: AccessNeed[] = ['self_managed', 'agency_clients', 'ad_services'];

export type AccessRequestInput = {
  name: string;
  email: string;
  company: string;
  phone: string;
  need: AccessNeed;
  message: string;
  locale: 'ar' | 'en';
  /** A field no person sees. Always empty from the form; anything in it marks a script. */
  website: string;
};

/** Whether this installation takes requests for an account at all. Public: it tells a stranger nothing else. */
export function useAccessOpenQuery() {
  return useQuery({
    queryKey: ['public', 'access-requests', 'open'] as const,
    queryFn: async (): Promise<boolean> => {
      const { data } = await api.get<{ data: { open: boolean } }>('/public/access-requests');

      return data.data.open;
    },
    staleTime: 5 * 60_000,
    retry: false,
  });
}

export function useSubmitAccessRequest() {
  return useMutation({
    mutationFn: async (input: AccessRequestInput): Promise<void> => {
      await ensureCsrfCookie();
      await api.post('/public/access-requests', {
        ...input,
        company: input.company.trim() === '' ? null : input.company.trim(),
        phone: input.phone.trim() === '' ? null : input.phone.trim(),
        message: input.message.trim() === '' ? null : input.message.trim(),
      });
    },
  });
}
