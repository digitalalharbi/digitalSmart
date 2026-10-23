import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Alert } from '@/shared/ui/alert';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { ApiError } from '@/shared/api/client';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { useAccessRequestsQuery, useReviewAccessRequestMutation } from './api';
import type { AccessRequestStatus } from './types';

const STATUSES: AccessRequestStatus[] = ['pending', 'contacted', 'approved', 'declined'];

/** Which decisions a request in each state may still take. A decision (approved, declined) is final. */
const NEXT: Record<AccessRequestStatus, AccessRequestStatus[]> = {
  pending: ['contacted', 'approved', 'declined'],
  contacted: ['approved', 'declined'],
  approved: [],
  declined: [],
};

const TONE: Record<AccessRequestStatus, 'warning' | 'info' | 'success' | 'neutral'> = {
  pending: 'warning',
  contacted: 'info',
  approved: 'success',
  declined: 'neutral',
};

/**
 * People who asked for an account from the landing page, waiting for an answer.
 *
 * Approving a request **adds nobody** — there is no sender to deliver an invitation, so the person who decided adds the
 * member from People, where the one-time password is read out. The panel says so, once, where the button is.
 */
export function AccessRequestsPanel() {
  const { t, i18n } = useTranslation();
  const [status, setStatus] = useState<AccessRequestStatus | null>('pending');
  const requests = useAccessRequestsQuery(status);
  const review = useReviewAccessRequestMutation();
  const counts = requests.data?.counts;
  const refusal = review.error instanceof ApiError ? review.error.message : null;

  return (
    <section aria-label={t('administration.requests.title')} className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-content text-lg font-semibold">{t('administration.requests.title')}</h2>
        <p className="text-content-secondary text-sm">{t('administration.requests.subtitle')}</p>
      </div>

      <div
        className="flex flex-wrap gap-2"
        role="group"
        aria-label={t('administration.requests.filter')}
      >
        {STATUSES.map((entry) => (
          <Button
            key={entry}
            type="button"
            size="sm"
            variant={status === entry ? 'primary' : 'secondary'}
            aria-pressed={status === entry}
            onClick={() => setStatus(entry)}
          >
            {t(`administration.requests.status.${entry}`)}
            {counts === undefined ? null : (
              <span className="ds-numeric ms-1">{counts[entry] ?? 0}</span>
            )}
          </Button>
        ))}
      </div>

      {refusal === null ? null : <Alert tone="danger">{refusal}</Alert>}

      {requests.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : requests.isError ? (
        <Alert tone="danger">{t('administration.requests.failed')}</Alert>
      ) : requests.data.rows.length === 0 ? (
        <EmptyState
          title={t('administration.requests.emptyTitle')}
          description={t('administration.requests.emptyBody')}
        />
      ) : (
        <ul className="flex flex-col gap-3">
          {requests.data.rows.map((row) => (
            <li
              key={row.id}
              className="bg-surface border-line flex flex-col gap-3 rounded-2xl border p-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-content text-sm font-semibold" dir="auto">
                    {row.name}
                  </p>
                  <p className="text-content-secondary text-xs" dir="ltr">
                    {row.email}
                    {row.phone === null ? '' : ` · ${row.phone}`}
                  </p>
                  {row.company === null ? null : (
                    <p className="text-content-secondary text-xs" dir="auto">
                      {row.company}
                    </p>
                  )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone="neutral">{t(`entry.needs.${row.need}.title`)}</Badge>
                  <Badge tone={TONE[row.status]}>
                    {t(`administration.requests.status.${row.status}`)}
                  </Badge>
                </div>
              </div>

              {row.message === null ? null : (
                <p
                  className="text-content-secondary bg-surface-sunken rounded-xl p-3 text-sm"
                  dir="auto"
                >
                  {row.message}
                </p>
              )}

              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-content-tertiary text-xs">
                  {formatRelativeTime(row.created_at, i18n.language)}
                  {row.reviewed_by_name === null ? '' : ` · ${row.reviewed_by_name}`}
                </span>
                <div className="flex flex-wrap gap-2">
                  {NEXT[row.status].map((next) => (
                    <Button
                      key={next}
                      type="button"
                      size="sm"
                      variant={next === 'approved' ? 'approve' : 'secondary'}
                      disabled={review.isPending}
                      onClick={() => review.mutate({ id: row.id, status: next })}
                    >
                      {t(`administration.requests.act.${next}`)}
                    </Button>
                  ))}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      <p className="text-content-tertiary text-xs">{t('administration.requests.approveNote')}</p>
    </section>
  );
}
