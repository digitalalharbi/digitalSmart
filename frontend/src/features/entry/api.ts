import { useMutation, useQuery } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';

export type AccessNeed = 'self_managed' | 'agency_clients' | 'ad_services';

export const ACCESS_NEEDS: AccessNeed[] = ['self_managed', 'agency_clients', 'ad_services'];

export type RequestKind =
  'account_access' | 'general_enquiry' | 'demo_request' | 'partnership' | 'other';

export const REQUEST_KINDS: RequestKind[] = [
  'account_access',
  'general_enquiry',
  'demo_request',
  'partnership',
  'other',
];

export type PreferredContact = 'email' | 'phone' | 'whatsapp';

export const PREFERRED_CONTACTS: PreferredContact[] = ['email', 'phone', 'whatsapp'];

export type AccessRequestInput = {
  kind: RequestKind;
  name: string;
  email: string;
  company: string;
  phone: string;
  jobTitle: string;
  preferredContact: PreferredContact;
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
      const { jobTitle, preferredContact, need, ...rest } = input;

      await api.post('/public/access-requests', {
        ...rest,
        job_title: jobTitle.trim() === '' ? null : jobTitle.trim(),
        preferred_contact: preferredContact,
        /* Only an account request is asked what it needs; an enquiry has no such question. */
        need: input.kind === 'account_access' ? need : null,
        company: input.company.trim() === '' ? null : input.company.trim(),
        phone: input.phone.trim() === '' ? null : input.phone.trim(),
        message: input.message.trim() === '' ? null : input.message.trim(),
      });
    },
  });
}
