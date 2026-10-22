import { useTranslation } from 'react-i18next';
import { formatMetric, MISSING } from '@/shared/metrics';
import { ChartFrame } from '@/shared/ui/chart';
import { FunnelBars, type FunnelStage } from '@/shared/ui/viz';
import type { RenderedSection } from './types';

/** The funnel's stages, in order, and the rates read beside them. Fixed by the server; named here only to be drawn. */
const STAGES = ['impressions', 'clicks', 'link_clicks', 'conversions'] as const;
const RATES = ['ctr', 'cpc', 'conversion_rate'] as const;

/**
 * Clicks and the funnel: how many people saw an ad, clicked it, followed its link and did what the campaign asked.
 *
 * Every figure is the server's. A stage the platforms did not report is drawn as "not tracked", never as a short bar,
 * and the rate between two stages is absent where it would be a rate over a different population (see `funnelSteps`).
 */
export function TrafficSectionView({ section }: { section: RenderedSection }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const values = section.totals?.values ?? {};

  const stages: FunnelStage[] = STAGES.map((metric) => {
    const value = values[metric];

    return {
      key: metric,
      label: t(`reports.traffic.stages.${metric}`),
      value: value?.value ?? null,
      display: value === undefined ? MISSING : formatMetric(value, locale),
    };
  });

  return (
    <ChartFrame
      title={section.title}
      caveats={section.caveats}
      isEmpty={section.is_empty}
      emptyTitle={t('reports.section.emptyTitle')}
      emptyBody={t('reports.section.emptyBody')}
      table={{
        columns: [t('reports.table.metric'), t('reports.table.value')],
        rows: [...STAGES, ...RATES].map((metric) => {
          const value = values[metric];

          return {
            key: metric,
            label: t(
              `reports.traffic.${STAGES.includes(metric as never) ? 'stages' : 'rates'}.${metric}`,
            ),
            cells: [value === undefined ? MISSING : formatMetric(value, locale)],
          };
        }),
      }}
    >
      <div className="flex flex-col gap-5">
        <FunnelBars
          stages={stages}
          label={t('reports.traffic.label')}
          notTracked={t('reports.traffic.notTracked')}
          leakFrom={2}
          leakLabel={t('reports.traffic.leak')}
        />
        <dl className="grid grid-cols-3 gap-3">
          {RATES.map((metric) => {
            const value = values[metric];

            return (
              <div
                key={metric}
                className="bg-surface-sunken flex min-w-0 flex-col gap-1 rounded-2xl p-4"
              >
                <dt className="text-content-tertiary ds-overline">
                  {t(`reports.traffic.rates.${metric}`)}
                </dt>
                <dd className="ds-figure text-content text-xl">
                  {value === undefined ? MISSING : formatMetric(value, locale)}
                </dd>
              </div>
            );
          })}
        </dl>
      </div>
    </ChartFrame>
  );
}
