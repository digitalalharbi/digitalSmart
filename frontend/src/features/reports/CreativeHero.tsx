import { useTranslation } from 'react-i18next';
import { CreativeMediaTile } from '@/features/creatives';
import { localeTag } from '@/shared/lib/format';
import { formatMetric, MISSING } from '@/shared/metrics';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { creativeHero, creativeInsights, creativeRecommendations } from './creativeHighlights';
import type { RenderedReport } from './types';

/**
 * The first screen of a creative report: how many creatives, what they cost and returned, which one led — and the
 * few things worth saying about them, each as a picture and a figure rather than a paragraph.
 *
 * Every figure is the gallery's own totals from the analytics layer, or a row's own value. A figure the layer did
 * not publish is not drawn, so a client sees only what is valid.
 */
export function CreativeHero({ report }: { report: RenderedReport }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const hero = creativeHero(report);

  if (hero === null) {
    return null;
  }

  const show = (key: string): string | null => {
    const value = hero.totals[key];

    return value === undefined || value.value === null ? null : formatMetric(value, locale, true);
  };
  const number = new Intl.NumberFormat(localeTag(locale));
  const percent = new Intl.NumberFormat(localeTag(locale), {
    style: 'percent',
    maximumFractionDigits: 0,
    signDisplay: 'exceptZero',
  });
  const tiles: Array<{ key: string; label: string; value: string }> = [
    ...(hero.creatives === null
      ? []
      : [
          {
            key: 'creatives',
            label: t('reports.creative.hero.creatives'),
            value: number.format(hero.creatives),
          },
        ]),
    ...(['spend', 'conversions', 'ctr', 'cpa'] as const).flatMap((key) => {
      const value = show(key);

      return value === null ? [] : [{ key, label: t(`reports.creative.hero.${key}`), value }];
    }),
  ];
  const insights = creativeInsights(report);
  const advice = creativeRecommendations(report);

  return (
    <section aria-label={t('reports.creative.hero.label')} className="flex flex-col gap-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        {tiles.map((tile) => (
          <KpiTile key={tile.key} label={tile.label} value={tile.value} />
        ))}
        {hero.best === null ? null : (
          <div className="bg-surface border-line-subtle flex items-center gap-3 rounded-2xl border p-3 shadow-xs">
            <CreativeMediaTile
              media={hero.best.media}
              format={hero.best.format}
              label={hero.best.label}
              className="w-16 shrink-0 rounded-lg"
            />
            <div className="min-w-0">
              <p className="text-content-tertiary ds-overline">{t('reports.creative.hero.best')}</p>
              <p className="text-content truncate text-sm font-medium" dir="auto">
                {hero.best.label}
              </p>
              <p className="text-content-secondary ds-numeric text-xs">
                {hero.bestBy === null
                  ? ''
                  : `${hero.bestBy.toUpperCase()} ${formatMetric(hero.best.metrics.values[hero.bestBy] as never, locale, true)}`}
              </p>
            </div>
          </div>
        )}
      </div>

      {insights.length === 0 ? null : (
        <ul
          aria-label={t('reports.creative.insights')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          {insights.map((insight) => (
            <li
              key={insight.key}
              className="bg-surface border-line-subtle flex items-center gap-3 rounded-2xl border p-3 shadow-xs"
            >
              {insight.media === null ? null : (
                <CreativeMediaTile
                  media={insight.media}
                  format="image"
                  label={insight.subject}
                  className="w-14 shrink-0 rounded-lg"
                />
              )}
              <div className="min-w-0">
                <p className="text-content-tertiary ds-overline">
                  {t(`reports.creative.insight.${insight.kind}`)}
                </p>
                <p className="text-content truncate text-sm font-medium" dir="auto">
                  {insight.subject}
                </p>
                <p className="ds-numeric text-content text-lg font-semibold">
                  <bdi dir="ltr">
                    {insight.change !== null
                      ? `CTR ${percent.format(insight.change)}`
                      : insight.value === null
                        ? MISSING
                        : formatMetric(insight.value, locale, true)}
                  </bdi>
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}

      {advice.length === 0 ? null : (
        <ul
          aria-label={t('reports.creative.recommendations')}
          className="grid gap-3 md:grid-cols-3"
        >
          {advice.map((entry) => (
            <li
              key={entry.key}
              className="bg-surface border-line-subtle flex items-start gap-3 rounded-2xl border p-3 shadow-xs"
            >
              {entry.media === null ? null : (
                <CreativeMediaTile
                  media={entry.media}
                  format="image"
                  label={entry.subject}
                  className="w-14 shrink-0 rounded-lg"
                />
              )}
              <div className="min-w-0">
                <p className="text-content text-sm font-semibold" dir="auto">
                  {t(`reports.creative.action.${entry.action}`, { subject: entry.subject })}
                </p>
                <dl className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                  {entry.evidence.map((item) => (
                    <div key={item.label} className="flex items-baseline gap-1">
                      <dt className="text-content-tertiary ds-overline">{item.label}</dt>
                      <dd className="ds-numeric text-content text-sm">
                        <bdi dir="ltr">
                          {item.value === null || item.value.value === null
                            ? MISSING
                            : formatMetric(item.value, locale, true)}
                          {item.change === null ? '' : ` ${percent.format(item.change)}`}
                        </bdi>
                      </dd>
                    </div>
                  ))}
                </dl>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
