import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ArrowRight, Plug } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useAnalytics } from '@/features/analytics';
import { ApiError } from '@/shared/api/client';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard, MetricCardSkeleton } from '@/shared/ui/metric-card';
import { SourceNotice } from '@/shared/ui/source-badge';
import { formatMetric } from '@/shared/metrics';
import { HealthPanel } from './HealthPanel';

/** Four figures, not ten. An overview that shows everything prioritises nothing. */
const HEADLINE = ['spend', 'roas', 'conversions', 'cpa'] as const;

/**
 * The landing screen.
 *
 * Answers one question — "is anything wrong, and where is the money going?" — and
 * then gets out of the way with a link to the surface that answers the follow-up.
 * It deliberately does not try to be a dashboard: that is what Analytics is for,
 * and two screens competing to be the dashboard is how both end up ignored.
 */
export function OverviewPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canSeeAnalytics = can(PERMISSIONS.analyticsView);

  const { data, isPending, isError, error } = useAnalytics(
    {
      preset: '28d',
      compare: 'previous_period',
      metrics: [...HEADLINE],
      dimension: 'provider',
      order_by: 'spend',
      limit: 8,
    },
    canSeeAnalytics,
  );

  const isForbidden = isError && error instanceof ApiError && error.status === 403;

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-content text-2xl font-semibold">{t('overview.title')}</h1>
        <p className="text-content-secondary text-sm">{t('overview.subtitle')}</p>
      </header>

      {canSeeAnalytics && !isForbidden && (
        <section className="flex flex-col gap-4" aria-label={t('overview.performance')}>
          {data !== undefined && <SourceNotice source={data.source} />}

          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {isPending
              ? HEADLINE.map((metric) => <MetricCardSkeleton key={metric} />)
              : HEADLINE.map((metric) => {
                  const value = data?.summary.values[metric];

                  return value === undefined ? null : (
                    <MetricCard key={metric} value={value} emphasis="primary" />
                  );
                })}
          </div>

          {data?.is_empty === true ? (
            <EmptyState
              title={t('overview.noData.title')}
              description={t('overview.noData.description')}
              action={
                <Link to="/integrations" className={buttonVariants({ variant: 'secondary' })}>
                  <Plug aria-hidden className="size-4" />
                  {t('overview.noData.action')}
                </Link>
              }
            />
          ) : (
            <Card>
              <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle>{t('overview.byPlatform')}</CardTitle>
                <Link to="/analytics" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                  {t('overview.openAnalytics')}
                  {/* An arrow is a direction, so it follows the reading one. */}
                  <ArrowRight aria-hidden className="size-4 rtl:rotate-180" />
                </Link>
              </CardHeader>
              <CardContent>
                <ul className="flex flex-col">
                  {(data?.breakdown ?? []).map((row) => {
                    const spend = row.values.spend;

                    return (
                      <li
                        key={row.key}
                        className="border-line-subtle flex items-center justify-between gap-3 border-b py-2.5 last:border-0"
                      >
                        <span className="text-content flex items-center gap-2 text-sm font-medium">
                          <span
                            aria-hidden
                            className="inline-block size-2 rounded-full"
                            style={{
                              backgroundColor: `var(--ds-platform-${row.key === 'google_ads' ? 'google' : row.key})`,
                            }}
                          />
                          {/* The API already returns the platform's display name. */}
                          {row.label}
                        </span>
                        <span className="ds-numeric text-content text-sm">
                          {spend === undefined ? '—' : formatMetric(spend, i18n.language)}
                        </span>
                      </li>
                    );
                  })}
                </ul>
              </CardContent>
            </Card>
          )}
        </section>
      )}

      {/*
        System health last, not first. It is what an operator checks when something
        feels wrong, not what anyone opens the product to see.
      */}
      <HealthPanel />
    </div>
  );
}
