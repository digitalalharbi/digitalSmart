import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { usePortfolio } from '@/features/portfolio';
import { compactMoney } from '@/shared/lib/compact-money';
import { formatCompact, formatNumber } from '@/shared/lib/format';
import { cn } from '@/shared/lib/cn';
import { providerName } from '@/shared/lib/providers';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { Skeleton } from '@/shared/ui/skeleton';
import { DonutChart, HEALTH_COLOUR, TrendChart } from '@/shared/ui/viz';
import { HEALTH_TO_STATE, type CampaignControlPage, type HealthValue } from './types';

const ORDER: HealthValue[] = [
  'healthy',
  'watch',
  'needs_attention',
  'critical',
  'insufficient_data',
  'inactive',
];

const MIX = [
  'var(--ds-accent)',
  'var(--ds-action)',
  'color-mix(in oklch, var(--ds-accent) 55%, var(--ds-surface))',
  'color-mix(in oklch, var(--ds-action) 55%, var(--ds-surface))',
  'color-mix(in oklch, var(--ds-accent) 30%, var(--ds-surface))',
  'color-mix(in oklch, var(--ds-action) 30%, var(--ds-surface))',
  'var(--ds-border-strong)',
];

/**
 * The campaigns' picture: five figures, the spend line, health as a ring you can click, and where the money goes.
 *
 * Everything describes what matched the filters, not the page of cards under it. Choosing a slice of the ring
 * filters the list to that health — the same filter the cards below respond to.
 */
export function CampaignsHero({
  meta,
  selected,
  onSelect,
  showMix,
}: {
  meta: CampaignControlPage['meta'] | undefined;
  selected: HealthValue | null;
  onSelect: (health: HealthValue | null) => void;
  showMix: boolean;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const portfolio = usePortfolio();
  const [chosen, setChosen] = useState<string | null>(null);

  if (meta === undefined) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
          {Array.from({ length: 5 }, (_, i) => (
            <Skeleton key={i} className="h-24 rounded-3xl" />
          ))}
        </div>
        <Skeleton className="h-72 rounded-3xl" />
      </div>
    );
  }

  const counts = meta.health_counts;
  const currencies = meta.spend_by_currency.map((entry) => entry.currency);
  const currency = chosen ?? meta.spend_by_currency[0]?.currency ?? 'USD';
  const spend = meta.spend_by_currency.find((entry) => entry.currency === currency);
  const series = meta.spend_trend.find((entry) => entry.currency === currency);

  const labels =
    series === undefined
      ? []
      : series.points.map((_, index) => {
          const day = new Date(`${meta.window.from}T00:00:00Z`);
          day.setUTCDate(day.getUTCDate() + index);

          return day.toLocaleDateString(locale, {
            month: 'short',
            day: 'numeric',
            timeZone: 'UTC',
          });
        });

  const mix = (portfolio.data?.platform_mix ?? [])
    .map((row) => ({
      provider: row.provider,
      minor: row.spend.find((value) => value.currency === currency)?.minor ?? 0,
    }))
    .filter((row) => row.minor > 0)
    .sort((a, b) => b.minor - a.minor);
  const mixTotal = mix.reduce((sum, row) => sum + row.minor, 0);

  return (
    <div className="flex flex-col gap-4">
      <section
        aria-label={t('campaignControl.summary')}
        className="grid grid-cols-2 gap-4 lg:grid-cols-5"
      >
        <KpiTile
          highlight
          label={t('campaignControl.tile.active')}
          value={formatNumber(meta.active_count, locale)}
        />
        <KpiTile
          label={t('campaignControl.tile.healthy')}
          value={formatNumber(counts.healthy, locale)}
        />
        <KpiTile
          label={t('campaignControl.tile.watch')}
          value={formatNumber(counts.watch, locale)}
        />
        <KpiTile
          label={t('campaignControl.tile.attention')}
          value={formatNumber(counts.needs_attention, locale)}
        />
        <KpiTile
          label={t('campaignControl.tile.critical')}
          value={formatNumber(counts.critical, locale)}
        />
      </section>

      <div
        className={cn(
          'grid gap-4',
          showMix && mix.length > 0 ? 'xl:grid-cols-4' : 'xl:grid-cols-3',
        )}
      >
        <Card className={showMix && mix.length > 0 ? 'xl:col-span-2' : 'xl:col-span-2'}>
          <CardHeader className="flex-row flex-wrap items-center justify-between gap-3 pb-3">
            <CardTitle>
              {t('campaignControl.spend.title')}
              {spend === undefined ? null : (
                <span className="ds-numeric text-content-secondary ms-3 font-normal">
                  {compactMoney(spend.spend_minor, spend.currency, locale)}
                </span>
              )}
            </CardTitle>
            {currencies.length > 1 ? (
              <div
                className="bg-surface-sunken inline-flex gap-1 rounded-full p-1"
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
                      'rounded-full px-3 py-1 text-xs font-medium',
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
          </CardHeader>
          <CardContent>
            {series === undefined || labels.length < 2 ? null : (
              <TrendChart
                labels={labels}
                series={[
                  {
                    key: 'spend',
                    label: t('campaignControl.spend.title'),
                    values: series.points.map((v) => (v === null ? null : v / 100)),
                    colour: 'var(--ds-accent)',
                  },
                ]}
                format={(v) => formatCompact(v, locale)}
                label={t('campaignControl.spend.title')}
                height={290}
              />
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle>{t('overview.cc.health.title')}</CardTitle>
          </CardHeader>
          <CardContent>
            <DonutChart
              className="sm:flex-col xl:flex-col"
              size={150}
              label={t('overview.cc.health.title')}
              centre={{
                value: formatNumber(
                  ORDER.reduce((sum, key) => sum + counts[key], 0),
                  locale,
                ),
                caption: t('overview.cc.health.centre'),
              }}
              segments={ORDER.map((key) => ({
                key,
                label: t(`health.${HEALTH_TO_STATE[key]}`),
                value: counts[key],
                colour: HEALTH_COLOUR[key],
              }))}
            />
            <div
              className="mt-3 flex flex-wrap gap-1.5"
              role="group"
              aria-label={t('campaignControl.distribution')}
            >
              {ORDER.filter((key) => counts[key] > 0).map((key) => (
                <button
                  key={key}
                  type="button"
                  aria-pressed={selected === key}
                  onClick={() => onSelect(selected === key ? null : key)}
                  className={cn(
                    'rounded-full px-2.5 py-1 text-xs font-medium',
                    selected === key
                      ? 'bg-action text-action-contrast'
                      : 'bg-surface-sunken text-content-secondary hover:bg-surface-active',
                  )}
                >
                  {t(`health.${HEALTH_TO_STATE[key]}`)}
                </button>
              ))}
            </div>
          </CardContent>
        </Card>

        {showMix && mix.length > 0 ? (
          <Card>
            <CardHeader className="pb-3">
              <CardTitle>{t('overview.cc.platforms.title')}</CardTitle>
            </CardHeader>
            <CardContent>
              <DonutChart
                className="sm:flex-col xl:flex-col"
                size={150}
                label={t('overview.cc.platforms.title')}
                format={(v) => `${String(Math.round((v / mixTotal) * 100))}%`}
                centre={{ value: formatCompact(mixTotal / 100, locale), caption: currency }}
                segments={mix.map((row, index) => ({
                  key: row.provider,
                  label: providerName(row.provider),
                  value: row.minor,
                  colour: MIX[index % MIX.length] ?? 'var(--ds-accent)',
                }))}
              />
            </CardContent>
          </Card>
        ) : null}
      </div>
    </div>
  );
}
