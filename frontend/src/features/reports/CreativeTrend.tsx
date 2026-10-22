import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatMetric } from '@/shared/metrics';
import { Choice } from '@/shared/ui/choice';
import { TrendChart } from '@/shared/ui/viz';
import type { RenderedSection } from './types';

const METRICS = ['spend', 'ctr', 'cpa', 'conversions', 'roas', 'frequency'] as const;

/**
 * The scope's days, one figure at a time. A switch rather than a stack: spend and click-through do not share an
 * axis, and a chart that put them on one would invent a crossing. A figure the layer did not publish is not offered.
 */
export function CreativeTrend({ section }: { section: RenderedSection }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const available = METRICS.filter((metric) =>
    section.series.some((point) => (point.metrics.values[metric]?.value ?? null) !== null),
  );
  const [metric, setMetric] = useState<(typeof METRICS)[number]>(available[0] ?? 'spend');

  if (available.length === 0 || section.series.length < 2) {
    return null;
  }

  const sample = section.series.find((point) => point.metrics.values[metric] !== undefined)?.metrics
    .values[metric];
  const label = sample?.label ?? metric;

  return (
    <div className="flex flex-col gap-3">
      <div
        className="flex flex-wrap gap-2"
        role="group"
        aria-label={t('reports.creative.trend.metric')}
      >
        {available.map((entry) => (
          <Choice key={entry} isSelected={metric === entry} onSelect={() => setMetric(entry)}>
            {section.series.find((point) => point.metrics.values[entry] !== undefined)?.metrics
              .values[entry]?.label ?? entry}
          </Choice>
        ))}
      </div>
      <TrendChart
        label={`${t('reports.creative.trend.label')} · ${label}`}
        labels={section.series.map((point) => point.date)}
        series={[
          {
            key: metric,
            label,
            values: section.series.map((point) => point.metrics.values[metric]?.value ?? null),
            colour: 'var(--ds-chart-1)',
          },
        ]}
        format={(value) =>
          sample === undefined ? String(value) : formatMetric({ ...sample, value }, locale, true)
        }
        height={200}
      />
    </div>
  );
}
