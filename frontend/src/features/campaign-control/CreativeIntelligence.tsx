import {
  AlertTriangle,
  Image as ImageIcon,
  Sparkles,
  TrendingDown,
  TrendingUp,
  Video,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useAnalytics } from '@/features/analytics';
import { cn } from '@/shared/lib/cn';
import { formatMetric, MISSING } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { useUiStore } from '@/shared/store/ui-store';
import { Choice } from '@/shared/ui/choice';
import { DonutChart, RankingBars } from '@/shared/ui/viz';
import {
  creativeItems,
  creativeViews,
  formatMix,
  type CreativeItem,
  type CreativeState,
} from './creativeIntel';
import { useCampaignHierarchy } from './hierarchyData';

const METRICS = ['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'conversions', 'cpa', 'roas'];
const VIEWS = ['top', 'rising', 'tiring', 'replace'] as const;
type View = (typeof VIEWS)[number];
const COMPARE_LIMIT = 4;
/** The figures a comparison puts side by side, and whether lower is the better. */
const COMPARED: Array<{ key: string; lowerIsBetter: boolean }> = [
  { key: 'spend', lowerIsBetter: false },
  { key: 'ctr', lowerIsBetter: false },
  { key: 'cpc', lowerIsBetter: true },
  { key: 'cpa', lowerIsBetter: true },
  { key: 'roas', lowerIsBetter: false },
  { key: 'conversions', lowerIsBetter: false },
];
const COLOURS = [
  'var(--ds-chart-1)',
  'var(--ds-chart-2)',
  'var(--ds-chart-3)',
  'var(--ds-chart-4)',
];

const STATE_ICON: Record<CreativeState, typeof TrendingUp> = {
  rising: TrendingUp,
  steady: Sparkles,
  tiring: TrendingDown,
  needs_replacing: AlertTriangle,
  not_enough_data: Sparkles,
};

/**
 * A campaign's creatives, media first: what each looks like, how it is doing, and which are wearing out.
 *
 * Four views of the same ads — the strongest, the rising, the tiring, the ones that need replacing — and a comparison of
 * two to four, drawn as bars so the winner is the one you see. Every label comes from `creativeIntel`'s plain rules over
 * the platform's own figures, and a creative seen too little to judge is in none of the lists and says so.
 *
 * What is **not** here: organic content (no reach or engagement is synced for it), audience or placement cuts, and a
 * workspace-wide view (there is no aggregate read of creatives across campaigns yet).
 */
export function CreativeIntelligence({ campaignId }: { campaignId: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const period = useUiStore((state) => state.period);
  const hierarchy = useCampaignHierarchy(campaignId, campaignId !== '');
  const analytics = useAnalytics(
    {
      preset: ({ 7: '7d', 30: '30d', 90: '90d' } as const)[period.days],
      dimension: 'ad',
      metrics: METRICS,
      order_by: 'spend',
      limit: 50,
      campaign_ids: [campaignId],
    },
    campaignId !== '',
  );
  const [view, setView] = useState<View>('top');
  const [chosen, setChosen] = useState<string[]>([]);

  const items = useMemo(() => {
    if (hierarchy.data === undefined) {
      return [];
    }

    const figures = Object.fromEntries(
      (analytics.data?.breakdown ?? []).map((row) => [row.key, row.values]),
    );

    return creativeItems(hierarchy.data.groups, figures);
  }, [hierarchy.data, analytics.data]);

  const views = useMemo(() => creativeViews(items), [items]);
  const mix = useMemo(() => formatMix(items), [items]);

  if (items.length === 0) {
    return null;
  }

  const shown = views[view];
  const compared = chosen
    .map((id) => items.find((item) => item.id === id))
    .filter((item): item is CreativeItem => item !== undefined);
  const toggle = (id: string): void =>
    setChosen((current) =>
      current.includes(id)
        ? current.filter((entry) => entry !== id)
        : current.length >= COMPARE_LIMIT
          ? current
          : [...current, id],
    );
  const show = (value: MetricValue | undefined): string =>
    value === undefined ? MISSING : formatMetric(value, locale, true);

  return (
    <section aria-label={t('creativeIntel.title')} className="flex flex-col gap-4">
      <h2 className="text-content text-[0.9375rem] font-semibold">{t('creativeIntel.title')}</h2>

      <div className="flex flex-wrap gap-2" role="group" aria-label={t('creativeIntel.views')}>
        {VIEWS.map((entry) => (
          <Choice key={entry} isSelected={view === entry} onSelect={() => setView(entry)}>
            {t(`creativeIntel.view.${entry}`)} ·{' '}
            {new Intl.NumberFormat(locale).format(views[entry].length)}
          </Choice>
        ))}
      </div>

      {shown.length === 0 ? (
        <p className="text-content-tertiary text-sm">{t('creativeIntel.none')}</p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map((item) => {
            const Icon = STATE_ICON[item.state];
            const isChosen = chosen.includes(item.id);
            const change = item.ctrChange;

            return (
              <li
                key={item.id}
                className={cn(
                  'border-line-subtle bg-surface flex min-w-0 flex-col overflow-hidden rounded-2xl border',
                  isChosen && 'border-accent-strong',
                )}
              >
                <div className="bg-surface-sunken relative grid aspect-video place-items-center">
                  {item.creative?.thumbnail_url ? (
                    <img
                      src={item.creative.thumbnail_url}
                      alt={item.creative.name}
                      className="size-full object-cover"
                      loading="lazy"
                    />
                  ) : item.format === 'video' ? (
                    <Video aria-hidden className="text-content-tertiary size-8" />
                  ) : (
                    <ImageIcon aria-hidden className="text-content-tertiary size-8" />
                  )}
                  <span className="bg-surface text-content absolute start-2 top-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium">
                    <Icon aria-hidden className="size-3" />
                    {t(`creativeIntel.state.${item.state}`)}
                  </span>
                </div>

                <div className="flex min-w-0 flex-col gap-2 p-3">
                  <p className="text-content truncate text-sm font-medium" dir="auto">
                    {item.name}
                  </p>
                  <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                    <Figure label={t('creativeIntel.spend')} value={show(item.figures['spend'])} />
                    <Figure
                      label="CTR"
                      value={show(item.figures['ctr'])}
                      hint={
                        change === null
                          ? null
                          : `${change > 0 ? '+' : ''}${new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 }).format(change)}`
                      }
                    />
                    <Figure
                      label={item.figures['roas'] === undefined ? 'CPA' : 'ROAS'}
                      value={show(item.figures['roas'] ?? item.figures['cpa'])}
                    />
                    <Figure
                      label={t('creativeIntel.frequency')}
                      value={
                        item.frequency === null
                          ? MISSING
                          : new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(
                              item.frequency,
                            )
                      }
                    />
                  </dl>
                  <label className="text-content-secondary flex items-center gap-2 text-xs">
                    <input
                      type="checkbox"
                      checked={isChosen}
                      disabled={!isChosen && chosen.length >= COMPARE_LIMIT}
                      onChange={() => toggle(item.id)}
                    />
                    {t('creativeIntel.compare')}
                    <span className="sr-only"> — {item.name}</span>
                  </label>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {compared.length < 2 ? (
        chosen.length === 1 ? (
          <p className="text-content-tertiary text-xs">{t('creativeIntel.pickOne')}</p>
        ) : null
      ) : (
        <div
          className="grid gap-4 md:grid-cols-2"
          aria-label={t('creativeIntel.comparison')}
          role="group"
        >
          {COMPARED.flatMap(({ key, lowerIsBetter }) => {
            const rows = compared.flatMap((item, index) => {
              const value = item.figures[key];

              return value?.value == null ? [] : [{ item, index, value }];
            });

            if (rows.length < 2) {
              return [];
            }

            const best = rows.reduce((top, row) =>
              (
                lowerIsBetter
                  ? (row.value.value as number) < (top.value.value as number)
                  : (row.value.value as number) > (top.value.value as number)
              )
                ? row
                : top,
            );

            return [
              <div key={key} className="flex min-w-0 flex-col gap-2">
                <h3 className="text-content-secondary text-xs font-medium">
                  {rows[0]?.value.label}
                </h3>
                <RankingBars
                  label={rows[0]?.value.label ?? key}
                  rows={rows.map(({ item, index, value }) => ({
                    key: item.id,
                    label: item.name,
                    value: value.value,
                    display: formatMetric(value, locale, true),
                    colour: COLOURS[index] ?? 'var(--ds-chart-1)',
                    ...(item.id === best.item.id ? { hint: t('creativeIntel.best') } : {}),
                  }))}
                />
              </div>,
            ];
          })}
        </div>
      )}

      {mix.length < 2 ? null : (
        <div className="grid gap-4 md:grid-cols-2">
          {(
            [
              ['spend', 'creativeIntel.spendByFormat'],
              ['results', 'creativeIntel.resultsByFormat'],
            ] as const
          ).map(([field, label]) => {
            const total = mix.reduce((sum, entry) => sum + entry[field], 0);

            return total <= 0 ? null : (
              <div key={field} className="flex min-w-0 flex-col gap-2">
                <h3 className="text-content text-sm font-medium">{t(label)}</h3>
                <DonutChart
                  label={t(label)}
                  segments={mix
                    .filter((entry) => entry[field] > 0)
                    .map((entry, index) => ({
                      key: entry.format,
                      label: t(`creativeIntel.format.${entry.format}`, {
                        defaultValue: entry.format,
                      }),
                      value: entry[field],
                      colour: COLOURS[index] ?? 'var(--ds-chart-4)',
                    }))}
                  format={(value) =>
                    new Intl.NumberFormat(locale, {
                      style: 'percent',
                      maximumFractionDigits: 0,
                    }).format(value / total)
                  }
                />
              </div>
            );
          })}
        </div>
      )}
    </section>
  );
}

function Figure({ label, value, hint }: { label: string; value: string; hint?: string | null }) {
  return (
    <div className="flex min-w-0 flex-col">
      <dt className="text-content-tertiary ds-overline truncate">{label}</dt>
      <dd className="ds-numeric text-content truncate text-sm">
        {value}
        {hint === null || hint === undefined ? null : (
          <span className="text-content-secondary ms-1 text-xs">
            <bdi dir="ltr">{hint}</bdi>
          </span>
        )}
      </dd>
    </div>
  );
}
