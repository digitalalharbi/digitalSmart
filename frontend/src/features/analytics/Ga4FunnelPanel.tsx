import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { formatNumber, formatPercent } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceNotice } from '@/shared/ui/source-badge';
import { ProgressBar } from '@/shared/ui/viz/Progress';
import { lastDays, useGa4Funnel } from './ga4Traffic';
import type { MetricSourceValue } from '@/shared/metrics';

/**
 * GA4's shopping events in the order a shopper meets them, as counts.
 *
 * These are events, not people: one shopper can add to a cart several times, so the ratio between two stages is an event ratio,
 * never a conversion rate, and the panel says so. A stage the property did not report is "Not reported", not zero, and no ratio is
 * drawn across it. Store orders — not GA4 purchases — are the authority for sales, and the panel says that too.
 */
export function Ga4FunnelPanel({ days, clientId }: { days: number; clientId: string | null }) {
  const { t, i18n } = useTranslation();
  const period = useMemo(() => lastDays(days), [days]);
  const query = useGa4Funnel({ ...period, clientId });

  if (query.isPending) {
    return <Skeleton className="h-48 w-full" />;
  }

  if (query.isError) {
    const message =
      query.error instanceof ApiError && query.error.message !== '' ? query.error.message : null;

    return (
      <Alert tone="danger" className="flex flex-col items-start gap-3">
        <span>{message ?? t('analytics.ga4.funnel.failed')}</span>
        <Button variant="secondary" size="sm" onClick={() => void query.refetch()}>
          {t('actions.retry')}
        </Button>
      </Alert>
    );
  }

  const data = query.data;
  const reported = data.stages.filter((stage) => stage.count !== null);
  const top = Math.max(1, ...reported.map((stage) => stage.count ?? 0));

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('analytics.ga4.funnel.title')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {data.source === null ? null : <SourceNotice source={data.source as MetricSourceValue} />}

        {reported.length === 0 ? (
          <p className="text-content-secondary text-sm">{t('analytics.ga4.funnel.empty')}</p>
        ) : (
          <ol className="flex flex-col gap-3">
            {data.stages.map((stage) => (
              <li key={stage.event} className="flex flex-col gap-1.5">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="text-content text-sm font-medium">
                    {t(`analytics.ga4.funnel.stage.${stage.event}`)}
                  </span>
                  <span className="ds-numeric text-sm">
                    {stage.count === null
                      ? t('analytics.ga4.notReported')
                      : formatNumber(stage.count, i18n.language)}
                    {stage.ratio_from_previous === null ? null : (
                      <span className="text-content-tertiary ms-2 text-xs">
                        {t('analytics.ga4.funnel.ofPrevious', {
                          ratio: formatPercent(stage.ratio_from_previous, i18n.language),
                        })}
                      </span>
                    )}
                  </span>
                </div>
                {stage.count === null ? null : <ProgressBar value={stage.count} max={top} />}
              </li>
            ))}
          </ol>
        )}

        <p className="text-content-tertiary text-xs">{t('analytics.ga4.funnel.note')}</p>
      </CardContent>
    </Card>
  );
}
