import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact, formatMinor, formatNumber, formatRate } from '@/shared/lib/format';
import { fromMinor } from '@/shared/lib/money';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  legend,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import type { AttributionComparison, AttributionModelInfo } from './types';

type ModelComparisonProps = {
  comparison: AttributionComparison;
  models: AttributionModelInfo[];
};

/**
 * The same campaigns under every model that can be computed.
 *
 * The most honest chart in this product, and the reason the comparison endpoint exists. A page with a
 * model selector teaches a reader that one of the options is the right one. This teaches the opposite,
 * which is the truth: the models disagree by construction, and the size of the disagreement on this
 * client's own campaigns is the only way to say how much the choice matters.
 *
 * Grouped bars rather than stacked, because the three figures are **alternative** accounts of the same
 * money and stacking them would add up to a total that exists nowhere. Three series is inside the
 * measured limit for a form where only neighbours touch, and a legend is present because identity must
 * never rest on colour alone.
 */
export function ModelComparison({ comparison, models }: ModelComparisonProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const labels = useMemo(() => {
    const byModel = new Map(models.map((model) => [model.model, model.label]));

    return comparison.models.map((model) => byModel.get(model) ?? model);
  }, [comparison.models, models]);

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const ordered = [...comparison.campaigns].reverse();
    /*
     * One axis, so one currency. A scale is only meaningful if every bar on it is in the
     * same unit, and the server withholds any figure it cannot name a single currency
     * for — so the first named currency is the axis's, and it decides how many minor
     * units make a major one.
     */
    const currency = ordered.find((row) => row.currency !== null)?.currency ?? null;

    return {
      ...base,
      grid: { top: 30, bottom: 8, left: 8, right: 8, containLabel: true },
      legend: legend({ locale, isRtl }, palette, comparison.models.length),
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        formatter: (params: unknown) => {
          const points = params as { dataIndex: number }[];
          const row = ordered[points[0]?.dataIndex ?? 0];

          if (row === undefined) {
            return '';
          }

          const lines = [`<strong>${row.label}</strong>`];

          comparison.models.forEach((model, index) => {
            const value = row.revenue_minor_by_model[model];

            lines.push(
              `${labels[index] ?? model}: ${
                value === null || value === undefined || row.currency === null
                  ? t('attribution.comparison.notInRows')
                  : formatMinor(value, row.currency, locale)
              }`,
            );
          });

          if (row.spread_percent !== null) {
            lines.push(
              `${t('attribution.comparison.spread')}: ${formatRate(row.spread_percent, locale)}`,
            );
          }

          return lines.join('<br/>');
        },
      },
      xAxis: {
        type: 'value',
        inverse: isRtl,
        splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: {
          color: palette.axisLabel,
          fontSize: 11,
          formatter: (value: number) => formatCompact(fromMinor(value, currency), locale),
        },
      },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: ordered.map((row) => row.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11, width: 190, overflow: 'truncate' },
      },
      series: comparison.models.map((model, index) => ({
        ...barSeries(palette, {
          name: labels[index] ?? model,
          /*
           * Null stays null. A campaign the model's truncated breakdown did not include is unknown
           * for that model, and drawing a zero-length bar would read as "this model gave it nothing".
           */
          data: ordered.map((row) => row.revenue_minor_by_model[model] ?? null),
          orientation: 'horizontal',
          isRtl,
          colour: seriesColour(palette, index),
        }),
      })),
    };
  }, [comparison, labels, isRtl, locale, t]);

  return (
    <div className="flex flex-col gap-3">
      {/*
        The headline, and the only sentence on this page that is unambiguously true: on this many
        campaigns the choice of model moves the figure enough to change a decision.

        Outside the frame rather than inside it, so it stays on screen in the table view as well. It is
        a statement about both representations, and a reader who has switched to the table has not
        stopped needing it.
      */}
      <Alert tone={comparison.material_disagreements > 0 ? 'warning' : 'info'}>
        {comparison.material_disagreements > 0
          ? t('attribution.comparison.headline', { count: comparison.material_disagreements })
          : t('attribution.comparison.agree')}
      </Alert>

      <ChartFrame
        title={t('attribution.comparison.title')}
        subtitle={t('attribution.comparison.subtitle')}
        caveats={[t('attribution.comparison.caveat')]}
        isEmpty={comparison.campaigns.length === 0}
        emptyTitle={t('attribution.comparison.emptyTitle')}
        emptyBody={t('attribution.comparison.emptyBody')}
        table={{
          columns: [t('attribution.table.campaign'), ...labels, t('attribution.comparison.spread')],
          rows: comparison.campaigns.map((row) => ({
            key: row.label,
            label: (
              <span className="flex flex-wrap items-center gap-1.5">
                {row.label}
                {row.is_material ? (
                  <Badge tone="warning">{t('attribution.comparison.material')}</Badge>
                ) : null}
              </span>
            ),
            cells: [
              ...comparison.models.map((model) => {
                const value = row.revenue_minor_by_model[model];

                return value === null || value === undefined || row.currency === null
                  ? '—'
                  : formatMinor(value, row.currency, locale);
              }),
              row.spread_percent === null ? '—' : formatRate(row.spread_percent, locale),
            ],
          })),
        }}
      >
        <Chart
          option={option}
          height={Math.max(220, comparison.campaigns.length * 48 + 60)}
          ariaLabel={t('attribution.comparison.aria')}
        >
          <ul>
            {comparison.campaigns.map((row) => (
              <li key={row.label}>
                {row.label}:{' '}
                {comparison.models
                  .map((model, index) => {
                    const value = row.revenue_minor_by_model[model];

                    return `${labels[index] ?? model} ${
                      value === null || value === undefined || row.currency === null
                        ? '—'
                        : formatMinor(value, row.currency, locale)
                    }`;
                  })
                  .join(', ')}
                {row.spread_percent === null
                  ? ''
                  : ` · ${t('attribution.comparison.spread')} ${formatNumber(row.spread_percent, locale, 1)}%`}
              </li>
            ))}
          </ul>
        </Chart>
      </ChartFrame>
    </div>
  );
}
