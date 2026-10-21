import { AlertTriangle, Lightbulb, Star, TrendingDown, TrendingUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { MISSING } from '@/shared/metrics';
import { fromMinor } from '@/shared/lib/money';
import { formatCurrency, formatMinor, localeTag } from '@/shared/lib/format';
import { DonutChart, RankingBars, TrendChart } from '@/shared/ui/viz';
import { refundPoints, storeAdvice, storeInsights, type StoreInsight } from './storeHighlights';
import type { StoreDetail } from './types';

const COLOURS = [
  'var(--ds-chart-1)',
  'var(--ds-chart-2)',
  'var(--ds-chart-3)',
  'var(--ds-chart-4)',
  'var(--ds-chart-5)',
  'var(--ds-chart-6)',
];

const ICON: Record<StoreInsight['kind'], typeof Star> = {
  best: Star,
  improved: TrendingUp,
  declined: TrendingDown,
  attention: AlertTriangle,
};

const DAY = 86_400_000;

/**
 * Every day from the first order to the last, with a day nobody ordered on as a gap.
 *
 * Not a zero: this section cannot tell "no orders" from "not synced", and a line dropping to zero across a sync gap
 * would be read as a collapse. Nothing is interpolated either — the line breaks.
 */
function dailySeries(detail: StoreDetail): { labels: string[]; dates: string[] } {
  const first = detail.daily[0];
  const last = detail.daily[detail.daily.length - 1];

  if (first === undefined || last === undefined) {
    return { labels: [], dates: [] };
  }

  const start = Date.parse(`${first.date}T00:00:00Z`);
  const end = Date.parse(`${last.date}T00:00:00Z`);
  const dates: string[] = [];

  for (let at = start; at <= end; at += DAY) {
    dates.push(new Date(at).toISOString().slice(0, 10));
  }

  return { labels: dates, dates };
}

/**
 * What sits behind a store section's headline figures, in the order a client reads it: the trend, the orders, the
 * stores, where the orders came from, the products, the customers, the refunds, and what stands out.
 *
 * A block with nothing to show is absent, not an empty card. Nothing here is a conversion rate or a funnel: a store
 * report holds no sessions, and dividing orders by ad clicks and calling it the store's conversion rate would be
 * a different claim under the same name.
 */
export function StoreDetailView({
  detail,
  orders,
  authority,
}: {
  detail: StoreDetail;
  /** The section's own order count, which the insights read. */
  orders: number;
  authority: string;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const money = (minor: number): string => formatMinor(minor, detail.currency, locale);
  const count = (value: number): string => new Intl.NumberFormat(localeTag(locale)).format(value);
  const compact = (value: number): string =>
    new Intl.NumberFormat(localeTag(locale), {
      notation: 'compact',
      maximumFractionDigits: 1,
    }).format(value);
  const percent = (value: number): string =>
    new Intl.NumberFormat(localeTag(locale), { style: 'percent', maximumFractionDigits: 1 }).format(
      value / 100,
    );
  const day = (iso: string): string =>
    new Intl.DateTimeFormat(localeTag(locale), {
      month: 'short',
      day: 'numeric',
      timeZone: 'UTC',
    }).format(new Date(`${iso}T00:00:00Z`));

  const { dates } = dailySeries(detail);
  const byDate = new Map(detail.daily.map((point) => [point.date, point]));
  const found = storeInsights(detail, orders);
  const advice = storeAdvice(detail);
  const points = refundPoints(detail);
  const named = detail.channels.filter((row) => !row.is_unattributed && row.label !== null);
  const unattributed = detail.channels.find((row) => row.is_unattributed);
  const mix = detail.customers;
  const hasRevenue = detail.daily.some((point) => point.net_revenue > 0);
  const fill = (values: Record<string, string | number>): Record<string, string | number> => ({
    ...values,
    ...(typeof values['date'] === 'string' ? { date: day(values['date']) } : {}),
  });

  return (
    <div className="mt-5 flex flex-col gap-5">
      {dates.length < 2 || !hasRevenue ? null : (
        <>
          <section className="flex min-w-0 flex-col gap-2">
            <h4 className="text-content text-sm font-medium">
              {t('reports.commerce.revenueTrend', { source: authority })}
            </h4>
            <TrendChart
              label={t('reports.commerce.revenueTrend', { source: authority })}
              labels={dates.map(day)}
              series={[
                {
                  key: 'revenue',
                  label: t('reports.commerce.netRevenue'),
                  colour: COLOURS[0] ?? 'var(--ds-chart-1)',
                  values: dates.map((date) => {
                    const point = byDate.get(date);

                    return point === undefined
                      ? null
                      : fromMinor(point.net_revenue, detail.currency);
                  }),
                },
              ]}
              format={(value) => formatCurrency(value, detail.currency, locale)}
              tickFormat={compact}
            />
            <p className="text-content-tertiary text-xs">{t('reports.commerce.gapNote')}</p>
          </section>

          <section className="flex min-w-0 flex-col gap-2">
            <h4 className="text-content text-sm font-medium">
              {t('reports.commerce.ordersTrend')}
            </h4>
            <TrendChart
              label={t('reports.commerce.ordersTrend')}
              labels={dates.map(day)}
              series={[
                {
                  key: 'orders',
                  label: t('reports.commerce.orders'),
                  colour: COLOURS[1] ?? 'var(--ds-chart-2)',
                  values: dates.map((date) => byDate.get(date)?.orders ?? null),
                },
              ]}
              format={count}
              /* A fifth of an order is not a thing: only whole-number ticks are written. */
              tickFormat={(value) => (Number.isInteger(value) ? compact(value) : '')}
            />
          </section>
        </>
      )}

      {detail.stores.length < 2 ? null : (
        <section className="flex min-w-0 flex-col gap-2">
          <h4 className="text-content text-sm font-medium">{t('reports.commerce.stores')}</h4>
          <RankingBars
            label={t('reports.commerce.stores')}
            rows={detail.stores.map((row) => ({
              key: row.name,
              label: row.name,
              value: row.net_revenue,
              display: money(row.net_revenue),
              hint: [
                row.share === null ? null : percent(row.share),
                t('reports.commerce.ordersCount', { count: row.orders }),
                row.aov === null ? null : t('reports.commerce.aovShort', { value: money(row.aov) }),
                row.refunded > 0
                  ? t('reports.commerce.refundedShort', { value: money(row.refunded) })
                  : null,
              ]
                .filter((part): part is string => part !== null)
                .join(' · '),
            }))}
          />
        </section>
      )}

      {named.length === 0 ? null : (
        <section className="flex min-w-0 flex-col gap-2">
          <h4 className="text-content text-sm font-medium">{t('reports.commerce.channels')}</h4>
          <DonutChart
            label={t('reports.commerce.channels')}
            segments={[
              ...named.slice(0, 5).map((row, index) => ({
                key: row.label ?? String(index),
                label: row.label ?? '',
                value: row.net_revenue,
                colour: COLOURS[index] ?? 'var(--ds-chart-6)',
              })),
              ...(unattributed === undefined || unattributed.net_revenue <= 0
                ? []
                : [
                    {
                      key: 'unattributed',
                      label: t('reports.commerce.unattributed'),
                      value: unattributed.net_revenue,
                      colour: 'var(--ds-border-strong, #9ca3af)',
                    },
                  ]),
            ]}
            centre={
              detail.attribution_coverage === null
                ? undefined
                : {
                    value: percent(detail.attribution_coverage),
                    caption: t('reports.commerce.traced'),
                  }
            }
            format={(value) => money(value)}
          />
          <p className="text-content-tertiary text-xs">{t('reports.commerce.channelsNote')}</p>
        </section>
      )}

      {detail.products.length === 0 ? null : (
        <section className="flex min-w-0 flex-col gap-2">
          <h4 className="text-content text-sm font-medium">{t('reports.commerce.products')}</h4>
          <RankingBars
            label={t('reports.commerce.products')}
            rows={detail.products.map((row) => ({
              key: row.label,
              label: row.label,
              value: row.revenue,
              display: money(row.revenue),
              hint: `${count(row.units)} ${t('reports.commerce.unitsLabel')} · ${t('reports.commerce.ordersCount', { count: row.orders })}`,
            }))}
          />
          <p className="text-content-tertiary text-xs">{t('reports.commerce.productsNote')}</p>
        </section>
      )}

      {mix === null || mix.new + mix.returning + mix.unknown === 0 ? null : (
        <section className="flex min-w-0 flex-col gap-2">
          <h4 className="text-content text-sm font-medium">{t('reports.commerce.customers')}</h4>
          <DonutChart
            label={t('reports.commerce.customers')}
            segments={[
              {
                key: 'new',
                label: t('reports.commerce.newCustomers'),
                value: mix.new,
                colour: COLOURS[0] ?? '',
              },
              {
                key: 'returning',
                label: t('reports.commerce.returningCustomers'),
                value: mix.returning,
                colour: COLOURS[1] ?? '',
              },
              ...(mix.unknown > 0
                ? [
                    {
                      key: 'unknown',
                      label: t('reports.commerce.unknownCustomers'),
                      value: mix.unknown,
                      colour: 'var(--ds-border-strong, #9ca3af)',
                    },
                  ]
                : []),
            ].filter((segment) => segment.value > 0)}
            centre={{
              value: count(mix.new + mix.returning + mix.unknown),
              caption: t('reports.commerce.customersCaption'),
            }}
            format={count}
          />
          {mix.unavailable_reason === null ? null : (
            <p className="text-content-tertiary text-xs">{mix.unavailable_reason}</p>
          )}
        </section>
      )}

      {detail.refunds.amount === 0 && detail.refunds.rate === null ? null : (
        <section className="flex min-w-0 flex-col gap-2">
          <h4 className="text-content text-sm font-medium">{t('reports.commerce.refunds')}</h4>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <RefundTile
              label={t('reports.commerce.refundAmount')}
              value={money(detail.refunds.amount)}
            />
            <RefundTile
              label={t('reports.commerce.refundOrders')}
              value={count(detail.refunds.orders)}
            />
            <RefundTile
              label={t('reports.commerce.refundRate')}
              value={detail.refunds.rate === null ? MISSING : percent(detail.refunds.rate)}
              warn={points !== null && points >= 1}
            />
          </div>
          {points !== null && points >= 1 ? (
            <p className="text-content-secondary flex items-center gap-2 text-xs">
              <AlertTriangle aria-hidden className="size-3.5 shrink-0" />
              {t('reports.commerce.refundMoved', {
                points: new Intl.NumberFormat(localeTag(locale)).format(points),
              })}
            </p>
          ) : null}
          <p className="text-content-tertiary text-xs">{t('reports.commerce.refundNote')}</p>
        </section>
      )}

      {found.length === 0 ? null : (
        <ul
          aria-label={t('reports.commerce.insightsLabel')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
          {found.map((insight) => {
            const Icon = ICON[insight.kind];

            return (
              <li
                key={insight.key}
                className="border-line-subtle bg-surface flex min-w-0 items-start gap-3 rounded-2xl border p-4"
              >
                <Icon aria-hidden className="text-content-brand mt-0.5 size-4 shrink-0" />
                <p className="text-content min-w-0 text-sm leading-snug">
                  {t(`reports.commerce.insight.${insight.text}`, fill(insight.values))}
                </p>
              </li>
            );
          })}
        </ul>
      )}

      {advice.length === 0 ? null : (
        <ul aria-label={t('reports.commerce.advice')} className="grid gap-3 md:grid-cols-2">
          {advice.map((item) => (
            <li
              key={item.key}
              className="border-line-subtle bg-accent-subtle/40 flex min-w-0 items-start gap-3 rounded-2xl border p-4"
            >
              <Lightbulb aria-hidden className="text-content-brand mt-0.5 size-4 shrink-0" />
              <div className="min-w-0">
                <p className="text-content text-sm font-medium">
                  {t(`reports.commerce.adviceText.${item.text}`, item.values)}
                </p>
                <p className="text-content-secondary text-xs">
                  {t(`reports.commerce.adviceText.${item.evidence}`, item.values)}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function RefundTile({
  label,
  value,
  warn = false,
}: {
  label: string;
  value: string;
  warn?: boolean;
}) {
  return (
    <div className="bg-surface-sunken flex min-w-0 flex-col gap-1 rounded-2xl p-4">
      <span className="text-content-tertiary ds-overline">{label}</span>
      <span className={warn ? 'ds-figure text-warning text-xl' : 'ds-figure text-content text-xl'}>
        {value}
      </span>
    </div>
  );
}
