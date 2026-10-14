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
import { PageHeader } from '@/shared/ui/page-header';
import { formatMetric } from '@/shared/metrics';
import { HealthPanel } from './HealthPanel';
import { SpendTrend } from './SpendTrend';

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
    <div className="ds-page">
      <PageHeader title={t('overview.title')} description={t('overview.subtitle')} />

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
            <>
              {data === undefined ? null : <SpendTrend analytics={data} />}

              <Card>
                <CardHeader className="flex-row items-center justify-between gap-3">
                  <CardTitle>{t('overview.byPlatform')}</CardTitle>
                  <Link
                    to="/analytics"
                    className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                  >
                    {t('overview.openAnalytics')}
                    {/* An arrow is a direction, so it follows the reading one. */}
                    <ArrowRight aria-hidden className="size-4 rtl:rotate-180" />
                  </Link>
                </CardHeader>
                <CardContent>
                  <ul className="flex flex-col">
                    {(() => {
                      const rows = data?.breakdown ?? [];
                      /*
                       * The largest spend in the list, so each row's bar is a share of it. A list of
                       * numbers does not show that one platform is most of the budget; a bar does, and
                       * the figure stays beside it so nothing rests on reading a length.
                       */
                      const largest = Math.max(
                        1,
                        ...rows.map((row) => {
                          const value = row.values.spend;

                          return value === undefined || value.value === null ? 0 : value.value;
                        }),
                      );

                      return rows.map((row) => {
                        const spend = row.values.spend;
                        const amount =
                          spend === undefined || spend.value === null ? null : spend.value;

                        return (
                          <li
                            key={row.key}
                            className="border-line-subtle flex flex-col gap-1.5 border-b py-2.5 last:border-0"
                          >
                            <div className="flex items-center justify-between gap-3">
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
                            </div>

                            {/*
                            A share bar, in the chart's own first slot rather than the platform's
                            brand colour: the dot beside the name already carries the identity, and
                            eight brand hues at bar size would be eight competing blocks.

                            Nothing is drawn for a platform that reported no spend — a zero-width bar
                            and a missing one look alike, and only one of them is a fact.
                          */}
                            {amount === null ? null : (
                              <span
                                aria-hidden
                                className="bg-surface-sunken block h-1 w-full overflow-hidden rounded-full"
                              >
                                <span
                                  className="block h-full rounded-full"
                                  style={{
                                    width: `${String(Math.max(2, (amount / largest) * 100))}%`,
                                    backgroundColor: 'var(--ds-chart-1)',
                                  }}
                                />
                              </span>
                            )}
                          </li>
                        );
                      });
                    })()}
                  </ul>
                </CardContent>
              </Card>
            </>
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
