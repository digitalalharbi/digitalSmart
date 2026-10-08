import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientsQuery } from '@/features/clients';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard } from '@/shared/ui/metric-card';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceBadge } from '@/shared/ui/source-badge';
import { ChartFrame } from '@/shared/ui/chart';
import { BreakdownTable } from './BreakdownTable';
import { CustomerMixPanel } from './CustomerMixPanel';
import { RevenueChart } from './RevenueChart';
import { useStoreAnalyticsQuery } from './useCommerce';
import { formatMinor, summaryMetrics, windowFor } from './money';
import type { RevenueSourceValue } from './types';

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

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const window = useMemo(() => windowFor(days), [days]);

  const query = useMemo(
    () => ({ revenue_source: source, from: window.from, until: window.until, client_id: clientId }),
    [source, window, clientId],
  );

  const analytics = useStoreAnalyticsQuery(query);
  const summary = analytics.data?.summary;
  const locale = i18n.language;

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-4">
        <div>
          <h1 className="text-content text-2xl font-semibold">{t('commerce.title')}</h1>
          <p className="text-content-secondary mt-1 max-w-2xl text-sm">{t('commerce.subtitle')}</p>
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
          <Alert tone={summary.is_modelled ? 'warning' : 'info'}>
            <p>
              {summary.is_invoiced
                ? t('commerce.claim.invoiced', { source: summary.revenue_source_label })
                : summary.is_modelled
                  ? t('commerce.claim.modelled', { source: summary.revenue_source_label })
                  : t('commerce.claim.reported', { source: summary.revenue_source_label })}
            </p>
          </Alert>

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
                {summaryMetrics(summary, {
                  netRevenue: t('commerce.metrics.netRevenue'),
                  orders: t('commerce.metrics.orders'),
                  aov: t('commerce.metrics.aov'),
                  customers: t('commerce.metrics.customers'),
                  noOrders: t('commerce.metrics.noOrders'),
                }).map((metric) => (
                  <MetricCard key={metric.metric} value={metric} />
                ))}
              </section>

              <p className="text-content-secondary text-sm">
                {t('commerce.metrics.netRevenueHint', {
                  gross: formatMinor(summary.revenue_minor, summary.currency, locale),
                  refunded: formatMinor(summary.refunded_minor, summary.currency, locale),
                  units: new Intl.NumberFormat(locale).format(summary.units),
                })}
              </p>

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
                  rows: (analytics.data?.daily ?? []).map((point) => ({
                    key: point.date,
                    label: point.date,
                    cells: [
                      formatMinor(point.net_revenue_minor, summary.currency, locale),
                      formatMinor(point.refunded_minor, summary.currency, locale),
                    ],
                  })),
                }}
              >
                <RevenueChart
                  points={analytics.data?.daily ?? []}
                  currency={summary.currency}
                  locale={locale}
                />
              </ChartFrame>

              <section className="flex flex-col gap-3" aria-labelledby="commerce-attribution">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h2 id="commerce-attribution" className="text-content text-lg font-semibold">
                    {t('commerce.attribution.title')}
                  </h2>
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

                <p className="text-content-secondary max-w-3xl text-sm">
                  {t('commerce.attribution.hint')}
                </p>

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
