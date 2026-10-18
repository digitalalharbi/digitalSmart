import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ArrowUpRight } from 'lucide-react';
import { ActionCenter } from '@/features/action-center';
import { usePortfolio } from '@/features/portfolio';
import type { Money, Portfolio } from '@/features/portfolio';
import { formatCompact, formatNumber } from '@/shared/lib/format';
import { compactMoney } from '@/shared/lib/compact-money';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { providerName } from '@/shared/lib/providers';
import { cn } from '@/shared/lib/cn';
import { ActivityFeed } from '@/shared/ui/activity-feed';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  ChartCard,
  DonutChart,
  FunnelBars,
  HEALTH_COLOUR,
  RankingBars,
  TrendChart,
} from '@/shared/ui/viz';
import { useAgencyActivity, useSpendTrend } from './useOverviewData';

/*
 * Platform shares are told apart by shade, not by each platform's brand colour: seven brand hues at ring size would
 * be a rainbow, and the legend names every slice anyway. Orange leads, graphite follows, then tints of both.
 */
const MIX_COLOURS = [
  'var(--ds-accent)',
  'var(--ds-action)',
  'color-mix(in oklch, var(--ds-accent) 55%, var(--ds-surface))',
  'color-mix(in oklch, var(--ds-action) 55%, var(--ds-surface))',
  'color-mix(in oklch, var(--ds-accent) 30%, var(--ds-surface))',
  'color-mix(in oklch, var(--ds-action) 30%, var(--ds-surface))',
  'var(--ds-border-strong)',
];

const pick = (values: Money[], currency: string): Money | undefined =>
  values.find((value) => value.currency === currency);

/** The currency with the most money behind it, so the page opens on the one that matters most. */
function dominantCurrency(portfolio: Portfolio): string {
  const totals = new Map<string, number>();

  for (const money of portfolio.kpis.spend) {
    totals.set(money.currency, (totals.get(money.currency) ?? 0) + money.minor);
  }

  return [...totals.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] ?? 'USD';
}

/**
 * The command centre: how the agency is doing, in one screen.
 *
 * A strip of eight figures, one large line, then a handful of pictures — health, who is leading, where the money
 * goes, whether budgets hold, whether leads arrive — and last what needs a person. There is no paragraph on it and
 * no table first; every picture opens the page that has the detail.
 *
 * Money is shown in one currency at a time, chosen at the top, because spend in dirhams, riyals and dollars cannot
 * honestly be added; the figures for the other currencies are one tap away rather than blended.
 */
export function CommandCenter() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const portfolio = usePortfolio();
  const trend = useSpendTrend();
  const activity = useAgencyActivity();
  const [chosen, setChosen] = useState<string | null>(null);

  const data = portfolio.data;
  const currencies = useMemo(
    () => [...new Set((data?.kpis.spend ?? []).map((m) => m.currency))],
    [data],
  );
  const currency = chosen ?? (data === undefined ? 'USD' : dominantCurrency(data));

  if (portfolio.isPending || data === undefined) {
    return (
      <div className="flex flex-col gap-5" aria-busy="true">
        <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} className="h-28 rounded-3xl" />
          ))}
        </div>
        <Skeleton className="h-80 rounded-3xl" />
      </div>
    );
  }

  const k = data.kpis;
  const money = (values: Money[]) => {
    const found = pick(values, currency);

    return found === undefined ? '—' : compactMoney(found.minor, found.currency, locale);
  };
  const roas = k.roas.find((r) => r.currency === currency)?.value;
  const budget = data.budget_health;
  const onTrack = budget === null ? null : budget.approved - budget.at_risk;

  const series = trend.data?.spend_trend.find((s) => s.currency === currency);
  const window = trend.data?.window;
  const labels =
    series === undefined || window === undefined
      ? []
      : series.points.map((_, index) => {
          const day = new Date(`${window.from}T00:00:00Z`);
          day.setUTCDate(day.getUTCDate() + index);

          return day.toLocaleDateString(locale, {
            month: 'short',
            day: 'numeric',
            timeZone: 'UTC',
          });
        });

  const healthSegments = (
    [
      ['healthy', t('health.healthy')],
      ['watch', t('health.watch')],
      ['needs_attention', t('health.attention')],
      ['critical', t('health.critical')],
      ['insufficient_data', t('health.unknown')],
      ['inactive', t('health.inactive')],
    ] as const
  ).map(([key, label]) => ({
    key,
    label,
    value: data.health[key],
    colour: HEALTH_COLOUR[key],
  }));
  const healthTotal = healthSegments.reduce((sum, s) => sum + s.value, 0);

  const ranked = [...data.clients]
    .map((client) => ({ client, spend: pick(client.spend, currency)?.minor ?? 0 }))
    .filter((row) => row.spend > 0)
    .sort((a, b) => b.spend - a.spend)
    .slice(0, 6);

  const mix = data.platform_mix
    .map((row) => ({ provider: row.provider, spend: pick(row.spend, currency)?.minor ?? 0 }))
    .filter((row) => row.spend > 0)
    .sort((a, b) => b.spend - a.spend);
  const mixTotal = mix.reduce((sum, row) => sum + row.spend, 0);

  return (
    <div className="flex flex-col gap-5">
      {currencies.length > 1 ? (
        <div
          className="bg-surface inline-flex w-fit gap-1 rounded-full p-1 shadow-xs"
          role="group"
          aria-label={t('overview.cc.currency')}
        >
          {currencies.map((code) => (
            <button
              key={code}
              type="button"
              aria-pressed={code === currency}
              onClick={() => setChosen(code)}
              className={cn(
                'rounded-full px-3.5 py-1.5 text-sm font-medium',
                code === currency
                  ? 'bg-action text-action-contrast'
                  : 'text-content-secondary hover:bg-surface-hover',
              )}
            >
              {code}
            </button>
          ))}
        </div>
      ) : null}

      {/* Eight figures. */}
      <section
        aria-label={t('overview.performance')}
        className="grid grid-cols-2 gap-4 xl:grid-cols-4"
      >
        <KpiTile
          highlight
          label={t('overview.cc.kpi.spend')}
          value={money(k.spend)}
          to="/analytics"
        />
        <KpiTile
          label={t('overview.cc.kpi.revenue')}
          value={money(k.platform_revenue)}
          to="/analytics"
        />
        <KpiTile
          label={t('overview.cc.kpi.roas')}
          value={roas === undefined ? '—' : `${formatNumber(roas, locale, 2)}×`}
          to="/analytics"
        />
        <KpiTile
          label={t('overview.cc.kpi.leads')}
          value={k.leads === null ? '—' : formatNumber(k.leads, locale)}
          sub={k.leads === null ? t('overview.cc.kpi.notTracked') : undefined}
          to="/leads"
        />
        <KpiTile
          label={t('overview.cc.kpi.sales')}
          value={formatNumber(k.sales, locale)}
          to="/leads"
        />
        <KpiTile
          label={t('overview.cc.kpi.clients')}
          value={formatNumber(k.active_clients, locale)}
          to="/clients"
        />
        <KpiTile
          label={t('overview.cc.kpi.campaigns')}
          value={formatNumber(k.active_campaigns, locale)}
          to="/campaigns"
        />
        <KpiTile
          label={t('overview.cc.kpi.budget')}
          value={onTrack === null ? '—' : formatNumber(onTrack, locale)}
          sub={
            budget === null
              ? t('overview.cc.kpi.notTracked')
              : t('overview.cc.kpi.onTrack', { count: budget.approved })
          }
          to="/budget-control"
        />
      </section>

      {/* One dominant picture, and health beside it. */}
      <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <ChartCard
          title={t('overview.cc.hero.title')}
          to="/analytics"
          actionLabel={t('overview.openAnalytics')}
        >
          {series === undefined || labels.length === 0 ? (
            trend.isPending ? (
              <Skeleton className="h-56 rounded-2xl" />
            ) : (
              <EmptyState title={t('overview.noData.title')} description="" />
            )
          ) : (
            <TrendChart
              labels={labels}
              series={[
                {
                  key: 'spend',
                  label: t('overview.cc.kpi.spend'),
                  values: series.points.map((v) => (v === null ? null : v / 100)),
                  colour: 'var(--ds-accent)',
                },
              ]}
              format={(v) => formatCompact(v, locale)}
              label={t('overview.cc.hero.title')}
              height={280}
            />
          )}
        </ChartCard>

        <ChartCard
          title={t('overview.cc.health.title')}
          to="/campaigns"
          actionLabel={t('overview.cc.openCampaigns')}
        >
          <DonutChart
            segments={healthSegments}
            centre={{
              value: formatNumber(healthTotal, locale),
              caption: t('overview.cc.health.centre'),
            }}
            label={t('overview.cc.health.title')}
            className="sm:flex-col xl:flex-col"
          />
        </ChartCard>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <ChartCard
          title={t('overview.cc.ranking.title')}
          to="/clients"
          actionLabel={t('overview.cc.openClients')}
        >
          {ranked.length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('overview.cc.ranking.none')}</p>
          ) : (
            <RankingBars
              label={t('overview.cc.ranking.title')}
              rows={ranked.map(({ client, spend }) => ({
                key: client.id,
                label: client.name,
                value: spend,
                display: compactMoney(spend, currency, locale),
                to: `/clients/${client.id}`,
                hint: [
                  t('overview.cc.ranking.projects', { count: client.projects }),
                  client.needs_attention > 0
                    ? t('overview.cc.ranking.attention', { count: client.needs_attention })
                    : null,
                ]
                  .filter((part) => part !== null)
                  .join(' · '),
              }))}
            />
          )}
        </ChartCard>

        <ChartCard
          title={t('overview.cc.platforms.title')}
          to="/analytics"
          actionLabel={t('overview.openAnalytics')}
        >
          {mix.length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('overview.cc.ranking.none')}</p>
          ) : (
            <DonutChart
              segments={mix.map((row, index) => ({
                key: row.provider,
                label: providerName(row.provider),
                value: row.spend,
                colour: MIX_COLOURS[index % MIX_COLOURS.length] ?? 'var(--ds-accent)',
              }))}
              format={(v) => `${String(Math.round((v / mixTotal) * 100))}%`}
              centre={{ value: formatCompact(mixTotal / 100, locale), caption: currency }}
              label={t('overview.cc.platforms.title')}
              className="sm:flex-col xl:flex-col"
            />
          )}
        </ChartCard>

        <ChartCard title={t('overview.cc.funnel.title')} to="/leads" actionLabel={t('nav.leads')}>
          <FunnelBars
            label={t('overview.cc.funnel.title')}
            notTracked={t('overview.cc.kpi.notTracked')}
            stages={[
              {
                key: 'leads',
                label: t('overview.cc.funnel.leads'),
                value: k.leads,
                display: formatNumber(k.leads ?? 0, locale),
              },
              {
                key: 'qualified',
                label: t('overview.cc.funnel.qualified'),
                value: k.qualified_leads,
                display: formatNumber(k.qualified_leads ?? 0, locale),
              },
            ]}
          />
        </ChartCard>
      </div>

      <section aria-label={t('overview.cc.attention.title')} className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-content text-lg font-semibold">{t('overview.cc.attention.title')}</h2>
          <Link
            to="/today"
            className="text-content-secondary hover:text-content inline-flex items-center gap-1 text-sm"
          >
            {t('actionCenter.title')}
            <ArrowUpRight aria-hidden className="size-4 rtl:-scale-x-100" />
          </Link>
        </div>
        <ActionCenter limit={6} />
      </section>

      <div className="grid gap-4">
        <ChartCard title={t('overview.cc.activity.title')}>
          <ActivityFeed
            isPending={activity.isPending}
            limit={6}
            entries={(activity.data ?? []).map((row) => ({
              id: row.id,
              actor: row.actor,
              summary: row.sentence,
              at: row.at,
            }))}
          />
        </ChartCard>
      </div>
    </div>
  );
}
