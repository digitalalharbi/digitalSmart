import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';

export type RecipientStatus = 'active' | 'paused' | 'unsubscribed' | 'halted';
export type EmailDeliveryState =
  'pending' | 'provider_accepted' | 'failed' | 'awaiting_credentials' | 'skipped';

export interface ReportRecipient {
  id: string;
  report_id: string;
  email: string;
  name: string | null;
  locale: 'ar' | 'en';
  cadence: 'weekly' | 'monthly';
  status: RecipientStatus;
  halted_reason: string | null;
  consent_note: string;
  consented_at: string;
  added_by_name: string | null;
  deliveries: Array<{
    id: string;
    period: string;
    state: EmailDeliveryState;
    detail: string | null;
    attempts: number;
    attempted_at: string | null;
  }>;
}

export interface RecipientList {
  recipients: ReportRecipient[];
  /** Whether this installation can send email at all. Said on the surface, not left to be inferred. */
  mailConfigured: boolean;
  mailMissing: string | null;
}

export interface RecipientDraft {
  email: string;
  name: string;
  locale: 'ar' | 'en';
  cadence: 'weekly' | 'monthly';
  consent_note: string;
}

const key = (reportId: string) => ['reports', 'recipients', reportId] as const;

export function useRecipientsQuery(reportId: string, enabled: boolean) {
  return useQuery({
    queryKey: key(reportId),
    enabled,
    queryFn: async (): Promise<RecipientList> => {
      const { data } = await api.get<{
        data: ReportRecipient[];
        meta: { mail_configured: boolean; mail_missing: string | null };
      }>(`/reports/${reportId}/recipients`);

      return {
        recipients: data.data,
        mailConfigured: data.meta.mail_configured,
        mailMissing: data.meta.mail_missing,
      };
    },
  });
}

export function useAddRecipientMutation(reportId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (draft: RecipientDraft): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/reports/${reportId}/recipients`, {
        ...draft,
        name: draft.name.trim() === '' ? null : draft.name.trim(),
      });
    },
    onSuccess: () => void client.invalidateQueries({ queryKey: key(reportId) }),
  });
}

export function useChangeRecipientMutation(reportId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      id: string;
      action: 'pause' | 'resume' | 'remove';
    }): Promise<void> => {
      await ensureCsrfCookie();

      if (input.action === 'remove') {
        await api.delete(`/report-recipients/${input.id}`);
      } else {
        await api.post(`/report-recipients/${input.id}/${input.action}`);
      }
    },
    onSuccess: () => void client.invalidateQueries({ queryKey: key(reportId) }),
  });
}

/** The recipient's own stop. No session; the same answer whatever the token was. */
export async function unsubscribeFromReportEmails(token: string): Promise<void> {
  /*
   * A visitor from an email has no cookies at all, and the API refuses a POST without the CSRF cookie. Without this
   * line the button fails for exactly the person it exists for — and only in a real browser, which is how it was found.
   */
  await ensureCsrfCookie();
  // Stopping twice stops once, so a dropped connection is worth one more try rather than an error on the one button here.
  await api.post(`/report-emails/unsubscribe/${token}`, undefined, { retryOnNetworkError: true });
}
