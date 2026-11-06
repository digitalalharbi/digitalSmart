import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { formatNumber } from '@/shared/lib/format';
import { useLinkHistoryQuery } from './api';
import type { LinkRevision } from './types';

function when(iso: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(iso),
  );
}

function shown(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '—';
  }

  return typeof value === 'string' || typeof value === 'number'
    ? String(value)
    : JSON.stringify(value);
}

function Revision({
  revision,
  previous,
}: {
  revision: LinkRevision;
  previous: LinkRevision | undefined;
}) {
  const { t, i18n } = useTranslation();

  return (
    <li className="border-line-subtle flex flex-col gap-2 rounded-xl border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-content text-sm font-medium">
          {t('tracking.history.revision', { n: revision.revision })}
        </span>
        {revision.is_current ? <Badge tone="neutral">{t('tracking.history.current')}</Badge> : null}
        <span className="text-content-tertiary text-xs">
          {revision.effective_until === null
            ? t('tracking.history.since', { from: when(revision.effective_from, i18n.language) })
            : t('tracking.history.between', {
                from: when(revision.effective_from, i18n.language),
                until: when(revision.effective_until, i18n.language),
              })}
        </span>
      </div>

      <p className="text-content-secondary text-xs">
        {t('tracking.history.clicks', { n: formatNumber(revision.clicks, i18n.language) })}
        {revision.changed_by_name === null
          ? null
          : ` · ${t('tracking.history.by', { name: revision.changed_by_name })}`}
      </p>

      {revision.backfilled ? (
        <p className="text-content-tertiary text-xs">{t('tracking.history.backfilled')}</p>
      ) : null}

      {revision.changed_fields.length === 0 ? null : (
        <dl className="flex flex-col gap-1 text-xs">
          {revision.changed_fields.map((field) => (
            <div key={field} className="flex flex-wrap gap-x-2">
              <dt className="text-content-tertiary font-mono">{field}</dt>
              <dd className="text-content min-w-0 break-all">
                <span dir="ltr">{shown(previous?.values[field])}</span>
                {' → '}
                <span dir="ltr">{shown(revision.values[field])}</span>
              </dd>
            </div>
          ))}
        </dl>
      )}
    </li>
  );
}

/** What the link said at each point in its life, newest first, with the clicks each state received. */
export function LinkHistoryPanel({ linkId }: { linkId: string }) {
  const { t } = useTranslation();
  const history = useLinkHistoryQuery(linkId);

  if (history.isPending) {
    return <Skeleton className="h-24 w-full" />;
  }

  if (history.isError) {
    return <Alert tone="danger">{t('tracking.history.failed')}</Alert>;
  }

  return (
    <section className="flex flex-col gap-2" aria-label={t('tracking.history.title')}>
      <ul className="flex flex-col gap-2">
        {history.data.map((revision, index) => (
          <Revision
            key={revision.revision}
            revision={revision}
            previous={history.data[index + 1]}
          />
        ))}
      </ul>
      <p className="text-content-tertiary text-xs">{t('tracking.history.note')}</p>
    </section>
  );
}
