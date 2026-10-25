import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { InfoHint } from '@/shared/ui/info-hint';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { DonutChart, ProgressRing } from '@/shared/ui/viz';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard } from '@/shared/ui/metric-card';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceBadge } from '@/shared/ui/source-badge';
import { ChartFrame } from '@/shared/ui/chart';
import { BreakdownTable } from './BreakdownTable';
import { CustomerMixPanel } from './CustomerMixPanel';
import { RevenueChart } from './RevenueChart';
import { StoresPanel } from './StoresPanel';
import { useStoreAnalyticsQuery } from './useCommerce';
import { formatMinor } from '@/shared/lib/format';
import { summaryMetrics, windowFor } from './money';
import type { RevenueSourceValue, TrendGrain } from './types';

/**
 * The revenue sources a reader may choose between.
 *
 * Ordered with the invoiced ones first, because those are the only ones that can answer "what did this
 * client actually earn". The rest are measurements of, or claims about, that number — and the interface
 * says so rather than listing all seven as equivalent.
 */
const SOURCES: readonly RevenueSourceValue[] = [
  'salla',
  'zid',
  'crm',
  'google_analytics',
  'platform_reported',
  'attributed',
  'manual',
];

const PERIODS = [7, 30, 90] as const;
const GRAINS: TrendGrain[] = ['day', 'week', 'month'];

/**
 * Store analytics.
 *
 * One page, and one decision it refuses to make for the reader: **which authority's revenue they are
 * looking at.** A Salla total counts invoices; a GA4 total counts what a browser reported before the
 * payment failed; an ad platform reports what it believes it caused. Those will disagree, by design, and
 * an interface that quietly picked one — or worse, added them — would be handing somebody a number to
 * quote at a client that nobody could defend.
 *
 * So the source selector is the first control on the page, it is always visible, and what it is set to is
 * repeated on the total itself.
 */
export function StoreAnalyticsPage() {
  const { t, i18n } = useTranslation();
  const [source, setSource] = useState<RevenueSourceValue>('salla');
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30);
  const [clientId, setClientId] = useState<string | null>(null);
  const [grain, setGrain] = useState<TrendGrain>('day');

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const window = useMemo(() => windowFor(days), [days]);

  const query = useMemo(
    () => ({
      revenue_source: source,
      from: window.from,
      until: window.until,
      client_id: clientId,
      grain,
      // Always against the same number of days immediately before; the server says when there is nothing to compare with.
      compare: 'previous' as const,
    }),
    [source, window, clientId, grain],
  );

  const analytics = useStoreAnalyticsQuery(query);
  const summary = analytics.data?.summary;
  // The trend at the chosen grain; the daily series is what the server sends for an audience that was given no other.
  const trend = analytics.data?.series?.points ?? analytics.data?.daily ?? [];
  const locale = i18n.language;

  /*
   * A `403` means the role cannot read revenue at all — a different message from a transport failure, and not
   * something a retry will fix. Said once, here, rather than left to every panel on the page to report as its
   * own load failure: a reader refused access was being told twice that something had gone wrong.
   */
  if (analytics.isError && analytics.error instanceof ApiError && analytics.error.status === 403) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold">{t('commerce.title')}</h1>
        <Alert tone="warning">{t('commerce.forbidden')}</Alert>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-4">
        <div className="flex items-center gap-1">
          <h1 className="text-content text-2xl font-semibold">{t('commerce.title')}</h1>
          <InfoHint text={t('commerce.subtitle')} />
        </div>

        <div className="flex flex-wrap items-start gap-6">
          <ChoiceGroup label={t('commerce.source.label')} hint={t('commerce.source.hint')}>
            <div className="flex flex-wrap gap-2">
              {SOURCES.map((value) => (
                <Choice key={value} isSelected={source === value} onSelect={() => setSource(value)}>
                  {t(`commerce.sources.${value}`)}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <ChoiceGroup label={t('commerce.period.label')}>
            <div className="flex flex-wrap gap-2">
              {PERIODS.map((value) => (
                <Choice key={value} isSelected={days === value} onSelect={() => setDays(value)}>
                  {t('commerce.period.days', { count: value })}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <ChoiceGroup label={t('commerce.grain.label')}>
            <div className="flex flex-wrap gap-2">
              {GRAINS.map((value) => (
                <Choice key={value} isSelected={grain === value} onSelect={() => setGrain(value)}>
                  {t(`commerce.grain.${value}`)}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <label className="flex flex-col gap-1.5">
            <span className="text-content text-sm font-medium">{t('commerce.client')}</span>
            <select
              className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
              value={clientId ?? ''}
              onChange={(event) =>
                setClientId(event.target.value === '' ? null : event.target.value)
              }
            >
              <option value="">{t('commerce.allClients')}</option>
              {(clients.data?.data ?? []).map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name}
                </option>
              ))}
            </select>
          </label>
        </div>
      </header>

      {/*
        Before the figures, deliberately. "Which stores are we reading, and when did we last read them" is the
        question a low total raises, and a page that led with the total left it unanswerable: until this panel
        existed there was no way to connect a store at all, so every figure above could be empty with nothing
        on screen to say why.
      */}
      <StoresPanel clientId={clientId} clients={clients.data?.data ?? []} />

      {analytics.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : analytics.isError ? (
        <Alert tone="danger">{t('commerce.loadFailed')}</Alert>
      ) : summary === undefined ? null : (
        <>
          {/*
           * What this total is, said in words rather than left to a badge. An invoiced figure and a
           * modelled one are different kinds of claim, and a reader about to quote one deserves to be
           * told which they have.
           */}
          {(() => {
            const claim = summary.is_invoiced
              ? t('commerce.claim.invoiced', { source: summary.revenue_source_label })
              : summary.is_modelled
                ? t('commerce.claim.modelled', { source: summary.revenue_source_label })
                : t('commerce.claim.reported', { source: summary.revenue_source_label });
            /* A modelled figure is the one claim that must be read before it is quoted: it stays in the open. */
            if (summary.is_modelled) {
              return <Alert tone="warning">{claim}</Alert>;
            }
            return (
              <div className="flex items-center gap-1.5">
                <SourceBadge source={summary.source} />
                <span className="text-content-secondary text-sm">
                  {summary.revenue_source_label}
                </span>
                <InfoHint text={claim} />
              </div>
            );
          })()}

          {summary.orders === 0 && summary.pending_orders === 0 ? (
            <EmptyState
              title={t('commerce.empty.title', { source: summary.revenue_source_label })}
              description={t('commerce.empty.body')}
            />
          ) : (
            <>
              <section
                className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                aria-label={t('commerce.summary')}
              >
                {/*
                 * Built as `MetricValue`s so the shared card formats them. It already carries the two
                 * rules that matter — a null value renders as absent rather than as zero, and every
                 * figure shows where it came from — and reimplementing either here would be
                 * reimplementing them differently.
                 */}
                {summaryMetrics(
                  summary,
                  {
                    netRevenue: t('commerce.metrics.netRevenue'),
                    orders: t('commerce.metrics.orders'),
                    aov: t('commerce.metrics.aov'),
                    customers: t('commerce.metrics.customers'),
                    noOrders: t('commerce.metrics.noOrders'),
                  },
                  analytics.data?.comparison,
                ).map((metric) => (
                  <MetricCard key={metric.metric} value={metric} />
                ))}
              </section>

              {analytics.data?.comparison === undefined ? null : analytics.data.comparison
                  .unavailable_reason === null ? (
                <p className="text-content-secondary text-xs" data-testid="comparison-basis">
                  {t('commerce.comparison.basis', {
                    days,
                    from: analytics.data.comparison.previous.from,
                    until: analytics.data.comparison.previous.until,
                  })}
                </p>
              ) : (
                <Alert tone="info">
                  {t(
                    `commerce.comparison.reasons.${analytics.data.comparison.unavailable_reason}`,
                    {
                      days,
                    },
                  )}
                </Alert>
              )}

              <div className="flex items-center gap-1">
                <InfoHint
                  text={t('commerce.metrics.netRevenueHint', {
                    gross: formatMinor(summary.revenue_minor, summary.currency, locale),
                    refunded: formatMinor(summary.refunded_minor, summary.currency, locale),
                    units: new Intl.NumberFormat(locale).format(summary.units),
                  })}
                />
              </div>

              <section
                className="grid gap-4 md:grid-cols-3"
                aria-label={t('commerce.glance.label')}
              >
                <Card>
                  <CardHeader>
                    <CardTitle>{t('commerce.glance.orders')}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <DonutChart
                      size={110}
                      label={t('commerce.glance.orders')}
                      centre={{
                        value: new Intl.NumberFormat(locale).format(
                          summary.orders + summary.pending_orders,
                        ),
                      }}
                      segments={[
                        {
                          key: 'paid',
                          label: t('commerce.glance.paid'),
                          value: summary.orders,
                          colour: 'var(--ds-success-500)',
                        },
                        {
                          key: 'pending',
                          label: t('commerce.glance.pending'),
                          value: summary.pending_orders,
                          colour: 'var(--ds-warning-500)',
                        },
                      ]}
                    />
                  </CardContent>
                </Card>
                <Card>
                  <CardHeader>
                    <CardTitle>{t('commerce.glance.refunds')}</CardTitle>
                  </CardHeader>
                  <CardContent className="flex items-center gap-4">
                    <ProgressRing
                      size={110}
                      value={
                        summary.revenue_minor === 0
                          ? 0
                          : (summary.refunded_minor / summary.revenue_minor) * 100
                      }
                      tone="bad"
                      label={t('commerce.glance.refunds')}
                      centre={`${summary.revenue_minor === 0 ? 0 : Math.round((summary.refunded_minor / summary.revenue_minor) * 100)}%`}
                    />
                    <span className="ds-numeric text-content text-lg font-semibold">
                      {formatMinor(summary.refunded_minor, summary.currency, locale)}
                    </span>
                  </CardContent>
                </Card>
                <Card>
                  <CardHeader>
                    <CardTitle>{t('commerce.glance.attributed')}</CardTitle>
                  </CardHeader>
                  <CardContent className="flex items-center gap-4">
                    <ProgressRing
                      size={110}
                      value={summary.attribution_coverage ?? 0}
                      tone={
                        coverageTone(summary.attribution_coverage) === 'success' ? 'good' : 'watch'
                      }
                      label={t('commerce.glance.attributed')}
                      centre={
                        summary.attribution_coverage === null
                          ? '—'
                          : `${summary.attribution_coverage}%`
                      }
                    />
                    <span className="ds-numeric text-content text-lg font-semibold">
                      {formatMinor(summary.attributed_revenue_minor, summary.currency, locale)}
                    </span>
                  </CardContent>
                </Card>
              </section>

              {summary.pending_orders > 0 ? (
                <Alert tone="warning">
                  {/*
                   * Beside the total, never inside it. Cash on delivery is normal here, so this is not an
                   * edge case — it is most of a busy week, and counting it would overstate every figure
                   * above until the courier either collects or comes back.
                   */}
                  {t('commerce.pending', {
                    count: summary.pending_orders,
                    amount: formatMinor(summary.pending_revenue_minor, summary.currency, locale),
                  })}
                </Alert>
              ) : null}

              {/*
                The daily figures, with a table of the same numbers.

                The breakdowns further down have their own tables already; these days appear
                nowhere else on the page, so this is the only route to them for anyone reading
                without a pointer or without colour.
              */}
              <ChartFrame
                title={t('commerce.trend')}
                subtitle={t('commerce.trendSubtitle')}
                source={summary.source}
                table={{
                  columns: [
                    t('commerce.table.day'),
                    t('commerce.table.netRevenue'),
                    t('commerce.table.refunded'),
                  ],
                  rows: trend.map((point) => ({
                    key: point.date,
                    label:
                      point.is_partial === true
                        ? `${point.date} (${t('commerce.grain.partial')})`
                        : point.date,
                    cells: [
                      formatMinor(point.net_revenue_minor, summary.currency, locale),
                      formatMinor(point.refunded_minor, summary.currency, locale),
                    ],
                  })),
                }}
              >
                {trend.some((point) => point.is_partial === true) ? (
                  <p className="text-content-secondary pb-2 text-xs" data-testid="partial-note">
                    {t('commerce.grain.partialNote', { grain: t(`commerce.grain.${grain}`) })}
                  </p>
                ) : null}
                <RevenueChart points={trend} currency={summary.currency} locale={locale} />
              </ChartFrame>

              <section className="flex flex-col gap-3" aria-labelledby="commerce-attribution">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h2 id="commerce-attribution" className="text-content text-lg font-semibold">
                    {t('commerce.attribution.title')}
                  </h2>
                  <InfoHint text={t('commerce.attribution.hint')} />
                  <div className="flex items-center gap-2">
                    <SourceBadge source={summary.source} />
                    {/*
                     * Coverage sits beside the breakdowns rather than in a footnote. A channel report at
                     * 30% coverage is a different document from one at 95%, and a reader needs to know
                     * which they are holding before they read a single row.
                     */}
                    <Badge tone={coverageTone(summary.attribution_coverage)}>
                      {summary.attribution_coverage === null
                        ? t('commerce.attribution.noCoverage')
                        : t('commerce.attribution.coverage', {
                            percent: summary.attribution_coverage,
                          })}
                    </Badge>
                  </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                  <BreakdownTable
                    title={t('commerce.breakdown.channel')}
                    rows={analytics.data?.by_channel ?? []}
                    currency={summary.currency}
                    locale={locale}
                  />
                  <BreakdownTable
                    title={t('commerce.breakdown.campaign')}
                    rows={analytics.data?.by_campaign ?? []}
                    currency={summary.currency}
                    locale={locale}
                  />
                </div>
              </section>

              {/*
                The card carries its own heading, so this section is labelled by it rather than by a second one
                saying the same thing twice to a screen reader.
              */}
              {analytics.data === undefined ? null : (
                <section className="flex flex-col gap-3" aria-label={t('commerce.mix.title')}>
                  <CustomerMixPanel
                    mix={analytics.data.customer_mix}
                    currency={summary.currency}
                    locale={locale}
                  />
                </section>
              )}

              <section className="flex flex-col gap-3" aria-labelledby="commerce-products">
                <h2 id="commerce-products" className="text-content text-lg font-semibold">
                  {t('commerce.breakdown.product')}
                </h2>
                <BreakdownTable
                  title={t('commerce.breakdown.product')}
                  rows={analytics.data?.by_product ?? []}
                  currency={summary.currency}
                  locale={locale}
                  showUnits
                  hideTitle
                />
              </section>
            </>
          )}
        </>
      )}
    </div>
  );
}

/** Low coverage is a warning, not a neutral fact: it changes what the breakdowns below can be read as. */
function coverageTone(coverage: number | null): 'neutral' | 'success' | 'warning' {
  if (coverage === null) {
    return 'neutral';
  }

  return coverage >= 70 ? 'success' : 'warning';
}
