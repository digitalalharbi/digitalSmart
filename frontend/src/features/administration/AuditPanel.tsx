import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search, ShieldAlert } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { useUiStore } from '@/shared/store/ui-store';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { useAuditLogQuery } from './api';

function formatTimestamp(value: string | null, locale: string): string {
  if (!value) {
    return '—';
  }

  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA-u-nu-latn' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
}

export function AuditPanel() {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const [search, setSearch] = useState('');
  const [securityOnly, setSecurityOnly] = useState(false);
  const [page, setPage] = useState(1);

  const { data, isPending, isError, error } = useAuditLogQuery({ search, securityOnly, page });

  if (isError && error instanceof ApiError && error.status === 403) {
    return <Alert tone="warning">{t('administration.auditForbidden')}</Alert>;
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-0 flex-1 sm:max-w-xs">
          <Search
            className="text-content-tertiary pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
            aria-hidden="true"
          />
          <Input
            value={search}
            onChange={(event) => {
              setPage(1);
              setSearch(event.target.value);
            }}
            placeholder={t('administration.searchAudit')}
            aria-label={t('administration.searchAudit')}
            className="ps-9"
            type="search"
          />
        </div>

        <Button
          size="sm"
          variant={securityOnly ? 'subtle' : 'ghost'}
          aria-pressed={securityOnly}
          onClick={() => {
            setPage(1);
            setSecurityOnly((current) => !current);
          }}
        >
          <ShieldAlert />
          {t('administration.securityOnly')}
        </Button>
      </div>

      {isPending ? (
        <LoadingRegion label={t('administration.loadingAudit')} className="flex flex-col gap-2">
          {Array.from({ length: 6 }, (_, index) => (
            <Skeleton key={index} className="h-12" />
          ))}
        </LoadingRegion>
      ) : null}

      {isError && !(error instanceof ApiError && error.status === 403) ? (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('administration.loadFailed')}
        </Alert>
      ) : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title={t('administration.noAudit')}
          description={t('administration.noAuditHint')}
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <ol className="flex flex-col gap-2">
          {data.data.map((entry) => (
            <li
              key={entry.id}
              className="border-line-subtle bg-surface flex flex-wrap items-start gap-x-4 gap-y-1 rounded-lg border px-4 py-3"
            >
              <time
                dateTime={entry.created_at ?? undefined}
                className="text-content-tertiary ds-numeric w-40 shrink-0 text-xs"
              >
                {formatTimestamp(entry.created_at, locale)}
              </time>

              <div className="min-w-0 flex-1">
                <p className="text-content text-sm">{entry.summary ?? entry.action}</p>
                <p className="text-content-tertiary mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                  <span>{entry.actor?.name ?? t('administration.systemActor')}</span>
                  <span aria-hidden="true">·</span>
                  <code className="font-mono">{entry.action}</code>
                  {entry.ip_address ? (
                    <>
                      <span aria-hidden="true">·</span>
                      <span className="font-mono">{entry.ip_address}</span>
                    </>
                  ) : null}
                </p>
              </div>

              {entry.is_security_event ? (
                <Badge tone="warning">{t('administration.securityEvent')}</Badge>
              ) : null}
            </li>
          ))}
        </ol>
      ) : null}

      {data && data.meta.last_page > 1 ? (
        <div className="flex items-center justify-between gap-4">
          <p className="text-content-secondary text-sm">
            {t('clients.pageOf', { page: data.meta.page, total: data.meta.last_page })}
          </p>
          <div className="flex gap-2">
            <Button
              variant="secondary"
              size="sm"
              disabled={data.meta.page <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              {t('actions.previous')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              disabled={!data.meta.has_more}
              onClick={() => setPage((current) => current + 1)}
            >
              {t('actions.next')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
