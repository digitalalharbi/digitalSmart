import { useTranslation } from 'react-i18next';
import { formatMetric } from '@/shared/metrics';
import type { AnalyticsPayload, MetricValue } from '@/shared/metrics';
import { Choice } from '@/shared/ui/choice';
import { TrendChart } from '@/shared/ui/viz';
import { HERO_METRICS } from './heroMetrics';

/**
 * One figure over the period, as the analytics endpoint reports it day by day, with a choice of which.
 *
 * The values are plotted as the server sent them — a currency in minor units — and written back through the one metric
 * formatter, so the axis, the tooltip and the figure beside the chart cannot disagree about a unit. A day with no figure
 * is a gap in the line. Two metrics are never put on one axis: a campaign's spend and its click-through rate share
 * nothing, and a second scale would invent a relationship.
 */
export function HeroTrend({
  data,
  metric,
  onMetric,
  label,
  labels,
}: {
  data: AnalyticsPayload;
  metric: string;
  onMetric: (metric: string) => void;
  /** The chart's accessible name. */
  label: string;
  /** Display names for each metric. */
  labels: ReadonlyMap<string, string>;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const points = data.series;
  const sample: MetricValue | undefined = points
    .map((point) => point.values[metric])
    .find((value): value is MetricValue => value !== undefined);
  const day = (iso: string): string =>
    new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(
      new Date(`${iso}T00:00:00Z`),
    );

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-2" role="group" aria-label={t('analytics.hero.metric')}>
        {HERO_METRICS.filter((entry) => data.metrics.includes(entry)).map((entry) => (
          <Choice key={entry} isSelected={metric === entry} onSelect={() => onMetric(entry)}>
            {labels.get(entry) ?? entry}
          </Choice>
        ))}
      </div>

      {/*
        A money figure with no currency is not drawn: the formatter would fall back to a default currency, and a chart
        labelled in a currency nobody reported is worse than no chart.
      */}
      {sample === undefined ||
      points.length < 2 ||
      (sample.format === 'currency' && sample.currency === null) ? null : (
        <TrendChart
          label={label}
          labels={points.map((point) => day(point.date))}
          series={[
            {
              key: metric,
              label: labels.get(metric) ?? metric,
              colour: 'var(--ds-chart-1)',
              values: points.map((point) => point.values[metric]?.value ?? null),
            },
          ]}
          format={(value) => formatMetric({ ...sample, value }, locale)}
          tickFormat={(value) => formatMetric({ ...sample, value }, locale, true)}
        />
      )}
    </div>
  );
}
