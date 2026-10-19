import { useMutation } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { CheckCircle2, ShieldAlert, XCircle } from 'lucide-react';
import { api } from '@/shared/api/client';
import { Button } from '@/shared/ui/button';

type Outcome = { state: 'approved' | 'declined'; title: string };

/**
 * Deciding an approval from a one-time WhatsApp or email link.
 *
 * Opening the page decides nothing — chat apps open links to preview them. The person presses Approve or Decline,
 * which is the one request the link allows; the server reads their permission and the proposal again at that moment.
 * The page shows no figures before the decision, so a forwarded link leaks nothing but that a decision was asked for.
 */
export function ApprovalLinkPage() {
  const { t } = useTranslation();
  const { token = '' } = useParams();
  const decide = useMutation({
    mutationFn: async (decision: 'approve' | 'reject'): Promise<Outcome> => {
      const { data } = await api.post<{ data: Outcome }>(`/approval-links/${token}`, { decision });

      return data.data;
    },
  });

  return (
    <main className="bg-canvas grid min-h-dvh place-items-center px-4">
      <section className="bg-surface flex w-full max-w-sm flex-col items-center gap-5 rounded-3xl p-8 text-center shadow-sm">
        <span className="bg-accent-subtle text-content-brand grid size-12 place-items-center rounded-full font-semibold">
          dS
        </span>

        {decide.isSuccess ? (
          <>
            {decide.data.state === 'approved' ? (
              <CheckCircle2 aria-hidden className="text-success size-10" />
            ) : (
              <XCircle aria-hidden className="text-content-secondary size-10" />
            )}
            <h1 className="text-content text-lg font-semibold" dir="auto">
              {t(
                decide.data.state === 'approved'
                  ? 'approvalLink.approved'
                  : 'approvalLink.declined',
              )}
            </h1>
            <p className="text-content-secondary text-sm" dir="auto">
              {decide.data.title}
            </p>
          </>
        ) : decide.isError ? (
          <>
            <ShieldAlert aria-hidden className="text-warning size-10" />
            <h1 className="text-content text-lg font-semibold">{t('approvalLink.refused')}</h1>
            <p className="text-content-secondary text-sm">{t('approvalLink.refusedBody')}</p>
          </>
        ) : (
          <>
            <h1 className="text-content text-lg font-semibold">{t('approvalLink.title')}</h1>
            <p className="text-content-secondary text-sm">{t('approvalLink.body')}</p>
            <div className="flex w-full flex-col gap-2">
              <Button disabled={decide.isPending} onClick={() => decide.mutate('approve')}>
                {t('approvalLink.approve')}
              </Button>
              <Button
                variant="secondary"
                disabled={decide.isPending}
                onClick={() => decide.mutate('reject')}
              >
                {t('approvalLink.decline')}
              </Button>
            </div>
            <p className="text-content-tertiary text-xs">{t('approvalLink.once')}</p>
          </>
        )}
      </section>
    </main>
  );
}
