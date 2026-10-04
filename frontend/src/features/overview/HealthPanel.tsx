import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, CircleSlash } from 'lucide-react';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { useHealthQuery } from './api';
import type { ServiceStatus } from './types';

const TONE: Record<ServiceStatus, 'success' | 'warning' | 'danger'> = {
  ok: 'success',
  degraded: 'warning',
  down: 'danger',
};

function StatusIcon({ status }: { status: ServiceStatus }) {
  if (status === 'ok') return <CheckCircle2 className="text-success" aria-hidden="true" />;
  if (status === 'degraded') return <AlertTriangle className="text-warning" aria-hidden="true" />;
  return <CircleSlash className="text-danger" aria-hidden="true" />;
}

export function HealthPanel() {
  const { t } = useTranslation();
  const { data, isPending, isError, refetch, isFetching } = useHealthQuery();

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1">
            <CardTitle>{t('overview.health.title')}</CardTitle>
            {data ? (
              <CardDescription>
                {data.application} · {t('overview.health.version')} {data.version} ·{' '}
                {t('overview.health.environment')} {data.environment}
              </CardDescription>
            ) : null}
          </div>
          {data ? (
            <Badge tone={TONE[data.status]}>{t(`overview.health.${data.status}`)}</Badge>
          ) : null}
        </div>
      </CardHeader>

      <CardContent>
        {isPending ? (
          <div
            className="flex flex-col gap-2"
            aria-label={t('overview.health.loading')}
            aria-busy="true"
          >
            <Skeleton className="h-9" />
            <Skeleton className="h-9" />
            <Skeleton className="h-9" />
          </div>
        ) : null}

        {isError ? (
          <div
            role="alert"
            className="border-danger/30 bg-danger-surface flex flex-col items-start gap-3 rounded-lg border p-4"
          >
            <div className="flex flex-col gap-1">
              <p className="text-content text-sm font-medium">{t('overview.health.error')}</p>
              <p className="text-content-secondary font-mono text-xs">
                {t('overview.health.errorHint')}
              </p>
            </div>
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void refetch()}
              disabled={isFetching}
            >
              {t('actions.retry')}
            </Button>
          </div>
        ) : null}

        {data ? (
          <table className="w-full text-sm">
            <caption className="sr-only">{t('overview.health.title')}</caption>
            <thead>
              <tr className="text-content-tertiary border-line-subtle border-b text-start text-xs tracking-wide uppercase">
                <th scope="col" className="py-2 pe-3 text-start font-medium">
                  {t('overview.health.service')}
                </th>
                <th scope="col" className="py-2 pe-3 text-start font-medium">
                  {t('overview.health.status')}
                </th>
                <th scope="col" className="py-2 text-start font-medium">
                  {t('overview.health.detail')}
                </th>
              </tr>
            </thead>
            <tbody>
              {data.services.map((service) => (
                <tr key={service.name} className="border-line-subtle border-b last:border-0">
                  <td className="text-content py-2.5 pe-3 font-medium">{service.name}</td>
                  <td className="py-2.5 pe-3">
                    <span className="inline-flex items-center gap-2">
                      <StatusIcon status={service.status} />
                      <span className="text-content-secondary">
                        {t(`overview.health.${service.status}`)}
                      </span>
                    </span>
                  </td>
                  <td className="text-content-secondary py-2.5 font-mono text-xs">
                    {service.detail ?? '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </CardContent>
    </Card>
  );
}
