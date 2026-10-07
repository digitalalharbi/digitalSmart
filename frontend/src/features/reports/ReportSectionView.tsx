import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatChange, formatMetric, MISSING } from '@/shared/metrics';
import { MetricCard } from '@/shared/ui/metric-card';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  legend,
  lineSeries,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import type { RenderedSection } from './types';

type ReportSectionViewProps = {
  section: RenderedSection;
};

/**
 * One block of a report.
 *
 * Every shape goes through `ChartFrame`, which is what carries the section's own **source state**, its caveats and
 * its table twin. A report is the artefact most likely to be read by somebody who was not in the conversation, so
 * the frame earns its place here more than anywhere else in the product.
 *
 * **Comparison figures are absent when the periods are not comparable** — not greyed out, not present. That is the
 * server's guarantee rather than this component's: `RenderReport` only asks the read model for a comparison when
 * `Comparability` permits one, so there is no earlier figure to render and nothing here has to remember to hide it.
 * A percentage on screen is a percentage somebody will quote regardless of the caption beside it, and the single
 * safest place to enforce that is where the figure is fetched.
 *
 * The change column in the table twin follows the same evidence: it appears when a figure actually carries a
 * comparison, rather than when a flag says it should.
 */
export function ReportSectionView({ section }: ReportSectionViewProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);

    if (section.kind === 'trend') {
      return {
        ...base,
        grid: { top: 30, bottom: 8, left: 8, right: 8, containLabel: true },
        legend: legend({ locale, isRtl }, palette, section.metrics.length),
        tooltip: { ...(base.tooltip as object), trigger: 'axis' },
        xAxis: {
          type: 'category',
          inverse: isRtl,
          data: section.series.map((point) => point.date),
          axisLine: { show: false },
          axisTick: { show: false },
          axisLabel: { color: palette.axisLabel, fontSize: 11 },
        },
        yAxis: {
          type: 'value',
          position: isRtl ? 'right' : 'left',
          splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
          axisLine: { show: false },
          axisTick: { show: false },
          axisLabel: { color: palette.axisLabel, fontSize: 11 },
        },
        series: section.metrics.map((metric, index) => ({
          ...lineSeries(palette, {
            name: section.series[0]?.metrics.values[metric]?.label ?? metric,
            /*
             * Null stays null. A day with no delivery comes back as an empty set rather than being omitted, so the
             * line shows a gap where spending stopped instead of being drawn straight through it.
             */
            data: section.series.map((point) => point.metrics.values[metric]?.value ?? null),
            colour: seriesColour(palette, index),
          }),
        })),
      };
    }

    // A ranking. Horizontal bars, ordered by the first metric, which is what the server ordered by too.
    const drawn = [...section.rows].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: { ...(base.tooltip as object), trigger: 'item' },
      xAxis: { type: 'value', inverse: isRtl, show: false },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: drawn.map((row) => row.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.textSecondary, fontSize: 11, width: 170, overflow: 'truncate' },
      },
      series: [
        {
          ...barSeries(palette, {
            name: section.title,
            data: drawn.map((row) => row.metrics.values[section.metrics[0] ?? '']?.value ?? null),
            orientation: 'horizontal',
            isRtl,
            colour: seriesColour(palette, 0),
          }),
        },
      ],
    };
  }, [section, isRtl, locale]);

  /*
   * Read from the figures rather than from a prop. The server withholds the comparison when the periods cannot be
   * compared, so its presence here *is* the answer — and a flag threaded down from the page would be a second copy
   * of a decision already made.
   */
  const hasComparison = useMemo(
    () => Object.values(section.totals?.values ?? {}).some((value) => value.change_ratio !== null),
    [section.totals],
  );

  const table = useMemo(() => {
    if (section.kind === 'scorecard') {
      return {
        columns: hasComparison
          ? [t('reports.table.metric'), t('reports.table.value'), t('reports.table.change')]
          : [t('reports.table.metric'), t('reports.table.value')],
        rows: section.metrics.map((metric) => {
          const value = section.totals?.values[metric];

          return {
            key: metric,
            label: value?.label ?? metric,
            cells: [
              value === undefined ? MISSING : formatMetric(value, locale),
              ...(hasComparison
                ? [
                    value === undefined
                      ? MISSING
                      : /*
                         * A dash rather than a zero where there is no change to report: no comparison and no change
                         * are different facts, and `formatChange` already returns null from a base of zero.
                         */
                        (formatChange(value, locale) ?? MISSING),
                  ]
                : []),
            ],
          };
        }),
      };
    }

    if (section.kind === 'trend') {
      return {
        columns: [
          t('reports.table.date'),
          ...section.metrics.map(
            (metric) => section.series[0]?.metrics.values[metric]?.label ?? metric,
          ),
        ],
        rows: section.series.map((point) => ({
          key: point.date,
          label: point.date,
          cells: section.metrics.map((metric) => {
            const value = point.metrics.values[metric];

            return value === undefined ? MISSING : formatMetric(value, locale);
          }),
        })),
      };
    }

    return {
      columns: [
        section.dimension_label ?? t('reports.table.row'),
        ...section.metrics.map(
          (metric) => section.rows[0]?.metrics.values[metric]?.label ?? metric,
        ),
      ],
      rows: section.rows.map((row) => ({
        key: row.key,
        label: row.label,
        cells: section.metrics.map((metric) => {
          const value = row.metrics.values[metric];

          return value === undefined ? MISSING : formatMetric(value, locale);
        }),
      })),
    };
  }, [section, hasComparison, locale, t]);

  /*
   * A scorecard is a row of figures rather than a chart, so it gets no `Chart` — `docs/DATA_VISUALISATION.md` is
   * explicit that sometimes the right answer is not a chart. It still goes through the frame, for the source badge
   * and the caveats.
   */
  if (section.kind === 'scorecard') {
    return (
      <ChartFrame
        title={section.title}
        caveats={section.caveats}
        isEmpty={section.is_empty}
        emptyTitle={t('reports.section.emptyTitle')}
        emptyBody={t('reports.section.emptyBody')}
        table={table}
      >
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {section.metrics.map((metric) => {
            const value = section.totals?.values[metric];

            if (value === undefined) {
              return null;
            }

            return (
              /*
                The card renders a comparison when the figure carries one. It never does when the periods are not
                comparable, because the server did not fetch one — see the note at the top of this file.
              */
              <MetricCard key={metric} value={value} />
            );
          })}
        </div>
      </ChartFrame>
    );
  }

  const isTable = section.kind === 'table';

  return (
    <ChartFrame
      title={section.title}
      caveats={section.caveats}
      isEmpty={section.is_empty}
      emptyTitle={t('reports.section.emptyTitle')}
      emptyBody={t('reports.section.emptyBody')}
      table={table}
    >
      {isTable ? (
        /*
         * A table section renders the table as its own content, not only as the frame's twin. The frame still offers
         * the toggle, which for this one shape shows the same figures twice — harmless, and cheaper than a special
         * case that would make one shape behave unlike the rest.
         */
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-sm">
            <caption className="sr-only">{section.title}</caption>
            <thead>
              <tr className="border-line-subtle border-b">
                {table.columns.map((column) => (
                  <th
                    key={column}
                    scope="col"
                    className="text-content-tertiary py-2 pe-4 text-start text-xs font-medium"
                  >
                    {column}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {table.rows.map((row) => (
                <tr key={row.key} className="border-line-subtle border-b last:border-0">
                  <th scope="row" className="text-content py-2 pe-4 text-start font-normal">
                    {row.label}
                  </th>
                  {row.cells.map((cell, index) => (
                    <td key={index} className="text-content-secondary py-2 pe-4 tabular-nums">
                      {cell}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Chart
          option={option}
          height={section.kind === 'trend' ? 260 : Math.max(200, section.rows.length * 34 + 60)}
          ariaLabel={section.title}
        >
          <ul>
            {table.rows.map((row) => (
              <li key={row.key}>
                {row.label}: {row.cells.join(', ')}
              </li>
            ))}
          </ul>
        </Chart>
      )}
    </ChartFrame>
  );
}
